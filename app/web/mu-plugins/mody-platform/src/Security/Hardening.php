<?php
/**
 * Signup and login hardening (the security pass of 2026-11-12,
 * tickets/M5-01-hardening-launch.md): the platform's own keys and salts,
 * XML-RPC off, who can learn a member's login, passwords of at least ten
 * characters, and the rate limit on the analytics beacon.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Security;

use Mody\Platform\Onboarding\Onboarding;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * One `register()` for the hooks of this group; the limits themselves are in
 * RateLimit, the secrets in Secrets, ALTCHA's in Altcha and FormGuard.
 */
final class Hardening {

	/** The shortest password accepted on the reset screen and in profiles. */
	public const MIN_PASSWORD = 10;

	/**
	 * Hooked from mody-platform.php.
	 */
	public static function register(): void {
		// Keys and salts: this installation's own where wp-config.php still has the committed ones.
		add_filter( 'salt', array( Secrets::class, 'salt' ), 10, 2 );

		// XML-RPC: nothing uses it (the editors use REST), and it logs in without ALTCHA.
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', '__return_empty_array' );
		add_filter( 'wp_headers', array( self::class, 'drop_pingback_header' ) );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );

		// Members' logins: not in public lists.
		add_filter( 'rest_endpoints', array( self::class, 'drop_users_routes' ) );
		add_filter( 'rest_pre_dispatch', array( self::class, 'refuse_users' ), 10, 3 );
		add_action( 'template_redirect', array( self::class, 'redirect_author_id' ), 0 );
		add_filter( 'wp_sitemaps_add_provider', array( self::class, 'drop_users_sitemap' ), 10, 2 );
		add_filter( 'oembed_response_data', array( self::class, 'hide_oembed_author' ) );
		add_filter( 'rest_prepare_user', array( Onboarding::class, 'hide_stage' ), 10, 3 );

		// Passwords.
		add_action( 'validate_password_reset', array( self::class, 'check_reset_password' ), 10, 2 );
		add_action( 'user_profile_update_errors', array( self::class, 'check_profile_password' ), 10, 3 );

		// mody-analytics works without this plugin: it asks, and we answer with the rate limit.
		add_filter( 'mody_analytics_accept_view', array( self::class, 'accept_view' ) );
	}

	/**
	 * `wp_headers`: no `X-Pingback` header.
	 *
	 * @param array<string, string> $headers The response headers.
	 * @return array<string, string>
	 */
	public static function drop_pingback_header( $headers ) {
		if ( is_array( $headers ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}

	/**
	 * Whether a REST route (or a route pattern) is the users' list or something
	 * under it. Case-insensitive, as core's own matching is: its router tests
	 * every route with the `i` flag, so `/WP/v2/Users` and `/wp/v2/USERS/1`
	 * reach the same handlers as the lower-case address.
	 *
	 * @param string $route A route such as `/wp/v2/users/(?P<id>[\d]+)` or a requested path.
	 */
	public static function is_users_route( string $route ): bool {
		return 1 === preg_match( '#^/wp/v2/users(/|$)#i', $route );
	}

	/**
	 * `rest_endpoints`: for a visitor who is not logged in the users' routes
	 * do not exist, whatever way the address is written (`/wp-json/...`,
	 * `?rest_route=`, any capitals, a batch request, an embedded link).
	 * refuse_users() below answers 401 first on the ordinary routes; this
	 * removes the routes themselves, so nothing is left to match.
	 *
	 * @param mixed $endpoints The registered routes.
	 * @return mixed
	 */
	public static function drop_users_routes( $endpoints ) {
		if ( ! is_array( $endpoints ) || is_user_logged_in() ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( self::is_users_route( (string) $route ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	/**
	 * `rest_pre_dispatch`: nobody who is not logged in reads the list of
	 * users or one user (core shows every author with a published post, with
	 * the slug, which is a login name on older accounts). Logged-in users
	 * keep what core gives them (the editors list authors). The second guard
	 * after drop_users_routes(), on the route as it was requested.
	 *
	 * @param mixed           $result  A result already set by another filter.
	 * @param mixed           $server  The REST server.
	 * @param WP_REST_Request $request The request.
	 * @return mixed
	 */
	public static function refuse_users( $result, $server, $request ) {
		if ( null === $result && ! is_user_logged_in() && $request instanceof WP_REST_Request && self::is_users_route( $request->get_route() ) ) {
			return new WP_Error( 'rest_user_cannot_view', __( 'Sorry, you are not allowed to list users.', 'mody_cloud' ), array( 'status' => 401 ) );
		}
		return $result;
	}

	/**
	 * `template_redirect`, before core's canonical redirect: `?author=3`
	 * sends visitors to the home page instead of to `/author/<slug>/`, which
	 * is how a number turns into a name.
	 */
	public static function redirect_author_id(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only its presence is read.
		if ( isset( $_GET['author'] ) && ! is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	/**
	 * `wp_sitemaps_add_provider`: no sitemap of authors.
	 *
	 * @param mixed  $provider The sitemap provider.
	 * @param string $name     Its name.
	 * @return mixed
	 */
	public static function drop_users_sitemap( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	}

	/**
	 * `oembed_response_data`: no author name or address in what other sites
	 * read when they embed a page.
	 *
	 * @param mixed $data The oEmbed response.
	 * @return mixed
	 */
	public static function hide_oembed_author( $data ) {
		if ( is_array( $data ) ) {
			unset( $data['author_name'], $data['author_url'] );
		}
		return $data;
	}

	/**
	 * `validate_password_reset`: the first password (the welcome email's
	 * link) and every reset need ten characters, and not the login or the
	 * email. Core only checks that the two fields match; its strength
	 * checkbox is JavaScript.
	 *
	 * @param WP_Error $errors The screen's errors.
	 * @param mixed    $user   The user (a WP_Error when the key is wrong).
	 */
	public static function check_reset_password( $errors, $user ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read by core's reset screen; only measured here.
		$password = isset( $_POST['pass1'] ) && is_string( $_POST['pass1'] ) ? trim( wp_unslash( $_POST['pass1'] ) ) : '';
		if ( '' === $password || ! $errors instanceof WP_Error ) {
			return;
		}
		$message = self::password_problem( $password, $user instanceof \WP_User ? $user->user_login : '', $user instanceof \WP_User ? $user->user_email : '' );
		if ( '' !== $message ) {
			$errors->add( 'mody_password_weak', $message );
		}
	}

	/**
	 * `user_profile_update_errors`: the same for a password set in a profile
	 * or when a user is added (only when one is given).
	 *
	 * @param WP_Error $errors The form's errors.
	 * @param bool     $update Whether it updates a user.
	 * @param object   $user   The user's new data (`user_pass` in plain text when set).
	 */
	public static function check_profile_password( $errors, $update, $user ): void {
		$password = is_object( $user ) && isset( $user->user_pass ) && is_string( $user->user_pass ) ? $user->user_pass : '';
		if ( '' === $password || ! $errors instanceof WP_Error ) {
			return;
		}
		$message = self::password_problem( $password, (string) ( $user->user_login ?? '' ), (string) ( $user->user_email ?? '' ) );
		if ( '' !== $message ) {
			$errors->add( 'mody_password_weak', $message );
		}
	}

	/**
	 * What is wrong with a password, in words; '' when nothing is.
	 *
	 * @param string $password The password.
	 * @param string $login    The account's login name, or ''.
	 * @param string $email    The account's email, or ''.
	 */
	public static function password_problem( string $password, string $login = '', string $email = '' ): string {
		if ( mb_strlen( $password ) < self::MIN_PASSWORD ) {
			/* translators: %d: the fewest characters a password can have. */
			return sprintf( __( 'Please choose a password with at least %d characters. Three or four words in a row make a strong one that is easy to remember.', 'mody_cloud' ), self::MIN_PASSWORD );
		}
		foreach ( array( $login, $email ) as $own ) {
			if ( '' !== $own && 0 === strcasecmp( $password, $own ) ) {
				return __( 'Please choose a password that is not your login or your email address.', 'mody_cloud' );
			}
		}
		return '';
	}

	/**
	 * `mody_analytics_accept_view`: a visitor's views are counted up to a
	 * rate (RateLimit, `beacon`); past it they are dropped without a word.
	 *
	 * @param mixed $accept Whether the view counts so far.
	 */
	public static function accept_view( $accept ): bool {
		return false !== $accept && 0 === RateLimit::hit_address( 'beacon' );
	}
}
