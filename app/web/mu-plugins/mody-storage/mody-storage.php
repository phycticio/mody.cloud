<?php
/**
 * Plugin Name:       Mody Storage
 * Description:       The space each site of a network has, 500 MB, with WordPress's own upload quota; no limit on the main site.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * The space per site (tickets/mody-dashboard.md; 500 MB since 2026-11-20,
 * documentation/membership-tiers.md), enforced with WordPress's own upload
 * quota: the network's quota settings once, every new site's 500 MB, and no
 * limit on the main site. The Mody dashboard shows the space with core's
 * own get_space_used() and get_space_allowed().
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class. A network-only plugin.
 *
 * @package Mody\Storage
 */

namespace Mody\Storage;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Storage\Example is src/Example.php.
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

if ( is_multisite() ) {
	Storage::register();
}
