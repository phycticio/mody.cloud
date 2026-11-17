<?php
/**
 * Plugin Name: Mody Sites
 * Description: Making a network's sites: the New site wizard, provisioning (the site, its brand, its style, a first page), and the sites an account owns.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Sites
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-sites/mody-sites.php';
