<?php
/**
 * Standalone smoke test — no composer required.
 *
 * Shims a minimal Mustache_Engine that covers the subset of features our templates use,
 * then runs the core renderer tests to prove the Phase 1 data flow is correct.
 *
 * The full PHPUnit suite (with real Mustache.php via composer) is in tests/.
 * This file is for "did I break something obvious" verification.
 *
 * Usage:  php tests/smoke.php
 */

declare( strict_types=1 );

// --- Minimal Mustache_Engine shim ---
if ( ! class_exists( 'Mustache_Engine' ) ) {
	class Mustache_Engine {
		public function __construct( array $opts = array() ) {}

		public function render( string $template, array $data ): string {
			return $this->renderScope( $template, array( $data ) );
		}

		private function renderScope( string $template, array $scopes ): string {
			// Handle sections {{#name}}...{{/name}} and inverted {{^name}}...{{/name}} including nested.
			while ( preg_match( '/\{\{([#^])([a-zA-Z0-9_.]+)\}\}/', $template, $m, PREG_OFFSET_CAPTURE ) ) {
				$type      = $m[1][0];
				$name      = $m[2][0];
				$open_pos  = (int) $m[0][1];
				$open_len  = strlen( $m[0][0] );

				// Find matching close, accounting for nested sections of the same name.
				$depth  = 1;
				$cursor = $open_pos + $open_len;
				$close_pos = null;
				$close_len = 0;
				while ( preg_match( '/\{\{([#^\/])' . preg_quote( $name, '/' ) . '\}\}/', $template, $mc, PREG_OFFSET_CAPTURE, $cursor ) ) {
					$t = $mc[1][0];
					$pos = (int) $mc[0][1];
					$len = strlen( $mc[0][0] );
					if ( '/' === $t ) {
						$depth--;
						if ( 0 === $depth ) {
							$close_pos = $pos;
							$close_len = $len;
							break;
						}
					} else {
						$depth++;
					}
					$cursor = $pos + $len;
				}
				if ( null === $close_pos ) {
					break; // Unbalanced — bail.
				}

				$inner = substr( $template, $open_pos + $open_len, $close_pos - ( $open_pos + $open_len ) );
				$value = $this->lookup( $name, $scopes );
				$rendered = '';

				if ( '#' === $type ) {
					if ( is_array( $value ) && array_is_list( $value ) ) {
						foreach ( $value as $item ) {
							$rendered .= $this->renderScope( $inner, array_merge( $scopes, array( is_array( $item ) ? $item : array( '.' => $item ) ) ) );
						}
					} elseif ( ! empty( $value ) ) {
						$new_scopes = is_array( $value ) ? array_merge( $scopes, array( $value ) ) : $scopes;
						$rendered = $this->renderScope( $inner, $new_scopes );
					}
				} else { // '^'
					if ( empty( $value ) || ( is_array( $value ) && array_is_list( $value ) && 0 === count( $value ) ) ) {
						$rendered = $this->renderScope( $inner, $scopes );
					}
				}

				$template = substr( $template, 0, $open_pos ) . $rendered . substr( $template, $close_pos + $close_len );
			}

			// Handle {{variable}} substitution — HTML escaped.
			$template = preg_replace_callback( '/\{\{(\{?)([a-zA-Z0-9_.]+)\}?\}\}/', function ( $m ) use ( $scopes ) {
				$value = $this->lookup( $m[2], $scopes );
				if ( is_bool( $value ) ) {
					return $value ? '1' : '';
				}
				if ( null === $value || is_array( $value ) ) {
					return '';
				}
				$str = (string) $value;
				return $m[1] === '{' ? $str : htmlspecialchars( $str, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
			}, $template );

			return (string) $template;
		}

		private function lookup( string $dotted_name, array $scopes ) {
			$keys = explode( '.', $dotted_name );
			// Walk scopes from innermost to outermost.
			foreach ( array_reverse( $scopes ) as $scope ) {
				if ( ! is_array( $scope ) ) {
					continue;
				}
				$cursor = $scope;
				$found  = true;
				foreach ( $keys as $k ) {
					if ( is_array( $cursor ) && array_key_exists( $k, $cursor ) ) {
						$cursor = $cursor[ $k ];
					} else {
						$found = false;
						break;
					}
				}
				if ( $found ) {
					return $cursor;
				}
			}
			return null;
		}
	}
}

// --- Set up constants so the renderer loads ---
define( 'ABSPATH', __DIR__ . '/' );
define( 'TC_ESTIMATE_VERSION', '0.1.0-smoke' );

require_once dirname( __DIR__ ) . '/includes/class-token-renderer.php';

echo "Smoke test — running against Token_Renderer with shimmed Mustache engine.\n\n";

// Re-declare the class in a non-PHPUnit-dependent way: redefine TestCase as a trivial base.
// We do this by copying the class body into a local context that doesn't require PHPUnit.

// Simpler: instantiate TokenRendererTest via reflection would fail because its parent doesn't exist.
// So we do it manually — call the static methods we want, creating the logic inline.

$renderer = \TempControl\Estimate\Token_Renderer::instance();

$tests = array();
$pass = 0;
$fail = 0;

$assert = function ( bool $cond, string $label ) use ( &$pass, &$fail ) {
	if ( $cond ) {
		$pass++;
		echo "  ✓ $label\n";
	} else {
		$fail++;
		echo "  ✗ $label\n";
	}
};

// ------ Test 1: simple scalar ------
echo "simple_scalar_substitution\n";
$out = $renderer->render( 'Hello {{name}}', array( 'name' => 'Jessica' ) );
$assert( $out === 'Hello Jessica', "renders 'Hello Jessica', got: $out" );

// ------ Test 2: HTML escaping ------
echo "\nhtml_escaping_by_default\n";
$out = $renderer->render( '{{note}}', array( 'note' => '<script>alert(1)</script>' ) );
$assert( ! str_contains( $out, '<script>' ), 'script tag escaped' );
$assert( str_contains( $out, '&lt;script&gt;' ), 'entity form present' );

// ------ Test 3: dot access ------
echo "\nnested_dot_access\n";
$out = $renderer->render( '{{customer.name}} at {{customer.address.city}}',
	array( 'customer' => array( 'name' => 'Test Co', 'address' => array( 'city' => 'Edison' ) ) ) );
$assert( $out === 'Test Co at Edison', "dot access, got: $out" );

// ------ Test 4: loops ------
echo "\neach_loop\n";
$out = $renderer->render( '{{#items}}- {{label}}{{/items}}', array(
	'items' => array( array( 'label' => 'A' ), array( 'label' => 'B' ), array( 'label' => 'C' ) ),
) );
$assert( $out === '- A- B- C', "loop renders, got: $out" );

// ------ Test 5: conditionals ------
echo "\nconditional_block\n";
$tpl = '{{#has}}Yes{{/has}}{{^has}}No{{/has}}';
$assert( $renderer->render( $tpl, array( 'has' => true ) ) === 'Yes', 'truthy branch' );
$assert( $renderer->render( $tpl, array( 'has' => false ) ) === 'No', 'falsy branch' );

// ------ Test 6: build_payload_view single-system ------
echo "\nbuild_payload_view_single_system\n";
$customer = array(
	'name' => 'Brian Balson', 'phone' => '(732) 555-0101', 'email' => 'brian@example.com',
	'billing_address' => array( 'street' => '123 Oak Avenue', 'city' => 'Edison', 'state' => 'NJ', 'zip' => '08817' ),
);
$payload = array(
	'template_id' => 1,
	'systems' => array(
		array( 'system_number' => 1, 'system_label' => 'Main System',
			'equipment' => array(
				'furnace'   => array( 'item_id' => '100000001' ),
				'condenser' => array( 'item_id' => '100000004' ),
				'coil'      => array( 'item_id' => '100000006' ),
			),
		),
	),
	'options' => array( 'warranty_parts_years' => 10, 'warranty_labor_years' => 10, 'special_notes' => 'Line chimney with 4" liner.' ),
	'pricing' => array( 'subtotal' => 18300.00, 'total' => 18300.00, 'deposit_percent' => 35 ),
);
$catalog = array(
	'100000001' => array( 'item_id' => '100000001', 'model' => 'TG9S080C16MP11A', 'brand' => 'Coleman', 'equipment_type' => 'furnace', 'afue' => 96.0, 'btu_output' => 76800, 'stages' => 'Single', 'short_description' => '96% AFUE' ),
	'100000004' => array( 'item_id' => '100000004', 'model' => 'AC8B3036A1B', 'brand' => 'Coleman', 'equipment_type' => 'condenser', 'seer' => 13.4, 'tons' => 3.0, 'refrigerant' => 'R-410A', 'stages' => 'Single', 'short_description' => '13.4 SEER2' ),
	'100000006' => array( 'item_id' => '100000006', 'model' => 'CF36CN4B', 'brand' => 'Coleman', 'equipment_type' => 'evaporator_coil', 'tons' => 3.0, 'short_description' => 'Cased coil' ),
);
$meta = array( 'id' => 1, 'name' => 'Full Replacement', 'template_type' => 'full_replacement', 'default_warranty_parts' => 10, 'default_warranty_labor' => 10, 'version' => 1 );

// date_i18n shim.
if ( ! function_exists( 'date_i18n' ) ) {
	function date_i18n( $format, $timestamp = null ) {
		return date( $format, $timestamp ?? time() );
	}
}

$view = $renderer->build_payload_view( $payload, $customer, $catalog, $meta );
$assert( $view['customer']['name'] === 'Brian Balson', 'customer name' );
$assert( count( $view['systems'] ) === 1, 'one system' );
$assert( $view['is_multi_system'] === false, 'not multi-system' );
$assert( $view['systems'][0]['furnace']['brand'] === 'Coleman', 'furnace brand' );
$assert( $view['systems'][0]['condenser']['tons'] === 3.0, 'condenser tons' );
$assert( $view['systems'][0]['condenser']['refrigerant'] === 'R-410A', 'refrigerant' );
$assert( $view['pricing']['total_formatted'] === '$18,300.00', "pricing total formatted, got: {$view['pricing']['total_formatted']}" );
$assert( $view['pricing']['deposit_amount_formatted'] === '$6,405.00', "deposit amount (35% of 18300), got: {$view['pricing']['deposit_amount_formatted']}" );

// ------ Test 7: multi-system ------
echo "\nbuild_payload_view_multi_system (Clemente case)\n";
$payload2 = $payload;
$payload2['systems'] = array(
	array( 'system_number' => 1, 'system_label' => 'First Floor System',
		'equipment' => array(
			'furnace'   => array( 'item_id' => '100000001' ),
			'condenser' => array( 'item_id' => '100000004' ),
			'coil'      => array( 'item_id' => '100000006' ),
		),
	),
	array( 'system_number' => 2, 'system_label' => 'Second Floor System',
		'equipment' => array(
			'furnace'   => array( 'item_id' => '100000001' ),
			'condenser' => array( 'item_id' => '100000004' ),
			'coil'      => array( 'item_id' => '100000006' ),
		),
	),
);
$view2 = $renderer->build_payload_view( $payload2, $customer, $catalog, $meta );
$assert( count( $view2['systems'] ) === 2, 'two systems' );
$assert( $view2['is_multi_system'] === true, 'is_multi_system flag' );
$assert( $view2['systems'][0]['system_label'] === 'First Floor System', 'first label' );
$assert( $view2['systems'][1]['system_label'] === 'Second Floor System', 'second label' );

// ------ Test 8: rebates ------
echo "\nrebates_formatted_money\n";
$payload3 = $payload;
$payload3['options']['rebates'] = array(
	array( 'name' => 'ETOWN 2025', 'amount' => 900.00 ),
	array( 'name' => 'Manufacturer Promo', 'amount' => 500.0 ),
);
$view3 = $renderer->build_payload_view( $payload3, $customer, $catalog, $meta );
$assert( $view3['has_rebates'] === true, 'has_rebates flag' );
$assert( count( $view3['rebates'] ) === 2, 'two rebates' );
$assert( $view3['rebates'][0]['amount_formatted'] === '$900.00', 'first formatted' );

// ------ Test 9: end-to-end with actual seed template ------
echo "\nsingle_system_template_renders (full-replacement seed)\n";
$tpl_path = dirname( __DIR__ ) . '/seed-templates/full-replacement.mustache';
$assert( file_exists( $tpl_path ), 'seed template exists' );
$tpl_body = (string) file_get_contents( $tpl_path );
$rendered = $renderer->render( $tpl_body, $view );
$assert( is_string( $rendered ), 'render returned string' );
$assert( str_contains( $rendered, 'Brian Balson' ), 'customer name in output' );
$assert( str_contains( $rendered, 'Coleman' ), 'brand in output' );
$assert( str_contains( $rendered, 'AC8B3036A1B' ), 'condenser model in output' );
$assert( str_contains( $rendered, '$18,300.00' ), 'total in output' );
$assert( str_contains( $rendered, '10-Year' ), 'warranty in output' );
$assert( ! str_contains( $rendered, 'Second Floor' ), 'no multi-system leakage' );

// ------ Test 10: two-system seed renders both ------
echo "\ntwo_system_template_renders_both (Clemente case)\n";
$customer2 = array_merge( $customer, array( 'name' => 'Michael Clemente' ) );
$view4 = $renderer->build_payload_view( $payload2, $customer2, $catalog, $meta );
$rendered2 = $renderer->render( $tpl_body, $view4 );
$assert( str_contains( $rendered2, 'First Floor System' ), 'first floor label' );
$assert( str_contains( $rendered2, 'Second Floor System' ), 'second floor label' );
$assert( str_contains( $rendered2, 'Michael Clemente' ), 'customer name' );
$assert( substr_count( $rendered2, 'TG9S080C16MP11A' ) === 2, 'furnace model appears twice (one per system)' );

// ------ Test 11: Phase 2 — Estimate_Generator payload shape ------
echo "\nphase2_generator_payload_shape\n";

// Load the generator class (it doesn't need WP to be loaded for shape testing).
if ( ! defined( 'TC_ESTIMATE_VERSION' ) ) {
	define( 'TC_ESTIMATE_VERSION', '0.2.0' );
}
// Minimal WP shims so the class file can be included without a full WP bootstrap.
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $k, $d = '' ) { return $k === 'tc_estimate_zoho_org_id' ? '60000000000' : ( $k === 'tc_estimate_zoho_dc' ? 'com' : $d ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $s, bool $remove_breaks = false ): string { return trim( strip_tags( $s ) ); }
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() { return (object) array( 'ID' => 1, 'display_name' => 'Smoke Tester' ); }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int { return 1; }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ): string { return gmdate( 'Y-m-d H:i:s' ); }
}
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

require_once __DIR__ . '/../includes/class-estimate-generator.php';

// We can't instantiate the real Estimate_Generator here (it depends on Zoho_API), but we can
// reflectively test its static shape transformation.
$generator = TempControl\Estimate\Estimate_Generator::instance();
$ref = new ReflectionClass( TempControl\Estimate\Estimate_Generator::class );
$m = $ref->getMethod( 'build_deluge_payload' );
$m->setAccessible( true );

$test_view = array(
	'systems' => array(
		array(
			'system_number' => 1,
			'system_label'  => 'Main System',
			'furnace' => array( 'item_id' => '100000001', 'brand' => 'Coleman', 'model' => 'TG9S', 'rate' => 3200, 'short_description' => '80k BTU' ),
			'other'   => array(),
		),
	),
	'pricing' => array( 'subtotal' => 3200, 'total' => 3200, 'deposit_percent' => 35 ),
	'system_count' => 1,
	'special_notes' => 'Test notes',
	'has_financing' => false,
	'financing' => array( 'term_months' => 0 ),
);
$test_customer = array(
	'id' => '20000000000',
	'name' => 'Test Customer',
	'billing_address' => array( 'street' => '1 Main', 'city' => 'Edison', 'state' => 'NJ', 'zip' => '08817', 'country' => 'U.S.A.' ),
);
$test_template = array( 'id' => 42, 'name' => 'Test Template', 'template_type' => 'full_replacement', 'version' => 3 );

$dp = $m->invoke( $generator, array(), $test_customer, $test_view, '<p>Test body</p>', $test_template, 'tc-smoke-1', '60000000000' );

$assert( isset( $dp['meta'], $dp['books'], $dp['crm'] ), 'deluge payload has meta/books/crm sections' );
$assert( 'tc-smoke-1' === $dp['meta']['idempotency_key'], 'idempotency_key propagated to meta' );
$assert( 42 === $dp['meta']['template_id'], 'template_id in meta' );
$assert( 3 === $dp['meta']['template_version'], 'template_version in meta' );
$assert( '' !== $dp['meta']['plugin_version'], 'plugin version stamped (value: ' . $dp['meta']['plugin_version'] . ')' );
$assert( count( $dp['books']['line_items'] ) === 1, 'one line item for one slot' );
$assert( 'furnace' === $dp['books']['line_items'][0]['slot'], 'line item carries slot name' );
$assert( count( $dp['crm']['quoted_equipment'] ) === 1, 'one quoted_equipment row' );
$assert( 'Coleman' === $dp['crm']['quoted_equipment'][0]['Brand'], 'quoted_equipment has brand' );
$assert( 1 === $dp['crm']['quoted_equipment'][0]['System_Number'], 'quoted_equipment has system number' );
$assert( false === $dp['crm']['financing_requested'], 'financing flag false by default' );
$assert( 3200.0 === (float) $dp['books']['subtotal'], 'books subtotal matches view' );
$assert( 3200.0 === (float) $dp['crm']['amount'], 'crm amount matches total' );
$assert( str_contains( $dp['crm']['deal_name'], 'Test Customer' ), 'deal_name includes customer' );
$assert( str_contains( $dp['books']['notes'], 'Test body' ), 'notes include stripped body' );
$assert( ! str_contains( $dp['books']['notes'], '<p>' ), 'notes have no html tags' );

// ------ Summary ------
echo "\n" . str_repeat( '=', 60 ) . "\n";
echo "Results: $pass passed, $fail failed\n";
echo str_repeat( '=', 60 ) . "\n";
exit( $fail > 0 ? 1 : 0 );
