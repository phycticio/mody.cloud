<?php
/**
 * Tests the login page in each site's brand (tickets/login-branding.md):
 * the schemes built from a site's palette, the page's CSS and logo, and
 * Settings → Login.
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/login-page-test.php
 *
 * 1. The color helpers.
 * 2. The schemes: at most six, Light always, every color readable where it
 *    is used, for the theme's presets and some awkward palettes.
 * 3. The chosen scheme, its fallback, the option's sanitizing, the CSS (only
 *    theme variables, hex and numbers) and the fonts.
 * 4. The logo: an image on its plate, else the icon, else the name, escaped.
 * 5. Over HTTP, with a throwaway member site (the Plum preset, its own logo)
 *    and its administrator: each login page wears its own site's brand;
 *    Settings → Login lists the schemes and saves one; a preview needs the
 *    right to choose.
 *
 * Everything created is removed at the end. No model call.
 */

use Mody\Platform\Design\Presets;
use Mody\Platform\Design\SiteDesign;
use Mody\Platform\Login\Color;
use Mody\Platform\Login\LoginPage;
use Mody\Platform\Login\Schemes;
use Mody\Platform\Login\SettingsPage;

require_once ABSPATH . 'wp-admin/includes/ms.php';

if ( 'production' === wp_get_environment_type() ) {
	WP_CLI::error( 'Local only: this test creates a site and users.' );
}

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-60s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

$main = get_main_site_id();
// As the other tests do: saving Global Styles without a user runs them through kses.
wp_set_current_user( get_user_by( 'login', get_super_admins()[0] )->ID );

// 1. The color helpers -------------------------------------------------------------

echo "The color helpers\n";
$check( 'hex: #rgb, #rrggbb, #rrggbbaa', '#aabbcc' === Color::hex( '#ABC' ) && '#005f6b' === Color::hex( '#005F6B' ) && '#005f6b' === Color::hex( '#005f6b80' ) );
$check( 'hex: anything else is refused', null === Color::hex( 'var(--x)' ) && null === Color::hex( 'red' ) && null === Color::hex( '#12345' ) && null === Color::hex( '#fff;}body{' ) );
$check( "darken: core's Modern steps (#2145e6, #183ad6)", '#2145e6' === Color::darken( '#3858e9', 0.05 ) && '#183ad6' === Color::darken( '#3858e9', 0.1 ) );
$check( 'contrast: black on white is 21', abs( Color::contrast( '#000000', '#ffffff' ) - 21 ) < 0.01 );
$check( 'visible: a light color darkens on a light page', '#71d3e0' !== Color::visible( '#71d3e0', array( '#f5f5f5' ) ) && Color::contrast( (string) Color::visible( '#71d3e0', array( '#f5f5f5' ) ), '#f5f5f5' ) >= 3 );

// 2. The schemes ---------------------------------------------------------------------

echo "\nThe schemes\n";
$palettes = array();
foreach ( array_keys( Presets::all() ) as $preset ) {
	$palettes[ 'preset ' . $preset ] = (array) Presets::colors( $preset ) + array(
		'base'     => '#f5f5f5',
		'contrast' => '#01110a',
	);
}
$palettes['light primary'] = array( 'primary' => '#71d3e0', 'secondary' => '#333333', 'accent' => '#b50ba4', 'base' => '#f5f5f5', 'contrast' => '#01110a' );
$palettes['dark base']     = array( 'primary' => '#4cc2d1', 'secondary' => '#c9d1d9', 'accent' => '#f0e000', 'base' => '#111418', 'contrast' => '#f5f5f5' );
$palettes['all one color'] = array( 'primary' => '#005f6b', 'secondary' => '#005f6b', 'accent' => '#005f6b', 'base' => '#ffffff', 'contrast' => '#000000' );
$palettes['no accent']     = array( 'primary' => '#005f6b', 'base' => '#ffffff', 'contrast' => '#000000' );
$palettes['white primary'] = array( 'primary' => '#ffffff', 'secondary' => '#eeeeee', 'accent' => '#fafafa', 'base' => '#ffffff', 'contrast' => '#000000' );

$readable = static function ( array $roles ): array {
	$hex  = static fn( string $role ): string => $roles[ $role ]['hex'];
	$bad  = array();
	$need = array(
		array( 'text', 'card', Color::TEXT ),
		array( 'link', 'card', Color::TEXT ),
		array( 'nav', 'page', Color::TEXT ),
		array( 'button_text', 'button', Color::TEXT ),
		array( 'button', 'card', Color::UI ),
	);
	foreach ( $need as list( $fg, $bg, $min ) ) {
		$ratio = Color::contrast( $hex( $fg ), $hex( $bg ) );
		if ( $ratio < $min ) {
			$bad[] = sprintf( '%s on %s %.2f', $fg, $bg, $ratio );
		}
	}
	return $bad;
};
foreach ( $palettes as $name => $palette ) {
	$schemes = Schemes::for_palette( $palette );
	$bad     = array();
	foreach ( $schemes as $slug => $scheme ) {
		if ( 'light' === $slug ) {
			continue; // Light bends instead of failing; checked below for the usual palettes.
		}
		foreach ( $readable( $scheme['roles'] ) as $problem ) {
			$bad[] = "$slug: $problem";
		}
	}
	$signatures = array_map( static fn( array $s ): string => $s['roles']['page']['hex'] . $s['roles']['card']['hex'] . $s['roles']['button']['hex'] . $s['roles']['link']['hex'] . $s['roles']['nav']['hex'], $schemes );
	$check(
		"$name: Light first, at most 6, distinct, readable",
		'light' === array_key_first( $schemes ) && count( $schemes ) <= 6 && count( array_unique( $signatures ) ) === count( $schemes ) && ! $bad,
		implode( ' ', array_keys( $schemes ) ) . ( $bad ? ' | ' . implode( '; ', $bad ) : '' )
	);
}
foreach ( array( 'preset ' . array_key_first( Presets::all() ), 'light primary', 'dark base' ) as $name ) {
	$light = Schemes::for_palette( $palettes[ $name ] )['light']['roles'];
	$check( "$name: Light is readable too", ! $readable( $light ), implode( '; ', $readable( $light ) ) );
}
$check( 'a light primary: Light shifts the button, keeps the hue', '#71d3e0' !== Schemes::for_palette( $palettes['light primary'] )['light']['roles']['button']['hex'] );
$check( 'all one color: duplicates are offered once', count( Schemes::for_palette( $palettes['all one color'] ) ) < 6, implode( ' ', array_keys( Schemes::for_palette( $palettes['all one color'] ) ) ) );
$check( 'no base, contrast or primary: no scheme', array() === Schemes::for_palette( array( 'primary' => '#005f6b', 'contrast' => '#000000' ) ) );

// 3. The chosen scheme, the CSS and the fonts -------------------------------------------

echo "\nThe chosen scheme and the CSS\n";
$saved_choice = get_option( Schemes::OPTION, null );
$check( 'sanitize: a known scheme is kept, anything else is Light', 'bold' === Schemes::sanitize( 'bold' ) && 'light' === Schemes::sanitize( 'neon' ) && 'light' === Schemes::sanitize( array( 'x' ) ) );
$offered = Schemes::for_palette( Schemes::palette() );
$other   = (string) ( array_keys( $offered )[1] ?? 'light' );
update_option( Schemes::OPTION, $other );
$check( 'the chosen scheme is shown', $other === ( Schemes::current()['slug'] ?? '' ), $other );
$missing = (string) ( array_values( array_diff( Schemes::SLUGS, array_keys( $offered ) ) )[0] ?? '' );
if ( '' !== $missing ) {
	update_option( Schemes::OPTION, $missing );
	$check( 'a chosen scheme the palette no longer allows falls back to Light', 'light' === Schemes::current()['slug'], $missing );
}
$check( 'a preview shows another scheme', $other === Schemes::current( $other )['slug'] );
null === $saved_choice ? delete_option( Schemes::OPTION ) : update_option( Schemes::OPTION, $saved_choice );

$css = LoginPage::css( Schemes::current() );
preg_match( '/body\.login\.wp-core-ui\{([^}]*)\}/', $css, $m );
$values = array();
foreach ( array_filter( explode( ';', $m[1] ?? '' ) ) as $declaration ) {
	$values[] = trim( substr( $declaration, strpos( $declaration, ':' ) + 1 ) );
}
$odd = array_filter( $values, static fn( string $v ): bool => ! preg_match( '/^(var\(--wp--preset--(color|font-family)--[a-z0-9-]+\)|#[0-9a-f]{6}|transparent|\d{1,3}, \d{1,3}, \d{1,3})$/', $v ) );
$check( 'the CSS: only theme variables, hex and numbers', count( $values ) > 10 && ! $odd, implode( ' ', $odd ) );
$check( '... the button is the theme\'s variable', str_contains( $css, '--wp-admin-theme-color:var(--wp--preset--color--' ) );
$check( '... balanced braces', substr_count( $css, '{' ) === substr_count( $css, '}' ) );
$check( 'fonts: the theme\'s font presets', 'inter' === LoginPage::font_slug( 'var(--wp--preset--font-family--inter)' ) && 'inter' === LoginPage::font_slug( 'var:preset|font-family|inter' ) && '' === LoginPage::font_slug( 'Comic Sans, sans-serif' ) && '' === LoginPage::font_slug( 'var(--x);}a{' ) );
$check( '... the site\'s text font is set', isset( LoginPage::fonts()['--mody-login-font'] ), wp_json_encode( LoginPage::fonts() ) );

// 4. The logo ----------------------------------------------------------------------

echo "\nThe logo\n";
$unsafe = static fn(): string => 'Mody <script>alert(1)</script> "Cloud"';
add_filter( 'pre_option_blogname', $unsafe );
add_filter( 'pre_option_site_logo', '__return_zero' );
add_filter( 'theme_mod_custom_logo', '__return_zero' );
add_filter( 'get_site_icon_url', '__return_empty_string' );
$html = LoginPage::logo_html();
$check( 'no logo, no icon: the name, escaped', str_starts_with( $html, '<span class="mody-login-name">' ) && ! str_contains( $html, '<script' ), $html );
remove_filter( 'get_site_icon_url', '__return_empty_string' );
$icon = static fn(): string => 'https://mody.local/icon.png?a="b"';
add_filter( 'get_site_icon_url', $icon );
$html = LoginPage::logo_html();
$check( 'an icon: on its plate, alt the name, escaped', str_contains( $html, 'mody-login-plate' ) && str_contains( $html, 'mody-login-logo--icon' ) && str_contains( $html, 'alt="Mody &lt;script&gt;' ) && ! str_contains( $html, '"b"' ), $html );
remove_filter( 'get_site_icon_url', $icon );
remove_filter( 'pre_option_blogname', $unsafe );
remove_filter( 'pre_option_site_logo', '__return_zero' );
remove_filter( 'theme_mod_custom_logo', '__return_zero' );

// 5. Over HTTP -------------------------------------------------------------------------

echo "\nOver HTTP\n";
$suffix  = strtolower( wp_generate_password( 6, false ) );
$created = array( 'sites' => array(), 'users' => array(), 'posts' => array() );
register_shutdown_function(
	static function () use ( &$created ): void {
		foreach ( $created['sites'] as $site_id ) {
			wp_delete_site( $site_id );
		}
		foreach ( $created['users'] as $user_id ) {
			wpmu_delete_user( $user_id );
		}
		foreach ( $created['posts'] as list( $site_id, $post_id ) ) {
			switch_to_blog( $site_id );
			wp_delete_attachment( $post_id, true );
			restore_current_blog();
		}
	}
);

// This container reaches only the main host; the Host header picks the site.
$get = static function ( int $site, string $path, array $cookies = array(), array $post = array() ): array {
	$args     = array(
		'headers'     => array( 'Host' => get_site( $site )->domain ),
		'cookies'     => $cookies,
		'timeout'     => 30,
		'sslverify'   => false,
		'redirection' => 0,
	);
	$url      = 'https://' . get_site( get_main_site_id() )->domain . $path;
	$response = $post ? wp_remote_post( $url, $args + array( 'body' => $post ) ) : wp_remote_get( $url, $args );
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $response ),
		'body'     => (string) wp_remote_retrieve_body( $response ),
		'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
	);
};
$cookie = static function ( int $user_id ): array {
	$expiration = time() + HOUR_IN_SECONDS;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	return array(
		SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token ),
		LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ),
	);
};

$member = wp_insert_user(
	array(
		'user_login' => 'modylogin' . $suffix,
		'user_email' => 'modylogin' . $suffix . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	)
);
$created['users'][] = $member;
$domain             = 'modylogin' . $suffix . '.' . get_network()->domain;
$site               = wp_insert_site(
	array(
		'domain'  => $domain,
		'path'    => '/',
		'title'   => 'Plum Bakery',
		'user_id' => $member,
		'options' => array(
			'home'       => 'https://' . $domain,
			'siteurl'    => 'https://' . $domain,
			'template'   => 'theme',
			'stylesheet' => 'theme',
		),
	)
);
if ( is_wp_error( $site ) ) {
	$check( 'a member site to test with', false, $site->get_error_message() );
} else {
	$created['sites'][] = $site;
	switch_to_blog( $site );
	wp_clean_theme_json_cache();
	$applied = SiteDesign::apply_preset( 'plum' );
	$logo    = wp_insert_attachment(
		array(
			'post_title'     => 'Plum logo',
			'post_mime_type' => 'image/png',
			'post_status'    => 'inherit',
		),
		'plum-logo.png'
	);
	update_post_meta( $logo, '_wp_attached_file', 'plum-logo.png' );
	wp_update_attachment_metadata( $logo, array( 'width' => 600, 'height' => 200, 'file' => 'plum-logo.png' ) );
	update_option( 'site_logo', $logo );
	$created['posts'][] = array( $site, $logo );
	$plum = Schemes::palette()['primary'] ?? '';
	wp_clean_theme_json_cache();
	restore_current_blog();
	wp_clean_theme_json_cache();
	$check( 'a member site with the Plum preset and a logo', true === $applied && '' !== $plum, $plum );

	$main_primary = Schemes::palette()['primary'] ?? '';
	$out          = $get( $main, '/wp-login.php' );
	$check( 'main site: its own brand', 200 === $out['code'] && str_contains( $out['body'], '--wp--preset--color--primary: ' . $main_primary ) && str_contains( $out['body'], 'mody-login--' ), $main_primary );
	$out = $get( $site, '/wp-login.php' );
	$check( 'member site: its own palette (Plum)', 200 === $out['code'] && str_contains( $out['body'], '--wp--preset--color--primary: ' . $plum ), $plum );
	$check( '... its own logo, linking to its home', str_contains( $out['body'], 'plum-logo.png' ) && str_contains( $out['body'], 'alt="Plum Bakery"' ) && str_contains( $out['body'], '<a href="https://' . $domain . '/">' ) );
	$check( '... its fonts, served from here', str_contains( $out['body'], 'wp-fonts-local' ) && ! preg_match( '#fonts\.(googleapis|gstatic)\.com#', $out['body'] ) );
	$check( '... the login stylesheet', str_contains( $out['body'], 'assets/login.css' ) );
	$out = $get( $site, '/wp-login.php?action=lostpassword' );
	$check( '... on lost password too', 200 === $out['code'] && str_contains( $out['body'], 'plum-logo.png' ) );

	// The member manages the site: Settings → Login.
	add_user_to_blog( $site, $member, 'administrator' );
	$jar  = $cookie( $member );
	$page = '/wp-admin/options-general.php?page=' . SettingsPage::SLUG;
	$out  = $get( $site, $page, $jar );
	$check( 'Settings → Login for the site\'s administrator', 200 === $out['code'] && str_contains( $out['body'], 'mody-login-settings__tabs' ) && str_contains( $out['body'], 'aria-current="page"' ), (string) $out['code'] );
	preg_match_all( '/name="mody_login_scheme" value="([a-z]+)"/', $out['body'], $radios );
	switch_to_blog( $site );
	wp_clean_theme_json_cache();
	$offered = array_keys( Schemes::for_palette( Schemes::palette() ) );
	restore_current_blog();
	wp_clean_theme_json_cache();
	$check( '... one card per scheme the palette allows', $radios[1] === $offered, implode( ' ', $radios[1] ) );
	$check( '... previews with the site\'s logo, and its colors', substr_count( $out['body'], 'plum-logo.png' ) >= count( $offered ) && str_contains( $out['body'], '--wp--preset--color--primary: ' . $plum ) );
	$check( '... links to the Identity and Styles screens', str_contains( $out['body'], 'p=%2Fidentity' ) && str_contains( $out['body'], 'p=%2Fstyles' ) );

	$choice = end( $offered );
	preg_match( '/name="_wpnonce" value="([^"]+)"/', $out['body'], $nonce );
	$saved = $get(
		$site,
		'/wp-admin/options.php',
		$jar,
		array(
			'option_page'           => SettingsPage::GROUP,
			'action'                => 'update',
			'_wpnonce'              => $nonce[1] ?? '',
			'_wp_http_referer'      => $page,
			Schemes::OPTION         => $choice,
		)
	);
	$fresh = static function ( int $site_id ): string {
		wp_cache_flush_runtime(); // The web request saved it; this process may hold the old value.
		return (string) get_blog_option( $site_id, Schemes::OPTION );
	};
	$check( 'saving a scheme', 302 === $saved['code'] && $choice === $fresh( $site ), $choice . ' ' . $saved['code'] );
	$out = $get( $site, '/wp-login.php' );
	$check( '... the login page shows it', str_contains( $out['body'], 'mody-login--' . $choice ) );
	$get(
		$site,
		'/wp-admin/options.php',
		$jar,
		array(
			'option_page'      => SettingsPage::GROUP,
			'action'           => 'update',
			'_wpnonce'         => $nonce[1] ?? '',
			'_wp_http_referer' => $page,
			Schemes::OPTION    => 'neon',
		)
	);
	$check( '... an unknown scheme is saved as Light', 'light' === $fresh( $site ), $fresh( $site ) );

	$preview = reset( $offered ) === $choice ? next( $offered ) : reset( $offered );
	$preview = 'light' === $preview ? (string) end( $offered ) : (string) $preview;
	$out     = $get( $site, '/wp-login.php?' . LoginPage::PREVIEW_ARG . '=' . $preview );
	$check( 'a preview link is ignored for visitors', ! str_contains( $out['body'], 'mody-login--' . $preview ) || 'light' === $preview, $preview );
	$out = $get( $site, '/wp-login.php?' . LoginPage::PREVIEW_ARG . '=' . $preview, $jar );
	$check( '... and shown to the site\'s administrator', str_contains( $out['body'], 'mody-login--' . $preview ), $preview );

	// Someone who cannot manage the site sees neither.
	$author = wp_insert_user(
		array(
			'user_login' => 'modyloginauthor' . $suffix,
			'user_email' => 'modyloginauthor' . $suffix . '@mody.local',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => '',
		)
	);
	$created['users'][] = $author;
	add_user_to_blog( $site, $author, 'author' );
	$out = $get( $site, $page, $cookie( $author ) );
	$check( 'an author cannot open Settings → Login', 200 !== $out['code'] || ! str_contains( $out['body'], 'mody-login-settings__tabs' ), (string) $out['code'] );
}

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
