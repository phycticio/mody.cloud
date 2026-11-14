<?php
/**
 * Member accounts: the `mody_members` table, plans and their site limits
 * (mvp-spec sections 3, 4 and 11; ticket M1-01, part 1).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Members;

use Mody\Platform\Wizard\WizardPage;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One row per member account, network-wide.
 *
 * - **Plans** (membership-tiers.md, decided by the maintainer on
 *   2026-11-13; they replace the 1, 3 and 10-site plans of 2026-11-10 and
 *   the 30-day trial): every site costs the same, PRICE, VAT included,
 *   monthly or yearly (`billing_period`), with the same features. `plan`
 *   is `free` (the launch offer, below) or `paid`. A plan is a column, not
 *   a WordPress role (decided 2026-11-08): roles are per site in Multisite,
 *   and members are `administrator` of their own sites.
 * - **The launch offer** (replaces the trial; its numbers on the Offers
 *   page since 2026-11-14, Offers): during the first
 *   PROMO_MONTHS months, the first FREE_PLACES accounts are free for
 *   FREE_MONTHS months, with one site and no card (start_free()). The
 *   offer's months count from the network option `mody_promo_starts_at`
 *   (a Unix time), set by the first free account unless set before; the
 *   places given are counted in `mody_promo_given`, so a deleted account
 *   does not give its place back. When it ends (`free_until`, also the
 *   first billing date), the account needs a card (Mollie, M1-01 part 3):
 *   reminders a month and a week before, then the sites go read-only for
 *   60 days, then they are deleted (spec section 4; not built yet).
 * - **Statuses** (spec section 4): `active` (free accounts included),
 *   `pending_payment` until a card is verified, `past_due` after a failed
 *   charge or no card when the free months end, `canceled` after a
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

	/** The plans: the launch offer, and paying per site. */
	public const PLANS = array( 'free', 'paid' );

	/** One site's price in EUR, VAT included (decided 2026-11-13: ten months for a year). */
	public const PRICE = array(
		'monthly' => 7.99,
		'yearly'  => 79.99,
	);

	/**
	 * The sites an account may have: one during the offer; ten when paying,
	 * each paid (a real limit, rule 7; more is not offered yet).
	 */
	public const SITE_LIMITS = array(
		'free' => 1,
		'paid' => 10,
	);

	/** The billing periods. */
	public const PERIODS = array( 'monthly', 'yearly' );

	/**
	 * The launch offer's defaults: how many accounts, for how long, and for how
	 * long it is offered. The Offers page changes them (Offers::places(),
	 * free_months(), months(), since 2026-11-14).
	 */
	public const FREE_PLACES  = 50;
	public const FREE_MONTHS  = 6;
	public const PROMO_MONTHS = 3;

	/** Network options: when the offer started (Unix time), and how many places it gave. */
	public const PROMO_START_OPTION = 'mody_promo_starts_at';
	public const PROMO_GIVEN_OPTION = 'mody_promo_given';

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
	private const DB_VERSION = '7'; // 2: next_plan (M1-01 part 2). 3: the 2026-11-10 plans and billing_period. 4: free and paid, free_until, no next_plan (2026-11-13). 5: `canceled`. 6: cancel_at. 7: invited_by, invite_point_at (2026-11-14).

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
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET free_until = DATE_ADD(created_at, INTERVAL %d MONTH), billing_due_at = DATE_ADD(created_at, INTERVAL %d MONTH) WHERE plan = 'free'", self::FREE_MONTHS, self::FREE_MONTHS ) );
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
	 * Gives a new account one of the launch offer's places: plan `free`,
	 * status `active`, free for FREE_MONTHS months (`free_until`, also the
	 * first billing date). No card is needed. The first place starts the
	 * offer's months.
	 *
	 * @param int  $user_id An existing user without a row.
	 * @param bool $invited Whether they signed up through an invite link (Offers::invited_only()).
	 * @return true|WP_Error `mody_promo_closed` when no place is left, the offer has ended or is off,
	 *                       or is for invited people only and they were not.
	 */
	public static function start_free( int $user_id, bool $invited = false ): bool|WP_Error {
		global $wpdb;
		// One signup at a time takes a place, so two at once never make 51.
		$locked = (bool) $wpdb->get_var( "SELECT GET_LOCK('mody_promo', 10)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A lock, not data.
		try {
			if ( ! $locked || ! self::promo_open( $invited ) ) {
				return new WP_Error( 'mody_promo_closed', self::promo_closed_message( $invited ) );
			}
			$created = self::create( $user_id, 'free', 'active' );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			if ( ! self::promo_starts() ) {
				update_site_option( self::PROMO_START_OPTION, time() );
			}
			update_site_option( self::PROMO_GIVEN_OPTION, self::promo_given() + 1 );
			$until = gmdate( 'Y-m-d H:i:s', (int) strtotime( '+' . Offers::free_months() . ' months' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$wpdb->update(
				self::table(),
				array(
					'free_until'     => $until,
					'billing_due_at' => $until,
				),
				array( 'user_id' => $user_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			unset( self::$rows[ $user_id ] );
			return true;
		} finally {
			if ( $locked ) {
				$wpdb->get_var( "SELECT RELEASE_LOCK('mody_promo')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A lock, not data.
			}
		}
	}

	/** When the launch offer started, as a Unix time, or 0 before its first account. */
	public static function promo_starts(): int {
		return (int) get_site_option( self::PROMO_START_OPTION, 0 );
	}

	/** When the launch offer stops taking accounts, or 0 before it started. */
	public static function promo_ends(): int {
		$start = self::promo_starts();
		return $start ? (int) strtotime( '+' . Offers::months() . ' months', $start ) : 0;
	}

	/** How many of the launch offer's places were given. */
	public static function promo_given(): int {
		return (int) get_site_option( self::PROMO_GIVEN_OPTION, 0 );
	}

	/** How many places are left. */
	public static function promo_left(): int {
		return max( 0, Offers::places() - self::promo_given() );
	}

	/**
	 * Whether the launch offer takes this account: it is on, places are left,
	 * within its months, and, when it is for invited people only, they were.
	 *
	 * @param bool $invited Whether the visitor came through an invite link.
	 */
	public static function promo_open( bool $invited = false ): bool {
		$ends = self::promo_ends();
		return Offers::launch_on()
			&& ( $invited || ! Offers::invited_only() )
			&& self::promo_left() > 0
			&& ( ! $ends || time() < $ends );
	}

	/**
	 * What a visitor reads when the offer does not take their account.
	 *
	 * @param bool $invited Whether the visitor came through an invite link.
	 */
	public static function promo_closed_message( bool $invited = false ): string {
		$ends = self::promo_ends();
		if ( ! Offers::launch_on() || ( $ends && time() >= $ends ) ) {
			$first = __( 'Our launch offer has ended.', 'mody_cloud' );
		} elseif ( self::promo_left() <= 0 ) {
			/* translators: %d: how many free places the launch offer had. */
			$first = sprintf( __( 'The %d free places of our launch offer are taken.', 'mody_cloud' ), Offers::places() );
		} elseif ( ! $invited && Offers::invited_only() ) {
			$first = __( 'Our launch offer is by invitation for now: sign up through the link a member sent you.', 'mody_cloud' );
		} else {
			$first = __( 'Our launch offer has ended.', 'mody_cloud' );
		}
		return $first . ' ' . sprintf(
			/* translators: %s: a site's monthly price, such as 7.99 EUR. */
			__( 'Sign-ups at %s a month per site, VAT included, open soon.', 'mody_cloud' ),
			self::price( self::PRICE['monthly'] )
		);
	}

	/**
	 * When a member's free months end, as a Unix timestamp, or 0 when they
	 * are not on the launch offer.
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
			'free'  => __( 'Launch offer', 'mody_cloud' ),
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
		if ( in_array( WizardPage::CAPABILITY, $caps, true ) && self::get( (int) ( $args[1] ?? 0 ) ) ) {
			$allcaps[ WizardPage::CAPABILITY ] = true;
		}
		return $allcaps;
	}
}
