<?php
/**
 * Plugin Name:       Mody Palette
 * Description:       Color pickers offer only the site's palette, Spectra's own pickers included.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        https://mody.cloud/
 * Text Domain:       mody_cloud
 *
 * Color pickers offer only the palette (M1-04 part A, decided by the
 * maintainer on 2026-11-09). The theme's theme.json switches the free
 * pickers off; this plugin hides the few that Spectra draws itself, in the
 * editors.
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class.
 *
 * @package Mody\Palette
 */

namespace Mody\Palette;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Palette\Example is src/Example.php.
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

add_action( 'enqueue_block_editor_assets', array( PaletteOnly::class, 'enqueue' ) );

// WordPress's update notice from mody.cloud, when installed as a normal plugin (never on Mody Cloud's network).
UpdateCheck::register( __FILE__ );
