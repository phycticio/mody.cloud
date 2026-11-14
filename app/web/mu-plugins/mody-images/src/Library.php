<?php
/**
 * Converts the pictures already in a site's media library.
 *
 * @package Mody\Images
 */

namespace Mody\Images;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * For pictures uploaded before Mody Images (`wp mody-images optimize`,
 * Command). Each one, on the current site:
 *
 * 1. Its WebP is made from the uploaded original (the file before
 *    WordPress's `-scaled` copy, when there is one), as Optimizer makes every
 *    upload, next to it under a free name: `photo.jpg` becomes `photo.webp`.
 * 2. The attachment points at the WebP, and WordPress makes its sizes again
 *    (WebP too). Its ID, title, alt text and every place that uses it by ID
 *    (featured images, the site logo and icon, image blocks) do not change.
 * 3. The site's content follows: in every post, page, template, template
 *    part, pattern and the global styles (revisions too), the addresses of
 *    the old file and of each of its sizes become the address of the WebP's
 *    file of the same size. Spectra's cached CSS for those posts is
 *    dropped, so it is made again with the new addresses.
 * 4. The old files are deleted: the original, the `-scaled` copy, the sizes,
 *    and the backups of edits made in WordPress's image editor.
 *
 * Addresses are matched by their path from the uploads folder on
 * (`/app/uploads/sites/3/2026/11/photo-300x200.jpg`), whatever the host,
 * so links written on the network's address and on a custom domain both
 * follow. Post meta is not changed (serialized values cannot be edited as
 * text); WordPress and Spectra keep images there by ID.
 *
 * This cannot be undone: the old files are gone.
 */
final class Library {

	/** The types a picture may have to be worth looking at. */
	private const MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/heic', 'image/heif', 'image/webp', 'image/avif' );

	/** Spectra's per-post caches that hold addresses (its own list when it drops page assets). */
	private const SPECTRA_CACHE_META = array( '_spectra_blocks_page_assets', '_spectra_blocks_css_file_name', '_spectra_blocks_js_file_name', '_spectra_css_cache', '_spectra_css_regenerated' );

	/**
	 * The current site's attachments that would be converted, by ID.
	 *
	 * @param int $limit At most this many; 0 for all.
	 * @return int[]
	 */
	public static function candidates( int $limit = 0 ): array {
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( self::MIME_TYPES ), '%s' ) );
		$ids          = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ($placeholders) ORDER BY ID", self::MIME_TYPES ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders only.
		);
		$found = array();
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			if ( Optimizer::wants( self::source( $id ) ) ) {
				$found[] = $id;
				if ( $limit > 0 && count( $found ) >= $limit ) {
					break;
				}
			}
		}
		return $found;
	}

	/**
	 * The file an attachment's WebP is made from: the uploaded original when
	 * WordPress made a `-scaled` copy, the attached file otherwise.
	 *
	 * @param int $id The attachment.
	 */
	public static function source( int $id ): string {
		$original = wp_get_original_image_path( $id );
		if ( is_string( $original ) && is_file( $original ) ) {
			return $original;
		}
		$file = get_attached_file( $id );
		return is_string( $file ) ? $file : '';
	}

	/**
	 * Converts one attachment.
	 *
	 * @param int $id The attachment.
	 * @return array{id: int, from: string, to: string, before: int, after: int, posts: int}|null|WP_Error
	 *         What changed; null when there is nothing to do; WP_Error when
	 *         the picture could not be converted (then nothing changed).
	 */
	public static function convert( int $id ) {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$source = self::source( $id );
		$file   = get_attached_file( $id );
		if ( ! is_string( $file ) || '' === $file || ! Optimizer::wants( $source ) ) {
			return null;
		}
		$dir    = dirname( $file );
		$meta   = wp_get_attachment_metadata( $id );
		$meta   = is_array( $meta ) ? $meta : array();
		$backup = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
		$backup = is_array( $backup ) ? $backup : array();

		// The old files: their names in the attachment's folder, by size.
		$old_sizes = array();
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $name => $size ) {
			if ( ! empty( $size['file'] ) ) {
				$old_sizes[ (string) $name ] = wp_basename( (string) $size['file'] );
			}
		}
		$old_names = array_merge( array( wp_basename( $file ), wp_basename( $source ) ), array_values( $old_sizes ) );
		foreach ( $backup as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$old_names[] = wp_basename( (string) $size['file'] );
			}
		}
		$old_names = array_values( array_unique( $old_names ) );
		$before    = 0;
		foreach ( $old_names as $name ) {
			$before += is_file( $dir . '/' . $name ) ? (int) filesize( $dir . '/' . $name ) : 0;
		}

		// 1. The WebP.
		$name = wp_unique_filename( $dir, pathinfo( $source, PATHINFO_FILENAME ) . '.webp' );
		$made = Optimizer::optimize( $source, $dir . '/' . $name );
		if ( ! is_array( $made ) ) {
			return $made;
		}

		// 2. The attachment, and its sizes.
		update_attached_file( $id, $made['path'] );
		$wpdb->update( $wpdb->posts, array( 'post_mime_type' => 'image/webp' ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $id );
		$new_meta = wp_generate_attachment_metadata( $id, $made['path'] );
		wp_update_attachment_metadata( $id, $new_meta );
		delete_post_meta( $id, '_wp_attachment_backup_sizes' );

		$new_sizes = array();
		foreach ( (array) ( $new_meta['sizes'] ?? array() ) as $size_name => $size ) {
			if ( ! empty( $size['file'] ) ) {
				$new_sizes[ (string) $size_name ] = wp_basename( (string) $size['file'] );
			}
		}
		$new_names = array_merge( array( $name ), array_values( $new_sizes ) );
		$after     = 0;
		foreach ( array_unique( $new_names ) as $new_name ) {
			$after += is_file( $dir . '/' . $new_name ) ? (int) filesize( $dir . '/' . $new_name ) : 0;
		}

		// 3. The content: each old file's address becomes the WebP's file of the same size, or the WebP itself.
		$map = array();
		foreach ( $old_names as $old_name ) {
			$size_name        = array_search( $old_name, $old_sizes, true );
			$map[ $old_name ] = ( false !== $size_name && isset( $new_sizes[ $size_name ] ) ) ? $new_sizes[ $size_name ] : $name;
		}
		$posts = self::replace_in_content( self::folder_path( $file ), $map );

		// 4. The old files.
		foreach ( array_diff( $old_names, $new_names ) as $old_name ) {
			if ( is_file( $dir . '/' . $old_name ) ) {
				wp_delete_file( $dir . '/' . $old_name );
			}
		}

		return array(
			'id'     => $id,
			'from'   => wp_basename( $source ),
			'to'     => $name,
			'before' => $before,
			'after'  => $after,
			'posts'  => $posts,
		);
	}

	/**
	 * The path, from the host on, of the folder a file is in:
	 * `/app/uploads/sites/3/2026/11`.
	 *
	 * @param string $file The file.
	 */
	private static function folder_path( string $file ): string {
		$uploads = wp_get_upload_dir();
		$rel     = ltrim( substr( dirname( $file ), strlen( untrailingslashit( (string) $uploads['basedir'] ) ) ), '/' );
		$base    = untrailingslashit( (string) wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH ) );
		return $base . ( '' === $rel ? '' : '/' . $rel );
	}

	/**
	 * Replaces the addresses of files in the folder, in every post of the
	 * current site.
	 *
	 * @param string                $folder The folder's path from the host on.
	 * @param array<string, string> $map    Old file name => new file name.
	 * @return int The posts changed.
	 */
	private static function replace_in_content( string $folder, array $map ): int {
		global $wpdb;
		$pairs = array();
		foreach ( $map as $old => $new ) {
			if ( $old === $new ) {
				continue;
			}
			$pairs[ $folder . '/' . $old ] = $folder . '/' . $new;
			// The same address in JSON with escaped slashes (the global styles).
			$pairs[ str_replace( '/', '\\/', $folder . '/' . $old ) ] = str_replace( '/', '\\/', $folder . '/' . $new );
		}
		if ( ! $pairs ) {
			return 0;
		}

		// Posts that mention any old file name; strtr() then changes only whole addresses.
		$likes = array();
		$args  = array();
		foreach ( array_keys( $map ) as $old ) {
			$likes[] = 'post_content LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $old ) . '%';
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type <> 'attachment' AND (" . implode( ' OR ', $likes ) . ')', $args ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders only.
		);

		$changed = 0;
		foreach ( (array) $rows as $row ) {
			$content = strtr( (string) $row->post_content, $pairs );
			if ( $content === $row->post_content ) {
				continue;
			}
			$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => (int) $row->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( self::SPECTRA_CACHE_META as $key ) {
				delete_post_meta( (int) $row->ID, $key );
			}
			clean_post_cache( (int) $row->ID );
			++$changed;
		}
		if ( $changed && function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache(); // The global styles may be one of them.
		}
		return $changed;
	}
}
