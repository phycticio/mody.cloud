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
 * Spectra is a MU plugin (M3-11, decided 2026-11-08): mu-plugins/spectra-blocks.php
 * loads it after this file. The copy it had as a network-active plugin
 * must never load beside it.
 */
Spectra\SpectraLoader::boot();

/*
 * The design market (built 2026-11-08): every site's patterns are the Mody
 * collection (patterns/collection.json), rewritten from WordPress.org's
 * Pattern Directory and core's patterns; nothing is fetched from
 * WordPress.org. Placeholder images are served here, before the rest of
 * WordPress runs.
 */
Design\Patterns::serve_placeholder();
Users\Avatars::serve_initials(); // Initials in place of Gravatar (tickets/network-profile-local-avatars.md).
add_filter( 'should_load_remote_block_patterns', '__return_false' );
add_action( 'after_setup_theme', array( Design\Patterns::class, 'remove_core_patterns' ), 20 );
add_action( 'rest_api_init', array( Design\Patterns::class, 'register' ), 5 );
add_filter( 'rest_pre_dispatch', array( Design\Patterns::class, 'refuse_directory' ), 10, 3 );

/*
 * The Font Library (rule 1): no Google Fonts collection (core registers it on
 * `init` at priority 10), the Mody fonts collection instead, and the theme's
 * Inter served from this plugin (M2-07).
 */
add_action( 'init', array( Design\Fonts::class, 'remove_collections' ), 20 );
add_action( 'init', array( Design\Fonts::class, 'register_collection' ), 20 ); // The Mody fonts collection (M2-07).
add_filter( 'wp_theme_json_data_theme', array( Design\Fonts::class, 'theme_fonts' ) ); // Real Inter for the theme.

/*
 * Color pickers offer only palette colors on every site (M1-04 part A).
 * theme.json switches the free pickers off; the few Spectra draws itself are
 * hidden in the editors.
 */
add_action( 'enqueue_block_editor_assets', array( Design\PaletteOnly::class, 'enqueue' ) );

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
 * site has 3 GB, with WordPress's own upload quota (Site\Storage).
 */
Dashboard\DashboardPage::register();
Site\Storage::register();
add_action( 'init', array( Agent\AgentEvents::class, 'install' ), 1 );
add_filter( 'http_response', array( Agent\TurnMetrics::class, 'observe' ), 10, 3 ); // Each agent turn's tokens and cache (2026-11-11).
add_action( 'init', array( Provisioning\Jobs::class, 'install' ), 1 );
add_action( 'init', array( Members\Members::class, 'install' ), 1 );

/*
 * Member accounts (M1-01, part 1): a `mody_members` row gives the dashboard
 * capability (mvp-spec section 3) and the plan's site limit (section 4).
 */
add_filter( 'user_has_cap', array( Members\Members::class, 'grant_dashboard' ), 10, 3 );
add_filter( 'mody_site_limit', array( Members\Members::class, 'site_limit' ), 10, 2 );
add_filter( 'mody_can_create_site', array( Members\Members::class, 'can_create_site' ), 10, 2 ); // No site before the card is verified.

/*
 * Registration (M1-01 part 2, M1-03): the `mody/register` block on the main
 * site's /register/ page, a chat that creates the account, a 30-day trial
 * without a card and the first site, then emails the set-password link.
 * Closed until the network option `mody_registration_open` is set. Members
 * log in to the Mody dashboard.
 */
add_action( 'init', array( Members\Registration::class, 'register_block' ) );
add_action( 'init', array( Members\Registration::class, 'ensure_page' ), 20 );
add_action( 'rest_api_init', array( Members\Registration::class, 'register_routes' ) );
add_action( 'template_redirect', array( Members\Registration::class, 'handle' ) );
add_filter( 'login_redirect', array( Members\Registration::class, 'login_redirect' ), 10, 3 );

/*
 * Antibot (tickets/antibot-altcha.md): ALTCHA, a proof-of-work check served
 * from our own servers, on the signup chat (Members\Registration), log in,
 * lost password and visitors' comments, on every site. Antispam Bee (a MU
 * plugin, mu-plugins/antispam-bee.php) checks what people type in comments.
 */
add_action( 'init', array( Security\Altcha::class, 'register_script' ) );
add_action( 'rest_api_init', array( Security\Altcha::class, 'register_route' ) );
add_filter( 'wp_kses_allowed_html', array( Security\Altcha::class, 'allow_widget' ), 10, 2 ); // Spectra's container sanitizes its inner blocks.
Security\FormGuard::register();
Security\AntispamBee::register(); // Its checks that call outside services stay off (rule 1).

/*
 * Onboarding (M1-03): the first password logs the member in on their site's
 * Identity screen, where the editor tour shows the agent, then the editor.
 */
add_action( 'init', array( Onboarding\Onboarding::class, 'register_meta' ) );
add_action( 'after_password_reset', array( Onboarding\Onboarding::class, 'after_password_reset' ) );
add_action( 'enqueue_block_editor_assets', array( Onboarding\EditorTour::class, 'enqueue' ), 20 ); // After the chat bubble.

/*
 * wp-admin colors (decided 2026-11-08): Ocean is every user's color scheme
 * unless they choose another; users on WordPress's default are moved to it
 * once. The agent chat's colors follow the user's scheme (Admin\AdminColors).
 */
add_filter( 'insert_user_meta', array( Admin\AdminColors::class, 'new_user_scheme' ), 10, 4 );
add_filter( 'get_user_option_admin_color', array( Admin\AdminColors::class, 'default_scheme' ) );
add_action( 'init', array( Admin\AdminColors::class, 'migrate' ), 1 );

/*
 * The login page (tickets/login-branding.md): each site's wp-login.php wears
 * that site's logo, palette and fonts (the theme's variables, read on each
 * request), arranged by the scheme its admins choose in Settings → Login.
 */
Login\LoginPage::register();
add_action( 'admin_init', array( Login\SettingsPage::class, 'register_setting' ) );
add_action( 'rest_api_init', array( Login\SettingsPage::class, 'register_setting' ) );
add_action( 'admin_menu', array( Login\SettingsPage::class, 'register_menu' ) );

/*
 * Agent settings (Network Admin > Settings > Mody Agent): event retention.
 */
add_action( 'network_admin_menu', array( Agent\AgentSettings::class, 'register_menu' ) );
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
add_action( 'admin_init', array( Gating\HiddenScreens::class, 'refuse_screens' ) );
add_filter( 'register_setting_args', array( Gating\HiddenScreens::class, 'hide_settings' ), 10, 4 );
add_filter( 'rest_pre_dispatch', array( Gating\HiddenScreens::class, 'refuse_routes' ), 10, 3 );

/*
 * One profile for the network, edited on the main site only, and pictures
 * stored there instead of Gravatar (decided 2026-11-11,
 * tickets/network-profile-local-avatars.md).
 */
add_action( 'load-profile.php', array( Users\NetworkProfile::class, 'redirect' ) );
add_action( 'load-user-edit.php', array( Users\NetworkProfile::class, 'redirect' ) );
add_filter( 'edit_profile_url', array( Users\NetworkProfile::class, 'profile_url' ), 10, 2 );
add_filter( 'allowed_redirect_hosts', array( Users\NetworkProfile::class, 'allow_main_host' ) );
add_filter( 'rest_pre_dispatch', array( Users\NetworkProfile::class, 'refuse_rest_writes' ), 10, 3 );
add_filter( 'pre_get_avatar_data', array( Users\Avatars::class, 'avatar_data' ), 10, 2 );
add_filter( 'avatar_defaults', array( Users\Avatars::class, 'defaults' ) );
add_action( 'user_edit_form_tag', array( Users\Avatars::class, 'form_tag' ) );
add_filter( 'user_profile_picture_description', array( Users\Avatars::class, 'description' ), 10, 2 );
add_action( 'show_user_profile', array( Users\Avatars::class, 'section' ) );
add_action( 'edit_user_profile', array( Users\Avatars::class, 'section' ) );
add_action( 'personal_options_update', array( Users\Avatars::class, 'save' ) );
add_action( 'edit_user_profile_update', array( Users\Avatars::class, 'save' ) );
add_filter( 'ajax_query_attachments_args', array( Users\Avatars::class, 'hide_from_media' ) );
add_filter( 'rest_attachment_query', array( Users\Avatars::class, 'hide_from_media' ) );
add_action( 'delete_attachment', array( Users\Avatars::class, 'forget_attachment' ) );
add_action( 'wpmu_delete_user', array( Users\Avatars::class, 'forget_user' ) );
add_action( 'delete_user', array( Users\Avatars::class, 'forget_user' ) );
add_filter( 'wp_is_application_passwords_available_for_user', array( Users\NetworkProfile::class, 'application_passwords' ), 10, 2 );

/*
 * Updates only through the repository (decided 2026-11-08): nobody updates
 * WordPress, a plugin, a theme or a translation from wp-admin, and core never
 * checks WordPress.org for updates on its own. Code that calls the checks
 * directly (Wordfence's scans, WP-CLI) is left alone.
 */
Gating\Updates::remove_triggers();
add_filter( 'map_meta_cap', array( Gating\Updates::class, 'map_meta_cap' ), 100, 2 ); // After the usual filters.
add_action( 'init', array( Gating\Updates::class, 'unschedule' ) );

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
 * Agent chat: the "Mody Agent" page in wp-admin and its REST endpoint
 * (mvp-spec section 8). Needs the AI Client (WordPress 7.0+).
 */
if ( function_exists( 'wp_ai_client_prompt' ) ) {
	add_action( 'rest_api_init', array( Agent\ChatController::class, 'register_routes' ) );
	add_action( 'admin_menu', array( Agent\ChatPage::class, 'register_menu' ) );
	add_action( 'enqueue_block_editor_assets', array( Agent\EditorBubble::class, 'enqueue' ) ); // The chat bubble in the editors (M3-05).
}
