<?php
/**
 * Tests the `ai` plugin on member sites (ticket M4-03).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/ai-plugin-lockdown-test.php
 *
 * 1. Every feature the plugin has is either curated or deliberately off; a
 *    feature this test does not know fails it (a plugin update needs a decision).
 * 2. On a member site the switches and the connector approvals are pinned,
 *    even after a save; the main site is untouched.
 * 3. Over HTTP on the member site (the main host with its Host header), as a
 *    member: the plugin's screens and its configuration and MCP routes are
 *    refused and hidden; a curated feature is there, an excluded one is not;
 *    a super admin is not refused.
 * 4. A new site gets the plugin's request log table.
 *
 * The test user and site are removed, and the saved option restored. No model call.
 */

use Mody\Platform\Gating\AiPlugin;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-48s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

if ( ! class_exists( \WordPress\AI\Features\Image_Generation\Image_Generation::class ) ) {
	echo "The ai plugin is not active here: nothing to test.\n";
	return;
}
global $wpdb;
$admins = get_super_admins();
$admin  = get_user_by( 'login', $admins[0] )->ID;
$main   = get_main_site_id();
$others = get_sites(
	array(
		'site__not_in' => array( $main ),
		'fields'       => 'ids',
		'number'       => 1,
	)
);
if ( ! $others ) {
	echo "No member site: nothing to test.\n";
	return;
}
$site   = (int) $others[0];
$suffix = strtolower( wp_generate_password( 6, false ) );
$member = wp_insert_user(
	array(
		'user_login' => 'modyai' . $suffix,
		'user_email' => 'modyai' . $suffix . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	)
);
add_user_to_blog( $site, $member, 'administrator' );
register_shutdown_function( static fn() => wpmu_delete_user( $member ) );

// The features this test knows to be off on member sites, on purpose.
$off = array( 'image-generation', 'text-to-speech', 'mcp-adapter', 'webmcp', 'abilities-explorer', 'custom-abilities', 'markdown-feeds', 'c2pa-monitor' );

// 1. Every feature classified ---------------------------------------------------------

echo "Features\n";
$ids = array_keys(
	apply_filters(
		'wpai_default_feature_classes',
		array( \WordPress\AI\Features\Image_Generation\Image_Generation::get_id() => \WordPress\AI\Features\Image_Generation\Image_Generation::class )
	)
);
$unknown = array_diff( $ids, AiPlugin::FEATURES, $off );
$check( 'every plugin feature is curated or off', ! $unknown, $unknown ? 'decide: ' . implode( ', ', $unknown ) : count( $ids ) . ' features: ' . count( AiPlugin::FEATURES ) . ' on, ' . count( $off ) . ' off' );
$check( '...and no curated feature is missing from the plugin', ! array_diff( AiPlugin::FEATURES, $ids ), implode( ', ', array_diff( AiPlugin::FEATURES, $ids ) ) );

// 2. Pinned on the member site ------------------------------------------------------------

echo "\nPinned (member site $site)\n";
switch_to_blog( $site );
$wrong = array();
foreach ( $ids as $id ) {
	if ( get_option( "wpai_feature_{$id}_enabled" ) !== ( in_array( $id, AiPlugin::FEATURES, true ) ? '1' : '0' ) ) {
		$wrong[] = $id;
	}
}
$check( 'the switches', ! $wrong, $wrong ? implode( ', ', $wrong ) : '' );
$check( '...a feature added later is off', '0' === get_option( 'wpai_feature_added-later_enabled' ) );
$store = new \WordPress\AI\Connector_Approval\Approvals_Store();
$check( 'the approvals: the plugin may use Infomaniak, nothing else', AiPlugin::APPROVALS === get_option( 'wpai_connector_approvals' ) && $store->is_approved( 'ai/ai.php', 'infomaniak' ) && ! $store->is_approved( 'mcp-adapter/mcp-adapter.php', 'infomaniak' ) );
$table = $wpdb->options;
$saved = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s", 'wpai_feature_type-ahead_enabled' ) ); // phpcs:ignore WordPress.DB
update_option( 'wpai_feature_type-ahead_enabled', '0' );
$check( 'a saved change does not take', '1' === get_option( 'wpai_feature_type-ahead_enabled' ) );
null === $saved ? delete_option( 'wpai_feature_type-ahead_enabled' ) : $wpdb->update( $table, array( 'option_value' => $saved ), array( 'option_name' => 'wpai_feature_type-ahead_enabled' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_cache_delete( 'wpai_feature_type-ahead_enabled', 'options' );
wp_cache_delete( 'alloptions', 'options' );
restore_current_blog();

remove_filter( 'pre_option', array( AiPlugin::class, 'pin_option' ), 10 );
$plain = array_map( static fn( $id ) => get_option( "wpai_feature_{$id}_enabled" ), $ids );
add_filter( 'pre_option', array( AiPlugin::class, 'pin_option' ), 10, 2 );
$check( 'the main site: untouched', array_map( static fn( $id ) => get_option( "wpai_feature_{$id}_enabled" ), $ids ) === $plain );

// 3. Over HTTP ------------------------------------------------------------------------------

echo "\nOver HTTP (member site)\n";
$cookie = static function ( int $user_id ): array {
	$expiration = time() + 300;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	register_shutdown_function( static fn() => WP_Session_Tokens::get_instance( $user_id )->destroy( $token ) );
	return array(
		SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token ),
		LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ),
	);
};
// This container reaches only the main host; the Host header picks the site. A REST call gets its nonce.
$get = static function ( string $path, int $user_id, array $cookies ) use ( $site ): array {
	$headers = array( 'Host' => get_site( $site )->domain );
	if ( str_contains( $path, '/wp-json/' ) ) {
		$_COOKIE[ LOGGED_IN_COOKIE ] = $cookies[ LOGGED_IN_COOKIE ];
		wp_set_current_user( $user_id );
		$headers['X-WP-Nonce'] = wp_create_nonce( 'wp_rest' );
	}
	$response = wp_remote_get(
		'https://' . get_site( get_main_site_id() )->domain . $path,
		array(
			'cookies'     => $cookies,
			'headers'     => $headers,
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
$as_member  = $cookie( $member );
$as_admin   = $cookie( $admin );
$admin_path = (string) wp_parse_url( get_admin_url( $site ), PHP_URL_PATH );
$rest_path  = (string) wp_parse_url( get_rest_url( $site ), PHP_URL_PATH );

$refused = array();
foreach ( array( 'options-general.php?page=ai-wp-admin', 'tools.php?page=ai-connector-approval', 'options-general.php?page=ai-request-logs' ) as $screen ) {
	$out = $get( $admin_path . $screen, $member, $as_member );
	if ( 403 !== $out['code'] ) {
		$refused[] = $screen . ' ' . $out['code'];
	}
}
$check( 'the plugin\'s screens are refused', ! $refused, implode( ', ', $refused ) );
$dashboard = $get( $admin_path . 'index.php', $member, $as_member );
$check( '...and not in the menus', 200 === $dashboard['code'] && ! preg_match( '/page=(ai-wp-admin|ai-connector-approval|ai-mcp-access|ai-request-logs)\b/', $dashboard['body'] ), (string) $dashboard['code'] );
$routes = array();
foreach ( array( 'ai/v1/connector-approvals', 'ai/v1/settings/export', 'mcp/mcp-adapter-default-server' ) as $route ) {
	$out = $get( $rest_path . $route, $member, $as_member );
	if ( 403 !== $out['code'] || ! str_contains( $out['body'], 'mody_locked' ) ) {
		$routes[] = $route . ' ' . $out['code'];
	}
}
$check( 'configuration and MCP routes are refused', ! $routes, implode( ', ', $routes ) );
$title = $get( $rest_path . 'wp-abilities/v1/abilities/ai/title-generation', $member, $as_member );
$image = $get( $rest_path . 'wp-abilities/v1/abilities/ai/image-generation', $member, $as_member );
$check( 'a curated feature is there, an excluded one is not', 200 === $title['code'] && 404 === $image['code'], 'title ' . $title['code'] . ', image ' . $image['code'] );
$out = $get( $rest_path . 'ai/v1/connector-approvals', $admin, $as_admin );
$check( 'a super admin is not refused', ! str_contains( $out['body'], 'mody_locked' ), (string) $out['code'] );
wp_set_current_user( $admin );

// 4. The request log table ---------------------------------------------------------------------

echo "\nA new site\n";
$new = wpmu_create_blog( 'modyai' . $suffix . '.' . get_network()->domain, '/', 'Mody AI test', $admin, array( 'template' => 'theme', 'stylesheet' => 'theme' ) );
wp_installing( false );
if ( is_wp_error( $new ) ) {
	$check( 'test site created', false, $new->get_error_message() );
} else {
	register_shutdown_function( static fn() => wp_delete_site( $new ) );
	$log = $wpdb->get_blog_prefix( $new ) . 'wpai_request_logs';
	$check( 'gets the plugin\'s request log table', $log === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log ) ), $log );
}

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
