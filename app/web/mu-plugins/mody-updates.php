<?php
/**
 * Plugin Name: Mody Updates
 * Description: Nobody updates WordPress, plugins, themes or translations from wp-admin, and core never checks WordPress.org for updates on its own: versions change only through the repository.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Updates
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-updates/mody-updates.php';
