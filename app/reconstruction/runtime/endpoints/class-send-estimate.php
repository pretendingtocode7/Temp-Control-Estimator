<?php
/**
 * Queued customer-email endpoints.
 *
 * The browser uploads the polished proposal to WordPress, then WordPress returns
 * immediately and sends the branded email from a background cron request. Zoho
 * Books remains the draft/signing system, but it does not compose or send email.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Endpoints;

use TempControl\Estimate\Audit_Log;
use TempControl\Estimate\Customer_Search;
use TempControl\Estimate\Customer_Choices;
use TempControl\Estimate\Rate_Limiter;
use TempControl\Estimate\Security;
use TempControl\Estimate\Zoho_API;
use Throwable;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class Send_Estimate extends Endpoint_Base {

	private const JOB_TTL = 3600;

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

		register_rest_route( TC_ESTIMATE_REST_NS, '/send-status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'status' ),
			'permission_callback' => array( $this, 'permission_check' ),
			'args'                => array(
				'job_id' => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				),
			),
		) );
	}

	/**
	 * Validate the request, persist the PDF briefly, and queue the website email.
	 */
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
				'status'      => 'sent',
				'sent'        => true,
				'replayed'    => true,
				'message'     => __( 'This estimate was already emailed to the customer.', 'tc-estimate' ),
			) );
		}

		$existing = $this->existing_job( $previous_send, $user_id );
		if ( null !== $existing && in_array( (string) ( $existing['status'] ?? '' ), array( 'queued', 'processing' ), true ) ) {
			$this->kick_cron();
			return $this->ok( $this->public_job( $existing ), 202 );
		}

		if ( null !== $previous_send && 'pending' === (string) ( $previous_send['status'] ?? '' ) && null === $existing ) {
			$created_at = strtotime( (string) ( $previous_send['created_at'] ?? '' ) . ' UTC' );
			if ( false !== $created_at && time() - $created_at < 90 ) {
				return $this->fail( new WP_Error(
					'tc_estimate_send_in_flight',
					__( 'This estimate email is already being sent. Wait a moment and try again.', 'tc-estimate' ),
					array( 'status' => 409, 'retry_after' => 5 )
				) );
			}
		}

		$pretty_attachment = $this->pretty_attachment( $body );
		if ( is_wp_error( $pretty_attachment ) ) {
			return $this->fail( $pretty_attachment );
		}

		$temp_base = wp_tempnam( $pretty_attachment['filename'] );
		$temp_path = is_string( $temp_base ) && '' !== $temp_base ? $temp_base . '.pdf' : '';
		if ( '' === $temp_path || ! @rename( $temp_base, $temp_path ) || false === file_put_contents( $temp_path, $pretty_attachment['contents'], LOCK_EX ) ) {
			if ( is_string( $temp_base ) && '' !== $temp_base && file_exists( $temp_base ) ) {
				wp_delete_file( $temp_base );
			}
			if ( is_string( $temp_path ) && '' !== $temp_path && file_exists( $temp_path ) ) {
				wp_delete_file( $temp_path );
			}
			return $this->fail( new WP_Error(
				'tc_estimate_attachment_store_failed',
				__( 'WordPress could not temporarily store the polished proposal. Please try again.', 'tc-estimate' ),
				array( 'status' => 500 )
			) );
		}
		@chmod( $temp_path, 0600 );

		$job_id   = strtolower( wp_generate_uuid4() );
		$audit_id = Audit_Log::instance()->record( array(
			'action'           => 'send',
			'status'           => 'pending',
			'template_id'      => (int) ( $generate['template_id'] ?? 0 ),
			'template_version' => (int) ( $generate['template_version'] ?? 0 ),
			'zoho_account_id'  => (string) ( $generate['zoho_account_id'] ?? '' ),
			'zoho_estimate_id' => $estimate_id,
			'zoho_deal_id'     => (string) ( $generate['zoho_deal_id'] ?? '' ),
			'payload'          => array(
				'job_id'             => $job_id,
				'pretty_attachment'  => true,
				'attachment_bytes'   => strlen( $pretty_attachment['contents'] ),
				'background_queued'  => true,
			),
		) );

		if ( $audit_id <= 0 ) {
			wp_delete_file( $temp_path );
			return $this->fail( new WP_Error(
				'tc_estimate_send_audit_failed',
				__( 'The email send could not be queued safely. No email was sent.', 'tc-estimate' ),
				array( 'status' => 500 )
			) );
		}

		$job = array(
			'job_id'          => $job_id,
			'status'          => 'queued',
			'user_id'         => $user_id,
			'estimate_id'     => $estimate_id,
			'account_id'      => (string) ( $generate['zoho_account_id'] ?? '' ),
			'audit_id'        => $audit_id,
			'attachment_path' => $temp_path,
			'filename'        => $pretty_attachment['filename'],
			'proposal_html'   => $pretty_attachment['html'],
			'created_at'      => time(),
			'message'         => __( 'The polished proposal is queued for email.', 'tc-estimate' ),
		);
		$this->save_job( $job );

		$scheduled = wp_schedule_single_event( time(), 'tc_estimate_process_send', array( $job_id ), true );
		if ( is_wp_error( $scheduled ) || false === $scheduled ) {
			$error = is_wp_error( $scheduled )
				? $scheduled
				: new WP_Error( 'tc_estimate_send_schedule_failed', __( 'WordPress could not start the background email job.', 'tc-estimate' ) );
			$this->fail_job( $job, $error );
			return $this->fail( new WP_Error(
				'tc_estimate_send_schedule_failed',
				__( 'The email could not be queued. No email was sent.', 'tc-estimate' ),
				array( 'status' => 500 )
			) );
		}

		$this->kick_cron();

		return $this->ok( $this->public_job( $job ), 202 );
	}

	/**
	 * Report a queued job to its owning user. The UI polls this route while WordPress sends.
	 */
	public function status( WP_REST_Request $request ) {
		$job_id = sanitize_key( (string) $request->get_param( 'job_id' ) );
		$job    = $this->get_job( $job_id );
		if ( null === $job ) {
			return $this->fail( new WP_Error(
				'tc_estimate_send_job_missing',
				__( 'This email job has expired or could not be found. Check the estimate in Zoho Books before trying again.', 'tc-estimate' ),
				array( 'status' => 404 )
			) );
		}

		if ( get_current_user_id() !== (int) ( $job['user_id'] ?? 0 ) ) {
			return $this->fail( new WP_Error(
				'tc_estimate_send_job_forbidden',
				__( 'You cannot view this email job.', 'tc-estimate' ),
				array( 'status' => 403 )
			) );
		}

		if ( 'queued' === (string) ( $job['status'] ?? '' ) ) {
			if ( ! wp_next_scheduled( 'tc_estimate_process_send', array( $job_id ) ) ) {
				wp_schedule_single_event( time(), 'tc_estimate_process_send', array( $job_id ) );
			}
			$this->kick_cron();
		}

		return $this->ok( $this->public_job( $job ) );
	}

	/**
	 * WP-Cron callback. Runs outside the browser's Cloudflare-held request.
	 */
	public function process_queued_send( string $job_id ): void {
		// This runs in a detached cron request. Give Zoho's multipart upload room to
		// finish even when the normal front-end PHP execution limit is shorter.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}
		@ignore_user_abort( true );

		$job_id = sanitize_key( $job_id );
		$job    = $this->get_job( $job_id );
		if ( null === $job || 'queued' !== (string) ( $job['status'] ?? '' ) ) {
			return;
		}

		$lock_key = $this->lock_key( $job_id );
		if ( ! add_option( $lock_key, time(), '', false ) ) {
			return;
		}

		$job['status']     = 'processing';
		$job['started_at'] = time();
		$job['message']    = __( 'Temp Control is preparing and sending the polished email.', 'tc-estimate' );
		$this->save_job( $job );

		try {
			$this->send_job( $job );
		} catch ( Throwable $throwable ) {
			$this->fail_job( $job, new WP_Error(
				'tc_estimate_send_background_failed',
				$throwable->getMessage() ?: __( 'The background email job stopped unexpectedly.', 'tc-estimate' )
			) );
		} finally {
			delete_option( $lock_key );
		}
	}

	/**
	 * Send the branded WordPress email for a queued job.
	 */
	private function send_job( array $job ): void {
		$estimate_id = Security::instance()->sanitize_zoho_id( (string) ( $job['estimate_id'] ?? '' ) );
		$audit_id    = (int) ( $job['audit_id'] ?? 0 );
		$temp_path   = (string) ( $job['attachment_path'] ?? '' );
		$contents    = '' !== $temp_path && is_readable( $temp_path ) ? file_get_contents( $temp_path ) : false;
		if ( false === $contents || ! str_starts_with( $contents, '%PDF-' ) ) {
			$this->fail_job( $job, new WP_Error( 'tc_estimate_attachment_missing', __( 'The temporary polished proposal file was unavailable. No email was sent.', 'tc-estimate' ) ) );
			return;
		}

		$org_id = trim( (string) get_option( 'tc_estimate_zoho_org_id', '' ) );
		if ( '' === $org_id ) {
			$this->fail_job( $job, new WP_Error( 'tc_estimate_zoho_not_configured', __( 'Zoho Books organization ID is not configured.', 'tc-estimate' ) ) );
			return;
		}

		$customer = Customer_Search::instance()->get_account( (string) ( $job['account_id'] ?? '' ) );
		if ( is_wp_error( $customer ) ) {
			$this->fail_job( $job, $customer );
			return;
		}
		$email = sanitize_email( (string) ( $customer['email'] ?? '' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			$this->fail_job( $job, new WP_Error( 'tc_estimate_customer_email_missing', __( 'The CRM account does not have a valid customer email address.', 'tc-estimate' ) ) );
			return;
		}

		$api      = Zoho_API::instance();
		$estimate = $api->get( Zoho_API::SERVICE_BOOKS, '/estimates/' . $estimate_id, array( 'organization_id' => $org_id ) );
		if ( is_wp_error( $estimate ) ) {
			$this->fail_job( $job, $estimate );
			return;
		}
		$books_status = strtolower( (string) ( $estimate['estimate']['status'] ?? '' ) );
		if ( 'draft' !== $books_status ) {
			if ( in_array( $books_status, array( 'sent', 'accepted', 'declined', 'invoiced' ), true ) ) {
				Audit_Log::instance()->update( $audit_id, array( 'status' => 'success' ) );
				$this->complete_job( $job, sprintf( __( 'The Books estimate already has status %s, so no duplicate email was sent.', 'tc-estimate' ), $books_status ) );
				return;
			}
			$this->fail_job( $job, new WP_Error(
				'tc_estimate_not_draft',
				sprintf( __( 'This Books estimate has status %s and will not be emailed from the builder.', 'tc-estimate' ), '' !== $books_status ? $books_status : __( 'unknown', 'tc-estimate' ) )
			) );
			return;
		}

		$estimate_data = isset( $estimate['estimate'] ) && is_array( $estimate['estimate'] ) ? $estimate['estimate'] : array();
		// The scoped choice link updates this existing estimate; it never accepts it.
		$choice_link = Customer_Choices::instance()->link_for_estimate( $estimate_id, (int) ( $job['user_id'] ?? 0 ) );
		if ( is_wp_error( $choice_link ) ) {
			$this->fail_job( $job, $choice_link );
			return;
		}
		$has_choices = true === ( $choice_link['available'] ?? false );
		$review_url = $has_choices ? (string) ( $choice_link['url'] ?? '' ) : $this->customer_review_url( $estimate_data );
		if ( $has_choices && '' === $review_url ) {
			$this->fail_job( $job, new WP_Error( 'tc_estimate_choice_link_missing', __( 'The customer package-selection link is unavailable. No email was sent.', 'tc-estimate' ) ) );
			return;
		}
		$subject       = sanitize_text_field( sprintf(
			__( 'Your Temp Control Estimate%s', 'tc-estimate' ),
			! empty( $estimate_data['estimate_number'] ) ? ' #' . (string) $estimate_data['estimate_number'] : ''
		) );
		$body          = $this->branded_email_body(
			(string) ( $customer['name'] ?? '' ),
			$review_url,
			(string) ( $job['proposal_html'] ?? '' ),
			$has_choices
		);
		$headers       = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: Temp Control Heating & Air Conditioning <info@tempcontrolnj.com>',
			'Reply-To: Temp Control Heating & Air Conditioning <info@tempcontrolnj.com>',
		);

		if ( ! wp_mail( $email, $subject, $body, $headers, array( $temp_path ) ) ) {
			$this->fail_job( $job, new WP_Error(
				'tc_estimate_wordpress_email_failed',
				__( 'The polished email could not be sent by the website. Check the site mail/SMTP configuration; Zoho Books did not send an email.', 'tc-estimate' )
			) );
			return;
		}

		// Keep Books in sync without invoking its email endpoint. Failure here must
		// not turn a successfully delivered email into a retry/duplicate-send risk.
		$api->post(
			Zoho_API::SERVICE_BOOKS,
			'/estimates/' . $estimate_id . '/status/sent?organization_id=' . rawurlencode( $org_id ),
			array()
		);

		Audit_Log::instance()->update( $audit_id, array( 'status' => 'success' ) );
		$this->complete_job( $job, sprintf( __( 'Temp Control polished proposal emailed to %s. Zoho Books did not send the message.', 'tc-estimate' ), $email ) );
	}

	/**
	 * Find a public customer review URL in the Books estimate response.
	 */
	private function customer_review_url( array $estimate ): string {
		$preferred_keys = array( 'estimate_url', 'customer_url', 'public_url', 'accept_url', 'portal_url', 'share_link' );
		foreach ( $preferred_keys as $key ) {
			$url = esc_url_raw( (string) ( $estimate[ $key ] ?? '' ) );
			if ( '' !== $url && ! str_contains( $url, '/app/#/' ) ) {
				return $url;
			}
		}
		return '';
	}

	private function branded_email_body( string $customer_name, string $review_url, string $proposal_html, bool $has_choices = false ): string {
		$name       = '' !== trim( $customer_name ) ? esc_html( $customer_name ) : __( 'Customer', 'tc-estimate' );
		$button_label = $has_choices ? __( 'Choose packages and update estimate', 'tc-estimate' ) : __( 'Review and approve estimate', 'tc-estimate' );
		$button     = '' !== $review_url
			? '<p style="margin:28px 0 10px"><a href="' . esc_url( $review_url ) . '" style="display:inline-block;background:#e85d04;color:#fff;text-decoration:none;font-weight:700;padding:14px 24px;border-radius:5px">' . esc_html( $button_label ) . '</a></p>'
				. '<p style="font-size:13px;color:#5b6570;margin:0 0 28px">If the button does not open, copy this link into your browser:<br><a href="' . esc_url( $review_url ) . '" style="color:#17324d;word-break:break-all">' . esc_html( $review_url ) . '</a></p>'
			: '';
		$proposal   = '' !== trim( $proposal_html )
			? '<div style="margin:26px 0;border-top:1px solid #d9dee3;padding-top:26px">' . wp_kses_post( $proposal_html ) . '</div>'
			: '';
		$introduction = $has_choices
			? '<p>Your package options are included below and attached as a comparison proposal. Choose one package for each system using the button below. Your existing estimate in Zoho Books will be updated so you can review and approve the final selection there. The attached comparison stays as originally prepared; the updated Books estimate contains your final choices.</p>'
			: '<p>Your Temp Control estimate is included below and attached as a PDF for downloading or printing. Please review the proposal and contact us with any questions.</p>';

		return '<!doctype html><html><body style="margin:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2933">'
			. '<div style="max-width:640px;margin:0 auto;padding:24px"><div style="background:#17324d;padding:24px 30px;color:#fff;font-size:25px;font-weight:700">Temp Control Heating &amp; Air Conditioning</div>'
			. '<div style="background:#fff;padding:32px 30px;border:1px solid #e5e7eb"><p style="font-size:18px;margin-top:0">Hello ' . $name . ',</p>'
			. $introduction
			. $button
			. $proposal
			. '<p style="margin-bottom:0">Thank you,<br><strong>Temp Control Heating &amp; Air Conditioning</strong></p></div>'
			. '<p style="color:#6b7280;font-size:12px;text-align:center">Professional heating and cooling service you can count on.</p></div></body></html>';
	}

	private function complete_job( array $job, string $message ): void {
		$job['status']      = 'sent';
		$job['sent']        = true;
		$job['finished_at'] = time();
		$job['message']     = $message;
		$this->cleanup_attachment( $job );
		unset( $job['attachment_path'] );
		$this->save_job( $job );
	}

	private function fail_job( array $job, WP_Error $error ): void {
		Audit_Log::instance()->update( (int) ( $job['audit_id'] ?? 0 ), array(
			'status'        => 'error',
			'error_message' => $error->get_error_message(),
		) );
		$job['status']      = 'error';
		$job['sent']        = false;
		$job['finished_at'] = time();
		$job['message']     = $error->get_error_message();
		$job['error_code']  = $error->get_error_code();
		$this->cleanup_attachment( $job );
		unset( $job['attachment_path'] );
		$this->save_job( $job );
	}

	private function cleanup_attachment( array $job ): void {
		$path = (string) ( $job['attachment_path'] ?? '' );
		if ( '' !== $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	private function save_job( array $job ): void {
		set_transient( $this->job_key( (string) ( $job['job_id'] ?? '' ) ), $job, self::JOB_TTL );
	}

	private function get_job( string $job_id ): ?array {
		if ( '' === $job_id ) {
			return null;
		}
		$job = get_transient( $this->job_key( $job_id ) );
		return is_array( $job ) ? $job : null;
	}

	private function existing_job( ?array $audit, int $user_id ): ?array {
		if ( null === $audit || 'pending' !== (string) ( $audit['status'] ?? '' ) ) {
			return null;
		}
		$payload = json_decode( (string) ( $audit['payload'] ?? '' ), true );
		$job_id  = is_array( $payload ) ? sanitize_key( (string) ( $payload['job_id'] ?? '' ) ) : '';
		$job     = $this->get_job( $job_id );
		if ( null === $job || $user_id !== (int) ( $job['user_id'] ?? 0 ) ) {
			return null;
		}
		return $job;
	}

	private function public_job( array $job ): array {
		return array(
			'job_id'      => (string) ( $job['job_id'] ?? '' ),
			'estimate_id' => (string) ( $job['estimate_id'] ?? '' ),
			'status'      => (string) ( $job['status'] ?? 'queued' ),
			'sent'        => (bool) ( $job['sent'] ?? false ),
			'message'     => (string) ( $job['message'] ?? '' ),
			'error_code'  => (string) ( $job['error_code'] ?? '' ),
		);
	}

	private function job_key( string $job_id ): string {
		return 'tc_estimate_send_job_' . md5( $job_id );
	}

	private function lock_key( string $job_id ): string {
		return 'tc_estimate_send_lock_' . md5( $job_id );
	}

	private function kick_cron(): void {
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron( time() );
		}
	}

	/**
	 * Validate and decode the client-rendered proposal PDF.
	 *
	 * @return array{filename:string,contents:string,html:string}|WP_Error
	 */
	private function pretty_attachment( array $body ): array|WP_Error {
		if ( empty( $body['pretty_attachment'] ) ) {
			return new WP_Error( 'tc_estimate_attachment_required', __( 'A polished proposal PDF is required before this estimate can be emailed.', 'tc-estimate' ), array( 'status' => 400 ) );
		}

		$attachment = $body['pretty_attachment'];
		if ( ! is_array( $attachment ) ) {
			return new WP_Error( 'tc_estimate_bad_attachment', __( 'The polished proposal attachment is invalid.', 'tc-estimate' ), array( 'status' => 400 ) );
		}

		$filename = sanitize_file_name( (string) ( $attachment['filename'] ?? '' ) );
		$data     = (string) ( $attachment['data'] ?? '' );
		$html     = (string) ( $attachment['html'] ?? '' );
		$html_b64 = (string) ( $attachment['html_b64'] ?? '' );
		if ( '' === trim( $html ) && '' !== $html_b64 ) {
			$decoded_html = base64_decode( $html_b64, true );
			$html = false !== $decoded_html ? $decoded_html : '';
		}
		if ( '' === $filename || ! str_ends_with( strtolower( $filename ), '.pdf' ) || '' === $data ) {
			return new WP_Error( 'tc_estimate_bad_attachment', __( 'The polished proposal must be a PDF file.', 'tc-estimate' ), array( 'status' => 400 ) );
		}

		$contents = base64_decode( $data, true );
		if ( false === $contents || ! str_starts_with( $contents, '%PDF-' ) ) {
			return new WP_Error( 'tc_estimate_bad_attachment', __( 'The polished proposal PDF could not be decoded.', 'tc-estimate' ), array( 'status' => 400 ) );
		}

		if ( strlen( $contents ) > 8 * 1024 * 1024 ) {
			return new WP_Error( 'tc_estimate_attachment_too_large', __( 'The polished proposal PDF is larger than 8 MB.', 'tc-estimate' ), array( 'status' => 413 ) );
		}
		// The PDF remains authoritative. If a security layer strips the optional
		// HTML copy, send the attachment and approval link instead of blocking email.
		$html = mb_substr( $html, 0, 250000 );

		return array(
			'filename' => $filename,
			'contents' => $contents,
			'html'     => $html,
		);
	}
}
