<?php
/**
 * Tests WordPress's own translations (tickets/spanish-by-default.md, part 2):
 * the packs `bin/languages` puts in app/web/languages/.
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/language-packs-test.php
 *
 * 1. Installed: WordPress lists Spanish and Catalan as available languages.
 * 2. Core: wp-admin's and the front end's words switch with the locale, the
 *    network admin's too, and the editor's JavaScript has its translations.
 * 3. Plugins: the `ai` plugin in Spanish and Antispam Bee in both.
 * 4. Nothing else: only translation files (no .po or .mo copies), and every
 *    PHP file is a plain array.
 */

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-74s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

$dir = WP_LANG_DIR;

// 1. Installed.
$available = get_available_languages();
$check( 'Spanish is installed', in_array( 'es_ES', $available, true ), implode( ', ', $available ) );
$check( 'Catalan is installed', in_array( 'ca', $available, true ) );

// 2. Core.
$words = static function ( string $locale, callable $read ) {
	switch_to_locale( $locale );
	$out = $read();
	restore_previous_locale();
	return $out;
};
$check( 'Spanish: wp-admin (Dashboard)', 'Escritorio' === $words( 'es_ES', static fn() => __( 'Dashboard' ) ) );
$check( 'Catalan: wp-admin (Dashboard)', 'Tauler' === $words( 'ca', static fn() => __( 'Dashboard' ) ) );
$check( 'Spanish: the front end (Read more)', 'Read more' !== $words( 'es_ES', static fn() => __( 'Read more' ) ) );
$check( 'Spanish: the network admin file is there', file_exists( "$dir/admin-network-es_ES.l10n.php" ) );
$check( 'Catalan: the network admin file is there', file_exists( "$dir/admin-network-ca.l10n.php" ) );
$check( 'Spanish: the editor\'s JavaScript translations', count( glob( "$dir/es_ES-*.json" ) ) > 20 );
$check( 'Catalan: the editor\'s JavaScript translations', count( glob( "$dir/ca-*.json" ) ) > 20 );

// 3. Plugins.
$check( 'Spanish: the ai plugin', file_exists( "$dir/plugins/ai-es_ES.l10n.php" ) );
$check( 'Spanish: Antispam Bee', file_exists( "$dir/plugins/antispam-bee-es_ES.l10n.php" ) );
$check( 'Catalan: Antispam Bee', file_exists( "$dir/plugins/antispam-bee-ca.l10n.php" ) );

// 4. Nothing else.
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
$other = array();
$code  = array();
foreach ( $files as $file ) {
	$name = $file->getFilename();
	if ( str_ends_with( $name, '.json' ) ) {
		continue;
	}
	if ( ! str_ends_with( $name, '.l10n.php' ) ) {
		$other[] = $name;
		continue;
	}
	if ( ! preg_match( '/^<\?php\s+return\s+\[/', (string) file_get_contents( $file->getPathname() ) ) ) {
		$code[] = $name;
	}
}
$check( 'Only translation files', array() === $other, implode( ', ', array_slice( $other, 0, 5 ) ) );
$check( 'Every PHP file is a plain array', array() === $code, implode( ', ', $code ) );

echo $failures ? "\n$failures failed.\n" : "\nAll passed.\n";
if ( $failures ) {
	exit( 1 );
}
