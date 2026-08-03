<?php
/**
 * POST /tc-estimate/v1/webhook/accepted
 *
 * Receives Zoho Books webhook when an estimate transitions to "accepted".
 * Phase 3 deliverable. Stub present now with full HMAC verification in place
 * so the signing secret pattern is established before Phase 3 adds business logic.
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

final class Acceptance_Webhook extends Endpoint_Base {

	private static ?Acceptance_Webhook $instance = null;

	public static function instance(): Acceptance_Webhook {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		register_rest_route( TC_ESTIMATE_REST_NS, '/webhook/accepted', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle' ),
			// Public endpoint — auth is HMAC signature, not WP session.
			'permission_callback' => '__return_true',
		) );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$signature = (string) $request->get_header( 'X-TC-Signature' );
		$raw_body  = (string) $request->get_body();
		$secret    = Security::instance()->get_webhook_secret();

		if ( ! Security::instance()->verify_hmac( $raw_body, $signature, $secret ) ) {
			// Constant-time check inside verify_hmac. Respond generically — don't help an attacker distinguish cases.
			return $this->fail( new WP_Error( 'tc_estimate_bad_signature', __( 'Invalid signature.', 'tc-estimate' ), array( 'status' => 401 ) ) );
		}

		// Phase 3 will parse the Books payload here and kick off the Deluge onEstimateAccepted function.
		return $this->fail( new WP_Error(
			'tc_estimate_not_implemented',
			__( 'Estimate acceptance handling is scheduled for Phase 3.', 'tc-estimate' ),
			array( 'status' => 501 )
		) );
	}
}
