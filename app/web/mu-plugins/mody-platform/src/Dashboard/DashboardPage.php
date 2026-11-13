<?php
/**
 * The Mody dashboard: every site's Dashboard.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Dashboard;

use Mody\Analytics\Stats;
use Mody\Platform\Agent\Budget;
use Mody\Platform\Billing\Billing;
use Mody\Platform\Billing\Mollie;
use Mody\Platform\Billing\Payments;
use Mody\Platform\Brand\BrandBook;
use Mody\Platform\Brand\BrandPage;
use Mody\Platform\Brand\BrandSettings;
use Mody\Platform\Login\LoginPage;
use Mody\Platform\Members\Members;
use Mody\Platform\Provisioning\Quota;
use Mody\Platform\Wizard\WizardPage;
use Mody\Platform\Site\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Asked for by the maintainer on 2026-11-11 (tickets/mody-dashboard.md):
 * wp-admin's Dashboard menu item, with the same word and icon in the same
 * place, opens `admin.php?page=mody-dashboard`, and core's dashboard
 * (index.php) redirects there. Every site has it, the main site included.
 *
 * The layout is the Brand page's (its stylesheet, its classes): the sections
 * on the left, the site's card, the section's panel. Which sections show
 * depends on the user (sections()).
 */
final class DashboardPage {

	/** The page's slug: admin.php?page=mody-dashboard. */
	public const SLUG = 'mody-dashboard';

	/** Who sees the dashboard: everyone who had core's. */
	public const CAPABILITY = 'read';

	/** Who sees the site's numbers: its administrators. */
	public const SITE_CAPABILITY = 'manage_options';

	/** Sections still being built: a "Work in progress" watermark over them (the maintainer, 2026-11-11; off Visitors since 2026-11-13). */
	public const WORK_IN_PROGRESS = array( 'mody' );

	/** The admin-post action that records the chosen plan. */
	public const PLAN_ACTION = 'mody_dashboard_plan';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 5 );
		add_action( 'admin_menu', array( self::class, 'adopt_submenu' ), PHP_INT_MAX );
		add_action( 'load-index.php', array( self::class, 'redirect' ) );
		add_action( 'admin_post_' . self::PLAN_ACTION, array( self::class, 'save_plan' ) );
		ExportPanel::register(); // Download your site (tickets/take-your-site-with-you.md).
	}

	/**
	 * The page's address, on a section.
	 *
	 * @param string               $section A section.
	 * @param array<string, mixed> $extra   More query arguments.
	 */
	public static function url( string $section = 'overview', array $extra = array() ): string {
		$args = array( 'page' => self::SLUG );
		if ( 'overview' !== $section ) {
			$args['section'] = $section;
		}
		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/**
	 * `admin_menu`: core's Dashboard item is replaced by this page, with the
	 * same word and icon, in the same place.
	 */
	public static function register_menu(): void {
		remove_menu_page( 'index.php' );
		$hook = add_menu_page(
			__( 'Dashboard' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own word, in the user's language.
			__( 'Dashboard' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own word, in the user's language.
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-dashboard',
			2
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'enqueue' ) );
			add_action( 'admin_head-' . $hook, array( LoginPage::class, 'print_fonts' ) );
		}
	}

	/**
	 * `admin_menu`, last: what plugins added under core's Dashboard moves
	 * under this one. Core's Home and Updates do not (Updates is gone for
	 * everyone, Gating\Updates).
	 */
	public static function adopt_submenu(): void {
		global $submenu;
		foreach ( (array) ( $submenu['index.php'] ?? array() ) as $item ) {
			if ( ! in_array( $item[2] ?? '', array( 'index.php', 'update-core.php' ), true ) ) {
				$submenu[ self::SLUG ][] = $item; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Moving menu items is what admin_menu is for.
			}
		}
		unset( $submenu['index.php'] );
		if ( ! empty( $submenu[ self::SLUG ] ) ) {
			// The page itself first, under the same word, as core does.
			array_unshift( $submenu[ self::SLUG ], array( __( 'Home' ), self::CAPABILITY, self::SLUG, __( 'Dashboard' ) ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain, WordPress.WP.GlobalVariablesOverride.Prohibited -- Core's words; moving menu items.
		}
	}

	/**
	 * `load-index.php`: core's dashboard opens this page instead, on a site's
	 * wp-admin (not Network Admin, nor the user dashboard).
	 */
	public static function redirect(): void {
		if ( is_network_admin() || is_user_admin() ) {
			return;
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * `load-<page>`: the Brand page's stylesheet, this page's own, and the
	 * site's theme variables.
	 */
	public static function enqueue(): void {
		LoginPage::enqueue_asset( 'mody-brand-page', 'brand-page.css' );
		LoginPage::enqueue_asset( 'mody-dashboard', 'dashboard-page.css', array( 'mody-brand-page' ) );
		wp_add_inline_style( 'mody-brand-page', LoginPage::variables_css() );
		ExportPanel::enqueue();
		if ( 'visitors' === self::current() && class_exists( Board::class ) && Board::can_manage() ) {
			self::enqueue_board();
		}
	}

	/** The board's script handle: app/dist/analytics-board.js, built from src/analytics-board.js. */
	public const BOARD_SCRIPT = 'mody-analytics-board';

	/**
	 * The analytics board's script, with the board and its first numbers
	 * (tickets/analytics-chat.md). Without the built file (no `pnpm build`
	 * yet) the Visitors section stays as the server draws it.
	 */
	private static function enqueue_board(): void {
		$asset = WP_CONTENT_DIR . '/../dist/analytics-board.asset.php';
		if ( ! is_readable( $asset ) ) {
			return;
		}
		$info = require $asset;
		wp_enqueue_script( self::BOARD_SCRIPT, WP_HOME . '/dist/analytics-board.js', (array) ( $info['dependencies'] ?? array() ), (string) ( $info['version'] ?? '' ), true );
		wp_enqueue_style( 'dashicons' );
		$analyst = function_exists( 'wp_ai_client_prompt' ) && wp_get_ability( Analyst::QUERY_ABILITY );
		if ( $analyst ) {
			// The analyst's bubble (phase 2) wears the editors' bubble: the same two stylesheets, in the user's color scheme.
			$root    = dirname( __DIR__, 2 );
			$url     = static fn( string $file ): string => plugins_url( 'assets/' . $file, $root . '/mody-platform.php' );
			$version = static fn( string $file ): string => (string) filemtime( $root . '/assets/' . $file );
			wp_enqueue_style( 'mody-agent-chat', $url( 'agent-chat.css' ), array(), $version( 'agent-chat.css' ) );
			\Mody\Platform\Admin\AdminColors::add_agent_chat_css();
			wp_enqueue_style( 'mody-agent-bubble', $url( 'agent-bubble.css' ), array( 'mody-agent-chat' ), $version( 'agent-bubble.css' ) );
		}
		wp_add_inline_script(
			self::BOARD_SCRIPT,
			'window.modyAnalyticsBoard = ' . wp_json_encode(
				array(
					'board'   => Board::payload( self::period() ),
					'analyst' => (bool) $analyst,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The sections this user sees: slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function sections(): array {
		$sections = array( 'overview' => __( 'Overview', 'mody_cloud' ) );
		if ( current_user_can( self::SITE_CAPABILITY ) && class_exists( Stats::class ) ) {
			$sections['visitors'] = __( 'Visitors', 'mody_cloud' );
		}
		if ( current_user_can( self::SITE_CAPABILITY ) ) {
			$sections['mody'] = __( 'Mody\'s work', 'mody_cloud' );
		}
		if ( ExportPanel::available() ) {
			$sections[ ExportPanel::SECTION ] = __( 'Download your site', 'mody_cloud' );
		}
		if ( Members::get( get_current_user_id() ) ) {
			$sections['sites'] = __( 'Your sites', 'mody_cloud' );
			$sections['plan']  = __( 'Plan & billing', 'mody_cloud' );
		}
		return $sections;
	}

	/** The periods the numbers cover, in days. */
	public const PERIODS = array( 7, 30, 90 );

	/**
	 * The period asked for, in days.
	 */
	private static function period(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which period to show.
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
		return in_array( $days, self::PERIODS, true ) ? $days : 30;
	}

	/**
	 * The section asked for.
	 */
	private static function current(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which section to show.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'overview';
		return isset( self::sections()[ $section ] ) ? $section : 'overview';
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
		<div class="wrap mody-brand mody-dashboard">
			<h1><?php esc_html_e( 'Dashboard' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own word. ?></h1>
			<div class="mody-brand__layout">
				<nav class="mody-brand__sections" aria-label="<?php esc_attr_e( 'Dashboard sections', 'mody_cloud' ); ?>">
					<ul>
						<?php foreach ( $sections as $slug => $label ) : ?>
							<li>
								<a href="<?php echo esc_url( self::url( $slug ) ); ?>"
									class="mody-brand__section-link<?php echo $slug === $section ? ' is-active' : ''; ?>"
									<?php echo $slug === $section ? 'aria-current="page"' : ''; ?>>
									<span><?php echo esc_html( $label ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</nav>
				<div class="mody-brand__main">
					<?php self::render_card(); ?>
					<?php self::render_notice(); ?>
					<section class="mody-brand__panel" aria-labelledby="mody-dashboard-section-title">
						<div class="mody-brand__panel-head">
							<h2 id="mody-dashboard-section-title"><?php echo esc_html( $sections[ $section ] ); ?></h2>
							<?php if ( in_array( $section, array( 'visitors', 'mody', 'sites' ), true ) ) : ?>
								<nav class="mody-brand__tabs" aria-label="<?php esc_attr_e( 'Period', 'mody_cloud' ); ?>">
									<?php foreach ( self::PERIODS as $days ) : ?>
										<?php $active = self::period() === $days; ?>
										<a href="<?php echo esc_url( self::url( $section, array( 'days' => $days ) ) ); ?>" class="mody-brand__tab<?php echo $active ? ' is-active' : ''; ?>" <?php echo $active ? 'aria-current="page"' : ''; ?>>
											<?php
											/* translators: %d: a number of days. */
											echo esc_html( sprintf( __( '%d days', 'mody_cloud' ), $days ) );
											?>
										</a>
									<?php endforeach; ?>
								</nav>
							<?php endif; ?>
						</div>
						<div class="mody-brand__panel-body<?php echo in_array( $section, self::WORK_IN_PROGRESS, true ) ? ' has-wip' : ''; ?>">
							<?php self::render_section( $section ); ?>
							<?php if ( in_array( $section, self::WORK_IN_PROGRESS, true ) ) : ?>
								<div class="mody-dashboard__wip" role="note">
									<span><?php esc_html_e( 'Work in progress', 'mody_cloud' ); ?><span class="mody-dashboard__dots" aria-hidden="true"><span>.</span><span>.</span><span>.</span></span></span>
								</div>
							<?php endif; ?>
						</div>
					</section>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * One section's panel.
	 *
	 * @param string $section The section.
	 */
	private static function render_section( string $section ): void {
		switch ( $section ) {
			case 'visitors':
				self::render_visitors();
				break;
			case 'mody':
				self::render_usage();
				break;
			case ExportPanel::SECTION:
				ExportPanel::render();
				break;
			case 'sites':
				self::render_sites();
				break;
			case 'plan':
				self::render_plan();
				break;
			default:
				self::render_overview();
		}
	}

	/**
	 * The site's card: logo, name, tagline, address, and the ways in.
	 */
	private static function render_card(): void {
		$brand = BrandSettings::brand();
		?>
		<section class="mody-brand__card" aria-label="<?php esc_attr_e( 'The site', 'mody_cloud' ); ?>">
			<?php $logo = LoginPage::logo_html( '200px' ); ?>
			<?php if ( ! str_contains( $logo, 'mody-login-name' ) ) : // No logo or icon: the name is already beside it. ?>
				<div class="mody-brand__logo"><?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in logo_html(). ?></div>
			<?php endif; ?>
			<div class="mody-brand__card-text">
				<p class="mody-brand__name"><?php echo esc_html( '' !== $brand['name'] ? $brand['name'] : __( '(no name yet)', 'mody_cloud' ) ); ?></p>
				<?php if ( '' !== $brand['tagline'] ) : ?>
					<p class="mody-brand__tagline"><?php echo esc_html( $brand['tagline'] ); ?></p>
				<?php endif; ?>
				<p class="mody-dashboard__address"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( self::address() ); ?></a></p>
			</div>
			<p class="mody-brand__links">
				<a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Visit site', 'mody_cloud' ); ?></a>
				<?php if ( current_user_can( 'edit_theme_options' ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'site-editor.php' ) ); ?>"><?php esc_html_e( 'Edit site', 'mody_cloud' ); ?></a>
				<?php endif; ?>
				<?php if ( ExportPanel::available() ) : ?>
					<a class="button" href="<?php echo esc_url( ExportPanel::url() ); ?>"><?php esc_html_e( 'Download your site', 'mody_cloud' ); ?></a>
				<?php endif; ?>
			</p>
		</section>
		<?php
	}

	/**
	 * The site's address without the scheme: example.mody.cloud.
	 */
	private static function address(): string {
		return untrailingslashit( (string) preg_replace( '#^https?://#', '', home_url( '/' ) ) );
	}

	/**
	 * The Overview section: the site at a glance.
	 */
	private static function render_overview(): void {
		$pages = wp_count_posts( 'page' );
		$posts = wp_count_posts( 'post' );
		?>
		<table class="mody-brand__rows">
			<tbody>
				<?php if ( current_user_can( self::SITE_CAPABILITY ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Search engines', 'mody_cloud' ); ?></th>
						<td>
							<?php
							echo esc_html( get_option( 'blog_public' ) ? __( 'Visible: search engines may list the site.', 'mody_cloud' ) : __( 'Hidden: search engines are asked not to list the site.', 'mody_cloud' ) );
							?>
							<a href="<?php echo esc_url( admin_url( 'options-reading.php' ) ); ?>"><?php esc_html_e( 'Change', 'mody_cloud' ); ?></a>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Pages', 'mody_cloud' ); ?></th>
					<td>
						<?php
						/* translators: 1: published pages, 2: draft pages. */
						echo esc_html( sprintf( __( '%1$s published, %2$s drafts', 'mody_cloud' ), number_format_i18n( (int) $pages->publish ), number_format_i18n( (int) $pages->draft ) ) );
						?>
						<?php if ( current_user_can( 'edit_pages' ) ) : ?>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>"><?php esc_html_e( 'See them', 'mody_cloud' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Posts', 'mody_cloud' ); ?></th>
					<td>
						<?php
						/* translators: 1: published posts, 2: draft posts. */
						echo esc_html( sprintf( __( '%1$s published, %2$s drafts', 'mody_cloud' ), number_format_i18n( (int) $posts->publish ), number_format_i18n( (int) $posts->draft ) ) );
						?>
						<?php if ( current_user_can( 'edit_posts' ) ) : ?>
							<a href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>"><?php esc_html_e( 'See them', 'mody_cloud' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( current_user_can( 'upload_files' ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Storage', 'mody_cloud' ); ?></th>
						<td><?php self::render_storage(); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( current_user_can( BrandPage::CAPABILITY ) ) : ?>
					<?php
					$editable = BrandBook::editable_sections();
					$filled   = count( array_intersect( BrandBook::filled_sections(), $editable ) );
					?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Your brand', 'mody_cloud' ); ?></th>
						<td>
							<?php
							/* translators: 1: sections filled in, 2: all sections. */
							echo esc_html( sprintf( __( '%1$d of %2$d sections filled in. Mody reads them with every request.', 'mody_cloud' ), $filled, count( $editable ) ) );
							?>
							<a href="<?php echo esc_url( BrandPage::url() ); ?>"><?php echo esc_html( $filled < count( $editable ) ? __( 'Fill in', 'mody_cloud' ) : __( 'Open', 'mody_cloud' ) ); ?></a>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Created', 'mody_cloud' ); ?></th>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) strtotime( (string) get_site()->registered . ' UTC' ) ) ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The Visitors section (mody-analytics), this site only: visits and
	 * page views over the period, against the period before; a bar per day;
	 * the journeys visits made; pages, entry and exit pages, sources and
	 * devices.
	 */
	private static function render_visitors(): void {
		// The board (src/analytics-board.js) replaces what is inside when its script runs; until then, and without it, the section as before.
		echo '<div id="mody-analytics-board" class="mody-board">';
		self::render_visitors_fallback();
		echo '</div>';
		if ( class_exists( \Mody\Analytics\Geo\Locator::class ) && \Mody\Analytics\Geo\Locator::reader() ) {
			// DB-IP City Lite is CC BY 4.0: the credit it asks for (tickets/visitor-location.md).
			printf(
				'<p class="mody-dashboard__note">%s</p>',
				wp_kses(
					sprintf(
						/* translators: %s: a link to DB-IP. */
						__( 'Countries and cities are worked out on our own server from %s (CC BY 4.0); visitors\' addresses are never kept. A city with fewer than 5 visits in a period is counted under its country.', 'mody_cloud' ),
						'<a href="https://db-ip.com" rel="noopener">IP Geolocation by DB-IP</a>'
					),
					array(
						'a' => array(
							'href' => true,
							'rel'  => true,
						),
					)
				)
			);
		}
	}

	/**
	 * The Visitors section drawn on the server: the board's fallback, kept
	 * until the board has been tried for real (tickets/analytics-chat.md).
	 */
	private static function render_visitors_fallback(): void {
		$days  = self::period();
		$stats = Stats::summary( get_current_blog_id(), $days );
		$pages = $stats['visits'] > 0 ? $stats['views'] / $stats['visits'] : 0;
		?>
		<div class="mody-dashboard__tiles">
			<?php
			self::render_tile( __( 'Visits', 'mody_cloud' ), number_format_i18n( $stats['visits'] ), self::change( $stats['visits'], $stats['previous_visits'] ) );
			self::render_tile( __( 'Page views', 'mody_cloud' ), number_format_i18n( $stats['views'] ), self::change( $stats['views'], $stats['previous_views'] ) );
			self::render_tile( __( 'Pages per visit', 'mody_cloud' ), number_format_i18n( $pages, 1 ), '' );
			self::render_tile( __( 'Single-page visits', 'mody_cloud' ), $stats['visits'] > 0 ? number_format_i18n( 100 * $stats['single'] / $stats['visits'] ) . '%' : '–', __( 'Left from the page they came in on', 'mody_cloud' ) );
			?>
		</div>
		<?php self::render_chart( $stats['days'] ); ?>
		<?php self::render_journeys( $stats['journeys'] ); ?>
		<div class="mody-dashboard__lists">
			<?php
			$path = static fn( string $path ): string => $path;
			self::render_list( __( 'Pages', 'mody_cloud' ), __( 'Views', 'mody_cloud' ), $stats['pages'], $path );
			self::render_list( __( 'Entry pages', 'mody_cloud' ), __( 'Visits', 'mody_cloud' ), $stats['entries'], $path );
			self::render_list( __( 'Exit pages', 'mody_cloud' ), __( 'Visits', 'mody_cloud' ), $stats['exits'], $path );
			self::render_list(
				__( 'Sources', 'mody_cloud' ),
				__( 'Visits', 'mody_cloud' ),
				$stats['sources'],
				static fn( string $host ): string => '' === $host ? __( 'Direct, or no source given', 'mody_cloud' ) : $host
			);
			self::render_list(
				__( 'Devices', 'mody_cloud' ),
				__( 'Visits', 'mody_cloud' ),
				$stats['devices'],
				static fn( string $device ): string => array(
					'mobile'  => __( 'Phone', 'mody_cloud' ),
					'tablet'  => __( 'Tablet', 'mody_cloud' ),
					'desktop' => __( 'Computer', 'mody_cloud' ),
				)[ $device ] ?? __( 'Unknown', 'mody_cloud' )
			);
			?>
		</div>
		<p class="mody-dashboard__note"><?php esc_html_e( 'Counted by Mody Cloud itself, without cookies and without keeping anyone\'s address or name: only visits and the pages they saw, in order. A visit ends after 30 minutes without a new page. Everyone who reads the site counts, you included when you are logged in.', 'mody_cloud' ); ?></p>
		<?php
	}

	/**
	 * The journeys: the most common orders of pages in visits of two pages
	 * or more, each with its visits.
	 *
	 * @param array<int, array{steps: array<int, string>, more: bool, count: int}> $journeys Most common first.
	 */
	private static function render_journeys( array $journeys ): void {
		$max = max( 1, (int) ( $journeys[0]['count'] ?? 1 ) );
		?>
		<section class="mody-dashboard__list mody-dashboard__journeys">
			<h3><span><?php esc_html_e( 'Journeys', 'mody_cloud' ); ?></span><span><?php esc_html_e( 'Visits', 'mody_cloud' ); ?></span></h3>
			<?php if ( ! $journeys ) : ?>
				<p class="mody-brand__empty"><?php esc_html_e( 'Nothing yet: journeys show when visits see more than one page.', 'mody_cloud' ); ?></p>
			<?php else : ?>
				<ol>
					<?php foreach ( $journeys as $journey ) : ?>
						<li style="--mody-share:<?php echo esc_attr( (string) round( 100 * $journey['count'] / $max, 1 ) ); ?>%">
							<span class="mody-dashboard__list-name mody-dashboard__steps">
								<?php foreach ( $journey['steps'] as $i => $step ) : ?>
									<?php if ( $i > 0 ) : ?>
										<span class="mody-dashboard__step-arrow" aria-hidden="true">→</span><span class="screen-reader-text"><?php esc_html_e( 'then', 'mody_cloud' ); ?></span>
									<?php endif; ?>
									<span class="mody-dashboard__step"><?php echo esc_html( $step ); ?></span>
								<?php endforeach; ?>
								<?php if ( $journey['more'] ) : ?>
									<span class="mody-dashboard__step-arrow" aria-hidden="true">→</span>
									<span class="mody-dashboard__step is-more"><?php esc_html_e( 'more', 'mody_cloud' ); ?></span>
								<?php endif; ?>
							</span>
							<span class="mody-dashboard__list-count"><?php echo esc_html( number_format_i18n( $journey['count'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * The Mody's work section: messages and tokens by kind of work, and the
	 * pages Mody worked on most (Usage).
	 */
	private static function render_usage(): void {
		$usage  = Usage::summary( get_current_blog_id(), self::period() );
		$labels = array(
			'design'        => array( __( 'Design', 'mody_cloud' ), __( 'Pages, templates, the design and the styles', 'mody_cloud' ) ),
			'configuration' => array( __( 'Configuration', 'mody_cloud' ), __( 'Settings, the menu, publishing, opening screens', 'mody_cloud' ) ),
			'conversation'  => array( __( 'Questions and answers', 'mody_cloud' ), __( 'Messages that changed nothing', 'mody_cloud' ) ),
			'analytics'     => array( __( 'Analytics', 'mody_cloud' ), __( 'Questions and charts on the Visitors section', 'mody_cloud' ) ),
		);
		$max    = max( 1, max( array_column( $usage['groups'], 'tokens' ) ) );
		?>
		<div class="mody-dashboard__tiles">
			<?php
			self::render_tile( __( 'Messages to Mody', 'mody_cloud' ), number_format_i18n( $usage['messages'] ), '' );
			self::render_tile( __( 'Tokens', 'mody_cloud' ), self::tokens( $usage['tokens'] ), '' );
			self::render_tile( __( 'Tokens on design', 'mody_cloud' ), self::tokens( $usage['groups']['design']['tokens'] ), '' );
			self::render_tile( __( 'Tokens on configuration', 'mody_cloud' ), self::tokens( $usage['groups']['configuration']['tokens'] ), '' );
			?>
		</div>
		<?php
		$month = Budget::used( get_current_blog_id() )['month'];
		$limit = Budget::limits( get_current_blog_id() )['month'];
		?>
		<p class="mody-dashboard__allowance">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: tokens used this month, such as 1.2 M, 2: the month's allowance, such as 5.0 M, 3: a date. */
					__( 'This month: %1$s of the site\'s %2$s tokens. The count starts again on %3$s.', 'mody_cloud' ),
					self::tokens( $month ),
					self::tokens( $limit ),
					wp_date( get_option( 'date_format' ), Budget::resets( 'month' ) )
				)
			);
			?>
			<span class="mody-brand__bar" aria-hidden="true"><span style="width:<?php echo esc_attr( (string) min( 100, round( 100 * $month / max( 1, $limit ), 1 ) ) ); ?>%"></span></span>
		</p>
		<table class="mody-brand__rows mody-dashboard__usage">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Work', 'mody_cloud' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Messages', 'mody_cloud' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Tokens', 'mody_cloud' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $usage['groups'] as $group => $counts ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $labels[ $group ][0] ); ?><span class="mody-dashboard__note"><?php echo esc_html( $labels[ $group ][1] ); ?></span></th>
						<td><?php echo esc_html( number_format_i18n( $counts['messages'] ) ); ?></td>
						<td>
							<span class="mody-dashboard__meter">
								<?php echo esc_html( number_format_i18n( $counts['tokens'] ) ); ?>
								<span class="mody-brand__bar" aria-hidden="true"><span style="width:<?php echo esc_attr( (string) round( 100 * $counts['tokens'] / $max, 1 ) ); ?>%"></span></span>
							</span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<div class="mody-dashboard__lists">
			<section class="mody-dashboard__list">
				<h3><span><?php esc_html_e( 'What Mody worked on most', 'mody_cloud' ); ?></span><span><?php esc_html_e( 'Tokens', 'mody_cloud' ); ?></span></h3>
				<?php if ( ! $usage['open'] ) : ?>
					<p class="mody-brand__empty"><?php esc_html_e( 'Nothing yet: pages and templates show here when you ask Mody with them open in the editor.', 'mody_cloud' ); ?></p>
				<?php else : ?>
					<?php $top = max( 1, (int) $usage['open'][0]['tokens'] ); ?>
					<ol>
						<?php foreach ( $usage['open'] as $item ) : ?>
							<li style="--mody-share:<?php echo esc_attr( (string) round( 100 * $item['tokens'] / $top, 1 ) ); ?>%">
								<span class="mody-dashboard__list-name">
									<?php echo esc_html( Usage::title( $item['type'], $item['id'] ) ); ?>
									<span class="mody-dashboard__note">
										<?php
										/* translators: %s: a number of messages. */
										echo esc_html( sprintf( _n( '%s message', '%s messages', $item['messages'], 'mody_cloud' ), number_format_i18n( $item['messages'] ) ) );
										?>
									</span>
								</span>
								<span class="mody-dashboard__list-count"><?php echo esc_html( number_format_i18n( $item['tokens'] ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
			</section>
		</div>
		<p class="mody-dashboard__note">
			<?php
			esc_html_e( 'A token is a piece of a word: the unit the AI model reads and writes in. A message counts what Mody read (your request, your brand, the page) and what it wrote back.', 'mody_cloud' );
			if ( '' !== $usage['since'] ) {
				echo ' ' . esc_html(
					sprintf(
						/* translators: %s: a date. */
						__( 'Counted since %s.', 'mody_cloud' ),
						wp_date( get_option( 'date_format' ), (int) strtotime( $usage['since'] . ' UTC' ) )
					)
				);
			}
			?>
		</p>
		<?php
	}

	/**
	 * A token count, short: 1,234 or 1.2 M.
	 *
	 * @param int $tokens Tokens.
	 */
	private static function tokens( int $tokens ): string {
		if ( $tokens >= 1000000 ) {
			/* translators: %s: millions, such as 1.2. */
			return sprintf( __( '%s M', 'mody_cloud' ), number_format_i18n( $tokens / 1000000, 1 ) );
		}
		if ( $tokens >= 10000 ) {
			/* translators: %s: thousands, such as 45. */
			return sprintf( __( '%s k', 'mody_cloud' ), number_format_i18n( $tokens / 1000 ) );
		}
		return number_format_i18n( $tokens );
	}

	/**
	 * The result of the last plan choice.
	 */
	private static function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: the result of the last choice.
		$result = isset( $_GET['plan-result'] ) ? sanitize_key( wp_unslash( $_GET['plan-result'] ) ) : '';
		if ( 'chosen' === $result ) {
			echo '<div class="notice notice-success inline is-dismissible mody-brand__notice"><p>' . esc_html__( 'Saved. Nothing is charged yet.', 'mody_cloud' ) . '</p></div>';
		} elseif ( '' !== $result ) {
			echo '<div class="notice notice-error inline mody-brand__notice"><p>' . esc_html__( 'That could not be saved. Try again.', 'mody_cloud' ) . '</p></div>';
		}
	}

	/**
	 * The member's sites: one card each (name, address, space, the ways in),
	 * how many of the plan's sites are used, and New site.
	 */
	private static function render_sites(): void {
		$user  = get_current_user_id();
		$sites = self::member_sites( $user );
		$used  = Quota::used( $user );
		$limit = Quota::limit( $user );
		$can   = true === Quota::can_create_site( $user );
		$days  = self::period();
		$stats = class_exists( Stats::class ) && $sites ? Stats::sites( array_column( $sites, 'id' ), $days ) : null;
		?>
		<div class="mody-dashboard__sites-head">
			<p>
				<?php
				echo esc_html(
					$used > $limit
						/* translators: %d: sites the account has (more than its plan includes: a trial account from before 2026-11-13). */
						? sprintf( _n( '%d site used', '%d sites used', $used, 'mody_cloud' ), $used )
						/* translators: 1: sites used, 2: sites the plan includes. */
						: sprintf( _n( '%1$d of %2$d site used', '%1$d of %2$d sites used', $limit, 'mody_cloud' ), $used, $limit )
				);
				?>
				<span class="mody-brand__bar" aria-hidden="true"><span style="width:<?php echo esc_attr( (string) min( 100, round( 100 * $used / max( 1, $limit ) ) ) ); ?>%"></span></span>
			</p>
			<?php if ( $can ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( network_admin_url( 'admin.php?page=' . WizardPage::SLUG ) ); ?>"><?php esc_html_e( 'New site', 'mody_cloud' ); ?></a>
			<?php else : ?>
				<span class="mody-dashboard__note">
					<?php
					$free = 'free' === (string) ( Members::get( $user )['plan'] ?? '' );
					echo esc_html(
						$free
							? __( 'The launch offer includes one site.', 'mody_cloud' )
							/* translators: %d: the most sites an account can have. */
							: sprintf( __( 'An account can have %d sites.', 'mody_cloud' ), $limit )
					);
					?>
					<a href="<?php echo esc_url( self::url( 'plan' ) ); ?>"><?php esc_html_e( 'Plan & billing', 'mody_cloud' ); ?></a>
				</span>
			<?php endif; ?>
		</div>
		<?php if ( $stats ) : ?>
			<?php self::render_sites_visitors( $sites, $stats ); ?>
		<?php endif; ?>
		<?php if ( ! $sites ) : ?>
			<p class="mody-brand__empty"><?php esc_html_e( 'No site yet.', 'mody_cloud' ); ?></p>
		<?php else : ?>
			<ul class="mody-dashboard__sites">
				<?php foreach ( $sites as $site ) : ?>
					<li class="mody-dashboard__site<?php echo (int) $site['id'] === get_current_blog_id() ? ' is-current' : ''; ?>">
						<div class="mody-dashboard__site-text">
							<p class="mody-dashboard__site-name"><?php echo esc_html( '' !== $site['name'] ? $site['name'] : $site['address'] ); ?>
								<?php if ( (int) $site['id'] === get_current_blog_id() ) : ?>
									<span class="mody-brand__badge is-filled"><?php esc_html_e( 'This site', 'mody_cloud' ); ?></span>
								<?php endif; ?>
							</p>
							<p class="mody-dashboard__address"><?php echo esc_html( $site['address'] ); ?></p>
							<p class="mody-dashboard__note">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: a date, 2: space used, 3: space the site has. */
										__( 'Created %1$s · %2$s of %3$s used', 'mody_cloud' ),
										wp_date( get_option( 'date_format' ), $site['created'] ),
										size_format( $site['used'], $site['used'] < MB_IN_BYTES ? 0 : 1 ),
										size_format( $site['allowed'] )
									)
								);
								?>
							</p>
							<?php if ( $stats ) : ?>
								<?php $counts = $stats['sites'][ (int) $site['id'] ]; ?>
								<p class="mody-dashboard__note">
									<a href="<?php echo esc_url( add_query_arg( 'days', $days, $site['visitors'] ) ); ?>">
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: visits, 2: page views, 3: a number of days. */
												__( '%1$s visits · %2$s page views in %3$d days', 'mody_cloud' ),
												number_format_i18n( $counts['visits'] ),
												number_format_i18n( $counts['views'] ),
												$days
											)
										);
										?>
									</a>
								</p>
							<?php endif; ?>
						</div>
						<p class="mody-brand__links">
							<a class="button" href="<?php echo esc_url( $site['home'] ); ?>"><?php esc_html_e( 'Visit', 'mody_cloud' ); ?></a>
							<a class="button" href="<?php echo esc_url( $site['admin'] ); ?>"><?php esc_html_e( 'Dashboard' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own word. ?></a>
							<a class="button" href="<?php echo esc_url( $site['editor'] ); ?>"><?php esc_html_e( 'Edit site', 'mody_cloud' ); ?></a>
							<a class="button" href="<?php echo esc_url( $site['export'] ); ?>"><?php esc_html_e( 'Download', 'mody_cloud' ); ?></a>
						</p>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	/**
	 * Your sites' visitors (mody-analytics): every site of the member
	 * together, visits per day, and the sites side by side. Each site's own
	 * Visitors section has the rest.
	 *
	 * @param array<int, array<string, mixed>> $sites The member's sites (member_sites()).
	 * @param array<string, mixed>             $stats Stats::sites() for them.
	 */
	private static function render_sites_visitors( array $sites, array $stats ): void {
		$days = self::period();
		$max  = max( 1, max( array_map( static fn( array $site ): int => $site['visits'], $stats['sites'] ) ) );
		$rows = $sites;
		usort( $rows, static fn( array $a, array $b ): int => $stats['sites'][ (int) $b['id'] ]['visits'] <=> $stats['sites'][ (int) $a['id'] ]['visits'] );
		?>
		<section class="mody-dashboard__across" aria-labelledby="mody-dashboard-across-title">
			<h3 id="mody-dashboard-across-title"><?php esc_html_e( 'Visitors across your sites', 'mody_cloud' ); ?></h3>
			<div class="mody-dashboard__tiles">
				<?php
				self::render_tile( __( 'Visits', 'mody_cloud' ), number_format_i18n( $stats['visits'] ), self::change( $stats['visits'], $stats['previous_visits'] ) );
				self::render_tile( __( 'Page views', 'mody_cloud' ), number_format_i18n( $stats['views'] ), self::change( $stats['views'], $stats['previous_views'] ) );
				self::render_tile( __( 'Pages per visit', 'mody_cloud' ), number_format_i18n( $stats['visits'] > 0 ? $stats['views'] / $stats['visits'] : 0, 1 ), '' );
				?>
			</div>
			<?php self::render_chart( $stats['days'], __( 'Visits per day, all your sites', 'mody_cloud' ) ); ?>
			<table class="mody-brand__rows mody-dashboard__usage mody-dashboard__compare">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Site', 'mody_cloud' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Visits', 'mody_cloud' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Page views', 'mody_cloud' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Most viewed page', 'mody_cloud' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $site ) : ?>
						<?php $counts = $stats['sites'][ (int) $site['id'] ]; ?>
						<tr>
							<th scope="row">
								<a href="<?php echo esc_url( add_query_arg( 'days', $days, $site['visitors'] ) ); ?>"><?php echo esc_html( '' !== $site['name'] ? $site['name'] : $site['address'] ); ?></a>
								<span class="mody-dashboard__note"><?php echo esc_html( self::change( $counts['visits'], $counts['previous_visits'] ) ); ?></span>
							</th>
							<td>
								<span class="mody-dashboard__meter">
									<?php echo esc_html( number_format_i18n( $counts['visits'] ) ); ?>
									<span class="mody-brand__bar" aria-hidden="true"><span style="width:<?php echo esc_attr( (string) round( 100 * $counts['visits'] / $max, 1 ) ); ?>%"></span></span>
								</span>
							</td>
							<td><?php echo esc_html( number_format_i18n( $counts['views'] ) ); ?></td>
							<td class="mody-dashboard__path"><?php echo esc_html( '' !== $counts['top'] ? $counts['top'] : '–' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * A member's sites: those they administer, not the main site.
	 *
	 * @param int $user_id The member.
	 * @return array<int, array{id: int, name: string, address: string, home: string, admin: string, visitors: string, export: string, editor: string, created: int, used: int, allowed: int}>
	 */
	public static function member_sites( int $user_id ): array {
		global $wpdb;
		$sites = array();
		foreach ( get_blogs_of_user( $user_id ) as $blog ) {
			$id   = (int) $blog->userblog_id;
			$caps = get_user_meta( $user_id, $wpdb->get_blog_prefix( $id ) . 'capabilities', true );
			if ( is_main_site( $id ) || ! is_array( $caps ) || empty( $caps['administrator'] ) ) {
				continue;
			}
			switch_to_blog( $id );
			$sites[] = array(
				'id'      => $id,
				'name'    => (string) get_option( 'blogname' ),
				'address' => self::address(),
				'home'    => home_url( '/' ),
				'admin'    => self::url(),
				'visitors' => self::url( 'visitors' ),
				'export'   => ExportPanel::url(), // That site's own Download your site section.
				'editor'  => admin_url( 'site-editor.php' ),
				'created' => (int) strtotime( (string) get_site( $id )->registered . ' UTC' ),
				'used'    => (int) round( get_space_used() * MB_IN_BYTES ),
				'allowed' => (int) round( get_space_allowed() * MB_IN_BYTES ),
			);
			restore_current_blog();
		}
		usort( $sites, static fn( array $a, array $b ): int => $a['created'] <=> $b['created'] );
		return $sites;
	}

	/**
	 * A price: 7.99 EUR.
	 *
	 * @param float $eur Euros.
	 */
	private static function price( float $eur ): string {
		return Members::price( $eur );
	}

	/**
	 * "a month" or "a year".
	 *
	 * @param string $period One of Members::PERIODS.
	 */
	private static function per( string $period ): string {
		return 'yearly' === $period ? __( 'a year', 'mody_cloud' ) : __( 'a month', 'mody_cloud' );
	}

	/**
	 * Plan & billing (decided 2026-11-13): the launch offer's free months or
	 * what the account pays (its sites times the price), the next payment,
	 * the card, and the choice between paying monthly and yearly.
	 */
	private static function render_plan(): void {
		$user = get_current_user_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: the payment Mollie's checkout returned from; it is checked with Mollie.
		$back = isset( $_GET['payment'] ) ? Billing::returned( $user, absint( $_GET['payment'] ) ) : '';
		self::render_billing_notice( $back );
		$row    = (array) Members::get( $user );
		$plan   = (string) ( $row['plan'] ?? 'free' );
		$saved  = in_array( (string) ( $row['billing_period'] ?? '' ), Members::PERIODS, true ) ? (string) $row['billing_period'] : 'monthly';
		$until  = Members::free_until( $user );
		$due    = ! empty( $row['billing_due_at'] ) ? (int) strtotime( $row['billing_due_at'] . ' UTC' ) : 0;
		$sites  = max( 1, Quota::used( $user ) );
		$total  = Members::total( $sites, $saved );
		$date   = static fn( int $time ): string => wp_date( get_option( 'date_format' ), $time );
		$amount = static fn( string $period ): string => sprintf(
			/* translators: 1: number of sites, 2: a site's price, 3: "a month" or "a year", 4: the total. */
			_n( '%1$d site × %2$s %3$s: %4$s, VAT included', '%1$d sites × %2$s %3$s: %4$s, VAT included', $sites, 'mody_cloud' ),
			$sites,
			Members::price( Members::PRICE[ $period ] ),
			self::per( $period ),
			Members::price( Members::total( $sites, $period ) )
		);
		?>
		<table class="mody-brand__rows">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Your plan', 'mody_cloud' ); ?></th>
					<td>
						<?php
						if ( 'free' === $plan ) {
							$left = max( 0, (int) ceil( ( $until - time() ) / DAY_IN_SECONDS ) );
							echo esc_html(
								$until
									/* translators: 1: a date, 2: days left. */
									? sprintf( _n( 'Launch offer: one site, free until %1$s (%2$d day left).', 'Launch offer: one site, free until %1$s (%2$d days left).', $left, 'mody_cloud' ), $date( $until ), $left )
									: __( 'Launch offer: one site, free.', 'mody_cloud' )
							);
						} else {
							echo esc_html( $amount( $saved ) );
						}
						?>
					</td>
				</tr>
				<?php if ( 'free' === $plan ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'After the free months', 'mody_cloud' ); ?></th>
						<td><?php echo esc_html( $amount( $saved ) ); ?></td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Next payment', 'mody_cloud' ); ?></th>
					<td>
						<?php
						if ( $due && empty( $row['mollie_payment_method_id'] ) ) {
							/* translators: 1: an amount, 2: a date. */
							echo esc_html( sprintf( __( '%1$s on %2$s, once you add a card', 'mody_cloud' ), self::price( $total ), $date( $due ) ) );
						} elseif ( $due ) {
							/* translators: 1: an amount, 2: a date. */
							echo esc_html( sprintf( __( '%1$s on %2$s', 'mody_cloud' ), self::price( $total ), $date( $due ) ) );
						} else {
							echo '<span class="mody-brand__empty">' . esc_html__( 'None planned', 'mody_cloud' ) . '</span>';
						}
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Payment method', 'mody_cloud' ); ?></th>
					<td>
						<?php self::render_payment_method( $user, $row ); ?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php self::render_withdrawal( $user ); ?>
		<?php self::render_cancel( $user, $row ); ?>
		<?php self::render_payments( $user ); ?>

		<div class="mody-dashboard__plans-head">
			<h3><?php esc_html_e( 'How you pay', 'mody_cloud' ); ?></h3>
		</div>
		<ul class="mody-dashboard__plans">
			<?php foreach ( Members::PERIODS as $period ) : ?>
				<?php $chosen = $period === $saved; ?>
				<li class="mody-dashboard__plan<?php echo $chosen ? ' is-chosen' : ''; ?>">
					<p class="mody-dashboard__plan-name"><?php echo esc_html( 'yearly' === $period ? __( 'Yearly', 'mody_cloud' ) : __( 'Monthly', 'mody_cloud' ) ); ?></p>
					<p class="mody-dashboard__plan-price">
						<?php echo esc_html( self::price( Members::PRICE[ $period ] ) ); ?>
						<span><?php echo esc_html( sprintf( /* translators: %s: "a month" or "a year". */ __( 'per site %s', 'mody_cloud' ), self::per( $period ) ) ); ?></span>
					</p>
					<p class="mody-dashboard__note">
						<?php
						echo esc_html(
							'yearly' === $period
								/* translators: %s: an amount saved. */
								? sprintf( __( 'Ten months\' price for twelve: you save %s per site.', 'mody_cloud' ), self::price( Members::yearly_saving() ) )
								: __( 'Cancel at the end of any month.', 'mody_cloud' )
						);
						?>
					</p>
					<?php if ( $chosen ) : ?>
						<p class="mody-brand__badge is-filled"><?php esc_html_e( 'Your choice', 'mody_cloud' ); ?></p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( self::PLAN_ACTION ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( self::PLAN_ACTION ); ?>" />
							<input type="hidden" name="period" value="<?php echo esc_attr( $period ); ?>" />
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Choose', 'mody_cloud' ); ?></button>
						</form>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="mody-dashboard__note">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: space per site, such as 1 GB, 2: Mody's tokens a month, such as 5 million. */
					__( 'Every site has the same: %1$s of space, every plugin in our market, the full Site Editor and its own Mody, with %2$s tokens a month. Prices in euros, VAT included.', 'mody_cloud' ),
					size_format( Storage::SITE_MB * MB_IN_BYTES ),
					number_format_i18n( Budget::MONTH )
				)
			);
			?>
		</p>
		<?php
	}

	/**
	 * What the last billing step did: from Mollie's checkout, or a form.
	 *
	 * @param string $back What returning from the checkout gave (Billing::returned()).
	 */
	private static function render_billing_notice( string $back ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: the result of the last step.
		$result = '' !== $back ? $back : ( isset( $_GET['billing'] ) ? sanitize_key( wp_unslash( $_GET['billing'] ) ) : '' );
		$notes  = array(
			'card-added'     => array( 'success', __( 'Your card is added. We sent you the terms by email.', 'mody_cloud' ) ),
			'card-waiting'   => array( 'info', __( 'Your card check is still being processed. This page shows it once it is done.', 'mody_cloud' ) ),
			'card-failed'    => array( 'error', __( 'The card check did not go through, so no card was added. Try again, or use another card.', 'mody_cloud' ) ),
			'card-error'     => array( 'error', __( 'The card check could not start. Please try again in a moment.', 'mody_cloud' ) ),
			'accept'         => array( 'error', __( 'To add a card, tick the box to accept the terms.', 'mody_cloud' ) ),
			'confirm'        => array( 'error', __( 'To withdraw, tick the box to confirm.', 'mody_cloud' ) ),
			'withdrawn'      => array( 'success', __( 'You have withdrawn. Your payment goes back to your card in full, and nothing more will be charged.', 'mody_cloud' ) ),
			'withdraw-error' => array( 'error', __( 'The withdrawal did not work. Please try again, or reply to any of our emails.', 'mody_cloud' ) ),
			'confirm-cancel' => array( 'error', __( 'To cancel, tick the box to confirm.', 'mody_cloud' ) ),
			'canceled'       => array( 'success', __( 'Cancelled: nothing more will be charged. We sent you the details by email.', 'mody_cloud' ) ),
			'resumed'        => array( 'success', __( 'Your plan goes on as before.', 'mody_cloud' ) ),
			'cancel-error'   => array( 'error', __( 'That did not work. Please reload the page and try again.', 'mody_cloud' ) ),
		);
		if ( isset( $notes[ $result ] ) ) {
			printf( '<div class="notice notice-%1$s inline mody-brand__notice"><p>%2$s</p></div>', esc_attr( $notes[ $result ][0] ), esc_html( $notes[ $result ][1] ) );
		}
	}

	/**
	 * The card on file, and the form to add or change it (the terms to
	 * accept, then Mollie's checkout).
	 *
	 * @param int                  $user The member.
	 * @param array<string, mixed> $row  Their row.
	 */
	private static function render_payment_method( int $user, array $row ): void {
		$card = (array) get_user_meta( $user, Billing::CARD_META, true );
		$has  = ! empty( $row['mollie_payment_method_id'] );
		if ( $has ) {
			echo esc_html(
				'' !== (string) ( $card['number'] ?? '' )
					/* translators: 1: a card's brand, such as Visa, 2: its last digits. */
					? sprintf( __( '%1$s ending in %2$s', 'mody_cloud' ), '' !== (string) ( $card['label'] ?? '' ) ? $card['label'] : __( 'Card', 'mody_cloud' ), $card['number'] )
					: __( 'A card is on file.', 'mody_cloud' )
			);
		}
		$expires = Billing::card_expires_first( $user );
		if ( $expires ) {
			printf(
				'<p class="mody-dashboard__card-expiry">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: a date, 2: a date. */
						__( 'This card expires on %1$s, before your next payment on %2$s. Change it below so the payment goes through.', 'mody_cloud' ),
						wp_date( (string) get_option( 'date_format' ), $expires ),
						wp_date( (string) get_option( 'date_format' ), (int) strtotime( $row['billing_due_at'] . ' UTC' ) )
					)
				)
			);
		}
		if ( 'expired' === (string) ( $row['status'] ?? '' ) ) {
			return;
		}
		if ( ! Mollie::offered() ) {
			if ( ! $has ) {
				echo '<span class="mody-brand__empty">' . esc_html__( 'None yet.', 'mody_cloud' ) . '</span> ';
				esc_html_e( 'Card payments open soon. You add your card here before your free months end, and nothing is charged before then. Without a card by then, your site becomes read-only for 60 days and is then deleted; you can download it at any time.', 'mody_cloud' );
			}
			return;
		}
		?>
		<form class="mody-dashboard__card-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( Billing::CARD_ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( Billing::CARD_ACTION ); ?>" />
			<?php if ( ! $has ) : ?>
				<p class="mody-brand__empty"><?php esc_html_e( 'None yet. Add your card before your free months end: nothing is charged before then, but a small card check.', 'mody_cloud' ); ?></p>
			<?php endif; ?>
			<p><label><input type="checkbox" name="accept" value="1" required /> <?php echo esc_html( Billing::terms( $user ) ); ?></label></p>
			<p><button type="submit" class="button<?php echo $has ? '' : ' button-primary'; ?>"><?php echo esc_html( $has ? __( 'Change card', 'mody_cloud' ) : __( 'Add your card', 'mody_cloud' ) ); ?></button>
			<?php if ( Mollie::test_mode() ) : ?>
				<span class="mody-dashboard__note"><?php esc_html_e( 'Test mode: no real money moves.', 'mody_cloud' ); ?></span>
			<?php endif; ?></p>
		</form>
		<?php
	}

	/**
	 * The 14-day withdrawal: the button EU law asks for, while it applies.
	 *
	 * @param int $user The member.
	 */
	private static function render_withdrawal( int $user ): void {
		$window = Billing::withdrawal( $user );
		if ( ! $window ) {
			return;
		}
		?>
		<form class="mody-dashboard__withdraw" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<h3><?php esc_html_e( 'Withdraw from the contract', 'mody_cloud' ); ?></h3>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: a date, 2: an amount. */
						__( 'Until %1$s you can withdraw without giving a reason and get your first payment, %2$s, back in full. Nothing more is charged, and your site stays online, read-only, for 60 days before it is deleted.', 'mody_cloud' ),
						wp_date( (string) get_option( 'date_format' ), $window['until'] ),
						self::price( (float) $window['row']['amount'] )
					)
				);
				?>
			</p>
			<?php wp_nonce_field( Billing::WITHDRAW_ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( Billing::WITHDRAW_ACTION ); ?>" />
			<p><label><input type="checkbox" name="confirm" value="1" required /> <?php esc_html_e( 'I withdraw from my contract.', 'mody_cloud' ); ?></label></p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Withdraw from the contract', 'mody_cloud' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Cancel at the end of the period, or, once cancelled, the date it ends
	 * and Keep my plan.
	 *
	 * @param int                  $user The member.
	 * @param array<string, mixed> $row  Their row.
	 */
	private static function render_cancel( int $user, array $row ): void {
		if ( ! empty( $row['cancel_at'] ) && 'active' === ( $row['status'] ?? '' ) ) {
			?>
			<form class="mody-dashboard__withdraw" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<h3><?php esc_html_e( 'Your plan ends soon', 'mody_cloud' ); ?></h3>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: a date, 2: days. */
							__( 'You cancelled, so nothing more is charged and your plan ends on %1$s. After that your site is read-only for %2$d days, then deleted.', 'mody_cloud' ),
							wp_date( (string) get_option( 'date_format' ), (int) strtotime( $row['cancel_at'] . ' UTC' ) ),
							\Mody\Platform\Billing\Lifecycle::DELETE_DAY
						)
					);
					?>
				</p>
				<?php wp_nonce_field( Billing::RESUME_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( Billing::RESUME_ACTION ); ?>" />
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Keep my plan', 'mody_cloud' ); ?></button></p>
			</form>
			<?php
			return;
		}
		if ( ! Billing::can_cancel( $user ) ) {
			return;
		}
		?>
		<details class="mody-dashboard__cancel">
			<summary><?php esc_html_e( 'Cancel your plan', 'mody_cloud' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: a date, 2: days. */
							__( 'Nothing more is charged, and your plan ends on %1$s, the end of what is already paid for (or of your free months). After that your site is read-only for %2$d days, then deleted; you can download it at any time.', 'mody_cloud' ),
							wp_date( (string) get_option( 'date_format' ), (int) strtotime( $row['billing_due_at'] . ' UTC' ) ),
							\Mody\Platform\Billing\Lifecycle::DELETE_DAY
						)
					);
					?>
				</p>
				<?php wp_nonce_field( Billing::CANCEL_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( Billing::CANCEL_ACTION ); ?>" />
				<p><label><input type="checkbox" name="confirm" value="1" required /> <?php esc_html_e( 'I cancel my plan at the end of the period.', 'mody_cloud' ); ?></label></p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Cancel my plan', 'mody_cloud' ); ?></button></p>
			</form>
		</details>
		<?php
	}

	/**
	 * The account's payments: charges, and card checks that went through.
	 *
	 * @param int $user The member.
	 */
	private static function render_payments( int $user ): void {
		$rows = array_filter( Payments::of( $user ), static fn( array $r ): bool => 'charge' === $r['kind'] || 'paid' === $r['status'] );
		if ( ! $rows ) {
			return;
		}
		$labels = array(
			'paid'     => __( 'Paid', 'mody_cloud' ),
			'failed'   => __( 'Failed', 'mody_cloud' ),
			'canceled' => __( 'Canceled', 'mody_cloud' ),
			'expired'  => __( 'Expired', 'mody_cloud' ),
		);
		?>
		<h3><?php esc_html_e( 'Payments', 'mody_cloud' ); ?></h3>
		<table class="mody-brand__rows mody-dashboard__payments">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'mody_cloud' ); ?></th>
					<th scope="col"><?php esc_html_e( 'For', 'mody_cloud' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'mody_cloud' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'mody_cloud' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $payment ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( (string) get_option( 'date_format' ), (int) strtotime( $payment['created_at'] . ' UTC' ) ) ); ?></td>
						<td>
							<?php
							echo esc_html(
								'card' === $payment['kind']
									? __( 'Card check', 'mody_cloud' )
									: sprintf(
										/* translators: 1: number of sites, 2: a date, 3: a date. */
										_n( '%1$d site, %2$s to %3$s', '%1$d sites, %2$s to %3$s', (int) $payment['sites'], 'mody_cloud' ),
										(int) $payment['sites'],
										wp_date( (string) get_option( 'date_format' ), (int) strtotime( $payment['covers_from'] . ' UTC' ) ),
										wp_date( (string) get_option( 'date_format' ), (int) strtotime( $payment['covers_to'] . ' UTC' ) )
									)
							);
							?>
						</td>
						<td><?php echo esc_html( self::price( (float) $payment['amount'] ) ); ?></td>
						<td>
							<?php
							$label = $labels[ $payment['status'] ] ?? __( 'Processing', 'mody_cloud' );
							if ( (float) ( $payment['charged_back'] ?? 0 ) > 0 ) {
								$label = __( 'Taken back by the bank', 'mody_cloud' );
							} elseif ( ! empty( $payment['refunded_at'] ) ) {
								$label = (float) ( $payment['refunded'] ?? 0 ) > 0 && (float) $payment['refunded'] < (float) $payment['amount']
									/* translators: %s: an amount. */
									? sprintf( __( 'Refunded %s', 'mody_cloud' ), self::price( (float) $payment['refunded'] ) )
									: __( 'Refunded', 'mody_cloud' );
							}
							echo esc_html( $label );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * admin-post `mody_dashboard_plan`: records how the member pays,
	 * then back to Plan & billing with the result.
	 */
	public static function save_plan(): void {
		check_admin_referer( self::PLAN_ACTION );
		$period = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : '';
		$result = Members::choose_period( get_current_user_id(), $period );
		wp_safe_redirect( self::url( 'plan', array( 'plan-result' => true === $result ? 'chosen' : 'error' ) ) );
		exit;
	}

	/**
	 * The change against the period before, such as "+12%", or '' when
	 * there is nothing to compare with.
	 *
	 * @param int $now    This period.
	 * @param int $before The period before.
	 */
	private static function change( int $now, int $before ): string {
		if ( $before <= 0 ) {
			return '';
		}
		$change = (int) round( 100 * ( $now - $before ) / $before );
		/* translators: %s: a signed percentage, such as +12. */
		return sprintf( __( '%s%% on the period before', 'mody_cloud' ), ( $change > 0 ? '+' : '' ) . number_format_i18n( $change ) );
	}

	/**
	 * One number tile.
	 *
	 * @param string $label  What it counts.
	 * @param string $value  The number, formatted.
	 * @param string $detail A line under it, or ''.
	 */
	private static function render_tile( string $label, string $value, string $detail ): void {
		?>
		<div class="mody-dashboard__tile">
			<span class="mody-dashboard__tile-label"><?php echo esc_html( $label ); ?></span>
			<span class="mody-dashboard__tile-value"><?php echo esc_html( $value ); ?></span>
			<?php if ( '' !== $detail ) : ?>
				<span class="mody-dashboard__tile-detail"><?php echo esc_html( $detail ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Visits per day: one bar each, the highest day's number on the scale,
	 * a tooltip per bar, and the same numbers as a table.
	 *
	 * @param array<string, array{views: int, visits: int}> $days    Day => counts.
	 * @param string                                         $caption The chart's caption; "Visits per day" by default.
	 */
	private static function render_chart( array $days, string $caption = '' ): void {
		$max    = max( 1, max( array_column( $days, 'visits' ) ) );
		$format = count( $days ) > 7 ? 'j M' : 'D j';
		$label  = static fn( string $day ): string => wp_date( $format, (int) strtotime( $day . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
		$keys   = array_keys( $days );
		?>
		<figure class="mody-dashboard__chart">
			<figcaption><?php echo esc_html( '' !== $caption ? $caption : __( 'Visits per day', 'mody_cloud' ) ); ?></figcaption>
			<div class="mody-dashboard__plot" aria-hidden="true">
				<span class="mody-dashboard__scale"><?php echo esc_html( number_format_i18n( $max ) ); ?></span>
				<div class="mody-dashboard__bars" style="--mody-days:<?php echo esc_attr( (string) count( $days ) ); ?>">
					<?php foreach ( $days as $day => $counts ) : ?>
						<?php
						/* translators: 1: a date, 2: visits, 3: page views. */
						$tip = sprintf( __( '%1$s: %2$s visits, %3$s page views', 'mody_cloud' ), $label( $day ), number_format_i18n( $counts['visits'] ), number_format_i18n( $counts['views'] ) );
						?>
						<span class="mody-dashboard__bar" data-tip="<?php echo esc_attr( $tip ); ?>"><span style="height:<?php echo esc_attr( (string) ( $counts['visits'] > 0 ? max( 2, round( 100 * $counts['visits'] / $max, 1 ) ) : 0 ) ); ?>%"></span></span>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="mody-dashboard__axis" aria-hidden="true">
				<span><?php echo esc_html( $label( (string) reset( $keys ) ) ); ?></span>
				<span><?php echo esc_html( $label( (string) end( $keys ) ) ); ?></span>
			</div>
			<details class="mody-brand__prompt">
				<summary><?php esc_html_e( 'Show as a table', 'mody_cloud' ); ?></summary>
				<table class="mody-brand__rows">
					<thead><tr><th scope="col"><?php esc_html_e( 'Day', 'mody_cloud' ); ?></th><th scope="col"><?php esc_html_e( 'Visits', 'mody_cloud' ); ?></th><th scope="col"><?php esc_html_e( 'Page views', 'mody_cloud' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( array_reverse( $days, true ) as $day => $counts ) : ?>
							<tr><th scope="row"><?php echo esc_html( $label( $day ) ); ?></th><td><?php echo esc_html( number_format_i18n( $counts['visits'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $counts['views'] ) ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		</figure>
		<?php
	}

	/**
	 * A top list: label, count, and a bar for its share of the first.
	 *
	 * @param string                                       $title  The list's title.
	 * @param string                                       $counts What the numbers count.
	 * @param array<int, array{value: string, count: int}> $rows   The rows, largest first.
	 * @param callable                                     $name   Value => the text shown.
	 */
	private static function render_list( string $title, string $counts, array $rows, callable $name ): void {
		$max = max( 1, (int) ( $rows[0]['count'] ?? 1 ) );
		?>
		<section class="mody-dashboard__list">
			<h3><span><?php echo esc_html( $title ); ?></span><span><?php echo esc_html( $counts ); ?></span></h3>
			<?php if ( ! $rows ) : ?>
				<p class="mody-brand__empty"><?php esc_html_e( 'Nothing yet', 'mody_cloud' ); ?></p>
			<?php else : ?>
				<ol>
					<?php foreach ( $rows as $row ) : ?>
						<li style="--mody-share:<?php echo esc_attr( (string) round( 100 * $row['count'] / $max, 1 ) ); ?>%">
							<span class="mody-dashboard__list-name"><?php echo esc_html( $name( $row['value'] ) ); ?></span>
							<span class="mody-dashboard__list-count"><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * The site's space: a bar and "0.2 GB of 1 GB used".
	 */
	private static function render_storage(): void {
		$usage = Storage::usage();
		if ( ! $usage['limited'] ) {
			/* translators: %s: space used, such as 1.2 GB. */
			echo esc_html( sprintf( __( '%s used. This site has no limit.', 'mody_cloud' ), size_format( $usage['used'], 1 ) ) );
			return;
		}
		$share = $usage['allowed'] > 0 ? min( 100, (int) round( 100 * $usage['used'] / $usage['allowed'] ) ) : 100;
		?>
		<span class="mody-dashboard__meter<?php echo $share >= 90 ? ' is-full' : ''; ?>">
			<?php
			/* translators: 1: space used, 2: space the site has, such as 1 GB. */
			echo esc_html( sprintf( __( '%1$s of %2$s used', 'mody_cloud' ), size_format( $usage['used'], $usage['used'] < MB_IN_BYTES ? 0 : 1 ), size_format( $usage['allowed'] ) ) );
			?>
			<span class="mody-brand__bar" role="img" aria-label="<?php echo esc_attr( $share . '%' ); ?>"><span style="width:<?php echo esc_attr( (string) max( 1, $share ) ); ?>%"></span></span>
		</span>
		<span class="mody-dashboard__note"><?php esc_html_e( 'Your uploads: images, videos, documents and fonts.', 'mody_cloud' ); ?></span>
		<?php
	}
}
