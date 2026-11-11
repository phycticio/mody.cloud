<?php
/**
 * Member accounts: the `mody_members` table, plans and their site limits
 * (mvp-spec sections 3, 4 and 11; ticket M1-01, part 1).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Members;

use Mody\Platform\Provisioning\Quota;
use Mody\Platform\Wizard\WizardPage;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One row per member account, network-wide.
 *
 * - **Plans** (membership-tiers.md, decided 2026-11-10; in code since
 *   2026-11-11): the trial has 3 sites; the paid plans 1, 3 and 10, paid
 *   monthly or yearly (`billing_period`). A plan is a column, not a
 *   WordPress role (decided 2026-11-08): roles are per site in Multisite,
 *   and members are `administrator` of their own sites. During the trial
 *   `plan` is `trial` and `next_plan` holds the plan the member chose on
 *   the dashboard (choose_plan()), charged when the trial ends; later it
 *   holds a plan change for the next billing date.
 * - **Statuses** (spec section 4): `active` (the trial included: since
 *   2026-11-08 it starts without a card, start_trial(), M1-03),
 *   `pending_payment` until a card is verified, `past_due` after a failed
 *   charge, `expired_signup` when no payment arrived within 24 hours, and
 *   `expired` once the sites are deleted after 60 days past due.
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

	/**
	 * Plans: slug => sites, and the prices in EUR, monthly and yearly
	 * (decided by the maintainer on 2026-11-10, membership-tiers.md). The
	 * slugs replaced Collaborator, Member and Patron on 2026-11-11.
	 */
	public const PLANS = array(
		'trial'    => array(
			'sites'   => 3,
			'monthly' => 0,
			'yearly'  => 0,
		),
		'sites_1'  => array(
			'sites'   => 1,
			'monthly' => 9.99,
			'yearly'  => 89.99,
		),
		'sites_3'  => array(
			'sites'   => 3,
			'monthly' => 25.99,
			'yearly'  => 249.99,
		),
		'sites_10' => array(
			'sites'   => 10,
			'monthly' => 49.99,
			'yearly'  => 499.99,
		),
	);

	/** The billing periods of a paid plan. */
	public const PERIODS = array( 'monthly', 'yearly' );

	/** The plans before 2026-11-11, and the plan each became. */
	private const OLD_PLANS = array(
		'collaborator' => 'sites_3',
		'member'       => 'sites_10',
		'patron'       => 'sites_10',
	);

	/** How long the trial lasts (spec decision 3). */
	public const TRIAL_DAYS = 30;

	/** The plans a member chooses during the trial or later; the trial is not one of them. */
	public const PAID_PLANS = array( 'sites_1', 'sites_3', 'sites_10' );

	/** Statuses whose card is not verified yet (spec section 4): no site may be created. */
	public const UNPAID_STATUSES = array( 'pending_payment', 'expired_signup' );

	/** Account statuses, in the order an account usually moves through them. */
	public const STATUSES = array( 'pending_payment', 'active', 'past_due', 'expired_signup', 'expired' );

	/** Bump when the table definition in install() changes. */
	private const DB_VERSION = '3'; // 2: next_plan (M1-01 part 2). 3: the 2026-11-10 plans and billing_period.

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

		// Version 3: the old plans become the new ones. Both sets are allowed for the update, then dbDelta() keeps the new.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check.
			$both = "'trial','" . implode( "','", array_keys( self::OLD_PLANS ) ) . "','" . implode( "','", self::PAID_PLANS ) . "'";
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Own table; constants only.
			$wpdb->query( "ALTER TABLE {$table} MODIFY plan enum({$both}) NOT NULL DEFAULT 'trial', MODIFY next_plan enum({$both}) DEFAULT NULL" );
			foreach ( self::OLD_PLANS as $old => $new ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET plan = %s WHERE plan = %s", $new, $old ) );
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET next_plan = %s WHERE next_plan = %s", $new, $old ) );
			}
			// phpcs:enable
		}

		// dbDelta() needs this exact layout: one column per line, two spaces after PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$table} (
  user_id bigint(20) unsigned NOT NULL,
  plan enum('trial','sites_1','sites_3','sites_10') NOT NULL DEFAULT 'trial',
  next_plan enum('sites_1','sites_3','sites_10') DEFAULT NULL,
  billing_period enum('monthly','yearly') NOT NULL DEFAULT 'monthly',
  status enum('pending_payment','active','past_due','expired_signup','expired') NOT NULL,
  trial_ends_at datetime DEFAULT NULL,
  billing_due_at datetime DEFAULT NULL,
  past_due_since datetime DEFAULT NULL,
  mollie_customer_id varchar(64) DEFAULT NULL,
  mollie_payment_method_id varchar(64) DEFAULT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (user_id),
  KEY status (status)
) {$charset};"
		);

		update_site_option( self::DB_VERSION_OPTION, self::DB_VERSION );
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
	 * Creates a member's row. Registration (part 2) is its caller.
	 *
	 * @param int    $user_id   An existing user.
	 * @param string $plan      One of the PLANS keys.
	 * @param string $status    One of STATUSES.
	 * @param string $next_plan One of PAID_PLANS, or '' for none: the plan
	 *                          chosen at signup, charged when the trial ends
	 *                          (and later a plan change for the next billing date).
	 * @return true|WP_Error
	 */
	public static function create( int $user_id, string $plan = 'trial', string $status = 'pending_payment', string $next_plan = '' ): bool|WP_Error {
		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'mody_member_no_user', __( 'There is no such user.', 'mody_cloud' ) );
		}
		if ( ! isset( self::PLANS[ $plan ] ) || ( '' !== $next_plan && ! in_array( $next_plan, self::PAID_PLANS, true ) ) ) {
			return new WP_Error( 'mody_member_plan', __( 'There is no such plan.', 'mody_cloud' ) );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'mody_member_status', __( 'There is no such account status.', 'mody_cloud' ) );
		}
		if ( self::get( $user_id ) ) {
			return new WP_Error( 'mody_member_exists', __( 'This user already has a member account.', 'mody_cloud' ) );
		}
		global $wpdb;
		$row = array(
			'user_id' => $user_id,
			'plan'    => $plan,
			'status'  => $status,
		);
		if ( '' !== $next_plan ) {
			$row['next_plan'] = $next_plan;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$inserted = $wpdb->insert( self::table(), $row, array_merge( array( '%d' ), array_fill( 0, count( $row ) - 1, '%s' ) ) );
		unset( self::$rows[ $user_id ] );
		return $inserted ? true : new WP_Error( 'mody_member_db', __( 'The member account could not be saved.', 'mody_cloud' ) );
	}

	/**
	 * Starts a member's trial: plan `trial`, status `active`, and the trial's
	 * end (also the first billing date) TRIAL_DAYS from now. No card is
	 * needed (decided 2026-11-08, M1-03).
	 *
	 * @param int $user_id An existing user without a row.
	 * @return true|WP_Error
	 */
	public static function start_trial( int $user_id ): bool|WP_Error {
		$created = self::create( $user_id, 'trial', 'active' );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$ends = gmdate( 'Y-m-d H:i:s', time() + self::TRIAL_DAYS * DAY_IN_SECONDS );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->update(
			self::table(),
			array(
				'trial_ends_at'  => $ends,
				'billing_due_at' => $ends,
			),
			array( 'user_id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		unset( self::$rows[ $user_id ] );
		return true;
	}

	/**
	 * When a member's trial ends, as a Unix timestamp, or 0 when they are not
	 * on a trial.
	 *
	 * @param int $user_id User ID.
	 */
	public static function trial_ends( int $user_id ): int {
		$row = self::get( $user_id );
		if ( ! $row || 'trial' !== $row['plan'] || empty( $row['trial_ends_at'] ) ) {
			return 0;
		}
		return (int) strtotime( $row['trial_ends_at'] . ' UTC' );
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
		return $row ? (int) self::PLANS[ $row['plan'] ]['sites'] : $limit;
	}

	/**
	 * A plan's name, translated.
	 *
	 * @param string $plan One of the PLANS keys.
	 */
	public static function plan_label( string $plan ): string {
		return match ( $plan ) {
			'trial' => __( 'Trial', 'mody_cloud' ),
			default => isset( self::PLANS[ $plan ] )
				/* translators: %d: the number of sites a plan includes. */
				? sprintf( _n( '%d site', '%d sites', self::PLANS[ $plan ]['sites'], 'mody_cloud' ), self::PLANS[ $plan ]['sites'] )
				: $plan,
		};
	}

	/**
	 * A member chooses the plan that follows the trial, or a change for the
	 * next billing date: `next_plan` and `billing_period`. The card and the
	 * charge come with Mollie (M1-01 part 3).
	 *
	 * @param int    $user_id A member.
	 * @param string $plan    One of PAID_PLANS.
	 * @param string $period  One of PERIODS.
	 * @return true|WP_Error `mody_plan_too_small` when the member has more sites than the plan includes.
	 */
	public static function choose_plan( int $user_id, string $plan, string $period ): bool|WP_Error {
		if ( ! self::get( $user_id ) ) {
			return new WP_Error( 'mody_member_none', __( 'This account has no plan.', 'mody_cloud' ) );
		}
		if ( ! in_array( $plan, self::PAID_PLANS, true ) || ! in_array( $period, self::PERIODS, true ) ) {
			return new WP_Error( 'mody_member_plan', __( 'There is no such plan.', 'mody_cloud' ) );
		}
		$used = Quota::used( $user_id );
		if ( $used > self::PLANS[ $plan ]['sites'] ) {
			return new WP_Error(
				'mody_plan_too_small',
				/* translators: 1: sites in use, 2: sites the plan includes. */
				sprintf( __( 'You have %1$d sites, and this plan includes %2$d. Delete sites first, or choose a larger plan.', 'mody_cloud' ), $used, self::PLANS[ $plan ]['sites'] )
			);
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->update(
			self::table(),
			array(
				'next_plan'      => $plan,
				'billing_period' => $period,
			),
			array( 'user_id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		unset( self::$rows[ $user_id ] );
		return true;
	}

	/**
	 * What a yearly plan saves on twelve monthly payments, in EUR.
	 *
	 * @param string $plan One of PAID_PLANS.
	 */
	public static function yearly_saving( string $plan ): float {
		return isset( self::PLANS[ $plan ] ) ? round( 12 * self::PLANS[ $plan ]['monthly'] - self::PLANS[ $plan ]['yearly'], 2 ) : 0.0;
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
			return new WP_Error( 'mody_payment_pending', __( 'Finish your payment to start your trial, then you can create your first site.', 'mody_cloud' ) );
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
		if ( in_array( WizardPage::CAPABILITY, $caps, true ) && self::get( (int) ( $args[1] ?? 0 ) ) ) {
			$allcaps[ WizardPage::CAPABILITY ] = true;
		}
		return $allcaps;
	}
}
