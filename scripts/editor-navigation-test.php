<?php
/**
 * Tests the agent's Site Editor screens (ticket M3-09, parts A and C, offline).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/editor-navigation-test.php
 *
 * 1. mody/open-editor's destinations: every Styles section, one block type's
 *    styles, templates, template parts, lists, navigation, patterns and the
 *    start screen. Each opens in place (`navigate`) and names what opens.
 * 2. The lists (decided 2026-11-08): what is registered decides them, and
 *    WordPress's template hierarchy decides the template. Checked with a post
 *    type and a taxonomy registered for the test, a template customized in
 *    the Site Editor, and a static front page with a posts page.
 * 3. Where the owner is: the bubble's `screen`, checked and named.
 * 4. The prompt: the targets, rule 9, the site's lists.
 * 5. Permissions.
 * 6. Real requests (part C): eleven phrases on the Site Editor's start
 *    screen, each checked for the screen the agent opens (or the question
 *    it asks); then pages: one opened by a partial title, two that match
 *    (asks, then opens the one picked), none (asks), and a new page, which
 *    opens directly. Add `-e MODY_TEST_OFFLINE=1` after `docker exec` to skip them.
 *
 * Everything the test adds is removed. Sections 1 to 5 make no model call.
 */

use Mody\Platform\AI\SystemPrompt;
use Mody\Platform\Brand\BrandSettings;
use Mody\Platform\SiteEditor;
use Mody\Platform\Agent\Agent;

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-60s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

$admins = get_super_admins();
wp_set_current_user( get_user_by( 'login', $admins[0] )->ID );
$open    = wp_get_ability( 'mody/open-editor' );
$run     = static fn( array $input ) => $open->execute( $input );
$theme   = get_stylesheet();
$query   = static function ( $result ): array {
	$args = array();
	if ( is_array( $result ) ) {
		parse_str( (string) wp_parse_url( $result['url'], PHP_URL_QUERY ), $args );
	}
	return $args;
};
$code    = static fn( $result ): string => is_wp_error( $result ) ? $result->get_error_code() : 'ok';
$cleanup = array();
register_shutdown_function(
	static function () use ( &$cleanup ): void {
		foreach ( array_reverse( $cleanup ) as $undo ) {
			$undo();
		}
	}
);

// 1. Destinations -------------------------------------------------------------------

echo "Destinations\n";
$sections = array();
foreach ( array_keys( SiteEditor::SECTIONS ) as $section ) {
	$result = $run(
		array(
			'target'  => 'styles',
			'section' => $section,
		)
	);
	$args   = $query( $result );
	$want   = SiteEditor::SECTIONS[ $section ];
	$ok     = is_array( $result ) && '/styles' === ( $args['p'] ?? '' ) && true === $result['navigate'] && '' !== $result['place']
		&& ( 'stylebook' === $section ? 'stylebook' === ( $args['preview'] ?? '' ) : ( '/' === $want ? ! isset( $args['section'] ) : $want === ( $args['section'] ?? '' ) ) );
	if ( ! $ok ) {
		$sections[] = $section;
	}
}
$check( 'styles: all ' . count( SiteEditor::SECTIONS ) . ' sections, in place, named', ! $sections, implode( ', ', $sections ) );
$result = $run(
	array(
		'target'  => 'styles',
		'section' => 'colors',
	)
);
$check( '...colors: Styles → Colors', is_array( $result ) && 'Styles → Colors' === $result['place'] && str_contains( $result['url'], 'p=%2Fstyles&section=%2Fcolors' ), is_array( $result ) ? $result['url'] : $code( $result ) );
$result = $run(
	array(
		'target' => 'styles',
		'block'  => 'core/button',
	)
);
$check( 'one block type: encoded as the Site Editor does', is_array( $result ) && str_contains( $result['url'], 'section=%2Fblocks%2Fcore%252Fbutton' ) && str_ends_with( $result['place'], '→ Button' ), is_array( $result ) ? $result['place'] : $code( $result ) );
$check( '...an unknown block refused', 'mody_block_not_found' === $code( $run( array( 'target' => 'styles', 'block' => 'core/nope' ) ) ) );

$result = $run(
	array(
		'target'   => 'part',
		'template' => 'header',
	)
);
$check( 'part: the header, in the canvas', is_array( $result ) && '/wp_template_part/' . $theme . '//header' === ( $query( $result )['p'] ?? '' ) && 'edit' === ( $query( $result )['canvas'] ?? '' ), is_array( $result ) ? $result['place'] : $code( $result ) );
$result = $run( array( 'target' => 'part' ) );
$check( '...no slug: the parts list', is_array( $result ) && '/pattern' === ( $query( $result )['p'] ?? '' ) && 'wp_template_part' === ( $query( $result )['postType'] ?? '' ) );
$result = $run(
	array(
		'target'   => 'part',
		'template' => 'sidebar',
	)
);
$check( '...unknown: refused, with the parts', 'mody_part_not_found' === $code( $result ) && in_array( 'header', (array) ( $result->get_error_data()['parts'] ?? array() ), true ), $code( $result ) );

$result = $run(
	array(
		'target' => 'list',
		'list'   => 'post',
	)
);
$check( 'list post: the template that shows the latest posts', is_array( $result ) && 'index' === $result['template'] && str_contains( $result['url'], '%2F%2Findex&canvas=edit' ), is_array( $result ) ? $result['place'] : $code( $result ) );
$lists  = array(
	'category' => 'archive',
	'post_tag' => 'archive',
	'author'   => 'archive',
	'date'     => 'archive',
	'search'   => 'search',
);
$wrong  = array();
foreach ( $lists as $key => $slug ) {
	$result = $run(
		array(
			'target' => 'list',
			'list'   => $key,
		)
	);
	if ( ! is_array( $result ) || $slug !== $result['template'] ) {
		$wrong[] = $key . ' → ' . ( is_array( $result ) ? $result['template'] : $code( $result ) );
	}
}
$check( '...categories, tags, authors, dates: Archive; search: Search', ! $wrong, implode( ', ', $wrong ) );
$result = $run(
	array(
		'target' => 'list',
		'list'   => 'recipes',
	)
);
$check( '...unknown: refused, with the lists', 'mody_list_not_found' === $code( $result ) && str_contains( wp_json_encode( $result->get_error_data() ), 'post: ' ), $code( $result ) );

$menus  = get_posts(
	array(
		'post_type'   => 'wp_navigation',
		'post_status' => 'publish',
		'numberposts' => 2,
		'fields'      => 'ids',
	)
);
$result = $run( array( 'target' => 'navigation' ) );
$check( 'navigation: the only menu, or the menus', is_array( $result ) && ( 1 === count( $menus ) ? '/wp_navigation/' . $menus[0] : '/navigation' ) === ( $query( $result )['p'] ?? '' ), count( $menus ) . ' menu(s): ' . ( is_array( $result ) ? $result['place'] : $code( $result ) ) );
$check( 'patterns and the start screen', '/pattern' === ( $query( $run( array( 'target' => 'patterns' ) ) )['p'] ?? '' ) && '/' === ( $query( $run( array( 'target' => 'home' ) ) )['p'] ?? '' ) );
$result = $run( array( 'target' => 'identity' ) );
$check( 'identity: the Identity screen (M2-08)', is_array( $result ) && '/identity' === ( $query( $result )['p'] ?? '' ) && 'the Identity screen' === $result['place'] && $result['navigate'], is_array( $result ) ? $result['url'] : $code( $result ) );
$result = $run( array( 'target' => 'page' ) );
$check( 'pages and templates lists, as before, now in place', is_array( $result ) && $result['navigate'] && '/page' === ( $query( $result )['p'] ?? '' ) && '/template' === ( $query( $run( array( 'target' => 'template' ) ) )['p'] ?? '' ) );

// 1b. Pages by title (decided 2026-11-08) -------------------------------------------------

echo "\nPages by title\n";
$made = array();
foreach ( array( 'Lighthouse Tours', 'Lighthouse Tours for Schools', 'Harbour Café' ) as $page_title ) {
	$made[ $page_title ] = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => $page_title,
		)
	);
}
$cleanup[] = static function () use ( $made ): void {
	foreach ( $made as $made_id ) {
		wp_delete_post( $made_id, true );
	}
};
$by_title  = static fn( string $page_title ) => $run(
	array(
		'target'     => 'page',
		'page_title' => $page_title,
	)
);
$result    = $by_title( 'lighthouse tours' );
$check( 'the exact title wins (any case)', is_array( $result ) && $made['Lighthouse Tours'] === $result['page_id'], is_array( $result ) ? $result['place'] : $code( $result ) );
$result = $by_title( 'the harbour cafe page' );
$check( 'one partial match opens it ("the harbour cafe page")', is_array( $result ) && $made['Harbour Café'] === $result['page_id'] && $result['navigate'], is_array( $result ) ? $result['place'] : $code( $result ) );
$result = $by_title( 'lighthouse' );
$titles = is_wp_error( $result ) ? wp_list_pluck( (array) ( $result->get_error_data()['pages'] ?? array() ), 'title' ) : array();
sort( $titles );
$check( 'several: refused, listing them, so the agent asks', 'mody_page_ambiguous' === $code( $result ) && array( 'Lighthouse Tours', 'Lighthouse Tours for Schools' ) === $titles, implode( ', ', $titles ) );
$check( 'none: not found, with the pages', 'mody_page_not_found' === $code( $by_title( 'pricing zzq' ) ) && 'mody_page_not_found' === $code( $by_title( 'ca' ) ) );

// 2. The lists ----------------------------------------------------------------------

echo "\nThe lists\n";
register_post_type(
	'mody_test_book',
	array(
		'public'      => true,
		'has_archive' => true,
		'label'       => 'Books',
		'labels'      => array( 'name' => 'Books' ),
	)
);
register_taxonomy(
	'mody_test_genre',
	'mody_test_book',
	array(
		'public' => true,
		'labels' => array(
			'name'          => 'Genres',
			'singular_name' => 'Genre',
		),
	)
);
$cleanup[] = static function (): void {
	unregister_taxonomy( 'mody_test_genre' );
	unregister_post_type( 'mody_test_book' );
};
$lists     = SiteEditor::lists();
$check( 'a post type with an archive: its list, through archive-…', 'archive-mody_test_book' === ( $lists['mody_test_book']['slug'] ?? '' ) && 'archive' === $lists['mody_test_book']['template'] && 'the list of Books' === $lists['mody_test_book']['label'], wp_json_encode( $lists['mody_test_book'] ?? null ) );
$check( 'a taxonomy: its list, through taxonomy-…', 'taxonomy-mody_test_genre' === ( $lists['mody_test_genre']['slug'] ?? '' ) && 'archive' === $lists['mody_test_genre']['template'] && 'terms' === $lists['mody_test_genre']['kind'], wp_json_encode( $lists['mody_test_genre'] ?? null ) );
$check( 'post formats are not a list', ! isset( $lists['post_format'] ) );

// A template customized in the Site Editor wins over the theme's fallback.
$custom = wp_insert_post(
	array(
		'post_type'    => 'wp_template',
		'post_name'    => 'category',
		'post_title'   => 'Category (test)',
		'post_status'  => 'publish',
		'post_content' => '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->',
	)
);
wp_set_object_terms( $custom, $theme, 'wp_theme' );
$cleanup[] = static fn() => wp_delete_post( $custom, true );
$lists     = SiteEditor::lists();
$check( 'a customized "category" template: categories use it', 'category' === ( $lists['category']['template'] ?? '' ) && 'archive' === $lists['post_tag']['template'], ( $lists['category']['template'] ?? '' ) . ' / tags: ' . $lists['post_tag']['template'] );
wp_delete_post( $custom, true );

// A static front page, with a posts page.
$front     = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_title'  => 'Welcome (test)',
		'post_status' => 'publish',
	)
);
$blog      = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_title'  => 'Journal (test)',
		'post_status' => 'publish',
	)
);
$options   = array(
	'show_on_front'  => get_option( 'show_on_front' ),
	'page_on_front'  => get_option( 'page_on_front' ),
	'page_for_posts' => get_option( 'page_for_posts' ),
);
$cleanup[] = static function () use ( $options, $front, $blog ): void {
	foreach ( $options as $name => $value ) {
		update_option( $name, $value );
	}
	wp_delete_post( $front, true );
	wp_delete_post( $blog, true );
};
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $front );
update_option( 'page_for_posts', $blog );
$lists = SiteEditor::lists();
$check( 'a posts page: the latest posts go through home → index', 'home' === ( $lists['post']['slug'] ?? '' ) && 'index' === $lists['post']['template'] && str_contains( $lists['post']['label'], 'Journal (test)' ), wp_json_encode( $lists['post'] ?? null ) );
foreach ( $options as $name => $value ) {
	update_option( $name, $value );
}

// 3. Where the owner is -------------------------------------------------------------

echo "\nWhere the owner is\n";
$named = static fn( array $raw ): string => ( $screen = SiteEditor::screen_from_input( $raw ) ) ? SiteEditor::describe( $screen ) : 'null';
$check(
	'Styles → Colors, a template, the start',
	'Styles → Colors' === $named(
		array(
			'p'       => '/styles',
			'section' => '/colors',
		)
	) && 'the "Index" template' === $named(
		array(
			'p'      => '/wp_template/' . $theme . '//index',
			'canvas' => 'edit',
		)
	) && 'the Site Editor\'s start screen' === $named( array( 'p' => '' ) ),
	$named( array( 'p' => '/wp_template/' . $theme . '//index' ) )
);
$check( 'the Identity screen', 'the Identity screen' === $named( array( 'p' => '/identity' ) ) );
$check( 'a block type\'s styles', str_ends_with( $named( array( 'p' => '/styles', 'section' => '/blocks/core%2Fheading' ) ), '→ Heading' ), $named( array( 'p' => '/styles', 'section' => '/blocks/core%2Fheading' ) ) );
$check( 'refused: not a route, too long; a bad section dropped', null === SiteEditor::screen_from_input( array( 'p' => 'javascript:alert(1)' ) ) && null === SiteEditor::screen_from_input( array( 'p' => '/' . str_repeat( 'a', 250 ) ) ) && '' === SiteEditor::screen_from_input( array( 'p' => '/styles', 'section' => '<b>' ) )['section'] && null === SiteEditor::screen_from_input( 'x' ) );
$check( 'the prompt line', str_contains( SystemPrompt::for_screen( SiteEditor::screen_from_input( array( 'p' => '/styles', 'section' => '/colors' ) ) ), 'and Styles → Colors is open right now. Only when they ask for this very screen, do not open it again' ) );

// 4. The prompt ---------------------------------------------------------------------

echo "\nThe prompt\n";
$prompt = SystemPrompt::for_agent( Agent::tool_names() );
$check( 'the targets explained, identity included (M2-08)', str_contains( $prompt, 'Targets: page' ) && str_contains( $prompt, 'list (list: a key from "Lists on this site"' ) && str_contains( $prompt, 'browse for the style presets' ) && str_contains( $prompt, 'identity (the site\'s title, tagline, logo, icon, mission and tone)' ) );
$check( 'rule 7: the brand is the site\'s settings and styles, no brand question', str_contains( $prompt, '7. The site\'s brand is its WordPress settings and its styles' ) && ! str_contains( $prompt, 'part of my brand' ) );
$check( 'rule 9: open the screen when the owner does not say how', str_contains( $prompt, '9. When the owner wants to change something without saying how' ) );
$archive = SiteEditor::lists()['category']['template_title'] ?? '';
$check( 'the site\'s lists, with counts and templates', str_contains( $prompt, '- post: the latest posts, on the front page (' ) && str_contains( $prompt, 'published). Template: Index.' ) && str_contains( $prompt, '- category: the posts of each category (' ) && '' !== $archive && str_contains( $prompt, 'Template: ' . $archive . '.' ), $archive );

// 5. Permissions --------------------------------------------------------------------

echo "\nPermissions\n";
$editor = wp_insert_user(
	array(
		'user_login' => 'modynaved' . strtolower( wp_generate_password( 5, false ) ),
		'user_email' => 'modynaved' . wp_rand() . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'editor',
	)
);
require_once ABSPATH . 'wp-admin/includes/ms.php';
$cleanup[] = static fn() => wpmu_delete_user( $editor );
wp_set_current_user( $editor );
$check( 'an editor: pages yes; styles, parts, lists, identity no', SiteEditor::can_open( array( 'target' => 'page' ) ) && ! SiteEditor::can_open( array( 'target' => 'styles' ) ) && ! SiteEditor::can_open( array( 'target' => 'part' ) ) && ! SiteEditor::can_open( array( 'target' => 'list' ) ) && ! SiteEditor::can_open( array( 'target' => 'identity' ) ) );
wp_set_current_user( 0 );
$check( 'logged out: nothing', ! SiteEditor::can_open( array( 'target' => 'page' ) ) && is_wp_error( $run( array( 'target' => 'home' ) ) ) );

// 6. Real requests (part C) ------------------------------------------------------------

if ( getenv( 'MODY_TEST_OFFLINE' ) ) {
	echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed (offline).\n";
	return;
}

echo "\nReal requests (the start screen of the Site Editor; paced)\n";
wp_set_current_user( get_user_by( 'login', $admins[0] )->ID );
// The identity settings the agent may change (M2-08): put them back at the end.
$saved_identity = array(
	'blogdescription'      => get_option( 'blogdescription' ),
	BrandSettings::MISSION => get_option( BrandSettings::MISSION, '' ),
	BrandSettings::TONE    => get_option( BrandSettings::TONE, '' ),
);
$cleanup[]      = static function () use ( $saved_identity ): void {
	foreach ( $saved_identity as $option => $value ) {
		update_option( $option, $value );
	}
};
// A named color now changes the palette at once (M2-08): put the site's styles back at the end.
$styles_id      = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
$styles_content = (string) get_post( $styles_id )->post_content;
$revisions      = wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) );
$cleanup[]      = static function () use ( $styles_id, $styles_content, $revisions ): void {
	if ( (string) get_post( $styles_id )->post_content !== $styles_content ) {
		wp_update_post( wp_slash( array( 'ID' => $styles_id, 'post_content' => $styles_content ) ), true, false ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
	}
	foreach ( array_diff( wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) ), $revisions ) as $revision ) {
		wp_delete_post_revision( $revision );
	}
	wp_clean_theme_json_cache();
};
$opened = static function ( string $text, array $screen = array( 'p' => '/' ), array $history = array() ): array {
	usleep( 700000 ); // Infomaniak's rate limit.
	$start = microtime( true );
	$turn  = Agent::respond( $text, $history, null, SiteEditor::screen_from_input( $screen ) );
	$call  = null;
	foreach ( $turn['calls'] ?? array() as $made ) {
		if ( str_ends_with( (string) $made['name'], 'open-editor' ) ) {
			$call = (array) $made['args'];
		}
	}
	$screen_action = array_values( array_filter( $turn['actions'] ?? array(), static fn( $a ) => ! empty( $a['navigate'] ) ) );
	return array(
		'turn'    => $turn,
		'args'    => $call,
		'place'   => (string) ( $screen_action[0]['place'] ?? '' ),
		'choices' => $turn['choices'] ?? array(),
		'detail'  => sprintf( '%s | %s | %.1fs', null === $call ? 'no open-editor' : wp_json_encode( $call ), mb_strimwidth( (string) $turn['reply'], 0, 60, '…' ), microtime( true ) - $start ),
	);
};
$cases = array(
	array( 'I want to change the colors of the site', static fn( $r ) => 'styles' === ( $r['args']['target'] ?? '' ) && in_array( $r['args']['section'] ?? '', array( 'colors', 'palette' ), true ) ),
	array( 'I want to edit the list of posts', static fn( $r ) => ( 'list' === ( $r['args']['target'] ?? '' ) && 'post' === ( $r['args']['list'] ?? '' ) ) || ( null === $r['args'] && $r['choices'] ) ),
	array( 'Edit the category pages', static fn( $r ) => 'list' === ( $r['args']['target'] ?? '' ) && 'category' === ( $r['args']['list'] ?? '' ) ),
	array( 'Edit the header', static fn( $r ) => 'part' === ( $r['args']['target'] ?? '' ) && 'header' === ( $r['args']['template'] ?? '' ) ),
	array( 'Change the fonts', static fn( $r ) => 'styles' === ( $r['args']['target'] ?? '' ) && 'typography' === ( $r['args']['section'] ?? '' ) ),
	array( 'Show me other designs for my site', static fn( $r ) => 'styles' === ( $r['args']['target'] ?? '' ) && 'browse' === ( $r['args']['section'] ?? '' ) ),
	array( 'I want to edit the menu', static fn( $r ) => 'navigation' === ( $r['args']['target'] ?? '' ) ),
	array( 'Change how buttons look', static fn( $r ) => 'styles' === ( $r['args']['target'] ?? '' ) && ( 'core/button' === ( $r['args']['block'] ?? '' ) || 'blocks' === ( $r['args']['section'] ?? '' ) ) ),
	array( 'Undo my last style changes', static fn( $r ) => 'styles' === ( $r['args']['target'] ?? '' ) && 'revisions' === ( $r['args']['section'] ?? '' ) ),
);
foreach ( $cases as list( $text, $ok ) ) {
	$result = $opened( $text );
	$check( '"' . $text . '"', $ok( $result ), $result['detail'] );
}
// Pages (decided 2026-11-08): open when clear, ask until it is; a new page opens directly.
$result = $opened( 'Edit the harbour cafe page' );
$check( '"Edit the harbour cafe page": opens "Harbour Café"', 'the page "Harbour Café"' === $result['place'], $result['detail'] );
$asked = $opened( 'I want to edit the lighthouse page' );
$check( '"…the lighthouse page" (two match): asks, with both', ! $asked['place'] && in_array( 'Lighthouse Tours', $asked['choices'], true ) && in_array( 'Lighthouse Tours for Schools', $asked['choices'], true ), implode( ' / ', $asked['choices'] ) . ' | ' . $asked['detail'] );
$result = $opened(
	'Lighthouse Tours for Schools',
	array( 'p' => '/' ),
	array(
		array(
			'role' => 'user',
			'text' => 'I want to edit the lighthouse page',
		),
		array(
			'role' => 'assistant',
			'text' => (string) $asked['turn']['reply'],
		),
	)
);
$check( '...the owner picks one: it opens', 'the page "Lighthouse Tours for Schools"' === $result['place'], $result['detail'] );
$result = $opened( 'Edit the pricing page' );
$check( '"Edit the pricing page" (none): asks, opens nothing', ! $result['place'] && $result['choices'], implode( ' / ', $result['choices'] ) . ' | ' . $result['detail'] );
$result = $opened( 'Create a short page about our sourdough workshops for office teams' ); // On brand: the main site is a bakery.
$created = 0;
foreach ( $result['turn']['calls'] ?? array() as $made_call ) {
	$created = (int) ( $made_call['result']['page_id'] ?? $created );
}
if ( $created ) {
	$cleanup[] = static fn() => wp_delete_post( $created, true );
}
$check( 'a new page opens directly', $created && str_starts_with( $result['place'], 'the page "' ), $result['place'] . ' | ' . $result['detail'] );

// The brand is the site's settings and styles (M2-08): no brand question, Identity for the logo.
$result = $opened( 'Make the primary color coral' );
$design = null;
foreach ( $result['turn']['calls'] ?? array() as $made_call ) {
	if ( str_ends_with( (string) $made_call['name'], 'update-design' ) ) {
		$design = (array) $made_call['args'];
	}
}
$check( 'a named color changes at once, no brand question, Colors opens', null !== $design && ! $result['choices'] && 'Styles → Colors' === $result['place'], ( null === $design ? 'no update-design' : wp_json_encode( $design ) ) . ' | ' . $result['detail'] );
$result = $opened( 'I want to change my logo' );
$check( '"I want to change my logo": the Identity screen', 'identity' === ( $result['args']['target'] ?? '' ) && 'the Identity screen' === $result['place'], $result['detail'] );
$settings_args  = static function ( array $turn ): ?array {
	foreach ( $turn['calls'] ?? array() as $made_call ) {
		if ( str_ends_with( (string) $made_call['name'], 'update-settings' ) ) {
			return (array) ( $made_call['args']['changes'] ?? array() );
		}
	}
	return null;
};
$result = $opened( 'Change the slogan to Fresh bread every morning' );
$args   = $settings_args( $result['turn'] );
$check( '"Change the slogan to …": the tagline setting', null !== $args && 'Fresh bread every morning' === ( $args['tagline'] ?? '' ) && 'Fresh bread every morning' === BrandSettings::brand()['tagline'], wp_json_encode( $args ) . ' | ' . $result['detail'] );
$result = $opened( 'We now also bake for schools, please update what we do' );
$args   = $settings_args( $result['turn'] );
$check( '"We now also bake for schools…": the mission setting', null !== $args && isset( $args['mission'] ) && str_contains( strtolower( BrandSettings::brand()['mission'] ), 'school' ), wp_json_encode( $args ) . ' | ' . $result['detail'] );
$result = $opened(
	'I want to change the colors of the site',
	array(
		'p'       => '/styles',
		'section' => '/colors',
	)
);
$check( 'already on Styles → Colors: not opened again', null === $result['args'], $result['detail'] );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
