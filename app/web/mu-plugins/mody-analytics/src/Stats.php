<?php
/**
 * What the dashboards read: a site's numbers and patterns, and a member's
 * sites side by side.
 *
 * @package Mody\Analytics
 */

namespace Mody\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only, and always filtered by site: a site's Dashboard reads its own
 * site, Your sites reads the member's sites. Everything comes from the one
 * table of page views (Store::views()); days with no views there but a
 * total in the archive (before the table existed, or older than its
 * retention) take the archive's total. Days are the current site's own,
 * today included.
 *
 * Patterns are visits, never people: where visits came in, where they
 * left, and the journeys they made (the order of their pages).
 */
final class Stats {

	/** Pages of a journey shown; longer ones end with "more". */
	public const JOURNEY_STEPS = 5;

	/**
	 * A site's numbers for the last days, with the period before for
	 * comparison.
	 *
	 * @param int $site_id Site.
	 * @param int $days    Days, today included.
	 * @param int $top     Rows per list.
	 * @return array{
	 *   days: array<string, array{views: int, visits: int}>,
	 *   views: int, visits: int, previous_views: int, previous_visits: int, single: int,
	 *   pages: array<int, array{value: string, count: int}>,
	 *   entries: array<int, array{value: string, count: int}>,
	 *   exits: array<int, array{value: string, count: int}>,
	 *   sources: array<int, array{value: string, count: int}>,
	 *   devices: array<int, array{value: string, count: int}>,
	 *   journeys: array<int, array{steps: array<int, string>, more: bool, count: int}>
	 * }
	 */
	public static function summary( int $site_id, int $days = 30, int $top = 10 ): array {
		global $wpdb;
		$range = self::range( $days );
		$first = $range['first'];
		$table = Store::views();
		$both  = self::series( array( $site_id ), $range )[ $site_id ];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$list = static function ( string $sql, ...$args ) use ( $wpdb ): array {
			$found = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
			return array_map(
				static fn( array $row ): array => array(
					'value' => (string) $row['value'],
					'count' => (int) $row['n'],
				),
				(array) $found
			);
		};
		$where = "site_id = %d AND day >= %s";
		$pages   = $list( "SELECT path AS value, COUNT(*) AS n FROM {$table} WHERE {$where} GROUP BY path ORDER BY n DESC, value ASC LIMIT %d", $site_id, $first, $top );
		$entries = $list( "SELECT path AS value, COUNT(*) AS n FROM {$table} WHERE {$where} AND seq = 1 GROUP BY path ORDER BY n DESC, value ASC LIMIT %d", $site_id, $first, $top );
		$sources = $list( "SELECT source AS value, COUNT(*) AS n FROM {$table} WHERE {$where} AND seq = 1 GROUP BY source ORDER BY n DESC, value ASC LIMIT %d", $site_id, $first, $top );
		$devices = $list( "SELECT device AS value, COUNT(*) AS n FROM {$table} WHERE {$where} AND seq = 1 GROUP BY device ORDER BY n DESC, value ASC LIMIT %d", $site_id, $first, $top );
		$exits   = $list(
			"SELECT v.path AS value, COUNT(*) AS n FROM {$table} v
			JOIN (SELECT visit_id, MAX(seq) AS seq FROM {$table} WHERE {$where} GROUP BY visit_id) l ON l.visit_id = v.visit_id AND l.seq = v.seq
			WHERE v.site_id = %d AND v.day >= %s GROUP BY v.path ORDER BY n DESC, value ASC LIMIT %d",
			$site_id,
			$first,
			$site_id,
			$first,
			$top
		);
		$single = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT visit_id FROM {$table} WHERE {$where} GROUP BY visit_id HAVING COUNT(*) = 1) s", $site_id, $first ) );

		// A journey: a visit's paths in order, the first JOURNEY_STEPS of them. Visits of one page are entries, not journeys.
		$wpdb->query( 'SET SESSION group_concat_max_len = 65535' );
		$found    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT journey, more, COUNT(*) AS n FROM (
					SELECT SUBSTRING_INDEX(GROUP_CONCAT(path ORDER BY seq, id SEPARATOR '\\n'), '\\n', %d) AS journey, IF(COUNT(*) > %d, 1, 0) AS more
					FROM {$table} WHERE {$where} GROUP BY visit_id HAVING COUNT(*) > 1
				) j GROUP BY journey, more ORDER BY n DESC, journey ASC LIMIT %d",
				self::JOURNEY_STEPS,
				self::JOURNEY_STEPS,
				$site_id,
				$first,
				$top
			),
			ARRAY_A
		);
		// phpcs:enable
		$journeys = array_map(
			static fn( array $row ): array => array(
				'steps' => explode( "\n", (string) $row['journey'] ),
				'more'  => (bool) (int) $row['more'],
				'count' => (int) $row['n'],
			),
			(array) $found
		);

		return array(
			'days'            => $both['days'],
			'views'           => $both['views'],
			'visits'          => $both['visits'],
			'previous_views'  => $both['previous_views'],
			'previous_visits' => $both['previous_visits'],
			'single'          => $single,
			'pages'           => $pages,
			'entries'         => $entries,
			'exits'           => $exits,
			'sources'         => $sources,
			'devices'         => $devices,
			'journeys'        => $journeys,
		);
	}

	/**
	 * Several sites side by side (a member's sites): each site's numbers
	 * and its most viewed page, and all of them together per day.
	 *
	 * @param array<int, int> $site_ids Sites.
	 * @param int             $days     Days, today included.
	 * @return array{
	 *   sites: array<int, array{views: int, visits: int, previous_views: int, previous_visits: int, top: string, days: array<string, array{views: int, visits: int}>}>,
	 *   days: array<string, array{views: int, visits: int}>,
	 *   views: int, visits: int, previous_views: int, previous_visits: int
	 * }
	 */
	public static function sites( array $site_ids, int $days = 30 ): array {
		global $wpdb;
		$site_ids = array_values( array_unique( array_filter( array_map( 'intval', $site_ids ) ) ) );
		$range    = self::range( $days );
		$sites    = $site_ids ? self::series( $site_ids, $range ) : array();
		$all      = array(
			'days'            => self::empty_days( $range ),
			'views'           => 0,
			'visits'          => 0,
			'previous_views'  => 0,
			'previous_visits' => 0,
		);
		foreach ( $sites as $site ) {
			foreach ( $site['days'] as $day => $counts ) {
				$all['days'][ $day ]['views']  += $counts['views'];
				$all['days'][ $day ]['visits'] += $counts['visits'];
			}
			foreach ( array( 'views', 'visits', 'previous_views', 'previous_visits' ) as $key ) {
				$all[ $key ] += $site[ $key ];
			}
		}

		$tops = array();
		if ( $site_ids ) {
			$table = Store::views();
			$in    = implode( ',', array_fill( 0, count( $site_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Own table; the IN list is placeholders.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT site_id, path, COUNT(*) AS n FROM {$table} WHERE site_id IN ({$in}) AND day >= %s GROUP BY site_id, path ORDER BY n DESC, path ASC", ...array_merge( $site_ids, array( $range['first'] ) ) ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$tops[ (int) $row['site_id'] ] ??= (string) $row['path'];
			}
		}
		foreach ( $sites as $id => $site ) {
			$sites[ $id ]['top'] = $tops[ $id ] ?? '';
		}

		return array( 'sites' => $sites ) + $all;
	}

	/**
	 * The period's days: the first one, the first of the period before, and
	 * every day of the period.
	 *
	 * @param int $days Days, today included.
	 * @return array{first: string, prior: string, days: array<int, string>}
	 */
	private static function range( int $days ): array {
		$days  = max( 1, $days );
		$today = (int) strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 UTC' );
		$list  = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$list[] = gmdate( 'Y-m-d', $today - $i * DAY_IN_SECONDS );
		}
		return array(
			'first' => $list[0],
			'prior' => gmdate( 'Y-m-d', $today - ( 2 * $days - 1 ) * DAY_IN_SECONDS ),
			'days'  => $list,
		);
	}

	/**
	 * Every day of the period at zero.
	 *
	 * @param array{days: array<int, string>} $range The period.
	 * @return array<string, array{views: int, visits: int}>
	 */
	private static function empty_days( array $range ): array {
		return array_fill_keys(
			$range['days'],
			array(
				'views'  => 0,
				'visits' => 0,
			)
		);
	}

	/**
	 * Views and visits per site and day over the period and the one
	 * before. A visit never crosses a day (the visitor's hash changes), so
	 * the days' visits add up.
	 *
	 * @param array<int, int>                                           $site_ids Sites, at least one.
	 * @param array{first: string, prior: string, days: array<int, string>} $range    The period.
	 * @return array<int, array{days: array<string, array{views: int, visits: int}>, views: int, visits: int, previous_views: int, previous_visits: int}>
	 */
	private static function series( array $site_ids, array $range ): array {
		global $wpdb;
		$out = array();
		foreach ( $site_ids as $id ) {
			$out[ $id ] = array(
				'days'            => self::empty_days( $range ),
				'views'           => 0,
				'visits'          => 0,
				'previous_views'  => 0,
				'previous_visits' => 0,
			);
		}
		$in    = implode( ',', array_fill( 0, count( $site_ids ), '%d' ) );
		$args  = array_merge( $site_ids, array( $range['prior'] ) );
		$views = Store::views();
		$daily = Store::daily();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Own tables; the IN list is placeholders.
		$counted  = $wpdb->get_results( $wpdb->prepare( "SELECT site_id, day, COUNT(*) AS views, COUNT(DISTINCT visit_id) AS visits FROM {$views} WHERE site_id IN ({$in}) AND day >= %s GROUP BY site_id, day", ...$args ), ARRAY_A );
		$archived = $wpdb->get_results( $wpdb->prepare( "SELECT site_id, day, views, visitors AS visits FROM {$daily} WHERE site_id IN ({$in}) AND kind = 'total' AND day >= %s", ...$args ), ARRAY_A );
		// phpcs:enable
		$found = array();
		foreach ( (array) $archived as $row ) {
			$found[ (int) $row['site_id'] ][ (string) $row['day'] ] = $row;
		}
		foreach ( (array) $counted as $row ) {
			$found[ (int) $row['site_id'] ][ (string) $row['day'] ] = $row; // The views table wins over the archive.
		}
		foreach ( $found as $id => $rows ) {
			foreach ( $rows as $day => $row ) {
				$day    = (string) $day;
				$counts = array(
					'views'  => (int) $row['views'],
					'visits' => (int) $row['visits'],
				);
				if ( isset( $out[ $id ]['days'][ $day ] ) ) {
					$out[ $id ]['days'][ $day ] = $counts;
					$out[ $id ]['views']       += $counts['views'];
					$out[ $id ]['visits']      += $counts['visits'];
				} elseif ( $day < $range['first'] ) {
					$out[ $id ]['previous_views']  += $counts['views'];
					$out[ $id ]['previous_visits'] += $counts['visits'];
				}
			}
		}
		return $out;
	}
}
