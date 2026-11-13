<?php
/**
 * Translates the main site's home page (scripts/home-page.php) into Spanish,
 * German and Italian, and publishes the translations (tickets/site-languages.md).
 *
 * Run from the repository root, on the main site, after scripts/home-page.php
 * (scripts/ is not mounted in the container, so the texts are copied in first):
 *
 *   docker cp scripts/home-page-translations.json mody_cloud_app:/tmp/home-page-translations.json
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/home-page-translations.php
 *
 * The texts are in scripts/home-page-translations.json, keyed by the English
 * text exactly as the page has it.
 *
 * It is safe to run again: it replaces the translations' content, so edits
 * made in the editor are lost. When the English page changes, the script
 * stops with the texts it has no translation for; add them to the JSON.
 *
 * - The site's other languages: Spanish, German and Italian are added (the
 *   ones already there are kept).
 * - Each translation: the English page with every text replaced, its title,
 *   excerpt (the meta description) and SEO title (Site\Seo); published; the
 *   front page's translation, so it lives at /es/, /de/ and /it/.
 * - The header's menu per language: a navigation post with the translated
 *   labels and links to the language's home page sections, used by a header
 *   copy for that language (header-es, header-de, header-it). The menus are
 *   dated before the English one, which the header without a menu of its own
 *   keeps showing (core shows the most recent).
 */

use Mody\Languages\Languages;
use Mody\Languages\Parts;
use Mody\Languages\Router;
use Mody\Languages\Translations;

if ( ! is_main_site() ) {
	WP_CLI::error( 'Run this on the main site.' );
}

$file = '/tmp/home-page-translations.json';
$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
if ( ! is_array( $data ) ) {
	WP_CLI::error( "The translations are missing: docker cp scripts/home-page-translations.json mody_cloud_app:$file" );
}
unset( $data['_about'] );

$english = get_page_by_path( 'home', OBJECT, 'page' );
if ( ! $english || (int) get_option( 'page_on_front' ) !== $english->ID ) {
	WP_CLI::error( 'Run scripts/home-page.php first: the English home page must be the front page.' );
}

// The site's languages.
Languages::save( array_merge( Languages::others(), array_keys( $data ) ) );
foreach ( array_keys( $data ) as $locale ) {
	if ( ! Languages::is_other( $locale ) ) {
		WP_CLI::error( "$locale is not one of the site's other languages (is it the default language?)." );
	}
}

/**
 * The block attribute form of a text, as WordPress serializes it: "text":"…".
 *
 * @param string $text A text.
 */
$attribute = static fn( string $text ): string => substr( serialize_block_attributes( array( 'text' => $text ) ), 1, -1 );

// The English menu, the one the header shows: the newest navigation post without a language.
$english_menu = get_posts(
	array(
		'post_type'   => 'wp_navigation',
		'post_status' => 'publish',
		'numberposts' => 1,
		'orderby'     => 'date',
		'order'       => 'DESC',
		'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'     => Translations::LANG,
				'compare' => 'NOT EXISTS',
			),
		),
	)
);
if ( ! $english_menu ) {
	WP_CLI::error( 'The English menu is missing: run scripts/home-page.php first.' );
}
$english_menu = $english_menu[0];

$header = Parts::find( 'header' );
if ( ! $header || ! str_contains( $header->content, '<!-- wp:navigation ' ) ) {
	WP_CLI::error( 'The header template part, with its navigation block, is missing.' );
}

foreach ( $data as $locale => $language ) {
	// 1. The page.
	$markup  = $english->post_content;
	$missing = array();
	foreach ( $language['texts'] as $source => $target ) {
		$count  = 0;
		$markup = str_replace( '>' . $source . '</', '>' . $target . '</', $markup, $found );
		$count += $found;
		$markup = str_replace( $attribute( $source ), $attribute( $target ), $markup, $found );
		$count += $found;
		if ( ! $count ) {
			$missing[] = $source;
		}
	}
	if ( $missing ) {
		WP_CLI::warning( "$locale: these texts are no longer on the English page: " . implode( ' | ', $missing ) );
	}
	// Every text of the page must have been translated: none of the English ones may be left.
	$left = array();
	preg_match_all( '#<(h[1-6]|p|li|a)\b[^>]*>(.*?)</\1>#s', $english->post_content, $texts );
	preg_match_all( '#"text":"((?:[^"\\\\]|\\\\.)*)"#', $english->post_content, $labels );
	$sources = array_merge( $texts[2], array_map( static fn( $raw ) => json_decode( '"' . $raw . '"' ), $labels[1] ) );
	foreach ( array_unique( array_map( 'trim', $sources ) ) as $source ) {
		// A line made only of links (the legal pages): each link's label is checked on its own.
		$only_links = (bool) preg_match( '#^(\s*<a\b[^>]*>[^<]*</a>\s*(·\s*)?)+$#u', $source );
		if ( '' !== $source && ! $only_links && ! preg_match( '#^<[^>]+>$#', $source ) && ! array_key_exists( $source, $language['texts'] ) ) {
			$left[] = $source;
		}
	}
	if ( $left ) {
		WP_CLI::error( "$locale: no translation for: " . implode( ' | ', $left ) . ' — add them to scripts/home-page-translations.json.' );
	}

	$id = Translations::in( $english, $locale, false );
	if ( ! $id ) {
		$id = Translations::create( $english->ID, $locale, $markup, $language['title'] );
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( "$locale: " . $id->get_error_message() );
		}
	}
	$result = wp_update_post(
		wp_slash(
			array(
				'ID'           => $id,
				'post_status'  => 'publish',
				'post_title'   => $language['title'],
				'post_excerpt' => $language['excerpt'],
				'post_content' => $markup,
				'meta_input'   => array( 'mody_seo_title' => (string) ( $language['seo_title'] ?? '' ) ),
			)
		),
		true
	);
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( "$locale: " . $result->get_error_message() );
	}
	update_post_meta( $id, '_wp_page_template', 'page-blank' );

	// 2. The menu: the same links as the English one, translated, to this language's home page.
	$home  = Router::home( $locale );
	$links = array(
		'How it works' => $home . '#how',
		'Mody'         => $home . '#agent',
		'Plans'        => $home . '#pricing',
		'Questions'    => $home . '#faq',
		'Log in'       => wp_login_url(),
	);
	$menu = '';
	foreach ( $links as $label => $url ) {
		$menu .= '<!-- wp:navigation-link ' . wp_json_encode( array( 'label' => $language['menu'][ $label ] ?? $label, 'url' => $url, 'kind' => 'custom', 'isTopLevelLink' => true ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' /-->' . "\n\n";
	}
	$existing = get_posts(
		array(
			'post_type'   => 'wp_navigation',
			'post_status' => 'publish',
			'numberposts' => 1,
			'meta_key'    => Translations::LANG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'  => $locale, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	$nav      = array(
		'post_type'     => 'wp_navigation',
		'post_status'   => 'publish',
		'post_title'    => sprintf( 'Navigation (%s)', Languages::label( $locale ) ),
		'post_content'  => trim( $menu ),
		// Older than the English menu, which the header without a menu of its own shows.
		'post_date'     => gmdate( 'Y-m-d H:i:s', strtotime( $english_menu->post_date ) - HOUR_IN_SECONDS ),
		'post_date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( $english_menu->post_date_gmt ) - HOUR_IN_SECONDS ),
	);
	if ( $existing ) {
		$nav['ID'] = $existing[0]->ID;
	}
	$menu_id = wp_insert_post( wp_slash( $nav ), true );
	if ( is_wp_error( $menu_id ) ) {
		WP_CLI::error( "$locale: " . $menu_id->get_error_message() );
	}
	update_post_meta( $menu_id, Translations::LANG, $locale );

	// 3. The header copy, with that menu.
	$slug = Parts::copy_slug( 'header', $locale );
	$copy = Parts::find( $slug );
	if ( ! $copy ) {
		$created = Parts::create( 'header', $locale );
		if ( is_wp_error( $created ) ) {
			WP_CLI::error( "$locale: " . $created->get_error_message() );
		}
		$copy = Parts::find( $slug );
	}
	$content = (string) preg_replace( '#<!-- wp:navigation \{(?:"ref":\d+,)?#', '<!-- wp:navigation {"ref":' . (int) $menu_id . ',', $header->content, 1 );
	wp_update_post( wp_slash( array( 'ID' => $copy->wp_id, 'post_content' => $content ) ) );

	WP_CLI::log( sprintf( '%s: page %d at %s, menu %d, header %s.', $locale, $id, get_permalink( $id ), $menu_id, $slug ) );
}

WP_CLI::success( 'The home page is published in ' . implode( ', ', array_map( array( Languages::class, 'label' ), array_keys( $data ) ) ) . '.' );
