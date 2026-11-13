<?php
/**
 * Tests visitor location (tickets/visitor-location.md): our MMDB reader on
 * a small database the test writes itself, the locator, what the beacon
 * stores, the 5-visit rule for cities, the archive, the board's cards, the
 * analyst's knowledge, and that the database file is never served.
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/visitor-location-test.php
 *
 * With the real file in place (bin/geoip), a few known addresses are also
 * looked up in it. A throwaway site is created and removed.
 */

use Mody\Analytics\Collector;
use Mody\Analytics\Geo\Locator;
use Mody\Analytics\Geo\Reader;
use Mody\Analytics\Query;
use Mody\Analytics\Store;
use Mody\Platform\Dashboard\Analyst;
use Mody\Platform\Dashboard\Board;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-66s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

// A tiny MMDB writer: an IPv4 tree with 24-bit records, and a data section. -------------------

/**
 * Encodes one MMDB value.
 *
 * @param mixed $value A string, an int (uint32) or an array (a map when it has string keys, else an array).
 */
$encode = static function ( $value ) use ( &$encode ): string {
	$control = static function ( int $type, int $size ): string {
		$extended = $type > 7;
		$first    = ( $extended ? 0 : $type << 5 );
		if ( $size < 29 ) {
			return chr( $first | $size ) . ( $extended ? chr( $type - 7 ) : '' );
		}
		return chr( $first | 29 ) . ( $extended ? chr( $type - 7 ) : '' ) . chr( $size - 29 );
	};
	if ( is_string( $value ) ) {
		return $control( 2, strlen( $value ) ) . $value;
	}
	if ( is_int( $value ) ) {
		$bytes = ltrim( pack( 'N', $value ), "\0" );
		return $control( 6, strlen( $bytes ) ) . $bytes;
	}
	if ( is_array( $value ) && array_is_list( $value ) ) {
		return $control( 11, count( $value ) ) . implode( '', array_map( $encode, $value ) );
	}
	$out = $control( 7, count( $value ) );
	foreach ( $value as $key => $item ) {
		$out .= $encode( (string) $key ) . $encode( $item );
	}
	return $out;
};
$networks = array(
	array( '81.10.0.0', 16, array( 'country' => array( 'iso_code' => 'ES' ), 'city' => array( 'names' => array( 'en' => 'Barcelona (Gràcia)' ) ) ) ),
	array( '82.20.30.0', 24, array( 'country' => array( 'iso_code' => 'FR' ), 'city' => array( 'names' => array( 'en' => 'Perros-Guirec' ) ) ) ),
	array( '83.0.0.0', 8, array( 'country' => array( 'iso_code' => 'DE' ) ) ),
);
$data     = '';
$offsets  = array();
foreach ( $networks as $i => $network ) {
	$offsets[ $i ] = strlen( $data );
	$data         .= $encode( $network[2] );
}
// The tree: node arrays [left, right]; null is empty, array('data' => i) a record.
$tree = array( array( null, null ) );
foreach ( $networks as $i => $network ) {
	$ip   = unpack( 'N', inet_pton( $network[0] ) )[1];
	$node = 0;
	for ( $bit = 0; $bit < $network[1]; $bit++ ) {
		$dir = ( $ip >> ( 31 - $bit ) ) & 1;
		if ( $bit === $network[1] - 1 ) {
			$tree[ $node ][ $dir ] = array( 'data' => $i );
			break;
		}
		if ( ! is_int( $tree[ $node ][ $dir ] ) ) {
			$tree[]                = array( null, null );
			$tree[ $node ][ $dir ] = count( $tree ) - 1;
		}
		$node = $tree[ $node ][ $dir ];
	}
}
$count  = count( $tree );
$record = static function ( $value ) use ( $count, $offsets ): int {
	if ( null === $value ) {
		return $count;
	}
	return is_int( $value ) ? $value : $count + 16 + $offsets[ $value['data'] ];
};
$file = '';
foreach ( $tree as $node ) {
	$file .= substr( pack( 'N', $record( $node[0] ) ), 1 ) . substr( pack( 'N', $record( $node[1] ) ), 1 );
}
$file .= str_repeat( "\0", 16 ) . $data . "\xAB\xCD\xEFMaxMind.com" . $encode(
	array(
		'binary_format_major_version' => 2,
		'binary_format_minor_version' => 0,
		'build_epoch'                 => 1794528000,
		'database_type'               => 'Test-City',
		'description'                 => array( 'en' => 'A test database' ),
		'ip_version'                  => 4,
		'languages'                   => array( 'en' ),
		'node_count'                  => $count,
		'record_size'                 => 24,
	)
);
$path = get_temp_dir() . 'mody-geo-test-' . wp_generate_password( 6, false ) . '.mmdb';
file_put_contents( $path, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- A test file.
register_shutdown_function( static fn() => wp_delete_file( $path ) );

// 1. The reader -------------------------------------------------------------------------------

echo "The reader\n";
$reader = new Reader( $path );
$check( 'the metadata: type, IPv4, 24-bit records', 'Test-City' === $reader->metadata()['database_type'] && 4 === $reader->metadata()['ip_version'] && 24 === $reader->metadata()['record_size'] );
$check( 'an address in a /16 finds its record', 'ES' === ( $reader->get( '81.10.200.3' )['country']['iso_code'] ?? '' ) );
$check( '... in a /24 and a /8', 'Perros-Guirec' === ( $reader->get( '82.20.30.77' )['city']['names']['en'] ?? '' ) && 'DE' === ( $reader->get( '83.255.0.1' )['country']['iso_code'] ?? '' ) );
$check( 'an address outside every network: nothing', null === $reader->get( '82.20.31.1' ) && null === $reader->get( '9.9.9.9' ) );
$check( 'IPv6 in an IPv4 database, and junk: nothing', null === $reader->get( '2001:db8::1' ) && null === $reader->get( 'not an address' ) );
$broken = get_temp_dir() . 'mody-geo-broken.mmdb';
file_put_contents( $broken, str_repeat( 'x', 2000 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- A test file.
$thrown = false;
try {
	new Reader( $broken );
} catch ( \RuntimeException $e ) {
	$thrown = true;
}
wp_delete_file( $broken );
$check( 'a file that is not MMDB is refused', $thrown );

// 2. The locator ------------------------------------------------------------------------------

echo "\nThe locator\n";
Locator::use( $reader );
$check( 'country and city; a district in brackets goes', array( 'country' => 'ES', 'city' => 'Barcelona' ) === Locator::locate( '81.10.1.1' ) );
$check( 'a country without a city', array( 'country' => 'DE', 'city' => '' ) === Locator::locate( '83.1.2.3' ) );
$check( 'private and local addresses are never looked up', array( 'country' => '', 'city' => '' ) === Locator::locate( '192.168.1.5' ) && array( 'country' => '', 'city' => '' ) === Locator::locate( '127.0.0.1' ) );
$check( 'country names in words', 'Spain' === Locator::country_name( 'ES' ) || 'ES' === Locator::country_name( 'ES' ) );

// 3. What the beacon stores, and the 5-visit rule ---------------------------------------------

echo "\nStored and counted\n";
$suffix = strtolower( wp_generate_password( 6, false ) );
$site   = (int) wpmu_create_blog( 'geo' . $suffix . '.' . preg_replace( '/^www\./', '', get_network()->domain ), '/', 'Geo test', get_current_user_id() ? get_current_user_id() : 1 );
register_shutdown_function( static fn() => wpmu_delete_blog( $site, true ) );
switch_to_blog( $site );
switch_theme( 'theme' );
$ua = 'Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/131.0';
for ( $i = 1; $i <= 6; $i++ ) {
	Collector::count( '/', '', 1440, '81.10.0.' . $i, $ua ); // Six visitors from Barcelona.
}
Collector::count( '/', '', 390, '82.20.30.9', $ua );   // One from Perros-Guirec.
Collector::count( '/menu/', '', 390, '83.4.4.4', $ua ); // One from somewhere in Germany.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test.
$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Store::views() . ' WHERE site_id = %d', $site ), ARRAY_A );
$check( 'each view carries a country and a city', 8 === count( $rows ) && 6 === count( array_filter( $rows, static fn( array $r ): bool => 'ES' === $r['country'] && 'Barcelona' === $r['city'] ) ) );
$check( '... and never the address', ! str_contains( (string) wp_json_encode( $rows ), '81.10.0' ) && ! str_contains( (string) wp_json_encode( $rows ), '82.20.30' ) );
$cities = Query::run( array( 'metrics' => array( 'visits' ), 'dimension' => 'city' ), array( $site ), 7 );
$labels = array_combine( $cities['keys'], $cities['series'][0]['data'] );
$check( 'cities: Barcelona (6) by name; the town of 1 under its country', 6 === ( $labels['ES|Barcelona'] ?? 0 ) && 1 === ( $labels['~FR'] ?? 0 ) && ! isset( $labels['FR|Perros-Guirec'] ), wp_json_encode( $labels ) );
$check( '... a place known only by its country is also under it', 1 === ( $labels['~DE'] ?? 0 ) );
$check( '... labelled in words', in_array( 'Other places in France', $cities['labels'], true ) || in_array( 'Other places in FR', $cities['labels'], true ) );
$town = Query::run( array( 'metrics' => array( 'visits' ), 'dimension' => 'device', 'filters' => array( 'city' => 'Perros-Guirec' ) ), array( $site ), 7 );
$check( 'a filter on a town under 5 visits is refused, with the reason', is_wp_error( $town ) && 'mody_analytics_small_place' === $town->get_error_code() && str_contains( $town->get_error_message(), 'privacy' ) );
$big = Query::run( array( 'metrics' => array( 'visits' ), 'dimension' => 'device', 'filters' => array( 'city' => 'Barcelona' ) ), array( $site ), 7 );
$check( '... a city with 5 or more is answered', ! is_wp_error( $big ) && 6 === array_sum( $big['series'][0]['data'] ) );
$countries = Query::run( array( 'metrics' => array( 'visits' ), 'dimension' => 'country' ), array( $site ), 7 );
$check( 'countries are always shown, even with one visit', array( 'ES' => 6, 'DE' => 1, 'FR' => 1 ) == array_combine( $countries['keys'], $countries['series'][0]['data'] ) ); // phpcs:ignore Universal.Operators.StrictComparisons -- Order of equal counts may differ.
$check( 'a country filter, a country breakdown', ! is_wp_error( Query::run( array( 'metrics' => array( 'visits' ), 'dimension' => 'day', 'filters' => array( 'country' => 'es' ), 'breakdown' => 'country' ), array( $site ), 7 ) ) && is_wp_error( Query::normalize( array( 'filters' => array( 'country' => 'Spain' ) ) ) ) );
$check( 'no breakdown by city (it would show small towns over time)', is_wp_error( Query::normalize( array( 'dimension' => 'day', 'breakdown' => 'city' ) ) ) );

// 4. The archive, the board, the analyst --------------------------------------------------------

echo "\nArchive, board, analyst\n";
$old = gmdate( 'Y-m-d', (int) strtotime( '-14 months' ) );
Store::add_view(
	array(
		'site_id'  => $site,
		'visit_id' => 'geoarchive000000',
		'seq'      => 1,
		'day'      => $old,
		'hour'     => 9,
		'path'     => '/',
		'source'   => '',
		'device'   => 'mobile',
		'country'  => 'PT',
		'city'     => 'Porto',
	)
);
Store::archive_before( Store::retention_start() );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test.
$kept = $wpdb->get_col( $wpdb->prepare( 'SELECT CONCAT(kind, ":", value) FROM ' . Store::daily() . ' WHERE site_id = %d AND day = %s', $site, $old ) );
$check( 'after 13 months the country is kept in the totals, the city is not', in_array( 'country:PT', $kept, true ) && ! preg_grep( '/Porto/', $kept ), implode( ', ', $kept ) );
$ids = array_column( Board::defaults(), 'id' );
$check( 'the default board has Countries and Cities', in_array( 'countries', $ids, true ) && in_array( 'cities', $ids, true ) );
if ( class_exists( Analyst::class ) ) {
	$prompt = Analyst::system_prompt( 30 );
	$check( 'the analyst knows the places and the 5-visit rule', str_contains( $prompt, 'country, city' ) && str_contains( $prompt, 'fewer than 5 visits' ) );
}
restore_current_blog();
Locator::use( null );

// 5. The file is never served; the real file, when present ---------------------------------------

echo "\nThe file\n";
$main     = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
$response = wp_remote_get(
	'https://' . $main . '/data/geoip/dbip-city-lite.mmdb',
	array(
		'sslverify'   => false,
		'redirection' => 0,
		'timeout'     => 15,
	)
);
$code     = (int) wp_remote_retrieve_response_code( $response );
$check( 'the database file is never served over HTTP', in_array( $code, array( 403, 404 ), true ), (string) $code );
if ( is_readable( Locator::path() ) ) {
	$real = Locator::locate( '8.8.8.8' );
	$check( 'the real DB-IP file: 8.8.8.8 is in the United States', 'US' === $real['country'], wp_json_encode( $real ) );
	$check( '... an IPv6 address is found too', '' !== Locator::locate( '2a00:1450:4001:80b::200e' )['country'] );
} else {
	echo "     (no real database file: run ./bin/geoip to check it too)\n";
}

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
