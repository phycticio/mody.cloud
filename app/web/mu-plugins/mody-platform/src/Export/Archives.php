<?php
/**
 * The media and code files a downloaded site takes with it.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Export;

use WP_Error;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Writes `media.zip` and `code.zip` for Exporter (tickets/take-your-site-with-you.md);
 * the contract is in Exporter's docblock: each returns true or a WP_Error,
 * writes `<path>.part` and renames it when complete, and calls
 * `$progress( $done, $total )` as the zip is written (the job's heartbeat).
 *
 * - **media.zip:** the site's uploads folder at the zip root, so it unzips
 *   straight into `wp-content/uploads`, plus `mody-export.json` (the
 *   manifest). Stored, not deflated, except a short list of text types:
 *   media is already compressed, and deflating 1 GB costs minutes of CPU
 *   for nothing. Never shipped from uploads: symlinks, hidden files
 *   (`.htaccess`, `.user.ini`), PHP and other executable code, `.part`
 *   files, and core's personal-data exports. Every such entry is counted
 *   with its reason; when there are any, the zip also carries
 *   `mody-export-skipped.txt` (the count and the first names), and the
 *   code file's README says how many. Files that vanish while the zip is
 *   made are left out without a mention: they are no longer in the library.
 * - **code.zip:** an installable plugin, `mody-import/` (export-kit/mody-import/),
 *   holding README-restore.txt, the manifest and `payload/`, the theme and
 *   plugins Portable assembles. The payload is the same for every site at a
 *   given deploy, so it is built once into a shared cache folder (a hash of
 *   the source files' paths, sizes and times), and each export zips it.
 *   Nothing in it is meant for the web: the kit's `.htaccess` files and
 *   `index.php` silence it, and the README's last step deletes the plugin.
 * - **The manifest in each file** carries `file`: `media` or `code` (the
 *   database file's says `database`), so the importer tells a file by what
 *   it says, not by its name or place.
 *
 * Seams for tests: the filter `mody_export_free_space` (the free bytes on
 * the target disk), the filter `mody_export_beat_every` (seconds between
 * heartbeats) and the action `mody_export_zip_entry_added` (a file was
 * queued; the argument is its path).
 */
final class Archives {

	/** The manifest's file name, at the root of every file. */
	public const MANIFEST_FILE = 'mody-export.json';

	/** The importer plugin's folder: the top folder of code.zip. */
	public const PLUGIN_FOLDER = 'mody-import';

	/** The list of what media.zip leaves out, at its root. */
	public const SKIPPED_FILE = 'mody-export-skipped.txt';

	/** The shared payload cache, under Jobs::root(). A dot name, so Exporter::sweep() never matches it. */
	public const CACHE_FOLDER = '.code-cache';

	/** Bump when the cache's layout changes, so old folders are not reused. */
	private const CACHE_VERSION = 1;

	/** Free space needed on top of 1.1 x the data. */
	private const SPARE_BYTES = 200 * MB_IN_BYTES;

	/** How much more than the data the disk must have free. */
	private const SPARE_FACTOR = 1.1;

	/** An unused cache folder older than this is deleted. */
	private const CACHE_KEEP = HOUR_IN_SECONDS;

	/** Seconds between heartbeats while files are listed and queued. */
	private const BEAT_EVERY = 15;

	/** How many left-out names media.zip lists. */
	private const LIST_MAX = 200;

	/** media.zip deflates only these (text); everything else is stored. */
	private const TEXT = array( 'txt', 'css', 'js', 'json', 'svg', 'xml', 'html', 'htm', 'csv', 'md' );

	/** code.zip stores only what is already compressed; the code and text in it are deflated. */
	private const COMPRESSED = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'webm', 'mov', 'mp3', 'zip', 'gz', 'woff', 'woff2', 'pdf' );

	/** Never copied into the code file, at any depth (the same as Portable). */
	private const SKIP = array( '.git', '.github', 'node_modules', '.DS_Store', '.gitignore', 'tests' );

	/**
	 * Writes media.zip: the site's uploads and the manifest.
	 *
	 * @param int      $site_id  A member site.
	 * @param string   $path     Where the zip goes; written to "$path.part" first.
	 * @param array    $manifest Exporter::manifest().
	 * @param callable $progress `function ( int $done, int $total ): void`, in bytes.
	 * @return true|WP_Error
	 */
	public static function media( int $site_id, string $path, array $manifest, callable $progress ) {
		// The main site's uploads folder holds every member's files (uploads/sites/<N>).
		if ( ! get_site( $site_id ) || is_main_site( $site_id ) ) {
			return new WP_Error( 'mody_export_site', 'Only a member site can be downloaded.' );
		}
		$json = self::json( $manifest, 'media' );
		if ( '' === $json ) {
			return new WP_Error( 'mody_export_manifest', 'The manifest could not be written as JSON.' );
		}

		switch_to_blog( $site_id );
		try {
			$base = untrailingslashit( (string) wp_get_upload_dir()['basedir'] );
		} finally {
			restore_current_blog();
		}

		if ( '' === $base ) {
			return new WP_Error( 'mody_export_uploads', 'The site\'s uploads folder is not set.' );
		}

		$attempts = 0;
		do {
			// A second pass when a file vanished after it was queued: the zip is written again from a fresh list.
			$listing = self::uploads( $base, $progress );
			$files   = $listing['files'];
			$progress( 0, 0 ); // The listing can take minutes on a big library: the heartbeat goes on.
			$disk = self::check_disk( $path, (int) array_sum( array_column( $files, 'size' ) ) );
			if ( is_wp_error( $disk ) ) {
				return $disk;
			}
			$strings = array( self::MANIFEST_FILE => $json );
			$left    = self::skipped_text( $listing );
			if ( '' !== $left ) {
				$strings[ self::SKIPPED_FILE ] = $left;
			}
			$done = self::zip( $path, $files, $strings, $progress, 'media' );
			++$attempts;
		} while ( is_wp_error( $done ) && 'mody_export_zip_vanished' === $done->get_error_code() && $attempts < 2 );
		return $done;
	}

	/**
	 * Writes code.zip: the Mody Import plugin with its payload.
	 *
	 * @param string   $path     Where the zip goes; written to "$path.part" first.
	 * @param array    $manifest Exporter::manifest().
	 * @param callable $progress `function ( int $done, int $total ): void`, in bytes.
	 * @return true|WP_Error
	 */
	public static function code( string $path, array $manifest, callable $progress ) {
		$kit = self::kit();
		if ( ! is_file( $kit . '/mody-import.php' ) ) {
			return new WP_Error( 'mody_export_kit', 'The importer plugin is missing from the platform.' );
		}
		$json = self::json( $manifest, 'code' );
		if ( '' === $json ) {
			return new WP_Error( 'mody_export_manifest', 'The manifest could not be written as JSON.' );
		}
		$disk = self::check_disk( $path, 0 );
		if ( is_wp_error( $disk ) ) {
			return $disk;
		}
		$payload = self::payload( $progress );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// The importer plugin, then the payload; the generated files win over any of the same name in the kit.
		$per_export = array(
			self::MANIFEST_FILE  => $json,
			'README-restore.txt' => self::readme( $manifest, $payload['info'], self::media_left_out( $path ) ),
		);
		$files      = array();
		foreach ( self::tree( $kit ) as $file ) {
			if ( ! isset( $per_export[ $file['name'] ] ) ) {
				$files[] = array( 'name' => self::PLUGIN_FOLDER . '/' . $file['name'] ) + $file;
			}
		}
		$licence = dirname( __DIR__, 2 ) . '/export-kit/LICENSE-GPL-2.0.txt';
		if ( ! is_file( $kit . '/LICENSE.txt' ) && is_readable( $licence ) ) {
			$files[] = array(
				'abs'  => $licence,
				'name' => self::PLUGIN_FOLDER . '/LICENSE.txt',
				'size' => (int) filesize( $licence ),
			);
		}
		foreach ( self::tree( $payload['dir'] . '/payload' ) as $file ) {
			$files[] = array( 'name' => self::PLUGIN_FOLDER . '/payload/' . $file['name'] ) + $file;
		}
		$strings = array();
		foreach ( $per_export as $name => $contents ) {
			$strings[ self::PLUGIN_FOLDER . '/' . $name ] = $contents;
		}
		return self::zip( $path, $files, $strings, $progress, 'code' );
	}

	/**
	 * The shared payload cache's folder.
	 */
	public static function cache_root(): string {
		return Jobs::root() . '/' . self::CACHE_FOLDER;
	}

	/**
	 * The importer plugin's source: export-kit/mody-import/.
	 */
	private static function kit(): string {
		return dirname( __DIR__, 2 ) . '/export-kit/' . self::PLUGIN_FOLDER;
	}

	/**
	 * The manifest as a file carries it: Exporter::manifest() and which of the
	 * three files this copy is (the database file's own says `database`).
	 *
	 * @param array  $manifest Exporter::manifest().
	 * @param string $file     `media` or `code`.
	 * @return string Empty when it cannot be encoded.
	 */
	private static function json( array $manifest, string $file ): string {
		$manifest['file'] = $file;
		$json             = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json . "\n" : '';
	}

	/**
	 * Writes a zip: the files, and strings as entries of their own.
	 *
	 * @param string                                                  $path     The final path.
	 * @param array<int, array{abs: string, name: string, size: int}> $files    Files to add.
	 * @param array<string, string>                                   $strings  Entry name => contents.
	 * @param callable                                                $progress The heartbeat.
	 * @param string                                                  $kind     `media` or `code`: how entries are compressed.
	 * @return true|WP_Error A 'mody_export_zip_vanished' error means a file went away and a second try may work.
	 */
	private static function zip( string $path, array $files, array $strings, callable $progress, string $kind ) {
		$part = $path . '.part';
		self::clear_part( $part );
		$total = (int) array_sum( array_column( $files, 'size' ) );
		$flags = ZipArchive::FL_OVERWRITE | ZipArchive::FL_ENC_UTF_8;

		$zip    = new ZipArchive();
		$opened = $zip->open( $part, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( true !== $opened ) {
			return new WP_Error( 'mody_export_zip', 'The zip file could not be started (libzip error ' . $opened . ').' );
		}

		$entries = 0;
		foreach ( $strings as $name => $contents ) {
			if ( ! $zip->addFromString( (string) $name, $contents, $flags ) ) {
				$zip->unchangeAll();
				$zip->close();
				self::clear_part( $part );
				return new WP_Error( 'mody_export_zip', 'Could not add ' . $name . ' to the zip file.' );
			}
			++$entries;
		}

		// With libzip's progress callback the writing reports itself; without it, every 250 files.
		$native = method_exists( $zip, 'registerProgressCallback' );
		$every  = self::beat_every();
		$beat   = time();
		$bytes  = 0;
		$queued = 0;
		foreach ( $files as $file ) {
			if ( ! is_readable( $file['abs'] ) ) {
				continue; // Deleted or locked since the list was made.
			}
			if ( ! $zip->addFile( $file['abs'], $file['name'], 0, 0, $flags ) ) {
				if ( ! is_readable( $file['abs'] ) ) {
					continue;
				}
				$zip->unchangeAll();
				$zip->close();
				self::clear_part( $part );
				return new WP_Error( 'mody_export_zip', 'Could not add ' . $file['name'] . ' to the zip file.' );
			}
			$zip->setCompressionIndex( $zip->count() - 1, self::method( $file['name'], $kind ) );
			++$entries;
			++$queued;
			$bytes += $file['size'];
			/**
			 * Fires after a file is queued for the zip.
			 *
			 * @param string $file The file's path.
			 */
			do_action( 'mody_export_zip_entry_added', $file['abs'] );
			if ( ! $native && 0 === $queued % 250 ) {
				$progress( $bytes, $total );
				$beat = time();
			} elseif ( time() - $beat >= $every ) {
				$progress( 0, $total );
				$beat = time();
			}
		}

		if ( $native ) {
			$zip->registerProgressCallback(
				0.01,
				static function ( float $rate ) use ( $progress, $total ): void {
					$progress( (int) round( $rate * $total ), $total );
				}
			);
		}
		// close() does the work, and PHP reports a failure only as a warning.
		$why = '';
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Collects the zip library's own message.
			static function ( int $number, string $message ) use ( &$why ): bool {
				$why = $message;
				return true;
			}
		);
		$closed = $zip->close();
		restore_error_handler();
		if ( ! $closed ) {
			self::clear_part( $part );
			$vanished = (bool) preg_match( "/can't open|no such file|read error|can't read/i", $why );
			return new WP_Error( $vanished ? 'mody_export_zip_vanished' : 'mody_export_zip', 'The zip file could not be written' . ( '' !== $why ? ': ' . $why : '.' ) );
		}
		$progress( $total, $total );

		// Every queued file made it in.
		$check = new ZipArchive();
		$count = true === $check->open( $part ) ? $check->numFiles : -1;
		$check->close();
		if ( $count !== $entries ) {
			self::clear_part( $part );
			return new WP_Error( 'mody_export_zip', 'The zip file is incomplete (' . $count . ' of ' . $entries . ' entries).' );
		}
		if ( ! rename( $part, $path ) ) {
			self::clear_part( $part );
			return new WP_Error( 'mody_export_zip', 'The finished zip file could not be moved into place.' );
		}
		return true;
	}

	/**
	 * How an entry is compressed. media.zip stores everything but a short list
	 * of text types (TEXT); code.zip deflates everything but what is already
	 * compressed (COMPRESSED).
	 *
	 * @param string $name Entry name.
	 * @param string $kind `media` or `code`.
	 */
	private static function method( string $name, string $kind ): int {
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'media' === $kind ) {
			return in_array( $extension, self::TEXT, true ) ? ZipArchive::CM_DEFLATE : ZipArchive::CM_STORE;
		}
		return in_array( $extension, self::COMPRESSED, true ) ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE;
	}

	/**
	 * Seconds between heartbeats.
	 */
	private static function beat_every(): int {
		/**
		 * Filters the seconds between the heartbeats while a file is prepared.
		 *
		 * @param int $seconds Default 15.
		 */
		return max( 0, (int) apply_filters( 'mody_export_beat_every', self::BEAT_EVERY ) );
	}

	/**
	 * Refuses a build the disk cannot hold, with a plain message. A disk whose
	 * free space cannot be read is not refused.
	 *
	 * @param string $path  The file about to be written.
	 * @param int    $bytes What goes into it.
	 * @return true|WP_Error
	 */
	private static function check_disk( string $path, int $bytes ) {
		$dir = dirname( $path );
		// Another build may create the folder between wp_mkdir_p()'s check and its mkdir().
		if ( ! wp_mkdir_p( $dir ) && ! is_dir( $dir ) ) {
			return new WP_Error( 'mody_export_dir', 'The download folder could not be created.' );
		}
		/**
		 * Filters the free bytes on the disk the download is written to.
		 *
		 * @param float|false $free Free bytes; false when unknown.
		 * @param string      $dir  The folder.
		 */
		$free = apply_filters( 'mody_export_free_space', disk_free_space( $dir ), $dir );
		$need = (int) ceil( $bytes * self::SPARE_FACTOR ) + self::SPARE_BYTES;
		if ( false !== $free && $free < $need ) {
			return new WP_Error(
				'mody_export_disk',
				sprintf( 'There is not enough free disk space to prepare this file right now (%1$s needed, %2$s free). Please try again later.', size_format( $need ), size_format( (float) $free ) )
			);
		}
		return true;
	}

	/**
	 * The files of an uploads folder that may be shipped, and a count of what
	 * is left out.
	 *
	 * @param string   $base     The uploads folder.
	 * @param callable $progress The heartbeat: a big library takes minutes to list.
	 * @return array{files: array<int, array{abs: string, name: string, size: int}>, left: int, listed: string[], beat: int, every: int, progress: callable} The files in listing order, how many entries were left out, and the first LIST_MAX of them with their reason.
	 */
	private static function uploads( string $base, callable $progress ): array {
		$state = array(
			'files'    => array(),
			'left'     => 0,
			'listed'   => array(),
			'beat'     => time(),
			'every'    => self::beat_every(),
			'progress' => $progress,
		);
		if ( is_dir( $base ) ) {
			self::scan( $base, '', $state );
		}
		usort(
			$state['files'],
			static fn( array $a, array $b ): int => strcmp( $a['name'], $b['name'] )
		);
		return $state;
	}

	/**
	 * Lists a folder of uploads, depth first.
	 *
	 * @param string $base     The uploads folder.
	 * @param string $relative The folder inside it ('' for the root).
	 * @param array  $state    The listing so far (see uploads()).
	 */
	private static function scan( string $base, string $relative, array &$state ): void {
		$dir   = '' === $relative ? $base : $base . '/' . $relative;
		$names = is_readable( $dir ) ? scandir( $dir ) : false;
		if ( false === $names ) {
			self::leave_out( $state, '' === $relative ? '(the uploads folder)' : $relative, 'folder cannot be read' );
			return;
		}
		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			if ( time() - $state['beat'] >= $state['every'] ) {
				( $state['progress'] )( 0, 0 );
				$state['beat'] = time();
			}
			$abs  = $dir . '/' . $name;
			$rel  = '' === $relative ? $name : $relative . '/' . $name;
			$root = '' === $relative;
			// .htaccess, .user.ini and every other hidden file: WordPress never makes one for media.
			if ( '.' === $name[0] ) {
				self::leave_out( $state, $rel, 'hidden file or folder' );
				continue;
			}
			if ( is_link( $abs ) ) {
				self::leave_out( $state, $rel, 'symbolic link' );
				continue;
			}
			if ( is_dir( $abs ) ) {
				if ( $root && 'wp-personal-data-exports' === $name ) {
					// What people asked WordPress for about themselves.
					self::leave_out( $state, $rel, 'private: personal data exports' );
				} else {
					self::scan( $base, $rel, $state );
				}
				continue;
			}
			if ( ! is_file( $abs ) ) {
				self::leave_out( $state, $rel, 'not a regular file' );
				continue;
			}
			if ( ! is_readable( $abs ) ) {
				self::leave_out( $state, $rel, 'file cannot be read' );
				continue;
			}
			// Never executable code from uploads (a double extension too: evil.php.jpg), a crash leftover, or a file that would shadow one of ours.
			if ( preg_match( '/\.(?:php\d*|phtml|phar|phps|pht|pgif)(?:\.|$)/i', $name ) ) {
				self::leave_out( $state, $rel, 'PHP code is never shipped from uploads' );
				continue;
			}
			if ( preg_match( '/\.part$/i', $name ) ) {
				self::leave_out( $state, $rel, 'unfinished upload (.part)' );
				continue;
			}
			if ( $root && in_array( $name, array( self::MANIFEST_FILE, self::SKIPPED_FILE ), true ) ) {
				self::leave_out( $state, $rel, 'name used by this download itself' );
				continue;
			}
			// A name that cannot be a safe zip entry; WordPress never makes one.
			if ( preg_match( '/[\x00-\x1f\x7f\\\\]/', $rel ) || ! mb_check_encoding( $rel, 'UTF-8' ) ) {
				self::leave_out( $state, $rel, 'name not safe in a zip (control character, backslash or invalid UTF-8)' );
				continue;
			}
			$size = filesize( $abs );
			if ( false === $size ) {
				self::leave_out( $state, $rel, 'file cannot be read' );
				continue;
			}
			$state['files'][] = array(
				'abs'  => $abs,
				'name' => $rel,
				'size' => $size,
			);
		}
	}

	/**
	 * Counts an entry the zip leaves out, and keeps its name for the list.
	 *
	 * @param array  $state  The listing (see uploads()).
	 * @param string $rel    The entry's name inside uploads.
	 * @param string $reason Why it is left out.
	 */
	private static function leave_out( array &$state, string $rel, string $reason ): void {
		++$state['left'];
		if ( count( $state['listed'] ) < self::LIST_MAX ) {
			// Names that cannot be shown safely (newlines, invalid UTF-8) are the very ones left out for it.
			$shown             = (string) preg_replace( '/[\x00-\x1f\x7f]/', '?', mb_scrub( $rel ) );
			$state['listed'][] = $shown . '  (' . $reason . ')';
		}
	}

	/**
	 * mody-export-skipped.txt: the entries media.zip leaves out.
	 *
	 * @param array $state The listing (see uploads()).
	 * @return string Empty when nothing was left out.
	 */
	private static function skipped_text( array $state ): string {
		if ( 0 === $state['left'] ) {
			return '';
		}
		$lines = array(
			'Left out of the media file',
			'==========================',
			'',
			'These files and folders of your uploads folder are not in the media file.',
			'Before you delete your old site, look through them and copy by hand what',
			'you still need.',
			'',
			'Total left out: ' . $state['left'],
			'',
		);
		$lines = array_merge( $lines, $state['listed'] );
		if ( $state['left'] > count( $state['listed'] ) ) {
			$lines[] = '... and ' . ( $state['left'] - count( $state['listed'] ) ) . ' more.';
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * How many entries the media file of this job left out, read from its
	 * mody-export-skipped.txt (the steps run in order, so the media file sits
	 * beside the code file).
	 *
	 * @param string $path The code file's path.
	 * @return int 0 when there is no media file or nothing was left out.
	 */
	private static function media_left_out( string $path ): int {
		$media = dirname( $path ) . '/' . Exporter::PATHS['media'];
		$text  = '';
		if ( is_file( $media ) ) {
			$zip = new ZipArchive();
			if ( true === $zip->open( $media, ZipArchive::RDONLY ) ) {
				$text = (string) $zip->getFromName( self::SKIPPED_FILE );
				$zip->close();
			}
		}
		return preg_match( '/^Total left out: (\d+)$/m', $text, $found ) ? (int) $found[1] : 0;
	}

	/**
	 * Every file under a folder (not symlinks, not SKIP), sorted by name.
	 *
	 * @param string $dir A folder.
	 * @return array<int, array{abs: string, name: string, size: int}> Names are relative to the folder.
	 */
	private static function tree( string $dir ): array {
		$files = array();
		if ( ! is_dir( $dir ) ) {
			return $files;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				static function ( \SplFileInfo $item ): bool {
					return ! $item->isLink() && ! in_array( $item->getFilename(), self::SKIP, true );
				}
			)
		);
		foreach ( $iterator as $item ) {
			if ( $item->isFile() ) {
				$files[] = array(
					'abs'  => $item->getPathname(),
					'name' => str_replace( '\\', '/', substr( $item->getPathname(), strlen( $dir ) + 1 ) ),
					'size' => (int) $item->getSize(),
				);
			}
		}
		usort(
			$files,
			static fn( array $a, array $b ): int => strcmp( $a['name'], $b['name'] )
		);
		return $files;
	}

	/**
	 * The payload, assembled once per deploy into the shared cache and reused.
	 *
	 * @param callable $progress The heartbeat.
	 * @return array{dir: string, info: array}|WP_Error The cache folder (it holds payload/) and what Portable::assemble() returned.
	 */
	private static function payload( callable $progress ) {
		$cache = self::cache_root();
		// Two builds starting together: the loser's mkdir fails because the winner's just made the folder.
		if ( ! wp_mkdir_p( $cache ) && ! is_dir( $cache ) ) {
			return new WP_Error( 'mody_export_dir', 'The payload cache folder could not be created.' );
		}
		$progress( 0, 0 ); // The key stats every source file: slow on a network disk.
		$key = self::cache_key();
		$dir = $cache . '/' . $key;

		if ( ! is_file( $dir . '/payload.json' ) ) {
			$progress( 0, 0 );
			// Built beside the final folder and renamed into place, so a half-built payload is never used and two builders cannot collide.
			$stage = $cache . '/' . $key . '.tmp-' . bin2hex( random_bytes( 4 ) );
			$info  = Portable::assemble( $stage . '/payload' );
			if ( is_wp_error( $info ) ) {
				self::remove_tree( $stage );
				return $info;
			}
			$written = file_put_contents( $stage . '/payload.json', (string) wp_json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The cache folder.
			if ( false === $written ) {
				self::remove_tree( $stage );
				return new WP_Error( 'mody_export_copy', 'Could not write the payload cache.' );
			}
			if ( is_file( $dir . '/payload.json' ) ) {
				self::remove_tree( $stage ); // Another builder finished first: use its payload, which may be in use.
			} else {
				if ( is_dir( $dir ) ) {
					self::remove_tree( $dir ); // A folder without payload.json: not one of ours.
				}
				// A builder that loses this race gets "Directory not empty": silenced, and it uses the winner's folder below.
				if ( ! @rename( $stage, $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					self::remove_tree( $stage );
					if ( ! is_file( $dir . '/payload.json' ) ) {
						return new WP_Error( 'mody_export_copy', 'Could not move the payload into the cache.' );
					}
				}
			}
			$progress( 0, 0 );
		}

		touch( $dir ); // In use now: other builders keep it for CACHE_KEEP.
		self::prune( $cache, $key );
		$info = json_decode( (string) file_get_contents( $dir . '/payload.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
		if ( ! is_array( $info ) ) {
			return new WP_Error( 'mody_export_copy', 'The payload cache could not be read.' );
		}
		return array(
			'dir'  => $dir,
			'info' => $info,
		);
	}

	/**
	 * A hash of what the payload is made of: the paths, sizes and times of
	 * the theme, Mody Site's files, the importer kit, the fonts and the list
	 * of their faces (Design/Fonts.php), Portable, this class and the three
	 * plugins. It changes when a deploy changes any of them.
	 */
	private static function cache_key(): string {
		$root  = dirname( __DIR__, 2 );
		$paths = array(
			get_theme_root( Portable::THEME ) . '/' . Portable::THEME,
			$root . '/export-kit',
			$root . '/fonts',
			$root . '/src/Export/Portable.php',
			$root . '/src/Design/Fonts.php',
			__FILE__,
		);
		foreach ( Portable::SITE_FILES as $file ) {
			$paths[] = $root . '/src/' . $file;
		}
		foreach ( Portable::SITE_ASSETS as $file ) {
			$paths[] = $root . '/assets/' . $file;
		}
		foreach ( array_keys( Portable::PLUGINS ) as $folder ) {
			$paths[] = WPMU_PLUGIN_DIR . '/' . $folder;
		}
		$lines = array( 'cache-v' . self::CACHE_VERSION );
		foreach ( $paths as $path ) {
			if ( is_file( $path ) ) {
				$lines[] = $path . '|' . filesize( $path ) . '|' . filemtime( $path );
				continue;
			}
			foreach ( self::tree( $path ) as $file ) {
				$lines[] = $file['abs'] . '|' . $file['size'] . '|' . filemtime( $file['abs'] );
			}
		}
		sort( $lines );
		return substr( hash( 'sha256', implode( "\n", $lines ) ), 0, 16 );
	}

	/**
	 * Deletes cache folders that are not the current one and have not been
	 * used for CACHE_KEEP (an older deploy's payload, a crashed build's
	 * leftovers), so the cache holds one payload.
	 *
	 * @param string $cache The cache root.
	 * @param string $keep  The current key.
	 */
	private static function prune( string $cache, string $keep ): void {
		foreach ( (array) glob( $cache . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( basename( (string) $dir ) !== $keep && (int) filemtime( (string) $dir ) < time() - self::CACHE_KEEP ) {
				self::remove_tree( (string) $dir );
			}
		}
	}

	/**
	 * Deletes a folder inside the cache, and what is in it.
	 *
	 * @param string $dir The folder.
	 */
	private static function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) || ! str_starts_with( $dir, self::cache_root() . '/' ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isLink() || $item->isFile() ) {
				wp_delete_file( $item->getPathname() );
			} else {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Inside the cache, now empty.
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The cache folder, now empty.
	}

	/**
	 * Deletes a zip's work file and what libzip leaves beside it: while it
	 * writes, libzip makes "<part>.XXXXXX.part" and renames it onto the part
	 * file at the end, so a killed build leaves the temporary one behind (up
	 * to the size of the zip). Called before every build and on every failure.
	 *
	 * @param string $part The zip's "<path>.part".
	 */
	private static function clear_part( string $part ): void {
		$dir  = dirname( $part );
		$base = basename( $part );
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			$name = (string) $name;
			if ( str_starts_with( $name, $base ) && is_file( $dir . '/' . $name ) ) {
				wp_delete_file( $dir . '/' . $name );
			}
		}
	}

	/**
	 * README-restore.txt: how to put the three files back by hand, for the
	 * owner or a technical friend. The search-replace pairs come from the
	 * manifest, so they are this site's own addresses.
	 *
	 * @param array $manifest Exporter::manifest().
	 * @param array $payload  What Portable::assemble() returned: the theme and the plugins with their versions.
	 * @param int   $left     How many entries the media file left out (media_left_out()).
	 */
	private static function readme( array $manifest, array $payload, int $left = 0 ): string {
		$site    = (array) ( $manifest['site'] ?? array() );
		$wp      = (string) ( $manifest['requires']['wp'] ?? '' );
		$php     = (string) ( $manifest['requires']['php'] ?? '' );
		$theme   = (string) ( $manifest['theme'] ?? Portable::THEME );
		$plugins = array_values( array_filter( (array) ( $manifest['plugins'] ?? array() ), 'is_string' ) );
		$slugs   = array_map( 'dirname', $plugins );
		$made    = substr( (string) ( $manifest['created_at'] ?? '' ), 0, 10 );

		$plugin_lines = array();
		foreach ( $plugins as $file ) {
			$data           = (array) ( $payload['plugins'][ $file ] ?? array() );
			$plugin_lines[] = sprintf( '    - %s %s (%s)', (string) ( $data['name'] ?? dirname( $file ) ), (string) ( $data['version'] ?? '' ), dirname( $file ) );
		}

		$lines = array(
			'RESTORING YOUR SITE BY HAND',
			'===========================',
			'',
			'You downloaded your site from Mody Cloud as three files. This page is for',
			'whoever puts it back on another host: you, or a technical friend. You do not',
			'have to install the Mody Import plugin this page came in to follow it. A',
			'later version of the plugin will do all of this in one click.',
			'',
			'The site',
			'    Name:       ' . (string) ( $site['name'] ?? '' ),
			'    Address:    ' . (string) ( $site['home'] ?? '' ),
			'    Made on:    ' . $made,
			'    Needs:      WordPress ' . $wp . ' or newer, PHP ' . $php . ' or newer, MySQL or MariaDB',
			'',
			'The three files',
			'    code      <name>-code-<date>.zip         this plugin, and in it the theme and plugins',
			'    media     <name>-media-<date>.zip        your media library',
			'    database  <name>-database-<date>.sql.gz  your pages, design, settings and users',
			'',
			'Run the commands below in a terminal on the new host, from the folder where',
			'WordPress is installed (the one with wp-config.php). They use WP-CLI for the',
			'steps that need it (https://wp-cli.org).',
			'',
			'STEP 1. Install a fresh WordPress',
			'---------------------------------',
			'Install WordPress ' . $wp . ' or newer, on a database that holds only this site (step 4',
			'empties every table in it, and step 5 rewrites them). Do not add content or',
			'change any setting: step 4 replaces what the installer made.',
			'',
			'STEP 2. Copy the theme and the plugins',
			'--------------------------------------',
			'    unzip -q THE-CODE-FILE.zip -d /tmp/mody-code',
			'    cp -R /tmp/mody-code/' . self::PLUGIN_FOLDER . '/payload/themes/.  wp-content/themes/',
			'    cp -R /tmp/mody-code/' . self::PLUGIN_FOLDER . '/payload/plugins/. wp-content/plugins/',
			'',
			'That puts the theme "' . $theme . '" and these plugins in place. They are normal',
			'WordPress plugins: you can switch them off or delete them like any other.',
			'',
		);
		$lines = array_merge( $lines, $plugin_lines );
		$lines = array_merge(
			$lines,
			array(
				'',
				'STEP 3. Copy the media',
				'----------------------',
				'The media file unzips straight into the uploads folder:',
				'',
				'    unzip -q THE-MEDIA-FILE.zip -d wp-content/uploads',
				'',
			),
			self::left_out_lines( $left ),
			array(
				'Two small files of the download, ' . self::MANIFEST_FILE . ' and',
				self::SKIPPED_FILE . ' (only when something was left out),',
				'now sit in the uploads folder. Step 9 deletes them.',
				'',
				'STEP 4. Load the database',
				'-------------------------',
				'The database file holds rows only. It goes into the empty tables of the fresh',
				'WordPress, so first empty what the installer put there (in phpMyAdmin: tick',
				'every table and choose "Empty"):',
				'',
				'    for TABLE in $(wp db tables); do wp db query "TRUNCATE TABLE \`$TABLE\`"; done',
				'',
				'Every table name in the file starts with {{prefix}}. Replace that with the table',
				'prefix of the new WordPress (the $table_prefix line in wp-config.php, wp_ unless',
				'you chose another). The echo at the start of each command below sets the',
				'connection up for the file (full unicode, and the 0000-00-00 dates drafts hold).',
				'',
				'With WP-CLI, which reads the connection from wp-config.php:',
				'',
				'    PREFIX=$(wp config get table_prefix)',
				'    ( echo "SET NAMES utf8mb4; SET SESSION sql_mode=\'NO_AUTO_VALUE_ON_ZERO\';"; gunzip -c THE-DATABASE-FILE.sql.gz | sed "s/{{prefix}}/$PREFIX/g" ) | wp db import -',
				'',
				'Or with mysql (change DB_USER and DB_NAME, and wp_ if your prefix is another):',
				'',
				'    ( echo "SET NAMES utf8mb4; SET SESSION sql_mode=\'NO_AUTO_VALUE_ON_ZERO\';"; gunzip -c THE-DATABASE-FILE.sql.gz | sed \'s/{{prefix}}/wp_/g\' ) | mysql -u DB_USER -p DB_NAME',
				'',
				'STEP 5. Point the addresses at the new site',
				'-------------------------------------------',
				'The database still has the addresses your site had on Mody Cloud. Set NEW_HOME',
				'to the address of the new site: with https://, no slash at the end, and its',
				'folder if WordPress is not at the root of the domain. Then run every line below,',
				'in this order, before you open the site in a browser. They need bash.',
				'',
				'The order matters. First your old address becomes the new one; then the folders',
				'of Mody Cloud (/web/uploads/sites/N for the media, /web/ for the rest) become',
				'wp-content under the new address; last, links written with the address of Mody',
				'Cloud\'s own network are pointed at wp-content too.',
				'',
				"    NEW_HOME='https://your-new-site.example'",
				'    NEW_HOME_JSON="${NEW_HOME//\\//\\\\/}"',
				'',
			),
			self::replace_lines( $site ),
			array(
				'If the database also holds other sites, use --all-tables-with-prefix instead',
				'of --all-tables, so only this site\'s tables change.',
				'',
				'STEP 6. Activate the theme and the plugins',
				'------------------------------------------',
				'    wp theme activate ' . $theme,
				'    wp plugin activate ' . implode( ' ', $slugs ),
				'',
				'STEP 7. Set a password and log in',
				'---------------------------------',
				'No password leaves Mody Cloud, so nobody can log in until you set one:',
				'',
				'    wp user list',
				"    wp user update USER --user_pass='a long new password'",
				'',
				'Everyone else who had an account sets their own with "Lost your password?" on',
				'the login page, once the new host can send email.',
				'',
				'STEP 8. Save the permalinks',
				'---------------------------',
				'Log in, open Settings → Permalinks and click Save Changes (change nothing).',
				'On Apache and most shared hosts that click is what writes the rules that make',
				'every page address work: until then every page except the home page answers',
				'"not found". WP-CLI cannot do it for you. Its "wp rewrite flush" only refreshes',
				'the list kept in the database, which is all a host with its own address rules',
				'(Nginx) needs:',
				'',
				'    wp rewrite flush',
				'',
				'STEP 9. Delete what you no longer need',
				'--------------------------------------',
				'Nothing from the download has to stay on the web. Remove the unzipped copy of',
				'the code, and the two small files that came into the uploads folder:',
				'',
				'    rm -rf /tmp/mody-code',
				'    rm -f wp-content/uploads/' . self::MANIFEST_FILE . ' wp-content/uploads/' . self::SKIPPED_FILE,
				'',
				'If you installed the Mody Import plugin from the code file, delete it too. Its',
				'folder holds a second copy of the theme and the plugins, which nobody updates:',
				'',
				'    wp plugin deactivate ' . self::PLUGIN_FOLDER,
				'    wp plugin delete ' . self::PLUGIN_FOLDER,
				'',
				'WHAT IS NOT IN THE DOWNLOAD',
				'---------------------------',
				'    - Mody, the AI agent: the chat bubble, its abilities and the chat history.',
				'    - Mody Cloud\'s own billing, sign-up and storage limit, and its locks on',
				'      plugins and updates: this is a normal WordPress you control completely.',
				'    - Wordfence, the visitor counter and the sign-in check that ran on Mody Cloud.',
				'      (Antispam Bee still guards your comments.)',
				'    - Passwords, application passwords and API keys.',
				'    - Email sending: your new host sends it.',
				'',
			)
		);
		return implode( "\n", $lines );
	}

	/**
	 * The README's note on what the media file left out.
	 *
	 * @param int $left Entries left out; 0 for none or unknown.
	 * @return string[] Lines, with a blank one at the end; none for 0.
	 */
	private static function left_out_lines( int $left ): array {
		if ( $left < 1 ) {
			return array();
		}
		return array(
			1 === $left ? '1 file or folder was left out of the media file.' : $left . ' files or folders were left out of the media file.',
			'They were hidden, symbolic links, unreadable, PHP code, or had a name that is',
			'not safe in a zip. Each is listed, with its reason, in',
			'wp-content/uploads/' . self::SKIPPED_FILE . ' now. Read it before you delete',
			'anything on the old site, and copy by hand what you still need.',
			'',
		);
	}

	/**
	 * The `wp search-replace` lines for a site, from its manifest, plain and
	 * then with the slashes escaped as JSON does (block markup stores them so).
	 * Each pass has three groups, in an order that cannot rewrite its own work:
	 *
	 * 1. The old home address (https and http) becomes NEW_HOME.
	 * 2. Under NEW_HOME, the uploads folder (/web/uploads/sites/N) becomes
	 *    /wp-content/uploads, then the content folder (/web/) /wp-content/.
	 *    They come after group 1, so a NEW_HOME that begins with the old
	 *    address (the same domain in a subfolder) is not rewritten twice.
	 * 3. The same two folders under every other host of the manifest (the
	 *    network's own) become NEW_HOME's. Not the hosts themselves: a link to
	 *    the network's address is a link to Mody Cloud's main site.
	 *
	 * A group that cannot be built safely is dropped with a warning line.
	 *
	 * @param array $site The manifest's `site` part.
	 * @return string[] Lines of the README.
	 */
	private static function replace_lines( array $site ): array {
		$flags   = '--precise --all-tables --skip-columns=guid';
		$notes   = array();
		$hosts   = array();
		$dropped = 0;
		foreach ( (array) ( $site['hosts'] ?? array() ) as $host ) {
			if ( is_string( $host ) && preg_match( '/^[A-Za-z0-9.\-]+(?::\d+)?$/', $host ) ) {
				$hosts[] = $host;
			} else {
				++$dropped;
			}
		}
		if ( $dropped ) {
			$notes[] = 'WARNING: ' . $dropped . ' host name(s) of the site are left out of the lines below because they are unusual. Replace their /web/uploads/sites/N and /web/ addresses by hand.';
		}
		$valid = static fn( string $path ): bool => (bool) preg_match( '#^/[A-Za-z0-9._\-/]*$#', $path );

		// What the site was called by, without the scheme: its home and site address. The longest first, so a site address inside the home is replaced before it.
		$homes = array();
		foreach ( array( 'home', 'siteurl' ) as $key ) {
			$bare = (string) preg_replace( '#^https?://#i', '', untrailingslashit( (string) ( $site[ $key ] ?? '' ) ) );
			if ( '' !== $bare && preg_match( '#^[A-Za-z0-9.\-]+(?::\d+)?(?:/[A-Za-z0-9._\-/]*)?$#', $bare ) ) {
				$homes[ $bare ] = $bare;
			}
		}
		uasort( $homes, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		$home_hosts = array();
		foreach ( $homes as $bare ) {
			$home_hosts[ (string) strtok( $bare, '/' ) ] = true;
		}
		$home_path = untrailingslashit( (string) wp_parse_url( (string) ( $site['home'] ?? '' ), PHP_URL_PATH ) );

		// The two folders of Mody Cloud: where they are (from the host), where they are under the home address, and where they go.
		$folders = array();
		$content = untrailingslashit( (string) ( $site['content'] ?? '' ) );
		$wanted  = array(
			'The uploads folder' => array( (string) ( $site['uploads']['path'] ?? '' ), '/wp-content/uploads' ),
			'The content folder' => array( '' === $content ? '' : $content . '/', '/wp-content/' ),
		);
		foreach ( $wanted as $label => list( $path, $to ) ) {
			if ( '' === $path ) {
				continue;
			}
			$under = '' === $home_path ? $path : ( str_starts_with( $path, $home_path . '/' ) ? substr( $path, strlen( $home_path ) ) : '' );
			if ( ! $valid( $path ) || '' === $under ) {
				$notes[] = 'WARNING: ' . $label . ' has an address these lines cannot write safely, so it is left out. Replace it by hand.';
				continue;
			}
			$folders[] = array(
				'path'  => $path,
				'under' => $under,
				'to'    => $to,
			);
		}

		// Each pair: the old text, whether it sits under NEW_HOME, and what follows NEW_HOME in the new text.
		$groups = array();
		if ( $homes ) {
			$pairs = array();
			foreach ( $homes as $bare ) {
				foreach ( array( 'https', 'http' ) as $scheme ) {
					$pairs[] = array( $scheme . '://' . $bare, false, '' );
				}
			}
			$groups[] = array( '1. Your old address becomes the new one.', $pairs );

			$pairs = array();
			foreach ( $folders as $folder ) {
				$pairs[] = array( $folder['under'], true, $folder['to'] );
			}
			if ( $pairs ) {
				$groups[] = array( '2. Under the new address, the folders of Mody Cloud become wp-content.', $pairs );
			}
		} else {
			$notes[] = 'WARNING: the address of the site is unusual, so it is not replaced below. Replace it by hand.';
		}
		$pairs = array();
		foreach ( $hosts as $host ) {
			if ( isset( $home_hosts[ $host ] ) ) {
				continue;
			}
			foreach ( $folders as $folder ) {
				foreach ( array( 'https', 'http' ) as $scheme ) {
					$pairs[] = array( $scheme . '://' . $host . $folder['path'], false, $folder['to'] );
				}
			}
		}
		if ( $pairs ) {
			$groups[] = array( '3. The same folders, written with the address of Mody Cloud\'s network.', $pairs );
		}

		$lines = array();
		foreach ( $notes as $note ) {
			$lines[] = '    # ' . $note;
		}
		foreach ( array( false, true ) as $json ) {
			$new     = $json ? '$NEW_HOME_JSON' : '$NEW_HOME';
			$esc     = static fn( string $text ): string => $json ? str_replace( '/', '\\/', $text ) : $text;
			$lines[] = $json
				? '    # The same, for the addresses stored with escaped slashes (\/), as JSON does:'
				: '    # Plain addresses.';
			foreach ( $groups as list( $label, $pairs ) ) {
				$lines[] = '    # ' . $label;
				foreach ( $pairs as list( $from, $under, $tail ) ) {
					// Under NEW_HOME the shell fills the variable in (the rest is validated); an old address is quoted as it is.
					$old     = $under ? '"' . $new . $esc( $from ) . '"' : escapeshellarg( $esc( $from ) );
					$lines[] = '    wp search-replace ' . $old . ' "' . $new . $esc( $tail ) . '" ' . $flags;
				}
			}
			$lines[] = '';
		}
		return $lines;
	}
}
