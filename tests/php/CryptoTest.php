<?php
/**
 * Tests for Keccak-256, secp256k1 recovery and personal_sign verification.
 *
 * Fixtures in fixtures/vectors.json were generated with ethers.js v6.
 *
 * @package MetaMask_Login
 */

use PHPUnit\Framework\TestCase;

/**
 * Crypto tests.
 */
class CryptoTest extends TestCase {

	/**
	 * Fixture data.
	 *
	 * @var array
	 */
	private static $vectors;

	public static function setUpBeforeClass(): void {
		self::$vectors = json_decode( file_get_contents( __DIR__ . '/fixtures/vectors.json' ), true );
	}

	public function test_keccak_matches_reference_vectors() {
		foreach ( self::$vectors['keccak'] as $vector ) {
			$this->assertSame( $vector['hash'], MetaMask_Keccak::hash( $vector['input'] ), 'Input length ' . strlen( $vector['input'] ) );
		}
	}

	public function test_keccak_is_not_sha3() {
		// Ethereum Keccak differs from NIST SHA3-256 (different padding).
		$this->assertNotSame( hash( 'sha3-256', '' ), MetaMask_Keccak::hash( '' ) );
		$this->assertSame( 32, strlen( MetaMask_Keccak::hash( 'abc', true ) ) );
	}

	public function test_personal_message_hash() {
		foreach ( self::$vectors['signatures'] as $vector ) {
			$this->assertSame( $vector['hash'], Eth_Sign_Verify::hash_message( $vector['message'] ) );
		}
	}

	public function test_recovers_signer_address() {
		foreach ( self::$vectors['signatures'] as $vector ) {
			$this->assertSame( strtolower( $vector['address'] ), Eth_Sign_Verify::recover( $vector['message'], $vector['signature'] ) );
			$this->assertTrue( ( new Eth_Sign_Verify() )->verify( $vector['message'], $vector['signature'], $vector['address'] ) );
			// Case-insensitive address comparison.
			$this->assertTrue( ( new Eth_Sign_Verify() )->verify( $vector['message'], $vector['signature'], strtolower( $vector['address'] ) ) );
		}
	}

	public function test_accepts_raw_recovery_id_and_missing_prefix() {
		$vector = self::$vectors['signatures'][0];
		$sig    = substr( $vector['signature'], 2 );
		$v      = hexdec( substr( $sig, 128, 2 ) ) - 27;
		$raw    = substr( $sig, 0, 128 ) . sprintf( '%02x', $v );
		$this->assertSame( strtolower( $vector['address'] ), Eth_Sign_Verify::recover( $vector['message'], $raw ) );
	}

	public function test_high_s_signature_recovers_same_address() {
		$vector = self::$vectors['high_s'];
		$this->assertSame( strtolower( $vector['address'] ), Eth_Sign_Verify::recover( $vector['message'], $vector['signature'] ) );
	}

	public function test_rejects_tampered_message_and_wrong_address() {
		$vector   = self::$vectors['signatures'][1];
		$verifier = new Eth_Sign_Verify();
		$this->assertFalse( $verifier->verify( $vector['message'] . ' ', $vector['signature'], $vector['address'] ) );
		$this->assertFalse( $verifier->verify( $vector['message'], $vector['signature'], self::$vectors['signatures'][2]['address'] ) );
		$this->assertFalse( $verifier->verify( $vector['message'], $vector['signature'], 'not-an-address' ) );
	}

	/**
	 * @dataProvider invalid_signatures
	 */
	public function test_rejects_malformed_signatures( $signature ) {
		$this->assertFalse( Eth_Sign_Verify::recover( 'hello', $signature ) );
	}

	public function invalid_signatures() {
		$n = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';
		$r = str_repeat( '11', 32 );
		return array(
			'empty'        => array( '' ),
			'too short'    => array( '0x' . str_repeat( 'ab', 64 ) ),
			'not hex'      => array( '0x' . str_repeat( 'zz', 65 ) ),
			'bad v'        => array( '0x' . $r . $r . '1d' ),
			'r is zero'    => array( '0x' . str_repeat( '0', 64 ) . $r . '1b' ),
			's is zero'    => array( '0x' . $r . str_repeat( '0', 64 ) . '1b' ),
			'r equals n'   => array( '0x' . $n . $r . '1b' ),
			's equals n'   => array( '0x' . $r . $n . '1c' ),
			'x not on curve' => array( '0x' . str_repeat( '0', 63 ) . '5' . $r . '1b' ),
			'not a string' => array( array() ),
		);
	}

	public function test_checksum_address_matches_eip55_examples() {
		$examples = array(
			'0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed',
			'0xfB6916095ca1df60bB79Ce92cE3Ea74c37c5d359',
			'0xdbF03B407c01E7cD3CBea99509d93f8DDDC8C6FB',
			'0xD1220A0cf47c7B9Be7A2E6BA89F429762e7b9aDb',
		);
		foreach ( $examples as $address ) {
			$this->assertSame( $address, Eth_Sign_Verify::to_checksum_address( strtolower( $address ) ) );
		}
		foreach ( self::$vectors['signatures'] as $vector ) {
			$this->assertSame( $vector['address'], Eth_Sign_Verify::to_checksum_address( strtolower( $vector['address'] ) ) );
		}
	}

	public function test_address_validation() {
		$this->assertTrue( Eth_Sign_Verify::is_valid_address( '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed' ) );
		$this->assertFalse( Eth_Sign_Verify::is_valid_address( '5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed' ) );
		$this->assertFalse( Eth_Sign_Verify::is_valid_address( '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAe' ) );
		$this->assertFalse( Eth_Sign_Verify::is_valid_address( null ) );
	}
}
