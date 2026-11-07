<?php
/**
 * The `ai` plugin on member sites (ticket M4-03).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Gating;

use WP_Error;
use WP_REST_Request;
use WP_Site;

defined( 'ABSPATH' ) || exit;

/**
 * Curates and locks WordPress's `ai` plugin on member sites (mvp-spec
 * section 8, decision 13; decided by the maintainer on 2026-11-07).
 *
 * - **Pinned configuration.** On member sites every feature switch
 *   (`wpai_feature_<id>_enabled`) and the connector approvals
 *   (`wpai_connector_approvals`) are read from here, whatever is saved, so
 *   members cannot change them and the plugin's settings screen shows them
 *   as they are. A feature not listed in FEATURES is off, so a plugin update
 *   adds nothing on its own.
 * - **Hidden screens.** The plugin's settings, connector approvals, MCP
 *   access, abilities explorer and request log screens are removed and
 *   refused for members, with its status widget on the dashboard and the
 *   notice about pending connector requests (pinned empty).
 * - **Locked routes.** MCP (mcp-adapter's `/mcp` routes) and the plugin's
 *   configuration routes are refused to members over REST.
 * - **The request log table** is created for every new site, so requests
 *   made in its context (provisioning) are logged without database errors.
 *
 * Super admins are not affected by the screens and routes. The pinned
 * values hold for them too: to change the curated list, change FEATURES.
 */
final class AiPlugin {

	/**
	 * The features members get (decided 2026-11-07: all the text features):
	 * on-demand writing, type-ahead, comments, organizing; plus the
	 * plugin's infrastructure (connector approval, request logging for cost
	 * tracking, key encryption). Everything else is off: image generation and
	 * text to speech (the Infomaniak connection is text only), the developer
	 * tools (MCP, WebMCP, abilities explorer, custom abilities) and the
	 * experiments Markdown feeds and C2PA monitor.
	 */
	public const FEATURES = array(
		// On-demand writing.
		'title-generation',
		'excerpt-generation',
		'meta-description',
		'content-resizing',
		'editorial-updates',
		'summarization',
		'content-translation',
		'slug-generation',
		'alt-text-generation',
		// Type-ahead.
		'type-ahead',
		// Comments.
		'comment-moderation',
		'suggest-reply',
		// Organizing.
		'content-classification',
		'block-container-label-generation',
		'editorial-notes',
		'revision-change-summary',
		// Infrastructure.
		'connector-approval',
		'ai-request-logging',
		'key-encryption',
	);

	/** Connector approvals on member sites: the plugin may use Infomaniak. */
	public const APPROVALS = array(
		'ai/ai.php' => array( 'infomaniak' => true ),
	);

	/** The plugin's admin screens (`page` slugs) members do not get. */
	public const SCREENS = array( 'ai-wp-admin', 'ai', 'ai-connector-approval', 'ai-mcp-access', 'ai-abilities-explorer', 'ai-request-logs' );

	/** REST routes refused to members: MCP and the plugin's configuration. */
	public const ROUTES = array( '/mcp', '/ai/v1/mcp', '/ai/v1/connector-approvals', '/ai/v1/settings', '/ai/v1/abilities', '/ai/v1/providers', '/ai/v1/logs' );

	/**
	 * Filters `pre_option` (every option, so the check is cheap): the
	 * pinned values on member sites.
	 *
	 * @param mixed  $pre    The value so far (false: read the database).
	 * @param string $option The option name.
	 * @return mixed
	 */
	public static function pin_option( $pre, $option ) {
		if ( ! is_string( $option ) || ! str_starts_with( $option, 'wpai_' ) || ! MemberSite::is_member_site() ) {
			return $pre;
		}
		if ( 'wpai_connector_approvals' === $option ) {
			return self::APPROVALS;
		}
		if ( 'wpai_connector_approval_pending' === $option ) {
			return array(); // No requests for members to review: the approvals are pinned.
		}
		if ( preg_match( '/^wpai_feature_(.+)_enabled$/', $option, $match ) ) {
			return in_array( $match[1], self::FEATURES, true ) ? '1' : '0';
		}
		return $pre;
	}

	/**
	 * Whether the screens and routes are refused to the current user.
	 */
	public static function locked(): bool {
		return MemberSite::applies_to( get_current_user_id() );
	}

	/**
	 * Hooked to `admin_menu` (late): removes the plugin's screens from every menu.
	 */
	public static function hide_screens(): void {
		global $submenu;
		if ( ! self::locked() || ! is_array( $submenu ) ) {
			return;
		}
		foreach ( $submenu as $parent => $items ) {
			foreach ( (array) $items as $item ) {
				if ( in_array( (string) ( $item[2] ?? '' ), self::SCREENS, true ) ) {
					remove_submenu_page( $parent, $item[2] );
				}
			}
		}
	}

	/**
	 * Hooked to `wp_dashboard_setup` (late): removes the plugin's status widget,
	 * whose links lead to the settings members do not get.
	 */
	public static function hide_widgets(): void {
		if ( self::locked() ) {
			remove_meta_box( 'wpai_status', 'dashboard', 'normal' );
			remove_meta_box( 'wpai_status', 'dashboard', 'side' );
		}
	}

	/**
	 * Hooked to `admin_init`: refuses the plugin's screens when reached by URL.
	 */
	public static function refuse_screens(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which screen is asked for.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( '' !== $page && in_array( $page, self::SCREENS, true ) && self::locked() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'mody_cloud' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Filters `rest_pre_dispatch`: refuses MCP and the plugin's configuration routes to members.
	 *
	 * @param mixed           $result  The response so far (null: dispatch).
	 * @param mixed           $server  The REST server.
	 * @param WP_REST_Request $request The request.
	 * @return mixed
	 */
	public static function lock_routes( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || ! $request instanceof WP_REST_Request || ! self::locked() ) {
			return $result;
		}
		$route = (string) $request->get_route();
		foreach ( self::ROUTES as $locked ) {
			if ( $route === $locked || str_starts_with( $route, $locked . '/' ) ) {
				return new WP_Error( 'mody_locked', __( 'This is not available on your site.', 'mody_cloud' ), array( 'status' => 403 ) );
			}
		}
		return $result;
	}

	/**
	 * Hooked to `wp_initialize_site` (after core sets the site up): creates
	 * the plugin's request log table for the new site.
	 *
	 * @param WP_Site $site The new site.
	 */
	public static function create_log_table( WP_Site $site ): void {
		if ( ! class_exists( \WordPress\AI\Logging\AI_Request_Log_Schema::class ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		( new \WordPress\AI\Logging\AI_Request_Log_Schema() )->maybe_upgrade_table();
		restore_current_blog();
	}
}
