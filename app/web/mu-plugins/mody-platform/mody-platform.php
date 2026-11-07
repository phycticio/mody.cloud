<?php
/**
 * Mody Platform bootstrap.
 *
 * See documentation/mvp-spec.md (section 12) for what belongs in this plugin,
 * and documentation/ai-engine.md for the AI integration.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Platform\AI\BlockValidator is src/AI/BlockValidator.php.
 * The AI classes extend core's AI Client, so they are only referenced after
 * checking that the client exists.
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

/*
 * Spectra: switch off everything that connects to a non-European service
 * (rule 1). Runs now, before plugins load, so it is in place before Spectra.
 */
Spectra\SpectraGuard::boot();

/*
 * Network-wide tables (mvp-spec section 11). Creates or updates them only
 * when their version changed.
 */
add_action( 'init', array( Brand\BrandProfile::class, 'install' ), 1 );
add_action( 'init', array( Agent\AgentEvents::class, 'install' ), 1 );
add_action( 'init', array( Provisioning\Jobs::class, 'install' ), 1 );

/*
 * Agent settings (Network Admin > Settings > Mody Agent): event retention.
 */
add_action( 'network_admin_menu', array( Agent\AgentSettings::class, 'register_menu' ) );
add_action( 'network_admin_edit_' . Agent\AgentSettings::SLUG, array( Agent\AgentSettings::class, 'save' ) );

/*
 * Brand wizard (Network Admin → Mody → New site, ticket M2-03): its page and
 * its REST endpoint, which runs the provisioning pipeline after the response.
 */
add_action( 'network_admin_menu', array( Wizard\WizardPage::class, 'register_menu' ) );
add_action( 'rest_api_init', array( Wizard\WizardController::class, 'register_routes' ) );
add_action( 'template_redirect', array( Wizard\WizardPreview::class, 'maybe_render' ), 0 ); // Step 2's live preview (M2-04).

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

/*
 * Abilities: the agent's actions (mvp-spec section 8, "Option C").
 */
add_action( 'wp_abilities_api_categories_init', array( Abilities::class, 'register_category' ) );
add_action( 'wp_abilities_api_init', array( Abilities::class, 'register' ) );

/*
 * Agent chat: the "Mody Agent" page in wp-admin and its REST endpoint
 * (mvp-spec section 8). Needs the AI Client (WordPress 7.0+).
 */
if ( function_exists( 'wp_ai_client_prompt' ) ) {
	add_action( 'rest_api_init', array( Agent\ChatController::class, 'register_routes' ) );
	add_action( 'admin_menu', array( Agent\ChatPage::class, 'register_menu' ) );
}
