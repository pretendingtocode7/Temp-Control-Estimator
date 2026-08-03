<?php
/**
 * Search and retrieve Zoho CRM Accounts for estimate addressees.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Customer_Search {

	private static ?Customer_Search $instance = null;

	public static function instance(): Customer_Search {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Search Accounts by name/address substring. Minimum 2 chars.
	 *
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function search( string $q, int $limit = 20 ): array|WP_Error {
		$q = trim( $q );
		if ( mb_strlen( $q ) < 2 ) {
			return array();
		}
		$limit = max( 1, min( 50, $limit ) );

		// Cache short-TTL — customer-edit latency should be low but not zero.
		return Zoho_Cache::instance()->remember(
			'customer_q_' . md5( $q ) . '_' . $limit,
			TC_ESTIMATE_CACHE_TTL_CUSTOMER,
			function () use ( $q, $limit ) {
				// Zoho CRM search-records API
				$response = Zoho_API::instance()->get(
					Zoho_API::SERVICE_CRM,
					'/Accounts/search',
					array(
						'word'     => $q,
						'per_page' => $limit,
					)
				);
				if ( is_wp_error( $response ) ) {
					// 204 / no results come back as valid empty response from our dispatcher,
					// but Zoho sometimes returns errors for empty searches — coerce to empty array.
					$data = $response->get_error_data();
					if ( is_array( $data ) && ( ( $data['status'] ?? 0 ) === 204 ) ) {
						return array();
					}
					return $response;
				}
				$accounts = $response['data'] ?? array();
				return array_map( array( $this, 'normalize' ), is_array( $accounts ) ? $accounts : array() );
			}
		);
	}

	/**
	 * Fetch a single Account by ID.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_account( string $account_id ): array|WP_Error {
		$account_id = Security::instance()->sanitize_zoho_id( $account_id );
		if ( '' === $account_id ) {
			return new WP_Error( 'tc_estimate_bad_id', __( 'Invalid account ID.', 'tc-estimate' ), array( 'status' => 400 ) );
		}

		return Zoho_Cache::instance()->remember(
			'account_' . $account_id,
			TC_ESTIMATE_CACHE_TTL_CUSTOMER,
			function () use ( $account_id ) {
				$response = Zoho_API::instance()->get( Zoho_API::SERVICE_CRM, '/Accounts/' . $account_id );
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				$rows = $response['data'] ?? array();
				if ( empty( $rows ) || ! is_array( $rows[0] ) ) {
					return new WP_Error( 'tc_estimate_not_found', __( 'Account not found.', 'tc-estimate' ), array( 'status' => 404 ) );
				}
				$account = $this->normalize( $rows[0] );
				if ( '' === trim( (string) ( $account['email'] ?? '' ) ) ) {
					$account = $this->hydrate_account_contact_email( $account, $account_id );
				}
				return $account;
			}
		);
	}

	private function normalize( array $row ): array {
		$billing = array(
			'street'  => (string) ( $row['Billing_Street']  ?? '' ),
			'city'    => (string) ( $row['Billing_City']    ?? '' ),
			'state'   => (string) ( $row['Billing_State']   ?? '' ),
			'zip'     => (string) ( $row['Billing_Code']    ?? '' ),
			'country' => (string) ( $row['Billing_Country'] ?? '' ),
		);
		return array(
			'id'              => (string) ( $row['id'] ?? '' ),
			'name'            => (string) ( $row['Account_Name'] ?? '' ),
			'phone'           => (string) ( $row['Phone'] ?? '' ),
			'email'           => (string) ( $row['Email'] ?? '' ),
			'billing_address' => $billing,
		);
	}

	private function hydrate_account_contact_email( array $account, string $account_id ): array {
		$response = Zoho_API::instance()->get(
			Zoho_API::SERVICE_CRM,
			'/Accounts/' . $account_id . '/Contacts',
			array(
				'fields'   => 'Email,Full_Name,First_Name,Last_Name',
				'per_page' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $account;
		}

		$contacts = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		foreach ( $contacts as $contact ) {
			if ( ! is_array( $contact ) ) {
				continue;
			}
			$email = strtolower( trim( (string) ( $contact['Email'] ?? '' ) ) );
			if ( '' !== $email && is_email( $email ) ) {
				$account['email'] = $email;
				return $account;
			}
		}

		return $account;
	}
}
