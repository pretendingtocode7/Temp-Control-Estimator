<?php
/**
 * Enqueues the React bundle + CSS on pages containing the shortcode, and serves the
 * service-worker.js file with the Service-Worker-Allowed header so it can claim a root scope.
 *
 * Only fires on singular pages that contain the [tc_estimate_builder] shortcode, so the
 * bundle is not loaded globally across the site.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\PublicSite;

defined( 'ABSPATH' ) || exit;

final class Enqueue {

	private static ?Enqueue $instance = null;

	public static function instance(): Enqueue {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_filter( 'script_loader_tag', array( $this, 'tag_bundle_script' ), 10, 2 );
		// The service worker file is served directly by WordPress when requested. To let
		// it claim a scope above its own directory, we add Service-Worker-Allowed: / on
		// that specific request.
		add_action( 'init', array( $this, 'maybe_serve_sw_headers' ) );
	}

	public function maybe_enqueue(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! has_shortcode( (string) $post->post_content, Shortcode::TAG ) ) {
			return;
		}

		$js  = TC_ESTIMATE_PLUGIN_DIR . 'app/dist/estimate-builder.js';
		$css = TC_ESTIMATE_PLUGIN_DIR . 'app/dist/estimate-builder.css';

		if ( file_exists( $js ) ) {
			wp_enqueue_script(
				'tc-estimate-builder',
				TC_ESTIMATE_PLUGIN_URL . 'app/dist/estimate-builder.js',
				array(),
				TC_ESTIMATE_VERSION . '-' . filemtime( $js ),
				true
			);
		}
		if ( file_exists( $css ) ) {
			wp_enqueue_style(
				'tc-estimate-builder',
				TC_ESTIMATE_PLUGIN_URL . 'app/dist/estimate-builder.css',
				array(),
				TC_ESTIMATE_VERSION . '-' . filemtime( $css )
			);
		}
	}

	/**
	 * Mark the bundle script tag with a data attribute so the SW registration helper
	 * can locate it and derive the service-worker.js URL without hard-coding paths.
	 *
	 * @param string $tag    Generated HTML for the script tag.
	 * @param string $handle Script handle.
	 */
	public function tag_bundle_script( string $tag, string $handle ): string {
		if ( 'tc-estimate-builder' !== $handle ) {
			return $tag;
		}
		// Insert data-tc-estimate-bundle after <script.
		return (string) preg_replace( '/^<script\b/', '<script data-tc-estimate-bundle', $tag, 1 );
	}

	/**
	 * If the current request is for our service-worker.js file, emit the
	 * Service-Worker-Allowed header so the browser will accept a root scope.
	 *
	 * This runs early on `init` so headers are sent before output.
	 */
	public function maybe_serve_sw_headers(): void {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( '' === $request_uri ) {
			return;
		}
		// Match /wp-content/plugins/temp-control-estimate-builder/app/dist/service-worker.js
		// regardless of query string or multisite prefix.
		if ( false === strpos( $request_uri, '/app/dist/service-worker.js' ) ) {
			return;
		}
		if ( false === strpos( $request_uri, 'temp-control-estimate-builder' ) ) {
			return;
		}
		// Only GETs of the file itself.
		if ( ! headers_sent() ) {
			header( 'Service-Worker-Allowed: /' );
		}
	}
}
