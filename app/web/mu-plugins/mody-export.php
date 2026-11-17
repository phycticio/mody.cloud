<?php
/**
 * Plugin Name: Mody Export
 * Description: Take your site with you: any administrator of a member site downloads the whole site, code, media and database, at any time.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Export
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-export/mody-export.php';
