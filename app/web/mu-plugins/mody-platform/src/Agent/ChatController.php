<?php
/**
 * REST endpoint of the agent chat: `mody-platform/v1/chat`.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Agent;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST a message to start a job, GET the job (the checker) until it is done,
 * DELETE to start a new conversation.
 *
 * Logged-in users only, with the REST nonce (cookie authentication): without
 * `X-WP-Nonce`, WordPress treats the request as logged out and it gets 401
 * before any model call. The conversation is kept on the server, per user
 * and site, so a client cannot put words in the agent's mouth.
 *
 * Background jobs (decided 2026-11-06): POST answers at once (202) with the
 * job ID. Then the same PHP-FPM worker finishes the agent turn after the
 * response is sent (`fastcgi_finish_request()`), so a slow page generation
 * never meets a proxy timeout and no cron is needed. Without PHP-FPM (WP-CLI,
 * tests) the turn runs before the response, which then holds the result.
 * One job at a time per user and site.
 */
final class ChatController {

	/** REST namespace. */
	public const REST_NAMESPACE = 'mody-platform/v1';

	/** A running job older than this is reported as failed (PHP's max_execution_time is 300). */
	public const JOB_TIMEOUT = 5 * MINUTE_IN_SECONDS;

	/** Messages kept per conversation. */
	private const KEPT_MESSAGES = 20;

	/** A conversation is forgotten after this long without messages. */
	private const TTL = 2 * HOUR_IN_SECONDS;

	/** A finished job can be read for this long. */
	private const JOB_TTL = HOUR_IN_SECONDS;

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

		register_rest_route(
			self::REST_NAMESPACE,
			'/chat/jobs/(?P<id>[0-9a-f-]{36})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'check' ),
				'permission_callback' => array( self::class, 'can_chat' ),
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
	 * Starts a job for one message.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error 202 with the job, 200 with the result
	 *                                   when it ran at once, 409 while another job runs.
	 */
	public static function chat( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$running = self::running_job();
		if ( $running ) {
			return new WP_Error(
				'mody_agent_busy',
				__( 'I am still working on your previous message. Please wait for it to finish.', 'mody_cloud' ),
				array(
					'status' => 409,
					'job'    => $running['id'],
				)
			);
		}

		$job = array(
			'id'      => wp_generate_uuid4(),
			'user_id' => get_current_user_id(),
			'message' => trim( mb_substr( sanitize_textarea_field( (string) $request->get_param( 'message' ) ), 0, Agent::MAX_MESSAGE_LENGTH ) ),
			'status'  => 'running',
			'started' => time(),
		);
		self::save_job( $job );
		set_transient( self::running_key(), $job['id'], self::JOB_TIMEOUT );

		if ( ! function_exists( 'fastcgi_finish_request' ) ) {
			return new WP_REST_Response( self::run( $job['id'] ) );
		}

		add_action(
			'shutdown',
			static function () use ( $job ): void {
				self::finish_response();
				self::run( $job['id'] );
			},
			0
		);
		return new WP_REST_Response( self::public_job( $job ), 202 );
	}

	/**
	 * The checker: the state of a job, and its result once it is done.
	 *
	 * @param WP_REST_Request $request The request, with the job `id`.
	 * @return WP_REST_Response|WP_Error 404 for an unknown job or another user's.
	 */
	public static function check( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$job = self::load_job( (string) $request['id'] );
		if ( ! $job || get_current_user_id() !== (int) $job['user_id'] ) {
			return new WP_Error( 'mody_job_not_found', __( 'This request is unknown or expired.', 'mody_cloud' ), array( 'status' => 404 ) );
		}
		if ( self::is_stale( $job ) ) {
			$job = self::fail_stale( $job );
		}
		return new WP_REST_Response( self::public_job( $job ) );
	}

	/**
	 * Runs a job's agent turn and stores the result. Public for tests.
	 *
	 * @param string $id Job ID.
	 * @return array<string, mixed> The job as the checker returns it.
	 */
	public static function run( string $id ): array {
		$job = self::load_job( $id );
		if ( ! $job || 'running' !== $job['status'] ) {
			return $job ? self::public_job( $job ) : array();
		}

		try {
			$history = self::history();
			$turn    = Agent::respond( $job['message'], $history );

			// Failed turns (no brand profile, model unavailable) are not context.
			if ( ! $turn['error'] ) {
				$history[] = array(
					'role' => 'user',
					'text' => $job['message'],
				);
				$history[] = array( 'role' => 'assistant' ) + array_intersect_key( $turn, array_flip( array( 'actions', 'choices', 'refused' ) ) ) + array( 'text' => $turn['reply'] );
				set_transient( self::history_key(), array_slice( $history, -self::KEPT_MESSAGES ), self::TTL );
			}
			$job['status'] = 'done';
			$job['turn']   = $turn;
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Never the message or the token.
			error_log( sprintf( '[mody-platform] agent job %s failed: %s in %s:%d', $id, $e->getMessage(), $e->getFile(), $e->getLine() ) );
			$job['status'] = 'failed';
			$job['turn']   = self::failed_turn( __( 'Something went wrong. Please try again.', 'mody_cloud' ) );
		}

		$job['finished'] = microtime( true );
		self::save_job( $job );
		if ( get_transient( self::running_key() ) === $id ) {
			delete_transient( self::running_key() );
		}
		return self::public_job( $job );
	}

	/**
	 * Forgets the conversation.
	 */
	public static function reset(): WP_REST_Response {
		delete_transient( self::history_key() );
		return new WP_REST_Response( array( 'reset' => true ) );
	}

	/**
	 * The current user's conversation on this site, oldest message first.
	 *
	 * @return list<array{role: string, text: string, actions?: array, choices?: array, refused?: bool}>
	 */
	public static function history(): array {
		$history = get_transient( self::history_key() );
		return is_array( $history ) ? array_values( $history ) : array();
	}

	/**
	 * The current user's running job on this site, if any. Clears a stale one.
	 *
	 * @return array<string, mixed>|null The job.
	 */
	public static function running_job(): ?array {
		$id = get_transient( self::running_key() );
		if ( ! is_string( $id ) ) {
			return null;
		}
		$job = self::load_job( $id );
		if ( ! $job || 'running' !== $job['status'] ) {
			delete_transient( self::running_key() );
			return null;
		}
		if ( self::is_stale( $job ) ) {
			self::fail_stale( $job );
			return null;
		}
		return $job;
	}

	/**
	 * Sends the response to the client and closes the connection, so the
	 * rest of the request runs in the background.
	 */
	private static function finish_response(): void {
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		ignore_user_abort( true );
		fastcgi_finish_request();
	}

	/**
	 * Whether a running job has been running for too long.
	 *
	 * @param array<string, mixed> $job The job.
	 */
	private static function is_stale( array $job ): bool {
		return 'running' === $job['status'] && time() - (int) $job['started'] > self::JOB_TIMEOUT;
	}

	/**
	 * Marks a stale job as failed, and logs it.
	 *
	 * @param array<string, mixed> $job The job.
	 * @return array<string, mixed> The failed job.
	 */
	private static function fail_stale( array $job ): array {
		$job['status'] = 'failed';
		$job['turn']   = self::failed_turn( __( 'This took too long and was stopped. Please try again.', 'mody_cloud' ) );
		self::save_job( $job );
		if ( get_transient( self::running_key() ) === $job['id'] ) {
			delete_transient( self::running_key() );
		}
		AgentEvents::log( (string) $job['message'], null, null, 'mody_job_timeout: ' . $job['turn']['reply'], (float) $job['started'] );
		return $job;
	}

	/**
	 * A chat turn for a failed job.
	 *
	 * @param string $reply What the member reads.
	 * @return array<string, mixed>
	 */
	private static function failed_turn( string $reply ): array {
		return array(
			'reply'   => $reply,
			'actions' => array(),
			'choices' => array(),
			'refused' => false,
			'error'   => true,
		);
	}

	/**
	 * What the client sees of a job: its ID, status and, once finished, the turn.
	 *
	 * @param array<string, mixed> $job The job.
	 * @return array<string, mixed>
	 */
	private static function public_job( array $job ): array {
		return array(
			'job'    => $job['id'],
			'status' => $job['status'],
		) + ( isset( $job['turn'] ) ? array( 'turn' => $job['turn'] ) : array() );
	}

	/**
	 * Loads a job.
	 *
	 * @param string $id Job ID.
	 * @return array<string, mixed>|null
	 */
	private static function load_job( string $id ): ?array {
		$job = get_transient( 'mody_agent_job_' . $id );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Stores a job. Transients are per site, like the conversation.
	 *
	 * @param array<string, mixed> $job The job.
	 */
	private static function save_job( array $job ): void {
		set_transient( 'mody_agent_job_' . $job['id'], $job, self::JOB_TTL );
	}

	/**
	 * Transient key of the conversation. Transients are per site, so this is per user and site.
	 */
	private static function history_key(): string {
		return 'mody_agent_chat_' . get_current_user_id();
	}

	/**
	 * Transient key holding the ID of the user's running job on this site.
	 */
	private static function running_key(): string {
		return 'mody_agent_running_' . get_current_user_id();
	}
}
