<?php
/**
 * Plugin Name:       Mody Billing
 * Description:       Paying for sites with a card through Mollie: the card, the charges per site, the withdrawal, cancel and resume, and read-only then deletion for unpaid accounts.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * Paying for sites with a card through Mollie (M1-01 part 3, M1-02,
 * 2026-11-13; tickets/M1-01-registration-trial-billing.md): adding a card,
 * the charges per site, the webhook (`mody-platform/v1/mollie/webhook`, the
 * address Mollie has), the 14-day withdrawal, Cancel and Resume, refunds and
 * chargebacks, the reminders, read-only then deletion for unpaid or canceled
 * accounts (Lifecycle), and the daily run `wp mody-billing run`. Off without
 * MOLLIE_API_KEY.
 *
 * It bills Mody Members' accounts for Mody Sites' sites, so it runs only
 * with both. It answers `mody_sites_plan_url` (the wizard's link to Plan &
 * billing) and `mody_agent_site_locked` (no model work on a read-only site).
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md).
 * A network-only plugin.
 *
 * @package Mody\Billing
 */

namespace Mody\Billing;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Billing\Example is src/Example.php.
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

/*
 * Billing is for Mody Members' accounts and Mody Sites' sites: once every
 * plugin is loaded, whatever their order, and only with both.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! is_multisite() || ! class_exists( \Mody\Members\Members::class ) || ! class_exists( \Mody\Sites\Quota::class ) ) {
			return;
		}
		Billing::register();
		add_filter( 'mody_agent_site_locked', static fn( $locked, $site_id ) => $locked || (bool) Lifecycle::site_lock( (int) $site_id ), 10, 2 ); // Mody does not work on a read-only site (Mody Agent's Budget).
		add_filter( 'mody_sites_plan_url', static fn( $url, $user_id ) => '' !== $url ? $url : Billing::plan_url( (int) $user_id ), 10, 2 ); // Mody Sites' wizard links to Plan & billing.
	},
	0
);
