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
 * Served before the rest of WordPress runs.
 */
Export\Downloads::serve(); // A site's download files, through signed links (tickets/take-your-site-with-you.md).

/*
 * Network-wide tables (mvp-spec section 11). Creates or updates them only
 * when their version changed.
 */
add_action( 'init', array( Brand\BrandSettings::class, 'migrate' ), 1 ); // Once per network: the old profile table into each site's settings, then dropped (M2-08).

/*
 * The brand is each site's own WordPress data (M2-08): title, tagline, logo
 * and icon (Identity), its Global Styles, and two site settings WordPress
 * lacks, mission and tone, shown on the Identity screen and in Settings →
 * General.
 */
add_action( 'init', array( Brand\BrandSettings::class, 'register' ) );
add_action( 'admin_init', array( Brand\BrandSettings::class, 'general_fields' ) );
add_action( 'admin_enqueue_scripts', array( Brand\BrandSettings::class, 'enqueue_identity_fields' ) );

/*
 * Your brand (2026-11-11, tickets/your-brand-page.md): Settings → Brand, the
 * rest of what Mody knows about the business (voice, audience, offer, story,
 * values, facts) in one site setting, read by every prompt.
 */
add_action( 'init', array( Brand\BrandBook::class, 'register' ) );
add_action( 'admin_menu', array( Brand\BrandPage::class, 'register_menu' ) );
add_action( 'admin_post_' . Brand\BrandPage::ACTION, array( Brand\BrandPage::class, 'save' ) );

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

/*
 * Take your site with you (2026-11-12, tickets/take-your-site-with-you.md):
 * the dashboard's Download your site section (Dashboard\ExportPanel, wired by
 * DashboardPage) builds three files in a detached `wp mody-export run`
 * process: the database, the media and the code with the Mody Import plugin.
 */
add_action( 'init', array( Export\Jobs::class, 'install' ), 1 );
Export\Command::register();
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
 * Onboarding (M1-03): the first password logs the member in on their site's
 * Identity screen, where the editor tour shows the agent, then the editor.
 */
add_action( 'init', array( Onboarding\Onboarding::class, 'register_meta' ) );
add_action( 'after_password_reset', array( Onboarding\Onboarding::class, 'after_password_reset' ) );
add_action( 'enqueue_block_editor_assets', array( Onboarding\EditorTour::class, 'enqueue' ), 20 ); // After the chat bubble.
add_filter( 'rest_prepare_user', array( Onboarding\Onboarding::class, 'hide_stage' ), 10, 3 ); // The tour's stage is nobody else's business (security pass).

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
 * Member sites (every site but the main one, ticket M4-01): no plugin
 * installs or theme changes for members, and pages, patterns and menus open
 * in the Site Editor. Super admins are not affected. The hidden plugins stay
 * out of every list and switch (M4-02), although nobody has a Plugins screen
 * since 2026-11-11 (below).
 */
add_filter( 'map_meta_cap', array( Gating\MemberSite::class, 'map_meta_cap' ), 10, 4 );
add_filter( 'all_plugins', array( Gating\MemberPlugins::class, 'filter_screen' ) );
add_filter( 'pre_update_option_active_plugins', array( Gating\MemberPlugins::class, 'keep_hidden' ), 10, 2 ); // Any code path, e.g. Spectra's onboarding API.
add_filter( 'rest_pre_dispatch', array( Gating\MemberPlugins::class, 'refuse_rest_item' ), 10, 3 );
add_filter( 'rest_request_after_callbacks', array( Gating\MemberPlugins::class, 'filter_rest_list' ), 10, 3 );
add_action( 'load-post.php', array( Gating\MemberSite::class, 'redirect_editor' ) );
add_action( 'load-post-new.php', array( Gating\MemberSite::class, 'redirect_editor' ) );

/*
 * No Plugins or Connectors screens for anyone, super admins included
 * (decided 2026-11-11, tickets/hide-plugins-and-connectors.md): plugins
 * change only through Composer, ./bin/deploy and WP-CLI, and connector keys
 * only through the environment.
 */
add_action( 'admin_menu', array( Gating\HiddenScreens::class, 'hide_menus' ), 999 );
add_action( 'network_admin_menu', array( Gating\HiddenScreens::class, 'hide_menus' ), 999 );
add_action( 'admin_bar_menu', array( Gating\HiddenScreens::class, 'hide_toolbar' ), 999 );
add_action( 'wp_dashboard_setup', array( Gating\HiddenScreens::class, 'hide_widgets' ), 999 );
add_action( 'admin_notices', array( Gating\HiddenScreens::class, 'hide_notices' ), 0 ); // The ai plugin's notice that links to the hidden approvals screen.
add_action( 'admin_init', array( Gating\HiddenScreens::class, 'refuse_screens' ) );
add_filter( 'register_setting_args', array( Gating\HiddenScreens::class, 'hide_settings' ), 10, 4 );
add_filter( 'rest_pre_dispatch', array( Gating\HiddenScreens::class, 'refuse_routes' ), 10, 3 );

/*
 * The `ai` plugin on member sites (ticket M4-03): the curated features and the
 * connector approval pinned, its screens and configuration routes refused to
 * members, MCP locked, and its request log table created for new sites.
 */
add_filter( 'pre_option', array( Gating\AiPlugin::class, 'pin_option' ), 10, 2 );
add_action( 'admin_menu', array( Gating\AiPlugin::class, 'hide_screens' ), 999 );
add_action( 'wp_dashboard_setup', array( Gating\AiPlugin::class, 'hide_widgets' ), 999 );
add_action( 'admin_init', array( Gating\AiPlugin::class, 'refuse_screens' ) );
add_filter( 'rest_pre_dispatch', array( Gating\AiPlugin::class, 'lock_routes' ), 10, 3 );
add_action( 'wp_initialize_site', array( Gating\AiPlugin::class, 'create_log_table' ), 200 );

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
Gating\Isolation::register(); // Widgets, Application Passwords, deleting a site, owners, mail limits and picture sizes (SpectraGuard handles block script).
