<?php
/**
 * A page as the agent and the edit writer read it, and its sections.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Decided by the maintainer on 2026-11-11: Mody sees the whole open page,
 * whatever its size, and changes a long page section by section.
 *
 * - compact(): the page as text. Its sections are numbered. Each block is
 *   one line with its text, and media is a reference (`[image 12: "Shop
 *   front"]`). Layout settings are left out: they are three quarters of a
 *   page's bytes and say nothing about its content (the main site's 56 KB
 *   landing page is about 9 KB this way).
 * - sections(): the page's top-level blocks (its sections), each with its
 *   markup and a label. splice() puts changed sections back.
 */
final class PageOutline {

	/** Answer of the edit writer that removes the section it was given. */
	public const REMOVE = '<!-- mody:remove -->';

	/**
	 * A safety cap on the compact view, in characters. No real page is near
	 * it (the 56 KB landing page gives about 9,000).
	 */
	public const MAX_COMPACT = 60000;

	/** Attributes that hold a block's own words (Spectra keeps text in attributes). */
	private const TEXT_ATTRIBUTES = array( 'text', 'title', 'label', 'heading', 'subheading', 'description', 'content', 'question', 'answer', 'prefix', 'suffix', 'startNumber', 'endNumber', 'buttonText' );

	/**
	 * The page's sections: its top-level blocks, in order.
	 *
	 * @param string $markup Block markup.
	 * @return array<int, array{markup: string, label: string, block: bool}> From 0.
	 */
	public static function sections( string $markup ): array {
		$sections = array();
		foreach ( parse_blocks( $markup ) as $block ) {
			if ( null === $block['blockName'] && '' === trim( (string) $block['innerHTML'] ) ) {
				continue; // The blank lines between blocks.
			}
			$sections[] = array(
				'markup' => serialize_block( $block ),
				'label'  => self::label( $block ),
				'block'  => null !== $block['blockName'], // Classic HTML outside blocks is kept as it is.
			);
		}
		return $sections;
	}

	/**
	 * The page again, with some sections replaced.
	 *
	 * @param array<int, array{markup: string, label: string}> $sections The sections (sections()).
	 * @param array<int, string>                               $changed  Index => new markup;
	 *                                                                   REMOVE drops the section.
	 */
	public static function splice( array $sections, array $changed ): string {
		$parts = array();
		foreach ( $sections as $index => $section ) {
			$markup = $changed[ $index ] ?? $section['markup'];
			if ( self::REMOVE !== $markup && '' !== trim( $markup ) ) {
				$parts[] = trim( $markup );
			}
		}
		return implode( "\n\n", $parts );
	}

	/**
	 * The compact view of a page: numbered sections, one line per block.
	 *
	 * @param string $markup Block markup.
	 */
	public static function compact( string $markup ): string {
		$lines = array();
		$index = 0;
		foreach ( parse_blocks( $markup ) as $block ) {
			if ( null === $block['blockName'] && '' === trim( (string) $block['innerHTML'] ) ) {
				continue;
			}
			++$index;
			$lines[] = sprintf( 'Section %d (%s):', $index, self::short( $block ) );
			self::lines( $block, $lines, 1 );
		}
		$text = implode( "\n", $lines );
		return mb_strlen( $text ) > self::MAX_COMPACT ? mb_substr( $text, 0, self::MAX_COMPACT ) . "\n… (the rest of the page is left out)" : $text;
	}

	/**
	 * A section's label for the owner: its first heading, else its first words.
	 *
	 * @param array<string, mixed> $block A parsed block.
	 */
	public static function label( array $block ): string {
		$lines = array();
		self::lines( $block, $lines, 0 );
		$first = '';
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s*heading \d: (.+)$/', $line, $m ) ) {
				return rtrim( mb_strimwidth( $m[1], 0, 60, '…' ), '.:;!? ' ); // The progress line adds its own "…".
			}
			if ( '' === $first && preg_match( '/^\s*[a-z0-9-]+(?: \d)?: (.+)$/', $line, $m ) ) {
				$first = $m[1];
			}
		}
		return rtrim( mb_strimwidth( $first, 0, 60, '…' ), '.:;!? ' );
	}

	/**
	 * Adds a block's lines: its inner blocks one level deeper, or its own
	 * line when it has words or is media.
	 *
	 * @param array<string, mixed> $block  A parsed block.
	 * @param string[]             $lines  The lines so far.
	 * @param int                  $depth  Indentation.
	 */
	private static function lines( array $block, array &$lines, int $depth ): void {
		$pad   = str_repeat( '  ', $depth );
		$name  = self::short( $block );
		$attrs = (array) ( $block['attrs'] ?? array() );

		$before = count( $lines );
		if ( self::is_media( (string) $block['blockName'] ) ) {
			$alt     = self::plain( preg_match( '/\balt="([^"]*)"/', (string) $block['innerHTML'], $m ) ? html_entity_decode( $m[1], ENT_QUOTES ) : (string) ( $attrs['alt'] ?? '' ) );
			$id      = isset( $attrs['id'] ) ? ' ' . (int) $attrs['id'] : ( isset( $attrs['ids'] ) ? ' ' . implode( ',', array_map( 'intval', (array) $attrs['ids'] ) ) : '' );
			$lines[] = $pad . '[' . $name . $id . ( '' !== $alt ? ': "' . $alt . '"' : '' ) . ']';
		} else {
			$words = self::words( $block );
			if ( '' !== $words ) {
				$level   = 'heading' === $name ? ' ' . (int) ( $attrs['level'] ?? 2 ) : '';
				$lines[] = $pad . $name . $level . ': ' . $words;
			}
		}
		// Layout blocks with no words of their own (containers) add no indentation.
		$deeper = count( $lines ) > $before ? $depth + 1 : $depth;
		foreach ( (array) $block['innerBlocks'] as $inner ) {
			self::lines( $inner, $lines, $deeper );
		}
	}

	/**
	 * A block's own words: its HTML outside its inner blocks, as text, and
	 * the attributes that hold text.
	 *
	 * @param array<string, mixed> $block A parsed block.
	 */
	private static function words( array $block ): string {
		$own = '';
		foreach ( (array) ( $block['innerContent'] ?? array() ) as $piece ) {
			$own .= is_string( $piece ) ? $piece . ' ' : ' ';
		}
		$parts = array( $own );
		foreach ( self::TEXT_ATTRIBUTES as $key ) {
			$value = $block['attrs'][ $key ] ?? null;
			if ( is_scalar( $value ) && ! is_bool( $value ) && '' !== trim( (string) $value ) ) {
				$parts[] = (string) $value;
			}
		}
		$text = html_entity_decode( wp_strip_all_tags( implode( ' ', $parts ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return self::plain( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Owner text as it goes into a prompt: angle brackets that decoding brought
	 * back (`&lt;/page&gt;` typed on the page) can never close a prompt's data tags.
	 *
	 * @param string $text Text.
	 */
	private static function plain( string $text ): string {
		return trim( str_replace( array( '<', '>' ), array( '‹', '›' ), $text ) );
	}

	/**
	 * Whether a block is media (shown as a reference, never its file).
	 *
	 * @param string $name Block name.
	 */
	private static function is_media( string $name ): bool {
		return (bool) preg_match( '#(^|/)(image|gallery|cover|video|audio|file|media-text)$#', $name );
	}

	/**
	 * A block's name without the core/ and spectra/ prefixes.
	 *
	 * @param array<string, mixed> $block A parsed block.
	 */
	private static function short( array $block ): string {
		return null === $block['blockName'] ? 'html' : str_replace( array( 'core/', 'spectra/' ), '', (string) $block['blockName'] );
	}
}
