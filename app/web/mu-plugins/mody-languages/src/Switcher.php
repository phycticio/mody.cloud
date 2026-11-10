<?php
/**
 * The mody/language-switcher block.
 *
 * - Server-rendered: a list of the site's languages, each linking to the
 *   current page's published translation, or to that language's home page.
 * - Placed after every navigation block by a block hook (block.json), so
 *   every header gets it, but only on a site with more than one language: on
 *   others the hook is dropped, so templates, the editors and the agent see
 *   exactly what they would without this plugin.
 * - The editors show a server-rendered preview (assets/language-switcher-block.js).
 *
 * @package Mody\Languages
 */

namespace Mody\Languages;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the language switcher.
 */
final class Switcher {

	public const EDITOR = 'mody-language-switcher-editor';

	/**
	 * Hooked on load.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
		add_filter( 'hooked_block_types', array( self::class, 'hook_only_when_multilingual' ) );
	}

	/**
	 * `hooked_block_types`: no switcher on a site with one language.
	 *
	 * @param string[] $types The blocks hooked to an anchor block.
	 * @return string[]
	 */
	public static function hook_only_when_multilingual( $types ) {
		if ( ! is_array( $types ) || Languages::is_multilingual() ) {
			return $types;
		}
		return array_values( array_diff( $types, array( 'mody/language-switcher' ) ) );
	}

	/**
	 * `init`: the editor script, then the block from its block.json.
	 */
	public static function register_block(): void {
		$root = dirname( __DIR__ );
		$file = $root . '/assets/language-switcher-block.js';
		wp_register_script(
			self::EDITOR,
			plugins_url( 'assets/language-switcher-block.js', $root . '/mody-languages.php' ),
			array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
			file_exists( $file ) ? (string) filemtime( $file ) : '1',
			true
		);
		// The style's version is its file's time, so proxies never keep an old copy (core uses WordPress's version).
		$version = static function ( array $metadata ) use ( $root ): array {
			if ( 'mody/language-switcher' === ( $metadata['name'] ?? '' ) ) {
				$metadata['version'] = (string) filemtime( $root . '/blocks/language-switcher/style.css' );
			}
			return $metadata;
		};
		add_filter( 'block_type_metadata', $version );
		register_block_type( $root . '/blocks/language-switcher', array( 'render_callback' => array( self::class, 'render' ) ) );
		remove_filter( 'block_type_metadata', $version );
	}

	/**
	 * Where a language's link leads from the current page.
	 *
	 * @param string $locale One of the site's languages.
	 */
	public static function target( string $locale ): string {
		if ( is_singular( Translations::POST_TYPES ) ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post ) {
				$id = Translations::in( $post, $locale );
				if ( $id ) {
					return (string) get_permalink( $id );
				}
			}
		}
		return Router::home( $locale );
	}

	/**
	 * The block's markup.
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 */
	public static function render( array $attributes = array() ): string {
		$preview = defined( 'REST_REQUEST' ) && REST_REQUEST;
		if ( ! Languages::is_multilingual() ) {
			if ( ! $preview ) {
				return '';
			}
			return sprintf(
				'<div %s><p>%s</p></div>',
				get_block_wrapper_attributes(),
				esc_html__( 'Language switcher: it appears once the site has more than one language (Settings → Languages).', 'mody_cloud' )
			);
		}

		$current = Router::current();
		$items   = '';
		foreach ( Languages::site_locales() as $locale ) {
			$is_current = $locale === $current;
			if ( $is_current && empty( $attributes['showCurrent'] ) && isset( $attributes['showCurrent'] ) ) {
				continue;
			}
			$items .= sprintf(
				'<li><a href="%s" lang="%s"%s><span class="wp-block-mody-language-switcher__name">%s</span><span class="wp-block-mody-language-switcher__code" aria-hidden="true">%s</span></a></li>',
				esc_url( $preview ? Router::home( $locale ) : self::target( $locale ) ),
				esc_attr( Languages::code( $locale ) ),
				$is_current ? ' aria-current="page"' : '',
				esc_html( Languages::label( $locale ) ),
				esc_html( strtoupper( Languages::slug( $locale ) ) )
			);
		}
		return sprintf(
			'<nav %s aria-label="%s"><ul>%s</ul></nav>',
			get_block_wrapper_attributes(),
			esc_attr__( 'Languages', 'mody_cloud' ),
			$items
		);
	}
}
