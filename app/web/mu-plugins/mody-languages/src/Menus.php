<?php
/**
 * Menus and home links follow the request's language.
 *
 * - A navigation link (or submenu) to a page or post leads to its published
 *   translation in the request's language, and shows the translation's
 *   title, unless the link has a label of its own.
 * - The site title, the site logo and the home link lead to the language's
 *   home page.
 * - Lists of pages on the front end (the page list block, the navigation's
 *   fallback) show only the request's language.
 *
 * Nothing changes in the default language, or on a site with one language.
 *
 * @package Mody\Languages
 */

namespace Mody\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites menu and home links while a page is rendered.
 */
final class Menus {

	/** The navigation blocks whose link can lead to a page. */
	public const LINK_BLOCKS = array( 'core/navigation-link', 'core/navigation-submenu' );

	/**
	 * Hooked on load.
	 */
	public static function register(): void {
		foreach ( self::LINK_BLOCKS as $name ) {
			add_filter( 'render_block_' . $name, array( self::class, 'link_html' ), 10, 3 );
		}
		add_filter( 'render_block_core/site-title', array( self::class, 'home_link' ) );
		add_filter( 'render_block_core/site-logo', array( self::class, 'home_link' ) );
		add_filter( 'render_block_core/home-link', array( self::class, 'home_link' ) );
		add_filter( 'get_pages', array( self::class, 'pages_in_language' ), 10, 2 );
	}

	/**
	 * `get_pages`: on the front end of a multilingual site, only the pages in
	 * the request's language. Other post types are left alone.
	 *
	 * @param \WP_Post[]           $pages Pages.
	 * @param array<string, mixed> $args  get_pages() arguments.
	 * @return \WP_Post[]
	 */
	public static function pages_in_language( $pages, $args = array() ) {
		if ( is_admin() || wp_is_serving_rest_request() || ! is_array( $pages ) || ! Languages::is_multilingual() ) {
			return $pages;
		}
		if ( ! in_array( (string) ( $args['post_type'] ?? 'page' ), Translations::POST_TYPES, true ) ) {
			return $pages;
		}
		$current = Router::current();
		return array_values(
			array_filter(
				$pages,
				static fn( $page ): bool => ! $page instanceof \WP_Post || ! in_array( $page->post_type, Translations::POST_TYPES, true ) || Translations::language( $page ) === $current
			)
		);
	}

	/**
	 * A navigation link's attributes in the request's language: the
	 * translation's ID, address and (unless the link has a label of its own)
	 * title. Null when nothing changes. Both ways: a link to a translation
	 * leads default-language visitors to the original (a link picked while
	 * working in another language, which the link search then offers).
	 *
	 * @param array<string, mixed> $attrs A navigation link's attributes.
	 * @return array<string, mixed>|null
	 */
	public static function translate_attrs( array $attrs ): ?array {
		if ( ! Languages::is_multilingual() ) {
			return null;
		}
		$id = (int) ( $attrs['id'] ?? 0 );
		if ( ! $id || 'post-type' !== ( $attrs['kind'] ?? '' ) || ! in_array( get_post_type( $id ), Translations::POST_TYPES, true ) ) {
			return null;
		}
		$translation = Translations::in( $id, Router::current() );
		if ( ! $translation || $translation === $id ) {
			return null;
		}
		$label = (string) ( $attrs['label'] ?? '' );
		if ( '' === $label || wp_strip_all_tags( $label ) === wp_strip_all_tags( get_the_title( $id ) ) ) {
			$attrs['label'] = get_the_title( $translation );
		}
		$attrs['id']  = $translation;
		$attrs['url'] = (string) get_permalink( $translation );
		return $attrs;
	}

	/**
	 * `render_block_core/navigation-link` and `…/navigation-submenu`: the
	 * rendered link leads to the translation. Works for menus stored as
	 * navigation posts too, whose links core renders without
	 * render_block_data.
	 *
	 * @param string          $html     The rendered block.
	 * @param array           $parsed   The parsed block.
	 * @param \WP_Block|null $instance The block instance.
	 */
	public static function link_html( $html, $parsed = array(), $instance = null ) {
		$attrs = $instance instanceof \WP_Block ? (array) $instance->attributes : (array) ( $parsed['attrs'] ?? array() );
		$new   = self::translate_attrs( $attrs );
		if ( null === $new || '' === (string) $html ) {
			return $html;
		}
		$tags = new \WP_HTML_Tag_Processor( (string) $html );
		if ( ! $tags->next_tag( 'a' ) ) {
			return $html;
		}
		$tags->set_attribute( 'href', $new['url'] );
		$html = $tags->get_updated_html();
		if ( ( $new['label'] ?? '' ) !== ( $attrs['label'] ?? '' ) ) {
			$html = (string) preg_replace(
				'#(<span class="wp-block-navigation-item__label">).*?(</span>)#s',
				'${1}' . str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), esc_html( wp_strip_all_tags( (string) $new['label'] ) ) ) . '${2}',
				$html,
				1
			);
		}
		return $html;
	}

	/**
	 * `render_block_core/site-title`, `…/site-logo`, `…/home-link`: the link to
	 * the home page leads to the language's home page.
	 *
	 * @param string $html The rendered block.
	 */
	public static function home_link( $html ) {
		if ( ! Router::is_other() ) {
			return $html;
		}
		$target = esc_url( Router::home( Router::current() ) );
		$homes  = array_unique( array( esc_url( home_url() ), esc_url( home_url( '/' ) ) ) );
		foreach ( $homes as $home ) {
			$html = str_replace( 'href="' . $home . '"', 'href="' . $target . '"', (string) $html );
		}
		return $html;
	}
}
