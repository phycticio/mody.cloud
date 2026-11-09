<?php
/**
 * WP Mail SMTP, the platform's only way of sending email.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * WP Mail SMTP (wp-mail-smtp, required in app/composer.json, network-active)
 * sends every email the platform sends: SMTP to Mailpit locally, Brevo's API
 * in production (decided by the maintainer on 2026-11-09). Its settings are
 * constants, set only by app/wp-config.php from the environment.
 *
 * Here:
 *
 * - Only super admins see it. Its screens, test tool, dashboard widget,
 *   admin bar menu and notices all check one capability, which a member
 *   (a site administrator) would have. The Plugins screen hides it too
 *   (Gating\MemberPlugins::PLATFORM_PLUGINS).
 * - Rule 1: it calls its maker's servers on its own for a notifications
 *   feed, usage tracking, a weekly summary email and the error banner's help
 *   links. All four are switched off, and so is its setup wizard (the
 *   constants set everything; its hosted variant calls its maker too).
 *   What is left runs only when a super admin uses a tool: the email
 *   test's domain check (connect.wpmailsmtp.com), Email Detective
 *   (detective.sendlayer.com) and the WPCode snippet library
 *   (library.wpcode.com).
 */
final class WpMailSmtp {

	/** Who may see and use WP Mail SMTP: super admins only. */
	public const CAPABILITY = 'manage_network_options';

	/** The plugin's main file, relative to the plugins directory. */
	public const PLUGIN = 'wp-mail-smtp/wp_mail_smtp.php';

	/**
	 * Hooks it up. Called from mody-platform.php.
	 */
	public static function register(): void {
		add_filter( 'wp_mail_smtp_core_get_capability_manage_options', array( self::class, 'capability' ) );
		add_filter( 'wp_mail_smtp_core_get_capability_manage_global_options', array( self::class, 'capability' ) );

		// Rule 1: nothing sent to, or fetched from, its maker on its own.
		add_filter( 'wp_mail_smtp_admin_notifications_has_access', '__return_false' );
		add_filter( 'wp_mail_smtp_usage_tracking_load_allowed', '__return_false' );
		add_filter( 'wp_mail_smtp_reports_emails_summary_is_disabled', '__return_true' );
		add_filter( 'wp_mail_smtp_admin_is_error_delivery_notice_enabled', '__return_false' ); // Its banner fetches help links from wpmailsmtpapi.com.

		// The constants set everything: no setup wizard, and no redirect to it after activation.
		add_filter( 'wp_mail_smtp_admin_setup_wizard_load_wizard', '__return_false' );
	}

	/**
	 * The capability WP Mail SMTP asks for, for all of its screens and tools.
	 */
	public static function capability(): string {
		return self::CAPABILITY;
	}
}
