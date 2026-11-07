<?php
/**
 * Tests editing templates and template parts from the editor (ticket M3-07).
 *
 * Run from the repository root, with INFOMANIAK_* set in .env:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/agent-template-test.php
 *
 * 1. The validator's template mode: every template and part of the theme
 *    passes; page mode refuses theme blocks; an edit must keep post-content
 *    and query; blocks outside both lists are refused.
 * 2. EditorContext for templates and parts: their string IDs, title and
 *    area; unknown or malformed IDs (400); users without edit_theme_options (403).
 * 3. The agent with fake answers: the header part, its prompt, the edit with
 *    the template's ID; a writer that keeps removing post-content.
 * 4. Real requests: the tagline in the header, a line in the footer, the
 *    title out of the page template; the agent's tool choice, probed. Add
 *    `-e MODY_TEST_OFFLINE=1` after `docker exec` to skip them.
 *
 * Nothing is saved: edits go back to the editor. A fixture brand profile and
 * an editor user are removed at the end, with the agent events made here.
 */

use Mody\Platform\Agent\Agent;
use Mody\Platform\Agent\AgentEvents;
use Mody\Platform\Agent\EditorContext;
use Mody\Platform\AI\BlockValidator;
use Mody\Platform\Brand\BrandProfile;

require_once ABSPATH . 'wp-admin/includes/user.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-48s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};
$message  = static fn( $result ): string => is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '';

global $wpdb;
$admins = get_super_admins();
$admin  = get_user_by( 'login', $admins[0] )->ID;
wp_set_current_user( $admin );
BrandProfile::install();
AgentEvents::install();
$site_id = get_current_blog_id();
$events  = AgentEvents::table();
$mark    = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$events}" ); // phpcs:ignore WordPress.DB
$fixture = 0;
if ( null === BrandProfile::for_site( $site_id ) ) {
	$wpdb->insert( BrandProfile::table(), array( 'user_id' => $admin, 'site_id' => $site_id, 'brand_name' => 'Ogia Bakery', 'mission' => 'We bake sourdough bread for offices in Zurich.', 'tone_of_voice' => 'friendly' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$fixture = (int) $wpdb->insert_id;
}
$editor = wp_insert_user( array( 'user_login' => 'modytpl' . wp_generate_password( 6, false, false ), 'user_email' => 'modytpl' . wp_rand() . '@mody.local', 'user_pass' => wp_generate_password( 24 ), 'role' => 'editor' ) );
register_shutdown_function(
	static function () use ( $fixture, $events, $mark, $editor ): void {
		global $wpdb;
		if ( $fixture ) {
			$wpdb->delete( BrandProfile::table(), array( 'id' => $fixture ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE id > %d", $mark ) ); // phpcs:ignore WordPress.DB
		is_multisite() ? wpmu_delete_user( $editor ) : wp_delete_user( $editor );
	}
);

// Model requests, recorded and answered with fakes (null: real requests).
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
		$answer = array(
			'role'    => 'assistant',
			'content' => is_string( $fake ) ? $fake : null,
		);
		if ( is_array( $fake ) ) {
			foreach ( $fake as $i => $call ) {
				$answer['tool_calls'][] = array(
					'id'       => 'call_' . count( $requests ) . '_' . $i,
					'type'     => 'function',
					'function' => array(
						'name'      => $call[0],
						'arguments' => wp_json_encode( (object) $call[1] ),
					),
				);
			}
		}
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
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
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
$text_in   = static fn( $content ): string => is_string( $content ) ? $content : implode( '', array_map( static fn( $part ) => is_array( $part ) ? (string) ( $part['text'] ?? '' ) : (string) $part, (array) $content ) );
$system_of = static fn( array $request ): string => $text_in( $request['messages'][0]['content'] ?? '' );
$names_in  = static function ( string $markup ): array {
	$names = array();
	$walk  = static function ( array $blocks ) use ( &$walk, &$names ): void {
		foreach ( $blocks as $block ) {
			if ( $block['blockName'] ) {
				$names[] = $block['blockName'];
			}
			$walk( $block['innerBlocks'] );
		}
	};
	$walk( parse_blocks( $markup ) );
	return $names;
};

$header = get_block_template( 'theme//header', 'wp_template_part' );
$footer = get_block_template( 'theme//footer', 'wp_template_part' );
$page   = get_block_template( 'theme//page', 'wp_template' );
$index  = get_block_template( 'theme//index', 'wp_template' );
$ctx    = static fn( WP_Block_Template $t ): array|null|WP_Error => EditorContext::from_input(
	array(
		'post_type' => $t->type,
		'post_id'   => $t->id,
		'content'   => $t->content,
	)
);

// 1. The validator's template mode ---------------------------------------------------------

echo "Validator: template mode\n";
$theme = get_theme_root() . '/theme';
$bad   = array();
foreach ( array_merge( glob( $theme . '/templates/*.html' ), glob( $theme . '/parts/*.html' ) ) as $file ) {
	if ( true !== BlockValidator::validate( trim( (string) file_get_contents( $file ) ), '', true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$bad[] = basename( $file );
	}
}
$check( 'every template and part of the theme passes', ! $bad, $bad ? implode( ', ', $bad ) : '' );
$check( 'page mode refuses theme blocks (the page template)', is_wp_error( BlockValidator::validate( trim( $page->content ) ) ) );
$without_content = str_replace( '<!-- wp:post-content {"layout":{"type":"constrained"}} /-->', '', $page->content );
$out             = BlockValidator::validate( $without_content, $page->content, true );
$check( 'an edit that drops post-content is refused', is_wp_error( $out ) && str_contains( $out->get_error_message(), 'core/post-content' ), $message( $out ) );
$no_query = preg_replace( '#<!-- wp:query .*<!-- /wp:query -->#s', '', $index->content );
$check( '...and one that drops the query loop', is_wp_error( BlockValidator::validate( $no_query, $index->content, true ) ) );
$check( 'blocks outside both lists are refused', is_wp_error( BlockValidator::validate( $header->content . "\n\n<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>x</code></pre>\n<!-- /wp:code -->", $header->content, true ) ) );

// 2. EditorContext ----------------------------------------------------------------------------

echo "\nEditorContext for templates\n";
$valid = $ctx( $header );
$check( 'the header part: its ID, title and area', is_array( $valid ) && 'theme//header' === $valid['post_id'] && 'Header' === $valid['title'] && 'header' === $valid['area'], is_array( $valid ) ? $valid['title'] . ', ' . $valid['area'] : $message( $valid ) );
$valid = $ctx( $page );
$check( 'the page template', is_array( $valid ) && 'theme//page' === $valid['post_id'] && '' === $valid['area'] );
$wrong = array();
foreach ( array( 'theme//no-such-part', 'header', '../theme//header', 7 ) as $id ) {
	$out = EditorContext::from_input( array( 'post_type' => 'wp_template_part', 'post_id' => $id ) );
	if ( ! is_wp_error( $out ) || 400 !== ( $out->get_error_data()['status'] ?? 0 ) ) {
		$wrong[] = (string) $id;
	}
}
$check( 'unknown or malformed IDs: 400', ! $wrong, implode( ', ', $wrong ) );
wp_set_current_user( $editor );
$out = $ctx( $header );
wp_set_current_user( $admin );
$check( 'without edit_theme_options: 403', is_wp_error( $out ) && 403 === ( $out->get_error_data()['status'] ?? 0 ) );

// 3. The agent, fake answers ---------------------------------------------------------------------

echo "\nThe agent (fake answers)\n";
$tagline  = str_replace( '<!-- wp:site-title {"level":0} /-->', "<!-- wp:site-title {\"level\":0} /-->\n\n<!-- wp:site-tagline /-->", $header->content );
$fakes    = array( array( array( Agent::EDIT_CONTENT, array( 'instruction' => 'Add the tagline under the site title' ) ) ), $tagline, 'Done: the tagline is in the header on every page, shown in the editor and not saved yet.' );
$requests = array();
$turn     = Agent::respond( 'Add the tagline under the site title', array(), $ctx( $header ) );
$edit     = $turn['edits'][0] ?? array();
$check( 'header part: the edit with the template\'s ID', 'theme//header' === ( $edit['post_id'] ?? '' ) && array_key_exists( 'client_id', $edit ) && null === $edit['client_id'] && trim( $tagline ) === $edit['markup'] && str_contains( $edit['markup'], 'wp:site-tagline' ), (string) ( $edit['post_id'] ?? 'no edit' ) );
$check( '...the agent knew it is the layout', str_contains( $system_of( $requests[0] ?? array() ), 'editing the header template part "Header" (ID theme//header)' ) && str_contains( $system_of( $requests[0] ?? array() ), 'every page that uses it' ) );
$check( '...the writer got the template rules', str_contains( $system_of( $requests[1] ?? array() ), 'never remove post-content' ) && str_contains( $system_of( $requests[1] ?? array() ), '<brand>' ) );

$fakes    = array( array( array( Agent::EDIT_CONTENT, array( 'instruction' => 'Remove everything' ) ) ), $without_content, $without_content, $without_content, 'That did not work.' );
$requests = array();
$turn     = Agent::respond( 'Remove everything from this template', array(), $ctx( $page ) );
$sent     = (string) wp_json_encode( end( $requests )['messages'] ?? array() );
$check( 'a writer that drops post-content: refused 3 times', ! $turn['edits'] && 5 === count( $requests ) && str_contains( $sent, 'mody_edit_failed' ), count( $requests ) . ' requests' );
$retry = $requests[2]['messages'] ?? array();
$check( '...and told why', str_contains( $text_in( end( $retry )['content'] ?? '' ), 'Keep the core/post-content block' ) );

// 4. Real requests -------------------------------------------------------------------------------------

if ( getenv( 'MODY_TEST_OFFLINE' ) ) {
	echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed (offline).\n";
	return;
}

echo "\nReal requests\n";
$fakes = null;
$real  = static function ( string $text, ?array $context ) use ( &$requests ): array {
	$requests = array();
	$start    = microtime( true );
	$turn     = Agent::respond( $text, array(), $context );
	return $turn + array( 'secs' => sprintf( '%.1fs, %d request(s)', microtime( true ) - $start, count( $requests ) ) );
};

$turn  = $real( 'Add the site tagline under the site title', $ctx( $header ) );
$names = $names_in( (string) ( $turn['edits'][0]['markup'] ?? '' ) );
$check( 'header: the tagline added, title, logo and menu kept', in_array( 'core/site-tagline', $names, true ) && ! array_diff( array( 'core/site-title', 'core/site-logo', 'core/navigation' ), $names ), implode( ', ', array_unique( $names ) ) . ', ' . $turn['secs'] );

$turn   = $real( 'Add a short line at the bottom of the footer: Made with care in Zurich', $ctx( $footer ) );
$markup = (string) ( $turn['edits'][0]['markup'] ?? '' );
$check( 'footer: the line added', str_contains( $markup, 'Made with care in Zurich' ) && in_array( 'core/site-title', $names_in( $markup ), true ), $turn['secs'] );

$turn  = $real( 'Remove the page title from this template', $ctx( $page ) );
$names = $names_in( (string) ( $turn['edits'][0]['markup'] ?? '' ) );
$check( 'page template: the title out, the content kept', $names && ! in_array( 'core/post-title', $names, true ) && in_array( 'core/post-content', $names, true ), implode( ', ', array_unique( $names ) ) . ', ' . $turn['secs'] );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
