<?php
/**
 * Tests the database file of a site's download (Export\DatabaseDump,
 * tickets/take-your-site-with-you.md): what it takes, what it cleans, the
 * format, and that it loads back into a plain single-site database.
 *
 * Run from the repository root with the local stack up:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://alpha.mody.local eval-file - < scripts/export-database-test.php
 *
 * 1. A throwaway site with awkward data: users with and without a role on it
 *    (a super admin, a spam account, an outsider), posts by authors that are
 *    not exported, transients, `wpai_*` and credential-like options, a draft
 *    with a zero date, a 300 KB row, 1,100 meta rows, tables of a plugin (with
 *    a MariaDB collation, a foreign key, foreign keys to the network's users
 *    and usermeta tables, a composite key, no key, a blob, BIT columns), the
 *    `ai` plugin's tables (request log, embeddings, queue, a made-up one) and
 *    its comment and post meta, the platform's `_mody_*` post meta, core's
 *    recovery keys and admin-email hash, a view, and a lookalike table of
 *    another site.
 * 2. The dump: its first line, its statements, no hashes, no transients,
 *    nothing of the main site or other sites, progress, batching.
 * 3. A restore into a scratch database (mody_exit_db_test) holding a fresh
 *    single-site schema: every statement runs, the row counts equal the
 *    manifest's, the awkward strings come back byte for byte, authors and
 *    usermeta are right.
 * 4. A site without a `WPLANG` row, and the failures: the main site, a
 *    missing owner, an unwritable folder, a crash half way (no file left).
 *
 * The scratch database and the site are removed at the end. No model call.
 */

use Mody\Platform\Export\DatabaseDump;
use Mody\Platform\Export\Exporter;

require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$failures = 0;
$checks   = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures, &$checks ): void {
	++$checks;
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-70s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

global $wpdb;
mysqli_report( MYSQLI_REPORT_OFF );
$root_pass = (string) getenv( 'DB_ROOT_PASSWORD' );
$db_host   = (string) getenv( 'WORDPRESS_DB_HOST' );
if ( '' === $root_pass || '' === $db_host ) {
	$check( 'the root credentials are in the container environment', false );
	exit( 1 );
}

$suffix     = strtolower( wp_generate_password( 6, false ) );
$start_blog = get_current_blog_id();
$dir     = '/tmp/mody-exit-db-test';
$scratch = 'mody_exit_db_test';
$dump    = $dir . '/database.sql.gz';
$site    = 0;
$users   = array();

$root = new mysqli( $db_host, 'root', $root_pass );
$root->set_charset( 'utf8mb4' );

register_shutdown_function(
	static function () use ( &$site, &$users, $dir, $scratch, $root ): void {
		unset( $GLOBALS['super_admins'] );
		global $wpdb;
		$root->query( 'DROP DATABASE IF EXISTS `' . $scratch . '`' );
		if ( $site ) {
			$prefix = $wpdb->get_blog_prefix( $site );
			$wpdb->query( "DROP VIEW IF EXISTS `{$prefix}v`" ); // phpcs:ignore WordPress.DB
			wpmu_delete_blog( $site, true );
			foreach ( (array) $wpdb->get_col( 'SHOW TABLES LIKE \'' . esc_sql( $wpdb->esc_like( $prefix ) ) . '%\'' ) as $table ) { // phpcs:ignore WordPress.DB
				$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); // phpcs:ignore WordPress.DB
			}
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->base_prefix . $site . '7_zz`' ); // phpcs:ignore WordPress.DB
		}
		foreach ( $users as $user_id ) {
			wpmu_delete_user( $user_id );
		}
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			is_file( (string) $file ) && unlink( (string) $file );
		}
		is_dir( $dir ) && rmdir( $dir );
	}
);

// 1. The fixture ----------------------------------------------------------------------

echo "\nA throwaway site\n";
foreach ( (array) glob( $dir . '/*' ) as $file ) {
	is_file( (string) $file ) && unlink( (string) $file );
}
wp_mkdir_p( $dir );
kses_remove_filters(); // The fixtures hold odd text on purpose.

$new = static function ( string $login ) use ( $suffix, &$users ): int {
	$id      = (int) wp_insert_user(
		array(
			'user_login' => $login . $suffix,
			'user_email' => $login . $suffix . '@mody.local',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => '',
		)
	);
	$users[] = $id;
	return $id;
};
$owner    = $new( 'exdbowner' );
$editor   = $new( 'exdbeditor' );
$outsider = $new( 'exdbout' );
$super    = $new( 'exdbsuper' );
$spam     = $new( 'exdbspam' );
$domain   = 'exdb' . $suffix . '.' . preg_replace( '/^www\./', '', get_network()->domain );
$site     = (int) wpmu_create_blog( $domain, '/', 'Export DB test', $owner );
$check( 'a site and five users', $site > 0 && $owner && $editor && $outsider && $super && $spam, (string) $site );
if ( $site < 1 ) {
	exit( 1 );
}
$p = $wpdb->get_blog_prefix( $site );
add_user_to_blog( $site, $editor, 'editor' );
add_user_to_blog( $site, $super, 'editor' );
add_user_to_blog( $site, $spam, 'subscriber' );
$wpdb->update( $wpdb->users, array( 'spam' => 1 ), array( 'ID' => $spam ) ); // phpcs:ignore WordPress.DB
$wpdb->update( $wpdb->users, array( 'user_activation_key' => '1700000000:$P$Bxyzxyzxyzxyzxyzxyzxyzxyzxyzxy.' ), array( 'ID' => $editor ) ); // phpcs:ignore WordPress.DB
// Another site's role for the owner (a lookalike id), and their role on the main site.
update_user_meta( $owner, 'wp_' . ( $site + 7000 ) . '_capabilities', array( 'editor' => true ) );
update_user_meta( $owner, $wpdb->base_prefix . 'capabilities', array( 'subscriber' => true ) );
update_user_meta( $owner, $wpdb->base_prefix . 'user_level', '0' );
update_user_meta( $owner, '_application_passwords', array( array( 'uuid' => wp_generate_uuid4(), 'app_id' => '', 'name' => 'fixture', 'password' => wp_hash_password( 'x' ), 'created' => time(), 'last_used' => null, 'last_ip' => null ) ) );
update_user_meta( $owner, 'mody_onboarding', array( 'step' => 2 ) );
update_user_meta( $owner, 'mody_avatar', '12' );
update_user_meta( $owner, 'mody_avatar_urls', array( 'x' => 'https://mody.local/a.png' ) );
update_user_meta( $owner, 'infinite_scrolling', '1' );
update_user_meta( $owner, 'description', 'I love {{prefix}} templates, 100% and \'quotes\'' );
update_user_meta( $owner, 'first_name', 'Ada' );
WP_Session_Tokens::get_instance( $owner )->create( time() + HOUR_IN_SECONDS );

switch_to_blog( $site );
update_user_option( $owner, 'mody_agent_held_change', array( 'x' => 1 ) );
update_user_option( $owner, 'mody_admin_language', 'es_ES' );
update_user_option( $owner, 'persisted_preferences', array( 'core' => array( 'fixedToolbar' => true ) ) );

$tricky = "Line one\nLine two\r\n'single' \"double\" back\\slash \\n literal 100% %s %d {{prefix}} {{prefix}}posts \u{1F600} \u{65E5}\u{672C}\u{8A9E} \$ \x1a end";
$post   = static function ( array $args ) use ( $wpdb, $owner ): int {
	$author = $args['author'] ?? $owner;
	unset( $args['author'] );
	$id = (int) wp_insert_post( wp_slash( $args + array( 'post_status' => 'publish', 'post_author' => $owner, 'post_type' => 'post' ) ) );
	$wpdb->update( $wpdb->posts, array( 'post_author' => $author ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB
	return $id;
};
$p_tricky = $post( array( 'post_title' => 'Tricky', 'post_content' => $tricky, 'author' => $outsider ) );
$p_gone   = $post( array( 'post_title' => 'Gone author', 'post_content' => 'x', 'author' => 424242 ) );
$p_super  = $post( array( 'post_title' => 'By the super admin', 'post_content' => 'x', 'author' => $super ) );
$p_editor = $post( array( 'post_title' => 'By the editor', 'post_content' => 'x', 'post_type' => 'page', 'author' => $editor ) );
$p_owner  = $post( array( 'post_title' => 'By the owner', 'post_content' => 'x' ) );
$p_draft  = $post( array( 'post_title' => 'A draft', 'post_content' => 'x', 'post_status' => 'draft' ) );
$p_big    = $post( array( 'post_title' => 'Big', 'post_content' => 'BIGROWMARK ' . str_repeat( "abcdefghij \u{1F600} ", 20000 ) ) );
$check( 'fixture: a draft with a zero GMT date', '0000-00-00 00:00:00' === $wpdb->get_var( "SELECT post_date_gmt FROM {$wpdb->posts} WHERE ID = {$p_draft}" ) ); // phpcs:ignore WordPress.DB

$rows = array();
for ( $i = 1; $i <= 1100; $i++ ) {
	$rows[] = $wpdb->prepare( '(%d, %s, %s)', $p_owner, 'mody_bulk', str_repeat( 'm', 400 ) . $i );
}
$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB
add_post_meta( $p_owner, '_edit_lock', time() . ':' . $owner );
add_post_meta( $p_owner, '_edit_last', $outsider );
add_post_meta( $p_owner, 'mody_seo_title', 'A title' );
add_post_meta( $p_owner, 'mody_lang', 'es' );
add_post_meta( $p_owner, 'mody_source', $p_tricky );
add_post_meta( $p_owner, '_wpai_monitor_record', '{"x":1}' );
add_post_meta( $p_owner, 'wpai_tts_display_audio', '1' ); // The ai plugin's speech flag has no underscore.
add_post_meta( $p_owner, '_mody_provisioning_job', '77' );
add_post_meta( $p_owner, '_mody_fallback', '1' );

$c_out = (int) wp_insert_comment( wp_slash( array( 'comment_post_ID' => $p_owner, 'comment_content' => "hi 'x' %s", 'user_id' => $outsider, 'comment_author' => 'Out', 'comment_author_email' => 'out@example.org', 'comment_approved' => 1 ) ) );
wp_insert_comment( wp_slash( array( 'comment_post_ID' => $p_owner, 'comment_content' => 'by the editor', 'user_id' => $editor, 'comment_author' => 'Ed', 'comment_author_email' => 'ed@example.org', 'comment_approved' => 1 ) ) );
wp_insert_comment( wp_slash( array( 'comment_post_ID' => $p_owner, 'comment_content' => 'a guest', 'user_id' => 0, 'comment_author' => 'Guest', 'comment_author_email' => 'g@example.org', 'comment_approved' => 1 ) ) );
foreach ( array( '_wpai_toxicity_score' => '0.91', '_wpai_sentiment' => 'negative', '_wpai_value_score' => '3', '_wpai_analysis_status' => 'done', '_wpai_analyzed_at' => '1700000000', 'wpai_note' => 'x', 'mody_comment_note' => 'KEEPCOMMENTMETA' ) as $key => $value ) {
	add_comment_meta( $c_out, $key, $value );
}
$wpdb->insert( $wpdb->links, array( 'link_url' => 'https://example.org/', 'link_name' => 'Example', 'link_owner' => $outsider ) ); // phpcs:ignore WordPress.DB
$wpdb->insert( $wpdb->links, array( 'link_url' => 'https://example.net/', 'link_name' => 'Net', 'link_owner' => $owner ) ); // phpcs:ignore WordPress.DB

$fixture_options = array(
	'_transient_mody_x'                    => 'x',
	'_transient_timeout_mody_x'            => '123',
	'wpai_toggle'                          => '1',
	'connectors_ai_x_api_key'              => 'sk-not-for-you',
	'mody_agent_settings_x'                => 'x',
	'mody_analytics_note'                  => 'x',
	'spectra_blocks_usage_events_pending'  => 'a:0:{}',
	'my_plugin_api_key'                    => 'abc123',
	'my_plugin_license'                    => '',
	'my_payment_secret'                    => 'whsec_123',
	'test_text_option'                     => "Line1\nLine2\r\n'q' \"dq\" \\ 100% {{prefix}} \u{1F600}",
	'test_serialized_option'               => array( 'a' => "x'y\"z\n", 'tok' => '{{prefix}}', 'pct' => '100%' ),
	'mody_mission'                         => 'Mission',
	'recovery_keys'                        => array( 'tok123abc' => array( 'hashed_key' => '$P$BRECOVERYKEYHASHxyzxyzxyzxyzxyz.', 'created_at' => 1700000000 ) ),
	'adminhash'                            => array( 'hash' => 'ADMINHASHTOKEN', 'newemail' => 'pending@example.org' ),
	'new_admin_email'                      => 'pending@example.org',
);
foreach ( $fixture_options as $name => $value ) {
	update_option( $name, $value, false );
}
$wpdb->query( "UPDATE {$wpdb->options} SET option_value = 'sekret' WHERE option_name = 'mailserver_pass'" ); // phpcs:ignore WordPress.DB
$wpdb->query( "UPDATE {$wpdb->options} SET option_value = 'es_ES' WHERE option_name = 'WPLANG'" ); // phpcs:ignore WordPress.DB -- update_option() refuses a language that is not installed.
wp_cache_flush();

// A plugin's tables.
$wpdb->query( "CREATE TABLE {$p}testplugin (
	id bigint unsigned NOT NULL AUTO_INCREMENT,
	title varchar(190) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT '',
	body longtext COLLATE utf8mb4_unicode_520_ci,
	raw blob,
	price decimal(10,2) DEFAULT NULL,
	ratio double DEFAULT NULL,
	happened datetime DEFAULT NULL,
	flag tinyint(1) NOT NULL DEFAULT 0,
	PRIMARY KEY (id),
	KEY title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci" ); // phpcs:ignore WordPress.DB
$wpdb->query( "CREATE TABLE {$p}child (
	id int NOT NULL AUTO_INCREMENT,
	parent_id bigint unsigned NOT NULL,
	PRIMARY KEY (id),
	CONSTRAINT {$p}child_parent FOREIGN KEY (parent_id) REFERENCES {$p}testplugin (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" ); // phpcs:ignore WordPress.DB
$wpdb->query( "CREATE TABLE {$p}nopk (a int NOT NULL, b varchar(40) DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" ); // phpcs:ignore WordPress.DB
$wpdb->query( "CREATE TABLE {$p}compkey (a int NOT NULL, b int NOT NULL, v varchar(20) DEFAULT NULL, PRIMARY KEY (a, b)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" ); // phpcs:ignore WordPress.DB
$wpdb->query( "CREATE TABLE {$p}types (
	id int NOT NULL AUTO_INCREMENT,
	b5 bit(5) DEFAULT NULL,
	b64 bit(64) DEFAULT NULL,
	b1 bit(1) NOT NULL DEFAULT b'0',
	PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" ); // phpcs:ignore WordPress.DB
$wpdb->query( "INSERT INTO {$p}types (b5, b64, b1) VALUES (21, 18446744073709551615, 1), (31, 1099511627776, 0), (NULL, 0, 1), (0, NULL, 0)" ); // phpcs:ignore WordPress.DB
// A plugin table whose foreign keys point at the network's users and usermeta tables (the owner and the editor are exported).
$first_name_meta = (int) $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'first_name'", $owner ) ); // phpcs:ignore WordPress.DB
$wpdb->query( "CREATE TABLE {$p}userref (
	id int NOT NULL AUTO_INCREMENT,
	user_id bigint unsigned NOT NULL,
	meta_id bigint unsigned DEFAULT NULL,
	PRIMARY KEY (id),
	CONSTRAINT {$p}userref_user FOREIGN KEY (user_id) REFERENCES `{$wpdb->users}` (`ID`) ON DELETE CASCADE,
	CONSTRAINT {$p}userref_meta FOREIGN KEY (meta_id) REFERENCES {$wpdb->usermeta} (umeta_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" ); // phpcs:ignore WordPress.DB
$wpdb->query( "INSERT INTO {$p}userref (user_id, meta_id) VALUES ({$owner}, {$first_name_meta}), ({$editor}, NULL)" ); // phpcs:ignore WordPress.DB
$wpdb->query( "CREATE VIEW {$p}v AS SELECT a FROM {$p}nopk" ); // phpcs:ignore WordPress.DB
$wpdb->query( "INSERT INTO {$p}testplugin (title, body, raw, price, ratio, happened, flag) VALUES
	('Emoji \u{1F600} and {{prefix}}', 'body {{prefix}}posts', 0x0001FF27225C0A, 19.99, 0.00000015, '2026-01-02 03:04:05', 1),
	('Nulls', NULL, NULL, NULL, NULL, NULL, 0),
	('Quote''s', '', '', 0.00, 2, '0000-00-00 00:00:00', 0)" ); // phpcs:ignore WordPress.DB
$wpdb->query( "INSERT INTO {$p}child (parent_id) VALUES (1), (2)" ); // phpcs:ignore WordPress.DB
$wpdb->query( "INSERT INTO {$p}nopk (a, b) VALUES (1, 'one'), (1, 'one'), (2, NULL), (3, 'three\n3'), (4, '4')" ); // phpcs:ignore WordPress.DB
for ( $a = 1; $a <= 13; $a++ ) {
	$values = array();
	for ( $b = 1; $b <= 100; $b++ ) {
		$values[] = "({$a}, {$b}, 'v{$a}-{$b}')";
	}
	$wpdb->query( "INSERT INTO {$p}compkey (a, b, v) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB
}
// The ai plugin's request log (created per site; made here if the plugin did not) and a lookalike table of another site.
if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$p}wpai_request_logs'" ) ) { // phpcs:ignore WordPress.DB
	$wpdb->query( "CREATE TABLE {$p}wpai_request_logs (id int NOT NULL AUTO_INCREMENT, note text, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" ); // phpcs:ignore WordPress.DB
}
$logs_columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$p}wpai_request_logs" ); // phpcs:ignore WordPress.DB
$wpdb->query( "INSERT INTO {$p}wpai_request_logs (" . $logs_columns[1] . ") VALUES ('LOGROW')" ); // phpcs:ignore WordPress.DB
foreach ( array( 'wpai_embeddings' => 'EMBEDROW', 'wpai_embedding_queue' => 'QUEUEROW', 'wpai_made_up_later' => 'LATERROW' ) as $ai_table => $marker ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$p}{$ai_table}" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "CREATE TABLE {$p}{$ai_table} (id int NOT NULL AUTO_INCREMENT, note longblob, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "INSERT INTO {$p}{$ai_table} (note) VALUES ('{$marker}')" ); // phpcs:ignore WordPress.DB
}
$wpdb->query( 'CREATE TABLE `' . $wpdb->base_prefix . $site . "7_zz` (id int NOT NULL, note varchar(20)) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB
$wpdb->query( 'INSERT INTO `' . $wpdb->base_prefix . $site . "7_zz` VALUES (1, 'LOOKALIKE')" ); // phpcs:ignore WordPress.DB
restore_current_blog();
$check( 'the fixture is in place', $p_tricky > 0 && $p_big > 0 && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}compkey" ) === 1300 ); // phpcs:ignore WordPress.DB

// 2. The dump -------------------------------------------------------------------------

echo "\nThe dump\n";
$GLOBALS['super_admins'] = array( 'exdbsuper' . $suffix ); // What get_super_admins() reads; nothing is written to the network.
switch_to_blog( $site );
$manifest = Exporter::manifest(
	array(
		'site_id'    => $site,
		'user_id'    => $owner,
		'token'      => bin2hex( random_bytes( 16 ) ),
		'created_at' => gmdate( 'Y-m-d H:i:s' ),
	)
);
$calls    = array();
$result   = DatabaseDump::write(
	$site,
	$owner,
	$dump,
	$manifest,
	static function ( int $done, int $total ) use ( &$calls ): void {
		$calls[] = array( $done, $total );
	}
);
restore_current_blog();
$check( 'write() returns true', true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
if ( true !== $result ) {
	exit( 1 );
}
$check( 'the file is there, and no .part file is left', is_file( $dump ) && ! file_exists( $dump . '.part' ) && ! file_exists( $dump . '.body.part' ), size_format( (int) filesize( $dump ) ) );

$read_lines = static function ( string $path ): array {
	$gz    = gzopen( $path, 'rb' );
	$lines = array();
	while ( $gz && ! gzeof( $gz ) ) {
		$line = gzgets( $gz, 4194304 );
		if ( false === $line ) {
			break;
		}
		$lines[] = rtrim( $line, "\n" );
	}
	$gz && gzclose( $gz );
	return $lines;
};
$lines = $read_lines( $dump );
$text  = implode( "\n", $lines );
$meta  = DatabaseDump::read_manifest( $dump );
$check( 'the first line is a manifest comment that parses', is_array( $meta ) && str_starts_with( $lines[0], '-- mody-export: ' ) && 'database' === ( $meta['file'] ?? '' ) );
$check( '... it carries the manifest passed in', is_array( $meta ) && $manifest['export_id'] === $meta['export_id'] && $p === $meta['source']['prefix'] && $manifest['site']['home'] === $meta['site']['home'] );
$check( '... and the database part: format, token, owner, no hashes', is_array( $meta ) && '1.0' === $meta['database']['format'] && '{{prefix}}' === $meta['database']['prefix_token'] && $owner === $meta['database']['users']['owner'] && false === $meta['database']['users']['password_hashes'] );
$check( 'the last line says the file is complete', str_starts_with( (string) end( $lines ), '-- mody-export-end: ' ) && 2 === count( preg_grep( '/^--/', $lines ) ) );
$statements = array_values( preg_grep( '/^--/', $lines, PREG_GREP_INVERT ) );
$bad        = array_filter( $statements, static fn( string $l ): bool => ! str_ends_with( $l, ';' ) || ! preg_match( '/^(INSERT INTO|CREATE TABLE IF NOT EXISTS) `\{\{prefix\}\}[a-z_0-9]+`/i', $l ) );
$check( 'every statement is one line, ends with ";" and names a tokenized table', $statements && ! $bad, count( $statements ) . ' statements' );
$check( 'no DELETE, TRUNCATE or DROP', ! preg_grep( '/^(DELETE|TRUNCATE|DROP|ALTER|SET|USE)\b/i', $statements ) );
$check( 'no password hash and no activation key in the file', ! preg_match( '/\$wp\$|\$P\$|\$2y\$|\$2a\$|1700000000:/', $text ) );
$check( 'no transient, no wpai_, no connectors_, no agent option', ! preg_match( "/'(_transient_|_site_transient_|wpai_|connectors_|mody_agent|mody_analytics)/", $text ) );
$check( 'no cron, rewrite rules, plugin lists or quota options', ! preg_match( "/'(cron|rewrite_rules|active_plugins|uninstall_plugins|recently_activated|blog_upload_space|wp_force_deactivated_plugins|spectra_blocks_usage_events_pending)'/", $text ) );
$check( 'no credential-like option value in the file', ! preg_match( '/sk-not-for-you|abc123|whsec_123|sekret/', $text ) );
$check( 'no recovery key hash, admin-email hash or pending admin email', ! preg_match( "/RECOVERYKEYHASH|tok123abc|ADMINHASHTOKEN|pending@example\\.org|'(recovery_keys|adminhash|new_admin_email)'/", $text ) );
$check( '... the real admin_email stays', str_contains( $text, "'admin_email'" ) );
$check( 'no wpai_ comment or post meta, no _mody_ bookkeeping: they are not in the file', ! preg_match( "/_?wpai_(toxicity_score|sentiment|value_score|analysis_status|analyzed_at|note|monitor_record)|wpai_tts_display_audio|_mody_provisioning_job|_mody_fallback/", $text ) );
$check( '... the comment meta that is not the ai plugin\'s is', str_contains( $text, 'KEEPCOMMENTMETA' ) && str_contains( $text, "'mody_seo_title'" ) );
$check( 'the ai request log is skipped, and said so', ! str_contains( $text, 'LOGROW' ) && ! isset( $meta['database']['tables']['wpai_request_logs'] ) && in_array( 'wpai_request_logs', $meta['database']['skipped_tables'], true ) );
$check( '... and so is every other table of the ai plugin: embeddings, queue, one made up later', ! preg_match( '/EMBEDROW|QUEUEROW|LATERROW/', $text ) && ! array_filter( array_keys( $meta['database']['tables'] ), static fn( string $t ): bool => str_starts_with( $t, 'wpai_' ) ) && ! array_filter( $meta['database']['created_tables'], static fn( string $t ): bool => str_starts_with( $t, 'wpai_' ) ) && ! preg_grep( '/wpai_/', array_values( preg_grep( '/^(CREATE|INSERT)/', $lines ) ) ) );
$check( '... all of them listed in skipped_tables, in order', array( 'wpai_embedding_queue', 'wpai_embeddings', 'wpai_made_up_later', 'wpai_request_logs' ) === $meta['database']['skipped_tables'], implode( ',', $meta['database']['skipped_tables'] ) );
$check( 'the lookalike table of another site is not in it', ! str_contains( $text, 'LOOKALIKE' ) && ! isset( $meta['database']['tables'][ $site . '7_zz' ] ) );
$check( 'the view is not exported', ! isset( $meta['database']['tables']['v'] ) );
$body = implode( "\n", array_slice( $lines, 1, -1 ) ); // The manifest names the source prefix on purpose.
$leak = preg_match( '/.{0,60}(' . preg_quote( $p, '/' ) . "|'wp_capabilities'|'wp_\\d+_).{0,60}/s", $body, $found ) ? $found[0] : '';
$check( 'the site prefix never appears; the main site\'s role and other sites\' keys are gone', '' === $leak, str_replace( "\n", ' ', substr( $leak, 0, 140 ) ) );
$check( 'the users: the owner and the editor only', 2 === $meta['database']['users']['exported'] && true === $meta['database']['users']['owner_in_dump'] && 2 === $meta['database']['tables']['users'] );
$stripped = $meta['database']['stripped'];
sort( $stripped );
$check( 'stripped: the credentials that held a value (not the empty license, not the connectors)', array( 'mailserver_pass', 'my_payment_secret', 'my_plugin_api_key' ) === $stripped, implode( ',', $stripped ) );
$check( 'WPLANG is written as the site has it', 'es_ES' === $meta['database']['wplang'], (string) $meta['database']['wplang'] );

$postmeta_lines = array_filter( $statements, static fn( string $l ): bool => str_starts_with( $l, 'INSERT INTO `{{prefix}}postmeta`' ) );
$too_big        = array_filter( $statements, static fn( string $l ): bool => strlen( $l ) > 262144 + 1 );
$check( 'statements are cut near 256 KB (1,100 long meta rows take two)', count( $postmeta_lines ) >= 2 && ! array_filter( $postmeta_lines, static fn( string $l ): bool => strlen( $l ) > 262144 ), count( $postmeta_lines ) . ' postmeta statements' );
$check( '... a row bigger than that stands alone', 1 === count( $too_big ) && str_contains( (string) reset( $too_big ), 'BIGROWMARK' ) && 1 === substr_count( (string) reset( $too_big ), '),(' ) + 1 );
$check( '... and the manifest knows the largest statement', $meta['database']['largest_statement'] >= strlen( (string) reset( $too_big ) ) );

$ddl = array_values( preg_grep( '/^CREATE TABLE/', $statements ) );
$names = array_map( static fn( string $l ): string => preg_match( '/`\{\{prefix\}\}([^`]+)`/', $l, $m ) ? $m[1] : '', $ddl );
$check( 'plugin tables come first as CREATE TABLE IF NOT EXISTS, core tables have none (the ai plugin\'s are not there)', array( 'child', 'compkey', 'nopk', 'testplugin', 'types', 'userref' ) === ( static function ( array $n ): array { sort( $n ); return $n; } )( $names ) && 6 === count( $names ), implode( ',', $names ) );
$check( '... parents before children (a foreign key needs it)', array_search( 'testplugin', $names, true ) < array_search( 'child', $names, true ) );
$plugin_ddl = (string) current( preg_grep( '/`\{\{prefix\}\}testplugin`/', $ddl ) );
$check( '... utf8mb4_unicode_ci, no 520 collation, no AUTO_INCREMENT counter', str_contains( $plugin_ddl, 'utf8mb4_unicode_ci' ) && ! str_contains( $plugin_ddl, '_520_' ) && ! str_contains( $plugin_ddl, 'AUTO_INCREMENT=' ), '' );
$child_ddl = (string) current( preg_grep( '/`\{\{prefix\}\}child`/', $ddl ) );
$check( '... the foreign key follows the prefix token', str_contains( $child_ddl, 'REFERENCES `{{prefix}}testplugin`' ) && ! str_contains( $child_ddl, $p ) );

$check( 'BIT columns are written as numbers (21, the 64-bit maximum), not as the bytes of their text', ( static function ( array $stmts ): bool {
	$line = (string) current( preg_grep( '/^INSERT INTO `\{\{prefix\}\}types`/', $stmts ) );
	return str_contains( $line, '(1,21,18446744073709551615,1)' ) && str_contains( $line, '(2,31,1099511627776,0)' ) && str_contains( $line, '(3,NULL,0,1)' ) && str_contains( $line, '(4,0,NULL,0)' ) && ! str_contains( $line, '0x' );
} )( $statements ), (string) current( preg_grep( '/^INSERT INTO `\{\{prefix\}\}types`/', $statements ) ) );
$userref_ddl = (string) current( preg_grep( '/`\{\{prefix\}\}userref`/', $ddl ) );
$check( 'foreign keys to the network\'s users and usermeta tables follow the token', str_contains( $userref_ddl, 'REFERENCES `{{prefix}}users` (`ID`)' ) && str_contains( $userref_ddl, 'REFERENCES `{{prefix}}usermeta` (`umeta_id`)' ) && ! preg_match( '/`' . preg_quote( $wpdb->users, '/' ) . '`|`' . preg_quote( $wpdb->usermeta, '/' ) . '`|' . preg_quote( $p, '/' ) . '/', $userref_ddl ), $userref_ddl );

$check( 'progress: called more than twice, in order, ending at the total', count( $calls ) >= 3 && $calls[ count( $calls ) - 1 ][0] === $calls[ count( $calls ) - 1 ][1] && $calls[0][1] > 2400 && 0 === $calls[0][0], count( $calls ) . ' calls, total ' . $calls[0][1] );
$monotonic = true;
foreach ( $calls as $i => $call ) {
	$monotonic = $monotonic && ( 0 === $i || $call[0] >= $calls[ $i - 1 ][0] ) && $call[1] === $calls[0][1];
}
$check( '... never going back', $monotonic );

// 3. The restore ----------------------------------------------------------------------

echo "\nA restore into a fresh single-site database\n";
$root->query( 'DROP DATABASE IF EXISTS `' . $scratch . '`' );
$root->query( 'CREATE DATABASE `' . $scratch . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
$fresh = new wpdb( 'root', $root_pass, $scratch, $db_host );
$fresh->set_prefix( 'wp_' );
$fresh->set_blog_id( 1, 1 ); // On multisite set_prefix() stops before the blog's tables.
$live_wpdb = $GLOBALS['wpdb'];
try {
	$GLOBALS['wpdb'] = $fresh;
	dbDelta( wp_get_db_schema( 'blog' ) ); // The tables a fresh WordPress creates for its one site.
} finally {
	$GLOBALS['wpdb'] = $live_wpdb;
}
$cs = $fresh->get_charset_collate();
$fresh->query( "CREATE TABLE wp_users (
	ID bigint(20) unsigned NOT NULL auto_increment,
	user_login varchar(60) NOT NULL default '',
	user_pass varchar(255) NOT NULL default '',
	user_nicename varchar(50) NOT NULL default '',
	user_email varchar(100) NOT NULL default '',
	user_url varchar(100) NOT NULL default '',
	user_registered datetime NOT NULL default '0000-00-00 00:00:00',
	user_activation_key varchar(255) NOT NULL default '',
	user_status int(11) NOT NULL default '0',
	display_name varchar(250) NOT NULL default '',
	PRIMARY KEY  (ID),
	KEY user_login_key (user_login),
	KEY user_nicename (user_nicename),
	KEY user_email (user_email)
) {$cs}" );
$fresh->query( "CREATE TABLE wp_usermeta (
	umeta_id bigint(20) unsigned NOT NULL auto_increment,
	user_id bigint(20) unsigned NOT NULL default '0',
	meta_key varchar(255) default NULL,
	meta_value longtext,
	PRIMARY KEY  (umeta_id),
	KEY user_id (user_id),
	KEY meta_key (meta_key(191))
) {$cs}" );
$check( 'a fresh schema: the core tables and the single-site users tables', 12 === count( (array) $fresh->get_col( 'SHOW TABLES' ) ), implode( ',', (array) $fresh->get_col( 'SHOW TABLES' ) ) );

$db = new mysqli( $db_host, 'root', $root_pass, $scratch );
$db->set_charset( 'utf8mb4' );
$db->query( "SET SESSION sql_mode = ''" ); // As the importer must: zero dates, no NO_BACKSLASH_ESCAPES.
$load = static function ( mysqli $db, array $lines ): array {
	$errors = array();
	foreach ( $lines as $line ) {
		if ( '' === $line || str_starts_with( $line, '--' ) ) {
			continue;
		}
		if ( ! $db->query( str_replace( '{{prefix}}', 'wp_', $line ) ) ) {
			$errors[] = $db->error . ' :: ' . substr( $line, 0, 90 );
		}
	}
	return $errors;
};
$errors = $load( $db, $lines );
$check( 'every statement runs (foreign keys on, a plain str_replace of the token)', ! $errors, (string) ( $errors[0] ?? '' ) );

$loaded = array();
foreach ( (array) $fresh->get_col( 'SHOW TABLES' ) as $table ) {
	$loaded[ substr( (string) $table, 3 ) ] = (int) $fresh->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB
}
$expected = $meta['database']['tables'];
$wrong    = array();
foreach ( $expected as $name => $count ) {
	if ( ( $loaded[ $name ] ?? -1 ) !== $count ) {
		$wrong[] = $name . ' ' . ( $loaded[ $name ] ?? 'missing' ) . '/' . $count;
	}
}
$check( 'every table holds as many rows as the manifest says', ! $wrong && count( $expected ) >= 14, implode( ', ', $wrong ) ?: count( $expected ) . ' tables, ' . $meta['database']['rows'] . ' rows' );
$check( '... and the fresh tables the dump does not name stay empty', 0 === array_sum( array_diff_key( $loaded, $expected ) ) );
$check( 'the 1,300 rows of the composite key all came, none twice', 1300 === $loaded['compkey'] && 1300 === (int) $fresh->get_var( 'SELECT COUNT(DISTINCT a, b) FROM wp_compkey' ) && $wpdb->get_var( "SELECT SUM(a * 1000 + b) FROM {$p}compkey" ) === $fresh->get_var( 'SELECT SUM(a * 1000 + b) FROM wp_compkey' ) ); // phpcs:ignore WordPress.DB
$check( 'the table without a key came whole, duplicates included', 5 === $loaded['nopk'] && 2 === (int) $fresh->get_var( "SELECT COUNT(*) FROM wp_nopk WHERE a = 1 AND b = 'one'" ) );
$check( 'the 1,100 meta rows came', 1100 === (int) $fresh->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = 'mody_bulk'" ) );

$sum = static function ( $conn, string $table, string $where = '1' ) {
	$query  = "SELECT ID, MD5(CONCAT_WS('|', post_title, post_content, post_status, post_name, post_date, post_date_gmt, post_type, post_parent, guid)) h FROM {$table} WHERE {$where} ORDER BY ID";
	$result = $conn instanceof wpdb ? $conn->get_results( $query, ARRAY_A ) : $conn->query( $query )->fetch_all( MYSQLI_ASSOC ); // phpcs:ignore WordPress.DB
	return array_column( (array) $result, 'h', 'ID' );
};
$live_posts  = $sum( $wpdb, "{$p}posts" );
$fresh_posts = $sum( $db, 'wp_posts' );
$check( 'every post comes back byte for byte (the tricky text, the 300 KB row, emoji)', $live_posts && $live_posts === $fresh_posts, count( $fresh_posts ) . ' posts' );
$check( '... the tricky text itself, with the token and the control character', $tricky === $db->query( "SELECT post_content FROM wp_posts WHERE ID = {$p_tricky}" )->fetch_row()[0] );
$check( '... the zero date of a draft', '0000-00-00 00:00:00' === $db->query( "SELECT post_date_gmt FROM wp_posts WHERE ID = {$p_draft}" )->fetch_row()[0] );
$authors = array_column( $db->query( "SELECT ID, post_author FROM wp_posts WHERE ID IN ({$p_tricky}, {$p_gone}, {$p_super}, {$p_editor}, {$p_owner})" )->fetch_all( MYSQLI_ASSOC ), 'post_author', 'ID' );
$check( 'authors outside the export became the owner; the editor stayed', array( $p_tricky => (string) $owner, $p_gone => (string) $owner, $p_super => (string) $owner, $p_editor => (string) $editor, $p_owner => (string) $owner ) === $authors, wp_json_encode( $authors ) );
$check( '... no post has an author who is not in wp_users (zero aside)', 0 === (int) $db->query( 'SELECT COUNT(*) FROM wp_posts WHERE post_author <> 0 AND post_author NOT IN (SELECT ID FROM wp_users)' )->fetch_row()[0] );
$commenters = array_column( $db->query( 'SELECT comment_content, user_id FROM wp_comments' )->fetch_all( MYSQLI_ASSOC ), 'user_id', 'comment_content' );
$check( '... comments: the outsider became the owner, the editor and the guest stayed', (string) $owner === ( $commenters["hi 'x' %s"] ?? '' ) && (string) $editor === ( $commenters['by the editor'] ?? '' ) && '0' === ( $commenters['a guest'] ?? '' ), wp_json_encode( $commenters ) );
$check( '... links', array( (string) $owner, (string) $owner ) === array_column( $db->query( 'SELECT link_owner FROM wp_links ORDER BY link_id' )->fetch_all( MYSQLI_ASSOC ), 'link_owner' ) );
$meta_query = "SELECT meta_key, meta_value FROM %s WHERE post_id = {$p_owner} AND meta_key <> 'mody_bulk'";
$meta_keys  = array_column( $db->query( sprintf( $meta_query, 'wp_postmeta' ) )->fetch_all( MYSQLI_ASSOC ), 'meta_value', 'meta_key' );
$live_meta  = array_column( (array) $wpdb->get_results( sprintf( $meta_query, "{$p}postmeta" ), ARRAY_A ), 'meta_value', 'meta_key' ); // phpcs:ignore WordPress.DB
unset( $live_meta['_edit_lock'], $live_meta['_wpai_monitor_record'], $live_meta['wpai_tts_display_audio'], $live_meta['_mody_provisioning_job'], $live_meta['_mody_fallback'] );
$live_meta['_edit_last'] = (string) $owner; // The outsider who edited last became the owner.
$check( 'post meta: no edit lock, no ai record; the SEO title and language keys stay; _edit_last follows', $live_meta === $meta_keys && isset( $meta_keys['mody_seo_title'], $meta_keys['mody_lang'], $meta_keys['mody_source'] ), wp_json_encode( array_keys( $meta_keys ) ) );
$check( '... nor the ai plugin\'s speech flag (no underscore), nor the platform\'s _mody_ bookkeeping', ! array_intersect_key( $meta_keys, array_flip( array( 'wpai_tts_display_audio', '_wpai_monitor_record', '_mody_provisioning_job', '_mody_fallback' ) ) ) );
$comment_meta = array_column( $db->query( 'SELECT meta_key, meta_value FROM wp_commentmeta' )->fetch_all( MYSQLI_ASSOC ), 'meta_value', 'meta_key' );
$check( 'comment meta: the ai plugin\'s scores are gone, the rest stays', 'KEEPCOMMENTMETA' === ( $comment_meta['mody_comment_note'] ?? '' ) && ! preg_grep( '/^_?wpai_/', array_keys( $comment_meta ) ), implode( ',', array_keys( $comment_meta ) ) );

$logins = array_column( $db->query( 'SELECT user_login, user_pass, user_activation_key FROM wp_users ORDER BY ID' )->fetch_all( MYSQLI_ASSOC ), null, 'user_login' );
$check( 'users: the owner and the editor; no super admin, no spam account, no outsider', array( 'exdbeditor' . $suffix, 'exdbowner' . $suffix ) === ( static function ( array $k ): array { sort( $k ); return $k; } )( array_keys( $logins ) ) );
$check( '... with empty user_pass and user_activation_key', ! array_filter( $logins, static fn( array $u ): bool => '' !== $u['user_pass'] || '' !== $u['user_activation_key'] ) );
$meta_rows = $db->query( 'SELECT user_id, meta_key, meta_value FROM wp_usermeta' )->fetch_all( MYSQLI_ASSOC );
$meta_names = array_unique( array_column( $meta_rows, 'meta_key' ) );
$forbidden  = array( 'session_tokens', '_application_passwords', 'primary_blog', 'source_domain', 'mody_onboarding', 'mody_avatar', 'mody_avatar_urls', 'infinite_scrolling', 'community-events-location', 'wp_mody_agent_held_change', 'mody_agent_held_change' );
$check( 'usermeta: no sessions, application passwords, onboarding, avatar, agent or unknown plugin keys', ! array_intersect( $forbidden, $meta_names ), implode( ',', $meta_names ) );
$check( '... no key of another site is left (and the main site\'s are not renamed over the site\'s)', ! preg_grep( '/^wp_\d+_/', $meta_names ) );
$caps = array();
foreach ( $meta_rows as $row ) {
	'wp_capabilities' === $row['meta_key'] && $caps[ $row['user_id'] ][] = maybe_unserialize( $row['meta_value'] );
}
$check( '... one role per user, the site\'s own: administrator and editor (not the main site\'s subscriber)', 2 === count( $caps ) && 1 === count( $caps[ $owner ] ) && isset( $caps[ $owner ][0]['administrator'] ) && ! isset( $caps[ $owner ][0]['subscriber'] ) && isset( $caps[ $editor ][0]['editor'] ), wp_json_encode( $caps ) );
$check( '... the site\'s own preferences were re-keyed (user level, language, persisted preferences)', in_array( 'wp_user_level', $meta_names, true ) && in_array( 'wp_mody_admin_language', $meta_names, true ) && in_array( 'wp_persisted_preferences', $meta_names, true ) );
$by_key = array_column( array_filter( $meta_rows, static fn( array $r ): bool => (string) $owner === $r['user_id'] ), 'meta_value', 'meta_key' );
$check( '... plain keys travel, and their text comes back intact', 'Ada' === ( $by_key['first_name'] ?? '' ) && 'I love {{prefix}} templates, 100% and \'quotes\'' === ( $by_key['description'] ?? '' ) );

$options = array_column( $db->query( 'SELECT option_name, option_value FROM wp_options' )->fetch_all( MYSQLI_ASSOC ), 'option_value', 'option_name' );
$dropped = array( 'recovery_keys', 'adminhash', 'new_admin_email', '_transient_mody_x', '_transient_timeout_mody_x', 'wpai_toggle', 'connectors_ai_x_api_key', 'mody_agent_settings_x', 'mody_analytics_note', 'spectra_blocks_usage_events_pending', 'my_plugin_api_key', 'my_plugin_license', 'my_payment_secret', 'cron', 'rewrite_rules', 'active_plugins', 'blog_upload_space', 'uninstall_plugins' );
$check( 'options: transients, wpai_, connectors_, agent, telemetry, credentials, cron and plugin lists are gone', ! array_intersect( $dropped, array_keys( $options ) ), implode( ',', array_intersect( $dropped, array_keys( $options ) ) ) );
$check( '... the real admin email stayed; the pending change did not', isset( $options['admin_email'] ) && ! isset( $options['new_admin_email'] ) );
$check( '... the site\'s own settings stayed (name, mission, siteurl, db_version)', isset( $options['blogname'], $options['mody_mission'], $options['siteurl'], $options['db_version'] ) && 'Export DB test' === $options['blogname'], implode( ',', array_diff( array( 'blogname', 'mody_mission', 'siteurl', 'db_version' ), array_keys( $options ) ) ) . ' ' . ( $options['blogname'] ?? '-' ) );
$check( '... an option\'s text and a serialized option come back intact', $fixture_options['test_text_option'] === $options['test_text_option'] );
$check( '... the serialized one unserializes to what it was', $fixture_options['test_serialized_option'] === maybe_unserialize( $options['test_serialized_option'] ) );
$roles = maybe_unserialize( $options['wp_user_roles'] ?? '' );
$check( '... the roles are under the destination\'s own name, wp_user_roles', is_array( $roles ) && isset( $roles['administrator'], $roles['editor'] ) && ! preg_grep( '/^wp_\d+_user_roles$/', array_keys( $options ) ) );
$check( '... WPLANG is there', 'es_ES' === ( $options['WPLANG'] ?? '' ) );
$check( '... core\'s mailserver_pass holding a real value is not here', ! isset( $options['mailserver_pass'] ) );

$plugin_rows = $db->query( 'SELECT id, title, body, HEX(raw) h, price, ratio, happened, flag FROM wp_testplugin ORDER BY id' )->fetch_all( MYSQLI_ASSOC );
$live_rows   = $wpdb->get_results( "SELECT id, title, body, HEX(raw) h, price, ratio, happened, flag FROM {$p}testplugin ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB
$check( 'plugin table: values, NULLs, a blob with a quote and a newline in it, decimals, dates', 3 === count( $plugin_rows ) && $live_rows === $plugin_rows, wp_json_encode( $plugin_rows[0] ?? array() ) );
$check( '... created with the portable collation', ! str_contains( (string) $db->query( 'SHOW CREATE TABLE wp_testplugin' )->fetch_row()[1], '_520_' ) && 2 === $loaded['child'] );
$bit_query = 'SELECT id, b5 + 0 n5, HEX(b5) h5, b64 + 0 n64, HEX(b64) h64, b1 + 0 n1 FROM %s ORDER BY id';
$live_bits = $wpdb->get_results( sprintf( $bit_query, "{$p}types" ), ARRAY_A ); // phpcs:ignore WordPress.DB
$back_bits = $db->query( sprintf( $bit_query, 'wp_types' ) )->fetch_all( MYSQLI_ASSOC );
$check( 'BIT columns come back with the same values (BIT(5), BIT(64) up to its maximum, BIT(1), NULLs)', 4 === count( $back_bits ) && $live_bits === $back_bits && '21' === $back_bits[0]['n5'] && '18446744073709551615' === $back_bits[0]['n64'], wp_json_encode( $back_bits[0] ?? array() ) );
// A strict session (MySQL 8's default for a plain `mysql < file`, or the importer's own) must accept the same statements.
$strict = new mysqli( $db_host, 'root', $root_pass, $scratch );
$strict->set_charset( 'utf8mb4' );
$strict->query( "SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'" );
$strict->query( 'CREATE TABLE wp_types_strict LIKE wp_types' );
$types_line = str_replace( array( '{{prefix}}types', '{{prefix}}' ), array( 'wp_types_strict', 'wp_' ), (string) current( preg_grep( '/^INSERT INTO `\{\{prefix\}\}types`/', $statements ) ) );
$strict_ok  = $strict->query( $types_line );
$strict_bits = $strict_ok ? $strict->query( sprintf( $bit_query, 'wp_types_strict' ) )->fetch_all( MYSQLI_ASSOC ) : array();
$check( '... and a strict sql_mode takes the BIT rows too (no "Data too long")', $strict_ok && $strict_bits === $live_bits, $strict_ok ? '' : $strict->error );
$strict->close();
$ref_rows = $db->query( 'SELECT user_id, meta_id FROM wp_userref ORDER BY id' )->fetch_all( MYSQLI_ASSOC );
$check( 'the table with foreign keys to wp_users and wp_usermeta loaded with the keys on, and the keys exist', 2 === count( $ref_rows ) && (string) $owner === $ref_rows[0]['user_id'] && (string) $first_name_meta === $ref_rows[0]['meta_id'] && null === $ref_rows[1]['meta_id'] && 2 === (int) $db->query( "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '{$scratch}' AND TABLE_NAME = 'wp_userref' AND REFERENCED_TABLE_NAME IN ('wp_users', 'wp_usermeta')" )->fetch_row()[0], wp_json_encode( $ref_rows ) );

// 4. No WPLANG row, and the failures --------------------------------------------------

echo "\nA site without a language of its own, and the failures\n";
delete_blog_option( $site, 'WPLANG' );
$wpdb->query( "UPDATE {$p}options SET option_value = 'password' WHERE option_name = 'mailserver_pass'" ); // phpcs:ignore WordPress.DB
wp_cache_flush();
$second = $dir . '/second.sql.gz';
switch_to_blog( $site );
$result = DatabaseDump::write( $site, $owner, $second, $manifest, static function (): void {} );
restore_current_blog();
$second_lines = true === $result ? $read_lines( $second ) : array();
$second_meta  = true === $result ? DatabaseDump::read_manifest( $second ) : array();
$network_lang = (string) get_site_option( 'WPLANG', '' );
$wplang_line  = array_values( preg_grep( "/^INSERT INTO `\{\{prefix\}\}options` \(`option_name`,`option_value`,`autoload`\) VALUES \('WPLANG'/", $second_lines ) );
$check( 'the network\'s language is written as a row of the site\'s own', 1 === count( $wplang_line ) && str_contains( $wplang_line[0], "'" . $network_lang . "'" ) && $network_lang === ( $second_meta['database']['wplang'] ?? null ), $network_lang );
$check( '... and the options count includes it (the first dump had dropped the real mailserver_pass, this one keeps core\'s default)', ( $second_meta['database']['tables']['options'] ?? 0 ) === ( $meta['database']['tables']['options'] ?? -1 ) + 1 );
$check( '... core\'s default mailserver_pass is kept, and not listed as stripped', str_contains( implode( "\n", $second_lines ), "'mailserver_pass','password'" ) && ! in_array( 'mailserver_pass', $second_meta['database']['stripped'] ?? array(), true ) );

$result = DatabaseDump::write( 1, $owner, $dir . '/main.sql.gz', $manifest, static function (): void {} );
$check( 'the main site is refused', is_wp_error( $result ) && 'mody_dump_site' === $result->get_error_code() && ! file_exists( $dir . '/main.sql.gz' ) );
$result = DatabaseDump::write( $site, 987654321, $dir . '/nobody.sql.gz', $manifest, static function (): void {} );
$check( 'an owner who does not exist is refused', is_wp_error( $result ) && 'mody_dump_owner' === $result->get_error_code() );
$result = DatabaseDump::write( $site, $owner, '/proc/mody-nope/database.sql.gz', $manifest, static function (): void {} );
$check( 'an unwritable folder is refused', is_wp_error( $result ) && 'mody_dump_dir' === $result->get_error_code() );
$n      = 0;
$result = DatabaseDump::write(
	$site,
	$owner,
	$dir . '/crash.sql.gz',
	$manifest,
	static function () use ( &$n ): void {
		if ( ++$n > 1 ) {
			throw new RuntimeException( 'simulated crash' );
		}
	}
);
$check( 'a crash half way is an error, and leaves no file behind', is_wp_error( $result ) && str_contains( $result->get_error_message(), 'simulated crash' ) && ! glob( $dir . '/crash*' ), is_wp_error( $result ) ? $result->get_error_message() : 'no error' );
$check( 'the blog context is the one the test started in', get_current_blog_id() === $start_blog, (string) get_current_blog_id() );

echo $failures ? "\n{$failures} of {$checks} checks failed.\n" : "\nAll {$checks} checks passed.\n";
exit( $failures ? 1 : 0 );
