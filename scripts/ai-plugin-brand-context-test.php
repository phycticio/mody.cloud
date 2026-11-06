<?php
/**
 * Tests the brand context in the `ai` plugin's features (ticket M3-04,
 * documentation/ai-engine.md "The `ai` plugin's features").
 *
 * Run from the repository root, with INFOMANIAK_* set in .env:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/ai-plugin-brand-context-test.php
 *
 * 1. No brand profile: the `wpai_system_instruction` filter leaves every
 *    feature's instruction unchanged, and so does a title generation with a
 *    fake model answer.
 * 2. With a brand profile: the writing features get the brand, everything
 *    else is unchanged (other features, empty instructions, other values).
 * 3. Every `ai/` feature the plugin registers, with its real instruction: the
 *    writing ones carry the brand, the others don't. A feature this test does
 *    not know fails it, so a plugin update that adds one needs a decision.
 * 4. Title generation end to end, with a fake model answer: the request's
 *    system message carries the plugin's rules and the brand.
 * 5. One real title generation. Add `-e MODY_TEST_OFFLINE=1` after
 *    `docker exec` to skip it.
 *
 * 1, 4 and 5 need the plugin's title generation feature, with the infomaniak
 * connector approved. They are skipped, with a note, when it is not. Both are
 * options of each site, so the run in 1, on another site of the network, is
 * often skipped (locally, alpha has no features on).
 * If the site has no brand profile, a fixture row is inserted and removed at
 * the end. Never prints the token.
 */

use Mody\Platform\AI\PluginBrandContext;
use Mody\Platform\AI\SystemPrompt;
use Mody\Platform\Brand\BrandProfile;

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-44s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};
$note     = static function ( string $label, string $why ): void {
	printf( "%-4s %-44s %s\n", '--', $label, "skipped: $why" );
};

global $wpdb;
$admins = get_super_admins();
wp_set_current_user( get_user_by( 'login', $admins[0] )->ID );
BrandProfile::install();
$site_id = get_current_blog_id();

// What the plugin's features are expected to do. Brand-aware ones must be exactly
// PluginBrandContext::FEATURES. Each entry: brand (true, false; null when the
// feature has no system instruction), and the file and data its class passes.
$expected = array(
	'ai/title-generation'                 => array( true ),
	'ai/excerpt-generation'               => array( true ),
	'ai/meta-description'                 => array( true ),
	'ai/content-resizing'                 => array( true, 'system-instruction.php', array( 'action' => 'expand' ) ),
	'ai/type-ahead'                       => array( true, null, array( 'block_name' => 'core/paragraph' ) ),
	'ai/suggest-reply'                    => array( true ),
	'ai/editorial-updates'                => array( true ),
	'ai/image-prompt-generation'          => array( true, 'image-prompt-system-instruction.php' ),
	'ai/summarization'                    => array( false, 'system-instruction.php', array( 'length' => 'short' ) ),
	'ai/content-translation'              => array( false, 'system-instruction.php', array( 'target_language' => 'German' ) ),
	'ai/slug-generation'                  => array( false, null, array( 'number_of_suggestions' => 3 ) ),
	'ai/revision-change-summary'          => array( false, null, array( 'max_changes' => 5 ) ),
	'ai/alt-text-generation'              => array( false, 'alt-text-system-instruction.php' ),
	'ai/block-container-label-generation' => array( false ),
	'ai/comment-analysis'                 => array( false ),
	'ai/content-classification'           => array( false ),
	'ai/editorial-notes'                  => array( false, null, array( 'block_name' => 'core/paragraph' ) ),
	'ai/image-generation'                 => array( null ),
	'ai/image-import'                     => array( null ),
	'ai/speech-generation'                => array( null ),
);
$left_out = array_keys( array_filter( $expected, static fn( $e ) => false === $e[0] ) );
$branded  = array_keys( array_filter( $expected, static fn( $e ) => true === $e[0] ) );
$check( 'feature list matches the test', $branded === PluginBrandContext::FEATURES, implode( ', ', PluginBrandContext::FEATURES ) );

// Model requests, recorded and answered with fakes at the HTTP layer (null: real requests).
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
$system_of = static function ( array $request ): string {
	$first = $request['messages'][0] ?? array();
	if ( 'system' !== ( $first['role'] ?? '' ) ) {
		return '';
	}
	return is_string( $first['content'] ) ? $first['content'] : (string) wp_json_encode( $first['content'] );
};
$content   = 'Ogia bakes sourdough bread every morning and delivers it to offices in Zurich before nine.';
$title     = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'ai/title-generation' ) : null;

/*
 * Runs title generation with one fake answer. Returns the result and the
 * request sent, or a reason to skip.
 */
$generate = static function ( ?string $fake ) use ( $title, $content, &$requests, &$fakes ): array {
	if ( ! $title ) {
		return array( 'skip' => 'the plugin\'s title generation is not registered (feature or plugin switched off)' );
	}
	$requests = array();
	$fakes    = null === $fake ? null : array( $fake );
	$start    = microtime( true );
	$result   = $title->execute( array( 'content' => $content ) );
	$fakes    = null;
	if ( is_wp_error( $result ) && 'wpai_connector_not_approved' === $result->get_error_code() ) {
		return array( 'skip' => 'the infomaniak connector is not approved for the plugin on this site' );
	}
	return array(
		'result'   => $result,
		'requests' => $requests,
		'secs'     => sprintf( '%.2fs', microtime( true ) - $start ),
	);
};

// 1. No brand profile -------------------------------------------------------

echo "\nNo brand profile\n";
$without = 0;
foreach ( get_sites( array( 'fields' => 'ids' ) ) as $id ) {
	if ( null === BrandProfile::for_site( (int) $id ) ) {
		$without = (int) $id;
		break;
	}
}
if ( $without ) {
	switch_to_blog( $without );
	$changed = array();
	foreach ( array_keys( $expected ) as $name ) {
		if ( 'Rules.' !== apply_filters( 'wpai_system_instruction', 'Rules.', $name, array() ) ) {
			$changed[] = $name;
		}
	}
	$check( 'filter: every feature unchanged', ! $changed, $changed ? implode( ', ', $changed ) : "site $without" );

	$run = $generate( 'Fresh sourdough for Zurich offices' );
	if ( ! isset( $run['skip'] ) && ! $run['requests'] && is_wp_error( $run['result'] ) ) {
		// The plugin's feature switches and connector approvals are options of each site.
		$run['skip'] = "no model request on site $without (" . $run['result']->get_error_message() . ' The plugin\'s features and connector approvals are set per site.)';
	}
	if ( isset( $run['skip'] ) ) {
		$note( 'title generation: no brand sent', $run['skip'] );
	} else {
		$system = $system_of( $run['requests'][0] ?? array() );
		$check( 'title generation: no brand sent', '' !== $system && ! str_contains( $system, '<brand>' ) && str_contains( $system, 'title suggestions' ), is_wp_error( $run['result'] ) ? $run['result']->get_error_message() : '' );
	}
	restore_current_blog();
} else {
	$note( 'no brand profile', 'every site has a brand profile' );
}

// Fixture brand profile, unless the site has one.
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
	register_shutdown_function(
		static function () use ( $fixture_id ): void {
			global $wpdb;
			$wpdb->delete( BrandProfile::table(), array( 'id' => $fixture_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	);
}
$profile = BrandProfile::for_site( $site_id );

// 2. The filter, with a brand profile ---------------------------------------

echo "\nWith a brand profile ({$profile->brand_name}" . ( $fixture_id ? ', fixture' : '' ) . ")\n";
$wrong = array();
foreach ( PluginBrandContext::FEATURES as $name ) {
	$out = apply_filters( 'wpai_system_instruction', 'Rules.', $name, array() );
	if ( SystemPrompt::for_ai_feature( 'Rules.', $profile ) !== $out || ! str_starts_with( $out, "Rules.\n\n" ) ) {
		$wrong[] = $name;
	}
}
$check( 'filter: writing features get the brand', ! $wrong, $wrong ? implode( ', ', $wrong ) : count( PluginBrandContext::FEATURES ) . ' features' );
$out = apply_filters( 'wpai_system_instruction', 'Rules.', 'ai/title-generation', array() );
$check( '...brand name, tone and <brand> tags', str_contains( $out, '<brand>' ) && str_contains( $out, $profile->brand_name ) && str_contains( $out, 'Tone of voice:' ) );
$check( '...the feature\'s rules come first', str_contains( $out, 'The instructions above come first' ) );
$check( '...no hex colors', ! preg_match( '/#[0-9a-f]{3,6}\b/i', $out ) );
$changed = array();
foreach ( $left_out as $name ) {
	if ( 'Rules.' !== apply_filters( 'wpai_system_instruction', 'Rules.', $name, array() ) ) {
		$changed[] = $name;
	}
}
$check( 'filter: other features unchanged', ! $changed, $changed ? implode( ', ', $changed ) : count( $left_out ) . ' features' );
$check( 'filter: empty instruction stays empty', '' === apply_filters( 'wpai_system_instruction', '', 'ai/title-generation', array() ) );
$check( 'filter: other abilities unchanged', 'Rules.' === apply_filters( 'wpai_system_instruction', 'Rules.', 'mody/generate-page', array() ) );
$check( 'filter: non-string left alone', null === PluginBrandContext::filter( null, 'ai/title-generation' ) );

// 3. The plugin's real instructions ------------------------------------------

echo "\nThe plugin's features\n";
$found = array();
if ( function_exists( 'wp_get_abilities' ) && class_exists( \WordPress\AI\Abstracts\Abstract_Ability::class ) ) {
	foreach ( wp_get_abilities() as $ability ) {
		$name = $ability->get_name();
		if ( ! str_starts_with( $name, 'ai/' ) || ! $ability instanceof \WordPress\AI\Abstracts\Abstract_Ability ) {
			continue; // ai/get-post-details and ai/get-post-terms read posts; they call no model.
		}
		$found[] = $name;
		if ( ! isset( $expected[ $name ] ) ) {
			$check( $name, false, 'new feature: decide whether it gets the brand (PluginBrandContext::FEATURES), then add it to this test' );
			continue;
		}
		list( $brand, $file, $data ) = $expected[ $name ] + array( null, null, array() );
		$text = $ability->get_system_instruction( $file, $data );
		$cut  = strpos( $text, "\n\nThe site you write for has the brand below." );
		if ( null === $brand ) {
			$check( $name, '' === $text, 'no system instruction' );
		} elseif ( $brand ) {
			$check( $name, false !== $cut && $cut > 0 && str_contains( $text, '<brand>' ), 'brand' );
		} else {
			$check( $name, '' !== $text && false === $cut && ! str_contains( $text, '<brand>' ), 'no brand' );
		}
	}
}
if ( ! $found ) {
	$note( 'every feature\'s instruction', 'the `ai` plugin registers no features here (plugin or features switched off)' );
} else {
	$off = array_diff( PluginBrandContext::FEATURES, $found );
	if ( $off ) {
		$note( 'not registered', implode( ', ', $off ) . ' (switched off?)' );
	}
}

// 4. Title generation, fake answer ------------------------------------------

echo "\nTitle generation (fake answer)\n";
$run = $generate( 'Fresh sourdough for Zurich offices' );
if ( isset( $run['skip'] ) ) {
	$note( 'title generation', $run['skip'] );
} else {
	$result = $run['result'];
	$system = $system_of( $run['requests'][0] ?? array() );
	$check( 'one model request', 1 === count( $run['requests'] ), count( $run['requests'] ) . ' request(s)' );
	$check( 'the fake title comes back', ! is_wp_error( $result ) && 'Fresh sourdough for Zurich offices' === ( $result['title'] ?? '' ), is_wp_error( $result ) ? $result->get_error_message() : (string) ( $result['title'] ?? '' ) );
	$check( 'system message: the plugin\'s rules', str_contains( $system, 'title suggestions' ) );
	$check( 'system message: the brand', str_contains( $system, '<brand>' ) && str_contains( $system, $profile->brand_name ) );
	$check( 'user message: the content', str_contains( (string) wp_json_encode( $run['requests'][0]['messages'] ?? array() ), 'delivers it to offices in Zurich' ) );
}

// 5. One real title generation ----------------------------------------------

echo "\nTitle generation (real)\n";
if ( getenv( 'MODY_TEST_OFFLINE' ) ) {
	$note( 'real title generation', 'MODY_TEST_OFFLINE is set' );
} else {
	$run = $generate( null );
	if ( isset( $run['skip'] ) ) {
		$note( 'real title generation', $run['skip'] );
	} else {
		$result = $run['result'];
		$check( 'a title comes back', ! is_wp_error( $result ) && '' !== trim( (string) ( $result['title'] ?? '' ) ), is_wp_error( $result ) ? $result->get_error_message() : '"' . ( $result['title'] ?? '' ) . '" ' . $run['secs'] );
		$check( '...the brand was in the system message', str_contains( $system_of( $run['requests'][0] ?? array() ), '<brand>' ) );
	}
}

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
