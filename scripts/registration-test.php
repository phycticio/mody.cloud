<?php
/**
 * Tests the registration page (ticket M1-01, part 2).
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/registration-test.php
 *
 * 1. Closed (the default): no page is created, the block says registration
 *    opens soon; the block is PHP-only and hidden from the inserter.
 * 2. Validation (in-process): name, email, password and plan, a duplicate
 *    email, the trial as a plan.
 * 3. Open, over HTTP on the main site: the page is created with the block;
 *    the form shows the three plans; a bad post shows its errors and keeps
 *    the name; an expired nonce is refused; a good post creates the user (no
 *    main-site role) and a pending trial row with the chosen plan, logs them
 *    in and sends them to the Mody dashboard.
 * 4. The pending account: no site may be created; the dashboard shows the
 *    payment step instead of the wizard; once active, the quota applies
 *    again. A logged-in visitor is told they have an account; members log in
 *    to the dashboard.
 * 5. Closed again: the page says so, and a good post creates nothing.
 *
 * The option, the page (when the test created it) and the users are put back
 * or removed at the end. No model call.
 */

use Mody\Platform\Members\Members;
use Mody\Platform\Members\Registration;
use Mody\Platform\Provisioning\Quota;

require_once ABSPATH . 'wp-admin/includes/ms.php';

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-60s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

if ( ! is_main_site() ) {
	echo "Run this on the main site (--url=https://<main host>).\n";
	return;
}
global $wpdb;
$suffix      = strtolower( wp_generate_password( 6, false ) );
$email       = 'modyreg' . $suffix . '@mody.local';
$was_open    = get_site_option( Registration::OPEN_OPTION );
$page_before = (int) get_option( Registration::PAGE_OPTION );
$admins      = get_super_admins();
$super       = get_user_by( 'login', $admins[0] );
register_shutdown_function(
	static function () use ( $email, $was_open, $page_before ): void {
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			Members::delete( $user->ID );
			wpmu_delete_user( $user->ID );
		}
		false === $was_open ? delete_site_option( Registration::OPEN_OPTION ) : update_site_option( Registration::OPEN_OPTION, $was_open );
		$page_now = (int) get_option( Registration::PAGE_OPTION );
		if ( ! $page_before && $page_now ) {
			wp_delete_post( $page_now, true );
			delete_option( Registration::PAGE_OPTION );
		}
	}
);
$block = static fn(): string => render_block( parse_blocks( '<!-- wp:' . Registration::BLOCK . ' /-->' )[0] );

// 1. Closed ------------------------------------------------------------------------

echo "Closed (the default)\n";
delete_site_option( Registration::OPEN_OPTION );
$check( 'closed unless the network option is set', ! Registration::is_open() );
if ( ! $page_before ) {
	Registration::ensure_page();
	$check( '... and no page is created', ! get_option( Registration::PAGE_OPTION ) && ! get_page_by_path( Registration::PAGE_SLUG ) );
}
$check( 'the block says registration opens soon', str_contains( $block(), 'Registration opens soon.' ) && ! str_contains( $block(), '<form' ) );
$type = WP_Block_Type_Registry::get_instance()->get_registered( Registration::BLOCK );
$check( 'the block: PHP-only (autoRegister), not in the inserter', $type && ! empty( $type->supports['autoRegister'] ) && false === ( $type->supports['inserter'] ?? null ) && is_callable( $type->render_callback ) );

// 2. Validation ---------------------------------------------------------------------

echo "\nValidation\n";
$result = Registration::register( '  ', 'not-an-email', 'short', 'gold' );
$codes  = is_wp_error( $result ) ? $result->get_error_codes() : array();
sort( $codes );
$check( 'empty name, bad email, short password, unknown plan', array( 'email', 'name', 'password', 'plan' ) === $codes, implode( ', ', $codes ) );
$result = Registration::register( 'Ada', $super->user_email, str_repeat( 'x', 16 ), 'member' );
$check( 'a duplicate email is refused', is_wp_error( $result ) && array( 'email' ) === $result->get_error_codes() && str_contains( $result->get_error_message( 'email' ), 'already exists' ) );
$result = Registration::register( 'Ada', $email, str_repeat( 'x', 16 ), 'trial' );
$check( 'the trial is not a plan to choose', is_wp_error( $result ) && array( 'plan' ) === $result->get_error_codes() );
$check( 'nothing was created', ! get_user_by( 'email', $email ) );

// 3. Open, over HTTP ----------------------------------------------------------------

echo "\nOpen, over HTTP\n";
update_site_option( Registration::OPEN_OPTION, 1 );
Registration::ensure_page();
$page_id = (int) get_option( Registration::PAGE_OPTION );
$page    = get_post( $page_id );
$check( 'the page exists, published at /register/, with the block', $page && 'publish' === $page->post_status && Registration::PAGE_SLUG === $page->post_name && has_block( Registration::BLOCK, $page ), $page_id ? (string) get_permalink( $page_id ) : 'none' );
$url = (string) get_permalink( $page_id );

$http = static function ( string $method, string $to, array $cookies = array(), array $body = array() ): array {
	$response = wp_remote_request(
		$to,
		array(
			'method'      => $method,
			'cookies'     => $cookies,
			'body'        => $body,
			'sslverify'   => false,
			'redirection' => 0,
			'timeout'     => 30,
		)
	);
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $response ),
		'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		'body'     => (string) wp_remote_retrieve_body( $response ),
		'cookies'  => array_map( static fn( $c ): string => $c->name, is_wp_error( $response ) ? array() : wp_remote_retrieve_cookies( $response ) ),
	);
};
$cookie = static function ( int $user_id ): array {
	$expiration = time() + 300;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	register_shutdown_function( static fn() => WP_Session_Tokens::get_instance( $user_id )->destroy( $token ) );
	return array(
		SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token ),
		LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ),
	);
};

$out = $http( 'GET', $url );
preg_match( '/name="_mody_nonce" value="([0-9a-f]+)"/', $out['body'], $nonce );
$check( 'the form opens', 200 === $out['code'] && str_contains( $out['body'], 'class="mody-register"' ) && isset( $nonce[1] ), (string) $out['code'] );
$plans = array( 'Collaborator', '5 EUR per month', '3 sites', 'Member', '15 EUR per month', '10 sites', 'Patron', '50 EUR per month', '50 sites' );
$check( '... with the three plans, their prices and sites', ! array_filter( $plans, static fn( string $s ): bool => ! str_contains( $out['body'], $s ) ) );
$check( '... the trial explained, and never "unlimited"', str_contains( $out['body'], '30-day trial with 3 sites' ) && false === stripos( $out['body'], 'unlimited' ) );

$form = static fn( array $over = array() ): array => array_merge(
	array(
		'mody_action'   => Registration::ACTION,
		'_mody_nonce'   => $nonce[1] ?? '',
		'mody_name'     => 'Ada Lovelace',
		'mody_email'    => $email,
		'mody_password' => 'correct horse battery staple',
		'mody_plan'     => 'member',
	),
	$over
);
$out = $http(
	'POST',
	$url,
	array(),
	$form(
		array(
			'mody_email'    => 'not-an-email',
			'mody_password' => 'short',
		)
	)
);
$check( 'a bad post: its errors, the name kept, no account', 200 === $out['code'] && str_contains( $out['body'], 'Please enter a valid email address.' ) && str_contains( $out['body'], 'Please use at least 12 characters.' ) && str_contains( $out['body'], 'value="Ada Lovelace"' ) && ! get_user_by( 'email', $email ), (string) $out['code'] );
$out = $http( 'POST', $url, array(), $form( array( '_mody_nonce' => 'deadbeef00' ) ) );
$check( 'an expired nonce is refused', str_contains( $out['body'], 'The form expired.' ) && ! get_user_by( 'email', $email ) );

$out  = $http( 'POST', $url, array(), $form() );
$user = get_user_by( 'email', $email );
$row  = $user ? Members::get( $user->ID ) : null;
$check( 'a good post: to the Mody dashboard, logged in', 302 === $out['code'] && Registration::dashboard_url() === $out['location'] && in_array( LOGGED_IN_COOKIE, $out['cookies'], true ), $out['code'] . ' ' . $out['location'] );
$check( '... the user, with their name', $user && 'Ada Lovelace' === $user->display_name, $user ? $user->user_login : 'no user' );
$check( '... no role on the main site', $user && ! ( new WP_User( $user->ID ) )->roles );
$check( '... a pending trial with the chosen plan next', $row && 'trial' === $row['plan'] && 'pending_payment' === $row['status'] && 'member' === $row['next_plan'], wp_json_encode( $row ? array_intersect_key( $row, array_flip( array( 'plan', 'status', 'next_plan' ) ) ) : null ) );

// 4. The pending account ------------------------------------------------------------

echo "\nThe pending account\n";
if ( $user ) {
	$quota = Quota::can_create_site( $user->ID );
	$check( 'no site before the card is verified', is_wp_error( $quota ) && 'mody_payment_pending' === $quota->get_error_code() );
	$as_member = $cookie( $user->ID );
	$out       = $http( 'GET', Registration::dashboard_url(), $as_member );
	$check( 'the dashboard: the payment step, not the wizard', 200 === $out['code'] && str_contains( $out['body'], 'Finish your payment' ) && ! str_contains( $out['body'], 'id="mody-wizard-form"' ) && ! str_contains( $out['body'], 'wizard.js' ), (string) $out['code'] );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test; own table.
	$wpdb->update( Members::table(), array( 'status' => 'active' ), array( 'user_id' => $user->ID ) ); // Read by the next request, a new process.
	$out = $http( 'GET', Registration::dashboard_url(), $as_member );
	$check( 'once active: the wizard, with the trial\'s 3 sites', str_contains( $out['body'], 'id="mody-wizard-form"' ) && str_contains( $out['body'], '0 of 3 sites used' ) );
	$out = $http( 'GET', $url, $as_member );
	$check( 'a logged-in visitor: "You already have an account."', str_contains( $out['body'], 'You already have an account.' ) && ! str_contains( $out['body'], 'name="mody_password"' ) );
	$check( 'members log in to the dashboard', Registration::dashboard_url() === Registration::login_redirect( admin_url(), '', $user ) );
	$check( '... unless they asked for a page', 'https://example.test/x' === Registration::login_redirect( 'https://example.test/x', 'https://example.test/x', $user ) );
	$check( '... and others keep the default', admin_url() === Registration::login_redirect( admin_url(), '', $super ) );
}

// 5. Closed again -------------------------------------------------------------------

echo "\nClosed again\n";
delete_site_option( Registration::OPEN_OPTION );
$other = 'modyreg2' . $suffix . '@mody.local';
$out   = $http( 'GET', $url );
$check( 'the page says registration opens soon', str_contains( $out['body'], 'Registration opens soon.' ) && ! str_contains( $out['body'], 'name="mody_password"' ) );
$out = $http( 'POST', $url, array(), $form( array( 'mody_email' => $other ) ) );
$check( 'a good post creates nothing', str_contains( $out['body'], 'Registration is not open yet.' ) && ! get_user_by( 'email', $other ) );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
