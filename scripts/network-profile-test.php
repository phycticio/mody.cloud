<?php
/**
 * Tests the network profile and local avatars (tickets/network-profile-local-avatars.md).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/network-profile-test.php
 *
 * Needs two member sites (alpha and beta locally). A throwaway member joins
 * both; everything is removed at the end. Refuses to run in production.
 *
 * 1. Avatars: users, emails, comments and posts give an image on this network,
 *    never Gravatar; the initials route draws letters and refuses anything else.
 * 2. Over HTTP: on a member site, Profile and Edit User lead to the main
 *    site; the REST API refuses changes to users there and still lists them.
 * 3. The picture: uploaded through the profile form on the main site, stored
 *    in its media library, shown on both member sites; a too-large or
 *    non-image file is refused; the member cannot change someone else's;
 *    Remove deletes it.
 */

use Mody\Platform\Users\Avatars;
use Mody\Platform\Users\NetworkProfile;

if ( 'production' === wp_get_environment_type() ) {
	echo "Refusing to run in production.\n";
	return;
}
require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-64s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

$main  = get_main_site_id();
$sites = get_sites(
	array(
		'site__not_in' => array( $main ),
		'fields'       => 'ids',
		'number'       => 2,
	)
);
if ( count( $sites ) < 2 ) {
	echo "Needs two member sites: nothing to test.\n";
	return;
}
list( $site_a, $site_b ) = array_map( 'intval', $sites );
$network_host            = (string) get_network()->domain;
$on_network              = static fn( string $url ): bool => '' !== $url && in_array( (string) wp_parse_url( $url, PHP_URL_HOST ), array_map( static fn( $s ) => $s->domain, get_sites( array( 'number' => 0 ) ) ), true );

$suffix = strtolower( wp_generate_password( 6, false ) );
$member = (int) wpmu_create_user( "modyprofile{$suffix}", wp_generate_password( 24 ), "modyprofile{$suffix}@mody.local" );
$other  = (int) wpmu_create_user( "modyprofileb{$suffix}", wp_generate_password( 24 ), "modyprofileb{$suffix}@mody.local" );
wp_update_user(
	array(
		'ID'           => $member,
		'display_name' => 'Ana López',
	)
);
add_user_to_blog( $site_a, $member, 'administrator' );
add_user_to_blog( $site_b, $member, 'editor' );
$super = get_user_by( 'login', get_super_admins()[0] )->ID;
register_shutdown_function(
	static function () use ( $member, $other ): void {
		Avatars::remove( $member );
		Avatars::remove( $other );
		wpmu_delete_user( $member );
		wpmu_delete_user( $other );
	}
);

// 1. Avatars ------------------------------------------------------------------------

echo "Avatars\n";
$urls = array(
	'user'    => get_avatar_url( $member ),
	'WP_User' => get_avatar_url( get_userdata( $member ) ),
	'email'   => get_avatar_url( "modyprofile{$suffix}@mody.local" ),
	'visitor' => get_avatar_url( 'someone.else@example.com' ),
	'forced'  => get_avatar_url( $member, array( 'force_default' => true ) ),
);
$comment          = new WP_Comment( (object) array( 'comment_author' => 'Jo Visitor', 'comment_author_email' => 'jo@example.com', 'user_id' => 0, 'comment_type' => 'comment' ) );
$urls['comment']  = get_avatar_url( $comment );
$foreign          = array_filter( $urls, static fn( string $url ): bool => ! $on_network( $url ) || str_contains( $url, 'gravatar' ) );
$check( 'every avatar is on this network, none from Gravatar', ! $foreign, wp_json_encode( $foreign ) );
$check( '... the user\'s initials, the same by ID, user or email', str_ends_with( $urls['user'], '/AL.svg' ) && $urls['user'] === $urls['WP_User'] && $urls['user'] === $urls['email'], $urls['user'] );
$check( '... a visitor\'s from their name', str_ends_with( $urls['comment'], '/JV.svg' ), $urls['comment'] );
$check( 'get_avatar() prints it (esc_url keeps it)', str_contains( get_avatar( $member ), "src='" . $urls['user'] . "'" ) );
$check( 'Settings → Discussion offers only the initials', array( 'mystery' ) === array_keys( apply_filters( 'avatar_defaults', array( 'gravatar_default' => 'x' ) ) ) );
$check( 'the route: letters and digits only', array( 3, 'AL' ) === Avatars::initials_request( '/mody-avatar/3/AL.svg' ) && array( 0, 'Ñ' ) === Avatars::initials_request( '/mody-avatar/0/' . rawurlencode( 'Ñ' ) . '.svg' ) && null === Avatars::initials_request( '/mody-avatar/3/%3Cs%3E.svg' ) && null === Avatars::initials_request( '/mody-avatar/9/A.svg' ) && null === Avatars::initials_request( '/mody-avatar/3/ABC.svg' ) );
$svg = Avatars::svg( 3, 'AL' );
$check( '... a self-contained SVG, no outside reference', str_starts_with( $svg, '<svg' ) && ! preg_match( '/href|url\(|<script|https?:\/\/(?!www\.w3\.org)/', $svg ) );

// HTTP helpers ------------------------------------------------------------------------

$cookie = static function ( int $user_id ): array {
	$expiration = time() + 600;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	register_shutdown_function( static fn() => WP_Session_Tokens::get_instance( $user_id )->destroy( $token ) );
	return array(
		SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token ),
		LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ),
	);
};
// This container reaches only the main host; the Host header picks the site.
$http = static function ( string $host, string $path, array $cookies, $body = null, array $headers = array() ) use ( $network_host ): array {
	$args     = array(
		'cookies'     => $cookies,
		'headers'     => array( 'Host' => $host ) + $headers,
		'sslverify'   => false,
		'redirection' => 0,
		'timeout'     => 30,
	);
	$url      = 'https://' . $network_host . $path;
	$response = null === $body ? wp_remote_get( $url, $args ) : wp_remote_post( $url, $args + array( 'body' => $body ) );
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $response ),
		'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		'body'     => (string) wp_remote_retrieve_body( $response ),
	);
};
$as_member = $cookie( $member );
$host_a    = get_site( $site_a )->domain;
$path_of   = static fn( string $url ): string => (string) wp_parse_url( $url, PHP_URL_PATH ) . ( wp_parse_url( $url, PHP_URL_QUERY ) ? '?' . wp_parse_url( $url, PHP_URL_QUERY ) : '' );

// 2. The profile lives on the main site ----------------------------------------------------

echo "\nThe profile lives on the main site\n";
$out = $http( $host_a, $path_of( get_admin_url( $site_a, 'profile.php' ) ), $as_member );
$check( 'member site Profile: to the main site\'s user dashboard', 302 === $out['code'] && user_admin_url( 'profile.php' ) === $out['location'], $out['code'] . ' ' . $out['location'] );
$out = $http( $host_a, $path_of( get_admin_url( $site_a, 'user-edit.php?user_id=' . $member ) ), $cookie( $super ) );
$check( 'member site Edit User (super admin): to Network Admin', 302 === $out['code'] && network_admin_url( 'user-edit.php?user_id=' . $member ) === $out['location'], $out['code'] . ' ' . $out['location'] );
$out = $http( $network_host, $path_of( user_admin_url( 'profile.php' ) ), $as_member );
$check( 'the main site\'s profile opens for the member (no role there)', 200 === $out['code'] && str_contains( $out['body'], 'enctype="multipart/form-data"' ) && str_contains( $out['body'], 'name="' . Avatars::FIELD . '"' ), (string) $out['code'] );
$check( '... no Gravatar text or link', ! str_contains( $out['body'], 'gravatar.com' ) );
switch_to_blog( $site_a );
wp_set_current_user( $member );
$check( 'Edit Profile links lead there', user_admin_url( 'profile.php' ) === get_edit_profile_url( $member ) );
$write = new WP_REST_Request( 'POST', '/wp/v2/users/me' );
$write->set_body_params( array( 'name' => 'Changed elsewhere' ) );
$write = rest_do_request( $write );
$read  = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users/me' ) );
$prefs = new WP_REST_Request( 'POST', '/wp/v2/users/me' );
$prefs->set_body_params( array( 'meta' => array( 'persisted_preferences' => array( 'core' => array( 'fixedToolbar' => true ) ) ) ) );
$prefs = rest_do_request( $prefs );
$mixed = new WP_REST_Request( 'POST', '/wp/v2/users/me' );
$mixed->set_body_params( array( 'meta' => array(), 'email' => 'x@example.com' ) );
$mixed = rest_do_request( $mixed );
$check( '... the editor\'s preferences (meta only) still saved there', 200 === $prefs->get_status() && 403 === $mixed->get_status(), $prefs->get_status() . ' ' . $mixed->get_status() );
$upper = new WP_REST_Request( 'PUT', '/wp/v2/Users/me' );
$upper->set_body_params( array( 'name' => 'Changed elsewhere' ) );
$upper = rest_do_request( $upper );
$batch = new WP_REST_Request( 'POST', '/batch/v1' );
$batch->set_body_params( array( 'requests' => array( array( 'method' => 'POST', 'path' => '/wp/v2/users/me', 'body' => array( 'name' => 'Changed elsewhere' ) ) ) ) );
$batch = rest_do_request( $batch );
$inner = (array) ( $batch->get_data()['responses'][0] ?? array() );
$check( '... other casings, PUT forms and /batch/v1 refused too (no 500)', 403 === $upper->get_status() && 207 === $batch->get_status() && 403 === (int) ( $inner['status'] ?? 0 ) && 'Ana López' === get_userdata( $member )->display_name, $upper->get_status() . ' ' . $batch->get_status() . ' ' . ( $inner['status'] ?? '?' ) );
$check( 'Application Passwords: only for super admins (rule 6)', ! wp_is_application_passwords_available_for_user( get_userdata( $member ) ) && wp_is_application_passwords_available_for_user( get_userdata( $super ) ) );
$check( 'member site REST: a change refused, reading allowed', 403 === $write->get_status() && 200 === $read->get_status() && 'Ana López' === get_userdata( $member )->display_name, $write->get_status() . ' ' . $read->get_status() );
wp_set_current_user( 0 );
restore_current_blog();

// 3. The picture ---------------------------------------------------------------------------

echo "\nThe picture\n";
$profile = $http( $network_host, $path_of( user_admin_url( 'profile.php' ) ), $as_member );
preg_match( '/name="_wpnonce" value="([0-9a-f]+)"/', $profile['body'], $nonce );
$fields    = array(
	'_wpnonce'     => $nonce[1] ?? '',
	'action'       => 'update',
	'user_id'      => (string) $member,
	'email'        => "modyprofile{$suffix}@mody.local",
	'nickname'     => "modyprofile{$suffix}",
	'display_name' => 'Ana López',
);
$multipart = static function ( array $fields, array $files ): array {
	$boundary = 'mody' . wp_generate_password( 16, false );
	$body     = '';
	foreach ( $fields as $name => $value ) {
		$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
	}
	foreach ( $files as $name => $file ) {
		$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"; filename=\"{$file[0]}\"\r\nContent-Type: {$file[1]}\r\n\r\n{$file[2]}\r\n";
	}
	return array( $body . "--$boundary--\r\n", array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ) );
};
$png = static function ( int $size ): string {
	$image = imagecreatetruecolor( $size, $size );
	imagefill( $image, 0, 0, imagecolorallocate( $image, 40, 90, 160 ) );
	ob_start();
	imagepng( $image );
	return (string) ob_get_clean();
};
$post = static function ( array $files, array $extra = array() ) use ( $http, $network_host, $path_of, $as_member, $fields, $multipart ): array {
	list( $body, $headers ) = $multipart( $fields + $extra, $files );
	return $http( $network_host, $path_of( user_admin_url( 'profile.php' ) ), $as_member, $body, $headers );
};
$fresh = static function () use ( $member ): void {
	wp_cache_delete( $member, 'user_meta' );
};
// Read from the database: the requests changed it in other processes.
$exists = static function ( int $post_id ) use ( $main ): bool {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test.
	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->get_blog_prefix( $main )}posts WHERE ID = %d", $post_id ) );
};

$out = $post( array( Avatars::FIELD => array( 'me.png', 'image/png', $png( 400 ) ) ) );
$fresh();
$attachment = (int) get_user_meta( $member, Avatars::META, true );
$urls       = (array) get_user_meta( $member, Avatars::URLS, true );
$check( 'upload: saved', 302 === $out['code'] && $attachment > 0 && isset( $urls['thumbnail'], $urls['full'] ), $out['code'] . ' ' . $out['location'] . ' ' . $attachment );
switch_to_blog( $main );
$stored = get_post( $attachment );
restore_current_blog();
$check( '... in the main site\'s media library, owned by the member', $stored && 'attachment' === $stored->post_type && $member === (int) $stored->post_author );
$seen = array();
foreach ( array( $site_a, $site_b ) as $site ) {
	switch_to_blog( $site );
	$seen[] = get_avatar_url( $member, array( 'size' => 64 ) );
	restore_current_blog();
}
$check( '... shown the same on both member sites', $seen[0] === $seen[1] && $seen[0] === $urls['thumbnail'] && $on_network( $seen[0] ), $seen[0] );
$check( '... a large size uses the full image', $urls['full'] === get_avatar_url( $member, array( 'size' => 512 ) ) );

// Found by the review: the stored picture is drawn again, square, without the original's metadata.
$file = get_attached_file( $attachment, true );
switch_to_blog( $main );
$file = get_attached_file( $attachment, true );
restore_current_blog();
$dims = $file && is_file( $file ) ? getimagesize( $file ) : array( 0, 0 );
$check( '... stored square, at most 512 pixels, named neutrally', 400 === (int) $dims[0] && 400 === (int) $dims[1] && str_contains( basename( (string) $file ), 'profile-picture-' . $member ), wp_json_encode( array( basename( (string) $file ), $dims[0], $dims[1] ) ) );
switch_to_blog( $main );
wp_set_current_user( $super );
$listed = wp_list_pluck( (array) rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/media' ) )->get_data(), 'id' );
wp_set_current_user( 0 );
restore_current_blog();
$check( '... and not listed in the main site\'s media', ! in_array( $attachment, $listed, true ) );
$jpeg = static function ( int $w, int $h ): string {
	$image = imagecreatetruecolor( $w, $h );
	imagefill( $image, 0, 0, imagecolorallocate( $image, 200, 120, 40 ) );
	ob_start();
	imagejpeg( $image );
	$data = (string) ob_get_clean();
	$exif = "Exif\0\0MODY-GPS-SECRET";
	return substr( $data, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $exif ) + 2 ) . $exif . substr( $data, 2 ); // An APP1 segment after SOI.
};
$out = $post( array( Avatars::FIELD => array( 'holiday.jpg', 'image/jpeg', $jpeg( 900, 600 ) ) ) );
$fresh();
$wide = (int) get_user_meta( $member, Avatars::META, true );
switch_to_blog( $main );
$file = get_attached_file( $wide, true );
restore_current_blog();
$dims = $file && is_file( $file ) ? getimagesize( $file ) : array( 0, 0 );
$check( 'a wide JPEG with EXIF: cropped square to 512, the EXIF gone', 512 === (int) $dims[0] && 512 === (int) $dims[1] && ! str_contains( (string) file_get_contents( (string) $file ), 'MODY-GPS-SECRET' ), wp_json_encode( array( $dims[0], $dims[1] ) ) );
$attachment = $wide;
$out = $post( array( Avatars::FIELD => array( 'notes.txt', 'text/plain', 'not an image' ) ) );
$fresh();
$check( 'a text file: refused, the picture kept', $attachment === (int) get_user_meta( $member, Avatars::META, true ) && (bool) preg_match( '/class="notice error[^"]*"/', $out['body'] ), (string) $out['code'] );

$out      = $post( array( Avatars::FIELD => array( 'me2.png', 'image/png', $png( 300 ) ) ) );
$fresh();
$replaced = (int) get_user_meta( $member, Avatars::META, true );
$gone = ! $exists( $attachment );
$check( 'a new picture replaces the old one, which is deleted', $replaced && $replaced !== $attachment && $gone, $attachment . ' → ' . $replaced );

wp_set_current_user( $member );
$_POST[ Avatars::REMOVE ] = '1';
Avatars::save( $super );
unset( $_POST[ Avatars::REMOVE ] );
wp_set_current_user( 0 );
$check( 'the member cannot remove someone else\'s picture', ! current_user_can( 'edit_user', $super ) );

$out = $post( array(), array( Avatars::REMOVE => '1' ) );
$fresh();
$gone = ! $exists( $replaced );
$check( 'Remove: the picture and its file are gone, initials again', 302 === $out['code'] && ! get_user_meta( $member, Avatars::META, true ) && $gone && str_ends_with( get_avatar_url( $member ), '/AL.svg' ), (string) $out['code'] );

// A deleted person's picture goes with them (found by the review).
$out = $post( array( Avatars::FIELD => array( 'me3.png', 'image/png', $png( 200 ) ) ) );
$fresh();
$last = (int) get_user_meta( $member, Avatars::META, true );
wpmu_delete_user( $member );
$check( 'deleting the user deletes their picture', $last && ! $exists( $last ) );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
