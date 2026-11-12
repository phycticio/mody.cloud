<?php
/**
 * Secrets that belong to this installation alone (the security pass of
 * 2026-11-12, tickets/M5-01-hardening-launch.md).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Random secrets, made the first time they are needed and kept in one
 * network option, so nothing has to be configured and nothing derives from a
 * value that is in git.
 *
 * Why: `app/wp-config.php` falls back to eight keys and salts that are
 * committed to the repository when the `WORDPRESS_*_KEY` and `_SALT`
 * variables are not set, and nothing sets them. Anyone who can read the
 * repository could compute everything built on `wp_salt()`: ALTCHA's secrets,
 * logged-out nonces, the hash in each cookie. So:
 * - ALTCHA and the rate limits take their secrets from here, never from
 *   `wp_salt()`;
 * - salt() replaces `wp_salt()`'s answer, for a scheme whose key or salt
 *   constant still has its committed value, with a secret from here. It does
 *   nothing when the environment sets real values. **The first request after
 *   the deploy that adds it logs everyone out once**, because every cookie
 *   was signed with the old value; nonces made before expire the same way.
 *
 * The option is `mody_security_secrets`, an array of name => 64 hex digits.
 * Treat it like a password: it is in every backup of the database.
 */
final class Secrets {

	/** The network option that holds them. */
	public const OPTION = 'mody_security_secrets';

	/** Every secret, made together. */
	public const NAMES = array(
		'altcha-signature',
		'altcha-key',
		'rate-limit',
		'salt-auth',
		'salt-secure_auth',
		'salt-logged_in',
		'salt-nonce',
	);

	/**
	 * The keys and salts committed in app/wp-config.php, per scheme of
	 * `wp_salt()`. Public already: they are in git.
	 */
	private const COMMITTED = array(
		'auth'        => array(
			'AUTH_KEY'  => '4f9affda1ab56529801dae42548c458395650bf2',
			'AUTH_SALT' => '7e57cb25ec800c549a4eb6d20427392b831cf5df',
		),
		'secure_auth' => array(
			'SECURE_AUTH_KEY'  => '21b036d787ce2539107d48264333ad4fbb022ccf',
			'SECURE_AUTH_SALT' => '5e828a2270610a9a170931b59c131da371e2a1d5',
		),
		'logged_in'   => array(
			'LOGGED_IN_KEY'  => '0b306815d1cba7f8136ec2b4d6b16f61ed8f74bb',
			'LOGGED_IN_SALT' => '39d8d300113526be805adbeb04c86cc87a1a3d18',
		),
		'nonce'       => array(
			'NONCE_KEY'  => 'f1b8b5ac5b73cad19a4a8ce7610c0dfd232a5a17',
			'NONCE_SALT' => 'efc226c23ae8a3897fc2479860d18c9a82d88146',
		),
	);

	/**
	 * What this request has read: name => secret.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $secrets = null;

	/**
	 * Which schemes still use a committed value: scheme => bool.
	 *
	 * @var array<string, bool>
	 */
	private static array $committed = array();

	/**
	 * A secret, made when there is none yet.
	 *
	 * @param string $name One of NAMES.
	 * @return string 64 hex digits; '' when it could not be stored (the database is down).
	 */
	public static function get( string $name ): string {
		self::$secrets ??= self::read();
		if ( ! isset( self::$secrets[ $name ] ) && in_array( $name, self::NAMES, true ) ) {
			self::$secrets = self::create();
		}
		return self::$secrets[ $name ] ?? '';
	}

	/**
	 * `salt`: `wp_salt()`'s answer, or a secret of this installation when the
	 * scheme's key or salt is still the one committed to git.
	 *
	 * @param string $salt   The salt `wp_salt()` computed.
	 * @param string $scheme 'auth', 'secure_auth', 'logged_in' or 'nonce' (others are left alone).
	 * @return string
	 */
	public static function salt( $salt, $scheme ) {
		// No check of wp_installing(): wpmu_create_blog() sets it and never clears it, so the rest of a
		// request that made a site (the signup, the wizard) would sign with other keys than the next one.
		if ( ! is_string( $salt ) || ! is_string( $scheme ) || ! isset( self::COMMITTED[ $scheme ] ) ) {
			return $salt;
		}
		self::$committed[ $scheme ] ??= self::is_committed( $scheme );
		if ( ! self::$committed[ $scheme ] ) {
			return $salt;
		}
		$secret = self::get( 'salt-' . $scheme );
		return '' === $secret ? $salt : $secret; // Never a different value on every call.
	}

	/**
	 * Whether one of a scheme's two constants still has its committed value.
	 *
	 * @param string $scheme A key of COMMITTED.
	 */
	private static function is_committed( string $scheme ): bool {
		foreach ( self::COMMITTED[ $scheme ] as $constant => $literal ) {
			if ( defined( $constant ) && constant( $constant ) === $literal ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads the stored secrets (valid ones only), from the database.
	 *
	 * @return array<string, string>
	 */
	private static function read(): array {
		$stored = get_site_option( self::OPTION, array() );
		return self::valid( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Makes the secrets that are missing and returns all of them. Another
	 * request may do the same at the same moment (the first ones after a
	 * deploy do): what the database holds afterwards is what everyone uses,
	 * so the answer is read back, not taken from what this call made.
	 *
	 * @return array<string, string>
	 */
	private static function create(): array {
		$stored  = self::read();
		$missing = array_diff( self::NAMES, array_keys( $stored ) );
		if ( ! $missing ) {
			return $stored;
		}
		$new = $stored;
		foreach ( $missing as $name ) {
			$new[ $name ] = bin2hex( random_bytes( 32 ) );
		}
		if ( ! add_site_option( self::OPTION, $new ) ) {
			// The option exists but lacks some names (added since): keep what is there.
			update_site_option( self::OPTION, $new );
		}
		return self::valid( self::fresh() );
	}

	/**
	 * The option straight from the database, past every cache.
	 *
	 * @return array<mixed>
	 */
	private static function fresh(): array {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d ORDER BY meta_id ASC LIMIT 1", self::OPTION, get_current_network_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A cache-free read: another request may have written it a moment ago.
		$value = is_string( $value ) ? maybe_unserialize( $value ) : array();
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Keeps the entries that are secrets: a known name and 64 hex digits.
	 *
	 * @param array<mixed> $secrets Stored values.
	 * @return array<string, string>
	 */
	private static function valid( array $secrets ): array {
		$valid = array();
		foreach ( self::NAMES as $name ) {
			if ( isset( $secrets[ $name ] ) && is_string( $secrets[ $name ] ) && preg_match( '/^[0-9a-f]{64}$/', $secrets[ $name ] ) ) {
				$valid[ $name ] = $secrets[ $name ];
			}
		}
		return $valid;
	}
}
