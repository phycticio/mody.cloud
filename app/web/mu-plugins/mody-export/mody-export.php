<?php
/**
 * Plugin Name:       Mody Export
 * Description:       Take your site with you: any administrator of a member site downloads the whole site, code, media and database, at any time.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * Take your site with you (decided by the maintainer on 2026-11-12,
 * tickets/take-your-site-with-you.md): any administrator of a member site
 * downloads the whole site at any time, whatever the plan or billing status,
 * as three files: the code (the theme and the independent Mody plugins a
 * site needs, inside the Mody Import plugin, export-kit/mody-import/), the
 * media and the database. Everything goes but Mody.
 *
 * - **Panel**: the Download your site section of the Mody dashboard,
 *   through its hooks (`mody_dashboard_sections`,
 *   `mody_dashboard_section_export`).
 * - **Jobs, Runner, Exporter**: builds run in a detached
 *   `wp mody-export run` process, at most two at once.
 * - **Downloads**: files kept three days in app/dumps/exports/, served
 *   through signed links with Nginx's X-Accel-Redirect.
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class. A network-only plugin.
 *
 * @package Mody\Export
 */

namespace Mody\Export;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Export\Example is src/Example.php.
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
	Downloads::serve(); // Now: a download request stops here, before the rest of WordPress runs.
	add_action( 'init', array( Jobs::class, 'install' ), 1 );
	Command::register();
	Panel::register();
}
