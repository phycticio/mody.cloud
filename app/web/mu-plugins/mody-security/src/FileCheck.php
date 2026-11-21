<?php
/**
 * The file check: the server's code compared with what the last deploy
 * shipped, and scripts where only uploads belong.
 *
 * @package Mody\Security
 */

namespace Mody\Security;

defined( 'ABSPATH' ) || exit;

/**
 * The reference is ours, not a checksum service outside our servers:
 * `bin/deploy` writes `app/files.sha256` (one `sha256  path` line per code
 * file, paths from `app/`) in the release it syncs, so every file the
 * server should have is listed with its hash. The check reports:
 *
 * - **changed**: a listed file whose contents differ;
 * - **added**: a file in a code folder that the deploy did not ship;
 * - **missing**: a listed file that is gone;
 * - **uploads**: anything in uploads with a script's extension (Nginx never
 *   runs them, but nothing legitimate puts them there).
 *
 * Files the server writes itself are not code and are skipped (SKIPPED).
 * Without a manifest (a local checkout) the check says so; `wp
 * mody-security files manifest` writes one.
 */
final class FileCheck {

	/** The manifest, in `app/`. */
	public const MANIFEST = 'files.sha256';

	/** The last result: a network option. */
	public const OPTION = 'mody_security_file_check';

	/** The code folders and files, from `app/`. */
	public const ROOTS = array( 'wp', 'web/plugins', 'web/mu-plugins', 'web/themes', 'vendor', 'web/index.php', 'web/sunrise.php', 'wp-config.php' );

	/** Paths the server writes itself (patterns from `app/`). */
	public const SKIPPED = array(
		'#(^|/)\.user\.ini$#',
		'#(^|/)\.DS_Store$#',
		'#(^|/)(node_modules|\.git)/#',
		'#^web/(advanced|object)-cache\.php$#',
		'#^web/plugins/mody-test-plugin/#',
		'#^wp/wp-content/#',
	);

	/** Extensions that run as scripts somewhere. */
	private const SCRIPT_EXTENSIONS = '/\.(?:php[0-9]?|phtml|phar|pht|phps|cgi|pl|py|sh|asp|aspx|jsp)$/i';

	/** Paths listed per kind in a result, at most. */
	private const MAX_LISTED = 200;

	/**
	 * The `app/` folder.
	 */
	public static function root(): string {
		return dirname( untrailingslashit( ABSPATH ) );
	}

	/**
	 * Every code file on disk, as path => full path.
	 *
	 * @return array<string, string>
	 */
	public static function files(): array {
		$root  = self::root();
		$files = array();
		foreach ( self::ROOTS as $entry ) {
			$full = $root . '/' . $entry;
			if ( is_file( $full ) ) {
				$files[ $entry ] = $full;
				continue;
			}
			if ( ! is_dir( $full ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $full, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$path = substr( $file->getPathname(), strlen( $root ) + 1 );
					if ( ! self::skipped( $path ) ) {
						$files[ $path ] = $file->getPathname();
					}
				}
			}
		}
		ksort( $files, SORT_STRING );
		return $files;
	}

	/**
	 * Whether a path is one the server writes itself.
	 *
	 * @param string $path From `app/`.
	 */
	public static function skipped( string $path ): bool {
		foreach ( self::SKIPPED as $pattern ) {
			if ( preg_match( $pattern, $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Writes the manifest from the files on disk (a local checkout; the
	 * deploy writes its own).
	 *
	 * @return int Files listed.
	 */
	public static function write_manifest(): int {
		$lines = '';
		$files = self::files();
		foreach ( $files as $path => $full ) {
			$lines .= hash_file( 'sha256', $full ) . '  ' . $path . "\n";
		}
		file_put_contents( self::root() . '/' . self::MANIFEST, $lines ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A file of our own, from WP-CLI.
		return count( $files );
	}

	/**
	 * The manifest: path => hash; null when there is none.
	 *
	 * @return array<string, string>|null
	 */
	public static function manifest(): ?array {
		$file = self::root() . '/' . self::MANIFEST;
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$list = array();
		foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			if ( preg_match( '/^([0-9a-f]{64}) [ *](.+)$/', (string) $line, $m ) ) {
				$path = (string) preg_replace( '#^\./#', '', $m[2] );
				if ( ! self::skipped( $path ) ) {
					$list[ $path ] = $m[1];
				}
			}
		}
		return $list;
	}

	/**
	 * Runs the check, saves and returns its result.
	 *
	 * @return array{time: int, manifest: bool, built: int, checked: int, changed: string[], added: string[], missing: string[], uploads: string[], seconds: float}
	 */
	public static function run(): array {
		$start    = microtime( true );
		$manifest = self::manifest();
		$result   = array(
			'time'     => time(),
			'manifest' => null !== $manifest,
			'built'    => null !== $manifest ? (int) filemtime( self::root() . '/' . self::MANIFEST ) : 0,
			'checked'  => 0,
			'changed'  => array(),
			'added'    => array(),
			'missing'  => array(),
			'uploads'  => self::scripts_in_uploads(),
			'seconds'  => 0.0,
		);
		if ( null !== $manifest ) {
			$files = self::files();
			foreach ( $files as $path => $full ) {
				++$result['checked'];
				if ( ! isset( $manifest[ $path ] ) ) {
					$result['added'][] = $path;
				} elseif ( ! hash_equals( $manifest[ $path ], (string) hash_file( 'sha256', $full ) ) ) {
					$result['changed'][] = $path;
				}
			}
			$result['missing'] = array_keys( array_diff_key( $manifest, $files ) );
		}
		foreach ( array( 'changed', 'added', 'missing', 'uploads' ) as $key ) {
			$result[ $key . '_count' ] = count( $result[ $key ] );
			$result[ $key ]            = array_slice( $result[ $key ], 0, self::MAX_LISTED );
		}
		$result['seconds'] = round( microtime( true ) - $start, 2 );
		update_site_option( self::OPTION, $result );
		if ( self::found( $result ) ) {
			Events::record( 'file_changes', 0, sprintf( '%d changed, %d added, %d missing, %d in uploads', $result['changed_count'], $result['added_count'], $result['missing_count'], $result['uploads_count'] ) );
		}
		return $result;
	}

	/**
	 * Whether a result found anything.
	 *
	 * @param array<string, mixed> $result From run().
	 */
	public static function found( array $result ): bool {
		return (bool) ( $result['changed'] || $result['added'] || $result['missing'] || $result['uploads'] );
	}

	/**
	 * The last result, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last(): ?array {
		$result = get_site_option( self::OPTION );
		return is_array( $result ) ? $result : null;
	}

	/**
	 * Files with a script's extension in the uploads folder (every site's).
	 *
	 * @return string[] Paths from `app/`.
	 */
	public static function scripts_in_uploads(): array {
		$uploads = self::root() . '/web/uploads';
		if ( ! is_dir( $uploads ) ) {
			return array();
		}
		$found    = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $uploads, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && preg_match( self::SCRIPT_EXTENSIONS, $file->getFilename() ) ) {
				$found[] = substr( $file->getPathname(), strlen( self::root() ) + 1 );
			}
		}
		sort( $found );
		return $found;
	}
}
