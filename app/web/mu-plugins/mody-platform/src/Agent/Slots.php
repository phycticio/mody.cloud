<?php
/**
 * How many model jobs run at once: one per member and site, and a few on the
 * whole network.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Agent;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Security pass, 2026-11-12 (finding `agent-worker-exhaustion`; round 2 gave the
 * `ai` plugin's abilities a smaller allowance of their own and a short wait).
 *
 * A model job (a chat turn, `mody/generate-page`, one of the `ai` plugin's
 * abilities) holds a PHP-FPM worker for as long as the model takes to answer,
 * up to minutes. The official image runs 5 workers, so a few parallel jobs
 * from one trial account would leave no worker for any other site, login or
 * signup. The limits:
 *
 * - **One job per user and site** (kind `job`; `ai` for the plugin's short
 *   abilities, which have a lock of their own so the editor's buttons and
 *   the chat do not block each other). A second chat job is refused at once
 *   (409), because the first one runs for minutes; a second `ai` ability waits
 *   a moment for the first (it takes seconds).
 * - **At most `limit()` model jobs on the whole network** (3 by default, so
 *   two workers always stay free). A job that finds no free slot waits a few
 *   seconds (`wait()`) and is then refused (429, "Mody is busy").
 * - **At most `ai_limit()` of those slots for the `ai` plugin's abilities**
 *   (1 by default). The editor's type-ahead, title, excerpt and alt-text
 *   features are on for every member, so without a cap of their own they would
 *   take the slots chat and page jobs need. An `ai` ability takes one of the
 *   `aislot:N` locks first, and then a slot like any job: chat and page jobs
 *   always have `limit() - ai_limit()` slots no editor feature can take.
 *
 * The locks are MariaDB advisory locks (`GET_LOCK( name, 0 )`, tried again
 * every few hundred milliseconds while a job waits). They belong to the
 * database connection of the PHP request that took them, so they are atomic
 * across workers, and they end by themselves when that request ends, even if
 * the worker dies or is killed: there is nothing to expire, and a dead job
 * never blocks anyone. The wall-clock limit of a job is the deadline
 * (set_deadline(), checked by Hardening on every model request).
 *
 * A request that already holds a lock does not take it again: the agent's job
 * runs `mody/generate-page` itself, inside the lock the job holds.
 */
final class Slots {

	/** Model jobs that run at once on the whole network: PHP-FPM has 5 workers, two stay free. */
	public const DEFAULT_LIMIT = 3;

	/** Of those, the most the `ai` plugin's abilities (editor features) may use at once. */
	public const DEFAULT_AI_LIMIT = 1;

	/** Seconds a job waits for a free slot (and an `ai` ability for its user's lock) before it is refused, by kind. */
	public const DEFAULT_WAIT = array(
		'job' => 4,
		'ai'  => 2,
	);

	/** Milliseconds between two tries while a job waits. */
	private const POLL_MS = 150;

	/** Locks this request holds, by name. */
	private static array $held = array();

	/** Whether this request holds one of the network-wide slots. */
	private static bool $slot = false;

	/** Whether this request holds one of the `ai` abilities' own slots. */
	private static bool $ai_slot = false;

	/** When the job running now must stop calling the model (a Unix time), or null. */
	private static ?int $deadline = null;

	/**
	 * Model jobs allowed at once on the network. Filter `mody_model_slots`
	 * (raise it together with PHP-FPM's `pm.max_children`).
	 */
	public static function limit(): int {
		return max( 1, min( 20, (int) apply_filters( 'mody_model_slots', self::DEFAULT_LIMIT ) ) );
	}

	/**
	 * Model jobs the `ai` plugin's abilities may run at once: never more than
	 * limit() - 1, so chat and page jobs always keep a slot. Filter
	 * `mody_ai_model_slots`.
	 */
	public static function ai_limit(): int {
		$most = max( 1, self::limit() - 1 );
		return max( 1, min( $most, (int) apply_filters( 'mody_ai_model_slots', min( $most, self::DEFAULT_AI_LIMIT ) ) ) );
	}

	/**
	 * Seconds a job of this kind waits for a free slot before it is refused.
	 * Filter `mody_model_slot_wait` (seconds, kind).
	 *
	 * @param string $kind `job` or `ai`.
	 */
	public static function wait( string $kind ): float {
		return max( 0.0, min( 15.0, (float) apply_filters( 'mody_model_slot_wait', (float) ( self::DEFAULT_WAIT[ $kind ] ?? 0 ), $kind ) ) );
	}

	/**
	 * A lock's name. Advisory locks belong to the database server, not to a
	 * database, so the name carries the database and its table prefix.
	 *
	 * @param string $what What the lock is for.
	 */
	public static function name( string $what ): string {
		global $wpdb;
		return 'mody' . substr( md5( DB_NAME . $wpdb->base_prefix ), 0, 8 ) . ':' . $what;
	}

	/**
	 * Takes the locks of a model job for the current user and site. A slot is
	 * waited for (wait()); the user's own lock is not, except for an `ai`
	 * ability, which waits for the user's previous one a moment.
	 *
	 * @param string $kind    `job` (chat turns, page generation) or `ai`.
	 * @param string $message What a member who is already running one reads.
	 * @return list<string>|WP_Error The names of the locks now held: pass them to release().
	 *                               Empty when this request holds them already.
	 *                               A WP_Error with status 409 (this user and site run one
	 *                               already: `mody_agent_busy`) or 429 (the network is full: `mody_busy`).
	 */
	public static function take( string $kind = 'job', string $message = '' ): array|WP_Error {
		$taken = array();
		$wait  = self::wait( $kind );
		$mine  = self::name( sprintf( '%s:%d:%d', $kind, get_current_blog_id(), get_current_user_id() ) );
		if ( ! isset( self::$held[ $mine ] ) ) {
			$state = self::lock_until( $mine, 'ai' === $kind ? microtime( true ) + $wait : 0.0 );
			if ( false === $state ) {
				return new WP_Error(
					'mody_agent_busy',
					'' !== $message ? $message : __( 'Mody is still working on your previous request. Please wait for it to finish.', 'mody_cloud' ),
					array( 'status' => 409 )
				);
			}
			if ( true === $state ) {
				self::$held[ $mine ] = true;
				$taken[]             = $mine;
			}
		}

		if ( ! self::$slot ) {
			$until = microtime( true ) + $wait;
			// The editor features first take one of their own (so they cannot fill the network), then a slot like any job.
			if ( 'ai' === $kind && ! self::$ai_slot ) {
				$name = self::take_slot( 'aislot:', self::ai_limit(), $until );
				if ( null !== $name && '' !== $name ) {
					self::$held[ $name ] = true;
					self::$ai_slot       = true;
					$taken[]             = $name;
				}
				if ( null === $name ) {
					self::release( $taken );
					return self::busy();
				}
			}
			$name = self::take_slot( 'slot:', self::limit(), $until );
			if ( null === $name ) {
				self::release( $taken );
				return self::busy();
			}
			if ( '' !== $name ) {
				self::$held[ $name ] = true;
				self::$slot          = true;
				$taken[]             = $name;
			}
		}
		return $taken;
	}

	/**
	 * The answer to a job that found no free slot.
	 */
	private static function busy(): WP_Error {
		return new WP_Error( 'mody_busy', __( 'Mody is busy right now. Please try again in a moment.', 'mody_cloud' ), array( 'status' => 429 ) );
	}

	/**
	 * Takes the first free lock of `$prefix1 … $prefixN`, trying again until `$until`.
	 *
	 * @param string $prefix The lock names' start (`slot:`, `aislot:`).
	 * @param int    $count  How many there are.
	 * @param float  $until  Unix time (microtime) to stop trying at.
	 * @return string|null The lock's name; '' when the database could not say (which never
	 *                     blocks a member); null when every one stayed taken.
	 */
	private static function take_slot( string $prefix, int $count, float $until ): ?string {
		while ( true ) {
			for ( $number = 1; $number <= $count; $number++ ) {
				$name  = self::name( $prefix . $number );
				$state = self::lock( $name );
				if ( true === $state ) {
					return $name;
				}
				if ( null === $state ) {
					return '';
				}
			}
			$left = $until - microtime( true );
			if ( $left <= 0 ) {
				return null;
			}
			usleep( (int) min( self::POLL_MS * 1000, $left * 1000000 ) );
		}
	}

	/**
	 * Gives the locks back.
	 *
	 * @param list<string> $names What take() returned.
	 */
	public static function release( array $names ): void {
		global $wpdb;
		foreach ( array_reverse( $names ) as $name ) {
			unset( self::$held[ $name ] );
			if ( str_contains( $name, ':slot:' ) ) {
				self::$slot = false;
			} elseif ( str_contains( $name, ':aislot:' ) ) {
				self::$ai_slot = false;
			}
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- An advisory lock.
		}
	}

	/**
	 * Runs a model job under take() and release(), with a deadline.
	 *
	 * @param string   $kind    `job` or `ai`.
	 * @param callable $work    The job.
	 * @param int      $seconds Wall-clock seconds the job may keep calling the model (0: no deadline of its own).
	 * @return mixed What the job returns, or the WP_Error of take().
	 */
	public static function run( string $kind, callable $work, int $seconds = 0 ): mixed {
		$taken = self::take( $kind );
		if ( is_wp_error( $taken ) ) {
			return $taken;
		}
		$before = self::set_deadline( $seconds );
		try {
			return $work();
		} finally {
			self::$deadline = $before;
			self::release( $taken );
		}
	}

	/**
	 * Sets the deadline of the job running now.
	 *
	 * @param int $seconds From now; 0 leaves the deadline as it is.
	 * @return int|null The deadline before, to put back.
	 */
	public static function set_deadline( int $seconds ): ?int {
		$before = self::$deadline;
		if ( $seconds > 0 ) {
			self::$deadline = null === $before ? time() + $seconds : min( $before, time() + $seconds );
		}
		return $before;
	}

	/**
	 * Puts a deadline back (what set_deadline() returned).
	 *
	 * @param int|null $deadline The earlier deadline.
	 */
	public static function restore_deadline( ?int $deadline ): void {
		self::$deadline = $deadline;
	}

	/**
	 * Whether the job running now is past its deadline.
	 */
	public static function expired(): bool {
		return null !== self::$deadline && time() > self::$deadline;
	}

	/**
	 * Tries one advisory lock, again every few hundred milliseconds until `$until`.
	 *
	 * @param string $name  The lock.
	 * @param float  $until Unix time (microtime) to stop trying at; 0 tries once.
	 * @return bool|null As lock().
	 */
	private static function lock_until( string $name, float $until ): ?bool {
		while ( true ) {
			$state = self::lock( $name );
			$left  = $until - microtime( true );
			if ( false !== $state || $left <= 0 ) {
				return $state;
			}
			usleep( (int) min( self::POLL_MS * 1000, $left * 1000000 ) );
		}
	}

	/**
	 * Tries one advisory lock.
	 *
	 * @param string $name The lock.
	 * @return bool|null True: taken now. False: another worker holds it. Null: the
	 *                   database could not say, which never blocks a member.
	 */
	private static function lock( string $name ): ?bool {
		global $wpdb;
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- An advisory lock.
		if ( null === $got ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A lock that cannot be taken must be visible somewhere.
			error_log( '[mody-platform] GET_LOCK failed: ' . $wpdb->last_error );
			return null;
		}
		return '1' === (string) $got;
	}
}
