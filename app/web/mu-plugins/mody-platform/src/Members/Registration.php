<?php
/**
 * The registration page on the main site: a chat that creates a member's
 * account, its free months and first site (mvp-spec section 4, "Signup flow";
 * tickets M1-01 part 2 and M1-03; the launch offer since 2026-11-13).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Members;

use Mody\Platform\Agent\ChatController;
use Mody\Platform\Billing\Billing;
use Mody\Platform\Billing\Mollie;
use Mody\Platform\Dashboard\DashboardPage;
use Mody\Platform\Onboarding\Onboarding;
use Mody\Platform\Provisioning\Pipeline;
use Mody\Platform\Security\Altcha;
use Mody\Platform\Security\RateLimit;
use Mody\Platform\Wizard\WizardPage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * `mody.cloud/register/`: four questions, then the account, its free months
 * and the site exist, and an email holds the link to choose a password.
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
 * - **A chat, and no card** (M1-03): name, email, the
 *   project's name and its address, one at a time (`assets/register.js`,
 *   no build step). Each answer is checked on the server (check(), through
 *   `mody-platform/v1/register/check`) before the next question. Then
 *   register() creates the user with a random password, gives it a place
 *   of the launch offer (Members::start_free(): six months free, one site),
 *   creates the site (Pipeline, a `starter` site: no model call) and
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
 * - **Abuse** (the security pass of 2026-11-12). Nobody proves they own the
 *   email before the account exists (that is the decision above), so:
 *   - the account, the site and the mail are rate limited (Security\RateLimit:
 *     `signup_address`, `signup_email`, `signup_network`; the chat's checks:
 *     `signup_check`);
 *   - what the visitor typed goes into the welcome email, so the name and the
 *     project's name are short (MAX_NAME, MAX_TITLE) and carry no links,
 *     email addresses or markup (has_link());
 *   - nothing says whether an email has an account: the chat's check accepts
 *     any valid address, and register() answers the same for an address that
 *     has one, creating nothing and mailing that member a short note
 *     instead (send_existing_note()). It answers in the same time too: in a
 *     web request the account (user, free months, site, welcome email) or the
 *     note is made **after the response has been sent**
 *     (`fastcgi_finish_request()` in a shutdown hook, run_later(), the
 *     pattern of the wizard and the exports), so what the visitor waits for
 *     is the same few checks and counters whichever branch ran. Both
 *     branches also stop alike when the whole network's hourly limit is
 *     full;
 *   - the login name is the name's letters and a random suffix, never the
 *     email's local part, and the public author address (`user_nicename`)
 *     is another random value.
 *
 * **Closed unless opened:** the network option `mody_registration_open`.
 * While it is off, nothing creates the page, the block says registration
 * opens soon, and both REST routes refuse. Open it with
 * `wp site option update mody_registration_open 1`.
 *
 * **The launch offer, then paying** (decided 2026-11-13): signing up gives
 * one of the offer's 50 places. Once they are taken, or its three months
 * have passed, signing up means paying (M1-01 part 3b, built 2026-11-13),
 * when Mollie is set up (`MOLLIE_API_KEY`); without it the block says paid
 * sign-ups open soon, and both routes refuse (takes_accounts()).
 *
 * **A paid sign-up:** the same chat and the same answer for every email
 * (so it tells nothing about who has an account). For a new email the user
 * and a `pending_payment` row are made, no site yet, and an email holds a
 * link to pay (PAY_META, valid PAY_HOURS). The link opens this page with the
 * site, the price and the terms to accept (EU rules: before paying), then
 * Mollie's checkout takes the first month and keeps the card. Paid
 * (Billing): the site is made and the welcome email goes, as for the offer.
 * Not paid within PAY_HOURS: the daily run removes the account.
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

	/** The longest full name accepted (it is printed in the welcome email). */
	public const MAX_NAME = 60;

	/** The longest project name accepted at signup (it is printed in the welcome email). */
	public const MAX_TITLE = 60;

	/** User meta of a paid sign-up waiting for its payment: title, slug, the link's hash, its end. */
	public const PAY_META = 'mody_signup';

	/** How long the link to pay works. */
	public const PAY_HOURS = 24;

	/** The pay page's form action. */
	public const PAY_ACTION = 'mody_signup_pay';

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
	 * Work queued for after the response (see later()).
	 *
	 * @var array<int, callable>
	 */
	private static array $later = array();

	/**
	 * Whether registration is open.
	 */
	public static function is_open(): bool {
		return (bool) get_site_option( self::OPEN_OPTION );
	}

	/**
	 * Whether a visitor can sign up now: registration is open and the launch
	 * offer takes them, or card payments are offered.
	 *
	 * @param bool $invited Whether they came through an invite link.
	 */
	public static function takes_accounts( bool $invited = false ): bool {
		return self::is_open() && ( Members::promo_open( $invited ) || Mollie::offered() );
	}

	/**
	 * Whether signing up now means paying: the offer does not take them and Mollie is set up.
	 *
	 * @param bool $invited Whether they came through an invite link.
	 */
	public static function paying( bool $invited = false ): bool {
		return ! Members::promo_open( $invited ) && Mollie::offered();
	}

	/**
	 * The invite code of this page view: from the address (`?ref=`) or the
	 * form just posted; '' without one (Invites).
	 */
	public static function invite_code(): string {
		// phpcs:disable WordPress.Security.NonceVerification -- Only which invite link was followed; Invites::inviter() checks it.
		if ( isset( $_POST['mody_ref'] ) ) {
			return Invites::clean( wp_unslash( $_POST['mody_ref'] ) );
		}
		return isset( $_GET[ Invites::ARG ] ) ? Invites::clean( wp_unslash( $_GET[ Invites::ARG ] ) ) : '';
		// phpcs:enable
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
		$id   = (int) get_option( self::PAGE_OPTION );
		$page = $id ? get_post( $id ) : null;
		if ( $page ) {
			// The title from before 2026-11-13, when signing up was a trial; a title changed by hand stays.
			if ( 'Start your free trial' === $page->post_title ) {
				wp_update_post(
					array(
						'ID'         => $id,
						'post_title' => __( 'Create your site', 'mody_cloud' ),
					)
				);
			}
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
				'post_title'   => __( 'Create your site', 'mody_cloud' ),
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
				if ( mb_strlen( $name ) > self::MAX_NAME ) {
					/* translators: %d: the number of characters allowed. */
					return sprintf( __( 'Please use at most %d characters.', 'mody_cloud' ), self::MAX_NAME );
				}
				return self::has_link( $name ) ? self::no_links_message() : '';
			case 'email':
				// Valid or not, never whether an account exists: register() deals with that, quietly.
				return is_email( sanitize_email( $value ) ) ? '' : __( 'Please enter a valid email address.', 'mody_cloud' );
			case 'title':
				$title = sanitize_text_field( $value );
				if ( '' === $title ) {
					return __( 'Please enter the name of your project or website.', 'mody_cloud' );
				}
				if ( mb_strlen( $title ) > self::MAX_TITLE ) {
					/* translators: %d: the number of characters allowed. */
					return sprintf( __( 'Please use at most %d characters.', 'mody_cloud' ), self::MAX_TITLE );
				}
				if ( self::has_link( $title ) ) {
					return self::no_links_message();
				}
				return Pipeline::check_address( 'placeholder', $title )['title'] ?? '';
			case 'slug':
				return Pipeline::check_address( $value, 'placeholder' )['slug'] ?? '';
		}
		return __( 'Unknown question.', 'mody_cloud' );
	}

	/**
	 * Whether a text holds a link, an email address, markup or a character
	 * that reorders text: what a stranger could use to put an address in front
	 * of the person who gets the welcome email.
	 *
	 * @param string $text A name or a project name.
	 */
	public static function has_link( string $text ): bool {
		return (bool) preg_match( '#[<>@\\\\/]|://|\bwww\.|[a-z0-9][a-z0-9-]*\.[a-z]{2,}\b|[\x{202A}-\x{202E}\x{2066}-\x{2069}]#iu', $text );
	}

	/**
	 * What to say when has_link() refuses an answer.
	 */
	private static function no_links_message(): string {
		return __( 'Please leave web addresses, email addresses and special characters out of this answer.', 'mody_cloud' );
	}

	/**
	 * An email as one mailbox, for counting: lowercase, without a `+tag`, and
	 * without dots in Gmail's addresses, where they do not matter.
	 *
	 * @param string $email A valid email.
	 */
	public static function normalize_email( string $email ): string {
		$email = strtolower( trim( $email ) );
		$at    = strrpos( $email, '@' );
		if ( false === $at ) {
			return $email;
		}
		$local  = (string) strtok( substr( $email, 0, $at ), '+' );
		$domain = substr( $email, $at + 1 );
		if ( in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) ) {
			$local  = str_replace( '.', '', $local );
			$domain = 'gmail.com';
		}
		return $local . '@' . $domain;
	}

	/**
	 * Creates an account: the WordPress user, its free months, the first site and
	 * the welcome email (M1-03).
	 *
	 * @param string $name  Full name, unsanitized.
	 * @param string $email Email, unsanitized.
	 * @param string $title The project's or website's name, unsanitized.
	 * @param string $slug  The site's address, as typed (normalized here).
	 * @param bool   $defer True in a web request: the work that differs between a new email and
	 *                      one that has an account is done after the response (see the class
	 *                      comment), and the answer is the same shape for both (`user_id` 0,
	 *                      no `url`, no `site_id`). False (WP-CLI, tests, the default): it is done
	 *                      here, and a new account's real `user_id`, `url` and `site_id` come back.
	 *                      The `mody_registration_defer` filter overrides what "a web request"
	 *                      means (tests).
	 * @param string $ref   The invite code the visitor came with ('' for none): who invited them (Invites).
	 * @return array{user_id: int, email: string, address: string, url: string, site_id: int}|WP_Error
	 *         The account (for an email that already has one, or when deferred, the same answer with `user_id`
	 *         0: nothing is created here, and that member gets a note), or `mody_invalid_input`
	 *         with `fields` (field => message), or `mody_rate_limited`, or
	 *         `mody_registration_failed`. A failure of deferred work cannot be shown: it is
	 *         logged (code and message only), and the visitor can sign up again.
	 */
	public static function register( string $name, string $email, string $title, string $slug, bool $defer = false, string $ref = '' ): array|WP_Error {
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

		$name  = sanitize_text_field( $name );
		$email = sanitize_email( $email );
		$title = sanitize_text_field( $title );

		// Per address and per mailbox, for every valid attempt: one that finds an account counts too, so it is no oracle.
		$wait = max( RateLimit::hit_address( 'signup_address' ), RateLimit::hit( 'signup_email', self::normalize_email( $email ) ) );
		if ( $wait ) {
			return RateLimit::error( $wait, __( 'A few accounts have already been started from here. Please try again in %s.', 'mody_cloud' ) );
		}
		// Everyone together: when the hour's accounts are made, every attempt stops, an existing email's too
		// (else the answer would tell whether an email has an account). Only accounts really made are counted below.
		$wait = RateLimit::wait( 'signup_network' );
		if ( $wait ) {
			return RateLimit::error( $wait, __( 'Many people are signing up right now. Please try again in %s.', 'mody_cloud' ) );
		}

		$existing = get_user_by( 'email', $email );
		$answer   = array(
			'user_id' => 0,
			'email'   => $email,
			'address' => Pipeline::domain( $slug ),
			'url'     => '',
			'site_id' => 0,
		);
		if ( $existing ) {
			$work = static fn() => self::send_existing_note( $existing );
		} else {
			// Counted here, before the response, so a burst of parallel requests cannot all pass the check above.
			$wait = RateLimit::hit( 'signup_network' );
			if ( $wait ) {
				return RateLimit::error( $wait, __( 'Many people are signing up right now. Please try again in %s.', 'mody_cloud' ) );
			}
			$work = static fn() => self::create_account( $name, $email, $title, $slug, $ref );
		}

		if ( $defer && (bool) apply_filters( 'mody_registration_defer', function_exists( 'fastcgi_finish_request' ) ) ) {
			self::later( $work );
			return $answer;
		}
		$result = $work();
		return $existing ? $answer : $result;
	}

	/**
	 * Queues work for after the response has been sent: run by run_later()
	 * on `shutdown`.
	 *
	 * @param callable $work Returns a WP_Error when it failed (logged), anything else when it did not.
	 */
	private static function later( callable $work ): void {
		if ( ! self::$later ) {
			add_action( 'shutdown', array( self::class, 'run_later' ), 0 ); // Before core flushes its buffers at 1.
		}
		self::$later[] = $work;
	}

	/**
	 * `shutdown`, first: sends the response (`fastcgi_finish_request()`, so
	 * the visitor stops waiting), then does the queued work. Without PHP-FPM
	 * there is nothing to finish and the work just runs. Tests call it too.
	 */
	public static function run_later(): void {
		$queue       = self::$later;
		self::$later = array();
		if ( ! $queue ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			while ( ob_get_level() > 0 ) {
				ob_end_flush();
			}
			ignore_user_abort( true );
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A site takes seconds, but nobody waits for it.
			}
			fastcgi_finish_request();
		}
		foreach ( $queue as $work ) {
			try {
				$result = $work();
				if ( $result instanceof WP_Error ) {
					error_log( 'Mody signup (after the response) failed: ' . $result->get_error_code() . ' ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Nobody is waiting for an answer.
				}
			} catch ( \Throwable $error ) {
				error_log( 'Mody signup (after the response) failed: ' . get_class( $error ) . ' ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- As above.
			}
		}
	}

	/**
	 * Makes the account: the WordPress user, its free months, the first site and
	 * the welcome email. Rolled back when a step fails.
	 *
	 * @param string $name  Full name, sanitized.
	 * @param string $email Email, sanitized and valid.
	 * @param string $title The project's name, sanitized.
	 * @param string $slug  The site's address, normalized.
	 * @param string $ref   The invite code, or ''.
	 * @return array{user_id: int, email: string, address: string, url: string, site_id: int}|WP_Error
	 */
	private static function create_account( string $name, string $email, string $title, string $slug, string $ref = '' ): array|WP_Error {
		$failed  = new WP_Error( 'mody_registration_failed', __( 'Your account could not be created. Please try again.', 'mody_cloud' ) );
		$names   = self::new_names( $name );
		$user_id = wp_insert_user(
			array(
				'user_login'    => $names['login'],
				'user_nicename' => $names['nicename'],
				'user_email'    => $email,
				'user_pass'     => wp_generate_password( 32, true, true ), // Replaced when they choose theirs.
				'display_name'  => $name,
				'nickname'      => $name,
				'role'          => '', // No role on the main site: the row is the membership.
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

		$inviter = Invites::inviter( $ref );
		$free    = Members::start_free( $user_id, $inviter > 0 );
		if ( is_wp_error( $free ) && 'mody_promo_closed' === $free->get_error_code() && Mollie::offered() ) {
			// The offer is over: a paid sign-up, no site until the first payment.
			$pending = self::start_paid( $user_id, $title, $slug );
			if ( is_wp_error( $pending ) ) {
				$undo();
				return $failed;
			}
			Invites::record( $user_id, $inviter );
			return array(
				'user_id' => $user_id,
				'email'   => $email,
				'address' => Pipeline::domain( $slug ),
				'url'     => '',
				'site_id' => 0,
			);
		}
		if ( is_wp_error( $free ) ) {
			$undo();
			return 'mody_promo_closed' === $free->get_error_code() ? $free : $failed;
		}
		Invites::record( $user_id, $inviter );
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
	 * The welcome email: the site, its free months, and the link to choose a
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
		$title = mb_substr( $title, 0, self::MAX_TITLE );
		$link  = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
		$ends  = Members::free_until( $user->ID );
		$row   = (array) Members::get( $user->ID );
		if ( ! $ends && ! empty( $row['billing_due_at'] ) ) {
			$body = sprintf(
				/* translators: 1: the member's name, 2: the site's name, 3: the site's address, 4: the set-password link, 5: the login page, 6: the next payment's date. */
				__( "Hello %1\$s,\n\nWelcome to Mody Cloud! Your payment went through, and your site %2\$s is ready at:\n%3\$s\n\nChoose your password to step in:\n%4\$s\n\nThe link works for 24 hours. After that, use \"Lost your password?\" on the login page:\n%5\$s\n\nYour next payment is on %6\$s. Your plan and payments are on your site's Dashboard, under Plan & billing.\n\nOnce your password is set, you land in your site's editor, where Mody, your AI agent, shows you around.\n\nMody Cloud", 'mody_cloud' ),
				mb_substr( $user->display_name, 0, self::MAX_NAME ),
				$title,
				$url,
				$link,
				wp_login_url(),
				wp_date( (string) get_option( 'date_format' ), (int) strtotime( $row['billing_due_at'] . ' UTC' ) )
			);
			return wp_mail( $user->user_email, __( 'Welcome to Mody Cloud: choose your password', 'mody_cloud' ), $body );
		}
		$body  = sprintf(
			/* translators: 1: the member's name, 2: the site's name, 3: the site's address, 4: the set-password link, 5: the login page, 6: the last free day, 7: a site's monthly price. */
			__( "Hello %1\$s,\n\nWelcome to Mody Cloud! Your site %2\$s is ready at:\n%3\$s\n\nChoose your password to step in:\n%4\$s\n\nThe link works for 24 hours. After that, use \"Lost your password?\" on the login page:\n%5\$s\n\nYour site is free until %6\$s, and no card is needed until then. After that, it costs %7\$s a month, VAT included.\n\nOnce your password is set, you land in your site's editor, where Mody, your AI agent, shows you around.\n\nMody Cloud", 'mody_cloud' ),
			mb_substr( $user->display_name, 0, self::MAX_NAME ),
			$title,
			$url,
			$link,
			wp_login_url(),
			$ends ? wp_date( (string) get_option( 'date_format' ), $ends ) : '',
			Members::price( Members::PRICE['monthly'] )
		);
		return wp_mail( $user->user_email, __( 'Welcome to Mody Cloud: choose your password', 'mody_cloud' ), $body );
	}

	/**
	 * A paid sign-up waits for its payment: the `pending_payment` row, what
	 * the site will be, and the email with the link to pay.
	 *
	 * @param int    $user_id The new user.
	 * @param string $title   The site's name.
	 * @param string $slug    The site's address.
	 * @return true|WP_Error
	 */
	private static function start_paid( int $user_id, string $title, string $slug ): bool|WP_Error {
		$made = Members::create( $user_id, 'paid', 'pending_payment' );
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		self::send_pay_link( $user_id, $title, $slug );
		return true;
	}

	/**
	 * Emails the link to pay, with a new token (the old link stops working).
	 *
	 * @param int    $user_id The user.
	 * @param string $title   The site's name.
	 * @param string $slug    The site's address.
	 */
	private static function send_pay_link( int $user_id, string $title, string $slug ): void {
		$token = wp_generate_password( 32, false );
		update_user_meta(
			$user_id,
			self::PAY_META,
			array(
				'title'   => $title,
				'slug'    => $slug,
				'hash'    => hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ),
				'expires' => time() + self::PAY_HOURS * HOUR_IN_SECONDS,
			)
		);
		$user = get_userdata( $user_id );
		$body = sprintf(
			/* translators: 1: the member's name, 2: the site's name, 3: the site's address, 4: the site's monthly price, 5: the link, 6: hours. */
			__( "Hello %1\$s,\n\nYou asked to create %2\$s at %3\$s on Mody Cloud. To finish, pay the first month by card: %4\$s a month, VAT included. Your site is created right after.\n\nPay and create your site (the link works for %6\$d hours):\n%5\$s\n\nIf it was not you, ignore this email: nothing is charged, and the account is removed after a day.\n\nMody Cloud", 'mody_cloud' ),
			mb_substr( $user->display_name, 0, self::MAX_NAME ),
			mb_substr( $title, 0, self::MAX_TITLE ),
			Pipeline::domain( $slug ),
			Members::price( Members::PRICE['monthly'] ),
			self::pay_url( $user_id, $token ),
			self::PAY_HOURS
		);
		wp_mail( $user->user_email, __( 'Mody Cloud: pay and create your site', 'mody_cloud' ), $body );
	}

	/**
	 * The link to pay: the registration page with the user and the token.
	 *
	 * @param int    $user_id The user.
	 * @param string $token   The token (only its hash is kept).
	 * @param array  $extra   More query arguments.
	 */
	public static function pay_url( int $user_id, string $token, array $extra = array() ): string {
		$page = (int) get_option( self::PAGE_OPTION );
		$base = $page ? (string) get_permalink( $page ) : home_url( '/' . self::PAGE_SLUG . '/' );
		return add_query_arg(
			array_merge(
				array(
					'mody_pay' => $token,
					'u'        => $user_id,
				),
				$extra
			),
			$base
		);
	}

	/**
	 * The paid sign-up a link names, while it can still be paid.
	 *
	 * @param int    $user_id The user, from the link.
	 * @param string $token   The token, from the link.
	 * @return array<string, mixed>|null The sign-up (PAY_META), or null.
	 */
	public static function pending( int $user_id, string $token ): ?array {
		$signup = get_user_meta( $user_id, self::PAY_META, true );
		$row    = Members::get( $user_id );
		if ( ! is_array( $signup ) || ! $row || '' === $token || ! hash_equals( (string) ( $signup['hash'] ?? '' ), hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ) ) ) {
			return null;
		}
		return $signup + array( 'paid' => 'pending_payment' !== $row['status'], 'expired' => (int) $signup['expires'] < time() );
	}

	/**
	 * The first payment went through: make the site and send the welcome
	 * email (Billing calls this). A taken address gets a few digits more.
	 *
	 * @param int $user_id The member.
	 * @return int The site's ID, or 0.
	 */
	public static function finish_paid( int $user_id ): int {
		$signup = get_user_meta( $user_id, self::PAY_META, true );
		if ( ! is_array( $signup ) || ! empty( $signup['site_id'] ) ) {
			return is_array( $signup ) ? (int) ( $signup['site_id'] ?? 0 ) : 0;
		}
		$slug   = (string) $signup['slug'];
		$job_id = Pipeline::start(
			$user_id,
			array(
				'slug'    => $slug,
				'title'   => (string) $signup['title'],
				'starter' => true,
			)
		);
		if ( is_wp_error( $job_id ) ) {
			$slug   = substr( $slug, 0, 56 ) . wp_rand( 100, 999 );
			$job_id = Pipeline::start(
				$user_id,
				array(
					'slug'    => $slug,
					'title'   => (string) $signup['title'],
					'starter' => true,
				)
			);
		}
		if ( is_wp_error( $job_id ) ) {
			return 0;
		}
		update_user_meta( $user_id, Onboarding::META, Onboarding::STAGE_PASSWORD );
		$job     = Pipeline::run( $job_id );
		$site_id = is_array( $job ) ? (int) $job['site_id'] : 0;
		$url     = $site_id ? get_home_url( $site_id, '/' ) : 'https://' . Pipeline::domain( $slug ) . '/';
		update_user_meta( $user_id, self::PAY_META, array_merge( $signup, array( 'site_id' => $site_id, 'url' => $url ) ) );
		self::send_welcome( get_userdata( $user_id ), (string) $signup['title'], $url );
		return $site_id;
	}

	/**
	 * `template_redirect`: the pay page's form (terms accepted) goes to
	 * Mollie's checkout.
	 */
	public static function handle_pay(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The token in the form is the proof; checked below.
		if ( ! is_main_site() || ! isset( $_POST['mody_action'] ) || self::PAY_ACTION !== $_POST['mody_action'] ) {
			return;
		}
		$user  = absint( $_POST['u'] ?? 0 );
		$token = (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['mody_pay'] ?? '' ) );
		$signup = self::pending( $user, $token );
		if ( ! $signup || $signup['paid'] || $signup['expired'] ) {
			wp_safe_redirect( self::pay_url( $user, $token ) );
			exit;
		}
		if ( empty( $_POST['accept'] ) ) {
			wp_safe_redirect( self::pay_url( $user, $token, array( 'accept' => 0 ) ) );
			exit;
		}
		// phpcs:enable
		$checkout = Billing::start_signup( $user, self::pay_url( $user, $token ) );
		$host     = is_string( $checkout ) ? (string) wp_parse_url( $checkout, PHP_URL_HOST ) : '';
		if ( ! preg_match( '/(^|\.)mollie\.com$/', $host ) ) {
			wp_safe_redirect( self::pay_url( $user, $token, array( 'failed' => 1 ) ) );
			exit;
		}
		wp_redirect( $checkout ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Mollie's checkout, host checked above.
		exit;
	}

	/**
	 * The pay page: what is being bought, the terms, Pay with card; after
	 * the checkout, how it went.
	 *
	 * @param string $wrapper The block's wrapper attributes.
	 * @return string|null The page, or null when there is no pay link in the address.
	 */
	private static function render_pay( string $wrapper ): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only; the token is the proof.
		if ( empty( $_GET['mody_pay'] ) ) {
			return null;
		}
		$user    = absint( $_GET['u'] ?? 0 );
		$token   = (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['mody_pay'] ) );
		$payment = absint( $_GET['payment'] ?? 0 );
		$failed  = ! empty( $_GET['failed'] );
		$refused = isset( $_GET['accept'] );
		// phpcs:enable
		$signup = self::pending( $user, $token );
		$box    = static fn( string $html ): string => '<div ' . $wrapper . '>' . $html . '</div>';
		if ( ! $signup ) {
			return $box( '<p role="status">' . esc_html__( 'This link does not work. Sign up again, or look for a newer email from us.', 'mody_cloud' ) . '</p>' );
		}
		if ( $payment && ! $signup['paid'] ) {
			$state = Billing::returned( $user, $payment );
			Members::forget();
			$signup = self::pending( $user, $token ) ?? $signup;
			if ( 'card-waiting' === $state ) {
				return $box( '<p role="status">' . esc_html__( 'Your payment is being processed. We email you as soon as your site is ready; you can close this page.', 'mody_cloud' ) . '</p>' );
			}
			$failed = $failed || ! $signup['paid'];
		}
		if ( $signup['paid'] ) {
			$signup = get_user_meta( $user, self::PAY_META, true );
			return $box(
				'<p role="status">' . esc_html(
					sprintf(
						/* translators: %s: the site's address. */
						__( 'Paid, thank you! Your site is ready at %s. We emailed you the link to choose your password.', 'mody_cloud' ),
						(string) ( $signup['url'] ?? Pipeline::domain( (string) $signup['slug'] ) )
					)
				) . '</p>'
			);
		}
		if ( $signup['expired'] ) {
			return $box( '<p role="status">' . esc_html__( 'This link has expired. Sign up again to get a new one.', 'mody_cloud' ) . '</p>' );
		}
		$notice = $failed
			? '<p class="mody-register__error" role="alert">' . esc_html__( 'The payment did not go through. Try again, or use another card.', 'mody_cloud' ) . '</p>'
			: ( $refused ? '<p class="mody-register__error" role="alert">' . esc_html__( 'Tick the box to accept the terms.', 'mody_cloud' ) . '</p>' : '' );
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( (string) get_permalink() ); ?>">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: the site's name, 2: its address, 3: a site's monthly price. */
						__( 'Your site %1$s at %2$s: %3$s a month, VAT included. Your site is created as soon as the payment goes through.', 'mody_cloud' ),
						(string) $signup['title'],
						Pipeline::domain( (string) $signup['slug'] ),
						Members::price( Members::PRICE['monthly'] )
					)
				);
				?>
			</p>
			<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>
			<p><label class="mody-register__accept"><input type="checkbox" name="accept" value="1" required /> <?php echo esc_html( Billing::signup_terms() ); ?></label></p>
			<?php if ( '' !== Billing::legal_links() ) : ?>
				<p class="mody-register__note"><?php echo Billing::legal_links(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in legal_links(). ?></p>
			<?php endif; ?>
			<input type="hidden" name="mody_action" value="<?php echo esc_attr( self::PAY_ACTION ); ?>">
			<input type="hidden" name="mody_pay" value="<?php echo esc_attr( $token ); ?>">
			<input type="hidden" name="u" value="<?php echo esc_attr( (string) $user ); ?>">
			<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Pay with card', 'mody_cloud' ); ?></button>
			<?php if ( Mollie::test_mode() ) : ?>
				<span class="mody-register__note"><?php esc_html_e( 'Test mode: no real money moves.', 'mody_cloud' ); ?></span>
			<?php endif; ?></p>
		</form>
		<?php
		return $box( (string) ob_get_clean() );
	}

	/**
	 * The note for a member whose email someone typed in the signup: they
	 * already have an account, nothing was created, and here is how to log in.
	 * Fixed text, nothing the visitor typed. One per member every six hours
	 * (RateLimit, `signup_note`), so the form cannot be used to flood them.
	 *
	 * @param WP_User $user The member.
	 */
	private static function send_existing_note( WP_User $user ): bool {
		if ( RateLimit::hit( 'signup_note', strtolower( $user->user_email ) ) ) {
			return false;
		}
		// A paid sign-up not paid yet: a new link to pay, for the site it asked for.
		$row    = Members::get( $user->ID );
		$signup = get_user_meta( $user->ID, self::PAY_META, true );
		if ( $row && 'pending_payment' === $row['status'] && is_array( $signup ) ) {
			self::send_pay_link( $user->ID, (string) $signup['title'], (string) $signup['slug'] );
			return true;
		}
		$body = sprintf(
			/* translators: 1: the login page, 2: the lost-password page. */
			__( "Hello,\n\nSomeone, maybe you, asked to create a site on Mody Cloud with this email address. You already have an account, so nothing new was created.\n\nLog in:\n%1\$s\n\nIf you forgot your password, choose a new one:\n%2\$s\n\nIf it was not you, you can ignore this email: nobody gets into your account with it.\n\nMody Cloud", 'mody_cloud' ),
			wp_login_url(),
			wp_lostpassword_url()
		);
		return wp_mail( $user->user_email, __( 'Mody Cloud: you already have an account', 'mody_cloud' ), $body );
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
		// The invite code the visitor came with (Invites), optional.
		$ref = array(
			'type'      => 'string',
			'default'   => '',
			'maxLength' => 64,
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
					'ref'   => $ref,
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
					'ref'    => $ref,
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
		$invited = Invites::inviter( (string) $request['ref'] ) > 0;
		if ( ! Members::promo_open( $invited ) && ! Mollie::offered() ) {
			return new WP_Error( 'mody_promo_closed', Members::promo_closed_message( $invited ), array( 'status' => 403 ) );
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
	 * @return WP_REST_Response|WP_Error `ok`, `value` (cleaned), and `message` when not ok. For
	 *                          the title, `suggestion`: a free address for it, or ''. 429 when
	 *                          the address asks too often (RateLimit, `signup_check`).
	 */
	public static function rest_check( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$wait = RateLimit::hit_address( 'signup_check' );
		if ( $wait ) {
			return RateLimit::error( $wait );
		}
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
		if ( 'slug' === $field ) {
			$data['address'] = '' !== $value ? Pipeline::domain( $value ) : '';
		}
		if ( 'title' === $field && '' === $error ) {
			$data['suggestion'] = self::suggest_slug( $value );
		}
		return new WP_REST_Response( $data );
	}

	/**
	 * POST `register`: the account, its free months and the site.
	 *
	 * @param WP_REST_Request $request The four answers.
	 * @return WP_REST_Response|WP_Error 201 with `email` and `address`; 400 with `fields`.
	 */
	public static function rest_register( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! Altcha::verify( $request['altcha'] ) ) {
			return new WP_Error( 'mody_altcha_failed', Altcha::failed_message(), array( 'status' => 403 ) );
		}
		$account = self::register( (string) $request['name'], (string) $request['email'], (string) $request['title'], (string) $request['slug'], true, (string) $request['ref'] );
		if ( is_wp_error( $account ) ) {
			$status = (int) ( ( (array) $account->get_error_data() )['status'] ?? 0 );
			$account->add_data( array_merge( (array) $account->get_error_data(), array( 'status' => $status ? $status : ( 'mody_invalid_input' === $account->get_error_code() ? 400 : 500 ) ) ) );
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
		$ref     = self::invite_code();
		$invited = Invites::inviter( $ref ) > 0;
		if ( ! Members::promo_open( $invited ) && ! Mollie::offered() ) {
			self::$errors = array( 'form' => Members::promo_closed_message( $invited ) );
			return;
		}
		if ( ! Altcha::verify( Altcha::posted() ) ) {
			self::$errors = array( 'form' => Altcha::failed_message() );
			return;
		}

		$account = self::register( $field( 'mody_name' ), $field( 'mody_email' ), $field( 'mody_title' ), $field( 'mody_slug' ), true, $ref );
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
	 * Where a member lands: the Mody dashboard of their first site, where
	 * Plan & billing is (every site's Dashboard since 2026-11-11), or
	 * Network Admin → Mody → New site while they have no site.
	 *
	 * @param int $user_id The member.
	 */
	public static function member_home( int $user_id ): string {
		$sites = DashboardPage::member_sites( $user_id );
		return $sites ? get_admin_url( (int) $sites[0]['id'], 'admin.php?page=' . DashboardPage::SLUG ) : self::dashboard_url();
	}

	/**
	 * `login_redirect`: a member who logs in without asking for a page lands
	 * on their site's Mody dashboard (member_home()), not the main site's
	 * wp-admin, where they have no role.
	 *
	 * @param string           $redirect_to The destination so far.
	 * @param string           $requested   The destination the request asked for.
	 * @param WP_User|WP_Error $user        The user logging in.
	 */
	public static function login_redirect( string $redirect_to, string $requested, WP_User|WP_Error $user ): string {
		if ( $user instanceof WP_User && ( '' === $requested || admin_url() === $requested ) && Members::get( $user->ID ) ) {
			return self::member_home( $user->ID );
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
		/* translators: 1: the site's address (not shown: the answer is the same when the email already had an account), 2: the member's email. */
		return sprintf( __( 'Done! I sent an email to %2$s. Open the link in it to continue, and I will show you around your editor. If you already had an account with this address, the email tells you how to log in.', 'mody_cloud' ), $address, $email );
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
		$pay     = self::render_pay( $wrapper );
		if ( null !== $pay ) {
			return $pay;
		}

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
		$ref     = self::invite_code();
		$invited = Invites::inviter( $ref ) > 0;
		if ( ! $invited ) {
			$ref = ''; // An unknown code is ignored.
		}
		if ( ! self::takes_accounts( $invited ) ) {
			// A form posted while closed says so; otherwise, when it opens, or that the launch offer's places are taken.
			$closed = self::is_open() ? Members::promo_closed_message( $invited ) : __( 'Registration opens soon.', 'mody_cloud' );
			return '<div ' . $wrapper . '><p role="status">' . esc_html( self::$errors['form'] ?? $closed ) . '</p></div>';
		}

		wp_enqueue_script( self::SCRIPT, self::asset_url( 'register.js' ), array( Altcha::SCRIPT ), self::asset_version( 'register.js' ), true );
		wp_add_inline_script( self::SCRIPT, 'window.modyRegister = ' . wp_json_encode( self::chat_settings( $invited, $ref ) ) . ';', 'before' );

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
				<p class="mody-register__intro"><?php echo esc_html( self::intro( $invited ) ); ?></p>
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
					<input id="mody-register-title" name="mody_title" type="text" autocomplete="organization" required maxlength="<?php echo (int) self::MAX_TITLE; ?>" value="<?php echo esc_attr( $value( 'title' ) ); ?>"<?php echo $aria( 'title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in $aria. ?>>
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
				<?php if ( '' !== $ref ) : ?>
					<input type="hidden" name="mody_ref" value="<?php echo esc_attr( $ref ); ?>">
				<?php endif; ?>
				<p><button type="submit" class="wp-element-button"><?php echo esc_html( self::paying( $invited ) ? __( 'Continue', 'mody_cloud' ) : __( 'Create my free site', 'mody_cloud' ) ); ?></button></p>
			</form>
			<?php if ( '' !== Billing::legal_url( 'terms' ) ) : ?>
				<p class="mody-register__note mody-register__legal">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: a link to the terms of service, 2: a link to the privacy policy. */
							__( 'By creating your site you accept the %1$s and the %2$s.', 'mody_cloud' ),
							'<a href="' . esc_url( Billing::legal_url( 'terms' ) ) . '">' . esc_html__( 'terms of service', 'mody_cloud' ) . '</a>',
							'<a href="' . esc_url( Billing::legal_url( 'privacy' ) ) . '">' . esc_html__( 'privacy policy', 'mody_cloud' ) . '</a>'
						),
						array( 'a' => array( 'href' => array() ) )
					);
					?>
				</p>
			<?php endif; ?>
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
			'isOpen'     => self::takes_accounts(),
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
	private static function intro( bool $invited = false ): string {
		if ( self::paying( $invited ) ) {
			/* translators: %s: a site's monthly price, such as 7.99 EUR. */
			return sprintf( __( 'Hi! I\'m Mody. Answer four quick questions, then pay the first month by card: %s a month per site, VAT included. Your site is ready right after.', 'mody_cloud' ), Members::price( Members::PRICE['monthly'] ) );
		}
		/* translators: 1: free months, 2: a site's monthly price, such as 7.99 EUR. */
		return sprintf( __( 'Hi! I\'m Mody. Answer four quick questions and your website is ready. It is free for %1$d months, no card needed; after that, %2$s a month, VAT included.', 'mody_cloud' ), Offers::free_months(), Members::price( Members::PRICE['monthly'] ) );
	}

	/**
	 * The chat's settings and words (register.js).
	 *
	 * @return array<string, mixed>
	 */
	private static function chat_settings( bool $invited = false, string $ref = '' ): array {
		return array(
			'endpoint' => rest_url( ChatController::REST_NAMESPACE . '/register' ),
			'ref'      => $ref,
			'nonce'    => wp_create_nonce( self::ACTION ),
			'suffix'   => self::address_suffix(),
			'login'    => wp_login_url(),
			'maxName'  => self::MAX_NAME,
			'maxTitle' => self::MAX_TITLE,
			'strings'  => array(
				'intro'      => self::intro( $invited ),
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
	 * The new account's login and its public name in author addresses, both
	 * without personal data beyond the name that is already public: the
	 * name's letters (at most twelve) and a random suffix, a different one
	 * for each. Members log in with their email, so the login is rarely
	 * typed; it is never the email's local part, which used to be public in
	 * the author address and in the REST users list.
	 *
	 * @param string $name The member's full name.
	 * @return array{login: string, nicename: string}
	 */
	private static function new_names( string $name ): array {
		$base = substr( (string) preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( $name ) ) ), 0, 12 );
		if ( strlen( $base ) < 3 ) {
			$base = 'member';
		}
		$illegal = (array) get_site_option( 'illegal_names', array() );
		do {
			$login = $base . bin2hex( random_bytes( 3 ) );
		} while ( username_exists( $login ) || in_array( $login, $illegal, true ) );
		return array(
			'login'    => $login,
			'nicename' => $base . '-' . bin2hex( random_bytes( 3 ) ),
		);
	}
}
