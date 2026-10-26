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
			'rate' => 3200, 'status' => 'active', 'is_combo_product' => false, 'cf_equipment_type' => 'furnace',
			'cf_brand' => 'Fixture Brand', 'cf_model' => 'FIXTURE-80', 'description' => 'Synthetic scope',
			'tags' => array( catalog_tag() ),
		), $overrides );
	}
	function catalog_page( array $items, bool $has_more = false, int $page = 1 ): array {
		return array( 'code' => 0, 'items' => $items, 'page_context' => array( 'page' => $page, 'has_more_page' => $has_more ) );
	}
	function catalog_key( string $org = ORG_A, string $tag = TAG_A, string $option = OPTION_A ): string {
		return 'books_items_package_tag_v3_' . $org . '_' . $tag . '_' . $option;
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
	function normalize_catalog_fixture( array $overrides = array(), string $id = ITEM_A ): array {
		$catalog = reset_catalog_test();
		route_catalog( '/items/' . $id, array( 'item' => catalog_item( $id, $overrides ) ) );
		$result = $catalog->get_item( $id );
		check_catalog( ! is_wp_error( $result ), 'Synthetic tagged item can be normalized' );
		return $result;
	}
	function selector_error( mixed $result ): void {
		check_catalog( is_wp_error( $result ), 'Invalid selector input must return WP_Error' );
		same_catalog( 400, $result->get_error_data()['status'] ?? null, 'Invalid selector input has HTTP 400' );
	}
	function selection_system( array $equipment ): array {
		return array( array( 'system_number' => 1, 'system_label' => 'Synthetic system', 'equipment' => $equipment ) );
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
		'missing selector category' => static function ( array $row ): array { unset( $row['selection_category'] ); return $row; },
		'non-boolean composite flag' => static function ( array $row ): array { $row['is_composite'] = 'false'; return $row; },
		'category and equipment type conflict' => static function ( array $row ): array { $row['selection_category'] = 'package'; return $row; },
		'string tonnage' => static function ( array $row ): array { $row['tons'] = '1.5'; return $row; },
		'string BTU' => static function ( array $row ): array { $row['btu_input'] = '80000'; return $row; },
		'missing efficiency' => static function ( array $row ): array { unset( $row['efficiency_percent'] ); return $row; },
		'string efficiency' => static function ( array $row ): array { $row['efficiency_percent'] = '96'; return $row; },
		'out-of-range efficiency' => static function ( array $row ): array { $row['efficiency_percent'] = 101.0; return $row; },
		'efficiency compatibility drift' => static function ( array $row ): array { $row['afue'] = 96.0; return $row; },
		'missing purchase cost' => static function ( array $row ): array { unset( $row['purchase_cost'] ); return $row; },
		'negative purchase cost' => static function ( array $row ): array { $row['purchase_cost'] = -1.0; return $row; },
		'string purchase cost' => static function ( array $row ): array { $row['purchase_cost'] = '1250'; return $row; },
		'unknown cost claimed known' => static function ( array $row ): array { $row['purchase_cost_status'] = 'known'; return $row; },
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

	run_catalog_case( 'composite truth and explicit classification are separate from Package eligibility', function (): void {
		$package = normalize_catalog_fixture( array(
			'is_combo_product' => true, 'cf_equipment_type' => 'furnace',
			'name' => 'Synthetic AC and Furnace with Evaporator Coil', 'cf_category_1' => 'Package',
		) );
		same_catalog( 'package', $package['selection_category'], 'An actual composite is one package, despite component descriptors' );
		same_catalog( 'package', $package['equipment_type'], 'Canonical package type is retained for downstream slot validation' );
		same_catalog( true, $package['is_composite'], 'Actual boolean composite flag is retained' );
		foreach ( array(
			array( 'Synthetic Series AC - 2 Ton', 'ac', 'condenser' ),
			array( 'Synthetic Single-Stage Furnace - 80,000 BTU', 'furnace', 'furnace' ),
			array( 'Synthetic Evaporator Coil - 2 Ton', 'coil', 'evaporator_coil' ),
		) as [ $name, $category, $type ] ) {
			$item = normalize_catalog_fixture( array( 'name' => $name, 'cf_equipment_type' => null, 'cf_category_1' => 'Package' ) );
			same_catalog( $category, $item['selection_category'], 'Explicit bounded component descriptor determines category' );
			same_catalog( $type, $item['equipment_type'], 'Component gets its canonical equipment type' );
			same_catalog( false, $item['is_composite'], 'Tagged component is not made composite by its Category 1 field' );
			same_catalog( true, $item['for_estimate'], 'Actual Package reporting tag continues to authorize components' );
		}
		$item = normalize_catalog_fixture( array( 'name' => 'Synthetic AC', 'cf_equipment_type' => 'furnace' ) );
		same_catalog( 'furnace', $item['selection_category'], 'Recognized structured type takes precedence over name fallback' );
	} );

	run_catalog_case( 'unverified composite flags and ambiguous descriptors never enter a selector category', function (): void {
		foreach ( array( null, 'false', 'true', 0, 1, array() ) as $flag ) {
			$item = normalize_catalog_fixture( array( 'is_combo_product' => $flag ) );
			same_catalog( '', $item['selection_category'], 'Only an actual boolean provider composite flag supports classification' );
			same_catalog( '', $item['equipment_type'], 'Malformed composite metadata cannot retain a guessed core type' );
			same_catalog( true, $item['for_estimate'], 'Classification does not alter verified tag eligibility' );
		}
		foreach ( array( 'Synthetic AC + Furnace', 'Synthetic ACME FurnaceParts', 'Synthetic Model-36' ) as $name ) {
			$item = normalize_catalog_fixture( array( 'name' => $name, 'cf_equipment_type' => null ) );
			same_catalog( '', $item['selection_category'], 'Ambiguous or embedded words do not classify a component' );
		}
		$catalog = reset_catalog_test();
		$missing_flag = catalog_item();
		unset( $missing_flag['is_combo_product'] );
		route_catalog( '/items/' . ITEM_A, array( 'item' => $missing_flag ) );
		$normalized = $catalog->get_item( ITEM_A );
		same_catalog( '', $normalized['selection_category'], 'Absent composite flag cannot authorize selector classification' );
		same_catalog( true, $normalized['for_estimate'], 'Absent composite flag does not change independently verified tag eligibility' );
		route_catalog( '/items', catalog_page( array( $missing_flag ) ) );
		same_catalog( 0, $catalog->search_selector( array( 'category' => 'furnace' ) )['total'], 'Missing provider flag cannot be guessed from a furnace name' );
		catalog_error( $catalog->validate_selection( selection_system( array( 'furnace' => array( 'item_id' => ITEM_A ) ) ), array( ITEM_A => $normalized ) ), 'tc_estimate_bad_equipment_selection', 400 );
		$catalog = reset_catalog_test();
		route_catalog( '/items', catalog_page( array(
			catalog_item( ITEM_A, array( 'name' => 'Synthetic Model-36', 'cf_equipment_type' => null ) ),
			catalog_item( ITEM_B, array( 'is_combo_product' => 'false' ) ),
		) ) );
		foreach ( array( 'package', 'ac', 'furnace', 'coil' ) as $category ) {
			same_catalog( 0, $catalog->search_selector( array( 'category' => $category ) )['total'], 'Unknown metadata cannot be relabeled into requested selector category' );
		}
		same_catalog( array(), $catalog->search( array( 'type' => 'furnace' ) ), 'Legacy array search also stops relabeling untyped items' );
	} );

	run_catalog_case( 'capacity normalization accepts complete positive comma and unit forms', function (): void {
		foreach ( array(
			array( 80000, 80000 ), array( '100,000', 100000 ), array( '80k', 80000 ),
			array( '80 K BTU', 80000 ), array( '100,000 BTU/hr', 100000 ), array( '80k BTU/hr', 80000 ),
		) as [ $raw, $expected ] ) {
			$item = normalize_catalog_fixture( array( 'cf_btu_input' => $raw, 'cf_btu_output' => $raw ) );
			same_catalog( $expected, $item['btu_input'], 'Structured input BTU normalizes its complete value' );
			same_catalog( $expected, $item['btu_output'], 'Structured output BTU uses the same strict numeric parser' );
		}
		foreach ( array( 1.5, '1.5', '1.50 tons', '1.5 Ton' ) as $raw ) {
			$item = normalize_catalog_fixture( array( 'cf_tons' => $raw ) );
			same_catalog( 1.5, $item['tons'], 'Tonnage keeps decimal precision and ignores unit capitalization' );
		}
		foreach ( array(
			array( 'custom_fields' => array( array( 'api_name' => 'cf_btu_input', 'value' => '60,000 BTU' ), array( 'api_name' => 'cf_tons', 'value' => '2.5 tons' ) ) ),
			array( 'custom_field_hash' => array( 'cf_btu_input' => '60,000 BTU', 'cf_tons' => '2.5 tons' ) ),
		) as $shape ) {
			$item = normalize_catalog_fixture( $shape );
			same_catalog( 60000, $item['btu_input'], 'Custom field collection/hash provides input rating' );
			same_catalog( 2.5, $item['tons'], 'Custom field collection/hash provides tonnage' );
		}
	} );

	run_catalog_case( 'invalid or conflicting structured ratings cannot fall back to a name or model', function (): void {
		foreach ( array( 0, -1, true, 'NaN', 'Infinity', '80,00', '80k garbage', '60-80k BTU', array( 80000 ) ) as $raw ) {
			$item = normalize_catalog_fixture( array( 'name' => 'Synthetic Furnace - 100,000 BTU', 'cf_btu_input' => $raw ) );
			same_catalog( null, $item['btu_input'], 'Invalid complete structured value is unknown despite a parseable name' );
		}
		foreach ( array( 0, -1, false, '1,5', '2/3 Ton', '2 Ton extra', 'NaN', array( 2 ) ) as $raw ) {
			$item = normalize_catalog_fixture( array( 'name' => 'Synthetic AC - 3 Ton', 'cf_tons' => $raw ) );
			same_catalog( null, $item['tons'], 'Invalid structured tonnage is not overwritten with a name guess' );
		}
		$item = normalize_catalog_fixture( array(
			'name' => 'Synthetic Furnace - 3 Ton, 100,000 BTU', 'cf_btu_input' => 60000, 'cf_tons' => 2,
			'custom_field_hash' => array( 'cf_btu_input' => 80000, 'cf_tons' => 2.5 ),
		) );
		same_catalog( null, $item['btu_input'], 'Conflicting aliases/shapes do not select an arbitrary BTU' );
		same_catalog( null, $item['tons'], 'Conflicting aliases/shapes do not select arbitrary tonnage' );
		$item = normalize_catalog_fixture( array( 'name' => 'Synthetic AC - 3 Ton, 100,000 BTU', 'cf_btu_input' => 60000, 'cf_tons' => 2 ) );
		same_catalog( 60000, $item['btu_input'], 'A valid structured rating wins over a conflicting name' );
		same_catalog( 2.0, $item['tons'], 'A valid structured tonnage wins over a conflicting name' );
	} );

	run_catalog_case( 'name fallback uses explicit parent capacity tokens only', function (): void {
		$item = normalize_catalog_fixture( array(
			'is_combo_product' => true, 'name' => 'Synthetic Package - 1.5 Ton, 100,000 BTU',
			'mapped_items' => array( array( 'name' => 'Synthetic Evaporator Coil - 2 Ton' ) ),
		) );
		same_catalog( 1.5, $item['tons'], 'Package parent rating is not replaced with component coil rating' );
		same_catalog( 100000, $item['btu_input'], 'Explicit parent BTU is available when structured rating is absent' );
		same_catalog( null, $item['btu_output'], 'Parent name BTU does not invent a separate output rating' );
		$item = normalize_catalog_fixture( array( 'name' => 'Synthetic AC - 2.5-ton, 80k BTU' ) );
		same_catalog( 2.5, $item['tons'], 'Explicit hyphenated tonnage is accepted' );
		same_catalog( 80000, $item['btu_input'], 'Explicit k BTU name token is accepted' );
		foreach ( array(
			'Synthetic Model-X36 80% AFUE 14.3 SEER',
			'Synthetic 2 Ton / 3 Ton, 60,000 BTU / 80,000 BTU',
			'Synthetic Furnace - 60–80k BTU',
			'Synthetic Furnace - 60 to 80k BTU',
			'Synthetic AC - 1.5–2 Ton',
		) as $name ) {
			$item = normalize_catalog_fixture( array( 'name' => $name ) );
			same_catalog( null, $item['tons'], 'Model digits, efficiency and ambiguous capacities do not become tonnage' );
			same_catalog( null, $item['btu_input'], 'Model digits, efficiency and ambiguous capacities do not become BTU' );
		}
	} );

	run_catalog_case( 'selector combines filters before pagination and keeps category-wide numeric facets', function (): void {
		$catalog = reset_catalog_test();
		$items = array();
		for ( $index = 0; $index < 140; $index++ ) {
			$id = '93' . str_pad( (string) $index, 9, '0', STR_PAD_LEFT );
			$items[] = catalog_item( $id, array(
				'is_combo_product' => true, 'name' => 'Synthetic Package ' . $index,
				'cf_tons' => array( 1.5, 2, 3 )[ $index % 3 ],
				'cf_btu_input' => array( 40000, 60000, 100000 )[ $index % 3 ],
				'cf_js_part_number' => 125 === $index ? 'JS-LATE-NEEDLE' : 'JS-' . $index,
			) );
		}
		$items[] = catalog_item( '94000000001', array( 'cf_equipment_type' => 'condenser', 'name' => 'Synthetic AC', 'cf_tons' => 5, 'cf_btu_input' => 120000 ) );
		$items[] = catalog_item( '94000000002', array( 'cf_equipment_type' => 'furnace', 'name' => 'Synthetic Furnace', 'cf_btu_input' => 80000 ) );
		$items[] = catalog_item( '94000000003', array( 'cf_equipment_type' => 'evaporator_coil', 'name' => 'Synthetic Evaporator Coil', 'cf_tons' => 2 ) );
		$items[] = catalog_item( '94000000004', array( 'is_combo_product' => true, 'name' => 'Synthetic Package unknown rating' ) );
		route_catalog( '/items', catalog_page( $items ) );
		$first = $catalog->search_selector();
		same_catalog( 141, $first['total'], 'Default selector returns only packages, including unknown ratings' );
		same_catalog( 1, $first['page'], 'Default page is one' );
		same_catalog( 50, $first['per_page'], 'Default page size is fifty' );
		same_catalog( 50, count( $first['items'] ), 'Default first page is bounded' );
		same_catalog( true, $first['has_more'], 'A next page is explicitly indicated' );
		same_catalog( array( 40000, 60000, 100000 ), $first['facets']['btus'], 'Package BTU facets are sorted numeric values without component ratings' );
		same_catalog( array( 1.5, 2.0, 3.0 ), $first['facets']['tons'], 'Package tonnage facets are numeric and sorted' );
		$page_two = $catalog->search_selector( array( 'category' => 'package', 'page' => 2, 'limit' => 100 ) );
		same_catalog( 41, count( $page_two['items'] ), 'Second page reaches packages beyond the old one-hundred item truncation' );
		same_catalog( $items[100]['item_id'], $page_two['items'][0]['item_id'], 'Second page offset is applied to filtered category results' );
		same_catalog( false, $page_two['has_more'], 'Final page has no further results' );
		$filtered = $catalog->search_selector( array( 'category' => 'package', 'q' => 'late-needle', 'brand' => 'fixture brand', 'btu' => 100000, 'tons' => 3, 'limit' => 1 ) );
		same_catalog( array( $items[125]['item_id'] ), array_column( $filtered['items'], 'item_id' ), 'JS part search and every numeric/category/brand condition match beyond item one hundred' );
		same_catalog( 1, $filtered['total'], 'Total reflects all combined conditions before pagination' );
		same_catalog( $first['facets'], $filtered['facets'], 'Facets remain category-wide despite text, brand, BTU, tonnage and limit filters' );
		$no_match = $catalog->search_selector( array( 'category' => 'package', 'q' => 'late-needle', 'btu' => 60000, 'tons' => 3 ) );
		same_catalog( array(), $no_match['items'], 'Numeric and text conditions are ANDed rather than ORed' );
		same_catalog( 0, $no_match['total'], 'No results retains a truthful zero count' );
		$past_end = $catalog->search_selector( array( 'category' => 'package', 'page' => 99, 'limit' => 100 ) );
		same_catalog( array(), $past_end['items'], 'Page beyond the end returns no phantom records' );
		same_catalog( 141, $past_end['total'], 'Page beyond the end retains category total' );
		same_catalog( false, $past_end['has_more'], 'Page beyond the end is terminal' );
		foreach ( array( 'ac' => '94000000001', 'furnace' => '94000000002', 'coil' => '94000000003' ) as $category => $id ) {
			same_catalog( array( $id ), array_column( $catalog->search_selector( array( 'category' => $category ) )['items'], 'item_id' ), 'Each component category is distinct from packages' );
		}
		same_catalog( 1, count( catalog_calls( '/items' ) ), 'Repeated filter and page changes use the complete normalized catalog cache' );
	} );

	run_catalog_case( 'selector searches all seven original text fields and excludes missing ratings only when filtered', function (): void {
		foreach ( array( 'name', 'sku', 'cf_brand', 'cf_model', 'cf_js_part_number', 'cf_mfg_part_number', 'description' ) as $field ) {
			$catalog = reset_catalog_test();
			route_catalog( '/items', catalog_page( array( catalog_item( ITEM_A, array( 'is_combo_product' => true, $field => 'MixedCase NEEDLE' ) ) ) ) );
			same_catalog( 1, $catalog->search_selector( array( 'q' => ' needle ' ) )['total'], 'Case-insensitive text search retains the original seven fields' );
		}
		$catalog = reset_catalog_test();
		route_catalog( '/items', catalog_page( array( catalog_item( ITEM_A, array( 'is_combo_product' => true, 'name' => 'Synthetic Package no ratings' ) ) ) ) );
		same_catalog( 1, $catalog->search_selector()['total'], 'Unfiltered selection includes unknown ratings' );
		same_catalog( array( 'btus' => array(), 'tons' => array(), 'efficiencies' => array() ), $catalog->search_selector()['facets'], 'Missing ratings do not create a zero facet' );
		same_catalog( 0, $catalog->search_selector( array( 'btu' => 80000 ) )['total'], 'Unknown BTU cannot satisfy a selected BTU' );
		same_catalog( 0, $catalog->search_selector( array( 'tons' => 2 ) )['total'], 'Unknown tonnage cannot satisfy selected tonnage' );
	} );

	run_catalog_case( 'selector rejects invalid filter input rather than silently broadening results', function (): void {
		$catalog = reset_catalog_test();
		foreach ( array(
			array( 'category' => 'other' ), array( 'category' => array( 'package' ) ),
			array( 'btu' => -1 ), array( 'btu' => 'not-btu' ), array( 'btu' => array( 80000 ) ),
			array( 'tons' => -1 ), array( 'tons' => 'not-tonnage' ), array( 'tons' => array( 2 ) ),
			array( 'page' => 'later' ), array( 'page' => '1x' ), array( 'page' => array( 1 ) ),
			array( 'limit' => 'many' ), array( 'limit' => '50x' ), array( 'limit' => array( 50 ) ),
			array( 'efficiency' => 0 ), array( 'efficiency' => -1 ), array( 'efficiency' => 101 ),
			array( 'efficiency' => '96 SEER' ), array( 'efficiency' => '96% extra' ), array( 'efficiency' => array( 96 ) ),
		) as $args ) {
			selector_error( $catalog->search_selector( $args ) );
		}
		same_catalog( array(), catalog_calls( '/items' ), 'Malformed filters fail before catalog reads' );
	} );

	run_catalog_case( 'AFUE percentages use complete structured values and explicit parent labels only', function (): void {
		foreach ( array( 80, '96', '96%', '96.5 %', 100.0 ) as $raw ) {
			$item = normalize_catalog_fixture( array( 'cf_afue' => $raw, 'name' => 'Synthetic Furnace - 80% AFUE' ) );
			$expected = (float) str_replace( '%', '', (string) $raw );
			same_catalog( $expected, $item['efficiency_percent'], 'Structured AFUE is a percentage, without fractional conversion' );
			same_catalog( $expected, $item['afue'], 'Existing AFUE consumers receive the same normalized value' );
		}
		foreach ( array(
			array( 'custom_fields' => array( array( 'api_name' => 'AFUE_Percent', 'value' => '97%' ) ) ),
			array( 'custom_field_hash' => array( 'cf_afue' => '97%' ) ),
		) as $shape ) {
			$item = normalize_catalog_fixture( $shape );
			same_catalog( 97.0, $item['efficiency_percent'], 'Supported provider custom-field shapes supply AFUE' );
		}
		foreach ( array( 'Synthetic Package - 96% AFUE', 'Synthetic Furnace AFUE: 96%', 'Synthetic Furnace 96% afue, 18 SEER' ) as $name ) {
			$item = normalize_catalog_fixture( array( 'name' => $name ) );
			same_catalog( 96.0, $item['efficiency_percent'], 'Explicit labeled name percentage supplies a missing structured AFUE' );
		}
		foreach ( array( 'Synthetic AC 18 SEER', 'Synthetic Model-AFUE96', 'Synthetic 96% efficiency claim', 'Synthetic 96%', 'Synthetic 80–96% AFUE', 'Synthetic 80% to 96% AFUE', 'Synthetic 80% AFUE / 96% AFUE' ) as $name ) {
			$item = normalize_catalog_fixture( array( 'name' => $name ) );
			same_catalog( null, $item['efficiency_percent'], 'Unlabeled percentages, SEER, models and ambiguous AFUE do not invent efficiency' );
		}
	} );

	run_catalog_case( 'invalid and conflicting structured AFUE suppresses name fallback', function (): void {
		foreach ( array( 0, -1, 101, true, false, array( 96 ), 'NaN', 'Infinity', '96 SEER', '96foo%', '80-96%', '0.96fraction' ) as $raw ) {
			$item = normalize_catalog_fixture( array( 'cf_afue' => $raw, 'name' => 'Synthetic Furnace - 96% AFUE' ) );
			same_catalog( null, $item['efficiency_percent'], 'Malformed or out-of-range structured percentage stays unknown' );
		}
		$item = normalize_catalog_fixture( array( 'cf_afue' => 80, 'custom_field_hash' => array( 'cf_afue' => 96 ), 'name' => 'Synthetic Furnace - 97% AFUE' ) );
		same_catalog( null, $item['efficiency_percent'], 'Conflicting structured shapes do not pick an arbitrary efficiency or fall back' );
		$item = normalize_catalog_fixture( array( 'cf_afue' => 80, 'name' => 'Synthetic Furnace - 97% AFUE' ) );
		same_catalog( 80.0, $item['efficiency_percent'], 'Valid structured AFUE takes precedence over name evidence' );
	} );

	run_catalog_case( 'efficiency combines with all selector filters before pagination and preserves full facets', function (): void {
		$catalog = reset_catalog_test();
		$items = array();
		for ( $index = 0; $index < 150; $index++ ) {
			$items[] = catalog_item( '95' . str_pad( (string) $index, 9, '0', STR_PAD_LEFT ), array(
				'is_combo_product' => true, 'name' => 'Synthetic Package ' . $index,
				'cf_btu_input' => 100000, 'cf_tons' => 1.5, 'cf_afue' => 149 === $index ? 97 : ( 148 === $index ? 96 : 80 ),
				'cf_mfg_part_number' => 149 === $index ? 'LATE-TARGET' : 'OTHER',
			) );
		}
		$items[] = catalog_item( '96000000001', array( 'is_combo_product' => true, 'name' => 'Synthetic Package without efficiency', 'cf_btu_input' => 100000, 'cf_tons' => 1.5 ) );
		$items[] = catalog_item( '96000000002', array( 'cf_equipment_type' => 'condenser', 'name' => 'Synthetic AC 18 SEER', 'cf_tons' => 1.5 ) );
		route_catalog( '/items', catalog_page( $items ) );
		$all = $catalog->search_selector();
		same_catalog( 151, $all['total'], 'All efficiencies includes packages with an unknown percentage' );
		same_catalog( array( 80.0, 96.0, 97.0 ), $all['facets']['efficiencies'], 'Category-wide efficiencies are sorted numeric percentages' );
		$filtered = $catalog->search_selector( array( 'category' => 'package', 'q' => 'late-target', 'brand' => 'fixture brand', 'btu' => 100000, 'tons' => 1.5, 'efficiency' => '97%', 'limit' => 1 ) );
		same_catalog( array( $items[149]['item_id'] ), array_column( $filtered['items'], 'item_id' ), 'Efficiency AND text/brand/capacities finds a match beyond the first hundred' );
		same_catalog( 1, $filtered['total'], 'Combined total is calculated before paging' );
		same_catalog( $all['facets'], $filtered['facets'], 'Efficiency does not narrow the category-wide facets' );
		same_catalog( 0, $catalog->search_selector( array( 'q' => 'late-target', 'efficiency' => 96 ) )['total'], 'Efficiency is not ORed with the other conditions' );
		same_catalog( 1, $catalog->search_selector( array( 'efficiency' => 97 ) )['total'], 'Unknown efficiency cannot satisfy an active filter' );
		$ac = $catalog->search_selector( array( 'category' => 'ac' ) );
		same_catalog( array(), $ac['facets']['efficiencies'], 'SEER cannot become a percent facet in AC category' );
		same_catalog( 0, $catalog->search_selector( array( 'category' => 'ac', 'efficiency' => 18 ) )['total'], 'SEER cannot satisfy an efficiency percentage filter' );
	} );

	run_catalog_case( 'purchase cost uses complete actual purchase rates independently of sales amounts', function (): void {
		foreach ( array( 0, 1250.25, '1,250.25' ) as $raw ) {
			$item = normalize_catalog_fixture( array( 'purchase_rate' => $raw, 'rate' => 9000 ) );
			$expected = (float) str_replace( ',', '', (string) $raw );
			same_catalog( $expected, $item['purchase_cost'], 'Explicit nonnegative purchase rate establishes component cost' );
			same_catalog( 'books_purchase_rate', $item['purchase_cost_source'], 'Known direct purchase cost records its actual source' );
			same_catalog( 'known', $item['purchase_cost_status'], 'Explicit zero is known for a noncomposite' );
			same_catalog( 9000.0, $item['rate'], 'Sales rate stays separate from internal purchase cost' );
		}
		foreach ( array( null, true, false, array( 1250 ), -1, INF, NAN, 'NaN', 'Infinity', '1250abc', '$1250', '1,25.00', '' ) as $raw ) {
			$item = normalize_catalog_fixture( array( 'purchase_rate' => $raw, 'rate' => 9000, 'sales_rate' => 8500, 'cf_markup' => 50 ) );
			same_catalog( null, $item['purchase_cost'], 'Missing or malformed purchase rate never borrows sales or markup' );
			same_catalog( null, $item['purchase_cost_source'], 'Unknown cost has no asserted provider source' );
			same_catalog( 'unknown', $item['purchase_cost_status'], 'Unknown cost is explicit rather than coerced to zero' );
		}
		$item = normalize_catalog_fixture( array( 'Purchase_Cost' => 1234, 'custom_fields' => array( array( 'api_name' => 'purchase_cost', 'value' => 4321 ) ) ) );
		same_catalog( null, $item['purchase_cost'], 'Unverified aliases do not substitute for the actual purchase_rate field' );
	} );

	run_catalog_case( 'composite detail costs use complete mapped purchase quantities without expanding the sales item', function (): void {
		$rows = array(
			array( 'item_id' => '97000000001', 'is_component' => true, 'is_combo_product' => false, 'purchase_rate' => 120, 'mapped_quantity' => 2, 'rate' => 9999 ),
			array( 'item_id' => '97000000002', 'is_component' => true, 'is_combo_product' => false, 'purchase_rate' => 50, 'mapped_quantity' => 0.5, 'rate' => 8888 ),
		);
		$item = normalize_catalog_fixture( array( 'is_combo_product' => true, 'purchase_rate' => 0, 'mapped_items' => $rows, 'rate' => 9000 ) );
		same_catalog( 265.0, $item['purchase_cost'], 'Complete component purchase rates times actual quantities establish kit cost' );
		same_catalog( 'books_mapped_items', $item['purchase_cost_source'], 'Derived cost identifies the explicit provider mapping' );
		same_catalog( 'known', $item['purchase_cost_status'], 'Complete flat provider mapping is known' );
		same_catalog( 9000.0, $item['rate'], 'Package retains its one actual sales rate' );
		same_catalog( 'package', $item['selection_category'], 'Cost calculation does not expand or reclassify the package' );
		foreach ( $rows as &$row ) { $row['purchase_rate'] = 0; }
		unset( $row );
		$item = normalize_catalog_fixture( array( 'is_combo_product' => true, 'purchase_rate' => 0, 'mapped_items' => $rows ) );
		same_catalog( 0.0, $item['purchase_cost'], 'Explicit complete all-zero mapping can establish a real zero cost' );
		same_catalog( 'books_mapped_items', $item['purchase_cost_source'], 'Mapped zero is distinguished from an unhydrated zero parent' );
		$item = normalize_catalog_fixture( array( 'is_combo_product' => true, 'purchase_rate' => 500, 'mapped_items' => array( 'malformed' ) ) );
		same_catalog( 500.0, $item['purchase_cost'], 'An explicit positive parent purchase rate remains authoritative' );
		same_catalog( 'books_purchase_rate', $item['purchase_cost_source'], 'Authoritative parent cost does not depend on component sales' );
	} );

	run_catalog_case( 'unhydrated or incomplete composite costs remain unknown without partial sums', function (): void {
		$row = array( 'item_id' => '97000000001', 'is_component' => true, 'is_combo_product' => false, 'purchase_rate' => 120, 'mapped_quantity' => 1 );
		$bad_mappings = array( null, array(), array( 'bad-row' ), array( 'not-a-list' => $row ), array( $row, $row ) );
		foreach ( array(
			array( 'purchase_rate' => null ), array( 'purchase_rate' => -1 ), array( 'purchase_rate' => '120junk' ),
			array( 'mapped_quantity' => null ), array( 'mapped_quantity' => 0 ), array( 'mapped_quantity' => -1 ), array( 'mapped_quantity' => true ),
			array( 'is_component' => false ), array( 'is_combo_product' => true ), array( 'is_combo_product' => null ),
			array( 'item_id' => ITEM_A ), array( 'item_id' => 'invalid' ), array( 'mapped_items' => array( $row ) ),
		) as $change ) { $bad_mappings[] = array( $row, array_replace( $row, array( 'item_id' => '97000000002' ), $change ) ); }
		$too_many = array();
		for ( $index = 0; $index <= 100; $index++ ) {
			$too_many[] = array_replace( $row, array( 'item_id' => '98' . str_pad( (string) $index, 9, '0', STR_PAD_LEFT ) ) );
		}
		$bad_mappings[] = $too_many;
		foreach ( $bad_mappings as $mapping ) {
			$item = normalize_catalog_fixture( array( 'is_combo_product' => true, 'purchase_rate' => 0, 'mapped_items' => $mapping, 'rate' => 9000 ) );
			same_catalog( null, $item['purchase_cost'], 'A zero parent with incomplete, nested or unbounded mapping has unknown cost' );
			same_catalog( 'unknown', $item['purchase_cost_status'], 'Incomplete mapping cannot claim a partial cost as complete' );
		}
	} );

	run_catalog_case( 'canonical slots accept one package or separate components with matching accessories', function (): void {
		$package = normalize_catalog_fixture( array( 'is_combo_product' => true ), ITEM_A );
		$furnace = normalize_catalog_fixture( array(), ITEM_B );
		$ac = normalize_catalog_fixture( array( 'cf_equipment_type' => 'condenser' ), '90000000003' );
		$coil = normalize_catalog_fixture( array( 'cf_equipment_type' => 'evaporator_coil' ), '90000000004' );
		$thermostat = normalize_catalog_fixture( array( 'cf_equipment_type' => 'thermostat' ), '90000000005' );
		$map = array_column( array( $package, $furnace, $ac, $coil, $thermostat ), null, 'item_id' );
		$catalog = Equipment_Catalog::instance();
		same_catalog( true, $catalog->validate_selection( selection_system( array( 'package' => array( 'item_id' => ITEM_A ), 'thermostat' => array( 'item_id' => $thermostat['item_id'] ) ) ), $map ), 'Package remains a single canonical item with an eligible accessory' );
		same_catalog( true, $catalog->validate_selection( selection_system( array( 'furnace' => array( 'item_id' => ITEM_B ), 'condenser' => array( 'item_id' => $ac['item_id'] ), 'coil' => array( 'item_id' => $coil['item_id'] ) ) ), $map ), 'Separate matched components use their canonical slots' );
		$two_systems = array_merge( selection_system( array( 'package' => array( 'item_id' => ITEM_A ) ) ), selection_system( array( 'package' => array( 'item_id' => ITEM_A ) ) ) );
		$two_systems[1]['system_number'] = 2;
		same_catalog( true, $catalog->validate_selection( $two_systems, $map ), 'Same catalog item can be selected once in each independent system' );
	} );

	run_catalog_case( 'missing system numbers retain positional defaults and distinct canonical numbers accept repeated packages', function (): void {
		$package = normalize_catalog_fixture( array( 'is_combo_product' => true ), ITEM_A );
		$map = array( ITEM_A => $package );
		$systems = array(
			array( 'equipment' => array( 'package' => array( 'item_id' => ITEM_A ) ) ),
			array( 'equipment' => array( 'package' => array( 'item_id' => ITEM_A ) ) ),
		);
		$catalog = Equipment_Catalog::instance();
		same_catalog( true, $catalog->validate_selection( $systems, $map ), 'Absent numbers default to distinct one-based array positions' );
		$systems[0]['system_number'] = '3';
		$systems[1]['system_number'] = 4;
		same_catalog( true, $catalog->validate_selection( $systems, $map ), 'Canonical numeric strings and positive integers identify independent systems' );
	} );

	run_catalog_case( 'duplicate effective system numbers cannot split a package and components across arrays', function (): void {
		$package = normalize_catalog_fixture( array( 'is_combo_product' => true ), ITEM_A );
		$furnace = normalize_catalog_fixture( array(), ITEM_B );
		$map = array( ITEM_A => $package, ITEM_B => $furnace );
		$catalog = Equipment_Catalog::instance();
		$systems = array(
			array( 'system_number' => 1, 'equipment' => array( 'package' => array( 'item_id' => ITEM_A ) ) ),
			array( 'system_number' => '1', 'equipment' => array( 'furnace' => array( 'item_id' => ITEM_B ) ) ),
		);
		catalog_error( $catalog->validate_selection( $systems, $map ), 'tc_estimate_bad_equipment_selection', 400 );
		$systems[0]['system_number'] = 2;
		unset( $systems[1]['system_number'] );
		catalog_error( $catalog->validate_selection( $systems, $map ), 'tc_estimate_bad_equipment_selection', 400 );
	} );

	run_catalog_case( 'invalid numbering and non-list system containers fail closed', function (): void {
		$package = normalize_catalog_fixture( array( 'is_combo_product' => true ), ITEM_A );
		$map = array( ITEM_A => $package );
		$catalog = Equipment_Catalog::instance();
		$systems = selection_system( array( 'package' => array( 'item_id' => ITEM_A ) ) );
		foreach ( array( 0, -1, 1.5, true, null, '', '01', '1x', array( 1 ) ) as $number ) {
			$systems[0]['system_number'] = $number;
			catalog_error( $catalog->validate_selection( $systems, $map ), 'tc_estimate_bad_equipment_selection', 400 );
		}
		$systems[0]['system_number'] = 1;
		catalog_error( $catalog->validate_selection( array( 'first' => $systems[0] ), $map ), 'tc_estimate_bad_equipment_selection', 400 );
		catalog_error( $catalog->validate_selection( array( 1 => $systems[0] ), $map ), 'tc_estimate_bad_equipment_selection', 400 );
	} );

	run_catalog_case( 'selection validator blocks conflicts, aliases, duplicate IDs and missing eligibility', function (): void {
		$package = normalize_catalog_fixture( array( 'is_combo_product' => true ), ITEM_A );
		$furnace = normalize_catalog_fixture( array(), ITEM_B );
		$unknown = normalize_catalog_fixture( array( 'is_combo_product' => 'false' ), '90000000003' );
		$thermostat = normalize_catalog_fixture( array( 'cf_equipment_type' => 'thermostat' ), '90000000004' );
		$map = array_column( array( $package, $furnace, $unknown, $thermostat ), null, 'item_id' );
		$catalog = Equipment_Catalog::instance();
		foreach ( array(
			array( 'package' => array( 'item_id' => ITEM_A ), 'furnace' => array( 'item_id' => ITEM_B ) ),
			array( 'furnace' => array( 'item_id' => ITEM_A ) ),
			array( 'other' => array( 'item_id' => ITEM_A ) ),
			array( 'ac' => array( 'item_id' => ITEM_B ) ),
			array( 'evaporator_coil' => array( 'item_id' => ITEM_B ) ),
			array( 'arbitrary' => array( 'item_id' => ITEM_B ) ),
			array( 'furnace' => array( 'item_id' => ITEM_B ), 'condenser' => array( 'item_id' => ITEM_B ) ),
			array( 'furnace' => array( 'item_id' => $unknown['item_id'] ) ),
			array( 'permit' => array( 'item_id' => $thermostat['item_id'] ) ),
			array( 'furnace' => array( 'item_id' => '99999999999' ) ),
		) as $equipment ) {
			catalog_error( $catalog->validate_selection( selection_system( $equipment ), $map ), 'tc_estimate_bad_equipment_selection', 400 );
		}
		$map[ ITEM_B ]['for_estimate'] = false;
		catalog_error( $catalog->validate_selection( selection_system( array( 'furnace' => array( 'item_id' => ITEM_B ) ) ), $map ), 'tc_estimate_bad_equipment_selection', 400 );
	} );

	echo "Catalog smoke: {$passed} cases passed, {$failed} failed; {$assertions} assertions passed.\n";
	exit( $failed > 0 ? 1 : 0 );
}
