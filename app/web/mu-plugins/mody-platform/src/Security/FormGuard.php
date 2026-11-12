<?php
/**
 * ALTCHA on WordPress's public forms, on every site of the network
 * (tickets/antibot-altcha.md): log in, lost password, and comments by
 * visitors. The signup chat checks it in Members\Registration.
 *
 * Only requests from those forms are checked: WP-CLI, the REST API,
 * application passwords, wp-admin's "Send password reset" and comments by
 * logged-in users are left alone.
 *
 * The same three forms have a rate limit per address (Security\RateLimit,
 * the security pass of 2026-11-12), counted before ALTCHA is checked so a
 * refused request does not use up a solution:
 * - log in: failed logins counted three ways, and refused (with the right
 *   password too) once one of them is full:
 *   1. per address (`login_address`, ten in 15 minutes, every account
 *      together): the limit that stops guessing. It only ever stops the
 *      address that made the failures;
 *   2. per account at one address (`login_pair`, five in 15 minutes, the
 *      key is the account and the address): one stranger's failures pause
 *      that account only for their own address, so the owner, anywhere else,
 *      logs in as usual. Nobody can lock an account out for everyone from one
 *      address;
 *   3. per account, from everywhere (`login_account`, 100 in 15 minutes): a
 *      last resort against guesses spread over many addresses. An attacker
 *      could use it to lock an account out, so it never refuses a browser the
 *      owner has logged in from before (a signed cookie, remember_device()),
 *      only a browser the account has not seen. The owner on a new device
 *      during such an attack uses "Lost your password?".
 *   A wrong password counts for an account only when the puzzle was solved,
 *   so a script without solutions cannot pause anyone. The counts are filed
 *   under the account's id and a piece of its password hash, so **choosing a
 *   new password (reset link, profile, WP-CLI) starts every account count
 *   over**, which is what the refusal's "or use Lost your password" promises.
 *   A good login clears the account's count at that address;
 * - lost password: requests per address;
 * - comments: comments by visitors per address.
 *
 * A lost-password request without a solution is answered with the antibot
 * message only, never with core's "There is no account with that username or
 * email address", which told anyone whether an address has an account.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Security;

use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the widget to the forms and refuses what they send without a solution.
 */
final class FormGuard {

	/**
	 * This request's verdict, once checked: a solution is used up when
	 * checked, and core checks a comment twice.
	 *
	 * @var bool|null
	 */
	private static ?bool $passed = null;

	/**
	 * Hooked on load.
	 */
	public static function register(): void {
		add_action( 'login_form', array( self::class, 'print_widget' ) );
		add_action( 'lostpassword_form', array( self::class, 'print_widget' ) );
		add_action( 'login_enqueue_scripts', array( self::class, 'login_style' ) );
		add_filter( 'authenticate', array( self::class, 'throttle_login' ), 98, 2 ); // After core's password checks (20 and 30), which would otherwise win: a locked account is refused with the right password too.
		add_filter( 'authenticate', array( self::class, 'check_login' ), 99 );
		add_action( 'wp_login_failed', array( self::class, 'count_failed_login' ), 10, 2 );
		add_action( 'wp_login', array( self::class, 'after_login' ), 10, 2 );
		add_action( 'lostpassword_post', array( self::class, 'throttle_lost_password' ), 5 );
		add_action( 'lostpassword_post', array( self::class, 'check_lost_password' ) );
		add_action( 'comment_form_after_fields', array( self::class, 'print_widget' ) ); // Visitors only: logged-in users see no fields.
		add_action( 'pre_comment_on_post', array( self::class, 'throttle_comment' ) );
		add_filter( 'pre_comment_approved', array( self::class, 'check_comment' ), 99 );
	}

	/**
	 * Prints the widget in a form.
	 */
	public static function print_widget(): void {
		echo '<div class="mody-altcha-field">' . Altcha::widget() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in widget().
	}

	/**
	 * `login_enqueue_scripts`: the widget as wide as the login form's fields.
	 */
	public static function login_style(): void {
		wp_add_inline_style( 'login', '.mody-altcha-field{margin:0 0 16px}.mody-altcha-field altcha-widget{--altcha-max-width:100%;display:block}' );
	}

	/**
	 * Whether this request carries a valid solution. Checked once.
	 */
	private static function passed(): bool {
		self::$passed ??= Altcha::verify( Altcha::posted() );
		return self::$passed;
	}

	/**
	 * Whether this request is a POST from wp-login.php.
	 */
	private static function on_login_page(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		return 'POST' === $method && 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' );
	}

	/**
	 * `authenticate`, last: a login from the form needs a solution, whatever
	 * the password check said.
	 *
	 * @param WP_User|WP_Error|null $user The result so far.
	 * @return WP_User|WP_Error|null
	 */
	public static function check_login( $user ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- wp-login.php's own form has no nonce; only its presence is read.
		if ( ! self::on_login_page() || empty( $_POST['log'] ) ) {
			return $user;
		}
		if ( is_wp_error( $user ) && 'mody_rate_limited' === $user->get_error_code() ) {
			return $user; // Refused for trying too often: no solution is used up.
		}
		if ( ! self::passed() ) {
			return new WP_Error( 'mody_altcha_failed', Altcha::failed_message() );
		}
		return $user;
	}

	/**
	 * `authenticate`, before check_login(): a login from the form while the
	 * address, the account at this address, or (from a browser the account
	 * has not seen) the account as a whole has too many failures is refused,
	 * with the right password too. Nothing is counted here:
	 * count_failed_login() does.
	 *
	 * @param WP_User|WP_Error|null $user     The result so far.
	 * @param string                $username The login or email typed.
	 * @return WP_User|WP_Error|null
	 */
	public static function throttle_login( $user, $username = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- wp-login.php's own form has no nonce; only its presence is read.
		if ( ! self::on_login_page() || empty( $_POST['log'] ) ) {
			return $user;
		}
		$typed   = (string) $username;
		$account = self::find_user( $typed );
		$subject = self::login_subject( $typed, $account );
		$address = ClientAddress::limit_address();

		// Not account-specific: choosing a new password does not lift it, so the message does not say so.
		$wait = RateLimit::wait_address( 'login_address' );
		if ( $wait ) {
			return new WP_Error(
				'mody_rate_limited',
				RateLimit::message( $wait, __( 'Too many failed attempts from this connection. Please try again in %s.', 'mody_cloud' ) )
			);
		}
		// This account at this address, then (from an unknown browser) this account from anywhere.
		$wait = '' !== $address ? RateLimit::wait( 'login_pair', $subject . '|' . $address ) : 0;
		if ( ! $wait && ! ( $account && self::known_device( $account ) ) ) {
			$wait = RateLimit::wait( 'login_account', $subject );
		}
		if ( $wait ) {
			return new WP_Error(
				'mody_rate_limited',
				RateLimit::message( $wait, __( 'Too many failed attempts for this account. Please try again in %s, or use "Lost your password?" below to choose a new password.', 'mody_cloud' ) )
			);
		}
		return $user;
	}

	/**
	 * `wp_login_failed`: counts a failure from the login form for the address,
	 * and for the account (at this address, and as a whole) unless the failure
	 * was the missing solution.
	 *
	 * @param string        $username The login or email typed.
	 * @param WP_Error|null $error    Why it failed.
	 */
	public static function count_failed_login( $username, $error = null ): void {
		if ( ! self::on_login_page() ) {
			return;
		}
		$code = $error instanceof WP_Error ? $error->get_error_code() : '';
		if ( 'mody_rate_limited' === $code ) {
			return; // Refused for trying too often: not another failure, and a window is never extended.
		}
		RateLimit::hit_address( 'login_address' );
		if ( 'mody_altcha_failed' !== $code && is_string( $username ) && '' !== $username ) {
			$subject = self::login_subject( $username, self::find_user( $username ) );
			$address = ClientAddress::limit_address();
			if ( '' !== $address ) {
				RateLimit::hit( 'login_pair', $subject . '|' . $address );
			}
			RateLimit::hit( 'login_account', $subject );
		}
	}

	/**
	 * `wp_login`: a good login from the form clears the account's failures at
	 * this address, and marks the browser as one the account has used (the
	 * account-wide limit does not apply to it).
	 *
	 * @param string       $user_login The login name.
	 * @param WP_User|null $user       The user.
	 */
	public static function after_login( $user_login, $user = null ): void {
		if ( ! self::on_login_page() || ! $user instanceof WP_User ) {
			return;
		}
		$fresh   = get_userdata( $user->ID ); // This login may have rehashed the password.
		$account = $fresh instanceof WP_User ? $fresh : $user;
		$address = ClientAddress::limit_address();
		if ( '' !== $address ) {
			RateLimit::reset( 'login_pair', self::login_subject( (string) $user_login, $account ) . '|' . $address );
		}
		self::remember_device( $account );
	}

	/**
	 * The account a typed login or email belongs to, as core finds it.
	 *
	 * @param string $typed The login or email.
	 */
	private static function find_user( string $typed ): ?WP_User {
		$typed = mb_substr( trim( $typed ), 0, 100 );
		$user  = is_email( $typed ) ? get_user_by( 'email', $typed ) : get_user_by( 'login', $typed );
		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * What an account's failures are filed under: the account (so its login
	 * and its email share one count) and a piece of its stored password hash,
	 * which changes with every new password: a new password starts the
	 * counts over, with nothing to hook. A name that is no account is filed
	 * under the text itself, so nothing shows which names exist.
	 *
	 * @param string       $typed   The login or email typed.
	 * @param WP_User|null $account Its account, from find_user().
	 */
	private static function login_subject( string $typed, ?WP_User $account ): string {
		if ( $account ) {
			return 'user:' . $account->ID . ':' . substr( hash( 'sha256', $account->user_pass ), 0, 12 );
		}
		return 'name:' . strtolower( mb_substr( trim( $typed ), 0, 100 ) );
	}

	/**
	 * The cookie that marks a browser as one this account logged in from:
	 * one per account, because a browser can hold several.
	 *
	 * @param WP_User $user The account.
	 */
	private static function device_cookie( WP_User $user ): string {
		return 'mody_device_' . COOKIEHASH . '_' . $user->ID;
	}

	/**
	 * The cookie's value: a keyed hash of the account and its password hash,
	 * so nobody can make one, and a new password voids every browser's.
	 *
	 * @param WP_User $user The account.
	 */
	private static function device_token( WP_User $user ): string {
		return hash_hmac( 'sha256', 'device:' . $user->ID . ':' . $user->user_pass, Secrets::get( 'rate-limit' ) );
	}

	/**
	 * Marks this browser, for half a year. It holds no personal data and is
	 * only read by the login limits (a strictly necessary security cookie).
	 *
	 * @param WP_User $user The account that just logged in.
	 */
	private static function remember_device( WP_User $user ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::device_cookie( $user ),
			self::device_token( $user ),
			array(
				'expires'  => time() + 180 * DAY_IN_SECONDS,
				'path'     => COOKIEPATH,
				'domain'   => (string) COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Whether this request comes from a browser the account has logged in
	 * from.
	 *
	 * @param WP_User $user The account.
	 */
	private static function known_device( WP_User $user ): bool {
		$value = $_COOKIE[ self::device_cookie( $user ) ] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compared with a hash, never used.
		return is_string( $value ) && '' !== $value && hash_equals( self::device_token( $user ), $value );
	}

	/**
	 * `lostpassword_post`, first: a few requests per address and hour.
	 *
	 * @param WP_Error $errors The form's errors.
	 */
	public static function throttle_lost_password( $errors ): void {
		if ( ! $errors instanceof WP_Error || ! self::on_login_page() ) {
			return;
		}
		$wait = RateLimit::hit_address( 'lost_password' );
		if ( $wait ) {
			self::only_error( $errors, 'mody_rate_limited', RateLimit::message( $wait, __( 'That is a few too many password requests. Please try again in %s.', 'mody_cloud' ) ) );
		}
	}

	/**
	 * `lostpassword_post`: the lost password form needs a solution. Without
	 * one the answer is the antibot message and nothing else: core has
	 * already added "no account with that email", which is not for someone
	 * who has not proved they are a person.
	 *
	 * @param WP_Error $errors The form's errors.
	 */
	public static function check_lost_password( $errors ): void {
		if ( ! $errors instanceof WP_Error || ! self::on_login_page() || in_array( 'mody_rate_limited', $errors->get_error_codes(), true ) ) {
			return;
		}
		if ( ! self::passed() ) {
			self::only_error( $errors, 'mody_altcha_failed', Altcha::failed_message() );
		}
	}

	/**
	 * Replaces every error with one.
	 *
	 * @param WP_Error $errors  The errors.
	 * @param string   $code    The error's code.
	 * @param string   $message The error's message.
	 */
	private static function only_error( WP_Error $errors, string $code, string $message ): void {
		foreach ( $errors->get_error_codes() as $existing ) {
			$errors->remove( $existing );
		}
		$errors->add( $code, $message );
	}

	/**
	 * `pre_comment_on_post`: a visitor posts a few comments per address and
	 * hour (before ALTCHA: a refused comment does not use up a solution).
	 * Logged-in users and comments added by code are not limited.
	 */
	public static function throttle_comment(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		$wait = RateLimit::hit_address( 'comment' );
		if ( $wait ) {
			wp_die(
				'<p>' . esc_html( RateLimit::message( $wait, __( 'You are commenting a little fast. Please try again in %s.', 'mody_cloud' ) ) ) . '</p>',
				esc_html__( 'Comment Submission Failure', 'mody_cloud' ),
				array(
					'response'  => 429,
					'back_link' => true,
				)
			);
		}
	}

	/**
	 * `pre_comment_approved`: a visitor's comment from the form
	 * (wp-comments-post.php) needs a solution.
	 *
	 * @param int|string|WP_Error $approved The comment's status so far.
	 * @return int|string|WP_Error
	 */
	public static function check_comment( $approved ) {
		if ( is_wp_error( $approved ) || ! did_action( 'pre_comment_on_post' ) || is_user_logged_in() ) {
			return $approved;
		}
		if ( ! self::passed() ) {
			return new WP_Error( 'mody_altcha_failed', Altcha::failed_message(), 403 );
		}
		return $approved;
	}
}
