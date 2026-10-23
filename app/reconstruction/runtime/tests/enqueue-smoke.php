<?php
/** Standalone tests for the deployment-only worker response and script metadata. */
declare( strict_types=1 );

define( 'ABSPATH', '/' );
define( 'TC_ESTIMATE_VERSION', '0.4.10' );
$fixture = sys_get_temp_dir() . '/tc-estimate-enqueue-' . bin2hex( random_bytes( 6 ) ) . '/';
define( 'TC_ESTIMATE_PLUGIN_DIR', $fixture );
mkdir( $fixture . 'app/dist', 0700, true );
$worker_body = "/* fixed public worker fixture */\nself.addEventListener('install',()=>{});\n";
file_put_contents( $fixture . 'app/dist/service-worker.js', $worker_body );

$GLOBALS['test_home'] = 'https://example.test/';
$GLOBALS['test_permalink'] = 'https://example.test/estimate-builder/';
$GLOBALS['test_hooks'] = array();
function home_url( string $path = '' ): string { return $GLOBALS['test_home']; }
function get_permalink() { return $GLOBALS['test_permalink']; }
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
function esc_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function add_query_arg( array $args, string $url ): string { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ); }
function add_action( string $name, $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['test_hooks'][] = array( 'action', $name, $priority ); }
function add_filter( string $name, $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['test_hooks'][] = array( 'filter', $name, $priority ); }

require dirname( __DIR__ ) . '/public/class-enqueue.php';
$enqueue = \TempControl\Estimate\PublicSite\Enqueue::instance();
$assertions = 0;
function check( bool $condition, string $message ): void {
	global $assertions;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	$assertions++;
}
function request( $flag = '1', string $uri = '/?tc_estimate_sw=1&ver=0.4.10', string $method = 'GET' ): ?array {
	global $enqueue;
	$_GET = null === $flag ? array() : array( 'tc_estimate_sw' => $flag );
	$_SERVER['REQUEST_URI'] = $uri;
	$_SERVER['REQUEST_METHOD'] = $method;
	return $enqueue->service_worker_response();
}

try {
	$enqueue->register();
	check( in_array( array( 'action', 'init', 0 ), $GLOBALS['test_hooks'], true ), 'endpoint must register before template output' );
	foreach ( array( null, '', '0', '01', 'true', 1, array( '1' ), '1/../../wp-config.php' ) as $flag ) {
		check( null === request( $flag ), 'only the exact scalar string flag matches' );
	}
	foreach ( array( '/estimate-builder/?tc_estimate_sw=1', '/wp-admin/?tc_estimate_sw=1', '/wp-content/plugins/temp-control-estimate-builder/app/dist/service-worker.js?tc_estimate_sw=1', '//?tc_estimate_sw=1' ) as $uri ) {
		check( null === request( '1', $uri ), 'endpoint must not hijack other paths' );
	}
	unset( $_SERVER['REQUEST_URI'] );
	$_GET = array( 'tc_estimate_sw' => '1' );
	check( null === $enqueue->service_worker_response(), 'missing request path must not match' );
	$response = request();
	check( 200 === $response['status'], 'GET returns success' );
	check( $worker_body === $response['body'], 'GET serves exact fixed worker bytes' );
	check( 'application/javascript; charset=UTF-8' === $response['headers']['Content-Type'], 'JavaScript MIME required' );
	check( 'nosniff' === $response['headers']['X-Content-Type-Options'], 'MIME sniffing disabled on worker response' );
	check( str_contains( $response['headers']['Cache-Control'], 'no-store' ), 'worker response must not be cached' );
	check( '/' === $response['headers']['Service-Worker-Allowed'], 'root installation allows its home scope' );
	$old = request( '1', '/?tc_estimate_sw=1&ver=old&file=../../wp-config.php' );
	check( $worker_body === $old['body'], 'query never selects files and old versions receive current worker' );
	$head = request( '1', '/?tc_estimate_sw=1', 'HEAD' );
	check( 200 === $head['status'] && '' === $head['body'], 'HEAD sends no body' );
	check( $head['headers'] === $response['headers'], 'HEAD retains GET metadata' );
	foreach ( array( 'POST', 'OPTIONS', 'DELETE', '' ) as $method ) {
		$invalid = request( '1', '/?tc_estimate_sw=1', $method );
		check( 405 === $invalid['status'] && 'GET, HEAD' === $invalid['headers']['Allow'], 'unsupported methods are rejected' );
		check( ! str_contains( $invalid['body'], $worker_body ), 'unsupported methods do not serve worker bytes' );
	}
	$tag = '<script src="/plugin/app/dist/estimate-builder.js?ver=0.4.10"></script>';
	check( $tag === $enqueue->tag_bundle_script( $tag, 'other-script' ), 'unrelated script tags remain identical' );
	$marked = $enqueue->tag_bundle_script( $tag, 'tc-estimate-builder' );
	check( str_contains( $marked, 'data-tc-estimate-worker-url="https://example.test/?tc_estimate_sw=1&amp;ver=0.4.10"' ), 'bundle receives versioned PHP worker endpoint' );
	check( str_contains( $marked, 'data-tc-estimate-worker-scope="/estimate-builder/"' ), 'registration is restricted to the estimator page' );
	check( str_contains( $marked, ' data-tc-estimate-bundle ' ), 'original bundle marker remains present' );
	$GLOBALS['test_permalink'] = 'https://example.test/?page_id=123';
	check( str_contains( $enqueue->tag_bundle_script( $tag, 'tc-estimate-builder' ), 'data-tc-estimate-worker-scope="/"' ), 'query permalinks fall back to home scope' );
	$GLOBALS['test_home'] = 'https://example.test/subsite/';
	$GLOBALS['test_permalink'] = 'https://example.test/subsite/estimate-builder';
	check( null === request(), 'root path does not match a subdirectory site endpoint' );
	$sub = request( '1', '/subsite/?tc_estimate_sw=1' );
	check( 200 === $sub['status'] && '/subsite/' === $sub['headers']['Service-Worker-Allowed'], 'subdirectory endpoint allows only its home path' );
	$marked = $enqueue->tag_bundle_script( $tag, 'tc-estimate-builder' );
	check( str_contains( $marked, 'data-tc-estimate-worker-url="https://example.test/subsite/?tc_estimate_sw=1&amp;ver=0.4.10"' ), 'subdirectory worker endpoint retains home path' );
	check( str_contains( $marked, 'data-tc-estimate-worker-scope="/subsite/estimate-builder/"' ), 'subdirectory page scope has trailing slash' );
	$GLOBALS['test_permalink'] = false;
	check( str_contains( $enqueue->tag_bundle_script( $tag, 'tc-estimate-builder' ), 'data-tc-estimate-worker-scope="/subsite/"' ), 'missing permalink falls back to home path' );
	$GLOBALS['test_permalink'] = 'https://example.test/outside/';
	check( str_contains( $enqueue->tag_bundle_script( $tag, 'tc-estimate-builder' ), 'data-tc-estimate-worker-scope="/subsite/"' ), 'scope outside subsite home is not used' );
	$GLOBALS['test_home'] = 'https://example.test/a&b/';
	$GLOBALS['test_permalink'] = 'https://example.test/a&b/estimate-"builder"/';
	$escaped = $enqueue->tag_bundle_script( $tag, 'tc-estimate-builder' );
	check( str_contains( $escaped, 'https://example.test/a&amp;b/?tc_estimate_sw=1&amp;ver=0.4.10' ), 'URL attribute is HTML escaped' );
	check( str_contains( $escaped, '/a&amp;b/estimate-&quot;builder&quot;/' ), 'scope attribute is HTML escaped' );
	check( ! str_contains( $escaped, 'estimate-"builder"' ), 'raw quotes cannot escape metadata attributes' );
	$GLOBALS['test_home'] = 'https://example.test/a$1/';
	$GLOBALS['test_permalink'] = 'https://example.test/a$1/estimate-builder/';
	check( str_contains( $enqueue->tag_bundle_script( $tag, 'tc-estimate-builder' ), '/a$1/?tc_estimate_sw=1' ), 'dollar signs are not interpreted as replacement backreferences' );
	$GLOBALS['test_home'] = 'https://example.test/';
	$GLOBALS['test_permalink'] = 'https://example.test/estimate-builder/';
	unlink( $fixture . 'app/dist/service-worker.js' );
	$missing = request();
	check( 503 === $missing['status'], 'missing worker returns explicit service unavailable' );
	check( str_starts_with( $missing['body'], '/*' ) && ! str_contains( $missing['body'], $fixture ), 'missing response is safe JavaScript without private path disclosure' );
	check( str_contains( $missing['headers']['Cache-Control'], 'no-store' ), 'failure response must not be cached' );
	check( '' === request( '1', '/?tc_estimate_sw=1', 'HEAD' )['body'], 'missing HEAD still sends no body' );
	echo "Enqueue worker smoke: {$assertions} assertions passed.\n";
} finally {
	@unlink( $fixture . 'app/dist/service-worker.js' );
	@rmdir( $fixture . 'app/dist' );
	@rmdir( $fixture . 'app' );
	@rmdir( $fixture );
}
