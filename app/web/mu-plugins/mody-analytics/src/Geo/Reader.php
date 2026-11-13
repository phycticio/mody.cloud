<?php
/**
 * A small reader for MMDB files: the address-to-place database DB-IP
 * publishes (tickets/visitor-location.md).
 *
 * @package Mody\Analytics
 */

namespace Mody\Analytics\Geo;

defined( 'ABSPATH' ) || exit;

/**
 * MMDB is a documented binary format (MaxMind DB File Format Specification
 * 2.0): a binary search tree over an address's bits, then a data section of
 * typed values, then the metadata after the marker "\xAB\xCD\xEFMaxMind.com"
 * near the end of the file. The file is read with seeks, never loaded whole
 * (DB-IP City Lite is about 130 MB). Nothing here touches the network.
 *
 * Record sizes 24, 28 and 32 bits; IPv4 and IPv6 trees (IPv4 addresses in an
 * IPv6 tree are looked up under ::/96, as the format says). Types: pointer,
 * string, double, bytes, uint16/32/64/128, map, int32, array, boolean,
 * float; the data cache container and end marker are skipped.
 */
final class Reader {

	/** The marker before the metadata. */
	private const MARKER = "\xAB\xCD\xEFMaxMind.com";

	/** How far from the end the metadata can start. */
	private const METADATA_MAX = 131072;

	/** The open file. @var resource */
	private $file;

	/** The tree's nodes. */
	private int $nodes;

	/** Bits per record. */
	private int $record_size;

	/** Bytes per node. */
	private int $node_bytes;

	/** 4 or 6. */
	private int $ip_version;

	/** Where the data section starts. */
	private int $data_start;

	/** The node where IPv4 addresses start, in an IPv6 tree. */
	private ?int $ipv4_start = null;

	/** The file's metadata. @var array<string, mixed> */
	private array $metadata;

	/**
	 * Opens a file.
	 *
	 * @param string $path The MMDB file.
	 * @throws \RuntimeException When it cannot be read as MMDB.
	 */
	public function __construct( string $path ) {
		$file = is_readable( $path ) ? fopen( $path, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions -- A local binary file read with seeks.
		if ( false === $file ) {
			throw new \RuntimeException( 'The location database cannot be read.' );
		}
		$this->file = $file;
		$size       = (int) fstat( $file )['size'];
		$tail_size  = min( $size, self::METADATA_MAX );
		fseek( $file, $size - $tail_size );
		$tail = (string) fread( $file, $tail_size ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$at   = strrpos( $tail, self::MARKER );
		if ( false === $at ) {
			throw new \RuntimeException( 'Not an MMDB file: no metadata marker.' );
		}
		$offset         = 0;
		$this->metadata = (array) self::decode_buffer( substr( $tail, $at + strlen( self::MARKER ) ), $offset );

		$this->nodes       = (int) ( $this->metadata['node_count'] ?? 0 );
		$this->record_size = (int) ( $this->metadata['record_size'] ?? 0 );
		$this->ip_version  = (int) ( $this->metadata['ip_version'] ?? 0 );
		if ( $this->nodes <= 0 || ! in_array( $this->record_size, array( 24, 28, 32 ), true ) || ! in_array( $this->ip_version, array( 4, 6 ), true ) ) {
			throw new \RuntimeException( 'Unsupported MMDB metadata.' );
		}
		$this->node_bytes = intdiv( $this->record_size * 2, 8 );
		$this->data_start = $this->nodes * $this->node_bytes + 16; // The tree, then 16 bytes of zeros.
	}

	/**
	 * Closes the file.
	 */
	public function __destruct() {
		if ( is_resource( $this->file ) ) {
			fclose( $this->file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * The file's metadata (database_type, build_epoch, description…).
	 *
	 * @return array<string, mixed>
	 */
	public function metadata(): array {
		return $this->metadata;
	}

	/**
	 * The record for an address, or null when the file has none.
	 *
	 * @param string $ip An IPv4 or IPv6 address.
	 * @return mixed The decoded record (usually an array), or null.
	 */
	public function get( string $ip ) {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- Bad input is null.
		if ( false === $packed ) {
			return null;
		}
		$bits = strlen( $packed ) * 8;
		if ( 16 === strlen( $packed ) && 4 === $this->ip_version ) {
			return null; // An IPv6 address in an IPv4 database.
		}
		$node = ( 4 === strlen( $packed ) && 6 === $this->ip_version ) ? $this->ipv4_start() : 0;
		for ( $i = 0; $i < $bits && $node < $this->nodes; $i++ ) {
			$bit  = ( ord( $packed[ $i >> 3 ] ) >> ( 7 - ( $i & 7 ) ) ) & 1;
			$node = $this->record( $node, $bit );
		}
		if ( $node === $this->nodes || $node < $this->nodes ) {
			return null; // Not found (or the address ran out before a leaf).
		}
		$offset = $this->data_start + ( $node - $this->nodes - 16 );
		return $this->decode_at( $offset );
	}

	/**
	 * The node where IPv4 addresses start in an IPv6 tree: 96 left turns.
	 */
	private function ipv4_start(): int {
		if ( null === $this->ipv4_start ) {
			$node = 0;
			for ( $i = 0; $i < 96 && $node < $this->nodes; $i++ ) {
				$node = $this->record( $node, 0 );
			}
			$this->ipv4_start = $node;
		}
		return $this->ipv4_start;
	}

	/**
	 * One of a node's two records.
	 *
	 * @param int $node  The node.
	 * @param int $right 0 for the left record, 1 for the right.
	 */
	private function record( int $node, int $right ): int {
		fseek( $this->file, $node * $this->node_bytes );
		$b = array_values( (array) unpack( 'C*', (string) fread( $this->file, $this->node_bytes ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		switch ( $this->record_size ) {
			case 24:
				$o = $right ? 3 : 0;
				return ( $b[ $o ] << 16 ) | ( $b[ $o + 1 ] << 8 ) | $b[ $o + 2 ];
			case 28:
				if ( $right ) {
					return ( ( $b[3] & 0x0F ) << 24 ) | ( $b[4] << 16 ) | ( $b[5] << 8 ) | $b[6];
				}
				return ( ( $b[3] & 0xF0 ) << 20 ) | ( $b[0] << 16 ) | ( $b[1] << 8 ) | $b[2];
			default:
				$o = $right ? 4 : 0;
				return ( $b[ $o ] << 24 ) | ( $b[ $o + 1 ] << 16 ) | ( $b[ $o + 2 ] << 8 ) | $b[ $o + 3 ];
		}
	}

	/**
	 * Decodes the value at an offset in the file. Reads a generous window
	 * and decodes from it; pointers read their own window.
	 *
	 * @param int $offset Absolute offset.
	 * @return mixed
	 */
	private function decode_at( int $offset ) {
		fseek( $this->file, $offset );
		$buffer = (string) fread( $this->file, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- A city record is a few hundred bytes.
		$at     = 0;
		return self::decode_buffer( $buffer, $at, fn( int $pointer ) => $this->decode_at( $this->data_start + $pointer ) );
	}

	/**
	 * Decodes one value from a buffer.
	 *
	 * @param string        $buffer  The bytes.
	 * @param int           $offset  Where to start; moved past the value.
	 * @param callable|null $follow  Resolves a pointer (an offset in the data section); null in the metadata.
	 * @return mixed
	 * @throws \RuntimeException On data that is not MMDB.
	 */
	public static function decode_buffer( string $buffer, int &$offset, ?callable $follow = null ) {
		if ( $offset >= strlen( $buffer ) ) {
			throw new \RuntimeException( 'MMDB data ends early.' );
		}
		$control = ord( $buffer[ $offset++ ] );
		$type    = $control >> 5;
		if ( 1 === $type ) {
			$ss    = ( $control >> 3 ) & 0x3;
			$v     = $control & 0x7;
			$bytes = self::take( $buffer, $offset, $ss + 1 );
			$n     = self::unsigned( $bytes );
			$value = array(
				( $v << 8 ) | $n,
				( ( $v << 16 ) | $n ) + 2048,
				( ( $v << 24 ) | $n ) + 526336,
				$n,
			)[ $ss ];
			if ( null === $follow ) {
				throw new \RuntimeException( 'A pointer where none is allowed.' );
			}
			return $follow( $value );
		}
		if ( 0 === $type ) {
			$type = 7 + ord( self::take( $buffer, $offset, 1 ) );
		}
		$size = $control & 0x1f;
		if ( $size >= 29 ) {
			$extra = $size - 28;
			$n     = self::unsigned( self::take( $buffer, $offset, $extra ) );
			$size  = array( 1 => 29, 2 => 285, 3 => 65821 )[ $extra ] + $n;
		}
		switch ( $type ) {
			case 2: // UTF-8 string.
			case 4: // Bytes.
				return self::take( $buffer, $offset, $size );
			case 3: // Double.
				return unpack( 'E', self::take( $buffer, $offset, 8 ) )[1];
			case 5: // uint16.
			case 6: // uint32.
			case 9: // uint64.
			case 10: // uint128.
				return self::unsigned( self::take( $buffer, $offset, $size ) );
			case 7: // Map.
				$map = array();
				for ( $i = 0; $i < $size; $i++ ) {
					$key         = (string) self::decode_buffer( $buffer, $offset, $follow );
					$map[ $key ] = self::decode_buffer( $buffer, $offset, $follow );
				}
				return $map;
			case 8: // int32.
				$n = self::unsigned( self::take( $buffer, $offset, $size ) );
				return $size > 0 && $n >= 2 ** ( 8 * $size - 1 ) ? $n - 2 ** ( 8 * $size ) : $n;
			case 11: // Array.
				$list = array();
				for ( $i = 0; $i < $size; $i++ ) {
					$list[] = self::decode_buffer( $buffer, $offset, $follow );
				}
				return $list;
			case 14: // Boolean: the size is the value.
				return 0 !== $size;
			case 15: // Float.
				return unpack( 'G', self::take( $buffer, $offset, 4 ) )[1];
			default: // 12 data cache container, 13 end marker.
				return null;
		}
	}

	/**
	 * Takes bytes from a buffer.
	 *
	 * @param string $buffer The bytes.
	 * @param int    $offset Moved past them.
	 * @param int    $length How many.
	 * @throws \RuntimeException When the buffer is too short.
	 */
	private static function take( string $buffer, int &$offset, int $length ): string {
		if ( $offset + $length > strlen( $buffer ) ) {
			throw new \RuntimeException( 'MMDB data ends early.' );
		}
		$bytes   = substr( $buffer, $offset, $length );
		$offset += $length;
		return $bytes;
	}

	/**
	 * Big-endian bytes as an integer (a float past PHP's integers: uint64 and
	 * uint128 values are not used for places).
	 *
	 * @param string $bytes The bytes.
	 * @return int|float
	 */
	private static function unsigned( string $bytes ) {
		$n = 0;
		foreach ( str_split( $bytes ) as $byte ) {
			$n = $n * 256 + ord( $byte );
		}
		return $n;
	}
}
