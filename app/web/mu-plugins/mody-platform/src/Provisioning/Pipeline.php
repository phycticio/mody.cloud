<?php
/**
 * The site provisioning pipeline (mvp-spec section 7, ticket M2-01).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Provisioning;

use Mody\Platform\Agent\EditorBubble;
use Mody\Platform\AI\PageGenerator;
use Mody\Platform\AI\SpectraLayout;
use Mody\Platform\Brand\BrandSettings;
use Mody\Platform\Design\Presets;
use Mody\Platform\Design\SiteDesign;
use Mody\Platform\SiteEditor;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a member's site from the wizard's answers, in-process.
 *
 * start() validates the answers and queues a job; run() runs its steps in
 * order (Jobs::STEPS): quota, site, profile, theme, styles, page, email.
 * Each step's outcome and the next step are written in one update, after
 * the step's side effects, so a killed run resumes at the step it was in.
 * Every step can run again without doing its work twice: the site records
 * its job (option `mody_provisioning_job`), and so does its front page (post
 * meta), the profile is looked up by site, and the welcome email is marked
 * before it is sent. The site gets its theme as it is created, so it is
 * never unthemed.
 *
 * Callers check permissions: the wizard (M2-03) for its user.
 */
final class Pipeline {

	/** The block theme every member site runs. */
	public const THEME = 'theme';

	/** Steps that run in the new site's context. */
	private const SITE_STEPS = array( 'theme', 'styles', 'page', 'email' );

	/** Longest "what do you do?" sentence: it becomes the tagline (mody/update-site-settings caps it at 200). */
	public const MAX_MISSION_LENGTH = 200;

	/** Longest site title and brand name (the profile table's column). */
	public const MAX_NAME_LENGTH = 120;

	/**
	 * Addresses no site may take (M1-03, 2026-11-08): the platform's own
	 * hosts and names a visitor could mistake for it. Core's network option
	 * `illegal_names` is checked too.
	 */
	public const RESERVED_SLUGS = array(
		'www',
		'mail',
		'webmail',
		'smtp',
		'imap',
		'pop3',
		'ftp',
		'api',
		'app',
		'admin',
		'dashboard',
		'login',
		'register',
		'signup',
		'account',
		'accounts',
		'billing',
		'plugins',
		'status',
		'support',
		'help',
		'docs',
		'static',
		'cdn',
		'assets',
		'staging',
		'mody',
		'modycloud',
	);

	/** The image types a logo may have (M2-05). */
	public const LOGO_MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
	);

	/**
	 * Validates the wizard's answers and queues a job.
	 *
	 * @param int                  $user_id The member the site is for.
	 * @param array<string, mixed> $input   slug, title (the brand name, M2-08), mission
	 *                                      (seeds the tagline and the mission setting),
	 *                                      prompt, and optionally tone (default: friendly),
	 *                                      preset (default: Presets::DEFAULT), logo_id (an
	 *                                      image the user uploaded to the main site), and
	 *                                      starter (the signup's site, M1-03: mission and
	 *                                      prompt may be empty, see validate()).
	 * @return int|WP_Error The job ID, or `mody_invalid_input` (data: `fields`, field => message),
	 *                      `mody_site_limit_reached`, `mody_invalid_user`.
	 */
	public static function start( int $user_id, array $input ): int|WP_Error {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'mody_invalid_user', __( 'Unknown member.', 'mody_cloud' ) );
		}

		$clean = self::validate( $input, $user );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$quota = Quota::can_create_site( $user_id );
		if ( is_wp_error( $quota ) ) {
			return $quota;
		}

		$job_id = Jobs::create( $user_id, $clean );
		return $job_id ? $job_id : new WP_Error( 'mody_job_not_saved', __( 'The site could not be queued. Please try again.', 'mody_cloud' ) );
	}

	/**
	 * Checks a site's address and name, as the wizard and the signup ask for
	 * them: core's signup rules, the network's domain, the reserved names
	 * and the name's length.
	 *
	 * @param string        $slug  The subdomain, as typed.
	 * @param string        $title The site title, as typed.
	 * @param \WP_User|null $user  The member, or null before the account exists (signup).
	 * @return array<string, string> Field (`slug`, `title`) => message; empty when both are fine.
	 */
	public static function check_address( string $slug, string $title, ?\WP_User $user = null ): array {
		$errors = array();
		$slug   = strtolower( trim( $slug ) );
		$title  = sanitize_text_field( $title );
		$check  = wpmu_validate_blog_signup( $slug, $title, $user ?? '' );
		// Core also keeps an address that matches someone's login for that user. Logins
		// here come from email addresses (hello@… is `hello`), so that rule would let any
		// account block an address nobody has: only a site or a pending signup takes one.
		$by_login = __( 'Sorry, that site is reserved!' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own string, as it adds it.
		foreach ( array( 'blogname' => 'slug', 'blog_title' => 'title' ) as $code => $field ) {
			$messages = array_values( array_diff( $check['errors']->get_error_messages( $code ), 'blogname' === $code ? array( $by_login ) : array() ) );
			if ( $messages ) {
				$errors[ $field ] = $messages[0];
			}
		}
		if ( ! isset( $errors['slug'] ) && in_array( $slug, self::RESERVED_SLUGS, true ) ) {
			$errors['slug'] = __( 'That name is not allowed.' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own string.
		}
		// The check above builds the domain from the current request: check the network's too.
		if ( ! isset( $errors['slug'] ) && '' !== $slug && domain_exists( self::domain( $slug ), get_network()->path, get_current_network_id() ) ) {
			$errors['slug'] = __( 'Sorry, that site already exists!' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own string.
		}
		if ( mb_strlen( $title ) > self::MAX_NAME_LENGTH ) {
			/* translators: %d: maximum number of characters. */
			$errors['title'] = sprintf( __( 'Use at most %d characters.', 'mody_cloud' ), self::MAX_NAME_LENGTH );
		}
		return $errors;
	}

	/**
	 * Validates and cleans the wizard's answers (mvp-spec section 6).
	 *
	 * A `starter` site (the signup's, M1-03) needs only its address and name:
	 * the mission and the prompt may be empty, and with no prompt the page
	 * step publishes the starter page without calling the model.
	 *
	 * @param array<string, mixed> $input The answers.
	 * @param \WP_User             $user  The member.
	 * @return array<string, string>|WP_Error
	 */
	public static function validate( array $input, \WP_User $user ): array|WP_Error {
		$text    = static fn( string $key ): string => is_scalar( $input[ $key ] ?? null ) ? trim( (string) $input[ $key ] ) : '';
		$starter = ! empty( $input['starter'] );

		$slug   = strtolower( $text( 'slug' ) );
		$title  = sanitize_text_field( $text( 'title' ) );
		$errors = self::check_address( $slug, $title, $user );

		$mission = sanitize_text_field( $text( 'mission' ) );
		if ( '' === $mission && $starter ) {
			// Nothing to check: the member tells the agent later (M1-03).
		} elseif ( '' === $mission ) {
			$errors['mission'] = __( 'Say in one sentence what you do.', 'mody_cloud' );
		} elseif ( mb_strlen( $mission ) > self::MAX_MISSION_LENGTH ) {
			/* translators: %d: maximum number of characters. */
			$errors['mission'] = sprintf( __( 'Use at most %d characters.', 'mody_cloud' ), self::MAX_MISSION_LENGTH );
		}

		$tone = '' !== $text( 'tone' ) ? $text( 'tone' ) : 'friendly';
		if ( ! in_array( $tone, BrandSettings::TONES, true ) ) {
			$errors['tone'] = __( 'Choose one of the three tones.', 'mody_cloud' );
		}

		$preset = '' !== $text( 'preset' ) ? $text( 'preset' ) : Presets::DEFAULT;
		if ( null === Presets::colors( $preset ) ) {
			$errors['preset'] = __( 'Choose one of the design presets.', 'mody_cloud' );
		}

		$prompt = trim( sanitize_textarea_field( $text( 'prompt' ) ) );
		if ( '' === $prompt && ! $starter ) {
			$errors['prompt'] = __( 'Describe the first page of your site.', 'mody_cloud' );
		} elseif ( mb_strlen( $prompt ) > PageGenerator::MAX_PROMPT_LENGTH ) {
			/* translators: %d: maximum number of characters. */
			$errors['prompt'] = sprintf( __( 'Use at most %d characters.', 'mody_cloud' ), PageGenerator::MAX_PROMPT_LENGTH );
		}

		$logo_id = absint( $input['logo_id'] ?? 0 );
		if ( $logo_id && ! self::is_own_logo( $logo_id, $user->ID ) ) {
			$errors['logo'] = __( 'The logo must be an image you uploaded: PNG, JPEG, GIF or WebP.', 'mody_cloud' );
		}

		if ( $errors ) {
			return new WP_Error( 'mody_invalid_input', reset( $errors ), array( 'fields' => $errors ) );
		}

		$clean = array(
			'slug'    => $slug,
			'title'   => $title,
			'mission' => $mission,
			'tone'    => $tone,
			'preset'  => $preset,
			'prompt'  => $prompt,
			'logo_id' => $logo_id,
		);
		if ( $starter ) {
			$clean['starter'] = true;
		}
		return $clean;
	}

	/**
	 * Whether an attachment on the main site is an image the user uploaded,
	 * of a type a logo may have.
	 *
	 * @param int $attachment_id Attachment ID on the main site.
	 * @param int $user_id       User ID.
	 */
	public static function is_own_logo( int $attachment_id, int $user_id ): bool {
		switch_to_blog( get_main_site_id() );
		$post = get_post( $attachment_id );
		$ok   = $post && 'attachment' === $post->post_type && $user_id === (int) $post->post_author
			&& in_array( get_post_mime_type( $post ), self::LOGO_MIMES, true );
		restore_current_blog();
		return $ok;
	}

	/**
	 * Runs a job from the step it is at, until it is done or a step fails.
	 *
	 * A queued or failed job runs; so does a running one that stopped being
	 * updated (Jobs::STALE_AFTER), which means its run was killed.
	 *
	 * @param int $job_id Job ID.
	 * @return array<string, mixed>|WP_Error The job as it ends (status `done` or `failed`), or
	 *                                       `mody_job_not_found`, `mody_job_busy` (another request runs it).
	 */
	public static function run( int $job_id ): array|WP_Error {
		if ( ! Jobs::claim( $job_id ) ) {
			$job = Jobs::get( $job_id );
			if ( null === $job ) {
				return new WP_Error( 'mody_job_not_found', __( 'Unknown site creation.', 'mody_cloud' ) );
			}
			return 'done' === $job['status'] ? $job : new WP_Error( 'mody_job_busy', __( 'This site is being created already.', 'mody_cloud' ) );
		}

		for ( $job = Jobs::get( $job_id ); 'done' !== $job['step']; $job = Jobs::get( $job_id ) ) {
			$step     = $job['step'];
			$method   = 'step_' . $step;
			$in_site  = in_array( $step, self::SITE_STEPS, true );
			$switched = $in_site && get_site( $job['site_id'] ) && switch_to_blog( $job['site_id'] );
			try {
				$outcome = $in_site && ! $switched
					? new WP_Error( 'mody_site_missing', __( 'The new site no longer exists.', 'mody_cloud' ) )
					: self::$method( $job );
			} catch ( \Exception $e ) {
				$outcome = new WP_Error( 'mody_step_exception', $e->getMessage() );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}

			if ( is_wp_error( $outcome ) ) {
				Jobs::update(
					$job_id,
					array(
						'status'     => 'failed',
						'last_error' => $step . ': ' . $outcome->get_error_code() . ': ' . $outcome->get_error_message(),
					)
				);
				return Jobs::get( $job_id );
			}

			/**
			 * Fires after a provisioning step did its work, before the job moves on.
			 *
			 * The tests throw here to simulate a run killed at the worst moment.
			 *
			 * @param string $step   The step that ran.
			 * @param int    $job_id Job ID.
			 */
			do_action( 'mody_provisioning_step_done', $step, $job_id );

			$outcome['step'] = Jobs::STEPS[ array_search( $step, Jobs::STEPS, true ) + 1 ];
			Jobs::update( $job_id, $outcome );
		}

		Jobs::update( $job_id, array( 'status' => 'done' ) );
		return Jobs::get( $job_id );
	}

	/**
	 * Generates the front page again, for a site that got the fallback page
	 * ("Retry generation", M2-03).
	 *
	 * @param int $job_id A done job whose page is the fallback.
	 * @return array<string, mixed>|WP_Error The job, or `mody_nothing_to_retry`, or the generation error
	 *                                       (the fallback page stays).
	 */
	public static function retry_generation( int $job_id ): array|WP_Error {
		$job = Jobs::get( $job_id );
		if ( null === $job || 'done' !== $job['status'] || empty( $job['result']['fallback'] ) || ! get_site( $job['site_id'] ) ) {
			return new WP_Error( 'mody_nothing_to_retry', __( 'This site\'s first page was generated already.', 'mody_cloud' ) );
		}

		switch_to_blog( $job['site_id'] );
		$page_id = (int) $job['result']['page_id'];
		$markup  = PageGenerator::generate_page( $job['site_id'], (string) $job['input']['prompt'] );
		if ( ! is_wp_error( $markup ) ) {
			$saved = wp_update_post(
				wp_slash(
					array(
						'ID'           => $page_id,
						'post_title'   => PageGenerator::first_heading( $markup ),
						'post_content' => SpectraLayout::with_ids( $markup ), // As insert_page() saves it.
					)
				),
				true
			);
			$markup = is_wp_error( $saved ) ? $saved : $markup;
			if ( ! is_wp_error( $markup ) ) {
				delete_post_meta( $page_id, '_mody_fallback' );
			}
		}
		restore_current_blog();

		if ( is_wp_error( $markup ) ) {
			Jobs::update( $job_id, array( 'result' => array( 'generation_error' => $markup->get_error_code() . ': ' . $markup->get_error_message() ) ) );
			return $markup;
		}
		Jobs::update(
			$job_id,
			array(
				'result' => array(
					'fallback'         => false,
					'generation_error' => '',
				),
			)
		);
		return Jobs::get( $job_id );
	}

	/**
	 * The page published when generation fails: "Hello, I'm {site title}"
	 * and the mission, in one section (SpectraLayout).
	 *
	 * @param string $brand   The site title (the brand name, M2-08).
	 * @param string $mission What the brand does.
	 */
	public static function fallback_markup( string $brand, string $mission ): string {
		/* translators: %s: the brand name. */
		$heading = sprintf( __( 'Hello, I\'m %s', 'mody_cloud' ), $brand );
		return '<!-- wp:spectra/container ' . SpectraLayout::SECTION . " -->\n"
			. "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">" . esc_html( $heading ) . "</h1>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:paragraph -->\n<p>" . esc_html( $mission ) . "</p>\n<!-- /wp:paragraph -->\n"
			. '<!-- /wp:spectra/container -->';
	}

	/**
	 * A starter site's front page (M1-03): the site's name and a line saying
	 * it is on its way, in one section (SpectraLayout). The member replaces
	 * it by asking the agent.
	 *
	 * @param string $title The site title.
	 */
	public static function starter_markup( string $title ): string {
		return '<!-- wp:spectra/container ' . SpectraLayout::SECTION . " -->\n"
			. "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">" . esc_html( $title ) . "</h1>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:paragraph -->\n<p>" . esc_html__( 'Our new website is on its way. Come back soon!', 'mody_cloud' ) . "</p>\n<!-- /wp:paragraph -->\n"
			. '<!-- /wp:spectra/container -->';
	}

	/**
	 * A site's domain on this network: `slug.mody.cloud`.
	 *
	 * @param string $slug The site's subdomain.
	 */
	public static function domain( string $slug ): string {
		return $slug . '.' . preg_replace( '|^www\.|', '', get_network()->domain );
	}

	/**
	 * A new site's `home` and `siteurl`, shaped like the main site's.
	 *
	 * In a subdomain network WordPress gives new sites `http://` addresses,
	 * and a `siteurl` without Bedrock's `/wp` (core lives in app/wp). The
	 * main site has both right, so its scheme and suffix are copied.
	 *
	 * @param string $domain The new site's domain.
	 * @param string $path   The new site's path.
	 * @return array{home: string, siteurl: string}
	 */
	public static function urls( string $domain, string $path ): array {
		$main_home    = untrailingslashit( get_home_url( get_main_site_id() ) );
		$main_siteurl = untrailingslashit( get_site_url( get_main_site_id() ) );
		$scheme       = (string) wp_parse_url( $main_home, PHP_URL_SCHEME );
		$suffix       = str_starts_with( $main_siteurl, $main_home ) ? substr( $main_siteurl, strlen( $main_home ) ) : '';
		$home         = untrailingslashit( ( '' !== $scheme ? $scheme : 'https' ) . '://' . $domain . $path );
		return array(
			'home'    => $home,
			'siteurl' => $home . $suffix,
		);
	}

	/**
	 * Step 1: the quota, and that the address is still free.
	 *
	 * @param array<string, mixed> $job The job.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function step_quota( array $job ): array|WP_Error {
		$quota = Quota::can_create_site( $job['user_id'], $job['id'] );
		if ( is_wp_error( $quota ) ) {
			return $quota;
		}
		if ( domain_exists( self::domain( $job['input']['slug'] ), get_network()->path, get_current_network_id() ) ) {
			return new WP_Error( 'mody_site_exists', __( 'Sorry, that site already exists!' ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own string.
		}
		return array();
	}

	/**
	 * Step 2: the site, with the member as its administrator and the theme
	 * already active. A site this job created before it was killed is reused.
	 *
	 * @param array<string, mixed> $job The job.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function step_site( array $job ): array|WP_Error {
		$input   = $job['input'];
		$domain = self::domain( $input['slug'] );
		$path   = get_network()->path;
		// get_sites(), not get_blog_id_from_url(), which may cache "not found" in the object cache.
		$found   = get_sites(
			array(
				'domain'     => $domain,
				'path'       => $path,
				'network_id' => get_current_network_id(),
				'fields'     => 'ids',
				'number'     => 1,
			)
		);
		$site_id = (int) ( $found[0] ?? 0 );

		if ( $site_id ) {
			if ( (int) get_blog_option( $site_id, 'mody_provisioning_job' ) !== $job['id'] ) {
				return new WP_Error( 'mody_site_exists', __( 'Sorry, that site already exists!' ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own string.
			}
		} else {
			// wpmu_create_blog() turns wp_installing() on and leaves it on.
			$installing = wp_installing();
			$site_id    = wpmu_create_blog(
				$domain,
				$path,
				$input['title'],
				$job['user_id'],
				array_merge(
					self::urls( $domain, $path ),
					array(
						// A starter site (the signup's) starts hidden from search engines
						// until it has real pages (tickets/seo.md, question 4).
						'public'                => empty( $job['input']['starter'] ) ? 1 : 0,
						'template'              => self::THEME,
						'stylesheet'            => self::THEME,
						'blogdescription'       => $input['mission'],
						'mody_provisioning_job' => $job['id'],
					)
				),
				get_current_network_id()
			);
			wp_installing( $installing );
			if ( is_wp_error( $site_id ) ) {
				return $site_id;
			}
		}

		// The site's owner counts it against the plan, whatever roles are given later (Quota::used()).
		Quota::record_owner( (int) $site_id, (int) $job['user_id'] );

		// WordPress's sample post and page: the generated front page replaces them.
		switch_to_blog( $site_id );
		$samples = array( get_page_by_path( 'hello-world', OBJECT, 'post' ), get_page_by_path( 'sample-page', OBJECT, 'page' ) );
		foreach ( array_filter( $samples ) as $sample ) {
			wp_delete_post( $sample->ID, true );
		}
		restore_current_blog();

		return array( 'site_id' => $site_id );
	}

	/**
	 * Step 3: the brand, in the new site's own settings (M2-08): the logo as
	 * its site logo, and the mission and tone site settings. The title and
	 * tagline were set with the site; the preset's colors go to its Global
	 * Styles in the styles step. Nothing is copied elsewhere.
	 *
	 * @param array<string, mixed> $job The job.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function step_profile( array $job ): array|WP_Error {
		$result = empty( $job['input']['logo_id'] ) ? array() : self::copy_logo( $job );
		switch_to_blog( (int) $job['site_id'] );
		BrandSettings::save( (string) $job['input']['mission'], (string) $job['input']['tone'] );
		restore_current_blog();
		return array( 'result' => $result );
	}

	/**
	 * Copies the uploaded logo into the new site's media library, makes it
	 * the site logo (the theme's header shows it), and deletes the upload on
	 * the main site. Done once: the copy records its job.
	 *
	 * A logo that cannot be copied does not stop the site: the error is kept
	 * in the job's result, and the site has no logo.
	 *
	 * @param array<string, mixed> $job The job.
	 * @return array{logo_url?: string, logo_error?: string}
	 */
	private static function copy_logo( array $job ): array {
		$source_id = (int) $job['input']['logo_id'];

		switch_to_blog( $job['site_id'] );
		$found = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'meta_key'    => '_mody_provisioning_job', // phpcs:ignore WordPress.DB.SlowDBQuery -- One lookup on a new site.
				'meta_value'  => $job['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);
		$logo_id = (int) ( $found[0] ?? 0 );
		restore_current_blog();

		if ( ! $logo_id ) {
			switch_to_blog( get_main_site_id() );
			$path = (string) get_attached_file( $source_id );
			$mime = (string) get_post_mime_type( $source_id );
			restore_current_blog();
			if ( '' === $path || ! is_readable( $path ) || ! in_array( $mime, self::LOGO_MIMES, true ) ) {
				return array( 'logo_error' => __( 'The uploaded logo could not be found.', 'mody_cloud' ) );
			}

			require_once ABSPATH . 'wp-admin/includes/image.php';
			switch_to_blog( $job['site_id'] );
			$upload = wp_upload_bits( wp_basename( $path ), null, (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
			if ( empty( $upload['error'] ) ) {
				$logo_id = wp_insert_attachment(
					array(
						'post_mime_type' => $mime,
						/* translators: %s: the brand name. */
						'post_title'     => sprintf( __( '%s logo', 'mody_cloud' ), $job['input']['title'] ),
						'post_status'    => 'inherit',
						'post_author'    => $job['user_id'],
						'meta_input'     => array( '_mody_provisioning_job' => $job['id'] ),
					),
					$upload['file'],
					0,
					true
				);
				if ( ! is_wp_error( $logo_id ) ) {
					wp_update_attachment_metadata( $logo_id, wp_generate_attachment_metadata( $logo_id, $upload['file'] ) );
				}
			}
			restore_current_blog();
			if ( ! empty( $upload['error'] ) || is_wp_error( $logo_id ) ) {
				return array( 'logo_error' => is_wp_error( $logo_id ) ? $logo_id->get_error_message() : (string) $upload['error'] );
			}
		}

		switch_to_blog( $job['site_id'] );
		update_option( 'site_logo', $logo_id );
		$url = (string) wp_get_attachment_url( $logo_id );
		restore_current_blog();

		// wp-config.php builds WP_CONTENT_URL from the request's host, so an upload URL made
		// while switched to the new site carries the wizard's host. The file is the same on
		// every host of the network: keep the site's own.
		$home = wp_parse_url( get_home_url( $job['site_id'] ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( ! empty( $home['host'] ) && '' !== $path ) {
			$url = ( $home['scheme'] ?? 'https' ) . '://' . $home['host'] . $path;
		}

		// The upload on the main site has served its purpose.
		switch_to_blog( get_main_site_id() );
		if ( get_post( $source_id ) ) {
			wp_delete_attachment( $source_id, true );
		}
		restore_current_blog();

		return array( 'logo_url' => $url );
	}

	/**
	 * Step 4: the theme (already set when the site was created; checked
	 * here). The market plugins join this step with M4-02.
	 *
	 * @param array<string, mixed> $job The job.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function step_theme( array $job ): array|WP_Error {
		unset( $job );
		if ( self::THEME !== get_stylesheet() ) {
			switch_theme( self::THEME );
		}
		return self::THEME === get_stylesheet()
			? array()
			: new WP_Error( 'mody_theme_not_active', __( 'The site\'s theme could not be activated.', 'mody_cloud' ) );
	}

	/**
	 * Step 5: the preset in the site's Global Styles: the theme's style
	 * variation in full (palette, typography, spacing, element styles), as the
	 * Site Editor applies it (M2-06). Skipped when the site already holds it.
	 *
	 * @param array<string, mixed> $job The job.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function step_styles( array $job ): array|WP_Error {
		$preset = (string) $job['input']['preset'];
		if ( SiteDesign::active_preset() === $preset ) {
			return array();
		}
		$applied = SiteDesign::apply_preset( $preset );
		return is_wp_error( $applied ) ? $applied : array();
	}

	/**
	 * Step 6: the generated front page, or the fallback page when generation
	 * fails. Either way the site gets a published front page.
	 *
	 * @param array<string, mixed> $job The job.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function step_page( array $job ): array|WP_Error {
		$result = array();
		$found  = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'any',
				'meta_key'    => '_mody_provisioning_job', // phpcs:ignore WordPress.DB.SlowDBQuery -- One lookup on a new site.
				'meta_value'  => $job['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);

		if ( $found ) {
			$page_id = (int) $found[0];
		} elseif ( '' === (string) $job['input']['prompt'] ) {
			// A starter site (M1-03): no model call; the member asks the agent for pages later.
			$page_id = PageGenerator::insert_page(
				self::starter_markup( (string) $job['input']['title'] ),
				array(
					'post_title'  => __( 'Home', 'mody_cloud' ),
					'post_status' => 'publish',
					'post_author' => $job['user_id'],
					'meta_input'  => array( '_mody_provisioning_job' => $job['id'] ),
				)
			);
			if ( is_wp_error( $page_id ) ) {
				return $page_id;
			}
		} else {
			$markup   = PageGenerator::generate_page( $job['site_id'], $job['input']['prompt'] );
			$fallback = is_wp_error( $markup );
			if ( $fallback ) {
				$result['generation_error'] = $markup->get_error_code() . ': ' . $markup->get_error_message();
				$markup                     = self::fallback_markup( $job['input']['title'], $job['input']['mission'] );
			}
			$meta = array( '_mody_provisioning_job' => $job['id'] );
			if ( $fallback ) {
				$meta['_mody_fallback'] = 1;
			}
			$page_id = PageGenerator::insert_page(
				$markup,
				array(
					'post_status' => 'publish',
					'post_author' => $job['user_id'],
					'meta_input'  => $meta,
				)
			);
			if ( is_wp_error( $page_id ) ) {
				return $page_id;
			}
		}

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );

		$result['page_id']  = $page_id;
		$result['fallback'] = (bool) get_post_meta( $page_id, '_mody_fallback', true );
		return array( 'result' => $result );
	}

	/**
	 * Step 7: the welcome email, at most once. A failed email does not fail
	 * the job: the site is ready either way.
	 *
	 * @param array<string, mixed> $job The job.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function step_email( array $job ): array|WP_Error {
		if ( ! empty( $job['result']['email'] ) ) {
			return array();
		}
		if ( ! empty( $job['input']['starter'] ) ) {
			// The signup's welcome email, with the set-password link, replaces this one (M1-03).
			return array( 'result' => array( 'email' => 'signup' ) );
		}
		// Marked before sending: a run killed while sending does not send it twice.
		Jobs::update( $job['id'], array( 'result' => array( 'email' => 'sending' ) ) );

		$user = get_userdata( $job['user_id'] );
		if ( ! $user ) {
			return array( 'result' => array( 'email' => 'failed' ) );
		}
		$title = $job['input']['title'];
		$body  = sprintf(
			/* translators: 1: the member's name, 2: the site title, 3: the site's address, 4: the Site Editor link. */
			__( "Hello %1\$s,\n\nYour new site %2\$s is ready:\n%3\$s\n\nOpen it in the editor:\n%4\$s\n\nLog in with your Mody Cloud account, as always.\n\nMody Cloud", 'mody_cloud' ),
			$user->display_name,
			$title,
			home_url( '/' ),
			// The front page in the Site Editor, with the agent's chat open (decided 2026-11-07).
			EditorBubble::open_url( SiteEditor::page_url( (int) ( $job['result']['page_id'] ?? 0 ) ) )
		);
		/* translators: %s: the site title. */
		$sent = wp_mail( $user->user_email, sprintf( __( 'Your site %s is ready', 'mody_cloud' ), $title ), $body );
		return array( 'result' => array( 'email' => $sent ? 'sent' : 'failed' ) );
	}
}
