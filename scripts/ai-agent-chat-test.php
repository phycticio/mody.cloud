<?php
/**
 * Tests the agent chat (ticket M3-01, documentation/ai-engine.md "The agent").
 *
 * Run from the repository root, with INFOMANIAK_* set in .env:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/ai-agent-chat-test.php
 *
 * 1. Tools: the system prompt, and mody/open-editor (offline).
 * 2. No brand profile: the agent refuses before any model request (offline).
 * 3. The loop, fed fake model answers through the WordPress HTTP API: a plain
 *    answer, a page generation, two tool calls at once, ask_user,
 *    refuse_request, unknown tools, the step and tool limits, a model error.
 *    Checks the requests sent, the chat turn and the mody_agent_events rows (offline).
 * 4. Design (ticket M3-02): the presets, mody/update-design's validation and
 *    permissions, and the ask-then-update flow with fake answers, brand yes
 *    and no, with a preset and a single color: the Global Styles palette and
 *    the brand profile row, changed or not; mody/update-styles (M3-10): the
 *    site's styles in the prompt, a change saved through the agent with its
 *    screen, a refused change (offline).
 * 5. Publish and site settings (ticket M3-03): mody/publish-page's refusals and
 *    permissions, publishing by title with two buttons, a hand-edited draft
 *    published as it is; mody/update-site-settings' sanitizing, refusals and
 *    permissions, and that nothing else can be written (offline).
 * 6. The REST endpoint mody-platform/v1/chat: jobs and the checker (here they
 *    run at once, WP-CLI has no PHP-FPM), another user's job, a busy and a
 *    stale job, permissions, validation, the stored conversation (offline).
 * 7. Retention: the setting, the once-a-day purge, the settings page (offline).
 *    It uses the retention in force, so it deletes only what the daily purge would.
 * 8. Real round trips: create a page over HTTP as a background job, polling the
 *    checker, which must stay a draft; open it in the editor; "publish it",
 *    over HTTP with the stored conversation; "change the tagline to …"; two requests that must be
 *    refused; the presets; "make the primary color coral" and "use the Forest
 *    preset". Add `-e MODY_TEST_OFFLINE=1` after `docker exec` to skip them.
 *
 * If the site has no mission, a test one is set and removed at
 * the end. Pages and events created by the test are removed too, other pages'
 * statuses, the site title and tagline, the site's Global Styles, its
 * revisions and the profile's colors are restored, and so is the user's chat
 * conversation. Add `-e MODY_TEST_KEEP_PAGE=1` to keep the generated draft.
 * Never prints the token.
 */

use Mody\Platform\Agent\Agent;
use Mody\Platform\Agent\AgentEvents;
use Mody\Platform\Agent\AgentSettings;
use Mody\Platform\Agent\EditorBubble;
use Mody\Platform\Agent\EditorContext;
use Mody\Platform\AI\BlockValidator;
use Mody\Platform\AI\SystemPrompt;
use Mody\Platform\Brand\BrandSettings;
use Mody\Platform\Design\Presets;
use Mody\Platform\Design\SiteDesign;
use Mody\Platform\Site\SiteSettings;
use Mody\Platform\SiteEditor;

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-40s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};
$message  = static fn( $result ): string => is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '';

global $wpdb;
$admins = get_super_admins();
wp_set_current_user( get_user_by( 'login', $admins[0] )->ID );
AgentEvents::install();

$site_id = get_current_blog_id();
$events  = AgentEvents::table();
$mark    = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$events}" ); // phpcs:ignore WordPress.DB
$since   = static function () use ( &$mark, $events ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$events} WHERE id > %d ORDER BY id", $mark ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$mark = $rows ? (int) end( $rows )['id'] : $mark;
	return $rows;
};
$test_mark = $mark;

// The brand is the site's own settings (M2-08): a mission for the test when it has none.
// The user's conversation is restored at the end.
$saved_brand = array(
	BrandSettings::MISSION => get_option( BrandSettings::MISSION, '' ),
	BrandSettings::TONE    => get_option( BrandSettings::TONE, '' ),
);
if ( '' === BrandSettings::brand()['mission'] ) {
	BrandSettings::save( 'We bake sourdough bread for offices in Zurich.', 'friendly' );
}
$chat_key     = 'mody_agent_chat_' . get_current_user_id();
$saved_chat   = get_transient( $chat_key );
$cleanup_ids  = array();

// The design tests change the site's Global Styles: restore them.
$styles_id      = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
$styles_content = get_post( $styles_id )->post_content;
$revisions      = wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) );
$restore_design = static function () use ( $styles_id, $styles_content ): void {
	if ( get_post( $styles_id )->post_content !== $styles_content ) {
		wp_update_post( wp_slash( array( 'ID' => $styles_id, 'post_content' => $styles_content ) ), true, false );
	}
	wp_clean_theme_json_cache();
};

register_shutdown_function(
	static function () use ( $saved_brand, $test_mark, $events, $chat_key, $saved_chat, &$cleanup_ids, $restore_design, $styles_id, $revisions ): void {
		global $wpdb;
		$restore_design();
		foreach ( array_diff( wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) ), $revisions ) as $revision ) {
			wp_delete_post_revision( $revision );
		}
		foreach ( $saved_brand as $option => $value ) {
			update_option( $option, $value );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE id > %d", $test_mark ) ); // phpcs:ignore WordPress.DB
		foreach ( $cleanup_ids as $id ) {
			wp_delete_post( $id, true );
		}
		false === $saved_chat ? delete_transient( $chat_key ) : set_transient( $chat_key, $saved_chat, 2 * HOUR_IN_SECONDS );
	}
);

// Publishing and the site settings: other pages' statuses, the title and the tagline are restored.
$page_statuses = wp_list_pluck(
	get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => 'any',
			'numberposts' => -1,
		)
	),
	'post_status',
	'ID'
);
$site_options  = array(
	'blogname'        => get_option( 'blogname' ),
	'blogdescription' => get_option( 'blogdescription' ),
);
$restore_site  = static function () use ( $page_statuses, $site_options ): void {
	foreach ( $site_options as $option => $value ) {
		update_option( $option, $value );
	}
	foreach ( $page_statuses as $id => $status ) {
		clean_post_cache( $id );
		if ( get_post_status( $id ) !== $status ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => $status,
				)
			);
		}
	}
};
register_shutdown_function( $restore_site );

// A page to open.
$page_id       = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'draft',
		'post_title'  => 'Mody agent test page',
	)
);
$cleanup_ids[] = $page_id;

// 1. Tools ------------------------------------------------------------------

echo "Tools\n";
$names  = Agent::tool_names();
$system = SystemPrompt::for_agent( $names );
$check( 'function names', 'wpab__mody__generate-page' === $names['generate_page'] && 'wpab__mody__open-editor' === $names['open_editor'], implode( ', ', $names ) );
$check( 'agent prompt: tools and brand', count( array_filter( $names, static fn( $n ) => str_contains( $system, $n ) ) ) === count( $names ) && str_contains( $system, '<brand>' ), strlen( $system ) . ' characters' );

$open   = wp_get_ability( 'mody/open-editor' );
$result = $open->execute( array( 'target' => 'page', 'page_id' => $page_id ) );
$check( 'open-editor: page by ID', is_array( $result ) && SiteEditor::page_url( $page_id ) === $result['url'] && str_contains( $result['url'], 'site-editor.php?p=%2Fpage%2F' . $page_id . '&canvas=edit' ), is_array( $result ) ? $result['url'] : $message( $result ) );
$result = $open->execute( array( 'target' => 'page', 'page_title' => 'mody AGENT test page' ) );
$check( 'open-editor: page by title', is_array( $result ) && $page_id === $result['page_id'], is_array( $result ) ? $result['label'] : $message( $result ) );
$result = $open->execute( array( 'target' => 'page', 'page_title' => 'No such page here' ) );
$check( 'open-editor: unknown page', is_wp_error( $result ) && 'mody_page_not_found' === $result->get_error_code() && ! empty( $result->get_error_data()['pages'] ), $message( $result ) );
$result = $open->execute( array( 'target' => 'styles' ) );
$check( 'open-editor: styles', is_array( $result ) && str_ends_with( $result['url'], 'site-editor.php?p=%2Fstyles' ), is_array( $result ) ? $result['url'] : $message( $result ) );
$result = $open->execute(
	array(
		'target'   => 'template',
		'template' => 'index',
	)
);
$check( 'open-editor: template', is_array( $result ) && str_ends_with( $result['url'], 'p=%2Fwp_template%2F' . get_stylesheet() . '%2F%2Findex&canvas=edit' ), is_array( $result ) ? $result['url'] : $message( $result ) );
$result = $open->execute(
	array(
		'target'   => 'template',
		'template' => 'no-such-template',
	)
);
$check( 'open-editor: unknown template', is_wp_error( $result ) && 'mody_template_not_found' === $result->get_error_code(), $message( $result ) );
$user = get_current_user_id();
wp_set_current_user( 0 );
$result = $open->execute( array( 'target' => 'styles' ) );
wp_set_current_user( $user );
$check( 'open-editor: logged out refused', is_wp_error( $result ), $message( $result ) );

// Model requests, recorded (and optionally answered with fakes) at the HTTP layer.
$requests = array();
$fakes    = null; // null: real requests. array: answers in order. A string is a text answer,
				// an array a list of tool calls (name => args), an int an HTTP error status.
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
		$fake    = array_shift( $fakes );
		$status  = is_int( $fake ) ? $fake : 200;
		$message = array(
			'role'    => 'assistant',
			'content' => is_string( $fake ) ? $fake : null,
		);
		if ( is_array( $fake ) ) {
			$message['tool_calls'] = array();
			foreach ( $fake as $i => $call ) {
				$message['tool_calls'][] = array(
					'id'       => 'call_' . count( $requests ) . '_' . $i,
					'type'     => 'function',
					'function' => array(
						'name'      => $call[0],
						'arguments' => wp_json_encode( (object) $call[1] ),
					),
				);
			}
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
						'message'       => $message,
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
	10,
	3
);
$tool_messages = static fn( array $request ): array => array_values( array_filter( $request['messages'] ?? array(), static fn( $m ) => 'tool' === $m['role'] ) );
$tools_of      = static fn( array $rows ): array => array_values( array_filter( array_column( $rows, 'tool' ) ) );

// 2. The brand: every site's own settings (M2-08) ---------------------------------

echo "\nThe brand (the site's settings)\n";
$other = (int) ( get_sites(
	array(
		'site__not_in' => array( $site_id ),
		'fields'       => 'ids',
		'number'       => 1,
	)
)[0] ?? 0 );
if ( $other ) {
	switch_to_blog( $other );
	$fakes    = array( 'Hello! What would you like to change?' );
	$requests = array();
	$turn     = Agent::respond( 'Hello' );
	$title    = BrandSettings::brand()['name'];
	restore_current_blog();
	$since();
	$sent = (string) wp_json_encode( $requests[0]['messages'][0] ?? array() );
	$check( 'another site: answered, with that site\'s own title as the brand', ! $turn['error'] && 1 === count( $requests ) && str_contains( $sent, 'Name: ' . $title ), "site $other: $title" );
}

// 3. The loop with fake model answers ---------------------------------------

echo "\nAgent loop (fake model answers)\n";
$fakes    = array( 'I can create pages and open the editor.' );
$requests = array();
$turn     = Agent::respond( 'What can you do?' );
$rows     = $since();
$sent     = array_column( array_column( $requests[0]['tools'] ?? array(), 'function' ), 'name' );
$check( 'plain answer', 'I can create pages and open the editor.' === $turn['reply'] && ! $turn['actions'] && ! $turn['error'], $turn['reply'] );
$check( '...nothing for the editor to reload', array() === $turn['refresh'] );
$check( '...tools offered', array() === array_diff( array_values( $names ), $sent ) && 8 === count( $sent ), implode( ', ', $sent ) );
$check( '...tools JSON size', strlen( (string) wp_json_encode( $requests[0]['tools'] ?? array() ) ) < 4000, strlen( (string) wp_json_encode( $requests[0]['tools'] ?? array() ) ) . ' bytes (see ai-engine.md "Prompt size")' );
$check( '...brand in the system prompt', str_contains( wp_json_encode( $requests[0]['messages'][0] ?? array() ), '<brand>' ) && 'system' === ( $requests[0]['messages'][0]['role'] ?? '' ) );
$check( '...temperature 0.1', 0.1 === (float) ( $requests[0]['temperature'] ?? 0 ) );
$check( '...request logged', 1 === count( $rows ) && null === $rows[0]['tool'] && 'What can you do?' === $rows[0]['input'] && $turn['reply'] === $rows[0]['result'] );

$fakes    = array(
	array( array( $names['generate_page'], array( 'prompt' => 'An about page for the bakery.' ) ) ),
	SystemPrompt::EXAMPLE,
	'Your About page draft is ready. Review it in the Site Editor and publish it there.',
);
$requests = array();
$turn     = Agent::respond( 'Create an about page' );
$rows     = $since();
$new_page = preg_match( '/%2Fpage%2F(\d+)/', $turn['actions'][0]['url'] ?? '', $match ) ? (int) $match[1] : 0;
if ( $new_page ) {
	$cleanup_ids[] = $new_page;
}
$tool_reply = $tool_messages( $requests[2] ?? array() )[0]['content'] ?? '';
$check( 'generate page: draft saved', $new_page && 'draft' === get_post_status( $new_page ), "page $new_page" );
$check( '...the new draft opens in place (M3-09)', EditorBubble::open_url( SiteEditor::page_url( $new_page ) ) === ( $turn['actions'][0]['url'] ?? '' ) && true === ( $turn['actions'][0]['editor'] ?? null ) && true === ( $turn['actions'][0]['navigate'] ?? null ) && str_starts_with( (string) ( $turn['actions'][0]['place'] ?? '' ), 'the page "' ), ( $turn['actions'][0]['place'] ?? '' ) . ' / ' . ( $turn['actions'][0]['label'] ?? '' ) );
$check( '...3 requests (agent, page, agent)', 3 === count( $requests ), count( $requests ) . ' request(s)' );
$check( '...result sent back without the URL', str_contains( $tool_reply, '"page_id":' . $new_page ) && ! str_contains( $tool_reply, 'site-editor' ), mb_strimwidth( $tool_reply, 0, 80, '...' ) );
$check( '...tool call logged', array( 'mody/generate-page' ) === $tools_of( $rows ) && str_contains( (string) $rows[0]['args'], 'An about page' ) && null !== $rows[0]['duration_ms'], $rows ? "args {$rows[0]['args']}, {$rows[0]['duration_ms']} ms" : '' );
$check( '...and the request', 2 === count( $rows ) && null === $rows[1]['tool'] );

$fakes    = array(
	array(
		array( $names['open_editor'], array( 'target' => 'styles' ) ),
		array(
			$names['open_editor'],
			array(
				'target'  => 'page',
				'page_id' => $page_id,
			),
		),
	),
	'Here are both.',
);
$requests = array();
$turn     = Agent::respond( 'Open the styles and the test page' );
$sent     = $tool_messages( $requests[1] ?? array() );
$since();
$check( 'two calls at once: two buttons', 2 === count( $turn['actions'] ), implode( ' | ', array_column( $turn['actions'], 'label' ) ) );
$check( '...one tool message each', 2 === count( $sent ) && array( 'call_1_0', 'call_1_1' ) === array_column( $sent, 'tool_call_id' ) );

$fakes    = array(
	array(
		array(
			Agent::ASK_USER,
			array(
				'question' => 'Which page should I create?',
				'choices'  => array( 'About', 'Contact', '' ),
			),
		),
	),
);
$requests = array();
$turn     = Agent::respond( 'Make a page' );
$rows     = $since();
$check( 'ask_user: question and choices', 'Which page should I create?' === $turn['reply'] && array( 'About', 'Contact' ) === $turn['choices'], wp_json_encode( $turn['choices'] ) );
$check( '...ends the turn, logged', 1 === count( $requests ) && array( 'ask_user' ) === $tools_of( $rows ) );

$fakes    = array( array( array( Agent::REFUSE, array( 'reason' => 'I cannot delete pages, but I can open the editor for you.' ) ) ) );
$requests = array();
$turn     = Agent::respond( 'Delete all pages' );
$rows     = $since();
$check( 'refuse_request: refused', $turn['refused'] && str_starts_with( $turn['reply'], 'I cannot delete pages' ), $turn['reply'] );
$check( '...logged as a refusal', array( 'refuse_request' ) === $tools_of( $rows ) && str_starts_with( $rows[0]['result'], 'refused:' ) );

$pages_before = (int) array_sum( (array) wp_count_posts( 'page' ) );
$fakes        = array(
	array(
		array( 'delete_all_pages', array() ),
		array( 'wpab__core__get-site-info', array() ),
	),
	'I cannot do that.',
);
$requests     = array();
$turn         = Agent::respond( 'Delete all pages and show the site settings' );
$rows         = $since();
$sent         = $tool_messages( $requests[1] ?? array() );
wp_cache_delete( _count_posts_cache_key( 'page' ), 'counts' );
$check( 'unknown tools: not run', 2 === count( $sent ) && 2 === substr_count( wp_json_encode( $sent ), 'mody_unknown_tool' ), count( $sent ) . ' tool message(s)' );
$check( '...nothing deleted', (int) array_sum( (array) wp_count_posts( 'page' ) ) === $pages_before );
$check( '...logged as refused', array( 'delete_all_pages', 'wpab__core__get-site-info' ) === $tools_of( $rows ) && 'refused: unknown tool' === $rows[0]['result'], implode( ', ', $tools_of( $rows ) ) );

// A reply that narrates instead of acting (M3-09): sent back once, never shown.
$fakes    = array(
	'The owner wants to see other designs. This maps to the style presets.',
	array(
		array(
			$names['open_editor'],
			array(
				'target'  => 'styles',
				'section' => 'browse',
			),
		),
	),
	'The style presets are open now.',
);
$requests = array();
$turn     = Agent::respond( 'Show me other designs' );
$since();
$check( 'a narrating reply: asked once more, then it acts', 3 === count( $requests ) && 'The style presets are open now.' === $turn['reply'] && 'Styles → Browse styles' === ( $turn['actions'][0]['place'] ?? '' ), count( $requests ) . ' requests: ' . $turn['reply'] );
$fakes    = array( 'The owner wants a page.', 'The owner wants a page, still.' );
$requests = array();
$turn     = Agent::respond( 'Hello again' );
$since();
$check( '...only once per message', 2 === count( $requests ) && 'The owner wants a page, still.' === $turn['reply'], count( $requests ) . ' requests' );
// A bare offer ("I can help with that.", M2-08): sent back once, never shown.
$fakes    = array(
	'I can help with that.',
	array( array( $names['open_editor'], array( 'target' => 'identity' ) ) ),
	'The Identity screen is open now.',
);
$requests = array();
$turn     = Agent::respond( 'I want to change my logo' );
$since();
$check( 'a bare offer: asked once more, then it acts', 3 === count( $requests ) && 'The Identity screen is open now.' === $turn['reply'] && 'the Identity screen' === ( $turn['actions'][0]['place'] ?? '' ), count( $requests ) . ' requests: ' . $turn['reply'] );
// "Done" with no tool run (rule 2, M2-08): sent back once.
$saved_tagline_for_done = (string) get_option( 'blogdescription' );
$fakes                  = array(
	'Done! Your tagline is now "Fresh bread every morning".',
	array( array( $names['update_site_settings'], array( 'tagline' => 'Fresh bread every morning' ) ) ),
	'Done! Your tagline is now "Fresh bread every morning".',
);
$requests = array();
$turn     = Agent::respond( 'Change the slogan to Fresh bread every morning' );
$rows     = $since();
$check( '"Done" with no tool run: asked once more, then the tool runs', 3 === count( $requests ) && in_array( 'mody/update-site-settings', $tools_of( $rows ), true ) && 'Fresh bread every morning' === SiteSettings::get( 'tagline' ), count( $requests ) . ' requests' );
update_option( 'blogdescription', $saved_tagline_for_done );

// Opening what is already open in the editor (M3-09): refused, so the model edits instead.
$open_ctx = EditorContext::from_input(
	array(
		'post_type' => 'page',
		'post_id'   => $page_id,
		'content'   => (string) get_post_field( 'post_content', $page_id ),
	)
);
$fakes    = array(
	array(
		array(
			$names['open_editor'],
			array(
				'target'  => 'page',
				'page_id' => $page_id,
			),
		),
	),
	'Tell me what to add.',
);
$requests = array();
$turn     = Agent::respond( 'Add a short FAQ at the end of this page', array(), $open_ctx );
$rows     = $since();
$sent     = (string) wp_json_encode( $tool_messages( $requests[1] ?? array() ) );
$check( 'opening the page that is open: refused, nothing opened', ! $turn['actions'] && str_contains( $sent, 'mody_already_open' ) && in_array( 'refused: already open', array_column( $rows, 'result' ), true ), count( $turn['actions'] ) . ' action(s)' );
$fakes    = array( array( array( $names['open_editor'], array( 'target' => 'styles' ) ) ), 'Done.' );
$requests = array();
$turn     = Agent::respond( 'Open the styles', array(), $open_ctx );
$since();
$check( '...another screen still opens from the editor', 'Styles' === ( $turn['actions'][0]['place'] ?? '' ) );

$styles   = array( $names['open_editor'], array( 'target' => 'styles' ) );
$fakes    = array_fill( 0, 5, array( $styles, $styles ) );
$requests = array();
$turn     = Agent::respond( 'Open the styles again and again' );
$rows     = $since();
$limited  = count( array_filter( $rows, static fn( $r ) => 'refused: tool call limit' === $r['result'] ) );
$check( 'limits: 4 model requests at most', 4 === count( $requests ), count( $requests ) . ' request(s)' );
$check( '...3 tool runs at most', 3 === count( $rows ) - 1 - $limited && 5 === $limited, ( count( $rows ) - 1 - $limited ) . " run, $limited refused" );
$check( '...still answers', '' !== $turn['reply'] && ! $turn['error'], $turn['reply'] );

$fakes    = array( 500 );
$requests = array();
$turn     = Agent::respond( 'Hello' );
$rows     = $since();
$check( 'model error: reported', $turn['error'] && 1 === count( $requests ), $turn['reply'] );
$check( '...logged', 1 === count( $rows ) && null === $rows[0]['tool'] && ! str_contains( $rows[0]['result'], 'Hello' ), $rows ? mb_strimwidth( $rows[0]['result'], 0, 70, '...' ) : '' );

// Two tools ran, then the model failed (seen once for real: a connection timeout).
$fakes    = array(
	array( array( $names['open_editor'], array( 'target' => 'styles' ) ) ),
	array( array( $names['open_editor'], array( 'target' => 'styles' ) ) ),
	500,
);
$requests = array();
$turn     = Agent::respond( 'Open the styles twice' );
$since();
$check( 'model error after a tool: says so', $turn['error'] && str_contains( $turn['reply'], 'may already be done' ), $turn['reply'] );
$check( '...buttons kept, once each', array( EditorBubble::open_url( SiteEditor::url( '/styles' ) ) ) === array_column( $turn['actions'], 'url' ), count( $turn['actions'] ) . ' button(s)' );
$check( '...a screen to open in place, named', true === ( $turn['actions'][0]['navigate'] ?? null ) && 'Styles' === ( $turn['actions'][0]['place'] ?? '' ), wp_json_encode( $turn['actions'][0] ?? null ) );

// 4. Design: mody/update-design ---------------------------------------------

echo "\nDesign (mody/update-design)\n";
$palette      = static function (): array { // Every theme palette slug => color, as the site shows it.
	wp_clean_theme_json_cache();
	return array_map( 'strtolower', wp_list_pluck( (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) ), 'color', 'slug' ) );
};
$css_color    = static function ( string $slug ): string {
	return preg_match( '/--wp--preset--color--' . $slug . ':\s*([^;]+);/', wp_get_global_stylesheet( array( 'variables' ) ), $m ) ? strtolower( trim( $m[1] ) ) : '';
};
$styles_now   = static fn(): string => (string) get_post( $styles_id )->post_content;
$theme_colors = array_map( 'strtolower', wp_list_pluck( WP_Theme_JSON_Resolver::get_theme_data()->get_settings()['color']['palette']['theme'], 'color', 'slug' ) );
$restore_design();
$start_colors = $palette();

// The theme's styles (M2-06): the default, then styles/*.json in the order WordPress lists them.
$check( 'six presets, Mody Teal first', array( 'mody-teal', 'forest', 'graphite', 'ocean', 'plum', 'sand' ) === Presets::slugs() && Presets::DEFAULT === Presets::slugs()[0], Presets::names() );
$check( '...each three hex colors', 6 === count( array_filter( array_map( array( Presets::class, 'colors' ), Presets::slugs() ), static fn( $c ) => is_array( $c ) && 3 === count( array_filter( $c, array( SiteDesign::class, 'is_hex' ) ) ) ) ) );
$default_hex = Presets::colors( Presets::DEFAULT );
$check( '...default = theme.json', array_intersect_key( $theme_colors, $default_hex ) == $default_hex, wp_json_encode( $default_hex ) ); // phpcs:ignore Universal.Operators.StrictComparisons
// The site's palette is its brand (M2-08): no brand question, nothing kept elsewhere.
$check( 'agent prompt: colors change at once, no brand question, no hex in the brand', str_contains( $system, $names['update_design'] ) && str_contains( $system, 'without asking whether it is part of the brand' ) && ! str_contains( $system, 'part of my brand' ) && ! preg_match( '/#[0-9a-f]{6}/i', SystemPrompt::brand_context() ) );

$design = wp_get_ability( 'mody/update-design' );
$invalid = array(
	'bad hex'             => array( 'primary' => '#ff7f5' ),
	'color name'          => array( 'primary' => 'coral' ),
	'unknown preset'      => array( 'preset' => 'jungle' ),
	'base color'          => array( 'base' => '#ffffff' ),
	'preset and colors'   => array(
		'preset' => 'forest',
		'accent' => '#ff7f50',
	),
	'nothing to change'   => array(),
	'brand is not a field' => array(
		'primary' => '#ff7f50',
		'brand'   => true,
	),
);
$codes   = array();
foreach ( $invalid as $label => $input ) {
	$result          = $design->execute( $input );
	$codes[ $label ] = is_wp_error( $result ) ? $result->get_error_code() : 'accepted';
}
$check( 'invalid input refused, "brand" included', ! in_array( 'accepted', $codes, true ) && 'mody_preset_or_colors' === $codes['preset and colors'] && 'mody_no_colors' === $codes['nothing to change'], wp_json_encode( array_count_values( $codes ) ) );
$check( '...nothing changed', $styles_content === $styles_now() );

$deny = static fn( array $caps, string $cap ): array => in_array( $cap, array( 'edit_theme_options' ), true ) ? array( 'do_not_allow' ) : $caps;
add_filter( 'map_meta_cap', $deny, 10, 2 ); // Super admins pass user_has_cap, not do_not_allow.
$result = $design->execute( array( 'primary' => '#ff7f50' ) );
remove_filter( 'map_meta_cap', $deny, 10 );
$check( 'without edit_theme_options: refused', is_wp_error( $result ) && $styles_content === $styles_now(), $message( $result ) );
wp_set_current_user( 0 );
$result = $design->execute( array( 'primary' => '#ff7f50' ) );
wp_set_current_user( $user );
$check( 'logged out: refused', is_wp_error( $result ) && $styles_content === $styles_now(), $message( $result ) );

// One color, at once: the site's palette (M2-08).
$fakes    = array(
	array(
		array(
			$names['update_design'],
			array( 'primary' => '#FF7F50' ),
		),
	),
	'Done: the primary color is now coral.',
);
$requests = array();
$turn     = Agent::respond( 'Make the primary color coral' );
$rows     = $since();
$now      = $palette();
$sent     = $tool_messages( $requests[1] ?? array() )[0]['content'] ?? '';
$check( 'coral: changed at once, no question', ! $turn['choices'] && '#ff7f50' === $now['primary'] && '#ff7f50' === $css_color( 'primary' ), 'palette ' . $now['primary'] . ', CSS ' . $css_color( 'primary' ) );
$check( '...other colors unchanged', array_diff_key( $now, array( 'primary' => 1 ) ) === array_diff_key( $start_colors, array( 'primary' => 1 ) ) );
$colors_screen = SiteEditor::styles_screen( 'colors' );
$check( '...Styles → Colors, opened in place (M3-09)', EditorBubble::open_url( $colors_screen['url'] ) === ( $turn['actions'][0]['url'] ?? '' ) && true === ( $turn['actions'][0]['navigate'] ?? null ) && $colors_screen['place'] === ( $turn['actions'][0]['place'] ?? '' ), ( $turn['actions'][0]['place'] ?? 'no screen' ) . ' ' . ( $turn['actions'][0]['url'] ?? '' ) );
$check( '...old and new sent back, no URL, nothing about a profile', str_contains( $sent, '"old":"' . $start_colors['primary'] . '"' ) && str_contains( $sent, '"new":"#ff7f50"' ) && ! str_contains( $sent, 'brand_profile' ) && ! str_contains( $sent, 'site-editor' ), mb_strimwidth( $sent, 0, 90, '...' ) );
$check( '...logged with old and new', array( 'mody/update-design' ) === $tools_of( $rows ) && str_contains( (string) $rows[0]['result'], '"new":"#ff7f50"' ) );
$check( '...the editor reloads the styles (refresh)', array( 'styles' ) === $turn['refresh'], wp_json_encode( $turn['refresh'] ) );
$stored = json_decode( $styles_now(), true );
$check( '...stored like the Site Editor', count( $theme_colors ) === count( $stored['settings']['color']['palette']['theme'] ?? array() ) && ! empty( $stored['isGlobalStylesUserThemeJSON'] ), 'settings.color.palette.theme, ' . count( $stored['settings']['color']['palette']['theme'] ?? array() ) . ' entries' );
$check( '...a Global Styles revision', count( wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) ) ) > count( $revisions ) );

// Any other style (M3-10 part B): mody/update-styles, with the site's styles in the prompt.
$restore_design();
$check( 'agent prompt: update-styles, the styles by path, presets and fonts', str_contains( $system, $names['update_styles'] ) && str_contains( $system, 'styles.elements.button: color.background var:preset|color|primary' ) && str_contains( $system, 'settings.layout: contentSize ' ) && str_contains( $system, 'Fonts: inter' ) && str_contains( $system, 'base ' . $start_colors['base'] ) );
$check( '...vague wishes are asked about, with concrete choices', str_contains( $system, 'When a style wish is vague' ) );
$fakes    = array(
	array(
		array(
			$names['update_styles'],
			array( 'changes' => array( 'styles' => array( 'elements' => array( 'link' => array( 'color' => array( 'text' => 'var:preset|color|accent' ) ) ) ) ) ),
		),
	),
	'Done: links are now in the accent color.',
);
$requests = array();
$turn     = Agent::respond( 'Links in the accent color' );
$rows     = $since();
$sent     = $tool_messages( $requests[1] ?? array() )[0]['content'] ?? '';
$stored   = json_decode( $styles_now(), true );
$check( 'links in accent: saved through the agent', 'var(--wp--preset--color--accent)' === ( $stored['styles']['elements']['link']['color']['text'] ?? '' ) && ! $turn['error'], wp_json_encode( $stored['styles'] ?? null ) );
$check( '...Styles → Colors, opened in place', true === ( $turn['actions'][0]['navigate'] ?? null ) && 'Styles → Colors' === ( $turn['actions'][0]['place'] ?? '' ), ( $turn['actions'][0]['place'] ?? 'no screen' ) );
$check( '...the change sent back, no URL', str_contains( $sent, 'styles.elements.link.color.text' ) && ! str_contains( $sent, 'site-editor' ), mb_strimwidth( $sent, 0, 120, '...' ) );
$check( '...logged', array( 'mody/update-styles' ) === $tools_of( $rows ) && str_contains( (string) $rows[0]['result'], 'styles.elements.link.color.text' ) );
$fakes    = array(
	array(
		array(
			$names['update_styles'],
			array( 'changes' => array( 'styles' => array( 'css' => 'body{display:none}' ) ) ),
		),
	),
	'I could not do that: custom CSS cannot be changed.',
);
$requests = array();
$before   = $styles_now();
$turn     = Agent::respond( 'Add this CSS: body{display:none}' );
$sent     = $tool_messages( $requests[1] ?? array() )[0]['content'] ?? '';
$check( '...a refused change: nothing saved, the reason sent back', $before === $styles_now() && str_contains( $sent, 'Nothing was saved' ) && ! $turn['actions'], mb_strimwidth( $sent, 0, 120, '...' ) );
$check( '...nothing for the editor to reload', array() === $turn['refresh'], wp_json_encode( $turn['refresh'] ) );
$restore_design();

// A preset.
$fakes  = array(
	array(
		array(
			$names['update_design'],
			array( 'preset' => 'forest' ),
		),
	),
	'Done: your site now uses Forest.',
);
$turn   = Agent::respond( 'Use the Forest preset' );
$rows   = $since();
$now    = $palette();
$forest = Presets::colors( 'forest' );
$check( 'forest: palette', array_intersect_key( $now, $forest ) == $forest && $now['base'] === $start_colors['base'] && $now['contrast'] === $start_colors['contrast'], wp_json_encode( array_intersect_key( $now, $forest ) ) ); // phpcs:ignore Universal.Operators.StrictComparisons
$check( '...the whole style is active (M2-06), Browse styles opened', 'forest' === SiteDesign::active_preset() && 'Styles → Browse styles' === ( $turn['actions'][0]['place'] ?? '' ) );
$check( '...the editor reloads the styles', array( 'styles' ) === $turn['refresh'], wp_json_encode( $turn['refresh'] ) );

// A member saves through kses (only super admins have unfiltered_html on Multisite).
$no_html = static fn( array $caps, string $cap ): array => 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
add_filter( 'map_meta_cap', $no_html, 10, 2 );
kses_init(); // What set_current_user runs for a member.
$filtered = false !== has_filter( 'content_save_pre', 'wp_filter_global_styles_post' );
$result   = $design->execute( array( 'secondary' => '#222222' ) );
remove_filter( 'map_meta_cap', $no_html, 10 );
kses_init();
$check( 'through kses: saved', $filtered && is_array( $result ) && '#222222' === $palette()['secondary'], $filtered ? $message( $result ) : 'kses filter not active' );

$restore_design();
$check( 'restored', $start_colors === $palette() );
$since();

// 5. Publish and site settings ----------------------------------------------

echo "\nPublish and site settings (mody/publish-page, mody/update-site-settings)\n";
$publish  = wp_get_ability( 'mody/publish-page' );
$settings = wp_get_ability( 'mody/update-site-settings' );
$status   = static function ( int $id ): string {
	clean_post_cache( $id );
	return (string) get_post_status( $id );
};
$make     = static function ( string $title, string $post_status = 'draft', string $type = 'page', string $content = '' ) use ( &$cleanup_ids ): int {
	$id            = (int) wp_insert_post(
		array(
			'post_type'    => $type,
			'post_status'  => $post_status,
			'post_title'   => $title,
			'post_content' => $content,
		)
	);
	$cleanup_ids[] = $id;
	return $id;
};
$check( 'agent prompt: publish on demand', str_contains( $system, $names['publish_page'] ) && str_contains( $system, $names['update_site_settings'] ) && str_contains( $system, 'Never ask whether to publish it' ) && ! str_contains( $system, 'publish it there' ) );

$draft_id     = $make( 'Mody publish test draft' );
$published_id = $make( 'Mody publish test published', 'publish' );
$private_id   = $make( 'Mody publish test private', 'private' );
$trashed_id   = $make( 'Mody publish test trashed' );
$post_id      = $make( 'Mody publish test post', 'draft', 'post' );
wp_trash_post( $trashed_id );
$statuses = static fn(): array => array_map( $status, array( $draft_id, $published_id, $private_id, $trashed_id, $post_id ) );
$before   = $statuses();

$refusals = array( // Input => the error code expected; null: any error (the schema refuses it).
	'nothing given'    => array( array(), 'mody_page_required' ),
	'unknown ID'       => array( array( 'page_id' => 999999999 ), 'mody_page_not_found' ),
	'unknown title'    => array( array( 'page_title' => 'No such page here' ), 'mody_page_not_found' ),
	'a post'           => array( array( 'page_id' => $post_id ), 'mody_page_not_found' ),
	'published'        => array( array( 'page_id' => $published_id ), 'mody_page_published' ),
	'published, title' => array( array( 'page_title' => 'mody publish test PUBLISHED' ), 'mody_page_published' ),
	'private'          => array( array( 'page_id' => $private_id ), 'mody_page_not_draft' ),
	'in the trash'     => array( array( 'page_id' => $trashed_id ), 'mody_page_in_trash' ),
	'other property'   => array(
		array(
			'page_id' => $draft_id,
			'status'  => 'publish',
		),
		null,
	),
);
$wrong    = array();
foreach ( $refusals as $label => list( $input, $code ) ) {
	$result = $publish->execute( $input );
	if ( ! is_wp_error( $result ) || ( null !== $code && $code !== $result->get_error_code() ) ) {
		$wrong[] = $label . ': ' . ( is_wp_error( $result ) ? $result->get_error_code() : 'published' );
	}
}
$check( 'publish: refusals', ! $wrong, $wrong ? implode( ', ', $wrong ) : count( $refusals ) . ' refused with the right code' );
$check( '...nothing changed', $before === $statuses(), implode( ', ', $statuses() ) );
$result = $publish->execute( array( 'page_title' => 'No such page here' ) );
$listed = is_wp_error( $result ) ? array_column( (array) ( $result->get_error_data()['pages'] ?? array() ), 'id' ) : array();
$check( '...not found: lists the drafts', in_array( $draft_id, $listed, true ) && ! in_array( $published_id, $listed, true ), count( $listed ) . ' draft(s) listed' );

$deny = static fn( array $caps, string $cap ): array => 'publish_pages' === $cap ? array( 'do_not_allow' ) : $caps;
add_filter( 'map_meta_cap', $deny, 10, 2 );
$result = $publish->execute( array( 'page_id' => $draft_id ) );
remove_filter( 'map_meta_cap', $deny, 10 );
$check( 'without publish_pages: refused', is_wp_error( $result ) && 'draft' === $status( $draft_id ), $message( $result ) );
$deny_page = static fn( array $caps, string $cap, int $uid, array $args ): array => 'edit_post' === $cap && $draft_id === (int) ( $args[0] ?? 0 ) ? array( 'do_not_allow' ) : $caps;
add_filter( 'map_meta_cap', $deny_page, 10, 4 );
$result = $publish->execute( array( 'page_id' => $draft_id ) );
remove_filter( 'map_meta_cap', $deny_page, 10 );
$check( 'without edit_post on the page: refused', is_wp_error( $result ) && 'mody_forbidden' === $result->get_error_code() && 'draft' === $status( $draft_id ), $message( $result ) );
wp_set_current_user( 0 );
$result = $publish->execute( array( 'page_id' => $draft_id ) );
wp_set_current_user( $user );
$check( 'logged out: refused', is_wp_error( $result ) && 'draft' === $status( $draft_id ), $message( $result ) );

// The member asks; the model names the page by title (any case).
$fakes    = array(
	array( array( $names['publish_page'], array( 'page_title' => 'mody PUBLISH test draft' ) ) ),
	'Done: "Mody publish test draft" is published.',
);
$requests = array();
$turn     = Agent::respond( 'Publish the Mody publish test draft' );
$rows     = $since();
$sent     = $tool_messages( $requests[1] ?? array() )[0]['content'] ?? '';
$page     = get_post( $draft_id );
$check( 'publish by title: published', 'publish' === $status( $draft_id ) && '' !== $page->post_name && '0000-00-00 00:00:00' !== $page->post_date_gmt, "slug {$page->post_name}, date {$page->post_date_gmt}" );
$check( '...two buttons: view and edit', array( get_permalink( $draft_id ), EditorBubble::open_url( SiteEditor::page_url( $draft_id ) ) ) === array_column( $turn['actions'], 'url' ) && str_starts_with( (string) get_permalink( $draft_id ), home_url( '/' ) ), implode( ' | ', array_column( $turn['actions'], 'label' ) ) );
$check( '...view in a new tab, edit in this one (chat open)', array( false, true ) === array_column( $turn['actions'], 'editor' ) && str_contains( $turn['actions'][1]['url'], 'mody-chat=open' ) );
$check( '...result sent back without URLs', str_contains( $sent, '"status":"publish"' ) && str_contains( $sent, 'as buttons' ) && ! str_contains( $sent, '://' ), mb_strimwidth( $sent, 0, 90, '...' ) );
$check( '...logged', array( 'mody/publish-page' ) === $tools_of( $rows ) && str_contains( (string) $rows[0]['result'], '"status":"publish"' ) && str_contains( (string) $rows[0]['args'], 'mody PUBLISH' ) );
$result = $publish->execute( array( 'page_id' => $draft_id ) );
$check( '...twice: already published', is_wp_error( $result ) && 'mody_page_published' === $result->get_error_code(), $message( $result ) );

// "Publish it" for a page that is gone: no other draft is published without asking (seen 2026-11-06).
$other_id = $make( 'Mody publish test other draft' );
$fakes    = array(
	array( array( $names['publish_page'], array( 'page_id' => 999999999 ) ) ),
	array( array( $names['publish_page'], array( 'page_id' => $other_id ) ) ),
	array( array( 'ask_user', array( 'question' => 'Which page should I publish?', 'choices' => array( 'Mody publish test other draft' ) ) ) ),
);
$requests = array();
$turn     = Agent::respond( 'Publish it' );
$rows     = $since();
$second   = $tool_messages( $requests[2] ?? array() )[1]['content'] ?? '';
$check( 'page gone: no other draft published', 'draft' === $status( $other_id ) && 'Which page should I publish?' === $turn['reply'] && array( 'Mody publish test other draft' ) === $turn['choices'], $turn['reply'] );
$check( '...the second publish refused, told to ask', str_contains( $second, 'mody_publish_needs_answer' ) && str_contains( $second, 'ask_user' ), mb_strimwidth( $second, 0, 90, '...' ) );
$check( '...logged', array( 'mody/publish-page', 'mody/publish-page', 'ask_user' ) === $tools_of( $rows ) && str_starts_with( (string) $rows[1]['result'], 'refused: publish failed' ), implode( ', ', $tools_of( $rows ) ) );

// A draft the member edited by hand, through kses: published as it is, though the validator refuses it.
$edited_id = $make( 'Mody publish test edited', 'draft', 'page', SystemPrompt::EXAMPLE );
add_filter( 'map_meta_cap', $no_html, 10, 2 );
kses_init();
wp_update_post(
	wp_slash(
		array(
			'ID'           => $edited_id,
			'post_content' => SystemPrompt::EXAMPLE . "\n\n<!-- wp:paragraph {\"style\":{\"color\":{\"text\":\"#c0392b\"}}} -->\n<p class=\"has-text-color\" style=\"color:#c0392b\">Edited by hand.</p>\n<!-- /wp:paragraph -->",
		)
	)
);
clean_post_cache( $edited_id );
$edited = get_post( $edited_id )->post_content;
$result = $publish->execute( array( 'page_id' => $edited_id ) );
remove_filter( 'map_meta_cap', $no_html, 10 );
kses_init();
clean_post_cache( $edited_id );
$verdict = BlockValidator::validate( $edited );
$check( 'hand-edited draft: published as it is', is_array( $result ) && 'publish' === $status( $edited_id ) && get_post( $edited_id )->post_content === $edited && str_contains( $edited, 'Edited by hand' ), is_array( $result ) ? strlen( $edited ) . ' bytes, unchanged' : $message( $result ) );
$check( '...which the validator refuses', is_wp_error( $verdict ), mb_strimwidth( $message( $verdict ), 0, 90, '...' ) );

// Site title and tagline.
$options    = static fn(): array => array_map( 'get_option', array( 'blogname', 'blogdescription', BrandSettings::MISSION, BrandSettings::TONE, 'admin_email', 'siteurl', 'home' ) );
$start_site = $options();
$schema     = $settings->get_input_schema();
$check( 'settings: the identity only, title, tagline, mission, tone (M2-08)', array( 'title', 'tagline', 'mission', 'tone' ) === array_keys( $schema['properties'] ) && false === $schema['additionalProperties'] && BrandSettings::TONES === ( $schema['properties']['tone']['enum'] ?? null ) );
$refusals = array( // Input => the error code expected; null: any error (the schema refuses it).
	'nothing given'      => array( array(), 'mody_no_settings' ),
	'empty title'        => array( array( 'title' => '  ' ), 'mody_empty_title' ),
	'title of 121 chars' => array( array( 'title' => str_repeat( 'a', 121 ) ), null ),
	'tagline, 201 chars' => array( array( 'tagline' => str_repeat( 'a', 201 ) ), null ),
	'admin_email'        => array( array( 'admin_email' => 'someone@example.org' ), null ),
	'blogname'           => array( array( 'blogname' => 'Renamed' ), null ),
	'title and siteurl'  => array(
		array(
			'title'   => 'Renamed',
			'siteurl' => 'https://example.org',
		),
		null,
	),
	'title as a list'    => array( array( 'title' => array( 'Renamed' ) ), null ),
	'unknown tone'       => array( array( 'tone' => 'pirate' ), null ),
	'mission, 201 chars' => array( array( 'mission' => str_repeat( 'a', 201 ) ), null ),
);
$wrong    = array();
foreach ( $refusals as $label => list( $input, $code ) ) {
	$result = $settings->execute( $input );
	if ( ! is_wp_error( $result ) || ( null !== $code && $code !== $result->get_error_code() ) ) {
		$wrong[] = $label . ': ' . ( is_wp_error( $result ) ? $result->get_error_code() : 'accepted' );
	}
}
$check( 'settings: refusals', ! $wrong, $wrong ? implode( ', ', $wrong ) : count( $refusals ) . ' refused with the right code' );
$check( '...no option changed', $start_site === $options() );

$deny = static fn( array $caps, string $cap ): array => 'manage_options' === $cap ? array( 'do_not_allow' ) : $caps;
add_filter( 'map_meta_cap', $deny, 10, 2 );
$result = $settings->execute( array( 'title' => 'Renamed' ) );
remove_filter( 'map_meta_cap', $deny, 10 );
$check( 'without manage_options: refused', is_wp_error( $result ) && $start_site === $options(), $message( $result ) );
wp_set_current_user( 0 );
$result = $settings->execute( array( 'tagline' => 'Renamed' ) );
wp_set_current_user( $user );
$check( 'logged out: refused', is_wp_error( $result ) && $start_site === $options(), $message( $result ) );
$result = $settings->execute(
	array(
		'mission' => 'We now bake for schools too.',
		'tone'    => 'formal',
	)
);
$check( 'settings: mission and tone saved as the site\'s settings', is_array( $result ) && 'We now bake for schools too.' === BrandSettings::brand()['mission'] && 'formal' === BrandSettings::brand()['tone'] && 'formal' === ( $result['tone']['new'] ?? '' ), $message( $result ) );
$check( '...and the brand context follows at once', str_contains( SystemPrompt::brand_context(), 'What the brand does: We now bake for schools too.' ) && str_contains( SystemPrompt::brand_context(), 'Tone of voice: Formal' ) );
update_option( BrandSettings::MISSION, $start_site[2] );
update_option( BrandSettings::TONE, $start_site[3] );

$old_tagline = SiteSettings::get( 'tagline' );
$fakes       = array(
	array( array( $names['update_site_settings'], array( 'tagline' => "Fresh <b>sourdough</b>\nfor offices & teams" ) ) ),
	'Done: the tagline is updated.',
);
$requests    = array();
$turn        = Agent::respond( 'Change the tagline to Fresh sourdough for offices & teams' );
$rows        = $since();
$sent        = $tool_messages( $requests[1] ?? array() )[0]['content'] ?? '';
$check( 'tagline: sanitized and saved', 'Fresh sourdough for offices & teams' === SiteSettings::get( 'tagline' ) && 'Fresh sourdough for offices &amp; teams' === get_option( 'blogdescription' ), get_option( 'blogdescription' ) );
$check( '...title unchanged', $start_site[0] === get_option( 'blogname' ), SiteSettings::get( 'title' ) );
$check( '...old and new sent back', str_contains( $sent, '"new":"Fresh sourdough for offices & teams"' ) && ! $turn['actions'], mb_strimwidth( $sent, 0, 90, '...' ) );
$check( '...the editor reloads the site settings (refresh)', array( 'site' ) === $turn['refresh'], wp_json_encode( $turn['refresh'] ) );
$check(
	'...logged with old and new',
	array( 'mody/update-site-settings' ) === $tools_of( $rows ) && array(
		'tagline' => array(
			'old' => $old_tagline,
			'new' => 'Fresh sourdough for offices & teams',
		),
	) === json_decode( (string) $rows[0]['result'], true )
);
$result = $settings->execute(
	array(
		'title'   => 'Ogia Bakery & Co',
		'tagline' => '',
	)
);
$check( 'title and an empty tagline', is_array( $result ) && 'Ogia Bakery & Co' === $result['title']['new'] && '' === $result['tagline']['new'] && 'Ogia Bakery &amp; Co' === get_option( 'blogname' ) && '' === get_option( 'blogdescription' ), is_array( $result ) ? wp_json_encode( $result ) : $message( $result ) );
$check( '...other options unchanged', array_slice( $start_site, 2 ) === array_slice( $options(), 2 ) );
$restore_site();
$check( 'restored', $start_site === $options(), SiteSettings::get( 'title' ) );
$since();

// 6. REST endpoint ----------------------------------------------------------

echo "\nREST endpoint\n";
$rest = static function ( string $method, array $body = array() ) {
	$request = new WP_REST_Request( $method, '/mody-platform/v1/chat' );
	foreach ( $body as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
};
$rest( 'DELETE' );
$fakes    = array( 'Hi there.' );
$requests = array();
$response = $rest( 'POST', array( 'message' => 'Hello' ) );
$data     = $response->get_data();
$check( 'POST runs the job (no PHP-FPM here)', 200 === $response->get_status() && 'done' === ( $data['status'] ?? '' ) && 'Hi there.' === ( $data['turn']['reply'] ?? '' ), $response->get_status() . ' ' . wp_json_encode( $data ) );
$job_id   = (string) ( $data['job'] ?? '' );
$checked  = rest_do_request( new WP_REST_Request( 'GET', '/mody-platform/v1/chat/jobs/' . $job_id ) );
$check( 'checker returns the result', 200 === $checked->get_status() && ( $checked->get_data()['turn'] ?? null ) === ( $data['turn'] ?? false ) );
$job_key  = 'mody_agent_job_' . $job_id;
$job      = get_transient( $job_key );
set_transient( $job_key, array( 'user_id' => $user + 1000 ) + $job, HOUR_IN_SECONDS );
$checked = rest_do_request( new WP_REST_Request( 'GET', '/mody-platform/v1/chat/jobs/' . $job_id ) );
$check( "...another user's job: 404", 404 === $checked->get_status(), (string) $checked->get_status() );
$checked = rest_do_request( new WP_REST_Request( 'GET', '/mody-platform/v1/chat/jobs/' . wp_generate_uuid4() ) );
$check( '...unknown job: 404', 404 === $checked->get_status(), (string) $checked->get_status() );
delete_transient( $job_key );

// A running job blocks a second message; a stale one is failed and logged.
$running_key = 'mody_agent_running_' . $user;
$busy_id     = wp_generate_uuid4();
$busy_job    = array(
	'id'      => $busy_id,
	'user_id' => $user,
	'message' => 'Create a long page',
	'status'  => 'running',
	'started' => time(),
);
set_transient( 'mody_agent_job_' . $busy_id, $busy_job, HOUR_IN_SECONDS );
set_transient( $running_key, $busy_id, HOUR_IN_SECONDS );
$requests = array();
$response = $rest( 'POST', array( 'message' => 'Another one' ) );
$check( 'job running: 409, no model request', 409 === $response->get_status() && ! $requests, $response->get_status() . ' ' . ( $response->get_data()['code'] ?? '' ) );
$since();
set_transient( 'mody_agent_job_' . $busy_id, array( 'started' => time() - Mody\Platform\Agent\ChatController::JOB_TIMEOUT - 10 ) + $busy_job, HOUR_IN_SECONDS );
$checked = rest_do_request( new WP_REST_Request( 'GET', '/mody-platform/v1/chat/jobs/' . $busy_id ) );
$rows    = $since();
$check( 'stale job: reported failed', 'failed' === ( $checked->get_data()['status'] ?? '' ) && true === ( $checked->get_data()['turn']['error'] ?? false ), $checked->get_data()['turn']['reply'] ?? '' );
$check( '...logged, and no longer blocks', 1 === count( $rows ) && str_starts_with( $rows[0]['result'], 'mody_job_timeout' ) && false === get_transient( $running_key ) );
delete_transient( 'mody_agent_job_' . $busy_id );
$rest( 'DELETE' );
$fakes    = array( 'Hi there.' );
$rest( 'POST', array( 'message' => 'Hello' ) );
$history = Mody\Platform\Agent\ChatController::history();
$check( '...conversation stored', 2 === count( $history ) && 'Hello' === $history[0]['text'] && 'Hi there.' === $history[1]['text'] );
$fakes    = array( 'Second answer.' );
$requests = array();
$rest( 'POST', array( 'message' => 'And now?' ) );
$roles = array_column( $requests[0]['messages'] ?? array(), 'role' );
$check( '...sent back as context', array( 'system', 'user', 'assistant', 'user' ) === $roles, implode( ', ', $roles ) );

// A turn that ran an ability: the call is stored with the reply, sent back to the model, never to the client.
$rest( 'DELETE' );
$fakes    = array(
	array( array( $names['open_editor'], array( 'target' => 'styles' ) ) ),
	'Here are the styles.',
);
$response = $rest( 'POST', array( 'message' => 'Open the styles' ) );
$stored   = Mody\Platform\Agent\ChatController::history()[1]['calls'] ?? array();
$check( 'ability calls stored with the reply', 1 === count( $stored ) && $names['open_editor'] === $stored[0]['name'] && array( 'target' => 'styles' ) === $stored[0]['args'] && ! str_contains( (string) wp_json_encode( $stored ), 'site-editor' ), mb_strimwidth( (string) wp_json_encode( $stored ), 0, 90, '...' ) );
$check( '...not sent to the client', ! array_key_exists( 'calls', (array) ( $response->get_data()['turn'] ?? array() ) ) && ! array_key_exists( 'calls', Mody\Platform\Agent\ChatController::display_history()[1] ?? array() ) );
$fakes    = array( 'Glad to help.' );
$requests = array();
$rest( 'POST', array( 'message' => 'Thanks' ) );
$sent  = $requests[0]['messages'] ?? array();
$roles = array_column( $sent, 'role' );
$check( '...sent back to the model as a tool call', array( 'system', 'user', 'assistant', 'tool', 'assistant', 'user' ) === $roles && $names['open_editor'] === ( $sent[2]['tool_calls'][0]['function']['name'] ?? '' ) && ( $sent[2]['tool_calls'][0]['id'] ?? '' ) === ( $sent[3]['tool_call_id'] ?? 'none' ), implode( ', ', $roles ) );
$rest( 'DELETE' );
$check( 'DELETE forgets it', array() === Mody\Platform\Agent\ChatController::history() );
$response = $rest( 'POST', array( 'message' => str_repeat( 'a', Agent::MAX_MESSAGE_LENGTH + 1 ) ) );
$check( 'too long: 400', 400 === $response->get_status(), (string) $response->get_status() );
$response = $rest( 'POST', array() );
$check( 'no message: 400', 400 === $response->get_status(), (string) $response->get_status() );
wp_set_current_user( 0 );
$requests = array();
$response = $rest( 'POST', array( 'message' => 'Delete all pages' ) );
wp_set_current_user( $user );
$check( 'logged out: 401, no model request', 401 === $response->get_status() && ! $requests, (string) $response->get_status() );
$since();

// 7. Retention --------------------------------------------------------------
// Uses the retention in force, so it deletes only what the daily purge would.

echo "\nRetention\n";
$retention = AgentSettings::retention_days();
$saved     = get_site_option( AgentSettings::OPTION, null );
$read      = array();
foreach ( array( 30, 0, 99999 ) as $days ) {
	update_site_option( AgentSettings::OPTION, array( 'retention_days' => $days ) );
	$read[] = AgentSettings::retention_days();
}
null === $saved ? delete_site_option( AgentSettings::OPTION ) : update_site_option( AgentSettings::OPTION, $saved );
$check( 'setting: read, default, capped', array( 30, AgentSettings::DEFAULT_RETENTION_DAYS, AgentSettings::MAX_RETENTION_DAYS ) === $read, implode( ', ', $read ) . " (in force: $retention days)" );

$insert = static function ( int $age_days ) use ( $events, $site_id, $user ): int {
	global $wpdb;
	// phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$events} (user_id, site_id, input, result, created_at) VALUES (%d, %d, 'retention test', 'retention test', DATE_SUB(NOW(), INTERVAL %d DAY))", $user, $site_id, $age_days ) );
	return (int) $wpdb->insert_id;
};
$exists = static function ( int $id ) use ( $events ): bool {
	global $wpdb;
	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
};
$old    = $insert( $retention + 10 );
$recent = $insert( max( 0, $retention - 1 ) );
delete_site_transient( 'mody_agent_events_purged' );
AgentEvents::log( 'retention test', null, null, 'first event of the day', microtime( true ) );
$check( 'first event of the day purges', ! $exists( $old ) && $exists( $recent ) && false !== get_site_transient( 'mody_agent_events_purged' ), "older than $retention days deleted, newer kept" );
$old = $insert( $retention + 10 );
AgentEvents::log( 'retention test', null, null, 'second event', microtime( true ) );
$check( '...once a day only', $exists( $old ) );
$check( 'purge() deletes old events', AgentEvents::purge( $retention ) >= 1 && ! $exists( $old ) );
$since();

ob_start();
AgentSettings::render();
$html = (string) ob_get_clean();
$check( 'settings page renders', str_contains( $html, 'name="retention_days"' ) && str_contains( $html, 'value="' . $retention . '"' ) && str_contains( $html, 'name="_wpnonce"' ) && str_contains( $html, 'edit.php?action=' . AgentSettings::SLUG ) );

// 8. Real round trips -------------------------------------------------------

if ( getenv( 'MODY_TEST_OFFLINE' ) ) {
	echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed (offline).\n";
	return;
}

echo "\nReal round trips (Infomaniak)\n";
$fakes = null;
$real  = static function ( string $text, array $history = array() ) use ( &$requests, $since ): array {
	$requests = array();
	$start    = microtime( true );
	$turn     = Agent::respond( $text, $history );
	$turn['secs']     = sprintf( '%.1fs, %d request(s)', microtime( true ) - $start, count( $requests ) );
	$turn['events']   = $since();
	return $turn;
};

// The background job needs PHP-FPM: a real HTTP request to this site, with a login cookie.
$expiration = time() + 600;
$token      = WP_Session_Tokens::get_instance( $user )->create( $expiration );
$cookies    = array(
	SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user, $expiration, 'secure_auth', $token ),
	LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user, $expiration, 'logged_in', $token ),
);
$_COOKIE[ LOGGED_IN_COOKIE ] = $cookies[ LOGGED_IN_COOKIE ]; // The REST nonce is tied to this session.
register_shutdown_function( static fn() => WP_Session_Tokens::get_instance( $user )->destroy( $token ) );
$http = static function ( string $method, string $path, ?array $body = null ) use ( $cookies ): array {
	$response = wp_remote_request(
		rest_url( 'mody-platform/v1/chat' . $path ),
		array(
			'method'    => $method,
			'cookies'   => $cookies,
			'sslverify' => false, // This site's own certificate (mkcert locally, the internal listener in production).
			'timeout'   => 30,
			'headers'   => array(
				'X-WP-Nonce'   => wp_create_nonce( 'wp_rest' ),
				'Content-Type' => 'application/json',
			),
			'body'      => $body ? wp_json_encode( $body ) : null,
		)
	);
	return array(
		'code' => (int) wp_remote_retrieve_response_code( $response ),
		'data' => (array) json_decode( (string) wp_remote_retrieve_body( $response ), true ),
	);
};

$http( 'DELETE', '' );
$start    = microtime( true );
$posted   = $http( 'POST', '', array( 'message' => 'Create a short About page for our bakery: who we are and how offices can order.' ) );
$answered = microtime( true );
$check( 'background: POST answers 202', 202 === $posted['code'] && 'running' === ( $posted['data']['status'] ?? '' ), sprintf( '%d %s in %.1fs', $posted['code'], $posted['data']['status'] ?? wp_json_encode( $posted['data'] ), $answered - $start ) );
$job   = $posted['data'];
$polls = 0;
while ( 'running' === ( $job['status'] ?? '' ) && microtime( true ) - $start < 150 ) {
	sleep( 2 );
	++$polls;
	$job = $http( 'GET', '/jobs/' . ( $posted['data']['job'] ?? 'none' ) )['data'];
}
$turn    = (array) ( $job['turn'] ?? array() );
$rows    = $since();
$created = preg_match( '/%2Fpage%2F(\d+)/', $turn['actions'][0]['url'] ?? '', $match ) ? (int) $match[1] : 0;
$check( '...checker reports it done', 'done' === ( $job['status'] ?? '' ), sprintf( '%s after %d polls, %.1fs', $job['status'] ?? '?', $polls, microtime( true ) - $start ) );
// Same container, same clock: the client had its answer before the job finished.
$stored = get_transient( 'mody_agent_job_' . ( $posted['data']['job'] ?? '' ) );
$check( '...answer arrived before the job ended', isset( $stored['finished'] ) && $answered < $stored['finished'], isset( $stored['finished'] ) ? sprintf( 'job ended %.1fs after the answer', $stored['finished'] - $answered ) : 'no finish time' );
$created_reply = (string) ( $turn['reply'] ?? '' );
$check( 'create a page: draft saved', $created && 'draft' === $status( $created ) && ! in_array( 'mody/publish-page', $tools_of( $rows ), true ), "page $created, tools: " . implode( ', ', $tools_of( $rows ) ) );
$check( '...button and reply', $created && ! str_contains( $created_reply, 'http' ), mb_strimwidth( $created_reply, 0, 100, '...' ) );
$check( '...does not ask to publish', ! preg_match( '/publish[^.!?]*\?/i', $created_reply ) );
if ( $created && ! getenv( 'MODY_TEST_KEEP_PAGE' ) ) {
	$cleanup_ids[] = $created;
} elseif ( $created ) {
	echo "     kept draft page $created: {$turn['actions'][0]['url']}\n";
}

$target = $created ? $created : $page_id;
$turn   = $real( "Open the editor for page $target" );
$check( 'open the editor for page N', EditorBubble::open_url( SiteEditor::page_url( $target ) ) === ( $turn['actions'][0]['url'] ?? '' ), ( $turn['actions'][0]['url'] ?? 'no button' ) . ', ' . $turn['secs'] );

// "Publish it", over HTTP like the chat page: the conversation stored on the server, with the
// creation's tool call, is the context. A question about which page is answered with the created one.
$http_turn = static function ( string $text ) use ( $http, $since ): array {
	$start  = microtime( true );
	$posted = $http( 'POST', '', array( 'message' => $text ) );
	$job    = $posted['data'];
	while ( 'running' === ( $job['status'] ?? '' ) && microtime( true ) - $start < 150 ) {
		sleep( 1 );
		$job = $http( 'GET', '/jobs/' . ( $posted['data']['job'] ?? 'none' ) )['data'];
	}
	$turn = (array) ( $job['turn'] ?? array() ) + array(
		'reply'   => '',
		'actions' => array(),
		'choices' => array(),
	);
	return array(
		'secs'   => sprintf( '%.1fs', microtime( true ) - $start ),
		'events' => $since(),
	) + $turn;
};
if ( $created && ! getenv( 'MODY_TEST_KEEP_PAGE' ) ) {
	$turn  = $http_turn( 'Publish it' );
	$asked = '';
	if ( $turn['choices'] && 'publish' !== $status( $created ) ) {
		$title = get_the_title( $created );
		$asked = ' (asked: ' . implode( ' | ', $turn['choices'] ) . ')';
		$turn  = $http_turn( array_values( array_filter( $turn['choices'], static fn( $c ) => 0 === strcasecmp( $c, $title ) ) )[0] ?? $title );
	}
	$others = array_filter( $page_statuses, static fn( $st, $id ) => $status( $id ) !== $st, ARRAY_FILTER_USE_BOTH );
	$args   = array_values( array_filter( $turn['events'], static fn( $row ) => 'mody/publish-page' === $row['tool'] ) )[0]['args'] ?? 'no call';
	$check( '"Publish it": published', 'publish' === $status( $created ) && ! $others, "page $created, $args" . ( $others ? ', also changed: ' . implode( ', ', array_keys( $others ) ) : '' ) . $asked . ', ' . $turn['secs'] );
	$check( '...view and edit buttons', array( get_permalink( $created ), EditorBubble::open_url( SiteEditor::page_url( $created ) ) ) === array_column( $turn['actions'], 'url' ) && ! str_contains( $turn['reply'], 'http' ), mb_strimwidth( $turn['reply'], 0, 90, '...' ) );
	$check( '...no calls sent to the client', ! array_key_exists( 'calls', $turn ) );
}

$tagline = 'Sourdough bread for Zurich offices';
$turn    = $real( "Change the tagline to \"$tagline\"" );
$check( '"Change the tagline to …": updated', $tagline === rtrim( SiteSettings::get( 'tagline' ), '.' ) && $site_options['blogname'] === get_option( 'blogname' ), SiteSettings::get( 'tagline' ) . ', ' . $turn['secs'] );
$check( '...logged', array( 'mody/update-site-settings' ) === array_values( array_filter( $tools_of( $turn['events'] ), static fn( $tool ) => str_starts_with( $tool, 'mody/' ) ) ), mb_strimwidth( $turn['reply'], 0, 90, '...' ) );
$restore_site();

foreach ( array( 'Delete all pages', 'Show me the database' ) as $text ) {
	wp_cache_delete( _count_posts_cache_key( 'page' ), 'counts' ); // The background job created a page in another process.
	$pages_before = (int) array_sum( (array) wp_count_posts( 'page' ) );
	$turn         = $real( $text );
	wp_cache_delete( _count_posts_cache_key( 'page' ), 'counts' );
	$ran = array_filter( $tools_of( $turn['events'] ), static fn( $tool ) => str_starts_with( $tool, 'mody/' ) );
	$check( "\"$text\": nothing run", ! $ran && (int) array_sum( (array) wp_count_posts( 'page' ) ) === $pages_before, ( $turn['refused'] ? 'refuse_request' : 'plain-text refusal' ) . ', ' . $turn['secs'] );
	$check( '...logged', (bool) array_filter( $turn['events'], static fn( $row ) => null === $row['tool'] && $text === $row['input'] ), mb_strimwidth( $turn['reply'], 0, 90, '...' ) );
}

// Design: the presets, then two changes, each at once (M2-08: the palette is the brand). Restored at the end.
$turn  = $real( 'Which design presets are there?' );
$named = array_filter( array_column( Presets::all(), 'name' ), static fn( $name ) => str_contains( $turn['reply'], $name ) );
$check( 'presets listed by name', 6 === count( $named ) && ! preg_match( '/#[0-9a-f]{6}/i', $turn['reply'] ), mb_strimwidth( $turn['reply'], 0, 90, '...' ) . ', ' . $turn['secs'] );

$design_runs = array(
	array( 'Make the primary color coral', false ),
	array( 'Use the Forest preset', true ),
);
foreach ( $design_runs as list( $text, $preset ) ) {
	$restore_design();
	$turn    = $real( $text );
	$call    = array_values( array_filter( $turn['events'], static fn( $row ) => 'mody/update-design' === $row['tool'] ) )[0] ?? null;
	$args    = $call ? (array) json_decode( (string) $call['args'], true ) : array();
	$now     = $palette();
	$changed = $preset ? Presets::colors( 'forest' ) : array( 'primary' => strtolower( (string) ( $args['primary'] ?? '' ) ) );
	$check( "\"$text\": changed at once, no question", $call && ! $turn['choices'] && array_intersect_key( $now, $changed ) == $changed && $start_colors['primary'] !== $now['primary'], ( $call ? $call['args'] : 'no call: ' . mb_strimwidth( $turn['reply'], 0, 60, '...' ) ) . ', ' . $turn['secs'] ); // phpcs:ignore Universal.Operators.StrictComparisons
	$screen = SiteEditor::styles_screen( $preset ? 'browse' : 'colors' ); // M3-09: a preset shows Browse styles, a color Colors.
	$check( '...the screen opens, and the reply', EditorBubble::open_url( $screen['url'] ) === ( $turn['actions'][0]['url'] ?? '' ) && true === ( $turn['actions'][0]['navigate'] ?? null ) && ! str_contains( $turn['reply'], 'http' ), mb_strimwidth( $turn['reply'], 0, 90, '...' ) );
}
$restore_design();

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
