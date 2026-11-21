<?php
/**
 * Agent settings: Network Admin > Settings > Mody Agent.
 *
 * @package Mody\Agent
 */

namespace Mody\Agent;

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
	 * The page's sections: slug => label. One today; the sidebar shows it
	 * anyway, as every Mody page does (tickets/mody-pages-dashboard-layout.md).
	 *
	 * @return array<string, string>
	 */
	public static function sections(): array {
		return array( 'events' => __( 'Agent events', 'mody_cloud' ) );
	}

	/**
	 * Hooked to `network_admin_menu`.
	 */
	public static function register_menu(): void {
		$hook = add_submenu_page(
			'settings.php',
			__( 'Mody Agent', 'mody_cloud' ),
			__( 'Mody Agent', 'mody_cloud' ),
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'enqueue' ) );
		}
	}

	/**
	 * `load-<page>`: the Mody dashboard's layout (a copy in this plugin).
	 */
	public static function enqueue(): void {
		$root = dirname( __DIR__ );
		wp_enqueue_style( 'mody-agent-admin-page', plugins_url( 'assets/admin-page.css', $root . '/mody-agent.php' ), array(), (string) filemtime( $root . '/assets/admin-page.css' ) );
	}

	/**
	 * A section's address.
	 *
	 * @param string $section A key of sections().
	 */
	public static function url( string $section = 'events' ): string {
		return add_query_arg(
			array(
				'page'    => self::SLUG,
				'section' => $section,
			),
			network_admin_url( 'settings.php' )
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
	 * Renders the page, in the Mody dashboard's layout.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$stats    = AgentEvents::stats();
		$sections = self::sections();
		$section  = 'events';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only, after the redirect from save().
		$updated = isset( $_GET['updated'] );
		$purged  = isset( $_GET['purged'] ) ? absint( $_GET['purged'] ) : 0;
		// phpcs:enable
		?>
		<div class="wrap mody-brand mody-agent-settings">
			<h1><?php esc_html_e( 'Mody Agent', 'mody_cloud' ); ?></h1>
			<div class="mody-brand__layout">
				<nav class="mody-brand__sections" aria-label="<?php esc_attr_e( 'Mody Agent sections', 'mody_cloud' ); ?>">
					<ul>
						<?php foreach ( $sections as $slug => $label ) : ?>
							<li>
								<a href="<?php echo esc_url( self::url( $slug ) ); ?>" class="mody-brand__section-link<?php echo $slug === $section ? ' is-active' : ''; ?>" <?php echo $slug === $section ? 'aria-current="page"' : ''; ?>>
									<span><?php echo esc_html( $label ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</nav>
				<div class="mody-brand__main">
					<section class="mody-brand__card" aria-label="<?php esc_attr_e( 'Mody Agent', 'mody_cloud' ); ?>">
						<div class="mody-brand__card-text">
							<p class="mody-brand__name"><?php esc_html_e( 'Mody Agent', 'mody_cloud' ); ?></p>
							<p class="mody-brand__tagline"><?php esc_html_e( 'Settings for Mody on every site of the network.', 'mody_cloud' ); ?></p>
						</div>
					</section>
					<?php if ( $updated ) : ?>
						<div class="notice notice-success inline mody-brand__notice"><p>
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
					<section class="mody-brand__panel" aria-labelledby="mody-agent-section-title">
						<div class="mody-brand__panel-head">
							<h2 id="mody-agent-section-title"><?php echo esc_html( $sections[ $section ] ); ?></h2>
						</div>
						<div class="mody-brand__panel-body">
							<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::SLUG ) ); ?>" class="mody-brand__form">
								<?php wp_nonce_field( self::SLUG ); ?>
								<div class="mody-brand__field">
									<label for="mody-retention-days"><?php esc_html_e( 'Keep agent events for', 'mody_cloud' ); ?></label>
									<span>
										<input name="retention_days" id="mody-retention-days" type="number" class="small-text" required
											min="<?php echo (int) self::MIN_RETENTION_DAYS; ?>" max="<?php echo (int) self::MAX_RETENTION_DAYS; ?>"
											value="<?php echo (int) self::retention_days(); ?>" />
										<?php esc_html_e( 'days', 'mody_cloud' ); ?>
									</span>
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
								</div>
								<?php submit_button( __( 'Save', 'mody_cloud' ) ); ?>
							</form>
						</div>
					</section>
				</div>
			</div>
		</div>
		<?php
	}
}
