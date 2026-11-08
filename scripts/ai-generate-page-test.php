<?php
/**
 * Tests page generation (ticket M2-02, documentation/ai-engine.md).
 *
 * Run from the repository root, with INFOMANIAK_* set in .env:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/ai-generate-page-test.php
 *
 * 1. Validator: the few-shot example passes, broken markup is rejected (offline).
 * 2. No brand profile: generation is refused before any model request (offline).
 * 3. Retries: fake model answers (broken, then broken or valid) through the
 *    WordPress HTTP API. Checks the retry count, the feedback, and that nothing
 *    is inserted on failure (offline).
 * 4. One real generation through the ability mody/generate-page.
 *
 * If the site has no brand profile, a test fixture row is inserted and removed
 * at the end, together with the draft page. Add `-e MODY_TEST_KEEP_PAGE=1`
 * after `docker exec` to keep the draft and print its Site Editor URL.
 * Never prints the token.
 */

use Mody\Platform\AI\BlockValidator;
use Mody\Platform\AI\PageGenerator;
use Mody\Platform\AI\SystemPrompt;
use Mody\Platform\Brand\BrandSettings;

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-34s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};
$message  = static fn( $result ): string => is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '';

$admins = get_super_admins();
wp_set_current_user( get_user_by( 'login', $admins[0] )->ID );

// 1. Validator --------------------------------------------------------------

echo "Validator\n";
$result = BlockValidator::validate( SystemPrompt::EXAMPLE );
$check( 'few-shot example is valid', true === $result, $message( $result ) );

$valid = array(
	'palette color through style'  => "<!-- wp:paragraph {\"style\":{\"color\":{\"text\":\"var:preset|color|primary\"}}} -->\n<p class=\"has-text-color\" style=\"color:var(--wp--preset--color--primary)\">Hi</p>\n<!-- /wp:paragraph -->",
	'"#123" in text is not a color' => "<!-- wp:paragraph -->\n<p>Order #123 ships today.</p>\n<!-- /wp:paragraph -->",
	'image from this site'          => "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"" . esc_url( home_url( '/app/uploads/logo.png' ) ) . "\" alt=\"\"/></figure>\n<!-- /wp:image -->",
);
foreach ( $valid as $label => $markup ) {
	$result = BlockValidator::validate( $markup );
	$check( 'accepts: ' . $label, true === $result, $message( $result ) );
}

$broken = array(
	'unclosed block'           => "<!-- wp:paragraph -->\n<p>Hi</p>",
	'stray closer'             => "<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->\n<!-- /wp:group -->",
	'mismatched closer'        => "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:group --></div>\n<!-- /wp:paragraph -->",
	'invalid attribute JSON'   => "<!-- wp:heading {\"level\":1,} -->\n<h1 class=\"wp-block-heading\">Hi</h1>\n<!-- /wp:heading -->",
	'non-canonical comment'    => "<!-- wp:core/paragraph -->\n<p>Hi</p>\n<!-- /wp:core/paragraph -->",
	'Markdown fence'           => "```html\n<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->\n```",
	'explanation before blocks' => "Here is your page:\n\n<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->",
	'loose HTML only'          => '<h1>Hi</h1><p>There</p>',
	'tag typo (seen from Qwen)' => "<!-- wp:paragraph -->\n<Packed with seeds. Great for those who like more crunch.</p>\n<!-- /wp:paragraph -->",
	'unbalanced HTML'          => "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph --></section>\n<!-- /wp:group -->",
	'heading level mismatch'   => "<!-- wp:heading {\"level\":3} -->\n<h2 class=\"wp-block-heading\">Hi</h2>\n<!-- /wp:heading -->",
	'wrong wrapper tag'        => "<!-- wp:paragraph -->\n<div>Hi</div>\n<!-- /wp:paragraph -->",
	'block not on whitelist'   => "<!-- wp:html -->\n<script>alert(1)</script>\n<!-- /wp:html -->",
	'unknown namespace'        => "<!-- wp:uagb/info-box -->\n<div>Hi</div>\n<!-- /wp:uagb/info-box -->",
	'hex color in attributes'  => "<!-- wp:paragraph {\"style\":{\"color\":{\"text\":\"#ff0000\"}}} -->\n<p class=\"has-text-color\" style=\"color:#ff0000\">Hi</p>\n<!-- /wp:paragraph -->",
	'unknown palette slug'     => "<!-- wp:paragraph {\"textColor\":\"vivid-red\"} -->\n<p class=\"has-vivid-red-color has-text-color\">Hi</p>\n<!-- /wp:paragraph -->",
	'hex color in style only'  => "<!-- wp:paragraph -->\n<p style=\"color:#f00\">Hi</p>\n<!-- /wp:paragraph -->",
	'named color in style'     => "<!-- wp:paragraph -->\n<p style=\"background-color:red\">Hi</p>\n<!-- /wp:paragraph -->",
	'external image'           => "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"https://images.example.com/a.jpg\" alt=\"\"/></figure>\n<!-- /wp:image -->",
);
foreach ( $broken as $label => $markup ) {
	$result = BlockValidator::validate( $markup );
	$check( 'rejects: ' . $label, is_wp_error( $result ), is_wp_error( $result ) ? mb_strimwidth( $result->get_error_message(), 0, 90, '...' ) : 'accepted' );
}

// Whitelisted but not registered: take core/spacer out of the registry for one check.
$registry = WP_Block_Type_Registry::get_instance();
$spacer   = $registry->unregister( 'core/spacer' );
$result   = BlockValidator::validate( "<!-- wp:spacer -->\n<div style=\"height:100px\" aria-hidden=\"true\" class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->" );
$registry->register( $spacer );
$check( 'rejects: block not registered', is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : 'accepted' );

// Model requests, counted (and optionally answered with fakes) at the HTTP layer.
$requests = array();
$fakes    = null; // null: let requests through. array: answer chat completions from it, in order.
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
							'message'       => array(
								'role'    => 'assistant',
								'content' => (string) array_shift( $fakes ),
							),
							'finish_reason' => 'stop',
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
$last_user_text = static function () use ( &$requests ): string {
	$messages = end( $requests )['messages'] ?? array();
	$content  = end( $messages )['content'] ?? '';
	return is_array( $content ) ? (string) ( $content[0]['text'] ?? '' ) : (string) $content;
};

// 2. The brand: the site's own settings (M2-08) ---------------------------------

echo "\nThe brand (the site's settings)\n";
global $wpdb;
$site_id     = get_current_blog_id();
$saved_brand = array(
	'blogname'             => get_option( 'blogname' ),
	BrandSettings::MISSION => get_option( BrandSettings::MISSION, '' ),
	BrandSettings::TONE    => get_option( BrandSettings::TONE, '' ),
);
register_shutdown_function(
	static function () use ( $saved_brand ): void {
		foreach ( $saved_brand as $option => $value ) {
			update_option( $option, $value );
		}
	}
);
update_option( 'blogname', 'Ogia Bakery & Co' );
BrandSettings::save( 'We bake sourdough bread for offices in Zurich.', 'technical' );
$system = SystemPrompt::for_page();
$check( 'the prompt reads the site title, mission and tone, decoded', str_contains( $system, '<brand>' ) && str_contains( $system, 'Name: Ogia Bakery & Co' ) && str_contains( $system, 'What the brand does: We bake sourdough bread' ) && str_contains( $system, 'Tone of voice: Technical' ), strlen( $system ) . ' characters' );
update_option( 'blogname', 'Ogia Bakery' );
BrandSettings::save( 'We bake sourdough bread for offices in Zurich.', 'friendly' );
$check( '...and follows a change at once, with nothing to sync', str_contains( SystemPrompt::for_page(), 'Name: Ogia Bakery' . "\n" ) && str_contains( SystemPrompt::for_page(), 'Tone of voice: Friendly' ) );
$check( 'no logo in the prompt (decision 4)', ! str_contains( $system, 'Logo:' ) );

// 3. Retries with fake answers ----------------------------------------------

echo "\nRetries (fake model answers)\n";
$broken_answer = "```html\n<p>Not blocks</p>\n```";
$fakes         = array( $broken_answer, SystemPrompt::EXAMPLE );
$requests      = array();
$result        = PageGenerator::generate_page( $site_id, 'A page for a bike repair shop.' );
$check( 'broken, then valid: accepted', SystemPrompt::EXAMPLE === $result, $message( $result ) );
$check( '...after one retry', 2 === count( $requests ), count( $requests ) . ' request(s)' );
$check( '...retry tells the model why', str_contains( $last_user_text(), 'previous answer was rejected' ) );
$check( '...request settings', 0.1 === (float) ( $requests[0]['temperature'] ?? 0 ) && false === ( $requests[0]['chat_template_kwargs']['enable_thinking'] ?? null ), 'temperature ' . ( $requests[0]['temperature'] ?? '?' ) . ', thinking ' . wp_json_encode( $requests[0]['chat_template_kwargs'] ?? null ) );
$check( '...the retry at a higher temperature', PageGenerator::RETRY_TEMPERATURE === (float) ( $requests[1]['temperature'] ?? 0 ), 'temperature ' . ( $requests[1]['temperature'] ?? '?' ) );

// The `<Packed` typo: a paragraph that lost the `p>` of its opening tag is repaired, then validated.
$typo     = str_replace( '<p>Drop your bike', '<Drop your bike', SystemPrompt::EXAMPLE );
$fakes    = array( $typo );
$requests = array();
$result   = PageGenerator::generate_page( $site_id, 'A page for a bike repair shop.' );
$check( 'typo "<Drop your bike": repaired', SystemPrompt::EXAMPLE === $result, $message( $result ) );
$check( '...with no retry', 1 === count( $requests ), count( $requests ) . ' request(s)' );
$attrs = "<!-- wp:paragraph {\"textColor\":\"primary\"} -->\n<Packed with seeds.</p>\n<!-- /wp:paragraph -->";
$check( '...also with block attributes', str_contains( PageGenerator::repair( $attrs ), "-->\n<p>Packed with seeds.</p>" ) );
$check( '...other tags left alone', $typo !== PageGenerator::repair( $typo ) && SystemPrompt::EXAMPLE === PageGenerator::repair( SystemPrompt::EXAMPLE ) && "<!-- wp:heading -->\n<Packed</h2>" === PageGenerator::repair( "<!-- wp:heading -->\n<Packed</h2>" ) );

$pages_before = (int) wp_count_posts( 'page' )->draft;
$fakes        = array_fill( 0, PageGenerator::MAX_ATTEMPTS, $broken_answer );
$requests     = array();
$result       = wp_get_ability( 'mody/generate-page' )->execute( array( 'prompt' => 'A page for a bike repair shop.' ) );
$check( 'always broken: reported', is_wp_error( $result ) && 'mody_generation_failed' === $result->get_error_code(), $message( $result ) );
$check( '...after ' . PageGenerator::MAX_ATTEMPTS . ' attempts', PageGenerator::MAX_ATTEMPTS === count( $requests ), count( $requests ) . ' request(s)' );
wp_cache_delete( _count_posts_cache_key( 'page' ), 'counts' );
$check( '...nothing inserted', (int) wp_count_posts( 'page' )->draft === $pages_before );

// 4. Real generation through the ability ------------------------------------

echo "\nReal generation (mody/generate-page)\n";
$fakes    = null;
$requests = array();
$ability  = wp_get_ability( 'mody/generate-page' );
$check( 'ability registered', null !== $ability, $ability ? $ability->get_category() : '' );
$start  = microtime( true );
$result = $ability->execute( array( 'prompt' => 'A home page for our bakery: who we are, our breads, and how offices can order.' ) );
$secs   = sprintf( '%.1fs', microtime( true ) - $start );
$check( 'draft page created', is_array( $result ) && 'draft' === get_post_status( $result['page_id'] ), is_array( $result ) ? "page {$result['page_id']} \"{$result['title']}\", $secs, " . count( $requests ) . ' request(s)' : $message( $result ) );

if ( is_array( $result ) ) {
	$content = get_post_field( 'post_content', $result['page_id'] );
	$valid   = BlockValidator::validate( $content );
	$names   = array();
	$parsed  = parse_blocks( $content );
	array_walk_recursive(
		$parsed,
		static function ( $value, $key ) use ( &$names ): void {
			if ( 'blockName' === $key && null !== $value ) {
				$names[] = $value;
			}
		}
	);
	$check( 'saved content is valid', true === $valid, $message( $valid ) . count( $names ) . ' blocks: ' . implode( ', ', array_unique( $names ) ) );
	$check( 'Site Editor URL', str_contains( $result['edit_url'], 'site-editor.php?p=%2Fpage%2F' . $result['page_id'] . '&canvas=edit' ), $result['edit_url'] );

	if ( getenv( 'MODY_TEST_KEEP_PAGE' ) ) {
		echo "     kept draft page {$result['page_id']}: {$result['edit_url']}\n";
	} else {
		wp_delete_post( $result['page_id'], true );
	}
}

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
