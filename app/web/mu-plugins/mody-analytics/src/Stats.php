<?php
/**
 * What the dashboard reads: a site's numbers over a period.
 *
 * @package Mody\Analytics
 */

namespace Mody\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only. Days are the site's own, today included.
 */
final class Stats {

	/**
	 * A site's numbers for the last days, with the period before for
	 * comparison.
	 *
	 * @param int $site_id Site.
	 * @param int $days    Days, today included.
	 * @param int $top     Rows per list.
	 * @return array{
	 *   days: array<string, array{views: int, visitors: int}>,
	 *   views: int, visitors: int, previous_views: int, previous_visitors: int,
	 *   pages: array<int, array{value: string, views: int}>,
	 *   sources: array<int, array{value: string, views: int}>,
	 *   devices: array<int, array{value: string, views: int}>
	 * }
	 */
	public static function summary( int $site_id, int $days = 30, int $top = 10 ): array {
		global $wpdb;
		$days  = max( 1, $days );
		$today = (int) strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 UTC' );
		$first = gmdate( 'Y-m-d', $today - ( $days - 1 ) * DAY_IN_SECONDS );
		$prior = gmdate( 'Y-m-d', $today - ( 2 * $days - 1 ) * DAY_IN_SECONDS );
		$table = Store::daily();

		$series = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$series[ gmdate( 'Y-m-d', $today - $i * DAY_IN_SECONDS ) ] = array(
				'views'    => 0,
				'visitors' => 0,
			);
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, views, visitors FROM {$table} WHERE site_id = %d AND kind = 'total' AND day >= %s", $site_id, $prior ), ARRAY_A );
		$previous = array(
			'views'    => 0,
			'visitors' => 0,
		);
		foreach ( (array) $rows as $row ) {
			$day = (string) $row['day'];
			if ( isset( $series[ $day ] ) ) {
				$series[ $day ] = array(
					'views'    => (int) $row['views'],
					'visitors' => (int) $row['visitors'],
				);
			} elseif ( $day < $first ) {
				$previous['views']    += (int) $row['views'];
				$previous['visitors'] += (int) $row['visitors'];
			}
		}

		$list = static function ( string $kind ) use ( $wpdb, $table, $site_id, $first, $top ): array {
			$found = $wpdb->get_results( $wpdb->prepare( "SELECT value, SUM(views) AS views FROM {$table} WHERE site_id = %d AND kind = %s AND day >= %s GROUP BY value ORDER BY views DESC, value ASC LIMIT %d", $site_id, $kind, $first, $top ), ARRAY_A );
			return array_map(
				static fn( array $row ): array => array(
					'value' => (string) $row['value'],
					'views' => (int) $row['views'],
				),
				(array) $found
			);
		};
		// phpcs:enable

		return array(
			'days'              => $series,
			'views'             => array_sum( array_column( $series, 'views' ) ),
			'visitors'          => array_sum( array_column( $series, 'visitors' ) ),
			'previous_views'    => $previous['views'],
			'previous_visitors' => $previous['visitors'],
			'pages'             => $list( 'page' ),
			'sources'           => $list( 'source' ),
			'devices'           => $list( 'device' ),
		);
	}
}
