<?php
/**
 * The PHP checks of scripts/standalone-test.sh, run with `wp eval-file` on its
 * plain WordPress (single site, or the main site of a plain multisite) with
 * the nine Mody plugins installed from their zips and activated
 * (tickets/product-pages-bari-and-plugins.md, part 3). Prints "ok …" and
 * "FAIL …".
 *
 * @package Mody\Package
 */

$check = static function ( string $label, bool $ok, $detail = '' ): void {
	echo ( $ok ? 'ok ' : 'FAIL ' ) . $label . ( ! $ok && '' !== $detail ? ' (' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) . ')' : '' ) . "\n";
};

require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user( 1 );
$multi = is_multisite();

// 1. Installed from the zips, active, and nothing of Mody Cloud around them.
$plugins = get_plugins();
foreach ( array( 'security', 'analytics', 'dashboard', 'images', 'seo', 'profiles', 'fonts', 'patterns', 'palette' ) as $slug ) {
	$file = "mody-$slug/mody-$slug.php";
	$check( "mody-$slug: installed as a normal plugin, " . ( $multi ? 'network active' : 'active' ), isset( $plugins[ $file ] ) && ( $multi ? is_plugin_active_for_network( $file ) : is_plugin_active( $file ) ) );
}
$check( 'no MU plugin and no Mody Updates: a plain WordPress', array() === get_mu_plugins() && ! class_exists( '\Mody\Updates\Updates' ) );

// 2. Mody Security.
$secret = \Mody\Security\Secrets::get( 'altcha-key' );
$check( 'Security: its secrets are made and read back', (bool) preg_match( '/^[0-9a-f]{64}$/', $secret ) && \Mody\Security\Secrets::get( 'altcha-key' ) === $secret );
$check( 'Security: the settings page is for ' . ( $multi ? 'network admins' : 'administrators' ), ( $multi ? 'manage_network_options' : 'manage_options' ) === \Mody\Security\SecurityPage::capability() && current_user_can( \Mody\Security\SecurityPage::capability() ) );
$check( 'Security: the administrator must use two-step login (the default)', \Mody\Security\TwoFactor::required( wp_get_current_user() ) );
$check( 'Security: the file check knows this is not Mody Cloud\'s layout', ! \Mody\Security\FileCheck::platform() && untrailingslashit( ABSPATH ) === \Mody\Security\FileCheck::root() );
$roots = \Mody\Security\FileCheck::roots();
$check( '  its folders: wp-admin, wp-includes, the PHP files, plugins, mu-plugins, themes', in_array( 'wp-admin', $roots, true ) && in_array( 'wp-includes', $roots, true ) && in_array( 'wp-login.php', $roots, true ) && in_array( 'wp-config.php', $roots, true ) && in_array( 'wp-content/plugins', $roots, true ) && in_array( 'wp-content/themes', $roots, true ), $roots );
$first = \Mody\Security\FileCheck::run();
$check( '  without a list: says so, and finds no script in uploads', false === $first['manifest'] && 0 === $first['uploads_count'], $first );
$listed = \Mody\Security\FileCheck::write_manifest();
$second = \Mody\Security\FileCheck::run();
$check( "  files-manifest lists the code ($listed files), kept in the database", $listed > 1000 && is_array( get_site_option( \Mody\Security\FileCheck::MANIFEST_OPTION ) ) && ! is_file( ABSPATH . \Mody\Security\FileCheck::MANIFEST ) && ! is_file( dirname( ABSPATH ) . '/' . \Mody\Security\FileCheck::MANIFEST ) );
$check( '  then nothing differs', $second['manifest'] && $second['checked'] === $listed && ! \Mody\Security\FileCheck::found( $second ), array_intersect_key( $second, array_flip( array( 'checked', 'changed', 'added', 'missing', 'uploads' ) ) ) );
$plugin_file = WP_PLUGIN_DIR . '/mody-seo/mody-seo.php';
$before      = (string) file_get_contents( $plugin_file );
file_put_contents( $plugin_file, $before . "\n// changed\n" );
file_put_contents( WP_CONTENT_DIR . '/uploads/shell.php', '<?php' );
$third = \Mody\Security\FileCheck::run();
file_put_contents( $plugin_file, $before );
unlink( WP_CONTENT_DIR . '/uploads/shell.php' );
$check( '  a changed plugin file and a script in uploads are found', array( 'wp-content/plugins/mody-seo/mody-seo.php' ) === $third['changed'] && array( 'wp-content/uploads/shell.php' ) === $third['uploads'], $third );
if ( ! $multi ) {
	$check( '  the list is not autoloaded', 'off' === $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", \Mody\Security\FileCheck::MANIFEST_OPTION ) ) || 'no' === $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", \Mody\Security\FileCheck::MANIFEST_OPTION ) ) );
}
list( $altcha_url, $altcha_asset ) = \Mody\Security\Altcha::built( 'altcha' );
$check( 'Security: ALTCHA\'s widget from the plugin\'s own dist/', plugins_url( 'dist/altcha.js', WP_PLUGIN_DIR . '/mody-security/mody-security.php' ) === $altcha_url && is_readable( $altcha_asset ), $altcha_url );

// 3. Mody Analytics and Mody Dashboard.
global $wpdb;
$check( 'Analytics: its tables exist', $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', \Mody\Analytics\Store::views() ) ) === \Mody\Analytics\Store::views() );
$check( 'Analytics: no location file, no location, no error', null === \Mody\Analytics\Geo\Locator::reader() );
$routes = rest_get_server()->get_routes();
$check( 'Analytics: the beacon\'s route', isset( $routes['/mody-analytics/v1/view'] ) );
$check( 'Dashboard: the board\'s routes (Mody Analytics is there)', (bool) preg_grep( '#^/mody-platform/v1/analytics/board#', array_keys( $routes ) ) );

// 4. The others.
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
$tmp   = wp_tempnam( 'p.jpg' );
$image = imagecreatetruecolor( 3000, 2000 );
imagejpeg( $image, $tmp, 95 );
$id = media_handle_sideload(
	array(
		'name'     => 'photo.jpg',
		'tmp_name' => $tmp,
	),
	0
);
$meta = is_wp_error( $id ) ? array() : wp_get_attachment_metadata( $id );
$check( 'Images: a 3000-pixel JPEG becomes a 2560-pixel WebP', ! is_wp_error( $id ) && 'image/webp' === get_post_mime_type( $id ) && 2560 === (int) ( $meta['width'] ?? 0 ), is_wp_error( $id ) ? $id->get_error_message() : $meta );
if ( ! is_wp_error( $id ) ) {
	wp_delete_attachment( $id, true );
}
$check( 'SEO: the two fields on pages and posts', registered_meta_key_exists( 'post', 'mody_seo_title', 'page' ) && registered_meta_key_exists( 'post', 'mody_seo_description', 'post' ) );
$avatar = (string) get_avatar_url( 1 );
$check( 'Profiles: no Gravatar, initials from the site', ! str_contains( $avatar, 'gravatar.com' ) && str_contains( $avatar, '/mody-avatar/' ), $avatar );
$collections = array_keys( WP_Font_Library::get_instance()->get_font_collections() );
$check( 'Fonts: the Mody collection, no Google Fonts', in_array( 'mody-fonts', $collections, true ) && ! in_array( 'google-fonts', $collections, true ), $collections );
$names = array_column( WP_Block_Patterns_Registry::get_instance()->get_all_registered(), 'name' );
$mody  = array_filter( $names, static fn( $n ) => str_starts_with( $n, 'mody/' ) );
$check( 'Patterns: the Mody collection (' . count( $mody ) . '), no core patterns', count( $mody ) > 50 && ! array_filter( $names, static fn( $n ) => str_starts_with( $n, 'core/' ) ), array_slice( array_values( array_diff( $names, $mody ) ), 0, 5 ) );
