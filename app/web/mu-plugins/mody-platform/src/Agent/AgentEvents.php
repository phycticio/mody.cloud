<?php
/**
 * The agent's audit log: the network-wide `mody_agent_events` table.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Records every chat request and every tool call of the agent
 * (documentation/mvp-spec.md sections 8 and 11).
 *
 * One row per tool call (`tool` set), and one row per chat request (`tool`
 * NULL) with the reply. Refusals are tool rows too: `refuse_request`, or the
 * name of a tool that does not exist. Never logs tokens.
 *
 * Rows older than the retention set in AgentSettings are deleted once a day,
 * by the first event written that day. No cron.
 */
final class AgentEvents {

	/** Bump when the table definition in install() changes. */
	private const DB_VERSION = '2';

	/** Network option that records the installed table version. */
	private const DB_VERSION_OPTION = 'mody_agent_events_db_version';

	/** Network transient set for a day after each purge. */
	private const PURGED_TRANSIENT = 'mody_agent_events_purged';

	/** Longest stored input or result, in characters. */
	private const MAX_TEXT = 4000;

	/**
	 * The table name. The table is network-wide, so it uses the base prefix.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'mody_agent_events';
	}

	/**
	 * Creates or updates the table when its version changed.
	 */
	public static function install(): void {
		if ( self::DB_VERSION === get_site_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta() needs this exact layout: one column per line, two spaces after PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  site_id bigint(20) unsigned NOT NULL,
  input text,
  tool varchar(60) DEFAULT NULL,
  args json DEFAULT NULL,
  result text,
  duration_ms int(10) unsigned DEFAULT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY site_created (site_id,created_at),
  KEY user_id (user_id),
  KEY created_at (created_at)
) {$charset};"
		);

		update_site_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Writes one event for the current user and site.
	 *
	 * @param string      $input    The member's message.
	 * @param string|null $tool     Tool name, or null for the request itself.
	 * @param mixed       $args     Tool arguments (stored as JSON), or null.
	 * @param string      $result   What happened: the reply, the tool result or the error.
	 * @param float       $started  microtime( true ) when it started.
	 * @return int The event ID, or 0 if it could not be written.
	 */
	public static function log( string $input, ?string $tool, $args, string $result, float $started ): int {
		global $wpdb;

		$json = null === $args ? null : wp_json_encode( $args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom audit table.
		$written = $wpdb->insert(
			self::table(),
			array(
				'user_id'     => get_current_user_id(),
				'site_id'     => get_current_blog_id(),
				'input'       => mb_substr( $input, 0, self::MAX_TEXT ),
				'tool'        => null === $tool ? null : mb_substr( $tool, 0, 60 ),
				'args'        => false === $json ? null : $json,
				'result'      => mb_substr( $result, 0, self::MAX_TEXT ),
				'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( false === $written ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- An unlogged agent action must be visible somewhere.
			error_log( '[mody-platform] could not write an agent event: ' . $wpdb->last_error );
			return 0;
		}

		$id = (int) $wpdb->insert_id;
		self::maybe_purge();
		return $id;
	}

	/**
	 * Purges old events if no purge ran in the last day.
	 */
	public static function maybe_purge(): void {
		if ( false !== get_site_transient( self::PURGED_TRANSIENT ) ) {
			return;
		}
		set_site_transient( self::PURGED_TRANSIENT, time(), DAY_IN_SECONDS );
		self::purge( AgentSettings::retention_days() );
	}

	/**
	 * Deletes the events older than a number of days.
	 *
	 * @param int $days Days to keep.
	 * @return int Rows deleted.
	 */
	public static function purge( int $days ): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom audit table.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", max( 1, $days ) ) );
		return (int) $deleted;
	}

	/**
	 * How many events are stored, and when the oldest was written.
	 *
	 * @return array{count: int, oldest: string|null} `oldest` in the database's time.
	 */
	public static function stats(): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom audit table.
		$row = $wpdb->get_row( "SELECT COUNT(*) AS count, MIN(created_at) AS oldest FROM {$table}", ARRAY_A );
		return array(
			'count'  => (int) ( $row['count'] ?? 0 ),
			'oldest' => $row['oldest'] ?? null,
		);
	}
}
