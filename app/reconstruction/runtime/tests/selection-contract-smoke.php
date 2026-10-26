<?php
/**
 * Package rendering/pricing and endpoint boundary tests. All providers and
 * WordPress persistence are in-memory doubles; no network or email is loaded.
 * Run separately: php app/reconstruction/runtime/tests/selection-contract-smoke.php
 */
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', '/' );
	define( 'TC_ESTIMATE_VERSION', 'selection-contract-test' );
	define( 'TC_ESTIMATE_CACHE_TTL_CATALOG', 43200 );
	define( 'TC_ESTIMATE_CACHE_TTL_CUSTOMER', 300 );
	require dirname( __DIR__, 4 ) . '/tests/standalone-shims.php';
	function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function sanitize_textarea_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function get_option( string $name, mixed $default = false ): mixed { return 'tc_estimate_zoho_org_id' === $name ? '60000000001' : $default; }
	function get_current_user_id(): int { return 1; }
	function is_email( string $value ): bool { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function get_post( int $id ): ?object { return 1 === $id ? $GLOBALS['selection_template'] : null; }
	function wp_mail( mixed ...$args ): never { throw new \LogicException( 'Email is forbidden in this test.' ); }
	function wp_remote_request( mixed ...$args ): never { throw new \LogicException( 'HTTP is forbidden in this test.' ); }
	final class WP_REST_Request {
		public function __construct( private array $body, private array $headers = array() ) {}
		public function get_json_params(): array { return $this->body; }
		public function get_header( string $key ): string { return $this->headers[ $key ] ?? ''; }
	}
	final class WP_REST_Response {
		public function __construct( private mixed $data, private int $status = 200 ) {}
		public function get_data(): mixed { return $this->data; }
		public function get_status(): int { return $this->status; }
		public function header( string $key, string $value ): void {}
	}
}

namespace TempControl\Estimate {
	final class Security {
		public static function instance(): self { static $instance; return $instance ??= new self(); }
		public function sanitize_zoho_id( string $id ): string { return preg_match( '/^[0-9]+$/', $id ) ? $id : ''; }
		public function gate_request( string $capability = '' ): bool { return true; }
	}
	final class Rate_Limiter {
		public static function instance(): self { static $instance; return $instance ??= new self(); }
		public function consume( string $bucket ): bool { return true; }
		public function consume_generate(): bool { return true; }
	}
	final class Template_CPT { public const POST_TYPE = 'estimate_template'; }
	final class Template_Meta {
		public static function instance(): self { static $instance; return $instance ??= new self(); }
		public function hydrate( int $id ): array { return array( 'id' => $id, 'version' => 2, 'name' => 'Synthetic Proposal', 'template_type' => 'full_replacement' ); }
	}
	final class Customer_Search {
		public int $reads = 0;
		public static function instance(): self { static $instance; return $instance ??= new self(); }
		public function get_account( string $id ): array { $this->reads++; return array( 'id' => $id, 'name' => 'Synthetic Customer', 'email' => 'customer@example.test' ); }
	}
	final class Audit_Log {
		public ?array $existing = null;
		public array $records = array();
		public array $updates = array();
		public static function instance(): self { static $instance; return $instance ??= new self(); }
		public function find_by_idempotency_key( string $key, int $user ): ?array { return $this->existing; }
		public function record( array $record ): int { $this->records[] = $record; return count( $this->records ); }
		public function update( int $id, array $record ): void { $this->updates[] = $record; }
	}
	final class Zoho_Cache {
		public array $values = array();
		public static function instance(): self { static $instance; return $instance ??= new self(); }
		public function get( string $key ): mixed { return $this->values[ $key ] ?? null; }
		public function set( string $key, mixed $value, int $ttl ): void { $this->values[ $key ] = $value; }
		public function delete( string $key ): void { unset( $this->values[ $key ] ); }
		public function remember( string $key, int $ttl, callable $callback ): mixed { return $this->values[ $key ] ??= $callback(); }
	}
	final class Zoho_API {
		public const SERVICE_BOOKS = 'books';
		public const SERVICE_CRM = 'crm';
		public array $items = array();
		public array $reads = array();
		public array $writes = array();
		public static function instance(): self { static $instance; return $instance ??= new self(); }
		public function get( string $service, string $path, array $query = array() ): array|\WP_Error {
			$this->reads[] = $path;
			if ( self::SERVICE_BOOKS !== $service ) { throw new \LogicException( 'Unexpected service.' ); }
			if ( '/reportingtags' === $path ) { return array( 'code' => 0, 'tags' => array( array( 'tag_id' => '70000000001', 'tag_name' => 'Package', 'is_active' => true ) ) ); }
			if ( '/reportingtags/70000000001/options/all' === $path ) { return array( 'results' => array( array( 'option_id' => '80000000001', 'option_name' => 'Package' ) ) ); }
			if ( '/contacts' === $path ) { return array( 'code' => 0, 'contacts' => array( array( 'contact_id' => '30000000001', 'email' => 'customer@example.test' ) ) ); }
			if ( str_starts_with( $path, '/items/' ) ) {
				$id = substr( $path, strlen( '/items/' ) );
				return isset( $this->items[ $id ] ) ? array( 'code' => 0, 'item' => $this->items[ $id ] ) : new \WP_Error( 'fixture_missing', 'Synthetic item missing.', array( 'status' => 404 ) );
			}
			throw new \LogicException( 'Unexpected synthetic GET: ' . $path );
		}
		public function post( string $service, string $path, array $body ): array {
			$this->writes[] = array( 'method' => 'POST', 'service' => $service, 'path' => $path, 'body' => $body );
			if ( self::SERVICE_BOOKS === $service && '/estimates?organization_id=60000000001&send=false' === $path ) {
				return array( 'code' => 0, 'estimate' => array( 'estimate_id' => '40000000001', 'estimate_number' => 'FIXTURE-1', 'status' => 'draft' ) );
			}
			if ( self::SERVICE_CRM === $service && '/Deals' === $path ) { return array( 'data' => array( array( 'code' => 'SUCCESS', 'details' => array( 'id' => '50000000001' ) ) ) ); }
			throw new \LogicException( 'Unexpected synthetic POST: ' . $path );
		}
		public function put( string $service, string $path, array $body ): array {
			if ( self::SERVICE_CRM !== $service || '/Deals/50000000001' !== $path ) { throw new \LogicException( 'Unexpected synthetic PUT.' ); }
			$this->writes[] = array( 'method' => 'PUT', 'service' => $service, 'path' => $path, 'body' => $body );
			return array( 'data' => array( array( 'code' => 'SUCCESS' ) ) );
		}
		public function delete( string $service, string $path, array $query ): never { throw new \LogicException( 'Unexpected deletion.' ); }
	}
}

namespace {
	use TempControl\Estimate\Audit_Log;
	use TempControl\Estimate\Customer_Search;
	use TempControl\Estimate\Equipment_Catalog;
	use TempControl\Estimate\Estimate_Generator;
	use TempControl\Estimate\Template_Meta;
	use TempControl\Estimate\Token_Renderer;
	use TempControl\Estimate\Zoho_API;
	use TempControl\Estimate\Zoho_Cache;
	use TempControl\Estimate\Endpoints\Generate_Estimate;
	use TempControl\Estimate\Endpoints\Preview_Estimate;

	$reference = dirname( __DIR__, 4 );
	$runtime = dirname( __DIR__ );
	require $reference . '/includes/class-mustache-shim.php';
	require $runtime . '/includes/class-equipment-catalog.php';
	require $runtime . '/includes/class-token-renderer.php';
	require $runtime . '/includes/class-estimate-generator.php';
	require $reference . '/endpoints/class-endpoint-base.php';
	require $runtime . '/endpoints/class-preview-estimate.php';
	require $runtime . '/endpoints/class-generate-estimate.php';

	const PACKAGE_ID = '90000000001';
	const FURNACE_ID = '90000000002';
	const AC_ID = '90000000003';
	const COIL_ID = '90000000004';
	const PERMIT_ID = '90000000005';
	$passed = $failed = $assertions = 0;
	function selection_check( bool $value, string $message ): void {
		global $assertions;
		$assertions++;
		if ( ! $value ) { throw new \RuntimeException( $message ); }
	}
	function selection_same( mixed $expected, mixed $actual, string $message ): void { selection_check( $expected === $actual, $message . ': ' . json_encode( $actual ) ); }
	function selection_case( string $label, callable $test ): void {
		global $passed, $failed;
		try { $test(); $passed++; echo 'PASS ' . $label . "\n"; }
		catch ( \Throwable $error ) { $failed++; echo 'FAIL ' . $label . ': ' . $error->getMessage() . "\n"; }
	}
	function raw_selection_item( string $id, string $name, string $type, float $rate ): array {
		return array( 'item_id' => $id, 'name' => $name, 'status' => 'active', 'rate' => $rate,
			'cf_equipment_type' => $type, 'cf_brand' => 'Fixture Brand', 'cf_model' => 'FIXTURE-MODEL',
			'is_combo_product' => 'package' === $type, 'description' => 'Synthetic description',
			'tags' => array( array( 'tag_id' => '70000000001', 'tag_option_id' => '80000000001' ) ) );
	}
	function reset_selection(): void {
		$property = new \ReflectionProperty( Equipment_Catalog::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
		$api = Zoho_API::instance();
		$api->reads = $api->writes = array();
		$api->items = array(
			PACKAGE_ID => raw_selection_item( PACKAGE_ID, 'Complete <Matched> Package & Scope', 'package', 9400 ),
			FURNACE_ID => raw_selection_item( FURNACE_ID, 'Synthetic Furnace', 'furnace', 3000 ),
			AC_ID => raw_selection_item( AC_ID, 'Synthetic AC', 'condenser', 4000 ),
			COIL_ID => raw_selection_item( COIL_ID, 'Synthetic Coil', 'evaporator_coil', 1200 ),
			PERMIT_ID => raw_selection_item( PERMIT_ID, 'Synthetic Permit', 'permit', 200 ),
		);
		$api->items[ PACKAGE_ID ]['description'] = 'Install <matched> system & startup';
		Zoho_Cache::instance()->values = array();
		Audit_Log::instance()->existing = null;
		Audit_Log::instance()->records = Audit_Log::instance()->updates = array();
		Customer_Search::instance()->reads = 0;
		$GLOBALS['selection_template'] = (object) array( 'ID' => 1, 'post_type' => 'estimate_template', 'post_status' => 'publish',
			'post_content' => '<h3>Equipment to Be Installed</h3>{{#systems}}<h4>{{system_label}}</h4><ul>{{#furnace}}{{name}}{{/furnace}}{{#has_other}}{{#other}}{{name}}{{/other}}{{/has_other}}</ul>{{/systems}}<h3>Scope of Work</h3>' );
	}
	function selection_payload( ?array $equipment = null ): array {
		return array( 'template_id' => 1, 'customer' => array( 'zoho_account_id' => '20000000001' ),
			'systems' => array( array( 'system_number' => 1, 'system_label' => 'Main <Floor>', 'equipment' => $equipment ?? array( 'package' => array( 'item_id' => PACKAGE_ID ) ) ) ),
			'pricing' => array( 'subtotal' => 9400, 'total' => 9400 ) );
	}
	function selected_view( array $payload ): array {
		$lookup = array();
		foreach ( Zoho_API::instance()->items as $id => $raw ) {
			$item = Equipment_Catalog::instance()->get_item( (string) $id );
			selection_check( ! is_wp_error( $item ), 'Fixture must hydrate.' );
			$lookup[ $id ] = $item;
		}
		return Token_Renderer::instance()->build_payload_view( $payload, array(), $lookup, Template_Meta::instance()->hydrate( 1 ) );
	}
	function selection_request( array $payload ): WP_REST_Request { return new WP_REST_Request( $payload, array( 'idempotency_key' => 'synthetic-selection-key' ) ); }
	function selection_error_response( WP_REST_Response $response, string $code ): void {
		selection_same( 400, $response->get_status(), 'Invalid selection HTTP status' );
		selection_same( $code, $response->get_data()['error']['code'] ?? '', 'Invalid selection error code' );
		selection_same( array(), Audit_Log::instance()->records, 'No pending audit record for invalid selection' );
		selection_same( array(), Zoho_API::instance()->writes, 'No provider writes for invalid selection' );
	}

	selection_case( 'Package is a known slot, one items entry and no other entry', function (): void {
		reset_selection(); $view = selected_view( selection_payload() );
		selection_same( PACKAGE_ID, $view['systems'][0]['package']['item_id'], 'Known package identity' );
		selection_same( 1, count( $view['systems'][0]['items'] ), 'No expanded children' );
		selection_same( array(), $view['systems'][0]['other'], 'No duplicate other entry' );
	} );
	selection_case( 'Legacy other template places package in equipment section without price or bottom duplicate', function (): void {
		reset_selection(); $view = selected_view( selection_payload() );
		$html = Token_Renderer::instance()->render( $GLOBALS['selection_template']->post_content, $view );
		selection_check( is_string( $html ), 'Rendered proposal' );
		selection_same( 1, substr_count( $html, 'Complete &lt;Matched&gt; Package &amp; Scope' ), 'One package name' );
		selection_same( 1, substr_count( $html, 'Install &lt;matched&gt; system &amp; startup' ), 'One escaped description' );
		selection_same( 0, substr_count( $html, '$9,400.00' ), 'No equipment line price' );
		selection_check( str_contains( $html, 'Main &lt;Floor&gt;' ) && ! str_contains( $html, '<Matched>' ), 'Escaped system and package text' );
		selection_check( strpos( $html, 'Equipment to Be Installed' ) < strpos( $html, 'Complete &lt;Matched&gt;' ) && strpos( $html, 'Complete &lt;Matched&gt;' ) < strpos( $html, 'Scope of Work' ), 'Package stays inside the actual equipment section' );
		selection_check( ! str_contains( $html, '<h3>Package</h3>' ), 'No appended package block' );
	} );
	selection_case( 'Legacy package row does not repeat an identical package-name description', function (): void {
		reset_selection(); $payload = selection_payload();
		$payload['systems'][0]['equipment']['package']['description'] = 'Complete <Matched> Package & Scope';
		$view = selected_view( $payload );
		$html = Token_Renderer::instance()->render( $GLOBALS['selection_template']->post_content, $view );
		selection_same( 1, substr_count( $html, 'Complete &lt;Matched&gt; Package &amp; Scope' ), 'One package name when description matches it' );
		selection_same( 'Complete <Matched> Package & Scope', $view['systems'][0]['package']['short_description'], 'Internal selected description stays intact' );
	} );
	foreach ( array(
		'generic items' => '{{#systems}}{{#items}}{{display_name}} | {{short_description}} | {{rate_formatted}}{{/items}}{{/systems}}',
		'explicit package' => '{{#systems}}{{#package}}{{display_name}} | {{short_description}} | {{rate_formatted}}{{/package}}{{/systems}}',
		'dotted package' => '{{#systems}}{{package.display_name}} | {{package.short_description}} | {{package.rate_formatted}}{{/systems}}',
		'whitespace package' => '{{ # systems }}{{ # package }}{{ display_name }} | {{ short_description }} | {{ rate_formatted }}{{ / package }}{{ / systems }}',
		'whitespace items' => '{{ # systems }}{{ # items }}{{ display_name }} | {{ short_description }} | {{ rate_formatted }}{{ / items }}{{ / systems }}',
		'whitespace dotted' => '{{ # systems }}{{ package.display_name }} | {{ package.short_description }} | {{ package.rate_formatted }}{{ / systems }}',
	) as $label => $template ) {
		selection_case( $label . ' renders package without duplicate compatibility section', function () use ( $template ): void {
			reset_selection(); $html = Token_Renderer::instance()->render( $template, selected_view( selection_payload() ) );
			selection_check( is_string( $html ), 'Rendered proposal' );
			selection_same( 1, substr_count( $html, 'Complete &lt;Matched&gt; Package &amp; Scope' ), 'Package name appears once' );
			selection_same( 0, substr_count( $html, '$9,400.00' ), 'Equipment price is withheld even from explicit price tokens' );
			selection_check( ! str_contains( $html, '<h3>Package</h3>' ), 'No compatibility duplicate' );
		} );
	}
	selection_case( 'Prose comments inverted and invalid list-dot references do not suppress fallback', function (): void {
		reset_selection(); $view = selected_view( selection_payload() );
		foreach ( array( 'Package and items are words.', '{{! package items }}', '{{#systems}}{{^package}}Absent{{/package}}{{/systems}}', '{{systems.package.name}}',
			'<!-- {{#systems}}{{#package}}{{display_name}}{{/package}}{{/systems}} -->',
			'<!-- {{#systems}}{{#items}}{{display_name}}{{/items}}{{/systems}} -->' ) as $template ) {
			$html = Token_Renderer::instance()->render( $template, $view );
			selection_check( is_string( $html ) && str_contains( $html, '<h3>Package</h3>' ), 'Unsupported template receives visible package details' );
		}
	} );
	selection_case( 'Same package in two systems renders once per system', function (): void {
		reset_selection(); $payload = selection_payload(); $second = $payload['systems'][0];
		$second['system_number'] = 2; $second['system_label'] = 'Second Floor'; $payload['systems'][] = $second;
		$html = Token_Renderer::instance()->render( 'Legacy', selected_view( $payload ) );
		selection_same( 2, substr_count( $html, 'Complete &lt;Matched&gt; Package &amp; Scope' ), 'Two separate systems' );
		selection_check( str_contains( $html, 'Main &lt;Floor&gt;' ) && str_contains( $html, 'Second Floor' ), 'Both labels' );
	} );
	selection_case( 'Document copy removes every equipment financial alias and keeps sale summary and specifications', function (): void {
		reset_selection(); $view = selected_view( selection_payload() );
		$financial = array( 'rate' => 'RATE-SECRET', 'rate_formatted' => 'RATE-FORMATTED-SECRET', 'purchase_cost' => 'COST-SECRET', 'markup' => 'MARKUP-SECRET', 'sales_price' => 'PRICE-SECRET' );
		$item = array_merge( $view['systems'][0]['package'], $financial, array( 'tons' => 1.5, 'btu_input' => 100000 ) );
		$view['systems'][0]['package'] = $item;
		$view['systems'][0]['items'] = array( $item );
		$view['systems'][0]['other'] = array( $item );
		$view['pricing'] = array( 'subtotal' => 'SUBTOTAL-SECRET', 'subtotal_formatted' => 'SUBTOTAL-FORMATTED-SECRET', 'equipment_cost' => 'INTERNAL-COST-SECRET',
			'total' => 14000, 'total_formatted' => '$14,000.00', 'deposit_percent' => 35, 'deposit_amount' => 4900, 'deposit_amount_formatted' => '$4,900.00' );
		$before = $view;
		$template = '{{#systems}}{{#package}}{{rate}}{{rate_formatted}}{{purchase_cost}}{{markup}}{{sales_price}}{{tons}} tons {{btu_input}} BTU{{/package}}'
			. '{{#items}}{{rate}}{{rate_formatted}}{{purchase_cost}}{{markup}}{{sales_price}}{{/items}}{{#other}}{{rate}}{{rate_formatted}}{{purchase_cost}}{{markup}}{{sales_price}}{{/other}}{{/systems}}'
			. '{{pricing.subtotal}}{{pricing.subtotal_formatted}}{{pricing.equipment_cost}}Total {{pricing.total_formatted}} Deposit {{pricing.deposit_amount_formatted}}';
		$html = Token_Renderer::instance()->render( $template, $view );
		selection_check( is_string( $html ) && ! str_contains( $html, 'SECRET' ), 'No structured line price or internal cost token reaches HTML' );
		selection_check( str_contains( $html, '$14,000.00' ) && str_contains( $html, '$4,900.00' ), 'Sale total and deposit retained' );
		selection_check( str_contains( $html, '1.5 tons 100000 BTU' ), 'Equipment specifications retained' );
		selection_same( $before, $view, 'Rendering never mutates the internal view' );
	} );
	selection_case( 'Known legacy subtotal row is omitted while total and deposit remain', function (): void {
		reset_selection(); $payload = selection_payload(); $payload['pricing'] = array( 'subtotal' => 9400, 'total' => 14000, 'deposit_percent' => 35 );
		$view = selected_view( $payload );
		$template = $GLOBALS['selection_template']->post_content . '<table><tr><td style="padding:6px 0;">Subtotal</td><td style="text-align:right;">{{pricing.subtotal_formatted}}</td></tr>'
			. '<tr><td>Total Project Cost</td><td>{{pricing.total_formatted}}</td></tr><tr><td>Deposit</td><td>{{pricing.deposit_amount_formatted}}</td></tr></table>';
		$html = Token_Renderer::instance()->render( $template, $view );
		selection_check( is_string( $html ) && ! str_contains( $html, 'Subtotal' ) && ! str_contains( $html, '$9,400.00' ), 'No subtotal row or equipment sale price' );
		selection_check( str_contains( $html, '$14,000.00' ) && str_contains( $html, '$4,900.00' ), 'Only project sale total and deposit values' );
		selection_same( 1, substr_count( $html, 'Complete &lt;Matched&gt; Package &amp; Scope' ), 'One package within equipment section' );
	} );
	selection_case( 'Exact legacy condenser specifications stay balanced for every missing-field combination', function (): void {
		reset_selection(); $view = selected_view( selection_payload( array( 'condenser' => array( 'item_id' => AC_ID ) ) ) );
		$template = '{{#systems}}{{#condenser}}Condenser {{model}}{{#seer}} ({{seer}} SEER{{/seer}}{{#tons}}, {{tons}} ton{{/tons}}{{#refrigerant}}, {{refrigerant}}{{/refrigerant}}){{/condenser}}{{/systems}}';
		foreach ( array( null, 13.4 ) as $seer ) {
			foreach ( array( null, 1.5 ) as $tons ) {
				foreach ( array( null, 'R-410A' ) as $refrigerant ) {
					$view['systems'][0]['condenser']['seer'] = $seer;
					$view['systems'][0]['condenser']['tons'] = $tons;
					$view['systems'][0]['condenser']['refrigerant'] = $refrigerant;
					$html = Token_Renderer::instance()->render( $template, $view );
					selection_same( substr_count( $html, '(' ), substr_count( $html, ')' ), 'No unmatched legacy parenthesis' );
					selection_same( null !== $seer, str_contains( $html, '(13.4 SEER)' ), 'SEER group appears only when known' );
					selection_same( null !== $tons, str_contains( $html, ' — 1.5 ton' ), 'Tonnage is independently labeled' );
					selection_same( null !== $refrigerant, str_contains( $html, ' — R-410A' ), 'Refrigerant is independently labeled' );
				}
			}
		}
		$custom = '{{#systems}}{{#condenser}}{{model}} (authored layout){{/condenser}}{{/systems}}';
		selection_same( 'FIXTURE-MODEL (authored layout)', Token_Renderer::instance()->render( $custom, $view ), 'Unrelated authored punctuation is unchanged' );
	} );
	selection_case( 'Unknown layouts retain explicit escaped price-free package and permit fallback', function (): void {
		reset_selection(); $payload = selection_payload( array( 'package' => array( 'item_id' => PACKAGE_ID ), 'permit' => array( 'item_id' => PERMIT_ID ) ) );
		$html = Token_Renderer::instance()->render( '<p>Custom unsupported layout</p>', selected_view( $payload ) );
		selection_check( is_string( $html ) && str_contains( $html, '<h3>Package</h3>' ) && str_contains( $html, '<h3>Permit</h3>' ), 'Explicit fallback preserves selected equipment' );
		selection_check( ! str_contains( $html, '$' ), 'No fallback line price or misleading zero amount' );
		selection_same( 1, substr_count( $html, 'Complete &lt;Matched&gt; Package &amp; Scope' ), 'Unknown layout does not duplicate package' );
	} );
	selection_case( 'Package plus permit creates one Books package line and stable edits without double pricing', function (): void {
		reset_selection(); $payload = selection_payload( array( 'permit' => array( 'item_id' => PERMIT_ID ), 'package' => array( 'item_id' => PACKAGE_ID ) ) );
		$payload['pricing'] = array( 'subtotal' => 9600, 'total' => 10000 );
		$payload['books_edits'] = array( 'line_items' => array( array( 'key' => 'system_1_package_0', 'description' => 'Reviewed package scope' ) ) );
		$view = selected_view( $payload ); $generator = Estimate_Generator::instance();
		$preview = $generator->build_books_preview( $view, array(), array() );
		$method = new \ReflectionMethod( $generator, 'build_transaction_payload' ); $method->setAccessible( true );
		$transaction = $method->invoke( $generator, $payload, array(), $view, 'Rendered', array(), 'test-key', '60000000001' );
		$lines = $transaction['books']['line_items'];
		selection_same( array( PACKAGE_ID, PERMIT_ID ), array_column( $lines, 'item_id' ), 'One package and preserved accessory' );
		selection_same( array_column( $preview['line_items'], 'key' ), array_column( $lines, 'edit_key' ), 'Preview/transaction order and keys agree' );
		selection_same( 'system_1_package_0', $lines[0]['edit_key'], 'Stable package edit key' );
		selection_same( 1, $lines[0]['quantity'], 'One composite quantity' );
		selection_same( 9400.0, $lines[0]['rate'], 'Authoritative package rate once' );
		selection_same( 'Reviewed package scope', $lines[0]['description'], 'Package edit maps to its line' );
		selection_same( 400.0, $transaction['books']['adjustment'], 'Manual total adjustment uses selected subtotal once' );
		selection_same( 2, count( $transaction['crm']['quoted_equipment'] ), 'One package and one permit CRM row' );
		selection_same( 'package', $transaction['crm']['quoted_equipment'][0]['Slot'], 'Canonical CRM slot payload' );
		selection_check( str_contains( $transaction['books']['annexure_content'], 'Complete &lt;Matched&gt; Package &amp; Scope' ), 'Annexure retains escaped actual package name' );
		$before = $view;
		$customer_html = Token_Renderer::instance()->render( $GLOBALS['selection_template']->post_content, $view );
		$after_render = $method->invoke( $generator, $payload, array(), $view, $customer_html, array(), 'test-key', '60000000001' );
		selection_same( $before, $view, 'Customer rendering leaves the pricing view unchanged' );
		selection_same( $lines, $after_render['books']['line_items'], 'Books sales line prices and edit mapping unchanged after private rendering' );
		selection_same( 400.0, $after_render['books']['adjustment'], 'Project total adjustment remains sales-based' );
		selection_check( ! str_contains( $after_render['books']['annexure_content'], '$9,400.00' ) && ! str_contains( $after_render['books']['template_body'], '$9,400.00' ), 'Customer document channels omit package line amount' );
	} );
	selection_case( 'Annexure package name survives blank/different brand/model and is escaped', function (): void {
		$method = new \ReflectionMethod( Estimate_Generator::instance(), 'books_equipment_html' ); $method->setAccessible( true );
		foreach ( array( array( 'Brand' => '', 'Model' => '' ), array( 'Brand' => 'Brand', 'Model' => 'Model' ) ) as $brand ) {
			$html = $method->invoke( Estimate_Generator::instance(), array_merge( $brand, array( 'Slot' => 'package', 'Name' => 'Whole <System> & Scope', 'Short_Description' => 'Scope <details>' ) ) );
			selection_same( 1, substr_count( $html, 'Whole &lt;System&gt; &amp; Scope' ), 'Package name exactly once' );
			selection_check( str_contains( $html, 'Scope &lt;details&gt;' ) && ! str_contains( $html, '<System>' ), 'Annexure escapes package text' );
		}
		$html = $method->invoke( Estimate_Generator::instance(), array( 'Slot' => 'package', 'Name' => 'Whole Package', 'Brand' => 'Brand', 'Model' => 'Model', 'Short_Description' => 'Whole Package' ) );
		selection_same( 1, substr_count( $html, 'Whole Package' ), 'Description identical to package name is not repeated' );
	} );
	selection_case( 'Preview accepts package plus accessory and leaves authored template unchanged', function (): void {
		reset_selection(); $template = $GLOBALS['selection_template']->post_content;
		$response = Preview_Estimate::instance()->handle( selection_request( selection_payload( array( 'package' => array( 'item_id' => PACKAGE_ID ), 'permit' => array( 'item_id' => PERMIT_ID ) ) ) ) );
		selection_same( 200, $response->get_status(), 'Preview succeeds' );
		selection_same( array( PACKAGE_ID, PERMIT_ID ), array_column( $response->get_data()['data']['books_preview']['line_items'], 'item_id' ), 'Preview one priced package plus accessory' );
		selection_same( $template, $GLOBALS['selection_template']->post_content, 'No stored template mutation' );
		selection_same( array(), Zoho_API::instance()->writes, 'Preview never writes providers' );
	} );
	foreach ( array( 'furnace' => FURNACE_ID, 'condenser' => AC_ID, 'coil' => COIL_ID ) as $slot => $id ) {
		selection_case( 'Preview and Generate reject package plus ' . $slot . ' before any writes', function () use ( $slot, $id ): void {
			foreach ( array( Preview_Estimate::instance(), Generate_Estimate::instance() ) as $endpoint ) {
				reset_selection(); $payload = selection_payload( array( 'package' => array( 'item_id' => PACKAGE_ID ), $slot => array( 'item_id' => $id ) ) );
				selection_error_response( $endpoint->handle( selection_request( $payload ) ), 'tc_estimate_bad_equipment_selection' );
				selection_check( in_array( '/items/' . PACKAGE_ID, Zoho_API::instance()->reads, true ) && in_array( '/items/' . $id, Zoho_API::instance()->reads, true ), 'Fresh identities loaded before category validation' );
			}
		} );
	}
	foreach ( array( 'furnace', 'other', 'invented_alias' ) as $slot ) {
		selection_case( 'Fresh composite cannot hide under ' . $slot, function () use ( $slot ): void {
			foreach ( array( Preview_Estimate::instance(), Generate_Estimate::instance() ) as $endpoint ) {
				reset_selection(); $payload = selection_payload( array( $slot => array( 'item_id' => PACKAGE_ID, 'equipment_type' => $slot, 'is_composite' => false ) ) );
				selection_error_response( $endpoint->handle( selection_request( $payload ) ), 'tc_estimate_bad_equipment_selection' );
			}
		} );
	}
	selection_case( 'Fresh tag removal blocks stale browser selection in both endpoints', function (): void {
		foreach ( array( Preview_Estimate::instance(), Generate_Estimate::instance() ) as $endpoint ) {
			reset_selection(); Zoho_API::instance()->items[ PACKAGE_ID ]['tags'] = array();
			selection_error_response( $endpoint->handle( selection_request( selection_payload() ) ), 'tc_estimate_item_missing' );
		}
	} );
	selection_case( 'Preview rejects malformed systems with a controlled error', function (): void {
		reset_selection(); $payload = selection_payload(); $payload['systems'] = 'malformed';
		selection_error_response( Preview_Estimate::instance()->handle( selection_request( $payload ) ), 'tc_estimate_bad_equipment_selection' );
	} );
	selection_case( 'Valid package generate sends one draft package line only through the fake providers', function (): void {
		reset_selection(); $response = Generate_Estimate::instance()->handle( selection_request( selection_payload() ) );
		selection_same( 201, $response->get_status(), 'Synthetic draft created' );
		$writes = Zoho_API::instance()->writes;
		selection_same( 3, count( $writes ), 'One Books POST plus CRM POST/PUT' );
		selection_same( '/estimates?organization_id=60000000001&send=false', $writes[0]['path'], 'Creation explicitly stays draft' );
		selection_same( 1, count( $writes[0]['body']['line_items'] ), 'No expanded/doubled package lines' );
		selection_same( PACKAGE_ID, $writes[0]['body']['line_items'][0]['item_id'], 'Actual Books package identity' );
		selection_same( 9400.0, $writes[0]['body']['line_items'][0]['rate'], 'One package price' );
		selection_same( false, $response->get_data()['data']['books_email_sent'], 'No customer email' );
		selection_same( 'success', Audit_Log::instance()->updates[0]['status'], 'Audit succeeds only after synthetic creation' );
	} );
	selection_case( 'Successful idempotency replay stays ahead of catalog validation and writes', function (): void {
		reset_selection(); Audit_Log::instance()->existing = array( 'status' => 'success', 'zoho_estimate_id' => '40000000001', 'zoho_deal_id' => '50000000001',
			'payload' => json_encode( selection_payload() ), 'template_id' => 1, 'template_version' => 2, 'idempotency_key' => 'synthetic-selection-key' );
		$payload = selection_payload( array( 'package' => array( 'item_id' => PACKAGE_ID ), 'furnace' => array( 'item_id' => FURNACE_ID ) ) );
		Zoho_API::instance()->items = array();
		$response = Generate_Estimate::instance()->handle( selection_request( $payload ) );
		selection_same( 200, $response->get_status(), 'Completed request replays' );
		selection_same( true, $response->get_data()['data']['replayed'], 'Replay identified' );
		selection_same( array(), Zoho_API::instance()->reads, 'No catalog/provider reads on replay' );
		selection_same( array(), Zoho_API::instance()->writes, 'No duplicate provider writes' );
		selection_same( array(), Audit_Log::instance()->records, 'No duplicate audit record' );
		selection_same( 0, Customer_Search::instance()->reads, 'No customer hydration on replay' );
	} );

	echo "Selection contract: {$passed} passed, {$failed} failed, {$assertions} assertions.\n";
	exit( $failed > 0 ? 1 : 0 );
}
