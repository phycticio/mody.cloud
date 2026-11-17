<?php
/**
 * Plugin Name: Mody Brand
 * Description: A site's mission and tone, and Settings → Brand: what the business is, in its owner's words, for whoever writes for the site.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Brand
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-brand/mody-brand.php';
