<?php
/**
 * The beacon's route: counts one page view.
 *
 * @package Mody\Analytics
 */

namespace Mody\Analytics;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST `mody-analytics/v1/view` on each site, open to everyone who reads
 * it. It always answers 204 with nothing, whether the view counted or not.
 *
 * Every counted view is one row of Store::views(): the site, the visit,
 * its place in the visit, the site's day and hour, the path (no query
 * string), the source (the referring host) and the device (from the
 * screen's width).
 *
 * **The visit, never the person.** The visitor's hash is md5 of the day's
 * salt, the site, the day, the address and the browser's user agent. It is
 * only a key in today's map (Store::see()), which points it at a random
 * visit ID; a new ID after VISIT_GAP without a view. Rows keep the random
 * ID, never the hash. Salts live two days in a network option and are then
 * dropped with the map, so nobody, the platform included, can link a visit
 * to an address or two visits to each other. What is left is a pattern:
 * a visit that came from a source, saw these pages in this order.
 *
 * Visits are per site: the hash includes the site, so the same person on
 * two sites is two unrelated visits (the maintainer's choice, 2026-11-12).
 *
 * Not counted: user agents that say they are bots, posts from another
 * site's pages (their Origin), bodies that are not the beacon's, a
 * visitor's views past MAX_VIEWS a day, and whatever the filter
 * `mody_analytics_accept_view` refuses (the platform plugs a rate limit per
 * address into it, so this plugin works alone too).
 *
 * **Bounded, whoever sends:** each site counts at most MAX_DISTINCT
 * different pages and MAX_DISTINCT different sources a day (Store::admit());
 * the rest are counted as OTHER. Anyone can post to the beacon with any
 * path and any referrer, so without the cap a script could grow the tables
 * and the dashboard's lists without end.
 */
final class Collector {

	/** The REST namespace. */
	public const NAMESPACE = 'mody-analytics/v1';

	/** The route. */
	public const ROUTE = '/view';

	/** A visitor's views counted per day; more are a script, not a person. */
	public const MAX_VIEWS = 300;

	/** Seconds without a view that end a visit (the maintainer's choice, 2026-11-12). */
	public const VISIT_GAP = 30 * MINUTE_IN_SECONDS;

	/** Different pages, and different sources, one site counts in a day; the rest go under OTHER. */
	public const MAX_DISTINCT = 1000;

	/** What the pages and the sources past the cap are counted as. */
	public const OTHER = 'other';

	/** The network option with the salts: day => salt. */
	private const SALT_OPTION = 'mody_analytics_salts';

	/** User agents that are not people. */
	private const BOTS = '/bot|crawl|spider|slurp|scrape|fetch|headless|lighthouse|pagespeed|preview|monitor|uptime|curl|wget|python|java\/|go-http|okhttp|axios|node-fetch|phantom|selenium|puppeteer|playwright/i';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * `rest_api_init`: the route.
	 */
	public static function register_route(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_view' ),
				'permission_callback' => '__return_true', // For visitors: it only adds to counts.
			)
		);
	}

	/**
	 * POST `view`.
	 *
	 * @param WP_REST_Request $request The beacon.
	 */
	public static function rest_view( WP_REST_Request $request ): WP_REST_Response {
		$data = json_decode( (string) $request->get_body(), true );
		/**
		 * Filters whether a view from this request is counted: a rate limit, for example.
		 * It runs for every beacon that is otherwise valid.
		 *
		 * @param bool $accept Whether it is counted.
		 */
		if ( is_array( $data ) && self::from_this_site( (string) $request->get_header( 'origin' ) ) && apply_filters( 'mody_analytics_accept_view', true ) ) {
			self::count(
				(string) ( $data['p'] ?? '' ),
				(string) ( $data['r'] ?? '' ),
				(int) ( $data['w'] ?? 0 ),
				self::address(),
				(string) $request->get_header( 'user_agent' )
			);
		}
		$response = new WP_REST_Response( null, 204 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Counts one view on the current site.
	 *
	 * @param string   $path     The page's path.
	 * @param string   $referrer The referring address, or ''.
	 * @param int      $width    The screen's width in CSS pixels, or 0.
	 * @param string   $address  The visitor's IP address.
	 * @param string   $agent    The browser's user agent.
	 * @param int|null $now      Unix time of the view; now by default.
	 * @return bool Whether it counted.
	 */
	public static function count( string $path, string $referrer, int $width, string $address, string $agent, ?int $now = null ): bool {
		$path = self::path( $path );
		if ( '' === $path || '' === $agent || preg_match( self::BOTS, $agent ) ) {
			return false;
		}
		$now   = $now ?? time();
		$site  = get_current_blog_id();
		$day   = wp_date( 'Y-m-d', $now );
		$hash  = md5( self::salt( $day ) . '|' . $site . '|' . $day . '|' . $address . '|' . $agent );
		$visit = Store::see( $site, $day, $hash, $now, self::VISIT_GAP );
		if ( $visit['views'] > self::MAX_VIEWS ) {
			return false;
		}
		$source = self::source( $referrer );
		/**
		 * Filters how many different pages and sources a site counts in a day.
		 *
		 * @param int $max The cap: MAX_DISTINCT.
		 */
		$max = max( 1, (int) apply_filters( 'mody_analytics_max_distinct', self::MAX_DISTINCT ) );
		Store::add_view(
			array(
				'site_id'  => $site,
				'visit_id' => $visit['visit_id'],
				'seq'      => $visit['seq'],
				'day'      => $day,
				'hour'     => (int) wp_date( 'G', $now ),
				'path'     => Store::admit( $site, $day, 'p', $path, $max ) ? $path : self::OTHER,
				'source'   => ( '' === $source || Store::admit( $site, $day, 's', $source, $max ) ) ? $source : self::OTHER,
				'device'   => self::device( $width ),
			) + Geo\Locator::locate( $address ) // Country and city from our own copy of DB-IP; the address is not kept.
		);
		return true;
	}

	/**
	 * A page's path as counted: from the site's root, no query or fragment,
	 * at most 191 characters; '' when it is not a path.
	 *
	 * @param string $path The beacon's path.
	 */
	public static function path( string $path ): string {
		$path = (string) strtok( $path, '?#' );
		if ( ! str_starts_with( $path, '/' ) || preg_match( '/[\x00-\x1f\x7f\\\\]/', $path ) ) {
			return '';
		}
		$path = rawurldecode( $path );
		if ( ! wp_check_invalid_utf8( $path ) ) {
			return '';
		}
		return mb_substr( $path, 0, 191 );
	}

	/**
	 * Where a visit came from: the referring host without `www.`, or '' for
	 * none (typed, a bookmark, an app) or the site itself.
	 *
	 * @param string $referrer The referring address.
	 */
	public static function source( string $referrer ): string {
		$host = strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );
		$host = (string) preg_replace( '/^www\./', '', $host );
		$own  = (string) preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		if ( '' === $host || $own === $host || ! preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
			return '';
		}
		return mb_substr( $host, 0, 191 );
	}

	/**
	 * The device, from the screen's width.
	 *
	 * @param int $width CSS pixels; 0 when unknown.
	 */
	public static function device( int $width ): string {
		if ( $width <= 0 ) {
			return 'unknown';
		}
		return $width < 768 ? 'mobile' : ( $width < 1100 ? 'tablet' : 'desktop' );
	}

	/**
	 * Whether a post comes from this site's pages: no Origin (older
	 * browsers), or this site's own host.
	 *
	 * @param string $origin The Origin header.
	 */
	public static function from_this_site( string $origin ): bool {
		if ( '' === $origin ) {
			return true;
		}
		return strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * The visitor's address. Behind our proxies (Cloudflare, then Nginx
	 * Proxy Manager, then nginx) PHP sees a private address; only then are
	 * the proxies' headers believed, and only the two a visitor cannot
	 * choose: Cloudflare's CF-Connecting-IP (it overwrites any value the
	 * visitor sent), else the rightmost public address in X-Forwarded-For,
	 * the one our own proxy appended. Never the leftmost, which is whatever
	 * the visitor wrote. A connection from a public address is direct and
	 * no header is believed.
	 *
	 * On the platform this is Mody\Platform\Security\ClientAddress::get(),
	 * which also checks that CF-Connecting-IP came from Cloudflare's own
	 * addresses (the rate limits use it too, so both agree). The rule below
	 * is the fallback when this plugin runs alone.
	 */
	public static function address(): string {
		if ( class_exists( '\\Mody\\Platform\\Security\\ClientAddress' ) ) {
			return \Mody\Platform\Security\ClientAddress::get();
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Validated as IP addresses below.
		$remote = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		if ( self::is_public( $remote ) ) {
			return $remote;
		}
		$cloudflare = trim( (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' ) );
		if ( self::is_public( $cloudflare ) ) {
			return $cloudflare;
		}
		foreach ( array_reverse( explode( ',', (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) ) ) as $candidate ) {
			if ( self::is_public( trim( $candidate ) ) ) {
				return trim( $candidate );
			}
		}
		// phpcs:enable
		return $remote;
	}

	/**
	 * Whether an IP address is a public one.
	 *
	 * @param string $ip The address.
	 */
	private static function is_public( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * The salt of a day, made the first time it is asked for. Salts and
	 * the map's rows of days before yesterday (UTC) are dropped then: every
	 * site's own day is within a day of UTC's. The same pass archives page
	 * views older than Store::RETENTION (no cron: WP-Cron does not run on
	 * subsites, architecture.md).
	 *
	 * @param string $day Y-m-d, the site's day.
	 */
	public static function salt( string $day ): string {
		$salts = get_site_option( self::SALT_OPTION, array() );
		$salts = is_array( $salts ) ? $salts : array();
		if ( isset( $salts[ $day ] ) && is_string( $salts[ $day ] ) ) {
			return $salts[ $day ];
		}
		$oldest = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$salts  = array_filter( $salts, static fn( $salt, $kept ): bool => (string) $kept >= $oldest, ARRAY_FILTER_USE_BOTH );
		$salts[ $day ] = bin2hex( random_bytes( 16 ) );
		update_site_option( self::SALT_OPTION, $salts );
		Store::forget_before( $oldest );
		Store::archive_before( Store::retention_start() );
		return $salts[ $day ];
	}
}
