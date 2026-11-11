<?php
/**
 * Which installed plugins members see on their own sites (ticket M4-02).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Gating;

use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Decided by the maintainer on 2026-11-08: members use core's Plugins screen
 * (`plugins.php`) on their own site, and every plugin installed through
 * Composer appears there unless it is hidden. Hidden plugins are invisible
 * to members and cannot be switched by them (MemberSite::map_meta_cap()):
 *
 * - the platform plugins, always (PLATFORM_PLUGINS);
 * - network-active plugins: core hides them on the screen already, but its
 *   REST list shows them to site administrators.
 *
 * A plugin hidden while active stays active. Mody does nothing
 * plugin-specific: activation is core's.
 *
 * The capability covers core's paths (the screen, bulk actions, REST).
 * Other plugins have their own routes that check only `activate_plugins`
 * and call activate_plugin() themselves: Spectra's onboarding API
 * (`one-onboarding/v1/activate-plugin`, any installed plugin by slug) was
 * found on 2026-11-08. keep_hidden() closes all of them at the option every
 * activation writes.
 */
final class MemberPlugins {

	/**
	 * The platform's own plugins, never shown to members: Spectra (a MU plugin
	 * since M3-11, listed for any leftover copy in plugins/), the `ai` plugin and its companions (curated and locked by
	 * M4-03; mcp-adapter would open MCP on a member's site), Wordfence, and the
	 * dev tools Query Monitor and User Switching (never in production). Listed
	 * even when network-active, in case that changes.
	 */
	public const PLATFORM_PLUGINS = array( 'ai', 'ai-provider-for-ollama', 'mcp-adapter', 'query-monitor', 'spectra-blocks', 'user-switching', 'wordfence' );

	/**
	 * A plugin's slug: its directory, or its file name for a single-file plugin.
	 *
	 * @param string $file Plugin file, relative to the plugins directory (`mcp-adapter/mcp-adapter.php`).
	 */
	public static function slug( string $file ): string {
		$dir = dirname( $file );
		return '.' === $dir ? basename( $file, '.php' ) : $dir;
	}

	/**
	 * Whether members never see a plugin.
	 *
	 * @param string $file Plugin file, relative to the plugins directory.
	 */
	public static function is_hidden( string $file ): bool {
		if ( in_array( self::slug( $file ), self::PLATFORM_PLUGINS, true ) ) {
			return true;
		}
		return isset( ( (array) get_site_option( 'active_sitewide_plugins', array() ) )[ $file ] );
	}

	/**
	 * Filters `pre_update_option_active_plugins`: during a logged-in member's
	 * request on a member site, no code path can switch a hidden plugin on or
	 * off. Hidden plugins keep the state they had; the rest of the change
	 * goes through. WP-CLI, cron without a user and super admins are not
	 * affected, and neither is a hidden plugin whose files are gone (core
	 * deactivates those).
	 *
	 * @param mixed $value     The new list of active plugin files.
	 * @param mixed $old_value The list before.
	 * @return mixed
	 */
	public static function keep_hidden( $value, $old_value ) {
		$user = get_current_user_id();
		if ( ! is_array( $value ) || ! $user || ! MemberSite::applies_to( $user ) ) {
			return $value;
		}
		$locked = static fn( $file ): bool => is_string( $file ) && self::is_hidden( $file ) && file_exists( WP_PLUGIN_DIR . '/' . $file );
		$kept   = array_filter( $value, static fn( $file ): bool => ! $locked( $file ) );
		$kept   = array_merge( $kept, array_filter( is_array( $old_value ) ? $old_value : array(), $locked ) );
		$kept   = array_values( array_unique( $kept ) );
		sort( $kept ); // As core stores it.
		return $kept;
	}

	/**
	 * Filters `all_plugins` (the Plugins screen's list and counts): hidden
	 * plugins are left out for members on member sites.
	 *
	 * @param array<string, array<string, mixed>> $plugins Installed plugins, by file.
	 * @return array<string, array<string, mixed>>
	 */
	public static function filter_screen( $plugins ) {
		if ( ! is_array( $plugins ) || ! MemberSite::applies_to( get_current_user_id() ) ) {
			return $plugins;
		}
		return array_filter( $plugins, static fn( $file ): bool => ! self::is_hidden( (string) $file ), ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Filters `rest_pre_dispatch`: for members on member sites, a hidden
	 * plugin's route (`/wp/v2/plugins/<plugin>`) answers as if it were not
	 * installed, whatever the method.
	 *
	 * @param mixed           $result  A response already chosen, or null.
	 * @param mixed           $server  The REST server.
	 * @param WP_REST_Request $request The request.
	 * @return mixed
	 */
	public static function refuse_rest_item( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}
		// The REST server matches routes regardless of case (`/wp/v2/Plugins`), so this does too.
		if ( ! preg_match( '#^/wp/v2/plugins/(.+)$#i', $request->get_route(), $match ) || ! MemberSite::applies_to( get_current_user_id() ) ) {
			return $result;
		}
		if ( self::is_hidden( $match[1] . '.php' ) ) {
			return new WP_Error( 'rest_plugin_not_found', __( 'Plugin not found.' ), array( 'status' => 404 ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own message.
		}
		return $result;
	}

	/**
	 * Filters `rest_request_after_callbacks`: for members on member sites,
	 * the plugin list (`GET /wp/v2/plugins`) leaves hidden plugins out. This
	 * filter runs for internal requests too (`rest_do_request()`, the
	 * editor's preloading), which `rest_post_dispatch` does not.
	 *
	 * @param mixed           $response The response.
	 * @param mixed           $handler  The route handler.
	 * @param WP_REST_Request $request  The request.
	 * @return mixed
	 */
	public static function filter_rest_list( $response, $handler, $request ) {
		if ( ! $response instanceof WP_HTTP_Response || ! $request instanceof WP_REST_Request || '/wp/v2/plugins' !== strtolower( untrailingslashit( (string) $request->get_route() ) ) || 'GET' !== $request->get_method() ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || ! MemberSite::applies_to( get_current_user_id() ) ) {
			return $response;
		}
		$response->set_data(
			array_values(
				array_filter(
					$data,
					static fn( $item ): bool => ! is_array( $item ) || ! isset( $item['plugin'] ) || ! self::is_hidden( $item['plugin'] . '.php' )
				)
			)
		);
		return $response;
	}
}
