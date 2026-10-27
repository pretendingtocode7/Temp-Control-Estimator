<?php
/** Generic, uncached customer shell. The bearer is read only from the fragment. */
declare( strict_types=1 );
namespace TempControl\Estimate\PublicSite;
defined( 'ABSPATH' ) || exit;
final class Customer_Choice_Page {
	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }
	public function register(): void { add_action( 'init', array( $this, 'maybe_render' ), -9999 ); }
	public function maybe_render(): void {
		if ( ! isset( $_GET['tc_estimate_choices'] ) || ! is_string( $_GET['tc_estimate_choices'] ) || '1' !== $_GET['tc_estimate_choices'] ) { return; }
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		$closed_policy = "default-src 'none'; script-src 'none'; script-src-attr 'none'; frame-src 'none'; worker-src 'none'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'; form-action 'none'";
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) { $this->response_headers( $closed_policy ); status_header( 405 ); header( 'Allow: GET, HEAD' ); exit; }
		$assets = $this->trusted_assets();
		if ( false === $assets ) {
			$this->response_headers( $closed_policy );
			header( 'Content-Type: text/plain; charset=UTF-8' ); status_header( 503 );
			if ( 'HEAD' !== $method ) { echo 'Equipment choices are temporarily unavailable. Please contact Temp Control.'; }
			exit;
		}
		$this->response_headers( "default-src 'none'; script-src " . $assets['script_url'] . "; script-src-attr 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-src 'none'; worker-src 'none'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'; form-action 'self'" );
		header( 'Content-Type: text/html; charset=UTF-8' ); status_header( 200 );
		if ( 'HEAD' === $method ) { exit; }
		$boot = array( 'restUrl' => esc_url_raw( rest_url( TC_ESTIMATE_REST_NS ) ), 'brand' => array( 'name' => 'Temp Control Heating & Air Conditioning' ) );
		$version = rawurlencode( TC_ESTIMATE_VERSION );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>Choose equipment — Temp Control</title><link rel="stylesheet" href="' . esc_url( $assets['style_url'] . '?ver=' . $version ) . '"></head><body><main id="tc-customer-choices-root" data-boot="' . esc_attr( wp_json_encode( $boot ) ) . '"><noscript>Please enable JavaScript to view your equipment choices.</noscript></main><script defer integrity="' . esc_attr( $assets['integrity'] ) . '" src="' . esc_url( $assets['script_url'] . '?ver=' . $version ) . '"></script></body></html>';
		exit;
	}
	private function response_headers( string $policy ): void {
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-transform, max-age=0' ); header( 'Pragma: no-cache' );
		header( 'Referrer-Policy: no-referrer' ); header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: ' . $policy );
	}
	/** Serve no customer application unless its exact local bundle can be verified. */
	private function trusted_assets(): array|false {
		$file = TC_ESTIMATE_PLUGIN_DIR . 'app/dist/estimate-builder.js';
		if ( ! is_file( $file ) || ! is_readable( $file ) ) { return false; }
		try { $hash = @hash_file( 'sha384', $file, true ); } catch ( \Throwable ) { return false; }
		if ( ! is_string( $hash ) || 48 !== strlen( $hash ) ) { return false; }
		$base = trailingslashit( TC_ESTIMATE_PLUGIN_URL ) . 'app/dist/';
		$url = esc_url_raw( $base . 'estimate-builder.js' );
		$parts = wp_parse_url( $url );
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) || 'https' !== ( $parts['scheme'] ?? '' ) || ( $parts['scheme'] ?? '' ) !== ( $home['scheme'] ?? '' ) || strtolower( (string) ( $parts['host'] ?? '' ) ) !== strtolower( (string) ( $home['host'] ?? '' ) ) || (int) ( $parts['port'] ?? 443 ) !== (int) ( $home['port'] ?? 443 ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || preg_match( '/[\x00-\x20\x7f;\x27]/', $url ) || ! str_ends_with( (string) ( $parts['path'] ?? '' ), '/app/dist/estimate-builder.js' ) ) { return false; }
		return array( 'script_url' => $url, 'style_url' => esc_url_raw( $base . 'estimate-builder.css' ), 'integrity' => 'sha384-' . base64_encode( $hash ) );
	}
}
