<?php
/**
 * Plugin Name: Mody Health
 * Description: Network Admin → Mody → Health: how the platform is doing at a glance, for super admins. Always active.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory.
 *
 * @package Mody\Health
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-health/mody-health.php';
