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
				'selector' => array( 'type' => 'boolean', 'required' => false, 'default' => false ),
				'category' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key', 'enum' => array( 'package', 'ac', 'furnace', 'coil' ) ),
				'category2' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'enum' => array_merge( array( '' ), Equipment_Catalog::CATEGORY2_VALUES ) ),
				'btu' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'tons' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'efficiency' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'page' => array( 'type' => 'integer', 'required' => false, 'default' => 1, 'minimum' => 1 ),
			),
		) );

		// Fixed optional proposal items have a separate authenticated lookup.
		register_rest_route( TC_ESTIMATE_REST_NS, '/equipment/addons', array(
			'methods' => 'GET',
			'callback' => array( $this, 'handle_addons' ),
			'permission_callback' => array( $this, 'permission_check' ),
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

		$catalog = Equipment_Catalog::instance();
		if ( true === $request->get_param( 'selector' ) ) {
			$results = $catalog->search_selector( array(
				'category' => (string) ( $request->get_param( 'category' ) ?? 'package' ),
				'category2' => $request->get_param( 'category2' ),
				'q' => (string) $request->get_param( 'q' ),
				'brand' => (string) $request->get_param( 'brand' ),
				'btu' => $request->get_param( 'btu' ),
				'tons' => $request->get_param( 'tons' ),
				'efficiency' => $request->get_param( 'efficiency' ),
				'page' => $request->get_param( 'page' ),
				'limit' => $request->get_param( 'limit' ),
			) );
		} else {
			// Retain the array contract used by existing clients and catalog warm-up.
			$results = $catalog->search( array(
				'type' => (string) $request->get_param( 'type' ),
				'q' => (string) $request->get_param( 'q' ),
				'brand' => (string) $request->get_param( 'brand' ),
				'limit' => (int) $request->get_param( 'limit' ),
			) );
		}

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

	public function handle_addons( WP_REST_Request $request ) {
		$limited = Rate_Limiter::instance()->consume( 'equipment' );
		if ( is_wp_error( $limited ) ) {
			return $this->fail( $limited );
		}
		return $this->ok( Equipment_Catalog::instance()->proposal_addons() );
	}
}
