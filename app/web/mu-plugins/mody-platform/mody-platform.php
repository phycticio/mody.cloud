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
 * Classes load on first use: Mody\Agent\AI\BlockValidator is src/AI/BlockValidator.php.
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
	if ( function_exists( 'wp_ai_client_prompt' ) && class_exists( \Mody\Agent\Budget::class ) ) { // The analyst runs on Mody Agent's model limits and log.
		Dashboard\Analyst::register(); // Mody on that board: reads only the analytics, changes only the board (phase 2).
	}
}

add_action( 'init', array( Members\Members::class, 'install' ), 1 );

/*
 * Paying with a card through Mollie (M1-01 part 3, 2026-11-13): adding a
 * card, the charges, the webhook, the 14-day withdrawal, the reminders and
 * the daily run (`wp mody-billing run`). Off without MOLLIE_API_KEY.
 */
Billing\Billing::register();
add_filter( 'mody_agent_site_locked', static fn( $locked, $site_id ) => $locked || (bool) Billing\Lifecycle::site_lock( (int) $site_id ), 10, 2 ); // Mody does not work on a read-only site (Mody Agent's Budget).

/*
 * Member accounts (M1-01, part 1): a `mody_members` row gives the dashboard
 * capability (mvp-spec section 3) and the plan's site limit (section 4).
 */
add_filter( 'user_has_cap', array( Members\Members::class, 'grant_dashboard' ), 10, 3 );
add_filter( 'mody_site_limit', array( Members\Members::class, 'site_limit' ), 10, 2 );
add_filter( 'mody_can_create_site', array( Members\Members::class, 'can_create_site' ), 10, 2 ); // No site before the card is verified.
add_filter( 'mody_sites_payment_pending', static fn( $pending, $user_id ) => $pending || Members\Members::payment_pending( (int) $user_id ), 10, 2 ); // Mody Sites' wizard: the payment step first.
add_filter( 'mody_sites_on_launch_offer', static fn( $free, $user_id ) => $free || 'free' === (string) ( Members\Members::get( (int) $user_id )['plan'] ?? '' ), 10, 2 );
add_filter( 'mody_sites_plan_url', static fn( $url, $user_id ) => '' !== $url ? $url : Billing\Billing::plan_url( (int) $user_id ), 10, 2 );

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
 * Offers (Network Admin → Mody → Offers, tickets/invites-and-offers.md): the
 * launch offer's numbers and who may take a place, and invites on or off.
 * Invite friends is a section of every member's Dashboard (Members\Invites).
 */
add_action( 'network_admin_menu', array( Members\Offers::class, 'register_menu' ), 20 );
add_action( 'network_admin_edit_' . Members\Offers::SLUG, array( Members\Offers::class, 'save' ) );


