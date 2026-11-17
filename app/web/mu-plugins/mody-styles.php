<?php
/**
 * Plugin Name: Mody Styles
 * Description: Writes a site's Global Styles for the plugins that change them: the design presets (the theme's style variations), palette colors, and a guarded patch of the rest.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Styles
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-styles/mody-styles.php';
