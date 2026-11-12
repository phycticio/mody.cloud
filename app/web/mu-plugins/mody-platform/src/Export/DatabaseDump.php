<?php
/**
 * The database file of a site's download.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Export;

use mysqli;
use mysqli_result;
use RuntimeException;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.RestrictedClasses.mysql__mysqli, WordPress.DB.RestrictedFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.WP.AlternativeFunctions -- The dump reads through a connection of its own (a consistent snapshot, rows streamed one by one) and writes a file; wpdb and the WordPress file functions cannot do either.

/**
 * Writes one site's `database.sql.gz` (tickets/take-your-site-with-you.md): pure
 * PHP, no mariadb-dump and no exec.
 *
 * What it reads. One dedicated mysqli connection, opened inside a transaction
 * `WITH CONSISTENT SNAPSHOT`, so posts, post meta and options all come from the
 * same moment. (The wpdb connection is not used: a transaction open on it would
 * hide the job's heartbeat writes and every other query of the process.) Pages
 * are read by keyset (`WHERE key > last ORDER BY key LIMIT n`, composite keys
 * included, never OFFSET) and streamed row by row, so memory stays flat.
 *
 * What it takes.
 *  - Every table whose name starts with the site's exact blog prefix, except
 *    the `ai` plugin's: every one whose name (after the prefix) starts with
 *    BLOCKED_TABLE_PREFIX (`wpai_`: the request log, the embeddings and their
 *    queue, and whatever the plugin adds later) is Mody's and is listed in
 *    `skipped_tables`. The list comes from `SHOW TABLES LIKE` with the prefix
 *    escaped (`_` is a wildcard) and confirmed in PHP, so `wp_14_` never picks
 *    up `wp_147_`.
 *  - The site's users: `users` rows for those holding `{prefix}capabilities`,
 *    super admins and spam or deleted accounts left out, with the ten
 *    single-site columns only. Password hashes and activation keys are emptied:
 *    no password hash leaves the platform. Their `usermeta` is re-keyed (below).
 *  - Nothing else: no site meta, no other base-prefix table (agent events,
 *    members, provisioning, analytics, signups).
 *
 * What it cleans.
 *  - Options: transients, `wpai_*`, `connectors_*`, cron, rewrite rules, the
 *    plugin lists, the upload quota, Spectra's telemetry queues, the
 *    platform's own bookkeeping, and core's recovery keys (hashes) and
 *    pending admin-email change (`adminhash` is a bearer token) are dropped;
 *    `WPLANG` is written with its
 *    effective value (the network's when the site has none); anything named
 *    like a credential (SECRET_PATTERN) is dropped and its name listed in the
 *    manifest's `database.stripped`, so the owner can be told.
 *  - Usermeta: the site's own keys, plus an ALLOWLIST of plain user keys
 *    (USERMETA_KEEP). Everything else is left out: the role on the main site
 *    and on other sites, application passwords, sessions, onboarding, avatar
 *    and agent keys, unknown plugin meta (dropped rather than leaked).
 *  - Post meta and comment meta: `_edit_lock`, the `ai` plugin's records
 *    (`_wpai_*` and `wpai_*`: monitor records, comment scores, the speech flag)
 *    and the platform's bookkeeping (`_mody_*`: provisioning job, fallback)
 *    go; `mody_seo_title`, `mody_lang` and `mody_source` stay.
 *  - Authors outside the exported users (`posts.post_author`,
 *    `comments.user_id`, `links.link_owner`, post meta `_edit_last`) become the
 *    owner who pressed the button. Zero (no author) stays zero.
 *
 * The format (what the importer relies on).
 *  - gzip, UTF-8, one statement per line; newlines inside strings are escaped
 *    (`\n`), so a reader splits on "\n". The session must not use
 *    NO_BACKSLASH_ESCAPES or ANSI_QUOTES, and should use a sql_mode without
 *    NO_ZERO_DATE (drafts hold `0000-00-00` dates).
 *  - Line 1 is `-- mody-export: {json}`: the manifest passed in, plus `file`
 *    ("database") and the `database` object (rows per table, created tables,
 *    skipped tables, users, wplang, stripped, largest statement). The last
 *    line is `-- mody-export-end: {json}`; a file without it is incomplete.
 *    Other lines starting with `--` are comments.
 *  - Data only: `INSERT INTO \`{{prefix}}posts\` (\`ID\`,…) VALUES (…),(…);`
 *    with explicit columns and batches of about 256 KB (a bigger row is a
 *    statement of its own). No DELETE, TRUNCATE or DROP: the importer loads
 *    into empty tables of its own (`database.tables` lists which). Core tables
 *    carry no DDL (the destination's WordPress made them); every other table
 *    of the site comes first as `CREATE TABLE IF NOT EXISTS …` with the
 *    prefix tokenized, utf8mb4 and `utf8mb4_unicode_ci` (utf8mb4_bin stays),
 *    and MariaDB-only options removed. Those tables are written parents
 *    first (a foreign key needs it), each with its rows right after it. A
 *    foreign key to the network's users or usermeta table follows the token
 *    (`REFERENCES \`{{prefix}}users\``): the destination's own table. Such a
 *    table's rows may name a user who is not exported (a super admin, a spam
 *    account), so the importer should load with `FOREIGN_KEY_CHECKS=0`.
 *  - `{{prefix}}` (TOKEN) is the destination's table prefix. The importer
 *    replaces it everywhere in a statement, with one plain str_replace: it
 *    stands in table names, in `usermeta.meta_key` (the site's own keys,
 *    `{{prefix}}capabilities`) and in the option `{{prefix}}user_roles`; and
 *    the writer breaks every real occurrence of the text inside data into
 *    `{{prefi' 'x}}` (two adjacent string literals, which SQL concatenates), so
 *    a replace can never touch content.
 *  - Numbers (BIT columns too: mysqli hands them over as decimal numbers) are
 *    written bare, binary columns as `0x…`, NULL as NULL, everything else as
 *    an escaped string.
 *
 * The manifest on line 1 holds the row counts, which are known only when the
 * last row is written, so the statements go first to a plain working file
 * (`<path>.body.part`, deleted at the end) and the final gzip is made from it
 * behind the manifest. It needs that much free disk next to the output.
 */
final class DatabaseDump {

	/** Stands for the destination's table prefix; see "The format". */
	public const TOKEN = '{{prefix}}';

	/** The file's own format; the importer refuses other majors. */
	public const FORMAT = '1.0';

	/** The first line's start. */
	public const HEADER = '-- mody-export: ';

	/** The last line's start. */
	public const FOOTER = '-- mody-export-end: ';

	/** Site tables (after the prefix) starting with this never travel: the `ai` plugin's request log, embeddings and queue are Mody's. */
	public const BLOCKED_TABLE_PREFIX = 'wpai_';

	/** Statements are cut near this size (a single bigger row stands alone). */
	private const BATCH_BYTES = 262144;

	/** Rows per page of the keyset reads. */
	private const PAGE_ROWS = 500;

	/** The progress callback runs at least every this many rows... */
	private const TICK_ROWS = 2000;

	/** ...or this many seconds (it is the job's heartbeat). */
	private const TICK_SECONDS = 15;

	/** Credential names listed in the manifest, at most. */
	private const MAX_STRIPPED = 200;

	/** Core tables first, in this order; the rest of the site's tables follow by name. */
	private const CORE_ORDER = array( 'options', 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'comments', 'commentmeta', 'links' );

	/** The single-site columns of `users`. A multisite table has two more (spam, deleted). */
	private const USER_COLUMNS = array( 'ID', 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url', 'user_registered', 'user_activation_key', 'user_status', 'display_name' );

	/** Options that are never exported, by exact name. */
	private const OPTION_DROP = array(
		'cron',
		'rewrite_rules',
		'uninstall_plugins',
		'recently_activated',
		'active_plugins',
		'blog_upload_space',
		'wp_force_deactivated_plugins',
		'recovery_keys',
		'adminhash',
		'new_admin_email',
		'mody_spectra_mu_plugin',
		'spectra_blocks_block_analytics',
		'spectra_blocks_daily_block_types',
		'spectra_blocks_daily_publish_counts',
		'spectra_blocks_usage_events_pending',
		'spectra_extension_analytics',
	);

	/** ...and by pattern: transients, the `ai` plugin and its connectors, and the platform's own bookkeeping and agent state. */
	private const OPTION_DROP_PATTERN = '/^(_transient_|_site_transient_|wpai_|connectors_|mody_(agent|analytics|export|members|provisioning|storage|register))/';

	/** A name that looks like a credential. Dropped and listed, whoever owns the plugin. */
	private const SECRET_PATTERN = '/(api[_-]?key|auth[_-]?key|secret|token|passwd|password|private[_-]?key|credential|licen[cs]e|salt|(^|[_-])pass($|[_-]))/i';

	/** Core's own credential option: kept while it holds nothing real (empty, or core's default). */
	private const SECRET_ALLOWED = array( 'mailserver_pass' => array( '', 'password' ) );

	/** The plain user keys that travel (the site's own keys travel as well). */
	private const USERMETA_KEEP = array(
		'nickname',
		'first_name',
		'last_name',
		'description',
		'locale',
		'rich_editing',
		'syntax_highlighting',
		'comment_shortcuts',
		'admin_color',
		'use_ssl',
		'show_admin_bar_front',
		'show_welcome_panel',
		'dismissed_wp_pointers',
		'enable_custom_fields',
		'nav_menu_recently_edited',
	);

	/** ...and the plain user keys that come in families (screen layouts and hidden boxes). */
	private const USERMETA_KEEP_PATTERN = '/^(closedpostboxes_|metaboxhidden_|meta-box-order_|screen_layout_|manage[a-z_\-]*columnshidden)/';

	/** The site's own user keys (after the blog prefix) that are the agent's or the signup's. */
	private const USERMETA_SITE_DROP_PATTERN = '/^(mody_agent|mody_onboarding|wpai_)/';

	/** Post meta that never travels: edit locks, the `ai` plugin's records (with or without the underscore) and the platform's bookkeeping. */
	private const POSTMETA_DROP_PATTERN = '/^(_edit_lock$|_?wpai_|_mody_)/';

	/** Comment meta that never travels: the `ai` plugin's scores (`_wpai_toxicity_score`, `_wpai_sentiment`...). */
	private const COMMENTMETA_DROP_PATTERN = '/^_?wpai_/';

	/** Column types written as bare numbers (mysqli's MYSQLI_TYPE_*: decimals, integers, floats, year, and BIT, which arrives as a decimal number). */
	private const NUMERIC_TYPES = array( 0, 1, 2, 3, 4, 5, 8, 9, 13, 16, 246 );

	/** Types that are bytes when the column's charset is binary (the blobs, strings, geometry). */
	private const BINARY_TYPES = array( 249, 250, 251, 252, 253, 254, 255 );

	/** @var mysqli The snapshot connection. */
	private mysqli $db;

	/** @var resource|null The body file (plain text), until the final file is assembled. */
	private $out = null;

	/** @var resource|null The final gzip file. */
	private $gz = null;

	/** @var callable( int, int ): void The job's progress callback. */
	private $progress;

	private int $site_id;
	private int $owner_id;
	private string $prefix = '';
	private string $body   = '';
	private string $final  = '';

	/** @var array<int, true> The ids of the exported users. */
	private array $user_set = array();

	/** @var array<string, int> Rows written, by table (after the prefix). */
	private array $counts = array();

	/** @var string[] Tables created by a CREATE statement. */
	private array $created = array();

	/** @var string[] Site tables left out on purpose. */
	private array $skipped = array();

	/** @var string[] Credential-like option names that held a value. */
	private array $stripped = array();

	private int $total      = 0;
	private int $done       = 0;
	private int $reported   = 0;
	private float $last_tick = 0.0;
	private int $statements = 0;
	private int $largest    = 0;
	private string $wplang  = '';
	private bool $wplang_seen = false;

	/**
	 * Writes the database file. Called inside `switch_to_blog( $site_id )` by
	 * Exporter::run() (it switches itself if it is not).
	 *
	 * @param int      $site_id  The member site.
	 * @param int      $owner_id The administrator who asked: authors outside the export become them.
	 * @param string   $path     Where to write; written as "$path.part" and renamed when complete.
	 * @param array    $manifest Exporter::manifest(); written as the file's first line.
	 * @param callable $progress `function ( int $rows_done, int $rows_total )`, called every few thousand rows or 15 seconds.
	 * @return true|WP_Error
	 */
	public static function write( int $site_id, int $owner_id, string $path, array $manifest, callable $progress ) {
		if ( ! function_exists( 'gzopen' ) ) {
			return new WP_Error( 'mody_dump_zlib', 'PHP has no zlib: the database file cannot be compressed.' );
		}
		if ( $site_id < 1 || ! is_multisite() || is_main_site( $site_id ) || ! get_site( $site_id ) ) {
			return new WP_Error( 'mody_dump_site', 'The database file is built for member sites only.' );
		}
		if ( $owner_id < 1 || ! get_userdata( $owner_id ) ) {
			return new WP_Error( 'mody_dump_owner', 'The owner of the export does not exist.' );
		}
		if ( ! wp_mkdir_p( dirname( $path ) ) || ! is_writable( dirname( $path ) ) ) {
			return new WP_Error( 'mody_dump_dir', 'The folder for the database file is not writable.' );
		}

		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}
		$dump = new self( $site_id, $owner_id, $path, $progress );
		try {
			$dump->build( $manifest );
			return true;
		} catch ( Throwable $e ) {
			return new WP_Error( 'mody_dump_failed', $e->getMessage() );
		} finally {
			$dump->close();
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Reads the manifest back from a finished file (its first line).
	 *
	 * @param string $path The `database.sql.gz`.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function read_manifest( string $path ) {
		$gz = is_readable( $path ) && function_exists( 'gzopen' ) ? gzopen( $path, 'rb' ) : false;
		if ( ! $gz ) {
			return new WP_Error( 'mody_dump_read', 'The database file cannot be read.' );
		}
		$line = gzgets( $gz, 4194304 );
		gzclose( $gz );
		if ( ! is_string( $line ) || ! str_starts_with( $line, self::HEADER ) ) {
			return new WP_Error( 'mody_dump_header', 'The file does not start with a Mody manifest.' );
		}
		$data = json_decode( substr( $line, strlen( self::HEADER ) ), true );
		return is_array( $data ) ? $data : new WP_Error( 'mody_dump_header', 'The manifest on the first line is not JSON.' );
	}

	/**
	 * The builder is made by write().
	 *
	 * @param int      $site_id  Site.
	 * @param int      $owner_id Owner.
	 * @param string   $path     The final path.
	 * @param callable $progress Progress callback.
	 */
	private function __construct( int $site_id, int $owner_id, string $path, callable $progress ) {
		$this->site_id   = $site_id;
		$this->owner_id  = $owner_id;
		$this->final     = $path;
		$this->body      = $path . '.body.part';
		$this->progress  = $progress;
		$this->last_tick = microtime( true );
	}

	/**
	 * The whole job: connect, plan, stream every table into the body file,
	 * then put the manifest first and compress it all into the final file.
	 *
	 * @param array $manifest The manifest passed in.
	 */
	private function build( array $manifest ): void {
		global $wpdb;
		$this->prefix = $wpdb->get_blog_prefix( $this->site_id );
		if ( 'wp_' === $this->prefix || ! preg_match( '/^[A-Za-z0-9_]+_\d+_$/', $this->prefix ) ) {
			throw new RuntimeException( 'Unexpected table prefix for a member site: ' . $this->prefix );
		}
		$this->wplang = $this->effective_wplang();

		$this->connect();
		$plan = $this->plan();
		$this->beat();

		$this->out = fopen( $this->body, 'wb' );
		if ( ! $this->out ) {
			throw new RuntimeException( 'Could not create the working file for the database.' );
		}
		foreach ( $plan as $table ) {
			if ( null !== $table['create'] ) {
				$this->emit( $table['create'] );
				$this->created[] = $table['suffix'];
			}
			$this->stream_table( $table );
		}
		$this->extra_wplang();
		if ( ! fclose( $this->out ) ) {
			$this->out = null;
			throw new RuntimeException( 'Could not finish the working file (disk full?).' );
		}
		$this->out = null;
		$this->db->rollback(); // Nothing was written; end the snapshot.

		$this->assemble( $manifest );
	}

	/**
	 * Releases everything, and deletes what a failure left behind.
	 */
	private function close(): void {
		if ( isset( $this->db ) ) {
			try {
				$this->db->close();
			} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Already closed.
				unset( $e );
			}
		}
		if ( is_resource( $this->out ) ) {
			fclose( $this->out );
		}
		if ( is_resource( $this->gz ) ) {
			gzclose( $this->gz );
		}
		foreach ( array( $this->body, $this->final . '.part' ) as $leftover ) {
			if ( '' !== $leftover && is_file( $leftover ) ) {
				wp_delete_file( $leftover );
			}
		}
	}

	// Connection and queries -------------------------------------------------------------

	/**
	 * Opens the dedicated connection and its snapshot.
	 */
	private function connect(): void {
		global $wpdb;
		$host = (string) $wpdb->dbhost;
		$port = null;
		$sock = null;
		$data = $wpdb->parse_db_host( $host );
		if ( $data ) {
			list( $host, $port, $sock, $ipv6 ) = $data;
			if ( $ipv6 && extension_loaded( 'mysqlnd' ) ) {
				$host = '[' . $host . ']';
			}
		}
		$flags = defined( 'MYSQL_CLIENT_FLAGS' ) ? (int) MYSQL_CLIENT_FLAGS : 0;
		$db    = mysqli_init();
		try {
			$ok = $db && @mysqli_real_connect( $db, $host, (string) $wpdb->dbuser, (string) $wpdb->dbpassword, (string) $wpdb->dbname, $port, $sock, $flags ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Reported below.
		} catch ( Throwable $e ) {
			$ok = false;
		}
		if ( ! $ok || $db->connect_errno ) {
			throw new RuntimeException( 'Could not open the database connection for the export.' );
		}
		$this->db = $db;
		if ( ! $db->set_charset( 'utf8mb4' ) ) {
			throw new RuntimeException( 'The database connection does not speak utf8mb4.' );
		}
		// Escaping depends on sql_mode: NO_BACKSLASH_ESCAPES would leave raw newlines in the file.
		$this->x( "SET SESSION sql_mode = ''" );
		$this->x( 'SET SESSION net_write_timeout = 3600' );
		$this->x( 'SET SESSION net_read_timeout = 3600' );
		$this->x( 'SET SESSION wait_timeout = 28800' );
		$this->x( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		$this->x( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
	}

	/**
	 * Runs a statement that returns no rows.
	 *
	 * @param string $sql The statement.
	 */
	private function x( string $sql ): void {
		try {
			$ok = $this->db->query( $sql );
		} catch ( Throwable $e ) {
			throw new RuntimeException( 'Database: ' . $e->getMessage(), 0, $e );
		}
		if ( ! $ok ) {
			throw new RuntimeException( 'Database: ' . $this->db->error );
		}
	}

	/**
	 * Runs a query and returns its (buffered) result.
	 *
	 * @param string $sql The query.
	 * @return mysqli_result
	 */
	private function q( string $sql ): mysqli_result {
		try {
			$res = $this->db->query( $sql );
		} catch ( Throwable $e ) {
			throw new RuntimeException( 'Database: ' . $e->getMessage(), 0, $e );
		}
		if ( ! $res instanceof mysqli_result ) {
			throw new RuntimeException( 'Database: ' . $this->db->error );
		}
		return $res;
	}

	/**
	 * Runs a query and returns its result unbuffered: rows are read from the
	 * server one by one and must all be read (or freed) before the next query.
	 *
	 * @param string $sql The query.
	 * @return mysqli_result
	 */
	private function stream( string $sql ): mysqli_result {
		try {
			$ok  = $this->db->real_query( $sql );
			$res = $ok ? $this->db->use_result() : false;
		} catch ( Throwable $e ) {
			throw new RuntimeException( 'Database: ' . $e->getMessage(), 0, $e );
		}
		if ( ! $res instanceof mysqli_result ) {
			throw new RuntimeException( 'Database: ' . $this->db->error );
		}
		return $res;
	}

	/**
	 * A backtick-quoted identifier.
	 *
	 * @param string $name The name.
	 */
	private function ident( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Escapes the inside of a string literal. A real occurrence of the prefix
	 * token in data is broken into two adjacent literals, so the importer's
	 * replace never finds it.
	 *
	 * @param string $value The text.
	 */
	private function esc( string $value ): string {
		$escaped = $this->db->real_escape_string( $value );
		return str_contains( $escaped, self::TOKEN ) ? str_replace( self::TOKEN, "{{prefi' 'x}}", $escaped ) : $escaped;
	}

	/**
	 * One value as an SQL literal.
	 *
	 * @param mixed  $value A value from a row; `array( 'prefixed' => 'name' )` stands for the token plus a name.
	 * @param string $kind  'num', 'bin' or 'str' (see kinds()).
	 */
	private function literal( $value, string $kind ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( is_array( $value ) ) {
			return "'" . self::TOKEN . $this->esc( (string) $value['prefixed'] ) . "'";
		}
		$value = (string) $value;
		if ( 'num' === $kind && preg_match( '/^-?\d+(\.\d+)?([eE][-+]?\d+)?$/', $value ) ) {
			return $value;
		}
		if ( 'bin' === $kind ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}
		return "'" . $this->esc( $value ) . "'";
	}

	/**
	 * How each column of a result is written.
	 *
	 * @param object[] $fields The result's fields.
	 * @return string[] 'num', 'bin' or 'str', by position.
	 */
	private function kinds( array $fields ): array {
		$kinds = array();
		foreach ( $fields as $field ) {
			$type = (int) $field->type;
			if ( in_array( $type, self::NUMERIC_TYPES, true ) ) {
				$kinds[] = 'num';
			} elseif ( 63 === (int) $field->charsetnr && in_array( $type, self::BINARY_TYPES, true ) ) {
				$kinds[] = 'bin';
			} else {
				$kinds[] = 'str';
			}
		}
		return $kinds;
	}

	// Planning ---------------------------------------------------------------------------

	/**
	 * Decides what is exported: the tables, their columns, keys, filters and
	 * row transforms, and counts the rows for the progress bar.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function plan(): array {
		global $wpdb;
		$core   = array_keys( $wpdb->tables( 'blog', true, $this->site_id ) );
		$tables = $this->site_tables();
		foreach ( array( 'posts', 'options' ) as $needed ) {
			if ( ! isset( $tables[ $needed ] ) ) {
				throw new RuntimeException( 'The site has no ' . $needed . ' table.' );
			}
		}

		$this->load_users();
		$ids   = $this->user_set ? implode( ',', array_keys( $this->user_set ) ) : '0';
		$plan  = array();
		$users = $this->columns( $wpdb->users );
		$plan[] = array(
			'suffix'    => 'users',
			'table'     => $wpdb->users,
			'create'    => null,
			'columns'   => array_values( array_intersect( self::USER_COLUMNS, $users ) ),
			'where'     => "`ID` IN ({$ids})",
			'transform' => array( $this, 'row_users' ),
		);
		$plan[] = array(
			'suffix'    => 'usermeta',
			'table'     => $wpdb->usermeta,
			'create'    => null,
			'columns'   => $this->columns( $wpdb->usermeta ),
			'where'     => "`user_id` IN ({$ids})",
			'transform' => array( $this, 'row_usermeta' ),
		);

		$transforms = array(
			'options'     => array( $this, 'row_options' ),
			'posts'       => array( $this, 'row_posts' ),
			'postmeta'    => array( $this, 'row_postmeta' ),
			'comments'    => array( $this, 'row_comments' ),
			'commentmeta' => array( $this, 'row_commentmeta' ),
			'links'       => array( $this, 'row_links' ),
		);
		$ordered    = array_merge( array_values( array_intersect( self::CORE_ORDER, $core ) ), array_values( array_diff( $core, self::CORE_ORDER ) ) );
		foreach ( $ordered as $suffix ) {
			if ( ! isset( $tables[ $suffix ] ) ) {
				continue; // A core table the site does not have.
			}
			$plan[] = array(
				'suffix'    => $suffix,
				'table'     => $tables[ $suffix ],
				'create'    => null,
				'columns'   => $this->columns( $tables[ $suffix ] ),
				'where'     => '',
				'transform' => $transforms[ $suffix ] ?? null,
			);
		}

		// The site's other tables (a plugin's): their definition first, and a table after the ones its foreign keys point to.
		$extras  = array_diff( array_keys( $tables ), $core );
		$defined = array();
		foreach ( $extras as $suffix ) {
			$create = $this->show_create( $tables[ $suffix ] );
			$needs  = array();
			if ( preg_match_all( '/REFERENCES\s+`([^`]+)`/i', $create, $found ) ) {
				foreach ( $found[1] as $name ) {
					$needs[] = str_starts_with( $name, $this->prefix ) ? substr( $name, strlen( $this->prefix ) ) : $name;
				}
			}
			$defined[ $suffix ] = array(
				'suffix'    => $suffix,
				'table'     => $tables[ $suffix ],
				'create'    => $this->ddl( $create ),
				'columns'   => $this->columns( $tables[ $suffix ] ),
				'where'     => '',
				'transform' => null,
				'needs'     => $needs,
			);
		}
		ksort( $defined );
		foreach ( $this->parents_first( $defined ) as $suffix ) {
			$plan[] = $defined[ $suffix ];
		}

		foreach ( $plan as $i => $table ) {
			if ( ! $table['columns'] ) {
				throw new RuntimeException( 'Could not read the columns of ' . $table['table'] );
			}
			$plan[ $i ]['key'] = $this->primary_key( $table['table'], $table['columns'] );
			$where             = '' === $table['where'] ? '' : ' WHERE ' . $table['where'];
			$count             = $this->q( 'SELECT COUNT(*) FROM ' . $this->ident( $table['table'] ) . $where );
			$this->total      += (int) $count->fetch_row()[0];
			$count->free();
		}
		return $plan;
	}

	/**
	 * The site's tables: every base table starting with the exact blog prefix,
	 * minus the `ai` plugin's (every name starting with BLOCKED_TABLE_PREFIX).
	 *
	 * @return array<string, string> Full name by name after the prefix.
	 */
	private function site_tables(): array {
		global $wpdb;
		$pattern = $this->db->real_escape_string( $wpdb->esc_like( $this->prefix ) ) . '%';
		$res     = $this->q( "SHOW FULL TABLES LIKE '{$pattern}'" );
		$tables  = array();
		while ( is_array( $row = $res->fetch_row() ) ) {
			list( $name, $type ) = array( (string) $row[0], strtoupper( (string) ( $row[1] ?? '' ) ) );
			if ( 'BASE TABLE' !== $type || ! str_starts_with( $name, $this->prefix ) || strlen( $name ) === strlen( $this->prefix ) ) {
				continue; // Views, and anything the wildcard-safe check does not confirm.
			}
			$suffix = substr( $name, strlen( $this->prefix ) );
			if ( str_starts_with( strtolower( $suffix ), self::BLOCKED_TABLE_PREFIX ) ) {
				$this->skipped[] = $suffix;
				continue;
			}
			$tables[ $suffix ] = $name;
		}
		$res->free();
		return $tables;
	}

	/**
	 * Orders the plugin tables so each comes after the ones its foreign keys
	 * point to (a cycle is left in name order).
	 *
	 * @param array<string, array<string, mixed>> $tables Entries by name, each with `needs`.
	 * @return string[] Names in the order to write.
	 */
	private function parents_first( array $tables ): array {
		$ordered = array();
		$seen    = array();
		$visit   = static function ( string $name ) use ( &$visit, &$ordered, &$seen, $tables ): void {
			if ( isset( $seen[ $name ] ) ) {
				return;
			}
			$seen[ $name ] = true;
			foreach ( $tables[ $name ]['needs'] as $need ) {
				if ( isset( $tables[ $need ] ) ) {
					$visit( $need );
				}
			}
			$ordered[] = $name;
		};
		foreach ( array_keys( $tables ) as $name ) {
			$visit( $name );
		}
		return $ordered;
	}

	/**
	 * Finds who is exported: the users with a role on this site, except super
	 * admins and spam or deleted accounts.
	 */
	private function load_users(): void {
		global $wpdb;
		$key = "'" . $this->db->real_escape_string( $this->prefix . 'capabilities' ) . "'";
		$res = $this->q( "SELECT DISTINCT `user_id` FROM {$this->ident( $wpdb->usermeta )} WHERE `meta_key` = {$key}" );
		$ids = array();
		while ( is_array( $row = $res->fetch_row() ) ) {
			$ids[] = (int) $row[0];
		}
		$res->free();
		if ( ! $ids ) {
			return;
		}
		$columns = $this->columns( $wpdb->users );
		$flags   = array_values( array_intersect( array( 'spam', 'deleted' ), $columns ) );
		$select  = implode( ',', array_map( array( $this, 'ident' ), array_merge( array( 'ID', 'user_login' ), $flags ) ) );
		$res     = $this->q( "SELECT {$select} FROM {$this->ident( $wpdb->users )} WHERE `ID` IN (" . implode( ',', $ids ) . ')' );
		$supers  = array_map( 'strtolower', (array) get_super_admins() );
		while ( is_array( $row = $res->fetch_assoc() ) ) {
			if ( in_array( strtolower( (string) $row['user_login'] ), $supers, true ) || ! empty( $row['spam'] ) || ! empty( $row['deleted'] ) ) {
				continue;
			}
			$this->user_set[ (int) $row['ID'] ] = true;
		}
		$res->free();
	}

	/**
	 * A table's columns that can be inserted (generated columns are left out).
	 *
	 * @param string $table Full table name.
	 * @return string[]
	 */
	private function columns( string $table ): array {
		$res  = $this->q( 'SHOW COLUMNS FROM ' . $this->ident( $table ) );
		$cols = array();
		while ( is_array( $row = $res->fetch_assoc() ) ) {
			if ( preg_match( '/GENERATED|PERSISTENT|VIRTUAL/i', (string) ( $row['Extra'] ?? '' ) ) ) {
				continue;
			}
			$cols[] = (string) $row['Field'];
		}
		$res->free();
		return $cols;
	}

	/**
	 * A table's primary key columns in order; empty when it has none (then the
	 * table is read in one unbuffered pass, still inside the snapshot).
	 *
	 * @param string   $table   Full table name.
	 * @param string[] $columns The columns that are read.
	 * @return string[]
	 */
	private function primary_key( string $table, array $columns ): array {
		$res = $this->q( 'SHOW KEYS FROM ' . $this->ident( $table ) . " WHERE `Key_name` = 'PRIMARY'" );
		$key = array();
		while ( is_array( $row = $res->fetch_assoc() ) ) {
			$key[ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
		}
		$res->free();
		ksort( $key );
		$key = array_values( $key );
		return array_diff( $key, $columns ) ? array() : $key;
	}

	/**
	 * `SHOW CREATE TABLE`.
	 *
	 * @param string $table Full table name.
	 */
	private function show_create( string $table ): string {
		$res = $this->q( 'SHOW CREATE TABLE ' . $this->ident( $table ) );
		$row = $res->fetch_row();
		$res->free();
		if ( ! is_array( $row ) || empty( $row[1] ) ) {
			throw new RuntimeException( 'Could not read the definition of ' . $table );
		}
		return (string) $row[1];
	}

	/**
	 * A plugin table's definition, made portable: IF NOT EXISTS, the prefix
	 * tokenized, utf8mb4 with `utf8mb4_unicode_ci` (a `_bin` collation stays),
	 * no AUTO_INCREMENT counter, no MariaDB-only options, on one line.
	 *
	 * @param string $create The server's `Create Table`.
	 */
	private function ddl( string $create ): string {
		$ddl = (string) preg_replace( '#/\*M!.*?\*/#s', '', $create );
		$ddl = str_replace( '`' . $this->prefix, '`' . self::TOKEN, $ddl );
		$ddl = $this->tokenize_user_references( $ddl );
		$ddl = (string) preg_replace( '/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $ddl, 1 );
		$ddl = (string) preg_replace( '/\s+AUTO_INCREMENT=\d+/i', '', $ddl );
		$ddl = (string) preg_replace( '/\s+(PAGE_CHECKSUM|TRANSACTIONAL)=\d+/i', '', $ddl );
		$ddl = (string) preg_replace( '/\s+ROW_FORMAT=PAGE\b/i', '', $ddl );
		$ddl = (string) preg_replace( '/\bENGINE=Aria\b/i', 'ENGINE=InnoDB', $ddl );
		$ddl = (string) preg_replace( '/\b(DEFAULT CHARSET|CHARSET|CHARACTER SET)(\s*=\s*|\s+)(utf8mb3|utf8)\b/i', '$1$2utf8mb4', $ddl );
		$ddl = (string) preg_replace_callback(
			'/\bCOLLATE(\s*=\s*|\s+)((?:utf8mb4|utf8mb3|utf8)_[a-z0-9_]+)/i',
			static fn( array $m ): string => 'COLLATE' . $m[1] . ( str_ends_with( strtolower( $m[2] ), '_bin' ) ? 'utf8mb4_bin' : 'utf8mb4_unicode_ci' ),
			$ddl
		);
		if ( ! preg_match( '/\bDEFAULT CHARSET=/i', $ddl ) ) {
			$ddl .= ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
		} else {
			$ddl = (string) preg_replace( '/\bDEFAULT CHARSET=utf8mb4\b(?! COLLATE=)/i', 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $ddl, 1 );
		}
		return rtrim( (string) preg_replace( '/\s*[\r\n]+\s*/', ' ', $ddl ), "; \t" ) . ';';
	}

	/**
	 * A foreign key to the network's users or usermeta table (`REFERENCES
	 * \`wp_users\``) points at the destination's own table: the token, then the name.
	 *
	 * @param string $ddl A definition whose site-prefixed names are tokenized already.
	 */
	private function tokenize_user_references( string $ddl ): string {
		global $wpdb;
		$map = array(
			$wpdb->users    => self::TOKEN . 'users',
			$wpdb->usermeta => self::TOKEN . 'usermeta',
		);
		return (string) preg_replace_callback(
			'/(\bREFERENCES\s+)`((?:[^`]|``)+)`/i',
			static fn( array $m ): string => $m[1] . '`' . ( $map[ str_replace( '``', '`', $m[2] ) ] ?? $m[2] ) . '`',
			$ddl
		);
	}

	// Streaming --------------------------------------------------------------------------

	/**
	 * Reads one table, page by page, and writes its INSERT statements.
	 *
	 * @param array<string, mixed> $table An entry of plan().
	 */
	private function stream_table( array $table ): void {
		$from      = $this->ident( $table['table'] );
		$cols_sql  = implode( ',', array_map( array( $this, 'ident' ), $table['columns'] ) );
		$key       = $table['key'];
		$order     = implode( ',', array_map( array( $this, 'ident' ), $key ) );
		$head      = 'INSERT INTO ' . $this->ident( self::TOKEN . $table['suffix'] ) . " ({$cols_sql}) VALUES ";
		$transform = $table['transform'];
		$batch     = '';
		$rows      = 0;
		$last      = null;
		$by_name   = array();

		do {
			$where = array();
			if ( '' !== $table['where'] ) {
				$where[] = '(' . $table['where'] . ')';
			}
			if ( null !== $last ) {
				$where[] = $this->after( $key, $last, $by_name );
			}
			$sql = "SELECT {$cols_sql} FROM {$from}" . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . ( $key ? " ORDER BY {$order} LIMIT " . self::PAGE_ROWS : '' );
			$res = $this->stream( $sql );

			$fields = $res->fetch_fields();
			$kinds  = $this->kinds( $fields );
			$names  = array();
			foreach ( $fields as $i => $field ) {
				$names[ $i ]            = $field->name;
				$by_name[ $field->name ] = $kinds[ $i ];
			}

			$page = 0;
			while ( is_array( $row = $res->fetch_assoc() ) ) {
				++$page;
				$this->tick();
				if ( $key ) {
					$last = array_intersect_key( $row, array_flip( $key ) );
				}
				$out = $transform ? $transform( $row ) : $row;
				if ( null === $out ) {
					continue;
				}
				$cells = array();
				foreach ( $names as $i => $name ) {
					$cells[] = $this->literal( $out[ $name ] ?? null, $kinds[ $i ] );
				}
				$tuple = '(' . implode( ',', $cells ) . ')';
				if ( '' !== $batch && strlen( $batch ) + strlen( $tuple ) + 1 > self::BATCH_BYTES ) {
					$this->emit( $head . $batch . ';' );
					$batch = '';
				}
				$batch .= ( '' === $batch ? '' : ',' ) . $tuple;
				++$rows;
			}
			$res->free();
			if ( $this->db->errno ) {
				throw new RuntimeException( 'Database: ' . $this->db->error );
			}
		} while ( $key && self::PAGE_ROWS === $page );

		if ( '' !== $batch ) {
			$this->emit( $head . $batch . ';' );
		}
		$this->counts[ $table['suffix'] ] = $rows;
	}

	/**
	 * The keyset condition for the page after `$last`: `(a > x) OR (a = x AND b > y)`.
	 *
	 * @param string[]              $key     Primary key columns.
	 * @param array<string, string> $last    The last row's key values.
	 * @param array<string, string> $by_name Column kinds by name.
	 */
	private function after( array $key, array $last, array $by_name ): string {
		$or = array();
		foreach ( $key as $i => $column ) {
			$and = array();
			for ( $j = 0; $j < $i; $j++ ) {
				$and[] = $this->ident( $key[ $j ] ) . ' = ' . $this->literal( $last[ $key[ $j ] ], $by_name[ $key[ $j ] ] ?? 'str' );
			}
			$and[] = $this->ident( $column ) . ' > ' . $this->literal( $last[ $column ], $by_name[ $column ] ?? 'str' );
			$or[]  = '(' . implode( ' AND ', $and ) . ')';
		}
		return '(' . implode( ' OR ', $or ) . ')';
	}

	/**
	 * Writes a statement to the body file: one line.
	 *
	 * @param string $statement The statement, without its newline.
	 */
	private function emit( string $statement ): void {
		if ( false !== strpbrk( $statement, "\r\n" ) ) {
			throw new RuntimeException( 'A statement holds a raw line break.' );
		}
		$statement .= "\n";
		$length     = strlen( $statement );
		if ( fwrite( $this->out, $statement ) !== $length ) {
			throw new RuntimeException( 'Could not write the database file (disk full?).' );
		}
		++$this->statements;
		$this->largest = max( $this->largest, $length );
	}

	/**
	 * One source row scanned; reports progress every few thousand rows.
	 */
	private function tick(): void {
		++$this->done;
		if ( $this->done - $this->reported >= self::TICK_ROWS || microtime( true ) - $this->last_tick >= self::TICK_SECONDS ) {
			$this->beat();
		}
	}

	/**
	 * Calls the progress callback (the job's heartbeat).
	 */
	private function beat(): void {
		$this->reported  = $this->done;
		$this->last_tick = microtime( true );
		( $this->progress )( min( $this->done, $this->total ), $this->total );
	}

	// Row transforms ---------------------------------------------------------------------

	/**
	 * An author id: the same if exported (or none), otherwise the owner.
	 *
	 * @param mixed $value A user id.
	 */
	private function author( $value ): string {
		$id = (int) $value;
		return $id > 0 && ! isset( $this->user_set[ $id ] ) ? (string) $this->owner_id : (string) $value;
	}

	/**
	 * Users: no password hash, no activation key.
	 *
	 * @param array<string, mixed> $row A row.
	 * @return array<string, mixed>
	 */
	private function row_users( array $row ): array {
		$row['user_pass']           = '';
		$row['user_activation_key'] = '';
		return $row;
	}

	/**
	 * User meta: the site's own keys re-keyed with the token, plus the allowlist.
	 *
	 * @param array<string, mixed> $row A row.
	 * @return array<string, mixed>|null
	 */
	private function row_usermeta( array $row ): ?array {
		$key = (string) $row['meta_key'];
		if ( str_starts_with( $key, $this->prefix ) ) {
			$name = substr( $key, strlen( $this->prefix ) );
			if ( '' === $name || preg_match( self::USERMETA_SITE_DROP_PATTERN, $name ) ) {
				return null;
			}
			$row['meta_key'] = array( 'prefixed' => $name );
			return $row;
		}
		if ( in_array( $key, self::USERMETA_KEEP, true ) || preg_match( self::USERMETA_KEEP_PATTERN, $key ) ) {
			return $row;
		}
		return null;
	}

	/**
	 * Options: drop, strip, materialize or re-key (see the class docblock).
	 *
	 * @param array<string, mixed> $row A row.
	 * @return array<string, mixed>|null
	 */
	private function row_options( array $row ): ?array {
		$name  = (string) $row['option_name'];
		$value = (string) $row['option_value'];
		if ( in_array( $name, self::OPTION_DROP, true ) || preg_match( self::OPTION_DROP_PATTERN, $name ) ) {
			return null;
		}
		if ( 'WPLANG' === $name ) {
			$this->wplang_seen   = true;
			$row['option_value'] = $this->wplang;
			return $row;
		}
		if ( preg_match( self::SECRET_PATTERN, $name ) ) {
			$allowed = self::SECRET_ALLOWED[ $name ] ?? array();
			if ( in_array( $value, $allowed, true ) ) {
				return $row;
			}
			if ( '' !== trim( $value ) && 'a:0:{}' !== $value && count( $this->stripped ) < self::MAX_STRIPPED && ! in_array( $name, $this->stripped, true ) ) {
				$this->stripped[] = $name;
			}
			return null;
		}
		if ( str_starts_with( $name, $this->prefix ) ) {
			$row['option_name'] = array( 'prefixed' => substr( $name, strlen( $this->prefix ) ) );
		}
		return $row;
	}

	/**
	 * Posts: authors outside the export become the owner.
	 *
	 * @param array<string, mixed> $row A row.
	 * @return array<string, mixed>
	 */
	private function row_posts( array $row ): array {
		$row['post_author'] = $this->author( $row['post_author'] );
		return $row;
	}

	/**
	 * Post meta: no edit locks, `ai` records or platform bookkeeping; `_edit_last` follows the authors.
	 *
	 * @param array<string, mixed> $row A row.
	 * @return array<string, mixed>|null
	 */
	private function row_postmeta( array $row ): ?array {
		$key = (string) $row['meta_key'];
		if ( preg_match( self::POSTMETA_DROP_PATTERN, $key ) ) {
			return null;
		}
		if ( '_edit_last' === $key ) {
			$row['meta_value'] = $this->author( $row['meta_value'] );
		}
		return $row;
	}

	/**
	 * Comments: a registered commenter outside the export becomes the owner.
	 *
	 * @param array<string, mixed> $row A row.
	 * @return array<string, mixed>
	 */
	private function row_comments( array $row ): array {
		$row['user_id'] = $this->author( $row['user_id'] );
		return $row;
	}

	/**
	 * Comment meta: no `ai` plugin scores on visitors' comments.
	 *
	 * @param array<string, mixed> $row A row.
	 * @return array<string, mixed>|null
	 */
	private function row_commentmeta( array $row ): ?array {
		return preg_match( self::COMMENTMETA_DROP_PATTERN, (string) $row['meta_key'] ) ? null : $row;
	}

	/**
	 * Links: the owner of a link outside the export becomes the owner.
	 *
	 * @param array<string, mixed> $row A row.
	 * @return array<string, mixed>
	 */
	private function row_links( array $row ): array {
		$row['link_owner'] = $this->author( $row['link_owner'] );
		return $row;
	}

	/**
	 * The language the site really uses: its own `WPLANG`, or the network's.
	 */
	private function effective_wplang(): string {
		$own = get_option( 'WPLANG' );
		return (string) ( false !== $own ? $own : get_site_option( 'WPLANG', '' ) );
	}

	/**
	 * A site without a `WPLANG` row follows the network's; the new host has no
	 * network, so the effective value is written as a row.
	 */
	private function extra_wplang(): void {
		if ( $this->wplang_seen ) {
			return;
		}
		$this->emit( 'INSERT INTO ' . $this->ident( self::TOKEN . 'options' ) . ' (`option_name`,`option_value`,`autoload`) VALUES (' . $this->literal( 'WPLANG', 'str' ) . ',' . $this->literal( $this->wplang, 'str' ) . ",'yes');" );
		$this->counts['options'] = ( $this->counts['options'] ?? 0 ) + 1;
	}

	// The final file ---------------------------------------------------------------------

	/**
	 * Puts the manifest on the first line (its row counts are known only now),
	 * compresses the body behind it and renames the result into place.
	 *
	 * @param array $manifest The manifest passed in.
	 */
	private function assemble( array $manifest ): void {
		$meta = $manifest;
		sort( $this->skipped );

		$meta['file']     = 'database';
		$meta['database'] = array(
			'format'            => self::FORMAT,
			'prefix_token'      => self::TOKEN,
			'tables'            => $this->counts,
			'rows'              => array_sum( $this->counts ),
			'created_tables'    => $this->created,
			'skipped_tables'    => $this->skipped,
			'users'             => array(
				'owner'           => $this->owner_id,
				'exported'        => count( $this->user_set ),
				'owner_in_dump'   => isset( $this->user_set[ $this->owner_id ] ),
				'password_hashes' => false,
			),
			'wplang'            => $this->wplang,
			'stripped'          => $this->stripped,
			'statements'        => $this->statements,
			'largest_statement' => $this->largest,
		);
		$json = wp_json_encode( $meta, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			throw new RuntimeException( 'Could not encode the manifest.' );
		}

		$this->gz = gzopen( $this->final . '.part', 'wb6' );
		if ( ! $this->gz ) {
			throw new RuntimeException( 'Could not create the database file.' );
		}
		$this->gz_write( self::HEADER . $json . "\n" );

		$in = fopen( $this->body, 'rb' );
		if ( ! $in ) {
			throw new RuntimeException( 'Could not read back the working file.' );
		}
		while ( ! feof( $in ) ) {
			$chunk = fread( $in, 1048576 );
			if ( false === $chunk ) {
				fclose( $in );
				throw new RuntimeException( 'Could not read back the working file.' );
			}
			$this->gz_write( $chunk );
			if ( microtime( true ) - $this->last_tick >= self::TICK_SECONDS ) {
				$this->beat();
			}
		}
		fclose( $in );

		$end = wp_json_encode(
			array(
				'rows'       => array_sum( $this->counts ),
				'statements' => $this->statements,
			)
		);
		$this->gz_write( self::FOOTER . $end . "\n" );
		$closed   = gzclose( $this->gz );
		$this->gz = null;
		if ( ! $closed ) {
			throw new RuntimeException( 'Could not finish the database file (disk full?).' );
		}
		wp_delete_file( $this->body );
		if ( ! rename( $this->final . '.part', $this->final ) ) {
			throw new RuntimeException( 'Could not move the database file into place.' );
		}
		$this->done = $this->total;
		$this->beat();
	}

	/**
	 * Writes to the final file and checks it all arrived.
	 *
	 * @param string $data Bytes.
	 */
	private function gz_write( string $data ): void {
		if ( '' === $data ) {
			return;
		}
		$written = gzwrite( $this->gz, $data );
		if ( false === $written || strlen( $data ) !== $written ) {
			throw new RuntimeException( 'Could not write the database file (disk full?).' );
		}
	}
}
