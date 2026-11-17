<?php
/**
 * Plugin Name: Mody Patterns
 * Description: Every site's patterns come from the Mody collection, with placeholder images drawn on the site; nothing is fetched from WordPress.org.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Patterns
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-patterns/mody-patterns.php';
