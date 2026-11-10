<?php
/**
 * Tests site languages: the mody-languages MU plugin (tickets/site-languages.md).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/languages-test.php
 *
 * It works on the alpha site (alpha.mody.local) and leaves it as it was: it
 * adds Spanish, makes a few test pages (one is the front page for a moment),
 * their translations and a header copy, checks them in PHP and over HTTP,
 * then deletes them and restores the options.
 *
 * 1. The plugin: a MU plugin; the switcher block, hooked after navigation.
 * 2. The languages: the default, the others, only offered ones.
 * 3. Translations: create, refusals, the group, the states.
 * 4. Links: prefixes, the front page's translation, a child under its
 *    parent's translation.
 * 5. Over HTTP: lang and hreflang, redirects of the wrong language, the
 *    language's home page, the switcher, the page list in one language, the
 *    header copy, drafts, and a site with one language unchanged.
 * 6. Menus: a navigation link follows the language, in a menu stored as a
 *    navigation post too; page lists show one language.
 * 7. What the review found: url_to_postid, the query string and page
 *    numbers, the front page's translation, reserved slugs, a removed
 *    language, passwords, the protected meta fields.
 * 8. Settings → Languages: the languages and the matrix, error codes.
 * 9. The working language in wp-admin: the choice, the lists, REST and
 *    the link search, lookups by ID, new pages, the toolbar, the language
 *    bar, the Languages column, the switch, the editors' menu. What its
 *    review found: a switch without a way back, every page in exactly one
 *    list, the Trash unfiltered, links and counts for users who are not
 *    administrators. (Menu links to a translation: section 6.)
 */

use Mody\Languages\AdminLanguage;
use Mody\Languages\Languages;
use Mody\Languages\Menus;
use Mody\Languages\Parts;
use Mody\Languages\Router;
use Mody\Languages\SettingsPage;
use Mody\Languages\Translations;

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-66s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

$alpha = get_sites( array( 'domain__in' => array( 'alpha.mody.local' ), 'number' => 1 ) );
if ( ! $alpha ) {
	WP_CLI::error( 'The alpha site (alpha.mody.local) is missing.' );
}
$site_id = (int) $alpha[0]->blog_id;
switch_to_blog( $site_id );

// What the test changes, restored at the end.
$saved   = array(
	Languages::OPTION => get_option( Languages::OPTION, null ),
	'show_on_front'   => get_option( 'show_on_front' ),
	'page_on_front'   => get_option( 'page_on_front' ),
);
$created = array();
$cleanup = static function () use ( &$created, $saved, $site_id ): void {
	switch_to_blog( $site_id );
	foreach ( $created as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $saved as $name => $value ) {
		null === $value ? delete_option( $name ) : update_option( $name, $value );
	}
	restore_current_blog();
};
register_shutdown_function( $cleanup );

// alpha.mody.local resolves to the app container itself; send its requests to
// the web server, which answers for mody.local.
$web = gethostbyname( 'mody.local' );
add_action(
	'http_api_curl',
	static function ( $handle, $args, $url ) use ( $web ): void {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( str_ends_with( $host, '.mody.local' ) ) {
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':443:' . $web ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		}
	},
	10,
	3
);
$http = static function ( string $url ): array {
	$response = wp_remote_get(
		$url,
		array(
			'sslverify'   => false,
			'redirection' => 0,
			'timeout'     => 30,
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
		)
	);
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $response ),
		'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		'body'     => (string) wp_remote_retrieve_body( $response ),
	);
};
$page = static function ( string $title, string $marker, int $parent = 0 ) use ( &$created ): int {
	$id        = (int) wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => sanitize_title( $title ),
			'post_parent'  => $parent,
			'post_content' => "<!-- wp:paragraph -->\n<p>$marker</p>\n<!-- /wp:paragraph -->",
		)
	);
	$created[] = $id;
	return $id;
};
$publish = static function ( int $id, string $marker ): void {
	wp_update_post(
		array(
			'ID'           => $id,
			'post_status'  => 'publish',
			'post_content' => "<!-- wp:paragraph -->\n<p>$marker</p>\n<!-- /wp:paragraph -->",
		)
	);
};
$home = untrailingslashit( home_url() );

// 1. The plugin ------------------------------------------------------------------

echo "The plugin\n";
$block = WP_Block_Type_Registry::get_instance()->get_registered( 'mody/language-switcher' );
$check( 'a MU plugin (always on, on every site)', isset( get_mu_plugins()['mody-languages.php'] ) && class_exists( Router::class ) );
$check( 'the switcher block, hooked after every navigation block', $block && ( $block->block_hooks['core/navigation'] ?? '' ) === 'after' );

// 2. The languages ---------------------------------------------------------------

echo "The languages\n";
delete_option( Languages::OPTION );
$check( 'one language: not multilingual, default en_US', ! Languages::is_multilingual() && 'en_US' === Languages::default_locale() );
Languages::save( array( 'es_ES', 'xx_XX', 'en_US' ) );
$check( 'only offered languages are kept', array( 'en_US', 'es_ES' ) === get_option( Languages::OPTION ), wp_json_encode( get_option( Languages::OPTION ) ) );
$check( 'the default is never one of the others', array( 'es_ES' ) === Languages::others() );
$check( 'prefix, name and code', 'es' === Languages::slug( 'es_ES' ) && 'Español' === Languages::label( 'es_ES' ) && 'es-ES' === Languages::code( 'es_ES' ) );
$check( 'a prefix leads to its language, only the site\'s', 'es_ES' === Languages::from_slug( 'es' ) && '' === Languages::from_slug( 'fr' ) && '' === Languages::from_slug( 'en' ) );

// 3. Translations ----------------------------------------------------------------

echo "Translations\n";
$front  = $page( 'Lang Test Home', 'LANGTEST-HOME-EN' );
$about  = $page( 'Lang Test About', 'LANGTEST-ABOUT-EN' );
$parent = $page( 'Lang Test Parent', 'LANGTEST-PARENT-EN' );
$child  = $page( 'Lang Test Child', 'LANGTEST-CHILD-EN', $parent );
update_post_meta( $about, '_wp_page_template', 'page-blank' );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $front );

$about_es = Translations::create( $about, 'es_ES' );
$created[] = is_int( $about_es ) ? $about_es : 0;
$check( 'create: a draft copy with its language and original', is_int( $about_es ) && 'draft' === get_post_status( $about_es ) && 'es_ES' === Translations::language( $about_es ) && $about === Translations::source( $about_es ) );
$check( '... the slug carries the language, the template is copied', 'lang-test-about-es' === get_post_field( 'post_name', $about_es ) && 'page-blank' === get_post_meta( $about_es, '_wp_page_template', true ) );
$check( '... a second translation into the same language is refused', is_wp_error( Translations::create( $about, 'es_ES' ) ) );
$check( '... a language the site does not have is refused', is_wp_error( Translations::create( $about, 'fr_FR' ) ) );
$check( 'the group, the original first; only published on request', array( 'en_US' => $about, 'es_ES' => $about_es ) === Translations::group( $about_es ) && array( 'en_US' => $about ) === Translations::group( $about, true ) );
$check( 'state: draft', 'draft' === Translations::state( $about_es ) );
$publish( $about_es, 'LANGTEST-ABOUT-ES' );
$check( 'state: translated once published', 'translated' === Translations::state( $about_es ) );
global $wpdb;
$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ), array( 'ID' => $about ) );
clean_post_cache( $about );
$check( 'state: outdated when the original is saved later', 'outdated' === Translations::state( $about_es ) );

$front_es  = Translations::create( $front, 'es_ES' );
$parent_es = Translations::create( $parent, 'es_ES' );
$child_es  = Translations::create( $child, 'es_ES' );
array_push( $created, (int) $front_es, (int) $parent_es, (int) $child_es );
$publish( (int) $front_es, 'LANGTEST-HOME-ES' );
$publish( (int) $parent_es, 'LANGTEST-PARENT-ES' );
$check( 'a child goes under its parent\'s translation', (int) $parent_es === (int) get_post_field( 'post_parent', $child_es ) );

// 4. Links -----------------------------------------------------------------------

echo "Links\n";
$check( 'a translation\'s address carries its prefix', $home . '/es/lang-test-about-es/' === get_permalink( $about_es ), (string) get_permalink( $about_es ) );
$check( 'the original keeps its address', $home . '/lang-test-about/' === get_permalink( $about ) );
$check( 'the front page\'s translation is /es/', $home . '/es/' === get_permalink( $front_es ), (string) get_permalink( $front_es ) );

// 5. Over HTTP -------------------------------------------------------------------

echo "Over HTTP\n";
flush_rewrite_rules( false );
$out = $http( $home . '/es/lang-test-about-es/' );
$check( '/es/… : the translation, lang="es-ES"', 200 === $out['code'] && str_contains( $out['body'], 'LANGTEST-ABOUT-ES' ) && (bool) preg_match( '/<html[^>]*lang="es-ES"/', $out['body'] ), (string) $out['code'] );
$check( '... hreflang for both languages and x-default', str_contains( $out['body'], 'hreflang="en-US" href="' . $home . '/lang-test-about/"' ) && str_contains( $out['body'], 'hreflang="es-ES" href="' . $home . '/es/lang-test-about-es/"' ) && str_contains( $out['body'], 'hreflang="x-default"' ) );
$check( '... the switcher: English leads to the original, Español is current', (bool) preg_match( '#wp-block-mody-language-switcher.*href="' . preg_quote( $home, '#' ) . '/lang-test-about/"[^>]*><span[^>]*>English<.*aria-current="page"><span[^>]*>Español<#s', $out['body'] ) );
$check( '... the page list shows the Spanish pages only', str_contains( $out['body'], 'href="' . $home . '/es/lang-test-parent-es/"' ) && ! str_contains( $out['body'], 'href="' . $home . '/lang-test-parent/"' ) );
$out = $http( $home . '/lang-test-about-es/' );
$check( 'a translation without its prefix: 301 to its address', 301 === $out['code'] && $home . '/es/lang-test-about-es/' === $out['location'], $out['code'] . ' ' . $out['location'] );
$out = $http( $home . '/es/lang-test-about/' );
$check( 'an original under a prefix: 301 to its address', 301 === $out['code'] && $home . '/lang-test-about/' === $out['location'], $out['code'] . ' ' . $out['location'] );
$out = $http( $home . '/es/' );
$check( '/es/: the front page\'s translation', 200 === $out['code'] && str_contains( $out['body'], 'LANGTEST-HOME-ES' ), (string) $out['code'] );
$out = $http( $home . '/' );
$check( '/: the original front page, lang="en-US", the switcher', 200 === $out['code'] && str_contains( $out['body'], 'LANGTEST-HOME-EN' ) && (bool) preg_match( '/<html[^>]*lang="en-US"/', $out['body'] ) && str_contains( $out['body'], 'href="' . $home . '/es/" lang="es-ES"' ) );
$out = $http( $home . '/es/lang-test-parent-es/lang-test-child-es/' );
$check( 'a draft translation is not public', 404 === $out['code'], (string) $out['code'] );

$copy      = Parts::create( 'header', 'es_ES' );
$created[] = is_int( $copy ) ? $copy : 0;
if ( is_int( $copy ) ) {
	wp_update_post( array( 'ID' => $copy, 'post_content' => "<!-- wp:paragraph -->\n<p>LANGTEST-HEADER-ES</p>\n<!-- /wp:paragraph -->" ) );
}
$check( 'a header copy for Spanish', is_int( $copy ) && null !== Parts::find( 'header-es' ) && is_wp_error( Parts::create( 'header', 'es_ES' ) ) );
$es = $http( $home . '/es/lang-test-about-es/' );
$en = $http( $home . '/lang-test-about/' );
$check( '... shown on Spanish pages only', str_contains( $es['body'], 'LANGTEST-HEADER-ES' ) && ! str_contains( $en['body'], 'LANGTEST-HEADER-ES' ) );

$main = $http( 'https://beta.mody.local/' ); // A site with one language (the main site has four since 2026-11-10).
$check( 'a site with one language is unchanged: no switcher, no hreflang', 200 === $main['code'] && ! str_contains( $main['body'], '<nav class="wp-block-mody-language-switcher' ) && ! str_contains( $main['body'], 'hreflang=' ) );

// 6. Menus -----------------------------------------------------------------------

echo "Menus\n";
$attrs = array(
	'id'    => $about,
	'kind'  => 'post-type',
	'type'  => 'page',
	'label' => 'Lang Test About',
	'url'   => get_permalink( $about ),
);
wp_update_post( array( 'ID' => $about_es, 'post_title' => 'Sobre nosotros' ) );
$menu      = (int) wp_insert_post(
	array(
		'post_type'    => 'wp_navigation',
		'post_status'  => 'publish',
		'post_title'   => 'Lang Test Menu',
		'post_content' => '<!-- wp:navigation-link ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->',
	)
);
$created[] = $menu;
Router::set_current( 'es_ES' );
$es_attrs = Menus::translate_attrs( $attrs );
$custom   = Menus::translate_attrs( array_merge( $attrs, array( 'label' => 'Who we are' ) ) );
$stored   = do_blocks( '<!-- wp:navigation {"ref":' . $menu . '} /-->' );
Router::set_current( '' );
$check( 'a menu link leads to the translation, with its title', $about_es === $es_attrs['id'] && $home . '/es/lang-test-about-es/' === $es_attrs['url'] && 'Sobre nosotros' === $es_attrs['label'] );
$check( '... a label of its own is kept', 'Who we are' === $custom['label'] && $home . '/es/lang-test-about-es/' === $custom['url'] );
$check( '... a menu stored as a navigation post too', str_contains( $stored, 'href="' . $home . '/es/lang-test-about-es/"' ) && str_contains( $stored, '>Sobre nosotros<' ) && ! str_contains( $stored, '/lang-test-about/"' ) );
$check( '... nothing changes in the default language', null === Menus::translate_attrs( $attrs ) && str_contains( do_blocks( '<!-- wp:navigation {"ref":' . $menu . '} /-->' ), 'href="' . $home . '/lang-test-about/"' ) );
$back = Menus::translate_attrs( array_merge( $attrs, array( 'id' => $about_es, 'url' => (string) get_permalink( $about_es ), 'label' => 'Sobre nosotros' ) ) );
$check( '... and a link to the translation leads default-language visitors to the original', $about === ( $back['id'] ?? 0 ) && $home . '/lang-test-about/' === ( $back['url'] ?? '' ) && 'Lang Test About' === ( $back['label'] ?? '' ) );
Router::set_current( 'es_ES' );
$others = Menus::pages_in_language( array( get_post( $about ), get_post( $about_es ) ), array( 'post_type' => 'page' ) );
$cpt    = Menus::pages_in_language( array( get_post( $about ) ), array( 'post_type' => 'mody_doc' ) );
Router::set_current( '' );
$check( 'page lists: the request\'s language only; other post types untouched', array( $about_es ) === wp_list_pluck( $others, 'ID' ) && 1 === count( $cpt ) );

// 7. What the review found (2026-11-10) -------------------------------------------

echo "Review fixes\n";
Languages::save( array() );
$one = Mody\Languages\Switcher::hook_only_when_multilingual( array( 'mody/language-switcher', 'x/y' ) );
Languages::save( array( 'es_ES' ) );
$two = Mody\Languages\Switcher::hook_only_when_multilingual( array( 'mody/language-switcher', 'x/y' ) );
$check( 'the switcher is hooked into headers only with two languages or more', array( 'x/y' ) === $one && array( 'mody/language-switcher', 'x/y' ) === $two );
$check( 'a translation is found from its address (url_to_postid)', $about_es === url_to_postid( $home . '/es/lang-test-about-es/' ) );
$out = $http( $home . '/lang-test-about-es/?utm_source=test' );
$check( 'the wrong-language redirect keeps the query string', 301 === $out['code'] && $home . '/es/lang-test-about-es/?utm_source=test' === $out['location'], $out['location'] );
$out = $http( $home . '/es/lang-test-home-es/' );
$check( 'the front page\'s translation by its slug: 301 to /es/', 301 === $out['code'] && $home . '/es/' === $out['location'], $out['code'] . ' ' . $out['location'] );
wp_update_post( array( 'ID' => (int) $front_es, 'post_content' => "<!-- wp:paragraph -->\n<p>LANGTEST-HOME-ES</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:nextpage -->\n<!--nextpage-->\n<!-- /wp:nextpage -->\n\n<!-- wp:paragraph -->\n<p>LANGTEST-HOME-ES-PAGE2</p>\n<!-- /wp:paragraph -->" ) );
$out = $http( $home . '/es/2/' );
$check( '/es/2/: the second page of the front page\'s translation', 200 === $out['code'] && str_contains( $out['body'], 'LANGTEST-HOME-ES-PAGE2' ), (string) $out['code'] );
$out = $http( $home . '/es/' );
$check( '/es/ is a front page: the site\'s title and tagline, the home class', (bool) preg_match( '/<body[^>]*class="[^"]*\bhome\b/', $out['body'] ) && (bool) preg_match( '#<title>' . preg_quote( esc_html( get_bloginfo( 'name' ) ), '#' ) . '#', $out['body'] ) );
$check( 'a new top-level page cannot take a language prefix as its slug', 'es' !== wp_unique_post_slug( 'es', 0, 'publish', 'page', 0 ) && 'es' === wp_unique_post_slug( 'es', 0, 'publish', 'page', $parent ) );
$taken     = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'FR', 'post_content' => 'LANGTEST-FR-PAGE' ) );
$created[] = $taken;
global $wpdb;
$wpdb->update( $wpdb->posts, array( 'post_name' => 'fr' ), array( 'ID' => $taken ) ); // As if made before French was added.
clean_post_cache( $taken );
Languages::save( array( 'es_ES', 'fr_FR' ) );
$out = $http( $home . '/fr/' );
$check( '... an older page with that slug keeps its address', 200 === $out['code'] && str_contains( $out['body'], 'LANGTEST-FR-PAGE' ), (string) $out['code'] );
Languages::save( array( 'es_ES', 'it_IT' ) );
$fr = Translations::create( $about, 'it_IT' );
$created[] = (int) $fr;
wp_update_post( array( 'ID' => (int) $fr, 'post_status' => 'publish' ) );
Languages::save( array( 'es_ES' ) );
$out = $http( $home . '/lang-test-about-it/' );
$check( 'a page in a language the site no longer has: served, no redirect loop', 200 === $out['code'], $out['code'] . ' ' . $out['location'] );
$protected = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Lang Test Locked', 'post_password' => 'secret' ) );
$created[] = (int) $protected;
$locked    = Translations::create( (int) $protected, 'es_ES' );
$created[] = (int) $locked;
$check( 'a copy keeps the password; a draft original gives a slug from its title', 'secret' === get_post_field( 'post_password', $locked ) && 'lang-test-locked-es' === get_post_field( 'post_name', $locked ) );
$meta = get_registered_meta_keys( 'post', 'page' );
$check( 'the meta fields: protected, not in REST', is_protected_meta( Translations::LANG, 'post' ) && is_protected_meta( Translations::SOURCE, 'post' ) && empty( $meta[ Translations::LANG ]['show_in_rest'] ) && empty( $meta[ Translations::SOURCE ]['show_in_rest'] ) );
$check( '... an unknown language is not stored', '' === Translations::sanitize_language( 'xx_XX' ) && 'es_ES' === Translations::sanitize_language( 'es_ES' ) );

// 8. Settings → Languages ------------------------------------------------------------

echo "Settings → Languages\n";
$supers = get_super_admins();
wp_set_current_user( (int) get_user_by( 'login', $supers[0] )->ID );
ob_start();
SettingsPage::render();
$screen = (string) ob_get_clean();
$check( 'the languages: Spanish checked, the default not offered', (bool) preg_match( '/value="es_ES"\s+checked/', $screen ) && ! str_contains( $screen, 'value="en_US"' ) );
$check( 'the matrix: rows for the pages, header and footer', str_contains( $screen, 'Lang Test About' ) && str_contains( $screen, 'template part' ) && str_contains( $screen, 'mody-languages-matrix' ) );
$check( '... states: outdated, missing with Create', str_contains( $screen, 'is-outdated' ) && str_contains( $screen, 'action=mody_languages_create' ) );
$_GET['error'] = 'Your subscription is suspended';
ob_start();
SettingsPage::render();
$spoofed       = (string) ob_get_clean();
$_GET['error'] = 'exists';
ob_start();
SettingsPage::render();
$known = (string) ob_get_clean();
unset( $_GET['error'] );
$check( 'errors come from a fixed list: text in the address is never shown', ! str_contains( $spoofed, 'suspended' ) && ! str_contains( $spoofed, 'notice-error' ) && str_contains( $known, 'That translation already exists.' ) );
wp_set_current_user( 0 );

// 9. The working language in wp-admin ------------------------------------------

echo "The working language in wp-admin\n";
$admin = (int) get_user_by( 'login', $supers[0] )->ID;
register_shutdown_function( static fn() => delete_user_option( $admin, AdminLanguage::OPTION ) );
wp_set_current_user( $admin );
delete_user_option( $admin, AdminLanguage::OPTION );
$check( 'a new user works in the default language', 'en_US' === AdminLanguage::current() && AdminLanguage::filters() );
$check( 'only a site language or "all" can be chosen', ! AdminLanguage::set( 'fr_FR' ) && AdminLanguage::set( 'es_ES' ) && 'es_ES' === AdminLanguage::current() );

$titles = static function ( string $route, array $params = array() ): array {
	$request = new WP_REST_Request( 'GET', $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$data = rest_do_request( $request )->get_data();
	return is_array( $data ) ? array_map( static fn( $item ) => is_array( $item ) ? ( $item['title']['raw'] ?? $item['title']['rendered'] ?? $item['title'] ?? '' ) : '', $data ) : array();
};
$es_list = $titles( '/wp/v2/pages', array( 'context' => 'edit', 'per_page' => 100, 'status' => 'any' ) );
$check( 'REST pages (the Site Editor\'s list): Spanish only', in_array( 'Sobre nosotros', $es_list, true ) && ! in_array( 'Lang Test About', $es_list, true ), implode( ', ', $es_list ) );
$search = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/search' ) );
$request = new WP_REST_Request( 'GET', '/wp/v2/search' );
$request->set_param( 'search', 'Lang Test' );
$found = array_map( 'intval', wp_list_pluck( (array) rest_do_request( $request )->get_data(), 'id' ) );
$check( '... the link search too', array() !== $found && array() === array_filter( $found, static fn( int $id ): bool => 'es_ES' !== Translations::language( $id ) ), implode( ', ', $found ) );
$by_id = $titles( '/wp/v2/pages', array( 'include' => array( $about ), 'context' => 'edit' ) );
$check( '... a lookup by ID is never filtered', array( 'Lang Test About' ) === $by_id );
AdminLanguage::set( AdminLanguage::ALL );
$all_list = $titles( '/wp/v2/pages', array( 'context' => 'edit', 'per_page' => 100, 'status' => 'any' ) );
$check( '"All languages" shows both', in_array( 'Sobre nosotros', $all_list, true ) && in_array( 'Lang Test About', $all_list, true ) );
wp_set_current_user( 0 );
$public = $titles( '/wp/v2/pages', array( 'per_page' => 100 ) );
wp_set_current_user( $admin );
$check( 'visitors\' REST requests are never filtered', in_array( 'Lang Test About', $public, true ) );

AdminLanguage::set( 'es_ES' );
global $pagenow, $wp_the_query;
$saved_pagenow = $pagenow;
$saved_main    = $wp_the_query;
$pagenow       = 'edit.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.
set_current_screen( 'edit-page' );
$list         = new WP_Query();
$wp_the_query = $list; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.
$list->query( array( 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => -1 ) );
$check( 'the Pages list: Spanish only', in_array( $about_es, wp_list_pluck( $list->posts, 'ID' ), true ) && ! in_array( $about, wp_list_pluck( $list->posts, 'ID' ), true ) );
$new       = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Lang Test Nueva' ) );
$created[] = $new;
$check( 'a page created while Spanish is chosen is Spanish (standalone)', 'es_ES' === Translations::language( $new ) && $new === Translations::source( $new ) );
$pagenow      = $saved_pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$wp_the_query = $saved_main; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$plain     = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Lang Test CLI' ) );
$created[] = $plain;
$check( '... but not one created outside wp-admin and REST (WP-CLI, code)', '' === get_post_meta( $plain, Translations::LANG, true ) );

// Over HTTP, as the super admin: the toolbar, the language bar, the column, the switch.
$expiration = time() + HOUR_IN_SECONDS;
$token      = WP_Session_Tokens::get_instance( $admin )->create( $expiration );
register_shutdown_function( static fn() => WP_Session_Tokens::get_instance( $admin )->destroy( $token ) );
$cookies = array(
	SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $admin, $expiration, 'secure_auth', $token ),
	LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $admin, $expiration, 'logged_in', $token ),
);
$admin_get = static function ( string $url ) use ( $cookies ): array {
	$response = wp_remote_get( $url, array( 'cookies' => $cookies, 'sslverify' => false, 'redirection' => 0, 'timeout' => 30 ) );
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $response ),
		'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		'body'     => (string) wp_remote_retrieve_body( $response ),
	);
};
$list_url = admin_url( 'edit.php?post_type=page' );
$out      = $admin_get( $list_url );
$check( 'wp-admin: the toolbar says Language: Español', 200 === $out['code'] && str_contains( $out['body'], 'Language: Español' ), (string) $out['code'] );
$check( '... the language bar with counts', (bool) preg_match( '#mody-admin-languages.*English <span class="count">\(\d+\)</span>.*aria-current="page">Español <span class="count">\(\d+\)</span>#s', $out['body'] ) );
$check( '... the list shows Spanish pages, with the Languages column', str_contains( $out['body'], 'Sobre nosotros' ) && ! str_contains( $out['body'], '>Lang Test About<' ) && str_contains( $out['body'], 'column-mody_languages' ) );
$_COOKIE[ LOGGED_IN_COOKIE ] = $cookies[ LOGGED_IN_COOKIE ];
$switch = AdminLanguage::switch_url( 'en_US', $list_url . '&paged=2' );
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
$out = $admin_get( $switch );
wp_cache_delete( $admin, 'user_meta' );
$check( 'switching: saved, back to the list (first page)', 302 === $out['code'] && $list_url === $out['location'] && 'en_US' === get_user_option( AdminLanguage::OPTION, $admin ), $out['code'] . ' ' . $out['location'] );
$out = $admin_get( $list_url );
$check( '... now the English pages, with "ES ✓" and "ES +" in the column', str_contains( $out['body'], '>Lang Test About<' ) && ! str_contains( $out['body'], 'Sobre nosotros</a>' ) && str_contains( $out['body'], 'ES ✓' ) && str_contains( $out['body'], 'ES +' ) );
$bad = $admin_get( add_query_arg( array( 'action' => 'mody_admin_language', 'lang' => 'es_ES' ), admin_url( 'admin-post.php' ) ) );
$check( '... a switch without its nonce is refused', 302 !== $bad['code'] || ! str_contains( $bad['location'], 'edit.php' ) );
do_action( 'enqueue_block_editor_assets' );
$check( 'the editors get the Language entries for their ⋮ menu', wp_script_is( AdminLanguage::SCRIPT, 'enqueued' ) );

// What the review of the working language found.
$_COOKIE[ LOGGED_IN_COOKIE ] = $cookies[ LOGGED_IN_COOKIE ];
$no_back = AdminLanguage::switch_url( 'es_ES' );
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
$out = $admin_get( $no_back );
$check( 'a switch without a way back goes to the dashboard (not a blank page)', 302 === $out['code'] && admin_url() === $out['location'], $out['code'] . ' ' . $out['location'] );
wp_cache_delete( $admin, 'user_meta' );

// Every page is in exactly one list: a default-language tag, or a language the site does not have, is the default list.
$tagged = array();
foreach ( array( 'en_US', 'fr_FR' ) as $tag ) {
	$id        = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Lang Test Tagged ' . $tag ) );
	$created[] = $id;
	$tagged[]  = $id;
	update_post_meta( $id, Translations::LANG, $tag );
}
$ids = static function ( string $lang ): array {
	AdminLanguage::set( $lang );
	$request = new WP_REST_Request( 'GET', '/wp/v2/pages' );
	$request->set_query_params( array( 'context' => 'edit', 'per_page' => 100, 'status' => 'any' ) );
	return array_map( 'intval', wp_list_pluck( (array) rest_do_request( $request )->get_data(), 'id' ) );
};
$en_ids = $ids( 'en_US' );
$es_ids = $ids( 'es_ES' );
$counts = AdminLanguage::counts( 'page' );
$check( 'a page tagged with the default language or a removed one is in the default list only', array() === array_diff( $tagged, $en_ids ) && array() === array_intersect( $tagged, $es_ids ) && 'en_US' === AdminLanguage::list_of( $tagged[1] ) );
$check( '... and the counts add up to "All languages"', array_sum( array_diff_key( $counts, array( AdminLanguage::ALL => 0 ) ) ) === $counts[ AdminLanguage::ALL ], wp_json_encode( $counts ) );

// The Trash is not filtered: its Empty Trash button deletes every language's.
$trashed   = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Lang Test Trashed' ) );
$created[] = $trashed;
wp_trash_post( $trashed );
AdminLanguage::set( 'es_ES' );
$pagenow      = 'edit.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.
$trash        = new WP_Query();
$wp_the_query = $trash; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.
$trash->query( array( 'post_type' => 'page', 'post_status' => 'trash', 'posts_per_page' => -1 ) );
$pagenow      = $saved_pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$wp_the_query = $saved_main; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$check( 'the Trash shows every language (Empty Trash deletes them all)', in_array( $trashed, wp_list_pluck( $trash->posts, 'ID' ), true ) );

// Users who are not administrators: no link to Settings → Languages, no create links they cannot use, no private counts.
require_once ABSPATH . 'wp-admin/includes/ms.php';
$people = array();
foreach ( array( 'author', 'contributor' ) as $role ) {
	$people[ $role ] = (int) wpmu_create_user( 'langtest' . $role . wp_rand( 1000, 9999 ), wp_generate_password(), 'langtest' . $role . wp_rand( 1000, 9999 ) . '@mody.local' );
	add_user_to_blog( $site_id, $people[ $role ], $role );
}
register_shutdown_function(
	static function () use ( $people ): void {
		foreach ( $people as $id ) {
			wpmu_delete_user( $id );
		}
	}
);
set_current_screen( 'edit-page' );
$toolbar = static function ( int $user ): string {
	wp_set_current_user( $user );
	require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
	$bar = new WP_Admin_Bar();
	AdminLanguage::toolbar( $bar );
	$node = $bar->get_node( 'mody-admin-language' );
	return $node ? (string) $node->href : 'none';
};
$check( 'the toolbar\'s Language item links to Settings → Languages for administrators only', SettingsPage::url() === $toolbar( $admin ) && '' === $toolbar( $people['author'] ) );
$column = static function ( int $user, int $post ): string {
	wp_set_current_user( $user );
	ob_start();
	AdminLanguage::column( 'mody_languages', $post );
	return (string) ob_get_clean();
};
$lone      = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Lang Test Lone', 'post_author' => $admin ) );
$created[] = $lone;
$check( 'the column: an administrator gets create links', str_contains( $column( $admin, $lone ), 'ES +</a>' ) );
$check( '... a contributor neither create links nor edit links to pages they cannot edit', ! str_contains( $column( $people['contributor'], $lone ), '<a ' ) && ! str_contains( $column( $people['contributor'], $about ), '<a ' ) && str_contains( $column( $people['contributor'], $about ), 'ES ✓</span>' ) );
$private   = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'private', 'post_title' => 'Lang Test Private', 'post_author' => $admin ) );
$created[] = $private;
wp_set_current_user( $people['author'] );
$author_counts = AdminLanguage::counts( 'page' );
wp_set_current_user( $admin );
$admin_counts = AdminLanguage::counts( 'page' );
$check( 'the counts leave out other authors\' private pages for those who cannot read them', $admin_counts['en_US'] === $author_counts['en_US'] + 1, $admin_counts['en_US'] . ' / ' . $author_counts['en_US'] );
$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
AdminLanguage::set( 'en_US' );
wp_set_current_user( 0 );

restore_current_blog();
echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
