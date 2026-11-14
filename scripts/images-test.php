<?php
/**
 * Tests Mody Images: every uploaded picture becomes a web-efficient WebP, and
 * `wp mody-images optimize` converts the pictures already in a media library
 * (tickets/image-optimization.md).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/images-test.php
 *
 * 1. The plugin: a MU plugin, its hooks, client-side media processing off,
 *    the quality of sizes.
 * 2. The optimizer: a phone photo (EXIF orientation and a GPS place) comes out
 *    upright, at most 2560 pixels, without camera data, as a smaller WebP; PNG
 *    keeps its transparency; CMYK becomes sRGB; WebP is changed only when too
 *    large; GIF and animations never.
 * 3. An upload: the stored file, its name and type, its sizes are WebP; no
 *    `-scaled` copy, no original kept.
 * 4. The media library: an older JPEG (with `-scaled` and its sizes) is
 *    converted, the content follows each size, the old files are gone.
 * 5. Take your site with you carries the plugin.
 *
 * Everything this makes (files, attachments, a post) is deleted at the end.
 */

use Mody\Images\Library;
use Mody\Images\Optimizer;
use Mody\Images\Uploads;
use Mody\Platform\Export\Portable;

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-66s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

$work = get_temp_dir() . 'mody-images-test-' . wp_generate_password( 8, false );
wp_mkdir_p( $work );
$attachments = array();
$posts       = array();
register_shutdown_function(
	static function () use ( $work, &$attachments, &$posts ): void {
		foreach ( $attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		foreach ( $posts as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( (array) glob( $work . '/*' ) as $file ) {
			wp_delete_file( $file );
		}
		rmdir( $work ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
);

/*
 * A photo-like picture written by Imagick: a fractal, so it compresses like
 * a photo does.
 */
$picture = static function ( string $path, int $width, int $height, string $format, array $options = array() ): string {
	$image = new Imagick();
	$image->setRegistry( 'temporary-path', get_temp_dir() );
	$image->newPseudoImage( $width, $height, 'plasma:fractal' );
	if ( ! empty( $options['alpha'] ) ) {
		$image->setImageAlphaChannel( Imagick::ALPHACHANNEL_SET );
		$image->evaluateImage( Imagick::EVALUATE_MULTIPLY, 0.5, Imagick::CHANNEL_ALPHA );
	}
	if ( ! empty( $options['cmyk'] ) ) {
		$image->transformImageColorspace( Imagick::COLORSPACE_CMYK );
	}
	$image->setImageFormat( $format );
	$image->setImageCompressionQuality( 95 );
	$image->writeImage( $path );
	$image->clear();
	return $path;
};

/*
 * EXIF as a phone writes it: Orientation 6 (turn 90 degrees clockwise) and a
 * GPS block with a latitude reference, inserted after the JPEG's SOI marker.
 */
$add_exif = static function ( string $path ): void {
	$tiff  = 'MM' . pack( 'n', 42 ) . pack( 'N', 8 );
	$tiff .= pack( 'n', 2 );
	$tiff .= pack( 'nnN', 0x0112, 3, 1 ) . pack( 'n', 6 ) . "\0\0"; // Orientation: 6.
	$tiff .= pack( 'nnNN', 0x8825, 4, 1, 38 ); // GPSInfo at offset 38.
	$tiff .= pack( 'N', 0 );
	$tiff .= pack( 'n', 1 ) . pack( 'nnN', 0x0001, 2, 2 ) . "N\0\0\0" . pack( 'N', 0 ); // GPSLatitudeRef: N.
	$app1  = "\xFF\xE1" . pack( 'n', 2 + 6 + strlen( $tiff ) ) . "Exif\0\0" . $tiff;
	$jpeg  = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	file_put_contents( $path, substr( $jpeg, 0, 2 ) . $app1 . substr( $jpeg, 2 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
};

$profiles = static function ( string $path ): array {
	$image = new Imagick( $path );
	$names = array_keys( $image->getImageProfiles( '*', false ) );
	$image->clear();
	return $names;
};

$sideload = static function ( string $source, string $name ) use ( &$attachments ) {
	$tmp = wp_tempnam( $name );
	copy( $source, $tmp );
	$id = media_handle_sideload(
		array(
			'name'     => $name,
			'tmp_name' => $tmp,
		),
		0
	);
	if ( is_file( $tmp ) ) {
		wp_delete_file( $tmp );
	}
	if ( ! is_wp_error( $id ) ) {
		$attachments[] = (int) $id;
	}
	return $id;
};

// 1. The plugin ------------------------------------------------------------------

echo "The plugin\n";
$check( 'a MU plugin (always on)', isset( get_mu_plugins()['mody-images.php'] ) && class_exists( Uploads::class ) );
$check( '...replaces uploads and sideloads, after the platform checks (10)', Uploads::PRIORITY === has_filter( 'wp_handle_upload_prefilter', array( Uploads::class, 'prefilter' ) ) && Uploads::PRIORITY === has_filter( 'wp_handle_sideload_prefilter', array( Uploads::class, 'prefilter' ) ) );
$platform = has_filter( 'wp_handle_upload_prefilter', array( 'Mody\Platform\Gating\ImageLimits', 'prefilter' ) );
$check( '...the platform\'s size check runs first', false !== $platform && $platform < Uploads::PRIORITY, var_export( $platform, true ) );
$check( 'client-side media processing off (one path for every upload)', ! wp_is_client_side_media_processing_enabled() && false === apply_filters( 'wp_client_side_media_processing_enabled', true ) );
$check( 'WebP sizes at SIZE_QUALITY; other types unchanged', Optimizer::SIZE_QUALITY === apply_filters( 'wp_editor_set_quality', 86, 'image/webp' ) && 90 === apply_filters( 'wp_editor_set_quality', 90, 'image/jpeg' ) );
$check( 'webp_name(): the name keeps its words, .webp', 'Holiday Photo.webp' === Uploads::webp_name( 'Holiday Photo.JPG' ) && 'image.webp' === Uploads::webp_name( '.jpg' ) );

// 2. The optimizer ----------------------------------------------------------------

echo "\nThe optimizer\n";
$photo = $picture( $work . '/photo.jpg', 3200, 2400, 'jpeg' );
$add_exif( $photo );
$exif = @exif_read_data( $photo ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
$check( 'the test photo: orientation 6, a GPS place, 3200 x 2400', 6 === (int) ( $exif['Orientation'] ?? 0 ) && 'N' === ( $exif['GPSLatitudeRef'] ?? '' ) );
$made = Optimizer::optimize( $photo, $work . '/photo.webp' );
$check( 'a phone photo becomes a WebP', is_array( $made ) && 'image/webp' === wp_get_image_mime( $work . '/photo.webp' ) );
$size = wp_getimagesize( $work . '/photo.webp' );
$check( '...upright and at most 2560 on a side (1920 x 2560)', 1920 === (int) $size[0] && 2560 === (int) $size[1], $size[0] . ' x ' . $size[1] );
$left = array_intersect( $profiles( $work . '/photo.webp' ), array( 'exif', 'xmp', 'iptc', '8bim' ) );
$check( '...without camera data (no EXIF, so no place)', ! $left, implode( ', ', $left ) );
$check( '...smaller', is_array( $made ) && $made['after'] < $made['before'], is_array( $made ) ? size_format( $made['before'], 1 ) . ' -> ' . size_format( $made['after'], 1 ) : '' );
$check( '...the photo itself unchanged', 6 === (int) ( @exif_read_data( $photo )['Orientation'] ?? 0 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

$png  = $picture( $work . '/logo.png', 900, 600, 'png', array( 'alpha' => true ) );
$made = Optimizer::optimize( $png, $work . '/logo.webp' );
$alpha = is_array( $made ) ? ( new Imagick( $work . '/logo.webp' ) )->getImageAlphaChannel() : false;
$check( 'a PNG becomes a WebP with its transparency', is_array( $made ) && (bool) $alpha, is_array( $made ) ? size_format( $made['before'], 1 ) . ' -> ' . size_format( $made['after'], 1 ) : var_export( $made, true ) );

$cmyk = $picture( $work . '/print.jpg', 800, 600, 'jpeg', array( 'cmyk' => true ) );
$made = Optimizer::optimize( $cmyk, $work . '/print.webp' );
$space = is_array( $made ) ? ( new Imagick( $work . '/print.webp' ) )->getImageColorspace() : -1;
$check( 'a CMYK JPEG becomes an sRGB WebP', is_array( $made ) && Imagick::COLORSPACE_SRGB === $space, (string) $space );

$small_webp = $picture( $work . '/small.webp', 1200, 800, 'webp' );
$check( 'a WebP within 2560 is left as it is', ! Optimizer::wants( $small_webp ) && null === Optimizer::optimize( $small_webp, $work . '/small-out.webp' ) && ! is_file( $work . '/small-out.webp' ) );
$big_webp = $picture( $work . '/big.webp', 3000, 2000, 'webp' );
$made     = Optimizer::optimize( $big_webp, $work . '/big-out.webp' );
$check( 'a WebP larger than 2560 is made smaller (2560 x 1707)', is_array( $made ) && 2560 === $made['width'] && 1707 === $made['height'] );

$gif = $picture( $work . '/anim.gif', 400, 300, 'gif' );
$check( 'GIF is never changed', ! Optimizer::wants( $gif ) );
$png_head = "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . str_repeat( "\0", 17 ) . pack( 'N', 8 ) . 'acTL' . str_repeat( "\0", 12 ) . pack( 'N', 0 ) . 'IDAT';
file_put_contents( $work . '/anim.png', $png_head ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$check( 'an APNG is animated (acTL before IDAT): never changed', Optimizer::animated( $work . '/anim.png', 'image/png' ) && ! Optimizer::animated( $png, 'image/png' ) );
file_put_contents( $work . '/anim.webp', 'RIFF' . pack( 'V', 30 ) . 'WEBPVP8X' . pack( 'V', 10 ) . "\x02" . str_repeat( "\0", 9 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$check( 'an animated WebP is animated: never changed', Optimizer::animated( $work . '/anim.webp', 'image/webp' ) && ! Optimizer::animated( $small_webp, 'image/webp' ) );

// 3. An upload ----------------------------------------------------------------------

echo "\nAn upload\n";
$id = $sideload( $photo, 'Holiday Photo.JPG' );
$check( 'uploaded', ! is_wp_error( $id ), is_wp_error( $id ) ? $id->get_error_message() : '' );
if ( ! is_wp_error( $id ) ) {
	$file = (string) get_attached_file( $id );
	$meta = wp_get_attachment_metadata( $id );
	$check( 'stored as a WebP, named after the upload', 'image/webp' === get_post_mime_type( $id ) && 'image/webp' === wp_get_image_mime( $file ) && str_ends_with( $file, '/Holiday-Photo.webp' ), wp_basename( $file ) );
	$check( '...upright, 1920 x 2560: no -scaled copy, no original kept', 1920 === (int) $meta['width'] && 2560 === (int) $meta['height'] && empty( $meta['original_image'] ) && ! str_contains( $file, '-scaled' ) );
	$sizes = (array) ( $meta['sizes'] ?? array() );
	$webp  = array_filter( $sizes, static fn( $s ) => 'image/webp' === $s['mime-type'] && is_file( dirname( $file ) . '/' . $s['file'] ) );
	$check( '...its sizes are WebP too', $sizes && count( $webp ) === count( $sizes ), count( $webp ) . ' of ' . count( $sizes ) );
	$check( '...without camera data', ! array_intersect( $profiles( $file ), array( 'exif', 'xmp', 'iptc' ) ) );
	$jpegs = preg_grep( '/\.jpe?g$/i', (array) glob( dirname( $file ) . '/Holiday-Photo*' ) ); // Alpine's libc has no GLOB_BRACE.
	$check( '...and no JPEG left in the folder', ! $jpegs, implode( ', ', array_map( 'wp_basename', (array) $jpegs ) ) );
}

// 4. The media library ---------------------------------------------------------------

echo "\nThe media library (wp mody-images optimize)\n";
$plain = $picture( $work . '/old.jpg', 3200, 2400, 'jpeg' );
remove_filter( 'wp_handle_sideload_prefilter', array( Uploads::class, 'prefilter' ), Uploads::PRIORITY );
$old = $sideload( $plain, 'old-photo.jpg' );
add_filter( 'wp_handle_sideload_prefilter', array( Uploads::class, 'prefilter' ), Uploads::PRIORITY );
$check( 'an older upload: a JPEG with a -scaled copy and JPEG sizes', ! is_wp_error( $old ) && 'image/jpeg' === get_post_mime_type( $old ) && ! empty( wp_get_attachment_metadata( $old )['original_image'] ) );
if ( ! is_wp_error( $old ) ) {
	$old_file  = (string) get_attached_file( $old );
	$old_meta  = wp_get_attachment_metadata( $old );
	$dir       = dirname( $old_file );
	$old_files = array_merge( array( $old_file, $dir . '/' . $old_meta['original_image'] ), array_map( static fn( $s ) => $dir . '/' . $s['file'], $old_meta['sizes'] ) );
	$full_url  = (string) wp_get_attachment_url( $old );
	$medium    = (string) wp_get_attachment_image_url( $old, 'medium' );
	$original  = (string) wp_get_original_image_url( $old );
	$escaped   = str_replace( '/', '\\/', (string) wp_get_attachment_image_url( $old, 'large' ) );
	$post      = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Mody Images test',
			'post_content' => wp_slash( '<!-- wp:image {"id":' . $old . ',"sizeSlug":"medium"} --><figure class="wp-block-image size-medium"><img src="' . $medium . '" class="wp-image-' . $old . '"/></figure><!-- /wp:image -->'
				. '<p><a href="' . $full_url . '">full</a> <a href="' . $original . '">original</a> {"url":"' . $escaped . '"} https://elsewhere.test/old-photo.jpg</p>' ), // wp_insert_post() unslashes: keep the escaped JSON.
		)
	);
	$posts[] = (int) $post;
	$check( 'listed by candidates()', in_array( (int) $old, Library::candidates(), true ) );

	$done = Library::convert( (int) $old );
	$check( 'converted', is_array( $done ), is_array( $done ) ? $done['from'] . ' -> ' . $done['to'] . ', ' . size_format( $done['before'], 1 ) . ' -> ' . size_format( $done['after'], 1 ) : var_export( $done, true ) );
	$new_file = (string) get_attached_file( $old );
	$new_meta = wp_get_attachment_metadata( $old );
	$check( '...the attachment is the WebP (same ID), 2560 x 1920, no -scaled', 'image/webp' === get_post_mime_type( $old ) && 'old-photo.webp' === wp_basename( $new_file ) && 2560 === (int) $new_meta['width'] && empty( $new_meta['original_image'] ) );
	$sizes = (array) ( $new_meta['sizes'] ?? array() );
	$check( '...its sizes made again, as WebP', $sizes && ! array_filter( $sizes, static fn( $s ) => 'image/webp' !== $s['mime-type'] || ! is_file( $dir . '/' . $s['file'] ) ) );
	$gone = array_filter( $old_files, 'is_file' );
	$check( '...the old files are gone (original, -scaled, sizes)', ! $gone, implode( ', ', array_map( 'wp_basename', $gone ) ) );
	$content = (string) get_post_field( 'post_content', $post );
	$check( '...the content: the medium size points at the WebP medium size', str_contains( $content, (string) wp_get_attachment_image_url( $old, 'medium' ) ) );
	$check( '...the -scaled and original addresses point at the WebP', 2 === substr_count( $content, '/old-photo.webp"' ) );
	$check( '...escaped addresses (JSON) follow', str_contains( $content, str_replace( '/', '\\/', (string) wp_get_attachment_image_url( $old, 'large' ) ) ) );
	$check( '...another site\'s address with the same name is untouched', str_contains( $content, 'https://elsewhere.test/old-photo.jpg' ) );
	$check( '...no old file address left', ! preg_match( '#/old-photo[^"\s]*\.jpg#', str_replace( 'https://elsewhere.test/old-photo.jpg', '', $content ) ) );
	$check( '...srcset works on the new files', '' !== (string) wp_get_attachment_image_srcset( $old, 'large' ) );
	$check( 'not listed again', ! in_array( (int) $old, Library::candidates(), true ) && null === Library::convert( (int) $old ) );
}

// 5. Take your site with you -----------------------------------------------------------

echo "\nTake your site with you\n";
$check( 'the downloaded site carries Mody Images, active', 'mody-images.php' === ( Portable::PLUGINS['mody-images'] ?? '' ) && in_array( 'mody-images/mody-images.php', Portable::active_plugins(), true ) );

echo "\n" . ( $failures ? "$failures check(s) failed.\n" : "All checks passed.\n" );
