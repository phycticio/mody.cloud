<?php
/**
 * Plugin Name: Spectra (loaded by Mody Cloud)
 * Description: Spectra's blocks on every site, as a must-use plugin that nobody can switch off (ticket M3-11). Composer installs Spectra into mu-plugins/spectra-blocks/. mody-platform.php loads first, so SpectraGuard keeps Spectra inside rule 1.
 * Author: Mody Cloud
 *
 * @package Mody\Platform
 */

defined( 'ABSPATH' ) || exit;

if ( is_readable( __DIR__ . '/spectra-blocks/spectra-blocks.php' ) ) {
	require_once __DIR__ . '/spectra-blocks/spectra-blocks.php';
}
