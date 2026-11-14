<?php
/**
 * `wp mody-images`: the pictures already in media libraries.
 *
 * @package Mody\Images
 */

namespace Mody\Images;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Makes the pictures uploaded before Mody Images web efficient, as every
 * upload is now (Library).
 */
final class Command {

	/**
	 * Converts a site's older pictures to web-efficient WebP.
	 *
	 * Each JPEG, PNG and HEIC (and each WebP or AVIF larger than 2560 pixels)
	 * becomes a WebP: upright, at most 2560 pixels on a side, without camera
	 * data. Its sizes are made again, the site's content is pointed at the new
	 * files, and the old files are deleted. This cannot be undone: back up the
	 * uploads folder and the database first.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Every site of the network, instead of the one in --url.
	 *
	 * [--dry-run]
	 * : List the pictures that would be converted; change nothing.
	 *
	 * [--limit=<number>]
	 * : At most this many pictures on each site.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mody-images optimize --url=demo.mody.local --dry-run
	 *     wp mody-images optimize --url=demo.mody.local
	 *     wp mody-images optimize --network --yes
	 *
	 * @param string[]             $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function optimize( array $args, array $assoc_args ): void {
		$dry   = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$limit = max( 0, (int) ( $assoc_args['limit'] ?? 0 ) );
		$sites = is_multisite() && WP_CLI\Utils\get_flag_value( $assoc_args, 'network', false )
			? array_map(
				'intval',
				get_sites(
					array(
						'fields' => 'ids',
						'number' => 0,
					)
				)
			)
			: array( get_current_blog_id() );

		if ( ! $dry ) {
			WP_CLI::confirm(
				sprintf( 'Convert the pictures of %d site(s) to WebP and delete their old files? This cannot be undone.', count( $sites ) ),
				$assoc_args
			);
		}

		$total = array(
			'pictures' => 0,
			'before'   => 0,
			'after'    => 0,
			'failed'   => 0,
		);
		foreach ( $sites as $site_id ) {
			$switched = is_multisite() && get_current_blog_id() !== $site_id;
			if ( $switched ) {
				switch_to_blog( $site_id );
			}
			$this->site( $dry, $limit, $total );
			if ( $switched ) {
				restore_current_blog();
			}
		}

		if ( $dry ) {
			WP_CLI::success( sprintf( '%d picture(s) would be converted (%s now).', $total['pictures'], size_format( $total['before'], 1 ) ) );
			return;
		}
		$message = sprintf(
			'%d picture(s) converted: %s became %s.',
			$total['pictures'],
			size_format( $total['before'], 1 ),
			size_format( $total['after'], 1 )
		);
		if ( $total['failed'] ) {
			WP_CLI::warning( $message . sprintf( ' %d could not be converted and were left as they were.', $total['failed'] ) );
			return;
		}
		WP_CLI::success( $message );
	}

	/**
	 * One site: the current one.
	 *
	 * @param bool                                                       $dry   List only.
	 * @param int                                                        $limit At most this many; 0 for all.
	 * @param array{pictures: int, before: int, after: int, failed: int} $total Running totals.
	 */
	private function site( bool $dry, int $limit, array &$total ): void {
		$ids = Library::candidates( $limit );
		WP_CLI::log( sprintf( '%s: %d picture(s) to convert.', home_url(), count( $ids ) ) );
		foreach ( $ids as $id ) {
			$source = Library::source( $id );
			if ( $dry ) {
				$size = (int) filesize( $source );
				WP_CLI::log( sprintf( '  #%d %s (%s)', $id, wp_basename( $source ), size_format( $size, 1 ) ) );
				++$total['pictures'];
				$total['before'] += $size;
				continue;
			}
			$done = Library::convert( $id );
			if ( is_wp_error( $done ) ) {
				WP_CLI::warning( sprintf( '  #%d %s: %s', $id, wp_basename( $source ), $done->get_error_message() ) );
				++$total['failed'];
				continue;
			}
			if ( null === $done ) {
				WP_CLI::log( sprintf( '  #%d %s: left as it is (the WebP would not be smaller).', $id, wp_basename( $source ) ) );
				continue;
			}
			WP_CLI::log(
				sprintf(
					'  #%d %s -> %s: %s -> %s, %d post(s) updated',
					$id,
					$done['from'],
					$done['to'],
					size_format( $done['before'], 1 ),
					size_format( $done['after'], 1 ),
					$done['posts']
				)
			);
			++$total['pictures'];
			$total['before'] += $done['before'];
			$total['after']  += $done['after'];
		}
	}
}
