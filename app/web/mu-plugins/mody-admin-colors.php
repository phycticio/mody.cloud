<?php
/**
 * Plugin Name: Mody Admin Colors
 * Description: Ocean as every user's wp-admin color scheme unless they choose another.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\AdminColors
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-admin-colors/mody-admin-colors.php';
