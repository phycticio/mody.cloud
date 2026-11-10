<?php
/**
 * Hooks Mody Mail into wp_mail().
 *
 * @package Mody\Mail
 */

namespace Mody\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Every message goes through wp_mail(), so this changes two things there:
 *
 * - The sender: always MAIL_SENDER_EMAIL. The name is MAIL_SENDER_NAME unless
 *   the message names its own sender (a plugin's "From: Name <…>"); WordPress's
 *   default name, "WordPress", is replaced.
 * - The transport: when Brevo sends (Config::transport()), wp_mail() is handed
 *   a BrevoMailer before it builds the message. It fills it in as it would any
 *   PHPMailer (recipients, headers, body, attachments) and calls send(), which
 *   posts the message to Brevo's API instead of using PHP's mail(). wp_mail()'s
 *   own filters and actions (wp_mail_failed, wp_mail_succeeded) keep working.
 */
final class Mail {

	/**
	 * Hooks it up. Called from mody-mail.php.
	 */
	public static function register(): void {
		add_filter( 'wp_mail_from', array( self::class, 'from_email' ), PHP_INT_MAX );
		add_filter( 'wp_mail_from_name', array( self::class, 'from_name' ), PHP_INT_MAX );
		add_filter( 'pre_wp_mail', array( self::class, 'use_transport' ), PHP_INT_MAX );
		add_action( 'wp_mail_failed', array( self::class, 'log_failure' ) );
	}

	/**
	 * Writes why a message failed to PHP's error log (WP_DEBUG_LOG locally,
	 * the container's log in production). Only the reason: the error's data
	 * holds the message, which can carry a password-reset link. The reason
	 * never holds the API key (BrevoMailer).
	 *
	 * @param \WP_Error $error What wp_mail() reports.
	 */
	public static function log_failure( $error ): void {
		if ( $error instanceof \WP_Error ) {
			error_log( '[mody-mail] Email not sent: ' . $error->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the only record of a failed email.
		}
	}

	/**
	 * The sender's address, for every message.
	 */
	public static function from_email(): string {
		return Config::sender_email();
	}

	/**
	 * The sender's name: the message's own, or MAIL_SENDER_NAME instead of
	 * WordPress's default.
	 *
	 * @param mixed $name The name wp_mail() has so far.
	 */
	public static function from_name( $name ): string {
		$name = trim( (string) $name );

		return ( '' === $name || 'WordPress' === $name ) ? Config::sender_name() : $name;
	}

	/**
	 * Hands wp_mail() a BrevoMailer when Brevo sends (or when production has
	 * no transport, so the message fails with a clear error). Runs last on
	 * pre_wp_mail and never short-circuits: when another filter already
	 * answered for wp_mail(), nothing is sent and nothing changes.
	 *
	 * @param mixed $short_circuit Null, or another filter's answer.
	 * @return mixed The same value.
	 */
	public static function use_transport( $short_circuit ) {
		if ( null !== $short_circuit ) {
			return $short_circuit;
		}

		global $phpmailer;
		if ( Config::TRANSPORT_PHP === Config::transport() ) {
			if ( $phpmailer instanceof BrevoMailer ) {
				$phpmailer = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- wp_mail() makes its own again.
			}
			return $short_circuit;
		}

		if ( ! ( $phpmailer instanceof BrevoMailer ) ) {
			$phpmailer = new BrevoMailer( true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- wp_mail() reuses the global mailer.
		}

		return $short_circuit;
	}
}
