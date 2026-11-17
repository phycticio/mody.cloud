<?php
/**
 * Mody Platform bootstrap.
 *
 * See documentation/mvp-spec.md (section 12) for what belongs in this plugin,
 * and documentation/ai-engine.md for the AI integration.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Agent\AI\BlockValidator is src/AI/BlockValidator.php.
 * The AI classes extend core's AI Client, so they are only referenced after
 * checking that the client exists.
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
 * The Mody dashboard (2026-11-11, tickets/mody-dashboard.md): every site's
 * Dashboard menu item opens it, and core's dashboard redirects there. Each
 * site has 1 GB, with WordPress's own upload quota (Mody Storage).
 */
Dashboard\DashboardPage::register();
if ( class_exists( \Mody\Analytics\Query::class ) ) {
	Dashboard\Board::register(); // The Visitors section's board of charts (2026-11-12, tickets/analytics-chat.md).
	if ( function_exists( 'wp_ai_client_prompt' ) && class_exists( \Mody\Agent\Budget::class ) ) { // The analyst runs on Mody Agent's model limits and log.
		Dashboard\Analyst::register(); // Mody on that board: reads only the analytics, changes only the board (phase 2).
	}
}
