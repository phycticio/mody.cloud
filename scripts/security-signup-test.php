<?php
/**
 * Tests the signup and login hardening of the security pass of 2026-11-12
 * (tickets/M5-01-hardening-launch.md): the platform's own secrets and salts,
 * ALTCHA's single use and scope, the visitor's address, the rate limits, the
 * signup (the same answer for an existing email, text in the welcome email,
 * logins that are not the email), XML-RPC, public lists of users, passwords of
 * ten characters, and the analytics beacon's limits.
 *
 * Run from the repository root, on the main site, after `pnpm build`:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/security-signup-test.php
 *
 * Requests that come from "visitors" are sent the way Cloudflare forwards
 * them: a CF-Connecting-IP header of a documentation address (198.51.100.x,
 * 203.0.113.x) and an X-Forwarded-For that ends in an address of Cloudflare's
 * (the peer; the header is believed only then). Each test picks its own
 * visitor address, so limits never touch the real local ones. Everything created is
 * removed at the end, and the rate limit counters are emptied at the start
 * and the end. A signup takes a few seconds (a real site is built, once).
 * No model call.
 */

use Mody\Analytics\Collector;
use Mody\Analytics\Store;
use Mody\Platform\Members\Members;
use Mody\Platform\Members\Registration;
use Mody\Platform\Onboarding\Onboarding;
use Mody\Platform\Provisioning\Pipeline;
use Mody\Platform\Security\Altcha;
use Mody\Platform\Security\ClientAddress;
use Mody\Platform\Security\FormGuard;
use Mody\Platform\Security\Hardening;
use Mody\Platform\Security\RateLimit;
use Mody\Platform\Security\Secrets;

require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-78s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

if ( ! is_main_site() ) {
	echo "Run this on the main site (--url=https://<main host>).\n";
	return;
}
$alpha = get_blog_id_from_url( 'alpha.' . get_network()->domain );
if ( ! $alpha ) {
	echo "This test needs the member site alpha.\n";
	return;
}
global $wpdb;
RateLimit::clear();
$cli_address = $_SERVER['REMOTE_ADDR'] ?? null; // WP-CLI's own (private); put back after the tests that change it.

$suffix   = strtolower( wp_generate_password( 6, false ) );
$password = wp_generate_password( 24, false );
$main     = get_site( get_main_site_id() )->domain;
$was_open = get_site_option( Registration::OPEN_OPTION );
$created  = array(); // Users to delete.
$post_id  = 0;
$views_id = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Store::views() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
update_site_option( Registration::OPEN_OPTION, 1 );
register_shutdown_function(
	static function () use ( &$created, &$post_id, $alpha, $was_open, $views_id, $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		if ( $post_id ) {
			switch_to_blog( $alpha );
			wp_delete_post( $post_id, true );
			restore_current_blog();
		}
		foreach ( array_filter( $created ) as $user_id ) {
			foreach ( get_blogs_of_user( $user_id ) as $site ) {
				if ( ! is_main_site( (int) $site->userblog_id ) && (int) $site->userblog_id !== $alpha ) {
					wpmu_delete_blog( (int) $site->userblog_id, true );
				}
			}
			remove_user_from_blog( $user_id, $alpha );
			Members::delete( $user_id );
			wpmu_delete_user( $user_id );
		}
		false === $was_open ? delete_site_option( Registration::OPEN_OPTION ) : update_site_option( Registration::OPEN_OPTION, $was_open );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Store::views() . ' WHERE id > %d AND site_id = %d', $views_id, $alpha ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Store::values() . ' WHERE site_id IN (%d, %d)', $alpha, 987654321 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		RateLimit::clear();
	}
);
$new_user = static function ( string $login, string $email = '' ) use ( &$created, $password ): int {
	$id = (int) wpmu_create_user( $login, $password, '' !== $email ? $email : $login . '@mody.local' );
	$created[] = $id;
	return $id;
};

$bucket = static fn( string $name, string $subject ): string => $name . ':' . substr( hash_hmac( 'sha256', $subject, Secrets::get( 'rate-limit' ) ), 0, 32 );
$hits   = static fn( string $name, string $subject ): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM ' . RateLimit::table() . ' WHERE bucket = %s', $bucket( $name, $subject ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

// A signup over HTTP answers before the account exists; wait for its last step (the welcome email's reset key).
$settle = static function ( string $email ) use ( $wpdb ): bool {
	for ( $i = 0; $i < 120; $i++ ) {
		if ( '' !== (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_activation_key FROM {$wpdb->users} WHERE user_email = %s", $email ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return true;
		}
		usleep( 500000 );
	}
	return false;
};

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
			'user-agent'  => 'Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/131.0',
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
		'headers'  => is_wp_error( $response ) ? array() : $response['headers'],
		'cookies'  => $set,
		'json'     => json_decode( (string) wp_remote_retrieve_body( $response ), true ),
	);
};
$edge   = '173.245.48.17'; // An address in Cloudflare's published ranges: what our proxy sees as the peer.
$from   = static fn( string $address ): array => array(
	'CF-Connecting-IP' => $address,
	'X-Forwarded-For'  => '9.9.9.9, ' . $edge, // The left entry is whatever the visitor sent.
);
$proof  = static fn(): string => Altcha::solve( Altcha::challenge( 3 ) );
$failed = esc_html( Altcha::failed_message() );
$home   = network_home_url( '/' );
$login  = static fn( string $user, string $pass, string $altcha, string $address, string $site = '', array $cookies = array() ) => $http(
	'POST',
	( '' !== $site ? $site : network_site_url( '/' ) ) . 'wp-login.php',
	array( 'wordpress_test_cookie' => 'WP Cookie check' ) + $cookies,
	array(
		'log'        => $user,
		'pwd'        => $pass,
		'testcookie' => '1',
		'altcha'     => $altcha,
	),
	$from( $address )
);
$logged_in = static fn( array $out ): bool => (bool) array_filter( array_keys( $out['cookies'] ), static fn( $name ) => str_starts_with( $name, 'wordpress_logged_in_' ) );
$mails     = array();
add_filter(
	'pre_wp_mail',
	static function ( $short, array $mail ) use ( &$mails ) {
		$mails[] = $mail;
		return true;
	},
	10,
	2
);

// 1. Keys, salts and ALTCHA's own secrets -------------------------------------------------

echo "Secrets and salts\n";
$committed = array(
	'NONCE_KEY'  => 'f1b8b5ac5b73cad19a4a8ce7610c0dfd232a5a17',
	'NONCE_SALT' => 'efc226c23ae8a3897fc2479860d18c9a82d88146',
	'AUTH_KEY'   => '4f9affda1ab56529801dae42548c458395650bf2',
	'AUTH_SALT'  => '7e57cb25ec800c549a4eb6d20427392b831cf5df',
);
$live = NONCE_KEY === $committed['NONCE_KEY'] && AUTH_KEY === $committed['AUTH_KEY'];
$sig  = Secrets::get( 'altcha-signature' );
$key  = Secrets::get( 'altcha-key' );
$check( 'ALTCHA has its own random secrets: 64 hex digits, two different ones', (bool) preg_match( '/^[0-9a-f]{64}$/', $sig ) && (bool) preg_match( '/^[0-9a-f]{64}$/', $key ) && $sig !== $key );
$stored = get_site_option( Secrets::OPTION );
$check( '... kept in a network option, the same on every call', is_array( $stored ) && ( $stored['altcha-signature'] ?? '' ) === $sig && Secrets::get( 'altcha-signature' ) === $sig && count( $stored ) === count( Secrets::NAMES ) );
$old_secret = static fn( string $purpose ): string => hash_hmac( 'sha256', 'mody-altcha-' . $purpose, $committed['NONCE_KEY'] . $committed['NONCE_SALT'] );
$check( '... and not what the salts used to give (the repository\'s values)', hash_hmac( 'sha256', 'mody-altcha-signature', $sig ) !== $old_secret( 'signature' ) && $sig !== $old_secret( 'signature' ) );

// The forgery from the audit: both secrets computed from the committed salts, no work done.
$forged_key  = random_bytes( 32 );
$forged_par  = array(
	'algorithm'    => Altcha::ALGORITHM,
	'cost'         => Altcha::COST,
	'expiresAt'    => time() + DAY_IN_SECONDS,
	'keyLength'    => 32,
	'keyPrefix'    => bin2hex( substr( $forged_key, 0, 16 ) ),
	'keySignature' => hash_hmac( 'sha256', $forged_key, $old_secret( 'key' ) ),
	'nonce'        => bin2hex( random_bytes( 16 ) ),
	'salt'         => bin2hex( random_bytes( 16 ) ),
);
ksort( $forged_par );
$forged = base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	(string) wp_json_encode(
		array(
			'challenge' => array(
				'parameters' => $forged_par,
				'signature'  => hash_hmac( 'sha256', (string) wp_json_encode( $forged_par, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $old_secret( 'signature' ) ),
			),
			'solution'  => array(
				'counter'    => 1,
				'derivedKey' => bin2hex( $forged_key ),
			),
		),
		JSON_UNESCAPED_SLASHES
	)
);
$check( 'a solution forged from the committed salts, with no work, is refused', ! Altcha::verify( $forged ) );
$check( 'the work was raised, moderately: 2,000 to 7,000 attempts', Altcha::MIN_COUNTER >= 2000 && Altcha::MAX_COUNTER <= 8000 );

if ( $live ) {
	$check( 'wp-config.php still has the committed salts here, and wp_salt() does not return them', wp_salt( 'nonce' ) !== $committed['NONCE_KEY'] . $committed['NONCE_SALT'] && wp_salt( 'auth' ) !== $committed['AUTH_KEY'] . $committed['AUTH_SALT'] );
	$check( '... it returns this installation\'s own secret, the same every time', wp_salt( 'nonce' ) === Secrets::get( 'salt-nonce' ) && wp_salt( 'nonce' ) === wp_salt( 'nonce' ) && wp_salt( 'logged_in' ) !== wp_salt( 'nonce' ) );
} else {
	$check( 'real keys in the environment: wp_salt() is left alone', 'x' === Secrets::salt( 'x', 'nonce' ) );
}
$check( 'a scheme that is not WordPress\'s own is left alone', 'x' === Secrets::salt( 'x', 'custom' ) );
$check( 'nonces still work', 1 === wp_verify_nonce( wp_create_nonce( 'mody-sec-test' ), 'mody-sec-test' ) );
$uid_a = $new_user( 'secsalt' . $suffix );
$token = WP_Session_Tokens::get_instance( $uid_a )->create( time() + 300 );
$check( 'auth cookies still work (made and checked here)', $uid_a === wp_validate_auth_cookie( wp_generate_auth_cookie( $uid_a, time() + 300, 'logged_in', $token ), 'logged_in' ) );
$out = $login( 'secsalt' . $suffix, $password, $proof(), '198.51.100.10' );
$check( 'a real login over HTTP sets the cookie', $logged_in( $out ) && 302 === $out['code'], (string) $out['code'] );
$cookies = array();
foreach ( $out['cookies'] as $name => $value ) {
	$cookies[ $name ] = $value;
}
$nonce_out = $http( 'GET', $home . 'wp-admin/admin-ajax.php?action=rest-nonce', $cookies );
$me        = $http( 'GET', $home . 'wp-json/wp/v2/users/me', $cookies, null, array( 'X-WP-Nonce' => trim( $nonce_out['body'] ) ) );
$check( '... another request accepts the cookie and the nonce made for it', 200 === $nonce_out['code'] && 200 === $me['code'] && $uid_a === (int) ( $me['json']['id'] ?? 0 ), $nonce_out['code'] . ' ' . $me['code'] );
$downloads = (string) preg_replace( '#^\s*(/\*|\*|//).*$#m', '', (string) file_get_contents( WPMU_PLUGIN_DIR . '/mody-platform/src/Export/Downloads.php' ) ); // Code only, no comments.
$check( 'the export links sign with their own key, not with wp_salt() or wp_hash()', '' !== $downloads && ! str_contains( $downloads, 'wp_salt(' ) && ! str_contains( $downloads, 'wp_hash(' ) && str_contains( $downloads, 'KEY_OPTION' ) );

// 2. ALTCHA: once, atomically; its tag only on the main site ------------------------------

echo "\nALTCHA\n";
$payload  = $proof();
$mh       = curl_multi_init();
$handles  = array();
$resolved = array();
foreach ( range( 1, 20 ) as $i ) {
	$ch = curl_init( 'https://' . $main . '/wp-login.php' );
	curl_setopt_array( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
		$ch,
		array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query(
				array(
					'log'    => 'nobody' . $suffix,
					'pwd'    => 'x',
					'altcha' => $payload,
				)
			),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_HTTPHEADER     => array( 'CF-Connecting-IP: 198.51.100.' . ( 100 + $i ), 'Host: ' . $main ),
		)
	);
	curl_multi_add_handle( $mh, $ch );
	$handles[] = $ch;
}
do {
	$status = curl_multi_exec( $mh, $running );
	if ( $running ) {
		curl_multi_select( $mh, 1 );
	}
} while ( $running && CURLM_OK === $status );
$passed = 0;
foreach ( $handles as $ch ) {
	$body    = (string) curl_multi_getcontent( $ch );
	$passed += str_contains( $body, $failed ) ? 0 : 1;
	curl_multi_remove_handle( $mh, $ch );
	curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
}
curl_multi_close( $mh );
$check( 'one solution sent in 20 parallel requests is accepted by exactly one', 1 === $passed, $passed . ' accepted' );
$counter = RateLimit::counter( 'altcha:' . substr( 'test' . $suffix, 0, 20 ), 60 );
$again   = RateLimit::counter( 'altcha:' . substr( 'test' . $suffix, 0, 20 ), 60 );
$check( 'RateLimit::counter(): only the hit that makes the row is new', $counter && $again && $counter['new'] && ! $again['new'] && 2 === $again['hits'] );

$widget = Altcha::widget();
$check( 'the widget tag survives sanitizing on the main site', str_contains( wp_kses_post( $widget ), '<altcha-widget class="mody-altcha" name="altcha" challenge="' ) && str_contains( wp_kses_post( $widget ), 'configuration=' ) && str_contains( wp_kses_post( $widget ), 'auto="onfocus"' ) );
$own = esc_url( rest_url( 'mody-platform/v1/altcha' ) );
$bad = array(
	'another challenge address'         => '<altcha-widget challenge="https://evil.example/c" auto="onload"></altcha-widget>',
	'a verify address in configuration' => '<altcha-widget challenge="' . $own . '" configuration="{&quot;verifyurl&quot;:&quot;https://evil.example/v&quot;}"></altcha-widget>',
	'an unknown start'                  => '<altcha-widget challenge="' . $own . '" auto="evil"></altcha-widget>',
);
foreach ( $bad as $what => $html ) {
	$kept = wp_kses_post( $html );
	$check( "... $what: the attribute is removed", ! str_contains( $kept, 'evil' ), $kept );
}
switch_to_blog( $alpha );
$on_alpha = wp_kses_post( $widget );
restore_current_blog();
$check( 'on a member site the tag is removed', ! str_contains( $on_alpha, 'altcha-widget' ), $on_alpha );
$wait = 0;
for ( $i = 0; $i < 60; $i++ ) {
	$wait = RateLimit::hit( 'altcha_challenge', '203.0.113.60' );
}
$out60 = $http( 'GET', $home . 'wp-json/mody-platform/v1/altcha', array(), null, $from( '203.0.113.60' ) );
$out61 = $http( 'GET', $home . 'wp-json/mody-platform/v1/altcha', array(), null, $from( '203.0.113.61' ) );
$check( 'challenges: 60 in ten minutes per address, then a kind refusal (429)', 0 === $wait && 429 === $out60['code'] && 'mody_rate_limited' === ( $out60['json']['code'] ?? '' ) && str_contains( (string) ( $out60['json']['message'] ?? '' ), 'try again in' ), (string) $out60['code'] );
$check( '... another address is not affected', 200 === $out61['code'] && isset( $out61['json']['signature'] ), (string) $out61['code'] );

// 3. The visitor's address -----------------------------------------------------------------

echo "\nThe visitor's address\n";
$server = $_SERVER;
// REMOTE_ADDR, CF-Connecting-IP, X-Forwarded-For, the address believed.
$cases  = array(
	'a direct connection believes no header'                      => array( '203.0.113.5', '198.51.100.1', '8.8.8.8, 1.1.1.1', '203.0.113.5' ),
	'a connection from Cloudflare itself: its header'             => array( '104.16.0.5', '198.51.100.1', '', '198.51.100.1' ),
	'behind the proxies, peer in Cloudflare\'s IPv4: its header'  => array( '172.18.0.5', '198.51.100.1', '1.2.3.4, 104.16.1.1, 172.18.0.1', '198.51.100.1' ),
	'... in its IPv6 ranges'                                      => array( '172.18.0.5', '198.51.100.1', '1.2.3.4, 2606:4700::1111', '198.51.100.1' ),
	'... written the IPv4-in-IPv6 way (a dual-stack proxy)'       => array( '172.18.0.5', '198.51.100.1', '1.2.3.4, ::ffff:104.16.1.1', '198.51.100.1' ),
	'... the visitor written that way too'                        => array( '172.18.0.5', '::ffff:198.51.100.1', '1.2.3.4, ::ffff:104.16.1.1', '198.51.100.1' ),
	'a direct connection written that way'                        => array( '::ffff:203.0.113.5', '198.51.100.1', '8.8.8.8', '203.0.113.5' ),
	'a forged CF-Connecting-IP, peer not Cloudflare: ignored'     => array( '172.18.0.5', '198.51.100.99', '9.9.9.9, 198.51.100.20', '198.51.100.20' ),
	'... with a Cloudflare address forged on the left'            => array( '172.18.0.5', '198.51.100.99', '104.16.1.1, 198.51.100.20', '198.51.100.20' ),
	'... with no X-Forwarded-For at all: not known'               => array( '172.18.0.5', '198.51.100.99', '', '172.18.0.5' ),
	'... changing it on every request changes nothing'            => array( '172.18.0.5', '198.51.100.98', '198.51.100.20', '198.51.100.20' ),
	'peer in Cloudflare, no CF-Connecting-IP: the peer'           => array( '172.18.0.5', '', '203.0.113.7, 104.16.1.1', '104.16.1.1' ),
	'... skipping our own proxies on the right'                   => array( '172.18.0.5', '', '203.0.113.7, 198.51.100.20, 172.18.0.1', '198.51.100.20' ),
	'a forged leftmost entry is never used'                       => array( '172.18.0.5', '', '9.9.9.9, 8.8.8.8, 198.51.100.30', '198.51.100.30' ),
	'a header that is no address is ignored'                      => array( '172.18.0.5', 'not-an-ip', '104.16.1.1', '104.16.1.1' ),
	'nothing public: the connecting address'                      => array( '172.18.0.5', '', '10.0.0.1', '172.18.0.5' ),
);
$collector_agrees = true;
$collector_seen   = array();
foreach ( $cases as $label => $case ) {
	$_SERVER['REMOTE_ADDR'] = $case[0];
	unset( $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
	if ( '' !== $case[1] ) {
		$_SERVER['HTTP_CF_CONNECTING_IP'] = $case[1];
	}
	$_SERVER['HTTP_X_FORWARDED_FOR'] = $case[2];
	$platform                        = ClientAddress::get();
	$check( $label, $case[3] === $platform, $platform );
	$analytics         = Collector::address();
	$collector_agrees  = $collector_agrees && $platform === $analytics;
	$collector_seen[] = $platform === $analytics ? '' : "$label: $analytics";
}
$check( 'mody-analytics gives the same address in every case (Collector::address() calls ClientAddress::get())', $collector_agrees, implode( ' | ', array_filter( $collector_seen ) ) );

$in  = array( '173.245.48.0', '173.245.63.255', '103.21.244.1', '103.22.203.255', '103.31.5.9', '141.101.127.255', '108.162.255.255', '190.93.255.1', '188.114.111.1', '197.234.243.1', '198.41.200.1', '162.159.255.255', '104.23.255.255', '104.24.0.1', '104.27.255.255', '172.64.0.1', '172.71.255.255', '131.0.75.255', '2400:cb00::1', '2606:4700:ffff::1', '2803:f800:1::1', '2405:b500::1', '2405:8100::1', '2a06:98c0::1', '2a06:98c7:ffff::1', '2c0f:f248::1' );
$out = array( '173.245.64.1', '103.21.248.1', '141.101.128.1', '162.160.0.1', '104.15.255.255', '104.28.0.1', '172.63.255.255', '172.72.0.1', '131.0.76.1', '8.8.8.8', '1.1.1.1', '198.51.100.1', '2a06:98c8::1', '2606:4701::1', '2001:db8::1', '2c0f:f249::1', 'not-an-ip', '' );
$wrong_in  = array_filter( $in, static fn( string $ip ): bool => ! ClientAddress::is_cloudflare( $ip ) );
$wrong_out = array_filter( $out, static fn( string $ip ): bool => ClientAddress::is_cloudflare( $ip ) );
$check( 'Cloudflare\'s ranges: the first and last address of each are in, the neighbours are out', ! $wrong_in && ! $wrong_out, implode( ' ', array_merge( $wrong_in, $wrong_out ) ) );
$check( '... 15 IPv4 ranges and 7 IPv6 ranges, as published on 2026-11-12', 22 === count( ClientAddress::CLOUDFLARE_RANGES ) && 7 === count( array_filter( ClientAddress::CLOUDFLARE_RANGES, static fn( string $r ): bool => str_contains( $r, ':' ) ) ) );

$_SERVER['REMOTE_ADDR'] = '172.18.0.5';
unset( $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
$check( 'limits skip an address that is not known (the site itself, a local browser, WP-CLI)', '' === ClientAddress::limit_address() && 0 === RateLimit::hit_address( 'signup_check' ) );
$_SERVER['HTTP_X_FORWARDED_FOR']  = '1.2.3.4, ' . $edge;
$_SERVER['HTTP_CF_CONNECTING_IP'] = '2001:db8:abcd:12:aaaa:bbbb:cccc:dddd';
$a = ClientAddress::limit_address();
$_SERVER['HTTP_CF_CONNECTING_IP'] = '2001:db8:abcd:12:1:2:3:4';
$b                                = ClientAddress::limit_address();
$_SERVER['HTTP_CF_CONNECTING_IP'] = '2001:db8:abcd:13::1';
$c                                = ClientAddress::limit_address();
$check( 'an IPv6 visitor counts by its /64', '' !== $a && $a === $b && $a !== $c, $a );
$_SERVER = $server;

// A forged header over HTTP: the request is counted under the address it really came from, not the one it claims.
$forged = array( 'CF-Connecting-IP' => '203.0.113.77', 'X-Forwarded-For' => '198.51.100.77' ); // Peer 198.51.100.77 is no Cloudflare address.
for ( $i = 0; $i < 3; $i++ ) {
	$http( 'GET', $home . 'wp-json/mody-platform/v1/altcha', array(), null, array( 'CF-Connecting-IP' => '203.0.113.' . ( 120 + $i ) ) + $forged );
}
$check( 'over HTTP: a forged CF-Connecting-IP with a non-Cloudflare peer is counted under the peer (3 requests), never under the forged addresses', 3 === $hits( 'altcha_challenge', '198.51.100.77' ) && 0 === $hits( 'altcha_challenge', '203.0.113.120' ) && 0 === $hits( 'altcha_challenge', '203.0.113.121' ) && 0 === $hits( 'altcha_challenge', '203.0.113.122' ), $hits( 'altcha_challenge', '198.51.100.77' ) . '/' . $hits( 'altcha_challenge', '203.0.113.120' ) );

// 4. The counters -------------------------------------------------------------------------

echo "\nRate limits\n";
$limits = static fn( array $l ): array => $l + array( 'sec_test' => array( 3, 60 ) );
add_filter( 'mody_rate_limits', $limits );
$results = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$results[] = RateLimit::hit( 'sec_test', 'who' );
}
$check( 'three events allowed, the fourth and fifth refused with the seconds to wait', array( 0, 0, 0 ) === array_slice( $results, 0, 3 ) && $results[3] > 0 && $results[3] <= 60 && $results[4] > 0, wp_json_encode( $results ) );
$check( '... for that subject only', 0 === RateLimit::hit( 'sec_test', 'someone else' ) );
$check( 'wait() reads without counting', RateLimit::wait( 'sec_test', 'who' ) > 0 && 0 === RateLimit::wait( 'sec_test', 'never seen' ) && 0 === RateLimit::wait( 'sec_test', 'never seen' ) );
RateLimit::reset( 'sec_test', 'who' );
$check( 'reset() starts a subject over', 0 === RateLimit::wait( 'sec_test', 'who' ) && 0 === RateLimit::hit( 'sec_test', 'who' ) );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . RateLimit::table() . ' SET expires = %d WHERE bucket LIKE %s', time() - 5, 'sec_test:%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$row = RateLimit::counter( $bucket( 'sec_test', 'who' ), 60 );
$check( 'a window that is over starts again', $row && 1 === $row['hits'] && $row['expires'] > time() );
add_filter(
	'mody_rate_limits',
	static function ( array $l ): array {
		$l['sec_test'] = array( 0, 60 );
		return $l;
	},
	20
);
$off = 0;
for ( $i = 0; $i < 10; $i++ ) {
	$off += RateLimit::hit( 'sec_test', 'who' );
}
$check( 'the filter changes a limit; zero events turns it off', 0 === $off );
$check( 'an unknown limit never refuses', 0 === RateLimit::hit( 'no_such_limit', 'who' ) );
$buckets = $wpdb->get_col( 'SELECT bucket FROM ' . RateLimit::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$check( 'the table keeps a keyed hash of who, never an address or a name', $buckets && ! array_filter( $buckets, static fn( string $b ): bool => str_contains( $b, '203.0.113' ) || str_contains( $b, 'who' ) || str_contains( $b, '198.51.100' ) ), count( $buckets ) . ' rows' );
remove_all_filters( 'mody_rate_limits' );
$check( 'the defaults: signup 3 a day per address and 30 an hour in all; login 10 per address, 5 per account at one address, 100 per account anywhere, each in 15 minutes; lost password 5 an hour', array( 3, DAY_IN_SECONDS ) === RateLimit::DEFAULTS['signup_address'] && array( 30, HOUR_IN_SECONDS ) === RateLimit::DEFAULTS['signup_network'] && array( 10, 900 ) === RateLimit::DEFAULTS['login_address'] && array( 5, 900 ) === RateLimit::DEFAULTS['login_pair'] && array( 100, 900 ) === RateLimit::DEFAULTS['login_account'] && ! isset( RateLimit::DEFAULTS['login_user'] ) && array( 5, HOUR_IN_SECONDS ) === RateLimit::DEFAULTS['lost_password'] );

// 5. Signup --------------------------------------------------------------------------------

echo "\nSignup\n";
$check( 'normalize_email(): case, +tags and Gmail dots', 'johndoe@gmail.com' === Registration::normalize_email( 'John.Doe+x@Gmail.com' ) && 'john.doe@example.org' === Registration::normalize_email( 'John.Doe+x@example.org' ) );
$nonce  = wp_create_nonce( Registration::ACTION );
$rest   = static fn( string $route, array $body, string $address ): array => $http( 'POST', $home . 'wp-json/mody-platform/v1/' . $route, array(), wp_json_encode( $body ), $from( $address ) + array( 'Content-Type' => 'application/json' ) );
$email1 = "secsignup{$suffix}@mody.local";
$slug1  = "secsign{$suffix}";
$_SERVER['REMOTE_ADDR'] = '198.51.100.201';
$mails                  = array();
$started                = microtime( true );
$account                = Registration::register( 'Ada Lovelace', $email1, 'Ada\'s Analytical Bakery', $slug1 );
$t_sync                 = microtime( true ) - $started; // What a visitor would wait for if the work were done before the response.
$user1                  = get_user_by( 'email', $email1 );
$created[]              = $user1 ? $user1->ID : 0;
$check( 'a first signup creates the account and the site', is_array( $account ) && $user1 && (int) $account['site_id'] > 0 && $account['user_id'] === $user1->ID );
$local = strstr( $email1, '@', true );
$check( 'the login is the name\'s letters and a random suffix, never the email\'s local part', $user1 && ! str_contains( $user1->user_login, $local ) && (bool) preg_match( '/^adalovelace[0-9a-f]{6}$/', $user1->user_login ), $user1 ? $user1->user_login : '' );
$check( '... and the public author name is another random value', $user1 && $user1->user_nicename !== $user1->user_login && (bool) preg_match( '/^adalovelace-[0-9a-f]{6}$/', $user1->user_nicename ), $user1 ? $user1->user_nicename : '' );
wp_installing( true ); // wpmu_create_blog() turns it on and never off, for the rest of its request (the wizard, tests).
$check( 'while WordPress says it is installing, the keys stay this installation\'s (one request must not sign with two)', wp_salt( 'nonce' ) === Secrets::get( 'salt-nonce' ) && wp_salt( 'secure_auth' ) === Secrets::get( 'salt-secure_auth' ) );
wp_installing( false );
$welcome = array_values( array_filter( $mails, static fn( array $m ): bool => $email1 === $m['to'] ) );
$check( 'one welcome email to the member', 1 === count( $welcome ) && str_contains( (string) $welcome[0]['subject'], 'choose your password' ) );

$users_before = count( get_users( array( 'fields' => 'ID', 'blog_id' => 0 ) ) );
$sites_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->blogs}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$mails        = array();
$again        = Registration::register( 'Mallory Visit evil', $email1, 'Pay now', 'secother' . $suffix );
$note         = array_values( array_filter( $mails, static fn( array $m ): bool => $email1 === $m['to'] ) );
$check( 'the same email again: the same kind of answer, with the email and the address', is_array( $again ) && $email1 === $again['email'] && Pipeline::domain( 'secother' . $suffix ) === $again['address'] && 0 === $again['user_id'] );
$check( '... nothing is created: no user, no site', count( get_users( array( 'fields' => 'ID', 'blog_id' => 0 ) ) ) === $users_before && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->blogs}" ) === $sites_before && ! get_blog_id_from_url( Pipeline::domain( 'secother' . $suffix ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$check( '... and the member gets a note, with fixed text and no word of what was typed', 1 === count( $note ) && str_contains( (string) $note[0]['subject'], 'already have an account' ) && str_contains( (string) $note[0]['message'], 'wp-login.php' ) && ! str_contains( (string) $note[0]['message'], 'Mallory' ) && ! str_contains( (string) $note[0]['message'], 'Pay now' ) );
$mails = array();
Registration::register( 'Mallory', $email1, 'Pay now', 'secother' . $suffix );
$check( '... once: a second try mails nothing more', 0 === count( array_filter( $mails, static fn( array $m ): bool => $email1 === $m['to'] ) ) );
$email2 = "secsign2{$suffix}@mody.local";
$fourth = Registration::register( 'Grace Hopper', $email2, 'Hopper Compilers', 'secfour' . $suffix );
$check( 'a fourth try from the same address in a day: refused kindly (429), nothing created', is_wp_error( $fourth ) && 'mody_rate_limited' === $fourth->get_error_code() && 429 === ( $fourth->get_error_data()['status'] ?? 0 ) && str_contains( $fourth->get_error_message(), 'try again in' ) && ! get_user_by( 'email', $email2 ), is_wp_error( $fourth ) ? $fourth->get_error_message() : 'created' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.202';
$bad                    = Registration::register( 'Grace Hopper', 'nope', 'Hopper Compilers', 'secfour' . $suffix );
$check( 'an invalid answer is refused as invalid, with no account', is_wp_error( $bad ) && 'mody_invalid_input' === $bad->get_error_code() && ! get_user_by( 'email', 'nope' ) );
$_SERVER['REMOTE_ADDR'] = $cli_address ?? '127.0.0.1';

RateLimit::clear();
for ( $i = 0; $i < 30; $i++ ) {
	RateLimit::hit( 'signup_network' );
}
$network = Registration::register( 'Grace Hopper', $email2, 'Hopper Compilers', 'secfour' . $suffix );
$check( 'the 31st account in an hour, anywhere: refused kindly, nothing created', is_wp_error( $network ) && 'mody_rate_limited' === $network->get_error_code() && str_contains( $network->get_error_message(), 'Many people are signing up' ) && ! get_user_by( 'email', $email2 ) );
$existing = Registration::register( 'Ada', $email1, 'Ada', 'secfive' . $suffix );
$check( '... and so is an email that has an account, with the same words: the pause tells nothing about who is a member', is_wp_error( $existing ) && 'mody_rate_limited' === $existing->get_error_code() && str_contains( $existing->get_error_message(), 'Many people are signing up' ), is_wp_error( $existing ) ? $existing->get_error_message() : 'answered' );
$check( '... and neither counted as an account', 30 === $hits( 'signup_network', '' ), (string) $hits( 'signup_network', '' ) );
RateLimit::clear();
RateLimit::hit( 'signup_email', Registration::normalize_email( "dot.x{$suffix}+1@gmail.com" ) );
add_filter(
	'mody_rate_limits',
	static function ( array $l ): array {
		$l['signup_email'] = array( 1, DAY_IN_SECONDS );
		return $l;
	}
);
$variant = Registration::register( 'Dot X', "dotx{$suffix}+2@gmail.com", 'Dot X', 'secsix' . $suffix );
$check( 'the same mailbox written another way (+tag, dots) shares one limit', is_wp_error( $variant ) && 'mody_rate_limited' === $variant->get_error_code() && ! get_user_by( 'email', "dotx{$suffix}+2@gmail.com" ) );
remove_all_filters( 'mody_rate_limits' );
RateLimit::clear();

// The same answer in the same time for a new email and one that has an account: the work that differs runs after the response.
RateLimit::clear();
add_filter( 'mody_registration_defer', '__return_true' ); // What a web request does with PHP-FPM; WP-CLI has no fastcgi_finish_request().
$_SERVER['REMOTE_ADDR'] = '198.51.100.231';
$email3                 = "secdefer{$suffix}@mody.local";
$slug3                  = "secdefer{$suffix}";
$email_e                = "secexist{$suffix}@mody.local";
$new_user( 'secexist' . $suffix, $email_e );
$mails = array(); // After the user: core mails the network admin about each new user.
$later_new   = Registration::register( 'Linus Pauling', $email3, 'Pauling Labs', $slug3, true );
$later_exist = Registration::register( 'Mallory', $email_e, 'Pauling Labs', 'secdeferb' . $suffix, true );
$same_shape  = is_array( $later_new ) && is_array( $later_exist ) && array_keys( $later_new ) === array_keys( $later_exist );
$check( 'deferred: a new email and an existing one get the same answer in the same shape', $same_shape && 0 === $later_new['user_id'] && 0 === $later_exist['user_id'] && '' === $later_new['url'] && '' === $later_exist['url'] && 0 === $later_new['site_id'] && 0 === $later_exist['site_id'] && $email3 === $later_new['email'] && Pipeline::domain( $slug3 ) === $later_new['address'], wp_json_encode( array( $later_new, $later_exist ) ) );
$check( '... and before the response nothing is made and nothing is sent', ! get_user_by( 'email', $email3 ) && ! get_blog_id_from_url( Pipeline::domain( $slug3 ) ) && ! $mails );
Registration::run_later();
$user3     = get_user_by( 'email', $email3 );
$created[] = $user3 ? $user3->ID : 0;
$welcome3  = array_values( array_filter( $mails, static fn( array $m ): bool => $email3 === $m['to'] ) );
$note3     = array_values( array_filter( $mails, static fn( array $m ): bool => $email_e === $m['to'] ) );
$check( '... after it: the account, its trial and its site, and the welcome email', $user3 && Members::get( $user3->ID ) && get_blog_id_from_url( Pipeline::domain( $slug3 ) ) && 1 === count( $welcome3 ) );
$check( '... and the existing member got the note, and nothing was created for them', 1 === count( $note3 ) && str_contains( (string) $note3[0]['subject'], 'already have an account' ) && ! get_blog_id_from_url( Pipeline::domain( 'secdeferb' . $suffix ) ) );
$count_before = count( $mails );
Registration::run_later();
$check( '... and the queue is empty after: running it again does nothing', count( $mails ) === $count_before );
remove_filter( 'mody_registration_defer', '__return_true' );
$_SERVER['REMOTE_ADDR'] = $cli_address ?? '127.0.0.1';
RateLimit::clear();

// Over HTTP, through PHP-FPM: the response of a new email comes back long before a real signup is done, about as fast as an existing email's.
// Three of each, one after the other, from different addresses (puzzles solved before the clock starts).
$timed = static function ( string $email, string $slug, string $address ) use ( $rest, $nonce, $proof ): array {
	$altcha  = $proof();
	$started = microtime( true );
	$out     = $rest( 'register', array( 'name' => 'Marie Curie', 'email' => $email, 'title' => 'Curie Radium', 'slug' => $slug, 'nonce' => $nonce, 'altcha' => $altcha ), $address );
	return array( $out, microtime( true ) - $started );
};
$activation = static fn( string $email ): string => (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_activation_key FROM {$wpdb->users} WHERE user_email = %s", $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$t_new      = array();
$t_exist    = array();
$pending    = 0; // New signups whose work was still going on when their answer arrived.
$alike      = true;
$emails_f   = array();
foreach ( range( 1, 3 ) as $i ) {
	list( $out_exist, $t_exist[] ) = $timed( $email1, 'secfastb' . $i . $suffix, '198.51.100.' . ( 233 + $i ) );
	$emails_f[ $i ]                = "secfast{$i}{$suffix}@mody.local";
	list( $out_new, $t_new[] )     = $timed( $emails_f[ $i ], 'secfast' . $i . $suffix, '198.51.100.' . ( 237 + $i ) );
	$pending                      += '' === $activation( $emails_f[ $i ] ) ? 1 : 0;
	$alike                         = $alike && 201 === $out_new['code'] && 201 === $out_exist['code'] && array_keys( (array) $out_new['json'] ) === array_keys( (array) $out_exist['json'] );
}
sort( $t_new );
sort( $t_exist );
$check( 'over HTTP: a new email and an existing one both answer 201, with the same fields (three of each)', $alike );
$check( '... the new email\'s answer comes back before its account is finished (2 of 3: the work follows the response)', $pending >= 2, $pending . ' of 3 still being made' );
$check( '... in about the same time as the existing email\'s (the middle of three, within a signup\'s length)', $t_new[1] - $t_exist[1] < max( $t_sync, 0.5 ), sprintf( 'new %.2fs, existing %.2fs, a whole signup in-process %.2fs', $t_new[1], $t_exist[1], $t_sync ) );
$done = true;
foreach ( $emails_f as $i => $email_f ) {
	$done      = $settle( $email_f ) && $done;
	$user_f    = get_user_by( 'email', $email_f );
	$created[] = $user_f ? $user_f->ID : 0;
	$done      = $done && $user_f && Members::get( $user_f->ID ) && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT blog_id FROM {$wpdb->blogs} WHERE domain = %s", Pipeline::domain( 'secfast' . $i . $suffix ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
$check( '... and the three accounts, their sites and their welcome emails do follow, after the response', $done );

// Over HTTP: the chat's routes, with the address from the header.
foreach ( range( 1, 30 ) as $i ) {
	RateLimit::hit( 'signup_check', '198.51.100.210' );
}
$out = $rest( 'register/check', array( 'field' => 'name', 'value' => 'Ada', 'nonce' => $nonce ), '198.51.100.210' );
$check( 'the chat\'s check: 30 per ten minutes per address, then 429', 429 === $out['code'] && 'mody_rate_limited' === ( $out['json']['code'] ?? '' ) && str_contains( (string) ( $out['json']['message'] ?? '' ), 'try again in' ), (string) $out['code'] );
$out = $rest( 'register/check', array( 'field' => 'name', 'value' => 'Ada', 'nonce' => $nonce ), '198.51.100.211' );
$check( '... another address is fine', 200 === $out['code'] && true === ( $out['json']['ok'] ?? null ), (string) $out['code'] );
$out = $rest( 'register/check', array( 'field' => 'email', 'value' => $email1, 'nonce' => $nonce ), '198.51.100.212' );
$free = $rest( 'register/check', array( 'field' => 'email', 'value' => "free{$suffix}@mody.local", 'nonce' => $nonce ), '198.51.100.212' );
$check( 'an email with an account and one without get the same answer', 200 === $out['code'] && $out['json'] === array_merge( $free['json'], array( 'value' => $email1 ) ) && true === ( $out['json']['ok'] ?? null ) && ! isset( $out['json']['exists'] ), wp_json_encode( $out['json'] ) );
$out = $rest( 'register/check', array( 'field' => 'name', 'value' => 'Call 600 000 000 or visit https://bank.example', 'nonce' => $nonce ), '198.51.100.213' );
$check( 'a name with a link is refused by the check', true !== ( $out['json']['ok'] ?? null ) && str_contains( (string) ( $out['json']['message'] ?? '' ), 'web addresses' ) );
$out = $rest( 'register/check', array( 'field' => 'title', 'value' => str_repeat( 'x', Registration::MAX_TITLE + 1 ), 'nonce' => $nonce ), '198.51.100.213' );
$check( '... and a project name over 60 characters', true !== ( $out['json']['ok'] ?? null ) );
$out = $rest(
	'register',
	array(
		'name'   => 'Visit https://bank.example/login now',
		'email'  => "victim2{$suffix}@mody.local",
		'title'  => 'Bakery',
		'slug'   => 'secvict2' . $suffix,
		'nonce'  => $nonce,
		'altcha' => $proof(),
	),
	'198.51.100.215'
);
$check( 'a signup whose name holds a link: 400 with the field, nothing created', 400 === $out['code'] && isset( $out['json']['data']['fields']['name'] ) && ! get_user_by( 'email', "victim2{$suffix}@mody.local" ), (string) $out['code'] );

// 6. Log in, lost password, comments --------------------------------------------------------

echo "\nLog in, lost password and comments\n";
$uid_b  = $new_user( 'seclock' . $suffix );
$user_b = get_userdata( $uid_b );
$x      = '198.51.100.20';
$y      = '198.51.100.21';
$subject = static function ( int $id ): string { // What FormGuard files an account's failures under (private: the test asks it).
	clean_user_cache( $id );
	$account = get_userdata( $id );
	return (string) ( new ReflectionMethod( FormGuard::class, 'login_subject' ) )->invoke( null, $account->user_login, $account );
};
$text = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$out    = $login( $user_b->user_login, 'wrong password ' . $i, $proof(), $x );
	$text[] = str_contains( $out['body'], 'incorrect' ) ? 'incorrect' : ( str_contains( $out['body'], 'Too many failed' ) ? 'locked' : '?' );
}
$check( 'five wrong passwords for one account from one address, each with a solved puzzle: ordinary errors, the fifth included', array_fill( 0, 5, 'incorrect' ) === $text, implode( ',', array_unique( $text ) ) );
$out = $login( $user_b->user_login, $password, $proof(), $x );
$check( 'the sixth, with the right password: refused kindly, not logged in', ! $logged_in( $out ) && str_contains( $out['body'], 'Too many failed attempts for this account' ) && str_contains( $out['body'], 'try again in' ) && str_contains( $out['body'], 'to choose a new password' ), (string) $out['code'] );
$out = $login( $user_b->user_login, $password, $proof(), $y );
$check( '... but from another address the owner logs in: nobody locks an account out for everyone from one address', $logged_in( $out ), (string) $out['code'] );
$known_b = array_filter( $out['cookies'], static fn( string $name ): bool => str_starts_with( $name, 'mody_device_' ), ARRAY_FILTER_USE_KEY );
$check( '... and that login marks the browser, with a cookie for this account only', 1 === count( $known_b ) && str_ends_with( (string) array_key_first( $known_b ), '_' . $uid_b ), implode( ',', array_keys( $known_b ) ) );
$out = $login( 'secsalt' . $suffix, $password, $proof(), $x );
$check( '... another account from the first address is not paused by it', $logged_in( $out ), (string) $out['code'] );
$spare = $proof();
$out   = $login( $user_b->user_login, $password, $spare, $x );
$check( 'a refused attempt does not use up its solution: it still works from another address', str_contains( $out['body'], 'Too many failed attempts' ), (string) $out['code'] );
$out = $login( 'secsalt' . $suffix, $password, $spare, '198.51.100.24' );
$check( '... (the same puzzle, solved once)', $logged_in( $out ), (string) $out['code'] );

// Ten failures from one address, whichever accounts: the address is paused, and its message promises nothing a reset cannot do.
$z = '198.51.100.25';
for ( $i = 1; $i <= 10; $i++ ) {
	$login( 'ghost' . $i . $suffix, 'x', $proof(), $z );
}
$out = $login( 'secsalt' . $suffix, $password, $proof(), $z );
$check( 'ten failures from one address: it is paused for every account, with the right password too', ! $logged_in( $out ) && str_contains( $out['body'], 'Too many failed attempts from this connection' ) && str_contains( $out['body'], 'try again in' ), (string) $out['code'] );
$check( '... and that message does not suggest a new password would help (it would not)', ! str_contains( $out['body'], 'to choose a new password' ) );
$out = $login( 'secsalt' . $suffix, $password, $proof(), '198.51.100.22' );
$check( '... while another address logs in', $logged_in( $out ), (string) $out['code'] );

// A good login clears the account's count at that address.
$uid_e  = $new_user( 'secclear' . $suffix );
$user_e = get_userdata( $uid_e );
$w      = '198.51.100.26';
for ( $i = 1; $i <= 3; $i++ ) {
	$login( $user_e->user_login, 'wrong ' . $i, $proof(), $w );
}
$key_e  = $subject( $uid_e ) . '|' . $w;
$before = $hits( 'login_pair', $key_e );
$out    = $login( $user_e->user_login, $password, $proof(), $w );
$check( 'three wrong passwords count for the account at that address; a good login clears them', 3 === $before && $logged_in( $out ) && 0 === $hits( 'login_pair', $key_e ), $before . ' then ' . $hits( 'login_pair', $key_e ) );
$check( '... and the account-wide count keeps them (a stranger\'s guesses stay counted)', 3 === $hits( 'login_account', $subject( $uid_e ) ), (string) $hits( 'login_account', $subject( $uid_e ) ) );

// The last resort: 100 failures in 15 minutes from anywhere. It stops a browser the account has not seen, never one it has.
$uid_d  = $new_user( 'secceil' . $suffix );
$user_d = get_userdata( $uid_d );
$out    = $login( $user_d->user_login, $password, $proof(), '198.51.100.40' );
$known  = array_filter( $out['cookies'], static fn( string $name ): bool => str_starts_with( $name, 'mody_device_' ), ARRAY_FILTER_USE_KEY );
for ( $i = 0; $i < 100; $i++ ) {
	RateLimit::hit( 'login_account', $subject( $uid_d ) );
}
$out = $login( $user_d->user_login, $password, $proof(), '198.51.100.41' );
$check( 'the account-wide ceiling (100 failures in 15 minutes, from anywhere): a browser the account has not seen is refused, the right password too', ! $logged_in( $out ) && str_contains( $out['body'], 'Too many failed attempts for this account' ) && str_contains( $out['body'], 'to choose a new password' ), (string) $out['code'] );
$out = $login( $user_d->user_login, $password, $proof(), '198.51.100.42', '', $known );
$check( '... a browser the account has logged in from still gets in: a stranger cannot lock the owner out', $logged_in( $out ), (string) $out['code'] );
$out = $login( $user_d->user_login, $password, $proof(), '198.51.100.43', '', array( (string) array_key_first( $known ) => 'forged' ) );
$check( '... a forged cookie does not count', ! $logged_in( $out ), (string) $out['code'] );
wp_set_password( $password, $uid_d ); // The same words, a new hash: what a reset does.
$out = $login( $user_d->user_login, $password, $proof(), '198.51.100.44' );
$check( 'choosing a new password starts the account\'s counts over (what "or use Lost your password" promises): a new browser logs in', $logged_in( $out ), (string) $out['code'] );
for ( $i = 0; $i < 100; $i++ ) {
	RateLimit::hit( 'login_account', $subject( $uid_d ) );
}
$out = $login( $user_d->user_login, $password, $proof(), '198.51.100.45', '', $known );
$check( '... and the browsers marked before it are void: the new password is what counts', ! $logged_in( $out ), (string) $out['code'] );

$uid_c  = $new_user( 'secnolock' . $suffix );
$user_c = get_userdata( $uid_c );
$locked = 0;
for ( $i = 1; $i <= 10; $i++ ) {
	$out     = $login( $user_c->user_login, 'wrong ' . $i, '', '198.51.100.' . ( 30 + $i ) );
	$locked += str_contains( $out['body'], $failed ) ? 0 : 1;
}
$check( 'ten tries without a solution, from ten addresses: only the antibot message', 0 === $locked, $locked . ' other' );
$out = $login( $user_c->user_login, $password, $proof(), '198.51.100.50' );
$check( '... and they did not lock the account: the owner logs in', $logged_in( $out ), (string) $out['code'] );

$lost  = network_site_url( 'wp-login.php?action=lostpassword' );
$post  = static fn( string $name, string $altcha, string $address ) => $http( 'POST', $lost, array(), array( 'user_login' => $name, 'altcha' => $altcha ), $from( $address ) );
$out   = $post( "nobody{$suffix}@example.org", '', '198.51.100.60' );
$check( 'lost password without a solution for an address with no account: only the antibot message', str_contains( $out['body'], $failed ) && ! str_contains( $out['body'], 'no account' ), (string) $out['code'] );
$out   = $post( $user_c->user_email, '', '198.51.100.61' );
$check( '... and the same for one that has an account', str_contains( $out['body'], $failed ) && ! str_contains( $out['body'], 'no account' ) );
$seen = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$out    = $post( "nobody{$i}{$suffix}@example.org", $proof(), '198.51.100.62' );
	$seen[] = str_contains( $out['body'], 'no account' ) ? 'asked' : '?';
}
$out = $post( "nobody6{$suffix}@example.org", $proof(), '198.51.100.62' );
$check( 'lost password: five requests an hour per address', array_fill( 0, 5, 'asked' ) === $seen, implode( ',', $seen ) );
$check( '... the sixth is refused kindly', str_contains( $out['body'], 'few too many password requests' ) && ! str_contains( $out['body'], 'no account' ), (string) $out['code'] );
$out = $post( "nobody7{$suffix}@example.org", $proof(), '198.51.100.63' );
$check( '... and another address is not affected', str_contains( $out['body'], 'no account' ) );

switch_to_blog( $alpha );
$post_id   = (int) wp_insert_post(
	array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'post_title'     => 'Security test ' . $suffix,
		'post_content'   => '<!-- wp:paragraph --><p>Comments welcome.</p><!-- /wp:paragraph -->',
		'comment_status' => 'open',
	)
);
Antispam_Bee::get_options();
$field = Antispam_Bee::get_secret_name_for_post( $post_id );
restore_current_blog();
$comment = static fn( string $address, string $text ) => $http(
	'POST',
	get_home_url( $alpha, '/wp-comments-post.php' ),
	array(),
	array(
		'comment_post_ID' => $post_id,
		'author'          => 'Visitor ' . $suffix,
		'email'           => 'visitor' . $suffix . '@example.org',
		$field            => $text,
		'comment'         => '',
		'altcha'          => $proof(),
	),
	$from( $address )
);
$count = static function () use ( $alpha, &$post_id, $wpdb ): int {
	switch_to_blog( $alpha );
	$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d", $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	restore_current_blog();
	return $n;
};
$one = $comment( '198.51.100.70', 'A first comment ' . $suffix );
$check( 'comments: the first from an address is saved and counted', 302 === $one['code'] && 1 === $count() && 1 === $hits( 'comment', '198.51.100.70' ), $one['code'] . ' ' . $count() . ' ' . $hits( 'comment', '198.51.100.70' ) );
for ( $i = 0; $i < 9; $i++ ) {
	RateLimit::hit( 'comment', '198.51.100.70' );
}
$two = $comment( '198.51.100.70', 'A second comment, different words ' . $suffix );
$check( '... past ten an hour per address: 429, kind, not saved', 429 === $two['code'] && str_contains( $two['body'], 'commenting a little fast' ) && 1 === $count(), (string) $two['code'] );
sleep( 16 ); // Core's own flood check: one comment per 15 seconds from this container.
$three = $comment( '198.51.100.71', 'A comment from someone else ' . $suffix );
$check( '... another address comments', 302 === $three['code'] && 2 === $count(), (string) $three['code'] );

// 7. XML-RPC --------------------------------------------------------------------------------

echo "\nXML-RPC\n";
$check( 'xmlrpc_enabled is false and no method is left', false === apply_filters( 'xmlrpc_enabled', true ) && array() === apply_filters( 'xmlrpc_methods', array( 'a' => 'b' ) ) );
require_once ABSPATH . WPINC . '/class-IXR.php';
require_once ABSPATH . WPINC . '/class-wp-xmlrpc-server.php';
$result = ( new wp_xmlrpc_server() )->wp_getUsersBlogs( array( 'secsalt' . $suffix, $password ) );
$check( 'the XML-RPC server itself refuses (405) before it looks at the password', $result instanceof IXR_Error && 405 === $result->code, $result instanceof IXR_Error ? (string) $result->code : 'answered' );
$xml = static fn( string $method, string $params = '' ) => $http( 'POST', $home . 'xmlrpc.php', array(), '<?xml version="1.0"?><methodCall><methodName>' . $method . '</methodName><params>' . $params . '</params></methodCall>', array( 'Content-Type' => 'text/xml' ) + $from( '198.51.100.80' ) );
$out = $xml( 'wp.getUsersBlogs', '<param><value><string>secsalt' . $suffix . '</string></value></param><param><value><string>' . $password . '</string></value></param>' );
$check( 'wp.getUsersBlogs with the right password is refused', ( 404 === $out['code'] || str_contains( $out['body'], 'faultCode' ) ) && ! str_contains( $out['body'], 'blogName' ), (string) $out['code'] );
$out = $xml( 'pingback.ping', '<param><value><string>https://example.org/a</string></value></param><param><value><string>' . get_home_url( $alpha, '/' ) . '</string></value></param>' );
$check( 'pingback.ping is refused', 404 === $out['code'] || str_contains( $out['body'], 'faultCode' ), (string) $out['code'] );
$out = $http( 'GET', get_home_url( $alpha, '/' ) );
$check( 'pages carry no RSD or Windows Live Writer link', 200 === $out['code'] && ! str_contains( $out['body'], 'xmlrpc.php' ) && ! str_contains( $out['body'], 'wlwmanifest' ), (string) $out['code'] );
$headers = apply_filters( 'wp_headers', array( 'X-Pingback' => 'https://example.org/xmlrpc.php', 'Content-Type' => 'text/html' ) );
$check( '... and no X-Pingback header, on a post that allows pings either', array( 'Content-Type' => 'text/html' ) === $headers && ! isset( $http( 'GET', get_home_url( $alpha, '/?p=' . $post_id ) )['headers']['x-pingback'] ) );

// 8. Public lists of users --------------------------------------------------------------------

echo "\nPublic lists of users\n";
add_user_to_blog( $alpha, $uid_b, 'administrator' );
add_user_to_blog( $alpha, $uid_c, 'editor' );
update_user_meta( $uid_c, Onboarding::META, Onboarding::STAGE_PASSWORD );
$out = $http( 'GET', get_home_url( $alpha, '/wp-json/wp/v2/users' ) );
$check( 'the users list is refused to visitors (401)', 401 === $out['code'] && ! str_contains( $out['body'], '"slug"' ), (string) $out['code'] );
$out = $http( 'GET', get_home_url( $alpha, '/wp-json/wp/v2/users/' . $uid_b ) );
$check( '... and so is one user', 401 === $out['code'], (string) $out['code'] );
$variants = array(
	'a capital in the namespace'         => '/wp-json/WP/v2/users',
	'a capital in the route'             => '/wp-json/wp/v2/Users',
	'a capital in the route, one user'   => '/wp-json/wp/v2/Users/' . $uid_b,
	'all capitals, one user'             => '/wp-json/wp/v2/USERS/' . $uid_b,
	'?rest_route=, lower case'           => '/?rest_route=/wp/v2/users',
	'?rest_route=, one user'             => '/?rest_route=/wp/v2/users/' . $uid_b,
	'?rest_route=, capitals'             => '/?rest_route=/WP/V2/Users',
	'?rest_route=, capitals, one user'   => '/?rest_route=/wp/v2/USERS/' . $uid_b,
	'with a search'                      => '/wp-json/wp/v2/users?search=sec',
	'the current user\'s route'          => '/wp-json/wp/v2/users/me',
);
$leaks = array();
foreach ( $variants as $what => $path ) {
	$out = $http( 'GET', get_home_url( $alpha, $path ) );
	if ( 401 !== $out['code'] || str_contains( $out['body'], '"slug"' ) || str_contains( $out['body'], 'avatar_urls' ) ) {
		$leaks[] = $what . ' (' . $out['code'] . ')';
	}
}
$check( 'every way to write the users\' address is refused to visitors (401, no user data)', ! $leaks, $leaks ? implode( ', ', $leaks ) : count( $variants ) . ' forms' );
$out    = $http( 'GET', get_home_url( $alpha, '/wp-json/' ) );
$listed = array_keys( (array) ( $out['json']['routes'] ?? array() ) );
$check( 'the REST index does not list the users\' routes to visitors', 200 === $out['code'] && count( $listed ) > 20 && ! array_filter( $listed, array( Hardening::class, 'is_users_route' ) ), $out['code'] . ', ' . count( $listed ) . ' routes' );
wp_set_current_user( 0 );
$visitor_routes = array_filter( array_keys( rest_get_server()->get_routes() ), array( Hardening::class, 'is_users_route' ) );
wp_set_current_user( $uid_b );
$member_routes = array_filter( array_keys( rest_get_server()->get_routes() ), array( Hardening::class, 'is_users_route' ) );
wp_set_current_user( 0 );
$check( 'for a visitor the users\' routes are not registered at all; a logged-in user keeps them', ! $visitor_routes && count( $member_routes ) >= 3, count( $visitor_routes ) . ' and ' . count( $member_routes ) );
remove_filter( 'rest_pre_dispatch', array( Hardening::class, 'refuse_users' ), 10 );
$bare = array();
foreach ( array( '/wp/v2/users', '/WP/v2/Users', '/wp/v2/USERS/' . $uid_b, '/wp/v2/users/me' ) as $route ) {
	$bare[] = rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status();
}
add_filter( 'rest_pre_dispatch', array( Hardening::class, 'refuse_users' ), 10, 3 );
$check( 'the routes alone are enough: with the 401 guard off, every form finds no route (404), never data', array( 404, 404, 404, 404 ) === $bare, implode( ',', $bare ) );
$guard = array();
foreach ( array( '/wp/v2/users', '/WP/v2/Users', '/wp/v2/USERS/' . $uid_b ) as $route ) {
	$guard[] = Hardening::refuse_users( null, null, new WP_REST_Request( 'GET', $route ) ) instanceof WP_Error ? 401 : 0;
}
$check( '... and the guard alone catches every case too (it is case-insensitive)', array( 401, 401, 401 ) === $guard && null === Hardening::refuse_users( null, null, new WP_REST_Request( 'GET', '/wp/v2/usersx' ) ) && null === Hardening::refuse_users( null, null, new WP_REST_Request( 'GET', '/wp/v2/posts' ) ), implode( ',', $guard ) );
$out = $http( 'GET', get_home_url( $alpha, '/?author=' . $uid_b ) );
$check( '?author=N sends visitors to the home page, not to the author\'s address', 301 === $out['code'] && get_home_url( $alpha, '/' ) === $out['location'], $out['code'] . ' ' . $out['location'] );
$out = $http( 'GET', get_home_url( $alpha, '/wp-sitemap-users-1.xml' ) );
$check( 'there is no sitemap of users', 404 === $out['code'] || 301 === $out['code'] || 302 === $out['code'], (string) $out['code'] );
$out = $http( 'GET', get_home_url( $alpha, '/wp-sitemap.xml' ) );
$check( '... and the sitemap index does not list one', ! str_contains( $out['body'], 'wp-sitemap-users' ), (string) $out['code'] );
switch_to_blog( $alpha );
$oembed = apply_filters( 'oembed_response_data', array( 'title' => 'T', 'author_name' => 'Ada', 'author_url' => 'https://example.org/author/ada/' ), get_post( $post_id ), 600, 400 );
restore_current_blog();
$check( 'oEmbed gives no author name or address', 'T' === ( $oembed['title'] ?? '' ) && ! isset( $oembed['author_name'] ) && ! isset( $oembed['author_url'] ) );
$read = static function ( int $viewer, int $target ) use ( $alpha ): array {
	switch_to_blog( $alpha );
	wp_set_current_user( $viewer );
	$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users/' . $target ) );
	wp_set_current_user( 0 );
	restore_current_blog();
	return array(
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	);
};
$admins = get_super_admins();
$super  = get_user_by( 'login', $admins[0] );
$by_admin = $read( $super->ID, $uid_c );
$by_self  = $read( $uid_c, $uid_c );
$by_other = $read( $uid_b, $uid_c ); // An administrator of the site: allowed to read, not to see the stage.
$visitor  = $read( 0, $uid_c );
$check( 'a logged-in administrator still reads users (the editors need it)', 200 === $by_admin['status'] && $uid_c === ( $by_admin['data']['id'] ?? 0 ), (string) $by_admin['status'] );
$check( 'another administrator reads the user too', 200 === $by_other['status'], (string) $by_other['status'] );
$check( 'the onboarding stage is in the user\'s own data only', Onboarding::STAGE_PASSWORD === ( $by_self['data']['meta'][ Onboarding::META ] ?? null ) && ! isset( $by_admin['data']['meta'][ Onboarding::META ] ) && ! isset( $by_other['data']['meta'][ Onboarding::META ] ), wp_json_encode( $by_admin['data']['meta'] ?? null ) );
$check( 'a visitor (not logged in) gets 401 in-process too', 401 === $visitor['status'], (string) $visitor['status'] );

// 9. Passwords ----------------------------------------------------------------------------------

echo "\nPasswords\n";
$check( 'password_problem(): fewer than ten characters, or the login or email', '' !== Hardening::password_problem( 'short1' ) && '' === Hardening::password_problem( 'ten chars!!' ) && '' !== Hardening::password_problem( 'seclock' . $suffix . 'x', 'seclock' . $suffix . 'x' ) && '' !== Hardening::password_problem( 'a@example.org1', '', 'A@example.org1' ) );
$errors = new WP_Error();
Hardening::check_profile_password( $errors, true, (object) array( 'user_pass' => 'abc' ) );
$check( 'a profile\'s new password is checked, an empty one (no change) is not', $errors->has_errors() && 'mody_password_weak' === $errors->get_error_code() );
$errors = new WP_Error();
Hardening::check_profile_password( $errors, true, (object) array( 'user_pass' => '' ) );
$check( '... an empty one means no change', ! $errors->has_errors() );
$key = get_password_reset_key( get_userdata( $uid_b ) );
$rp  = static fn( string $pass ) => $http(
	'POST',
	network_site_url( 'wp-login.php?action=resetpass', 'login_post' ),
	array( 'wp-resetpass-' . COOKIEHASH => $user_b->user_login . ':' . $key ),
	array(
		'pass1'     => $pass,
		'pass2'     => $pass,
		'rp_key'    => $key,
		'wp-submit' => 'Save Password',
	),
	$from( '198.51.100.90' )
);
$out = $rp( 'short1' );
$check( 'the reset screen refuses a short password, and says why', str_contains( $out['body'], 'at least 10 characters' ) && ! str_contains( $out['body'], 'Your password has been reset' ), (string) $out['code'] );
wp_cache_delete( $uid_b, 'users' );
$check( '... the password did not change', wp_check_password( $password, get_userdata( $uid_b )->user_pass, $uid_b ) );
$out = $rp( 'three words in a row' );
$check( '... and a longer one is accepted', str_contains( $out['body'], 'Your password has been reset' ), (string) $out['code'] );
$out = $login( $user_b->user_login, 'three words in a row', $proof(), $x );
$check( 'after the real reset, the account that was paused at an address (section 6) logs in there: the refusal\'s advice is true', $logged_in( $out ), (string) $out['code'] );

// 10. The analytics beacon ------------------------------------------------------------------------

echo "\nAnalytics\n";
$path   = '/sectest-' . $suffix . '/';
$views  = static fn( string $p ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Store::views() . ' WHERE site_id = %d AND path = %s', $alpha, $p ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$beacon = static fn( string $address, string $p ) => $http( 'POST', get_home_url( $alpha, '/wp-json/mody-analytics/v1/view' ), array(), wp_json_encode( array( 'p' => $p, 'r' => '', 'w' => 1440 ) ), $from( $address ) + array( 'Content-Type' => 'text/plain' ) );
$out    = $beacon( '203.0.113.90', $path );
$check( 'the beacon counts a view from an address', 204 === $out['code'] && 1 === $views( $path ), $out['code'] . ' ' . $views( $path ) );
foreach ( range( 1, 120 ) as $i ) {
	RateLimit::hit( 'beacon', '203.0.113.91' );
}
$out = $beacon( '203.0.113.91', $path );
$check( '... past 120 a minute from one address it counts nothing, and still answers 204', 204 === $out['code'] && 1 === $views( $path ), $out['code'] . ' ' . $views( $path ) );
$out = $beacon( '203.0.113.92', $path );
$check( '... another address counts', 2 === $views( $path ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.93';
$accepted               = 0;
for ( $i = 0; $i < 125; $i++ ) {
	$accepted += Hardening::accept_view( true ) ? 1 : 0;
}
$_SERVER['REMOTE_ADDR'] = $cli_address ?? '127.0.0.1';
$check( 'the platform\'s filter accepts 120 views a minute per address', 120 === $accepted, (string) $accepted );

$ua = 'Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/131.0';
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Store::values() . ' WHERE site_id = %d AND day = %s', $alpha, wp_date( 'Y-m-d' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- A clean count of today's distinct values.
$mark = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Store::views() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
add_filter( 'mody_analytics_max_distinct', static fn(): int => 3 );
switch_to_blog( $alpha );
foreach ( range( 1, 5 ) as $i ) {
	Collector::count( '/sectest-cap-' . $suffix . '-' . $i . '/', 'https://source' . $i . '.example/page', 1440, '203.0.113.' . ( 100 + $i ), $ua . $i );
}
Collector::count( '/sectest-cap-' . $suffix . '-1/', 'https://source1.example/', 1440, '203.0.113.110', $ua . 'x' );
Collector::count( '/sectest-cap-' . $suffix . '-9/', '', 1440, '203.0.113.111', $ua . 'y' );
restore_current_blog();
remove_all_filters( 'mody_analytics_max_distinct' );
$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT path, source FROM ' . Store::views() . ' WHERE site_id = %d AND id > %d ORDER BY id', $alpha, $mark ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$paths   = array_column( $rows, 'path' );
$sources = array_column( $rows, 'source' );
$p       = '/sectest-cap-' . $suffix . '-';
$check( 'a site counts at most N different pages a day; the rest are "other"', array( $p . '1/', $p . '2/', $p . '3/', Collector::OTHER, Collector::OTHER ) === array_slice( $paths, 0, 5 ), wp_json_encode( $paths ) );
$check( '... a page already counted today keeps its name; a new one past the cap is "other"', array( $p . '1/', Collector::OTHER ) === array_slice( $paths, 5, 2 ), wp_json_encode( $paths ) );
$check( '... the same for sources, and "no source" is not a source', array( 'source1.example', 'source2.example', 'source3.example', Collector::OTHER, Collector::OTHER, 'source1.example', '' ) === $sources, wp_json_encode( $sources ) );
$check( 'Store::admit(): known values pass, new ones until the cap', Store::admit( 987654321, '2026-01-01', 'p', '/a', 2 ) && Store::admit( 987654321, '2026-01-01', 'p', '/b', 2 ) && ! Store::admit( 987654321, '2026-01-01', 'p', '/c', 2 ) && Store::admit( 987654321, '2026-01-01', 'p', '/a', 2 ) && Store::admit( 987654321, '2026-01-01', 's', '/c', 2 ) && Store::admit( 987654321, '2026-01-02', 'p', '/c', 2 ) );
Store::forget_before( '2026-01-02' );
$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Store::values() . ' WHERE site_id = %d AND day < %s', 987654321, '2026-01-02' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$check( '... and the values of earlier days are forgotten with the visit map', 0 === $left, (string) $left );

remove_all_filters( 'pre_wp_mail' );
echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
