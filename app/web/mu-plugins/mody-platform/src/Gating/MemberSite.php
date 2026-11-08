<?php
/**
 * Member sites: capability gating and editor-first redirects (ticket M4-01).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Gating;

use Mody\Platform\SiteEditor;

defined( 'ABSPATH' ) || exit;

/**
 * What a member may do on their own site (mvp-spec sections 9 and 10).
 *
 * Decided 2026-11-07: every site but the main one is a member site, and the
 * rules apply to everyone there except super admins, who keep full rights
 * to fix things.
 *
 * - **Plugins and themes:** no install, upload, update, delete, edit or
 *   theme switch, through any screen or REST route, because the
 *   capabilities themselves are refused. Content, media, users, settings,
 *   Spectra and Global Styles stay.
 * - **Plugins on core's Plugins screen** (M4-02, decided 2026-11-08):
 *   members activate and deactivate, for their own site, every installed
 *   plugin that is not hidden (MemberPlugins). Core's network switch for
 *   that screen ("Enable administration menus: Plugins") is not needed.
 * - **Editor first:** pages, patterns and navigation menus open in the Site
 *   Editor. WordPress 7.1's Site Editor has no route for posts, so posts
 *   keep the block editor (post.php), decided 2026-11-07.
 */
final class MemberSite {

	/** Capabilities a member never has on a member site. */
	public const BLOCKED_CAPS = array(
		'install_plugins',
		'upload_plugins',
		'resume_plugin',
		'update_plugins',
		'delete_plugins',
		'edit_plugins',
		'switch_themes',
		'install_themes',
		'upload_themes',
		'update_themes',
		'delete_themes',
		'edit_themes',
		'resume_theme',
	);

	/**
	 * The Plugins screen and its bulk actions: allowed to a member site's
	 * administrators (who hold `activate_plugins`), without core's network switch.
	 */
	public const PLUGIN_SCREEN_CAPS = array( 'activate_plugins', 'deactivate_plugins' );

	/** Switching one plugin (single, bulk and REST all check it): allowed for visible plugins only. */
	public const PLUGIN_CAPS = array( 'activate_plugin', 'deactivate_plugin' );

	/** Post types the Site Editor edits: their route for one item, and their list. */
	private const ROUTES = array(
		'page'          => array( '/page/', '/page' ),
		'wp_block'      => array( '/wp_block/', '/pattern' ),
		'wp_navigation' => array( '/wp_navigation/', '/navigation' ),
	);

	/**
	 * Whether a site is a member site: any site of the network but the main one.
	 *
	 * @param int $site_id Site ID; 0 for the current site.
	 */
	public static function is_member_site( int $site_id = 0 ): bool {
		return is_multisite() && ! is_main_site( $site_id ? $site_id : null );
	}

	/**
	 * Whether the rules apply to a user on the current site.
	 *
	 * @param int $user_id User ID.
	 */
	public static function applies_to( int $user_id ): bool {
		return self::is_member_site() && ! is_super_admin( $user_id );
	}

	/**
	 * Filters `map_meta_cap`, for members on member sites: refuses the
	 * blocked plugin and theme capabilities, opens the Plugins screen, and
	 * allows switching a plugin only when it is not hidden.
	 *
	 * @param string[] $caps    The primitive capabilities required.
	 * @param string   $cap     The capability checked.
	 * @param int      $user_id The user.
	 * @param mixed[]  $args    Extra arguments: the plugin file, for activate_plugin and deactivate_plugin.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args = array() ) {
		$blocked = in_array( $cap, self::BLOCKED_CAPS, true );
		$screen  = in_array( $cap, self::PLUGIN_SCREEN_CAPS, true );
		$plugin  = in_array( $cap, self::PLUGIN_CAPS, true );
		if ( ( ! $blocked && ! $screen && ! $plugin ) || ! self::applies_to( (int) $user_id ) ) {
			return $caps;
		}
		if ( $blocked ) {
			return array( 'do_not_allow' );
		}
		if ( $plugin ) {
			$file = isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : '';
			if ( '' === $file || MemberPlugins::is_hidden( $file ) ) {
				return array( 'do_not_allow' );
			}
		}
		// Core adds manage_network_plugins unless the network's Plugins menu switch is on.
		return array( 'activate_plugins' );
	}

	/**
	 * Where an editor screen should go instead: the Site Editor, or '' to stay.
	 *
	 * @param string               $screen `post.php` or `post-new.php`.
	 * @param array<string, mixed> $query  The screen's query arguments.
	 */
	public static function editor_target( string $screen, array $query ): string {
		if ( 'post.php' === $screen ) {
			$post_id = absint( $query['post'] ?? 0 );
			$type    = $post_id ? get_post_type( $post_id ) : '';
			if ( 'edit' !== ( $query['action'] ?? '' ) || ! isset( self::ROUTES[ $type ] ) ) {
				return '';
			}
			return SiteEditor::url( self::ROUTES[ $type ][0] . $post_id, true );
		}

		if ( 'post-new.php' === $screen ) {
			$type = sanitize_key( (string) ( $query['post_type'] ?? 'post' ) );
			return isset( self::ROUTES[ $type ] ) ? SiteEditor::url( self::ROUTES[ $type ][1] ) : '';
		}
		return '';
	}

	/**
	 * Hooked to `load-post.php` and `load-post-new.php`: sends members to the
	 * Site Editor. Only page views (GET): saving, trashing and the like stay.
	 */
	public static function redirect_editor(): void {
		global $pagenow;
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! wp_is_block_theme() || ! self::applies_to( get_current_user_id() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: picks a screen to show.
		$target = self::editor_target( (string) $pagenow, wp_unslash( $_GET ) );
		if ( '' !== $target ) {
			wp_safe_redirect( $target );
			exit;
		}
	}
}
