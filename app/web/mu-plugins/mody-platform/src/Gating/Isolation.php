<?php
/**
 * Keeps member sites apart from each other and from the network (security pass, 2026-11-12).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Gating;

use Mody\Platform\Provisioning\Quota;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * The hooks of the isolation group of the security pass
 * (tickets/M5-01-hardening-launch.md). Each one closes a way for a member, who
 * is the administrator of their own site, to reach past it:
 *
 * - **Script on a member's site.** Spectra prints a block's `spectraCustomJS`
 *   as inline script (SpectraGuard removes it). The block that carried it
 *   past the save-time check, `core/legacy-widget`, is unregistered on member
 *   sites and the widget routes that make its signed instances are refused:
 *   an FSE site has no widget areas. Members share the network's login
 *   cookie, so script on their site runs as any super admin who opens it.
 * - **Application Passwords on a member's host.** The screen that creates one
 *   (`authorize-application.php`) opens on the main site only: script on a
 *   member's site could otherwise create one for a super admin who visits.
 * - **Deleting the site.** `delete_site` is refused (MemberSite); the screen
 *   says so kindly (the refusal of the page carries the message).
 * - **The plan's site count.** Sites are counted by owner (Quota).
 * - **Mail and pictures.** MemberMail and ImageLimits.
 *
 * Super admins are not affected by any of it.
 */
final class Isolation {

	/** The widget REST routes members never use: the widgets themselves, and the ones that make or draw a signed widget instance. */
	private const WIDGET_ROUTES = '#^/wp/v2/(widgets|widget-types/[^/]+/(encode|render))(/|$)#';

	/** Blocks that carry widgets, unregistered on member sites. */
	public const WIDGET_BLOCKS = array( 'core/legacy-widget' );

	/**
	 * Adds every hook of the group. Called once, from the bootstrap.
	 */
	public static function register(): void {
		// Script.
		add_action( 'init', array( self::class, 'unregister_widget_blocks' ), PHP_INT_MAX );
		add_filter( 'rest_pre_dispatch', array( self::class, 'refuse_widget_routes' ), 10, 3 );
		add_action( 'load-authorize-application.php', array( self::class, 'redirect_authorize_application' ) );

		// Deleting the site: the screen is refused before its own hook runs (the capability is gone), so the message goes in the refusal.
		add_action( 'admin_page_access_denied', array( self::class, 'refuse_delete_site' ) );

		// The plan's sites are the ones a member owns.
		add_action( 'wp_initialize_site', array( Quota::class, 'on_initialize_site' ), 10, 2 );
		add_action( 'admin_init', array( Quota::class, 'backfill' ) );

		// Mail the platform sends for a site.
		add_filter( 'pre_update_option_new_admin_email', array( MemberMail::class, 'limit_admin_email_changes' ), 10, 2 );
		add_filter( 'new_admin_email_subject', array( MemberMail::class, 'admin_email_subject' ) );
		add_filter( 'new_admin_email_content', array( MemberMail::class, 'admin_email_content' ) );
		add_filter( 'invited_user_email', array( MemberMail::class, 'invitation_email' ) );
		add_action( 'load-user-new.php', array( MemberMail::class, 'handle_invitation' ) );

		// Pictures.
		add_filter( 'wp_handle_upload_prefilter', array( ImageLimits::class, 'prefilter' ) );
		add_filter( 'wp_handle_sideload_prefilter', array( ImageLimits::class, 'prefilter' ) );
		add_filter( 'wp_image_editors', array( ImageLimits::class, 'limit_imagick' ) );
	}

	/**
	 * Hooked to `init` (last): the widget blocks are not blocks of a member
	 * site. One that is already stored renders nothing.
	 */
	public static function unregister_widget_blocks(): void {
		if ( ! MemberSite::is_member_site() ) {
			return;
		}
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( self::WIDGET_BLOCKS as $name ) {
			if ( $registry->is_registered( $name ) ) {
				$registry->unregister( $name );
			}
		}
	}

	/**
	 * Filters `rest_pre_dispatch`: members get no widget routes, the ones that
	 * store widgets and the ones that sign or draw a widget instance.
	 *
	 * @param mixed           $result  The response so far (null: dispatch).
	 * @param mixed           $server  The REST server.
	 * @param WP_REST_Request $request The request.
	 * @return mixed
	 */
	public static function refuse_widget_routes( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || ! $request instanceof WP_REST_Request || ! MemberSite::applies_to( get_current_user_id() ) ) {
			return $result;
		}
		// The REST server matches routes regardless of case, so this does too.
		if ( preg_match( self::WIDGET_ROUTES, strtolower( untrailingslashit( (string) $request->get_route() ) ) ) ) {
			return new WP_Error( 'mody_hidden', __( 'This is not available.', 'mody_cloud' ), array( 'status' => 403 ) );
		}
		return $result;
	}

	/**
	 * Hooked to `load-authorize-application.php`: on a member's host, the same
	 * screen on the main site, with the same request.
	 */
	public static function redirect_authorize_application(): void {
		if ( ! MemberSite::is_member_site() ) {
			return;
		}
		$query = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passed on in a redirect to our own main site, never output.
		wp_safe_redirect( get_admin_url( get_main_site_id(), 'authorize-application.php' ) . ( '' !== $query ? '?' . $query : '' ) );
		exit;
	}

	/**
	 * Hooked to `admin_page_access_denied`: for Tools -> Delete Site, said
	 * kindly. The screen is already out of the menu and refused, because
	 * `delete_site` is (MemberSite); WordPress's own refusal would say only
	 * that the member may not open the page, and this is what someone who
	 * types its address reads instead.
	 */
	public static function refuse_delete_site(): void {
		global $pagenow;
		if ( 'ms-delete-site.php' !== $pagenow || ! MemberSite::applies_to( get_current_user_id() ) ) {
			return;
		}
		wp_die(
			esc_html__( 'Deleting a site is not available from here yet. We are building it so that you can download your whole site first and nothing is lost by accident. If you need your site removed in the meantime, please contact us.', 'mody_cloud' ),
			esc_html__( 'Deleting your site', 'mody_cloud' ),
			array(
				'response'  => 403,
				'back_link' => true,
			)
		);
	}
}
