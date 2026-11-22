<?php
/**
 * Plugin Name:       Mody Profiles
 * Description:       Profile pictures stored on the site and initials for everyone else, no Gravatar; on a network, one profile per person, edited on the main site.
 * Version:           1.0.1
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        https://mody.cloud/
 * Text Domain:       mody_cloud
 *
 * One profile per person and no Gravatar (decided by the maintainer on
 * 2026-11-11, tickets/network-profile-local-avatars.md):
 *
 * - **Avatars:** a profile picture is uploaded on the profile screen and
 *   stored in the main site's media library; everyone else gets initials,
 *   drawn here at /mody-avatar/. Nothing calls gravatar.com.
 * - **NetworkProfile** (multisite only): every site but the main one sends
 *   Profile and Edit User to the main site, and refuses profile changes over
 *   REST (user meta still saves). Only super admins have Application
 *   Passwords.
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md).
 * When Mody Images is there, a picture too big to decode is refused first.
 *
 * @package Mody\Profiles
 */

namespace Mody\Profiles;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Profiles\Example is src/Example.php.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( __NAMESPACE__ ) + 1 ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	}
);

/*
 * Avatars: initials served now, before the rest of WordPress runs.
 */
Avatars::serve_initials();
add_filter( 'pre_get_avatar_data', array( Avatars::class, 'avatar_data' ), 10, 2 );
add_filter( 'avatar_defaults', array( Avatars::class, 'defaults' ) );
add_action( 'user_edit_form_tag', array( Avatars::class, 'form_tag' ) );
add_filter( 'user_profile_picture_description', array( Avatars::class, 'description' ), 10, 2 );
add_action( 'show_user_profile', array( Avatars::class, 'section' ) );
add_action( 'edit_user_profile', array( Avatars::class, 'section' ) );
add_action( 'personal_options_update', array( Avatars::class, 'save' ) );
add_action( 'edit_user_profile_update', array( Avatars::class, 'save' ) );
add_filter( 'ajax_query_attachments_args', array( Avatars::class, 'hide_from_media' ) );
add_filter( 'rest_attachment_query', array( Avatars::class, 'hide_from_media' ) );
add_action( 'delete_attachment', array( Avatars::class, 'forget_attachment' ) );
add_action( 'wpmu_delete_user', array( Avatars::class, 'forget_user' ) );
add_action( 'delete_user', array( Avatars::class, 'forget_user' ) );

/*
 * One profile for the network, edited on the main site.
 */
if ( is_multisite() ) {
	add_action( 'load-profile.php', array( NetworkProfile::class, 'redirect' ) );
	add_action( 'load-user-edit.php', array( NetworkProfile::class, 'redirect' ) );
	add_filter( 'edit_profile_url', array( NetworkProfile::class, 'profile_url' ), 10, 2 );
	add_filter( 'allowed_redirect_hosts', array( NetworkProfile::class, 'allow_main_host' ) );
	add_filter( 'rest_pre_dispatch', array( NetworkProfile::class, 'refuse_rest_writes' ), 10, 3 );
	add_filter( 'wp_is_application_passwords_available_for_user', array( NetworkProfile::class, 'application_passwords' ), 10, 2 );
}

// WordPress's update notice from mody.cloud, when installed as a normal plugin (never on Mody Cloud's network).
UpdateCheck::register( __FILE__ );
