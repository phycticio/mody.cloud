<?php
/**
 * Builds the Mody patterns collection (the design market, ticket
 * design-market-themes-patterns.md).
 *
 * Run from the repository root, on the main site, when the collection should
 * be refreshed (it is committed; sites never call WordPress.org):
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/import-patterns.php
 *
 * 1. Takes the patterns WordPress offered by default until 2026-11-08: the
 *    Pattern Directory's "core" keyword (11) and "featured" category (26),
 *    through core's proxy, and core's bundled patterns (wp-includes/block-patterns).
 *    All are GPL (the Directory requires GPLv2 or later).
 * 2. Rewrites each with Design\PatternImport: palette colors, the theme's
 *    fonts, the platform's placeholder images, no links to other sites.
 *    Patterns it cannot make fit (gradients) are left out, with the reason.
 * 3. Writes app/web/mu-plugins/mody-platform/patterns/collection.json and
 *    prints a report.
 */

use Mody\Platform\Design\PatternImport;
use Mody\Platform\Design\Patterns;

if ( ! is_main_site() ) {
	echo "Run this on the main site.\n";
	return;
}
$admins = get_super_admins();
wp_set_current_user( get_user_by( 'login', $admins[0] )->ID ); // The Directory proxy is for super admins only.

// Directory categories WordPress does not register, mapped to ones it does (or Mody's).
$categories = array(
	'images' => 'media',
);

$sources = array();
foreach ( array(
	array( 'keyword', 11, 'Pattern Directory, "core" keyword' ),
	array( 'category', 26, 'Pattern Directory, "featured" category' ),
) as list( $param, $value, $label ) ) {
	for ( $page = 1; $page <= 5; $page++ ) {
		$request = new WP_REST_Request( 'GET', '/wp/v2/pattern-directory/patterns' );
		$request->set_param( $param, $value );
		$request->set_param( 'per_page', 100 );
		$request->set_param( 'page', $page );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			if ( 1 === $page ) {
				printf( "Could not read the %s: %s\n", $label, wp_json_encode( $response->get_data() ) );
			}
			break;
		}
		$items = (array) $response->get_data();
		foreach ( $items as $item ) {
			$sources[ 'directory-' . $item['id'] ] = array(
				'title'         => html_entity_decode( (string) $item['title'], ENT_QUOTES ),
				'description'   => html_entity_decode( (string) ( $item['description'] ?? '' ), ENT_QUOTES ),
				'categories'    => (array) ( $item['categories'] ?? array() ),
				'keywords'      => (array) ( $item['keywords'] ?? array() ),
				'viewportWidth' => (int) ( $item['viewport_width'] ?? 0 ),
				'blockTypes'    => (array) ( $item['block_types'] ?? array() ),
				'content'       => (string) $item['content'],
				'source'        => array(
					'from'    => $label,
					'id'      => (int) $item['id'],
					'license' => 'GPL-2.0-or-later',
				),
			);
		}
		if ( count( $items ) < 100 ) {
			break;
		}
	}
}
foreach ( glob( ABSPATH . WPINC . '/block-patterns/*.php' ) as $file ) {
	$pattern = require $file;
	if ( ! is_array( $pattern ) || empty( $pattern['content'] ) ) {
		continue;
	}
	$sources[ 'core-' . basename( $file, '.php' ) ] = array(
		'title'         => (string) $pattern['title'],
		'description'   => (string) ( $pattern['description'] ?? '' ),
		'categories'    => (array) ( $pattern['categories'] ?? array() ),
		'keywords'      => (array) ( $pattern['keywords'] ?? array() ),
		'viewportWidth' => (int) ( $pattern['viewportWidth'] ?? 0 ),
		'blockTypes'    => (array) ( $pattern['blockTypes'] ?? array() ),
		'content'       => (string) $pattern['content'],
		'source'        => array(
			'from'    => 'WordPress core, wp-includes/block-patterns/' . basename( $file ),
			'license' => 'GPL-2.0-or-later',
		),
	);
}

$collection = array();
$left_out   = array();
$names      = array();
foreach ( $sources as $key => $pattern ) {
	$result = PatternImport::transform( $pattern['content'] );
	if ( '' !== $result['refused'] ) {
		$left_out[] = sprintf( '%s (%s): %s', $pattern['title'], $key, $result['refused'] );
		continue;
	}
	$name = sanitize_title( $pattern['title'] );
	if ( isset( $names[ $name ] ) ) {
		$name .= '-' . substr( md5( $key ), 0, 4 );
	}
	$names[ $name ]          = true;
	$pattern['name']         = $name;
	$pattern['content']      = $result['content'];
	$pattern['categories']   = array_values( array_unique( array_map( static fn( $c ) => $categories[ $c ] ?? $c, $pattern['categories'] ) ) );
	$pattern['source']['as'] = Patterns::PREFIX . $name;
	$collection[]            = $pattern;
}
usort( $collection, static fn( $a, $b ) => strcmp( $a['title'], $b['title'] ) );

$file = WPMU_PLUGIN_DIR . '/mody-platform/' . Patterns::FILE;
wp_mkdir_p( dirname( $file ) );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes the collection into the plugin.
file_put_contents(
	$file,
	wp_json_encode(
		array(
			'generated' => gmdate( 'Y-m-d' ),
			'about'     => 'Rewritten by scripts/import-patterns.php from the WordPress.org Pattern Directory and WordPress core. GPL-2.0-or-later, like their sources.',
			'patterns'  => $collection,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	) . "\n"
);

printf( "Read: %d patterns. Written: %d to %s.\n", count( $sources ), count( $collection ), Patterns::FILE );
foreach ( PatternImport::$changes as $kind => $count ) {
	printf( "  %-28s %d\n", $kind, $count );
}
if ( $left_out ) {
	echo "Left out:\n  " . implode( "\n  ", $left_out ) . "\n";
}
