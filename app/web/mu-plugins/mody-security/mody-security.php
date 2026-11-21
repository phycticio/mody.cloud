<?php
/**
 * Plugin Name:       Mody Security
 * Description:       The network's security: two-step login (authenticator app, security keys), login protection, a firewall, a file check, a security log and alerts, all set on Network Admin → Mody → Security; ALTCHA, rate limits, and hardening.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        https://mody.cloud/
 * Text Domain:       mody_cloud
 *
 * The platform's security (tickets/antibot-altcha.md and the security pass
 * of 2026-11-12, tickets/M5-01-hardening-launch.md):
 *
 * - **ALTCHA** (Altcha, FormGuard): a proof-of-work check served from our
 *   own servers, on log in, lost password and visitors' comments on every
 *   site; other plugins check it too (the signup chat). Its route stays
 *   `mody-platform/v1/altcha`, the address the widget has always used.
 * - **Rate limits** (RateLimit, by the visitor's real address from
 *   ClientAddress): log in, lost password, comments, the analytics beacon,
 *   and whatever other plugins count with RateLimit::hit().
 * - **Hardening**: this installation's own keys and salts (Secrets), XML-RPC
 *   off, members' logins out of public lists, passwords of ten characters.
 * - **Since 2026-11-21** (tickets/security-plugin-replaces-wordfence.md):
 *   two-step login with an
 *   authenticator app or security keys, and recovery codes (TwoFactor,
 *   TwoFactorLogin, TwoFactorProfile, Totp, WebAuthn, Cbor, Qr); blocked
 *   usernames; a firewall (Firewall); a file check against what the deploy
 *   shipped (FileCheck); a security log (Events) and alerts (Alerts); every
 *   setting on Network Admin → Mody → Security (Settings, SecurityPage);
 *   `wp mody-security` (Command). Nothing calls a service outside our
 *   servers.
 *
 * The login limits and ALTCHA work together (a wrong password counts only
 * when the puzzle was solved), so they are one plugin. On the platform only:
 * a downloaded site does not carry it.
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class, except behind class_exists() for where
 * the Security page sits in the menu.
 *
 * @package Mody\Security
 */

namespace Mody\Security;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Security\Example is src/Example.php.
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

add_action( 'init', array( Altcha::class, 'register_script' ) );
add_action( 'rest_api_init', array( Altcha::class, 'register_route' ) );
add_filter( 'wp_kses_allowed_html', array( Altcha::class, 'allow_widget' ), 10, 2 ); // Spectra's container sanitizes its inner blocks.
FormGuard::register();
Hardening::register();
Firewall::boot();
TwoFactorLogin::register();
TwoFactorProfile::register();
SecurityPage::register();
Alerts::register();
add_filter( 'mody_rate_limits', array( Settings::class, 'rate_limits' ), 5 );
add_action(
	'wp_login',
	static function ( $user_login, $user = null ): void {
		if ( $user instanceof \WP_User ) {
			Events::record( 'login', $user->ID );
		}
	},
	10,
	2
);
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'mody-security', Command::class );
}

// WordPress's update notice from mody.cloud, when installed as a normal plugin (never on Mody Cloud's network).
UpdateCheck::register( __FILE__ );
