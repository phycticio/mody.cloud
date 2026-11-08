<?php
/**
 * Tests the member-site rules (ticket M4-01, mvp-spec sections 9 and 10).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/gating-test.php
 *
 * A test user is made administrator of a member site (the first site that is
 * not the main one) and of the main site.
 *
 * 1. Capabilities: on the member site it loses the blocked plugin and theme
 *    capabilities (install, upload, update, delete, edit; themes) and keeps
 *    the rest, the Plugins screen included (M4-02); on the main site
 *    nothing changes; super admins keep everything.
 * 2. REST: installing a plugin is refused on the member site, and a
 *    platform plugin's route answers as if it were not installed.
 * 3. Redirect targets: pages, patterns and menus go to the Site Editor;
 *    posts and other actions stay.
 * 4. Over HTTP on the member site (a request to this host with the member
 *    site's Host header): editing a page and adding one land in the Site
 *    Editor, a post opens the post editor, the Plugins screen opens (M4-02;
 *    scripts/member-plugins-test.php has the details), the plugin install
 *    screen refuses, a theme switch does nothing; a super admin is not
 *    redirected.
 *
 * The user, the page and the post made here are removed at the end. No model call.
 */

use Mody\Platform\Gating\MemberPlugins;
use Mody\Platform\Gating\MemberSite;
use Mody\Platform\SiteEditor;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-48s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

$admins = get_super_admins();
$admin  = get_user_by( 'login', $admins[0] )->ID;
$main   = get_main_site_id();
$member_sites = get_sites(
	array(
		'site__not_in' => array( $main ),
		'fields'       => 'ids',
		'number'       => 1,
	)
);
if ( ! $member_sites ) {
	echo "No member site on this network: nothing to test.\n";
	return;
}
$site   = (int) $member_sites[0];
$suffix = strtolower( wp_generate_password( 6, false ) );
$user   = wp_insert_user(
	array(
		'user_login' => 'modygate' . $suffix,
		'user_email' => 'modygate' . $suffix . '@mody.local',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	)
);
add_user_to_blog( $site, $user, 'administrator' );
add_user_to_blog( $main, $user, 'administrator' );
switch_to_blog( $site );
$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Mody gating test page', 'post_author' => $user ) );
$post_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Mody gating test post', 'post_author' => $user ) );
$theme   = get_stylesheet();
restore_current_blog();
register_shutdown_function(
	static function () use ( $site, $page_id, $post_id, $user ): void {
		switch_to_blog( $site );
		wp_delete_post( $page_id, true );
		wp_delete_post( $post_id, true );
		restore_current_blog();
		wpmu_delete_user( $user );
	}
);

$kept = array( 'edit_theme_options', 'manage_options', 'upload_files', 'edit_pages', 'publish_pages', 'edit_posts', 'edit_others_posts', 'list_users', 'promote_users', 'moderate_comments' );
$can  = static fn( int $site_id, int $user_id, string $cap ): bool => user_can_for_site( $user_id, $site_id, $cap );

// 1. Capabilities ------------------------------------------------------------

echo "Capabilities (member site $site)\n";
$granted = array_filter( MemberSite::BLOCKED_CAPS, static fn( $cap ) => $can( $site, $user, $cap ) );
$check( 'no plugin or theme capability', ! $granted, $granted ? implode( ', ', $granted ) : count( MemberSite::BLOCKED_CAPS ) . ' refused' );
$missing = array_filter( $kept, static fn( $cap ) => ! $can( $site, $user, $cap ) );
$check( '...the rest kept: content, media, users, settings, styles', ! $missing, $missing ? implode( ', ', $missing ) : implode( ', ', $kept ) );
$check( '...and the Plugins screen (M4-02)', $can( $site, $user, 'activate_plugins' ) );
$super = array_filter( array( 'switch_themes', 'activate_plugins', 'install_plugins' ), static fn( $cap ) => ! $can( $site, $admin, $cap ) );
$check( 'super admins keep everything', ! $super, $super ? implode( ', ', $super ) : '' );

echo "\nCapabilities (main site)\n";
$with = array_map( static fn( $cap ) => $can( $main, $user, $cap ), MemberSite::BLOCKED_CAPS );
remove_filter( 'map_meta_cap', array( MemberSite::class, 'map_meta_cap' ), 10 );
$without = array_map( static fn( $cap ) => $can( $main, $user, $cap ), MemberSite::BLOCKED_CAPS );
add_filter( 'map_meta_cap', array( MemberSite::class, 'map_meta_cap' ), 10, 4 );
$check( 'unchanged by the rules', $with === $without, 'switch_themes: ' . wp_json_encode( $with[ array_search( 'switch_themes', MemberSite::BLOCKED_CAPS, true ) ] ) );
$check( '...no Plugins screen for the member there', ! $can( $main, $user, 'activate_plugins' ) );

// 2. REST ---------------------------------------------------------------------

echo "\nREST (member site)\n";
switch_to_blog( $site );
wp_set_current_user( $user );
$list    = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/plugins' ) )->get_status();
$install = new WP_REST_Request( 'POST', '/wp/v2/plugins' );
$install->set_param( 'slug', 'hello-dolly' );
$install = rest_do_request( $install )->get_status();
$plugin  = array_key_first( array_filter( get_plugins(), static fn( $file ) => MemberPlugins::is_hidden( (string) $file ), ARRAY_FILTER_USE_KEY ) );
$toggle  = new WP_REST_Request( 'PUT', '/wp/v2/plugins/' . preg_replace( '/\.php$/', '', (string) $plugin ) );
$toggle->set_param( 'status', 'active' );
$toggle = rest_do_request( $toggle )->get_status();
wp_set_current_user( $admin );
restore_current_blog();
$check( 'plugins: install refused, a platform plugin not found', 403 === $install && 404 === $toggle && 200 === $list, "install $install, " . $plugin . " $toggle, list $list" );

// 3. Redirect targets -------------------------------------------------------------

echo "\nRedirect targets\n";
switch_to_blog( $site );
$block_id = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Mody gating pattern' ) );
$targets  = array(
	'edit a page'      => array( MemberSite::editor_target( 'post.php', array( 'post' => $page_id, 'action' => 'edit' ) ), SiteEditor::page_url( $page_id ) ),
	'edit a pattern'   => array( MemberSite::editor_target( 'post.php', array( 'post' => $block_id, 'action' => 'edit' ) ), SiteEditor::url( '/wp_block/' . $block_id, true ) ),
	'add a page'       => array( MemberSite::editor_target( 'post-new.php', array( 'post_type' => 'page' ) ), SiteEditor::url( '/page' ) ),
	'add a pattern'    => array( MemberSite::editor_target( 'post-new.php', array( 'post_type' => 'wp_block' ) ), SiteEditor::url( '/pattern' ) ),
	'edit a post'      => array( MemberSite::editor_target( 'post.php', array( 'post' => $post_id, 'action' => 'edit' ) ), '' ),
	'add a post'       => array( MemberSite::editor_target( 'post-new.php', array() ), '' ),
	'trash a page'     => array( MemberSite::editor_target( 'post.php', array( 'post' => $page_id, 'action' => 'trash' ) ), '' ),
	'an unknown ID'    => array( MemberSite::editor_target( 'post.php', array( 'post' => 999999999, 'action' => 'edit' ) ), '' ),
);
wp_delete_post( $block_id, true );
restore_current_blog();
foreach ( $targets as $label => list( $got, $want ) ) {
	$check( $label, $got === $want, '' === $got ? 'stays' : $got );
}

// 4. Over HTTP ---------------------------------------------------------------------

echo "\nOver HTTP (member site)\n";
$host   = get_site( $site )->domain;
$cookie = static function ( int $user_id ): array {
	$expiration = time() + 300;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	register_shutdown_function( static fn() => WP_Session_Tokens::get_instance( $user_id )->destroy( $token ) );
	return array(
		SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token ),
		LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ),
	);
};
// This container reaches only the main host; the Host header picks the member site.
$get = static function ( string $path, array $cookies ) use ( $host ): array {
	$response = wp_remote_get(
		'https://' . get_site( get_main_site_id() )->domain . $path,
		array(
			'cookies'     => $cookies,
			'headers'     => array( 'Host' => $host ),
			'sslverify'   => false,
			'redirection' => 0,
			'timeout'     => 30,
		)
	);
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $response ),
		'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
	);
};
$as_member = $cookie( $user );
$as_admin  = $cookie( $admin );
$admin_path = (string) wp_parse_url( get_admin_url( $site ), PHP_URL_PATH );

$out = $get( $admin_path . 'post.php?post=' . $page_id . '&action=edit', $as_member );
$check( 'edit a page: the Site Editor', 302 === $out['code'] && str_contains( $out['location'], 'site-editor.php?p=%2Fpage%2F' . $page_id . '&canvas=edit' ), $out['code'] . ' ' . $out['location'] );
$out = $get( $admin_path . 'post-new.php?post_type=page', $as_member );
$check( 'add a page: the Site Editor\'s pages', 302 === $out['code'] && str_contains( $out['location'], 'site-editor.php?p=%2Fpage' ), $out['code'] . ' ' . $out['location'] );
$out = $get( $admin_path . 'post.php?post=' . $post_id . '&action=edit', $as_member );
$check( 'edit a post: the post editor', 200 === $out['code'], (string) $out['code'] );
$out = $get( $admin_path . 'plugins.php', $as_member );
$check( 'the Plugins screen opens (M4-02)', 200 === $out['code'], (string) $out['code'] );
$out = $get( $admin_path . 'plugin-install.php', $as_member );
$check( 'the plugin install screen refuses', in_array( $out['code'], array( 403, 500 ), true ), $out['code'] . ' (core\'s wp_die() without a status is 500)' );
$out = $get( $admin_path . 'themes.php?action=activate&stylesheet=twentytwentyfive', $as_member );
$check( 'a theme switch does nothing', $theme === get_blog_option( $site, 'stylesheet' ), $out['code'] . ', theme ' . get_blog_option( $site, 'stylesheet' ) );
$out = $get( $admin_path . 'post.php?post=' . $page_id . '&action=edit', $as_admin );
$check( 'a super admin is not redirected', 200 === $out['code'], (string) $out['code'] );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
