<?php
/**
 * Builds a site's three download files.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Export;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The builder (tickets/take-your-site-with-you.md). Runs in a detached
 * `wp mody-export run <job>` process (Runner), never in a web request.
 *
 * The contract every part follows:
 *
 * - `DatabaseDump::write( int $site_id, int $owner_id, string $path, array $manifest, callable $progress )`
 *   writes `database.sql.gz`.
 * - `Archives::media( int $site_id, string $path, array $manifest, callable $progress )` writes
 *   `media.zip`.
 * - `Archives::code( string $path, array $manifest, callable $progress )` writes `code.zip`, the
 *   Mody Import plugin with the payload (Portable).
 *
 * Each returns true or a WP_Error, writes to `<path>.part` and renames it
 * when complete, and calls `$progress( int $done, int $total )` now and then
 * (it is the job's heartbeat: at least once a minute). `$manifest` is
 * manifest(): every file carries it as `mody-export.json` (the database as
 * its first line), so the importer recognizes a file by its content.
 */
final class Exporter {

	/** The manifest's format; the importer refuses other majors. */
	public const FORMAT = '1.0';

	/** The files in a job's folder, by step. */
	public const PATHS = array(
		'database' => 'database.sql.gz',
		'media'    => 'media.zip',
		'code'     => 'code.zip',
	);

	/**
	 * Builds a job's files, from the step it is on. Claims it first, so a
	 * second process exits at once.
	 *
	 * @param int $job_id Job.
	 * @return true|WP_Error
	 */
	public static function run( int $job_id ) {
		if ( ! Jobs::claim( $job_id ) ) {
			return new WP_Error( 'mody_export_claimed', 'The job is not waiting, or another builder has it.' );
		}
		$job = Jobs::get( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'mody_export_missing', 'No such job.' );
		}
		$site = get_site( $job['site_id'] );
		if ( ! $site || is_main_site( $job['site_id'] ) ) {
			Jobs::fail( $job_id, 'Not a member site.' );
			return new WP_Error( 'mody_export_site', 'Not a member site.' );
		}
		$dir = Jobs::dir( $job );
		if ( ! wp_mkdir_p( $dir ) && ! is_dir( $dir ) ) { // Another site's first build may create exports/ at the same moment.
			Jobs::fail( $job_id, 'Could not create the job folder.' );
			return new WP_Error( 'mody_export_dir', 'Could not create ' . $dir );
		}

		// A restart begins clean: half-written files of the step it resumes.
		foreach ( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) as $name ) {
			if ( str_contains( $name, '.part' ) ) {
				wp_delete_file( $dir . '/' . $name );
			}
		}

		switch_to_blog( $job['site_id'] );
		try {
			$manifest = self::manifest( $job );
			$start    = array_search( $job['step'], Jobs::STEPS, true );
			foreach ( array_slice( Jobs::FILES, false === $start ? 0 : (int) $start ) as $step ) {
				$path     = $dir . '/' . self::PATHS[ $step ];
				$progress = static function ( int $done, int $total ) use ( $job_id, $step ): void {
					Jobs::progress( $job_id, $step, $done, $total );
				};
				$progress( 0, 0 );
				$done = match ( $step ) {
					'database' => DatabaseDump::write( $job['site_id'], $job['user_id'], $path, $manifest, $progress ),
					'media'    => Archives::media( $job['site_id'], $path, $manifest, $progress ),
					'code'     => Archives::code( $path, $manifest, $progress ),
				};
				if ( is_wp_error( $done ) ) {
					Jobs::fail( $job_id, $step . ': ' . $done->get_error_message() );
					return $done;
				}
				$file = self::verify( $path );
				if ( is_wp_error( $file ) ) {
					Jobs::fail( $job_id, $step . ': ' . $file->get_error_message() );
					return $file;
				}
				if ( 'database' === $step ) {
					// The credential-like settings left out, so the owner can be told.
					$read = DatabaseDump::read_manifest( $path );
					if ( is_array( $read ) && ! empty( $read['database']['stripped'] ) ) {
						$file['stripped'] = array_values( (array) $read['database']['stripped'] );
					}
				}
				Jobs::add_file( $job_id, $step, array( 'name' => Jobs::file_name( $job, $step ) ) + $file );
			}
			Jobs::finish( $job_id );
			self::retire_older( $job );
			Notices::ready( $job_id );
		} catch ( \Throwable $e ) {
			Jobs::fail( $job_id, get_class( $e ) . ': ' . $e->getMessage() );
			return new WP_Error( 'mody_export_error', $e->getMessage() );
		} finally {
			restore_current_blog();
			// This build's slot is free: start the next one waiting.
			if ( class_exists( Runner::class ) ) {
				Runner::watch();
			}
		}
		return true;
	}

	/**
	 * A site keeps one finished copy: the older ones' files go when a new one
	 * is ready.
	 *
	 * @param array $job The job that just finished.
	 */
	private static function retire_older( array $job ): void {
		global $wpdb;
		$table = Jobs::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$older = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE site_id = %d AND id < %d AND status IN ('done','failed')", $job['site_id'], $job['id'] ) );
		foreach ( array_map( 'intval', $older ) as $id ) {
			$old = Jobs::get( $id );
			if ( $old ) {
				self::remove( Jobs::dir( $old ) );
				Jobs::expire( $id );
			}
		}
	}

	/**
	 * What every file says about where it comes from, for the importer. Called
	 * in the site's context (switch_to_blog).
	 *
	 * @param array $job A job.
	 * @return array<string, mixed>
	 */
	public static function manifest( array $job ): array {
		global $wpdb;
		$site    = get_site( $job['site_id'] );
		$uploads = wp_get_upload_dir();
		$home    = (string) get_option( 'home' );
		$hosts   = array_values(
			array_unique(
				array_filter(
					array(
						(string) wp_parse_url( $home, PHP_URL_HOST ),
						$site ? (string) $site->domain : '',
						defined( 'DOMAIN_CURRENT_SITE' ) ? (string) DOMAIN_CURRENT_SITE : '',
					)
				)
			)
		);
		return array(
			'format'      => self::FORMAT,
			'generator'   => 'Mody Cloud',
			'export_id'   => $job['token'],
			'created_at'  => gmdate( 'c', strtotime( $job['created_at'] . ' UTC' ) ?: time() ),
			'site'        => array(
				'name'    => (string) get_option( 'blogname' ),
				'home'    => $home,
				'siteurl' => (string) get_option( 'siteurl' ),
				'hosts'   => $hosts,
				// Content stores uploads as <host>/web/uploads/sites/<N>/…, under any of the hosts.
				'uploads' => array(
					'path' => (string) wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH ),
					'url'  => (string) $uploads['baseurl'],
				),
				'content' => (string) wp_parse_url( content_url(), PHP_URL_PATH ),
				'locale'  => (string) get_locale(),
			),
			'source'      => array(
				'prefix'     => $wpdb->get_blog_prefix( $job['site_id'] ),
				'wp_version' => (string) get_bloginfo( 'version' ),
				'db_version' => (int) get_option( 'db_version' ),
				'php'        => PHP_VERSION,
			),
			'requires'    => array(
				'wp'  => implode( '.', array_slice( explode( '.', (string) get_bloginfo( 'version' ) ), 0, 2 ) ),
				'php' => '8.1',
			),
			'theme'       => Portable::THEME,
			'plugins'     => Portable::active_plugins(),
			'owner'       => (int) $job['user_id'],
		);
	}

	/**
	 * A finished file, read back: its size and checksum. The production disk
	 * sits behind a network mount, so the size is checked after a fresh stat.
	 *
	 * @param string $path The file.
	 * @return array{size: int, sha256: string}|WP_Error
	 */
	private static function verify( string $path ) {
		clearstatcache( true, $path );
		$size = is_file( $path ) ? (int) filesize( $path ) : 0;
		$hash = $size ? hash_file( 'sha256', $path ) : false;
		if ( ! $size || ! $hash ) {
			return new WP_Error( 'mody_export_file', 'The file is missing or empty: ' . basename( $path ) );
		}
		return array(
			'size'   => $size,
			'sha256' => $hash,
		);
	}

	/**
	 * Deletes expired files and their jobs, crash leftovers too. Called from
	 * the dashboard's status poll and `wp mody-export purge`, at most hourly.
	 *
	 * @param bool $force Run even if it ran within the hour.
	 * @return int Jobs expired.
	 */
	public static function sweep( bool $force = false ): int {
		if ( ! $force && (int) get_site_option( 'mody_export_swept', 0 ) > time() - HOUR_IN_SECONDS ) {
			return 0;
		}
		update_site_option( 'mody_export_swept', time() );
		try {
			return self::sweep_now();
		} catch ( \Throwable $e ) {
			wp_trigger_error( __METHOD__, $e->getMessage() );
			return 0;
		}
	}

	/**
	 * The sweep itself.
	 *
	 * @return int Jobs expired.
	 */
	private static function sweep_now(): int {
		$count = 0;
		foreach ( Jobs::expired() as $job ) {
			self::remove( Jobs::dir( $job ) );
			Jobs::expire( $job['id'] );
			++$count;
		}
		// Folders no live job owns: a deleted site, a crash between create and claim.
		global $wpdb;
		$table = Jobs::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$live = array_flip( (array) $wpdb->get_col( "SELECT token FROM {$table} WHERE status <> 'expired'" ) );
		foreach ( (array) glob( Jobs::root() . '/*/*', GLOB_ONLYDIR ) as $dir ) {
			if ( ! isset( $live[ basename( (string) $dir ) ] ) && filemtime( (string) $dir ) < time() - HOUR_IN_SECONDS ) {
				self::remove( (string) $dir );
			}
		}
		return $count;
	}

	/**
	 * Deletes a job folder and what is in it (one level: the three files,
	 * their .part files and libzip's temporary files).
	 *
	 * @param string $dir The folder.
	 */
	private static function remove( string $dir ): void {
		if ( ! is_dir( $dir ) || ! str_starts_with( $dir, Jobs::root() . '/' ) ) {
			return;
		}
		// scandir, not glob( GLOB_BRACE ): that flag does not exist on Alpine (musl).
		foreach ( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) as $name ) {
			$file = $dir . '/' . $name;
			if ( is_file( $file ) || is_link( $file ) ) {
				wp_delete_file( $file );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Own folder, now empty.
	}
}
