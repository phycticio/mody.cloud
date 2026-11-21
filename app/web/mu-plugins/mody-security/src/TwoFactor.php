<?php
/**
 * Two-step login: what each account has set up (an authenticator app,
 * security keys, recovery codes), and who must have it.
 *
 * @package Mody\Security
 */

namespace Mody\Security;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Stored on the user (users are the network's, so one setup serves every
 * site):
 *
 * - `mody_two_factor`: the app's secret (encrypted with the `two-factor`
 *   secret of Secrets, so a copy of the users' table alone does not carry
 *   it), the last step used (each code works once), when it was added, and
 *   the recovery codes as keyed hashes.
 * - `mody_security_keys`: the security keys, each with its credential ID,
 *   public key, algorithm, counter, name, relying party, and when it was
 *   added and last used.
 * - `mody_two_factor_setup`: an app secret being set up, until confirmed
 *   with a code (an hour).
 *
 * The login screens are TwoFactorLogin's; the profile's, TwoFactorProfile's.
 */
final class TwoFactor {

	/** User meta: the app's secret and the recovery codes. */
	public const META = 'mody_two_factor';

	/** User meta: the security keys. */
	public const KEYS_META = 'mody_security_keys';

	/** User meta: an app secret not confirmed yet. */
	public const SETUP_META = 'mody_two_factor_setup';

	/** Recovery codes made at a time. */
	public const CODES = 10;

	/** Security keys an account may have. */
	public const MAX_KEYS = 10;

	/** The recovery codes' alphabet: no 0/o, 1/l/i. */
	private const CODE_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

	/**
	 * The account's stored state.
	 *
	 * @param int $user_id The user.
	 * @return array{totp: string, step: int, totp_added: int, codes: string[], codes_made: int}
	 */
	private static function state( int $user_id ): array {
		$state = get_user_meta( $user_id, self::META, true );
		$state = is_array( $state ) ? $state : array();
		return array(
			'totp'       => is_string( $state['totp'] ?? null ) ? $state['totp'] : '',
			'step'       => (int) ( $state['step'] ?? 0 ),
			'totp_added' => (int) ( $state['totp_added'] ?? 0 ),
			'codes'      => array_values( array_filter( (array) ( $state['codes'] ?? array() ), 'is_string' ) ),
			'codes_made' => (int) ( $state['codes_made'] ?? 0 ),
		);
	}

	/**
	 * Saves the state; an empty one is deleted.
	 *
	 * @param int                  $user_id The user.
	 * @param array<string, mixed> $state   From state().
	 */
	private static function save_state( int $user_id, array $state ): void {
		if ( '' === $state['totp'] && ! $state['codes'] ) {
			delete_user_meta( $user_id, self::META );
			return;
		}
		update_user_meta( $user_id, self::META, $state );
	}

	/**
	 * Whether two-step login is on for an account: an app or a key.
	 *
	 * @param WP_User|int $user The user.
	 */
	public static function enabled( $user ): bool {
		$id = $user instanceof WP_User ? $user->ID : (int) $user;
		return $id > 0 && ( self::has_totp( $id ) || (bool) self::keys( $id ) );
	}

	/**
	 * Whether the account must use two-step login (the Security page's
	 * setting).
	 *
	 * @param WP_User|int $user The user.
	 */
	public static function required( $user ): bool {
		$id = $user instanceof WP_User ? $user->ID : (int) $user;
		if ( $id <= 0 ) {
			return false;
		}
		switch ( Settings::get( 'two_factor_required' ) ) {
			case 'super_admins':
				return is_super_admin( $id );
			case 'administrators':
				return is_super_admin( $id ) || self::administers_a_site( $id );
			default:
				return false;
		}
	}

	/**
	 * Whether a user is an administrator of any site of the network.
	 *
	 * @param int $user_id The user.
	 */
	public static function administers_a_site( int $user_id ): bool {
		foreach ( (array) get_user_meta( $user_id ) as $key => $values ) {
			if ( str_ends_with( (string) $key, 'capabilities' ) ) {
				$caps = maybe_unserialize( $values[0] ?? '' );
				if ( is_array( $caps ) && ! empty( $caps['administrator'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	// --- The authenticator app ---------------------------------------------

	/**
	 * Whether the account has an authenticator app.
	 *
	 * @param int $user_id The user.
	 */
	public static function has_totp( int $user_id ): bool {
		return '' !== self::state( $user_id )['totp'];
	}

	/**
	 * When the app was added (0 when there is none).
	 *
	 * @param int $user_id The user.
	 */
	public static function totp_added( int $user_id ): int {
		return self::state( $user_id )['totp_added'];
	}

	/**
	 * The secret being set up, made (or remade after an hour) when asked.
	 *
	 * @param int $user_id The user.
	 */
	public static function setup_secret( int $user_id ): string {
		$setup  = get_user_meta( $user_id, self::SETUP_META, true );
		$secret = is_array( $setup ) && (int) ( $setup['time'] ?? 0 ) > time() - HOUR_IN_SECONDS ? self::decrypt( (string) ( $setup['secret'] ?? '' ) ) : '';
		if ( '' === $secret ) {
			$secret = Totp::new_secret();
			update_user_meta(
				$user_id,
				self::SETUP_META,
				array(
					'secret' => self::encrypt( $secret ),
					'time'   => time(),
				)
			);
		}
		return $secret;
	}

	/**
	 * Turns the app on with the secret being set up, when the code is right.
	 *
	 * @param int    $user_id The user.
	 * @param string $code    A code from the app.
	 */
	public static function confirm_totp( int $user_id, string $code ): bool {
		$secret = self::setup_secret( $user_id );
		$step   = Totp::verify( $secret, $code );
		if ( null === $step ) {
			return false;
		}
		$state               = self::state( $user_id );
		$state['totp']       = self::encrypt( $secret );
		$state['step']       = $step;
		$state['totp_added'] = time();
		self::save_state( $user_id, $state );
		delete_user_meta( $user_id, self::SETUP_META );
		return true;
	}

	/**
	 * Removes the app.
	 *
	 * @param int $user_id The user.
	 */
	public static function remove_totp( int $user_id ): void {
		$state               = self::state( $user_id );
		$state['totp']       = '';
		$state['step']       = 0;
		$state['totp_added'] = 0;
		if ( ! self::keys( $user_id ) ) {
			$state['codes'] = array(); // Nothing left to recover.
		}
		self::save_state( $user_id, $state );
	}

	/**
	 * Checks a code from the app, and uses its step up.
	 *
	 * @param int    $user_id The user.
	 * @param string $code    What was typed.
	 */
	public static function verify_totp( int $user_id, string $code ): bool {
		$state  = self::state( $user_id );
		$secret = self::decrypt( $state['totp'] );
		$step   = '' === $secret ? null : Totp::verify( $secret, $code, $state['step'] );
		if ( null === $step ) {
			return false;
		}
		$state['step'] = $step;
		self::save_state( $user_id, $state );
		return true;
	}

	// --- Security keys ------------------------------------------------------

	/**
	 * The account's security keys.
	 *
	 * @param int $user_id The user.
	 * @return array<int, array<string, mixed>>
	 */
	public static function keys( int $user_id ): array {
		$keys = get_user_meta( $user_id, self::KEYS_META, true );
		return is_array( $keys ) ? array_values( array_filter( $keys, static fn( $key ) => is_array( $key ) && ! empty( $key['id'] ) && ! empty( $key['key'] ) ) ) : array();
	}

	/**
	 * The keys that work on this request's address.
	 *
	 * @param int $user_id The user.
	 * @return array<int, array<string, mixed>>
	 */
	public static function keys_here( int $user_id ): array {
		return array_values( array_filter( self::keys( $user_id ), static fn( $key ) => WebAuthn::works_on( (string) ( $key['rp'] ?? '' ) ) ) );
	}

	/**
	 * Adds a key.
	 *
	 * @param int                  $user_id    The user.
	 * @param array<string, mixed> $credential From WebAuthn::verify_registration().
	 * @param string               $name       What the owner calls it.
	 */
	public static function add_key( int $user_id, array $credential, string $name ): bool {
		$keys = self::keys( $user_id );
		if ( count( $keys ) >= self::MAX_KEYS || self::find_key( $user_id, (string) $credential['id'] ) ) {
			return false;
		}
		$keys[] = array(
			'id'    => (string) $credential['id'],
			'key'   => (string) $credential['key'],
			'alg'   => (int) $credential['alg'],
			'count' => (int) $credential['count'],
			'rp'    => (string) $credential['rp'],
			'name'  => '' !== trim( $name ) ? mb_substr( sanitize_text_field( $name ), 0, 60 ) : __( 'Security key', 'mody_cloud' ),
			'added' => time(),
			'used'  => 0,
		);
		update_user_meta( $user_id, self::KEYS_META, $keys );
		return true;
	}

	/**
	 * One of the account's keys by its credential ID.
	 *
	 * @param int    $user_id The user.
	 * @param string $id      The credential ID, base64url.
	 * @return array<string, mixed>|null
	 */
	public static function find_key( int $user_id, string $id ): ?array {
		foreach ( self::keys( $user_id ) as $key ) {
			if ( hash_equals( (string) $key['id'], $id ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Records a key's use: its new counter and the time.
	 *
	 * @param int    $user_id The user.
	 * @param string $id      The credential ID.
	 * @param int    $count   The new counter.
	 */
	public static function used_key( int $user_id, string $id, int $count ): void {
		$keys = self::keys( $user_id );
		foreach ( $keys as &$key ) {
			if ( hash_equals( (string) $key['id'], $id ) ) {
				$key['count'] = $count;
				$key['used']  = time();
			}
		}
		unset( $key );
		update_user_meta( $user_id, self::KEYS_META, $keys );
	}

	/**
	 * Removes a key.
	 *
	 * @param int    $user_id The user.
	 * @param string $id      The credential ID.
	 */
	public static function remove_key( int $user_id, string $id ): bool {
		$keys = self::keys( $user_id );
		$left = array_values( array_filter( $keys, static fn( $key ) => ! hash_equals( (string) $key['id'], $id ) ) );
		if ( count( $left ) === count( $keys ) ) {
			return false;
		}
		if ( $left ) {
			update_user_meta( $user_id, self::KEYS_META, $left );
		} else {
			delete_user_meta( $user_id, self::KEYS_META );
			if ( ! self::has_totp( $user_id ) ) {
				self::save_state( $user_id, array_merge( self::state( $user_id ), array( 'codes' => array() ) ) );
			}
		}
		return true;
	}

	// --- Recovery codes -----------------------------------------------------

	/**
	 * Makes a new set of recovery codes (the old ones stop working) and
	 * returns them, the only time they are shown.
	 *
	 * @param int $user_id The user.
	 * @return string[] Such as `k7rmq-2xd9p`.
	 */
	public static function new_codes( int $user_id ): array {
		$codes  = array();
		$hashes = array();
		$max    = strlen( self::CODE_ALPHABET ) - 1;
		while ( count( $codes ) < self::CODES ) {
			$code = '';
			for ( $i = 0; $i < 10; $i++ ) {
				$code .= self::CODE_ALPHABET[ random_int( 0, $max ) ];
			}
			$codes[]  = substr( $code, 0, 5 ) . '-' . substr( $code, 5 );
			$hashes[] = self::code_hash( $user_id, $code );
		}
		$state               = self::state( $user_id );
		$state['codes']      = $hashes;
		$state['codes_made'] = time();
		self::save_state( $user_id, $state );
		return $codes;
	}

	/**
	 * How many recovery codes are left.
	 *
	 * @param int $user_id The user.
	 */
	public static function codes_left( int $user_id ): int {
		return count( self::state( $user_id )['codes'] );
	}

	/**
	 * Uses a recovery code: true once per code.
	 *
	 * @param int    $user_id The user.
	 * @param string $typed   What was typed (case, spaces and dashes ignored).
	 */
	public static function use_code( int $user_id, string $typed ): bool {
		$code = self::normal_code( $typed );
		if ( 10 !== strlen( $code ) ) {
			return false;
		}
		$hash  = self::code_hash( $user_id, $code );
		$state = self::state( $user_id );
		foreach ( $state['codes'] as $i => $stored ) {
			if ( hash_equals( $stored, $hash ) ) {
				unset( $state['codes'][ $i ] );
				$state['codes'] = array_values( $state['codes'] );
				self::save_state( $user_id, $state );
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks what was typed on the second screen: a code from the app (six
	 * digits) or a recovery code.
	 *
	 * @param int    $user_id The user.
	 * @param string $typed   What was typed.
	 * @return string 'totp', 'recovery', or '' when it is neither.
	 */
	public static function verify_typed( int $user_id, string $typed ): string {
		$digits = (string) preg_replace( '/\s+/', '', $typed );
		if ( preg_match( '/^\d{6}$/', $digits ) ) {
			return self::verify_totp( $user_id, $digits ) ? 'totp' : '';
		}
		return self::use_code( $user_id, $typed ) ? 'recovery' : '';
	}

	/**
	 * Turns everything off: app, keys, codes (a super admin's reset, or
	 * WP-CLI).
	 *
	 * @param int $user_id The user.
	 */
	public static function reset( int $user_id ): void {
		delete_user_meta( $user_id, self::META );
		delete_user_meta( $user_id, self::KEYS_META );
		delete_user_meta( $user_id, self::SETUP_META );
	}

	/**
	 * A recovery code as stored.
	 *
	 * @param string $typed What was typed.
	 */
	private static function normal_code( string $typed ): string {
		return strtolower( (string) preg_replace( '/[\s-]+/', '', $typed ) );
	}

	/**
	 * A code's keyed hash, bound to the account.
	 *
	 * @param int    $user_id The user.
	 * @param string $code    The code, normal form.
	 */
	private static function code_hash( int $user_id, string $code ): string {
		return hash_hmac( 'sha256', 'recovery:' . $user_id . ':' . $code, Secrets::get( 'two-factor' ) );
	}

	// --- Encryption ---------------------------------------------------------

	/**
	 * The key that encrypts app secrets.
	 */
	private static function key(): string {
		return hash( 'sha256', 'totp:' . Secrets::get( 'two-factor' ), true );
	}

	/**
	 * Encrypts an app secret (sodium's secretbox).
	 *
	 * @param string $secret The base32 secret.
	 */
	private static function encrypt( string $secret ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $secret, $nonce, self::key() ) );
	}

	/**
	 * Decrypts an app secret; '' when it cannot be read.
	 *
	 * @param string $stored From encrypt().
	 */
	private static function decrypt( string $stored ): string {
		if ( ! str_starts_with( $stored, 'v1:' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		return false === $plain ? '' : $plain;
	}
}
