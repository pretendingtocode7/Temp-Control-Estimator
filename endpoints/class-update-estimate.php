<?php
/**
 * POST /tc-estimate/v1/update-estimate
 *
 * Saves technician-edited line descriptions, notes, and terms to a Books draft
 * created by the current user. Pricing and item identities are always preserved
 * from the live Books estimate.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Endpoints;

use TempControl\Estimate\Audit_Log;
use TempControl\Estimate\Rate_Limiter;
use TempControl\Estimate\Security;
use TempControl\Estimate\Zoho_API;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class Update_Estimate extends Endpoint_Base {

	private static ?Update_Estimate $instance = null;

	public static function instance(): Update_Estimate {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		register_rest_route( TC_ESTIMATE_REST_NS, '/update-estimate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle' ),
			'permission_callback' => array( $this, 'permission_check' ),
		) );
	}

	public function handle( WP_REST_Request $request ) {
		$limited = Rate_Limiter::instance()->consume( 'update_estimate' );
		if ( is_wp_error( $limited ) ) {
			return $this->fail( $limited );
		}

		$body = $this->body( $request );
		if ( is_wp_error( $body ) ) {
			return $this->fail( $body );
		}

		$estimate_id = Security::instance()->sanitize_zoho_id( (string) ( $body['estimate_id'] ?? '' ) );
		$books_edits = isset( $body['books_edits'] ) && is_array( $body['books_edits'] ) ? $body['books_edits'] : null;
		if ( '' === $estimate_id || null === $books_edits ) {
			return $this->fail( new WP_Error(
				'tc_estimate_bad_update',
				__( 'A valid estimate_id and Books edits are required.', 'tc-estimate' ),
				array( 'status' => 400 )
			) );
		}

		$user_id  = get_current_user_id();
		$generate = Audit_Log::instance()->find_by_estimate_action( $estimate_id, 'generate', $user_id );
		if ( null === $generate || 'success' !== (string) ( $generate['status'] ?? '' ) ) {
			return $this->fail( new WP_Error(
				'tc_estimate_update_not_allowed',
				__( 'This estimate was not generated successfully by your account.', 'tc-estimate' ),
				array( 'status' => 403 )
			) );
		}

		$org_id = trim( (string) get_option( 'tc_estimate_zoho_org_id', '' ) );
		if ( '' === $org_id ) {
			return $this->fail( new WP_Error( 'tc_estimate_zoho_not_configured', __( 'Zoho Books organization ID is not configured.', 'tc-estimate' ), array( 'status' => 500 ) ) );
		}

		$api      = Zoho_API::instance();
		$response = $api->get( Zoho_API::SERVICE_BOOKS, '/estimates/' . $estimate_id, array( 'organization_id' => $org_id ) );
		if ( is_wp_error( $response ) ) {
			return $this->fail( $response );
		}
		$estimate = isset( $response['estimate'] ) && is_array( $response['estimate'] ) ? $response['estimate'] : array();
		$status   = strtolower( (string) ( $estimate['status'] ?? '' ) );
		if ( 'draft' !== $status ) {
			return $this->fail( new WP_Error(
				'tc_estimate_not_draft',
				sprintf( __( 'This Books estimate has status %s and can no longer be edited from the builder.', 'tc-estimate' ), '' !== $status ? $status : __( 'unknown', 'tc-estimate' ) ),
				array( 'status' => 409 )
			) );
		}

		$current_lines = isset( $estimate['line_items'] ) && is_array( $estimate['line_items'] ) ? $estimate['line_items'] : array();
		$edited_lines  = isset( $books_edits['line_items'] ) && is_array( $books_edits['line_items'] ) ? $books_edits['line_items'] : array();
		if ( count( $current_lines ) !== count( $edited_lines ) ) {
			return $this->fail( new WP_Error(
				'tc_estimate_books_lines_changed',
				__( 'The Books line items changed after this page loaded. Reload the estimate before editing it again.', 'tc-estimate' ),
				array( 'status' => 409 )
			) );
		}

		$line_items = array();
		foreach ( $current_lines as $index => $current_line ) {
			if ( ! is_array( $current_line ) ) {
				continue;
			}
			$line = array(
				'line_item_id' => (string) ( $current_line['line_item_id'] ?? '' ),
				'name'         => (string) ( $current_line['name'] ?? '' ),
				'description'  => (string) ( $edited_lines[ $index ]['description'] ?? $current_line['description'] ?? '' ),
				'rate'         => (float) ( $current_line['rate'] ?? 0 ),
				'quantity'     => (float) ( $current_line['quantity'] ?? 1 ),
			);
			foreach ( array( 'item_id', 'tax_id', 'unit', 'location_id' ) as $optional_key ) {
				if ( '' !== trim( (string) ( $current_line[ $optional_key ] ?? '' ) ) ) {
					$line[ $optional_key ] = (string) $current_line[ $optional_key ];
				}
			}
			if ( isset( $current_line['discount'] ) ) {
				$line['discount'] = (float) $current_line['discount'];
			}
			$line_items[] = $line;
		}

		$update_body = array(
			'customer_id'            => (string) ( $estimate['customer_id'] ?? '' ),
			'line_items'             => $line_items,
			'notes'                  => (string) ( $books_edits['notes'] ?? '' ),
			'terms'                  => (string) ( $books_edits['terms'] ?? '' ),
			'adjustment'             => (float) ( $estimate['adjustment'] ?? 0 ),
			'adjustment_description' => (string) ( $estimate['adjustment_description'] ?? '' ),
		);

		$audit_id = Audit_Log::instance()->record( array(
			'action'           => 'update',
			'status'           => 'pending',
			'template_id'      => (int) ( $generate['template_id'] ?? 0 ),
			'template_version' => (int) ( $generate['template_version'] ?? 0 ),
			'zoho_account_id'  => (string) ( $generate['zoho_account_id'] ?? '' ),
			'zoho_estimate_id' => $estimate_id,
			'zoho_deal_id'     => (string) ( $generate['zoho_deal_id'] ?? '' ),
			'payload'          => array( 'books_edits' => $books_edits ),
		) );

		$updated = $api->put(
			Zoho_API::SERVICE_BOOKS,
			'/estimates/' . $estimate_id . '?organization_id=' . rawurlencode( $org_id ) . '&send=false',
			$update_body
		);
		if ( is_wp_error( $updated ) ) {
			Audit_Log::instance()->update( $audit_id, array( 'status' => 'error', 'error_message' => $updated->get_error_message() ) );
			return $this->fail( $updated );
		}
		if ( isset( $updated['code'] ) && 0 !== (int) $updated['code'] ) {
			$error = new WP_Error(
				'tc_estimate_books_update_failed',
				(string) ( $updated['message'] ?? __( 'Zoho Books could not update the estimate draft.', 'tc-estimate' ) ),
				array( 'status' => 502, 'zoho_response' => $updated )
			);
			Audit_Log::instance()->update( $audit_id, array( 'status' => 'error', 'error_message' => $error->get_error_message() ) );
			return $this->fail( $error );
		}

		Audit_Log::instance()->update( $audit_id, array( 'status' => 'success' ) );
		return $this->ok( array(
			'estimate_id' => $estimate_id,
			'saved'       => true,
			'message'     => __( 'Books descriptions, notes, and terms were updated.', 'tc-estimate' ),
		) );
	}
}
