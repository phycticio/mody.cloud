<?php
/**
 * Plugin Name: Mody Domains
 * Description: Custom domains for a network's sites: a CNAME, DNS checks, certificates through Nginx Proxy Manager, and the Your domain section of the Mody dashboard.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Domains
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-domains/mody-domains.php';
