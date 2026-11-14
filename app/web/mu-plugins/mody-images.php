<?php
/**
 * Plugin Name: Mody Images
 * Description: Makes every picture uploaded to a site of the network web efficient: turned upright, at most 2560 pixels on a side, its camera data (place included) removed, saved as WebP with WebP sizes. Always active.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory.
 *
 * @package Mody\Images
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-images/mody-images.php';
