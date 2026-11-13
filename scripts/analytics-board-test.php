<?php
/**
 * Tests the analytics board, phase 1 (tickets/analytics-chat.md): the query
 * layer (Mody\Analytics\Query), chart specs (Dashboard\ChartSpec) and the
 * board (Dashboard\Board) with its routes.
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/analytics-board-test.php
 *
 * 1. Queries: the allowlists, refusals with reasons, no site from the query.
 * 2. Numbers: the same as Stats (the Visitors section before), every
 *    dimension, breakdowns, the archive for older days.
 * 3. Isolation: a site never counts another's views; no visit ID in answers.
 * 4. Specs: types and dimensions, one scale per axis, colors and contrast.
 * 5. The board: defaults, add, change, remove, move, reset, undo, limits.
 * 6. REST: administrators only; the actions; a bad spec is a 400.
 *
 * Two throwaway sites and two users are created and removed. No model call.
 */

use Mody\Analytics\Query;
use Mody\Analytics\Stats;
use Mody\Analytics\Store;
use Mody\Platform\Dashboard\Board;
use Mody\Platform\Dashboard\ChartSpec;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-66s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

global $wpdb;
$suffix = strtolower( wp_generate_password( 6, false ) );
$new    = static fn( string $login ): int => (int) wp_insert_user(
	array(
		'user_login' => $login . $suffix,
		'user_email' => $login . $suffix . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	)
);
$owner  = $new( 'modyboard' );
$editor = $new( 'modyboarded' );
$domain = preg_replace( '/^www\./', '', get_network()->domain );
$site   = (int) wpmu_create_blog( 'board' . $suffix . '.' . $domain, '/', 'Board test', $owner );
$other  = (int) wpmu_create_blog( 'board2' . $suffix . '.' . $domain, '/', 'Other site', $owner );
add_user_to_blog( $site, $editor, 'editor' );
foreach ( array( $site, $other ) as $id ) {
	switch_to_blog( $id );
	switch_theme( 'theme' ); // As provisioning does: the theme's palette (primary, base...).
	restore_current_blog();
}
register_shutdown_function(
	static function () use ( $owner, $editor, $site, $other ): void {
		foreach ( array( $site, $other ) as $id ) {
			if ( $id ) {
				wpmu_delete_blog( $id, true );
			}
		}
		foreach ( array( $owner, $editor ) as $user_id ) {
			wpmu_delete_user( $user_id );
		}
	}
);
$check( 'two throwaway sites and two users', $site > 0 && $other > 0 && $owner && $editor );

// Known visits: today and two days ago on the test site, and a decoy on the other site.
switch_to_blog( $site );
$today = wp_date( 'Y-m-d' );
$ago   = gmdate( 'Y-m-d', (int) strtotime( $today . ' 00:00:00 UTC' ) - 2 * DAY_IN_SECONDS );
restore_current_blog();
$visit = static function ( int $site_id, string $day, array $paths, string $source = '', string $device = 'desktop', int $hour = 10 ): string {
	$id = bin2hex( random_bytes( 8 ) );
	foreach ( $paths as $i => $path ) {
		Store::add_view(
			array(
				'site_id'  => $site_id,
				'visit_id' => $id,
				'seq'      => $i + 1,
				'day'      => $day,
				'hour'     => $hour,
				'path'     => $path,
				'source'   => 0 === $i ? $source : '',
				'device'   => $device,
			)
		);
	}
	return $id;
};
$ids   = array();
$ids[] = $visit( $site, $today, array( '/', '/menu/', '/order/' ), 'instagram.com', 'mobile', 9 );
$ids[] = $visit( $site, $today, array( '/', '/menu/' ), '', 'mobile', 9 );
$ids[] = $visit( $site, $today, array( '/menu/' ), 'ecosia.org', 'desktop', 14 );
$ids[] = $visit( $site, $ago, array( '/blog/a/', '/' ), '', 'tablet', 20 );
$ids[] = $visit( $site, $ago, array( '/blog/b/' ), 'duckduckgo.com', 'desktop', 20 );
$decoy = $visit( $other, $today, array( '/secret/', '/secret/2/' ), 'other.example', 'desktop', 3 );

// 1. Queries ---------------------------------------------------------------------------

echo "\nQueries\n";
$refused = static function ( array $query ): string {
	$result = Query::normalize( $query );
	return is_wp_error( $result ) ? $result->get_error_message() : '';
};
$check( 'an unknown metric is refused, with the list', str_contains( $refused( array( 'metrics' => array( 'revenue' ) ) ), 'Metrics: visits' ) );
$check( 'an unknown dimension is refused', str_contains( $refused( array( 'dimension' => 'country' ) ), 'Unknown dimension' ) );
$check( 'a site in the query is refused: sites come from the caller', str_contains( $refused( array( 'site_id' => $other ) ), 'Unknown query field' ) );
$check( 'an unknown filter is refused', str_contains( $refused( array( 'filters' => array( 'visit_id' => 'x' ) ) ), 'Unknown filter' ) );
$check( 'SQL in a source filter is refused', str_contains( $refused( array( 'filters' => array( 'source' => "x' OR 1=1 --" ) ) ), 'must be a host' ) );
$check( 'more than 13 months is refused', str_contains( $refused( array( 'days' => 400 ) ), 'days must be' ) );
$check( 'a metric the dimension cannot count is refused', str_contains( $refused( array( 'metrics' => array( 'single_page_rate' ), 'dimension' => 'path' ) ), 'does not work with' ) );
$check( '"next" needs a page to start from', str_contains( $refused( array( 'dimension' => 'next' ) ), 'needs "from"' ) );
$check( 'a breakdown needs time and one count', '' !== $refused( array( 'dimension' => 'path', 'breakdown' => 'device' ) ) && '' !== $refused( array( 'metrics' => array( 'pages_per_visit' ), 'dimension' => 'day', 'breakdown' => 'device' ) ) );
$q = Query::normalize( array( 'metrics' => 'visits', 'dimension' => 'path', 'limit' => 500, 'filters' => array( 'path' => '/blog/*', 'source' => 'WWW.Example.org' ) ) );
$check( 'defaults and cleaning: one metric, the limit capped, prefixes, hosts', ! is_wp_error( $q ) && array( 'visits' ) === $q['metrics'] && Query::MAX_LIMIT === $q['limit'] && '/blog/*' === $q['filters']['path'] && 'example.org' === $q['filters']['source'] );

// 2. Numbers ---------------------------------------------------------------------------

echo "\nNumbers\n";
switch_to_blog( $site );
$run   = static fn( array $query, int $days = 7 ) => Query::run( $query, array( $site ), $days );
$stats = Stats::summary( $site, 7 );
$tile  = $run( array( 'metrics' => array( 'visits', 'views' ), 'dimension' => 'none' ) );
$ratio = $run( array( 'metrics' => array( 'pages_per_visit', 'single_page_rate' ), 'dimension' => 'none' ) );
$value = static fn( array $result, string $metric ) => $result['series'][ array_search( $metric, array_column( $result['series'], 'metric' ), true ) ]['data'][0];
$check( 'totals: 5 visits, 9 views, as the Visitors section counts them', 5 === $value( $tile, 'visits' ) && 9 === $value( $tile, 'views' ) && $stats['visits'] === $value( $tile, 'visits' ) && $stats['views'] === $value( $tile, 'views' ) );
$check( '... pages per visit 1.8, single-page visits 40%', 1.8 === (float) $value( $ratio, 'pages_per_visit' ) && 40.0 === (float) $value( $ratio, 'single_page_rate' ) );
$days = $run( array( 'metrics' => array( 'visits' ), 'dimension' => 'day' ) );
$check( 'by day: every day of the period, in order, the right counts', 7 === count( $days['keys'] ) && $today === end( $days['keys'] ) && 3 === end( $days['series'][0]['data'] ) && 2 === $days['series'][0]['data'][4] && 5 === array_sum( $days['series'][0]['data'] ) );
$paths = $run( array( 'metrics' => array( 'views', 'entries', 'exits' ), 'dimension' => 'path' ) );
$row   = array_search( '/menu/', $paths['keys'], true );
$check( 'by page: views, entries and exits of /menu/ (3, 1, 2)', false !== $row && 3 === $paths['series'][0]['data'][ $row ] && 1 === $paths['series'][1]['data'][ $row ] && 2 === $paths['series'][2]['data'][ $row ] );
$check( '... the same pages as the Visitors section', array_column( $stats['pages'], 'count', 'value' ) == array_combine( $paths['keys'], $paths['series'][0]['data'] ) ); // phpcs:ignore Universal.Operators.StrictComparisons -- Order of equal counts may differ.
$entries = $run( array( 'metrics' => array( 'visits', 'single_page_rate' ), 'dimension' => 'entry' ) );
$check( 'by entry page, with single-page visits: / 2 at 0%, /menu/ 1 at 100%', array( 2, 1 ) === array( $entries['series'][0]['data'][ array_search( '/', $entries['keys'], true ) ], $entries['series'][0]['data'][ array_search( '/menu/', $entries['keys'], true ) ] ) && 100.0 === (float) $entries['series'][1]['data'][ array_search( '/menu/', $entries['keys'], true ) ] );
$exits = $run( array( 'metrics' => array( 'visits' ), 'dimension' => 'exit' ) );
$check( 'by exit page: /menu/ 2', 2 === $exits['series'][0]['data'][ array_search( '/menu/', $exits['keys'], true ) ] );
$journeys = $run( array( 'metrics' => array( 'visits' ), 'dimension' => 'journey' ) );
$check( 'journeys: visits of two pages or more, as Stats finds them', 3 === count( $journeys['keys'] ) && in_array( "/\n/menu/\n/order/", $journeys['keys'], true ) && count( $stats['journeys'] ) === count( $journeys['keys'] ) );
$next = $run( array( 'metrics' => array( 'visits' ), 'dimension' => 'next', 'from' => '/' ) );
$check( 'next pages after /: /menu/ 2', array( '/menu/' ) === $next['keys'] && array( 2 ) === $next['series'][0]['data'] );
$sources = $run( array( 'metrics' => array( 'visits' ), 'dimension' => 'source' ) );
$check( 'sources: direct 2, three hosts 1 each, labelled', 2 === $sources['series'][0]['data'][ array_search( '', $sources['keys'], true ) ] && in_array( 'Direct, or no source given', $sources['labels'], true ) && 4 === count( $sources['keys'] ) );
$filtered = $run( array( 'metrics' => array( 'visits' ), 'dimension' => 'device', 'filters' => array( 'path' => '/blog/*' ) ) );
$check( 'a prefix filter: visits that saw /blog/ pages (tablet 1, desktop 1)', array( 'desktop' => 1, 'tablet' => 1 ) === ( static function () use ( $filtered ) { $a = array_combine( $filtered['keys'], $filtered['series'][0]['data'] ); ksort( $a ); return $a; } )() );
$hours = $run( array( 'metrics' => array( 'visits' ), 'dimension' => 'hour' ) );
$check( 'by hour: 24 hours, 9:00 has 2, 20:00 has 2', 24 === count( $hours['keys'] ) && 2 === $hours['series'][0]['data'][9] && 2 === $hours['series'][0]['data'][20] && '09:00' === $hours['labels'][9] );
$grid = $run( array( 'metrics' => array( 'visits' ), 'dimension' => 'hour_weekday' ) );
$check( 'hour by weekday: 168 cells adding up to 5', 168 === count( $grid['keys'] ) && 5 === array_sum( $grid['series'][0]['data'] ) );
$weekday = $run( array( 'metrics' => array( 'visits' ), 'dimension' => 'weekday', 'compare' => true ) );
$check( 'by weekday, with the period before: 7 days, Monday first, previous aligned', 7 === count( $weekday['keys'] ) && 5 === array_sum( $weekday['series'][0]['data'] ) && 7 === count( (array) $weekday['series'][0]['previous'] ) );
$split = $run( array( 'metrics' => array( 'visits' ), 'dimension' => 'day', 'breakdown' => 'device' ) );
$check( 'a breakdown: one series per device, adding up to the total', 3 === count( $split['series'] ) && 5 === array_sum( array_map( 'array_sum', array_column( $split['series'], 'data' ) ) ) );
for ( $i = 0; $i < 7; $i++ ) {
	$visit( $site, $today, array( '/x/' ), 'host' . $i . '.example' );
}
$split = $run( array( 'metrics' => array( 'visits' ), 'dimension' => 'day', 'breakdown' => 'source' ) );
$check( '... the top 5 and "Other", still adding up', 6 === count( $split['series'] ) && '__other' === end( $split['series'] )['key'] && 12 === array_sum( array_map( 'array_sum', array_column( $split['series'], 'data' ) ) ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test: an archived day, as the retention leaves it.
$wpdb->insert( Store::daily(), array( 'site_id' => $site, 'day' => gmdate( 'Y-m-d', (int) strtotime( $today . ' 00:00:00 UTC' ) - 5 * DAY_IN_SECONDS ), 'kind' => 'total', 'value' => '', 'views' => 40, 'visitors' => 10 ) );
$archived = $run( array( 'metrics' => array( 'visits' ), 'dimension' => 'day' ) );
$check( 'archived days: their daily totals fill the chart', 10 === $archived['series'][0]['data'][1] && 22 === array_sum( $archived['series'][0]['data'] ) );
$check( '... but not a filtered chart, which needs the visits', 0 === $run( array( 'metrics' => array( 'visits' ), 'dimension' => 'day', 'filters' => array( 'device' => 'desktop' ) ) )['series'][0]['data'][1] );

// 3. Isolation -------------------------------------------------------------------------

echo "\nIsolation\n";
$everything = wp_json_encode(
	array(
		$run( array( 'metrics' => array( 'views' ), 'dimension' => 'path', 'limit' => 50 ) ),
		$run( array( 'metrics' => array( 'visits' ), 'dimension' => 'source', 'limit' => 50 ) ),
		$run( array( 'metrics' => array( 'visits' ), 'dimension' => 'journey', 'limit' => 50 ) ),
		$run( array( 'metrics' => array( 'visits' ), 'dimension' => 'hour_weekday' ) ),
	)
);
$quoted = $run( array( 'metrics' => array( 'visits' ), 'dimension' => 'device', 'filters' => array( 'path' => "/' OR '1'='1" ) ) );
$check( 'quotes in a path filter are only a value: nothing matches', ! is_wp_error( $quoted ) && 0 === count( $quoted['keys'] ) );
$check( 'a site never counts another site\'s views', ! str_contains( $everything, 'secret' ) && ! str_contains( $everything, 'other.example' ) );
$check( 'no visit ID in any answer', ! array_filter( array_merge( $ids, array( $decoy ) ), static fn( string $id ): bool => str_contains( $everything, $id ) ) );
$card = Board::with_data( ChartSpec::validate( array( 'title' => 'Pages', 'type' => 'list', 'query' => array( 'metrics' => array( 'views' ), 'dimension' => 'path' ) ) ), 7 );
$check( 'the board counts the current site only', ! in_array( '/secret/', $card['data']['keys'], true ) && in_array( '/menu/', $card['data']['keys'], true ) );
restore_current_blog();
switch_to_blog( $other );
$card = Board::with_data( ChartSpec::validate( array( 'title' => 'Pages', 'type' => 'list', 'query' => array( 'metrics' => array( 'views' ), 'dimension' => 'path' ) ) ), 7 );
$check( '... and on the other site, only its own', array( '/secret/', '/secret/2/' ) === $card['data']['keys'] );
restore_current_blog();

// 4. Specs -----------------------------------------------------------------------------

echo "\nSpecs\n";
switch_to_blog( $site );
$why = static function ( array $spec ): string {
	$result = ChartSpec::validate( $spec );
	return is_wp_error( $result ) ? $result->get_error_message() : '';
};
$base = array(
	'title' => 'A chart',
	'type'  => 'bar',
	'query' => array(
		'metrics'   => array( 'visits' ),
		'dimension' => 'day',
	),
);
$check( 'a good spec passes, with defaults', '' === $why( $base ) && 'full' === ChartSpec::validate( $base )['size'] && str_starts_with( ChartSpec::validate( $base )['id'], 'chart-' ) );
$check( 'an unknown type is refused, with the list', str_contains( $why( array( 'type' => 'radar' ) + $base ), 'Types: tile' ) );
$check( 'a chart needs a title', str_contains( $why( array( 'title' => '  ' ) + $base ), 'needs a title' ) );
$check( 'an unknown field is refused (no code, no data in a spec)', str_contains( $why( $base + array( 'data' => array( 1, 2 ) ) ), 'Unknown chart field' ) );
$check( 'lines need time', str_contains( $why( array( 'type' => 'line', 'query' => array( 'dimension' => 'path' ) ) + $base ), 'time dimension' ) );
$check( 'a tile shows one number', str_contains( $why( array( 'type' => 'tile' ) + $base ), 'one number' ) );
$check( 'a donut needs parts of one whole (not visits by page)', str_contains( $why( array( 'type' => 'donut', 'query' => array( 'dimension' => 'path' ) ) + $base ), 'parts of one whole' ) && '' === $why( array( 'type' => 'donut', 'query' => array( 'metrics' => array( 'views' ), 'dimension' => 'path' ) ) + $base ) );
$mixed = array( 'query' => array( 'metrics' => array( 'visits', 'single_page_rate' ), 'dimension' => 'entry' ) );
$check( 'counts and ratios never share an axis, but may share a table', str_contains( $why( $mixed + $base ), 'cannot share an axis' ) && '' === $why( array( 'type' => 'table' ) + $mixed + $base ) );
$check( 'a stacked chart needs a breakdown', str_contains( $why( array( 'type' => 'stacked' ) + $base ), 'needs a breakdown' ) );
$palette = \Mody\Platform\Login\Schemes::palette();
$dark    = array_keys( array_filter( $palette, static fn( string $hex ): bool => \Mody\Platform\Login\Color::contrast( $hex, '#ffffff' ) >= 3 ) );
$check( 'colors: a palette name, "admin" and hex pass', '' !== ( $dark[0] ?? '' ) && '' === $why( array( 'style' => array( 'colors' => array( $dark[0], 'admin', '#1f6feb' ) ) ) + $base ), implode( ',', $dark ) );
$check( '... a light color is refused with its contrast', str_contains( $why( array( 'style' => array( 'colors' => array( '#ffff00' ) ) ) + $base ), 'too light' ) && str_contains( $why( array( 'style' => array( 'colors' => array( 'base' ) ) ) + $base ), 'too light' ) );
$check( '... an unknown color or a CSS expression is refused', str_contains( $why( array( 'style' => array( 'colors' => array( 'url(x)' ) ) ) + $base ), 'Unknown color' ) && str_contains( $why( array( 'style' => array( 'colors' => array( 'red' ) ) ) + $base ), 'Unknown color' ) );
$check( 'a title is plain text, cut to 80 characters', 'Hi' === ChartSpec::validate( array( 'title' => '<script>x</script>Hi' ) + $base )['title'] && ChartSpec::MAX_TITLE === mb_strlen( ChartSpec::validate( array( 'title' => str_repeat( 'a', 200 ) ) + $base )['title'] ) );

// 5. The board -------------------------------------------------------------------------

echo "\nThe board\n";
$check( 'a new site has the default board', Board::is_default() && array_column( Board::defaults(), 'id' ) === array_column( Board::cards(), 'id' ) && ! Board::can_undo() );
$payload = Board::payload( 7 );
$tiles   = array_combine( array_column( array_column( $payload['cards'], 'spec' ), 'id' ), $payload['cards'] );
$check( 'the default board shows today\'s numbers (12 visits and 10 archived, in 7 days)', 22 === $tiles['visits']['data']['series'][0]['data'][0] && 'admin' === $tiles['visits']['colors']['series'][0], wp_json_encode( array( $tiles['visits']['data']['series'][0] ?? null, $tiles['visits']['colors'] ?? null ) ) );
$added = Board::apply( 'add', array( 'spec' => array( 'id' => 'visits' ) + array( 'title' => 'Devices', 'type' => 'donut', 'query' => array( 'metrics' => array( 'visits' ), 'dimension' => 'device' ) ), 'after' => '' ) );
$check( 'add: a new id when taken, at the top when asked', ! is_wp_error( $added ) && 'visits-2' === $added['cards'][0]['id'] && array( 'visits-2' ) === $added['changed'] && ! Board::is_default() && Board::can_undo() );
$check( '... a donut gets the series colors, not one', Board::SERIES_COLORS === Board::colors( $added['cards'][0] )['series'] );
$changed = Board::apply( 'change', array( 'id' => 'visits-per-day', 'spec' => array( 'type' => 'line', 'style' => array( 'colors' => array( '#6250d6' ) ), 'query' => array( 'days' => 14 ) ) ) );
$card    = array_column( is_wp_error( $changed ) ? array() : $changed['cards'], null, 'id' )['visits-per-day'] ?? array();
$check( 'change: type, color and period, the rest kept', 'line' === ( $card['type'] ?? '' ) && array( '#6250d6' ) === $card['style']['colors'] && 14 === $card['query']['days'] && array( 'visits' ) === $card['query']['metrics'] && 'day' === $card['query']['dimension'] );
$bad = Board::apply( 'change', array( 'id' => 'visits-per-day', 'spec' => array( 'type' => 'donut' ) ) );
$check( '... a change that does not fit is refused, and nothing changes', is_wp_error( $bad ) && 'line' === array_column( Board::cards(), null, 'id' )['visits-per-day']['type'] );
$moved = Board::apply( 'move', array( 'id' => 'devices', 'to' => 0 ) );
$check( 'move: to a position', ! is_wp_error( $moved ) && 'devices' === $moved['cards'][0]['id'] );
$moved = Board::apply( 'move', array( 'id' => 'devices', 'to' => 'down' ) );
$check( '... and one step', ! is_wp_error( $moved ) && 'devices' === $moved['cards'][1]['id'] );
$removed = Board::apply( 'remove', array( 'id' => 'journeys' ) );
$check( 'remove', ! is_wp_error( $removed ) && ! in_array( 'journeys', array_column( Board::cards(), 'id' ), true ) );
$undone = Board::apply( 'undo' );
$check( 'undo: one step back', ! is_wp_error( $undone ) && in_array( 'journeys', array_column( Board::cards(), 'id' ), true ) && ! Board::can_undo() );
$check( '... and not twice', is_wp_error( Board::apply( 'undo' ) ) );
$unknown = Board::apply( 'remove', array( 'id' => 'nope' ) );
$check( 'an unknown chart is refused, with the board\'s ids', is_wp_error( $unknown ) && str_contains( $unknown->get_error_message(), 'visits-per-day' ) );
$reset = Board::apply( 'reset' );
$check( 'reset: the default board, and Undo brings ours back', ! is_wp_error( $reset ) && Board::is_default() && Board::can_undo() && ! is_wp_error( Board::apply( 'undo' ) ) && ! Board::is_default() );
update_option( Board::OPTION, array_merge( Board::cards(), array( array( 'id' => 'broken', 'type' => 'radar' ) ) ), false );
$check( 'a stored card that no longer passes is left out', ! in_array( 'broken', array_column( Board::cards(), 'id' ), true ) );
for ( $i = count( Board::cards() ); $i < Board::MAX_CARDS; $i++ ) {
	Board::apply( 'add', array( 'spec' => $base ) );
}
$full = Board::apply( 'add', array( 'spec' => $base ) );
$check( 'at most 24 charts', is_wp_error( $full ) && Board::MAX_CARDS === count( Board::cards() ) );
Board::apply( 'reset' );

// 6. REST ------------------------------------------------------------------------------

echo "\nREST\n";
$rest = static function ( string $method, string $route, array $params = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/mody-platform/v1' . $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_get_server()->dispatch( $request );
};
wp_set_current_user( $editor );
$check( 'an editor cannot read the board', 403 === $rest( 'GET', '/analytics/board' )->get_status() || 401 === $rest( 'GET', '/analytics/board' )->get_status() );
wp_set_current_user( $owner );
$out = $rest( 'GET', '/analytics/board', array( 'days' => 7 ) );
$check( 'the owner reads it: the cards with their numbers', 200 === $out->get_status() && count( Board::defaults() ) === count( $out->get_data()['cards'] ) && 7 === $out->get_data()['days'] );
$out = $rest( 'POST', '/analytics/board', array( 'action' => 'remove', 'id' => 'devices', 'days' => 7 ) );
$check( 'a card button: remove, and the new board comes back', 200 === $out->get_status() && ! in_array( 'devices', array_column( array_column( $out->get_data()['cards'], 'spec' ), 'id' ), true ) && $out->get_data()['canUndo'] );
$out = $rest( 'POST', '/analytics/board', array( 'action' => 'add', 'days' => 7 ) );
$check( 'adding is not a card button (the analyst\'s, phase 2)', 400 === $out->get_status() );
$out = $rest( 'POST', '/analytics/board/chart', array( 'spec' => array( 'title' => 'x', 'type' => 'line', 'query' => array( 'dimension' => 'path' ) ) ) );
$check( 'a bad spec is a 400 with its reason', 400 === $out->get_status() && str_contains( (string) $out->as_error()->get_error_message(), 'time dimension' ) );
$out = $rest( 'POST', '/analytics/board/chart', array( 'spec' => $base, 'days' => 7 ) );
$check( 'a good spec: its numbers, nothing saved', 200 === $out->get_status() && 7 === count( $out->get_data()['data']['keys'] ) && ! in_array( 'A chart', array_column( Board::cards(), 'title' ), true ) );
wp_set_current_user( 0 );
restore_current_blog();

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
