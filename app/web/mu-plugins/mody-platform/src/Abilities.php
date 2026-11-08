<?php
/**
 * Mody's agent actions as WordPress abilities (Abilities API).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform;

use Mody\Platform\AI\PageGenerator;
use Mody\Platform\Design\Presets;
use Mody\Platform\Design\SiteDesign;
use Mody\Platform\Site\PagePublisher;
use Mody\Platform\Site\SiteSettings;

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
						'place'    => array( 'type' => 'string' ),
						'navigate' => array( 'type' => 'boolean' ),
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

		wp_register_ability(
			'mody/open-editor',
			array(
				'label'               => __( 'Open the editor', 'mody_cloud' ),
				// Short on purpose (ai-engine.md, "Prompt size"): the targets are explained in SystemPrompt::for_agent().
				'description'         => __( 'Opens a Site Editor screen for the owner. Changes nothing.', 'mody_cloud' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'target'     => array(
							'type' => 'string',
							'enum' => SiteEditor::TARGETS,
						),
						'page_id'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'page_title' => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'template'   => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
						'list'       => array(
							'type'      => 'string',
							'maxLength' => 60,
						),
						'section'    => array(
							'type' => 'string',
							'enum' => array_keys( SiteEditor::SECTIONS ),
						),
						'block'      => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
					),
					'required'             => array( 'target' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'target'   => array( 'type' => 'string' ),
						'url'      => array( 'type' => 'string' ),
						'label'    => array( 'type' => 'string' ),
						'place'    => array( 'type' => 'string' ),
						'navigate' => array( 'type' => 'boolean' ),
						'page_id'  => array( 'type' => 'integer' ),
						'template' => array( 'type' => 'string' ),
					),
					'required'   => array( 'target', 'url', 'label' ),
				),
				'execute_callback'    => array( SiteEditor::class, 'open' ),
				'permission_callback' => array( SiteEditor::class, 'can_open' ),
				'meta'                => array(
					'public'      => true, // REST, MCP and the agent.
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		/*
		 * Kept short on purpose: with a longer description and a description
		 * per color (a 3.8 KB tools block), Qwen on Infomaniak often answered
		 * with bracketed transcript text instead of calling a tool (2026-11-06,
		 * documentation/ai-engine.md "Prompt size").
		 */
		$color = array(
			'type'    => 'string',
			'pattern' => SiteDesign::HEX_PATTERN,
		);
		wp_register_ability(
			'mody/update-design',
			array(
				'label'               => __( 'Update the design', 'mody_cloud' ),
				/* translators: %s: the preset names. */
				'description'         => sprintf( __( 'Changes the site\'s colors: a Mody design preset (%s), or new primary, secondary or accent colors as hex codes. Needs the brand profile.', 'mody_cloud' ), implode( ', ', array_column( Presets::all(), 'name' ) ) ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'preset'    => array(
							'type' => 'string',
							'enum' => Presets::slugs(),
						),
						'primary'   => $color,
						'secondary' => $color,
						'accent'    => $color,
						'brand'     => array(
							'type'        => 'boolean',
							'description' => __( 'True if the owner said the colors are part of their brand: the brand profile changes too.', 'mody_cloud' ),
						),
					),
					'required'             => array( 'brand' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'preset'                => array( 'type' => 'string' ),
						'colors'                => array(
							'type'                 => 'object',
							'additionalProperties' => array(
								'type'       => 'object',
								'properties' => array(
									'old' => array( 'type' => 'string' ),
									'new' => array( 'type' => 'string' ),
								),
							),
						),
						'brand_profile_updated' => array( 'type' => 'boolean' ),
						'url'                   => array( 'type' => 'string' ),
						'label'                 => array( 'type' => 'string' ),
						'place'                 => array( 'type' => 'string' ),
						'navigate'              => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'colors', 'brand_profile_updated', 'url', 'label' ),
				),
				'execute_callback'    => array( SiteDesign::class, 'update' ),
				'permission_callback' => array( SiteDesign::class, 'can_update' ),
				'meta'                => array(
					'public'      => true, // REST, MCP and the agent.
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		// Short declarations, for the same reason. When to publish is in the agent's system prompt.
		wp_register_ability(
			'mody/publish-page',
			array(
				'label'               => __( 'Publish page', 'mody_cloud' ),
				'description'         => __( 'Publishes a draft page of this site as it is now, found by ID or exact title.', 'mody_cloud' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'page_id'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'page_title' => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'page_id'  => array( 'type' => 'integer' ),
						'title'    => array( 'type' => 'string' ),
						'status'   => array(
							'type' => 'string',
							'enum' => array( 'publish' ),
						),
						'url'      => array( 'type' => 'string' ),
						'label'    => array( 'type' => 'string' ),
						'edit_url' => array( 'type' => 'string' ),
					),
					'required'   => array( 'page_id', 'title', 'status', 'url', 'label', 'edit_url' ),
				),
				'execute_callback'    => array( PagePublisher::class, 'publish' ),
				'permission_callback' => array( PagePublisher::class, 'can_publish' ),
				'meta'                => array(
					'public'      => true, // REST, MCP and the agent.
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		$change = array(
			'type'       => 'object',
			'properties' => array(
				'old' => array( 'type' => 'string' ),
				'new' => array( 'type' => 'string' ),
			),
		);
		wp_register_ability(
			'mody/update-site-settings',
			array(
				'label'               => __( 'Update site settings', 'mody_cloud' ),
				'description'         => __( 'Changes the site title, the tagline, or both.', 'mody_cloud' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'   => array(
							'type'      => 'string',
							'maxLength' => SiteSettings::FIELDS['title']['max'],
						),
						'tagline' => array(
							'type'      => 'string',
							'maxLength' => SiteSettings::FIELDS['tagline']['max'],
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'title'   => $change,
						'tagline' => $change,
					),
				),
				'execute_callback'    => array( SiteSettings::class, 'update' ),
				'permission_callback' => array( SiteSettings::class, 'can_update' ),
				'meta'                => array(
					'public'      => true, // REST, MCP and the agent.
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}
}
