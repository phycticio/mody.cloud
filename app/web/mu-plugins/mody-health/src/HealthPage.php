<?php
/**
 * Network Admin → Mody → Health.
 *
 * @package Mody\Health
 */

namespace Mody\Health;

defined( 'ABSPATH' ) || exit;

/**
 * The page: Report's numbers, for super admins only, in the Mody
 * dashboard's layout (asked by the maintainer on 2026-11-21,
 * tickets/mody-pages-dashboard-layout.md): the sections on the left, the
 * network's card, the section's panel. Under the network's Mody menu when
 * Mody Sites made it, else under Settings.
 */
final class HealthPage {

	/** Page slug. */
	public const SLUG = 'mody-health';

	/** Who may see it. */
	public const CAPABILITY = 'manage_network';

	/**
	 * The sections: slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function sections(): array {
		return array(
			'tokens'   => __( 'Mody\'s tokens this month', 'mody_cloud' ),
			'accounts' => __( 'Accounts', 'mody_cloud' ),
			'sites'    => __( 'Sites and space', 'mody_cloud' ),
			'failures' => __( 'What failed', 'mody_cloud' ),
		);
	}

	/**
	 * `network_admin_menu`.
	 */
	public static function register_menu(): void {
		$parent = class_exists( \Mody\Sites\WizardPage::class ) ? \Mody\Sites\WizardPage::SLUG : 'settings.php';
		$hook   = add_submenu_page( $parent, __( 'Health', 'mody_cloud' ), __( 'Health', 'mody_cloud' ), self::CAPABILITY, self::SLUG, array( self::class, 'render' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'enqueue' ) );
		}
	}

	/**
	 * `load-<page>`: the layout's stylesheet.
	 */
	public static function enqueue(): void {
		$root = dirname( __DIR__ );
		wp_enqueue_style( 'mody-health-admin-page', plugins_url( 'assets/admin-page.css', $root . '/mody-health.php' ), array(), (string) filemtime( $root . '/assets/admin-page.css' ) );
	}

	/**
	 * A section's address.
	 *
	 * @param string $section A key of sections().
	 */
	public static function url( string $section ): string {
		$parent = class_exists( \Mody\Sites\WizardPage::class ) ? 'admin.php' : 'settings.php';
		return add_query_arg(
			array(
				'page'    => self::SLUG,
				'section' => $section,
			),
			network_admin_url( $parent )
		);
	}

	/**
	 * The section asked for.
	 */
	private static function current(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which section to show.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		return isset( self::sections()[ $section ] ) ? $section : 'tokens';
	}

	/**
	 * A number in the site's format.
	 *
	 * @param int|float $number A number.
	 */
	private static function n( $number ): string {
		return number_format_i18n( $number );
	}

	/**
	 * Label => value rows (values already escaped).
	 *
	 * @param array<string, string> $rows Rows.
	 */
	private static function rows( array $rows ): void {
		echo '<table class="mody-brand__rows"><tbody>';
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Values escaped by the caller.
		}
		echo '</tbody></table>';
	}

	/**
	 * Renders the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$sections = self::sections();
		$section  = self::current();
		?>
		<div class="wrap mody-brand mody-health">
			<h1><?php esc_html_e( 'Health', 'mody_cloud' ); ?></h1>
			<div class="mody-brand__layout">
				<nav class="mody-brand__sections" aria-label="<?php esc_attr_e( 'Health sections', 'mody_cloud' ); ?>">
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
					<section class="mody-brand__card" aria-label="<?php esc_attr_e( 'The network', 'mody_cloud' ); ?>">
						<div class="mody-brand__card-text">
							<p class="mody-brand__name"><?php echo esc_html( wp_specialchars_decode( (string) get_network()->site_name, ENT_QUOTES ) ); ?></p>
							<p class="mody-brand__tagline"><?php esc_html_e( 'How the platform is doing, read live from the database. Nothing here changes anything.', 'mody_cloud' ); ?></p>
						</div>
					</section>
					<section class="mody-brand__panel" aria-labelledby="mody-health-section-title">
						<div class="mody-brand__panel-head">
							<h2 id="mody-health-section-title"><?php echo esc_html( $sections[ $section ] ); ?></h2>
						</div>
						<div class="mody-brand__panel-body">
							<?php
							switch ( $section ) {
								case 'accounts':
									self::render_accounts();
									break;
								case 'sites':
									self::render_sites();
									break;
								case 'failures':
									self::render_failures();
									break;
								default:
									self::render_tokens();
							}
							?>
						</div>
					</section>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Mody's tokens: the cost that grows with free accounts.
	 */
	private static function render_tokens(): void {
		$e      = static fn( $text ): string => esc_html( (string) $text );
		$tokens = Report::tokens();
		if ( null === $tokens ) {
			echo '<p class="mody-brand__empty">' . esc_html__( 'Mody Agent is not active.', 'mody_cloud' ) . '</p>';
			return;
		}
		self::rows(
			array(
				__( 'Used so far', 'mody_cloud' )            => $e( self::n( $tokens['total'] ) ) . ' ' . $e( sprintf( /* translators: %s: an amount in EUR. */ __( '(about %s EUR)', 'mody_cloud' ), number_format_i18n( $tokens['cost_eur'], 2 ) ) ),
				__( 'At this pace, the month', 'mody_cloud' ) => $e( self::n( $tokens['projected'] ) ) . ' ' . $e( sprintf( /* translators: %s: an amount in EUR. */ __( '(about %s EUR)', 'mody_cloud' ), number_format_i18n( $tokens['projected_eur'], 2 ) ) ),
			)
		);
		if ( $tokens['sites'] ) {
			echo '<h3>' . esc_html__( 'The sites that used the most', 'mody_cloud' ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Site', 'mody_cloud' ) . '</th><th>' . esc_html__( 'Tokens', 'mody_cloud' ) . '</th><th>' . esc_html__( 'Of its month', 'mody_cloud' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $tokens['sites'], 0, 10 ) as $site ) {
				$share = null === $site['share'] ? '' : round( $site['share'] * 100 ) . '%';
				$flag  = null !== $site['share'] && $site['share'] >= Report::TOKENS_WARNING ? ' <strong>' . esc_html__( 'near its allowance', 'mody_cloud' ) . '</strong>' : '';
				printf( '<tr><td>%s</td><td>%s</td><td>%s%s</td></tr>', $e( $site['domain'] ), $e( self::n( $site['tokens'] ) ), $e( $share ), $flag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			}
			echo '</tbody></table>';
		}
		echo '<p class="description">' . $e( sprintf( /* translators: %s: EUR per million tokens. */ __( 'Every request counts, super admins\' too. Cost estimated at %s EUR a million tokens (Infomaniak\'s price with our mix of prompt and answer tokens).', 'mody_cloud' ), number_format_i18n( Report::EUR_PER_MILLION, 2 ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped.
	}

	/**
	 * Accounts and sign-ups.
	 */
	private static function render_accounts(): void {
		$e        = static fn( $text ): string => esc_html( (string) $text );
		$accounts = Report::accounts();
		if ( null === $accounts ) {
			echo '<p class="mody-brand__empty">' . esc_html__( 'Mody Members is not active.', 'mody_cloud' ) . '</p>';
			return;
		}
		$list = static fn( array $counts ): string => $counts ? implode( ', ', array_map( static fn( $key, $n ) => esc_html( $key . ' ' . self::n( $n ) ), array_keys( $counts ), $counts ) ) : '0';
		self::rows(
			array(
				__( 'Accounts', 'mody_cloud' )                   => $e( self::n( $accounts['total'] ) ) . ' (' . $list( $accounts['plans'] ) . ')',
				__( 'By status', 'mody_cloud' )                  => $list( $accounts['statuses'] ),
				__( 'New in the last day, week, 30 days', 'mody_cloud' ) => $e( self::n( $accounts['new_day'] ) . ' · ' . self::n( $accounts['new_week'] ) . ' · ' . self::n( $accounts['new_month'] ) ),
				__( 'Joined through an invite', 'mody_cloud' )   => $e( self::n( $accounts['invited'] ) ),
				__( 'Sign-ups', 'mody_cloud' )                   => $e( $accounts['registration_open'] ? ( $accounts['invited_only'] ? __( 'Open, to invited people only', 'mody_cloud' ) : __( 'Open to everyone', 'mody_cloud' ) ) : __( 'Closed', 'mody_cloud' ) ),
			)
		);
	}

	/**
	 * Member sites and their space.
	 */
	private static function render_sites(): void {
		$e     = static fn( $text ): string => esc_html( (string) $text );
		$sites = Report::sites();
		$near  = array_map( static fn( array $s ): string => esc_html( sprintf( '%s: %s of %s MB', $s['domain'], self::n( $s['used_mb'] ), self::n( $s['allowed_mb'] ) ) ), $sites['near'] );
		self::rows(
			array(
				__( 'Member sites', 'mody_cloud' )                 => $e( self::n( $sites['count'] ) ),
				__( 'Space used in all', 'mody_cloud' )            => $e( self::n( $sites['used_mb'] ) . ' MB' ),
				__( 'At 80% of their space or more', 'mody_cloud' ) => $near ? implode( '<br>', $near ) : $e( __( 'None', 'mody_cloud' ) ),
			)
		);
	}

	/**
	 * Failures and payments.
	 */
	private static function render_failures(): void {
		$e    = static fn( $text ): string => esc_html( (string) $text );
		$f    = Report::failures();
		$rows = array();
		if ( null !== $f['jobs_failed'] ) {
			$rows[ __( 'Site creations failed, last 30 days', 'mody_cloud' ) ] = $e( self::n( $f['jobs_failed'] ) );
		}
		if ( null !== $f['agent_errors'] ) {
			$rows[ __( 'Mody\'s answers that ended in an error, last 7 days', 'mody_cloud' ) ] = $e( sprintf( /* translators: 1: errors, 2: all answers. */ __( '%1$s of %2$s', 'mody_cloud' ), self::n( $f['agent_errors'] ), self::n( (int) $f['agent_turns'] ) ) );
		}
		if ( null !== $f['domains'] ) {
			$rows[ __( 'Custom domains', 'mody_cloud' ) ] = $f['domains'] ? implode( ', ', array_map( static fn( $status, $n ) => esc_html( $status . ' ' . self::n( $n ) ), array_keys( $f['domains'] ), $f['domains'] ) ) : $e( __( 'None', 'mody_cloud' ) );
		}
		$payments = Report::payments();
		if ( null !== $payments ) {
			$rows[ __( 'Payments', 'mody_cloud' ) ] = $e(
				( $payments['offered'] ? __( 'Offered to members', 'mody_cloud' ) : __( 'Off: members are offered nothing', 'mody_cloud' ) )
				/* translators: 1: on or off, 2: live, test or none. */
				. ' · ' . sprintf( __( 'switch %1$s, key %2$s', 'mody_cloud' ), $payments['switched_on'] ? __( 'on', 'mody_cloud' ) : __( 'off', 'mody_cloud' ), $payments['key'] )
			);
		}
		if ( ! $rows ) {
			echo '<p class="mody-brand__empty">' . esc_html__( 'Nothing to report: the plugins this reads are not active.', 'mody_cloud' ) . '</p>';
			return;
		}
		self::rows( $rows );
	}
}
