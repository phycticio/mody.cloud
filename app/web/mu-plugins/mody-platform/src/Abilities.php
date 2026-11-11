<?php
/**
 * Mody's agent actions as WordPress abilities (Abilities API).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform;

use Mody\Platform\AI\PageGenerator;
use Mody\Platform\Brand\BrandSettings;
use Mody\Platform\Design\Presets;
use Mody\Platform\Design\SiteDesign;
use Mody\Platform\Design\SiteStyles;
use Mody\Platform\Site\PagePublisher;
use Mody\Platform\Site\Menu;
use Mody\Platform\Site\Settings;
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
				'description' => __( 'Actions of the Mody Cloud agent on a member site, always faithful to the site\'s brand: its settings and styles.', 'mody_cloud' ),
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
				'description'         => __( 'Generates a new page from a description, in the site\'s brand identity, and saves it as a draft.', 'mody_cloud' ),
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
							'description' => __( 'Optional page title.', 'mody_cloud' ),
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
				'description'         => sprintf( __( 'Changes the site\'s colors: a Mody design preset (%s), or new primary, secondary or accent colors as hex codes.', 'mody_cloud' ), implode( ', ', array_column( Presets::all(), 'name' ) ) ),
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
					),
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
						'url'                   => array( 'type' => 'string' ),
						'label'                 => array( 'type' => 'string' ),
						'place'                 => array( 'type' => 'string' ),
						'navigate'              => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'colors', 'url', 'label' ),
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

		/*
		 * M3-10 (2026-11-08): every other part of the site's styles. One short
		 * sentence and one property (ai-engine.md, "Prompt size"); the paths
		 * and presets are in SystemPrompt::styles_context().
		 */
		wp_register_ability(
			'mody/update-styles',
			array(
				'label'               => __( 'Update the styles', 'mody_cloud' ),
				'description'         => __( 'Changes the site\'s styles: `changes`, a partial theme.json merged into them.', 'mody_cloud' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'changes' => array( 'type' => 'object' ),
					),
					'required'             => array( 'changes' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'changed'  => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'path' => array( 'type' => 'string' ),
									'old'  => array( 'type' => 'string' ),
									'new'  => array( 'type' => 'string' ),
								),
							),
						),
						'url'      => array( 'type' => 'string' ),
						'label'    => array( 'type' => 'string' ),
						'place'    => array( 'type' => 'string' ),
						'navigate' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'changed', 'url', 'label' ),
				),
				'execute_callback'    => array( SiteStyles::class, 'update' ),
				'permission_callback' => array( SiteStyles::class, 'can_update' ),
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

		/*
		 * 2026-11-11 (tickets/agent-knows-gutenberg-spectra-settings.md): the
		 * site's settings by name, in tiers. One short sentence and one
		 * property; the names, current values and tiers are in the agent's
		 * system prompt (Settings::catalog()). The agent holds a call with a
		 * setting of the `confirm` tier for the owner (Agent::hold()).
		 */
		wp_register_ability(
			'mody/update-settings',
			array(
				'label'               => __( 'Update settings', 'mody_cloud' ),
				'description'         => __( 'Changes the site\'s settings: `changes`, setting name => new value.', 'mody_cloud' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'changes' => array(
							'type'          => 'object',
							'minProperties' => 1,
						),
					),
					'required'             => array( 'changes' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'changed' => array( 'type' => 'object' ),
					),
					'required'   => array( 'changed' ),
				),
				'execute_callback'    => array( Settings::class, 'update' ),
				'permission_callback' => array( Settings::class, 'can_update' ),
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

		/*
		 * 2026-11-11, part 3: the header's menu as a whole list of items. The
		 * current items and how to write them are in the agent's system prompt
		 * (Menu::context()); the blocks are built in PHP.
		 */
		wp_register_ability(
			'mody/update-menu',
			array(
				'label'               => __( 'Update the menu', 'mody_cloud' ),
				'description'         => __( 'Replaces the site\'s menu: `items`, all its links in order.', 'mody_cloud' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'items' => array( 'type' => 'array' ),
					),
					'required'             => array( 'items' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'old'   => array( 'type' => 'array' ),
						'new'   => array( 'type' => 'array' ),
						'url'   => array( 'type' => 'string' ),
						'label' => array( 'type' => 'string' ),
					),
					'required'   => array( 'old', 'new' ),
				),
				'execute_callback'    => array( Menu::class, 'update' ),
				'permission_callback' => array( Menu::class, 'can_update' ),
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
				'description'         => __( 'Changes the site\'s identity: title, tagline, mission, tone.', 'mody_cloud' ),
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
						'mission' => array(
							'type'      => 'string',
							'maxLength' => SiteSettings::FIELDS['mission']['max'],
						),
						'tone'    => array(
							'type' => 'string',
							'enum' => BrandSettings::TONES,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'title'   => $change,
						'tagline' => $change,
						'mission' => $change,
						'tone'    => $change,
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
