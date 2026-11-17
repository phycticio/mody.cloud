<?php
/**
 * Plugin Name:       Mody Security
 * Description:       ALTCHA on the login, lost password and comment forms, rate limits by the visitor's real address, the installation's own keys and salts, and XML-RPC off.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
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
 *
 * The login limits and ALTCHA work together (a wrong password counts only
 * when the puzzle was solved), so they are one plugin. On the platform only:
 * a downloaded site does not carry it.
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class.
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
