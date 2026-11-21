<?php
/**
 * A QR code as SVG, drawn on our server: the authenticator app's setup
 * address never leaves it (no chart service, no library).
 *
 * @package Mody\Security
 */

namespace Mody\Security;

defined( 'ABSPATH' ) || exit;

/**
 * ISO/IEC 18004 in its smallest useful form: byte mode, error correction
 * level M, versions 1 to 10 (up to 213 bytes; an `otpauth://` address is
 * about 150). The steps follow the standard's order: the bit stream, the
 * Reed-Solomon blocks interleaved, the fixed patterns, the zigzag
 * placement, the mask with the lowest penalty, the format and version bits.
 */
final class Qr {

	/**
	 * Per version (level M): error correction codewords per block, and the
	 * blocks' data lengths (shorter blocks first).
	 */
	private const BLOCKS = array(
		1  => array( 10, array( 16 ) ),
		2  => array( 16, array( 28 ) ),
		3  => array( 26, array( 44 ) ),
		4  => array( 18, array( 32, 32 ) ),
		5  => array( 24, array( 43, 43 ) ),
		6  => array( 16, array( 27, 27, 27, 27 ) ),
		7  => array( 18, array( 31, 31, 31, 31 ) ),
		8  => array( 22, array( 38, 38, 39, 39 ) ),
		9  => array( 22, array( 36, 36, 36, 37, 37 ) ),
		10 => array( 26, array( 43, 43, 43, 43, 44 ) ),
	);

	/** Alignment pattern centers per version. */
	private const ALIGNMENT = array(
		1  => array(),
		2  => array( 6, 18 ),
		3  => array( 6, 22 ),
		4  => array( 6, 26 ),
		5  => array( 6, 30 ),
		6  => array( 6, 34 ),
		7  => array( 6, 22, 38 ),
		8  => array( 6, 24, 42 ),
		9  => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
	);

	/**
	 * Dark modules, [row][column].
	 *
	 * @var bool[][]
	 */
	private array $modules = array();

	/**
	 * Modules that belong to a fixed pattern, [row][column].
	 *
	 * @var bool[][]
	 */
	private array $fixed = array();

	/** Modules per side. */
	private int $size;

	/**
	 * Builds the symbol.
	 *
	 * @param string $data    The bytes to encode.
	 * @param int    $version 1 to 10.
	 * @param int    $mask    0 to 7, or -1 for the best.
	 */
	private function __construct( string $data, private int $version, int $mask ) {
		$this->size    = $version * 4 + 17;
		$row           = array_fill( 0, $this->size, false );
		$this->modules = array_fill( 0, $this->size, $row );
		$this->fixed   = $this->modules;

		$this->draw_fixed_patterns();
		$this->draw_codewords( $this->codewords( $data ) );

		if ( $mask < 0 ) {
			$best = PHP_INT_MAX;
			for ( $candidate = 0; $candidate < 8; $candidate++ ) {
				$this->apply_mask( $candidate );
				$this->draw_format( $candidate );
				$penalty = $this->penalty();
				if ( $penalty < $best ) {
					$best = $penalty;
					$mask = $candidate;
				}
				$this->apply_mask( $candidate ); // Masking twice undoes it.
			}
		}
		$this->apply_mask( $mask );
		$this->draw_format( $mask );
	}

	/**
	 * The modules of a text's QR code, [row][column], or null when it is
	 * too long for version 10.
	 *
	 * @param string $data The text (bytes).
	 * @param int    $mask 0 to 7, or -1 for the best (tests pin one).
	 * @return bool[][]|null
	 */
	public static function matrix( string $data, int $mask = -1 ): ?array {
		foreach ( self::BLOCKS as $version => $blocks ) {
			$count_bits = $version < 10 ? 8 : 16;
			$capacity   = array_sum( $blocks[1] ) * 8;
			if ( 4 + $count_bits + strlen( $data ) * 8 <= $capacity ) {
				return ( new self( $data, $version, $mask ) )->modules;
			}
		}
		return null;
	}

	/**
	 * A text's QR code as an SVG element, black on white with the standard's
	 * quiet zone, or '' when it is too long.
	 *
	 * @param string $data  The text.
	 * @param string $label Its accessible name.
	 * @param int    $px    The width and height in CSS pixels.
	 */
	public static function svg( string $data, string $label, int $px = 200 ): string {
		$matrix = self::matrix( $data );
		if ( null === $matrix ) {
			return '';
		}
		$size = count( $matrix );
		$path = '';
		foreach ( $matrix as $y => $row ) {
			$x = 0;
			while ( $x < $size ) {
				if ( ! $row[ $x ] ) {
					++$x;
					continue;
				}
				$start = $x;
				while ( $x < $size && $row[ $x ] ) {
					++$x;
				}
				$path .= 'M' . ( $start + 4 ) . ' ' . ( $y + 4 ) . 'h' . ( $x - $start ) . 'v1h-' . ( $x - $start ) . 'z';
			}
		}
		$box = $size + 8;
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%2$d" height="%2$d" role="img" aria-label="%3$s" shape-rendering="crispEdges"><rect width="%1$d" height="%1$d" fill="#fff"/><path d="%4$s" fill="#000"/></svg>',
			$box,
			$px,
			esc_attr( $label ),
			$path
		);
	}

	/**
	 * The final codewords: data with padding, split into blocks, each with
	 * its error correction, interleaved.
	 *
	 * @param string $data The bytes.
	 * @return int[]
	 */
	private function codewords( string $data ): array {
		list( $ec_length, $lengths ) = self::BLOCKS[ $this->version ];
		$capacity                    = array_sum( $lengths );

		// Mode 0100 (bytes), the count, the bytes, a terminator, padding.
		$bits  = '0100' . str_pad( decbin( strlen( $data ) ), $this->version < 10 ? 8 : 16, '0', STR_PAD_LEFT );
		$bits .= implode( '', array_map( static fn( $c ) => str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT ), str_split( $data ) ) );
		$bits .= str_repeat( '0', min( 4, $capacity * 8 - strlen( $bits ) ) );
		$bits .= str_repeat( '0', ( 8 - strlen( $bits ) % 8 ) % 8 );
		$bytes = array_map( 'bindec', str_split( $bits, 8 ) );
		for ( $pad = 0xEC; count( $bytes ) < $capacity; $pad ^= 0xEC ^ 0x11 ) {
			$bytes[] = $pad;
		}

		$divisor = self::rs_divisor( $ec_length );
		$blocks  = array();
		$offset  = 0;
		foreach ( $lengths as $length ) {
			$block    = array_slice( $bytes, $offset, $length );
			$offset  += $length;
			$blocks[] = array( $block, self::rs_remainder( $block, $divisor ) );
		}

		$out = array();
		for ( $i = 0; $i < max( $lengths ); $i++ ) {
			foreach ( $blocks as $block ) {
				if ( $i < count( $block[0] ) ) {
					$out[] = $block[0][ $i ];
				}
			}
		}
		for ( $i = 0; $i < $ec_length; $i++ ) {
			foreach ( $blocks as $block ) {
				$out[] = $block[1][ $i ];
			}
		}
		return $out;
	}

	/**
	 * A fixed module.
	 *
	 * @param int  $x    Column.
	 * @param int  $y    Row.
	 * @param bool $dark Dark or light.
	 */
	private function set_fixed( int $x, int $y, bool $dark ): void {
		$this->modules[ $y ][ $x ] = $dark;
		$this->fixed[ $y ][ $x ]   = true;
	}

	/**
	 * Timing lines, finders with their separators, alignment patterns, and
	 * the format and version areas (reserved now, written last).
	 */
	private function draw_fixed_patterns(): void {
		$size = $this->size;
		for ( $i = 0; $i < $size; $i++ ) {
			$this->set_fixed( 6, $i, 0 === $i % 2 );
			$this->set_fixed( $i, 6, 0 === $i % 2 );
		}
		foreach ( array( array( 3, 3 ), array( $size - 4, 3 ), array( 3, $size - 4 ) ) as list( $cx, $cy ) ) {
			for ( $dy = -4; $dy <= 4; $dy++ ) {
				for ( $dx = -4; $dx <= 4; $dx++ ) {
					$x = $cx + $dx;
					$y = $cy + $dy;
					if ( $x >= 0 && $x < $size && $y >= 0 && $y < $size ) {
						$distance = max( abs( $dx ), abs( $dy ) );
						$this->set_fixed( $x, $y, 2 !== $distance && 4 !== $distance );
					}
				}
			}
		}
		$centers = self::ALIGNMENT[ $this->version ];
		$last    = count( $centers ) - 1;
		foreach ( $centers as $i => $cx ) {
			foreach ( $centers as $j => $cy ) {
				if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $last === $j ) || ( $last === $i && 0 === $j ) ) {
					continue; // Under a finder.
				}
				for ( $dy = -2; $dy <= 2; $dy++ ) {
					for ( $dx = -2; $dx <= 2; $dx++ ) {
						$this->set_fixed( $cx + $dx, $cy + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
					}
				}
			}
		}
		$this->draw_format( 0 );
		if ( $this->version >= 7 ) {
			$rem = $this->version;
			for ( $i = 0; $i < 12; $i++ ) {
				$rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
			}
			$bits = ( $this->version << 12 ) | $rem;
			for ( $i = 0; $i < 18; $i++ ) {
				$dark = 1 === ( ( $bits >> $i ) & 1 );
				$a    = $size - 11 + $i % 3;
				$b    = intdiv( $i, 3 );
				$this->set_fixed( $a, $b, $dark );
				$this->set_fixed( $b, $a, $dark );
			}
		}
	}

	/**
	 * The 15 format bits (level M, the mask), twice, and the dark module.
	 *
	 * @param int $mask 0 to 7.
	 */
	private function draw_format( int $mask ): void {
		$data = ( 0 << 3 ) | $mask; // Level M is 00.
		$rem  = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
		}
		$bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;
		$bit  = static fn( int $i ): bool => 1 === ( ( $bits >> $i ) & 1 );
		$size = $this->size;

		for ( $i = 0; $i <= 5; $i++ ) {
			$this->set_fixed( 8, $i, $bit( $i ) );
		}
		$this->set_fixed( 8, 7, $bit( 6 ) );
		$this->set_fixed( 8, 8, $bit( 7 ) );
		$this->set_fixed( 7, 8, $bit( 8 ) );
		for ( $i = 9; $i < 15; $i++ ) {
			$this->set_fixed( 14 - $i, 8, $bit( $i ) );
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$this->set_fixed( $size - 1 - $i, 8, $bit( $i ) );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$this->set_fixed( 8, $size - 15 + $i, $bit( $i ) );
		}
		$this->set_fixed( 8, $size - 8, true );
	}

	/**
	 * The codewords' bits in the zigzag: two columns at a time from the
	 * right, up then down, around the fixed patterns.
	 *
	 * @param int[] $codewords The codewords.
	 */
	private function draw_codewords( array $codewords ): void {
		$total = count( $codewords ) * 8;
		$i     = 0;
		for ( $right = $this->size - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5; // The vertical timing line.
			}
			$upward = 0 === ( ( $right + 1 ) & 2 );
			for ( $vert = 0; $vert < $this->size; $vert++ ) {
				$y = $upward ? $this->size - 1 - $vert : $vert;
				for ( $j = 0; $j < 2; $j++ ) {
					$x = $right - $j;
					if ( ! $this->fixed[ $y ][ $x ] && $i < $total ) {
						$this->modules[ $y ][ $x ] = 1 === ( ( $codewords[ $i >> 3 ] >> ( 7 - ( $i & 7 ) ) ) & 1 );
						++$i;
					}
				}
			}
		}
	}

	/**
	 * Flips the data modules a mask selects.
	 *
	 * @param int $mask 0 to 7.
	 */
	private function apply_mask( int $mask ): void {
		for ( $y = 0; $y < $this->size; $y++ ) {
			for ( $x = 0; $x < $this->size; $x++ ) {
				if ( $this->fixed[ $y ][ $x ] ) {
					continue;
				}
				switch ( $mask ) {
					case 0:
						$flip = 0 === ( $x + $y ) % 2;
						break;
					case 1:
						$flip = 0 === $y % 2;
						break;
					case 2:
						$flip = 0 === $x % 3;
						break;
					case 3:
						$flip = 0 === ( $x + $y ) % 3;
						break;
					case 4:
						$flip = 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2;
						break;
					case 5:
						$flip = 0 === ( $x * $y ) % 2 + ( $x * $y ) % 3;
						break;
					case 6:
						$flip = 0 === ( ( $x * $y ) % 2 + ( $x * $y ) % 3 ) % 2;
						break;
					default:
						$flip = 0 === ( ( $x + $y ) % 2 + ( $x * $y ) % 3 ) % 2;
				}
				if ( $flip ) {
					$this->modules[ $y ][ $x ] = ! $this->modules[ $y ][ $x ];
				}
			}
		}
	}

	/**
	 * The standard's four penalties: runs, 2×2 blocks, finder look-alikes,
	 * and the dark share away from half.
	 */
	private function penalty(): int {
		$size    = $this->size;
		$penalty = 0;
		$dark    = 0;
		$lines   = array();
		for ( $i = 0; $i < $size; $i++ ) {
			$row    = '';
			$column = '';
			for ( $j = 0; $j < $size; $j++ ) {
				$row    .= $this->modules[ $i ][ $j ] ? '1' : '0';
				$column .= $this->modules[ $j ][ $i ] ? '1' : '0';
			}
			$lines[] = $row;
			$lines[] = $column;
			$dark   += substr_count( $row, '1' );
		}
		foreach ( $lines as $line ) {
			if ( preg_match_all( '/0{5,}|1{5,}/', $line, $runs ) ) {
				foreach ( $runs[0] as $run ) {
					$penalty += 3 + strlen( $run ) - 5;
				}
			}
			$penalty += 40 * preg_match_all( '/(?=10111010000|00001011101)/', $line );
		}
		for ( $y = 0; $y < $size - 1; $y++ ) {
			for ( $x = 0; $x < $size - 1; $x++ ) {
				$c = $this->modules[ $y ][ $x ];
				if ( $c === $this->modules[ $y ][ $x + 1 ] && $c === $this->modules[ $y + 1 ][ $x ] && $c === $this->modules[ $y + 1 ][ $x + 1 ] ) {
					$penalty += 3;
				}
			}
		}
		$percent  = $dark * 100 / ( $size * $size );
		$penalty += 10 * intdiv( (int) abs( $percent - 50 ), 5 );
		return $penalty;
	}

	/**
	 * GF(256) multiplication, modulo x^8 + x^4 + x^3 + x^2 + 1.
	 *
	 * @param int $x A byte.
	 * @param int $y A byte.
	 */
	private static function gf_multiply( int $x, int $y ): int {
		$z = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D );
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}
		return $z;
	}

	/**
	 * The Reed-Solomon generator polynomial of a degree, without its leading
	 * term, highest power first.
	 *
	 * @param int $degree The number of error correction codewords.
	 * @return int[]
	 */
	private static function rs_divisor( int $degree ): array {
		$result                = array_fill( 0, $degree, 0 );
		$result[ $degree - 1 ] = 1;
		$root                  = 1;
		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$result[ $j ] = self::gf_multiply( $result[ $j ], $root );
				if ( $j + 1 < $degree ) {
					$result[ $j ] ^= $result[ $j + 1 ];
				}
			}
			$root = self::gf_multiply( $root, 0x02 );
		}
		return $result;
	}

	/**
	 * A block's error correction codewords.
	 *
	 * @param int[] $data    The block's data codewords.
	 * @param int[] $divisor From rs_divisor().
	 * @return int[]
	 */
	private static function rs_remainder( array $data, array $divisor ): array {
		$result = array_fill( 0, count( $divisor ), 0 );
		foreach ( $data as $byte ) {
			$factor   = $byte ^ array_shift( $result );
			$result[] = 0;
			foreach ( $divisor as $i => $coefficient ) {
				$result[ $i ] ^= self::gf_multiply( $coefficient, $factor );
			}
		}
		return $result;
	}
}
