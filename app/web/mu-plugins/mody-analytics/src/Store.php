<?php
/**
 * The two tables: daily totals and today's visitor hashes.
 *
 * @package Mody\Analytics
 */

namespace Mody\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * - `mody_analytics_daily`: one row per site, day and counted thing. `kind`
 *   is `total` (value ''), `page` (the path), `source` (the host of another
 *   site that linked in, or '' for none) or `device` (`mobile`, `tablet`,
 *   `desktop`). `views` counts page views; `visitors` counts visitors for
 *   `total` and visits for `source` and `device` (a visitor's first view of
 *   the day).
 * - `mody_analytics_seen`: the hashes of today's visitors, with their views
 *   (to cap a runaway sender). Rows of earlier days are deleted when the day
 *   changes (Collector::salt()).
 *
 * Days are the site's own (its timezone). Both tables are network-wide, so
 * they use the base prefix; a deleted site's rows go with it.
 */
final class Store {

	/** Bump when the tables change. */
	private const DB_VERSION = '1';

	/** Network option that records the installed version. */
	private const DB_VERSION_OPTION = 'mody_analytics_db_version';

	/** The things counted per day. */
	public const KINDS = array( 'total', 'page', 'source', 'device' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'install' ), 1 );
		add_action( 'wp_uninitialize_site', array( self::class, 'forget_site' ) );
	}

	/**
	 * The daily totals table.
	 */
	public static function daily(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'mody_analytics_daily';
	}

	/**
	 * Today's visitors table.
	 */
	public static function seen(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'mody_analytics_seen';
	}

	/**
	 * `init`: creates or updates the tables when their version changed.
	 */
	public static function install(): void {
		if ( self::DB_VERSION === get_site_option( self::DB_VERSION_OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$daily   = self::daily();
		$seen    = self::seen();

		// dbDelta() needs this exact layout: one column per line, two spaces after PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$daily} (
  site_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  kind varchar(16) NOT NULL,
  value varchar(191) NOT NULL DEFAULT '',
  views int(10) unsigned NOT NULL DEFAULT 0,
  visitors int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (site_id,day,kind,value),
  KEY site_kind_day (site_id,kind,day)
) {$charset};
CREATE TABLE {$seen} (
  site_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  hash char(32) NOT NULL,
  views smallint(5) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY  (site_id,day,hash),
  KEY day (day)
) {$charset};"
		);
		update_site_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Adds one to a day's row.
	 *
	 * @param int    $site_id  Site.
	 * @param string $day      Y-m-d.
	 * @param string $kind     One of KINDS.
	 * @param string $value    The page, source or device; '' for the total.
	 * @param int    $views    Views to add.
	 * @param int    $visitors Visitors to add.
	 */
	public static function add( int $site_id, string $day, string $kind, string $value, int $views, int $visitors ): void {
		global $wpdb;
		$table = self::daily();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; an upsert has no WordPress API.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (site_id, day, kind, value, views, visitors) VALUES (%d, %s, %s, %s, %d, %d) ON DUPLICATE KEY UPDATE views = views + VALUES(views), visitors = visitors + VALUES(visitors)", $site_id, $day, $kind, mb_substr( $value, 0, 191 ), $views, $visitors ) );
	}

	/**
	 * Records a visitor's view today.
	 *
	 * @param int    $site_id Site.
	 * @param string $day     Y-m-d.
	 * @param string $hash    The visitor's hash for the day.
	 * @return int The visitor's views today, this one included (1: a new visitor).
	 */
	public static function see( int $site_id, string $day, string $hash ): int {
		global $wpdb;
		$table = self::seen();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (site_id, day, hash, views) VALUES (%d, %s, %s, 1) ON DUPLICATE KEY UPDATE views = LEAST(views + 1, 65535)", $site_id, $day, $hash ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT views FROM {$table} WHERE site_id = %d AND day = %s AND hash = %s", $site_id, $day, $hash ) );
		// phpcs:enable
	}

	/**
	 * Deletes the hashes of days before this one.
	 *
	 * @param string $day Y-m-d: the earliest day kept.
	 */
	public static function forget_before( string $day ): void {
		global $wpdb;
		$table = self::seen();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", $day ) );
	}

	/**
	 * `wp_uninitialize_site`: a deleted site's counts go with it.
	 *
	 * @param \WP_Site $site The site.
	 */
	public static function forget_site( \WP_Site $site ): void {
		global $wpdb;
		foreach ( array( self::daily(), self::seen() ) as $table ) {
			$wpdb->delete( $table, array( 'site_id' => (int) $site->blog_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own tables.
		}
	}
}
