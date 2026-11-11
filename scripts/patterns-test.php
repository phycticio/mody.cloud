<?php
/**
 * Tests the Mody patterns collection, the design market (ticket
 * design-market-themes-patterns.md).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/patterns-test.php
 *
 * 1. The rewrite (PatternImport): images to placeholders, colors to palette
 *    slugs, fonts, links; a gradient refused; the nearest palette color.
 * 2. The collection file: every pattern has its source and license, loads
 *    nothing from another site, uses only palette colors, the theme's fonts
 *    and sizes, and the platform's placeholders, and survives a parse and
 *    serialize unchanged.
 * 3. What a member's editor gets: exactly the collection (source `plugin`,
 *    `mody/…`), no core or Pattern Directory patterns, and no request to
 *    WordPress.org; the Directory proxy refused.
 * 4. The placeholders over HTTP.
 *
 * No model call. The editor's own block validation is checked in a browser
 * (ticket, Results).
 */

use Mody\Platform\Design\PatternImport;
use Mody\Platform\Design\Patterns;

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-62s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

// 1. The rewrite ------------------------------------------------------------------

echo "The rewrite\n";
$check( 'nearest palette color: white base, black contrast, teal primary', 'base' === PatternImport::nearest( '#ffffff' ) && 'contrast' === PatternImport::nearest( '#000000' ) && 'primary' === PatternImport::nearest( '#0a5d68' ) );
$sample = '<!-- wp:cover {"url":"https://pd.w.org/2022/08/a.jpg","id":12,"dimRatio":50,"customOverlayColor":"#141414"} -->'
	. '<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim" style="background-color:#141414"></span>'
	. '<img class="wp-block-cover__image-background wp-image-12" alt="" src="https://pd.w.org/2022/08/a.jpg" data-object-fit="cover"/>'
	. '<div class="wp-block-cover__inner-container"><!-- wp:paragraph {"textColor":"white","fontFamily":"source-serif-pro","fontSize":"extra-small"} -->'
	. '<p class="has-white-color has-text-color has-source-serif-pro-font-family has-extra-small-font-size">Hello <a href="https://wordpress.org">there</a></p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->';
$result = PatternImport::transform( $sample );
$out    = $result['content'];
$check( 'accepted', '' === $result['refused'], $result['refused'] );
$check( 'image and cover URL: the platform\'s placeholder, no attachment ID', 2 === substr_count( $out, '/mody-placeholder/1600x900.svg' ) && ! str_contains( $out, 'wp-image-12' ) && ! str_contains( $out, '"id":12' ) );
$check( 'overlay hex: a palette slug and class', str_contains( $out, '"overlayColor":"contrast"' ) && str_contains( $out, 'has-contrast-background-color' ) && ! str_contains( $out, '#141414' ) );
$check( 'core\'s "white": the palette\'s base', str_contains( $out, '"textColor":"base"' ) && str_contains( $out, 'has-base-color' ) && ! str_contains( $out, 'has-white-color' ) );
$check( 'unknown font family dropped, font size mapped', ! str_contains( $out, 'source-serif-pro' ) && str_contains( $out, '"fontSize":"small"' ) && str_contains( $out, 'has-small-font-size' ) );
$check( 'links to other sites: #', str_contains( $out, 'href="#"' ) && ! preg_match( '#https?://#', $out ) );
$check( 'a gradient is refused', 'a gradient' === PatternImport::transform( '<!-- wp:group {"gradient":"vivid-cyan-blue-to-vivid-purple"} --><div class="wp-block-group has-vivid-cyan-blue-to-vivid-purple-gradient-background has-background"></div><!-- /wp:group -->' )['refused'] );

// 2. The collection file -------------------------------------------------------------

echo "\nThe collection\n";
$collection = Patterns::collection();
$patterns   = (array) ( $collection['patterns'] ?? array() );
$palette    = array_keys( PatternImport::palette() );
$check( 'the collection is there', count( $patterns ) >= 60, count( $patterns ) . ' patterns, generated ' . ( $collection['generated'] ?? '?' ) );
$bad = array();
foreach ( $patterns as $pattern ) {
	$content = (string) $pattern['content'];
	$why     = array();
	if ( empty( $pattern['name'] ) || empty( $pattern['title'] ) || 'GPL-2.0-or-later' !== ( $pattern['source']['license'] ?? '' ) || empty( $pattern['source']['from'] ) ) {
		$why[] = 'source';
	}
	if ( preg_match( '#https?://#i', $content ) ) {
		$why[] = 'URL';
	}
	if ( preg_match( '/#[0-9a-f]{3,8}\b|rgba?\(/i', $content ) ) {
		$why[] = 'custom color';
	}
	preg_match_all( '/\bhas-([a-z0-9-]+?)-(?:background-|border-)?color\b/', $content, $classes );
	$off = array_diff( array_unique( $classes[1] ), array_merge( $palette, array( 'text', 'link', 'inline', 'icon', 'icon-background' ) ) );
	if ( $off ) {
		$why[] = 'colors ' . implode( ',', $off );
	}
	preg_match_all( '/var\(--wp--preset--color--([a-z0-9-]+)\)|var:preset\|color\|([a-z0-9-]+)/', $content, $vars );
	$off = array_diff( array_unique( array_filter( array_merge( $vars[1], $vars[2] ) ) ), $palette );
	if ( $off ) {
		$why[] = 'color vars ' . implode( ',', $off );
	}
	preg_match_all( '/has-([a-z0-9-]+)-font-family/', $content, $fonts );
	if ( array_diff( $fonts[1], array( 'inter' ) ) ) {
		$why[] = 'font';
	}
	preg_match_all( '#src="([^"]+)"#', $content, $sources );
	foreach ( $sources[1] as $src ) {
		if ( null === Patterns::placeholder_size( $src ) ) {
			$why[] = 'image ' . $src;
		}
	}
	if ( serialize_blocks( parse_blocks( $content ) ) !== $content ) {
		$why[] = 'not stable through parse and serialize';
	}
	if ( $why ) {
		$bad[] = $pattern['title'] . ' (' . implode( '; ', $why ) . ')';
	}
}
$check( 'each: source and license, no URL, palette colors only, theme fonts, placeholders, stable', ! $bad, implode( ' | ', array_slice( $bad, 0, 4 ) ) );
$check( 'names are unique', count( $patterns ) === count( array_unique( array_column( $patterns, 'name' ) ) ) );

// 3. What a member's editor gets ---------------------------------------------------

echo "\nA member's editor\n";
$member_sites = get_sites(
	array(
		'site__not_in' => array( get_main_site_id() ),
		'fields'       => 'ids',
		'number'       => 1,
	)
);
$site         = (int) ( $member_sites[0] ?? get_main_site_id() );
switch_to_blog( $site );
$members = get_users(
	array(
		'role'   => 'administrator',
		'number' => 5,
	)
);
$member  = null;
foreach ( $members as $candidate ) {
	if ( ! is_super_admin( $candidate->ID ) ) {
		$member = $candidate;
		break;
	}
}
wp_set_current_user( $member ? $member->ID : 0 );
$outgoing = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$outgoing ) {
		$outgoing[] = (string) wp_parse_url( $url, PHP_URL_HOST );
		return $pre;
	},
	10,
	3
);
$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/block-patterns/patterns' ) );
$got      = (array) $response->get_data();
$sources  = array_count_values( array_map( static fn( $p ) => (string) ( $p['source'] ?? '' ), $got ) );
$check( 'a member (not a super admin) on a member site', null !== $member && ! is_super_admin( $member->ID ), $member ? $member->user_login : 'none found' );
$check( 'exactly the collection, as plugin patterns named mody/…', count( $got ) === count( $patterns ) && array( 'plugin' => count( $patterns ) ) === $sources && ! array_filter( $got, static fn( $p ) => ! str_starts_with( $p['name'], 'mody/' ) ), wp_json_encode( $sources ) );
$check( '...and no request to WordPress.org for them', ! in_array( 'api.wordpress.org', $outgoing, true ), implode( ', ', array_unique( $outgoing ) ) );
$check( 'remote patterns off, core\'s bundled ones replaced', false === apply_filters( 'should_load_remote_block_patterns', true ) && ! current_theme_supports( 'core-block-patterns' ) );
$categories = wp_list_pluck( (array) rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/block-patterns/categories' ) )->get_data(), 'name' );
$used       = array_unique( array_merge( ...array_map( static fn( $p ) => (array) $p['categories'], $patterns ) ) );
$check( 'every category used is registered', ! array_diff( $used, $categories ), implode( ', ', array_diff( $used, $categories ) ) );
$directory = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/pattern-directory/patterns' ) );
$check( 'the Pattern Directory proxy: refused to the member', 403 === $directory->get_status() && 'mody_pattern_directory_off' === ( $directory->get_data()['code'] ?? '' ), (string) $directory->get_status() );
$upper = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/Pattern-Directory/patterns' ) );
$check( '...in any casing (the REST server ignores it)', 403 === $upper->get_status(), (string) $upper->get_status() );
restore_current_blog();
$admins = get_super_admins();
wp_set_current_user( get_user_by( 'login', $admins[0] )->ID );
$check( '...not to a super admin (who runs the import)', 'pass' === Patterns::refuse_directory( 'pass', null, new WP_REST_Request( 'GET', '/wp/v2/pattern-directory/patterns' ) ) );

// 4. The placeholders over HTTP --------------------------------------------------------

echo "\nPlaceholders\n";
$get  = static function ( string $path, string $method = 'GET' ): array {
	$response = wp_remote_request(
		'https://' . get_site( get_main_site_id() )->domain . $path,
		array(
			'method'      => $method,
			'sslverify'   => false,
			'redirection' => 0,
			'timeout'     => 20,
		)
	);
	return array(
		'code' => (int) wp_remote_retrieve_response_code( $response ),
		'type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
		'body' => (string) wp_remote_retrieve_body( $response ),
	);
};
$out  = $get( '/mody-placeholder/1200x800.svg' );
$check( '1200x800: an SVG of that size, made here', 200 === $out['code'] && str_starts_with( $out['type'], 'image/svg+xml' ) && str_contains( $out['body'], 'width="1200" height="800"' ) && ! preg_match( '#https?://(?!www\.w3\.org/2000/svg)#', $out['body'] ), $out['code'] . ' ' . $out['type'] );
$check( 'too large, too small or another path: not a placeholder', null === Patterns::placeholder_size( '/mody-placeholder/4001x800.svg' ) && null === Patterns::placeholder_size( '/mody-placeholder/15x800.svg' ) && null === Patterns::placeholder_size( '/mody-placeholder/1200x800.png' ) && 404 === $get( '/mody-placeholder/5000x5000.svg' )['code'] );
$check( 'a POST is not served', ! str_starts_with( $get( '/mody-placeholder/1200x800.svg', 'POST' )['type'], 'image/svg+xml' ) );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
