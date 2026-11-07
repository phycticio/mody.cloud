<?php
/**
 * The brand wizard: Network Admin → Mody → New site.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Wizard;

use Mody\Platform\Agent\ChatController;
use Mody\Platform\AI\PageGenerator;
use Mody\Platform\Design\Presets;
use Mody\Platform\Provisioning\Jobs;
use Mody\Platform\Provisioning\Pipeline;
use Mody\Platform\Provisioning\Quota;

defined( 'ABSPATH' ) || exit;

/**
 * Three steps of choices (mvp-spec section 6, ticket M2-03): HTML from PHP,
 * and a small script that posts the answers to WizardController and follows
 * the job. No JS build step, like the agent chat.
 *
 * Until M1, only super admins open it: no role has CAPABILITY, and super
 * admins pass every capability check. M1 grants it to members (spec section 3).
 */
final class WizardPage {

	/** Who may create sites from the dashboard (mvp-spec section 3). */
	public const CAPABILITY = 'mody_access_dashboard';

	/** Admin page slug. */
	public const SLUG = 'mody-new-site';

	/**
	 * The tone cards: the same sentence written in each tone (mvp-spec section 6).
	 * The keys are BrandProfile::TONES.
	 *
	 * @return array<string, array{0: string, 1: string}> Tone => name, sample sentence.
	 */
	public static function tones(): array {
		return array(
			'formal'    => array( __( 'Formal', 'mody_cloud' ), __( 'We provide professional sourdough services.', 'mody_cloud' ) ),
			'friendly'  => array( __( 'Friendly', 'mody_cloud' ), __( 'We bake for your team. Come hungry!', 'mody_cloud' ) ),
			'technical' => array( __( 'Technical', 'mody_cloud' ), __( 'Precise levain management. Documented processes.', 'mody_cloud' ) ),
		);
	}

	/**
	 * Hooked to `network_admin_menu`.
	 */
	public static function register_menu(): void {
		$hook = add_menu_page(
			__( 'New site', 'mody_cloud' ),
			__( 'Mody', 'mody_cloud' ),
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-admin-site-alt3',
			3
		);
		add_submenu_page( self::SLUG, __( 'New site', 'mody_cloud' ), __( 'New site', 'mody_cloud' ), self::CAPABILITY, self::SLUG, array( self::class, 'render' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'enqueue' ) );
		}
	}

	/**
	 * Loads the wizard's script and its settings (`load-<page>`).
	 */
	public static function enqueue(): void {
		$root    = dirname( __DIR__, 2 );
		$version = static fn( string $file ): string => (string) filemtime( $root . '/assets/' . $file );

		wp_enqueue_style( 'mody-wizard', plugins_url( 'assets/wizard.css', $root . '/mody-platform.php' ), array(), $version( 'wizard.css' ) );
		wp_enqueue_script( 'mody-wizard', plugins_url( 'assets/wizard.js', $root . '/mody-platform.php' ), array(), $version( 'wizard.js' ), true );

		$pending = Jobs::unfinished_for_user( get_current_user_id() );
		wp_add_inline_script(
			'mody-wizard',
			'window.modyWizard = ' . wp_json_encode(
				array(
					'endpoint' => rest_url( ChatController::REST_NAMESPACE . '/sites' ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'pending'  => $pending ? WizardController::view( $pending ) : null,
					'interval' => 1500,
					'preview'  => WizardPreview::url(),
					'logoMax'  => WizardController::MAX_LOGO_BYTES,
					'logoMime' => array_values( Pipeline::LOGO_MIMES ),
					'steps'    => array(
						'quota'   => __( 'Checking your plan', 'mody_cloud' ),
						'site'    => __( 'Creating your site', 'mody_cloud' ),
						'profile' => __( 'Saving your brand', 'mody_cloud' ),
						'theme'   => __( 'Setting up the theme', 'mody_cloud' ),
						'styles'  => __( 'Applying your colors', 'mody_cloud' ),
						'page'    => __( 'Writing your first page (this can take a minute)', 'mody_cloud' ),
						'email'   => __( 'Sending your welcome email', 'mody_cloud' ),
					),
					'strings'  => array(
						'required'   => __( 'Please fill this in.', 'mody_cloud' ),
						/* translators: %s: the size limit, such as "2 MB". */
						'badLogo'    => sprintf( __( 'The logo must be a PNG, JPEG, GIF or WebP image of at most %s.', 'mody_cloud' ), size_format( WizardController::MAX_LOGO_BYTES ) ),
						'creating'   => __( 'Creating your site…', 'mody_cloud' ),
						'ready'      => __( 'Your site is ready', 'mody_cloud' ),
						'failed'     => __( 'Your site could not be created', 'mody_cloud' ),
						'tryAgain'   => __( 'Try again', 'mody_cloud' ),
						'startOver'  => __( 'Create a different site', 'mody_cloud' ),
						'visit'      => __( 'Visit your site', 'mody_cloud' ),
						'editor'     => __( 'Open the Site Editor', 'mody_cloud' ),
						'admin'      => __( 'Go to its dashboard', 'mody_cloud' ),
						'fallback'   => __( 'Your first page could not be written this time, so the site shows a simple welcome page. You can try again now, or later ask the Mody Agent in your site\'s dashboard.', 'mody_cloud' ),
						'retry'      => __( 'Retry generation', 'mody_cloud' ),
						'retrying'   => __( 'Writing your first page again…', 'mody_cloud' ),
						'retryFail'  => __( 'It did not work this time either. Your site keeps its welcome page.', 'mody_cloud' ),
						'network'    => __( 'The connection failed. Check your connection and try again.', 'mody_cloud' ),
						'unexpected' => __( 'Something went wrong. Please reload the page.', 'mody_cloud' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Renders the page.
	 */
	public static function render(): void {
		$user_id = get_current_user_id();
		$used    = Quota::used( $user_id );
		$limit   = Quota::limit( $user_id );
		$suffix  = '.' . preg_replace( '|^www\.|', '', get_network()->domain );
		$chips   = array(
			__( 'Home page for my business', 'mody_cloud' ),
			__( 'About me', 'mody_cloud' ),
			__( 'Product page', 'mody_cloud' ),
		);
		$error   = static function ( string $field ): void {
			printf( '<p class="mody-wizard__error" data-error-for="%s" role="alert"></p>', esc_attr( $field ) );
		};
		?>
		<div class="wrap mody-wizard">
			<h1><?php esc_html_e( 'New site', 'mody_cloud' ); ?></h1>
			<p class="mody-wizard__quota">
				<?php
				/* translators: 1: sites in use, 2: sites the plan includes. */
				echo esc_html( sprintf( __( '%1$d of %2$d sites used', 'mody_cloud' ), $used, $limit ) );
				?>
			</p>
			<?php if ( $used >= $limit ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'All the sites of your plan are in use, so you cannot create another one.', 'mody_cloud' ); ?></p></div>
			<?php endif; ?>

			<form id="mody-wizard-form" class="mody-wizard__form" novalidate>
				<ol class="mody-wizard__nav">
					<li data-nav="1" class="is-current"><?php esc_html_e( 'Your brand', 'mody_cloud' ); ?></li>
					<li data-nav="2"><?php esc_html_e( 'Your design', 'mody_cloud' ); ?></li>
					<li data-nav="3"><?php esc_html_e( 'Your first page', 'mody_cloud' ); ?></li>
				</ol>

				<section class="mody-wizard__step" data-step="1">
					<h2><?php esc_html_e( 'Your brand', 'mody_cloud' ); ?></h2>
					<p class="mody-wizard__field">
						<label for="mody-title"><?php esc_html_e( 'Site name', 'mody_cloud' ); ?></label>
						<input type="text" id="mody-title" name="title" maxlength="<?php echo (int) Pipeline::MAX_NAME_LENGTH; ?>" autocomplete="off" required>
						<?php $error( 'title' ); ?>
					</p>
					<p class="mody-wizard__field">
						<label for="mody-slug"><?php esc_html_e( 'Address', 'mody_cloud' ); ?></label>
						<span class="mody-wizard__address"><input type="text" id="mody-slug" name="slug" maxlength="63" autocomplete="off" spellcheck="false" required><span><?php echo esc_html( $suffix ); ?></span></span>
						<?php $error( 'slug' ); ?>
					</p>
					<p class="mody-wizard__field">
						<label for="mody-brand"><?php esc_html_e( 'Brand name', 'mody_cloud' ); ?></label>
						<input type="text" id="mody-brand" name="brand_name" maxlength="<?php echo (int) Pipeline::MAX_NAME_LENGTH; ?>" autocomplete="off">
						<?php $error( 'brand_name' ); ?>
					</p>
					<p class="mody-wizard__field">
						<label for="mody-mission"><?php esc_html_e( 'What do you do?', 'mody_cloud' ); ?></label>
						<input type="text" id="mody-mission" name="mission" maxlength="<?php echo (int) Pipeline::MAX_MISSION_LENGTH; ?>" placeholder="<?php esc_attr_e( 'We bake sourdough bread for offices in Zurich.', 'mody_cloud' ); ?>" required>
						<?php $error( 'mission' ); ?>
					</p>
					<fieldset class="mody-wizard__cards">
						<legend><?php esc_html_e( 'Tone of voice', 'mody_cloud' ); ?></legend>
						<?php foreach ( self::tones() as $tone => list( $name, $sample ) ) : ?>
							<label class="mody-wizard__card">
								<input type="radio" name="tone" value="<?php echo esc_attr( $tone ); ?>" <?php checked( 'friendly', $tone ); ?>>
								<strong><?php echo esc_html( $name ); ?></strong>
								<span>“<?php echo esc_html( $sample ); ?>”</span>
							</label>
						<?php endforeach; ?>
						<?php $error( 'tone' ); ?>
					</fieldset>
					<p class="mody-wizard__buttons"><button type="button" class="button button-primary" data-go="2"><?php esc_html_e( 'Next', 'mody_cloud' ); ?></button></p>
				</section>

				<section class="mody-wizard__step" data-step="2" hidden>
					<h2><?php esc_html_e( 'Your design', 'mody_cloud' ); ?></h2>
					<fieldset class="mody-wizard__cards mody-wizard__presets">
						<legend><?php esc_html_e( 'Colors', 'mody_cloud' ); ?></legend>
						<?php foreach ( Presets::all() as $slug => $preset ) : ?>
							<label class="mody-wizard__card mody-wizard__preset">
								<input type="radio" name="preset" value="<?php echo esc_attr( $slug ); ?>" <?php checked( Presets::DEFAULT, $slug ); ?>>
								<strong><?php echo esc_html( $preset['name'] ); ?></strong>
								<span class="mody-wizard__swatches">
									<?php foreach ( array( 'primary', 'secondary', 'accent' ) as $color ) : ?>
										<span style="background-color:<?php echo esc_attr( $preset[ $color ] ); ?>"></span>
									<?php endforeach; ?>
								</span>
								<span class="mody-wizard__mock">
									<span class="mody-wizard__mock-heading" style="color:<?php echo esc_attr( $preset['primary'] ); ?>"><?php esc_html_e( 'Your headline', 'mody_cloud' ); ?></span>
									<span class="mody-wizard__mock-button" style="background-color:<?php echo esc_attr( $preset['accent'] ); ?>"><?php esc_html_e( 'Get in touch', 'mody_cloud' ); ?></span>
								</span>
							</label>
						<?php endforeach; ?>
						<?php $error( 'preset' ); ?>
					</fieldset>
					<p class="mody-wizard__field">
						<label for="mody-logo"><?php esc_html_e( 'Logo (optional)', 'mody_cloud' ); ?></label>
						<input type="file" id="mody-logo" name="logo" accept="<?php echo esc_attr( implode( ',', Pipeline::LOGO_MIMES ) ); ?>">
						<span class="description">
							<?php
							/* translators: %s: the size limit, such as "2 MB". */
							echo esc_html( sprintf( __( 'PNG, JPEG, GIF or WebP, at most %s.', 'mody_cloud' ), size_format( WizardController::MAX_LOGO_BYTES ) ) );
							?>
						</span>
						<?php $error( 'logo' ); ?>
					</p>
					<div class="mody-wizard__preview">
						<span class="mody-wizard__preview-label"><?php esc_html_e( 'Preview', 'mody_cloud' ); ?></span>
						<div class="mody-wizard__preview-frame"><iframe id="mody-wizard-preview" title="<?php esc_attr_e( 'Preview of your site', 'mody_cloud' ); ?>" tabindex="-1"></iframe></div>
					</div>
					<p class="description"><?php esc_html_e( 'You can fine-tune the colors later, in the Site Editor or by asking the Mody Agent.', 'mody_cloud' ); ?></p>
					<p class="mody-wizard__buttons">
						<button type="button" class="button" data-go="1"><?php esc_html_e( 'Back', 'mody_cloud' ); ?></button>
						<button type="button" class="button button-primary" data-go="3"><?php esc_html_e( 'Next', 'mody_cloud' ); ?></button>
					</p>
				</section>

				<section class="mody-wizard__step" data-step="3" hidden>
					<h2><?php esc_html_e( 'Your first page', 'mody_cloud' ); ?></h2>
					<p class="mody-wizard__field">
						<label for="mody-prompt"><?php esc_html_e( 'Describe the first page of your site.', 'mody_cloud' ); ?></label>
						<textarea id="mody-prompt" name="prompt" rows="5" maxlength="<?php echo (int) PageGenerator::MAX_PROMPT_LENGTH; ?>" required></textarea>
						<?php $error( 'prompt' ); ?>
					</p>
					<p class="mody-wizard__chips">
						<?php foreach ( $chips as $chip ) : ?>
							<button type="button" class="button mody-wizard__chip" data-prompt="<?php echo esc_attr( $chip ); ?>"><?php echo esc_html( $chip ); ?></button>
						<?php endforeach; ?>
					</p>
					<p class="mody-wizard__error mody-wizard__form-error" data-error-for="form" role="alert"></p>
					<p class="mody-wizard__buttons">
						<button type="button" class="button" data-go="2"><?php esc_html_e( 'Back', 'mody_cloud' ); ?></button>
						<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Create my site', 'mody_cloud' ); ?></button>
					</p>
				</section>
			</form>

			<div id="mody-wizard-status" class="mody-wizard__status" hidden aria-live="polite"></div>
		</div>
		<?php
	}
}
