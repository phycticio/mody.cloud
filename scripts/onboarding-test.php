<?php
/**
 * Tests a new member's first steps (ticket M1-03): the welcome email's link,
 * the first password opening the Identity screen, and the editor tour.
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/onboarding-test.php
 *
 * 1. An account (Registration::register()) and its welcome email's link.
 * 2. Over HTTP, WordPress's own reset screen: the link, then the new
 *    password logs the member in and redirects to their site's Identity
 *    screen; the stage moves to `tour`; the password works.
 * 3. The Site Editor on the member's site loads the tour, after the chat
 *    bubble; the steps and their selectors (checked against core's
 *    edit-site.js, so a WordPress update that renames them shows here).
 * 4. Finishing: the stage set to `done` through wp/v2/users/me with the
 *    page's nonce; another value is refused, and nobody sets another
 *    user's. Then no tour, unless asked for with ?mody-tour=start.
 * 5. Later resets behave as in WordPress; without a site the first screen
 *    is the dashboard; outside wp-login.php only the stage moves.
 *
 * Everything created is removed at the end. No model call.
 */

use Mody\Platform\Members\Members;
use Mody\Platform\Members\Registration;
use Mody\Platform\Onboarding\EditorTour;
use Mody\Platform\Onboarding\Onboarding;
use Mody\Platform\SiteEditor;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-64s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

if ( ! is_main_site() ) {
	echo "Run this on the main site (--url=https://<main host>).\n";
	return;
}
$suffix = strtolower( wp_generate_password( 6, false ) );
$email  = "modyonb{$suffix}@mody.local";
$bare   = 0;
register_shutdown_function(
	static function () use ( $email, &$bare ): void {
		foreach ( array_filter( array( get_user_by( 'email', $email ), $bare ? get_userdata( $bare ) : false ) ) as $user ) {
			foreach ( get_blogs_of_user( $user->ID ) as $site ) {
				if ( ! is_main_site( (int) $site->userblog_id ) ) {
					wpmu_delete_blog( (int) $site->userblog_id, true );
				}
			}
			Members::delete( $user->ID );
			wpmu_delete_user( $user->ID );
		}
	}
);
$main = get_site( get_main_site_id() )->domain;
// This container reaches only the main host; the Host header picks the site.
$http = static function ( string $method, string $url, array $cookies = array(), $body = null, array $headers = array() ) use ( $main ): array {
	$parts    = wp_parse_url( $url );
	$response = wp_remote_request(
		'https://' . $main . ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ),
		array(
			'method'      => $method,
			'cookies'     => $cookies,
			'headers'     => array_merge( array( 'Host' => $parts['host'] ), $headers ),
			'body'        => $body,
			'sslverify'   => false,
			'redirection' => 0,
			'timeout'     => 60,
		)
	);
	$set = array();
	foreach ( is_wp_error( $response ) ? array() : wp_remote_retrieve_cookies( $response ) as $cookie ) {
		$set[ $cookie->name ] = $cookie->value;
	}
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $response ),
		'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		'body'     => (string) wp_remote_retrieve_body( $response ),
		'cookies'  => $set,
	);
};
$login_cookie = static function ( int $user_id ): array {
	$expiration = time() + 300;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	register_shutdown_function( static fn() => WP_Session_Tokens::get_instance( $user_id )->destroy( $token ) );
	return array(
		SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token ),
		LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ),
	);
};
$reset_post = static function ( string $login, string $key, string $password ) use ( $http ): array {
	return $http(
		'POST',
		network_site_url( 'wp-login.php?action=resetpass', 'login_post' ),
		array( 'wp-resetpass-' . COOKIEHASH => $login . ':' . $key ),
		array(
			'pass1'     => $password,
			'pass2'     => $password,
			'rp_key'    => $key,
			'wp-submit' => 'Save Password',
		)
	);
};

// 1. The account and its link -------------------------------------------------------

echo "The account\n";
$mails = array();
add_filter(
	'pre_wp_mail',
	static function ( $short, array $mail ) use ( &$mails, $email ) {
		if ( $email === $mail['to'] ) {
			$mails[] = $mail;
		}
		return true;
	},
	10,
	2
);
$account = Registration::register( 'Grace Hopper', $email, 'Hopper Compilers', 'hopper' . $suffix );
$user    = get_user_by( 'email', $email );
$site_id = is_array( $account ) ? (int) $account['site_id'] : 0;
preg_match( '#(https?://\S+wp-login\.php\?action=rp&key=([^&\s]+)&login=(\S+))#', (string) ( $mails[0]['message'] ?? '' ), $link );
$check( 'an account with a site, and the welcome email\'s link', $user && $site_id && isset( $link[1] ), $site_id ? get_site( $site_id )->domain : 'none' );
if ( ! $user || ! $site_id || ! isset( $link[1] ) ) {
	echo "\nCannot go on.\n";
	return;
}
$identity = ( static function () use ( $site_id ): string {
	switch_to_blog( $site_id );
	$url = SiteEditor::url( '/identity' );
	restore_current_blog();
	return $url;
} )();
$check( 'the first screen is the site\'s Identity screen', $identity === Onboarding::first_url( $user->ID ), $identity );

// 2. WordPress's reset screen, over HTTP --------------------------------------------

echo "\nThe first password, over HTTP\n";
$out = $http( 'GET', $link[1] );
$check( 'the link: WordPress\'s reset screen keeps the key in a cookie', 302 === $out['code'] && str_contains( $out['location'], 'action=rp' ) && isset( $out['cookies'][ 'wp-resetpass-' . COOKIEHASH ] ), $out['code'] . ' ' . $out['location'] );
$password = 'a long and new password ' . $suffix;
$out      = $reset_post( rawurldecode( $link[3] ), $link[2], $password );
$check( 'the new password: to the Identity screen', 302 === $out['code'] && $identity === $out['location'], $out['code'] . ' ' . $out['location'] );
$check( '... logged in', isset( $out['cookies'][ LOGGED_IN_COOKIE ], $out['cookies'][ SECURE_AUTH_COOKIE ] ), implode( ', ', array_keys( $out['cookies'] ) ) );
clean_user_cache( $user->ID );
wp_cache_delete( $user->ID, 'user_meta' );
$check( '... the stage is now "tour"', Onboarding::STAGE_TOUR === get_user_meta( $user->ID, Onboarding::META, true ) );
$check( '... and the password works', wp_authenticate( $user->user_login, $password ) instanceof WP_User );

// 3. The tour in the Site Editor ----------------------------------------------------

echo "\nThe tour\n";
$cookies = $login_cookie( $user->ID );
$out     = $http( 'GET', $identity, $cookies );
$tour    = strpos( $out['body'], 'assets/editor-tour.js' );
$bubble  = strpos( $out['body'], 'assets/agent-chat.js' );
$agent   = function_exists( 'wp_ai_client_prompt' );
$check( 'the Identity screen loads the tour and its settings', 200 === $out['code'] && false !== $tour && str_contains( $out['body'], 'window.modyTour' ) && str_contains( $out['body'], '"pending":true' ), (string) $out['code'] );
$check( '... after the chat bubble', ! $agent || ( false !== $bubble && $bubble < $tour ) );
$check( '... with the member\'s name and the trial\'s end', str_contains( $out['body'], '"name":"Grace Hopper"' ) && str_contains( $out['body'], 'Your free trial runs until' ) );

$ids = array_column( EditorTour::steps( 'X', true ), 'id' );
$check( 'the steps: welcome, identity, the agent, two tries, the editor', array( 'welcome', 'identity', 'agent', 'try-1', 'try-2', 'preview', 'styles', 'pages', 'navigation', 'patterns', 'templates', 'save', 'done' ) === $ids, implode( ',', $ids ) );
$check( '... without the agent, no agent steps', ! array_intersect( array( 'agent', 'try-1', 'try-2' ), array_column( EditorTour::steps( 'X', false ), 'id' ) ) );
$tries = array_filter( EditorTour::steps( 'X', true ), static fn( array $step ): bool => 'reply' === ( $step['wait'] ?? '' ) );
$check( '... the tries wait for a reply, offer starters, and end on Identity', 2 === count( $tries ) && ! array_filter( $tries, static fn( array $step ): bool => empty( $step['starters'] ) || 'chat' !== ( $step['open'] ?? '' ) ) && 'identity' === ( end( $tries )['leave'] ?? '' ) );
$core    = (string) file_get_contents( ABSPATH . 'wp-includes/js/dist/edit-site.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
$ours    = (string) file_get_contents( WPMU_PLUGIN_DIR . '/mody-platform/assets/agent-bubble.js' ) . file_get_contents( WPMU_PLUGIN_DIR . '/mody-platform/assets/identity-fields.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$missing = array();
foreach ( EditorTour::steps( 'X', true ) as $step ) {
	foreach ( $step['target'] ?? array() as $selector ) {
		foreach ( preg_split( '/\s+/', $selector ) as $part ) {
			$name = substr( $part, 1 );
			if ( ! str_contains( $core, '"' . $name . '"' ) && ! str_contains( $core, '"' . $name . ' ' ) && ! str_contains( $ours, $name ) ) {
				$missing[] = $part;
			}
		}
	}
}
$check( 'every selector exists in WordPress 7.1\'s Site Editor or in ours', ! $missing, implode( ' ', $missing ) );

// 4. Finishing ----------------------------------------------------------------------

echo "\nFinishing\n";
preg_match( '/createNonceMiddleware\(\s*"([0-9a-f]+)"\s*\)/', $out['body'], $nonce );
$me   = get_rest_url( $site_id, 'wp/v2/users/me' );
$post = static fn( array $meta ) => $http( 'POST', $me, $cookies, wp_json_encode( array( 'meta' => $meta ) ), array( 'Content-Type' => 'application/json', 'X-WP-Nonce' => $nonce[1] ?? '' ) );
$bad  = $post( array( Onboarding::META => 'nonsense' ) );
$check( 'an unknown stage is refused (400)', 400 === $bad['code'], (string) $bad['code'] );
$done = $post( array( Onboarding::META => Onboarding::STAGE_DONE ) );
wp_cache_delete( $user->ID, 'user_meta' );
$check( 'the tour saves "done" through wp/v2/users/me', 200 === $done['code'] && Onboarding::STAGE_DONE === get_user_meta( $user->ID, Onboarding::META, true ), (string) $done['code'] );
$admins = get_super_admins();
$super  = get_user_by( 'login', $admins[0] );
wp_set_current_user( $user->ID );
$check( '... and nobody sets another user\'s', ! current_user_can( 'edit_user_meta', $super->ID, Onboarding::META ) && current_user_can( 'edit_user_meta', $user->ID, Onboarding::META ) );
wp_set_current_user( 0 );
$out = $http( 'GET', $identity, $cookies );
$check( 'then the Site Editor has no tour', 200 === $out['code'] && ! str_contains( $out['body'], 'assets/editor-tour.js' ), (string) $out['code'] );
$out = $http( 'GET', $identity . '&' . Onboarding::TOUR_PARAM . '=start', $cookies );
$check( '... unless asked for: ?mody-tour=start, not pending', str_contains( $out['body'], 'assets/editor-tour.js' ) && str_contains( $out['body'], '"pending":false' ) );

// 5. Later resets, no site, other callers -------------------------------------------

echo "\nLater\n";
$key = get_password_reset_key( $user );
$out = $reset_post( $user->user_login, (string) $key, 'another long password ' . $suffix );
$check( 'a later reset: WordPress\'s own "Your password has been reset."', 200 === $out['code'] && str_contains( $out['body'], 'Your password has been reset.' ) && ! isset( $out['cookies'][ LOGGED_IN_COOKIE ] ), (string) $out['code'] );

$bare = wp_insert_user(
	array(
		'user_login' => 'modyonbbare' . $suffix,
		'user_email' => 'modyonbbare' . $suffix . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	)
);
update_user_meta( $bare, Onboarding::META, Onboarding::STAGE_PASSWORD );
$check( 'without a site, the first screen is the dashboard', Registration::dashboard_url() === Onboarding::first_url( $bare ) );
reset_password( get_userdata( $bare ), 'yet another long password ' . $suffix ); // Not on wp-login.php: no redirect.
$check( 'outside wp-login.php the stage moves, nothing else', Onboarding::STAGE_TOUR === get_user_meta( $bare, Onboarding::META, true ) && 0 === get_current_user_id() );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
