<?php
/**
 * The site's look on the platform's own wp-admin pages.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Brand and the Mody dashboard wear the site's palette, fonts
 * and logo, as the login page does. These helpers were Mody Login's
 * (LoginPage, Schemes, Color) until each Mody plugin became independent
 * (2026-11-17, tickets/independent-mody-plugins.md): this plugin keeps its
 * own copy, so it works without Mody Login. The logo keeps the login page's
 * class names, which brand-page.css styles.
 */
final class Look {

	/** A palette slug that can be a CSS variable name. */
	private const SLUG_PATTERN = '/^[a-z0-9-]+$/';

	/**
	 * Enqueues one of this plugin's stylesheets, versioned by its file time.
	 *
	 * @param string   $handle The style handle.
	 * @param string   $file   The file in `assets/`.
	 * @param string[] $deps   Handles it follows.
	 */
	public static function enqueue_asset( string $handle, string $file, array $deps = array() ): void {
		$root = dirname( __DIR__ );
		wp_enqueue_style( $handle, plugins_url( 'assets/' . $file, $root . '/mody-platform.php' ), $deps, (string) filemtime( $root . '/assets/' . $file ) );
	}

	/**
	 * The theme's preset variables (colors, fonts, sizes) for this site, as
	 * the front end has them.
	 */
	public static function variables_css(): string {
		return (string) wp_get_global_stylesheet( array( 'variables' ) );
	}

	/**
	 * `admin_head-<page>`: the @font-face rules of the site's fonts (theme
	 * and Font Library), served from this server.
	 */
	public static function print_fonts(): void {
		if ( function_exists( 'wp_print_font_faces' ) ) {
			wp_print_font_faces();
		}
	}

	/**
	 * The font preset a Global Styles value points to, or ''.
	 *
	 * @param string $value Such as `var(--wp--preset--font-family--inter)` or `var:preset|font-family|inter`.
	 */
	public static function font_slug( string $value ): string {
		if ( preg_match( '/^var\(--wp--preset--font-family--([a-z0-9-]+)\)$/', $value, $m ) || preg_match( '/^var:preset\|font-family\|([a-z0-9-]+)$/', $value, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * The site's logo as an image on its plate, else its icon, else its name.
	 *
	 * @param string $sizes The image's `sizes` attribute.
	 */
	public static function logo_html( string $sizes ): string {
		$name    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$logo_id = (int) get_option( 'site_logo' );
		$logo_id = $logo_id ? $logo_id : (int) get_theme_mod( 'custom_logo' );
		$image   = $logo_id ? wp_get_attachment_image(
			$logo_id,
			'full',
			false,
			array(
				'class'         => 'mody-login-logo',
				'alt'           => $name,
				'sizes'         => $sizes,
				'loading'       => 'eager',
				'decoding'      => 'async',
				'fetchpriority' => 'high',
			)
		) : '';

		if ( '' === $image ) {
			$icon = (string) get_site_icon_url( 192 );
			if ( '' !== $icon ) {
				$image = sprintf( '<img class="mody-login-logo mody-login-logo--icon" src="%s" alt="%s" width="96" height="96" decoding="async" />', esc_url( $icon ), esc_attr( $name ) );
			}
		}

		if ( '' === $image ) {
			return '<span class="mody-login-name">' . esc_html( $name ) . '</span>';
		}
		return '<span class="mody-login-plate">' . $image . '</span>';
	}

	/**
	 * The current site's palette as six-digit hex, by slug. Values that are
	 * not hex colors, and slugs that could not be a CSS variable name, are
	 * left out.
	 *
	 * @return array<string, string>
	 */
	public static function palette(): array {
		$palette = array();
		foreach ( (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) ) as $entry ) {
			$slug = is_array( $entry ) ? (string) ( $entry['slug'] ?? '' ) : '';
			$hex  = self::hex( is_array( $entry ) ? (string) ( $entry['color'] ?? '' ) : '' );
			if ( null !== $hex && preg_match( self::SLUG_PATTERN, $slug ) ) {
				$palette[ $slug ] = $hex;
			}
		}
		return $palette;
	}

	/**
	 * A palette value as six-digit lowercase hex, or null. Accepts #rgb,
	 * #rrggbb and #rrggbbaa (the alpha is dropped).
	 *
	 * @param mixed $value The palette value.
	 */
	public static function hex( $value ): ?string {
		if ( ! is_string( $value ) || ! preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', trim( $value ), $m ) ) {
			return null;
		}
		$hex = strtolower( $m[1] );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return '#' . substr( $hex, 0, 6 );
	}

	/**
	 * The contrast ratio of two six-digit hex colors (WCAG 2), 1 to 21.
	 *
	 * @param string $a Six-digit hex.
	 * @param string $b Six-digit hex.
	 */
	public static function contrast( string $a, string $b ): float {
		$luminance = static function ( string $hex ): float {
			list( $r, $g, $b ) = array_map(
				static function ( $v ): float {
					$v /= 255;
					return $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
				},
				array_map( 'hexdec', str_split( substr( $hex, 1, 6 ), 2 ) )
			);
			return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
		};
		$la = $luminance( $a );
		$lb = $luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}
}
