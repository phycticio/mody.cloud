<?php
/**
 * The page's language, for browsers and search engines.
 *
 * - <html lang> is the request's language.
 * - hreflang links: one per published translation of the page (the front
 *   page included), plus x-default for the default language's version.
 * - WordPress's own words (dates, "Read more") switch with
 *   switch_to_locale(), once the language is installed (language packs come
 *   with part 9 of the ticket; until then the words stay English).
 *
 * @package Mody\Languages
 */

namespace Mody\Languages;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Language attributes, hreflang links and the locale on the front end.
 */
final class Head {

	/**
	 * Hooked on load.
	 */
	public static function register(): void {
		add_action( 'parse_request', array( self::class, 'switch_locale' ), 5 );
		add_filter( 'language_attributes', array( self::class, 'language_attributes' ), 20 );
		add_action( 'wp_head', array( self::class, 'hreflang' ), 2 );
	}

	/**
	 * `parse_request`: WordPress's words in the request's language, when it
	 * is installed.
	 */
	public static function switch_locale(): void {
		if ( Router::is_other() && Router::current() !== determine_locale() ) {
			switch_to_locale( Router::current() );
		}
	}

	/**
	 * `language_attributes`: lang is the request's language, installed or not.
	 *
	 * @param string $output The attributes.
	 */
	public static function language_attributes( $output ) {
		if ( is_admin() || ! Router::is_other() ) {
			return $output;
		}
		$lang = 'lang="' . esc_attr( Languages::code( Router::current() ) ) . '"';
		$out  = preg_replace( '/lang="[^"]*"/', $lang, (string) $output, 1, $count );
		return $count ? $out : trim( $output . ' ' . $lang );
	}

	/**
	 * The hreflang links of the current page: locale => address. Empty
	 * unless the page has a published translation.
	 *
	 * @return array<string, string>
	 */
	public static function alternates(): array {
		if ( ! Languages::is_multilingual() || ! is_singular( Translations::POST_TYPES ) ) {
			return array();
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return array();
		}
		$group = array_intersect_key( Translations::group( $post, true ), array_flip( Languages::site_locales() ) );
		if ( count( $group ) < 2 ) {
			return array();
		}
		return array_map( static fn( int $id ): string => (string) get_permalink( $id ), $group );
	}

	/**
	 * `wp_head`: the hreflang links.
	 */
	public static function hreflang(): void {
		$links   = self::alternates();
		$default = Languages::default_locale();
		foreach ( $links as $locale => $url ) {
			printf( '<link rel="alternate" hreflang="%s" href="%s" />' . "\n", esc_attr( Languages::code( $locale ) ), esc_url( $url ) );
		}
		if ( isset( $links[ $default ] ) ) {
			printf( '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url( $links[ $default ] ) );
		}
	}
}
