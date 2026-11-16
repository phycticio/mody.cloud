<?php
/**
 * Plugin Name: Mody SEO
 * Description: How each page and post of a site shows in search results: its SEO title and SEO description, edited in the editor's sidebar. Always active.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory.
 *
 * @package Mody\Seo
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-seo/mody-seo.php';
