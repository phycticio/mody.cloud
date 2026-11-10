<?php
/**
 * ALTCHA: a proof-of-work check that a person, not a script, sends a form
 * (tickets/antibot-altcha.md). Self-hosted: no outside service is called
 * (rule 1), no cookie is set, nothing about the visitor is stored.
 *
 * How it works (ALTCHA v3's protocol, `altcha` 3.5.0's `createChallenge()`
 * and `verifySolution()`):
 * 1. The widget fetches a challenge from GET `mody-platform/v1/altcha`. The
 *    server picks a secret counter, derives a key from it
 *    (PBKDF2/SHA-256 over nonce + counter, with the salt) and sends the
 *    key's first half (`keyPrefix`), with an HMAC of the whole key
 *    (`keySignature`) and an HMAC of all the parameters (`signature`).
 * 2. The browser tries counters from 0 until a derived key starts with the
 *    prefix: that is the work. It sends the challenge back with the key it
 *    found, base64-encoded JSON, in the form field `altcha`.
 * 3. verify() checks the signature, the expiry, and the key against
 *    `keySignature`: one HMAC, no key derivation on the server. Each
 *    challenge is accepted once.
 *
 * The two HMAC secrets come from WordPress's own salts, so there is nothing
 * to configure and they are the same on every site of the network.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Security;

use Mody\Platform\Agent\ChatController;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and checks ALTCHA challenges, and prints the widget.
 */
final class Altcha {

	/** The key derivation the browser repeats. */
	public const ALGORITHM = 'PBKDF2/SHA-256';

	/** PBKDF2 iterations per attempt. */
	public const COST = 1000;

	/** The secret counter's range: the browser tries about MAX / 2 counters on average. */
	public const MIN_COUNTER = 1000;
	public const MAX_COUNTER = 6000;

	/** How long a challenge can be solved and used. */
	public const LIFETIME = 30 * MINUTE_IN_SECONDS;

	/** The form field (and REST parameter) that carries the solution. */
	public const FIELD = 'altcha';

	/** The widget's script handle: app/dist/altcha.js, built from src/altcha.js. */
	public const SCRIPT = 'mody-altcha';

	/** The cache group of used challenges. */
	private const USED = 'mody_altcha_used';

	/**
	 * `init`: the widget's script.
	 */
	public static function register_script(): void {
		$asset = WP_CONTENT_DIR . '/../dist/altcha.asset.php';
		$info  = is_readable( $asset ) ? (array) require $asset : array();
		wp_register_script(
			self::SCRIPT,
			WP_HOME . '/dist/altcha.js',
			array(),
			(string) ( $info['version'] ?? '3.5.0' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_cache_add_global_groups( array( self::USED ) );
	}

	/**
	 * `rest_api_init`: GET `mody-platform/v1/altcha`, a new challenge, on
	 * every site.
	 */
	public static function register_route(): void {
		register_rest_route(
			ChatController::REST_NAMESPACE,
			'/altcha',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'rest_challenge' ),
				'permission_callback' => '__return_true', // For visitors: it hands out work, nothing else.
			)
		);
	}

	/**
	 * GET `altcha`: a challenge, never cached.
	 */
	public static function rest_challenge(): WP_REST_Response {
		$response = new WP_REST_Response( self::challenge() );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}

	/**
	 * A new signed challenge.
	 *
	 * @param int|null $counter The secret counter; random when null (tests pass a small one).
	 * @return array{parameters: array<string, int|string>, signature: string}
	 */
	public static function challenge( ?int $counter = null ): array {
		$counter    = $counter ?? random_int( self::MIN_COUNTER, self::MAX_COUNTER );
		$nonce      = random_bytes( 16 );
		$salt       = random_bytes( 16 );
		$key        = self::derive( $nonce, $salt, $counter, self::COST );
		$parameters = array(
			'algorithm'    => self::ALGORITHM,
			'cost'         => self::COST,
			'expiresAt'    => time() + self::LIFETIME,
			'keyLength'    => 32,
			'keyPrefix'    => bin2hex( substr( $key, 0, 16 ) ),
			'keySignature' => hash_hmac( 'sha256', $key, self::secret( 'key' ) ),
			'nonce'        => bin2hex( $nonce ),
			'salt'         => bin2hex( $salt ),
		);
		ksort( $parameters );
		return array(
			'parameters' => $parameters,
			'signature'  => hash_hmac( 'sha256', self::json( $parameters ), self::secret( 'signature' ) ),
		);
	}

	/**
	 * Whether a solution is valid, unexpired and not used before. A valid
	 * one is used up: the same payload fails the second time.
	 *
	 * @param mixed $payload The `altcha` field: base64-encoded JSON.
	 */
	public static function verify( mixed $payload ): bool {
		if ( ! is_string( $payload ) || '' === $payload || strlen( $payload ) > 4096 ) {
			return false;
		}
		$decoded = base64_decode( $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The widget's payload format.
		$data    = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
		$params  = $data['challenge']['parameters'] ?? null;
		$sign    = $data['challenge']['signature'] ?? null;
		$key     = $data['solution']['derivedKey'] ?? null;
		if ( ! is_array( $params ) || ! is_string( $sign ) || ! is_string( $key ) || ! preg_match( '/^(?:[0-9a-f]{2})+$/', $key ) ) {
			return false;
		}
		self::sort( $params );
		if ( ! hash_equals( hash_hmac( 'sha256', self::json( $params ), self::secret( 'signature' ) ), $sign ) ) {
			return false; // Not ours, or changed.
		}
		if ( ! is_int( $params['expiresAt'] ?? null ) || $params['expiresAt'] < time() || ! is_string( $params['keySignature'] ?? null ) ) {
			return false;
		}
		if ( ! hash_equals( $params['keySignature'], hash_hmac( 'sha256', (string) hex2bin( $key ), self::secret( 'key' ) ) ) ) {
			return false; // The work was not done.
		}
		return self::use_up( $sign, $params['expiresAt'] );
	}

	/**
	 * The `altcha` field of the current POST request.
	 */
	public static function posted(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked by verify(), never output or stored.
		return isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : '';
	}

	/**
	 * The widget, for a form: it fetches a challenge when the visitor starts
	 * filling the form in, and puts the solution in a hidden `altcha` field.
	 * Enqueues its script.
	 *
	 * @param string $auto When it starts: 'onfocus' (a field gets focus) or 'off' (a script calls verify()).
	 */
	public static function widget( string $auto = 'onfocus' ): string {
		wp_enqueue_script( self::SCRIPT );
		return sprintf(
			'<altcha-widget class="mody-altcha" name="%s" challenge="%s" auto="%s" configuration="%s"></altcha-widget>',
			esc_attr( self::FIELD ),
			esc_url( rest_url( ChatController::REST_NAMESPACE . '/altcha' ) ),
			esc_attr( $auto ),
			esc_attr(
				(string) wp_json_encode(
					array(
						'hideLogo'   => true,
						'hideFooter' => true,
					)
				)
			)
		);
	}

	/**
	 * The message when the check fails.
	 */
	public static function failed_message(): string {
		return __( 'We could not confirm that a person sent this. Please try again.', 'mody_cloud' );
	}

	/**
	 * Solves a challenge the way the widget does (tests only: create the
	 * challenge with a small counter).
	 *
	 * @param array{parameters: array<string, int|string>, signature: string} $challenge A challenge.
	 * @param int                                                              $limit     The last counter to try.
	 * @return string The payload, or '' when no counter up to $limit matches.
	 */
	public static function solve( array $challenge, int $limit = 100000 ): string {
		$params = $challenge['parameters'];
		$nonce  = (string) hex2bin( (string) $params['nonce'] );
		$salt   = (string) hex2bin( (string) $params['salt'] );
		for ( $counter = 0; $counter <= $limit; $counter++ ) {
			$key = self::derive( $nonce, $salt, $counter, (int) $params['cost'] );
			if ( str_starts_with( bin2hex( $key ), (string) $params['keyPrefix'] ) ) {
				return base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The widget's payload format.
					(string) wp_json_encode(
						array(
							'challenge' => $challenge,
							'solution'  => array(
								'counter'    => $counter,
								'derivedKey' => bin2hex( $key ),
							),
						),
						JSON_UNESCAPED_SLASHES
					)
				);
			}
		}
		return '';
	}

	/**
	 * PBKDF2/SHA-256 over nonce + counter (big-endian uint32, the widget's
	 * `uint32` counter mode), 32 bytes.
	 *
	 * @param string $nonce   Raw nonce.
	 * @param string $salt    Raw salt.
	 * @param int    $counter The counter.
	 * @param int    $cost    Iterations.
	 */
	private static function derive( string $nonce, string $salt, int $counter, int $cost ): string {
		return hash_pbkdf2( 'sha256', $nonce . pack( 'N', $counter ), $salt, $cost, 32, true );
	}

	/**
	 * Marks a challenge used; false when it already was. Atomic with a
	 * persistent object cache (Redis), a site transient otherwise.
	 *
	 * @param string $signature  The challenge's signature.
	 * @param int    $expires_at When it expires.
	 */
	private static function use_up( string $signature, int $expires_at ): bool {
		$id  = substr( $signature, 0, 40 );
		$ttl = max( 60, $expires_at - time() + 60 );
		if ( wp_using_ext_object_cache() ) {
			return wp_cache_add( $id, 1, self::USED, $ttl );
		}
		if ( get_site_transient( 'mody_altcha_' . $id ) ) {
			return false;
		}
		set_site_transient( 'mody_altcha_' . $id, 1, $ttl );
		return true;
	}

	/**
	 * A secret for one purpose, from WordPress's salts.
	 *
	 * @param string $purpose 'signature' or 'key'.
	 */
	private static function secret( string $purpose ): string {
		return hash_hmac( 'sha256', 'mody-altcha-' . $purpose, wp_salt( 'nonce' ) );
	}

	/**
	 * JSON as the widget's JavaScript writes it (JSON.stringify): no escaped
	 * slashes or Unicode.
	 *
	 * @param array<string, mixed> $data Data.
	 */
	private static function json( array $data ): string {
		return (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Sorts keys recursively, like the widget's canonicalJSON().
	 *
	 * @param array<string, mixed> $data Data, sorted in place.
	 */
	private static function sort( array &$data ): void {
		ksort( $data, SORT_STRING );
		foreach ( $data as &$value ) {
			if ( is_array( $value ) && ! array_is_list( $value ) ) {
				self::sort( $value );
			}
		}
	}
}
