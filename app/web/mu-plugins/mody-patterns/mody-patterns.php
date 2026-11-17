<?php
/**
 * Plugin Name:       Mody Patterns
 * Description:       Every site's patterns come from the Mody collection, with placeholder images drawn on the site; nothing is fetched from WordPress.org.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * The design market's patterns (built 2026-11-08,
 * tickets/design-market-themes-patterns.md): every site's patterns are the
 * Mody collection (patterns/collection.json, written by
 * scripts/import-patterns.php with PatternImport from WordPress.org's
 * Pattern Directory and core's patterns). Nothing is fetched from
 * WordPress.org: remote patterns and core's bundled ones are off, and the
 * Directory proxy is refused to everyone but super admins. Placeholder
 * images (/mody-placeholder/<w>x<h>.svg) are drawn here, before the rest of
 * WordPress runs.
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class.
 *
 * @package Mody\Patterns
 */

namespace Mody\Patterns;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Patterns\Example is src/Example.php.
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

Patterns::serve_placeholder(); // Now: a placeholder request stops here.
add_filter( 'should_load_remote_block_patterns', '__return_false' );
add_action( 'after_setup_theme', array( Patterns::class, 'remove_core_patterns' ), 20 );
add_action( 'rest_api_init', array( Patterns::class, 'register' ), 5 );
add_filter( 'rest_pre_dispatch', array( Patterns::class, 'refuse_directory' ), 10, 3 );
