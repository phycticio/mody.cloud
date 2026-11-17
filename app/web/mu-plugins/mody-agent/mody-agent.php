<?php
/**
 * Plugin Name:       Mody Agent
 * Description:       Mody, the site's AI agent: the chat in the editors, the pages it writes in the site's brand, and its tools, through a European model (Infomaniak AI Services).
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * Mody, the site's AI agent (mvp-spec section 8, documentation/ai-engine.md):
 *
 * - **The model** (AI/): Infomaniak AI Services as a provider for the
 *   WordPress AI Client, configured only by the environment
 *   (INFOMANIAK_API_KEY, INFOMANIAK_AI_BASE_URL); the prompts, the page
 *   writer and its validator, the block reference cards (reference/).
 * - **The chat** (ChatController, Agent, EditorBubble): the bubble in the
 *   editors and its REST endpoint (`mody-platform/v1`, the address it has
 *   always had), background jobs, the tool loop, the audit log
 *   (AgentEvents), each turn's tokens (TurnMetrics), the locks and token
 *   budgets (Slots, Budget, Hardening).
 * - **The tools** (Abilities, Site/, SiteEditor): abilities in the `mody`
 *   category, also for REST and MCP.
 *
 * Other plugins it works with when they are there, never needs: Mody Brand
 * (the brand in every prompt, rule 4), Mody Styles (the design tools), Mody
 * SEO (mody/update-seo), Mody Languages, Mody Login, Mody Admin Colors.
 * `mody_agent_site_locked` (filter) lets the plugin that bills a site stop
 * model work on it while it is read-only.
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md).
 *
 * @package Mody\Agent
 */

namespace Mody\Agent;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Agent\Example is src/Example.php.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( __NAMESPACE__ ) + 1 ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	}
);

add_action( 'init', array( AgentEvents::class, 'install' ), 1 );
add_filter( 'http_response', array( TurnMetrics::class, 'observe' ), 10, 3 ); // Each agent turn's tokens and cache (2026-11-11).

/*
 * Agent settings (Network Admin → Settings → Mody Agent): event retention.
 */
add_action( 'network_admin_menu', array( AgentSettings::class, 'register_menu' ) );
add_action( 'network_admin_edit_' . AgentSettings::SLUG, array( AgentSettings::class, 'save' ) );

/*
 * AI: register Infomaniak AI Services as a provider for the WordPress AI
 * Client (WordPress 7.0+). Core reads the registry on `init` at priority 15
 * (Settings > Connectors), so the provider must be registered before that.
 */
add_action(
	'init',
	static function (): void {
		if ( ! function_exists( 'wp_supports_ai' ) || ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
			return;
		}

		// Configuration comes only from the environment, never from the
		// database: INFOMANIAK_AI_BASE_URL here, INFOMANIAK_API_KEY read by
		// the AI Client itself. Without a base URL there is nothing to call.
		if ( '' === AI\InfomaniakProvider::configured_base_url() ) {
			return;
		}

		try {
			\WordPress\AiClient\AiClient::defaultRegistry()->registerProvider( AI\InfomaniakProvider::class );
		} catch ( \Exception $e ) {
			wp_trigger_error( __NAMESPACE__, $e->getMessage() );
		}
	},
	5
);

/*
 * The `ai` plugin's writing features get the site's brand in their system
 * instructions (rule 4, ticket M3-04). The plugin's source is not edited.
 */
add_filter( 'wpai_system_instruction', array( AI\PluginBrandContext::class, 'filter' ), 10, 2 );
AI\ConnectorApprovals::register(); // Mody's own MU plugins may use the Infomaniak connector (the ai plugin's approvals).
add_filter( 'mody_brand_context', array( AI\SystemPrompt::class, 'brand_context' ) ); // Shown at the foot of Settings → Brand (Mody Brand).

/*
 * Abilities: the agent's actions (mvp-spec section 8, "Option C").
 */
add_action( 'wp_abilities_api_categories_init', array( Abilities::class, 'register_category' ) );
add_action( 'wp_abilities_api_init', array( Abilities::class, 'register' ) );

/*
 * The chat: its REST endpoint and the bubble in the editors. Needs the AI
 * Client (WordPress 7.0+). The old wp-admin "Mody Agent" page opens the Site
 * Editor with the chat open.
 */
if ( function_exists( 'wp_ai_client_prompt' ) ) {
	add_action( 'rest_api_init', array( ChatController::class, 'register_routes' ) );
	add_action( 'admin_page_access_denied', array( EditorBubble::class, 'redirect_old_page' ) ); // Before WordPress refuses an unknown page.
	add_action( 'enqueue_block_editor_assets', array( EditorBubble::class, 'enqueue' ) ); // The chat bubble in the editors (M3-05).
	add_action( 'admin_init', array( EditorBubble::class, 'register_styles' ) ); // The chat's look, by handle, for other screens (before their load- hooks).
}

/*
 * Model work's limits (security pass, 2026-11-12): one job per user and
 * site, three on the network, the token budgets, the ai plugin's abilities,
 * server details for super admins only.
 */
Hardening::register();
