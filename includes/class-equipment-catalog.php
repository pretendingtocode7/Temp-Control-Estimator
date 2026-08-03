<?php
/**
 * Equipment catalog — reads eligible items from Zoho Books.
 *
 * Zoho Books is the catalog authority because its item IDs are the IDs required on
 * Books estimate line items. Only active items whose cf_for_estimate checkbox is true
 * are ever exposed to the picker or accepted during estimate generation.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Equipment_Catalog {

	private static ?Equipment_Catalog $instance = null;

	private const MAX_PAGES = 50;
	private const PER_PAGE  = 200;

	public const TYPES = array(
		'furnace', 'condenser', 'evaporator_coil', 'air_handler', 'thermostat',
		'humidifier', 'uv_purifier', 'water_heater', 'filter', 'part', 'other',
	);

	public static function instance(): Equipment_Catalog {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Search eligible Books items. The full filtered catalog is cached for 15 minutes,
	 * then type, brand, and text filters are applied locally.
	 *
	 * @param array{type?:string, q?:string, brand?:string, limit?:int} $args
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function search( array $args = array() ): array|WP_Error {
		$type  = isset( $args['type'] ) ? sanitize_key( (string) $args['type'] ) : '';
		$q     = isset( $args['q'] ) ? sanitize_text_field( (string) $args['q'] ) : '';
		$brand = isset( $args['brand'] ) ? sanitize_text_field( (string) $args['brand'] ) : '';
		$limit = max( 1, min( 100, (int) ( $args['limit'] ?? 50 ) ) );

		if ( '' !== $type && ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'tc_estimate_bad_type', __( 'Unknown equipment type.', 'tc-estimate' ), array( 'status' => 400 ) );
		}

		$items = $this->eligible_items();
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$matches = array();
		foreach ( $items as $entry ) {
			if ( '' !== $type && '' !== $entry['equipment_type'] && in_array( $entry['equipment_type'], self::TYPES, true ) && $entry['equipment_type'] !== $type ) {
				continue;
			}
			if ( '' !== $type && ( '' === $entry['equipment_type'] || ! in_array( $entry['equipment_type'], self::TYPES, true ) ) ) {
				$entry['equipment_type'] = $type;
			}
			if ( '' !== $brand && 0 !== strcasecmp( $entry['brand'], $brand ) ) {
				continue;
			}
			if ( '' !== $q ) {
				$haystack = implode( ' ', array(
					$entry['name'],
					$entry['sku'],
					$entry['brand'],
					$entry['model'],
					$entry['short_description'],
				) );
				if ( false === stripos( $haystack, $q ) ) {
					continue;
				}
			}
			$matches[] = $entry;
			if ( count( $matches ) >= $limit ) {
				break;
			}
		}

		return $matches;
	}

	/**
	 * Fetch a single Books item and enforce the same eligibility rule used by search().
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_item( string $item_id ): array|WP_Error {
		$item_id = trim( $item_id );
		if ( ! preg_match( '/^[0-9]{10,20}$/', $item_id ) ) {
			return new WP_Error( 'tc_estimate_bad_id', __( 'Invalid Zoho Books item ID.', 'tc-estimate' ), array( 'status' => 400 ) );
		}

		$org_id = $this->organization_id();
		if ( is_wp_error( $org_id ) ) {
			return $org_id;
		}

		$cache_key = 'books_item_for_estimate_' . $org_id . '_' . $item_id;
		$cached    = Zoho_Cache::instance()->get( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = Zoho_API::instance()->get(
			Zoho_API::SERVICE_BOOKS,
			'/items/' . $item_id,
			array( 'organization_id' => $org_id )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$item = isset( $response['item'] ) && is_array( $response['item'] ) ? $response['item'] : null;
		if ( null === $item ) {
			return new WP_Error( 'tc_estimate_not_found', __( 'Books item not found.', 'tc-estimate' ), array( 'status' => 404 ) );
		}

		$normalized = $this->normalize_item( $item );
		if ( ! $normalized['for_estimate'] || 'inactive' === strtolower( (string) ( $item['status'] ?? 'active' ) ) ) {
			return new WP_Error(
				'tc_estimate_item_not_eligible',
				__( 'This Books item is not enabled for estimates.', 'tc-estimate' ),
				array( 'status' => 400 )
			);
		}

		Zoho_Cache::instance()->set( $cache_key, $normalized, TC_ESTIMATE_CACHE_TTL_CATALOG );
		return $normalized;
	}

	/**
	 * Load all active Books items and retain only cf_for_estimate=true records.
	 *
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function eligible_items(): array|WP_Error {
		$org_id = $this->organization_id();
		if ( is_wp_error( $org_id ) ) {
			return $org_id;
		}

		$cache_key = 'books_items_for_estimate_' . $org_id;
		$cached    = Zoho_Cache::instance()->get( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$eligible = array();
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$response = Zoho_API::instance()->get(
				Zoho_API::SERVICE_BOOKS,
				'/items',
				array(
					'organization_id' => $org_id,
					'filter_by'       => 'Status.Active',
					'page'            => $page,
					'per_page'        => self::PER_PAGE,
					'sort_column'     => 'name',
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$items = isset( $response['items'] ) && is_array( $response['items'] ) ? $response['items'] : array();
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$normalized = $this->normalize_item( $item );
				if ( $normalized['for_estimate'] ) {
					$eligible[] = $normalized;
				}
			}

			$has_more = ! empty( $response['page_context']['has_more_page'] );
			if ( ! $has_more ) {
				break;
			}
		}

		Zoho_Cache::instance()->set( $cache_key, $eligible, TC_ESTIMATE_CACHE_TTL_CATALOG );
		return $eligible;
	}

	private function organization_id(): string|WP_Error {
		$org_id = trim( (string) get_option( 'tc_estimate_zoho_org_id', '' ) );
		if ( '' === $org_id || ! preg_match( '/^[0-9]{5,20}$/', $org_id ) ) {
			return new WP_Error(
				'tc_estimate_zoho_not_configured',
				__( 'Zoho Books organization ID is not configured.', 'tc-estimate' ),
				array( 'status' => 500 )
			);
		}
		return $org_id;
	}

	/**
	 * Convert a Books item into the normalized catalog entry used by the app.
	 *
	 * Books exposes custom fields inline as cf_* keys in most organizations, and may
	 * also return them in custom_fields. Both shapes are supported.
	 *
	 * @return array<string,mixed>
	 */
	private function normalize_item( array $item ): array {
		$cf    = $this->extract_custom_fields( $item );
		$name  = (string) $this->first_value( $item, $cf, array( 'name', 'Name' ), '' );
		$sku   = (string) $this->first_value( $item, $cf, array( 'sku', 'SKU', 'JS_Part_Number', 'Mfg_Part_Number' ), '' );
		$brand = (string) $this->first_value( $item, $cf, array( 'cf_brand', 'brand', 'Brand', 'manufacturer', 'Manufacturer' ), '' );
		$model = (string) $this->first_value( $item, $cf, array( 'cf_model', 'model', 'Model', 'Mfg_Part_Number', 'JS_Part_Number', 'sku', 'SKU' ), '' );
		$type  = $this->normalize_type( (string) $this->first_value( $item, $cf, array( 'cf_equipment_type', 'equipment_type', 'Equipment_Type', 'Type' ), '' ) );
		$desc  = (string) $this->first_value( $item, $cf, array( 'description', 'Description', 'cf_short_description', 'short_description', 'Short_Description' ), '' );

		return array(
			'item_id'                => (string) ( $item['item_id'] ?? $item['id'] ?? '' ),
			'name'                   => $name,
			'sku'                    => $sku,
			'rate'                   => (float) $this->first_value( $item, $cf, array( 'rate', 'Rate', 'sales_rate', 'Sales_Price' ), 0 ),
			'stock_on_hand'          => (float) $this->first_value( $item, $cf, array( 'stock_on_hand', 'available_stock', 'Qty_in_Stock' ), 0 ),
			'equipment_type'         => $type,
			'brand'                  => $brand,
			'model'                  => $model,
			'afue'                   => $this->nullable_float( $this->first_value( $item, $cf, array( 'cf_afue', 'afue', 'AFUE', 'AFUE_Percent' ), null ) ),
			'seer'                   => $this->nullable_float( $this->first_value( $item, $cf, array( 'cf_seer', 'seer', 'SEER', 'SEER_Rating' ), null ) ),
			'tons'                   => $this->nullable_float( $this->first_value( $item, $cf, array( 'cf_tons', 'tons', 'Tons' ), null ) ),
			'btu_input'              => $this->nullable_int( $this->first_value( $item, $cf, array( 'cf_btu_input', 'btu_input', 'BTU_Input' ), null ) ),
			'btu_output'             => $this->nullable_int( $this->first_value( $item, $cf, array( 'cf_btu_output', 'btu_output', 'BTU_Output' ), null ) ),
			'stages'                 => (string) $this->first_value( $item, $cf, array( 'cf_stages', 'stages', 'Stages' ), '' ),
			'refrigerant'            => (string) $this->first_value( $item, $cf, array( 'cf_refrigerant', 'refrigerant', 'Refrigerant' ), '' ),
			'width_inches'           => $this->nullable_float( $this->first_value( $item, $cf, array( 'cf_width', 'width_inches', 'Width' ), null ) ),
			'warranty_parts_years'   => $this->nullable_int( $this->first_value( $item, $cf, array( 'cf_warranty_parts_years', 'warranty_parts_years', 'Warranty_Parts_Years' ), null ) ),
			'warranty_labor_years'   => $this->nullable_int( $this->first_value( $item, $cf, array( 'cf_warranty_labor_years', 'warranty_labor_years', 'Warranty_Labor_Years' ), null ) ),
			'short_description'      => $desc,
			'long_description'       => $desc,
			'purchase_cost'          => $this->nullable_float( $this->first_value( $item, $cf, array( 'purchase_rate', 'Purchase_Cost' ), null ) ),
			'markup'                 => $this->nullable_float( $this->first_value( $item, $cf, array( 'cf_markup', 'markup', 'Markup' ), null ) ),
			'js_part_number'         => (string) $this->first_value( $item, $cf, array( 'cf_js_part_number', 'js_part_number', 'JS_Part_Number', 'sku' ), '' ),
			'mfg_part_number'        => (string) $this->first_value( $item, $cf, array( 'cf_mfg_part_number', 'mfg_part_number', 'Mfg_Part_Number' ), '' ),
			'for_estimate'           => $this->truthy( $this->first_value( $item, $cf, array( 'cf_for_estimate', 'for_estimate', 'For_Estimate', 'For Estimate' ), false ) ),
			'source'                 => 'zoho_books',
		);
	}

	/**
	 * Pull custom fields from inline cf_* keys and from the custom_fields array.
	 *
	 * @return array<string,mixed>
	 */
	private function extract_custom_fields( array $item ): array {
		$map = array();
		foreach ( $item as $key => $value ) {
			if ( ! is_string( $key ) || ! str_starts_with( strtolower( $key ), 'cf_' ) ) {
				continue;
			}
			$map[ $key ]                    = $value;
			$map[ strtolower( $key ) ]       = $value;
			$map[ substr( strtolower( $key ), 3 ) ] = $value;
		}

		if ( isset( $item['custom_fields'] ) && is_array( $item['custom_fields'] ) ) {
			foreach ( $item['custom_fields'] as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$name = (string) ( $field['api_name'] ?? $field['placeholder'] ?? $field['label'] ?? '' );
				if ( '' === $name ) {
					continue;
				}
				$value = $field['value'] ?? null;
				$key   = strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '_', trim( $name ) ) ?? '' );
				$map[ $name ] = $value;
				$map[ $key ]  = $value;
				if ( str_starts_with( $key, 'cf_' ) ) {
					$map[ substr( $key, 3 ) ] = $value;
				}
			}
		}

		return $map;
	}

	private function first_value( array $item, array $cf, array $keys, mixed $default ): mixed {
		foreach ( $keys as $key ) {
			$lower = strtolower( (string) $key );
			foreach ( array( $key, $lower ) as $candidate ) {
				if ( array_key_exists( $candidate, $item ) && null !== $item[ $candidate ] && '' !== $item[ $candidate ] ) {
					return $item[ $candidate ];
				}
				if ( array_key_exists( $candidate, $cf ) && null !== $cf[ $candidate ] && '' !== $cf[ $candidate ] ) {
					return $cf[ $candidate ];
				}
			}
		}
		return $default;
	}

	private function normalize_type( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', $value ), '_' );
		$aliases = array(
			'gas_furnace'      => 'furnace',
			'furnaces'         => 'furnace',
			'ac'               => 'condenser',
			'air_conditioner'  => 'condenser',
			'air_conditioning' => 'condenser',
			'coil'             => 'evaporator_coil',
			'evap_coil'        => 'evaporator_coil',
			'parts'            => 'part',
			'misc_part'        => 'part',
		);
		return $aliases[ $value ] ?? $value;
	}

	private function truthy( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 1 === (int) $value;
		}
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on', 'checked', 'enabled' ), true );
	}

	private function nullable_int( mixed $value ): ?int {
		return null === $value || '' === $value ? null : (int) $value;
	}

	private function nullable_float( mixed $value ): ?float {
		return null === $value || '' === $value ? null : (float) $value;
	}
}
