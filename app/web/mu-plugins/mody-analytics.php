<?php
/**
 * Plugin Name: Mody Analytics
 * Description: Counts every site's visitors and page views without cookies, with nothing stored in the browser and no address kept. The site's Dashboard shows them. Always active.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory.
 *
 * @package Mody\Analytics
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-analytics/mody-analytics.php';
