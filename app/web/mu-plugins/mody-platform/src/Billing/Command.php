<?php
/**
 * WP-CLI: `wp mody-billing`.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Billing;

use Mody\Platform\Members\Members;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * The daily billing run and a look at one payment or account, for a host
 * cron and for operators (M1-01 part 3).
 */
final class Command {

	/**
	 * Runs the day's billing: payments still waiting, reminders, charges and
	 * retries. Safe to run again: it never charges a period twice.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mody-billing run --url=https://mody.cloud
	 */
	public function run(): void {
		if ( ! Mollie::configured() ) {
			WP_CLI::warning( 'MOLLIE_API_KEY is not set: reminders only; no charge can be made.' );
		}
		$done = Billing::run();
		WP_CLI::success( (string) wp_json_encode( $done ) );
	}

	/**
	 * Asks Mollie about one payment and applies it.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The payment's Mollie ID (`tr_…`).
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function process( array $args ): void {
		$row = Billing::process( (string) ( $args[0] ?? '' ) );
		if ( ! $row ) {
			WP_CLI::error( 'Not a payment the platform started.' );
		}
		WP_CLI::success( $row['kind'] . ' ' . $row['mollie_id'] . ': ' . $row['status'] . ( (int) $row['applied'] ? ', applied' : '' ) );
	}

	/**
	 * Shows an account's plan, card and payments.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : The member's user ID or email.
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function status( array $args ): void {
		$user = is_numeric( $args[0] ?? '' ) ? get_userdata( (int) $args[0] ) : get_user_by( 'email', (string) ( $args[0] ?? '' ) );
		$row  = $user ? Members::get( (int) $user->ID ) : null;
		if ( ! $row ) {
			WP_CLI::error( 'Not a member.' );
		}
		unset( $row['mollie_customer_id'] );
		$row['card'] = get_user_meta( (int) $user->ID, Billing::CARD_META, true );
		WP_CLI::line( (string) wp_json_encode( $row, JSON_PRETTY_PRINT ) );
		$payments = Payments::of( (int) $user->ID );
		if ( $payments ) {
			WP_CLI\Utils\format_items( 'table', $payments, array( 'id', 'mollie_id', 'kind', 'amount', 'sites', 'period', 'covers_from', 'status', 'applied', 'refunded_at', 'created_at' ) );
		}
	}
}
