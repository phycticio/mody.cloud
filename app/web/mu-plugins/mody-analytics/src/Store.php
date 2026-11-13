<?php
/**
 * The tables: every page view, today's visitor map, and the archive.
 *
 * @package Mody\Analytics
 */

namespace Mody\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Four network tables (base prefix), one set for every site:
 *
 * - `mody_analytics_views`, **the** table: one row per page view on any
 *   site of the network. A row holds the site, an anonymous visit, the
 *   view's place in the visit (`seq`: 1 is where the visit came in), the
 *   site's day and hour, the path, the source and the device. Every number
 *   and every pattern the dashboards show is read from it, filtered by site.
 *   `visit_id` is random, never made from the visitor, so a row says "a
 *   visit did this", never who did.
 * - `mody_analytics_seen`: today's map from a visitor's daily hash to their
 *   current visit (Collector), with the time of their last view and their
 *   views today. It is the only link between a person and a visit, and the
 *   rows of earlier days are deleted when the day changes.
 * - `mody_analytics_daily`: the archive. Page views older than RETENTION
 *   are reduced to daily totals here (per site and day: `total`, `page`,
 *   `source`, `device`; `visitors` counts visits) and then deleted. It also
 *   holds the daily totals counted before the views table (2026-11-11/12).
 * - `mody_analytics_values`: today's distinct pages and sources per site,
 *   hashed, so admit() can cap how many a site counts in a day (anyone can
 *   post any path to the beacon). Rows of earlier days are deleted with the
 *   map's.
 *
 * Days and hours are the site's own (its timezone). A deleted site's rows
 * go with it.
 */
final class Store {

	/** Bump when the tables change. 2: the views table and the visit map (2026-11-12). 3: the distinct values (security pass, 2026-11-12). */
	private const DB_VERSION = '4'; // 4: country and city on each view (2026-11-13, tickets/visitor-location.md).

	/** Network option that records the installed version. */
	private const DB_VERSION_OPTION = 'mody_analytics_db_version';

	/** How long page views are kept before they become daily totals (the maintainer's choice, 2026-11-12). */
	public const RETENTION = '13 months';

	/** Days archived at most per pass, so a pass stays short. */
	private const ARCHIVE_DAYS = 7;

	/** The things the archive counts per day. */
	public const KINDS = array( 'total', 'page', 'source', 'device', 'country' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'install' ), 1 );
		add_action( 'wp_uninitialize_site', array( self::class, 'forget_site' ) );
	}

	/**
	 * The page views table.
	 */
	public static function views(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'mody_analytics_views';
	}

	/**
	 * Today's visitor map.
	 */
	public static function seen(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'mody_analytics_seen';
	}

	/**
	 * The archive of daily totals.
	 */
	public static function daily(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'mody_analytics_daily';
	}

	/**
	 * Today's distinct pages and sources.
	 */
	public static function values(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'mody_analytics_values';
	}

	/**
	 * `init`: creates or updates the tables when their version changed.
	 */
	public static function install(): void {
		$installed = get_site_option( self::DB_VERSION_OPTION );
		if ( self::DB_VERSION === $installed ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$views   = self::views();
		$seen    = self::seen();
		$daily   = self::daily();
		$values  = self::values();

		if ( '1' === $installed ) {
			// Version 1's map had no visit: today's hashes are dropped, so today's visitors start new visits.
			$wpdb->query( "DROP TABLE IF EXISTS {$seen}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Own table.
		}

		// dbDelta() needs this exact layout: one column per line, two spaces after PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$views} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id bigint(20) unsigned NOT NULL,
  visit_id char(16) NOT NULL,
  seq smallint(5) unsigned NOT NULL DEFAULT 1,
  day date NOT NULL,
  hour tinyint(3) unsigned NOT NULL DEFAULT 0,
  path varchar(191) NOT NULL,
  source varchar(191) NOT NULL DEFAULT '',
  device varchar(8) NOT NULL DEFAULT '',
  country char(2) NOT NULL DEFAULT '',
  city varchar(80) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY site_day (site_id,day),
  KEY visit (visit_id,seq),
  KEY day (day)
) {$charset};
CREATE TABLE {$seen} (
  site_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  hash char(32) NOT NULL,
  visit_id char(16) NOT NULL,
  seq smallint(5) unsigned NOT NULL DEFAULT 1,
  views smallint(5) unsigned NOT NULL DEFAULT 1,
  last_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (site_id,day,hash),
  KEY day (day)
) {$charset};
CREATE TABLE {$daily} (
  site_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  kind varchar(16) NOT NULL,
  value varchar(191) NOT NULL DEFAULT '',
  views int(10) unsigned NOT NULL DEFAULT 0,
  visitors int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (site_id,day,kind,value),
  KEY site_kind_day (site_id,kind,day)
) {$charset};
CREATE TABLE {$values} (
  site_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  kind char(1) NOT NULL,
  hash char(32) NOT NULL,
  PRIMARY KEY  (site_id,day,kind,hash),
  KEY day (day)
) {$charset};"
		);
		update_site_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Records a visitor's view in today's map: the same visit while they
	 * keep viewing pages, a new one after Collector::VISIT_GAP seconds
	 * without a view.
	 *
	 * @param int    $site_id Site.
	 * @param string $day     Y-m-d, the site's day.
	 * @param string $hash    The visitor's hash for the day.
	 * @param int    $now     Unix time of the view.
	 * @param int    $gap     Seconds without a view that end a visit.
	 * @return array{visit_id: string, seq: int, views: int} Their visit, this view's place in it, and their views today.
	 */
	public static function see( int $site_id, string $day, string $hash, int $now, int $gap ): array {
		global $wpdb;
		$table = self::seen();
		$fresh = bin2hex( random_bytes( 8 ) );
		$stale = $now - $gap;
		// MySQL applies the assignments left to right: last_at changes last, so the others still see the previous view's time.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; an upsert has no WordPress API.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (site_id, day, hash, visit_id, seq, views, last_at) VALUES (%d, %s, %s, %s, 1, 1, %d)
				ON DUPLICATE KEY UPDATE
					visit_id = IF(last_at < %d, VALUES(visit_id), visit_id),
					seq = IF(last_at < %d, 1, LEAST(seq + 1, 65535)),
					views = LEAST(views + 1, 65535),
					last_at = VALUES(last_at)",
				$site_id,
				$day,
				$hash,
				$fresh,
				$now,
				$stale,
				$stale
			)
		);
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT visit_id, seq, views FROM {$table} WHERE site_id = %d AND day = %s AND hash = %s", $site_id, $day, $hash ), ARRAY_A );
		// phpcs:enable
		return array(
			'visit_id' => (string) ( $row['visit_id'] ?? $fresh ),
			'seq'      => (int) ( $row['seq'] ?? 1 ),
			'views'    => (int) ( $row['views'] ?? 1 ),
		);
	}

	/**
	 * Whether a site may count a page or a source today: it is one the site
	 * already counted today, or the site has not reached the cap of different
	 * ones. The first time a value is seen it is remembered (its hash only).
	 * Two requests at the limit may both get in: the cap is a bound, not a
	 * quota.
	 *
	 * @param int    $site_id Site.
	 * @param string $day     Y-m-d, the site's day.
	 * @param string $kind    'p' for a page, 's' for a source.
	 * @param string $value   The page's path or the source's host.
	 * @param int    $cap     The most different values of this kind.
	 */
	public static function admit( int $site_id, string $day, string $kind, string $value, int $cap ): bool {
		global $wpdb;
		$table = self::values();
		$hash  = md5( $value );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE site_id = %d AND day = %s AND kind = %s AND hash = %s", $site_id, $day, $kind, $hash ) ) ) {
			return true;
		}
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE site_id = %d AND day = %s AND kind = %s", $site_id, $day, $kind ) ) >= $cap ) {
			return false;
		}
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (site_id, day, kind, hash) VALUES (%d, %s, %s, %s)", $site_id, $day, $kind, $hash ) );
		// phpcs:enable
		return true;
	}

	/**
	 * Stores one page view.
	 *
	 * @param array{site_id: int, visit_id: string, seq: int, day: string, hour: int, path: string, source: string, device: string, country?: string, city?: string} $view The view.
	 */
	public static function add_view( array $view ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			self::views(),
			array(
				'site_id'  => $view['site_id'],
				'visit_id' => $view['visit_id'],
				'seq'      => min( 65535, max( 1, $view['seq'] ) ),
				'day'      => $view['day'],
				'hour'     => min( 23, max( 0, $view['hour'] ) ),
				'path'     => mb_substr( $view['path'], 0, 191 ),
				'source'   => mb_substr( $view['source'], 0, 191 ),
				'device'   => substr( $view['device'], 0, 8 ),
				'country'  => substr( (string) ( $view['country'] ?? '' ), 0, 2 ),
				'city'     => mb_substr( (string) ( $view['city'] ?? '' ), 0, 80 ),
			),
			array( '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Deletes the map's and the distinct values' rows of days before this one.
	 *
	 * @param string $day Y-m-d: the earliest day kept.
	 */
	public static function forget_before( string $day ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own tables.
		foreach ( array( self::seen(), self::values() ) as $table ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", $day ) );
		}
		// phpcs:enable
	}

	/**
	 * The first day whose page views are kept; older ones are archived.
	 */
	public static function retention_start(): string {
		return gmdate( 'Y-m-d', (int) strtotime( '-' . self::RETENTION, time() ) );
	}

	/**
	 * Reduces the oldest page views, those before $before, to daily totals
	 * in the archive and deletes them: up to ARCHIVE_DAYS days per call. A
	 * day archived again replaces its totals, so a pass cut short is safe.
	 *
	 * @param string $before Y-m-d: views of earlier days go.
	 * @return int The days archived.
	 */
	public static function archive_before( string $before ): int {
		global $wpdb;
		$views = self::views();
		$daily = self::daily();
		$done  = 0;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own tables.
		while ( $done < self::ARCHIVE_DAYS ) {
			$day = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(day) FROM {$views} WHERE day < %s", $before ) );
			if ( '' === $day ) {
				break;
			}
			$upsert = " ON DUPLICATE KEY UPDATE views = VALUES(views), visitors = VALUES(visitors)";
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$daily} (site_id, day, kind, value, views, visitors) SELECT site_id, day, 'total', '', COUNT(*), COUNT(DISTINCT visit_id) FROM {$views} WHERE day = %s GROUP BY site_id" . $upsert, $day ) );
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$daily} (site_id, day, kind, value, views, visitors) SELECT site_id, day, 'page', path, COUNT(*), COUNT(DISTINCT visit_id) FROM {$views} WHERE day = %s GROUP BY site_id, path" . $upsert, $day ) );
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$daily} (site_id, day, kind, value, views, visitors) SELECT site_id, day, 'source', source, COUNT(*), COUNT(*) FROM {$views} WHERE day = %s AND seq = 1 GROUP BY site_id, source" . $upsert, $day ) );
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$daily} (site_id, day, kind, value, views, visitors) SELECT site_id, day, 'device', device, COUNT(*), COUNT(*) FROM {$views} WHERE day = %s AND seq = 1 GROUP BY site_id, device" . $upsert, $day ) );
			// Countries are kept in the archive; cities are not (small towns, tickets/visitor-location.md).
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$daily} (site_id, day, kind, value, views, visitors) SELECT site_id, day, 'country', country, COUNT(*), COUNT(*) FROM {$views} WHERE day = %s AND seq = 1 GROUP BY site_id, country" . $upsert, $day ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$views} WHERE day = %s", $day ) );
			++$done;
		}
		// phpcs:enable
		return $done;
	}

	/**
	 * `wp_uninitialize_site`: a deleted site's rows go with it.
	 *
	 * @param \WP_Site $site The site.
	 */
	public static function forget_site( \WP_Site $site ): void {
		global $wpdb;
		foreach ( array( self::views(), self::seen(), self::daily(), self::values() ) as $table ) {
			$wpdb->delete( $table, array( 'site_id' => (int) $site->blog_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own tables.
		}
	}
}
