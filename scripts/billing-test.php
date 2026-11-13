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
 *
 * Users and rows made here are removed. No real payment.
 */

use Mody\Platform\Billing\Billing;
use Mody\Platform\Billing\Mollie;
use Mody\Platform\Billing\Payments;
use Mody\Platform\Dashboard\DashboardPage;
use Mody\Platform\Members\Members;
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
	static function () use ( &$users, $key_before, $wpdb ): void {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
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
$check( 'a replay of an applied payment: 200, nothing asked', 200 === $hook( 'tr_fake1' ) && count( $fake['requests'] ) === $before );

// 4. Charges -----------------------------------------------------------------------------------

echo "\nCharges\n";
$due = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
Members::update( $ada, array( 'free_until' => $due, 'billing_due_at' => $due ) );
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
$check( '... a canceled account cannot add a card', is_wp_error( Billing::add_card( $ada ) ) );
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
putenv( 'MOLLIE_API_KEY' );
$html = $render( $di );
$check( 'without a key: "Card payments open soon", no form', str_contains( $html, 'Card payments open soon' ) && ! str_contains( $html, 'name="accept"' ) );
wp_set_current_user( 0 );

echo $failures ? "\n$failures check(s) failed.\n" : "\nAll checks passed.\n";
