<?php
/**
 * Equipment catalog — reads eligible items from Zoho Books.
 *
 * Zoho Books is the catalog authority because its item IDs are the IDs required on
 * Books estimate line items. The release override requires the actual active Package
 * reporting tag's Package option; product names, checkbox fields, and composite
 * flags never grant eligibility. The saved runtime/reference remains unchanged.
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
	private const CACHE_POLICY = 'package_tag_v1';
	private const LIST_CACHE_TTL = 900;

	/** Resolved from Books once per organization during this PHP request. */
	private array $package_selections = array();

	public const TYPES = array(
		'furnace', 'condenser', 'evaporator_coil', 'air_handler', 'thermostat',
		'humidifier', 'uv_purifier', 'water_heater', 'filter', 'part', 'permit', 'other',
	);

	public static function instance(): Equipment_Catalog {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Search eligible Books items. The full filtered catalog is cached for at most 15 minutes,
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
	 * Read raw Books tags afresh. A cached picker selection must not authorize a
	 * new estimate after the Package tag or active status has been removed.
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
		$package = $this->package_selection( $org_id );
		if ( is_wp_error( $package ) ) {
			return $package;
		}

		// Deliberately do not read/write the old normalized single-item cache.
		$response = Zoho_API::instance()->get(
			Zoho_API::SERVICE_BOOKS,
			'/items/' . $item_id,
			array( 'organization_id' => $org_id )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $this->provider_failed( $response ) ) {
			return $this->invalid_catalog_response();
		}
		$item = isset( $response['item'] ) && is_array( $response['item'] ) ? $response['item'] : null;
		if ( null === $item ) {
			return new WP_Error( 'tc_estimate_not_found', __( 'Books item not found.', 'tc-estimate' ), array( 'status' => 404 ) );
		}
		if ( ( $item['item_id'] ?? null ) !== $item_id ) {
			return $this->invalid_catalog_response();
		}
		$normalized = $this->normalize_item( $item, $package );
		if ( ! $normalized['for_estimate'] ) {
			return new WP_Error(
				'tc_estimate_item_not_eligible',
				__( 'This Books item must be active and have the Package reporting tag set to Package.', 'tc-estimate' ),
				array( 'status' => 400 )
			);
		}
		return $normalized;
	}

	/**
	 * Load the active list, applying the resolved tag/option pair before caching.
	 * Errors or incomplete pagination never publish a partial catalog.
	 *
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function eligible_items(): array|WP_Error {
		$org_id = $this->organization_id();
		if ( is_wp_error( $org_id ) ) {
			return $org_id;
		}
		$package = $this->package_selection( $org_id );
		if ( is_wp_error( $package ) ) {
			return $package;
		}

		$cache_key = 'books_items_' . self::CACHE_POLICY . '_' . $org_id . '_' . $package['tag_id'] . '_' . $package['option_id'];
		$cached = Zoho_Cache::instance()->get( $cache_key );
		if ( is_array( $cached ) && $this->valid_cached_list( $cached, $package ) ) {
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
			if ( $this->provider_failed( $response ) ||
				! isset( $response['items'] ) || ! is_array( $response['items'] ) ||
				! isset( $response['page_context'] ) || ! is_array( $response['page_context'] ) ||
				! array_key_exists( 'has_more_page', $response['page_context'] ) ||
				! is_bool( $response['page_context']['has_more_page'] ) ) {
				return $this->invalid_catalog_response();
			}
			foreach ( $response['items'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$normalized = $this->normalize_item( $item, $package );
				if ( $normalized['for_estimate'] ) {
					$eligible[] = $normalized;
				}
			}

			$has_more = $response['page_context']['has_more_page'];
			if ( ! $has_more ) {
				Zoho_Cache::instance()->set( $cache_key, $eligible, min( self::LIST_CACHE_TTL, max( 1, (int) TC_ESTIMATE_CACHE_TTL_CATALOG ) ) );
				return $eligible;
			}
		}
		return new WP_Error(
			'tc_estimate_catalog_page_limit',
			__( 'The Books catalog could not be loaded completely. No partial catalog was cached.', 'tc-estimate' ),
			array( 'status' => 503 )
		);
	}

	/** Resolve the unique active Package reporting tag and its exact Package option. */
	private function package_selection( string $org_id ): array|WP_Error {
		if ( isset( $this->package_selections[ $org_id ] ) ) {
			return $this->package_selections[ $org_id ];
		}
		$api = Zoho_API::instance();
		$response = $api->get( Zoho_API::SERVICE_BOOKS, '/reportingtags', array( 'organization_id' => $org_id ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $this->provider_failed( $response ) || ! isset( $response['tags'] ) || ! is_array( $response['tags'] ) ) {
			return $this->package_configuration_error();
		}
		$tags = array_values( array_filter(
			$response['tags'],
			static fn ( $tag ): bool => is_array( $tag ) && 'Package' === ( $tag['tag_name'] ?? null ) && true === ( $tag['is_active'] ?? null )
		) );
		if ( 1 !== count( $tags ) || ! $this->valid_reporting_id( $tags[0]['tag_id'] ?? null ) ) {
			return $this->package_configuration_error();
		}
		$tag_id = $tags[0]['tag_id'];
		$response = $api->get( Zoho_API::SERVICE_BOOKS, '/reportingtags/' . $tag_id . '/options/all', array( 'organization_id' => $org_id ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $this->provider_failed( $response ) || ! isset( $response['results'] ) || ! is_array( $response['results'] ) ) {
			return $this->package_configuration_error();
		}
		$options = array_values( array_filter(
			$response['results'],
			static fn ( $option ): bool => is_array( $option ) && 'Package' === ( $option['option_name'] ?? null ) &&
				( ! array_key_exists( 'is_active', $option ) || true === $option['is_active'] )
		) );
		if ( 1 !== count( $options ) || ! $this->valid_reporting_id( $options[0]['option_id'] ?? null ) ) {
			return $this->package_configuration_error();
		}
		$selection = array( 'tag_id' => $tag_id, 'option_id' => $options[0]['option_id'] );
		$this->package_selections[ $org_id ] = $selection;
		return $selection;
	}

	/** Labels identify metadata definitions only; assigned item eligibility uses both IDs. */
	private function has_package_tag( array $item, array $package ): bool {
		if ( ! isset( $item['tags'] ) || ! is_array( $item['tags'] ) ) {
			return false;
		}
		$assigned = array_values( array_filter(
			$item['tags'],
			static fn ( $tag ): bool => is_array( $tag ) && ( $tag['tag_id'] ?? null ) === $package['tag_id']
		) );
		return 1 === count( $assigned ) && ( $assigned[0]['tag_option_id'] ?? null ) === $package['option_id'];
	}

	private function is_active_item( array $item ): bool {
		return isset( $item['status'] ) && is_string( $item['status'] ) && 'active' === strtolower( trim( $item['status'] ) );
	}

	private function valid_item_id( mixed $id ): bool {
		return is_string( $id ) && 1 === preg_match( '/^[0-9]{10,20}$/', $id );
	}

	/** Old checkbox-only or malformed rows cannot become an eligible cached list. */
	private function valid_cached_list( array $items, array $package ): bool {
		if ( ! array_is_list( $items ) ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ||
				true !== ( $item['for_estimate'] ?? null ) ||
				true !== ( $item['package_tagged'] ?? null ) ||
				( $item['package_tag_id'] ?? null ) !== $package['tag_id'] ||
				( $item['package_tag_option_id'] ?? null ) !== $package['option_id'] ||
				! is_string( $item['item_id'] ?? null ) ||
				! preg_match( '/^[0-9]{10,20}$/', $item['item_id'] ) ) {
				return false;
			}
			foreach ( array( 'name', 'sku', 'brand', 'model', 'equipment_type', 'short_description' ) as $field ) {
				if ( ! isset( $item[ $field ] ) || ! is_string( $item[ $field ] ) ) {
					return false;
				}
			}
			if ( ! isset( $item['rate'] ) || ( ! is_int( $item['rate'] ) && ! is_float( $item['rate'] ) ) ) {
				return false;
			}
		}
		return true;
	}

	private function valid_reporting_id( mixed $id ): bool {
		return is_string( $id ) && 1 === preg_match( '/^[1-9][0-9]{0,31}$/', $id );
	}

	private function provider_failed( array $response ): bool {
		return array_key_exists( 'code', $response ) && ! in_array( $response['code'], array( 0, '0' ), true );
	}

	private function package_configuration_error(): WP_Error {
		return new WP_Error(
			'tc_estimate_package_tag_unavailable',
			__( 'Zoho Books must have one active Package reporting tag with one Package option. The catalog could not be verified.', 'tc-estimate' ),
			array( 'status' => 503 )
		);
	}

	private function invalid_catalog_response(): WP_Error {
		return new WP_Error(
			'tc_estimate_catalog_invalid_response',
			__( 'Zoho Books returned an invalid item response. No partial catalog was cached.', 'tc-estimate' ),
			array( 'status' => 502 )
		);
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
	private function normalize_item( array $item, array $package ): array {
		$package_tagged = $this->has_package_tag( $item, $package );
		$eligible = $package_tagged && $this->is_active_item( $item ) && $this->valid_item_id( $item['item_id'] ?? null );
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
			'for_estimate'           => $eligible,
			'package_tagged'         => $package_tagged,
			'package_tag_id'         => $package_tagged ? $package['tag_id'] : '',
			'package_tag_option_id'  => $package_tagged ? $package['option_id'] : '',
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
			'permits'          => 'permit',
			'permit_fee'       => 'permit',
			'permit_fees'      => 'permit',
		);
		return $aliases[ $value ] ?? $value;
	}

	private function nullable_int( mixed $value ): ?int {
		return null === $value || '' === $value ? null : (int) $value;
	}

	private function nullable_float( mixed $value ): ?float {
		return null === $value || '' === $value ? null : (float) $value;
	}
}
