<?php
/**
 * How the agent is doing: a summary of the `turn` rows that every message
 * writes in the agent's audit log (Agent\TurnMetrics, 2026-11-11).
 * Read-only. Works on any environment, production included.
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/agent-metrics.php
 *
 * Options (after `docker exec`): `-e MODY_DAYS=30` (default 7), `-e MODY_SITE=12`
 * (one site; default every site).
 *
 * Prints: turns; time per turn (median, 90th percentile); model calls,
 * tokens and the share of the prompt read from Infomaniak's cache; the
 * page writer's attempts and how often an answer was rejected, with the
 * commonest reasons; outcomes (acted, answered, asked, held, refused,
 * error) and the tools used.
 */

use Mody\Platform\Agent\AgentEvents;
use Mody\Platform\Agent\TurnMetrics;

global $wpdb;
$days  = max( 1, (int) ( getenv( 'MODY_DAYS' ) ?: 7 ) );
$site  = (int) getenv( 'MODY_SITE' );
$table = AgentEvents::table();
$where = $wpdb->prepare( 'tool = %s AND created_at >= %s', TurnMetrics::TOOL, gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) . ( $site ? $wpdb->prepare( ' AND site_id = %d', $site ) : '' );
$rows  = $wpdb->get_results( "SELECT site_id, duration_ms, result FROM {$table} WHERE {$where} ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB

if ( ! $rows ) {
	printf( "No agent turns in the last %d day(s)%s.\n", $days, $site ? " on site $site" : '' );
	return;
}

$durations = array();
$sum       = array_fill_keys( array( 'requests', 'prompt_tokens', 'cached_tokens', 'completion_tokens', 'writer_attempts', 'writer_rejected' ), 0 );
$outcomes  = array();
$tools     = array();
$reasons   = array();
$sites     = array();
foreach ( $rows as $row ) {
	$data = json_decode( (string) $row['result'], true );
	if ( ! is_array( $data ) ) {
		continue;
	}
	$durations[]                 = (int) $row['duration_ms'];
	$sites[ (int) $row['site_id'] ] = true;
	foreach ( array_keys( $sum ) as $key ) {
		$sum[ $key ] += (int) ( $data[ $key ] ?? 0 );
	}
	$outcomes[ (string) ( $data['outcome'] ?? '?' ) ] = ( $outcomes[ (string) ( $data['outcome'] ?? '?' ) ] ?? 0 ) + 1;
	foreach ( (array) ( $data['tools'] ?? array() ) as $tool ) {
		$tools[ (string) $tool ] = ( $tools[ (string) $tool ] ?? 0 ) + 1;
	}
	foreach ( (array) ( $data['rejections'] ?? array() ) as $reason ) {
		$reasons[ (string) $reason ] = ( $reasons[ (string) $reason ] ?? 0 ) + 1;
	}
}
$count = count( $durations );
sort( $durations );
$at = static fn( float $share ): int => $durations[ (int) min( $count - 1, floor( $share * $count ) ) ];
arsort( $outcomes );
arsort( $tools );
arsort( $reasons );

printf( "Agent turns, last %d day(s)%s: %d on %d site(s)\n\n", $days, $site ? " on site $site" : '', $count, count( $sites ) );
printf( "Time per turn:     median %.1f s, 90th percentile %.1f s, slowest %.1f s\n", $at( 0.5 ) / 1000, $at( 0.9 ) / 1000, end( $durations ) / 1000 );
printf( "Model calls:       %.1f per turn\n", $sum['requests'] / $count );
printf( "Prompt tokens:     %d per turn, %d%% from the cache\n", (int) round( $sum['prompt_tokens'] / $count ), $sum['prompt_tokens'] ? (int) round( 100 * $sum['cached_tokens'] / $sum['prompt_tokens'] ) : 0 );
printf( "Answer tokens:     %d per turn\n", (int) round( $sum['completion_tokens'] / $count ) );
printf( "Page writer:       %d answers, %d rejected (%d%%)\n", $sum['writer_attempts'], $sum['writer_rejected'], $sum['writer_attempts'] ? (int) round( 100 * $sum['writer_rejected'] / $sum['writer_attempts'] ) : 0 );
echo "\nOutcomes:\n";
foreach ( $outcomes as $outcome => $n ) {
	printf( "  %-10s %4d  %3d%%\n", $outcome, $n, (int) round( 100 * $n / $count ) );
}
echo "\nTools:\n";
foreach ( array_slice( $tools, 0, 12, true ) as $tool => $n ) {
	printf( "  %-32s %4d\n", $tool, $n );
}
if ( $reasons ) {
	echo "\nWhy the writer's answers were rejected (commonest first):\n";
	foreach ( array_slice( $reasons, 0, 10, true ) as $reason => $n ) {
		printf( "  %4d  %s\n", $n, $reason );
	}
}
