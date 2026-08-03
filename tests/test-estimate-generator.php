<?php
/**
 * Estimate_Generator payload-shaping tests.
 *
 * Focuses on build_deluge_payload() — the pure transformation from the validated request
 * shape into the JSON the Deluge function consumes. No Zoho calls are made; we reflect
 * into the private method so the shaping logic is verified independent of the network.
 *
 * Run via: `vendor/bin/phpunit tests/test-estimate-generator.php`
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use TempControl\Estimate\Estimate_Generator;

final class Test_Estimate_Generator extends TestCase {

	private Estimate_Generator $generator;

	protected function setUp(): void {
		parent::setUp();
		$this->generator = Estimate_Generator::instance();
		// Stub the options our generator reads.
		if ( ! defined( 'TC_ESTIMATE_VERSION' ) ) {
			define( 'TC_ESTIMATE_VERSION', '0.2.0' );
		}
		update_option( 'tc_estimate_zoho_org_id', '60000000000' );
		update_option( 'tc_estimate_zoho_dc', 'com' );
	}

	/**
	 * Reflectively invoke build_deluge_payload.
	 */
	private function build( array $payload, array $customer, array $view, string $rendered, array $template, string $idem ): array {
		$ref = new ReflectionClass( Estimate_Generator::class );
		$m   = $ref->getMethod( 'build_deluge_payload' );
		$m->setAccessible( true );
		return $m->invoke( $this->generator, $payload, $customer, $view, $rendered, $template, $idem, '60000000000' );
	}

	private function sample_view(): array {
		return array(
			'systems' => array(
				array(
					'system_number' => 1,
					'system_label'  => 'Main System',
					'furnace' => array(
						'item_id' => '100000001',
						'name'    => 'Coleman TG9S',
						'brand'   => 'Coleman',
						'model'   => 'TG9S080B12MP11',
						'rate'    => 3200,
						'short_description' => '80k BTU single-stage gas furnace',
					),
					'condenser' => array(
						'item_id' => '100000002',
						'name'    => 'Coleman CC7',
						'brand'   => 'Coleman',
						'model'   => 'CC7B3036A1',
						'rate'    => 4100,
						'short_description' => '3-ton 14 SEER2 condenser',
					),
					'other' => array(),
				),
			),
			'pricing' => array(
				'subtotal'        => 7300,
				'total'           => 9200,
				'deposit_percent' => 35,
			),
			'system_count'   => 1,
			'special_notes'  => '',
			'has_financing'  => false,
			'financing'      => array( 'term_months' => 0 ),
		);
	}

	private function sample_customer(): array {
		return array(
			'id'   => '20000000000',
			'name' => 'Balson Residence',
			'billing_address' => array(
				'street'  => '12 Maple St',
				'city'    => 'Edison',
				'state'   => 'NJ',
				'zip'     => '08817',
				'country' => 'U.S.A.',
			),
		);
	}

	private function sample_template(): array {
		return array(
			'id'            => 42,
			'name'          => 'Full Replacement — Coleman',
			'template_type' => 'full_replacement',
			'version'       => 3,
		);
	}

	public function test_payload_has_three_sections(): void {
		$out = $this->build( array(), $this->sample_customer(), $this->sample_view(), '<p>body</p>', $this->sample_template(), 'tc-abc-123' );
		$this->assertArrayHasKey( 'meta', $out );
		$this->assertArrayHasKey( 'books', $out );
		$this->assertArrayHasKey( 'crm', $out );
	}

	public function test_meta_carries_idempotency_and_template_version(): void {
		$out = $this->build( array(), $this->sample_customer(), $this->sample_view(), '<p>body</p>', $this->sample_template(), 'tc-key-99' );
		$this->assertSame( 'tc-key-99', $out['meta']['idempotency_key'] );
		$this->assertSame( 42, $out['meta']['template_id'] );
		$this->assertSame( 3, $out['meta']['template_version'] );
		$this->assertSame( '0.2.0', $out['meta']['plugin_version'] );
	}

	public function test_books_line_items_include_every_slot(): void {
		$out = $this->build( array(), $this->sample_customer(), $this->sample_view(), '<p>body</p>', $this->sample_template(), 'tc-x' );
		$items = $out['books']['line_items'];
		$this->assertCount( 2, $items );
		$this->assertSame( '100000001', $items[0]['item_id'] );
		$this->assertSame( 'furnace', $items[0]['slot'] );
		$this->assertSame( 1, $items[0]['system_num'] );
		$this->assertSame( 'condenser', $items[1]['slot'] );
	}

	public function test_crm_quoted_equipment_mirrors_slots(): void {
		$out = $this->build( array(), $this->sample_customer(), $this->sample_view(), '<p>body</p>', $this->sample_template(), 'tc-x' );
		$qe = $out['crm']['quoted_equipment'];
		$this->assertCount( 2, $qe );
		$this->assertSame( 'furnace', $qe[0]['Slot'] );
		$this->assertSame( 'Coleman', $qe[0]['Brand'] );
		$this->assertSame( 'TG9S080B12MP11', $qe[0]['Model'] );
		$this->assertSame( '100000001', $qe[0]['Zoho_Item_ID'] );
		$this->assertSame( 1, $qe[0]['System_Number'] );
	}

	public function test_deal_name_is_truncated_to_100_chars(): void {
		$customer = $this->sample_customer();
		$customer['name'] = str_repeat( 'A very long customer name ', 10 );
		$template = $this->sample_template();
		$template['name'] = 'And a template name ' . str_repeat( 'X', 50 );
		$out = $this->build( array(), $customer, $this->sample_view(), '<p>body</p>', $template, 'tc-x' );
		$this->assertLessThanOrEqual( 100, mb_strlen( $out['crm']['deal_name'] ) );
	}

	public function test_multi_system_produces_numbered_rows(): void {
		$view = $this->sample_view();
		$view['systems'][] = array(
			'system_number' => 2,
			'system_label'  => 'Second Floor System',
			'furnace' => array(
				'item_id' => '100000003',
				'brand'   => 'Coleman',
				'model'   => 'TG8S060',
				'rate'    => 2800,
				'short_description' => '60k BTU',
			),
			'other' => array(),
		);
		$view['system_count'] = 2;
		$out = $this->build( array(), $this->sample_customer(), $view, '<p>body</p>', $this->sample_template(), 'tc-x' );

		$qe = $out['crm']['quoted_equipment'];
		$this->assertCount( 3, $qe );
		$this->assertSame( 1, $qe[0]['System_Number'] );
		$this->assertSame( 1, $qe[1]['System_Number'] );
		$this->assertSame( 2, $qe[2]['System_Number'] );
	}

	public function test_notes_strip_html(): void {
		$html = '<h1>Proposal</h1><p>This is <strong>bold</strong>.</p><script>alert(1)</script>';
		$out = $this->build( array(), $this->sample_customer(), $this->sample_view(), $html, $this->sample_template(), 'tc-x' );
		$this->assertStringNotContainsString( '<', $out['books']['notes'] );
		$this->assertStringNotContainsString( 'alert', $out['books']['notes'] );
		$this->assertStringContainsString( 'Proposal', $out['books']['notes'] );
		$this->assertStringContainsString( 'bold', $out['books']['notes'] );
	}

	public function test_billing_address_maps_correctly(): void {
		$out = $this->build( array(), $this->sample_customer(), $this->sample_view(), '<p>body</p>', $this->sample_template(), 'tc-x' );
		$this->assertSame( '12 Maple St', $out['books']['billing_address']['address'] );
		$this->assertSame( 'Edison', $out['books']['billing_address']['city'] );
		$this->assertSame( 'NJ', $out['books']['billing_address']['state'] );
		$this->assertSame( '08817', $out['books']['billing_address']['zip'] );
	}

	public function test_financing_flags_propagate(): void {
		$view = $this->sample_view();
		$view['has_financing'] = true;
		$view['financing'] = array( 'term_months' => 60 );
		$out = $this->build( array(), $this->sample_customer(), $view, '<p>body</p>', $this->sample_template(), 'tc-x' );
		$this->assertTrue( $out['crm']['financing_requested'] );
		$this->assertSame( 60, $out['crm']['financing_term'] );
	}

	public function test_totals_match_view(): void {
		$out = $this->build( array(), $this->sample_customer(), $this->sample_view(), '<p>body</p>', $this->sample_template(), 'tc-x' );
		$this->assertSame( 7300.0, $out['books']['subtotal'] );
		$this->assertSame( 9200.0, $out['books']['total'] );
		$this->assertSame( 9200.0, $out['crm']['amount'] );
	}
}
