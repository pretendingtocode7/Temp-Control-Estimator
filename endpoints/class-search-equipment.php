<?php
/**
 * GET /tc-estimate/v1/equipment
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Endpoints;

use TempControl\Estimate\Equipment_Catalog;
use TempControl\Estimate\Rate_Limiter;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class Search_Equipment extends Endpoint_Base {

	private static ?Search_Equipment $instance = null;

	public static function instance(): Search_Equipment {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		register_rest_route( TC_ESTIMATE_REST_NS, '/equipment', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle' ),
			'permission_callback' => array( $this, 'permission_check' ),
			'args'                => array(
				'type'  => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
				'q'     => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'brand' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'limit' => array( 'type' => 'integer', 'required' => false, 'default' => 50 ),
			),
		) );

		// Individual item fetch.
		register_rest_route( TC_ESTIMATE_REST_NS, '/equipment/(?P<id>[0-9]+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_single' ),
			'permission_callback' => array( $this, 'permission_check' ),
			'args'                => array(
				'id' => array( 'type' => 'string', 'required' => true ),
			),
		) );
	}

	public function handle( WP_REST_Request $request ) {
		$limited = Rate_Limiter::instance()->consume( 'equipment' );
		if ( is_wp_error( $limited ) ) {
			return $this->fail( $limited );
		}

		$results = Equipment_Catalog::instance()->search( array(
			'type'  => (string) $request->get_param( 'type' ),
			'q'     => (string) $request->get_param( 'q' ),
			'brand' => (string) $request->get_param( 'brand' ),
			'limit' => (int) $request->get_param( 'limit' ),
		) );

		if ( is_wp_error( $results ) ) {
			return $this->fail( $results );
		}
		return $this->ok( $results );
	}

	public function handle_single( WP_REST_Request $request ) {
		$limited = Rate_Limiter::instance()->consume( 'equipment' );
		if ( is_wp_error( $limited ) ) {
			return $this->fail( $limited );
		}
		$item = Equipment_Catalog::instance()->get_item( (string) $request->get_param( 'id' ) );
		if ( is_wp_error( $item ) ) {
			return $this->fail( $item );
		}
		return $this->ok( $item );
	}
}
