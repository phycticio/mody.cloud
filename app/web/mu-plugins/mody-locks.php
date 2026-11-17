<?php
/**
 * Plugin Name: Mody Locks
 * Description: What members may not do on a network: install plugins, change themes, open the Plugins or Connectors screens, change the ai plugin, delete their site.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Locks
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-locks/mody-locks.php';
