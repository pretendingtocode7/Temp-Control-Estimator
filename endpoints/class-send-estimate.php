<?php
/**
 * POST /tc-estimate/v1/send-estimate
 *
 * Sends a previously-created Books estimate only after the technician explicitly
 * approves it. The estimate must belong to a successful generation audit row for
 * the current user, and a successful send is replay-safe.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Endpoints;

use TempControl\Estimate\Audit_Log;
use TempControl\Estimate\Customer_Search;
use TempControl\Estimate\Rate_Limiter;
use TempControl\Estimate\Security;
use TempControl\Estimate\Zoho_API;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class Send_Estimate extends Endpoint_Base {

	private static ?Send_Estimate $instance = null;

	public static function instance(): Send_Estimate {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		register_rest_route( TC_ESTIMATE_REST_NS, '/send-estimate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle' ),
			'permission_callback' => array( $this, 'permission_check' ),
		) );
	}

	public function handle( WP_REST_Request $request ) {
		$limited = Rate_Limiter::instance()->consume( 'send_estimate' );
		if ( is_wp_error( $limited ) ) {
			return $this->fail( $limited );
		}

		$body = $this->body( $request );
		if ( is_wp_error( $body ) ) {
			return $this->fail( $body );
		}

		$estimate_id = Security::instance()->sanitize_zoho_id( (string) ( $body['estimate_id'] ?? '' ) );
		if ( '' === $estimate_id ) {
			return $this->fail( new WP_Error(
				'tc_estimate_bad_estimate_id',
				__( 'A valid estimate_id is required.', 'tc-estimate' ),
				array( 'status' => 400 )
			) );
		}

		$user_id  = get_current_user_id();
		$generate = Audit_Log::instance()->find_by_estimate_action( $estimate_id, 'generate', $user_id );
		if ( null === $generate || 'success' !== (string) ( $generate['status'] ?? '' ) ) {
			return $this->fail( new WP_Error(
				'tc_estimate_send_not_allowed',
				__( 'This estimate was not generated successfully by your account.', 'tc-estimate' ),
				array( 'status' => 403 )
			) );
		}

		$previous_send = Audit_Log::instance()->find_by_estimate_action( $estimate_id, 'send', $user_id );
		if ( null !== $previous_send && 'success' === (string) ( $previous_send['status'] ?? '' ) ) {
			return $this->ok( array(
				'estimate_id' => $estimate_id,
				'sent'        => true,
				'replayed'    => true,
				'message'     => __( 'This estimate was already emailed to the customer.', 'tc-estimate' ),
			) );
		}
		if ( null !== $previous_send && 'pending' === (string) ( $previous_send['status'] ?? '' ) ) {
			$created_at = strtotime( (string) ( $previous_send['created_at'] ?? '' ) . ' UTC' );
			if ( false !== $created_at && time() - $created_at < 90 ) {
				return $this->fail( new WP_Error(
					'tc_estimate_send_in_flight',
					__( 'This estimate email is already being sent. Wait a moment and try again.', 'tc-estimate' ),
					array( 'status' => 409, 'retry_after' => 5 )
				) );
			}
		}

		$account_id = (string) ( $generate['zoho_account_id'] ?? '' );
		$customer   = Customer_Search::instance()->get_account( $account_id );
		if ( is_wp_error( $customer ) ) {
			return $this->fail( $customer );
		}

		$email = sanitize_email( (string) ( $customer['email'] ?? '' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return $this->fail( new WP_Error(
				'tc_estimate_customer_email_missing',
				__( 'The CRM account does not have a valid customer email address.', 'tc-estimate' ),
				array( 'status' => 400 )
			) );
		}

		$org_id = trim( (string) get_option( 'tc_estimate_zoho_org_id', '' ) );
		if ( '' === $org_id ) {
			return $this->fail( new WP_Error(
				'tc_estimate_zoho_not_configured',
				__( 'Zoho Books organization ID is not configured.', 'tc-estimate' ),
				array( 'status' => 500 )
			) );
		}

		$audit_id = Audit_Log::instance()->record( array(
			'action'           => 'send',
			'status'           => 'pending',
			'template_id'      => (int) ( $generate['template_id'] ?? 0 ),
			'template_version' => (int) ( $generate['template_version'] ?? 0 ),
			'zoho_account_id'  => $account_id,
			'zoho_estimate_id' => $estimate_id,
			'zoho_deal_id'     => (string) ( $generate['zoho_deal_id'] ?? '' ),
			'payload'          => array( 'recipient' => $email ),
		) );

		$name = trim( (string) ( $customer['name'] ?? '' ) );
		$body_text = sprintf(
			"Hello%s,\n\nYour Temp Control estimate is attached and ready for your review and approval.\n\nThank you,\nTemp Control Heating & Air Conditioning",
			'' !== $name ? ' ' . $name : ''
		);

		$result = Zoho_API::instance()->post(
			Zoho_API::SERVICE_BOOKS,
			'/estimates/' . $estimate_id . '/email?organization_id=' . rawurlencode( $org_id ),
			array(
				'send_from_org_email_id' => true,
				'to_mail_ids'             => array( $email ),
				'subject'                 => __( 'Your Temp Control Estimate', 'tc-estimate' ),
				'body'                    => nl2br( esc_html( $body_text ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			Audit_Log::instance()->update( $audit_id, array(
				'status'        => 'error',
				'error_message' => $result->get_error_message(),
			) );
			return $this->fail( $result );
		}

		if ( isset( $result['code'] ) && 0 !== (int) $result['code'] ) {
			$error = new WP_Error(
				'tc_estimate_books_email_failed',
				(string) ( $result['message'] ?? __( 'Zoho Books did not send the estimate email.', 'tc-estimate' ) ),
				array( 'status' => 502, 'zoho_response' => $result )
			);
			Audit_Log::instance()->update( $audit_id, array(
				'status'        => 'error',
				'error_message' => $error->get_error_message(),
			) );
			return $this->fail( $error );
		}

		Audit_Log::instance()->update( $audit_id, array( 'status' => 'success' ) );

		return $this->ok( array(
			'estimate_id' => $estimate_id,
			'sent'        => true,
			'replayed'    => false,
			'message'     => sprintf( __( 'Estimate emailed to %s.', 'tc-estimate' ), $email ),
		) );
	}
}
