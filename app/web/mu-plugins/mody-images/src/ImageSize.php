<?php
/**
 * How big a picture may be before anything decodes it (security pass,
 * 2026-11-12; in Mody Images since 2026-11-17).
 *
 * @package Mody\Images
 */

namespace Mody\Images;

defined( 'ABSPATH' ) || exit;

/**
 * A few kilobytes of PNG can say it is 40,000 pixels wide: the file is small,
 * but the image editor has to decode every pixel into memory before it can
 * resize it. Two guards:
 *
 * - **The header** (too_big()): the size a file says it has, read without
 *   decoding. Only for the formats PHP sizes by itself. HEIC and the others
 *   have no cheap header path (WordPress would load the whole picture with
 *   Imagick to size them, which is the decode this exists to avoid), so
 *   they are not judged here.
 * - **Imagick's own limits** (limit_imagick()): width, height, area, memory,
 *   map and disk. This is what refuses what the header check cannot see (HEIC,
 *   a picture that lies about its header). Memory, map and area alone only make
 *   Imagick keep the pixels on disk instead of in memory, slowly, so the disk
 *   limit is what turns "too big" into a refusal.
 *
 * Where they run:
 *
 * - **Every upload to a media library** (the Media Library, the block
 *   editor, REST, sideloads; prefilter(), before Uploads makes the WebP): at
 *   most MEDIA_MAX_PIXELS in total and MEDIA_MAX_SIDE on a side. 64
 *   megapixels is above every phone and most cameras (a 48 MP phone photo is
 *   8064 by 6048). Super admins are not limited.
 * - **Logos and profile pictures** (the wizard, the profile screen): at most
 *   PROFILE_MAX_SIDE on a side; the plugins that take them call too_big().
 * - **Imagick** gets its limits before the image editors are chosen
 *   (limit_editors()), because it reads the whole picture outside PHP's
 *   memory limit and formats with no cheap header (HEIC) are not sized by the
 *   header check.
 */
final class ImageSize {

	/** Logos and profile pictures: pixels on each side, at most. */
	public const PROFILE_MAX_SIDE = 6000;

	/** Any other upload: pixels on each side, at most. */
	public const MEDIA_MAX_SIDE = 20000;

	/** Any other upload: pixels in total, at most (64 megapixels). */
	public const MEDIA_MAX_PIXELS = 64000000;

	/**
	 * Imagick's memory, map and disk for the pixels of one process, in bytes
	 * (1 GiB each). The stack's Imagick (Q16-HDRI) keeps four 32-bit channels
	 * per pixel, so 64 megapixels of RGBA is 1.02 GB: the biggest picture the
	 * upload limit allows still fits in memory. A picture past the area limit
	 * is kept on disk, and one that needs more than the disk limit is refused
	 * ("cache resources exhausted").
	 */
	public const IMAGICK_BYTES = 1073741824;

	/**
	 * The types PHP reads the size of from the header alone.
	 *
	 * @var string[]
	 */
	private const HEADER_TYPES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/tiff' );

	/**
	 * Whether a picture is too big or too small, from its header only.
	 *
	 * A file whose header cannot be read, or whose type has no cheap header
	 * (HEIC), is not judged here: it is not a picture WordPress sizes without
	 * decoding it, and the type checks and Imagick's limits decide.
	 *
	 * @param string $path       The file.
	 * @param int    $max_side   Pixels on each side, at most.
	 * @param int    $max_pixels Pixels in total, at most; 0 for no limit.
	 */
	public static function too_big( string $path, int $max_side, int $max_pixels = 0 ): bool {
		if ( '' === $path || ! is_readable( $path ) || ! in_array( wp_get_image_mime( $path ), self::HEADER_TYPES, true ) ) {
			return false;
		}
		$size = wp_getimagesize( $path );
		if ( ! is_array( $size ) ) {
			return false;
		}
		$width  = (int) $size[0];
		$height = (int) $size[1];
		if ( $width < 1 || $height < 1 || $width > $max_side || $height > $max_side ) {
			return true;
		}
		return $max_pixels > 0 && $width * $height > $max_pixels;
	}

	/**
	 * Gives Imagick its limits. Imagick is a process-wide library: the limits
	 * hold for every picture it opens in this PHP process, and stay for the
	 * next request this worker serves, so they are set the same every time.
	 * Without Imagick (GD only) there is nothing to set.
	 */
	public static function limit_imagick(): void {
		if ( ! class_exists( \Imagick::class ) ) {
			return;
		}
		$limits = array(
			\Imagick::RESOURCETYPE_WIDTH  => self::MEDIA_MAX_SIDE,
			\Imagick::RESOURCETYPE_HEIGHT => self::MEDIA_MAX_SIDE,
			\Imagick::RESOURCETYPE_AREA   => self::MEDIA_MAX_PIXELS,
			\Imagick::RESOURCETYPE_MEMORY => self::IMAGICK_BYTES,
			\Imagick::RESOURCETYPE_MAP    => self::IMAGICK_BYTES,
			\Imagick::RESOURCETYPE_DISK   => self::IMAGICK_BYTES,
		);
		foreach ( $limits as $type => $value ) {
			\Imagick::setResourceLimit( $type, $value );
		}
	}

	/**
	 * `wp_handle_upload_prefilter` and `wp_handle_sideload_prefilter` (before
	 * Uploads): refuses an oversized picture before anything decodes it.
	 *
	 * @param array<string, mixed> $file The upload, as in `$_FILES`.
	 * @return array<string, mixed>
	 */
	public static function prefilter( $file ) {
		if ( ! is_array( $file ) || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || is_super_admin() ) {
			return $file;
		}
		if ( self::too_big( (string) $file['tmp_name'], self::MEDIA_MAX_SIDE, self::MEDIA_MAX_PIXELS ) ) {
			$file['error'] = sprintf(
				/* translators: 1: pixels on a side, 2: megapixels. */
				__( 'That picture is too large to process: it can be up to %1$s pixels on a side and %2$s megapixels. Please make it smaller first.', 'mody_cloud' ),
				number_format_i18n( self::MEDIA_MAX_SIDE ),
				number_format_i18n( (int) ( self::MEDIA_MAX_PIXELS / 1000000 ) )
			);
		}
		return $file;
	}

	/**
	 * `wp_image_editors`: gives Imagick its limits before the editors are
	 * chosen. The list of editors is returned unchanged.
	 *
	 * @param string[] $editors The editors.
	 * @return string[]
	 */
	public static function limit_editors( $editors ) {
		self::limit_imagick();
		return $editors;
	}
}
