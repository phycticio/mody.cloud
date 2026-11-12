<?php
/**
 * Mody's daily work allowance per site and per member account.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Agent;

use Mody\Platform\Members\Members;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Security pass, 2026-11-12 (finding `agent-no-model-quota`).
 *
 * Every model call is paid by the platform's one Infomaniak project, and an
 * account on the card-less trial costs nothing to make. Nothing capped how
 * much one site or one account could ask Mody for, so this does: a daily
 * allowance of tokens (what the model read and wrote: prompt plus completion,
 * the cached part of the prompt counted in full), kept per site and per
 * account, smaller during the trial.
 *
 * **Where the numbers come from.** The agent's audit log (AgentEvents) already
 * holds them: every chat message writes a `turn` row with its tokens
 * (TurnMetrics), and every model answer outside a chat message (a
 * `mody/generate-page` call over REST, the `ai` plugin's features, anything
 * else) writes a `usage` row (record()). Rows of super admins are not counted
 * against a site: the maintainer testing on a member's site does not use up
 * the member's day. Nothing here stores a prompt or an answer.
 *
 * **When it is checked.** Before a chat message starts, before each step that
 * costs a model run (a page, an edit), at the head of every model-backed
 * ability, and before any model request made outside a chat message (Hardening).
 * Tokens spent in the request still running count at once (spend()), so a
 * long job cannot ignore the limit it crosses. One message can run past the
 * limit by what that message costs (a page is about 25,000 tokens, a whole
 * long page rewritten up to about 140,000 in the worst measured case).
 *
 * **The numbers** (measured on 167 turns on 2026-11-11 and 12: a message costs
 * 12,600 tokens at the median, 28,000 at the 90th percentile; a generated page
 * about 25,000): a trial site gets 800,000 tokens a day (about 55 average
 * messages, or 30 generated pages), a trial account 1,500,000 across its
 * sites; a site with a paid plan 2,000,000 (about 135 messages), an account
 * 4,000,000. A first day of building a whole site (8 pages and 40 other
 * messages) takes about 700,000. Change them with the filter
 * `mody_agent_daily_token_budget`. The day is the UTC day. Super admins are exempt.
 */
final class Budget {

	/** The `usage` row's tool name in the audit log. */
	public const USAGE_TOOL = 'usage';

	/** Tokens a day: the plan's kind, then `site` and `user` (the member account). */
	public const LIMITS = array(
		'trial' => array(
			'site' => 800000,
			'user' => 1500000,
		),
		'paid'  => array(
			'site' => 2000000,
			'user' => 4000000,
		),
	);

	/** Seconds a count read from the log is reused inside one request. */
	private const CACHE_SECONDS = 20;

	/** Tokens this request spent that the log does not hold yet (the chat message running now). */
	private static int $pending = 0;

	/** Counts read from the log: "site:user" => array( site, user, time ). */
	private static array $read = array();

	/** Super admins' user IDs, for the sums. */
	private static ?array $supers = null;

	/**
	 * The allowance of the current user on a site.
	 *
	 * @param int $site_id Site.
	 * @param int $user_id User.
	 * @return array{site: int, user: int} Tokens a day.
	 */
	public static function limits( int $site_id, int $user_id ): array {
		// A site's allowance is the trial's when its owner is on the trial; an account's when the account is.
		$trial_site    = self::is_trial_site( $site_id );
		$trial_account = self::is_trial_account( $user_id );
		$trial         = $trial_site || $trial_account;
		$limits        = array(
			'site' => self::LIMITS[ $trial_site ? 'trial' : 'paid' ]['site'],
			'user' => self::LIMITS[ $trial_account ? 'trial' : 'paid' ]['user'],
		);
		/**
		 * Filters the daily token allowance.
		 *
		 * @param array{site: int, user: int} $limits  Tokens a day, for the site and for the account.
		 * @param int                         $site_id Site.
		 * @param int                         $user_id User.
		 * @param bool                        $trial   Whether the account or the site is on the trial.
		 */
		$filtered = apply_filters( 'mody_agent_daily_token_budget', $limits, $site_id, $user_id, $trial );
		return array(
			'site' => max( 0, (int) ( is_array( $filtered ) ? ( $filtered['site'] ?? $limits['site'] ) : $limits['site'] ) ),
			'user' => max( 0, (int) ( is_array( $filtered ) ? ( $filtered['user'] ?? $limits['user'] ) : $limits['user'] ) ),
		);
	}

	/**
	 * Tokens used today, from the log, plus what this request spent.
	 *
	 * @param int $site_id Site.
	 * @param int $user_id User; 0 for none (visitors).
	 * @return array{site: int, user: int}
	 */
	public static function used( int $site_id, int $user_id ): array {
		$key = $site_id . ':' . $user_id;
		if ( ! isset( self::$read[ $key ] ) || time() - self::$read[ $key ]['time'] > self::CACHE_SECONDS ) {
			self::$read[ $key ] = array(
				'site' => self::sum( 'site_id', $site_id, true ),
				'user' => $user_id > 0 ? self::sum( 'user_id', $user_id, false ) : 0,
				'time' => time(),
			);
		}
		return array(
			'site' => self::$read[ $key ]['site'] + self::$pending,
			'user' => self::$read[ $key ]['user'] + ( $user_id > 0 ? self::$pending : 0 ),
		);
	}

	/**
	 * Whether the current user may start more model work now.
	 *
	 * @return WP_Error|null Null when there is allowance left, or for a super admin.
	 *                       Otherwise `mody_budget_exceeded` (429), with when it starts again.
	 */
	public static function check(): ?WP_Error {
		if ( is_super_admin() ) {
			return null;
		}
		$site   = get_current_blog_id();
		$user   = get_current_user_id();
		$limits = self::limits( $site, $user );
		$used   = self::used( $site, $user );
		if ( $used['site'] >= $limits['site'] ) {
			return self::error( 'site' );
		}
		if ( $user > 0 && $used['user'] >= $limits['user'] ) {
			return self::error( 'user' );
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
	 * When the day's allowance starts again: the next UTC midnight.
	 *
	 * @return int A Unix time.
	 */
	public static function resets(): int {
		return (int) strtotime( 'tomorrow 00:00:00 UTC' );
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
	 * @param string $who `site` or `user`.
	 */
	private static function error( string $who ): WP_Error {
		$when = wp_date( trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ), self::resets() );
		return new WP_Error(
			'mody_budget_exceeded',
			'site' === $who
				/* translators: %s: a date and time. */
				? sprintf( __( 'Mody has done its share of work for this site today, to keep the service fair for everyone. Your site and the editor keep working as usual, and Mody is back on %s.', 'mody_cloud' ), $when )
				/* translators: %s: a date and time. */
				: sprintf( __( 'You have reached your daily limit of work with Mody, across all your sites. Your sites and the editor keep working as usual, and Mody is back on %s.', 'mody_cloud' ), $when ),
			array(
				'status' => 429,
				'resets' => self::resets(),
				'limit'  => $who,
			)
		);
	}

	/**
	 * Tokens of today's `turn` and `usage` rows of a site, or of a user.
	 *
	 * @param string $column         `site_id` or `user_id`.
	 * @param int    $id             The site or the user.
	 * @param bool   $without_supers Leave out the rows of super admins (a site's count).
	 */
	private static function sum( string $column, int $id, bool $without_supers ): int {
		global $wpdb;
		$table = AgentEvents::table();
		$skip  = '';
		if ( $without_supers ) {
			$ids  = self::super_admin_ids();
			$skip = $ids ? ' AND user_id NOT IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' : '';
		}
		$column = 'user_id' === $column ? 'user_id' : 'site_id';
		$where  = $column . ' = %d AND tool IN ( %s, %s ) AND created_at >= %s' . $skip;
		$args   = array( $id, TurnMetrics::TOOL, self::USAGE_TOOL, gmdate( 'Y-m-d 00:00:00' ) );

		// The database adds them up (a day can hold thousands of `usage` rows); where it has no JSON_VALUE, PHP does.
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

	/**
	 * Whether an account is on the free trial.
	 *
	 * @param int $user_id User.
	 */
	private static function is_trial_account( int $user_id ): bool {
		$row = Members::get( $user_id );
		return is_array( $row ) && 'trial' === ( $row['plan'] ?? '' );
	}

	/**
	 * Whether a site belongs to an account on the free trial: the account of
	 * the site's administration email.
	 *
	 * @param int $site_id Site.
	 */
	private static function is_trial_site( int $site_id ): bool {
		$email = (string) get_blog_option( $site_id, 'admin_email' );
		$owner = '' !== $email ? get_user_by( 'email', $email ) : false;
		return $owner && self::is_trial_account( (int) $owner->ID );
	}
}
