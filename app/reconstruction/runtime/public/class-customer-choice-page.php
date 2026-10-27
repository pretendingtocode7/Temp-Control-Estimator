<?php
/** Generic, uncached customer shell. The bearer is read only from the fragment. */
declare( strict_types=1 );
namespace TempControl\Estimate\PublicSite;
defined( 'ABSPATH' ) || exit;
final class Customer_Choice_Page {
	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }
	public function register(): void { add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 ); }
	public function maybe_render(): void {
		if ( ! isset( $_GET['tc_estimate_choices'] ) || '1' !== (string) $_GET['tc_estimate_choices'] ) { return; }
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) { status_header( 405 ); header( 'Allow: GET, HEAD' ); exit; }
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' ); header( 'Pragma: no-cache' );
		header( 'Referrer-Policy: no-referrer' ); header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'; form-action 'self'" );
		header( 'Content-Type: text/html; charset=UTF-8' ); status_header( 200 );
		if ( 'HEAD' === $method ) { exit; }
		$boot = array( 'restUrl' => esc_url_raw( rest_url( TC_ESTIMATE_REST_NS ) ), 'brand' => array( 'name' => 'Temp Control Heating & Air Conditioning' ) );
		$base = trailingslashit( TC_ESTIMATE_PLUGIN_URL ) . 'app/dist/';
		$version = rawurlencode( TC_ESTIMATE_VERSION );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>Choose equipment — Temp Control</title><link rel="stylesheet" href="' . esc_url( $base . 'estimate-builder.css?ver=' . $version ) . '"></head><body><main id="tc-customer-choices-root" data-boot="' . esc_attr( wp_json_encode( $boot ) ) . '"><noscript>Please enable JavaScript to view your equipment choices.</noscript></main><script defer src="' . esc_url( $base . 'estimate-builder.js?ver=' . $version ) . '"></script></body></html>';
		exit;
	}
}
