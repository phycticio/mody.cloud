<?php
/**
 * Plugin Name: Mody Fonts
 * Description: The Font Library with the Mody fonts collection, ten free families served from this plugin, and no Google Fonts.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Fonts
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-fonts/mody-fonts.php';
