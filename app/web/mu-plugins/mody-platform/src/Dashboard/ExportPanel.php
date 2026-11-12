<?php
/**
 * The dashboard's Download your site section.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Dashboard;

use Mody\Platform\Export\Downloads;
use Mody\Platform\Export\Exporter;
use Mody\Platform\Export\Jobs;
use Mody\Platform\Export\Notices;
use Mody\Platform\Export\Runner;

defined( 'ABSPATH' ) || exit;

/**
 * What the owner sees of a site download (tickets/take-your-site-with-you.md,
 * asked for by the maintainer on 2026-11-12): one button, no confirmation, no
 * questions, whatever the plan or billing status. Then the steps while the
 * three files are built, the three downloads, and an honest list of what
 * comes along and what stays with Mody Cloud.
 *
 * Only on member sites, and only for their administrators (`manage_options`):
 * the main site is the platform's own. The builder (Export\Runner and
 * Export\Exporter) runs apart from the request; this class asks for a job,
 * shows it, and answers the page's status poll.
 */
final class ExportPanel {

	/** The dashboard section's slug: admin.php?page=mody-dashboard&section=export. */
	public const SECTION = 'export';

	/** The admin-post action behind Prepare my download. */
	public const ACTION = 'mody_export_request';

	/** The admin-ajax action the page polls while a job is queued or running. */
	public const STATUS_ACTION = 'mody_export_status';

	/** How often the page asks, in milliseconds. */
	public const POLL_MS = 3000;

	/**
	 * A site may ask for a new copy this many seconds after its last finished
	 * copy was asked for (security pass, 2026-11-12). A copy zips the whole 3 GB
	 * and stays for three days outside the site's space, and the network builds
	 * only Jobs::MAX_RUNNING at a time: without a pause, a few sites asking in
	 * a loop would keep every builder busy and fill the disk. Only a copy that
	 * is done counts: after a failed one, Try again works at once, because there
	 * are no files to keep. Filter `mody_export_cooldown` changes it (tests set
	 * it to 0). Super administrators helping a member are not held back.
	 */
	public const COOLDOWN = 15 * MINUTE_IN_SECONDS;

	/** How many left-out settings the page names; the rest are counted. */
	private const LEFT_OUT_SHOWN = 10;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_request' ) );
		add_action( 'wp_ajax_' . self::STATUS_ACTION, array( self::class, 'handle_status' ) );
	}

	/**
	 * Whether this user, on this site, may download it: an administrator of a
	 * member site. Super administrators pass as on every site.
	 */
	public static function available(): bool {
		return ! is_main_site() && current_user_can( DashboardPage::SITE_CAPABILITY );
	}

	/**
	 * The section's address on this site, for the links to it.
	 */
	public static function url(): string {
		return DashboardPage::url( self::SECTION );
	}

	/**
	 * `load-<dashboard page>`: the status script, on this section only.
	 */
	public static function enqueue(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which section is open.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		if ( self::SECTION !== $section || ! self::available() ) {
			return;
		}
		$root = dirname( __DIR__, 2 );
		wp_enqueue_script( 'mody-export-panel', plugins_url( 'assets/export-panel.js', $root . '/mody-platform.php' ), array(), (string) filemtime( $root . '/assets/export-panel.js' ), true );
	}

	/**
	 * The steps' names, in the order the builder takes them.
	 *
	 * @return array<string, string>
	 */
	private static function step_labels(): array {
		return array(
			'database' => __( 'Database', 'mody_cloud' ),
			'media'    => __( 'Media', 'mody_cloud' ),
			'code'     => __( 'Code', 'mody_cloud' ),
		);
	}

	/**
	 * The three files, as the owner reads them: name, and what is in it.
	 *
	 * @return array<string, array{label: string, about: string}>
	 */
	private static function file_labels(): array {
		return array(
			'code'     => array(
				'label' => __( 'Code', 'mody_cloud' ),
				'about' => __( 'Your theme and plugins, and README-restore.txt with the steps to restore your site.', 'mody_cloud' ),
			),
			'media'    => array(
				'label' => __( 'Media', 'mody_cloud' ),
				'about' => __( 'Every image, video, document and font you uploaded.', 'mody_cloud' ),
			),
			'database' => array(
				'label' => __( 'Database', 'mody_cloud' ),
				'about' => __( 'Your pages, posts, menus, settings and users, without anyone\'s password.', 'mody_cloud' ),
			),
		);
	}

	/**
	 * How the restore is explained, in one place. The Mody Import plugin
	 * (phase 3 of the ticket) will turn this into three one-click steps; until
	 * a restore has been drilled on a clean WordPress, the page does not
	 * promise them (rule 7).
	 *
	 * @return array{title: string, intro: string, steps: string[]}
	 */
	public static function restore_steps(): array {
		return array(
			'title' => __( 'Putting your site back together', 'mody_cloud' ),
			'intro' => __( 'A one-click restore is coming. For now, the code file holds README-restore.txt, which lists the steps to restore your site by hand.', 'mody_cloud' ),
			'steps' => array(
				__( 'Install a fresh WordPress (7.1 or newer, PHP 8.1 or newer) on your new host.', 'mody_cloud' ),
				__( 'Open the code file: it holds your theme, your plugins and README-restore.txt.', 'mody_cloud' ),
				__( 'Follow README-restore.txt to put the code, the media and the database in place.', 'mody_cloud' ),
			),
		);
	}

	/**
	 * What comes along with the files.
	 *
	 * @return string[]
	 */
	private static function comes_with(): array {
		return array(
			__( 'Your pages and posts, drafts included', 'mody_cloud' ),
			__( 'Your media: images, videos and other files', 'mody_cloud' ),
			__( 'Your menus, templates and styles (colors, fonts and layout)', 'mody_cloud' ),
			__( 'The fonts you installed', 'mody_cloud' ),
			__( 'Your languages and their translations', 'mody_cloud' ),
			__( 'Your users, without their passwords: they choose a new one with "Lost password"', 'mody_cloud' ),
			__( 'Your SEO titles and descriptions, and your login page', 'mody_cloud' ),
			__( 'Your theme, and the plugins your site runs on (Spectra and Mody\'s site tools), as ordinary plugins you can update or switch off', 'mody_cloud' ),
		);
	}

	/**
	 * What stays with Mody Cloud.
	 *
	 * @return string[]
	 */
	private static function stays(): array {
		return array(
			__( 'Mody, the AI agent, and your conversations with it', 'mody_cloud' ),
			__( 'Your plan and billing', 'mody_cloud' ),
			__( 'The antibot check on sign-in forms (Antispam Bee still checks comments)', 'mody_cloud' ),
			__( 'The visitor counter', 'mody_cloud' ),
			__( 'The email sender: your new host sends your site\'s email', 'mody_cloud' ),
			__( 'Passwords, API keys and anything else secret', 'mody_cloud' ),
		);
	}

	/**
	 * A job as the page shows it and the status poll returns it: what to
	 * say, which step it is on, how far, and when done the three downloads.
	 *
	 * @param array|null $job A job (Jobs::current()), or null for none.
	 * @return array{status: string, step: string, steps: array<int, array{key: string, label: string, state: string}>, percent: int|null, percent_label: string, message: string, retrying: bool, files: array<int, array{key: string, label: string, name: string, size: int, size_label: string, url: string}>, kept_until: string, stripped: string[]}
	 */
	public static function view( ?array $job ): array {
		if ( $job && 'done' === $job['status'] && self::lapsed( $job ) ) {
			$job = null; // Its files are past their three days (the sweep deletes them): as if there were none.
		}
		$status = $job ? (string) $job['status'] : 'none';
		if ( 'expired' === $status ) {
			$status = 'none';
		}
		$step     = $job ? (string) $job['step'] : '';
		$at       = array_search( $step, Jobs::FILES, true );
		$retrying = 'queued' === $status && $job && $job['attempts'] > 0;

		$steps = array();
		foreach ( self::step_labels() as $key => $label ) {
			$position = (int) array_search( $key, Jobs::FILES, true );
			if ( 'done' === $status ) {
				$state = 'done';
			} elseif ( false === $at || ! in_array( $status, array( 'queued', 'running' ), true ) ) {
				$state = 'todo';
			} elseif ( $position < $at ) {
				$state = 'done';
			} else {
				$state = 'running' === $status && $position === $at ? 'current' : 'todo';
			}
			$steps[] = array(
				'key'   => $key,
				'label' => $label,
				'state' => $state,
			);
		}

		$percent = null;
		if ( 'running' === $status && $job && ( $job['progress']['total'] ?? 0 ) > 0 ) {
			$percent = (int) max( 0, min( 100, floor( 100 * (int) $job['progress']['done'] / (int) $job['progress']['total'] ) ) );
		}

		$files    = array();
		$kept     = '';
		$stripped = array();
		if ( 'done' === $status && $job ) {
			foreach ( self::file_labels() as $key => $text ) {
				if ( empty( $job['files'][ $key ] ) ) {
					continue;
				}
				$size    = (int) ( $job['files'][ $key ]['size'] ?? 0 );
				$files[] = array(
					'key'        => $key,
					'label'      => $text['label'],
					'name'       => (string) ( $job['files'][ $key ]['name'] ?? '' ),
					'size'       => $size,
					'size_label' => size_format( $size, $size < MB_IN_BYTES ? 0 : 1 ),
					'url'        => Downloads::url( $job, $key ),
				);
			}
			$kept     = self::kept_until( $job );
			$stripped = self::left_out( $job );
		}

		return array(
			'status'        => $status,
			'step'          => $step,
			'steps'         => $steps,
			'percent'       => $percent,
			'percent_label' => null === $percent ? '' : $percent . '%',
			'message'       => self::message( $status, $step, $retrying ),
			'retrying'      => $retrying,
			'files'         => $files,
			'kept_until'    => $kept,
			'stripped'      => $stripped,
		);
	}

	/**
	 * The settings the database file left out because they look like a
	 * password or a key (the builder records them in the file's entry).
	 *
	 * @param array $job A finished job.
	 * @return string[] Setting names.
	 */
	private static function left_out( array $job ): array {
		$names = array();
		foreach ( (array) ( $job['files']['database']['stripped'] ?? array() ) as $name ) {
			if ( is_string( $name ) && '' !== trim( $name ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/**
	 * Whether a finished job's files are past their time: the same rule as
	 * Downloads::resolve(), which refuses every link from then on. A job
	 * with no finishing time (Jobs::finish() always sets one) is not lapsed.
	 *
	 * @param array $job A job.
	 */
	private static function lapsed( array $job ): bool {
		$finished = '' !== $job['finished_at'] ? (int) strtotime( $job['finished_at'] . ' UTC' ) : 0;
		return $finished > 0 && $finished + Jobs::KEEP_FOR < time();
	}

	/**
	 * The sentence for a state.
	 *
	 * @param string $status   The job's status, or 'none'.
	 * @param string $step     The job's step.
	 * @param bool   $retrying A queued job that has been tried before.
	 */
	private static function message( string $status, string $step, bool $retrying ): string {
		switch ( $status ) {
			case 'queued':
				return $retrying
					? __( 'Something interrupted the first try, so Mody Cloud is starting again. Nothing is needed from you.', 'mody_cloud' )
					: __( 'Waiting for its turn. Mody Cloud prepares a few downloads at a time, and yours starts as soon as there is room.', 'mody_cloud' );
			case 'running':
				return array(
					'database' => __( 'Packing your pages, posts, menus and settings…', 'mody_cloud' ),
					'media'    => __( 'Packing your images, videos and other files…', 'mody_cloud' ),
					'code'     => __( 'Packing your theme and plugins…', 'mody_cloud' ),
				)[ $step ] ?? __( 'Preparing your download…', 'mody_cloud' );
			case 'done':
				return __( 'Your download is ready.', 'mody_cloud' );
			case 'failed':
				return __( 'Your download could not be prepared this time. Your site is untouched. Please try again.', 'mody_cloud' );
		}
		return '';
	}

	/**
	 * When the finished files are deleted, in the site's own time zone.
	 *
	 * @param array $job A finished job.
	 */
	private static function kept_until( array $job ): string {
		$finished = '' !== $job['finished_at'] ? (int) strtotime( $job['finished_at'] . ' UTC' ) : 0;
		if ( ! $finished ) {
			return '';
		}
		$until = $finished + Jobs::KEEP_FOR;
		/* translators: 1: a date, 2: a time, such as "15 November 2026 at 14:30". */
		return sprintf( __( '%1$s at %2$s', 'mody_cloud' ), wp_date( get_option( 'date_format' ), $until ), wp_date( get_option( 'time_format' ), $until ) );
	}

	/**
	 * Renders the section: what it is, the state of the download, what comes
	 * along and what stays, and how to restore.
	 */
	public static function render(): void {
		if ( ! self::available() ) {
			return;
		}
		self::keep_moving(); // Opening the section is enough to move a waiting or stalled job, and to delete old files.
		$job  = Jobs::current( get_current_blog_id() );
		$view = self::view( $job );
		$live = in_array( $view['status'], array( 'queued', 'running' ), true );
		$data = $live
			? array(
				'data-ajax-url' => admin_url( 'admin-ajax.php' ),
				'data-action'   => self::STATUS_ACTION,
				'data-nonce'    => wp_create_nonce( self::STATUS_ACTION ),
				'data-interval' => (string) self::POLL_MS,
			)
			: array();
		$attrs = '';
		foreach ( $data as $name => $value ) {
			$attrs .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}
		?>
		<div class="mody-export" data-status="<?php echo esc_attr( $view['status'] ); ?>"<?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Names are literals, values escaped above. ?>>
			<?php self::render_notice( $view['status'] ); ?>
			<p class="mody-export__intro">
				<?php esc_html_e( 'Your whole site in three files: the code (your theme and plugins), the media (everything you uploaded) and the database (your pages, posts, menus, settings and users).', 'mody_cloud' ); ?>
			</p>
			<p class="mody-export__intro">
				<?php esc_html_e( 'Mody, the AI agent, stays here: it does not come with the files. Everything else is yours to take anywhere.', 'mody_cloud' ); ?>
			</p>

			<div class="mody-export__box" aria-live="polite">
				<?php
				switch ( $view['status'] ) {
					case 'queued':
					case 'running':
						self::render_progress( $view );
						break;
					case 'done':
						self::render_done( $view );
						break;
					case 'failed':
						self::render_failed( $view );
						break;
					default:
						self::render_start();
				}
				?>
			</div>

			<div class="mody-export__lists">
				<section class="mody-export__list">
					<h3><?php esc_html_e( 'What comes with it', 'mody_cloud' ); ?></h3>
					<ul>
						<?php foreach ( self::comes_with() as $item ) : ?>
							<li><?php echo esc_html( $item ); ?></li>
						<?php endforeach; ?>
					</ul>
				</section>
				<section class="mody-export__list">
					<h3><?php esc_html_e( 'What stays with Mody Cloud', 'mody_cloud' ); ?></h3>
					<ul>
						<?php foreach ( self::stays() as $item ) : ?>
							<li><?php echo esc_html( $item ); ?></li>
						<?php endforeach; ?>
					</ul>
				</section>
			</div>

			<?php $restore = self::restore_steps(); ?>
			<section class="mody-export__restore">
				<h3><?php echo esc_html( $restore['title'] ); ?></h3>
				<p><?php echo esc_html( $restore['intro'] ); ?></p>
				<ol>
					<?php foreach ( $restore['steps'] as $step ) : ?>
						<li><?php echo esc_html( $step ); ?></li>
					<?php endforeach; ?>
				</ol>
			</section>
		</div>
		<?php
	}

	/**
	 * The result of the last request, from the redirect's query, or the note
	 * for a download link that no longer works (Downloads sends the owner here
	 * with `export_link=expired`).
	 *
	 * @param string $status The section's state (view()['status']).
	 */
	private static function render_notice( string $status ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only: the result of the last request.
		$result  = isset( $_GET['export-result'] ) ? sanitize_key( wp_unslash( $_GET['export-result'] ) ) : '';
		$expired = isset( $_GET[ Downloads::EXPIRED_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ Downloads::EXPIRED_ARG ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 'started' === $result ) {
			echo '<div class="notice notice-success inline mody-brand__notice"><p>' . esc_html__( 'Started. We will email you when your download is ready: you do not need to stay on this page.', 'mody_cloud' ) . '</p></div>';
		} elseif ( 'busy' === $result ) {
			echo '<div class="notice notice-info inline mody-brand__notice"><p>' . esc_html__( 'A download of this site is already being prepared.', 'mody_cloud' ) . '</p></div>';
		} elseif ( 'cooldown' === $result ) {
			$left = self::cooldown_left( Jobs::current( get_current_blog_id() ) );
			echo '<div class="notice notice-info inline mody-brand__notice"><p>' . esc_html(
				$left > 0
					? sprintf(
						/* translators: 1: a time of day, 2: how long from now, such as "9 minutes". */
						__( 'A copy of this site was prepared a few minutes ago, and the files above are the latest. To keep downloads quick for everyone, you can prepare a fresh copy from %1$s (in %2$s).', 'mody_cloud' ),
						wp_date( get_option( 'time_format' ), time() + $left ),
						human_time_diff( time(), time() + $left )
					)
					: __( 'You can prepare a fresh copy now.', 'mody_cloud' )
			) . '</p></div>';
		} elseif ( '' !== $result ) {
			echo '<div class="notice notice-error inline mody-brand__notice"><p>' . esc_html__( 'The download could not be started. Please try again.', 'mody_cloud' ) . '</p></div>';
		} elseif ( 'expired' === $expired ) {
			if ( 'done' === $status ) {
				$text = __( 'That download link has expired. The buttons below are new, and they work.', 'mody_cloud' );
			} elseif ( in_array( $status, array( 'queued', 'running' ), true ) ) {
				$text = __( 'That download link has expired. A new download is being prepared: it will be here when it is ready.', 'mody_cloud' );
			} else {
				$text = __( 'That download link has expired, and its files are no longer kept. You can prepare a fresh copy below.', 'mody_cloud' );
			}
			echo '<div class="notice notice-info inline mody-brand__notice"><p>' . esc_html( $text ) . '</p></div>';
		}
	}

	/**
	 * The one form: a button that asks for a download, nothing else.
	 *
	 * @param string $label   The button.
	 * @param bool   $primary Whether it is the main button.
	 */
	private static function render_form( string $label, bool $primary ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mody-export__form">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<button type="submit" class="button<?php echo $primary ? ' button-primary button-hero' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * No download yet: the button.
	 */
	private static function render_start(): void {
		self::render_form( __( 'Prepare my download', 'mody_cloud' ), true );
		?>
		<p class="mody-dashboard__note"><?php esc_html_e( 'You can ask at any time, whatever your plan. Preparing takes a few minutes, longer for a big site: we email you when it is ready, and nothing changes on your site meanwhile.', 'mody_cloud' ); ?></p>
		<?php
	}

	/**
	 * Queued or running: the steps, the bar and what it is doing. The page's
	 * script keeps these up to date (assets/export-panel.js).
	 *
	 * @param array $view view().
	 */
	private static function render_progress( array $view ): void {
		$bar = null === $view['percent'] ? ' is-indeterminate' : '';
		?>
		<div class="mody-export__progress">
			<ol class="mody-export__steps" data-role="steps">
				<?php foreach ( $view['steps'] as $step ) : ?>
					<li class="is-<?php echo esc_attr( $step['state'] ); ?>" data-step="<?php echo esc_attr( $step['key'] ); ?>"<?php echo 'current' === $step['state'] ? ' aria-current="step"' : ''; ?>><?php echo esc_html( $step['label'] ); ?></li>
				<?php endforeach; ?>
			</ol>
			<p class="mody-export__message" data-role="message"><?php echo esc_html( $view['message'] ); ?></p>
			<div class="mody-export__meter">
				<span class="mody-brand__bar mody-export__bar<?php echo esc_attr( $bar ); ?>" data-role="bar" role="progressbar" aria-label="<?php esc_attr_e( 'Progress of this step', 'mody_cloud' ); ?>" aria-valuemin="0" aria-valuemax="100"<?php echo null === $view['percent'] ? '' : ' aria-valuenow="' . esc_attr( (string) $view['percent'] ) . '"'; ?>>
					<span style="width:<?php echo esc_attr( null === $view['percent'] ? '100' : (string) $view['percent'] ); ?>%"></span>
				</span>
				<span class="mody-export__percent" data-role="percent"><?php echo esc_html( $view['percent_label'] ); ?></span>
			</div>
			<p class="mody-dashboard__note"><?php esc_html_e( 'You can leave this page: we will email you when it is ready.', 'mody_cloud' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Done: the three downloads, how long they are kept, and a fresh copy.
	 *
	 * @param array $view view().
	 */
	private static function render_done( array $view ): void {
		?>
		<p class="mody-export__message"><?php echo esc_html( $view['message'] ); ?></p>
		<ul class="mody-export__files">
			<?php foreach ( $view['files'] as $file ) : ?>
				<?php $about = self::file_labels()[ $file['key'] ]['about']; ?>
				<li class="mody-export__file">
					<p class="mody-export__file-name"><?php echo esc_html( $file['label'] ); ?> <span><?php echo esc_html( $file['size_label'] ); ?></span></p>
					<p class="mody-dashboard__note"><?php echo esc_html( $about ); ?></p>
					<p class="mody-export__file-file"><?php echo esc_html( $file['name'] ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( $file['url'] ); ?>"><?php esc_html_e( 'Download', 'mody_cloud' ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php self::render_left_out( $view['stripped'] ); ?>
		<p class="mody-dashboard__note">
			<?php
			if ( '' !== $view['kept_until'] ) {
				/* translators: %s: a date and time. */
				echo esc_html( sprintf( __( 'These files are kept until %s, then deleted. A download that stops can be resumed.', 'mody_cloud' ), $view['kept_until'] ) );
			}
			?>
		</p>
		<?php
		self::render_form( __( 'Prepare a fresh copy', 'mody_cloud' ), false );
	}

	/**
	 * The settings left out of the database file, said kindly: they look like
	 * a password or a key, so nothing secret travels in the file, and the owner
	 * enters them again on the new host.
	 *
	 * @param string[] $names Setting names (view()['stripped']).
	 */
	private static function render_left_out( array $names ): void {
		if ( ! $names ) {
			return;
		}
		$shown = array_slice( $names, 0, self::LEFT_OUT_SHOWN );
		$more  = count( $names ) - count( $shown );
		$list  = implode( ', ', array_map( static fn( string $name ): string => '<code>' . esc_html( $name ) . '</code>', $shown ) );
		if ( $more > 0 ) {
			/* translators: %d: how many more settings. */
			$list .= ' ' . esc_html( sprintf( __( 'and %d more', 'mody_cloud' ), $more ) );
		}
		$text = sprintf(
			_n(
				'A setting that looks like a password or a key was left out of your database file, so nothing secret travels in it: %s. Please enter it again on your new host.',
				'Some settings that look like passwords or keys were left out of your database file, so nothing secret travels in it: %s. Please enter them again on your new host.',
				count( $names ),
				'mody_cloud'
			),
			$list
		);
		echo '<p class="mody-export__left-out">' . wp_kses( $text, array( 'code' => array() ) ) . '</p>';
	}

	/**
	 * Failed: a plain sentence and another go.
	 *
	 * @param array $view view().
	 */
	private static function render_failed( array $view ): void {
		?>
		<p class="mody-export__message is-failed"><?php echo esc_html( $view['message'] ); ?></p>
		<?php
		self::render_form( __( 'Try again', 'mody_cloud' ), true );
	}

	/**
	 * Asks for a download for a site: a job, the emails, the builder. A
	 * download already being prepared is not started twice, nor announced
	 * twice.
	 *
	 * The job goes through Runner::watch(), not straight to a builder, so the
	 * network never builds more than Jobs::MAX_RUNNING at once: a request made
	 * when the network is full waits its turn.
	 *
	 * Whose job it is (the person who gets the "ready" email, and the owner the
	 * exported database names): the one who asked, unless that is a super
	 * administrator who is not an administrator of the site (the maintainer,
	 * helping a member). The export leaves super administrators out of the
	 * database, so such a job is recorded for the site's first real
	 * administrator, by ID, and the emails say that Mody Cloud asked.
	 *
	 * @param int $site_id Site.
	 * @param int $user_id Who asked.
	 * @return string 'started', 'busy' (one is already queued or running), 'cooldown' (the last copy is done and was asked for a moment ago) or 'error'.
	 */
	public static function start( int $site_id, int $user_id ): string {
		global $wpdb;
		$owner = self::owner_for( $site_id, $user_id );

		// Two requests together (a double click) take turns, so only one creates the job and only one announces it.
		$lock = 'mody_export_panel_' . $site_id;
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 5 )', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A named lock.
		if ( null !== $got && 1 !== (int) $got ) {
			return 'busy'; // Another request is creating this site's job right now.
		}
		try {
			$live = Jobs::current( $site_id );
			if ( ! is_super_admin( $user_id ) && self::cooldown_left( $live ) > 0 ) {
				return 'cooldown'; // The lock is released below; no job, no email.
			}
			$id = Jobs::create( $site_id, $owner );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A named lock.
		}
		if ( ! $id ) {
			return 'error';
		}
		if ( $live && $live['id'] === $id ) {
			return 'busy'; // Jobs::create() gave back the live job: nothing was created, so nothing is announced.
		}
		// The administrators hear first, so the "ready" email cannot arrive before the "being prepared" one on a small site.
		self::quietly( static fn() => Notices::started( $id, $user_id ) );
		self::quietly( array( Runner::class, 'watch' ) );
		return 'started';
	}

	/**
	 * Seconds until a site may ask for another copy, counted from when its
	 * last copy was asked for; 0 when it may now. Only a finished copy holds
	 * a site back: a queued or running one is "busy" (Jobs::create() hands it
	 * back), and a failed one has no files, so asking again is fine.
	 *
	 * @param array|null $job The site's latest job (Jobs::current()).
	 */
	private static function cooldown_left( ?array $job ): int {
		if ( ! $job || 'done' !== $job['status'] ) {
			return 0;
		}
		/**
		 * Filters how long a site waits between two copies, in seconds.
		 *
		 * @param int $seconds COOLDOWN.
		 */
		$cooldown = max( 0, (int) apply_filters( 'mody_export_cooldown', self::COOLDOWN ) );
		$asked    = strtotime( $job['created_at'] . ' UTC' ); // The table keeps UTC.
		return $asked ? max( 0, $asked + $cooldown - time() ) : 0;
	}

	/**
	 * Who a job is recorded for: the person who asked, or, for a super
	 * administrator who is not an administrator of the site, the site's first
	 * administrator (lowest ID). See start().
	 *
	 * @param int $site_id Site.
	 * @param int $user_id Who asked.
	 */
	private static function owner_for( int $site_id, int $user_id ): int {
		if ( ! is_super_admin( $user_id ) ) {
			return $user_id;
		}
		$admins = array_map(
			'intval',
			get_users(
				array(
					'blog_id' => $site_id,
					'role'    => 'administrator',
					'orderby' => 'ID',
					'order'   => 'ASC',
					'fields'  => 'ID',
				)
			)
		);
		return ! $admins || in_array( $user_id, $admins, true ) ? $user_id : $admins[0];
	}

	/**
	 * Keeps the downloads moving, from any visit to this section: a job that
	 * waits for room, or whose builder went quiet, is started again, and files
	 * past their three days are deleted. Both are cheap (the sweep runs at most
	 * hourly). Nothing here may break the page.
	 */
	private static function keep_moving(): void {
		self::quietly( array( Exporter::class, 'sweep' ) );
		self::quietly( array( Runner::class, 'watch' ) );
	}

	/**
	 * Runs a step that must never stop the request: a failed email or a
	 * builder that cannot be started leaves the job queued, and the next
	 * visit or the next build's end starts it.
	 *
	 * @param callable $step The step.
	 */
	private static function quietly( callable $step ): void {
		try {
			$step();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * admin-post `mody_export_request`: Prepare my download. No question and
	 * no confirmation: back to the section, which shows the job.
	 */
	public static function handle_request(): void {
		check_admin_referer( self::ACTION );
		if ( ! self::available() ) {
			wp_die( esc_html__( 'You are not allowed to download this site.', 'mody_cloud' ), '', array( 'response' => 403 ) );
		}
		self::keep_moving(); // Old files go before a new copy is built, and a stalled job is nudged.
		$result = self::start( get_current_blog_id(), get_current_user_id() );
		wp_safe_redirect( add_query_arg( 'export-result', $result, self::url() ) );
		exit;
	}

	/**
	 * admin-ajax `mody_export_status`: the page's poll, every few seconds
	 * while a job is queued or running. Like opening the section, it keeps
	 * jobs moving (keep_moving()).
	 */
	public static function handle_status(): void {
		if ( ! check_ajax_referer( self::STATUS_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'This page has expired. Reload it.', 'mody_cloud' ) ), 403 );
		}
		if ( ! self::available() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to download this site.', 'mody_cloud' ) ), 403 );
		}
		self::keep_moving();
		wp_send_json_success( self::view( Jobs::current( get_current_blog_id() ) ) );
	}
}
