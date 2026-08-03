<?php
/**
 * Estimate generator — builds Zoho payloads, invokes the generateEstimate Deluge function,
 * and interprets its response.
 *
 * The Deluge function creates a Books estimate and a CRM Deal in one transactional block.
 * If the CRM Deal insert fails after the Books estimate was created, the Deluge function
 * voids the estimate before returning an error. That rollback logic lives on the Zoho side
 * because it needs an authenticated CRM context and a single round-trip boundary.
 *
 * Pillars covered:
 *   20-25: Timeouts, bounded retries, and the circuit breaker all live in Zoho_API — this
 *          class inherits them by going through Zoho_API::post for the function execution.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Estimate_Generator {

	private static ?Estimate_Generator $instance = null;

	/**
	 * Deluge standalone function name, as registered in Zoho CRM → Developer Space → Functions.
	 * Keep in sync with deluge/generate_estimate.deluge.
	 */
	public const DELUGE_FUNCTION = 'tc_generate_estimate';

	public static function instance(): Estimate_Generator {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Generate a Books estimate + CRM Deal for a validated payload.
	 *
	 * @return array{estimate_id:string,estimate_url:string,deal_id:string,deal_url:string,subtotal:float,total:float}|WP_Error
	 */
	public function generate(
		array $validated_payload,
		array $customer,
		array $view,
		string $rendered_body,
		array $template_meta,
		string $idempotency_key
	): array|WP_Error {

		$org_id = (string) get_option( 'tc_estimate_zoho_org_id', '' );
		if ( '' === $org_id ) {
			return new WP_Error(
				'tc_estimate_zoho_not_configured',
				__( 'Zoho Books organization ID is not configured. See Estimate Builder → Settings.', 'tc-estimate' ),
				array( 'status' => 500 )
			);
		}

		$books_customer_id = $this->resolve_books_customer_id( $customer, $org_id );
		if ( is_wp_error( $books_customer_id ) ) {
			return $books_customer_id;
		}
		$customer['books_contact_id'] = $books_customer_id;

		$deluge_payload = $this->build_deluge_payload( $validated_payload, $customer, $view, $rendered_body, $template_meta, $idempotency_key, $org_id );
		$deluge_payload_json = wp_json_encode( $deluge_payload );
		if ( false === $deluge_payload_json ) {
			return new WP_Error(
				'tc_estimate_deluge_payload_encode',
				__( 'Could not encode Deluge payload as JSON.', 'tc-estimate' ),
				array( 'status' => 500 )
			);
		}

		$payload_token = wp_generate_password( 48, false, false );
		set_transient( 'tc_estimate_deluge_payload_' . $payload_token, $deluge_payload_json, 10 * MINUTE_IN_SECONDS );
		$payload_url = rest_url( TC_ESTIMATE_REST_NS . '/deluge-payload/' . rawurlencode( $payload_token ) );

		$arguments = wp_json_encode( array(
			'payload' => esc_url_raw( $payload_url ),
		) );
		if ( false === $arguments ) {
			return new WP_Error(
				'tc_estimate_deluge_arguments_encode',
				__( 'Could not encode Deluge function arguments as JSON.', 'tc-estimate' ),
				array( 'status' => 500 )
			);
		}

		$path = sprintf(
			'/functions/%s/actions/execute?auth_type=oauth&arguments=%s',
			self::DELUGE_FUNCTION,
			rawurlencode( $arguments )
		);

		$response = Zoho_API::instance()->post_form(
			Zoho_API::SERVICE_CRM,
			$path,
			array()
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$output_raw = $response['details']['output'] ?? $response['output'] ?? null;
		if ( null === $output_raw ) {
			$detail = $this->summarize_deluge_execute_response( $response );
			return new WP_Error(
				'tc_estimate_deluge_empty',
				'' !== $detail
					? sprintf( __( 'Deluge function returned no output. Zoho response: %s', 'tc-estimate' ), $detail )
					: __( 'Deluge function returned no output.', 'tc-estimate' ),
				array( 'status' => 502, 'zoho_response' => $response )
			);
		}

		$output = is_string( $output_raw ) ? json_decode( $output_raw, true ) : $output_raw;
		if ( ! is_array( $output ) ) {
			return new WP_Error(
				'tc_estimate_deluge_decode',
				sprintf(
					__( 'Deluge function output was not valid JSON: %s', 'tc-estimate' ),
					mb_substr( (string) $output_raw, 0, 500 )
				),
				array( 'status' => 502, 'deluge_output' => $output_raw )
			);
		}

		// Deluge signals failure with {"ok":false,"error_code":"...","message":"..."}.
		if ( empty( $output['ok'] ) ) {
			$code = (string) ( $output['error_code'] ?? 'tc_estimate_deluge_failed' );
			$msg  = (string) ( $output['message'] ?? __( 'Deluge generateEstimate reported failure.', 'tc-estimate' ) );
			$status = str_starts_with( $code, 'tc_deluge_' ) ? 400 : 502;
			return new WP_Error( $code, $msg, array( 'status' => $status, 'deluge_output' => $output ) );
		}

		$estimate_id = (string) ( $output['estimate_id'] ?? '' );
		$deal_id     = (string) ( $output['deal_id'] ?? '' );
		if ( '' === $estimate_id || '' === $deal_id ) {
			return new WP_Error(
				'tc_estimate_deluge_incomplete',
				__( 'Deluge function returned success but did not include both IDs.', 'tc-estimate' ),
				array( 'status' => 502 )
			);
		}

		return array(
			'estimate_id'  => $estimate_id,
			'estimate_url' => (string) ( $output['estimate_url'] ?? $this->books_estimate_url( $estimate_id ) ),
			'deal_id'      => $deal_id,
			'deal_url'     => (string) ( $output['deal_url'] ?? $this->crm_deal_url( $deal_id ) ),
			'subtotal'     => (float) ( $output['subtotal'] ?? ( $view['pricing']['subtotal'] ?? 0 ) ),
			'total'        => (float) ( $output['total'] ?? ( $view['pricing']['total'] ?? 0 ) ),
			'books_email_sent' => ! empty( $output['books_email_sent'] ),
			'books_email_message' => (string) ( $output['books_email_message'] ?? '' ),
		);
	}

	/**
	 * Transform the validated request into the exact shape the Deluge function expects.
	 * Deluge is easier to reason about with flat, pre-computed values.
	 */
	private function build_deluge_payload(
		array $payload,
		array $customer,
		array $view,
		string $rendered_body,
		array $template_meta,
		string $idempotency_key,
		string $org_id
	): array {

		$line_items = array();
		$quoted_equipment_rows = array();
		$known_slots = array( 'furnace', 'condenser', 'coil', 'air_handler', 'thermostat', 'humidifier', 'uv_purifier', 'water_heater', 'filter', 'part' );

		foreach ( $view['systems'] as $sys ) {
			$system_num   = (int) ( $sys['system_number'] ?? 0 );
			$system_label = (string) ( $sys['system_label'] ?? '' );

			foreach ( $known_slots as $k ) {
				if ( empty( $sys[ $k ] ) || ! is_array( $sys[ $k ] ) ) {
					continue;
				}
				$item = $sys[ $k ];
				$quoted_equipment_rows[] = array(
					'Slot'             => $k,
					'System_Number'    => $system_num,
					'System_Label'     => $system_label,
					'Name'             => (string) ( $item['name'] ?? '' ),
					'Brand'            => (string) ( $item['brand'] ?? '' ),
					'Model'            => (string) ( $item['model'] ?? '' ),
					'SEER'             => (string) ( $item['seer'] ?? '' ),
					'AFUE'             => (string) ( $item['afue'] ?? '' ),
					'Tons'             => (string) ( $item['tons'] ?? '' ),
					'BTU_Input'        => (string) ( $item['btu_input'] ?? '' ),
					'BTU_Output'       => (string) ( $item['btu_output'] ?? '' ),
					'Refrigerant'      => (string) ( $item['refrigerant'] ?? '' ),
					'Short_Description' => (string) ( $item['short_description'] ?? '' ),
					'Zoho_Item_ID'     => (string) ( $item['item_id'] ?? '' ),
					'CRM_Equipment_ID' => (string) ( $item['item_id'] ?? '' ),
					'Rate'             => (float) ( $item['rate'] ?? 0 ),
				);
			}

			foreach ( ( $sys['other'] ?? array() ) as $o ) {
				$slot = (string) ( $o['slot'] ?? 'other' );
				$quoted_equipment_rows[] = array(
					'Slot'             => $slot,
					'System_Number'    => $system_num,
					'System_Label'     => $system_label,
					'Name'             => (string) ( $o['name'] ?? '' ),
					'Brand'            => (string) ( $o['brand'] ?? '' ),
					'Model'            => (string) ( $o['model'] ?? '' ),
					'SEER'             => (string) ( $o['seer'] ?? '' ),
					'AFUE'             => (string) ( $o['afue'] ?? '' ),
					'Tons'             => (string) ( $o['tons'] ?? '' ),
					'BTU_Input'        => (string) ( $o['btu_input'] ?? '' ),
					'BTU_Output'       => (string) ( $o['btu_output'] ?? '' ),
					'Refrigerant'      => (string) ( $o['refrigerant'] ?? '' ),
					'Short_Description' => (string) ( $o['short_description'] ?? '' ),
					'Zoho_Item_ID'     => (string) ( $o['item_id'] ?? '' ),
					'CRM_Equipment_ID' => (string) ( $o['item_id'] ?? '' ),
					'Rate'             => (float) ( $o['rate'] ?? 0 ),
				);
			}
		}

		$selected_subtotal = 0.0;
		foreach ( $quoted_equipment_rows as $row ) {
			$rate = (float) ( $row['Rate'] ?? 0 );
			$selected_subtotal += $rate;
			$line_items[] = array(
				'item_id'     => (string) ( $row['Zoho_Item_ID'] ?? '' ),
				'name'        => (string) ( $row['Name'] ?? '' ),
				'description' => (string) ( $row['Short_Description'] ?? '' ),
				'rate'        => $rate,
				'quantity'    => 1,
				'slot'        => (string) ( $row['Slot'] ?? '' ),
				'system_num'  => (int) ( $row['System_Number'] ?? 0 ),
			);
		}
		$target_total = (float) ( $view['pricing']['total'] ?? $selected_subtotal );
		$adjustment   = round( $target_total - $selected_subtotal, 2 );

		$billing = isset( $customer['billing_address'] ) && is_array( $customer['billing_address'] ) ? $customer['billing_address'] : array();
		$wp_user = wp_get_current_user();

		return array(
			'meta' => array(
				'plugin_version'   => TC_ESTIMATE_VERSION,
				'idempotency_key'  => $idempotency_key,
				'template_id'      => (int) ( $template_meta['id'] ?? $payload['template_id'] ?? 0 ),
				'template_version' => (int) ( $template_meta['version'] ?? 1 ),
				'template_name'    => (string) ( $template_meta['name'] ?? '' ),
				'template_type'    => (string) ( $template_meta['template_type'] ?? '' ),
				'wp_user_id'       => $wp_user ? (int) $wp_user->ID : 0,
				'wp_user_display'  => $wp_user ? (string) $wp_user->display_name : '',
				'generated_at'     => current_time( 'mysql', true ),
			),
			'books' => array(
				'organization_id'  => $org_id,
				'customer_id'      => (string) ( $customer['books_contact_id'] ?? '' ),
				'customer_name'    => (string) ( $customer['name'] ?? '' ),
				'customer_email'   => (string) ( $customer['email'] ?? '' ),
				'reference_number' => sprintf( 'TC-%d', time() ),
				'date'             => gmdate( 'Y-m-d' ),
				'expiry_date'      => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
				'line_items'       => $line_items,
				'adjustment'       => $adjustment,
				'adjustment_description' => 0.0 === $adjustment ? '' : 'Project price adjustment',
				'notes'            => $this->books_notes( $customer, $view, $template_meta ),
				'terms'            => $this->books_terms(),
				'annexure_content' => $this->books_annexure_content( $quoted_equipment_rows, $view, $template_meta ),
				'email_subject'    => $this->books_email_subject( $customer, $template_meta ),
				'email_body'       => $this->books_email_body( $customer, $view, $template_meta, $quoted_equipment_rows ),
				'template_body'    => $rendered_body,
				'billing_address'  => array(
					'address' => (string) ( $billing['street']  ?? '' ),
					'city'    => (string) ( $billing['city']    ?? '' ),
					'state'   => (string) ( $billing['state']   ?? '' ),
					'zip'     => (string) ( $billing['zip']     ?? '' ),
					'country' => (string) ( $billing['country'] ?? 'U.S.A.' ),
				),
				'subtotal'         => (float) ( $view['pricing']['subtotal'] ?? 0 ),
				'total'            => (float) ( $view['pricing']['total']    ?? 0 ),
				'deposit_percent'  => (int) ( $view['pricing']['deposit_percent'] ?? 0 ),
			),
			'crm' => array(
				'account_id'          => (string) ( $customer['id'] ?? '' ),
				'deal_name'           => $this->deal_name( $customer, $template_meta ),
				'stage'               => 'Proposal Sent',
				'closing_date'        => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
				'amount'              => (float) ( $view['pricing']['total'] ?? 0 ),
				'description'         => sprintf(
					'%s — %d system(s). %s',
					(string) ( $template_meta['name'] ?? '' ),
					(int) ( $view['system_count'] ?? 0 ),
					(string) ( $view['special_notes'] ?? '' )
				),
				'quoted_equipment'    => $quoted_equipment_rows,
				'financing_requested' => ! empty( $view['has_financing'] ),
				'financing_term'      => (int) ( $view['financing']['term_months'] ?? 0 ),
			),
		);
	}

	private function books_line_description( array $quoted_equipment_rows, array $view ): string {
		return 'Complete HVAC installation package. See Annexure for equipment, scope, warranty, and notes.';
	}

	private function books_annexure_content( array $quoted_equipment_rows, array $view, array $template_meta ): string {
		$parts = array(
			sprintf( '<p><strong>%s</strong></p>', esc_html( $this->books_proposal_title( $template_meta ) ) ),
			sprintf( '<p>%s</p>', esc_html( $this->books_scope_text( $template_meta ) ) ),
			'<p><strong>Equipment Included</strong></p>',
			'<ul>',
		);

		$last_system = null;
		foreach ( array_slice( $quoted_equipment_rows, 0, 12 ) as $row ) {
			$system_number = (string) ( $row['System_Number'] ?? '' );
			if ( $system_number !== $last_system && count( $quoted_equipment_rows ) > 1 ) {
				$system_label = trim( (string) ( $row['System_Label'] ?? '' ) );
				$parts[] = '' !== $system_label
					? sprintf( '<li><strong>System %s - %s</strong></li>', esc_html( $system_number ), esc_html( $system_label ) )
					: sprintf( '<li><strong>System %s</strong></li>', esc_html( $system_number ) );
				$last_system = $system_number;
			}
			$parts[] = $this->books_equipment_html( $row );
		}
		if ( count( $quoted_equipment_rows ) > 12 ) {
			$parts[] = sprintf( '<li>+%d additional item(s)</li>', count( $quoted_equipment_rows ) - 12 );
		}
		$parts[] = '</ul>';

		$parts[] = '<p><strong>Included Scope</strong></p>';
		$parts[] = '<ul>';
		foreach ( $this->books_scope_items( $template_meta ) as $scope_item ) {
			$parts[] = sprintf( '<li>%s</li>', esc_html( $scope_item ) );
		}
		$parts[] = '</ul>';

		$parts[] = '<p><strong>Warranty &amp; Maintenance</strong></p>';
		$parts[] = '<ul>';
		$parts[] = sprintf(
			'<li>%d year parts warranty and %d year labor warranty.</li>',
			(int) ( $view['warranty']['parts_years'] ?? 10 ),
			(int) ( $view['warranty']['labor_years'] ?? 10 )
		);
		$parts[] = '<li>Labor warranty requires an active Temp Control maintenance agreement.</li>';
		$parts[] = '<li>First year heating and air conditioning maintenance is included.</li>';
		$parts[] = '</ul>';

		if ( ! empty( $view['has_rebates'] ) && ! empty( $view['rebates'] ) && is_array( $view['rebates'] ) ) {
			$parts[] = '<p><strong>Rebates Included</strong></p>';
			$parts[] = '<ul>';
			foreach ( $view['rebates'] as $rebate ) {
				if ( ! is_array( $rebate ) ) {
					continue;
				}
				$parts[] = sprintf(
					'<li>%s: %s</li>',
					esc_html( (string) ( $rebate['name'] ?? 'Rebate' ) ),
					esc_html( (string) ( $rebate['amount_formatted'] ?? '' ) )
				);
			}
			$parts[] = '</ul>';
		}

		$special_notes = trim( (string) ( $view['special_notes'] ?? '' ) );
		if ( '' !== $special_notes ) {
			$parts[] = '<p><strong>Project Notes</strong></p>';
			$parts[] = sprintf( '<p>%s</p>', nl2br( esc_html( $special_notes ) ) );
		}

		$parts[] = '<p><strong>Additional Notes</strong></p>';
		$parts[] = '<ul>';
		$parts[] = '<li>Permit fees are not included unless specifically listed on the estimate.</li>';
		$parts[] = '<li>Temp Control will provide necessary permit paperwork.</li>';
		$parts[] = '<li>Deduct $750 if paying by check, cash, or not financing the project.</li>';
		$parts[] = '</ul>';

		return implode( "\n", array_filter( $parts ) );
	}

	private function books_proposal_title( array $template_meta ): string {
		$type = (string) ( $template_meta['template_type'] ?? '' );
		if ( 'ac_only' === $type ) {
			return 'Air Conditioning System Replacement Proposal';
		}
		if ( 'furnace_only' === $type ) {
			return 'Heating System Replacement Proposal';
		}
		return 'Heating & Air Conditioning System Replacement Proposal';
	}

	private function books_scope_text( array $template_meta ): string {
		$type = (string) ( $template_meta['template_type'] ?? '' );
		if ( 'ac_only' === $type ) {
			return 'Temp Control proposes to remove and replace the existing air-conditioning system with new high-efficiency equipment listed below. Installation will include removal and disposal of existing equipment, necessary connections, startup, testing, and calibration of the new system.';
		}
		if ( 'furnace_only' === $type ) {
			return 'Temp Control proposes to remove and replace the existing heating system with new equipment listed below. Installation will include removal and disposal of existing equipment, necessary connections, startup, testing, and calibration of the new system.';
		}
		return 'Temp Control proposes to remove and replace the existing heating and air conditioning system with new equipment listed below. Installation will include all necessary connections, startup, testing, and calibration of the new system. We will also remove and properly dispose of the existing equipment.';
	}

	private function books_scope_items( array $template_meta ): array {
		$type = (string) ( $template_meta['template_type'] ?? '' );
		if ( 'ac_only' === $type ) {
			return array(
				'Remove existing condenser and indoor coil.',
				'Install new matched air conditioning equipment.',
				'Pressure test, evacuate, charge, start up, and test operation.',
				'Review system operation and maintenance requirements with homeowner.',
			);
		}
		if ( 'furnace_only' === $type ) {
			return array(
				'Remove and dispose of existing heating equipment.',
				'Install new heating equipment with required connections.',
				'Start up, test, and verify safe operation.',
				'Review system operation and maintenance requirements with homeowner.',
			);
		}
		return array(
			'Remove and dispose of existing heating and air conditioning equipment.',
			'Install new matched HVAC equipment with required connections.',
			'Start up, test, calibrate, and verify system operation.',
			'Review system operation and maintenance requirements with homeowner.',
		);
	}

	private function books_equipment_lines( array $row ): array {
		$slot = ucwords( str_replace( '_', ' ', (string) ( $row['Slot'] ?? 'Equipment' ) ) );
		$brand_model = trim( trim( (string) ( $row['Brand'] ?? '' ) ) . ' ' . trim( (string) ( $row['Model'] ?? '' ) ) );
		if ( '' === $brand_model ) {
			$brand_model = trim( (string) ( $row['Name'] ?? '' ) );
		}

		$lines = array();
		$lines[] = '' !== $brand_model ? sprintf( '%s', $slot ) : $slot;
		if ( '' !== $brand_model ) {
			$lines[] = sprintf( '* %s', $brand_model );
		}

		$specs = array(
			'SEER'        => 'SEER2',
			'AFUE'        => 'AFUE',
			'Tons'        => 'Tons',
			'BTU_Input'   => 'BTU Input',
			'BTU_Output'  => 'BTU Output',
			'Refrigerant' => 'Refrigerant',
		);
		foreach ( $specs as $key => $label ) {
			$value = trim( (string) ( $row[ $key ] ?? '' ) );
			if ( '' !== $value && '0' !== $value && '0.0' !== $value ) {
				$lines[] = sprintf( '* %s: %s', $label, $value );
			}
		}

		$description = trim( (string) ( $row['Short_Description'] ?? '' ) );
		if ( '' !== $description && $description !== $brand_model ) {
			$lines[] = sprintf( '* %s', $description );
		}

		return $lines;
	}

	private function books_equipment_html( array $row ): string {
		$lines = $this->books_equipment_lines( $row );
		if ( empty( $lines ) ) {
			return '';
		}

		$title = array_shift( $lines );
		$details = array();
		foreach ( $lines as $line ) {
			$details[] = esc_html( ltrim( $line, '* ' ) );
		}

		if ( empty( $details ) ) {
			return sprintf( '<li><strong>%s</strong></li>', esc_html( $title ) );
		}

		return sprintf( '<li><strong>%s:</strong> %s</li>', esc_html( $title ), implode( '; ', $details ) );
	}

	private function books_notes( array $customer, array $view, array $template_meta ): string {
		return 'Please review this estimate and approve through Zoho Books when ready to proceed.';
	}

	private function books_terms(): string {
		return implode( "\n", array(
			'This proposal is valid for 30 days from the estimate date.',
			'Pricing is subject to equipment availability at the time of acceptance.',
			'Customer approval through Zoho Books authorizes Temp Control to proceed with the proposed work.',
		) );
	}

	private function books_email_subject( array $customer, array $template_meta ): string {
		$customer_name = trim( (string) ( $customer['name'] ?? '' ) );
		$template_name = trim( (string) ( $template_meta['name'] ?? __( 'HVAC Estimate', 'tc-estimate' ) ) );
		return trim( sprintf( 'Your Temp Control Estimate%s', '' !== $customer_name ? ' - ' . $customer_name : '' ) ) ?: $template_name;
	}

	private function books_email_body( array $customer, array $view, array $template_meta, array $quoted_equipment_rows ): string {
		$lines = array();
		$customer_name = trim( (string) ( $customer['name'] ?? '' ) );
		$lines[] = '' !== $customer_name ? 'Hi ' . $customer_name . ',' : 'Hello,';
		$lines[] = '';
		$lines[] = 'Thank you for choosing Temp Control Heating & Air Conditioning. Your estimate is attached and ready for review and approval through Zoho Books.';
		$lines[] = '';
		$lines[] = 'Proposal summary:';
		$lines[] = (string) ( $template_meta['name'] ?? 'HVAC proposal' );
		$lines[] = sprintf( 'Total: $%s', number_format( (float) ( $view['pricing']['total'] ?? 0 ), 2, '.', ',' ) );
		$lines[] = '';
		$lines[] = 'Included equipment:';
		foreach ( array_slice( $quoted_equipment_rows, 0, 8 ) as $row ) {
			$lines[] = trim( sprintf(
				'System %s - %s: %s %s',
				(string) ( $row['System_Number'] ?? '' ),
				ucwords( str_replace( '_', ' ', (string) ( $row['Slot'] ?? '' ) ) ),
				(string) ( $row['Brand'] ?? '' ),
				(string) ( $row['Model'] ?? '' )
			) );
		}
		if ( count( $quoted_equipment_rows ) > 8 ) {
			$lines[] = sprintf( '+%d additional item(s)', count( $quoted_equipment_rows ) - 8 );
		}
		$lines[] = '';
		$lines[] = 'Please use the Zoho Books review link in this email to approve the estimate when you are ready.';
		$lines[] = '';
		$lines[] = 'Thank you,';
		$lines[] = 'Temp Control Heating & Air Conditioning';
		return implode( "\n", array_filter( $lines, static fn( $line ) => null !== $line ) );
	}

	private function deal_name( array $customer, array $template_meta ): string {
		$customer_name = trim( (string) ( $customer['name'] ?? __( 'New Customer', 'tc-estimate' ) ) );
		$tmpl          = (string) ( $template_meta['name'] ?? __( 'Estimate', 'tc-estimate' ) );
		return mb_substr( sprintf( '%s — %s', $customer_name, $tmpl ), 0, 100 );
	}

	private function books_estimate_url( string $estimate_id ): string {
		$dc = (string) get_option( 'tc_estimate_zoho_dc', 'com' );
		return sprintf( 'https://books.zoho.%s/app/#/estimates/%s', $dc, rawurlencode( $estimate_id ) );
	}

	private function crm_deal_url( string $deal_id ): string {
		$dc = (string) get_option( 'tc_estimate_zoho_dc', 'com' );
		return sprintf( 'https://crm.zoho.%s/crm/tab/Potentials/%s', $dc, rawurlencode( $deal_id ) );
	}

	private function resolve_books_customer_id( array $customer, string $org_id ): string|WP_Error {
		$email = strtolower( trim( (string) ( $customer['email'] ?? '' ) ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
				'tc_estimate_books_customer_no_email',
				__( 'The selected CRM account does not have a valid email address to match a Zoho Books customer.', 'tc-estimate' ),
				array( 'status' => 400 )
			);
		}

		return Zoho_Cache::instance()->remember(
			'books_customer_email_' . md5( $org_id . '|' . $email ),
			TC_ESTIMATE_CACHE_TTL_CUSTOMER,
			function () use ( $email, $org_id ) {
				$queries = array(
					array(
						'organization_id' => $org_id,
						'contact_type'    => 'customer',
						'email'           => $email,
						'per_page'        => 10,
					),
					array(
						'organization_id' => $org_id,
						'contact_type'    => 'customer',
						'search_text'     => $email,
						'per_page'        => 25,
					),
				);

				$last_error = null;
				foreach ( $queries as $query ) {
					$response = Zoho_API::instance()->get(
						Zoho_API::SERVICE_BOOKS,
						'/contacts',
						$query
					);
					if ( is_wp_error( $response ) ) {
						$last_error = $response;
						continue;
					}

					$contacts = isset( $response['contacts'] ) && is_array( $response['contacts'] ) ? $response['contacts'] : array();
					foreach ( $contacts as $contact ) {
						if ( ! is_array( $contact ) || empty( $contact['contact_id'] ) ) {
							continue;
						}
						if ( $this->books_contact_has_email( $contact, $email ) ) {
							return (string) $contact['contact_id'];
						}
					}
				}

				if ( is_wp_error( $last_error ) ) {
					return $last_error;
				}

				return new WP_Error(
					'tc_estimate_books_customer_not_found',
					sprintf(
						/* translators: %s: customer email address */
						__( 'No active Zoho Books customer was found with email %s.', 'tc-estimate' ),
						$email
					),
					array( 'status' => 400 )
				);
			}
		);
	}

	private function books_contact_has_email( array $contact, string $email ): bool {
		$candidates = array(
			$contact['email'] ?? '',
			$contact['primary_contact_email'] ?? '',
		);

		if ( isset( $contact['contact_persons'] ) && is_array( $contact['contact_persons'] ) ) {
			foreach ( $contact['contact_persons'] as $person ) {
				if ( is_array( $person ) ) {
					$candidates[] = $person['email'] ?? '';
				}
			}
		}

		foreach ( $candidates as $candidate ) {
			if ( strtolower( trim( (string) $candidate ) ) === $email ) {
				return true;
			}
		}

		return false;
	}

	private function summarize_deluge_execute_response( array $response ): string {
		$bits = array();
		foreach ( array( 'code', 'message', 'status' ) as $key ) {
			if ( isset( $response[ $key ] ) && is_scalar( $response[ $key ] ) ) {
				$bits[] = sprintf( '%s=%s', $key, (string) $response[ $key ] );
			}
		}
		if ( isset( $response['details'] ) && is_array( $response['details'] ) ) {
			foreach ( array( 'code', 'message', 'status', 'output' ) as $key ) {
				if ( isset( $response['details'][ $key ] ) && is_scalar( $response['details'][ $key ] ) ) {
					$bits[] = sprintf( 'details.%s=%s', $key, (string) $response['details'][ $key ] );
				}
			}
		}
		return mb_substr( implode( '; ', $bits ), 0, 700 );
	}
}
