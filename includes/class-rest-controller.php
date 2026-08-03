<?php
/**
 * Registers all REST routes under /wp-json/tc-estimate/v1.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate;

use TempControl\Estimate\Endpoints;

defined( 'ABSPATH' ) || exit;

final class Rest_Controller {

	private static ?Rest_Controller $instance = null;

	public static function instance(): Rest_Controller {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'disable_cache' ), 10, 3 );
	}

	public function register_routes(): void {
		Endpoints\Search_Customers::instance()->register();
		Endpoints\Search_Equipment::instance()->register();
		Endpoints\Get_Templates::instance()->register();
		Endpoints\Preview_Estimate::instance()->register();
		Endpoints\Deluge_Payload::instance()->register();
		Endpoints\Generate_Estimate::instance()->register();
		Endpoints\Acceptance_Webhook::instance()->register();
	}

	/**
	 * Send Cache-Control: private, no-store on all our REST responses.
	 *
	 * Prevents WP Engine / CDN caching from serving stale customer or equipment data.
	 */
	public function disable_cache( \WP_HTTP_Response $response, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_HTTP_Response {
		if ( str_starts_with( (string) $request->get_route(), '/' . TC_ESTIMATE_REST_NS ) ) {
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
		}
		return $response;
	}
}
