<?php
/**
 * Alternatives/customer-document and canonical zero-price add-on checks.
 * All identities and amounts are synthetic. No HTTP, email or provider adapter
 * is loaded; transaction mapping is invoked as a pure function by reflection.
 * Run separately: php app/reconstruction/runtime/tests/proposal-render-smoke.php
 */
declare( strict_types=1 );

define( 'ABSPATH', '/' );
define( 'TC_ESTIMATE_VERSION', 'proposal-render-test' );
require dirname( __DIR__, 4 ) . '/tests/standalone-shims.php';
function get_option( string $key, mixed $default = false ): mixed { return 'tc_estimate_zoho_org_id' === $key ? '60000000001' : $default; }
function wp_mail( mixed ...$args ): never { throw new LogicException( 'Email is forbidden in this test.' ); }
function wp_remote_request( mixed ...$args ): never { throw new LogicException( 'HTTP is forbidden in this test.' ); }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function sanitize_textarea_field( string $value ): string { return trim( strip_tags( $value ) ); }

$reference = dirname( __DIR__, 4 );
$runtime = dirname( __DIR__ );
require $reference . '/includes/class-mustache-shim.php';
require $runtime . '/includes/class-token-renderer.php';
require $runtime . '/includes/class-estimate-generator.php';

use TempControl\Estimate\Token_Renderer;
use TempControl\Estimate\Estimate_Generator;

const PROPOSAL_MAINTENANCE_TEXT = 'Renews at $349 for one unit.';
const PROPOSAL_PERMIT_PARAGRAPHS = array(
	'Permit fees are not included in the project total.',
	'Temp Control will prepare and complete all required permit paperwork and submit the necessary documentation to the local township on the homeowner’s behalf.',
	'All work will be performed in accordance with local building codes and manufacturer specifications. Permit payment, pickup and scheduling of Inspections are the responsibility of the homeowner.',
);
$proposal_passed = $proposal_failed = $proposal_assertions = 0;
function proposal_check( bool $condition, string $message ): void {
	$GLOBALS['proposal_assertions']++;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function proposal_same( mixed $expected, mixed $actual, string $message ): void {
	proposal_check( $expected === $actual, $message . ': ' . json_encode( $actual ) );
}
function proposal_case( string $label, callable $test ): void {
	try { $test(); $GLOBALS['proposal_passed']++; echo 'PASS ' . $label . "\n"; }
	catch ( Throwable $error ) { $GLOBALS['proposal_failed']++; echo 'FAIL ' . $label . ': ' . $error->getMessage() . "\n"; }
}
function proposal_customer(): array {
	return array( 'id' => '20000000001', 'books_contact_id' => '30000000001', 'name' => 'Synthetic Customer',
		'email' => 'customer@example.test', 'billing_address' => array( 'street' => '100 Example Street', 'city' => 'ExampleCity', 'state' => 'NJ', 'zip' => '00000' ) );
}
function proposal_meta(): array {
	return array( 'id' => 1, 'version' => 1, 'name' => 'Synthetic Alternatives Proposal', 'template_type' => 'full_replacement' );
}
function proposal_catalog(): array {
	$catalog = array();
	foreach ( array( 'Lower Silver', 'Lower Gold', 'Lower Platinum', 'Upper Silver', 'Upper Gold', 'Upper Platinum' ) as $index => $name ) {
		$id = (string) ( 90000000001 + $index );
		$catalog[ $id ] = array(
			'item_id' => $id, 'name' => 'Synthetic ' . $name . ' Package', 'model' => 'FIXTURE-' . $index, 'brand' => 'Synthetic',
			'equipment_type' => 'package', 'selection_category' => 'package', 'is_composite' => true, 'rate' => 7001.0 + $index,
			'purchase_cost' => 1137.91 + $index, 'markup' => 0.34, 'short_description' => 'Synthetic installation scope ' . $index,
			'tons' => 2.0, 'btu_input' => 80000, 'afue' => 96.0,
		);
	}
	return $catalog;
}
function proposal_addons(): array {
	return array(
		'maintenance' => array( 'available' => true, 'addon_key' => 'maintenance', 'item_id' => '90000000007', 'name' => 'New Residential System Maintenance Agreement', 'rate' => 0.0, 'quantity' => 1, 'renewal_rate' => 349.0, 'renewal_units' => 1, 'source' => 'zoho_books' ),
		'permits' => array( 'available' => true, 'addon_key' => 'permits', 'item_id' => '90000000008', 'name' => 'Permits & Inspections', 'rate' => 0.0, 'quantity' => 1, 'source' => 'zoho_books' ),
	);
}
function proposal_legacy_template(): string {
	return '<h3>Equipment to Be Installed</h3>{{#systems}}<h4>{{system_label}}</h4><ul>{{#furnace}}<li>{{display_name}}</li>{{/furnace}}{{#has_other}}{{#other}}<li>{{display_name}}</li>{{/other}}{{/has_other}}</ul>{{/systems}}'
		. '<h3>Scope of Work</h3><p>Synthetic installation scope.</p><h3>Investment</h3><table>'
		. '<tr><td style="padding:6px 0;">Subtotal</td><td style="text-align:right;">{{pricing.subtotal_formatted}}</td></tr>'
		. '<tr><td>Project total</td><td>{{pricing.total_formatted}}</td></tr><tr><td>Deposit</td><td>{{pricing.deposit_amount_formatted}}</td></tr></table>';
}
function proposal_transaction( array $payload, array $view, string $html = '' ): array {
	$method = new ReflectionMethod( Estimate_Generator::class, 'build_transaction_payload' );
	$method->setAccessible( true );
	return $method->invoke( Estimate_Generator::instance(), $payload, proposal_customer(), $view, $html, proposal_meta(), 'synthetic-proposal-key', '60000000001' );
}

/** Build the already-validated endpoint projection, including separate offered metadata. */
function proposal_payload( int $lower = 0, int $upper = 0, bool $addons = true, float $adjustment = 0 ): array {
	$prices = array( array( 8000.0, 10000.0, 12000.0 ), array( 9000.0, 11000.0, 13000.0 ) );
	$labels = array( 'Silver', 'Gold', 'Platinum' );
	$selected = array( $lower, $upper );
	$systems = $offered = array();
	$catalog = proposal_catalog();
	$subtotal = $total = 0.0;
	foreach ( array( 'Lower System', 'Upper System' ) as $index => $label ) {
		$choices = array();
		foreach ( $labels as $choice_index => $tier ) {
			$id = (string) ( 90000000001 + $index * 3 + $choice_index );
			$choices[] = array( 'choice_id' => 'choice-' . $index . '-' . $choice_index, 'label' => $tier,
				'equipment' => array( 'package' => array( 'item_id' => $id ) ), 'sales_price' => $prices[ $index ][ $choice_index ] );
		}
		$choice = $choices[ $selected[ $index ] ];
		$systems[] = array( 'system_number' => $index + 1, 'system_label' => $label, 'equipment' => $choice['equipment'] );
		$offered[] = array( 'system_number' => $index + 1, 'system_label' => $label,
			'selected_choice_id' => $choice['choice_id'], 'choices' => $choices );
		$subtotal += $catalog[ $choice['equipment']['package']['item_id'] ]['rate'];
		$total += $choice['sales_price'];
	}
	return array(
		'template_id' => 1, 'customer' => array( 'zoho_account_id' => '20000000001' ), 'systems' => $systems,
		'_proposal_systems' => $offered, '_proposal_addons' => $addons ? proposal_addons() : array(),
		'options' => array( 'include_maintenance' => $addons, 'include_permits' => $addons, 'warranty_parts_years' => 0, 'warranty_labor_years' => 0 ),
		'pricing' => array( 'subtotal' => $subtotal, 'total' => $total + $adjustment, 'project_adjustment' => $adjustment, 'deposit_percent' => 35 ),
	);
}
function proposal_view( array $payload ): array {
	return Token_Renderer::instance()->build_payload_view( $payload, proposal_customer(), proposal_catalog(), proposal_meta() );
}
function proposal_html( array $payload, ?string $template = null ): string {
	$html = Token_Renderer::instance()->render( $template ?? proposal_legacy_template(), proposal_view( $payload ) );
	proposal_check( is_string( $html ), 'The real renderer returns HTML, not an error.' );
	return $html;
}
function proposal_money( float $amount ): string { return '$' . number_format( $amount, 2, '.', ',' ); }
function proposal_assert_private_values_absent( string $html ): void {
	foreach ( proposal_catalog() as $item ) {
		proposal_check( ! str_contains( $html, proposal_money( $item['rate'] ) ), 'Catalog unit sales rate is not a document line price.' );
		proposal_check( ! str_contains( $html, number_format( $item['purchase_cost'], 2, '.', ',' ) ), 'Internal purchase cost is absent.' );
	}
	foreach ( array( 'CHOICE-COST-SECRET', 'ITEM-COST-SECRET', 'MARKUP-SECRET', 'UNSELECTED-PRIVATE-COST' ) as $secret ) {
		proposal_check( ! str_contains( $html, $secret ), 'Private metadata is not exposed: ' . $secret );
	}
}

proposal_case( 'Offered alternatives stay separate from selected charged systems', function (): void {
	$view = proposal_view( proposal_payload() );
	proposal_same( 2, count( $view['systems'] ), 'Only two physical selected systems.' );
	proposal_same( 2, count( $view['proposal_systems'] ?? array() ), 'Two offered physical groups.' );
	proposal_check( true === ( $view['has_proposal_choices'] ?? false ), 'Alternative document flag.' );
	foreach ( $view['proposal_systems'] as $system ) {
		proposal_same( 3, count( $system['choices'] ), 'Each group has three alternatives.' );
		proposal_same( 1, count( array_filter( $system['choices'], static fn ( array $choice ): bool => true === ( $choice['is_selected'] ?? false ) ) ), 'Exactly one selected alternative per physical system.' );
	}
});

proposal_case( 'Legacy equipment section shows all six option totals once without a bottom duplicate', function (): void {
	$template = proposal_legacy_template();
	$html = proposal_html( proposal_payload(), $template );
	$equipment_start = strpos( $html, '<h3>Equipment to Be Installed</h3>' );
	$scope_start = strpos( $html, '<h3>Scope of Work</h3>' );
	proposal_check( false !== $equipment_start && false !== $scope_start, 'Existing document headings remain.' );
	$equipment = substr( $html, $equipment_start, $scope_start - $equipment_start );
	foreach ( proposal_catalog() as $item ) {
		proposal_same( 1, substr_count( $html, esc_html( $item['name'] ) ), 'Every package appears exactly once in the full proposal.' );
		proposal_check( str_contains( $equipment, esc_html( $item['name'] ) ), 'Every option belongs to the equipment section.' );
	}
	foreach ( array( 8000.0, 10000.0, 12000.0, 9000.0, 11000.0, 13000.0 ) as $price ) {
		proposal_same( 1, substr_count( $equipment, proposal_money( $price ) ), 'Each offered installed sales total appears once.' );
	}
	proposal_check( ! str_contains( $html, '<h3>Package</h3>' ), 'No bottom package compatibility duplicate.' );
	proposal_check( str_contains( $equipment, 'Lower System' ) && str_contains( $equipment, 'Upper System' ), 'Physical systems remain distinct.' );
	proposal_assert_private_values_absent( $html );
	proposal_same( proposal_legacy_template(), $template, 'Caller-authored template remains byte-identical.' );
});

foreach ( array( array( 0, 0, 17000.0 ), array( 1, 0, 19000.0 ), array( 1, 2, 23000.0 ), array( 2, 2, 25000.0 ) ) as $combination ) {
	proposal_case( 'Selected alternative total ' . $combination[2] . ' charges only two real systems', function () use ( $combination ): void {
		$payload = proposal_payload( $combination[0], $combination[1] );
		$view = proposal_view( $payload );
		$html = proposal_html( $payload );
		$transaction = proposal_transaction( $payload, $view, $html );
		$books = $transaction['books'];
		$crm = $transaction['crm'];
		$selected_ids = array_map( static fn ( array $system ): string => $system['equipment']['package']['item_id'], $payload['systems'] );
		proposal_same( $combination[2], $view['pricing']['total'], 'Project total is the sum of two chosen prices.' );
		proposal_same( $combination[2], $books['total'], 'Books target total remains the chosen amount.' );
		proposal_same( $combination[2], $crm['amount'], 'CRM amount matches selected project total.' );
		proposal_same( 4, count( $books['line_items'] ), 'Two equipment lines plus two zero-price add-ons.' );
		proposal_same( 2, count( $crm['quoted_equipment'] ), 'Add-ons and unselected alternatives never become CRM equipment.' );
		proposal_same( $selected_ids, array_column( $crm['quoted_equipment'], 'Zoho_Item_ID' ), 'Only selected item identities enter CRM.' );
		proposal_same( array_merge( $selected_ids, array( '90000000007', '90000000008' ) ), array_column( $books['line_items'], 'item_id' ), 'Only selected equipment and canonical add-on item IDs enter Books.' );
		proposal_same( array( 1, 1, 1, 1 ), array_column( $books['line_items'], 'quantity' ), 'Every line has quantity one; no alternative expansion.' );
		proposal_same( array( 0.0, 0.0 ), array_column( array_slice( $books['line_items'], -2 ), 'rate' ), 'Add-ons are zero today.' );
		proposal_same( $combination[2], array_sum( array_column( $books['line_items'], 'rate' ) ) + $books['adjustment'], 'Adjustment reconciles catalog sales lines to approved installed total once.' );
		proposal_check( 63000.0 !== $books['total'], 'All offered alternatives are not added together.' );
		proposal_check( str_contains( $html, proposal_money( $combination[2] ) ), 'Selected summary appears in customer document.' );
		proposal_assert_private_values_absent( $html );
	} );
}

proposal_case( 'Common project adjustment is counted once and add-on renewal is not charged', function (): void {
	$payload = proposal_payload( 0, 0, true, 500 );
	$view = proposal_view( $payload );
	$books = proposal_transaction( $payload, $view )['books'];
	proposal_same( 17500.0, $books['total'], 'Common adjustment applies once.' );
	proposal_same( 17500.0, array_sum( array_column( $books['line_items'], 'rate' ) ) + $books['adjustment'], 'Provider line math reconciles once.' );
	proposal_check( 17849.0 !== $books['total'], 'Future renewal is not today’s charge.' );
	$html = proposal_html( $payload );
	proposal_same( 1, substr_count( $html, '$500.00' ), 'Common adjustment is disclosed once in the customer summary.' );
	proposal_check( str_contains( $html, 'Project adjustment' ), 'Adjustment amount has an explicit customer label.' );
});

proposal_case( 'Canonical maintenance and permits have stable edit keys and exact customer terms', function (): void {
	$payload = proposal_payload();
	$view = proposal_view( $payload );
	$html = proposal_html( $payload );
	$preview = Estimate_Generator::instance()->build_books_preview( $view, proposal_customer(), proposal_meta() );
	$transaction = proposal_transaction( $payload, $view, $html );
	proposal_same( array( 'proposal_maintenance_0', 'proposal_permits_0' ), array_column( array_slice( $preview['line_items'], -2 ), 'key' ), 'Stable editor keys match proposal add-ons.' );
	proposal_same( array( 'proposal_maintenance_0', 'proposal_permits_0' ), array_column( array_slice( $transaction['books']['line_items'], -2 ), 'edit_key' ), 'Transaction add-on keys match preview.' );
	proposal_check( str_contains( $html, 'New Residential System Maintenance Agreement' ), 'Maintenance is visible.' );
	proposal_check( str_contains( $html, PROPOSAL_MAINTENANCE_TEXT ), 'Renewal wording stays explicitly one unit.' );
	foreach ( PROPOSAL_PERMIT_PARAGRAPHS as $paragraph ) { proposal_check( str_contains( $html, esc_html( $paragraph ) ), 'Exact permit policy is rendered.' ); }
	proposal_same( 2, substr_count( $html, '$0.00' ), 'Exactly two included zero-price options, no equipment line amounts.' );
});

proposal_case( 'Default Books document channels preserve exact zero-price option policies without leaking costs', function (): void {
	$payload = proposal_payload();
	$view = proposal_view( $payload );
	$html = proposal_html( $payload );
	$books = proposal_transaction( $payload, $view, $html )['books'];
	$addons = array_slice( $books['line_items'], -2 );
	proposal_check( str_contains( $addons[0]['description'], PROPOSAL_MAINTENANCE_TEXT ), 'Maintenance Books line identifies future one-unit renewal.' );
	foreach ( PROPOSAL_PERMIT_PARAGRAPHS as $paragraph ) {
		proposal_check( str_contains( $addons[1]['description'], $paragraph ), 'Permit Books line includes exact policy paragraph.' );
		proposal_check( str_contains( $books['terms'], $paragraph ), 'Default Books terms retain permit policy.' );
		proposal_check( str_contains( $books['annexure_content'], esc_html( $paragraph ) ), 'Books annexure retains permit policy.' );
	}
	proposal_check( ! str_contains( $books['annexure_content'], 'unless specifically listed' ), 'Old permit inclusion exception does not contradict the new zero-price paperwork item.' );
	foreach ( array( 'template_body', 'annexure_content', 'email_body', 'terms' ) as $key ) { proposal_assert_private_values_absent( $books[ $key ] ); }
});

proposal_case( 'Rendering a customer document does not mutate Books prices or selected mappings', function (): void {
	$payload = proposal_payload( 1, 2 );
	$view = proposal_view( $payload );
	$before = proposal_transaction( $payload, $view );
	$html = Token_Renderer::instance()->render( proposal_legacy_template(), $view );
	proposal_check( is_string( $html ), 'Customer document renders.' );
	$after = proposal_transaction( $payload, $view );
	proposal_same( $before['books']['line_items'], $after['books']['line_items'], 'Selected line rates, quantity and edit mapping are unchanged.' );
	proposal_same( $before['crm']['quoted_equipment'], $after['crm']['quoted_equipment'], 'Selected CRM equipment is unchanged.' );
	proposal_same( $before['books']['adjustment'], $after['books']['adjustment'], 'Rendering never recalculates provider adjustment.' );
});

proposal_case( 'Unchecked proposal add-ons are absent from document and charged mappings', function (): void {
	$payload = proposal_payload( 0, 0, false );
	$view = proposal_view( $payload );
	$html = proposal_html( $payload );
	$transaction = proposal_transaction( $payload, $view, $html );
	proposal_same( 2, count( $transaction['books']['line_items'] ), 'Only selected equipment remains.' );
	proposal_same( 2, count( $transaction['crm']['quoted_equipment'] ), 'Physical equipment remains unchanged.' );
	proposal_check( ! str_contains( $html, 'New Residential System Maintenance Agreement' ), 'Unchecked maintenance absent.' );
	proposal_check( ! str_contains( $html, PROPOSAL_MAINTENANCE_TEXT ), 'Unchecked future renewal absent.' );
	proposal_check( ! str_contains( $html, PROPOSAL_PERMIT_PARAGRAPHS[0] ), 'Unchecked permit note absent.' );
});

proposal_case( 'Modern alternatives template renders explicit option totals but no item financial aliases', function (): void {
	$payload = proposal_payload();
	$payload['_proposal_systems'][0]['choices'][0]['purchase_cost'] = 'CHOICE-COST-SECRET';
	$catalog = proposal_catalog();
	foreach ( $catalog as &$item ) { $item['purchase_cost'] = 'ITEM-COST-SECRET'; $item['markup'] = 'MARKUP-SECRET'; }
	unset( $item );
	$view = Token_Renderer::instance()->build_payload_view( $payload, proposal_customer(), $catalog, proposal_meta() );
	$view['proposal_systems'][0]['choices'][0]['purchase_cost'] = 'CHOICE-COST-SECRET';
	$view['proposal_systems'][0]['choices'][0]['items'][0]['purchase_cost'] = 'ITEM-COST-SECRET';
	$view['proposal_systems'][0]['choices'][0]['items'][0]['markup'] = 'MARKUP-SECRET';
	$template = '{{#proposal_systems}}<h4>{{system_label}}</h4>{{#choices}}<p>{{label}} {{sales_price_formatted}} {{purchase_cost}}</p>{{#items}}{{display_name}}{{rate}}{{rate_formatted}}{{purchase_cost}}{{markup}}{{/items}}{{/choices}}{{/proposal_systems}}';
	$html = Token_Renderer::instance()->render( $template, $view );
	proposal_check( is_string( $html ), 'Modern proposal renders.' );
	proposal_assert_private_values_absent( $html );
	foreach ( array( 8000.0, 10000.0, 12000.0, 9000.0, 11000.0, 13000.0 ) as $price ) { proposal_same( 1, substr_count( $html, proposal_money( $price ) ), 'Modern template does not gain duplicate option totals.' ); }
});

proposal_case( 'Partial and comment-only aliases never hide or duplicate offered choices and add-ons', function (): void {
	$complete_choices = '{{#proposal_systems}}{{#choices}}{{#items}}{{display_name}}{{/items}}{{sales_price_formatted}}{{/choices}}{{/proposal_systems}}';
	$complete_addons = '{{#proposal_addons.maintenance}}{{name}} {{rate_formatted}} {{description}}{{/proposal_addons.maintenance}}'
		. '{{#proposal_addons.permits}}{{name}} {{rate_formatted}} {{description}}{{/proposal_addons.permits}}';
	$partial = '{{#proposal_systems}}{{#choices}}{{label}} {{sales_price_formatted}}{{/choices}}{{/proposal_systems}}'
		. '{{#proposal_addons.maintenance}}{{name}}{{/proposal_addons.maintenance}}{{#proposal_addons.permits}}{{name}}{{/proposal_addons.permits}}';
	foreach ( array( $partial, '<!--' . $complete_choices . $complete_addons . '-->' ) as $template ) {
		$html = proposal_html( proposal_payload(), $template );
		foreach ( proposal_catalog() as $item ) { proposal_same( 1, substr_count( $html, esc_html( $item['name'] ) ), 'Unsupported/comment-only aliases fall back to each offered item exactly once.' ); }
		foreach ( array( 8000.0, 10000.0, 12000.0, 9000.0, 11000.0, 13000.0 ) as $price ) { proposal_same( 1, substr_count( $html, proposal_money( $price ) ), 'Fallback keeps every option price without duplicate partial output.' ); }
		foreach ( proposal_addons() as $addon ) { proposal_same( 1, substr_count( $html, esc_html( $addon['name'] ) ), 'Every requested add-on appears once despite incomplete/comment-only aliases.' ); }
		proposal_same( 1, substr_count( $html, PROPOSAL_MAINTENANCE_TEXT ), 'One renewal statement survives fallback.' );
		foreach ( PROPOSAL_PERMIT_PARAGRAPHS as $paragraph ) { proposal_same( 1, substr_count( $html, esc_html( $paragraph ) ), 'Each exact permit paragraph appears once.' ); }
		proposal_assert_private_values_absent( $html );
	}
});

proposal_case( 'Complete modern add-on loops do not gain duplicate compatibility sections', function (): void {
	$template = '<h3>Included options</h3>{{#proposal_addons.maintenance}}<section><h4>{{name}}</h4><p>{{rate_formatted}}</p><p>{{description}}</p></section>{{/proposal_addons.maintenance}}'
		. '{{#proposal_addons.permits}}<section><h4>{{name}}</h4><p>{{rate_formatted}}</p><p>{{description}}</p></section>{{/proposal_addons.permits}}';
	$html = proposal_html( proposal_payload(), $template );
	foreach ( proposal_addons() as $addon ) { proposal_same( 1, substr_count( $html, esc_html( $addon['name'] ) ), 'A complete authored add-on loop is authoritative exactly once.' ); }
	proposal_same( 2, substr_count( $html, '$0.00' ), 'Complete add-on loops retain exactly two zero-price rows.' );
	proposal_same( 1, substr_count( $html, PROPOSAL_MAINTENANCE_TEXT ), 'Modern maintenance has one renewal statement.' );
	foreach ( PROPOSAL_PERMIT_PARAGRAPHS as $paragraph ) { proposal_same( 1, substr_count( $html, esc_html( $paragraph ) ), 'Modern permit terms are not appended twice.' ); }
	proposal_assert_private_values_absent( $html );
});

proposal_case( 'Untrusted option and equipment labels are escaped in customer output', function (): void {
	$payload = proposal_payload();
	$payload['_proposal_systems'][0]['choices'][0]['label'] = 'Silver <script>alert(1)</script> & Choice';
	$catalog = proposal_catalog();
	$catalog['90000000001']['name'] = 'Synthetic <img src=x onerror=alert(2)> package';
	$catalog['90000000001']['short_description'] = '<script>alert(3)</script> & install';
	$view = Token_Renderer::instance()->build_payload_view( $payload, proposal_customer(), $catalog, proposal_meta() );
	$html = Token_Renderer::instance()->render( proposal_legacy_template(), $view );
	proposal_check( is_string( $html ), 'Malicious text still renders safely.' );
	proposal_check( ! str_contains( $html, '<script>' ) && ! str_contains( $html, '<img src=x' ), 'Provider and option text cannot inject active HTML.' );
	proposal_check( str_contains( $html, 'Synthetic' ) && str_contains( $html, 'Choice' ), 'Useful equipment and option labels remain visible.' );
	proposal_assert_private_values_absent( $html );
});

proposal_case( 'Unknown custom template receives all offered equipment exactly once', function (): void {
	$html = proposal_html( proposal_payload(), '<h1>Custom proposal</h1><p>{{customer.name}}</p><p>{{pricing.total_formatted}}</p>' );
	foreach ( proposal_catalog() as $item ) { proposal_same( 1, substr_count( $html, esc_html( $item['name'] ) ), 'Unsupported layout does not lose or duplicate options.' ); }
	proposal_check( str_contains( $html, 'Lower System' ) && str_contains( $html, 'Upper System' ), 'Fallback groups the two actual systems.' );
	proposal_assert_private_values_absent( $html );
});

proposal_case( 'Known legacy permit footer is replaced only on the render copy when selected', function (): void {
	$footer = 'Prices are subject to equipment availability and do not include any required permits beyond those standard for NJ residential HVAC work.';
	$template = proposal_legacy_template() . '<p>' . $footer . '</p>';
	$selected = proposal_html( proposal_payload(), $template );
	$unchecked = proposal_html( proposal_payload( 0, 0, false ), $template );
	proposal_check( ! str_contains( $selected, 'beyond those standard' ), 'Selected permit policy removes the conflicting legacy exception.' );
	proposal_check( str_contains( $selected, PROPOSAL_PERMIT_PARAGRAPHS[0] ), 'Exact exclusion policy appears.' );
	proposal_check( str_contains( $unchecked, $footer ), 'Unchecked legacy footer is preserved.' );
	proposal_same( proposal_legacy_template() . '<p>' . $footer . '</p>', $template, 'Stored/caller template is not rewritten.' );
});

proposal_case( 'Plain legacy payload still renders one selected package without alternatives', function (): void {
	$payload = proposal_payload( 0, 0, false );
	unset( $payload['_proposal_systems'] );
	$payload['systems'] = array_slice( $payload['systems'], 0, 1 );
	$html = proposal_html( $payload );
	proposal_check( str_contains( $html, 'Synthetic Lower Silver Package' ), 'Existing plain equipment selection remains visible.' );
	proposal_check( ! str_contains( $html, 'Synthetic Lower Gold Package' ), 'Unselected metadata is not invented.' );
	proposal_assert_private_values_absent( $html );
});

function proposal_known_warranty_template( string $line_ending = "\n" ): string {
	$block = '<h3>Warranty</h3>' . "\n<ul>\n  "
		. '<li>{{warranty.parts_years}}-Year Manufacturer Parts Warranty</li>' . "\n  "
		. '<li>{{warranty.labor_years}}-Year Labor Warranty (requires active Temp Control maintenance plan)</li>' . "\n</ul>";
	return '<h3>Scope of Work</h3><p>Synthetic scope.</p>' . str_replace( "\n", $line_ending, $block ) . '<h3>Investment</h3><p>{{pricing.total_formatted}}</p>';
}

foreach ( array( array( 0, 0, 'both unchecked' ), array( 10, 10, 'both checked' ), array( 10, 0, 'parts only' ), array( 0, 10, 'labor only' ) ) as $coverage ) {
	proposal_case( 'Verified legacy warranty renders ' . $coverage[2], function () use ( $coverage ): void {
		foreach ( array( "\n", "\r\n" ) as $line_ending ) {
			$payload = proposal_payload( 0, 0, false );
			$payload['options']['warranty_parts_years'] = $coverage[0];
			$payload['options']['warranty_labor_years'] = $coverage[1];
			$template = proposal_known_warranty_template( $line_ending );
			$before = $template;
			$html = proposal_html( $payload, $template );
			proposal_same( $coverage[0] + $coverage[1] > 0 ? 1 : 0, substr_count( $html, '<h3>Warranty</h3>' ), 'Warranty heading exists only when coverage is selected.' );
			proposal_same( $coverage[0] > 0 ? 1 : 0, substr_count( $html, '<li>10-Year Manufacturer Parts Warranty</li>' ), 'Parts row follows only its own checkbox.' );
			proposal_same( $coverage[1] > 0 ? 1 : 0, substr_count( $html, '<li>10-Year Labor Warranty (requires active Temp Control maintenance plan)</li>' ), 'Labor row follows only its own checkbox.' );
			proposal_check( 0 === preg_match( '/(?<![0-9])0-Year/', $html ), 'Unchecked coverage never becomes a zero-year promise.' );
			proposal_same( $before, $template, 'Caller/stored warranty template remains byte-identical.' );
		}
	});
}

proposal_case( 'Positive warranty years remain their supplied values on the verified layout', function (): void {
	$payload = proposal_payload( 0, 0, false );
	$payload['options']['warranty_parts_years'] = 7;
	$payload['options']['warranty_labor_years'] = 2;
	$html = proposal_html( $payload, proposal_known_warranty_template() );
	proposal_check( str_contains( $html, '<li>7-Year Manufacturer Parts Warranty</li>' ), 'Positive parts years are not replaced with a fixed default.' );
	proposal_check( str_contains( $html, '<li>2-Year Labor Warranty (requires active Temp Control maintenance plan)</li>' ), 'Positive labor years are not replaced with a fixed default.' );
});

proposal_case( 'Unrelated custom warranty layout is not rewritten', function (): void {
	$payload = proposal_payload( 0, 0, false );
	$template = '<h3>Custom coverage</h3><p>Owner-selected parts coverage: {{warranty.parts_years}} years.</p>';
	$html = proposal_html( $payload, $template );
	proposal_check( str_contains( $html, '<h3>Custom coverage</h3><p>Owner-selected parts coverage: 0 years.</p>' ), 'Only the verified legacy rows receive new visibility conditions.' );
	proposal_same( '<h3>Custom coverage</h3><p>Owner-selected parts coverage: {{warranty.parts_years}} years.</p>', $template, 'Authored custom layout remains unchanged.' );
});

echo sprintf( "\nProposal render smoke: %d cases passed, %d failed; %d assertions passed.\n", $proposal_passed, $proposal_failed, $proposal_assertions );
exit( $proposal_failed > 0 ? 1 : 0 );
