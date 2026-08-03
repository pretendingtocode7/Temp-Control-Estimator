<?php
/**
 * Thin wrapper around WP transients for Zoho responses.
 *
 * Pillar 20-25: Third-Party Resilience — caching reduces Zoho API pressure and insulates
 * the builder from short outages.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate;

defined( 'ABSPATH' ) || exit;

final class Zoho_Cache {

	private static ?Zoho_Cache $instance = null;

	private const KEY_PREFIX = 'tc_zoho_';

	/**
	 * Track keys we've set so flush_all() can clear them. Stored in one option to avoid DB scans.
	 */
	private const KEY_INDEX_OPTION = 'tc_estimate_cache_index';

	public static function instance(): Zoho_Cache {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Retrieve a value. Returns null on miss.
	 */
	public function get( string $key ): mixed {
		$value = get_transient( $this->key( $key ) );
		return false === $value ? null : $value;
	}

	/**
	 * Store a value. TTL in seconds.
	 */
	public function set( string $key, mixed $value, int $ttl ): void {
		set_transient( $this->key( $key ), $value, $ttl );
		$this->track( $key );
	}

	/**
	 * Delete a specific cache key.
	 */
	public function delete( string $key ): void {
		delete_transient( $this->key( $key ) );
		$this->untrack( $key );
	}

	/**
	 * Clear all Zoho cache entries we've set.
	 */
	public function flush_all(): void {
		$index = get_option( self::KEY_INDEX_OPTION, array() );
		if ( is_array( $index ) ) {
			foreach ( $index as $key ) {
				delete_transient( $this->key( $key ) );
			}
		}
		delete_option( self::KEY_INDEX_OPTION );
	}

	/**
	 * Remember-or-compute pattern. Runs $producer only on miss.
	 *
	 * @param string   $key
	 * @param int      $ttl
	 * @param callable $producer Returns the value to cache.
	 */
	public function remember( string $key, int $ttl, callable $producer ): mixed {
		$hit = $this->get( $key );
		if ( null !== $hit ) {
			return $hit;
		}
		$fresh = $producer();
		if ( null !== $fresh ) {
			$this->set( $key, $fresh, $ttl );
		}
		return $fresh;
	}

	private function key( string $key ): string {
		// Transient keys are limited to 172 chars — hash long keys for safety.
		$raw = self::KEY_PREFIX . $key;
		return strlen( $raw ) > 160 ? self::KEY_PREFIX . md5( $key ) : $raw;
	}

	private function track( string $key ): void {
		$index = get_option( self::KEY_INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			$index = array();
		}
		if ( ! in_array( $key, $index, true ) ) {
			$index[] = $key;
			// Cap at a reasonable size to prevent unbounded growth.
			if ( count( $index ) > 500 ) {
				$index = array_slice( $index, -500 );
			}
			update_option( self::KEY_INDEX_OPTION, $index, false );
		}
	}

	private function untrack( string $key ): void {
		$index = get_option( self::KEY_INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			return;
		}
		$filtered = array_values( array_filter( $index, fn( $k ) => $k !== $key ) );
		update_option( self::KEY_INDEX_OPTION, $filtered, false );
	}
}
