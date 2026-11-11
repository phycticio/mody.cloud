<?php
/**
 * What the owner has open in the editor, sent with a chat message (ticket M3-06).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Agent;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Validates the editor context the chat bubble sends with a message.
 *
 * The bubble reads it from the editor's public data stores: the post type
 * and ID being edited, the content as it is in the editor (unsaved changes
 * included), and the selected block. The content is the member's own data:
 * it goes to the page writer between <markup> tags, never into the agent's
 * prompt, and nothing here is saved.
 */
final class EditorContext {

	/** Post types the agent may edit from the editor (templates and parts since M3-07). */
	public const TYPES = array( 'page', 'post', 'wp_template', 'wp_template_part' );

	/** Template types: their ID is a string, `theme//slug`. */
	public const TEMPLATE_TYPES = array( 'wp_template', 'wp_template_part' );

	/**
	 * Longest markup the edit writer rewrites at once, in bytes (a generated
	 * page is about 5 KB). A longer page is changed section by section.
	 */
	public const MAX_MARKUP = 20000;

	/** A safety cap on the open page, in bytes; no real page is near it. */
	public const MAX_CONTENT = 2000000;

	/** A block's client ID in the editor: a UUID. */
	private const CLIENT_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Checks the context of a chat request.
	 *
	 * @param mixed $raw The `context` parameter.
	 * @return array{post_type: string, post_id: int|string, title: string, area: string, selected: array{client_id: string, name: string, markup: string}|null, content: string, too_long: bool}|null|WP_Error
	 *         Null when there is nothing the agent may edit (no context, another
	 *         type); a WP_Error (with an HTTP status) for a bad one. A template's
	 *         post_id is its ID (`theme//header`), and `area` a part's area.
	 *         `too_long`: the content is over MAX_MARKUP (changed section by section).
	 */
	public static function from_input( $raw ): array|null|WP_Error {
		if ( ! is_array( $raw ) || ! $raw ) {
			return null;
		}
		$type = sanitize_key( (string) ( $raw['post_type'] ?? '' ) );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return null;
		}

		$area = '';
		if ( in_array( $type, self::TEMPLATE_TYPES, true ) ) {
			// Templates and parts: `theme//slug`, edited with the site's design (M3-07).
			$post_id  = is_string( $raw['post_id'] ?? null ) ? $raw['post_id'] : '';
			$template = preg_match( '#^[a-z0-9_-]+//[a-z0-9_-]+$#', $post_id ) ? get_block_template( $post_id, $type ) : null;
			if ( ! $template ) {
				return new WP_Error( 'mody_bad_context', __( 'The template open in the editor was not found.', 'mody_cloud' ), array( 'status' => 400 ) );
			}
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return new WP_Error( 'mody_bad_context', __( 'You may not edit the site\'s templates.', 'mody_cloud' ), array( 'status' => 403 ) );
			}
			$title = (string) $template->title;
			$area  = 'wp_template_part' === $type ? sanitize_key( (string) $template->area ) : '';
		} else {
			$post_id = absint( $raw['post_id'] ?? 0 );
			$post    = $post_id ? get_post( $post_id ) : null;
			if ( ! $post || $post->post_type !== $type ) {
				return new WP_Error( 'mody_bad_context', __( 'The page open in the editor was not found.', 'mody_cloud' ), array( 'status' => 400 ) );
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'mody_bad_context', __( 'You may not edit the page open in the editor.', 'mody_cloud' ), array( 'status' => 403 ) );
			}
			$title = $post->post_title;
		}

		$content = is_string( $raw['content'] ?? null ) ? $raw['content'] : '';
		// Mody sees the whole open page, whatever its size (decided 2026-11-11). A page
		// over MAX_MARKUP (the main site's landing page is about 56 KB) is changed
		// section by section (Agent::edit_sections()); a template that long is changed
		// only through a selected block.
		if ( strlen( $content ) > self::MAX_CONTENT ) {
			return new WP_Error( 'mody_bad_context', __( 'This page is too large for the assistant to read.', 'mody_cloud' ), array( 'status' => 400 ) );
		}
		$too_long = strlen( $content ) > self::MAX_MARKUP;

		$selected = null;
		if ( is_array( $raw['selected'] ?? null ) ) {
			$client_id = (string) ( $raw['selected']['client_id'] ?? '' );
			$markup    = is_string( $raw['selected']['markup'] ?? null ) ? $raw['selected']['markup'] : '';
			$blocks    = array_values( array_filter( parse_blocks( $markup ), static fn( $block ) => null !== $block['blockName'] ) );
			if ( ! preg_match( self::CLIENT_ID, $client_id ) || '' === trim( $markup ) || strlen( $markup ) > self::MAX_MARKUP || 1 !== count( $blocks ) ) {
				return new WP_Error( 'mody_bad_context', __( 'The selected block could not be read.', 'mody_cloud' ), array( 'status' => 400 ) );
			}
			$selected = array(
				'client_id' => $client_id,
				'name'      => (string) $blocks[0]['blockName'],
				'markup'    => $markup,
			);
		}

		return array(
			'post_type' => $type,
			'post_id'   => $post_id,
			'title'     => mb_substr( sanitize_text_field( $title ), 0, 120 ),
			'area'      => $area,
			'selected'  => $selected,
			'content'   => $content,
			'too_long'  => $too_long,
		);
	}
}
