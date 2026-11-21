<?php
/**
 * Codes from an authenticator app: TOTP (RFC 6238) over HOTP (RFC 4226).
 *
 * @package Mody\Security
 */

namespace Mody\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Six digits, a new code every 30 seconds, HMAC-SHA1: what every
 * authenticator app reads from an `otpauth://` QR code. A code is accepted
 * one step early or late (a phone's clock a little off), and each step only
 * once (the caller keeps the last one used).
 */
final class Totp {

	/** Seconds a code lasts. */
	public const PERIOD = 30;

	/** Digits in a code. */
	public const DIGITS = 6;

	/** Steps accepted before and after now. */
	public const DRIFT = 1;

	/** RFC 4648's base32 alphabet. */
	private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/**
	 * A new secret: 20 random bytes (160 bits, RFC 4226's recommendation),
	 * in base32.
	 */
	public static function new_secret(): string {
		return self::base32_encode( random_bytes( 20 ) );
	}

	/**
	 * The step a moment falls in.
	 *
	 * @param int|null $time A Unix time; now by default.
	 */
	public static function step( ?int $time = null ): int {
		return intdiv( $time ?? time(), self::PERIOD );
	}

	/**
	 * The code for one step.
	 *
	 * @param string $secret The base32 secret.
	 * @param int    $step   The step.
	 * @param int    $digits Digits (RFC 6238's test vectors use 8).
	 * @param string $algo   'sha1' (apps), or 'sha256' and 'sha512' (the RFC's vectors).
	 */
	public static function code( string $secret, int $step, int $digits = self::DIGITS, string $algo = 'sha1' ): string {
		return self::hotp( self::base32_decode( $secret ), $step, $digits, $algo );
	}

	/**
	 * HOTP with a raw key.
	 *
	 * @param string $key    The key, raw bytes.
	 * @param int    $count  The counter.
	 * @param int    $digits Digits.
	 * @param string $algo   The hash.
	 */
	public static function hotp( string $key, int $count, int $digits = self::DIGITS, string $algo = 'sha1' ): string {
		$hash   = hash_hmac( $algo, pack( 'J', $count ), $key, true );
		$offset = ord( $hash[ strlen( $hash ) - 1 ] ) & 0x0F;
		$value  = ( ( ord( $hash[ $offset ] ) & 0x7F ) << 24 )
			| ( ord( $hash[ $offset + 1 ] ) << 16 )
			| ( ord( $hash[ $offset + 2 ] ) << 8 )
			| ord( $hash[ $offset + 3 ] );
		return str_pad( (string) ( $value % ( 10 ** $digits ) ), $digits, '0', STR_PAD_LEFT );
	}

	/**
	 * Checks a typed code.
	 *
	 * @param string   $secret    The base32 secret.
	 * @param string   $code      What was typed (spaces allowed).
	 * @param int      $last_step The last step accepted for this secret: it and earlier ones are refused.
	 * @param int|null $time      A Unix time; now by default.
	 * @return int|null The step it matched, or null.
	 */
	public static function verify( string $secret, string $code, int $last_step = 0, ?int $time = null ): ?int {
		$code = preg_replace( '/\s+/', '', $code );
		if ( ! is_string( $code ) || ! preg_match( '/^\d{' . self::DIGITS . '}$/', $code ) || '' === $secret ) {
			return null;
		}
		$now = self::step( $time );
		for ( $step = $now - self::DRIFT; $step <= $now + self::DRIFT; $step++ ) {
			if ( $step > $last_step && hash_equals( self::code( $secret, $step ), $code ) ) {
				return $step;
			}
		}
		return null;
	}

	/**
	 * The address an app reads from the QR code (Google's Key Uri Format,
	 * which every app follows).
	 *
	 * @param string $secret  The base32 secret.
	 * @param string $account The account's name in the app (its email).
	 * @param string $issuer  Who it is for (the network's name).
	 */
	public static function uri( string $secret, string $account, string $issuer ): string {
		$label = rawurlencode( $issuer ) . ':' . rawurlencode( $account );
		return 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode( $issuer ) . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
	}

	/**
	 * The secret in groups of four, as people type it.
	 *
	 * @param string $secret The base32 secret.
	 */
	public static function grouped( string $secret ): string {
		return trim( chunk_split( $secret, 4, ' ' ) );
	}

	/**
	 * Base32, no padding.
	 *
	 * @param string $bytes Raw bytes.
	 */
	public static function base32_encode( string $bytes ): string {
		$bits = '';
		foreach ( str_split( $bytes ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= self::ALPHABET[ bindec( str_pad( $chunk, 5, '0' ) ) ];
		}
		return '' === $bytes ? '' : $out;
	}

	/**
	 * Base32 to bytes; spaces, dashes, padding and case ignored, other
	 * characters make it ''.
	 *
	 * @param string $text Base32.
	 */
	public static function base32_decode( string $text ): string {
		$text = strtoupper( (string) preg_replace( '/[\s=-]+/', '', $text ) );
		if ( '' === $text || strspn( $text, self::ALPHABET ) !== strlen( $text ) ) {
			return '';
		}
		$bits = '';
		foreach ( str_split( $text ) as $char ) {
			$bits .= str_pad( decbin( strpos( self::ALPHABET, $char ) ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $chunk ) {
			if ( 8 === strlen( $chunk ) ) {
				$out .= chr( bindec( $chunk ) );
			}
		}
		return $out;
	}
}
