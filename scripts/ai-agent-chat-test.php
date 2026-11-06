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
 *    the brand profile row, changed or not (offline).
 * 5. The REST endpoint mody-platform/v1/chat: jobs and the checker (here they
 *    run at once, WP-CLI has no PHP-FPM), another user's job, a busy and a
 *    stale job, permissions, validation, the stored conversation (offline).
 * 6. Retention: the setting, the once-a-day purge, the settings page (offline).
 *    It uses the retention in force, so it deletes only what the daily purge would.
 * 7. Real round trips: create a page over HTTP as a background job, polling the
 *    checker; open it in the editor; two requests that must be refused; the
 *    presets; "make the primary color coral" and "use the Forest preset".
 *    Add `-e MODY_TEST_OFFLINE=1` after `docker exec` to skip them.
 *
 * If the site has no brand profile, a fixture row is inserted and removed at
 * the end. Pages and events created by the test are removed too, the site's
 * Global Styles, its revisions and the profile's colors are restored, and so
 * is the user's chat conversation. Add `-e MODY_TEST_KEEP_PAGE=1` to keep the
 * generated draft. Never prints the token.
 */

use Mody\Platform\Agent\Agent;
use Mody\Platform\Agent\AgentEvents;
use Mody\Platform\Agent\AgentSettings;
use Mody\Platform\AI\SystemPrompt;
use Mody\Platform\Brand\BrandProfile;
use Mody\Platform\Design\Presets;
use Mody\Platform\Design\SiteDesign;
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
BrandProfile::install();
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

// Fixture brand profile, unless the site has one; the user's conversation is restored at the end.
$fixture_id = 0;
if ( null === BrandProfile::for_site( $site_id ) ) {
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		BrandProfile::table(),
		array(
			'user_id'       => get_current_user_id(),
			'site_id'       => $site_id,
			'brand_name'    => 'Ogia Bakery',
			'mission'       => 'We bake sourdough bread for offices in Zurich.',
			'tone_of_voice' => 'friendly',
		)
	);
	$fixture_id = (int) $wpdb->insert_id;
}
$chat_key     = 'mody_agent_chat_' . get_current_user_id();
$saved_chat   = get_transient( $chat_key );
$cleanup_ids  = array();

// The design tests change the site's Global Styles and the profile's colors: restore both.
$profile_id     = BrandProfile::for_site( $site_id )->id;
$profile_colors = $wpdb->get_row( $wpdb->prepare( 'SELECT color_primary, color_secondary, color_accent FROM ' . BrandProfile::table() . ' WHERE id = %d', $profile_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
$styles_id      = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
$styles_content = get_post( $styles_id )->post_content;
$revisions      = wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) );
$restore_design = static function () use ( $profile_id, $profile_colors, $styles_id, $styles_content ): void {
	global $wpdb;
	$wpdb->update( BrandProfile::table(), $profile_colors, array( 'id' => $profile_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( get_post( $styles_id )->post_content !== $styles_content ) {
		wp_update_post( wp_slash( array( 'ID' => $styles_id, 'post_content' => $styles_content ) ), true, false );
	}
	wp_clean_theme_json_cache();
};

register_shutdown_function(
	static function () use ( $fixture_id, $test_mark, $events, $chat_key, $saved_chat, &$cleanup_ids, $restore_design, $styles_id, $revisions ): void {
		global $wpdb;
		$restore_design();
		foreach ( array_diff( wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) ), $revisions ) as $revision ) {
			wp_delete_post_revision( $revision );
		}
		if ( $fixture_id ) {
			$wpdb->delete( BrandProfile::table(), array( 'id' => $fixture_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE id > %d", $test_mark ) ); // phpcs:ignore WordPress.DB
		foreach ( $cleanup_ids as $id ) {
			wp_delete_post( $id, true );
		}
		false === $saved_chat ? delete_transient( $chat_key ) : set_transient( $chat_key, $saved_chat, 2 * HOUR_IN_SECONDS );
	}
);

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
$system = SystemPrompt::for_agent( BrandProfile::for_site( $site_id ), $names );
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

// 2. No brand profile -------------------------------------------------------

echo "\nBrand profile\n";
$without = 0;
foreach ( get_sites( array( 'fields' => 'ids' ) ) as $id ) {
	if ( null === BrandProfile::for_site( (int) $id ) ) {
		$without = (int) $id;
		break;
	}
}
if ( $without ) {
	switch_to_blog( $without );
	$fakes    = array();
	$requests = array();
	$turn     = Agent::respond( 'Create an about page' );
	restore_current_blog();
	$rows = $since();
	$check( 'refused without a brand profile', $turn['error'] && str_contains( $turn['reply'], 'brand profile' ), $turn['reply'] . " (site $without)" );
	$check( '...no model request made', 0 === count( $requests ), count( $requests ) . ' request(s)' );
	$check( '...logged', 1 === count( $rows ) && (int) $without === (int) $rows[0]['site_id'] && str_starts_with( $rows[0]['result'], 'mody_no_brand_profile' ), $rows ? $rows[0]['result'] : 'no event' );
} else {
	echo "     (skipped: every site has a brand profile)\n";
}

// 3. The loop with fake model answers ---------------------------------------

echo "\nAgent loop (fake model answers)\n";
$fakes    = array( 'I can create pages and open the editor.' );
$requests = array();
$turn     = Agent::respond( 'What can you do?' );
$rows     = $since();
$sent     = array_column( array_column( $requests[0]['tools'] ?? array(), 'function' ), 'name' );
$check( 'plain answer', 'I can create pages and open the editor.' === $turn['reply'] && ! $turn['actions'] && ! $turn['error'], $turn['reply'] );
$check( '...tools offered', array() === array_diff( array_values( $names ), $sent ) && 5 === count( $sent ), implode( ', ', $sent ) );
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
$check( '...button to the Site Editor', SiteEditor::page_url( $new_page ) === ( $turn['actions'][0]['url'] ?? '' ), $turn['actions'][0]['label'] ?? '' );
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

// 4. Design: mody/update-design ---------------------------------------------

echo "\nDesign (mody/update-design)\n";
$palette      = static function (): array { // Every theme palette slug => color, as the site shows it.
	wp_clean_theme_json_cache();
	return array_map( 'strtolower', wp_list_pluck( (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) ), 'color', 'slug' ) );
};
$css_color    = static function ( string $slug ): string {
	return preg_match( '/--wp--preset--color--' . $slug . ':\s*([^;]+);/', wp_get_global_stylesheet( array( 'variables' ) ), $m ) ? strtolower( trim( $m[1] ) ) : '';
};
$profile_now  = static fn(): array => BrandProfile::for_site( $site_id )->colors;
$styles_now   = static fn(): string => (string) get_post( $styles_id )->post_content;
$theme_colors = array_map( 'strtolower', wp_list_pluck( WP_Theme_JSON_Resolver::get_theme_data()->get_settings()['color']['palette']['theme'], 'color', 'slug' ) );
$restore_design();
$start_colors = $palette();
$start_brand  = $profile_now();

$check( 'six presets, Mody Teal first', array( 'mody-teal', 'forest', 'ocean', 'plum', 'graphite', 'sand' ) === Presets::slugs() && Presets::DEFAULT === Presets::slugs()[0], Presets::names() );
$check( '...each three hex colors', 6 === count( array_filter( array_map( array( Presets::class, 'colors' ), Presets::slugs() ), static fn( $c ) => is_array( $c ) && 3 === count( array_filter( $c, array( SiteDesign::class, 'is_hex' ) ) ) ) ) );
$default_hex = Presets::colors( Presets::DEFAULT );
$table_hex   = (array) $wpdb->get_row( 'SELECT DEFAULT(color_primary) AS `primary`, DEFAULT(color_secondary) AS secondary, DEFAULT(color_accent) AS accent FROM ' . BrandProfile::table() . ' LIMIT 1', ARRAY_A ); // phpcs:ignore WordPress.DB
$check( '...default = theme.json = table defaults', array_intersect_key( $theme_colors, $default_hex ) == $default_hex && $table_hex == $default_hex, wp_json_encode( $default_hex ) ); // phpcs:ignore Universal.Operators.StrictComparisons
$check( 'brand profile loads its colors', 3 === count( array_filter( $start_brand, array( SiteDesign::class, 'is_hex' ) ) ) && $profile_id > 0, wp_json_encode( $start_brand ) );
$fallback = BrandProfile::from_row(
	array(
		'brand_name'    => 'Fixture',
		'color_primary' => 'red',
		'color_accent'  => '#ABCDEF',
	)
);
$check( '...invalid colors fall back to the default', array( 'primary' => '#005f6b', 'secondary' => '#333333', 'accent' => '#abcdef' ) === $fallback->colors, wp_json_encode( $fallback->colors ) );
$check( 'agent prompt: brand question', str_contains( $system, $names['update_design'] ) && str_contains( $system, 'part of my brand' ) && ! preg_match( '/#[0-9a-f]{6}/i', SystemPrompt::brand_context( BrandProfile::for_site( $site_id ) ) ), 'no hex in the brand context' );

$design = wp_get_ability( 'mody/update-design' );
$invalid = array(
	'bad hex'           => array( 'primary' => '#ff7f5', 'brand' => false ),
	'color name'        => array( 'primary' => 'coral', 'brand' => false ),
	'unknown preset'    => array( 'preset' => 'jungle', 'brand' => false ),
	'no brand'          => array( 'primary' => '#ff7f50' ),
	'base color'        => array( 'base' => '#ffffff', 'brand' => false ),
	'preset and colors' => array( 'preset' => 'forest', 'accent' => '#ff7f50', 'brand' => false ),
	'nothing to change' => array( 'brand' => true ),
);
$codes   = array();
foreach ( $invalid as $label => $input ) {
	$result          = $design->execute( $input );
	$codes[ $label ] = is_wp_error( $result ) ? $result->get_error_code() : 'accepted';
}
$check( 'invalid input refused', ! in_array( 'accepted', $codes, true ) && 'mody_preset_or_colors' === $codes['preset and colors'] && 'mody_no_colors' === $codes['nothing to change'], wp_json_encode( array_count_values( $codes ) ) );
$check( '...nothing changed', $styles_content === $styles_now() && $start_brand === $profile_now() );

$deny = static fn( array $caps, string $cap ): array => in_array( $cap, array( 'edit_theme_options' ), true ) ? array( 'do_not_allow' ) : $caps;
add_filter( 'map_meta_cap', $deny, 10, 2 ); // Super admins pass user_has_cap, not do_not_allow.
$result = $design->execute( array( 'primary' => '#ff7f50', 'brand' => false ) );
remove_filter( 'map_meta_cap', $deny, 10 );
$check( 'without edit_theme_options: refused', is_wp_error( $result ) && $styles_content === $styles_now(), $message( $result ) );
wp_set_current_user( 0 );
$result = $design->execute( array( 'primary' => '#ff7f50', 'brand' => false ) );
wp_set_current_user( $user );
$check( 'logged out: refused', is_wp_error( $result ) && $styles_content === $styles_now(), $message( $result ) );
if ( $without ) {
	switch_to_blog( $without );
	$other_styles = (string) get_post( WP_Theme_JSON_Resolver::get_user_global_styles_post_id() )->post_content;
	$result       = $design->execute( array( 'primary' => '#ff7f50', 'brand' => false ) );
	$unchanged    = (string) get_post( WP_Theme_JSON_Resolver::get_user_global_styles_post_id() )->post_content === $other_styles;
	restore_current_blog();
	wp_clean_theme_json_cache();
	$check( 'no brand profile: refused', is_wp_error( $result ) && 'mody_no_brand_profile' === $result->get_error_code() && $unchanged, $message( $result ) . " (site $without)" );
}

// Ask, then change one color: only this site's design.
$fakes    = array(
	array(
		array(
			Agent::ASK_USER,
			array(
				'question' => 'Is coral part of your brand, or only for this site?',
				'choices'  => array( 'Yes, they are part of my brand', 'No, only this site\'s design' ),
			),
		),
	),
);
$requests = array();
$turn     = Agent::respond( 'Make the primary color coral' );
$rows     = $since();
$check( 'coral: the agent asks first', 2 === count( $turn['choices'] ) && array( 'ask_user' ) === $tools_of( $rows ) && $start_colors === $palette(), $turn['reply'] );
$history  = array(
	array(
		'role' => 'user',
		'text' => 'Make the primary color coral',
	),
	array(
		'role' => 'assistant',
		'text' => $turn['reply'],
	),
);
$fakes    = array(
	array(
		array(
			$names['update_design'],
			array(
				'primary' => '#FF7F50',
				'brand'   => false,
			),
		),
	),
	'Done: the primary color is now coral.',
);
$requests = array();
$turn     = Agent::respond( 'No, only this site\'s design', $history );
$rows     = $since();
$now      = $palette();
$sent     = $tool_messages( $requests[1] ?? array() )[0]['content'] ?? '';
$check( '...brand no: primary changed', '#ff7f50' === $now['primary'] && '#ff7f50' === $css_color( 'primary' ), 'palette ' . $now['primary'] . ', CSS ' . $css_color( 'primary' ) );
$check( '...other colors unchanged', array_diff_key( $now, array( 'primary' => 1 ) ) === array_diff_key( $start_colors, array( 'primary' => 1 ) ) );
$check( '...profile unchanged', $start_brand === $profile_now(), wp_json_encode( $profile_now() ) );
$check( '...button to see the colors', SiteEditor::url( '/styles' ) === ( $turn['actions'][0]['url'] ?? '' ), $turn['actions'][0]['label'] ?? 'no button' );
$check( '...old and new sent back, no URL', str_contains( $sent, '"old":"' . $start_colors['primary'] . '"' ) && str_contains( $sent, '"new":"#ff7f50"' ) && str_contains( $sent, '"brand_profile_updated":false' ) && ! str_contains( $sent, 'site-editor' ), mb_strimwidth( $sent, 0, 90, '...' ) );
$check( '...logged with old and new', array( 'mody/update-design' ) === $tools_of( $rows ) && str_contains( (string) $rows[0]['result'], '"new":"#ff7f50"' ) && str_contains( (string) $rows[0]['args'], '"brand":false' ) );
$stored = json_decode( $styles_now(), true );
$check( '...stored like the Site Editor', count( $theme_colors ) === count( $stored['settings']['color']['palette']['theme'] ?? array() ) && ! empty( $stored['isGlobalStylesUserThemeJSON'] ), 'settings.color.palette.theme, ' . count( $stored['settings']['color']['palette']['theme'] ?? array() ) . ' entries' );
$check( '...a Global Styles revision', count( wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) ) ) > count( $revisions ) );

// A preset, part of the brand.
$fakes    = array(
	array(
		array(
			$names['update_design'],
			array(
				'preset' => 'forest',
				'brand'  => true,
			),
		),
	),
	'Done: your site and your brand now use Forest.',
);
$turn     = Agent::respond( 'Yes, they are part of my brand', array( array( 'role' => 'user', 'text' => 'Use the Forest preset' ), array( 'role' => 'assistant', 'text' => 'Are these colors part of your brand?' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
$rows     = $since();
$now      = $palette();
$forest   = Presets::colors( 'forest' );
$check( 'forest, brand yes: palette', array_intersect_key( $now, $forest ) == $forest && $now['base'] === $start_colors['base'] && $now['contrast'] === $start_colors['contrast'], wp_json_encode( array_intersect_key( $now, $forest ) ) ); // phpcs:ignore Universal.Operators.StrictComparisons
$check( '...brand profile changed too', $forest == $profile_now() && str_contains( (string) $rows[0]['result'], '"brand_profile_updated":true' ), wp_json_encode( $profile_now() ) ); // phpcs:ignore Universal.Operators.StrictComparisons

// "false" as a string is a valid boolean for the schema, and means false.
$result = $design->execute(
	array(
		'accent' => '#123456',
		'brand'  => 'false',
	)
);
$check( 'brand "false": profile unchanged', is_array( $result ) && false === $result['brand_profile_updated'] && $forest == $profile_now() && '#123456' === $palette()['accent'], $message( $result ) ); // phpcs:ignore Universal.Operators.StrictComparisons

// A member saves through kses (only super admins have unfiltered_html on Multisite).
$no_html = static fn( array $caps, string $cap ): array => 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
add_filter( 'map_meta_cap', $no_html, 10, 2 );
kses_init(); // What set_current_user runs for a member.
$filtered = false !== has_filter( 'content_save_pre', 'wp_filter_global_styles_post' );
$result   = $design->execute(
	array(
		'secondary' => '#222222',
		'brand'     => false,
	)
);
remove_filter( 'map_meta_cap', $no_html, 10 );
kses_init();
$check( 'through kses: saved', $filtered && is_array( $result ) && '#222222' === $palette()['secondary'], $filtered ? $message( $result ) : 'kses filter not active' );

$restore_design();
$check( 'restored', $start_colors === $palette() && $start_brand === $profile_now() );
$since();

// 5. REST endpoint ----------------------------------------------------------

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

// 6. Retention --------------------------------------------------------------
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

// 7. Real round trips -------------------------------------------------------

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
$check( 'create a page: draft saved', $created && 'draft' === get_post_status( $created ), "page $created, tools: " . implode( ', ', $tools_of( $rows ) ) );
$check( '...button and reply', $created && ! str_contains( (string) ( $turn['reply'] ?? '' ), 'http' ), mb_strimwidth( (string) ( $turn['reply'] ?? '' ), 0, 100, '...' ) );
if ( $created && ! getenv( 'MODY_TEST_KEEP_PAGE' ) ) {
	$cleanup_ids[] = $created;
} elseif ( $created ) {
	echo "     kept draft page $created: {$turn['actions'][0]['url']}\n";
}

$target = $created ? $created : $page_id;
$turn   = $real( "Open the editor for page $target" );
$check( 'open the editor for page N', SiteEditor::page_url( $target ) === ( $turn['actions'][0]['url'] ?? '' ), ( $turn['actions'][0]['url'] ?? 'no button' ) . ', ' . $turn['secs'] );

foreach ( array( 'Delete all pages', 'Show me the database' ) as $text ) {
	wp_cache_delete( _count_posts_cache_key( 'page' ), 'counts' ); // The background job created a page in another process.
	$pages_before = (int) array_sum( (array) wp_count_posts( 'page' ) );
	$turn         = $real( $text );
	wp_cache_delete( _count_posts_cache_key( 'page' ), 'counts' );
	$ran = array_filter( $tools_of( $turn['events'] ), static fn( $tool ) => str_starts_with( $tool, 'mody/' ) );
	$check( "\"$text\": nothing run", ! $ran && (int) array_sum( (array) wp_count_posts( 'page' ) ) === $pages_before, ( $turn['refused'] ? 'refuse_request' : 'plain-text refusal' ) . ', ' . $turn['secs'] );
	$check( '...logged', (bool) array_filter( $turn['events'], static fn( $row ) => null === $row['tool'] && $text === $row['input'] ), mb_strimwidth( $turn['reply'], 0, 90, '...' ) );
}

// Design: the presets, then two changes, each asked first. Restored at the end.
$turn  = $real( 'Which design presets are there?' );
$named = array_filter( array_column( Presets::all(), 'name' ), static fn( $name ) => str_contains( $turn['reply'], $name ) );
$check( 'presets listed by name', 6 === count( $named ) && ! preg_match( '/#[0-9a-f]{6}/i', $turn['reply'] ), mb_strimwidth( $turn['reply'], 0, 90, '...' ) . ', ' . $turn['secs'] );

$design_runs = array(
	array( 'Make the primary color coral', 1, false ), // Answer with the first choice (brand) or the second (this site only).
	array( 'Use the Forest preset', 0, true ),
);
foreach ( $design_runs as list( $text, $choice, $brand ) ) {
	$restore_design();
	$asked   = $real( $text );
	$ran     = in_array( 'mody/update-design', $tools_of( $asked['events'] ), true );
	$check( "\"$text\": asks first", 2 === count( $asked['choices'] ) && ! $ran, mb_strimwidth( $asked['reply'], 0, 60, '...' ) . ' [' . implode( ' | ', $asked['choices'] ) . '], ' . $asked['secs'] );
	$answer  = $asked['choices'][ $choice ] ?? ( $brand ? 'Yes, they are part of my brand' : 'No, only this site\'s design' );
	$history = array(
		array(
			'role' => 'user',
			'text' => $text,
		),
		array(
			'role' => 'assistant',
			'text' => $asked['reply'],
		),
	);
	$turn    = $real( $answer, $history );
	$call    = array_values( array_filter( $turn['events'], static fn( $row ) => 'mody/update-design' === $row['tool'] ) )[0] ?? null;
	$args    = $call ? (array) json_decode( (string) $call['args'], true ) : array();
	$now     = $palette();
	$changed = $brand ? Presets::colors( 'forest' ) : array( 'primary' => strtolower( (string) ( $args['primary'] ?? '' ) ) );
	$check( '...then updates', $call && array_intersect_key( $now, $changed ) == $changed && $start_colors['primary'] !== $now['primary'], ( $call ? $call['args'] : 'no call' ) . ', ' . $turn['secs'] ); // phpcs:ignore Universal.Operators.StrictComparisons
	$check( $brand ? '...brand profile changed' : '...brand profile unchanged', $brand ? Presets::colors( 'forest' ) == $profile_now() : $start_brand === $profile_now(), wp_json_encode( $profile_now() ) ); // phpcs:ignore Universal.Operators.StrictComparisons
	$check( '...button and reply', SiteEditor::url( '/styles' ) === ( $turn['actions'][0]['url'] ?? '' ) && ! str_contains( $turn['reply'], 'http' ), mb_strimwidth( $turn['reply'], 0, 90, '...' ) );
}
$restore_design();

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
