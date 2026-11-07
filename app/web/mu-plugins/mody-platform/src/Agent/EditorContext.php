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

	/** Post types the agent may edit from the editor. Templates and parts: M3-07. */
	public const TYPES = array( 'page', 'post' );

	/** Longest markup accepted, in bytes (a generated page is about 5 KB). */
	public const MAX_MARKUP = 20000;

	/** A block's client ID in the editor: a UUID. */
	private const CLIENT_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Checks the context of a chat request.
	 *
	 * @param mixed $raw The `context` parameter.
	 * @return array{post_type: string, post_id: int, title: string, selected: array{client_id: string, name: string, markup: string}|null, content: string}|null|WP_Error
	 *         Null when there is nothing the agent may edit (no context, a
	 *         template); a WP_Error (with an HTTP status) for a bad one.
	 */
	public static function from_input( $raw ): array|null|WP_Error {
		if ( ! is_array( $raw ) || ! $raw ) {
			return null;
		}
		$type = sanitize_key( (string) ( $raw['post_type'] ?? '' ) );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return null;
		}

		$post_id = absint( $raw['post_id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || $post->post_type !== $type ) {
			return new WP_Error( 'mody_bad_context', __( 'The page open in the editor was not found.', 'mody_cloud' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'mody_bad_context', __( 'You may not edit the page open in the editor.', 'mody_cloud' ), array( 'status' => 403 ) );
		}

		$content = is_string( $raw['content'] ?? null ) ? $raw['content'] : '';
		if ( strlen( $content ) > self::MAX_MARKUP ) {
			return new WP_Error( 'mody_bad_context', __( 'This page is too long for the assistant to change at once. Select the block to change, then ask again.', 'mody_cloud' ), array( 'status' => 400 ) );
		}

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
			'title'     => mb_substr( sanitize_text_field( $post->post_title ), 0, 120 ),
			'selected'  => $selected,
			'content'   => $content,
		);
	}
}
