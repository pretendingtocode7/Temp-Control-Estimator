<?php
/**
 * Tests for the Mustache token renderer and payload-view builder.
 *
 * This is the Phase 1 success gate: prove that Option 3 tokens (loop blocks) handle
 * both a single-system Brian-Balson-style proposal and a two-system Michael-Clemente-style
 * proposal from a single template.
 *
 * Run standalone:
 *   cd temp-control-estimate-builder && composer install && ./vendor/bin/phpunit -c tests/phpunit.xml
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Tests;

use PHPUnit\Framework\TestCase;
use TempControl\Estimate\Token_Renderer;

require_once dirname( __DIR__ ) . '/includes/class-token-renderer.php';

final class TokenRendererTest extends TestCase {

	private Token_Renderer $renderer;

	protected function setUp(): void {
		$this->renderer = Token_Renderer::instance();
	}

	// ----- Rendering -----

	public function test_simple_scalar_substitution(): void {
		$result = $this->renderer->render( 'Hello {{name}}', array( 'name' => 'Jessica' ) );
		$this->assertSame( 'Hello Jessica', $result );
	}

	public function test_html_escaping_by_default(): void {
		$result = $this->renderer->render( '{{note}}', array( 'note' => '<script>alert(1)</script>' ) );
		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( '&lt;script&gt;', $result );
	}

	public function test_nested_dot_access(): void {
		$result = $this->renderer->render(
			'{{customer.name}} at {{customer.address.city}}',
			array( 'customer' => array( 'name' => 'Test Co', 'address' => array( 'city' => 'Edison' ) ) )
		);
		$this->assertSame( 'Test Co at Edison', $result );
	}

	public function test_each_loop(): void {
		$tpl = '{{#items}}- {{label}}{{/items}}';
		$result = $this->renderer->render( $tpl, array(
			'items' => array(
				array( 'label' => 'A' ),
				array( 'label' => 'B' ),
				array( 'label' => 'C' ),
			),
		) );
		$this->assertSame( '- A- B- C', $result );
	}

	public function test_conditional_block(): void {
		$tpl = '{{#has_rebates}}Rebates apply{{/has_rebates}}{{^has_rebates}}No rebates{{/has_rebates}}';
		$this->assertSame( 'Rebates apply', $this->renderer->render( $tpl, array( 'has_rebates' => true ) ) );
		$this->assertSame( 'No rebates',    $this->renderer->render( $tpl, array( 'has_rebates' => false ) ) );
	}

	// ----- Payload view shape -----

	public function test_build_payload_view_single_system(): void {
		$view = $this->renderer->build_payload_view(
			$this->balson_payload(),
			$this->balson_customer(),
			$this->catalog(),
			$this->meta()
		);

		$this->assertSame( 'Brian Balson', $view['customer']['name'] );
		$this->assertCount( 1, $view['systems'] );
		$this->assertFalse( $view['is_multi_system'] );
		$this->assertSame( 1, $view['system_count'] );

		$system = $view['systems'][0];
		$this->assertSame( 'Main System', $system['system_label'] );
		$this->assertSame( 'Coleman', $system['furnace']['brand'] );
		$this->assertSame( 96.0, $system['furnace']['afue'] );
		$this->assertSame( 3.0, $system['condenser']['tons'] );
		$this->assertSame( 'R-410A', $system['condenser']['refrigerant'] );

		$this->assertSame( 10, $view['warranty']['parts_years'] );
		$this->assertSame( '$18,300.00', $view['pricing']['total_formatted'] );
	}

	public function test_build_payload_view_multi_system(): void {
		$view = $this->renderer->build_payload_view(
			$this->clemente_payload(),
			$this->clemente_customer(),
			$this->catalog(),
			$this->meta()
		);

		$this->assertCount( 2, $view['systems'] );
		$this->assertTrue( $view['is_multi_system'] );
		$this->assertSame( 'First Floor System', $view['systems'][0]['system_label'] );
		$this->assertSame( 'Second Floor System', $view['systems'][1]['system_label'] );

		// Both systems reference different condensers — verify catalog lookup is per-slot.
		$this->assertSame( 'AC8B3024A1B', $view['systems'][0]['condenser']['model'] );
		$this->assertSame( 'AC8B3036A1B', $view['systems'][1]['condenser']['model'] );
	}

	public function test_item_description_override_is_used_without_mutating_catalog(): void {
		$payload = $this->balson_payload();
		$payload['systems'][0]['equipment']['furnace']['description'] = 'Customer-specific furnace description';
		$catalog = $this->catalog();

		$view = $this->renderer->build_payload_view( $payload, $this->balson_customer(), $catalog, $this->meta() );

		$this->assertSame( 'Customer-specific furnace description', $view['systems'][0]['furnace']['short_description'] );
		$this->assertSame( '96% AFUE single-stage gas furnace', $catalog['100000001']['short_description'] );
	}

	public function test_rebates_formatted_money(): void {
		$payload = $this->balson_payload();
		$payload['options']['rebates'] = array(
			array( 'name' => 'ETOWN 2025', 'amount' => 900.00 ),
			array( 'name' => 'Manufacturer Promo', 'amount' => 500.0 ),
		);
		$view = $this->renderer->build_payload_view( $payload, $this->balson_customer(), $this->catalog(), $this->meta() );

		$this->assertTrue( $view['has_rebates'] );
		$this->assertCount( 2, $view['rebates'] );
		$this->assertSame( '$900.00', $view['rebates'][0]['amount_formatted'] );
		$this->assertSame( '$500.00', $view['rebates'][1]['amount_formatted'] );
	}

	// ----- End-to-end: real template against real payload -----

	public function test_single_system_template_renders_completely(): void {
		$tpl = $this->load_template( 'full-replacement.mustache' );
		$view = $this->renderer->build_payload_view(
			$this->balson_payload(),
			$this->balson_customer(),
			$this->catalog(),
			$this->meta()
		);
		$rendered = $this->renderer->render( $tpl, $view );

		$this->assertIsString( $rendered );
		$this->assertStringContainsString( 'Brian Balson', $rendered );
		$this->assertStringContainsString( 'Coleman', $rendered );
		$this->assertStringContainsString( 'AC8B3036A1B', $rendered );
		$this->assertStringContainsString( '$18,300.00', $rendered );
		$this->assertStringContainsString( '10-Year', $rendered );
		// Should NOT contain the "Second Floor" language — only one system present.
		$this->assertStringNotContainsString( 'Second Floor', $rendered );
	}

	public function test_two_system_template_renders_both_systems(): void {
		$tpl = $this->load_template( 'full-replacement.mustache' );
		$view = $this->renderer->build_payload_view(
			$this->clemente_payload(),
			$this->clemente_customer(),
			$this->catalog(),
			$this->meta()
		);
		$rendered = $this->renderer->render( $tpl, $view );

		$this->assertStringContainsString( 'First Floor System', $rendered );
		$this->assertStringContainsString( 'Second Floor System', $rendered );
		$this->assertStringContainsString( 'Michael Clemente', $rendered );
		// Both condenser model numbers should appear.
		$this->assertStringContainsString( 'AC8B3024A1B', $rendered );
		$this->assertStringContainsString( 'AC8B3036A1B', $rendered );
	}

	public function test_unknown_tokens_render_as_empty_string(): void {
		$result = $this->renderer->render( 'Before [{{missing_field}}] After', array() );
		$this->assertSame( 'Before [] After', $result );
	}

	// ----- Fixtures -----

	private function balson_customer(): array {
		return array(
			'name'  => 'Brian Balson',
			'phone' => '(732) 555-0101',
			'email' => 'brian@example.com',
			'billing_address' => array(
				'street' => '123 Oak Avenue',
				'city'   => 'Edison',
				'state'  => 'NJ',
				'zip'    => '08817',
				'country' => 'USA',
			),
		);
	}

	private function balson_payload(): array {
		return array(
			'template_id' => 1,
			'systems' => array(
				array(
					'system_number' => 1,
					'system_label'  => 'Main System',
					'equipment' => array(
						'furnace'   => array( 'item_id' => '100000001', 'notes' => '' ),
						'condenser' => array( 'item_id' => '100000004', 'notes' => '' ),
						'coil'      => array( 'item_id' => '100000006', 'notes' => '' ),
					),
				),
			),
			'options' => array(
				'warranty_parts_years' => 10,
				'warranty_labor_years' => 10,
				'special_notes' => 'Line chimney with 4" liner.',
			),
			'pricing' => array(
				'subtotal' => 18300.00,
				'total' => 18300.00,
				'deposit_percent' => 35,
			),
		);
	}

	private function clemente_customer(): array {
		return array(
			'name'  => 'Michael Clemente',
			'phone' => '',
			'email' => '',
			'billing_address' => array(
				'street' => '456 Maple Street',
				'city'   => 'Metuchen',
				'state'  => 'NJ',
				'zip'    => '08840',
				'country' => 'USA',
			),
		);
	}

	private function clemente_payload(): array {
		return array(
			'template_id' => 1,
			'systems' => array(
				array(
					'system_number' => 1,
					'system_label'  => 'First Floor System',
					'equipment' => array(
						'furnace'   => array( 'item_id' => '100000001', 'notes' => '' ),
						'condenser' => array( 'item_id' => '100000003', 'notes' => '' ), // 2-ton
						'coil'      => array( 'item_id' => '100000006', 'notes' => '' ),
					),
				),
				array(
					'system_number' => 2,
					'system_label'  => 'Second Floor System',
					'equipment' => array(
						'furnace'   => array( 'item_id' => '100000002', 'notes' => '' ), // 100k BTU
						'condenser' => array( 'item_id' => '100000004', 'notes' => '' ), // 3-ton
						'coil'      => array( 'item_id' => '100000006', 'notes' => '' ),
					),
				),
			),
			'options' => array(
				'warranty_parts_years' => 10,
				'warranty_labor_years' => 10,
			),
			'pricing' => array(
				'subtotal' => 34500.00,
				'total'    => 34500.00,
			),
		);
	}

	private function catalog(): array {
		return array(
			'100000001' => array(
				'item_id' => '100000001', 'name' => 'Coleman TG9S080',
				'model' => 'TG9S080C16MP11A', 'brand' => 'Coleman',
				'equipment_type' => 'furnace', 'afue' => 96.0, 'btu_input' => 80000, 'btu_output' => 76800,
				'stages' => 'Single', 'short_description' => '96% AFUE single-stage gas furnace',
			),
			'100000002' => array(
				'item_id' => '100000002', 'name' => 'Coleman TG9S100',
				'model' => 'TG9S100C20MP11A', 'brand' => 'Coleman',
				'equipment_type' => 'furnace', 'afue' => 96.0, 'btu_input' => 100000, 'btu_output' => 96000,
				'stages' => 'Single', 'short_description' => '96% AFUE single-stage gas furnace',
			),
			'100000003' => array(
				'item_id' => '100000003', 'name' => 'Coleman 2-ton',
				'model' => 'AC8B3024A1B', 'brand' => 'Coleman',
				'equipment_type' => 'condenser', 'seer' => 13.4, 'tons' => 2.0, 'refrigerant' => 'R-410A',
				'stages' => 'Single', 'short_description' => '13.4 SEER2 condenser, 2 ton',
			),
			'100000004' => array(
				'item_id' => '100000004', 'name' => 'Coleman 3-ton',
				'model' => 'AC8B3036A1B', 'brand' => 'Coleman',
				'equipment_type' => 'condenser', 'seer' => 13.4, 'tons' => 3.0, 'refrigerant' => 'R-410A',
				'stages' => 'Single', 'short_description' => '13.4 SEER2 condenser, 3 ton',
			),
			'100000006' => array(
				'item_id' => '100000006', 'name' => 'Coleman Coil',
				'model' => 'CF36CN4B', 'brand' => 'Coleman',
				'equipment_type' => 'evaporator_coil', 'tons' => 3.0, 'refrigerant' => 'R-410A',
				'short_description' => 'Cased evaporator coil',
			),
		);
	}

	private function meta(): array {
		return array(
			'id' => 1, 'name' => 'Full Replacement — Coleman High Efficiency',
			'template_type' => 'full_replacement',
			'default_warranty_parts' => 10, 'default_warranty_labor' => 10,
			'version' => 1,
		);
	}

	private function load_template( string $name ): string {
		$path = dirname( __DIR__ ) . '/seed-templates/' . $name;
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}
}
