<?php
/**
 * Plugin Name: Mody Antispam
 * Description: Antispam Bee without its checks that call outside services: Gravatar, the country check and the language check stay off.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Antispam
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-antispam/mody-antispam.php';
