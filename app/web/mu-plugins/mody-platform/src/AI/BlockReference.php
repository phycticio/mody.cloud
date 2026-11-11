<?php
/**
 * What the page and edit writers know about blocks, picked per request.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Part 4 of tickets/agent-knows-gutenberg-spectra-settings.md (2026-11-11).
 *
 * Every block family the writers may use has a card in
 * `mody-platform/reference/<family>.json`: its blocks, when to use it, a few
 * rules, a checked example, and words (in the eight site languages) that
 * name it in a request. A writer's system prompt carries:
 *
 * - an index: one line per family, always, so the writer knows what exists;
 * - the cards of the families the request names (its keywords), or whose
 *   blocks are already in the markup being edited.
 *
 * A family used without its card is caught by the validator (unknown
 * attributes, wrong nesting); the retry then carries the cards of every
 * family in the rejected answer (cards_for_markup()). So prompts stay short
 * while every family stays reachable.
 *
 * Adding a family: a card file, its example checked by the validator and in
 * the editor (scripts/block-reference-test.php), and its blocks allowed in
 * BlockValidator.
 */
final class BlockReference {

	/** Loaded cards, by family. */
	private static ?array $cards = null;

	/**
	 * Every card, by family, in a stable order (file name).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function cards(): array {
		if ( null === self::$cards ) {
			self::$cards = array();
			$files       = glob( dirname( __DIR__, 2 ) . '/reference/*.json' ) ?: array();
			sort( $files );
			foreach ( $files as $file ) {
				$card = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
				if ( is_array( $card ) && ! empty( $card['family'] ) && ! empty( $card['blocks'] ) && isset( $card['example'] ) ) {
					self::$cards[ (string) $card['family'] ] = $card;
				}
			}
		}
		return self::$cards;
	}

	/**
	 * Every block the cards cover.
	 *
	 * @return string[]
	 */
	public static function blocks(): array {
		return array_values( array_unique( array_merge( ...array_values( array_map( static fn( array $card ): array => (array) $card['blocks'], self::cards() ) ) ?: array( array() ) ) ) );
	}

	/**
	 * The families a request names, or whose blocks are in the markup.
	 *
	 * @param string $text   The owner's request or instruction.
	 * @param string $markup The markup being edited, if any.
	 * @return string[]
	 */
	public static function select( string $text, string $markup = '' ): array {
		$words  = ' ' . strtolower( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', remove_accents( $text ) ) ) . ' ';
		$picked = array();
		foreach ( self::cards() as $family => $card ) {
			foreach ( (array) ( $card['keywords'] ?? array() ) as $keyword ) {
				if ( str_contains( $words, ' ' . strtolower( remove_accents( (string) $keyword ) ) . ' ' ) ) {
					$picked[] = $family;
					continue 2;
				}
			}
			if ( '' !== $markup && self::uses( $markup, (array) $card['blocks'] ) ) {
				$picked[] = $family;
			}
		}
		return $picked;
	}

	/**
	 * Whether markup uses one of these blocks.
	 *
	 * @param string   $markup Block markup.
	 * @param string[] $blocks Block names.
	 */
	private static function uses( string $markup, array $blocks ): bool {
		foreach ( $blocks as $name ) {
			$short = str_starts_with( $name, 'core/' ) ? '(?:core/)?' . preg_quote( substr( $name, 5 ), '#' ) : preg_quote( $name, '#' );
			if ( preg_match( '#<!--\s+wp:' . $short . '[\s/]#', $markup ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The index: one line per family.
	 */
	public static function index(): string {
		$lines = array();
		foreach ( self::cards() as $card ) {
			$lines[] = '- ' . $card['label'] . ' (' . ( $card['top'] ?? $card['blocks'][0] ) . '): ' . $card['when'] . '.';
		}
		return implode( "\n", $lines );
	}

	/**
	 * The cards of some families.
	 *
	 * @param string[] $families Families.
	 */
	public static function cards_text( array $families ): string {
		$text = array();
		foreach ( array_unique( $families ) as $family ) {
			$card = self::cards()[ $family ] ?? null;
			if ( $card ) {
				$text[] = $card['label'] . ' (' . implode( ', ', (array) $card['blocks'] ) . '): ' . $card['rules'] . " Example:\n" . $card['example'];
			}
		}
		return implode( "\n\n", $text );
	}

	/**
	 * For a rejected answer: the cards of the families it used that the
	 * writer had no card for.
	 *
	 * @param string   $markup The rejected answer.
	 * @param string[] $had    The families whose cards were in the prompt.
	 */
	public static function cards_for_markup( string $markup, array $had ): string {
		$used = array_diff( self::select( '', $markup ), $had );
		return $used ? "\n\nThe reference for the blocks you used:\n" . self::cards_text( $used ) : '';
	}

	/**
	 * Whether the writer needs the site's media library: a picked family is
	 * a media one (`"media": true` on its card: images, galleries, video…),
	 * or the markup being edited has media.
	 *
	 * @param string[] $families The picked families.
	 * @param string   $markup   The markup being edited, if any.
	 */
	public static function needs_media( array $families, string $markup = '' ): bool {
		foreach ( $families as $family ) {
			if ( ! empty( self::cards()[ $family ]['media'] ) ) {
				return true;
			}
		}
		return (bool) preg_match( '#<!--\s+wp:(?:core/)?(?:image|gallery|media-text|cover|video|audio|file)[\s/]#', $markup );
	}

	/**
	 * The writers' block reference: how these blocks are written, the index,
	 * and the cards for this request.
	 *
	 * @param string[] $families The families to give in full.
	 */
	public static function reference( array $families ): string {
		return self::base() . self::for_request( $families );
	}

	/**
	 * The part of the reference that is the same for every request: how
	 * these blocks are written, and the index. Prompts put it before what
	 * changes per request, so it is cached (2026-11-11).
	 */
	public static function base(): string {
		return 'Block families: use them only where the content calls for them; most sections need none. Each is listed with its top block. Write them exactly like the examples, with the same attributes and no others. Spectra blocks save no HTML of their own: a block without inner blocks is one self-closing comment ending in /-->, and the others hold only their inner blocks. Their "text" attributes are plain text, without HTML tags. Like headings and paragraphs, they go inside a section or a column.' . "\n" . self::index();
	}

	/**
	 * The part that changes per request: the cards of its families.
	 *
	 * @param string[] $families The families to give in full.
	 */
	public static function for_request( array $families ): string {
		$cards = self::cards_text( $families );
		return '' !== $cards ? "\n\nHow to write the ones this request needs:\n" . $cards : "\n\nIf you use one, write only attributes you are sure of; the answer is checked.";
	}
}
