<?php
/**
 * wp-login.php in the site's own brand: its logo, its palette (through the
 * chosen scheme) and its fonts, all from the theme's variables.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Login;

defined( 'ABSPATH' ) || exit;

/**
 * Decided by the maintainer on 2026-11-10 (tickets/login-branding.md): each
 * site's login page is that site's: its logo (else its icon, else its name)
 * links to its home page, and its colors and fonts are its Global Styles,
 * read on every request, arranged by the scheme chosen in Settings → Login.
 *
 * Core's login, form and button styles read `--wp-admin-theme-color` and its
 * darker steps, so setting those to the scheme's button color restyles
 * buttons, focus rings and checkboxes without overriding each one.
 * `assets/login.css` does the rest with the scheme's `--mody-login-*` roles.
 */
final class LoginPage {

	/** Query argument that previews a scheme, for users who may choose one. */
	public const PREVIEW_ARG = 'mody-login-scheme';

	/** The logo's largest height on the page, in pixels (core's own logo is 84). */
	public const LOGO_HEIGHT = 84;

	/**
	 * Hooked on load.
	 */
	public static function register(): void {
		add_action( 'login_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'login_head', array( self::class, 'print_fonts' ) );
		add_filter( 'login_headerurl', array( self::class, 'header_url' ) );
		add_filter( 'login_headertext', array( self::class, 'header_text' ) );
		add_filter( 'login_body_class', array( self::class, 'body_class' ) );
		add_filter( 'authenticate', array( self::class, 'keep_preview' ), 100 );
	}

	/**
	 * `authenticate`, last: a preview link opened by someone already logged
	 * in shows the form instead of sending them to wp-admin. Core signs a
	 * logged-in visitor in again from their cookie and redirects; the error
	 * core itself gives an empty form (no username, no password) keeps the
	 * page. Only GET requests with the preview argument, from users who may
	 * choose a scheme: a real login is a POST and never carries it.
	 *
	 * @param mixed $user The user so far, or an error.
	 * @return mixed
	 */
	public static function keep_preview( $user ) {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: whether this is a preview.
		if ( 'GET' !== $method || ! isset( $_GET[ self::PREVIEW_ARG ] ) || 'wp-login.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return $user;
		}
		if ( ! $user instanceof \WP_User || ! user_can( $user, SettingsPage::CAPABILITY ) ) {
			return $user;
		}
		$empty = new \WP_Error( 'empty_username', '' );
		$empty->add( 'empty_password', '' );
		return $empty;
	}

	/**
	 * The scheme this request shows: a preview for those who may choose
	 * one, otherwise the site's own. Null leaves core's colors.
	 *
	 * @return array{slug: string, label: string, description: string, roles: array<string, array{slug: string, hex: string}>}|null
	 */
	public static function scheme(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: a preview for users who may change the setting.
		$preview = isset( $_GET[ self::PREVIEW_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::PREVIEW_ARG ] ) ) : '';
		if ( '' !== $preview && ! current_user_can( SettingsPage::CAPABILITY ) ) {
			$preview = '';
		}
		return Schemes::current( $preview );
	}

	/**
	 * `login_enqueue_scripts`: the theme's variables, the login stylesheet,
	 * and the scheme's colors and fonts on it.
	 */
	public static function enqueue(): void {
		self::enqueue_asset( 'mody-login', 'login.css', array( 'login' ) );
		wp_add_inline_style( 'mody-login', self::variables_css() . self::css( self::scheme() ) );
	}

	/**
	 * Enqueues one of the plugin's stylesheets, versioned by its file time.
	 *
	 * @param string   $handle The style handle.
	 * @param string   $file   The file in `assets/`.
	 * @param string[] $deps   Handles it follows.
	 */
	public static function enqueue_asset( string $handle, string $file, array $deps = array() ): void {
		$root = dirname( __DIR__, 2 );
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
	 * `login_head`: the @font-face rules of the site's fonts (theme and Font
	 * Library), served from this server.
	 */
	public static function print_fonts(): void {
		if ( function_exists( 'wp_print_font_faces' ) ) {
			wp_print_font_faces();
		}
	}

	/**
	 * The CSS that puts a scheme on the login page.
	 *
	 * @param array{slug: string, roles: array<string, array{slug: string, hex: string}>}|null $scheme The scheme, or null.
	 */
	public static function css( ?array $scheme ): string {
		$fonts = self::fonts();
		$css   = '';
		if ( null !== $scheme ) {
			$button = $scheme['roles']['button'];
			$dark10 = Color::darken( $button['hex'], 0.05 ); // Core's steps: Modern's #3858e9 is 5 and 10 points darker.
			$dark20 = Color::darken( $button['hex'], 0.1 );
			$rgb    = static fn( string $hex ): string => implode( ', ', Color::rgb( $hex ) );

			$properties = Schemes::properties( $scheme['roles'] ) + array(
				'--wp-admin-theme-color'                => Schemes::css( $button ),
				'--wp-admin-theme-color--rgb'           => $rgb( $button['hex'] ),
				'--wp-admin-theme-color-darker-10'      => $dark10,
				'--wp-admin-theme-color-darker-10--rgb' => $rgb( $dark10 ),
				'--wp-admin-theme-color-darker-20'      => $dark20,
				'--wp-admin-theme-color-darker-20--rgb' => $rgb( $dark20 ),
			);
			$css .= 'body.login.wp-core-ui{' . self::declarations( $properties + $fonts ) . '}';

			// Core's checked tick (forms.css) is a white SVG: the same icon in the button's text color.
			$tick = 'data:image/svg+xml;utf8,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20viewBox%3D%270%200%2020%2020%27%3E%3Cpath%20d%3D%27M14.83%204.89l1.34.94-5.81%208.38H9.02L5.78%209.67l1.34-1.25%202.57%202.4z%27%20fill%3D%27%23' . substr( $scheme['roles']['button_text']['hex'], 1 ) . '%27%2F%3E%3C%2Fsvg%3E';
			$css .= 'body.mody-login input[type="checkbox"]:checked::before{content:url("' . $tick . '");content:url("' . $tick . '") / ""}';
		} elseif ( $fonts ) {
			$css .= 'body.login.wp-core-ui{' . self::declarations( $fonts ) . '}';
		}
		return $css;
	}

	/**
	 * CSS declarations from property => value pairs.
	 *
	 * @param array<string, string> $properties The properties.
	 */
	public static function declarations( array $properties ): string {
		$out = '';
		foreach ( $properties as $name => $value ) {
			$out .= $name . ':' . $value . ';';
		}
		return $out;
	}

	/**
	 * The site's text and heading fonts as `--mody-login-font` and
	 * `--mody-login-heading-font`, when Global Styles set them to a font of
	 * the theme's (a `--wp--preset--font-family--*` variable).
	 *
	 * @return array<string, string>
	 */
	public static function fonts(): array {
		$fonts = array();
		$paths = array(
			'--mody-login-font'         => array( 'typography', 'fontFamily' ),
			'--mody-login-heading-font' => array( 'elements', 'heading', 'typography', 'fontFamily' ),
		);
		foreach ( $paths as $property => $path ) {
			$value = wp_get_global_styles( $path );
			$slug  = is_string( $value ) ? self::font_slug( $value ) : '';
			if ( '' !== $slug ) {
				$fonts[ $property ] = 'var(--wp--preset--font-family--' . $slug . ')';
			}
		}
		return $fonts;
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
	 * `login_body_class`: marks the page as branded, and names its scheme.
	 *
	 * @param string[] $classes The body classes.
	 * @return string[]
	 */
	public static function body_class( $classes ): array {
		$classes   = (array) $classes;
		$classes[] = 'mody-login';
		$scheme    = self::scheme();
		if ( null !== $scheme ) {
			$classes[] = 'mody-login--' . $scheme['slug'];
		}
		return $classes;
	}

	/**
	 * `login_headerurl`: the logo links to the site.
	 */
	public static function header_url(): string {
		return home_url( '/' );
	}

	/**
	 * `login_headertext`: the site's logo. Core prints this unescaped inside
	 * the link: everything is escaped here.
	 */
	public static function header_text(): string {
		return self::logo_html();
	}

	/**
	 * The site's logo as an image on its plate, else its icon, else its name.
	 * Also for the previews in Settings → Login.
	 *
	 * @param string $sizes The image's `sizes` attribute.
	 */
	public static function logo_html( string $sizes = '(max-width: 400px) 90vw, 320px' ): string {
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
}
