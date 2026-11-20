<?php
/**
 * Plugin Name:       Mody Domains
 * Description:       Custom domains for a network's sites: a CNAME, DNS checks, certificates through Nginx Proxy Manager, and the Your domain section of the Mody dashboard.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * Custom domains (decided by the maintainer on 2026-11-13,
 * tickets/custom-domains.md): a member site answers at its own domain, a
 * CNAME to MODY_DOMAINS_TARGET (sites.mody.cloud); DNS is checked, and the
 * certificate and proxy host are made through Nginx Proxy Manager's API
 * (NPM_API_* in .env). The admin stays on the site's *.mody.cloud address.
 *
 * - **Mapping** runs before WordPress picks a site: app/web/sunrise.php
 *   loads src/Mapping.php by path (SUNRISE in wp-config.php).
 * - **Domains**: the `mody_domains` table, the checks (WP-Cron, every five
 *   minutes), the handlers, the redirects, `wp mody-domains`.
 * - **Panel**: the Your domain section of the Mody dashboard, through its
 *   hooks (`mody_dashboard_sections`, `mody_dashboard_section_domain`).
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class. A network-only plugin.
 *
 * @package Mody\Domains
 */

namespace Mody\Domains;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Domains\Example is src/Example.php.
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
	Domains::register();
	HostSessions::register(); // A login counts only where it was made: the network, or one member's domain (#27).
	add_filter( 'mody_dashboard_sections', array( Panel::class, 'add_section' ) );
	add_action( 'mody_dashboard_section_' . Panel::SECTION, array( Panel::class, 'render' ) );
	add_action( 'admin_enqueue_scripts', array( Panel::class, 'enqueue' ) );
}
