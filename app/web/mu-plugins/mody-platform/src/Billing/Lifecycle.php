<?php
/**
 * Unpaid and canceled accounts: read-only sites, then deleted (M1-02).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Billing;

use Mody\Platform\Members\Members;
use Mody\Platform\Provisioning\Quota;

defined( 'ABSPATH' ) || exit;

/**
 * mvp-spec §4 and M1-02, built 2026-11-13. An account is **locked** while
 * its status is `past_due` (a failed charge, or no card when the free
 * months ended) or `canceled` (a withdrawal, or a cancellation that reached
 * its date). Its `past_due_since` starts the count.
 *
 * - **Read-only:** on every site the account owns (`mody_owner`), nobody
 *   but a super admin may change content or styles: the capabilities in
 *   DENIED are taken away, so the editors, the Site Editor, REST and Mody's
 *   abilities refuse (403). The front end, reading, Plan & billing and
 *   Download your site keep working, and Mody does nothing
 *   (Agent\Budget::check()).
 * - **A banner** on every wp-admin screen of those sites and in the editors.
 * - **Lifted at once** when a payment goes through: the status is `active`
 *   again, and nothing else is stored.
 * - **Day 53:** a last warning by email, with the link to download the site.
 * - **Day 60:** the sites go to the network's trash (WordPress's `deleted`
 *   flag: a super admin can still restore or delete them for good from
 *   Network Admin → Sites), and the account is `expired`.
 */
final class Lifecycle {

	/** The statuses that lock an account's sites. */
	public const LOCKED = array( 'past_due', 'canceled' );

	/** The day of the last warning, and of the deletion. */
	public const WARN_DAY   = 53;
	public const DELETE_DAY = 60;

	/** User meta: when the last warning was sent. */
	public const WARNED_META = 'mody_billing_final_warning';

	/**
	 * The capabilities a locked site's users lose: everything that changes
	 * content, media, comments, menus or styles.
	 */
	public const DENIED = array(
		'edit_posts',
		'edit_others_posts',
		'edit_published_posts',
		'edit_private_posts',
		'publish_posts',
		'delete_posts',
		'delete_others_posts',
		'delete_published_posts',
		'delete_private_posts',
		'edit_pages',
		'edit_others_pages',
		'edit_published_pages',
		'edit_private_pages',
		'publish_pages',
		'delete_pages',
		'delete_others_pages',
		'delete_published_pages',
		'delete_private_pages',
		'upload_files',
		'edit_theme_options',
		'customize',
		'manage_categories',
		'moderate_comments',
		'unfiltered_html',
		'edit_css',
	);

	/**
	 * Locks already worked out in this request: site ID => lock or null.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private static array $locks = array();

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'user_has_cap', array( self::class, 'filter_caps' ), 20, 4 );
		add_action( 'admin_notices', array( self::class, 'banner' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_notice' ) );
	}

	/**
	 * A site's lock: its owner's account, why, and when the site is deleted.
	 *
	 * @param int $site_id Site.
	 * @return array{owner: int, status: string, since: int, deletes: int}|null Null when the site is not locked.
	 */
	public static function site_lock( int $site_id ): ?array {
		if ( ! array_key_exists( $site_id, self::$locks ) ) {
			self::$locks[ $site_id ] = null;
			$owner                   = is_main_site( $site_id ) ? 0 : (int) get_site_meta( $site_id, Quota::OWNER_META, true );
			$member                  = $owner ? Members::get( $owner ) : null;
			if ( $member && in_array( $member['status'], self::LOCKED, true ) ) {
				$since                   = ! empty( $member['past_due_since'] ) ? (int) strtotime( $member['past_due_since'] . ' UTC' ) : time();
				self::$locks[ $site_id ] = array(
					'owner'   => $owner,
					'status'  => (string) $member['status'],
					'since'   => $since,
					'deletes' => $since + self::DELETE_DAY * DAY_IN_SECONDS,
				);
			}
		}
		return self::$locks[ $site_id ];
	}

	/**
	 * Forgets the locks worked out in this request (a status changed).
	 */
	public static function forget(): void {
		self::$locks = array();
	}

	/**
	 * `user_has_cap`: on a locked site, the DENIED capabilities are gone,
	 * for everyone but super admins.
	 *
	 * @param array<string, bool> $allcaps The user's capabilities.
	 * @param string[]            $caps    The primitive capabilities checked.
	 * @param array<int, mixed>   $args    The check.
	 * @param \WP_User            $user    The user.
	 * @return array<string, bool>
	 */
	public static function filter_caps( array $allcaps, array $caps, array $args, $user ): array {
		if ( ! array_intersect( $caps, self::DENIED ) || ! $user instanceof \WP_User || ! $user->ID || is_super_admin( $user->ID ) ) {
			return $allcaps;
		}
		if ( self::site_lock( get_current_blog_id() ) ) {
			foreach ( self::DENIED as $cap ) {
				$allcaps[ $cap ] = false;
			}
		}
		return $allcaps;
	}

	/**
	 * What a locked site's people read, with what the owner can do.
	 *
	 * @param array{owner: int, status: string, since: int, deletes: int} $lock The lock.
	 */
	public static function message( array $lock ): string {
		$date  = wp_date( (string) get_option( 'date_format' ), $lock['deletes'] );
		$owner = get_current_user_id() === $lock['owner'];
		if ( 'canceled' === $lock['status'] ) {
			return $owner
				/* translators: %s: a date. */
				? sprintf( __( 'Your plan has ended, so this site is read-only. It will be deleted on %s: download it before then, or add a card on Plan & billing to keep it.', 'mody_cloud' ), $date )
				/* translators: %s: a date. */
				: sprintf( __( 'This site is read-only: its owner\'s plan has ended. It will be deleted on %s.', 'mody_cloud' ), $date );
		}
		return $owner
			/* translators: %s: a date. */
			? sprintf( __( 'This site is read-only until a payment goes through. Add or update your card on Plan & billing, or download your site: without a payment it will be deleted on %s.', 'mody_cloud' ), $date )
			/* translators: %s: a date. */
			: sprintf( __( 'This site is read-only until its owner\'s payment goes through. Without one, it will be deleted on %s.', 'mody_cloud' ), $date );
	}

	/**
	 * `admin_notices`: the banner, on every wp-admin screen of a locked site.
	 */
	public static function banner(): void {
		$lock = self::site_lock( get_current_blog_id() );
		if ( ! $lock || ! is_user_logged_in() ) {
			return;
		}
		$link = get_current_user_id() === $lock['owner']
			? sprintf( ' <a href="%s">%s</a>', esc_url( Billing::plan_url( $lock['owner'] ) ), esc_html__( 'Plan & billing', 'mody_cloud' ) )
			: '';
		printf( '<div class="notice notice-error mody-billing-lock"><p>%s%s</p></div>', esc_html( self::message( $lock ) ), $link ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	/**
	 * `enqueue_block_editor_assets`: the same words in the editors, where
	 * wp-admin's notices do not show.
	 */
	public static function editor_notice(): void {
		$lock = self::site_lock( get_current_blog_id() );
		if ( ! $lock ) {
			return;
		}
		wp_add_inline_script(
			'wp-notices',
			sprintf(
				'wp.domReady(function(){wp.data.dispatch("core/notices").createErrorNotice(%s,{id:"mody-billing-lock",isDismissible:false});});',
				wp_json_encode( self::message( $lock ) )
			),
			'after'
		);
		wp_enqueue_script( 'wp-notices' );
		wp_enqueue_script( 'wp-dom-ready' );
	}

	/**
	 * The sites an account owns.
	 *
	 * @param int $user_id The member.
	 * @return int[]
	 */
	public static function owned_sites( int $user_id ): array {
		return array_map(
			'intval',
			get_sites(
				array(
					'fields'     => 'ids',
					'number'     => 0,
					'meta_key'   => Quota::OWNER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The daily run, a few accounts.
					'meta_value' => (string) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Same.
				)
			)
		);
	}

	/**
	 * The daily run's part: the day-53 warning, the day-60 deletion.
	 *
	 * @return array{warned: int, deleted: int}
	 */
	public static function sweep(): array {
		global $wpdb;
		$done    = array(
			'warned'  => 0,
			'deleted' => 0,
		);
		$table   = Members::table();
		$locked  = "'" . implode( "','", self::LOCKED ) . "'";
		$members = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, past_due_since FROM {$table} WHERE status IN ({$locked}) AND past_due_since IS NOT NULL AND past_due_since <= %s", gmdate( 'Y-m-d H:i:s', time() - self::WARN_DAY * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; constants.
		foreach ( (array) $members as $member ) {
			$user  = (int) $member['user_id'];
			$since = (int) strtotime( $member['past_due_since'] . ' UTC' );
			if ( time() >= $since + self::DELETE_DAY * DAY_IN_SECONDS ) {
				self::expire( $user );
				++$done['deleted'];
				continue;
			}
			if ( (int) get_user_meta( $user, self::WARNED_META, true ) < $since ) {
				self::mail_warning( $user, $since + self::DELETE_DAY * DAY_IN_SECONDS );
				update_user_meta( $user, self::WARNED_META, time() );
				++$done['warned'];
			}
		}
		return $done;
	}

	/**
	 * Day 60: the account's sites to the network's trash, the account expired.
	 *
	 * @param int $user_id The member.
	 */
	private static function expire( int $user_id ): void {
		$sites = self::owned_sites( $user_id );
		foreach ( $sites as $site_id ) {
			if ( ! is_main_site( $site_id ) ) {
				update_blog_status( $site_id, 'deleted', '1' );
			}
		}
		Members::update(
			$user_id,
			array(
				'status'         => 'expired',
				'billing_due_at' => null,
				'cancel_at'      => null,
			)
		);
		self::forget();
		$user = get_userdata( $user_id );
		if ( $user ) {
			wp_mail(
				$user->user_email,
				__( 'Mody Cloud: your sites are deleted', 'mody_cloud' ),
				/* translators: %s: the member's name. */
				sprintf( __( 'Hello %s,', 'mody_cloud' ), $user->display_name ) . "\n\n" . __( 'Your sites have been deleted, 60 days after your plan ended or your payment failed, as we told you. Thank you for having been with us.', 'mody_cloud' ) . "\n\nMody Cloud"
			);
		}
	}

	/**
	 * Day 53: the last warning, with the link to download.
	 *
	 * @param int $user_id The member.
	 * @param int $deletes When the sites are deleted.
	 */
	private static function mail_warning( int $user_id, int $deletes ): void {
		$user  = get_userdata( $user_id );
		$sites = self::owned_sites( $user_id );
		if ( ! $user ) {
			return;
		}
		$link = $sites ? get_admin_url( $sites[0], 'admin.php?page=mody-dashboard&section=export' ) : Billing::plan_url( $user_id );
		wp_mail(
			$user->user_email,
			__( 'Mody Cloud: your sites will be deleted in a week', 'mody_cloud' ),
			/* translators: %s: the member's name. */
			sprintf( __( 'Hello %s,', 'mody_cloud' ), $user->display_name ) . "\n\n" . sprintf(
				/* translators: 1: a date, 2: the download page, 3: Plan & billing. */
				__( "Your sites will be deleted on %1\$s. To keep a copy, download them now:\n%2\$s\n\nTo keep them online instead, add a card on Plan & billing:\n%3\$s", 'mody_cloud' ),
				wp_date( (string) get_option( 'date_format' ), $deletes ),
				$link,
				Billing::plan_url( $user_id )
			) . "\n\nMody Cloud"
		);
	}
}
