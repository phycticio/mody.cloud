<?php
/**
 * The code a downloaded site takes with it: the theme and its plugins,
 * without Mody.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Export;

use Mody\Platform\Design\Fonts;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles the payload of the code file (tickets/take-your-site-with-you.md)
 * into a folder laid out like wp-content:
 *
 * - `themes/theme`: the theme, with Inter baked into its theme.json (on the
 *   platform, Design\Fonts adds the faces from this plugin's fonts folder).
 * - `plugins/mody-site`: Mody Site, the platform's classes a site needs to
 *   look and work the same (SITE_FILES), with its own bootstrap
 *   (export-kit/mody-site/mody-site.php). Same namespace, so options, post
 *   meta and addresses keep their names.
 * - `plugins/mody-languages`, `plugins/spectra-blocks`, `plugins/antispam-bee`:
 *   copies of what the platform runs as MU plugins; the downloaded site runs
 *   them as normal plugins, which its owner updates and switches off.
 *
 * Never: the agent (Agent/, AI/, Abilities, the `ai` plugin and its
 * connectors), the platform's billing, signup, provisioning, quota and locks
 * (Gating/), mody-mail, mody-analytics, Wordfence or the dev plugins.
 */
final class Portable {

	/** Mody Site's classes, from this plugin's src/. */
	public const SITE_FILES = array(
		'Admin/AdminColors.php',
		'Design/PaletteOnly.php',
		'Design/Patterns.php',
		'Login/Color.php',
		'Login/LoginPage.php',
		'Login/Schemes.php',
		'Login/SettingsPage.php',
		'Security/AntispamBee.php',
		'Site/Seo.php',
		'Spectra/SpectraGuard.php',
		'Users/Avatars.php',
	);

	/** Mody Site's assets, from this plugin's assets/. */
	public const SITE_ASSETS = array( 'login.css', 'login-settings.css' );

	/** Plugins copied from mu-plugins/, by folder: their main file. */
	public const PLUGINS = array(
		'antispam-bee'   => 'antispam_bee.php',
		'mody-languages' => 'mody-languages.php',
		'spectra-blocks' => 'spectra-blocks.php',
	);

	/** Mody Site's main file, in its folder. */
	public const SITE_PLUGIN = 'mody-site/mody-site.php';

	/** The theme's folder name: the database's template and stylesheet options point to it. */
	public const THEME = 'theme';

	/** Never copied, at any depth. */
	private const SKIP = array( '.git', '.github', 'node_modules', '.DS_Store', '.gitignore', 'tests' );

	/**
	 * This plugin's folder.
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * The plugins the downloaded site activates, in load order: Mody Site
	 * before Spectra, so the guard is in place first.
	 *
	 * @return string[] Plugin files, relative to the plugins folder.
	 */
	public static function active_plugins(): array {
		$plugins = array( self::SITE_PLUGIN );
		foreach ( self::PLUGINS as $folder => $file ) {
			$plugins[] = $folder . '/' . $file;
		}
		sort( $plugins ); // WordPress keeps active_plugins sorted, and loads them in that order.
		return $plugins;
	}

	/**
	 * Builds the payload in an empty or missing folder.
	 *
	 * @param string $dir The folder: it gets themes/ and plugins/.
	 * @return array{theme: array{folder: string, version: string}, plugins: array<string, array{file: string, name: string, version: string}>}|WP_Error
	 */
	public static function assemble( string $dir ) {
		$dir = untrailingslashit( $dir );
		if ( is_dir( $dir ) && ( new \FilesystemIterator( $dir ) )->valid() ) {
			return new WP_Error( 'mody_export_not_empty', 'The payload folder is not empty: ' . $dir );
		}

		// The theme, with Inter's files and faces.
		$theme_source = get_theme_root( self::THEME ) . '/' . self::THEME;
		$theme        = $dir . '/themes/' . self::THEME;
		$done         = self::copy( $theme_source, $theme );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		$done = self::bake_fonts( $theme );
		if ( is_wp_error( $done ) ) {
			return $done;
		}

		// Mody Site.
		$site = $dir . '/plugins/' . dirname( self::SITE_PLUGIN );
		$done = self::copy( self::root() . '/export-kit/mody-site', $site );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		foreach ( self::SITE_FILES as $file ) {
			$done = self::copy( self::root() . '/src/' . $file, $site . '/src/' . $file );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		foreach ( self::SITE_ASSETS as $file ) {
			$done = self::copy( self::root() . '/assets/' . $file, $site . '/assets/' . $file );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		$licenses = array( $site . '/LICENSE.txt', $dir . '/plugins/mody-languages/LICENSE.txt', $theme . '/LICENSE.txt' );

		// The plugins the platform runs as MU plugins.
		foreach ( array_keys( self::PLUGINS ) as $folder ) {
			$done = self::copy( WPMU_PLUGIN_DIR . '/' . $folder, $dir . '/plugins/' . $folder );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		foreach ( $licenses as $license ) {
			if ( ! is_file( $license ) && ! copy( self::root() . '/export-kit/LICENSE-GPL-2.0.txt', $license ) ) {
				return new WP_Error( 'mody_export_copy', 'Could not write ' . $license );
			}
		}

		$unresolved = self::unresolved( $site );
		if ( $unresolved ) {
			return new WP_Error( 'mody_export_unresolved', 'Mody Site uses classes it does not carry: ' . implode( ', ', $unresolved ) );
		}

		$plugins = array();
		foreach ( self::active_plugins() as $file ) {
			$data             = get_file_data(
				$dir . '/plugins/' . $file,
				array(
					'name'    => 'Plugin Name',
					'version' => 'Version',
				)
			);
			$plugins[ $file ] = array(
				'file'    => $file,
				'name'    => (string) $data['name'],
				'version' => (string) $data['version'],
			);
			if ( '' === $plugins[ $file ]['name'] ) {
				return new WP_Error( 'mody_export_header', 'No plugin header in ' . $file );
			}
		}
		$theme_data = get_file_data( $theme . '/style.css', array( 'version' => 'Version' ) );

		return array(
			'theme'   => array(
				'folder'  => self::THEME,
				'version' => (string) $theme_data['version'],
			),
			'plugins' => $plugins,
		);
	}

	/**
	 * Inter's files into the theme's assets/fonts/inter/, and its faces into
	 * theme.json with `file:` addresses, so the theme needs no plugin for them.
	 *
	 * @param string $theme The copied theme's folder.
	 * @return true|WP_Error
	 */
	private static function bake_fonts( string $theme ) {
		$json = json_decode( (string) file_get_contents( $theme . '/theme.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'mody_export_theme_json', 'The theme.json could not be read.' );
		}
		$families = (array) ( $json['settings']['typography']['fontFamilies'] ?? array() );
		foreach ( $families as $i => $family ) {
			$slug = is_array( $family ) ? (string) ( $family['slug'] ?? '' ) : '';
			if ( ! isset( Fonts::FAMILIES[ $slug ] ) || ! empty( $family['fontFace'] ) ) {
				continue;
			}
			$faces = array();
			foreach ( Fonts::FAMILIES[ $slug ]['faces'] as list( $file, $weight, $style ) ) {
				$done = self::copy( Fonts::path( $slug, $file ), $theme . '/assets/fonts/' . $slug . '/' . $file );
				if ( is_wp_error( $done ) ) {
					return $done;
				}
				$faces[] = array(
					'fontFamily' => Fonts::FAMILIES[ $slug ]['name'],
					'fontStyle'  => $style,
					'fontWeight' => $weight,
					'src'        => array( 'file:./assets/fonts/' . $slug . '/' . $file ),
				);
			}
			$license = Fonts::path( $slug, 'LICENSE.txt' );
			if ( is_file( $license ) ) {
				self::copy( $license, $theme . '/assets/fonts/' . $slug . '/LICENSE.txt' );
			}
			$families[ $i ]['fontFace'] = $faces;
		}
		$json['settings']['typography']['fontFamilies'] = array_values( $families );
		$written = file_put_contents( $theme . '/theme.json', wp_json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The payload folder.
		return false === $written ? new WP_Error( 'mody_export_copy', 'Could not write the theme.json.' ) : true;
	}

	/**
	 * The Mody\Platform classes Mody Site's files name but do not carry.
	 * Every name is resolved the way PHP does: `use` imports, then the file's
	 * own namespace.
	 *
	 * @param string $plugin Mody Site's folder.
	 * @return string[] Class names.
	 */
	public static function unresolved( string $plugin ): array {
		$missing = array();
		$files   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $plugin, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			foreach ( self::class_names( (string) file_get_contents( $file->getPathname() ) ) as $name ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
				if ( ! str_starts_with( $name, 'Mody\\Platform\\' ) ) {
					continue;
				}
				$path = $plugin . '/src/' . str_replace( '\\', '/', substr( $name, strlen( 'Mody\\Platform\\' ) ) ) . '.php';
				if ( ! is_file( $path ) ) {
					$missing[ $name ] = $name;
				}
			}
		}
		sort( $missing );
		return array_values( $missing );
	}

	/**
	 * The classes a PHP file names: in `use`, before `::`, after `new`,
	 * `instanceof` and `extends`, and in `X::class`, resolved to full names.
	 *
	 * @param string $code PHP source.
	 * @return string[]
	 */
	public static function class_names( string $code ): array {
		$tokens    = token_get_all( $code );
		$namespace = '';
		$uses      = array();
		$names     = array();
		$count     = count( $tokens );
		$text      = static fn( $t ): string => is_array( $t ) ? $t[1] : $t;
		$next      = static function ( int $i ) use ( $tokens, $count ): int {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( ! is_array( $tokens[ $j ] ) || ! in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					return $j;
				}
			}
			return $count;
		};
		$prev      = static function ( int $i ) use ( $tokens ): int {
			for ( $j = $i - 1; $j >= 0; $j-- ) {
				if ( ! is_array( $tokens[ $j ] ) || ! in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					return $j;
				}
			}
			return -1;
		};
		$name_ids  = array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED );
		$depth     = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$depth;
			} elseif ( '}' === $token ) {
				--$depth;
			}
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( T_NAMESPACE === $token[0] ) {
				$j         = $next( $i );
				$namespace = $j < $count && is_array( $tokens[ $j ] ) ? ltrim( $tokens[ $j ][1], '\\' ) : '';
				continue;
			}
			if ( T_USE === $token[0] && 0 === $depth ) {
				$j = $next( $i );
				if ( $j < $count && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING ), true ) && 'function' !== $tokens[ $j ][1] && 'const' !== $tokens[ $j ][1] ) {
					$full  = ltrim( $tokens[ $j ][1], '\\' );
					$alias = substr( (string) strrchr( '\\' . $full, '\\' ), 1 );
					$k     = $next( $j );
					if ( $k < $count && is_array( $tokens[ $k ] ) && T_AS === $tokens[ $k ][0] ) {
						$alias = $text( $tokens[ $next( $k ) ] );
					}
					$uses[ strtolower( $alias ) ] = $full;
					$names[]                      = $full;
				}
				continue;
			}
			if ( ! in_array( $token[0], $name_ids, true ) ) {
				continue;
			}
			$before = $prev( $i );
			$after  = $next( $i );
			$is_ref = ( $after < $count && is_array( $tokens[ $after ] ) && T_DOUBLE_COLON === $tokens[ $after ][0] )
				|| ( $before >= 0 && is_array( $tokens[ $before ] ) && in_array( $tokens[ $before ][0], array( T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS ), true ) );
			if ( ! $is_ref ) {
				continue;
			}
			$name = $token[1];
			if ( in_array( strtolower( $name ), array( 'self', 'static', 'parent' ), true ) ) {
				continue;
			}
			if ( '\\' === $name[0] ) {
				$names[] = ltrim( $name, '\\' );
				continue;
			}
			$first = strtolower( strtok( $name, '\\' ) );
			if ( isset( $uses[ $first ] ) ) {
				$rest    = substr( $name, strlen( $first ) );
				$names[] = $uses[ $first ] . $rest;
			} else {
				$names[] = ( '' !== $namespace ? $namespace . '\\' : '' ) . $name;
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Copies a file or a folder, skipping SKIP.
	 *
	 * @param string $from A file or folder.
	 * @param string $to   Where it goes.
	 * @return true|WP_Error
	 */
	private static function copy( string $from, string $to ) {
		if ( is_file( $from ) ) {
			if ( ! wp_mkdir_p( dirname( $to ) ) || ! copy( $from, $to ) ) {
				return new WP_Error( 'mody_export_copy', 'Could not copy ' . $from );
			}
			return true;
		}
		if ( ! is_dir( $from ) ) {
			return new WP_Error( 'mody_export_missing', 'Missing: ' . $from );
		}
		if ( ! wp_mkdir_p( $to ) ) {
			return new WP_Error( 'mody_export_copy', 'Could not create ' . $to );
		}
		foreach ( new \FilesystemIterator( $from, \FilesystemIterator::SKIP_DOTS ) as $item ) {
			if ( in_array( $item->getFilename(), self::SKIP, true ) || $item->isLink() ) {
				continue;
			}
			$done = self::copy( $item->getPathname(), $to . '/' . $item->getFilename() );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		return true;
	}
}
