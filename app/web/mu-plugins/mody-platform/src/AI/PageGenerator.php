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
	public const TEMPERATURE = 0.1;

	/**
	 * Temperature of the retries. At TEMPERATURE the model repeats the same
	 * text, mistakes included, so a retry rarely fixed anything (2026-11-06).
	 */
	public const RETRY_TEMPERATURE = 0.4;

	/** Room for a full page. A typical page is 1,000 to 3,000 tokens. */
	private const MAX_TOKENS = 4000;

	/** Seconds per model request. The AI Client's default (30) is short for a full page. */
	private const TIMEOUT = 60;

	/** Largest answer for an edit: a whole page sent back, plus what is added (M3-06). */
	private const MAX_EDIT_TOKENS = 8000;

	/** Seconds per model request for a long edit. */
	private const EDIT_TIMEOUT = 120;

	/** Longest accepted member prompt, in characters. */
	public const MAX_PROMPT_LENGTH = 2000;

	/** The theme's page template without a title (theme.json customTemplates). */
	public const TEMPLATE = 'page-blank';

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

		$markup = self::ask( $site_id, SystemPrompt::for_page( $profile ), $prompt, '', self::MAX_TOKENS, 'generate_page' );
		if ( is_wp_error( $markup ) && 'mody_invalid_answers' === $markup->get_error_code() ) {
			return new WP_Error(
				'mody_generation_failed',
				/* translators: %d: number of attempts. */
				sprintf( __( 'The page could not be generated: the AI returned invalid content %d times.', 'mody_cloud' ), self::MAX_ATTEMPTS ),
				$markup->get_error_data()
			);
		}
		return $markup;
	}

	/**
	 * Changes existing block markup following the owner's instruction (M3-06):
	 * the selected block in the editor, or the whole content.
	 *
	 * Nothing is saved: the result goes back to the editor, where the member
	 * sees it, can undo it, and saves it. Blocks returned exactly as they
	 * were pass the validator as the member's own; everything new is checked.
	 *
	 * @param int    $site_id     Site whose brand profile is used.
	 * @param string $markup      The markup as it is in the editor (may be empty).
	 * @param string $instruction What the owner asked for.
	 * @param bool   $fragment    True for one selected block, false for the whole content.
	 * @return string|WP_Error The validated new markup, or `mody_empty_prompt`,
	 *                         `mody_no_brand_profile`, an AI Client error, `mody_edit_failed`.
	 */
	public static function edit_markup( int $site_id, string $markup, string $instruction, bool $fragment ): string|WP_Error {
		$instruction = trim( mb_substr( sanitize_textarea_field( $instruction ), 0, self::MAX_PROMPT_LENGTH ) );
		if ( '' === $instruction ) {
			return new WP_Error( 'mody_empty_prompt', __( 'Say what to change.', 'mody_cloud' ) );
		}
		$profile = BrandProfile::for_site( $site_id );
		if ( null === $profile ) {
			return new WP_Error( 'mody_no_brand_profile', __( 'This site has no brand profile yet. Complete the brand wizard first.', 'mody_cloud' ) );
		}

		$prompt = "The site owner's instruction:\n<instruction>\n" . $instruction . "\n</instruction>\n\n"
			. ( $fragment ? 'The selected block, with its inner blocks:' : 'The whole content:' )
			. "\n<markup>\n" . $markup . "\n</markup>";
		// Room for the whole content again, plus what the instruction adds.
		$tokens = min( self::MAX_EDIT_TOKENS, max( self::MAX_TOKENS, (int) ceil( strlen( $markup ) / 3 ) + 1500 ) );

		$result = self::ask( $site_id, SystemPrompt::for_edit( $profile, $fragment ), $prompt, $markup, $tokens, 'edit_markup' );
		if ( is_wp_error( $result ) && 'mody_invalid_answers' === $result->get_error_code() ) {
			return new WP_Error(
				'mody_edit_failed',
				/* translators: %d: number of attempts. */
				sprintf( __( 'The change could not be made: the AI returned invalid content %d times.', 'mody_cloud' ), self::MAX_ATTEMPTS ),
				$result->get_error_data()
			);
		}
		return $result;
	}

	/**
	 * Asks the page writer, repairs and validates its answer, and retries with
	 * the reason: the loop shared by generation and edits.
	 *
	 * @param int    $site_id    Site ID, for the log.
	 * @param string $system     System prompt (always with the brand, rule 4).
	 * @param string $prompt     User prompt.
	 * @param string $original   For an edit: the markup before it (see BlockValidator).
	 * @param int    $max_tokens Answer size.
	 * @param string $label      Name in the log.
	 * @return string|WP_Error Valid markup, an AI Client error, or `mody_invalid_answers`
	 *                         after MAX_ATTEMPTS invalid answers.
	 */
	private static function ask( int $site_id, string $system, string $prompt, string $original, int $max_tokens, string $label ): string|WP_Error {
		$feedback = '';
		$problem  = '';
		$timeout  = $max_tokens > self::MAX_TOKENS ? self::EDIT_TIMEOUT : self::TIMEOUT;

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$output = wp_ai_client_prompt( $prompt . $feedback )
				->using_system_instruction( $system )
				->using_provider( 'infomaniak' )
				->using_temperature( 1 === $attempt ? self::TEMPERATURE : self::RETRY_TEMPERATURE )
				->using_max_tokens( $max_tokens )
				->using_request_options( RequestOptions::fromArray( array( RequestOptions::KEY_TIMEOUT => $timeout ) ) )
				->generate_text();

			// A client error (network, auth, timeout) is not something a retry with feedback fixes.
			if ( is_wp_error( $output ) ) {
				self::log( $site_id, $attempt, 'AI Client error ' . $output->get_error_code() . ': ' . $output->get_error_message(), $label );
				return $output;
			}

			$markup = self::repair( trim( $output ), $site_id, $attempt );
			$valid  = BlockValidator::validate( $markup, $original );
			if ( true === $valid ) {
				return $markup;
			}

			$problem = $valid->get_error_message();
			self::log( $site_id, $attempt, 'invalid markup: ' . $problem, $label );
			$feedback = "\n\n(Your previous answer was rejected: " . $problem . ' Return the whole markup again, following every output rule.)';
		}

		return new WP_Error(
			'mody_invalid_answers',
			'Invalid answers.',
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
	 * through the model. Publishing is left to the member (mody/publish-page).
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

		$page_id = self::insert_page( $markup, array( 'post_title' => $title ) );
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
	 * Repairs the one model typo seen often enough to need it, before validation.
	 *
	 * Qwen sometimes drops the `p>` of a paragraph's opening tag: `<p>Packed
	 * with seeds…</p>` comes back as `<Packed with seeds…</p>`, and the
	 * retries repeated it (ai-engine.md, "Validation pipeline"). Only a
	 * paragraph block whose HTML starts with `<` and a capital letter is
	 * touched: no HTML tag starts that way in generated markup. The result is
	 * validated like any other answer.
	 *
	 * @param string $markup  The model's answer, trimmed.
	 * @param int    $site_id Site ID, for the log.
	 * @param int    $attempt Attempt number, for the log.
	 */
	public static function repair( string $markup, int $site_id = 0, int $attempt = 0 ): string {
		$repaired = preg_replace( '/(<!-- wp:paragraph(?: \{[^\n]*?\})? -->\s*)<(?=[A-Z])/', '$1<p>', $markup, -1, $count );
		if ( ! is_string( $repaired ) ) {
			return $markup;
		}
		if ( $count && $site_id ) {
			self::log( $site_id, $attempt, "repaired $count paragraph opening tag(s)" );
		}
		return $repaired;
	}

	/**
	 * Saves block markup as a page on the current site.
	 *
	 * The page uses the theme's template without a title (TEMPLATE) when the
	 * theme has it: generated pages start with their own level-1 heading, and
	 * the default page template would show the title a second time.
	 *
	 * @param string               $markup Block markup, already validated.
	 * @param array<string, mixed> $args   wp_insert_post() arguments that replace the defaults:
	 *                                     a draft by the current user, titled with the first
	 *                                     level-1 heading (an empty post_title means the same).
	 * @return int|WP_Error The page ID.
	 */
	public static function insert_page( string $markup, array $args = array() ): int|WP_Error {
		$title = sanitize_text_field( (string) ( $args['post_title'] ?? '' ) );
		if ( '' === $title ) {
			$title = self::first_heading( $markup );
		}

		$defaults = array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_content' => $markup,
			'post_author'  => get_current_user_id(),
		);
		if ( isset( wp_get_theme()->get_page_templates( null, 'page' )[ self::TEMPLATE ] ) ) {
			$defaults['page_template'] = self::TEMPLATE;
		}

		$args['post_title'] = '' !== $title ? $title : __( 'New page', 'mody_cloud' );

		return wp_insert_post( wp_slash( array_merge( $defaults, $args ) ), true );
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
	public static function first_heading( string $markup ): string {
		// Decoded: the title is stored as text ("I'm", not "I&#039;m").
		return preg_match( '/<h1\b[^>]*>(.*?)<\/h1>/is', $markup, $match )
			? trim( html_entity_decode( wp_strip_all_tags( $match[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) )
			: '';
	}

	/**
	 * Logs a failure to the PHP error log. Never the prompt or the token.
	 *
	 * @param int    $site_id Site ID.
	 * @param int    $attempt Attempt number.
	 * @param string $message What went wrong.
	 * @param string $label   What was running: generate_page or edit_markup.
	 */
	private static function log( int $site_id, int $attempt, string $message, string $label = 'generate_page' ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Server log; never the prompt or the token.
		error_log( sprintf( '[mody-platform] %s site %d attempt %d/%d: %s', $label, $site_id, $attempt, self::MAX_ATTEMPTS, $message ) );
	}
}
