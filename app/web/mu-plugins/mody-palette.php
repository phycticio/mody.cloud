<?php
/**
 * Plugin Name: Mody Palette
 * Description: Color pickers offer only the site's palette, Spectra's own pickers included.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Palette
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-palette/mody-palette.php';
