<?php
/**
 * No Plugins or Connectors screens for anyone (tickets/hide-plugins-and-connectors.md).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Gating;

use WP_Admin_Bar;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Decided by the maintainer on 2026-11-11: "For now no connector settings nor
 * plugins should be visible for any user." Super admins included. Plugins are
 * installed and switched on only through Composer, `./bin/deploy` and WP-CLI,
 * which none of this touches (no user, no screen, no REST request).
 *
 * The capabilities stay as they are: other screens are gated on them
 * (Wordfence's menu needs `activate_plugins`). What goes is every way to see
 * or change plugins and connector keys from a browser:
 *
 * - the menus (site and network), the toolbar's network Plugins entry;
 * - the screens, refused when reached by URL;
 * - the REST routes for plugins and the block directory's installer;
 * - the connector keys (`connectors_*`) in `/wp/v2/settings`, for everyone, and
 *   the `ai` plugin's options (`wpai_*`) there for members (its own settings
 *   screen saves through that route for super admins on the main site);
 * - the `ai` plugin's connector approval screen.
 */
final class HiddenScreens {

	/** Screens (`$pagenow`) nobody opens, in site and network admin. */
	public const SCREENS = array( 'plugins.php', 'plugin-install.php', 'plugin-editor.php', 'options-connectors.php' );

	/** The `ai` plugin's screens (`page` slugs) about connectors, for everyone. */
	public const AI_SCREENS = array( 'ai-connector-approval' );

	/**
	 * REST routes refused to everyone: plugins, installing a block's plugin,
	 * and Spectra's onboarding routes that read, install or switch plugins.
	 */
	public const ROUTES = array( '/wp/v2/plugins', '/wp/v2/block-directory', '/one-onboarding/v1/plugin-status', '/one-onboarding/v1/install-plugin', '/one-onboarding/v1/activate-plugin' );

	/** Core's plugin actions on admin-ajax.php, refused to everyone. */
	public const AJAX_ACTIONS = array( 'search-plugins', 'activate-plugin', 'install-plugin', 'search-install-plugins', 'update-plugin', 'delete-plugin' );

	/** Option prefixes kept out of `/wp/v2/settings` for everyone. */
	public const HIDDEN_SETTINGS = array( 'connectors_' );

	/** Option prefixes refused in `/wp/v2/settings` to members (MemberSite::applies_to()). */
	public const MEMBER_SETTINGS = array( 'wpai_' );

	/**
	 * Hooked to `admin_menu` and `network_admin_menu` (late): removes the screens from the menus.
	 */
	public static function hide_menus(): void {
		global $submenu;
		remove_menu_page( 'plugins.php' );
		remove_submenu_page( 'options-general.php', 'options-connectors.php' );
		if ( ! is_array( $submenu ) ) {
			return;
		}
		foreach ( $submenu as $parent => $items ) {
			foreach ( (array) $items as $item ) {
				$slug = (string) ( $item[2] ?? '' );
				if ( in_array( $slug, self::SCREENS, true ) || in_array( $slug, self::AI_SCREENS, true ) ) {
					remove_submenu_page( $parent, $slug );
				}
			}
		}
	}

	/**
	 * Hooked to `admin_bar_menu` (late): the toolbar's network Plugins entry.
	 *
	 * @param WP_Admin_Bar $bar The toolbar.
	 */
	public static function hide_toolbar( WP_Admin_Bar $bar ): void {
		$bar->remove_node( 'network-admin-p' );
	}

	/**
	 * Hooked to `wp_dashboard_setup` (late): the `ai` plugin's status widget,
	 * whose checklist links to the Connectors screen. AiPlugin::hide_widgets()
	 * removed it for members before; now nobody has that screen.
	 */
	public static function hide_widgets(): void {
		remove_meta_box( 'wpai_status', 'dashboard', 'normal' );
		remove_meta_box( 'wpai_status', 'dashboard', 'side' );
	}

	/**
	 * Hooked to `admin_init`: refuses the screens when reached by URL.
	 */
	public static function refuse_screens(): void {
		global $pagenow;
		// admin_init runs on admin-ajax.php before any handler: core's plugin actions answer there too.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which action is asked for.
		if ( wp_doing_ajax() && in_array( sanitize_key( wp_unslash( $_REQUEST['action'] ?? '' ) ), self::AJAX_ACTIONS, true ) ) {
			wp_send_json_error( array( 'errorMessage' => __( 'This is not available.', 'mody_cloud' ) ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which screen is asked for.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( in_array( (string) $pagenow, self::SCREENS, true ) || ( '' !== $page && in_array( $page, self::AI_SCREENS, true ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'mody_cloud' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Filters `register_setting_args`: the connector keys are never in the REST API.
	 *
	 * @param array<string, mixed> $args   The setting's arguments.
	 * @param array<string, mixed> $defaults Unused.
	 * @param string               $group  Unused.
	 * @param string               $name   The option.
	 * @return array<string, mixed>
	 */
	public static function hide_settings( $args, $defaults, $group, $name ) {
		unset( $defaults, $group );
		if ( is_array( $args ) && self::matches( (string) $name, self::HIDDEN_SETTINGS ) ) {
			$args['show_in_rest'] = false;
		}
		return $args;
	}

	/**
	 * Filters `rest_pre_dispatch`: refuses the plugin routes to everyone, and
	 * a settings write with an `ai` plugin option to members.
	 *
	 * @param mixed           $result  The response so far (null: dispatch).
	 * @param mixed           $server  The REST server.
	 * @param WP_REST_Request $request The request.
	 * @return mixed
	 */
	public static function refuse_routes( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}
		// The REST server matches routes regardless of case (`/wp/v2/Plugins`), so this does too.
		$route = strtolower( untrailingslashit( (string) $request->get_route() ) );
		foreach ( self::ROUTES as $refused ) {
			if ( $route === $refused || str_starts_with( $route, $refused . '/' ) ) {
				return self::refused();
			}
		}
		if ( '/wp/v2/settings' === $route && 'GET' !== $request->get_method() && MemberSite::applies_to( get_current_user_id() ) ) {
			foreach ( array_keys( (array) $request->get_params() ) as $key ) {
				if ( self::matches( (string) $key, self::MEMBER_SETTINGS ) ) {
					return self::refused();
				}
			}
		}
		return $result;
	}

	/**
	 * Whether an option name starts with one of the prefixes.
	 *
	 * @param string             $name     The option.
	 * @param array<int, string> $prefixes The prefixes.
	 */
	private static function matches( string $name, array $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The refusal.
	 */
	private static function refused(): WP_Error {
		return new WP_Error( 'mody_hidden', __( 'This is not available.', 'mody_cloud' ), array( 'status' => 403 ) );
	}
}
