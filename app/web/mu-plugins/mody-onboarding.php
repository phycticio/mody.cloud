<?php
/**
 * Plugin Name: Mody Onboarding
 * Description: A new member's first steps: their first password opens their site's Identity screen, and the editor tour shows them around.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Onboarding
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-onboarding/mody-onboarding.php';
