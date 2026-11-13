<?php
/**
 * The charts' query layer: a chart's query, from an allowlist, run on the
 * views table for the sites the caller names.
 *
 * @package Mody\Analytics
 */

namespace Mody\Analytics;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A query is a small array (tickets/analytics-chat.md), never SQL:
 *
 *   metrics    1 to 3 of METRICS
 *   dimension  one of DIMENSIONS ('none' for a single number)
 *   breakdown  '' or one of BREAKDOWNS: one series per value (top 5 and
 *              "Other"), for time dimensions and a single count metric
 *   days       1 to 395 (13 months, the retention)
 *   compare    the period before, too
 *   filters    path (a page; "/blog/*" for a prefix: visits that saw it),
 *              entry (a page), source (a host, or "direct"), device
 *   from       the page the `next` dimension starts from
 *   limit      1 to 50 rows (not for time dimensions, which show every step)
 *
 * The sites never come from the query: the caller passes them (the current
 * site, or a member's own sites). Answers are aggregates, never a visit ID
 * or a row.
 *
 * Everything about a visit (its day, first hour, pages, entry and exit
 * page, source, device, journey) comes from one subquery that groups the
 * views by visit, so every chart counts visits the same way. The `path`
 * and `next` dimensions read single views. Time dimensions are counted per
 * day and added up in PHP, so the archive's daily totals fill the days the
 * views table no longer has (views and visits only, without filters).
 */
final class Query {

	/** What can be counted. Counts add up; ratios do not, and never share an axis with counts. */
	public const METRICS = array( 'visits', 'views', 'pages_per_visit', 'single_page_rate', 'entries', 'exits' );

	/** Metrics that are counts. */
	public const COUNTS = array( 'visits', 'views', 'entries', 'exits' );

	/** What a query can be split by. */
	public const DIMENSIONS = array( 'none', 'day', 'week', 'month', 'weekday', 'hour', 'hour_weekday', 'path', 'entry', 'exit', 'source', 'device', 'journey', 'next', 'site' );

	/** Dimensions in time order, shown whole. */
	public const TIME = array( 'day', 'week', 'month', 'weekday', 'hour', 'hour_weekday' );

	/** Dimensions where every visit falls in exactly one value, so parts make a whole. */
	public const PARTITIONS = array( 'weekday', 'hour', 'entry', 'exit', 'source', 'device', 'site' );

	/** What a series can be broken down by. */
	public const BREAKDOWNS = array( 'device', 'source', 'entry', 'site' );

	/** Devices. */
	public const DEVICES = array( 'mobile', 'tablet', 'desktop', 'unknown' );

	/** Metrics each dimension allows; others allow the visit metrics. */
	private const ALLOWED = array(
		'path'    => array( 'views', 'visits', 'entries', 'exits' ),
		'next'    => array( 'visits' ),
		'journey' => array( 'visits' ),
	);

	/** The visit metrics: every dimension but path, next and journey. */
	private const VISIT_METRICS = array( 'visits', 'views', 'pages_per_visit', 'single_page_rate' );

	/** The longest period: the retention. */
	public const MAX_DAYS = 395;

	/** The most rows. */
	public const MAX_LIMIT = 50;

	/** Series a breakdown keeps before "Other". */
	public const BREAKDOWN_TOP = 5;

	/** Pages of a journey kept. */
	public const JOURNEY_STEPS = 5;

	/**
	 * Checks a query and fills its defaults.
	 *
	 * @param mixed $query The query, as the model or the board wrote it.
	 * @return array{metrics: array<int, string>, dimension: string, breakdown: string, days: int|null, compare: bool, filters: array<string, string>, from: string, limit: int}|WP_Error The query, or why not.
	 */
	public static function normalize( $query ) {
		if ( ! is_array( $query ) ) {
			return self::error( 'The query must be an object.' );
		}
		$unknown = array_diff( array_keys( $query ), array( 'metrics', 'dimension', 'breakdown', 'days', 'compare', 'filters', 'from', 'limit' ) );
		if ( $unknown ) {
			return self::error( sprintf( 'Unknown query field: %s.', implode( ', ', $unknown ) ) );
		}
		$metrics = $query['metrics'] ?? array( 'visits' );
		$metrics = is_string( $metrics ) ? array( $metrics ) : $metrics;
		if ( ! is_array( $metrics ) || ! $metrics || count( $metrics ) > 3 ) {
			return self::error( 'metrics must list 1 to 3 of: ' . implode( ', ', self::METRICS ) . '.' );
		}
		$metrics = array_values( array_unique( array_map( 'strval', $metrics ) ) );
		foreach ( $metrics as $metric ) {
			if ( ! in_array( $metric, self::METRICS, true ) ) {
				return self::error( sprintf( 'Unknown metric "%s". Metrics: %s.', $metric, implode( ', ', self::METRICS ) ) );
			}
		}
		$dimension = (string) ( $query['dimension'] ?? 'none' );
		if ( ! in_array( $dimension, self::DIMENSIONS, true ) ) {
			return self::error( sprintf( 'Unknown dimension "%s". Dimensions: %s.', $dimension, implode( ', ', self::DIMENSIONS ) ) );
		}
		$allowed = self::ALLOWED[ $dimension ] ?? self::VISIT_METRICS;
		foreach ( $metrics as $metric ) {
			if ( ! in_array( $metric, $allowed, true ) ) {
				return self::error( sprintf( 'The metric "%s" does not work with the dimension "%s". It allows: %s.', $metric, $dimension, implode( ', ', $allowed ) ) );
			}
		}
		$breakdown = (string) ( $query['breakdown'] ?? '' );
		if ( '' !== $breakdown ) {
			if ( ! in_array( $breakdown, self::BREAKDOWNS, true ) ) {
				return self::error( sprintf( 'Unknown breakdown "%s". Breakdowns: %s.', $breakdown, implode( ', ', self::BREAKDOWNS ) ) );
			}
			if ( ! in_array( $dimension, array( 'day', 'week', 'month', 'weekday', 'hour' ), true ) ) {
				return self::error( 'A breakdown needs a time dimension: day, week, month, weekday or hour.' );
			}
			if ( 1 !== count( $metrics ) || ! in_array( $metrics[0], array( 'visits', 'views' ), true ) ) {
				return self::error( 'A breakdown needs exactly one metric: visits or views.' );
			}
		}
		$days = $query['days'] ?? null;
		if ( null !== $days && ( ! is_numeric( $days ) || (int) $days < 1 || (int) $days > self::MAX_DAYS ) ) {
			return self::error( sprintf( 'days must be 1 to %d.', self::MAX_DAYS ) );
		}
		$filters = $query['filters'] ?? array();
		if ( ! is_array( $filters ) ) {
			return self::error( 'filters must be an object.' );
		}
		$clean = array();
		foreach ( $filters as $name => $value ) {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' === $value ) {
				continue;
			}
			switch ( $name ) {
				case 'path':
				case 'entry':
					$prefix = 'path' === $name && str_ends_with( $value, '*' );
					$path   = Collector::path( $prefix ? substr( $value, 0, -1 ) : $value );
					if ( '' === $path ) {
						return self::error( sprintf( 'The %s filter must be a path that starts with "/".', $name ) );
					}
					$clean[ $name ] = $path . ( $prefix ? '*' : '' );
					break;
				case 'source':
					$value = strtolower( $value );
					if ( 'direct' !== $value && ! preg_match( '/^[a-z0-9.-]{1,191}$/', $value ) ) {
						return self::error( 'The source filter must be a host, such as "instagram.com", or "direct".' );
					}
					$clean['source'] = (string) preg_replace( '/^www\./', '', $value );
					break;
				case 'device':
					if ( ! in_array( $value, self::DEVICES, true ) ) {
						return self::error( 'The device filter must be one of: ' . implode( ', ', self::DEVICES ) . '.' );
					}
					$clean['device'] = $value;
					break;
				default:
					return self::error( sprintf( 'Unknown filter "%s". Filters: path, entry, source, device.', (string) $name ) );
			}
		}
		$from = '';
		if ( 'next' === $dimension ) {
			$from = Collector::path( (string) ( $query['from'] ?? '' ) );
			if ( '' === $from ) {
				return self::error( 'The "next" dimension needs "from": the page visits went on from, such as "/".' );
			}
		}
		$limit = (int) ( $query['limit'] ?? 10 );
		return array(
			'metrics'   => $metrics,
			'dimension' => $dimension,
			'breakdown' => $breakdown,
			'days'      => null === $days ? null : (int) $days,
			'compare'   => ! empty( $query['compare'] ) && '' === $breakdown && ! in_array( $dimension, array( 'hour_weekday', 'journey' ), true ),
			'filters'   => $clean,
			'from'      => $from,
			'limit'     => max( 1, min( self::MAX_LIMIT, $limit ) ),
		);
	}

	/**
	 * Runs a query.
	 *
	 * @param array<string, mixed> $query    A query; checked here again.
	 * @param array<int, int>      $site_ids The sites it counts: never from the query.
	 * @param int                  $days     The period when the query names none.
	 * @return array{dimension: string, keys: array<int, string>, labels: array<int, string>, series: array<int, array{key: string, label: string, metric: string, data: array<int, float|int>, previous: array<int, float|int>|null}>, first: string, last: string, more: array<int, bool>}|WP_Error
	 */
	public static function run( array $query, array $site_ids, int $days = 30 ) {
		$query = self::normalize( $query );
		if ( is_wp_error( $query ) ) {
			return $query;
		}
		$site_ids = array_values( array_unique( array_filter( array_map( 'intval', $site_ids ) ) ) );
		if ( ! $site_ids ) {
			return self::error( 'No site to count.' );
		}
		$range = self::range( $query['days'] ?? max( 1, min( self::MAX_DAYS, $days ) ) );
		$now   = self::counted( $query, $site_ids, $range['first'], $range['last'] );
		$prev  = $query['compare'] ? self::counted( $query, $site_ids, $range['prior_first'], $range['prior_last'] ) : null;
		return self::shape( $query, $now, $prev, $range );
	}

	/**
	 * The period's days: first and last, and the period before.
	 *
	 * @param int $days Days, today included (the current site's today).
	 * @return array{first: string, last: string, prior_first: string, prior_last: string, days: int}
	 */
	public static function range( int $days ): array {
		$today = (int) strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 UTC' );
		return array(
			'first'       => gmdate( 'Y-m-d', $today - ( $days - 1 ) * DAY_IN_SECONDS ),
			'last'        => gmdate( 'Y-m-d', $today ),
			'prior_first' => gmdate( 'Y-m-d', $today - ( 2 * $days - 1 ) * DAY_IN_SECONDS ),
			'prior_last'  => gmdate( 'Y-m-d', $today - $days * DAY_IN_SECONDS ),
			'days'        => $days,
		);
	}

	/**
	 * The raw counts for a period: rows keyed by the dimension's value (and
	 * the breakdown's), each with visits, views, singles (visits of one
	 * page), known (visits whose singles are known), entries and exits.
	 *
	 * @param array<string, mixed> $query    A normalized query.
	 * @param array<int, int>      $site_ids Sites.
	 * @param string               $first    Y-m-d.
	 * @param string               $last     Y-m-d.
	 * @return array<string, array<string, array<string, int|bool>>> key => breakdown value ('' without) => counts.
	 */
	private static function counted( array $query, array $site_ids, string $first, string $last ): array {
		global $wpdb;
		$dimension = $query['dimension'];
		$filters   = $query['filters'];
		$views     = Store::views();
		$in        = implode( ',', array_fill( 0, count( $site_ids ), '%d' ) );

		// The visits: one row each, with everything a chart can split or filter by.
		$need_exit    = in_array( $dimension, array( 'exit' ), true );
		$need_journey = 'journey' === $dimension;
		$columns      = array(
			'v.visit_id',
			'v.site_id',
			'MIN(v.day) AS day',
			'MIN(v.hour) AS hour',
			'COUNT(*) AS pages',
			'MAX(v.seq) AS last_seq',
			'MAX(CASE WHEN v.seq = 1 THEN v.path END) AS entry',
			'MAX(CASE WHEN v.seq = 1 THEN v.source END) AS source',
			'MAX(v.device) AS device',
		);
		$args         = array();
		if ( $need_exit ) {
			$columns[] = "SUBSTRING_INDEX(GROUP_CONCAT(v.path ORDER BY v.seq DESC, v.id DESC SEPARATOR '\\n'), '\\n', 1) AS exit_path";
		}
		if ( $need_journey ) {
			$columns[] = "SUBSTRING_INDEX(GROUP_CONCAT(v.path ORDER BY v.seq, v.id SEPARATOR '\\n'), '\\n', " . self::JOURNEY_STEPS . ') AS journey';
			$columns[] = 'IF(COUNT(*) > ' . self::JOURNEY_STEPS . ', 1, 0) AS more';
		}
		if ( isset( $filters['path'] ) && 'path' !== $dimension ) {
			if ( str_ends_with( $filters['path'], '*' ) ) {
				$columns[] = 'SUM(v.path LIKE %s) AS saw';
				$args[]    = $wpdb->esc_like( substr( $filters['path'], 0, -1 ) ) . '%';
			} else {
				$columns[] = 'SUM(v.path = %s) AS saw';
				$args[]    = $filters['path'];
			}
		}
		$visits = 'SELECT ' . implode( ', ', $columns ) . " FROM {$views} v WHERE v.site_id IN ({$in}) AND v.day BETWEEN %s AND %s GROUP BY v.visit_id, v.site_id";
		$args   = array_merge( $args, $site_ids, array( $first, $last ) );

		// Filters on the visit.
		$where = array( '1 = 1' );
		$wargs = array();
		if ( isset( $filters['path'] ) && 'path' !== $dimension ) {
			$where[] = 'x.saw > 0';
		}
		if ( isset( $filters['entry'] ) ) {
			$where[] = 'x.entry = %s';
			$wargs[] = $filters['entry'];
		}
		if ( isset( $filters['source'] ) ) {
			$where[] = 'x.source = %s';
			$wargs[] = 'direct' === $filters['source'] ? '' : $filters['source'];
		}
		if ( isset( $filters['device'] ) ) {
			$where[] = 'x.device = %s';
			$wargs[] = $filters['device'];
		}
		$breakdown = $query['breakdown'];
		$split     = '' !== $breakdown ? 'x.' . ( 'entry' === $breakdown ? 'entry' : ( 'site' === $breakdown ? 'site_id' : $breakdown ) ) : "''";
		$order     = in_array( 'views', $query['metrics'], true ) ? 'views' : 'visits';

		$wpdb->query( 'SET SESSION group_concat_max_len = 65535' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Session setting.

		if ( 'path' === $dimension ) {
			$rwhere = array( 'r.site_id IN (' . $in . ')', 'r.day BETWEEN %s AND %s' );
			$rargs  = array_merge( $site_ids, array( $first, $last ) );
			if ( isset( $filters['path'] ) ) {
				if ( str_ends_with( $filters['path'], '*' ) ) {
					$rwhere[] = 'r.path LIKE %s';
					$rargs[]  = $wpdb->esc_like( substr( $filters['path'], 0, -1 ) ) . '%';
				} else {
					$rwhere[] = 'r.path = %s';
					$rargs[]  = $filters['path'];
				}
			}
			$sql = "SELECT r.path AS k, '' AS b, COUNT(*) AS views, COUNT(DISTINCT r.visit_id) AS visits, SUM(r.seq = 1) AS entries, SUM(r.seq = x.last_seq) AS exits
				FROM {$views} r JOIN ({$visits}) x ON x.visit_id = r.visit_id AND x.site_id = r.site_id
				WHERE " . implode( ' AND ', array_merge( $rwhere, $where ) ) . " GROUP BY r.path ORDER BY {$order} DESC, k ASC LIMIT %d";
			$all = array_merge( $args, $rargs, $wargs, array( $query['limit'] ) );
		} elseif ( 'next' === $dimension ) {
			$sql = "SELECT n.path AS k, '' AS b, COUNT(*) AS visits
				FROM {$views} r JOIN {$views} n ON n.visit_id = r.visit_id AND n.site_id = r.site_id AND n.seq = r.seq + 1
				JOIN ({$visits}) x ON x.visit_id = r.visit_id AND x.site_id = r.site_id
				WHERE r.site_id IN ({$in}) AND r.day BETWEEN %s AND %s AND r.path = %s AND " . implode( ' AND ', $where ) . ' GROUP BY n.path ORDER BY visits DESC, k ASC LIMIT %d';
			$all = array_merge( $args, $site_ids, array( $first, $last, $query['from'] ), $wargs, array( $query['limit'] ) );
		} else {
			$key = array(
				'none'         => 'x.day',
				'day'          => 'x.day',
				'week'         => 'x.day',
				'month'        => 'x.day',
				'weekday'      => 'x.day',
				'hour'         => 'x.hour',
				'hour_weekday' => "CONCAT(WEEKDAY(x.day), ':', x.hour)",
				'entry'        => 'x.entry',
				'exit'         => 'x.exit_path',
				'source'       => 'x.source',
				'device'       => 'x.device',
				'journey'      => 'x.journey',
				'site'         => 'x.site_id',
			)[ $dimension ];
			if ( 'journey' === $dimension ) {
				$where[] = 'x.pages > 1';
			}
			$more  = 'journey' === $dimension ? ', MAX(x.more) AS more' : '';
			$group = in_array( $dimension, array( 'none', 'day', 'week', 'month', 'weekday' ), true ) ? "x.day, x.site_id, {$split}" : "k, {$split}";
			$sql   = "SELECT {$key} AS k, {$split} AS b, MIN(x.site_id) AS s, COUNT(*) AS visits, SUM(x.pages) AS views, SUM(x.pages = 1) AS singles{$more}
				FROM ({$visits}) x WHERE " . implode( ' AND ', $where ) . " GROUP BY {$group}";
			$all   = array_merge( $args, $wargs );
			if ( ! in_array( $dimension, self::TIME, true ) && 'none' !== $dimension ) {
				$sql  .= " ORDER BY {$order} DESC, k ASC LIMIT %d";
				$all[] = $query['limit'];
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Built from allowlists above; every value is a placeholder.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$all ), ARRAY_A );

		$out  = array();
		$seen = array();
		foreach ( $rows as $row ) {
			$k = (string) $row['k'];
			$b = (string) $row['b'];
			if ( isset( $row['s'] ) && in_array( $dimension, array( 'none', 'day', 'week', 'month', 'weekday' ), true ) ) {
				$seen[ (int) $row['s'] ][ $k ] = true;
			}
			self::add_counts(
				$out,
				$k,
				$b,
				array(
					'visits'  => (int) $row['visits'],
					'views'   => (int) ( $row['views'] ?? 0 ),
					'singles' => (int) ( $row['singles'] ?? 0 ),
					'known'   => (int) $row['visits'],
					'entries' => (int) ( $row['entries'] ?? 0 ),
					'exits'   => (int) ( $row['exits'] ?? 0 ),
					'more'    => ! empty( $row['more'] ),
				)
			);
		}

		// Days the views table no longer has: the archive's totals (views and visits; singles unknown).
		if ( in_array( $dimension, array( 'none', 'day', 'week', 'month', 'weekday' ), true ) && ! $filters && '' === $breakdown ) {
			$daily = Store::daily();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Own table; the IN list is placeholders.
			$archived = (array) $wpdb->get_results( $wpdb->prepare( "SELECT site_id, day, views, visitors FROM {$daily} WHERE site_id IN ({$in}) AND kind = 'total' AND day BETWEEN %s AND %s", ...array_merge( $site_ids, array( $first, $last ) ) ), ARRAY_A );
			foreach ( $archived as $row ) {
				if ( empty( $seen[ (int) $row['site_id'] ][ (string) $row['day'] ] ) ) {
					self::add_counts(
						$out,
						(string) $row['day'],
						'',
						array(
							'visits'  => (int) $row['visitors'],
							'views'   => (int) $row['views'],
							'singles' => 0,
							'known'   => 0,
							'entries' => 0,
							'exits'   => 0,
							'more'    => false,
						)
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Adds counts to a key and breakdown value.
	 *
	 * @param array<string, array<string, array<string, int|bool>>> $out    The counts.
	 * @param string                                                $key    The dimension's value.
	 * @param string                                                $split  The breakdown's value.
	 * @param array<string, int|bool>                               $counts The counts to add.
	 */
	private static function add_counts( array &$out, string $key, string $split, array $counts ): void {
		if ( ! isset( $out[ $key ][ $split ] ) ) {
			$out[ $key ][ $split ] = $counts;
			return;
		}
		foreach ( $counts as $name => $value ) {
			$out[ $key ][ $split ][ $name ] = 'more' === $name ? ( $out[ $key ][ $split ][ $name ] || $value ) : $out[ $key ][ $split ][ $name ] + $value;
		}
	}

	/**
	 * A metric from counts.
	 *
	 * @param string              $metric The metric.
	 * @param array<string, mixed> $c      The counts.
	 * @return float|int
	 */
	private static function value( string $metric, array $c ) {
		switch ( $metric ) {
			case 'pages_per_visit':
				return $c['visits'] > 0 ? round( $c['views'] / $c['visits'], 2 ) : 0;
			case 'single_page_rate':
				return $c['known'] > 0 ? round( 100 * $c['singles'] / $c['known'], 1 ) : 0;
			default:
				return (int) ( $c[ $metric ] ?? 0 );
		}
	}

	/**
	 * Groups day counts into the dimension's buckets, in order, every bucket
	 * present (time dimensions), or keeps the rows' order (the others).
	 *
	 * @param array<string, mixed>                                  $query  The query.
	 * @param array<string, array<string, array<string, int|bool>>> $counts key => split => counts.
	 * @param string                                                $first  Y-m-d.
	 * @param string                                                $last   Y-m-d.
	 * @return array<string, array<string, array<string, int|bool>>> bucket => split => counts, in order.
	 */
	private static function bucket( array $query, array $counts, string $first, string $last ): array {
		$dimension = $query['dimension'];
		$zero      = array();
		$out       = array();
		$start     = (int) strtotime( $first . ' 00:00:00 UTC' );
		$end       = (int) strtotime( $last . ' 00:00:00 UTC' );
		$of        = static function ( string $day ) use ( $dimension ): string {
			$time = (int) strtotime( $day . ' 00:00:00 UTC' );
			switch ( $dimension ) {
				case 'week':
					return gmdate( 'Y-m-d', $time - ( (int) gmdate( 'N', $time ) - 1 ) * DAY_IN_SECONDS );
				case 'month':
					return gmdate( 'Y-m', $time );
				case 'weekday':
					return (string) ( (int) gmdate( 'N', $time ) - 1 );
				case 'none':
					return '';
				default:
					return $day;
			}
		};
		switch ( $dimension ) {
			case 'none':
			case 'day':
			case 'week':
			case 'month':
				for ( $t = $start; $t <= $end; $t += DAY_IN_SECONDS ) {
					$out[ $of( gmdate( 'Y-m-d', $t ) ) ] = $zero;
				}
				break;
			case 'weekday':
				$out = array_fill_keys( array_map( 'strval', range( 0, 6 ) ), $zero );
				break;
			case 'hour':
				$out = array_fill_keys( array_map( 'strval', range( 0, 23 ) ), $zero );
				break;
			case 'hour_weekday':
				foreach ( range( 0, 6 ) as $w ) {
					foreach ( range( 0, 23 ) as $h ) {
						$out[ $w . ':' . $h ] = $zero;
					}
				}
				break;
		}
		foreach ( $counts as $key => $splits ) {
			$bucket = in_array( $dimension, array( 'none', 'day', 'week', 'month', 'weekday' ), true ) ? $of( (string) $key ) : (string) $key;
			if ( in_array( $dimension, self::TIME, true ) || 'none' === $dimension ) {
				if ( ! array_key_exists( $bucket, $out ) ) {
					continue; // Outside the period.
				}
			}
			foreach ( $splits as $split => $c ) {
				$merged = array( $bucket => $out[ $bucket ] ?? array() );
				self::add_counts( $merged, $bucket, (string) $split, $c );
				$out[ $bucket ] = $merged[ $bucket ];
			}
		}
		return $out;
	}

	/**
	 * The answer: keys and labels in order, and one series per metric (or
	 * per breakdown value), with the period before aligned to it.
	 *
	 * @param array<string, mixed>                                       $query The query.
	 * @param array<string, array<string, array<string, int|bool>>>      $now   This period's counts.
	 * @param array<string, array<string, array<string, int|bool>>>|null $prev  The period before's, or null.
	 * @param array<string, mixed>                                       $range The period.
	 * @return array<string, mixed>
	 */
	private static function shape( array $query, array $now, ?array $prev, array $range ): array {
		$dimension = $query['dimension'];
		$now       = self::bucket( $query, $now, $range['first'], $range['last'] );
		$prev      = null === $prev ? null : self::bucket( $query, $prev, $range['prior_first'], $range['prior_last'] );
		$keys      = array_map( 'strval', array_keys( $now ) );
		$total     = static function ( array $splits ): array {
			$sum = array(
				'visits'  => 0,
				'views'   => 0,
				'singles' => 0,
				'known'   => 0,
				'entries' => 0,
				'exits'   => 0,
				'more'    => false,
			);
			foreach ( $splits as $c ) {
				foreach ( $c as $name => $value ) {
					$sum[ $name ] = 'more' === $name ? ( $sum[ $name ] || $value ) : $sum[ $name ] + $value;
				}
			}
			return $sum;
		};

		$series = array();
		if ( '' !== $query['breakdown'] ) {
			$metric = $query['metrics'][0];
			$sums   = array();
			foreach ( $now as $splits ) {
				foreach ( $splits as $split => $c ) {
					$sums[ $split ] = ( $sums[ $split ] ?? 0 ) + (int) $c[ $metric ];
				}
			}
			arsort( $sums );
			$top   = array_slice( array_keys( $sums ), 0, self::BREAKDOWN_TOP, true );
			$top   = array_map( 'strval', $top );
			$other = count( $sums ) > count( $top );
			foreach ( $top as $split ) {
				$series[] = array(
					'key'      => $split,
					'label'    => self::label( $query['breakdown'], $split ),
					'metric'   => $metric,
					'data'     => array_map( static fn( array $splits ): int => (int) ( $splits[ $split ][ $metric ] ?? 0 ), array_values( $now ) ),
					'previous' => null,
				);
			}
			if ( $other ) {
				$series[] = array(
					'key'      => '__other',
					'label'    => __( 'Other', 'mody_cloud' ),
					'metric'   => $metric,
					'data'     => array_map(
						static function ( array $splits ) use ( $top, $metric ): int {
							$sum = 0;
							foreach ( $splits as $split => $c ) {
								$sum += in_array( (string) $split, $top, true ) ? 0 : (int) $c[ $metric ];
							}
							return $sum;
						},
						array_values( $now )
					),
					'previous' => null,
				);
			}
		} else {
			foreach ( $query['metrics'] as $metric ) {
				$series[] = array(
					'key'      => $metric,
					'label'    => self::metric_label( $metric ),
					'metric'   => $metric,
					'data'     => array_map( static fn( array $splits ) => self::value( $metric, $total( $splits ) ), array_values( $now ) ),
					'previous' => null === $prev ? null : array_map(
						static function ( string $key, int $index ) use ( $prev, $metric, $total, $dimension ) {
							if ( in_array( $dimension, array( 'day', 'week', 'month' ), true ) ) {
								$found = array_values( $prev )[ $index ] ?? array(); // Days, weeks and months line up by position.
							} else {
								$found = $prev[ $key ] ?? array();
							}
							return self::value( $metric, $total( $found ) );
						},
						$keys,
						array_keys( $keys )
					),
				);
			}
		}

		return array(
			'dimension' => $dimension,
			'keys'      => $keys,
			'labels'    => array_map( static fn( string $key ): string => self::label( $dimension, $key ), $keys ),
			'series'    => $series,
			'more'      => array_map( static fn( array $splits ): bool => (bool) $total( $splits )['more'], array_values( $now ) ),
			'first'     => $range['first'],
			'last'      => $range['last'],
		);
	}

	/**
	 * What a metric is called.
	 *
	 * @param string $metric The metric.
	 */
	public static function metric_label( string $metric ): string {
		return array(
			'visits'           => __( 'Visits', 'mody_cloud' ),
			'views'            => __( 'Page views', 'mody_cloud' ),
			'pages_per_visit'  => __( 'Pages per visit', 'mody_cloud' ),
			'single_page_rate' => __( 'Single-page visits (%)', 'mody_cloud' ),
			'entries'          => __( 'Entries', 'mody_cloud' ),
			'exits'            => __( 'Exits', 'mody_cloud' ),
		)[ $metric ] ?? $metric;
	}

	/**
	 * A value as people read it: a date, a weekday, an hour, a device, a
	 * source, a site's name.
	 *
	 * @param string $dimension The dimension (or breakdown).
	 * @param string $key       The value.
	 */
	public static function label( string $dimension, string $key ): string {
		global $wp_locale;
		$utc = new \DateTimeZone( 'UTC' );
		switch ( $dimension ) {
			case 'day':
			case 'week':
				return wp_date( 'j M', (int) strtotime( $key . ' 12:00:00 UTC' ), $utc );
			case 'month':
				return wp_date( 'M Y', (int) strtotime( $key . '-01 12:00:00 UTC' ), $utc );
			case 'weekday':
				return $wp_locale ? $wp_locale->get_weekday( ( (int) $key + 1 ) % 7 ) : $key;
			case 'hour':
				return sprintf( '%02d:00', (int) $key );
			case 'hour_weekday':
				return $key;
			case 'device':
				return array(
					'mobile'  => __( 'Phone', 'mody_cloud' ),
					'tablet'  => __( 'Tablet', 'mody_cloud' ),
					'desktop' => __( 'Computer', 'mody_cloud' ),
				)[ $key ] ?? __( 'Unknown', 'mody_cloud' );
			case 'source':
				return '' === $key ? __( 'Direct, or no source given', 'mody_cloud' ) : $key;
			case 'site':
				$name = (string) get_blog_option( (int) $key, 'blogname' );
				return '' !== $name ? $name : '#' . $key;
			case 'journey':
				return implode( ' → ', explode( "\n", $key ) );
			case 'none':
				return '';
			default:
				return $key;
		}
	}

	/**
	 * A refusal with its reason.
	 *
	 * @param string $reason Why.
	 */
	private static function error( string $reason ): WP_Error {
		return new WP_Error( 'mody_analytics_query', $reason );
	}
}
