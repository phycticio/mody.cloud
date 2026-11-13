<?php
/**
 * Mody's work allowance per site: a month's and a day's.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Agent;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Security pass, 2026-11-12 (finding `agent-no-model-quota`); per site, a
 * month and a day since 2026-11-13 (membership-tiers.md).
 *
 * Every model call is paid by the platform's one Infomaniak project, and a
 * site costs the same whatever it asks Mody for, so each site has an
 * allowance of tokens (what the model read and wrote: prompt plus
 * completion, the cached part of the prompt counted in full): MONTH tokens a
 * calendar month and at most DAY of them in one day, the same on every site,
 * free or paid. The month's number keeps a busy site within what its price
 * pays for (at Infomaniak's CHF 0.40 and 3.00 per million prompt and answer
 * tokens, 5 million cost about 3.25 EUR, against 7.99 EUR with VAT); the
 * day's keeps one day from using the whole month.
 *
 * **Where the numbers come from.** The agent's audit log (AgentEvents) already
 * holds them: every chat message writes a `turn` row with its tokens
 * (TurnMetrics), and every model answer outside a chat message (a
 * `mody/generate-page` call over REST, the `ai` plugin's features, anything
 * else) writes a `usage` row (record()). Rows of super admins are not counted
 * against a site: the maintainer testing on a member's site does not use up
 * the member's allowance. Nothing here stores a prompt or an answer.
 *
 * **When it is checked.** Before a chat message starts, before each step that
 * costs a model run (a page, an edit), at the head of every model-backed
 * ability, and before any model request made outside a chat message (Hardening).
 * Tokens spent in the request still running count at once (spend()), so a
 * long job cannot ignore the limit it crosses. One message can run past the
 * limit by what that message costs (a page is about 25,000 tokens, a whole
 * long page rewritten up to about 140,000 in the worst measured case).
 *
 * **The numbers** (717 turns measured over 30 days to 2026-11-13: a message
 * costs about 11,500 tokens, a generated page about 25,000): 5,000,000 a
 * month is about 430 messages; 1,000,000 a day takes a first day of building
 * a whole site (8 pages and 40 other messages, about 700,000). Change them
 * with the filter `mody_agent_token_budget`. Days and months are UTC. Super
 * admins are exempt.
 */
final class Budget {

	/** The `usage` row's tool name in the audit log. */
	public const USAGE_TOOL = 'usage';

	/** Tokens a site may use in a calendar month. */
	public const MONTH = 5000000;

	/** Tokens a site may use in one day, within its month. */
	public const DAY = 1000000;

	/** Seconds a count read from the log is reused inside one request. */
	private const CACHE_SECONDS = 20;

	/** Tokens this request spent that the log does not hold yet (the chat message running now). */
	private static int $pending = 0;

	/** Counts read from the log: site => array( day, month, time ). */
	private static array $read = array();

	/** Super admins' user IDs, for the sums. */
	private static ?array $supers = null;

	/**
	 * A site's allowance.
	 *
	 * @param int $site_id Site.
	 * @return array{day: int, month: int} Tokens.
	 */
	public static function limits( int $site_id ): array {
		$limits = array(
			'day'   => self::DAY,
			'month' => self::MONTH,
		);
		/**
		 * Filters a site's token allowance.
		 *
		 * @param array{day: int, month: int} $limits  Tokens a day and a calendar month.
		 * @param int                         $site_id Site.
		 */
		$filtered = apply_filters( 'mody_agent_token_budget', $limits, $site_id );
		return array(
			'day'   => max( 0, (int) ( is_array( $filtered ) ? ( $filtered['day'] ?? $limits['day'] ) : $limits['day'] ) ),
			'month' => max( 0, (int) ( is_array( $filtered ) ? ( $filtered['month'] ?? $limits['month'] ) : $limits['month'] ) ),
		);
	}

	/**
	 * Tokens a site used today and this month, from the log, plus what this
	 * request spent.
	 *
	 * @param int $site_id Site.
	 * @return array{day: int, month: int}
	 */
	public static function used( int $site_id ): array {
		if ( ! isset( self::$read[ $site_id ] ) || time() - self::$read[ $site_id ]['time'] > self::CACHE_SECONDS ) {
			self::$read[ $site_id ] = array(
				'day'   => self::sum( $site_id, gmdate( 'Y-m-d 00:00:00' ) ),
				'month' => self::sum( $site_id, gmdate( 'Y-m-01 00:00:00' ) ),
				'time'  => time(),
			);
		}
		return array(
			'day'   => self::$read[ $site_id ]['day'] + self::$pending,
			'month' => self::$read[ $site_id ]['month'] + self::$pending,
		);
	}

	/**
	 * Whether the current user may start more model work on this site now.
	 *
	 * @return WP_Error|null Null when there is allowance left, or for a super admin.
	 *                       Otherwise `mody_budget_exceeded` (429), with when it starts again.
	 */
	public static function check(): ?WP_Error {
		if ( is_super_admin() ) {
			return null;
		}
		$site   = get_current_blog_id();
		$limits = self::limits( $site );
		$used   = self::used( $site );
		if ( $used['month'] >= $limits['month'] ) {
			return self::error( 'month' );
		}
		if ( $used['day'] >= $limits['day'] ) {
			return self::error( 'day' );
		}
		return null;
	}

	/**
	 * Counts tokens spent by the request running now, before the log has them.
	 *
	 * @param int $tokens Prompt plus completion tokens.
	 */
	public static function spend( int $tokens ): void {
		self::$pending += max( 0, $tokens );
	}

	/**
	 * The chat message has written its `turn` row: the log has its tokens now.
	 */
	public static function settle(): void {
		self::$pending = 0;
		self::$read    = array();
	}

	/**
	 * Writes a `usage` row for a model answer given outside a chat message.
	 *
	 * @param string $body A chat completion's JSON.
	 */
	public static function record( string $body ): void {
		$data  = json_decode( $body, true );
		$usage = is_array( $data ) ? ( $data['usage'] ?? null ) : null;
		if ( ! is_array( $usage ) ) {
			return;
		}
		$prompt     = (int) ( $usage['prompt_tokens'] ?? 0 );
		$completion = (int) ( $usage['completion_tokens'] ?? 0 );
		if ( $prompt + $completion <= 0 ) {
			return;
		}
		AgentEvents::log(
			'',
			self::USAGE_TOOL,
			null,
			(string) wp_json_encode(
				array(
					'prompt_tokens'     => $prompt,
					'cached_tokens'     => (int) ( $usage['prompt_tokens_details']['cached_tokens'] ?? 0 ),
					'completion_tokens' => $completion,
				)
			),
			microtime( true )
		);
		self::$read = array(); // The next check reads the log again.
	}

	/**
	 * When an allowance starts again: the next UTC midnight, or the first day
	 * of next month.
	 *
	 * @param string $which `day` or `month`.
	 * @return int A Unix time.
	 */
	public static function resets( string $which = 'day' ): int {
		return (int) strtotime( 'month' === $which ? 'first day of next month 00:00:00 UTC' : 'tomorrow 00:00:00 UTC' );
	}

	/**
	 * Forgets what was read and spent. For tests.
	 */
	public static function reset_cache(): void {
		self::$pending = 0;
		self::$read    = array();
		self::$supers  = null;
	}

	/**
	 * The refusal a member reads.
	 *
	 * @param string $which `day` or `month`.
	 */
	private static function error( string $which ): WP_Error {
		$resets = self::resets( $which );
		$when   = wp_date( trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ), $resets );
		return new WP_Error(
			'mody_budget_exceeded',
			'month' === $which
				/* translators: %s: a date and time. */
				? sprintf( __( 'Mody has done this month\'s share of work for this site. Your site and the editor keep working as usual, and Mody is back on %s.', 'mody_cloud' ), $when )
				/* translators: %s: a date and time. */
				: sprintf( __( 'Mody has done its share of work for this site today, to keep the service fair for everyone. Your site and the editor keep working as usual, and Mody is back on %s.', 'mody_cloud' ), $when ),
			array(
				'status' => 429,
				'resets' => $resets,
				'limit'  => $which,
			)
		);
	}

	/**
	 * Tokens of a site's `turn` and `usage` rows since a time, super admins' left out.
	 *
	 * @param int    $site_id The site.
	 * @param string $since   A UTC time, `Y-m-d H:i:s`.
	 */
	private static function sum( int $site_id, string $since ): int {
		global $wpdb;
		$table = AgentEvents::table();
		$ids   = self::super_admin_ids();
		$skip  = $ids ? ' AND user_id NOT IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' : '';
		$where = 'site_id = %d AND tool IN ( %s, %s ) AND created_at >= %s' . $skip;
		$args  = array( $site_id, TurnMetrics::TOOL, self::USAGE_TOOL, $since );

		// The database adds them up (a month can hold thousands of `usage` rows); where it has no JSON_VALUE, PHP does.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The agent's own audit table; the IDs are integers.
		$total = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM( COALESCE( CAST( JSON_VALUE( result, \'$.prompt_tokens\' ) AS UNSIGNED ), 0 ) + COALESCE( CAST( JSON_VALUE( result, \'$.completion_tokens\' ) AS UNSIGNED ), 0 ) ) FROM ' . $table . ' WHERE ' . $where,
				...$args
			)
		);
		if ( '' === $wpdb->last_error ) {
			return (int) $total;
		}
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT result FROM ' . $table . ' WHERE ' . $where, ...$args ) );
		// phpcs:enable
		return self::tokens_in( (array) $rows );
	}

	/**
	 * Adds up the tokens in log rows' `result` JSON: prompt plus completion.
	 *
	 * @param string[] $results The rows' `result`.
	 */
	public static function tokens_in( array $results ): int {
		$tokens = 0;
		foreach ( $results as $result ) {
			$data = json_decode( (string) $result, true );
			if ( is_array( $data ) ) {
				$tokens += (int) ( $data['prompt_tokens'] ?? 0 ) + (int) ( $data['completion_tokens'] ?? 0 );
			}
		}
		return $tokens;
	}

	/**
	 * The super admins' user IDs.
	 *
	 * @return int[]
	 */
	private static function super_admin_ids(): array {
		if ( null === self::$supers ) {
			self::$supers = array();
			foreach ( (array) get_super_admins() as $login ) {
				$user = get_user_by( 'login', (string) $login );
				if ( $user ) {
					self::$supers[] = (int) $user->ID;
				}
			}
		}
		return self::$supers;
	}
}
