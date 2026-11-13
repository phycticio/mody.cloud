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
 *   days       1 to 395 (13 months, the retention), or
 *   from, to   dates (Y-m-d) within the retention (phase 3a, 2026-11-13)
 *   compare    true: the period just before; or {from, to}: any period
 *   filters    path ("/blog/*" for a prefix: visits that saw it), entry,
 *              source ("direct" for none), channel, device, country, city:
 *              each one value, a list, or {"not": value or list}; weekday
 *              (monday…sunday, "weekend", "weekdays" or 1–7) and hour
 *              ({from, to}, 0–23, wrapping past midnight)
 *   page       the page the `next` dimension starts from
 *   sort       desc (biggest first), asc (smallest first; pages with no
 *              views listed too) or label
 *   other      true: after `limit` rows, the rest added up as "Other"
 *   limit      1 to 50 rows (not for time dimensions, which show every step)
 *
 * The sites never come from the query: the caller passes them (the current
 * site, or a member's own sites). Answers are aggregates, never a visit ID
 * or a row. A city with fewer than MIN_CITY_VISITS visits in a period is
 * never shown by name (tickets/visitor-location.md).
 *
 * Everything about a visit (its day, first hour, pages, entry and exit
 * page, source, device, place, journey) comes from one subquery that groups
 * the views by visit, so every chart counts visits the same way. The `path`
 * and `next` dimensions read single views. Time dimensions are counted per
 * day and added up in PHP, so the archive's daily totals fill the days the
 * views table no longer has (views and visits only, without filters).
 */
final class Query {

	/** What can be counted. Counts add up; ratios do not, and never share an axis with counts. */
	public const METRICS = array( 'visits', 'views', 'pages_per_visit', 'single_page_rate', 'entries', 'exits', 'reached', 'conversion_rate' );

	/** Metrics that are counts. */
	public const COUNTS = array( 'visits', 'views', 'entries', 'exits', 'reached' );

	/** What a query can be split by. */
	public const DIMENSIONS = array( 'none', 'day', 'week', 'month', 'weekday', 'hour', 'hour_weekday', 'path', 'entry', 'exit', 'source', 'channel', 'device', 'country', 'city', 'journey', 'next', 'funnel', 'site' );

	/** Dimensions in time order, shown whole. */
	public const TIME = array( 'day', 'week', 'month', 'weekday', 'hour', 'hour_weekday' );

	/** Dimensions where every visit falls in exactly one value, so parts make a whole. */
	public const PARTITIONS = array( 'weekday', 'hour', 'entry', 'exit', 'source', 'channel', 'device', 'country', 'city', 'site' );

	/** What a series can be broken down by. */
	public const BREAKDOWNS = array( 'device', 'source', 'channel', 'entry', 'country', 'site' );

	/** The filters. */
	public const FILTERS = array( 'path', 'entry', 'source', 'channel', 'device', 'country', 'city', 'weekday', 'hour' );

	/** A city with fewer visits than this in a period is counted as "Other places in <country>" (tickets/visitor-location.md). */
	public const MIN_CITY_VISITS = 5;

	/** Devices. */
	public const DEVICES = array( 'mobile', 'tablet', 'desktop', 'unknown' );

	/** Weekday names, ISO numbers (Monday 1). */
	private const WEEKDAYS = array(
		'monday'    => 1,
		'tuesday'   => 2,
		'wednesday' => 3,
		'thursday'  => 4,
		'friday'    => 5,
		'saturday'  => 6,
		'sunday'    => 7,
	);

	/** Metrics each dimension allows; others allow the visit metrics. */
	private const ALLOWED = array(
		'path'    => array( 'views', 'visits', 'entries', 'exits' ),
		'next'    => array( 'visits' ),
		'journey' => array( 'visits' ),
		'funnel'  => array( 'visits' ),
	);

	/** The visit metrics: every dimension but path, next and journey. */
	private const VISIT_METRICS = array( 'visits', 'views', 'pages_per_visit', 'single_page_rate', 'reached', 'conversion_rate' );

	/** Steps of a funnel. */
	public const MAX_STEPS = 5;

	/** The longest period: the retention. */
	public const MAX_DAYS = 395;

	/** The most rows. */
	public const MAX_LIMIT = 50;

	/** Series a breakdown keeps before "Other". */
	public const BREAKDOWN_TOP = 5;

	/** Pages of a journey kept. */
	public const JOURNEY_STEPS = 5;

	/** The key of the "Other" row. */
	public const OTHER = '__other';

	/**
	 * Checks a query and fills its defaults.
	 *
	 * @param mixed $query The query, as the model or the board wrote it.
	 * @return array<string, mixed>|WP_Error The query, or why not.
	 */
	public static function normalize( $query ) {
		if ( ! is_array( $query ) ) {
			return self::error( 'The query must be an object.' );
		}
		$unknown = array_diff( array_keys( $query ), array( 'metrics', 'dimension', 'breakdown', 'days', 'from', 'to', 'compare', 'filters', 'page', 'goal', 'steps', 'sort', 'other', 'limit' ) );
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

		// The period: days, or dates.
		$days = $query['days'] ?? null;
		if ( null !== $days && ( ! is_numeric( $days ) || (int) $days < 1 || (int) $days > self::MAX_DAYS ) ) {
			return self::error( sprintf( 'days must be 1 to %d.', self::MAX_DAYS ) );
		}
		$dates = null;
		if ( isset( $query['from'] ) || isset( $query['to'] ) ) {
			$dates = self::dates( $query['from'] ?? '', $query['to'] ?? '' );
			if ( is_wp_error( $dates ) ) {
				return $dates;
			}
			$days = null;
		}
		$compare = $query['compare'] ?? false;
		if ( is_array( $compare ) ) {
			$compare = self::dates( $compare['from'] ?? '', $compare['to'] ?? '', 'compare' );
			if ( is_wp_error( $compare ) ) {
				return $compare;
			}
		} else {
			$compare = ! empty( $compare );
		}
		if ( $compare && ( '' !== $breakdown || in_array( $dimension, array( 'hour_weekday', 'journey' ), true ) ) ) {
			$compare = false; // No second period for these: the chart would not show it.
		}

		$filters = $query['filters'] ?? array();
		if ( ! is_array( $filters ) ) {
			return self::error( 'filters must be an object.' );
		}
		$clean = array();
		foreach ( $filters as $name => $value ) {
			$name = (string) $name;
			if ( ! in_array( $name, self::FILTERS, true ) ) {
				return self::error( sprintf( 'Unknown filter "%s". Filters: %s.', $name, implode( ', ', self::FILTERS ) ) );
			}
			$filter = self::filter( $name, $value );
			if ( is_wp_error( $filter ) ) {
				return $filter;
			}
			if ( null !== $filter ) {
				$clean[ $name ] = $filter;
			}
		}

		$page = '';
		if ( 'next' === $dimension ) {
			$page = Collector::path( (string) ( $query['page'] ?? '' ) );
			if ( '' === $page ) {
				return self::error( 'The "next" dimension needs "page": the page visits went on from, such as "/".' );
			}
		}
		// Goals (phase 3b): the visits that saw a page.
		$goal = array();
		if ( array_intersect( $metrics, array( 'reached', 'conversion_rate' ) ) ) {
			$goal = self::paths( $query['goal'] ?? '', 'goal' );
			if ( is_wp_error( $goal ) ) {
				return $goal;
			}
			if ( ! $goal ) {
				return self::error( 'reached and conversion_rate need "goal": the page a visit must reach, such as "/register/" (or "/blog/*").' );
			}
		}
		$steps = array();
		if ( 'funnel' === $dimension ) {
			$raw = $query['steps'] ?? array();
			if ( ! is_array( $raw ) || count( $raw ) < 2 || count( $raw ) > self::MAX_STEPS ) {
				return self::error( sprintf( 'A funnel needs "steps": 2 to %d pages in order, such as ["/", "/pricing/", "/register/"].', self::MAX_STEPS ) );
			}
			foreach ( $raw as $step ) {
				$one = self::paths( $step, 'steps' );
				if ( is_wp_error( $one ) ) {
					return $one;
				}
				if ( 1 !== count( $one ) ) {
					return self::error( 'Each step of a funnel is one page (or a prefix such as "/blog/*").' );
				}
				$steps[] = $one[0];
			}
		}
		$sort = (string) ( $query['sort'] ?? 'desc' );
		if ( ! in_array( $sort, array( 'desc', 'asc', 'label' ), true ) ) {
			return self::error( 'sort must be desc (biggest first), asc (smallest first) or label.' );
		}
		$other = ! empty( $query['other'] );
		if ( $other && ! in_array( $dimension, self::PARTITIONS, true ) && ! ( 'path' === $dimension && ! in_array( 'visits', $metrics, true ) ) ) {
			return self::error( sprintf( '"other" adds up the rest only where the parts make a whole: %s, or path without visits.', implode( ', ', self::PARTITIONS ) ) );
		}
		if ( $other && in_array( $dimension, self::TIME, true ) ) {
			$other = false; // Time dimensions show every step.
		}
		$limit = (int) ( $query['limit'] ?? 10 );
		return array(
			'metrics'   => $metrics,
			'dimension' => $dimension,
			'breakdown' => $breakdown,
			'days'      => null === $days ? null : (int) $days,
			'from'      => $dates['from'] ?? null,
			'to'        => $dates['to'] ?? null,
			'compare'   => $compare,
			'filters'   => $clean,
			'page'      => $page,
			'goal'      => $goal,
			'steps'     => $steps,
			'sort'      => $sort,
			'other'     => $other,
			'limit'     => max( 1, min( self::MAX_LIMIT, $limit ) ),
		);
	}

	/**
	 * Checks a period's dates: Y-m-d, within the retention, the end not past
	 * today (moved back to today when it is: "this month").
	 *
	 * @param mixed  $from  The first day.
	 * @param mixed  $to    The last day.
	 * @param string $field The field's name, for the reason.
	 * @return array{from: string, to: string}|WP_Error
	 */
	private static function dates( $from, $to, string $field = 'the period' ) {
		$parse = static function ( $day ): ?int {
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $day, new \DateTimeZone( 'UTC' ) );
			return $date && $date->format( 'Y-m-d' ) === (string) $day ? $date->getTimestamp() : null;
		};
		$a = $parse( $from );
		$b = $parse( $to );
		if ( null === $a || null === $b ) {
			return self::error( sprintf( 'The dates of %s must be "from" and "to", both as YYYY-MM-DD.', $field ) );
		}
		$today    = (int) strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 UTC' );
		$earliest = $today - ( self::MAX_DAYS - 1 ) * DAY_IN_SECONDS;
		$b        = min( $b, $today );
		if ( $a > $b ) {
			return self::error( sprintf( 'In %s, "from" must be before "to" and not after today (%s).', $field, gmdate( 'Y-m-d', $today ) ) );
		}
		if ( $a < $earliest ) {
			return self::error( sprintf( 'Visits are kept 13 months: %s cannot start before %s.', $field, gmdate( 'Y-m-d', $earliest ) ) );
		}
		return array(
			'from' => gmdate( 'Y-m-d', $a ),
			'to'   => gmdate( 'Y-m-d', $b ),
		);
	}

	/**
	 * Checks one page or a list of pages (a prefix ends in *).
	 *
	 * @param mixed  $value A path or a list.
	 * @param string $field The field's name, for the reason.
	 * @return array<int, string>|WP_Error
	 */
	private static function paths( $value, string $field ) {
		$clean = array();
		foreach ( is_array( $value ) ? $value : array( $value ) as $one ) {
			if ( ! is_scalar( $one ) || '' === trim( (string) $one ) ) {
				continue;
			}
			$one    = trim( (string) $one );
			$prefix = str_ends_with( $one, '*' );
			$path   = Collector::path( $prefix ? substr( $one, 0, -1 ) : $one );
			if ( '' === $path ) {
				return self::error( sprintf( '%s takes paths that start with "/".', $field ) );
			}
			$clean[] = $path . ( $prefix ? '*' : '' );
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Checks one filter.
	 *
	 * @param string $name  The filter.
	 * @param mixed  $value One value, a list, or {"not": …}; for hour {from, to}.
	 * @return array<string, mixed>|null|WP_Error The filter ({in: values} or {not: values}; hour {from, to}), null when empty, or why not.
	 */
	private static function filter( string $name, $value ) {
		if ( 'hour' === $name ) {
			if ( ! is_array( $value ) || ! isset( $value['from'], $value['to'] ) || ! is_numeric( $value['from'] ) || ! is_numeric( $value['to'] ) ) {
				return self::error( 'The hour filter is {"from": 9, "to": 17}: hours 0 to 23, both included.' );
			}
			$from = (int) $value['from'];
			$to   = (int) $value['to'];
			if ( $from < 0 || $from > 23 || $to < 0 || $to > 23 ) {
				return self::error( 'The hour filter takes hours from 0 to 23.' );
			}
			return array(
				'from' => $from,
				'to'   => $to,
			);
		}
		$op = 'in';
		if ( is_array( $value ) && array_key_exists( 'not', $value ) ) {
			$op    = 'not';
			$value = $value['not'];
		} elseif ( is_array( $value ) && array_key_exists( 'in', $value ) ) {
			$value = $value['in']; // The normalized form, as a stored chart has it.
		}
		$values = is_array( $value ) ? $value : array( $value );
		$clean  = array();
		foreach ( $values as $one ) {
			if ( ! is_scalar( $one ) ) {
				return self::error( sprintf( 'The %s filter takes one value, a list, or {"not": …}.', $name ) );
			}
			$one = trim( (string) $one );
			if ( '' === $one && 'source' !== $name ) {
				continue;
			}
			switch ( $name ) {
				case 'path':
				case 'entry':
					$prefix = 'path' === $name && str_ends_with( $one, '*' );
					$path   = Collector::path( $prefix ? substr( $one, 0, -1 ) : $one );
					if ( '' === $path ) {
						return self::error( sprintf( 'The %s filter takes paths that start with "/".', $name ) );
					}
					$clean[] = $path . ( $prefix ? '*' : '' );
					break;
				case 'source':
					$one = strtolower( $one );
					if ( '' !== $one && 'direct' !== $one && ! preg_match( '/^[a-z0-9.-]{1,191}$/', $one ) ) {
						return self::error( 'The source filter takes hosts, such as "instagram.com", or "direct".' );
					}
					$clean[] = 'direct' === $one ? '' : (string) preg_replace( '/^www\./', '', $one );
					break;
				case 'channel':
					$one = strtolower( $one );
					if ( ! in_array( $one, Channels::CHANNELS, true ) ) {
						return self::error( 'The channel filter takes: ' . implode( ', ', Channels::CHANNELS ) . '.' );
					}
					$clean[] = $one;
					break;
				case 'device':
					if ( ! in_array( $one, self::DEVICES, true ) ) {
						return self::error( 'The device filter takes: ' . implode( ', ', self::DEVICES ) . '.' );
					}
					$clean[] = $one;
					break;
				case 'country':
					$one = strtoupper( $one );
					if ( ! preg_match( '/^[A-Z]{2}$/', $one ) ) {
						return self::error( 'The country filter takes two-letter ISO codes, such as "ES".' );
					}
					$clean[] = $one;
					break;
				case 'city':
					$one = mb_substr( sanitize_text_field( $one ), 0, 80 );
					if ( '' !== $one ) {
						$clean[] = $one;
					}
					break;
				case 'weekday':
					$key = strtolower( $one );
					if ( 'weekend' === $key ) {
						array_push( $clean, 6, 7 );
					} elseif ( 'weekdays' === $key ) {
						array_push( $clean, 1, 2, 3, 4, 5 );
					} elseif ( isset( self::WEEKDAYS[ $key ] ) ) {
						$clean[] = self::WEEKDAYS[ $key ];
					} elseif ( ctype_digit( $key ) && (int) $key >= 1 && (int) $key <= 7 ) {
						$clean[] = (int) $key;
					} else {
						return self::error( 'The weekday filter takes monday to sunday, "weekend", "weekdays", or 1 (Monday) to 7.' );
					}
					break;
			}
		}
		$clean = array_values( array_unique( $clean, SORT_REGULAR ) );
		if ( ! $clean ) {
			return null;
		}
		if ( 'city' === $name && 'not' === $op ) {
			// Leaving one town out of a total would tell its number: not offered.
			return self::error( 'A city can be chosen but not left out: leaving a town out would reveal how many visits it had.' );
		}
		if ( 'weekday' === $name ) {
			sort( $clean );
		}
		return array( $op => $clean );
	}

	/**
	 * Runs a query.
	 *
	 * @param array<string, mixed> $query    A query; checked here again.
	 * @param array<int, int>      $site_ids The sites it counts: never from the query.
	 * @param int                  $days     The period when the query names none.
	 * @return array<string, mixed>|WP_Error
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
		$range = self::period( $query, max( 1, min( self::MAX_DAYS, $days ) ) );

		$cities = (array) ( $query['filters']['city']['in'] ?? array() );
		if ( $cities ) {
			// A small town is never shown by its number (tickets/visitor-location.md): fewer than 5 visits in a period, no answer.
			$periods = array( array( $range['first'], $range['last'] ) );
			if ( $query['compare'] ) {
				$periods[] = array( $range['prior_first'], $range['prior_last'] );
			}
			foreach ( $cities as $city ) {
				$town = array(
					'metrics'   => array( 'visits' ),
					'dimension' => 'none',
					'filters'   => array( 'city' => array( 'in' => array( $city ) ) ) + array_intersect_key( $query['filters'], array( 'country' => true ) ),
					'other'     => false,
				) + $query;
				foreach ( $periods as $period ) {
					$visits = 0;
					foreach ( self::counted( $town, $site_ids, $period[0], $period[1] ) as $splits ) {
						foreach ( $splits as $c ) {
							$visits += (int) $c['visits'];
						}
					}
					if ( $visits < self::MIN_CITY_VISITS ) {
						return new WP_Error( 'mody_analytics_small_place', sprintf( 'Fewer than %d visits came from %s in this period. Places with so few visits are not shown, to protect visitors\' privacy.', self::MIN_CITY_VISITS, $city ) );
					}
				}
			}
		}
		$now  = self::counted( $query, $site_ids, $range['first'], $range['last'] );
		$prev = $query['compare'] ? self::counted( $query, $site_ids, $range['prior_first'], $range['prior_last'], false ) : null;
		return self::shape( $query, $now, $prev, $range );
	}

	/**
	 * A query's period and the one it is compared with.
	 *
	 * @param array<string, mixed> $query A normalized query.
	 * @param int                  $days  The board's period, when the query names none.
	 * @return array{first: string, last: string, prior_first: string, prior_last: string, days: int}
	 */
	public static function period( array $query, int $days ): array {
		if ( null !== $query['from'] ) {
			$first = (int) strtotime( $query['from'] . ' 00:00:00 UTC' );
			$last  = (int) strtotime( $query['to'] . ' 00:00:00 UTC' );
			$count = (int) round( ( $last - $first ) / DAY_IN_SECONDS ) + 1;
			$range = array(
				'first'       => $query['from'],
				'last'        => $query['to'],
				'prior_first' => gmdate( 'Y-m-d', $first - $count * DAY_IN_SECONDS ),
				'prior_last'  => gmdate( 'Y-m-d', $first - DAY_IN_SECONDS ),
				'days'        => $count,
			);
		} else {
			$range = self::range( $query['days'] ?? $days );
		}
		if ( is_array( $query['compare'] ) ) {
			$range['prior_first'] = $query['compare']['from'];
			$range['prior_last']  = $query['compare']['to'];
		}
		return $range;
	}

	/**
	 * The last days' period and the one before.
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
	 * Two dates as people read them: "March 2026" for a whole month,
	 * otherwise "1 Jun – 15 Aug 2026".
	 *
	 * @param string $first Y-m-d.
	 * @param string $last  Y-m-d.
	 */
	public static function range_label( string $first, string $last ): string {
		$utc = new \DateTimeZone( 'UTC' );
		$a   = (int) strtotime( $first . ' 12:00:00 UTC' );
		$b   = (int) strtotime( $last . ' 12:00:00 UTC' );
		if ( '01' === gmdate( 'd', $a ) && gmdate( 'Y-m', $a ) === gmdate( 'Y-m', $b ) && gmdate( 't', $b ) === gmdate( 'd', $b ) ) {
			return wp_date( 'F Y', $a, $utc );
		}
		if ( $first === $last ) {
			return wp_date( 'j M Y', $a, $utc );
		}
		$start = gmdate( 'Y', $a ) === gmdate( 'Y', $b ) ? wp_date( 'j M', $a, $utc ) : wp_date( 'j M Y', $a, $utc );
		return $start . ' – ' . wp_date( 'j M Y', $b, $utc );
	}

	/**
	 * SQL for one filter on a column: IN or NOT IN, with placeholders.
	 *
	 * @param string               $column The column.
	 * @param array<string, mixed> $filter {in: values} or {not: values}.
	 * @param array<int, mixed>    $args   Placeholder values, added to.
	 */
	private static function in_sql( string $column, array $filter, array &$args ): string {
		$not    = isset( $filter['not'] );
		$values = array_values( (array) ( $filter['not'] ?? $filter['in'] ?? array() ) );
		if ( ! $values ) {
			return $not ? '1 = 1' : '1 = 0';
		}
		$args = array_merge( $args, $values );
		return $column . ( $not ? ' NOT IN (' : ' IN (' ) . implode( ',', array_fill( 0, count( $values ), is_int( $values[0] ) ? '%d' : '%s' ) ) . ')';
	}

	/**
	 * SQL that is true for a view whose path matches a path filter's values
	 * (exact, or a prefix ending in *), before any NOT.
	 *
	 * @param string            $column The path column.
	 * @param array<int, string> $paths  The values.
	 * @param array<int, mixed> $args   Placeholder values, added to.
	 */
	private static function path_sql( string $column, array $paths, array &$args ): string {
		global $wpdb;
		$parts = array();
		foreach ( $paths as $path ) {
			if ( str_ends_with( $path, '*' ) ) {
				$parts[] = $column . ' LIKE %s';
				$args[]  = $wpdb->esc_like( substr( $path, 0, -1 ) ) . '%';
			} else {
				$parts[] = $column . ' = %s';
				$args[]  = $path;
			}
		}
		return '(' . implode( ' OR ', $parts ) . ')';
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
	 * @param bool                 $slice    Whether to keep only the query's top rows (false: every row, for a period compared with).
	 * @return array<string, array<string, array<string, int|bool>>> key => breakdown value ('' without) => counts.
	 */
	private static function counted( array $query, array $site_ids, string $first, string $last, bool $slice = true ): array {
		global $wpdb;
		$dimension = $query['dimension'];
		$filters   = $query['filters'];
		$breakdown = $query['breakdown'];
		$views     = Store::views();
		$in        = implode( ',', array_fill( 0, count( $site_ids ), '%d' ) );

		// The visits: one row each, with everything a chart can split or filter by.
		$columns = array(
			'v.visit_id',
			'v.site_id',
			'MIN(v.day) AS day',
			'MIN(v.hour) AS hour',
			'COUNT(*) AS pages',
			'MAX(v.seq) AS last_seq',
			'MAX(CASE WHEN v.seq = 1 THEN v.path END) AS entry',
			'MAX(CASE WHEN v.seq = 1 THEN v.source END) AS source',
			'MAX(v.device) AS device',
			'MAX(CASE WHEN v.seq = 1 THEN v.country END) AS country',
			'MAX(CASE WHEN v.seq = 1 THEN v.city END) AS city',
		);
		$args    = array();
		if ( 'exit' === $dimension ) {
			$columns[] = "SUBSTRING_INDEX(GROUP_CONCAT(v.path ORDER BY v.seq DESC, v.id DESC SEPARATOR '\\n'), '\\n', 1) AS exit_path";
		}
		if ( 'journey' === $dimension ) {
			$columns[] = "SUBSTRING_INDEX(GROUP_CONCAT(v.path ORDER BY v.seq, v.id SEPARATOR '\\n'), '\\n', " . self::JOURNEY_STEPS . ') AS journey';
			$columns[] = 'IF(COUNT(*) > ' . self::JOURNEY_STEPS . ', 1, 0) AS more';
		}
		if ( $query['goal'] ) {
			$columns[] = 'SUM(' . self::path_sql( 'v.path', $query['goal'], $args ) . ') AS goal_hits';
		}
		$path = $filters['path'] ?? null;
		if ( $path && 'path' !== $dimension ) {
			$columns[] = 'SUM(' . self::path_sql( 'v.path', (array) ( $path['in'] ?? $path['not'] ), $args ) . ') AS saw';
		}
		$visits = 'SELECT ' . implode( ', ', $columns ) . " FROM {$views} v WHERE v.site_id IN ({$in}) AND v.day BETWEEN %s AND %s GROUP BY v.visit_id, v.site_id";
		$args   = array_merge( $args, $site_ids, array( $first, $last ) );

		// Filters on the visit.
		$where = array( '1 = 1' );
		$wargs = array();
		if ( $path && 'path' !== $dimension ) {
			$where[] = isset( $path['not'] ) ? 'x.saw = 0' : 'x.saw > 0';
		}
		foreach ( array( 'entry', 'source', 'device', 'country', 'city' ) as $name ) {
			if ( isset( $filters[ $name ] ) ) {
				$where[] = self::in_sql( 'x.' . $name, $filters[ $name ], $wargs );
			}
		}
		if ( isset( $filters['channel'] ) ) {
			// Channels are worked out from the hosts: the sources of the period that belong to them.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Own table; the IN list is placeholders.
			$hosts   = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT source FROM {$views} WHERE site_id IN ({$in}) AND day BETWEEN %s AND %s AND seq = 1", ...array_merge( $site_ids, array( $first, $last ) ) ) );
			$wanted  = (array) ( $filters['channel']['in'] ?? $filters['channel']['not'] );
			$members = array_values( array_filter( $hosts, static fn( $host ): bool => in_array( Channels::of( (string) $host ), $wanted, true ) ) );
			$where[] = self::in_sql( 'x.source', isset( $filters['channel']['not'] ) ? array( 'not' => $members ) : array( 'in' => $members ), $wargs );
		}
		if ( isset( $filters['weekday'] ) ) {
			$where[] = self::in_sql( '(WEEKDAY(x.day) + 1)', $filters['weekday'], $wargs );
		}
		if ( isset( $filters['hour'] ) ) {
			$from    = (int) $filters['hour']['from'];
			$to      = (int) $filters['hour']['to'];
			$where[] = $from <= $to ? 'x.hour BETWEEN %d AND %d' : '(x.hour >= %d OR x.hour <= %d)';
			array_push( $wargs, $from, $to );
		}

		if ( 'funnel' === $dimension ) {
			return self::funnel( $query, $visits, $args, $where, $wargs, $site_ids, $first, $last );
		}

		$columns_of = array(
			'entry'   => 'x.entry',
			'site'    => 'x.site_id',
			'channel' => 'x.source',
		);
		$split   = '' !== $breakdown ? ( $columns_of[ $breakdown ] ?? 'x.' . $breakdown ) : "''";
		$order   = in_array( 'views', $query['metrics'], true ) ? 'views' : 'visits';
		$by_time = in_array( $dimension, array( 'none', 'day', 'week', 'month', 'weekday' ), true );
		// Rows PHP has to see whole before keeping the top: grouped (city, channel), "Other", and pages nobody visited.
		$zeros    = 'asc' === $query['sort'] && 'path' === $dimension && 1 === count( $site_ids );
		$in_php   = in_array( $dimension, array( 'city', 'channel' ), true ) || $query['other'] || $zeros || ! $slice;
		$sql_sort = array(
			'desc'  => "{$order} DESC, k ASC",
			'asc'   => "{$order} ASC, k ASC",
			'label' => 'k ASC',
		)[ $query['sort'] ];

		$wpdb->query( 'SET SESSION group_concat_max_len = 65535' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Session setting.

		if ( 'path' === $dimension ) {
			$rwhere = array( 'r.site_id IN (' . $in . ')', 'r.day BETWEEN %s AND %s' );
			$rargs  = array_merge( $site_ids, array( $first, $last ) );
			if ( $path ) {
				$rwhere[] = ( isset( $path['not'] ) ? 'NOT ' : '' ) . self::path_sql( 'r.path', (array) ( $path['in'] ?? $path['not'] ), $rargs );
			}
			$sql = "SELECT r.path AS k, '' AS b, COUNT(*) AS views, COUNT(DISTINCT r.visit_id) AS visits, SUM(r.seq = 1) AS entries, SUM(r.seq = x.last_seq) AS exits
				FROM {$views} r JOIN ({$visits}) x ON x.visit_id = r.visit_id AND x.site_id = r.site_id
				WHERE " . implode( ' AND ', array_merge( $rwhere, $where ) ) . " GROUP BY r.path ORDER BY {$sql_sort}";
			$all = array_merge( $args, $rargs, $wargs );
		} elseif ( 'next' === $dimension ) {
			$sql = "SELECT n.path AS k, '' AS b, COUNT(*) AS visits
				FROM {$views} r JOIN {$views} n ON n.visit_id = r.visit_id AND n.site_id = r.site_id AND n.seq = r.seq + 1
				JOIN ({$visits}) x ON x.visit_id = r.visit_id AND x.site_id = r.site_id
				WHERE r.site_id IN ({$in}) AND r.day BETWEEN %s AND %s AND r.path = %s AND " . implode( ' AND ', $where ) . ' GROUP BY n.path ORDER BY ' . str_replace( 'views', 'visits', $sql_sort );
			$all = array_merge( $args, $site_ids, array( $first, $last, $query['page'] ), $wargs );
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
				'channel'      => 'x.source',
				'device'       => 'x.device',
				'country'      => 'x.country',
				'city'         => "CONCAT(x.country, '|', x.city)",
				'journey'      => 'x.journey',
				'site'         => 'x.site_id',
			)[ $dimension ];
			if ( 'journey' === $dimension ) {
				$where[] = 'x.pages > 1';
			}
			$more    = 'journey' === $dimension ? ', MAX(x.more) AS more' : '';
			$reached = $query['goal'] ? ', SUM(x.goal_hits > 0) AS reached' : '';
			$group = $by_time ? "x.day, x.site_id, {$split}" : "k, {$split}";
			$sql   = "SELECT {$key} AS k, {$split} AS b, MIN(x.site_id) AS s, COUNT(*) AS visits, SUM(x.pages) AS views, SUM(x.pages = 1) AS singles{$reached}{$more}
				FROM ({$visits}) x WHERE " . implode( ' AND ', $where ) . " GROUP BY {$group}";
			$all   = array_merge( $args, $wargs );
			if ( ! $by_time && ! in_array( $dimension, self::TIME, true ) ) {
				$sql .= " ORDER BY {$sql_sort}";
			}
		}
		if ( ! $by_time && ! in_array( $dimension, self::TIME, true ) && ! $in_php ) {
			$sql  .= ' LIMIT %d';
			$all[] = $query['limit'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Built from allowlists above; every value is a placeholder.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$all ), ARRAY_A );

		$out  = array();
		$seen = array();
		foreach ( $rows as $row ) {
			$k = (string) $row['k'];
			$b = (string) $row['b'];
			if ( 'channel' === $dimension ) {
				$k = Channels::of( $k );
			}
			if ( 'channel' === $breakdown ) {
				$b = Channels::of( $b );
			}
			if ( isset( $row['s'] ) && $by_time ) {
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
					'reached' => (int) ( $row['reached'] ?? 0 ),
					'more'    => ! empty( $row['more'] ),
				)
			);
		}

		if ( $zeros ) {
			foreach ( self::published_paths( $site_ids[0] ) as $published ) {
				if ( ! isset( $out[ $published ] ) && ( ! $path || self::matches_path( $published, $path ) ) ) {
					$out[ $published ][''] = array(
						'visits'  => 0,
						'views'   => 0,
						'singles' => 0,
						'known'   => 0,
						'entries' => 0,
						'exits'   => 0,
						'reached' => 0,
						'more'    => false,
					);
				}
			}
		}
		if ( 'city' === $dimension ) {
			$out = self::fold_small_places( $out );
		}
		if ( $in_php && ! $by_time && ! in_array( $dimension, self::TIME, true ) ) {
			$out = self::sorted( $out, $order, $query['sort'] );
			if ( $slice ) {
				$out = self::keep( $out, $query['limit'], $query['other'] );
			}
		}

		// Days the views table no longer has: the archive's totals (views and visits; singles unknown).
		if ( $by_time && ! $filters && '' === $breakdown ) {
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
							'reached' => 0,
							'more'    => false,
						)
					);
				}
			}
		}
		return $out;
	}

	/**
	 * A funnel: the visits that saw each step after the one before, in the
	 * same visit (not necessarily next). Each visit's pages that match a
	 * step come back in order, and PHP walks them: a page that matches the
	 * step it waits for moves it on, so steps can repeat ("/" → "/pricing/"
	 * → "/") and be prefixes.
	 *
	 * @param array<string, mixed> $query    The query.
	 * @param string               $visits   The visits subquery.
	 * @param array<int, mixed>    $args     Its placeholder values.
	 * @param array<int, string>   $where    Filters on the visit.
	 * @param array<int, mixed>    $wargs    Their placeholder values.
	 * @param array<int, int>      $site_ids Sites.
	 * @param string               $first    Y-m-d.
	 * @param string               $last     Y-m-d.
	 * @return array<string, array<string, array<string, int|bool>>> `n|path` => '' => counts, in step order.
	 */
	private static function funnel( array $query, string $visits, array $args, array $where, array $wargs, array $site_ids, string $first, string $last ): array {
		global $wpdb;
		$views = Store::views();
		$in    = implode( ',', array_fill( 0, count( $site_ids ), '%d' ) );
		$sargs = array();
		$any   = self::path_sql( 'r.path', $query['steps'], $sargs );
		$sql   = "SELECT GROUP_CONCAT(r.path ORDER BY r.seq, r.id SEPARATOR '\n') AS pages
			FROM {$views} r JOIN ({$visits}) x ON x.visit_id = r.visit_id AND x.site_id = r.site_id
			WHERE r.site_id IN ({$in}) AND r.day BETWEEN %s AND %s AND {$any} AND " . implode( ' AND ', $where ) . '
			GROUP BY r.visit_id, r.site_id';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Built from allowlists; every value is a placeholder.
		$visits_pages = (array) $wpdb->get_col( $wpdb->prepare( $sql, ...array_merge( $args, $site_ids, array( $first, $last ), $sargs, $wargs ) ) );
		$reached = array_fill( 0, count( $query['steps'] ), 0 );
		foreach ( $visits_pages as $list ) {
			$at = 0;
			foreach ( explode( "\n", (string) $list ) as $page ) {
				$step = $query['steps'][ $at ];
				if ( str_ends_with( $step, '*' ) ? str_starts_with( $page, substr( $step, 0, -1 ) ) : $page === $step ) {
					++$reached[ $at ];
					++$at;
					if ( $at >= count( $query['steps'] ) ) {
						break;
					}
				}
			}
		}
		$out = array();
		foreach ( $query['steps'] as $i => $step ) {
			$out[ ( $i + 1 ) . '|' . $step ][''] = array(
				'visits'  => $reached[ $i ],
				'views'   => 0,
				'singles' => 0,
				'known'   => $reached[ $i ],
				'entries' => 0,
				'exits'   => 0,
				'reached' => 0,
				'more'    => false,
			);
		}
		return $out;
	}

	/**
	 * Whether a path matches a path filter.
	 *
	 * @param string               $path   A path.
	 * @param array<string, mixed> $filter {in: …} or {not: …}.
	 */
	private static function matches_path( string $path, array $filter ): bool {
		$hit = false;
		foreach ( (array) ( $filter['in'] ?? $filter['not'] ) as $value ) {
			$hit = $hit || ( str_ends_with( $value, '*' ) ? str_starts_with( $path, substr( $value, 0, -1 ) ) : $path === $value );
		}
		return isset( $filter['not'] ) ? ! $hit : $hit;
	}

	/**
	 * A site's published pages, as paths: "the pages nobody visits" lists
	 * them at 0.
	 *
	 * @param int $site_id The site.
	 * @return array<int, string>
	 */
	private static function published_paths( int $site_id ): array {
		$switch = get_current_blog_id() !== $site_id;
		if ( $switch ) {
			switch_to_blog( $site_id );
		}
		$paths = array( '/' );
		foreach ( get_pages( array( 'post_status' => 'publish' ) ) as $page ) {
			$path = (string) wp_make_link_relative( (string) get_permalink( $page ) );
			if ( '' !== $path ) {
				$paths[] = $path;
			}
		}
		if ( $switch ) {
			restore_current_blog();
		}
		return array_values( array_unique( $paths ) );
	}

	/**
	 * Cities with fewer than MIN_CITY_VISITS visits become "Other places in
	 * <country>" (`~XX`). Every city is counted first, so these add up.
	 *
	 * @param array<string, array<string, array<string, int|bool>>> $counts City key (`XX|Name`) => split => counts.
	 * @return array<string, array<string, array<string, int|bool>>>
	 */
	private static function fold_small_places( array $counts ): array {
		$out = array();
		foreach ( $counts as $key => $splits ) {
			$visits = 0;
			foreach ( $splits as $c ) {
				$visits += (int) $c['visits'];
			}
			$key = (string) $key;
			if ( $visits < self::MIN_CITY_VISITS || str_ends_with( $key, '|' ) ) {
				$key = '~' . strstr( $key, '|', true ); // Small, or a place DB-IP knows only by its country.
			}
			foreach ( $splits as $split => $c ) {
				self::add_counts( $out, $key, (string) $split, $c );
			}
		}
		return $out;
	}

	/**
	 * Rows in the query's order. Grouped rows ("Other places in…", "Other")
	 * always come last.
	 *
	 * @param array<string, array<string, array<string, int|bool>>> $counts The rows.
	 * @param string                                                $order  visits or views.
	 * @param string                                                $sort   desc, asc or label.
	 * @return array<string, array<string, array<string, int|bool>>>
	 */
	private static function sorted( array $counts, string $order, string $sort ): array {
		$rank = static function ( array $splits ) use ( $order ): int {
			$sum = 0;
			foreach ( $splits as $c ) {
				$sum += (int) $c[ $order ];
			}
			return $sum;
		};
		uksort(
			$counts,
			static function ( $a, $b ) use ( $counts, $rank, $sort ): int {
				$a       = (string) $a;
				$b       = (string) $b;
				$grouped = ( str_starts_with( $a, '~' ) || self::OTHER === $a ) <=> ( str_starts_with( $b, '~' ) || self::OTHER === $b );
				if ( $grouped ) {
					return $grouped;
				}
				if ( 'label' === $sort ) {
					return strcmp( $a, $b );
				}
				$by = 'asc' === $sort ? $rank( $counts[ $a ] ) <=> $rank( $counts[ $b ] ) : $rank( $counts[ $b ] ) <=> $rank( $counts[ $a ] );
				return $by ?: strcmp( $a, $b );
			}
		);
		return $counts;
	}

	/**
	 * The first rows, and with `other` the rest added up as one "Other" row.
	 *
	 * @param array<string, array<string, array<string, int|bool>>> $counts The rows, in order.
	 * @param int                                                   $limit  Rows kept.
	 * @param bool                                                  $other  Whether to add up the rest.
	 * @return array<string, array<string, array<string, int|bool>>>
	 */
	private static function keep( array $counts, int $limit, bool $other ): array {
		$kept = array_slice( $counts, 0, $limit, true );
		if ( $other && count( $counts ) > $limit ) {
			foreach ( array_slice( $counts, $limit, null, true ) as $splits ) {
				foreach ( $splits as $split => $c ) {
					self::add_counts( $kept, self::OTHER, (string) $split, $c );
				}
			}
		}
		return $kept;
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
	 * @param string               $metric The metric.
	 * @param array<string, mixed> $c      The counts.
	 * @return float|int
	 */
	private static function value( string $metric, array $c ) {
		switch ( $metric ) {
			case 'conversion_rate':
				return $c['known'] > 0 ? round( 100 * (int) ( $c['reached'] ?? 0 ) / $c['known'], 1 ) : 0;
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
		$of        = static function ( string $day ) use ( $dimension, $start ): string {
			$time = (int) strtotime( $day . ' 00:00:00 UTC' );
			switch ( $dimension ) {
				case 'week':
					// A week is named by its Monday, or by the period's first day when the period starts mid-week.
					return gmdate( 'Y-m-d', max( $start, $time - ( (int) gmdate( 'N', $time ) - 1 ) * DAY_IN_SECONDS ) );
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
	 * per breakdown value), with the compared period aligned to it.
	 *
	 * @param array<string, mixed>                                       $query The query.
	 * @param array<string, array<string, array<string, int|bool>>>      $now   This period's counts.
	 * @param array<string, array<string, array<string, int|bool>>>|null $prev  The compared period's, every row; or null.
	 * @param array<string, mixed>                                       $range The period.
	 * @return array<string, mixed>
	 */
	private static function shape( array $query, array $now, ?array $prev, array $range ): array {
		$dimension = $query['dimension'];
		$now       = self::bucket( $query, $now, $range['first'], $range['last'] );
		$prev      = null === $prev ? null : self::bucket( $query, $prev, $range['prior_first'], $range['prior_last'] );
		$keys      = array_map( 'strval', array_keys( $now ) );
		if ( null !== $prev && $query['other'] && isset( $now[ self::OTHER ] ) ) {
			// The compared period's rows that are not shown add up to its "Other".
			foreach ( $prev as $key => $splits ) {
				if ( ! in_array( (string) $key, $keys, true ) ) {
					foreach ( $splits as $split => $c ) {
						self::add_counts( $prev, self::OTHER, (string) $split, $c );
					}
				}
			}
		}
		$total = static function ( array $splits ): array {
			$sum = array(
				'visits'  => 0,
				'views'   => 0,
				'singles' => 0,
				'known'   => 0,
				'entries' => 0,
				'exits'   => 0,
				'reached' => 0,
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
			$top   = array_map( 'strval', array_slice( array_keys( $sums ), 0, self::BREAKDOWN_TOP, true ) );
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
					'key'      => self::OTHER,
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
			'dimension'      => $dimension,
			'keys'           => $keys,
			'labels'         => array_map( static fn( string $key ): string => self::label( $dimension, $key ), $keys ),
			'series'         => $series,
			'more'           => array_map( static fn( array $splits ): bool => (bool) $total( $splits )['more'], array_values( $now ) ),
			'first'          => $range['first'],
			'last'           => $range['last'],
			'period_label'   => self::range_label( $range['first'], $range['last'] ),
			'previous_label' => null === $prev ? '' : self::range_label( $range['prior_first'], $range['prior_last'] ),
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
			'reached'          => __( 'Visits that reached the goal', 'mody_cloud' ),
			'conversion_rate'  => __( 'Conversion rate (%)', 'mody_cloud' ),
		)[ $metric ] ?? $metric;
	}

	/**
	 * A value as people read it: a date, a weekday, an hour, a device, a
	 * source, a channel, a place, a site's name.
	 *
	 * @param string $dimension The dimension (or breakdown).
	 * @param string $key       The value.
	 */
	public static function label( string $dimension, string $key ): string {
		global $wp_locale;
		if ( self::OTHER === $key ) {
			return __( 'Other', 'mody_cloud' );
		}
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
			case 'channel':
				return Channels::label( $key );
			case 'country':
				return Geo\Locator::country_name( $key );
			case 'city':
				if ( str_starts_with( $key, '~' ) ) {
					$code = substr( $key, 1 );
					/* translators: %s: a country. */
					return '' === $code ? __( 'Unknown', 'mody_cloud' ) : sprintf( __( 'Other places in %s', 'mody_cloud' ), Geo\Locator::country_name( $code ) );
				}
				list( $code, $name ) = array_pad( explode( '|', $key, 2 ), 2, '' );
				/* translators: 1: a city, 2: its country. */
				return sprintf( __( '%1$s, %2$s', 'mody_cloud' ), $name, Geo\Locator::country_name( $code ) );
			case 'site':
				$name = (string) get_blog_option( (int) $key, 'blogname' );
				return '' !== $name ? $name : '#' . $key;
			case 'journey':
				return implode( ' → ', explode( "\n", $key ) );
			case 'funnel':
				list( $number, $step ) = array_pad( explode( '|', $key, 2 ), 2, '' );
				return $number . '. ' . $step;
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
