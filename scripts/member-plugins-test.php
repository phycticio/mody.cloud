<?php
/**
 * Tests plugins on member sites (ticket M4-02, part A1): members use core's
 * Plugins screen, which never shows them the hidden plugins.
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/member-plugins-test.php
 *
 * No plugin is visible to members yet, so the test writes a one-file fixture
 * plugin, app/web/plugins/mody-test-plugin/, and removes it at its start and
 * end (bin/deploy never ships it). A test member is made administrator of a
 * member site (the first site that is not the main one) and of the main site;
 * a test editor joins the member site.
 *
 * 1. The rule: what is hidden (the platform plugins, network-active ones),
 *    and what the member, an editor and a super admin may do.
 * 2. REST (as the member, internal requests): the list leaves hidden
 *    plugins out; their routes answer "not found"; the fixture switches on
 *    and off; install and delete refused. Another plugin's route that
 *    checks only `activate_plugins` (Spectra's onboarding API) switches the
 *    fixture but never mcp-adapter: hidden plugins keep their state.
 * 3. Over HTTP on the member site: the screen and its menu list the
 *    fixture and no hidden plugin, with no Add Plugin or Must-Use; the
 *    row's Activate link and the bulk Deactivate work; a crafted bulk
 *    request, single link or deactivation for a hidden plugin is refused;
 *    an editor is refused.
 * 4. Unchanged: the main site's screen refuses the member; a super admin
 *    sees the platform plugins.
 *
 * Everything is removed at the end: the fixture (switched off first), the
 * users. No model call.
 */

use Mody\Platform\Gating\MemberPlugins;

require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-56s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

global $wpdb;
$main         = get_main_site_id();
$member_sites = get_sites(
	array(
		'site__not_in' => array( $main ),
		'fields'       => 'ids',
		'number'       => 1,
	)
);
if ( ! $member_sites ) {
	echo "No member site on this network: nothing to test.\n";
	return;
}
$site = (int) $member_sites[0];

// The fixture --------------------------------------------------------------------

$fixture     = 'mody-test-plugin/mody-test-plugin.php';
$fixture_dir = WP_PLUGIN_DIR . '/mody-test-plugin';
// Read from the database: the HTTP requests change it outside this process.
$active_on = static function ( int $site_id ) use ( $wpdb ): array {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test; fixed option.
	return (array) maybe_unserialize( (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->get_blog_prefix( $site_id )}options WHERE option_name = 'active_plugins'" ) );
};
$remove_fixture = static function () use ( $fixture, $fixture_dir, $active_on ): void {
	wp_cache_flush(); // Other processes changed the options.
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $site_id ) {
		if ( in_array( $fixture, $active_on( (int) $site_id ), true ) ) {
			switch_to_blog( (int) $site_id );
			deactivate_plugins( $fixture, true );
			restore_current_blog();
		}
	}
	if ( is_file( $fixture_dir . '/mody-test-plugin.php' ) ) {
		wp_delete_file( $fixture_dir . '/mody-test-plugin.php' );
	}
	if ( is_dir( $fixture_dir ) ) {
		rmdir( $fixture_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture.
	}
	wp_clean_plugins_cache( false );
};
$remove_fixture(); // A run killed earlier may have left it.
wp_mkdir_p( $fixture_dir );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
file_put_contents( $fixture_dir . '/mody-test-plugin.php', "<?php\n/**\n * Plugin Name: Mody test plugin\n * Description: Created and removed by scripts/member-plugins-test.php. It does nothing.\n * Version: 1.0.0\n */\n" );
wp_clean_plugins_cache( false );

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
$member = $new( 'modyplugins' );
$editor = $new( 'modypluginsed' );
add_user_to_blog( $site, $member, 'administrator' );
add_user_to_blog( $main, $member, 'administrator' );
add_user_to_blog( $site, $editor, 'editor' );
$admins = get_super_admins();
$super  = get_user_by( 'login', $admins[0] )->ID;
register_shutdown_function(
	static function () use ( $remove_fixture, $member, $editor ): void {
		$remove_fixture();
		wpmu_delete_user( $member );
		wpmu_delete_user( $editor );
	}
);

$installed = array_keys( get_plugins() );
$hidden    = array_values( array_filter( $installed, array( MemberPlugins::class, 'is_hidden' ) ) );
$visible   = array_values( array_diff( $installed, $hidden ) );
$platform  = 'mcp-adapter/mcp-adapter.php';                // Installed everywhere, not network-active.
$network   = 'ai/ai.php';                                  // Network-active everywhere (Spectra is a MU plugin since M3-11).

// 1. The rule ----------------------------------------------------------------------

echo "The rule\n";
$check( 'the fixture is installed and visible', in_array( $fixture, $visible, true ), implode( ', ', $visible ) );
$platform_files = array_filter( $installed, static fn( $file ): bool => in_array( MemberPlugins::slug( $file ), MemberPlugins::PLATFORM_PLUGINS, true ) );
$check( 'every installed platform plugin is hidden', ! array_diff( $platform_files, $hidden ), implode( ', ', array_map( array( MemberPlugins::class, 'slug' ), $hidden ) ) );
$check( 'network-active plugins are hidden', ! array_diff( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ), $hidden ) );
$check( 'mcp-adapter is installed and not network-active', in_array( $platform, $installed, true ) && ! is_plugin_active_for_network( $platform ) );
$check( 'Spectra is a MU plugin: never on a Plugins screen (M3-11)', Mody\Platform\Spectra\SpectraLoader::is_must_use() && ! is_plugin_active_for_network( 'spectra-blocks/spectra-blocks.php' ) );
$can = static fn( int $user_id, int $site_id, string $cap, string $file = '' ): bool => '' === $file ? user_can_for_site( $user_id, $site_id, $cap ) : user_can_for_site( $user_id, $site_id, $cap, $file );
$check( 'member: the Plugins screen and bulk actions', $can( $member, $site, 'activate_plugins' ) && $can( $member, $site, 'deactivate_plugins' ) );
$check( 'member: switch the fixture on and off', $can( $member, $site, 'activate_plugin', $fixture ) && $can( $member, $site, 'deactivate_plugin', $fixture ) );
$check( 'member: not a platform plugin', ! $can( $member, $site, 'activate_plugin', $platform ) && ! $can( $member, $site, 'deactivate_plugin', $platform ) );
$check( 'member: not a network-active plugin', ! $can( $member, $site, 'deactivate_plugin', $network ) );
$check( 'member: no plugin named at all', ! $can( $member, $site, 'activate_plugin' ) );
$still = array_filter( array( 'install_plugins', 'upload_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins' ), static fn( $cap ): bool => $can( $member, $site, $cap ) );
$check( 'member: still no install, upload, update, delete, edit', ! $still, implode( ', ', $still ) );
$check( 'editor: no Plugins screen', ! $can( $editor, $site, 'activate_plugins' ) && ! $can( $editor, $site, 'activate_plugin', $fixture ) );
$check( 'main site: unchanged, no Plugins screen for the member', ! $can( $member, $main, 'activate_plugins' ) && ! $can( $member, $main, 'activate_plugin', $fixture ) );
$check( 'super admin: every plugin, on the member site too', $can( $super, $site, 'activate_plugin', $platform ) && $can( $super, $site, 'install_plugins' ) );

// 2. REST --------------------------------------------------------------------------

echo "\nREST (member, member site)\n";
switch_to_blog( $site );
wp_set_current_user( $member );
$rest = static function ( string $method, string $route, array $params = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_ensure_response( rest_do_request( $request ) );
};
$route  = static fn( string $file ): string => '/wp/v2/plugins/' . preg_replace( '/\.php$/', '', $file );
$out    = $rest( 'GET', '/wp/v2/plugins' );
$listed = array_map( static fn( $item ): string => $item['plugin'] . '.php', (array) $out->get_data() );
sort( $listed );
$check( 'the list: exactly the visible plugins', 200 === $out->get_status() && $listed === $visible, implode( ', ', $listed ) );
$out = $rest( 'GET', $route( $platform ) );
$check( 'a platform plugin: not found', 404 === $out->get_status(), (string) $out->get_status() );
$out = $rest( 'POST', $route( $platform ), array( 'status' => 'active' ) );
$check( '... and not activated', 404 === $out->get_status() && ! in_array( $platform, $active_on( $site ), true ), (string) $out->get_status() );
$out = $rest( 'GET', $route( $network ) );
$check( 'a network-active plugin: not found', 404 === $out->get_status(), (string) $out->get_status() );
$out = $rest( 'POST', $route( $fixture ), array( 'status' => 'active' ) );
$check( 'the fixture: switched on', 200 === $out->get_status() && in_array( $fixture, $active_on( $site ), true ), (string) $out->get_status() );
$out = $rest( 'POST', $route( $fixture ), array( 'status' => 'inactive' ) );
$check( '... and off', 200 === $out->get_status() && ! in_array( $fixture, $active_on( $site ), true ), (string) $out->get_status() );
$out = $rest( 'POST', '/wp/v2/plugins', array( 'slug' => 'akismet' ) );
$check( 'install from wordpress.org: refused', 403 === $out->get_status(), (string) $out->get_status() );
$out = $rest( 'DELETE', $route( $fixture ) );
$check( 'delete the fixture: refused', 403 === $out->get_status() && is_file( WP_PLUGIN_DIR . '/' . $fixture ), (string) $out->get_status() );
$out = $rest( 'POST', '/one-onboarding/v1/activate-plugin', array( 'plugin' => 'mcp-adapter' ) );
$check( 'Spectra\'s onboarding API: mcp-adapter stays off', ! in_array( $platform, $active_on( $site ), true ), $out->get_status() . ' ' . wp_json_encode( $out->get_data() ) );
$out = $rest( 'POST', '/one-onboarding/v1/activate-plugin', array( 'plugin' => 'mody-test-plugin' ) );
$check( '... while it still switches the fixture on', in_array( $fixture, $active_on( $site ), true ), (string) $out->get_status() );
deactivate_plugins( $fixture );
$check( 'hidden plugins keep their state, both ways', array( $fixture ) === MemberPlugins::keep_hidden( array( $fixture, $platform ), array() ) && array( $platform ) === MemberPlugins::keep_hidden( array(), array( $fixture, $platform ) ) );
wp_set_current_user( 0 );
$check( '... except without a logged-in user (WP-CLI, cron)', array( $fixture, $platform ) === MemberPlugins::keep_hidden( array( $fixture, $platform ), array() ) );
wp_set_current_user( $super );
$listed = array_map( static fn( $item ): string => $item['plugin'] . '.php', (array) $rest( 'GET', '/wp/v2/plugins' )->get_data() );
$check( 'super admin: the list shows platform plugins', in_array( $platform, $listed, true ) );
wp_set_current_user( 0 );
restore_current_blog();

// 3. Over HTTP ----------------------------------------------------------------------

echo "\nOver HTTP (member site)\n";
$host   = get_site( $site )->domain;
$cookie = static function ( int $user_id ): array {
	$expiration = time() + 300;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	register_shutdown_function( static fn() => WP_Session_Tokens::get_instance( $user_id )->destroy( $token ) );
	return array(
		SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token ),
		LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ),
	);
};
// This container reaches only the main host; the Host header picks the site.
$http = static function ( string $site_host, string $path, array $cookies, ?array $body = null ): array {
	$args     = array(
		'cookies'     => $cookies,
		'headers'     => array( 'Host' => $site_host ),
		'sslverify'   => false,
		'redirection' => 0,
		'timeout'     => 30,
	);
	$url      = 'https://' . get_site( get_main_site_id() )->domain . $path;
	$response = null === $body ? wp_remote_get( $url, $args ) : wp_remote_post( $url, $args + array( 'body' => $body ) );
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $response ),
		'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		'body'     => (string) wp_remote_retrieve_body( $response ),
	);
};
$as_member = $cookie( $member );
$screen    = (string) wp_parse_url( get_admin_url( $site, 'plugins.php' ), PHP_URL_PATH );
$rows      = static function ( string $body ): array {
	preg_match_all( '/<tr class="[^"]*" data-slug="[^"]*" data-plugin="([^"]+)"/', $body, $match );
	sort( $match[1] );
	return $match[1];
};

$out = $http( $host, $screen, $as_member );
$check( 'the Plugins screen opens, in the menu', 200 === $out['code'] && str_contains( $out['body'], 'id="menu-plugins"' ), (string) $out['code'] );
$check( '... its rows: exactly the visible plugins', $rows( $out['body'] ) === $visible, implode( ', ', $rows( $out['body'] ) ) );
$check( '... no Add Plugin, no Must-Use or Drop-ins', ! preg_match( '/plugin-install\.php|plugin_status=mustuse|plugin_status=dropins/', $out['body'] ) );
preg_match( '/href="(plugins\.php\?action=activate&amp;plugin=mody-test-plugin%2Fmody-test-plugin\.php[^"]*)"/', $out['body'], $link );
preg_match( '/<form [^>]*id="bulk-action-form".*?name="_wpnonce" value="([0-9a-f]+)"/s', $out['body'], $bulk );
$check( '... the fixture\'s Activate link and the bulk form', isset( $link[1], $bulk[1] ) );
$out = $http( $host, dirname( $screen ) . '/' . html_entity_decode( $link[1] ?? '' ), $as_member );
$check( 'Activate (the row\'s link): on', 302 === $out['code'] && in_array( $fixture, $active_on( $site ), true ), $out['code'] . ' ' . $out['location'] );
$bulk_post = static fn( string $action, array $files ): array => $http(
	$host,
	$screen,
	$as_member,
	array(
		'action'        => $action,
		'checked'       => $files,
		'_wpnonce'      => $bulk[1] ?? '',
		'plugin_status' => 'all',
	)
);
$out = $bulk_post( 'deactivate-selected', array( $fixture ) );
$check( 'bulk Deactivate: off', 302 === $out['code'] && ! in_array( $fixture, $active_on( $site ), true ), (string) $out['code'] );
$off = ! in_array( $fixture, $active_on( $site ), true );
$out = $bulk_post( 'activate-selected', array( $fixture, $platform ) );
$check( 'crafted bulk Activate with mcp-adapter: only the fixture', $off && 302 === $out['code'] && in_array( $fixture, $active_on( $site ), true ) && ! in_array( $platform, $active_on( $site ), true ), (string) $out['code'] );
// A valid nonce for the crafted links, so only the capability check can stop them.
$_COOKIE[ LOGGED_IN_COOKIE ] = $as_member[ LOGGED_IN_COOKIE ];
wp_set_current_user( $member );
$crafted = static fn( string $action, string $file ): array => $http( $host, $screen . '?action=' . $action . '&plugin=' . rawurlencode( $file ) . '&_wpnonce=' . wp_create_nonce( $action . '-plugin_' . $file ), $as_member );
$out     = $crafted( 'activate', $platform );
$check( 'crafted Activate for mcp-adapter: refused', str_contains( $out['body'], 'Sorry, you are not allowed to activate this plugin.' ) && ! in_array( $platform, $active_on( $site ), true ), (string) $out['code'] );
$out = $crafted( 'deactivate', $network );
$check( 'crafted Deactivate for the ai plugin (network-active): refused', str_contains( $out['body'], 'Sorry, you are not allowed to deactivate this plugin.' ) && is_plugin_active_for_network( $network ), (string) $out['code'] );
wp_set_current_user( 0 );
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
$out = $http( $host, $screen, $cookie( $editor ) );
$check( 'an editor: the screen refuses', in_array( $out['code'], array( 403, 500 ), true ) && str_contains( $out['body'], 'Sorry, you are not allowed to' ), (string) $out['code'] );

// 4. Unchanged ----------------------------------------------------------------------

echo "\nUnchanged\n";
$main_screen = (string) wp_parse_url( get_admin_url( $main, 'plugins.php' ), PHP_URL_PATH );
$out         = $http( get_site( $main )->domain, $main_screen, $as_member );
$check( 'main site: the screen refuses the member', in_array( $out['code'], array( 403, 500 ), true ) && str_contains( $out['body'], 'Sorry, you are not allowed to' ), (string) $out['code'] );
$out = $http( $host, $screen, $cookie( $super ) );
$check( 'member site: a super admin sees mcp-adapter', 200 === $out['code'] && in_array( $platform, $rows( $out['body'] ), true ), implode( ', ', $rows( $out['body'] ) ) );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
