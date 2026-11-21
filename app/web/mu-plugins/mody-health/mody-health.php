<?php
/**
 * Plugin Name:       Mody Health
 * Description:       How the platform is doing at a glance, for super admins: accounts and sign-ups, sites and space, Mody's tokens this month and what they cost, and what failed.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * One page, Network Admin → Mody → Health (tickets/health-page.md; the
 * "simple system health page for super admins" of M5-01's Monitoring, built
 * on 2026-11-20 by the agent, see the night report). Since 2026-11-20 every
 * account is free and there is no cap on accounts, so the cost that grows,
 * Mody's tokens paid to Infomaniak, is the page's centre.
 *
 * It reads, never writes. What it shows of another plugin it reads behind
 * class_exists(), so each part is left out when its plugin is missing:
 * Mody Members (accounts), Mody Agent (`mody_agent_events`: tokens and
 * errors), Mody Sites (provisioning jobs), Mody Domains (domains), Mody
 * Billing (whether payments are on). Sites and space are WordPress's own.
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md).
 * A network-only plugin.
 *
 * @package Mody\Health
 */

namespace Mody\Health;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Health\Example is src/Example.php.
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
	add_action( 'network_admin_menu', array( HealthPage::class, 'register_menu' ), 30 ); // After the Mody menu (Mody Sites) and Offers.
}
