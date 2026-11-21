<?php
/**
 * Plugin Name:       Mody Members
 * Description:       Member accounts: the signup chat, the free plan, the sites an account may have, and Invite friends.
 * Version:           1.0.0
 * Author:            Mody Cloud
 * License:           GPL-2.0-or-later
 * Requires PHP:      8.1
 * Update URI:        false
 * Text Domain:       mody_cloud
 *
 * Member accounts (M1-01, M1-03, tickets/invites-and-offers.md and
 * tickets/free-mvp.md):
 *
 * - **Members**: the `mody_members` table, one row per account: the plan,
 *   free with no end since 2026-11-20 (the price per site is kept for paid
 *   tiers later), the site limit (through `mody_site_limit`), the
 *   network's Mody menu.
 * - **Registration**: the `mody/register` block on the main site's
 *   /register/ page, a chat that creates a free account and its first site
 *   (through Mody Sites, which it needs; sign-ups from Spain only), then
 *   emails the set-password link; paid sign-ups when Mody Billing offers
 *   cards. Its REST routes keep `mody-platform/v1`.
 * - **SignupLanguage**: the signup in the page's language (Spanish, French,
 *   German, Italian, Portuguese, Dutch, Catalan; English otherwise), from
 *   its own translation files in `languages/`; the page's language comes
 *   from Mody Languages when it is there
 *   (tickets/signup-chat-languages.md).
 * - **Offers**: Network Admin → Mody → Offers: sign-ups open to everyone or
 *   to invited people only, and invites on or off.
 * - **Invites**: Invite friends, a section of the Mody dashboard through its
 *   hooks.
 *
 * It answers the questions of Mody Sites (`mody_site_limit`,
 * `mody_can_create_site`, `mody_sites_payment_pending`,
 * `mody_sites_on_launch_offer`) and Mody Onboarding
 * (`mody_onboarding_no_site_url`, `mody_onboarding_free_until`).
 *
 * One of the independent Mody plugins (tickets/independent-mody-plugins.md).
 * A network-only plugin.
 *
 * @package Mody\Members
 */

namespace Mody\Members;

defined( 'ABSPATH' ) || exit;

/*
 * Classes load on first use: Mody\Members\Example is src/Example.php.
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

if ( ! is_multisite() ) {
	return;
}

add_action( 'init', array( Members::class, 'install' ), 1 );

/*
 * Member accounts (M1-01, part 1): a `mody_members` row gives the network's
 * Mody menu (mvp-spec section 3) and the plan's site limit (section 4).
 */
add_filter( 'user_has_cap', array( Members::class, 'grant_dashboard' ), 10, 3 );
add_filter( 'mody_site_limit', array( Members::class, 'site_limit' ), 10, 2 );
add_filter( 'mody_can_create_site', array( Members::class, 'can_create_site' ), 10, 2 ); // No site before the card is verified.
add_filter( 'mody_sites_payment_pending', static fn( $pending, $user_id ) => $pending || Members::payment_pending( (int) $user_id ), 10, 2 ); // Mody Sites' wizard: the payment step first.
add_filter( 'mody_sites_on_launch_offer', static fn( $free, $user_id ) => $free || 'free' === (string) ( Members::get( (int) $user_id )['plan'] ?? '' ), 10, 2 );

/*
 * Registration (M1-01 part 2, M1-03): the signup chat, closed until the
 * network option `mody_registration_open` is set. It makes the account's
 * first site, so it needs Mody Sites.
 */
add_action(
	'plugins_loaded', // Once every plugin is loaded, whatever their order.
	static function (): void {
		if ( ! class_exists( \Mody\Sites\Pipeline::class ) ) {
			return;
		}
		add_action( 'init', array( Registration::class, 'register_block' ) );
		add_action( 'init', array( Registration::class, 'ensure_page' ), 20 );
		add_action( 'rest_api_init', array( Registration::class, 'register_routes' ) );
		add_action( 'template_redirect', array( Registration::class, 'handle' ) );
		SignupLanguage::register();
		if ( class_exists( \Mody\Billing\Billing::class ) ) {
			add_action( 'template_redirect', array( Registration::class, 'handle_pay' ) ); // The pay page of a paid sign-up (M1-01 part 3b, Mody Billing).
		}
	},
	0
);
add_filter( 'login_redirect', array( Registration::class, 'login_redirect' ), 10, 3 );

/*
 * Onboarding (Mody Onboarding, M1-03): where a member without a site goes
 * after their first password, and when their free months end, for the tour.
 */
add_filter( 'mody_onboarding_no_site_url', array( Registration::class, 'dashboard_url' ) );
add_filter( 'mody_onboarding_free_until', static fn( $ends, $user_id ) => Members::free_until( (int) $user_id ), 10, 2 );

/*
 * Offers (Network Admin → Mody → Offers) and Invite friends, a section of
 * every member's Mody dashboard.
 */
add_action( 'network_admin_menu', array( Offers::class, 'register_menu' ), 20 );
add_action( 'network_admin_edit_' . Offers::SLUG, array( Offers::class, 'save' ) );
add_filter( 'mody_dashboard_sections', array( Invites::class, 'add_section' ), 20 ); // After the accounts' own sections.
add_action( 'mody_dashboard_section_' . Invites::SECTION, array( Invites::class, 'render' ) );
