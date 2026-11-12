<?php
/**
 * Download jobs: the network table `mody_export_jobs`.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Export;

defined( 'ABSPATH' ) || exit;

/**
 * One row per download a site's administrator asked for
 * (tickets/take-your-site-with-you.md). A job walks STEPS in order; the
 * builder (Exporter::run(), in a detached `wp mody-export run` process) claims
 * it, beats `updated_at` while it works, and resumes from the step it was on
 * when a claim went stale (a killed process, a container restart).
 *
 * The files live in `dir()`: `app/dumps/exports/<site>/<token>/`, which Nginx
 * denies, git ignores and the deploy skips. They are served only through
 * Downloads (signed links, Nginx's X-Accel-Redirect) and deleted KEEP_FOR
 * after the job finished (Exporter::sweep()).
 */
final class Jobs {

	/** The steps, in order. The database goes first: every attachment it lists has its file in the media zip. */
	public const STEPS = array( 'database', 'media', 'code', 'done' );

	/** The three files, by step. */
	public const FILES = array( 'database', 'media', 'code' );

	/** A running job whose heartbeat is older than this is restarted. */
	public const STALE_AFTER = 5 * MINUTE_IN_SECONDS;

	/** How long finished files are kept. */
	public const KEEP_FOR = 3 * DAY_IN_SECONDS;

	/** Builds running at once on the network; the others wait their turn. */
	public const MAX_RUNNING = 2;

	/** A job is given up after this many claims. */
	public const MAX_ATTEMPTS = 3;

	/** Bump when the table changes. */
	private const DB_VERSION = '1';

	/** Network option recording the installed version. */
	private const DB_VERSION_OPTION = 'mody_export_jobs_db_version';

	/**
	 * The table.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'mody_export_jobs';
	}

	/**
	 * `init`: creates or updates the table when its version changed.
	 */
	public static function install(): void {
		if ( self::DB_VERSION === get_site_option( self::DB_VERSION_OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta() needs this exact layout: one column per line, two spaces after PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  token char(32) NOT NULL,
  step enum('database','media','code','done') NOT NULL DEFAULT 'database',
  status enum('queued','running','done','failed','expired') NOT NULL DEFAULT 'queued',
  attempts int(11) NOT NULL DEFAULT 0,
  progress text,
  files longtext,
  last_error text,
  downloads int(11) NOT NULL DEFAULT 0,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  finished_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY site_id (site_id),
  KEY status (status)
) {$charset};"
		);
		update_site_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * A new job for a site, or the one already queued or running.
	 *
	 * @param int $site_id Site.
	 * @param int $user_id Who asked.
	 * @return int Job ID; 0 on a database error.
	 */
	public static function create( int $site_id, int $user_id ): int {
		global $wpdb;
		// One live job per site, even when two requests arrive together.
		$lock = 'mody_export_site_' . $site_id;
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 5 )', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A named lock.
		try {
			$live = self::current( $site_id );
			if ( $live && in_array( $live['status'], array( 'queued', 'running' ), true ) ) {
				return $live['id'];
			}
			return self::insert( $site_id, $user_id );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A named lock.
		}
	}

	/**
	 * Inserts a job row.
	 *
	 * @param int $site_id Site.
	 * @param int $user_id Who asked.
	 */
	private static function insert( int $site_id, int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table; one row.
		$ok = $wpdb->insert(
			self::table(),
			array(
				'site_id'  => $site_id,
				'user_id'  => $user_id,
				'token'    => bin2hex( random_bytes( 16 ) ),
				'progress' => '{}',
				'files'    => '{}',
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * A job.
	 *
	 * @param int $job_id Job.
	 * @return array{id: int, site_id: int, user_id: int, token: string, step: string, status: string, attempts: int, progress: array, files: array, last_error: string, downloads: int, created_at: string, updated_at: string, finished_at: string}|null
	 */
	public static function get( int $job_id ): ?array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; primary key.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ), ARRAY_A );
		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * A site's latest job that is not expired.
	 *
	 * @param int $site_id Site.
	 */
	public static function current( int $site_id ): ?array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE site_id = %d AND status <> 'expired' ORDER BY id DESC LIMIT 1", $site_id ), ARRAY_A );
		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * Jobs waiting for a builder: queued, or running with a stale heartbeat.
	 *
	 * @return int[] Job IDs, oldest first.
	 */
	public static function waiting(): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'queued' OR ( status = 'running' AND updated_at < %s ) ORDER BY id ASC", self::stale_time() ) ) );
	}

	/**
	 * Builds running now, with a fresh heartbeat.
	 */
	public static function running(): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'running' AND updated_at >= %s", self::stale_time() ) );
	}

	/**
	 * Claims a job for one builder: atomic, so two processes never build the
	 * same job. Queued jobs, and running ones whose builder went quiet.
	 *
	 * @param int $job_id Job.
	 * @return bool Whether this process has it.
	 */
	public static function claim( int $job_id ): bool {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; the compare-and-set is the lock.
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'running', attempts = attempts + 1, updated_at = CURRENT_TIMESTAMP WHERE id = %d AND attempts < %d AND ( status = 'queued' OR ( status = 'running' AND updated_at < %s ) )", $job_id, self::MAX_ATTEMPTS, self::stale_time() ) );
		return 1 === $claimed;
	}

	/**
	 * Heartbeat and progress: the step, and how far into it.
	 *
	 * @param int    $job_id Job.
	 * @param string $step   One of STEPS.
	 * @param int    $done   Units done (bytes, rows).
	 * @param int    $total  Units in the step; 0 when unknown.
	 */
	public static function progress( int $job_id, string $step, int $done = 0, int $total = 0 ): void {
		global $wpdb;
		$table    = self::table();
		$progress = wp_json_encode(
			array(
				'done'  => $done,
				'total' => $total,
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; the heartbeat is set even when nothing else changed.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET step = %s, progress = %s, updated_at = CURRENT_TIMESTAMP WHERE id = %d", $step, $progress, $job_id ) );
	}

	/**
	 * A finished file: its name, size and checksum.
	 *
	 * @param int                                              $job_id Job.
	 * @param string                                           $which  One of FILES.
	 * @param array{name: string, size: int, sha256: string} $file   The file.
	 */
	public static function add_file( int $job_id, string $which, array $file ): void {
		$job = self::get( $job_id );
		if ( $job ) {
			$files           = $job['files'];
			$files[ $which ] = $file;
			self::update( $job_id, array( 'files' => wp_json_encode( $files ) ) );
		}
	}

	/**
	 * The job is done: every file is ready.
	 *
	 * @param int $job_id Job.
	 */
	public static function finish( int $job_id ): void {
		self::update(
			$job_id,
			array(
				'step'        => 'done',
				'status'      => 'done',
				'last_error'  => '',
				'finished_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * The job failed: queued again unless it ran out of attempts.
	 *
	 * @param int    $job_id Job.
	 * @param string $error  Why, for the log (never shown with paths to the owner).
	 */
	public static function fail( int $job_id, string $error ): void {
		$job = self::get( $job_id );
		self::update(
			$job_id,
			array(
				'status'     => $job && $job['attempts'] < self::MAX_ATTEMPTS ? 'queued' : 'failed',
				'last_error' => mb_substr( $error, 0, 2000 ),
			)
		);
	}

	/**
	 * The job's files are gone.
	 *
	 * @param int $job_id Job.
	 */
	public static function expire( int $job_id ): void {
		self::update( $job_id, array( 'status' => 'expired' ) );
	}

	/**
	 * Counts a download.
	 *
	 * @param int $job_id Job.
	 */
	public static function count_download( int $job_id ): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; keeps updated_at.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET downloads = downloads + 1, updated_at = updated_at WHERE id = %d", $job_id ) );
	}

	/**
	 * Finished jobs older than KEEP_FOR, and failed ones a day old.
	 *
	 * @return array<int, array> Jobs.
	 */
	public static function expired(): array {
		global $wpdb;
		$table = self::table();
		$keep  = gmdate( 'Y-m-d H:i:s', time() - self::KEEP_FOR );
		$day   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ( status = 'done' AND finished_at < %s ) OR ( status = 'failed' AND updated_at < %s )", $keep, $day ), ARRAY_A );
		return array_map( array( self::class, 'shape' ), $rows );
	}

	/**
	 * Where a job's files are written: `<exports>/<site>/<token>/`.
	 *
	 * @param array $job A job.
	 */
	public static function dir( array $job ): string {
		return self::root() . '/' . (int) $job['site_id'] . '/' . $job['token'];
	}

	/**
	 * The exports folder: app/dumps/exports, beside the content folder,
	 * outside the web's reach (Nginx denies dumps/).
	 */
	public static function root(): string {
		/**
		 * Filters the folder download files are written to.
		 *
		 * @param string $root An absolute path, no trailing slash.
		 */
		return untrailingslashit( (string) apply_filters( 'mody_export_root', dirname( WP_CONTENT_DIR ) . '/dumps/exports' ) );
	}

	/**
	 * A file's name as the owner downloads it: `<site>-<which>-<date>.<ext>`.
	 *
	 * @param array  $job   A job.
	 * @param string $which One of FILES.
	 */
	public static function file_name( array $job, string $which ): string {
		$site = get_site( (int) $job['site_id'] );
		$slug = $site ? sanitize_title( strtok( $site->domain, '.' ) ?: 'site' ) : 'site';
		$date = gmdate( 'Y-m-d', strtotime( $job['created_at'] . ' UTC' ) ?: time() );
		return $slug . '-' . $which . '-' . $date . ( 'database' === $which ? '.sql.gz' : '.zip' );
	}

	/**
	 * A row as an array of the right types.
	 *
	 * @param array $row A table row.
	 */
	private static function shape( array $row ): array {
		$progress = json_decode( (string) $row['progress'], true );
		$files    = json_decode( (string) $row['files'], true );
		return array(
			'id'          => (int) $row['id'],
			'site_id'     => (int) $row['site_id'],
			'user_id'     => (int) $row['user_id'],
			'token'       => (string) $row['token'],
			'step'        => (string) $row['step'],
			'status'      => (string) $row['status'],
			'attempts'    => (int) $row['attempts'],
			'progress'    => is_array( $progress ) ? $progress : array(),
			'files'       => is_array( $files ) ? $files : array(),
			'last_error'  => (string) $row['last_error'],
			'downloads'   => (int) $row['downloads'],
			'created_at'  => (string) $row['created_at'],
			'updated_at'  => (string) $row['updated_at'],
			'finished_at' => (string) $row['finished_at'],
		);
	}

	/**
	 * Columns of a job.
	 *
	 * @param int                  $job_id Job.
	 * @param array<string, mixed> $data   Column => value.
	 */
	private static function update( int $job_id, array $data ): void {
		global $wpdb;
		$wpdb->update( self::table(), $data, array( 'id' => $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table; one row.
	}

	/**
	 * The heartbeat older than which a running job counts as stale, in the
	 * database's time (timestamps are written by the database).
	 */
	private static function stale_time(): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT CURRENT_TIMESTAMP - INTERVAL %d SECOND', self::STALE_AFTER ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The database's clock.
	}
}
