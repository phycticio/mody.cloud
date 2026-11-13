<?php
/**
 * The analytics board: the site's charts, their numbers, and changes to them.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Dashboard;

use Mody\Analytics\Query;
use Mody\Platform\Login\Schemes;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * One board per site (the maintainer's choice, 2026-11-12,
 * tickets/analytics-chat.md): an ordered list of chart specs in the site
 * option `mody_analytics_board`, shared by its administrators. While the
 * option is empty the board is the default one (DEFAULTS: what the Visitors
 * section showed before). The board before the last change is kept in
 * `mody_analytics_board_undo`, so one Undo goes back.
 *
 * Every change goes through apply(): the card buttons' route here, and the
 * analyst (phase 2) through the same method. Numbers are counted when a
 * chart is shown, for the current site only (Query::run() gets the site
 * from here, never from the spec).
 *
 * REST, `manage_options` on the site (cookie and nonce):
 * - GET  mody-platform/v1/analytics/board?days=30: the cards with their numbers;
 * - POST mody-platform/v1/analytics/board: {action: move|remove|reset|undo, id, to};
 * - POST mody-platform/v1/analytics/board/chart: {spec, days}: one chart's numbers.
 */
final class Board {

	/** The site option with the cards. */
	public const OPTION = 'mody_analytics_board';

	/** The site option with the board before the last change. */
	public const UNDO_OPTION = 'mody_analytics_board_undo';

	/** The most cards on a board. */
	public const MAX_CARDS = 24;

	/** Who sees and changes the board: the site's administrators, as for Visitors. */
	public const CAPABILITY = 'manage_options';

	/** The REST namespace (the platform's). */
	public const REST_NAMESPACE = 'mody-platform/v1';

	/** The validated colors for several series, in their fixed order (the dataviz reference palette, light). */
	public const SERIES_COLORS = array( '#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948' );

	/** The period before, and "Other": a gray that still clears 3:1 on white. */
	public const MUTED = '#8c8f94';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * The default board: the Visitors section as it was, as specs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function defaults(): array {
		$list = static fn( string $id, string $title, string $dimension ): array => array(
			'id'    => $id,
			'title' => $title,
			'type'  => 'list',
			'query' => array(
				'metrics'   => array( 'path' === $dimension ? 'views' : 'visits' ),
				'dimension' => $dimension,
				'limit'     => 10,
			),
			'size'  => 'third',
		);
		$tile = static fn( string $id, string $title, string $metric ): array => array(
			'id'    => $id,
			'title' => $title,
			'type'  => 'tile',
			'query' => array(
				'metrics'   => array( $metric ),
				'dimension' => 'none',
				'compare'   => in_array( $metric, Query::COUNTS, true ),
			),
			'size'  => 'small',
		);
		return array(
			$tile( 'visits', __( 'Visits', 'mody_cloud' ), 'visits' ),
			$tile( 'views', __( 'Page views', 'mody_cloud' ), 'views' ),
			$tile( 'pages-per-visit', __( 'Pages per visit', 'mody_cloud' ), 'pages_per_visit' ),
			$tile( 'single-page', __( 'Single-page visits', 'mody_cloud' ), 'single_page_rate' ),
			array(
				'id'    => 'visits-per-day',
				'title' => __( 'Visits per day', 'mody_cloud' ),
				'type'  => 'bar',
				'query' => array(
					'metrics'   => array( 'visits' ),
					'dimension' => 'day',
				),
				'style' => array( 'legend' => false ),
				'size'  => 'full',
			),
			array(
				'id'    => 'journeys',
				'title' => __( 'Journeys', 'mody_cloud' ),
				'type'  => 'journeys',
				'query' => array(
					'metrics'   => array( 'visits' ),
					'dimension' => 'journey',
					'limit'     => 10,
				),
				'size'  => 'full',
			),
			$list( 'pages', __( 'Pages', 'mody_cloud' ), 'path' ),
			$list( 'entry-pages', __( 'Entry pages', 'mody_cloud' ), 'entry' ),
			$list( 'exit-pages', __( 'Exit pages', 'mody_cloud' ), 'exit' ),
			$list( 'sources', __( 'Sources', 'mody_cloud' ), 'source' ),
			$list( 'devices', __( 'Devices', 'mody_cloud' ), 'device' ),
			$list( 'countries', __( 'Countries', 'mody_cloud' ), 'country' ), // tickets/visitor-location.md (2026-11-13).
			$list( 'cities', __( 'Cities', 'mody_cloud' ), 'city' ),
		);
	}

	/**
	 * The current site's cards, validated (a card that no longer passes is
	 * left out), or the default board.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function cards(): array {
		$stored = get_option( self::OPTION );
		$cards  = is_array( $stored ) && $stored ? $stored : self::defaults();
		$out    = array();
		foreach ( $cards as $card ) {
			$spec = ChartSpec::validate( $card );
			if ( ! is_wp_error( $spec ) ) {
				$out[] = $spec;
			}
		}
		return $out;
	}

	/**
	 * Whether the board is the default one.
	 */
	public static function is_default(): bool {
		$stored = get_option( self::OPTION );
		return ! is_array( $stored ) || ! $stored;
	}

	/**
	 * Whether there is a board to go back to.
	 */
	public static function can_undo(): bool {
		return is_array( get_option( self::UNDO_OPTION ) );
	}

	/**
	 * Changes the board.
	 *
	 * @param string               $action add, change, remove, move, reset or undo.
	 * @param array<string, mixed> $args   id (change, remove, move), spec (add: a whole spec; change: the fields to change), to (move: up, down, or a position from 0), after (add: an id, or '' for the top; the end by default).
	 * @return array{cards: array<int, array<string, mixed>>, changed: array<int, string>}|WP_Error The new board and the ids it changed, or why not.
	 */
	public static function apply( string $action, array $args = array() ) {
		$cards = self::cards();
		$ids   = array_column( $cards, 'id' );
		$id    = sanitize_key( (string) ( $args['id'] ?? '' ) );
		$index = '' !== $id ? array_search( $id, $ids, true ) : false;
		$needs = array( 'change', 'remove', 'move' );
		if ( in_array( $action, $needs, true ) && false === $index ) {
			return new WP_Error( 'mody_board_unknown_chart', sprintf( 'No chart with the id "%s". Charts: %s.', $id, implode( ', ', $ids ) ) );
		}
		$changed = array();
		switch ( $action ) {
			case 'add':
				if ( count( $cards ) >= self::MAX_CARDS ) {
					return new WP_Error( 'mody_board_full', sprintf( 'The board holds at most %d charts. Remove one first.', self::MAX_CARDS ) );
				}
				$spec = ChartSpec::validate( $args['spec'] ?? null );
				if ( is_wp_error( $spec ) ) {
					return $spec;
				}
				$spec['id'] = self::unique_id( $spec['id'], $ids );
				$after      = array_key_exists( 'after', $args ) ? sanitize_key( (string) $args['after'] ) : null;
				$at         = null === $after ? count( $cards ) : ( '' === $after ? 0 : (int) array_search( $after, $ids, true ) + 1 );
				array_splice( $cards, max( 0, min( count( $cards ), $at ) ), 0, array( $spec ) );
				$changed[] = $spec['id'];
				break;
			case 'change':
				$fields = $args['spec'] ?? null;
				if ( ! is_array( $fields ) ) {
					return new WP_Error( 'mody_chart_spec', 'To change a chart, give the fields to change in "spec".' );
				}
				$merged = $cards[ $index ];
				foreach ( array( 'title', 'type', 'size' ) as $field ) {
					if ( array_key_exists( $field, $fields ) ) {
						$merged[ $field ] = $fields[ $field ];
					}
				}
				foreach ( array( 'query', 'style' ) as $field ) {
					if ( array_key_exists( $field, $fields ) ) {
						if ( ! is_array( $fields[ $field ] ) ) {
							return new WP_Error( 'mody_chart_spec', sprintf( '%s must be an object.', $field ) );
						}
						$merged[ $field ] = array_merge( (array) $merged[ $field ], $fields[ $field ] );
					}
				}
				if ( isset( $fields['type'] ) && ! array_key_exists( 'size', $fields ) ) { // A new type takes its own width unless one is given.
					$merged['size'] = ChartSpec::default_size( (string) $merged['type'] );
				}
				$spec = ChartSpec::validate( $merged );
				if ( is_wp_error( $spec ) ) {
					return $spec;
				}
				$spec['id']      = $cards[ $index ]['id'];
				$cards[ $index ] = $spec;
				$changed[]       = $spec['id'];
				break;
			case 'remove':
				array_splice( $cards, (int) $index, 1 );
				break;
			case 'move':
				$to = $args['to'] ?? 'down';
				$to = 'up' === $to ? $index - 1 : ( 'down' === $to ? $index + 1 : (int) $to );
				$to = max( 0, min( count( $cards ) - 1, $to ) );
				$card = array_splice( $cards, (int) $index, 1 );
				array_splice( $cards, $to, 0, $card );
				$changed[] = $id;
				break;
			case 'reset':
				self::remember();
				delete_option( self::OPTION );
				return array(
					'cards'   => self::defaults_validated(),
					'changed' => array(),
				);
			case 'undo':
				$before = get_option( self::UNDO_OPTION );
				if ( ! is_array( $before ) ) {
					return new WP_Error( 'mody_board_no_undo', 'There is no change to undo.' );
				}
				delete_option( self::UNDO_OPTION );
				if ( $before ) {
					update_option( self::OPTION, $before, false );
				} else {
					delete_option( self::OPTION );
				}
				return array(
					'cards'   => self::cards(),
					'changed' => array(),
				);
			default:
				return new WP_Error( 'mody_board_action', 'Unknown action. Actions: add, change, remove, move, reset, undo.' );
		}
		self::remember();
		update_option( self::OPTION, array_values( $cards ), false );
		return array(
			'cards'   => array_values( $cards ),
			'changed' => $changed,
		);
	}

	/**
	 * Keeps the board as it is now for Undo: the stored cards, or an empty
	 * list for the default board.
	 */
	private static function remember(): void {
		$stored = get_option( self::OPTION );
		update_option( self::UNDO_OPTION, is_array( $stored ) ? $stored : array(), false );
	}

	/**
	 * The default board, validated.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function defaults_validated(): array {
		return array_values( array_filter( array_map( array( ChartSpec::class, 'validate' ), self::defaults() ), static fn( $spec ): bool => ! is_wp_error( $spec ) ) );
	}

	/**
	 * An id not used on the board yet.
	 *
	 * @param string             $id  The wanted id.
	 * @param array<int, string> $ids The ids in use.
	 */
	private static function unique_id( string $id, array $ids ): string {
		$base = $id;
		for ( $n = 2; in_array( $id, $ids, true ); $n++ ) {
			$id = substr( $base, 0, 36 ) . '-' . $n;
		}
		return $id;
	}

	/**
	 * A card with its numbers, for the current site, and its colors as the
	 * browser draws them.
	 *
	 * @param array<string, mixed> $spec A validated spec.
	 * @param int                  $days The board's period, for cards that name none.
	 * @return array<string, mixed>
	 */
	public static function with_data( array $spec, int $days ): array {
		$data = Query::run( $spec['query'], array( get_current_blog_id() ), $days );
		return array(
			'spec'   => $spec,
			'colors' => self::colors( $spec ),
			'days'   => $spec['query']['days'] ?? $days,
			'data'   => is_wp_error( $data ) ? null : $data,
			'error'  => is_wp_error( $data ) ? $data->get_error_message() : '',
		);
	}

	/**
	 * The colors a card is drawn in: the spec's (palette names resolved to
	 * hex, `admin` kept for the browser), else the default for its series.
	 *
	 * @param array<string, mixed> $spec A validated spec.
	 * @return array{series: array<int, string>, muted: string}
	 */
	public static function colors( array $spec ): array {
		$chosen = array_values( array_filter( array_map( array( ChartSpec::class, 'resolve' ), (array) $spec['style']['colors'] ) ) );
		$many   = '' !== $spec['query']['breakdown'] || count( $spec['query']['metrics'] ) > 1 || 'donut' === $spec['type'];
		if ( ! $chosen ) {
			$chosen = $many ? self::SERIES_COLORS : array( 'admin' );
		}
		return array(
			'series' => $chosen,
			'muted'  => self::MUTED,
		);
	}

	/**
	 * The board for the page and the routes.
	 *
	 * @param int $days The period, in days.
	 * @return array<string, mixed>
	 */
	public static function payload( int $days ): array {
		return array(
			'cards'     => array_map( static fn( array $spec ): array => self::with_data( $spec, $days ), self::cards() ),
			'days'      => $days,
			'isDefault' => self::is_default(),
			'canUndo'   => self::can_undo(),
			'palette'   => Schemes::palette(),
		);
	}

	/**
	 * `rest_api_init`: the routes.
	 */
	public static function register_routes(): void {
		$days = array(
			'type'    => 'integer',
			'minimum' => 1,
			'maximum' => Query::MAX_DAYS,
			'default' => 30,
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/analytics/board',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => static fn( WP_REST_Request $request ): WP_REST_Response => new WP_REST_Response( self::payload( (int) $request['days'] ) ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => array( 'days' => $days ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'rest_change' ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => array(
						'action' => array(
							'type'     => 'string',
							'enum'     => array( 'move', 'remove', 'reset', 'undo' ),
							'required' => true,
						),
						'id'     => array( 'type' => 'string' ),
						'to'     => array( 'type' => array( 'string', 'integer' ) ),
						'days'   => $days,
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/analytics/board/chart',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_chart' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'spec' => array(
						'type'     => 'object',
						'required' => true,
					),
					'days' => $days,
				),
			)
		);
	}

	/**
	 * Whether the current user may see and change this site's board.
	 */
	public static function can_manage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * POST `analytics/board`: the card buttons (move, remove), reset and undo.
	 * Adding and changing charts is the analyst's (phase 2), through apply().
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_change( WP_REST_Request $request ) {
		$result = self::apply(
			(string) $request['action'],
			array(
				'id' => (string) $request['id'],
				'to' => $request['to'] ?? 'down',
			)
		);
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		return new WP_REST_Response( self::payload( (int) $request['days'] ) + array( 'changed' => $result['changed'] ) );
	}

	/**
	 * POST `analytics/board/chart`: one spec's numbers, without saving it.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_chart( WP_REST_Request $request ) {
		$spec = ChartSpec::validate( $request['spec'] );
		if ( is_wp_error( $spec ) ) {
			$spec->add_data( array( 'status' => 400 ) );
			return $spec;
		}
		return new WP_REST_Response( self::with_data( $spec, (int) $request['days'] ) );
	}
}
