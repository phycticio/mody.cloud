<?php
/**
 * Plugin Name:       Mody Dashboard
 * Description:       Every site's Dashboard: the site at a glance, its visitors on a board of charts, Mody's work, and the account's sites and plan; other Mody plugins add their own sections.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        https://mody.cloud/
 * Text Domain:       mody_cloud
 *
 * The Mody dashboard (asked for by the maintainer on 2026-11-11,
 * tickets/mody-dashboard.md): wp-admin's Dashboard item opens
 * admin.php?page=mody-dashboard on every site, and core's dashboard
 * redirects there. The layout is Settings → Brand's (admin-page.css, a
 * copy, so this plugin needs no other).
 *
 * - **Its own sections:** Overview (the site's card, its storage), Visitors
 *   (Mody Analytics' numbers; the board of charts, Board and ChartSpec,
 *   tickets/analytics-chat.md), Mody's work (Usage, Mody Agent's log), Your
 *   sites and Plan & billing (Mody Members' and Mody Billing's accounts).
 *   Each shows only when the plugin it reads is there.
 * - **The analyst** (Analyst): Mody on the Visitors board, with Mody Agent's
 *   model limits and log, only when that plugin is there.
 * - **Other plugins' sections**, through two hooks:
 *   - `mody_dashboard_sections` (filter): the sections this user sees, slug
 *     => label, in order;
 *   - `mody_dashboard_section_<slug>` (action): prints the section's panel.
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md).
 *
 * @package Mody\Dashboard
 */

namespace Mody\Dashboard;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Dashboard\Example is src/Example.php.
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

DashboardPage::register();

/*
 * The Visitors board and its analyst, once every plugin is loaded: the board
 * reads Mody Analytics, the analyst runs on Mody Agent's model limits and log.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( \Mody\Analytics\Query::class ) ) {
			return;
		}
		Board::register(); // The Visitors section's board of charts (2026-11-12, tickets/analytics-chat.md).
		if ( function_exists( 'wp_ai_client_prompt' ) && class_exists( \Mody\Agent\Budget::class ) ) {
			Analyst::register(); // Mody on that board: reads only the analytics, changes only the board (phase 2).
		}
	},
	0
);

// WordPress's update notice from mody.cloud, when installed as a normal plugin (never on Mody Cloud's network).
UpdateCheck::register( __FILE__ );
