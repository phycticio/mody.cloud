<?php
/**
 * Every uploaded picture becomes a web-efficient WebP before WordPress
 * stores it.
 *
 * @package Mody\Images
 */

namespace Mody\Images;

defined( 'ABSPATH' ) || exit;

/**
 * The hooks:
 *
 * - `wp_handle_upload_prefilter` and `wp_handle_sideload_prefilter`
 *   (prefilter()): every upload goes through one of them (the Media
 *   Library, the block editor's REST uploads, media_handle_sideload()). The
 *   uploaded file is replaced in place by its WebP, and its name and type
 *   follow, so WordPress checks, names and stores a WebP: `photo.jpg` is
 *   saved as `photo.webp`. Priority 20: after the platform's checks at 10
 *   (Gating\ImageLimits refuses a picture too big to decode before this
 *   decodes it). A picture that cannot be optimized is uploaded as it came:
 *   this never makes an upload fail.
 * - `wp_editor_set_quality`: the sizes WordPress makes from a WebP use
 *   Optimizer::SIZE_QUALITY.
 * - `wp_client_side_media_processing_enabled`: off. Since WordPress 7.1 the
 *   block editor can make the sizes in the browser and upload each one; here
 *   the server makes them from the optimized WebP, the same way for every
 *   upload.
 */
final class Uploads {

	/** After the platform's upload checks (10). */
	public const PRIORITY = 20;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'wp_handle_upload_prefilter', array( self::class, 'prefilter' ), self::PRIORITY );
		add_filter( 'wp_handle_sideload_prefilter', array( self::class, 'prefilter' ), self::PRIORITY );
		add_filter( 'wp_editor_set_quality', array( self::class, 'quality' ), 10, 2 );
		add_filter( 'wp_client_side_media_processing_enabled', '__return_false' );
	}

	/**
	 * Replaces an uploaded picture by its WebP.
	 *
	 * @param array<string, mixed> $file The upload, as in `$_FILES`.
	 * @return array<string, mixed>
	 */
	public static function prefilter( $file ) {
		if ( ! is_array( $file ) || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
			return $file;
		}
		$tmp = (string) $file['tmp_name'];
		if ( ! Optimizer::wants( $tmp ) ) {
			return $file;
		}

		// Next to the upload, then over it: the path stays the one PHP registered, so move_uploaded_file() still accepts it.
		$webp = $tmp . '-mody.webp';
		$made = Optimizer::optimize( $tmp, $webp );
		if ( ! is_array( $made ) ) {
			return $file;
		}
		if ( ! rename( $webp, $tmp ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Files of this upload.
			wp_delete_file( $webp );
			return $file;
		}
		clearstatcache( true, $tmp );

		$file['name'] = self::webp_name( (string) ( $file['name'] ?? '' ) );
		$file['type'] = 'image/webp';
		$file['size'] = (int) filesize( $tmp );
		return $file;
	}

	/**
	 * `wp_editor_set_quality`: the quality of WebP sizes.
	 *
	 * @param int    $quality   The quality.
	 * @param string $mime_type The type being written.
	 * @return int
	 */
	public static function quality( $quality, $mime_type = '' ) {
		return 'image/webp' === $mime_type ? Optimizer::SIZE_QUALITY : $quality;
	}

	/**
	 * A file name with the .webp extension: `Photo 1.JPG` is `Photo 1.webp`.
	 *
	 * @param string $name The uploaded name.
	 */
	public static function webp_name( string $name ): string {
		$base = pathinfo( $name, PATHINFO_FILENAME );
		return ( '' === $base ? 'image' : $base ) . '.webp';
	}
}
