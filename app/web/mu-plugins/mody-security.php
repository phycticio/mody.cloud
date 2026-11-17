<?php
/**
 * Plugin Name: Mody Security
 * Description: ALTCHA on the login, lost password and comment forms, rate limits by the visitor's real address, the installation's own keys and salts, and XML-RPC off.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Security
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-security/mody-security.php';
