<?php
/**
 * Security keys and passkeys: WebAuthn's two ceremonies, checked on our
 * server (W3C Web Authentication, level 2).
 *
 * @package Mody\Security
 */

namespace Mody\Security;

use UnexpectedValueException;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * What this checks, in the specification's order:
 *
 * - **Registration** (§7.1): the client data is a `webauthn.create` for our
 *   challenge from an origin on the relying party; the authenticator data is
 *   for our relying party, with the user present and a credential attached;
 *   its public key is a COSE key we can use (ES256, RS256 or Ed25519).
 *   Attestation is `none`: which make of key it is does not matter here, so
 *   the attestation statement is not read.
 * - **Authentication** (§7.2): the client data is a `webauthn.get` for our
 *   challenge from an origin on the relying party; the authenticator data
 *   is for our relying party with the user present; the signature over the
 *   authenticator data and the client data's hash is the stored key's; the
 *   signature counter went up (when the key keeps one).
 *
 * The relying party is the network's domain (`mody.cloud`) on it and its
 * subdomains, so one key serves every `*.mody.cloud` site; on a member's own
 * domain it is that domain, where keys added on the network do not work
 * (the ticket's Q1).
 */
final class WebAuthn {

	/** COSE algorithms we accept, in the order offered. */
	public const ALGORITHMS = array( -7, -8, -257 );

	/** Authenticator data flags. */
	private const FLAG_UP = 0x01;
	private const FLAG_AT = 0x40;

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Raw bytes.
	 */
	public static function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64url (padding optional) to bytes; '' when it is not base64url.
	 *
	 * @param string $text Base64url.
	 */
	public static function from_b64url( string $text ): string {
		if ( '' === $text || ! preg_match( '/^[A-Za-z0-9_-]+={0,2}$/', $text ) ) {
			return '';
		}
		$bytes = base64_decode( strtr( rtrim( $text, '=' ), '-_', '+/' ), true );
		return false === $bytes ? '' : $bytes;
	}

	/**
	 * The host this request is for, lower case, without a port.
	 */
	public static function request_host(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		$host = (string) preg_replace( '/:\d+$/', '', $host );
		if ( '' === $host || ! preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		return $host;
	}

	/**
	 * The relying party for a host: the network's domain on it and its
	 * subdomains, else the host itself.
	 *
	 * @param string|null $host A host; this request's by default.
	 */
	public static function rp_id( ?string $host = null ): string {
		$host    = $host ?? self::request_host();
		$network = is_multisite() ? strtolower( (string) get_network()->domain ) : strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' !== $network && ( $host === $network || str_ends_with( $host, '.' . $network ) ) ) {
			return $network;
		}
		return $host;
	}

	/**
	 * Whether a credential made for one relying party works on a host.
	 *
	 * @param string      $rp   The credential's relying party.
	 * @param string|null $host A host; this request's by default.
	 */
	public static function works_on( string $rp, ?string $host = null ): bool {
		return '' !== $rp && self::rp_id( $host ) === $rp;
	}

	/**
	 * Whether an origin the browser reported belongs to a relying party:
	 * HTTPS, on its domain or a subdomain.
	 *
	 * @param string $origin Such as `https://shop.mody.cloud`.
	 * @param string $rp_id  The relying party.
	 */
	public static function origin_ok( string $origin, string $rp_id ): bool {
		$parts = wp_parse_url( $origin );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['path'] ) ) {
			return false;
		}
		$host = strtolower( $parts['host'] );
		return $host === $rp_id || str_ends_with( $host, '.' . $rp_id );
	}

	/**
	 * A user's handle for their keys: stable, not their ID, nothing personal.
	 *
	 * @param int $user_id The user.
	 */
	public static function user_handle( int $user_id ): string {
		return substr( hash_hmac( 'sha256', 'webauthn-user:' . $user_id, Secrets::get( 'two-factor' ), true ), 0, 32 );
	}

	/**
	 * `navigator.credentials.create()`'s options, binary values in base64url
	 * (the script turns them into buffers).
	 *
	 * @param \WP_User                        $user      Who adds a key.
	 * @param string                          $challenge The challenge, raw.
	 * @param string                          $rp_id     The relying party.
	 * @param array<int, array<string,mixed>> $existing  The user's keys, not offered again.
	 * @return array<string, mixed>
	 */
	public static function creation_options( \WP_User $user, string $challenge, string $rp_id, array $existing ): array {
		$exclude = array();
		foreach ( $existing as $key ) {
			if ( ( $key['rp'] ?? '' ) === $rp_id ) {
				$exclude[] = array(
					'type' => 'public-key',
					'id'   => $key['id'],
				);
			}
		}
		$network = is_multisite() ? get_network()->site_name : get_bloginfo( 'name' );
		return array(
			'challenge'              => self::b64url( $challenge ),
			'rp'                     => array(
				'id'   => $rp_id,
				'name' => wp_specialchars_decode( (string) $network, ENT_QUOTES ),
			),
			'user'                   => array(
				'id'          => self::b64url( self::user_handle( $user->ID ) ),
				'name'        => $user->user_email,
				'displayName' => $user->display_name,
			),
			'pubKeyCredParams'       => array_map(
				static fn( $alg ) => array(
					'type' => 'public-key',
					'alg'  => $alg,
				),
				self::ALGORITHMS
			),
			'timeout'                => 120000,
			'excludeCredentials'     => $exclude,
			'authenticatorSelection' => array(
				'residentKey'      => 'discouraged',
				'userVerification' => 'discouraged',
			),
			'attestation'            => 'none',
		);
	}

	/**
	 * `navigator.credentials.get()`'s options for a user's keys on a
	 * relying party.
	 *
	 * @param string                          $challenge The challenge, raw.
	 * @param string                          $rp_id     The relying party.
	 * @param array<int, array<string,mixed>> $keys      The user's keys.
	 * @return array<string, mixed>
	 */
	public static function request_options( string $challenge, string $rp_id, array $keys ): array {
		$allow = array();
		foreach ( $keys as $key ) {
			if ( ( $key['rp'] ?? '' ) === $rp_id ) {
				$allow[] = array(
					'type' => 'public-key',
					'id'   => $key['id'],
				);
			}
		}
		return array(
			'challenge'        => self::b64url( $challenge ),
			'rpId'             => $rp_id,
			'allowCredentials' => $allow,
			'timeout'          => 120000,
			'userVerification' => 'discouraged',
		);
	}

	/**
	 * Checks a new credential.
	 *
	 * @param string $client_data_json  The client data, raw JSON.
	 * @param string $attestation       The attestation object, raw CBOR.
	 * @param string $challenge         The challenge we gave, raw.
	 * @param string $rp_id             The relying party.
	 * @return array{id: string, key: string, alg: int, count: int, rp: string}|WP_Error
	 */
	public static function verify_registration( string $client_data_json, string $attestation, string $challenge, string $rp_id ) {
		$client = self::client_data( $client_data_json, 'webauthn.create', $challenge, $rp_id );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		try {
			$object = Cbor::decode( $attestation );
		} catch ( UnexpectedValueException $e ) {
			return self::error( __( 'The security key sent something unreadable.', 'mody_cloud' ) );
		}
		if ( ! is_array( $object ) || ! isset( $object['authData'] ) || ! is_string( $object['authData'] ) ) {
			return self::error( __( 'The security key sent something unreadable.', 'mody_cloud' ) );
		}
		$data = self::auth_data( $object['authData'], $rp_id, true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$key = self::cose_key( $data['cose'] );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		return array(
			'id'    => self::b64url( $data['credential_id'] ),
			'key'   => $key['key'],
			'alg'   => $key['alg'],
			'count' => $data['count'],
			'rp'    => $rp_id,
		);
	}

	/**
	 * Checks a signed login with a stored key.
	 *
	 * @param array<string, mixed> $credential         The stored key (verify_registration()'s answer, kept).
	 * @param string               $client_data_json   The client data, raw JSON.
	 * @param string               $authenticator_data The authenticator data, raw.
	 * @param string               $signature          The signature, raw.
	 * @param string               $challenge          The challenge we gave, raw.
	 * @param string               $rp_id              The relying party.
	 * @return int|WP_Error The new signature count.
	 */
	public static function verify_assertion( array $credential, string $client_data_json, string $authenticator_data, string $signature, string $challenge, string $rp_id ) {
		if ( ( $credential['rp'] ?? '' ) !== $rp_id ) {
			return self::error( __( 'This security key was added for another address.', 'mody_cloud' ) );
		}
		$client = self::client_data( $client_data_json, 'webauthn.get', $challenge, $rp_id );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$data = self::auth_data( $authenticator_data, $rp_id, false );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$signed = $authenticator_data . hash( 'sha256', $client_data_json, true );
		if ( ! self::signature_ok( (string) ( $credential['key'] ?? '' ), (int) ( $credential['alg'] ?? 0 ), $signed, $signature ) ) {
			return self::error( __( 'The security key\'s signature is not right.', 'mody_cloud' ) );
		}
		$stored = (int) ( $credential['count'] ?? 0 );
		if ( ( 0 !== $data['count'] || 0 !== $stored ) && $data['count'] <= $stored ) {
			return self::error( __( 'This security key\'s counter went back: it may have been copied.', 'mody_cloud' ) );
		}
		return $data['count'];
	}

	/**
	 * Checks the client data.
	 *
	 * @param string $json      The client data, raw JSON.
	 * @param string $type      `webauthn.create` or `webauthn.get`.
	 * @param string $challenge The challenge we gave, raw.
	 * @param string $rp_id     The relying party.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function client_data( string $json, string $type, string $challenge, string $rp_id ) {
		$client = json_decode( $json, true );
		if ( ! is_array( $client ) ) {
			return self::error( __( 'The browser sent something unreadable.', 'mody_cloud' ) );
		}
		if ( ( $client['type'] ?? '' ) !== $type ) {
			return self::error( __( 'The browser answered another question.', 'mody_cloud' ) );
		}
		if ( '' === $challenge || ! is_string( $client['challenge'] ?? null ) || ! hash_equals( $challenge, self::from_b64url( $client['challenge'] ) ) ) {
			return self::error( __( 'That answer is for another request. Please try again.', 'mody_cloud' ) );
		}
		if ( ! is_string( $client['origin'] ?? null ) || ! self::origin_ok( $client['origin'], $rp_id ) ) {
			return self::error( __( 'That answer came from another address.', 'mody_cloud' ) );
		}
		if ( ! empty( $client['crossOrigin'] ) ) {
			return self::error( __( 'That answer came from inside another site.', 'mody_cloud' ) );
		}
		return $client;
	}

	/**
	 * Reads and checks authenticator data.
	 *
	 * @param string $data            The bytes.
	 * @param string $rp_id           The relying party.
	 * @param bool   $with_credential Whether a credential must be attached (registration).
	 * @return array{flags: int, count: int, credential_id: string, cose: array<int|string, mixed>}|WP_Error
	 */
	private static function auth_data( string $data, string $rp_id, bool $with_credential ) {
		if ( strlen( $data ) < 37 ) {
			return self::error( __( 'The security key sent something unreadable.', 'mody_cloud' ) );
		}
		if ( ! hash_equals( hash( 'sha256', $rp_id, true ), substr( $data, 0, 32 ) ) ) {
			return self::error( __( 'This security key answered for another address.', 'mody_cloud' ) );
		}
		$flags = ord( $data[32] );
		if ( ! ( $flags & self::FLAG_UP ) ) {
			return self::error( __( 'The security key was not touched.', 'mody_cloud' ) );
		}
		$result = array(
			'flags'         => $flags,
			'count'         => unpack( 'N', substr( $data, 33, 4 ) )[1],
			'credential_id' => '',
			'cose'          => array(),
		);
		if ( ! $with_credential ) {
			return $result;
		}
		if ( ! ( $flags & self::FLAG_AT ) || strlen( $data ) < 55 ) {
			return self::error( __( 'The security key sent no key.', 'mody_cloud' ) );
		}
		$id_length = unpack( 'n', substr( $data, 53, 2 ) )[1];
		$offset    = 55 + $id_length;
		if ( $id_length < 16 || $id_length > 1023 || strlen( $data ) <= $offset ) {
			return self::error( __( 'The security key sent something unreadable.', 'mody_cloud' ) );
		}
		$result['credential_id'] = substr( $data, 55, $id_length );
		try {
			$cose = Cbor::decode( $data, $offset );
		} catch ( UnexpectedValueException $e ) {
			return self::error( __( 'The security key sent something unreadable.', 'mody_cloud' ) );
		}
		if ( ! is_array( $cose ) ) {
			return self::error( __( 'The security key sent something unreadable.', 'mody_cloud' ) );
		}
		$result['cose'] = $cose;
		return $result;
	}

	/**
	 * A COSE public key as something we can verify with: a PEM key for
	 * OpenSSL (ES256, RS256), or `ed25519:` and the raw key in base64 for
	 * sodium.
	 *
	 * @param array<int|string, mixed> $cose The COSE key.
	 * @return array{key: string, alg: int}|WP_Error
	 */
	public static function cose_key( array $cose ) {
		$kty = $cose[1] ?? null;
		$alg = $cose[3] ?? null;
		if ( 2 === $kty && -7 === $alg && 1 === ( $cose[-1] ?? null ) && is_string( $cose[-2] ?? null ) && is_string( $cose[-3] ?? null ) && 32 === strlen( $cose[-2] ) && 32 === strlen( $cose[-3] ) ) {
			$der = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . "\x04" . $cose[-2] . $cose[-3];
			return array(
				'key' => self::pem( $der ),
				'alg' => -7,
			);
		}
		if ( 3 === $kty && -257 === $alg && is_string( $cose[-1] ?? null ) && is_string( $cose[-2] ?? null ) && strlen( $cose[-1] ) >= 256 ) {
			$rsa = self::der( 0x30, self::der_integer( $cose[-1] ) . self::der_integer( $cose[-2] ) );
			$der = self::der( 0x30, hex2bin( '300d06092a864886f70d0101010500' ) . self::der( 0x03, "\x00" . $rsa ) );
			return array(
				'key' => self::pem( $der ),
				'alg' => -257,
			);
		}
		if ( 1 === $kty && -8 === $alg && 6 === ( $cose[-1] ?? null ) && is_string( $cose[-2] ?? null ) && 32 === strlen( $cose[-2] ) && function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return array(
				'key' => 'ed25519:' . base64_encode( $cose[-2] ),
				'alg' => -8,
			);
		}
		return self::error( __( 'This kind of security key is not supported.', 'mody_cloud' ) );
	}

	/**
	 * Whether a signature is the key's.
	 *
	 * @param string $key       From cose_key().
	 * @param int    $alg       The COSE algorithm.
	 * @param string $data      What was signed.
	 * @param string $signature The signature.
	 */
	public static function signature_ok( string $key, int $alg, string $data, string $signature ): bool {
		if ( -8 === $alg ) {
			$raw = str_starts_with( $key, 'ed25519:' ) ? base64_decode( substr( $key, 8 ), true ) : false;
			return is_string( $raw ) && 32 === strlen( $raw ) && 64 === strlen( $signature ) && function_exists( 'sodium_crypto_sign_verify_detached' )
				&& sodium_crypto_sign_verify_detached( $signature, $data, $raw );
		}
		if ( -7 !== $alg && -257 !== $alg ) {
			return false;
		}
		$public = openssl_pkey_get_public( $key );
		return false !== $public && 1 === openssl_verify( $data, $signature, $public, OPENSSL_ALGO_SHA256 );
	}

	/**
	 * DER: a tag, its length, its content.
	 *
	 * @param int    $tag     The tag.
	 * @param string $content The content.
	 */
	private static function der( int $tag, string $content ): string {
		$length = strlen( $content );
		if ( $length < 0x80 ) {
			return chr( $tag ) . chr( $length ) . $content;
		}
		$bytes = ltrim( pack( 'N', $length ), "\x00" );
		return chr( $tag ) . chr( 0x80 | strlen( $bytes ) ) . $bytes . $content;
	}

	/**
	 * A DER integer from unsigned big-endian bytes.
	 *
	 * @param string $bytes The number.
	 */
	private static function der_integer( string $bytes ): string {
		$bytes = ltrim( $bytes, "\x00" );
		if ( '' === $bytes || ord( $bytes[0] ) & 0x80 ) {
			$bytes = "\x00" . $bytes;
		}
		return self::der( 0x02, $bytes );
	}

	/**
	 * A public key in PEM.
	 *
	 * @param string $der SubjectPublicKeyInfo, DER.
	 */
	private static function pem( string $der ): string {
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	/**
	 * A refusal, in the user's words.
	 *
	 * @param string $message What went wrong, translated.
	 */
	private static function error( string $message ): WP_Error {
		return new WP_Error( 'mody_webauthn', $message );
	}
}
