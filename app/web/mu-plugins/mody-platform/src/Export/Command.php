<?php
/**
 * WP-CLI: `wp mody-export`.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Export;

use WP_CLI;
use WP_Site;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and inspects site downloads from the command line
 * (tickets/take-your-site-with-you.md). `run` is what Runner spawns for every
 * job; `watch` is for a host cron; `start`, `purge` and `list` are for tests
 * and operators.
 */
final class Command {

	/**
	 * Registers `wp mody-export`, only inside WP-CLI.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'mody-export', self::class );
		}
	}

	/**
	 * Builds a job's files, from the step it is on. This is the command the
	 * dashboard's button ends up running, detached.
	 *
	 * ## OPTIONS
	 *
	 * <job>
	 * : The job ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mody-export run 12 --url=https://alpha.mody.cloud
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function run( array $args ): void {
		$job_id = absint( $args[0] ?? 0 );
		if ( ! $job_id || ! Jobs::get( $job_id ) ) {
			WP_CLI::error( 'No such job: ' . ( $args[0] ?? '' ) );
		}
		$done = Exporter::run( $job_id );
		if ( is_wp_error( $done ) ) {
			WP_CLI::error( $done->get_error_message() );
		}
		self::report( (int) $job_id );
		WP_CLI::success( 'Job ' . $job_id . ' is done.' );
	}

	/**
	 * Creates a download job for a site and builds it in the foreground, as
	 * the dashboard's button does in the background.
	 *
	 * ## OPTIONS
	 *
	 * --site=<site>
	 * : The site's ID, its subdomain (the part before the network's domain) or its full domain.
	 *
	 * [--owner=<user>]
	 * : The user the download is for: ID, login or email. Must be an administrator of the site. Defaults to WP-CLI's own `--user`, if given, else the site's first administrator. (WP-CLI takes `--user` for itself and never passes it on, so this command reads it as the current user.)
	 *
	 * ## EXAMPLES
	 *
	 *     wp mody-export start --site=alpha
	 *     wp mody-export start --site=1479 --owner=1091
	 *
	 * @param string[]              $args  Positional arguments.
	 * @param array<string, string> $assoc Named arguments.
	 */
	public function start( array $args, array $assoc ): void {
		unset( $args );
		$site = self::find_site( (string) ( $assoc['site'] ?? '' ) );
		if ( ! $site ) {
			WP_CLI::error( 'No such site: ' . ( $assoc['site'] ?? '' ) );
		}
		if ( is_main_site( (int) $site->blog_id ) ) {
			WP_CLI::error( 'The main site cannot be downloaded.' );
		}
		// WP-CLI's global --user is consumed by WP-CLI itself: it arrives as the current user, not in $assoc.
		$owner = (string) ( $assoc['owner'] ?? '' );
		if ( '' === $owner && get_current_user_id() ) {
			$owner = (string) get_current_user_id();
		}
		$user_id = self::find_user( (int) $site->blog_id, $owner );
		if ( ! $user_id ) {
			WP_CLI::error( 'No user to make the download for: pass --owner.' );
		}
		if ( ! self::is_site_administrator( $user_id, (int) $site->blog_id ) ) {
			WP_CLI::error( 'User ' . $user_id . ' is not an administrator of site ' . $site->blog_id . ': the download is for the site\'s owner.' );
		}
		Jobs::install();
		$job_id = Jobs::create( (int) $site->blog_id, $user_id );
		if ( ! $job_id ) {
			WP_CLI::error( 'Could not create the job.' );
		}
		WP_CLI::log( 'Job ' . $job_id . ' for site ' . $site->blog_id . ' (' . $site->domain . ').' );
		$done = Exporter::run( $job_id );
		if ( is_wp_error( $done ) ) {
			WP_CLI::error( $done->get_error_message() );
		}
		self::report( $job_id );
		WP_CLI::success( 'Job ' . $job_id . ' is done.' );
	}

	/**
	 * The watchdog, for a host cron: starts waiting downloads (at most two
	 * builds run at once), restarts builds whose builder died, and deletes
	 * expired files (at most hourly). Without it these happen only while
	 * someone has the dashboard open.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mody-export watch --quiet
	 */
	public function watch(): void {
		$started = Runner::watch();
		$expired = Exporter::sweep();
		WP_CLI::success( sprintf( '%d build(s) started, %d job(s) expired.', $started, $expired ) );
	}

	/**
	 * Deletes expired files and their jobs now, without waiting for the hourly sweep.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mody-export purge
	 */
	public function purge(): void {
		$count = Exporter::sweep( true );
		WP_CLI::success( sprintf( '%d job(s) expired.', $count ) );
	}

	/**
	 * Lists the download jobs, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : How many jobs. Default 20.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mody-export list
	 *
	 * @subcommand list
	 *
	 * @param string[]              $args  Positional arguments.
	 * @param array<string, string> $assoc Named arguments.
	 */
	public function list_( array $args, array $assoc ): void {
		unset( $args );
		global $wpdb;
		$table = Jobs::table();
		$limit = max( 1, absint( $assoc['limit'] ?? 20 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; an operator's listing.
		$ids  = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
		$rows = array();
		foreach ( $ids as $id ) {
			$job = Jobs::get( (int) $id );
			if ( ! $job ) {
				continue;
			}
			$rows[] = array(
				'id'        => $job['id'],
				'site'      => $job['site_id'],
				'user'      => $job['user_id'],
				'status'    => $job['status'],
				'step'      => $job['step'],
				'attempts'  => $job['attempts'],
				'downloads' => $job['downloads'],
				'files'     => implode( ',', array_keys( $job['files'] ) ),
				'created'   => $job['created_at'],
				'updated'   => $job['updated_at'],
				'error'     => $job['last_error'],
			);
		}
		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'id', 'site', 'user', 'status', 'step', 'attempts', 'downloads', 'files', 'created', 'updated', 'error' ) );
	}

	/**
	 * Prints a finished job's files.
	 *
	 * @param int $job_id Job.
	 */
	private static function report( int $job_id ): void {
		$job = Jobs::get( $job_id );
		if ( ! $job ) {
			return;
		}
		foreach ( Jobs::FILES as $which ) {
			if ( isset( $job['files'][ $which ] ) ) {
				WP_CLI::log( sprintf( '  %-9s %s (%s, sha256 %s)', $which, $job['files'][ $which ]['name'], size_format( (int) $job['files'][ $which ]['size'], 1 ), substr( (string) $job['files'][ $which ]['sha256'], 0, 12 ) ) );
			}
		}
		WP_CLI::log( '  folder    ' . Jobs::dir( $job ) );
	}

	/**
	 * A site by ID, subdomain or domain.
	 *
	 * @param string $site What the operator typed.
	 */
	private static function find_site( string $site ): ?WP_Site {
		if ( '' === $site ) {
			return null;
		}
		if ( ctype_digit( $site ) ) {
			return get_site( (int) $site ) ?: null;
		}
		$network = get_network();
		$domain  = str_contains( $site, '.' ) ? $site : $site . '.' . ( $network ? $network->domain : '' );
		return get_site_by_path( $domain, '/' ) ?: null;
	}

	/**
	 * Whether a user is a member of a site with the administrator's powers
	 * there (the capability is checked in the site's own context).
	 *
	 * @param int $user_id User.
	 * @param int $site_id Site.
	 */
	private static function is_site_administrator( int $user_id, int $site_id ): bool {
		if ( ! is_user_member_of_blog( $user_id, $site_id ) ) {
			return false;
		}
		switch_to_blog( $site_id );
		try {
			return user_can( $user_id, 'manage_options' );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * The user a job is for: the one named, or the site's first administrator.
	 *
	 * @param int    $site_id Site.
	 * @param string $user    ID, login or email; empty for the first administrator.
	 * @return int User ID; 0 when there is none.
	 */
	private static function find_user( int $site_id, string $user ): int {
		if ( '' !== $user ) {
			$found = get_user_by( ctype_digit( $user ) ? 'id' : ( is_email( $user ) ? 'email' : 'login' ), $user );
			return $found ? (int) $found->ID : 0;
		}
		$admins = get_users(
			array(
				'blog_id' => $site_id,
				'role'    => 'administrator',
				'orderby' => 'ID',
				'number'  => 1,
				'fields'  => 'ID',
			)
		);
		return $admins ? (int) $admins[0] : 0;
	}
}
