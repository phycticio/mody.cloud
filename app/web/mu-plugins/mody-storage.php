<?php
/**
 * Plugin Name: Mody Storage
 * Description: The space each site of a network has, 1 GB, with WordPress's own upload quota; no limit on the main site.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Storage
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-storage/mody-storage.php';
