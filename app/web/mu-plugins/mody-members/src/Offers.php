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
	 * Hooked to `network_admin_menu` (after the Mody menu exists).
	 */
	public static function register_menu(): void {
		add_submenu_page(
			Members::MENU, // Under the network's Mody menu.
			__( 'Offers', 'mody_cloud' ),
			__( 'Offers', 'mody_cloud' ),
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Saves the form (`network_admin_edit_mody-offers`).
	 */
	public static function save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'mody_cloud' ), 403 );
		}
		check_admin_referer( self::SLUG );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked above.
		$who = isset( $_POST['launch_who'] ) ? sanitize_key( wp_unslash( $_POST['launch_who'] ) ) : 'everyone';
		update_site_option(
			self::OPTION,
			array(
				'launch_who' => in_array( $who, self::WHO, true ) ? $who : 'everyone',
				'invites_on' => ! empty( $_POST['invites_on'] ),
			)
		);
		// phpcs:enable
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SLUG,
					'updated' => 'true',
				),
				network_admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Renders the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$settings = self::settings();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, after the redirect from save().
		$updated = isset( $_GET['updated'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Offers', 'mody_cloud' ); ?></h1>
			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved. They apply to the next sign-ups.', 'mody_cloud' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::SLUG ) ); ?>">
				<?php wp_nonce_field( self::SLUG ); ?>
				<h2><?php esc_html_e( 'Sign-ups', 'mody_cloud' ); ?></h2>
				<p><?php esc_html_e( 'Every new account gets one free site, with no card and no end date. Registration itself opens and closes with the network option mody_registration_open.', 'mody_cloud' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Who can sign up', 'mody_cloud' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="launch_who" value="everyone" <?php checked( 'everyone', $settings['launch_who'] ); ?>> <?php esc_html_e( 'Everyone', 'mody_cloud' ); ?></label><br>
								<label><input type="radio" name="launch_who" value="invited" <?php checked( 'invited', $settings['launch_who'] ); ?>> <?php esc_html_e( 'Invited people only: they sign up through an invite link', 'mody_cloud' ); ?></label>
								<p class="description"><?php esc_html_e( 'Your own invite link is on your Dashboard, under Invite friends. Others read that sign-ups are by invitation for now.', 'mody_cloud' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Invites', 'mody_cloud' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'On', 'mody_cloud' ); ?></th>
						<td>
							<label><input type="checkbox" name="invites_on" value="1" <?php checked( $settings['invites_on'] ); ?>> <?php esc_html_e( 'Members see Invite friends on their Dashboard, and invite links record who invited', 'mody_cloud' ); ?></label>
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
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
