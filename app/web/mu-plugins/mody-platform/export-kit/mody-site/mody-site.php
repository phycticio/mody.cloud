<?php
/**
 * Plugin Name:       Mody Site
 * Description:       What a site made on Mody Cloud needs to look and work the same on any WordPress: its login page and Settings → Login, SEO titles and descriptions, placeholder images, initials avatars, and the Spectra privacy guard. Mody, the AI agent, is not part of it.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * Author URI:        https://mody.cloud
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 7.1
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * Built from the platform's own classes (tickets/take-your-site-with-you.md):
 * Export\Portable copies the files this bootstrap loads, under the same
 * namespace, so every option, post meta and address keeps its name. It is a
 * normal plugin: switching it off removes these features and nothing else.
 *
 * Not here, on purpose: the agent and its abilities, the platform's billing,
 * signup and quotas, and the locks that keep members from installing plugins
 * or updating WordPress on Mody Cloud.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform;

defined( 'ABSPATH' ) || exit;

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
 * Spectra connects to no service outside Europe: no Google Maps, Google
 * Fonts, AI design library or telemetry. This plugin loads before Spectra
 * (plugins load in alphabetical order), so the guard is in place first.
 */
Spectra\SpectraGuard::boot();

/*
 * Placeholder images (/mody-placeholder/<w>x<h>.svg) and initials avatars
 * (/mody-avatar/...), drawn here, before the rest of WordPress runs.
 */
Design\Patterns::serve_placeholder();
Users\Avatars::serve_initials();
add_filter( 'pre_get_avatar_data', array( Users\Avatars::class, 'avatar_data' ), 10, 2 );
add_filter( 'avatar_defaults', array( Users\Avatars::class, 'defaults' ) );
add_action( 'user_edit_form_tag', array( Users\Avatars::class, 'form_tag' ) );
add_filter( 'user_profile_picture_description', array( Users\Avatars::class, 'description' ), 10, 2 );
add_action( 'show_user_profile', array( Users\Avatars::class, 'section' ) );
add_action( 'edit_user_profile', array( Users\Avatars::class, 'section' ) );
add_action( 'personal_options_update', array( Users\Avatars::class, 'save' ) );
add_action( 'edit_user_profile_update', array( Users\Avatars::class, 'save' ) );
add_filter( 'ajax_query_attachments_args', array( Users\Avatars::class, 'hide_from_media' ) );
add_filter( 'rest_attachment_query', array( Users\Avatars::class, 'hide_from_media' ) );
add_action( 'delete_attachment', array( Users\Avatars::class, 'forget_attachment' ) );
add_action( 'delete_user', array( Users\Avatars::class, 'forget_user' ) );

// The page's SEO title (post meta mody_seo_title) and meta description (its excerpt).
Site\Seo::register();

// The login page in the site's brand, and Settings → Login.
Login\LoginPage::register();
add_action( 'admin_init', array( Login\SettingsPage::class, 'register_setting' ) );
add_action( 'rest_api_init', array( Login\SettingsPage::class, 'register_setting' ) );
add_action( 'admin_menu', array( Login\SettingsPage::class, 'register_menu' ) );

// Color pickers offer the palette only, as on Mody Cloud.
add_action( 'enqueue_block_editor_assets', array( Design\PaletteOnly::class, 'enqueue' ) );

// wp-admin's Ocean colors for new users.
add_filter( 'insert_user_meta', array( Admin\AdminColors::class, 'new_user_scheme' ), 10, 4 );
add_filter( 'get_user_option_admin_color', array( Admin\AdminColors::class, 'default_scheme' ) );

// Antispam Bee's checks that call outside services stay off.
Security\AntispamBee::register();
