<?php
/**
 * Tests paying with a card through Mollie (M1-01 part 3, 2026-11-13), against
 * a fake Mollie: every request to api.mollie.com is answered here, so no key
 * and no network are needed. scripts/mollie-sandbox.md is the real sandbox
 * check.
 *
 * Run from the repository root:
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/billing-test.php
 *
 * 1. Off without a key: nothing starts; no webhook address on a local stack.
 * 2. Adding a card: the customer, the 0.01 EUR first payment (card only,
 *    idempotency key, return address naming our row), waiting, then paid:
 *    the mandate and the card's digits kept, the terms emailed with the
 *    withdrawal form; applied once (a replay asks Mollie nothing).
 * 3. The webhook: an unknown ID and a replay change nothing, always 200; a
 *    waiting payment is applied.
 * 4. Charges: due → one recurring charge (the run twice still one); paid →
 *    the plan `paid`, the next date a month on, a receipt.
 * 5. The 14-day withdrawal: the first charge refunded in full, the account
 *    canceled; not twice; not after 14 days.
 * 6. A failed charge: past due and an email; tried again after a day with a
 *    new idempotency key.
 * 7. No card when the free months end: past due and an email.
 * 8. Reminders: a month before, a week before, each once.
 * 9. Plan & billing: Add your card with the terms; the card, Change card,
 *    the payments, the Withdraw button.
 * 10. Cancel: at the end of the period; Keep my plan; on the date the plan
 *     ends without a charge; a card added afterwards brings it back.
 * 11. Read-only (M1-02): on a locked account's site nobody but a super admin
 *     may edit, upload or change styles; reading and the download stay; the
 *     banner; Mody refuses; lifted when paid.
 * 12. Day 53: one last warning; day 60: the sites in the network's trash,
 *     the account expired.
 * 14. Paid sign-ups after the launch offer: the account waits, no site; the
 *     link to pay by email; the pay page with the terms; the first month on
 *     a new card; paid: active, the site made, the welcome email, the 14
 *     days; a second try resends the link; unpaid after a day: removed;
 *     without a key: closed.
 * 15. Refunds and chargebacks made in Mollie's dashboard: a refund recorded,
 *     shown and emailed once, the account unchanged; the withdrawal's own
 *     refund not emailed twice; a chargeback: past due, the card dropped,
 *     the period unpaid again, charged again with a new card; a card check
 *     taken back changes nothing.
 * 16. A test key on production: members are not offered card payments; the
 *     command line still starts a card check.
 * 13. A card that expires before the next charge: said on Plan & billing;
 *     one email in the 30 days before that charge, again for a new card
 *     only; nothing for a card that lasts, or a plan that is cancelled.
 *
 * Users and rows made here are removed. No real payment.
 */

use Mody\Platform\Agent\Budget;
use Mody\Platform\Billing\Billing;
use Mody\Platform\Billing\Lifecycle;
use Mody\Platform\Billing\Mollie;
use Mody\Platform\Billing\Payments;
use Mody\Platform\Dashboard\DashboardPage;
use Mody\Platform\Members\Invites;
use Mody\Platform\Members\Members;
use Mody\Platform\Members\Registration;
use Mody\Platform\Provisioning\Quota;
use Mody\Platform\Security\RateLimit;

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	$failures += $ok ? 0 : 1;
	printf( "%-4s %-72s %s\n", $ok ? 'ok' : 'FAIL', $label, $detail );
};

global $wpdb;
$key_before = getenv( 'MOLLIE_API_KEY' );
putenv( 'MOLLIE_API_KEY' );
RateLimit::clear();

// The fake Mollie ---------------------------------------------------------------------

$fake = array(
	'customers' => 0,
	'payments'  => array(),
	'requests'  => array(),
	'recurring' => 'pending', // The status a new recurring payment starts with.
);
add_filter(
	'pre_http_request',
	static function ( $pre, array $args, string $url ) use ( &$fake ) {
		if ( ! str_starts_with( $url, Mollie::BASE ) ) {
			return $pre;
		}
		$path = substr( $url, strlen( Mollie::BASE ) );
		$body = json_decode( (string) ( $args['body'] ?? '' ), true );
		$fake['requests'][] = array(
			'method' => $args['method'],
			'path'   => $path,
			'body'   => $body,
			'key'    => $args['headers']['Idempotency-Key'] ?? '',
			'auth'   => $args['headers']['Authorization'] ?? '',
		);
		$answer = static fn( int $code, array $data ): array => array(
			'response' => array( 'code' => $code ),
			'body'     => wp_json_encode( $data ),
			'headers'  => array(),
		);
		if ( 'POST' === $args['method'] && '/customers' === $path ) {
			return $answer( 201, array( 'id' => 'cst_fake' . ( ++$fake['customers'] ) ) );
		}
		if ( 'POST' === $args['method'] && '/payments' === $path ) {
			$id                      = 'tr_fake' . ( count( $fake['payments'] ) + 1 );
			$first                   = 'first' === ( $body['sequenceType'] ?? '' );
			$fake['payments'][ $id ] = array(
				'id'         => $id,
				'status'     => $first ? 'open' : $fake['recurring'],
				'amount'     => $body['amount'],
				'customerId' => $body['customerId'] ?? null,
				'mandateId'  => $first ? null : ( $body['mandateId'] ?? null ),
				'_links'     => $first ? array( 'checkout' => array( 'href' => 'https://www.mollie.com/checkout/test-mode?id=' . $id ) ) : array(),
			);
			return $answer( 201, $fake['payments'][ $id ] );
		}
		if ( 'GET' === $args['method'] && preg_match( '#^/payments/(tr_\w+)$#', $path, $m ) ) {
			return isset( $fake['payments'][ $m[1] ] ) ? $answer( 200, $fake['payments'][ $m[1] ] ) : $answer( 404, array( 'detail' => 'No payment exists with token ' . $m[1] ) );
		}
		if ( 'GET' === $args['method'] && preg_match( '#^/customers/cst_\w+/mandates/mdt_\w+$#', $path ) ) {
			return $answer(
				200,
				array(
					'status'  => 'valid',
					'details' => array(
						'cardLabel'      => 'Visa',
						'cardNumber'     => '4242',
						'cardExpiryDate' => '2029-12-31',
					),
				)
			);
		}
		if ( 'POST' === $args['method'] && preg_match( '#^/payments/(tr_\w+)/refunds$#', $path ) ) {
			return $answer(
				201,
				array(
					'id'     => 're_fake',
					'status' => 'queued',
					'amount' => $body['amount'],
				)
			);
		}
		return $answer( 404, array( 'detail' => 'Unknown fake route ' . $path ) );
	},
	10,
	3
);
// Closures, not arrow functions: arrow functions copy $fake and $mails when made.
$requests = static function ( string $method, string $prefix ) use ( &$fake ): array {
	return array_values( array_filter( $fake['requests'], static fn( array $r ): bool => $r['method'] === $method && str_starts_with( $r['path'], $prefix ) ) );
};

$mails = array();
add_filter(
	'pre_wp_mail',
	static function ( $pre, array $mail ) use ( &$mails ) {
		$mails[] = $mail;
		return true;
	},
	10,
	2
);
$mails_to = static function ( string $email ) use ( &$mails ): array {
	return array_values( array_filter( $mails, static fn( array $m ): bool => $m['to'] === $email ) );
};

// Users -----------------------------------------------------------------------------------

$suffix = strtolower( wp_generate_password( 6, false ) );
$users  = array();
$sites  = array();
$member = static function ( string $name, array $fields = array() ) use ( $suffix, &$users ): int {
	$id = (int) wp_insert_user(
		array(
			'user_login'   => 'bill' . $name . $suffix,
			'user_email'   => 'bill' . $name . $suffix . '@mody.local',
			'user_pass'    => wp_generate_password( 24 ),
			'display_name' => ucfirst( $name ) . ' Tester',
			'role'         => '',
		)
	);
	$users[] = $id;
	Members::create( $id, 'free', 'active' );
	Members::update(
		$id,
		array_merge(
			array(
				'free_until'     => gmdate( 'Y-m-d H:i:s', time() + 150 * DAY_IN_SECONDS ),
				'billing_due_at' => gmdate( 'Y-m-d H:i:s', time() + 150 * DAY_IN_SECONDS ),
			),
			$fields
		)
	);
	return $id;
};
register_shutdown_function(
	static function () use ( &$users, &$sites, $key_before, $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		foreach ( $sites as $site ) {
			wpmu_delete_blog( $site, true );
		}
		foreach ( $users as $id ) {
			$wpdb->delete( Payments::table(), array( 'user_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Members::delete( $id );
			wpmu_delete_user( $id );
		}
		false === $key_before ? putenv( 'MOLLIE_API_KEY' ) : putenv( 'MOLLIE_API_KEY=' . $key_before );
		RateLimit::clear();
	}
);

// 1. Off without a key -----------------------------------------------------------------------

echo "Off without a key\n";
$ada = $member( 'ada' );
$off = Billing::add_card( $ada );
$check( 'no key: nothing starts, nothing asked of Mollie', ! Mollie::configured() && is_wp_error( $off ) && 'mody_mollie_off' === $off->get_error_code() && ! $fake['requests'] );
$check( 'no webhook address on a local stack (Mollie cannot reach it)', '' === Billing::webhook_url() );
$check( 'a malformed key counts as none', ( putenv( 'MOLLIE_API_KEY=sk_live_1234' ) || true ) && ! Mollie::configured() );
putenv( 'MOLLIE_API_KEY=test_' . str_repeat( 'a1B2', 8 ) );
$check( 'a test key: configured, in test mode', Mollie::configured() && Mollie::test_mode() );

// 2. Adding a card ---------------------------------------------------------------------------

echo "\nAdding a card\n";
$checkout = Billing::add_card( $ada );
$first    = $requests( 'POST', '/payments' )[0] ?? array();
$row      = Payments::of( $ada, 'card' )[0] ?? array();
$check( 'a checkout on mollie.com', is_string( $checkout ) && str_starts_with( $checkout, 'https://www.mollie.com/' ), is_wp_error( $checkout ) ? $checkout->get_error_message() : (string) $checkout );
$check( '... a customer made once and kept', 1 === count( $requests( 'POST', '/customers' ) ) && 'cst_fake1' === Members::get( $ada )['mollie_customer_id'] );
$check( '... the first payment: 0.01 EUR, card only, sequence "first"', array( 'currency' => 'EUR', 'value' => '0.01' ) === ( $first['body']['amount'] ?? null ) && 'creditcard' === ( $first['body']['method'] ?? '' ) && 'first' === ( $first['body']['sequenceType'] ?? '' ) );
$check( '... an idempotency key, the key as a bearer token, no webhook locally', str_starts_with( (string) $first['key'], 'card-' ) && str_starts_with( (string) $first['auth'], 'Bearer test_' ) && ! isset( $first['body']['webhookUrl'] ) );
$check( '... the return address names our row', str_contains( (string) ( $first['body']['redirectUrl'] ?? '' ), 'payment=' . $row['id'] ) && str_contains( (string) $first['body']['redirectUrl'], 'section=plan' ) );
$check( '... the row: card, open, its Mollie ID', 'card' === $row['kind'] && 'open' === $row['status'] && 'tr_fake1' === $row['mollie_id'] && ! (int) $row['applied'] );
$check( 'back from the checkout before paying: waiting', 'card-waiting' === Billing::returned( $ada, (int) $row['id'] ) );
$check( 'another member\'s row is not theirs', '' === Billing::returned( $member( 'eve' ), (int) $row['id'] ) );

$fake['payments']['tr_fake1']['status']    = 'paid';
$fake['payments']['tr_fake1']['mandateId'] = 'mdt_fake1';
$back = Billing::returned( $ada, (int) $row['id'] );
Members::forget();
$card = get_user_meta( $ada, Billing::CARD_META, true );
$mail = $mails_to( 'billada' . $suffix . '@mody.local' );
$check( 'paid: card added, the mandate kept', 'card-added' === $back && 'mdt_fake1' === Members::get( $ada )['mollie_payment_method_id'], $back );
$check( '... the card\'s brand and last digits for Plan & billing', is_array( $card ) && 'Visa' === $card['label'] && '4242' === $card['number'] );
$check( '... the terms by email, with the 14 days and the withdrawal form', 1 === count( $mail ) && str_contains( $mail[0]['message'], '7.99 EUR a month' ) && str_contains( $mail[0]['message'], 'within 14 days of your first payment' ) && str_contains( $mail[0]['message'], 'I hereby give notice that I withdraw' ) );
$check( '... still free: nothing is charged before the free months end', 'free' === Members::get( $ada )['plan'] && 1 === count( $requests( 'POST', '/payments' ) ) );
$gets = count( $requests( 'GET', '/payments' ) );
Billing::process( 'tr_fake1' );
$check( 'a replay: applied once, Mollie not asked again, no second email', count( $requests( 'GET', '/payments' ) ) === $gets && 1 === count( $mails_to( 'billada' . $suffix . '@mody.local' ) ) );

// 3. The webhook ------------------------------------------------------------------------------

echo "\nThe webhook\n";
$hook = static function ( string $id ): int {
	$request = new WP_REST_Request( 'POST', '/mody-platform/v1/mollie/webhook' );
	$request->set_param( 'id', $id );
	return rest_do_request( $request )->get_status();
};
$before = count( $fake['requests'] );
$check( 'an ID that is not ours: 200, Mollie not asked, nothing changed', 200 === $hook( 'tr_forged123' ) && count( $fake['requests'] ) === $before );
$mails_before = count( $mails );
$check( 'a replay of an applied payment: 200, Mollie asked again (for refunds), nothing changed', 200 === $hook( 'tr_fake1' ) && count( $fake['requests'] ) === $before + 1 && count( $mails ) === $mails_before && 'mdt_fake1' === Members::get( $ada )['mollie_payment_method_id'] );

// 4. Charges -----------------------------------------------------------------------------------

echo "\nCharges\n";
$due     = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
$inviter = (int) get_user_by( 'login', get_super_admins()[0] )->ID;
Members::update( $ada, array( 'free_until' => $due, 'billing_due_at' => $due, 'invited_by' => $inviter ) ); // Invited (tickets/invites-and-offers.md).
$run    = Billing::run();
$again  = Billing::run();
$charge = $requests( 'POST', '/payments' );
$charge = end( $charge );
$rows   = Payments::of( $ada, 'charge' );
$check( 'due: one recurring charge on the mandate, the run twice still one', 1 === $run['charged'] && 0 === $again['charged'] && 1 === count( $rows ), wp_json_encode( $run ) );
$check( '... 7.99 EUR for one site, a month', array( 'currency' => 'EUR', 'value' => '7.99' ) === $charge['body']['amount'] && 'recurring' === $charge['body']['sequenceType'] && 'mdt_fake1' === $charge['body']['mandateId'] && 'monthly' === $rows[0]['period'] );
$check( '... it covers from the due date, a month', $due === $rows[0]['covers_from'] && gmdate( 'Y-m-d H:i:s', (int) strtotime( '+1 month', (int) strtotime( $due . ' UTC' ) ) ) === $rows[0]['covers_to'] );
$fake['payments'][ $rows[0]['mollie_id'] ]['status'] = 'paid';
$hook( $rows[0]['mollie_id'] ); // Mollie's webhook, as on production.
Members::forget();
$after = Members::get( $ada );
$check( 'paid (through the webhook): plan paid, active, the next date a month on', 'paid' === $after['plan'] && 'active' === $after['status'] && $rows[0]['covers_to'] === $after['billing_due_at'] );
$check( '... a receipt', str_contains( (string) ( end( $mails )['subject'] ?? '' ), 'payment received' ) );
$check( '... the first paid charge: a point for who invited her', null !== Members::get( $ada )['invite_point_at'] );

// 5. The 14-day withdrawal ------------------------------------------------------------------------

echo "\nThe 14-day withdrawal\n";
$window = Billing::withdrawal( $ada );
$check( 'offered within 14 days of the first charge', $window && abs( $window['until'] - ( time() + 14 * DAY_IN_SECONDS ) ) < 120 );
$done   = Billing::withdraw( $ada );
$refund = $requests( 'POST', '/payments/' . $rows[0]['mollie_id'] . '/refunds' );
Members::forget();
$check( 'withdrawn: the first charge refunded in full', true === $done && 1 === count( $refund ) && '7.99' === $refund[0]['body']['amount']['value'] && 'refund-' . $rows[0]['mollie_id'] === $refund[0]['key'] );
$check( '... the account canceled, nothing more due, an email', 'canceled' === Members::get( $ada )['status'] && null === Members::get( $ada )['billing_due_at'] && str_contains( (string) end( $mails )['subject'], 'withdrawn' ) );
$check( '... not twice', null === Billing::withdrawal( $ada ) && is_wp_error( Billing::withdraw( $ada ) ) );
$check( '... the inviter\'s point taken back', null === Members::get( $ada )['invite_point_at'] && $inviter === (int) Members::get( $ada )['invited_by'] );
$check( '... a canceled account can add a card to come back (section 10)', is_string( Billing::add_card( $ada ) ) );
$late = $member( 'late', array( 'plan' => 'paid' ) );
Payments::add( array( 'user_id' => $late, 'mollie_id' => 'tr_old' . $suffix, 'kind' => 'charge', 'amount' => 7.99, 'sites' => 1, 'period' => 'monthly', 'status' => 'paid', 'applied' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 15 * DAY_IN_SECONDS ) ) );
$check( 'not after 14 days', null === Billing::withdrawal( $late ) );

// 6. A failed charge -------------------------------------------------------------------------------

echo "\nA failed charge\n";
$bob = $member( 'bob', array( 'mollie_customer_id' => 'cst_bob', 'mollie_payment_method_id' => 'mdt_bob', 'billing_due_at' => $due ) );
$fake['recurring'] = 'failed';
Billing::run();
Members::forget();
$bobs = Payments::of( $bob, 'charge' );
$check( 'failed at once: past due, an email', 'past_due' === Members::get( $bob )['status'] && null !== Members::get( $bob )['past_due_since'] && 1 === count( $bobs ) && str_contains( (string) end( $mails )['subject'], 'did not go through' ) );
$check( '... not tried again the same day', 0 === Billing::run()['retried'] );
$wpdb->update( Payments::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 25 * HOUR_IN_SECONDS ) ), array( 'id' => $bobs[0]['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$fake['recurring'] = 'paid';
$retry = Billing::run();
$keys  = array_column( array_filter( $requests( 'POST', '/payments' ), static fn( array $r ): bool => 'mdt_bob' === ( $r['body']['mandateId'] ?? '' ) ), 'key' );
Members::forget();
$check( 'a day later: tried again, a new idempotency key, paid: active again', 1 === $retry['retried'] && 2 === count( array_unique( $keys ) ) && 'active' === Members::get( $bob )['status'] && null === Members::get( $bob )['past_due_since'], wp_json_encode( $retry ) );

// 7. No card when the free months end ----------------------------------------------------------------

echo "\nNo card\n";
$cy  = $member( 'cy', array( 'free_until' => $due, 'billing_due_at' => $due ) );
$run = Billing::run();
Members::forget();
$check( 'free months over, no card: past due, an email, nothing charged', 1 === $run['no_card'] && 'past_due' === Members::get( $cy )['status'] && ! Payments::of( $cy ) && str_contains( (string) end( $mails )['subject'], 'add a card' ) );

// 8. Reminders ----------------------------------------------------------------------------------------

echo "\nReminders\n";
$di    = $member( 'di', array( 'free_until' => gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ) ) );
$count = static fn(): int => count( array_filter( $mails_to( 'billdi' . $suffix . '@mody.local' ), static fn( array $m ): bool => str_contains( $m['subject'], 'free months end soon' ) ) );
Billing::run();
Billing::run();
$check( 'a month before: one reminder', 1 === $count() );
Members::update( $di, array( 'free_until' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) ) );
Billing::run();
Billing::run();
$check( 'a week before: one more', 2 === $count() );
$check( '... with the date, both prices and the link', str_contains( (string) end( $mails )['message'], '79.99 EUR a year' ) && str_contains( (string) end( $mails )['message'], 'section=plan' ) );
$ed = $member( 'ed', array( 'free_until' => gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ), 'mollie_payment_method_id' => 'mdt_ed' ) );
Billing::run();
$check( 'with a card: no reminder', ! $mails_to( 'billed' . $suffix . '@mody.local' ) );

// 9. Plan & billing ---------------------------------------------------------------------------------------

echo "\nPlan & billing\n";
$render = static function ( int $user_id ): string {
	add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' ); // The dashboard needs a role on the site it is on.
	wp_set_current_user( $user_id );
	$_GET['section'] = 'plan';
	ob_start();
	DashboardPage::render();
	unset( $_GET['section'] );
	remove_user_from_blog( $user_id, get_current_blog_id() );
	return (string) ob_get_clean();
};
$html = $render( $di );
$check( 'no card: Add your card, the terms to tick, test mode said', str_contains( $html, 'Add your card' ) && str_contains( $html, 'name="accept"' ) && str_contains( $html, 'A card check of 0.01 EUR is charged now' ) && str_contains( $html, 'Test mode: no real money moves.' ) );
$fresh = $member( 'fay', array( 'plan' => 'paid', 'mollie_customer_id' => 'cst_fay', 'mollie_payment_method_id' => 'mdt_fay' ) );
update_user_meta( $fresh, Billing::CARD_META, array( 'label' => 'Mastercard', 'number' => '5555', 'expiry' => '2030-01-31' ) );
Payments::add( array( 'user_id' => $fresh, 'mollie_id' => 'tr_fay' . $suffix, 'kind' => 'charge', 'amount' => 7.99, 'sites' => 1, 'period' => 'monthly', 'covers_from' => gmdate( 'Y-m-d H:i:s' ), 'covers_to' => gmdate( 'Y-m-d H:i:s', (int) strtotime( '+1 month' ) ), 'status' => 'paid', 'applied' => 1 ) );
$html = $render( $fresh );
$check( 'a card: its brand and digits, Change card', str_contains( $html, 'Mastercard ending in 5555' ) && str_contains( $html, 'Change card' ) );
$check( '... the payments, and the Withdraw button while it applies', str_contains( $html, 'mody-dashboard__payments' ) && str_contains( $html, 'Withdraw from the contract' ) && str_contains( $html, 'name="confirm"' ) );
$check( 'a card and a date: Cancel your plan, to confirm', str_contains( $html, 'Cancel my plan' ) && str_contains( $html, Billing::CANCEL_ACTION ) );

// 10. Cancel ---------------------------------------------------------------------------------------------

echo "\nCancel\n";
$gil = $member( 'gil', array( 'plan' => 'paid', 'mollie_customer_id' => 'cst_gil', 'mollie_payment_method_id' => 'mdt_gil', 'billing_due_at' => gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ) ) );
$none = $member( 'hal' ); // Free, no card.
$check( 'without a card there is nothing to cancel (the free months just end)', ! Billing::can_cancel( $none ) && is_wp_error( Billing::cancel( $none ) ) );
$done = Billing::cancel( $gil );
Members::forget();
$check( 'cancelled: it ends on the paid date, an email, still active', true === $done && Members::get( $gil )['billing_due_at'] === Members::get( $gil )['cancel_at'] && 'active' === Members::get( $gil )['status'] && str_contains( (string) end( $mails )['subject'], 'plan ends soon' ) );
$html = $render( $gil );
$check( '... Plan & billing says when, and offers Keep my plan', str_contains( $html, 'Your plan ends soon' ) && str_contains( $html, 'Keep my plan' ) && ! str_contains( $html, 'Cancel my plan' ) );
$check( 'Keep my plan undoes it', true === Billing::resume( $gil ) && null === Members::get( $gil )['cancel_at'] && Billing::can_cancel( $gil ) );
Billing::cancel( $gil );
Members::update( $gil, array( 'billing_due_at' => $due, 'cancel_at' => $due ) );
$posts = count( $requests( 'POST', '/payments' ) );
$run   = Billing::run();
Members::forget();
$check( 'on the date: ended, canceled, nothing charged, the 60 days start, an email', 1 === $run['ended'] && 'canceled' === Members::get( $gil )['status'] && null === Members::get( $gil )['billing_due_at'] && null !== Members::get( $gil )['past_due_since'] && count( $requests( 'POST', '/payments' ) ) === $posts && str_contains( (string) end( $mails )['subject'], 'plan has ended' ) );
$checkout = Billing::add_card( $gil );
$back_id  = (int) Payments::of( $gil, 'card' )[0]['id'];
$tr       = Payments::of( $gil, 'card' )[0]['mollie_id'];
$fake['payments'][ $tr ]['status']    = 'paid';
$fake['payments'][ $tr ]['mandateId'] = 'mdt_gil2';
Billing::returned( $gil, $back_id );
Members::forget();
$check( 'a card added after the end: charged from today, active again', is_string( $checkout ) && 'active' === Members::get( $gil )['status'] && 'paid' === Payments::of( $gil, 'charge' )[0]['status'] && abs( strtotime( Payments::of( $gil, 'charge' )[0]['covers_from'] . ' UTC' ) - time() ) < 120 );

// 11. Read-only ------------------------------------------------------------------------------------------

echo "\nRead-only\n";
$jo     = $member( 'jo', array( 'plan' => 'paid' ) );
$editor = $member( 'kit' );
Members::delete( $editor ); // A plain user, an editor on the member's site.
$site    = (int) wpmu_create_blog( 'billro' . $suffix . '.' . preg_replace( '/^www\./', '', get_network()->domain ), '/', 'Read-only test', $jo );
$sites[] = $site;
update_site_meta( $site, Quota::OWNER_META, $jo );
add_user_to_blog( $site, $editor, 'editor' );
$can = static function ( int $user, string $cap ) use ( $site ): bool {
	Lifecycle::forget();
	return user_can_for_site( $user, $site, $cap );
};
$check( 'active: the owner edits, uploads and changes styles', $can( $jo, 'edit_pages' ) && $can( $jo, 'upload_files' ) && $can( $jo, 'edit_theme_options' ) && null === Lifecycle::site_lock( $site ) );
Members::update( $jo, array( 'status' => 'past_due', 'past_due_since' => gmdate( 'Y-m-d H:i:s' ) ) );
$check( 'past due: the owner cannot edit, upload, publish or change styles', ! $can( $jo, 'edit_pages' ) && ! $can( $jo, 'edit_posts' ) && ! $can( $jo, 'upload_files' ) && ! $can( $jo, 'publish_posts' ) && ! $can( $jo, 'edit_theme_options' ) );
$check( '... nor can the editor they invited', ! $can( $editor, 'edit_posts' ) && ! $can( $editor, 'edit_others_pages' ) );
$check( '... reading, settings (the download, Plan & billing) stay', $can( $jo, 'read' ) && $can( $jo, 'manage_options' ) );
$admins = get_super_admins();
$super  = get_user_by( 'login', $admins[0] );
$check( '... a super admin is not locked', $can( (int) $super->ID, 'edit_pages' ) );
$post = (int) wp_insert_post( array( 'post_title' => 'x', 'post_status' => 'publish' ) );
switch_to_blog( $site );
wp_set_current_user( $jo );
Lifecycle::forget();
$page    = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Locked', 'post_status' => 'draft' ) );
$request = new WP_REST_Request( 'POST', '/wp/v2/pages' );
$request->set_param( 'title', 'Over REST' );
$rest    = rest_do_request( $request );
$budget  = Budget::check();
ob_start();
Lifecycle::banner();
$banner = (string) ob_get_clean();
restore_current_blog();
wp_delete_post( $post, true );
$check( '... REST refuses a new page (403)', 403 === $rest->get_status(), (string) $rest->get_status() );
$check( '... Mody refuses on the site', $budget instanceof WP_Error && 'mody_site_read_only' === $budget->get_error_code() );
$check( '... the banner: read-only, the deletion date, Plan & billing for the owner', str_contains( $banner, 'read-only until a payment goes through' ) && str_contains( $banner, wp_date( (string) get_option( 'date_format' ), time() + 60 * DAY_IN_SECONDS ) ) && str_contains( $banner, 'Plan &amp; billing' ) );
Members::update( $jo, array( 'status' => 'active', 'past_due_since' => null ) );
$check( 'paid again: lifted at once', $can( $jo, 'edit_pages' ) && $can( $editor, 'edit_posts' ) );
Members::update( $jo, array( 'status' => 'canceled', 'past_due_since' => gmdate( 'Y-m-d H:i:s' ) ) );
switch_to_blog( $site );
wp_set_current_user( $jo );
Lifecycle::forget();
ob_start();
Lifecycle::banner();
$banner = (string) ob_get_clean();
restore_current_blog();
$check( 'canceled: locked too, with its own words', ! $can( $jo, 'edit_pages' ) && str_contains( $banner, 'Your plan has ended' ) );

// 12. Day 53 and day 60 ------------------------------------------------------------------------------------

echo "\nDay 53 and day 60\n";
Members::update( $jo, array( 'past_due_since' => gmdate( 'Y-m-d H:i:s', time() - 54 * DAY_IN_SECONDS ) ) );
$first  = Billing::run();
$second = Billing::run();
$warn   = array_filter( $mails_to( 'billjo' . $suffix . '@mody.local' ), static fn( array $m ): bool => str_contains( $m['subject'], 'deleted in a week' ) );
$check( 'day 53: one last warning, with the download link', 1 === $first['warned'] && 0 === $second['warned'] && 1 === count( $warn ) && str_contains( (string) reset( $warn )['message'], 'section=export' ) );
Members::update( $jo, array( 'past_due_since' => gmdate( 'Y-m-d H:i:s', time() - 61 * DAY_IN_SECONDS ) ) );
$run = Billing::run();
Members::forget();
$check( 'day 60: the site in the network\'s trash (restorable), the account expired, an email', 1 === $run['deleted'] && '1' === (string) get_site( $site )->deleted && 'expired' === Members::get( $jo )['status'] && str_contains( (string) end( $mails )['subject'], 'sites are deleted' ) );
$check( '... and not again', 0 === Billing::run()['deleted'] );
$check( 'an expired account cannot add a card', is_wp_error( Billing::add_card( $jo ) ) );

// 13. A card that expires before the next charge -----------------------------------------------------------

echo "\nAn expiring card\n";
$liv = $member( 'liv', array( 'plan' => 'paid', 'mollie_customer_id' => 'cst_liv', 'mollie_payment_method_id' => 'mdt_liv', 'billing_due_at' => gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ) ) );
update_user_meta( $liv, Billing::CARD_META, array( 'label' => 'Visa', 'number' => '1111', 'expiry' => gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS ) ) );
$exp = static fn(): int => count( array_filter( $mails_to( 'billliv' . $suffix . '@mody.local' ), static fn( array $m ): bool => str_contains( $m['subject'], 'card expires' ) ) );
$check( 'it expires before the charge: known', Billing::card_expires_first( $liv ) > time() );
$html = $render( $liv );
$check( '... Plan & billing says so, next to the card', str_contains( $html, 'This card expires on' ) && str_contains( $html, 'Change card' ) );
$first = Billing::run();
Billing::run();
$check( '... one email in the 30 days before the charge', 1 === $first['expiring'] && 1 === $exp() );
Members::update( $liv, array( 'mollie_payment_method_id' => 'mdt_liv2' ) ); // A new card that expires early too.
Billing::run();
$check( '... again for a new card only', 2 === $exp() );
update_user_meta( $liv, Billing::CARD_META, array( 'label' => 'Visa', 'number' => '2222', 'expiry' => gmdate( 'Y-m-d', time() + 400 * DAY_IN_SECONDS ) ) );
$check( 'a card that lasts: nothing said', 0 === Billing::card_expires_first( $liv ) && ! str_contains( $render( $liv ), 'This card expires on' ) );
$far = $member( 'mo', array( 'plan' => 'paid', 'mollie_payment_method_id' => 'mdt_mo', 'billing_due_at' => gmdate( 'Y-m-d H:i:s', time() + 90 * DAY_IN_SECONDS ) ) );
update_user_meta( $far, Billing::CARD_META, array( 'expiry' => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ) ) );
Billing::run();
$check( 'a charge more than 30 days away: shown on the page, no email yet', Billing::card_expires_first( $far ) > 0 && ! $mails_to( 'billmo' . $suffix . '@mody.local' ) );
Members::update( $far, array( 'cancel_at' => Members::get( $far )['billing_due_at'] ) );
$check( 'a cancelled plan: nothing to warn about', 0 === Billing::card_expires_first( $far ) );

// 14. Paid sign-ups ----------------------------------------------------------------------------------------

echo "\nPaid sign-ups\n";
$promo = array(
	Members::PROMO_START_OPTION => get_site_option( Members::PROMO_START_OPTION, null ),
	Members::PROMO_GIVEN_OPTION => get_site_option( Members::PROMO_GIVEN_OPTION, null ),
);
register_shutdown_function(
	static function () use ( $promo ): void {
		foreach ( $promo as $option => $value ) {
			null === $value ? delete_site_option( $option ) : update_site_option( $option, $value );
		}
	}
);
update_site_option( Members::PROMO_GIVEN_OPTION, Members::FREE_PLACES ); // The 50 places are taken.
RateLimit::clear();
wp_set_current_user( 0 );
$email   = 'billpay' . $suffix . '@mody.local';
$slug    = 'billpay' . $suffix;
$account = Registration::register( 'Pia Payer', $email, 'Pia Paid Bakery', $slug, false, Invites::code( $inviter ) );
$pia     = is_array( $account ) ? (int) $account['user_id'] : 0;
$users[] = $pia;
Members::forget();
$check( 'paying now: the chat says so', Registration::paying() && Registration::takes_accounts() );
$check( 'the account waits for its payment: paid plan, pending, no site', $pia && 'pending_payment' === Members::get( $pia )['status'] && 'paid' === Members::get( $pia )['plan'] && ! Lifecycle::owned_sites( $pia ) && 0 === $account['site_id'], is_wp_error( $account ) ? $account->get_error_message() : '' );
$link = (string) ( end( $mails )['message'] ?? '' );
preg_match( '#mody_pay=([A-Za-z0-9]+)&u=(\d+)#', html_entity_decode( $link ), $m );
$token = $m[1] ?? '';
$check( '... an email with the link to pay, the price with VAT, 24 hours', str_contains( (string) end( $mails )['subject'], 'pay and create your site' ) && str_contains( $link, '7.99 EUR a month, VAT included' ) && '' !== $token && (int) ( $m[2] ?? 0 ) === $pia );
$check( '... only its hash is kept', ! str_contains( (string) wp_json_encode( get_user_meta( $pia, Registration::PAY_META, true ) ), $token ) && null !== Registration::pending( $pia, $token ) && null === Registration::pending( $pia, 'wrong' . $token ) );
$page = static function ( array $get ): string {
	$_GET = $get;
	$html = Registration::render();
	$_GET = array();
	return $html;
};
$html = $page( array( 'mody_pay' => $token, 'u' => $pia ) );
$check( 'the pay page: the site, the price, the terms to tick, Pay with card', str_contains( $html, 'Pia Paid Bakery' ) && str_contains( $html, '7.99 EUR a month' ) && str_contains( $html, 'name="accept"' ) && str_contains( $html, 'withdraw within 14 days' ) && str_contains( $html, 'Pay with card' ) );
$check( '... a wrong link does not open it', str_contains( $page( array( 'mody_pay' => 'nope', 'u' => $pia ) ), 'This link does not work' ) );
$checkout = Billing::start_signup( $pia, Registration::pay_url( $pia, $token ) );
$first    = $requests( 'POST', '/payments' );
$first    = end( $first );
$row      = Payments::of( $pia )[0];
$check( 'the first month: 7.99 EUR on a new card (sequence "first"), back to the pay page', is_string( $checkout ) && '7.99' === $first['body']['amount']['value'] && 'first' === $first['body']['sequenceType'] && 'creditcard' === $first['body']['method'] && str_contains( (string) $first['body']['redirectUrl'], 'mody_pay=' ) && 'signup' === $row['kind'] );
$html = $page( array( 'mody_pay' => $token, 'u' => $pia, 'payment' => $row['id'] ) );
$check( 'back before paying: still the pay page', str_contains( $html, 'Pay with card' ) || str_contains( $html, 'being processed' ) );
$fake['payments'][ $row['mollie_id'] ]['status']    = 'paid';
$fake['payments'][ $row['mollie_id'] ]['mandateId'] = 'mdt_pia';
$hook( $row['mollie_id'] );
Members::forget();
$owned   = Lifecycle::owned_sites( $pia );
$sites   = array_merge( $sites, $owned );
$welcome = array_values( array_filter( $mails_to( $email ), static fn( array $mail ): bool => str_contains( $mail['subject'], 'choose your password' ) ) );
$check( 'paid (webhook): active, paid, the card kept, next charge a month on', 'active' === Members::get( $pia )['status'] && 'paid' === Members::get( $pia )['plan'] && 'mdt_pia' === Members::get( $pia )['mollie_payment_method_id'] && abs( strtotime( Members::get( $pia )['billing_due_at'] . ' UTC' ) - strtotime( '+1 month' ) ) < 300 );
$check( '... the site made at its address', 1 === count( $owned ) && str_starts_with( get_site( $owned[0] )->domain, $slug . '.' ) );
$check( '... the welcome email with the password link and the next payment', 1 === count( $welcome ) && str_contains( $welcome[0]['message'], 'Your payment went through' ) && str_contains( $welcome[0]['message'], 'action=rp' ) );
$check( '... the terms by email; the 14-day withdrawal applies', (bool) array_filter( $mails_to( $email ), static fn( array $mail ): bool => str_contains( $mail['subject'], 'card is added' ) ) && null !== Billing::withdrawal( $pia ) );
$check( '... the pay page now says it is done', str_contains( $page( array( 'mody_pay' => $token, 'u' => $pia ) ), 'Paid, thank you!' ) );
Billing::process( $row['mollie_id'] );
$check( '... a replay makes no second site', 1 === count( Lifecycle::owned_sites( $pia ) ) );
$check( 'invited (a paid sign-up): recorded, and the first month earns the point', $inviter === (int) Members::get( $pia )['invited_by'] && null !== Members::get( $pia )['invite_point_at'] );

$email2 = 'billpay2' . $suffix . '@mody.local';
$second = Registration::register( 'Quin Payer', $email2, 'Quin Shop', 'billpayq' . $suffix );
$quin   = is_array( $second ) ? (int) $second['user_id'] : 0;
$users[] = $quin;
preg_match( '#mody_pay=([A-Za-z0-9]+)#', (string) end( $mails )['message'], $m1 );
RateLimit::clear();
Registration::register( 'Quin Payer', $email2, 'Quin Shop', 'billpayq' . $suffix );
preg_match( '#mody_pay=([A-Za-z0-9]+)#', (string) end( $mails )['message'], $m2 );
$check( 'the same email again before paying: a new link, the old one off', isset( $m1[1], $m2[1] ) && $m1[1] !== $m2[1] && null === Registration::pending( $quin, $m1[1] ) && null !== Registration::pending( $quin, $m2[1] ) );
$wpdb->update( Members::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 25 * HOUR_IN_SECONDS ) ), array( 'user_id' => $quin ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$run = Billing::run();
$check( 'not paid within a day: the account removed', 1 === $run['unpaid'] && false === get_userdata( $quin ) && null === Members::get( $quin ) );
putenv( 'MOLLIE_API_KEY' );
$closed = Registration::register( 'Ray Late', 'billpay3' . $suffix . '@mody.local', 'Ray', 'billpayr' . $suffix );
$check( 'without a key after the offer: closed', is_wp_error( $closed ) && ! Registration::takes_accounts() && ! get_user_by( 'email', 'billpay3' . $suffix . '@mody.local' ) );
putenv( 'MOLLIE_API_KEY=test_' . str_repeat( 'a1B2', 8 ) );

// 15. Refunds and chargebacks made in Mollie's dashboard -------------------------------------------------

echo "\nRefunds and chargebacks\n";
$ned = $member( 'ned', array( 'plan' => 'paid', 'mollie_customer_id' => 'cst_ned', 'mollie_payment_method_id' => 'mdt_ned', 'billing_due_at' => $due ) );
update_user_meta( $ned, Billing::CARD_META, array( 'label' => 'Visa', 'number' => '4242', 'expiry' => '2030-12-31' ) );
$fake['recurring'] = 'paid';
Billing::run();
Members::forget();
$nrow  = Payments::of( $ned, 'charge' )[0];
$nmail = static fn( string $subject ): int => count( array_filter( $mails_to( 'billned' . $suffix . '@mody.local' ), static fn( array $m ): bool => str_contains( $m['subject'], $subject ) ) );
$check( 'a paid charge to reverse', 'paid' === $nrow['status'] && 'active' === Members::get( $ned )['status'] );
$fake['payments'][ $nrow['mollie_id'] ]['amountRefunded'] = array( 'currency' => 'EUR', 'value' => '3.00' );
$hook( $nrow['mollie_id'] );
$hook( $nrow['mollie_id'] );
Members::forget();
$nrow = Payments::get( (int) $nrow['id'] );
$check( 'a refund made in Mollie: recorded once, one email, the account unchanged', 3.0 === (float) $nrow['refunded'] && null !== $nrow['refunded_at'] && 1 === $nmail( 'refund is on its way' ) && 'active' === Members::get( $ned )['status'] && 'mdt_ned' === Members::get( $ned )['mollie_payment_method_id'] );
$check( '... Plan & billing: "Refunded 3.00 EUR"', str_contains( $render( $ned ), 'Refunded 3.00 EUR' ) );
$fake['payments'][ $nrow['mollie_id'] ]['amountRefunded'] = array( 'currency' => 'EUR', 'value' => '7.99' );
$hook( $nrow['mollie_id'] );
$check( '... refunded in full later: the amount updated, no second email', 7.99 === (float) Payments::get( (int) $nrow['id'] )['refunded'] && 1 === $nmail( 'refund is on its way' ) );
$wd = $rows[0]; // Ada's withdrawn charge (section 5): the platform refunded it itself.
$fake['payments'][ $wd['mollie_id'] ]['amountRefunded'] = array( 'currency' => 'EUR', 'value' => '7.99' );
$before_ada = count( $mails_to( 'billada' . $suffix . '@mody.local' ) );
$hook( $wd['mollie_id'] );
$check( 'the withdrawal\'s own refund: no "refund" email on top', count( $mails_to( 'billada' . $suffix . '@mody.local' ) ) === $before_ada );

$fake['payments'][ $nrow['mollie_id'] ]['amountChargedBack'] = array( 'currency' => 'EUR', 'value' => '7.99' );
$hook( $nrow['mollie_id'] );
$hook( $nrow['mollie_id'] );
Members::forget();
Lifecycle::forget();
$nm = Members::get( $ned );
$check( 'a chargeback: past due, the card dropped, the period unpaid again, one email', 'past_due' === $nm['status'] && null === $nm['mollie_payment_method_id'] && $nrow['covers_from'] === $nm['billing_due_at'] && ! get_user_meta( $ned, Billing::CARD_META, true ) && 1 === $nmail( 'took a payment back' ) );
$check( '... Plan & billing: "Taken back by the bank", Add your card', str_contains( $render( $ned ), 'Taken back by the bank' ) && str_contains( $render( $ned ), 'Add your card' ) );
$check( '... no automatic retry on the disputed card', 0 === Billing::run()['retried'] );
Billing::add_card( $ned );
$ncard = Payments::of( $ned, 'card' )[0];
$fake['payments'][ $ncard['mollie_id'] ]['status']    = 'paid';
$fake['payments'][ $ncard['mollie_id'] ]['mandateId'] = 'mdt_ned2';
$hook( $ncard['mollie_id'] );
Members::forget();
$again = Payments::of( $ned, 'charge' )[0];
$check( 'a new card: that period charged again, active', $again['id'] !== $nrow['id'] && $nrow['covers_from'] === $again['covers_from'] && 'paid' === $again['status'] && 'active' === Members::get( $ned )['status'] );
$fake['payments'][ $ncard['mollie_id'] ]['amountChargedBack'] = array( 'currency' => 'EUR', 'value' => '0.01' );
$hook( $ncard['mollie_id'] );
Members::forget();
$check( 'the card check taken back: recorded, nothing else changes', 0.01 === (float) Payments::get( (int) $ncard['id'] )['charged_back'] && 'active' === Members::get( $ned )['status'] && 'mdt_ned2' === Members::get( $ned )['mollie_payment_method_id'] );

// 16. A test key on production ------------------------------------------------------------------------------

echo "\nA test key on production\n";
$env = static fn(): string => 'production';
add_filter( 'mody_billing_environment', $env );
$check( 'members are not offered card payments', ! Mollie::offered() && ! str_contains( $render( $di ), 'name="accept"' ) && ! Registration::paying() );
$check( '... the command line still starts a card check', is_string( Billing::add_card( $di ) ) );
remove_filter( 'mody_billing_environment', $env );
$check( 'a test key elsewhere (local) is offered', Mollie::offered() );

putenv( 'MOLLIE_API_KEY' );
$html = $render( $di );
$check( 'without a key: "Card payments open soon", no form', str_contains( $html, 'Card payments open soon' ) && ! str_contains( $html, 'name="accept"' ) );
wp_set_current_user( 0 );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
