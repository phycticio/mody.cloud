<?php
/**
 * A small per-site counter for things a member may do only so often.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Gating;

defined( 'ABSPATH' ) || exit;

/**
 * Counts events in a rolling window on the current site, and says whether
 * another one is allowed (security pass, 2026-11-12).
 *
 * The times of the events inside the window are kept in a transient of the
 * current site, so every site has its own counts and nothing is stored once
 * the window has passed (transients are never exported either). With a
 * persistent object cache the transient can be evicted early: the count then
 * starts again, which is acceptable for a limit meant to stop abuse, not to
 * bill.
 */
final class Throttle {

	/** The transient's name starts with this. */
	private const PREFIX = 'mody_throttle_';

	/**
	 * Counts one event if the limit allows it.
	 *
	 * @param string $key    What is counted, such as `admin_email`.
	 * @param int    $max    Events allowed in the window.
	 * @param int    $window The window, in seconds.
	 * @return bool True when the event is allowed (and now counted), false when the limit is reached.
	 */
	public static function hit( string $key, int $max, int $window ): bool {
		$times = self::times( $key, $window );
		if ( count( $times ) >= $max ) {
			return false;
		}
		$times[] = time();
		set_transient( self::PREFIX . $key, $times, $window );
		return true;
	}

	/**
	 * Seconds until the limit lets another event through; 0 when it already does.
	 *
	 * @param string $key    What is counted.
	 * @param int    $max    Events allowed in the window.
	 * @param int    $window The window, in seconds.
	 */
	public static function wait( string $key, int $max, int $window ): int {
		$times = self::times( $key, $window );
		if ( count( $times ) < $max ) {
			return 0;
		}
		// The event that frees a place is the one that ages out first of the last $max.
		$oldest = $times[ count( $times ) - $max ];
		return max( 0, $oldest + $window - time() );
	}

	/**
	 * Forgets the counts of one key (tests, and nothing else).
	 *
	 * @param string $key What is counted.
	 */
	public static function reset( string $key ): void {
		delete_transient( self::PREFIX . $key );
	}

	/**
	 * The times of the events still inside the window, oldest first.
	 *
	 * @param string $key    What is counted.
	 * @param int    $window The window, in seconds.
	 * @return int[]
	 */
	private static function times( string $key, int $window ): array {
		$stored = get_transient( self::PREFIX . $key );
		$since  = time() - $window;
		$times  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $time ) {
			if ( is_int( $time ) && $time > $since ) {
				$times[] = $time;
			}
		}
		return $times;
	}
}
