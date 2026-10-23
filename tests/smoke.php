<?php
/**
 * Standalone smoke test — no composer required.
 *
 * Uses the bundled Mustache fallback and early WordPress stand-ins to check
 * rendering, v0.4.8 transaction shaping, and draft creation with an in-memory Zoho double.
 *
 * The full PHPUnit suite (with real Mustache.php via composer) is in tests/.
 * This file is for "did I break something obvious" verification.
 *
 * Usage:  php tests/smoke.php
 */

declare( strict_types=1 );

// Exercise the same Mustache fallback shipped in the saved installer.
require_once __DIR__ . '/standalone-shims.php';
require_once dirname( __DIR__ ) . '/includes/class-mustache-shim.php';

// --- Set up constants so the renderer loads ---
define( 'ABSPATH', __DIR__ . '/' );
define( 'TC_ESTIMATE_VERSION', '0.4.8-smoke' );

require_once dirname( __DIR__ ) . '/includes/class-token-renderer.php';

echo "Smoke test — running against Token_Renderer with the bundled Mustache fallback.\n\n";

$renderer = \TempControl\Estimate\Token_Renderer::instance();

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
$assert( str_contains( $rendered, 'Includes a 10-year manufacturer parts warranty.' ), 'selected parts warranty in output' );
$assert( str_contains( $rendered, 'Includes a 10-year labor warranty with an active Temp Control maintenance agreement.' ), 'selected labor warranty in output' );
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

// Only pure payload shaping is invoked here; no WordPress or provider calls.
require_once __DIR__ . '/../includes/class-estimate-generator.php';

// Invoke the current transformation without creating any provider records.
$generator = TempControl\Estimate\Estimate_Generator::instance();
$ref = new ReflectionClass( TempControl\Estimate\Estimate_Generator::class );
$m = $ref->getMethod( 'build_transaction_payload' );
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
	'books_contact_id' => '30000000000',
	'name' => 'Test Customer',
	'billing_address' => array( 'street' => '1 Main', 'city' => 'Edison', 'state' => 'NJ', 'zip' => '08817', 'country' => 'U.S.A.' ),
);
$test_template = array( 'id' => 42, 'name' => 'Test Template', 'template_type' => 'full_replacement', 'version' => 3 );

$dp = $m->invoke( $generator, array(), $test_customer, $test_view, '<p>Test body</p>', $test_template, 'tc-smoke-1', '60000000000' );

$assert( isset( $dp['meta'], $dp['books'], $dp['crm'] ), 'transaction payload has meta/books/crm sections' );
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
$assert( '<p>Test body</p>' === $dp['books']['template_body'], 'polished HTML stays in template_body' );
$assert( str_contains( $dp['books']['notes'], 'approve through Zoho Books' ), 'Books notes explain the approval workflow' );
$assert( ! str_contains( $dp['books']['notes'], '<p>' ), 'Books notes stay plain text' );
$assert( '30000000000' === $dp['books']['customer_id'], 'Books contact ID stays separate from CRM account ID' );
$assert( '20000000000' === $dp['crm']['account_id'], 'CRM account ID is preserved' );
$assert( 0.0 === $dp['books']['adjustment'], 'matching project total needs no adjustment' );

// ------ Test 12: Books draft boundary, with no HTTP or email implementation loaded ------
echo "\nbooks_draft_boundary_with_synthetic_responses\n";
if ( class_exists( 'TempControl\\Estimate\\Zoho_API', false ) ) {
	throw new LogicException( 'Smoke tests require an isolated process; the real Zoho API must not be loaded.' );
}
require_once __DIR__ . '/fixtures/zoho-api-double.php';
class_alias( TempControl\Estimate\Tests\ZohoApiDouble::class, 'TempControl\\Estimate\\Zoho_API' );
$api = TempControl\Estimate\Tests\ZohoApiDouble::instance();
$create_draft = $ref->getMethod( 'create_books_draft' );
$create_draft->setAccessible( true );

$api->responses = array( array( 'code' => 0, 'estimate' => array( 'estimate_id' => '90000000001', 'status' => 'draft' ) ) );
$draft = $create_draft->invoke( $generator, $dp['books'] );
$assert( '90000000001' === $draft['estimate_id'], 'a draft response is accepted' );
$assert( 1 === count( $api->calls ), 'draft creation makes one provider call' );
$request = $api->calls[0];
$assert( 'POST' === $request['method'] && 'books' === $request['service'], 'draft targets the Books estimate API' );
$assert( '/estimates?organization_id=60000000000&send=false' === $request['path'], 'Books creation explicitly disables send' );
$assert( '30000000000' === $request['body']['customer_id'], 'provider receives the Books customer ID' );
$assert( 3200.0 === $request['body']['line_items'][0]['rate'], 'provider receives the selected line price' );
$assert( ! array_key_exists( 'slot', $request['body']['line_items'][0] ) && ! array_key_exists( 'edit_key', $request['body']['line_items'][0] ), 'internal edit metadata is excluded from provider lines' );
$assert( ! array_key_exists( 'email_body', $request['body'] ) && ! array_key_exists( 'template_body', $request['body'] ), 'draft creation does not submit email/proposal bodies' );

$provider_error = new WP_Error( 'synthetic_timeout', 'Synthetic provider timeout', array( 'status' => 502 ) );
$api->calls = array();
$api->responses = array( $provider_error );
$error = $create_draft->invoke( $generator, $dp['books'] );
$assert( $provider_error === $error, 'provider transport errors propagate without invented success' );
$assert( 1 === count( $api->calls ), 'uncertain provider result is not retried by draft creation' );

$api->responses = array( array( 'code' => 42, 'message' => 'Synthetic validation failure' ) );
$error = $create_draft->invoke( $generator, $dp['books'] );
$assert( is_wp_error( $error ) && 'tc_estimate_books_create_failed' === $error->get_error_code(), 'provider validation failure stays an error' );
$api->responses = array( array( 'code' => 0, 'estimate' => array( 'status' => 'draft' ) ) );
$error = $create_draft->invoke( $generator, $dp['books'] );
$assert( is_wp_error( $error ) && 'tc_estimate_books_no_id' === $error->get_error_code(), 'a response without an estimate ID is rejected' );

$api->calls = array();
$api->responses = array( array( 'code' => 0, 'estimate' => array( 'estimate_id' => '90000000002', 'status' => 'sent' ) ) );
$error = $create_draft->invoke( $generator, $dp['books'] );
$assert( is_wp_error( $error ) && 'tc_estimate_books_not_draft' === $error->get_error_code(), 'an unexpectedly sent estimate is rejected' );
$assert( 2 === count( $api->calls ) && 'DELETE' === $api->calls[1]['method'], 'unexpected status triggers synthetic cleanup' );
$assert( '/estimates/90000000002' === $api->calls[1]['path'], 'cleanup targets only the newly returned estimate' );
$assert( array( 'organization_id' => '60000000000' ) === $api->calls[1]['query'], 'cleanup retains the organization boundary' );

// ------ Summary ------
echo "\n" . str_repeat( '=', 60 ) . "\n";
echo "Results: $pass passed, $fail failed\n";
echo str_repeat( '=', 60 ) . "\n";
exit( $fail > 0 ? 1 : 0 );
