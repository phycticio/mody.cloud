<?php
/**
 * Mody Security's settings: one network option, set on Network Admin → Mody
 * → Security (tickets/security-plugin-replaces-wordfence.md).
 *
 * @package Mody\Security
 */

namespace Mody\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Every setting the Security page manages, with its default. Read with
 * get(), written only through save(), which sanitizes each value; anything
 * unknown or invalid keeps its default.
 */
final class Settings {

	/** The network option. */
	public const OPTION = 'mody_security';

	/** Who must use two-step login. */
	public const REQUIRED = array( 'none', 'super_admins', 'administrators' );

	/** The firewall's rule groups (Firewall::RULES). */
	public const RULE_GROUPS = array( 'sqli', 'xss', 'traversal', 'code' );

	/** The alerts a super admin can get by email. */
	public const ALERTS = array( 'admin_login', 'address_paused', 'file_changes', 'firewall_block' );

	/**
	 * This request's settings, once read.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * The defaults: today's limits (RateLimit::DEFAULTS), super admins with
	 * two-step login, every firewall rule on.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'two_factor_required' => 'super_admins',
			'login_address'       => 10,
			'login_pair'          => 5,
			'login_account'       => 100,
			'login_minutes'       => 15,
			'blocked_usernames'   => array( 'admin', 'administrator', 'root', 'test', 'wpadmin' ),
			'firewall'            => true,
			'rules'               => array_fill_keys( self::RULE_GROUPS, true ),
			'blocked_addresses'   => array(),
			'allowed_addresses'   => array(),
			'alerts'              => array(
				'admin_login'    => true,
				'address_paused' => false,
				'file_changes'   => true,
				'firewall_block' => false,
			),
		);
	}

	/**
	 * Every setting, the stored ones over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_site_option( self::OPTION, array() );
			self::$cache = self::sanitize( is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key A key of defaults().
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Whether an alert is on.
	 *
	 * @param string $alert One of ALERTS.
	 */
	public static function alert( string $alert ): bool {
		return ! empty( self::get( 'alerts' )[ $alert ] );
	}

	/**
	 * Whether a firewall rule group is on.
	 *
	 * @param string $group One of RULE_GROUPS.
	 */
	public static function rule( string $group ): bool {
		return ! empty( self::get( 'rules' )[ $group ] );
	}

	/**
	 * Merges changes into the stored settings and saves them.
	 *
	 * @param array<string, mixed> $changes Keys of defaults() to change.
	 * @return array<string, mixed> The settings saved.
	 */
	public static function save( array $changes ): array {
		$settings = self::sanitize( array_merge( self::all(), $changes ) );
		update_site_option( self::OPTION, $settings );
		self::$cache = $settings;
		return $settings;
	}

	/**
	 * Forgets this request's copy (tests).
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Every value checked, over the defaults.
	 *
	 * @param array<string, mixed> $values Stored or submitted values.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $values ): array {
		$defaults = self::defaults();
		$clean    = $defaults;

		if ( isset( $values['two_factor_required'] ) && in_array( $values['two_factor_required'], self::REQUIRED, true ) ) {
			$clean['two_factor_required'] = $values['two_factor_required'];
		}
		// Attempts: 1 to 1000; the window: 1 minute to a day.
		foreach ( array( 'login_address', 'login_pair', 'login_account' ) as $key ) {
			if ( isset( $values[ $key ] ) && is_numeric( $values[ $key ] ) ) {
				$clean[ $key ] = max( 1, min( 1000, (int) $values[ $key ] ) );
			}
		}
		if ( isset( $values['login_minutes'] ) && is_numeric( $values['login_minutes'] ) ) {
			$clean['login_minutes'] = max( 1, min( 1440, (int) $values['login_minutes'] ) );
		}
		if ( isset( $values['blocked_usernames'] ) ) {
			$clean['blocked_usernames'] = self::usernames( $values['blocked_usernames'] );
		}
		if ( isset( $values['firewall'] ) ) {
			$clean['firewall'] = (bool) $values['firewall'];
		}
		if ( isset( $values['rules'] ) && is_array( $values['rules'] ) ) {
			foreach ( self::RULE_GROUPS as $group ) {
				$clean['rules'][ $group ] = ! empty( $values['rules'][ $group ] );
			}
		}
		foreach ( array( 'blocked_addresses', 'allowed_addresses' ) as $key ) {
			if ( isset( $values[ $key ] ) ) {
				$clean[ $key ] = self::addresses( $values[ $key ] );
			}
		}
		if ( isset( $values['alerts'] ) && is_array( $values['alerts'] ) ) {
			foreach ( self::ALERTS as $alert ) {
				$clean['alerts'][ $alert ] = ! empty( $values['alerts'][ $alert ] );
			}
		}
		return $clean;
	}

	/**
	 * A list of login names: lower case, one per entry, at most 100.
	 *
	 * @param mixed $value A list, or text with one per line or comma.
	 * @return string[]
	 */
	public static function usernames( $value ): array {
		$items = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
		$names = array();
		foreach ( (array) $items as $item ) {
			$name = strtolower( sanitize_user( (string) $item, true ) );
			if ( '' !== $name ) {
				$names[ $name ] = $name;
			}
		}
		return array_slice( array_values( $names ), 0, 100 );
	}

	/**
	 * A list of addresses and ranges (`203.0.113.7`, `203.0.113.0/24`,
	 * `2001:db8::/32`), the invalid ones left out, at most 500.
	 *
	 * @param mixed $value A list, or text with one per line or comma.
	 * @return string[]
	 */
	public static function addresses( $value ): array {
		$items = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
		$list  = array();
		foreach ( (array) $items as $item ) {
			$range = self::range( (string) $item );
			if ( null !== $range ) {
				$list[ $range ] = $range;
			}
		}
		return array_slice( array_values( $list ), 0, 500 );
	}

	/**
	 * An address or range in its canonical form, or null when it is neither.
	 *
	 * @param string $item Such as `203.0.113.0/24`.
	 */
	public static function range( string $item ): ?string {
		$item = trim( $item );
		if ( '' === $item ) {
			return null;
		}
		$parts  = explode( '/', $item, 2 );
		$packed = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input is expected; false is the answer.
		if ( false === $packed ) {
			return null;
		}
		$address = (string) inet_ntop( $packed );
		$max     = 4 === strlen( $packed ) ? 32 : 128;
		if ( ! isset( $parts[1] ) ) {
			return $address;
		}
		if ( ! ctype_digit( $parts[1] ) || (int) $parts[1] > $max || (int) $parts[1] < 1 ) {
			return null;
		}
		return (int) $parts[1] === $max ? $address : $address . '/' . (int) $parts[1];
	}

	/**
	 * Whether an address is in a list of addresses and ranges.
	 *
	 * @param string   $address An IP address.
	 * @param string[] $list    Entries from addresses().
	 */
	public static function in_list( string $address, array $list ): bool {
		$packed = @inet_pton( $address ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An empty or odd address is simply in no list.
		if ( false === $packed || ! $list ) {
			return false;
		}
		foreach ( $list as $entry ) {
			$parts = explode( '/', $entry, 2 );
			$net   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Stored entries are valid; this is a guard.
			if ( false === $net || strlen( $net ) !== strlen( $packed ) ) {
				continue;
			}
			$bits  = isset( $parts[1] ) ? (int) $parts[1] : strlen( $net ) * 8;
			$bytes = intdiv( $bits, 8 );
			if ( substr( $packed, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
				continue;
			}
			$rest = $bits % 8;
			if ( $rest ) {
				$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
				if ( ( ord( $packed[ $bytes ] ) & $mask ) !== ( ord( $net[ $bytes ] ) & $mask ) ) {
					continue;
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * `mody_rate_limits`, first: the three login limits and their window
	 * from the page.
	 *
	 * @param array<string, array{0: int, 1: int}> $limits The limits.
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function rate_limits( $limits ) {
		if ( ! is_array( $limits ) ) {
			return $limits;
		}
		$window = (int) self::get( 'login_minutes' ) * MINUTE_IN_SECONDS;
		foreach ( array( 'login_address', 'login_pair', 'login_account' ) as $key ) {
			$limits[ $key ] = array( (int) self::get( $key ), $window );
		}
		return $limits;
	}
}
