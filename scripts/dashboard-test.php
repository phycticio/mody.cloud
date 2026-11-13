<?php
/**
 * Tests the Mody dashboard (tickets/mody-dashboard.md, 2026-11-11): every
 * site's Dashboard, the space per site (1 GB since 2026-11-13), Mody
 * Analytics, Mody's work, the plans (per site since 2026-11-13) and Plan &
 * billing.
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/dashboard-test.php
 *
 * 1. Storage: the network's quota, a new site's 1 GB, older sites moved from
 *    3 GB to 1 GB (a size set by hand stays), no limit on the main site, an
 *    upload over the quota refused.
 * 2. Mody Analytics: paths, sources, devices, the address behind the
 *    proxies, bots, visits (a new one after 30 minutes), journeys, entry
 *    and exit pages, only random visit IDs stored, the cap, several sites
 *    side by side, the archive after 13 months.
 * 3. Mody's work: the groups, and a site's summary from turn rows.
 * 4. The plans: the old plans' migration (to free and paid, free_until, no
 *    next_plan), choose_period() and its refusals.
 * 5. The page: the sections each user sees, and every section rendered.
 * 6. Over HTTP: wp-admin/ opens the dashboard, its menu item, the beacon
 *    on the front end and its route.
 *
 * A throwaway site and three users are created and removed. No model call.
 */

use Mody\Analytics\Collector;
use Mody\Analytics\Stats;
use Mody\Analytics\Store;
use Mody\Platform\Agent\AgentEvents;
use Mody\Platform\Agent\TurnMetrics;
use Mody\Platform\Dashboard\DashboardPage;
use Mody\Platform\Dashboard\Usage;
use Mody\Platform\Members\Members;
use Mody\Platform\Site\Storage;

require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-62s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

global $wpdb;
// Plan & billing without card payments (billing-test.php covers them): the key off for this run, back after.
$mody_key = getenv( 'MOLLIE_API_KEY' );
putenv( 'MOLLIE_API_KEY' );
register_shutdown_function( static fn() => false === $mody_key ? null : putenv( 'MOLLIE_API_KEY=' . $mody_key ) );
$suffix = strtolower( wp_generate_password( 6, false ) );
$new    = static function ( string $login ) use ( $suffix ): int {
	return (int) wp_insert_user(
		array(
			'user_login' => $login . $suffix,
			'user_email' => $login . $suffix . '@mody.local',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => '',
		)
	);
};
$member = $new( 'modydash' );
$editor = $new( 'modydashed' );
$other  = $new( 'modydashot' );
$site   = (int) wpmu_create_blog( 'dash' . $suffix . '.' . preg_replace( '/^www\./', '', get_network()->domain ), '/', 'Dashboard test', $member );
add_user_to_blog( $site, $editor, 'editor' );
switch_to_blog( $site );
switch_theme( 'theme' ); // As provisioning does: without it the front end is blank.
restore_current_blog();
register_shutdown_function(
	static function () use ( $member, $editor, $other, $site ): void {
		if ( $site ) {
			wpmu_delete_blog( $site, true );
		}
		foreach ( array( $member, $editor, $other ) as $user_id ) {
			Members::delete( $user_id );
			wpmu_delete_user( $user_id );
		}
	}
);
$check( 'a throwaway site and users', $site > 0 && $member && $editor && $other, (string) $site );

// 1. Storage --------------------------------------------------------------------------

echo "\nStorage\n";
$check( 'the network: 1 GB, the check on, 1 GB files', 1024 === (int) get_site_option( 'blog_upload_space' ) && 0 === (int) get_site_option( 'upload_space_check_disabled' ) && 1048576 === (int) get_site_option( 'fileupload_maxk' ) );
$check( 'a new site gets its own 1024 MB', '1024' === (string) get_blog_option( $site, 'blog_upload_space' ) );
// Version 2 of the settings: a site still on the old 3 GB gets 1 GB; a size set by hand stays.
$hand = (int) get_blog_option( get_main_site_id(), 'blog_upload_space' );
update_blog_option( $site, 'blog_upload_space', 3072 );
update_blog_option( get_main_site_id(), 'blog_upload_space', 500 );
update_site_option( 'mody_storage_version', '1' );
update_site_option( 'blog_upload_space', 3072 );
Storage::install();
$check( 'older sites: 3 GB becomes 1 GB, a size set by hand stays', '1024' === (string) get_blog_option( $site, 'blog_upload_space' ) && 500 === (int) get_blog_option( get_main_site_id(), 'blog_upload_space' ) && 1024 === (int) get_site_option( 'blog_upload_space' ) && '2' === get_site_option( 'mody_storage_version' ) );
$hand ? update_blog_option( get_main_site_id(), 'blog_upload_space', $hand ) : delete_blog_option( get_main_site_id(), 'blog_upload_space' );
$check( 'the main site has no limit', Storage::usage()['limited'] === false && get_space_allowed() > 1000000 );
switch_to_blog( $site );
$usage = Storage::usage();
$check( 'a member site has 1 GB, limited', $usage['limited'] && GB_IN_BYTES === $usage['allowed'], size_format( $usage['allowed'] ) );
$upload = static function ( int $bytes ): array {
	$tmp = (string) wp_tempnam( 'mody-quota' );
	file_put_contents( $tmp, str_repeat( 'x', $bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test file.
	add_filter( 'wp_doing_ajax', '__return_true' ); // check_upload_size() stops the request otherwise.
	$file = check_upload_size(
		array(
			'name'     => 'file.jpg',
			'size'     => $bytes,
			'tmp_name' => $tmp,
			'error'    => 0,
		)
	);
	remove_filter( 'wp_doing_ajax', '__return_true' );
	wp_delete_file( $tmp );
	return $file;
};
update_option( 'blog_upload_space', 1 );
$file = $upload( 2 * MB_IN_BYTES );
$check( 'an upload over the quota is refused', ! empty( $file['error'] ), (string) ( $file['error'] ?? '' ) );
update_option( 'blog_upload_space', Storage::SITE_MB );
$file = $upload( 5 * MB_IN_BYTES );
$check( 'a 5 MB file within the quota passes (no 1.5 MB cap)', empty( $file['error'] ), (string) ( $file['error'] ?? '' ) );
restore_current_blog();

// 2. Mody Analytics -------------------------------------------------------------------

echo "\nMody Analytics\n";
$check( 'paths: no query, decoded, refused when not a path', '/about/' === Collector::path( '/about/?utm=x#top' ) && '/café/' === Collector::path( '/caf%C3%A9/' ) && '' === Collector::path( 'https://evil.example/' ) && '' === Collector::path( "/a\x01" ) );
switch_to_blog( $site );
$own = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$check( 'sources: the host, no www., none for the site itself', 'example.org' === Collector::source( 'https://www.example.org/q?s=1' ) && '' === Collector::source( 'https://' . $own . '/x/' ) && '' === Collector::source( '' ) );
$check( 'devices: by width', array( 'mobile', 'tablet', 'desktop', 'unknown' ) === array( Collector::device( 390 ), Collector::device( 820 ), Collector::device( 1440 ), Collector::device( 0 ) ) );
$check( 'posts from another site\'s pages are not counted', Collector::from_this_site( '' ) && Collector::from_this_site( 'https://' . $own ) && ! Collector::from_this_site( 'https://evil.example' ) );

$server = $_SERVER;
$_SERVER['REMOTE_ADDR']           = '172.18.0.5';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.7';
$_SERVER['HTTP_X_FORWARDED_FOR']  = '104.16.0.5, 172.18.0.1'; // The peer is Cloudflare: its header is believed.
$behind                           = Collector::address();
$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.1, 172.18.0.1'; // The peer is not Cloudflare: its header is forged (security pass, 2026-11-12).
$forged                           = Collector::address();
unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
$forwarded                        = Collector::address();
$_SERVER['REMOTE_ADDR']           = '192.0.2.44';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.7';
$direct                           = Collector::address();
$_SERVER                          = $server;
$check( 'the address behind the proxies, never a forged header', '203.0.113.7' === $behind && '198.51.100.1' === $forged && '198.51.100.1' === $forwarded && '192.0.2.44' === $direct, "$behind $forged $forwarded $direct" );

$ua  = 'Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/131.0';
$ua2 = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Safari/604.1';
$counted = array(
	Collector::count( '/', 'https://www.example.org/', 1440, '203.0.113.7', $ua ),
	Collector::count( '/about/', 'https://' . $own . '/', 1440, '203.0.113.7', $ua ),
	Collector::count( '/', '', 390, '203.0.113.8', $ua2 ),
	Collector::count( '/', '', 1440, '203.0.113.9', 'Mozilla/5.0 (compatible; Googlebot/2.1)' ),
	Collector::count( 'nope', '', 1440, '203.0.113.9', $ua ),
);
$check( 'people count; bots and non-paths do not', array( true, true, true, false, false ) === $counted, wp_json_encode( $counted ) );
$stats = Stats::summary( $site, 7 );
$check( 'the summary: 3 views, 2 visits today', 3 === $stats['views'] && 2 === $stats['visits'] && 7 === count( $stats['days'] ), $stats['views'] . ' / ' . $stats['visits'] );
$check( '... pages, sources (where visits came in) and devices', array( '/' => 2, '/about/' => 1 ) === array_column( $stats['pages'], 'count', 'value' ) && array( '' => 1, 'example.org' => 1 ) === array_column( $stats['sources'], 'count', 'value' ) && 2 === count( $stats['devices'] ), wp_json_encode( array( $stats['sources'], $stats['devices'] ) ) );
$check( '... entry and exit pages, single-page visits', array( '/' => 2 ) === array_column( $stats['entries'], 'count', 'value' ) && array( '/' => 1, '/about/' => 1 ) === array_column( $stats['exits'], 'count', 'value' ) && 1 === $stats['single'], wp_json_encode( array( $stats['entries'], $stats['exits'], $stats['single'] ) ) );
$check( '... the journey: / then /about/', 1 === count( $stats['journeys'] ) && array( '/', '/about/' ) === $stats['journeys'][0]['steps'] && ! $stats['journeys'][0]['more'], wp_json_encode( $stats['journeys'] ) );

// A visit ends after 30 minutes without a page; all three views are today, whatever the time now.
$start = ( new DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp() + 60;
Collector::count( '/a/', '', 1440, '203.0.113.30', $ua, $start );
Collector::count( '/b/', '', 1440, '203.0.113.30', $ua, $start + Collector::VISIT_GAP + 60 );
Collector::count( '/c/', '', 1440, '203.0.113.30', $ua, $start + Collector::VISIT_GAP + 120 );
$stats = Stats::summary( $site, 1 );
$check( 'a new visit after 30 minutes without a page', 6 === $stats['views'] && 4 === $stats['visits'] && in_array( array( '/b/', '/c/' ), array_column( $stats['journeys'], 'steps' ), true ), $stats['views'] . ' / ' . $stats['visits'] );
for ( $i = 0; $i < 7; $i++ ) {
	Collector::count( '/p' . $i . '/', '', 1440, '203.0.113.31', $ua );
}
$long = array_values( array_filter( Stats::summary( $site, 1 )['journeys'], static fn( array $j ): bool => '/p0/' === $j['steps'][0] ) );
$check( '... a long journey shows its first pages and "more"', 1 === count( $long ) && Stats::JOURNEY_STEPS === count( $long[0]['steps'] ) && $long[0]['more'] );

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test.
$hashes = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT hash FROM ' . Store::seen() . ' WHERE site_id = %d', $site ) );
$rows   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT GROUP_CONCAT(CONCAT_WS('|', visit_id, seq, day, hour, path, source, device) SEPARATOR ';') FROM " . Store::views() . ' WHERE site_id = %d', $site ) );
$ids    = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT visit_id FROM ' . Store::views() . ' WHERE site_id = %d', $site ) );
// phpcs:enable
$check( 'the map keeps hashes only, no address or user agent', 4 === count( $hashes ) && ! array_filter( $hashes, static fn( string $h ): bool => ! preg_match( '/^[0-9a-f]{32}$/', $h ) ) );
$check( 'views keep random visits, never the hash, an address or a browser', ! array_intersect( $ids, $hashes ) && ! array_intersect( $ids, array_map( static fn( string $h ): string => substr( $h, 0, 16 ), $hashes ) ) && ! str_contains( $rows, '203.0.113' ) && ! str_contains( $rows, 'Mozilla' ) && ! str_contains( $rows, 'Firefox' ) );
$views = Stats::summary( $site, 1 )['views'];
for ( $i = 0; $i < Collector::MAX_VIEWS; $i++ ) {
	Collector::count( '/', '', 1440, '203.0.113.20', $ua );
}
$check( 'a visitor past the day\'s cap is not counted', ! Collector::count( '/', '', 1440, '203.0.113.20', $ua ) && $views + Collector::MAX_VIEWS === Stats::summary( $site, 1 )['views'] );

// Your sites: several sites side by side, all of them together.
$both = Stats::sites( array( $site, get_main_site_id() ), 7 );
$mine = Stats::summary( $site, 7 );
$check( 'several sites: each one\'s numbers and top page, and the sum', $mine['visits'] === $both['sites'][ $site ]['visits'] && '/' === $both['sites'][ $site ]['top'] && $both['visits'] === $mine['visits'] + $both['sites'][ get_main_site_id() ]['visits'] && 7 === count( $both['days'] ) );

// Views older than the retention become daily totals, and go.
$old = gmdate( 'Y-m-d', (int) strtotime( '-14 months' ) );
foreach ( array( '/', '/old/' ) as $n => $old_path ) {
	Store::add_view(
		array(
			'site_id'  => $site,
			'visit_id' => 'oldvisit00000000',
			'seq'      => $n + 1,
			'day'      => $old,
			'hour'     => 10,
			'path'     => $old_path,
			'source'   => 'example.net',
			'device'   => 'desktop',
		)
	);
}
Store::archive_before( Store::retention_start() );
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test.
$left    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Store::views() . ' WHERE site_id = %d AND day = %s', $site, $old ) );
$totals  = $wpdb->get_results( $wpdb->prepare( 'SELECT kind, value, views, visitors FROM ' . Store::daily() . ' WHERE site_id = %d AND day = %s ORDER BY kind, value', $site, $old ), ARRAY_A );
// phpcs:enable
$archive = array_map( static fn( array $r ): string => implode( ':', $r ), (array) $totals );
$check( 'after 13 months: daily totals kept, the views gone', 0 === $left && array( 'country::1:1', 'device:desktop:1:1', 'page:/:1:1', 'page:/old/:1:1', 'source:example.net:1:1', 'total::2:1' ) === $archive, implode( ', ', $archive ) );
restore_current_blog();
$salts = get_site_option( 'mody_analytics_salts' );
$check( 'salts: at most two days kept', is_array( $salts ) && count( $salts ) <= 2 && count( $salts ) >= 1, implode( ', ', array_keys( (array) $salts ) ) );

// 3. Mody's work ----------------------------------------------------------------------

echo "\nMody's work\n";
$check( 'groups: design wins, then configuration, then conversation', 'design' === Usage::group( array( 'update-settings', 'edit_content' ) ) && 'configuration' === Usage::group( array( 'update-menu' ) ) && 'conversation' === Usage::group( array() ) && 'conversation' === Usage::group( array( 'ask_user' ) ) );
switch_to_blog( $site );
wp_set_current_user( $member );
$page = (int) wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_title'  => 'Pricing',
		'post_status' => 'draft',
	)
);
$turn = static function ( array $tools, int $prompt, int $completion, ?int $open = null ): void {
	$data = array(
		'prompt_tokens'     => $prompt,
		'completion_tokens' => $completion,
		'tools'             => $tools,
		'outcome'           => 'acted',
	);
	if ( $open ) {
		$data['open'] = array(
			'type' => 'page',
			'id'   => $open,
		);
	}
	AgentEvents::log( 'test', TurnMetrics::TOOL, null, (string) wp_json_encode( $data ), microtime( true ) );
};
$turn( array( 'edit_content' ), 10000, 2000, $page );
$turn( array( 'generate-page' ), 20000, 8000 );
$turn( array( 'update-settings' ), 5000, 100 );
$turn( array(), 4000, 200, $page );
$usage = Usage::summary( $site, 30 );
$check( 'the summary: 4 messages, 49,300 tokens', 4 === $usage['messages'] && 49300 === $usage['tokens'], $usage['messages'] . ' / ' . $usage['tokens'] );
$check( '... design 40,000, configuration 5,100, conversation 4,200', 40000 === $usage['groups']['design']['tokens'] && 5100 === $usage['groups']['configuration']['tokens'] && 4200 === $usage['groups']['conversation']['tokens'] );
$check( '... the open page: 2 messages, 16,200 tokens, by its title', 1 === count( $usage['open'] ) && 2 === $usage['open'][0]['messages'] && 16200 === $usage['open'][0]['tokens'] && 'Pricing' === Usage::title( 'page', $usage['open'][0]['id'] ) );
$check( 'another site\'s rows are not counted', 0 === Usage::summary( get_main_site_id() + 999999, 30 )['messages'] );
restore_current_blog();

// 4. The plans ------------------------------------------------------------------------

echo "\nThe plans\n";
$check( 'the 2026-11-13 plans: free (one site) and paid (7.99 or 79.99 per site)', array( 'free', 'paid' ) === Members::PLANS && 1 === Members::SITE_LIMITS['free'] && 7.99 === Members::PRICE['monthly'] && 79.99 === Members::PRICE['yearly'] );
$check( 'labels: Launch offer, Per site', 'Launch offer' === Members::plan_label( 'free' ) && 'Per site' === Members::plan_label( 'paid' ) );

// The migration from the 2026-11-10 plans, on the real table: version 3's shape again, four rows, then install().
$table = Members::table();
$old   = "'trial','collaborator','member','patron','sites_1','sites_3','sites_10','free','paid'";
$ids   = array( $member, $editor, $other );
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test.
$wpdb->query( "ALTER TABLE {$table} MODIFY plan enum({$old}) NOT NULL DEFAULT 'trial', CHANGE free_until trial_ends_at datetime DEFAULT NULL, ADD next_plan enum('sites_1','sites_3','sites_10') DEFAULT NULL" );
$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (user_id, plan, next_plan, status, trial_ends_at, created_at) VALUES (%d, 'trial', 'sites_3', 'active', '2026-12-01 10:00:00', '2026-11-01 10:00:00'), (%d, 'sites_10', NULL, 'active', NULL, '2026-11-01 10:00:00'), (%d, 'sites_1', NULL, 'active', NULL, '2026-11-01 10:00:00')", ...$ids ) );
update_site_option( 'mody_members_db_version', '3' );
Members::install();
$migrated = $wpdb->get_results( $wpdb->prepare( "SELECT plan, free_until, billing_due_at FROM {$table} WHERE user_id IN (%d, %d, %d) ORDER BY FIELD(user_id, %d, %d, %d)", ...$ids, ...$ids ), ARRAY_A );
$type     = (string) $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'plan'", 1 );
$columns  = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
// phpcs:enable
foreach ( $ids as $user_id ) {
	Members::delete( $user_id );
}
$check(
	'old plans become new: the trial free for six months from signup, the paid plans paid',
	array(
		array( 'free', '2027-05-01 10:00:00', '2027-05-01 10:00:00' ),
		array( 'paid', null, null ),
		array( 'paid', null, null ),
	) === array_map( 'array_values', (array) $migrated ) && "enum('free','paid')" === $type && ! in_array( 'next_plan', $columns, true ) && ! in_array( 'trial_ends_at', $columns, true ) && '6' === get_site_option( 'mody_members_db_version' ),
	wp_json_encode( $migrated )
);

Members::create( $member, 'paid', 'active' );
$results = array(
	Members::choose_period( $other, 'monthly' ),
	Members::choose_period( $member, 'weekly' ),
);
$codes = array_map( static fn( $r ): string => is_wp_error( $r ) ? $r->get_error_code() : 'chosen', $results );
$check( 'choose_period() refuses a non-member and an unknown period', array( 'mody_member_none', 'mody_member_period' ) === $codes, implode( ', ', $codes ) );
$second = (int) wpmu_create_blog( 'dash2' . $suffix . '.' . preg_replace( '/^www\./', '', get_network()->domain ), '/', 'Second', $member );
$chosen = Members::choose_period( $member, 'yearly' );
$row    = Members::get( $member );
$check( 'records the period', true === $chosen && 'yearly' === $row['billing_period'] && 'paid' === $row['plan'] );
// The other user: a launch offer account, its free months set by hand (start_free() counts a place).
Members::create( $other, 'free', 'active' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test.
$wpdb->update( $table, array( 'free_until' => gmdate( 'Y-m-d H:i:s', (int) strtotime( '+6 months' ) ), 'billing_due_at' => gmdate( 'Y-m-d H:i:s', (int) strtotime( '+6 months' ) ) ), array( 'user_id' => $other ) );
Members::get( $other );

// 5. The page -------------------------------------------------------------------------

echo "\nThe page\n";
switch_to_blog( $site );
add_filter( 'mody_export_spawn', '__return_true' ); // The export section asks the watchdog on every visit: no real builder from a test.
$render = static function ( int $user_id, string $section ): string {
	wp_set_current_user( $user_id );
	$_GET['section'] = $section;
	ob_start();
	DashboardPage::render();
	unset( $_GET['section'] );
	return (string) ob_get_clean();
};
wp_set_current_user( $member );
$check( 'the member: Overview, Visitors, Mody\'s work, Download your site, Your sites, Plan & billing', array( 'overview', 'visitors', 'mody', 'export', 'sites', 'plan' ) === array_keys( DashboardPage::sections() ) );
wp_set_current_user( $editor );
$check( 'an editor: Overview only', array( 'overview' ) === array_keys( DashboardPage::sections() ) );
$html = $render( $member, 'overview' );
$check( 'Overview: storage of 1 GB, pages, the brand', str_contains( $html, 'of 1 GB used' ) && str_contains( $html, 'Your brand' ) && str_contains( $html, 'Dashboard test' ) );
$html = $render( $member, 'visitors' );
$check( 'Visitors: the tiles, the chart, the lists', str_contains( $html, 'Page views' ) && str_contains( $html, 'mody-dashboard__bar' ) && str_contains( $html, 'example.org' ) && str_contains( $html, 'Show as a table' ) );
$check( '... the journeys, entry and exit pages', str_contains( $html, 'Journeys' ) && str_contains( $html, 'mody-dashboard__step-arrow' ) && str_contains( $html, 'Entry pages' ) && str_contains( $html, 'Exit pages' ) && str_contains( $html, 'Single-page visits' ) );
$html = $render( $member, 'mody' );
$check( 'Mody\'s work: design and configuration tokens, the page', str_contains( $html, 'Tokens on design' ) && str_contains( $html, '40,000' ) && str_contains( $html, 'Pricing' ) );
$check( '... the month against the site\'s allowance', str_contains( $html, 'This month:' ) && str_contains( $html, 'of the site&#039;s 5.0 M tokens' ), (string) ( preg_match( '/This month:[^<]*/', $html, $m ) ? $m[0] : '' ) );
$html = $render( $member, 'sites' );
$check( 'Your sites: both sites, 2 of 10 used, New site', str_contains( $html, 'Dashboard test' ) && str_contains( $html, 'Second' ) && str_contains( $html, '2 of 10 sites used' ) && str_contains( $html, 'mody-new-site' ) );
$check( '... visitors across them: the sum, the chart, the sites compared', str_contains( $html, 'Visitors across your sites' ) && str_contains( $html, 'Visits per day, all your sites' ) && str_contains( $html, 'mody-dashboard__compare' ) && str_contains( $html, 'section=visitors' ) && preg_match( '/\d+ visits · \d+ page views in 30 days/', $html ) );
$html = $render( $member, 'plan' );
$check( 'Plan & billing, paying: sites times the price, the choice, no card yet', str_contains( $html, '2 sites × 79.99 EUR a year: 159.98 EUR, VAT included' ) && str_contains( $html, 'Your choice' ) && str_contains( $html, 'None yet.' ) && str_contains( $html, 'you save 15.89 EUR per site' ) && str_contains( $html, '7.99 EUR' ) );
$check( '... every site the same: 1 GB and 5,000,000 tokens a month', str_contains( $html, '1 GB of space' ) && str_contains( $html, '5,000,000 tokens a month' ) );
add_user_to_blog( $site, $other, 'administrator' );
$html = $render( $other, 'plan' );
$check( 'Plan & billing, launch offer: free until, then the price, read-only without a card', str_contains( $html, 'Launch offer: one site, free until' ) && str_contains( $html, 'days left' ) && str_contains( $html, 'After the free months' ) && str_contains( $html, '1 site × 7.99 EUR a month: 7.99 EUR, VAT included' ) && str_contains( $html, 'once you add a card' ) && str_contains( $html, 'read-only for 60 days' ) );
remove_user_from_blog( $other, $site );
$html = $render( $member, 'export' );
$check( 'Download your site: the button, what comes along, what stays (tests in export-panel-test.php)', str_contains( $html, 'Prepare my download' ) && str_contains( $html, 'What stays with Mody Cloud' ) );
$marked = array_map( static fn( string $section ): bool => str_contains( $render( $member, $section ), 'mody-dashboard__wip' ), array_keys( DashboardPage::sections() ) );
$check( '"Work in progress" over Mody\'s work only (off Visitors since 2026-11-13)', array( false, false, true, false, false, false ) === $marked, wp_json_encode( $marked ) );
$html = $render( $editor, 'plan' );
$check( 'an editor asking for Plan & billing gets the Overview', ! str_contains( $html, 'How you pay' ) && str_contains( $html, 'Storage' ) );
remove_filter( 'mody_export_spawn', '__return_true' );
restore_current_blog();
wpmu_delete_blog( $second, true );

// 6. Over HTTP ------------------------------------------------------------------------

echo "\nOver HTTP\n";
$cookie = static function ( int $user_id ): array {
	$expiration = time() + 300;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	register_shutdown_function( static fn() => WP_Session_Tokens::get_instance( $user_id )->destroy( $token ) );
	return array(
		SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token ),
		LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ),
	);
};
$get = static function ( string $url, array $cookies = array(), string $method = 'GET', string $body = '' ): array {
	// PHP in the container cannot resolve subsites (architecture.md, WP-Cron on subsites): ask the main host, with the subsite's Host.
	$url      = set_url_scheme( $url, 'https' ); // WP-CLI is not on HTTPS, so its URLs are http://, which nginx moves.
	$host     = (string) wp_parse_url( $url, PHP_URL_HOST );
	$main     = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
	$response = wp_remote_request(
		(string) preg_replace( '#^(https?://)' . preg_quote( $host, '#' ) . '#', '$1' . $main, $url ),
		array(
			'method'      => $method,
			'body'        => $body,
			'headers'     => array(
				'Host'       => $host,
				'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/131.0',
			),
			'cookies'     => $cookies,
			'sslverify'   => false,
			'redirection' => 0,
			'timeout'     => 30,
		)
	);
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $response ),
		'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		'body'     => (string) wp_remote_retrieve_body( $response ),
	);
};
switch_to_blog( $site );
$admin = admin_url( '/' );
$dash  = DashboardPage::url();
$home  = home_url( '/' );
$route = rest_url( Collector::NAMESPACE . Collector::ROUTE );
restore_current_blog();
$out = $get( $admin, $cookie( $member ) );
$check( 'wp-admin/ opens the Mody dashboard', 302 === $out['code'] && str_contains( $out['location'], 'page=mody-dashboard' ), $out['code'] . ' ' . $out['location'] );
$out = $get( $dash, $cookie( $member ) );
$check( '... with the Dashboard item, its icon, and no core dashboard link', 200 === $out['code'] && str_contains( $out['body'], 'toplevel_page_mody-dashboard' ) && str_contains( $out['body'], 'dashicons-dashboard' ) && ! preg_match( '/id="menu-dashboard"/', $out['body'] ), (string) $out['code'] );
$out = $get( $home );
$check( 'the front end carries the beacon for visitors', 200 === $out['code'] && str_contains( $out['body'], 'id="mody-analytics"' ) );
$out = $get( $home, $cookie( $member ) );
$check( '... and for logged-in users, counted anonymously like anyone', 200 === $out['code'] && str_contains( $out['body'], 'id="mody-analytics"' ) );
$out = $get( $route, array(), 'POST', (string) wp_json_encode( array( 'p' => '/http/', 'r' => '', 'w' => 1440 ) ) );
$check( 'the route answers 204 and counts', 204 === $out['code'] && in_array( '/http/', array_column( Stats::summary( $site, 1 )['pages'], 'value' ), true ), (string) $out['code'] );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
