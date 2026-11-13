<?php
/**
 * Rate limits for the public routes and forms (the security pass of
 * 2026-11-12, tickets/M5-01-hardening-launch.md): signup, the signup chat's
 * checks, log in, lost password, comments, ALTCHA challenges, and the
 * analytics beacon.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Security;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Counters in one small network table, `mody_rate_limits`, one row per
 * limit and visitor: a hit count and the time the window ends. A window
 * starts at the first hit and the count starts again once it is over.
 * Counting is one atomic SQL statement, so parallel requests cannot all slip
 * through (ALTCHA's single-use marks rely on it too, see counter()).
 *
 * **What is kept:** a keyed hash of the address (or of the email, or of the
 * login name) with the secret `rate-limit` of Security\Secrets, never the
 * value itself, until its window ends. Expired rows are deleted as the
 * table is used.
 *
 * **Which address:** ClientAddress::limit_address(). When it is not known (a
 * private address with no forwarding header: the site calling itself, a
 * local browser, WP-CLI) the per-address limits are skipped instead of
 * throttling everyone together. Limits with no visitor (the whole network's
 * signups per hour) always count.
 *
 * **Fail open:** if the table cannot be written, nothing is limited.
 *
 * The limits are in DEFAULTS and can be changed with the filter
 * `mody_rate_limits`, which takes and returns name => array( events, seconds ).
 * Zero events turns a limit off.
 */
final class RateLimit {

	/**
	 * Every limit: name => array( events allowed, window in seconds ).
	 *
	 * - signup_address:   accounts made from one address a day (every valid
	 *                     attempt counts, one that finds an existing account too).
	 * - signup_email:     accounts for one email a day, `+tags` and Gmail dots
	 *                     removed.
	 * - signup_network:   accounts made in an hour, everywhere.
	 * - signup_check:     the chat's answers checked from one address.
	 * - signup_note:      "someone tried to sign up with your email" notes to
	 *                     one existing member.
	 * - login_address:    failed logins from one address.
	 * - login_pair:       failed logins for one account at one address (the
	 *                     key is both, so a stranger pauses the account only
	 *                     for their own address).
	 * - login_account:    failed logins for one account from everywhere, a
	 *                     last resort against guesses from many addresses;
	 *                     it does not apply to a browser the account has
	 *                     logged in from (FormGuard).
	 * - lost_password:    lost-password requests from one address.
	 * - comment:          comments posted by visitors from one address.
	 * - altcha_challenge: challenges handed to one address.
	 * - beacon:           analytics views from one address (only counted views
	 *                     are dropped, nothing is refused).
	 * - mollie_webhook:   Mollie's webhook calls from one address (each asks
	 *                     Mollie's API once).
	 * - card_check:       card checks one member starts (Mollie checkouts).
	 */
	public const DEFAULTS = array(
		'signup_address'   => array( 3, DAY_IN_SECONDS ),
		'signup_email'     => array( 3, DAY_IN_SECONDS ),
		'signup_network'   => array( 30, HOUR_IN_SECONDS ),
		'signup_check'     => array( 30, 10 * MINUTE_IN_SECONDS ),
		'signup_note'      => array( 1, 6 * HOUR_IN_SECONDS ),
		'login_address'    => array( 10, 15 * MINUTE_IN_SECONDS ),
		'login_pair'       => array( 5, 15 * MINUTE_IN_SECONDS ),
		'login_account'    => array( 100, 15 * MINUTE_IN_SECONDS ),
		'lost_password'    => array( 5, HOUR_IN_SECONDS ),
		'comment'          => array( 10, HOUR_IN_SECONDS ),
		'altcha_challenge' => array( 60, 10 * MINUTE_IN_SECONDS ),
		'beacon'           => array( 120, MINUTE_IN_SECONDS ),
		'mollie_webhook'   => array( 120, MINUTE_IN_SECONDS ),
		'card_check'       => array( 10, HOUR_IN_SECONDS ),
	);

	/** The table, without the network's prefix. */
	private const TABLE = 'mody_rate_limits';

	/** About one hit in this many also deletes the rows whose window is over. */
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
	 * The limits, after the filter.
	 *
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function limits(): array {
		/**
		 * Filters the rate limits.
		 *
		 * @param array<string, array{0: int, 1: int}> $limits Name => array( events allowed, seconds ). 0 events: no limit.
		 */
		$limits = apply_filters( 'mody_rate_limits', self::DEFAULTS );
		return is_array( $limits ) ? $limits : self::DEFAULTS;
	}

	/**
	 * Counts one event and says whether it is over the limit.
	 *
	 * @param string $name    A key of DEFAULTS.
	 * @param string $subject Who it counts for: an address, an email, a login name; '' for everyone together.
	 * @return int Seconds to wait; 0 when the event is allowed.
	 */
	public static function hit( string $name, string $subject = '' ): int {
		$limit = self::limit( $name );
		if ( ! $limit ) {
			return 0;
		}
		$row = self::counter( self::bucket( $name, $subject ), $limit[1] );
		return $row && $row['hits'] > $limit[0] ? max( 1, $row['expires'] - time() ) : 0;
	}

	/**
	 * Says whether the limit is already reached, without counting (for
	 * failures, which are counted after the fact, with hit()).
	 *
	 * @param string $name    A key of DEFAULTS.
	 * @param string $subject Who it counts for.
	 * @return int Seconds to wait; 0 when another event is allowed.
	 */
	public static function wait( string $name, string $subject = '' ): int {
		global $wpdb;
		$limit = self::limit( $name );
		if ( ! $limit ) {
			return 0;
		}
		$table = self::table();
		$wpdb->suppress_errors( true );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT hits, expires FROM {$table} WHERE bucket = %s AND expires > %d", self::bucket( $name, $subject ), time() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$wpdb->suppress_errors( false );
		return is_array( $row ) && (int) $row['hits'] >= $limit[0] ? max( 1, (int) $row['expires'] - time() ) : 0;
	}

	/**
	 * `hit()` for the visitor's address; 0 (allowed, not counted) when it is
	 * not known.
	 *
	 * @param string $name A key of DEFAULTS.
	 * @return int Seconds to wait.
	 */
	public static function hit_address( string $name ): int {
		$address = ClientAddress::limit_address();
		return '' === $address ? 0 : self::hit( $name, $address );
	}

	/**
	 * `wait()` for the visitor's address; 0 when it is not known.
	 *
	 * @param string $name A key of DEFAULTS.
	 * @return int Seconds to wait.
	 */
	public static function wait_address( string $name ): int {
		$address = ClientAddress::limit_address();
		return '' === $address ? 0 : self::wait( $name, $address );
	}

	/**
	 * Starts a limit over for one subject (a login name after a good login).
	 *
	 * @param string $name    A key of DEFAULTS.
	 * @param string $subject Who it counts for.
	 */
	public static function reset( string $name, string $subject = '' ): void {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$wpdb->delete( self::table(), array( 'bucket' => self::bucket( $name, $subject ) ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->suppress_errors( false );
	}

	/**
	 * Deletes every counter (tests).
	 */
	public static function clear(): void {
		global $wpdb;
		$table = self::table();
		$wpdb->suppress_errors( true );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table, no input.
		$wpdb->suppress_errors( false );
	}

	/**
	 * A kind refusal, as a REST error (429).
	 *
	 * @param int         $wait    Seconds until the visitor can try again.
	 * @param string|null $message What to say; `%s` is the time to wait. A general one by default.
	 */
	public static function error( int $wait, ?string $message = null ): WP_Error {
		return new WP_Error(
			'mody_rate_limited',
			self::message( $wait, $message ),
			array(
				'status'      => 429,
				'retry_after' => $wait,
			)
		);
	}

	/**
	 * The words for a refusal.
	 *
	 * @param int         $wait    Seconds until the visitor can try again.
	 * @param string|null $message A message with `%s` for the time to wait.
	 */
	public static function message( int $wait, ?string $message = null ): string {
		$message = $message ?? __( 'That is a few too many tries in a short time. Please try again in %s.', 'mody_cloud' );
		return sprintf( $message, human_time_diff( time(), time() + max( 60, $wait ) ) );
	}

	/**
	 * One atomic count in a window: the building block of the limits, and of
	 * ALTCHA's "each solution once" (Altcha::use_up()).
	 *
	 * @param string $bucket The counter's name, 64 characters at most.
	 * @param int    $window Seconds a window lasts, from the first hit.
	 * @return array{hits: int, expires: int, new: bool}|null `new` is true for the one hit that
	 *         made the row (the first of a window); null when the table cannot be written.
	 */
	public static function counter( string $bucket, int $window ): ?array {
		global $wpdb;
		$table = self::table();
		$now   = time();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Own table; the statement is prepared, and an upsert has no WordPress API.
		$query = $wpdb->prepare(
			"INSERT INTO {$table} (bucket, hits, expires) VALUES (%s, 1, %d)
			ON DUPLICATE KEY UPDATE
				hits = IF(expires <= %d, 1, hits + 1),
				expires = IF(expires <= %d, VALUES(expires), expires)", // MySQL applies the assignments left to right: expires changes last.
			$bucket,
			$now + $window,
			$now,
			$now
		);
		$wpdb->suppress_errors( true );
		$result = $wpdb->query( $query );
		if ( false === $result && ! self::$installed ) {
			self::install();
			$result = $wpdb->query( $query );
		}
		if ( false === $result ) {
			$wpdb->suppress_errors( false );
			return null;
		}
		$inserted = 1 === $wpdb->rows_affected; // An update counts 2 (or 0): only the hit that made the row is new.
		$row      = $inserted ? array(
			'hits'    => 1,
			'expires' => $now + $window,
		) : $wpdb->get_row( $wpdb->prepare( "SELECT hits, expires FROM {$table} WHERE bucket = %s", $bucket ), ARRAY_A );
		// phpcs:enable
		$wpdb->suppress_errors( false );
		if ( ! is_array( $row ) ) {
			return null;
		}
		if ( 1 === wp_rand( 1, self::PURGE_ONE_IN ) ) {
			self::purge();
		}
		return array(
			'hits'    => (int) $row['hits'],
			'expires' => (int) $row['expires'],
			'new'     => $inserted,
		);
	}

	/**
	 * Deletes the counters whose window is over.
	 */
	private static function purge(): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires < %d LIMIT 1000", time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
	}

	/**
	 * Creates the table, the first time a counter is written.
	 */
	private static function install(): void {
		global $wpdb;
		self::$installed = true;
		$table           = self::table();
		$charset         = $wpdb->get_charset_collate();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Own table.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} (bucket varchar(64) NOT NULL, hits int(10) unsigned NOT NULL DEFAULT 0, expires int(10) unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (bucket), KEY expires (expires)) {$charset}" );
	}

	/**
	 * A limit's rule, or null when it is off or unknown.
	 *
	 * @param string $name A key of DEFAULTS.
	 * @return array{0: int, 1: int}|null
	 */
	private static function limit( string $name ): ?array {
		$limit = self::limits()[ $name ] ?? null;
		if ( ! is_array( $limit ) || (int) ( $limit[0] ?? 0 ) <= 0 || (int) ( $limit[1] ?? 0 ) <= 0 ) {
			return null;
		}
		return array( (int) $limit[0], (int) $limit[1] );
	}

	/**
	 * A counter's name: the limit and a keyed hash of who it counts for.
	 *
	 * @param string $name    The limit.
	 * @param string $subject An address, an email, a login name, or ''.
	 */
	private static function bucket( string $name, string $subject ): string {
		return $name . ':' . substr( hash_hmac( 'sha256', $subject, Secrets::get( 'rate-limit' ) ), 0, 32 );
	}
}
