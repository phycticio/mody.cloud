<?php
/**
 * A page's two SEO fields, and what search results show without them.
 *
 * @package Mody\Seo
 */

namespace Mody\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Two post meta fields on every public post type (pages and posts):
 *
 * - `mody_seo_title`: the whole `<title>`, site name included. Empty means
 *   WordPress's own, built from the page's title ("Page title – Site", or
 *   "Site – Tagline" on the front page).
 * - `mody_seo_description`: the meta description. Empty means the page's
 *   excerpt; with no excerpt either, there is none.
 *
 * Both are plain text, saved by whoever may edit the page, through REST (the
 * sidebar) or PHP (the agent). They are hidden from the Custom Fields box.
 * A translation (Mody Languages) is its own page with its own fields; its
 * excerpt is ignored while it is still the copy of its original's.
 */
final class Fields {

	/** Post meta: the page's whole `<title>`. */
	public const TITLE = 'mody_seo_title';

	/** Post meta: the page's meta description. */
	public const DESCRIPTION = 'mody_seo_description';

	/** The longest SEO title saved, in characters (search results show about 60). */
	public const TITLE_MAX = 120;

	/** The longest SEO description saved, in characters (search results show about 155). */
	public const DESCRIPTION_MAX = 300;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_meta' ), 20 ); // After the post types.
		add_filter( 'is_protected_meta', array( self::class, 'protect' ), 10, 2 );
	}

	/**
	 * The post types with SEO fields: the public ones the editor edits.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Whether a post has SEO fields.
	 *
	 * @param \WP_Post $post A post.
	 */
	public static function applies( \WP_Post $post ): bool {
		return in_array( $post->post_type, self::post_types(), true );
	}

	/**
	 * `init`: the two fields, in REST for the editor.
	 */
	public static function register_meta(): void {
		$fields = array(
			self::TITLE       => self::TITLE_MAX,
			self::DESCRIPTION => self::DESCRIPTION_MAX,
		);
		foreach ( self::post_types() as $type ) {
			foreach ( $fields as $key => $max ) {
				register_post_meta(
					$type,
					$key,
					array(
						'type'              => 'string',
						'single'            => true,
						'default'           => '',
						'show_in_rest'      => true,
						'sanitize_callback' => static fn( $value ): string => mb_substr( self::clean( $value ), 0, $max ),
						'auth_callback'     => static fn( $allowed, $meta_key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
					)
				);
			}
		}
	}

	/**
	 * `is_protected_meta`: not in the Custom Fields box; the sidebar edits them.
	 *
	 * @param bool   $protected Whether the key is protected.
	 * @param string $key       A meta key.
	 */
	public static function protect( $protected, $key ): bool {
		return in_array( $key, array( self::TITLE, self::DESCRIPTION ), true ) || (bool) $protected;
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
	 * The page's SEO title field; '' when empty.
	 *
	 * @param \WP_Post $post A page or post.
	 */
	public static function seo_title( \WP_Post $post ): string {
		return self::clean( get_post_meta( $post->ID, self::TITLE, true ) );
	}

	/**
	 * The page's SEO description field; '' when empty.
	 *
	 * @param \WP_Post $post A page or post.
	 */
	public static function seo_description( \WP_Post $post ): string {
		return self::clean( get_post_meta( $post->ID, self::DESCRIPTION, true ) );
	}

	/**
	 * The `<title>` search results show: the SEO title, else WordPress's own.
	 *
	 * @param \WP_Post $post A page or post.
	 */
	public static function title( \WP_Post $post ): string {
		$title = self::seo_title( $post );
		return '' !== $title ? $title : self::automatic_title( $post );
	}

	/**
	 * The description search results show, uncut: the SEO description, else
	 * the excerpt; '' with neither.
	 *
	 * @param \WP_Post $post A page or post.
	 */
	public static function description( \WP_Post $post ): string {
		$description = self::seo_description( $post );
		return '' !== $description ? $description : self::excerpt( $post );
	}

	/**
	 * The `<title>` WordPress builds without an SEO title, from the page's
	 * title (from the site title and tagline on the front page).
	 *
	 * @param \WP_Post $post A page or post.
	 */
	public static function automatic_title( \WP_Post $post ): string {
		$site = self::plain( (string) get_bloginfo( 'name' ) );
		if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $post->ID ) {
			$tagline = self::plain( (string) get_bloginfo( 'description' ) );
			return '' === $tagline ? $site : $site . ' – ' . $tagline;
		}
		return self::plain( get_the_title( $post ) ) . ' – ' . $site;
	}

	/**
	 * The page's own excerpt, the description's fallback; '' when it has none
	 * or when a translation's is still its original's (the wrong language).
	 *
	 * @param \WP_Post $post A page or post.
	 */
	public static function excerpt( \WP_Post $post ): string {
		$excerpt = self::clean( $post->post_excerpt );
		if ( '' === $excerpt || ! class_exists( '\Mody\Languages\Translations' ) ) {
			return $excerpt;
		}
		$source = \Mody\Languages\Translations::source( $post );
		return $source !== $post->ID && self::clean( get_post_field( 'post_excerpt', $source ) ) === $excerpt ? '' : $excerpt;
	}

	/**
	 * A page's search appearance, for whoever explains or changes it.
	 *
	 * @param \WP_Post $post A page or post.
	 * @return array{title: string, automatic_title: string, description: string, excerpt: string}
	 *         The two fields ('' when empty) and what is used without them.
	 */
	public static function state( \WP_Post $post ): array {
		return array(
			'title'           => self::seo_title( $post ),
			'automatic_title' => self::automatic_title( $post ),
			'description'     => self::seo_description( $post ),
			'excerpt'         => self::excerpt( $post ),
		);
	}

	/**
	 * A title as plain text: entities decoded, one line.
	 *
	 * @param string $text A title.
	 */
	private static function plain( string $text ): string {
		return self::clean( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
	}
}
