<?php
/**
 * Mody Analytics: visitors and page views on every site of the network
 * (asked for by the maintainer on 2026-11-11, tickets/mody-dashboard.md).
 *
 * - **Tracker:** a few lines of script on the front end send one beacon per
 *   page view to the site's own REST route. Not for logged-in users,
 *   previews or error pages.
 * - **Collector:** the route. It counts the view, the page, and for a
 *   visitor's first view of the day their source and device. A visitor is
 *   recognised for one day by a hash of their address, browser and the site
 *   with a salt that changes every day; the hashes of earlier days are
 *   deleted. No cookie, nothing stored in the browser, no address kept.
 * - **Store:** two network tables: daily totals, and today's hashes.
 * - **Stats:** what the Mody dashboard reads (mody-platform's
 *   Dashboard\DashboardPage).
 *
 * Rule 1: nothing leaves the network; no outside service, script or font.
 * It stands alone: mody-platform only reads Stats when it exists.
 *
 * @package Mody\Analytics
 */

namespace Mody\Analytics;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Analytics\Store is src/Store.php.
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

Store::register();
Tracker::register();
Collector::register();
