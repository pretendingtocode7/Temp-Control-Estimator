<?php
/**
 * Rate limiter tests (require WP-PHPUnit).
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Tests;

use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase {

	protected function setUp(): void {
		if ( ! function_exists( 'wp_set_current_user' ) ) {
			$this->markTestSkipped( 'WP-PHPUnit required.' );
		}
		$user_id = function_exists( 'self::factory' ) ? self::factory()->user->create() : 1;
		wp_set_current_user( $user_id );
	}

	public function test_consume_blocks_after_limit(): void {
		$rl = \TempControl\Estimate\Rate_Limiter::instance();
		// preview bucket: 30/minute.
		for ( $i = 0; $i < 30; $i++ ) {
			$result = $rl->consume( 'preview' );
			$this->assertTrue( $result, "Request $i should be allowed." );
		}
		$result = $rl->consume( 'preview' );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'tc_estimate_rate_limited', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertArrayHasKey( 'retry_after', $data );
	}

	public function test_unknown_bucket_passes_through(): void {
		$rl = \TempControl\Estimate\Rate_Limiter::instance();
		$this->assertTrue( $rl->consume( 'nonexistent_bucket' ) );
	}
}
