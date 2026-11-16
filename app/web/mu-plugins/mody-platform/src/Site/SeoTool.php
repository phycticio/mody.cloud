<?php
/**
 * The agent's tool for a page's search appearance: mody/update-seo.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Site;

use Mody\Platform\SiteEditor;
use Mody\Seo\Fields;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * `mody/update-seo` (2026-11-14, tickets/seo.md) changes a page's or post's
 * SEO title and SEO description (the Mody SEO plugin's fields, the post meta
 * `mody_seo_title` and `mody_seo_description`), and can have the `ai`
 * plugin's Meta Description ability write the description from the page's
 * saved text, in the site's brand (PluginBrandContext).
 */
final class SeoTool {

	/** The ability that writes a description. */
	public const GENERATOR = 'ai/meta-description';

	/**
	 * The home page, for the agent: its page's ID, or that it shows the latest posts.
	 */
	public static function home_page(): string {
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$post  = $front ? get_post( $front ) : null;
		return $post instanceof \WP_Post && 'page' === $post->post_type
			? sprintf( 'the page "%s" (ID %d)', str_replace( array( "\n", '"', '<', '>' ), array( ' ', "'", '‹', '›' ), get_the_title( $post ) ), $post->ID )
			: 'the latest posts, not a page: its search title is the site title and tagline (settings title and tagline), and it has no description of its own';
	}

	/**
	 * Permission callback of mody/update-seo: someone who edits pages. The
	 * page itself is checked when it is found (edit_post).
	 */
	public static function can_update(): bool {
		return is_user_logged_in() && current_user_can( 'edit_pages' );
	}

	/**
	 * The page or post a call names: by ID, or by title (a page whose title
	 * matches, as mody/open-editor finds it; else a post with that exact title).
	 *
	 * @param int    $id    A page or post ID, or 0.
	 * @param string $title A title, or ''.
	 */
	public static function find( int $id, string $title ): \WP_Post|WP_Error {
		if ( $id ) {
			$post = get_post( $id );
			if ( $post && Fields::applies( $post ) && ! in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
				return $post;
			}
			/* translators: %d: a page ID. */
			return new WP_Error( 'mody_page_not_found', sprintf( __( 'There is no page or post %d on this site.', 'mody_cloud' ), $id ), array( 'pages' => SiteEditor::pages() ) );
		}
		$page = SiteEditor::match_page( 0, $title );
		if ( ! is_wp_error( $page ) || 'mody_page_not_found' !== $page->get_error_code() ) {
			return $page;
		}
		$posts = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'title'       => $title,
				'numberposts' => 1,
				'orderby'     => 'modified',
			)
		);
		return $posts ? $posts[0] : $page;
	}

	/**
	 * Execute callback of mody/update-seo. Input already validated against
	 * the ability's schema. All or nothing: a description to write is written
	 * first, and nothing is saved if that fails.
	 *
	 * @param mixed $input `page_id` or `page_title`; `title`, `description`
	 *                     ('' goes back to automatic) or `generate`.
	 * @return array<string, mixed>|WP_Error The page and its changes.
	 */
	public static function update( $input ): array|WP_Error {
		$input = is_array( $input ) ? $input : array();
		$id    = (int) ( $input['page_id'] ?? 0 );
		$name  = sanitize_text_field( (string) ( $input['page_title'] ?? '' ) );
		if ( ! $id && '' === $name ) {
			return new WP_Error( 'mody_page_required', __( 'Say which page to change: its ID or its title.', 'mody_cloud' ), array( 'pages' => SiteEditor::pages() ) );
		}
		$has_title       = array_key_exists( 'title', $input );
		$has_description = array_key_exists( 'description', $input );
		$generate        = ! empty( $input['generate'] );
		if ( $generate && $has_description ) {
			return new WP_Error( 'mody_seo_both', __( 'Give a description or generate one, not both.', 'mody_cloud' ) );
		}
		if ( ! $has_title && ! $has_description && ! $generate ) {
			return new WP_Error( 'mody_seo_nothing', __( 'Nothing to change: give a title, a description, or generate one.', 'mody_cloud' ) );
		}

		$post = self::find( $id, $name );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'mody_seo_forbidden', __( 'You may not change this page.', 'mody_cloud' ) );
		}

		$before = Fields::state( $post );
		$title  = $has_title ? mb_substr( Fields::clean( $input['title'] ), 0, Fields::TITLE_MAX ) : null;
		$text   = $has_description ? mb_substr( Fields::clean( $input['description'] ), 0, Fields::DESCRIPTION_MAX ) : null;
		if ( $generate ) {
			$text = self::generate( $post );
			if ( is_wp_error( $text ) ) {
				return $text;
			}
		}

		foreach ( array( Fields::TITLE => $title, Fields::DESCRIPTION => $text ) as $key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $post->ID, $key );
			} elseif ( null !== $value ) {
				update_post_meta( $post->ID, $key, wp_slash( $value ) );
			}
		}

		$after  = Fields::state( get_post( $post->ID ) );
		$result = array(
			'page_id' => $post->ID,
			'page'    => get_the_title( $post ),
			'type'    => $post->post_type,
		);
		if ( null !== $title ) {
			$result['search_title'] = array( // Not `title`: Agent::take_link() reads that as the page's.
				'old' => '' !== $before['title'] ? $before['title'] : 'automatic: ' . $before['automatic_title'],
				'new' => '' !== $after['title'] ? $after['title'] : 'automatic: ' . $after['automatic_title'],
			);
		}
		if ( null !== $text ) {
			$result['description'] = array(
				'old' => self::shown_description( $before ),
				'new' => self::shown_description( $after ),
			);
		}
		if ( 'publish' !== $post->post_status ) {
			$result['note'] = 'The page is not published, so search engines do not see it yet.';
		}
		return $result;
	}

	/**
	 * A description written from the page's saved text by the `ai` plugin's
	 * Meta Description ability (the site's brand is in it). It runs as the
	 * current user, inside the agent's job and budget (Agent\Hardening).
	 *
	 * @param \WP_Post $post A page or post.
	 * @return string|WP_Error One line of text.
	 */
	public static function generate( \WP_Post $post ): string|WP_Error {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( self::GENERATOR ) : null;
		if ( ! $ability ) {
			return new WP_Error( 'mody_seo_generate_unavailable', __( 'Writing a description is not available on this site. Write one from the page\'s text, or ask the owner for it.', 'mody_cloud' ) );
		}
		$answer = $ability->execute( array( 'post_id' => $post->ID ) );
		if ( is_wp_error( $answer ) && 'content_not_provided' === $answer->get_error_code() ) {
			return new WP_Error( 'mody_seo_empty_page', __( 'The page has no saved text yet to describe. Nothing was changed. Save it first, or give the description.', 'mody_cloud' ) );
		}
		if ( is_wp_error( $answer ) ) {
			return new WP_Error( 'mody_seo_generate_failed', __( 'The description could not be written right now. Nothing was changed.', 'mody_cloud' ) . ' (' . $answer->get_error_code() . ')' );
		}
		$text = Fields::clean( trim( (string) ( $answer['description']['text'] ?? '' ), " \"'“”«»" ) );
		if ( '' === $text ) {
			return new WP_Error( 'mody_seo_generate_failed', __( 'The description could not be written right now. Nothing was changed.', 'mody_cloud' ) );
		}
		if ( mb_strlen( $text ) > Fields::DESCRIPTION_MAX ) {
			$text = rtrim( (string) preg_replace( '/\s+\S*$/u', '', mb_substr( $text, 0, Fields::DESCRIPTION_MAX ) ), ' ,;:' );
		}
		return $text;
	}

	/**
	 * A description as the agent reports it: the field, or what is used
	 * without it (the excerpt, or none).
	 *
	 * @param array{description: string, excerpt: string} $state Fields::state().
	 */
	private static function shown_description( array $state ): string {
		if ( '' !== $state['description'] ) {
			return $state['description'];
		}
		return '' !== $state['excerpt'] ? 'automatic, the excerpt: ' . $state['excerpt'] : 'none (search engines pick a snippet)';
	}
}
