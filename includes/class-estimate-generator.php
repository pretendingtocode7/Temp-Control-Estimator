<?php
/**
 * Estimate generator — builds Zoho payloads and creates a Books draft plus CRM Deal.
 *
 * Creation happens directly through the Books and CRM APIs. This is intentional: an older
 * installed Deluge function emailed the estimate immediately after creation. Passing
 * send=false to Books here guarantees that a newly-created estimate remains a draft until
 * the technician uses the separate send action. If CRM creation fails, the draft is deleted.
 *
 * Pillars covered:
 *   20-25: Timeouts, bounded retries, and the circuit breaker all live in Zoho_API — this
 *          class inherits them by going through Zoho_API for Books and CRM writes.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Estimate_Generator {

	private static ?Estimate_Generator $instance = null;

	public static function instance(): Estimate_Generator {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Generate a Books estimate + CRM Deal for a validated payload.
	 *
	 * @return array{estimate_id:string,estimate_number:string,estimate_status:string,estimate_url:string,deal_id:string,deal_url:string,subtotal:float,total:float,books_email_sent:bool,books_email_message:string}|WP_Error
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

		$transaction = $this->build_transaction_payload( $validated_payload, $customer, $view, $rendered_body, $template_meta, $idempotency_key, $org_id );

		$books = $this->create_books_draft( $transaction['books'] );
		if ( is_wp_error( $books ) ) {
			return $books;
		}

		$estimate_id     = (string) $books['estimate_id'];
		$estimate_number = (string) ( $books['estimate_number'] ?? '' );
		$deal = $this->create_crm_deal( $transaction['crm'], $estimate_id, $estimate_number, $idempotency_key );
		if ( is_wp_error( $deal ) ) {
			$rollback = Zoho_API::instance()->delete(
				Zoho_API::SERVICE_BOOKS,
				'/estimates/' . rawurlencode( $estimate_id ),
				array( 'organization_id' => $org_id )
			);
			$message = $deal->get_error_message();
			if ( is_wp_error( $rollback ) ) {
				$message .= ' ' . __( 'The Books draft could not be removed automatically; delete it manually.', 'tc-estimate' );
			}
			return new WP_Error( $deal->get_error_code(), $message, $deal->get_error_data() );
		}

		return array(
			'estimate_id'          => $estimate_id,
			'estimate_number'      => $estimate_number,
			'estimate_status'      => (string) ( $books['status'] ?? 'draft' ),
			'estimate_url'         => $this->books_estimate_url( $estimate_id ),
			'deal_id'              => (string) $deal['deal_id'],
			'deal_url'             => $this->crm_deal_url( (string) $deal['deal_id'] ),
			'subtotal'             => (float) ( $books['sub_total'] ?? ( $view['pricing']['subtotal'] ?? 0 ) ),
			'total'                => (float) ( $books['total'] ?? ( $view['pricing']['total'] ?? 0 ) ),
			'books_email_sent'     => false,
			'books_email_message'  => __( 'Estimate created as a draft. No customer email was sent.', 'tc-estimate' ),
		);
	}

	/**
	 * Create the Books transaction with send=false and verify that it stayed in draft.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function create_books_draft( array $books ): array|WP_Error {
		$org_id = (string) ( $books['organization_id'] ?? '' );
		$line_items = array();
		foreach ( (array) ( $books['line_items'] ?? array() ) as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$books_line = array(
				'name'        => (string) ( $line['name'] ?? '' ),
				'description' => (string) ( $line['description'] ?? '' ),
				'rate'        => (float) ( $line['rate'] ?? 0 ),
				'quantity'    => (float) ( $line['quantity'] ?? 1 ),
			);
			$item_id = (string) ( $line['item_id'] ?? '' );
			if ( '' !== $item_id ) {
				$books_line['item_id'] = $item_id;
			}
			$line_items[] = $books_line;
		}

		$body = array(
			'customer_id'            => (string) ( $books['customer_id'] ?? '' ),
			'reference_number'       => (string) ( $books['reference_number'] ?? '' ),
			'date'                   => (string) ( $books['date'] ?? '' ),
			'expiry_date'            => (string) ( $books['expiry_date'] ?? '' ),
			'line_items'             => $line_items,
			'notes'                  => (string) ( $books['notes'] ?? '' ),
			'terms'                  => (string) ( $books['terms'] ?? '' ),
			'adjustment'             => (float) ( $books['adjustment'] ?? 0 ),
			'adjustment_description' => (string) ( $books['adjustment_description'] ?? '' ),
		);
		$billing_address = is_array( $books['billing_address'] ?? null ) ? $books['billing_address'] : array();
		if ( '' !== trim( (string) ( $billing_address['address'] ?? '' ) ) ) {
			$body['billing_address'] = $billing_address;
		}

		$response = Zoho_API::instance()->post(
			Zoho_API::SERVICE_BOOKS,
			'/estimates?organization_id=' . rawurlencode( $org_id ) . '&send=false',
			$body
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( isset( $response['code'] ) && 0 !== (int) $response['code'] ) {
			return new WP_Error(
				'tc_estimate_books_create_failed',
				(string) ( $response['message'] ?? __( 'Zoho Books could not create the estimate draft.', 'tc-estimate' ) ),
				array( 'status' => 502, 'zoho_response' => $response )
			);
		}

		$estimate = isset( $response['estimate'] ) && is_array( $response['estimate'] ) ? $response['estimate'] : array();
		$estimate_id = (string) ( $estimate['estimate_id'] ?? '' );
		if ( '' === $estimate_id ) {
			return new WP_Error( 'tc_estimate_books_no_id', __( 'Zoho Books created no usable estimate ID.', 'tc-estimate' ), array( 'status' => 502 ) );
		}
		$status = strtolower( (string) ( $estimate['status'] ?? 'draft' ) );
		if ( '' !== $status && 'draft' !== $status ) {
			Zoho_API::instance()->delete(
				Zoho_API::SERVICE_BOOKS,
				'/estimates/' . rawurlencode( $estimate_id ),
				array( 'organization_id' => $org_id )
			);
			return new WP_Error(
				'tc_estimate_books_not_draft',
				sprintf( __( 'Zoho Books created the estimate with unexpected status %s instead of draft.', 'tc-estimate' ), $status ),
				array( 'status' => 502, 'estimate_id' => $estimate_id )
			);
		}

		return $estimate;
	}

	/**
	 * Create the related CRM Deal directly. Optional tracking fields are best-effort so
	 * a missing custom field cannot destroy an otherwise valid estimate and Deal.
	 *
	 * @return array{deal_id:string}|WP_Error
	 */
	private function create_crm_deal( array $crm, string $estimate_id, string $estimate_number, string $idempotency_key ): array|WP_Error {
		$record = array(
			'Deal_Name'    => (string) ( $crm['deal_name'] ?? __( 'Untitled Deal', 'tc-estimate' ) ),
			'Stage'        => (string) ( $crm['stage'] ?? 'Proposal Sent' ),
			'Closing_Date' => (string) ( $crm['closing_date'] ?? '' ),
			'Amount'       => (float) ( $crm['amount'] ?? 0 ),
			'Description'  => (string) ( $crm['description'] ?? '' ),
		);
		$account_id = (string) ( $crm['account_id'] ?? '' );
		if ( '' !== $account_id ) {
			$record['Account_Name'] = array( 'id' => $account_id );
		}

		$response = Zoho_API::instance()->post( Zoho_API::SERVICE_CRM, '/Deals', array( 'data' => array( $record ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$first = isset( $response['data'][0] ) && is_array( $response['data'][0] ) ? $response['data'][0] : array();
		$deal_id = (string) ( $first['details']['id'] ?? $first['id'] ?? '' );
		$code = strtoupper( (string) ( $first['code'] ?? '' ) );
		if ( '' === $deal_id || ( '' !== $code && 'SUCCESS' !== $code ) ) {
			return new WP_Error(
				'tc_estimate_crm_create_failed',
				(string) ( $first['message'] ?? __( 'Zoho CRM could not create the related Deal.', 'tc-estimate' ) ),
				array( 'status' => 502, 'zoho_response' => $response )
			);
		}

		$optional = array(
			'Books_Estimate_ID'       => $estimate_id,
			'Books_Estimate_Number'   => $estimate_number,
			'Idempotency_Key'         => $idempotency_key,
			'Financing_Requested'     => ! empty( $crm['financing_requested'] ),
			'Financing_Term_Months'  => (int) ( $crm['financing_term'] ?? 0 ),
			'Quoted_Equipment'        => is_array( $crm['quoted_equipment'] ?? null ) ? $crm['quoted_equipment'] : array(),
		);
		Zoho_API::instance()->put( Zoho_API::SERVICE_CRM, '/Deals/' . rawurlencode( $deal_id ), array( 'data' => array( $optional ) ) );

		return array( 'deal_id' => $deal_id );
	}

	/**
	 * Build the editable text fields shown before and after the Books draft is created.
	 * The ordering matches build_transaction_payload(), allowing updates to map safely
	 * back to the corresponding Books line item without exposing prices for editing.
	 *
	 * @return array{line_items:array<int,array<string,mixed>>,notes:string,terms:string}
	 */
	public function build_books_preview( array $view, array $customer, array $template_meta ): array {
		$line_items  = array();
		$known_slots = array( 'furnace', 'condenser', 'coil', 'air_handler', 'thermostat', 'humidifier', 'uv_purifier', 'water_heater', 'filter', 'part', 'permit' );

		foreach ( (array) ( $view['systems'] ?? array() ) as $sys ) {
			if ( ! is_array( $sys ) ) {
				continue;
			}
			$system_num = (int) ( $sys['system_number'] ?? 0 );
			foreach ( $known_slots as $slot ) {
				if ( empty( $sys[ $slot ] ) || ! is_array( $sys[ $slot ] ) ) {
					continue;
				}
				$item = $sys[ $slot ];
				$line_items[] = array(
					'key'         => $this->books_edit_key( $system_num, $slot ),
					'item_id'     => (string) ( $item['item_id'] ?? '' ),
					'name'        => (string) ( $item['name'] ?? ucwords( str_replace( '_', ' ', $slot ) ) ),
					'description' => (string) ( $item['short_description'] ?? '' ),
					'rate'        => (float) ( $item['rate'] ?? 0 ),
				);
			}

			foreach ( (array) ( $sys['other'] ?? array() ) as $other_index => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$slot = (string) ( $item['slot'] ?? 'other' );
				$line_items[] = array(
					'key'         => $this->books_edit_key( $system_num, $slot, (int) $other_index ),
					'item_id'     => (string) ( $item['item_id'] ?? '' ),
					'name'        => (string) ( $item['name'] ?? ucwords( str_replace( '_', ' ', $slot ) ) ),
					'description' => (string) ( $item['short_description'] ?? '' ),
					'rate'        => (float) ( $item['rate'] ?? 0 ),
				);
			}
		}

		return array(
			'line_items' => $line_items,
			'notes'      => $this->books_notes( $customer, $view, $template_meta ),
			'terms'      => $this->books_terms( $view ),
		);
	}

	/**
	 * Transform the validated request into flat Books and CRM transaction payloads.
	 */
	private function build_transaction_payload(
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
		$known_slots = array( 'furnace', 'condenser', 'coil', 'air_handler', 'thermostat', 'humidifier', 'uv_purifier', 'water_heater', 'filter', 'part', 'permit' );

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
					'Edit_Key'         => $this->books_edit_key( $system_num, $k ),
				);
			}

			foreach ( ( $sys['other'] ?? array() ) as $other_index => $o ) {
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
					'Edit_Key'         => $this->books_edit_key( $system_num, $slot, (int) $other_index ),
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
				'edit_key'    => (string) ( $row['Edit_Key'] ?? '' ),
			);
		}

		$books_edits = isset( $payload['books_edits'] ) && is_array( $payload['books_edits'] ) ? $payload['books_edits'] : array();
		$description_overrides = array();
		foreach ( (array) ( $books_edits['line_items'] ?? array() ) as $line_edit ) {
			if ( is_array( $line_edit ) && '' !== (string) ( $line_edit['key'] ?? '' ) ) {
				$description_overrides[ (string) $line_edit['key'] ] = (string) ( $line_edit['description'] ?? '' );
			}
		}
		foreach ( $line_items as $line_index => $line_item ) {
			$edit_key = (string) ( $line_item['edit_key'] ?? '' );
			if ( array_key_exists( $edit_key, $description_overrides ) ) {
				$line_items[ $line_index ]['description'] = $description_overrides[ $edit_key ];
			}
		}

		$books_notes = array_key_exists( 'notes', $books_edits )
			? (string) $books_edits['notes']
			: $this->books_notes( $customer, $view, $template_meta );
		$books_terms = array_key_exists( 'terms', $books_edits )
			? (string) $books_edits['terms']
			: $this->books_terms( $view );
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
					'notes'            => $books_notes,
					'terms'            => $books_terms,
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

	private function books_edit_key( int $system_number, string $slot, int $index = 0 ): string {
		return sanitize_key( sprintf( 'system_%d_%s_%d', $system_number, $slot, $index ) );
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

		if ( ! empty( $view['proposal_notes'] ) && is_array( $view['proposal_notes'] ) ) {
			$parts[] = '<p><strong>Proposal Notes</strong></p>';
			$parts[] = '<ul>';
			foreach ( $view['proposal_notes'] as $note ) {
				if ( is_array( $note ) && '' !== trim( (string) ( $note['text'] ?? '' ) ) ) {
					$parts[] = sprintf( '<li>%s</li>', esc_html( (string) $note['text'] ) );
				}
			}
			$parts[] = '</ul>';
		}

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

	private function books_terms( array $view ): string {
		$terms = array(
			'This proposal is valid for 30 days from the estimate date.',
			'Pricing is subject to equipment availability at the time of acceptance.',
			'Customer approval through Zoho Books authorizes Temp Control to proceed with the proposed work.',
		);
		foreach ( (array) ( $view['proposal_notes'] ?? array() ) as $note ) {
			if ( is_array( $note ) && '' !== trim( (string) ( $note['text'] ?? '' ) ) ) {
				$terms[] = (string) $note['text'];
			}
		}
		return implode( "\n", $terms );
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

}
