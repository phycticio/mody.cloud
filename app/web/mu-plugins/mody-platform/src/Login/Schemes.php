<?php
/**
 * The login page's color schemes: up to six arrangements of a site's own
 * palette, and the one the site chose.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Login;

defined( 'ABSPATH' ) || exit;

/**
 * Decided by the maintainer on 2026-11-10 (tickets/login-branding.md): a
 * site's login page wears that site's own palette, arranged by one of up to
 * six schemes: Light, Soft, Bold, Dark, Accent and Secondary. Each is a
 * recipe of palette slugs (which color is the page, the form, the button).
 * A recipe that would be unreadable with the site's colors is not offered,
 * and two recipes that come out the same are offered once, so a site may get
 * fewer than six. Light is always offered: it bends instead of failing.
 *
 * Colors are the theme's own variables (`var(--wp--preset--color--primary)`),
 * so the page follows the site's Global Styles; only tints and shades
 * derived from them are written as hex, computed on each request.
 */
final class Schemes {

	/** The site option holding the chosen scheme. */
	public const OPTION = 'mody_login_scheme';

	/** The scheme every site has, and gets until it chooses. */
	public const DEFAULT = 'light';

	/** The schemes, in the order they are offered. */
	public const SLUGS = array( 'light', 'soft', 'bold', 'dark', 'accent', 'secondary' );

	/** A palette slug as CSS custom properties allow it. */
	private const SLUG_PATTERN = '/^[a-z0-9-]+$/';

	/**
	 * The current site's palette as six-digit hex, by slug. Values that are
	 * not hex colors, and slugs that could not be a CSS variable name, are left out.
	 *
	 * @return array<string, string>
	 */
	public static function palette(): array {
		// Read here, not through Design\SiteDesign: the login page also runs in
		// Mody Site, the portable plugin a downloaded site takes with it.
		$palette = array();
		foreach ( (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) ) as $entry ) {
			$slug = is_array( $entry ) ? (string) ( $entry['slug'] ?? '' ) : '';
			$hex  = Color::hex( is_array( $entry ) ? (string) ( $entry['color'] ?? '' ) : '' );
			if ( null !== $hex && preg_match( self::SLUG_PATTERN, $slug ) ) {
				$palette[ $slug ] = $hex;
			}
		}
		return $palette;
	}

	/**
	 * The schemes a palette allows, in order, without duplicates.
	 *
	 * @param array<string, string> $palette Slug => six-digit hex.
	 * @return array<string, array{label: string, description: string, roles: array<string, array{slug: string, hex: string}>}>
	 *         Empty when the palette lacks `base`, `contrast` or `primary`.
	 */
	public static function for_palette( array $palette ): array {
		if ( ! isset( $palette['base'], $palette['contrast'], $palette['primary'] ) ) {
			return array();
		}
		$schemes = array();
		$seen    = array();
		foreach ( self::SLUGS as $slug ) {
			$roles = self::build( $slug, $palette );
			if ( null === $roles ) {
				continue;
			}
			$signature = implode( '|', array_map( static fn( string $role ): string => $roles[ $role ]['hex'], array( 'page', 'card', 'button', 'link', 'nav' ) ) );
			if ( isset( $seen[ $signature ] ) ) {
				continue;
			}
			$seen[ $signature ] = true;
			$schemes[ $slug ]   = self::describe( $slug, $palette ) + array( 'roles' => $roles );
		}
		return $schemes;
	}

	/**
	 * The scheme the current site shows: the one it chose if its palette
	 * still allows it, otherwise Light. Null when the palette lacks `base`,
	 * `contrast` or `primary`: the page then keeps core's colors.
	 *
	 * @param string $preview A scheme to show instead of the chosen one, or ''.
	 * @return array{slug: string, label: string, description: string, roles: array<string, array{slug: string, hex: string}>}|null
	 */
	public static function current( string $preview = '' ): ?array {
		$schemes = self::for_palette( self::palette() );
		if ( ! $schemes ) {
			return null;
		}
		$slug = '' !== $preview ? $preview : self::chosen();
		$slug = isset( $schemes[ $slug ] ) ? $slug : self::DEFAULT;
		return array( 'slug' => $slug ) + $schemes[ $slug ];
	}

	/**
	 * The scheme the current site chose (it may no longer be offered).
	 */
	public static function chosen(): string {
		$slug = (string) get_option( self::OPTION, self::DEFAULT );
		return in_array( $slug, self::SLUGS, true ) ? $slug : self::DEFAULT;
	}

	/**
	 * Sanitize callback of the option: a known scheme, else the default.
	 *
	 * @param mixed $value The submitted value.
	 */
	public static function sanitize( $value ): string {
		$value = is_string( $value ) ? sanitize_key( $value ) : '';
		return in_array( $value, self::SLUGS, true ) ? $value : self::DEFAULT;
	}

	/**
	 * A scheme's roles as CSS custom properties: palette colors as the
	 * theme's variables, derived ones as hex.
	 *
	 * @param array<string, array{slug: string, hex: string}> $roles The scheme's roles.
	 * @return array<string, string> Property name => value.
	 */
	public static function properties( array $roles ): array {
		$properties = array();
		foreach ( $roles as $role => $color ) {
			$properties[ '--mody-login-' . str_replace( '_', '-', $role ) ] = self::css( $color );
		}
		return $properties;
	}

	/**
	 * A role's CSS value: the theme's variable for a palette color, else hex.
	 *
	 * @param array{slug: string, hex: string} $color The role's color.
	 */
	public static function css( array $color ): string {
		return '' !== $color['slug'] ? 'var(--wp--preset--color--' . $color['slug'] . ')' : $color['hex'];
	}

	/**
	 * A scheme's name and what it does, in the site's words.
	 *
	 * @param string                $slug    The scheme.
	 * @param array<string, string> $palette The palette.
	 * @return array{label: string, description: string}
	 */
	private static function describe( string $slug, array $palette ): array {
		$dark = ! Color::is_light( $palette['contrast'] );
		switch ( $slug ) {
			case 'soft':
				return array(
					'label'       => __( 'Soft', 'mody_cloud' ),
					'description' => __( 'A page tinted with the primary color, the form in the background color.', 'mody_cloud' ),
				);
			case 'bold':
				return array(
					'label'       => __( 'Bold', 'mody_cloud' ),
					'description' => __( 'The page in the primary color, the button in the accent color.', 'mody_cloud' ),
				);
			case 'dark':
				return array(
					'label'       => $dark ? __( 'Dark', 'mody_cloud' ) : __( 'Inverted', 'mody_cloud' ),
					'description' => __( 'The page in the text color, with the background color as text.', 'mody_cloud' ),
				);
			case 'accent':
				return array(
					'label'       => __( 'Accent', 'mody_cloud' ),
					'description' => __( 'The site\'s background, with the button in the accent color.', 'mody_cloud' ),
				);
			case 'secondary':
				return array(
					'label'       => __( 'Secondary', 'mody_cloud' ),
					'description' => __( 'The page in the secondary color, the button in the primary color.', 'mody_cloud' ),
				);
			default:
				return array(
					'label'       => __( 'Light', 'mody_cloud' ),
					'description' => __( 'The site\'s background, with the button in the primary color. Always readable.', 'mody_cloud' ),
				);
		}
	}

	/**
	 * A scheme's roles for a palette, or null when the palette cannot carry it.
	 *
	 * Roles: `page`, `card` (the form and messages), `border`, `field` (input
	 * borders), `text` (in the form), `muted`, `link` (in the form), `nav`
	 * (text and links on the page), `button`, `button_text`, and `plate`: what
	 * the logo sits on. A logo is drawn for the site's background, so on a
	 * page of another color it sits on a plate of the background color.
	 *
	 * @param string                $slug    The scheme.
	 * @param array<string, string> $palette Slug => six-digit hex; has `base`, `contrast` and `primary`.
	 * @return array<string, array{slug: string, hex: string}>|null
	 */
	private static function build( string $slug, array $palette ): ?array {
		$ref    = static fn( string $name ): ?array => isset( $palette[ $name ] ) ? array( 'slug' => $name, 'hex' => $palette[ $name ] ) : null;
		$hex    = static fn( string $value ): array => array( 'slug' => '', 'hex' => $value );
		$base   = $ref( 'base' );
		$text   = $ref( 'contrast' );
		$light  = Color::is_light( $base['hex'] );
		$lifted = $hex( $light ? Color::mix( $base['hex'], '#ffffff', 0.7 ) : Color::mix( $base['hex'], $text['hex'], 0.06 ) );
		$clear  = array( 'slug' => '', 'hex' => 'transparent' );

		switch ( $slug ) {
			case 'light':
				$page = $base;
				$card = $lifted;
				// Never refused: every color falls back to the most readable one.
				$text   = self::pick( array( $text, $base ), array( $page, $card ), false );
				$shown  = Color::visible( $palette['primary'], array( $page['hex'], $card['hex'] ) );
				// Then on, if needed, until a label reads on it and on its hover shade.
				$step = Color::is_light( $page['hex'] ) ? 0.05 : -0.05;
				for ( $i = 0; null !== $shown && $i <= 20; $i++ ) {
					$shade = Color::darken( $shown, $step * $i );
					if ( null !== self::pick( array( $base, $ref( 'contrast' ) ), array( $hex( $shade ), $hex( Color::darken( $shade, 0.05 ) ) ) ) ) {
						$shown = $shade;
						break;
					}
				}
				$button = null === $shown ? $text : ( $shown === $palette['primary'] ? $ref( 'primary' ) : $hex( $shown ) );
				$roles  = array(
					'page'   => $page,
					'card'   => $card,
					'text'   => $text,
					'button' => $button,
					'link'   => self::pick( array( $ref( 'accent' ), $ref( 'primary' ), $text ), array( $card ), false ),
					'nav'    => self::pick( array( $ref( 'accent' ), $ref( 'primary' ), $text ), array( $page ), false ),
					'muted'  => self::pick( array( $ref( 'secondary' ), $text ), array( $page, $card ), false ),
					'plate'  => $clear,
				);
				break;

			case 'soft':
				$page  = $hex( Color::mix( $base['hex'], $palette['primary'], 0.1 ) );
				$roles = array(
					'page'   => $page,
					'card'   => $base,
					'text'   => $text,
					'button' => $ref( 'primary' ),
					'link'   => self::pick( array( $ref( 'primary' ), $ref( 'accent' ), $text ), array( $base ) ),
					'nav'    => self::pick( array( $ref( 'primary' ), $ref( 'accent' ), $text ), array( $page ) ),
					'muted'  => self::pick( array( $ref( 'secondary' ), $text ), array( $base ) ),
					'plate'  => $clear,
				);
				break;

			case 'bold':
				$page  = $ref( 'primary' );
				$roles = array(
					'page'   => $page,
					'card'   => $base,
					'text'   => $text,
					'button' => $ref( 'accent' ),
					'link'   => self::pick( array( $ref( 'primary' ), $ref( 'accent' ), $text ), array( $base ) ),
					'nav'    => self::pick( array( $base, $text ), array( $page ) ),
					'muted'  => self::pick( array( $ref( 'secondary' ), $text ), array( $base ) ),
					'plate'  => $base,
				);
				break;

			case 'dark':
				$page  = $text;
				$card  = $hex( Color::mix( $text['hex'], $base['hex'], 0.08 ) );
				$roles = array(
					'page'   => $page,
					'card'   => $card,
					'text'   => $base,
					'button' => $ref( 'primary' ),
					'link'   => self::pick( array( $ref( 'accent' ), $ref( 'primary' ), $base ), array( $card ) ),
					'nav'    => self::pick( array( $ref( 'accent' ), $ref( 'primary' ), $base ), array( $page ) ),
					'muted'  => self::pick( array( $hex( Color::mix( $base['hex'], $text['hex'], 0.25 ) ), $base ), array( $card ) ),
					'plate'  => $base,
				);
				break;

			case 'accent':
				$roles = array(
					'page'   => $base,
					'card'   => $lifted,
					'text'   => $text,
					'button' => $ref( 'accent' ),
					'link'   => self::pick( array( $ref( 'primary' ), $text ), array( $lifted ) ),
					'nav'    => self::pick( array( $ref( 'primary' ), $text ), array( $base ) ),
					'muted'  => self::pick( array( $ref( 'secondary' ), $text ), array( $base, $lifted ) ),
					'plate'  => $clear,
				);
				break;

			case 'secondary':
				$page  = $ref( 'secondary' );
				$roles = array(
					'page'   => $page,
					'card'   => $base,
					'text'   => $text,
					'button' => $ref( 'primary' ),
					'link'   => self::pick( array( $ref( 'accent' ), $ref( 'primary' ), $text ), array( $base ) ),
					'nav'    => self::pick( array( $base, $text ), array( $page ) ),
					'muted'  => self::pick( array( $text ), array( $base ) ),
					'plate'  => $base,
				);
				break;

			default:
				return null;
		}

		// A strict scheme is refused when a color is missing or would not read.
		foreach ( $roles as $color ) {
			if ( null === $color ) {
				return null;
			}
		}
		$card = $roles['card']['hex'];
		if ( 'light' !== $slug && ( Color::contrast( $roles['text']['hex'], $card ) < Color::TEXT || Color::contrast( $roles['button']['hex'], $card ) < Color::UI ) ) {
			return null;
		}
		$hover               = Color::darken( $roles['button']['hex'], 0.05 );
		$roles['button_text'] = self::pick( array( $base, $ref( 'contrast' ) ), array( $roles['button'], $hex( $hover ) ), 'light' === $slug ? false : true );
		if ( null === $roles['button_text'] ) {
			return null;
		}
		$roles['border'] = $hex( Color::mix( $card, $roles['text']['hex'], 0.2 ) );
		$roles['field']  = $hex( Color::mix( $card, $roles['text']['hex'], 0.45 ) );
		return $roles;
	}

	/**
	 * The first candidate that reads (4.5:1) on every background. When none
	 * does: null if strict, otherwise the one that reads best.
	 *
	 * @param array<int, array{slug: string, hex: string}|null> $candidates  In order of preference; nulls (colors the palette lacks) are skipped.
	 * @param array<int, array{slug: string, hex: string}|null> $backgrounds Where the color is used; a null (a color the palette lacks) gives null.
	 * @param bool                                              $strict      Whether to give up when none reads.
	 * @return array{slug: string, hex: string}|null
	 */
	private static function pick( array $candidates, array $backgrounds, bool $strict = true ): ?array {
		if ( in_array( null, $backgrounds, true ) ) {
			return null;
		}
		$backgrounds = array_map( static fn( array $bg ): string => $bg['hex'], $backgrounds );
		$best        = null;
		$best_ratio  = -1.0;
		foreach ( array_filter( $candidates ) as $color ) {
			$ratio = Color::worst( $color['hex'], $backgrounds );
			if ( $ratio >= Color::TEXT ) {
				return $color;
			}
			if ( $ratio > $best_ratio ) {
				$best       = $color;
				$best_ratio = $ratio;
			}
		}
		return $strict ? null : $best;
	}
}
