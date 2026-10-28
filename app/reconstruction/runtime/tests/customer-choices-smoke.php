<?php
/** Synthetic customer-choice service/REST tests. No real HTTP, email or provider adapters are loaded. */
declare( strict_types=1 );

namespace {
	define( 'ABSPATH', '/' );
	define( 'TC_ESTIMATE_CAP', 'manage_tc_estimates' );
	define( 'TC_ESTIMATE_REST_NS', 'tc-estimate/v1' );
	define( 'TC_ESTIMATE_VERSION', '0.4.14-test' );
	define( 'TC_ESTIMATE_CACHE_TTL_CATALOG', 900 );
	define( 'MINUTE_IN_SECONDS', 60 );
	require dirname( __DIR__, 4 ) . '/tests/standalone-shims.php';
	$GLOBALS['cc_options'] = array();
	$GLOBALS['cc_transients'] = array();
	$GLOBALS['cc_user'] = 1;
	$GLOBALS['cc_owner_allowed'] = true;
	$GLOBALS['cc_cron'] = false;
	$GLOBALS['cc_time'] = time();
	$GLOBALS['cc_routes'] = array();
	$GLOBALS['cc_persist_fail'] = '';
	$GLOBALS['cc_provider_pending'] = null;
	$GLOBALS['cc_revoke_inflight'] = null;
	$GLOBALS['cc_customer_override'] = null;
	$GLOBALS['cc_single_attempts'] = array();
	$GLOBALS['cc_customer_reads'] = 0;
	function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['cc_options'][ $key ] ?? $default; }
	function add_option( string $key, mixed $value, mixed $deprecated = '', mixed $autoload = false ): bool {
		if ( array_key_exists( $key, $GLOBALS['cc_options'] ) ) { return false; }
		$GLOBALS['cc_options'][ $key ] = $value; return true;
	}
	function update_option( string $key, mixed $value, mixed $autoload = null ): bool {
		if ( is_array( $value ) && isset( $value['signature_hash'] ) && ( 'all' === $GLOBALS['cc_persist_fail'] || ( 'completed' === $GLOBALS['cc_persist_fail'] && 'saved' === ( $value['state'] ?? '' ) && null === ( $value['pending'] ?? null ) ) ) ) { return false; }
		if ( isset( $GLOBALS['cc_options'][ $key ] ) && $GLOBALS['cc_options'][ $key ] === $value ) { return false; }
		$GLOBALS['cc_options'][ $key ] = $value; return true;
	}
	function delete_option( string $key ): bool { $exists = array_key_exists( $key, $GLOBALS['cc_options'] ); unset( $GLOBALS['cc_options'][ $key ] ); return $exists; }
	function get_transient( string $key ): mixed { return $GLOBALS['cc_transients'][ $key ] ?? false; }
	function set_transient( string $key, mixed $value, int $ttl = 0 ): bool { $GLOBALS['cc_transients'][ $key ] = $value; return true; }
	function delete_transient( string $key ): bool { unset( $GLOBALS['cc_transients'][ $key ] ); return true; }
	function get_post( int $id ): object|false { return 1 === $id ? (object) array( 'ID' => 1, 'post_type' => 'estimate_template', 'post_status' => 'publish', 'post_content' => '<h3>Equipment to Be Installed</h3>{{#systems}}<ul>{{#items}}<li>{{display_name}}</li>{{/items}}</ul>{{/systems}}<p>{{pricing.total_formatted}}</p>' ) : false; }
	function get_current_user_id(): int { return $GLOBALS['cc_user']; }
	function user_can( int|object $user, string $cap ): bool { $id = is_object( $user ) ? (int) $user->ID : $user; return 1 === $id && $GLOBALS['cc_owner_allowed']; }
	function current_user_can( string $cap ): bool { return user_can( get_current_user_id(), $cap ); }
	function get_userdata( int $id ): object|false { return 1 === $id ? (object) array( 'ID' => 1, 'roles' => array( 'administrator' ) ) : false; }
	function wp_doing_cron(): bool { return $GLOBALS['cc_cron']; }
	function wp_salt( string $scheme = 'auth' ): string { return 'synthetic-customer-choice-test-salt-not-a-live-secret-' . $scheme; }
	function home_url( string $path = '' ): string { return ( $GLOBALS['cc_home_url'] ?? 'https://fixture.example.test' ) . $path; }
	function site_url( string $path = '' ): string { return home_url( $path ); }
	function rest_url( string $path = '' ): string { $GLOBALS['cc_rest_url_reads']++; return $GLOBALS['cc_rest_url'] ?? home_url( '/wp-json/' . $path ); }
	function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
	function maybe_serialize( mixed $value ): mixed { return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value; }
	function wp_cache_delete( string $key, string $group = '' ): bool { return true; }
	function is_email( string $value ): string|false { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
	function sanitize_text_field( string $text ): string { return trim( strip_tags( $text ) ); }
	function sanitize_textarea_field( string $text ): string { return trim( strip_tags( $text ) ); }
	function esc_url_raw( string $url ): string { return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : ''; }
	function esc_url( string $url ): string { return esc_html( $url ); }
	function esc_attr( string $value ): string { return esc_html( $value ); }
	function wp_unslash( mixed $value ): mixed { return $value; }
	function absint( mixed $value ): int { return abs( (int) $value ); }
	function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-000000000001'; }
	function __return_true(): bool { return true; }
	function register_rest_route( string $namespace, string $route, array $options ): void { $GLOBALS['cc_routes'][ $route ] = $options; }
	function wp_mail( mixed ...$args ): never { throw new \LogicException( 'No email is permitted in customer-choice tests.' ); }
	function wp_remote_request( mixed ...$args ): never { throw new \LogicException( 'No HTTP is permitted in customer-choice tests.' ); }
	function do_action( mixed ...$args ): void {}
	class WP_REST_Request {
		public function __construct( private mixed $data = array(), private array $headers = array(), private string $method = 'POST', private ?string $raw = null ) {}
		public function get_json_params(): mixed { return $this->data; }
		public function get_header( string $key ): string { return $this->headers[ strtolower( str_replace( '_', '-', $key ) ) ] ?? ''; }
		public function get_body(): string { return $this->raw ?? (string) json_encode( $this->data ); }
		public function get_method(): string { return $this->method; }
		public function get_param( string $key ): mixed { return is_array( $this->data ) ? ( $this->data[ $key ] ?? null ) : null; }
	}
	class WP_REST_Response {
		private array $headers = array();
		public function __construct( private mixed $data, private int $status = 200 ) {}
		public function get_data(): mixed { return $this->data; }
		public function get_status(): int { return $this->status; }
		public function header( string $key, string $value ): void { $this->headers[ $key ] = $value; }
		public function get_headers(): array { return $this->headers; }
	}
}

namespace TempControl\Estimate {
	function time(): int { return $GLOBALS['cc_time'] ?? \time(); }
	final class Security {
		public static function instance(): self { static $self; return $self ??= new self(); }
		public function sanitize_zoho_id( string $id ): string { return preg_match( '/^[0-9]{10,20}$/D', $id ) ? $id : ''; }
		public function gate_request( string $cap = TC_ESTIMATE_CAP ): bool|\WP_Error { return \current_user_can( $cap ) ? true : new \WP_Error( 'tc_estimate_forbidden', 'Synthetic denied.', array( 'status' => 403 ) ); }
	}
	final class Audit_Log {
		public ?array $generate = null;
		public array $records = array();
		public static function instance(): self { static $self; return $self ??= new self(); }
		public function find_by_estimate_action( string $id, string $action, int $owner ): ?array { return '400000000000000001' === $id && 'generate' === $action && 1 === $owner ? $this->generate : null; }
		public function record( array $row ): int { $this->records[] = $row; return count( $this->records ); }
		public function update( int $id, array $fields ): void { $this->records[ $id - 1 ] = array_merge( $this->records[ $id - 1 ] ?? array(), $fields ); }
	}
	final class Template_CPT { public const POST_TYPE = 'estimate_template'; }
	final class Template_Meta {
		public static function instance(): self { static $self; return $self ??= new self(); }
		public function hydrate( int $id ): array { return array( 'id' => 1, 'version' => 1, 'name' => 'Synthetic Customer Options', 'template_type' => 'full_replacement' ); }
	}
	final class Customer_Search {
		public static function instance(): self { static $self; return $self ??= new self(); }
		public function get_account( string $id ): array|\WP_Error {
			$GLOBALS['cc_customer_reads']++;
			return '200000000000000001' === $id ? ( $GLOBALS['cc_customer_override'] ?? array( 'id' => $id, 'books_contact_id' => '300000000000000001', 'name' => 'Fixture Customer', 'email' => 'fixture@example.test' ) ) : new \WP_Error( 'fixture_customer_missing', 'Synthetic customer unavailable.' );
		}
	}
	final class Zoho_Cache {
		public static function instance(): self { static $self; return $self ??= new self(); }
		public function get( string $key ): mixed { return null; }
		public function set( mixed ...$args ): void {}
	}
	final class Zoho_API {
		public const SERVICE_BOOKS = 'books';
		public const SERVICE_CRM = 'crm';
		public array $items = array();
		public array $estimate = array();
		public array $deal = array();
		public array $calls = array();
		public string $books_mode = 'success';
		public bool $crm_fail = false;
		public bool $books_get_fail = false;
		public bool $crm_get_fail = false;
		public bool $crm_missing = false;
		public string $crm_mode = 'success';
		public bool $revoke_during_books_put = false;
		public bool $replace_lock_during_books_put = false;
		public array $contacts = array();
		public bool $contacts_more = false;
		public array $crm_drop_fields_after_write = array();
		public static function instance(): self { static $self; return $self ??= new self(); }
		public function get( string $service, string $path, array $query = array() ): array|\WP_Error {
			$this->calls[] = array( 'method' => 'GET', 'service' => $service, 'path' => $path, 'query' => $query );
			if ( 'books' === $service && '/reportingtags' === $path ) { return array( 'tags' => array( array( 'tag_id' => '70000000001', 'tag_name' => 'Package', 'is_active' => true ) ) ); }
			if ( 'books' === $service && '/reportingtags/70000000001/options/all' === $path ) { return array( 'results' => array( array( 'option_id' => '80000000001', 'option_name' => 'Package' ) ) ); }
			if ( 'books' === $service && '/contacts' === $path ) { return array( 'contacts' => $this->contacts, 'page_context' => array( 'has_more_page' => $this->contacts_more ) ); }
			if ( 'books' === $service && '/items' === $path ) {
				$rows = array_values( array_filter( $this->items, static fn ( array $i ): bool => $i['name'] === ( $query['name_contains'] ?? '' ) ) );
				return array( 'items' => $rows, 'page_context' => array( 'has_more_page' => false ) );
			}
			if ( 'books' === $service && str_starts_with( $path, '/items/' ) ) { $id = substr( $path, 7 ); return isset( $this->items[ $id ] ) ? array( 'item' => $this->items[ $id ] ) : new \WP_Error( 'fixture_item_missing', 'Synthetic item unavailable.' ); }
			if ( 'books' === $service && '/estimates/400000000000000001' === $path ) { return $this->books_get_fail ? new \WP_Error( 'fixture_get_timeout', 'Synthetic read timeout.' ) : array( 'code' => 0, 'estimate' => $this->estimate ); }
			if ( 'crm' === $service && '/Deals/500000000000000001' === $path ) { return $this->crm_get_fail ? new \WP_Error( 'fixture_crm_read_timeout', 'Synthetic CRM read timeout.' ) : array( 'data' => $this->crm_missing ? array() : array( $this->deal ) ); }
			throw new \LogicException( 'Unexpected synthetic GET: ' . $service . ' ' . $path );
		}
		public function put_once( string $service, string $path, array $body ): array|\WP_Error { $GLOBALS['cc_single_attempts'][] = array( 'service' => $service, 'path' => $path ); return $this->put( $service, $path, $body ); }
		public function put( string $service, string $path, array $body ): array|\WP_Error {
			$this->calls[] = array( 'method' => 'PUT', 'service' => $service, 'path' => $path, 'body' => $body );
			if ( 'books' === $service && str_starts_with( $path, '/estimates/400000000000000001?' ) ) {
				foreach ( $GLOBALS['cc_options'] as $record ) { if ( is_array( $record ) && isset( $record['signature_hash'] ) ) { $GLOBALS['cc_provider_pending'] = $record['pending']; } }
				if ( $this->replace_lock_during_books_put ) { foreach ( $GLOBALS['cc_options'] as $key => $value ) { if ( str_starts_with( $key, 'tc_estimate_choice_lock_' ) ) { $GLOBALS['cc_options'][ $key ] = array( 'token' => 'fixture-fenced-worker', 'created_at' => time() ); } } }
				if ( $this->revoke_during_books_put ) { $this->revoke_during_books_put = false; $GLOBALS['cc_revoke_inflight'] = Customer_Choices::instance()->revoke_for_estimate( '400000000000000001', 1 ); }
				if ( 'timeout-before' === $this->books_mode ) { return new \WP_Error( 'fixture_timeout', 'Synthetic provider timeout.' ); }
				$this->estimate = array_merge( $this->estimate, $body );
				$this->estimate['subtotal'] = array_sum( array_map( static fn ( array $line ): float => (float) $line['rate'] * (float) ( $line['quantity'] ?? 1 ), $body['line_items'] ) );
				$this->estimate['total'] = round( $this->estimate['subtotal'] + (float) ( $body['adjustment'] ?? 0 ), 2 );
				foreach ( $this->estimate['line_items'] as $index => &$line ) { $line['line_item_id'] = (string) ( 810000000000000000 + $index ); } unset( $line );
				return 'timeout-after' === $this->books_mode ? new \WP_Error( 'fixture_timeout', 'Synthetic provider timeout.' ) : array( 'code' => 0, 'estimate' => $this->estimate );
			}
			if ( 'crm' === $service && '/Deals/500000000000000001' === $path ) {
				if ( $this->crm_fail ) { return array( 'data' => array( array( 'code' => 'INVALID_DATA', 'status' => 'error', 'message' => 'Synthetic rejected update.' ) ) ); }
				if ( 'timeout-before' === $this->crm_mode ) { return new \WP_Error( 'fixture_crm_timeout', 'Synthetic CRM write timeout.' ); }
				$record = $body['data'][0] ?? array();
				foreach ( $record as $field => $value ) {
					if ( 'id' === $field ) { continue; }
					if ( 'Quoted_Equipment' !== $field ) { $this->deal[ $field ] = $value; continue; }
					// CRM V3+ updates subform rows with IDs and appends rows that omit IDs.
					$rows = $this->deal['Quoted_Equipment'] ?? array();
					foreach ( $value as $row ) {
						if ( array_key_exists( '_delete', $row ) && isset( $row['id'] ) ) { $rows = array_values( array_filter( $rows, static fn ( array $existing ): bool => ( $existing['id'] ?? '' ) !== $row['id'] ) ); continue; }
						$found = false;
						foreach ( $rows as &$existing ) { if ( isset( $row['id'] ) && $row['id'] === ( $existing['id'] ?? null ) ) { $existing = array_merge( $existing, $row ); $found = true; break; } } unset( $existing );
						if ( ! $found ) { $row['id'] = $row['id'] ?? (string) ( 820000000000000001 + count( $rows ) ); $rows[] = $row; }
					}
					$this->deal['Quoted_Equipment'] = $rows;
				}
				foreach ( $this->deal['Quoted_Equipment'] as &$after_row ) { foreach ( $this->crm_drop_fields_after_write as $field ) { unset( $after_row[ $field ] ); } } unset( $after_row );
				return 'timeout-after' === $this->crm_mode ? new \WP_Error( 'fixture_crm_timeout', 'Synthetic CRM write timeout.' ) : array( 'data' => array( array( 'code' => 'SUCCESS', 'status' => 'success' ) ) );
			}
			throw new \LogicException( 'Unexpected synthetic PUT target.' );
		}
		public function post( mixed ...$args ): never { throw new \LogicException( 'No create, email or status action is permitted.' ); }
		public function delete( mixed ...$args ): never { throw new \LogicException( 'No provider deletion is permitted.' ); }
	}
}

namespace {
	use TempControl\Estimate\Audit_Log;
	use TempControl\Estimate\Customer_Choices;
	use TempControl\Estimate\Equipment_Catalog;
	use TempControl\Estimate\Zoho_API;

	$cc_passed = $cc_failed = $cc_assertions = 0;
	function cc_check( bool $value, string $message ): void { global $cc_assertions; $cc_assertions++; if ( ! $value ) { throw new \RuntimeException( $message ); } }
	function cc_same( mixed $expected, mixed $actual, string $message ): void { cc_check( $expected === $actual, $message . ' [' . json_encode( $actual ) . ']' ); }
	function cc_case( string $name, callable $test ): void { global $cc_passed, $cc_failed; try { $test(); $cc_passed++; echo 'PASS ' . $name . "\n"; } catch ( \Throwable $error ) { $cc_failed++; echo 'FAIL ' . $name . ': ' . $error->getMessage() . "\n"; } }
	function cc_error( mixed $value, ?int $status = null ): void { cc_check( is_wp_error( $value ), 'Expected a controlled error.' ); if ( null !== $status ) { cc_same( $status, $value->get_error_data()['status'] ?? null, 'Controlled HTTP status.' ); } }
	function cc_writes( string $service = 'books' ): array { return array_values( array_filter( Zoho_API::instance()->calls, static fn ( array $call ): bool => 'PUT' === $call['method'] && $service === $call['service'] ) ); }

	function cc_fixture(): array {
		$choices = $selected = array(); $selected_rates = 0.0;
		$prices = array( array( 8000.0, 10000.0, 12000.0 ), array( 9000.0, 11000.0, 13000.0 ) );
		$api = Zoho_API::instance(); $api->items = array();
		foreach ( array( 'Lower System', 'Upper System' ) as $system_index => $label ) {
			$options = array();
			foreach ( array( 'Silver', 'Gold', 'Platinum' ) as $choice_index => $tier ) {
				$offset = $system_index * 3 + $choice_index;
				$id = (string) ( 900000000000000001 + $offset );
				$rate = 7001.0 + $offset;
				$options[] = array( 'choice_id' => 'fixture-' . $system_index . '-' . strtolower( $tier ), 'label' => $tier,
					'equipment' => array( 'package' => array( 'item_id' => $id ) ), 'sales_price' => $prices[ $system_index ][ $choice_index ] );
				$api->items[ $id ] = array( 'item_id' => $id, 'name' => 'Fixture ' . $label . ' ' . $tier . ' Package 80% AFUE 100000 BTU 1.5 Ton',
					'rate' => $rate, 'purchase_rate' => 9876.54 + $offset, 'status' => 'active', 'is_combo_product' => true,
					'brand' => 'Fixture', 'sku' => 'FIX-' . $offset,
					'tags' => array( array( 'tag_id' => '70000000001', 'tag_option_id' => '80000000001' ) ),
					'cf_category_2' => 'Standard-Efficiency ' . strtoupper( $tier ), 'description' => 'Synthetic scope only.' );
			}
			$choices[] = array( 'system_number' => $system_index + 1, 'system_label' => $label, 'selected_choice_id' => $options[0]['choice_id'], 'choices' => $options );
			$selected[] = array( 'system_number' => $system_index + 1, 'system_label' => $label, 'equipment' => $options[0]['equipment'] );
			$selected_rates += $api->items[ $options[0]['equipment']['package']['item_id'] ]['rate'];
		}
		$addons = array();
		foreach ( array( 'maintenance' => 'New Residential System Maintenance Agreement', 'permits' => 'Permits & Inspections' ) as $key => $name ) {
			$id = 'maintenance' === $key ? '900000000000000007' : '900000000000000008';
			$api->items[ $id ] = array( 'item_id' => $id, 'name' => $name, 'rate' => 0, 'status' => 'active', 'is_combo_product' => false );
			$addons[ $key ] = array( 'available' => true, 'addon_key' => $key, 'item_id' => $id, 'name' => $name, 'rate' => 0.0, 'quantity' => 1, 'source' => 'zoho_books' );
			if ( 'maintenance' === $key ) { $addons[ $key ]['renewal_rate'] = 349.0; $addons[ $key ]['renewal_units'] = 1; }
		}
		$body = array( 'template_id' => 1, 'customer' => array( 'zoho_account_id' => '200000000000000001' ), 'systems' => $selected,
			'_proposal_systems' => $choices, '_proposal_addons' => $addons,
			'options' => array( 'include_maintenance' => true, 'include_permits' => true ),
			'pricing' => array( 'subtotal' => $selected_rates, 'total' => 17500.0, 'project_adjustment' => 500.0, 'deposit_percent' => 35 ) );
		$lines = array();
		foreach ( $selected as $system ) {
			$item = $api->items[ $system['equipment']['package']['item_id'] ];
			$lines[] = array( 'line_item_id' => (string) ( 810000000000000001 + count( $lines ) ), 'item_id' => $item['item_id'], 'name' => $item['name'], 'description' => 'Synthetic scope only.', 'rate' => $item['rate'], 'quantity' => 1, 'tax_id' => '', 'discount' => 0 );
		}
		foreach ( $addons as $addon ) {
			$lines[] = array( 'line_item_id' => (string) ( 810000000000000001 + count( $lines ) ), 'item_id' => $addon['item_id'], 'name' => $addon['name'], 'description' => 'Synthetic optional policy.', 'rate' => 0.0, 'quantity' => 1, 'tax_id' => '', 'discount' => 0 );
		}
		$api->estimate = array( 'estimate_id' => '400000000000000001', 'customer_id' => '300000000000000001', 'status' => 'draft',
			'estimate_number' => 'FIX-001', 'line_items' => $lines, 'subtotal' => $selected_rates, 'adjustment' => 17500.0 - $selected_rates, 'total' => 17500.0,
			'notes' => 'Synthetic original notes.', 'terms' => 'Synthetic original terms.', 'adjustment_description' => 'Project price adjustment',
			'tax_total' => 0, 'discount' => 0, 'discount_amount' => 0, 'shipping_charge' => 0, 'currency_code' => 'USD', 'currency_id' => '100000000000000001',
			'expiry_date' => gmdate( 'Y-m-d', time() + 20 * DAY_IN_SECONDS ), 'estimate_url' => 'https://books.zoho.com/portal/fixture-estimate-review' );
		$api->deal = array( 'id' => '500000000000000001', 'Amount' => 17500.0, 'Stage' => 'Proposal Sent',
			'Account_Name' => array( 'id' => '200000000000000001', 'name' => 'Fixture Customer' ), 'Books_Estimate_ID' => '400000000000000001',
			'Quoted_Equipment' => array(
				array( 'id' => '820000000000000001', 'Slot' => 'package', 'System_Number' => 1, 'System_Label' => 'Lower System', 'Zoho_Item_ID' => '900000000000000001', 'CRM_Equipment_ID' => '900000000000000001', 'Rate' => 7001.0, 'Name' => $api->items['900000000000000001']['name'], 'Brand' => 'Fixture', 'Model' => 'FIX-0', 'Short_Description' => 'Synthetic scope only.', 'SEER' => '', 'AFUE' => '80', 'Tons' => '1.5', 'BTU_Input' => '100000', 'BTU_Output' => '', 'Refrigerant' => '', 'Fixture_Independent_Field' => 'preserve lower metadata' ),
				array( 'id' => '820000000000000002', 'Slot' => 'package', 'System_Number' => 2, 'System_Label' => 'Upper System', 'Zoho_Item_ID' => '900000000000000004', 'CRM_Equipment_ID' => '900000000000000004', 'Rate' => 7004.0, 'Name' => $api->items['900000000000000004']['name'], 'Brand' => 'Fixture', 'Model' => 'FIX-3', 'Short_Description' => 'Synthetic scope only.', 'SEER' => '', 'AFUE' => '80', 'Tons' => '1.5', 'BTU_Input' => '100000', 'BTU_Output' => '', 'Refrigerant' => '', 'Fixture_Independent_Field' => 'preserve upper metadata' ),
				array( 'id' => '820000000000000003', 'Slot' => 'accessory', 'System_Number' => 99, 'System_Label' => 'Independent synthetic line', 'Zoho_Item_ID' => '900000000000000009', 'CRM_Equipment_ID' => '900000000000000009', 'Rate' => 42.0, 'Name' => 'Synthetic unrelated row', 'Fixture_Independent_Field' => 'preserve independent row' ),
			) );
		Audit_Log::instance()->generate = array( 'id' => 1, 'user_id' => 1, 'action' => 'generate', 'status' => 'success', 'zoho_estimate_id' => '400000000000000001',
			'zoho_deal_id' => '500000000000000001', 'zoho_account_id' => '200000000000000001', 'template_id' => 1, 'template_version' => 1, 'payload' => json_encode( $body ) );
		return $body;
	}
	function cc_reset(): array {
		$GLOBALS['cc_home_url'] = 'https://fixture.example.test'; $GLOBALS['cc_rest_url'] = null; $GLOBALS['cc_rest_url_reads'] = 0;
		$GLOBALS['cc_options'] = array( 'tc_estimate_zoho_org_id' => '600000000000000001', 'tc_estimate_zoho_dc' => 'com', 'home' => 'https://fixture.example.test', 'siteurl' => 'https://fixture.example.test' );
		$GLOBALS['cc_time'] = time(); $GLOBALS['cc_transients'] = array(); $GLOBALS['cc_user'] = 1; $GLOBALS['cc_owner_allowed'] = true; $GLOBALS['cc_cron'] = false;
		$_SERVER['REMOTE_ADDR'] = '192.0.2.1'; $_SERVER['HTTP_ORIGIN'] = 'https://fixture.example.test';
		$api = Zoho_API::instance(); $api->calls = array(); $api->books_mode = 'success'; $api->crm_fail = false; $api->books_get_fail = false; $api->crm_get_fail = false; $api->crm_missing = false; $api->crm_mode = 'success'; $api->revoke_during_books_put = false; $GLOBALS['cc_revoke_inflight'] = null; $api->replace_lock_during_books_put = false; $GLOBALS['cc_customer_override'] = null; $GLOBALS['cc_single_attempts'] = array(); $GLOBALS['cc_customer_reads'] = 0; $api->contacts = array( array( 'contact_id' => '300000000000000001', 'email' => 'fixture@example.test', 'status' => 'active' ) ); $api->contacts_more = false; $api->crm_drop_fields_after_write = array();
		$GLOBALS['cc_persist_fail'] = ''; $GLOBALS['cc_provider_pending'] = null;
		Audit_Log::instance()->records = array();
		foreach ( array( Customer_Choices::class, Equipment_Catalog::class ) as $class ) {
			if ( class_exists( $class, false ) ) { $property = new ReflectionProperty( $class, 'instance' ); $property->setAccessible( true ); $property->setValue( null, null ); }
		}
		return cc_fixture();
	}
	function cc_token( array $link ): string { $fragment = parse_url( $link['url'], PHP_URL_FRAGMENT ); parse_str( (string) $fragment, $parts ); return (string) ( $parts['token'] ?? '' ); }
	function cc_link(): array { $link = Customer_Choices::instance()->link_for_estimate( '400000000000000001', 1 ); cc_check( is_array( $link ) && true === ( $link['available'] ?? false ), 'An owned alternatives estimate gets a scoped link.' . ( is_wp_error( $link ) ? ' ' . $link->get_error_code() . ' ' . $link->get_error_message() : '' ) ); return $link; }
	function cc_selections( string $lower = 'platinum', string $upper = 'platinum' ): array { return array( array( 'system_number' => 1, 'choice_id' => 'fixture-0-' . $lower ), array( 'system_number' => 2, 'choice_id' => 'fixture-1-' . $upper ) ); }

	function cc_record_key(): string {
		foreach ( $GLOBALS['cc_options'] as $key => $value ) {
			if ( is_array( $value ) && isset( $value['estimate_id'], $value['signature_hash'] ) ) { return $key; }
		}
		throw new RuntimeException( 'Synthetic private offer record missing.' );
	}
	function cc_assert_public( array $value ): void {
		$json = (string) json_encode( $value );
		foreach ( array( 'purchase_cost', 'purchase_rate', 'markup', 'source_body', 'signature_hash', 'org_id', 'owner_id', 'baseline', '9876.54' ) as $private ) {
			cc_check( ! str_contains( $json, $private ), 'Public response excludes private field/value ' . $private );
		}
	}
	function cc_apply( string $token, int $revision = 1, string $request = 'fixture-request-0001', ?array $selections = null ): array|WP_Error {
		return Customer_Choices::instance()->apply( $token, $revision, $request, $selections ?? cc_selections() );
	}

	$runtime = dirname( __DIR__ );
	require dirname( __DIR__, 4 ) . '/includes/class-mustache-shim.php';
	require $runtime . '/includes/class-equipment-catalog.php';
	require $runtime . '/includes/class-proposal-choices.php';
	require $runtime . '/includes/class-token-renderer.php';
	require $runtime . '/includes/class-estimate-generator.php';
	require $runtime . '/includes/class-customer-choices.php';
	require dirname( __DIR__, 4 ) . '/endpoints/class-endpoint-base.php';
	require $runtime . '/endpoints/class-customer-choices.php';

	cc_case( 'Owned alternatives estimate gets a private scoped expiring fragment link', function (): void {
		cc_reset(); $link = cc_link(); $token = cc_token( $link );
		cc_check( preg_match( '/^[a-f0-9]{32}\.[a-f0-9]{64}$/D', $token ) === 1, 'Scoped high-entropy token shape.' );
		cc_same( 'https', parse_url( $link['url'], PHP_URL_SCHEME ), 'Canonical selection link uses HTTPS.' );
		cc_same( 'fixture.example.test', parse_url( $link['url'], PHP_URL_HOST ), 'Configured site origin is used.' );
		cc_same( '/wp-json/tc-estimate/v1/customer-choices/page', parse_url( $link['url'], PHP_URL_PATH ), 'Selection link is canonical under the configured REST namespace, away from the root homepage.' );
		$query = array(); parse_str( (string) parse_url( $link['url'], PHP_URL_QUERY ), $query );
		cc_same( array( 'tc_estimate_choices' => '1', 'ver' => TC_ESTIMATE_VERSION ), $query, 'Cache compatibility query contains only the route marker and current plugin version.' );
		cc_same( 'token=' . $token, (string) parse_url( $link['url'], PHP_URL_FRAGMENT ), 'Scoped credential remains entirely in the fragment.' );
		cc_check( ! str_contains( (string) parse_url( $link['url'], PHP_URL_QUERY ), $token ), 'Bearer token is not in query/loggable URL.' );
		foreach ( array( 'estimate_id', 'customer_id', 'owner_id', 'signature', 'token' ) as $private_query_field ) { cc_check( ! array_key_exists( $private_query_field, $query ), 'Versioned query excludes private authority field ' . $private_query_field ); }
		$record = $GLOBALS['cc_options'][ cc_record_key() ];
		cc_check( $record['expires_at'] > time() && $record['expires_at'] <= time() + 30 * DAY_IN_SECONDS, 'Link expiry is bounded.' );
		cc_check( ! str_contains( (string) json_encode( $record ), $token ), 'Raw bearer token is not persisted.' );
		cc_same( 0, count( cc_writes() ), 'Link preparation does not update Books.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'Canonical URL preparation adds no CRM mutation.' );
	});
	cc_case( 'Retrieving the same staff link preserves version query token expiry and revision without writes', function (): void {
		cc_reset(); $first = cc_link(); $record = $GLOBALS['cc_options'][ cc_record_key() ]; $count = count( $GLOBALS['cc_options'] );
		$second = cc_link(); cc_same( $first, $second, 'Unchanged owned quote returns the same complete versioned link.' );
		cc_same( cc_token( $first ), cc_token( $second ), 'Cache version does not rotate the scoped credential.' ); cc_same( $record, $GLOBALS['cc_options'][ cc_record_key() ], 'Link retrieval preserves quoted prices pending state signature and TTL.' ); cc_same( $count, count( $GLOBALS['cc_options'] ), 'Link retrieval creates no second record.' );
		$query = array(); parse_str( (string) parse_url( $second['url'], PHP_URL_QUERY ), $query ); cc_same( TC_ESTIMATE_VERSION, $query['ver'] ?? null, 'Reused link carries current version marker.' );
		cc_same( 0, count( cc_writes() ), 'No Books update while retrieving link.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'No CRM update while retrieving link.' );
	});

	cc_case( 'Deployment hold blocks new alternative links before customer provider or record access', function (): void {
		cc_reset(); $GLOBALS['cc_options']['tc_estimate_customer_choices_deployment_hold'] = true; $before = $GLOBALS['cc_options'];
		cc_error( Customer_Choices::instance()->link_for_estimate( '400000000000000001', 1 ), 503 ); cc_same( 0, $GLOBALS['cc_customer_reads'], 'No customer resolution under hold.' ); cc_same( array(), Zoho_API::instance()->calls, 'No provider reads or writes under hold.' ); cc_same( $before, $GLOBALS['cc_options'], 'Hold creates no link record or index.' ); cc_same( array(), $GLOBALS['cc_transients'], 'Hold consumes no rate-limit state.' ); cc_same( 0, $GLOBALS['cc_rest_url_reads'], 'Hold resolves no link address.' );
	});
	foreach ( array(
		'external origin' => 'https://external.example.test/wp-json/tc-estimate/v1/customer-choices/page',
		'protocol relative' => '//fixture.example.test/wp-json/tc-estimate/v1/customer-choices/page',
		'HTTP scheme' => 'http://fixture.example.test/wp-json/tc-estimate/v1/customer-choices/page',
		'invalid port' => 'https://fixture.example.test:65536/wp-json/tc-estimate/v1/customer-choices/page',
		'nonmatching port' => 'https://fixture.example.test:444/wp-json/tc-estimate/v1/customer-choices/page',
		'userinfo' => 'https://operator@fixture.example.test/wp-json/tc-estimate/v1/customer-choices/page',
		'password' => 'https://operator:password@fixture.example.test/wp-json/tc-estimate/v1/customer-choices/page',
		'query string' => 'https://fixture.example.test/wp-json/tc-estimate/v1/customer-choices/page?other=1',
		'plain query REST routing' => 'https://fixture.example.test/?rest_route=/tc-estimate/v1/customer-choices/page',
		'empty query delimiter' => 'https://fixture.example.test/wp-json/tc-estimate/v1/customer-choices/page?',
		'empty fragment delimiter' => 'https://fixture.example.test/wp-json/tc-estimate/v1/customer-choices/page#',
		'fragment' => 'https://fixture.example.test/wp-json/tc-estimate/v1/customer-choices/page#other',
		'literal control' => "https://fixture.example.test/wp-json/tc-estimate/v1/customer-choices/page\n",
		'encoded control' => 'https://fixture.example.test/wp-json/tc-estimate/v1/customer-choices/page%0a',
		'backslash ambiguity' => 'https://fixture.example.test\\@external.example.test/wp-json/tc-estimate/v1/customer-choices/page',
		'non-page path' => 'https://fixture.example.test/',
	) as $description => $url ) {
		cc_case( 'Unsafe canonical URL ' . $description . ' fails before customer/provider work or record creation', function () use ( $url ): void {
			cc_reset(); $GLOBALS['cc_rest_url'] = $url; $before = $GLOBALS['cc_options'];
			cc_error( Customer_Choices::instance()->link_for_estimate( '400000000000000001', 1 ), 503 );
			cc_same( $before, $GLOBALS['cc_options'], 'Unsafe URL creates no private record or index.' ); cc_same( array(), $GLOBALS['cc_transients'], 'Unsafe URL writes no transient state.' ); cc_same( 0, $GLOBALS['cc_customer_reads'], 'Unsafe URL resolves no customer.' ); cc_same( array(), Zoho_API::instance()->calls, 'Unsafe URL makes no provider call.' ); cc_same( 1, $GLOBALS['cc_rest_url_reads'], 'Canonical address is resolved once.' );
		} );
	}
	cc_case( 'A valid configured subsite custom REST prefix is used once for new and reused links', function (): void {
		cc_reset(); $GLOBALS['cc_home_url'] = 'https://fixture.example.test/subsite';
		$GLOBALS['cc_rest_url'] = 'https://fixture.example.test:443/subsite/custom-api/tc-estimate/v1/customer-choices/page';
		$link = cc_link(); cc_same( $GLOBALS['cc_rest_url'] . '?tc_estimate_choices=1&ver=' . rawurlencode( TC_ESTIMATE_VERSION ) . '#token=' . cc_token( $link ), $link['url'], 'Configured REST prefix and equivalent default HTTPS port are preserved.' ); cc_same( 1, $GLOBALS['cc_rest_url_reads'], 'One canonical resolution on new link.' );
		cc_same( $link, cc_link(), 'Reused link retains canonical configured address and original bearer.' ); cc_same( 2, $GLOBALS['cc_rest_url_reads'], 'Exactly one canonical resolution per reuse request.' );
	});
	cc_case( 'A changed unsafe canonical URL blocks link reuse before provider work', function (): void {
		cc_reset(); cc_link(); $GLOBALS['cc_rest_url'] = 'https://external.example.test/wp-json/tc-estimate/v1/customer-choices/page'; $before = $GLOBALS['cc_options']; Zoho_API::instance()->calls = array(); $GLOBALS['cc_customer_reads'] = 0;
		cc_error( Customer_Choices::instance()->link_for_estimate( '400000000000000001', 1 ), 503 ); cc_same( $before, $GLOBALS['cc_options'], 'Original issued private record and index stay unchanged.' ); cc_same( 0, $GLOBALS['cc_customer_reads'], 'Reusing unsafe URL resolves no customer.' ); cc_same( array(), Zoho_API::instance()->calls, 'Reusing unsafe URL makes no provider call.' );
	});
	cc_case( 'Deployment hold leaves legacy no-alternatives quotes unavailable without customer or provider work', function (): void {
		$body = cc_reset(); unset( $body['_proposal_systems'] ); Audit_Log::instance()->generate['payload'] = json_encode( $body ); $GLOBALS['cc_options']['tc_estimate_customer_choices_deployment_hold'] = true;
		$result = Customer_Choices::instance()->link_for_estimate( '400000000000000001', 1 ); cc_same( array( 'available' => false ), $result, 'Legacy behavior remains unchanged while held.' ); cc_same( 0, $GLOBALS['cc_customer_reads'], 'No customer lookup for legacy quote.' ); cc_same( array(), Zoho_API::instance()->calls, 'No provider calls for legacy quote.' ); cc_same( 0, $GLOBALS['cc_rest_url_reads'], 'Legacy quote resolves no link address.' );
	});
	cc_case( 'Deployment hold rejects valid token view and apply without mutating local state or providers', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $GLOBALS['cc_options']['tc_estimate_customer_choices_deployment_hold'] = true; $before = $GLOBALS['cc_options']; $rates = $GLOBALS['cc_transients']; Zoho_API::instance()->calls = array(); $GLOBALS['cc_customer_reads'] = 0;
		cc_error( Customer_Choices::instance()->view( $token ), 503 ); cc_error( cc_apply( $token ), 503 ); cc_same( $before, $GLOBALS['cc_options'], 'All quote signature pricing revision pending and expiry fields remain untouched.' ); cc_same( $rates, $GLOBALS['cc_transients'], 'No rate-limit writes while held.' ); cc_same( array(), Zoho_API::instance()->calls, 'No provider calls while held.' ); cc_same( 0, $GLOBALS['cc_customer_reads'], 'No customer lookup while held.' );
	});
	cc_case( 'Invalid token remains401 during deployment hold without revealing hold availability', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $GLOBALS['cc_options']['tc_estimate_customer_choices_deployment_hold'] = true; Zoho_API::instance()->calls = array();
		$last = substr( $token, -1 ); $tampered = substr( $token, 0, -1 ) . ( 'a' === $last ? 'b' : 'a' );
		foreach ( array( '', 'not-a-token', $tampered, str_repeat( '0', 32 ) . '.' . str_repeat( 'a', 64 ) ) as $invalid ) { cc_error( Customer_Choices::instance()->view( $invalid ), 401 ); cc_error( cc_apply( $invalid ), 401 ); }
		cc_same( array(), Zoho_API::instance()->calls, 'Invalid held credentials cannot reach providers.' );
	});
	cc_case( 'Expired token remains401 during deployment hold', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $GLOBALS['cc_options']['tc_estimate_customer_choices_deployment_hold'] = true; $GLOBALS['cc_time'] += 31 * DAY_IN_SECONDS; Zoho_API::instance()->calls = array();
		cc_error( Customer_Choices::instance()->view( $token ), 401 ); cc_error( cc_apply( $token ), 401 ); cc_same( array(), Zoho_API::instance()->calls, 'Expired held credentials cannot reach providers.' );
	});
	cc_case( 'Releasing deployment hold restores the same valid token quote state and normal selected update', function (): void {
		cc_reset(); $link = cc_link(); $token = cc_token( $link ); $key = cc_record_key(); $record = $GLOBALS['cc_options'][ $key ]; $before = Customer_Choices::instance()->view( $token );
		$GLOBALS['cc_options']['tc_estimate_customer_choices_deployment_hold'] = true; cc_error( Customer_Choices::instance()->view( $token ), 503 ); cc_error( cc_apply( $token ), 503 );
		delete_option( 'tc_estimate_customer_choices_deployment_hold' ); $after = Customer_Choices::instance()->view( $token ); cc_same( $before, $after, 'Same unconsumed link restores its original public choices revision and expiry.' ); cc_same( $record, $GLOBALS['cc_options'][ $key ], 'Hold never rotates HMAC token or quoted state.' ); cc_same( $link, cc_link(), 'Staff retrieves the identical original link after release.' );
		$result = cc_apply( $token ); cc_check( is_array( $result ) && 'saved' === ( $result['state'] ?? '' ), 'Same valid token can complete its normal confirmed workflow after release.' ); cc_same( 25500.0, (float) $result['total'], 'Unchanged quoted retail combination applies.' ); cc_same( 1, count( cc_writes() ), 'One existing Books update after release.' ); cc_same( 1, count( cc_writes( 'crm' ) ), 'One paired CRM update after release.' );
	});

	cc_case( 'Public view exposes only customer quote choices and has no approval URL before confirmation', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $key = cc_record_key(); $before = $GLOBALS['cc_options'][ $key ];
		$view = Customer_Choices::instance()->view( $token ); cc_check( is_array( $view ), 'Valid link gives a public view.' );
		cc_assert_public( $view ); cc_same( 2, count( $view['systems'] ), 'Two physical systems.' );
		foreach ( $view['systems'] as $system ) { cc_same( 3, count( $system['choices'] ), 'Three alternatives per physical system.' ); }
		cc_same( '', $view['review_url'] ?? '', 'No approval link before explicit choices confirmation.' );
		cc_same( $before, $GLOBALS['cc_options'][ $key ], 'Reading does not consume or mutate the quote record.' );
		cc_same( 0, count( cc_writes() ), 'Reading does not mutate Books.' );
	});
	cc_case( 'Legacy quote without alternatives creates no customer choice record', function (): void {
		$body = cc_reset(); unset( $body['_proposal_systems'] ); Audit_Log::instance()->generate['payload'] = json_encode( $body );
		$link = Customer_Choices::instance()->link_for_estimate( '400000000000000001', 1 );
		cc_check( is_array( $link ) && false === ( $link['available'] ?? true ), 'Legacy quote reports no alternative link.' ); cc_same( 0, count( cc_writes() ), 'No provider writes.' );
	});
	cc_case( 'A different owner cannot mint a link for a generated estimate', function (): void {
		cc_reset(); $GLOBALS['cc_user'] = 2;
		cc_error( Customer_Choices::instance()->link_for_estimate( '400000000000000001', 2 ) ); cc_same( 0, count( cc_writes() ), 'No provider writes.' );
	});
	cc_case( 'An arbitrary estimate ID cannot mint a scoped link', function (): void {
		cc_reset(); cc_error( Customer_Choices::instance()->link_for_estimate( '400000000000000002', 1 ) ); cc_same( 0, count( cc_writes() ), 'No provider writes.' );
	});
	foreach ( array( '', 'bad', str_repeat( 'a', 97 ), '00000000000000000000000000000000.' . str_repeat( 'b', 64 ) ) as $index => $token ) {
		cc_case( 'Missing or malformed token is rejected ' . $index, function () use ( $token ): void { cc_reset(); cc_error( Customer_Choices::instance()->view( $token ) ); cc_same( 0, count( cc_writes() ), 'No provider writes.' ); });
	}
	cc_case( 'Tampered signature does not expose or update a quote', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $last = substr( $token, -1 ); $token = substr( $token, 0, -1 ) . ( 'a' === $last ? 'b' : 'a' );
		cc_error( Customer_Choices::instance()->view( $token ) ); cc_error( cc_apply( $token ) ); cc_same( 0, count( cc_writes() ), 'No provider writes.' );
	});
	cc_case( 'Expiry rejects a previously valid signed token without a provider write', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $GLOBALS['cc_time'] += 31 * DAY_IN_SECONDS;
		cc_error( Customer_Choices::instance()->view( $token ) ); cc_error( cc_apply( $token ) ); cc_same( 0, count( cc_writes() ), 'Expired link cannot update Books.' );
	});
	cc_case( 'Revocation rejects a previously valid signed token', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); Customer_Choices::instance()->revoke_for_estimate( '400000000000000001', 1 );
		cc_error( Customer_Choices::instance()->view( $token ) ); cc_error( cc_apply( $token ) ); cc_same( 0, count( cc_writes() ), 'Revoked link cannot update Books.' );
	});
	cc_case( 'Organization switch invalidates an older scoped link', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $GLOBALS['cc_options']['tc_estimate_zoho_org_id'] = '600000000000000002';
		cc_error( Customer_Choices::instance()->view( $token ) ); cc_error( cc_apply( $token ) ); cc_same( 0, count( cc_writes() ), 'No cross-organization write.' );
	});
	cc_case( 'Revoked owner capability invalidates previously delegated choices', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $GLOBALS['cc_owner_allowed'] = false;
		cc_error( Customer_Choices::instance()->view( $token ) ); cc_error( cc_apply( $token ) ); cc_same( 0, count( cc_writes() ), 'No write after owner permission is revoked.' );
	});
	cc_case( 'Selecting one package for each system updates exactly one existing Books estimate', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $result = cc_apply( $token ); cc_check( is_array( $result ), 'Choices update returns a result.' );
		cc_same( 25500.0, (float) $result['total'], 'Chosen25000+common500, never all63000.' );
		$writes = cc_writes(); cc_same( 1, count( $writes ), 'One Books mutation.' );
		cc_check( str_starts_with( $writes[0]['path'], '/estimates/400000000000000001?' ) && str_contains( $writes[0]['path'], 'organization_id=600000000000000001' ) && str_contains( $writes[0]['path'], 'send=false' ), 'Existing bound resource, organization and no-email update.' );
		$lines = $writes[0]['body']['line_items']; cc_same( 4, count( $lines ), 'Two chosen equipment items and two zero add-ons.' );
		cc_same( array( '900000000000000003', '900000000000000006', '900000000000000007', '900000000000000008' ), array_column( $lines, 'item_id' ), 'Only chosen identities and approved add-ons.' );
		cc_same( array( 1, 1, 1, 1 ), array_map( static fn ( array $line ): int => (int) $line['quantity'], $lines ), 'Each physical item appears once.' );
		cc_same( array( 0.0, 0.0 ), array_map( static fn ( array $line ): float => (float) $line['rate'], array_slice( $lines, 2 ) ), 'Optional items remain zero today.' );
		cc_same( 'Synthetic original notes.', $writes[0]['body']['notes'], 'Unrelated current notes preserved.' );
		cc_same( 'Synthetic original terms.', $writes[0]['body']['terms'], 'Unrelated current terms preserved.' );
		cc_same( 'draft', Zoho_API::instance()->estimate['status'], 'Choice update does not accept or change status.' );
		cc_same( 2, count( $GLOBALS['cc_single_attempts'] ), 'Both financial provider mutations use the single-attempt adapter.' );
		$crm = cc_writes( 'crm' ); cc_same( 1, count( $crm ), 'The bound existing CRM deal is synchronized once.' );
		cc_same( array(), $crm[0]['body']['trigger'] ?? null, 'CRM workflows are not triggered by package selection.' );
		cc_same( 25500.0, (float) ( $crm[0]['body']['data'][0]['Amount'] ?? 0 ), 'CRM amount matches the selected sales total.' );
		cc_check( ! str_contains( (string) json_encode( $crm[0]['body'] ), 'purchase_cost' ), 'CRM payload has no private purchase cost field.' );
		cc_assert_public( $result );
	});
	cc_case( 'A sent estimate can receive package choices before approval', function (): void {
		cc_reset(); Zoho_API::instance()->estimate['status'] = 'sent'; $token = cc_token( cc_link() );
		$result = cc_apply( $token ); cc_check( is_array( $result ), 'Sent quote remains selectable.' ); cc_same( 'sent', Zoho_API::instance()->estimate['status'], 'No acceptance/status mutation.' );
	});
	foreach ( array( 'accepted', 'declined', 'invoiced', 'expired' ) as $status ) {
		cc_case( 'Finalized estimate status cannot be changed: ' . $status, function () use ( $status ): void {
			cc_reset(); $token = cc_token( cc_link() ); Zoho_API::instance()->estimate['status'] = $status;
			cc_error( cc_apply( $token ) ); cc_same( 0, count( cc_writes() ), 'No mutation of finalized quote.' );
		});
	}
	cc_case( 'A mismatched bound customer is rejected before update', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); Zoho_API::instance()->estimate['customer_id'] = '300000000000000002';
		cc_error( cc_apply( $token ) ); cc_same( 0, count( cc_writes() ), 'No cross-customer mutation.' );
	});
	foreach ( array( array(), array( array( 'system_number' => 1, 'choice_id' => 'fixture-0-gold' ) ), array( array( 'system_number' => 1, 'choice_id' => 'fixture-0-gold' ), array( 'system_number' => 1, 'choice_id' => 'fixture-0-platinum' ) ), array( array( 'system_number' => 1, 'choice_id' => 'fixture-1-gold' ), array( 'system_number' => 2, 'choice_id' => 'fixture-0-gold' ) ), array( array( 'system_number' => 1, 'choice_id' => 'invented' ), array( 'system_number' => 2, 'choice_id' => 'fixture-1-gold' ) ) ) as $index => $selections ) {
		cc_case( 'Missing, duplicate or cross-system selections are rejected ' . $index, function () use ( $selections, $index ): void {
			cc_reset(); $token = cc_token( cc_link() ); cc_error( cc_apply( $token, 1, 'fixture-invalid-' . $index, $selections ) ); cc_same( 0, count( cc_writes() ), 'Invalid choices never reach PUT.' );
		});
	}
	cc_case( 'Stale revision cannot overwrite a newer customer choice', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); cc_error( cc_apply( $token, 99 ), 409 ); cc_same( 0, count( cc_writes() ), 'No stale mutation.' );
	});
	foreach ( array( 'line_rate', 'line_quantity', 'adjustment', 'currency', 'tax' ) as $change ) {
		cc_case( 'Independent financial changes block replacement: ' . $change, function () use ( $change ): void {
			cc_reset(); $token = cc_token( cc_link() ); $api = Zoho_API::instance();
			if ( 'line_rate' === $change ) { $api->estimate['line_items'][0]['rate'] += 1; }
			elseif ( 'line_quantity' === $change ) { $api->estimate['line_items'][0]['quantity'] = 2; }
			elseif ( 'adjustment' === $change ) { $api->estimate['adjustment'] += 1; }
			elseif ( 'currency' === $change ) { $api->estimate['currency_code'] = 'EUR'; }
			else { $api->estimate['tax_total'] = 100; }
			cc_error( cc_apply( $token ) ); cc_same( 0, count( cc_writes() ), 'External edits are not overwritten.' );
		});
	}
	cc_case( 'Identical completed request is replayed without another Books mutation', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $first = cc_apply( $token ); cc_check( is_array( $first ), 'First request completed.' );
		$second = cc_apply( $token ); cc_check( is_array( $second ), 'Replay returns a result.' );
		cc_same( $first['total'], $second['total'], 'Stable total on replay.' ); cc_same( $first['revision'], $second['revision'], 'No revision increment on replay.' ); cc_same( 1, count( cc_writes() ), 'No repeated financial write.' );
	});
	cc_case( 'Reusing an operation ID for different choices is rejected', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); cc_check( is_array( cc_apply( $token ) ), 'First operation saved.' );
		cc_error( cc_apply( $token, 1, 'fixture-request-0001', cc_selections( 'gold', 'gold' ) ), 409 ); cc_same( 1, count( cc_writes() ), 'Different body cannot reuse a completed operation.' );
	});
	cc_case( 'Timeout with unchanged provider state stays pending and is not blindly repeated', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); Zoho_API::instance()->books_mode = 'timeout-before';
		$first = cc_apply( $token ); cc_check( is_array( $first ) && 'pending' === ( $first['state'] ?? '' ), 'Unknown outcome is explicit pending.' );
		$second = cc_apply( $token ); cc_check( is_array( $second ) && 'pending' === ( $second['state'] ?? '' ), 'Read reconciliation does not invent success.' );
		cc_same( 1, count( cc_writes() ), 'No second ambiguous Books PUT.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'No CRM update before confirmed Books.' );
	});
	cc_case( 'A different operation cannot overtake an unresolved update', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); Zoho_API::instance()->books_mode = 'timeout-before'; cc_apply( $token );
		cc_error( cc_apply( $token, 1, 'fixture-request-0002', cc_selections( 'gold', 'gold' ) ), 409 ); cc_same( 1, count( cc_writes() ), 'Only one unresolved operation.' );
	});
	cc_case( 'Timeout after provider application reconciles by GET without another Books PUT', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); Zoho_API::instance()->books_mode = 'timeout-after'; $first = cc_apply( $token ); cc_check( is_array( $first ), 'Initial result is controlled.' );
		$next = cc_apply( $token ); cc_check( is_array( $next ) && true === ( $next['saved'] ?? false ), 'Desired provider state is confirmed.' );
		cc_same( 1, count( cc_writes() ), 'Reconciliation does not repeat the PUT.' ); cc_same( 25500.0, (float) $next['total'], 'Confirmed selected total.' );
	});
	cc_case( 'CRM failure remains an explicit partial state and never blindly retries either provider', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); Zoho_API::instance()->crm_fail = true;
		$first = cc_apply( $token ); cc_check( is_array( $first ) && true === ( $first['crm_sync_pending'] ?? false ), 'Partial CRM state is visible.' );
		cc_check( 'saved' !== ( $first['state'] ?? '' ), 'Full success is not claimed while CRM remains pending.' );
		Zoho_API::instance()->crm_fail = false; $next = cc_apply( $token );
		cc_check( is_array( $next ) && true === ( $next['crm_sync_pending'] ?? false ), 'Unchanged CRM state still needs staff review or later reconciliation.' );
		cc_same( 1, count( cc_writes() ), 'Books is not written twice.' ); cc_same( 1, count( cc_writes( 'crm' ) ), 'An uncertain CRM write is not blindly repeated.' );
	});

	cc_case( 'Changed Books customer before link creation fails the independent account binding', function (): void {
		cc_reset(); Zoho_API::instance()->estimate['customer_id'] = '300000000000000002';
		cc_error( Customer_Choices::instance()->link_for_estimate( '400000000000000001', 1 ) ); cc_same( 0, count( cc_writes() ), 'No choice link can authorize a quote moved to another customer.' );
	});
	foreach ( array( 'accepted', 'financial_edit', 'customer_changed' ) as $change ) {
		cc_case( 'Existing staff link reuse rechecks the current Books quote: ' . $change, function () use ( $change ): void {
			cc_reset(); cc_link(); $api = Zoho_API::instance();
			if ( 'accepted' === $change ) { $api->estimate['status'] = 'accepted'; }
			elseif ( 'customer_changed' === $change ) { $api->estimate['customer_id'] = '300000000000000002'; }
			else { $api->estimate['line_items'][0]['rate'] += 1; $api->estimate['total'] += 1; }
			cc_error( Customer_Choices::instance()->link_for_estimate( '400000000000000001', 1 ) ); cc_same( 0, count( cc_writes() ), 'Staff retry cannot silently reuse a changed quote link.' );
		});
	}
	foreach ( array( 'missing', 'reassigned_account', 'wrong_estimate', 'wrong_deal_id', 'missing_row_id', 'ambiguous_slot' ) as $change ) {
		cc_case( 'An unbound or ambiguous existing CRM deal blocks link issuance: ' . $change, function () use ( $change ): void {
			cc_reset(); $api = Zoho_API::instance();
			if ( 'missing' === $change ) { $api->crm_missing = true; }
			elseif ( 'reassigned_account' === $change ) { $api->deal['Account_Name']['id'] = '200000000000000002'; }
			elseif ( 'wrong_estimate' === $change ) { $api->deal['Books_Estimate_ID'] = '400000000000000002'; }
			elseif ( 'wrong_deal_id' === $change ) { $api->deal['id'] = '500000000000000002'; }
			elseif ( 'missing_row_id' === $change ) { unset( $api->deal['Quoted_Equipment'][0]['id'] ); }
			else { $row = $api->deal['Quoted_Equipment'][0]; $row['id'] = '820000000000000004'; $api->deal['Quoted_Equipment'][] = $row; }
			cc_error( Customer_Choices::instance()->link_for_estimate( '400000000000000001', 1 ) ); cc_same( 0, count( cc_writes() ), 'No Books mutation if paired CRM identity cannot be established.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'No speculative deal modification.' );
		});
	}
	foreach ( array( 'amount', 'owned_rate', 'owned_item', 'reassigned' ) as $change ) {
		cc_case( 'Independent owned CRM changes block package writes: ' . $change, function () use ( $change ): void {
			cc_reset(); $token = cc_token( cc_link() ); $api = Zoho_API::instance();
			if ( 'amount' === $change ) { $api->deal['Amount'] += 10; }
			elseif ( 'owned_rate' === $change ) { $api->deal['Quoted_Equipment'][0]['Rate'] += 1; }
			elseif ( 'owned_item' === $change ) { $api->deal['Quoted_Equipment'][0]['Zoho_Item_ID'] = '900000000000000009'; }
			else { $api->deal['Account_Name']['id'] = '200000000000000002'; }
			cc_error( cc_apply( $token ) ); cc_same( 0, count( cc_writes() ), 'Independent quote changes require staff review before Books modification.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'No unrelated CRM overwrite.' );
		});
	}
	cc_case( 'Existing CRM subform IDs prevent duplicate equipment and preserve independent rows', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $api = Zoho_API::instance(); $independent = $api->deal['Quoted_Equipment'][2];
		$api->deal['Quoted_Equipment'][2]['Fixture_Independent_Field'] = 'new independent metadata';
		$result = cc_apply( $token ); cc_check( is_array( $result ) && true === ( $result['saved'] ?? false ), 'Package choices synchronize.' );
		$rows = $api->deal['Quoted_Equipment']; cc_same( 3, count( $rows ), 'Updates do not append duplicate owned rows.' ); cc_same( array( '820000000000000001', '820000000000000002', '820000000000000003' ), array_column( $rows, 'id' ), 'Stable row IDs preserved.' );
		cc_same( array( '900000000000000003', '900000000000000006', '900000000000000009' ), array_column( $rows, 'Zoho_Item_ID' ), 'Only original owned system slots changed.' );
		cc_same( 'new independent metadata', $rows[2]['Fixture_Independent_Field'], 'Independent metadata survives the choice update.' );
		cc_same( 'preserve lower metadata', $rows[0]['Fixture_Independent_Field'], 'Unrelated owned-row metadata preserved.' );
		$sent = cc_writes( 'crm' )[0]['body']['data'][0]['Quoted_Equipment'];
		cc_same( array( '820000000000000001', '820000000000000002' ), array_column( $sent, 'id' ), 'CRM PUT updates owned rows by their existing IDs, omitting independent rows.' );
		cc_check( count( array_filter( $api->calls, static fn ( array $call ): bool => 'GET' === $call['method'] && 'crm' === $call['service'] ) ) >= 3, 'Read back CRM after the update before claiming success.' );
	});
	cc_case( 'CRM timeout after applying reconciles by GET with no repeated financial writes', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $api = Zoho_API::instance(); $api->crm_mode = 'timeout-after';
		$first = cc_apply( $token ); cc_check( is_array( $first ), 'Ambiguous provider response is controlled.' );
		$next = cc_apply( $token ); cc_check( is_array( $next ) && true === ( $next['saved'] ?? false ) && false === ( $next['crm_sync_pending'] ?? true ), 'Desired CRM state is verified by read.' );
		cc_same( 1, count( cc_writes() ), 'Books not repeated.' ); cc_same( 1, count( cc_writes( 'crm' ) ), 'CRM not repeated.' ); cc_same( 3, count( $api->deal['Quoted_Equipment'] ), 'No appended duplicate subform rows.' );
	});
	cc_case( 'CRM timeout before applying stays partial and never blindly repeats the CRM update', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $api = Zoho_API::instance(); $api->crm_mode = 'timeout-before'; cc_apply( $token ); $api->crm_mode = 'success'; $api->revoke_during_books_put = false; $GLOBALS['cc_revoke_inflight'] = null;
		$next = cc_apply( $token ); cc_check( is_array( $next ) && true === ( $next['crm_sync_pending'] ?? false ) && 'books_updated' === ( $next['state'] ?? '' ), 'Unchanged CRM remains a visible partial outcome.' );
		cc_same( 1, count( cc_writes() ), 'Books not repeated.' ); cc_same( 1, count( cc_writes( 'crm' ) ), 'Uncertain CRM write not repeated.' );
		cc_check( isset( $next['pending']['request_id'] ), 'Customer can check the same durable operation rather than start another.' );
	});

	cc_case( 'Confirming already quoted selections does not needlessly replace Books lines', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $result = cc_apply( $token, 1, 'fixture-unchanged-0001', cc_selections( 'silver', 'silver' ) );
		cc_check( is_array( $result ) && true === ( $result['saved'] ?? false ), 'Customer explicitly confirmed the original selected packages.' ); cc_same( 2, $result['revision'], 'Confirmation advances choice revision.' );
		cc_same( 17500.0, (float) $result['total'], 'Original chosen quote retains its total.' ); cc_same( 0, count( cc_writes() ), 'No replacement needed for identical Books state.' );
		cc_same( 'https://books.zoho.com/portal/fixture-estimate-review', $result['review_url'], 'Verified customer approval remains separate.' );
	});
	cc_case( 'A later valid revision updates the same quote to another per-system combination', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $first = cc_apply( $token );
		$second = cc_apply( $token, $first['revision'], 'fixture-request-0002', cc_selections( 'gold', 'silver' ) );
		cc_check( is_array( $second ) && true === ( $second['saved'] ?? false ), 'Next quoted choice combination saved.' ); cc_same( 19500.0, (float) $second['total'], '10000 lower+9000 upper+shared500.' );
		cc_same( 3, $second['revision'], 'Each successful selection advances one revision.' ); cc_same( 2, count( cc_writes() ), 'Each explicit changed combination updates the existing quote once.' );
		cc_same( array( '900000000000000002', '900000000000000004', '900000000000000007', '900000000000000008' ), array_column( cc_writes()[1]['body']['line_items'], 'item_id' ), 'Selected combination replaces the full quote line list while preserving zero add-ons.' );
	});
	cc_case( 'Semantically identical reordered selections replay the same completed request', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $first = cc_apply( $token );
		$second = cc_apply( $token, 1, 'fixture-request-0001', array_reverse( cc_selections() ) );
		cc_check( is_array( $second ), 'Ordering alone does not create an operation conflict.' ); cc_same( $first, $second, 'Canonical system order produces the identical stored response.' ); cc_same( 1, count( cc_writes() ), 'Replay is read-only.' );
	});
	cc_case( 'Durable request history is bounded across minute rate-limit windows', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $revision = 1;
		for ( $i = 0; $i < 20; $i++ ) {
			$choice = 0 === $i % 2 ? 'gold' : 'platinum';
			$result = cc_apply( $token, $revision, 'fixture-bounded-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ), cc_selections( $choice, $choice ) );
			cc_check( is_array( $result ) && true === ( $result['saved'] ?? false ), 'Within durable operation bound.' ); $revision = $result['revision']; $GLOBALS['cc_time'] += 61;
		}
		cc_error( cc_apply( $token, $revision, 'fixture-bounded-9999', cc_selections( 'silver', 'silver' ) ), 429 ); cc_same( 20, count( cc_writes() ), 'Bounded history rejects further operations before provider mutation.' );
	});

	cc_case( 'An atomic held estimate lock rejects a competing update', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $lookup = explode( '.', $token )[0];
		$GLOBALS['cc_options'][ 'tc_estimate_choice_lock_' . $lookup ] = array( 'token' => 'fixture-other-lock', 'created_at' => time() );
		cc_error( cc_apply( $token ), 409 ); cc_same( 0, count( cc_writes() ), 'No concurrent Books mutation.' );
		cc_same( 'fixture-other-lock', $GLOBALS['cc_options'][ 'tc_estimate_choice_lock_' . $lookup ]['token'], 'A competing lock is never deleted.' );
	});
	cc_case( 'Staff revocation cannot race an existing customer update lock', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $lookup = explode( '.', $token )[0]; $key = cc_record_key();
		$GLOBALS['cc_options'][ 'tc_estimate_choice_lock_' . $lookup ] = array( 'token' => 'fixture-active-apply', 'created_at' => time() );
		cc_error( Customer_Choices::instance()->revoke_for_estimate( '400000000000000001', 1 ), 409 );
		cc_same( false, $GLOBALS['cc_options'][ $key ]['revoked'], 'A denied competing revoke never mutates the choice record.' ); cc_same( 'fixture-active-apply', $GLOBALS['cc_options'][ 'tc_estimate_choice_lock_' . $lookup ]['token'], 'Revoke never deletes another operation lock.' );
		cc_same( 0, count( cc_writes() ), 'No financial writes on a denied revocation.' );
	});
	cc_case( 'Revoke attempted inside provider update cannot be lost beneath completion persistence', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); Zoho_API::instance()->revoke_during_books_put = true;
		$result = cc_apply( $token ); cc_check( is_array( $result ) && true === ( $result['saved'] ?? false ), 'Original locked update completes.' ); cc_error( $GLOBALS['cc_revoke_inflight'], 409 );
		cc_check( is_array( Customer_Choices::instance()->revoke_for_estimate( '400000000000000001', 1 ) ), 'Staff can explicitly revoke after operation releases its lock.' );
		cc_error( Customer_Choices::instance()->view( $token ) ); cc_same( 1, count( cc_writes() ), 'Revocation does not submit another quote update.' );
	});

	function cc_crashed_lock( string $token, int $age = 121 ): string {
		$key = 'tc_estimate_choice_lock_' . explode( '.', $token )[0]; $GLOBALS['cc_options'][ $key ] = array( 'token' => 'fixture-crashed-worker', 'created_at' => $GLOBALS['cc_time'] - $age ); return $key;
	}
	cc_case( 'An aged worker lock still blocks the same pending request without automatic takeover', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); Zoho_API::instance()->books_mode = 'timeout-before'; cc_apply( $token ); $lock = cc_crashed_lock( $token, 600 );
		cc_error( cc_apply( $token ), 409 ); cc_same( 'fixture-crashed-worker', $GLOBALS['cc_options'][ $lock ]['token'], 'Elapsed time is not proof the original worker stopped.' );
		cc_same( 1, count( cc_writes() ), 'No blind repeated Books write.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'No new CRM mutation.' );
	});
	cc_case( 'Staff revocation cannot automatically take over an aged worker lock', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $lock = cc_crashed_lock( $token, 600 );
		cc_error( Customer_Choices::instance()->revoke_for_estimate( '400000000000000001', 1 ), 409 ); cc_same( 'fixture-crashed-worker', $GLOBALS['cc_options'][ $lock ]['token'], 'Staff route also preserves original lease.' );
		cc_same( false, $GLOBALS['cc_options'][ cc_record_key() ]['revoked'], 'No lost revocation is reported as successful.' ); cc_same( 0, count( cc_writes() ), 'No provider writes.' );
	});
	cc_case( 'Verified administrative removal of an exact dead-worker lock preserves pending outcome for reconciliation', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $api = Zoho_API::instance(); $api->books_mode = 'timeout-before'; cc_apply( $token ); $lock = cc_crashed_lock( $token, 600 ); $before = $GLOBALS['cc_options'][ cc_record_key() ];
		// Simulates an administrator proving worker termination and deleting this exact option via supported WP-CLI. This is in-memory only.
		cc_same( 'fixture-crashed-worker', $GLOBALS['cc_options'][ $lock ]['token'], 'Operator identifies the exact old lease.' ); delete_option( $lock );
		cc_same( $before, $GLOBALS['cc_options'][ cc_record_key() ], 'Administrative unlock never clears pending request identity.' );
		$result = cc_apply( $token ); cc_check( is_array( $result ) && 'pending' === ( $result['state'] ?? '' ), 'Unchanged provider is still unresolved after a verified unlock.' );
		cc_same( 1, count( cc_writes() ), 'Even a verified unlock does not authorize another ambiguous Books PUT.' );
	});
	cc_case( 'After verified dead-worker removal the same operation can read-confirm applied Books and synchronize CRM once', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $api = Zoho_API::instance(); $api->books_mode = 'timeout-after'; cc_apply( $token ); $lock = cc_crashed_lock( $token, 600 ); delete_option( $lock );
		$result = cc_apply( $token ); cc_check( is_array( $result ) && true === ( $result['saved'] ?? false ) && false === ( $result['crm_sync_pending'] ?? true ), 'Original operation reconciles actual provider state.' );
		cc_same( 1, count( cc_writes() ), 'Books not repeated after operator unlock.' ); cc_same( 1, count( cc_writes( 'crm' ) ), 'Previously unattempted CRM synchronization occurs once.' );
	});
	cc_case( 'A different operation cannot overtake the preserved pending request after administrative unlock', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); Zoho_API::instance()->books_mode = 'timeout-before'; cc_apply( $token ); delete_option( cc_crashed_lock( $token, 600 ) );
		cc_error( cc_apply( $token, 1, 'fixture-request-0002', cc_selections( 'gold', 'gold' ) ), 409 ); cc_same( 1, count( cc_writes() ), 'Only the exact original pending request may reconcile.' );
	});
	cc_case( 'A worker that loses its lease cannot persist success or start the CRM write', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $api = Zoho_API::instance(); $api->replace_lock_during_books_put = true; $lock = 'tc_estimate_choice_lock_' . explode( '.', $token )[0];
		$result = cc_apply( $token ); cc_check( is_array( $result ) && 'pending' === ( $result['state'] ?? '' ), 'Lost fence remains unresolved locally.' );
		cc_same( 1, count( cc_writes() ), 'Initial in-flight Books mutation was not repeated.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'Fenced prior worker cannot start next provider mutation.' ); cc_same( 'fixture-fenced-worker', $GLOBALS['cc_options'][ $lock ]['token'], 'Prior worker does not delete replacement lease.' );
		cc_same( false, $GLOBALS['cc_options'][ cc_record_key() ]['confirmed'], 'Prior worker cannot overwrite durable confirmation under another lease.' );
	});

	cc_case( 'Successful update releases its own lock and preserves durable operation result', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); cc_apply( $token ); $lookup = explode( '.', $token )[0];
		cc_check( ! isset( $GLOBALS['cc_options'][ 'tc_estimate_choice_lock_' . $lookup ] ), 'Own lock released.' );
		$record = $GLOBALS['cc_options'][ cc_record_key() ];
		cc_check( isset( $record['requests']['fixture-request-0001']['result'] ) && null === $record['pending'], 'Completed operation is durable and no longer pending.' );
	});
	foreach ( array( 'inactive', 'missing_package_tag', 'category_changed', 'item_tax', 'maintenance_unavailable', 'permit_became_paid' ) as $change ) {
		cc_case( 'Fresh catalog eligibility is required before replacement: ' . $change, function () use ( $change ): void {
			cc_reset(); $token = cc_token( cc_link() ); $api = Zoho_API::instance(); $id = '900000000000000003';
			if ( 'inactive' === $change ) { $api->items[ $id ]['status'] = 'inactive'; }
			elseif ( 'missing_package_tag' === $change ) { $api->items[ $id ]['tags'] = array(); }
			elseif ( 'category_changed' === $change ) { $api->items[ $id ]['cf_category_2'] = 'High-Efficiency PLATINUM'; }
			elseif ( 'item_tax' === $change ) { $api->items[ $id ]['tax_id'] = '700000000000000001'; }
			elseif ( 'maintenance_unavailable' === $change ) { $api->items['900000000000000007']['status'] = 'inactive'; }
			else { $api->items['900000000000000008']['rate'] = 350; }
			cc_error( cc_apply( $token ) ); cc_same( 0, count( cc_writes() ), 'Changed eligibility or zero-price policy is not silently replaced.' );
		});
	}
	cc_case( 'Canonical efficiency_percent drift alone blocks package replacement before either provider write', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $id = '900000000000000003'; $key = cc_record_key();
		// Isolate the canonical rating from the legacy AFUE alias in the synthetic quoted snapshot.
		// The real Catalog currently emits both from the same raw rating; testing a raw rating change alone would exercise both guards.
		$GLOBALS['cc_options'][ $key ]['catalog'][ $id ]['efficiency_percent'] = 79.0;
		$quoted = $GLOBALS['cc_options'][ $key ]['catalog'][ $id ]; $fresh = Equipment_Catalog::instance()->get_item( $id );
		cc_check( is_array( $fresh ), 'Fresh catalog lookup succeeds.' ); cc_same( $quoted['afue'], $fresh['afue'], 'Legacy AFUE alias is unchanged.' );
		$identity_fields = array( 'item_id', 'name', 'model', 'brand', 'category_2', 'selection_category', 'is_composite', 'efficiency_percent', 'afue', 'seer', 'tons', 'btu_input', 'btu_output' ); $different = array();
		foreach ( $identity_fields as $field ) { if ( ( $quoted[ $field ] ?? null ) !== ( $fresh[ $field ] ?? null ) ) { $different[] = $field; } }
		cc_same( array( 'efficiency_percent' ), $different, 'Canonical efficiency is the sole identity difference in this fixture.' );
		cc_error( cc_apply( $token ), 409 ); cc_same( 0, count( cc_writes() ), 'No Books replacement for canonical efficiency drift.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'No CRM replacement for canonical efficiency drift.' );
	});

	cc_case( 'Quoted customer sales prices remain fixed after market and purchase-cost changes', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $api = Zoho_API::instance();
		$api->items['900000000000000003']['rate'] = 99999; $api->items['900000000000000006']['rate'] = 123456;
		$api->items['900000000000000003']['purchase_rate'] = 0.01;
		$result = cc_apply( $token ); cc_check( is_array( $result ) && true === ( $result['saved'] ?? false ), 'Compatible identity with price changes can retain the original retail offer.' );
		cc_same( 25500.0, (float) $result['total'], 'Quoted25000+shared500 remains authoritative.' ); cc_assert_public( $result );
	});
	cc_case( 'Cross-record token substitution is rejected by the bound verifier', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $key = cc_record_key();
		$GLOBALS['cc_options'][ $key ]['estimate_id'] = '400000000000000002';
		cc_error( cc_apply( $token ) ); cc_same( 0, count( cc_writes() ), 'Token cannot authorize another estimate identity.' );
	});
	cc_case( 'A rate-limited public view is read-only and recovers after its minute window', function (): void {
		cc_reset(); $token = cc_token( cc_link() );
		for ( $i = 0; $i < 60; $i++ ) { cc_check( is_array( Customer_Choices::instance()->view( $token ) ), 'Within read quota.' ); }
		cc_error( Customer_Choices::instance()->view( $token ), 429 ); $GLOBALS['cc_time'] += 61;
		cc_check( is_array( Customer_Choices::instance()->view( $token ) ), 'Read quota resets.' ); cc_same( 0, count( cc_writes() ), 'Rate-limiting does not mutate Books.' );
	});
	foreach ( array( 'http://books.zoho.com/portal/fixture', 'https://books.zoho.com.evil.test/portal/fixture', 'https://evil.test/portal/fixture', 'https://books.zoho.com/app/600000000000000001#/estimates/400000000000000001', 'https://books.zoho.com/admin/fixture', 'https://books.zoho.com:444/portal/fixture', 'https://user:password@books.zoho.com/portal/fixture' ) as $index => $url ) {
		cc_case( 'Unsafe provider review link is never returned ' . $index, function () use ( $url ): void {
			cc_reset(); Zoho_API::instance()->estimate['estimate_url'] = $url; $result = cc_apply( cc_token( cc_link() ) );
			cc_check( is_array( $result ) && true === ( $result['saved'] ?? false ), 'Selection still saves without an unsafe approval URL.' ); cc_same( '', $result['review_url'], 'Only verified secure Zoho customer links are offered.' );
		});
	}

	cc_case( 'Failed durable pending persistence blocks all provider mutation', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $GLOBALS['cc_persist_fail'] = 'all';
		cc_error( cc_apply( $token ), 503 ); cc_same( 0, count( cc_writes() ), 'No Books write without durable request state.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'No CRM write before durable request state.' );
	});
	cc_case( 'Request identity and intended selections are durable before provider PUT begins', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); cc_apply( $token ); $pending = $GLOBALS['cc_provider_pending'];
		cc_check( is_array( $pending ), 'Provider observed durable pending request.' ); cc_same( 'fixture-request-0001', $pending['request_id'] ?? '', 'Bound operation ID already persisted.' );
		cc_same( 'put_started', $pending['phase'] ?? '', 'Write-start phase already persisted.' ); cc_same( array( 1 => 'fixture-0-platinum', 2 => 'fixture-1-platinum' ), $pending['selections'] ?? null, 'Bound choices already persisted.' ); cc_check( isset( $pending['hash'], $pending['lines'], $pending['total'] ), 'Reconciliation has hash and authoritative expected result.' );
	});
	cc_case( 'Provider read failure before replacement prevents every financial write', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); Zoho_API::instance()->books_get_fail = true;
		cc_error( cc_apply( $token ), 502 ); cc_same( 0, count( cc_writes() ), 'No Books write without current baseline.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'No premature CRM synchronization.' );
	});
	cc_case( 'Read timeout while reconciling an uncertain outcome stays pending without another PUT', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $api = Zoho_API::instance(); $api->books_mode = 'timeout-after'; cc_apply( $token ); $api->books_get_fail = true;
		$result = cc_apply( $token ); cc_check( is_array( $result ) && 'pending' === ( $result['state'] ?? '' ), 'Read uncertainty remains visible.' ); cc_same( 1, count( cc_writes() ), 'Unknown reads never cause another PUT.' );
	});
	cc_case( 'Final completion persistence failure returns a controlled reconciliable state', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $GLOBALS['cc_persist_fail'] = 'completed';
		$result = cc_apply( $token ); cc_check( is_array( $result ) && in_array( $result['state'] ?? '', array( 'pending', 'books_updated' ), true ), 'Failed local completion remains controlled and visibly incomplete.' );
		cc_same( 1, count( cc_writes() ), 'Books was updated once.' ); $GLOBALS['cc_persist_fail'] = '';
		$next = cc_apply( $token ); cc_check( is_array( $next ) && true === ( $next['saved'] ?? false ), 'Durable intermediate state permits reconciliation.' ); cc_same( 1, count( cc_writes() ), 'Local persistence retry never repeats Books.' );
	});

	function cc_use_email_contact_binding(): void { $GLOBALS['cc_customer_override'] = array( 'id' => '200000000000000001', 'email' => 'fixture@example.test', 'name' => 'Fixture Customer' ); }
	cc_case( 'Missing stored Books contact ID uses exact read-only email lookup before issuing a link', function (): void {
		cc_reset(); cc_use_email_contact_binding(); cc_link();
		$lookups = array_values( array_filter( Zoho_API::instance()->calls, static fn ( array $call ): bool => 'GET' === $call['method'] && '/contacts' === $call['path'] ) );
		cc_same( 1, count( $lookups ), 'One read-only contact lookup.' ); cc_same( 'fixture@example.test', $lookups[0]['query']['email'] ?? '', 'Exact synthetic account email used.' ); cc_same( 'customer', $lookups[0]['query']['contact_type'] ?? '', 'Only customer contact lookup.' ); cc_same( 0, count( cc_writes() ), 'Contact binding never creates a customer/quote.' );
	});
	foreach ( array( 'ambiguous', 'wrong_email', 'inactive', 'pagination_incomplete' ) as $problem ) {
		cc_case( 'Uncertain email contact binding fails closed: ' . $problem, function () use ( $problem ): void {
			cc_reset(); cc_use_email_contact_binding(); $api = Zoho_API::instance();
			if ( 'ambiguous' === $problem ) { $api->contacts[] = array( 'contact_id' => '300000000000000002', 'email' => 'fixture@example.test', 'status' => 'active' ); }
			elseif ( 'wrong_email' === $problem ) { $api->contacts[0]['email'] = 'other@example.test'; }
			elseif ( 'inactive' === $problem ) { $api->contacts[0]['status'] = 'inactive'; }
			else { $api->contacts_more = true; }
			cc_error( Customer_Choices::instance()->link_for_estimate( '400000000000000001', 1 ) ); cc_same( 0, count( cc_writes() ), 'No unverified contact writes.' );
		});
	}
	cc_case( 'An exact active primary contact email resolves without adopting an unrelated email match', function (): void {
		cc_reset(); cc_use_email_contact_binding(); Zoho_API::instance()->contacts[0] = array( 'contact_id' => '300000000000000001', 'email' => 'billing@example.test', 'primary_contact_email' => 'Fixture@Example.Test', 'status' => 'active' );
		cc_link(); cc_same( 0, count( cc_writes() ), 'Contact resolution is read-only.' );
	});
	function cc_component_offer(): void {
		$api = Zoho_API::instance(); $body = json_decode( Audit_Log::instance()->generate['payload'], true );
		foreach ( array( '900000000000000020' => array( 'name' => 'Fixture AC 2 Ton', 'type' => 'condenser', 'rate' => 1000 ), '900000000000000021' => array( 'name' => 'Fixture Furnace 80% AFUE 100000 BTU', 'type' => 'furnace', 'rate' => 2000 ) ) as $id => $fixture ) {
			$id = (string) $id; $api->items[ $id ] = array( 'item_id' => $id, 'name' => $fixture['name'], 'cf_equipment_type' => $fixture['type'], 'rate' => $fixture['rate'], 'status' => 'active', 'is_combo_product' => false, 'brand' => 'Fixture', 'sku' => 'FIX-COMP-' . substr( $id, -2 ), 'description' => 'Synthetic component scope.', 'tags' => array( array( 'tag_id' => '70000000001', 'tag_option_id' => '80000000001' ) ), 'cf_category_2' => 'Standard-Efficiency GOLD' );
		}
		$body['_proposal_systems'][0]['choices'][1]['equipment'] = array( 'condenser' => array( 'item_id' => '900000000000000020' ), 'furnace' => array( 'item_id' => '900000000000000021' ) );
		Audit_Log::instance()->generate['payload'] = json_encode( $body );
	}
	cc_case( 'Changing package to individual components appends only new CRM slots and deletes only the owned old slot', function (): void {
		cc_reset(); cc_component_offer(); $token = cc_token( cc_link() ); $api = Zoho_API::instance();
		$result = cc_apply( $token, 1, 'fixture-component-0001', cc_selections( 'gold', 'silver' ) );
		cc_check( is_array( $result ) && 'saved' === ( $result['state'] ?? '' ), 'Component alternative updates the same quote and paired deal.' ); cc_same( 19500.0, (float) $result['total'], 'Quoted sales prices remain per system.' );
		$selected_ids = array_column( cc_writes()[0]['body']['line_items'], 'item_id' ); sort( $selected_ids, SORT_STRING ); cc_same( array( '900000000000000004', '900000000000000007', '900000000000000008', '900000000000000020', '900000000000000021' ), $selected_ids, 'Full Books replacement contains only chosen component+upper package and zero add-ons.' );
		$updates = cc_writes( 'crm' )[0]['body']['data'][0]['Quoted_Equipment']; $deletions = array_values( array_filter( $updates, static fn ( array $row ): bool => array_key_exists( '_delete', $row ) ) );
		cc_same( array( array( 'id' => '820000000000000001', '_delete' => null ) ), $deletions, 'Only original lower package row is deleted.' );
		cc_same( 2, count( array_filter( $updates, static fn ( array $row ): bool => ! isset( $row['id'] ) ) ), 'Exactly the two new component slots append without IDs.' );
		cc_same( 4, count( $api->deal['Quoted_Equipment'] ), 'Final CRM has lower2+upper1+independent1 with no duplicate old package.' );
		cc_check( ! in_array( '820000000000000001', array_column( $api->deal['Quoted_Equipment'], 'id' ), true ), 'Old owned package row removed.' );
		cc_check( in_array( '820000000000000003', array_column( $api->deal['Quoted_Equipment'], 'id' ), true ), 'Independent row remains.' );
	});
	cc_case( 'An uncertain component CRM update is GET-reconciled without appending component duplicates', function (): void {
		cc_reset(); cc_component_offer(); $token = cc_token( cc_link() ); $api = Zoho_API::instance(); $api->crm_mode = 'timeout-after';
		$selection = cc_selections( 'gold', 'silver' ); cc_apply( $token, 1, 'fixture-component-0001', $selection );
		$result = cc_apply( $token, 1, 'fixture-component-0001', $selection ); cc_check( is_array( $result ) && 'saved' === ( $result['state'] ?? '' ), 'Actual component rows confirmed by read.' ); cc_same( 1, count( cc_writes( 'crm' ) ), 'Uncertain append never repeats.' ); cc_same( 4, count( $api->deal['Quoted_Equipment'] ), 'Only two component rows are present.' );
	});
	function cc_same_item_new_scope_offer(): void {
		$body = json_decode( Audit_Log::instance()->generate['payload'], true );
		$body['_proposal_systems'][0]['choices'][1]['equipment'] = array( 'package' => array( 'item_id' => '900000000000000001', 'description' => 'New synthetic quoted Gold scope for the same package.' ) );
		$body['_proposal_systems'][0]['choices'][1]['sales_price'] = 8000.0; Audit_Log::instance()->generate['payload'] = json_encode( $body );
	}
	cc_case( 'Same package and price with a different offered description still updates the quoted scope', function (): void {
		cc_reset(); cc_same_item_new_scope_offer(); $token = cc_token( cc_link() );
		$result = cc_apply( $token, 1, 'fixture-scope-000001', cc_selections( 'gold', 'silver' ) ); cc_check( is_array( $result ) && 'saved' === ( $result['state'] ?? '' ), 'New quoted scope saved.' );
		cc_same( 1, count( cc_writes() ), 'Same financial identities do not skip a real selected-scope change.' ); cc_same( 17500.0, (float) $result['total'], 'Price remains unchanged.' ); cc_same( 'New synthetic quoted Gold scope for the same package.', cc_writes()[0]['body']['line_items'][0]['description'], 'New selected scope reaches Books.' );
	});
	cc_case( 'Old scope after an uncertain same-item same-price update is not mistaken for confirmed success', function (): void {
		cc_reset(); cc_same_item_new_scope_offer(); $token = cc_token( cc_link() ); Zoho_API::instance()->books_mode = 'timeout-before'; $selection = cc_selections( 'gold', 'silver' );
		cc_apply( $token, 1, 'fixture-scope-000001', $selection ); $result = cc_apply( $token, 1, 'fixture-scope-000001', $selection );
		cc_check( is_array( $result ) && 'pending' === ( $result['state'] ?? '' ), 'Financial equality alone cannot confirm a changed scope.' ); cc_same( 1, count( cc_writes() ), 'Unknown scope update never blindly repeats.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'No CRM success before Books scope is verified.' );
	});

	function cc_sparse_crm( bool $populated = false ): void {
		$api = Zoho_API::instance();
		foreach ( array( 0, 1 ) as $index ) {
			$original = $api->deal['Quoted_Equipment'][ $index ];
			$api->deal['Quoted_Equipment'][ $index ] = array_intersect_key( $original, array_flip( array( 'id', 'Slot', 'System_Number', 'Zoho_Item_ID' ) ) );
			$api->deal['Quoted_Equipment'][ $index ]['Brand'] = $populated ? $original['Brand'] : null;
			$api->deal['Quoted_Equipment'][ $index ]['Model'] = $populated ? $original['Model'] : null;
		}
	}
	function cc_assert_sparse_crm_update( array $updates ): void {
		foreach ( $updates as $row ) {
			if ( array_key_exists( '_delete', $row ) ) { cc_same( array( 'id', '_delete' ), array_keys( $row ), 'Deletion carries only exact old row ID and deletion marker.' ); continue; }
			$keys = array_keys( $row ); sort( $keys, SORT_STRING );
			$expected = array( 'Brand', 'Model', 'Slot', 'System_Number', 'Zoho_Item_ID' ); if ( isset( $row['id'] ) ) { $expected[] = 'id'; } sort( $expected, SORT_STRING );
			cc_same( $expected, $keys, 'Only verified sparse CRM contract fields are transmitted.' );
		}
	}
	cc_case( 'Sparse actual CRM schema with null Brand and Model supports link and verified existing-quote update', function (): void {
		cc_reset(); cc_sparse_crm(); $api = Zoho_API::instance();
		foreach ( array( 0, 1 ) as $index ) { cc_same( null, $api->deal['Quoted_Equipment'][ $index ]['Brand'], 'Initial supported Brand is unknown.' ); cc_same( null, $api->deal['Quoted_Equipment'][ $index ]['Model'], 'Initial supported Model is unknown.' ); cc_same( 6, count( $api->deal['Quoted_Equipment'][ $index ] ), 'All other generated optional fields are absent.' ); }
		$token = cc_token( cc_link() ); $record = $GLOBALS['cc_options'][ cc_record_key() ];
		$contract = $record['crm_field_contract']; sort( $contract, SORT_STRING ); cc_same( array( 'Brand', 'Model', 'Slot', 'System_Number', 'Zoho_Item_ID' ), $contract, 'Presence establishes only this supported field contract.' );
		$result = cc_apply( $token ); cc_check( is_array( $result ) && 'saved' === ( $result['state'] ?? '' ) && false === ( $result['crm_sync_pending'] ?? true ), 'Sparse existing quote and deal update is read-confirmed.' );
		$crm = cc_writes( 'crm' ); cc_same( 1, count( $crm ), 'One paired CRM mutation.' ); cc_same( 25500.0, (float) $crm[0]['body']['data'][0]['Amount'], 'Deal amount matches chosen sales total.' ); cc_assert_sparse_crm_update( $crm[0]['body']['data'][0]['Quoted_Equipment'] );
		cc_same( 'Fixture', $api->deal['Quoted_Equipment'][0]['Brand'], 'Supported formerly-null Brand is populated from chosen catalog item.' ); cc_same( 'FIX-2', $api->deal['Quoted_Equipment'][0]['Model'], 'Supported formerly-null Model is populated from chosen item.' );
		cc_same( 3, count( $api->deal['Quoted_Equipment'] ), 'Two owned and one independent row remain.' ); cc_same( 'preserve independent row', $api->deal['Quoted_Equipment'][2]['Fixture_Independent_Field'], 'Independent row untouched.' );
	});
	cc_case( 'Sparse CRM schema with populated matching supported descriptors remains compatible', function (): void {
		cc_reset(); cc_sparse_crm( true ); $token = cc_token( cc_link() ); $result = cc_apply( $token ); cc_check( is_array( $result ) && 'saved' === ( $result['state'] ?? '' ), 'Known matching supported descriptors bind normally.' ); cc_assert_sparse_crm_update( cc_writes( 'crm' )[0]['body']['data'][0]['Quoted_Equipment'] );
	});
	cc_case( 'A populated supported CRM descriptor that disagrees with the original quote blocks link creation', function (): void {
		cc_reset(); cc_sparse_crm(); Zoho_API::instance()->deal['Quoted_Equipment'][0]['Brand'] = 'Independent different brand'; cc_error( Customer_Choices::instance()->link_for_estimate( '400000000000000001', 1 ), 409 ); cc_same( 0, count( cc_writes() ), 'No unsupported ownership assumption can change Books.' );
	});
	foreach ( array( 'id', 'Slot', 'System_Number', 'Zoho_Item_ID' ) as $field ) {
		cc_case( 'Missing required sparse CRM field blocks initial link: ' . $field, function () use ( $field ): void {
			cc_reset(); cc_sparse_crm(); unset( Zoho_API::instance()->deal['Quoted_Equipment'][0][ $field ] ); cc_error( Customer_Choices::instance()->link_for_estimate( '400000000000000001', 1 ) ); cc_same( 0, count( cc_writes() ), 'Missing required quote identity never authorizes Books.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'No speculative CRM repair.' );
		});
		cc_case( 'Required sparse CRM field disappearing after link blocks both provider writes: ' . $field, function () use ( $field ): void {
			cc_reset(); cc_sparse_crm(); $token = cc_token( cc_link() ); unset( Zoho_API::instance()->deal['Quoted_Equipment'][0][ $field ] ); cc_error( cc_apply( $token ), 409 ); cc_same( 0, count( cc_writes() ), 'Missing required identity blocks before Books.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'No speculative CRM correction.' );
		});
	}
	foreach ( array( 'Slot' => 'independent_slot', 'System_Number' => 0, 'Zoho_Item_ID' => 'bad-item', 'id' => 'bad-row' ) as $field => $value ) {
		cc_case( 'Invalid required sparse CRM identity blocks package replacement: ' . $field, function () use ( $field, $value ): void {
			cc_reset(); cc_sparse_crm(); $token = cc_token( cc_link() ); Zoho_API::instance()->deal['Quoted_Equipment'][0][ $field ] = $value; cc_error( cc_apply( $token ), 409 ); cc_same( 0, count( cc_writes() ), 'Invalid required identity blocks before Books.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'No arbitrary CRM write.' );
		});
	}
	foreach ( array( 'Brand', 'Model' ) as $field ) {
		cc_case( 'Supported sparse descriptor changed independently after link blocks both writes: ' . $field, function () use ( $field ): void {
			cc_reset(); cc_sparse_crm(); $token = cc_token( cc_link() ); Zoho_API::instance()->deal['Quoted_Equipment'][0][ $field ] = 'Independent descriptor change'; cc_error( cc_apply( $token ), 409 ); cc_same( 0, count( cc_writes() ), 'Supported descriptor drift is checked before Books.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'Independent supported descriptor not overwritten.' );
		});
		cc_case( 'Supported sparse descriptor disappearing after link blocks both writes: ' . $field, function () use ( $field ): void {
			cc_reset(); cc_sparse_crm(); $token = cc_token( cc_link() ); unset( Zoho_API::instance()->deal['Quoted_Equipment'][0][ $field ] ); cc_error( cc_apply( $token ), 409 ); cc_same( 0, count( cc_writes() ), 'Schema support cannot silently shrink before Books.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'No guesses about removed CRM schema.' );
		});
	}
	foreach ( array( 'Brand', 'Zoho_Item_ID' ) as $field ) {
		cc_case( 'Sparse CRM post-update field loss remains partial and GET-only on replay: ' . $field, function () use ( $field ): void {
			cc_reset(); cc_sparse_crm(); $token = cc_token( cc_link() ); Zoho_API::instance()->crm_drop_fields_after_write = array( $field );
			$first = cc_apply( $token ); cc_check( is_array( $first ) && 'books_updated' === ( $first['state'] ?? '' ) && true === ( $first['crm_sync_pending'] ?? false ), 'Failed provider readback remains explicit partial.' ); cc_same( '', $first['review_url'] ?? '', 'No Books approval shortcut before paired update verified.' );
			$next = cc_apply( $token ); cc_check( is_array( $next ) && true === ( $next['crm_sync_pending'] ?? false ), 'Missing provider field is not invented as successful.' ); cc_same( 1, count( cc_writes() ), 'No repeated Books PUT.' ); cc_same( 1, count( cc_writes( 'crm' ) ), 'No blind repeated CRM PUT.' );
		});
	}
	foreach ( array( 'Rate' => 7001.0, 'Short_Description' => 'New independent schema scope' ) as $field => $value ) {
		cc_case( 'A known optional CRM field appearing after sparse link issuance requires review: ' . $field, function () use ( $field, $value ): void {
			cc_reset(); cc_sparse_crm(); $token = cc_token( cc_link() ); Zoho_API::instance()->deal['Quoted_Equipment'][0][ $field ] = $value;
			cc_error( cc_apply( $token ), 409 ); cc_same( 0, count( cc_writes() ), 'Expanded known schema is detected before Books mutation.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'New independently populated optional field is not overwritten.' );
		});
	}
	cc_case( 'Inconsistent supported optional schema between original owned rows blocks link issuance', function (): void {
		cc_reset(); cc_sparse_crm(); unset( Zoho_API::instance()->deal['Quoted_Equipment'][1]['Model'] );
		cc_error( Customer_Choices::instance()->link_for_estimate( '400000000000000001', 1 ), 409 ); cc_same( 0, count( cc_writes() ), 'Ambiguous original schema blocks before Books.' ); cc_same( 0, count( cc_writes( 'crm' ) ), 'No speculative subform repair.' );
	});
	cc_case( 'Unrecognized provider metadata does not expand the optional CRM write contract', function (): void {
		cc_reset(); cc_sparse_crm(); $token = cc_token( cc_link() ); Zoho_API::instance()->deal['Quoted_Equipment'][0]['Fixture_Provider_Metadata'] = array( 'opaque' => 'Synthetic metadata, not an equipment field.' );
		$result = cc_apply( $token ); cc_check( is_array( $result ) && 'saved' === ( $result['state'] ?? '' ), 'Unrelated provider metadata does not block the existing equipment contract.' ); cc_assert_sparse_crm_update( cc_writes( 'crm' )[0]['body']['data'][0]['Quoted_Equipment'] );
		cc_check( isset( Zoho_API::instance()->deal['Quoted_Equipment'][0]['Fixture_Provider_Metadata'] ), 'Unsupported metadata is preserved by omission.' );
	});

	cc_case( 'Sparse schema component alternative appends new slots with supported fields and deletes only the owned old row', function (): void {
		cc_reset(); cc_component_offer(); cc_sparse_crm(); $token = cc_token( cc_link() ); $result = cc_apply( $token, 1, 'fixture-sparse-components', cc_selections( 'gold', 'silver' ) );
		cc_check( is_array( $result ) && 'saved' === ( $result['state'] ?? '' ), 'Mixed slot update is verified under actual sparse schema.' ); $rows = cc_writes( 'crm' )[0]['body']['data'][0]['Quoted_Equipment']; cc_assert_sparse_crm_update( $rows );
		cc_same( 2, count( array_filter( $rows, static fn ( array $row ): bool => ! isset( $row['id'] ) ) ), 'Only two new component slots append.' ); cc_same( 4, count( Zoho_API::instance()->deal['Quoted_Equipment'] ), 'Old lower package replaced by two components without duplicates.' );
	});

	function cc_endpoint(): \TempControl\Estimate\Endpoints\Customer_Choices_Endpoint { return \TempControl\Estimate\Endpoints\Customer_Choices_Endpoint::instance(); }
	function cc_request( mixed $body, array $headers = array(), string $method = 'POST', ?string $raw = null ): WP_REST_Request {
		return new WP_REST_Request( $body, array_merge( array( 'origin' => 'https://fixture.example.test', 'content-type' => 'application/json', 'sec-fetch-site' => 'same-origin' ), $headers ), $method, $raw );
	}
	function cc_dispatch( string $action, WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$endpoint = cc_endpoint();
		$permission = in_array( $action, array( 'link', 'revoke' ), true ) ? $endpoint->permission_check( $request ) : $endpoint->public_permission( $request );
		return is_wp_error( $permission ) ? $permission : $endpoint->$action( $request );
	}
	function cc_response_error( WP_REST_Response|WP_Error $response, int $status ): void {
		if ( is_wp_error( $response ) ) { cc_error( $response, $status ); return; }
		cc_same( $status, $response->get_status(), 'Controlled REST status.' ); cc_same( false, $response->get_data()['ok'] ?? null, 'Controlled error envelope.' );
	}
	cc_case( 'All customer-choice routes are POST-only with explicit permission callbacks', function (): void {
		cc_reset(); $GLOBALS['cc_routes'] = array(); cc_endpoint()->register();
		// The canonical URL is handled by the existing early-init query shell, not a new REST/business route.
		// WordPress core's inactive/missing-route response remains a separate host/framework check.
		cc_same( 4, count( $GLOBALS['cc_routes'] ), 'Only the four existing customer-choice REST operations are registered.' );
		cc_check( ! isset( $GLOBALS['cc_routes']['/customer-choices/page'] ), 'No page REST handler or new business action is registered.' );
		foreach ( array( 'link', 'revoke', 'view', 'apply' ) as $action ) {
			$route = $GLOBALS['cc_routes'][ '/customer-choices/' . $action ] ?? array();
			cc_same( 'POST', $route['methods'] ?? '', 'GET/HEAD scanners cannot invoke ' . $action ); cc_check( is_callable( $route['permission_callback'] ?? null ), 'Explicit permission gate.' );
		}
	});
	cc_case( 'Anonymous requests cannot mint or revoke staff customer-choice links', function (): void {
		cc_reset(); $GLOBALS['cc_user'] = 0;
		foreach ( array( 'link', 'revoke' ) as $action ) { cc_response_error( cc_dispatch( $action, cc_request( array( 'estimate_id' => '400000000000000001' ) ) ), 403 ); }
		cc_same( 0, count( cc_writes() ), 'No anonymous provider writes.' );
	});
	cc_case( 'Valid public view returns an explicit no-store response with no private values', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $response = cc_dispatch( 'view', cc_request( array( 'token' => $token ) ) );
		cc_check( $response instanceof WP_REST_Response, 'Successful response.' ); cc_same( 200, $response->get_status(), 'Public read status.' );
		cc_assert_public( $response->get_data() ); $headers = $response->get_headers();
		cc_check( str_contains( $headers['Cache-Control'] ?? '', 'no-store' ), 'Private bearer-scoped result is never cached.' ); cc_same( 'no-referrer', $headers['Referrer-Policy'] ?? '', 'No referrer leak.' ); cc_same( 'nosniff', $headers['X-Content-Type-Options'] ?? '', 'No content sniffing.' );
	});
	foreach ( array( array( 'origin' => '' ), array( 'origin' => 'https://evil.test' ), array( 'origin' => 'null' ), array( 'origin' => 'https://fixture.example.test.evil.test' ), array( 'sec-fetch-site' => 'cross-site' ), array( 'content-type' => 'text/plain' ), array( 'content-type' => 'application/x-www-form-urlencoded' ) ) as $index => $headers ) {
		cc_case( 'Public API rejects an unsafe browser request boundary ' . $index, function () use ( $headers ): void {
			cc_reset(); $token = cc_token( cc_link() ); $request = cc_request( array( 'token' => $token, 'revision' => 1, 'request_id' => 'fixture-request-0001', 'selections' => cc_selections() ), $headers );
			cc_response_error( cc_dispatch( 'apply', $request ), isset( $headers['content-type'] ) ? 400 : 403 ); cc_same( 0, count( cc_writes() ), 'Rejected browser boundary never reaches provider.' );
		});
	}
	cc_case( 'Oversized JSON request is rejected before quote mutation', function (): void {
		cc_reset(); $token = cc_token( cc_link() );
		$request = cc_request( array( 'token' => $token, 'revision' => 1, 'request_id' => 'fixture-request-0001', 'selections' => cc_selections() ), array(), 'POST', str_repeat( ' ', 8193 ) );
		cc_response_error( cc_dispatch( 'apply', $request ), 400 ); cc_same( 0, count( cc_writes() ), 'No oversized provider writes.' );
	});
	foreach ( array( 'price', 'estimate_id', 'customer_id', 'line_items', 'purchase_cost' ) as $field ) {
		cc_case( 'Public apply rejects an extra client authority field: ' . $field, function () use ( $field ): void {
			cc_reset(); $token = cc_token( cc_link() ); $body = array( 'token' => $token, 'revision' => 1, 'request_id' => 'fixture-request-0001', 'selections' => cc_selections(), $field => 'untrusted' );
			cc_response_error( cc_dispatch( 'apply', cc_request( $body ) ), 400 ); cc_same( 0, count( cc_writes() ), 'Client authority never replaces server quote.' );
		});
	}
	cc_case( 'Selection-level client prices are rejected even when the offered choice is valid', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); $selections = cc_selections(); $selections[0]['sales_price'] = 0;
		$response = cc_dispatch( 'apply', cc_request( array( 'token' => $token, 'revision' => 1, 'request_id' => 'fixture-request-0001', 'selections' => $selections ) ) );
		cc_response_error( $response, 400 ); cc_same( 0, count( cc_writes() ), 'No client price applied.' );
	});
	foreach ( array( null, false, 'not-an-object', array( 'token' => array() ), array( 'token' => 'bad', 'revision' => '1', 'request_id' => 'fixture-request-0001', 'selections' => array() ) ) as $index => $body ) {
		cc_case( 'Malformed or wrongly typed JSON input gets a controlled error ' . $index, function () use ( $body ): void {
			cc_reset(); cc_response_error( cc_dispatch( 'apply', cc_request( $body ) ), 400 ); cc_same( 0, count( cc_writes() ), 'No malformed provider writes.' );
		});
	}
	cc_case( 'Unknown provider result is represented as HTTP202 with no approval link', function (): void {
		cc_reset(); $token = cc_token( cc_link() ); Zoho_API::instance()->books_mode = 'timeout-before';
		$response = cc_dispatch( 'apply', cc_request( array( 'token' => $token, 'revision' => 1, 'request_id' => 'fixture-request-0001', 'selections' => cc_selections() ) ) );
		cc_check( $response instanceof WP_REST_Response, 'Controlled pending result.' ); cc_same( 202, $response->get_status(), 'Pending must not look like confirmed success.' ); cc_same( '', $response->get_data()['data']['review_url'] ?? null, 'Pending has no approval link.' );
	});
	cc_case( 'Public rate-limit errors include Retry-After without provider diagnostics', function (): void {
		cc_reset(); $token = cc_token( cc_link() );
		for ( $i = 0; $i < 60; $i++ ) { cc_dispatch( 'view', cc_request( array( 'token' => $token ) ) ); }
		$response = cc_dispatch( 'view', cc_request( array( 'token' => $token ) ) );
		cc_check( $response instanceof WP_REST_Response, 'Controlled REST rate response.' ); cc_response_error( $response, 429 ); cc_same( '60', $response->get_headers()['Retry-After'] ?? '', 'Bounded retry guidance.' );
		cc_same( array( 'code', 'message' ), array_keys( $response->get_data()['error'] ), 'No provider body/debug data in public error.' );
	});

	echo "Customer choices smoke: {$cc_passed} cases passed, {$cc_failed} failed; {$cc_assertions} assertions passed.\n";
	exit( $cc_failed > 0 ? 1 : 0 );
}
