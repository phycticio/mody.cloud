<?php
/**
 * Plugin Name:       Mody Brand
 * Description:       A site's mission and tone, and Settings → Brand: what the business is, in its owner's words, for whoever writes for the site.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * A site's brand, beyond what WordPress already has (decided by the
 * maintainer on 2026-11-08 and 2026-11-11, tickets/M2-08 and
 * tickets/your-brand-page.md). The name, tagline, logo and icon are
 * WordPress's own settings, and the styles are its Global Styles. This
 * plugin adds:
 *
 * - **Mission and tone** (BrandSettings): two site settings, shown on the
 *   Identity screen (assets/identity-fields.js) and in Settings → General.
 * - **Your brand** (BrandBook, BrandPage): Settings → Brand, the rest of
 *   what the business is (voice, audience, offer, story, values, facts), in
 *   one site setting, `mody_brand`.
 *
 * Other plugins read the brand through these classes, behind a
 * class_exists() check. The `mody_brand_context` filter lets the plugin that
 * writes the prompts show what it sends, at the foot of Settings → Brand.
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class.
 *
 * @package Mody\Brand
 */

namespace Mody\Brand;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Brand\Example is src/Example.php.
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

add_action( 'init', array( BrandSettings::class, 'migrate' ), 1 ); // Once per network: the old profile table into each site's settings, then dropped (M2-08).
add_action( 'init', array( BrandSettings::class, 'register' ) );
add_action( 'admin_init', array( BrandSettings::class, 'general_fields' ) );
add_action( 'admin_enqueue_scripts', array( BrandSettings::class, 'enqueue_identity_fields' ) );
add_action( 'init', array( BrandBook::class, 'register' ) );
add_action( 'admin_menu', array( BrandPage::class, 'register_menu' ) );
add_action( 'admin_post_' . BrandPage::ACTION, array( BrandPage::class, 'save' ) );
