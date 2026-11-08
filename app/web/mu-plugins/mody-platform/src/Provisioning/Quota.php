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
 * Until M1 adds `mody_members`, every user gets the trial's 3 sites (decided
 * 2026-11-06). M1 replaces limit() with the plan's limit (3, 10 or 50),
 * through the `mody_site_limit` filter or here.
 */
final class Quota {

	/** The trial and Collaborator limit, used for everyone until M1. */
	public const DEFAULT_LIMIT = 3;

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
	 * The sites a user uses: the network sites they administer (not the
	 * main site, nor archived, spammed or deleted ones), plus the site
	 * creations still on the way.
	 *
	 * @param int $user_id     User ID.
	 * @param int $exclude_job A job not to count (the one being checked).
	 */
	public static function used( int $user_id, int $exclude_job = 0 ): int {
		global $wpdb;
		$sites = 0;
		foreach ( get_blogs_of_user( $user_id ) as $site ) {
			$caps = get_user_meta( $user_id, $wpdb->get_blog_prefix( (int) $site->userblog_id ) . 'capabilities', true );
			if ( ! is_main_site( (int) $site->userblog_id ) && is_array( $caps ) && ! empty( $caps['administrator'] ) ) {
				++$sites;
			}
		}
		return $sites + Jobs::pending_without_site( $user_id, $exclude_job );
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
