<?php
/**
 * The Font Library: Mody's own collection of free fonts, nothing from outside (rule 1).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Design;

defined( 'ABSPATH' ) || exit;

/**
 * Typography for members' sites (tickets M2-06, M2-07).
 *
 * - **No Google Fonts.** WordPress registers a "Google Fonts" collection in
 *   the Site Editor's Font Library (Styles → Typography → Fonts). Its list
 *   and previews load from wordpress.org and its files from Google, outside
 *   Europe, so it is removed on every site (2026-11-07).
 * - **The Mody fonts collection** (M2-07): ten families of free fonts
 *   (SIL Open Font License) whose files live in this plugin
 *   (`fonts/<slug>/`, with each license and `fonts/sources.json`, the commit
 *   each file came from). A member installs one from the Font Library: the
 *   editor copies its files into the site's own font directory. The URLs
 *   are built on the current site's host, so the editor fetches them from
 *   its own origin.
 * - **Inter, the theme's font, is real Inter**: the theme's `inter` family
 *   gets its font faces from here, instead of falling back to the system
 *   font. theme.json is not edited.
 */
final class Fonts {

	/** Font collections WordPress registers that load from outside the network. */
	public const REMOVED_COLLECTIONS = array( 'google-fonts' );

	/** The Mody collection's slug. */
	public const COLLECTION = 'mody-fonts';

	/** The collection's categories, in display order. */
	public const CATEGORIES = array(
		'sans-serif' => 'Sans serif',
		'serif'      => 'Serif',
		'display'    => 'Display',
		'monospace'  => 'Monospace',
	);

	/**
	 * The families: name, category, fallback stack, and faces (file, weight, style).
	 * Chosen on 2026-11-07 (proposed, the maintainer approved the download).
	 */
	public const FAMILIES = array(
		'inter'          => array(
			'name'     => 'Inter',
			'category' => 'sans-serif',
			'fallback' => 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
			'faces'    => array(
				array( 'InterVariable.woff2', '100 900', 'normal' ),
				array( 'InterVariable-Italic.woff2', '100 900', 'italic' ),
			),
		),
		'ibm-plex-sans'  => array(
			'name'     => 'IBM Plex Sans',
			'category' => 'sans-serif',
			'fallback' => 'system-ui, sans-serif',
			'faces'    => array(
				array( 'IBMPlexSansVar-Roman.woff2', '100 700', 'normal' ),
				array( 'IBMPlexSansVar-Italic.woff2', '100 700', 'italic' ),
			),
		),
		'work-sans'      => array(
			'name'     => 'Work Sans',
			'category' => 'sans-serif',
			'fallback' => 'system-ui, sans-serif',
			'faces'    => array(
				array( 'WorkSans-Regular.woff2', '400', 'normal' ),
				array( 'WorkSans-Italic.woff2', '400', 'italic' ),
				array( 'WorkSans-SemiBold.woff2', '600', 'normal' ),
				array( 'WorkSans-Bold.woff2', '700', 'normal' ),
			),
		),
		'space-grotesk'  => array(
			'name'     => 'Space Grotesk',
			'category' => 'sans-serif',
			'fallback' => 'system-ui, sans-serif',
			'faces'    => array(
				array( 'SpaceGrotesk-Variable.woff2', '300 700', 'normal' ),
			),
		),
		'lora'           => array(
			'name'     => 'Lora',
			'category' => 'serif',
			'fallback' => 'Georgia, serif',
			'faces'    => array(
				array( 'Lora-Variable.woff2', '400 700', 'normal' ),
				array( 'Lora-Italic-Variable.woff2', '400 700', 'italic' ),
			),
		),
		'merriweather'   => array(
			'name'     => 'Merriweather',
			'category' => 'serif',
			'fallback' => 'Georgia, serif',
			'faces'    => array(
				array( 'Merriweather-Regular.woff2', '400', 'normal' ),
				array( 'Merriweather-Italic.woff2', '400', 'italic' ),
				array( 'Merriweather-Bold.woff2', '700', 'normal' ),
				array( 'Merriweather-BoldItalic.woff2', '700', 'italic' ),
			),
		),
		'eb-garamond'    => array(
			'name'     => 'EB Garamond',
			'category' => 'serif',
			'fallback' => 'Garamond, Georgia, serif',
			'faces'    => array(
				array( 'EBGaramond-Regular.woff2', '400', 'normal' ),
				array( 'EBGaramond-Italic.woff2', '400', 'italic' ),
				array( 'EBGaramond-Bold.woff2', '700', 'normal' ),
			),
		),
		'playfair'       => array(
			'name'     => 'Playfair',
			'category' => 'display',
			'fallback' => 'Georgia, serif',
			'faces'    => array(
				array( 'Playfair-Variable.woff2', '300 900', 'normal' ),
			),
		),
		'fraunces'       => array(
			'name'     => 'Fraunces',
			'category' => 'display',
			'fallback' => 'Georgia, serif',
			'faces'    => array(
				array( 'Fraunces-Regular.woff2', '400', 'normal' ),
				array( 'Fraunces-Italic.woff2', '400', 'italic' ),
				array( 'Fraunces-SemiBold.woff2', '600', 'normal' ),
				array( 'Fraunces-Bold.woff2', '700', 'normal' ),
			),
		),
		'jetbrains-mono' => array(
			'name'     => 'JetBrains Mono',
			'category' => 'monospace',
			'fallback' => 'ui-monospace, Menlo, Consolas, monospace',
			'faces'    => array(
				array( 'JetBrainsMono-Variable.woff2', '100 800', 'normal' ),
				array( 'JetBrainsMono-Italic-Variable.woff2', '100 800', 'italic' ),
			),
		),
	);

	/**
	 * Hooked to `init` after core registers its default collections (priority 10).
	 */
	public static function remove_collections(): void {
		if ( ! function_exists( 'wp_unregister_font_collection' ) ) {
			return;
		}
		foreach ( self::REMOVED_COLLECTIONS as $slug ) {
			if ( isset( \WP_Font_Library::get_instance()->get_font_collections()[ $slug ] ) ) {
				wp_unregister_font_collection( $slug );
			}
		}
	}

	/**
	 * Hooked to `init`: registers the Mody fonts collection.
	 */
	public static function register_collection(): void {
		if ( ! function_exists( 'wp_register_font_collection' ) || isset( \WP_Font_Library::get_instance()->get_font_collections()[ self::COLLECTION ] ) ) {
			return;
		}
		$families = array();
		foreach ( self::FAMILIES as $slug => $family ) {
			$families[] = array(
				'font_family_settings' => array(
					'name'       => $family['name'],
					'slug'       => $slug,
					'fontFamily' => self::stack( $slug ),
					'fontFace'   => self::faces( $slug ),
				),
				'categories'           => array( $family['category'] ),
			);
		}
		$categories = array();
		foreach ( self::CATEGORIES as $slug => $name ) {
			$categories[] = array(
				'slug' => $slug,
				'name' => $name,
			);
		}
		wp_register_font_collection(
			self::COLLECTION,
			array(
				'name'          => __( 'Mody fonts', 'mody_cloud' ),
				'description'   => __( 'Free fonts (SIL Open Font License), served by Mody Cloud. Install one, then choose it in Typography.', 'mody_cloud' ),
				'font_families' => $families,
				'categories'    => $categories,
			)
		);
	}

	/**
	 * Filters `wp_theme_json_data_theme`: the theme's Inter gets its font faces.
	 *
	 * @param \WP_Theme_JSON_Data $theme_json The theme's data.
	 * @return \WP_Theme_JSON_Data
	 */
	public static function theme_fonts( $theme_json ) {
		$data     = $theme_json->get_data();
		$families = (array) ( $data['settings']['typography']['fontFamilies']['theme'] ?? $data['settings']['typography']['fontFamilies'] ?? array() );
		$changed  = false;
		foreach ( $families as $i => $family ) {
			if ( is_array( $family ) && isset( self::FAMILIES[ $family['slug'] ?? '' ] ) && empty( $family['fontFace'] ) ) {
				$families[ $i ]['fontFace'] = self::faces( (string) $family['slug'] );
				$changed                    = true;
			}
		}
		if ( ! $changed ) {
			return $theme_json;
		}
		return $theme_json->update_with(
			array(
				'version'  => \WP_Theme_JSON::LATEST_SCHEMA,
				'settings' => array( 'typography' => array( 'fontFamilies' => array_values( $families ) ) ),
			)
		);
	}

	/**
	 * A family's font faces, with URLs on the current site's host.
	 *
	 * @param string $slug Family slug.
	 * @return list<array{fontFamily: string, fontStyle: string, fontWeight: string, src: string}>
	 */
	public static function faces( string $slug ): array {
		$faces = array();
		foreach ( self::FAMILIES[ $slug ]['faces'] ?? array() as list( $file, $weight, $style ) ) {
			$faces[] = array(
				'fontFamily' => self::FAMILIES[ $slug ]['name'],
				'fontStyle'  => $style,
				'fontWeight' => $weight,
				'src'        => self::url( $slug, $file ),
			);
		}
		return $faces;
	}

	/**
	 * A font file's URL: this plugin's `fonts/<slug>/<file>`.
	 *
	 * @param string $slug Family slug.
	 * @param string $file File name.
	 */
	public static function url( string $slug, string $file ): string {
		return plugins_url( 'fonts/' . $slug . '/' . $file, dirname( __DIR__, 2 ) . '/mody-platform.php' );
	}

	/**
	 * A font file's path on disk.
	 *
	 * @param string $slug Family slug.
	 * @param string $file File name.
	 */
	public static function path( string $slug, string $file ): string {
		return dirname( __DIR__, 2 ) . '/fonts/' . $slug . '/' . $file;
	}

	/**
	 * A family's CSS font stack: its name, then its fallbacks.
	 *
	 * @param string $slug Family slug.
	 */
	public static function stack( string $slug ): string {
		$family = self::FAMILIES[ $slug ];
		return ( str_contains( $family['name'], ' ' ) ? '"' . $family['name'] . '"' : $family['name'] ) . ', ' . $family['fallback'];
	}
}
