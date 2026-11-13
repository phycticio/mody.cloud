<?php
/**
 * Tests member accounts (ticket M1-01, part 1): the `mody_members` table, the
 * plans' site limits and the dashboard capability.
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/members-test.php
 *
 * 1. The table: its columns and statuses (mvp-spec section 11).
 * 2. create(): refuses a missing user, an unknown plan or status, and a
 *    second row; creates a pending free account by default.
 * 3. Site limits: a user without a row keeps the default (3); each plan gives
 *    its own (the launch offer 1, paying 10; 2026-11-13), through
 *    Provisioning\Quota.
 * 4. The dashboard capability: only with a row (super admins pass anyway).
 * 5. Over HTTP: with a row, a user who is not a super admin opens Network
 *    Admin → Mody → New site and sees the plan's limit; without one, the page
 *    refuses them.
 *
 * 6. The launch offer (2026-11-13): start_free() gives a place (free six
 *    months, one site, no card), starts the offer's three months, counts
 *    the places, and refuses once the 50 are given or the months passed;
 *    a deleted account does not give its place back.
 *
 * Two test users are created and removed with their rows; the offer's two
 * options are put back as they were. No model call.
 */

use Mody\Platform\Members\Members;
use Mody\Platform\Provisioning\Quota;
use Mody\Platform\Wizard\WizardPage;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-58s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

global $wpdb;
$suffix = strtolower( wp_generate_password( 6, false ) );
$new    = static function ( string $login ) use ( $suffix ): int {
	return (int) wp_insert_user(
		array(
			'user_login' => $login . $suffix,
			'user_email' => $login . $suffix . '@mody.local',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => '',
		)
	);
};
$member   = $new( 'modymember' );
$stranger = $new( 'modystranger' );
register_shutdown_function(
	static function () use ( $member, $stranger ): void {
		foreach ( array( $member, $stranger ) as $user_id ) {
			Members::delete( $user_id );
			wpmu_delete_user( $user_id );
		}
	}
);
$admins = get_super_admins();
$super  = get_user_by( 'login', $admins[0] )->ID;

// 1. The table ----------------------------------------------------------------------

echo "The table\n";
$table = Members::table();
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test; own table.
$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
$status  = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'status'", ARRAY_A );
$plan    = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'plan'", ARRAY_A );
// phpcs:enable
$expected = array( 'user_id', 'plan', 'billing_period', 'status', 'free_until', 'billing_due_at', 'past_due_since', 'cancel_at', 'mollie_customer_id', 'mollie_payment_method_id', 'created_at', 'updated_at' );
$sorted   = (array) $columns;
sort( $sorted );
sort( $expected );
// Order aside: dbDelta() appends billing_period (version 3) at the end of an existing table.
$check( 'network-wide, with the spec\'s columns, free_until and billing_period, no next_plan', $wpdb->base_prefix . 'mody_members' === $table && $expected === $sorted, implode( ', ', (array) $columns ) );
$check( 'statuses: the five of the signup and billing flow', "enum('" . implode( "','", Members::STATUSES ) . "')" === ( $status['Type'] ?? '' ), (string) ( $status['Type'] ?? '' ) );
$check( 'plans: free and paid', "enum('free','paid')" === ( $plan['Type'] ?? '' ) && 'free' === ( $plan['Default'] ?? '' ) && array( 'free', 'paid' ) === Members::PLANS, (string) ( $plan['Type'] ?? '' ) );

// 2. create() -----------------------------------------------------------------------

echo "\ncreate()\n";
$codes = array(
	Members::create( 999999999 ),
	Members::create( $member, 'gold' ),
	Members::create( $member, 'free', 'paid' ),
);
$codes = array_map( static fn( $r ): string => is_wp_error( $r ) ? $r->get_error_code() : 'created', $codes );
$check( 'refuses a missing user, an unknown plan and an unknown status', array( 'mody_member_no_user', 'mody_member_plan', 'mody_member_status' ) === $codes, implode( ', ', $codes ) );
$check( 'nothing was written', null === Members::get( $member ) );
$created = Members::create( $member );
$row     = Members::get( $member );
$check( 'creates a pending free account by default', true === $created && 'free' === ( $row['plan'] ?? '' ) && 'pending_payment' === ( $row['status'] ?? '' ), wp_json_encode( array_intersect_key( (array) $row, array_flip( array( 'plan', 'status' ) ) ) ) );
$again = Members::create( $member, 'paid', 'active' );
$check( 'refuses an old plan', is_wp_error( Members::create( $stranger, 'sites_3', 'active' ) ) && null === Members::get( $stranger ) );
$check( 'refuses a second row', is_wp_error( $again ) && 'mody_member_exists' === $again->get_error_code() && 'free' === Members::get( $member )['plan'] );

// 3. Site limits --------------------------------------------------------------------

echo "\nSite limits\n";
$check( 'no row: the default limit', Quota::DEFAULT_LIMIT === Quota::limit( $stranger ), (string) Quota::limit( $stranger ) );
$limits = array();
foreach ( Members::PLANS as $slug ) {
	Members::delete( $member );
	Members::create( $member, $slug, 'active' );
	$limits[ $slug ] = Quota::limit( $member );
}
$check( 'each plan its own: the launch offer 1, paying 10', array(
	'free' => 1,
	'paid' => 10,
) === $limits, wp_json_encode( $limits ) );

// 4. The dashboard capability -------------------------------------------------------

echo "\nThe dashboard capability\n";
$check( 'a member has it', user_can( $member, WizardPage::CAPABILITY ) );
$check( 'a user without a row does not', ! user_can( $stranger, WizardPage::CAPABILITY ) );
$check( 'a super admin does', user_can( $super, WizardPage::CAPABILITY ) );
$check( 'nothing else is granted', ! user_can( $member, 'manage_network' ) && ! user_can( $member, 'create_sites' ) );

// 5. Over HTTP ----------------------------------------------------------------------

echo "\nOver HTTP\n";
Members::delete( $member );
Members::create( $member, 'paid', 'active' );
$cookie = static function ( int $user_id ): array {
	$expiration = time() + 300;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	register_shutdown_function( static fn() => WP_Session_Tokens::get_instance( $user_id )->destroy( $token ) );
	return array(
		SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token ),
		LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ),
	);
};
$http = static function ( array $cookies ): array {
	$response = wp_remote_get(
		network_admin_url( 'admin.php?page=' . WizardPage::SLUG ),
		array(
			'cookies'     => $cookies,
			'sslverify'   => false,
			'redirection' => 0,
			'timeout'     => 30,
		)
	);
	return array(
		'code' => (int) wp_remote_retrieve_response_code( $response ),
		'body' => (string) wp_remote_retrieve_body( $response ),
	);
};
$out = $http( $cookie( $member ) );
$check( 'a member opens Mody → New site', 200 === $out['code'] && str_contains( $out['body'], 'id="mody-wizard-form"' ), (string) $out['code'] );
$check( '... with their plan\'s limit', str_contains( $out['body'], '0 of 10 sites used' ) );
$check( '... and no other network menu', ! preg_match( '/href=[\'"](sites|users|themes|plugins|settings)\.php[\'"]/', $out['body'] ) );
$out = $http( $cookie( $stranger ) );
$check( 'a user without a row is refused', in_array( $out['code'], array( 403, 500 ), true ) && str_contains( $out['body'], 'Sorry, you are not allowed to' ), (string) $out['code'] );

// 6. The launch offer -----------------------------------------------------------------

echo "\nThe launch offer\n";
$saved_start = get_site_option( Members::PROMO_START_OPTION, null );
$saved_given = get_site_option( Members::PROMO_GIVEN_OPTION, null );
delete_site_option( Members::PROMO_START_OPTION );
delete_site_option( Members::PROMO_GIVEN_OPTION );
Members::delete( $member );
Members::delete( $stranger );
$check( 'before the first account: open, 50 places, no end yet', Members::promo_open() && 50 === Members::promo_left() && 0 === Members::promo_ends() );
$free  = Members::start_free( $member );
$row   = Members::get( $member );
$until = Members::free_until( $member );
$check( 'a place: free, active, no card, one site', true === $free && 'free' === $row['plan'] && 'active' === $row['status'] && empty( $row['mollie_payment_method_id'] ) && 1 === Quota::limit( $member ) );
$check( '... free for six months, the first billing date the same', abs( $until - (int) strtotime( '+6 months' ) ) < 120 && $row['free_until'] === $row['billing_due_at'], (string) $row['free_until'] );
$check( '... it started the three months and took a place', abs( Members::promo_ends() - (int) strtotime( '+3 months' ) ) < 120 && 49 === Members::promo_left() );
Members::delete( $member );
$check( 'a deleted account does not give its place back', 49 === Members::promo_left() );
update_site_option( Members::PROMO_GIVEN_OPTION, Members::FREE_PLACES );
$full = Members::start_free( $stranger );
$check( 'the 51st is refused, nothing written', is_wp_error( $full ) && 'mody_promo_closed' === $full->get_error_code() && null === Members::get( $stranger ) && ! Members::promo_open() );
$check( '... and told the places are taken, and the price', str_contains( $full->get_error_message(), 'The 50 free places' ) && str_contains( $full->get_error_message(), '7.99' ), $full->get_error_message() );
update_site_option( Members::PROMO_GIVEN_OPTION, 10 );
update_site_option( Members::PROMO_START_OPTION, (int) strtotime( '-3 months -1 day' ) );
$late = Members::start_free( $stranger );
$check( 'after three months, refused with places left', is_wp_error( $late ) && str_contains( $late->get_error_message(), 'has ended' ) && null === Members::get( $stranger ) );
$check( 'prices: 7.99 a month, 79.99 a year, saving 15.89 per site', 7.99 === Members::PRICE['monthly'] && 79.99 === Members::PRICE['yearly'] && 15.89 === Members::yearly_saving() && 23.97 === Members::total( 3, 'monthly' ) );
foreach ( array( Members::PROMO_START_OPTION => $saved_start, Members::PROMO_GIVEN_OPTION => $saved_given ) as $option => $value ) {
	null === $value ? delete_site_option( $option ) : update_site_option( $option, $value );
}

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
