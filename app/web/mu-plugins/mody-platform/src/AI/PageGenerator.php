<?php
/**
 * Page generation: brand profile -> model -> validator.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\AI;

use Mody\Platform\Brand\BrandProfile;
use Mody\Platform\SiteEditor;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Generates validated block markup for a page, and saves it as a draft.
 *
 * generate_page() is the only path from a member's page request to the model,
 * and it refuses to run without the site's brand profile (rule 4).
 */
final class PageGenerator {

	/** The first try plus 2 retries (mvp-spec section 7). */
	public const MAX_ATTEMPTS = 3;

	/** Low temperature for valid syntax (ai-engine.md, "Master prompt rules"). */
	private const TEMPERATURE = 0.1;

	/** Room for a full page. A typical page is 1,000 to 3,000 tokens. */
	private const MAX_TOKENS = 4000;

	/** Seconds per model request. The AI Client's default (30) is short for a full page. */
	private const TIMEOUT = 60;

	/** Longest accepted member prompt, in characters. */
	public const MAX_PROMPT_LENGTH = 2000;

	/**
	 * Generates the block markup of a page for a site.
	 *
	 * The blocks are validated against the registry of the current request,
	 * so call this on the target site (core blocks are registered everywhere).
	 *
	 * @param int    $site_id Site (blog) ID whose brand profile is used.
	 * @param string $prompt  The member's description of the page.
	 * @return string|WP_Error Validated markup, or an error: `mody_empty_prompt`,
	 *                         `mody_no_brand_profile`, an AI Client error, or
	 *                         `mody_generation_failed` after MAX_ATTEMPTS invalid answers.
	 */
	public static function generate_page( int $site_id, string $prompt ): string|WP_Error {
		$prompt = trim( mb_substr( sanitize_textarea_field( $prompt ), 0, self::MAX_PROMPT_LENGTH ) );
		if ( '' === $prompt ) {
			return new WP_Error( 'mody_empty_prompt', __( 'Describe the page you want.', 'mody_cloud' ) );
		}

		$profile = BrandProfile::for_site( $site_id );
		if ( null === $profile ) {
			return new WP_Error( 'mody_no_brand_profile', __( 'This site has no brand profile yet. Complete the brand wizard first.', 'mody_cloud' ) );
		}

		$system   = SystemPrompt::for_page( $profile );
		$feedback = '';
		$problem  = '';

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$output = wp_ai_client_prompt( $prompt . $feedback )
				->using_system_instruction( $system )
				->using_provider( 'infomaniak' )
				->using_temperature( self::TEMPERATURE )
				->using_max_tokens( self::MAX_TOKENS )
				->using_request_options( RequestOptions::fromArray( array( RequestOptions::KEY_TIMEOUT => self::TIMEOUT ) ) )
				->generate_text();

			// A client error (network, auth, timeout) is not something a retry with feedback fixes.
			if ( is_wp_error( $output ) ) {
				self::log( $site_id, $attempt, 'AI Client error ' . $output->get_error_code() . ': ' . $output->get_error_message() );
				return $output;
			}

			$markup = trim( $output );
			$valid  = BlockValidator::validate( $markup );
			if ( true === $valid ) {
				return $markup;
			}

			$problem = $valid->get_error_message();
			self::log( $site_id, $attempt, 'invalid markup: ' . $problem );
			$feedback = "\n\n(Your previous answer was rejected: " . $problem . ' Return the whole page again, following every output rule.)';
		}

		return new WP_Error(
			'mody_generation_failed',
			/* translators: %d: number of attempts. */
			sprintf( __( 'The page could not be generated: the AI returned invalid content %d times.', 'mody_cloud' ), self::MAX_ATTEMPTS ),
			array(
				'attempts' => self::MAX_ATTEMPTS,
				'reason'   => $problem,
			)
		);
	}

	/**
	 * Generates a page on the current site and saves it as a draft.
	 *
	 * The validated markup goes straight into the post; it never passes back
	 * through the model. Publishing and the front page are left to the caller
	 * (provisioning in M2-01, the agent in M3-01).
	 *
	 * @param string $prompt The member's description of the page.
	 * @param string $title  Page title. Defaults to the generated level-1 heading.
	 * @return array{page_id: int, title: string, status: string, edit_url: string}|WP_Error
	 */
	public static function create_draft( string $prompt, string $title = '' ): array|WP_Error {
		$markup = self::generate_page( get_current_blog_id(), $prompt );
		if ( is_wp_error( $markup ) ) {
			return $markup;
		}

		$title = sanitize_text_field( $title );
		if ( '' === $title ) {
			$title = self::first_heading( $markup );
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => '' !== $title ? $title : __( 'New page', 'mody_cloud' ),
				'post_content' => wp_slash( $markup ),
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		return array(
			'page_id'  => $page_id,
			'title'    => get_the_title( $page_id ),
			'status'   => 'draft',
			'edit_url' => self::site_editor_url( $page_id ),
		);
	}

	/**
	 * The Site Editor URL of a page (mvp-spec section 9). See SiteEditor.
	 *
	 * @param int $page_id Page ID on the current site.
	 */
	public static function site_editor_url( int $page_id ): string {
		return SiteEditor::page_url( $page_id );
	}

	/**
	 * Plain text of the first level-1 heading, or ''.
	 *
	 * @param string $markup Validated block markup.
	 */
	private static function first_heading( string $markup ): string {
		return preg_match( '/<h1\b[^>]*>(.*?)<\/h1>/is', $markup, $match )
			? trim( wp_strip_all_tags( $match[1] ) )
			: '';
	}

	/**
	 * Logs a failure to the PHP error log. Never the prompt or the token.
	 *
	 * @param int    $site_id Site ID.
	 * @param int    $attempt Attempt number.
	 * @param string $message What went wrong.
	 */
	private static function log( int $site_id, int $attempt, string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Server log; mody_agent_events arrives with M3.
		error_log( sprintf( '[mody-platform] generate_page site %d attempt %d/%d: %s', $site_id, $attempt, self::MAX_ATTEMPTS, $message ) );
	}
}
