<?php
/**
 * A small CBOR reader (RFC 8949) for WebAuthn's attestation objects and
 * COSE keys.
 *
 * @package Mody\Security
 */

namespace Mody\Security;

use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

/**
 * Reads what authenticators send: unsigned and negative integers, byte and
 * text strings, arrays, maps, tags (their value kept), true, false, null and
 * floats. Definite lengths only (CTAP2's canonical CBOR has no others).
 * Byte strings come back as PHP strings; so do text strings.
 */
final class Cbor {

	/** Nesting deeper than this is refused. */
	private const MAX_DEPTH = 16;

	/**
	 * Decodes one item at the start of the bytes.
	 *
	 * @param string $bytes  The CBOR.
	 * @param int    $offset Where the item starts; moved past it.
	 * @return mixed
	 * @throws UnexpectedValueException On malformed or unsupported input.
	 */
	public static function decode( string $bytes, int &$offset = 0 ) {
		return self::item( $bytes, $offset, 0 );
	}

	/**
	 * Decodes one item.
	 *
	 * @param string $bytes  The CBOR.
	 * @param int    $offset The position; moved past the item.
	 * @param int    $depth  How deep we are.
	 * @return mixed
	 * @throws UnexpectedValueException On malformed or unsupported input.
	 */
	private static function item( string $bytes, int &$offset, int $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			throw new UnexpectedValueException( 'CBOR nested too deeply.' );
		}
		$initial = ord( self::take( $bytes, $offset, 1 ) );
		$major   = $initial >> 5;
		$info    = $initial & 0x1F;

		if ( 7 === $major ) {
			switch ( $info ) {
				case 20:
					return false;
				case 21:
					return true;
				case 22:
				case 23:
					return null;
				case 25:
					return self::half( self::take( $bytes, $offset, 2 ) );
				case 26:
					return unpack( 'G', self::take( $bytes, $offset, 4 ) )[1];
				case 27:
					return unpack( 'E', self::take( $bytes, $offset, 8 ) )[1];
			}
			throw new UnexpectedValueException( 'Unsupported CBOR simple value.' );
		}

		$length = self::argument( $bytes, $offset, $info );
		switch ( $major ) {
			case 0:
				return $length;
			case 1:
				return -1 - $length;
			case 2:
			case 3:
				return self::take( $bytes, $offset, $length );
			case 4:
				$list = array();
				for ( $i = 0; $i < $length; $i++ ) {
					$list[] = self::item( $bytes, $offset, $depth + 1 );
				}
				return $list;
			case 5:
				$map = array();
				for ( $i = 0; $i < $length; $i++ ) {
					$key = self::item( $bytes, $offset, $depth + 1 );
					if ( ! is_int( $key ) && ! is_string( $key ) ) {
						throw new UnexpectedValueException( 'Unsupported CBOR map key.' );
					}
					$map[ $key ] = self::item( $bytes, $offset, $depth + 1 );
				}
				return $map;
			default: // 6: a tag; its value is what matters here.
				return self::item( $bytes, $offset, $depth + 1 );
		}
	}

	/**
	 * An item's argument: the length or value after its first byte.
	 *
	 * @param string $bytes  The CBOR.
	 * @param int    $offset The position.
	 * @param int    $info   The first byte's low five bits.
	 * @throws UnexpectedValueException On indefinite or reserved lengths.
	 */
	private static function argument( string $bytes, int &$offset, int $info ): int {
		if ( $info < 24 ) {
			return $info;
		}
		$sizes = array(
			24 => 1,
			25 => 2,
			26 => 4,
			27 => 8,
		);
		if ( ! isset( $sizes[ $info ] ) ) {
			throw new UnexpectedValueException( 'Unsupported CBOR length.' );
		}
		$value = 0;
		foreach ( str_split( self::take( $bytes, $offset, $sizes[ $info ] ) ) as $byte ) {
			$value = ( $value << 8 ) | ord( $byte );
		}
		if ( $value < 0 ) {
			throw new UnexpectedValueException( 'CBOR value too large.' );
		}
		return $value;
	}

	/**
	 * The next bytes.
	 *
	 * @param string $bytes  The CBOR.
	 * @param int    $offset The position; moved past them.
	 * @param int    $length How many.
	 * @throws UnexpectedValueException When the input ends first.
	 */
	private static function take( string $bytes, int &$offset, int $length ): string {
		if ( $length < 0 || $offset + $length > strlen( $bytes ) ) {
			throw new UnexpectedValueException( 'CBOR ends early.' );
		}
		$part    = substr( $bytes, $offset, $length );
		$offset += $length;
		return $part;
	}

	/**
	 * A half-precision float.
	 *
	 * @param string $bytes Two bytes.
	 */
	private static function half( string $bytes ): float {
		$half     = ( ord( $bytes[0] ) << 8 ) | ord( $bytes[1] );
		$exponent = ( $half >> 10 ) & 0x1F;
		$mantissa = $half & 0x3FF;
		if ( 0 === $exponent ) {
			$value = $mantissa * 2 ** -24;
		} elseif ( 31 === $exponent ) {
			$value = $mantissa ? NAN : INF;
		} else {
			$value = ( $mantissa + 1024 ) * 2 ** ( $exponent - 25 );
		}
		return ( $half & 0x8000 ) ? -$value : $value;
	}
}
