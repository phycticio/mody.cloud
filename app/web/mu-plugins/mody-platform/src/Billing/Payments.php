<?php
/**
 * The platform's record of Mollie payments: `mody_payments`.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Billing;

defined( 'ABSPATH' ) || exit;

/**
 * One row per Mollie payment the platform started (network-wide), so the
 * webhook, the return from the checkout and the daily run all apply a
 * payment once, and Plan & billing lists what was paid.
 *
 * - `kind`: `card` (the 0.01 EUR check that makes the card's mandate),
 *   `charge` (the sites for a period), or `signup` (a paid sign-up's first
 *   month, which also makes the mandate).
 * - `status`: Mollie's (`open`, `pending`, `authorized`, `paid`, `failed`,
 *   `canceled`, `expired`); `applied` says whether the platform acted on it.
 * - `covers_from` and `covers_to`: the period a charge pays for.
 * - `refunded_at`: when the platform refunded it (the 14-day withdrawal).
 */
final class Payments {

	/** Bump when the table definition changes. */
	private const DB_VERSION = '2'; // 2: `signup`, a paid sign-up's first payment (2026-11-13).

	/** Network option with the installed version. */
	private const DB_VERSION_OPTION = 'mody_payments_db_version';

	/** Mollie's statuses that are over: nothing more will happen to the payment. */
	public const FINAL = array( 'paid', 'failed', 'canceled', 'expired' );

	/**
	 * The table's name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'mody_payments';
	}

	/**
	 * Creates the table when its version changed.
	 */
	public static function install(): void {
		if ( self::DB_VERSION === get_site_option( self::DB_VERSION_OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  mollie_id varchar(32) NOT NULL,
  kind enum('card','charge','signup') NOT NULL,
  amount decimal(10,2) NOT NULL,
  sites int(10) unsigned NOT NULL DEFAULT 0,
  period enum('monthly','yearly') DEFAULT NULL,
  covers_from datetime DEFAULT NULL,
  covers_to datetime DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'open',
  applied tinyint(1) NOT NULL DEFAULT 0,
  refunded_at datetime DEFAULT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY mollie_id (mollie_id),
  KEY user_id (user_id),
  KEY status (status)
) {$wpdb->get_charset_collate()};"
		);
		update_site_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Records a payment the platform just started.
	 *
	 * @param array<string, mixed> $row Columns.
	 * @return int The row's ID, or 0.
	 */
	public static function add( array $row ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		return $wpdb->insert( self::table(), $row ) ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * A row by its Mollie ID.
	 *
	 * @param string $mollie_id `tr_…`.
	 * @return array<string, mixed>|null
	 */
	public static function by_mollie( string $mollie_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE mollie_id = %s', $mollie_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * A row by its ID.
	 *
	 * @param int $id Row ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Changes a row.
	 *
	 * @param int                  $id     Row ID.
	 * @param array<string, mixed> $fields Columns.
	 */
	public static function update( int $id, array $fields ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->update( self::table(), $fields, array( 'id' => $id ) );
	}

	/**
	 * A member's payments, newest first.
	 *
	 * @param int    $user_id User.
	 * @param string $kind    `card`, `charge`, or '' for both.
	 * @param int    $limit   How many.
	 * @return array<int, array<string, mixed>>
	 */
	public static function of( int $user_id, string $kind = '', int $limit = 24 ): array {
		global $wpdb;
		$where = $wpdb->prepare( 'user_id = %d', $user_id ) . ( '' !== $kind ? $wpdb->prepare( ' AND kind = %s', $kind ) : '' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Own table; prepared above.
		return (array) $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' WHERE ' . $where . $wpdb->prepare( ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
	}

	/**
	 * Payments not over yet, started more than a few minutes ago: the daily
	 * run asks Mollie about them (no webhook reaches a local stack).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function waiting(): array {
		global $wpdb;
		$final = "'" . implode( "','", self::FINAL ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; constants.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status NOT IN ({$final}) AND created_at < %s AND created_at > %s ORDER BY id", gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ), ARRAY_A );
	}
}
