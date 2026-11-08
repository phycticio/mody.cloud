<?php
/**
 * The site's brand colors in Global Styles, and the ability mody/update-design.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Design;

use Mody\Platform\Brand\BrandProfile;
use Mody\Platform\SiteEditor;
use WP_Error;
use WP_Theme_JSON;
use WP_Theme_JSON_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the brand palette slugs of the current site
 * (documentation/mvp-spec.md sections 6 and 8, ticket M3-02).
 *
 * Colors are written the way the Site Editor saves an edited theme color: the
 * whole theme palette, under `settings.color.palette.theme` of the site's user
 * `wp_global_styles` post (checked against WordPress 7.1's editor on
 * 2026-11-06). Only `primary`, `secondary` and `accent` change; `base` and
 * `contrast` keep their value. Content refers to palette slugs, so every page
 * follows at once. Each save is a Global Styles revision, which the member
 * can restore in the Site Editor.
 */
final class SiteDesign {

	/** The palette slugs that carry the brand colors. */
	public const SLUGS = array( 'primary', 'secondary', 'accent' );

	/** A color as stored: six-digit hex (mvp-spec section 6). JSON Schema syntax, no delimiters. */
	public const HEX_PATTERN = '^#[0-9a-fA-F]{6}$';

	/**
	 * Whether a value is a six-digit hex color.
	 *
	 * @param mixed $value The value.
	 */
	public static function is_hex( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/' . self::HEX_PATTERN . '/', $value );
	}

	/**
	 * The brand colors the current site shows now (theme palette merged with
	 * the site's Global Styles).
	 *
	 * @return array<string, string> Slug => lowercase hex.
	 */
	public static function colors(): array {
		$colors = array();
		foreach ( (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) ) as $entry ) {
			$slug = is_array( $entry ) ? (string) ( $entry['slug'] ?? '' ) : '';
			if ( in_array( $slug, self::SLUGS, true ) ) {
				$colors[ $slug ] = strtolower( (string) ( $entry['color'] ?? '' ) );
			}
		}
		return $colors;
	}

	/**
	 * Writes brand colors to the current site's Global Styles.
	 *
	 * Also for provisioning (M2-01), which applies the chosen preset.
	 *
	 * @param array<string, string> $colors Slug => hex, for slugs in self::SLUGS.
	 * @return true|WP_Error
	 */
	public static function apply( array $colors ): bool|WP_Error {
		$colors = array_intersect_key( $colors, array_flip( self::SLUGS ) );
		if ( ! $colors ) {
			return new WP_Error( 'mody_no_colors', __( 'No color to change.', 'mody_cloud' ) );
		}
		foreach ( $colors as $slug => $hex ) {
			if ( ! self::is_hex( $hex ) ) {
				/* translators: %s: the rejected value. */
				return new WP_Error( 'mody_invalid_color', sprintf( __( '"%s" is not a six-digit hex color such as #ff7f50.', 'mody_cloud' ), is_scalar( $hex ) ? (string) $hex : '' ) );
			}
			$colors[ $slug ] = strtolower( $hex );
		}

		// The resolver keeps its data in static properties, which switch_to_blog() does not reset.
		wp_clean_theme_json_cache();
		$post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'mody_global_styles_missing', __( 'The site\'s styles could not be loaded.', 'mody_cloud' ) );
		}

		// Unreadable content counts as empty, as it does for the resolver and core's REST controller.
		$config = json_decode( $post->post_content, true );
		if ( ! is_array( $config ) || empty( $config['isGlobalStylesUserThemeJSON'] ) ) {
			$config = array();
		}

		// The site's own palette if it has one (Site Editor edits), else the theme's.
		$palette = $config['settings']['color']['palette']['theme'] ?? null;
		if ( ! is_array( $palette ) || ! $palette ) {
			$palette = WP_Theme_JSON_Resolver::get_theme_data()->get_settings()['color']['palette']['theme'] ?? array();
		}
		$missing = $colors;
		foreach ( $palette as $i => $entry ) {
			$slug = is_array( $entry ) ? (string) ( $entry['slug'] ?? '' ) : '';
			if ( isset( $colors[ $slug ] ) ) {
				$palette[ $i ]['color'] = $colors[ $slug ];
				unset( $missing[ $slug ] );
			}
		}
		foreach ( $missing as $slug => $hex ) {
			$palette[] = array(
				'slug'  => $slug,
				'name'  => ucfirst( $slug ),
				'color' => $hex,
			);
		}

		$config['settings']['color']['palette']['theme'] = array_values( $palette );
		return self::save_config( $post, $config, $colors );
	}

	/**
	 * Applies a Mody preset, one of the theme's style variations (M2-06), to
	 * the current site: its Global Styles become the variation's settings and
	 * styles, as when the style is chosen in the Site Editor's Styles. The
	 * member's earlier changes to the styles are replaced, as there; they stay
	 * in the revisions. The default preset empties them: the theme's own style.
	 * The fonts the member installed from the Font Library stay active (the
	 * site's `settings.typography.fontFamilies`, which presets do not define,
	 * M2-07); the Site Editor's own style switch drops them.
	 *
	 * @param string $slug Preset slug (Presets::slugs()).
	 * @return true|WP_Error
	 */
	public static function apply_preset( string $slug ): bool|WP_Error {
		$variation = Presets::variation( $slug );
		if ( null === $variation ) {
			/* translators: %s: the preset names. */
			return new WP_Error( 'mody_unknown_preset', sprintf( __( 'There is no such preset. The presets are: %s.', 'mody_cloud' ), Presets::names() ) );
		}

		wp_clean_theme_json_cache();
		$post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'mody_global_styles_missing', __( 'The site\'s styles could not be loaded.', 'mody_cloud' ) );
		}

		$current = json_decode( $post->post_content, true );
		$fonts   = is_array( $current ) ? ( $current['settings']['typography']['fontFamilies'] ?? null ) : null;
		if ( is_array( $fonts ) && $fonts ) {
			$variation['settings']['typography']['fontFamilies'] = $fonts;
		}
		return self::save_config( $post, array_filter( $variation ), (array) Presets::colors( $slug ) );
	}

	/**
	 * The preset the current site's Global Styles hold unchanged, or null
	 * once the member has changed them (Presets::active()).
	 */
	public static function active_preset(): ?string {
		wp_clean_theme_json_cache();
		$post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$post    = $post_id ? get_post( $post_id ) : null;
		$config  = $post ? json_decode( $post->post_content, true ) : null;
		return Presets::active( is_array( $config ) ? $config : array() );
	}

	/**
	 * Saves a site's Global Styles, then checks that the site shows the colors.
	 *
	 * @param \WP_Post             $post   The site's Global Styles post.
	 * @param array<string, mixed> $config The configuration (settings, styles).
	 * @param array<string, string> $colors The brand colors the site must show.
	 * @return true|WP_Error
	 */
	private static function save_config( \WP_Post $post, array $config, array $colors ): bool|WP_Error {
		$config['isGlobalStylesUserThemeJSON'] = true;
		$config['version']                     = $config['version'] ?? WP_Theme_JSON::LATEST_SCHEMA;

		// Encoded like core's REST controller; kses re-encodes it for users without unfiltered_html.
		// The after-insert hooks save the revision (wp_save_post_revision_on_insert).
		$saved = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ),
				)
			),
			true
		);
		wp_clean_theme_json_cache();
		if ( is_wp_error( $saved ) ) {
			return new WP_Error( 'mody_global_styles_not_saved', __( 'The site\'s styles could not be saved. Please try again.', 'mody_cloud' ) );
		}

		// What the site shows now must be what was asked for.
		if ( array_intersect_key( self::colors(), $colors ) != $colors ) { // phpcs:ignore Universal.Operators.StrictComparisons -- Key order differs.
			return new WP_Error( 'mody_global_styles_not_saved', __( 'The site\'s styles were saved, but the new colors do not show. Please check the styles in the Site Editor.', 'mody_cloud' ) );
		}
		return true;
	}

	/**
	 * Permission callback of mody/update-design.
	 */
	public static function can_update(): bool {
		return is_user_logged_in() && current_user_can( 'edit_theme_options' );
	}

	/**
	 * Execute callback of mody/update-design. Input already validated against
	 * the ability's schema.
	 *
	 * @param mixed $input `preset`, or `primary`, `secondary`, `accent`; and `brand`.
	 * @return array<string, mixed>|WP_Error The old and new colors, and a link to see them.
	 */
	public static function update( $input ): array|WP_Error {
		$input   = is_array( $input ) ? $input : array();
		$profile = BrandProfile::for_site( get_current_blog_id() );
		if ( null === $profile ) {
			return new WP_Error( 'mody_no_brand_profile', __( 'This site has no brand profile yet. Complete the brand wizard first.', 'mody_cloud' ) );
		}

		$preset = (string) ( $input['preset'] ?? '' );
		$colors = array_intersect_key( $input, array_flip( self::SLUGS ) );
		if ( '' !== $preset && $colors ) {
			return new WP_Error( 'mody_preset_or_colors', __( 'Give either a preset or colors, not both.', 'mody_cloud' ) );
		}
		if ( '' !== $preset ) {
			$colors = Presets::colors( $preset );
			if ( null === $colors ) {
				/* translators: %s: the preset names. */
				return new WP_Error( 'mody_unknown_preset', sprintf( __( 'There is no such preset. The presets are: %s.', 'mody_cloud' ), Presets::names() ) );
			}
		}
		if ( ! $colors ) {
			return new WP_Error( 'mody_no_colors', __( 'Give a preset, or at least one of the primary, secondary and accent colors.', 'mody_cloud' ) );
		}

		wp_clean_theme_json_cache(); // This site's palette, even after switch_to_blog().
		$before  = self::colors();
		$applied = '' !== $preset ? self::apply_preset( $preset ) : self::apply( $colors );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$brand = rest_sanitize_boolean( $input['brand'] ?? false ); // "false" is a valid boolean for the schema.
		if ( $brand && ! BrandProfile::update_colors( $profile->id, $colors ) ) {
			return new WP_Error( 'mody_brand_profile_not_updated', __( 'The site\'s colors changed, but the brand profile could not be updated. Please try again.', 'mody_cloud' ) );
		}

		$changes = array();
		foreach ( $colors as $slug => $hex ) {
			$changes[ $slug ] = array(
				'old' => $before[ $slug ] ?? '',
				'new' => strtolower( $hex ),
			);
		}

		// The editor shows the result (M3-09, decided 2026-11-08): Colors after a color
		// change, Browse styles (with the preset active) after a preset.
		$screen = SiteEditor::styles_screen( '' !== $preset ? 'browse' : 'colors' );
		return ( '' !== $preset ? array( 'preset' => $preset ) : array() ) + array(
			'colors'                => $changes,
			'brand_profile_updated' => $brand,
			'url'                   => $screen['url'],
			'label'                 => __( 'See the new colors', 'mody_cloud' ),
			'place'                 => $screen['place'],
			'navigate'              => true,
		);
	}
}
