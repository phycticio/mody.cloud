<?php
/**
 * Tests the agent's chat bubble in the editors (ticket M3-05).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/agent-bubble-test.php
 *
 * 1. The Mody Agent page: gone on member sites, super admins only on the main site.
 * 2. The bubble's settings: the chat page's, plus its own; the brand profile flag.
 * 3. Where it loads: the Site Editor and the post editor, for users who may
 *    use the agent; not on other screens or for other users.
 * 4. Over HTTP: the Site Editor and the post editor carry the bubble, on the
 *    main site and on a member site (through the Host header); the page is
 *    refused on the member site.
 *
 * A test user (administrator of a member site), a subscriber and a post are
 * created and removed. No model call.
 */

use Mody\Platform\Agent\ChatPage;
use Mody\Platform\Agent\EditorBubble;

require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-48s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

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
	echo "No member site on this network: nothing to test.\n";
	return;
}
$site   = (int) $others[0];
$suffix = strtolower( wp_generate_password( 6, false ) );
$make   = static fn( string $name ): int => wp_insert_user(
	array(
		'user_login' => $name . $suffix,
		'user_email' => $name . $suffix . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	)
);
$member     = $make( 'modybubble' );
$subscriber = $make( 'modybubblesub' );
add_user_to_blog( $site, $member, 'administrator' );
add_user_to_blog( $main, $member, 'administrator' );
add_user_to_blog( $site, $subscriber, 'subscriber' );
switch_to_blog( $site );
$post_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Mody bubble test post', 'post_author' => $member ) );
restore_current_blog();
register_shutdown_function(
	static function () use ( $site, $post_id, $member, $subscriber ): void {
		switch_to_blog( $site );
		wp_delete_post( $post_id, true );
		restore_current_blog();
		wpmu_delete_user( $member );
		wpmu_delete_user( $subscriber );
	}
);

// 1. The page ------------------------------------------------------------------

echo "The Mody Agent page\n";
$shows = static function ( int $site_id, int $user_id ): bool {
	switch_to_blog( $site_id );
	wp_set_current_user( $user_id );
	$shown = ChatPage::shows_menu();
	restore_current_blog();
	return $shown;
};
$check( 'main site, super admin: shown', $shows( $main, $admin ) );
$check( 'main site, another administrator: hidden', ! $shows( $main, $member ) );
$check( 'member site, its administrator: hidden', ! $shows( $site, $member ) );
$check( 'member site, super admin: hidden (the bubble)', ! $shows( $site, $admin ) );
wp_set_current_user( $admin );

// 2. Settings ---------------------------------------------------------------------

echo "\nSettings\n";
$settings = EditorBubble::settings();
$page     = ChatPage::settings();
$check( 'the chat page\'s settings', $settings['endpoint'] === $page['endpoint'] && $settings['interval'] === $page['interval'] && array_intersect_key( $settings['strings'], $page['strings'] ) === $page['strings'] );
$check( '...plus the bubble\'s', true === $settings['bubble'] && ChatPage::chips() === $settings['chips'] && isset( $settings['strings']['open'] ) );
$check( 'screens open in place: the strings', isset( $settings['strings']['opened'], $settings['strings']['back'] ) && str_contains( $settings['strings']['opened'], '%s' ) );
$script = (string) file_get_contents( WPMU_PLUGIN_DIR . '/mody-platform/assets/agent-bubble.js' );
$check( '...the script: pushState, popstate, the screen sent', str_contains( $script, 'history.pushState' ) && str_contains( $script, "new PopStateEvent( 'popstate'" ) && str_contains( $script, 'config.screen' ) && str_contains( (string) file_get_contents( WPMU_PLUGIN_DIR . '/mody-platform/assets/agent-chat.js' ), 'body.screen' ) );
$check( 'agent buttons open the editor with the chat open', 'mody-chat' === $settings['openParam'] && str_ends_with( EditorBubble::open_url( admin_url( 'site-editor.php?p=%2Fstyles' ) ), '&mody-chat=open' ) );
$check( 'no brand gate: every site has its brand in its settings (M2-08)', ! array_key_exists( 'hasProfile', $settings ) && ! isset( $settings['strings']['profile'] ) );

// 3. Where it loads -----------------------------------------------------------------

echo "\nWhere it loads\n";
$loads = static function ( string $screen, int $user_id ): bool {
	wp_dequeue_script( 'mody-agent-bubble' );
	wp_dequeue_script( 'mody-agent-chat' );
	wp_set_current_user( $user_id );
	set_current_screen( $screen );
	EditorBubble::enqueue();
	return wp_script_is( 'mody-agent-bubble', 'enqueued' ) && wp_script_is( 'mody-agent-chat', 'enqueued' );
};
$check( 'the Site Editor', $loads( 'site-editor', $admin ) );
$check( 'the post editor', $loads( 'post', $admin ) );
$check( 'the chat script runs after the bubble', in_array( 'mody-agent-bubble', wp_scripts()->registered['mody-agent-chat']->deps, true ) );
$check( 'not on the dashboard', ! $loads( 'dashboard', $admin ) );
switch_to_blog( $site );
$check( 'not for a subscriber', ! $loads( 'site-editor', $subscriber ) );
restore_current_blog();
wp_set_current_user( $admin );

// 4. Over HTTP -----------------------------------------------------------------------

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
$get = static function ( int $site_id, string $path, array $cookies ): array {
	$response = wp_remote_get(
		'https://' . get_site( get_main_site_id() )->domain . wp_parse_url( get_admin_url( $site_id ), PHP_URL_PATH ) . $path,
		array(
			'cookies'     => $cookies,
			'headers'     => array( 'Host' => get_site( $site_id )->domain ),
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
$has_bubble = static fn( array $out ): bool => 200 === $out['code'] && str_contains( $out['body'], 'assets/agent-bubble.js' ) && str_contains( $out['body'], '"bubble":true' );

$out = $get( $main, 'site-editor.php', $cookie( $admin ) );
$check( 'main site: the Site Editor has the bubble', $has_bubble( $out ), (string) $out['code'] );
$as_member = $cookie( $member );
$out       = $get( $site, 'site-editor.php', $as_member );
$check( 'member site: the Site Editor has the bubble', $has_bubble( $out ), (string) $out['code'] );
$out = $get( $site, 'post.php?post=' . $post_id . '&action=edit', $as_member );
$check( '...and the post editor', $has_bubble( $out ), (string) $out['code'] );
$out = $get( $site, 'index.php', $as_member );
$check( '...not the dashboard', 200 === $out['code'] && ! str_contains( $out['body'], 'assets/agent-bubble.js' ), (string) $out['code'] );
$out = $get( $site, 'admin.php?page=' . ChatPage::SLUG, $as_member );
$check( '...the Mody Agent page is gone', 403 === $out['code'] && ! str_contains( $out['body'], 'id="mody-agent-form"' ), (string) $out['code'] );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
