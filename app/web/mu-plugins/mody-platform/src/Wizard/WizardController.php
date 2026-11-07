<?php
/**
 * REST endpoints of the brand wizard: `mody-platform/v1/sites`.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Wizard;

use Mody\Platform\Agent\ChatController;
use Mody\Platform\Provisioning\Jobs;
use Mody\Platform\Provisioning\Pipeline;
use Mody\Platform\SiteEditor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Starts a site creation and reports on it (ticket M2-03).
 *
 * - POST `sites`: validates the answers, queues a job, runs it after the response.
 * - GET `sites/jobs/<id>`: the job, as the wizard shows it.
 * - POST `sites/jobs/<id>/run`: Try again, for a failed or stopped job.
 * - POST `sites/jobs/<id>/retry-generation`: generates the front page again,
 *   for a site that got the fallback page.
 *
 * The work runs after the response, in the same PHP-FPM worker, like the
 * agent chat (ai-engine.md, "Background jobs"). Without PHP-FPM (WP-CLI,
 * tests) it runs before the response, which then holds the result.
 * Logged-in users with WizardPage::CAPABILITY only, and only their own jobs.
 */
final class WizardController {

	/** A retry of the generation not finished after this long has stopped. */
	private const RETRY_TIMEOUT = 5 * MINUTE_IN_SECONDS;

	/** The largest logo accepted (M2-05). */
	public const MAX_LOGO_BYTES = 2 * MB_IN_BYTES;

	/**
	 * Hooked to `rest_api_init`.
	 */
	public static function register_routes(): void {
		$string = static fn( int $max, bool $required = false ): array => array(
			'type'      => 'string',
			'required'  => $required,
			'maxLength' => $max,
		);

		register_rest_route(
			ChatController::REST_NAMESPACE,
			'/sites',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( self::class, 'can_create' ),
				'args'                => array(
					'slug'       => $string( 63, true ),
					'title'      => $string( 500, true ),
					'brand_name' => $string( 500 ),
					'mission'    => $string( 1000, true ),
					'tone'       => $string( 20 ),
					'preset'     => $string( 40 ),
					'prompt'     => $string( 5000, true ),
				),
			)
		);

		$job_route = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);
		register_rest_route(
			ChatController::REST_NAMESPACE,
			'/sites/jobs/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'check' ),
				'permission_callback' => array( self::class, 'can_create' ),
				'args'                => $job_route,
			)
		);
		register_rest_route(
			ChatController::REST_NAMESPACE,
			'/sites/jobs/(?P<id>\d+)/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'resume' ),
				'permission_callback' => array( self::class, 'can_create' ),
				'args'                => $job_route,
			)
		);
		register_rest_route(
			ChatController::REST_NAMESPACE,
			'/sites/jobs/(?P<id>\d+)/retry-generation',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'retry_generation' ),
				'permission_callback' => array( self::class, 'can_create' ),
				'args'                => $job_route,
			)
		);
	}

	/**
	 * Permission callback: a logged-in user who may create sites.
	 */
	public static function can_create(): bool {
		return is_user_logged_in() && current_user_can( WizardPage::CAPABILITY );
	}

	/**
	 * Starts a site creation.
	 *
	 * @param WP_REST_Request $request The wizard's answers.
	 * @return WP_REST_Response|WP_Error 202 with the job (200 when it ran at once); 400 with
	 *                                   `fields` for invalid answers; 403 at the site limit;
	 *                                   409 while another creation runs.
	 */
	public static function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$running = Jobs::unfinished_for_user( $user_id );
		if ( $running && in_array( $running['status'], array( 'queued', 'running' ), true ) && ! Jobs::is_stale( $running ) ) {
			return new WP_Error(
				'mody_site_in_progress',
				__( 'Your previous site is still being created. Please wait for it to finish.', 'mody_cloud' ),
				array(
					'status' => 409,
					'job'    => $running['id'],
				)
			);
		}

		$input = array_intersect_key( $request->get_params(), array_flip( array( 'slug', 'title', 'brand_name', 'mission', 'tone', 'preset', 'prompt' ) ) );

		// The answers first, so a logo is not stored for a request that fails anyway.
		$valid = Pipeline::validate( $input, wp_get_current_user() );
		if ( is_wp_error( $valid ) ) {
			$valid->add_data( array_merge( (array) $valid->get_error_data(), array( 'status' => 400 ) ) );
			return $valid;
		}

		$files = $request->get_file_params();
		if ( ! empty( $files['logo']['name'] ) ) {
			$logo_id = self::store_logo( (array) $files['logo'] );
			if ( is_wp_error( $logo_id ) ) {
				return new WP_Error(
					'mody_invalid_input',
					$logo_id->get_error_message(),
					array(
						'status' => 400,
						'fields' => array( 'logo' => $logo_id->get_error_message() ),
					)
				);
			}
			$input['logo_id'] = $logo_id;
		}

		$job_id = Pipeline::start( $user_id, $input );
		if ( is_wp_error( $job_id ) && ! empty( $input['logo_id'] ) ) {
			wp_delete_attachment( (int) $input['logo_id'], true );
		}
		if ( is_wp_error( $job_id ) ) {
			$status = array(
				'mody_invalid_input'      => 400,
				'mody_site_limit_reached' => 403,
			)[ $job_id->get_error_code() ] ?? 500;
			$job_id->add_data( array_merge( (array) $job_id->get_error_data(), array( 'status' => $status ) ) );
			return $job_id;
		}

		return self::later( $job_id, static fn() => Pipeline::run( $job_id ) );
	}

	/**
	 * The job, as the wizard shows it.
	 *
	 * @param WP_REST_Request $request The request, with the job `id`.
	 * @return WP_REST_Response|WP_Error 404 for an unknown job or another user's.
	 */
	public static function check( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$job = self::own_job( (int) $request['id'] );
		return is_wp_error( $job ) ? $job : new WP_REST_Response( self::view( $job ) );
	}

	/**
	 * Try again: runs a failed or stopped job from the step it failed at.
	 *
	 * @param WP_REST_Request $request The request, with the job `id`.
	 * @return WP_REST_Response|WP_Error 202 (200 when it ran at once); 409 if it runs or is done.
	 */
	public static function resume( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$job = self::own_job( (int) $request['id'] );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( 'done' === $job['status'] || ( 'running' === $job['status'] && ! Jobs::is_stale( $job ) ) ) {
			return new WP_Error( 'mody_job_not_resumable', __( 'This site creation is not stopped.', 'mody_cloud' ), array( 'status' => 409 ) );
		}

		// Queued, so the wizard shows it running until the run claims it.
		if ( 'failed' === $job['status'] ) {
			Jobs::update(
				$job['id'],
				array(
					'status'     => 'queued',
					'last_error' => null,
				)
			);
		}
		return self::later( $job['id'], static fn() => Pipeline::run( $job['id'] ) );
	}

	/**
	 * Retry generation: generates the front page again, replacing the fallback page.
	 *
	 * @param WP_REST_Request $request The request, with the job `id`.
	 * @return WP_REST_Response|WP_Error 202 (200 when it ran at once); 409 if there is nothing to retry.
	 */
	public static function retry_generation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$job = self::own_job( (int) $request['id'] );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( 'done' !== $job['status'] || empty( $job['result']['fallback'] ) || self::is_retrying( $job ) ) {
			return new WP_Error( 'mody_nothing_to_retry', __( 'There is no page to generate again.', 'mody_cloud' ), array( 'status' => 409 ) );
		}

		Jobs::update( $job['id'], array( 'result' => array( 'retrying' => time() ) ) );
		return self::later(
			$job['id'],
			static function () use ( $job ): void {
				Pipeline::retry_generation( $job['id'] );
				Jobs::update( $job['id'], array( 'result' => array( 'retrying' => 0 ) ) );
			}
		);
	}

	/**
	 * What the wizard sees of a job.
	 *
	 * @param array<string, mixed> $job The job (Jobs::get()).
	 * @return array<string, mixed>
	 */
	public static function view( array $job ): array {
		$status = $job['status'];
		$error  = (string) preg_replace( '/^[a-z]+: [a-z_]+: /', '', $job['last_error'] );
		if ( Jobs::is_stale( $job ) ) {
			$status = 'failed';
			$error  = __( 'Creating the site stopped before it finished. Try again to continue where it stopped.', 'mody_cloud' );
		}

		$view = array(
			'job'      => $job['id'],
			'status'   => $status,
			'step'     => $job['step'],
			'error'    => $error,
			'site'     => null,
			'fallback' => ! empty( $job['result']['fallback'] ),
			'retrying' => self::is_retrying( $job ),
		);

		$site_id = (int) $job['site_id'];
		if ( $site_id && get_site( $site_id ) ) {
			switch_to_blog( $site_id );
			$page_id      = (int) ( $job['result']['page_id'] ?? 0 );
			$view['site'] = array(
				'title'  => get_option( 'blogname' ),
				'url'    => home_url( '/' ),
				'admin'  => admin_url(),
				'editor' => $page_id ? SiteEditor::page_url( $page_id ) : SiteEditor::url( '/' ),
			);
			restore_current_blog();
		}
		return $view;
	}

	/**
	 * Stores an uploaded logo in the main site's media library, until the
	 * pipeline copies it into the new site (M2-05).
	 *
	 * @param array<string, mixed> $file The upload, as in $_FILES.
	 * @return int|WP_Error The attachment ID on the main site.
	 */
	private static function store_logo( array $file ): int|WP_Error {
		$invalid = new WP_Error(
			'mody_invalid_logo',
			/* translators: %s: the size limit, such as "2 MB". */
			sprintf( __( 'The logo must be a PNG, JPEG, GIF or WebP image of at most %s.', 'mody_cloud' ), size_format( self::MAX_LOGO_BYTES ) )
		);
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $file['tmp_name'] ) || (int) ( $file['size'] ?? 0 ) > self::MAX_LOGO_BYTES || filesize( $file['tmp_name'] ) > self::MAX_LOGO_BYTES ) {
			return $invalid;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// The file's content decides its type, not its name.
		$type = wp_check_filetype_and_ext( $file['tmp_name'], (string) $file['name'], Pipeline::LOGO_MIMES );
		if ( ! $type['type'] || ! in_array( $type['type'], Pipeline::LOGO_MIMES, true ) ) {
			return $invalid;
		}

		$restrict = static fn(): array => Pipeline::LOGO_MIMES;
		add_filter( 'upload_mimes', $restrict );
		switch_to_blog( get_main_site_id() );
		$id = media_handle_sideload(
			array(
				'name'     => sanitize_file_name( (string) $file['name'] ),
				'tmp_name' => $file['tmp_name'],
			),
			0
		);
		restore_current_blog();
		remove_filter( 'upload_mimes', $restrict );

		return is_wp_error( $id ) ? $invalid : (int) $id;
	}

	/**
	 * Runs a task after the response is sent, and answers with the job.
	 *
	 * @param int      $job_id Job ID.
	 * @param callable $task   The work.
	 * @return WP_REST_Response 202 with the job; 200 with the finished job when there is no PHP-FPM.
	 */
	private static function later( int $job_id, callable $task ): WP_REST_Response {
		if ( ! function_exists( 'fastcgi_finish_request' ) ) {
			$task();
			return new WP_REST_Response( self::view( Jobs::get( $job_id ) ) );
		}

		add_action(
			'shutdown',
			static function () use ( $task ): void {
				while ( ob_get_level() > 0 ) {
					ob_end_flush();
				}
				ignore_user_abort( true );
				fastcgi_finish_request();
				$task();
			},
			0
		);
		return new WP_REST_Response( self::view( Jobs::get( $job_id ) ), 202 );
	}

	/**
	 * The current user's job.
	 *
	 * @param int $job_id Job ID.
	 * @return array<string, mixed>|WP_Error 404 for an unknown job or another user's.
	 */
	private static function own_job( int $job_id ): array|WP_Error {
		$job = Jobs::get( $job_id );
		if ( null === $job || get_current_user_id() !== $job['user_id'] ) {
			return new WP_Error( 'mody_job_not_found', __( 'This site creation is unknown.', 'mody_cloud' ), array( 'status' => 404 ) );
		}
		return $job;
	}

	/**
	 * Whether a Retry generation is under way (and has not stopped).
	 *
	 * @param array<string, mixed> $job The job.
	 */
	private static function is_retrying( array $job ): bool {
		$started = (int) ( $job['result']['retrying'] ?? 0 );
		return $started > 0 && time() - $started < self::RETRY_TIMEOUT;
	}
}
