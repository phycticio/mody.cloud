<?php
/**
 * The security log: what happened at the doors of the network, kept 30
 * days (tickets/security-plugin-replaces-wordfence.md).
 *
 * @package Mody\Security
 */

namespace Mody\Security;

defined( 'ABSPATH' ) || exit;

/**
 * One network table, `mody_security_events`: a row per login, failed login,
 * pause, blocked username, firewall block, two-step change or failure, file
 * check finding, and settings change. Each row keeps the visitor's address
 * (decided by the maintainer on 2026-11-21: like Wordfence, so an address
 * can be blocked from the log), the account when there is one, the site and
 * a short detail. Rows older than KEEP_DAYS are deleted as the table is
 * used. Writing never breaks a request: a failed insert is ignored.
 */
final class Events {

	/** Days a row is kept. */
	public const KEEP_DAYS = 30;

	/** What can happen: type => label (translated in labels()). */
	public const TYPES = array(
		'login',
		'login_failed',
		'paused',
		'username_blocked',
		'two_factor_failed',
		'two_factor_on',
		'two_factor_off',
		'firewall',
		'address_blocked',
		'file_changes',
		'settings',
	);

	/** The table, without the network's prefix. */
	private const TABLE = 'mody_security_events';

	/** About one write in this many also deletes old rows. */
	private const PURGE_ONE_IN = 50;

	/**
	 * Whether this request already tried to create the table.
	 *
	 * @var bool
	 */
	private static bool $installed = false;

	/**
	 * The table's name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->base_prefix . self::TABLE;
	}

	/**
	 * The types' names, for people.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'login'             => __( 'Logged in', 'mody_cloud' ),
			'login_failed'      => __( 'Failed login', 'mody_cloud' ),
			'paused'            => __( 'Logins paused', 'mody_cloud' ),
			'username_blocked'  => __( 'Blocked username', 'mody_cloud' ),
			'two_factor_failed' => __( 'Wrong second step', 'mody_cloud' ),
			'two_factor_on'     => __( 'Two-step login changed', 'mody_cloud' ),
			'two_factor_off'    => __( 'Two-step login off', 'mody_cloud' ),
			'firewall'          => __( 'Firewall block', 'mody_cloud' ),
			'address_blocked'   => __( 'Blocked address', 'mody_cloud' ),
			'file_changes'      => __( 'File check', 'mody_cloud' ),
			'settings'          => __( 'Settings changed', 'mody_cloud' ),
		);
	}

	/**
	 * Records one event.
	 *
	 * @param string $type    One of TYPES.
	 * @param int    $user_id The account, or 0.
	 * @param string $detail  A short detail (cut to 255 characters).
	 */
	public static function record( string $type, int $user_id = 0, string $detail = '' ): void {
		global $wpdb;
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return;
		}
		$row = array(
			'created' => time(),
			'type'    => $type,
			'user_id' => max( 0, $user_id ),
			'site_id' => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0,
			'address' => substr( ClientAddress::get(), 0, 45 ),
			'detail'  => mb_substr( wp_strip_all_tags( $detail ), 0, 255 ),
		);
		$wpdb->suppress_errors( true );
		$done = $wpdb->insert( self::table(), $row, array( '%d', '%s', '%d', '%d', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		if ( false === $done && ! self::$installed ) {
			self::install();
			$wpdb->insert( self::table(), $row, array( '%d', '%s', '%d', '%d', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		}
		$wpdb->suppress_errors( false );
		if ( 1 === random_int( 1, self::PURGE_ONE_IN ) ) { // Not wp_rand(): pluggable, and the firewall counts before plugins load.
			self::purge();
		}
	}

	/**
	 * The latest events, newest first.
	 *
	 * @param int    $limit At most this many.
	 * @param string $type  Only this type; '' for all.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = 200, string $type = '' ): array {
		global $wpdb;
		$table = self::table();
		$wpdb->suppress_errors( true );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$rows = in_array( $type, self::TYPES, true )
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s AND created >= %d ORDER BY id DESC LIMIT %d", $type, self::since(), $limit ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created >= %d ORDER BY id DESC LIMIT %d", self::since(), $limit ), ARRAY_A );
		// phpcs:enable
		$wpdb->suppress_errors( false );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many events of each type since a moment.
	 *
	 * @param int $since A Unix time.
	 * @return array<string, int>
	 */
	public static function counts( int $since ): array {
		global $wpdb;
		$table = self::table();
		$wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT type, COUNT(*) AS n FROM {$table} WHERE created >= %d GROUP BY type", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$wpdb->suppress_errors( false );
		$counts = array_fill_keys( self::TYPES, 0 );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ $row['type'] ] = (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * Deletes every row (tests).
	 */
	public static function clear(): void {
		global $wpdb;
		$table = self::table();
		$wpdb->suppress_errors( true );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table, no input.
		$wpdb->suppress_errors( false );
	}

	/**
	 * Deletes the rows older than KEEP_DAYS.
	 */
	public static function purge(): void {
		global $wpdb;
		$table = self::table();
		$wpdb->suppress_errors( true );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created < %d LIMIT 5000", self::since() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$wpdb->suppress_errors( false );
	}

	/**
	 * The oldest moment kept.
	 */
	private static function since(): int {
		return time() - self::KEEP_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Creates the table, the first time an event is written.
	 */
	private static function install(): void {
		global $wpdb;
		self::$installed = true;
		$table           = self::table();
		$charset         = $wpdb->get_charset_collate();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Own table.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, created int(10) unsigned NOT NULL, type varchar(32) NOT NULL, user_id bigint(20) unsigned NOT NULL DEFAULT 0, site_id bigint(20) unsigned NOT NULL DEFAULT 0, address varchar(45) NOT NULL DEFAULT '', detail varchar(255) NOT NULL DEFAULT '', PRIMARY KEY  (id), KEY created (created), KEY type_created (type, created)) {$charset}" );
	}
}
