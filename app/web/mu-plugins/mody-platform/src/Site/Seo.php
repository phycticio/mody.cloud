<?php
/**
 * A page's title and description for search engines.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Site;

defined( 'ABSPATH' ) || exit;

/**
 * On every site (2026-11-12):
 *
 * - **The description** is the page's (or post's) own excerpt, WordPress's
 *   field for a summary, printed as `<meta name="description">`. Nothing is
 *   printed when the excerpt is empty: search engines then pick their own
 *   snippet, which beats a cut-off first paragraph.
 * - **The SEO title** is the post meta `mody_seo_title`: the whole `<title>`,
 *   site name included, instead of WordPress's "Page – Site" (or "Site –
 *   Tagline" on the front page). Empty means WordPress's own.
 *
 * A translation (mody-languages) is its own page, so it has its own excerpt
 * and title.
 */
final class Seo {

	/** Post meta: the page's whole `<title>`. */
	public const TITLE_META = 'mody_seo_title';

	/** The longest description printed, in characters (search results show about 155). */
	private const DESCRIPTION_MAX = 160;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_meta' ) );
		add_filter( 'pre_get_document_title', array( self::class, 'title' ), 20 );
		add_action( 'wp_head', array( self::class, 'print_description' ), 1 );
	}

	/**
	 * `init`: the title meta on pages and posts, editable by who edits them.
	 */
	public static function register_meta(): void {
		foreach ( array( 'page', 'post' ) as $type ) {
			register_post_meta(
				$type,
				self::TITLE_META,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => array( self::class, 'clean' ),
					'auth_callback'     => static fn( $allowed, $key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
				)
			);
		}
	}

	/**
	 * One line of plain text.
	 *
	 * @param mixed $text A text.
	 */
	public static function clean( $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
	}

	/**
	 * `pre_get_document_title`: the page's SEO title, when it has one.
	 *
	 * @param string $title The title so far ('' lets WordPress build it).
	 */
	public static function title( $title ) {
		$post = self::page();
		$seo  = $post ? self::clean( get_post_meta( $post->ID, self::TITLE_META, true ) ) : '';
		return '' !== $seo ? esc_html( $seo ) : $title;
	}

	/**
	 * The page's description: its excerpt, as one line of at most
	 * DESCRIPTION_MAX characters; '' without one.
	 */
	public static function description(): string {
		$post = self::page();
		if ( ! $post || ! has_excerpt( $post ) || post_password_required( $post ) ) {
			return '';
		}
		$text = self::clean( $post->post_excerpt );
		if ( mb_strlen( $text ) > self::DESCRIPTION_MAX ) {
			$text = rtrim( (string) preg_replace( '/\s+\S*$/u', '', mb_substr( $text, 0, self::DESCRIPTION_MAX - 1 ) ), ' ,;:' ) . '…';
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
	 * The page or post being shown, if one is.
	 */
	private static function page(): ?\WP_Post {
		if ( ! is_singular( array( 'page', 'post' ) ) ) {
			return null;
		}
		$post = get_queried_object();
		return $post instanceof \WP_Post ? $post : null;
	}
}
