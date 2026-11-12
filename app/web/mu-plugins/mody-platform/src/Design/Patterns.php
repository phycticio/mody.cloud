<?php
/**
 * The Mody patterns collection: the design market (ticket
 * design-market-themes-patterns.md).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Design;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Every site's patterns come from the platform, not WordPress.org (built
 * 2026-11-08).
 *
 * - **The collection:** `patterns/collection.json` in this plugin, written by
 *   scripts/import-patterns.php from the WordPress.org Pattern Directory and
 *   core's bundled patterns (all GPL), rewritten by PatternImport: palette
 *   colors, the theme's fonts, placeholder images. Registered as `mody/…`
 *   with the source `plugin`, so they show on the Site Editor's Patterns
 *   screen as well as in the inserter (WordPress hides core and Directory
 *   patterns on that screen).
 * - **Nothing remote:** `should_load_remote_block_patterns` is off, core's
 *   bundled patterns are replaced by their rewritten copies, and the
 *   Directory proxy (`/wp/v2/pattern-directory/…`) is refused to everyone
 *   but super admins, who run the import.
 * - **Placeholders:** `/mody-placeholder/<width>x<height>.svg` on every site,
 *   an SVG made here: no third-party image service (rule 1).
 */
final class Patterns {

	/** The collection file, in this plugin. */
	public const FILE = 'patterns/collection.json';

	/** Where placeholder images are served: `/mody-placeholder/<w>x<h>.svg`. */
	public const PLACEHOLDER_PATH = '/mody-placeholder/';

	/** Prefix of the registered pattern names. */
	public const PREFIX = 'mody/';

	/** Categories the collection uses that WordPress does not register: slug => label. */
	private const CATEGORIES = array(
		'wireframe' => 'Wireframes',
	);

	/** Whether the collection was registered in this request. */
	private static bool $registered = false;

	/**
	 * The collection: `generated`, `license`, `patterns` (each with `name`,
	 * `title`, `description`, `categories`, `keywords`, `viewportWidth`,
	 * `blockTypes`, `content` and `source`).
	 *
	 * @return array<string, mixed>
	 */
	public static function collection(): array {
		$file = dirname( __DIR__, 2 ) . '/' . self::FILE;
		if ( ! is_readable( $file ) ) {
			return array( 'patterns' => array() );
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
		return is_array( $data ) ? $data : array( 'patterns' => array() );
	}

	/**
	 * `rest_api_init`: registers the collection. The editors read patterns
	 * through REST (and preload them through it), so nothing else needs them.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		foreach ( self::CATEGORIES as $slug => $label ) {
			if ( ! \WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( $slug ) ) {
				register_block_pattern_category( $slug, array( 'label' => $label ) );
			}
		}
		foreach ( (array) ( self::collection()['patterns'] ?? array() ) as $pattern ) {
			$properties = array(
				'title'      => (string) $pattern['title'],
				'content'    => (string) $pattern['content'],
				'categories' => (array) ( $pattern['categories'] ?? array() ),
				'keywords'   => (array) ( $pattern['keywords'] ?? array() ),
				'source'     => 'plugin',
			);
			if ( ! empty( $pattern['description'] ) ) {
				$properties['description'] = (string) $pattern['description'];
			}
			if ( ! empty( $pattern['viewportWidth'] ) ) {
				$properties['viewportWidth'] = (int) $pattern['viewportWidth'];
			}
			if ( ! empty( $pattern['blockTypes'] ) ) {
				$properties['blockTypes'] = (array) $pattern['blockTypes'];
			}
			register_block_pattern( self::PREFIX . $pattern['name'], $properties );
		}
	}

	/**
	 * `after_setup_theme` (late): core's bundled patterns are in the
	 * collection, rewritten; their originals use custom colors.
	 */
	public static function remove_core_patterns(): void {
		remove_theme_support( 'core-block-patterns' );
	}

	/**
	 * `rest_pre_dispatch`: the Pattern Directory proxy talks to WordPress.org;
	 * only super admins may use it (to run the import).
	 *
	 * @param mixed           $result  The response so far.
	 * @param mixed           $server  The REST server.
	 * @param WP_REST_Request $request The request.
	 * @return mixed
	 */
	public static function refuse_directory( $result, $server, $request ) {
		// The REST server matches routes regardless of case (`/wp/v2/Pattern-Directory`), so this does too.
		if ( $request instanceof WP_REST_Request && str_starts_with( strtolower( (string) $request->get_route() ), '/wp/v2/pattern-directory' ) && ! is_super_admin() ) {
			return new WP_Error( 'mody_pattern_directory_off', __( 'Patterns come from the Mody collection on this platform.', 'mody_cloud' ), array( 'status' => 403 ) );
		}
		return $result;
	}

	/**
	 * Serves a placeholder image, when this request is for one, and stops.
	 * Called when the MU plugin loads, before the rest of WordPress runs.
	 */
	public static function serve_placeholder(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		$path   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$size   = self::placeholder_size( $path );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || null === $size ) {
			return;
		}
		header( 'Content-Type: image/svg+xml; charset=utf-8' );
		header( 'Cache-Control: public, max-age=31536000, immutable' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( 'HEAD' !== $method ) {
			echo self::svg( $size[0], $size[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from two integers.
		}
		exit;
	}

	/**
	 * The size a placeholder path asks for, or null when it is not one.
	 *
	 * @param string $path A request path, such as `/mody-placeholder/1200x800.svg`.
	 * @return array{0: int, 1: int}|null
	 */
	public static function placeholder_size( string $path ): ?array {
		if ( ! preg_match( '#^' . preg_quote( self::PLACEHOLDER_PATH, '#' ) . '(\d{1,4})x(\d{1,4})\.svg$#', $path, $match ) ) {
			return null;
		}
		$width  = (int) $match[1];
		$height = (int) $match[2];
		return $width >= 16 && $height >= 16 && $width <= 4000 && $height <= 4000 ? array( $width, $height ) : null;
	}

	/**
	 * A placeholder: a neutral box with a picture sign and its size, made
	 * here. Neutral greys, not the palette: it stands for a photo, and works
	 * on any background.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 */
	public static function svg( int $width, int $height ): string {
		$u      = max( 8, min( $width, $height ) / 8 ); // One unit of the drawing.
		$cx     = $width / 2;
		$cy     = $height / 2 - $u / 2;
		$points = array(
			array( $cx - 2 * $u, $cy + $u ),
			array( $cx - 0.7 * $u, $cy - 0.6 * $u ),
			array( $cx + 0.2 * $u, $cy + 0.3 * $u ),
			array( $cx + 0.9 * $u, $cy - 0.2 * $u ),
			array( $cx + 2 * $u, $cy + $u ),
		);
		$font   = max( 10, $u / 2 );
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" role="img" aria-label="%3$s">'
			. '<rect width="%1$d" height="%2$d" fill="#d6dadf"/>'
			. '<polygon points="%4$s" fill="#b8bec6"/>'
			. '<circle cx="%5$.1f" cy="%6$.1f" r="%7$.1f" fill="#b8bec6"/>'
			. '<text x="%8$.1f" y="%9$.1f" font-family="system-ui, sans-serif" font-size="%10$.1f" fill="#6b7280" text-anchor="middle">%3$s</text>'
			. '</svg>',
			$width,
			$height,
			$width . ' × ' . $height,
			implode( ' ', array_map( static fn( array $p ): string => sprintf( '%.1f,%.1f', $p[0], $p[1] ), $points ) ),
			$cx + 1.2 * $u,
			$cy - 0.8 * $u,
			max( 3, $u / 3 ),
			$cx,
			$cy + $u + 1.6 * $font,
			$font
		);
	}
}
