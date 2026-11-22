<?php
/**
 * `wp mody-security`: the file check, and two-step login for one account.
 *
 * @package Mody\Security
 */

namespace Mody\Security;

use WP_CLI;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Mody Security's commands.
 */
final class Command {

	/**
	 * Compares the code with what the last deploy shipped.
	 *
	 * ## OPTIONS
	 *
	 * [--alert]
	 * : Email the super admins when something differs (the Alerts setting decides).
	 *
	 * ## EXAMPLES
	 *
	 *     wp mody-security check-files --alert
	 *
	 * @subcommand check-files
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function check_files( array $args, array $assoc_args ): void {
		$result = FileCheck::run();
		if ( ! $result['manifest'] ) {
			WP_CLI::warning( FileCheck::platform() ? 'No app/files.sha256: the deploy writes it. Locally, run `wp mody-security files-manifest` first.' : 'No list yet: run `wp mody-security files-manifest` once the code is as it should be.' );
		}
		foreach ( array( 'changed', 'added', 'missing', 'uploads' ) as $key ) {
			foreach ( $result[ $key ] as $path ) {
				WP_CLI::line( str_pad( $key, 8 ) . ' ' . $path );
			}
		}
		WP_CLI::line( sprintf( 'Checked %d files in %.2f s: %d changed, %d added, %d missing, %d scripts in uploads.', $result['checked'], $result['seconds'], $result['changed_count'], $result['added_count'], $result['missing_count'], $result['uploads_count'] ) );
		if ( FileCheck::found( $result ) ) {
			if ( ! empty( $assoc_args['alert'] ) ) {
				Alerts::file_changes( $result );
			}
			WP_CLI::halt( 1 );
		}
		WP_CLI::success( $result['manifest'] ? 'Every file is as deployed.' : 'No scripts in uploads.' );
	}

	/**
	 * Writes the list of code files the check compares with: app/files.sha256
	 * on Mody Cloud (a local checkout; the deploy writes the server's), the
	 * database on a plain WordPress.
	 *
	 * @subcommand files-manifest
	 */
	public function files_manifest(): void {
		$count = FileCheck::write_manifest();
		WP_CLI::success( FileCheck::platform() ? sprintf( 'Listed %d files in app/%s.', $count, FileCheck::MANIFEST ) : sprintf( 'Listed %d files.', $count ) );
	}

	/**
	 * Shows or turns off an account's two-step login.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : status or reset.
	 * ---
	 * options:
	 *   - status
	 *   - reset
	 * ---
	 *
	 * <user>
	 * : A login, email or ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mody-security two-factor status admin
	 *     wp mody-security two-factor reset someone@example.com
	 *
	 * @subcommand two-factor
	 * @param string[] $args Positional arguments.
	 */
	public function two_factor( array $args ): void {
		list( $action, $who ) = $args;
		$user                 = is_numeric( $who ) ? get_userdata( (int) $who ) : ( is_email( $who ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who ) );
		if ( ! $user instanceof WP_User ) {
			WP_CLI::error( 'No such user.' );
		}
		if ( 'reset' === $action ) {
			TwoFactor::reset( $user->ID );
			Events::record( 'two_factor_off', $user->ID, 'turned off with WP-CLI' );
			WP_CLI::success( sprintf( 'Two-step login is off for %s.', $user->user_login ) );
			return;
		}
		WP_CLI::line( sprintf( 'Account: %s', $user->user_login ) );
		WP_CLI::line( sprintf( 'Must use it: %s', TwoFactor::required( $user ) ? 'yes' : 'no' ) );
		WP_CLI::line( sprintf( 'Authenticator app: %s', TwoFactor::has_totp( $user->ID ) ? 'yes' : 'no' ) );
		WP_CLI::line( sprintf( 'Security keys: %d', count( TwoFactor::keys( $user->ID ) ) ) );
		WP_CLI::line( sprintf( 'Recovery codes left: %d', TwoFactor::codes_left( $user->ID ) ) );
	}
}
