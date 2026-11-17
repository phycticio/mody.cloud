<?php
/**
 * Plugin Name:       Mody Antispam
 * Description:       Antispam Bee without its checks that call outside services: Gravatar, the country check and the language check stay off.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * Keeps Antispam Bee inside rule 1 (tickets/antibot-altcha.md): its three
 * checks that call outside services (Gravatar, iplocate.io, its language
 * API) are pinned off, whatever is saved, and hidden on its settings screen.
 * On the platform Antispam Bee is a MU plugin (mu-plugins/antispam-bee.php);
 * on a downloaded site, a normal one.
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class.
 *
 * @package Mody\Antispam
 */

namespace Mody\Antispam;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Antispam\Example is src/Example.php.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( __NAMESPACE__ ) + 1 ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	}
);

AntispamBee::register();
