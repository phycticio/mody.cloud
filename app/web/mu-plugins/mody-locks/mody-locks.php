<?php
/**
 * Plugin Name:       Mody Locks
 * Description:       What members may not do on a network: install plugins, change themes, open the Plugins or Connectors screens, change the ai plugin, delete their site.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * What members may not do on a network (tickets M4-01, M4-02, M4-03,
 * hide-plugins-and-connectors.md and the security pass of 2026-11-12).
 * Super admins are not affected, except by HiddenScreens:
 *
 * - **MemberSite**: no plugin installs or theme changes for members; pages,
 *   patterns and menus open in the Site Editor; Delete Site refused.
 * - **MemberPlugins**: the plugins members never switch, out of every list.
 * - **HiddenScreens**: no Plugins or Connectors screens for anyone, super
 *   admins included; plugins change only through Composer, ./bin/deploy and
 *   WP-CLI (decided 2026-11-11).
 * - **AiPlugin**: the `ai` plugin's features and approvals pinned, its
 *   screens and configuration routes refused to members, MCP locked.
 * - **Isolation, MemberMail, Throttle**: widgets, Application Passwords,
 *   deleting a site, and limits on the mail members make the platform send.
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md):
 * it names no other plugin's class. A network-only plugin.
 *
 * @package Mody\Locks
 */

namespace Mody\Locks;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Locks\Example is src/Example.php.
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

if ( ! is_multisite() ) {
	return; // Nothing to lock on a single site: its owner runs it.
}

/*
 * Member sites (every site but the main one, ticket M4-01): no plugin
 * installs or theme changes for members, and pages, patterns and menus open
 * in the Site Editor. The hidden plugins stay out of every list and switch
 * (M4-02).
 */
add_filter( 'map_meta_cap', array( MemberSite::class, 'map_meta_cap' ), 10, 4 );
add_filter( 'all_plugins', array( MemberPlugins::class, 'filter_screen' ) );
add_filter( 'pre_update_option_active_plugins', array( MemberPlugins::class, 'keep_hidden' ), 10, 2 ); // Any code path, e.g. Spectra's onboarding API.
add_filter( 'rest_pre_dispatch', array( MemberPlugins::class, 'refuse_rest_item' ), 10, 3 );
add_filter( 'rest_request_after_callbacks', array( MemberPlugins::class, 'filter_rest_list' ), 10, 3 );
add_action( 'load-post.php', array( MemberSite::class, 'redirect_editor' ) );
add_action( 'load-post-new.php', array( MemberSite::class, 'redirect_editor' ) );

/*
 * No Plugins or Connectors screens for anyone, super admins included
 * (decided 2026-11-11, tickets/hide-plugins-and-connectors.md).
 */
add_action( 'admin_menu', array( HiddenScreens::class, 'hide_menus' ), 999 );
add_action( 'network_admin_menu', array( HiddenScreens::class, 'hide_menus' ), 999 );
add_action( 'admin_bar_menu', array( HiddenScreens::class, 'hide_toolbar' ), 999 );
add_action( 'wp_dashboard_setup', array( HiddenScreens::class, 'hide_widgets' ), 999 );
add_action( 'admin_notices', array( HiddenScreens::class, 'hide_notices' ), 0 ); // The ai plugin's notice that links to the hidden approvals screen.
add_action( 'admin_init', array( HiddenScreens::class, 'refuse_screens' ) );
add_filter( 'register_setting_args', array( HiddenScreens::class, 'hide_settings' ), 10, 4 );
add_filter( 'rest_pre_dispatch', array( HiddenScreens::class, 'refuse_routes' ), 10, 3 );

/*
 * The `ai` plugin on member sites (ticket M4-03).
 */
add_filter( 'pre_option', array( AiPlugin::class, 'pin_option' ), 10, 2 );
add_action( 'admin_menu', array( AiPlugin::class, 'hide_screens' ), 999 );
add_action( 'wp_dashboard_setup', array( AiPlugin::class, 'hide_widgets' ), 999 );
add_action( 'admin_init', array( AiPlugin::class, 'refuse_screens' ) );
add_filter( 'rest_pre_dispatch', array( AiPlugin::class, 'lock_routes' ), 10, 3 );
add_action( 'wp_initialize_site', array( AiPlugin::class, 'create_log_table' ), 200 );

/*
 * Widgets, Application Passwords, deleting a site, mail limits (security
 * pass, 2026-11-12).
 */
Isolation::register();
