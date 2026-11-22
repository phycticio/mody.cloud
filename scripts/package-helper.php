<?php
/**
 * The two jobs of bin/package that are easier in PHP, run with the host's
 * PHP CLI (tickets/product-pages-bari-and-plugins.md, part 4). No WordPress.
 *
 *   php scripts/package-helper.php theme-fonts <theme folder> <mody-fonts folder>
 *     Copies the files of every family theme.json names without faces (Inter)
 *     from Mody Fonts into the theme's assets/fonts/<slug>/, and writes their
 *     faces into theme.json with file: addresses, as Take your site with you
 *     does (Export\Portable::bake_fonts()), so the theme needs no plugin.
 *
 *   php scripts/package-helper.php latest <downloads folder> <base URL>
 *     Writes latest.json from the zips in the folder: for each product, its
 *     newest version, its zip, its page, the requirements and that version's
 *     changelog from the readme.txt inside the zip. The update check of each
 *     plugin (UpdateCheck) reads "plugins"; "themes" lists the theme.
 *
 * @package Mody\Package
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

/**
 * Stops with a message.
 *
 * @param string $message What went wrong.
 */
function mody_package_die( string $message ): void {
	fwrite( STDERR, $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit( 1 );
}

/**
 * The readme's header fields and a version's changelog.
 *
 * @param string $readme  The readme.txt's text.
 * @param string $version The version whose changelog is wanted.
 * @return array{fields: array<string, string>, changelog: string}
 */
function mody_package_readme( string $readme, string $version ): array {
	$fields = array();
	foreach ( preg_split( '/\R/', $readme ) as $line ) {
		if ( preg_match( '/^(Requires at least|Tested up to|Requires PHP|Stable tag):\s*(.+?)\s*$/', $line, $m ) ) {
			$fields[ $m[1] ] = $m[2];
		}
	}
	$changelog = '';
	if ( preg_match( '/^== Changelog ==\s*$(.*?)(?=^== |\z)/ms', $readme, $section )
		&& preg_match( '/^= ' . preg_quote( $version, '/' ) . ' =\s*$(.*?)(?=^= |\z)/ms', $section[1], $entry ) ) {
		$changelog = trim( $entry[1] );
	}
	return array(
		'fields'    => $fields,
		'changelog' => $changelog,
	);
}

$command = $argv[1] ?? '';

if ( 'theme-fonts' === $command ) {
	list( , , $theme, $fonts ) = $argv + array( '', '', '', '' );
	define( 'ABSPATH', '/' ); // Fonts.php stops without it; nothing of WordPress runs.
	require $fonts . '/src/Fonts.php';
	$file = $theme . '/theme.json';
	$json = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $json ) ) {
		mody_package_die( 'Could not read ' . $file );
	}
	$families = (array) ( $json['settings']['typography']['fontFamilies'] ?? array() );
	foreach ( $families as $i => $family ) {
		$slug = (string) ( $family['slug'] ?? '' );
		if ( ! isset( \Mody\Fonts\Fonts::FAMILIES[ $slug ] ) || ! empty( $family['fontFace'] ) ) {
			continue;
		}
		$dir = $theme . '/assets/fonts/' . $slug;
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) ) {
			mody_package_die( 'Could not create ' . $dir );
		}
		$faces = array();
		foreach ( \Mody\Fonts\Fonts::FAMILIES[ $slug ]['faces'] as list( $face, $weight, $style ) ) {
			if ( ! copy( $fonts . '/fonts/' . $slug . '/' . $face, $dir . '/' . $face ) ) {
				mody_package_die( 'Could not copy ' . $slug . '/' . $face );
			}
			$faces[] = array(
				'fontFamily' => \Mody\Fonts\Fonts::FAMILIES[ $slug ]['name'],
				'fontStyle'  => $style,
				'fontWeight' => $weight,
				'src'        => array( 'file:./assets/fonts/' . $slug . '/' . $face ),
			);
		}
		if ( is_file( $fonts . '/fonts/' . $slug . '/LICENSE.txt' ) ) {
			copy( $fonts . '/fonts/' . $slug . '/LICENSE.txt', $dir . '/LICENSE.txt' );
		}
		$families[ $i ]['fontFace'] = $faces;
		echo $slug . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	$json['settings']['typography']['fontFamilies'] = array_values( $families );
	file_put_contents( $file, json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ) || mody_package_die( 'Could not write ' . $file );
	exit( 0 );
}

if ( 'latest' === $command ) {
	list( , , $dir, $base ) = $argv + array( '', '', '', '' );
	$base   = rtrim( $base, '/' ) . '/';
	$newest = array();
	foreach ( glob( $dir . '/mody-*.zip' ) as $zip ) {
		if ( ! preg_match( '/^mody-([a-z]+)-(\d+\.\d+\.\d+)\.zip$/', basename( $zip ), $m ) ) {
			continue;
		}
		if ( ! isset( $newest[ $m[1] ] ) || version_compare( $m[2], $newest[ $m[1] ]['version'], '>' ) ) {
			$newest[ $m[1] ] = array(
				'version' => $m[2],
				'zip'     => $zip,
			);
		}
	}
	ksort( $newest );
	$latest = array(
		'plugins' => array(),
		'themes'  => array(),
	);
	foreach ( $newest as $slug => $found ) {
		$archive = new ZipArchive();
		if ( true !== $archive->open( $found['zip'] ) ) {
			mody_package_die( 'Could not open ' . $found['zip'] );
		}
		$folder = 'mody-' . $slug;
		$readme = $archive->getFromName( $folder . '/readme.txt' );
		$archive->close();
		if ( false === $readme ) {
			mody_package_die( basename( $found['zip'] ) . ' has no ' . $folder . '/readme.txt' );
		}
		$read  = mody_package_readme( $readme, $found['version'] );
		$entry = array(
			'version'      => $found['version'],
			'package'      => $base . basename( $found['zip'] ),
			'url'          => 'theme' === $slug ? 'https://mody.cloud/plugins/' : 'https://mody.cloud/plugins/' . $slug . '/',
			'requires'     => $read['fields']['Requires at least'] ?? '',
			'tested'       => $read['fields']['Tested up to'] ?? '',
			'requires_php' => $read['fields']['Requires PHP'] ?? '',
			'changelog'    => $read['changelog'],
		);
		if ( 'theme' === $slug ) {
			$latest['themes'][ $folder ] = $entry;
		} else {
			$latest['plugins'][ $folder ] = $entry;
		}
	}
	file_put_contents( $dir . '/latest.json', json_encode( $latest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ) || mody_package_die( 'Could not write latest.json' );
	foreach ( $newest as $slug => $found ) {
		echo 'mody-' . $slug . ' ' . $found['version'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	exit( 0 );
}

mody_package_die( 'Usage: php scripts/package-helper.php theme-fonts <theme> <mody-fonts> | latest <folder> <base URL>' );
