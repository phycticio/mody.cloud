<?php
/**
 * The mail a member's site can make the platform send for them (security pass, 2026-11-12).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Gating;

defined( 'ABSPATH' ) || exit;

/**
 * Every message leaves from the platform's own sender (`mody-mail`), so two
 * things on a member site could make Mody Cloud mail strangers:
 *
 * - **Settings → General → Administration Email Address.** Core mails a
 *   confirmation to whatever address is typed, with the site's title in the
 *   subject and the text. A site may ask for that CHANGE_LIMIT times a day.
 * - **Users → Add Existing User.** Core mails an invitation to a registered
 *   account, and answers differently when the address has none (an oracle for
 *   which emails have an account). A site may send INVITATION_LIMIT a day,
 *   counting every attempt, and the answer is the same whether or not the
 *   address has an account.
 *
 * In both, the site title is the member's own text: it is shortened to
 * TITLE_LENGTH characters and stripped of line breaks, so it cannot turn the
 * subject into a message of its own.
 *
 * Super admins and the main site are left alone.
 */
final class MemberMail {

	/** Administration email changes (confirmation emails) a site may ask for in a day. */
	public const CHANGE_LIMIT = 3;

	/** Invitations a site may send in a day, counting every attempt. */
	public const INVITATION_LIMIT = 20;

	/** The site title in a subject or body, at most this many characters. */
	public const TITLE_LENGTH = 40;

	/** Core's text shown after an invitation was sent; replaced by the neutral one. */
	private const CORE_INVITED = 'Invitation email sent to user. A confirmation link must be clicked for them to be added to your site.';

	/**
	 * Filters `pre_update_option_new_admin_email`: a new address is accepted
	 * (and core mails its confirmation) only while the site is under its
	 * daily limit; over it, the old value stays and the settings page says why.
	 *
	 * @param mixed $value     The new value.
	 * @param mixed $old_value The old value.
	 * @return mixed
	 */
	public static function limit_admin_email_changes( $value, $old_value ) {
		if ( ! MemberSite::applies_to( get_current_user_id() ) || ! is_string( $value ) || ! is_email( $value ) || $value === $old_value || get_option( 'admin_email' ) === $value ) {
			return $value; // Nothing core would mail.
		}
		if ( Throttle::hit( 'admin_email', self::CHANGE_LIMIT, DAY_IN_SECONDS ) ) {
			return $value;
		}
		add_settings_error(
			'general',
			'mody_admin_email_limit',
			sprintf(
				/* translators: 1: how many a day, 2: how long until another is possible, such as "5 hours". */
				__( 'This site has asked for %1$d administration email changes today, which is the most a site can ask for in a day. You can ask again in %2$s.', 'mody_cloud' ),
				self::CHANGE_LIMIT,
				human_time_diff( time(), time() + Throttle::wait( 'admin_email', self::CHANGE_LIMIT, DAY_IN_SECONDS ) )
			),
			'error'
		);
		return $old_value;
	}

	/**
	 * Filters `new_admin_email_subject` (the confirmation email): the site's
	 * title, shortened.
	 *
	 * @param mixed $subject Core's subject.
	 * @return mixed
	 */
	public static function admin_email_subject( $subject ) {
		if ( ! MemberSite::is_member_site() ) {
			return $subject;
		}
		/* translators: Core's own string, with the site title shortened. %s: Site title. */
		return sprintf( __( '[%s] New Admin Email Address' ), self::short_title() ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own string.
	}

	/**
	 * Filters `new_admin_email_content` (the confirmation email): the site's
	 * title, shortened, in the place of core's `###SITENAME###`.
	 *
	 * @param mixed $text Core's text, before its placeholders are filled in.
	 * @return mixed
	 */
	public static function admin_email_content( $text ) {
		if ( ! MemberSite::is_member_site() || ! is_string( $text ) ) {
			return $text;
		}
		return str_replace( '###SITENAME###', self::short_title(), $text );
	}

	/**
	 * Filters `invited_user_email` (the invitation): the site's title,
	 * shortened, in the subject and the text.
	 *
	 * @param mixed $email The email core builds: `to`, `subject`, `message`, `headers`.
	 * @return mixed
	 */
	public static function invitation_email( $email ) {
		if ( ! MemberSite::is_member_site() || ! is_array( $email ) || ! isset( $email['subject'], $email['message'] ) ) {
			return $email;
		}
		$title = self::short_title();
		/* translators: Core's own string, with the site title shortened. %s: Site title. */
		$email['subject'] = sprintf( __( '[%s] Joining Confirmation' ), $title ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own string.
		$name             = (string) get_option( 'blogname' );
		if ( '' !== $name ) {
			$email['message'] = str_replace( $name, $title, (string) $email['message'] );
		}
		return $email;
	}

	/**
	 * Hooked to `load-user-new.php`: Add Existing User, for members.
	 *
	 * Every attempt counts toward INVITATION_LIMIT, and an address with no
	 * account gets the answer an invited one does, with nothing sent. Nonce
	 * and capability checks stay core's; the nonce is checked here too, so a
	 * forged request cannot use up the site's invitations.
	 */
	public static function handle_invitation(): void {
		if ( ! MemberSite::applies_to( get_current_user_id() ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only: which message core is about to show.
		if ( isset( $_GET['update'] ) && 'add' === sanitize_key( wp_unslash( $_GET['update'] ) ) ) {
			add_filter( 'gettext', array( self::class, 'neutral_text' ), 10, 2 );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below, as core checks it.
		if ( 'adduser' !== sanitize_key( wp_unslash( $_REQUEST['action'] ?? '' ) ) ) {
			return;
		}
		check_admin_referer( 'add-user', '_wpnonce_add-user' );

		if ( ! Throttle::hit( 'invitations', self::INVITATION_LIMIT, DAY_IN_SECONDS ) ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: invitations a day, 2: how long until another is possible, such as "5 hours". */
						__( 'This site has sent %1$d invitations today, which is the most a site can send in a day: it keeps invitations from being used to send unwanted email. You can invite again in %2$s.', 'mody_cloud' ),
						self::INVITATION_LIMIT,
						human_time_diff( time(), time() + Throttle::wait( 'invitations', self::INVITATION_LIMIT, DAY_IN_SECONDS ) )
					)
				),
				esc_html__( 'Invitations', 'mody_cloud' ),
				array(
					'response'  => 429,
					'back_link' => true,
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked above; only looked up, never output.
		$email = wp_unslash( $_REQUEST['email'] ?? '' );
		if ( is_string( $email ) && str_contains( $email, '@' ) && ! get_user_by( 'email', $email ) ) {
			// The very redirect core makes after an invitation, byte for byte: a fixed relative address.
			wp_redirect( add_query_arg( array( 'update' => 'add' ), 'user-new.php' ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Fixed relative address, as core's own.
			exit;
		}
	}

	/**
	 * Filters `gettext`: core's "Invitation email sent" becomes a sentence
	 * that is true whether or not the address had an account.
	 *
	 * @param string $translation The translated text.
	 * @param string $text        The original text.
	 */
	public static function neutral_text( $translation, $text ): string {
		if ( self::CORE_INVITED === $text ) {
			return __( 'If that address has an account, an invitation email has been sent. They are added to your site when they click the link in it.', 'mody_cloud' );
		}
		return (string) $translation;
	}

	/**
	 * The site's title as it may appear in an email: no line breaks or other
	 * control characters, at most TITLE_LENGTH characters, or the site's
	 * address when it has no title.
	 */
	public static function short_title(): string {
		$title = trim( (string) preg_replace( '/[\x00-\x1F\x7F\s]+/u', ' ', wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) ) );
		if ( '' === $title ) {
			return (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		return mb_strimwidth( $title, 0, self::TITLE_LENGTH, '…', 'UTF-8' );
	}
}
