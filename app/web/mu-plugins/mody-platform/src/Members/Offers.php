<?php
/**
 * The offers: the launch offer's numbers and who may take a place, and
 * invites on or off. Network Admin → Mody → Offers (tickets/invites-and-offers.md).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Members;

use Mody\Platform\Wizard\WizardPage;

defined( 'ABSPATH' ) || exit;

/**
 * Settings in the network option `mody_offers`, for super admins only
 * (decided by the maintainer on 2026-11-14). Each value falls back to the
 * launch offer of 2026-11-13 (Members::FREE_PLACES, FREE_MONTHS,
 * PROMO_MONTHS) until the page is saved.
 *
 * - **The launch offer:** on or off; who may take a place (`everyone`, or
 *   `invited` people only, who sign up through a member's invite link);
 *   its places, free months, and how many months it takes accounts. Its
 *   start is Members::PROMO_START_OPTION, set here or by the first place
 *   given; the places given are Members::PROMO_GIVEN_OPTION (read only here).
 * - **Invites:** on or off (Invites).
 *
 * A change applies to the next signups: accounts already given keep their
 * `free_until`.
 */
final class Offers {

	/** Network option holding the settings. */
	public const OPTION = 'mody_offers';

	/** Page slug, and the `edit.php` action the form posts to. */
	public const SLUG = 'mody-offers';

	/** Who may see and change the settings. */
	public const CAPABILITY = 'manage_network_options';

	/** Who may take a place of the launch offer. */
	public const WHO = array( 'everyone', 'invited' );

	/** The bounds of each number. */
	private const BOUNDS = array(
		'places'      => array( 0, 100000 ),
		'free_months' => array( 1, 36 ),
		'months'      => array( 1, 60 ),
	);

	/**
	 * The settings, with their defaults.
	 *
	 * @return array{launch_on: bool, launch_who: string, places: int, free_months: int, months: int, invites_on: bool}
	 */
	public static function settings(): array {
		$saved = get_site_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$int   = static function ( string $key, int $fallback ) use ( $saved ): int {
			if ( ! isset( $saved[ $key ] ) || ! is_numeric( $saved[ $key ] ) ) {
				return $fallback;
			}
			return max( self::BOUNDS[ $key ][0], min( self::BOUNDS[ $key ][1], (int) $saved[ $key ] ) );
		};
		return array(
			'launch_on'   => (bool) ( $saved['launch_on'] ?? true ),
			'launch_who'  => in_array( $saved['launch_who'] ?? '', self::WHO, true ) ? (string) $saved['launch_who'] : 'everyone',
			'places'      => $int( 'places', Members::FREE_PLACES ),
			'free_months' => $int( 'free_months', Members::FREE_MONTHS ),
			'months'      => $int( 'months', Members::PROMO_MONTHS ),
			'invites_on'  => (bool) ( $saved['invites_on'] ?? true ),
		);
	}

	/** Whether the launch offer is on. */
	public static function launch_on(): bool {
		return self::settings()['launch_on'];
	}

	/** Whether only invited people may take a place of the launch offer. */
	public static function invited_only(): bool {
		return 'invited' === self::settings()['launch_who'];
	}

	/** The launch offer's places. */
	public static function places(): int {
		return self::settings()['places'];
	}

	/** The months a place is free. */
	public static function free_months(): int {
		return self::settings()['free_months'];
	}

	/** The months the launch offer takes accounts, from its start. */
	public static function months(): int {
		return self::settings()['months'];
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
			WizardPage::SLUG,
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
		$number = static fn( string $key ): int => isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0;
		$who    = isset( $_POST['launch_who'] ) ? sanitize_key( wp_unslash( $_POST['launch_who'] ) ) : 'everyone';
		$start  = isset( $_POST['launch_start'] ) ? sanitize_text_field( wp_unslash( $_POST['launch_start'] ) ) : '';
		$values = array(
			'launch_on'   => ! empty( $_POST['launch_on'] ),
			'launch_who'  => in_array( $who, self::WHO, true ) ? $who : 'everyone',
			'places'      => $number( 'places' ),
			'free_months' => $number( 'free_months' ),
			'months'      => $number( 'months' ),
			'invites_on'  => ! empty( $_POST['invites_on'] ),
		);
		// phpcs:enable
		foreach ( self::BOUNDS as $key => $bounds ) {
			$values[ $key ] = max( $bounds[0], min( $bounds[1], $values[ $key ] ) );
		}
		update_site_option( self::OPTION, $values );

		// The start: a date (UTC midnight), or empty for "the first place given starts it".
		$error = '';
		if ( '' === $start ) {
			delete_site_option( Members::PROMO_START_OPTION );
		} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) && false !== strtotime( $start . ' 00:00:00 UTC' ) ) {
			update_site_option( Members::PROMO_START_OPTION, (int) strtotime( $start . ' 00:00:00 UTC' ) );
		} else {
			$error = 'start';
		}

		wp_safe_redirect(
			add_query_arg(
				array_filter(
					array(
						'page'    => self::SLUG,
						'updated' => 'true',
						'error'   => $error,
					)
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
		$start    = Members::promo_starts();
		$ends     = Members::promo_ends();
		$given    = Members::promo_given();
		$date     = static fn( int $time ): string => wp_date( get_option( 'date_format' ), $time );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only, after the redirect from save().
		$updated = isset( $_GET['updated'] );
		$error   = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable
		if ( ! $settings['launch_on'] ) {
			$state = __( 'Off: signing up means paying, when card payments are offered.', 'mody_cloud' );
		} elseif ( ! Members::promo_open( true ) ) {
			$state = Members::promo_left() > 0 ? __( 'Ended: its months have passed.', 'mody_cloud' ) : __( 'Ended: every place is taken.', 'mody_cloud' );
		} else {
			$state = self::invited_only() ? __( 'Open to invited people only.', 'mody_cloud' ) : __( 'Open to everyone.', 'mody_cloud' );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Offers', 'mody_cloud' ); ?></h1>
			<?php if ( $updated && 'start' === $error ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Settings saved, but the start date was not a date: it was left as it was.', 'mody_cloud' ); ?></p></div>
			<?php elseif ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved. They apply to the next signups; accounts already given keep their free months.', 'mody_cloud' ); ?></p>
					<p><?php esc_html_e( 'The home page and the legal pages state these numbers in their text: if you changed them, run scripts/home-page.php (and its translations) and scripts/legal-pages.php again on the main site.', 'mody_cloud' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::SLUG ) ); ?>">
				<?php wp_nonce_field( self::SLUG ); ?>
				<h2><?php esc_html_e( 'Launch offer', 'mody_cloud' ); ?></h2>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: places, 2: free months, 3: months it takes accounts. */
							__( 'The first %1$d accounts get one site free for %2$d months, no card, during the offer\'s first %3$d months.', 'mody_cloud' ),
							$settings['places'],
							$settings['free_months'],
							$settings['months']
						)
					);
					?>
					<strong><?php echo esc_html( $state ); ?></strong>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'On', 'mody_cloud' ); ?></th>
						<td>
							<label><input type="checkbox" name="launch_on" value="1" <?php checked( $settings['launch_on'] ); ?>> <?php esc_html_e( 'New accounts can take a place', 'mody_cloud' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Who can take a place', 'mody_cloud' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="launch_who" value="everyone" <?php checked( 'everyone', $settings['launch_who'] ); ?>> <?php esc_html_e( 'Everyone who signs up', 'mody_cloud' ); ?></label><br>
								<label><input type="radio" name="launch_who" value="invited" <?php checked( 'invited', $settings['launch_who'] ); ?>> <?php esc_html_e( 'Invited people only: they sign up through an invite link', 'mody_cloud' ); ?></label>
								<p class="description"><?php esc_html_e( 'Invited only keeps the places for the people you and the members invite. Your own invite link is on your Dashboard, under Invite friends. Others pay, or read that paid sign-ups open soon.', 'mody_cloud' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mody-offers-places"><?php esc_html_e( 'Places', 'mody_cloud' ); ?></label></th>
						<td>
							<input name="places" id="mody-offers-places" type="number" class="small-text" required min="<?php echo (int) self::BOUNDS['places'][0]; ?>" max="<?php echo (int) self::BOUNDS['places'][1]; ?>" value="<?php echo (int) $settings['places']; ?>">
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: places given, 2: places left. */
										__( 'Given so far: %1$d. Left: %2$d. A deleted account does not give its place back.', 'mody_cloud' ),
										$given,
										Members::promo_left()
									)
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mody-offers-free-months"><?php esc_html_e( 'Free for', 'mody_cloud' ); ?></label></th>
						<td>
							<input name="free_months" id="mody-offers-free-months" type="number" class="small-text" required min="<?php echo (int) self::BOUNDS['free_months'][0]; ?>" max="<?php echo (int) self::BOUNDS['free_months'][1]; ?>" value="<?php echo (int) $settings['free_months']; ?>">
							<?php esc_html_e( 'months', 'mody_cloud' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mody-offers-months"><?php esc_html_e( 'Takes accounts for', 'mody_cloud' ); ?></label></th>
						<td>
							<input name="months" id="mody-offers-months" type="number" class="small-text" required min="<?php echo (int) self::BOUNDS['months'][0]; ?>" max="<?php echo (int) self::BOUNDS['months'][1]; ?>" value="<?php echo (int) $settings['months']; ?>">
							<?php esc_html_e( 'months from its start', 'mody_cloud' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mody-offers-start"><?php esc_html_e( 'Start', 'mody_cloud' ); ?></label></th>
						<td>
							<input name="launch_start" id="mody-offers-start" type="date" value="<?php echo $start ? esc_attr( gmdate( 'Y-m-d', $start ) ) : ''; ?>">
							<p class="description">
								<?php
								echo esc_html(
									$start
										/* translators: 1: the start date, 2: the end date. */
										? sprintf( __( 'It started on %1$s and takes accounts until %2$s.', 'mody_cloud' ), $date( $start ), $date( $ends ) )
										: __( 'Empty: the first place given starts it.', 'mody_cloud' )
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Invites', 'mody_cloud' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'On', 'mody_cloud' ); ?></th>
						<td>
							<label><input type="checkbox" name="invites_on" value="1" <?php checked( $settings['invites_on'] ); ?>> <?php esc_html_e( 'Members see Invite friends on their Dashboard, and invite links record who invited', 'mody_cloud' ); ?></label>
							<p class="description"><?php esc_html_e( 'A point counts when an invited friend makes their first payment. Points give nothing yet: they are counted and shown. Off keeps the points already counted.', 'mody_cloud' ); ?></p>
							<p class="description">
								<?php
								$totals = Invites::totals();
								echo esc_html(
									sprintf(
										/* translators: 1: accounts that joined through an invite, 2: points earned. */
										__( 'Joined through an invite: %1$d. Points earned: %2$d.', 'mody_cloud' ),
										$totals['joined'],
										$totals['points']
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
