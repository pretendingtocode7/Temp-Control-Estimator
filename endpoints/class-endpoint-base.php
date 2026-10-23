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
		return $this->sanitize_estimate_input( $body );
	}

	/**
	 * Sanitize optional per-line description overrides before preview, audit logging,
	 * template rendering, or estimate generation sees the payload.
	 */
	private function sanitize_estimate_input( array $body ): array {
		if ( isset( $body['books_edits'] ) && is_array( $body['books_edits'] ) ) {
			$books_edits = $body['books_edits'];
			$sanitized_lines = array();
			foreach ( array_slice( (array) ( $books_edits['line_items'] ?? array() ), 0, 50 ) as $line ) {
				if ( ! is_array( $line ) ) {
					continue;
				}
				$sanitized_lines[] = array(
					'key'         => mb_substr( sanitize_key( (string) ( $line['key'] ?? '' ) ), 0, 100 ),
					'item_id'     => Security::instance()->sanitize_zoho_id( (string) ( $line['item_id'] ?? '' ) ),
					'name'        => mb_substr( sanitize_text_field( (string) ( $line['name'] ?? '' ) ), 0, 250 ),
					'description' => mb_substr( sanitize_textarea_field( (string) ( $line['description'] ?? '' ) ), 0, 2000 ),
				);
			}
			$body['books_edits'] = array(
				'line_items' => $sanitized_lines,
				'notes'      => mb_substr( sanitize_textarea_field( (string) ( $books_edits['notes'] ?? '' ) ), 0, 5000 ),
				'terms'      => mb_substr( sanitize_textarea_field( (string) ( $books_edits['terms'] ?? '' ) ), 0, 5000 ),
			);
		}

		if ( isset( $body['options'] ) && is_array( $body['options'] ) ) {
			foreach ( array( 'credit_card_fee_applies', 'financing_fee_applies', 'financing_requested' ) as $flag ) {
				if ( array_key_exists( $flag, $body['options'] ) ) {
					$body['options'][ $flag ] = $this->truthy( $body['options'][ $flag ] );
				}
			}
			foreach ( array( 'warranty_parts_years', 'warranty_labor_years' ) as $warranty ) {
				if ( array_key_exists( $warranty, $body['options'] ) ) {
					$body['options'][ $warranty ] = 10 === (int) $body['options'][ $warranty ] ? 10 : 0;
				}
			}
			if ( array_key_exists( 'special_notes', $body['options'] ) ) {
				$notes = sanitize_textarea_field( (string) $body['options']['special_notes'] );
				$body['options']['special_notes'] = mb_substr( $notes, 0, 2000 );
			}
		}

		if ( empty( $body['systems'] ) || ! is_array( $body['systems'] ) ) {
			return $body;
		}

		foreach ( $body['systems'] as $system_index => $system ) {
			if ( ! is_array( $system ) || empty( $system['equipment'] ) || ! is_array( $system['equipment'] ) ) {
				continue;
			}
			foreach ( $system['equipment'] as $slot => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				if ( array_key_exists( 'description', $item ) ) {
					$description = sanitize_textarea_field( (string) $item['description'] );
					$body['systems'][ $system_index ]['equipment'][ $slot ]['description'] = mb_substr( $description, 0, 2000 );
				}
				if ( 'permit' === (string) $slot && array_key_exists( 'rate', $item ) ) {
					$rate = round( (float) $item['rate'], 2 );
					$body['systems'][ $system_index ]['equipment'][ $slot ]['rate'] = max( 0, min( 100000, $rate ) );
				}
			}
		}

		return $body;
	}

	private function truthy( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 1 === (int) $value;
		}
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
