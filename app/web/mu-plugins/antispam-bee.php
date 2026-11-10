<?php
/**
 * Plugin Name: Antispam Bee (loaded by Mody Cloud)
 * Description: Antispam Bee's comment checks on every site, as a must-use plugin that nobody can switch off (tickets/antibot-altcha.md). Composer installs it into mu-plugins/antispam-bee/. Mody's Security\AntispamBee pins its three checks that call outside services off (rule 1).
 * Author: Mody Cloud
 *
 * @package Mody\Platform
 */

defined( 'ABSPATH' ) || exit;

if ( is_readable( __DIR__ . '/antispam-bee/antispam_bee.php' ) ) {
	require_once __DIR__ . '/antispam-bee/antispam_bee.php';
}
