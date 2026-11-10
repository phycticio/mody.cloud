<?php
/**
 * Plugin Name: Mody Languages
 * Description: Lets every site of the network be multilingual: translations as ordinary pages, language prefixes in addresses, a language switcher and the translation matrix (Settings → Languages). Always active.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory.
 *
 * @package Mody\Languages
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-languages/mody-languages.php';
