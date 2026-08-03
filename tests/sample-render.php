<?php
/**
 * Generate two sample proposals from the Phase 1 plan's real cases.
 * Produces samples/balson-full-replacement.html and samples/clemente-two-system.html.
 * Run: php tests/sample-render.php
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'TC_ESTIMATE_VERSION', '0.1.0-sample' );

require_once __DIR__ . '/mustache-shim.php';
require_once dirname( __DIR__ ) . '/includes/class-token-renderer.php';

if ( ! function_exists( 'date_i18n' ) ) {
	function date_i18n( $format, $timestamp = null ) {
		return date( $format, $timestamp ?? time() );
	}
}

$renderer = \TempControl\Estimate\Token_Renderer::instance();

$catalog = array(
	'100000001' => array( 'item_id' => '100000001', 'model' => 'TG9S080C16MP11A', 'brand' => 'Coleman', 'equipment_type' => 'furnace', 'afue' => 96.0, 'btu_output' => 76800, 'stages' => 'Single', 'short_description' => '96% AFUE single-stage gas furnace' ),
	'100000002' => array( 'item_id' => '100000002', 'model' => 'TG9S100C20MP11A', 'brand' => 'Coleman', 'equipment_type' => 'furnace', 'afue' => 96.0, 'btu_output' => 96000, 'stages' => 'Single', 'short_description' => '96% AFUE single-stage gas furnace' ),
	'100000003' => array( 'item_id' => '100000003', 'model' => 'AC8B3024A1B', 'brand' => 'Coleman', 'equipment_type' => 'condenser', 'seer' => 13.4, 'tons' => 2.0, 'refrigerant' => 'R-410A', 'stages' => 'Single', 'short_description' => '13.4 SEER2 condenser, 2 ton' ),
	'100000004' => array( 'item_id' => '100000004', 'model' => 'AC8B3036A1B', 'brand' => 'Coleman', 'equipment_type' => 'condenser', 'seer' => 13.4, 'tons' => 3.0, 'refrigerant' => 'R-410A', 'stages' => 'Single', 'short_description' => '13.4 SEER2 condenser, 3 ton' ),
	'100000006' => array( 'item_id' => '100000006', 'model' => 'CF36CN4B', 'brand' => 'Coleman', 'equipment_type' => 'evaporator_coil', 'tons' => 3.0, 'short_description' => 'Cased evaporator coil' ),
);

$meta = array(
	'id' => 1, 'name' => 'Full Replacement — Coleman High Efficiency',
	'template_type' => 'full_replacement',
	'default_warranty_parts' => 10, 'default_warranty_labor' => 10, 'version' => 1,
);
$template_body = (string) file_get_contents( dirname( __DIR__ ) . '/seed-templates/full-replacement.mustache' );

// Embed the logo as a data URL so the standalone sample HTMLs render correctly
// no matter where they're opened. In production the template uses {{branding.logo_url}}
// which resolves to TC_ESTIMATE_PLUGIN_URL . 'assets/logo.png'.
$logo_path = dirname( __DIR__ ) . '/assets/logo.jpg';
$logo_data_url = 'data:image/jpeg;base64,' . base64_encode( (string) file_get_contents( $logo_path ) );
$branding_override = array( 'logo_url' => $logo_data_url );

$out_dir = dirname( __DIR__ ) . '/samples';
if ( ! is_dir( $out_dir ) ) {
	mkdir( $out_dir, 0755, true );
}

// --- Balson: single system ---
$balson_customer = array(
	'name' => 'Brian Balson', 'phone' => '(732) 555-0101', 'email' => 'brian@example.com',
	'billing_address' => array( 'street' => '123 Oak Avenue', 'city' => 'Edison', 'state' => 'NJ', 'zip' => '08817' ),
);
$balson_payload = array(
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
	'options' => array(
		'warranty_parts_years' => 10, 'warranty_labor_years' => 10,
		'special_notes' => 'Line chimney with 4" stainless steel liner. Existing thermostat wiring compatible — no new runs required.',
		'rebates' => array( array( 'name' => 'ETOWN 2025 Instant Rebate', 'amount' => 900.00 ) ),
		'financing_requested' => true, 'financing_term_months' => 60,
	),
	'pricing' => array( 'subtotal' => 18300.00, 'total' => 18300.00, 'deposit_percent' => 35 ),
);
$balson_view = $renderer->build_payload_view( $balson_payload, $balson_customer, $catalog, $meta, $branding_override );
$balson_html = $renderer->render( $template_body, $balson_view );
file_put_contents( "$out_dir/balson-full-replacement.html", wrap_html( $balson_html, 'Balson — Full Replacement (single system)' ) );

// --- Clemente: two systems ---
$clemente_customer = array(
	'name' => 'Michael Clemente', 'phone' => '', 'email' => '',
	'billing_address' => array( 'street' => '456 Maple Street', 'city' => 'Metuchen', 'state' => 'NJ', 'zip' => '08840' ),
);
$clemente_payload = array(
	'template_id' => 1,
	'systems' => array(
		array( 'system_number' => 1, 'system_label' => 'First Floor System',
			'equipment' => array(
				'furnace'   => array( 'item_id' => '100000001' ),
				'condenser' => array( 'item_id' => '100000003' ),
				'coil'      => array( 'item_id' => '100000006' ),
			),
		),
		array( 'system_number' => 2, 'system_label' => 'Second Floor System',
			'equipment' => array(
				'furnace'   => array( 'item_id' => '100000002' ),
				'condenser' => array( 'item_id' => '100000004' ),
				'coil'      => array( 'item_id' => '100000006' ),
			),
		),
	),
	'options' => array( 'warranty_parts_years' => 10, 'warranty_labor_years' => 10 ),
	'pricing' => array( 'subtotal' => 34500.00, 'total' => 34500.00, 'deposit_percent' => 35 ),
);
$clemente_view = $renderer->build_payload_view( $clemente_payload, $clemente_customer, $catalog, $meta, $branding_override );
$clemente_html = $renderer->render( $template_body, $clemente_view );
file_put_contents( "$out_dir/clemente-two-system.html", wrap_html( $clemente_html, 'Clemente — Full Replacement (two systems)' ) );

echo "Wrote:\n";
echo "  samples/balson-full-replacement.html (" . strlen( $balson_html ) . " bytes)\n";
echo "  samples/clemente-two-system.html (" . strlen( $clemente_html ) . " bytes)\n";

function wrap_html( string $body, string $title ): string {
	$esc = htmlspecialchars( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>{$esc}</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; max-width: 760px; margin: 40px auto; padding: 0 24px; color: #1d2327; line-height: 1.55; }
  h2, h3, h4 { color: #1d2327; }
  hr { border: none; border-top: 1px solid #dcdcde; margin: 20px 0; }
  ul { padding-left: 24px; }
  li { margin-bottom: 4px; }
</style>
</head>
<body>
{$body}
</body>
</html>
HTML;
}
