<?php
/**
 * 1 GB of space per site (membership-tiers.md), with WordPress's own upload
 * quota.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Enforced since 2026-11-11 (tickets/mody-dashboard.md, decision 8); 3 GB
 * until the maintainer made it 1 GB for every site on 2026-11-13:
 *
 * - **Every new site** gets `blog_upload_space` = 1024 (MB) when it is
 *   created (`wp_initialize_site`), whatever creates it: the signup, the
 *   wizard, WP-CLI.
 * - **The network** (once per VERSION, install()): the same 1024 as the
 *   default, the check switched on (`upload_space_check_disabled` = 0), and
 *   WordPress's per-file cap (`fileupload_maxk`, 1.5 MB by default, enforced
 *   only with the check) raised to PHP's 1 GB (config/php/uploads.ini).
 * - **Sites made before** (version 2): those with the old 3072 get 1024. A
 *   site already over it keeps its files; it uploads again once under 1 GB.
 *   A size a super admin set by hand (any other value) stays.
 * - **The main site** is the platform's own: no limit (space_allowed()).
 *
 * WordPress counts the site's uploads folder only (get_space_used()), not
 * its database rows.
 */
final class Storage {

	/** Each site's space, in MB. */
	public const SITE_MB = 1024;

	/** The space every site had before 2026-11-13, in MB. */
	private const OLD_SITE_MB = 3072;

	/** The largest single file, in KB: PHP's `upload_max_filesize` (1024M). */
	public const FILE_KB = 1048576;

	/** The main site's space, in MB: in effect no limit. */
	private const MAIN_SITE_MB = 10485760;

	/** Bump to apply the network settings again. */
	private const VERSION = '2'; // 2: 1 GB, older sites too (2026-11-13).

	/** Network option that records the applied version. */
	private const VERSION_OPTION = 'mody_storage_version';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'install' ), 1 );
		add_action( 'wp_initialize_site', array( self::class, 'new_site' ), 200 );
		add_filter( 'get_space_allowed', array( self::class, 'space_allowed' ) );
	}

	/**
	 * `init`: the network's quota settings, once. Cheap otherwise: one network
	 * option read, which the object cache serves.
	 */
	public static function install(): void {
		if ( ! is_multisite() || self::VERSION === get_site_option( self::VERSION_OPTION ) ) {
			return;
		}
		update_site_option( 'blog_upload_space', self::SITE_MB );
		update_site_option( 'upload_space_check_disabled', 0 );
		update_site_option( 'fileupload_maxk', self::FILE_KB );
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			if ( self::OLD_SITE_MB === (int) get_blog_option( (int) $site_id, 'blog_upload_space' ) ) {
				update_blog_option( (int) $site_id, 'blog_upload_space', self::SITE_MB );
			}
		}
		update_site_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * `wp_initialize_site`, after core filled the new site's tables: its 1 GB.
	 *
	 * @param \WP_Site $site The new site.
	 */
	public static function new_site( \WP_Site $site ): void {
		update_blog_option( (int) $site->blog_id, 'blog_upload_space', self::SITE_MB );
	}

	/**
	 * `get_space_allowed`: no limit on the main site.
	 *
	 * @param int|float $allowed The site's space, in MB.
	 * @return int|float
	 */
	public static function space_allowed( $allowed ) {
		return is_main_site() ? self::MAIN_SITE_MB : $allowed;
	}

	/**
	 * The current site's space: used and allowed, in bytes, and whether a
	 * limit applies.
	 *
	 * @return array{used: int, allowed: int, limited: bool}
	 */
	public static function usage(): array {
		return array(
			'used'    => (int) round( get_space_used() * MB_IN_BYTES ),
			'allowed' => (int) round( get_space_allowed() * MB_IN_BYTES ),
			'limited' => ! is_main_site() && ! get_site_option( 'upload_space_check_disabled' ),
		);
	}
}
