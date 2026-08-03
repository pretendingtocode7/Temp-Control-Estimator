<?php
/**
 * Per-user rate limiter using transients.
 *
 * Pillar 20-25: Protect Zoho and internal endpoints from runaway clients.
 * Pillar 05-10: Uses constant-time increments via transient get/set.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Rate_Limiter {

	private static ?Rate_Limiter $instance = null;

	/**
	 * Default limits, indexable by endpoint name. Values: array of [limit, window_seconds].
	 */
	private array $limits = array(
		'generate'  => array( 5, 60 ),
		'generate_hourly' => array( 20, 3600 ),
		'preview'   => array( 30, 60 ),
		'send_estimate' => array( 5, 60 ),
		'customers' => array( 60, 60 ),
		'equipment' => array( 60, 60 ),
	);

	public static function instance(): Rate_Limiter {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Attempt to consume a request slot.
	 *
	 * @return true|WP_Error True on allowed, WP_Error with 429 status when blocked.
	 */
	public function consume( string $bucket ): bool|WP_Error {
		if ( ! isset( $this->limits[ $bucket ] ) ) {
			return true; // Unknown buckets pass through — prevents mistakes hard-blocking traffic.
		}
		[ $limit, $window ] = $this->limits[ $bucket ];
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error( 'tc_estimate_unauth', __( 'Authentication required.', 'tc-estimate' ), array( 'status' => 401 ) );
		}

		$key   = sprintf( 'tc_rl_%s_%d', $bucket, $user_id );
		$entry = get_transient( $key );

		if ( ! is_array( $entry ) || ! isset( $entry['count'], $entry['reset_at'] ) || time() >= (int) $entry['reset_at'] ) {
			$entry = array( 'count' => 0, 'reset_at' => time() + $window );
		}

		$entry['count']++;

		if ( $entry['count'] > $limit ) {
			$retry_after = max( 1, (int) $entry['reset_at'] - time() );
			return new WP_Error(
				'tc_estimate_rate_limited',
				__( 'Rate limit exceeded. Try again shortly.', 'tc-estimate' ),
				array( 'status' => 429, 'retry_after' => $retry_after )
			);
		}

		// Store remainder of window as TTL.
		$ttl = max( 1, (int) $entry['reset_at'] - time() );
		set_transient( $key, $entry, $ttl );
		return true;
	}

	/**
	 * Convenience — apply both limits for /generate (per-minute and per-hour).
	 */
	public function consume_generate(): bool|WP_Error {
		$minute = $this->consume( 'generate' );
		if ( is_wp_error( $minute ) ) {
			return $minute;
		}
		return $this->consume( 'generate_hourly' );
	}
}
