<?php
/**
 * A chart on the analytics board: what it shows and how, never its numbers.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Dashboard;

use Mody\Analytics\Query;
use Mody\Platform\Login\Color;
use Mody\Platform\Login\Schemes;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A chart spec (tickets/analytics-chat.md): an id, a title, a type, a query
 * (Mody\Analytics\Query), a style and a size. The board stores specs; the
 * numbers are counted every time a chart is shown, so a chart always shows
 * the real ones. The analyst writes specs too, and validate() refuses a bad
 * one with the reason, so it can try again.
 *
 * - **Types:** Chart.js draws `line`, `area`, `bar`, `hbar`, `stacked` and
 *   `donut`; plain HTML shows `tile` (a number and its change), `list` (a
 *   ranked list with share bars), `table`, `journeys` and `heatmap` (hour by
 *   weekday).
 * - **Colors:** names from the site's palette, `admin` (the user's admin
 *   color scheme) or six-digit hex. A color under 3:1 against the card's
 *   white is refused: marks must be visible. With none, the board uses the
 *   admin color for one series and a validated set of eight in a fixed
 *   order for more (the dataviz reference palette).
 * - **One axis:** a chart with axes never mixes counts and ratios.
 */
final class ChartSpec {

	/** The chart types. */
	public const TYPES = array( 'tile', 'line', 'area', 'bar', 'hbar', 'stacked', 'donut', 'list', 'table', 'journeys', 'heatmap' );

	/** Types drawn with axes: one scale only. */
	public const AXES = array( 'line', 'area', 'bar', 'hbar', 'stacked' );

	/** Widths, as columns of 12. */
	public const SIZES = array(
		'small' => 3,
		'third' => 4,
		'half'  => 6,
		'wide'  => 8,
		'full'  => 12,
	);

	/** The card's background: marks are checked against it. */
	public const SURFACE = '#ffffff';

	/** The least contrast a mark's color needs against the card. */
	public const MIN_CONTRAST = 3.0;

	/** Longest title. */
	public const MAX_TITLE = 80;

	/**
	 * Checks a spec and fills its defaults.
	 *
	 * @param mixed $spec The spec.
	 * @return array{id: string, title: string, type: string, query: array<string, mixed>, style: array{colors: array<int, string>, legend: bool, values: bool}, size: string}|WP_Error The spec, or why not.
	 */
	public static function validate( $spec ) {
		if ( ! is_array( $spec ) ) {
			return self::error( 'A chart must be an object with id, title, type, query, style and size.' );
		}
		$unknown = array_diff( array_keys( $spec ), array( 'id', 'title', 'type', 'query', 'style', 'size' ) );
		if ( $unknown ) {
			return self::error( sprintf( 'Unknown chart field: %s.', implode( ', ', $unknown ) ) );
		}
		$type = (string) ( $spec['type'] ?? '' );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return self::error( sprintf( 'Unknown chart type "%s". Types: %s.', $type, implode( ', ', self::TYPES ) ) );
		}
		$query = Query::normalize( $spec['query'] ?? array() );
		if ( is_wp_error( $query ) ) {
			return self::error( $query->get_error_message() );
		}
		$fit = self::fits( $type, $query );
		if ( '' !== $fit ) {
			return self::error( $fit );
		}
		$title = trim( sanitize_text_field( (string) ( $spec['title'] ?? '' ) ) );
		if ( '' === $title ) {
			return self::error( 'A chart needs a title, such as "Visits per day".' );
		}
		$style = self::style( $spec['style'] ?? array() );
		if ( is_wp_error( $style ) ) {
			return $style;
		}
		$size = (string) ( $spec['size'] ?? self::default_size( $type ) );
		if ( ! isset( self::SIZES[ $size ] ) ) {
			return self::error( sprintf( 'Unknown size "%s". Sizes: %s.', $size, implode( ', ', array_keys( self::SIZES ) ) ) );
		}
		$id = sanitize_key( (string) ( $spec['id'] ?? '' ) );
		return array(
			'id'    => '' !== $id ? substr( $id, 0, 40 ) : 'chart-' . substr( md5( wp_json_encode( array( $type, $query, $title ) ) ), 0, 8 ),
			'title' => mb_substr( $title, 0, self::MAX_TITLE ),
			'type'  => $type,
			'query' => $query,
			'style' => $style,
			'size'  => $size,
		);
	}

	/**
	 * Why a type cannot show a query, or ''.
	 *
	 * @param string               $type  The type.
	 * @param array<string, mixed> $query A normalized query.
	 */
	private static function fits( string $type, array $query ): string {
		$dimension = $query['dimension'];
		$metrics   = $query['metrics'];
		$counts    = array_intersect( $metrics, Query::COUNTS );
		if ( in_array( $type, self::AXES, true ) && $counts && count( $counts ) !== count( $metrics ) ) {
			return 'Counts (visits, views, entries, exits) and ratios (pages_per_visit, single_page_rate) cannot share an axis. Make two charts, or use a table.';
		}
		switch ( $type ) {
			case 'tile':
				return 'none' === $dimension && 1 === count( $metrics ) ? '' : 'A tile shows one number: dimension "none" and one metric.';
			case 'journeys':
				return 'journey' === $dimension ? '' : 'The journeys type needs the dimension "journey".';
			case 'heatmap':
				return 'hour_weekday' === $dimension && 1 === count( $metrics ) ? '' : 'A heatmap needs the dimension "hour_weekday" and one metric.';
			case 'donut':
				if ( 1 !== count( $metrics ) || ! $counts ) {
					return 'A donut shows one count: visits, views, entries or exits.';
				}
				if ( ! in_array( $dimension, Query::PARTITIONS, true ) && ! ( 'path' === $dimension && 'views' === $metrics[0] ) ) {
					return sprintf( 'A donut needs parts of one whole: a dimension where each visit falls in one value (%s), or path with views. Use a bar chart instead.', implode( ', ', Query::PARTITIONS ) );
				}
				return '';
			case 'list':
				return 1 === count( $metrics ) && ! in_array( $dimension, array( 'none', 'hour_weekday', 'journey' ), true ) ? '' : 'A list ranks one metric over a dimension such as path, entry, exit, source or device.';
			case 'stacked':
				return '' !== $query['breakdown'] ? '' : 'A stacked chart needs a breakdown (device, source, entry or site) over a time dimension.';
			case 'line':
			case 'area':
				return in_array( $dimension, array( 'day', 'week', 'month', 'weekday', 'hour' ), true ) ? '' : 'Lines and areas need a time dimension: day, week, month, weekday or hour. Use a bar chart for the others.';
			case 'bar':
			case 'hbar':
				return in_array( $dimension, array( 'none', 'hour_weekday', 'journey' ), true ) ? 'Bars need a dimension other than none, hour_weekday or journey.' : '';
			default:
				return 'none' === $dimension && 'table' !== $type ? 'This type needs a dimension.' : '';
		}
	}

	/**
	 * Checks the style.
	 *
	 * @param mixed $style The style.
	 * @return array{colors: array<int, string>, legend: bool, values: bool}|WP_Error
	 */
	private static function style( $style ) {
		if ( ! is_array( $style ) ) {
			return self::error( 'style must be an object: colors, legend, values.' );
		}
		$unknown = array_diff( array_keys( $style ), array( 'colors', 'legend', 'values' ) );
		if ( $unknown ) {
			return self::error( sprintf( 'Unknown style field: %s.', implode( ', ', $unknown ) ) );
		}
		$colors = $style['colors'] ?? array();
		$colors = is_string( $colors ) ? array( $colors ) : $colors;
		if ( ! is_array( $colors ) || count( $colors ) > 8 ) {
			return self::error( 'colors must list at most 8 colors.' );
		}
		$clean = array();
		foreach ( $colors as $color ) {
			$color = strtolower( trim( (string) $color ) );
			$hex   = self::resolve( $color );
			if ( null === $hex ) {
				return self::error( sprintf( 'Unknown color "%s". Use a palette name (%s), "admin", or a six-digit hex such as #1f6feb.', $color, implode( ', ', array_keys( Schemes::palette() ) ) ) );
			}
			if ( 'admin' !== $hex && Color::contrast( $hex, self::SURFACE ) < self::MIN_CONTRAST ) {
				return self::error( sprintf( 'The color "%s" (%s) is too light to see on the white card: its contrast is %.1f:1 and marks need %.0f:1. Choose a darker color.', $color, $hex, Color::contrast( $hex, self::SURFACE ), self::MIN_CONTRAST ) );
			}
			$clean[] = $color;
		}
		return array(
			'colors' => $clean,
			'legend' => ! array_key_exists( 'legend', $style ) || (bool) $style['legend'],
			'values' => ! empty( $style['values'] ),
		);
	}

	/**
	 * A color as hex: a palette name, `admin` (kept as is), or a hex value.
	 *
	 * @param string $color The color as written.
	 * @return string|null Six-digit hex, 'admin', or null when unknown.
	 */
	public static function resolve( string $color ): ?string {
		if ( 'admin' === $color ) {
			return 'admin';
		}
		$palette = Schemes::palette();
		if ( isset( $palette[ $color ] ) ) {
			return $palette[ $color ];
		}
		return preg_match( '/^#[0-9a-f]{6}$/', $color ) ? $color : null;
	}

	/**
	 * A type's width when the spec names none.
	 *
	 * @param string $type The type.
	 */
	public static function default_size( string $type ): string {
		return array(
			'tile'     => 'small',
			'donut'    => 'third',
			'list'     => 'third',
			'journeys' => 'full',
			'heatmap'  => 'full',
		)[ $type ] ?? 'full';
	}

	/**
	 * A refusal with its reason.
	 *
	 * @param string $reason Why.
	 */
	private static function error( string $reason ): WP_Error {
		return new WP_Error( 'mody_chart_spec', $reason );
	}
}
