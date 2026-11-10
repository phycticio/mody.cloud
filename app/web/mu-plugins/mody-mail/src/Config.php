<?php
/**
 * Mody Mail's settings, read from the environment.
 *
 * @package Mody\Mail
 */

namespace Mody\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * The settings come only from the environment (.env reaches the app
 * container through compose.yml's env_file). Each one also accepts a
 * NAME_FILE variable holding a path, like the rest of wp-config.php
 * (getenv_docker()).
 *
 * - BREVO_API_KEY: Brevo's API key (SMTP & API → API keys). A secret: only in
 *   the server's .env, never committed, never logged or shown.
 * - MAIL_SENDER_EMAIL: the one address every message is sent from. In
 *   production it must be a sender verified in Brevo. Default:
 *   no-reply@ the network's domain.
 * - MAIL_SENDER_NAME: the name shown with it. Default: Mody Cloud.
 */
final class Config {

	/** The sender name when MAIL_SENDER_NAME is not set. */
	public const DEFAULT_SENDER_NAME = 'Mody Cloud';

	/** Transports (transport()). */
	public const TRANSPORT_BREVO = 'brevo';
	public const TRANSPORT_PHP   = 'php';
	public const TRANSPORT_NONE  = 'none';

	/**
	 * Reads one environment variable, trimmed; '' when it is not set.
	 *
	 * @param string $name The variable's name.
	 */
	public static function env( string $name ): string {
		$value = function_exists( 'getenv_docker' ) ? getenv_docker( $name, '' ) : getenv( $name );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Brevo's API key, or '' when it is not set.
	 */
	public static function brevo_api_key(): string {
		return self::env( 'BREVO_API_KEY' );
	}

	/**
	 * The address every message is sent from.
	 */
	public static function sender_email(): string {
		$email = self::env( 'MAIL_SENDER_EMAIL' );

		return is_email( $email ) ? $email : 'no-reply@' . self::network_domain();
	}

	/**
	 * The name shown with the sender's address, on one line.
	 */
	public static function sender_name(): string {
		$name = trim( (string) preg_replace( '/[\r\n\t]+/', ' ', self::env( 'MAIL_SENDER_NAME' ) ) );

		return '' !== $name ? $name : self::DEFAULT_SENDER_NAME;
	}

	/**
	 * How messages leave:
	 *
	 * - brevo: Brevo's API, whenever the key is set;
	 * - none: production without the key, where every message fails with a
	 *   clear error (PHP's mail() would hand it to a Mailpit that does not run);
	 * - php: WordPress's own way, PHP's mail() (Mailpit locally).
	 */
	public static function transport(): string {
		if ( '' !== self::brevo_api_key() ) {
			return self::TRANSPORT_BREVO;
		}

		return 'production' === wp_get_environment_type() ? self::TRANSPORT_NONE : self::TRANSPORT_PHP;
	}

	/**
	 * The network's domain, without www.
	 */
	private static function network_domain(): string {
		$domain = defined( 'DOMAIN_CURRENT_SITE' ) ? (string) DOMAIN_CURRENT_SITE : (string) wp_parse_url( network_home_url(), PHP_URL_HOST );

		return str_starts_with( $domain, 'www.' ) ? substr( $domain, 4 ) : $domain;
	}
}
