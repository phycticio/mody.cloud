<?php
/**
 * Tests mody/update-menu (tickets/agent-knows-gutenberg-spectra-settings.md, part 3).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/update-menu-test.php
 *
 * On the first member site, as a throwaway administrator. The header's menu
 * is put back as it was (or removed, if the test created it), and the pages
 * the test makes are deleted. Refuses to run in production.
 *
 * 1. The menu: the header's, or WordPress's fallback; its items read back.
 * 2. Writing: pages by title, links, a submenu, "all pages"; the blocks are
 *    valid and the header shows them; drafts, unsafe addresses, deep
 *    submenus, long labels and long menus refused.
 * 3. Confirmation: only a list that drops an item waits (the agent holds it).
 * 4. The agent, for real: add, remove (held), reorder, a link, a question.
 *    `-e MODY_TEST_RUNS=3` repeats, `-e MODY_TEST_OFFLINE=1` skips.
 */

use Mody\Platform\Agent\Agent;
use Mody\Platform\Site\Menu;

if ( 'production' === wp_get_environment_type() ) {
	echo "Refusing to run in production.\n";
	return;
}
require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-62s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};
$sites = get_sites(
	array(
		'site__not_in' => array( get_main_site_id() ),
		'fields'       => 'ids',
		'number'       => 1,
	)
);
if ( ! $sites ) {
	echo "No member site: nothing to test.\n";
	return;
}
$site = (int) $sites[0];
switch_to_blog( $site );

$suffix = strtolower( wp_generate_password( 5, false ) );
$admin  = (int) wpmu_create_user( "modymenu{$suffix}", wp_generate_password( 24 ), "modymenu{$suffix}@mody.local" );
add_user_to_blog( $site, $admin, 'administrator' );
wp_set_current_user( $admin );

$menus_before = get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
$menu         = Menu::post();
$content      = $menu ? $menu->post_content : '';
$page         = static fn( string $title, string $status = 'publish' ): int => (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => $status, 'post_title' => $title . ' ' . $suffix ) );
$pages        = array(
	'about'   => $page( 'About' ),
	'contact' => $page( 'Contact' ),
	'team'    => $page( 'Team' ),
	'draft'   => $page( 'Draft', 'draft' ),
);
register_shutdown_function(
	static function () use ( $site, $menu, $content, $menus_before, $pages, $admin ): void {
		switch_to_blog( $site );
		if ( $menu ) {
			wp_update_post( wp_slash( array( 'ID' => $menu->ID, 'post_content' => $content ) ) );
		}
		foreach ( get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
			if ( ! in_array( $id, $menus_before, true ) ) {
				wp_delete_post( $id, true ); // A fallback the test made.
			}
		}
		foreach ( $pages as $id ) {
			wp_delete_post( $id, true );
		}
		Agent::forget_held();
		restore_current_blog();
		wpmu_delete_user( $admin );
	}
);
$title  = static fn( string $key ): string => ucfirst( $key ) . ' ' . $suffix;
$update = static fn( array $items ) => wp_get_ability( 'mody/update-menu' )->execute( array( 'items' => $items ) );

// 1. The menu ------------------------------------------------------------------------

echo "The menu\n";
$check( 'the header\'s menu, or WordPress\'s fallback', $menu instanceof WP_Post && 'wp_navigation' === $menu->post_type, $menu ? '#' . $menu->ID : 'none' );
$check( 'its items read', is_array( Menu::items() ), wp_json_encode( Menu::items() ) );
$check( 'the prompt shows them', str_contains( Menu::context(), "The site's menu" ) && str_contains( Menu::context(), (string) wp_json_encode( Menu::items(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );

// 2. Writing ------------------------------------------------------------------------

echo "\nWriting\n";
$items = array(
	$title( 'about' ),
	array( 'label' => 'Shop', 'url' => 'https://shop.example.com/' ),
	array( 'page' => $title( 'contact' ), 'children' => array( $title( 'team' ), array( 'label' => 'Call us', 'url' => 'tel:+34 600 000 000' ) ) ),
	'all pages',
);
$out = $update( $items );
$check( 'pages, a link, a submenu, all pages: saved', ! is_wp_error( $out ) && $items === Menu::items(), is_wp_error( $out ) ? $out->get_error_message() : wp_json_encode( Menu::items() ) );
$check( '... the old and new items sent back, and the menu\'s screen', ! is_wp_error( $out ) && $items === $out['new'] && str_contains( (string) $out['url'], 'wp_navigation' ) );
$saved = get_post( Menu::post()->ID )->post_content;
$check( '... valid blocks: they round-trip through the parser', serialize_blocks( parse_blocks( $saved ) ) === $saved && 4 === count( array_filter( parse_blocks( $saved ), static fn( $b ) => null !== $b['blockName'] ) ) );
$check( '... pages linked by ID, with their address', str_contains( $saved, '"id":' . $pages['about'] ) && str_contains( $saved, '"url":"' . get_permalink( $pages['about'] ) . '"' ) );
$html = do_blocks( '<!-- wp:navigation {"ref":' . Menu::post()->ID . '} /-->' );
$check( 'the header shows them', str_contains( $html, $title( 'about' ) ) && str_contains( $html, 'https://shop.example.com/' ) && str_contains( $html, 'Call us' ) );
$refused = array(
	'a draft page'         => array( $title( 'draft' ) ),
	'a javascript: link'   => array( array( 'label' => 'X', 'url' => 'javascript:alert(1)' ) ),
	'a link without label' => array( array( 'url' => 'https://example.com/' ) ),
	'two submenu levels'   => array( array( 'page' => $title( 'about' ), 'children' => array( array( 'page' => $title( 'team' ), 'children' => array( $title( 'contact' ) ) ) ) ) ),
	'a long label'         => array( array( 'label' => str_repeat( 'a', 61 ), 'url' => '/x' ) ),
	'41 items'             => array_fill( 0, 41, $title( 'about' ) ),
);
$before = Menu::items();
foreach ( $refused as $what => $bad ) {
	$out = $update( $bad );
	$check( "$what: refused, the menu unchanged", is_wp_error( $out ) && $before === Menu::items(), is_wp_error( $out ) ? $out->get_error_message() : 'saved' );
}
$check( 'no items: refused by the schema', is_wp_error( wp_get_ability( 'mody/update-menu' )->execute( array() ) ) );

// 3. Confirmation -------------------------------------------------------------------

echo "\nConfirmation\n";
$update( array( $title( 'about' ), $title( 'contact' ) ) );
$check( 'adding or reordering: at once', ! Menu::to_confirm( array( 'items' => array( $title( 'contact' ), $title( 'about' ), $title( 'team' ) ) ) ) );
$check( 'dropping an item: waits for the owner', Menu::to_confirm( array( 'items' => array( $title( 'contact' ) ) ) ) );
add_filter(
	'wp_ai_client_prevent_prompt',
	'__return_false'
);
$names = Agent::tool_names();
$fake  = static function ( array $answers ) {
	return static function ( $pre, $args ) use ( &$answers ) {
		unset( $pre, $args );
		return array_shift( $answers );
	};
};

if ( getenv( 'MODY_TEST_OFFLINE' ) ) {
	echo "\nThe agent: real requests skipped (MODY_TEST_OFFLINE is set)\n";
} else {
	echo "\nThe agent, for real (Qwen on Infomaniak)\n";
	$runs  = max( 1, (int) getenv( 'MODY_TEST_RUNS' ) );
	$start = array( $title( 'about' ), $title( 'contact' ) );
	$items_of = static function ( array $turn ): ?array {
		foreach ( $turn['calls'] ?? array() as $made_call ) {
			if ( str_ends_with( (string) $made_call['name'], 'update-menu' ) ) {
				return (array) ( $made_call['args']['items'] ?? array() );
			}
		}
		return null;
	};
	$cases = array(
		'Add the Team ' . $suffix . ' page to the menu'           => static fn( array $t, ?array $i ): bool => array( $title( 'about' ), $title( 'contact' ), $title( 'team' ) ) === Menu::items() || array( $title( 'team' ), $title( 'about' ), $title( 'contact' ) ) === Menu::items(),
		'Remove About ' . $suffix . ' from the menu'               => static fn( array $t, ?array $i ): bool => null !== $i && isset( $t['decision'] ) && $start === Menu::items() && array( $title( 'contact' ) ) === array_map( static fn( $x ) => is_array( $x ) ? ( $x['page'] ?? '' ) : $x, $i ),
		'Put Contact ' . $suffix . ' first in the menu'            => static fn( array $t, ?array $i ): bool => array( $title( 'contact' ), $title( 'about' ) ) === Menu::items(),
		'Add a link to our shop at https://shop.example.com in the menu' => static fn( array $t, ?array $i ): bool => in_array( 'https://shop.example.com', array_map( static fn( $x ) => is_array( $x ) ? rtrim( (string) ( $x['url'] ?? '' ), '/' ) : '', Menu::items() ), true ) && 3 === count( Menu::items() ),
		'What is in the menu?'                                     => static fn( array $t, ?array $i ): bool => null === $i && str_contains( $t['reply'], 'About' ) && str_contains( $t['reply'], 'Contact' ),
	);
	foreach ( $cases as $text => $ok ) {
		$passed = 0;
		$seen   = array();
		for ( $i = 0; $i < $runs; $i++ ) {
			Agent::forget_held();
			$update( $start );
			$turn    = Agent::respond( $text );
			$items   = $items_of( $turn );
			$good    = $ok( $turn, $items );
			$passed += $good ? 1 : 0;
			$seen[]  = ( $good ? '' : 'FAILED: ' ) . wp_json_encode( $items, JSON_UNESCAPED_SLASHES ) . ( isset( $turn['decision'] ) ? ' held' : '' ) . ' "' . mb_strimwidth( (string) $turn['reply'], 0, 90, '…' ) . '"';
			sleep( 3 ); // Infomaniak's rate limit is shared with production.
		}
		$check( "\"$text\": $passed/$runs", $passed === $runs, implode( ' | ', $seen ) );
	}
}

wp_set_current_user( 0 );
restore_current_blog();
echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
