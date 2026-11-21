<?php
/**
 * Plugin Name:       Mody Updates
 * Description:       Nobody updates WordPress, plugins, themes or translations from wp-admin, and core never checks WordPress.org for updates on its own: versions change only through the repository.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * Nobody updates WordPress, a plugin, a theme or a translation from
 * wp-admin (decided by the maintainer on 2026-11-08,
 * tickets/updates-through-composer-only.md): the four update capabilities
 * are refused to every user, super admins included, and core never checks
 * WordPress.org for updates on its own. Versions change only in the
 * repository (core with ./bin/version, plugins in app/composer.json) and
 * reach production through ./bin/deploy. Code that calls the checks
 * directly (Site Health, WP-CLI) is left alone.
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class.
 *
 * @package Mody\Updates
 */

namespace Mody\Updates;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Updates\Example is src/Example.php.
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

Updates::remove_triggers();
add_filter( 'map_meta_cap', array( Updates::class, 'map_meta_cap' ), 100, 2 ); // After the usual filters.
add_action( 'init', array( Updates::class, 'unschedule' ) );
