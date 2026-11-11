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
 * POST `mody-analytics/v1/view` on each site, open to visitors. It always
 * answers 204 with nothing, whether the view counted or not.
 *
 * What it counts, in the site's day (Store):
 * - the view, on the total and on the page's path (no query string);
 * - for a visitor's first view of the day: a visitor on the total, a visit
 *   on its source (the referring host, '' when there is none or it is the
 *   site itself) and on its device (from the screen's width).
 *
 * The visitor's hash is md5 of the day's salt, the site, the day, the
 * address and the browser's user agent. Salts live two days in a network
 * option and are then dropped with the hashes, so nobody, the platform
 * included, can link two days or recover an address.
 *
 * Not counted: user agents that say they are bots, posts from another
 * site's pages (their Origin), bodies that are not the beacon's, and a
 * visitor's views past MAX_VIEWS a day.
 */
final class Collector {

	/** The REST namespace. */
	public const NAMESPACE = 'mody-analytics/v1';

	/** The route. */
	public const ROUTE = '/view';

	/** A visitor's views counted per day; more are a script, not a person. */
	public const MAX_VIEWS = 300;

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
		if ( is_array( $data ) && self::from_this_site( (string) $request->get_header( 'origin' ) ) ) {
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
	 * @param string $path     The page's path.
	 * @param string $referrer The referring address, or ''.
	 * @param int    $width    The screen's width in CSS pixels, or 0.
	 * @param string $address  The visitor's IP address.
	 * @param string $agent    The browser's user agent.
	 * @return bool Whether it counted.
	 */
	public static function count( string $path, string $referrer, int $width, string $address, string $agent ): bool {
		$path = self::path( $path );
		if ( '' === $path || '' === $agent || preg_match( self::BOTS, $agent ) ) {
			return false;
		}
		$site  = get_current_blog_id();
		$day   = wp_date( 'Y-m-d' );
		$hash  = md5( self::salt( $day ) . '|' . $site . '|' . $day . '|' . $address . '|' . $agent );
		$views = Store::see( $site, $day, $hash );
		if ( $views > self::MAX_VIEWS ) {
			return false;
		}
		$first = 1 === $views ? 1 : 0;
		Store::add( $site, $day, 'total', '', 1, $first );
		Store::add( $site, $day, 'page', $path, 1, $first );
		if ( $first ) {
			Store::add( $site, $day, 'source', self::source( $referrer ), 1, 1 );
			Store::add( $site, $day, 'device', self::device( $width ), 1, 1 );
		}
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
	 * the proxies' headers believed: Cloudflare's CF-Connecting-IP, else the
	 * first public address in X-Forwarded-For.
	 */
	public static function address(): string {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Validated as IP addresses below.
		$remote = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		if ( ! self::is_public( $remote ) ) {
			$candidates = array_merge(
				array( (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' ) ),
				array_map( 'trim', explode( ',', (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) ) )
			);
			foreach ( $candidates as $candidate ) {
				if ( self::is_public( $candidate ) ) {
					return $candidate;
				}
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
	 * hashes of days before yesterday (UTC) are dropped then: every site's
	 * own day is within a day of UTC's.
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
		return $salts[ $day ];
	}
}
