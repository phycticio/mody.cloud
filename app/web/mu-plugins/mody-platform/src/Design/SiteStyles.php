<?php
/**
 * Every part of the site's styles, and the ability mody/update-styles (M3-10).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Design;

use Mody\Platform\SiteEditor;
use WP_Block_Type_Registry;
use WP_Error;
use WP_Theme_JSON;
use WP_Theme_JSON_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * A guarded patch of the current site's Global Styles (ticket M3-10, part A).
 *
 * The site's design is its user `wp_global_styles` post: the theme.json data
 * Editor → Styles reads and writes. `changes` is a partial theme.json object,
 * merged into it the way a JSON merge patch is (objects key by key, `null`
 * removes a key, so the theme's value shows again). Preset lists (palette,
 * font sizes, font families, spacing sizes) merge entry by entry, by slug.
 *
 * The guard, before anything is saved:
 * - only the style groups and settings in the constants below;
 * - the theme's presets keep their slugs (templates, patterns and pages use
 *   them): their values change, nothing is added or removed. The member's
 *   own presets (the `custom` origin, where Styles → Palette puts them) are
 *   added, renamed and removed freely (decided 2026-11-08);
 * - styles name colors by palette slug; hex is accepted only in the palette;
 * - font families only from the site's Font Library: a family that is
 *   installed but not active is activated, its files built from the Font
 *   Library, never from the input;
 * - no CSS (`css` anywhere), no URLs, no unknown keys, a size limit.
 *
 * Saved as SiteDesign saves: a Global Styles revision, then read back.
 */
final class SiteStyles {

	/** The largest `changes` accepted, JSON-encoded. */
	public const MAX_BYTES = 8000;

	/** Style groups a member may change, in any style node. */
	public const STYLE_GROUPS = array( 'color', 'typography', 'spacing', 'border', 'shadow', 'dimensions' );

	/**
	 * Preset lists a member may change: settings path => the preset type (in
	 * `var:preset|<type>|<slug>`) and the entry's value key.
	 */
	public const PRESETS = array(
		'color.palette'           => array( 'color', 'color' ),
		'typography.fontSizes'    => array( 'font-size', 'size' ),
		'typography.fontFamilies' => array( 'font-family', 'fontFamily' ),
		'spacing.spacingSizes'    => array( 'spacing', 'size' ),
	);

	/** Layout settings a member may change. */
	public const LAYOUT = array( 'contentSize', 'wideSize' );

	/** Color values that name no color. */
	private const COLOR_KEYWORDS = array( 'transparent', 'inherit', 'currentcolor', 'initial', 'unset' );

	/** Preset types a style value may refer to. */
	private const REF_TYPES = 'color|gradient|font-size|font-family|spacing|shadow';

	/** A preset or custom color's slug. */
	private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';

	/** Paths listed in a result, at most. */
	private const LISTED_CHANGES = 30;

	/**
	 * Permission callback of mody/update-styles.
	 */
	public static function can_update(): bool {
		return is_user_logged_in() && current_user_can( 'edit_theme_options' );
	}

	/**
	 * Execute callback of mody/update-styles.
	 *
	 * @param mixed $input `changes`: a partial theme.json object (`styles`, `settings`).
	 * @return array<string, mixed>|WP_Error What changed (path, old, new) and the Styles screen that shows it.
	 */
	public static function update( $input ): array|WP_Error {
		$changes = is_array( $input ) ? ( $input['changes'] ?? null ) : null;
		if ( is_string( $changes ) ) {
			$changes = json_decode( $changes, true ); // Some models send the object as a string.
		}
		if ( ! is_array( $changes ) || ! $changes || array_is_list( $changes ) ) {
			return self::error( 'mody_no_changes', __( 'Give `changes`: an object with `styles` and/or `settings`.', 'mody_cloud' ) );
		}
		if ( strlen( (string) wp_json_encode( $changes ) ) > self::MAX_BYTES ) {
			/* translators: %d: a number of bytes. */
			return self::error( 'mody_changes_too_large', sprintf( __( 'The changes are too large (at most %d bytes). Change fewer styles at a time.', 'mody_cloud' ), self::MAX_BYTES ) );
		}
		$unknown = array_diff( array_keys( $changes ), array( 'styles', 'settings' ) );
		if ( $unknown ) {
			/* translators: %s: the refused keys. */
			return self::error( 'mody_styles_not_allowed', sprintf( __( 'Only `styles` and `settings` can change, not: %s.', 'mody_cloud' ), implode( ', ', $unknown ) ) );
		}

		$loaded = SiteDesign::user_styles();
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $post, $before ) = $loaded;

		$errors = array();
		$after  = $before;

		// Settings: preset lists by slug, and the two layout widths.
		$removed = array();
		if ( array_key_exists( 'settings', $changes ) ) {
			$after = self::apply_settings( $after, $changes['settings'], $removed, $errors );
		}

		// Styles: checked node by node, then merged.
		$style_patch = $changes['styles'] ?? array();
		if ( array_key_exists( 'styles', $changes ) ) {
			if ( ! is_array( $style_patch ) || ( $style_patch && array_is_list( $style_patch ) ) ) {
				$errors[] = __( '`styles` must be an object.', 'mody_cloud' );
			} else {
				self::check_node( $style_patch, 'styles', 'root', '', $errors );
			}
		}
		if ( $errors ) {
			return self::refuse( $errors );
		}
		if ( is_array( $style_patch ) && $style_patch ) {
			$style_patch     = self::normalize( $style_patch );
			$after['styles'] = self::prune( self::merge( (array) ( $after['styles'] ?? array() ), $style_patch ) );
			if ( ! $after['styles'] ) {
				unset( $after['styles'] );
			}
		}

		// Every preset a changed value names must exist; fonts from the Font Library are activated.
		$after = self::check_references( $after, is_array( $style_patch ) ? $style_patch : array(), $removed, $errors );
		if ( $errors ) {
			return self::refuse( $errors );
		}

		// What WordPress keeps: nothing in the changes may be dropped by its own sanitizing.
		$dropped = self::dropped_by_sanitize( $after );
		if ( $dropped ) {
			/* translators: %s: style paths. */
			return self::refuse( array( sprintf( __( 'WordPress does not know these styles: %s.', 'mody_cloud' ), implode( ', ', $dropped ) ) ) );
		}

		$diff = self::diff( self::flatten_config( $before ), self::flatten_config( $after ) );
		if ( ! $diff ) {
			return self::error( 'mody_no_change', __( 'Nothing changed: the site\'s styles already have these values.', 'mody_cloud' ) );
		}

		$saved = SiteDesign::save( $post, $after );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Read back: kses (members have no unfiltered_html) must have kept every change.
		$reloaded = SiteDesign::user_styles();
		if ( is_wp_error( $reloaded ) ) {
			return $reloaded;
		}
		$now  = self::flatten_config( $reloaded[1] );
		$lost = array();
		foreach ( $diff as $path => $values ) {
			if ( ( $now[ $path ] ?? null ) !== $values['new'] ) {
				$lost[] = $path;
			}
		}
		if ( $lost ) {
			/* translators: %s: style paths. */
			return self::error( 'mody_styles_not_kept', sprintf( __( 'The styles were saved, but these changes did not survive WordPress\'s safety filter: %s. Check Styles → Revisions in the Site Editor.', 'mody_cloud' ), implode( ', ', array_slice( $lost, 0, 10 ) ) ) );
		}

		$theme   = self::flatten_config( WP_Theme_JSON_Resolver::get_theme_data()->get_raw_data() );
		$changed = array();
		foreach ( array_slice( $diff, 0, self::LISTED_CHANGES, true ) as $path => $values ) {
			$changed[] = array(
				'path' => $path,
				'old'  => $values['old'] ?? ( $theme[ $path ] ?? '' ),
				'new'  => $values['new'] ?? '',
			);
		}

		$screen = self::screen_for( array_keys( $diff ) );
		return array(
			'changed'  => $changed,
			'url'      => $screen['url'],
			'label'    => __( 'See the new styles', 'mody_cloud' ),
			'place'    => $screen['place'],
			'navigate' => true,
		);
	}

	/**
	 * What the agent needs to change the styles from what is there (part B):
	 * the styles the site shows (the theme's, with the owner's changes over
	 * them), its presets, the fonts it can use and the two widths.
	 *
	 * @return array{styles: array<string, string>, palette: array<string, string>, custom: array<string, string>, font_sizes: array<string, string>, spacing: array<string, string>, fonts: list<string>, layout: array<string, string>}
	 */
	public static function overview(): array {
		$loaded = SiteDesign::user_styles();
		$user   = is_wp_error( $loaded ) ? array() : $loaded[1];
		$theme  = WP_Theme_JSON_Resolver::get_theme_data()->get_raw_data();
		$styles = array();
		foreach ( self::flatten( self::merge( (array) ( $theme['styles'] ?? array() ), self::normalize( (array) ( $user['styles'] ?? array() ) ) ), 'styles' ) as $path => $value ) {
			if ( is_scalar( $value ) ) {
				// Shown as the agent writes them: var:preset|color|accent.
				$styles[ substr( $path, 7 ) ] = (string) preg_replace( '/var\(--wp--preset--(' . self::REF_TYPES . ')--([a-z0-9-]+)\)/', 'var:preset|$1|$2', (string) $value );
			}
		}

		$list = static function ( string $path, string $value_key, string $origin ) use ( $user ): array {
			list( $group, $key ) = explode( '.', $path );
			$entries             = $user['settings'][ $group ][ $key ][ $origin ] ?? ( 'theme' === $origin ? self::theme_presets( $path ) : array() );
			$out                 = array();
			foreach ( (array) $entries as $entry ) {
				if ( is_array( $entry ) && isset( $entry['slug'] ) ) {
					$out[ (string) $entry['slug'] ] = (string) ( $entry[ $value_key ] ?? '' );
				}
			}
			return $out;
		};

		$fonts = array_merge(
			array_keys( $list( 'typography.fontFamilies', 'name', 'theme' ) ),
			array_keys( $list( 'typography.fontFamilies', 'name', 'custom' ) ),
			wp_list_pluck(
				get_posts(
					array(
						'post_type'      => 'wp_font_family',
						'post_status'    => 'publish',
						'posts_per_page' => 30,
						'orderby'        => 'title',
						'order'          => 'ASC',
					)
				),
				'post_name'
			)
		);

		$layout = array();
		foreach ( self::LAYOUT as $key ) {
			$layout[ $key ] = (string) ( $user['settings']['layout'][ $key ] ?? $theme['settings']['layout'][ $key ] ?? '' );
		}

		return array(
			'styles'     => $styles,
			'palette'    => $list( 'color.palette', 'color', 'theme' ),
			'custom'     => $list( 'color.palette', 'color', 'custom' ),
			'font_sizes' => $list( 'typography.fontSizes', 'size', 'theme' ) + $list( 'typography.fontSizes', 'size', 'custom' ),
			'spacing'    => $list( 'spacing.spacingSizes', 'size', 'theme' ) + $list( 'spacing.spacingSizes', 'size', 'custom' ),
			'fonts'      => array_values( array_unique( $fonts ) ),
			'layout'     => $layout,
		);
	}

	/*
	 * Settings.
	 */

	/**
	 * Applies `settings`: preset entries by slug, and the layout widths.
	 *
	 * @param array<string, mixed> $config  The site's configuration.
	 * @param mixed                $patch   The `settings` changes.
	 * @param array<string, true>  $removed Filled with "type|slug" of removed presets.
	 * @param list<string>         $errors  Filled with what is refused.
	 * @return array<string, mixed> The configuration with the changes.
	 */
	private static function apply_settings( array $config, $patch, array &$removed, array &$errors ): array {
		if ( ! is_array( $patch ) || ( $patch && array_is_list( $patch ) ) ) {
			$errors[] = __( '`settings` must be an object.', 'mody_cloud' );
			return $config;
		}
		$allowed = array(
			'color'      => array( 'palette' ),
			'typography' => array( 'fontSizes', 'fontFamilies' ),
			'spacing'    => array( 'spacingSizes' ),
			'layout'     => self::LAYOUT,
		);
		foreach ( $patch as $group => $values ) {
			if ( ! isset( $allowed[ $group ] ) || ! is_array( $values ) || array_is_list( $values ) ) {
				$errors[] = self::not_allowed( 'settings.' . $group );
				continue;
			}
			foreach ( $values as $key => $value ) {
				$path = $group . '.' . $key;
				if ( ! in_array( $key, $allowed[ $group ], true ) ) {
					$errors[] = self::not_allowed( 'settings.' . $path );
				} elseif ( 'layout' === $group ) {
					if ( null === $value ) {
						unset( $config['settings']['layout'][ $key ] );
					} elseif ( ! is_string( $value ) || ! self::is_safe( $value ) || '' === trim( $value ) ) {
						/* translators: %s: a settings path. */
						$errors[] = sprintf( __( '`settings.%s` must be a CSS width such as 800px.', 'mody_cloud' ), $path );
					} else {
						$config['settings']['layout'][ $key ] = trim( $value );
					}
				} else {
					$config = self::apply_presets( $config, $path, $value, $removed, $errors );
				}
			}
		}
		foreach ( array( 'layout', 'color', 'typography', 'spacing' ) as $group ) {
			if ( isset( $config['settings'][ $group ] ) && ! self::prune( (array) $config['settings'][ $group ] ) ) {
				unset( $config['settings'][ $group ] );
			}
		}
		if ( isset( $config['settings'] ) && ! $config['settings'] ) {
			unset( $config['settings'] );
		}
		return $config;
	}

	/**
	 * Merges preset entries by slug: the theme's slugs change their value
	 * only; other slugs are the member's own (the custom origin).
	 *
	 * @param array<string, mixed> $config  The site's configuration.
	 * @param string               $path    A PRESETS key, such as `color.palette`.
	 * @param mixed                $patch   A list of entries, or lists keyed by origin.
	 * @param array<string, true>  $removed Filled with "type|slug" of removed presets.
	 * @param list<string>         $errors  Filled with what is refused.
	 * @return array<string, mixed>
	 */
	private static function apply_presets( array $config, string $path, $patch, array &$removed, array &$errors ): array {
		list( $type, $value_key ) = self::PRESETS[ $path ];
		list( $group, $key )      = explode( '.', $path );
		$label                    = 'settings.' . $path;

		// A plain list (theme.json's own form), or lists keyed by origin.
		$entries = array();
		if ( is_array( $patch ) && array_is_list( $patch ) ) {
			$entries = $patch;
		} elseif ( is_array( $patch ) ) {
			foreach ( $patch as $origin => $list ) {
				if ( ! in_array( $origin, array( 'theme', 'custom' ), true ) || ! is_array( $list ) || ! array_is_list( $list ) ) {
					$errors[] = self::not_allowed( $label . '.' . $origin );
					continue;
				}
				$entries = array_merge( $entries, $list );
			}
		} else {
			/* translators: %s: a settings path. */
			$errors[] = sprintf( __( '`%s` must be a list of entries with a slug.', 'mody_cloud' ), $label );
			return $config;
		}

		$theme_list = self::theme_presets( $path );
		$fixed      = array_column( $theme_list, 'slug' );
		$user       = (array) ( $config['settings'][ $group ][ $key ] ?? array() );
		$own_theme  = isset( $user['theme'] ) && is_array( $user['theme'] ) && $user['theme'] ? array_values( $user['theme'] ) : $theme_list;
		$custom     = isset( $user['custom'] ) && is_array( $user['custom'] ) ? array_values( $user['custom'] ) : array();
		$theme_set  = false;

		foreach ( $entries as $entry ) {
			$slug = is_array( $entry ) ? ( $entry['slug'] ?? null ) : null;
			if ( ! is_string( $slug ) || ! preg_match( self::SLUG_PATTERN, $slug ) ) {
				/* translators: %s: a settings path. */
				$errors[] = sprintf( __( 'Every entry of `%s` needs a slug of lowercase letters, digits and hyphens.', 'mody_cloud' ), $label );
				continue;
			}
			$remove = ! empty( $entry['remove'] );

			if ( in_array( $slug, $fixed, true ) ) {
				// The theme's presets: the value changes, the slug stays (decided 2026-11-08).
				if ( 'font-family' === $type ) {
					/* translators: %s: a font slug. */
					$errors[] = sprintf( __( 'The theme\'s font "%s" cannot change. Install another family in the Font Library and use it instead.', 'mody_cloud' ), $slug );
					continue;
				}
				if ( $remove || ! array_key_exists( $value_key, $entry ) ) {
					/* translators: 1: a slug, 2: a settings path. */
					$errors[] = sprintf( __( '"%1$s" is one of the theme\'s presets in `%2$s`: it stays, only its value changes.', 'mody_cloud' ), $slug, $label );
					continue;
				}
				$value = self::preset_value( $type, $entry[ $value_key ], $slug, $errors );
				if ( null === $value ) {
					continue;
				}
				foreach ( $own_theme as $i => $existing ) {
					if ( ( $existing['slug'] ?? '' ) === $slug ) {
						$own_theme[ $i ][ $value_key ] = $value;
						if ( 'font-size' === $type && array_key_exists( 'fluid', $entry ) ) {
							$own_theme[ $i ]['fluid'] = self::fluid( $entry['fluid'], $slug, $errors );
						}
					}
				}
				$theme_set = true;
				continue;
			}

			// The member's own presets.
			$index = null;
			foreach ( $custom as $i => $existing ) {
				if ( ( $existing['slug'] ?? '' ) === $slug ) {
					$index = $i;
				}
			}
			if ( $remove ) {
				if ( null !== $index ) {
					unset( $custom[ $index ] );
					$custom                         = array_values( $custom );
					$removed[ $type . '|' . $slug ] = true;
				}
				continue;
			}
			if ( 'font-family' === $type ) {
				$family = self::library_family( $slug );
				if ( null === $family ) {
					$errors[] = self::font_missing( $slug );
				} elseif ( null === $index ) {
					$custom[] = $family;
				}
				continue;
			}
			$name = $entry['name'] ?? ( null === $index ? ucwords( str_replace( '-', ' ', $slug ) ) : null );
			if ( null !== $name && ( ! is_string( $name ) || '' === trim( $name ) || strlen( $name ) > 60 || esc_attr( esc_html( $name ) ) !== $name ) ) {
				/* translators: %s: a slug. */
				$errors[] = sprintf( __( 'The name of "%s" must be plain text (no &, quotes or tags), at most 60 characters.', 'mody_cloud' ), $slug );
				continue;
			}
			if ( null === $index && ! array_key_exists( $value_key, $entry ) ) {
				/* translators: 1: a slug, 2: the value key, such as color. */
				$errors[] = sprintf( __( 'The new preset "%1$s" needs its %2$s.', 'mody_cloud' ), $slug, $value_key );
				continue;
			}
			$item = null === $index ? array( 'slug' => $slug ) : $custom[ $index ];
			if ( null !== $name ) {
				$item['name'] = trim( $name );
			}
			if ( array_key_exists( $value_key, $entry ) ) {
				$value = self::preset_value( $type, $entry[ $value_key ], $slug, $errors );
				if ( null === $value ) {
					continue;
				}
				$item[ $value_key ] = $value;
			}
			if ( 'font-size' === $type && array_key_exists( 'fluid', $entry ) ) {
				$item['fluid'] = self::fluid( $entry['fluid'], $slug, $errors );
			}
			$item = array(
				'slug' => $slug,
				'name' => $item['name'] ?? ucwords( str_replace( '-', ' ', $slug ) ),
			) + $item;
			if ( null === $index ) {
				$custom[] = $item;
			} else {
				$custom[ $index ] = $item;
			}
		}

		if ( $theme_set ) {
			$user['theme'] = array_values( $own_theme ); // The whole list: a user list replaces the theme's.
		}
		if ( $custom ) {
			$user['custom'] = $custom;
		} else {
			unset( $user['custom'] );
		}
		if ( $user ) {
			$config['settings'][ $group ][ $key ] = $user;
		} else {
			unset( $config['settings'][ $group ][ $key ] );
		}
		return $config;
	}

	/**
	 * A preset's value, checked: hex for colors, a CSS size otherwise.
	 *
	 * @param string       $type   The preset type.
	 * @param mixed        $value  The value given.
	 * @param string       $slug   The preset's slug, for errors.
	 * @param list<string> $errors Filled with what is refused.
	 */
	private static function preset_value( string $type, $value, string $slug, array &$errors ): ?string {
		if ( 'color' === $type ) {
			if ( SiteDesign::is_hex( $value ) ) {
				return strtolower( $value );
			}
			/* translators: %s: a color slug. */
			$errors[] = sprintf( __( 'The color "%s" must be a six-digit hex code such as #ff7f50.', 'mody_cloud' ), $slug );
			return null;
		}
		if ( is_string( $value ) && '' !== trim( $value ) && self::is_safe( $value ) && ! self::has_raw_color( $value ) ) {
			return trim( $value );
		}
		/* translators: %s: a preset slug. */
		$errors[] = sprintf( __( 'The size of "%s" must be a CSS size such as 1.5rem.', 'mody_cloud' ), $slug );
		return null;
	}

	/**
	 * A font size's `fluid`: false, or min and max sizes.
	 *
	 * @param mixed        $fluid  The value given.
	 * @param string       $slug   The preset's slug, for errors.
	 * @param list<string> $errors Filled with what is refused.
	 * @return false|array<string, string>
	 */
	private static function fluid( $fluid, string $slug, array &$errors ): false|array {
		if ( false === $fluid ) {
			return false;
		}
		$out = array();
		foreach ( is_array( $fluid ) ? $fluid : array( 'x' => null ) as $key => $value ) {
			if ( ! in_array( $key, array( 'min', 'max' ), true ) || ! is_string( $value ) || ! self::is_safe( $value ) ) {
				/* translators: %s: a font size slug. */
				$errors[] = sprintf( __( 'The fluid size of "%s" must be false, or min and max sizes.', 'mody_cloud' ), $slug );
				return false;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * The theme's own preset list for a PRESETS path (the theme origin).
	 *
	 * @param string $path A PRESETS key.
	 * @return list<array<string, mixed>>
	 */
	private static function theme_presets( string $path ): array {
		$settings = WP_Theme_JSON_Resolver::get_theme_data()->get_settings();
		list( $group, $key ) = explode( '.', $path );
		$list = $settings[ $group ][ $key ]['theme'] ?? array();
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * An installed font family, as the Site Editor activates it: its
	 * settings and faces from the site's Font Library (`wp_font_family` and
	 * `wp_font_face`), never from the input.
	 *
	 * @param string $slug The family's slug.
	 * @return array<string, mixed>|null Null when it is not installed on this site.
	 */
	public static function library_family( string $slug ): ?array {
		$posts = get_posts(
			array(
				'post_type'      => 'wp_font_family',
				'name'           => $slug,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);
		if ( ! $posts ) {
			return null;
		}
		$family   = $posts[0];
		$settings = json_decode( $family->post_content, true );
		$faces    = array();
		foreach (
			get_posts(
				array(
					'post_type'      => 'wp_font_face',
					'post_parent'    => $family->ID,
					'post_status'    => 'publish',
					'posts_per_page' => 50,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			) as $face
		) {
			$face_settings = json_decode( $face->post_content, true );
			if ( is_array( $face_settings ) && ! empty( $face_settings['src'] ) ) {
				$faces[] = $face_settings;
			}
		}
		$entry = array(
			'slug'       => $family->post_name,
			'name'       => $family->post_title,
			'fontFamily' => (string) ( is_array( $settings ) && ! empty( $settings['fontFamily'] ) ? $settings['fontFamily'] : $family->post_title ),
		);
		if ( $faces ) {
			$entry['fontFace'] = $faces;
		}
		return $entry;
	}

	/**
	 * The error for a family that is not in the site's Font Library.
	 *
	 * @param string $slug The family's slug.
	 */
	private static function font_missing( string $slug ): string {
		$installed = wp_list_pluck(
			get_posts(
				array(
					'post_type'      => 'wp_font_family',
					'post_status'    => 'publish',
					'posts_per_page' => 30,
				)
			),
			'post_name'
		);
		/* translators: 1: a font slug, 2: the installed font slugs. */
		return sprintf( __( 'The font "%1$s" is not installed on this site. Fonts come only from the Font Library (Styles → Typography → Fonts), where the owner can install it. Installed: %2$s.', 'mody_cloud' ), $slug, $installed ? implode( ', ', $installed ) : __( 'none', 'mody_cloud' ) );
	}

	/*
	 * Styles.
	 */

	/**
	 * Checks a style node of the changes: only known nodes and groups, and
	 * safe values.
	 *
	 * @param mixed        $node   The node.
	 * @param string       $path   Its path, such as `styles.elements.link`.
	 * @param string       $kind   root, element, block or state.
	 * @param string       $name   The element or block name.
	 * @param list<string> $errors Filled with what is refused.
	 */
	private static function check_node( $node, string $path, string $kind, string $name, array &$errors ): void {
		if ( null === $node ) {
			return; // Removes the node: the theme's styles show again.
		}
		if ( ! is_array( $node ) || ( $node && array_is_list( $node ) ) ) {
			/* translators: %s: a style path. */
			$errors[] = sprintf( __( '`%s` must be an object.', 'mody_cloud' ), $path );
			return;
		}
		$states = array();
		if ( 'element' === $kind ) {
			$states = WP_Theme_JSON::VALID_ELEMENT_PSEUDO_SELECTORS[ $name ] ?? array();
		} elseif ( 'block' === $kind && defined( WP_Theme_JSON::class . '::VALID_BLOCK_PSEUDO_SELECTORS' ) ) {
			$states = WP_Theme_JSON::VALID_BLOCK_PSEUDO_SELECTORS[ $name ] ?? array();
		}
		foreach ( $node as $key => $value ) {
			$key  = (string) $key;
			$here = $path . '.' . $key;
			if ( in_array( $key, self::STYLE_GROUPS, true ) ) {
				self::check_group( $key, $value, $here, $errors );
			} elseif ( 'elements' === $key && in_array( $kind, array( 'root', 'block' ), true ) ) {
				foreach ( (array) $value as $element => $element_node ) {
					if ( ! isset( WP_Theme_JSON::ELEMENTS[ $element ] ) ) {
						/* translators: 1: an element name, 2: the element names. */
						$errors[] = sprintf( __( 'There is no "%1$s" element. The elements are: %2$s.', 'mody_cloud' ), $element, implode( ', ', array_keys( WP_Theme_JSON::ELEMENTS ) ) );
						continue;
					}
					self::check_node( $element_node, $here . '.' . $element, 'element', (string) $element, $errors );
				}
			} elseif ( 'blocks' === $key && 'root' === $kind ) {
				foreach ( (array) $value as $block => $block_node ) {
					if ( ! WP_Block_Type_Registry::get_instance()->is_registered( (string) $block ) ) {
						/* translators: %s: a block name. */
						$errors[] = sprintf( __( 'There is no "%s" block.', 'mody_cloud' ), $block );
						continue;
					}
					self::check_node( $block_node, $here . '.' . $block, 'block', (string) $block, $errors );
				}
			} elseif ( in_array( $key, $states, true ) ) {
				self::check_node( $value, $here, 'state', $name, $errors );
			} else {
				$errors[] = self::not_allowed( $here );
			}
		}
	}

	/**
	 * Checks one style group: its keys are WordPress's, its values safe;
	 * colors, fonts and shadows name presets.
	 *
	 * @param string       $group  A STYLE_GROUPS value.
	 * @param mixed        $value  The group's value.
	 * @param string       $path   Its path.
	 * @param list<string> $errors Filled with what is refused.
	 */
	private static function check_group( string $group, $value, string $path, array &$errors ): void {
		if ( null === $value ) {
			return;
		}
		if ( 'shadow' === $group ) {
			if ( ! is_string( $value ) || ( 'none' !== $value && ! self::is_ref( $value, 'shadow' ) ) ) {
				/* translators: %s: a style path. */
				$errors[] = sprintf( __( '`%s` must be a shadow preset, such as var:preset|shadow|natural, or none.', 'mody_cloud' ), $path );
			}
			return;
		}
		if ( ! is_array( $value ) || ( $value && array_is_list( $value ) ) ) {
			/* translators: %s: a style path. */
			$errors[] = sprintf( __( '`%s` must be an object.', 'mody_cloud' ), $path );
			return;
		}
		$known = (array) ( WP_Theme_JSON::VALID_STYLES[ $group ] ?? array() );
		foreach ( $value as $key => $leaf ) {
			$here = $path . '.' . $key;
			if ( ! array_key_exists( $key, $known ) ) {
				$errors[] = self::not_allowed( $here );
				continue;
			}
			// Border sides and spacing boxes are one level deeper.
			$leaves = is_array( $leaf ) && ! array_is_list( $leaf ) ? $leaf : array( '' => $leaf );
			foreach ( $leaves as $sub => $item ) {
				$at = '' === $sub ? $here : $here . '.' . $sub;
				if ( is_array( $item ) ) {
					$errors[] = self::not_allowed( $at );
					continue;
				}
				if ( null === $item ) {
					continue;
				}
				$item  = (string) $item;
				$color = ( 'color' === $group && in_array( $key, array( 'text', 'background' ), true ) ) || ( 'border' === $group && ( 'color' === $key || 'color' === $sub ) );
				if ( ! self::is_safe( $item ) ) {
					/* translators: %s: a style path. */
					$errors[] = sprintf( __( '`%s` has characters or a URL that styles may not contain.', 'mody_cloud' ), $at );
				} elseif ( 'color' === $group && 'gradient' === $key ) {
					if ( ! self::is_ref( $item, 'gradient' ) ) {
						/* translators: %s: a style path. */
						$errors[] = sprintf( __( '`%s` must name a gradient preset. The site has no gradients.', 'mody_cloud' ), $at );
					}
				} elseif ( $color ) {
					if ( ! self::is_ref( $item, 'color' ) && ! in_array( strtolower( $item ), self::COLOR_KEYWORDS, true ) ) {
						/* translators: %s: a style path. */
						$errors[] = sprintf( __( '`%s` must name a palette color, such as var:preset|color|accent. To use a new color, add it to settings.color.palette first.', 'mody_cloud' ), $at );
					}
				} elseif ( self::has_raw_color( $item ) ) {
					/* translators: %s: a style path. */
					$errors[] = sprintf( __( '`%s` contains a color code. Styles name palette colors, such as var(--wp--preset--color--accent).', 'mody_cloud' ), $at );
				} elseif ( 'typography' === $group && 'fontFamily' === $key && ! self::is_ref( $item, 'font-family' ) ) {
					/* translators: %s: a style path. */
					$errors[] = sprintf( __( '`%s` must name a font family preset, such as var:preset|font-family|inter.', 'mody_cloud' ), $at );
				}
			}
		}
	}

	/**
	 * Every preset that a changed value names must exist: the site's
	 * palette, sizes and fonts after the changes. A font family installed in
	 * the Font Library but not active is activated. A preset removed by the
	 * changes may not still be in use.
	 *
	 * @param array<string, mixed> $config  The configuration after the changes.
	 * @param array<string, mixed> $patch   The `styles` changes.
	 * @param array<string, true>  $removed "type|slug" of presets the changes removed.
	 * @param list<string>         $errors  Filled with what is refused.
	 * @return array<string, mixed> The configuration, with fonts activated.
	 */
	private static function check_references( array $config, array $patch, array $removed, array &$errors ): array {
		$changed = array();
		foreach ( self::flatten( $patch, 'styles' ) as $path => $value ) {
			if ( null !== $value ) {
				$changed[ $path ] = (string) $value;
			}
		}

		// Fonts the changes name: activate installed families first.
		foreach ( $changed as $value ) {
			foreach ( self::refs( $value ) as list( $type, $slug ) ) {
				if ( 'font-family' !== $type || in_array( $slug, self::slugs( $config, 'font-family' ), true ) ) {
					continue;
				}
				$family = self::library_family( $slug );
				if ( null === $family ) {
					$errors[] = self::font_missing( $slug );
					continue;
				}
				$config['settings']['typography']['fontFamilies']['custom']   = array_values( (array) ( $config['settings']['typography']['fontFamilies']['custom'] ?? array() ) );
				$config['settings']['typography']['fontFamilies']['custom'][] = $family;
			}
		}

		$existing = array();
		foreach ( explode( '|', self::REF_TYPES ) as $type ) {
			$existing[ $type ] = self::slugs( $config, $type );
		}
		foreach ( self::flatten( (array) ( $config['styles'] ?? array() ), 'styles' ) as $path => $value ) {
			foreach ( self::refs( (string) $value ) as list( $type, $slug ) ) {
				if ( in_array( $slug, $existing[ $type ], true ) ) {
					continue;
				}
				if ( isset( $removed[ $type . '|' . $slug ] ) ) {
					/* translators: 1: a preset slug, 2: a style path. */
					$errors[] = sprintf( __( '"%1$s" cannot be removed: `%2$s` still uses it. Change that style too.', 'mody_cloud' ), $slug, $path );
				} elseif ( isset( $changed[ $path ] ) && 'font-family' !== $type ) {
					/* translators: 1: a preset type, 2: a slug, 3: the slugs that exist. */
					$errors[] = sprintf( __( 'There is no %1$s preset "%2$s". The site has: %3$s.', 'mody_cloud' ), $type, $slug, $existing[ $type ] ? implode( ', ', $existing[ $type ] ) : __( 'none', 'mody_cloud' ) );
				}
			}
		}
		return $config;
	}

	/**
	 * The preset slugs a style may name, after the changes: the site's own
	 * list for an origin replaces the theme's, as in WordPress's merge. Core's
	 * default shadows count too (the theme defines none).
	 *
	 * @param array<string, mixed> $config The site's configuration.
	 * @param string               $type   A preset type.
	 * @return list<string>
	 */
	private static function slugs( array $config, string $type ): array {
		$paths = array(
			'color'       => array( 'color', 'palette' ),
			'gradient'    => array( 'color', 'gradients' ),
			'font-size'   => array( 'typography', 'fontSizes' ),
			'font-family' => array( 'typography', 'fontFamilies' ),
			'spacing'     => array( 'spacing', 'spacingSizes' ),
			'shadow'      => array( 'shadow', 'presets' ),
		);
		list( $group, $key ) = $paths[ $type ];
		$theme  = WP_Theme_JSON_Resolver::get_theme_data()->get_settings()[ $group ][ $key ] ?? array();
		$user   = $config['settings'][ $group ][ $key ] ?? array();
		$slugs  = array();
		$origin = array( 'theme', 'custom' );
		if ( 'shadow' === $type ) {
			$origin[] = 'default';
			$theme   += WP_Theme_JSON_Resolver::get_core_data()->get_settings()['shadow']['presets'] ?? array();
		}
		foreach ( $origin as $name ) {
			$list = ! empty( $user[ $name ] ) ? $user[ $name ] : ( $theme[ $name ] ?? array() );
			foreach ( (array) $list as $entry ) {
				if ( is_array( $entry ) && isset( $entry['slug'] ) ) {
					$slugs[] = (string) $entry['slug'];
				}
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Paths of the configuration that WordPress's own sanitizing drops
	 * (WP_Theme_JSON, as the resolver reads the site's styles).
	 *
	 * @param array<string, mixed> $config The configuration to save.
	 * @return list<string>
	 */
	private static function dropped_by_sanitize( array $config ): array {
		$config['version'] = $config['version'] ?? WP_Theme_JSON::LATEST_SCHEMA;
		unset( $config['isGlobalStylesUserThemeJSON'] );
		$kept = self::flatten( ( new WP_Theme_JSON( $config, 'custom' ) )->get_raw_data()['styles'] ?? array(), 'styles' );
		return array_keys( array_diff_key( self::flatten( (array) ( $config['styles'] ?? array() ), 'styles' ), $kept ) );
	}

	/*
	 * Values.
	 */

	/**
	 * Whether a style value is free of what styles may not contain: URLs,
	 * declarations, blocks, tags, escapes, imports.
	 *
	 * @param string $value A value.
	 */
	private static function is_safe( string $value ): bool {
		return strlen( $value ) <= 200 && ! preg_match( '/url\s*\(|expression\s*\(|javascript:|@import|[;{}<>\\\\"]/i', $value );
	}

	/**
	 * Whether a value contains a color code (hex or a color function).
	 *
	 * @param string $value A value.
	 */
	private static function has_raw_color( string $value ): bool {
		return (bool) preg_match( '/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\s*\(/i', $value );
	}

	/**
	 * Whether a value is exactly a reference to a preset of a type.
	 *
	 * @param string $value A value.
	 * @param string $type  A preset type.
	 */
	private static function is_ref( string $value, string $type ): bool {
		$refs = self::refs( $value );
		return 1 === count( $refs ) && $type === $refs[0][0] && in_array( trim( $value ), array( 'var:preset|' . $type . '|' . $refs[0][1], 'var(--wp--preset--' . $type . '--' . $refs[0][1] . ')' ), true );
	}

	/**
	 * The preset references in a value, in both forms WordPress reads.
	 *
	 * @param string $value A value.
	 * @return list<array{0: string, 1: string}> Type and slug.
	 */
	private static function refs( string $value ): array {
		preg_match_all( '/var:preset\|(' . self::REF_TYPES . ')\|([a-z0-9-]+)|var\(--wp--preset--(' . self::REF_TYPES . ')--([a-z0-9-]+)\)/', $value, $matches, PREG_SET_ORDER );
		$refs = array();
		foreach ( $matches as $match ) {
			$refs[] = '' !== $match[1] ? array( $match[1], $match[2] ) : array( $match[3], $match[4] );
		}
		return $refs;
	}

	/**
	 * Preset references in the form kses keeps: WordPress 7.1 rewrites
	 * `var:preset|color|accent` as `var(--wp--preset--color--accent)` when a
	 * member (no unfiltered_html) saves. Both mean the same; storing one form
	 * keeps the read-back and the no-change check exact for everyone.
	 *
	 * @param mixed $value A value, or nested values.
	 * @return mixed
	 */
	private static function normalize( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'normalize' ), $value );
		}
		return is_string( $value ) ? (string) preg_replace( '/var:preset\|(' . self::REF_TYPES . ')\|([a-z0-9-]+)/', 'var(--wp--preset--$1--$2)', $value ) : $value;
	}

	/*
	 * Merging and comparing.
	 */

	/**
	 * A JSON merge patch: objects merge key by key, null removes a key.
	 *
	 * @param array<string, mixed> $target The current values.
	 * @param array<string, mixed> $patch  The changes.
	 * @return array<string, mixed>
	 */
	private static function merge( array $target, array $patch ): array {
		foreach ( $patch as $key => $value ) {
			if ( null === $value ) {
				unset( $target[ $key ] );
			} elseif ( is_array( $value ) && $value && ! array_is_list( $value ) ) {
				$target[ $key ] = self::merge( isset( $target[ $key ] ) && is_array( $target[ $key ] ) ? $target[ $key ] : array(), $value );
			} else {
				$target[ $key ] = $value;
			}
		}
		return $target;
	}

	/**
	 * Removes empty objects, so a removed style leaves nothing behind.
	 *
	 * @param array<string, mixed> $values Nested values.
	 * @return array<string, mixed>
	 */
	private static function prune( array $values ): array {
		foreach ( $values as $key => $value ) {
			if ( is_array( $value ) && ! array_is_list( $value ) ) {
				$value = self::prune( $value );
			}
			if ( array() === $value ) {
				unset( $values[ $key ] );
			} else {
				$values[ $key ] = $value;
			}
		}
		return $values;
	}

	/**
	 * Nested values as dotted paths to scalars.
	 *
	 * @param mixed  $values Nested values.
	 * @param string $prefix The path so far.
	 * @return array<string, mixed>
	 */
	private static function flatten( $values, string $prefix ): array {
		if ( ! is_array( $values ) || ( $values && array_is_list( $values ) ) ) {
			return array( $prefix => $values );
		}
		$flat = array();
		foreach ( $values as $key => $value ) {
			$flat += self::flatten( $value, $prefix . '.' . $key );
		}
		return $flat;
	}

	/**
	 * What a member sees of a configuration, as paths: styles, the layout
	 * widths, and each preset by slug (the theme's list when the site has
	 * none of its own).
	 *
	 * @param array<string, mixed> $config A theme.json configuration.
	 * @return array<string, string>
	 */
	private static function flatten_config( array $config ): array {
		$flat = array();
		foreach ( self::flatten( (array) ( $config['styles'] ?? array() ), 'styles' ) as $path => $value ) {
			if ( is_scalar( $value ) ) {
				$flat[ $path ] = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : self::normalize( (string) $value );
			}
		}
		foreach ( self::LAYOUT as $key ) {
			if ( isset( $config['settings']['layout'][ $key ] ) && is_scalar( $config['settings']['layout'][ $key ] ) ) {
				$flat[ 'settings.layout.' . $key ] = (string) $config['settings']['layout'][ $key ];
			}
		}
		foreach ( self::PRESETS as $path => list( $type, $value_key ) ) {
			list( $group, $key ) = explode( '.', $path );
			$lists               = (array) ( $config['settings'][ $group ][ $key ] ?? array() );
			if ( array_is_list( $lists ) ) {
				$lists = array( 'theme' => $lists ); // The theme's own file.
			}
			if ( empty( $lists['theme'] ) && 'font-family' !== $type ) {
				$lists['theme'] = self::theme_presets( $path );
			}
			foreach ( array( 'theme', 'custom' ) as $origin ) {
				foreach ( (array) ( $lists[ $origin ] ?? array() ) as $entry ) {
					if ( ! is_array( $entry ) || ! isset( $entry['slug'] ) ) {
						continue;
					}
					$at          = 'settings.' . $path . '.' . $entry['slug'];
					$flat[ $at ] = 'font-family' === $type ? __( 'active', 'mody_cloud' ) : (string) ( $entry[ $value_key ] ?? '' );
					if ( 'custom' === $origin && isset( $entry['name'] ) ) {
						$flat[ $at . '.name' ] = (string) $entry['name'];
					}
				}
			}
		}
		return $flat;
	}

	/**
	 * The paths whose values differ, with the old and new value (null when absent).
	 *
	 * @param array<string, string> $before Paths before.
	 * @param array<string, string> $after  Paths after.
	 * @return array<string, array{old: ?string, new: ?string}>
	 */
	private static function diff( array $before, array $after ): array {
		$diff = array();
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $path ) {
			if ( ( $before[ $path ] ?? null ) !== ( $after[ $path ] ?? null ) ) {
				$diff[ $path ] = array(
					'old' => $before[ $path ] ?? null,
					'new' => $after[ $path ] ?? null,
				);
			}
		}
		return $diff;
	}

	/*
	 * Where the change shows.
	 */

	/**
	 * The Styles screen that shows changed paths (M3-09): one section when
	 * they agree, one block's styles when only that block changed, else the
	 * Styles overview.
	 *
	 * @param list<string> $paths Changed paths.
	 * @return array{url: string, place: string}
	 */
	public static function screen_for( array $paths ): array {
		$sections = array();
		$blocks   = array();
		foreach ( $paths as $path ) {
			$parts = explode( '.', $path );
			if ( 'settings' === $parts[0] ) {
				$sections[] = array( 'color' => 'palette', 'typography' => 'typography' )[ $parts[1] ] ?? 'layout';
				continue;
			}
			if ( 'blocks' === ( $parts[1] ?? '' ) || ( 'elements' === ( $parts[1] ?? '' ) && 'button' === ( $parts[2] ?? '' ) ) ) {
				$sections[] = 'blocks';
				$blocks[]   = 'blocks' === $parts[1] ? $parts[2] : 'core/button';
				continue;
			}
			$group      = 'elements' === ( $parts[1] ?? '' ) ? ( str_starts_with( $parts[3] ?? '', ':' ) ? ( $parts[4] ?? '' ) : ( $parts[3] ?? '' ) ) : ( $parts[1] ?? '' );
			$sections[] = array(
				'color'      => 'colors',
				'typography' => 'typography',
				'spacing'    => 'layout',
				'dimensions' => 'layout',
			)[ $group ] ?? 'overview';
		}
		$sections = array_values( array_unique( $sections ) );
		$blocks   = array_values( array_unique( $blocks ) );
		if ( 1 !== count( $sections ) ) {
			return SiteEditor::styles_screen( 'overview' );
		}
		return SiteEditor::styles_screen( $sections[0], 'blocks' === $sections[0] && 1 === count( $blocks ) ? $blocks[0] : '' );
	}

	/*
	 * Errors.
	 */

	/**
	 * A refusal listing what is not allowed. Nothing was saved.
	 *
	 * @param list<string> $errors What is refused.
	 */
	private static function refuse( array $errors ): WP_Error {
		$errors  = array_values( array_unique( $errors ) );
		$message = __( 'Nothing was saved.', 'mody_cloud' ) . ' ' . implode( ' ', array_slice( $errors, 0, 6 ) );
		if ( preg_grep( '/^NOT_ALLOWED /', $errors ) ) {
			$message  = str_replace( 'NOT_ALLOWED ', '', $message );
			$message .= ' ' . __( 'Styles take color, typography, spacing, border, shadow and dimensions, in styles, elements and blocks. Settings take the palette, font sizes, font families, spacing sizes and the layout widths. No CSS.', 'mody_cloud' );
		}
		return self::error( 'mody_styles_refused', $message );
	}

	/**
	 * The message for a path members may not change (refuse() explains once what can).
	 *
	 * @param string $path A path.
	 */
	private static function not_allowed( string $path ): string {
		/* translators: %s: a style or settings path. */
		return 'NOT_ALLOWED ' . sprintf( __( '`%s` cannot be changed.', 'mody_cloud' ), $path );
	}

	/**
	 * A WP_Error with a code and message.
	 *
	 * @param string $code    The code.
	 * @param string $message The message.
	 */
	private static function error( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message );
	}
}
