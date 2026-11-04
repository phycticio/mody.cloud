<?php
/**
 * Plugin Name: Mody Platform
 * Description: Mody Cloud platform logic (AI provider, and later registration, billing, wizard, gating). Always active.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory.
 *
 * @package Mody\Platform
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-platform/mody-platform.php';
