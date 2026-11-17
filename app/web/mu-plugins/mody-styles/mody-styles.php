<?php
/**
 * Plugin Name:       Mody Styles
 * Description:       Writes a site's Global Styles for the plugins that change them: the design presets (the theme's style variations), palette colors, and a guarded patch of the rest.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * Writing a site's Global Styles, for whoever changes them (the agent's
 * mody/update-design and mody/update-styles, the wizard, provisioning):
 *
 * - **Presets** (M2-06): the theme's style variations, the single list of
 *   design presets.
 * - **SiteDesign**: a whole preset, or single palette colors.
 * - **SiteStyles** (M3-10): a guarded patch of the rest of the styles.
 * - **Screens**: the Site Editor's Styles screen that shows a change.
 *
 * It adds no hook: other plugins call it, behind a class_exists() check.
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class.
 *
 * @package Mody\Styles
 */

namespace Mody\Styles;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Styles\Example is src/Example.php.
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

// No hooks: a library for the plugins that change a site's styles.
