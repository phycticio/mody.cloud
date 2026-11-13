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
 * `mody_analytics_board_undo`, so one Undo goes back. One Undo is one
 * change of the owner's, or one whole reply of the analyst's (batch()).
 * Every change of the cards raises `mody_analytics_board_version`, so the
 * bubble can tell that its Undo no longer matches the board.
 *
 * A stored card that no longer passes validation (after a change to the
 * chart format) is not drawn, but it is kept in the option: a later save
 * never deletes it, only Reset does.
 *
 * Every change goes through apply(): the card buttons' route here, and the
 * analyst (phase 2) through the same method. Numbers are counted when a
 * chart is shown, for the current site only (Query::run() gets the site
 * from here, never from the spec).
 *
 * REST, `manage_options` on the site (cookie and nonce):
 * - GET  mody-platform/v1/analytics/board?days=30: the cards with their numbers;
 * - POST mody-platform/v1/analytics/board: {action: move|remove|reset|undo, id, to, version};
 * - POST mody-platform/v1/analytics/board/chart: {spec, days}: one chart's numbers.
 */
final class Board {

	/** The site option with the cards. */
	public const OPTION = 'mody_analytics_board';

	/** The site option with the board before the last change. */
	public const UNDO_OPTION = 'mody_analytics_board_undo';

	/** The site option counting changes to the cards. */
	public const VERSION_OPTION = 'mody_analytics_board_version';

	/** The action fired after the owner changes the board with its buttons: the change in words (English, and the owner's language). */
	public const OWNER_CHANGED = 'mody_analytics_board_owner_changed';

	/** The site option with the notes on dates: [{date, label}] (phase 3c). */
	public const NOTES_OPTION = 'mody_analytics_notes';

	/** The most notes. */
	public const MAX_NOTES = 50;

	/** The longest note. */
	public const MAX_NOTE = 60;

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

	/** Whether changes are grouped into one Undo (batch()). */
	private static bool $batching = false;

	/** Whether the board before the batch is kept already. */
	private static bool $remembered = false;

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
			'size'  => 'half',
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
			'size'  => 'half',
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
		return self::split()[0];
	}

	/**
	 * The stored cards: the valid ones, validated, and the ones that no
	 * longer pass, as stored (kept, never drawn).
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, mixed>}
	 */
	private static function split(): array {
		$stored = get_option( self::OPTION );
		$cards  = is_array( $stored ) && $stored ? $stored : self::defaults();
		$valid  = array();
		$kept   = array();
		foreach ( $cards as $card ) {
			$spec = ChartSpec::validate( $card );
			if ( is_wp_error( $spec ) ) {
				$kept[] = $card;
			} else {
				$valid[] = $spec;
			}
		}
		return array( $valid, $kept );
	}

	/**
	 * The board's version: it goes up with every change to the cards.
	 */
	public static function version(): int {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * Runs changes as one: Undo then goes back to the board before them
	 * all. The analyst's reply is one batch.
	 *
	 * @template T
	 * @param callable(): T $changes The changes.
	 * @return T What they return.
	 */
	public static function batch( callable $changes ) {
		self::$batching   = true;
		self::$remembered = false;
		try {
			return $changes();
		} finally {
			self::$batching   = false;
			self::$remembered = false;
		}
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
	 * @param string               $action add, change, remove, move, reset, undo; note (date, label) or unnote (date).
	 * @param array<string, mixed> $args   id (change, remove, move), spec (add: a whole spec; change: the fields to change), to (move: up, down, or a position from 0), after (add: an id, or '' for the top; the end by default).
	 * @return array{cards: array<int, array<string, mixed>>, changed: array<int, string>}|WP_Error The new board and the ids it changed, or why not.
	 */
	public static function apply( string $action, array $args = array() ) {
		if ( 'note' === $action || 'unnote' === $action ) {
			return self::note( $action, $args );
		}
		list( $cards, $kept ) = self::split();
		$ids                  = array_column( $cards, 'id' );
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
				$spec['id'] = self::unique_id( $spec['id'], array_merge( $ids, array_filter( array_map( static fn( $card ): string => is_array( $card ) ? (string) ( $card['id'] ?? '' ) : '', $kept ) ) ) );
				$after      = array_key_exists( 'after', $args ) ? sanitize_key( (string) $args['after'] ) : null;
				$found      = null !== $after && '' !== $after ? array_search( $after, $ids, true ) : false;
				// The end by default, and after an id that is not on the board; the top for ''.
				$at = '' === $after ? 0 : ( false === $found ? count( $cards ) : (int) $found + 1 );
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
				// A new period replaces the old one: days drop the dates, dates drop the days.
				if ( isset( $fields['query'] ) && is_array( $fields['query'] ) ) {
					if ( array_key_exists( 'days', $fields['query'] ) && ! array_key_exists( 'from', $fields['query'] ) ) {
						unset( $merged['query']['from'], $merged['query']['to'] );
					} elseif ( array_key_exists( 'from', $fields['query'] ) ) {
						unset( $merged['query']['days'] );
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
				self::bump();
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
				self::$remembered = false; // A change after it, in the same batch, is undone on its own.
				self::bump();
				return array(
					'cards'   => self::cards(),
					'changed' => array(),
				);
			default:
				return new WP_Error( 'mody_board_action', 'Unknown action. Actions: add, change, remove, move, reset, undo, note, unnote.' );
		}
		self::remember();
		update_option( self::OPTION, array_merge( array_values( $cards ), $kept ), false );
		self::bump();
		return array(
			'cards'   => array_values( $cards ),
			'changed' => $changed,
		);
	}

	/**
	 * The site's notes on dates, oldest first.
	 *
	 * @return array<int, array{date: string, label: string}>
	 */
	public static function notes(): array {
		$notes = get_option( self::NOTES_OPTION, array() );
		$out   = array();
		foreach ( is_array( $notes ) ? $notes : array() as $note ) {
			if ( is_array( $note ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $note['date'] ?? '' ) ) && '' !== (string) ( $note['label'] ?? '' ) ) {
				$out[] = array(
					'date'  => (string) $note['date'],
					'label' => (string) $note['label'],
				);
			}
		}
		usort( $out, static fn( array $a, array $b ): int => strcmp( $a['date'], $b['date'] ) );
		return $out;
	}

	/**
	 * Adds a note on a date (one per date: a new one replaces it), or
	 * removes the note of a date. Notes are not part of Undo.
	 *
	 * @param string               $action note or unnote.
	 * @param array<string, mixed> $args   date (Y-m-d), and label for note.
	 * @return array{cards: array<int, array<string, mixed>>, changed: array<int, string>, notes: array<int, array{date: string, label: string}>}|WP_Error
	 */
	private static function note( string $action, array $args ) {
		$date = (string) ( $args['date'] ?? '' );
		$day  = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) );
		if ( ! $day || $day->format( 'Y-m-d' ) !== $date ) {
			return new WP_Error( 'mody_board_note', 'A note needs "date" as YYYY-MM-DD.' );
		}
		$notes = array_values( array_filter( self::notes(), static fn( array $note ): bool => $note['date'] !== $date ) );
		if ( 'note' === $action ) {
			$label = trim( sanitize_text_field( (string) ( $args['label'] ?? '' ) ) );
			if ( '' === $label ) {
				return new WP_Error( 'mody_board_note', 'A note needs "label": a few words, such as "Registration opened".' );
			}
			if ( count( $notes ) >= self::MAX_NOTES ) {
				return new WP_Error( 'mody_board_note', sprintf( 'A site keeps at most %d notes. Remove one first.', self::MAX_NOTES ) );
			}
			$notes[] = array(
				'date'  => $date,
				'label' => mb_substr( $label, 0, self::MAX_NOTE ),
			);
		} elseif ( count( $notes ) === count( self::notes() ) ) {
			return new WP_Error( 'mody_board_note', sprintf( 'There is no note on %s.', $date ) );
		}
		update_option( self::NOTES_OPTION, $notes, false );
		return array(
			'cards'   => self::cards(),
			'changed' => array(),
			'notes'   => self::notes(),
		);
	}

	/**
	 * Keeps the board as it is now for Undo: the stored cards, or an empty
	 * list for the default board.
	 */
	private static function remember(): void {
		if ( self::$batching && self::$remembered ) {
			return; // The board before the batch is the one to go back to.
		}
		$stored = get_option( self::OPTION );
		update_option( self::UNDO_OPTION, is_array( $stored ) ? $stored : array(), false );
		self::$remembered = self::$batching;
	}

	/**
	 * Raises the board's version.
	 */
	private static function bump(): void {
		update_option( self::VERSION_OPTION, self::version() + 1, false );
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
		$data  = Query::run( $spec['query'], array( get_current_blog_id() ), $days );
		$query = $spec['query'];
		// A card with its own period says so: its dates ("March 2026"), or its days when they differ from the board's.
		$period = '';
		if ( ! empty( $query['from'] ) ) {
			$period = Query::range_label( (string) $query['from'], (string) $query['to'] );
		} elseif ( null !== $query['days'] && (int) $query['days'] !== $days ) {
			/* translators: %d: a number of days. */
			$period = sprintf( __( 'Last %d days', 'mody_cloud' ), (int) $query['days'] );
		}
		return array(
			'spec'   => $spec,
			'colors' => self::colors( $spec ),
			'days'   => $query['days'] ?? $days,
			'period' => $period,
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
			'version'   => self::version(),
			'canUndo'   => self::can_undo(),
			'notes'     => self::notes(),
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
						'id'      => array( 'type' => 'string' ),
						'to'      => array( 'type' => array( 'string', 'integer' ) ),
						'version' => array( 'type' => 'integer' ),
						'days'    => $days,
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
	 * POST `analytics/board`: the card buttons (move, remove), dragging (move
	 * to a position), reset and undo. Adding and changing charts is the
	 * analyst's (phase 2), through apply(). With `version`, an undo is
	 * refused when the board changed since (the bubble's Undo of one reply).
	 * The change, in words, goes to OWNER_CHANGED (the analyst's
	 * conversation: in English for the model, in the owner's language for
	 * the bubble) and back as `event`.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_change( WP_REST_Request $request ) {
		$action = (string) $request['action'];
		if ( 'undo' === $action && null !== $request['version'] && (int) $request['version'] !== self::version() ) {
			return new WP_Error( 'mody_board_changed', __( 'The board changed after this reply, so this Undo would undo something else. Use “Undo the last change” on the board.', 'mody_cloud' ), array( 'status' => 409 ) );
		}
		$before = self::cards();
		$result = self::apply(
			$action,
			array(
				'id' => (string) $request['id'],
				'to' => $request['to'] ?? 'down',
			)
		);
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		$event = self::describe( $action, (string) $request['id'], $before, $result['cards'] );
		$shown = self::describe( $action, (string) $request['id'], $before, $result['cards'], true );
		if ( '' !== $event ) {
			do_action( self::OWNER_CHANGED, $event, $shown );
		}
		return new WP_REST_Response(
			self::payload( (int) $request['days'] ) + array(
				'changed' => $result['changed'],
				'event'   => $shown,
			)
		);
	}

	/**
	 * A change made with the buttons, in words: in English for the analyst
	 * (like its prompt), or in the owner's language for the bubble.
	 *
	 * @param string                           $action     The action.
	 * @param string                           $id         The chart's id.
	 * @param array<int, array<string, mixed>> $before     The cards before.
	 * @param array<int, array<string, mixed>> $after      The cards after.
	 * @param bool                             $translated The owner's language.
	 */
	public static function describe( string $action, string $id, array $before, array $after, bool $translated = false ): string {
		$id    = sanitize_key( $id );
		$card  = current( array_filter( $before, static fn( array $spec ): bool => $spec['id'] === $id ) );
		$title = $card ? (string) $card['title'] : $id;
		switch ( $action ) {
			case 'remove':
				/* translators: %s: a chart's title. */
				return $translated ? sprintf( __( 'You removed “%s”.', 'mody_cloud' ), $title ) : sprintf( 'The owner removed the chart “%s” (%s).', $title, $id );
			case 'move':
				$at = array_search( $id, array_column( $after, 'id' ), true );
				if ( false === $at ) {
					return '';
				}
				/* translators: 1: a chart's title, 2: its position, 3: the number of charts. */
				return $translated ? sprintf( __( 'You moved “%1$s” to position %2$d of %3$d.', 'mody_cloud' ), $title, $at + 1, count( $after ) ) : sprintf( 'The owner moved the chart “%s” (%s) to position %d of %d.', $title, $id, $at + 1, count( $after ) );
			case 'reset':
				return $translated ? __( 'You went back to the default board.', 'mody_cloud' ) : 'The owner went back to the default board.';
			case 'undo':
				return $translated ? __( 'You undid the last change to the board.', 'mody_cloud' ) : 'The owner undid the last change to the board.';
		}
		return '';
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
