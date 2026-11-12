<?php
/**
 * Settings → Login: tabs on the left, the tab's settings on the right. One
 * tab today: Login color scheme.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Login;

defined( 'ABSPATH' ) || exit;

/**
 * Asked for by the maintainer on 2026-11-10 (tickets/login-branding.md). On
 * every site, for whoever manages its settings (members on their own sites).
 * The schemes are previewed as small login pages with the site's own logo,
 * colors and fonts. The choice is the site option Schemes::OPTION, saved
 * through core's options.php.
 */
final class SettingsPage {

	/** Page slug under Settings. */
	public const SLUG = 'mody-login';

	/** The settings group the form saves. */
	public const GROUP = 'mody_login';

	/** Who may see the page, choose a scheme and preview one on wp-login.php. */
	public const CAPABILITY = 'manage_options';

	/**
	 * Hooked to `admin_init` (and `rest_api_init`, so the option is known
	 * wherever settings are saved): the option, sanitized to a known scheme.
	 */
	public static function register_setting(): void {
		register_setting(
			self::GROUP,
			Schemes::OPTION,
			array(
				'type'              => 'string',
				'label'             => __( 'Login color scheme', 'mody_cloud' ),
				'sanitize_callback' => array( Schemes::class, 'sanitize' ),
				'default'           => Schemes::DEFAULT,
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Hooked to `admin_menu`: Settings → Login.
	 */
	public static function register_menu(): void {
		$hook = add_options_page(
			__( 'Login', 'mody_cloud' ),
			__( 'Login', 'mody_cloud' ),
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'enqueue' ) );
			add_action( 'admin_head-' . $hook, array( LoginPage::class, 'print_fonts' ) );
		}
	}

	/**
	 * The page's tabs: slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return array(
			'scheme' => __( 'Login color scheme', 'mody_cloud' ),
		);
	}

	/**
	 * `load-<page>`: the page's styles and the site's theme variables, so
	 * the previews show the site's own colors and fonts.
	 */
	public static function enqueue(): void {
		LoginPage::enqueue_asset( 'mody-login-settings', 'login-settings.css' );
		wp_add_inline_style( 'mody-login-settings', LoginPage::variables_css() . '.mody-login-settings{' . LoginPage::declarations( LoginPage::fonts() ) . '}' );
	}

	/**
	 * Renders the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which tab to show.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$tab = isset( $tabs[ $tab ] ) ? $tab : (string) array_key_first( $tabs );
		?>
		<div class="wrap mody-login-settings">
			<h1><?php esc_html_e( 'Login', 'mody_cloud' ); ?></h1>
			<div class="mody-login-settings__layout">
				<nav class="mody-login-settings__tabs" aria-label="<?php esc_attr_e( 'Login settings', 'mody_cloud' ); ?>">
					<ul>
						<?php foreach ( $tabs as $slug => $label ) : ?>
							<li>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $slug ), admin_url( 'options-general.php' ) ) ); ?>"
									class="mody-login-settings__tab<?php echo $slug === $tab ? ' is-active' : ''; ?>"
									<?php echo $slug === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				</nav>
				<div class="mody-login-settings__panel">
					<?php self::render_scheme_tab(); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The Login color scheme tab: the site, then the schemes its palette allows.
	 */
	private static function render_scheme_tab(): void {
		$palette = Schemes::palette();
		$schemes = Schemes::for_palette( $palette );
		$chosen  = Schemes::chosen();
		$shown   = isset( $schemes[ $chosen ] ) ? $chosen : Schemes::DEFAULT;
		?>
		<h2><?php esc_html_e( 'Login color scheme', 'mody_cloud' ); ?></h2>
		<p class="mody-login-settings__intro">
			<?php esc_html_e( 'How this site\'s login page looks. Every scheme uses the site\'s own logo, colors and fonts, so it follows them when they change. Schemes that would be hard to read with these colors are not offered.', 'mody_cloud' ); ?>
		</p>

		<?php self::render_site_card( $palette ); ?>

		<?php if ( ! $schemes ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'This site\'s palette has no background, text or primary color, so the login page keeps WordPress\'s own colors. Set them in Editor → Styles → Colors.', 'mody_cloud' ); ?>
			</p></div>
			<?php
			return;
		endif;
		?>

		<?php if ( $shown !== $chosen ) : ?>
			<div class="notice notice-info inline"><p>
				<?php esc_html_e( 'The scheme chosen before is no longer readable with the site\'s colors, so the login page shows Light until another one is chosen.', 'mody_cloud' ); ?>
			</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( self::GROUP ); ?>
			<fieldset class="mody-login-schemes">
				<legend class="screen-reader-text"><?php esc_html_e( 'Login color scheme', 'mody_cloud' ); ?></legend>
				<?php foreach ( $schemes as $slug => $scheme ) : ?>
					<div class="mody-login-scheme">
						<input type="radio" class="mody-login-scheme__input" id="mody-login-scheme-<?php echo esc_attr( $slug ); ?>"
							name="<?php echo esc_attr( Schemes::OPTION ); ?>" value="<?php echo esc_attr( $slug ); ?>"
							aria-describedby="mody-login-scheme-<?php echo esc_attr( $slug ); ?>-description"
							<?php checked( $shown, $slug ); ?> />
						<label class="mody-login-scheme__label" for="mody-login-scheme-<?php echo esc_attr( $slug ); ?>">
							<?php self::render_preview( $slug, $scheme['roles'] ); ?>
							<span class="mody-login-scheme__name"><?php echo esc_html( $scheme['label'] ); ?></span>
						</label>
						<p class="mody-login-scheme__description" id="mody-login-scheme-<?php echo esc_attr( $slug ); ?>-description"><?php echo esc_html( $scheme['description'] ); ?></p>
						<a class="mody-login-scheme__open" href="<?php echo esc_url( add_query_arg( LoginPage::PREVIEW_ARG, $slug, wp_login_url() ) ); ?>" target="_blank" rel="noopener">
							<?php
							/* translators: %s: the scheme's name. */
							echo esc_html( sprintf( __( 'Preview %s on the login page', 'mody_cloud' ), $scheme['label'] ) );
							?>
							<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'mody_cloud' ); ?></span>
						</a>
					</div>
				<?php endforeach; ?>
			</fieldset>
			<?php submit_button( __( 'Save the login color scheme', 'mody_cloud' ) ); ?>
		</form>
		<?php
	}

	/**
	 * The site as the login page shows it: its logo, name and tagline, its
	 * palette, and where to change them.
	 *
	 * @param array<string, string> $palette Slug => hex.
	 */
	private static function render_site_card( array $palette ): void {
		$can_edit = current_user_can( 'edit_theme_options' );
		$tagline  = (string) get_bloginfo( 'description' );
		?>
		<section class="mody-login-site" aria-labelledby="mody-login-site-title">
			<div class="mody-login-site__logo"><?php echo LoginPage::logo_html( '240px' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in logo_html(). ?></div>
			<div class="mody-login-site__details">
				<h3 id="mody-login-site-title" class="mody-login-site__name"><?php echo esc_html( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ); ?></h3>
				<?php if ( '' !== $tagline ) : ?>
					<p class="mody-login-site__tagline"><?php echo esc_html( wp_specialchars_decode( $tagline, ENT_QUOTES ) ); ?></p>
				<?php endif; ?>
				<ul class="mody-login-site__palette" aria-label="<?php esc_attr_e( 'The site\'s colors', 'mody_cloud' ); ?>">
					<?php foreach ( $palette as $slug => $hex ) : ?>
						<li><span class="mody-login-site__swatch" style="background:<?php echo esc_attr( $hex ); ?>"></span><?php echo esc_html( ucfirst( $slug ) ); ?> <code><?php echo esc_html( $hex ); ?></code></li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $can_edit ) : ?>
					<p class="mody-login-site__links">
						<a href="<?php echo esc_url( add_query_arg( 'p', rawurlencode( '/identity' ), admin_url( 'site-editor.php' ) ) ); ?>"><?php esc_html_e( 'Change the logo', 'mody_cloud' ); ?></a>
						<a href="<?php echo esc_url( add_query_arg( 'p', rawurlencode( '/styles' ), admin_url( 'site-editor.php' ) ) ); ?>"><?php esc_html_e( 'Change the colors and fonts', 'mody_cloud' ); ?></a>
					</p>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * A scheme as a small login page: the logo, two fields, the button and a link.
	 *
	 * @param string                                          $slug  The scheme.
	 * @param array<string, array{slug: string, hex: string}> $roles The scheme's roles.
	 */
	private static function render_preview( string $slug, array $roles ): void {
		?>
		<span class="mody-login-preview mody-login--<?php echo esc_attr( $slug ); ?>" style="<?php echo esc_attr( LoginPage::declarations( Schemes::properties( $roles ) ) ); ?>" aria-hidden="true">
			<span class="mody-login-preview__brand"><?php echo LoginPage::logo_html( '160px' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in logo_html(). ?></span>
			<span class="mody-login-preview__card">
				<span class="mody-login-preview__label"><?php esc_html_e( 'Username or Email Address' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own string. ?></span>
				<span class="mody-login-preview__field"></span>
				<span class="mody-login-preview__label"><?php esc_html_e( 'Password' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own string. ?></span>
				<span class="mody-login-preview__field"></span>
				<span class="mody-login-preview__row">
					<span class="mody-login-preview__check"></span>
					<span class="mody-login-preview__button"><?php esc_html_e( 'Log In' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own string. ?></span>
				</span>
			</span>
			<span class="mody-login-preview__nav"><?php esc_html_e( 'Lost your password?' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own string. ?></span>
		</span>
		<?php
	}
}
