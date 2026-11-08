<?php
/**
 * The Mody design presets: the theme's style variations.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Design;

use WP_Theme_JSON_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * The presets of documentation/mvp-spec.md section 6, read from the theme
 * (decided 2026-11-07, ticket M2-06).
 *
 * Mody Teal is the theme's own `theme.json` (the Site Editor's "Default");
 * every other preset is a style variation in `themes/theme/styles/*.json`,
 * with its palette, typography, spacing and element styles. The Site Editor
 * lists them under Styles → Browse styles, and each site can pick and edit
 * them. This class is the single source for the brand wizard (M2-03),
 * provisioning (M2-01) and the agent's `mody/update-design` (M3-02): adding a
 * file to `styles/` adds a preset everywhere.
 */
final class Presets {

	/** The preset a new site starts with: the theme's own style. */
	public const DEFAULT = 'mody-teal';

	/** The default style's name. The Site Editor calls it "Default". */
	public const DEFAULT_NAME = 'Mody Teal';

	/**
	 * Every preset with a complete brand palette, the default first, then
	 * the variations in the order WordPress lists them.
	 *
	 * @return array<string, array{name: string, primary: string, secondary: string, accent: string}> Keyed by slug.
	 */
	public static function all(): array {
		$theme   = WP_Theme_JSON_Resolver::get_theme_data( array(), array( 'with_supports' => false ) )->get_settings();
		$presets = array();
		$colors  = self::brand_colors( $theme['color']['palette'] ?? array() );
		if ( $colors ) {
			$presets[ self::DEFAULT ] = array( 'name' => self::DEFAULT_NAME ) + $colors;
		}
		foreach ( self::variations() as $slug => $variation ) {
			$colors = self::brand_colors( $variation['settings']['color']['palette'] ?? array() );
			if ( $colors ) {
				$presets[ $slug ] = array( 'name' => (string) $variation['title'] ) + $colors;
			}
		}
		return $presets;
	}

	/**
	 * The theme's style variations, keyed by slug (the title, sanitized:
	 * "Forest" is `forest`).
	 *
	 * @return array<string, array{title: string, settings?: array, styles?: array}>
	 */
	public static function variations(): array {
		$variations = array();
		foreach ( WP_Theme_JSON_Resolver::get_style_variations() as $variation ) {
			$slug = sanitize_title( (string) ( $variation['title'] ?? '' ) );
			if ( '' !== $slug && self::DEFAULT !== $slug ) {
				$variations[ $slug ] = $variation;
			}
		}
		return $variations;
	}

	/**
	 * A preset's style, as the site's Global Styles hold it once chosen:
	 * the variation's settings and styles. The default is the theme's own
	 * style, so its Global Styles are empty.
	 *
	 * @param string $slug Preset slug.
	 * @return array{settings: array, styles: array}|null Null for an unknown slug.
	 */
	public static function variation( string $slug ): ?array {
		if ( self::DEFAULT === $slug ) {
			return array(
				'settings' => array(),
				'styles'   => array(),
			);
		}
		$variation = self::variations()[ $slug ] ?? null;
		if ( null === $variation || null === self::colors( $slug ) ) {
			return null;
		}
		return array(
			'settings' => (array) ( $variation['settings'] ?? array() ),
			'styles'   => (array) ( $variation['styles'] ?? array() ),
		);
	}

	/**
	 * The preset slugs, in display order.
	 *
	 * @return list<string>
	 */
	public static function slugs(): array {
		return array_keys( self::all() );
	}

	/**
	 * The three brand colors of a preset.
	 *
	 * @param string $slug Preset slug.
	 * @return array{primary: string, secondary: string, accent: string}|null Null for an unknown slug.
	 */
	public static function colors( string $slug ): ?array {
		$preset = self::all()[ $slug ] ?? null;
		return null === $preset ? null : array_intersect_key( $preset, array_flip( SiteDesign::SLUGS ) );
	}

	/**
	 * The preset names, for the agent: "Mody Teal (mody-teal), Forest (forest), …".
	 */
	public static function names(): string {
		$names = array();
		foreach ( self::all() as $slug => $preset ) {
			$names[] = sprintf( '%s (%s)', $preset['name'], $slug );
		}
		return implode( ', ', $names );
	}

	/**
	 * The preset a site's Global Styles hold, if they hold one unchanged:
	 * its settings and styles are exactly a variation's, or empty for the
	 * default. Null once the member has changed something, as the Site
	 * Editor then shows no style as active. The site's font families (fonts
	 * installed from the Font Library) do not count: presets define none (M2-07).
	 *
	 * @param array<string, mixed> $config The site's Global Styles (decoded post content).
	 */
	public static function active( array $config ): ?string {
		$settings = (array) ( $config['settings'] ?? array() );
		unset( $settings['typography']['fontFamilies'] );
		if ( isset( $settings['typography'] ) && ! $settings['typography'] ) {
			unset( $settings['typography'] );
		}
		$current = array(
			'settings' => $settings,
			'styles'   => (array) ( $config['styles'] ?? array() ),
		);
		foreach ( self::slugs() as $slug ) {
			if ( self::same( self::variation( $slug ), $current ) ) {
				return $slug;
			}
		}
		return null;
	}

	/**
	 * Whether two configurations are equal, whatever their key order.
	 *
	 * @param array<string, mixed>|null $a A configuration.
	 * @param array<string, mixed>|null $b Another.
	 */
	private static function same( ?array $a, ?array $b ): bool {
		$sort = static function ( $value ) use ( &$sort ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}
			if ( ! array_is_list( $value ) ) {
				ksort( $value );
			}
			return array_map( $sort, $value );
		};
		return null !== $a && null !== $b && $sort( $a ) === $sort( $b );
	}

	/**
	 * The three brand colors from a palette, as a theme.json file or the
	 * resolver writes it (a list, or a list per origin).
	 *
	 * @param array<int|string, mixed> $palette The palette.
	 * @return array{primary: string, secondary: string, accent: string}|array{} Empty unless all three are hex.
	 */
	private static function brand_colors( array $palette ): array {
		$entries = isset( $palette['theme'] ) && is_array( $palette['theme'] ) ? $palette['theme'] : $palette;
		$colors  = array();
		foreach ( $entries as $entry ) {
			$slug = is_array( $entry ) ? (string) ( $entry['slug'] ?? '' ) : '';
			if ( in_array( $slug, SiteDesign::SLUGS, true ) && SiteDesign::is_hex( $entry['color'] ?? '' ) ) {
				$colors[ $slug ] = strtolower( $entry['color'] );
			}
		}
		return count( $colors ) === count( SiteDesign::SLUGS ) ? array_merge( array_flip( SiteDesign::SLUGS ), $colors ) : array();
	}
}
