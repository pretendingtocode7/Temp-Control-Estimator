<?php
/**
 * Standalone tests of the Package reporting-tag catalog release override.
 * Uses synthetic Books responses and an in-memory cache; no HTTP is possible.
 * Run in an isolated process: php app/reconstruction/runtime/tests/catalog-smoke.php
 */
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', '/' );
	// Deliberately larger than policy: the override must cap list TTL at 900s.
	define( 'TC_ESTIMATE_CACHE_TTL_CATALOG', 43200 );
	$GLOBALS['catalog_test_options'] = array();
	function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['catalog_test_options'][ $key ] ?? $default; }
	function sanitize_key( string $value ): string { return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
	function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function __( string $message, string $domain = '' ): string { return $message; }
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
}

namespace TempControl\Estimate {
	/** Installed before the real catalog class, so it cannot load a real API adapter. */
	final class Zoho_API {
		public const SERVICE_BOOKS = 'books';
		private static ?self $instance = null;
		public array $routes = array();
		public array $calls = array();
		public static function instance(): self { return self::$instance ??= new self(); }
		public function get( string $service, string $path, array $query = array() ): array|\WP_Error {
			if ( self::SERVICE_BOOKS !== $service ) { throw new \LogicException( 'Catalog must use the Books boundary.' ); }
			$this->calls[] = array( 'service' => $service, 'path' => $path, 'query' => $query );
			$org = (string) ( $query['organization_id'] ?? '' );
			$key = $org . '|' . $path;
			if ( ! array_key_exists( $key, $this->routes ) ) {
				throw new \LogicException( 'Unexpected synthetic API request: ' . $key );
			}
			$route = $this->routes[ $key ];
			return is_callable( $route ) ? $route( $query ) : $route;
		}
	}

	final class Zoho_Cache {
		private static ?self $instance = null;
		public array $values = array();
		public array $reads = array();
		public array $writes = array();
		public static function instance(): self { return self::$instance ??= new self(); }
		public function get( string $key ): mixed { $this->reads[] = $key; return $this->values[ $key ] ?? null; }
		public function set( string $key, mixed $value, int $ttl ): void {
			$this->writes[] = array( 'key' => $key, 'value' => $value, 'ttl' => $ttl );
			$this->values[ $key ] = $value;
		}
		public function delete( string $key ): void { unset( $this->values[ $key ] ); }
	}
}

namespace {
	use TempControl\Estimate\Equipment_Catalog;
	use TempControl\Estimate\Zoho_API;
	use TempControl\Estimate\Zoho_Cache;

	require dirname( __DIR__ ) . '/includes/class-equipment-catalog.php';

	const ORG_A = '60000000001';
	const ORG_B = '60000000002';
	const TAG_A = '70000000001';
	const TAG_B = '70000000002';
	const OPTION_A = '80000000001';
	const OPTION_B = '80000000002';
	const ITEM_A = '90000000001';
	const ITEM_B = '90000000002';
	$assertions = 0;
	$passed = 0;
	$failed = 0;

	function check_catalog( bool $condition, string $message ): void {
		global $assertions;
		if ( ! $condition ) { throw new RuntimeException( $message ); }
		$assertions++;
	}
	function same_catalog( mixed $expected, mixed $actual, string $message ): void {
		check_catalog( $expected === $actual, $message . ' (expected ' . json_encode( $expected ) . ', received ' . json_encode( $actual ) . ')' );
	}
	function catalog_error( mixed $result, string $code, int $status ): void {
		check_catalog( is_wp_error( $result ), 'Expected a fail-closed WP_Error.' );
		same_catalog( $code, $result->get_error_code(), 'Error code' );
		same_catalog( $status, $result->get_error_data()['status'] ?? null, 'HTTP status' );
	}
	function new_catalog_request(): Equipment_Catalog {
		$property = new ReflectionProperty( Equipment_Catalog::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
		return Equipment_Catalog::instance();
	}
	function route_catalog( string $path, mixed $response, string $org = ORG_A ): void {
		Zoho_API::instance()->routes[ $org . '|' . $path ] = $response;
	}
	function configure_catalog_org( string $org = ORG_A, string $tag = TAG_A, string $option = OPTION_A ): void {
		route_catalog( '/reportingtags', array( 'code' => 0, 'tags' => array(
			array( 'tag_id' => $tag, 'tag_name' => 'Package', 'is_active' => true ),
			array( 'tag_id' => '70000000999', 'tag_name' => 'Package Tier', 'is_active' => true ),
		) ), $org );
		route_catalog( '/reportingtags/' . $tag . '/options/all', array( 'results' => array(
			array( 'option_id' => $option, 'option_name' => 'Package' ),
			array( 'option_id' => 'untagged', 'option_name' => 'Untagged' ),
		) ), $org );
		route_catalog( '/items', catalog_page( array() ), $org );
	}
	function reset_catalog_test(): Equipment_Catalog {
		$GLOBALS['catalog_test_options'] = array( 'tc_estimate_zoho_org_id' => ORG_A );
		$api = Zoho_API::instance();
		$api->routes = $api->calls = array();
		$cache = Zoho_Cache::instance();
		$cache->values = $cache->reads = $cache->writes = array();
		configure_catalog_org();
		return new_catalog_request();
	}
	function catalog_tag( string $tag = TAG_A, string $option = OPTION_A ): array {
		return array( 'tag_id' => $tag, 'tag_name' => 'Package', 'tag_option_id' => $option, 'tag_option_name' => 'Package' );
	}
	function catalog_item( string $id = ITEM_A, array $overrides = array() ): array {
		return array_replace( array(
			'item_id' => $id, 'name' => 'Synthetic Furnace', 'sku' => 'FIXTURE-SKU',
			'rate' => 3200, 'status' => 'active', 'cf_equipment_type' => 'furnace',
			'cf_brand' => 'Fixture Brand', 'cf_model' => 'FIXTURE-80', 'description' => 'Synthetic scope',
			'tags' => array( catalog_tag() ),
		), $overrides );
	}
	function catalog_page( array $items, bool $has_more = false, int $page = 1 ): array {
		return array( 'code' => 0, 'items' => $items, 'page_context' => array( 'page' => $page, 'has_more_page' => $has_more ) );
	}
	function catalog_key( string $org = ORG_A, string $tag = TAG_A, string $option = OPTION_A ): string {
		return 'books_items_package_tag_v1_' . $org . '_' . $tag . '_' . $option;
	}
	function catalog_calls( string $path, ?string $org = null ): array {
		return array_values( array_filter( Zoho_API::instance()->calls, static fn ( array $call ): bool =>
			$call['path'] === $path && ( null === $org || $call['query']['organization_id'] === $org )
		) );
	}
	function run_catalog_case( string $name, callable $case ): void {
		global $passed, $failed;
		try { $case(); $passed++; echo "PASS " . $name . "\n"; }
		catch ( Throwable $error ) { $failed++; echo "FAIL " . $name . ': ' . $error->getMessage() . "\n"; }
	}

	run_catalog_case( 'Package tag replaces checkbox for true, false and absent values', function (): void {
		$catalog = reset_catalog_test();
		$items = array(
			catalog_item( ITEM_A, array( 'cf_for_estimate' => true ) ),
			catalog_item( ITEM_B, array( 'cf_for_estimate' => false ) ),
			catalog_item( '90000000003' ),
		);
		route_catalog( '/items', catalog_page( $items ) );
		$result = $catalog->search();
		same_catalog( array_column( $items, 'item_id' ), array_column( $result, 'item_id' ), 'Every correctly tagged active item is eligible' );
		foreach ( $result as $item ) {
			same_catalog( true, $item['for_estimate'], 'Derived eligibility must be a strict boolean' );
			same_catalog( true, $item['package_tagged'], 'Tag marker must be a strict boolean' );
			same_catalog( TAG_A, $item['package_tag_id'], 'Resolved tag ID retained' );
			same_catalog( OPTION_A, $item['package_tag_option_id'], 'Resolved option ID retained' );
		}
		$writes = Zoho_Cache::instance()->writes;
		same_catalog( 1, count( $writes ), 'Only a complete list is cached' );
		same_catalog( catalog_key(), $writes[0]['key'], 'List cache includes policy, org and exact tag/option IDs' );
		check_catalog( $writes[0]['ttl'] > 0 && $writes[0]['ttl'] <= 900, 'Long legacy TTL is capped at fifteen minutes' );
	} );

	run_catalog_case( 'Checkbox, names, Package Tier and composite hints cannot grant eligibility', function (): void {
		$catalog = reset_catalog_test();
		$false_positives = array(
			array( 'tags' => array(), 'cf_for_estimate' => true ),
			array( 'tags' => array(), 'name' => 'Package', 'item_type' => 'package', 'is_combo_product' => true ),
			array( 'tags' => array(), 'custom_fields' => array( array( 'api_name' => 'cf_for_estimate', 'value' => true ) ) ),
			array( 'tags' => array( catalog_tag( '70000000999', OPTION_A ) ) ),
			array( 'tags' => array( catalog_tag( TAG_A, '80000000999' ) ) ),
			array( 'tags' => array( array( 'tag_name' => 'Package', 'tag_option_name' => 'Package' ) ) ),
			array( 'tags' => array( catalog_tag(), catalog_tag() ) ),
			array( 'tags' => array( catalog_tag(), catalog_tag( TAG_A, '80000000999' ) ) ),
			array( 'status' => 'inactive' ), array( 'status' => '' ), array( 'status' => null ),
		);
		$items = array();
		foreach ( $false_positives as $index => $overrides ) {
			$id = '91' . str_pad( (string) $index, 9, '0', STR_PAD_LEFT );
			$item = catalog_item( $id, $overrides );
			$items[] = $item;
			route_catalog( '/items/' . $id, array( 'code' => 0, 'item' => $item ) );
			catalog_error( $catalog->get_item( $id ), 'tc_estimate_item_not_eligible', 400 );
		}
		route_catalog( '/items', catalog_page( $items ) );
		same_catalog( array(), $catalog->search(), 'List filtering uses the same strict eligibility rule' );
	} );

	run_catalog_case( 'Assigned IDs are authoritative and unrelated tags do not interfere', function (): void {
		$catalog = reset_catalog_test();
		$assignment = catalog_tag();
		$assignment['tag_name'] = 'Outdated display label';
		$assignment['tag_option_name'] = 'Outdated option label';
		$item = catalog_item( ITEM_A, array( 'tags' => array( catalog_tag( '70000000999', '80000000999' ), $assignment ) ) );
		route_catalog( '/items/' . ITEM_A, array( 'item' => $item ) );
		same_catalog( ITEM_A, $catalog->get_item( ITEM_A )['item_id'], 'Matching resolved IDs allow the item despite stale item-side labels' );
	} );

	run_catalog_case( 'detail cannot authorize a missing or mismatched provider item ID', function (): void {
		$catalog = reset_catalog_test();
		foreach ( array( ITEM_B, '', null, (int) ITEM_A ) as $returned_id ) {
			route_catalog( '/items/' . ITEM_A, array( 'code' => 0, 'item' => catalog_item( ITEM_A, array( 'item_id' => $returned_id ) ) ) );
			catalog_error( $catalog->get_item( ITEM_A ), 'tc_estimate_catalog_invalid_response', 502 );
		}
		same_catalog( array(), Zoho_Cache::instance()->writes, 'Malformed detail never persists eligibility' );
	} );

	run_catalog_case( 'list excludes invalid raw item IDs even when Package tagged', function (): void {
		$catalog = reset_catalog_test();
		route_catalog( '/items', catalog_page( array(
			catalog_item( ITEM_A ), catalog_item( '', array() ),
			catalog_item( 'short' ), catalog_item( ITEM_B, array( 'item_id' => (int) ITEM_B ) ),
		) ) );
		same_catalog( array( ITEM_A ), array_column( $catalog->search(), 'item_id' ), 'Invalid raw identity cannot reach the picker or cache' );
		same_catalog( array( ITEM_A ), array_column( Zoho_Cache::instance()->writes[0]['value'], 'item_id' ), 'Only valid identified rows are cached' );
	} );

	run_catalog_case( 'get_item refreshes eligibility twice in one request and ignores normalized caches', function (): void {
		$catalog = reset_catalog_test();
		$route_count = 0;
		route_catalog( '/items/' . ITEM_A, function () use ( &$route_count ): array {
			$route_count++;
			return array( 'item' => catalog_item( ITEM_A, $route_count > 1 ? array( 'tags' => array(), 'cf_for_estimate' => true ) : array() ) );
		} );
		Zoho_Cache::instance()->values['books_item_for_estimate_' . ORG_A . '_' . ITEM_A] = array( 'item_id' => ITEM_A, 'for_estimate' => true );
		same_catalog( ITEM_A, $catalog->get_item( ITEM_A )['item_id'], 'Initially tagged item is returned' );
		catalog_error( $catalog->get_item( ITEM_A ), 'tc_estimate_item_not_eligible', 400 );
		same_catalog( 2, count( catalog_calls( '/items/' . ITEM_A ) ), 'Fresh detail fetched on every authorization, without singleton reset' );
		same_catalog( 1, count( catalog_calls( '/reportingtags' ) ), 'Successful metadata is memoized in this request' );
		same_catalog( 1, count( catalog_calls( '/reportingtags/' . TAG_A . '/options/all' ) ), 'Successful option resolution is memoized' );
		same_catalog( array(), Zoho_Cache::instance()->reads, 'Detail never consults any item/list cache' );
		same_catalog( array(), Zoho_Cache::instance()->writes, 'Detail never persists normalized authorization' );
	} );

	$bad_tag_metadata = array(
		'missing Package' => array( 'tags' => array( array( 'tag_id' => TAG_A, 'tag_name' => 'Package Tier', 'is_active' => true ) ) ),
		'inactive Package' => array( 'tags' => array( array( 'tag_id' => TAG_A, 'tag_name' => 'Package', 'is_active' => false ) ) ),
		'ambiguous Package' => array( 'tags' => array( array( 'tag_id' => TAG_A, 'tag_name' => 'Package', 'is_active' => true ), array( 'tag_id' => TAG_B, 'tag_name' => 'Package', 'is_active' => true ) ) ),
		'invalid tag ID' => array( 'tags' => array( array( 'tag_id' => 'untagged', 'tag_name' => 'Package', 'is_active' => true ) ) ),
		'missing tags collection' => array( 'code' => 0 ),
		'provider metadata error' => array( 'code' => 42, 'tags' => array() ),
	);
	foreach ( $bad_tag_metadata as $label => $response ) {
		run_catalog_case( 'fails closed for ' . $label, function () use ( $response ): void {
			$catalog = reset_catalog_test();
			route_catalog( '/reportingtags', $response );
			catalog_error( $catalog->search(), 'tc_estimate_package_tag_unavailable', 503 );
			same_catalog( array(), catalog_calls( '/items' ), 'No item list is exposed before metadata verification' );
			same_catalog( array(), Zoho_Cache::instance()->writes, 'Unverifiable metadata is not cached' );
		} );
	}
	$bad_options = array(
		'missing option' => array( 'results' => array( array( 'option_id' => 'untagged', 'option_name' => 'Untagged' ) ) ),
		'Package Tier option is not Package' => array( 'results' => array( array( 'option_id' => OPTION_A, 'option_name' => 'Package Tier' ) ) ),
		'ambiguous option' => array( 'results' => array( array( 'option_id' => OPTION_A, 'option_name' => 'Package' ), array( 'option_id' => OPTION_B, 'option_name' => 'Package' ) ) ),
		'invalid option ID' => array( 'results' => array( array( 'option_id' => 'untagged', 'option_name' => 'Package' ) ) ),
		'inactive option' => array( 'results' => array( array( 'option_id' => OPTION_A, 'option_name' => 'Package', 'is_active' => false ) ) ),
		'missing results collection' => array( 'code' => 0 ),
	);
	foreach ( $bad_options as $label => $response ) {
		run_catalog_case( 'fails closed for ' . $label, function () use ( $response ): void {
			$catalog = reset_catalog_test();
			route_catalog( '/reportingtags/' . TAG_A . '/options/all', $response );
			catalog_error( $catalog->search(), 'tc_estimate_package_tag_unavailable', 503 );
			same_catalog( array(), catalog_calls( '/items' ), 'No unverified option can expose the catalog' );
			same_catalog( array(), Zoho_Cache::instance()->writes, 'Invalid options never populate cache' );
		} );
	}

	foreach ( array( '/reportingtags', '/reportingtags/' . TAG_A . '/options/all' ) as $path ) {
		run_catalog_case( 'propagates metadata transport errors and allows recovery: ' . $path, function () use ( $path ): void {
			$catalog = reset_catalog_test();
			$error = new WP_Error( 'synthetic_transport', 'Synthetic unavailable provider', array( 'status' => 502 ) );
			route_catalog( $path, $error );
			same_catalog( $error, $catalog->search(), 'Original provider error is propagated without fabricated metadata' );
			configure_catalog_org();
			route_catalog( '/items', catalog_page( array( catalog_item() ) ) );
			same_catalog( ITEM_A, $catalog->search()[0]['item_id'], 'Failed resolution is not permanently memoized' );
		} );
	}

	run_catalog_case( 'complete pagination is filtered before one bounded cache write', function (): void {
		$catalog = reset_catalog_test();
		route_catalog( '/items', static function ( array $query ): array {
			return 1 === $query['page']
				? catalog_page( array( catalog_item(), catalog_item( '90000000003', array( 'tags' => array(), 'cf_for_estimate' => true ) ) ), true, 1 )
				: catalog_page( array( catalog_item( ITEM_B ) ), false, 2 );
		} );
		$result = $catalog->search( array( 'limit' => 1 ) );
		same_catalog( array( ITEM_A ), array_column( $result, 'item_id' ), 'Caller limit applies after authoritative complete loading' );
		same_catalog( array( 1, 2 ), array_column( array_column( catalog_calls( '/items' ), 'query' ), 'page' ), 'Every page is requested in order' );
		$writes = Zoho_Cache::instance()->writes;
		same_catalog( 1, count( $writes ), 'Only the complete result is written' );
		same_catalog( array( ITEM_A, ITEM_B ), array_column( $writes[0]['value'], 'item_id' ), 'Cache stores all eligible pages, without checkbox-only item' );
	} );

	run_catalog_case( 'later page transport error never caches a partial first page', function (): void {
		$catalog = reset_catalog_test();
		$error = new WP_Error( 'synthetic_second_page', 'Synthetic second page failure', array( 'status' => 502 ) );
		route_catalog( '/items', static fn ( array $query ): array|WP_Error => 1 === $query['page'] ? catalog_page( array( catalog_item() ), true ) : $error );
		same_catalog( $error, $catalog->search(), 'Later page provider error is propagated' );
		same_catalog( array(), Zoho_Cache::instance()->writes, 'No partial cache is published' );
	} );

	$malformed_pages = array(
		'provider list failure' => array( 'code' => 42, 'items' => array( catalog_item( ITEM_B ) ), 'page_context' => array( 'has_more_page' => false ) ),
		'non-array items' => array( 'code' => 0, 'items' => 'malformed', 'page_context' => array( 'has_more_page' => false ) ),
		'missing page context' => array( 'code' => 0, 'items' => array( catalog_item( ITEM_B ) ) ),
		'missing more-pages flag' => array( 'items' => array( catalog_item( ITEM_B ) ), 'page_context' => array( 'page' => 2 ) ),
		'string more-pages flag' => array( 'items' => array( catalog_item( ITEM_B ) ), 'page_context' => array( 'has_more_page' => 'false' ) ),
	);
	foreach ( $malformed_pages as $label => $bad_page ) {
		run_catalog_case( 'rejects malformed pagination: ' . $label, function () use ( $bad_page ): void {
			$catalog = reset_catalog_test();
			route_catalog( '/items', static fn ( array $query ): array => 1 === $query['page'] ? catalog_page( array( catalog_item() ), true ) : $bad_page );
			catalog_error( $catalog->search(), 'tc_estimate_catalog_invalid_response', 502 );
			same_catalog( array(), Zoho_Cache::instance()->writes, 'Malformed later page cannot publish a partial cache' );
		} );
	}

	run_catalog_case( 'fifty-page ceiling fails closed instead of truncating the catalog', function (): void {
		$catalog = reset_catalog_test();
		route_catalog( '/items', static fn ( array $query ): array => catalog_page( array( catalog_item( '92' . str_pad( (string) $query['page'], 9, '0', STR_PAD_LEFT ) ) ), true, $query['page'] ) );
		catalog_error( $catalog->search(), 'tc_estimate_catalog_page_limit', 503 );
		same_catalog( 50, count( catalog_calls( '/items' ) ), 'At most fifty pages are fetched' );
		same_catalog( array(), Zoho_Cache::instance()->writes, 'Never caches a fifty-page partial result' );
	} );

	run_catalog_case( 'legacy checkbox cache is ignored; valid policy cache is reused after fresh metadata', function (): void {
		$catalog = reset_catalog_test();
		$legacy_key = 'books_items_for_estimate_' . ORG_A;
		Zoho_Cache::instance()->values[ $legacy_key ] = array( array( 'item_id' => ITEM_B, 'for_estimate' => true ) );
		route_catalog( '/items', catalog_page( array( catalog_item() ) ) );
		same_catalog( ITEM_A, $catalog->search()[0]['item_id'], 'Legacy cached checkbox authorization is ignored' );
		check_catalog( ! in_array( $legacy_key, Zoho_Cache::instance()->reads, true ), 'Old namespace is not consulted' );
		Zoho_API::instance()->calls = array();
		$catalog = new_catalog_request();
		same_catalog( ITEM_A, $catalog->search()[0]['item_id'], 'Strict valid policy cache can serve the picker' );
		same_catalog( 1, count( catalog_calls( '/reportingtags' ) ), 'New request resolves authoritative metadata even with a cache hit' );
		same_catalog( array(), catalog_calls( '/items' ), 'Valid policy cache avoids item-list refetch' );
	} );

	$cache_mutations = array(
		'non-boolean eligibility' => static function ( array $row ): array { $row['for_estimate'] = 'true'; return $row; },
		'non-boolean package marker' => static function ( array $row ): array { $row['package_tagged'] = 1; return $row; },
		'wrong tag ID' => static function ( array $row ): array { $row['package_tag_id'] = TAG_B; return $row; },
		'wrong option ID' => static function ( array $row ): array { $row['package_tag_option_id'] = OPTION_B; return $row; },
		'numeric tag ID' => static function ( array $row ): array { $row['package_tag_id'] = (int) TAG_A; return $row; },
		'missing normalization field' => static function ( array $row ): array { unset( $row['short_description'] ); return $row; },
		'invalid item ID' => static function ( array $row ): array { $row['item_id'] = '123'; return $row; },
		'non-numeric rate' => static function ( array $row ): array { $row['rate'] = 'not-a-price'; return $row; },
		'numeric string rate' => static function ( array $row ): array { $row['rate'] = '3200'; return $row; },
	);
	foreach ( $cache_mutations as $label => $mutate ) {
		run_catalog_case( 'rebuilds invalid cached metadata: ' . $label, function () use ( $mutate ): void {
			$catalog = reset_catalog_test();
			route_catalog( '/items', catalog_page( array( catalog_item() ) ) );
			$catalog->search();
			$cache = Zoho_Cache::instance();
			$cache->values[ catalog_key() ][0] = $mutate( $cache->values[ catalog_key() ][0] );
			$cache->writes = array();
			route_catalog( '/items', catalog_page( array( catalog_item( ITEM_B ) ) ) );
			$result = new_catalog_request()->search();
			same_catalog( array( ITEM_B ), array_column( $result, 'item_id' ), 'Malformed cached row cannot be exposed' );
			same_catalog( 1, count( $cache->writes ), 'Complete authoritative cache is rebuilt' );
		} );
	}

	run_catalog_case( 'organization and resolved tag/option IDs isolate cached catalogs', function (): void {
		$catalog = reset_catalog_test();
		configure_catalog_org( ORG_B, TAG_B, OPTION_B );
		route_catalog( '/items', catalog_page( array( catalog_item() ) ) );
		route_catalog( '/items', catalog_page( array( catalog_item( ITEM_B, array( 'tags' => array( catalog_tag( TAG_B, OPTION_B ) ) ) ) ) ), ORG_B );
		same_catalog( ITEM_A, $catalog->search()[0]['item_id'], 'Org A gets its item' );
		$GLOBALS['catalog_test_options']['tc_estimate_zoho_org_id'] = ORG_B;
		same_catalog( ITEM_B, $catalog->search()[0]['item_id'], 'Org B cannot reuse Org A metadata or list' );
		$GLOBALS['catalog_test_options']['tc_estimate_zoho_org_id'] = ORG_A;
		same_catalog( ITEM_A, $catalog->search()[0]['item_id'], 'Returning to Org A uses its own policy cache' );
		check_catalog( isset( Zoho_Cache::instance()->values[ catalog_key() ], Zoho_Cache::instance()->values[ catalog_key( ORG_B, TAG_B, OPTION_B ) ] ), 'Both organization-specific keys coexist' );
		same_catalog( 1, count( catalog_calls( '/reportingtags', ORG_A ) ), 'Org A metadata is memoized only for Org A' );
		same_catalog( 1, count( catalog_calls( '/reportingtags', ORG_B ) ), 'Org B metadata is separately resolved' );
	} );

	echo "Catalog smoke: {$passed} cases passed, {$failed} failed; {$assertions} assertions passed.\n";
	exit( $failed > 0 ? 1 : 0 );
}
