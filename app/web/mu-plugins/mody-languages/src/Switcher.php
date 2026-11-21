<?php
/**
 * The mody/language-switcher block.
 *
 * - Server-rendered: a list of the site's languages, each linking to the
 *   current page's published translation, or to that language's home page.
 * - Two settings (tickets/language-switcher-settings.md): the layout, links
 *   in a row or a dropdown (a <details> element, so it works without
 *   JavaScript; view.js closes it on Escape or a click outside), and what
 *   each language shows: its name, its flag or both. The flags are the
 *   block's own SVGs (blocks/language-switcher/flags/), inlined: nothing is
 *   loaded from outside.
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

	/** The layouts: links in a row (the default), or a dropdown. */
	public const LAYOUTS = array( 'list', 'dropdown' );

	/** What each language shows: its name (the default), its flag, or both. */
	public const DISPLAYS = array( 'text', 'flag', 'both' );

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
		// The style's and the view script's version is their newest file's time, so proxies never keep an old copy (core uses WordPress's version).
		$version = static function ( array $metadata ) use ( $root ): array {
			if ( 'mody/language-switcher' === ( $metadata['name'] ?? '' ) ) {
				$metadata['version'] = (string) max( (int) filemtime( $root . '/blocks/language-switcher/style.css' ), (int) filemtime( $root . '/blocks/language-switcher/view.js' ) );
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
	 * A language's flag: the block's own SVG, or '' when it has none.
	 *
	 * @param string $locale A locale.
	 */
	public static function flag( string $locale ): string {
		static $flags = array();
		$slug = Languages::slug( $locale );
		if ( ! isset( $flags[ $slug ] ) ) {
			$file           = dirname( __DIR__ ) . '/blocks/language-switcher/flags/' . $slug . '.svg';
			$flags[ $slug ] = preg_match( '/^[a-z]{2,3}$/', $slug ) && is_readable( $file ) ? trim( (string) file_get_contents( $file ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own file.
		}
		return $flags[ $slug ];
	}

	/**
	 * What a language shows: its flag, its name, and its short code for
	 * phones when it has no flag. The name is always there, for screen
	 * readers when only the flag shows.
	 *
	 * @param string $locale  A locale.
	 * @param string $display One of DISPLAYS.
	 */
	private static function label_html( string $locale, string $display ): string {
		$flag = 'text' === $display ? '' : self::flag( $locale );
		$html = '' === $flag ? '' : '<span class="wp-block-mody-language-switcher__flag" aria-hidden="true">' . $flag . '</span>';
		$html .= sprintf( '<span class="wp-block-mody-language-switcher__name">%s</span>', esc_html( Languages::label( $locale ) ) );
		if ( '' === $flag ) {
			$html .= sprintf( '<span class="wp-block-mody-language-switcher__code" aria-hidden="true">%s</span>', esc_html( strtoupper( Languages::slug( $locale ) ) ) );
		}
		return $html;
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

		$layout  = in_array( $attributes['layout'] ?? '', self::LAYOUTS, true ) ? $attributes['layout'] : 'list';
		$display = in_array( $attributes['display'] ?? '', self::DISPLAYS, true ) ? $attributes['display'] : 'text';
		$current = Router::current();
		$items   = '';
		foreach ( Languages::site_locales() as $locale ) {
			$is_current = $locale === $current;
			if ( $is_current && empty( $attributes['showCurrent'] ) && isset( $attributes['showCurrent'] ) ) {
				continue;
			}
			$items .= sprintf(
				'<li><a href="%s" lang="%s"%s%s>%s</a></li>',
				esc_url( $preview ? Router::home( $locale ) : self::target( $locale ) ),
				esc_attr( Languages::code( $locale ) ),
				'flag' === $display && '' !== self::flag( $locale ) ? ' title="' . esc_attr( Languages::label( $locale ) ) . '"' : '',
				$is_current ? ' aria-current="page"' : '',
				self::label_html( $locale, $display )
			);
		}

		$list = '<ul>' . $items . '</ul>';
		if ( 'dropdown' === $layout ) {
			$list = sprintf(
				'<details class="wp-block-mody-language-switcher__dropdown"><summary lang="%s"%s>%s<span class="wp-block-mody-language-switcher__chevron" aria-hidden="true"></span></summary>%s</details>',
				esc_attr( Languages::code( $current ) ),
				'flag' === $display && '' !== self::flag( $current ) ? ' title="' . esc_attr( Languages::label( $current ) ) . '"' : '',
				self::label_html( $current, $display ),
				$list
			);
		}
		return sprintf(
			'<nav %s aria-label="%s">%s</nav>',
			get_block_wrapper_attributes( array( 'class' => ( 'dropdown' === $layout ? 'is-dropdown ' : '' ) . 'is-display-' . $display ) ),
			esc_attr__( 'Languages', 'mody_cloud' ),
			$list
		);
	}
}
