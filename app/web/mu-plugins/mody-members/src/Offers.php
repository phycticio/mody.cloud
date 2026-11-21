<?php
/**
 * The offers: who may sign up, and invites on or off. Network Admin → Mody →
 * Offers (tickets/invites-and-offers.md; cut down by tickets/free-mvp.md).
 *
 * @package Mody\Members
 */

namespace Mody\Members;


defined( 'ABSPATH' ) || exit;

/**
 * Settings in the network option `mody_offers`, for super admins only
 * (decided by the maintainer on 2026-11-14).
 *
 * - **Who may sign up:** `everyone`, or `invited` people only, who sign up
 *   through a member's invite link. Every account is free (decided by the
 *   maintainer on 2026-11-20); the launch offer's places, free months and
 *   months are gone with it.
 * - **Invites:** on or off (Invites).
 */
final class Offers {

	/** Network option holding the settings. */
	public const OPTION = 'mody_offers';

	/** Page slug, and the `edit.php` action the form posts to. */
	public const SLUG = 'mody-offers';

	/** Who may see and change the settings. */
	public const CAPABILITY = 'manage_network_options';

	/** Who may sign up. */
	public const WHO = array( 'everyone', 'invited' );

	/**
	 * The settings, with their defaults. `launch_who` keeps the key the
	 * launch offer saved it under.
	 *
	 * @return array{launch_who: string, invites_on: bool}
	 */
	public static function settings(): array {
		$saved = get_site_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'launch_who' => in_array( $saved['launch_who'] ?? '', self::WHO, true ) ? (string) $saved['launch_who'] : 'everyone',
			'invites_on' => (bool) ( $saved['invites_on'] ?? true ),
		);
	}

	/** Whether only invited people may sign up. */
	public static function invited_only(): bool {
		return 'invited' === self::settings()['launch_who'];
	}

	/** Whether invites are on: the Invite friends section, and links that record who invited. */
	public static function invites_on(): bool {
		return self::settings()['invites_on'];
	}

	/**
	 * The page's sections: slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function sections(): array {
		return array(
			'signups' => __( 'Sign-ups', 'mody_cloud' ),
			'invites' => __( 'Invites', 'mody_cloud' ),
		);
	}

	/**
	 * A section's address.
	 *
	 * @param string               $section A key of sections().
	 * @param array<string, mixed> $extra   More query arguments.
	 */
	public static function url( string $section = 'signups', array $extra = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page'    => self::SLUG,
					'section' => $section,
				),
				$extra
			),
			network_admin_url( 'admin.php' )
		);
	}

	/**
	 * The section asked for.
	 */
	private static function current(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which section to show.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		return isset( self::sections()[ $section ] ) ? $section : 'signups';
	}

	/**
	 * Hooked to `network_admin_menu` (after the Mody menu exists).
	 */
	public static function register_menu(): void {
		$hook = add_submenu_page(
			Members::MENU, // Under the network's Mody menu.
			__( 'Offers', 'mody_cloud' ),
			__( 'Offers', 'mody_cloud' ),
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'enqueue' ) );
		}
	}

	/**
	 * `load-<page>`: the Mody dashboard's layout (a copy in this plugin).
	 */
	public static function enqueue(): void {
		$root = dirname( __DIR__ );
		wp_enqueue_style( 'mody-members-admin-page', plugins_url( 'assets/admin-page.css', $root . '/mody-members.php' ), array(), (string) filemtime( $root . '/assets/admin-page.css' ) );
	}

	/**
	 * Saves one section's form (`network_admin_edit_mody-offers`); the other
	 * section's setting stays as it was.
	 */
	public static function save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'mody_cloud' ), 403 );
		}
		check_admin_referer( self::SLUG );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked above.
		$section  = isset( $_POST['section'] ) && 'invites' === $_POST['section'] ? 'invites' : 'signups';
		$settings = self::settings();
		if ( 'invites' === $section ) {
			$settings['invites_on'] = ! empty( $_POST['invites_on'] );
		} else {
			$who                    = isset( $_POST['launch_who'] ) ? sanitize_key( wp_unslash( $_POST['launch_who'] ) ) : 'everyone';
			$settings['launch_who'] = in_array( $who, self::WHO, true ) ? $who : 'everyone';
		}
		// phpcs:enable
		update_site_option( self::OPTION, $settings );
		wp_safe_redirect( self::url( $section, array( 'updated' => 'true' ) ) );
		exit;
	}

	/**
	 * Renders the page, in the Mody dashboard's layout.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$settings = self::settings();
		$sections = self::sections();
		$section  = self::current();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, after the redirect from save().
		$updated = isset( $_GET['updated'] );
		?>
		<div class="wrap mody-brand mody-offers">
			<h1><?php esc_html_e( 'Offers', 'mody_cloud' ); ?></h1>
			<div class="mody-brand__layout">
				<nav class="mody-brand__sections" aria-label="<?php esc_attr_e( 'Offers sections', 'mody_cloud' ); ?>">
					<ul>
						<?php foreach ( $sections as $slug => $label ) : ?>
							<li>
								<a href="<?php echo esc_url( self::url( $slug ) ); ?>" class="mody-brand__section-link<?php echo $slug === $section ? ' is-active' : ''; ?>" <?php echo $slug === $section ? 'aria-current="page"' : ''; ?>>
									<span><?php echo esc_html( $label ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</nav>
				<div class="mody-brand__main">
					<section class="mody-brand__card" aria-label="<?php esc_attr_e( 'The network', 'mody_cloud' ); ?>">
						<div class="mody-brand__card-text">
							<p class="mody-brand__name"><?php echo esc_html( wp_specialchars_decode( (string) get_network()->site_name, ENT_QUOTES ) ); ?></p>
							<p class="mody-brand__tagline"><?php esc_html_e( 'Every new account gets one free site, with no card and no end date. Registration itself opens and closes with the network option mody_registration_open.', 'mody_cloud' ); ?></p>
						</div>
					</section>
					<?php if ( $updated ) : ?>
						<div class="notice notice-success inline mody-brand__notice"><p><?php esc_html_e( 'Settings saved. They apply to the next sign-ups.', 'mody_cloud' ); ?></p></div>
					<?php endif; ?>
					<section class="mody-brand__panel" aria-labelledby="mody-offers-section-title">
						<div class="mody-brand__panel-head">
							<h2 id="mody-offers-section-title"><?php echo esc_html( $sections[ $section ] ); ?></h2>
						</div>
						<div class="mody-brand__panel-body">
							<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::SLUG ) ); ?>" class="mody-brand__form">
								<?php wp_nonce_field( self::SLUG ); ?>
								<input type="hidden" name="section" value="<?php echo esc_attr( $section ); ?>" />
								<?php if ( 'invites' === $section ) : ?>
									<div class="mody-brand__field">
										<label class="mody-brand__choice"><input type="checkbox" name="invites_on" value="1" <?php checked( $settings['invites_on'] ); ?>> <?php esc_html_e( 'Members see Invite friends on their Dashboard, and invite links record who invited', 'mody_cloud' ); ?></label>
										<p class="description">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %d: accounts that joined through an invite. */
													__( 'Joined through an invite: %d.', 'mody_cloud' ),
													Invites::totals()['joined']
												)
											);
											?>
										</p>
									</div>
								<?php else : ?>
									<fieldset class="mody-brand__field">
										<legend><?php esc_html_e( 'Who can sign up', 'mody_cloud' ); ?></legend>
										<label class="mody-brand__choice"><input type="radio" name="launch_who" value="everyone" <?php checked( 'everyone', $settings['launch_who'] ); ?>> <?php esc_html_e( 'Everyone', 'mody_cloud' ); ?></label>
										<label class="mody-brand__choice"><input type="radio" name="launch_who" value="invited" <?php checked( 'invited', $settings['launch_who'] ); ?>> <?php esc_html_e( 'Invited people only: they sign up through an invite link', 'mody_cloud' ); ?></label>
										<p class="description"><?php esc_html_e( 'Your own invite link is on your Dashboard, under Invite friends. Others read that sign-ups are by invitation for now.', 'mody_cloud' ); ?></p>
									</fieldset>
								<?php endif; ?>
								<?php submit_button( __( 'Save', 'mody_cloud' ) ); ?>
							</form>
						</div>
					</section>
				</div>
			</div>
		</div>
		<?php
	}
}
