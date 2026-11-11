<?php
/**
 * One profile for the whole network, edited on the main site only
 * (tickets/network-profile-local-avatars.md).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Users;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Decided by the maintainer on 2026-11-11: "the information in all sites is
 * always synced and only maintained in the main site. What is on the main
 * site (site id 1) is the truth."
 *
 * WordPress keeps a user and their profile fields once for the network
 * already (`wp_users`, `wp_usermeta`). What changes is where they are edited:
 * every other site sends Profile and Edit User to the main site, and refuses
 * changes to users through its REST API. The login cookie covers every
 * subdomain (wp-config.php, COOKIE_DOMAIN), so the member stays logged in.
 */
final class NetworkProfile {

	/**
	 * Whether the current request is on a site that is not the truth.
	 */
	private static function elsewhere(): bool {
		return is_multisite() && ! is_main_site() && ! is_network_admin() && ! is_user_admin();
	}

	/**
	 * Where a user's profile is edited: the network's user dashboard on the
	 * main site for oneself (it opens for anyone logged in, with or without
	 * a role there), the network's Edit User screen for others.
	 *
	 * @param int $user_id The user; 0 for the current one.
	 */
	public static function url( int $user_id = 0 ): string {
		if ( $user_id && get_current_user_id() !== $user_id ) {
			return network_admin_url( 'user-edit.php?user_id=' . $user_id );
		}
		return user_admin_url( 'profile.php' );
	}

	/**
	 * Hooked to `load-profile.php` and `load-user-edit.php`: on any other site,
	 * the main site's screen.
	 */
	public static function redirect(): void {
		if ( ! self::elsewhere() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which user is asked for.
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		wp_safe_redirect( self::url( $user_id ) );
		exit;
	}

	/**
	 * Filters `edit_profile_url` (the toolbar's Edit Profile, core's links):
	 * the main site's screen.
	 *
	 * @param string $url     The URL.
	 * @param int    $user_id The user.
	 */
	public static function profile_url( $url, $user_id ): string {
		return is_multisite() ? self::url( (int) $user_id === get_current_user_id() ? 0 : (int) $user_id ) : (string) $url;
	}

	/**
	 * Filters `allowed_redirect_hosts`: the main site, for the redirect above.
	 *
	 * @param string[] $hosts The allowed hosts.
	 * @return string[]
	 */
	public static function allow_main_host( $hosts ): array {
		$hosts   = (array) $hosts;
		$hosts[] = (string) get_network()->domain;
		return $hosts;
	}

	/** Request fields that are not the profile: user meta (the editor's preferences, the tour's stage) and request plumbing. */
	private const NOT_PROFILE = array( 'meta', 'id', 'context', '_locale', '_method' );

	/**
	 * Filters `wp_is_application_passwords_available_for_user`: only super
	 * admins have Application Passwords (rule 6). Members have no use for
	 * them (MCP and the REST API's admin routes are locked for them), and the
	 * section on the network profile could not work for them anyway.
	 *
	 * @param bool     $available Whether they are available.
	 * @param \WP_User $user      The user.
	 */
	public static function application_passwords( $available, $user ): bool {
		return (bool) $available && $user instanceof \WP_User && is_super_admin( $user->ID );
	}

	/**
	 * Filters `rest_pre_dispatch`: on any other site, a user's profile is not
	 * changed through the REST API. Reading stays (the editors list authors),
	 * and so do changes to user meta only: the block editor saves its
	 * preferences there, and the editor tour its stage (Onboarding).
	 *
	 * @param mixed           $result  The response so far (null: dispatch).
	 * @param mixed           $server  The REST server.
	 * @param WP_REST_Request $request The request.
	 * @return mixed
	 */
	public static function refuse_rest_writes( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || ! $request instanceof WP_REST_Request || ! is_multisite() || is_main_site() ) {
			return $result;
		}
		// The REST server matches routes regardless of case, so this does too.
		$route = strtolower( untrailingslashit( (string) $request->get_route() ) );
		if ( in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ) || ( '/wp/v2/users' !== $route && ! str_starts_with( $route, '/wp/v2/users/' ) ) ) {
			return $result;
		}
		$fields  = array_keys( (array) $request->get_params() ); // Every source: query, form (PUT and PATCH too), JSON.
		$profile = array_diff( $fields, self::NOT_PROFILE );
		$meta    = (bool) preg_match( '#^/wp/v2/users/(me|\d+)$#', $route ) && 'DELETE' !== $request->get_method();
		if ( ! $meta || $profile ) {
			// A response, not an error: inside /batch/v1 an error here ends in a 500.
			return rest_convert_error_to_response( new WP_Error( 'mody_profile_on_main_site', __( 'Profiles are changed on the main site.', 'mody_cloud' ), array( 'status' => 403 ) ) );
		}
		return $result;
	}
}
