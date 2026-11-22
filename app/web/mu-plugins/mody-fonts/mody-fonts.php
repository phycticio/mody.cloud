<?php
/**
 * Plugin Name:       Mody Fonts
 * Description:       The Font Library with the Mody fonts collection, ten free families served from this plugin, and no Google Fonts.
 * Version:           1.0.1
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        https://mody.cloud/
 * Text Domain:       mody_cloud
 *
 * The Font Library without anything from outside (rule 1, M2-06 and M2-07):
 * no Google Fonts collection, the Mody fonts collection instead (ten free
 * families whose files live in fonts/<slug>/, with each license and
 * fonts/sources.json), and real Inter for the theme's `inter` family.
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class.
 *
 * @package Mody\Fonts
 */

namespace Mody\Fonts;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Fonts\Example is src/Example.php.
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

add_action( 'init', array( Fonts::class, 'remove_collections' ), 20 ); // Core registers Google Fonts on `init` at 10.
add_action( 'init', array( Fonts::class, 'register_collection' ), 20 ); // The Mody fonts collection.
add_filter( 'wp_theme_json_data_theme', array( Fonts::class, 'theme_fonts' ) ); // Real Inter for the theme.

// WordPress's update notice from mody.cloud, when installed as a normal plugin (never on Mody Cloud's network).
UpdateCheck::register( __FILE__ );
