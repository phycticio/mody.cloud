<?php
/**
 * Tests the writers' block reference and the validator's attribute check
 * (tickets/agent-knows-gutenberg-spectra-settings.md, part 4). No model call:
 * the retry is checked with fake answers.
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/block-reference-test.php
 *
 * 1. The cards: each loads complete, its blocks are registered and allowed,
 *    its example is valid in a section and renders, it has words for the
 *    eight languages' requests; the index lists every family.
 * 2. Picking cards: by the request's words (any language), by the markup
 *    being edited; none for an ordinary page.
 * 3. The attribute check: an invented attribute (with the real ones named),
 *    an attribute core keeps in the HTML, a wrong type or value, an older
 *    form refused; `spectraId` and a member's unchanged block accepted.
 * 4. The retry: an answer that uses a family without its card is rejected,
 *    and the next request carries that card.
 */

use Mody\Platform\AI\BlockReference;
use Mody\Platform\AI\BlockValidator;
use Mody\Platform\AI\PageGenerator;
use Mody\Platform\AI\SpectraLayout;
use Mody\Platform\AI\SystemPrompt;

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-64s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};
$section = static fn( string $inner ): string => '<!-- wp:spectra/container ' . SpectraLayout::SECTION . " -->\n" . $inner . "\n<!-- /wp:spectra/container -->";
$message = static fn( $result ): string => true === $result ? 'valid' : $result->get_error_message();

// 1. The cards ------------------------------------------------------------------------

echo "The cards\n";
$cards    = BlockReference::cards();
$registry = WP_Block_Type_Registry::get_instance();
$allowed  = array_merge( BlockValidator::WHITELIST, BlockValidator::SPECTRA_BLOCKS, BlockValidator::POST_ITEM_BLOCKS );
$check( count( $cards ) . ' families', count( $cards ) >= 2, implode( ', ', array_keys( $cards ) ) );
foreach ( $cards as $family => $card ) {
	$missing = array_diff( array( 'family', 'label', 'top', 'blocks', 'when', 'keywords', 'rules', 'example' ), array_keys( $card ) );
	$blocks  = (array) $card['blocks'];
	$unknown = array_filter( $blocks, static fn( string $name ): bool => ! $registry->is_registered( $name ) || ! in_array( $name, $allowed, true ) );
	$result  = BlockValidator::validate( $section( (string) $card['example'] ) );
	$html    = do_blocks( $section( (string) $card['example'] ) );
	$check( "$family: complete, its blocks registered and allowed", ! $missing && ! $unknown && in_array( $card['top'], $blocks, true ), implode( ', ', array_merge( $missing, $unknown ) ) );
	$check( "$family: the example is valid in a section", true === $result, $message( $result ) );
	$check( "$family: ... and renders", '' !== trim( wp_strip_all_tags( $html ) ) || str_contains( $html, '<svg' ), strlen( $html ) . ' bytes' );
	$check( "$family: words to pick it by, in several languages", count( (array) $card['keywords'] ) >= 8 );
}
$index = BlockReference::index();
$check( 'the index: one line per family', count( explode( "\n", $index ) ) === count( $cards ), strlen( $index ) . ' characters' );

// 2. Picking cards --------------------------------------------------------------------------

echo "\nPicking cards\n";
$check( 'by the request: an FAQ', array( 'accordion' ) === BlockReference::select( 'Add an FAQ about delivery' ) );
$check( '... in German and Catalan', in_array( 'accordion', BlockReference::select( 'Häufige Fragen zur Lieferung' ), true ) && in_array( 'accordion', BlockReference::select( 'Preguntes freqüents' ), true ) );
$check( 'by the markup being edited', in_array( 'tabs', BlockReference::select( 'Rename the second one', $card = (string) $cards['tabs']['example'] ), true ) );
$check( 'none for an ordinary page', array() === BlockReference::select( 'An about page for our bakery, with our story' ) );
$check( '... and its prompt has the index, but no card', str_contains( SystemPrompt::for_page( 'An about page' ), $index ) && ! str_contains( SystemPrompt::for_page( 'An about page' ), 'How to write the ones this request needs' ) );

// 3. The attribute check --------------------------------------------------------------------

echo "\nThe attribute check\n";
$paragraph = static fn( string $json ): string => $section( '<!-- wp:paragraph ' . $json . " -->\n<p>Hello.</p>\n<!-- /wp:paragraph -->" );
$result    = BlockValidator::validate( $paragraph( '{"fontWeight":"bold"}' ) );
$check( 'an invented attribute: refused, with the real ones named', true !== $result && str_contains( $message( $result ), 'no attribute "fontWeight"' ) && str_contains( $message( $result ), 'textColor' ), $message( $result ) );
$result = BlockValidator::validate( $paragraph( '{"content":"Hello."}' ) );
$check( 'an attribute core keeps in the HTML: refused', true !== $result && str_contains( $message( $result ), 'HTML content' ), $message( $result ) );
$result = BlockValidator::validate( $section( "<!-- wp:heading {\"level\":\"two\"} -->\n<h2 class=\"wp-block-heading\">Hi</h2>\n<!-- /wp:heading -->" ) );
$check( 'a wrong type: refused', true !== $result && str_contains( $message( $result ), 'level' ), $message( $result ) );
$result = BlockValidator::validate( $section( "<!-- wp:heading {\"textAlign\":\"center\"} -->\n<h2 class=\"wp-block-heading has-text-align-center\">Hi</h2>\n<!-- /wp:heading -->" ) );
$check( 'an older form (a heading\'s textAlign): refused', true !== $result, $message( $result ) );
$accordion = (string) $cards['accordion']['example'];
$result    = BlockValidator::validate( $section( str_replace( '{"openByDefault":true}', '{"openByDefault":"yes"}', $accordion ) ) );
$check( 'a Spectra attribute of the wrong type: refused', true !== $result, $message( $result ) );
$result = BlockValidator::validate( $section( str_replace( '<!-- wp:spectra/accordion -->', '<!-- wp:spectra/accordion {"spectraId":"abc123"} -->', $accordion ) ) );
$check( 'spectraId on a Spectra block: accepted', true === $result, $message( $result ) );
$old    = $section( "<!-- wp:heading {\"textAlign\":\"center\"} -->\n<h2 class=\"wp-block-heading has-text-align-center\">Hi</h2>\n<!-- /wp:heading -->" );
$result = BlockValidator::validate( $old, $old );
$check( 'a member\'s unchanged block with an older form: accepted in an edit', true === $result, $message( $result ) );

// 4. The retry ---------------------------------------------------------------------------------

echo "\nThe retry\n";
$requests = array();
$fakes    = array();
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) use ( &$requests, &$fakes ) {
		if ( ! str_ends_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '/chat/completions' ) ) {
			return $preempt;
		}
		$requests[] = json_decode( (string) $args['body'], true );
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'id'      => 'fake',
					'object'  => 'chat.completion',
					'created' => time(),
					'model'   => 'fake',
					'choices' => array(
						array(
							'index'         => 0,
							'message'       => array(
								'role'    => 'assistant',
								'content' => (string) array_shift( $fakes ),
							),
							'finish_reason' => 'stop',
						),
					),
					'usage'   => array(
						'prompt_tokens'     => 1,
						'completion_tokens' => 1,
						'total_tokens'      => 2,
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
$tabs     = (string) $cards['tabs']['example'];
$h1       = "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Our bikes</h1>\n<!-- /wp:heading -->\n\n";
$guessed  = $section( $h1 . str_replace( '{"currentTab":0,"text":"City bikes"}', '{"currentTab":0,"label":"City bikes"}', $tabs ) );
$right    = $section( $h1 . $tabs );
$fakes    = array( $guessed, $right );
$result   = PageGenerator::generate_page( get_current_blog_id(), 'A page about our bikes' );
$first    = (string) wp_json_encode( $requests[0]['messages'] ?? array() );
$content  = ( $requests[1]['messages'] ?? array() ) ? end( $requests[1]['messages'] )['content'] : '';
$second   = is_array( $content ) ? implode( "\n", array_column( $content, 'text' ) ) : (string) $content;
$check( 'no tabs card in the first request (the words do not name them)', 2 === count( $requests ) && ! str_contains( $first, 'How to write the ones this request needs' ) );
$check( 'the guess is rejected: "label" is not an attribute of a tab button', str_contains( $second, 'no attribute "label"' ), mb_strimwidth( $second, 0, 120, '…' ) );
$check( '... and the retry carries the tabs card', str_contains( $second, 'The reference for the blocks you used' ) && str_contains( $second, $tabs ) );
$check( 'the second answer is taken', ! is_wp_error( $result ) && str_contains( $result, 'spectra/tabs' ), is_wp_error( $result ) ? $result->get_error_message() : '' );

// 5. The writer, for real ---------------------------------------------------------------------

remove_all_filters( 'pre_http_request' );
if ( getenv( 'MODY_TEST_OFFLINE' ) ) {
	echo "\nThe writer: real requests skipped (MODY_TEST_OFFLINE is set)\n";
} else {
	echo "\nThe writer, for real (Qwen on Infomaniak; each must use the family and pass the validator)\n";
	$cases = array(
		'Our opening hours page: Monday to Friday 9:00 to 18:00, Saturday 10:00 to 14:00, closed on Sunday. Show the hours in a table.' => 'wp:table',
		'A page announcing our new shop, with a countdown to the opening on 1 March 2027 at 10:00 and a button to get directions.' => 'wp:spectra/countdown',
		'A services page: three services, each with an icon and a short text, then a checklist of what is included.' => 'wp:spectra/list',
		'A page with what our customers say, three short testimonials in a slider.' => 'wp:spectra/slider',
		'A page with our numbers: 1200 bikes repaired, 15 years in business, 98 percent happy customers, shown as counters.' => 'wp:spectra/counter',
		'Our blog page: a short intro and a grid of our latest posts.' => 'wp:spectra/post',
		'A page with our terms, and a button that opens the full terms in a popup.' => 'wp:spectra/modal',
		'A gallery page with photos of our workshop.' => 'wp:gallery',
	);
	foreach ( $cases as $request => $needle ) {
		$started = microtime( true );
		$out     = PageGenerator::generate_page( get_current_blog_id(), $request );
		$valid   = ! is_wp_error( $out ) && true === BlockValidator::validate( $out );
		$used    = ! is_wp_error( $out ) && str_contains( $out, '<!-- ' . $needle );
		$check( mb_strimwidth( $request, 0, 60, '…' ), $valid && $used, ( is_wp_error( $out ) ? $out->get_error_message() . ' ' . wp_json_encode( $out->get_error_data()['reason'] ?? '' ) : ( $used ? 'used ' . $needle : 'did not use ' . $needle ) ) . ', ' . round( microtime( true ) - $started, 1 ) . ' s' );
		sleep( 3 ); // Infomaniak's rate limit is shared with production.
	}
}

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
