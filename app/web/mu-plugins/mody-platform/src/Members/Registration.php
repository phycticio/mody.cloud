<?php
/**
 * The registration page on the main site: a chat that creates a member's
 * account, trial and first site (mvp-spec section 4, "Signup flow"; tickets
 * M1-01 part 2 and M1-03).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Members;

use Mody\Platform\Agent\ChatController;
use Mody\Platform\Onboarding\Onboarding;
use Mody\Platform\Provisioning\Pipeline;
use Mody\Platform\Security\Altcha;
use Mody\Platform\Wizard\WizardPage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * `mody.cloud/register/`: four questions, then the account, the trial and
 * the site exist, and an email holds the link to choose a password.
 *
 * Decided by the maintainer on 2026-11-08:
 * - **The page** holds the dynamic block `mody/register`, rendered by PHP.
 *   The theme stays free of platform logic.
 * - **A block to place anywhere on the main site** (M1-04, 2026-11-09):
 *   `blocks/register/block.json` gives it every style WordPress offers a
 *   block (colors, button colors, background image and gradient, spacing,
 *   typography, border, shadow, alignment). render() wraps the chat in the
 *   block's wrapper attributes, so they apply on the front end, and
 *   `assets/register.css` makes the chat inherit them. The editor shows a
 *   still preview (`assets/register-block.js`). The block is in the inserter
 *   on the main site only, and renders nothing elsewhere.
 * - **No WordPress role:** the `mody_members` row is the membership. The new
 *   user has no role on the main site and is `administrator` of their sites.
 * - **A chat, and a trial without a card** (M1-03): name, email, the
 *   project's name and its address, one at a time (`assets/register.js`,
 *   no build step). Each answer is checked on the server (check(), through
 *   `mody-platform/v1/register/check`) before the next question. Then
 *   register() creates the user with a random password, starts the 30-day
 *   trial, creates the site (Pipeline, a `starter` site: no model call) and
 *   sends the welcome email with the set-password link. Choosing the
 *   password logs the member in on the site's Identity screen (Onboarding).
 * - **Without JavaScript** the block is a form with the same four fields,
 *   posted to the page and handled on `template_redirect`.
 * - **Antibot** (tickets/antibot-altcha.md): creating the account needs an
 *   ALTCHA solution (Security\Altcha), from the chat or the form. The chat
 *   starts solving after the first answer, in the background; the form's
 *   widget when a field gets focus. Without JavaScript nobody can sign up,
 *   and the form says so.
 *
 * **Closed unless opened:** the network option `mody_registration_open`.
 * While it is off, nothing creates the page, the block says registration
 * opens soon, and both REST routes refuse. Open it with
 * `wp site option update mody_registration_open 1`.
 */
final class Registration {

	/** The block's name. */
	public const BLOCK = 'mody/register';

	/** Network option: registration is open when it is truthy. */
	public const OPEN_OPTION = 'mody_registration_open';

	/** Main-site option: the registration page's ID, once created. */
	public const PAGE_OPTION = 'mody_register_page_id';

	/** The page's slug: `/register/`. */
	public const PAGE_SLUG = 'register';

	/** The form's action and nonce action. */
	public const ACTION = 'mody_register';

	/** The longest full name accepted. */
	public const MAX_NAME = 120;

	/** The questions, in the order the chat asks them. */
	public const FIELDS = array( 'name', 'email', 'title', 'slug' );

	/**
	 * The errors of the form just posted: field (name, email, title, slug,
	 * form) => message.
	 *
	 * @var array<string, string>
	 */
	private static array $errors = array();

	/**
	 * The values just posted, to fill the form again.
	 *
	 * @var array<string, string>
	 */
	private static array $values = array();

	/**
	 * The account the form just created: email and address.
	 *
	 * @var array<string, string>
	 */
	private static array $created = array();

	/**
	 * Whether registration is open.
	 */
	public static function is_open(): bool {
		return (bool) get_site_option( self::OPEN_OPTION );
	}

	/**
	 * Where a member lands: the Mody dashboard in Network Admin.
	 */
	public static function dashboard_url(): string {
		return network_admin_url( 'admin.php?page=' . WizardPage::SLUG );
	}

	/**
	 * The part of every site's address after its name: `.mody.cloud`.
	 */
	public static function address_suffix(): string {
		return '.' . preg_replace( '|^www\.|', '', get_network()->domain );
	}

	/** The front end's style and script, and the editor's script. */
	public const STYLE  = 'mody-register';
	public const SCRIPT = 'mody-register';
	public const EDITOR = 'mody-register-block';

	/**
	 * `init`: registers the block from its block.json, with its style (front
	 * end and editor) and the editor's preview script.
	 */
	public static function register_block(): void {
		$root = dirname( __DIR__, 2 );
		wp_register_style( self::STYLE, self::asset_url( 'register.css' ), array(), self::asset_version( 'register.css' ) );
		wp_register_script( self::EDITOR, self::asset_url( 'register-block.js' ), array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n' ), self::asset_version( 'register-block.js' ), true );
		wp_add_inline_script( self::EDITOR, 'window.modyRegisterBlock = ' . wp_json_encode( self::preview_settings() ) . ';', 'before' );
		add_filter( 'block_type_metadata', array( self::class, 'block_metadata' ) );
		register_block_type( $root . '/blocks/register', array( 'render_callback' => array( self::class, 'render' ) ) );
	}

	/**
	 * `block_type_metadata`: the block is in the inserter on the main site only
	 * (decided by the maintainer on 2026-11-09).
	 *
	 * @param array<string, mixed> $metadata A block's block.json.
	 * @return array<string, mixed>
	 */
	public static function block_metadata( array $metadata ): array {
		if ( self::BLOCK === ( $metadata['name'] ?? '' ) ) {
			$metadata['supports']['inserter'] = is_main_site();
		}
		return $metadata;
	}

	/**
	 * `init`: creates the page on the main site the first time registration
	 * is open. An existing `/register/` page is adopted, never changed.
	 */
	public static function ensure_page(): void {
		if ( ! is_main_site() || ! self::is_open() || wp_installing() ) {
			return;
		}
		$id = (int) get_option( self::PAGE_OPTION );
		if ( $id && get_post( $id ) ) {
			return;
		}
		$existing = get_page_by_path( self::PAGE_SLUG );
		if ( $existing ) {
			update_option( self::PAGE_OPTION, $existing->ID );
			return;
		}
		$admins = get_super_admins();
		$author = $admins ? get_user_by( 'login', $admins[0] ) : false;
		$id     = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Start your free trial', 'mody_cloud' ),
				'post_name'    => self::PAGE_SLUG,
				'post_content' => '<!-- wp:' . self::BLOCK . ' /-->',
				'post_author'  => $author ? $author->ID : 0,
			),
			true
		);
		if ( ! is_wp_error( $id ) ) {
			update_option( self::PAGE_OPTION, $id );
		}
	}

	/**
	 * A site address from what the visitor typed: lowercase letters and
	 * digits only, without the network's suffix (`bakery.mody.cloud` and
	 * `Bäkery!` both give `bakery`).
	 *
	 * @param string $slug As typed.
	 */
	public static function normalize_slug( string $slug ): string {
		$slug = strtolower( trim( $slug ) );
		if ( str_ends_with( $slug, self::address_suffix() ) ) {
			$slug = substr( $slug, 0, -strlen( self::address_suffix() ) );
		}
		$slug = (string) preg_replace( '#^https?://#', '', $slug );
		return substr( (string) preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( $slug ) ) ), 0, 63 );
	}

	/**
	 * A free address for a project's name, or '' when none fits: its letters
	 * and digits, with a number added when it is taken.
	 *
	 * @param string $title The project's name.
	 */
	public static function suggest_slug( string $title ): string {
		$base = substr( self::normalize_slug( $title ), 0, 30 );
		if ( '' === $base ) {
			return '';
		}
		if ( preg_match( '/^[0-9]+$/', $base ) ) {
			$base = 'site' . $base;
		}
		if ( strlen( $base ) < 4 ) {
			$base .= 'site';
		}
		for ( $n = 1; $n <= 20; $n++ ) {
			$candidate = 1 === $n ? $base : $base . $n;
			if ( '' === self::check( 'slug', $candidate ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Checks one answer, as the chat asks it and as register() validates it.
	 *
	 * @param string $field One of FIELDS.
	 * @param string $value The answer, unsanitized (a slug normalized first).
	 * @return string '' when the answer is fine, or why it is not.
	 */
	public static function check( string $field, string $value ): string {
		switch ( $field ) {
			case 'name':
				$name = sanitize_text_field( $value );
				if ( '' === $name ) {
					return __( 'Please enter your name.', 'mody_cloud' );
				}
				/* translators: %d: the number of characters allowed. */
				return mb_strlen( $name ) > self::MAX_NAME ? sprintf( __( 'Please use at most %d characters.', 'mody_cloud' ), self::MAX_NAME ) : '';
			case 'email':
				$email = sanitize_email( $value );
				if ( ! is_email( $email ) ) {
					return __( 'Please enter a valid email address.', 'mody_cloud' );
				}
				return email_exists( $email ) ? __( 'An account with this email already exists. Log in instead.', 'mody_cloud' ) : '';
			case 'title':
				$title = sanitize_text_field( $value );
				if ( '' === $title ) {
					return __( 'Please enter the name of your project or website.', 'mody_cloud' );
				}
				return Pipeline::check_address( 'placeholder', $title )['title'] ?? '';
			case 'slug':
				return Pipeline::check_address( $value, 'placeholder' )['slug'] ?? '';
		}
		return __( 'Unknown question.', 'mody_cloud' );
	}

	/**
	 * Creates an account: the WordPress user, the trial, the first site and
	 * the welcome email (M1-03).
	 *
	 * @param string $name  Full name, unsanitized.
	 * @param string $email Email, unsanitized.
	 * @param string $title The project's or website's name, unsanitized.
	 * @param string $slug  The site's address, as typed (normalized here).
	 * @return array{user_id: int, email: string, address: string, url: string, site_id: int}|WP_Error
	 *         The account, or `mody_invalid_input` with `fields` (field => message), or
	 *         `mody_registration_failed`.
	 */
	public static function register( string $name, string $email, string $title, string $slug ): array|WP_Error {
		$slug   = self::normalize_slug( $slug );
		$values = compact( 'name', 'email', 'title', 'slug' );
		$fields = array();
		foreach ( self::FIELDS as $field ) {
			$message = self::check( $field, $values[ $field ] );
			if ( '' !== $message ) {
				$fields[ $field ] = $message;
			}
		}
		if ( $fields ) {
			return new WP_Error( 'mody_invalid_input', reset( $fields ), array( 'fields' => $fields ) );
		}

		$name    = sanitize_text_field( $name );
		$email   = sanitize_email( $email );
		$title   = sanitize_text_field( $title );
		$failed  = new WP_Error( 'mody_registration_failed', __( 'Your account could not be created. Please try again.', 'mody_cloud' ) );
		$user_id = wp_insert_user(
			array(
				'user_login'   => self::unique_login( $email ),
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ), // Replaced when they choose theirs.
				'display_name' => $name,
				'nickname'     => $name,
				'role'         => '', // No role on the main site: the row is the membership.
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $failed;
		}
		$undo = static function () use ( $user_id ): void {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			Members::delete( $user_id );
			wpmu_delete_user( $user_id ); // No user without a row and a site.
		};

		if ( is_wp_error( Members::start_trial( $user_id ) ) ) {
			$undo();
			return $failed;
		}
		$job_id = Pipeline::start(
			$user_id,
			array(
				'slug'    => $slug,
				'title'   => $title,
				'starter' => true,
			)
		);
		if ( is_wp_error( $job_id ) ) {
			$undo();
			$fields = (array) ( $job_id->get_error_data()['fields'] ?? array() );
			return $fields ? new WP_Error( 'mody_invalid_input', reset( $fields ), array( 'fields' => $fields ) ) : $failed;
		}
		update_user_meta( $user_id, Onboarding::META, Onboarding::STAGE_PASSWORD );

		// No model call, so this takes seconds. A failed step keeps the account: the
		// member's first login opens the dashboard, where the wizard offers Try again.
		$job     = Pipeline::run( $job_id );
		$site_id = is_array( $job ) ? (int) $job['site_id'] : 0;
		$url     = $site_id ? get_home_url( $site_id, '/' ) : 'https://' . Pipeline::domain( $slug ) . '/';

		self::send_welcome( get_userdata( $user_id ), $title, $url );

		return array(
			'user_id' => $user_id,
			'email'   => $email,
			'address' => Pipeline::domain( $slug ),
			'url'     => $url,
			'site_id' => is_array( $job ) && 'done' === $job['status'] ? $site_id : 0,
		);
	}

	/**
	 * The welcome email: the site, the trial, and the link to choose a
	 * password (WordPress's own reset screen, valid for a day).
	 *
	 * @param WP_User|false $user  The new member.
	 * @param string        $title The site's name.
	 * @param string        $url   The site's address.
	 */
	public static function send_welcome( WP_User|false $user, string $title, string $url ): bool {
		if ( ! $user ) {
			return false;
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return false;
		}
		$link = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
		$ends = Members::trial_ends( $user->ID );
		$body = sprintf(
			/* translators: 1: the member's name, 2: the site's name, 3: the site's address, 4: the set-password link, 5: the login page, 6: the trial's last day. */
			__( "Hello %1\$s,\n\nWelcome to Mody Cloud! Your site %2\$s is ready at:\n%3\$s\n\nChoose your password to step in:\n%4\$s\n\nThe link works for 24 hours. After that, use \"Lost your password?\" on the login page:\n%5\$s\n\nYour free trial runs until %6\$s. No card is needed.\n\nOnce your password is set, you land in your site's editor, where Mody, your AI agent, shows you around.\n\nMody Cloud", 'mody_cloud' ),
			$user->display_name,
			$title,
			$url,
			$link,
			wp_login_url(),
			$ends ? wp_date( (string) get_option( 'date_format' ), $ends ) : ''
		);
		return wp_mail( $user->user_email, __( 'Welcome to Mody Cloud: choose your password', 'mody_cloud' ), $body );
	}

	/**
	 * `rest_api_init`: the chat's two routes, open to visitors.
	 */
	public static function register_routes(): void {
		$string = static fn( int $max ): array => array(
			'type'      => 'string',
			'required'  => true,
			'maxLength' => $max,
		);
		register_rest_route(
			ChatController::REST_NAMESPACE,
			'/register/check',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_check' ),
				'permission_callback' => array( self::class, 'rest_permission' ),
				'args'                => array(
					'field' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => self::FIELDS,
					),
					'value' => $string( 500 ),
					'nonce' => $string( 64 ),
				),
			)
		);
		register_rest_route(
			ChatController::REST_NAMESPACE,
			'/register',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_register' ),
				'permission_callback' => array( self::class, 'rest_permission' ),
				'args'                => array(
					'name'  => $string( 500 ),
					'email' => $string( 500 ),
					'title' => $string( 500 ),
					'slug'   => $string( 500 ),
					'nonce'  => $string( 64 ),
					'altcha' => array( // Optional here, so a missing one gets the antibot message.
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 4096,
					),
				),
			)
		);
	}

	/**
	 * Permission callback of both routes: registration is open, and the
	 * request comes from the page (its nonce, made for visitors).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public static function rest_permission( WP_REST_Request $request ): bool|WP_Error {
		if ( ! self::is_open() || ! is_main_site() ) {
			return new WP_Error( 'mody_registration_closed', __( 'Registration is not open yet.', 'mody_cloud' ), array( 'status' => 403 ) );
		}
		if ( ! wp_verify_nonce( (string) $request['nonce'], self::ACTION ) ) {
			return new WP_Error( 'mody_registration_expired', __( 'This page expired. Please reload it and try again.', 'mody_cloud' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * POST `register/check`: one answer.
	 *
	 * @param WP_REST_Request $request `field` and `value`.
	 * @return WP_REST_Response `ok`, `value` (cleaned), and `message` when not ok. For
	 *                          the title, `suggestion`: a free address for it, or ''.
	 */
	public static function rest_check( WP_REST_Request $request ): WP_REST_Response {
		$field = (string) $request['field'];
		$value = 'slug' === $field ? self::normalize_slug( (string) $request['value'] ) : (string) $request['value'];
		$error = self::check( $field, $value );
		$data  = array(
			'ok'    => '' === $error,
			'value' => 'email' === $field ? sanitize_email( $value ) : ( 'slug' === $field ? $value : sanitize_text_field( $value ) ),
		);
		if ( '' !== $error ) {
			$data['message'] = $error;
		}
		if ( 'email' === $field && '' !== $error && email_exists( $data['value'] ) ) {
			$data['exists'] = true; // The chat offers Log in.
		}
		if ( 'slug' === $field ) {
			$data['address'] = '' !== $value ? Pipeline::domain( $value ) : '';
		}
		if ( 'title' === $field && '' === $error ) {
			$data['suggestion'] = self::suggest_slug( $value );
		}
		return new WP_REST_Response( $data );
	}

	/**
	 * POST `register`: the account, the trial and the site.
	 *
	 * @param WP_REST_Request $request The four answers.
	 * @return WP_REST_Response|WP_Error 201 with `email` and `address`; 400 with `fields`.
	 */
	public static function rest_register( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! Altcha::verify( $request['altcha'] ) ) {
			return new WP_Error( 'mody_altcha_failed', Altcha::failed_message(), array( 'status' => 403 ) );
		}
		$account = self::register( (string) $request['name'], (string) $request['email'], (string) $request['title'], (string) $request['slug'] );
		if ( is_wp_error( $account ) ) {
			$account->add_data( array_merge( (array) $account->get_error_data(), array( 'status' => 'mody_invalid_input' === $account->get_error_code() ? 400 : 500 ) ) );
			return $account;
		}
		return new WP_REST_Response(
			array(
				'email'   => $account['email'],
				'address' => $account['address'],
			),
			201
		);
	}

	/**
	 * `template_redirect`: handles the form posted without JavaScript.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is checked below, once the request is known to be ours.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'post' !== $method || ! isset( $_POST['mody_action'] ) || self::ACTION !== $_POST['mody_action'] || ! is_main_site() ) {
			return;
		}
		$field = static fn( string $key ): string => isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in register().
		// phpcs:enable

		foreach ( self::FIELDS as $key ) {
			self::$values[ $key ] = sanitize_text_field( $field( 'mody_' . $key ) );
		}
		if ( ! wp_verify_nonce( sanitize_key( $field( '_mody_nonce' ) ), self::ACTION ) ) {
			self::$errors = array( 'form' => __( 'The form expired. Please try again.', 'mody_cloud' ) );
			return;
		}
		if ( is_user_logged_in() ) {
			self::$errors = array( 'form' => __( 'You are already logged in.', 'mody_cloud' ) );
			return;
		}
		if ( ! self::is_open() ) {
			self::$errors = array( 'form' => __( 'Registration is not open yet.', 'mody_cloud' ) );
			return;
		}
		if ( ! Altcha::verify( Altcha::posted() ) ) {
			self::$errors = array( 'form' => Altcha::failed_message() );
			return;
		}

		$account = self::register( $field( 'mody_name' ), $field( 'mody_email' ), $field( 'mody_title' ), $field( 'mody_slug' ) );
		if ( is_wp_error( $account ) ) {
			self::$errors = (array) ( $account->get_error_data()['fields'] ?? array( 'form' => $account->get_error_message() ) );
			return;
		}
		self::$created = array(
			'email'   => $account['email'],
			'address' => $account['address'],
		);
	}

	/**
	 * `login_redirect`: a member who logs in without asking for a page lands
	 * on the Mody dashboard, not the main site's wp-admin, where they have no
	 * role.
	 *
	 * @param string           $redirect_to The destination so far.
	 * @param string           $requested   The destination the request asked for.
	 * @param WP_User|WP_Error $user        The user logging in.
	 */
	public static function login_redirect( string $redirect_to, string $requested, WP_User|WP_Error $user ): string {
		if ( $user instanceof WP_User && ( '' === $requested || admin_url() === $requested ) && Members::get( $user->ID ) ) {
			return self::dashboard_url();
		}
		return $redirect_to;
	}

	/**
	 * "Check your email": the end of the chat, and of the form.
	 *
	 * @param string $email   Where the link went.
	 * @param string $address The site's address.
	 */
	public static function done_message( string $email, string $address ): string {
		/* translators: 1: the site's address, 2: the member's email. */
		return sprintf( __( 'Done! Your site %1$s is ready. I sent an email to %2$s: open the link in it to choose your password, and I will show you around your editor.', 'mody_cloud' ), $address, $email );
	}

	/**
	 * The block: the chat (the form, which register.js turns into one), or
	 * why there is none. Every output is one element with the block's wrapper
	 * attributes, so its styles apply (M1-04).
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 */
	public static function render( array $attributes = array() ): string {
		if ( ! is_main_site() ) {
			return '';
		}
		wp_enqueue_style( self::STYLE ); // WordPress does it for a block; render() is also called directly.
		$wrapper = self::wrapper_attributes( $attributes );

		if ( self::$created ) {
			return sprintf(
				'<div %s><p role="status">%s</p><p class="mody-register__note">%s</p></div>',
				$wrapper,
				esc_html( self::done_message( self::$created['email'], self::$created['address'] ) ),
				esc_html__( 'Cannot find it? Look in your spam folder.', 'mody_cloud' )
			);
		}
		if ( is_user_logged_in() ) {
			return sprintf(
				'<div %s><p>%s</p><p><a class="wp-element-button" href="%s">%s</a></p></div>',
				$wrapper,
				esc_html__( 'You already have an account.', 'mody_cloud' ),
				esc_url( self::dashboard_url() ),
				esc_html__( 'Go to your dashboard', 'mody_cloud' )
			);
		}
		if ( ! self::is_open() ) {
			// A form posted while closed says so; otherwise, when it opens.
			return '<div ' . $wrapper . '><p role="status">' . esc_html( self::$errors['form'] ?? __( 'Registration opens soon.', 'mody_cloud' ) ) . '</p></div>';
		}

		wp_enqueue_script( self::SCRIPT, self::asset_url( 'register.js' ), array( Altcha::SCRIPT ), self::asset_version( 'register.js' ), true );
		wp_add_inline_script( self::SCRIPT, 'window.modyRegister = ' . wp_json_encode( self::chat_settings() ) . ';', 'before' );

		$value = static fn( string $key ): string => self::$values[ $key ] ?? '';
		$error = static fn( string $key ): string => isset( self::$errors[ $key ] )
			? sprintf( '<p class="mody-register__error" id="mody-register-%s-error" role="alert">%s</p>', esc_attr( $key ), esc_html( self::$errors[ $key ] ) )
			: '';
		$aria  = static fn( string $key ): string => isset( self::$errors[ $key ] ) ? sprintf( ' aria-invalid="true" aria-describedby="mody-register-%s-error"', esc_attr( $key ) ) : '';

		ob_start();
		?>
		<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by WordPress and wrapper_attributes(). ?>>
			<div class="mody-register__chat" id="mody-register-chat" hidden></div>
			<form class="mody-register__form" id="mody-register-form" method="post" action="<?php echo esc_url( (string) get_permalink() ); ?>" novalidate>
				<p class="mody-register__intro"><?php echo esc_html( self::intro() ); ?></p>
				<?php echo $error( 'form' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $error. ?>
				<p class="mody-register__field">
					<label for="mody-register-name"><?php esc_html_e( 'Your name', 'mody_cloud' ); ?></label>
					<input id="mody-register-name" name="mody_name" type="text" autocomplete="name" required maxlength="<?php echo (int) self::MAX_NAME; ?>" value="<?php echo esc_attr( $value( 'name' ) ); ?>"<?php echo $aria( 'name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $aria. ?>>
					<?php echo $error( 'name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $error. ?>
				</p>
				<p class="mody-register__field">
					<label for="mody-register-email"><?php esc_html_e( 'Email', 'mody_cloud' ); ?></label>
					<input id="mody-register-email" name="mody_email" type="email" autocomplete="email" required value="<?php echo esc_attr( $value( 'email' ) ); ?>"<?php echo $aria( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $aria. ?>>
					<?php echo $error( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $error. ?>
				</p>
				<p class="mody-register__field">
					<label for="mody-register-title"><?php esc_html_e( 'Name of your project or website', 'mody_cloud' ); ?></label>
					<input id="mody-register-title" name="mody_title" type="text" autocomplete="organization" required maxlength="<?php echo (int) Pipeline::MAX_NAME_LENGTH; ?>" value="<?php echo esc_attr( $value( 'title' ) ); ?>"<?php echo $aria( 'title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $aria. ?>>
					<?php echo $error( 'title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $error. ?>
				</p>
				<p class="mody-register__field">
					<label for="mody-register-slug"><?php esc_html_e( 'Your site\'s address', 'mody_cloud' ); ?></label>
					<span class="mody-register__address">
						<input id="mody-register-slug" name="mody_slug" type="text" autocomplete="off" spellcheck="false" required maxlength="63" value="<?php echo esc_attr( $value( 'slug' ) ); ?>"<?php echo $aria( 'slug' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $aria. ?>>
						<span><?php echo esc_html( self::address_suffix() ); ?></span>
					</span>
					<span class="mody-register__hint"><?php esc_html_e( 'Lowercase letters and numbers, at least 4.', 'mody_cloud' ); ?></span>
					<?php echo $error( 'slug' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $error. ?>
				</p>
				<?php echo Altcha::widget(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in widget(). ?>
				<noscript><p class="mody-register__error"><?php esc_html_e( 'Signing up needs JavaScript: your browser solves a small puzzle that shows you are not a robot. Please turn JavaScript on and reload this page.', 'mody_cloud' ); ?></p></noscript>
				<input type="hidden" name="mody_action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="_mody_nonce" value="<?php echo esc_attr( wp_create_nonce( self::ACTION ) ); ?>">
				<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Start my free trial', 'mody_cloud' ); ?></button></p>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The wrapper's attributes: the block's own (classes and styles from its
	 * supports, its anchor), plus the class the chat's style needs and the
	 * two things WordPress does not turn into CSS for this block:
	 * - `--mody-register-gap`: the block spacing (WordPress applies it only to
	 *   blocks with a layout);
	 * - `--mody-register-you-background` and `-text`: the visitor's messages
	 *   take the button colors, the block's own or else the site's.
	 * Outside a block render (render() called directly), the class alone.
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 */
	private static function wrapper_attributes( array $attributes ): string {
		$vars = array();
		$gap  = $attributes['style']['spacing']['blockGap'] ?? null;
		$gap  = is_array( $gap ) ? ( $gap['top'] ?? null ) : $gap;
		if ( is_string( $gap ) ) {
			$vars['--mody-register-gap'] = self::css_value( $gap );
		}
		$own    = (array) ( $attributes['style']['elements']['button']['color'] ?? array() );
		$site   = (array) wp_get_global_styles( array( 'elements', 'button', 'color' ) );
		$colors = ( isset( $own['background'] ) || isset( $own['gradient'] ) ) ? $own : $site;
		$vars['--mody-register-you-background'] = self::css_value( (string) ( $colors['gradient'] ?? $colors['background'] ?? '' ) );
		$vars['--mody-register-you-text']       = self::css_value( (string) ( $own['text'] ?? $site['text'] ?? '' ) );

		$style = '';
		foreach ( array_filter( $vars ) as $name => $value ) {
			$style .= $name . ':' . $value . ';';
		}
		$extra = array( 'class' => 'mody-register' );
		if ( '' !== $style ) {
			$extra['style'] = $style;
		}
		$wrapper = get_block_wrapper_attributes( $extra );
		if ( '' === $wrapper ) {
			$wrapper = 'class="mody-register"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' );
		}
		return $wrapper;
	}

	/**
	 * A style value as CSS: `var:preset|color|accent` becomes
	 * `var(--wp--preset--color--accent)`. Anything that could end the
	 * declaration, or load a file, gives ''.
	 *
	 * @param string $value A value from the block's attributes or the site's styles.
	 */
	private static function css_value( string $value ): string {
		$value = trim( (string) preg_replace( '/^var:preset\|([a-z0-9-]+)\|([a-z0-9-]+)$/i', 'var(--wp--preset--$1--$2)', trim( $value ) ) );
		if ( '' === $value || ! preg_match( '/^[\w\s#%(),.+\-\/]+$/', $value ) || preg_match( '/url\s*\(|expression/i', $value ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * The URL of a file in assets/.
	 *
	 * @param string $file A file name.
	 */
	private static function asset_url( string $file ): string {
		return plugins_url( 'assets/' . $file, dirname( __DIR__, 2 ) . '/mody-platform.php' );
	}

	/**
	 * A file's version: when it last changed.
	 *
	 * @param string $file A file name in assets/.
	 */
	private static function asset_version( string $file ): string {
		return (string) filemtime( dirname( __DIR__, 2 ) . '/assets/' . $file );
	}

	/**
	 * What the editor's preview says (register-block.js): the chat's first
	 * lines, a sample answer, and where the block works.
	 *
	 * @return array<string, mixed>
	 */
	private static function preview_settings(): array {
		return array(
			'isMainSite' => is_main_site(),
			'isOpen'     => self::is_open(),
			'strings'    => array(
				'intro'       => self::intro(),
				'mody'        => __( 'Mody', 'mody_cloud' ),
				'you'         => __( 'You', 'mody_cloud' ),
				'askName'     => __( 'What\'s your name?', 'mody_cloud' ),
				'sampleName'  => __( 'Lina', 'mody_cloud' ),
				/* translators: %s: the visitor's name. */
				'askEmail'    => __( 'Nice to meet you, %s! What\'s your email address? I\'ll send your login link there.', 'mody_cloud' ),
				'placeholder' => __( 'you@example.com', 'mody_cloud' ),
				'send'        => __( 'Send', 'mody_cloud' ),
				'notMain'     => __( 'The registration chat works only on the main site. Here it shows nothing.', 'mody_cloud' ),
				'closed'      => __( 'Registration is closed, so visitors see "Registration opens soon." instead of the chat.', 'mody_cloud' ),
			),
		);
	}

	/**
	 * The first thing the page says, in the chat and above the form.
	 */
	private static function intro(): string {
		/* translators: 1: the trial's length in days, 2: the number of sites in the trial. */
		return sprintf( __( 'Hi! I\'m Mody. Answer four quick questions and your website is ready: a free %1$d-day trial with up to %2$d sites, no card needed.', 'mody_cloud' ), Members::TRIAL_DAYS, Members::PLANS['trial']['sites'] );
	}

	/**
	 * The chat's settings and words (register.js).
	 *
	 * @return array<string, mixed>
	 */
	private static function chat_settings(): array {
		return array(
			'endpoint' => rest_url( ChatController::REST_NAMESPACE . '/register' ),
			'nonce'    => wp_create_nonce( self::ACTION ),
			'suffix'   => self::address_suffix(),
			'login'    => wp_login_url(),
			'maxName'  => self::MAX_NAME,
			'maxTitle' => Pipeline::MAX_NAME_LENGTH,
			'strings'  => array(
				'intro'      => self::intro(),
				'mody'       => __( 'Mody', 'mody_cloud' ),
				'you'        => __( 'You', 'mody_cloud' ),
				'askName'    => __( 'What\'s your name?', 'mody_cloud' ),
				/* translators: %s: the visitor's name. */
				'askEmail'   => __( 'Nice to meet you, %s! What\'s your email address? I\'ll send your login link there.', 'mody_cloud' ),
				'askTitle'   => __( 'What\'s the name of your project or website?', 'mody_cloud' ),
				/* translators: 1: a suggested address, such as bakery.mody.cloud, 2: the part every address ends with, such as .mody.cloud. */
				'askSlug'    => __( 'Your site\'s address will be %1$s. Keep it, or type the part you want before %2$s.', 'mody_cloud' ),
				/* translators: %s: the part every address ends with, such as .mody.cloud. */
				'askSlugNew' => __( 'Now choose your site\'s address: type the part before %s. Lowercase letters and numbers, at least 4.', 'mody_cloud' ),
				/* translators: %s: an address, such as bakery.mody.cloud. */
				'keep'       => __( 'Keep %s', 'mody_cloud' ),
				'summary'    => __( 'Here is what I have. Shall I create your account and your site?', 'mody_cloud' ),
				'labels'     => array(
					'name'  => __( 'Name', 'mody_cloud' ),
					'email' => __( 'Email', 'mody_cloud' ),
					'title' => __( 'Project', 'mody_cloud' ),
					'slug'  => __( 'Address', 'mody_cloud' ),
				),
				'create'     => __( 'Create my site', 'mody_cloud' ),
				'change'     => __( 'Change something', 'mody_cloud' ),
				'which'      => __( 'What would you like to change?', 'mody_cloud' ),
				'creating'   => __( 'Creating your account and your site… This takes a few seconds.', 'mody_cloud' ),
				// register.js puts the address in %1$s and the email in %2$s.
				'done'       => self::done_message( '%2$s', '%1$s' ),
				'spam'       => __( 'Cannot find it? Look in your spam folder.', 'mody_cloud' ),
				'logIn'      => __( 'Log in', 'mody_cloud' ),
				'send'       => __( 'Send', 'mody_cloud' ),
				'checking'   => __( 'Checking…', 'mody_cloud' ),
				'failed'     => __( 'Something went wrong. Please try again.', 'mody_cloud' ),
				'network'    => __( 'The connection failed. Check your connection and try again.', 'mody_cloud' ),
				'placeholders' => array(
					'name'  => __( 'Your name', 'mody_cloud' ),
					'email' => __( 'you@example.com', 'mody_cloud' ),
					'title' => __( 'For example: Lina\'s Bakery', 'mody_cloud' ),
					'slug'  => __( 'yourname', 'mody_cloud' ),
				),
			),
		);
	}

	/**
	 * A free login name from the email's local part: lowercase letters and
	 * digits, at least four characters, not one of the network's illegal
	 * names, with a number added when taken.
	 *
	 * @param string $email A valid email.
	 */
	private static function unique_login( string $email ): string {
		$base = substr( (string) preg_replace( '/[^a-z0-9]/', '', strtolower( (string) strstr( $email, '@', true ) ) ), 0, 50 );
		if ( strlen( $base ) < 4 ) {
			$base .= 'member';
		}
		$illegal   = (array) get_site_option( 'illegal_names', array() );
		$candidate = $base;
		for ( $n = 2; username_exists( $candidate ) || in_array( $candidate, $illegal, true ); $n++ ) {
			$candidate = $base . $n;
		}
		return $candidate;
	}
}
