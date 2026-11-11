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
 *    second row; creates a pending trial by default.
 * 3. Site limits: a user without a row keeps the default (3); each plan gives
 *    its own (3, 1, 3, 10; the plans of 2026-11-10), through Provisioning\Quota.
 * 4. The dashboard capability: only with a row (super admins pass anyway).
 * 5. Over HTTP: with a row, a user who is not a super admin opens Network
 *    Admin → Mody → New site and sees the plan's limit; without one, the page
 *    refuses them.
 *
 * Two test users are created and removed with their rows. No model call.
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
$expected = array( 'user_id', 'plan', 'next_plan', 'billing_period', 'status', 'trial_ends_at', 'billing_due_at', 'past_due_since', 'mollie_customer_id', 'mollie_payment_method_id', 'created_at', 'updated_at' );
$sorted   = (array) $columns;
sort( $sorted );
sort( $expected );
// Order aside: dbDelta() appends next_plan (version 2) at the end of an existing table.
$check( 'network-wide, with the spec\'s columns, next_plan and billing_period', $wpdb->base_prefix . 'mody_members' === $table && $expected === $sorted, implode( ', ', (array) $columns ) );
$check( 'statuses: the five of the signup and billing flow', "enum('" . implode( "','", Members::STATUSES ) . "')" === ( $status['Type'] ?? '' ), (string) ( $status['Type'] ?? '' ) );
$check( 'plans: trial, sites_1, sites_3, sites_10', "enum('" . implode( "','", array_keys( Members::PLANS ) ) . "')" === ( $plan['Type'] ?? '' ) && 'trial' === ( $plan['Default'] ?? '' ), (string) ( $plan['Type'] ?? '' ) );

// 2. create() -----------------------------------------------------------------------

echo "\ncreate()\n";
$codes = array(
	Members::create( 999999999 ),
	Members::create( $member, 'gold' ),
	Members::create( $member, 'trial', 'paid' ),
);
$codes = array_map( static fn( $r ): string => is_wp_error( $r ) ? $r->get_error_code() : 'created', $codes );
$check( 'refuses a missing user, an unknown plan and an unknown status', array( 'mody_member_no_user', 'mody_member_plan', 'mody_member_status' ) === $codes, implode( ', ', $codes ) );
$check( 'nothing was written', null === Members::get( $member ) );
$created = Members::create( $member );
$row     = Members::get( $member );
$check( 'creates a pending trial by default', true === $created && 'trial' === ( $row['plan'] ?? '' ) && 'pending_payment' === ( $row['status'] ?? '' ), wp_json_encode( array_intersect_key( (array) $row, array_flip( array( 'plan', 'status' ) ) ) ) );
$again = Members::create( $member, 'sites_10', 'active' );
$check( 'refuses a trial as the next plan', is_wp_error( Members::create( $stranger, 'trial', 'pending_payment', 'trial' ) ) && null === Members::get( $stranger ) );
$check( 'refuses a second row', is_wp_error( $again ) && 'mody_member_exists' === $again->get_error_code() && 'trial' === Members::get( $member )['plan'] );

// 3. Site limits --------------------------------------------------------------------

echo "\nSite limits\n";
$check( 'no row: the default limit', Quota::DEFAULT_LIMIT === Quota::limit( $stranger ), (string) Quota::limit( $stranger ) );
$limits = array();
foreach ( array_keys( Members::PLANS ) as $slug ) {
	Members::delete( $member );
	Members::create( $member, $slug, 'active' );
	$limits[ $slug ] = Quota::limit( $member );
}
$check( 'each plan its own: 3, 1, 3, 10', array(
	'trial'    => 3,
	'sites_1'  => 1,
	'sites_3'  => 3,
	'sites_10' => 10,
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
Members::create( $member, 'sites_10', 'active' );
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

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
