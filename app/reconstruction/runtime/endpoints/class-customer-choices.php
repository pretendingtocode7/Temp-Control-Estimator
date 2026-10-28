<?php
/** Staff link lifecycle and scoped public customer selections. */
declare( strict_types=1 );
namespace TempControl\Estimate\Endpoints;
use TempControl\Estimate\Customer_Choices;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
defined( 'ABSPATH' ) || exit;

final class Customer_Choices_Endpoint extends Endpoint_Base {
	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }
	public function register(): void {
		foreach ( array( 'link', 'revoke', 'view', 'apply' ) as $action ) {
			register_rest_route( TC_ESTIMATE_REST_NS, '/customer-choices/' . $action, array(
				'methods' => 'POST', 'callback' => array( $this, $action ),
				'permission_callback' => in_array( $action, array( 'link', 'revoke' ), true ) ? array( $this, 'permission_check' ) : array( $this, 'public_permission' ),
			) );
		}
	}
	public function public_permission( WP_REST_Request $request ): bool|WP_Error {
		$origin = $request->get_header( 'origin' );
		$site = wp_parse_url( home_url( '/' ) );
		$expected = ( $site['scheme'] ?? 'https' ) . '://' . ( $site['host'] ?? '' ) . ( isset( $site['port'] ) ? ':' . $site['port'] : '' );
		$fetch_site = strtolower( (string) $request->get_header( 'sec_fetch_site' ) );
		if ( ! is_string( $origin ) || ! hash_equals( $expected, rtrim( $origin, '/' ) ) || ( '' !== $fetch_site && 'same-origin' !== $fetch_site ) ) {
			return new WP_Error( 'tc_estimate_choices_unavailable', __( 'This selection request is not allowed.', 'tc-estimate' ), array( 'status' => 403 ) );
		}
		if ( 1 !== preg_match( '#^application/json(?:\s*;|$)#i', (string) $request->get_header( 'content_type' ) ) || strlen( $request->get_body() ) > 8192 ) {
			return new WP_Error( 'tc_estimate_choices_bad_selection', __( 'The selection request is invalid.', 'tc-estimate' ), array( 'status' => 400 ) );
		}
		// Bound invalid-token traffic without trusting caller-supplied forwarding headers.
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
		$key = 'tc_choice_ip_' . hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
		$entry = get_transient( $key );
		if ( ! is_array( $entry ) || time() >= (int) ( $entry['until'] ?? 0 ) ) { $entry = array( 'count' => 0, 'until' => time() + 60 ); }
		$entry['count']++;
		set_transient( $key, $entry, max( 1, $entry['until'] - time() ) );
		if ( $entry['count'] > 120 ) { return new WP_Error( 'tc_estimate_choices_rate_limited', __( 'Too many requests. Please try again shortly.', 'tc-estimate' ), array( 'status' => 429 ) ); }
		return true;
	}
	public function link( WP_REST_Request $request ): WP_REST_Response {
		$body = $this->input( $request, array( 'estimate_id' ) );
		if ( is_wp_error( $body ) ) { return $this->safe_fail( $body ); }
		$result = Customer_Choices::instance()->link_for_estimate( (string) ( $body['estimate_id'] ?? '' ), get_current_user_id() );
		return is_wp_error( $result ) ? $this->safe_fail( $result ) : $this->private_ok( $result );
	}
	public function revoke( WP_REST_Request $request ): WP_REST_Response {
		$body = $this->input( $request, array( 'estimate_id' ) );
		if ( is_wp_error( $body ) ) { return $this->safe_fail( $body ); }
		$result = Customer_Choices::instance()->revoke_for_estimate( (string) ( $body['estimate_id'] ?? '' ), get_current_user_id() );
		return is_wp_error( $result ) ? $this->safe_fail( $result ) : $this->private_ok( $result );
	}
	public function view( WP_REST_Request $request ): WP_REST_Response {
		$body = $this->input( $request, array( 'token' ) );
		if ( is_wp_error( $body ) ) { return $this->safe_fail( $body ); }
		$result = Customer_Choices::instance()->view( (string) ( $body['token'] ?? '' ) );
		return is_wp_error( $result ) ? $this->safe_fail( $result ) : $this->private_ok( $result );
	}
	public function apply( WP_REST_Request $request ): WP_REST_Response {
		$body = $this->input( $request, array( 'token', 'revision', 'request_id', 'selections' ) );
		if ( is_wp_error( $body ) ) { return $this->safe_fail( $body ); }
		if ( ! is_string( $body['token'] ?? null ) || ! is_int( $body['revision'] ?? null ) || ! is_string( $body['request_id'] ?? null ) || ! is_array( $body['selections'] ?? null ) ) {
			return $this->safe_fail( new WP_Error( 'tc_estimate_choices_bad_selection', __( 'Choose one offered option for every system.', 'tc-estimate' ), array( 'status' => 400 ) ) );
		}
		$result = Customer_Choices::instance()->apply( $body['token'], $body['revision'], $body['request_id'], $body['selections'] );
		return is_wp_error( $result ) ? $this->safe_fail( $result ) : $this->private_ok( $result, empty( $result['saved'] ) && 'pending' === ( $result['state'] ?? '' ) ? 202 : 200 );
	}
	private function input( WP_REST_Request $request, array $keys ): array|WP_Error {
		if ( strlen( $request->get_body() ) > 8192 ) { return new WP_Error( 'tc_estimate_choices_bad_selection', __( 'The selection request is invalid.', 'tc-estimate' ), array( 'status' => 400 ) ); }
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || array_diff( array_keys( $body ), $keys ) ) { return new WP_Error( 'tc_estimate_choices_bad_selection', __( 'The selection request is invalid.', 'tc-estimate' ), array( 'status' => 400 ) ); }
		foreach ( $body as $key => $value ) {
			if ( in_array( $key, array( 'token', 'estimate_id', 'request_id' ), true ) && ! is_string( $value ) ) { return new WP_Error( 'tc_estimate_choices_bad_selection', __( 'The selection request is invalid.', 'tc-estimate' ), array( 'status' => 400 ) ); }
		}
		return $body;
	}
	private function private_ok( array $data, int $status = 200 ): WP_REST_Response { $r = $this->ok( $data, $status ); return $this->headers( $r ); }
	private function safe_fail( WP_Error $error ): WP_REST_Response {
		$status = (int) ( $error->get_error_data()['status'] ?? 400 );
		$r = new WP_REST_Response( array( 'ok' => false, 'error' => array( 'code' => $error->get_error_code(), 'message' => $error->get_error_message() ) ), $status );
		if ( 429 === $status ) { $r->header( 'Retry-After', '60' ); }
		return $this->headers( $r );
	}
	private function headers( WP_REST_Response $r ): WP_REST_Response {
		$r->header( 'Cache-Control', 'private, no-store, max-age=0' ); $r->header( 'Pragma', 'no-cache' );
		$r->header( 'Referrer-Policy', 'no-referrer' ); $r->header( 'X-Content-Type-Options', 'nosniff' );
		return $r;
	}
}
