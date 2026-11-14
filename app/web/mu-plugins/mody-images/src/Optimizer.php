<?php
/**
 * Turns one picture into a web-efficient WebP.
 *
 * @package Mody\Images
 */

namespace Mody\Images;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * What every picture becomes (tickets/image-optimization.md):
 *
 * - **Upright**: the camera's orientation is applied to the pixels, so no
 *   browser or editor has to read it.
 * - **At most MAX_SIDE pixels on a side** (WordPress's own "big image"
 *   threshold, so it never makes a `-scaled` copy of these).
 * - **Without its camera data**: EXIF, XMP, IPTC and comments go, with the
 *   place a phone wrote into a photo. The color profile stays, so a photo
 *   taken in Display P3 keeps its colors; CMYK and grayscale become sRGB.
 * - **WebP**: photos (JPEG, HEIC) at PHOTO_QUALITY, PNG at GRAPHIC_QUALITY
 *   (sharper edges for logos and screenshots), transparency kept.
 *
 * Which pictures: JPEG, PNG and HEIC always; WebP and AVIF only when larger
 * than MAX_SIDE (they are already efficient, and encoding them again only
 * loses quality). Never GIF, SVG or an animation, which would lose its frames.
 * When nothing had to be made smaller and the WebP is not smaller than the
 * file, the file is left as it is.
 *
 * Imagick does the work when it writes WebP (the platform's stack), GD
 * through WordPress's image editor otherwise (HEIC then stays as it is: GD
 * cannot read it). Either way the editors are chosen through WordPress first,
 * so the `wp_image_editors` filter, and the platform's Imagick limits
 * (Media\ImageSize), apply before anything is decoded.
 */
final class Optimizer {

	/** Pixels on the longest side, at most. */
	public const MAX_SIDE = 2560;

	/** WebP quality for photos (JPEG, HEIC, and WebP or AVIF made smaller). */
	public const PHOTO_QUALITY = 82;

	/** WebP quality for PNG: logos, screenshots and drawings. */
	public const GRAPHIC_QUALITY = 90;

	/** WebP quality of the sizes WordPress makes from the picture. */
	public const SIZE_QUALITY = 82;

	/** Always turned into WebP. */
	public const CONVERT = array( 'image/jpeg', 'image/png', 'image/heic', 'image/heif' );

	/** Turned into a smaller WebP only when larger than MAX_SIDE. */
	public const RESIZE_ONLY = array( 'image/webp', 'image/avif' );

	/** Bytes read from the start of a file to tell whether it is animated. */
	private const HEAD_BYTES = 262144;

	/**
	 * Whether optimize() has work to do on a file: its type (from its content,
	 * never its name), no animation, and for WebP and AVIF its size. Cheap:
	 * nothing is decoded.
	 *
	 * @param string $path The file.
	 */
	public static function wants( string $path ): bool {
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		$mime = (string) wp_get_image_mime( $path );
		if ( ! in_array( $mime, array_merge( self::CONVERT, self::RESIZE_ONLY ), true ) || self::animated( $path, $mime ) ) {
			return false;
		}
		if ( in_array( $mime, self::RESIZE_ONLY, true ) ) {
			$size = wp_getimagesize( $path );
			return is_array( $size ) && max( (int) $size[0], (int) $size[1] ) > self::MAX_SIDE;
		}
		return true;
	}

	/**
	 * Writes the web-efficient WebP of a picture.
	 *
	 * @param string $source The picture; it is not changed.
	 * @param string $dest   Where the WebP goes (a path ending in .webp).
	 * @return array{path: string, width: int, height: int, resized: bool, before: int, after: int}|null|WP_Error
	 *         The WebP; null when there is nothing to do (the type, an
	 *         animation, or a WebP no smaller than the file); WP_Error when the
	 *         picture could not be read or written. Only an array leaves a
	 *         file at $dest.
	 */
	public static function optimize( string $source, string $dest ) {
		if ( ! self::wants( $source ) ) {
			return null;
		}
		// Choosing an editor runs the wp_image_editors filter: the platform's Imagick limits are set before anything decodes.
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return null;
		}
		$mime    = (string) wp_get_image_mime( $source );
		$quality = 'image/png' === $mime ? self::GRAPHIC_QUALITY : self::PHOTO_QUALITY;

		$made = self::imagick_writes_webp()
			? self::with_imagick( $source, $dest, $quality )
			: self::with_editor( $source, $dest, $quality );
		if ( ! is_array( $made ) ) {
			if ( is_file( $dest ) ) {
				wp_delete_file( $dest );
			}
			return $made;
		}

		clearstatcache( true, $dest );
		$made['before'] = (int) filesize( $source );
		$made['after']  = (int) filesize( $dest );
		if ( $made['after'] < 1 || ( ! $made['resized'] && $made['after'] >= $made['before'] ) ) {
			wp_delete_file( $dest );
			return null;
		}
		$made['path'] = $dest;
		return $made;
	}

	/**
	 * Whether the file is an animation (APNG, animated WebP or AVIF). Read from
	 * the start of the file only.
	 *
	 * @param string $path The file.
	 * @param string $mime Its type, from its content.
	 */
	public static function animated( string $path, string $mime ): bool {
		$head = (string) file_get_contents( $path, false, null, 0, self::HEAD_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
		switch ( $mime ) {
			case 'image/png':
				// An APNG has an acTL chunk before its first IDAT.
				$actl = strpos( $head, 'acTL' );
				$idat = strpos( $head, 'IDAT' );
				return false !== $actl && ( false === $idat || $actl < $idat );
			case 'image/webp':
				// The extended header's animation flag.
				return strlen( $head ) > 20 && 'VP8X' === substr( $head, 12, 4 ) && ( ord( $head[20] ) & 0x02 );
			case 'image/avif':
				// An image sequence: the "avis" brand in the ftyp box.
				return str_contains( substr( $head, 0, 64 ), 'avis' );
			case 'image/gif':
				// Two or more frames: GIF is never changed anyway.
				return substr_count( $head, "\x00\x21\xF9\x04" ) > 1;
		}
		return false;
	}

	/**
	 * Whether this PHP's Imagick can write WebP.
	 */
	private static function imagick_writes_webp(): bool {
		return extension_loaded( 'imagick' ) && class_exists( \Imagick::class ) && array() !== \Imagick::queryFormats( 'WEBP' );
	}

	/**
	 * The WebP, with Imagick.
	 *
	 * @param string $source  The picture.
	 * @param string $dest    The WebP.
	 * @param int    $quality WebP quality.
	 * @return array{width: int, height: int, resized: bool}|WP_Error
	 */
	private static function with_imagick( string $source, string $dest, int $quality ) {
		try {
			$image = new \Imagick();
			$image->readImage( $source );
			if ( $image->getNumberImages() > 1 ) {
				// A HEIC may carry thumbnails or depth maps: the first image is the picture.
				$image->setIteratorIndex( 0 );
				$first = $image->getImage();
				$image->clear();
				$image = $first;
			}

			self::upright( $image );

			// Colors: sRGB pixels keep their profile; CMYK, grayscale and the rest become sRGB.
			$colorspace = $image->getImageColorspace();
			$icc        = '';
			if ( in_array( $colorspace, array( \Imagick::COLORSPACE_SRGB, \Imagick::COLORSPACE_RGB ), true ) ) {
				$profiles = $image->getImageProfiles( 'icc', true );
				$icc      = (string) ( $profiles['icc'] ?? '' );
			} else {
				$image->transformImageColorspace( \Imagick::COLORSPACE_SRGB );
			}
			$image->stripImage(); // EXIF (and the place), XMP, IPTC, comments.
			if ( '' !== $icc ) {
				$image->profileImage( 'icc', $icc );
			}

			$width   = $image->getImageWidth();
			$height  = $image->getImageHeight();
			$resized = max( $width, $height ) > self::MAX_SIDE;
			if ( $resized ) {
				list( $width, $height ) = wp_constrain_dimensions( $width, $height, self::MAX_SIDE, self::MAX_SIDE );
				$image->resizeImage( $width, $height, \Imagick::FILTER_LANCZOS, 1 );
			}

			$image->setImageDepth( 8 );
			$image->setImageFormat( 'WEBP' );
			$image->setImageCompressionQuality( $quality );
			$image->setCompressionQuality( $quality );
			$image->writeImage( 'webp:' . $dest );
			$image->clear();
		} catch ( \Exception $e ) {
			return new WP_Error( 'mody_images_imagick', $e->getMessage() );
		}
		return array(
			'width'   => (int) $width,
			'height'  => (int) $height,
			'resized' => $resized,
		);
	}

	/**
	 * Applies the camera's orientation to the pixels. Older Imagick has no
	 * autoOrient(): the eight EXIF orientations by hand.
	 *
	 * @param \Imagick $image The picture.
	 */
	private static function upright( \Imagick $image ): void {
		if ( method_exists( $image, 'autoOrient' ) ) {
			$image->autoOrient();
			return;
		}
		switch ( $image->getImageOrientation() ) {
			case \Imagick::ORIENTATION_TOPRIGHT:
				$image->flopImage();
				break;
			case \Imagick::ORIENTATION_BOTTOMRIGHT:
				$image->rotateImage( '#000', 180 );
				break;
			case \Imagick::ORIENTATION_BOTTOMLEFT:
				$image->flipImage();
				break;
			case \Imagick::ORIENTATION_LEFTTOP:
				$image->transposeImage();
				break;
			case \Imagick::ORIENTATION_RIGHTTOP:
				$image->rotateImage( '#000', 90 );
				break;
			case \Imagick::ORIENTATION_RIGHTBOTTOM:
				$image->transverseImage();
				break;
			case \Imagick::ORIENTATION_LEFTBOTTOM:
				$image->rotateImage( '#000', 270 );
				break;
		}
		$image->setImageOrientation( \Imagick::ORIENTATION_TOPLEFT );
	}

	/**
	 * The WebP, with WordPress's image editor (GD). GD keeps no metadata at
	 * all, so the camera data goes with it (the color profile too).
	 *
	 * @param string $source  The picture.
	 * @param string $dest    The WebP.
	 * @param int    $quality WebP quality.
	 * @return array{width: int, height: int, resized: bool}|WP_Error
	 */
	private static function with_editor( string $source, string $dest, int $quality ) {
		$editor = wp_get_image_editor( $source, array( 'mime_type' => 'image/webp' ) );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		$editor->maybe_exif_rotate();
		$size    = $editor->get_size();
		$resized = max( (int) $size['width'], (int) $size['height'] ) > self::MAX_SIDE;
		if ( $resized ) {
			$done = $editor->resize( self::MAX_SIDE, self::MAX_SIDE );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		$editor->set_quality( $quality );
		$saved = $editor->save( $dest, 'image/webp' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		if ( $saved['path'] !== $dest && ! rename( $saved['path'], $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- A file this request made.
			return new WP_Error( 'mody_images_write', 'Could not write ' . $dest );
		}
		return array(
			'width'   => (int) $saved['width'],
			'height'  => (int) $saved['height'],
			'resized' => $resized,
		);
	}
}
