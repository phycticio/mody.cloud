<?php
/**
 * The Mody dashboard: every site's Dashboard.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Dashboard;

use Mody\Analytics\Stats;
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

	/** Sections still being built: a "Work in progress" watermark over them (the maintainer, 2026-11-11). */
	public const WORK_IN_PROGRESS = array( 'visitors', 'mody' );

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
			echo '<div class="notice notice-success inline is-dismissible mody-brand__notice"><p>' . esc_html__( 'Your plan is chosen. Nothing is charged yet.', 'mody_cloud' ) . '</p></div>';
		} elseif ( 'too-small' === $result ) {
			echo '<div class="notice notice-error inline mody-brand__notice"><p>' . esc_html__( 'That plan includes fewer sites than you have. Delete sites first, or choose a larger plan.', 'mody_cloud' ) . '</p></div>';
		} elseif ( '' !== $result ) {
			echo '<div class="notice notice-error inline mody-brand__notice"><p>' . esc_html__( 'The plan could not be chosen. Try again.', 'mody_cloud' ) . '</p></div>';
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
				/* translators: 1: sites used, 2: sites the plan includes. */
				echo esc_html( sprintf( _n( '%1$d of %2$d site used', '%1$d of %2$d sites used', $limit, 'mody_cloud' ), $used, $limit ) );
				?>
				<span class="mody-brand__bar" aria-hidden="true"><span style="width:<?php echo esc_attr( (string) min( 100, round( 100 * $used / max( 1, $limit ) ) ) ); ?>%"></span></span>
			</p>
			<?php if ( $can ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( network_admin_url( 'admin.php?page=' . WizardPage::SLUG ) ); ?>"><?php esc_html_e( 'New site', 'mody_cloud' ); ?></a>
			<?php else : ?>
				<span class="mody-dashboard__note">
					<?php esc_html_e( 'Every site of your plan is in use.', 'mody_cloud' ); ?>
					<a href="<?php echo esc_url( self::url( 'plan' ) ); ?>"><?php esc_html_e( 'See the plans', 'mody_cloud' ); ?></a>
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
	 * The billing period asked for on the plans: the query's, else the
	 * member's own.
	 *
	 * @param array<string, mixed> $row The member's row.
	 */
	private static function billing_period( array $row ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which prices to show.
		$period = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : (string) ( $row['billing_period'] ?? 'monthly' );
		return in_array( $period, Members::PERIODS, true ) ? $period : 'monthly';
	}

	/**
	 * A price: 9.99 EUR.
	 *
	 * @param float $eur Euros.
	 */
	private static function price( float $eur ): string {
		/* translators: %s: an amount in euros, such as 9.99. */
		return sprintf( __( '%s EUR', 'mody_cloud' ), number_format_i18n( $eur, 2 ) );
	}

	/**
	 * Plan & billing: the plan, the trial, what follows it, the payment, and
	 * the three plans to choose from.
	 */
	private static function render_plan(): void {
		$user   = get_current_user_id();
		$row    = (array) Members::get( $user );
		$plan   = (string) ( $row['plan'] ?? 'trial' );
		$next   = (string) ( $row['next_plan'] ?? '' );
		$period = self::billing_period( $row );
		$ends   = Members::trial_ends( $user );
		$due    = ! empty( $row['billing_due_at'] ) ? (int) strtotime( $row['billing_due_at'] . ' UTC' ) : 0;
		$used   = Quota::used( $user );
		$date   = static fn( int $time ): string => wp_date( get_option( 'date_format' ), $time );
		?>
		<table class="mody-brand__rows">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Your plan', 'mody_cloud' ); ?></th>
					<td>
						<?php
						if ( 'trial' === $plan ) {
							$left = max( 0, (int) ceil( ( $ends - time() ) / DAY_IN_SECONDS ) );
							echo esc_html(
								$ends
									/* translators: 1: sites, 2: days left, 3: a date. */
									? sprintf( _n( 'Free trial with %1$d sites: %2$d day left, until %3$s.', 'Free trial with %1$d sites: %2$d days left, until %3$s.', $left, 'mody_cloud' ), Members::PLANS['trial']['sites'], $left, $date( $ends ) )
									: __( 'Free trial', 'mody_cloud' )
							);
						} else {
							$paid = (string) ( $row['billing_period'] ?? 'monthly' );
							echo esc_html(
								sprintf(
									/* translators: 1: the plan, 2: its price, 3: monthly or yearly. */
									__( '%1$s, %2$s %3$s', 'mody_cloud' ),
									Members::plan_label( $plan ),
									self::price( (float) Members::PLANS[ $plan ][ $paid ] ),
									'yearly' === $paid ? __( 'a year', 'mody_cloud' ) : __( 'a month', 'mody_cloud' )
								)
							);
						}
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html( 'trial' === $plan ? __( 'After the trial', 'mody_cloud' ) : __( 'From the next payment', 'mody_cloud' ) ); ?></th>
					<td>
						<?php
						if ( '' === $next ) {
							echo '<span class="mody-brand__empty">' . esc_html( 'trial' === $plan ? __( 'No plan chosen yet. Choose one below.', 'mody_cloud' ) : __( 'The same plan', 'mody_cloud' ) ) . '</span>';
						} else {
							$paid = (string) ( $row['billing_period'] ?? 'monthly' );
							echo esc_html(
								sprintf(
									/* translators: 1: the plan, 2: its price, 3: monthly or yearly. */
									__( '%1$s, %2$s %3$s', 'mody_cloud' ),
									Members::plan_label( $next ),
									self::price( (float) Members::PLANS[ $next ][ $paid ] ),
									'yearly' === $paid ? __( 'a year', 'mody_cloud' ) : __( 'a month', 'mody_cloud' )
								)
							);
						}
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Next payment', 'mody_cloud' ); ?></th>
					<td>
						<?php
						if ( $due && '' !== $next ) {
							/* translators: 1: an amount, 2: a date. */
							echo esc_html( sprintf( __( '%1$s on %2$s', 'mody_cloud' ), self::price( (float) Members::PLANS[ $next ][ (string) ( $row['billing_period'] ?? 'monthly' ) ] ), $date( $due ) ) );
						} elseif ( $due ) {
							/* translators: %s: a date. */
							echo esc_html( sprintf( __( 'On %s, once you choose a plan', 'mody_cloud' ), $date( $due ) ) );
						} else {
							echo '<span class="mody-brand__empty">' . esc_html__( 'None planned', 'mody_cloud' ) . '</span>';
						}
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Payment method', 'mody_cloud' ); ?></th>
					<td>
						<?php if ( empty( $row['mollie_payment_method_id'] ) ) : ?>
							<span class="mody-brand__empty"><?php esc_html_e( 'None yet.', 'mody_cloud' ); ?></span>
							<?php esc_html_e( 'Card payments open soon: you add your card here before your trial ends, and nothing is charged before then.', 'mody_cloud' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'A card is on file.', 'mody_cloud' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<div class="mody-dashboard__plans-head">
			<h3><?php echo esc_html( 'trial' === $plan ? __( 'Choose your plan', 'mody_cloud' ) : __( 'Change your plan', 'mody_cloud' ) ); ?></h3>
			<nav class="mody-brand__tabs" aria-label="<?php esc_attr_e( 'Billing period', 'mody_cloud' ); ?>">
				<?php
				foreach ( array(
					'monthly' => __( 'Monthly', 'mody_cloud' ),
					'yearly'  => __( 'Yearly', 'mody_cloud' ),
				) as $slug => $label ) :
					?>
					<a href="<?php echo esc_url( self::url( 'plan', array( 'period' => $slug ) ) ); ?>" class="mody-brand__tab<?php echo $slug === $period ? ' is-active' : ''; ?>" <?php echo $slug === $period ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
		</div>
		<ul class="mody-dashboard__plans">
			<?php foreach ( Members::PAID_PLANS as $slug ) : ?>
				<?php
				$sites  = (int) Members::PLANS[ $slug ]['sites'];
				$chosen = $slug === $next && $period === (string) ( $row['billing_period'] ?? '' );
				$small  = $used > $sites;
				?>
				<li class="mody-dashboard__plan<?php echo $chosen ? ' is-chosen' : ''; ?>">
					<p class="mody-dashboard__plan-name"><?php echo esc_html( Members::plan_label( $slug ) ); ?></p>
					<p class="mody-dashboard__plan-price">
						<?php echo esc_html( self::price( (float) Members::PLANS[ $slug ][ $period ] ) ); ?>
						<span><?php echo esc_html( 'yearly' === $period ? __( 'a year', 'mody_cloud' ) : __( 'a month', 'mody_cloud' ) ); ?></span>
					</p>
					<p class="mody-dashboard__note">
						<?php
						echo esc_html(
							'yearly' === $period
								/* translators: %s: an amount saved. */
								? sprintf( __( 'You save %s on twelve monthly payments.', 'mody_cloud' ), self::price( Members::yearly_saving( $slug ) ) )
								: __( 'Cancel at the end of any month.', 'mody_cloud' )
						);
						?>
					</p>
					<ul class="mody-dashboard__plan-features">
						<li>
							<?php
							/* translators: %d: sites. */
							echo esc_html( sprintf( _n( '%d site, each with its own Mody', '%d sites, each with its own Mody', $sites, 'mody_cloud' ), $sites ) );
							?>
						</li>
						<li><?php esc_html_e( '3 GB of space per site', 'mody_cloud' ); ?></li>
					</ul>
					<?php if ( $chosen ) : ?>
						<p class="mody-brand__badge is-filled"><?php esc_html_e( 'Your choice', 'mody_cloud' ); ?></p>
					<?php elseif ( $small ) : ?>
						<p class="mody-dashboard__note">
							<?php
							/* translators: %d: sites in use. */
							echo esc_html( sprintf( __( 'You have %d sites: delete some first to choose this plan.', 'mody_cloud' ), $used ) );
							?>
						</p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( self::PLAN_ACTION ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( self::PLAN_ACTION ); ?>" />
							<input type="hidden" name="plan" value="<?php echo esc_attr( $slug ); ?>" />
							<input type="hidden" name="period" value="<?php echo esc_attr( $period ); ?>" />
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Choose', 'mody_cloud' ); ?></button>
						</form>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="mody-dashboard__note"><?php esc_html_e( 'Every plan has the same features; plans differ only in the number of sites. Prices in euros.', 'mody_cloud' ); ?></p>
		<?php
	}

	/**
	 * admin-post `mody_dashboard_plan`: records the member's chosen plan,
	 * then back to Plan & billing with the result.
	 */
	public static function save_plan(): void {
		check_admin_referer( self::PLAN_ACTION );
		$user   = get_current_user_id();
		$plan   = isset( $_POST['plan'] ) ? sanitize_key( wp_unslash( $_POST['plan'] ) ) : '';
		$period = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : '';
		$result = Members::choose_plan( $user, $plan, $period );
		$code   = true === $result ? 'chosen' : ( 'mody_plan_too_small' === $result->get_error_code() ? 'too-small' : 'error' );
		wp_safe_redirect( self::url( 'plan', array( 'plan-result' => $code, 'period' => $period ) ) );
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
	 * The site's space: a bar and "1.2 GB of 3 GB used".
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
			/* translators: 1: space used, 2: space the site has, such as 3 GB. */
			echo esc_html( sprintf( __( '%1$s of %2$s used', 'mody_cloud' ), size_format( $usage['used'], $usage['used'] < MB_IN_BYTES ? 0 : 1 ), size_format( $usage['allowed'] ) ) );
			?>
			<span class="mody-brand__bar" role="img" aria-label="<?php echo esc_attr( $share . '%' ); ?>"><span style="width:<?php echo esc_attr( (string) max( 1, $share ) ); ?>%"></span></span>
		</span>
		<span class="mody-dashboard__note"><?php esc_html_e( 'Your uploads: images, videos, documents and fonts.', 'mody_cloud' ); ?></span>
		<?php
	}
}
