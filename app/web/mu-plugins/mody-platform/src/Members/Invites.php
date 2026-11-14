<?php
/**
 * Invite friends: each member's invite link, who joined through it, and a
 * point when an invited friend first pays (tickets/invites-and-offers.md).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Members;

defined( 'ABSPATH' ) || exit;

/**
 * Decided by the maintainer on 2026-11-14:
 * - **The link:** `<main site>/register/?ref=<code>`. The code is the user
 *   meta `mody_invite_code`, random (never the name or email), made the
 *   first time it is needed. Members and super admins have one.
 * - **The reference:** a signup through a valid link stores the inviter in
 *   the new account's `mody_members.invited_by` (Registration). No cookie:
 *   the code travels from the page's address to the chat or the form.
 * - **The point:** when the invited account's first payment is paid
 *   (Billing: a `signup` or `charge` payment), `invite_point_at` is set,
 *   once. A withdrawal takes it back (uncredit()).
 * - **Points give nothing yet**, and the section says so (rule 7).
 * - **The section** shows the link, how many joined and the points, never
 *   who: that would tell whether an email has an account.
 * - **Off** (Offers::invites_on()): no section, and links record nothing.
 */
final class Invites {

	/** User meta: the user's invite code. */
	public const META = 'mody_invite_code';

	/** The Dashboard's section. */
	public const SECTION = 'invite';

	/** The query argument of an invite link. */
	public const ARG = 'ref';

	/** A code's length. */
	private const LENGTH = 10;

	/**
	 * A code as typed or posted: lowercase letters and digits, or ''.
	 *
	 * @param mixed $code Anything.
	 */
	public static function clean( mixed $code ): string {
		$code = is_string( $code ) ? strtolower( trim( $code ) ) : '';
		return preg_match( '/^[a-z0-9]{' . self::LENGTH . '}$/', $code ) ? $code : '';
	}

	/**
	 * The user's code, made the first time.
	 *
	 * @param int $user_id A user.
	 */
	public static function code( int $user_id ): string {
		$code = self::clean( get_user_meta( $user_id, self::META, true ) );
		if ( '' !== $code ) {
			return $code;
		}
		do {
			$code = strtolower( wp_generate_password( self::LENGTH, false ) );
		} while ( self::owner( $code ) );
		update_user_meta( $user_id, self::META, $code );
		return $code;
	}

	/**
	 * The user a code belongs to, or 0.
	 *
	 * @param string $code A clean code.
	 */
	private static function owner( string $code ): int {
		if ( '' === $code ) {
			return 0;
		}
		$users = get_users(
			array(
				'blog_id'    => 0, // Every user of the network.
				'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One lookup per signup.
				'meta_value' => $code, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
				'fields'     => 'ID',
				'number'     => 1,
			)
		);
		return $users ? (int) $users[0] : 0;
	}

	/**
	 * Who invites through a code: a member or a super admin, while invites
	 * are on; 0 otherwise (an unknown code is ignored).
	 *
	 * @param mixed $code The code from a link.
	 */
	public static function inviter( mixed $code ): int {
		if ( ! Offers::invites_on() ) {
			return 0;
		}
		$user_id = self::owner( self::clean( $code ) );
		return $user_id && self::can_invite( $user_id ) ? $user_id : 0;
	}

	/**
	 * Whether a user has an invite link: a member or a super admin.
	 *
	 * @param int $user_id A user.
	 */
	public static function can_invite( int $user_id ): bool {
		return $user_id > 0 && ( null !== Members::get( $user_id ) || is_super_admin( $user_id ) );
	}

	/**
	 * Whether the current user sees the Invite friends section.
	 */
	public static function available(): bool {
		return Offers::invites_on() && self::can_invite( get_current_user_id() );
	}

	/**
	 * The user's invite link: the main site's signup page with their code.
	 *
	 * @param int $user_id A user who can invite.
	 */
	public static function url( int $user_id ): string {
		return add_query_arg( self::ARG, self::code( $user_id ), get_home_url( get_main_site_id(), '/' . Registration::PAGE_SLUG . '/' ) );
	}

	/**
	 * Records who invited a new account (Registration, right after its row).
	 *
	 * @param int $user_id The new member.
	 * @param int $inviter Who invited them, or 0.
	 */
	public static function record( int $user_id, int $inviter ): void {
		if ( $inviter > 0 && $inviter !== $user_id && Members::get( $user_id ) ) {
			Members::update( $user_id, array( 'invited_by' => $inviter ) );
		}
	}

	/**
	 * The invited account's first payment was paid: its inviter's point (once).
	 *
	 * @param int $user_id The member who paid.
	 */
	public static function credit( int $user_id ): void {
		$row = Members::get( $user_id );
		if ( $row && ! empty( $row['invited_by'] ) && empty( $row['invite_point_at'] ) ) {
			Members::update( $user_id, array( 'invite_point_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		}
	}

	/**
	 * The invited account withdrew (its first payment refunded): the point goes.
	 *
	 * @param int $user_id The member.
	 */
	public static function uncredit( int $user_id ): void {
		$row = Members::get( $user_id );
		if ( $row && ! empty( $row['invite_point_at'] ) ) {
			Members::update( $user_id, array( 'invite_point_at' => null ) );
		}
	}

	/**
	 * How many joined through a user's link, and their points.
	 *
	 * @param int $inviter A user.
	 * @return array{joined: int, points: int}
	 */
	public static function counts( int $inviter ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS joined, COUNT(invite_point_at) AS points FROM ' . Members::table() . ' WHERE invited_by = %d', $inviter ), ARRAY_A );
		return array(
			'joined' => (int) ( $row['joined'] ?? 0 ),
			'points' => (int) ( $row['points'] ?? 0 ),
		);
	}

	/**
	 * The whole network's invites (the Offers page).
	 *
	 * @return array{joined: int, points: int}
	 */
	public static function totals(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$row = $wpdb->get_row( 'SELECT COUNT(invited_by) AS joined, COUNT(invite_point_at) AS points FROM ' . Members::table(), ARRAY_A );
		return array(
			'joined' => (int) ( $row['joined'] ?? 0 ),
			'points' => (int) ( $row['points'] ?? 0 ),
		);
	}

	/**
	 * What an invited friend gets now, in a sentence.
	 */
	public static function offer_sentence(): string {
		if ( Members::promo_open( true ) ) {
			return sprintf(
				/* translators: %d: free months. */
				_n( 'Your friends get what every new account gets now: one site free for %d month, no card needed, while the launch offer has places.', 'Your friends get what every new account gets now: one site free for %d months, no card needed, while the launch offer has places.', Offers::free_months(), 'mody_cloud' ),
				Offers::free_months()
			);
		}
		return sprintf(
			/* translators: %s: a site's monthly price, such as 7.99 EUR. */
			__( 'Your friends sign up like everyone: %s a month per site, VAT included.', 'mody_cloud' ),
			Members::price( Members::PRICE['monthly'] )
		);
	}

	/**
	 * The Dashboard's Invite friends section.
	 */
	public static function render(): void {
		$user   = get_current_user_id();
		$url    = self::url( $user );
		$counts = self::counts( $user );
		?>
		<p><?php esc_html_e( 'Know someone who needs a website? Send them your link. When they sign up through it, they are counted as yours.', 'mody_cloud' ); ?></p>
		<p class="mody-invite__link">
			<label for="mody-invite-url" class="screen-reader-text"><?php esc_html_e( 'Your invite link', 'mody_cloud' ); ?></label>
			<input id="mody-invite-url" type="text" class="regular-text code" readonly value="<?php echo esc_attr( $url ); ?>" onfocus="this.select()">
			<button type="button" class="button" id="mody-invite-copy" data-copied="<?php esc_attr_e( 'Copied', 'mody_cloud' ); ?>"><?php esc_html_e( 'Copy link', 'mody_cloud' ); ?></button>
		</p>
		<p class="description"><?php echo esc_html( self::offer_sentence() ); ?></p>
		<table class="mody-brand__rows">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Joined through your link', 'mody_cloud' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $counts['joined'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Your points', 'mody_cloud' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $counts['points'] ) ); ?></td>
				</tr>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'You earn a point when a friend you invited makes their first payment. Points give nothing yet: we count them now, and we will tell you before they are worth something.', 'mody_cloud' ); ?></p>
		<script>
		( function () {
			var button = document.getElementById( 'mody-invite-copy' );
			var input = document.getElementById( 'mody-invite-url' );
			if ( ! button || ! input || ! navigator.clipboard ) {
				return;
			}
			button.addEventListener( 'click', function () {
				navigator.clipboard.writeText( input.value ).then( function () {
					button.textContent = button.getAttribute( 'data-copied' );
				} );
			} );
		} )();
		</script>
		<?php
	}
}
