<?php
/** Real Zoho transport with synthetic WordPress HTTP; no network or live credentials. */
declare( strict_types=1 );
namespace {
	define( 'ABSPATH', '/' );
	require dirname( __DIR__, 4 ) . '/tests/standalone-shims.php';
	$GLOBALS['transport_options'] = array();
	$GLOBALS['transport_transients'] = array();
	$GLOBALS['transport_queue'] = array();
	$GLOBALS['transport_calls'] = array();
	$GLOBALS['transport_refreshes'] = 0;
	function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['transport_options'][ $key ] ?? $default; }
	function update_option( string $key, mixed $value, mixed $autoload = null ): bool { $GLOBALS['transport_options'][ $key ] = $value; return true; }
	function get_transient( string $key ): mixed { return $GLOBALS['transport_transients'][ $key ] ?? false; }
	function set_transient( string $key, mixed $value, int $ttl = 0 ): bool { $GLOBALS['transport_transients'][ $key ] = $value; return true; }
	function delete_transient( string $key ): bool { unset( $GLOBALS['transport_transients'][ $key ] ); return true; }
	function wp_json_encode( mixed $data ): string|false { return json_encode( $data ); }
	function wp_remote_request( string $url, array $args ): array|WP_Error {
		if ( 'PUT' !== $args['method'] || ! str_starts_with( $url, 'https://' ) ) { throw new LogicException( 'Unexpected synthetic HTTP method.' ); }
		$GLOBALS['transport_calls'][] = array( 'method' => $args['method'], 'timeout' => $args['timeout'] );
		if ( ! $GLOBALS['transport_queue'] ) { throw new LogicException( 'An unexpected write retry occurred.' ); }
		return array_shift( $GLOBALS['transport_queue'] );
	}
	function wp_remote_post( string $url, array $args ): array {
		if ( 'https://accounts.zoho.com/oauth/v2/token' !== $url ) { throw new LogicException( 'Only a synthetic OAuth refresh is permitted.' ); }
		$GLOBALS['transport_refreshes']++;
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'access_token' => 'synthetic-refreshed-access', 'expires_in' => 3600 ) ) );
	}
	function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
	function wp_remote_retrieve_body( array $response ): string { return (string) ( $response['body'] ?? '' ); }
	function response( int $code, mixed $body = array( 'code' => 0 ) ): array { return array( 'response' => array( 'code' => $code ), 'body' => is_string( $body ) ? $body : json_encode( $body ) ); }
}
namespace TempControl\Estimate {
	function sleep( int $seconds ): never { throw new \LogicException( 'Customer choices must never back off and repeat a write.' ); }
	final class Security {
		public static function instance(): self { static $self; return $self ??= new self(); }
		public function get_zoho_refresh_token(): string { return 'synthetic-refresh-not-a-live-secret'; }
	}
}
namespace {
	require dirname( __DIR__ ) . '/includes/class-zoho-api.php';
	$passed = 0;
	$failed = 0;
	$assertions = 0;
	function check( bool $condition, string $message ): void { $GLOBALS['assertions']++; if ( ! $condition ) { throw new RuntimeException( $message ); } }
	function fixture( array $queue ): \TempControl\Estimate\Zoho_API {
		$GLOBALS['transport_options'] = array( 'tc_estimate_zoho_dc' => 'com', 'tc_estimate_zoho_client_id' => 'synthetic-client', 'tc_estimate_zoho_client_secret' => 'synthetic-secret' );
		$GLOBALS['transport_transients'] = array( 'tc_estimate_zoho_access_token' => array( 'token' => 'synthetic-cached-access', 'expires_at' => time() + 3600 ) );
		$GLOBALS['transport_queue'] = $queue; $GLOBALS['transport_calls'] = array(); $GLOBALS['transport_refreshes'] = 0;
		$p = new ReflectionProperty( \TempControl\Estimate\Zoho_API::class, 'instance' ); $p->setAccessible( true ); $p->setValue( null, null );
		return \TempControl\Estimate\Zoho_API::instance();
	}
	function run_case( string $name, callable $case ): void { try { $case(); $GLOBALS['passed']++; echo "PASS $name\n"; } catch ( Throwable $error ) { $GLOBALS['failed']++; echo 'FAIL ' . $name . ': ' . $error->getMessage() . "\n"; } }
	foreach ( array( 'network' => new WP_Error( 'fixture_timeout', 'Synthetic timeout.' ), 'server' => response( 500 ), 'rate limit' => response( 429 ), 'client' => response( 400 ), 'invalid JSON' => response( 200, 'invalid' ) ) as $name => $reply ) {
		run_case( $name . ' does not repeat PUT', static function () use ( $reply ): void {
			$api = fixture( array( $reply ) ); $result = $api->put_once( 'books', '/estimates/400000000000000001?organization_id=600000000000000001&send=false', array( 'line_items' => array() ) );
			check( is_wp_error( $result ), 'Expected controlled transport error.' ); check( 1 === count( $GLOBALS['transport_calls'] ), 'Exactly one write attempt.' ); check( 0 === $GLOBALS['transport_refreshes'], 'No unrequested refresh.' ); check( 15 === $GLOBALS['transport_calls'][0]['timeout'], 'Existing bounded timeout retained.' );
		} );
	}
	run_case( 'successful PUT returns provider data once', static function (): void { $api = fixture( array( response( 200, array( 'code' => 0, 'estimate' => array( 'total' => 100 ) ) ) ) ); $r = $api->put_once( 'books', '/estimates/400000000000000001', array() ); check( 100 === $r['estimate']['total'], 'Decoded data.' ); check( 1 === count( $GLOBALS['transport_calls'] ), 'One write.' ); } );
	run_case( 'explicit unauthorized response permits one token refresh', static function (): void { $api = fixture( array( response( 401 ), response( 200 ) ) ); $r = $api->put_once( 'crm', '/Deals/500000000000000001', array() ); check( ! is_wp_error( $r ), 'Refreshed authentication succeeded.' ); check( 2 === count( $GLOBALS['transport_calls'] ), 'Only rejected request and one refresh retry.' ); check( 1 === $GLOBALS['transport_refreshes'], 'One refresh.' ); } );
	run_case( 'repeated unauthorized response stops after refresh', static function (): void { $api = fixture( array( response( 401 ), response( 401 ) ) ); $r = $api->put_once( 'crm', '/Deals/500000000000000001', array() ); check( is_wp_error( $r ), 'Controlled auth failure.' ); check( 2 === count( $GLOBALS['transport_calls'] ), 'No third write.' ); } );
	run_case( 'server error after refresh is not replayed', static function (): void { $api = fixture( array( response( 401 ), response( 503 ) ) ); $r = $api->put_once( 'crm', '/Deals/500000000000000001', array() ); check( is_wp_error( $r ), 'Uncertain server failure retained.' ); check( 2 === count( $GLOBALS['transport_calls'] ), 'No transient retry after refresh.' ); check( 1 === $GLOBALS['transport_refreshes'], 'One refresh.' ); } );
	run_case( 'CRM timeout never appends again', static function (): void { $api = fixture( array( new WP_Error( 'fixture_timeout', 'Synthetic timeout.' ) ) ); $r = $api->put_once( 'crm', '/Deals/500000000000000001', array( 'data' => array( array( 'Quoted_Equipment' => array( array( 'Name' => 'New slot' ) ) ) ), 'trigger' => array() ) ); check( is_wp_error( $r ), 'Unknown outcome retained.' ); check( 1 === count( $GLOBALS['transport_calls'] ), 'No duplicate append.' ); } );
	echo sprintf( "Customer choice transport: %d cases passed, %d failed; %d assertions passed.\n", $passed, $failed, $assertions );
	exit( $failed > 0 ? 1 : 0 );
}
