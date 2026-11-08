<?php
/**
 * Tests the layout of generated content: Spectra containers (SpectraLayout, 2026-11-07).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/spectra-layout-test.php
 *
 * 1. The recipe and the few-shot example: every section, row and column is a
 *    container with exactly the recipe's attributes; no group, columns or column.
 * 2. The validator: a container saves no HTML; a new page refuses group,
 *    columns, column and container attributes outside the recipe, while edits
 *    and templates keep them; media and colors are checked in containers too.
 * 3. normalize() and with_ids(): what Spectra's editor sets on load.
 * 4. Rendered on a member site: sections full width with constrained content,
 *    rows of 3 columns that become 2 on tablets and 1 on phones, and the
 *    section padding smaller on phones.
 * 5. Real generations and one real edit on the main site (it has a brand
 *    profile). Add `-e MODY_TEST_OFFLINE=1` after `docker exec` to skip them.
 *
 * Everything created is removed.
 */

use Mody\Platform\AI\BlockValidator;
use Mody\Platform\AI\PageGenerator;
use Mody\Platform\AI\SpectraLayout;
use Mody\Platform\AI\SystemPrompt;
use Mody\Platform\Brand\BrandProfile;
use Mody\Platform\Provisioning\Pipeline;

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-50s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};
$message  = static fn( $result ): string => is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '';
$admins   = get_super_admins();
$admin    = get_user_by( 'login', $admins[0] )->ID;
wp_set_current_user( $admin );

// Every container of some markup, with its depth and the names of its inner blocks.
$containers = static function ( string $markup ): array {
	$found = array();
	$walk  = static function ( array $blocks, int $depth, ?array $parent ) use ( &$walk, &$found ): void {
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}
			if ( SpectraLayout::BLOCK === $block['blockName'] ) {
				$found[] = array(
					'depth'  => $depth,
					'parent' => $parent,
					'attrs'  => $block['attrs'],
					'inner'  => array_values( array_filter( array_column( $block['innerBlocks'], 'blockName' ) ) ),
				);
			}
			$walk( $block['innerBlocks'], $depth + 1, $block );
		}
	};
	$walk( parse_blocks( $markup ), 0, null );
	return $found;
};
$recipe = array(
	'section'    => json_decode( SpectraLayout::SECTION, true ),
	'row'        => json_decode( SpectraLayout::ROW, true ),
	'row_of_two' => json_decode( SpectraLayout::ROW_OF_TWO, true ),
	'column'     => json_decode( SpectraLayout::COLUMN, true ),
);
// Which recipe shape a container is, colors aside; null when it is none.
$shape = static function ( array $attrs ) use ( $recipe ): ?string {
	unset( $attrs['backgroundColor'], $attrs['textColor'], $attrs['spectraId'] );
	foreach ( $recipe as $name => $expected ) {
		if ( $expected === $attrs ) {
			return $name;
		}
		if ( in_array( $name, array( 'row', 'row_of_two' ), true ) && isset( $attrs['layout']['columnCount'] ) ) {
			$expected['layout']['columnCount'] = $attrs['layout']['columnCount'];
			if ( $expected === $attrs && ( 'row' === $name ? $attrs['layout']['columnCount'] >= 3 : 2 === $attrs['layout']['columnCount'] ) ) {
				return $name;
			}
		}
	}
	return null;
};
// The layout check of a whole page: sections at the top, rows of columns, no core layout blocks.
$layout_problems = static function ( string $markup ) use ( $containers, $shape ): array {
	$problems = array();
	foreach ( parse_blocks( $markup ) as $block ) {
		if ( null !== $block['blockName'] && SpectraLayout::BLOCK !== $block['blockName'] ) {
			$problems[] = 'top-level ' . $block['blockName'];
		}
	}
	foreach ( $containers( $markup ) as $box ) {
		$kind = $shape( $box['attrs'] );
		if ( null === $kind ) {
			$problems[] = 'container off the recipe: ' . wp_json_encode( $box['attrs'] );
		} elseif ( ( 'section' === $kind ) !== ( 0 === $box['depth'] ) ) {
			$problems[] = $kind . ' at depth ' . $box['depth'];
		} elseif ( str_starts_with( $kind, 'row' ) && array_diff( $box['inner'], array( SpectraLayout::BLOCK ) ) ) {
			$problems[] = 'a row holding ' . implode( ', ', array_diff( $box['inner'], array( SpectraLayout::BLOCK ) ) );
		} elseif ( 'column' === $kind && ( SpectraLayout::BLOCK !== ( $box['parent']['blockName'] ?? '' ) || ! str_starts_with( (string) $shape( (array) $box['parent']['attrs'] ), 'row' ) ) ) {
			$problems[] = 'a column outside a row';
		}
	}
	if ( preg_match( '/<!-- wp:(group|columns|column)[ \/-]/', $markup, $m ) ) {
		$problems[] = 'core/' . $m[1];
	}
	return $problems;
};

// 1. The recipe and the example ------------------------------------------------------------

echo "Recipe and example\n";
$check( 'the recipe strings are valid JSON objects', ! in_array( null, $recipe, true ) );
$check( 'the example: sections, rows of columns, nothing else', ! $layout_problems( SystemPrompt::EXAMPLE ), implode( '; ', $layout_problems( SystemPrompt::EXAMPLE ) ) );
// "4 section, 2 row, ..." for the output.
$tally = static function ( string $markup ) use ( $containers, $shape ): string {
	$kinds = array_count_values( array_map( static fn( $box ) => (string) $shape( $box['attrs'] ), $containers( $markup ) ) );
	return implode( ', ', array_map( static fn( $kind, $n ) => "$n " . ( '' === $kind ? 'off-recipe' : $kind ), array_keys( $kinds ), $kinds ) );
};
$kinds = array_map( static fn( $box ) => $shape( $box['attrs'] ), $containers( SystemPrompt::EXAMPLE ) );
$check( '...it shows every shape', ! array_diff( array_keys( $recipe ), $kinds ), $tally( SystemPrompt::EXAMPLE ) );
$check( '...and passes the validator', true === BlockValidator::validate( SystemPrompt::EXAMPLE ) );
$main_profile = BrandProfile::for_site( get_main_site_id() );
if ( $main_profile ) {
	$check( 'the page and edit prompts carry the layout rules', str_contains( SystemPrompt::for_page( $main_profile ), SpectraLayout::rules() ) && str_contains( SystemPrompt::for_edit( $main_profile, false ), SpectraLayout::rules() ) && str_contains( SystemPrompt::for_edit( $main_profile, true, true ), SpectraLayout::rules() ) );
}
$fallback = Pipeline::fallback_markup( 'Ogia Bakery', 'We bake bread.' );
$check( 'the fallback page is one section', true === BlockValidator::validate( $fallback ) && ! $layout_problems( $fallback ), implode( '; ', $layout_problems( $fallback ) ) );

// 2. The validator ---------------------------------------------------------------------------

echo "\nValidator\n";
$section = static fn( string $inner, string $attrs = SpectraLayout::SECTION ): string => "<!-- wp:spectra/container $attrs -->\n$inner\n<!-- /wp:spectra/container -->";
$para    = "<!-- wp:paragraph -->\n<p>Text.</p>\n<!-- /wp:paragraph -->";
$group   = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\">$para</div>\n<!-- /wp:group -->";
$refused = static function ( string $markup, string $needle, string $original = '', bool $template = false ): bool {
	$result = BlockValidator::validate( $markup, $original, $template );
	return is_wp_error( $result ) && str_contains( $result->get_error_message(), $needle );
};
$check( 'a container with HTML of its own is refused', $refused( "<!-- wp:spectra/container {\"variationSelected\":true} -->\n<div class=\"wp-block-spectra-container\">$para</div>\n<!-- /wp:spectra/container -->", 'saves no HTML' ) );
$check( 'a new page with a group is refused', $refused( $group, 'Do not use the "core/group"' ) );
$columns = "<!-- wp:columns -->\n<div class=\"wp-block-columns\"><!-- wp:column -->\n<div class=\"wp-block-column\">$para</div>\n<!-- /wp:column --></div>\n<!-- /wp:columns -->";
$check( '...and columns', $refused( $section( $columns ), 'core/columns' ) );
$changed = str_replace( 'Text.', 'Changed.', $group );
$check( 'an edit may change an existing group', true === BlockValidator::validate( $changed, $group ) );
$check( 'a template may have a group', true === BlockValidator::validate( $group, '', true ) );
$extra = str_replace( '"isBlockRootParent":true,', '"isBlockRootParent":true,"boxShadow":"0 0 4px currentColor",', SpectraLayout::SECTION );
$check( 'a new page: an attribute off the recipe is refused', $refused( $section( $para, $extra ), '"boxShadow" is not part of the layout rules' ) );
$check( '...kept when the member\'s own container is unchanged', true === BlockValidator::validate( $section( $para, $extra ) . "\n\n" . $section( $para ), $section( $para, $extra ) ) );
$check( 'spacing must be a preset', $refused( $section( $para, str_replace( '"top":"var:preset|spacing|70"', '"top":"20px"', SpectraLayout::SECTION ) ), 'spacing preset' ) );
$check( '...one of the theme\'s', $refused( $section( $para, str_replace( 'spacing|70', 'spacing|99', SpectraLayout::SECTION ) ), 'spacing preset' ) );
$check( 'a row has at most 4 columns', $refused( $section( $section( $para, str_replace( '"columnCount":3', '"columnCount":6', SpectraLayout::ROW ) ) ), '1 to 4 columns' ) );
$check( 'layout types: constrained, grid, flex', $refused( $section( $para, str_replace( '"constrained"', '"flow"', SpectraLayout::SECTION ) ), 'layout type' ) );
$check( 'a loose background color is refused', $refused( $section( $para, str_replace( '"isBlockRootParent":true,', '"isBlockRootParent":true,"backgroundColor":"#ff0000",', SpectraLayout::SECTION ) ), 'loose color' ) );
$check( '...and an unknown slug', $refused( $section( $para, str_replace( '"isBlockRootParent":true,', '"isBlockRootParent":true,"backgroundColor":"vivid-red",', SpectraLayout::SECTION ) ), 'not a palette slug' ) );
$outside = $section( $para, '{"variationSelected":true,"background":{"type":"image","media":{"url":"https://images.example.com/a.jpg"}}}' );
$check( 'background media from outside is refused, in edits too', $refused( $outside, 'external sites', $section( $para ) ) );

// 3. normalize() and with_ids() ------------------------------------------------------------------

echo "\nNormalize\n";
$bare       = "<!-- wp:spectra/container {\"htmlTag\":\"section\"} -->\n<!-- wp:spectra/container {\"align\":\"wide\",\"isBlockRootParent\":true} -->\n$para\n<!-- /wp:spectra/container -->\n<!-- /wp:spectra/container -->";
$normalized = SpectraLayout::normalize( $bare );
$boxes      = $containers( $normalized );
$check( 'every container: variationSelected', 2 === count( array_filter( $boxes, static fn( $b ) => true === ( $b['attrs']['variationSelected'] ?? null ) ) ) );
$check( 'the root: isBlockRootParent, align full', true === ( $boxes[0]['attrs']['isBlockRootParent'] ?? null ) && 'full' === ( $boxes[0]['attrs']['align'] ?? null ) );
$check( 'a nested one: neither', ! isset( $boxes[1]['attrs']['isBlockRootParent'] ) && ! isset( $boxes[1]['attrs']['align'] ) );
$check( 'the same twice; the example is already normalized', SpectraLayout::normalize( $normalized ) === $normalized && SpectraLayout::normalize( SystemPrompt::EXAMPLE ) === SystemPrompt::EXAMPLE );
$in_group = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\"><!-- wp:spectra/container {\"variationSelected\":true} -->\n$para\n<!-- /wp:spectra/container --></div>\n<!-- /wp:group -->";
$check( 'under a core block (a template part), still a root', true === ( $containers( SpectraLayout::normalize( $in_group ) )[0]['attrs']['isBlockRootParent'] ?? null ) );
$fragment = $containers( SpectraLayout::normalize( $bare, true ) );
$check( 'a fragment: only variationSelected', true === $fragment[0]['attrs']['variationSelected'] && ! isset( $fragment[0]['attrs']['align'] ) && true === $fragment[1]['attrs']['isBlockRootParent'] );
$with_ids = SpectraLayout::with_ids( SystemPrompt::EXAMPLE );
$ids      = array_map( static fn( $b ) => (string) ( $b['attrs']['spectraId'] ?? '' ), $containers( $with_ids ) );
$check( 'with_ids: one ID per container, unique, Spectra\'s format', count( $ids ) === count( array_unique( $ids ) ) && ! array_filter( $ids, static fn( $id ) => ! preg_match( '/^spectra-[0-9a-z]+-[0-9a-z]{6}$/', $id ) ), count( $ids ) . ' IDs' );
$check( '...kept on a second pass, and still valid', SpectraLayout::with_ids( $with_ids ) === $with_ids && true === BlockValidator::validate( $with_ids ) );

// 4. Rendered on a member site ----------------------------------------------------------------------

$others = get_sites(
	array(
		'site__not_in' => array( get_main_site_id() ),
		'fields'       => 'ids',
		'number'       => 1,
	)
);
if ( $others ) {
	$site = (int) $others[0];
	echo "\nRendered (member site $site)\n";
	switch_to_blog( $site );
	$page_id = PageGenerator::insert_page( SystemPrompt::EXAMPLE, array( 'post_status' => 'publish' ) );
	$path    = is_wp_error( $page_id ) ? '' : (string) wp_parse_url( get_permalink( $page_id ), PHP_URL_PATH );
	$saved   = is_wp_error( $page_id ) ? '' : get_post( $page_id )->post_content;
	restore_current_blog();
	register_shutdown_function(
		static function () use ( $site, $page_id ): void {
			if ( ! is_wp_error( $page_id ) ) {
				switch_to_blog( $site );
				wp_delete_post( $page_id, true );
				restore_current_blog();
			}
		}
	);
	$check( 'insert_page: the example with an ID per container', '' !== $saved && preg_replace( '/,"spectraId":"spectra-[0-9a-z-]+"/', '', $saved ) === SystemPrompt::EXAMPLE && substr_count( $saved, '"spectraId"' ) === count( $containers( SystemPrompt::EXAMPLE ) ) );
	$response = wp_remote_get(
		'https://' . get_site( get_main_site_id() )->domain . $path,
		array(
			'headers'   => array( 'Host' => get_site( $site )->domain ),
			'sslverify' => false,
			'timeout'   => 30,
		)
	);
	$html = (string) wp_remote_retrieve_body( $response );
	preg_match_all( '/<section\b[^>]*class="([^"]*wp-block-spectra-container[^"]*)"/', $html, $sections );
	$whole = array_filter( $sections[1], static fn( $c ) => str_contains( $c, 'alignfull' ) && str_contains( $c, 'spectra-is-root-container' ) && str_contains( $c, 'is-layout-constrained' ) );
	$check( 'four sections: full width, content constrained', 200 === (int) wp_remote_retrieve_response_code( $response ) && 4 === count( $sections[1] ) && 4 === count( $whole ), wp_remote_retrieve_response_code( $response ) . ', ' . count( $sections[1] ) . ' sections' );
	$check( '...the hero in the palette colors', (bool) preg_match( '/<section\b[^>]*class="[^"]*has-base-color[^"]*"[^>]*--spectra-background-color: var\(--wp--preset--color--primary\)/', $html ) );
	preg_match( '/data-spectra-id="([^"]+)"[^>]*class="[^"]*is-layout-grid/', $html, $grid );
	// Within one media block: whole rules, then the rule for this container.
	$css  = static fn( string $media, string $id ) => (bool) preg_match( '/@media \(' . preg_quote( $media, '/' ) . '\)\s*\{(?:[^{}]*\{[^{}]*\})*?[^{}]*' . preg_quote( $id, '/' ) . '[^{}]*\{[^}]*grid-template-columns:repeat\((\d)/', $html, $m ) ? (int) $m[1] : 0;
	$cols = static fn( int $n, string $id ) => (bool) preg_match( '/' . preg_quote( $id, '/' ) . '\'\]\)\{grid-template-columns:repeat\(' . $n . ',/', $html );
	$id   = $grid[1] ?? 'none';
	$check( 'the row: 3 columns, 2 on tablets, 1 on phones', $cols( 3, $id ) && 2 === $css( '480px < width <= 782px', $id ) && 1 === $css( 'width <= 480px', $id ) );
	$check( 'section padding: 70 and 40, then 50 and 30 on phones', (bool) preg_match( '/padding-top:var\(--wp--preset--spacing--70\);padding-right:var\(--wp--preset--spacing--40\)/', $html ) && (bool) preg_match( '/@media \(width <= 480px\)\s*\{[^@]*padding-top:var\(--wp--preset--spacing--50\);padding-right:var\(--wp--preset--spacing--30\)/', $html ) );
} else {
	echo "\nNo member site: section 4 skipped.\n";
}

// 5. Real runs ---------------------------------------------------------------------------------------

if ( getenv( 'MODY_TEST_OFFLINE' ) ) {
	echo "\nReal runs skipped (MODY_TEST_OFFLINE).\n";
	echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed (offline).\n";
	return;
}
$site_id = get_main_site_id();
if ( null === BrandProfile::for_site( $site_id ) ) {
	echo "\nThe main site has no brand profile: real runs skipped.\n";
	echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
	return;
}
echo "\nReal generations (main site)\n";
$requests = 0;
add_action(
	'http_api_debug',
	static function ( $response, $context, $class, $args, $url ) use ( &$requests ): void {
		if ( str_ends_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '/chat/completions' ) ) {
			++$requests;
		}
	},
	10,
	5
);
$prompts = array(
	'A home page for our bakery: what we bake, how delivery works, and how to order.',
	'A services page with our three services and a short FAQ.',
	'An about page: our story, our values and our team.',
);
$last = '';
foreach ( $prompts as $i => $prompt ) {
	$requests = 0;
	$start    = microtime( true );
	$markup   = PageGenerator::generate_page( $site_id, $prompt );
	$seconds  = round( microtime( true ) - $start, 1 );
	$problems = is_wp_error( $markup ) ? array( $message( $markup ) ) : $layout_problems( $markup );
	$check( 'page ' . ( $i + 1 ) . ': valid, the layout as the rules say', ! $problems, $problems ? implode( '; ', array_slice( $problems, 0, 2 ) ) : sprintf( '%s s, %d request(s): %s', $seconds, $requests, $tally( $markup ) ) );
	$last = is_wp_error( $markup ) ? $last : $markup;
}

echo "\nA real edit (whole content)\n";
$original = '' !== $last ? $last : SystemPrompt::EXAMPLE;
$start    = microtime( true );
$edited   = PageGenerator::edit_markup( $site_id, $original, 'Add a section before the last one with three short tips for our customers, side by side.', false );
$top      = static fn( string $markup ): int => count( array_filter( array_column( parse_blocks( $markup ), 'blockName' ) ) );
$rows     = is_wp_error( $edited ) ? array() : array_filter( $containers( $edited ), static fn( $b ) => 'row' === $shape( $b['attrs'] ) && 3 === ( $b['attrs']['layout']['columnCount'] ?? 0 ) );
$check( 'a new section with a row of three', ! is_wp_error( $edited ) && $top( $edited ) === $top( $original ) + 1 && $rows && ! $layout_problems( $edited ), is_wp_error( $edited ) ? $message( $edited ) : round( microtime( true ) - $start, 1 ) . ' s, ' . $tally( $edited ) . ( $layout_problems( $edited ) ? '; ' . implode( '; ', array_slice( $layout_problems( $edited ), 0, 2 ) ) : '' ) );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
