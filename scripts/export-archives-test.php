<?php
/**
 * Tests Export\Archives, the media and code files of "Take your site with
 * you" (tickets/take-your-site-with-you.md).
 *
 * Run from the repository root, on a member site (alpha.mody.local):
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://alpha.mody.local eval-file - < scripts/export-archives-test.php
 *
 * 1. media.zip of the site's real uploads folder: the entries are the
 *    folder's files, sizes and contents match, no PHP, the manifest at the
 *    root (`file: media`), progress reported, no .part left.
 * 2. media.zip of a fixture folder with every case: everything stored but a
 *    short list of text types, UTF-8 names, and what must never ship (PHP
 *    and double extensions, .htaccess and .user.ini, hidden files, .part
 *    files, symlinks, unreadable files, personal-data exports, a file named
 *    like the manifest, unsafe names), counted with their reasons in
 *    mody-export-skipped.txt (the first 200 listed). A file that vanishes
 *    while the zip is made. An empty and a missing folder. The disk check
 *    at its boundary. The main site refused. Libzip's temporary files
 *    deleted before a build. A heartbeat while the folder is listed.
 * 3. code.zip: the importer plugin and its payload, the per-export files
 *    (README-restore.txt and the manifest, `file: code`), the .htaccess and
 *    index.php files that keep it off the web, `Update URI: false` in every
 *    header of ours, no agent, no platform; the README's shell lines are
 *    run with a stub `wp`, and its search-replace pairs applied to sample
 *    text in several cases (a new address that begins with the old one).
 * 4. The payload cache: built once, reused by the second export (faster, the
 *    same files untouched), one folder, a source change makes a new one, an
 *    old one is pruned; the key covers Fonts.php and Archives.php; three
 *    builds started together on an empty cache all succeed.
 *
 * Everything is written to a folder under /tmp, and removed. The real uploads
 * folder gets a small fixture folder that is removed too. No model call.
 */

use Mody\Platform\Export\Archives;
use Mody\Platform\Export\Exporter;

$failures = 0;
$total    = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures, &$total ): void {
	++$total;
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-70s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

$site_id = get_current_blog_id();
if ( is_main_site( $site_id ) ) {
	echo "Run this on a member site, for example --url=https://alpha.mody.local\n";
	exit( 1 );
}

$work = '/tmp/mody-export-archives-test-' . strtolower( wp_generate_password( 6, false ) );
mkdir( $work . '/out', 0777, true );

/** Removes a folder made by this test. */
$rrmdir = static function ( string $dir ) use ( &$rrmdir ): void {
	if ( ! is_dir( $dir ) || ! str_starts_with( $dir, '/tmp/mody-export-archives-test-' ) && ! str_contains( $dir, '/mody-export-test-' ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		$path = $dir . '/' . $name;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			$rrmdir( $path );
		} else {
			@chmod( $path, 0644 ); // phpcs:ignore -- The locked fixture.
			@unlink( $path ); // phpcs:ignore -- Test cleanup.
		}
	}
	@rmdir( $dir ); // phpcs:ignore -- Test cleanup.
};

/** Regular files under a folder, relative names, by a simple walk of our own (the expected list). */
$listing = static function ( string $base ): array {
	$names = array();
	if ( ! is_dir( $base ) ) {
		return $names;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$rel = substr( $file->getPathname(), strlen( $base ) + 1 );
		if ( $file->isLink() || ! $file->isFile() || '.' === $file->getFilename()[0] || preg_match( '/\.php/i', $rel ) || str_ends_with( $rel, '.part' ) || 'mody-export.json' === $rel || str_starts_with( $rel, 'wp-personal-data-exports/' ) ) {
			continue;
		}
		$names[] = $rel;
	}
	sort( $names );
	return $names;
};

/** A zip's entry names, without the manifest, sorted; and the opened zip. */
$entries = static function ( string $path, ?ZipArchive &$zip = null ): array {
	$zip   = new ZipArchive();
	$names = array();
	if ( true !== $zip->open( $path ) ) {
		return $names;
	}
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$names[] = (string) $zip->getNameIndex( $i );
	}
	sort( $names );
	return $names;
};

$job      = array(
	'site_id'    => $site_id,
	'user_id'    => 1,
	'token'      => md5( 'export-archives-test-one' ),
	'created_at' => gmdate( 'Y-m-d H:i:s' ),
);
$manifest = Exporter::manifest( $job );
$norm     = static fn( $value ) => json_decode( (string) wp_json_encode( $value ), true );
$media_m  = $manifest + array( 'file' => 'media' );
$code_m   = $manifest + array( 'file' => 'code' );

$cleanup_dirs = array( $work );
$real         = untrailingslashit( (string) wp_get_upload_dir()['basedir'] );
$fixture_real = $real . '/mody-export-test-' . substr( basename( $work ), -6 );

try {
	// Exports go to the test folder, not to the platform's app/dumps/exports.
	add_filter( 'mody_export_root', static fn() => $GLOBALS['mody_test_root'] );
	$GLOBALS['mody_test_root'] = $work . '/exports';

	/*
	 * 1. The site's real uploads folder.
	 */
	echo "-- media.zip: the site's uploads\n";
	$check( 'the uploads folder is the site\'s own (sites/' . $site_id . ')', str_ends_with( $real, '/sites/' . $site_id ), $real );
	mkdir( $fixture_real . '/2026/11', 0777, true );
	file_put_contents( $fixture_real . '/2026/11/photo.jpg', random_bytes( 60000 ) );
	file_put_contents( $fixture_real . '/2026/11/notes.txt', str_repeat( "A line of text that compresses well.\n", 2000 ) );
	file_put_contents( $fixture_real . '/2026/11/index.php', '<?php // Silence.' );

	$calls  = array();
	$out    = $work . '/out/media-real.zip';
	$result = Archives::media(
		$site_id,
		$out,
		$manifest,
		static function ( int $done, int $total ) use ( &$calls ): void {
			$calls[] = array( $done, $total );
		}
	);
	$check( 'media() returns true', true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
	$check( 'the zip exists and no .part is left', is_file( $out ) && ! file_exists( $out . '.part' ) );
	$names    = $entries( $out, $zip );
	$expected = $listing( $real );
	$expected[] = 'mody-export.json';
	$expected[] = Archives::SKIPPED_FILE; // The fixture's index.php is left out.
	sort( $expected );
	$check( 'the entries are the uploads\' files, the manifest and the list of what is left out', $names === $expected, count( $names ) . ' entries, expected ' . count( $expected ) );
	$check( 'the fixture\'s jpg and txt are in, its PHP file is not', in_array( substr( $fixture_real, strlen( $real ) + 1 ) . '/2026/11/photo.jpg', $names, true ) && ! preg_grep( '/\.php/i', $names ) );
	$sizes_ok = true;
	foreach ( array_diff( $names, array( 'mody-export.json', Archives::SKIPPED_FILE ) ) as $name ) {
		$stat      = $zip->statName( $name );
		$sizes_ok  = $sizes_ok && is_array( $stat ) && filesize( $real . '/' . $name ) === $stat['size'] && md5_file( $real . '/' . $name ) === md5( (string) $zip->getFromName( $name ) );
	}
	$check( 'every file\'s size and contents match', $sizes_ok );
	$photo = substr( $fixture_real, strlen( $real ) + 1 ) . '/2026/11/photo.jpg';
	$notes = substr( $fixture_real, strlen( $real ) + 1 ) . '/2026/11/notes.txt';
	$check( 'a jpg is stored, a text file deflated', ZipArchive::CM_STORE === $zip->statName( $photo )['comp_method'] && ZipArchive::CM_DEFLATE === $zip->statName( $notes )['comp_method'] && $zip->statName( $notes )['comp_size'] < $zip->statName( $notes )['size'] );
	$check( 'mody-export.json is at the root, equals the manifest, and says file: media', $norm( json_decode( (string) $zip->getFromName( 'mody-export.json' ), true ) ) === $norm( $media_m ) && 'media' === json_decode( (string) $zip->getFromName( 'mody-export.json' ), true )['file'] );
	$skipped = (string) $zip->getFromName( Archives::SKIPPED_FILE );
	$check( 'the fixture\'s PHP file is in the left-out list, with its reason and the total', preg_match( '/^Total left out: (\d+)$/m', $skipped, $found ) && (int) $found[1] >= 1 && str_contains( $skipped, '/2026/11/index.php  (PHP code is never shipped from uploads)' ) );
	$sum = 0;
	foreach ( array_diff( $names, array( 'mody-export.json', Archives::SKIPPED_FILE ) ) as $name ) {
		$sum += filesize( $real . '/' . $name );
	}
	$last = $calls ? $calls[ count( $calls ) - 1 ] : array( -1, -2 );
	$check( 'progress was reported, ending at the total in bytes', count( $calls ) >= 1 && $last[0] === $last[1] && $sum === $last[1], count( $calls ) . ' calls, ' . $last[1] . ' bytes' );
	$zip->close();
	$rrmdir( $fixture_real );

	/*
	 * 2. A fixture folder with every case.
	 */
	echo "-- media.zip: every case\n";
	$fixture = $work . '/uploads';
	$other   = $work . '/elsewhere';
	mkdir( $fixture . '/2026/11', 0777, true );
	mkdir( $fixture . '/fonts/Inter', 0777, true );
	mkdir( $fixture . '/wp-personal-data-exports', 0777, true );
	mkdir( $other, 0777, true );
	$put = static function ( string $rel, string $data ) use ( $fixture ): void {
		file_put_contents( $fixture . '/' . $rel, $data );
	};
	$put( '2026/11/photo.jpg', random_bytes( 300000 ) );
	$put( '2026/11/vector.svg', str_repeat( '<path d="M0 0L10 10"/>', 9000 ) );
	$put( '2026/11/Café-ñandú-日本.png', random_bytes( 5000 ) );
	$put( '2026/11/report.part.jpg', random_bytes( 2000 ) ); // Legit: ".part" only matters at the end.
	$put( 'fonts/Inter/Inter.woff2', random_bytes( 20000 ) );
	$put( 'readme.txt', 'Hello' );
	$put( 'zzz-empty.jpg', '' );
	// Stored, as everything but the text list: video, office files and an unknown type.
	$put( 'clip.m4v', random_bytes( 30000 ) );
	$put( 'doc.docx', random_bytes( 9000 ) );
	$put( 'blob.xyz', str_repeat( 'compressible but not text ', 3000 ) );
	// Deflated: the text list, in any case.
	$put( 'data.CSV', str_repeat( "a,b,c\n", 4000 ) );
	$put( 'style.css', str_repeat( '.a { color: red; }', 2000 ) );
	$put( 'NOTES.MD', str_repeat( "# A line\n", 3000 ) );
	$put( 'page.html', str_repeat( '<p>Hello</p>', 3000 ) );
	// Never shipped.
	$put( '2026/11/evil.php', '<?php system($_GET["c"]);' );
	$put( '2026/11/evil.php5', '<?php' );
	$put( '2026/11/evil.PHTML', '<?php' );
	$put( '2026/11/evil.php.jpg', '<?php' );
	$put( '2026/11/draft.jpg.part', 'partial' );
	$put( '.htaccess', 'AddHandler application/x-httpd-php .jpg' );
	$put( '.user.ini', 'auto_prepend_file=x' );
	$put( '2026/11/.hidden', 'x' );
	$put( 'wp-personal-data-exports/export.zip', 'personal data' );
	$put( 'mody-export.json', '{"junk":true}' );
	mkdir( $fixture . '/.cache', 0777, true );
	$put( '.cache/thumb.jpg', 'inside a hidden folder' );
	$put( 'bad\\name.jpg', 'a backslash in the name' );
	$put( "caf\xe9.jpg", 'a name that is not UTF-8' );
	file_put_contents( $other . '/secret.jpg', 'outside the uploads folder' );
	symlink( $fixture . '/2026/11/photo.jpg', $fixture . '/2026/11/link.jpg' );
	symlink( $other, $fixture . '/linked-folder' );
	$put( '2026/11/unreadable.jpg', 'x' );
	chmod( $fixture . '/2026/11/unreadable.jpg', 0000 );
	$locked_works = ! is_readable( $fixture . '/2026/11/unreadable.jpg' );

	$included = array( '2026/11/Café-ñandú-日本.png', '2026/11/photo.jpg', '2026/11/report.part.jpg', '2026/11/vector.svg', 'fonts/Inter/Inter.woff2', 'readme.txt', 'zzz-empty.jpg', 'clip.m4v', 'doc.docx', 'blob.xyz', 'data.CSV', 'style.css', 'NOTES.MD', 'page.html' );
	if ( ! $locked_works ) {
		$included[] = '2026/11/unreadable.jpg'; // The mount ignored chmod: the file is readable, so it ships.
	}
	sort( $included );
	// Left out, by reason: PHP 4, .part 1, hidden 4 (.htaccess, .user.ini, .hidden, .cache/), personal data 1, our own name 1, symlinks 2, unsafe names 2 (a backslash, invalid UTF-8), and the unreadable file.
	$left_expected = 15 + ( $locked_works ? 1 : 0 );
	$expect        = array_merge( $included, array( 'mody-export.json', Archives::SKIPPED_FILE ) );
	sort( $expect );

	$fixture_filter = static function ( array $dirs ) use ( &$fixture ): array {
		$dirs['basedir'] = $fixture;
		return $dirs;
	};
	add_filter( 'upload_dir', $fixture_filter );

	$out    = $work . '/out/media.zip';
	$result = Archives::media( $site_id, $out, $manifest, static function (): void {} );
	$check( 'media() returns true', true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
	$names = $entries( $out, $zip );
	$check( 'exactly the shippable files, the manifest and the list of what is left out', $names === $expect, implode( ', ', array_diff( $names, $included ) ) );
	$check( 'no PHP, .htaccess, .user.ini, hidden, .part, symlink or personal-data entry', ! preg_grep( '/\.php|\.phtml|htaccess|user\.ini|\.hidden|\.cache|draft|link|personal|secret|bad|caf.\.jpg/i', array_diff( $names, array( '2026/11/unreadable.jpg' ) ) ) );
	$check( 'an unreadable file is left out (or the mount ignored chmod)', ! $locked_works || ! in_array( '2026/11/unreadable.jpg', $names, true ) );
	$check( 'the root manifest is ours, not the folder\'s junk file, and says file: media', $norm( json_decode( (string) $zip->getFromName( 'mody-export.json' ), true ) ) === $norm( $media_m ) );
	$skipped = (string) $zip->getFromName( Archives::SKIPPED_FILE );
	$check( 'mody-export-skipped.txt counts every left-out entry', preg_match( '/^Total left out: (\d+)$/m', $skipped, $found ) && (int) $found[1] === $left_expected, ( $found[1] ?? '?' ) . ' of ' . $left_expected );
	foreach ( array(
		'2026/11/evil.php  (PHP code is never shipped from uploads)',
		'2026/11/evil.php.jpg  (PHP code is never shipped from uploads)',
		'2026/11/draft.jpg.part  (unfinished upload (.part))',
		'.htaccess  (hidden file or folder)',
		'.cache  (hidden file or folder)',
		'2026/11/link.jpg  (symbolic link)',
		'linked-folder  (symbolic link)',
		'wp-personal-data-exports  (private: personal data exports)',
		'mody-export.json  (name used by this download itself)',
		'bad\\name.jpg  (name not safe in a zip',
		'caf?.jpg  (name not safe in a zip',
	) as $line ) {
		$check( 'the list has: ' . $line, str_contains( $skipped, "\n" . $line ) );
	}
	$check( 'the list is all text lines, no unsafe character', ! preg_match( '/[\x00-\x09\x0b-\x1f\x7f]/', $skipped ) && mb_check_encoding( $skipped, 'UTF-8' ) );
	$check( 'a file with a UTF-8 name is intact', md5_file( $fixture . '/2026/11/Café-ñandú-日本.png' ) === md5( (string) $zip->getFromName( '2026/11/Café-ñandú-日本.png' ) ) );
	$check( 'a zero-byte file is kept', false !== $zip->statName( 'zzz-empty.jpg' ) && 0 === $zip->statName( 'zzz-empty.jpg' )['size'] );
	$method = static fn( string $name ): int => (int) $zip->statName( $name )['comp_method'];
	$check( 'jpg, png and woff2 are stored; svg and txt deflated', ZipArchive::CM_STORE === $method( '2026/11/photo.jpg' ) && ZipArchive::CM_STORE === $method( '2026/11/Café-ñandú-日本.png' ) && ZipArchive::CM_STORE === $method( 'fonts/Inter/Inter.woff2' ) && ZipArchive::CM_DEFLATE === $method( '2026/11/vector.svg' ) && ZipArchive::CM_DEFLATE === $method( 'readme.txt' ) );
	$check( 'everything else is stored: m4v, docx and an unknown type, even when it would compress', ZipArchive::CM_STORE === $method( 'clip.m4v' ) && ZipArchive::CM_STORE === $method( 'doc.docx' ) && ZipArchive::CM_STORE === $method( 'blob.xyz' ) && $zip->statName( 'blob.xyz' )['comp_size'] === $zip->statName( 'blob.xyz' )['size'] );
	$check( 'the text list is deflated, in any case: csv, css, md, html', ZipArchive::CM_DEFLATE === $method( 'data.CSV' ) && ZipArchive::CM_DEFLATE === $method( 'style.css' ) && ZipArchive::CM_DEFLATE === $method( 'NOTES.MD' ) && ZipArchive::CM_DEFLATE === $method( 'page.html' ) );
	$check( 'the zip unzips straight into uploads (relative names only)', ! preg_grep( '#^/|\.\./|\\\\#', $names ) );
	$zip->close();
	$data_size = (int) array_sum( array_map( static fn( string $name ): int => (int) filesize( $fixture . '/' . $name ), $included ) );

	// A file that vanishes after it is queued: the zip is written again from a fresh list.
	$put( 'zzz-vanish.jpg', random_bytes( 1000 ) );
	$vanish  = $fixture . '/zzz-vanish.jpg';
	$queued  = 0;
	$vanisher = static function ( string $path ) use ( $vanish, &$queued ): void {
		++$queued;
		if ( $path === $vanish && is_file( $vanish ) ) {
			unlink( $vanish );
		}
	};
	add_action( 'mody_export_zip_entry_added', $vanisher );
	$out    = $work . '/out/media-vanish.zip';
	$result = Archives::media( $site_id, $out, $manifest, static function (): void {} );
	remove_action( 'mody_export_zip_entry_added', $vanisher );
	$names = $entries( $out, $zip );
	$check( 'a file that vanishes mid-way: still true, second pass', true === $result && $queued > count( $included ) + 1, is_wp_error( $result ) ? $result->get_error_message() : $queued . ' files queued' );
	$check( '... and the vanished file is not in the zip', ! in_array( 'zzz-vanish.jpg', $names, true ) && $names === $expect );
	$zip->close();

	// The heartbeat goes on while the folder is listed, not only while it is zipped.
	$beats   = array();
	$first   = null;
	$probe   = static function () use ( &$first, &$beats ): void {
		if ( null === $first ) {
			$first = count( $beats );
		}
	};
	$collect = static function ( int $done, int $total ) use ( &$beats ): void {
		$beats[] = array( $done, $total );
	};
	add_action( 'mody_export_zip_entry_added', $probe );
	Archives::media( $site_id, $work . '/out/media-beat.zip', $manifest, $collect );
	$default_beats = $first;
	$first         = null;
	$beats         = array();
	$every_entry   = static fn() => 0;
	add_filter( 'mody_export_beat_every', $every_entry );
	Archives::media( $site_id, $work . '/out/media-beat2.zip', $manifest, $collect );
	remove_filter( 'mody_export_beat_every', $every_entry );
	remove_action( 'mody_export_zip_entry_added', $probe );
	$scanned = count( $included ) + $left_expected;
	$check( 'a quick listing sends just one heartbeat before the first file is queued (15 s apart by default)', null !== $default_beats && $default_beats <= 2, (string) $default_beats );
	$check( 'with a 0 s interval every listed entry sends one', null !== $first && $first >= $scanned, $first . ' heartbeats for at least ' . $scanned . ' entries' );

	// A build killed in close() leaves libzip\'s temporary file beside the part file: the next build deletes both, and only its own.
	$out = $work . '/out/media-leftover.zip';
	file_put_contents( $out . '.part', 'half a zip' );
	file_put_contents( $out . '.part.k7mglw.part', 'libzip\'s temporary file' );
	file_put_contents( $work . '/out/media-other.zip.part.abc123.part', 'another file\'s' );
	$result = Archives::media( $site_id, $out, $manifest, static function (): void {} );
	$check( 'a build deletes the part file and libzip\'s temporary leftovers of its own zip first', true === $result && is_file( $out ) && ! file_exists( $out . '.part' ) && ! file_exists( $out . '.part.k7mglw.part' ), is_wp_error( $result ) ? $result->get_error_message() : '' );
	$check( '... and not another file\'s', is_file( $work . '/out/media-other.zip.part.abc123.part' ) );

	// An empty uploads folder, and one that does not exist.
	$empty  = $work . '/empty-uploads';
	mkdir( $empty );
	$fixture = $empty;
	$out     = $work . '/out/media-empty.zip';
	$result  = Archives::media( $site_id, $out, $manifest, static function (): void {} );
	$names   = $entries( $out, $zip );
	$check( 'an empty uploads folder still gives a valid zip with the manifest', true === $result && array( 'mody-export.json' ) === $names );
	$zip->close();
	$fixture = $work . '/never-made';
	$out     = $work . '/out/media-missing.zip';
	$result  = Archives::media( $site_id, $out, $manifest, static function (): void {} );
	$names   = $entries( $out, $zip );
	$check( 'a missing uploads folder too', true === $result && array( 'mody-export.json' ) === $names );
	$zip->close();

	// A long list is cut at 200 names; the total stays exact.
	$many = $work . '/uploads-many';
	mkdir( $many );
	for ( $i = 0; $i < 230; $i++ ) {
		file_put_contents( sprintf( '%s/.h%03d', $many, $i ), 'x' );
	}
	file_put_contents( $many . '/keep.jpg', 'x' );
	$fixture = $many;
	$out     = $work . '/out/media-many.zip';
	$result  = Archives::media( $site_id, $out, $manifest, static function (): void {} );
	$names   = $entries( $out, $zip );
	$text    = (string) $zip->getFromName( Archives::SKIPPED_FILE );
	$check( 'a long left-out list: the total is exact, 200 names are listed, the rest counted', true === $result && str_contains( $text, "\nTotal left out: 230\n" ) && 200 === preg_match_all( '/^\.h\d{3}  \(hidden file or folder\)$/m', $text ) && str_contains( $text, "\n... and 30 more.\n" ) );
	$check( '... and the shippable file is still in', array( 'keep.jpg', Archives::SKIPPED_FILE, 'mody-export.json' ) === array_values( array_intersect( array( 'keep.jpg', Archives::SKIPPED_FILE, 'mody-export.json' ), $names ) ) && 3 === count( $names ) );
	$zip->close();

	// The disk check: 1.1 x the data + 200 MB, at its boundary.
	$fixture = $work . '/uploads';
	$need    = (int) ceil( $data_size * 1.1 ) + 200 * MB_IN_BYTES;
	$tight   = static fn() => $GLOBALS['mody_test_free'];
	add_filter( 'mody_export_free_space', $tight );
	$GLOBALS['mody_test_free'] = $need - 1;
	$out    = $work . '/out/media-nodisk.zip';
	$result = Archives::media( $site_id, $out, $manifest, static function (): void {} );
	$check( 'one byte short of 1.1 x data + 200 MB: refused with a plain message', is_wp_error( $result ) && 'mody_export_disk' === $result->get_error_code() && str_contains( $result->get_error_message(), 'not enough free disk space' ) && ! is_file( $out ) && ! file_exists( $out . '.part' ), is_wp_error( $result ) ? $result->get_error_message() : 'not refused' );
	$GLOBALS['mody_test_free'] = $need;
	$result = Archives::media( $site_id, $out, $manifest, static function (): void {} );
	$check( 'exactly enough: it starts', true === $result && is_file( $out ) );
	remove_filter( 'mody_export_free_space', $tight );
	remove_filter( 'upload_dir', $fixture_filter );

	// The main site's uploads hold every member's files: never.
	$out    = $work . '/out/media-main.zip';
	$result = Archives::media( get_main_site_id(), $out, $manifest, static function (): void {} );
	$check( 'the main site is refused', is_wp_error( $result ) && 'mody_export_site' === $result->get_error_code() && ! is_file( $out ) );
	$result = Archives::media( 999999, $out, $manifest, static function (): void {} );
	$check( 'a site that does not exist is refused', is_wp_error( $result ) && ! is_file( $out ) );

	/*
	 * 3. code.zip.
	 */
	echo "-- code.zip\n";
	$cache_root = Archives::cache_root();
	$check( 'the cache lives under the export root', $work . '/exports/.code-cache' === $cache_root );
	$check( 'no cache yet', ! is_dir( $cache_root ) );
	$calls  = array();
	$out1   = $work . '/out/code1.zip';
	$start  = microtime( true );
	$result = Archives::code(
		$out1,
		$manifest,
		static function ( int $done, int $total ) use ( &$calls ): void {
			$calls[] = array( $done, $total );
		}
	);
	$first  = microtime( true ) - $start;
	$check( 'code() returns true', true === $result, is_wp_error( $result ) ? $result->get_error_message() : sprintf( '%.1f s, %s', $first, size_format( (int) filesize( $out1 ), 1 ) ) );
	$check( 'the zip exists and no .part is left', is_file( $out1 ) && ! file_exists( $out1 . '.part' ) );
	$last = $calls ? $calls[ count( $calls ) - 1 ] : array( -1, -2 );
	$check( 'progress was reported, ending at the total', count( $calls ) >= 1 && $last[0] === $last[1] && $last[1] > 0, count( $calls ) . ' calls' );

	$names = $entries( $out1, $zip );
	$check( 'every entry is inside mody-import/ (an installable plugin zip)', $names && ! array_filter( $names, static fn( string $name ): bool => ! str_starts_with( $name, 'mody-import/' ) ) );
	$check( 'no unsafe entry names', ! preg_grep( '#^/|\.\.|\\\\#', $names ) );
	foreach ( array( 'mody-import/mody-import.php', 'mody-import/README-restore.txt', 'mody-import/mody-export.json', 'mody-import/LICENSE.txt', 'mody-import/.htaccess', 'mody-import/index.php', 'mody-import/payload/.htaccess', 'mody-import/payload/index.php', 'mody-import/payload/plugins/mody-site/mody-site.php', 'mody-import/payload/plugins/spectra-blocks/spectra-blocks.php', 'mody-import/payload/plugins/antispam-bee/antispam_bee.php', 'mody-import/payload/plugins/mody-languages/mody-languages.php', 'mody-import/payload/plugins/mody-images/mody-images.php', 'mody-import/payload/themes/theme/theme.json', 'mody-import/payload/themes/theme/assets/fonts/inter/InterVariable.woff2' ) as $name ) {
		$check( 'has ' . $name, in_array( $name, $names, true ) );
	}
	$bad = preg_grep( '#^mody-import/payload/plugins/(ai|mcp-adapter|ai-provider-for-ollama|wordfence|mody-mail|mody-analytics|query-monitor|user-switching)/|^mody-import/payload/plugins/mody-site/src/(Agent|AI|Gating|Members|Provisioning|Wizard|Dashboard|Onboarding)/|mody-site/src/(Abilities|Spectra/SpectraLoader|Users/NetworkProfile|Security/Altcha|Security/FormGuard)\.php#', $names );
	$check( 'nothing of the agent, the platform\'s locks, billing, mail or the dev plugins', ! $bad, implode( ', ', array_slice( $bad, 0, 3 ) ) );

	$unzipped = $work . '/unzipped';
	mkdir( $unzipped );
	$check( 'it unzips', true === $zip->extractTo( $unzipped ) );
	$header = get_file_data(
		$unzipped . '/mody-import/mody-import.php',
		array(
			'name'    => 'Plugin Name',
			'version' => 'Version',
			'license' => 'License',
			'php'     => 'Requires PHP',
		)
	);
	$check( 'the stub has a proper plugin header', 'Mody Import' === $header['name'] && '' !== $header['version'] && str_contains( $header['license'], 'GPL' ) && '8.1' === $header['php'], $header['name'] . ' ' . $header['version'] );
	exec( 'php -l ' . escapeshellarg( $unzipped . '/mody-import/mody-import.php' ) . ' 2>&1', $lint, $status );
	$check( 'the stub lints', 0 === $status );
	$stub = (string) file_get_contents( $unzipped . '/mody-import/mody-import.php' );
	$check( 'the stub makes no network calls', ! preg_match( '/wp_remote|curl_|fsockopen|file_get_contents\(\s*[\'"]https?:|wp_safe_remote/', $stub ) );
	$check( 'the stub adds Tools → Mody Import and points to the README', str_contains( $stub, 'add_management_page' ) && str_contains( $stub, 'README-restore.txt' ) && str_contains( $stub, 'later version' ) );

	$theme_json = json_decode( (string) file_get_contents( $unzipped . '/mody-import/payload/themes/theme/theme.json' ), true );
	$inter      = null;
	foreach ( (array) ( $theme_json['settings']['typography']['fontFamilies'] ?? array() ) as $family ) {
		if ( 'inter' === ( $family['slug'] ?? '' ) ) {
			$inter = $family;
		}
	}
	$check( 'the theme.json has Inter\'s fontFace, from the theme\'s own files', is_array( $inter ) && count( $inter['fontFace'] ?? array() ) >= 2 && str_starts_with( (string) $inter['fontFace'][0]['src'][0], 'file:./assets/fonts/inter/' ) );
	$check( 'woff2 stored, php deflated', ZipArchive::CM_STORE === $zip->statName( 'mody-import/payload/themes/theme/assets/fonts/inter/InterVariable.woff2' )['comp_method'] && ZipArchive::CM_DEFLATE === $zip->statName( 'mody-import/payload/plugins/mody-site/mody-site.php' )['comp_method'] );
	$inner = json_decode( (string) $zip->getFromName( 'mody-import/mody-export.json' ), true );
	$check( 'the manifest inside is the export\'s, and says file: code', $norm( $inner ) === $norm( $code_m ) && 'code' === $inner['file'] );

	// Nothing of the download is meant for the web: the kit's .htaccess files and index.php.
	$htaccess = (string) file_get_contents( $unzipped . '/mody-import/.htaccess' );
	$check( 'mody-import/.htaccess denies *.json and *.txt (Apache 2.4 and 2.2)', str_contains( $htaccess, '<FilesMatch "\.(json|txt)$">' ) && str_contains( $htaccess, 'Require all denied' ) && str_contains( $htaccess, 'Deny from all' ) );
	$payload_htaccess = (string) file_get_contents( $unzipped . '/mody-import/payload/.htaccess' );
	$check( 'payload/.htaccess denies everything (Apache 2.4 and 2.2)', str_contains( $payload_htaccess, 'Require all denied' ) && str_contains( $payload_htaccess, 'Deny from all' ) && ! str_contains( $payload_htaccess, 'FilesMatch' ) );
	$silence = true;
	foreach ( array( 'mody-import/index.php', 'mody-import/payload/index.php' ) as $name ) {
		$code    = trim( (string) file_get_contents( $unzipped . '/' . $name ) );
		$silence = $silence && 1 === preg_match( '#^<\?php\s*(?://[^\n]*)?$#', $code );
	}
	$check( 'both index.php files are silence (a comment and nothing else)', $silence );
	$check( 'the stub shows the README itself: its .txt is not served', ! str_contains( $stub, 'plugins_url' ) && str_contains( $stub, "'/README-restore.txt'" ) );

	// "Update URI: false": core would match these folder names to wordpress.org, where anyone can register a plugin by that name.
	$no_update = array(
		'the importer'    => 'mody-import/mody-import.php',
		'Mody Site'       => 'mody-import/payload/plugins/mody-site/mody-site.php',
		'Mody Languages'  => 'mody-import/payload/plugins/mody-languages/mody-languages.php',
		'Mody Images'     => 'mody-import/payload/plugins/mody-images/mody-images.php',
	);
	foreach ( $no_update as $label => $name ) {
		$data = get_file_data( $unzipped . '/' . $name, array( 'uri' => 'Update URI' ) );
		$check( 'the header of ' . $label . ' says Update URI: false', 'false' === $data['uri'], $data['uri'] );
	}
	$data = get_file_data( $unzipped . '/mody-import/payload/themes/theme/style.css', array( 'uri' => 'Update URI' ) );
	$check( 'the theme\'s style.css says Update URI: false', 'false' === $data['uri'], $data['uri'] );
	$theme = new WP_Theme( 'theme', $unzipped . '/mody-import/payload/themes' );
	$check( 'WordPress itself reads it from the theme (core checks this header before it asks wordpress.org)', 'false' === $theme->get( 'UpdateURI' ), (string) $theme->get( 'UpdateURI' ) );

	// The README. The media file of this folder (section 2's) left entries out: it says how many.
	$readme       = (string) $zip->getFromName( 'mody-import/README-restore.txt' );
	$uploads_path = (string) $manifest['site']['uploads']['path'];
	$content_path = (string) $manifest['site']['content'];
	$check( 'the README names the site\'s own uploads path', '' !== $uploads_path && str_contains( $readme, $uploads_path ), $uploads_path );
	$home_bare = (string) preg_replace( '#^https?://#', '', (string) $manifest['site']['home'] );
	$net_hosts = array_values( array_diff( (array) $manifest['site']['hosts'], array( $home_bare ) ) );
	foreach ( $net_hosts as $host ) {
		$check( 'the README has the media pair for the network host ' . $host . ' in https and http', str_contains( $readme, "'https://" . $host . $uploads_path . "' \"\$NEW_HOME/wp-content/uploads\"" ) && str_contains( $readme, "'http://" . $host . $uploads_path . "' \"\$NEW_HOME/wp-content/uploads\"" ) );
	}
	$check( 'the home pair (never the network host), the folders under the new address, and the escaped pairs', str_contains( $readme, "'https://" . $home_bare . "' \"\$NEW_HOME\"" ) && ! preg_match( '#\'https?://' . preg_quote( (string) ( $net_hosts[0] ?? 'none.invalid' ), '#' ) . '\' "\$NEW_HOME#', $readme ) && str_contains( $readme, '"$NEW_HOME' . $uploads_path . '" "$NEW_HOME/wp-content/uploads"' ) && str_contains( $readme, '"$NEW_HOME' . $content_path . '/" "$NEW_HOME/wp-content/"' ) && str_contains( $readme, str_replace( '/', '\\/', 'https://' . $home_bare ) ) && str_contains( $readme, '"$NEW_HOME_JSON' . str_replace( '/', '\\/', $uploads_path ) . '"' ) );
	$check( 'the README carries the table prefix placeholder, the sed and the WP-CLI variant', str_contains( $readme, '{{prefix}}' ) && str_contains( $readme, "sed 's/{{prefix}}/wp_/g' ) | mysql" ) && str_contains( $readme, ') | wp db import -' ) && str_contains( $readme, 'TRUNCATE TABLE' ) );
	$slugs = implode( ' ', array_map( 'dirname', (array) $manifest['plugins'] ) );
	$check( 'the README names the WordPress version, the theme and the manifest\'s plugins', str_contains( $readme, 'WordPress ' . $manifest['requires']['wp'] ) && str_contains( $readme, 'wp theme activate ' . $manifest['theme'] ) && str_contains( $readme, 'wp plugin activate ' . $slugs ), $slugs );
	$check( 'no unfilled placeholder in the README', ! str_contains( $readme, '@@' ) && ! str_contains( $readme, '%s' ) && ! str_contains( $readme, '%d' ) );
	$wide = array_filter( explode( "\n", $readme ), static fn( string $line ): bool => ! str_starts_with( $line, '    ' ) && mb_strlen( $line ) > 80 );
	$check( 'the README\'s prose fits 80 columns', ! $wide, implode( ' | ', array_slice( $wide, 0, 1 ) ) );

	// The permalinks: only saving them in wp-admin writes .htaccess; and the last step cleans up.
	$check( 'the permalink step is Settings → Permalinks → Save Changes, with wp rewrite flush as the extra for Nginx', str_contains( $readme, "STEP 8. Save the permalinks\n" ) && str_contains( $readme, 'Settings → Permalinks and click Save Changes' ) && str_contains( $readme, '    wp rewrite flush' ) && strpos( $readme, 'STEP 8.' ) > strpos( $readme, 'STEP 7.' ) );
	$step9 = substr( $readme, (int) strpos( $readme, 'STEP 9.' ), (int) strpos( $readme, 'WHAT IS NOT IN THE DOWNLOAD' ) - (int) strpos( $readme, 'STEP 9.' ) );
	$check( 'the last step deletes the Mody Import plugin and the manifest in uploads', str_contains( $step9, '    wp plugin delete mody-import' ) && str_contains( $step9, 'wp-content/uploads/mody-export.json' ) && str_contains( $step9, 'wp-content/uploads/mody-export-skipped.txt' ) && ! str_contains( substr( $readme, 0, (int) strpos( $readme, 'STEP 9.' ) ), '    rm wp-content/uploads' ) );
	$check( 'the README says how many entries the media file left out, and where they are listed', str_contains( $readme, $left_expected . ' files or folders were left out of the media file.' ) && str_contains( $readme, 'wp-content/uploads/mody-export-skipped.txt now' ), (string) $left_expected );
	// A job folder with no media file, or with one that left nothing out: no such line.
	mkdir( $work . '/out-none' );
	$result = Archives::code( $work . '/out-none/code.zip', $manifest, static function (): void {} );
	$none   = $entries( $work . '/out-none/code.zip', $zip_none );
	$text   = (string) $zip_none->getFromName( 'mody-import/README-restore.txt' );
	$check( 'a job folder with no media file: the README has no left-out line', true === $result && ! str_contains( $text, 'left out of the media file' ) );
	$zip_none->close();
	mkdir( $work . '/out-clean' );
	copy( $work . '/out/media-empty.zip', $work . '/out-clean/media.zip' );
	$result = Archives::code( $work . '/out-clean/code.zip', $manifest, static function (): void {} );
	$none   = $entries( $work . '/out-clean/code.zip', $zip_none );
	$text   = (string) $zip_none->getFromName( 'mody-import/README-restore.txt' );
	$check( 'a media file that left nothing out: no left-out line either', true === $result && ! str_contains( $text, 'left out of the media file' ) && ! str_contains( $text, 'wp-content/uploads/mody-export-skipped.txt now' ) );
	$zip_none->close();

	// The shell in the README: syntax, and the search-replace lines as they run, with a stub wp.
	$shell = array();
	foreach ( explode( "\n", $readme ) as $line ) {
		if ( preg_match( '/^    (?:#|wp |unzip |cp |rm |gunzip |NEW_HOME|PREFIX=|for |\( echo)/', $line ) ) {
			$shell[] = substr( $line, 4 );
		}
	}
	file_put_contents( $work . '/readme.sh', implode( "\n", $shell ) . "\n" );
	exec( 'bash -n ' . escapeshellarg( $work . '/readme.sh' ) . ' 2>&1', $syntax, $status );
	$check( 'bash accepts every command in the README', 0 === $status, implode( ' ', $syntax ) );

	/**
	 * Runs README shell lines with a stub wp that prints its arguments, for a new address; returns the search-replace commands as [from, to] pairs, in order.
	 */
	$run_lines = static function ( array $lines, string $new_home ) use ( $work ): array {
		$script = "wp() { printf '%s\\n' \"\$*\"; }\nunzip() { :; }\ncp() { :; }\nrm() { :; }\ngunzip() { :; }\nmysql() { :; }\n" . implode( "\n", $lines ) . "\n";
		file_put_contents( $work . '/readme-run.sh', str_replace( "NEW_HOME='https://your-new-site.example'", "NEW_HOME='" . $new_home . "'", $script ) );
		$printed = array();
		exec( 'bash ' . escapeshellarg( $work . '/readme-run.sh' ) . ' 2>&1', $printed );
		$pairs = array();
		foreach ( $printed as $line ) {
			$part = explode( ' ', $line );
			if ( 'search-replace' === $part[0] && count( $part ) >= 3 ) {
				$pairs[] = array( $part[1], $part[2], substr( $line, strlen( $part[0] . ' ' . $part[1] . ' ' . $part[2] . ' ' ) ) );
			}
		}
		return $pairs;
	};
	/** What the pairs make of a text, one after the other, as wp search-replace would. */
	$rewrite = static function ( array $pairs, string $text ): string {
		foreach ( $pairs as list( $from, $to ) ) {
			$text = str_replace( $from, $to, $text );
		}
		return $text;
	};
	$flags = '--precise --all-tables --skip-columns=guid';
	$pairs = $run_lines( $shell, 'https://new.example/blog' );
	$host  = (string) $manifest['site']['hosts'][0];
	$check( 'run with a new address: every line has the three flags', $pairs && ! array_filter( $pairs, static fn( array $pair ): bool => $flags !== $pair[2] ) );
	$check( '... the home pair is exact', in_array( array( 'http://' . $home_bare, 'https://new.example/blog', $flags ), $pairs, true ) );
	$check( '... the uploads pair under the new address is exact', in_array( array( 'https://new.example/blog' . $uploads_path, 'https://new.example/blog/wp-content/uploads', $flags ), $pairs, true ) );
	$check( '... and its escaped twin', in_array( array( 'https:\\/\\/new.example\\/blog' . str_replace( '/', '\\/', $uploads_path ), 'https:\\/\\/new.example\\/blog\\/wp-content\\/uploads', $flags ), $pairs, true ) );
	foreach ( $net_hosts as $host ) {
		$check( '... the network host ' . $host . ' becomes the new address\'s uploads (the host itself is left alone)', in_array( array( 'https://' . $host . $uploads_path, 'https://new.example/blog/wp-content/uploads', $flags ), $pairs, true ) && ! array_filter( $pairs, static fn( array $pair ): bool => $pair[0] === 'https://' . $host || $pair[0] === 'http://' . $host ) );
	}
	// The order: the home first, then the folders under the new address, then the network's.
	$groups = array();
	foreach ( explode( "\n", $readme ) as $line ) {
		if ( preg_match( '/^    # (\d)\. /', $line, $found ) ) {
			$groups[] = (int) $found[1];
		}
	}
	$check( '... the README\'s groups run 1, 2, 3 in the plain pass and again in the escaped pass', array( 1, 2, 3, 1, 2, 3 ) === $groups, implode( ',', $groups ) );

	// Applied to text, whatever the new address: the review's case is a new address that begins with the old one (https, as the README asks; http on the old host would match the http pair again).
	$old_home = (string) $manifest['site']['home'];
	$net      = $net_hosts ? $net_hosts[0] : $home_bare;
	$cases    = array(
		'https://new.example',
		'https://new.example/blog',
		$old_home . '/blog', // The same domain, in a subfolder.
		'https://' . $net . '/restored', // On the network's own host: the old address of the network's links is not the new home's.
	);
	foreach ( $cases as $new_home ) {
		$pairs = $run_lines( $shell, $new_home );
		$json  = str_replace( '/', '\\/', $new_home );
		$want  = array(
			$old_home . $uploads_path . '/2026/11/x.jpg'  => $new_home . '/wp-content/uploads/2026/11/x.jpg',
			'http://' . $home_bare . $uploads_path . '/y.png' => $new_home . '/wp-content/uploads/y.png',
			$old_home . $content_path . '/themes/theme/a.css' => $new_home . '/wp-content/themes/theme/a.css',
			$old_home . '/about/' => $new_home . '/about/',
			str_replace( '/', '\\/', $old_home . $uploads_path . '/2026/11/x.jpg' ) => $json . '\\/wp-content\\/uploads\\/2026\\/11\\/x.jpg',
			str_replace( '/', '\\/', $old_home ) . '\\/about\\/' => $json . '\\/about\\/',
		);
		foreach ( $net_hosts as $host ) {
			$want[ 'https://' . $host . $uploads_path . '/z.png' ] = $new_home . '/wp-content/uploads/z.png';
			$want[ 'https://' . $host . $content_path . '/themes/theme/b.css' ] = $new_home . '/wp-content/themes/theme/b.css';
		}
		$wrong = array();
		foreach ( $want as $text => $expected_text ) {
			$got = $rewrite( $pairs, $text );
			if ( $got !== $expected_text ) {
				$wrong[] = $text . ' gave ' . $got;
			}
		}
		$check( 'applied to sample text, new address ' . $new_home, ! $wrong, implode( ' | ', array_slice( $wrong, 0, 2 ) ) );
	}
	if ( $net_hosts ) {
		$pairs = $run_lines( $shell, 'https://new.example' );
		$check( 'a link to the network\'s own address (its main site) is not touched', 'https://' . $net_hosts[0] . '/pricing/' === $rewrite( $pairs, 'https://' . $net_hosts[0] . '/pricing/' ) );
	}

	// replace_lines() for other manifests: a site in a folder, unusual values (dropped with a warning), no network host.
	$replace = new ReflectionMethod( Archives::class, 'replace_lines' );
	$lines_of = static function ( array $site ) use ( $replace ): array {
		return array_map( static fn( string $line ): string => substr( $line, 4 ), array_values( array_filter( $replace->invoke( null, $site ), static fn( string $line ): bool => '' !== $line ) ) );
	};
	$prelude = array( "NEW_HOME='https://your-new-site.example'", 'NEW_HOME_JSON="${NEW_HOME//\\//\\\\/}"' );
	$folder_site = array(
		'home'    => 'https://old.example/blog',
		'siteurl' => 'https://old.example/blog',
		'hosts'   => array( 'old.example', 'mody.cloud' ),
		'uploads' => array( 'path' => '/blog/web/uploads/sites/7' ),
		'content' => '/blog/web',
	);
	$pairs = $run_lines( array_merge( $prelude, $lines_of( $folder_site ) ), 'https://new.example' );
	$check( 'a site that lived in a folder: its paths under the new address lose the folder', 'https://new.example/wp-content/uploads/a.jpg' === $rewrite( $pairs, 'https://old.example/blog/web/uploads/sites/7/a.jpg' ) && 'https://new.example/wp-content/uploads/b.jpg' === $rewrite( $pairs, 'https://mody.cloud/blog/web/uploads/sites/7/b.jpg' ) && 'https://new.example/about/' === $rewrite( $pairs, 'https://old.example/blog/about/' ) );
	$odd_site = array(
		'home'    => 'https://old.example',
		'siteurl' => 'https://old.example',
		'hosts'   => array( 'old.example', 'bad host', 'mody.cloud' ),
		'uploads' => array( 'path' => '/web/uploads/sites/7 8' ),
		'content' => '/web',
	);
	$lines = $replace->invoke( null, $odd_site );
	$joined = implode( "\n", $lines );
	$check( 'unusual values are dropped with a warning, the safe pairs stay', str_contains( $joined, '# WARNING: 1 host name(s)' ) && str_contains( $joined, '# WARNING: The uploads folder has an address' ) && ! str_contains( $joined, '7 8' ) && ! str_contains( $joined, 'bad host' ) && str_contains( $joined, '"$NEW_HOME/web/" "$NEW_HOME/wp-content/"' ) );
	$home_only = array(
		'home'    => 'https://old.example',
		'siteurl' => 'https://old.example',
		'hosts'   => array( 'old.example' ),
		'uploads' => array( 'path' => '/web/uploads/sites/7' ),
		'content' => '/web',
	);
	$joined = implode( "\n", $replace->invoke( null, $home_only ) );
	$check( 'a site with no network host has no third group', ! str_contains( $joined, '# 3.' ) && str_contains( $joined, '# 2.' ) );
	$no_home = array(
		'home'    => 'not a url at all',
		'hosts'   => array( 'old.example' ),
		'uploads' => array( 'path' => '/web/uploads/sites/7' ),
		'content' => '/web',
	);
	$joined = implode( "\n", $replace->invoke( null, $no_home ) );
	$check( 'a home address that cannot be written safely is a warning, and no folder pairs under it', str_contains( $joined, '# WARNING: the address of the site is unusual' ) && ! str_contains( $joined, '# 2.' ) );
	$zip->close();

	/*
	 * 4. The payload cache.
	 */
	echo "-- the payload cache\n";
	$signature = static function ( string $dir ): array {
		$sig = array();
		$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$sig[] = substr( $file->getPathname(), strlen( $dir ) ) . '|' . $file->getSize() . '|' . $file->getMTime();
		}
		sort( $sig );
		return $sig;
	};
	$folders = glob( $cache_root . '/*', GLOB_ONLYDIR );
	$check( 'one folder in the cache, with the payload and its info', 1 === count( $folders ) && is_dir( $folders[0] . '/payload/themes/theme' ) && is_file( $folders[0] . '/payload.json' ), basename( (string) ( $folders[0] ?? '' ) ) );
	$sig1 = $signature( $folders[0] );
	sleep( 1 ); // A rebuild would stamp new times.
	$manifest2 = $manifest;
	$manifest2['export_id'] = md5( 'export-archives-test-two' );
	$out2   = $work . '/out/code2.zip';
	$start  = microtime( true );
	$result = Archives::code( $out2, $manifest2, static function (): void {} );
	$second = microtime( true ) - $start;
	$check( 'the second code() returns true', true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
	// Timing only informs: the first export may already find a warm cache (another export just ran), so the folder checks are the proof.
	echo sprintf( "     (second export %.1f s, first %.1f s)\n", $second, $first );
	$folders2 = glob( $cache_root . '/*', GLOB_ONLYDIR );
	$check( 'the cache folder is the same, and its files untouched', $folders2 === $folders && $sig1 === $signature( $folders[0] ) );
	$names2 = $entries( $out2, $zip );
	$check( 'the second zip carries its own manifest', $manifest2['export_id'] === json_decode( (string) $zip->getFromName( 'mody-import/mody-export.json' ), true )['export_id'] );
	$check( '... the same entries as the first', $names2 === $names );
	$zip->close();

	// Old and crashed folders go; a recently used one stays.
	mkdir( $cache_root . '/deadbeefdeadbeef/payload', 0777, true );
	touch( $cache_root . '/deadbeefdeadbeef', time() - 2 * HOUR_IN_SECONDS );
	mkdir( $cache_root . '/cafecafecafecafe.tmp-1234/payload', 0777, true );
	touch( $cache_root . '/cafecafecafecafe.tmp-1234', time() - 2 * HOUR_IN_SECONDS );
	mkdir( $cache_root . '/0123012301230123/payload', 0777, true );
	$result = Archives::code( $work . '/out/code3.zip', $manifest, static function (): void {} );
	$check( 'an old folder and a crashed build\'s leftovers are pruned', true === $result && ! is_dir( $cache_root . '/deadbeefdeadbeef' ) && ! is_dir( $cache_root . '/cafecafecafecafe.tmp-1234' ) );
	$check( '... one used within the hour stays (another builder may be reading it)', is_dir( $cache_root . '/0123012301230123' ) );
	$rrmdir_cache = static function () use ( $cache_root, $rrmdir ): void {
		$rrmdir( $cache_root . '/0123012301230123' );
	};
	$rrmdir_cache();

	// A changed source file: a new payload, and after the hour the old one is pruned.
	$kit_file = WPMU_PLUGIN_DIR . '/mody-platform/export-kit/mody-import/mody-import.php';
	$original = (int) filemtime( $kit_file );
	try {
		touch( $kit_file, $original + 7 );
		clearstatcache( true, $kit_file );
		$result  = Archives::code( $work . '/out/code4.zip', $manifest, static function (): void {} );
		$folders3 = glob( $cache_root . '/*', GLOB_ONLYDIR );
		$fresh    = array_values( array_diff( $folders3, $folders ) );
		$check( 'a source file changed: a new payload is built, the old one kept for an hour', true === $result && 2 === count( $folders3 ) && 1 === count( $fresh ), count( $folders3 ) . ' folders' );
		touch( $folders[0], time() - 2 * HOUR_IN_SECONDS );
		$result   = Archives::code( $work . '/out/code5.zip', $manifest, static function (): void {} );
		$folders4 = glob( $cache_root . '/*', GLOB_ONLYDIR );
		$check( '... and the old one is pruned once it has been unused for an hour', true === $result && $folders4 === $fresh, count( $folders4 ) . ' folder' );
	} finally {
		touch( $kit_file, $original );
	}

	// The key also covers Design/Fonts.php (the faces Portable bakes into theme.json) and this class.
	$key      = new ReflectionMethod( Archives::class, 'cache_key' );
	$base_key = $key->invoke( null );
	foreach ( array(
		'Design/Fonts.php'    => WPMU_PLUGIN_DIR . '/mody-platform/src/Design/Fonts.php',
		'Export/Archives.php' => WPMU_PLUGIN_DIR . '/mody-platform/src/Export/Archives.php',
	) as $label => $file ) {
		$mtime = (int) filemtime( $file );
		try {
			touch( $file, $mtime + 7 );
			clearstatcache( true, $file );
			$changed = $key->invoke( null );
		} finally {
			touch( $file, $mtime );
			clearstatcache( true, $file );
		}
		$check( 'the cache key changes with ' . $label, $changed !== $base_key && $key->invoke( null ) === $base_key );
	}

	// Three builds started together on an empty cache (the first exports after a deploy): all succeed, quietly, and leave one folder.
	$race = $work . '/race';
	mkdir( $race );
	file_put_contents( $race . '/manifest.json', (string) wp_json_encode( $manifest ) );
	file_put_contents(
		$race . '/run.php',
		implode(
			"\n",
			array(
				'<?php',
				'add_filter( "mody_export_root", static fn() => getenv( "RACE_ROOT" ) );',
				'$result = Mody\\Platform\\Export\\Archives::code( getenv( "RACE_OUT" ), json_decode( (string) file_get_contents( getenv( "RACE_MANIFEST" ) ), true ), static function (): void {} );',
				'echo is_wp_error( $result ) ? "ERROR " . $result->get_error_message() : "OK";',
			)
		)
	);
	$procs = array();
	$pipes = array();
	for ( $i = 1; $i <= 3; $i++ ) {
		$procs[ $i ] = proc_open(
			'wp --path=' . escapeshellarg( rtrim( ABSPATH, '/' ) ) . ' --url=' . escapeshellarg( home_url() ) . ' eval-file ' . escapeshellarg( $race . '/run.php' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes[ $i ],
			null,
			array_merge(
				(array) getenv(), // The database settings come from the environment.
				array(
					'RACE_ROOT'     => $race . '/exports',
					'RACE_OUT'      => $race . '/out' . $i . '.zip',
					'RACE_MANIFEST' => $race . '/manifest.json',
				)
			)
		);
	}
	$said  = array();
	$noise = '';
	foreach ( $procs as $i => $proc ) {
		$said[ $i ] = trim( (string) stream_get_contents( $pipes[ $i ][1] ) );
		$noise     .= (string) stream_get_contents( $pipes[ $i ][2] );
		proc_close( $proc );
	}
	$check( 'three simultaneous code() builds on an empty cache all return true', array( 'OK', 'OK', 'OK' ) === array_values( $said ), implode( ' | ', $said ) );
	$check( '... without a warning (a lost rename race is not one)', ! preg_match( '/warning|notice|deprecated|directory not empty/i', $noise . implode( ' ', $said ) ), trim( substr( $noise, 0, 160 ) ) );
	$left_over = glob( $race . '/exports/.code-cache/*' );
	$check( '... leaving one cache folder and three zips with the same entries', 1 === count( (array) $left_over ) && $entries( $race . '/out1.zip', $z1 ) === $entries( $race . '/out2.zip', $z2 ) && $entries( $race . '/out2.zip', $z2 ) === $entries( $race . '/out3.zip', $z3 ) && count( $entries( $race . '/out3.zip', $z3 ) ) > 100, count( (array) $left_over ) . ' folders' );

	// A disk too small for the code file.
	$tight_code = static fn() => 1000;
	add_filter( 'mody_export_free_space', $tight_code );
	$result = Archives::code( $work . '/out/code-nodisk.zip', $manifest, static function (): void {} );
	remove_filter( 'mody_export_free_space', $tight_code );
	$check( 'code() refuses a disk with under 200 MB free', is_wp_error( $result ) && 'mody_export_disk' === $result->get_error_code() && ! is_file( $work . '/out/code-nodisk.zip' ) );
} finally {
	$rrmdir( $fixture_real );
	$rrmdir( $work );
	remove_all_filters( 'mody_export_root' );
	$check( 'the test folders are removed', ! is_dir( $work ) && ! is_dir( $fixture_real ) );
}

printf( "\n%d checks, %d failed.\n", $total, $failures );
exit( $failures ? 1 : 0 );
