<?php
/**
 * Ethereum personal_sign (EIP-191) signature verification.
 *
 * @package MetaMask_Login
 * @since   2.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../crypto/class-metamask-keccak.php';
require_once __DIR__ . '/../crypto/class-metamask-secp256k1.php';

/**
 * Verifies Ethereum `personal_sign` signatures.
 *
 * Since 3.0.0 this performs real secp256k1 public-key recovery in pure PHP,
 * so no extra PHP extensions or Composer libraries are required.
 *
 * @since 2.0.0
 */
class Eth_Sign_Verify {

	/**
	 * Verify that `$address` signed `$message` with personal_sign.
	 *
	 * @since 2.0.0
	 * @param string $message   Original (unprefixed) message.
	 * @param string $signature 65-byte signature as 0x-prefixed hex.
	 * @param string $address   Expected signer address.
	 * @return bool
	 */
	public function verify( $message, $signature, $address ) {
		if ( ! self::is_valid_address( $address ) ) {
			return false;
		}

		$recovered = self::recover( $message, $signature );

		return false !== $recovered && hash_equals( strtolower( $address ), $recovered );
	}

	/**
	 * Recover the signer address of a personal_sign signature.
	 *
	 * @since 3.0.0
	 * @param string $message   Original message.
	 * @param string $signature Signature hex.
	 * @return string|false Lowercase 0x address or false.
	 */
	public static function recover( $message, $signature ) {
		if ( ! is_string( $message ) || ! is_string( $signature ) ) {
			return false;
		}

		$signature = strtolower( $signature );
		if ( 0 === strpos( $signature, '0x' ) ) {
			$signature = substr( $signature, 2 );
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{130}$/', $signature ) ) {
			return false;
		}

		$hash = self::hash_message( $message );
		$r    = substr( $signature, 0, 64 );
		$s    = substr( $signature, 64, 64 );
		$v    = hexdec( substr( $signature, 128, 2 ) );

		// Accept both legacy (27/28) and raw (0/1) recovery ids.
		if ( $v >= 27 ) {
			$v -= 27;
		}
		if ( 0 !== $v && 1 !== $v ) {
			return false;
		}

		/**
		 * Short-circuit signature recovery with a custom implementation.
		 *
		 * Return a 0x-prefixed address to use it, or null to use the built-in
		 * pure-PHP secp256k1 recovery.
		 *
		 * @since 2.0.0
		 * @param string|null $address Recovered address.
		 * @param string      $hash    Message hash (hex, no prefix).
		 * @param string      $r       Signature r (hex).
		 * @param string      $s       Signature s (hex).
		 * @param int         $v       Recovery id (27 or 28).
		 */
		$custom = function_exists( 'apply_filters' )
			? apply_filters( 'metamask_login_custom_signature_verify', null, $hash, $r, $s, $v + 27 )
			: null;
		if ( null !== $custom ) {
			return is_string( $custom ) ? strtolower( $custom ) : false;
		}

		return MetaMask_Secp256k1::recover_address( $hash, $r, $s, $v );
	}

	/**
	 * EIP-191 personal message hash.
	 *
	 * @since 3.0.0
	 * @param string $message Message.
	 * @return string Hex hash.
	 */
	public static function hash_message( $message ) {
		return MetaMask_Keccak::hash( "\x19Ethereum Signed Message:\n" . strlen( $message ) . $message );
	}

	/**
	 * Whether a string is a syntactically valid Ethereum address.
	 *
	 * @since 3.0.0
	 * @param mixed $address Address.
	 * @return bool
	 */
	public static function is_valid_address( $address ) {
		return is_string( $address ) && 1 === preg_match( '/^0x[0-9a-fA-F]{40}$/', $address );
	}

	/**
	 * EIP-55 mixed-case checksum encoding.
	 *
	 * @since 3.0.0
	 * @param string $address Address.
	 * @return string Checksummed address.
	 */
	public static function to_checksum_address( $address ) {
		if ( ! self::is_valid_address( $address ) ) {
			return (string) $address;
		}
		$lower = strtolower( substr( $address, 2 ) );
		$hash  = MetaMask_Keccak::hash( $lower );
		$out   = '0x';
		for ( $i = 0; $i < 40; $i++ ) {
			$out .= hexdec( $hash[ $i ] ) >= 8 ? strtoupper( $lower[ $i ] ) : $lower[ $i ];
		}
		return $out;
	}
}
