<?php
/**
 * The network-wide `mody_provisioning_jobs` table (mvp-spec section 11).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Provisioning;

defined( 'ABSPATH' ) || exit;

/**
 * One row per site creation: the wizard's input, the step reached, and what
 * the steps produced. Pipeline reads and writes it; nothing else does.
 */
final class Jobs {

	/** The steps, in the order they run. `done` is the end. */
	public const STEPS = array( 'quota', 'site', 'profile', 'theme', 'styles', 'page', 'email', 'done' );

	/** A running job not updated for this long was killed: it may be run again. */
	public const STALE_AFTER = 5 * MINUTE_IN_SECONDS;

	/** Bump when the table definition in install() changes. */
	private const DB_VERSION = '1';

	/** Network option that records the installed table version. */
	private const DB_VERSION_OPTION = 'mody_provisioning_jobs_db_version';

	/**
	 * The table name. The table is network-wide, so it uses the base prefix.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'mody_provisioning_jobs';
	}

	/**
	 * Creates or updates the table when its version changed.
	 *
	 * Additions to the spec's definition: the `queued` status, `input` (the
	 * wizard's answers) and `result` (what the steps produced), both JSON.
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
  site_id bigint(20) unsigned DEFAULT NULL,
  step enum('quota','site','profile','theme','styles','page','email','done') NOT NULL DEFAULT 'quota',
  status enum('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  attempts int(11) NOT NULL DEFAULT 0,
  last_error text,
  input longtext,
  result longtext,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY status (status)
) {$charset};"
		);

		update_site_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Creates a queued job.
	 *
	 * @param int                  $user_id The member the site is for.
	 * @param array<string, mixed> $input   The validated wizard answers.
	 * @return int The job ID, or 0 on a database error.
	 */
	public static function create( int $user_id, array $input ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table; one row.
		$ok = $wpdb->insert(
			self::table(),
			array(
				'user_id' => $user_id,
				'input'   => wp_json_encode( $input ),
				'result'  => '{}',
			),
			array( '%d', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Loads a job.
	 *
	 * @param int $job_id Job ID.
	 * @return array{id: int, user_id: int, site_id: int, step: string, status: string, attempts: int,
	 *               last_error: string, input: array<string, mixed>, result: array<string, mixed>,
	 *               updated_at: string}|null
	 */
	public static function get( int $job_id ): ?array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; primary key.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}

		$input  = json_decode( (string) $row['input'], true );
		$result = json_decode( (string) $row['result'], true );
		return array(
			'id'         => (int) $row['id'],
			'user_id'    => (int) $row['user_id'],
			'site_id'    => (int) $row['site_id'],
			'step'       => (string) $row['step'],
			'status'     => (string) $row['status'],
			'attempts'   => (int) $row['attempts'],
			'last_error' => (string) $row['last_error'],
			'input'      => is_array( $input ) ? $input : array(),
			'result'     => is_array( $result ) ? $result : array(),
			'updated_at' => (string) $row['updated_at'],
		);
	}

	/**
	 * Marks a job as running, unless it is done or another request runs it.
	 *
	 * A single UPDATE, so two requests cannot both claim it. A running job
	 * counts as killed once it is STALE_AFTER old.
	 *
	 * @param int $job_id Job ID.
	 * @return bool Whether this request may run the job.
	 */
	public static function claim( int $job_id ): bool {
		global $wpdb;
		$table = self::table();
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::STALE_AFTER );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; primary key.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'running', attempts = attempts + 1, last_error = NULL, updated_at = UTC_TIMESTAMP()
				WHERE id = %d AND status <> 'done' AND ( status <> 'running' OR updated_at < %s )",
				$job_id,
				$stale
			)
		);
		return 1 === $claimed;
	}

	/**
	 * Changes a job's columns. `result` is merged into the stored result.
	 *
	 * @param int                  $job_id Job ID.
	 * @param array<string, mixed> $fields site_id, step, status, last_error, result.
	 */
	public static function update( int $job_id, array $fields ): void {
		$columns = array_intersect_key( $fields, array_flip( array( 'site_id', 'step', 'status', 'last_error' ) ) );
		if ( isset( $fields['result'] ) ) {
			$job               = self::get( $job_id );
			$columns['result'] = wp_json_encode( array_merge( $job['result'] ?? array(), $fields['result'] ) );
		}
		$columns['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table; primary key.
		$wpdb->update( self::table(), $columns, array( 'id' => $job_id ) );
	}

	/**
	 * A user's most recent job that is not done, changed in the last day:
	 * the wizard picks it up again after a reload.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, mixed>|null The job (see get()).
	 */
	public static function unfinished_for_user( int $user_id ): ?array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; indexed.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d AND status <> 'done' AND updated_at > %s ORDER BY id DESC LIMIT 1",
				$user_id,
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);
		return $id ? self::get( (int) $id ) : null;
	}

	/**
	 * Whether a running job stopped being updated: its run was killed.
	 *
	 * @param array<string, mixed> $job The job (see get()).
	 */
	public static function is_stale( array $job ): bool {
		return 'running' === $job['status'] && strtotime( $job['updated_at'] . ' UTC' ) < time() - self::STALE_AFTER;
	}

	/**
	 * Jobs of a user that have not created their site yet (they count against the quota).
	 *
	 * @param int $user_id     User ID.
	 * @param int $exclude_job A job not to count (the one being checked).
	 */
	public static function pending_without_site( int $user_id, int $exclude_job = 0 ): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; indexed.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND id <> %d AND site_id IS NULL AND status IN ('queued','running')",
				$user_id,
				$exclude_job
			)
		);
	}
}
