<?php
/**
 * Tests invites and the Offers page (tickets/invites-and-offers.md).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/invites-test.php
 *
 * 1. Offers: the defaults are the launch offer of 2026-11-13; saved values
 *    apply, within bounds; Members reads them.
 * 2. The launch offer: off, or for invited people only; what a visitor reads.
 * 3. Invite codes: random, kept, one per user; who can invite (members and
 *    super admins); unknown codes and invites off are ignored.
 * 4. Signing up through a link: the account records who invited it, and takes
 *    a place even when the offer is for invited people only; without a link it
 *    does not. The page carries the code (chat and form); the REST check too.
 * 5. Points: once, on the first payment; a withdrawal takes it back; counts.
 * 6. The Dashboard's Invite friends section, and the Offers page.
 *
 * Users and sites made here are removed; the options are put back. No model
 * call, no mail sent (pre_wp_mail), Mollie not offered (the production
 * environment with no live key).
 */

use Mody\Platform\Dashboard\DashboardPage;
use Mody\Platform\Members\Invites;
use Mody\Platform\Members\Members;
use Mody\Platform\Members\Offers;
use Mody\Platform\Members\Registration;
use Mody\Platform\Security\RateLimit;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-70s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};
if ( ! is_main_site() ) {
	echo "Run this on the main site (--url=https://<main host>).\n";
	return;
}

$saved = array();
foreach ( array( Offers::OPTION, Members::PROMO_START_OPTION, Members::PROMO_GIVEN_OPTION, Registration::OPEN_OPTION ) as $option ) {
	$saved[ $option ] = get_site_option( $option, null );
}
$suffix = strtolower( wp_generate_password( 6, false ) );
$emails = array();
$users  = array();
register_shutdown_function(
	static function () use ( &$saved, &$emails, &$users ): void {
		foreach ( $saved as $option => $value ) {
			null === $value ? delete_site_option( $option ) : update_site_option( $option, $value );
		}
		foreach ( $emails as $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$users[] = $user->ID;
			}
		}
		foreach ( array_unique( $users ) as $user_id ) {
			foreach ( get_blogs_of_user( $user_id ) as $site ) {
				if ( ! is_main_site( (int) $site->userblog_id ) ) {
					wpmu_delete_blog( (int) $site->userblog_id, true );
				}
			}
			Members::delete( $user_id );
			wpmu_delete_user( $user_id );
		}
	}
);
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'mody_billing_environment', static fn(): string => 'production' ); // A sandbox key offers nothing there.
add_filter( 'mody_registration_defer', '__return_false' );
$new = static function ( string $login ) use ( $suffix, &$users ): int {
	$id      = (int) wp_insert_user(
		array(
			'user_login' => $login . $suffix,
			'user_email' => $login . $suffix . '@mody.local',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => '',
		)
	);
	$users[] = $id;
	return $id;
};
$super = (int) get_user_by( 'login', get_super_admins()[0] )->ID;
delete_site_option( Offers::OPTION );
delete_site_option( Members::PROMO_START_OPTION );
delete_site_option( Members::PROMO_GIVEN_OPTION );
update_site_option( Registration::OPEN_OPTION, 1 );

// 1. Offers ---------------------------------------------------------------------------------

echo "Offers\n";
$s = Offers::settings();
$check( 'defaults: on, everyone, 50 places, 6 free months, 3 months, invites on', $s['launch_on'] && 'everyone' === $s['launch_who'] && 50 === $s['places'] && 6 === $s['free_months'] && 3 === $s['months'] && $s['invites_on'] );
update_site_option( Offers::OPTION, array( 'places' => 20, 'free_months' => 12, 'months' => 2, 'launch_who' => 'nobody' ) );
$check( 'saved values apply; an unknown "who" is everyone', 20 === Offers::places() && 12 === Offers::free_months() && 2 === Offers::months() && ! Offers::invited_only() );
$check( '... Members reads them: 20 places left', 20 === Members::promo_left() );
update_site_option( Offers::OPTION, array( 'places' => -5, 'free_months' => 999, 'months' => 0 ) );
$check( 'out of bounds: clamped', 0 === Offers::places() && 36 === Offers::free_months() && 1 === Offers::months() );
delete_site_option( Offers::OPTION );

// 2. The launch offer: off, or invited only ------------------------------------------------------

echo "\nThe launch offer\n";
update_site_option( Offers::OPTION, array( 'launch_on' => false ) );
$check( 'off: closed to everyone, invited too; "has ended"', ! Members::promo_open() && ! Members::promo_open( true ) && str_contains( Members::promo_closed_message( true ), 'has ended' ) );
update_site_option( Offers::OPTION, array( 'launch_who' => 'invited' ) );
$check( 'invited only: closed without a link, open with one', ! Members::promo_open() && Members::promo_open( true ) );
$check( '... the message says it is by invitation', str_contains( Members::promo_closed_message(), 'by invitation' ) );
update_site_option( Members::PROMO_GIVEN_OPTION, 50 );
$check( '... all places taken: closed for invited people too, "are taken"', ! Members::promo_open( true ) && str_contains( Members::promo_closed_message(), 'The 50 free places' ) );
delete_site_option( Members::PROMO_GIVEN_OPTION );

// 3. Invite codes ------------------------------------------------------------------------------

echo "\nInvite codes\n";
$inviter  = $new( 'modyinviter' );
$stranger = $new( 'modyinvstranger' );
Members::create( $inviter, 'free', 'active' );
$code = Invites::code( $inviter );
$check( 'a code: 10 lowercase letters and digits, not the login', 1 === preg_match( '/^[a-z0-9]{10}$/', $code ) && ! str_contains( $code, 'modyinviter' ), $code );
$check( '... kept: the same next time', Invites::code( $inviter ) === $code );
$check( '... another user gets another', Invites::code( $super ) !== $code );
$check( 'a member\'s code names them; a super admin\'s too', $inviter === Invites::inviter( $code ) && $super === Invites::inviter( Invites::code( $super ) ) );
$check( 'a user without a member row cannot invite', 0 === Invites::inviter( Invites::code( $stranger ) ) && ! Invites::can_invite( $stranger ) );
$check( 'an unknown or malformed code: nobody', 0 === Invites::inviter( 'zzzzzzzzzz' ) && 0 === Invites::inviter( "{$code}' OR 1" ) && 0 === Invites::inviter( array( $code ) ) );
$check( 'case and spaces do not matter', $inviter === Invites::inviter( ' ' . strtoupper( $code ) . ' ' ) );
$url = Invites::url( $inviter );
$check( 'the link: the main site\'s /register/ with ?ref=', str_starts_with( $url, get_home_url( get_main_site_id(), '/register/' ) ) && str_contains( $url, 'ref=' . $code ), $url );
update_site_option( Offers::OPTION, array( 'launch_who' => 'invited', 'invites_on' => false ) );
$check( 'invites off: links name nobody', 0 === Invites::inviter( $code ) );

// 4. Signing up through a link ----------------------------------------------------------------------

echo "\nSigning up through a link\n";
update_site_option( Offers::OPTION, array( 'launch_who' => 'invited' ) );
RateLimit::clear();
wp_set_current_user( 0 );
$emails['friend'] = "modyfriend{$suffix}@mody.local";
RateLimit::clear();
$friend_account   = Registration::register( 'Fay Friend', $emails['friend'], 'Fay Flowers', "invfriend{$suffix}", false, strtoupper( $code ) );
$friend           = get_user_by( 'email', $emails['friend'] );
$row              = $friend ? Members::get( $friend->ID ) : null;
$check( 'invited only, with a link: the account and its place', is_array( $friend_account ) && $row && 'free' === $row['plan'] && 'active' === $row['status'] && 1 === Members::promo_given(), is_wp_error( $friend_account ) ? $friend_account->get_error_message() : '' );
$check( '... who invited is recorded; no point yet', $row && $inviter === (int) $row['invited_by'] && null === $row['invite_point_at'] );
$check( '... its own site', $friend && is_array( $friend_account ) && $friend_account['site_id'] > 0 );
$emails['walkin'] = "modywalkin{$suffix}@mody.local";
RateLimit::clear();
$walkin           = Registration::register( 'Wes Walkin', $emails['walkin'], 'Wes Wares', "invwalkin{$suffix}" );
$check( 'invited only, no link, no card payments: refused, nothing made', is_wp_error( $walkin ) && 'mody_promo_closed' === $walkin->get_error_code() && ! get_user_by( 'email', $emails['walkin'] ) && 1 === Members::promo_given() );
$emails['bad'] = "modybadref{$suffix}@mody.local";
RateLimit::clear();
$bad           = Registration::register( 'Bo Bad', $emails['bad'], 'Bo Bikes', "invbad{$suffix}", false, 'zzzzzzzzzz' );
$check( '... an unknown code is no link', is_wp_error( $bad ) && ! get_user_by( 'email', $emails['bad'] ) );
update_site_option( Offers::OPTION, array( 'launch_who' => 'everyone' ) );
$emails['open'] = "modyopen{$suffix}@mody.local";
RateLimit::clear();
Registration::register( 'Ola Open', $emails['open'], 'Ola Oils', "invopen{$suffix}" );
$open = get_user_by( 'email', $emails['open'] );
$check( 'everyone: no link, a place, nobody recorded', $open && Members::get( $open->ID ) && null === Members::get( $open->ID )['invited_by'] && 2 === Members::promo_given() );
update_site_option( Offers::OPTION, array( 'free_months' => 9 ) );
$emails['nine'] = "modynine{$suffix}@mody.local";
RateLimit::clear();
Registration::register( 'Nia Nine', $emails['nine'], 'Nia Nails', "invnine{$suffix}" );
$nine  = get_user_by( 'email', $emails['nine'] );
$until = $nine ? Members::free_until( $nine->ID ) : 0;
$check( 'free months from the Offers page: 9', abs( $until - (int) strtotime( '+9 months' ) ) < 300 );
$check( '... accounts already given keep theirs (6)', $friend && abs( Members::free_until( $friend->ID ) - (int) strtotime( '+6 months' ) ) < 300 );

// The page and the REST check carry the code.
update_site_option( Offers::OPTION, array( 'launch_who' => 'invited' ) );
$page = static function ( array $get ): string {
	$_GET = $get;
	$html = Registration::render();
	$_GET = array();
	return $html;
};
$with    = $page( array( 'ref' => $code ) );
$without = $page( array() );
$wrong   = $page( array( 'ref' => 'zzzzzzzzzz' ) );
$scripts = wp_scripts()->get_data( Registration::SCRIPT, 'before' );
$check( 'the page with a link: the chat and the form carry the code', str_contains( $with, 'name="mody_ref" value="' . $code . '"' ) && str_contains( (string) wp_json_encode( $scripts ), $code ) );
$check( '... without one (invited only): "by invitation", no form', str_contains( $without, 'by invitation' ) && ! str_contains( $without, 'mody-register-form' ) );
$check( '... a wrong code: as without one', str_contains( $wrong, 'by invitation' ) && ! str_contains( $wrong, 'zzzzzzzzzz' ) );
$request = new WP_REST_Request( 'POST', '/mody-platform/v1/register/check' );
$request->set_param( 'nonce', wp_create_nonce( Registration::ACTION ) );
$denied = Registration::rest_permission( $request );
$request->set_param( 'ref', $code );
$check( 'REST: refused without the code, allowed with it', is_wp_error( $denied ) && 'mody_promo_closed' === $denied->get_error_code() && true === Registration::rest_permission( $request ) );

// 5. Points --------------------------------------------------------------------------------------

echo "\nPoints\n";
$check( 'joined 1, points 0', array( 'joined' => 1, 'points' => 0 ) === Invites::counts( $inviter ) );
Invites::credit( $friend->ID );
$first = Members::get( $friend->ID )['invite_point_at'];
Invites::credit( $friend->ID );
$check( 'the first payment: a point, once', null !== $first && $first === Members::get( $friend->ID )['invite_point_at'] && array( 'joined' => 1, 'points' => 1 ) === Invites::counts( $inviter ) );
Invites::credit( $open->ID );
$check( 'an account nobody invited: no point', null === Members::get( $open->ID )['invite_point_at'] );
Invites::uncredit( $friend->ID );
$check( 'a withdrawal: the point goes, the reference stays', array( 'joined' => 1, 'points' => 0 ) === Invites::counts( $inviter ) );
$totals = Invites::totals();
$check( 'the network\'s totals count it', $totals['joined'] >= 1 );

// 6. The Dashboard's section and the Offers page ----------------------------------------------------------

echo "\nThe Dashboard and the Offers page\n";
wp_set_current_user( $inviter );
$check( 'a member: Invite friends among the sections', isset( DashboardPage::sections()[ Invites::SECTION ] ) );
ob_start();
Invites::render();
$section = (string) ob_get_clean();
$check( '... the link, Copy, joined and points, "Points give nothing yet"', str_contains( $section, esc_attr( $url ) ) && str_contains( $section, 'Copy link' ) && str_contains( $section, 'Joined through your link' ) && str_contains( $section, 'Points give nothing yet' ) );
$check( '... never who joined', ! str_contains( $section, 'Fay' ) && ! str_contains( $section, $emails['friend'] ) );
wp_set_current_user( $stranger );
$check( 'a user without a row: no section', ! isset( DashboardPage::sections()[ Invites::SECTION ] ) );
wp_set_current_user( $inviter );
update_site_option( Offers::OPTION, array( 'invites_on' => false ) );
$check( 'invites off: no section', ! isset( DashboardPage::sections()[ Invites::SECTION ] ) );
wp_set_current_user( $super );
update_site_option( Offers::OPTION, array( 'launch_who' => 'invited' ) );
ob_start();
Offers::render();
$admin = (string) ob_get_clean();
$check( 'the Offers page (super admin): the offer, invited only, places given, invites', str_contains( $admin, 'Launch offer' ) && str_contains( $admin, 'Open to invited people only.' ) && str_contains( $admin, 'Given so far: 3' ) && str_contains( $admin, 'name="invites_on"' ) );
wp_set_current_user( $inviter );
ob_start();
Offers::render();
$check( '... a member sees nothing', '' === trim( (string) ob_get_clean() ) );

echo "\n" . ( $failures ? "{$failures} check(s) FAILED\n" : "All checks passed.\n" );
