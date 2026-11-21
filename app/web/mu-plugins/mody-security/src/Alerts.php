<?php
/**
 * Security emails: alerts to the super admins, and notes to an account
 * about its own two-step login.
 *
 * @package Mody\Security
 */

namespace Mody\Security;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Which alerts go out is set on the Security page (Settings::ALERTS). Each
 * kind is limited to RateLimit's `alert` (ten an hour), so an attack does
 * not flood anyone's inbox. Mail leaves through `wp_mail()` (Mody Mail).
 *
 * The notes to an account (two-step login turned off, a recovery code
 * used) always go: they are how someone hears that their account changed.
 */
final class Alerts {

	/**
	 * Hooked from mody-security.php.
	 */
	public static function register(): void {
		add_action( 'wp_login', array( self::class, 'admin_login' ), 5, 2 ); // Before FormGuard marks the browser as known.
	}

	/**
	 * `wp_login`: a super admin logged in from a browser the account has not
	 * used before.
	 *
	 * @param string       $user_login The login name.
	 * @param WP_User|null $user       The user.
	 */
	public static function admin_login( $user_login, $user = null ): void {
		if ( ! $user instanceof WP_User || ! is_super_admin( $user->ID ) || ! Settings::alert( 'admin_login' ) || FormGuard::known_device( $user ) ) {
			return;
		}
		$address = ClientAddress::get();
		self::send(
			'admin_login',
			/* translators: %s: the network's name. */
			sprintf( __( '[%s] A super admin logged in from a new browser', 'mody_cloud' ), self::network_name() ),
			sprintf(
				/* translators: 1: a login name, 2: an IP address, 3: a browser's description, 4: a time. */
				__( "The super admin %1\$s logged in from a browser this account had not used before.\n\nAddress: %2\$s\nBrowser: %3\$s\nTime: %4\$s\n\nIf this was not them, change the account's password and turn off its sessions from its profile.", 'mody_cloud' ),
				$user->user_login,
				'' !== $address ? $address : __( 'unknown', 'mody_cloud' ),
				isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 200 ) : __( 'unknown', 'mody_cloud' ),
				wp_date( 'Y-m-d H:i T' )
			)
		);
	}

	/**
	 * An address was paused after too many failed logins.
	 *
	 * @param string $typed The login name typed last.
	 */
	public static function address_paused( string $typed ): void {
		if ( ! Settings::alert( 'address_paused' ) ) {
			return;
		}
		self::send(
			'address_paused',
			/* translators: %s: the network's name. */
			sprintf( __( '[%s] Logins paused for an address', 'mody_cloud' ), self::network_name() ),
			sprintf(
				/* translators: 1: an IP address, 2: a login name, 3: minutes. */
				__( "The address %1\$s failed to log in too many times (last with \"%2\$s\"). Its logins are paused for %3\$d minutes.\n\nThe security log on Network Admin → Mody → Security has the details; an address can be blocked from there.", 'mody_cloud' ),
				ClientAddress::get(),
				mb_substr( $typed, 0, 60 ),
				(int) Settings::get( 'login_minutes' )
			)
		);
	}

	/**
	 * The firewall blocked a request.
	 *
	 * @param string $rule What matched.
	 * @param string $uri  The address asked for.
	 */
	public static function firewall_block( string $rule, string $uri ): void {
		if ( ! Settings::alert( 'firewall_block' ) ) {
			return;
		}
		self::send(
			'firewall_block',
			/* translators: %s: the network's name. */
			sprintf( __( '[%s] The firewall blocked a request', 'mody_cloud' ), self::network_name() ),
			sprintf(
				/* translators: 1: a rule's name, 2: an IP address, 3: an address on the site. */
				__( "Rule: %1\$s\nFrom: %2\$s\nAsked for: %3\$s", 'mody_cloud' ),
				$rule,
				ClientAddress::get(),
				mb_substr( $uri, 0, 300 )
			)
		);
	}

	/**
	 * The file check found differences.
	 *
	 * @param array<string, mixed> $result FileCheck::run()'s answer.
	 */
	public static function file_changes( array $result ): void {
		if ( ! Settings::alert( 'file_changes' ) ) {
			return;
		}
		$lines = array();
		foreach ( array(
			'changed' => __( 'Changed', 'mody_cloud' ),
			'added'   => __( 'Added', 'mody_cloud' ),
			'missing' => __( 'Missing', 'mody_cloud' ),
			'uploads' => __( 'Scripts in uploads', 'mody_cloud' ),
		) as $key => $label ) {
			foreach ( array_slice( (array) ( $result[ $key ] ?? array() ), 0, 50 ) as $path ) {
				$lines[] = $label . ': ' . $path;
			}
		}
		self::send(
			'file_changes',
			/* translators: %s: the network's name. */
			sprintf( __( '[%s] The file check found changes', 'mody_cloud' ), self::network_name() ),
			__( "These files differ from what the last deploy shipped:\n\n", 'mody_cloud' ) . implode( "\n", $lines )
		);
	}

	/**
	 * Tells an account that a recovery code was used to log in.
	 *
	 * @param WP_User $user The account.
	 */
	public static function recovery_code_used( WP_User $user ): void {
		wp_mail(
			$user->user_email,
			/* translators: %s: the network's name. */
			sprintf( __( '[%s] A recovery code was used on your account', 'mody_cloud' ), self::network_name() ),
			sprintf(
				/* translators: 1: a number of codes, 2: an IP address. */
				__( "Someone logged in to your account with a recovery code, from the address %2\$s. You have %1\$d codes left.\n\nIf it was you, you can make new codes on your profile, under Two-step login. If it was not, change your password now.", 'mody_cloud' ),
				TwoFactor::codes_left( $user->ID ),
				ClientAddress::get()
			)
		);
	}

	/**
	 * Tells an account that its two-step login was changed by someone else,
	 * or turned off.
	 *
	 * @param WP_User $user What changed.
	 * @param string  $what A sentence: what happened.
	 */
	public static function two_factor_changed( WP_User $user, string $what ): void {
		wp_mail(
			$user->user_email,
			/* translators: %s: the network's name. */
			sprintf( __( '[%s] Your two-step login changed', 'mody_cloud' ), self::network_name() ),
			$what . "\n\n" . __( 'If you did not expect this, change your password and set up two-step login again on your profile.', 'mody_cloud' )
		);
	}

	/**
	 * Emails every super admin, within the kind's limit.
	 *
	 * @param string $kind    One of Settings::ALERTS.
	 * @param string $subject The subject.
	 * @param string $body    The text.
	 */
	private static function send( string $kind, string $subject, string $body ): void {
		if ( RateLimit::hit( 'alert', $kind ) ) {
			return;
		}
		foreach ( self::recipients() as $email ) {
			wp_mail( $email, $subject, $body );
		}
	}

	/**
	 * The super admins' emails.
	 *
	 * @return string[]
	 */
	public static function recipients(): array {
		$emails = array();
		foreach ( get_super_admins() as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user instanceof WP_User && is_email( $user->user_email ) ) {
				$emails[] = $user->user_email;
			}
		}
		return array_values( array_unique( $emails ) );
	}

	/**
	 * The network's name.
	 */
	private static function network_name(): string {
		$name = is_multisite() ? (string) get_network()->site_name : (string) get_bloginfo( 'name' );
		return wp_specialchars_decode( $name, ENT_QUOTES );
	}
}
