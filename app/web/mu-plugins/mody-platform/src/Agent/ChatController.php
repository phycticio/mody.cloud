<?php
/**
 * REST endpoint of the agent chat: `mody-platform/v1/chat`.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Agent;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST a message, get the agent's turn. DELETE starts a new conversation.
 *
 * Logged-in users only, with the REST nonce (cookie authentication): without
 * `X-WP-Nonce`, WordPress treats the request as logged out and it gets 401
 * before any model call. The conversation is kept on the server, per user
 * and site, so a client cannot put words in the agent's mouth.
 */
final class ChatController {

	/** REST namespace. */
	public const REST_NAMESPACE = 'mody-platform/v1';

	/** Messages kept per conversation. */
	private const KEPT_MESSAGES = 20;

	/** A conversation is forgotten after this long without messages. */
	private const TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * Hooked to `rest_api_init`.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/chat',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'chat' ),
					'permission_callback' => array( self::class, 'can_chat' ),
					'args'                => array(
						'message' => array(
							'type'      => 'string',
							'required'  => true,
							'minLength' => 1,
							'maxLength' => Agent::MAX_MESSAGE_LENGTH,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'reset' ),
					'permission_callback' => array( self::class, 'can_chat' ),
				),
			)
		);
	}

	/**
	 * Permission callback: a logged-in user who may use the agent on this site.
	 */
	public static function can_chat(): bool {
		return is_user_logged_in() && current_user_can( Agent::CAPABILITY );
	}

	/**
	 * Runs the agent on one message.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public static function chat( WP_REST_Request $request ): WP_REST_Response {
		$message = (string) $request->get_param( 'message' );
		$history = self::history();
		$turn    = Agent::respond( $message, $history );

		// Failed turns (no brand profile, model unavailable) are not context.
		if ( ! $turn['error'] ) {
			$history[] = array(
				'role' => 'user',
				'text' => trim( mb_substr( sanitize_textarea_field( $message ), 0, Agent::MAX_MESSAGE_LENGTH ) ),
			);
			$history[] = array( 'role' => 'assistant' ) + array_intersect_key( $turn, array_flip( array( 'actions', 'choices', 'refused' ) ) ) + array( 'text' => $turn['reply'] );
			set_transient( self::key(), array_slice( $history, -self::KEPT_MESSAGES ), self::TTL );
		}

		return new WP_REST_Response( $turn );
	}

	/**
	 * Forgets the conversation.
	 */
	public static function reset(): WP_REST_Response {
		delete_transient( self::key() );
		return new WP_REST_Response( array( 'reset' => true ) );
	}

	/**
	 * The current user's conversation on this site, oldest message first.
	 *
	 * @return list<array{role: string, text: string, actions?: array, choices?: array, refused?: bool}>
	 */
	public static function history(): array {
		$history = get_transient( self::key() );
		return is_array( $history ) ? array_values( $history ) : array();
	}

	/**
	 * Transient key. Transients are per site, so this is per user and site.
	 */
	private static function key(): string {
		return 'mody_agent_chat_' . get_current_user_id();
	}
}
