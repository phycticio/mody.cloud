<?php
/**
 * Server-side validation of generated block markup.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\AI;

use WP_Block_Type_Registry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Checks model output before anything is saved (documentation/ai-engine.md,
 * "Validation pipeline"):
 *
 * 1. WordPress's parser accepts it, and serialize_blocks() reproduces it exactly.
 *    There is no text or HTML outside blocks.
 * 2. Every block is on the whitelist AND registered on this site.
 * 3. Colors are palette slugs only. No hex, rgb() or named colors.
 * 4. Media (src, srcset, url) stay on this network. Nothing loads from a third party (rule 1).
 * 5. Layout is Spectra containers (SpectraLayout, 2026-11-07): a new page
 *    uses no group, columns or column block and no container attribute
 *    outside the recipe.
 * 6. Every Spectra block but the popup builder (M3-11, 2026-11-08). Spectra
 *    blocks are rendered on the server from their attributes, so they save
 *    no HTML of their own. Blocks sit where their block.json allows
 *    (`parent`, `ancestor`, `allowedBlocks`). Spectra's color attributes
 *    take palette slugs, its media come from this network, its links are
 *    plain links, icons are names, and no `htmlAttributes`. Tabs have one
 *    label per panel, and an accordion item a question and an answer (part 3).
 *
 * What PHP cannot check: whether each block's HTML matches what its save
 * function in the editor would produce. A mismatch shows as "unexpected or
 * invalid content" in the editor. The few-shot example keeps that rare.
 */
final class BlockValidator {

	/**
	 * Blocks the model may use. documentation/ai-engine.md, "Initial block whitelist",
	 * plus the child blocks that its parents need: button, list-item, social-link,
	 * and Spectra's container, the layout block (2026-11-07). group, columns and
	 * column stay for existing content and templates; new pages may not use them.
	 */
	public const WHITELIST = array(
		'core/paragraph',
		'core/heading',
		'core/buttons',
		'core/button',
		'core/columns',
		'core/column',
		'core/group',
		'core/cover',
		'core/image',
		'core/list',
		'core/list-item',
		'core/quote',
		'core/spacer',
		'core/separator',
		'core/social-links',
		'core/social-link',
		'core/navigation',
		'core/site-logo',
		'core/site-title',
		'core/site-tagline',
		'core/query',
		'core/post-template',
		...self::SPECTRA_BLOCKS,
		// 2026-11-11, part 8: text formats and lists of the site's content.
		'core/table',
		'core/pullquote',
		'core/code',
		'core/preformatted',
		'core/verse',
		'core/latest-posts',
		'core/categories',
		'core/archives',
		// Part 9: media from the site's library (SystemPrompt::media_context()).
		'core/gallery',
		'core/media-text',
		'core/video',
		'core/audio',
		'core/file',
	);

	/**
	 * Spectra 1.0.13's blocks the agent may use (M3-11, decided 2026-11-08):
	 * all of them but SPECTRA_EXCLUDED. A Spectra update that adds a block
	 * fails scripts/spectra-blocks-test.php until it is reviewed here.
	 */
	public const SPECTRA_BLOCKS = array(
		'spectra/accordion',
		'spectra/accordion-child-details',
		'spectra/accordion-child-header',
		'spectra/accordion-child-header-content',
		'spectra/accordion-child-header-icon',
		'spectra/accordion-child-item',
		'spectra/button',
		'spectra/buttons',
		'spectra/container',
		'spectra/content',
		'spectra/countdown',
		'spectra/countdown-child-day',
		'spectra/countdown-child-hour',
		'spectra/countdown-child-label',
		'spectra/countdown-child-minute',
		'spectra/countdown-child-number',
		'spectra/countdown-child-second',
		'spectra/countdown-child-separator',
		'spectra/counter',
		'spectra/counter-child-number',
		'spectra/counter-child-progress-bar',
		'spectra/counter-child-wrapper',
		'spectra/icon',
		'spectra/icons',
		'spectra/list',
		'spectra/list-child-icon',
		'spectra/list-child-item',
		'spectra/modal',
		'spectra/modal-child-button',
		'spectra/modal-child-content',
		'spectra/modal-child-icon',
		'spectra/modal-child-popup-close-icon',
		'spectra/modal-child-trigger',
		'spectra/modal-popup',
		'spectra/modal-popup-content',
		'spectra/post',
		'spectra/post-no-results',
		'spectra/post-template',
		'spectra/separator',
		'spectra/slider',
		'spectra/slider-child',
		'spectra/tabs',
		'spectra/tabs-child-tab-button',
		'spectra/tabs-child-tab-trigger',
		'spectra/tabs-child-tab-wrapper',
		'spectra/tabs-child-tabpanel',
	);

	/**
	 * Spectra blocks never offered: the popup builder (site-wide popups, not
	 * page content) and the Google Map (rule 1; SpectraGuard unregisters it).
	 */
	public const SPECTRA_EXCLUDED = array( 'spectra/popup-builder', 'spectra/google-map' );

	/** Attributes that name a link target. Plain links are allowed; scripts and data are not. */
	private const LINK_KEYS = array( 'linkURL', 'linkUrl', 'href' );

	/** Attributes that choose a Spectra block's HTML element. */
	private const TAG_KEYS = array( 'tagName', 'htmlTag', 'headerElement' );

	/** The elements a Spectra block may render as. */
	private const SAFE_TAGS = array( 'div', 'section', 'header', 'footer', 'main', 'article', 'aside', 'nav', 'p', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'button', 'a', 'figure', 'blockquote' );

	/** Layout blocks a new page may not use: its layout is Spectra containers (SpectraLayout). */
	public const REPLACED_BY_CONTAINER = array( 'core/group', 'core/columns', 'core/column' );

	/**
	 * Core blocks new pages build with Spectra's instead (decided by the
	 * maintainer on 2026-11-11: one way to build each thing, and Spectra's are
	 * rendered on the server, so the editor never marks them invalid). Blocks
	 * already on a page stay as they are.
	 */
	public const REPLACED_BY_SPECTRA = array(
		'core/buttons'   => 'spectra/buttons',
		'core/button'    => 'spectra/button',
		'core/list'      => 'spectra/list',
		'core/list-item' => 'spectra/list-child-item',
		'core/separator' => 'spectra/separator',
	);

	/**
	 * Core blocks that show one post of a list: allowed in templates, and
	 * anywhere inside a spectra/post-template (2026-11-11, the post family).
	 */
	public const POST_ITEM_BLOCKS = array( 'core/post-featured-image', 'core/post-title', 'core/post-date', 'core/post-excerpt', 'core/read-more' );

	/**
	 * Values Spectra's blocks accept that their block.json does not say
	 * (2026-11-11, checked against their renderers): another value passes the
	 * schema but renders nothing, or the wrong thing.
	 */
	private const SPECTRA_ENUMS = array(
		'spectra/separator' => array(
			'separatorStyle' => array( 'solid', 'dashed', 'dotted', 'double', 'rectangles', 'parallelogram', 'slash', 'leaves' ),
			'separatorAlign' => array( 'left', 'center', 'right' ),
		),
		'spectra/counter'   => array(
			'counterStyle' => array( 'simple', 'circular', 'bar' ),
		),
		'spectra/list'      => array(
			'listType'  => array( 'unordered', 'ordered' ),
			'listStyle' => array( 'decimal', 'upper-alpha', 'lower-alpha', 'upper-roman', 'lower-roman', 'decimal-leading-zero' ),
		),
		'spectra/modal'     => array(
			'modalTrigger' => array( 'button', 'icon', 'text' ),
		),
	);

	/** Spectra's icon names and aliases, loaded once when needed. */
	private static ?array $icons = null;

	/**
	 * The container attributes a new page may use (SpectraLayout's recipe), and
	 * where: top-level keys, keys of `style` and of its `@tablet` and `@mobile`
	 * states, and keys of `layout`.
	 */
	private const CONTAINER_KEYS = array(
		'attrs'  => array( 'variationSelected', 'htmlTag', 'align', 'isBlockRootParent', 'backgroundColor', 'textColor', 'style', 'layout', 'anchor', 'spectraId', 'metadata' ),
		'style'  => array( 'spacing', 'layout', '@tablet', '@mobile' ),
		'state'  => array( 'spacing', 'layout' ),
		'layout' => array( 'type', 'columnCount', 'minimumColumnWidth', 'orientation', 'justifyContent', 'verticalAlignment', 'flexWrap', 'columnSpan', 'rowSpan', 'selfStretch', 'flexSize' ),
	);

	/** HTML elements a container may render as. */
	private const CONTAINER_TAGS = array( 'div', 'section', 'header', 'footer', 'main', 'article', 'aside' );

	/**
	 * Also allowed in templates and template parts (M3-07): the theme blocks
	 * that `templates/` and `parts/` use, which pages never need.
	 */
	public const TEMPLATE_BLOCKS = array(
		'core/template-part',
		'core/post-content',
		'core/post-title',
		'core/post-date',
		'core/post-excerpt',
		'core/post-featured-image',
		'core/post-terms',
		'core/query-pagination',
		'core/query-pagination-previous',
		'core/query-pagination-numbers',
		'core/query-pagination-next',
		'core/query-no-results',
		'core/query-title',
		'core/term-description',
		'core/search',
		'core/navigation-link',
		'core/navigation-submenu',
		'core/page-list',
		// The mody-languages switcher, placed after every navigation block (tickets/site-languages.md).
		'mody/language-switcher',
		'core/read-more', // 2026-11-11, the post family.
	);

	/** Blocks a template must keep when it has them: without them, its pages show nothing. */
	public const TEMPLATE_ESSENTIAL = array( 'core/post-content', 'core/query' );

	/** Used when the theme's palette cannot be read. Matches theme.json. */
	public const DEFAULT_PALETTE = array( 'primary', 'secondary', 'accent', 'base', 'contrast' );

	/** Words that appear in core's has-*-color classes but are not palette slugs. */
	private const GENERIC_COLOR_CLASSES = array( 'text', 'link', 'border', 'icon', 'background' );

	/** HTML elements that block markup may contain. Anything else is a typo or an invented tag. */
	private const HTML_TAGS = array(
		'A', 'ABBR', 'ARTICLE', 'ASIDE', 'B', 'BLOCKQUOTE', 'BR', 'CITE', 'CODE', 'DEL', 'DIV', 'EM', 'FIGCAPTION',
		'FIGURE', 'FOOTER', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'HEADER', 'HR', 'I', 'IMG', 'INS', 'KBD', 'LI', 'MAIN',
		'MARK', 'NAV', 'OL', 'P', 'Q', 'S', 'SECTION', 'SMALL', 'SPAN', 'STRONG', 'SUB', 'SUP', 'TIME', 'U', 'UL',
		// Tables and preformatted text (2026-11-11, part 8 of tickets/agent-knows-gutenberg-spectra-settings.md).
		'TABLE', 'THEAD', 'TBODY', 'TFOOT', 'TR', 'TH', 'TD', 'CAPTION', 'PRE',
		// Video and audio (part 9). No SOURCE, TRACK or OBJECT: the cards use a single src.
		'VIDEO', 'AUDIO',
	);

	/** Elements without a closing tag. */
	private const VOID_TAGS = array( 'BR', 'HR', 'IMG' );

	/** The outer HTML element each block saves. Headings, lists and groups are resolved in wrapper_tag(). */
	private const WRAPPER_TAGS = array(
		'core/paragraph' => 'P',
		'core/list-item' => 'LI',
		'core/quote'     => 'BLOCKQUOTE',
		'core/separator' => 'HR',
		'core/image'     => 'FIGURE',
		'core/buttons'   => 'DIV',
		'core/button'    => 'DIV',
		'core/columns'   => 'DIV',
		'core/column'    => 'DIV',
		'core/spacer'    => 'DIV',
		'core/cover'     => 'DIV',
		// 2026-11-11, part 8.
		'core/table'        => 'FIGURE',
		'core/pullquote'    => 'FIGURE',
		'core/code'         => 'PRE',
		'core/preformatted' => 'PRE',
		'core/verse'        => 'PRE',
		// Part 9.
		'core/gallery'      => 'FIGURE',
		'core/media-text'   => 'DIV',
		'core/video'        => 'FIGURE',
		'core/audio'        => 'FIGURE',
		'core/file'         => 'DIV',
	);

	/** A color literal: hex, or a CSS color function (CSS allows no space before the parenthesis). */
	private const COLOR_LITERAL = '/(?<![\w&])#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})\b|\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\(/i';

	/**
	 * Validates serialized block markup.
	 *
	 * For an edit of existing content (M3-06), pass the content as it was:
	 * a block the model returned exactly as it was there (same serialization,
	 * inner blocks included) is the member's own and is accepted as it is,
	 * whatever its name or attributes (an image, a Spectra block). Every block
	 * the model wrote or changed is checked in full.
	 *
	 * For a template or template part (M3-07), the theme blocks in
	 * TEMPLATE_BLOCKS are allowed too, and a template must keep the blocks of
	 * TEMPLATE_ESSENTIAL it had.
	 *
	 * @param string $markup   The markup, exactly as it would be saved.
	 * @param string $original For an edit: the markup before it. '' for new content.
	 * @param bool   $template Whether the markup is a template or template part.
	 * @return true|WP_Error True when valid. Otherwise code `mody_invalid_blocks`,
	 *                       with a message the model can act on in a retry.
	 */
	public static function validate( string $markup, string $original = '', bool $template = false ): true|WP_Error {
		if ( '' === trim( $markup ) ) {
			return self::error( 'The response is empty.' );
		}

		$blocks = parse_blocks( $markup );

		$serialized = serialize_blocks( $blocks );
		if ( $serialized !== $markup ) {
			return self::error(
				sprintf(
					'The block markup does not survive parse_blocks() and serialize_blocks() unchanged (unbalanced or misspelled block comments, invalid attribute JSON, or attributes not written as compact JSON). First difference near: "%s".',
					self::excerpt( $markup, self::first_difference( $markup, $serialized ) )
				)
			);
		}

		$named = 0;
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				if ( '' !== trim( $block['innerHTML'] ) ) {
					return self::error( sprintf( 'There is text outside blocks: "%s". Return only blocks, with no explanations, Markdown or backticks.', self::excerpt( $block['innerHTML'], 0 ) ) );
				}
				continue;
			}
			++$named;
		}
		if ( 0 === $named ) {
			return self::error( 'The response contains no blocks.' );
		}

		$palette   = self::palette();
		$unchanged = '' === trim( $original ) ? array() : self::serialized_blocks( parse_blocks( $original ) );
		$allowed   = $template ? array_merge( self::WHITELIST, self::TEMPLATE_BLOCKS ) : self::WHITELIST;
		$new_page  = ! $template && '' === trim( $original );
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				continue; // Whitespace between blocks, checked above.
			}
			$result = self::check_block( $block, $palette, $unchanged, $allowed, $new_page, array() );
			if ( true !== $result ) {
				return $result;
			}
		}

		if ( $template && '' !== trim( $original ) ) {
			$had = self::block_names( parse_blocks( $original ) );
			$has = self::block_names( $blocks );
			foreach ( self::TEMPLATE_ESSENTIAL as $essential ) {
				if ( in_array( $essential, $had, true ) && ! in_array( $essential, $has, true ) ) {
					return self::error( sprintf( 'Keep the %s block: the pages that use this template show their content through it.', $essential ) );
				}
			}
		}

		return true;
	}

	/**
	 * Every block name in parsed blocks, inner blocks included.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return list<string>
	 */
	private static function block_names( array $blocks ): array {
		$names = array();
		foreach ( $blocks as $block ) {
			if ( null !== $block['blockName'] ) {
				$names[] = (string) $block['blockName'];
			}
			$names = array_merge( $names, self::block_names( (array) $block['innerBlocks'] ) );
		}
		return $names;
	}

	/**
	 * Slugs of the active theme's palette.
	 *
	 * @return list<string>
	 */
	public static function palette(): array {
		$slugs = array();
		// The theme's five, then the owner's own colors (M3-10).
		foreach ( array( 'theme', 'custom' ) as $origin ) {
			$palette = wp_get_global_settings( array( 'color', 'palette', $origin ) );
			$slugs   = array_merge( $slugs, is_array( $palette ) ? array_values( array_filter( array_column( $palette, 'slug' ), 'is_string' ) ) : array() );
		}
		return $slugs ? array_values( array_unique( $slugs ) ) : self::DEFAULT_PALETTE;
	}

	/**
	 * Every block of some markup, inner blocks included, serialized: the set
	 * of blocks an edit may keep as they are.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<string, true> Serialized block => true.
	 */
	private static function serialized_blocks( array $blocks ): array {
		$set = array();
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}
			$set[ serialize_block( $block ) ] = true;
			$set                             += self::serialized_blocks( (array) $block['innerBlocks'] );
		}
		return $set;
	}

	/**
	 * Checks one block and its inner blocks.
	 *
	 * @param array<string, mixed> $block     A parsed block.
	 * @param list<string>         $palette   Allowed color slugs.
	 * @param array<string, true>  $unchanged Blocks of the original content, serialized (an edit).
	 * @param list<string>         $allowed   Block names allowed (the whitelist, plus TEMPLATE_BLOCKS in templates).
	 * @param bool                 $new_page  A new page: its layout must follow SpectraLayout's recipe.
	 * @param list<string>         $outer     The blocks around it, innermost last.
	 * @return true|WP_Error
	 */
	private static function check_block( array $block, array $palette, array $unchanged = array(), array $allowed = self::WHITELIST, bool $new_page = false, array $outer = array() ): true|WP_Error {
		if ( $unchanged && isset( $unchanged[ serialize_block( $block ) ] ) ) {
			return true; // The member's own block, returned as it was.
		}
		$name = (string) $block['blockName'];

		if ( ! in_array( $name, $allowed, true ) && ! ( in_array( $name, self::POST_ITEM_BLOCKS, true ) && in_array( 'spectra/post-template', $outer, true ) ) ) {
			if ( in_array( $name, self::POST_ITEM_BLOCKS, true ) ) {
				return self::error( sprintf( 'The block "%s" goes inside a spectra/post-template, where it shows one post of the list.', $name ) );
			}
			return self::error( sprintf( 'The block "%s" is not allowed. Use only the blocks listed in the instructions.', $name ) );
		}
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			return self::error( sprintf( 'The block "%s" is not available on this site.', $name ) );
		}
		if ( $new_page && in_array( $name, self::REPLACED_BY_CONTAINER, true ) ) {
			return self::error( sprintf( 'Do not use the "%s" block. Every section, row and column is a spectra/container, as the layout rules say.', $name ) );
		}
		if ( $new_page && isset( self::REPLACED_BY_SPECTRA[ $name ] ) ) {
			return self::error( sprintf( 'Do not use the "%1$s" block: write %2$s instead, as in the example.', $name, self::REPLACED_BY_SPECTRA[ $name ] ) );
		}
		$result = self::check_nesting( $name, $outer );
		if ( true !== $result ) {
			return $result;
		}
		$result = self::check_schema( $name, (array) $block['attrs'] );
		if ( true !== $result ) {
			return $result;
		}
		$spectra = str_starts_with( $name, 'spectra/' );
		if ( $spectra && '' !== trim( (string) $block['innerHTML'] ) ) {
			return self::error( sprintf( 'The %s block saves no HTML of its own: write it as a comment with its attributes (self-closing when it has no inner blocks, such as <!-- wp:spectra/button {"text":"Book"} /-->), and between its opening and closing comments only its inner blocks, with no <div> or text. Found: "%s".', $name, self::excerpt( trim( (string) $block['innerHTML'] ), 0 ) ) );
		}
		if ( $spectra ) {
			$result = self::check_spectra_attributes( $name, (array) $block['attrs'], $palette );
			if ( true !== $result ) {
				return $result;
			}
		}
		if ( $new_page && SpectraLayout::BLOCK === $name ) {
			$result = self::check_container( (array) $block['attrs'] );
			if ( true !== $result ) {
				return $result;
			}
		}

		$result = self::check_attributes( $name, (array) $block['attrs'], $palette );
		if ( true !== $result ) {
			return $result;
		}

		// innerHTML holds only this block's own HTML; inner blocks are checked below.
		$result = self::check_structure( $name, (array) $block['attrs'], (string) $block['innerHTML'] );
		if ( true !== $result ) {
			return $result;
		}
		$result = self::check_html( $name, (string) $block['innerHTML'], $palette );
		if ( true !== $result ) {
			return $result;
		}

		foreach ( $block['innerBlocks'] as $inner ) {
			$result = self::check_block( $inner, $palette, $unchanged, $allowed, $new_page, array_merge( $outer, array( $name ) ) );
			if ( true !== $result ) {
				return $result;
			}
		}
		// After the inner blocks, whose own errors are more precise.
		if ( $spectra ) {
			$result = self::check_family( $block );
			if ( true !== $result ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * A container of a new page uses only the recipe's attributes
	 * (SpectraLayout): no backgrounds, overlays, links, shadows or sizes of
	 * its own, and spacing only from the theme's spacing presets.
	 *
	 * @param array<string, mixed> $attrs The container's attributes.
	 * @return true|WP_Error
	 */
	private static function check_container( array $attrs ): true|WP_Error {
		$extra = array_diff( array_keys( $attrs ), self::CONTAINER_KEYS['attrs'] );
		if ( $extra ) {
			return self::error( sprintf( 'The spectra/container attribute "%s" is not part of the layout rules. Use only the attributes given there for sections, rows and columns.', (string) reset( $extra ) ) );
		}
		if ( isset( $attrs['htmlTag'] ) && ! in_array( $attrs['htmlTag'], self::CONTAINER_TAGS, true ) ) {
			return self::error( sprintf( 'A spectra/container cannot be a <%s>. Sections use "htmlTag":"section"; rows and columns have no htmlTag.', is_scalar( $attrs['htmlTag'] ) ? (string) $attrs['htmlTag'] : '?' ) );
		}
		if ( isset( $attrs['align'] ) && ! in_array( $attrs['align'], array( 'full', 'wide' ), true ) ) {
			return self::error( 'A section\'s "align" is "full".' );
		}
		$style = $attrs['style'] ?? array();
		if ( ! is_array( $style ) ) {
			return self::error( 'The "style" of a spectra/container must be an object, as in the layout rules.' );
		}
		$states = array( 'style' => $style );
		foreach ( array( '@tablet', '@mobile' ) as $state ) {
			if ( isset( $style[ $state ] ) ) {
				if ( ! is_array( $style[ $state ] ) || array_diff( array_keys( $style[ $state ] ), self::CONTAINER_KEYS['state'] ) ) {
					return self::error( sprintf( 'In a spectra/container, "%s" may hold only "spacing" and "layout", as in the layout rules.', $state ) );
				}
				$states[ $state ] = $style[ $state ];
			}
		}
		$extra = array_diff( array_keys( $style ), self::CONTAINER_KEYS['style'] );
		if ( $extra ) {
			return self::error( sprintf( 'The spectra/container style "%s" is not part of the layout rules. Containers set only spacing and layout.', (string) reset( $extra ) ) );
		}
		$spacing_slugs = self::spacing_slugs();
		foreach ( $states as $where => $state ) {
			foreach ( (array) ( $state['spacing'] ?? array() ) as $property => $value ) {
				if ( ! in_array( $property, array( 'padding', 'margin', 'blockGap' ), true ) ) {
					return self::error( sprintf( 'The spacing "%s" of a spectra/container is not part of the layout rules.', $property ) );
				}
				foreach ( is_array( $value ) ? $value : array( $value ) as $side ) {
					$preset = is_string( $side ) && str_starts_with( $side, 'var:preset|spacing|' ) && in_array( substr( $side, strlen( 'var:preset|spacing|' ) ), $spacing_slugs, true );
					if ( ! $preset && '0' !== $side ) {
						return self::error( sprintf( 'The %s in "%s" of a spectra/container must be a spacing preset such as "var:preset|spacing|40" (presets: %s).', $property, $where, implode( ', ', $spacing_slugs ) ) );
					}
				}
			}
		}
		$layouts = array( $attrs['layout'] ?? array() );
		foreach ( $states as $state ) {
			$layouts[] = $state['layout'] ?? array();
		}
		foreach ( $layouts as $layout ) {
			if ( ! is_array( $layout ) || array_diff( array_keys( $layout ), self::CONTAINER_KEYS['layout'] ) ) {
				return self::error( 'The "layout" of a spectra/container may hold only the keys given in the layout rules (type, columnCount, minimumColumnWidth).' );
			}
			if ( isset( $layout['type'] ) && ! in_array( $layout['type'], array( 'constrained', 'grid', 'flex' ), true ) ) {
				return self::error( 'A section\'s layout type is "constrained" and a row\'s is "grid".' );
			}
			if ( isset( $layout['columnCount'] ) && ( ! is_int( $layout['columnCount'] ) || $layout['columnCount'] < 1 || $layout['columnCount'] > 4 ) ) {
				return self::error( 'A row has 1 to 4 columns: "columnCount" is a number from 1 to 4.' );
			}
		}
		return true;
	}

	/**
	 * Slugs of the theme's spacing presets.
	 *
	 * @return list<string>
	 */
	public static function spacing_slugs(): array {
		$sizes = wp_get_global_settings( array( 'spacing', 'spacingSizes', 'theme' ) );
		$slugs = is_array( $sizes ) ? array_values( array_map( 'strval', array_column( $sizes, 'slug' ) ) ) : array();
		return $slugs ? $slugs : array( '20', '30', '40', '50', '60', '70', '80' );
	}

	/**
	 * Colors and media in the block comment's attributes.
	 *
	 * @param string               $name    Block name.
	 * @param array<string, mixed> $attrs   Block attributes.
	 * @param list<string>         $palette Allowed color slugs.
	 * @param string               $path    Attribute path, for messages.
	 * @return true|WP_Error
	 */
	private static function check_attributes( string $name, array $attrs, array $palette, string $path = '' ): true|WP_Error {
		foreach ( $attrs as $key => $value ) {
			$key_path = '' === $path ? (string) $key : $path . '.' . $key;

			if ( is_array( $value ) ) {
				$result = self::check_attributes( $name, $value, $palette, $key_path );
				if ( true !== $result ) {
					return $result;
				}
				continue;
			}
			if ( ! is_string( $value ) ) {
				continue;
			}

			if ( preg_match( self::COLOR_LITERAL, $value ) ) {
				return self::error( sprintf( 'The attribute "%s" of %s has a loose color value "%s". Use a palette slug instead.', $key_path, $name, $value ) );
			}
			// textColor, backgroundColorHover, iconColor…: a palette slug (Spectra resolves it, M3-11).
			// The CSS variable of a palette color is accepted too: SpectraBlocks::normalize() writes
			// it for an icon's color, which Spectra prints as CSS as it is (2026-11-11).
			$variable = preg_match( '/^var\(--wp--preset--color--([a-z0-9-]+)\)$/', $value, $slug ) && in_array( $slug[1], $palette, true );
			if ( is_string( $key ) && str_contains( $key, 'Color' ) && '' !== $value && ! in_array( $value, $palette, true ) && ! $variable ) {
				return self::error( sprintf( 'The attribute "%s" of %s uses "%s", which is not a palette slug. Use one of: %s.', $key_path, $name, $value, implode( ', ', $palette ) ) );
			}
			// style.color.text, style.border.color…: a palette color, written as a preset.
			if ( self::is_style_color( $key_path ) && ! preg_match( '/^(?:var:preset\|color\|[a-z0-9-]+|var\(--wp--preset--color--[a-z0-9-]+\)|transparent|inherit|currentcolor)$/i', $value ) ) {
				return self::error( sprintf( 'The attribute "%s" of %s uses "%s". Colors in style are palette presets, such as "var:preset|color|accent".', $key_path, $name, $value ) );
			}
			if ( is_string( $key ) && ( 'gradient' === $key || str_contains( $key, 'Gradient' ) ) && '' !== $value ) {
				return self::error( sprintf( 'The attribute "%s" of %s is a gradient. Generated pages use no gradients: use a palette color.', $key_path, $name ) );
			}
			if ( is_string( $key ) && in_array( $key, self::LINK_KEYS, true ) && preg_match( '/^\s*(?:javascript|data|vbscript):/i', $value ) ) {
				return self::error( sprintf( 'The link "%s" of %s is not allowed. Links are pages of this site, #anchors, mailto:, tel: or https:// addresses.', $key_path, $name ) );
			}
			$result = self::check_preset_references( $value, $palette, $name );
			if ( true !== $result ) {
				return $result;
			}
			if ( ( 'url' === $key && in_array( $name, array( 'core/image', 'core/cover' ), true ) ) || ( 'href' === $key && 'core/file' === $name ) ) {
				$result = self::check_media_url( $value, $name );
				if ( true !== $result ) {
					return $result;
				}
			}
		}
		return true;
	}

	/**
	 * The block's own HTML is well formed: known tags, balanced, and the outer
	 * tag the block saves (a paragraph is a <p>, a level-3 heading an <h3>).
	 *
	 * Catches typos such as `<Packed with ...</p>` in place of `<p>Packed with ...</p>`,
	 * which parse as blocks but break in the editor (seen from Qwen on 2026-11-06).
	 *
	 * @param string               $name  Block name.
	 * @param array<string, mixed> $attrs Block attributes.
	 * @param string               $html  The block's own HTML.
	 * @return true|WP_Error
	 */
	private static function check_structure( string $name, array $attrs, string $html ): true|WP_Error {
		$processor = new \WP_HTML_Tag_Processor( $html );
		$stack     = array();
		$first     = null;

		while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag = (string) $processor->get_tag();
			if ( ! in_array( $tag, self::HTML_TAGS, true ) ) {
				return self::error( sprintf( 'The %s block contains the unknown HTML tag <%s>. Check the spelling of its tags.', $name, strtolower( $tag ) ) );
			}
			if ( $processor->is_tag_closer() ) {
				$open = array_pop( $stack );
				if ( $open !== $tag ) {
					return self::error( sprintf( 'The %s block closes </%s> where <%s> is open. Its HTML tags must be balanced.', $name, strtolower( $tag ), strtolower( (string) $open ) ) );
				}
				continue;
			}
			$first = $first ?? $tag;
			if ( ! in_array( $tag, self::VOID_TAGS, true ) ) {
				$stack[] = $tag;
			}
		}

		if ( $processor->paused_at_incomplete_token() ) {
			return self::error( sprintf( 'The HTML of the %s block ends in the middle of a tag.', $name ) );
		}
		if ( $stack ) {
			return self::error( sprintf( 'The %s block leaves <%s> unclosed. Its HTML tags must be balanced.', $name, strtolower( (string) end( $stack ) ) ) );
		}

		$expected = self::wrapper_tag( $name, $attrs );
		if ( null !== $expected && $first !== $expected ) {
			return self::error( sprintf( 'The %s block must be wrapped in <%s>, not <%s>.', $name, strtolower( $expected ), strtolower( (string) $first ) ) );
		}

		return true;
	}

	/**
	 * The outer tag a block saves, or null when this validator does not know it.
	 *
	 * @param string               $name  Block name.
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private static function wrapper_tag( string $name, array $attrs ): ?string {
		return match ( $name ) {
			'core/heading' => 'H' . (int) ( $attrs['level'] ?? 2 ),
			'core/list'    => empty( $attrs['ordered'] ) ? 'UL' : 'OL',
			'core/group'   => strtoupper( is_string( $attrs['tagName'] ?? null ) ? $attrs['tagName'] : 'div' ),
			default        => self::WRAPPER_TAGS[ $name ] ?? null,
		};
	}

	/**
	 * Colors and media in the block's own HTML.
	 *
	 * @param string       $name    Block name.
	 * @param string       $html    The block's own HTML.
	 * @param list<string> $palette Allowed color slugs.
	 * @return true|WP_Error
	 */
	private static function check_html( string $name, string $html, array $palette ): true|WP_Error {
		// Inline styles: no color literals, and color properties only through palette variables.
		preg_match_all( '/\sstyle\s*=\s*(["\'])(.*?)\1/is', $html, $styles );
		foreach ( $styles[2] as $style ) {
			if ( preg_match( self::COLOR_LITERAL, $style ) ) {
				return self::error( sprintf( 'The inline style "%s" in %s has a loose color. Use palette slugs through the textColor and backgroundColor attributes.', $style, $name ) );
			}
			preg_match_all( '/(?:^|;)\s*([a-z-]+)\s*:\s*([^;]+)/i', $style, $declarations, PREG_SET_ORDER );
			foreach ( $declarations as $declaration ) {
				$property = strtolower( $declaration[1] );
				$value    = trim( $declaration[2] );
				$is_color = str_ends_with( $property, 'color' ) || in_array( $property, array( 'background', 'fill', 'stroke' ), true );
				if ( $is_color && ! preg_match( '/^(?:var\(--wp--preset--color--[a-z0-9-]+\)|transparent|inherit|currentcolor|initial|unset)$/i', $value ) ) {
					return self::error( sprintf( 'The inline style "%s: %s" in %s is not a palette color.', $property, $value, $name ) );
				}
			}
			$result = self::check_preset_references( $style, $palette, $name );
			if ( true !== $result ) {
				return $result;
			}
		}

		// Color attributes on elements (fill, stroke, color, bgcolor).
		preg_match_all( '/\s(?:fill|stroke|bgcolor|color)\s*=\s*(["\'])(.*?)\1/is', $html, $color_attrs );
		foreach ( $color_attrs[2] as $value ) {
			if ( ! preg_match( '/^(?:none|currentcolor|transparent)$/i', trim( $value ) ) ) {
				return self::error( sprintf( 'The color attribute value "%s" in %s is not allowed. Use palette slugs.', $value, $name ) );
			}
		}

		// Palette classes such as has-primary-color or has-base-background-color.
		preg_match_all( '/\bhas-([a-z0-9-]+?)-(?:background-color|border-color|color)\b/', $html, $classes );
		foreach ( array_unique( $classes[1] ) as $slug ) {
			if ( ! in_array( $slug, $palette, true ) && ! in_array( $slug, self::GENERIC_COLOR_CLASSES, true ) ) {
				return self::error( sprintf( 'The class "has-%s-..." in %s refers to "%s", which is not a palette slug. Use one of: %s.', $slug, $name, $slug, implode( ', ', $palette ) ) );
			}
		}

		// Links: no scripts or data.
		preg_match_all( '/\shref\s*=\s*(["\'])(.*?)\1/is', $html, $hrefs );
		foreach ( $hrefs[2] as $href ) {
			if ( preg_match( '/^\s*(?:javascript|data|vbscript):/i', html_entity_decode( $href ) ) ) {
				return self::error( sprintf( 'The link "%s" in %s is not allowed. Links are pages of this site, #anchors, mailto:, tel: or https:// addresses.', $href, $name ) );
			}
		}

		// A file block's links are the file itself: on this network, like other media (part 9).
		if ( 'core/file' === $name ) {
			foreach ( $hrefs[2] as $href ) {
				$result = self::check_media_url( html_entity_decode( $href ), $name );
				if ( true !== $result ) {
					return $result;
				}
			}
		}

		// Media sources: src, srcset, poster and CSS url().
		preg_match_all( '/\s(?:src|poster)\s*=\s*(["\'])(.*?)\1/is', $html, $sources );
		preg_match_all( '/\ssrcset\s*=\s*(["\'])(.*?)\1/is', $html, $srcsets );
		foreach ( $srcsets[2] as $srcset ) {
			foreach ( explode( ',', $srcset ) as $candidate ) {
				$sources[2][] = strtok( trim( $candidate ), ' ' );
			}
		}
		preg_match_all( '/url\(\s*["\']?([^"\')]+)/i', implode( ' ', $styles[2] ), $css_urls );
		foreach ( array_merge( $sources[2], $css_urls[1] ) as $url ) {
			$result = self::check_media_url( (string) $url, $name );
			if ( true !== $result ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Attributes Spectra's editor script adds that its blocks do not register
	 * in PHP (SpectraLayout::with_ids() writes them too).
	 */
	private const UNREGISTERED = array( 'spectraId' );

	/**
	 * Checks a block's attributes against what the block registers
	 * (2026-11-11, part 4 of tickets/agent-knows-gutenberg-spectra-settings.md):
	 * no invented attribute, none that core keeps in the block's HTML, and
	 * each of its type and allowed values. Older forms the editor still
	 * migrates (a heading's `textAlign`) are refused too: new content uses
	 * the current ones.
	 *
	 * @param string               $name  Block name.
	 * @param array<string, mixed> $attrs Its attributes.
	 * @return true|WP_Error
	 */
	private static function check_schema( string $name, array $attrs ): true|WP_Error {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );
		if ( ! $type ) {
			return true;
		}
		foreach ( $attrs as $key => $value ) {
			$schema = $type->attributes[ $key ] ?? null;
			if ( null === $schema ) {
				if ( str_starts_with( $name, 'spectra/' ) && in_array( $key, self::UNREGISTERED, true ) ) {
					continue;
				}
				$names = array_keys( array_filter( (array) $type->attributes, static fn( $spec ): bool => ! isset( $spec['source'] ) ) );
				return self::error( sprintf( 'The %1$s block has no attribute "%2$s". Its attributes are: %3$s.', $name, $key, implode( ', ', array_slice( $names, 0, 60 ) ) ) );
			}
			// Spectra marks two editor-only attributes with a source, but its blocks save no HTML.
			if ( isset( $schema['source'] ) && ! str_starts_with( $name, 'spectra/' ) ) {
				return self::error( sprintf( 'The "%1$s" of the %2$s block is not written in its comment: it is the block\'s HTML content.', $key, $name ) );
			}
			$valid = rest_validate_value_from_schema( $value, array_intersect_key( (array) $schema, array_flip( array( 'type', 'enum', 'items', 'properties' ) ) ), $key );
			if ( is_wp_error( $valid ) ) {
				return self::error( sprintf( 'In the %1$s block: %2$s', $name, $valid->get_error_message() ) );
			}
		}
		return true;
	}

	/**
	 * A block sits where its block.json allows (M3-11): directly inside one of
	 * its `parent` blocks, somewhere inside one of its `ancestor` blocks, and
	 * only in a block whose `allowedBlocks` lists it. A child that names its
	 * parent itself is accepted too: Spectra 1.0.13's modal trigger lists
	 * "spectra/spectra/modal-child-button" in allowedBlocks, a typo.
	 *
	 * @param string       $name  Block name.
	 * @param list<string> $outer The blocks around it, innermost last.
	 * @return true|WP_Error
	 */
	private static function check_nesting( string $name, array $outer ): true|WP_Error {
		$registry = WP_Block_Type_Registry::get_instance();
		$type     = $registry->get_registered( $name );
		$parent   = $outer ? (string) end( $outer ) : null;
		$parents  = $type && is_array( $type->parent ) ? $type->parent : array();
		if ( $parents && ! in_array( $parent, $parents, true ) ) {
			return self::error( sprintf( 'The %s block goes directly inside %s, not %s.', $name, implode( ' or ', $parents ), null === $parent ? 'at the top level' : 'inside ' . $parent ) );
		}
		$ancestors = $type && is_array( $type->ancestor ) ? $type->ancestor : array();
		if ( $ancestors && ! array_intersect( $ancestors, $outer ) ) {
			return self::error( sprintf( 'The %s block goes somewhere inside %s.', $name, implode( ' or ', $ancestors ) ) );
		}
		if ( null !== $parent ) {
			$outer_type = $registry->get_registered( $parent );
			$children   = $outer_type && is_array( $outer_type->allowed_blocks ) ? $outer_type->allowed_blocks : array();
			if ( $children && ! in_array( $name, $children, true ) && ! in_array( $parent, $parents, true ) ) {
				return self::error( sprintf( 'The %s block may hold only %s, not %s.', $parent, implode( ', ', $children ), $name ) );
			}
		}
		return true;
	}

	/**
	 * What only Spectra blocks carry (M3-11): media from this network, icons
	 * by name, the element they render as, no `htmlAttributes`, and HTML in
	 * text attributes held to the rules of block HTML.
	 *
	 * @param string               $name    Block name.
	 * @param array<string, mixed> $attrs   Block attributes.
	 * @param list<string>         $palette Allowed color slugs.
	 * @return true|WP_Error
	 */
	private static function check_spectra_attributes( string $name, array $attrs, array $palette ): true|WP_Error {
		if ( ! empty( $attrs['htmlAttributes'] ) ) {
			return self::error( sprintf( 'Do not set htmlAttributes on %s.', $name ) );
		}
		foreach ( array( 'icon', 'iconSecondary', 'hoverIcon' ) as $key ) {
			if ( isset( $attrs[ $key ] ) && ( ! is_string( $attrs[ $key ] ) || ! preg_match( '/^[a-z0-9-]*$/', $attrs[ $key ] ) ) ) {
				return self::error( sprintf( 'The "%s" of %s is an icon name, such as "star" or "arrow-right".', $key, $name ) );
			}
			// A name outside Spectra's library renders an empty icon.
			if ( isset( $attrs[ $key ] ) && '' !== $attrs[ $key ] && ! self::is_icon( $attrs[ $key ] ) ) {
				return self::error( sprintf( 'The icon "%1$s" of %2$s does not exist. Use a Font Awesome name from the icons card, such as "star", "phone", "envelope" or "arrow-right".', $attrs[ $key ], $name ) );
			}
		}
		foreach ( self::SPECTRA_ENUMS[ $name ] ?? array() as $key => $values ) {
			if ( isset( $attrs[ $key ] ) && ! in_array( $attrs[ $key ], $values, true ) ) {
				return self::error( sprintf( 'The "%1$s" of %2$s must be one of: %3$s.', $key, $name, implode( ', ', $values ) ) );
			}
		}
		foreach ( self::TAG_KEYS as $key ) {
			if ( isset( $attrs[ $key ] ) && ! in_array( $attrs[ $key ], self::SAFE_TAGS, true ) ) {
				return self::error( sprintf( 'The "%s" of %s must be one of: %s.', $key, $name, implode( ', ', self::SAFE_TAGS ) ) );
			}
		}
		$strings = array();
		array_walk_recursive(
			$attrs,
			static function ( $value, $key ) use ( &$strings ) {
				if ( is_string( $value ) ) {
					$strings[] = array( (string) $key, $value );
				}
			}
		);
		foreach ( $strings as list( $key, $value ) ) {
			// Anything that loads from an address is media: this network only (rule 1).
			if ( ! in_array( $key, self::LINK_KEYS, true ) && preg_match( '#^\s*(?:https?:)?//#i', $value ) ) {
				$result = self::check_media_url( $value, $name );
				if ( true !== $result ) {
					return $result;
				}
			}
			// Text with markup (a heading's text with <strong>): known tags only, and block HTML's rules.
			if ( str_contains( $value, '<' ) ) {
				$processor = new \WP_HTML_Tag_Processor( $value );
				while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
					if ( ! in_array( (string) $processor->get_tag(), self::HTML_TAGS, true ) ) {
						return self::error( sprintf( 'The "%s" of %s contains the HTML tag <%s>, which is not allowed there.', $key, $name, strtolower( (string) $processor->get_tag() ) ) );
					}
				}
				$result = self::check_html( $name, $value, $palette );
				if ( true !== $result ) {
					return $result;
				}
			}
		}
		return true;
	}

	/**
	 * Whether an icon name is in Spectra's library, or one of its aliases
	 * (the lookup Spectra's renderer does). Without Spectra, any name passes.
	 *
	 * @param string $icon An icon name.
	 */
	private static function is_icon( string $icon ): bool {
		if ( null === self::$icons ) {
			self::$icons = array();
			if ( class_exists( '\\SpectraBlocks\\Helpers\\Renderer' ) && method_exists( '\\SpectraBlocks\\Helpers\\Renderer', 'icons' ) ) {
				self::$icons = array_fill_keys( array_keys( (array) \SpectraBlocks\Helpers\Renderer::icons() ), true );
				if ( class_exists( '\\SpectraBlocks\\Helpers\\Core' ) && method_exists( '\\SpectraBlocks\\Helpers\\Core', 'backend_load_font_awesome_icon_aliases' ) ) {
					self::$icons += array_fill_keys( array_keys( (array) \SpectraBlocks\Helpers\Core::backend_load_font_awesome_icon_aliases() ), true );
				}
			}
		}
		return ! self::$icons || isset( self::$icons[ $icon ] );
	}

	/**
	 * What a Spectra family needs to work (M3-11 part 3): tabs have one label
	 * per panel (a label without a panel shows nothing), and an accordion item
	 * has its question and then its answer.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return true|WP_Error
	 */
	private static function check_family( array $block ): true|WP_Error {
		$inner = array_column( (array) $block['innerBlocks'], 'blockName' );
		if ( 'spectra/tabs' === $block['blockName'] ) {
			$wrappers = array_values( array_filter( (array) $block['innerBlocks'], static fn( $b ) => 'spectra/tabs-child-tab-wrapper' === $b['blockName'] ) );
			$labels   = $wrappers ? count( (array) $wrappers[0]['innerBlocks'] ) : 0;
			$panels   = count( array_keys( $inner, 'spectra/tabs-child-tabpanel', true ) );
			if ( 1 !== count( $wrappers ) || 0 === $panels || $labels !== $panels ) {
				return self::error( sprintf( 'A spectra/tabs block holds one tabs-child-tab-wrapper with one tabs-child-tab-button per tab, then one tabs-child-tabpanel per tab, as in the example. Found %d wrapper(s), %d tab label(s) and %d panel(s).', count( $wrappers ), $labels, $panels ) );
			}
		}
		if ( 'spectra/countdown' === $block['blockName'] ) {
			$end = (string) ( $block['attrs']['endDateTime'] ?? '' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $end ) || false === strtotime( $end ) ) {
				return self::error( 'A spectra/countdown needs "endDateTime", the date and time it counts down to, as in the example (2027-09-04T07:00:00.000Z). Use a countdown only when the request gives the date and time.' );
			}
			$shown = array_filter( array( 'showDays', 'showHours', 'showMinutes', 'showSeconds' ), static fn( $flag ) => false !== ( $block['attrs'][ $flag ] ?? true ) );
			if ( ! $shown ) {
				return self::error( 'A spectra/countdown must show at least one of days, hours, minutes or seconds.' );
			}
		}
		if ( 'spectra/accordion-child-item' === $block['blockName'] && array( 'spectra/accordion-child-header', 'spectra/accordion-child-details' ) !== $inner ) {
			return self::error( 'Each spectra/accordion-child-item holds one accordion-child-header (the question) and then one accordion-child-details (the answer), as in the example.' );
		}
		return true;
	}

	/**
	 * Whether an attribute path is a color in core's `style`: style.color.text,
	 * style.color.background, style.elements.link.color.text,
	 * style.border.color, style.border.top.color.
	 *
	 * @param string $path Attribute path, dot-separated.
	 */
	private static function is_style_color( string $path ): bool {
		return str_starts_with( $path, 'style.' ) && (bool) preg_match( '/(?:^|\.)color\.(?:text|background)$|(?:^|\.)border(?:\.(?:top|right|bottom|left))?\.color$/', $path );
	}

	/**
	 * Palette references (`var:preset|color|slug`, `var(--wp--preset--color--slug)`) must name palette slugs.
	 *
	 * @param string       $value   Text to scan.
	 * @param list<string> $palette Allowed color slugs.
	 * @param string       $name    Block name, for messages.
	 * @return true|WP_Error
	 */
	private static function check_preset_references( string $value, array $palette, string $name ): true|WP_Error {
		preg_match_all( '/var(?::preset\|color\||\(--wp--preset--color--)([a-z0-9-]+)/', $value, $matches );
		foreach ( $matches[1] as $slug ) {
			if ( ! in_array( $slug, $palette, true ) ) {
				return self::error( sprintf( 'The color "%s" in %s is not a palette slug. Use one of: %s.', $slug, $name, implode( ', ', $palette ) ) );
			}
		}
		return true;
	}

	/**
	 * Media must come from this network, or be a path on the same host.
	 *
	 * @param string $url  The URL.
	 * @param string $name Block name, for messages.
	 * @return true|WP_Error
	 */
	private static function check_media_url( string $url, string $name ): true|WP_Error {
		$url = trim( $url );
		if ( '' === $url || ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) ) {
			return true;
		}

		$host    = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$network = function_exists( 'get_network' ) && get_network() ? strtolower( get_network()->domain ) : '';
		$home    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$local   = '' !== $host && ( $host === $home || ( '' !== $network && ( $host === $network || str_ends_with( $host, '.' . $network ) ) ) );

		return $local
			? true
			: self::error( sprintf( 'The %s block loads media from "%s". Do not use images or other media from external sites.', $name, $url ) );
	}

	/**
	 * Byte offset of the first difference between two strings.
	 *
	 * @param string $a First string.
	 * @param string $b Second string.
	 */
	private static function first_difference( string $a, string $b ): int {
		$length = min( strlen( $a ), strlen( $b ) );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( $a[ $i ] !== $b[ $i ] ) {
				return $i;
			}
		}
		return $length;
	}

	/**
	 * A short, single-line piece of text around an offset.
	 *
	 * @param string $text   The text.
	 * @param int    $offset Byte offset.
	 */
	private static function excerpt( string $text, int $offset ): string {
		$start = max( 0, $offset - 30 );
		$piece = mb_strcut( $text, $start, 90 );
		return trim( (string) preg_replace( '/\s+/', ' ', $piece ) );
	}

	/**
	 * A validation error.
	 *
	 * @param string $message What is wrong, phrased so the model can fix it.
	 */
	private static function error( string $message ): WP_Error {
		return new WP_Error( 'mody_invalid_blocks', $message );
	}
}
