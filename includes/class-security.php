<?php
/**
 * Security helpers — nonces, HMAC, constant-time comparisons, sodium encryption, sanitizers.
 *
 * Covers pillars 01-04 (access control), 05-10 (timing), 11-14 (encryption).
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Security {

	private static ?Security $instance = null;

	private const NONCE_ACTION = 'wp_rest'; // Standard WP REST nonce action.

	private const OPT_ENCRYPTED_REFRESH_TOKEN = 'tc_estimate_zoho_refresh_token_enc';

	private const OPT_WEBHOOK_HMAC_SECRET = 'tc_estimate_webhook_secret';

	public static function instance(): Security {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Verify WP REST nonce on the current request.
	 */
	public function verify_rest_nonce(): bool {
		$nonce = $this->header( 'X-WP-Nonce' );
		if ( '' === $nonce ) {
			return false;
		}
		return (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION );
	}

	/**
	 * Capability check + nonce check in one call. Returns true or a WP_Error.
	 */
	public function gate_request( string $required_cap = TC_ESTIMATE_CAP ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'tc_estimate_not_logged_in', __( 'Authentication required.', 'tc-estimate' ), array( 'status' => 401 ) );
		}
		$allowed = TC_ESTIMATE_CAP === $required_cap
			? Capabilities::instance()->current_user_can_use()
			: current_user_can( $required_cap );
		if ( ! $allowed ) {
			return new WP_Error( 'tc_estimate_forbidden', __( 'Insufficient permissions.', 'tc-estimate' ), array( 'status' => 403 ) );
		}
		if ( ! $this->verify_rest_nonce() ) {
			return new WP_Error( 'tc_estimate_bad_nonce', __( 'Invalid or missing nonce.', 'tc-estimate' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Constant-time string comparison. Uses hash_equals which is timing-safe.
	 *
	 * Pillar 05-10: Timing Defense.
	 */
	public function safe_equals( string $known, string $user ): bool {
		return hash_equals( $known, $user );
	}

	/**
	 * Verify an HMAC-SHA256 signature over a payload body.
	 *
	 * @param string $payload   Raw request body.
	 * @param string $signature Hex-encoded signature from request header.
	 * @param string $secret    Shared secret.
	 */
	public function verify_hmac( string $payload, string $signature, string $secret ): bool {
		if ( '' === $secret || '' === $signature ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $payload, $secret );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Encrypt a secret using sodium_crypto_secretbox. Key must be 32 bytes.
	 * Returns base64-encoded (nonce || ciphertext).
	 *
	 * Pillar 11-14: Encryption of sensitive stored data.
	 *
	 * @throws \RuntimeException When libsodium is unavailable or key is invalid.
	 */
	public function encrypt( string $plaintext ): string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			throw new \RuntimeException( 'libsodium is required for secret encryption.' );
		}
		$key = $this->get_encryption_key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		return base64_encode( $nonce . $cipher );
	}

	/**
	 * Decrypt a value previously produced by encrypt().
	 *
	 * @throws \RuntimeException When decryption fails.
	 */
	public function decrypt( string $b64 ): string {
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			throw new \RuntimeException( 'libsodium is required for secret decryption.' );
		}
		$raw = base64_decode( $b64, true );
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			throw new \RuntimeException( 'Encrypted payload is malformed.' );
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $this->get_encryption_key() );
		if ( false === $plain ) {
			throw new \RuntimeException( 'Decryption failed — key mismatch or tampered ciphertext.' );
		}
		return $plain;
	}

	/**
	 * Store the Zoho refresh token, encrypted.
	 */
	public function set_zoho_refresh_token( string $token ): void {
		if ( '' === trim( $token ) ) {
			delete_option( self::OPT_ENCRYPTED_REFRESH_TOKEN );
			return;
		}
		$encrypted = $this->encrypt( $token );
		// autoload=no — we only need this on Zoho calls, not every page load.
		update_option( self::OPT_ENCRYPTED_REFRESH_TOKEN, $encrypted, false );
	}

	/**
	 * Retrieve and decrypt the Zoho refresh token. Returns empty string if not set.
	 */
	public function get_zoho_refresh_token(): string {
		$enc = get_option( self::OPT_ENCRYPTED_REFRESH_TOKEN, '' );
		if ( '' === $enc ) {
			return '';
		}
		try {
			return $this->decrypt( $enc );
		} catch ( \Throwable $e ) {
			// Don't leak decryption errors — log and return empty so calling code surfaces a setup error.
			error_log( '[tc-estimate] Zoho refresh token decrypt failed: ' . $e->getMessage() );
			return '';
		}
	}

	/**
	 * Get or generate the webhook HMAC secret (shared with Books webhook config).
	 */
	public function get_webhook_secret(): string {
		$secret = get_option( self::OPT_WEBHOOK_HMAC_SECRET, '' );
		if ( '' === $secret ) {
			$secret = bin2hex( random_bytes( 32 ) );
			update_option( self::OPT_WEBHOOK_HMAC_SECRET, $secret, false );
		}
		return $secret;
	}

	/**
	 * Sanitize a Zoho entity ID — must be numeric, 15-20 digits.
	 */
	public function sanitize_zoho_id( string $id ): string {
		$id = trim( $id );
		return preg_match( '/^[0-9]{15,20}$/', $id ) ? $id : '';
	}

	/**
	 * Sanitize rich text from a template or special-notes field.
	 */
	public function sanitize_rich_text( string $input, int $max_len = 10000 ): string {
		$clean = wp_kses_post( $input );
		if ( strlen( $clean ) > $max_len ) {
			$clean = substr( $clean, 0, $max_len );
		}
		return $clean;
	}

	/**
	 * Get a request header, case-insensitive, via apache_request_headers or $_SERVER fallback.
	 */
	private function header( string $name ): string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		if ( isset( $_SERVER[ $key ] ) ) {
			return (string) wp_unslash( $_SERVER[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		return '';
	}

	/**
	 * Resolve the 32-byte encryption key from constant or fallback to AUTH_KEY.
	 * Using a dedicated constant is strongly preferred — operators should define TC_ESTIMATE_ENC_KEY in wp-config.php.
	 */
	private function get_encryption_key(): string {
		if ( defined( 'TC_ESTIMATE_ENC_KEY' ) && is_string( TC_ESTIMATE_ENC_KEY ) ) {
			$raw = TC_ESTIMATE_ENC_KEY;
			// Allow base64-encoded 32-byte keys or hex 64-char keys or raw 32-byte binary.
			if ( ctype_xdigit( $raw ) && 64 === strlen( $raw ) ) {
				return hex2bin( $raw );
			}
			$decoded = base64_decode( $raw, true );
			if ( false !== $decoded && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $decoded ) ) {
				return $decoded;
			}
			// Fallback: hash it to 32 bytes. Better than refusing to run, but less preferred.
			return hash( 'sha256', $raw, true );
		}
		// Last resort — derive from WP salts. Functional but less robust to salt rotation.
		if ( defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) ) {
			return hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
		}
		throw new \RuntimeException( 'No encryption key available. Define TC_ESTIMATE_ENC_KEY in wp-config.php.' );
	}
}
