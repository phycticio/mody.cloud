<?php
/**
 * Tests that the validator takes every Spectra block but the popup builder,
 * with Spectra's rules (ticket M3-11, part 2).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/spectra-blocks-test.php
 *
 * 1. The list: every registered Spectra block is allowed or excluded on
 *    purpose, so a Spectra update that adds one fails here until reviewed.
 * 2. Spectra's own markup for each family (from its create-* abilities,
 *    which return markup when given no post) passes, as a new page and as
 *    an edit, and renders.
 * 3. What is refused: HTML inside a Spectra block, blocks out of place,
 *    colors that are not palette slugs, gradients, script links, media
 *    from elsewhere, icon objects, htmlAttributes, unsafe tags, markup in
 *    text attributes, the popup builder.
 *
 * Read-only: nothing is saved. No model call.
 */

use Mody\Platform\AI\BlockValidator;

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-64s %s\n", $ok ? 'ok' : 'FAIL', $label, $ok ? '' : $detail );
};
$valid = static function ( string $markup, string $original = '' ) {
	$result = BlockValidator::validate( $markup, $original );
	return true === $result ? 'valid' : $result->get_error_message();
};
$others = get_sites(
	array(
		'site__not_in' => array( get_main_site_id() ),
		'fields'       => 'ids',
		'number'       => 1,
	)
);
if ( $others ) {
	switch_to_blog( (int) $others[0] ); // A member site.
}

// 1. The list -------------------------------------------------------------------------------------

echo "The list\n";
$registered = array_values( array_filter( array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ), static fn( $name ) => str_starts_with( $name, 'spectra/' ) ) );
$unreviewed = array_diff( $registered, BlockValidator::SPECTRA_BLOCKS, BlockValidator::SPECTRA_EXCLUDED );
$check( 'every registered Spectra block is allowed or excluded', ! $unreviewed, 'review in BlockValidator::SPECTRA_BLOCKS: ' . implode( ', ', $unreviewed ) );
$missing = array_diff( BlockValidator::SPECTRA_BLOCKS, $registered );
$check( 'every allowed Spectra block is registered', ! $missing, implode( ', ', $missing ) );
$check( '46 allowed, on the whitelist', 46 === count( BlockValidator::SPECTRA_BLOCKS ) && ! array_diff( BlockValidator::SPECTRA_BLOCKS, BlockValidator::WHITELIST ) );
$check( 'the popup builder and the map are not', ! array_intersect( BlockValidator::SPECTRA_EXCLUDED, BlockValidator::WHITELIST ) );

// 2. Spectra's own markup -------------------------------------------------------------------------

echo "\nSpectra's own markup, per family\n";
$inputs = array(
	'CreateAccordion' => array( 'items' => array( array( 'question' => 'Do you deliver?', 'answer' => 'Yes, every morning.' ), array( 'question' => 'Is it organic?', 'answer' => 'Our flour is.' ) ) ),
	'CreateButtons'   => array( 'buttons' => array( array( 'text' => 'Order now', 'linkURL' => '#order' ), array( 'text' => 'Contact', 'linkURL' => '/contact/' ) ) ),
	'CreateContent'   => array( 'text' => 'Fresh bread for <strong>your office</strong>.', 'tagName' => 'p' ),
	'CreateCountdown' => array( 'endDateTime' => '2026-12-24T18:00:00' ),
	'CreateCounter'   => array( 'endNumber' => 120, 'title' => 'Offices served', 'suffix' => '+' ),
	'CreateIcons'     => array( 'icons' => array( array( 'icon' => 'heart', 'accessibilityLabel' => 'Love' ), array( 'icon' => 'star', 'accessibilityLabel' => 'Quality' ) ) ),
	'CreateList'      => array( 'items' => array( array( 'text' => 'Sourdough' ), array( 'text' => 'Rye' ) ) ),
	'CreateModal'     => array( 'triggerText' => 'See our hours', 'popupContent' => 'Mon to Fri, 7 to 15.' ),
	'CreatePost'      => array( 'postsToShow' => 3, 'columns' => 3 ),
	'CreateSeparator' => array(),
	'CreateSlider'    => array( 'slides' => array( array( 'content' => 'Our bakery' ), array( 'content' => 'Our team' ) ) ),
	'CreateTabs'      => array( 'tabs' => array( array( 'title' => 'Bread', 'content' => 'Sourdough and rye.' ), array( 'title' => 'Pastries', 'content' => 'Croissants.' ) ) ),
);
$samples = array();
foreach ( $inputs as $class => $input ) {
	$ability = 'SpectraBlocks\\Abilities\\' . $class;
	$out     = class_exists( $ability ) ? $ability::instance()->execute( $input ) : null;
	if ( ! is_array( $out ) ) {
		$check( $class . ': markup from Spectra', false, is_wp_error( $out ) ? $out->get_error_message() : 'no class' );
		continue;
	}
	// As WordPress serializes it: compact JSON, as the model must write it.
	$markup            = serialize_blocks( parse_blocks( (string) $out['block_markup'] ) );
	$samples[ $class ] = $markup;
	$result            = $valid( $markup );
	$check( $class . ': valid as a new page', 'valid' === $result, $result );
	$check( $class . ': valid as an edit', 'valid' === $valid( $markup, '<!-- wp:paragraph --><p>Before.</p><!-- /wp:paragraph -->' ) );
	$html = do_blocks( $markup );
	$check( $class . ': renders', '' !== trim( wp_strip_all_tags( $html, true ) ) || str_contains( $html, 'wp-block-spectra' ), mb_substr( $html, 0, 80 ) );
}
$section = '<!-- wp:spectra/container {"variationSelected":true,"htmlTag":"section","align":"full","isBlockRootParent":true,"layout":{"type":"constrained"}} -->' . ( $samples['CreateAccordion'] ?? '' ) . '<!-- /wp:spectra/container -->';
$check( 'an accordion in a section (the layout recipe)', 'valid' === $valid( $section ), $valid( $section ) );
$modal = $samples['CreateModal'] ?? '';
$check( 'the modal button in its trigger (Spectra\'s allowedBlocks typo)', str_contains( $modal, 'spectra/modal-child-button' ) && 'valid' === $valid( $modal ) );

// 3. What is refused ------------------------------------------------------------------------------

echo "\nRefused, each for its own reason\n";
// Built the way WordPress serializes blocks (markup in attributes becomes \u003c…), so each
// case reaches the rule it tests instead of failing the round trip.
$block   = static function ( string $name, array $attrs = array(), array $inner = array() ): string {
	return serialize_block(
		array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => '',
			'innerContent' => array_fill( 0, count( $inner ), null ),
		)
	);
};
$parsed  = static fn( string $markup ): array => parse_blocks( $markup )[0];
$button  = static fn( array $attrs ): string => $block( 'spectra/buttons', array(), array( $parsed( $block( 'spectra/button', $attrs ) ) ) );
$content = static fn( array $attrs ): string => $block( 'spectra/content', $attrs );
$edit    = '<!-- wp:paragraph --><p>Before.</p><!-- /wp:paragraph -->'; // As an edit: containers are not held to the new-page recipe.
$local   = esc_url_raw( home_url( '/wp-content/uploads/a.jpg' ) );
$refused = array(
	'HTML inside a Spectra block'           => array( '<!-- wp:spectra/buttons --><!-- wp:spectra/button {"text":"Go"} --><a href="#">Go</a><!-- /wp:spectra/button --><!-- /wp:spectra/buttons -->', 'saves no HTML' ),
	'a button outside buttons'              => array( $block( 'spectra/button', array( 'text' => 'Go' ) ), 'goes directly inside spectra/buttons' ),
	'a button inside an accordion'          => array( $block( 'spectra/accordion', array(), array( $parsed( $block( 'spectra/button', array( 'text' => 'Go' ) ) ) ) ), 'goes directly inside spectra/buttons' ),
	'a container inside an accordion'       => array( $block( 'spectra/accordion', array(), array( $parsed( $block( 'spectra/container' ) ) ) ), 'may hold only' ),
	'an accordion title outside its header' => array( $block( 'spectra/accordion', array(), array( $parsed( $block( 'spectra/accordion-child-item', array(), array( $parsed( $block( 'spectra/accordion-child-header-content', array( 'text' => 'Q' ) ) ) ) ) ) ) ), 'goes directly inside spectra/accordion-child-header' ),
	'a post template outside a post block'  => array( $block( 'spectra/post-template' ), 'goes somewhere inside spectra/post' ),
	'a hex text color'                      => array( $button( array( 'text' => 'Go', 'textColor' => '#ff0000' ) ), 'loose color' ),
	'a named text color'                    => array( $button( array( 'text' => 'Go', 'textColor' => 'red' ) ), 'not a palette slug' ),
	'an unknown slug in a hover color'      => array( $button( array( 'text' => 'Go', 'backgroundColorHover' => 'nope' ) ), 'not a palette slug' ),
	'a gradient'                            => array( $button( array( 'text' => 'Go', 'backgroundGradient' => 'linear-gradient(var(--wp--preset--color--primary),var(--wp--preset--color--accent))' ) ), 'gradient' ),
	'a javascript: link'                    => array( $button( array( 'text' => 'Go', 'linkURL' => 'javascript:alert(1)' ) ), 'is not allowed' ),
	'an icon object (custom SVG)'           => array( $button( array( 'text' => 'Go', 'icon' => array( 'svg' => '<svg></svg>' ) ) ), 'icon name' ),
	'htmlAttributes'                        => array( $button( array( 'text' => 'Go', 'htmlAttributes' => array( 'onclick' => 'alert(1)' ) ) ), 'htmlAttributes' ),
	'a background image from another site'  => array( $block( 'spectra/container', array( 'background' => array( 'type' => 'image', 'media' => array( 'url' => 'https://images.example.com/a.jpg' ) ) ) ), 'external sites', $edit ),
	'a script tag as the element'           => array( $content( array( 'text' => 'Hi', 'tagName' => 'script' ) ), 'must be one of' ),
	'a script in text'                      => array( $content( array( 'text' => 'Hi <script>alert(1)</script>' ) ), 'HTML tag <script>' ),
	'an image from elsewhere in text'       => array( $content( array( 'text' => 'Hi <img src="https://images.example.com/a.jpg">' ) ), 'external sites' ),
	'a javascript: link in text'            => array( $content( array( 'text' => '<a href="javascript:alert(1)">Hi</a>' ) ), 'is not allowed' ),
	'an inline color in text'               => array( $content( array( 'text' => '<span style="color:#f00">Hi</span>' ) ), 'loose color' ),
	'the popup builder'                     => array( $block( 'spectra/popup-builder' ), 'not allowed' ),
	'a named color in core style'           => array( '<!-- wp:paragraph {"style":{"color":{"text":"red"}}} --><p>Hi</p><!-- /wp:paragraph -->', 'palette presets' ),
);
foreach ( $refused as $label => $case ) {
	$result = $valid( $case[0], $case[2] ?? '' );
	$check( 'refused: ' . $label, 'valid' !== $result && str_contains( $result, $case[1] ), $result );
}

echo "\nAccepted\n";
$accepted = array(
	'palette slugs in Spectra colors'            => array( $button( array( 'text' => 'Go', 'textColor' => 'base', 'backgroundColor' => 'primary', 'backgroundColorHover' => 'accent', 'iconColor' => 'base' ) ) ),
	'a link to another site (a link, not media)' => array( $button( array( 'text' => 'Book', 'linkURL' => 'https://booking.example.com/', 'linkTarget' => '_blank' ) ) ),
	'an icon by name'                            => array( $button( array( 'text' => 'Go', 'icon' => 'arrow-right' ) ) ),
	'formatting in text, as WordPress stores it' => array( $content( array( 'text' => 'Fresh <strong>bread</strong>, <a href="/contact/">contact us</a>', 'tagName' => 'h2' ) ) ),
	'a background image from this site'          => array( $block( 'spectra/container', array( 'background' => array( 'type' => 'image', 'media' => array( 'url' => $local ) ) ) ), $edit ),
	'core style colors as presets'               => array( '<!-- wp:paragraph {"style":{"color":{"text":"var:preset|color|accent"}}} --><p class="has-text-color" style="color:var(--wp--preset--color--accent)">Hi</p><!-- /wp:paragraph -->' ),
);
foreach ( $accepted as $label => $case ) {
	$result = $valid( $case[0], $case[1] ?? '' );
	$check( 'accepted: ' . $label, 'valid' === $result, $result );
}
$raw = '<!-- wp:spectra/content {"text":"Fresh <strong>bread</strong>"} /-->';
$check( 'raw markup in an attribute fails the round trip (PageGenerator::repair() rewrites it first)', str_contains( $valid( $raw ), 'serialize_blocks' ) );

if ( $others ) {
	restore_current_blog();
}
echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
