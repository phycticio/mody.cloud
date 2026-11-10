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
		add_filter( 'authenticate', array( self::class, 'check_login' ), 99 );
		add_action( 'lostpassword_post', array( self::class, 'check_lost_password' ) );
		add_action( 'comment_form_after_fields', array( self::class, 'print_widget' ) ); // Visitors only: logged-in users see no fields.
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
		if ( ! self::passed() ) {
			return new WP_Error( 'mody_altcha_failed', Altcha::failed_message() );
		}
		return $user;
	}

	/**
	 * `lostpassword_post`: the lost password form needs a solution.
	 *
	 * @param WP_Error $errors The form's errors.
	 */
	public static function check_lost_password( $errors ): void {
		if ( $errors instanceof WP_Error && self::on_login_page() && ! self::passed() ) {
			$errors->add( 'mody_altcha_failed', Altcha::failed_message() );
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
