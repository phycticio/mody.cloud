<?php
/**
 * Plugin Name:       Mody Admin Colors
 * Description:       Ocean as every user's wp-admin color scheme unless they choose another.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * Ocean is every user's wp-admin color scheme unless they choose another
 * (decided by the maintainer on 2026-11-08, tickets/admin-colors.md). Users
 * on WordPress's default (Modern) were moved to it once, on the network.
 * It also gives Mody's chat the second color of the user's scheme, for
 * whichever plugin draws the chat (AdminColors::add_agent_chat_css()).
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class.
 *
 * @package Mody\AdminColors
 */

namespace Mody\AdminColors;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\AdminColors\Example is src/Example.php.
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

add_filter( 'insert_user_meta', array( AdminColors::class, 'new_user_scheme' ), 10, 4 );
add_filter( 'get_user_option_admin_color', array( AdminColors::class, 'default_scheme' ) );
if ( is_multisite() ) {
	add_action( 'init', array( AdminColors::class, 'migrate' ), 1 ); // Once per network: users on Modern move to Ocean.
}
