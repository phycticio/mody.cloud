<?php
/**
 * Plugin Name:       Mody Sites
 * Description:       Making a network's sites: the New site wizard, provisioning (the site, its brand, its style, a first page), and the sites an account owns.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * Making a network's sites (tickets M2-01, M2-03, M2-04):
 *
 * - **The wizard** (WizardPage, WizardController, WizardPreview): Network
 *   Admin → Mody → New site, its REST endpoint (`mody-platform/v1/sites`, the
 *   address it has always had) and step 2's live preview.
 * - **Provisioning** (Pipeline, Jobs): wizard answers or a signup → a new
 *   site, its brand, its style and a first page, in `mody_provisioning_jobs`.
 * - **The quota** (Quota): the sites an account owns (`mody_owner`), against
 *   its limit.
 *
 * It uses Mody Agent (the first page), Mody Brand (mission and tone) and Mody
 * Styles (the preset) when they are there. What it cannot know comes through
 * filters the plugin that keeps the accounts answers: `mody_site_limit`,
 * `mody_can_create_site`, `mody_sites_payment_pending` (whether the account
 * must pay before its first site), `mody_sites_on_launch_offer` (whether the account is on the free plan; named after the old launch offer) and
 * `mody_sites_plan_url`.
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class but those, behind class_exists(). A
 * network-only plugin.
 *
 * @package Mody\Sites
 */

namespace Mody\Sites;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Sites\Example is src/Example.php.
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

if ( ! is_multisite() ) {
	return;
}

add_action( 'init', array( Jobs::class, 'install' ), 1 );

/*
 * The wizard (Network Admin → Mody → New site, ticket M2-03): its page and
 * its REST endpoint, which runs the provisioning pipeline after the response.
 */
add_action( 'network_admin_menu', array( WizardPage::class, 'register_menu' ) );
add_action( 'rest_api_init', array( WizardController::class, 'register_routes' ) );
add_action( 'template_redirect', array( WizardPreview::class, 'maybe_render' ), 0 ); // Step 2's live preview (M2-04).

/*
 * The plan's sites are the ones a member owns (security pass, 2026-11-12).
 */
add_action( 'wp_initialize_site', array( Quota::class, 'on_initialize_site' ), 10, 2 );
add_action( 'admin_init', array( Quota::class, 'backfill' ) );
