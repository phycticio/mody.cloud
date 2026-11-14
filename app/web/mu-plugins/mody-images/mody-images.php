<?php
/**
 * Plugin Name:       Mody Images
 * Description:       Makes every uploaded picture web efficient: turned upright, at most 2560 pixels on a side, its camera data (place included) removed, saved as WebP with WebP sizes. A WP-CLI command converts the pictures already in the media library.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * On the platform, mu-plugins/mody-images.php loads this file; a site
 * downloaded from Mody Cloud (tickets/take-your-site-with-you.md) runs it
 * as a normal plugin, so it carries its own header.
 *
 * Mody Images (decided by the maintainer on 2026-11-14,
 * tickets/image-optimization.md):
 *
 * - **Uploads** (Uploads): every JPEG, PNG and HEIC uploaded through
 *   WordPress (the Media Library, the block editor, REST, sideloads) is
 *   replaced before WordPress stores it by a WebP: upright, at most
 *   Optimizer::MAX_SIDE pixels on a side, without EXIF, XMP or IPTC (the
 *   place a phone wrote into a photo is gone), with its color profile. The
 *   uploaded original is not kept. WebP and AVIF are changed only when larger
 *   than MAX_SIDE; GIF, SVG and animations never.
 * - **Sizes**: WordPress makes the sizes from that WebP, so they are WebP too,
 *   at Optimizer::SIZE_QUALITY. The browser does not make them (core's
 *   client-side media processing is off), so every picture takes one path.
 * - **The media library already there** (Library, Command):
 *   `wp mody-images optimize` converts older pictures the same way, and
 *   points the site's content at the new files.
 *
 * Everything runs on our own server with Imagick (GD when Imagick is
 * missing): no outside service (rule 1), and no setting to keep.
 *
 * @package Mody\Images
 */

namespace Mody\Images;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Images\Optimizer is src/Optimizer.php.
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

Uploads::register();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'mody-images', Command::class );
}
