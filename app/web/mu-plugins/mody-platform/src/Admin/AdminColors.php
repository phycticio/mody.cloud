<?php
/**
 * wp-admin colors: Ocean by default, and the agent chat in the user's own
 * color scheme.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Decided by the maintainer on 2026-11-08 (tickets/admin-colors.md):
 *
 * - **Ocean is every user's admin color scheme by default.** New users get
 *   it, users without a stored scheme get it, and users on WordPress's own
 *   default (Modern) were moved to it once. Anyone may still choose another
 *   scheme in their profile, Modern included.
 * - **The agent chat follows the user's scheme.** Buttons and borders use the
 *   scheme's accent (`--wp-admin-theme-color`, set by core). The user's own
 *   messages use the scheme's second color, the second swatch of the
 *   palette in the profile's color scheme picker (Ocean: #39535a), through
 *   `--mody-agent-user-message`.
 */
final class AdminColors {

	/** The scheme users get unless they choose another. */
	public const DEFAULT_SCHEME = 'ocean';

	/** WordPress's own default scheme (7.0 and later). */
	public const CORE_DEFAULT = 'modern';

	/** The CSS variable the chat's user messages read (assets/agent-chat.css). */
	public const USER_MESSAGE_VAR = '--mody-agent-user-message';

	/** Bump to run the one-time move to Ocean again. */
	private const VERSION = '1';

	/** Network option recording that the move ran. */
	private const VERSION_OPTION = 'mody_admin_colors_version';

	/**
	 * Filters `insert_user_meta`: a new user gets Ocean unless the code that
	 * creates them chose a scheme.
	 *
	 * @param array<string, mixed> $meta     The user's meta, as core will save it.
	 * @param \WP_User             $user     The user.
	 * @param bool                 $update   Whether an existing user is being updated.
	 * @param array<string, mixed> $userdata The data passed to wp_insert_user().
	 * @return array<string, mixed>
	 */
	public static function new_user_scheme( $meta, $user, $update, $userdata ) {
		if ( ! $update && empty( $userdata['admin_color'] ) ) {
			$meta['admin_color'] = self::DEFAULT_SCHEME;
		}
		return $meta;
	}

	/**
	 * Filters `get_user_option_admin_color`: Ocean for a user with no scheme stored.
	 *
	 * @param mixed $value The stored scheme, or false.
	 * @return mixed
	 */
	public static function default_scheme( $value ) {
		return empty( $value ) ? self::DEFAULT_SCHEME : $value;
	}

	/**
	 * Hooked to `init`: once per network, moves the users on WordPress's
	 * default scheme to Ocean. Users who chose another scheme keep it.
	 */
	public static function migrate(): void {
		if ( self::VERSION === get_site_option( self::VERSION_OPTION ) ) {
			return;
		}
		global $wpdb;
		// The user meta table is network-wide: one query covers every site's users.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time move; the updates go through the meta API.
		$ids =$wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'admin_color' AND meta_value IN (%s, '')", self::CORE_DEFAULT ) );
		self::move_users( array_map( 'intval', $ids ) );
		update_site_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Gives Ocean to the listed users who are on WordPress's default scheme.
	 *
	 * @param int[] $user_ids Users.
	 * @return int How many were moved.
	 */
	public static function move_users( array $user_ids ): int {
		$moved = 0;
		foreach ( $user_ids as $user_id ) {
			$scheme = get_user_meta( $user_id, 'admin_color', true );
			if ( '' === $scheme || self::CORE_DEFAULT === $scheme ) {
				update_user_meta( $user_id, 'admin_color', self::DEFAULT_SCHEME );
				++$moved;
			}
		}
		return $moved;
	}

	/**
	 * The second color of a user's scheme palette, or '' when the scheme has
	 * none. An unknown scheme counts as Ocean, as core falls back to the
	 * default too.
	 *
	 * @param int $user_id User; 0 for the current one.
	 */
	public static function second_color( int $user_id = 0 ): string {
		global $_wp_admin_css_colors;
		if ( empty( $_wp_admin_css_colors ) ) {
			register_admin_color_schemes(); // Core registers them on `admin_init`; REST and WP-CLI never run it.
		}
		$scheme = (string) get_user_option( 'admin_color', $user_id ? $user_id : get_current_user_id() );
		$scheme = isset( $_wp_admin_css_colors[ $scheme ] ) ? $scheme : self::DEFAULT_SCHEME;
		$color  = (string) ( $_wp_admin_css_colors[ $scheme ]->colors[1] ?? '' );
		return preg_match( '/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i', $color ) ? $color : '';
	}

	/**
	 * The chat's colors for the current user, as inline CSS for the
	 * `mody-agent-chat` style. Empty when there is nothing to set: the
	 * stylesheet then falls back to the scheme's accent.
	 */
	public static function agent_chat_css(): string {
		$color = self::second_color();
		return '' === $color ? '' : ':root { ' . self::USER_MESSAGE_VAR . ': ' . $color . '; }';
	}

	/**
	 * Adds the chat's colors to its stylesheet. Called where the chat is enqueued.
	 */
	public static function add_agent_chat_css(): void {
		$css = self::agent_chat_css();
		if ( '' !== $css ) {
			wp_add_inline_style( 'mody-agent-chat', $css );
		}
	}
}
