<?php
/**
 * A visitor's country and city, from their address, on our own server.
 *
 * @package Mody\Analytics
 */

namespace Mody\Analytics\Geo;

defined( 'ABSPATH' ) || exit;

/**
 * Looks an address up in DB-IP City Lite (tickets/visitor-location.md), a
 * file on this server: `app/data/geoip/dbip-city-lite.mmdb`, put there by
 * `bin/geoip` before a deploy. Only the country (ISO code) and the city (its
 * English name) come out; the address goes nowhere and is never kept.
 *
 * Without the file, or with a broken one, every place is unknown and
 * nothing fails: the beacon still counts the visit.
 */
final class Locator {

	/** The file, from the app folder. */
	public const RELATIVE_PATH = 'data/geoip/dbip-city-lite.mmdb';

	/** The open database, per request. @var Reader|false|null */
	private static $reader = null;

	/**
	 * The database file's path. The constant MODY_GEOIP_FILE, else
	 * app/data/geoip/dbip-city-lite.mmdb.
	 */
	public static function path(): string {
		if ( defined( 'MODY_GEOIP_FILE' ) ) {
			return (string) constant( 'MODY_GEOIP_FILE' );
		}
		return dirname( WP_CONTENT_DIR ) . '/' . self::RELATIVE_PATH;
	}

	/**
	 * The open database, or null without one.
	 */
	public static function reader(): ?Reader {
		if ( null === self::$reader ) {
			try {
				self::$reader = new Reader( self::path() );
			} catch ( \RuntimeException $e ) {
				self::$reader = false;
			}
		}
		return self::$reader ?: null;
	}

	/**
	 * Uses another database (tests), or forgets the open one (null).
	 *
	 * @param Reader|null $reader The database.
	 */
	public static function use( ?Reader $reader ): void {
		self::$reader = $reader;
	}

	/**
	 * Where an address is.
	 *
	 * @param string $ip The visitor's address.
	 * @return array{country: string, city: string} ISO country code (uppercase) and English city name; '' when unknown.
	 */
	public static function locate( string $ip ): array {
		$none = array(
			'country' => '',
			'city'    => '',
		);
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return $none; // Private, local or not an address.
		}
		$reader = self::reader();
		if ( ! $reader ) {
			return $none;
		}
		try {
			$record = $reader->get( $ip );
		} catch ( \RuntimeException $e ) {
			return $none;
		}
		if ( ! is_array( $record ) ) {
			return $none;
		}
		$country = strtoupper( (string) ( $record['country']['iso_code'] ?? '' ) );
		$city    = (string) ( $record['city']['names']['en'] ?? '' );
		return array(
			'country' => preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '',
			// DB-IP sometimes names a district in brackets ("Amsterdam (Amsterdam-Centrum)"): finer than a city, so it goes.
			'city'    => '' !== $country ? mb_substr( trim( (string) preg_replace( '/\s*\([^)]*\)\s*$/', '', wp_strip_all_tags( $city ) ) ), 0, 80 ) : '',
		);
	}

	/**
	 * A country's name in the dashboard's language, from its ISO code.
	 *
	 * @param string $code The ISO code; '' for unknown.
	 */
	public static function country_name( string $code ): string {
		if ( '' === $code ) {
			return __( 'Unknown', 'mody_cloud' );
		}
		if ( class_exists( \Locale::class ) ) {
			$name = \Locale::getDisplayRegion( '-' . $code, determine_locale() );
			if ( is_string( $name ) && '' !== $name && strtoupper( $name ) !== $code ) {
				return $name;
			}
		}
		return $code;
	}
}
