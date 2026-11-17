<?php
/**
 * Plugin Name: Mody Agent
 * Description: Mody, the site's AI agent: the chat in the editors, the pages it writes in the site's brand, and its tools, through a European model (Infomaniak AI Services).
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Agent
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-agent/mody-agent.php';
