<?php
/**
 * The update check: WordPress's usual update notice for this plugin, from
 * mody.cloud (tickets/product-pages-bari-and-plugins.md, part 5; decided by
 * the maintainer on 2026-11-21, D14 and D21).
 *
 * Every Mody plugin offered on mody.cloud carries an identical copy of this
 * class in its own namespace, so each still runs alone
 * (scripts/update-check-test.php checks that the copies match).
 *
 * - **When:** only when WordPress checks for plugin updates (core's twice a
 *   day), and only for a plugin installed the normal way whose header says
 *   `Update URI: https://mody.cloud/` (core then asks the
 *   `update_plugins_mody.cloud` filter). Never on Mody Cloud's own network,
 *   where the plugins are MU plugins and versions come only through the
 *   repository (Mody Updates).
 * - **What it asks:** one GET of mody.cloud's latest.json, with its own
 *   User-Agent (WordPress's default carries the site's address), no query,
 *   no cookies, no redirects. Nothing about the site is sent.
 * - **How often:** at most every 12 hours for the whole site (a network:
 *   for the whole network), whatever the number of Mody plugins: every copy
 *   shares one cached answer. A failed request waits as long.
 * - **What it trusts:** a version, and a zip and page on mody.cloud over
 *   HTTPS; anything else is ignored.
 * - **The details link** of the update notice is answered from the same
 *   file, so WordPress does not ask WordPress.org about a plugin with the
 *   same slug.
 *
 * latest.json: {"plugins": {"mody-seo": {"version": "1.0.1", "package":
 * "https://mody.cloud/.../mody-seo-1.0.1.zip", "url":
 * "https://mody.cloud/plugins/seo/", "requires": "7.1", "tested": "7.1",
 * "requires_php": "8.1", "changelog": "…"}}}, keyed by the plugin's folder.
 *
 * @package Mody
 */

namespace Mody\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Feeds WordPress's update notice from mody.cloud.
 */
final class UpdateCheck {

	/** The host in the plugins' `Update URI` header, and the only one trusted. */
	public const HOST = 'mody.cloud';

	/** What is asked. */
	public const URL = 'https://mody.cloud/web/uploads/downloads/latest.json';

	/** Sent instead of WordPress's "WordPress/<version>; <site address>". */
	public const USER_AGENT = 'Mody-Update-Check';

	/** The site transient every copy shares. */
	public const CACHE = 'mody_update_check';

	/** The plugin's main file. */
	private static string $file = '';

	/**
	 * Hooks, for the plugin whose main file this is.
	 *
	 * @param string $file The plugin's main file (__FILE__).
	 */
	public static function register( string $file ): void {
		self::$file = $file;
		add_filter( 'update_plugins_' . self::HOST, array( self::class, 'update' ), 10, 3 );
		add_filter( 'plugins_api', array( self::class, 'details' ), 10, 3 );
	}

	/**
	 * Whether this is Mody Cloud's own network, where versions come only
	 * through the repository.
	 */
	public static function platform(): bool {
		return class_exists( '\Mody\Updates\Updates' );
	}

	/**
	 * `update_plugins_mody.cloud`: this plugin's newest version, when the
	 * plugin asked about is this one.
	 *
	 * @param array|false $update      An answer from another filter, or false.
	 * @param array       $plugin_data The plugin's header.
	 * @param string      $plugin_file The plugin's file, relative to the plugins folder.
	 * @return array|false
	 */
	public static function update( $update, $plugin_data, $plugin_file ) {
		if ( $update || self::platform() || '' === self::$file || plugin_basename( self::$file ) !== $plugin_file ) {
			return $update;
		}
		return self::answer( dirname( $plugin_file ) ) ?? false;
	}

	/**
	 * What WordPress needs to offer the update, from latest.json; null
	 * without a trusted entry for that folder.
	 *
	 * @param string $slug The plugin's folder (mody-seo).
	 */
	public static function answer( string $slug ): ?array {
		$entry = self::latest()[ $slug ] ?? null;
		if ( ! is_array( $entry ) || ! is_string( $entry['version'] ?? null ) || ! preg_match( '/^\d+(?:\.\d+){0,3}$/', $entry['version'] ) || ! self::ours( $entry['package'] ?? '' ) ) {
			return null;
		}
		$answer = array(
			'slug'    => $slug,
			'version' => $entry['version'],
			'package' => $entry['package'],
			'url'     => self::ours( $entry['url'] ?? '' ) ? $entry['url'] : '',
		);
		foreach ( array( 'requires', 'tested', 'requires_php' ) as $key ) {
			if ( is_string( $entry[ $key ] ?? null ) && preg_match( '/^\d+(?:\.\d+){0,2}$/', $entry[ $key ] ) ) {
				$answer[ $key ] = $entry[ $key ];
			}
		}
		$answer['changelog'] = is_string( $entry['changelog'] ?? null ) ? $entry['changelog'] : '';
		return $answer;
	}

	/**
	 * `plugins_api`: the update notice's details, for this plugin, from
	 * latest.json instead of WordPress.org.
	 *
	 * @param false|object|array $result An answer from another filter, or false.
	 * @param string             $action The request (plugin_information).
	 * @param object             $args   Its arguments (slug).
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( false !== $result || 'plugin_information' !== $action || self::platform() || '' === self::$file || ! is_object( $args ) || ( $args->slug ?? '' ) !== dirname( plugin_basename( self::$file ) ) ) {
			return $result;
		}
		$answer = self::answer( $args->slug );
		if ( ! $answer ) {
			return $result;
		}
		$header = get_file_data( self::$file, array( 'name' => 'Plugin Name' ) );
		$info   = array(
			'name'          => $header['name'] ? $header['name'] : $answer['slug'],
			'slug'          => $answer['slug'],
			'version'       => $answer['version'],
			'homepage'      => $answer['url'],
			'download_link' => $answer['package'],
			'sections'      => array(
				'changelog' => '' !== $answer['changelog'] ? wpautop( esc_html( $answer['changelog'] ) ) : '',
			),
		);
		foreach ( array( 'requires', 'tested', 'requires_php' ) as $key ) {
			if ( isset( $answer[ $key ] ) ) {
				$info[ $key ] = $answer[ $key ];
			}
		}
		return (object) $info;
	}

	/**
	 * latest.json's plugins, cached for 12 hours for every copy of this class.
	 */
	private static function latest(): array {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) && is_array( $cached['plugins'] ?? null ) ) {
			return $cached['plugins'];
		}
		$response = wp_safe_remote_get(
			self::URL,
			array(
				'user-agent'  => self::USER_AGENT,
				'timeout'     => 5,
				'redirection' => 0,
				'cookies'     => array(),
			)
		);
		$plugins = array();
		if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
			$json = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $json ) && is_array( $json['plugins'] ?? null ) ) {
				$plugins = $json['plugins'];
			}
		}
		set_site_transient( self::CACHE, array( 'plugins' => $plugins ), 12 * HOUR_IN_SECONDS );
		return $plugins;
	}

	/**
	 * Whether an address is on mody.cloud, over HTTPS.
	 *
	 * @param mixed $url The address.
	 */
	private static function ours( $url ): bool {
		return is_string( $url ) && 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && self::HOST === wp_parse_url( $url, PHP_URL_HOST );
	}
}
