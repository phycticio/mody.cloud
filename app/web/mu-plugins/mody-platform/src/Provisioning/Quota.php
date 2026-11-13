<?php
/**
 * How many sites a member may have (mvp-spec section 4).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Provisioning;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The site quota, checked by the pipeline's `quota` step.
 *
 * A member's limit is their plan's, through the `mody_site_limit` filter
 * (Members::site_limit(): one site on the launch offer, ten when paying,
 * 2026-11-13). Users without a member row keep DEFAULT_LIMIT.
 *
 * A member's sites are the ones they OWN, not the ones where they hold the
 * administrator role (security pass, 2026-11-12): a role can be given, taken
 * away or accepted from an invitation, so counting roles let one member give
 * a site to a second account and open another, and let a stranger fill
 * someone else's plan. The owner is recorded in the site's meta
 * (OWNER_META) when the platform creates the site (record_owner()); older
 * sites are filled in from the job that made them, or else from their first
 * administrator (owner(), backfill()).
 */
final class Quota {

	/** The limit of users without a member row (they were all on the old trial's 3 until M1). */
	public const DEFAULT_LIMIT = 3;

	/** Site meta: the ID of the user who owns the site (the member it was created for). */
	public const OWNER_META = 'mody_owner';

	/** Network option: when the owners of the older sites were filled in (once). */
	public const BACKFILL_OPTION = 'mody_owner_backfill';

	/**
	 * The number of sites a user may have.
	 *
	 * @param int $user_id User ID.
	 */
	public static function limit( int $user_id ): int {
		/**
		 * Filters a user's site limit.
		 *
		 * @param int $limit   Sites the user may have.
		 * @param int $user_id User ID.
		 */
		return (int) apply_filters( 'mody_site_limit', self::DEFAULT_LIMIT, $user_id );
	}

	/**
	 * The sites a user uses: the network sites they own (not the main site,
	 * nor archived, spammed or deleted ones), plus the site creations still
	 * on the way.
	 *
	 * @param int $user_id     User ID.
	 * @param int $exclude_job A job not to count (the one being checked).
	 */
	public static function used( int $user_id, int $exclude_job = 0 ): int {
		return count( self::owned_sites( $user_id ) ) + Jobs::pending_without_site( $user_id, $exclude_job );
	}

	/**
	 * The IDs of the sites a user owns that count: not the main site, nor
	 * archived, spammed or deleted ones.
	 *
	 * Sites with no recorded owner yet, among the ones the user has a role
	 * on, are filled in first (owner()), so a site made before owners were
	 * recorded still counts for the member who made it.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public static function owned_sites( int $user_id ): array {
		if ( $user_id < 1 ) {
			return array();
		}
		foreach ( get_blogs_of_user( $user_id ) as $site ) {
			$site_id = (int) $site->userblog_id;
			if ( ! is_main_site( $site_id ) && '' === (string) get_site_meta( $site_id, self::OWNER_META, true ) ) {
				self::owner( $site_id );
			}
		}
		return array_map(
			'intval',
			get_sites(
				array(
					'network_id'   => get_current_network_id(),
					'site__not_in' => array( get_main_site_id() ),
					'archived'     => 0,
					'spam'         => 0,
					'deleted'      => 0,
					'meta_key'     => self::OWNER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small table; one lookup.
					'meta_value'   => (string) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'       => 'ids',
					'number'       => '',
				)
			)
		);
	}

	/**
	 * Records who owns a site. Called by the pipeline when it creates one,
	 * and by `wp_initialize_site` for sites made elsewhere (the Network Admin's
	 * Add Site), where the site's first user is its owner.
	 *
	 * @param int $site_id Site ID.
	 * @param int $user_id The owner.
	 */
	public static function record_owner( int $site_id, int $user_id ): void {
		if ( $site_id < 1 || $user_id < 1 || is_main_site( $site_id ) ) {
			return;
		}
		update_site_meta( $site_id, self::OWNER_META, $user_id );
		wp_cache_set_sites_last_changed(); // get_sites() caches its answers; the meta changes who owns what.
	}

	/**
	 * Hooked to `wp_initialize_site`: the owner of a site created without the
	 * pipeline is the user it was created for.
	 *
	 * @param \WP_Site             $site Site being initialized.
	 * @param array<string, mixed> $args Its arguments (`user_id` is the site's administrator).
	 */
	public static function on_initialize_site( $site, $args = array() ): void {
		$user_id = is_array( $args ) ? (int) ( $args['user_id'] ?? 0 ) : 0;
		if ( $site instanceof \WP_Site && $user_id > 0 && '' === (string) get_site_meta( (int) $site->blog_id, self::OWNER_META, true ) ) {
			self::record_owner( (int) $site->blog_id, $user_id );
		}
	}

	/**
	 * The owner of a site: the recorded one, or, for a site made before
	 * owners were recorded, the user of the provisioning job that made it, or
	 * else its first administrator (lowest ID, a super admin only when no one
	 * else is). What is found is recorded, so this runs once per site.
	 *
	 * @param int $site_id Site ID.
	 * @return int The user's ID; 0 for the main site, or when no one can be found.
	 */
	public static function owner( int $site_id ): int {
		if ( $site_id < 1 || is_main_site( $site_id ) ) {
			return 0;
		}
		$owner = (int) get_site_meta( $site_id, self::OWNER_META, true );
		if ( $owner < 1 ) {
			$owner = self::guess_owner( $site_id );
			self::record_owner( $site_id, $owner );
		}
		return $owner;
	}

	/**
	 * Hooked to `admin_init`: fills in the owner of every site that has none,
	 * once (BACKFILL_OPTION). Cheap after that: one option read.
	 */
	public static function backfill(): void {
		if ( ! is_multisite() || get_site_option( self::BACKFILL_OPTION ) ) {
			return;
		}
		$ids = get_sites(
			array(
				'network_id'   => get_current_network_id(),
				'site__not_in' => array( get_main_site_id() ),
				'fields'       => 'ids',
				'number'       => '',
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Once, on the sites without an owner.
					array(
						'key'     => self::OWNER_META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		foreach ( $ids as $site_id ) {
			self::owner( (int) $site_id );
		}
		update_site_option( self::BACKFILL_OPTION, time() );
	}

	/**
	 * Who owned a site that has no recorded owner: its provisioning job's
	 * user, else its first administrator.
	 *
	 * @param int $site_id Site ID.
	 */
	private static function guess_owner( int $site_id ): int {
		$job_id = (int) get_blog_option( $site_id, 'mody_provisioning_job' );
		$job    = $job_id ? Jobs::get( $job_id ) : null;
		if ( $job && $job['user_id'] > 0 && get_userdata( $job['user_id'] ) ) {
			return $job['user_id'];
		}
		$admins = array_map(
			'intval',
			get_users(
				array(
					'blog_id' => $site_id,
					'role'    => 'administrator',
					'orderby' => 'ID',
					'order'   => 'ASC',
					'fields'  => 'ID',
				)
			)
		);
		foreach ( $admins as $admin ) {
			if ( ! is_super_admin( $admin ) ) {
				return $admin;
			}
		}
		return $admins[0] ?? 0;
	}

	/**
	 * Whether a user may create another site (`can_create_site()` in the spec).
	 *
	 * @param int $user_id     User ID.
	 * @param int $exclude_job A job not to count (the one being checked).
	 * @return true|WP_Error `mody_site_limit_reached` when every site is used.
	 */
	public static function can_create_site( int $user_id, int $exclude_job = 0 ): bool|WP_Error {
		/**
		 * Filters whether a user may create a site at all, before the count.
		 * Members\Members refuses accounts whose card is not verified.
		 *
		 * @param true|WP_Error $allowed True, or the refusal.
		 * @param int           $user_id User ID.
		 */
		$allowed = apply_filters( 'mody_can_create_site', true, $user_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$limit = self::limit( $user_id );
		if ( self::used( $user_id, $exclude_job ) < $limit ) {
			return true;
		}
		return new WP_Error(
			'mody_site_limit_reached',
			/* translators: %d: the number of sites the plan includes. */
			sprintf( _n( 'Your plan includes %d site, and it is in use.', 'Your plan includes %d sites, and all of them are in use.', $limit, 'mody_cloud' ), $limit ),
			array( 'limit' => $limit )
		);
	}
}
