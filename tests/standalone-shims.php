<?php
/**
 * Minimal WordPress stand-ins for offline unit/smoke tests, loaded before plugin code.
 * These are not a replacement for the WordPress integration suite.
 */

declare( strict_types=1 );

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string { return $text; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string { return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
}
if ( ! function_exists( 'date_i18n' ) ) {
	function date_i18n( string $format, ?int $timestamp = null ): string { return gmdate( $format, $timestamp ?? time() ); }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ): string { return gmdate( 'Y-m-d H:i:s' ); }
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): object { return (object) array( 'ID' => 1, 'display_name' => 'Offline Test User' ); }
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
}
