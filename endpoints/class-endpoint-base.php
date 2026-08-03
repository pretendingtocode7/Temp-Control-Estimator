<?php
/**
 * Shared endpoint scaffolding — validation, error shaping, gate checks.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Endpoints;

use TempControl\Estimate\Security;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

abstract class Endpoint_Base {

	abstract public function register(): void;

	/**
	 * Standard permission callback — nonce + capability.
	 */
	public function permission_check( WP_REST_Request $request ): bool|WP_Error {
		return Security::instance()->gate_request();
	}

	/**
	 * Admin-only permission callback — also requires manage_options.
	 */
	public function admin_permission_check( WP_REST_Request $request ): bool|WP_Error {
		$gate = Security::instance()->gate_request( 'manage_options' );
		return $gate;
	}

	/**
	 * Shape a successful response with consistent envelope.
	 *
	 * @param mixed $data
	 */
	protected function ok( $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => true, 'data' => $data ), $status );
	}

	/**
	 * Convert a WP_Error to a WP_REST_Response with the right status.
	 */
	protected function fail( WP_Error $err ): WP_REST_Response {
		$status = 500;
		$data = $err->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			$status = (int) $data['status'];
		}
		$body = array(
			'ok'    => false,
			'error' => array(
				'code'    => $err->get_error_code(),
				'message' => $err->get_error_message(),
			),
		);
		if ( is_array( $data ) ) {
			foreach ( array( 'body', 'zoho_response', 'deluge_output' ) as $debug_key ) {
				if ( isset( $data[ $debug_key ] ) ) {
					$body['error'][ $debug_key ] = $data[ $debug_key ];
				}
			}
		}
		$response = new WP_REST_Response( $body, $status );
		if ( is_array( $data ) && isset( $data['retry_after'] ) ) {
			$response->header( 'Retry-After', (string) (int) $data['retry_after'] );
		}
		return $response;
	}

	/**
	 * Retrieve a JSON body. Returns the array or a WP_Error.
	 */
	protected function body( WP_REST_Request $request ): array|WP_Error {
		$body = $request->get_json_params();
		if ( null === $body || ! is_array( $body ) ) {
			return new WP_Error( 'tc_estimate_bad_body', __( 'Request body must be JSON.', 'tc-estimate' ), array( 'status' => 400 ) );
		}
		return $this->sanitize_item_descriptions( $body );
	}

	/**
	 * Sanitize optional per-line description overrides before preview, audit logging,
	 * template rendering, or estimate generation sees the payload.
	 */
	private function sanitize_item_descriptions( array $body ): array {
		if ( empty( $body['systems'] ) || ! is_array( $body['systems'] ) ) {
			return $body;
		}

		foreach ( $body['systems'] as $system_index => $system ) {
			if ( ! is_array( $system ) || empty( $system['equipment'] ) || ! is_array( $system['equipment'] ) ) {
				continue;
			}
			foreach ( $system['equipment'] as $slot => $item ) {
				if ( ! is_array( $item ) || ! array_key_exists( 'description', $item ) ) {
					continue;
				}
				$description = sanitize_textarea_field( (string) $item['description'] );
				$body['systems'][ $system_index ]['equipment'][ $slot ]['description'] = mb_substr( $description, 0, 2000 );
			}
		}

		return $body;
	}
}
