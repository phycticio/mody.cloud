<?php
/**
 * Tests Mody SEO (the `mody-seo` MU plugin: a page's SEO title and SEO
 * description) and the agent's tool for them, mody/update-seo
 * (tickets/seo.md, 2026-11-14 and 2026-11-16).
 *
 * Run from the repository root, on a member site, with INFOMANIAK_* set in .env:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://alpha.mody.local eval-file - < scripts/seo-test.php
 *
 * 1. The plugin: a MU plugin on its own, carried by Take your site with you;
 *    the two fields on pages and posts, in REST (the sidebar saves them
 *    there), cleaned, capped, only for who edits the page; the sidebar loaded
 *    in the editors, the `ai` plugin's panel removed.
 * 2. The head: the SEO title and description; without them WordPress's
 *    title and the excerpt; the posts page, a translation's copied excerpt,
 *    the `ai` plugin's field and tag ignored, password-protected pages; over
 *    HTTP.
 * 3. The ability: by ID and by title, pages and posts, back to automatic,
 *    the limits, every refusal, permissions.
 * 4. Generate, with fake model answers: the `ai` plugin's ability writes it;
 *    a failure and an empty page change nothing.
 * 5. The agent, fake answers: offered only with the editor open (the tools'
 *    size measured both ways), the open page's search appearance and the
 *    home page in the prompt, the editor told to reload, held on someone
 *    else's page, left out of the history without the editor.
 * 6. The agent, for real (Qwen on Infomaniak): suggest, write a description,
 *    set a title, another page by title. `-e MODY_TEST_RUNS=3` repeats,
 *    `-e MODY_TEST_OFFLINE=1` skips.
 *
 * Pages, posts, users and agent events made here are removed at the end, the
 * reading settings and the user's conversation restored. Refuses to run in
 * production or on the main site.
 */

use Mody\Platform\Agent\Agent;
use Mody\Platform\Agent\AgentEvents;
use Mody\Platform\Agent\EditorContext;
use Mody\Platform\AI\SystemPrompt;
use Mody\Platform\Export\Portable;
use Mody\Platform\Site\SeoTool;
use Mody\Seo\Fields;
use Mody\Seo\Head;
use Mody\Seo\Panel;

if ( 'production' === wp_get_environment_type() ) {
	echo "Refusing to run in production.\n";
	return;
}
if ( is_main_site() ) {
	echo "Run it on a member site (--url=https://alpha.mody.local): the `ai` plugin's features are a member site's.\n";
	return;
}
require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-62s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};
$message  = static fn( $result ): string => is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '';

global $wpdb;
$site   = get_current_blog_id();
$suffix = strtolower( wp_generate_password( 5, false ) );
$admin  = (int) wpmu_create_user( "modyseo{$suffix}", wp_generate_password( 24 ), "modyseo{$suffix}@mody.local" );
$author = (int) wpmu_create_user( "modyseoa{$suffix}", wp_generate_password( 24 ), "modyseoa{$suffix}@mody.local" );
add_user_to_blog( $site, $admin, 'administrator' );
add_user_to_blog( $site, $author, 'author' );
wp_set_current_user( $admin );
AgentEvents::install();
$events  = AgentEvents::table();
$mark    = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$events}" ); // phpcs:ignore WordPress.DB
$reading = array();
foreach ( array( 'show_on_front', 'page_on_front', 'page_for_posts' ) as $option ) {
	$reading[ $option ] = get_option( $option );
}
$created = array();
$make    = static function ( string $title, array $more = array() ) use ( &$created, $suffix, $admin ): int {
	$id        = (int) wp_insert_post(
		wp_slash(
			$more + array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title . ' ' . $suffix,
				'post_content' => "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">{$title}</h1>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>We repair city bikes in Bilbao the same day, with parts from local makers. Bring it in before ten and ride it home after five.</p>\n<!-- /wp:paragraph -->",
				'post_author'  => $admin,
			)
		)
	);
	$created[] = $id;
	return $id;
};
register_shutdown_function(
	static function () use ( &$created, $reading, $admin, $author, $events, $mark ): void {
		global $wpdb;
		foreach ( $created as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $reading as $option => $value ) {
			update_option( $option, $value );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE id > %d", $mark ) ); // phpcs:ignore WordPress.DB
		Agent::forget_held();
		delete_transient( 'mody_agent_chat_' . $admin );
		wpmu_delete_user( $admin );
		wpmu_delete_user( $author );
	}
);
$update = static fn( array $input ) => wp_get_ability( 'mody/update-seo' )->execute( $input );

// Model requests, recorded and answered with fakes (null: real requests). A string is a
// text answer, an array a list of tool calls (name, args), an int an HTTP error status.
$requests = array();
$fakes    = null;
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) use ( &$requests, &$fakes ) {
		if ( ! str_ends_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '/chat/completions' ) ) {
			return $preempt;
		}
		$requests[] = json_decode( (string) $args['body'], true );
		if ( null === $fakes ) {
			return $preempt;
		}
		$fake   = array_shift( $fakes );
		$status = is_int( $fake ) ? $fake : 200;
		$answer = array(
			'role'    => 'assistant',
			'content' => is_string( $fake ) ? $fake : null,
		);
		foreach ( is_array( $fake ) ? $fake : array() as $i => $call ) {
			$answer['tool_calls'][] = array(
				'id'       => 'call_' . count( $requests ) . '_' . $i,
				'type'     => 'function',
				'function' => array(
					'name'      => $call[0],
					'arguments' => wp_json_encode( (object) $call[1] ),
				),
			);
		}
		$body = 200 === $status
			? array(
				'id'      => 'fake-' . count( $requests ),
				'object'  => 'chat.completion',
				'created' => time(),
				'model'   => 'fake',
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => $answer,
						'finish_reason' => is_array( $fake ) ? 'tool_calls' : 'stop',
					),
				),
				'usage'   => array(
					'prompt_tokens'     => 1,
					'completion_tokens' => 1,
					'total_tokens'      => 2,
				),
			)
			: array( 'error' => array( 'message' => 'Fake server error' ) );
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => 200 === $status ? 'OK' : 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	5,
	3
);

// A request for a page, as the front end sees it.
$visit = static function ( string $url ): void {
	global $wp, $wp_query, $wp_the_query;
	$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
	parse_str( $query, $vars );
	$wp_query     = new WP_Query( $vars ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	$wp_the_query = $wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	$wp->query_vars = $vars;
};
$head = static function (): string {
	ob_start();
	Head::print_description();
	return (string) ob_get_clean();
};

// 1. The plugin ---------------------------------------------------------------------------

echo "The plugin\n";
$dir = WPMU_PLUGIN_DIR . '/mody-seo';
$check( 'a MU plugin of its own (always on)', isset( get_mu_plugins()['mody-seo.php'] ) && class_exists( Fields::class ) && ! file_exists( WPMU_PLUGIN_DIR . '/mody-platform/src/Site/Seo.php' ) );
$named = array();
foreach ( array_merge( array( $dir . '/mody-seo.php' ), glob( $dir . '/src/*.php' ) ) as $file ) {
	$named = array_merge( $named, Portable::class_names( (string) file_get_contents( $file ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}
$foreign = array_filter( array_unique( $named ), static fn( string $name ): bool => str_starts_with( ltrim( $name, '\\' ), 'Mody\\' ) && ! str_starts_with( ltrim( $name, '\\' ), 'Mody\\Seo\\' ) && 'Mody\\Languages\\Translations' !== ltrim( $name, '\\' ) );
$check( '... names no other Mody class (Translations only if there)', ! $foreign, implode( ', ', $foreign ) );
$check( '... the downloaded site carries it, active', 'mody-seo.php' === ( Portable::PLUGINS['mody-seo'] ?? '' ) && in_array( 'mody-seo/mody-seo.php', Portable::active_plugins(), true ) );
$check( 'the fields on pages and posts, not on media', array( 'post', 'page' ) === array_values( array_intersect( array( 'post', 'page' ), Fields::post_types() ) ) && ! in_array( 'attachment', Fields::post_types(), true ) );
$registered = get_registered_meta_keys( 'post', 'page' );
$check( '... registered, in REST', ! empty( $registered[ Fields::TITLE ]['show_in_rest'] ) && ! empty( $registered[ Fields::DESCRIPTION ]['show_in_rest'] ) && isset( get_registered_meta_keys( 'post', 'post' )[ Fields::DESCRIPTION ] ) );
$check( '... hidden from the Custom Fields box', is_protected_meta( Fields::TITLE, 'post' ) && is_protected_meta( Fields::DESCRIPTION, 'post' ) );
$check( '... pages have no excerpt panel (the SEO description is the field)', ! post_type_supports( 'page', 'excerpt' ) );

$about = $make( 'About' );
$rest  = static function ( int $id, array $meta, string $type = 'pages' ) {
	$request = new WP_REST_Request( 'POST', "/wp/v2/{$type}/{$id}" );
	$request->set_body_params( array( 'meta' => $meta ) );
	return rest_do_request( $request );
};
$saved = $rest( $about, array( Fields::TITLE => '  Bike repairs   in Bilbao | Test ', Fields::DESCRIPTION => "Same-day city bike repairs in <b>Bilbao</b>,\n with parts from local makers." ) );
$check( 'the sidebar\'s save (REST): saved, one line, no tags', 200 === $saved->get_status() && 'Bike repairs in Bilbao | Test' === get_post_meta( $about, Fields::TITLE, true ) && 'Same-day city bike repairs in Bilbao, with parts from local makers.' === get_post_meta( $about, Fields::DESCRIPTION, true ), $saved->get_status() . ' ' . get_post_meta( $about, Fields::DESCRIPTION, true ) );
$check( '... and read back', 'Bike repairs in Bilbao | Test' === ( $saved->get_data()['meta'][ Fields::TITLE ] ?? '' ) );
$capped = $make( 'Capped' );
$rest( $capped, array( Fields::TITLE => str_repeat( 'x', 200 ), Fields::DESCRIPTION => str_repeat( 'y', 400 ) ) );
$check( '... capped at 120 and 300', Fields::TITLE_MAX === mb_strlen( get_post_meta( $capped, Fields::TITLE, true ) ) && Fields::DESCRIPTION_MAX === mb_strlen( get_post_meta( $capped, Fields::DESCRIPTION, true ) ) );
wp_set_current_user( $author );
$denied = $rest( $about, array( Fields::TITLE => 'Hijacked' ) );
$check( '... an author on someone else\'s page: refused', 'Bike repairs in Bilbao | Test' === get_post_meta( $about, Fields::TITLE, true ), (string) $denied->get_status() );
wp_set_current_user( $admin );

set_current_screen( 'page' );
wp_register_script( 'ai_meta_description', 'about:blank', array(), '1', true ); // The `ai` plugin's panel, as it loads.
wp_enqueue_script( 'ai_meta_description' );
Panel::enqueue();
Panel::drop_ai_panel();
$inline = (string) implode( '', (array) wp_scripts()->get_data( Panel::HANDLE, 'before' ) );
$check( 'the sidebar: loaded in the post editor', wp_script_is( Panel::HANDLE, 'enqueued' ) && str_contains( $inline, '"postTypes"' ) && str_contains( $inline, '"descriptionKey":"' . Fields::DESCRIPTION . '"' ) );
$check( '... the ai plugin\'s Meta Description panel removed', ! wp_script_is( 'ai_meta_description', 'enqueued' ) );
wp_dequeue_script( Panel::HANDLE );
set_current_screen( 'widgets' );
Panel::enqueue();
$check( '... not in the widgets editor', ! wp_script_is( Panel::HANDLE, 'enqueued' ) );
set_current_screen( 'front' );

// 2. The head ----------------------------------------------------------------------------

echo "\nThe head\n";
$visit( '?page_id=' . $about );
$check( 'the SEO description is the description', '<meta name="description" content="Same-day city bike repairs in Bilbao, with parts from local makers." />' . "\n" === $head(), trim( $head() ) );
$check( 'the SEO title is the whole <title>', 'Bike repairs in Bilbao | Test' === Head::title( '' ) );
$plain = $make( 'Plain', array( 'post_excerpt' => 'An excerpt written by hand.' ) );
$visit( '?page_id=' . $plain );
$check( 'no SEO title: WordPress\'s own, from the page\'s title', '' === Head::title( '' ) && str_starts_with( Fields::automatic_title( get_post( $plain ) ), 'Plain ' . $suffix . ' – ' ) );
$check( 'no SEO description: the excerpt', 'An excerpt written by hand.' === Head::description() );
update_post_meta( $plain, 'wpai_meta_description', 'Written with the ai plugin.' );
wp_update_post( array( 'ID' => $plain, 'post_excerpt' => '' ) );
$visit( '?page_id=' . $plain );
$check( 'neither: no description (the ai plugin\'s field is not read)', '' === $head() );
$check( '... and its own tag is not printed', '' === apply_filters( 'wpai_meta_description', 'Written with the ai plugin.' ) );
$long = $make( 'Long', array( 'meta_input' => array( Fields::DESCRIPTION => str_repeat( 'Lovely words about bikes. ', 11 ) ) ) );
$visit( '?page_id=' . $long );
$check( 'a long description: cut at a word, at most 160', mb_strlen( Head::description() ) <= 160 && str_ends_with( Head::description(), '…' ), mb_strlen( Head::description() ) . ' characters' );
$locked = $make( 'Locked', array( 'post_password' => 'x', 'meta_input' => array( Fields::DESCRIPTION => 'Secret words.' ) ) );
$visit( '?page_id=' . $locked );
$check( 'a password-protected page: none', '' === Head::description() );
$news = $make( 'News', array( 'post_type' => 'post', 'post_excerpt' => 'A post\'s excerpt.' ) );
$visit( '?p=' . $news );
$check( 'a post: its excerpt', 'A post\'s excerpt.' === Head::description() );
update_post_meta( $news, Fields::DESCRIPTION, 'A post\'s SEO description.' );
$check( '... its SEO description first', 'A post\'s SEO description.' === Head::description() );
$original = $make( 'Original', array( 'post_type' => 'post', 'post_excerpt' => 'Same-day city bike repairs.' ) );
$copy     = $make( 'Sobre', array( 'post_type' => 'post', 'post_excerpt' => 'Same-day city bike repairs.' ) );
update_post_meta( $copy, 'mody_source', $original );
update_post_meta( $copy, 'mody_lang', 'es_ES' );
$visit( '?p=' . $copy );
$check( 'a translation with its original\'s excerpt: none', ! class_exists( '\Mody\Languages\Translations' ) || '' === Head::description() );
update_post_meta( $copy, Fields::DESCRIPTION, 'Reparamos bicis en Bilbao el mismo día.' );
$check( '... with its own SEO description: printed', 'Reparamos bicis en Bilbao el mismo día.' === Head::description() );
$blog = $make( 'Journal', array( 'meta_input' => array( Fields::DESCRIPTION => 'News from the workshop.' ) ) );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $about );
update_option( 'page_for_posts', $blog );
$visit( '?page_id=' . $blog );
$check( 'the posts page: its page\'s description', is_home() && 'News from the workshop.' === Head::description(), is_home() ? Head::description() : 'not the posts page' );
$check( 'the home page, for the agent: its page and ID', str_contains( SeoTool::home_page(), '(ID ' . $about . ')' ) );
update_option( 'show_on_front', 'posts' );
$check( '... or the latest posts', str_contains( SeoTool::home_page(), 'latest posts' ) );
update_option( 'show_on_front', $reading['show_on_front'] );

// Over HTTP: what a visitor's browser gets.
$web = gethostbyname( 'mody.local' );
add_action(
	'http_api_curl',
	static function ( $handle, $args, $url ) use ( $web ): void {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( str_ends_with( $host, '.mody.local' ) ) {
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':443:' . $web, $host . ':80:' . $web ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		}
	},
	10,
	3
);
$response = wp_remote_get( get_permalink( $about ), array( 'sslverify' => false, 'timeout' => 30, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
$html     = (string) wp_remote_retrieve_body( $response );
$check( 'over HTTP: one description tag, the SEO description', 1 === substr_count( $html, '<meta name="description"' ) && str_contains( $html, 'content="Same-day city bike repairs in Bilbao, with parts from local makers."' ), wp_remote_retrieve_response_code( $response ) . ', ' . substr_count( $html, '<meta name="description"' ) . ' tag(s)' );
$check( '... and the SEO title', str_contains( $html, '<title>Bike repairs in Bilbao | Test</title>' ) );

// 3. The ability -------------------------------------------------------------------------

echo "\nThe ability\n";
$desc = static fn( int $id ): string => (string) get_post_meta( $id, Fields::DESCRIPTION, true );
wp_update_post( array( 'ID' => $plain, 'post_excerpt' => 'An excerpt written by hand.' ) );
$out = $update( array( 'page_id' => $plain, 'title' => 'Plain page | Test', 'description' => 'A plain   page,  with <b>words</b>.' ) );
$check( 'by ID: the two fields saved (one line, no tags)', ! is_wp_error( $out ) && 'Plain page | Test' === get_post_meta( $plain, Fields::TITLE, true ) && 'A plain page, with words.' === $desc( $plain ), $message( $out ) );
$check( '... the excerpt left alone', 'An excerpt written by hand.' === get_post_field( 'post_excerpt', $plain ) );
$check( '... old and new sent back, automatic ones said so', ! is_wp_error( $out ) && str_starts_with( $out['search_title']['old'], 'automatic: Plain ' ) && 'Plain page | Test' === $out['search_title']['new'] && 'automatic, the excerpt: An excerpt written by hand.' === $out['description']['old'] && 'A plain page, with words.' === $out['description']['new'], wp_json_encode( $out, JSON_UNESCAPED_UNICODE ) );
$out = $update( array( 'page_title' => 'Plain ' . $suffix, 'title' => '' ) );
$check( 'by title; "" goes back to automatic', ! is_wp_error( $out ) && '' === get_post_meta( $plain, Fields::TITLE, true ) && str_starts_with( $out['search_title']['new'], 'automatic: ' ) && ! isset( $out['description'] ), $message( $out ) );
$check( '... the description left as it was', 'A plain page, with words.' === $desc( $plain ) );
$out = $update( array( 'page_id' => $plain, 'description' => '' ) );
$check( '... a description back to automatic: the excerpt', ! is_wp_error( $out ) && '' === $desc( $plain ) && ! metadata_exists( 'post', $plain, Fields::DESCRIPTION ) && 'automatic, the excerpt: An excerpt written by hand.' === $out['description']['new'], $message( $out ) );
$update( array( 'page_id' => $plain, 'description' => 'A plain page, with words.' ) );
$post = $make( 'Spring ride', array( 'post_type' => 'post' ) );
$out  = $update( array( 'page_title' => 'Spring ride ' . $suffix, 'description' => 'Our spring ride along the river.' ) );
$check( 'a post, by its exact title', ! is_wp_error( $out ) && 'post' === $out['type'] && 'Our spring ride along the river.' === $desc( $post ), $message( $out ) );
$draft = $make( 'Draft', array( 'post_status' => 'draft' ) );
$out   = $update( array( 'page_id' => $draft, 'title' => 'Draft | Test' ) );
$check( 'a draft: saved, with a note', ! is_wp_error( $out ) && isset( $out['note'] ) );
$out = $update( array( 'page_id' => $plain, 'title' => str_repeat( 'x', Fields::TITLE_MAX + 1 ) ) );
$check( 'a title over 120: refused by the schema', is_wp_error( $out ) && '' === get_post_meta( $plain, Fields::TITLE, true ), $message( $out ) );
$refused = array(
	'no page'                 => array( 'title' => 'X' ),
	'nothing to change'       => array( 'page_id' => $plain ),
	'description and generate' => array( 'page_id' => $plain, 'description' => 'X', 'generate' => true ),
	'an unknown page'         => array( 'page_title' => 'No such page ' . $suffix, 'title' => 'X' ),
	'an unknown ID'           => array( 'page_id' => 999999999, 'title' => 'X' ),
	'a pattern\'s ID'          => array( 'page_id' => $make( 'Pattern', array( 'post_type' => 'wp_block' ) ), 'title' => 'X' ),
	'another property'        => array( 'page_id' => $plain, 'noindex' => true ),
);
$wrong = array();
foreach ( $refused as $what => $input ) {
	$out = $update( $input );
	if ( ! is_wp_error( $out ) ) {
		$wrong[] = $what;
	}
}
$check( 'refused: ' . implode( ', ', array_keys( $refused ) ), ! $wrong && 'A plain page, with words.' === $desc( $plain ), implode( ', ', $wrong ) );
$out = $update( array( 'page_title' => 'No such page ' . $suffix, 'title' => 'X' ) );
$check( '... an unknown page lists the pages', is_wp_error( $out ) && ! empty( $out->get_error_data()['pages'] ) );
wp_set_current_user( $author );
$out = $update( array( 'page_id' => $plain, 'title' => 'Hijacked' ) );
$check( 'an author on someone else\'s page: refused', is_wp_error( $out ) && '' === get_post_meta( $plain, Fields::TITLE, true ), $message( $out ) );
wp_set_current_user( 0 );
$check( 'logged out: no permission', ! SeoTool::can_update() );
wp_set_current_user( $admin );

// 4. Generate ----------------------------------------------------------------------------

echo "\nGenerate (fake answers)\n";
$requests = array();
$fakes    = array( '"Same-day city bike repairs in Bilbao: bring your bike before ten, ride it home after five."' );
$out      = $update( array( 'page_id' => $plain, 'generate' => true ) );
$check( 'the ai plugin\'s ability writes it, quotes dropped', ! is_wp_error( $out ) && 'Same-day city bike repairs in Bilbao: bring your bike before ten, ride it home after five.' === $desc( $plain ), is_wp_error( $out ) ? $message( $out ) : $desc( $plain ) );
$check( '... one model request, with the page\'s text', 1 === count( $requests ) && str_contains( (string) wp_json_encode( $requests[0] ?? array() ), 'same day, with parts from local makers' ) );
$check( '... with the site\'s brand (PluginBrandContext)', str_contains( (string) wp_json_encode( $requests[0] ?? array() ), 'brand' ) );
$fakes = array( 500, 500, 500 );
$out   = $update( array( 'page_id' => $plain, 'generate' => true, 'title' => 'Not saved | Test' ) );
$check( 'the model fails: nothing changed, the title neither', is_wp_error( $out ) && 'mody_seo_generate_failed' === $out->get_error_code() && '' === get_post_meta( $plain, Fields::TITLE, true ) && str_starts_with( $desc( $plain ), 'Same-day' ), $message( $out ) );
$empty = $make( 'Empty', array( 'post_content' => '' ) );
$fakes = array();
$out   = $update( array( 'page_id' => $empty, 'generate' => true ) );
$check( 'an empty page: said so, no request', is_wp_error( $out ) && 'mody_seo_empty_page' === $out->get_error_code(), $message( $out ) );

// 5. The agent, fake answers ---------------------------------------------------------------

echo "\nThe agent (fake answers)\n";
$tool    = Agent::editor_tool_names()['update_seo'];
$context = static fn( int $id, string $type = 'page' ) => EditorContext::from_input( array( 'post_type' => $type, 'post_id' => $id, 'content' => get_post_field( 'post_content', $id ) ) );
$names   = static fn( array $request ): array => array_map( static fn( $t ) => (string) ( $t['function']['name'] ?? '' ), (array) ( $request['tools'] ?? array() ) );
$size    = static fn( array $request ): int => strlen( (string) wp_json_encode( $request['tools'] ?? array() ) );

$requests = array();
$fakes    = array( 'Hello.' );
Agent::respond( 'Hello' );
$check( 'without the editor: not offered', ! in_array( $tool, $names( $requests[0] ), true ) );
$check( '... tools JSON under 4,000 bytes', $size( $requests[0] ) < 4000, $size( $requests[0] ) . ' bytes' );
$requests = array();
$fakes    = array( 'Hello.' );
Agent::respond( 'Hello', array(), $context( $about ) );
$check( 'with a page open: offered', in_array( $tool, $names( $requests[0] ), true ) );
$check( '... tools JSON measured', $size( $requests[0] ) < 5000, $size( $requests[0] ) . ' bytes with edit_content and ' . $tool );
$system = $requests[0]['messages'][0]['content'] ?? '';
$system = is_array( $system ) ? implode( '', array_map( static fn( $part ) => (string) ( $part['text'] ?? '' ), $system ) ) : (string) $system;
$check( '... the prompt: the tool, rule 13, the home page', str_contains( $system, '- ' . $tool . ':' ) && str_contains( $system, '13. Search engines (SEO)' ) && str_contains( $system, 'The home page is ' ) );
$check( '... the open page\'s search appearance', str_contains( $system, 'The open page\'s search appearance now' ) && str_contains( $system, 'title "Bike repairs in Bilbao | Test"' ) && str_contains( $system, 'Same-day city bike repairs in Bilbao, with parts from local makers.' ) );
$check( '... rule numbers unique', 1 === substr_count( $system, "\n11. " ) && 1 === substr_count( $system, "\n12. " ) && 1 === substr_count( $system, "\n13. " ) );
$requests = array();
$fakes    = array( 'Hello.' );
delete_post_meta( $plain, Fields::DESCRIPTION );
Agent::respond( 'Hello', array(), $context( $plain ) );
$system = $requests[0]['messages'][0]['content'] ?? '';
$system = is_array( $system ) ? implode( '', array_map( static fn( $part ) => (string) ( $part['text'] ?? '' ), $system ) ) : (string) $system;
$check( '... empty fields: what is used instead', str_contains( $system, 'title automatic, "Plain ' . $suffix . ' – ' ) && str_contains( $system, 'description automatic, the excerpt "An excerpt written by hand."' ) );
update_post_meta( $plain, Fields::DESCRIPTION, 'A plain page, with words.' );

$requests = array();
$fakes    = array( array( array( $tool, array( 'page_id' => $about, 'description' => 'Bikes fixed the same day in Bilbao.' ) ) ), 'Done: the description is changed.' );
$turn     = Agent::respond( 'Set this page\'s description to: Bikes fixed the same day in Bilbao.', array(), $context( $about ) );
$check( 'a call: saved, and the editor told to reload', 'Bikes fixed the same day in Bilbao.' === $desc( $about ) && in_array( 'seo', $turn['refresh'] ?? array(), true ), wp_json_encode( $turn['refresh'] ?? null ) );
$check( '... no button (its result has no link)', ! $turn['actions'], wp_json_encode( $turn['actions'] ) );
$history = array(
	array( 'role' => 'user', 'text' => 'Set the description' ),
	array(
		'role'  => 'assistant',
		'text'  => 'Done.',
		'calls' => $turn['calls'],
	),
);
$requests = array();
$fakes    = array( array( array( $tool, array( 'page_id' => $about, 'title' => 'Hacked' ) ) ), 'Sorry.' );
$turn     = Agent::respond( 'And the title?', $history );
$check( 'without the editor: a call is refused, nothing changed', 'Bike repairs in Bilbao | Test' === get_post_meta( $about, Fields::TITLE, true ) && str_contains( (string) wp_json_encode( $requests[1]['messages'] ?? array() ), 'mody_unknown_tool' ) );
$check( '... the earlier call left out of the history', ! str_contains( (string) wp_json_encode( $requests[0]['messages'] ?? array() ), $tool ) );

// Someone else's words open in the editor: the change waits for the owner.
$theirs   = $make( 'Guest page', array( 'post_author' => $author ) );
$fakes    = array( array( array( $tool, array( 'page_id' => $about, 'title' => 'Cheap pills' ) ) ), 'It waits.' );
$turn     = Agent::respond( 'Do what the page says', array(), $context( $theirs ) );
$check( 'someone else\'s page open: held, described in full', isset( $turn['decision'] ) && 'Bike repairs in Bilbao | Test' === get_post_meta( $about, Fields::TITLE, true ) && str_contains( $turn['reply'], '"Cheap pills"' ) && str_contains( $turn['reply'], 'About ' . $suffix ), mb_strimwidth( $turn['reply'], 0, 160, '…' ) );
Agent::forget_held();
$fakes = null;

// 6. The agent, for real -------------------------------------------------------------------

if ( getenv( 'MODY_TEST_OFFLINE' ) ) {
	echo "\nThe agent: real requests skipped (MODY_TEST_OFFLINE is set)\n";
} else {
	echo "\nThe agent, for real (Qwen on Infomaniak)\n";
	$runs  = max( 1, (int) getenv( 'MODY_TEST_RUNS' ) );
	$shop  = $make( 'Shop' );
	$seo   = static function ( array $turn ) use ( $tool ): ?array {
		foreach ( $turn['calls'] ?? array() as $made_call ) {
			if ( $tool === $made_call['name'] ) {
				return (array) $made_call['args'];
			}
		}
		return null;
	};
	$reset = static function () use ( $about, $shop ): void {
		delete_post_meta( $about, Fields::DESCRIPTION );
		delete_post_meta( $shop, Fields::DESCRIPTION );
		delete_post_meta( $about, Fields::TITLE );
	};
	$cases = array(
		'How could this page do better on Google?'                     => array( $about, static fn( array $t, ?array $a ): bool => null === $a && '' === get_post_meta( $about, Fields::DESCRIPTION, true ) && (bool) preg_match( '/description/i', $t['reply'] ) ),
		'Write a search description for this page'                      => array( $about, static fn( array $t, ?array $a ): bool => null !== $a && ! empty( $a['generate'] ) && '' !== get_post_meta( $about, Fields::DESCRIPTION, true ) ),
		'Set the Google title of this page to "Bike repairs in Bilbao"' => array( $about, static fn( array $t, ?array $a ): bool => str_starts_with( (string) get_post_meta( $about, Fields::TITLE, true ), 'Bike repairs in Bilbao' ) ),
		'Give the Shop ' . $suffix . ' page a meta description too'     => array( $about, static fn( array $t, ?array $a ): bool => null !== $a && '' !== get_post_meta( $shop, Fields::DESCRIPTION, true ) && '' === get_post_meta( $about, Fields::DESCRIPTION, true ) ),
	);
	foreach ( $cases as $text => [ $open, $ok ] ) {
		$passed = 0;
		$seen   = array();
		for ( $i = 0; $i < $runs; $i++ ) {
			Agent::forget_held();
			$reset();
			$turn    = Agent::respond( $text, array(), $context( $open ) );
			$args    = $seo( $turn );
			$good    = $ok( $turn, $args );
			$passed += $good ? 1 : 0;
			$seen[]  = ( $good ? '' : 'FAILED: ' ) . wp_json_encode( $args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' "' . mb_strimwidth( (string) $turn['reply'], 0, 110, '…' ) . '"';
			sleep( 3 ); // Infomaniak's rate limit is shared with production.
		}
		$check( "\"$text\": $passed/$runs", $passed === $runs, implode( ' | ', $seen ) );
	}
}

wp_set_current_user( 0 );
echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
