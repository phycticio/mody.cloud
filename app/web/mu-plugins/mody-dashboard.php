<?php
/**
 * Plugin Name: Mody Dashboard
 * Description: Every site's Dashboard: the site at a glance, its visitors on a board of charts, Mody's work, and the account's sites and plan; other Mody plugins add their own sections.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Dashboard
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-dashboard/mody-dashboard.php';
