<?php
/**
 * Mollie's REST API, through WordPress's HTTP functions.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Billing;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The few Mollie calls the platform makes (M1-01 part 3, decided
 * 2026-11-13: cards only, no new dependency, so no Mollie PHP client):
 * customers, payments (the first one makes the card's mandate, the next
 * ones charge it), mandates and refunds.
 *
 * - **The key** is only in the environment: `MOLLIE_API_KEY` (`test_…` in
 *   the sandbox, `live_…` for real money). Never stored, logged or shown.
 * - **Mollie, EU:** api.mollie.com is Mollie B.V., Amsterdam (rule 1).
 * - **Every POST** carries an `Idempotency-Key`, so a request retried after a
 *   timeout never charges twice.
 */
final class Mollie {

	/** The API's base address. */
	public const BASE = 'https://api.mollie.com/v2';

	/** Seconds a request may take. */
	private const TIMEOUT = 20;

	/**
	 * The API key, or '' when none is set.
	 */
	public static function key(): string {
		$key = getenv( 'MOLLIE_API_KEY' );
		return is_string( $key ) && preg_match( '/^(test|live)_[A-Za-z0-9]{20,}$/', $key ) ? $key : '';
	}

	/**
	 * Whether payments are possible: a key is set.
	 */
	public static function configured(): bool {
		return '' !== self::key();
	}

	/**
	 * Whether members are offered card payments: a key is set, and it is not
	 * a sandbox key on production (2026-11-13). A `test_` key on production is
	 * for checking Mollie's webhook from the command line (`wp mody-billing
	 * card-check`); members never see test mode there, so no card of theirs
	 * is kept in the sandbox, where the live key cannot charge it.
	 */
	public static function offered(): bool {
		/**
		 * Filters the environment payments run in (tests).
		 *
		 * @param string $type wp_get_environment_type().
		 */
		$environment = (string) apply_filters( 'mody_billing_environment', wp_get_environment_type() );
		return self::configured() && ! ( self::test_mode() && 'production' === $environment );
	}

	/**
	 * Whether the key is the sandbox's.
	 */
	public static function test_mode(): bool {
		return str_starts_with( self::key(), 'test_' );
	}

	/**
	 * An amount as Mollie wants it: EUR and a string with two decimals.
	 *
	 * @param float $eur Euros.
	 * @return array{currency: string, value: string}
	 */
	public static function amount( float $eur ): array {
		return array(
			'currency' => 'EUR',
			'value'    => number_format( $eur, 2, '.', '' ),
		);
	}

	/**
	 * Creates a customer.
	 *
	 * @param string $name  Name.
	 * @param string $email Email.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_customer( string $name, string $email ): array|WP_Error {
		return self::request(
			'POST',
			'/customers',
			array(
				'name'  => $name,
				'email' => $email,
			),
			'customer-' . md5( strtolower( $email ) )
		);
	}

	/**
	 * Creates a payment.
	 *
	 * @param array<string, mixed> $payment The payment, as Mollie's API takes it.
	 * @param string               $key     The idempotency key.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_payment( array $payment, string $key ): array|WP_Error {
		return self::request( 'POST', '/payments', $payment, $key );
	}

	/**
	 * A payment.
	 *
	 * @param string $id `tr_…`.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_payment( string $id ): array|WP_Error {
		if ( ! preg_match( '/^tr_[A-Za-z0-9]+$/', $id ) ) {
			return new WP_Error( 'mody_mollie_id', 'Not a payment ID.' );
		}
		return self::request( 'GET', '/payments/' . $id );
	}

	/**
	 * A customer's mandate: the card behind it.
	 *
	 * @param string $customer `cst_…`.
	 * @param string $mandate  `mdt_…`.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_mandate( string $customer, string $mandate ): array|WP_Error {
		if ( ! preg_match( '/^cst_[A-Za-z0-9]+$/', $customer ) || ! preg_match( '/^mdt_[A-Za-z0-9]+$/', $mandate ) ) {
			return new WP_Error( 'mody_mollie_id', 'Not a mandate.' );
		}
		return self::request( 'GET', '/customers/' . $customer . '/mandates/' . $mandate );
	}

	/**
	 * Refunds a payment.
	 *
	 * @param string $id          `tr_…`.
	 * @param float  $eur         How much.
	 * @param string $description What the bank statement says.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function refund( string $id, float $eur, string $description ): array|WP_Error {
		if ( ! preg_match( '/^tr_[A-Za-z0-9]+$/', $id ) ) {
			return new WP_Error( 'mody_mollie_id', 'Not a payment ID.' );
		}
		return self::request(
			'POST',
			'/payments/' . $id . '/refunds',
			array(
				'amount'      => self::amount( $eur ),
				'description' => $description,
			),
			'refund-' . $id
		);
	}

	/**
	 * One API request.
	 *
	 * @param string                    $method GET or POST.
	 * @param string                    $path   After the base, such as `/payments`.
	 * @param array<string, mixed>|null $body   JSON body.
	 * @param string                    $key    The idempotency key of a POST.
	 * @return array<string, mixed>|WP_Error The decoded answer; `mody_mollie` with Mollie's own words when it refused.
	 */
	private static function request( string $method, string $path, ?array $body = null, string $key = '' ): array|WP_Error {
		if ( ! self::configured() ) {
			return new WP_Error( 'mody_mollie_off', __( 'Card payments are not available yet.', 'mody_cloud' ) );
		}
		$headers = array(
			'Authorization' => 'Bearer ' . self::key(),
			'Accept'        => 'application/json',
		);
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( '' !== $key ) {
			$headers['Idempotency-Key'] = substr( $key, 0, 64 );
		}
		$response = wp_remote_request(
			self::BASE . $path,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => null !== $body ? wp_json_encode( $body ) : null,
				'timeout' => self::TIMEOUT,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'mody_mollie_down', __( 'The payment provider did not answer. Please try again in a moment.', 'mody_cloud' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			// Mollie's own words (never the key), for the log and the operator.
			$detail = is_array( $data ) ? (string) ( $data['detail'] ?? $data['title'] ?? '' ) : '';
			return new WP_Error( 'mody_mollie', $detail ? $detail : 'Mollie answered ' . $code, array( 'status' => $code ) );
		}
		return $data;
	}
}
