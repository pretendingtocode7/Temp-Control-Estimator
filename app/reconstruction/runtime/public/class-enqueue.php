<?php
/**
 * Deployment override reconstructed from the saved v0.4.8 enqueue class.
 * Serves only this plugin's fixed worker through WordPress, where response
 * headers can be set even when the host serves plugin assets directly.
 * The original runtime/reference file remains unchanged.
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
		add_action( 'init', array( $this, 'maybe_serve_service_worker' ), 0 );
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

	/** Add the fixed versioned worker URL and the current shortcode page's scope. */
	public function tag_bundle_script( string $tag, string $handle ): string {
		if ( 'tc-estimate-builder' !== $handle ) {
			return $tag;
		}
		$url = add_query_arg(
			array( 'tc_estimate_sw' => '1', 'ver' => TC_ESTIMATE_VERSION ),
			home_url( '/' )
		);
		$attributes = ' data-tc-estimate-bundle data-tc-estimate-worker-url="' . esc_attr( $url ) .
			'" data-tc-estimate-worker-scope="' . esc_attr( $this->registration_scope() ) . '"';
		// A callback avoids interpreting dollar signs in URL paths as backreferences.
		return (string) preg_replace_callback(
			'/^<script\b/',
			static fn (): string => '<script' . $attributes,
			$tag,
			1
		);
	}

	/**
	 * Build a response without emitting or exiting, so the complete route contract
	 * can be tested without WordPress or a real HTTP server. Query values never
	 * select a filesystem path; even an old version query serves the current file.
	 *
	 * @return array{status:int,headers:array<string,string>,body:string}|null
	 */
	public function service_worker_response(): ?array {
		if ( ! isset( $_GET['tc_estimate_sw'] ) || ! is_string( $_GET['tc_estimate_sw'] ) || '1' !== $_GET['tc_estimate_sw'] ) {
			return null;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		if ( $this->home_scope() !== wp_parse_url( $uri, PHP_URL_PATH ) ) {
			return null;
		}

		$headers = array(
			'Content-Type'           => 'application/javascript; charset=UTF-8',
			'Cache-Control'          => 'no-store, no-cache, must-revalidate, max-age=0',
			'Pragma'                 => 'no-cache',
			'Expires'                => 'Wed, 11 Jan 1984 05:00:00 GMT',
			'X-Content-Type-Options'  => 'nosniff',
			'Service-Worker-Allowed'  => $this->home_scope(),
		);
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			$headers['Allow'] = 'GET, HEAD';
			return array( 'status' => 405, 'headers' => $headers, 'body' => '/* Method not allowed. */' );
		}

		$path = TC_ESTIMATE_PLUGIN_DIR . 'app/dist/service-worker.js';
		$body = is_file( $path ) && is_readable( $path ) ? @file_get_contents( $path ) : false;
		$status = false === $body ? 503 : 200;
		if ( false === $body ) {
			$body = '/* Estimate Builder service worker is unavailable. */';
		}
		return array( 'status' => $status, 'headers' => $headers, 'body' => 'HEAD' === $method ? '' : $body );
	}

	/** Serve the dedicated public worker response before template output. */
	public function maybe_serve_service_worker(): void {
		$response = $this->service_worker_response();
		if ( null === $response ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		status_header( $response['status'] );
		foreach ( $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value, true );
		}
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed public JavaScript file.
		exit;
	}

	/** Maximum scope allowed by the dedicated endpoint, including subdirectory sites. */
	private function home_scope(): string {
		$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return is_string( $path ) && '' !== $path ? rtrim( $path, '/' ) . '/' : '/';
	}

	/** Scope clients to this shortcode page rather than unrelated pages on the site. */
	private function registration_scope(): string {
		$home = $this->home_scope();
		$permalink = get_permalink();
		$path = is_string( $permalink ) ? wp_parse_url( $permalink, PHP_URL_PATH ) : null;
		if ( ! is_string( $path ) || '' === $path || ! str_starts_with( $path, $home ) ) {
			return $home;
		}
		return rtrim( $path, '/' ) . '/';
	}
}
