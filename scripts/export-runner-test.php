<?php
/**
 * Tests running the builder and serving its files (tickets/take-your-site-with-you.md):
 * Export\Runner (the detached WP-CLI process, the watchdog, the in-process
 * fallback), Export\Command (`wp mody-export`) and Export\Downloads (signed,
 * expiring links, X-Accel-Redirect, PHP streaming with Range).
 *
 * Run from the repository root, on the main site:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/export-runner-test.php
 *
 * 1. Links: the round trip, a tampered token, a file outside the three, an
 *    expired link, another site's host; the signing key is a random network
 *    option of its own (never the wp-config salts), made when the first link
 *    is, never by checking one.
 * 2. Which jobs can be downloaded: only a finished one, on its own site, with
 *    its file there, inside the keeping time.
 * 3. PHP streaming: the file's bytes, a 206 for a Range request (and 416, a
 *    resume only of the same file, multiple ranges ignored), what counts as a
 *    download.
 * 4. Nginx: the X-Accel-Redirect plan, and the filter that turns it off.
 * 5. serve() itself, over HTTP (it exits): headers, body, a Range, HEAD, a bad
 *    link, a POST left alone; X-Accel-Redirect only when Nginx's template
 *    said it has the location (MODY_EXPORT_ACCEL), never because
 *    SERVER_SOFTWARE says nginx (an Nginx that was not restarted after a
 *    deploy).
 * 6. Runner: the command it builds, a real detached spawn (a stand-in for
 *    `wp` that outlives the call and the session), the in-process fallback,
 *    the watchdog (at most MAX_RUNNING, no double spawns, a stale job
 *    restarted, one that keeps dying failed), and start() itself respecting
 *    MAX_RUNNING (spawn-marked builders count; a full network leaves the job
 *    queued; the check is under a database lock).
 * 7. The command, through the real WP-CLI: list, run, start (only for an
 *    administrator of the site), watch and purge, and their errors.
 * 8. End to end, when the builder's parts exist (DatabaseDump, Archives,
 *    Notices): `start` builds a throwaway site's three files, and a job
 *    spawned by Runner builds too. It takes a minute or two; set
 *    EXPORT_TEST_SKIP_BUILD=1 to skip it.
 *
 * Section 5 starts PHP's built-in server on a free port of 127.0.0.1 inside the
 * container and uses curl against it. A throwaway site and users are created
 * and removed with the jobs and folders this run made (it deletes only job ids
 * it created, so tests of other agents sharing the stack are left alone).
 * Nothing is sent outside; no model call. Nginx's
 * side is checked by hand (config/nginx/app.conf.template, a throwaway
 * Nginx container), not here.
 */

use Mody\Platform\Export\Command;
use Mody\Platform\Export\Downloads;
use Mody\Platform\Export\Exporter;
use Mody\Platform\Export\Jobs;
use Mody\Platform\Export\Runner;
use Mody\Platform\Members\Members;

require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

$failures = 0;
$total    = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures, &$total ): void {
	++$total;
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-70s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

global $wpdb;
Jobs::install();
$suffix  = strtolower( wp_generate_password( 6, false ) );
$tmp     = sys_get_temp_dir() . '/mody-export-runner-test-' . $suffix;
$domain  = 'xrun' . $suffix . '.' . preg_replace( '/^www\./', '', get_network()->domain );
$site_url = 'https://' . $domain;
$fake    = 9000000 + random_int( 1000, 899000 ); // A site ID that does not exist: jobs for it fail at once.
$made    = array(); // Every job id this run inserted: the only ones it deletes.
$member  = (int) wp_insert_user(
	array(
		'user_login' => 'xrun' . $suffix,
		'user_email' => 'xrun' . $suffix . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	)
);
$stranger   = (int) wp_insert_user(
	array(
		'user_login' => 'xrunout' . $suffix,
		'user_email' => 'xrunout' . $suffix . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	)
);
$subscriber = (int) wp_insert_user(
	array(
		'user_login' => 'xrunsub' . $suffix,
		'user_email' => 'xrunsub' . $suffix . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	)
);
$site    = (int) wpmu_create_blog( $domain, '/', 'Export runner test', $member );
if ( $site ) {
	switch_to_blog( $site );
	switch_theme( 'theme' ); // As provisioning does: without it the front end is blank.
	restore_current_blog();
	add_user_to_blog( $site, $subscriber, 'subscriber' );
}
wp_mkdir_p( $tmp );

$rm_dir = static function ( string $dir ): void { // A folder and everything under it, without glob( GLOB_BRACE ): Alpine's libc has no such flag.
	if ( ! is_dir( $dir ) ) {
		return;
	}
	// The project folder is a network mount whose directory cache lags: an emptied folder may take a moment to be removable.
	$rmdir = static function ( string $path ): void {
		for ( $i = 0; $i < 20 && ! @rmdir( $path ); $i++ ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Retried below.
			clearstatcache( true, $path );
			usleep( 150000 );
		}
	};
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) {
		$item->isDir() && ! $item->isLink() ? $rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	$rmdir( $dir );
};
$orphan_dirs = array();

register_shutdown_function(
	static function () use ( $wpdb, $site, $member, $stranger, $subscriber, $tmp, $rm_dir, &$orphan_dirs, &$made ): void {
		$table = Jobs::table();
		// The ids this run made, and whatever was made for its own throwaway site (by `wp mody-export start` and Runner).
		$ids = $made;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test cleanup.
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE site_id = %d", $site ) ) as $id ) {
			$ids[] = (int) $id;
		}
		foreach ( array_unique( $ids ) as $id ) {
			$job = Jobs::get( (int) $id );
			if ( $job ) {
				$rm_dir( Jobs::dir( $job ) );
				@rmdir( Jobs::root() . '/' . $job['site_id'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Only when empty.
			}
			delete_site_transient( 'mody_export_spawn_' . $id );
			$wpdb->delete( $table, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test cleanup.
		}
		foreach ( $orphan_dirs as $dir ) {
			$rm_dir( $dir );
		}
		@rmdir( Jobs::root() . '/' . $site ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The site's folder, when empty.
		if ( $site ) {
			wpmu_delete_blog( $site, true );
		}
		foreach ( array( $member, $stranger, $subscriber ) as $user ) {
			Members::delete( $user );
			wpmu_delete_user( $user );
		}
		$rm_dir( $tmp );
	}
);
$check( 'a throwaway site and users', $site > 0 && $member > 0 && $stranger > 0 && $subscriber > 0, (string) $site );
if ( ! $site ) {
	echo "\nCannot go on without the throwaway site.\n";
	exit( 1 );
}

/**
 * Inserts a job row directly, in any state, and writes its files.
 *
 * @param int                    $site_id  Site.
 * @param string                 $status   Job status.
 * @param array<string, string>  $contents File bytes by `code`, `database` or `media`; `null` entries list a file that is not on disk.
 * @param int                    $finished Unix time it finished.
 */
$make_job = static function ( int $site_id, string $status, array $contents = array(), int $finished = 0 ) use ( $wpdb, $member, &$made ): array {
	$files = array();
	$token = bin2hex( random_bytes( 16 ) );
	$row   = array(
		'site_id'  => $site_id,
		'user_id'  => $member,
		'token'    => $token,
		'step'     => 'done' === $status ? 'done' : 'database',
		'status'   => $status,
		'progress' => '{}',
		'files'    => '{}',
	);
	if ( $finished ) {
		$row['finished_at'] = gmdate( 'Y-m-d H:i:s', $finished );
	}
	$wpdb->insert( Jobs::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
	$job    = Jobs::get( (int) $wpdb->insert_id );
	$made[] = $job['id'];
	foreach ( $contents as $which => $bytes ) {
		$files[ $which ] = array(
			'name'   => Jobs::file_name( $job, $which ),
			'size'   => strlen( (string) $bytes ),
			'sha256' => hash( 'sha256', (string) $bytes ),
		);
		if ( null !== $bytes ) {
			wp_mkdir_p( Jobs::dir( $job ) );
			file_put_contents( Jobs::dir( $job ) . '/' . Exporter::PATHS[ $which ], $bytes );
		}
	}
	$wpdb->update( Jobs::table(), array( 'files' => wp_json_encode( $files ) ), array( 'id' => $job['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
	return Jobs::get( $job['id'] );
};
// A job that failed an attempt is queued again, and any build that ends calls Runner::watch(), which would really spawn a builder
// for it (and make its folder): this run's jobs that are still waiting are expired at the end of every section that makes some.
$settle = static function () use ( $wpdb, &$made ): void {
	foreach ( array_unique( $made ) as $id ) {
		$j = Jobs::get( (int) $id );
		if ( $j && in_array( $j['status'], array( 'queued', 'running' ), true ) ) {
			$wpdb->update( Jobs::table(), array( 'status' => 'expired' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		}
		delete_site_transient( 'mody_export_spawn_' . $id );
	}
};
$token_of = static function ( string $url ): string {
	return substr( (string) wp_parse_url( $url, PHP_URL_PATH ), strlen( Downloads::PATH ) );
};
$sign = new ReflectionMethod( Downloads::class, 'sign' );
$body = static function ( array $plan ): string {
	$out = '';
	if ( '' !== $plan['file'] && $plan['length'] > 0 ) {
		Downloads::stream(
			$plan['file'],
			$plan['offset'],
			$plan['length'],
			static function ( string $chunk ) use ( &$out ): void {
				$out .= $chunk;
			}
		);
	}
	return $out;
};

$size  = 2500000; // More than two read chunks.
$bytes = random_bytes( $size );
$gz    = random_bytes( 4096 );
$job   = $make_job(
	$site,
	'done',
	array(
		'code'     => $bytes,
		'database' => $gz,
		'media'    => null, // Listed, not on disk.
	),
	time() - 60
);
$name = Jobs::file_name( $job, 'code' );

// 1. Links ----------------------------------------------------------------------------

echo "\nLinks\n";
$url   = Downloads::url( $job, 'code' );
$token = $token_of( $url );
$check( 'the link is on the job\'s site host, under /mody-export/', str_starts_with( $url, $site_url . Downloads::PATH ) || str_starts_with( $url, 'http://' . $domain . Downloads::PATH ), $url );
$check( '... and shows neither the folder token nor a path', ! str_contains( $url, $job['token'] ) && ! str_contains( $url, 'dumps' ) && ! str_contains( $url, 'code.zip' ) );
$link = Downloads::parse( $token );
$check( 'a link verifies and says its job and file', is_array( $link ) && $link['job_id'] === $job['id'] && 'code' === $link['which'], wp_json_encode( $link ) );
$check( '... for about six hours', is_array( $link ) && abs( $link['expires'] - ( time() + 6 * HOUR_IN_SECONDS ) ) < 5 );
$check( 'a different TTL is honoured', is_array( $p = Downloads::parse( $token_of( Downloads::url( $job, 'database', 120 ) ) ) ) && abs( $p['expires'] - ( time() + 120 ) ) < 5 );
$check( 'only the three files get a link', '' === Downloads::url( $job, '../../etc/passwd' ) && '' === Downloads::url( $job, 'secrets' ) && '' === Downloads::url( $job, '' ) );

list( $payload, $sig ) = explode( '.', $token );
$flip = static function ( string $s ): string {
	return substr( $s, 0, -1 ) . ( 'a' === substr( $s, -1 ) ? 'b' : 'a' );
};
$other_job = $make_job( $site, 'done', array( 'code' => 'x' ), time() );
$other_tok = Downloads::token( $other_job, 'code', time() + 600 );
$bad       = array(
	'a changed signature'                  => $payload . '.' . $flip( $sig ),
	'a changed payload'                    => $flip( $payload ) . '.' . $sig,
	'another job\'s payload, this signature' => explode( '.', $other_tok )[0] . '.' . $sig,
	'no signature'                         => $payload,
	'an empty signature'                   => $payload . '.',
	'an extra part'                        => $token . '.00',
	'nothing'                              => '',
	'a signature made with another key'    => $payload . '.' . hash_hmac( 'sha256', $payload, 'not the key' ),
);
foreach ( $bad as $label => $tok ) {
	$check( 'refused: ' . $label, is_wp_error( Downloads::parse( $tok ) ) && 'mody_export_link_bad' === Downloads::parse( $tok )->get_error_code() );
}
$signed   = static function ( string $data ) use ( $sign ): string {
	$p = rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	return $p . '.' . $sign->invoke( null, $p );
};
$check( 'refused: a signed file name that is not one of the three', is_wp_error( Downloads::parse( $signed( $job['id'] . '|../../etc/passwd|' . ( time() + 600 ) ) ) ) );
$check( 'refused: a signed payload of the wrong shape', is_wp_error( Downloads::parse( $signed( 'hello' ) ) ) && is_wp_error( Downloads::parse( $signed( $job['id'] . '|code' ) ) ) && is_wp_error( Downloads::parse( $signed( 'x|code|' . ( time() + 600 ) ) ) ) );
$old = Downloads::token( $job, 'code', time() - 5 );
$err = Downloads::parse( $old );
$check( 'an expired link says so', is_wp_error( $err ) && 'mody_export_link_expired' === $err->get_error_code() );
$check( '... and works again with a clock before its expiry', is_array( Downloads::parse( $old, time() - 60 ) ) );

// The signing key: a random option of its own, not the wp-config salts (which have a default committed to the repository).
$key = get_site_option( Downloads::KEY_OPTION, '' );
$check( 'the signing key is 32 random bytes in the network option mody_export_link_key', is_string( $key ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $key ), Downloads::KEY_OPTION );
$forge = static function ( string $secret ) use ( $job ): string {
	$p = rtrim( strtr( base64_encode( $job['id'] . '|code|' . ( time() + 600 ) ), '+/', '-_' ), '=' );
	return $p . '.' . hash_hmac( 'sha256', $p, $secret );
};
$salts  = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );
$forged = array(
	'the old derivation from AUTH_KEY and AUTH_SALT' => hash_hmac( 'sha256', 'mody-export-link', $salts ),
	'AUTH_KEY and AUTH_SALT as they are'              => $salts,
	'wp_salt( auth )'                                 => wp_salt( 'auth' ),
);
foreach ( $forged as $label => $secret ) {
	$check( 'refused: a link signed with ' . $label, is_wp_error( Downloads::parse( $forge( $secret ) ) ) );
}
// A site with no key yet: checking a link makes none (any visitor can send one), making a link does, and it keeps.
$kept = get_site_option( Downloads::KEY_OPTION, null );
delete_site_option( Downloads::KEY_OPTION );
$parsed_without = Downloads::parse( $token );
$none_made      = false === get_site_option( Downloads::KEY_OPTION, false );
$fresh_url      = Downloads::url( $job, 'code' );
$fresh_key      = get_site_option( Downloads::KEY_OPTION, '' );
$fresh_ok       = is_array( Downloads::parse( $token_of( $fresh_url ) ) );
$old_after      = is_wp_error( Downloads::parse( $token ) );
if ( is_string( $kept ) && '' !== $kept ) {
	update_site_option( Downloads::KEY_OPTION, $kept ); // Back at once: links other runs made keep working.
}
$check( 'with no key, a link is refused and checking it makes none', is_wp_error( $parsed_without ) && $none_made );
$check( 'making a link makes the key (64 hex), and the link verifies', is_string( $fresh_key ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $fresh_key ) && '' !== $fresh_url && $fresh_ok );
$check( 'a link made with the old key does not verify with the new one', $old_after );
$check( 'the key is back as it was', ! is_string( $kept ) || '' === $kept || get_site_option( Downloads::KEY_OPTION ) === $kept );

// 2. Which jobs can be downloaded -----------------------------------------------------

echo "\nWhich jobs\n";
$refused = static function ( array $plan ): bool {
	return 302 === $plan['status'] && '' === $plan['file'] && ! isset( $plan['headers']['X-Accel-Redirect'] ) && ! $plan['count'];
};
$ok_plan = Downloads::plan( 'GET', $token, array(), false, $site );
$check( 'the finished job\'s file is offered', 200 === $ok_plan['status'] && $ok_plan['job_id'] === $job['id'] );
$p = Downloads::plan( 'GET', $old, array(), false, $site );
$check( 'an expired link: back to the dashboard\'s export section', $refused( $p ) && str_contains( $p['headers']['Location'], 'page=mody-dashboard' ) && str_contains( $p['headers']['Location'], 'section=export' ) && str_contains( $p['headers']['Location'], Downloads::EXPIRED_ARG . '=expired' ), $p['headers']['Location'] ?? '' );
$check( '... with no caching and no path in it', 'private, no-store' === $p['headers']['Cache-Control'] && ! str_contains( implode( ' ', $p['headers'] ), 'dumps' ) );
$check( 'a tampered link: the same', $refused( Downloads::plan( 'GET', $payload . '.' . $flip( $sig ), array(), false, $site ) ) );
$check( 'a link used on another site\'s host: the same', $refused( Downloads::plan( 'GET', $token, array(), false, $site + 1 ) ) && $refused( Downloads::plan( 'GET', $token, array(), false, 1 ) ) );
$queued = $make_job( $site, 'queued', array( 'code' => 'x' ) );
$check( 'a job that is not finished: the same', $refused( Downloads::plan( 'GET', Downloads::token( $queued, 'code', time() + 600 ), array(), false, $site ) ) );
$gone = $make_job( $site, 'expired', array( 'code' => 'x' ), time() - DAY_IN_SECONDS );
$check( 'a job whose files expired: the same', $refused( Downloads::plan( 'GET', Downloads::token( $gone, 'code', time() + 600 ), array(), false, $site ) ) );
$check( 'a file that is listed but not on disk: the same', $refused( Downloads::plan( 'GET', Downloads::token( $job, 'media', time() + 600 ), array(), false, $site ) ) );
$check( 'a file the job never made: the same', $refused( Downloads::plan( 'GET', Downloads::token( $queued, 'database', time() + 600 ), array(), false, $site ) ) );
$old_job = $make_job( $site, 'done', array( 'code' => 'x' ), time() - Jobs::KEEP_FOR - HOUR_IN_SECONDS );
$check( 'a finished job past its keeping time (not yet swept): the same', $refused( Downloads::plan( 'GET', Downloads::token( $old_job, 'code', time() + 600 ), array(), false, $site ) ) );
$check( 'a job that does not exist: the same', $refused( Downloads::plan( 'GET', Downloads::token( array( 'id' => 987654321 ), 'code', time() + 600 ), array(), false, $site ) ) );

// 3. PHP streaming --------------------------------------------------------------------

echo "\nPHP streaming\n";
$plan = Downloads::plan( 'GET', $token, array(), false, $site );
$h    = $plan['headers'];
$check( 'a zip: 200, the right type, the file\'s name as an attachment', 200 === $plan['status'] && 'application/zip' === $h['Content-Type'] && 'attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) === $h['Content-Disposition'], $h['Content-Disposition'] );
$check( '... its real length, private and not stored, not sniffed, not indexed', (string) $size === $h['Content-Length'] && 'private, no-store' === $h['Cache-Control'] && 'nosniff' === $h['X-Content-Type-Options'] && 'noindex' === $h['X-Robots-Tag'] );
$check( '... resumable, with a validator', 'bytes' === $h['Accept-Ranges'] && '"' . hash( 'sha256', $bytes ) . '"' === $h['ETag'] && isset( $h['Last-Modified'] ) );
$got = $body( $plan );
$check( 'the bytes streamed are the file\'s, all of them', strlen( $got ) === $size && hash( 'sha256', $got ) === hash( 'sha256', $bytes ) );
$db = Downloads::plan( 'GET', $token_of( Downloads::url( $job, 'database' ) ), array(), false, $site );
$check( 'the database is application/gzip, named .sql.gz', 'application/gzip' === $db['headers']['Content-Type'] && str_ends_with( $db['headers']['Content-Disposition'], rawurlencode( Jobs::file_name( $job, 'database' ) ) ) && $body( $db ) === $gz );
$check( 'a download is counted: a GET of the whole file; a HEAD is not', $plan['count'] && ! Downloads::plan( 'HEAD', $token, array(), false, $site )['count'] );

$ranged = static function ( string $range, array $extra = array() ) use ( $token, $site ): array {
	return Downloads::plan( 'GET', $token, array( 'range' => $range ) + $extra, false, $site );
};
$p = $ranged( 'bytes=100-199' );
$check( 'Range bytes=100-199: 206, Content-Range, 100 bytes, the right ones', 206 === $p['status'] && 'bytes 100-199/' . $size === $p['headers']['Content-Range'] && '100' === $p['headers']['Content-Length'] && $body( $p ) === substr( $bytes, 100, 100 ) );
$p = $ranged( 'bytes=1048000-1050000' );
$check( '... across a read chunk\'s edge', 206 === $p['status'] && $body( $p ) === substr( $bytes, 1048000, 2001 ) );
$p = $ranged( 'bytes=-50' );
$check( '... the last 50 bytes (bytes=-50)', 206 === $p['status'] && 'bytes ' . ( $size - 50 ) . '-' . ( $size - 1 ) . '/' . $size === $p['headers']['Content-Range'] && $body( $p ) === substr( $bytes, -50 ) );
$p = $ranged( 'bytes=2499990-' );
$check( '... from a byte to the end (bytes=2499990-)', 206 === $p['status'] && $body( $p ) === substr( $bytes, 2499990 ) );
$p = $ranged( 'bytes=2499990-99999999' );
$check( '... an end past the file is cut to it', 206 === $p['status'] && 'bytes 2499990-' . ( $size - 1 ) . '/' . $size === $p['headers']['Content-Range'] );
$p = $ranged( 'bytes=0-0' );
$check( '... the first byte', 206 === $p['status'] && $body( $p ) === $bytes[0] );
$p = $ranged( 'bytes=' . $size . '-' );
$check( 'a start past the end: 416 with the size', 416 === $p['status'] && 'bytes */' . $size === $p['headers']['Content-Range'] && '' === $body( $p ) && ! $p['count'] );
$p = $ranged( 'bytes=-0' );
$check( 'the last 0 bytes: 416', 416 === $p['status'] );
$p = $ranged( 'bytes=0-1,5-6' );
$check( 'two ranges: ignored, the whole file with a 200', 200 === $p['status'] && strlen( $body( $p ) ) === $size );
$p = $ranged( 'bytes=9-3' );
$check( 'a backwards range: ignored', 200 === $p['status'] );
foreach ( array( 'bytes=abc', 'items=0-5', 'bytes=-', '0-5', '' ) as $junk ) {
	$check( 'a malformed Range (' . $junk . '): ignored', 200 === $ranged( $junk )['status'] );
}
$check( 'If-Range with another file\'s validator: ignored, the whole file', 200 === $ranged( 'bytes=100-199', array( 'if-range' => '"0000"' ) )['status'] );
$check( 'If-Range with this file\'s ETag: the range is served', 206 === $ranged( 'bytes=100-199', array( 'if-range' => $h['ETag'] ) )['status'] );
$check( 'If-Range with this file\'s date: the range is served', 206 === $ranged( 'bytes=100-199', array( 'if-range' => $h['Last-Modified'] ) )['status'] );
$check( 'a resume is not a new download; a range from 0 is', ! $ranged( 'bytes=100-199' )['count'] && $ranged( 'bytes=0-99' )['count'] );
$check( 'the range helper alone', array( 5, 9 ) === Downloads::range( 'bytes=5-9', 100 ) && false === Downloads::range( 'bytes=100-', 100 ) && null === Downloads::range( 'bytes=-', 100 ) && array( 0, 99 ) === Downloads::range( 'bytes=-500', 100 ) );

// 4. Nginx ----------------------------------------------------------------------------

echo "\nNginx\n";
$p = Downloads::plan( 'GET', $token, array(), true, $site );
$check( 'X-Accel-Redirect to /_mody_exports/<site>/<folder>/code.zip', '/_mody_exports/' . $site . '/' . $job['token'] . '/code.zip' === ( $p['headers']['X-Accel-Redirect'] ?? '' ), $p['headers']['X-Accel-Redirect'] ?? '' );
$check( '... PHP sends no body, the type and name still set', '' === $p['file'] && 0 === $p['length'] && 200 === $p['status'] && 'application/zip' === $p['headers']['Content-Type'] && isset( $p['headers']['Content-Disposition'] ) && 'private, no-store' === $p['headers']['Cache-Control'] );
$check( '... counted once; a resume or a HEAD is not', $p['count'] && ! Downloads::plan( 'GET', $token, array( 'range' => 'bytes=500-' ), true, $site )['count'] && ! Downloads::plan( 'HEAD', $token, array(), true, $site )['count'] );
add_filter( 'mody_export_x_accel', '__return_false' );
$p = Downloads::plan( 'GET', $token, array(), true, $site );
$check( 'the filter mody_export_x_accel turns it off: PHP streams', ! isset( $p['headers']['X-Accel-Redirect'] ) && $p['length'] === $size && strlen( $body( $p ) ) === $size );
remove_filter( 'mody_export_x_accel', '__return_false' );
$p = Downloads::plan( 'GET', $old, array(), true, $site );
$check( 'a refused link is never handed to Nginx', $refused( $p ) );

// 5. serve(), over HTTP ---------------------------------------------------------------

echo "\nserve()\n";
// PHP's built-in server, running WordPress's front controller on a free port, so serve() is
// reached over real HTTP, without Nginx (PHP streams unless the router says Nginx has the
// location). serve() is called from mody-platform.php, at MU plugin load, where the pluggable
// functions (wp_salt(), wp_redirect()) are not loaded yet: this tests that wiring too.
$wp_path = untrailingslashit( ABSPATH );
$router  = $tmp . '/router.php';
// The router can pretend to be what PHP-FPM behind Nginx would see: X-Test-Software sets SERVER_SOFTWARE and
// X-Test-Accel sets MODY_EXPORT_ACCEL (what `fastcgi_param MODY_EXPORT_ACCEL 1;` in the Nginx template does). Both
// are set before WordPress loads: serve() runs from mody-platform.php, at MU plugin load, which is the wiring tested here.
file_put_contents(
	$router,
	str_replace(
		'__RUN__',
		$suffix,
		<<<'PHP'
<?php
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/index.php';
header( 'X-Mody-Test-Run: __RUN__' );
if ( isset( $_SERVER['HTTP_X_TEST_SOFTWARE'] ) ) {
	$_SERVER['SERVER_SOFTWARE'] = $_SERVER['HTTP_X_TEST_SOFTWARE'];
}
if ( isset( $_SERVER['HTTP_X_TEST_ACCEL'] ) ) {
	$_SERVER['MODY_EXPORT_ACCEL'] = $_SERVER['HTTP_X_TEST_ACCEL'];
}
require '/var/www/html/index.php';
PHP
	)
);
// A free port: ask the system for one and let go of it; if another process takes it first, the server does not start and another is tried.
$port   = 0;
$server = false;
for ( $try = 0; $try < 5; $try++ ) {
	$probe = stream_socket_server( 'tcp://127.0.0.1:0' );
	$port  = $probe ? (int) substr( (string) strrchr( (string) stream_socket_get_name( $probe, false ), ':' ), 1 ) : 0;
	if ( $probe ) {
		fclose( $probe );
	}
	$server = $port ? proc_open(
		array( 'php', '-S', '127.0.0.1:' . $port, '-t', '/var/www/html', $router ),
		array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'file', $tmp . '/server.log', 'w' ),
			2 => array( 'file', $tmp . '/server.log', 'a' ),
		),
		$pipes,
		'/var/www/html'
	) : false;
	usleep( 400000 ); // A bind that failed has ended the process by now.
	if ( is_resource( $server ) && proc_get_status( $server )['running'] ) {
		break;
	}
	if ( is_resource( $server ) ) {
		proc_close( $server );
	}
	$server = false;
}
register_shutdown_function(
	static function () use ( &$server ): void {
		if ( is_resource( $server ) ) {
			proc_terminate( $server );
		}
	}
);
$http = static function ( string $method, string $path, array $headers = array() ) use ( $tmp, $port, $domain ): array {
	$head = $tmp . '/h-' . random_int( 1000, 9999 ) . '.txt';
	$file = $tmp . '/b-' . random_int( 1000, 9999 ) . '.bin';
	$cmd  = 'curl -s -D ' . escapeshellarg( $head ) . ' -o ' . escapeshellarg( 'HEAD' === $method ? '/dev/null' : $file ) . ' --max-time 60 -H ' . escapeshellarg( 'Host: ' . $domain );
	foreach ( $headers as $name => $value ) {
		$cmd .= ' -H ' . escapeshellarg( $name . ': ' . $value );
	}
	$cmd .= 'HEAD' === $method ? ' -I' : ' -X ' . escapeshellarg( $method );
	exec( $cmd . ' ' . escapeshellarg( 'http://127.0.0.1:' . $port . $path ), $unused, $code );
	$raw   = is_file( $head ) ? (string) file_get_contents( $head ) : '';
	$blocks = array_filter( explode( "\r\n\r\n", trim( $raw ) ) );
	$last  = (string) end( $blocks );
	$lines = explode( "\r\n", $last );
	preg_match( '#^HTTP/\S+\s+(\d+)#', (string) array_shift( $lines ), $m );
	$out = array(
		'code'    => (int) ( $m[1] ?? 0 ),
		'headers' => array(),
		'body'    => is_file( $file ) ? (string) file_get_contents( $file ) : '',
		'curl'    => $code,
	);
	foreach ( $lines as $line ) {
		$parts = explode( ':', $line, 2 );
		if ( 2 === count( $parts ) ) {
			$out['headers'][ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
		}
	}
	@unlink( $head ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Test scratch files.
	@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Test scratch files.
	return $out;
};
for ( $i = 0; $i < 50; $i++ ) {
	exec( 'curl -s -o /dev/null --max-time 2 http://127.0.0.1:' . $port . '/nothing', $unused, $up );
	if ( 0 === $up ) {
		break;
	}
	usleep( 100000 );
}
$check( 'the test server is up, on a free port', 0 === $up && is_resource( $server ), (string) $port );
$mine = $http( 'GET', '/nothing' );
$check( '... and it is this run\'s own server (not another run\'s)', $suffix === ( $mine['headers']['x-mody-test-run'] ?? '' ) );
$uri = Downloads::PATH . $token;
$r   = $http( 'GET', $uri );
$h   = $r['headers'];
$check( 'GET: 200, the whole file, an attachment', 200 === $r['code'] && strlen( $r['body'] ) === $size && hash( 'sha256', $r['body'] ) === hash( 'sha256', $bytes ) && str_starts_with( $h['content-disposition'] ?? '', 'attachment;' ), $r['code'] . ' ' . strlen( $r['body'] ) . ' bytes' );
$check( '... the headers: type, length, ranges, no-store, nosniff, noindex', 'application/zip' === ( $h['content-type'] ?? '' ) && (string) $size === ( $h['content-length'] ?? '' ) && 'bytes' === ( $h['accept-ranges'] ?? '' ) && 'private, no-store' === ( $h['cache-control'] ?? '' ) && 'nosniff' === ( $h['x-content-type-options'] ?? '' ) && 'noindex' === ( $h['x-robots-tag'] ?? '' ) );
$check( '... no cookie, no X-Accel-Redirect to leak', ! isset( $h['set-cookie'] ) && ! isset( $h['x-accel-redirect'] ) );
$after = Jobs::get( $job['id'] )['downloads'];
$check( '... and counted', 1 === $after, (string) $after );
$r = $http( 'GET', $uri, array( 'Range' => 'bytes=1048000-1048999' ) );
$check( 'GET with a Range: 206 and exactly those bytes', 206 === $r['code'] && $r['body'] === substr( $bytes, 1048000, 1000 ) && 'bytes 1048000-1048999/' . $size === ( $r['headers']['content-range'] ?? '' ) && '1000' === ( $r['headers']['content-length'] ?? '' ) );
$check( '... not counted again', Jobs::get( $job['id'] )['downloads'] === $after );
$r = $http( 'GET', $uri, array( 'Range' => 'bytes=' . ( $size - 10 ) . '-' ) );
$check( 'a resume from near the end: the last 10 bytes', 206 === $r['code'] && $r['body'] === substr( $bytes, -10 ) );
$r = $http( 'GET', $uri, array( 'Range' => 'bytes=' . $size . '-' ) );
$check( 'a Range past the end: 416', 416 === $r['code'] && 'bytes */' . $size === ( $r['headers']['content-range'] ?? '' ) && '' === $r['body'] );
$r = $http( 'HEAD', $uri );
$check( 'HEAD: the headers and no body, not counted', 200 === $r['code'] && '' === $r['body'] && (string) $size === ( $r['headers']['content-length'] ?? '' ) && Jobs::get( $job['id'] )['downloads'] === $after );
$r = $http( 'GET', Downloads::PATH . $token_of( Downloads::url( $job, 'database' ) ) );
$check( 'the database file: gzip', 200 === $r['code'] && 'application/gzip' === ( $r['headers']['content-type'] ?? '' ) && $r['body'] === $gz );
$r = $http( 'GET', Downloads::PATH . $old );
$check( 'an expired link: 302 to the export section', 302 === $r['code'] && str_contains( $r['headers']['location'] ?? '', 'section=export' ) && str_contains( $r['headers']['location'] ?? '', 'export_link=expired' ) && '' === $r['body'], $r['headers']['location'] ?? '' );
$check( '... never shown with a path or a cookie', ! isset( $r['headers']['set-cookie'] ) && ! str_contains( implode( ' ', $r['headers'] ), 'dumps' ) );
$r = $http( 'GET', Downloads::PATH . 'garbage' );
$check( 'a made-up link: the same', 302 === $r['code'] && str_contains( $r['headers']['location'] ?? '', 'section=export' ) );
$r = $http( 'GET', Downloads::PATH );
$check( 'the bare path: the same', 302 === $r['code'] );
$r = $http( 'GET', $uri . '?x=1' );
$check( 'a query string on the link does no harm', 200 === $r['code'] && strlen( $r['body'] ) === $size );
$r = $http( 'POST', $uri );
$check( 'a POST is left to WordPress (no file)', 0 !== $r['code'] && ! isset( $r['headers']['content-disposition'] ) && 'application/zip' !== ( $r['headers']['content-type'] ?? '' ), (string) $r['code'] );
$r = $http( 'GET', '/some/other/path' );
$check( 'another path is left to WordPress (no file)', ! isset( $r['headers']['content-disposition'] ) && 'application/zip' !== ( $r['headers']['content-type'] ?? '' ) );
// The throwaway site's link, used on the main site's host: refused.
$wrong = $tmp . '/wrong.txt';
exec( 'curl -s -D ' . escapeshellarg( $wrong ) . ' -o /dev/null -H ' . escapeshellarg( 'Host: ' . preg_replace( '/^www\./', '', get_network()->domain ) ) . ' ' . escapeshellarg( 'http://127.0.0.1:' . $port . Downloads::PATH . $token ) );
$wrong_head = is_file( $wrong ) ? (string) file_get_contents( $wrong ) : '';
$check( 'the link on another site\'s host (the main site): 302, no file', str_contains( $wrong_head, ' 302 ' ) && ! str_contains( strtolower( $wrong_head ), 'content-disposition' ), strtok( $wrong_head, "\r\n" ) );

// X-Accel-Redirect only when the Nginx template said it has the location.
$stale = $http( 'GET', $uri, array( 'X-Test-Software' => 'nginx/1.27.4' ) );
$check( 'behind an Nginx that has not been restarted (nginx in SERVER_SOFTWARE, no MODY_EXPORT_ACCEL): PHP streams the file', 200 === $stale['code'] && ! isset( $stale['headers']['x-accel-redirect'] ) && strlen( $stale['body'] ) === $size && hash( 'sha256', $stale['body'] ) === hash( 'sha256', $bytes ) );
foreach ( array( '0', 'true', '11' ) as $not_one ) {
	$r = $http( 'GET', $uri, array( 'X-Test-Software' => 'nginx/1.27.4', 'X-Test-Accel' => $not_one ) );
	$check( 'MODY_EXPORT_ACCEL set to "' . $not_one . '", not 1: PHP streams the file', 200 === $r['code'] && ! isset( $r['headers']['x-accel-redirect'] ) && strlen( $r['body'] ) === $size );
}
$r = $http( 'GET', $uri, array( 'X-Test-Software' => 'nginx/1.27.4', 'X-Test-Accel' => '1' ) );
$check( 'MODY_EXPORT_ACCEL 1 (the template\'s fastcgi_param): X-Accel-Redirect to the internal location, no body from PHP', 200 === $r['code'] && '/_mody_exports/' . $site . '/' . $job['token'] . '/code.zip' === ( $r['headers']['x-accel-redirect'] ?? '' ) && '' === $r['body'], $r['code'] . ' ' . ( $r['headers']['x-accel-redirect'] ?? '-' ) . ' curl ' . $r['curl'] . ' body ' . strlen( $r['body'] ) );
$check( '... PHP still sets the name, the type and no-store; the length is Nginx\'s to send', str_starts_with( $r['headers']['content-disposition'] ?? '', 'attachment;' ) && 'application/zip' === ( $r['headers']['content-type'] ?? '' ) && 'private, no-store' === ( $r['headers']['cache-control'] ?? '' ) );
$r = $http( 'GET', Downloads::PATH . $old, array( 'X-Test-Accel' => '1' ) );
$check( '... a refused link is still a 302, never handed to Nginx', 302 === $r['code'] && ! isset( $r['headers']['x-accel-redirect'] ) );
// The template is in the repository's config/, which the app container does not mount: checked when this runs where it can be read.
$template_path = null;
foreach ( array( dirname( ABSPATH ) . '/../config/nginx/app.conf.template', getenv( 'MODY_REPO' ) . '/config/nginx/app.conf.template' ) as $candidate ) {
	if ( is_readable( $candidate ) ) {
		$template_path = $candidate;
		break;
	}
}
if ( $template_path ) {
	$template = (string) file_get_contents( $template_path );
	$check( 'the Nginx template sets MODY_EXPORT_ACCEL 1 in the PHP location, beside the internal location it announces', 1 === preg_match( '#location\s+~\s+\\\.php\$\s*\{[^}]*fastcgi_param\s+MODY_EXPORT_ACCEL\s+1\s*;#s', $template ) && 1 === preg_match( '#location\s+\^~\s+/_mody_exports/\s*\{[^}]*\binternal\s*;#s', $template ) );
} else {
	echo "note config/nginx/app.conf.template is not mounted in this container: check by hand that its PHP location has `fastcgi_param MODY_EXPORT_ACCEL 1;`.\n";
}

// 6. Runner ---------------------------------------------------------------------------

echo "\nRunner\n";
$spawned = array();
$stub    = static function ( $result, $job ) use ( &$spawned ) {
	$spawned[] = $job['id'];
	return true;
};
$command = Runner::command( $queued );
$check( 'the command: wp, the core path, the site\'s address, mody-export run <job>', 6 === count( $command ) && str_ends_with( $command[0], '/wp' ) && '--path=' . $wp_path === $command[1] && '--url=' . $site_url === $command[2] && array( 'mody-export', 'run', (string) $queued['id'] ) === array_slice( $command, 3 ), implode( ' ', $command ) );
$check( '... for a site that is gone, the network\'s main site', str_starts_with( Runner::command( array( 'id' => 5, 'site_id' => $fake ) )[2], '--url=' . untrailingslashit( network_home_url() ) ) );

// A real spawn, of a stand-in for `wp` that records how it was called and then lives on for a while.
$fake_wp = $tmp . '/wp-fake.sh';
file_put_contents(
	$fake_wp,
	"#!/bin/sh\nD=" . escapeshellarg( $tmp ) . "\necho \"\$@\" > \$D/args.txt\ncat /proc/\$\$/stat > \$D/stat.txt\necho 'hello from the builder'\nsleep 4\necho finished > \$D/finished.txt\n"
);
chmod( $fake_wp, 0755 );
$use_fake = static function () use ( $fake_wp ): string {
	return $fake_wp;
};
add_filter( 'mody_export_wp_binary', $use_fake );
$spawn_job = $make_job( $site, 'queued' );
$t0        = microtime( true );
$started   = Runner::start( $spawn_job['id'] );
$took      = microtime( true ) - $t0;
$check( 'start() does not wait for the builder (it runs 4 seconds)', $took < 2.0, round( $took, 2 ) . ' s' );
$check( '... and says it started one', true === $started );
for ( $i = 0; $i < 30 && ! is_file( $tmp . '/stat.txt' ); $i++ ) {
	usleep( 100000 );
}
$args = is_file( $tmp . '/args.txt' ) ? trim( (string) file_get_contents( $tmp . '/args.txt' ) ) : '';
$check( 'the builder started, with the command built', '--path=' . $wp_path . ' --url=' . $site_url . ' mody-export run ' . $spawn_job['id'] === $args, $args );
$check( '... and still runs after start() returned', ! is_file( $tmp . '/finished.txt' ) );
$mine = (string) file_get_contents( '/proc/self/stat' );
$his  = (string) file_get_contents( $tmp . '/stat.txt' );
preg_match( '/\)\s+\S\s+(\d+)\s+(\d+)\s+(\d+)/', $mine, $m_mine );
preg_match( '/\)\s+\S\s+(\d+)\s+(\d+)\s+(\d+)/', $his, $m_his );
$check( '... in a session of its own (nothing sent to this process group reaches it)', isset( $m_mine[3], $m_his[3] ) && $m_mine[3] !== $m_his[3], 'session ' . ( $m_mine[3] ?? '?' ) . ' vs ' . ( $m_his[3] ?? '?' ) );
$log = Jobs::dir( $spawn_job ) . '/build.log';
$check( '... its output in build.log inside the job folder', is_file( $log ) && str_contains( (string) file_get_contents( $log ), 'hello from the builder' ) );
for ( $i = 0; $i < 90 && ! is_file( $tmp . '/finished.txt' ); $i++ ) {
	usleep( 100000 );
}
$check( '... and it finishes on its own', is_file( $tmp . '/finished.txt' ) );
$state = get_site_transient( 'mody_export_spawn_' . $spawn_job['id'] );
$check( 'the spawn is remembered, for the watchdog', is_array( $state ) && 1 === $state['n'] && 0 === $state['attempts'] );
remove_filter( 'mody_export_wp_binary', $use_fake );
$check( 'a finished job is never started', false === Runner::start( $job['id'] ) && 'done' === Jobs::get( $job['id'] )['status'] && false === get_site_transient( 'mody_export_spawn_' . $job['id'] ) && array() === Runner::scheduled() );
$wpdb->update( Jobs::table(), array( 'status' => 'expired' ), array( 'id' => $queued['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
$wpdb->update( Jobs::table(), array( 'status' => 'expired' ), array( 'id' => $spawn_job['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
delete_site_transient( 'mody_export_spawn_' . $queued['id'] );

// The fallback: nothing could be spawned, so the job is built in this process once the response is out.
$no_spawn = $make_job( $fake, 'queued' );
add_filter( 'mody_export_spawn', '__return_false' );
Runner::start( $no_spawn['id'] );
Runner::start( $no_spawn['id'] );
remove_filter( 'mody_export_spawn', '__return_false' );
$check( 'when spawning fails, the job is scheduled for this request\'s shutdown, once', array( $no_spawn['id'] ) === Runner::scheduled() && false !== has_action( 'shutdown', array( Runner::class, 'run_later' ) ) );
remove_action( 'shutdown', array( Runner::class, 'run_later' ), 0 ); // The test runs it by hand below.
Runner::run_later();
$after = Jobs::get( $no_spawn['id'] );
$check( '... run_later() builds it (a site that is gone: claimed, tried, failed)', 1 === $after['attempts'] && str_contains( $after['last_error'], 'Not a member site' ), $after['last_error'] );
$check( '... and the schedule is empty again', array() === Runner::scheduled() );

// Three spawns that never claimed the job: the fourth goes to the fallback.
$loop = $make_job( $site, 'queued' );
set_site_transient(
	'mody_export_spawn_' . $loop['id'],
	array(
		'at'       => time() - 600,
		'attempts' => $loop['attempts'],
		'n'        => 3,
	),
	HOUR_IN_SECONDS
);
$spawned = array();
add_filter( 'mody_export_spawn', $stub, 10, 2 );
Runner::start( $loop['id'] );
$check( 'three spawns that never claimed the job: the next goes to the fallback', array() === $spawned && array( $loop['id'] ) === Runner::scheduled() );
remove_action( 'shutdown', array( Runner::class, 'run_later' ), 0 );
$wpdb->update( Jobs::table(), array( 'status' => 'expired' ), array( 'id' => $loop['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
Runner::run_later(); // Empties the schedule; the job is expired, so the claim fails and nothing is built.
$check( '... and run_later() on an expired job builds nothing', 'expired' === Jobs::get( $loop['id'] )['status'] && array() === Runner::scheduled() );

// The watchdog.
$batch = array();
for ( $i = 0; $i < 4; $i++ ) {
	$batch[] = $make_job( $fake + 1 + $i, 'queued' )['id'];
}
// What watch() should start now: the oldest waiting jobs whose builder is not already spawning, as many as there are free builds.
$expected = static function (): array {
	$ids = array();
	foreach ( Jobs::waiting() as $id ) {
		$j = Jobs::get( $id );
		if ( $j && $j['attempts'] < Jobs::MAX_ATTEMPTS && ! Runner::is_spawning( $j ) ) {
			$ids[] = $id;
		}
	}
	return array_slice( $ids, 0, Runner::free() );
};
$waiting = Jobs::waiting();
$free    = Runner::free();
$expect  = $expected();
$spawned = array();
$count   = Runner::watch();
$check( 'watch(): starts waiting jobs up to the free builds, the oldest first', $spawned === $expect, 'free ' . $free . ', started ' . implode( ',', $spawned ) );
$check( '... says how many it started', count( $spawned ) === $count );
$check( '... never more than MAX_RUNNING (' . Jobs::MAX_RUNNING . ') in all', count( $spawned ) <= Jobs::MAX_RUNNING );
if ( 0 === $free ) {
	echo "note Another build is using every slot: the checks above only show that nothing was started.\n";
}
$spawned = array();
Runner::watch();
$check( 'watch() again at once: nothing is spawned twice (the builders have not claimed yet)', array() === $spawned, implode( ',', $spawned ) );
foreach ( $batch as $id ) {
	delete_site_transient( 'mody_export_spawn_' . $id );
	$wpdb->update( Jobs::table(), array( 'status' => 'expired' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
}
foreach ( $waiting as $id ) {
	delete_site_transient( 'mody_export_spawn_' . $id ); // Others' jobs: forget this test's spawn markers, it never really spawned them.
}

// A running job whose builder went quiet is restarted; one that died too often is failed.
$stale_at = gmdate( 'Y-m-d H:i:s', time() - Jobs::STALE_AFTER - 600 );
$stale    = $make_job( $fake + 10, 'running' );
$wpdb->update( Jobs::table(), array( 'attempts' => 1, 'updated_at' => $stale_at ), array( 'id' => $stale['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
$dead = $make_job( $fake + 11, 'running' );
$wpdb->update( Jobs::table(), array( 'attempts' => Jobs::MAX_ATTEMPTS, 'updated_at' => $stale_at ), array( 'id' => $dead['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
$free    = Runner::free();
$waiting = Jobs::waiting();
$expect  = $expected();
$spawned = array();
Runner::watch();
$check( 'a running job that went quiet is waiting, and (with a free build, in its turn) restarted', in_array( $stale['id'], $waiting, true ) && $spawned === $expect && ( ! in_array( $stale['id'], $expect, true ) || in_array( $stale['id'], $spawned, true ) ), 'free ' . $free );
$check( 'a job claimed ' . Jobs::MAX_ATTEMPTS . ' times that still went quiet is failed, not started', 'failed' === Jobs::get( $dead['id'] )['status'] && ! in_array( $dead['id'], $spawned, true ) && '' !== Jobs::get( $dead['id'] )['last_error'] );
remove_filter( 'mody_export_spawn', $stub, 10 );
foreach ( array( $stale['id'], $dead['id'] ) as $id ) {
	delete_site_transient( 'mody_export_spawn_' . $id );
	$wpdb->update( Jobs::table(), array( 'status' => 'expired' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
}
foreach ( $waiting as $id ) {
	delete_site_transient( 'mody_export_spawn_' . $id );
}

// start() itself respects MAX_RUNNING: builders that run, and builders spawned a moment ago, take the builds.
echo "\nBuilds at once\n";
$spawned = array();
add_filter( 'mody_export_spawn', $stub, 10, 2 );
$expire  = static function ( int ...$ids ) use ( $wpdb ): void {
	foreach ( $ids as $id ) {
		$wpdb->update( Jobs::table(), array( 'status' => 'expired' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		delete_site_transient( 'mody_export_spawn_' . $id );
	}
};
$fill = array(); // Fresh running jobs that take every build that nothing else is using.
for ( $i = Jobs::running(); $i < Jobs::MAX_RUNNING; $i++ ) {
	$fill[] = $make_job( $fake + 30 + $i, 'running' )['id'];
}
if ( count( $fill ) < 2 ) {
	echo "note Another build is using a build: the cap checks that need two free builds are skipped.\n";
} else {
	$w1 = $make_job( $fake + 40, 'queued' );
	$w2 = $make_job( $fake + 41, 'queued' );
	$check( 'the network is full: ' . Jobs::MAX_RUNNING . ' fresh builds are running, none free', 0 === Runner::free() && Jobs::MAX_RUNNING === Jobs::running() );
	$r = Runner::start( $w1['id'] );
	$check( 'start() with no free build: false, nothing spawned, nothing scheduled, no spawn marker', false === $r && array() === $spawned && array() === Runner::scheduled() && false === get_site_transient( 'mody_export_spawn_' . $w1['id'] ) );
	$check( '... the job stays queued, with no attempt used', 'queued' === Jobs::get( $w1['id'] )['status'] && 0 === Jobs::get( $w1['id'] )['attempts'] );
	$r = Runner::watch();
	$check( 'watch() on a full network starts nothing either', 0 === $r && array() === $spawned );

	$expire( array_shift( $fill ) ); // One build ends.
	$check( 'a build ended: one is free', 1 === Runner::free() );
	$r = Runner::start( $w1['id'] );
	$check( 'start() with a free build: true, the builder is spawned for that job', true === $r && array( $w1['id'] ) === $spawned );
	$state = get_site_transient( 'mody_export_spawn_' . $w1['id'] );
	$check( '... and remembered as spawned', is_array( $state ) && 1 === $state['n'] );
	$check( 'a builder that was spawned and has not claimed yet takes the build (free is 0 again)', 0 === Runner::free() );
	$r = Runner::start( $w2['id'] );
	$check( 'so the next start() leaves its job queued', false === $r && array( $w1['id'] ) === $spawned && 'queued' === Jobs::get( $w2['id'] )['status'] && false === get_site_transient( 'mody_export_spawn_' . $w2['id'] ) );

	$expire( array_shift( $fill ) ); // Another ends: a build is free, and one job has its builder on the way.
	$check( 'another build ended: one free, one taken by the builder on the way', 1 === Runner::free() );
	$r = Runner::start( $w1['id'] );
	$check( 'start() for the job whose builder was spawned a moment ago: false, no second builder', false === $r && array( $w1['id'] ) === $spawned );
	$r = Runner::start( $w2['id'] );
	$check( 'start() for the other job takes the free build', true === $r && array( $w1['id'], $w2['id'] ) === $spawned );
	$check( '... and now no build is free, and watch() waits', 0 === Runner::free() && 0 === Runner::watch() && array( $w1['id'], $w2['id'] ) === $spawned );
	$expire( $w1['id'], $w2['id'] );
}
$expire( ...$fill );
$alive = $make_job( $fake + 42, 'running' ); // A builder that is working (fresh heartbeat).
$r     = Runner::start( $alive['id'] );
$check( 'start() for a job whose builder is working: false, no second builder', false === $r && ! in_array( $alive['id'], $spawned, true ) && false === get_site_transient( 'mody_export_spawn_' . $alive['id'] ) );
$expire( $alive['id'] );
$maxed = $make_job( $fake + 43, 'queued' );
$wpdb->update( Jobs::table(), array( 'attempts' => Jobs::MAX_ATTEMPTS ), array( 'id' => $maxed['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
$r = Runner::start( $maxed['id'] );
$check( 'start() for a job claimed ' . Jobs::MAX_ATTEMPTS . ' times already: failed, not started (Jobs::claim() would refuse it)', false === $r && 'failed' === Jobs::get( $maxed['id'] )['status'] && ! in_array( $maxed['id'], $spawned, true ) );
$expire( $maxed['id'] );

// The check and the marker are made under a database lock: with another connection holding it, start() waits, then gives up.
$db_host = (string) DB_HOST;
$db_port = '3306';
if ( str_contains( $db_host, ':' ) ) {
	list( $db_host, $db_port ) = explode( ':', $db_host, 2 );
}
$lockdb = new mysqli( $db_host, DB_USER, DB_PASSWORD, DB_NAME, (int) $db_port );
$lock_name = $wpdb->base_prefix . 'mody_export_spawn';
$held      = $lockdb->query( "SELECT GET_LOCK('" . $lockdb->real_escape_string( $lock_name ) . "', 1)" )->fetch_row()[0] ?? '0';
$w3        = $make_job( $fake + 44, 'queued' );
$spawned   = array();
$t0        = microtime( true );
$r         = Runner::start( $w3['id'] );
$waited    = microtime( true ) - $t0;
$count     = Runner::watch();
$check( 'the lock is held by another connection (a request that is deciding): start() waits for it, then leaves the job queued', '1' === (string) $held && false === $r && $waited >= 4.0 && array() === $spawned && 'queued' === Jobs::get( $w3['id'] )['status'], round( $waited, 1 ) . ' s' );
$check( '... and so does watch(), which starts nothing', 0 === $count && array() === $spawned );
$lockdb->query( "SELECT RELEASE_LOCK('" . $lockdb->real_escape_string( $lock_name ) . "')" );
$lockdb->close();
$free = Runner::free();
$r    = Runner::start( $w3['id'] );
$check( 'with the lock free again, start() works (when a build is free)', $free < 1 || ( true === $r && array( $w3['id'] ) === $spawned ), 'free ' . $free );
$expire( $w3['id'] );
remove_filter( 'mody_export_spawn', $stub, 10 );
foreach ( Jobs::waiting() as $id ) {
	delete_site_transient( 'mody_export_spawn_' . $id ); // Others' jobs: forget this test's spawn markers, it never really spawned them.
}
$settle();

// 7. The command, through the real WP-CLI ---------------------------------------------

echo "\nwp mody-export\n";
$reg = $tmp . '/register.php';
file_put_contents( $reg, "<?php\nWP_CLI::add_hook( 'after_wp_load', static function () { Mody\\Platform\\Export\\Command::register(); } );\n" );
$wp = static function ( string $args, string $url ) use ( $reg, $wp_path ): array {
	exec( 'wp --require=' . escapeshellarg( $reg ) . ' --path=' . escapeshellarg( $wp_path ) . ' --url=' . escapeshellarg( $url ) . ' ' . $args . ' 2>&1', $lines, $code );
	return array(
		'code' => $code,
		'out'  => implode( "\n", $lines ),
	);
};
$main = untrailingslashit( network_home_url() );
$r    = $wp( 'mody-export list --limit=1000 --format=json', $main );
$rows = json_decode( $r['out'], true );
$check( 'list: exit 0, JSON, this test\'s jobs in it', 0 === $r['code'] && is_array( $rows ) && in_array( $job['id'], array_column( $rows, 'id' ), true ), 'exit ' . $r['code'] );
$check( '... with the job\'s state and its files', is_array( $rows ) && (bool) array_filter( $rows, static fn( $row ) => $job['id'] === (int) $row['id'] && 'done' === $row['status'] && str_contains( $row['files'], 'code' ) ) );
$r = $wp( 'mody-export run 99999999', $main );
$check( 'run: an unknown job exits non-zero', 0 !== $r['code'] && str_contains( $r['out'], 'No such job' ), 'exit ' . $r['code'] );
$gone_site = $make_job( $fake + 20, 'queued' );
$r         = $wp( 'mody-export run ' . $gone_site['id'], $main );
$after     = Jobs::get( $gone_site['id'] );
$check( 'run: a job that fails exits non-zero, with why, and the job records it', 0 !== $r['code'] && str_contains( $r['out'], 'Not a member site' ) && 1 === $after['attempts'] && str_contains( $after['last_error'], 'Not a member site' ), 'exit ' . $r['code'] );
$settle(); // The failed job is queued again for another attempt: not wanted.
$r = $wp( 'mody-export run ' . $job['id'], $site_url );
$check( 'run: a finished job is not built again (claimed or done): non-zero', 0 !== $r['code'] );
$r = $wp( 'mody-export start --site=1', $main );
$check( 'start: the main site is refused', 0 !== $r['code'] && str_contains( $r['out'], 'main site' ), 'exit ' . $r['code'] );
$r = $wp( 'mody-export start --site=no-such-site-' . $suffix, $main );
$check( 'start: an unknown site is refused', 0 !== $r['code'] && str_contains( $r['out'], 'No such site' ), 'exit ' . $r['code'] );
$r = $wp( 'mody-export start --site=' . $site . ' --user=no-such-user-' . $suffix, $main );
$check( 'start: an unknown --user is refused (by WP-CLI itself)', 0 !== $r['code'], 'exit ' . $r['code'] );
$r = $wp( 'mody-export start --site=' . $site . ' --owner=no-such-user-' . $suffix, $main );
$check( 'start: an unknown --owner is refused', 0 !== $r['code'] && str_contains( $r['out'], 'No user' ), 'exit ' . $r['code'] );
$jobs_of_site = static function () use ( $wpdb, $site ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Jobs::table() . ' WHERE site_id = %d', $site ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test count.
};
$before   = $jobs_of_site();
$refusals = array(
	'a user who is not a member of the site (--owner, by ID)'        => '--owner=' . $stranger,
	'... the same by email'                                          => '--owner=xrunout' . $suffix . '@mody.local',
	'... the same by login'                                          => '--owner=xrunout' . $suffix,
	'a member of the site who is not its administrator (--owner)'    => '--owner=' . $subscriber,
	'... and WP-CLI\'s own --user, which arrives as the current user' => '--user=' . $subscriber,
	'... a stranger through --user'                                  => '--user=' . $stranger,
);
foreach ( $refusals as $label => $arg ) {
	$r = $wp( 'mody-export start --site=' . $site . ' ' . $arg, $main );
	$check( 'start: ' . $label . ' is refused, and no job is made', 0 !== $r['code'] && str_contains( $r['out'], 'not an administrator' ) && $jobs_of_site() === $before, 'exit ' . $r['code'] . ' ' . substr( trim( $r['out'] ), 0, 100 ) );
}

// watch: the watchdog and the sweep, for a host cron. Its subprocess never spawns real builders: the spawn is stubbed and recorded.
$reg_stub = $tmp . '/register-stub.php';
file_put_contents(
	$reg_stub,
	str_replace(
		'__LOG__',
		$tmp . '/watch-spawned.txt',
		"<?php\nWP_CLI::add_hook( 'after_wp_load', static function () {\n\tMody\\Platform\\Export\\Command::register();\n\tadd_filter( 'mody_export_spawn', static function ( \$r, \$job ) {\n\t\tfile_put_contents( '__LOG__', \$job['id'] . \"\\n\", FILE_APPEND );\n\t\treturn true;\n\t}, 10, 2 );\n} );\n"
	)
);
$wp_stub = static function ( string $args, string $url ) use ( $reg_stub, $wp_path ): array {
	exec( 'wp --require=' . escapeshellarg( $reg_stub ) . ' --path=' . escapeshellarg( $wp_path ) . ' --url=' . escapeshellarg( $url ) . ' ' . $args . ' 2>&1', $lines, $code );
	return array(
		'code' => $code,
		'out'  => implode( "\n", $lines ),
	);
};
$swept_job = $make_job( $site, 'done', array( 'code' => 'x' ), time() - Jobs::KEEP_FOR - DAY_IN_SECONDS );
$cli_wait  = $make_job( $fake + 60, 'queued' );
$expect    = $expected();
update_site_option( 'mody_export_swept', 0 ); // The sweep is hourly: let this one run.
$r      = $wp_stub( 'mody-export watch', $main );
$logged = is_file( $tmp . '/watch-spawned.txt' ) ? array_map( 'intval', array_filter( explode( "\n", trim( (string) file_get_contents( $tmp . '/watch-spawned.txt' ) ) ) ) ) : array();
$check( 'watch: exits 0 and says how many builds it started and jobs it expired', 0 === $r['code'] && 1 === preg_match( '/(\d+) build\(s\) started, (\d+) job\(s\) expired/', $r['out'], $m ), trim( $r['out'] ) );
$check( '... it is Runner::watch(): the waiting jobs with a free build were started, oldest first (as many as it says)', $logged === $expect && (int) ( $m[1] ?? -1 ) === count( $logged ), implode( ',', $logged ) . ' of ' . implode( ',', $expect ) );
$check( '... and Exporter::sweep(): the job past its keeping time is expired and its folder gone', 'expired' === Jobs::get( $swept_job['id'] )['status'] && ! is_dir( Jobs::dir( $swept_job ) ) && (int) ( $m[2] ?? 0 ) >= 1 );
$r = $wp_stub( 'mody-export watch --quiet', $main );
$check( 'watch --quiet (the cron line): exits 0 and prints nothing', 0 === $r['code'] && '' === trim( $r['out'] ), trim( $r['out'] ) );
$expire( $cli_wait['id'] );
foreach ( Jobs::waiting() as $id ) {
	delete_site_transient( 'mody_export_spawn_' . $id ); // Others' jobs: this subprocess only recorded its spawns.
}

// purge: an expired job's files go, and so does a folder no job owns.
$stale_job = $make_job( $site, 'done', array( 'code' => 'x' ), time() - Jobs::KEEP_FOR - DAY_IN_SECONDS );
$orphan    = Jobs::root() . '/' . $site . '/' . bin2hex( random_bytes( 16 ) );
wp_mkdir_p( $orphan );
$orphan_dirs[] = $orphan;
file_put_contents( $orphan . '/code.zip.part', 'half' );
touch( $orphan, time() - 2 * HOUR_IN_SECONDS );
$r = $wp( 'mody-export purge', $main );
$check( 'purge: exits 0 and says how many expired', 0 === $r['code'] && preg_match( '/\d+ job\(s\) expired/', $r['out'] ) === 1, trim( $r['out'] ) );
$check( '... the old job\'s folder is gone and its job expired', ! is_dir( Jobs::dir( $stale_job ) ) && 'expired' === Jobs::get( $stale_job['id'] )['status'] );
$check( '... and so is the folder no job owns', ! is_dir( $orphan ) );
$check( '... a recent finished job is kept', is_file( Jobs::dir( $job ) . '/code.zip' ) && 'done' === Jobs::get( $job['id'] )['status'] );

// 8. End to end -----------------------------------------------------------------------

$settle();

echo "\nEnd to end\n";
$parts = array( 'DatabaseDump', 'Archives', 'Notices' );
$have  = array_filter(
	$parts,
	static function ( string $part ): bool {
		return is_readable( dirname( __DIR__ ) . '/app/web/mu-plugins/mody-platform/src/Export/' . $part . '.php' ) || is_readable( WPMU_PLUGIN_DIR . '/mody-platform/src/Export/' . $part . '.php' );
	}
);
if ( getenv( 'EXPORT_TEST_SKIP_BUILD' ) ) {
	echo "note Skipped: EXPORT_TEST_SKIP_BUILD is set (the real builds take a minute or two).\n";
} elseif ( count( $have ) !== count( $parts ) ) {
	echo 'note Skipped: the builder\'s parts are not all written yet (missing: ' . implode( ', ', array_diff( $parts, $have ) ) . ").\n";
} else {
	$mail_before = null;
	$t0          = microtime( true );
	$r           = $wp( 'mody-export start --site=' . $site . ' --owner=' . $member, $main );
	$built       = Jobs::current( $site );
	$files_ok    = true;
	if ( $built ) {
		foreach ( Jobs::FILES as $which ) {
			$path     = Jobs::dir( $built ) . '/' . Exporter::PATHS[ $which ];
			$files_ok = $files_ok && is_file( $path ) && filesize( $path ) > 0 && isset( $built['files'][ $which ] ) && filesize( $path ) === $built['files'][ $which ]['size'];
		}
	}
	$check( 'start: builds the throwaway site\'s job in the foreground', 0 === $r['code'] && $built && 'done' === $built['status'] && $files_ok, 'exit ' . $r['code'] . ' in ' . round( microtime( true ) - $t0, 1 ) . ' s' );
	if ( 0 !== $r['code'] ) {
		echo "     " . str_replace( "\n", "\n     ", trim( $r['out'] ) ) . "\n";
	}
	if ( $built && 'done' === $built['status'] ) {
		$check( '... the file names are <slug>-<kind>-<date>', 3 === count( array_filter( $built['files'], static fn( $f ) => str_starts_with( $f['name'], strtok( $domain, '.' ) . '-' ) ) ) );
		$p = Downloads::plan( 'GET', $token_of( Downloads::url( $built, 'database' ) ), array(), false, $site );
		$check( '... and every file is served with its checksum', 200 === $p['status'] && hash( 'sha256', $body( $p ) ) === $built['files']['database']['sha256'] );

		// A job started by Runner: WP-CLI in a detached process, then the same build.
		add_filter(
			'mody_export_wp_binary',
			static function () use ( $tmp ): string {
				file_put_contents( $tmp . '/wp-real.sh', "#!/bin/sh\nexec wp --require=" . escapeshellarg( $tmp . '/register.php' ) . " \"\$@\"\n" );
				chmod( $tmp . '/wp-real.sh', 0755 );
				return $tmp . '/wp-real.sh';
			}
		);
		$next = Jobs::create( $site, $member );
		$check( 'a new job after a finished one', $next > 0 && $next !== $built['id'] );
		Runner::start( $next );
		$job2 = Jobs::get( $next );
		for ( $i = 0; $i < 600 && 'done' !== $job2['status'] && 'failed' !== $job2['status']; $i++ ) {
			usleep( 200000 );
			$job2 = Jobs::get( $next );
		}
		$log2 = is_file( Jobs::dir( $job2 ) . '/build.log' ) ? trim( (string) file_get_contents( Jobs::dir( $job2 ) . '/build.log' ) ) : '';
		$check( 'Runner::start() builds it in a detached WP-CLI process', 'done' === $job2['status'] && 1 === $job2['attempts'], $job2['status'] . ', ' . $job2['step'] . ( $job2['last_error'] ? ', ' . $job2['last_error'] : '' ) );
		if ( 'done' !== $job2['status'] ) {
			echo "     build.log: " . str_replace( "\n", "\n     ", $log2 ) . "\n";
		}
	}
}

echo $failures ? sprintf( "\n%d of %d checks failed.\n", $failures, $total ) : sprintf( "\nAll %d checks passed.\n", $total );
