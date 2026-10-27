<?php
/** Synthetic choice-projection tests; real catalog validator, no HTTP/email/provider writes. */
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', '/' );
	define( 'TC_ESTIMATE_CACHE_TTL_CATALOG', 900 );
	require dirname( __DIR__, 4 ) . '/tests/standalone-shims.php';
	function sanitize_text_field( string $s ): string { return trim( strip_tags( $s ) ); }
	function sanitize_textarea_field( string $s ): string { return trim( strip_tags( $s ) ); }
	function get_option( string $key, mixed $default = false ): mixed { return 'tc_estimate_zoho_org_id' === $key ? '60000000001' : $default; }
	function get_current_user_id(): int { return 1; }
	function get_post( int $id ): ?object { return 1 === $id ? (object) array( 'ID' => 1, 'post_type' => 'estimate_template', 'post_status' => 'publish', 'post_content' => 'Synthetic proposal' ) : null; }
	function wp_mail( mixed ...$args ): never { throw new \LogicException( 'No email allowed.' ); }
	function wp_remote_request( mixed ...$args ): never { throw new \LogicException( 'No HTTP allowed.' ); }
	class WP_REST_Request {
		public function __construct( private array $body, private array $headers = array() ) {}
		public function get_json_params(): array { return $this->body; }
		public function get_header( string $key ): string { return $this->headers[ $key ] ?? ''; }
	}
	class WP_REST_Response {
		public function __construct( private mixed $data, private int $status = 200 ) {}
		public function get_data(): mixed { return $this->data; }
		public function get_status(): int { return $this->status; }
		public function header( string $key, string $value ): void {}
	}
}

namespace TempControl\Estimate {
	final class Zoho_API {
		public const SERVICE_BOOKS = 'books';
		public array $items = array();
		public array $calls = array();
		public static function instance(): self { static $instance; return $instance ??= new self(); }
		public function get( string $service, string $path, array $query = array() ): array|\WP_Error {
			$this->calls[] = array( $path, $query );
			if ( 'books' !== $service ) { throw new \LogicException( 'Unexpected service.' ); }
			if ( '/reportingtags' === $path ) { return array( 'tags' => array( array( 'tag_id' => '70000000001', 'tag_name' => 'Package', 'is_active' => true ) ) ); }
			if ( '/reportingtags/70000000001/options/all' === $path ) { return array( 'results' => array( array( 'option_id' => '80000000001', 'option_name' => 'Package' ) ) ); }
			if ( '/items' === $path ) {
				$items = array_values( array_filter( $this->items, static fn ( array $i ): bool => $i['name'] === ( $query['name_contains'] ?? '' ) ) );
				return array( 'items' => $items, 'page_context' => array( 'has_more_page' => false ) );
			}
			if ( str_starts_with( $path, '/items/' ) ) {
				$id = substr( $path, 7 );
				return isset( $this->items[ $id ] ) ? array( 'item' => $this->items[ $id ] ) : new \WP_Error( 'fixture_missing', 'Synthetic item missing.' );
			}
			throw new \LogicException( 'Unexpected read.' );
		}
		public function post( mixed ...$args ): never { throw new \LogicException( 'No provider writes allowed.' ); }
		public function put( mixed ...$args ): never { throw new \LogicException( 'No provider writes allowed.' ); }
		public function delete( mixed ...$args ): never { throw new \LogicException( 'No provider writes allowed.' ); }
	}
	final class Zoho_Cache {
		public static function instance(): self { static $instance; return $instance ??= new self(); }
		public function get( string $key ): mixed { return null; }
		public function set( mixed ...$args ): void {}
		public function delete( string $key ): void {}
	}
	final class Security {
		public static function instance(): self { static $instance; return $instance ??= new self(); }
		public function sanitize_zoho_id( string $id ): string { return preg_match( '/^[0-9]+$/D', $id ) ? $id : ''; }
	}
	final class Rate_Limiter {
		public static function instance(): self { static $instance; return $instance ??= new self(); }
		public function consume( string $key ): bool { return true; }
		public function consume_generate(): bool { return true; }
	}
	final class Customer_Search {
		public static function instance(): self { static $instance; return $instance ??= new self(); }
		public function get_account( string $id ): array { return array( 'id' => $id, 'name' => 'Fixture Customer', 'email' => 'fixture@example.test' ); }
	}
	final class Template_CPT { public const POST_TYPE = 'estimate_template'; }
	final class Template_Meta {
		public static function instance(): self { static $instance; return $instance ??= new self(); }
		public function hydrate( int $id ): array { return array( 'id' => 1, 'version' => 1 ); }
	}
	final class Audit_Log {
		public array $records = array();
		public ?array $existing = null;
		public static function instance(): self { static $instance; return $instance ??= new self(); }
		public function find_by_idempotency_key( string $key, int $user ): ?array { return $this->existing; }
		public function record( array $record ): int { $this->records[] = $record; return 1; }
		public function update( int $id, array $record ): void {}
	}
	/** Boundary doubles record exactly what the real endpoints pass after projection. */
	final class Token_Renderer {
		public array $body = array();
		public static function instance(): self { static $instance; return $instance ??= new self(); }
		public function build_payload_view( array $body, mixed ...$args ): array { $this->body = $body; return $body; }
		public function render( string $template, array $view ): string { return '<p>Synthetic proposal</p>'; }
	}
	final class Estimate_Generator {
		public array $body = array();
		public static function instance(): self { static $instance; return $instance ??= new self(); }
		public function build_books_preview( array $view, mixed ...$args ): array { return array( 'line_items' => array() ); }
		public function generate( array $body, mixed ...$args ): array {
			$this->body = $body;
			return array( 'estimate_id' => '40000000001', 'deal_id' => '50000000001', 'estimate_url' => '', 'deal_url' => '', 'subtotal' => $body['pricing']['subtotal'], 'total' => $body['pricing']['total'] );
		}
	}
}

namespace {
	use TempControl\Estimate\Proposal_Choices;
	use TempControl\Estimate\Equipment_Catalog;
	use TempControl\Estimate\Zoho_API;
	use TempControl\Estimate\Audit_Log;
	use TempControl\Estimate\Estimate_Generator;
	use TempControl\Estimate\Token_Renderer;
	use TempControl\Estimate\Endpoints\Preview_Estimate;
	use TempControl\Estimate\Endpoints\Generate_Estimate;
	$runtime = dirname( __DIR__ );
	require $runtime . '/includes/class-equipment-catalog.php';
	require $runtime . '/includes/class-proposal-choices.php';
	require dirname( __DIR__, 4 ) . '/endpoints/class-endpoint-base.php';
	require $runtime . '/endpoints/class-preview-estimate.php';
	require $runtime . '/endpoints/class-generate-estimate.php';
	$passed = $failed = $assertions = 0;
	function choices_check( bool $value, string $message ): void { global $assertions; $assertions++; if ( ! $value ) { throw new \RuntimeException( $message ); } }
	function choices_same( mixed $expected, mixed $actual, string $message ): void { choices_check( $expected === $actual, $message . ': ' . json_encode( $actual ) ); }
	function choices_case( string $name, callable $test ): void { global $passed, $failed; try { $test(); $passed++; echo 'PASS ' . $name . "\n"; } catch ( \Throwable $e ) { $failed++; echo 'FAIL ' . $name . ': ' . $e->getMessage() . "\n"; } }
	function choices_error( mixed $value, ?string $code = null ): void { choices_check( is_wp_error( $value ), 'Expected controlled WP_Error' ); choices_same( 400, $value->get_error_data()['status'] ?? null, 'Controlled HTTP status' ); if ( null !== $code ) { choices_same( $code, $value->get_error_code(), 'Controlled error code' ); } }
	function choices_fixture(): array {
		$systems = array(); $prices = array( 7000, 9000, 13000, 10000, 12000, 12000 );
		for ( $n = 0; $n < 2; $n++ ) {
			$choices = array();
			foreach ( array( 'Silver', 'Gold', 'Platinum' ) as $i => $label ) {
				$index = $n * 3 + $i;
				$choices[] = array( 'choice_id' => 'system-' . ( $n + 1 ) . '-' . strtolower( $label ), 'label' => $label,
					'equipment' => array( 'package' => array( 'item_id' => (string) ( 9000000001 + $index ) ) ), 'sales_price' => $prices[ $index ] );
			}
			$systems[] = array( 'system_number' => $n + 1, 'system_label' => 'Fixture System ' . ( $n + 1 ), 'choices' => $choices,
				'selected_choice_id' => $choices[0]['choice_id'], 'equipment' => array( 'invented_hidden' => array( 'item_id' => '99999999999', 'rate' => 1 ) ) );
		}
		return array( 'template_id' => 1, 'customer' => array( 'zoho_account_id' => '20000000001' ), 'systems' => $systems, 'options' => array(), 'pricing' => array( 'subtotal' => 63000, 'total' => 17000 ) );
	}
	function choices_catalog(): array {
		$items = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$id = (string) ( 9000000001 + $i );
			$items[ $id ] = array( 'item_id' => $id, 'name' => 'Fixture Package ' . $i, 'rate' => (float) ( ( $i + 1 ) * 1000 ),
				'equipment_type' => 'package', 'selection_category' => 'package', 'is_composite' => true, 'for_estimate' => true, 'package_tagged' => true );
		}
		return $items;
	}
	function reset_choices(): void {
		$p = new \ReflectionProperty( Equipment_Catalog::class, 'instance' ); $p->setAccessible( true ); $p->setValue( null, null );
		Zoho_API::instance()->calls = Zoho_API::instance()->items = array();
		Audit_Log::instance()->records = array(); Audit_Log::instance()->existing = null;
		Estimate_Generator::instance()->body = Token_Renderer::instance()->body = array();
		foreach ( choices_catalog() as $id => $item ) {
			Zoho_API::instance()->items[ $id ] = array( 'item_id' => (string) $id, 'name' => $item['name'], 'rate' => $item['rate'], 'status' => 'active', 'is_combo_product' => true,
				'tags' => array( array( 'tag_id' => '70000000001', 'tag_option_id' => '80000000001' ) ) );
		}
		foreach ( array( '9000000091' => 'New Residential System Maintenance Agreement', '9000000092' => 'Permits & Inspections' ) as $id => $name ) {
			Zoho_API::instance()->items[ $id ] = array( 'item_id' => (string) $id, 'name' => $name, 'rate' => 0, 'status' => 'active', 'is_combo_product' => false );
		}
	}
	function projected_choices( array $body, ?array $catalog = null ): array|WP_Error {
		$normalized = Proposal_Choices::instance()->normalize( $body );
		return is_wp_error( $normalized ) ? $normalized : Proposal_Choices::instance()->project( $normalized, $catalog ?? choices_catalog() );
	}
	choices_case( 'Two physical systems keep all offers but charge only selections: 17k,19k,23k,25k, never63k', function (): void {
		reset_choices();
		foreach ( array( array( 0, 0, 17000 ), array( 1, 0, 19000 ), array( 2, 0, 23000 ), array( 2, 1, 25000 ) ) as [ $a, $b, $total ] ) {
			$body = choices_fixture(); $body['systems'][0]['selected_choice_id'] = $body['systems'][0]['choices'][ $a ]['choice_id']; $body['systems'][1]['selected_choice_id'] = $body['systems'][1]['choices'][ $b ]['choice_id']; $body['pricing']['total'] = $total;
			$p = projected_choices( $body ); choices_check( ! is_wp_error( $p ), 'Selected combination projects' );
			choices_same( (float) $total, $p['pricing']['total'], 'Selected-only installed sale total' );
			choices_same( 2, count( $p['systems'] ), 'Two physical systems' );
			choices_same( array( 'package' ), array_keys( $p['systems'][0]['equipment'] ), 'Hidden mirror ignored' );
			choices_same( 3, count( $p['_proposal_systems'][0]['choices'] ), 'All offers remain available for display' );
			choices_check( ! isset( $p['systems'][0]['choices'] ), 'No alternatives enter transaction systems' );
			choices_same( (float) ( ( $a + 1 + $b + 4 ) * 1000 ), $p['pricing']['subtotal'], 'Books subtotal uses catalog sales rates, not installed option prices' );
		}
	} );
	choices_case( 'Collect every canonical choice ID once, never the mirror; editing does not select', function (): void {
		reset_choices(); $b = choices_fixture(); $b['systems'][0]['choices'][1]['sales_price'] = 9999;
		$n = Proposal_Choices::instance()->normalize( $b );
		choices_same( 6, count( Proposal_Choices::instance()->collect_item_ids( $n ) ), 'Six offered item identities' );
		choices_check( ! in_array( '99999999999', Proposal_Choices::instance()->collect_item_ids( $n ), true ), 'Mirror item never hydrated' );
		choices_same( 'system-1-silver', $n['systems'][0]['selected_choice_id'], 'Option edit never selects another choice' );
	} );
	choices_case( 'Missing option price defaults to actual rate; common adjustment supports cents and discounts', function (): void {
		reset_choices(); $b = choices_fixture(); unset( $b['pricing']['total'] );
		foreach ( $b['systems'] as &$s ) { foreach ( $s['choices'] as &$c ) { unset( $c['sales_price'] ); } unset( $c ); } unset( $s );
		$b['pricing']['project_adjustment'] = -100.125; $p = projected_choices( $b );
		choices_same( 4899.87, $p['pricing']['total'], 'Selected actual sales rates minus rounded common discount' );
		choices_same( 1000.0, $p['_proposal_systems'][0]['choices'][0]['sales_price'], 'Resolved offer price retained' );
	} );
	choices_case( 'Costs, hydrated metadata and client internal projection injection cannot survive normalization', function (): void {
		reset_choices(); $b = choices_fixture(); $b['_proposal_addons'] = array( 'forged' => true ); $b['_proposal_systems'] = array( 'forged' => true ); $b['purchase_cost'] = 1;
		$b['pricing']['equipment_cost'] = 1; $b['systems'][0]['choices'][0]['equipment']['package'] += array( 'rate' => 1, 'purchase_cost' => 1, 'is_composite' => false, 'for_estimate' => true, '_proposal_hidden' => true );
		$n = Proposal_Choices::instance()->normalize( $b );
		foreach ( array( '_proposal_addons', '_proposal_systems', 'purchase_cost' ) as $key ) { choices_check( ! array_key_exists( $key, $n ), 'Client field removed: ' . $key ); }
		choices_check( ! array_key_exists( 'equipment_cost', $n['pricing'] ), 'No internal pricing cost' );
		choices_same( array( 'item_id' ), array_keys( $n['systems'][0]['choices'][0]['equipment']['package'] ), 'Item identity only, no client rates/metadata' );
		$p = Proposal_Choices::instance()->project( $n, choices_catalog() ); choices_same( array(), $p['_proposal_addons'], 'Add-ons rebuilt from server authority' );
	} );
	choices_case( 'Labels and multiline item text are sanitized and bounded', function (): void {
		reset_choices(); $b = choices_fixture(); $b['systems'][0]['choices'][0]['label'] = '<b>' . str_repeat( 'X', 70 ) . '</b>';
		$b['systems'][0]['choices'][0]['equipment']['package']['description'] = '<script>unsafe</script>' . str_repeat( 'D', 2100 );
		$b['systems'][0]['choices'][0]['equipment']['package']['notes'] = str_repeat( 'N', 2100 );
		$n = Proposal_Choices::instance()->normalize( $b ); $c = $n['systems'][0]['choices'][0];
		choices_same( 60, strlen( $c['label'] ), 'Label cap' ); choices_same( 2000, strlen( $c['equipment']['package']['description'] ), 'Description cap' ); choices_same( 2000, strlen( $c['equipment']['package']['notes'] ), 'Notes cap' ); choices_check( ! str_contains( $c['equipment']['package']['description'], '<' ), 'Markup removed' );
	} );
	$mutations = array(
		'missing selected ID' => static function ( &$b ) { unset( $b['systems'][0]['selected_choice_id'] ); },
		'unknown selected ID' => static function ( &$b ) { $b['systems'][0]['selected_choice_id'] = 'not-offered'; },
		'duplicate choice IDs globally' => static function ( &$b ) { $b['systems'][1]['choices'][1]['choice_id'] = $b['systems'][0]['choices'][0]['choice_id']; },
		'invalid choice ID' => static function ( &$b ) { $b['systems'][0]['choices'][0]['choice_id'] = 'bad/id'; },
		'oversized choice ID' => static function ( &$b ) { $b['systems'][0]['choices'][0]['choice_id'] = str_repeat( 'X', 65 ); },
		'empty choices' => static function ( &$b ) { $b['systems'][0]['choices'] = array(); },
		'empty equipment' => static function ( &$b ) { $b['systems'][0]['choices'][0]['equipment'] = array(); },
		'malformed description' => static function ( &$b ) { $b['systems'][0]['choices'][0]['equipment']['package']['description'] = array(); },
		'non-list systems' => static function ( &$b ) { $b['systems'] = array( 'named' => $b['systems'][0] ); },
		'duplicate physical numbers' => static function ( &$b ) { $b['systems'][1]['system_number'] = 1; },
		'null physical number' => static function ( &$b ) { $b['systems'][0]['system_number'] = null; },
		'noncanonical physical number' => static function ( &$b ) { $b['systems'][0]['system_number'] = '01'; },
		'malformed item ID' => static function ( &$b ) { $b['systems'][0]['choices'][0]['equipment']['package']['item_id'] = '../bad'; },
		'negative sales price' => static function ( &$b ) { $b['systems'][0]['choices'][0]['sales_price'] = -1; },
		'string sales price' => static function ( &$b ) { $b['systems'][0]['choices'][0]['sales_price'] = '7000'; },
		'boolean sales price' => static function ( &$b ) { $b['systems'][0]['choices'][0]['sales_price'] = true; },
		'infinite sales price' => static function ( &$b ) { $b['systems'][0]['choices'][0]['sales_price'] = INF; },
		'oversized sales price' => static function ( &$b ) { $b['systems'][0]['choices'][0]['sales_price'] = 10000001; },
		'string adjustment' => static function ( &$b ) { $b['pricing']['project_adjustment'] = '5'; },
		'null adjustment' => static function ( &$b ) { $b['pricing']['project_adjustment'] = null; },
		'NaN adjustment' => static function ( &$b ) { $b['pricing']['project_adjustment'] = NAN; },
		'oversized positive adjustment' => static function ( &$b ) { $b['pricing']['project_adjustment'] = 10000001; },
		'oversized negative adjustment' => static function ( &$b ) { $b['pricing']['project_adjustment'] = -10000001; },
		'nonfinite total' => static function ( &$b ) { $b['pricing']['total'] = INF; },
		'string total' => static function ( &$b ) { $b['pricing']['total'] = '17000'; },
		'mismatched total' => static function ( &$b ) { $b['pricing']['total'] = 63000; },
		'more than ten systems' => static function ( &$b ) { $b['systems'] = array_fill( 0, 11, $b['systems'][0] ); },
		'more than six alternatives' => static function ( &$b ) { $b['systems'][0]['choices'] = array_fill( 0, 7, $b['systems'][0]['choices'][0] ); },
		'nonboolean add-on flag' => static function ( &$b ) { $b['options']['include_maintenance'] = 'true'; },
	);
	foreach ( $mutations as $name => $mutate ) { choices_case( 'Reject ' . $name, function () use ( $mutate ): void { reset_choices(); $b = choices_fixture(); $mutate( $b ); choices_error( projected_choices( $b ) ); choices_same( array(), Zoho_API::instance()->calls, 'Invalid request needs no provider reads' ); } ); }
	choices_case( 'Every alternative is category validated even when it is not selected', function (): void {
		reset_choices(); $b = choices_fixture(); $b['systems'][0]['choices'][2]['equipment'] = array( 'furnace' => array( 'item_id' => '9000000003' ) ); choices_error( projected_choices( $b ), 'tc_estimate_bad_equipment_selection' );
		$b = choices_fixture(); $b['systems'][0]['choices'][2]['equipment']['furnace'] = array( 'item_id' => '9000000002' ); choices_error( projected_choices( $b ), 'tc_estimate_bad_equipment_selection' );
	} );
	choices_case( 'At most 200 distinct canonical item IDs can trigger provider reads', function (): void {
		reset_choices();
		foreach ( array( 5 => 200, 6 => 240 ) as $count => $total ) {
			$b = choices_fixture(); $b['systems'] = array(); $next = 9000000000;
			for ( $s = 1; $s <= 10; $s++ ) {
				$choices = array();
				for ( $c = 1; $c <= $count; $c++ ) {
					$equipment = array(); foreach ( array( 'furnace', 'condenser', 'coil', 'thermostat' ) as $slot ) { $equipment[ $slot ] = array( 'item_id' => (string) ++$next ); }
					$choices[] = array( 'choice_id' => 's' . $s . '-c' . $c, 'label' => 'Fixture option', 'equipment' => $equipment );
				}
				$b['systems'][] = array( 'system_number' => $s, 'choices' => $choices, 'selected_choice_id' => $choices[0]['choice_id'] );
			}
			$n = Proposal_Choices::instance()->normalize( $b );
			if ( 200 === $total ) { choices_check( ! is_wp_error( $n ), 'Boundary allows two hundred unique item IDs' ); choices_same( 200, count( Proposal_Choices::instance()->collect_item_ids( $n ) ), 'Distinct ID bound is exact' ); }
			else { choices_error( $n, 'tc_estimate_bad_proposal_choices' ); }
			choices_same( array(), Zoho_API::instance()->calls, 'No provider reads during normalization' );
		}
	} );
	choices_case( 'Choice migration preserves the legacy paid-permit override without hiding it in adjustment', function (): void {
		reset_choices(); $b = choices_fixture(); $id = '9000000099';
		$b['systems'][0]['choices'][0]['equipment']['permit'] = array( 'item_id' => $id, 'rate' => 375.126 );
		unset( $b['systems'][0]['choices'][0]['sales_price'], $b['pricing']['total'] );
		$catalog = choices_catalog(); $catalog[ $id ] = array( 'item_id' => $id, 'rate' => 125.0, 'equipment_type' => 'permit', 'selection_category' => '', 'is_composite' => false, 'for_estimate' => true, 'package_tagged' => true );
		$p = projected_choices( $b, $catalog ); choices_check( ! is_wp_error( $p ), 'Paid-permit migrated choice is valid' );
		choices_same( 375.13, $p['systems'][0]['equipment']['permit']['rate'], 'Selected permit override survives' );
		choices_same( 1375.13, $p['_proposal_systems'][0]['choices'][0]['sales_price'], 'Default option price includes actual selected permit amount' );
		choices_same( 5375.13, $p['pricing']['subtotal'], 'Books sales subtotal keeps paid permit on its own line' );
		choices_same( 11375.13, $p['pricing']['total'], 'Chosen installed total includes the paid permit only once' );
	} );
	choices_case( 'Provided totals within one cent normalize to the exact formula; larger mismatch rejects', function (): void {
		reset_choices(); foreach ( array( -0.01, 0.01 ) as $delta ) { $b = choices_fixture(); $b['pricing']['total'] += $delta; $p = projected_choices( $b ); choices_check( ! is_wp_error( $p ), 'One-cent transport tolerance' ); choices_same( 17000.0, $p['pricing']['total'], 'Canonical total uses selected prices' ); }
		$b['pricing']['total'] = 17000.02; choices_error( projected_choices( $b ), 'tc_estimate_bad_pricing' );
	} );
	choices_case( 'Same catalog item across alternatives/systems is permitted, duplicates within a choice fail', function (): void {
		reset_choices(); $b = choices_fixture(); $b['systems'][1]['choices'][0]['equipment'] = $b['systems'][0]['choices'][0]['equipment']; choices_check( ! is_wp_error( projected_choices( $b ) ), 'Reuse across separate physical systems is valid' );
		$b['systems'][0]['choices'][0]['equipment']['other'] = array( 'item_id' => '9000000001' ); choices_error( projected_choices( $b ), 'tc_estimate_bad_equipment_selection' );
	} );
	choices_case( 'Legacy manual total is preserved and mixed legacy/choices contribute only selected prices', function (): void {
		reset_choices(); $b = choices_fixture(); $legacy = array( 'system_number' => 2, 'system_label' => 'Legacy fixture', 'equipment' => array( 'package' => array( 'item_id' => '9000000004' ) ) );
		$b['systems'][1] = $legacy; $b['pricing']['total'] = 11000; $p = projected_choices( $b ); choices_same( 11000.0, $p['pricing']['total'], 'Chosen7k plus legacy catalog4k' );
		$b['systems'] = array( $legacy ); $b['pricing'] = array( 'subtotal' => 4000, 'total' => 9000 ); $p = projected_choices( $b ); choices_same( 9000, $p['pricing']['total'], 'Legacy-only manual total unchanged' );
	} );
	choices_case( 'Strict server-resolved zero-price add-ons add no project charge and no cost metadata', function (): void {
		reset_choices(); $b = choices_fixture(); $b['options'] = array( 'include_maintenance' => true, 'include_permits' => true, 'addon_item_id' => 'forged' ); $p = projected_choices( $b );
		choices_check( ! is_wp_error( $p ), 'Both canonical add-ons resolve' ); choices_same( 17000.0, $p['pricing']['total'], 'Zero-price add-ons never change project price' );
		choices_same( array( 'maintenance', 'permits' ), array_keys( $p['_proposal_addons'] ), 'Server add-on keys only' ); choices_same( 0.0, $p['_proposal_addons']['maintenance']['rate'], 'Zero first-year charge' ); choices_same( 349.0, $p['_proposal_addons']['maintenance']['renewal_rate'], 'Explicit future renewal' );
		choices_check( ! isset( $p['_proposal_addons']['maintenance']['purchase_cost'] ), 'No internal add-on cost' );
	} );
	choices_case( 'Paperwork option rejects a permit in any offered or legacy system', function (): void {
		reset_choices(); $b = choices_fixture(); $b['options']['include_permits'] = true; $b['systems'][0]['choices'][2]['equipment']['permit'] = array( 'item_id' => '9000000092', 'rate' => 0 );
		$e = projected_choices( $b ); choices_error( $e, 'tc_estimate_duplicate_permit_option' ); choices_check( str_contains( $e->get_error_message(), 'Remove the existing permit item' ), 'Clear conflict resolution message' ); choices_same( array(), Zoho_API::instance()->calls, 'Reject before add-on/provider reads' );
		$b['systems'] = array( array( 'system_number' => 1, 'equipment' => array( 'permit' => array( 'item_id' => '9000000092', 'rate' => 0 ) ) ) ); choices_error( projected_choices( $b ), 'tc_estimate_duplicate_permit_option' );
	} );
	choices_case( 'Unavailable selected add-on fails closed', function (): void {
		reset_choices(); unset( Zoho_API::instance()->items['9000000091'] ); $b = choices_fixture(); $b['options']['include_maintenance'] = true;
		choices_error( projected_choices( $b ), 'tc_estimate_proposal_addon_unavailable' );
	} );
	choices_case( 'Preview and Generate hydrate every offer and pass selected-only projection before writes', function (): void {
		foreach ( array( Preview_Estimate::instance(), Generate_Estimate::instance() ) as $endpoint ) {
			reset_choices(); $response = $endpoint->handle( new WP_REST_Request( choices_fixture(), array( 'idempotency_key' => 'fixture-choice-key' ) ) ); choices_check( in_array( $response->get_status(), array( 200, 201 ), true ), 'Endpoint succeeds' );
			$body = Token_Renderer::instance()->body; choices_same( 2, count( $body['systems'] ), 'Only two selected physical systems reach render/generate boundary' ); choices_same( 17000.0, $body['pricing']['total'], 'Selected total at boundary' ); choices_same( 3, count( $body['_proposal_systems'][0]['choices'] ), 'All offers stay separate' );
			$details = array_filter( Zoho_API::instance()->calls, static fn ( $call ) => str_starts_with( $call[0], '/items/' ) ); choices_same( 6, count( $details ), 'All six offers freshly hydrated, mirror ignored' );
		}
	} );
	choices_case( 'Invalid unselected offer prevents renderer, audit and generator activity', function (): void {
		foreach ( array( Preview_Estimate::instance(), Generate_Estimate::instance() ) as $endpoint ) {
			reset_choices(); $b = choices_fixture(); $b['systems'][0]['choices'][2]['equipment'] = array( 'furnace' => array( 'item_id' => '9000000003' ) ); $r = $endpoint->handle( new WP_REST_Request( $b, array( 'idempotency_key' => 'fixture-choice-key' ) ) ); choices_same( 400, $r->get_status(), 'Invalid alternative rejected' ); choices_same( array(), Token_Renderer::instance()->body, 'No rendering' ); choices_same( array(), Audit_Log::instance()->records, 'No pending audit' ); choices_same( array(), Estimate_Generator::instance()->body, 'No generation' );
		}
	} );
	choices_case( 'Successful prior replay remains ahead of new choice normalization and hydration', function (): void {
		reset_choices(); Audit_Log::instance()->existing = array( 'status' => 'success', 'zoho_estimate_id' => '40000000001', 'zoho_deal_id' => '50000000001', 'payload' => '{}', 'template_id' => 1, 'template_version' => 1, 'idempotency_key' => 'fixture-choice-key' );
		$b = choices_fixture(); $b['systems'][0]['selected_choice_id'] = 'invalid-now'; $r = Generate_Estimate::instance()->handle( new WP_REST_Request( $b, array( 'idempotency_key' => 'fixture-choice-key' ) ) ); choices_same( 200, $r->get_status(), 'Prior completed response replays' ); choices_same( true, $r->get_data()['data']['replayed'], 'Replay identified' ); choices_same( array(), Zoho_API::instance()->calls, 'No fresh reads' ); choices_same( array(), Audit_Log::instance()->records, 'No new audit row' );
	} );
	echo "Proposal choices: {$passed} passed, {$failed} failed, {$assertions} assertions.\n";
	exit( $failed ? 1 : 0 );
}
