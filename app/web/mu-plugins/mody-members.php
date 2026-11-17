<?php
/**
 * Plugin Name: Mody Members
 * Description: Member accounts: the signup chat, the plan and the launch offer, the sites an account may have, and Invite friends.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Members
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-members/mody-members.php';
