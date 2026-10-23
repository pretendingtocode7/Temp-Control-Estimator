<?php
/**
 * Estimate_Generator transaction-shaping tests for the saved v0.4.8 implementation.
 *
 * Exercises the pure Books/CRM transformation and its editable Books preview.
 * These tests never call an API adapter or send a customer email.
 *
 * Run via: `vendor/bin/phpunit -c tests/phpunit.xml --filter EstimateGeneratorTest`
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use TempControl\Estimate\Estimate_Generator;

require_once dirname( __DIR__ ) . '/includes/class-estimate-generator.php';

final class EstimateGeneratorTest extends TestCase {

	private Estimate_Generator $generator;

	protected function setUp(): void {
		parent::setUp();
		$this->generator = Estimate_Generator::instance();
	}

	/**
	 * Invoke the pure transaction builder without creating a Books draft or CRM Deal.
	 */
	private function build( array $payload, array $customer, array $view, string $rendered, array $template, string $idem ): array {
		$method = new ReflectionMethod( Estimate_Generator::class, 'build_transaction_payload' );
		$method->setAccessible( true );
		return $method->invoke( $this->generator, $payload, $customer, $view, $rendered, $template, $idem, '60000000000' );
	}

	private function sample_view(): array {
		return array(
			'systems' => array(
				array(
					'system_number' => 1,
					'system_label'  => 'Main System',
					'furnace' => array(
						'item_id' => '100000001',
						'name'    => 'Fixture Furnace',
						'brand'   => 'Fixture Brand',
						'model'   => 'FURNACE-80',
						'rate'    => 3200,
						'short_description' => '80k BTU single-stage gas furnace',
					),
					'condenser' => array(
						'item_id' => '100000002',
						'name'    => 'Fixture Condenser',
						'brand'   => 'Fixture Brand',
						'model'   => 'CONDENSER-36',
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
			'id'               => '20000000000',
			'books_contact_id' => '30000000000',
			'name'             => 'Fixture Residence',
			'email'            => 'fixture@example.test',
			'billing_address' => array(
				'street'  => '7 Example St',
				'city'    => 'Example City',
				'state'   => 'NJ',
				'zip'     => '08817',
				'country' => 'U.S.A.',
			),
		);
	}

	private function sample_template(): array {
		return array(
			'id'            => 42,
			'name'          => 'Full Replacement — Fixture',
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

	public function test_meta_carries_idempotency_template_and_current_plugin_version(): void {
		$out = $this->build( array(), $this->sample_customer(), $this->sample_view(), '<p>body</p>', $this->sample_template(), 'tc-key-99' );
		$this->assertSame( 'tc-key-99', $out['meta']['idempotency_key'] );
		$this->assertSame( 42, $out['meta']['template_id'] );
		$this->assertSame( 3, $out['meta']['template_version'] );
		$this->assertSame( TC_ESTIMATE_VERSION, $out['meta']['plugin_version'] );
	}

	public function test_books_customer_and_crm_account_ids_remain_distinct(): void {
		$out = $this->build( array(), $this->sample_customer(), $this->sample_view(), '<p>body</p>', $this->sample_template(), 'tc-x' );
		$this->assertSame( '60000000000', $out['books']['organization_id'] );
		$this->assertSame( '30000000000', $out['books']['customer_id'] );
		$this->assertSame( '20000000000', $out['crm']['account_id'] );
		$this->assertSame( 'fixture@example.test', $out['books']['customer_email'] );
	}

	public function test_books_line_items_include_every_slot_at_its_catalog_rate(): void {
		$out = $this->build( array(), $this->sample_customer(), $this->sample_view(), '<p>body</p>', $this->sample_template(), 'tc-x' );
		$items = $out['books']['line_items'];
		$this->assertCount( 2, $items );
		$this->assertSame( '100000001', $items[0]['item_id'] );
		$this->assertSame( 'furnace', $items[0]['slot'] );
		$this->assertSame( 1, $items[0]['system_num'] );
		$this->assertSame( '80k BTU single-stage gas furnace', $items[0]['description'] );
		$this->assertSame( 3200.0, $items[0]['rate'] );
		$this->assertSame( 1, $items[0]['quantity'] );
		$this->assertSame( '100000002', $items[1]['item_id'] );
		$this->assertSame( 'condenser', $items[1]['slot'] );
		$this->assertSame( 4100.0, $items[1]['rate'] );
	}

	public function test_crm_quoted_equipment_mirrors_slots(): void {
		$out = $this->build( array(), $this->sample_customer(), $this->sample_view(), '<p>body</p>', $this->sample_template(), 'tc-x' );
		$equipment = $out['crm']['quoted_equipment'];
		$this->assertCount( 2, $equipment );
		$this->assertSame( 'furnace', $equipment[0]['Slot'] );
		$this->assertSame( 'Fixture Brand', $equipment[0]['Brand'] );
		$this->assertSame( 'FURNACE-80', $equipment[0]['Model'] );
		$this->assertSame( '100000001', $equipment[0]['Zoho_Item_ID'] );
		$this->assertSame( 1, $equipment[0]['System_Number'] );
		$this->assertSame( 'Main System', $equipment[0]['System_Label'] );
		$this->assertSame( 3200.0, $equipment[0]['Rate'] );
	}

	public function test_deal_name_is_truncated_to_100_characters_without_breaking_unicode(): void {
		$customer = $this->sample_customer();
		$customer['name'] = str_repeat( 'Résidence ', 20 );
		$out = $this->build( array(), $customer, $this->sample_view(), '<p>body</p>', $this->sample_template(), 'tc-x' );
		$this->assertSame( 100, mb_strlen( $out['crm']['deal_name'] ) );
		$this->assertStringStartsWith( 'Résidence ', $out['crm']['deal_name'] );
		$this->assertTrue( mb_check_encoding( $out['crm']['deal_name'], 'UTF-8' ) );
	}

	public function test_multi_system_produces_numbered_books_and_crm_rows(): void {
		$view = $this->sample_view();
		$view['systems'][] = array(
			'system_number' => 2,
			'system_label'  => 'Second Floor System',
			'furnace' => array(
				'item_id' => '100000003',
				'brand'   => 'Fixture Brand',
				'model'   => 'FURNACE-60',
				'rate'    => 2800,
				'short_description' => '60k BTU',
			),
			'other' => array(),
		);
		$view['system_count'] = 2;
		$out = $this->build( array(), $this->sample_customer(), $view, '<p>body</p>', $this->sample_template(), 'tc-x' );
		$this->assertCount( 3, $out['crm']['quoted_equipment'] );
		$this->assertSame( array( 1, 1, 2 ), array_column( $out['crm']['quoted_equipment'], 'System_Number' ) );
		$this->assertSame( array( 1, 1, 2 ), array_column( $out['books']['line_items'], 'system_num' ) );
		$this->assertSame( 'Second Floor System', $out['crm']['quoted_equipment'][2]['System_Label'] );
	}

	public function test_default_notes_do_not_embed_the_rendered_proposal(): void {
		$html = '<h1>Proposal</h1><p>Customer scope</p><script>alert(1)</script>';
		$out = $this->build( array(), $this->sample_customer(), $this->sample_view(), $html, $this->sample_template(), 'tc-x' );
		$this->assertStringContainsString( 'approve through Zoho Books', $out['books']['notes'] );
		$this->assertStringNotContainsString( '<', $out['books']['notes'] );
		$this->assertStringNotContainsString( 'alert', $out['books']['notes'] );
		$this->assertStringNotContainsString( 'Customer scope', $out['books']['notes'] );
		$this->assertSame( $html, $out['books']['template_body'], 'The separately rendered proposal is preserved.' );
	}

	public function test_billing_address_maps_correctly(): void {
		$out = $this->build( array(), $this->sample_customer(), $this->sample_view(), '<p>body</p>', $this->sample_template(), 'tc-x' );
		$this->assertSame( array(
			'address' => '7 Example St',
			'city'    => 'Example City',
			'state'   => 'NJ',
			'zip'     => '08817',
			'country' => 'U.S.A.',
		), $out['books']['billing_address'] );
	}

	public function test_financing_flags_propagate(): void {
		$view = $this->sample_view();
		$view['has_financing'] = true;
		$view['financing'] = array( 'term_months' => 60 );
		$out = $this->build( array(), $this->sample_customer(), $view, '<p>body</p>', $this->sample_template(), 'tc-x' );
		$this->assertTrue( $out['crm']['financing_requested'] );
		$this->assertSame( 60, $out['crm']['financing_term'] );
	}

	public function test_totals_reconcile_catalog_lines_with_project_price_adjustment(): void {
		$out = $this->build( array(), $this->sample_customer(), $this->sample_view(), '<p>body</p>', $this->sample_template(), 'tc-x' );
		$this->assertSame( 7300.0, $out['books']['subtotal'] );
		$this->assertSame( 9200.0, $out['books']['total'] );
		$this->assertSame( 9200.0, $out['crm']['amount'] );
		$this->assertSame( 1900.0, $out['books']['adjustment'] );
		$this->assertSame( 'Project price adjustment', $out['books']['adjustment_description'] );
		$this->assertSame( $out['books']['total'], array_sum( array_column( $out['books']['line_items'], 'rate' ) ) + $out['books']['adjustment'] );
	}

	public function test_discount_and_zero_adjustment_do_not_rewrite_catalog_prices(): void {
		$view = $this->sample_view();
		$view['pricing']['total'] = 7000;
		$out = $this->build( array(), $this->sample_customer(), $view, '<p>body</p>', $this->sample_template(), 'tc-x' );
		$this->assertSame( -300.0, $out['books']['adjustment'] );
		$this->assertSame( array( 3200.0, 4100.0 ), array_column( $out['books']['line_items'], 'rate' ) );
		$view['pricing']['total'] = 7300;
		$out = $this->build( array(), $this->sample_customer(), $view, '<p>body</p>', $this->sample_template(), 'tc-x' );
		$this->assertSame( 0.0, $out['books']['adjustment'] );
		$this->assertSame( '', $out['books']['adjustment_description'] );
	}

	public function test_permit_and_other_slots_have_stable_distinct_edit_keys(): void {
		$view = $this->sample_view();
		$view['systems'][0]['permit'] = array( 'item_id' => '100000004', 'name' => 'Municipal permit', 'rate' => 315 );
		$view['systems'][0]['other'] = array(
			array( 'slot' => 'accessory', 'item_id' => '100000005', 'name' => 'Accessory one', 'rate' => 50 ),
			array( 'slot' => 'accessory', 'item_id' => '100000006', 'name' => 'Accessory two', 'rate' => 75 ),
		);
		$out = $this->build( array(), $this->sample_customer(), $view, '<p>body</p>', $this->sample_template(), 'tc-x' );
		$keys = array_column( $out['books']['line_items'], 'edit_key' );
		$this->assertCount( 5, $out['books']['line_items'] );
		$this->assertSame( 'permit', $out['books']['line_items'][2]['slot'] );
		$this->assertSame( 315.0, $out['books']['line_items'][2]['rate'] );
		$this->assertSame( '100000004', $out['crm']['quoted_equipment'][2]['Zoho_Item_ID'] );
		$this->assertCount( 5, array_unique( $keys ) );
		$this->assertSame( array( 'system_1_permit_0', 'system_1_accessory_0', 'system_1_accessory_1' ), array_slice( $keys, 2 ) );
		$this->assertSame( $keys, array_column( $this->generator->build_books_preview( $view, $this->sample_customer(), $this->sample_template() )['line_items'], 'key' ) );
	}

	public function test_edits_change_only_matching_description_notes_and_terms(): void {
		$payload = array( 'books_edits' => array(
			'line_items' => array(
				array( 'key' => 'system_1_furnace_0', 'description' => 'Customer-approved furnace scope', 'rate' => 1, 'quantity' => 99, 'item_id' => 'wrong-item' ),
				array( 'key' => 'system_99_furnace_0', 'description' => 'Unrelated system override' ),
			),
			'notes' => 'Edited customer notes',
			'terms' => 'Edited customer terms',
		) );
		$out = $this->build( $payload, $this->sample_customer(), $this->sample_view(), '<p>body</p>', $this->sample_template(), 'tc-x' );
		$this->assertSame( 'Customer-approved furnace scope', $out['books']['line_items'][0]['description'] );
		$this->assertSame( '3-ton 14 SEER2 condenser', $out['books']['line_items'][1]['description'] );
		$this->assertSame( '100000001', $out['books']['line_items'][0]['item_id'] );
		$this->assertSame( 3200.0, $out['books']['line_items'][0]['rate'] );
		$this->assertSame( 1, $out['books']['line_items'][0]['quantity'] );
		$this->assertSame( 'Edited customer notes', $out['books']['notes'] );
		$this->assertSame( 'Edited customer terms', $out['books']['terms'] );
		$this->assertSame( 1900.0, $out['books']['adjustment'] );
	}

	public function test_empty_text_edits_are_kept_instead_of_restoring_defaults(): void {
		$payload = array( 'books_edits' => array(
			'line_items' => array( array( 'key' => 'system_1_furnace_0', 'description' => '' ) ),
			'notes' => '',
			'terms' => '',
		) );
		$out = $this->build( $payload, $this->sample_customer(), $this->sample_view(), '<p>body</p>', $this->sample_template(), 'tc-x' );
		$this->assertSame( '', $out['books']['line_items'][0]['description'] );
		$this->assertSame( '', $out['books']['notes'] );
		$this->assertSame( '', $out['books']['terms'] );
	}

	public function test_selected_proposal_notes_appear_in_default_books_terms(): void {
		$view = $this->sample_view();
		$view['proposal_notes'] = array(
			array( 'text' => 'Includes a 10-year manufacturer parts warranty.' ),
			array( 'text' => 'An additional processing fee applies to credit card payments.' ),
		);
		$out = $this->build( array(), $this->sample_customer(), $view, '<p>body</p>', $this->sample_template(), 'tc-x' );
		$this->assertStringContainsString( 'valid for 30 days', $out['books']['terms'] );
		$this->assertStringContainsString( $view['proposal_notes'][0]['text'], $out['books']['terms'] );
		$this->assertStringContainsString( $view['proposal_notes'][1]['text'], $out['books']['terms'] );
	}
}
