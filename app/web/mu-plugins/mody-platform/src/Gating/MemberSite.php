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
 * - **Plugins and themes:** no install, upload, activation, update, edit or
 *   theme switch, through any screen or REST route, because the
 *   capabilities themselves are refused. Plugins come from the market page
 *   (M4-02, `mody_manage_market`). Content, media, users, settings, Spectra
 *   and Global Styles stay.
 * - **Editor first:** pages, patterns and navigation menus open in the Site
 *   Editor. WordPress 7.1's Site Editor has no route for posts, so posts
 *   keep the block editor (post.php), decided 2026-11-07.
 */
final class MemberSite {

	/** Capabilities a member never has on a member site. */
	public const BLOCKED_CAPS = array(
		'install_plugins',
		'upload_plugins',
		'activate_plugins',
		'activate_plugin',
		'deactivate_plugins',
		'deactivate_plugin',
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

	/** Who may use the plugin market (M4-02): a member site's administrators. */
	public const MARKET_CAP = 'mody_manage_market';

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
	 * Filters `map_meta_cap`: refuses the plugin and theme capabilities to
	 * members on member sites, and maps the market capability.
	 *
	 * @param string[] $caps    The primitive capabilities required.
	 * @param string   $cap     The capability checked.
	 * @param int      $user_id The user.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id ) {
		if ( self::MARKET_CAP === $cap ) {
			return self::is_member_site() ? array( 'manage_options' ) : array( 'do_not_allow' );
		}
		if ( in_array( $cap, self::BLOCKED_CAPS, true ) && self::applies_to( (int) $user_id ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
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
