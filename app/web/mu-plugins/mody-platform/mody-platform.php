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
 * The Mody dashboard (2026-11-11, tickets/mody-dashboard.md): every site's
 * Dashboard menu item opens it, and core's dashboard redirects there. Each
 * site has 1 GB, with WordPress's own upload quota (Mody Storage).
 */
Dashboard\DashboardPage::register();
if ( class_exists( \Mody\Analytics\Query::class ) ) {
	Dashboard\Board::register(); // The Visitors section's board of charts (2026-11-12, tickets/analytics-chat.md).
	if ( function_exists( 'wp_ai_client_prompt' ) ) {
		Dashboard\Analyst::register(); // Mody on that board: reads only the analytics, changes only the board (phase 2).
	}
}

add_action( 'init', array( Agent\AgentEvents::class, 'install' ), 1 );
add_filter( 'http_response', array( Agent\TurnMetrics::class, 'observe' ), 10, 3 ); // Each agent turn's tokens and cache (2026-11-11).
add_action( 'init', array( Provisioning\Jobs::class, 'install' ), 1 );
add_action( 'init', array( Members\Members::class, 'install' ), 1 );

/*
 * Paying with a card through Mollie (M1-01 part 3, 2026-11-13): adding a
 * card, the charges, the webhook, the 14-day withdrawal, the reminders and
 * the daily run (`wp mody-billing run`). Off without MOLLIE_API_KEY.
 */
Billing\Billing::register();

/*
 * Member accounts (M1-01, part 1): a `mody_members` row gives the dashboard
 * capability (mvp-spec section 3) and the plan's site limit (section 4).
 */
add_filter( 'user_has_cap', array( Members\Members::class, 'grant_dashboard' ), 10, 3 );
add_filter( 'mody_site_limit', array( Members\Members::class, 'site_limit' ), 10, 2 );
add_filter( 'mody_can_create_site', array( Members\Members::class, 'can_create_site' ), 10, 2 ); // No site before the card is verified.

/*
 * Registration (M1-01 part 2, M1-03): the `mody/register` block on the main
 * site's /register/ page, a chat that creates the account, a place of the
 * launch offer (six months free, no card) and the first site, then emails
 * the set-password link.
 * Closed until the network option `mody_registration_open` is set. Members
 * log in to the Mody dashboard.
 */
add_action( 'init', array( Members\Registration::class, 'register_block' ) );
add_action( 'init', array( Members\Registration::class, 'ensure_page' ), 20 );
add_action( 'rest_api_init', array( Members\Registration::class, 'register_routes' ) );
add_action( 'template_redirect', array( Members\Registration::class, 'handle' ) );
add_action( 'template_redirect', array( Members\Registration::class, 'handle_pay' ) ); // The pay page of a paid sign-up (M1-01 part 3b).
add_filter( 'login_redirect', array( Members\Registration::class, 'login_redirect' ), 10, 3 );

/*
 * Onboarding (Mody Onboarding, M1-03): where a member without a site goes
 * after their first password, and when their free months end, for the tour.
 */
add_filter( 'mody_onboarding_no_site_url', array( Members\Registration::class, 'dashboard_url' ) );
add_filter( 'mody_onboarding_free_until', static fn( $ends, $user_id ) => Members\Members::free_until( (int) $user_id ), 10, 2 );

/*
 * Agent settings (Network Admin > Settings > Mody Agent): event retention.
 */
add_action( 'network_admin_menu', array( Agent\AgentSettings::class, 'register_menu' ) );

/*
 * Offers (Network Admin → Mody → Offers, tickets/invites-and-offers.md): the
 * launch offer's numbers and who may take a place, and invites on or off.
 * Invite friends is a section of every member's Dashboard (Members\Invites).
 */
add_action( 'network_admin_menu', array( Members\Offers::class, 'register_menu' ), 20 );
add_action( 'network_admin_edit_' . Members\Offers::SLUG, array( Members\Offers::class, 'save' ) );
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
add_filter( 'mody_brand_context', array( AI\SystemPrompt::class, 'brand_context' ) ); // Shown at the foot of Settings → Brand (Mody Brand).

/*
 * Abilities: the agent's actions (mvp-spec section 8, "Option C").
 */
add_action( 'wp_abilities_api_categories_init', array( Abilities::class, 'register_category' ) );
add_action( 'wp_abilities_api_init', array( Abilities::class, 'register' ) );

/*
 * Agent chat: its REST endpoint and the chat bubble in the editors
 * (mvp-spec section 8). Needs the AI Client (WordPress 7.0+). The wp-admin
 * "Mody Agent" page was removed on 2026-11-11; its address opens the Site
 * Editor with the chat open.
 */
if ( function_exists( 'wp_ai_client_prompt' ) ) {
	add_action( 'rest_api_init', array( Agent\ChatController::class, 'register_routes' ) );
	add_action( 'admin_page_access_denied', array( Agent\EditorBubble::class, 'redirect_old_page' ) ); // Before WordPress refuses an unknown page.
	add_action( 'enqueue_block_editor_assets', array( Agent\EditorBubble::class, 'enqueue' ) ); // The chat bubble in the editors (M3-05).
}

/*
 * Security pass (2026-11-12, tickets/M5-01-hardening-launch.md): one line per
 * group; each group's class registers its own hooks.
 */
Agent\Hardening::register(); // Model work: one job per user and site, three on the network, a daily token allowance, the ai plugin's abilities, server details for super admins only.

/*
 * The plan's sites are the ones a member owns (security pass, 2026-11-12).
 */
add_action( 'wp_initialize_site', array( Provisioning\Quota::class, 'on_initialize_site' ), 10, 2 );
add_action( 'admin_init', array( Provisioning\Quota::class, 'backfill' ) );
