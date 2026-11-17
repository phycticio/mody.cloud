<?php
/**
 * Plugin Name: Mody Login
 * Description: The login page in the site's brand: its logo, palette and fonts, in a color scheme chosen in Settings → Login.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Login
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-login/mody-login.php';
