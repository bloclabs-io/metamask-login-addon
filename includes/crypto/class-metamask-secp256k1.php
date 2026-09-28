<?php
/**
 * Pure-PHP secp256k1 public key recovery (ecrecover).
 *
 * Implements just enough elliptic-curve arithmetic to recover the Ethereum
 * address that produced a signature. It has no dependency on the GMP or
 * BCMath extensions, which are missing on many WordPress hosts.
 *
 * Numbers are stored as little-endian arrays of 28-bit limbs and all modular
 * multiplication uses Montgomery reduction, so no big-number division is
 * ever needed. Only public data (hash, signature) is processed, so the code
 * does not need to be constant-time.
 *
 * @package MetaMask_Login
 * @since   3.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * secp256k1 ecrecover.
 *
 * @since 3.0.0
 */
final class MetaMask_Secp256k1 {

	const LIMB_BITS = 28;
	const LIMBS     = 10;
	const MASK      = 0xFFFFFFF;

	const P_HEX      = 'fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f';
	const P_MINUS_2  = 'fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2d';
	const P_SQRT_EXP = '3fffffffffffffffffffffffffffffffffffffffffffffffffffffffbfffff0c';
	const N_HEX      = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';
	const N_MINUS_2  = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd036413f';
	const GX_HEX     = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
	const GY_HEX     = '483ada7726a3c4655da4fbfc0e1108a8fd17b448a68554199c47d08ffb10d4b8';

	/**
	 * Montgomery context for the field prime p.
	 *
	 * @var array|null
	 */
	private static $fp = null;

	/**
	 * Montgomery context for the group order n.
	 *
	 * @var array|null
	 */
	private static $fn = null;

	/**
	 * Recover the Ethereum address that signed a 32-byte hash.
	 *
	 * @since 3.0.0
	 * @param string $hash_hex 64 hex chars (message hash).
	 * @param string $r_hex    64 hex chars.
	 * @param string $s_hex    64 hex chars.
	 * @param int    $recid    Recovery id (0 or 1).
	 * @return string|false Lowercase 0x-prefixed address, or false.
	 */
	public static function recover_address( $hash_hex, $r_hex, $s_hex, $recid ) {
		$pub = self::recover_public_key( $hash_hex, $r_hex, $s_hex, $recid );
		if ( false === $pub ) {
			return false;
		}
		return '0x' . substr( MetaMask_Keccak::hash( hex2bin( $pub ) ), 24 );
	}

	/**
	 * Recover the uncompressed public key (x || y, 128 hex chars, no prefix).
	 *
	 * @since 3.0.0
	 * @param string $hash_hex Message hash.
	 * @param string $r_hex    Signature r.
	 * @param string $s_hex    Signature s.
	 * @param int    $recid    Recovery id (0 or 1).
	 * @return string|false
	 */
	public static function recover_public_key( $hash_hex, $r_hex, $s_hex, $recid ) {
		foreach ( array( $hash_hex, $r_hex, $s_hex ) as $hex ) {
			if ( ! is_string( $hex ) || 1 !== preg_match( '/^[0-9a-fA-F]{64}$/', $hex ) ) {
				return false;
			}
		}
		if ( 0 !== $recid && 1 !== $recid ) {
			return false;
		}

		self::init();
		$fp = self::$fp;
		$fn = self::$fn;

		$r = self::from_hex( $r_hex );
		$s = self::from_hex( $s_hex );
		$e = self::from_hex( $hash_hex );

		// 1 <= r, s < n.
		if ( self::is_zero( $r ) || self::is_zero( $s ) || self::cmp( $r, $fn['m'] ) >= 0 || self::cmp( $s, $fn['m'] ) >= 0 ) {
			return false;
		}

		// e mod n (e < 2^256 < 2n, so one subtraction is enough).
		if ( self::cmp( $e, $fn['m'] ) >= 0 ) {
			$e = self::sub( $e, $fn['m'] );
		}

		// Lift x = r to the curve point R: y^2 = x^3 + 7.
		$x     = self::to_mont( $r, $fp );
		$alpha = self::add_mod( self::mul( self::mul( $x, $x, $fp ), $x, $fp ), $fp['seven'], $fp['m'] );
		$y     = self::pow( $alpha, self::P_SQRT_EXP, $fp );
		if ( self::cmp( self::mul( $y, $y, $fp ), $alpha ) !== 0 ) {
			return false; // r is not the x coordinate of a curve point.
		}
		$y_plain = self::from_mont( $y, $fp );
		if ( ( $y_plain[0] & 1 ) !== $recid ) {
			$y = self::sub_mod( self::zero(), $y, $fp['m'] );
		}
		$point_r = array( $x, $y, $fp['one'] );

		// u1 = -e / r mod n, u2 = s / r mod n.
		$r_inv = self::pow( self::to_mont( $r, $fn ), self::N_MINUS_2, $fn );
		$neg_e = self::sub_mod( self::zero(), $e, $fn['m'] );
		$u1    = self::from_mont( self::mul( self::to_mont( $neg_e, $fn ), $r_inv, $fn ), $fn );
		$u2    = self::from_mont( self::mul( self::to_mont( $s, $fn ), $r_inv, $fn ), $fn );

		// Q = u1 * G + u2 * R (Shamir's trick).
		$point_g = array( $fp['gx'], $fp['gy'], $fp['one'] );
		$q       = self::double_mul( $u1, $point_g, $u2, $point_r );

		if ( self::is_zero( $q[2] ) ) {
			return false;
		}

		// Convert back to affine coordinates.
		$z_inv  = self::pow( $q[2], self::P_MINUS_2, $fp );
		$z_inv2 = self::mul( $z_inv, $z_inv, $fp );
		$ax     = self::from_mont( self::mul( $q[0], $z_inv2, $fp ), $fp );
		$ay     = self::from_mont( self::mul( $q[1], self::mul( $z_inv2, $z_inv, $fp ), $fp ), $fp );

		return self::to_hex( $ax ) . self::to_hex( $ay );
	}

	/**
	 * Build Montgomery contexts once per request.
	 */
	private static function init() {
		if ( null !== self::$fp ) {
			return;
		}
		self::$fp          = self::context( self::P_HEX );
		self::$fn          = self::context( self::N_HEX );
		self::$fp['seven'] = self::to_mont( self::from_int( 7 ), self::$fp );
		self::$fp['gx']    = self::to_mont( self::from_hex( self::GX_HEX ), self::$fp );
		self::$fp['gy']    = self::to_mont( self::from_hex( self::GY_HEX ), self::$fp );
	}

	/**
	 * Create a Montgomery context for an odd modulus.
	 *
	 * @param string $modulus_hex Modulus.
	 * @return array
	 */
	private static function context( $modulus_hex ) {
		$m = self::from_hex( $modulus_hex );

		// -m^-1 mod 2^28 via Newton iteration.
		$inv = 1;
		for ( $i = 0; $i < 6; $i++ ) {
			$inv = ( $inv * ( ( 2 - ( ( $m[0] * $inv ) & self::MASK ) ) & self::MASK ) ) & self::MASK;
		}
		$minv = ( ( 1 << self::LIMB_BITS ) - $inv ) & self::MASK;

		// R^2 mod m where R = 2^(28 * 10), computed by repeated doubling.
		$r2 = self::from_int( 1 );
		for ( $i = 0; $i < 2 * self::LIMB_BITS * self::LIMBS; $i++ ) {
			$r2 = self::add_mod( $r2, $r2, $m );
		}

		$ctx        = array(
			'm'    => $m,
			'minv' => $minv,
			'r2'   => $r2,
		);
		$ctx['one'] = self::to_mont( self::from_int( 1 ), $ctx );

		return $ctx;
	}

	/**
	 * Compute a*P + b*Q in Jacobian coordinates.
	 *
	 * @param int[] $a Scalar (plain form).
	 * @param array $p Point.
	 * @param int[] $b Scalar (plain form).
	 * @param array $q Point.
	 * @return array Jacobian point.
	 */
	private static function double_mul( array $a, array $p, array $b, array $q ) {
		$pq  = self::point_add( $p, $q );
		$acc = array( self::zero(), self::zero(), self::zero() );

		for ( $i = self::LIMB_BITS * self::LIMBS - 1; $i >= 0; $i-- ) {
			$acc = self::point_double( $acc );
			$bit_a = ( $a[ intdiv( $i, self::LIMB_BITS ) ] >> ( $i % self::LIMB_BITS ) ) & 1;
			$bit_b = ( $b[ intdiv( $i, self::LIMB_BITS ) ] >> ( $i % self::LIMB_BITS ) ) & 1;
			if ( $bit_a && $bit_b ) {
				$acc = self::point_add( $acc, $pq );
			} elseif ( $bit_a ) {
				$acc = self::point_add( $acc, $p );
			} elseif ( $bit_b ) {
				$acc = self::point_add( $acc, $q );
			}
		}

		return $acc;
	}

	/**
	 * Jacobian point doubling for a = 0 curves (dbl-2009-l).
	 *
	 * @param array $pt Point.
	 * @return array
	 */
	private static function point_double( array $pt ) {
		$fp = self::$fp;
		$m  = $fp['m'];

		if ( self::is_zero( $pt[2] ) || self::is_zero( $pt[1] ) ) {
			return array( self::zero(), self::zero(), self::zero() );
		}

		list( $x1, $y1, $z1 ) = $pt;

		$a  = self::mul( $x1, $x1, $fp );
		$b  = self::mul( $y1, $y1, $fp );
		$c  = self::mul( $b, $b, $fp );
		$t  = self::add_mod( $x1, $b, $m );
		$d  = self::sub_mod( self::sub_mod( self::mul( $t, $t, $fp ), $a, $m ), $c, $m );
		$d  = self::add_mod( $d, $d, $m );
		$e  = self::add_mod( self::add_mod( $a, $a, $m ), $a, $m );
		$f  = self::mul( $e, $e, $fp );
		$x3 = self::sub_mod( $f, self::add_mod( $d, $d, $m ), $m );
		$c8 = self::add_mod( $c, $c, $m );
		$c8 = self::add_mod( $c8, $c8, $m );
		$c8 = self::add_mod( $c8, $c8, $m );
		$y3 = self::sub_mod( self::mul( $e, self::sub_mod( $d, $x3, $m ), $fp ), $c8, $m );
		$z3 = self::mul( $y1, $z1, $fp );
		$z3 = self::add_mod( $z3, $z3, $m );

		return array( $x3, $y3, $z3 );
	}

	/**
	 * Jacobian point addition.
	 *
	 * @param array $p1 Point.
	 * @param array $p2 Point.
	 * @return array
	 */
	private static function point_add( array $p1, array $p2 ) {
		if ( self::is_zero( $p1[2] ) ) {
			return $p2;
		}
		if ( self::is_zero( $p2[2] ) ) {
			return $p1;
		}

		$fp = self::$fp;
		$m  = $fp['m'];

		list( $x1, $y1, $z1 ) = $p1;
		list( $x2, $y2, $z2 ) = $p2;

		$z1z1 = self::mul( $z1, $z1, $fp );
		$z2z2 = self::mul( $z2, $z2, $fp );
		$u1   = self::mul( $x1, $z2z2, $fp );
		$u2   = self::mul( $x2, $z1z1, $fp );
		$s1   = self::mul( $y1, self::mul( $z2, $z2z2, $fp ), $fp );
		$s2   = self::mul( $y2, self::mul( $z1, $z1z1, $fp ), $fp );
		$h    = self::sub_mod( $u2, $u1, $m );
		$r    = self::sub_mod( $s2, $s1, $m );

		if ( self::is_zero( $h ) ) {
			if ( self::is_zero( $r ) ) {
				return self::point_double( $p1 );
			}
			return array( self::zero(), self::zero(), self::zero() );
		}

		$hh   = self::mul( $h, $h, $fp );
		$hhh  = self::mul( $h, $hh, $fp );
		$v    = self::mul( $u1, $hh, $fp );
		$x3   = self::sub_mod( self::sub_mod( self::mul( $r, $r, $fp ), $hhh, $m ), self::add_mod( $v, $v, $m ), $m );
		$y3   = self::sub_mod( self::mul( $r, self::sub_mod( $v, $x3, $m ), $fp ), self::mul( $s1, $hhh, $fp ), $m );
		$z3   = self::mul( self::mul( $h, $z1, $fp ), $z2, $fp );

		return array( $x3, $y3, $z3 );
	}

	/**
	 * Montgomery modular exponentiation.
	 *
	 * @param int[]  $base    Base (Montgomery form).
	 * @param string $exp_hex Exponent as hex.
	 * @param array  $ctx     Context.
	 * @return int[] Result (Montgomery form).
	 */
	private static function pow( array $base, $exp_hex, array $ctx ) {
		$result = $ctx['one'];
		$len    = strlen( $exp_hex );
		for ( $i = 0; $i < $len; $i++ ) {
			$nibble = hexdec( $exp_hex[ $i ] );
			for ( $bit = 3; $bit >= 0; $bit-- ) {
				$result = self::mul( $result, $result, $ctx );
				if ( ( $nibble >> $bit ) & 1 ) {
					$result = self::mul( $result, $base, $ctx );
				}
			}
		}
		return $result;
	}

	/**
	 * Montgomery multiplication (CIOS): a * b * R^-1 mod m.
	 *
	 * @param int[] $a   Operand < m.
	 * @param int[] $b   Operand < m.
	 * @param array $ctx Context.
	 * @return int[]
	 */
	private static function mul( array $a, array $b, array $ctx ) {
		$k    = self::LIMBS;
		$m    = $ctx['m'];
		$minv = $ctx['minv'];
		$t    = array_fill( 0, $k + 2, 0 );

		for ( $i = 0; $i < $k; $i++ ) {
			$bi    = $b[ $i ];
			$carry = 0;
			for ( $j = 0; $j < $k; $j++ ) {
				$sum     = $t[ $j ] + $a[ $j ] * $bi + $carry;
				$t[ $j ] = $sum & self::MASK;
				$carry   = $sum >> self::LIMB_BITS;
			}
			$sum          = $t[ $k ] + $carry;
			$t[ $k ]      = $sum & self::MASK;
			$t[ $k + 1 ]  = $sum >> self::LIMB_BITS;

			$q     = ( $t[0] * $minv ) & self::MASK;
			$carry = ( $t[0] + $q * $m[0] ) >> self::LIMB_BITS;
			for ( $j = 1; $j < $k; $j++ ) {
				$sum           = $t[ $j ] + $q * $m[ $j ] + $carry;
				$t[ $j - 1 ]   = $sum & self::MASK;
				$carry         = $sum >> self::LIMB_BITS;
			}
			$sum           = $t[ $k ] + $carry;
			$t[ $k - 1 ]   = $sum & self::MASK;
			$t[ $k ]       = $t[ $k + 1 ] + ( $sum >> self::LIMB_BITS );
		}

		$result = array_slice( $t, 0, $k );
		if ( $t[ $k ] > 0 || self::cmp( $result, $m ) >= 0 ) {
			$result = self::sub( $result, $m );
		}
		return $result;
	}

	/**
	 * Convert to Montgomery form.
	 *
	 * @param int[] $a   Plain value < m.
	 * @param array $ctx Context.
	 * @return int[]
	 */
	private static function to_mont( array $a, array $ctx ) {
		return self::mul( $a, $ctx['r2'], $ctx );
	}

	/**
	 * Convert out of Montgomery form.
	 *
	 * @param int[] $a   Montgomery value.
	 * @param array $ctx Context.
	 * @return int[]
	 */
	private static function from_mont( array $a, array $ctx ) {
		return self::mul( $a, self::from_int( 1 ), $ctx );
	}

	/**
	 * (a + b) mod m for a, b < m.
	 *
	 * @param int[] $a A.
	 * @param int[] $b B.
	 * @param int[] $m Modulus.
	 * @return int[]
	 */
	private static function add_mod( array $a, array $b, array $m ) {
		$r     = array();
		$carry = 0;
		for ( $i = 0; $i < self::LIMBS; $i++ ) {
			$sum     = $a[ $i ] + $b[ $i ] + $carry;
			$r[ $i ] = $sum & self::MASK;
			$carry   = $sum >> self::LIMB_BITS;
		}
		if ( $carry || self::cmp( $r, $m ) >= 0 ) {
			$r = self::sub( $r, $m );
		}
		return $r;
	}

	/**
	 * (a - b) mod m for a, b < m.
	 *
	 * @param int[] $a A.
	 * @param int[] $b B.
	 * @param int[] $m Modulus.
	 * @return int[]
	 */
	private static function sub_mod( array $a, array $b, array $m ) {
		if ( self::cmp( $a, $b ) >= 0 ) {
			return self::sub( $a, $b );
		}
		return self::sub( self::add_raw( $a, $m ), $b );
	}

	/**
	 * Plain addition (caller guarantees no overflow beyond 280 bits).
	 *
	 * @param int[] $a A.
	 * @param int[] $b B.
	 * @return int[]
	 */
	private static function add_raw( array $a, array $b ) {
		$r     = array();
		$carry = 0;
		for ( $i = 0; $i < self::LIMBS; $i++ ) {
			$sum     = $a[ $i ] + $b[ $i ] + $carry;
			$r[ $i ] = $sum & self::MASK;
			$carry   = $sum >> self::LIMB_BITS;
		}
		return $r;
	}

	/**
	 * Plain subtraction a - b (a >= b, modulo 2^280 otherwise).
	 *
	 * @param int[] $a A.
	 * @param int[] $b B.
	 * @return int[]
	 */
	private static function sub( array $a, array $b ) {
		$r      = array();
		$borrow = 0;
		for ( $i = 0; $i < self::LIMBS; $i++ ) {
			$diff = $a[ $i ] - $b[ $i ] - $borrow;
			if ( $diff < 0 ) {
				$diff  += 1 << self::LIMB_BITS;
				$borrow = 1;
			} else {
				$borrow = 0;
			}
			$r[ $i ] = $diff;
		}
		return $r;
	}

	/**
	 * Compare two numbers.
	 *
	 * @param int[] $a A.
	 * @param int[] $b B.
	 * @return int -1, 0 or 1.
	 */
	private static function cmp( array $a, array $b ) {
		for ( $i = self::LIMBS - 1; $i >= 0; $i-- ) {
			if ( $a[ $i ] !== $b[ $i ] ) {
				return $a[ $i ] < $b[ $i ] ? -1 : 1;
			}
		}
		return 0;
	}

	/**
	 * Whether a number is zero.
	 *
	 * @param int[] $a Number.
	 * @return bool
	 */
	private static function is_zero( array $a ) {
		foreach ( $a as $limb ) {
			if ( 0 !== $limb ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Zero.
	 *
	 * @return int[]
	 */
	private static function zero() {
		return array_fill( 0, self::LIMBS, 0 );
	}

	/**
	 * Small integer to limbs.
	 *
	 * @param int $value Value < 2^28.
	 * @return int[]
	 */
	private static function from_int( $value ) {
		$r    = self::zero();
		$r[0] = $value;
		return $r;
	}

	/**
	 * Hex string (up to 64 chars) to limbs.
	 *
	 * @param string $hex Hex.
	 * @return int[]
	 */
	private static function from_hex( $hex ) {
		$r    = self::zero();
		$bits = 0;
		$len  = strlen( $hex );
		// Consume nibbles from the least significant end.
		for ( $i = $len - 1; $i >= 0; $i-- ) {
			$nibble = hexdec( $hex[ $i ] );
			$limb   = intdiv( $bits, self::LIMB_BITS );
			$offset = $bits % self::LIMB_BITS;
			$r[ $limb ] |= ( $nibble << $offset ) & self::MASK;
			if ( $offset > self::LIMB_BITS - 4 ) {
				$r[ $limb + 1 ] |= $nibble >> ( self::LIMB_BITS - $offset );
			}
			$bits += 4;
		}
		return $r;
	}

	/**
	 * Limbs to 64-char lowercase hex.
	 *
	 * @param int[] $a Number < 2^256.
	 * @return string
	 */
	private static function to_hex( array $a ) {
		$hex = '';
		for ( $n = 63; $n >= 0; $n-- ) {
			$nibble = 0;
			for ( $bit = 3; $bit >= 0; $bit-- ) {
				$pos     = $n * 4 + $bit;
				$nibble  = ( $nibble << 1 ) | ( ( $a[ intdiv( $pos, self::LIMB_BITS ) ] >> ( $pos % self::LIMB_BITS ) ) & 1 );
			}
			$hex .= dechex( $nibble );
		}
		return $hex;
	}
}
