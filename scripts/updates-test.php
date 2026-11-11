<?php
/**
 * Tests that WordPress and plugins are updated only through the repository
 * (tickets/updates-through-composer-only.md): nobody has an update
 * capability, and core never checks WordPress.org for updates on its own.
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/updates-test.php
 *
 * A test member is made administrator of a member site (the first site that
 * is not the main one).
 *
 * 1. Capabilities: the four update capabilities are refused to the member
 *    and to a super admin, on the member site and the main site. What they
 *    may do otherwise is unchanged.
 * 2. Core's triggers of the checks are gone, and the main site has no update
 *    events scheduled.
 * 3. Over HTTP:
 *    - With the update caches emptied, a super admin opens the network
 *      Dashboard and Plugins and Themes screens, and a member site's Plugins
 *      screen. No check ran: core writes its cache before it asks
 *      WordPress.org, and the caches stay empty.
 *    - The member site's update events, scheduled by the test, are cleared
 *      by that request.
 *    - The network Updates screen refuses the super admin and is not in the
 *      menu.
 *    - With a fake update in the cache, the network Plugins screen shows no
 *      update notice and no update count.
 *
 * The update caches are put back at the end, and the member is removed.
 * No model call, and no request to WordPress.org (one would mean a failure).
 */

use Mody\Platform\Gating\Updates;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-60s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

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

$suffix = strtolower( wp_generate_password( 6, false ) );
$member = (int) wp_insert_user(
	array(
		'user_login' => 'modyupdates' . $suffix,
		'user_email' => 'modyupdates' . $suffix . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	)
);
add_user_to_blog( $site, $member, 'administrator' );
$admins = get_super_admins();
$super  = get_user_by( 'login', $admins[0] )->ID;

// The update caches, put back at the end.
$caches = array( 'update_core', 'update_plugins', 'update_themes' );
$saved  = array();
foreach ( $caches as $cache ) {
	$saved[ $cache ] = get_site_transient( $cache );
}
register_shutdown_function(
	static function () use ( $saved, $member ): void {
		foreach ( $saved as $cache => $value ) {
			false === $value ? delete_site_transient( $cache ) : set_site_transient( $cache, $value );
		}
		wpmu_delete_user( $member );
	}
);

// 1. Capabilities -------------------------------------------------------------------

echo "Capabilities\n";
foreach ( array(
	'member'      => $member,
	'super admin' => $super,
) as $who => $user_id ) {
	foreach ( array(
		'member site' => $site,
		'main site'   => $main,
	) as $where => $site_id ) {
		$granted = array_filter( Updates::CAPS, static fn( string $cap ): bool => user_can_for_site( $user_id, $site_id, $cap ) );
		$check( "$who, $where: no update capability", ! $granted, implode( ', ', $granted ) );
	}
}
$check( 'unchanged: the member switches plugins on the member site (M4-02)', user_can_for_site( $member, $site, 'activate_plugins' ) );
$check( 'unchanged: a super admin manages network plugins', user_can( $super, 'manage_network_plugins' ) && user_can_for_site( $super, $main, 'activate_plugins' ) );
$check( 'unchanged: installing follows DISALLOW_FILE_MODS', user_can_for_site( $super, $main, 'install_plugins' ) === wp_is_file_mod_allowed( 'capability_update_core' ), wp_is_file_mod_allowed( 'capability_update_core' ) ? 'file changes allowed here' : 'file changes disallowed here' );

// 2. Triggers -----------------------------------------------------------------------

echo "\nCore's triggers\n";
$left = array_filter( Updates::TRIGGERS, static fn( array $trigger ): bool => false !== has_action( $trigger[0], $trigger[1] ) );
$check( 'none of the ' . count( Updates::TRIGGERS ) . ' triggers is hooked', ! $left, implode( ', ', array_map( static fn( array $t ): string => $t[0] . ' ' . $t[1], $left ) ) );
$scheduled = array_filter( Updates::EVENTS, static fn( string $event ): bool => (bool) wp_next_scheduled( $event ) );
$check( 'main site: no update event scheduled', ! $scheduled, implode( ', ', $scheduled ) );

// 3. Over HTTP ----------------------------------------------------------------------

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
// This container reaches only the main host; the Host header picks the site.
$http     = static function ( string $site_host, string $url, array $cookies ): array {
	$response = wp_remote_get(
		'https://' . get_site( get_main_site_id() )->domain . (string) wp_parse_url( $url, PHP_URL_PATH ),
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
$as_super  = $cookie( $super );
$main_host = get_site( $main )->domain;
$host      = get_site( $site )->domain;

// The member site's events, for the request below to clear.
switch_to_blog( $site );
foreach ( Updates::EVENTS as $event ) {
	if ( ! wp_next_scheduled( $event ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', $event );
	}
}
$planted = count( array_filter( Updates::EVENTS, static fn( string $event ): bool => (bool) wp_next_scheduled( $event ) ) );
restore_current_blog();

foreach ( $caches as $cache ) {
	delete_site_transient( $cache );
}
$codes = array();
foreach ( array(
	array( $main_host, network_admin_url( 'index.php' ) ),
	array( $main_host, network_admin_url( 'plugins.php' ) ),
	array( $main_host, network_admin_url( 'themes.php' ) ),
	array( $host, get_admin_url( $site, 'plugins.php' ) ),
) as list( $screen_host, $url ) ) {
	$codes[] = $http( $screen_host, $url, $as_super )['code'];
}
wp_cache_flush(); // The requests ran in other processes.
$written = array_filter( $caches, static fn( string $cache ): bool => false !== get_site_transient( $cache ) );
// The Plugins screens are refused to everyone since 2026-11-11 (tickets/hide-plugins-and-connectors.md).
$check( 'two admin screens open, the two Plugins screens refused', array( 200, 403, 200, 403 ) === $codes, implode( ' ', $codes ) );
$check( '... and no update check ran (the caches stay empty)', ! $written, implode( ', ', $written ) );
switch_to_blog( $site );
$still = array_filter( Updates::EVENTS, static fn( string $event ): bool => (bool) wp_next_scheduled( $event ) );
restore_current_blog();
$check( 'member site: the update events are cleared by a request', 4 === $planted && ! $still, "planted $planted, left: " . implode( ', ', $still ) );

$out = $http( $main_host, network_admin_url( 'update-core.php' ), $as_super );
// Core's menu check refuses first (the page has no menu entry); update-core.php's own check would say "update this site".
$check( 'the network Updates screen refuses a super admin', 403 === $out['code'] && (bool) preg_match( '/Sorry, you are not allowed to (access this page|update this site)\./', $out['body'] ), (string) $out['code'] );
$out = $http( $main_host, network_admin_url( 'index.php' ), $as_super );
$check( '... and is not in the network menu', 200 === $out['code'] && str_contains( $out['body'], 'id="adminmenu"' ) && ! str_contains( $out['body'], 'update-core.php' ), (string) $out['code'] );

// A fake update for mcp-adapter, as if a check had found one.
$platform = 'mcp-adapter/mcp-adapter.php';
set_site_transient(
	'update_plugins',
	(object) array(
		'last_checked' => time(),
		'checked'      => array(),
		'response'     => array(
			$platform => (object) array(
				'slug'        => 'mcp-adapter',
				'plugin'      => $platform,
				'new_version' => '99.0.0',
				'url'         => 'https://wordpress.org/plugins/mcp-adapter/',
				'package'     => '',
			),
		),
		'no_update'    => array(),
		'translations' => array(),
	)
);
$out = $http( $main_host, network_admin_url( 'index.php' ), $as_super );
$check( 'a fake update: the network dashboard opens', 200 === $out['code'], (string) $out['code'] );
$check( '... with no update notice and no update count', ! str_contains( $out['body'], 'There is a new version of' ) && ! preg_match( '/class="update-plugins count-[1-9]/', $out['body'] ) );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
