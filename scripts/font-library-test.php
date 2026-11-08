<?php
/**
 * Tests the Font Library: the Mody fonts collection and the theme's Inter (ticket M2-07).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/font-library-test.php
 *
 * 1. The collections: Mody fonts is registered and Google Fonts is not; every
 *    family has its files, license and source commit (fonts/sources.json).
 * 2. The URLs: every font file's URL is on the site's own host and is served
 *    as font/woff2.
 * 3. The theme's Inter has its font faces, and pages print them.
 * 4. On a member site, as a member over HTTP: the Font Library lists the
 *    collection, and a font installs from it the way the editor does it
 *    (the family, then a face with its file). It is removed afterwards.
 * 5. A preset applied afterwards keeps the installed font active.
 *
 * Everything created is removed, and the member site's Global Styles restored.
 * No model call.
 */

use Mody\Platform\Design\Fonts;
use Mody\Platform\Design\Presets;
use Mody\Platform\Design\SiteDesign;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-48s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};
$admins = get_super_admins();
$admin  = get_user_by( 'login', $admins[0] )->ID;
wp_set_current_user( $admin );
$fonts_dir = dirname( Fonts::path( 'inter', 'x' ), 2 );

// 1. The collections -----------------------------------------------------------------------

echo "Collections\n";
$collections = WP_Font_Library::get_instance()->get_font_collections();
$check( 'Mody fonts is registered, Google Fonts is not', isset( $collections[ Fonts::COLLECTION ] ) && ! isset( $collections['google-fonts'] ), implode( ', ', array_keys( $collections ) ) );
$data     = $collections[ Fonts::COLLECTION ]->get_data();
$listed   = is_wp_error( $data ) ? array() : array_column( array_column( $data['font_families'], 'font_family_settings' ), 'slug' );
$check( '...with every family', array_keys( Fonts::FAMILIES ) === $listed, count( $listed ) . ' families' );
$sources = json_decode( (string) file_get_contents( $fonts_dir . '/sources.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$missing = array();
$bytes   = 0;
foreach ( Fonts::FAMILIES as $slug => $family ) {
	$from = array_column( (array) ( $sources[ $slug ]['files'] ?? array() ), 'bytes', 'file' );
	if ( ! is_file( Fonts::path( $slug, 'LICENSE.txt' ) ) || ! str_contains( (string) file_get_contents( Fonts::path( $slug, 'LICENSE.txt' ) ), 'SIL OPEN FONT LICENSE' ) || 40 !== strlen( (string) ( $sources[ $slug ]['commit'] ?? '' ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$missing[] = $slug . ': license or source';
	}
	foreach ( $family['faces'] as list( $file ) ) {
		$path = Fonts::path( $slug, $file );
		if ( ! is_file( $path ) || 'wOF2' !== (string) file_get_contents( $path, false, null, 0, 4 ) || ( $from[ $file ] ?? -1 ) !== filesize( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$missing[] = $slug . '/' . $file;
		}
		$bytes += is_file( $path ) ? filesize( $path ) : 0;
	}
}
$check( 'files, OFL licenses and sources', ! $missing, $missing ? implode( ', ', $missing ) : round( $bytes / 1048576, 1 ) . ' MB' );
$categories = array();
foreach ( $data['font_families'] as $family ) {
	$categories = array_merge( $categories, $family['categories'] );
}
$check( '...each in one of the four categories', ! array_diff( $categories, array_keys( Fonts::CATEGORIES ) ) && ! array_diff( array_keys( Fonts::CATEGORIES ), $categories ) );

// 2. The URLs ------------------------------------------------------------------------------

echo "\nURLs\n";
$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$wrong = array();
foreach ( $data['font_families'] as $family ) {
	foreach ( $family['font_family_settings']['fontFace'] as $face ) {
		if ( wp_parse_url( $face['src'], PHP_URL_HOST ) !== $host ) {
			$wrong[] = $face['src'];
		}
	}
}
$check( 'every file on the site\'s own host', ! $wrong, $wrong ? $wrong[0] : $host );
// This container reaches only the main host; the Host header picks the site.
$fetch = static function ( string $url, string $site_host ): array {
	$response = wp_remote_get(
		'https://' . get_site( get_main_site_id() )->domain . wp_parse_url( $url, PHP_URL_PATH ),
		array(
			'headers'   => array( 'Host' => $site_host ),
			'sslverify' => false,
			'timeout'   => 30,
		)
	);
	return array(
		'code' => (int) wp_remote_retrieve_response_code( $response ),
		'type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
		'body' => (string) wp_remote_retrieve_body( $response ),
	);
};
$served = array();
foreach ( Fonts::FAMILIES as $slug => $family ) {
	$file = $family['faces'][0][0];
	$out  = $fetch( Fonts::url( $slug, $file ), $host );
	if ( 200 !== $out['code'] || ! str_starts_with( $out['type'], 'font/woff2' ) || strlen( $out['body'] ) !== filesize( Fonts::path( $slug, $file ) ) ) {
		$served[] = "$slug {$out['code']} {$out['type']}";
	}
}
$check( 'served as font/woff2', ! $served, $served ? implode( ', ', $served ) : count( Fonts::FAMILIES ) . ' files fetched' );

// 3. The theme's Inter ---------------------------------------------------------------------------

echo "\nThe theme's Inter\n";
wp_clean_theme_json_cache();
$theme_fonts = array_column( (array) wp_get_global_settings( array( 'typography', 'fontFamilies', 'theme' ) ), null, 'slug' );
$check( 'Inter has its font faces', Fonts::faces( 'inter' ) === ( $theme_fonts['inter']['fontFace'] ?? null ), count( $theme_fonts['inter']['fontFace'] ?? array() ) . ' faces' );
$check( '...theme.json is unchanged', ! str_contains( (string) file_get_contents( get_theme_root( 'theme' ) . '/theme/theme.json' ), 'fontFace' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$bad = array();
foreach ( glob( get_theme_root( 'theme' ) . '/theme/styles/*.json' ) as $file ) {
	$style = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( isset( $style['settings']['typography']['fontFamilies'] ) ) {
		$bad[] = basename( $file );
	}
}
$check( '...no style replaces the theme\'s font families', ! $bad, implode( ', ', $bad ) );
ob_start();
wp_print_font_faces();
$css = (string) ob_get_clean();
$check( 'pages print its @font-face rules', 2 <= substr_count( $css, 'font-family:Inter;' ) && str_contains( $css, Fonts::url( 'inter', 'InterVariable.woff2' ) ) );
$home = $fetch( home_url( '/' ), $host );
$check( '...the home page loads them, nothing from outside', 200 === $home['code'] && str_contains( $home['body'], '/fonts/inter/InterVariable.woff2' ) && ! preg_match( '#fonts\.(googleapis|gstatic)\.com|fonts\.wp\.com#', $home['body'] ), (string) $home['code'] );

// 4. Installing a font on a member site ------------------------------------------------------------

$others = get_sites(
	array(
		'site__not_in' => array( get_main_site_id() ),
		'fields'       => 'ids',
		'number'       => 1,
	)
);
if ( ! $others ) {
	echo "\nNo member site: sections 4 and 5 skipped.\n";
	echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
	return;
}
$site      = (int) $others[0];
$site_host = get_site( $site )->domain;
echo "\nInstalling a font (member site $site, as a member)\n";
$suffix = strtolower( wp_generate_password( 6, false ) );
$member = wp_insert_user(
	array(
		'user_login' => 'modyfont' . $suffix,
		'user_email' => 'modyfont' . $suffix . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	)
);
add_user_to_blog( $site, $member, 'administrator' );
register_shutdown_function( static fn() => wpmu_delete_user( $member ) );

// Installing a font changes the site's Global Styles (Spectra activates every installed
// family there), so they are saved first and restored at the end.
switch_to_blog( $site );
wp_clean_theme_json_cache();
$styles_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
$saved     = get_post( $styles_id )->post_content;
$revisions = wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) );
register_shutdown_function(
	static function () use ( $site, $styles_id, $saved, $revisions ): void {
		switch_to_blog( $site );
		wp_update_post( wp_slash( array( 'ID' => $styles_id, 'post_content' => $saved ) ) );
		foreach ( array_diff( wp_get_post_revisions( $styles_id, array( 'fields' => 'ids' ) ), $revisions ) as $revision ) {
			wp_delete_post_revision( $revision );
		}
		wp_clean_theme_json_cache();
		restore_current_blog();
	}
);
restore_current_blog();

$expiration = time() + 300;
$token      = WP_Session_Tokens::get_instance( $member )->create( $expiration );
register_shutdown_function( static fn() => WP_Session_Tokens::get_instance( $member )->destroy( $token ) );
$cookies = array(
	SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $member, $expiration, 'secure_auth', $token ),
	LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $member, $expiration, 'logged_in', $token ),
);
$_COOKIE[ LOGGED_IN_COOKIE ] = $cookies[ LOGGED_IN_COOKIE ];
wp_set_current_user( $member );
$nonce = wp_create_nonce( 'wp_rest' );
wp_set_current_user( $admin );
$rest = (string) wp_parse_url( get_rest_url( $site ), PHP_URL_PATH );

// The REST calls the Font Library makes. A body with files is sent as multipart/form-data.
$call = static function ( string $method, string $route, array $fields = array(), array $files = array() ) use ( $rest, $site_host, $cookies, $nonce ): array {
	$headers = array(
		'Host'       => $site_host,
		'X-WP-Nonce' => $nonce,
	);
	$body    = null;
	if ( $fields || $files ) {
		$boundary                = 'mody' . wp_generate_password( 16, false );
		$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
		$body                    = '';
		foreach ( $fields as $name => $value ) {
			$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
		}
		foreach ( $files as $name => $path ) {
			$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"; filename=\"" . basename( $path ) . "\"\r\nContent-Type: font/woff2\r\n\r\n" . file_get_contents( $path ) . "\r\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		$body .= "--$boundary--\r\n";
	}
	$response = wp_remote_request(
		'https://' . get_site( get_main_site_id() )->domain . $rest . $route,
		array(
			'method'    => $method,
			'headers'   => $headers,
			'cookies'   => $cookies,
			'body'      => $body,
			'sslverify' => false,
			'timeout'   => 30,
		)
	);
	return array(
		'code' => (int) wp_remote_retrieve_response_code( $response ),
		'data' => json_decode( (string) wp_remote_retrieve_body( $response ), true ),
	);
};

$out   = $call( 'GET', 'wp/v2/font-collections' );
$slugs = array_column( (array) $out['data'], 'slug' );
$check( 'the Font Library lists the Mody fonts only', 200 === $out['code'] && array( Fonts::COLLECTION ) === $slugs, $out['code'] . ' ' . implode( ', ', $slugs ) );
$out  = $call( 'GET', 'wp/v2/font-collections/' . Fonts::COLLECTION );
$lora = array_values( array_filter( (array) ( $out['data']['font_families'] ?? array() ), static fn( $f ) => 'lora' === ( $f['font_family_settings']['slug'] ?? '' ) ) )[0]['font_family_settings'] ?? array();
$check( '...its URLs on the member site\'s host', 200 === $out['code'] && $lora && wp_parse_url( $lora['fontFace'][0]['src'], PHP_URL_HOST ) === $site_host, (string) ( $lora['fontFace'][0]['src'] ?? $out['code'] ) );

// As the editor installs it: the family, then each face with its downloaded file.
$family = $call(
	'POST',
	'wp/v2/font-families',
	array(
		'font_family_settings' => wp_json_encode(
			array(
				'name'       => $lora['name'] ?? 'Lora',
				'slug'       => 'lora',
				'fontFamily' => $lora['fontFamily'] ?? 'Lora, Georgia, serif',
			)
		),
	)
);
$family_id = (int) ( $family['data']['id'] ?? 0 );
register_shutdown_function(
	static function () use ( $site, $family_id ): void {
		if ( ! $family_id ) {
			return;
		}
		switch_to_blog( $site );
		foreach ( get_children( array( 'post_parent' => $family_id, 'post_type' => 'wp_font_face' ) ) as $face ) {
			wp_delete_post( $face->ID, true ); // Deletes its file too (_wp_after_delete_font_face).
		}
		wp_delete_post( $family_id, true );
		restore_current_blog();
	}
);
$check( 'the family is created', 201 === $family['code'] && $family_id > 0, $family['code'] . ' ' . ( $family['data']['code'] ?? '' ) );
$face_path = Fonts::path( 'lora', 'Lora-Variable.woff2' );
$face      = $call(
	'POST',
	"wp/v2/font-families/$family_id/font-faces",
	array(
		'font_face_settings' => wp_json_encode(
			array(
				'fontFamily' => 'Lora',
				'fontStyle'  => 'normal',
				'fontWeight' => '400 700',
				'src'        => 'file-0',
			)
		),
	),
	array( 'file-0' => $face_path )
);
$src = (string) ( $face['data']['font_face_settings']['src'] ?? '' );
$check( 'the face uploads, into the site\'s font directory', 201 === $face['code'] && str_contains( $src, '/fonts/' ) && ! str_contains( $src, 'mu-plugins' ), $face['code'] . ' ' . ( $face['data']['code'] ?? $src ) );
switch_to_blog( $site );
$stored = wp_get_font_dir()['path'] . '/' . basename( $src );
$check( '...the same file, stored on the site', '' !== $src && is_file( $stored ) && filesize( $stored ) === filesize( $face_path ), basename( $src ) );
restore_current_blog();

// 5. A preset keeps an installed font active -----------------------------------------------------

echo "\nA preset afterwards (member site $site)\n";
switch_to_blog( $site );
// Activated as the Font Library does it (Spectra may have done it already): the family under
// the site's custom font families.
$active = array(
	'name'       => 'Lora',
	'slug'       => 'lora',
	'fontFamily' => Fonts::stack( 'lora' ),
	'fontFace'   => array(
		array(
			'fontFamily' => 'Lora',
			'fontStyle'  => 'normal',
			'fontWeight' => '400 700',
			'src'        => $src,
		),
	),
);
wp_clean_theme_json_cache();
$config = json_decode( get_post( $styles_id )->post_content, true );
$config = is_array( $config ) ? $config : array( 'version' => WP_Theme_JSON::LATEST_SCHEMA, 'isGlobalStylesUserThemeJSON' => true );
$others = array_filter( (array) ( $config['settings']['typography']['fontFamilies']['custom'] ?? array() ), static fn( $f ) => 'lora' !== ( $f['slug'] ?? '' ) );
$config['settings']['typography']['fontFamilies']['custom'] = array_merge( array_values( $others ), array( $active ) );
wp_update_post( wp_slash( array( 'ID' => $styles_id, 'post_content' => wp_json_encode( $config ) ) ) );
wp_clean_theme_json_cache();
// Spectra adds its own font (Cardo, served locally) to the custom families on every site.
$custom = static fn(): array => array_column( (array) wp_get_global_settings( array( 'typography', 'fontFamilies', 'custom' ) ), 'slug' );
$check( 'Lora installed and active', in_array( 'lora', $custom(), true ), implode( ', ', $custom() ) );
$applied = SiteDesign::apply_preset( 'ocean' );
$check( 'Ocean applied, Lora still active', true === $applied && in_array( 'lora', $custom(), true ) && Presets::colors( 'ocean' ) == SiteDesign::colors(), is_wp_error( $applied ) ? $applied->get_error_message() : implode( ', ', $custom() ) ); // phpcs:ignore Universal.Operators.StrictComparisons
$check( '...and Ocean is the active style', 'ocean' === SiteDesign::active_preset() );
$applied = SiteDesign::apply_preset( Presets::DEFAULT );
$check( 'the default style, Lora still active', true === $applied && in_array( 'lora', $custom(), true ) && Presets::DEFAULT === SiteDesign::active_preset() );
restore_current_blog();

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
