<?php
/**
 * Custom domains: the table, and the lookup that runs before WordPress picks
 * a site (sunrise.php).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * Decided by the maintainer on 2026-11-13 (tickets/custom-domains.md):
 * a member site can answer at its own domain. The admin stays on the
 * site's `*.mody.cloud` address, where the network's login works; only the
 * public site moves.
 *
 * This file is loaded by `app/web/sunrise.php` before the plugins and the
 * autoloader, so it uses nothing but `$wpdb` and the object cache. A request
 * for a host that is not the network's (`mody.cloud`, `*.mody.cloud`) is
 * looked up in `mody_domains`; an active row makes WordPress load that row's
 * site (`pre_get_site_by_path`). Anything else goes on as without this
 * (multisite's own "no site here").
 */
final class Mapping {

	/** Object cache group. */
	public const GROUP = 'mody_domains';

	/** The statuses a domain goes through. */
	public const STATUSES = array( 'pending', 'issuing', 'active', 'failed' );

	/**
	 * The table's name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'mody_domains';
	}

	/**
	 * The network's own domain (`mody.cloud`).
	 */
	public static function network_domain(): string {
		return strtolower( defined( 'DOMAIN_CURRENT_SITE' ) ? (string) DOMAIN_CURRENT_SITE : '' );
	}

	/**
	 * Whether a host belongs to the network: its domain or one of its subdomains.
	 *
	 * @param string $host A host, lowercase, without a port.
	 */
	public static function is_network_host( string $host ): bool {
		$network = self::network_domain();
		return '' === $network || $host === $network || str_ends_with( $host, '.' . $network );
	}

	/**
	 * The active domain row for a host, from the object cache when it can.
	 *
	 * @param string $host A host.
	 * @return array<string, mixed>|null
	 */
	public static function active( string $host ): ?array {
		global $wpdb;
		$host  = strtolower( $host );
		$found = wp_cache_get( $host, self::GROUP );
		if ( false === $found ) {
			$wpdb->suppress_errors( true ); // Before the table exists (the first request after a deploy).
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE domain = %s AND status = 'active'", $host ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; cached.
			$wpdb->suppress_errors( false );
			$found = is_array( $row ) ? $row : array();
			wp_cache_set( $host, $found, self::GROUP, 300 );
		}
		return $found ? $found : null;
	}

	/**
	 * Forgets a host's cached row (it changed).
	 *
	 * @param string $host A host.
	 */
	public static function forget( string $host ): void {
		wp_cache_delete( strtolower( $host ), self::GROUP );
	}

	/**
	 * The host of the request running now, lowercase, without a port.
	 */
	public static function request_host(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compared with stored domains only.
		return (string) preg_replace( '/:\d+$/', '', $host );
	}

	/**
	 * sunrise.php: a mapped host loads its site.
	 */
	public static function sunrise(): void {
		$host = self::request_host();
		if ( '' === $host || self::is_network_host( $host ) ) {
			return;
		}
		$row = self::active( $host );
		if ( ! $row ) {
			return;
		}
		add_filter(
			'pre_get_site_by_path',
			static function ( $site, $domain ) use ( $host, $row ) {
				return strtolower( (string) $domain ) === $host ? get_site( (int) $row['site_id'] ) : $site;
			},
			10,
			2
		);
	}
}
