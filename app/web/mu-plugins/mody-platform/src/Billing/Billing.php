<?php
/**
 * Paying for sites with a card through Mollie (M1-01 part 3).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Billing;

use Mody\Platform\Dashboard\DashboardPage;
use Mody\Platform\Members\Invites;
use Mody\Platform\Members\Members;
use Mody\Platform\Members\Registration;
use Mody\Platform\Provisioning\Quota;
use Mody\Platform\Security\RateLimit;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Decided by the maintainer on 2026-11-13 (tickets/pricing-per-site.md,
 * M1-01 part 3): cards only, through Mollie's REST API (Billing\Mollie).
 *
 * - **Adding a card:** on Plan & billing the member accepts the terms and
 *   pays a 0.01 EUR card check on Mollie's checkout (a `first` payment); its
 *   mandate is the card the platform charges later. Changing the card is the
 *   same.
 * - **Charges:** when `billing_due_at` comes (the end of the free months,
 *   then every month or year), the account's sites times the price
 *   (Members::total()), a `recurring` payment on the mandate. Paid: the plan
 *   is `paid` and the next date moves on. Failed, or no card: `past_due`
 *   (read-only and deletion are M1-02), and the daily run tries the card
 *   again every day.
 * - **The webhook** (`mody-platform/v1/mollie/webhook`) gets only a payment's
 *   ID; the platform asks Mollie for the payment with its key, so a forged
 *   call changes nothing, and acts only on payments it started itself
 *   (Payments), once. Mollie cannot reach a local stack, so the return from
 *   the checkout and the daily run ask Mollie too.
 * - **The 14-day withdrawal** (decided 2026-11-13: in full): within 14 days of
 *   an account's first charge, a Withdraw button refunds it in full; the
 *   account is then `canceled`. That is at least what EU law asks (14 days
 *   from the contract, which is made when the card is added, before the
 *   first charge), with no share kept for the days used.
 * - **Cancel** (built 2026-11-13): the plan ends at the end of the period
 *   already paid (or of the free months): `cancel_at`; until then Keep my
 *   plan undoes it. On that date the account is `canceled`, nothing is
 *   charged, and the sites are read-only, then deleted after 60 days
 *   (Lifecycle). Adding a card while canceled brings the account back with a
 *   charge from that day.
 * - **Reminders:** a free account without a card gets an email a month and a
 *   week before its free months end.
 * - **A card that expires before the next charge** (2026-11-13): Plan &
 *   billing says so next to the card, and the daily run sends one email, in
 *   the 30 days before that charge, asking for another card (the charge on
 *   an expired card would fail).
 * - **The daily run** (run(), `wp mody-billing run`): reminders, charges,
 *   retries, and payments still waiting. Idempotent, under a lock. What
 *   starts it is the open WP-Cron decision (architecture.md).
 * - **A paid sign-up** (after the launch offer, Members\Registration): the
 *   first month is a `first` payment of one site's price (`signup`), which
 *   makes the mandate too; paid, the account is `paid` and `active`, its next
 *   charge a month on, and Registration::finish_paid() makes the site. It is
 *   the first charge for the 14-day withdrawal. Not paid within a day, the
 *   daily run removes the account (it has no site and paid nothing).
 * - **Refunds and chargebacks made outside the platform** (2026-11-13): Mollie
 *   calls the webhook for them too, so the webhook always asks Mollie again,
 *   even about a payment already applied (reversals()). A refund made in
 *   Mollie's dashboard is the maintainer's choice: it is recorded and shown
 *   ("Refunded") and the member gets an email; the account stays as it is.
 *   A chargeback (the card's bank took a charge back) leaves that period
 *   unpaid: the account is `past_due` (read-only), the card is dropped (no
 *   automatic retries on a card that was disputed), and the next charge is
 *   that period again, made when the member adds a card.
 * - **Invoices:** from Mollie at first, then our own plugin (decided
 *   2026-11-13); not part of this code.
 */
final class Billing {

	/** The card check's amount, in EUR. */
	public const CARD_CHECK = 0.01;

	/** Days after the first charge when the full refund is offered. */
	public const WITHDRAWAL_DAYS = 14;

	/** Days a past-due account's card is tried again (then M1-02 deletes). */
	public const RETRY_DAYS = 60;

	/** admin-post actions. */
	public const CARD_ACTION     = 'mody_billing_card';
	public const WITHDRAW_ACTION = 'mody_billing_withdraw';
	public const CANCEL_ACTION   = 'mody_billing_cancel';
	public const RESUME_ACTION   = 'mody_billing_resume';

	/** User meta: the card's label, last digits and expiry, for Plan & billing. */
	public const CARD_META = 'mody_card';

	/** User meta: the reminders already sent (`month`, `week` => time). */
	public const REMINDERS_META = 'mody_billing_reminders';

	/** User meta: the mandate whose expiry was already emailed about. */
	public const EXPIRY_META = 'mody_card_expiry_warned';

	/** Days before a charge when an expiring card is emailed about. */
	public const EXPIRY_NOTICE_DAYS = 30;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( Payments::class, 'install' ), 1 );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'admin_post_' . self::CARD_ACTION, array( self::class, 'start_card' ) );
		add_action( 'admin_post_' . self::WITHDRAW_ACTION, array( self::class, 'start_withdrawal' ) );
		add_action( 'admin_post_' . self::CANCEL_ACTION, array( self::class, 'start_cancel' ) );
		add_action( 'admin_post_' . self::RESUME_ACTION, array( self::class, 'start_resume' ) );
		Lifecycle::register();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'mody-billing', Command::class );
		}
	}

	/**
	 * `rest_api_init`: the webhook, open to Mollie.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'mody-platform/v1',
			'/mollie/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_webhook' ),
				'permission_callback' => '__return_true', // Mollie has no account here; the payment is checked with Mollie.
				'args'                => array(
					'id' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 32,
					),
				),
			)
		);
	}

	/**
	 * The webhook: asks Mollie about the payment and applies it. Always 200,
	 * so it says nothing about which IDs exist.
	 *
	 * @param WP_REST_Request $request `id`.
	 */
	public static function rest_webhook( WP_REST_Request $request ): WP_REST_Response {
		if ( ! RateLimit::hit_address( 'mollie_webhook' ) ) {
			self::process( (string) $request['id'], true ); // Fresh: a refund or a chargeback calls it for a payment already applied.
		}
		return new WP_REST_Response( null, 200 );
	}

	/**
	 * Where Mollie calls back, or '' where it cannot reach (a local stack).
	 */
	public static function webhook_url(): string {
		$url  = get_rest_url( get_main_site_id(), 'mody-platform/v1/mollie/webhook' );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return preg_match( '/(\.local|\.test|\.localhost|^localhost)$/', $host ) ? '' : $url;
	}

	/**
	 * A member's Plan & billing page, on their first site.
	 *
	 * @param int                  $user_id The member.
	 * @param array<string, mixed> $extra   More query arguments.
	 */
	public static function plan_url( int $user_id, array $extra = array() ): string {
		$sites = DashboardPage::member_sites( $user_id );
		$args  = array_merge(
			array(
				'page'    => DashboardPage::SLUG,
				'section' => 'plan',
			),
			$extra
		);
		return $sites
			? add_query_arg( $args, get_admin_url( (int) $sites[0]['id'], 'admin.php' ) )
			: add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * A legal page of the main site (scripts/legal-pages.php), once published.
	 *
	 * @param string $slug `terms`, `privacy` or `legal-notice`.
	 * @return string Its address, or '' while it is not published.
	 */
	public static function legal_url( string $slug ): string {
		static $found = array();
		if ( ! array_key_exists( $slug, $found ) ) {
			switch_to_blog( get_main_site_id() );
			$page           = get_page_by_path( $slug, OBJECT, 'page' );
			$found[ $slug ] = $page && 'publish' === $page->post_status ? (string) get_permalink( $page ) : '';
			restore_current_blog();
		}
		return $found[ $slug ];
	}

	/**
	 * The links to the terms and the privacy policy, for next to the box
	 * people tick, or '' while the pages are not published.
	 */
	public static function legal_links(): string {
		$terms   = self::legal_url( 'terms' );
		$privacy = self::legal_url( 'privacy' );
		if ( '' === $terms ) {
			return '';
		}
		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>%3$s',
			esc_url( $terms ),
			esc_html__( 'Terms of service', 'mody_cloud' ),
			'' !== $privacy ? ' · <a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy policy', 'mody_cloud' ) . '</a>' : ''
		);
	}

	/**
	 * What the member agrees to when adding a card: the price, when it is
	 * charged, the card check, cancelling and the 14-day withdrawal.
	 *
	 * @param int $user_id The member.
	 */
	public static function terms( int $user_id ): string {
		$row    = (array) Members::get( $user_id );
		$period = in_array( (string) ( $row['billing_period'] ?? '' ), Members::PERIODS, true ) ? (string) $row['billing_period'] : 'monthly';
		$due    = ! empty( $row['billing_due_at'] ) ? (int) strtotime( $row['billing_due_at'] . ' UTC' ) : time();
		return sprintf(
			/* translators: 1: a site's price, 2: "a month" or "a year", 3: a date, 4: the card check's amount, 5: days. */
			__( 'Each of my sites costs %1$s %2$s, VAT included, charged to this card from %3$s until I cancel. A card check of %4$s is charged now. I can cancel at the end of any period, and withdraw within %5$d days of my first payment to get it back in full.', 'mody_cloud' ),
			Members::price( Members::PRICE[ $period ] ),
			'yearly' === $period ? __( 'a year', 'mody_cloud' ) : __( 'a month', 'mody_cloud' ),
			wp_date( (string) get_option( 'date_format' ), max( time(), $due ) ),
			Members::price( self::CARD_CHECK ),
			self::WITHDRAWAL_DAYS
		);
	}

	/**
	 * Starts adding a card: the customer, then the 0.01 EUR check.
	 *
	 * @param int $user_id The member.
	 * @return string|WP_Error Mollie's checkout address.
	 */
	public static function add_card( int $user_id ): string|WP_Error {
		$row  = Members::get( $user_id );
		$user = get_userdata( $user_id );
		if ( ! $row || ! $user ) {
			return new WP_Error( 'mody_member_none', __( 'This account has no plan.', 'mody_cloud' ) );
		}
		if ( ! Mollie::configured() ) {
			return new WP_Error( 'mody_mollie_off', __( 'Card payments are not available yet.', 'mody_cloud' ) );
		}
		if ( 'expired' === $row['status'] ) {
			return new WP_Error( 'mody_billing_closed', __( 'This account is closed.', 'mody_cloud' ) );
		}
		if ( RateLimit::hit( 'card_check', (string) $user_id ) ) {
			return new WP_Error( 'mody_rate_limited', __( 'Too many tries. Please wait an hour and try again.', 'mody_cloud' ) );
		}
		$customer = (string) ( $row['mollie_customer_id'] ?? '' );
		if ( '' === $customer ) {
			$made = Mollie::create_customer( $user->display_name, $user->user_email );
			if ( is_wp_error( $made ) ) {
				return $made;
			}
			$customer = (string) ( $made['id'] ?? '' );
			Members::update( $user_id, array( 'mollie_customer_id' => $customer ) );
		}
		$id      = 0;
		$payment = array(
			'amount'       => Mollie::amount( self::CARD_CHECK ),
			'description'  => __( 'Mody Cloud: card check', 'mody_cloud' ),
			'redirectUrl'  => '', // Set below, with the row's ID.
			'customerId'   => $customer,
			'sequenceType' => 'first',
			'method'       => 'creditcard',
			'metadata'     => array(
				'user' => $user_id,
				'kind' => 'card',
			),
		);
		$webhook = self::webhook_url();
		if ( '' !== $webhook ) {
			$payment['webhookUrl'] = $webhook;
		}
		// The row first, so the return address can name it; its Mollie ID follows.
		$id = Payments::add(
			array(
				'user_id'   => $user_id,
				'mollie_id' => 'new_' . wp_generate_password( 20, false ),
				'kind'      => 'card',
				'amount'    => self::CARD_CHECK,
			)
		);
		if ( ! $id ) {
			return new WP_Error( 'mody_billing_db', __( 'That did not work. Please try again.', 'mody_cloud' ) );
		}
		$payment['redirectUrl'] = self::plan_url( $user_id, array( 'payment' => $id ) );
		$made                   = Mollie::create_payment( $payment, 'card-' . $id );
		$checkout               = is_array( $made ) ? (string) ( $made['_links']['checkout']['href'] ?? '' ) : '';
		if ( is_wp_error( $made ) || '' === $checkout || empty( $made['id'] ) ) {
			global $wpdb;
			$wpdb->delete( Payments::table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			return is_wp_error( $made ) ? $made : new WP_Error( 'mody_mollie', __( 'The payment provider did not answer. Please try again in a moment.', 'mody_cloud' ) );
		}
		Payments::update(
			$id,
			array(
				'mollie_id' => (string) $made['id'],
				'status'    => (string) ( $made['status'] ?? 'open' ),
			)
		);
		return $checkout;
	}

	/**
	 * admin-post: Add (or change) the card. The terms must be accepted.
	 */
	public static function start_card(): void {
		check_admin_referer( self::CARD_ACTION );
		$user = get_current_user_id();
		if ( ! Mollie::offered() ) {
			wp_safe_redirect( self::plan_url( $user, array( 'billing' => 'card-error' ) ) );
			exit;
		}
		if ( empty( $_POST['accept'] ) ) {
			wp_safe_redirect( self::plan_url( $user, array( 'billing' => 'accept' ) ) );
			exit;
		}
		$checkout = self::add_card( $user );
		if ( is_wp_error( $checkout ) ) {
			wp_safe_redirect( self::plan_url( $user, array( 'billing' => 'card-error' ) ) );
			exit;
		}
		$host = (string) wp_parse_url( $checkout, PHP_URL_HOST );
		if ( ! preg_match( '/(^|\.)mollie\.com$/', $host ) ) {
			wp_safe_redirect( self::plan_url( $user, array( 'billing' => 'card-error' ) ) );
			exit;
		}
		wp_redirect( $checkout ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Mollie's checkout, host checked above.
		exit;
	}

	/**
	 * What someone signing up agrees to before paying.
	 */
	public static function signup_terms(): string {
		return sprintf(
			/* translators: 1: a site's price, 2: days. */
			__( 'My site costs %1$s a month, VAT included, charged to this card now and every month until I cancel. I can cancel at the end of any month, and withdraw within %2$d days of this payment to get it back in full.', 'mody_cloud' ),
			Members::price( Members::PRICE['monthly'] ),
			self::WITHDRAWAL_DAYS
		);
	}

	/**
	 * Starts a paid sign-up's first payment: one site's month, on a new
	 * card.
	 *
	 * @param int    $user_id A `pending_payment` member.
	 * @param string $back    The pay page to come back to (gets `payment`).
	 * @return string|WP_Error Mollie's checkout address.
	 */
	public static function start_signup( int $user_id, string $back ): string|WP_Error {
		$row  = Members::get( $user_id );
		$user = get_userdata( $user_id );
		if ( ! Mollie::offered() || ! $row || ! $user || 'pending_payment' !== $row['status'] ) {
			return new WP_Error( 'mody_signup_none', 'Nothing to pay.' );
		}
		if ( RateLimit::hit( 'card_check', (string) $user_id ) ) {
			return new WP_Error( 'mody_rate_limited', __( 'Too many tries. Please wait an hour and try again.', 'mody_cloud' ) );
		}
		$customer = (string) ( $row['mollie_customer_id'] ?? '' );
		if ( '' === $customer ) {
			$made = Mollie::create_customer( $user->display_name, $user->user_email );
			if ( is_wp_error( $made ) ) {
				return $made;
			}
			$customer = (string) ( $made['id'] ?? '' );
			Members::update( $user_id, array( 'mollie_customer_id' => $customer ) );
		}
		$amount = Members::PRICE['monthly'];
		$id     = Payments::add(
			array(
				'user_id'     => $user_id,
				'mollie_id'   => 'new_' . wp_generate_password( 20, false ),
				'kind'        => 'signup',
				'amount'      => $amount,
				'sites'       => 1,
				'period'      => 'monthly',
				'covers_from' => gmdate( 'Y-m-d H:i:s' ),
				'covers_to'   => gmdate( 'Y-m-d H:i:s', (int) strtotime( '+1 month' ) ),
			)
		);
		if ( ! $id ) {
			return new WP_Error( 'mody_billing_db', __( 'That did not work. Please try again.', 'mody_cloud' ) );
		}
		$payment = array(
			'amount'       => Mollie::amount( $amount ),
			'description'  => __( 'Mody Cloud: 1 site, first month', 'mody_cloud' ),
			'redirectUrl'  => add_query_arg( 'payment', $id, $back ),
			'customerId'   => $customer,
			'sequenceType' => 'first',
			'method'       => 'creditcard',
			'metadata'     => array(
				'user' => $user_id,
				'kind' => 'signup',
			),
		);
		$webhook = self::webhook_url();
		if ( '' !== $webhook ) {
			$payment['webhookUrl'] = $webhook;
		}
		$made     = Mollie::create_payment( $payment, 'signup-' . $id );
		$checkout = is_array( $made ) ? (string) ( $made['_links']['checkout']['href'] ?? '' ) : '';
		if ( is_wp_error( $made ) || '' === $checkout || empty( $made['id'] ) ) {
			global $wpdb;
			$wpdb->delete( Payments::table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			return is_wp_error( $made ) ? $made : new WP_Error( 'mody_mollie', __( 'The payment provider did not answer. Please try again in a moment.', 'mody_cloud' ) );
		}
		Payments::update(
			$id,
			array(
				'mollie_id' => (string) $made['id'],
				'status'    => (string) ( $made['status'] ?? 'open' ),
			)
		);
		return $checkout;
	}

	/**
	 * The member came back from Mollie's checkout: ask Mollie about that
	 * payment now (no webhook reaches a local stack).
	 *
	 * @param int $user_id The member.
	 * @param int $row_id  The payment's row, from the return address.
	 * @return string `card-added`, `card-failed`, `card-waiting`, or ''.
	 */
	public static function returned( int $user_id, int $row_id ): string {
		$row = Payments::get( $row_id );
		if ( ! $row || (int) $row['user_id'] !== $user_id ) {
			return '';
		}
		$row = self::process( (string) $row['mollie_id'] ) ?? $row;
		if ( 'paid' === $row['status'] ) {
			return in_array( $row['kind'], array( 'card', 'signup' ), true ) ? 'card-added' : '';
		}
		return in_array( $row['status'], Payments::FINAL, true ) ? 'card-failed' : 'card-waiting';
	}

	/**
	 * Asks Mollie about a payment the platform started and applies it, once;
	 * fresh (the webhook), it also asks about a payment already applied, for
	 * refunds and chargebacks made since.
	 *
	 * @param string $mollie_id `tr_…`.
	 * @param bool   $fresh     Ask Mollie even when the payment was applied.
	 * @return array<string, mixed>|null The row after, or null for a payment that is not ours.
	 */
	public static function process( string $mollie_id, bool $fresh = false ): ?array {
		$row = Payments::by_mollie( $mollie_id );
		if ( ! $row ) {
			return null;
		}
		if ( (int) $row['applied'] && ! $fresh ) {
			return $row;
		}
		$payment = Mollie::get_payment( $mollie_id );
		if ( is_wp_error( $payment ) ) {
			return $row;
		}
		$status = (string) ( $payment['status'] ?? $row['status'] );
		global $wpdb;
		$lock   = 'mody_payment_' . (int) $row['id'];
		$locked = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A lock.
		try {
			$row = Payments::get( (int) $row['id'] ) ?? $row; // Another request may have applied it meanwhile.
			if ( ! $locked ) {
				return $row;
			}
			if ( ! (int) $row['applied'] ) {
				$fields = array( 'status' => $status );
				if ( in_array( $status, Payments::FINAL, true ) ) {
					self::apply( $row, $status, $payment );
					$fields['applied'] = 1;
				}
				Payments::update( (int) $row['id'], $fields );
				$row = array_merge( $row, $fields );
			}
			if ( (int) $row['applied'] && 'paid' === $row['status'] ) {
				$row = self::reversals( $row, $payment );
			}
			return $row;
		} finally {
			if ( $locked ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A lock.
			}
		}
	}

	/**
	 * Refunds and chargebacks Mollie reports on a paid payment, each acted on once.
	 *
	 * @param array<string, mixed> $row     Our row (applied, paid).
	 * @param array<string, mixed> $payment Mollie's payment.
	 * @return array<string, mixed> The row after.
	 */
	private static function reversals( array $row, array $payment ): array {
		$refunded = round( (float) ( $payment['amountRefunded']['value'] ?? 0 ), 2 );
		$back     = round( (float) ( $payment['amountChargedBack']['value'] ?? 0 ), 2 );
		$user_id  = (int) $row['user_id'];
		$fields   = array();
		if ( $refunded > (float) $row['refunded'] ) {
			$fields['refunded'] = $refunded;
			if ( empty( $row['refunded_at'] ) ) { // Not the platform's own refund (the withdrawal sets it first).
				$fields['refunded_at'] = gmdate( 'Y-m-d H:i:s' );
				self::mail_refunded( $user_id, $refunded - (float) $row['refunded'] );
			}
		}
		if ( $back > (float) $row['charged_back'] ) {
			$fields['charged_back'] = $back;
			if ( in_array( $row['kind'], array( 'charge', 'signup' ), true ) ) {
				Members::update(
					$user_id,
					array(
						'status'                   => 'past_due',
						'past_due_since'           => gmdate( 'Y-m-d H:i:s' ),
						'billing_due_at'           => $row['covers_from'], // That period is unpaid again.
						'mollie_payment_method_id' => null, // No automatic retries on a disputed card.
						'cancel_at'                => null,
					)
				);
				delete_user_meta( $user_id, self::CARD_META );
				Lifecycle::forget();
				self::mail_charged_back( $user_id, $back );
			}
		}
		if ( $fields ) {
			Payments::update( (int) $row['id'], $fields );
			$row = array_merge( $row, $fields );
		}
		return $row;
	}

	/**
	 * What a payment that is over changes.
	 *
	 * @param array<string, mixed> $row     Our row.
	 * @param string               $status  Mollie's final status.
	 * @param array<string, mixed> $payment Mollie's payment.
	 */
	private static function apply( array $row, string $status, array $payment ): void {
		$user_id = (int) $row['user_id'];
		Members::forget();
		$member = Members::get( $user_id );
		if ( ! $member ) {
			return;
		}
		if ( 'signup' === $row['kind'] ) {
			if ( 'paid' !== $status || 'pending_payment' !== $member['status'] ) {
				return;
			}
			$to = gmdate( 'Y-m-d H:i:s', (int) strtotime( '+1 month' ) );
			Payments::update( (int) $row['id'], array( 'covers_from' => gmdate( 'Y-m-d H:i:s' ), 'covers_to' => $to ) );
			Members::update(
				$user_id,
				array(
					'plan'                     => 'paid',
					'status'                   => 'active',
					'billing_due_at'           => $to,
					'mollie_customer_id'       => (string) ( $payment['customerId'] ?? $member['mollie_customer_id'] ),
					'mollie_payment_method_id' => ! empty( $payment['mandateId'] ) ? (string) $payment['mandateId'] : null,
				)
			);
			self::remember_card( $user_id, (string) ( $payment['customerId'] ?? $member['mollie_customer_id'] ), (string) ( $payment['mandateId'] ?? '' ) );
			Registration::finish_paid( $user_id );
			Invites::credit( $user_id ); // Who invited them earns their point (once).
			self::mail_card_added( $user_id );
			return;
		}
		if ( 'card' === $row['kind'] ) {
			if ( 'paid' !== $status || empty( $payment['mandateId'] ) ) {
				return;
			}
			$customer = (string) ( $payment['customerId'] ?? $member['mollie_customer_id'] );
			Members::update(
				$user_id,
				array(
					'mollie_customer_id'       => $customer,
					'mollie_payment_method_id' => (string) $payment['mandateId'],
				)
			);
			self::remember_card( $user_id, $customer, (string) $payment['mandateId'] );
			self::mail_card_added( $user_id );
			// Already due (no card when the free months ended, or a failed charge), or coming back after the plan ended: charge now.
			Members::forget();
			$member = Members::get( $user_id );
			$due    = ! empty( $member['billing_due_at'] ) ? (int) strtotime( $member['billing_due_at'] . ' UTC' ) : 0;
			if ( 'canceled' === $member['status'] || ( $due && $due <= time() && in_array( $member['status'], array( 'active', 'past_due' ), true ) ) ) {
				self::charge( $user_id );
			}
			return;
		}
		// A charge.
		if ( 'paid' === $status ) {
			Members::update(
				$user_id,
				array(
					'plan'           => 'paid',
					'status'         => 'active',
					'past_due_since' => null,
					'cancel_at'      => null,
					'billing_due_at' => $row['covers_to'],
				)
			);
			Lifecycle::forget();
			Invites::credit( $user_id ); // The first paid charge earns the inviter's point (once).
			self::mail_paid( $user_id, $row );
			return;
		}
		if ( 'active' === $member['status'] ) {
			Members::update(
				$user_id,
				array(
					'status'         => 'past_due',
					'past_due_since' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			Lifecycle::forget();
		}
		self::mail_failed( $user_id, $row );
	}

	/**
	 * Keeps the card's brand, last digits and expiry, from its mandate.
	 *
	 * @param int    $user_id  The member.
	 * @param string $customer `cst_…`.
	 * @param string $mandate  `mdt_…`.
	 */
	private static function remember_card( int $user_id, string $customer, string $mandate ): void {
		if ( '' === $mandate ) {
			return;
		}
		$found   = Mollie::get_mandate( $customer, $mandate );
		$details = is_array( $found ) ? (array) ( $found['details'] ?? array() ) : array();
		update_user_meta(
			$user_id,
			self::CARD_META,
			array(
				'label'  => sanitize_text_field( (string) ( $details['cardLabel'] ?? '' ) ),
				'number' => preg_replace( '/\D/', '', (string) ( $details['cardNumber'] ?? '' ) ),
				'expiry' => sanitize_text_field( (string) ( $details['cardExpiryDate'] ?? '' ) ),
			)
		);
	}

	/**
	 * Charges an account's card for its next period, unless a charge for
	 * that period is already paid or on its way.
	 *
	 * @param int $user_id The member.
	 * @return array<string, mixed>|WP_Error|null The new row, null when nothing was due, or the error.
	 */
	public static function charge( int $user_id ): array|WP_Error|null {
		Members::forget();
		$member  = Members::get( $user_id );
		$mandate = (string) ( $member['mollie_payment_method_id'] ?? '' );
		if ( ! $member || '' === $mandate || empty( $member['mollie_customer_id'] ) ) {
			return new WP_Error( 'mody_billing_no_card', 'No card.' );
		}
		$period = in_array( (string) $member['billing_period'], Members::PERIODS, true ) ? (string) $member['billing_period'] : 'monthly';
		$from   = ! empty( $member['billing_due_at'] ) ? (int) strtotime( $member['billing_due_at'] . ' UTC' ) : time();
		$to     = (int) strtotime( 'yearly' === $period ? '+1 year' : '+1 month', $from );
		$start  = gmdate( 'Y-m-d H:i:s', $from );
		$tries  = 0;
		foreach ( Payments::of( $user_id, 'charge', 50 ) as $earlier ) {
			if ( $earlier['covers_from'] !== $start ) {
				continue;
			}
			if ( ! in_array( $earlier['status'], array( 'failed', 'canceled', 'expired' ), true ) && (float) ( $earlier['charged_back'] ?? 0 ) <= 0 ) {
				return null; // Paid already, or on its way.
			}
			++$tries;
		}
		$sites   = max( 1, Quota::used( $user_id ) );
		$amount  = Members::total( $sites, $period );
		$payment = array(
			'amount'       => Mollie::amount( $amount ),
			'description'  => sprintf(
				/* translators: 1: number of sites, 2: a date, 3: a date. */
				_n( 'Mody Cloud: %1$d site, %2$s to %3$s', 'Mody Cloud: %1$d sites, %2$s to %3$s', $sites, 'mody_cloud' ),
				$sites,
				gmdate( 'Y-m-d', $from ),
				gmdate( 'Y-m-d', $to )
			),
			'customerId'   => (string) $member['mollie_customer_id'],
			'mandateId'    => $mandate,
			'sequenceType' => 'recurring',
			'metadata'     => array(
				'user' => $user_id,
				'kind' => 'charge',
			),
		);
		$webhook = self::webhook_url();
		if ( '' !== $webhook ) {
			$payment['webhookUrl'] = $webhook;
		}
		$made = Mollie::create_payment( $payment, 'charge-' . $user_id . '-' . $from . '-' . $tries );
		if ( is_wp_error( $made ) || empty( $made['id'] ) ) {
			return is_wp_error( $made ) ? $made : new WP_Error( 'mody_mollie', 'No payment.' );
		}
		$id = Payments::add(
			array(
				'user_id'     => $user_id,
				'mollie_id'   => (string) $made['id'],
				'kind'        => 'charge',
				'amount'      => $amount,
				'sites'       => $sites,
				'period'      => $period,
				'covers_from' => $start,
				'covers_to'   => gmdate( 'Y-m-d H:i:s', $to ),
				'status'      => (string) ( $made['status'] ?? 'pending' ),
			)
		);
		if ( in_array( (string) ( $made['status'] ?? '' ), Payments::FINAL, true ) ) {
			self::process( (string) $made['id'] );
		}
		return Payments::get( $id );
	}

	/**
	 * The withdrawal an account can still make: its first charge, paid less
	 * than WITHDRAWAL_DAYS ago and not refunded.
	 *
	 * @param int $user_id The member.
	 * @return array{row: array<string, mixed>, until: int}|null
	 */
	public static function withdrawal( int $user_id ): ?array {
		$member = Members::get( $user_id );
		if ( ! $member || 'canceled' === $member['status'] ) {
			return null;
		}
		$paid = array_values( array_filter( Payments::of( $user_id, '', 50 ), static fn( array $r ): bool => 'paid' === $r['status'] && in_array( $r['kind'], array( 'charge', 'signup' ), true ) ) );
		if ( 1 !== count( $paid ) || ! empty( $paid[0]['refunded_at'] ) ) {
			return null; // Only the account's first charge.
		}
		$until = (int) strtotime( $paid[0]['created_at'] . ' UTC' ) + self::WITHDRAWAL_DAYS * DAY_IN_SECONDS;
		return $until > time()
			? array(
				'row'   => $paid[0],
				'until' => $until,
			)
			: null;
	}

	/**
	 * Withdraws: the first charge back in full, and the account canceled.
	 *
	 * @param int $user_id The member.
	 * @return true|WP_Error
	 */
	public static function withdraw( int $user_id ): bool|WP_Error {
		$window = self::withdrawal( $user_id );
		if ( ! $window ) {
			return new WP_Error( 'mody_withdrawal_over', __( 'The 14 days to withdraw are over.', 'mody_cloud' ) );
		}
		$row    = $window['row'];
		$refund = Mollie::refund( (string) $row['mollie_id'], (float) $row['amount'], __( 'Mody Cloud: withdrawal', 'mody_cloud' ) );
		if ( is_wp_error( $refund ) ) {
			return $refund;
		}
		Payments::update(
			(int) $row['id'],
			array(
				'refunded_at' => gmdate( 'Y-m-d H:i:s' ),
				'refunded'    => (float) $row['amount'],
			)
		);
		Members::update(
			$user_id,
			array(
				'status'         => 'canceled',
				'billing_due_at' => null,
				'cancel_at'      => null,
				'past_due_since' => gmdate( 'Y-m-d H:i:s' ), // Lifecycle counts the 60 days from here.
			)
		);
		Lifecycle::forget();
		Invites::uncredit( $user_id ); // Refunded in full: no point for who invited them.
		self::mail_withdrawn( $user_id, (float) $row['amount'] );
		return true;
	}

	/**
	 * admin-post: Withdraw from the contract (confirmed).
	 */
	public static function start_withdrawal(): void {
		check_admin_referer( self::WITHDRAW_ACTION );
		$user = get_current_user_id();
		if ( empty( $_POST['confirm'] ) ) {
			wp_safe_redirect( self::plan_url( $user, array( 'billing' => 'confirm' ) ) );
			exit;
		}
		$done = self::withdraw( $user );
		wp_safe_redirect( self::plan_url( $user, array( 'billing' => true === $done ? 'withdrawn' : 'withdraw-error' ) ) );
		exit;
	}

	/**
	 * When the card on file stops working, if that is before the next
	 * charge: the card's last valid day, or 0 when it is fine (or unknown).
	 *
	 * @param int $user_id The member.
	 */
	public static function card_expires_first( int $user_id ): int {
		$member = Members::get( $user_id );
		$card   = (array) get_user_meta( $user_id, self::CARD_META, true );
		if ( ! $member || empty( $member['mollie_payment_method_id'] ) || empty( $member['billing_due_at'] ) || ! empty( $member['cancel_at'] ) || empty( $card['expiry'] ) ) {
			return 0;
		}
		$expires = (int) strtotime( $card['expiry'] . ' 23:59:59 UTC' );
		$due     = (int) strtotime( $member['billing_due_at'] . ' UTC' );
		return $expires && $expires < $due ? $expires : 0;
	}

	/**
	 * Whether an account can cancel now: active, with a card, a date to end
	 * on, and not cancelling already. (Without a card, the free months simply
	 * end.)
	 *
	 * @param int $user_id The member.
	 */
	public static function can_cancel( int $user_id ): bool {
		$member = Members::get( $user_id );
		return $member && 'active' === $member['status'] && ! empty( $member['mollie_payment_method_id'] ) && ! empty( $member['billing_due_at'] ) && empty( $member['cancel_at'] );
	}

	/**
	 * Cancels at the end of the period: nothing more is charged, and the
	 * plan ends on `billing_due_at`.
	 *
	 * @param int $user_id The member.
	 * @return true|WP_Error
	 */
	public static function cancel( int $user_id ): bool|WP_Error {
		if ( ! self::can_cancel( $user_id ) ) {
			return new WP_Error( 'mody_cancel', __( 'There is nothing to cancel.', 'mody_cloud' ) );
		}
		$member = Members::get( $user_id );
		Members::update( $user_id, array( 'cancel_at' => $member['billing_due_at'] ) );
		$ends = (int) strtotime( $member['billing_due_at'] . ' UTC' );
		self::mail(
			$user_id,
			__( 'Mody Cloud: your plan ends soon', 'mody_cloud' ),
			sprintf(
				/* translators: 1: a date, 2: days, 3: Plan & billing's address. */
				__( "You have cancelled. Nothing more will be charged, and your plan ends on %1\$s. After that your site is read-only for %2\$d days, then deleted: download it before then if you want to keep it. Changed your mind? Use Keep my plan on Plan & billing before that date:
%3\$s", 'mody_cloud' ),
				wp_date( (string) get_option( 'date_format' ), $ends ),
				Lifecycle::DELETE_DAY,
				self::plan_url( $user_id )
			)
		);
		return true;
	}

	/**
	 * Undoes a cancellation before its date.
	 *
	 * @param int $user_id The member.
	 * @return true|WP_Error
	 */
	public static function resume( int $user_id ): bool|WP_Error {
		$member = Members::get( $user_id );
		if ( ! $member || 'active' !== $member['status'] || empty( $member['cancel_at'] ) ) {
			return new WP_Error( 'mody_resume', __( 'There is no cancellation to undo.', 'mody_cloud' ) );
		}
		Members::update( $user_id, array( 'cancel_at' => null ) );
		return true;
	}

	/**
	 * admin-post: Cancel (confirmed).
	 */
	public static function start_cancel(): void {
		check_admin_referer( self::CANCEL_ACTION );
		$user = get_current_user_id();
		if ( empty( $_POST['confirm'] ) ) {
			wp_safe_redirect( self::plan_url( $user, array( 'billing' => 'confirm-cancel' ) ) );
			exit;
		}
		$done = self::cancel( $user );
		wp_safe_redirect( self::plan_url( $user, array( 'billing' => true === $done ? 'canceled' : 'cancel-error' ) ) );
		exit;
	}

	/**
	 * admin-post: Keep my plan.
	 */
	public static function start_resume(): void {
		check_admin_referer( self::RESUME_ACTION );
		$user = get_current_user_id();
		$done = self::resume( $user );
		wp_safe_redirect( self::plan_url( $user, array( 'billing' => true === $done ? 'resumed' : 'cancel-error' ) ) );
		exit;
	}

	/**
	 * The plan reached the date it was cancelled for: canceled, nothing
	 * charged, the 60 days start.
	 *
	 * @param int $user_id The member.
	 */
	private static function end_plan( int $user_id ): void {
		Members::update(
			$user_id,
			array(
				'status'         => 'canceled',
				'cancel_at'      => null,
				'billing_due_at' => null,
				'past_due_since' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		Lifecycle::forget();
		self::mail(
			$user_id,
			__( 'Mody Cloud: your plan has ended', 'mody_cloud' ),
			sprintf(
				/* translators: 1: days, 2: Plan & billing's address. */
				__( "Your plan has ended, as you asked, and nothing more will be charged. Your site stays online, read-only, for %1\$d days, then it is deleted: download it before then if you want to keep it. To keep it online, add a card on Plan & billing:
%2\$s", 'mody_cloud' ),
				Lifecycle::DELETE_DAY,
				self::plan_url( $user_id )
			)
		);
	}

	/**
	 * The daily run: reminders, charges, retries, payments still waiting.
	 *
	 * @return array<string, int> What it did.
	 */
	public static function run(): array {
		global $wpdb;
		$done = array_fill_keys( array( 'reminders', 'expiring', 'charged', 'no_card', 'retried', 'checked', 'ended', 'unpaid', 'warned', 'deleted' ), 0 );
		if ( ! (bool) $wpdb->get_var( "SELECT GET_LOCK('mody_billing_run', 1)" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A lock.
			return $done;
		}
		try {
			$table = Members::table();
			$now   = gmdate( 'Y-m-d H:i:s' );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.

			// Payments still waiting first: what they change counts for the rest.
			foreach ( Payments::waiting() as $row ) {
				self::process( (string) $row['mollie_id'] );
				++$done['checked'];
			}

			// Free accounts without a card: a month and a week before.
			$soon = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, free_until FROM {$table} WHERE plan = 'free' AND status = 'active' AND mollie_payment_method_id IS NULL AND cancel_at IS NULL AND free_until > %s AND free_until <= %s", $now, gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ), ARRAY_A );
			foreach ( (array) $soon as $member ) {
				$user = (int) $member['user_id'];
				$sent = (array) get_user_meta( $user, self::REMINDERS_META, true );
				$left = (int) strtotime( $member['free_until'] . ' UTC' ) - time();
				$kind = $left <= 7 * DAY_IN_SECONDS ? 'week' : 'month';
				if ( empty( $sent[ $kind ] ) && ( 'month' === $kind || empty( $sent['week'] ) ) ) {
					self::mail_reminder( $user, (int) strtotime( $member['free_until'] . ' UTC' ) );
					$sent[ $kind ] = time();
					if ( 'week' === $kind ) {
						$sent['month'] = $sent['month'] ?? time();
					}
					update_user_meta( $user, self::REMINDERS_META, $sent );
					++$done['reminders'];
				}
			}

			// Cards that expire before a charge in the next 30 days: one email per card.
			$soon_due = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE status = 'active' AND cancel_at IS NULL AND mollie_payment_method_id IS NOT NULL AND billing_due_at > %s AND billing_due_at <= %s", $now, gmdate( 'Y-m-d H:i:s', time() + self::EXPIRY_NOTICE_DAYS * DAY_IN_SECONDS ) ) );
			foreach ( (array) $soon_due as $user ) {
				$user    = (int) $user;
				$expires = self::card_expires_first( $user );
				$mandate = (string) Members::get( $user )['mollie_payment_method_id'];
				if ( $expires && get_user_meta( $user, self::EXPIRY_META, true ) !== $mandate ) {
					self::mail_card_expires( $user, $expires );
					update_user_meta( $user, self::EXPIRY_META, $mandate );
					++$done['expiring'];
				}
			}

			// Due: charge the card, or past due without one.
			$due = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE status = 'active' AND billing_due_at IS NOT NULL AND billing_due_at <= %s", $now ) );
			foreach ( (array) $due as $user ) {
				$user   = (int) $user;
				$member = Members::get( $user );
				if ( ! empty( $member['cancel_at'] ) ) {
					self::end_plan( $user );
					++$done['ended'];
					continue;
				}
				if ( empty( $member['mollie_payment_method_id'] ) ) {
					Members::update(
						$user,
						array(
							'status'         => 'past_due',
							'past_due_since' => $now,
						)
					);
					Lifecycle::forget();
					self::mail_no_card( $user );
					++$done['no_card'];
					continue;
				}
				if ( is_array( self::charge( $user ) ) ) {
					++$done['charged'];
				}
			}

			// Past due with a card: try again once a day, for RETRY_DAYS.
			$late = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE status = 'past_due' AND mollie_payment_method_id IS NOT NULL AND past_due_since > %s", gmdate( 'Y-m-d H:i:s', time() - self::RETRY_DAYS * DAY_IN_SECONDS ) ) );
			foreach ( (array) $late as $user ) {
				$user = (int) $user;
				$last = Payments::of( $user, 'charge', 1 );
				if ( $last && ( ! in_array( $last[0]['status'], Payments::FINAL, true ) || strtotime( $last[0]['created_at'] . ' UTC' ) > time() - DAY_IN_SECONDS + HOUR_IN_SECONDS ) ) {
					continue; // On its way, or tried in the last day.
				}
				if ( is_array( self::charge( $user ) ) ) {
					++$done['retried'];
				}
			}
			// phpcs:enable

			// Paid sign-ups not paid within a day: no site, nothing paid, so the account goes.
			$stale = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE status = 'pending_payment' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - Registration::PAY_HOURS * HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
			foreach ( (array) $stale as $user ) {
				$user = (int) $user;
				if ( Lifecycle::owned_sites( $user ) || array_filter( Payments::of( $user ), static fn( array $r ): bool => 'paid' === $r['status'] ) ) {
					continue; // Never remove an account with a site or a payment.
				}
				require_once ABSPATH . 'wp-admin/includes/ms.php';
				Members::delete( $user );
				wpmu_delete_user( $user );
				++$done['unpaid'];
			}

			// Locked accounts: the last warning on day 53, the sites deleted on day 60.
			$done = array_merge( $done, Lifecycle::sweep() );
		} finally {
			$wpdb->get_var( "SELECT RELEASE_LOCK('mody_billing_run')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A lock.
		}
		return $done;
	}

	/* Emails ---------------------------------------------------------------------- */

	/**
	 * Who sells: the network options `mody_seller_name` and
	 * `mody_seller_address` (set before taking real money), or the platform's name.
	 */
	public static function seller(): string {
		$name    = trim( (string) get_site_option( 'mody_seller_name', '' ) );
		$address = trim( (string) get_site_option( 'mody_seller_address', '' ) );
		return trim( ( '' !== $name ? $name : 'Mody Cloud' ) . ( '' !== $address ? ', ' . $address : '' ) );
	}

	/**
	 * Sends a member an email, signed by the platform.
	 *
	 * @param int    $user_id The member.
	 * @param string $subject Subject.
	 * @param string $body    Text, after "Hello <name>,".
	 */
	private static function mail( int $user_id, string $subject, string $body ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		/* translators: %s: the member's name. */
		$hello = sprintf( __( 'Hello %s,', 'mody_cloud' ), $user->display_name );
		wp_mail( $user->user_email, $subject, $hello . "\n\n" . $body . "\n\nMody Cloud" );
	}

	/**
	 * The card is added: the terms, and how to withdraw (a durable copy, as
	 * EU law asks), with the model withdrawal form.
	 *
	 * @param int $user_id The member.
	 */
	private static function mail_card_added( int $user_id ): void {
		$url  = self::plan_url( $user_id );
		$full = self::legal_url( 'terms' );
		$body = sprintf(
			/* translators: 1: the terms, 2: Plan & billing's address, 3: days, 4: who sells. */
			__( "Your card is added. What you agreed to:\n\n%1\$s\n\nYour plan and payments: %2\$s\n\nYour right to withdraw: within %3\$d days of your first payment you can withdraw from the contract, without giving a reason, and get that payment back in full. Use the Withdraw button on your Plan & billing page, or reply to this email. If you prefer, you can use this form:\n\nTo %4\$s: I hereby give notice that I withdraw from my contract for the Mody Cloud service. Ordered on: [date]. Name: [your name]. Email of the account: [email]. Date: [date].", 'mody_cloud' ),
			self::terms( $user_id ),
			$url,
			self::WITHDRAWAL_DAYS,
			self::seller()
		);
		if ( '' !== $full ) {
			/* translators: %s: the terms of service's address. */
			$body .= "\n\n" . sprintf( __( 'The full terms of service: %s', 'mody_cloud' ), $full );
		}
		self::mail( $user_id, __( 'Mody Cloud: your card is added', 'mody_cloud' ), $body );
	}

	/**
	 * A charge went through.
	 *
	 * @param int                  $user_id The member.
	 * @param array<string, mixed> $row     The charge.
	 */
	private static function mail_paid( int $user_id, array $row ): void {
		$body = sprintf(
			/* translators: 1: an amount, 2: number of sites, 3: a date, 4: a date, 5: Plan & billing's address. */
			_n( "We charged %1\$s to your card for %2\$d site, from %3\$s to %4\$s, VAT included. Thank you.\n\nYour plan and payments: %5\$s", "We charged %1\$s to your card for %2\$d sites, from %3\$s to %4\$s, VAT included. Thank you.\n\nYour plan and payments: %5\$s", (int) $row['sites'], 'mody_cloud' ),
			Members::price( (float) $row['amount'] ),
			(int) $row['sites'],
			wp_date( (string) get_option( 'date_format' ), (int) strtotime( $row['covers_from'] . ' UTC' ) ),
			wp_date( (string) get_option( 'date_format' ), (int) strtotime( $row['covers_to'] . ' UTC' ) ),
			self::plan_url( $user_id )
		);
		self::mail( $user_id, __( 'Mody Cloud: payment received', 'mody_cloud' ), $body );
	}

	/**
	 * A charge failed.
	 *
	 * @param int                  $user_id The member.
	 * @param array<string, mixed> $row     The charge.
	 */
	private static function mail_failed( int $user_id, array $row ): void {
		$body = sprintf(
			/* translators: 1: an amount, 2: days, 3: Plan & billing's address. */
			__( "We could not charge %1\$s to your card. We will try again every day. Your sites stay online, but you cannot change them until a payment goes through; after %2\$d days without one, they are deleted. You can add another card, or download your site, here:\n%3\$s", 'mody_cloud' ),
			Members::price( (float) $row['amount'] ),
			self::RETRY_DAYS,
			self::plan_url( $user_id )
		);
		self::mail( $user_id, __( 'Mody Cloud: your payment did not go through', 'mody_cloud' ), $body );
	}

	/**
	 * The free months ended without a card.
	 *
	 * @param int $user_id The member.
	 */
	private static function mail_no_card( int $user_id ): void {
		$body = sprintf(
			/* translators: 1: days, 2: Plan & billing's address. */
			__( "Your free months have ended, and there is no card on your account. Your site stays online, but you cannot change it until you add a card; after %1\$d days without one, it is deleted. Add your card, or download your site, here:\n%2\$s", 'mody_cloud' ),
			self::RETRY_DAYS,
			self::plan_url( $user_id )
		);
		self::mail( $user_id, __( 'Mody Cloud: add a card to keep your site', 'mody_cloud' ), $body );
	}

	/**
	 * The free months end soon, and there is no card yet.
	 *
	 * @param int $user_id The member.
	 * @param int $until   When they end.
	 */
	private static function mail_reminder( int $user_id, int $until ): void {
		$body = sprintf(
			/* translators: 1: a date, 2: a site's monthly price, 3: a site's yearly price, 4: days, 5: Plan & billing's address. */
			__( "Your site is free until %1\$s. To keep it after that, add a card: %2\$s a month or %3\$s a year per site, VAT included, charged from that day. Without a card, your site becomes read-only and is deleted %4\$d days later.\n\nAdd your card: %5\$s", 'mody_cloud' ),
			wp_date( (string) get_option( 'date_format' ), $until ),
			Members::price( Members::PRICE['monthly'] ),
			Members::price( Members::PRICE['yearly'] ),
			self::RETRY_DAYS,
			self::plan_url( $user_id )
		);
		self::mail( $user_id, __( 'Mody Cloud: your free months end soon', 'mody_cloud' ), $body );
	}

	/**
	 * The card expires before the next charge.
	 *
	 * @param int $user_id The member.
	 * @param int $expires The card's last valid day.
	 */
	private static function mail_card_expires( int $user_id, int $expires ): void {
		$member = Members::get( $user_id );
		$body   = sprintf(
			/* translators: 1: a date, 2: a date, 3: Plan & billing's address. */
			__( "The card on your account expires on %1\$s, before your next payment on %2\$s. Please add another card, so the payment goes through and your site stays as it is:
%3\$s", 'mody_cloud' ),
			wp_date( (string) get_option( 'date_format' ), $expires ),
			wp_date( (string) get_option( 'date_format' ), (int) strtotime( $member['billing_due_at'] . ' UTC' ) ),
			self::plan_url( $user_id )
		);
		self::mail( $user_id, __( 'Mody Cloud: your card expires before your next payment', 'mody_cloud' ), $body );
	}

	/**
	 * A refund made in Mollie's dashboard.
	 *
	 * @param int   $user_id The member.
	 * @param float $amount  How much.
	 */
	private static function mail_refunded( int $user_id, float $amount ): void {
		$body = sprintf(
			/* translators: 1: an amount, 2: Plan & billing's address. */
			__( "We have refunded %1\$s to your card; banks take a few days to show it. Your plan is unchanged.\n\nYour plan and payments: %2\$s", 'mody_cloud' ),
			Members::price( $amount ),
			self::plan_url( $user_id )
		);
		self::mail( $user_id, __( 'Mody Cloud: a refund is on its way', 'mody_cloud' ), $body );
	}

	/**
	 * A chargeback: the card's bank took a payment back.
	 *
	 * @param int   $user_id The member.
	 * @param float $amount  How much.
	 */
	private static function mail_charged_back( int $user_id, float $amount ): void {
		$body = sprintf(
			/* translators: 1: an amount, 2: days, 3: Plan & billing's address. */
			__( "Your card's bank took back a payment of %1\$s, so that period is unpaid again, and we removed the card from your account. Your site stays online, but you cannot change it until you pay with a card; after %2\$d days without a payment it is deleted. If this was a mistake, add a card here and the period is charged again:\n%3\$s", 'mody_cloud' ),
			Members::price( $amount ),
			Lifecycle::DELETE_DAY,
			self::plan_url( $user_id )
		);
		self::mail( $user_id, __( 'Mody Cloud: your bank took a payment back', 'mody_cloud' ), $body );
	}

	/**
	 * The withdrawal is done.
	 *
	 * @param int   $user_id The member.
	 * @param float $amount  What comes back.
	 */
	private static function mail_withdrawn( int $user_id, float $amount ): void {
		$body = sprintf(
			/* translators: 1: an amount, 2: days, 3: Plan & billing's address. */
			__( "You have withdrawn from your contract. %1\$s goes back to your card; banks take a few days to show it. Nothing more will be charged. Your site stays online, read-only, for %2\$d days, then it is deleted: download it first if you want to keep it.\n%3\$s", 'mody_cloud' ),
			Members::price( $amount ),
			self::RETRY_DAYS,
			self::plan_url( $user_id )
		);
		self::mail( $user_id, __( 'Mody Cloud: you have withdrawn', 'mody_cloud' ), $body );
	}
}
