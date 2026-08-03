<?php
/**
 * POST /tc-estimate/v1/generate
 *
 * Creates a Zoho Books estimate + CRM Deal for a validated payload.
 *
 * Flow:
 *   1. Rate-limit + nonce + capability checks.
 *   2. Parse + validate body (template_id, customer.zoho_account_id, systems[], pricing).
 *   3. Extract Idempotency-Key header. If a matching row exists and succeeded, return its result.
 *      If it exists and is still pending, return 409 Conflict (another request is in flight).
 *   4. Hydrate customer + equipment from Zoho.
 *   5. Render template body.
 *   6. Insert audit row as 'pending'.
 *   7. Call Estimate_Generator::generate() — which invokes the Deluge function.
 *   8. Update audit row to 'success' (with IDs) or 'error' (with message).
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Endpoints;

use TempControl\Estimate\Audit_Log;
use TempControl\Estimate\Customer_Search;
use TempControl\Estimate\Equipment_Catalog;
use TempControl\Estimate\Estimate_Generator;
use TempControl\Estimate\Rate_Limiter;
use TempControl\Estimate\Security;
use TempControl\Estimate\Template_CPT;
use TempControl\Estimate\Template_Meta;
use TempControl\Estimate\Token_Renderer;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class Generate_Estimate extends Endpoint_Base {

	private static ?Generate_Estimate $instance = null;

	/**
	 * Max time (seconds) that a 'pending' audit row blocks duplicate idempotency keys.
	 * Matches our Zoho call timeout (15s) + retry budget (~7s) + headroom.
	 */
	private const PENDING_STALE_SECONDS = 90;

	public static function instance(): Generate_Estimate {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		register_rest_route( TC_ESTIMATE_REST_NS, '/generate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle' ),
			'permission_callback' => array( $this, 'permission_check' ),
		) );
	}

	public function handle( WP_REST_Request $request ) {
		$started_at = microtime( true );

		$limited = Rate_Limiter::instance()->consume_generate();
		if ( is_wp_error( $limited ) ) {
			return $this->fail( $limited );
		}

		$body = $this->body( $request );
		if ( is_wp_error( $body ) ) {
			return $this->fail( $body );
		}

		// --- Idempotency key: required ---
		$idempotency_key = (string) $request->get_header( 'idempotency_key' );
		if ( '' === $idempotency_key ) {
			$idempotency_key = (string) $request->get_header( 'x_tc_idempotency_key' );
		}
		$idempotency_key = trim( $idempotency_key );
		if ( '' === $idempotency_key || strlen( $idempotency_key ) > 64 || ! preg_match( '/^[A-Za-z0-9\-_]+$/', $idempotency_key ) ) {
			return $this->fail( new WP_Error(
				'tc_estimate_bad_idem',
				__( 'Idempotency-Key header is required (alphanumeric + dash/underscore, ≤64 chars).', 'tc-estimate' ),
				array( 'status' => 400 )
			) );
		}

		// --- Idempotency lookup before doing any Zoho work ---
		$existing = Audit_Log::instance()->find_by_idempotency_key( $idempotency_key, get_current_user_id() );
		if ( null !== $existing ) {
			$replay = $this->maybe_replay( $existing );
			if ( null !== $replay ) {
				return $replay;
			}
		}

		// --- Parse + validate core fields ---
		$template_id = (int) ( $body['template_id'] ?? 0 );
		if ( $template_id <= 0 ) {
			return $this->fail( new WP_Error( 'tc_estimate_bad_template', __( 'template_id is required.', 'tc-estimate' ), array( 'status' => 400 ) ) );
		}

		$post = get_post( $template_id );
		if ( ! $post || Template_CPT::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return $this->fail( new WP_Error( 'tc_estimate_not_found', __( 'Template not found or unpublished.', 'tc-estimate' ), array( 'status' => 404 ) ) );
		}
		$template_meta = Template_Meta::instance()->hydrate( $post->ID );

		$customer_data = isset( $body['customer'] ) && is_array( $body['customer'] ) ? $body['customer'] : array();
		$account_id    = Security::instance()->sanitize_zoho_id( (string) ( $customer_data['zoho_account_id'] ?? '' ) );
		if ( '' === $account_id ) {
			return $this->fail( new WP_Error(
				'tc_estimate_bad_account',
				__( 'customer.zoho_account_id is required for generate. Create the account in CRM first, then retry.', 'tc-estimate' ),
				array( 'status' => 400 )
			) );
		}

		$systems = $body['systems'] ?? array();
		if ( ! is_array( $systems ) || empty( $systems ) ) {
			return $this->fail( new WP_Error( 'tc_estimate_bad_systems', __( 'systems[] must contain at least one system.', 'tc-estimate' ), array( 'status' => 400 ) ) );
		}

		$pricing = isset( $body['pricing'] ) && is_array( $body['pricing'] ) ? $body['pricing'] : array();
		if ( ! isset( $pricing['total'] ) || (float) $pricing['total'] <= 0 ) {
			return $this->fail( new WP_Error( 'tc_estimate_bad_pricing', __( 'pricing.total must be greater than zero.', 'tc-estimate' ), array( 'status' => 400 ) ) );
		}

		// --- Hydrate customer (strict: any error is a hard failure for /generate) ---
		$customer = Customer_Search::instance()->get_account( $account_id );
		if ( is_wp_error( $customer ) ) {
			return $this->fail( $customer );
		}

		// --- Hydrate every referenced item. Missing items are a hard error here (unlike /preview). ---
		$item_ids = $this->collect_item_ids( $systems );
		if ( empty( $item_ids ) ) {
			return $this->fail( new WP_Error( 'tc_estimate_no_equipment', __( 'At least one equipment item must be selected.', 'tc-estimate' ), array( 'status' => 400 ) ) );
		}
		$catalog_by_id = array();
		foreach ( $item_ids as $id ) {
			$item = Equipment_Catalog::instance()->get_item( $id );
			if ( is_wp_error( $item ) ) {
				return $this->fail( new WP_Error(
					'tc_estimate_item_missing',
					sprintf( __( 'Equipment item %s could not be loaded: %s', 'tc-estimate' ), $id, $item->get_error_message() ),
					array( 'status' => 400 )
				) );
			}
			$catalog_by_id[ $id ] = $item;
		}

		// --- Build view + render body ---
		$branding_overrides = get_option( 'tc_estimate_branding', array() );
		if ( ! is_array( $branding_overrides ) ) {
			$branding_overrides = array();
		}
		$view     = Token_Renderer::instance()->build_payload_view( $body, $customer, $catalog_by_id, $template_meta, $branding_overrides );
		$rendered = Token_Renderer::instance()->render( $post->post_content, $view );
		if ( is_wp_error( $rendered ) ) {
			return $this->fail( $rendered );
		}

		// --- Audit row: pending ---
		$audit_id = Audit_Log::instance()->record( array(
			'idempotency_key'  => $idempotency_key,
			'action'           => 'generate',
			'status'           => 'pending',
			'template_id'      => (int) $post->ID,
			'template_version' => (int) ( $template_meta['version'] ?? 0 ),
			'zoho_account_id'  => $account_id,
			'payload'          => $body,
		) );

		// --- Call the generator ---
		$result = Estimate_Generator::instance()->generate(
			$body,
			$customer,
			$view,
			$rendered,
			$template_meta,
			$idempotency_key
		);

		$duration_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );

		if ( is_wp_error( $result ) ) {
			Audit_Log::instance()->update( $audit_id, array(
				'status'        => 'error',
				'error_message' => $result->get_error_message(),
				'duration_ms'   => $duration_ms,
			) );
			return $this->fail( $result );
		}

		Audit_Log::instance()->update( $audit_id, array(
			'status'           => 'success',
			'zoho_estimate_id' => $result['estimate_id'],
			'zoho_deal_id'     => $result['deal_id'],
			'duration_ms'      => $duration_ms,
		) );

		return $this->ok( array(
			'estimate_id'      => $result['estimate_id'],
			'estimate_url'     => $result['estimate_url'],
			'deal_id'          => $result['deal_id'],
			'deal_url'         => $result['deal_url'],
			'subtotal'         => $result['subtotal'],
			'total'            => $result['total'],
			'books_email_sent' => ! empty( $result['books_email_sent'] ),
			'books_email_message' => (string) ( $result['books_email_message'] ?? '' ),
			'template_id'      => (int) $post->ID,
			'template_version' => (int) ( $template_meta['version'] ?? 0 ),
			'idempotency_key'  => $idempotency_key,
			'duration_ms'      => $duration_ms,
		), 201 );
	}

	/**
	 * Walk systems and return every unique item_id (string).
	 * @return string[]
	 */
	private function collect_item_ids( array $systems ): array {
		$ids = array();
		foreach ( $systems as $sys ) {
			if ( ! is_array( $sys ) || empty( $sys['equipment'] ) || ! is_array( $sys['equipment'] ) ) {
				continue;
			}
			foreach ( $sys['equipment'] as $slot ) {
				if ( is_array( $slot ) && ! empty( $slot['item_id'] ) ) {
					$ids[] = (string) $slot['item_id'];
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Handle idempotency replay.
	 *   - success row → return cached result.
	 *   - error row   → return 409 so the client chooses whether to retry with a fresh key.
	 *   - pending row newer than PENDING_STALE_SECONDS → 409 (another request in flight).
	 *   - pending row older → null (fall through, proceed as a retry; this happens when a prior
	 *     call crashed before recording success/error).
	 */
	private function maybe_replay( array $row ): mixed {
		$status = (string) ( $row['status'] ?? '' );

		if ( 'success' === $status ) {
			return $this->ok( array(
				'estimate_id'      => (string) $row['zoho_estimate_id'],
				'deal_id'          => (string) $row['zoho_deal_id'],
				'template_id'      => (int) $row['template_id'],
				'template_version' => (int) $row['template_version'],
				'idempotency_key'  => (string) $row['idempotency_key'],
				'replayed'         => true,
			), 200 );
		}

		if ( 'pending' === $status ) {
			$age = time() - strtotime( (string) $row['created_at'] . ' UTC' );
			if ( $age >= 0 && $age < self::PENDING_STALE_SECONDS ) {
				return $this->fail( new WP_Error(
					'tc_estimate_in_flight',
					__( 'An identical request is still being processed. Wait and retry with the same Idempotency-Key to replay its result.', 'tc-estimate' ),
					array( 'status' => 409, 'retry_after' => 5 )
				) );
			}
			// Stale pending — fall through, proceed as retry.
			return null;
		}

		if ( 'error' === $status ) {
			return $this->fail( new WP_Error(
				'tc_estimate_prior_error',
				sprintf(
					/* translators: %s: prior error message */
					__( 'A prior request with this Idempotency-Key failed: %s. Use a new key to retry.', 'tc-estimate' ),
					(string) $row['error_message']
				),
				array( 'status' => 409 )
			) );
		}

		return null;
	}
}
