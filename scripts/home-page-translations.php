<?php
/**
 * Puts the main site's home page (scripts/home-page.php) in its languages:
 * Spanish as the default, Catalan and English as translations
 * (tickets/spanish-by-default.md, part 3; before 2026-11-27 English was the
 * default, with Spanish, German and Italian translations).
 *
 * Run from the repository root, on the main site, right after
 * scripts/home-page.php, which writes the English source into the page
 * (scripts/ is not mounted in the container, so the texts are copied in first):
 *
 *   docker cp scripts/home-page-translations.json mody_cloud_app:/tmp/home-page-translations.json
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/home-page-translations.php
 *
 * The texts are in scripts/home-page-translations.json, keyed by the English
 * text exactly as scripts/home-page.php has it. The site's default language
 * (core's Site Language, `WPLANG`) must be one of the JSON's languages: es_ES.
 *
 * Safe to run again after scripts/home-page.php: it replaces the content of
 * the page and its translations, so edits made in the editor are lost. When
 * the English page changes, the script stops with the texts it has no
 * translation for; add them to the JSON.
 *
 * - The site's other languages: English and the JSON's languages but the
 *   default. Any other language is removed, and its pages, menus and header
 *   copies go to the trash; Mody Languages sends their old addresses to the
 *   default language's (Router::redirect_missing_language()).
 * - The page: the English source becomes the English translation (/en/), each
 *   other language a translation of its own (/ca/), and the page itself is
 *   given the default language's texts, title and SEO fields.
 * - The legal pages linked at the bottom (scripts/legal-pages.php): each
 *   translation links to the legal pages in its language, when they exist.
 * - The header's menu: the default menu gets the default language's labels;
 *   every other language gets a navigation post with its labels and links to
 *   its home page's sections, used by a header copy for it (header-en,
 *   header-ca). Those menus are dated before the default one, which the header
 *   without a menu of its own keeps showing (core shows the most recent).
 * - /register/ (Mody Members' page with the signup chat): its title in the
 *   default language, and a translation per other language (/en/sign-up/,
 *   /ca/registre/).
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

$default = Languages::default_locale();
if ( ! isset( $data[ $default ] ) ) {
	WP_CLI::error( "The site's default language is \"$default\", and the JSON has no texts for it. Set it first: wp option update WPLANG es_ES" );
}
$english = 'en_US';
$others  = array_values( array_diff( array_merge( array( $english ), array_keys( $data ) ), array( $default ) ) );

// The English source, as scripts/home-page.php wrote it, with its title and SEO fields.
$page = get_page_by_path( 'home', OBJECT, 'page' );
if ( ! $page || (int) get_option( 'page_on_front' ) !== $page->ID ) {
	WP_CLI::error( 'Run scripts/home-page.php first: the home page must be the front page.' );
}
$source = $page->post_content;
if ( ! str_contains( $source, '>' . array_key_first( $data[ $default ]['texts'] ) . '</' ) ) {
	WP_CLI::error( 'The home page does not hold the English source: run scripts/home-page.php first, then this script.' );
}
$data[ $english ] = array(
	'title'     => $page->post_title,
	'seo_title' => (string) get_post_meta( $page->ID, 'mody_seo_title', true ),
	'excerpt'   => (string) get_post_meta( $page->ID, 'mody_seo_description', true ),
	'menu'      => array(),
	'texts'     => array(),
);

// The site's languages: languages no longer offered go, with what was made for them.
$removed = array_diff( Languages::others(), $others );
Languages::save( $others );
foreach ( $others as $locale ) {
	if ( ! Languages::is_other( $locale ) ) {
		WP_CLI::error( "$locale could not be added to the site's languages." );
	}
}
foreach ( $removed as $locale ) {
	$made = get_posts(
		array(
			'post_type'   => array( 'page', 'post', 'wp_navigation' ),
			'post_status' => 'any',
			'numberposts' => -1,
			'meta_key'    => Translations::LANG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'  => $locale, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	foreach ( $made as $post ) {
		wp_trash_post( $post->ID );
	}
	$copy = Parts::find( Parts::copy_slug( 'header', $locale ) );
	if ( $copy && $copy->wp_id ) {
		wp_trash_post( $copy->wp_id );
	}
	WP_CLI::log( sprintf( '%s removed: %d pages and menus in the trash%s.', $locale, count( $made ), $copy ? ', and its header copy' : '' ) );
}

/**
 * The block attribute form of a text, as WordPress serializes it: "text":"…".
 *
 * @param string $text A text.
 */
$attribute = static fn( string $text ): string => substr( serialize_block_attributes( array( 'text' => $text ) ), 1, -1 );

/**
 * The page in a language: every English text replaced. Stops when a text of
 * the page has no translation.
 *
 * @param string $locale A locale of the JSON.
 */
$translate = static function ( string $locale ) use ( $data, $source, $attribute, $english ): string {
	if ( $english === $locale ) {
		return $source;
	}
	$markup  = $source;
	$missing = array();
	foreach ( $data[ $locale ]['texts'] as $from => $to ) {
		$count  = 0;
		$markup = str_replace( '>' . $from . '</', '>' . $to . '</', $markup, $found );
		$count += $found;
		$markup = str_replace( $attribute( $from ), $attribute( $to ), $markup, $found );
		$count += $found;
		if ( ! $count ) {
			$missing[] = $from;
		}
	}
	if ( $missing ) {
		WP_CLI::warning( "$locale: these texts are no longer on the English page: " . implode( ' | ', $missing ) );
	}
	// Every text of the page must have been translated: none of the English ones may be left.
	$left = array();
	preg_match_all( '#<(h[1-6]|p|li|a)\b[^>]*>(.*?)</\1>#s', $source, $texts );
	preg_match_all( '#"text":"((?:[^"\\\\]|\\\\.)*)"#', $source, $labels );
	$sources = array_merge( $texts[2], array_map( static fn( $raw ) => json_decode( '"' . $raw . '"' ), $labels[1] ) );
	foreach ( array_unique( array_map( 'trim', $sources ) ) as $text ) {
		// A line made only of links (the legal pages): each link's label is checked on its own.
		$only_links = (bool) preg_match( '#^(\s*<a\b[^>]*>[^<]*</a>\s*(·\s*)?)+$#u', $text );
		if ( '' !== $text && ! $only_links && ! preg_match( '#^<[^>]+>$#', $text ) && ! array_key_exists( $text, $data[ $locale ]['texts'] ) ) {
			$left[] = $text;
		}
	}
	if ( $left ) {
		WP_CLI::error( "$locale: no translation for: " . implode( ' | ', $left ) . ' — add them to scripts/home-page-translations.json.' );
	}
	return $markup;
};

/**
 * Points the legal pages' links at their translation in a language, when it
 * is published.
 *
 * @param string $markup The page in that language.
 * @param string $locale The language.
 */
$legal_links = static function ( string $markup, string $locale ): string {
	foreach ( array( 'legal-notice', 'terms', 'privacy' ) as $slug ) {
		$legal = get_page_by_path( $slug, OBJECT, 'page' );
		$in    = $legal ? Translations::in( $legal, $locale ) : 0;
		if ( $in ) {
			$markup = str_replace( 'href="' . get_permalink( $legal ) . '"', 'href="' . get_permalink( $in ) . '"', $markup );
		}
	}
	return $markup;
};

/**
 * Saves a page's content, title and SEO fields, published.
 *
 * @param int                  $id       The page.
 * @param string               $markup   Its content.
 * @param array<string, mixed> $language The language's title, seo_title and excerpt.
 */
$save = static function ( int $id, string $markup, array $language ): void {
	$result = wp_update_post(
		wp_slash(
			array(
				'ID'           => $id,
				'post_status'  => 'publish',
				'post_title'   => $language['title'],
				'post_excerpt' => '', // Until 2026-11-16 the meta description.
				'post_content' => $markup,
				'meta_input'   => array(
					'mody_seo_title'       => (string) ( $language['seo_title'] ?? '' ),
					'mody_seo_description' => (string) $language['excerpt'], // The JSON's key is still "excerpt".
				),
			)
		),
		true
	);
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( "Page $id: " . $result->get_error_message() );
	}
	update_post_meta( $id, '_wp_page_template', 'page-blank' );
};

// The default menu, the one the header shows: the newest navigation post without a language.
$default_menu = get_posts(
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
if ( ! $default_menu ) {
	WP_CLI::error( 'The menu is missing: run scripts/home-page.php first.' );
}
$default_menu = $default_menu[0];

$header = Parts::find( 'header' );
if ( ! $header || ! str_contains( $header->content, '<!-- wp:navigation ' ) ) {
	WP_CLI::error( 'The header template part, with its navigation block, is missing.' );
}

// 1. The translations, from the English source.
foreach ( $others as $locale ) {
	$language = $data[ $locale ];
	$markup   = $legal_links( $translate( $locale ), $locale );
	$id       = Translations::in( $page, $locale, false );
	if ( ! $id ) {
		$id = Translations::create( $page->ID, $locale, $markup, $language['title'] );
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( "$locale: " . $id->get_error_message() );
		}
	}
	$save( $id, $markup, $language );

	// The menu: the same links as the default one, in this language, to this language's home page.
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
	$nav = array(
		'post_type'     => 'wp_navigation',
		'post_status'   => 'publish',
		'post_title'    => sprintf( 'Navigation (%s)', Languages::label( $locale ) ),
		'post_content'  => trim( $menu ),
		// Older than the default menu, which the header without a menu of its own shows.
		'post_date'     => gmdate( 'Y-m-d H:i:s', strtotime( $default_menu->post_date ) - HOUR_IN_SECONDS ),
		'post_date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( $default_menu->post_date_gmt ) - HOUR_IN_SECONDS ),
	);
	if ( $existing ) {
		$nav['ID'] = $existing[0]->ID;
	}
	$menu_id = wp_insert_post( wp_slash( $nav ), true );
	if ( is_wp_error( $menu_id ) ) {
		WP_CLI::error( "$locale: " . $menu_id->get_error_message() );
	}
	update_post_meta( $menu_id, Translations::LANG, $locale );

	// The header copy, with that menu.
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

// 2. The page itself, in the default language, and the default menu's labels.
$save( $page->ID, $translate( $default ), $data[ $default ] );
$labels = $default_menu->post_content;
foreach ( $data[ $default ]['menu'] as $from => $to ) {
	$labels = str_replace( '"label":' . wp_json_encode( $from, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), '"label":' . wp_json_encode( $to, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $labels );
}
wp_update_post( wp_slash( array( 'ID' => $default_menu->ID, 'post_content' => $labels ) ) );
WP_CLI::log( sprintf( '%s: page %d at %s, menu %d.', $default, $page->ID, get_permalink( $page->ID ), $default_menu->ID ) );

// 3. /register/: its title in each language (the page holds only the signup block).
$register_titles = array(
	'es_ES' => 'Crea tu web',
	'ca'    => 'Crea el teu web',
	'en_US' => 'Create your site',
);
// A translation's slug is unique on the site, so each has its own word (/en/sign-up/, /ca/registre/).
$register_slugs = array(
	'ca'    => 'registre',
	'en_US' => 'sign-up',
);
$register       = get_page_by_path( 'register', OBJECT, 'page' );
if ( $register ) {
	wp_update_post( wp_slash( array( 'ID' => $register->ID, 'post_title' => $register_titles[ $default ] ?? $register->post_title ) ) );
	foreach ( $others as $locale ) {
		$id = Translations::in( $register, $locale, false );
		if ( ! $id ) {
			$id = Translations::create( $register->ID, $locale, $register->post_content, $register_titles[ $locale ] ?? $register->post_title );
			if ( is_wp_error( $id ) ) {
				WP_CLI::error( "/register/ in $locale: " . $id->get_error_message() );
			}
		}
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_status'  => 'publish',
					'post_title'   => $register_titles[ $locale ] ?? $register->post_title,
					'post_name'    => $register_slugs[ $locale ] ?? get_post_field( 'post_name', $id ),
					'post_content' => $register->post_content,
				)
			)
		);
		WP_CLI::log( sprintf( '/register/ in %s: page %d at %s.', $locale, $id, get_permalink( $id ) ) );
	}
} else {
	WP_CLI::warning( 'No /register/ page yet (Mody Members makes it once registration is open): run this again afterwards.' );
}

WP_CLI::success( sprintf( 'The home page is in %s (the default), with %s.', Languages::label( $default ), implode( ' and ', array_map( array( Languages::class, 'label' ), $others ) ) ) );
