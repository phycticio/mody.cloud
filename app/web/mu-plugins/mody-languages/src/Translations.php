<?php
/**
 * Translations: ordinary pages and posts linked by two post meta fields.
 *
 * - mody_lang: the translation's locale. Without it, a page is in the site's
 *   default language.
 * - mody_source: the original's ID. Originals are always in the default
 *   language; a translation of a translation points to the first original.
 *
 * Both are protected meta: not in REST, not in the Custom Fields panel, and
 * writable only by site administrators. Only this plugin (the matrix, the
 * agent's ability, WP-CLI) sets them.
 *
 * @package Mody\Languages
 */

namespace Mody\Languages;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and creates translations.
 */
final class Translations {

	public const LANG   = 'mody_lang';
	public const SOURCE = 'mody_source';

	/** The post types that can be translated. */
	public const POST_TYPES = array( 'page', 'post' );

	/**
	 * Hooked on load: registers the two meta fields.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_meta' ) );
		add_filter( 'is_protected_meta', array( self::class, 'protect' ), 10, 2 );
	}

	/**
	 * `is_protected_meta`: the two fields are hidden from the Custom Fields
	 * panel and from XML-RPC.
	 *
	 * @param bool   $protected Whether the key is protected.
	 * @param string $key       A meta key.
	 */
	public static function protect( $protected, $key ) {
		return $protected || in_array( $key, array( self::LANG, self::SOURCE ), true );
	}

	/**
	 * A stored language, kept only when it is an offered one.
	 *
	 * @param mixed $value The value.
	 */
	public static function sanitize_language( $value ): string {
		$value = sanitize_text_field( (string) $value );
		return isset( Languages::OFFERED[ $value ] ) ? $value : '';
	}

	/**
	 * Only site administrators may write the fields through WordPress's meta
	 * APIs that check (REST, XML-RPC, the Custom Fields panel).
	 */
	public static function can_write(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * `init`: the meta fields.
	 */
	public static function register_meta(): void {
		foreach ( self::POST_TYPES as $type ) {
			register_post_meta(
				$type,
				self::LANG,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => array( self::class, 'sanitize_language' ),
					'auth_callback'     => array( self::class, 'can_write' ),
				)
			);
			register_post_meta(
				$type,
				self::SOURCE,
				array(
					'type'              => 'integer',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'absint',
					'auth_callback'     => array( self::class, 'can_write' ),
				)
			);
		}
	}

	/**
	 * A post's language: its mody_lang, or the site's default language.
	 *
	 * @param int|WP_Post $post A post.
	 */
	public static function language( int|WP_Post $post ): string {
		$id   = $post instanceof WP_Post ? $post->ID : $post;
		$lang = (string) get_post_meta( $id, self::LANG, true );
		return '' === $lang ? Languages::default_locale() : $lang;
	}

	/**
	 * The original of a post: its mody_source, or itself.
	 *
	 * @param int|WP_Post $post A post.
	 */
	public static function source( int|WP_Post $post ): int {
		$id     = $post instanceof WP_Post ? $post->ID : $post;
		$source = (int) get_post_meta( $id, self::SOURCE, true );
		return $source > 0 ? $source : $id;
	}

	/**
	 * A post and its translations: locale => post ID, the original first.
	 * Trashed posts are left out.
	 *
	 * @param int|WP_Post $post A post or one of its translations.
	 * @param bool        $published_only Only published posts.
	 * @return array<string, int>
	 */
	public static function group( int|WP_Post $post, bool $published_only = false ): array {
		$source = self::source( $post );
		$type   = get_post_type( $source );
		$status = $published_only ? array( 'publish' ) : array( 'publish', 'draft', 'pending', 'private', 'future' );
		$group  = array();
		if ( in_array( get_post_status( $source ), $status, true ) ) {
			$group[ self::language( $source ) ] = $source;
		}
		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPES,
				'post_status'      => $status,
				'meta_key'         => self::SOURCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A site's translations are few.
				'meta_value'       => $source, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'           => 'ids',
				'numberposts'      => 100,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		foreach ( $ids as $id ) {
			$lang = self::language( (int) $id );
			if ( get_post_type( (int) $id ) === $type && isset( Languages::OFFERED[ $lang ] ) && ! isset( $group[ $lang ] ) ) {
				$group[ $lang ] = (int) $id;
			}
		}
		return $group;
	}

	/**
	 * A post's translation into a language, or 0.
	 *
	 * @param int|WP_Post $post           A post or one of its translations.
	 * @param string      $locale         The language.
	 * @param bool        $published_only Only a published translation.
	 */
	public static function in( int|WP_Post $post, string $locale, bool $published_only = true ): int {
		return self::group( $post, $published_only )[ $locale ] ?? 0;
	}

	/**
	 * The translation's state against its original: draft, outdated (the
	 * original was saved after the translation) or translated.
	 *
	 * @param int $translation_id A translation.
	 */
	public static function state( int $translation_id ): string {
		$translation = get_post( $translation_id );
		$source      = $translation ? get_post( self::source( $translation ) ) : null;
		if ( ! $translation || ! $source ) {
			return 'missing';
		}
		if ( 'publish' !== $translation->post_status ) {
			return 'draft';
		}
		return strtotime( $source->post_modified_gmt ) > strtotime( $translation->post_modified_gmt ) ? 'outdated' : 'translated';
	}

	/**
	 * Creates a draft translation: a copy of the original's title, content,
	 * excerpt, password and template, under the parent's translation when
	 * there is one. Its slug is the original's plus the language (about-es);
	 * from the title when the original has no slug yet (a draft).
	 *
	 * @param int         $post_id A post in the default language, or a translation of one.
	 * @param string      $locale  One of the site's other languages.
	 * @param string|null $content Content for the copy; the original's when null.
	 * @param string|null $title   Title for the copy; the original's when null.
	 * @return int|WP_Error The new translation's ID.
	 */
	public static function create( int $post_id, string $locale, ?string $content = null, ?string $title = null ): int|WP_Error {
		$source = get_post( self::source( $post_id ) );
		if ( ! $source || ! in_array( $source->post_type, self::POST_TYPES, true ) || 'trash' === $source->post_status ) {
			return new WP_Error( 'mody_languages_no_original', __( 'This page cannot be translated.', 'mody_cloud' ) );
		}
		if ( ! Languages::is_other( $locale ) ) {
			return new WP_Error( 'mody_languages_not_a_language', __( 'This is not one of the site\'s other languages.', 'mody_cloud' ) );
		}
		if ( self::in( $source, $locale, false ) ) {
			return new WP_Error( 'mody_languages_exists', __( 'This page already has a translation in that language.', 'mody_cloud' ) );
		}

		$parent = $source->post_parent ? self::in( $source->post_parent, $locale, false ) : 0;
		$base   = '' !== $source->post_name ? $source->post_name : sanitize_title( $source->post_title );
		$base   = '' !== $base ? $base : $source->post_type;
		$id     = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $source->post_type,
					'post_status'  => 'draft',
					'post_title'   => $title ?? $source->post_title,
					'post_content' => $content ?? $source->post_content,
					'post_excerpt' => $source->post_excerpt,
					'post_name'    => $base . '-' . Languages::slug( $locale ),
					'post_password' => $source->post_password,
					'post_parent'  => $parent,
					'menu_order'   => $source->menu_order,
					'post_author'  => get_current_user_id() ? get_current_user_id() : (int) $source->post_author,
					'meta_input'   => array(
						self::LANG   => $locale,
						self::SOURCE => $source->ID,
					),
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$template = get_post_meta( $source->ID, '_wp_page_template', true );
		if ( $template ) {
			update_post_meta( $id, '_wp_page_template', $template );
		}
		return (int) $id;
	}
}
