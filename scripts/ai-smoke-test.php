<?php
/**
 * Smoke test for the AI integration (documentation/ai-engine.md).
 *
 * Run from the repository root, with INFOMANIAK_* set in .env:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/ai-smoke-test.php
 *
 * Checks the Infomaniak provider, then plain text, Gutenberg blocks (validated
 * with WordPress's parser), function calling, JSON output and image input through
 * wp_ai_client_prompt(), and one feature of the `ai` plugin if it is active.
 * Never prints the token.
 */

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-22s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};
$timed = static function ( callable $fn ): array {
	$start  = microtime( true );
	$result = $fn();
	return array( $result, sprintf( '%.2fs', microtime( true ) - $start ) );
};

$registry   = AiClient::defaultRegistry();
$registered = $registry->hasProvider( 'infomaniak' );
$check( 'provider registered', $registered, $registered ? '' : 'set INFOMANIAK_AI_BASE_URL in .env, then docker compose up -d wordpress' );
if ( ! $registered ) {
	exit( 1 );
}
$check( 'token works', $registry->isProviderConfigured( 'infomaniak' ), 'model list fetched with INFOMANIAK_API_KEY' );

list( $r, $secs ) = $timed( fn() => wp_ai_client_prompt( 'Reply with exactly: OK' )->using_max_tokens( 20 )->generate_text_result() );
$check( 'plain text', ! is_wp_error( $r ) && 'OK' === trim( $r->toText() ), is_wp_error( $r ) ? $r->get_error_message() : $r->getModelMetadata()->getId() . " $secs" );

list( $out, $secs ) = $timed(
	fn() => wp_ai_client_prompt( 'A hero for a bakery called Ogia: one level-1 heading and one paragraph.' )
		->using_system_instruction( 'Return ONLY serialized WordPress Gutenberg block markup. No explanations, no Markdown, no backticks. Use only core/heading and core/paragraph. Colors must be palette slugs (primary, secondary, accent), never hex.' )
		->using_temperature( 0.1 )
		->using_max_tokens( 800 )
		->generate_text()
);
$blocks_ok = ! is_wp_error( $out )
	&& count( array_filter( parse_blocks( $out ), fn( $b ) => null !== $b['blockName'] ) ) >= 2
	&& trim( serialize_blocks( parse_blocks( $out ) ) ) === trim( $out )
	&& ! preg_match( '/#[0-9a-fA-F]{6}/', $out );
$check( 'gutenberg blocks', $blocks_ok, is_wp_error( $out ) ? $out->get_error_message() : $secs );

list( $r, $secs ) = $timed(
	fn() => wp_ai_client_prompt( 'Make the primary color coral.' )
		->using_system_instruction( 'You manage a WordPress site. Use the provided tools when the user asks for a change.' )
		->using_function_declarations(
			new FunctionDeclaration(
				'update_design',
				'Change a palette color of the site.',
				array(
					'type'       => 'object',
					'properties' => array(
						'slug'  => array( 'type' => 'string', 'enum' => array( 'primary', 'secondary', 'accent' ) ),
						'color' => array( 'type' => 'string' ),
					),
					'required'   => array( 'slug', 'color' ),
				)
			)
		)
		->generate_text_result()
);
$call = null;
if ( ! is_wp_error( $r ) ) {
	foreach ( $r->toMessage()->getParts() as $part ) {
		$call = $call ?? $part->getFunctionCall();
	}
}
$check( 'function calling', null !== $call && 'update_design' === $call->getName(), $call ? json_encode( $call->getArgs() ) . " $secs" : ( is_wp_error( $r ) ? $r->get_error_message() : 'no function call' ) );

list( $out, $secs ) = $timed(
	fn() => wp_ai_client_prompt( 'Give a tagline for a bakery called Ogia.' )
		->as_json_response( array( 'type' => 'object', 'properties' => array( 'tagline' => array( 'type' => 'string' ) ), 'required' => array( 'tagline' ) ) )
		->generate_text()
);
$json = is_wp_error( $out ) ? null : json_decode( trim( $out ), true );
$check( 'json output', isset( $json['tagline'] ), is_wp_error( $out ) ? $out->get_error_message() : $secs );

// Image input: a red circle drawn with GD, sent inline as a data URI.
$img = imagecreatetruecolor( 120, 120 );
imagefill( $img, 0, 0, imagecolorallocate( $img, 255, 255, 255 ) );
imagefilledellipse( $img, 60, 60, 90, 90, imagecolorallocate( $img, 220, 30, 30 ) );
ob_start();
imagepng( $img );
$png = 'data:image/png;base64,' . base64_encode( (string) ob_get_clean() );
list( $out, $secs ) = $timed( fn() => wp_ai_client_prompt( 'Name the color of the shape in one word.' )->with_file( $png )->using_max_tokens( 20 )->generate_text() );
$check( 'image input', ! is_wp_error( $out ) && false !== stripos( $out, 'red' ), is_wp_error( $out ) ? $out->get_error_message() : trim( $out ) . " $secs" );

if ( function_exists( 'wp_get_ability' ) && wp_get_ability( 'ai/title-generation' ) ) {
	$admins = get_super_admins();
	wp_set_current_user( get_user_by( 'login', $admins[0] )->ID );
	list( $r, $secs ) = $timed( fn() => wp_get_ability( 'ai/title-generation' )->execute( array( 'content' => 'Ogia is a small bakery that bakes sourdough every morning.' ) ) );
	$hint = is_wp_error( $r ) && str_contains( $r->get_error_message(), 'approved' ) ? ' (approve it in Settings > Connector Approvals)' : '';
	$check( 'ai plugin: titles', ! is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() . $hint : ( $r['title'] ?? json_encode( $r ) ) . " $secs" );
}

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
