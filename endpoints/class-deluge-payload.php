<?php
/**
 * GET /tc-estimate/v1/deluge-payload/{token}
 *
 * One-time payload retrieval for Zoho Deluge. Zoho's function execute endpoint accepts
 * arguments reliably in the query string, but full estimate payloads can exceed URL limits.
 * The PHP side stores the payload briefly and sends Deluge only this opaque token.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Endpoints;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class Deluge_Payload extends Endpoint_Base {

	private static ?Deluge_Payload $instance = null;

	public static function instance(): Deluge_Payload {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		register_rest_route( TC_ESTIMATE_REST_NS, '/deluge-payload/(?P<token>[A-Za-z0-9_-]{32,128})', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'token' => array( 'type' => 'string', 'required' => true ),
			),
		) );
	}

	public function handle( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		$key   = 'tc_estimate_deluge_payload_' . $token;
		$payload = get_transient( $key );

		if ( ! is_string( $payload ) || '' === $payload ) {
			return $this->fail( new WP_Error(
				'tc_estimate_deluge_payload_missing',
				__( 'Deluge payload token is missing or expired.', 'tc-estimate' ),
				array( 'status' => 404 )
			) );
		}

		delete_transient( $key );
		return $this->ok( array( 'payload' => $payload ) );
	}
}
