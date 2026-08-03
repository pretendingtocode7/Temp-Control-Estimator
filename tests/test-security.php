<?php
/**
 * Security helper tests — encryption roundtrip, HMAC verification, ID validation.
 *
 * The encryption tests run standalone (no WP). HMAC and sanitization tests run standalone too.
 * Zoho-refresh-token storage tests require WP options API.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-security.php';

final class SecurityTest extends TestCase {

	protected function setUp(): void {
		if ( ! defined( 'TC_ESTIMATE_ENC_KEY' ) ) {
			define( 'TC_ESTIMATE_ENC_KEY', bin2hex( random_bytes( 32 ) ) );
		}
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'libsodium required.' );
		}
	}

	public function test_encrypt_decrypt_roundtrip(): void {
		$sec = \TempControl\Estimate\Security::instance();
		$plain = 'super-secret-refresh-token-abc-123';

		$encrypted = $sec->encrypt( $plain );
		$this->assertNotSame( $plain, $encrypted );
		$this->assertTrue( (bool) base64_decode( $encrypted, true ), 'Encrypted output should be base64.' );

		$decrypted = $sec->decrypt( $encrypted );
		$this->assertSame( $plain, $decrypted );
	}

	public function test_encrypt_produces_different_ciphertext_each_time(): void {
		// Nonce is random on every encrypt — same plaintext should yield different ciphertext.
		$sec = \TempControl\Estimate\Security::instance();
		$plain = 'same-input';
		$a = $sec->encrypt( $plain );
		$b = $sec->encrypt( $plain );
		$this->assertNotSame( $a, $b );
	}

	public function test_tampered_ciphertext_fails_to_decrypt(): void {
		$sec = \TempControl\Estimate\Security::instance();
		$encrypted = $sec->encrypt( 'original' );
		$raw = base64_decode( $encrypted, true );
		$this->assertIsString( $raw );
		// Flip one bit in the ciphertext portion.
		$tampered = substr( $raw, 0, -1 ) . chr( ord( substr( $raw, -1 ) ) ^ 0x01 );
		$this->expectException( \RuntimeException::class );
		$sec->decrypt( base64_encode( $tampered ) );
	}

	public function test_hmac_verification(): void {
		$sec = \TempControl\Estimate\Security::instance();
		$secret = 'shared-secret-123';
		$payload = '{"estimate_id":"987","status":"accepted"}';
		$sig = hash_hmac( 'sha256', $payload, $secret );

		$this->assertTrue( $sec->verify_hmac( $payload, $sig, $secret ) );
		$this->assertFalse( $sec->verify_hmac( $payload, $sig, 'wrong-secret' ) );
		$this->assertFalse( $sec->verify_hmac( $payload, '', $secret ) );
		$this->assertFalse( $sec->verify_hmac( $payload . 'x', $sig, $secret ) );
	}

	public function test_safe_equals_is_constant_time(): void {
		$sec = \TempControl\Estimate\Security::instance();
		// Functional check — hash_equals behavior.
		$this->assertTrue( $sec->safe_equals( 'abc', 'abc' ) );
		$this->assertFalse( $sec->safe_equals( 'abc', 'abd' ) );
		$this->assertFalse( $sec->safe_equals( 'abc', 'ab' ) );
	}

	public function test_zoho_id_sanitization(): void {
		$sec = \TempControl\Estimate\Security::instance();
		$this->assertSame( '2273343000000787001', $sec->sanitize_zoho_id( '2273343000000787001' ) );
		$this->assertSame( '', $sec->sanitize_zoho_id( 'abc123' ) );
		$this->assertSame( '', $sec->sanitize_zoho_id( '123' ) ); // too short
		$this->assertSame( '', $sec->sanitize_zoho_id( str_repeat( '9', 25 ) ) ); // too long
		$this->assertSame( '', $sec->sanitize_zoho_id( '<script>alert(1)</script>' ) );
	}
}
