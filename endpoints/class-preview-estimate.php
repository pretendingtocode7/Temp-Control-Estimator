<?php
/**
 * POST /tc-estimate/v1/preview
 *
 * Renders a template against a payload and returns the rendered HTML. Does NOT call Zoho to create anything.
 *
 * This is the Phase 1 success gate — office staff can prove template language works end to end by
 * POSTing a Brian Balson style payload and getting a correctly rendered proposal body back.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Endpoints;

use TempControl\Estimate\Customer_Search;
use TempControl\Estimate\Equipment_Catalog;
use TempControl\Estimate\Rate_Limiter;
use TempControl\Estimate\Security;
use TempControl\Estimate\Template_CPT;
use TempControl\Estimate\Template_Meta;
use TempControl\Estimate\Token_Renderer;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class Preview_Estimate extends Endpoint_Base {

	private static ?Preview_Estimate $instance = null;

	public static function instance(): Preview_Estimate {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		register_rest_route( TC_ESTIMATE_REST_NS, '/preview', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle' ),
			'permission_callback' => array( $this, 'permission_check' ),
		) );
	}

	public function handle( WP_REST_Request $request ) {
		$limited = Rate_Limiter::instance()->consume( 'preview' );
		if ( is_wp_error( $limited ) ) {
			return $this->fail( $limited );
		}

		$body = $this->body( $request );
		if ( is_wp_error( $body ) ) {
			return $this->fail( $body );
		}

		// --- Validate + fetch template ---
		$template_id = (int) ( $body['template_id'] ?? 0 );
		if ( $template_id <= 0 ) {
			return $this->fail( new WP_Error( 'tc_estimate_bad_template', __( 'template_id is required.', 'tc-estimate' ), array( 'status' => 400 ) ) );
		}

		$post = get_post( $template_id );
		if ( ! $post || Template_CPT::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return $this->fail( new WP_Error( 'tc_estimate_not_found', __( 'Template not found or unpublished.', 'tc-estimate' ), array( 'status' => 404 ) ) );
		}
		$template_meta = Template_Meta::instance()->hydrate( $post->ID );
		$template_body = $post->post_content;

		// --- Validate + fetch customer ---
		$customer_data = isset( $body['customer'] ) && is_array( $body['customer'] ) ? $body['customer'] : array();
		$account_id    = Security::instance()->sanitize_zoho_id( (string) ( $customer_data['zoho_account_id'] ?? '' ) );

		$customer = array();
		if ( '' !== $account_id ) {
			$fetched = Customer_Search::instance()->get_account( $account_id );
			if ( is_wp_error( $fetched ) ) {
				// For preview we soft-fail — continue with empty customer so template authors can work without
				// a valid Zoho connection. The /generate endpoint is stricter.
				$customer = array();
			} else {
				$customer = $fetched;
			}
		}

		// Allow full override of customer when operating in preview-only mode (useful for tests).
		if ( ! empty( $customer_data['override'] ) && is_array( $customer_data['override'] ) ) {
			$customer = array_merge( $customer, $customer_data['override'] );
		}

		// --- Build the catalog lookup for every referenced item_id ---
		$item_ids = $this->collect_item_ids( $body['systems'] ?? array() );
		$catalog_by_id = array();
		foreach ( $item_ids as $id ) {
			$item = Equipment_Catalog::instance()->get_item( $id );
			if ( ! is_wp_error( $item ) ) {
				$catalog_by_id[ $id ] = $item;
			}
			// If the item fetch fails we simply skip it — the template will render without that slot filled.
			// /generate treats missing items as a hard error.
		}

		// --- Build view + render ---
		$branding_overrides = get_option( 'tc_estimate_branding', array() );
		if ( ! is_array( $branding_overrides ) ) {
			$branding_overrides = array();
		}

		$view = Token_Renderer::instance()->build_payload_view( $body, $customer, $catalog_by_id, $template_meta, $branding_overrides );

		$rendered = Token_Renderer::instance()->render( $template_body, $view );
		if ( is_wp_error( $rendered ) ) {
			return $this->fail( $rendered );
		}

		return $this->ok( array(
			'html'             => $rendered,
			'view'             => $view,
			'template_id'      => $template_id,
			'template_version' => (int) $template_meta['version'],
		) );
	}

	/**
	 * Walk the systems array and pull out every item_id.
	 *
	 * @param mixed $systems
	 * @return string[]
	 */
	private function collect_item_ids( mixed $systems ): array {
		if ( ! is_array( $systems ) ) {
			return array();
		}
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
}
