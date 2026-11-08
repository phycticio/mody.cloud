<?php
/**
 * Tests the wp-admin colors (tickets/admin-colors.md): Ocean by default, and
 * the agent chat in the user's own color scheme.
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/admin-colors-test.php
 *
 * 1. Ocean by default: new users get it unless a scheme is given, users
 *    without one get it, the one-time move ran and moves only users on
 *    WordPress's default (Modern).
 * 2. The chat's user-message color is the second color of every core
 *    scheme's palette, readable under white text.
 * 3. Over HTTP: the Site Editor on a member site (the bubble) and the agent
 *    page on the main site carry the user's color and scheme.
 *
 * The test users are removed at the end. No model call.
 */

use Mody\Platform\Admin\AdminColors;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-52s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

$suffix = strtolower( wp_generate_password( 6, false ) );
$users  = array();
$new    = static function ( string $login, string $scheme = '' ) use ( $suffix, &$users ): int {
	$data = array(
		'user_login' => $login . $suffix,
		'user_email' => $login . $suffix . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	);
	if ( '' !== $scheme ) {
		$data['admin_color'] = $scheme;
	}
	$users[] = (int) wp_insert_user( $data );
	return end( $users );
};
register_shutdown_function(
	static function () use ( &$users ): void {
		foreach ( $users as $user_id ) {
			wpmu_delete_user( $user_id );
		}
	}
);

// 1. Ocean by default ----------------------------------------------------------------

echo "Ocean by default\n";
$plain = $new( 'modycolor' );
$check( 'a new user gets Ocean', 'ocean' === get_user_meta( $plain, 'admin_color', true ), (string) get_user_meta( $plain, 'admin_color', true ) );
$chosen = $new( 'modycolormid', 'midnight' );
$check( 'a scheme given at creation is kept', 'midnight' === get_user_meta( $chosen, 'admin_color', true ), (string) get_user_meta( $chosen, 'admin_color', true ) );
wp_update_user(
	array(
		'ID'          => $chosen,
		'admin_color' => 'coffee',
	)
);
$check( 'a user can still change it (profile)', 'coffee' === get_user_option( 'admin_color', $chosen ), (string) get_user_option( 'admin_color', $chosen ) );
delete_user_meta( $plain, 'admin_color' );
$check( 'a user with no scheme stored sees Ocean', 'ocean' === get_user_option( 'admin_color', $plain ), (string) get_user_option( 'admin_color', $plain ) );
$check( 'the one-time move ran', '1' === get_site_option( 'mody_admin_colors_version' ) );
update_user_meta( $plain, 'admin_color', AdminColors::CORE_DEFAULT );
$moved = AdminColors::move_users( array( $plain, $chosen ) );
$check( 'the move takes Modern users to Ocean, only them', 1 === $moved && 'ocean' === get_user_meta( $plain, 'admin_color', true ) && 'coffee' === get_user_meta( $chosen, 'admin_color', true ), $moved . ' moved' );

// 2. The chat's user-message color --------------------------------------------------

echo "\nThe chat's user-message color\n";
global $_wp_admin_css_colors;
register_admin_color_schemes();
$luminance = static function ( string $hex ): float {
	$hex = ltrim( $hex, '#' );
	$hex = 3 === strlen( $hex ) ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] : $hex;
	$rgb = array_map( static fn( string $c ): float => hexdec( $c ) / 255, str_split( $hex, 2 ) );
	$lin = array_map( static fn( float $c ): float => $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4, $rgb );
	return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
};
$probe = $new( 'modycolorprobe' );
foreach ( $_wp_admin_css_colors as $scheme => $data ) {
	update_user_meta( $probe, 'admin_color', $scheme );
	$color    = AdminColors::second_color( $probe );
	$contrast = 1.05 / ( $luminance( $color ) + 0.05 );
	$check( $scheme . ': the palette\'s second color, white text readable', $color === $data->colors[1] && $contrast >= 4.5, $color . ', ' . round( $contrast, 1 ) . ':1' );
}
update_user_meta( $probe, 'admin_color', 'no-such-scheme' );
$check( 'an unknown scheme: Ocean\'s', '#39535a' === AdminColors::second_color( $probe ), AdminColors::second_color( $probe ) );
wp_set_current_user( $plain );
$check( 'the inline CSS for the current user', ':root { --mody-agent-user-message: #39535a; }' === AdminColors::agent_chat_css(), AdminColors::agent_chat_css() );
wp_set_current_user( 0 );
$css = (string) file_get_contents( WPMU_PLUGIN_DIR . '/mody-platform/assets/agent-chat.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
$check( 'agent-chat.css reads it for user messages', (bool) preg_match( '/\.mody-agent__message--user\s*\{[^}]*background:\s*var\(--mody-agent-user-message,\s*var\(--wp-admin-theme-color/', $css ) );

// 3. Over HTTP ----------------------------------------------------------------------

echo "\nOver HTTP\n";
$main         = get_main_site_id();
$member_sites = get_sites(
	array(
		'site__not_in' => array( $main ),
		'fields'       => 'ids',
		'number'       => 1,
	)
);
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
		'https://' . get_site( get_main_site_id() )->domain . $path,
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
$has_color = static fn( string $body, string $color ): bool => str_contains( $body, '--mody-agent-user-message: ' . $color . ';' );

if ( ! $member_sites ) {
	echo "No member site on this network: the bubble is not checked.\n";
} else {
	$site   = (int) $member_sites[0];
	$editor = (string) wp_parse_url( get_admin_url( $site, 'site-editor.php' ), PHP_URL_PATH );
	add_user_to_blog( $site, $plain, 'administrator' );
	$out = $get( $site, $editor, $cookie( $plain ) );
	$check( 'Site Editor, member on Ocean: the scheme', 200 === $out['code'] && str_contains( $out['body'], 'admin-color-ocean' ), (string) $out['code'] );
	$check( '... and the bubble\'s user color #39535a', $has_color( $out['body'], '#39535a' ) );
	$sunrise = $new( 'modycolorsun', 'sunrise' );
	add_user_to_blog( $site, $sunrise, 'administrator' );
	$out = $get( $site, $editor, $cookie( $sunrise ) );
	$check( 'Site Editor, member on Sunrise: the scheme', 200 === $out['code'] && str_contains( $out['body'], 'admin-color-sunrise' ), (string) $out['code'] );
	$check( '... and the bubble\'s user color #8a312d', $has_color( $out['body'], '#8a312d' ) );
}

$admins = get_super_admins();
$super  = get_user_by( 'login', $admins[0] )->ID;
$scheme = (string) get_user_option( 'admin_color', $super );
$out    = $get( $main, (string) wp_parse_url( get_admin_url( $main, 'admin.php?page=' . Mody\Platform\Agent\ChatPage::SLUG ), PHP_URL_PATH ) . '?page=' . Mody\Platform\Agent\ChatPage::SLUG, $cookie( $super ) );
$check( 'agent page (main site, super admin): their color', 200 === $out['code'] && $has_color( $out['body'], AdminColors::second_color( $super ) ), $out['code'] . ', ' . $scheme . ' ' . AdminColors::second_color( $super ) );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
