<?php
/**
 * How big an uploaded picture may be in pixels (security pass, 2026-11-12).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Gating;

use Mody\Platform\Media\ImageSize;

defined( 'ABSPATH' ) || exit;

/**
 * The platform's part of the picture limits: who is limited, and where the
 * check runs. What measures a picture, and Imagick's limits, are in
 * Media\ImageSize, which Mody Site carries into a downloaded site.
 *
 * - **Logos and profile pictures** (the wizard, the profile screen): at most
 *   PROFILE_MAX_SIDE pixels on each side. They are drawn small.
 * - **Everything members upload to a media library** (the Media Library, the
 *   block editor, REST): at most MEDIA_MAX_PIXELS in total and MEDIA_MAX_SIDE
 *   on a side, read from the header before anything decodes the file. 64
 *   megapixels is above every phone and most cameras (a 48 MP phone photo is
 *   8064 by 6048).
 * - **Imagick** gets its own limits (width, height, area, memory, map and
 *   disk), because it reads the whole picture outside PHP's memory limit and
 *   formats with no cheap header (HEIC) are not sized by the check above.
 *
 * Super admins are not limited by the upload check.
 */
final class ImageLimits {

	/** Logos and profile pictures: pixels on each side, at most. */
	public const PROFILE_MAX_SIDE = ImageSize::PROFILE_MAX_SIDE;

	/** Any other upload: pixels on each side, at most. */
	public const MEDIA_MAX_SIDE = ImageSize::MEDIA_MAX_SIDE;

	/** Any other upload: pixels in total, at most (64 megapixels). */
	public const MEDIA_MAX_PIXELS = ImageSize::MEDIA_MAX_PIXELS;

	/**
	 * Whether a picture is too big or too small, from its header only.
	 *
	 * @param string $path       The file.
	 * @param int    $max_side   Pixels on each side, at most.
	 * @param int    $max_pixels Pixels in total, at most; 0 for no limit.
	 */
	public static function too_big( string $path, int $max_side, int $max_pixels = 0 ): bool {
		return ImageSize::too_big( $path, $max_side, $max_pixels );
	}

	/**
	 * Hooked to `wp_handle_upload_prefilter` and `wp_handle_sideload_prefilter`:
	 * refuses an oversized picture before anything decodes it. Every upload
	 * (the Media Library, the block editor's REST uploads, sideloads) goes
	 * through these.
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
	 * Hooked to `wp_image_editors`: gives Imagick its limits before the editors
	 * are chosen (ImageSize::limit_imagick()). The list of editors is returned
	 * unchanged.
	 *
	 * @param string[] $editors The editors.
	 * @return string[]
	 */
	public static function limit_imagick( $editors ) {
		ImageSize::limit_imagick();
		return $editors;
	}
}
