<?php
/**
 * Plugin Name:       Mody Spectra
 * Description:       Spectra's blocks without anything that connects outside Europe: no Google Maps, Google Fonts, AI design library or telemetry.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * Keeps Spectra inside rule 1 (documentation/constraints.md): everything in
 * Spectra that connects outside Europe is off, and requests to those hosts
 * are refused (SpectraGuard; run scripts/spectra-guard-test.php after every
 * Spectra update). It must load before Spectra: as a MU plugin,
 * mody-spectra.php sorts before spectra-blocks.php; as a normal plugin,
 * mody-spectra/ sorts before spectra-blocks/.
 *
 * On the platform, Spectra itself is a MU plugin (M3-11), and SpectraLoader
 * keeps the copy it once had as a network-active plugin from loading beside
 * it. That part runs only where Spectra is a MU plugin.
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class.
 *
 * @package Mody\Spectra
 */

namespace Mody\Spectra;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Spectra\Example is src/Example.php.
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
 * Now, before plugins load, so the guard is in place before Spectra.
 */
SpectraGuard::boot();
if ( is_readable( WPMU_PLUGIN_DIR . '/spectra-blocks/spectra-blocks.php' ) ) { // Spectra is a MU plugin here (it is not loaded yet).
	SpectraLoader::boot();
}
