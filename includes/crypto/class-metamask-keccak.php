<?php
/**
 * Pure-PHP Keccak-256 implementation.
 *
 * Ethereum uses the original Keccak padding (0x01), which differs from the
 * NIST SHA3-256 padding (0x06) exposed by PHP's hash() extension, so the
 * native `sha3-256` algorithm can NOT be used for Ethereum hashing.
 *
 * Requires a 64-bit PHP build (standard on every supported platform).
 *
 * @package MetaMask_Login
 * @since   3.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keccak-256 hashing.
 *
 * @since 3.0.0
 */
final class MetaMask_Keccak {

	/**
	 * Rate in bytes for Keccak-256 (1600 - 2 * 256 bits).
	 */
	const RATE = 136;

	/**
	 * Round constants as [high 32 bits, low 32 bits] pairs.
	 *
	 * Stored split because several constants exceed PHP_INT_MAX when written
	 * as a single literal (which would turn them into floats).
	 *
	 * @var array<int, array{0:int,1:int}>
	 */
	private static $round_constants = array(
		array( 0x00000000, 0x00000001 ), array( 0x00000000, 0x00008082 ),
		array( 0x80000000, 0x0000808A ), array( 0x80000000, 0x80008000 ),
		array( 0x00000000, 0x0000808B ), array( 0x00000000, 0x80000001 ),
		array( 0x80000000, 0x80008081 ), array( 0x80000000, 0x00008009 ),
		array( 0x00000000, 0x0000008A ), array( 0x00000000, 0x00000088 ),
		array( 0x00000000, 0x80008009 ), array( 0x00000000, 0x8000000A ),
		array( 0x00000000, 0x8000808B ), array( 0x80000000, 0x0000008B ),
		array( 0x80000000, 0x00008089 ), array( 0x80000000, 0x00008003 ),
		array( 0x80000000, 0x00008002 ), array( 0x80000000, 0x00000080 ),
		array( 0x00000000, 0x0000800A ), array( 0x80000000, 0x8000000A ),
		array( 0x80000000, 0x80008081 ), array( 0x80000000, 0x00008080 ),
		array( 0x00000000, 0x80000001 ), array( 0x80000000, 0x80008008 ),
	);

	/**
	 * Rotation offsets indexed by lane (x + 5 * y).
	 *
	 * @var int[]
	 */
	private static $rotations = array(
		0, 1, 62, 28, 27,
		36, 44, 6, 55, 20,
		3, 10, 43, 25, 39,
		41, 45, 15, 21, 8,
		18, 2, 61, 56, 14,
	);

	/**
	 * Cached 64-bit round constants.
	 *
	 * @var int[]|null
	 */
	private static $rc = null;

	/**
	 * Hash data with Keccak-256.
	 *
	 * @since 3.0.0
	 * @param string $data Raw bytes to hash.
	 * @param bool   $raw  Return raw 32 bytes instead of lowercase hex.
	 * @return string
	 */
	public static function hash( $data, $raw = false ) {
		if ( null === self::$rc ) {
			self::$rc = array();
			foreach ( self::$round_constants as $pair ) {
				self::$rc[] = ( $pair[0] << 32 ) | $pair[1];
			}
		}

		// Keccak padding: 0x01 ... 0x80.
		$data   .= "\x01";
		$pad_len = self::RATE - ( strlen( $data ) % self::RATE );
		if ( self::RATE !== $pad_len ) {
			$data .= str_repeat( "\x00", $pad_len );
		}
		$data[ strlen( $data ) - 1 ] = chr( ord( $data[ strlen( $data ) - 1 ] ) | 0x80 );

		$state  = array_fill( 0, 25, 0 );
		$blocks = str_split( $data, self::RATE );

		foreach ( $blocks as $block ) {
			$lanes = array_values( unpack( 'P17', $block ) );
			for ( $i = 0; $i < 17; $i++ ) {
				$state[ $i ] ^= $lanes[ $i ];
			}
			self::permute( $state );
		}

		$out = pack( 'P4', $state[0], $state[1], $state[2], $state[3] );

		return $raw ? $out : bin2hex( $out );
	}

	/**
	 * Rotate a 64-bit lane left.
	 *
	 * @param int $value Lane.
	 * @param int $shift Shift (0-63).
	 * @return int
	 */
	private static function rotl( $value, $shift ) {
		if ( 0 === $shift ) {
			return $value;
		}
		// PHP's >> is arithmetic, so mask off the sign-extended bits.
		return ( $value << $shift ) | ( ( $value >> ( 64 - $shift ) ) & ( ( 1 << $shift ) - 1 ) );
	}

	/**
	 * Keccak-f[1600] permutation.
	 *
	 * @param int[] $s State (25 lanes), modified in place.
	 */
	private static function permute( array &$s ) {
		$rot = self::$rotations;
		$b   = array_fill( 0, 25, 0 );
		$c   = array( 0, 0, 0, 0, 0 );

		for ( $round = 0; $round < 24; $round++ ) {
			// Theta.
			for ( $x = 0; $x < 5; $x++ ) {
				$c[ $x ] = $s[ $x ] ^ $s[ $x + 5 ] ^ $s[ $x + 10 ] ^ $s[ $x + 15 ] ^ $s[ $x + 20 ];
			}
			for ( $x = 0; $x < 5; $x++ ) {
				$d = $c[ ( $x + 4 ) % 5 ] ^ self::rotl( $c[ ( $x + 1 ) % 5 ], 1 );
				for ( $y = 0; $y < 25; $y += 5 ) {
					$s[ $y + $x ] ^= $d;
				}
			}

			// Rho and Pi.
			for ( $x = 0; $x < 5; $x++ ) {
				for ( $y = 0; $y < 5; $y++ ) {
					$idx = $x + 5 * $y;
					$b[ $y + 5 * ( ( 2 * $x + 3 * $y ) % 5 ) ] = self::rotl( $s[ $idx ], $rot[ $idx ] );
				}
			}

			// Chi.
			for ( $y = 0; $y < 25; $y += 5 ) {
				for ( $x = 0; $x < 5; $x++ ) {
					$s[ $y + $x ] = $b[ $y + $x ] ^ ( ( ~$b[ $y + ( $x + 1 ) % 5 ] ) & $b[ $y + ( $x + 2 ) % 5 ] );
				}
			}

			// Iota.
			$s[0] ^= self::$rc[ $round ];
		}
	}
}
