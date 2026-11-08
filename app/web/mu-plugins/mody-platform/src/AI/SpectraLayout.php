<?php
/**
 * The layout system of generated content: Spectra containers.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Every part of a generated layout is a `spectra/container` (decided by the
 * maintainer on 2026-11-07), so pages look and respond the same on every site.
 *
 * The recipe has three shapes, each with fixed attributes:
 *
 * - **Section:** a top-level container, full width, the content constrained
 *   to the theme's content width, padding that shrinks on phones.
 * - **Row:** a grid container inside a section, one column per child; 3 or 4
 *   columns become 2 on tablets, and every row becomes 1 column on phones.
 * - **Column:** a plain container inside a row.
 *
 * On WordPress 7.1 the per-device values live in core's `style` attribute,
 * under `@tablet` and `@mobile`, where both core and Spectra read them
 * (Spectra's `ResponsiveControls`). The breakpoints are core's: 480px and 782px.
 *
 * A container saves no HTML of its own (its save is `InnerBlocks.Content`),
 * so its markup cannot be "invalid" in the editor. normalize() sets what
 * Spectra's editor sets on load, so a page looks the same before and after
 * it is opened there.
 */
final class SpectraLayout {

	/** The block. */
	public const BLOCK = 'spectra/container';

	/** A section's attributes. Colors are added to it, nothing else. */
	public const SECTION = '{"variationSelected":true,"htmlTag":"section","align":"full","isBlockRootParent":true,"style":{"spacing":{"padding":{"top":"var:preset|spacing|70","right":"var:preset|spacing|40","bottom":"var:preset|spacing|70","left":"var:preset|spacing|40"}},"@mobile":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|30","bottom":"var:preset|spacing|50","left":"var:preset|spacing|30"}}}},"layout":{"type":"constrained"}}';

	/** A row of three or four columns: two on tablets, one on phones. */
	public const ROW = '{"variationSelected":true,"style":{"spacing":{"blockGap":"var:preset|spacing|40"},"@tablet":{"layout":{"columnCount":2}},"@mobile":{"layout":{"columnCount":1}}},"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":null}}';

	/** A row of two columns: one on phones. */
	public const ROW_OF_TWO = '{"variationSelected":true,"style":{"spacing":{"blockGap":"var:preset|spacing|40"},"@mobile":{"layout":{"columnCount":1}}},"layout":{"type":"grid","columnCount":2,"minimumColumnWidth":null}}';

	/** A column of a row. */
	public const COLUMN = '{"variationSelected":true}';

	/**
	 * The layout rules every writer prompt carries (page, edit, template).
	 */
	public static function rules(): string {
		return 'Layout: every part of the layout is a Spectra container block, written <!-- wp:spectra/container {...} --> ... <!-- /wp:spectra/container -->. Never use group, columns or column blocks for new layout.' . "\n"
			. '- Section: a page is a sequence of sections. Each section is a top-level container with exactly these attributes: ' . self::SECTION . "\n"
			. '  To color a section, add "backgroundColor" and "textColor" with palette slugs, for example "backgroundColor":"primary","textColor":"base". Nothing else changes.' . "\n"
			. '- Row: content side by side is a row, a container inside a section. A row of 3 or 4 columns has exactly ' . self::ROW . ' with "columnCount" set to its number of columns. A row of 2 columns has exactly ' . self::ROW_OF_TWO . "\n"
			. '- Column: each column of a row is a container with exactly ' . self::COLUMN . "\n"
			. '- A container saves no HTML of its own: between its opening and closing comments write only its inner blocks, no <div> or other HTML.' . "\n"
			. '- Headings, paragraphs, lists and buttons go inside a section or a column, never at the top level of the page and never directly inside a row.';
	}

	/**
	 * Sets what Spectra's editor sets on every container when it loads them,
	 * so the content is the same before and after it is opened there:
	 *
	 * - `variationSelected` (without it the editor shows the layout picker
	 *   instead of the content);
	 * - for a root container (no Spectra block above it) `isBlockRootParent`,
	 *   and `align` "full" unless it is "wide"; a nested container has neither.
	 *
	 * A fragment (one block from the editor) does not know its parents, so
	 * only `variationSelected` is set; the editor sets the rest when the block
	 * is placed.
	 *
	 * @param string $markup   Validated block markup.
	 * @param bool   $fragment True for one block taken from a larger content.
	 */
	public static function normalize( string $markup, bool $fragment = false ): string {
		if ( ! str_contains( $markup, 'wp:' . self::BLOCK ) ) {
			return $markup;
		}
		return serialize_blocks( self::walk( parse_blocks( $markup ), $fragment ? null : false, true, null ) );
	}

	/**
	 * Gives every Spectra block without one a `spectraId`, as Spectra's editor
	 * does (`spectra-<time in base 36>-<6 characters>`). Without it, Spectra
	 * makes a new ID on every page view and cannot cache the block's CSS. For
	 * content saved without passing through the editor (PageGenerator::insert_page()).
	 *
	 * @param string $markup Block markup.
	 */
	public static function with_ids( string $markup ): string {
		if ( ! str_contains( $markup, 'wp:spectra/' ) ) {
			return $markup;
		}
		$time = base_convert( (string) (int) floor( microtime( true ) * 1000 ), 10, 36 );
		$id   = static function ( array $block ) use ( $time ): array {
			if ( str_starts_with( (string) $block['blockName'], 'spectra/' ) && empty( $block['attrs']['spectraId'] ) ) {
				$block['attrs']['spectraId'] = 'spectra-' . $time . '-' . strtolower( wp_generate_password( 6, false ) );
			}
			return $block;
		};
		return serialize_blocks( self::walk( parse_blocks( $markup ), null, false, $id ) );
	}

	/**
	 * Walks parsed blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks   Parsed blocks.
	 * @param bool|null                        $inside   Whether a Spectra block is above; null when unknown (a fragment).
	 * @param bool                             $rules    Apply the container rules of normalize().
	 * @param callable|null                    $callback Applied to every block, if given.
	 * @return array<int, array<string, mixed>>
	 */
	private static function walk( array $blocks, ?bool $inside, bool $rules, ?callable $callback ): array {
		foreach ( $blocks as $i => $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}
			$name = (string) $block['blockName'];
			if ( $rules && self::BLOCK === $name ) {
				$attrs                      = (array) $block['attrs'];
				$attrs['variationSelected'] = true;
				if ( true === $inside ) {
					unset( $attrs['isBlockRootParent'], $attrs['align'] );
				} elseif ( false === $inside ) {
					$attrs['isBlockRootParent'] = true;
					if ( ! in_array( $attrs['align'] ?? '', array( 'full', 'wide' ), true ) ) {
						$attrs['align'] = 'full';
					}
				}
				$block['attrs'] = $attrs;
			}
			if ( null !== $callback ) {
				$block = $callback( $block );
			}
			// Spectra's editor counts a container as a root when no block above it is a Spectra block.
			$below                = null === $inside ? null : ( $inside || str_contains( $name, 'spectra' ) );
			$block['innerBlocks'] = self::walk( (array) $block['innerBlocks'], $below, $rules, $callback );
			$blocks[ $i ]         = $block;
		}
		return $blocks;
	}
}
