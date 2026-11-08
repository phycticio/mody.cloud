<?php
/**
 * WordPress and plugin updates only through the repository
 * (tickets/updates-through-composer-only.md).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Gating;

defined( 'ABSPATH' ) || exit;

/**
 * Nobody updates WordPress, a plugin, a theme or a translation from wp-admin.
 *
 * Decided by the maintainer on 2026-11-08. Core changes with `./bin/version`
 * and plugins in `app/composer.json`. Both reach production through
 * `./bin/deploy`.
 *
 * - **Capabilities:** the four update capabilities are refused to every user
 *   on every site and environment, super admins included. That hides the
 *   Updates screen, the update notices and rows, and the auto-update
 *   controls. It refuses the update requests (AJAX, the upgrader screens),
 *   and with them the update check that the Updates screen runs itself.
 * - **Automatic checks:** core's own triggers of the checks to
 *   api.wordpress.org are removed: admin page loads, the Plugins, Themes and
 *   Updates screens, and the cron events, which are also unscheduled. Nobody
 *   could act on the result, so page loads (members opening their Plugins
 *   screen since M4-02) never call WordPress.org for updates.
 * - **Left alone:** code that calls core's check functions directly. That is
 *   Wordfence's scans and activity report, which use the result to report
 *   outdated and vulnerable plugins; Site Health → Info; and WP-CLI commands
 *   run by hand (`wp core check-update`, `wp plugin list`).
 */
final class Updates {

	/** Capabilities nobody has. */
	public const CAPS = array( 'update_core', 'update_plugins', 'update_themes', 'update_languages' );

	/** Core's automatic triggers of the update checks (wp-includes/update.php), as hook and callback. */
	public const TRIGGERS = array(
		array( 'admin_init', '_maybe_update_core' ),
		array( 'admin_init', '_maybe_update_plugins' ),
		array( 'admin_init', '_maybe_update_themes' ),
		array( 'load-plugins.php', 'wp_update_plugins' ),
		array( 'load-themes.php', 'wp_update_themes' ),
		array( 'load-update.php', 'wp_update_plugins' ),
		array( 'load-update.php', 'wp_update_themes' ),
		array( 'load-update-core.php', 'wp_update_plugins' ),
		array( 'load-update-core.php', 'wp_update_themes' ),
		array( 'wp_version_check', 'wp_version_check' ),
		array( 'wp_update_plugins', 'wp_update_plugins' ),
		array( 'wp_update_themes', 'wp_update_themes' ),
		array( 'wp_maybe_auto_update', 'wp_maybe_auto_update' ),
		array( 'init', 'wp_schedule_update_checks' ),
	);

	/** The cron events core schedules on every site for the checks and automatic updates. */
	public const EVENTS = array( 'wp_version_check', 'wp_update_plugins', 'wp_update_themes', 'wp_maybe_auto_update' );

	/**
	 * Removes core's triggers. Called when the MU plugin loads: core adds them
	 * in wp-includes/update.php, which wp-settings.php loads before MU plugins.
	 */
	public static function remove_triggers(): void {
		foreach ( self::TRIGGERS as list( $hook, $callback ) ) {
			remove_action( $hook, $callback );
		}
	}

	/**
	 * `map_meta_cap`: the update capabilities are refused to everyone.
	 * `do_not_allow` denies super admins too.
	 *
	 * @param string[] $caps The primitive capabilities the user needs.
	 * @param string   $cap  The capability checked.
	 * @return string[]
	 */
	public static function map_meta_cap( array $caps, string $cap ): array {
		return in_array( $cap, self::CAPS, true ) ? array( 'do_not_allow' ) : $caps;
	}

	/**
	 * `init`: clears the events already scheduled on this site. Without their
	 * callbacks they would do nothing, but recurring events reschedule
	 * themselves forever. Cheap when there is nothing to clear: the cron
	 * option is autoloaded.
	 */
	public static function unschedule(): void {
		if ( wp_installing() ) {
			return;
		}
		foreach ( self::EVENTS as $event ) {
			if ( wp_next_scheduled( $event ) ) {
				wp_clear_scheduled_hook( $event );
			}
		}
	}
}
