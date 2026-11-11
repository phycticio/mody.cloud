<?php
/**
 * Tests plugins on member sites (ticket M4-02, part A1): which plugins are
 * hidden from members, and, since 2026-11-11, that nobody has a Plugins or
 * Connectors screen (tickets/hide-plugins-and-connectors.md).
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
 * 2. REST: the plugin and block directory routes refused to a member and a
 *    super admin; Spectra's onboarding API (it checks only
 *    `activate_plugins`) never switches a hidden plugin. The settings route
 *    shows no connector key and ignores writes to one; members may not
 *    write the `ai` plugin's options there, super admins may.
 * 3. Over HTTP: the Plugins, Add Plugin and Connectors screens, the network
 *    Plugins screen and the ai plugin's connector approvals refused; no
 *    menu or toolbar entry for them, for a member or a super admin.
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

echo "\nREST (member site)\n";
switch_to_blog( $site );
$rest = static function ( string $method, string $route, array $params = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_ensure_response( rest_do_request( $request ) );
};
$route = static fn( string $file ): string => '/wp/v2/plugins/' . preg_replace( '/\.php$/', '', $file );
// Nobody has the plugin routes since 2026-11-11 (tickets/hide-plugins-and-connectors.md).
foreach ( array( 'member' => $member, 'super admin' => $super ) as $who => $user_id ) {
	wp_set_current_user( $user_id );
	$codes = array(
		$rest( 'GET', '/wp/v2/plugins' )->get_status(),
		$rest( 'POST', $route( $fixture ), array( 'status' => 'active' ) )->get_status(),
		$rest( 'POST', '/wp/v2/plugins', array( 'slug' => 'akismet' ) )->get_status(),
		$rest( 'GET', '/wp/v2/block-directory/search', array( 'term' => 'x' ) )->get_status(),
	);
	$check( "$who: list, switch, install, block directory refused", array( 403, 403, 403, 403 ) === $codes && ! in_array( $fixture, $active_on( $site ), true ), implode( ' ', $codes ) );
}
// Found by the review (2026-11-11): the REST server matches routes regardless of case.
foreach ( array( 'member' => $member, 'super admin' => $super ) as $who => $user_id ) {
	wp_set_current_user( $user_id );
	$codes = array(
		$rest( 'GET', '/wp/v2/Plugins' )->get_status(),
		$rest( 'GET', '/WP/V2/PLUGINS/' )->get_status(),
		$rest( 'GET', '/wp/v2/Block-Directory/search', array( 'term' => 'x' ) )->get_status(),
		$rest( 'GET', '/one-onboarding/v1/plugin-status' )->get_status(),
	);
	$check( "$who: other casings and Spectra's plugin routes refused too", array( 403, 403, 403, 403 ) === $codes, implode( ' ', $codes ) );
}
wp_set_current_user( $member );
$out = $rest( 'POST', '/wp/v2/Settings', array( 'wpai_feature_summarization_enabled' => '1' ) );
$check( 'member: the ai plugin\'s options refused at /wp/v2/Settings too', 403 === $out->get_status(), (string) $out->get_status() );
$out = $rest( 'POST', '/one-onboarding/v1/activate-plugin', array( 'plugin' => 'mcp-adapter' ) );
$check( 'Spectra\'s onboarding API: mcp-adapter stays off', ! in_array( $platform, $active_on( $site ), true ), $out->get_status() . ' ' . wp_json_encode( $out->get_data() ) );
deactivate_plugins( $fixture );
$check( 'hidden plugins keep their state, both ways', array( $fixture ) === MemberPlugins::keep_hidden( array( $fixture, $platform ), array() ) && array( $platform ) === MemberPlugins::keep_hidden( array(), array( $fixture, $platform ) ) );
wp_set_current_user( 0 );
$check( '... except without a logged-in user (WP-CLI, cron)', array( $fixture, $platform ) === MemberPlugins::keep_hidden( array( $fixture, $platform ), array() ) );

// The settings route: no connector keys for anyone, no `ai` plugin options for members.
wp_set_current_user( $super );
$settings = (array) $rest( 'GET', '/wp/v2/settings' )->get_data();
$keys     = array_keys( $settings );
$check( 'settings: no connector key, for a super admin either', ! preg_grep( '/^connectors_/', $keys ) && isset( $settings['title'] ), implode( ', ', preg_grep( '/^connectors_/', $keys ) ) );
$before = get_option( 'connectors_ai_infomaniak_api_key', '' );
$out    = $rest( 'POST', '/wp/v2/settings', array( 'connectors_ai_infomaniak_api_key' => 'mody-test-key' ) );
$check( '... and a write leaves it alone', get_option( 'connectors_ai_infomaniak_api_key', '' ) === $before, (string) $out->get_status() );
$wpai = (string) array_values( preg_grep( '/^wpai_/', $keys ) )[0];
$out  = $rest( 'POST', '/wp/v2/settings', array( $wpai => $settings[ $wpai ] ) );
$check( '... a super admin may still save the ai plugin\'s options', 200 === $out->get_status(), $wpai . ' ' . $out->get_status() );
wp_set_current_user( $member );
$out = $rest( 'POST', '/wp/v2/settings', array( $wpai => $settings[ $wpai ] ) );
$check( '... a member may not', 403 === $out->get_status(), (string) $out->get_status() );
$out = $rest( 'POST', '/wp/v2/settings', array( 'title' => get_option( 'blogname' ) ) );
$check( '... while the member saves other settings', 200 === $out->get_status(), (string) $out->get_status() );
wp_set_current_user( 0 );
restore_current_blog();

// 3. Over HTTP ----------------------------------------------------------------------

echo "\nOver HTTP\n";
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
$http = static function ( string $site_host, string $path, array $cookies ): array {
	$response = wp_remote_get(
		'https://' . get_site( get_main_site_id() )->domain . $path,
		array(
			'cookies'     => $cookies,
			'headers'     => array( 'Host' => $site_host ),
			'sslverify'   => false,
			'redirection' => 0,
			'timeout'     => 30,
		)
	);
	return array(
		'code' => (int) wp_remote_retrieve_response_code( $response ),
		'body' => (string) wp_remote_retrieve_body( $response ),
	);
};
$path      = static fn( int $site_id, string $screen ): string => (string) wp_parse_url( get_admin_url( $site_id, $screen ), PHP_URL_PATH );
$main_host = get_site( $main )->domain;
$as_member = $cookie( $member );
$as_super  = $cookie( $super );
foreach ( array( 'plugins.php', 'plugin-install.php', 'options-connectors.php' ) as $screen ) {
	$codes = array( $http( $host, $path( $site, $screen ), $as_member )['code'], $http( $host, $path( $site, $screen ), $as_super )['code'] );
	$check( "$screen: refused to the member and a super admin", array( 403, 403 ) === $codes, implode( ' ', $codes ) );
}
// Core's plugin actions on admin-ajax.php (found by the review): the updates nonce is on the Import screen.
$ajax = static function ( string $host, int $site_id, array $cookies, string $action ) use ( $http, $path ): array {
	$page = $http( $host, $path( $site_id, 'import.php' ), $cookies );
	preg_match( '/"ajax_nonce":"([0-9a-f]+)"/', $page['body'], $nonce );
	$response = wp_remote_post(
		'https://' . get_site( get_main_site_id() )->domain . $path( $site_id, 'admin-ajax.php' ),
		array(
			'cookies'   => $cookies,
			'headers'   => array( 'Host' => $host ),
			'sslverify' => false,
			'body'      => array( 'action' => $action, '_ajax_nonce' => $nonce[1] ?? '', 's' => '', 'plugin' => 'mcp-adapter/mcp-adapter.php', 'slug' => 'mcp-adapter', 'name' => 'x' ),
		)
	);
	return array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) );
};
foreach ( array( 'search-plugins', 'activate-plugin', 'search-install-plugins' ) as $action ) {
	$codes = array( $ajax( $host, $site, $as_member, $action )[0], $ajax( $main_host, $main, $as_super, $action )[0] );
	$check( "admin-ajax $action: refused to the member and a super admin", array( 403, 403 ) === $codes, implode( ' ', $codes ) );
}
$out = $http( $main_host, (string) wp_parse_url( network_admin_url( 'plugins.php' ), PHP_URL_PATH ), $as_super );
$check( 'the network Plugins screen: refused to a super admin', 403 === $out['code'], (string) $out['code'] );
$out = $http( $main_host, $path( $main, 'admin.php' ) . '?page=ai-connector-approval', $as_super );
$check( 'the ai plugin\'s connector approvals: refused', 403 === $out['code'], (string) $out['code'] );
// Visible entries only: the ai plugin's scripts also carry the Connectors URL as data, used only without a provider.
$menu = static fn( string $body ): bool => (bool) preg_match( '/id="menu-plugins"|href="[^"]*plugins\.php"|href="[^"]*options-connectors\.php"|href="[^"]*page=ai-connector-approval|id="wp-admin-bar-network-admin-p"/', $body );
$out  = $http( $host, $path( $site, 'admin.php' ) . '?page=mody-dashboard', $as_member ); // The Mody dashboard replaced core's on 2026-11-11.
$check( 'member dashboard: no Plugins or Connectors in the menus', 200 === $out['code'] && ! $menu( $out['body'] ), (string) $out['code'] );
$out = $http( $main_host, $path( $main, 'admin.php' ) . '?page=mody-dashboard', $as_super );
$check( 'super admin, main site: none either', 200 === $out['code'] && ! $menu( $out['body'] ), (string) $out['code'] );
$out = $http( $main_host, (string) wp_parse_url( network_admin_url( 'index.php' ), PHP_URL_PATH ), $as_super );
$check( 'super admin, network admin: none either', 200 === $out['code'] && ! $menu( $out['body'] ), (string) $out['code'] );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
