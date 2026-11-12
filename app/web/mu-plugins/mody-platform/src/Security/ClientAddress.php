<?php
/**
 * The visitor's address, for rate limits (tickets/M5-01-hardening-launch.md,
 * the security pass of 2026-11-12).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Who is asking, behind our proxies.
 *
 * In production a request passes Cloudflare, then Nginx Proxy Manager, then
 * nginx, so PHP sees a private address and the real one is in headers. The
 * rule, in two steps:
 *
 * 1. **The peer**: the address that connected to our own side. A connection
 *    from a public address is its own peer (nothing is forwarded, no header
 *    is believed). Behind a proxy PHP sees a private address, and the peer
 *    is the rightmost public entry of `X-Forwarded-For`: the one Nginx Proxy
 *    Manager wrote itself (it appends the address it was connected from, or
 *    sets it, depending on its configuration; either way it is the last
 *    entry, and private entries to its right are our own proxies). Everything
 *    to the left is whatever the visitor sent.
 * 2. **The visitor**: when the peer is one of Cloudflare's published ranges,
 *    it is Cloudflare's edge talking, and `CF-Connecting-IP` (which
 *    Cloudflare sets, overwriting what the visitor sent) is the visitor.
 *    Otherwise the peer is the visitor, and `CF-Connecting-IP` is ignored:
 *    anyone who reaches Nginx Proxy Manager without going through Cloudflare
 *    can send any value in it, and would escape every limit by changing it on
 *    each request.
 *
 * Cloudflare's ranges are a constant, with no network call. They change
 * rarely (a handful of times a year, only ever by adding ranges); when a new
 * edge range is missing, its visitors are counted under the edge's address,
 * which is stricter, never looser. Update them from the two URLs in the
 * constant's comment.
 *
 * mody-analytics' Collector::address() must give the same answer: it should
 * call ClientAddress::get() when this class exists (scripts/security-signup-test.php
 * checks that it does).
 *
 * **Not known** is the empty string from limit_address(): a private address
 * with no forwarding headers is the site calling itself, a local browser or
 * WP-CLI, never a visitor. Rate limits skip it instead of throttling
 * everyone together.
 */
final class ClientAddress {

	/**
	 * Cloudflare's edge ranges, as published at https://www.cloudflare.com/ips-v4
	 * and https://www.cloudflare.com/ips-v6, copied on 2026-11-12.
	 */
	public const CLOUDFLARE_RANGES = array(
		// IPv4.
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		// IPv6.
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * The visitor's address; the connecting one when there is nothing better
	 * (private behind a proxy that forwards nothing; empty in WP-CLI).
	 */
	public static function get(): string {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Validated as IP addresses below.
		$remote = self::clean( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$peer   = $remote;
		if ( ! self::is_public( $remote ) ) {
			$peer = '';
			foreach ( array_reverse( explode( ',', (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) ) ) as $candidate ) {
				$candidate = self::clean( $candidate );
				if ( self::is_public( $candidate ) ) {
					$peer = $candidate;
					break;
				}
			}
			if ( '' === $peer ) {
				return $remote;
			}
		}
		if ( self::is_cloudflare( $peer ) ) {
			$visitor = self::clean( (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' ) );
			if ( self::is_public( $visitor ) ) {
				return $visitor;
			}
		}
		// phpcs:enable
		return $peer;
	}

	/**
	 * The key a visitor's limits are counted under: their public address,
	 * an IPv6 one reduced to its /64 (a household or an attacker owns the
	 * whole range); '' when the address is not known.
	 */
	public static function limit_address(): string {
		$address = self::get();
		if ( ! self::is_public( $address ) ) {
			return '';
		}
		$packed = inet_pton( $address );
		if ( false !== $packed && 16 === strlen( $packed ) && ! str_contains( $address, '.' ) ) {
			return bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
		}
		return $address;
	}

	/**
	 * Whether an IP address is a public one.
	 *
	 * @param string $ip The address.
	 */
	public static function is_public( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Whether an address is in one of Cloudflare's ranges.
	 *
	 * @param string $ip An IPv4 or IPv6 address.
	 */
	public static function is_cloudflare( string $ip ): bool {
		$packed = self::pack( $ip );
		if ( null === $packed ) {
			return false;
		}
		foreach ( self::CLOUDFLARE_RANGES as $range ) {
			if ( self::in_range( $packed, $range ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * An address as text, trimmed, with an IPv4 address written the IPv6 way
	 * (`::ffff:104.16.0.1`, which a dual-stack proxy prints) as the IPv4 one:
	 * PHP counts the mapped form as reserved, so it would never be found.
	 * Anything that is no address comes back as it was.
	 *
	 * @param string $ip The address.
	 */
	private static function clean( string $ip ): string {
		$ip     = trim( $ip );
		$packed = self::pack( $ip );
		return null !== $packed && 4 === strlen( $packed ) && str_contains( $ip, ':' ) ? (string) inet_ntop( $packed ) : $ip;
	}

	/**
	 * An address as bytes: 4 for IPv4, 16 for IPv6. A mapped IPv4 address is
	 * the IPv4 one.
	 *
	 * @param string $ip The address.
	 */
	private static function pack( string $ip ): ?string {
		$packed = filter_var( $ip, FILTER_VALIDATE_IP ) ? inet_pton( $ip ) : false;
		if ( false === $packed ) {
			return null;
		}
		if ( 16 === strlen( $packed ) && str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			return substr( $packed, 12 );
		}
		return $packed;
	}

	/**
	 * Whether packed address bytes are inside a CIDR range of the same family.
	 *
	 * @param string $packed An address from pack().
	 * @param string $range  `network/bits`.
	 */
	private static function in_range( string $packed, string $range ): bool {
		list( $network, $bits ) = explode( '/', $range );
		$base                   = inet_pton( $network );
		if ( false === $base || strlen( $base ) !== strlen( $packed ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$whole = intdiv( $bits, 8 );
		if ( 0 !== strncmp( $packed, $base, $whole ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $packed[ $whole ] ) & $mask ) === ( ord( $base[ $whole ] ) & $mask );
	}
}
