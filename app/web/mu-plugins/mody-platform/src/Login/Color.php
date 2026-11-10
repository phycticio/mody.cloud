<?php
/**
 * Color math for the login page's schemes: parsing, mixing, contrast.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Login;

defined( 'ABSPATH' ) || exit;

/**
 * Six-digit hex colors in, six-digit hex colors out. Contrast follows WCAG 2.
 */
final class Color {

	/** Minimum contrast for text (WCAG AA, normal text). */
	public const TEXT = 4.5;

	/** Minimum contrast for controls, icons and focus rings (WCAG 1.4.11). */
	public const UI = 3.0;

	/**
	 * A palette value as six-digit lowercase hex, or null. Accepts #rgb,
	 * #rrggbb and #rrggbbaa (the alpha is dropped): what the Site Editor's
	 * color picker saves. Anything else (a variable, a function) is refused.
	 *
	 * @param mixed $value The palette value.
	 */
	public static function hex( $value ): ?string {
		if ( ! is_string( $value ) || ! preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', trim( $value ), $m ) ) {
			return null;
		}
		$hex = strtolower( $m[1] );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return '#' . substr( $hex, 0, 6 );
	}

	/**
	 * The color's red, green and blue, 0 to 255.
	 *
	 * @param string $hex Six-digit hex.
	 * @return array{0: int, 1: int, 2: int}
	 */
	public static function rgb( string $hex ): array {
		return array_map( 'hexdec', str_split( substr( $hex, 1, 6 ), 2 ) );
	}

	/**
	 * Six-digit hex from red, green and blue.
	 *
	 * @param array<int, float|int> $rgb Red, green and blue, 0 to 255.
	 */
	private static function to_hex( array $rgb ): string {
		return '#' . implode(
			'',
			array_map(
				static fn( $v ): string => str_pad( dechex( (int) round( max( 0, min( 255, $v ) ) ) ), 2, '0', STR_PAD_LEFT ),
				$rgb
			)
		);
	}

	/**
	 * Two colors mixed: `$amount` of the second.
	 *
	 * @param string $from   Six-digit hex.
	 * @param string $to     Six-digit hex.
	 * @param float  $amount 0 to 1.
	 */
	public static function mix( string $from, string $to, float $amount ): string {
		$a = self::rgb( $from );
		$b = self::rgb( $to );
		return self::to_hex(
			array(
				$a[0] + ( $b[0] - $a[0] ) * $amount,
				$a[1] + ( $b[1] - $a[1] ) * $amount,
				$a[2] + ( $b[2] - $a[2] ) * $amount,
			)
		);
	}

	/**
	 * The color with its HSL lightness lowered, as core's admin schemes do for
	 * `--wp-admin-theme-color-darker-10` (5 points) and `-20` (10 points).
	 * A negative amount lightens.
	 *
	 * @param string $hex    Six-digit hex.
	 * @param float  $amount -1 to 1 (0.05 is 5 points).
	 */
	public static function darken( string $hex, float $amount ): string {
		list( $r, $g, $b ) = array_map( static fn( $v ): float => $v / 255, self::rgb( $hex ) );
		$max               = max( $r, $g, $b );
		$min               = min( $r, $g, $b );
		$l                 = ( $max + $min ) / 2;
		$h                 = 0.0;
		$s                 = 0.0;
		if ( $max !== $min ) {
			$d = $max - $min;
			$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
			if ( $max === $r ) {
				$h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
			} elseif ( $max === $g ) {
				$h = ( $b - $r ) / $d + 2;
			} else {
				$h = ( $r - $g ) / $d + 4;
			}
			$h /= 6;
		}
		$l = max( 0.0, min( 1.0, $l - $amount ) );

		if ( 0.0 === $s ) {
			return self::to_hex( array( $l * 255, $l * 255, $l * 255 ) );
		}
		$q   = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
		$p   = 2 * $l - $q;
		$hue = static function ( float $t ) use ( $p, $q ): float {
			$t += $t < 0 ? 1 : 0;
			$t -= $t > 1 ? 1 : 0;
			if ( $t < 1 / 6 ) {
				return $p + ( $q - $p ) * 6 * $t;
			}
			if ( $t < 1 / 2 ) {
				return $q;
			}
			if ( $t < 2 / 3 ) {
				return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
			}
			return $p;
		};
		return self::to_hex( array( $hue( $h + 1 / 3 ) * 255, $hue( $h ) * 255, $hue( $h - 1 / 3 ) * 255 ) );
	}

	/**
	 * The color's relative luminance (WCAG 2), 0 to 1.
	 *
	 * @param string $hex Six-digit hex.
	 */
	public static function luminance( string $hex ): float {
		list( $r, $g, $b ) = array_map(
			static function ( $v ): float {
				$v /= 255;
				return $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
			},
			self::rgb( $hex )
		);
		return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
	}

	/**
	 * Whether the color is light: dark text reads better on it than light text.
	 *
	 * @param string $hex Six-digit hex.
	 */
	public static function is_light( string $hex ): bool {
		return self::contrast( $hex, '#000000' ) >= self::contrast( $hex, '#ffffff' );
	}

	/**
	 * The contrast ratio of two colors (WCAG 2), 1 to 21.
	 *
	 * @param string $a Six-digit hex.
	 * @param string $b Six-digit hex.
	 */
	public static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * The lowest contrast of a color against several backgrounds.
	 *
	 * @param string             $color       Six-digit hex.
	 * @param array<int, string> $backgrounds Six-digit hex colors.
	 */
	public static function worst( string $color, array $backgrounds ): float {
		return min( array_map( static fn( string $bg ): float => self::contrast( $color, $bg ), $backgrounds ) );
	}

	/**
	 * The color if it shows (3:1) against every background; otherwise the
	 * same hue made darker on a light page, or lighter on a dark one, until
	 * it does. Null when no shade does.
	 *
	 * @param string             $color       Six-digit hex.
	 * @param array<int, string> $backgrounds Where the color is used; the first decides the direction.
	 */
	public static function visible( string $color, array $backgrounds ): ?string {
		$step = self::is_light( $backgrounds[0] ) ? 0.05 : -0.05;
		for ( $i = 0; $i <= 20; $i++ ) {
			$candidate = self::darken( $color, $step * $i );
			if ( self::worst( $candidate, $backgrounds ) >= self::UI ) {
				return $candidate;
			}
		}
		return null;
	}
}
