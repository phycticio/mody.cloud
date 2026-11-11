<?php
/**
 * What one agent turn cost: model calls, tokens, the prompt cache, the
 * writer's attempts.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Recommendation 4 of 2026-11-11 ("measure before tuning more"). While a turn
 * runs (Agent::respond()), every chat completion's usage is added up from
 * its response (`http_response`, and PageGenerator's parallel requests),
 * and the page writer reports each attempt and why one was rejected. The
 * turn then writes one `turn` row in the agent's audit log
 * (AgentEvents), read by scripts/agent-metrics.php.
 *
 * Nothing here is sent anywhere, and no prompt or answer text is kept:
 * only counts and the validator's rejection reasons.
 */
final class TurnMetrics {

	/** The audit log's tool name of a turn's row. */
	public const TOOL = 'turn';

	/** The counts of the turn running now, or null. */
	private static ?array $turn = null;

	/**
	 * `http_response`: a chat completion's usage, while a turn runs.
	 *
	 * @param mixed  $response The response.
	 * @param array  $args     The request's arguments.
	 * @param string $url      The request's URL.
	 * @return mixed The response, unchanged.
	 */
	public static function observe( $response, $args, $url ) {
		if ( null !== self::$turn && is_string( $url ) && str_ends_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '/chat/completions' ) && is_array( $response ) ) {
			self::usage( (string) wp_remote_retrieve_body( $response ) );
		}
		return $response;
	}

	/**
	 * Starts counting a turn.
	 */
	public static function start(): void {
		self::$turn = array(
			'requests'          => 0,
			'prompt_tokens'     => 0,
			'cached_tokens'     => 0,
			'completion_tokens' => 0,
			'writer_attempts'   => 0,
			'writer_rejected'   => 0,
			'rejections'        => array(),
		);
	}

	/**
	 * Adds a completion's usage, from its response body.
	 *
	 * @param string $body A chat completion's JSON.
	 */
	public static function usage( string $body ): void {
		if ( null === self::$turn ) {
			return;
		}
		$usage = json_decode( $body, true )['usage'] ?? null;
		++self::$turn['requests'];
		if ( is_array( $usage ) ) {
			self::$turn['prompt_tokens']     += (int) ( $usage['prompt_tokens'] ?? 0 );
			self::$turn['cached_tokens']     += (int) ( $usage['prompt_tokens_details']['cached_tokens'] ?? 0 );
			self::$turn['completion_tokens'] += (int) ( $usage['completion_tokens'] ?? 0 );
		}
	}

	/**
	 * One answer of the page writer, accepted or rejected.
	 *
	 * @param string $reason The validator's reason, or '' when accepted.
	 */
	public static function writer( string $reason = '' ): void {
		if ( null === self::$turn ) {
			return;
		}
		++self::$turn['writer_attempts'];
		if ( '' !== $reason ) {
			++self::$turn['writer_rejected'];
			// The reason's kind, without the owner's words: quoted text and numbers are dropped.
			$kind = mb_substr( (string) preg_replace( array( '/".*$/s', '/\d+/' ), array( '"…"', 'N' ), $reason ), 0, 160 ); // Cut at the first quote: what follows can be the owner's text.
			if ( count( self::$turn['rejections'] ) < 5 ) {
				self::$turn['rejections'][] = $kind;
			}
		}
	}

	/**
	 * Ends the turn and returns its counts, with the turn's outcome.
	 *
	 * @param array<string, mixed> $turn  The agent's turn.
	 * @param string[]             $tools The tools it ran.
	 * @return array<string, mixed>
	 */
	public static function finish( array $turn, array $tools ): array {
		$counts     = self::$turn ?? array();
		self::$turn = null;
		return $counts + array(
			'tools'   => array_values( $tools ),
			'outcome' => ! empty( $turn['error'] ) ? 'error' : ( ! empty( $turn['refused'] ) ? 'refused' : ( ! empty( $turn['choices'] ) && ! $tools ? 'asked' : ( isset( $turn['decision'] ) ? 'held' : ( $tools ? 'acted' : 'answered' ) ) ) ),
			'editing' => ! empty( $turn['edits'] ),
		);
	}
}
