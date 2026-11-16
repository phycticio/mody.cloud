<?php
/**
 * The SEO title and description in the page's head.
 *
 * @package Mody\Seo
 */

namespace Mody\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * On a page or post, and on the posts page (Settings → Reading), which uses
 * its page's fields:
 *
 * - `<title>`: the SEO title, else WordPress's own.
 * - `<meta name="description">`: the SEO description, else the excerpt, cut
 *   at DESCRIPTION_SHOWN characters at a word; none with neither, and none on
 *   a password-protected page.
 *
 * The `ai` plugin's own description tag is switched off, so there is one.
 * A front page that shows the latest posts has no page: WordPress's title
 * (site title and tagline) and no description.
 */
final class Head {

	/** The longest description printed, in characters (search results show about 155). */
	private const DESCRIPTION_SHOWN = 160;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'pre_get_document_title', array( self::class, 'title' ), 20 );
		add_action( 'wp_head', array( self::class, 'print_description' ), 1 );
		add_filter( 'wpai_meta_description', '__return_empty_string', 99 ); // The `ai` plugin's tag.
	}

	/**
	 * `pre_get_document_title`: the page's SEO title, when it has one.
	 *
	 * @param string $title The title so far ('' lets WordPress build it).
	 */
	public static function title( $title ) {
		$post = self::page();
		$seo  = $post ? Fields::seo_title( $post ) : '';
		return '' !== $seo ? esc_html( $seo ) : $title;
	}

	/**
	 * The page's description as printed; '' without one.
	 */
	public static function description(): string {
		$post = self::page();
		if ( ! $post || post_password_required( $post ) ) {
			return '';
		}
		$text = Fields::description( $post );
		if ( mb_strlen( $text ) > self::DESCRIPTION_SHOWN ) {
			$text = rtrim( (string) preg_replace( '/\s+\S*$/u', '', mb_substr( $text, 0, self::DESCRIPTION_SHOWN - 1 ) ), ' ,;:' ) . '…';
		}
		return $text;
	}

	/**
	 * `wp_head`: the description.
	 */
	public static function print_description(): void {
		$text = self::description();
		if ( '' !== $text ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $text ) );
		}
	}

	/**
	 * The page or post being shown, if one is; on the posts page, that page.
	 */
	private static function page(): ?\WP_Post {
		if ( is_home() && ! is_front_page() && get_option( 'page_for_posts' ) ) {
			$post = get_post( (int) get_option( 'page_for_posts' ) );
			return $post instanceof \WP_Post && 'publish' === $post->post_status ? $post : null;
		}
		if ( ! is_singular( Fields::post_types() ) ) {
			return null;
		}
		$post = get_queried_object();
		return $post instanceof \WP_Post ? $post : null;
	}
}
