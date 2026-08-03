<?php
/**
 * GET /tc-estimate/v1/templates  and  GET /tc-estimate/v1/templates/{id}
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Endpoints;

use TempControl\Estimate\Template_CPT;
use TempControl\Estimate\Template_Meta;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class Get_Templates extends Endpoint_Base {

	private static ?Get_Templates $instance = null;

	public static function instance(): Get_Templates {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		register_rest_route( TC_ESTIMATE_REST_NS, '/templates', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_list' ),
			'permission_callback' => array( $this, 'permission_check' ),
			'args'                => array(
				'type' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		register_rest_route( TC_ESTIMATE_REST_NS, '/templates/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_single' ),
			'permission_callback' => array( $this, 'permission_check' ),
			'args'                => array(
				'id' => array( 'type' => 'integer', 'required' => true ),
			),
		) );
	}

	public function handle_list( WP_REST_Request $request ) {
		$type = (string) $request->get_param( 'type' );

		$query = array(
			'post_type'      => Template_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'no_found_rows'  => true,
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => Template_Meta::META_ACTIVE,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => Template_Meta::META_ACTIVE,
					'value'   => array( '1', 1, 'true', true ),
					'compare' => 'IN',
				),
			),
		);
		if ( '' !== $type ) {
			$query['meta_query'] = array(
				'relation' => 'AND',
				$query['meta_query'],
				array(
					'relation' => 'OR',
					array(
						'key'   => Template_Meta::META_TYPE,
						'value' => $type,
					),
					array(
						'key'   => '_tc_template_type',
						'value' => $type,
					),
				),
			);
		}

		$posts = get_posts( $query );
		$out = array();
		foreach ( $posts as $post ) {
			$row = Template_Meta::instance()->hydrate( $post->ID );
			if ( '' !== $type && $type !== $row['template_type'] ) {
				continue;
			}
			// Strip body from list response — keep it light.
			unset( $row['body'] );
			$out[] = $row;
		}
		return $this->ok( $out );
	}

	public function handle_single( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post || Template_CPT::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return $this->fail( new WP_Error( 'tc_estimate_not_found', __( 'Template not found.', 'tc-estimate' ), array( 'status' => 404 ) ) );
		}
		$row = Template_Meta::instance()->hydrate( $post->ID );
		if ( ! $row['active'] ) {
			return $this->fail( new WP_Error( 'tc_estimate_not_found', __( 'Template not found.', 'tc-estimate' ), array( 'status' => 404 ) ) );
		}
		$row['body'] = $post->post_content;
		return $this->ok( $row );
	}
}
