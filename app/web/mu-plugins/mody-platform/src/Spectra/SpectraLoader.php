<?php
/**
 * Spectra as a must-use plugin (ticket M3-11).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Spectra;

defined( 'ABSPATH' ) || exit;

/**
 * Spectra is the platform's block library, loaded on every site as a MU
 * plugin (decided 2026-11-08): Composer installs it into
 * `mu-plugins/spectra-blocks/`, and `mu-plugins/spectra-blocks.php` loads
 * it after this plugin, so SpectraGuard's hooks are in place first.
 *
 * Until 2026-11-08 it was network-activated from `plugins/spectra-blocks/`.
 * WordPress must never load that copy beside the MU one (the same classes
 * twice), so the old activation is hidden from what WordPress reads, then
 * removed once from the stored option.
 */
final class SpectraLoader {

	/** The plugin file Spectra had as a network-active plugin. */
	public const FILE = 'spectra-blocks/spectra-blocks.php';

	/** Network option: the old network activation was removed. */
	private const FORGOTTEN_OPTION = 'mody_spectra_mu_plugin';

	/**
	 * Called when the MU plugin loads, before any plugin.
	 */
	public static function boot(): void {
		add_filter( 'site_option_active_sitewide_plugins', array( self::class, 'not_network_active' ) );
		add_filter( 'option_active_plugins', array( self::class, 'not_site_active' ) );
		add_action( 'init', array( self::class, 'forget_activation' ), 1 );
	}

	/**
	 * Filters the network's active plugins: never the old Spectra copy.
	 *
	 * @param mixed $plugins Plugin file => activation time.
	 * @return mixed
	 */
	public static function not_network_active( $plugins ) {
		if ( is_array( $plugins ) ) {
			unset( $plugins[ self::FILE ] );
		}
		return $plugins;
	}

	/**
	 * Filters a site's active plugins: never the old Spectra copy.
	 *
	 * @param mixed $plugins Plugin files.
	 * @return mixed
	 */
	public static function not_site_active( $plugins ) {
		return is_array( $plugins ) ? array_values( array_diff( $plugins, array( self::FILE ) ) ) : $plugins;
	}

	/**
	 * Once per network: removes the old network activation from the stored
	 * option, so the Network Admin's Plugins screen agrees.
	 */
	public static function forget_activation(): void {
		if ( '1' === (string) get_site_option( self::FORGOTTEN_OPTION ) ) {
			return;
		}
		remove_filter( 'site_option_active_sitewide_plugins', array( self::class, 'not_network_active' ) );
		$active = get_site_option( 'active_sitewide_plugins', array() );
		if ( is_array( $active ) && isset( $active[ self::FILE ] ) ) {
			unset( $active[ self::FILE ] );
			update_site_option( 'active_sitewide_plugins', $active );
		}
		add_filter( 'site_option_active_sitewide_plugins', array( self::class, 'not_network_active' ) );
		update_site_option( self::FORGOTTEN_OPTION, '1' );
	}

	/**
	 * Whether Spectra runs from mu-plugins (for tests and checks).
	 */
	public static function is_must_use(): bool {
		return defined( 'SPECTRA_BLOCKS_FILE' ) && str_starts_with( wp_normalize_path( (string) SPECTRA_BLOCKS_FILE ), trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ) );
	}
}
