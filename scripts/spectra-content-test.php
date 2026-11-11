<?php
/**
 * Tests the Spectra block families the writers know (AI\SpectraBlocks, M3-11 parts 3 to 7).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/spectra-content-test.php
 *
 * 1. The prompts: the page and edit writers carry the reference and offer the families.
 * 2. The examples: valid inside a section, the same blocks and attributes as
 *    Spectra's own generators write, unchanged by normalize(), and rendered.
 * 3. The repair of attribute JSON (PageGenerator::repair()): text with &, quotes
 *    and tags is written as WordPress saves it; broken JSON is left to the validator.
 * 4. The validator: tabs need one label per panel, an accordion item its
 *    question and its answer.
 * 5. normalize(): tabs numbered by position, and the tabs' variation set.
 * 6. Real requests on the main site: an FAQ page, a page in tabs, a page that
 *    needs neither, a whole-content edit and a selected-block edit. Paced for
 *    Infomaniak's rate limit; add `-e MODY_TEST_OFFLINE=1` after `docker exec`
 *    to skip them.
 *
 * Nothing is saved.
 */

use Mody\Platform\AI\BlockValidator;
use Mody\Platform\AI\PageGenerator;
use Mody\Platform\AI\SpectraBlocks;
use Mody\Platform\AI\SpectraLayout;
use Mody\Platform\AI\SystemPrompt;

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-60s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};
$valid    = static function ( string $markup, string $original = '' ): string {
	$result = BlockValidator::validate( $markup, $original );
	return true === $result ? 'valid' : $result->get_error_message();
};
$section  = static fn( string $inner ): string => '<!-- wp:spectra/container ' . SpectraLayout::SECTION . " -->\n" . $inner . "\n<!-- /wp:spectra/container -->";
// Every block of some markup, depth first: [ name, attrs, inner block names ].
$blocks = static function ( string $markup ): array {
	$found = array();
	$walk  = static function ( array $list ) use ( &$walk, &$found ): void {
		foreach ( $list as $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}
			$found[] = array( $block['blockName'], (array) $block['attrs'], array_column( $block['innerBlocks'], 'blockName' ) );
			$walk( $block['innerBlocks'] );
		}
	};
	$walk( parse_blocks( $markup ) );
	return $found;
};
$count = static fn( string $markup, string $name ): int => count( array_filter( $blocks( $markup ), static fn( $b ) => $name === $b[0] ) );
// A short description of a family in some markup, for the output.
$tally = static function ( string $markup ) use ( $count ): string {
	return sprintf( '%d accordion(s) with %d item(s), %d tabs with %d label(s) and %d panel(s)', $count( $markup, 'spectra/accordion' ), $count( $markup, 'spectra/accordion-child-item' ), $count( $markup, 'spectra/tabs' ), $count( $markup, 'spectra/tabs-child-tab-button' ), $count( $markup, 'spectra/tabs-child-tabpanel' ) );
};
// The tabs of some markup, each as its labels' and panels' currentTab numbers.
$numbers = static function ( string $markup ): array {
	$found = array();
	$walk  = static function ( array $list ) use ( &$walk, &$found ): void {
		foreach ( $list as $block ) {
			if ( 'spectra/tabs' === $block['blockName'] ) {
				$tabs = array( 'open' => $block['attrs']['currentTab'] ?? null, 'variation' => $block['attrs']['variationSelected'] ?? null, 'labels' => array(), 'panels' => array() );
				foreach ( $block['innerBlocks'] as $inner ) {
					if ( 'spectra/tabs-child-tab-wrapper' === $inner['blockName'] ) {
						$tabs['labels'] = array_map( static fn( $b ) => $b['attrs']['currentTab'] ?? null, $inner['innerBlocks'] );
					} elseif ( 'spectra/tabs-child-tabpanel' === $inner['blockName'] ) {
						$tabs['panels'][] = $inner['attrs']['currentTab'] ?? null;
					}
				}
				$found[] = $tabs;
			}
			$walk( (array) $block['innerBlocks'] );
		}
	};
	$walk( parse_blocks( $markup ) );
	return $found;
};
$others = get_sites(
	array(
		'site__not_in' => array( get_main_site_id() ),
		'fields'       => 'ids',
		'number'       => 1,
	)
);
$member = $others ? (int) $others[0] : get_main_site_id();

// 1. The prompts -------------------------------------------------------------------------------

echo "The prompts\n";
$reference = SpectraBlocks::reference();
$prompts   = array(
	'page'            => SystemPrompt::for_page(),
	'whole edit'      => SystemPrompt::for_edit( false ),
	'block edit'      => SystemPrompt::for_edit( true ),
	'template edit'   => SystemPrompt::for_edit( false, true ),
);
// Since 2026-11-11 the families are cards (Mody\Platform\AI\BlockReference): every prompt lists them all,
// and carries the cards of the families its request names or its markup uses.
foreach ( $prompts as $label => $prompt ) {
	$check( $label . ': every family listed, accordion and tabs among them', str_contains( $prompt, Mody\Platform\AI\BlockReference::index() ) && str_contains( $prompt, '- Accordion (spectra/accordion)' ) && str_contains( $prompt, '- Tabs (spectra/tabs)' ) && str_contains( $prompt, 'and the block families listed below' ) );
}
$check( 'an FAQ request gets the accordion card, not the tabs one', str_contains( SystemPrompt::for_page( 'An FAQ page about our repairs' ), SpectraBlocks::ACCORDION ) && ! str_contains( SystemPrompt::for_page( 'An FAQ page about our repairs' ), SpectraBlocks::TABS ) );
$check( '... in Spanish too', str_contains( SystemPrompt::for_page( 'Una página de preguntas frecuentes' ), SpectraBlocks::ACCORDION ) );
$check( 'an edit of markup with tabs gets the tabs card', str_contains( SystemPrompt::for_edit( true, false, 'Rename the second tab', SpectraBlocks::TABS ), SpectraBlocks::TABS ) );
$check( 'an about page gets no card', ! str_contains( SystemPrompt::for_page( 'An about page for our bakery' ), 'How to write the ones this request needs' ) );
$check( 'the reference holds both examples', str_contains( $reference, SpectraBlocks::ACCORDION ) && str_contains( $reference, SpectraBlocks::TABS ), strlen( $reference ) . ' characters' );
$check( 'one family per example', array( 'spectra/accordion', 'spectra/tabs' ) === SpectraBlocks::FAMILIES );

// 2. The examples ------------------------------------------------------------------------------

echo "\nThe examples\n";
switch_to_blog( $member );
$generated = array(
	'spectra/accordion' => \SpectraBlocks\Abilities\CreateAccordion::instance()->execute( array( 'items' => array( array( 'question' => 'A?', 'answer' => 'Yes.' ), array( 'question' => 'B?', 'answer' => 'No.' ) ) ) ),
	'spectra/tabs'      => \SpectraBlocks\Abilities\CreateTabs::instance()->execute( array( 'tabs' => array( array( 'title' => 'A', 'content' => 'One.' ), array( 'title' => 'B', 'content' => 'Two.' ) ) ) ),
);
$examples  = array(
	'spectra/accordion' => SpectraBlocks::ACCORDION,
	'spectra/tabs'      => SpectraBlocks::TABS,
);
// The example has Spectra's blocks, and no attribute Spectra's generator does not write.
$names = static fn( string $markup ): array => array_column( $blocks( $markup ), 0 );
$extra = static function ( string $example, string $spectra ) use ( $blocks ): array {
	$theirs = array_map( static fn( $b ) => array_keys( $b[1] ), $blocks( $spectra ) );
	$extra  = array();
	foreach ( $blocks( $example ) as $i => $b ) {
		$extra = array_merge( $extra, array_diff( array_keys( $b[1] ), $theirs[ $i ] ?? array() ) );
	}
	return $extra;
};
foreach ( $examples as $family => $example ) {
	$markup = $section( $example );
	$result = $valid( $markup );
	$check( $family . ': valid in a section', 'valid' === $result, $result );
	$check( $family . ': valid as an edit', 'valid' === $valid( $markup, $section( '<!-- wp:paragraph --><p>Before.</p><!-- /wp:paragraph -->' ) ) );
	$spectra = is_array( $generated[ $family ] ) ? (string) $generated[ $family ]['block_markup'] : '';
	$check( $family . ': the blocks Spectra writes, no attribute it does not', $names( $example ) === $names( $spectra ) && ! $extra( $example, $spectra ), implode( ', ', $extra( $example, $spectra ) ) );
	$check( $family . ': saved as WordPress saves it', serialize_blocks( parse_blocks( $example ) ) === $example );
	$check( $family . ': normalize() changes nothing (it is as the editor saves it)', SpectraBlocks::normalize( SpectraLayout::normalize( $markup ) ) === $markup );
}
$html = do_blocks( $section( SpectraBlocks::ACCORDION ) );
$check( 'the accordion renders its questions and answers', str_contains( $html, 'Do I need an appointment?' ) && str_contains( $html, 'Yes, including batteries and motors.' ) && str_contains( $html, 'wp-block-spectra-accordion' ) );
$html = do_blocks( $section( SpectraBlocks::TABS ) );
$check( 'the tabs render their labels and panels', str_contains( $html, 'City bikes' ) && str_contains( $html, 'E-bikes' ) && 2 === substr_count( $html, 'role="tabpanel"' ) && 2 === substr_count( $html, 'role="tab"' ), substr_count( $html, 'role="tabpanel"' ) . ' panels, ' . substr_count( $html, 'role="tab"' ) . ' tabs' );
restore_current_blog();

// 3. The repair of attribute JSON --------------------------------------------------------------

echo "\nThe repair of attribute JSON\n";
$question = static fn( string $json ): string => $section( "<!-- wp:spectra/accordion -->\n<!-- wp:spectra/accordion-child-item -->\n<!-- wp:spectra/accordion-child-header -->\n<!-- wp:spectra/accordion-child-header-content " . $json . " /-->\n<!-- /wp:spectra/accordion-child-header -->\n\n<!-- wp:spectra/accordion-child-details -->\n<!-- wp:paragraph -->\n<p>Yes.</p>\n<!-- /wp:paragraph -->\n<!-- /wp:spectra/accordion-child-details -->\n<!-- /wp:spectra/accordion-child-item -->\n<!-- /wp:spectra/accordion -->" );
$raw      = $question( '{"text":"Bread & cakes: \"fresh\" for kids < 12 -- really?"}' );
$check( 'raw &, quotes, < and -- fail the round trip unrepaired', 'valid' !== $valid( $raw ) );
$repaired = PageGenerator::repair( $raw );
$check( '...repaired, they are written as WordPress saves them', str_contains( $repaired, '{"text":"Bread \u0026 cakes: \u0022fresh\u0022 for kids \u003c 12 \u002d\u002d really?"}' ), $repaired === $raw ? 'unchanged' : '' );
$check( '...and the page passes', 'valid' === $valid( $repaired ), $valid( $repaired ) );
$check( '...the text itself is unchanged', 'Bread & cakes: "fresh" for kids < 12 -- really?' === ( parse_blocks( $repaired )[0]['innerBlocks'][0]['innerBlocks'][0]['innerBlocks'][0]['innerBlocks'][0]['attrs']['text'] ?? '' ) );
$spaced = '<!-- wp:heading {"level": 3,  "textColor": "primary"} --><h3 class="wp-block-heading has-primary-color has-text-color">Hi</h3><!-- /wp:heading -->';
$check( 'spaces in attribute JSON are written compactly', str_contains( PageGenerator::repair( $spaced ), '<!-- wp:heading {"level":3,"textColor":"primary"} -->' ) );
$check( 'markup already as WordPress saves it is unchanged', SystemPrompt::EXAMPLE === PageGenerator::repair( SystemPrompt::EXAMPLE ) && $section( SpectraBlocks::TABS ) === PageGenerator::repair( $section( SpectraBlocks::TABS ) ) );
$broken = $question( '{"text":"Unclosed}' );
$check( 'broken JSON is left to the validator', PageGenerator::repair( $broken ) === $broken && 'valid' !== $valid( $broken ) );
$empty = '<!-- wp:spectra/container {"variationSelected":true,"style":{}} -->' . "\n<!-- /wp:spectra/container -->";
$check( 'an empty object inside is left as it is (PHP would write [])', PageGenerator::repair( $empty ) === $empty );
$check( 'the paragraph repair still runs', str_contains( PageGenerator::repair( "<!-- wp:paragraph -->\n<Packed with seeds.</p>\n<!-- /wp:paragraph -->" ), '<p>Packed with seeds.</p>' ) );

// 4. The validator -----------------------------------------------------------------------------

echo "\nThe validator\n";
switch_to_blog( $member );
$tabs    = static fn( array $labels, array $panels ): string => $section(
	"<!-- wp:spectra/tabs {\"variationSelected\":true,\"currentTab\":0} -->\n<!-- wp:spectra/tabs-child-tab-wrapper -->\n"
	. implode( "\n\n", array_map( static fn( $l ) => '<!-- wp:spectra/tabs-child-tab-button {"text":"' . $l . '"} /-->', $labels ) )
	. "\n<!-- /wp:spectra/tabs-child-tab-wrapper -->\n\n"
	. implode( "\n\n", array_map( static fn( $p ) => "<!-- wp:spectra/tabs-child-tabpanel -->\n<!-- wp:paragraph -->\n<p>" . $p . "</p>\n<!-- /wp:paragraph -->\n<!-- /wp:spectra/tabs-child-tabpanel -->", $panels ) )
	. "\n<!-- /wp:spectra/tabs -->"
);
$result = $valid( $tabs( array( 'A', 'B' ), array( 'One' ) ) );
$check( 'refused: two tab labels and one panel', str_contains( $result, '2 tab label(s) and 1 panel(s)' ), $result );
$result = $valid( $tabs( array(), array( 'One' ) ) );
$check( 'refused: no tab labels', str_contains( $result, '0 tab label(s)' ), $result );
$check( 'accepted: three labels and three panels', 'valid' === $valid( $tabs( array( 'A', 'B', 'C' ), array( 'One', 'Two', 'Three' ) ) ) );
$no_answer = str_replace( "\n\n<!-- wp:spectra/accordion-child-details -->\n<!-- wp:paragraph -->\n<p>Yes.</p>\n<!-- /wp:paragraph -->\n<!-- /wp:spectra/accordion-child-details -->", '', $question( '{"text":"A?"}' ) );
$result    = $valid( $no_answer );
$check( 'refused: an accordion item without its answer', str_contains( $result, 'accordion-child-details (the answer)' ), $result );
$result = $valid( $section( "<!-- wp:spectra/tabs-child-tabpanel -->\n<!-- /wp:spectra/tabs-child-tabpanel -->" ) );
$check( 'refused: a tab panel outside tabs', str_contains( $result, 'goes directly inside spectra/tabs' ), $result );
restore_current_blog();

// 5. normalize() -------------------------------------------------------------------------------

echo "\nnormalize()\n";
$plain = $tabs( array( 'A', 'B', 'C' ), array( 'One', 'Two', 'Three' ) );
$plain = str_replace( '{"variationSelected":true,"currentTab":0}', '{"currentTab":7}', $plain );
$found = $numbers( SpectraBlocks::normalize( $plain ) );
$check( 'tabs numbered by position, labels and panels', array( 0, 1, 2 ) === ( $found[0]['labels'] ?? null ) && array( 0, 1, 2 ) === ( $found[0]['panels'] ?? null ), wp_json_encode( $found ) );
$check( '...the variation set, and an open tab out of range back to 0', true === ( $found[0]['variation'] ?? null ) && 0 === ( $found[0]['open'] ?? null ) );
$inner  = str_replace( '"currentTab":1}', '"currentTab":5}', SpectraBlocks::TABS ); // Numbered wrong.
$nested = str_replace( "<p>Two</p>\n<!-- /wp:paragraph -->", "<p>Two</p>\n<!-- /wp:paragraph -->\n\n" . $inner, $plain );
$found  = $numbers( SpectraBlocks::normalize( $nested ) );
$check( 'tabs inside a tab are numbered on their own', 2 === count( $found ) && array( 0, 1 ) === ( $found[1]['labels'] ?? null ) && array( 0, 1, 2 ) === ( $found[0]['panels'] ?? null ), wp_json_encode( $found ) );
$check( 'markup without tabs is returned as it is', SystemPrompt::EXAMPLE === SpectraBlocks::normalize( SystemPrompt::EXAMPLE ) );
$defaults = str_replace( '<!-- wp:spectra/accordion -->', '<!-- wp:spectra/accordion {"activeAccordion":0} -->', SpectraBlocks::ACCORDION );
$check( 'default values dropped, as the editor does ("activeAccordion":0)', SpectraBlocks::ACCORDION === SpectraBlocks::normalize( $defaults ) );
$check( 'currentTab first on tab buttons, where the editor writes it', str_contains( SpectraBlocks::normalize( str_replace( '{"currentTab":0,"text":"City bikes"}', '{"text":"City bikes"}', SpectraBlocks::TABS ) ), '{"currentTab":0,"text":"City bikes"}' ) );

// 6. Real requests -----------------------------------------------------------------------------

if ( getenv( 'MODY_TEST_OFFLINE' ) ) {
	echo "\nReal requests skipped (MODY_TEST_OFFLINE).\n";
	echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed (offline).\n";
	return;
}
$site_id  = get_main_site_id();
$message  = static fn( $result ): string => is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : '';
$top_ok   = static fn( string $markup ): bool => ! array_filter( parse_blocks( $markup ), static fn( $b ) => null !== $b['blockName'] && SpectraLayout::BLOCK !== $b['blockName'] );
$numbered = static function ( string $markup ) use ( $numbers ): bool {
	foreach ( $numbers( $markup ) as $tabs ) {
		$range = $tabs['panels'] ? range( 0, count( $tabs['panels'] ) - 1 ) : array();
		if ( $tabs['labels'] !== $range || $tabs['panels'] !== $range || true !== $tabs['variation'] ) {
			return false;
		}
	}
	return true;
};
$run = static function ( callable $request ) use ( $message ): array {
	sleep( 4 ); // Infomaniak's rate limit.
	$start  = microtime( true );
	$result = $request();
	return array( $result, round( microtime( true ) - $start, 1 ) . ' s', $message( $result ) );
};

echo "\nReal requests (main site)\n";
list( $faq, $secs, $error ) = $run( static fn() => PageGenerator::generate_page( $site_id, 'A FAQ page: the questions customers ask us about delivery, payment, allergies and custom orders.' ) );
$check( 'an FAQ page: an accordion, one item per question', ! $error && $top_ok( $faq ) && $count( $faq, 'spectra/accordion' ) >= 1 && $count( $faq, 'spectra/accordion-child-item' ) >= 4, $error ? $error : $secs . ', ' . $tally( $faq ) );

list( $menu, $secs, $error ) = $run( static fn() => PageGenerator::generate_page( $site_id, 'Our menu page: breakfast, lunch and dinner, each in its own tab.' ) );
$check( 'a menu in tabs: three labels, three panels, numbered', ! $error && $top_ok( $menu ) && 3 === $count( $menu, 'spectra/tabs-child-tab-button' ) && 3 === $count( $menu, 'spectra/tabs-child-tabpanel' ) && $numbered( $menu ), $error ? $error : $secs . ', ' . $tally( $menu ) );

list( $about, $secs, $error ) = $run( static fn() => PageGenerator::generate_page( $site_id, 'An about page: our story, our values and our team.' ) );
$check( 'an about page: neither family', ! $error && $top_ok( $about ) && 0 === $count( $about, 'spectra/accordion' ) + $count( $about, 'spectra/tabs' ), $error ? $error : $secs . ', ' . $tally( $about ) );

$original = SystemPrompt::EXAMPLE;
list( $edited, $secs, $error ) = $run( static fn() => PageGenerator::edit_markup( $site_id, $original, 'Add a section with four frequently asked questions before the contact section.', false ) );
$kept = ! $error && str_contains( $edited, 'Bikes repaired while you work' ) && str_contains( $edited, 'Call us at [phone number]' );
$check( 'an edit: an FAQ section added, the rest kept', $kept && $top_ok( $edited ) && count( array_filter( parse_blocks( $edited ), static fn( $b ) => null !== $b['blockName'] ) ) === 5 && 4 === $count( $edited, 'spectra/accordion-child-item' ), $error ? $error : $secs . ', ' . $tally( $edited ) );

$selected = SpectraBlocks::ACCORDION;
list( $more, $secs, $error ) = $run( static fn() => PageGenerator::edit_markup( $site_id, $selected, 'Add a question about how to pay.', true ) );
$check( 'the selected accordion: one question more', ! $error && 1 === $count( $more, 'spectra/accordion' ) && 3 === $count( $more, 'spectra/accordion-child-item' ) && str_contains( $more, 'Do I need an appointment?' ), $error ? $error : $secs . ', ' . $tally( $more ) );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
