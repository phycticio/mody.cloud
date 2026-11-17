<?php
/**
 * Plugin Name: Mody Spectra
 * Description: Spectra's blocks without anything that connects outside Europe: no Google Maps, Google Fonts, AI design library or telemetry.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Spectra
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-spectra/mody-spectra.php';
