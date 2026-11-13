<?php
/**
 * WP-CLI: `wp mody-domains`.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Domains;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Custom domains from the command line (tickets/custom-domains.md).
 */
final class Command {

	/**
	 * Lists every custom domain.
	 */
	public function list(): void {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, site_id, domain, status, npm_host_id, npm_cert_id, checked_at, error FROM ' . Mapping::table() . ' ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Own table.
		if ( ! $rows ) {
			WP_CLI::line( 'No custom domains.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'site_id', 'domain', 'status', 'npm_host_id', 'npm_cert_id', 'checked_at', 'error' ) );
	}

	/**
	 * Adds a domain to a site.
	 *
	 * ## OPTIONS
	 *
	 * <site>
	 * : The site's ID.
	 *
	 * <domain>
	 * : The domain, such as www.example.com.
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function add( array $args ): void {
		$row = Domains::add( (int) $args[0], (string) $args[1] );
		if ( is_wp_error( $row ) ) {
			WP_CLI::error( $row->get_error_message() );
		}
		$row = Domains::advance( $row );
		WP_CLI::success( $row['domain'] . ': ' . $row['status'] . ( $row['error'] ? ' (' . $row['error'] . ')' : '' ) );
	}

	/**
	 * Checks a site's domain now and moves it on as far as it can.
	 *
	 * ## OPTIONS
	 *
	 * <site>
	 * : The site's ID.
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function check( array $args ): void {
		$row = Domains::of_site( (int) $args[0] );
		if ( ! $row ) {
			WP_CLI::error( 'That site has no custom domain.' );
		}
		$row = Domains::advance( $row );
		WP_CLI::success( $row['domain'] . ': ' . $row['status'] . ( $row['error'] ? ' (' . $row['error'] . ')' : '' ) );
	}

	/**
	 * Takes a site's domain off.
	 *
	 * ## OPTIONS
	 *
	 * <site>
	 * : The site's ID.
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function remove( array $args ): void {
		$done = Domains::remove( (int) $args[0] );
		if ( is_wp_error( $done ) ) {
			WP_CLI::error( $done->get_error_message() );
		}
		WP_CLI::success( 'Removed.' );
	}

	/**
	 * Runs what the cron runs: pending checks and certificates.
	 */
	public function run(): void {
		WP_CLI::success( (string) wp_json_encode( Domains::run() ) );
	}
}
