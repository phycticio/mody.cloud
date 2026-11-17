<?php
/**
 * Mody's own plugins may use the Infomaniak connector.
 *
 * @package Mody\Agent
 */

namespace Mody\Agent\AI;

defined( 'ABSPATH' ) || exit;

/**
 * The `ai` plugin lets a plugin reach an AI connector only once a site
 * administrator approved it, and names a MU plugin by its folder. The plugin
 * that registers a provider counts as infrastructure, so while every Mody
 * feature lived in mody-platform its calls passed. Since the platform became
 * independent plugins (2026-11-17, tickets/independent-mody-plugins.md), the
 * wizard, provisioning and the analyst call the model through this plugin
 * from their own folders: Mody's own MU plugins (`mody-*`) are approved for
 * Infomaniak here, on every site. On member sites Mody Locks pins the same.
 */
final class ConnectorApprovals {

	/** The ai plugin's option: caller => connector => approved. */
	public const OPTION = 'wpai_connector_approvals';

	/** The connector Mody's provider registers (InfomaniakProvider). */
	public const CONNECTOR = 'infomaniak';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'option_' . self::OPTION, array( self::class, 'approve' ) );
		add_filter( 'default_option_' . self::OPTION, array( self::class, 'approve' ) );
	}

	/**
	 * `option_wpai_connector_approvals`: Mody's MU plugins added, approved for
	 * Infomaniak; what the site stored stays as it is.
	 *
	 * @param mixed $approvals The stored approvals.
	 * @return mixed
	 */
	public static function approve( $approvals ) {
		$approvals = is_array( $approvals ) ? $approvals : array();
		foreach ( array_keys( get_mu_plugins() ) as $file ) {
			if ( str_starts_with( $file, 'mody-' ) ) {
				$caller                                  = basename( $file, '.php' );
				$approvals[ $caller ]                    = is_array( $approvals[ $caller ] ?? null ) ? $approvals[ $caller ] : array();
				$approvals[ $caller ][ self::CONNECTOR ] = true;
			}
		}
		return $approvals;
	}
}
