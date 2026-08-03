<?php
/**
 * GET /tc-estimate/v1/customers
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Endpoints;

use TempControl\Estimate\Customer_Search;
use TempControl\Estimate\Rate_Limiter;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class Search_Customers extends Endpoint_Base {

	private static ?Search_Customers $instance = null;

	public static function instance(): Search_Customers {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		register_rest_route( TC_ESTIMATE_REST_NS, '/customers', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle' ),
			'permission_callback' => array( $this, 'permission_check' ),
			'args'                => array(
				'q'     => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'limit' => array( 'type' => 'integer', 'required' => false, 'default' => 20 ),
			),
		) );
	}

	public function handle( WP_REST_Request $request ) {
		$limited = Rate_Limiter::instance()->consume( 'customers' );
		if ( is_wp_error( $limited ) ) {
			return $this->fail( $limited );
		}

		$q = (string) $request->get_param( 'q' );
		$limit = (int) $request->get_param( 'limit' );

		$results = Customer_Search::instance()->search( $q, $limit );
		if ( is_wp_error( $results ) ) {
			return $this->fail( $results );
		}
		return $this->ok( $results );
	}
}
