<?php
/**
 * Mody's agent actions as WordPress abilities (Abilities API).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform;

use Mody\Platform\AI\PageGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `mody` ability category and its abilities ("Option C",
 * decided 2026-11-06, documentation/mvp-spec.md section 8).
 *
 * Abilities are the agent's tools: the in-site agent offers them to the model
 * with `using_abilities()`, and MCP clients see them through mcp-adapter. Each
 * one checks capabilities in its permission callback, and its input against
 * its schema, before it runs.
 */
final class Abilities {

	/** The ability category slug. */
	public const CATEGORY = 'mody';

	/**
	 * Hooked to `wp_abilities_api_categories_init`.
	 */
	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Mody Cloud', 'mody_cloud' ),
				'description' => __( 'Actions of the Mody Cloud agent on a member site, always faithful to the site\'s brand profile.', 'mody_cloud' ),
			)
		);
	}

	/**
	 * Hooked to `wp_abilities_api_init`.
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return;
		}

		wp_register_ability(
			'mody/generate-page',
			array(
				'label'               => __( 'Generate page', 'mody_cloud' ),
				'description'         => __( 'Generates a new page from a description, in the site\'s brand identity, and saves it as a draft. Returns the page ID and the Site Editor link to review and publish it. Needs the site\'s brand profile.', 'mody_cloud' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'prompt' => array(
							'type'        => 'string',
							'description' => __( 'What the page should contain, in the member\'s words. The page is written in the language of this text.', 'mody_cloud' ),
							'minLength'   => 3,
							'maxLength'   => PageGenerator::MAX_PROMPT_LENGTH,
						),
						'title'  => array(
							'type'        => 'string',
							'description' => __( 'Optional page title. Defaults to the generated main heading.', 'mody_cloud' ),
							'maxLength'   => 200,
						),
					),
					'required'             => array( 'prompt' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'page_id'  => array( 'type' => 'integer' ),
						'title'    => array( 'type' => 'string' ),
						'status'   => array(
							'type' => 'string',
							'enum' => array( 'draft' ),
						),
						'edit_url' => array( 'type' => 'string' ),
					),
					'required'   => array( 'page_id', 'title', 'status', 'edit_url' ),
				),
				'execute_callback'    => static fn( $input ) => PageGenerator::create_draft( (string) $input['prompt'], (string) ( $input['title'] ?? '' ) ),
				'permission_callback' => static fn() => is_user_logged_in() && current_user_can( 'edit_pages' ),
				'meta'                => array(
					'public'      => true, // REST, MCP and the agent.
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);
	}
}
