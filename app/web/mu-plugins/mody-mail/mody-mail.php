<?php
/**
 * Mody Mail: the platform's only way of sending email (decided by the
 * maintainer on 2026-11-10, replacing WP Mail SMTP).
 *
 * - Every message leaves from one sender: MAIL_SENDER_EMAIL and
 *   MAIL_SENDER_NAME in .env (Config).
 * - With BREVO_API_KEY set (production), every message goes to Brevo's
 *   transactional API (BrevoMailer). Without it, WordPress sends as it always
 *   does: PHP's mail(), which msmtp hands to Mailpit locally
 *   (config/php/zzz-mail.ini). In production a missing key is an error, never
 *   a silent fallback.
 *
 * Settings come only from the environment: there is no settings screen, and
 * nothing is stored in the database. Dependencies, if this plugin ever needs
 * one, go in app/composer.json, never in a composer.json of its own.
 *
 * See documentation/architecture.md#production.
 *
 * @package Mody\Mail
 */

namespace Mody\Mail;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Mail\BrevoMailer is src/BrevoMailer.php.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( __NAMESPACE__ ) + 1 ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	}
);

Mail::register();
