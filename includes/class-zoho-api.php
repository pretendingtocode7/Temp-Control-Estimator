<?php
/**
 * Zoho API bridge.
 *
 * Handles:
 *   - OAuth2 refresh-token flow (refresh token encrypted at rest)
 *   - Access token caching (in-memory per request + transient across requests)
 *   - Retry-with-exponential-backoff (1s, 2s, 4s)
 *   - Circuit breaker after N consecutive failures (fails fast, no hammering Zoho)
 *   - Per-service base URLs (CRM, Books, Inventory)
 *
 * Pillars covered:
 *   11-14: Refresh token encrypted at rest (Security::encrypt).
 *   20-25: Retry, backoff, circuit breaker, bounded timeouts.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Zoho_API {

	private static ?Zoho_API $instance = null;

	/**
	 * Per-request access token cache — avoids decrypting and hitting the token transient repeatedly.
	 */
	private ?string $access_token = null;

	/**
	 * Expiry time of the per-request access token (unix seconds).
	 */
	private int $access_token_expires_at = 0;

	public const SERVICE_CRM      = 'crm';
	public const SERVICE_BOOKS    = 'books';
	public const SERVICE_INVENTORY = 'inventory';

	// Circuit-breaker tuning.
	private const CIRCUIT_FAILURE_THRESHOLD = 5;
	private const CIRCUIT_OPEN_SECONDS      = 60;
	private const CIRCUIT_OPT               = 'tc_estimate_zoho_circuit';

	// Access-token transient.
	private const ACCESS_TOKEN_TRANSIENT = 'tc_estimate_zoho_access_token';

	// Default timeout in seconds (connect + total).
	private const HTTP_TIMEOUT = 15;

	public static function instance(): Zoho_API {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * GET helper.
	 *
	 * @param string $service One of the SERVICE_* constants.
	 * @param string $path    Path relative to the service base (e.g. "/items/123").
	 * @param array  $query   Query string args.
	 */
	public function get( string $service, string $path, array $query = array() ): array|WP_Error {
		return $this->request( 'GET', $service, $path, $query, null );
	}

	public function post( string $service, string $path, array $body ): array|WP_Error {
		return $this->request( 'POST', $service, $path, array(), $body );
	}

	/**
	 * POST helper for Zoho endpoints that expect form parameters instead of JSON.
	 *
	 * CRM serverless functions are one of these endpoints: their docs require a form
	 * field named "arguments" containing a JSON string.
	 */
	public function post_form( string $service, string $path, array $body ): array|WP_Error {
		return $this->request( 'POST', $service, $path, array(), $body, 'form' );
	}

	public function put( string $service, string $path, array $body ): array|WP_Error {
		return $this->request( 'PUT', $service, $path, array(), $body );
	}

	/**
	 * Core request dispatcher. Handles retries and circuit breaker.
	 *
	 * @return array|WP_Error Decoded JSON body on success, WP_Error on failure.
	 */
	private function request( string $method, string $service, string $path, array $query, ?array $body, string $body_format = 'json' ): array|WP_Error {
		if ( $this->circuit_open() ) {
			return new WP_Error( 'tc_estimate_zoho_circuit_open', __( 'Zoho integration temporarily disabled after repeated failures. Retry in ~60s.', 'tc-estimate' ), array( 'status' => 503 ) );
		}

		$base = $this->base_url( $service );
		if ( '' === $base ) {
			return new WP_Error( 'tc_estimate_zoho_service', sprintf( __( 'Unknown Zoho service: %s', 'tc-estimate' ), $service ), array( 'status' => 500 ) );
		}

		$url = rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		// Three attempts: 1s, 2s, 4s backoff.
		$delays = array( 0, 1, 2, 4 );
		$last_error = null;

		foreach ( $delays as $i => $delay ) {
			if ( $delay > 0 ) {
				sleep( $delay );
			}

			$token = $this->get_access_token();
			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$args = array(
				'method'  => $method,
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array(
					'Authorization' => 'Zoho-oauthtoken ' . $token,
					'Accept'        => 'application/json',
				),
			);

			if ( null !== $body && 'json' === $body_format ) {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body']                    = wp_json_encode( $body );
			} elseif ( null !== $body ) {
				$args['body'] = $body;
			}

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				$this->record_failure();
				continue; // network error — retry.
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$raw  = (string) wp_remote_retrieve_body( $response );

			// 401 → access token likely expired mid-flight. Force refresh and retry once.
			if ( 401 === $code && $i < 2 ) {
				$this->invalidate_access_token();
				continue;
			}

			// 429 and 5xx are retryable.
			if ( 429 === $code || ( $code >= 500 && $code <= 599 ) ) {
				$message = sprintf( __( 'Zoho returned HTTP %d.', 'tc-estimate' ), $code );
				$decoded_error = json_decode( $raw, true );
				if ( is_array( $decoded_error ) ) {
					$zoho_message = (string) ( $decoded_error['message'] ?? $decoded_error['error'] ?? $decoded_error['code'] ?? '' );
					if ( '' !== $zoho_message ) {
						$message .= ' ' . $zoho_message;
					}
				} elseif ( '' !== trim( $raw ) ) {
					$message .= ' ' . mb_substr( trim( wp_strip_all_tags( $raw ) ), 0, 300 );
				}
				$last_error = new WP_Error(
					'tc_estimate_zoho_http',
					$message,
					array( 'status' => 502, 'body' => $raw )
				);
				$this->record_failure();
				continue;
			}

			// Any other 4xx is a real client error — don't retry.
			if ( $code >= 400 ) {
				$message = sprintf( __( 'Zoho returned HTTP %d.', 'tc-estimate' ), $code );
				$decoded_error = json_decode( $raw, true );
				if ( is_array( $decoded_error ) ) {
					$zoho_message = (string) ( $decoded_error['message'] ?? $decoded_error['error'] ?? $decoded_error['code'] ?? '' );
					if ( '' !== $zoho_message ) {
						$message .= ' ' . $zoho_message;
					}
					if ( ! empty( $decoded_error['details'] ) && is_array( $decoded_error['details'] ) ) {
						$detail_bits = array();
						foreach ( $decoded_error['details'] as $detail_key => $detail_value ) {
							if ( is_scalar( $detail_value ) ) {
								$detail_bits[] = sprintf( '%s=%s', (string) $detail_key, (string) $detail_value );
							}
						}
						if ( ! empty( $detail_bits ) ) {
							$message .= ' (' . implode( ', ', $detail_bits ) . ')';
						}
					}
				}
				return new WP_Error(
					'tc_estimate_zoho_client_error',
					$message,
					array( 'status' => $code, 'body' => $raw )
				);
			}

			// Success.
			$this->record_success();
			if ( '' === $raw ) {
				return array();
			}
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				return new WP_Error( 'tc_estimate_zoho_decode', __( 'Zoho response was not valid JSON.', 'tc-estimate' ), array( 'status' => 502 ) );
			}
			return $decoded;
		}

		return $last_error ?: new WP_Error( 'tc_estimate_zoho_unknown', __( 'Zoho request failed after retries.', 'tc-estimate' ), array( 'status' => 502 ) );
	}

	/**
	 * Get a valid access token. Refreshes from Zoho if cache is empty or expired.
	 */
	public function get_access_token(): string|WP_Error {
		// Per-request cache first.
		if ( null !== $this->access_token && $this->access_token_expires_at > time() + 30 ) {
			return $this->access_token;
		}

		// Cross-request transient.
		$cached = get_transient( self::ACCESS_TOKEN_TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached['token'] ) && (int) $cached['expires_at'] > time() + 30 ) {
			$this->access_token            = (string) $cached['token'];
			$this->access_token_expires_at = (int) $cached['expires_at'];
			return $this->access_token;
		}

		// Refresh from Zoho.
		return $this->refresh_access_token();
	}

	/**
	 * Invalidate the access token so the next call forces a refresh.
	 */
	public function invalidate_access_token(): void {
		$this->access_token            = null;
		$this->access_token_expires_at = 0;
		delete_transient( self::ACCESS_TOKEN_TRANSIENT );
	}

	/**
	 * Hit Zoho's OAuth endpoint to exchange the refresh token for a new access token.
	 */
	private function refresh_access_token(): string|WP_Error {
		$refresh = Security::instance()->get_zoho_refresh_token();
		$client_id = (string) get_option( 'tc_estimate_zoho_client_id', '' );
		$client_secret = (string) get_option( 'tc_estimate_zoho_client_secret', '' );
		$dc = (string) get_option( 'tc_estimate_zoho_dc', 'com' ); // e.g., 'com', 'eu', 'in'

		if ( '' === $refresh || '' === $client_id || '' === $client_secret ) {
			return new WP_Error( 'tc_estimate_zoho_not_configured', __( 'Zoho OAuth is not fully configured. See Estimate Builder → Settings.', 'tc-estimate' ), array( 'status' => 500 ) );
		}

		$endpoint = sprintf( 'https://accounts.zoho.%s/oauth/v2/token', $dc );

		$response = wp_remote_post( $endpoint, array(
			'timeout' => self::HTTP_TIMEOUT,
			'body'    => array(
				'refresh_token' => $refresh,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'grant_type'    => 'refresh_token',
			),
		) );

		if ( is_wp_error( $response ) ) {
			$this->record_failure();
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== $code || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$this->record_failure();
			$msg = is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : sprintf( 'HTTP %d', $code );
			return new WP_Error( 'tc_estimate_zoho_refresh_failed', sprintf( __( 'Zoho OAuth refresh failed: %s', 'tc-estimate' ), $msg ), array( 'status' => 502 ) );
		}

		$token      = (string) $data['access_token'];
		$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
		$expires_at = time() + $expires_in;

		$this->access_token            = $token;
		$this->access_token_expires_at = $expires_at;

		// Cache in transient for the token lifetime minus a safety margin.
		set_transient(
			self::ACCESS_TOKEN_TRANSIENT,
			array( 'token' => $token, 'expires_at' => $expires_at ),
			max( 60, $expires_in - 60 )
		);

		$this->record_success();
		return $token;
	}

	/**
	 * Return the base URL for a given Zoho service, taking DC into account.
	 */
	private function base_url( string $service ): string {
		$dc = (string) get_option( 'tc_estimate_zoho_dc', 'com' );
		return match ( $service ) {
			self::SERVICE_CRM       => sprintf( 'https://www.zohoapis.%s/crm/v7', $dc ),
			self::SERVICE_BOOKS     => sprintf( 'https://www.zohoapis.%s/books/v3', $dc ),
			self::SERVICE_INVENTORY => sprintf( 'https://www.zohoapis.%s/inventory/v1', $dc ),
			default                 => '',
		};
	}

	// -----------------------------------------------------------------------------
	// Circuit breaker — fail fast after N consecutive failures.
	// -----------------------------------------------------------------------------

	private function circuit_open(): bool {
		$state = get_option( self::CIRCUIT_OPT, array( 'failures' => 0, 'opened_at' => 0 ) );
		if ( ! is_array( $state ) ) {
			return false;
		}
		if ( (int) ( $state['failures'] ?? 0 ) < self::CIRCUIT_FAILURE_THRESHOLD ) {
			return false;
		}
		$age = time() - (int) ( $state['opened_at'] ?? 0 );
		if ( $age > self::CIRCUIT_OPEN_SECONDS ) {
			// Half-open — let one request through.
			update_option( self::CIRCUIT_OPT, array( 'failures' => self::CIRCUIT_FAILURE_THRESHOLD - 1, 'opened_at' => 0 ), false );
			return false;
		}
		return true;
	}

	private function record_failure(): void {
		$state = get_option( self::CIRCUIT_OPT, array( 'failures' => 0, 'opened_at' => 0 ) );
		if ( ! is_array( $state ) ) {
			$state = array( 'failures' => 0, 'opened_at' => 0 );
		}
		$state['failures']  = (int) ( $state['failures'] ?? 0 ) + 1;
		$state['opened_at'] = $state['failures'] >= self::CIRCUIT_FAILURE_THRESHOLD ? time() : 0;
		update_option( self::CIRCUIT_OPT, $state, false );
	}

	private function record_success(): void {
		update_option( self::CIRCUIT_OPT, array( 'failures' => 0, 'opened_at' => 0 ), false );
	}
}
