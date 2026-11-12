<?php
/**
 * The two emails of a site download.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Export;

use Mody\Platform\Dashboard\DashboardPage;

defined( 'ABSPATH' ) || exit;

/**
 * Plain text, short and kind (tickets/take-your-site-with-you.md):
 *
 * - started(): every administrator of the site hears that a download is being
 *   prepared and by whom, so a second administrator is never surprised.
 * - ready(): the person who asked hears it is ready. The link goes to the
 *   dashboard's Download your site section (the owner logs in), never to the
 *   files: a forwarded or leaked email must not hand out the site.
 *
 * Nothing here is a confirmation step: the download starts without anyone's
 * answer. Sending goes through wp_mail(), which is the mody-mail plugin's
 * (one sender, Brevo's API in production, Mailpit locally).
 */
final class Notices {

	/**
	 * A download was asked for: tell the site's administrators, each in their
	 * own language (their profile's, not the language of whoever asked).
	 *
	 * @param int $job_id   Job.
	 * @param int $asked_by Who pressed the button; the job's user when 0. It
	 *                      differs from the job's user when a super
	 *                      administrator who is not an administrator of the
	 *                      site asked: the job is then recorded for the site's
	 *                      first administrator (Dashboard\ExportPanel::start())
	 *                      and the email says Mody Cloud asked.
	 * @return int Emails sent.
	 */
	public static function started( int $job_id, int $asked_by = 0 ): int {
		$job = Jobs::get( $job_id );
		if ( ! $job ) {
			return 0;
		}
		$switched = self::enter( $job['site_id'] );
		try {
			$asker  = get_userdata( $asked_by > 0 ? $asked_by : $job['user_id'] );
			$admins = get_users( array( 'role' => 'administrator' ) );
			$ids    = array_map( static fn( \WP_User $admin ): int => (int) $admin->ID, $admins );
			$kind   = 'other';
			if ( $asker && in_array( (int) $asker->ID, $ids, true ) ) {
				$kind = 'administrator';
			} elseif ( $asker && is_super_admin( $asker->ID ) ) {
				$kind = 'platform';
			}
			$title  = self::title();
			$sent   = 0;
			$mailed = array();
			foreach ( $admins as $admin ) {
				$email = strtolower( (string) $admin->user_email );
				if ( '' === $email || isset( $mailed[ $email ] ) ) {
					continue;
				}
				$mailed[ $email ] = true;
				$message          = self::localized( (int) $admin->ID, static fn(): array => self::started_message( $admin, $asker, $kind, $title ) );
				$sent            += wp_mail( $admin->user_email, $message['subject'], $message['body'] ) ? 1 : 0;
			}
			return $sent;
		} finally {
			self::leave( $switched );
		}
	}

	/**
	 * The "being prepared" email for one administrator, in the language now in
	 * force (see localized()).
	 *
	 * @param \WP_User       $admin The administrator it goes to.
	 * @param \WP_User|false $asker Who pressed the button.
	 * @param string         $kind  'administrator' (an administrator of the site asked: told to them as "You asked", to the others by name), 'platform' (a super administrator who does not administer the site asked) or 'other'.
	 * @param string         $title The site's title.
	 * @return array{subject: string, body: string}
	 */
	private static function started_message( \WP_User $admin, $asker, string $kind, string $title ): array {
		$link = admin_url( 'users.php' );
		if ( $asker && $admin->ID === $asker->ID ) {
			$body = sprintf(
				/* translators: 1: the administrator's name, 2: the site title, 3: the site's address, 4: the Users screen's link. */
				__( "Hello %1\$s,\n\nYou asked to download the whole of %2\$s (%3\$s). Mody Cloud is preparing the three files now, and you will get another email when they are ready. You do not need to stay on the page.\n\nNothing changes on your site meanwhile.\n\nIf this was not you, see who has administrator access:\n%4\$s\n\nMody Cloud", 'mody_cloud' ),
				$admin->display_name,
				$title,
				home_url( '/' ),
				$link
			);
		} elseif ( 'platform' === $kind ) {
			$body = sprintf(
				/* translators: 1: the administrator's name, 2: the name of the person at Mody Cloud, 3: the site title, 4: the site's address, 5: the Users screen's link. */
				__( "Hello %1\$s,\n\nMody Cloud (%2\$s) asked to download the whole of %3\$s (%4\$s). Mody Cloud is preparing the three files now, and the first administrator of the site will get another email when they are ready.\n\nNothing changes on your site meanwhile.\n\nIf you did not expect this, see who has administrator access:\n%5\$s\n\nMody Cloud", 'mody_cloud' ),
				$admin->display_name,
				$asker->display_name,
				$title,
				home_url( '/' ),
				$link
			);
		} else {
			$body = sprintf(
				/* translators: 1: the administrator's name, 2: who asked, 3: the site title, 4: the site's address, 5: the Users screen's link. */
				__( "Hello %1\$s,\n\n%2\$s, another administrator of %3\$s (%4\$s), asked to download the whole site. Mody Cloud is preparing the three files now.\n\nNothing changes on your site meanwhile.\n\nIf you did not expect this, see who has administrator access:\n%5\$s\n\nMody Cloud", 'mody_cloud' ),
				$admin->display_name,
				$asker ? $asker->display_name : __( 'Someone', 'mody_cloud' ),
				$title,
				home_url( '/' ),
				$link
			);
		}
		return array(
			/* translators: %s: the site title. */
			'subject' => sprintf( __( 'Your site %s is being prepared for download', 'mody_cloud' ), $title ),
			'body'    => $body,
		);
	}

	/**
	 * The files are ready: tell the person who asked, in their own language
	 * (their profile's: the builder runs with the site's language). The link
	 * is the dashboard's section, never a file.
	 *
	 * Nothing here may throw: the builder calls it after the files are done,
	 * and a mail that cannot be sent must not undo a finished build.
	 *
	 * @param int $job_id Job.
	 * @return bool Whether the email was sent.
	 */
	public static function ready( int $job_id ): bool {
		try {
			return self::send_ready( $job_id );
		} catch ( \Throwable $e ) {
			wp_trigger_error( __METHOD__, $e->getMessage() );
			return false;
		}
	}

	/**
	 * The "ready" email itself.
	 *
	 * @param int $job_id Job.
	 * @return bool Whether the email was sent.
	 */
	private static function send_ready( int $job_id ): bool {
		$job = Jobs::get( $job_id );
		if ( ! $job ) {
			return false;
		}
		$user = get_userdata( $job['user_id'] );
		if ( ! $user || '' === (string) $user->user_email ) {
			return false;
		}
		$switched = self::enter( $job['site_id'] );
		try {
			$title   = self::title();
			$message = self::localized(
				(int) $user->ID,
				static function () use ( $user, $title ): array {
					$days = (int) round( Jobs::KEEP_FOR / DAY_IN_SECONDS );
					return array(
						/* translators: %s: the site title. */
						'subject' => sprintf( __( 'Your download of %s is ready', 'mody_cloud' ), $title ),
						'body'    => sprintf(
							/* translators: 1: the person's name, 2: the site title, 3: the dashboard's Download your site link, 4: days the files are kept. */
							_n(
								"Hello %1\$s,\n\nThe download of your site %2\$s is ready: three files, the code, the media and the database.\n\nOpen it here (you will be asked to log in):\n%3\$s\n\nThe files stay available for %4\$d day, then they are deleted. You can prepare a fresh copy whenever you want.\n\nMody Cloud",
								"Hello %1\$s,\n\nThe download of your site %2\$s is ready: three files, the code, the media and the database.\n\nOpen it here (you will be asked to log in):\n%3\$s\n\nThe files stay available for %4\$d days, then they are deleted. You can prepare a fresh copy whenever you want.\n\nMody Cloud",
								$days,
								'mody_cloud'
							),
							$user->display_name,
							$title,
							DashboardPage::url( 'export' ),
							$days
						),
					);
				}
			);
			return (bool) wp_mail( $user->user_email, $message['subject'], $message['body'] );
		} finally {
			self::leave( $switched );
		}
	}

	/**
	 * Builds a message in a user's language: the one in their profile, which
	 * is not the language of the request (whoever asked, or the site's, in the
	 * builder). The language is restored before this returns, so sending
	 * happens in the original one.
	 *
	 * @param int      $user_id The recipient.
	 * @param callable $build   Returns the message (every translated string is looked up inside it).
	 * @return array{subject: string, body: string}
	 */
	private static function localized( int $user_id, callable $build ): array {
		$switched = switch_to_user_locale( $user_id );
		try {
			return $build();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * The site's title, or its address when it has none.
	 */
	private static function title(): string {
		$title = trim( wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) );
		return '' !== $title ? $title : (string) preg_replace( '#^https?://#', '', untrailingslashit( home_url( '/' ) ) );
	}

	/**
	 * Works in the job's site: the builder is already there, the request that
	 * asked for it is too, but the emails do not rely on it.
	 *
	 * @param int $site_id Site.
	 * @return bool Whether it switched.
	 */
	private static function enter( int $site_id ): bool {
		if ( get_current_blog_id() === $site_id ) {
			return false;
		}
		switch_to_blog( $site_id );
		return true;
	}

	/**
	 * Back to the site this was called from.
	 *
	 * @param bool $switched What enter() returned.
	 */
	private static function leave( bool $switched ): void {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}
