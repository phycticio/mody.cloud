<?php
/**
 * Header and footer per language.
 *
 * A template part named after the original plus the language's prefix
 * (header-es) replaces the original on that language's pages. Copies are
 * made from the matrix (SettingsPage) and edited in the Site Editor like any
 * other template part.
 *
 * @package Mody\Languages
 */

namespace Mody\Languages;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Swaps template parts for their copy in the request's language.
 */
final class Parts {

	/** The template parts the matrix offers to translate. */
	public const TRANSLATABLE = array( 'header', 'footer' );

	/**
	 * Hooked on load.
	 */
	public static function register(): void {
		add_filter( 'render_block_data', array( self::class, 'swap' ) );
	}

	/**
	 * A part's copy for a language: header-es.
	 *
	 * @param string $slug   A template part's slug.
	 * @param string $locale A language.
	 */
	public static function copy_slug( string $slug, string $locale ): string {
		return $slug . '-' . Languages::slug( $locale );
	}

	/**
	 * A template part of the active theme, or null.
	 *
	 * @param string $slug A template part's slug.
	 */
	public static function find( string $slug ): ?\WP_Block_Template {
		return get_block_template( get_stylesheet() . '//' . $slug, 'wp_template_part' );
	}

	/**
	 * `render_block_data`: a template part in the request's language.
	 *
	 * @param array<string, mixed> $block A parsed block.
	 * @return array<string, mixed>
	 */
	public static function swap( $block ) {
		if ( ! Router::is_other() || 'core/template-part' !== ( $block['blockName'] ?? '' ) || empty( $block['attrs']['slug'] ) ) {
			return $block;
		}
		$theme = (string) ( $block['attrs']['theme'] ?? get_stylesheet() );
		if ( get_stylesheet() !== $theme ) {
			return $block;
		}
		$copy = self::copy_slug( (string) $block['attrs']['slug'], Router::current() );
		if ( self::find( $copy ) ) {
			$block['attrs']['slug'] = $copy;
		}
		return $block;
	}

	/**
	 * Creates a part's copy for a language, from the part as the site has it.
	 *
	 * @param string $slug   header or footer.
	 * @param string $locale One of the site's other languages.
	 * @return int|WP_Error The copy's post ID.
	 */
	public static function create( string $slug, string $locale ): int|WP_Error {
		$part = in_array( $slug, self::TRANSLATABLE, true ) ? self::find( $slug ) : null;
		if ( ! $part ) {
			return new WP_Error( 'mody_languages_no_part', __( 'This template part cannot be translated.', 'mody_cloud' ) );
		}
		if ( ! Languages::is_other( $locale ) ) {
			return new WP_Error( 'mody_languages_not_a_language', __( 'This is not one of the site\'s other languages.', 'mody_cloud' ) );
		}
		$copy = self::copy_slug( $slug, $locale );
		if ( self::find( $copy ) ) {
			return new WP_Error( 'mody_languages_exists', __( 'This template part already has a copy in that language.', 'mody_cloud' ) );
		}
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'wp_template_part',
					'post_status'  => 'publish',
					'post_name'    => $copy,
					/* translators: 1: a template part's title, such as Header; 2: a language's own name. */
					'post_title'   => sprintf( __( '%1$s (%2$s)', 'mody_cloud' ), $part->title, Languages::label( $locale ) ),
					'post_content' => $part->content,
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		wp_set_post_terms( $id, array( get_stylesheet() ), 'wp_theme' );
		wp_set_post_terms( $id, array( $part->area ), 'wp_template_part_area' );
		return (int) $id;
	}
}
