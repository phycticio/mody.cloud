<?php
/**
 * Member accounts: the `mody_members` table, plans and their site limits
 * (mvp-spec sections 3, 4 and 11; ticket M1-01, part 1).
 *
 * @package Mody\Members
 */

namespace Mody\Members;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One row per member account, network-wide.
 *
 * - **Plans** (membership-tiers.md): `plan` is `free` or `paid`. A plan is
 *   a column, not a WordPress role (decided 2026-11-08): roles are per site
 *   in Multisite, and members are `administrator` of their own sites.
 * - **A free service** (decided by the maintainer on 2026-11-20,
 *   tickets/free-mvp.md; it replaces the launch offer and the price per
 *   site of 2026-11-13): every account starts free, with one site, no card
 *   and no end date (start_free(): `free_until` and `billing_due_at` stay
 *   empty, so Mody Billing's daily run never reminds, charges or locks it).
 *   Anyone may sign up while registration is open, or only invited people
 *   (Offers::invited_only()). Paid tiers come later (freemium): `paid`,
 *   PRICE and the billing code are kept for them, switched off
 *   (Billing\Mollie::offered()).
 * - **Statuses** (spec section 4): `active` (free accounts included),
 *   `pending_payment` until a card is verified, `past_due` after a failed
 *   charge (paid accounts; never a free one since 2026-11-20), `canceled` after a
 *   withdrawal (Billing\Billing::withdraw()), `expired_signup` when no payment
 *   arrived within 24 hours, and `expired` once the sites are deleted after
 *   60 days past due or canceled (M1-02).
 * - **What a row gives:** the dashboard capability (spec section 3) and the
 *   plan's site limit (`mody_site_limit`). No site may be created before the
 *   card is verified (`mody_can_create_site`, decided 2026-11-08). Past-due
 *   accounts are M1-02's.
 *
 * Users without a row keep the defaults that applied before M1: no dashboard
 * capability (super admins pass every check anyway) and
 * Provisioning\Quota::DEFAULT_LIMIT.
 */
final class Members {

	/** The network's Mody menu (Mody Sites' New site page): members open it. */
	public const MENU = 'mody-new-site';

	/** The capability that opens it, given to every member (Mody Sites' WizardPage::CAPABILITY). */
	public const MENU_CAPABILITY = 'mody_access_dashboard';

	/** The plans: free (everyone now), and paying per site (later, switched off). */
	public const PLANS = array( 'free', 'paid' );

	/** One site's price in EUR, VAT included (decided 2026-11-13; unused while payments are off, 2026-11-20). */
	public const PRICE = array(
		'monthly' => 7.99,
		'yearly'  => 79.99,
	);

	/**
	 * The sites an account may have: one free; ten when paying, each paid
	 * (a real limit, rule 7; more is not offered yet).
	 */
	public const SITE_LIMITS = array(
		'free' => 1,
		'paid' => 10,
	);

	/** The billing periods. */
	public const PERIODS = array( 'monthly', 'yearly' );

	/** The launch offer's free months, for the version 4 migration of older trials only. */
	private const OLD_FREE_MONTHS = 6;

	/** The launch offer's network options (2026-11-13 to 2026-11-20), removed by version 8. */
	private const OLD_PROMO_OPTIONS = array( 'mody_promo_starts_at', 'mody_promo_given' );

	/** The plans before 2026-11-13, and the plan each became. */
	private const OLD_PLANS = array(
		'trial'    => 'free',
		'sites_1'  => 'paid',
		'sites_3'  => 'paid',
		'sites_10' => 'paid',
	);

	/** Statuses whose card is not verified yet (spec section 4): no site may be created. */
	public const UNPAID_STATUSES = array( 'pending_payment', 'expired_signup' );

	/** Account statuses, in the order an account usually moves through them. */
	public const STATUSES = array( 'pending_payment', 'active', 'past_due', 'canceled', 'expired_signup', 'expired' );

	/** Bump when the table definition in install() changes. */
	private const DB_VERSION = '8'; // 2: next_plan (M1-01 part 2). 3: the 2026-11-10 plans and billing_period. 4: free and paid, free_until, no next_plan (2026-11-13). 5: `canceled`. 6: cancel_at. 7: invited_by, invite_point_at (2026-11-14). 8: free accounts without an end (2026-11-20).

	/** Network option that records the installed table version. */
	private const DB_VERSION_OPTION = 'mody_members_db_version';

	/**
	 * Rows already read in this request: user ID => row, or null for none.
	 * Capability checks ask often.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private static array $rows = array();

	/**
	 * The table name. The table is network-wide, so it uses the base prefix.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'mody_members';
	}

	/**
	 * Creates or updates the table when its version changed. Cheap otherwise:
	 * one network option read, which the object cache serves.
	 */
	public static function install(): void {
		if ( self::DB_VERSION === get_site_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// Version 4: the old plans become free and paid; the trial's end becomes free_until, six months after signup.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Own table; constants only.
			$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
			$both    = "'" . implode( "','", array_unique( array_merge( array( 'collaborator', 'member', 'patron' ), array_keys( self::OLD_PLANS ), self::PLANS ) ) ) . "'";
			$wpdb->query( "ALTER TABLE {$table} MODIFY plan enum({$both}) NOT NULL DEFAULT 'free'" );
			$wpdb->query( "UPDATE {$table} SET plan = 'paid' WHERE plan IN ('collaborator','member','patron')" );
			foreach ( self::OLD_PLANS as $old => $new ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET plan = %s WHERE plan = %s", $new, $old ) );
			}
			if ( in_array( 'trial_ends_at', $columns, true ) && ! in_array( 'free_until', $columns, true ) ) {
				$wpdb->query( "ALTER TABLE {$table} CHANGE trial_ends_at free_until datetime DEFAULT NULL" );
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET free_until = DATE_ADD(created_at, INTERVAL %d MONTH), billing_due_at = DATE_ADD(created_at, INTERVAL %d MONTH) WHERE plan = 'free'", self::OLD_FREE_MONTHS, self::OLD_FREE_MONTHS ) );
			if ( in_array( 'next_plan', $columns, true ) ) {
				$wpdb->query( "ALTER TABLE {$table} DROP COLUMN next_plan" );
			}
			// phpcs:enable
		}

		// dbDelta() needs this exact layout: one column per line, two spaces after PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$table} (
  user_id bigint(20) unsigned NOT NULL,
  plan enum('free','paid') NOT NULL DEFAULT 'free',
  billing_period enum('monthly','yearly') NOT NULL DEFAULT 'monthly',
  status enum('pending_payment','active','past_due','canceled','expired_signup','expired') NOT NULL,
  free_until datetime DEFAULT NULL,
  billing_due_at datetime DEFAULT NULL,
  past_due_since datetime DEFAULT NULL,
  cancel_at datetime DEFAULT NULL,
  mollie_customer_id varchar(64) DEFAULT NULL,
  mollie_payment_method_id varchar(64) DEFAULT NULL,
  invited_by bigint(20) unsigned DEFAULT NULL,
  invite_point_at datetime DEFAULT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (user_id),
  KEY status (status),
  KEY invited_by (invited_by)
) {$charset};"
		);

		self::free_without_end();
		update_site_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Version 8 (decided by the maintainer on 2026-11-20): every free account
	 * is free with no end. The launch offer's end, its first billing date and
	 * a cancellation at that end are emptied; a free account the daily run
	 * made past due or cancelled for want of a card is active again (its
	 * sites were never deleted while it was not `expired`). Paid accounts are
	 * left as they are. The offer's counters go.
	 */
	private static function free_without_end(): void {
		global $wpdb;
		$table = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check.
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; constants only.
		$wpdb->query( "UPDATE {$table} SET free_until = NULL, billing_due_at = NULL, cancel_at = NULL, past_due_since = NULL, status = IF(status IN ('past_due','canceled'), 'active', status) WHERE plan = 'free' AND status IN ('active','past_due','canceled')" );
		foreach ( self::OLD_PROMO_OPTIONS as $option ) {
			delete_site_option( $option );
		}
		self::$rows = array();
	}

	/**
	 * A member's row.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, mixed>|null Null when the user is not a member.
	 */
	public static function get( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}
		if ( ! array_key_exists( $user_id, self::$rows ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; cached above.
			$row                    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d', $user_id ), ARRAY_A );
			self::$rows[ $user_id ] = is_array( $row ) ? $row : null;
		}
		return self::$rows[ $user_id ];
	}

	/**
	 * Creates a member's row. Registration is its caller (start_free()).
	 *
	 * @param int    $user_id An existing user.
	 * @param string $plan    One of PLANS.
	 * @param string $status  One of STATUSES.
	 * @return true|WP_Error
	 */
	public static function create( int $user_id, string $plan = 'free', string $status = 'pending_payment' ): bool|WP_Error {
		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'mody_member_no_user', __( 'There is no such user.', 'mody_cloud' ) );
		}
		if ( ! in_array( $plan, self::PLANS, true ) ) {
			return new WP_Error( 'mody_member_plan', __( 'There is no such plan.', 'mody_cloud' ) );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'mody_member_status', __( 'There is no such account status.', 'mody_cloud' ) );
		}
		if ( self::get( $user_id ) ) {
			return new WP_Error( 'mody_member_exists', __( 'This user already has a member account.', 'mody_cloud' ) );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'user_id' => $user_id,
				'plan'    => $plan,
				'status'  => $status,
			),
			array( '%d', '%s', '%s' )
		);
		unset( self::$rows[ $user_id ] );
		return $inserted ? true : new WP_Error( 'mody_member_db', __( 'The member account could not be saved.', 'mody_cloud' ) );
	}

	/**
	 * Makes a new account free: plan `free`, status `active`, one site, no
	 * card and no end date (decided by the maintainer on 2026-11-20).
	 *
	 * @param int  $user_id An existing user without a row.
	 * @param bool $invited Whether they signed up through an invite link (Offers::invited_only()).
	 * @return true|WP_Error `mody_signups_invited_only` when sign-ups are for invited people
	 *                       only and they were not.
	 */
	public static function start_free( int $user_id, bool $invited = false ): bool|WP_Error {
		if ( ! self::free_open( $invited ) ) {
			return new WP_Error( 'mody_signups_invited_only', self::free_closed_message() );
		}
		return self::create( $user_id, 'free', 'active' );
	}

	/**
	 * Whether a new free account can start: everyone may sign up, or only
	 * invited people and they were (Offers, since 2026-11-14).
	 *
	 * @param bool $invited Whether the visitor came through an invite link.
	 */
	public static function free_open( bool $invited = false ): bool {
		return $invited || ! Offers::invited_only();
	}

	/**
	 * What a visitor reads when sign-ups do not take their account: they
	 * are for invited people only.
	 */
	public static function free_closed_message(): string {
		return __( 'Sign-ups are by invitation for now: sign up through the link a member sent you.', 'mody_cloud' );
	}

	/**
	 * When a member's free months end, as a Unix timestamp, or 0 when they
	 * do not end (every free account since 2026-11-20) or the plan is not free.
	 *
	 * @param int $user_id User ID.
	 */
	public static function free_until( int $user_id ): int {
		$row = self::get( $user_id );
		if ( ! $row || 'free' !== $row['plan'] || empty( $row['free_until'] ) ) {
			return 0;
		}
		return (int) strtotime( $row['free_until'] . ' UTC' );
	}

	/**
	 * Changes columns of a member's row.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $fields  Columns; null writes NULL.
	 */
	public static function update( int $user_id, array $fields ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->update( self::table(), $fields, array( 'user_id' => $user_id ) );
		unset( self::$rows[ $user_id ] );
	}

	/**
	 * Forgets the rows read in this request (another process changed them).
	 */
	public static function forget(): void {
		self::$rows = array();
	}

	/**
	 * Removes a member's row (tests; account deletion comes later).
	 *
	 * @param int $user_id User ID.
	 */
	public static function delete( int $user_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->delete( self::table(), array( 'user_id' => $user_id ), array( '%d' ) );
		unset( self::$rows[ $user_id ] );
	}

	/**
	 * `mody_site_limit`: a member's limit is their plan's.
	 *
	 * @param int $limit   The limit so far (Quota::DEFAULT_LIMIT).
	 * @param int $user_id User ID.
	 */
	public static function site_limit( int $limit, int $user_id ): int {
		$row = self::get( $user_id );
		return $row ? (int) ( self::SITE_LIMITS[ $row['plan'] ] ?? self::SITE_LIMITS['free'] ) : $limit;
	}

	/**
	 * A plan's name, translated.
	 *
	 * @param string $plan One of PLANS.
	 */
	public static function plan_label( string $plan ): string {
		return match ( $plan ) {
			'free'  => __( 'Free', 'mody_cloud' ),
			'paid'  => __( 'Per site', 'mody_cloud' ),
			default => $plan,
		};
	}

	/**
	 * A price: 7.99 EUR.
	 *
	 * @param float $eur Euros.
	 */
	public static function price( float $eur ): string {
		/* translators: %s: an amount in euros, such as 7.99. */
		return sprintf( __( '%s EUR', 'mody_cloud' ), number_format_i18n( $eur, 2 ) );
	}

	/**
	 * What an account pays per period: the price times its sites (at least one).
	 *
	 * @param int    $sites  Sites.
	 * @param string $period One of PERIODS.
	 */
	public static function total( int $sites, string $period ): float {
		return round( max( 1, $sites ) * ( self::PRICE[ $period ] ?? self::PRICE['monthly'] ), 2 );
	}

	/**
	 * A member chooses how to pay once the free months end, or from the next
	 * billing date: `billing_period`. The card and the charge come with
	 * Mollie (M1-01 part 3).
	 *
	 * @param int    $user_id A member.
	 * @param string $period  One of PERIODS.
	 * @return true|WP_Error
	 */
	public static function choose_period( int $user_id, string $period ): bool|WP_Error {
		if ( ! self::get( $user_id ) ) {
			return new WP_Error( 'mody_member_none', __( 'This account has no plan.', 'mody_cloud' ) );
		}
		if ( ! in_array( $period, self::PERIODS, true ) ) {
			return new WP_Error( 'mody_member_period', __( 'There is no such billing period.', 'mody_cloud' ) );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->update( self::table(), array( 'billing_period' => $period ), array( 'user_id' => $user_id ), array( '%s' ), array( '%d' ) );
		unset( self::$rows[ $user_id ] );
		return true;
	}

	/**
	 * What paying yearly saves on twelve monthly payments, per site, in EUR.
	 */
	public static function yearly_saving(): float {
		return round( 12 * self::PRICE['monthly'] - self::PRICE['yearly'], 2 );
	}

	/**
	 * Whether a member's card is not verified yet (`pending_payment`, or
	 * `expired_signup` after 24 hours without payment).
	 *
	 * @param int $user_id User ID.
	 */
	public static function payment_pending( int $user_id ): bool {
		$row = self::get( $user_id );
		return $row && in_array( $row['status'], self::UNPAID_STATUSES, true );
	}

	/**
	 * `mody_can_create_site`: no site before the card is verified (decided
	 * 2026-11-08, M1-01 part 2). Past-due accounts are M1-02's.
	 *
	 * @param true|WP_Error $allowed Whether the user may create a site so far.
	 * @param int           $user_id User ID.
	 * @return true|WP_Error
	 */
	public static function can_create_site( bool|WP_Error $allowed, int $user_id ): bool|WP_Error {
		if ( true === $allowed && self::payment_pending( $user_id ) ) {
			return new WP_Error( 'mody_payment_pending', __( 'Finish your payment, then you can create your first site.', 'mody_cloud' ) );
		}
		return $allowed;
	}

	/**
	 * `user_has_cap`: every user with a row has the dashboard capability
	 * (spec section 3).
	 *
	 * @param array<string, bool> $allcaps The user's capabilities.
	 * @param string[]            $caps    The primitive capabilities checked.
	 * @param array<int, mixed>   $args    The check: capability, user ID, ...
	 * @return array<string, bool>
	 */
	public static function grant_dashboard( array $allcaps, array $caps, array $args ): array {
		if ( in_array( self::MENU_CAPABILITY, $caps, true ) && self::get( (int) ( $args[1] ?? 0 ) ) ) {
			$allcaps[ self::MENU_CAPABILITY ] = true;
		}
		return $allcaps;
	}

	/**
	 * A member's first site: the oldest they administer, not the main site;
	 * 0 when they have none.
	 *
	 * @param int $user_id The member.
	 */
	public static function first_site( int $user_id ): int {
		global $wpdb;
		$first = 0;
		$when  = PHP_INT_MAX;
		foreach ( get_blogs_of_user( $user_id ) as $blog ) {
			$id   = (int) $blog->userblog_id;
			$caps = get_user_meta( $user_id, $wpdb->get_blog_prefix( $id ) . 'capabilities', true );
			if ( is_main_site( $id ) || ! is_array( $caps ) || empty( $caps['administrator'] ) ) {
				continue;
			}
			$created = (int) strtotime( (string) get_site( $id )->registered . ' UTC' );
			if ( $created < $when ) {
				$first = $id;
				$when  = $created;
			}
		}
		return $first;
	}
}
