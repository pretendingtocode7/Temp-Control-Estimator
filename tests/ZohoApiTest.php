<?php
/**
 * Tests for the Zoho API bridge.
 *
 * These require the WP-PHPUnit integration mode (WP_TESTS_DIR set) because they exercise
 * WP options, transients, and wp_remote_request with a filter-based HTTP mock.
 *
 * Skipped automatically when run in standalone mode.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Tests;

use PHPUnit\Framework\TestCase;

final class ZohoApiTest extends TestCase {

	protected function setUp(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			$this->markTestSkipped( 'WP-PHPUnit required. Set WP_TESTS_DIR and re-run.' );
		}
	}

	public function test_circuit_breaker_opens_after_repeated_failures(): void {
		$api = \TempControl\Estimate\Zoho_API::instance();

		// Force repeated 500s from Zoho.
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
			return array(
				'response' => array( 'code' => 500, 'message' => 'Server Error' ),
				'body'     => '{"error":"synthetic"}',
				'headers'  => array(),
			);
		}, 10, 3 );

		// Seed a refresh token so auth doesn't short-circuit before HTTP layer.
		update_option( 'tc_estimate_zoho_client_id', 'x' );
		update_option( 'tc_estimate_zoho_client_secret', 'y' );
		\TempControl\Estimate\Security::instance()->set_zoho_refresh_token( 'fake-refresh-token' );

		// Exhaust the failure budget.
		for ( $i = 0; $i < 5; $i++ ) {
			$api->invalidate_access_token();
			$api->get( \TempControl\Estimate\Zoho_API::SERVICE_CRM, '/Accounts/123' );
		}

		// Next call should fail fast with circuit_open code.
		$result = $api->get( \TempControl\Estimate\Zoho_API::SERVICE_CRM, '/Accounts/123' );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'tc_estimate_zoho_circuit_open', $result->get_error_code() );
	}

	public function test_access_token_is_cached(): void {
		$api = \TempControl\Estimate\Zoho_API::instance();

		update_option( 'tc_estimate_zoho_client_id', 'x' );
		update_option( 'tc_estimate_zoho_client_secret', 'y' );
		\TempControl\Estimate\Security::instance()->set_zoho_refresh_token( 'fake-refresh-token' );
		$api->invalidate_access_token();

		// Count refresh-endpoint hits.
		$hits = 0;
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$hits ) {
			if ( str_contains( $url, '/oauth/v2/token' ) ) {
				$hits++;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'access_token' => 'TOK-' . $hits, 'expires_in' => 3600 ) ),
					'headers'  => array(),
				);
			}
			return $pre;
		}, 10, 3 );

		$t1 = $api->get_access_token();
		$t2 = $api->get_access_token();
		$this->assertSame( $t1, $t2 );
		$this->assertSame( 1, $hits, 'Refresh should be called exactly once for two consecutive requests.' );
	}
}
