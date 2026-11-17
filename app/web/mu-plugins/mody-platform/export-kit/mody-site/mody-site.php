<?php
/**
 * Plugin Name:       Mody Site
 * Description:       What a site made on Mody Cloud needs to look and work the same on any WordPress: Antispam Bee without its outside checks. Mody, the AI agent, is not part of it.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * Author URI:        https://mody.cloud
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 7.1
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * Built from the platform's own classes (tickets/take-your-site-with-you.md):
 * Export\Portable copies the files this bootstrap loads, under the same
 * namespace, so every option, post meta and address keeps its name. It is a
 * normal plugin: switching it off removes these features and nothing else.
 *
 * Not here, on purpose: the agent and its abilities, the platform's billing,
 * signup and quotas, and the locks that keep members from installing plugins
 * or updating WordPress on Mody Cloud.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform;

defined( 'ABSPATH' ) || exit;

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

// Antispam Bee's checks that call outside services stay off.
Security\AntispamBee::register();
