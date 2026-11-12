<?php
/**
 * A new member's first steps: their first password opens their site's
 * Identity screen, and the editor tour runs there (ticket M1-03).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Onboarding;

use Mody\Platform\Members\Registration;
use Mody\Platform\SiteEditor;
use WP_REST_Response;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Where a member is in their onboarding: the user meta META.
 *
 * - `password`: set at signup (Members\Registration). The member has not
 *   chosen a password yet; the welcome email holds the link to WordPress's
 *   reset screen.
 * - `tour`: the password is set. after_password_reset() logged them in and
 *   opened their site's Identity screen, where EditorTour runs until they
 *   finish or skip it.
 * - `done`: the tour was finished or skipped. The member sets it through
 *   `wp/v2/users/me` (the meta is registered in REST; only the user themself
 *   can write it, and hide_stage() shows it to nobody else).
 *
 * Users without the meta (everyone before 2026-11-08, super admins) never
 * see the tour unless they ask for it with `?mody-tour=start`.
 */
final class Onboarding {

	/** User meta: the onboarding stage. */
	public const META = 'mody_onboarding';

	/** Stage: no password chosen yet. */
	public const STAGE_PASSWORD = 'password';

	/** Stage: the editor tour is pending. */
	public const STAGE_TOUR = 'tour';

	/** Stage: the tour was finished or skipped. */
	public const STAGE_DONE = 'done';

	/** URL parameter that starts the tour for anyone: `mody-tour=start`. */
	public const TOUR_PARAM = 'mody-tour';

	/**
	 * `init`: the stage in REST, writable by the user only (hide_stage() makes it
	 * readable by the user only).
	 */
	public static function register_meta(): void {
		register_meta(
			'user',
			self::META,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitize_stage' ),
				'auth_callback'     => static fn( bool $allowed, string $key, int $object_id, int $user_id ): bool => $object_id === $user_id,
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => array( '', self::STAGE_PASSWORD, self::STAGE_TOUR, self::STAGE_DONE ),
					),
				),
			)
		);
	}

	/**
	 * `rest_prepare_user`: a user's stage is theirs alone. Core's meta field
	 * would show it to anyone who can read the user, and the 'password' stage
	 * marks accounts that have not chosen a password yet.
	 *
	 * @param mixed $response The user's REST response.
	 * @param mixed $user     The user.
	 * @param mixed $request  The request.
	 * @return mixed
	 */
	public static function hide_stage( $response, $user, $request = null ) {
		unset( $request );
		if ( $response instanceof WP_REST_Response && $user instanceof WP_User && get_current_user_id() !== $user->ID ) {
			$data = $response->get_data();
			if ( is_array( $data ) && isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
				unset( $data['meta'][ self::META ] );
				$response->set_data( $data );
			}
		}
		return $response;
	}

	/**
	 * Keeps the stage to one of the known values.
	 *
	 * @param mixed $value The stage.
	 */
	public static function sanitize_stage( $value ): string {
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, array( self::STAGE_PASSWORD, self::STAGE_TOUR, self::STAGE_DONE ), true ) ? $value : '';
	}

	/**
	 * Whether a member's editor tour is pending.
	 *
	 * @param int $user_id User ID.
	 */
	public static function tour_pending( int $user_id ): bool {
		return $user_id > 0 && self::STAGE_TOUR === get_user_meta( $user_id, self::META, true );
	}

	/**
	 * `after_password_reset`: a member's first password moves them to the
	 * tour. On WordPress's reset screen it also logs them in and opens their
	 * site's Identity screen, instead of "Your password has been reset. Log
	 * in". Later resets behave as in WordPress.
	 *
	 * @param WP_User $user The member.
	 */
	public static function after_password_reset( WP_User $user ): void {
		if ( self::STAGE_PASSWORD !== get_user_meta( $user->ID, self::META, true ) ) {
			return;
		}
		update_user_meta( $user->ID, self::META, self::STAGE_TOUR );
		if ( 'wp-login.php' !== ( $GLOBALS['pagenow'] ?? '' ) || ( defined( 'WP_CLI' ) && WP_CLI ) || headers_sent() ) {
			return;
		}

		// wp-login.php clears its reset cookie after this hook; the redirect below comes first.
		$path = isset( $_SERVER['REQUEST_URI'] ) ? strtok( sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?' ) : '';
		setcookie( 'wp-resetpass-' . COOKIEHASH, ' ', time() - YEAR_IN_SECONDS, (string) $path, COOKIE_DOMAIN, is_ssl(), true ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- As core does.

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		/** This action is documented in wp-includes/user.php */
		do_action( 'wp_login', $user->user_login, $user );
		wp_redirect( self::first_url( $user->ID ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Built here: one of the network's own sites.
		exit;
	}

	/**
	 * The member's first screen: their first site's Identity screen in the
	 * Site Editor, or the Mody dashboard when they have no site (its
	 * creation failed; the wizard there offers Try again).
	 *
	 * @param int $user_id User ID.
	 */
	public static function first_url( int $user_id ): string {
		$site_id = self::first_site( $user_id );
		if ( ! $site_id ) {
			return Registration::dashboard_url();
		}
		switch_to_blog( $site_id );
		$url = SiteEditor::url( '/identity' );
		restore_current_blog();
		return $url;
	}

	/**
	 * The oldest member site a user administers, or 0.
	 *
	 * @param int $user_id User ID.
	 */
	public static function first_site( int $user_id ): int {
		global $wpdb;
		$sites = array();
		foreach ( get_blogs_of_user( $user_id ) as $site ) {
			$id   = (int) $site->userblog_id;
			$caps = get_user_meta( $user_id, $wpdb->get_blog_prefix( $id ) . 'capabilities', true );
			if ( ! is_main_site( $id ) && is_array( $caps ) && ! empty( $caps['administrator'] ) ) {
				$sites[] = $id;
			}
		}
		return $sites ? min( $sites ) : 0;
	}
}
