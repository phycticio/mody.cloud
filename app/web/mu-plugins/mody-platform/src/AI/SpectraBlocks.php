<?php
/**
 * Spectra's content blocks in generated content (ticket M3-11, parts 3 to 7).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\AI;

defined( 'ABSPATH' ) || exit;

/**
 * The Spectra block families the page writer and the edit writer know (M3-11).
 * The validator accepts every Spectra block but the popup builder (part 2);
 * the prompts offer only the families taught here, one group per part:
 *
 * - part 3: `accordion` (questions and answers) and `tabs`.
 *
 * For each family: a short reference and one example for the prompts, with
 * the blocks Spectra's own generators write (its `create-*` abilities) and
 * the attributes as the block editor saves them (its key order, no default
 * values, a blank line between sibling blocks); checks in BlockValidator;
 * and normalize() for what the editor would change on load.
 */
final class SpectraBlocks {

	/** The families the prompts offer, by their top-level block. */
	public const FAMILIES = array( 'spectra/accordion', 'spectra/tabs' );

	/** Questions and answers: one item per question, only the first open. */
	public const ACCORDION = <<<'HTML'
<!-- wp:spectra/accordion -->
<!-- wp:spectra/accordion-child-item {"openByDefault":true} -->
<!-- wp:spectra/accordion-child-header -->
<!-- wp:spectra/accordion-child-header-content {"text":"Do I need an appointment?"} /-->

<!-- wp:spectra/accordion-child-header-icon /-->
<!-- /wp:spectra/accordion-child-header -->

<!-- wp:spectra/accordion-child-details -->
<!-- wp:paragraph -->
<p>No. Bring your bike before 10 am and it is usually ready the same day.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/accordion-child-details -->
<!-- /wp:spectra/accordion-child-item -->

<!-- wp:spectra/accordion-child-item -->
<!-- wp:spectra/accordion-child-header -->
<!-- wp:spectra/accordion-child-header-content {"text":"Do you repair e-bikes?"} /-->

<!-- wp:spectra/accordion-child-header-icon /-->
<!-- /wp:spectra/accordion-child-header -->

<!-- wp:spectra/accordion-child-details -->
<!-- wp:paragraph -->
<p>Yes, including batteries and motors.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/accordion-child-details -->
<!-- /wp:spectra/accordion-child-item -->
<!-- /wp:spectra/accordion -->
HTML;

	/** Tabs: the labels first, then one panel per tab in the same order. */
	public const TABS = <<<'HTML'
<!-- wp:spectra/tabs {"variationSelected":true,"currentTab":0} -->
<!-- wp:spectra/tabs-child-tab-wrapper -->
<!-- wp:spectra/tabs-child-tab-button {"currentTab":0,"text":"City bikes"} /-->

<!-- wp:spectra/tabs-child-tab-button {"currentTab":1,"text":"E-bikes"} /-->
<!-- /wp:spectra/tabs-child-tab-wrapper -->

<!-- wp:spectra/tabs-child-tabpanel {"currentTab":0} -->
<!-- wp:paragraph -->
<p>A full service: brakes, gears, chain and tyres checked and adjusted.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/tabs-child-tabpanel -->

<!-- wp:spectra/tabs-child-tabpanel {"currentTab":1} -->
<!-- wp:paragraph -->
<p>Everything in the city bike service, plus a battery and motor check.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/tabs-child-tabpanel -->
<!-- /wp:spectra/tabs -->
HTML;

	/**
	 * The reference every writer prompt carries (page, edit, template), after
	 * the layout rules.
	 */
	public static function reference(): string {
		return 'Spectra content blocks: use them only where the content calls for them; most sections need none. Write them exactly like the examples, with the same attributes and no others. They save no HTML of their own: a block without inner blocks is one self-closing comment ending in /-->, and the others hold only their inner blocks. Their "text" attributes are plain text, without HTML tags. Like headings and paragraphs, they go inside a section or a column.' . "\n"
			. '- Accordion, for questions and answers (an FAQ): one accordion-child-item per question. The question is the "text" of accordion-child-header-content; the answer is paragraphs or a list inside accordion-child-details. Only the first item has "openByDefault":true. Example:' . "\n"
			. self::ACCORDION . "\n"
			. '- Tabs, for a few parallel groups of content the reader picks one of (a menu by meal, prices by service): tabs-child-tab-wrapper holds one tabs-child-tab-button per tab, its label as "text"; then one tabs-child-tabpanel per tab, in the same order, holding that tab\'s blocks. "currentTab" numbers the tabs from 0, on each button and on its panel. Example:' . "\n"
			. self::TABS;
	}

	/**
	 * Sets what the block editor would change on these blocks when it opens
	 * them, after validation, so the content is the same before and after:
	 *
	 * - no attribute with its default value (the editor saves none, so
	 *   `"activeAccordion":0` would go);
	 * - `variationSelected` on tabs (without it the editor shows the layout
	 *   picker instead of the tabs);
	 * - `currentTab` by position on each tab button and panel: Spectra pairs
	 *   them by that number, and a missing one counts as 0. The tabs' own
	 *   `currentTab` (the tab open at first) is 0 unless it names a tab.
	 *   These go first, where the editor writes them.
	 *
	 * @param string $markup Validated block markup.
	 */
	public static function normalize( string $markup ): string {
		if ( ! preg_match( '#wp:spectra/(?:accordion|tabs)\b#', $markup ) ) {
			return $markup;
		}
		return serialize_blocks( self::walk( parse_blocks( $markup ) ) );
	}

	/**
	 * Walks parsed blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private static function walk( array $blocks ): array {
		foreach ( $blocks as $i => $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}
			if ( preg_match( '#^spectra/(?:accordion|tabs)#', (string) $block['blockName'] ) ) {
				$block['attrs'] = self::without_defaults( (string) $block['blockName'], (array) $block['attrs'] );
			}
			if ( 'spectra/tabs' === $block['blockName'] ) {
				$panel = 0;
				foreach ( $block['innerBlocks'] as $j => $inner ) {
					if ( 'spectra/tabs-child-tab-wrapper' === $inner['blockName'] ) {
						$tab = 0;
						foreach ( $inner['innerBlocks'] as $k => $button ) {
							$inner['innerBlocks'][ $k ]['attrs'] = array( 'currentTab' => $tab++ ) + (array) $button['attrs'];
						}
					} elseif ( 'spectra/tabs-child-tabpanel' === $inner['blockName'] ) {
						$inner['attrs'] = array( 'currentTab' => $panel++ ) + (array) $inner['attrs'];
					}
					$block['innerBlocks'][ $j ] = $inner;
				}
				$open           = $block['attrs']['currentTab'] ?? 0;
				$block['attrs'] = array(
					'variationSelected' => true,
					'currentTab'        => is_int( $open ) && $open >= 0 && $open < $panel ? $open : 0,
				) + $block['attrs'];
			}
			$block['innerBlocks'] = self::walk( (array) $block['innerBlocks'] );
			$blocks[ $i ]         = $block;
		}
		return $blocks;
	}

	/**
	 * A block's attributes without those set to their default value.
	 *
	 * @param string               $name  Block name.
	 * @param array<string, mixed> $attrs Attributes.
	 * @return array<string, mixed>
	 */
	private static function without_defaults( string $name, array $attrs ): array {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		if ( ! $type ) {
			return $attrs;
		}
		foreach ( $attrs as $key => $value ) {
			$schema = $type->attributes[ $key ] ?? array();
			if ( array_key_exists( 'default', $schema ) && wp_json_encode( $schema['default'] ) === wp_json_encode( $value ) ) {
				unset( $attrs[ $key ] );
			}
		}
		return $attrs;
	}
}
