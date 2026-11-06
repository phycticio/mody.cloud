<?php
/**
 * Agent settings: Network Admin > Settings > Mody Agent.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Network-wide agent settings, for super admins only (decided 2026-11-06).
 *
 * Today one setting: how many days agent events are kept in
 * `mody_agent_events`. Stored in the network option `mody_agent_settings`.
 */
final class AgentSettings {

	/** Network option holding the settings. */
	public const OPTION = 'mody_agent_settings';

	/** Days events are kept until the page is saved. */
	public const DEFAULT_RETENTION_DAYS = 90;

	/** Shortest retention, in days. */
	public const MIN_RETENTION_DAYS = 1;

	/** Longest retention, in days. */
	public const MAX_RETENTION_DAYS = 3650;

	/** Page slug, and the `edit.php` action the form posts to. */
	public const SLUG = 'mody-agent-settings';

	/** Who may see and change the settings. */
	private const CAPABILITY = 'manage_network_options';

	/**
	 * Days agent events are kept.
	 */
	public static function retention_days(): int {
		$settings = get_site_option( self::OPTION, array() );
		$days     = is_array( $settings ) ? (int) ( $settings['retention_days'] ?? 0 ) : 0;
		return $days >= self::MIN_RETENTION_DAYS ? min( $days, self::MAX_RETENTION_DAYS ) : self::DEFAULT_RETENTION_DAYS;
	}

	/**
	 * Hooked to `network_admin_menu`.
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'settings.php',
			__( 'Mody Agent', 'mody_cloud' ),
			__( 'Mody Agent', 'mody_cloud' ),
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Saves the form (`network_admin_edit_mody-agent-settings`), then purges
	 * at once, so a shorter retention applies immediately.
	 */
	public static function save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'mody_cloud' ), 403 );
		}
		check_admin_referer( self::SLUG );

		$days = isset( $_POST['retention_days'] ) ? absint( wp_unslash( $_POST['retention_days'] ) ) : self::DEFAULT_RETENTION_DAYS;
		$days = max( self::MIN_RETENTION_DAYS, min( self::MAX_RETENTION_DAYS, $days ) );
		update_site_option( self::OPTION, array( 'retention_days' => $days ) );
		$purged = AgentEvents::purge( $days );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SLUG,
					'updated' => 'true',
					'purged'  => $purged,
				),
				network_admin_url( 'settings.php' )
			)
		);
		exit;
	}

	/**
	 * Renders the page.
	 */
	public static function render(): void {
		$stats = AgentEvents::stats();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only, after the redirect from save().
		$updated = isset( $_GET['updated'] );
		$purged  = isset( $_GET['purged'] ) ? absint( $_GET['purged'] ) : 0;
		// phpcs:enable
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mody Agent', 'mody_cloud' ); ?></h1>
			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of events deleted. */
							_n( 'Settings saved. %d old event was deleted.', 'Settings saved. %d old events were deleted.', $purged, 'mody_cloud' ),
							$purged
						)
					);
					?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::SLUG ) ); ?>">
				<?php wp_nonce_field( self::SLUG ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mody-retention-days"><?php esc_html_e( 'Keep agent events for', 'mody_cloud' ); ?></label></th>
						<td>
							<input name="retention_days" id="mody-retention-days" type="number" class="small-text" required
								min="<?php echo (int) self::MIN_RETENTION_DAYS; ?>" max="<?php echo (int) self::MAX_RETENTION_DAYS; ?>"
								value="<?php echo (int) self::retention_days(); ?>" />
							<?php esc_html_e( 'days', 'mody_cloud' ); ?>
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: default number of days. */
										__( 'Every chat request and tool call of the agent, on every site, is recorded with the member\'s message. Older events are deleted once a day. Default: %d days.', 'mody_cloud' ),
										self::DEFAULT_RETENTION_DAYS
									)
								);
								?>
							</p>
							<p class="description">
								<?php
								echo esc_html(
									$stats['count']
										/* translators: 1: number of events, 2: date and time of the oldest one. */
										? sprintf( _n( 'The log holds %1$d event. The oldest is from %2$s.', 'The log holds %1$d events. The oldest is from %2$s.', $stats['count'], 'mody_cloud' ), $stats['count'], (string) $stats['oldest'] )
										: __( 'The log is empty.', 'mody_cloud' )
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
