<?php
/**
 * Tests what the owner sees of "Take your site with you": the dashboard's
 * Download your site section and the two emails
 * (tickets/take-your-site-with-you.md).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/export-panel-test.php
 *
 * 1. Who sees it: administrators of a member site, never the main site, an
 *    editor, or a visitor; the section's place in the menu.
 * 2. The panel in every state: no job, queued, retrying, running (a known and
 *    an unknown progress), done, failed; a done job past its three days is no
 *    job; the expired-link note; the settings left out of the database file.
 *    Rule 7: nothing "unlimited", the restore is honest, an error never leaks
 *    a path.
 * 3. The entry points: the Overview card's button, and each card in Your sites.
 * 4. The status poll: refuses a missing or wrong nonce and a non-administrator,
 *    answers with the state, calls the watchdog and the sweep. Opening the
 *    section and pressing the button do the same.
 * 5. Prepare my download: nonce and capability, one job per site at a time,
 *    asked of the watchdog (never a builder directly, so the cap of two builds
 *    holds), the administrators told once and before it starts; a super
 *    administrator who does not administer the site gets the job recorded for
 *    its first administrator.
 * 6. The emails: who gets them, what they say, in each recipient's language,
 *    and that none links to a file.
 * 7. The script, and every string in the two classes carries the text domain.
 *
 * A throwaway site and five users are created and removed, with the job rows
 * made for them. The builder (Export\Runner) is replaced by a stub that
 * records its calls, unless the real one is already loaded: nothing is built
 * and no process is started. No mail leaves the process. The sweep looks in an
 * empty folder of its own, and runs only when a check asks for it.
 */

use Mody\Platform\Dashboard\DashboardPage;
use Mody\Platform\Dashboard\ExportPanel;
use Mody\Platform\Export\Downloads;
use Mody\Platform\Export\Jobs;
use Mody\Platform\Export\Notices;
use Mody\Platform\Export\Runner;
use Mody\Platform\Members\Members;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-70s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};
$skip = static function ( string $label, string $why ): void {
	printf( "%-4s %-70s %s\n", 'skip', $label, $why );
};

if ( ! class_exists( 'Mody_Export_Test_Stop' ) ) {
	/**
	 * Thrown in place of exit and wp_die(), so a handler can be run to its end.
	 */
	class Mody_Export_Test_Stop extends Exception {
		/** What was output before it, or the redirect's address. */
		public string $output = '';
	}
}

// The builder and the links are other parts: stubs when they are not there yet, and the builder never really started.
$stubbed = ! class_exists( Runner::class, false );
if ( $stubbed ) {
	eval( 'namespace Mody\Platform\Export; final class Runner { public static array $started = array(); public static array $log = array(); public static int $watched = 0; public static function start( int $job_id ): void { self::$started[] = $job_id; self::$log[] = "start"; } public static function watch(): void { ++self::$watched; self::$log[] = "watch"; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test stub.
}
if ( ! class_exists( Downloads::class ) ) {
	eval( 'namespace Mody\Platform\Export; final class Downloads { public static function url( array $job, string $which ): string { return home_url( "/stub-download/" . $job["id"] . "/" . $which ); } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test stub.
}

global $wpdb;
$entry_blog = get_current_blog_id();
Jobs::install();
$suffix = strtolower( wp_generate_password( 6, false ) );
$new    = static function ( string $login, string $name ) use ( $suffix ): int {
	return (int) wp_insert_user(
		array(
			'user_login'   => $login . $suffix,
			'user_email'   => $login . $suffix . '@mody.local',
			'user_pass'    => wp_generate_password( 24 ),
			'display_name' => $name,
			'role'         => '',
		)
	);
};
$owner  = $new( 'exown', 'Olivia Owner' );
$second = $new( 'exsec', 'Sam Second' );
$editor = $new( 'exed', 'Eddie Editor' );
$none   = $new( 'exnone', 'Nora Nobody' );
$staff  = $new( 'exstaff', 'Pat Platform' ); // A super administrator with no role on the site.
$main   = get_main_site_id();
$site   = (int) wpmu_create_blog( 'exp' . $suffix . '.' . preg_replace( '/^www\./', '', get_network()->domain ), '/', 'Export test', $owner );
add_user_to_blog( $site, $second, 'administrator' );
add_user_to_blog( $site, $editor, 'editor' );
add_user_to_blog( $main, $owner, 'administrator' ); // An administrator of the main site too: still no export there.

$swept   = get_site_option( 'mody_export_swept', 0 );
$jobs    = array();
$empty_root = '/tmp/mody-export-panel-test-' . $suffix; // The sweep's folder: nothing real is ever deleted.
wp_mkdir_p( $empty_root );
$elsewhere = static fn() => $empty_root;
add_filter( 'mody_export_root', $elsewhere );
update_site_option( 'mody_export_swept', time() ); // No sweep until a check asks for one.
$cleanup = static function () use ( $site, $owner, $second, $editor, $none, $staff, $main, $swept, $empty_root, &$jobs ): void {
	global $wpdb;
	if ( $jobs ) {
		$wpdb->query( 'DELETE FROM ' . Jobs::table() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $jobs ) ) . ')' ); // phpcs:ignore WordPress.DB -- Test rows.
	}
	if ( $site ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Jobs::table() . ' WHERE site_id = %d', $site ) ); // phpcs:ignore WordPress.DB -- Test rows.
		wpmu_delete_blog( $site, true );
	}
	remove_user_from_blog( $owner, $main );
	foreach ( array( $owner, $second, $editor, $none, $staff ) as $user_id ) {
		revoke_super_admin( $user_id );
		Members::delete( $user_id );
		wpmu_delete_user( $user_id );
	}
	@rmdir( $empty_root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- Test folder, empty.
	if ( $swept ) {
		update_site_option( 'mody_export_swept', $swept );
	} else {
		delete_site_option( 'mody_export_swept' );
	}
};
register_shutdown_function( $cleanup );
$check( 'a throwaway site, an owner, a second administrator, an editor', $site > 0 && $owner && $second && $editor && $none && $staff, (string) $site );

/** A job row for the site, replacing any other. */
$job = static function ( string $status, string $step = 'database', array $extra = array() ) use ( $site, $owner, &$jobs ): array {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Jobs::table() . ' WHERE site_id = %d', $site ) ); // phpcs:ignore WordPress.DB -- Test rows.
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test rows.
		Jobs::table(),
		array_merge(
			array(
				'site_id'  => $site,
				'user_id'  => $owner,
				'token'    => bin2hex( random_bytes( 16 ) ),
				'step'     => $step,
				'status'   => $status,
				'progress' => '{}',
				'files'    => '{}',
			),
			$extra
		)
	);
	$id     = (int) $wpdb->insert_id;
	$jobs[] = $id;
	return (array) Jobs::get( $id );
};

/** The dashboard page's HTML, for a user, on the site, on a section. */
$page = static function ( int $user_id, string $section ) use ( $site ): string {
	switch_to_blog( $site );
	wp_set_current_user( $user_id );
	$_GET['section'] = $section;
	ob_start();
	DashboardPage::render();
	unset( $_GET['section'] );
	$html = (string) ob_get_clean();
	restore_current_blog();
	return $html;
};
/** The panel alone. */
$panel = static function ( int $user_id ) use ( $site ): string {
	switch_to_blog( $site );
	wp_set_current_user( $user_id );
	ob_start();
	ExportPanel::render();
	$html = (string) ob_get_clean();
	restore_current_blog();
	return $html;
};

// 1. Who sees it -----------------------------------------------------------------------

echo "\nWho sees it\n";
switch_to_blog( $site );
wp_set_current_user( $owner );
$sections = DashboardPage::sections();
$check( 'an administrator of the member site has the section', isset( $sections['export'] ) && 'Download your site' === $sections['export'] );
$keys = array_keys( $sections );
$check( '... after Mody\'s work, before the account sections', array_search( 'export', $keys, true ) === array_search( 'mody', $keys, true ) + 1, implode( ',', $keys ) );
$check( 'available() says the same', ExportPanel::available() );
wp_set_current_user( $second );
$check( 'a second administrator too', ExportPanel::available() );
wp_set_current_user( $editor );
$check( 'an editor does not', ! ExportPanel::available() && ! isset( DashboardPage::sections()['export'] ) );
wp_set_current_user( 0 );
$check( 'nobody logged in does not', ! ExportPanel::available() );
restore_current_blog();
switch_to_blog( $main );
wp_set_current_user( $owner );
$check( 'the main site has no export, even for its administrator', current_user_can( 'manage_options' ) && ! ExportPanel::available() && ! isset( DashboardPage::sections()['export'] ) );
ob_start();
ExportPanel::render();
$main_html = (string) ob_get_clean();
restore_current_blog();
$check( '... the panel prints nothing there', '' === trim( $main_html ) );
$check( 'an editor asking for the section gets the Overview', ! str_contains( $page( $editor, 'export' ), 'Prepare my download' ) && str_contains( $page( $editor, 'export' ), 'Storage' ) );
$check( 'a non-administrator\'s panel prints nothing', '' === trim( $panel( $editor ) ) );

// 2. The panel in every state ----------------------------------------------------------

echo "\nThe panel\n";
$job( 'expired' ); // Nothing current.
$html = $panel( $owner );
$check( 'no job: Prepare my download, a form with a nonce to admin-post', str_contains( $html, 'Prepare my download' ) && str_contains( $html, 'admin-post.php' ) && str_contains( $html, 'name="_wpnonce"' ) && str_contains( $html, 'value="' . ExportPanel::ACTION . '"' ) );
$check( '... no question, no checkbox, no confirmation', ! preg_match( '/<input[^>]+type="(checkbox|text|radio)"/', $html ) && ! preg_match( '/confirm|are you sure|why are you leaving/i', $html ) );
$check( '... three files, and Mody does not come along', str_contains( $html, 'three files' ) && str_contains( $html, 'Mody, the AI agent, stays here' ) );
$check( '... what comes with it and what stays', str_contains( $html, 'What comes with it' ) && str_contains( $html, 'What stays with Mody Cloud' ) && str_contains( $html, 'without their passwords' ) && str_contains( $html, 'The visitor counter' ) && str_contains( $html, 'API keys' ) );
$check( '... the restore is honest: manual steps in the code file, no promise of one click', str_contains( $html, 'README-restore.txt' ) && str_contains( $html, 'A one-click restore is coming' ) && str_contains( $html, 'Install a fresh WordPress' ) );
$check( '... no "unlimited", and no polling script data', ! preg_match( '/unlimited/i', $html ) && ! str_contains( $html, 'data-ajax-url' ) );
$check( '... a page of the dashboard has the section, no watermark', ! str_contains( $page( $owner, 'export' ), 'mody-dashboard__wip' ) && str_contains( $page( $owner, 'export' ), 'mody-export' ) );

$row  = $job( 'queued' );
$html = $panel( $owner );
$check( 'queued: waiting for its turn, no button, the poll\'s data', str_contains( $html, 'Waiting for its turn' ) && ! str_contains( $html, 'Prepare my download' ) && str_contains( $html, 'data-ajax-url' ) && str_contains( $html, 'data-nonce' ) && str_contains( $html, 'data-interval="3000"' ) );
$check( '... the steps Database, Media, Code, none highlighted', preg_match( '/data-step="database"[^>]*>Database<.*data-step="media"[^>]*>Media<.*data-step="code"[^>]*>Code</s', $html ) && ! str_contains( $html, 'is-current' ) );
$check( '... and a way to leave the page', str_contains( $html, 'we will email you' ) );
$row  = $job( 'queued', 'media', array( 'attempts' => 1 ) );
$html = $panel( $owner );
$check( 'queued after a try: says it starts again, the database step done', str_contains( $html, 'starting again' ) && str_contains( $html, 'class="is-done" data-step="database"' ) && str_contains( $html, 'class="is-todo" data-step="media"' ) );

$row  = $job( 'running', 'media', array( 'progress' => '{"done":512,"total":1024}' ) );
$html = $panel( $owner );
$check( 'running, media: Database done, Media current, Code to do', str_contains( $html, 'class="is-done" data-step="database"' ) && str_contains( $html, 'class="is-current" data-step="media" aria-current="step"' ) && str_contains( $html, 'class="is-todo" data-step="code"' ) );
$check( '... the bar at 50%, the sentence for media', str_contains( $html, 'width:50%' ) && str_contains( $html, 'aria-valuenow="50"' ) && str_contains( $html, '>50%<' ) && str_contains( $html, 'Packing your images' ) && ! str_contains( $html, 'is-indeterminate' ) );
$row  = $job( 'running', 'database', array( 'progress' => '{"done":0,"total":0}' ) );
$html = $panel( $owner );
$check( 'running, progress unknown: an indeterminate bar, no number', str_contains( $html, 'is-indeterminate' ) && ! str_contains( $html, 'aria-valuenow' ) && str_contains( $html, 'Packing your pages' ) );
$row  = $job( 'running', 'code', array( 'progress' => '{"done":5,"total":4}' ) );
$check( 'running, progress over its total: held at 100%', str_contains( $panel( $owner ), 'width:100%' ) );

$files = array();
foreach ( Jobs::FILES as $which ) {
	$files[ $which ] = array(
		'name'   => 'exp-' . $which . '-2026-11-12.zip',
		'size'   => 'database' === $which ? 4 * MB_IN_BYTES : ( 'media' === $which ? 2 * GB_IN_BYTES : 15 * MB_IN_BYTES ),
		'sha256' => str_repeat( 'a', 64 ),
	);
}
$finished = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
$row      = $job(
	'done',
	'done',
	array(
		'files'       => wp_json_encode( $files ),
		'finished_at' => $finished,
	)
);
$html     = $panel( $owner );
preg_match_all( '/<a class="button button-primary" href="([^"]+)">Download</', $html, $links );
$check( 'done: three Download buttons, one per file', 3 === count( $links[1] ), (string) count( $links[1] ) );
$bases = array_map( static fn( string $which ): string => strtok( Downloads::url( $row, $which ), '?' ), Jobs::FILES );
$ok    = true;
foreach ( $bases as $base ) {
	$ok = $ok && 1 === count( array_filter( $links[1], static fn( string $href ): bool => str_starts_with( html_entity_decode( $href ), $base ) ) );
}
$check( '... each link is Downloads::url() for its file', $ok );
$check( '... with the sizes: 4 MB, 2 GB, 15 MB', str_contains( $html, '4.0 MB' ) && str_contains( $html, '2.0 GB' ) && str_contains( $html, '15.0 MB' ), implode( ' ', array_column( array_map( static fn( $f ) => array( size_format( $f['size'], 1 ) ), $files ), 0 ) ) );
$check( '... and their names', str_contains( $html, 'exp-code-2026-11-12.zip' ) && str_contains( $html, 'exp-database-2026-11-12.zip' ) );
$until = (int) strtotime( $finished . ' UTC' ) + Jobs::KEEP_FOR;
$check( '... kept until finished + 3 days, in the site\'s time zone', str_contains( $html, 'kept until ' . wp_date( get_option( 'date_format' ), $until ) ), wp_date( 'c', $until ) );
$check( '... a fresh copy is one click away; no polling now', str_contains( $html, 'Prepare a fresh copy' ) && ! str_contains( $html, 'data-ajax-url' ) && ! str_contains( $html, 'Prepare my download' ) );

$row  = $job( 'failed', 'media', array( 'last_error' => 'media: could not write /var/www/html/dumps/exports/1/abc/media.zip.part' ) );
$html = $panel( $owner );
$check( 'failed: a plain sentence and Try again', str_contains( $html, 'could not be prepared this time' ) && str_contains( $html, 'Try again' ) && str_contains( $html, 'Your site is untouched' ) );
$check( '... never a path or the error', ! str_contains( $html, '/var/www' ) && ! str_contains( $html, 'media.zip.part' ) && ! str_contains( $html, 'could not write' ) );

$job( 'done', 'done', array( 'files' => '{}', 'finished_at' => $finished ) );
$check( 'done without files in the row: no broken buttons', 0 === preg_match_all( '/>Download</', $panel( $owner ) ) );

// Past its three days: Downloads refuses every link, so the section must not offer them.
$lapsed_at = gmdate( 'Y-m-d H:i:s', time() - Jobs::KEEP_FOR - HOUR_IN_SECONDS );
$job( 'done', 'done', array( 'files' => wp_json_encode( $files ), 'finished_at' => $lapsed_at ) );
$html = $panel( $owner );
$check( 'done, but past its three days: no downloads, no "ready"', 0 === preg_match_all( '/>Download</', $html ) && ! str_contains( $html, 'Your download is ready' ) && ! str_contains( $html, 'kept until' ) );
$check( '... the button to start again', str_contains( $html, 'Prepare my download' ) && ! str_contains( $html, 'data-ajax-url' ) );
$check( '... view() says there is no job, and no files', 'none' === ExportPanel::view( Jobs::current( $site ) )['status'] && array() === ExportPanel::view( Jobs::current( $site ) )['files'] );
$job( 'done', 'done', array( 'files' => wp_json_encode( $files ), 'finished_at' => gmdate( 'Y-m-d H:i:s', time() - Jobs::KEEP_FOR + HOUR_IN_SECONDS ) ) );
$check( 'done with an hour left: still ready', str_contains( $panel( $owner ), 'Your download is ready' ) && 3 === preg_match_all( '/>Download</', $panel( $owner ) ) );
$job( 'done', 'done', array( 'files' => wp_json_encode( $files ), 'finished_at' => null ) );
$check( 'done with no finishing time (never real): shown as done, with no date to promise', str_contains( $panel( $owner ), 'Your download is ready' ) && ! str_contains( $panel( $owner ), 'kept until' ) );

// A dead link sends the owner back here (Downloads::EXPIRED_ARG): a short, kind note.
$arg = Downloads::EXPIRED_ARG;
$job( 'done', 'done', array( 'files' => wp_json_encode( $files ), 'finished_at' => $finished ) );
$_GET[ $arg ] = 'expired';
$html = $panel( $owner );
$check( 'an expired link, files still kept: says so, and that the buttons are new', str_contains( $html, 'That download link has expired' ) && str_contains( $html, 'buttons below are new' ) && str_contains( $html, 'notice-info' ) && 3 === preg_match_all( '/>Download</', $html ) );
$job( 'running', 'media', array( 'progress' => '{"done":1,"total":2}' ) );
$html = $panel( $owner );
$check( 'an expired link while a new download is prepared: says it is coming', str_contains( $html, 'That download link has expired' ) && str_contains( $html, 'A new download is being prepared' ) );
$job( 'done', 'done', array( 'files' => wp_json_encode( $files ), 'finished_at' => $lapsed_at ) );
$html = $panel( $owner );
$check( 'an expired link, files gone: says they are no longer kept, offers a fresh copy', str_contains( $html, 'no longer kept' ) && str_contains( $html, 'prepare a fresh copy below' ) && str_contains( $html, 'Prepare my download' ) );
$job( 'failed', 'media' );
$check( 'an expired link after a failure: the same, with Try again below', str_contains( $panel( $owner ), 'no longer kept' ) && str_contains( $panel( $owner ), 'Try again' ) );
$_GET[ $arg ] = '<script>alert(1)</script>';
$html = $panel( $owner );
$check( 'any other value of the argument shows nothing, and is never printed', ! str_contains( $html, 'has expired' ) && ! str_contains( $html, 'alert(1)' ) );
$_GET[ $arg ]           = 'expired';
$_GET['export-result'] = 'started';
$html                   = $panel( $owner );
$check( 'the result of a request wins over the note: one notice at a time', str_contains( $html, 'Started.' ) && ! str_contains( $html, 'has expired' ) );
unset( $_GET[ $arg ], $_GET['export-result'] );
$check( 'no argument, no note', ! str_contains( $panel( $owner ), 'has expired' ) );

// The settings the database file left out (credentials), named, and to be entered again on the new host.
$with_stripped = static function ( array $names ) use ( $files, $finished, $job ): array {
	$files['database']['stripped'] = $names;
	return $job( 'done', 'done', array( 'files' => wp_json_encode( $files ), 'finished_at' => $finished ) );
};
$with_stripped( array( 'my_plugin_api_key', 'smtp_password' ) );
$html = $panel( $owner );
$check( 'settings left out: named, in code', str_contains( $html, '<code>my_plugin_api_key</code>' ) && str_contains( $html, '<code>smtp_password</code>' ) && str_contains( $html, 'mody-export__left-out' ) );
$check( '... kind and clear: why, and what to do on the new host', str_contains( $html, 'Some settings that look like passwords or keys were left out of your database file' ) && str_contains( $html, 'nothing secret travels in it' ) && str_contains( $html, 'enter them again on your new host' ) );
$check( '... the three downloads are still there', 3 === preg_match_all( '/>Download</', $html ) );
$with_stripped( array( 'one_secret_key' ) );
$html = $panel( $owner );
$check( 'one setting: the singular', str_contains( $html, 'A setting that looks like a password or a key was left out' ) && str_contains( $html, 'enter it again on your new host' ) && str_contains( $html, '<code>one_secret_key</code>' ) );
$with_stripped( array( '<script>alert(1)</script>_token' ) );
$html = $panel( $owner );
$check( 'a name with markup is printed as text', str_contains( $html, '&lt;script&gt;alert(1)&lt;/script&gt;_token' ) && ! str_contains( $html, '<script>alert(1)' ) );
$twelve = array();
for ( $n = 1; $n <= 12; $n++ ) {
	$twelve[] = 'plugin_' . $n . '_secret';
}
$with_stripped( $twelve );
$html = $panel( $owner );
$check( 'twelve settings: ten named, the rest counted', str_contains( $html, '<code>plugin_10_secret</code>' ) && ! str_contains( $html, 'plugin_11_secret' ) && str_contains( $html, 'and 2 more' ) );
$with_stripped( array() );
$check( 'nothing left out: nothing said', ! str_contains( $panel( $owner ), 'left out' ) && ! str_contains( $panel( $owner ), 'mody-export__left-out' ) );
$with_stripped( array( 'a_secret', '', 5, array( 'x' ) ) );
$check( 'odd entries in the list are ignored', 1 === substr_count( $panel( $owner ), '<code>' ) && str_contains( $panel( $owner ), '<code>a_secret</code>' ) );

// 3. The entry points ------------------------------------------------------------------

echo "\nEntry points\n";
$job( 'expired' );
$html = $page( $owner, 'overview' );
$check( 'Overview: a Download your site button beside Visit site and Edit site', str_contains( $html, 'Visit site' ) && str_contains( $html, 'Edit site' ) && preg_match( '#<a class="button" href="[^"]*section=export[^"]*">Download your site</a>#', $html ) );
$check( 'Overview: not for an editor', ! str_contains( $page( $editor, 'overview' ), 'Download your site' ) );
Members::install();
Members::create( $owner );
Members::start_trial( $owner );
$sites = DashboardPage::member_sites( $owner );
$mine  = array_values( array_filter( $sites, static fn( array $s ): bool => $s['id'] === $site ) );
$check( 'member_sites() gives each site its own export address', 1 === count( $mine ) && str_contains( $mine[0]['export'], 'page=mody-dashboard' ) && str_contains( $mine[0]['export'], 'section=export' ) && str_contains( $mine[0]['export'], 'exp' . $suffix ), $mine ? $mine[0]['export'] : '' );
$html = $page( $owner, 'sites' );
$check( 'Your sites: a Download link on the site\'s card, to that site\'s section', preg_match( '#<a class="button" href="[^"]*exp' . $suffix . '[^"]*section=export[^"]*">Download</a>#', $html ) === 1 );
Members::delete( $owner );

// 4. The status poll -------------------------------------------------------------------

echo "\nStatus poll\n";
$poll = static function ( int $user_id, array $request ) use ( $site ): array {
	switch_to_blog( $site );
	wp_set_current_user( $user_id );
	$_REQUEST = $request;
	$filters  = array(
		array( 'wp_doing_ajax', '__return_true', 10 ),
		array(
			'wp_die_ajax_handler',
			static fn() => static function ( $message = '' ): void {
				$stop         = new Mody_Export_Test_Stop( 'die' );
				$stop->output = (string) ob_get_contents();
				throw $stop;
			},
			PHP_INT_MAX,
		),
	);
	foreach ( $filters as $f ) {
		add_filter( $f[0], $f[1], $f[2] );
	}
	ob_start();
	$output = '';
	try {
		ExportPanel::handle_status();
		$output = (string) ob_get_contents();
	} catch ( Mody_Export_Test_Stop $stop ) {
		$output = $stop->output;
	} finally {
		ob_end_clean();
		foreach ( $filters as $f ) {
			remove_filter( $f[0], $f[1], $f[2] );
		}
		$_REQUEST = array();
		restore_current_blog();
	}
	$json = json_decode( $output, true );
	return array(
		'json' => is_array( $json ) ? $json : array(),
		'raw'  => $output,
	);
};
$good = static function ( int $user_id ) use ( $site ): string {
	switch_to_blog( $site );
	wp_set_current_user( $user_id );
	$nonce = wp_create_nonce( ExportPanel::STATUS_ACTION );
	restore_current_blog();
	return $nonce;
};

$job( 'running', 'media', array( 'progress' => '{"done":256,"total":1024}' ) );
$out = $poll( $owner, array() );
$check( 'no nonce: refused, nothing about the job', array() !== $out['json'] && false === $out['json']['success'] && ! str_contains( $out['raw'], 'Packing' ) && ! isset( $out['json']['data']['status'] ), $out['raw'] );
$out = $poll( $owner, array( 'nonce' => 'nope' ) );
$check( 'a wrong nonce: refused', false === ( $out['json']['success'] ?? null ), $out['raw'] );
$out = $poll( $editor, array( 'nonce' => $good( $editor ) ) );
$check( 'an editor with a valid nonce: refused (no manage_options)', false === ( $out['json']['success'] ?? null ) && ! isset( $out['json']['data']['status'] ), $out['raw'] );
$out = $poll( 0, array( 'nonce' => $good( $owner ) ) );
$check( 'nobody logged in, an administrator\'s nonce: refused', false === ( $out['json']['success'] ?? null ), $out['raw'] );
$out = $poll( $owner, array( 'nonce' => $good( $owner ) ) );
$data = $out['json']['data'] ?? array();
$check( 'an administrator with the nonce: the state', true === ( $out['json']['success'] ?? null ) && 'running' === ( $data['status'] ?? '' ) && 'media' === ( $data['step'] ?? '' ) && 25 === ( $data['percent'] ?? null ), $out['raw'] );
$check( '... the steps, the sentence, no files yet', 3 === count( $data['steps'] ?? array() ) && 'current' === ( $data['steps'][1]['state'] ?? '' ) && 'done' === ( $data['steps'][0]['state'] ?? '' ) && str_contains( (string) ( $data['message'] ?? '' ), 'Packing your images' ) && array() === ( $data['files'] ?? null ) );
$check( '... and "25%" for the page', '25%' === ( $data['percent_label'] ?? '' ) );
if ( $stubbed ) {
	Runner::$watched = 0;
	$poll( $owner, array( 'nonce' => $good( $owner ) ) );
	$check( 'a poll calls the watchdog (Runner::watch)', 1 === Runner::$watched, (string) Runner::$watched );
} else {
	$skip( 'a poll calls the watchdog (Runner::watch)', 'the real Runner is loaded; its calls are not counted here' );
}
update_site_option( 'mody_export_swept', time() - 2 * HOUR_IN_SECONDS );
$before = time();
$poll( $owner, array( 'nonce' => $good( $owner ) ) );
$check( 'a poll runs the sweep (at most hourly)', (int) get_site_option( 'mody_export_swept', 0 ) >= $before, (string) get_site_option( 'mody_export_swept' ) );
$stamp = time() - 600;
update_site_option( 'mody_export_swept', $stamp );
$poll( $owner, array( 'nonce' => $good( $owner ) ) );
$check( '... and not again within the hour', $stamp === (int) get_site_option( 'mody_export_swept', 0 ) );
update_site_option( 'mody_export_swept', time() );

$job( 'done', 'done', array( 'files' => wp_json_encode( $files ), 'finished_at' => $finished ) );
$out  = $poll( $owner, array( 'nonce' => $good( $owner ) ) );
$data = $out['json']['data'] ?? array();
$check( 'done: the three files with name, size and link', 'done' === ( $data['status'] ?? '' ) && array( 'code', 'media', 'database' ) === array_column( $data['files'] ?? array(), 'key' ) && '2.0 GB' === ( $data['files'][1]['size_label'] ?? '' ) && 'exp-media-2026-11-12.zip' === ( $data['files'][1]['name'] ?? '' ), $out['raw'] );
$check( '... links from Downloads::url, a kept-until line', str_starts_with( (string) ( $data['files'][0]['url'] ?? '' ), strtok( Downloads::url( (array) Jobs::current( $site ), 'code' ), '?' ) ) && str_contains( (string) ( $data['kept_until'] ?? '' ), wp_date( get_option( 'date_format' ), $until ) ) );
$job( 'done', 'done', array( 'files' => wp_json_encode( array_replace_recursive( $files, array( 'database' => array( 'stripped' => array( 'x_secret' ) ) ) ) ), 'finished_at' => $finished ) );
$out  = $poll( $owner, array( 'nonce' => $good( $owner ) ) );
$check( 'done: the poll lists the settings left out', array( 'x_secret' ) === ( $out['json']['data']['stripped'] ?? null ), $out['raw'] );
$job( 'done', 'done', array( 'files' => wp_json_encode( $files ), 'finished_at' => gmdate( 'Y-m-d H:i:s', time() - Jobs::KEEP_FOR - HOUR_IN_SECONDS ) ) );
$out = $poll( $owner, array( 'nonce' => $good( $owner ) ) );
$check( 'done but past its three days: the poll says none, with no links', 'none' === ( $out['json']['data']['status'] ?? '' ) && array() === ( $out['json']['data']['files'] ?? null ) && array() === ( $out['json']['data']['stripped'] ?? null ), $out['raw'] );
$job( 'expired' );
$out = $poll( $owner, array( 'nonce' => $good( $owner ) ) );
$check( 'no job: status none, nothing else', 'none' === ( $out['json']['data']['status'] ?? '' ) && array() === ( $out['json']['data']['files'] ?? null ) );

// Opening the section keeps jobs moving too: a visit is enough, no open poll needed.
$job( 'queued' );
if ( $stubbed ) {
	Runner::$watched = 0;
	$panel( $owner );
	$check( 'opening the section calls the watchdog (Runner::watch)', 1 === Runner::$watched, (string) Runner::$watched );
	$panel( $editor );
	ob_start();
	switch_to_blog( $main );
	wp_set_current_user( $owner );
	ExportPanel::render();
	restore_current_blog();
	ob_end_clean();
	$check( '... not for an editor, nor on the main site (the panel does not render there)', 1 === Runner::$watched, (string) Runner::$watched );
	$page( $owner, 'overview' );
	$check( '... not for the Overview', 1 === Runner::$watched, (string) Runner::$watched );
} else {
	$skip( 'opening the section calls the watchdog (Runner::watch)', 'the real Runner is loaded; its calls are not counted here' );
}
update_site_option( 'mody_export_swept', time() - 2 * HOUR_IN_SECONDS );
$before = time();
$panel( $owner );
$check( 'opening the section runs the sweep (at most hourly)', (int) get_site_option( 'mody_export_swept', 0 ) >= $before, (string) get_site_option( 'mody_export_swept' ) );
$stamp = time() - 600;
update_site_option( 'mody_export_swept', $stamp );
$panel( $owner );
$check( '... and not again within the hour', $stamp === (int) get_site_option( 'mody_export_swept', 0 ) );
update_site_option( 'mody_export_swept', time() );
$job( 'expired' );

// 5. Prepare my download ---------------------------------------------------------------

echo "\nPrepare my download\n";
$mail  = array();
$to_of = static fn( array $m ): string => (string) ( is_array( $m['to'] ) ? $m['to'][0] : $m['to'] );
add_filter(
	'pre_wp_mail',
	static function ( $pre, array $atts ) use ( &$mail, $stubbed ) {
		$mail[] = $atts;
		if ( $stubbed ) {
			Runner::$log[] = 'mail'; // The order of the emails and the builder's start.
		}
		return true;
	},
	1,
	2
);
$post = static function ( int $user_id, int $blog, ?string $nonce_for = null, bool $with_nonce = true ) use ( $site ): array {
	switch_to_blog( $blog );
	wp_set_current_user( $user_id );
	$_REQUEST = $with_nonce ? array( '_wpnonce' => wp_create_nonce( null === $nonce_for ? ExportPanel::ACTION : $nonce_for ) ) : array();
	$_POST    = $_REQUEST;
	$stop     = static function ( $location ) {
		$stop         = new Mody_Export_Test_Stop( 'redirect' );
		$stop->output = (string) $location;
		throw $stop;
	};
	$die      = static fn() => static function ( $message = '', $title = '', $args = array() ): void {
		$stop         = new Mody_Export_Test_Stop( 'die' );
		$stop->output = ( is_wp_error( $message ) ? $message->get_error_message() : wp_strip_all_tags( (string) $message ) ) . ' [' . ( is_array( $args ) ? (int) ( $args['response'] ?? 0 ) : 0 ) . ']';
		throw $stop;
	};
	add_filter( 'wp_redirect', $stop, PHP_INT_MIN ); // Before WP-CLI's own handler, which warns.
	add_filter( 'wp_die_handler', $die, PHP_INT_MAX );
	$result = array( 'kind' => 'returned', 'output' => '' );
	try {
		ExportPanel::handle_request();
	} catch ( Mody_Export_Test_Stop $e ) {
		$result = array( 'kind' => $e->getMessage(), 'output' => $e->output );
	} finally {
		remove_filter( 'wp_redirect', $stop, PHP_INT_MIN );
		remove_filter( 'wp_die_handler', $die, PHP_INT_MAX );
		$_REQUEST = array();
		$_POST    = array();
		restore_current_blog();
	}
	return $result;
};
$count = static function () use ( $site ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Jobs::table() . ' WHERE site_id = %d', $site ) ); // phpcs:ignore WordPress.DB -- Test rows.
};

$job( 'expired' );
$res = $post( $owner, $site, null, false );
$check( 'no nonce: refused, no job', 'die' === $res['kind'] && 1 === $count(), $res['kind'] . ' ' . $res['output'] );
$res = $post( $owner, $site, 'some_other_action' );
$check( 'a nonce for another action: refused, no job', 'die' === $res['kind'] && 1 === $count(), $res['kind'] );
$res = $post( $editor, $site );
$check( 'an editor: refused with 403, no job', 'die' === $res['kind'] && str_contains( $res['output'], '[403]' ) && 1 === $count(), $res['output'] );
$res = $post( $owner, $main );
$check( 'an administrator on the main site: refused with 403', 'die' === $res['kind'] && str_contains( $res['output'], '[403]' ), $res['output'] );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Jobs::table() . ' WHERE site_id = %d', $main ) ); // phpcs:ignore WordPress.DB -- Test rows (none should exist).

if ( $stubbed ) {
	Runner::$started = array();
	Runner::$log     = array();
	Runner::$watched = 0;
	$mail            = array();
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Jobs::table() . ' WHERE site_id = %d', $site ) ); // phpcs:ignore WordPress.DB -- Test rows.
	$res = $post( $owner, $site );
	$row = (array) Jobs::current( $site );
	$jobs[] = (int) ( $row['id'] ?? 0 );
	$check( 'the owner: redirected to the section with export-result=started', 'redirect' === $res['kind'] && str_contains( $res['output'], 'section=export' ) && str_contains( $res['output'], 'export-result=started' ), $res['output'] );
	$check( '... a queued job for this site and this user', 'queued' === ( $row['status'] ?? '' ) && $owner === ( $row['user_id'] ?? 0 ) && $site === ( $row['site_id'] ?? 0 ) );
	$check( '... asked of the watchdog, never a builder started directly (the cap of two builds holds)', array() === Runner::$started && Runner::$watched >= 1, 'started ' . wp_json_encode( Runner::$started ) . ', watched ' . Runner::$watched );
	$tos = array_map( $to_of, $mail );
	sort( $tos );
	$check( '... both administrators emailed, once each', array( 'exown' . $suffix . '@mody.local', 'exsec' . $suffix . '@mody.local' ) === $tos, implode( ' ', $tos ) );
	$last_mail  = (int) array_key_last( array_filter( Runner::$log, static fn( string $entry ): bool => 'mail' === $entry ) );
	$last_watch = (int) array_key_last( array_filter( Runner::$log, static fn( string $entry ): bool => 'watch' === $entry ) );
	$check( '... the emails go before the builder is asked, so "ready" cannot overtake "being prepared"', $last_mail > 0 && $last_watch > $last_mail, implode( ',', Runner::$log ) );
	$check( '... the per-site lock is released afterwards', 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK( %s )', 'mody_export_panel_' . $site ) ) );

	$mail            = array();
	$watched         = Runner::$watched;
	update_site_option( 'mody_export_swept', time() - 2 * HOUR_IN_SECONDS );
	$before = time();
	$res    = $post( $second, $site );
	$check( 'pressing again while it runs: busy, no second job, no second email', str_contains( $res['output'], 'export-result=busy' ) && 1 === $count() && array() === $mail && array() === Runner::$started, $res['output'] );
	$check( '... the press itself still calls the watchdog (it may be stalled) and sweeps', Runner::$watched > $watched && (int) get_site_option( 'mody_export_swept', 0 ) >= $before, Runner::$watched . ' ' . $watched );
	update_site_option( 'mody_export_swept', time() );
	$mail = array();
	$check( 'start() on a live job says busy and announces nothing', 'busy' === ExportPanel::start( $site, $owner ) && array() === $mail && 1 === $count() );

	// Another request holds the site's lock (a double click, in the middle of creating the job): this one does not pile on.
	$other = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
	$other->query( "SELECT GET_LOCK( 'mody_export_panel_" . (int) $site . "', 0 )" );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Jobs::table() . ' WHERE site_id = %d', $site ) ); // phpcs:ignore WordPress.DB -- Test rows.
	$mail   = array();
	$result = ExportPanel::start( $site, $owner );
	$other->close();
	$check( 'while another request holds the lock: busy, no job, no email (after its 5 seconds)', 'busy' === $result && 0 === $count() && array() === $mail, $result );
	$check( '... and the lock is free again once that request ends', 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK( %s )', 'mody_export_panel_' . $site ) ) );
	$res = $post( $owner, $site );
	$row = (array) Jobs::current( $site );
	$jobs[] = (int) ( $row['id'] ?? 0 );
	$check( 'and then the next press starts normally', str_contains( $res['output'], 'export-result=started' ) && 1 === $count() && 2 === count( $mail ), $res['output'] );

	$wpdb->update( Jobs::table(), array( 'status' => 'done', 'step' => 'done', 'finished_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test rows.
	$res = $post( $second, $site );
	$new = (array) Jobs::current( $site );
	$jobs[] = (int) ( $new['id'] ?? 0 );
	$check( 'after one is done, a fresh copy: a new job for whoever asked', str_contains( $res['output'], 'export-result=started' ) && ( $new['id'] ?? 0 ) > $row['id'] && $second === ( $new['user_id'] ?? 0 ) && 2 === $count(), $res['output'] );
	$wpdb->update( Jobs::table(), array( 'status' => 'failed' ), array( 'id' => $new['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test rows.
	$res = $post( $owner, $site );
	$check( 'after a failure, Try again starts a new job', str_contains( $res['output'], 'export-result=started' ) && 3 === $count(), $res['output'] );

	// A super administrator who does not administer the site (the maintainer, helping): the job is the first real administrator's.
	grant_super_admin( $staff );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Jobs::table() . ' WHERE site_id = %d', $site ) ); // phpcs:ignore WordPress.DB -- Test rows.
	$mail = array();
	$res  = $post( $staff, $site );
	$row  = (array) Jobs::current( $site );
	$jobs[] = (int) ( $row['id'] ?? 0 );
	$check( 'a super administrator with no role on the site can press the button', str_contains( $res['output'], 'export-result=started' ) && 'queued' === ( $row['status'] ?? '' ), $res['output'] );
	$check( '... the job is recorded for the first administrator of the site, not for them', $owner === ( $row['user_id'] ?? 0 ) && $staff !== ( $row['user_id'] ?? 0 ), (string) ( $row['user_id'] ?? '' ) );
	$tos = array_map( $to_of, $mail );
	sort( $tos );
	$bodies = implode( "\n---\n", array_column( $mail, 'message' ) );
	$check( '... both administrators are told, and not the super administrator', array( 'exown' . $suffix . '@mody.local', 'exsec' . $suffix . '@mody.local' ) === $tos );
	$check( '... "Mody Cloud (name) asked", the first administrator named as the one who will hear, never "You asked"', 2 === substr_count( $bodies, 'Mody Cloud (Pat Platform) asked to download the whole of Export test' ) && 2 === substr_count( $bodies, 'the first administrator of the site will get another email' ) && ! str_contains( $bodies, 'You asked' ) && ! str_contains( $bodies, 'another administrator of' ), $bodies );
	// A super administrator who is also an administrator of the site keeps the job.
	grant_super_admin( $second );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Jobs::table() . ' WHERE site_id = %d', $site ) ); // phpcs:ignore WordPress.DB -- Test rows.
	$mail = array();
	$post( $second, $site );
	$row  = (array) Jobs::current( $site );
	$jobs[] = (int) ( $row['id'] ?? 0 );
	$check( 'a super administrator who administers the site: the job is theirs, as for anyone', $second === ( $row['user_id'] ?? 0 ) && 2 === count( $mail ) && str_contains( implode( ' ', array_column( $mail, 'message' ) ), 'You asked' ), (string) ( $row['user_id'] ?? '' ) );
	revoke_super_admin( $second );
	revoke_super_admin( $staff );
	$check( 'an ordinary editor is still refused; so is someone with no role who is not a super administrator', 'die' === $post( $editor, $site )['kind'] && 'die' === $post( $none, $site )['kind'] );
} else {
	$skip( 'the owner presses the button: job, builder, emails', 'the real Runner is loaded and would start a real build' );
}
remove_all_filters( 'pre_wp_mail', 1 );

// 6. The emails ------------------------------------------------------------------------

echo "\nEmails\n";
$done_job = $job( 'done', 'done', array( 'files' => wp_json_encode( $files ), 'finished_at' => gmdate( 'Y-m-d H:i:s' ), 'user_id' => $second ) );
$mail     = array();
add_filter(
	'pre_wp_mail',
	static function ( $pre, array $atts ) use ( &$mail ) {
		$mail[] = $atts;
		return true;
	},
	1,
	2
);
$sent = Notices::started( $done_job['id'] );
$by_to = array();
foreach ( $mail as $m ) {
	$by_to[ $to_of( $m ) ] = $m;
}
$first  = $by_to[ 'exown' . $suffix . '@mody.local' ] ?? array();
$second_mail = $by_to[ 'exsec' . $suffix . '@mody.local' ] ?? array();
$check( 'started: every administrator of the site, and nobody else', 2 === $sent && 2 === count( $mail ) && $first && $second_mail && ! isset( $by_to[ 'exed' . $suffix . '@mody.local' ] ), (string) $sent );
$check( '... the subject names the site', str_contains( (string) ( $first['subject'] ?? '' ), 'Export test' ) && str_contains( (string) ( $first['subject'] ?? '' ), 'being prepared for download' ), (string) ( $first['subject'] ?? '' ) );
$check( '... the other administrator learns who asked', str_contains( (string) ( $first['message'] ?? '' ), 'Sam Second' ) && str_contains( (string) ( $first['message'] ?? '' ), 'another administrator' ), '' );
$check( '... the one who asked is told "You asked"', str_contains( (string) ( $second_mail['message'] ?? '' ), 'You asked to download the whole of Export test' ) && ! str_contains( (string) ( $second_mail['message'] ?? '' ), 'another administrator' ) );
$check( '... plain text, greeted by name, kind, short', ! str_contains( (string) ( $first['message'] ?? '' ), '<' ) && str_starts_with( (string) ( $first['message'] ?? '' ), 'Hello Olivia Owner' ) && strlen( (string) ( $first['message'] ?? '' ) ) < 700 );
$check( '... the mail carries no file address', ! str_contains( wp_json_encode( $mail ), '/mody-export' ) && ! str_contains( wp_json_encode( $mail ), 'stub-download' ) );
$check( 'started: a job that does not exist sends nothing', 0 === Notices::started( 999999999 ) );

$mail = array();
$ok   = Notices::ready( $done_job['id'] );
$check( 'ready: one email, to the person who asked', true === $ok && 1 === count( $mail ) && 'exsec' . $suffix . '@mody.local' === $to_of( $mail[0] ), (string) count( $mail ) );
$body = (string) ( $mail[0]['message'] ?? '' );
$check( '... "Your download of <site> is ready"', str_contains( (string) ( $mail[0]['subject'] ?? '' ), 'Export test' ) && str_contains( (string) ( $mail[0]['subject'] ?? '' ), 'is ready' ), (string) ( $mail[0]['subject'] ?? '' ) );
$check( '... links to the dashboard section on that site', str_contains( $body, 'page=mody-dashboard' ) && str_contains( $body, 'section=export' ) && str_contains( $body, 'exp' . $suffix ) && 1 === preg_match_all( '#https?://\S+#', $body ), $body );
$check( '... never to a file: no /mody-export/, no download address, no file name', ! str_contains( $body, '/mody-export' ) && ! str_contains( $body, 'stub-download' ) && ! str_contains( $body, '.zip' ) && ! str_contains( $body, '.sql' ) );
$check( '... says how long the files stay (3 days)', str_contains( $body, '3 days' ), '' );
$mail = array();
switch_to_blog( $site );
Notices::ready( $done_job['id'] );
restore_current_blog();
$check( 'ready: the same from inside the site (the builder\'s context)', 1 === count( $mail ) && str_contains( (string) ( $mail[0]['message'] ?? '' ), 'section=export' ) );
$check( 'the current site is back after the emails', get_current_blog_id() === $entry_blog, (string) get_current_blog_id() );
// Each recipient reads their email in their own language (their profile's), not the asker's or the site's.
// No language pack is installed here, so Spanish is made "available" to the switcher and the text is marked instead.
$switcher  = $GLOBALS['wp_locale_switcher'];
$available = new ReflectionProperty( $switcher, 'available_languages' );
$was       = $available->getValue( $switcher );
$available->setValue( $switcher, array_merge( (array) $was, array( 'es_ES' ) ) );
$locale_before = get_locale();
$marker        = static fn( $text, $unused, $domain ) => 'mody_cloud' === $domain && 'es_ES' === get_locale() ? '[es]' . $text : $text;
$marker_n      = static fn( $text, $one, $many, $number, $domain ) => 'mody_cloud' === $domain && 'es_ES' === get_locale() ? '[es]' . $text : $text;
add_filter( 'gettext', $marker, 10, 3 );
add_filter( 'ngettext', $marker_n, 10, 5 );
update_user_meta( $owner, 'locale', 'es_ES' ); // Olivia reads Spanish; Sam (who asks, in $done_job) reads the site's.
$mail = array();
Notices::started( $done_job['id'] );
$by_to = array();
foreach ( $mail as $m ) {
	$by_to[ $to_of( $m ) ] = $m;
}
$olivia = $by_to[ 'exown' . $suffix . '@mody.local' ] ?? array();
$sam    = $by_to[ 'exsec' . $suffix . '@mody.local' ] ?? array();
$check( 'started: the administrator whose profile says Spanish gets it in Spanish, subject and body', str_starts_with( (string) ( $olivia['subject'] ?? '' ), '[es]' ) && str_starts_with( (string) ( $olivia['message'] ?? '' ), '[es]Hello Olivia Owner' ), (string) ( $olivia['subject'] ?? '' ) );
$check( '... the other administrator, with no language of their own, does not', ! str_contains( (string) ( $sam['subject'] ?? '' ), '[es]' ) && ! str_contains( (string) ( $sam['message'] ?? '' ), '[es]' ) && str_starts_with( (string) ( $sam['message'] ?? '' ), 'Hello Sam Second' ) );
$check( '... the language is back to what it was when the emails are done', get_locale() === $locale_before, get_locale() );
update_user_meta( $owner, 'locale', '' );
update_user_meta( $second, 'locale', 'es_ES' ); // Sam asked and reads Spanish: the ready email goes to Sam.
$mail = array();
Notices::started( $done_job['id'] );
$by_to = array();
foreach ( $mail as $m ) {
	$by_to[ $to_of( $m ) ] = $m;
}
$check( 'started: the asker\'s language is not forced on the others, and is used for the asker', ! str_contains( (string) ( $by_to[ 'exown' . $suffix . '@mody.local' ]['message'] ?? '' ), '[es]' ) && str_starts_with( (string) ( $by_to[ 'exsec' . $suffix . '@mody.local' ]['message'] ?? '' ), '[es]Hello Sam Second' ) );
$mail = array();
$ok   = Notices::ready( $done_job['id'] );
$check( 'ready: in the language of the person it goes to (the builder runs in the site\'s)', $ok && str_starts_with( (string) ( $mail[0]['subject'] ?? '' ), '[es]' ) && str_starts_with( (string) ( $mail[0]['message'] ?? '' ), '[es]Hello Sam Second' ) && str_contains( (string) ( $mail[0]['message'] ?? '' ), 'section=export' ), (string) ( $mail[0]['subject'] ?? '' ) );
$check( '... and the language is back afterwards', get_locale() === $locale_before );
update_user_meta( $second, 'locale', '' );
$mail = array();
Notices::ready( $done_job['id'] );
$check( 'ready: with no language in the profile, the site\'s', ! str_contains( (string) ( $mail[0]['message'] ?? '' ), '[es]' ) && str_starts_with( (string) ( $mail[0]['message'] ?? '' ), 'Hello Sam Second' ) );
remove_filter( 'gettext', $marker, 10 );
remove_filter( 'ngettext', $marker_n, 10 );
$available->setValue( $switcher, $was );
delete_user_meta( $owner, 'locale' );
delete_user_meta( $second, 'locale' );

// A mail that blows up must not undo a finished build: the builder calls ready() after the files are done.
$boom = static function (): void {
	throw new RuntimeException( 'mail server down' );
};
add_filter( 'pre_wp_mail', $boom, 0 );
$notified = null;
$thrown   = false;
try {
	switch_to_blog( $site );
	$notified = @Notices::ready( $done_job['id'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The notice is the point; the test only wants the return value.
	restore_current_blog();
} catch ( \Throwable $e ) {
	$thrown = true;
	restore_current_blog();
}
remove_filter( 'pre_wp_mail', $boom, 0 );
$check( 'ready: an email that cannot be sent returns false and throws nothing', false === $notified && ! $thrown && get_current_blog_id() === $entry_blog );

$wpdb->update( Jobs::table(), array( 'user_id' => 999999999 ), array( 'id' => $done_job['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test rows.
$mail = array();
$check( 'ready: a user who is gone gets nothing, and nothing breaks', false === Notices::ready( $done_job['id'] ) && array() === $mail );
remove_all_filters( 'pre_wp_mail', 1 );

// 7. The script and the strings --------------------------------------------------------

echo "\nScript and strings\n";
$root = WPMU_PLUGIN_DIR . '/mody-platform';
$js   = (string) file_get_contents( $root . '/assets/export-panel.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test.
$job( 'running', 'database', array( 'progress' => '{"done":1,"total":2}' ) );
$html = $panel( $owner );
preg_match_all( '/data-role="([a-z]+)"/', $js, $roles );
$missing = array_filter( array_unique( $roles[1] ), static fn( string $role ): bool => ! str_contains( $html, 'data-role="' . $role . '"' ) );
$check( 'the script finds every element it updates in the rendered panel', array() === $missing && count( array_unique( $roles[1] ) ) >= 4, implode( ',', $missing ) );
$check( 'the script asks the poll\'s action, reads the page\'s data, and has no text of its own', str_contains( $js, 'root.dataset.action' ) && str_contains( $js, 'root.dataset.nonce' ) && ! preg_match( '/textContent = [\'"]/', $js ) );
switch_to_blog( $site );
wp_set_current_user( $owner );
$_GET['section'] = 'overview';
ExportPanel::enqueue();
$on_overview = wp_script_is( 'mody-export-panel', 'enqueued' );
$_GET['section'] = 'export';
ExportPanel::enqueue();
$on_export = wp_script_is( 'mody-export-panel', 'enqueued' );
wp_dequeue_script( 'mody-export-panel' );
unset( $_GET['section'] );
restore_current_blog();
$check( '... not on Overview, on the export section', ! $on_overview && $on_export );
foreach ( array( 'src/Dashboard/ExportPanel.php', 'src/Export/Notices.php' ) as $file ) {
	$source = (string) file_get_contents( $root . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test.
	$calls  = preg_match_all( '/\b(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e|_n|_x)\(/', $source );
	$domain = preg_match_all( "/'mody_cloud'/", $source );
	$check( $file . ': every translated string has the mody_cloud domain', $calls > 0 && $calls === $domain, $calls . ' calls, ' . $domain . ' domains' );
}

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
