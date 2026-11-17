<?php
/**
 * Plugin Name: Mody Profiles
 * Description: Profile pictures stored on the site and initials for everyone else, no Gravatar; on a network, one profile per person, edited on the main site.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Profiles
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-profiles/mody-profiles.php';
