<?php
/**
 * Plugin Name:       Mody Login
 * Description:       The login page in the site's brand: its logo, palette and fonts, in a color scheme chosen in Settings → Login.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * Each site's wp-login.php wears that site's logo, palette and fonts (the
 * theme's variables, read on each request), arranged by one of up to six
 * color schemes built from its palette, which the site's admins choose in
 * Settings → Login (tickets/login-branding.md).
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class.
 *
 * @package Mody\Login
 */

namespace Mody\Login;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Login\Example is src/Example.php.
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

LoginPage::register();
add_action( 'admin_init', array( SettingsPage::class, 'register_setting' ) );
add_action( 'rest_api_init', array( SettingsPage::class, 'register_setting' ) );
add_action( 'admin_menu', array( SettingsPage::class, 'register_menu' ) );
