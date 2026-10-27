<?php
/** Isolated synthetic WordPress shell fixture. Run one case per PHP process/runtime. */
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', '/' );
	define( 'TC_ESTIMATE_VERSION', '0.4.14' );
	define( 'TC_ESTIMATE_REST_NS', 'tc-estimate/v1' );
	define( 'TC_ESTIMATE_PLUGIN_DIR', '/plugin/' );
	$ccs_asset_urls = array(
		'cross_origin_asset' => 'https://other.example.test/wp-content/plugins/tc-estimate/',
		'credential_asset' => 'https://user:synthetic@fixture.example.test/wp-content/plugins/tc-estimate/',
		'query_asset' => 'https://fixture.example.test/wp-content/plugins/tc-estimate/?bad=1',
		'unsafe_asset' => 'https://fixture.example.test/wp-content/plugins/tc-estimate/ unsafe;script-src/',
	);
	define( 'TC_ESTIMATE_PLUGIN_URL', $ccs_asset_urls[ defined( 'CC_SHELL_CASE' ) ? CC_SHELL_CASE : '' ] ?? 'https://fixture.example.test/wp-content/plugins/tc-estimate/' );
	$GLOBALS['ccs_case'] = defined( 'CC_SHELL_CASE' ) ? CC_SHELL_CASE : 'get';
	$GLOBALS['ccs_headers'] = array( 'x-fixture-existing' => 'preserved' );
	$GLOBALS['ccs_status'] = null;
	$GLOBALS['ccs_hooks'] = array();
	$GLOBALS['ccs_calls'] = array();
	$GLOBALS['ccs_completed'] = false;

	function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool { $GLOBALS['ccs_hooks'][] = array( 'hook' => $hook, 'priority' => $priority, 'accepted_args' => $accepted_args, 'callback' => is_array( $callback ) ? get_class( $callback[0] ) . '::' . $callback[1] : 'synthetic' ); return true; }
	function remove_all_actions( mixed ...$args ): never { throw new \LogicException( 'Global actions must not be cleared by the customer shell.' ); }
	function remove_all_filters( mixed ...$args ): never { throw new \LogicException( 'Global filters must not be cleared by the customer shell.' ); }
	function status_header( int $status ): void { $GLOBALS['ccs_status'] = $status; $GLOBALS['ccs_calls'][] = 'status'; }
	function nocache_headers(): void { $GLOBALS['ccs_calls'][] = 'nocache'; $GLOBALS['ccs_headers']['cache-control'] = 'no-cache, must-revalidate, max-age=0'; $GLOBALS['ccs_headers']['expires'] = 'Wed, 11 Jan 1984 05:00:00 GMT'; }
	function home_url( string $path = '' ): string { return 'https://fixture.example.test' . $path; }
	function rest_url( string $path = '' ): string { return home_url( '/wp-json/' . $path ); }
	function esc_url_raw( string $value ): string { return filter_var( $value, FILTER_VALIDATE_URL ) ? $value : ''; }
	function esc_url( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
	function esc_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
	function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; }
	function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
	function wp_create_nonce( mixed ...$args ): never { throw new \LogicException( 'No staff nonce belongs in the customer shell.' ); }
	function wp_remote_request( mixed ...$args ): never { throw new \LogicException( 'No network belongs in the shell fixture.' ); }
	function wp_mail( mixed ...$args ): never { throw new \LogicException( 'No mail belongs in the shell fixture.' ); }
	function wp_enqueue_script( mixed ...$args ): never { throw new \LogicException( 'The isolated shell must emit only its fixed trusted asset.' ); }

	$ccs_queries = array(
		'get' => array( 'tc_estimate_choices' => '1' ), 'head' => array( 'tc_estimate_choices' => '1' ), 'post' => array( 'tc_estimate_choices' => '1' ),
		'missing_bundle' => array( 'tc_estimate_choices' => '1' ), 'unreadable_bundle' => array( 'tc_estimate_choices' => '1' ), 'hash_failure' => array( 'tc_estimate_choices' => '1' ), 'hash_throw' => array( 'tc_estimate_choices' => '1' ), 'cross_origin_asset' => array( 'tc_estimate_choices' => '1' ), 'credential_asset' => array( 'tc_estimate_choices' => '1' ), 'query_asset' => array( 'tc_estimate_choices' => '1' ), 'unsafe_asset' => array( 'tc_estimate_choices' => '1' ), 'missing_bundle_head' => array( 'tc_estimate_choices' => '1' ),
		'hook' => array(), 'unrelated_missing' => array(), 'unrelated_other' => array( 'tc_estimate_choices' => '2' ),
		'unrelated_array' => array( 'tc_estimate_choices' => array( '1' ) ), 'unrelated_integer' => array( 'tc_estimate_choices' => 1 ), 'unrelated_suffix' => array( 'tc_estimate_choices' => '1junk' ),
	);
	$_GET = $ccs_queries[ $GLOBALS['ccs_case'] ] ?? array();
	$_SERVER['REQUEST_METHOD'] = in_array( $GLOBALS['ccs_case'], array( 'head', 'missing_bundle_head' ), true ) ? 'HEAD' : ( 'post' === $GLOBALS['ccs_case'] ? 'POST' : 'GET' );
	ob_start();
	register_shutdown_function( static function (): void {
		$body = ob_get_contents(); if ( false !== $body ) { ob_end_clean(); }
		echo json_encode( array( 'php_version' => PHP_VERSION, 'case' => $GLOBALS['ccs_case'], 'status' => $GLOBALS['ccs_status'], 'headers' => $GLOBALS['ccs_headers'], 'hooks' => $GLOBALS['ccs_hooks'], 'calls' => $GLOBALS['ccs_calls'], 'returned' => $GLOBALS['ccs_completed'], 'body' => false === $body ? null : $body, 'last_error' => error_get_last() ), JSON_UNESCAPED_SLASHES );
	} );
}

namespace TempControl\Estimate\PublicSite {
	function header( string $value, bool $replace = true, int $status = 0 ): void {
		$GLOBALS['ccs_calls'][] = 'header';
		if ( $status ) { $GLOBALS['ccs_status'] = $status; }
		$parts = explode( ':', $value, 2 );
		if ( 2 === count( $parts ) ) { $key = strtolower( trim( $parts[0] ) ); $GLOBALS['ccs_headers'][ $key ] = trim( $parts[1] ); }
	}
	function header_remove( ?string $name = null ): void { if ( null === $name ) { throw new \LogicException( 'Customer shell must not remove unrelated headers.' ); } unset( $GLOBALS['ccs_headers'][ strtolower( $name ) ] ); }
	function headers_sent( ?string &$filename = null, ?int &$line = null ): bool { return false; }
	function is_readable( string $path ): bool { return 'unreadable_bundle' !== $GLOBALS['ccs_case'] && \is_readable( $path ); }
	function hash_file( string $algorithm, string $path, bool $binary = false ): string|false { if ( 'hash_throw' === $GLOBALS['ccs_case'] ) { throw new \RuntimeException( 'Synthetic hashing failure.' ); } return 'hash_failure' === $GLOBALS['ccs_case'] ? false : \hash_file( $algorithm, $path, $binary ); }
}

namespace {
	require dirname( __DIR__ ) . '/public/class-customer-choice-page.php';
	$ccs_page = \TempControl\Estimate\PublicSite\Customer_Choice_Page::instance();
	$ccs_page->register();
	if ( 'hook' !== $GLOBALS['ccs_case'] ) { $ccs_page->maybe_render(); }
	$GLOBALS['ccs_completed'] = true;
}
