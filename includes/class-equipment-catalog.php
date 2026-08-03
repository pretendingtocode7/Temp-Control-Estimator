<?php
/**
 * Equipment catalog — reads Zoho CRM equipment/part records and normalizes custom fields.
 *
 * Matches the field set defined in Data Model 5.1 of the plan.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Equipment_Catalog {

	private static ?Equipment_Catalog $instance = null;

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
	 * Search the catalog. Results are cached for 15min per query.
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

		$module = $this->crm_module();
		$cache_key = sprintf( 'catalog_%s_%s_%s_%s_%d', $module, $type, md5( $q ), md5( $brand ), $limit );

		return Zoho_Cache::instance()->remember(
			$cache_key,
			TC_ESTIMATE_CACHE_TTL_CATALOG,
			function () use ( $type, $q, $brand, $limit ) {
				$query = array(
					'per_page' => $limit,
					'fields'   => $this->crm_fields_param(),
				);
				$response = Zoho_API::instance()->get( Zoho_API::SERVICE_CRM, '/' . rawurlencode( $this->crm_module() ), $query );
				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$items = $response['data'] ?? array();
				$normalized = array();
				foreach ( $items as $item ) {
					$entry = $this->normalize_item( $item );
					if ( '' !== $type && '' !== $entry['equipment_type'] && in_array( $entry['equipment_type'], self::TYPES, true ) && $entry['equipment_type'] !== $type ) {
						continue;
					}
					if ( '' !== $type && ( '' === $entry['equipment_type'] || ! in_array( $entry['equipment_type'], self::TYPES, true ) ) ) {
						$entry['equipment_type'] = $type;
					}
					if ( '' !== $brand && strcasecmp( $entry['brand'], $brand ) !== 0 ) {
						continue;
					}
					if ( '' !== $q && false === stripos( implode( ' ', array( $entry['name'], $entry['sku'], $entry['brand'], $entry['model'], $entry['short_description'] ) ), $q ) ) {
						continue;
					}
					$normalized[] = $entry;
				}
				return $normalized;
			}
		);
	}

	/**
	 * Fetch a single item by Zoho item_id.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_item( string $item_id ): array|WP_Error {
		$item_id = trim( $item_id );
		if ( ! preg_match( '/^[0-9]{15,20}$/', $item_id ) ) {
			return new WP_Error( 'tc_estimate_bad_id', __( 'Invalid Zoho item ID.', 'tc-estimate' ), array( 'status' => 400 ) );
		}

		return Zoho_Cache::instance()->remember(
			'crm_item_' . $this->crm_module() . '_' . $item_id,
			TC_ESTIMATE_CACHE_TTL_CATALOG,
			function () use ( $item_id ) {
				$response = Zoho_API::instance()->get(
					Zoho_API::SERVICE_CRM,
					'/' . rawurlencode( $this->crm_module() ) . '/' . $item_id,
					array( 'fields' => $this->crm_fields_param() )
				);
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				$item = $response['data'][0] ?? null;
				return $item ? $this->normalize_item( $item ) : new WP_Error( 'tc_estimate_not_found', __( 'Item not found.', 'tc-estimate' ), array( 'status' => 404 ) );
			}
		);
	}

	private function crm_module(): string {
		$module = (string) get_option( 'tc_estimate_crm_equipment_module', 'Products' );
		return '' !== $module && preg_match( '/^[A-Za-z0-9_]+$/', $module ) ? $module : 'Products';
	}

	private function crm_fields_param(): string {
		return implode( ',', array(
			'id',
			'Estimate_Builder_Part_Info',
			'Name',
			'Product_Name',
			'Product_Code',
			'Equipment_Type',
			'Brand',
			'Model',
			'Purchase_Cost',
			'Markup',
			'Sales_Price',
			'Stages',
			'Efficiency',
			'SEER',
			'AFUE',
			'Width',
			'JS_Part_Number',
			'Mfg_Part_Number',
			'BTU_Input',
			'BTU_Output',
			'Tons',
			'Refrigerant',
			'Warranty_Parts_Years',
			'Warranty_Labor_Years',
			'Short_Description',
			'Description',
		) );
	}

	/**
	 * Convert Zoho's raw item shape into our normalized catalog entry.
	 *
	 * Zoho returns custom fields either inline (cf_equipment_type) OR in a custom_fields array.
	 * We handle both shapes.
	 */
	private function normalize_item( array $item ): array {
		$cf = $this->extract_custom_fields( $item );
		$price = $this->first_float( $item, $cf, array( 'Sales_Price', 'Unit_Price', 'Price', 'Rate', 'rate', 'unit_price' ) );
		$type = $this->normalize_type( (string) $this->first_value( $item, $cf, array( 'equipment_type', 'Equipment_Type', 'Type', 'Product_Category' ), '' ) );
		$brand = (string) $this->first_value( $item, $cf, array( 'brand', 'Brand', 'Manufacturer' ), '' );
		$model = (string) $this->first_value( $item, $cf, array( 'model', 'Model', 'Mfg_Part_Number', 'JS_Part_Number', 'Product_Code', 'sku', 'SKU' ), '' );
		$sku = (string) $this->first_value( $item, $cf, array( 'JS_Part_Number', 'Mfg_Part_Number', 'Product_Code', 'sku', 'SKU' ), '' );
		$name = (string) $this->first_value( $item, $cf, array( 'Estimate_Builder_Part_Info', 'Estimate_Builder_Part_Name', 'Product_Name', 'Name', 'name' ), '' );
		if ( '' === $name ) {
			$name = trim( $brand . ' ' . $model );
		}

		return array(
			'item_id'            => (string) ( $item['id'] ?? $item['item_id'] ?? '' ),
			'name'               => $name,
			'sku'                => $sku,
			'rate'               => $price,
			'stock_on_hand'      => (float) $this->first_value( $item, $cf, array( 'Qty_in_Stock', 'stock_on_hand' ), 0 ),
			'equipment_type'     => $type,
			'brand'              => $brand,
			'model'              => $model,
			'afue'               => $this->nullable_float( $this->first_value( $item, $cf, array( 'afue_percent', 'AFUE_Percent', 'AFUE' ), null ) ),
			'seer'               => $this->nullable_float( $this->first_value( $item, $cf, array( 'seer_rating', 'SEER_Rating', 'SEER' ), null ) ),
			'tons'               => $this->nullable_float( $this->first_value( $item, $cf, array( 'tons', 'Tons' ), null ) ),
			'btu_input'          => $this->nullable_int( $this->first_value( $item, $cf, array( 'btu_input', 'BTU_Input' ), null ) ),
			'btu_output'         => $this->nullable_int( $this->first_value( $item, $cf, array( 'btu_output', 'BTU_Output' ), null ) ),
			'stages'             => (string) $this->first_value( $item, $cf, array( 'stages', 'Stages' ), '' ),
			'refrigerant'        => (string) $this->first_value( $item, $cf, array( 'refrigerant', 'Refrigerant' ), '' ),
			'width_inches'       => $this->nullable_float( $this->first_value( $item, $cf, array( 'width_inches', 'Width_Inches', 'Width' ), null ) ),
			'warranty_parts_years' => $this->nullable_int( $this->first_value( $item, $cf, array( 'warranty_parts_years', 'Warranty_Parts_Years' ), null ) ),
			'warranty_labor_years' => $this->nullable_int( $this->first_value( $item, $cf, array( 'warranty_labor_years', 'Warranty_Labor_Years' ), null ) ),
			'short_description'  => (string) $this->first_value( $item, $cf, array( 'short_description', 'Short_Description', 'Description', 'Mfg_Part_Number', 'JS_Part_Number' ), '' ),
			'long_description'   => (string) $this->first_value( $item, $cf, array( 'long_description', 'Long_Description' ), '' ),
			'purchase_cost'      => $this->nullable_float( $this->first_value( $item, $cf, array( 'Purchase_Cost' ), null ) ),
			'markup'             => $this->nullable_float( $this->first_value( $item, $cf, array( 'Markup' ), null ) ),
			'js_part_number'     => (string) $this->first_value( $item, $cf, array( 'JS_Part_Number' ), '' ),
			'mfg_part_number'    => (string) $this->first_value( $item, $cf, array( 'Mfg_Part_Number' ), '' ),
		);
	}

	/**
	 * Pull cf_* values from either inline keys or the custom_fields array.
	 */
	private function extract_custom_fields( array $item ): array {
		$map = array();

		// Inline form: cf_equipment_type, cf_afue_percent, etc.
		foreach ( $item as $k => $v ) {
			if ( str_starts_with( $k, 'cf_' ) ) {
				$map[ substr( $k, 3 ) ] = $v;
			}
		}

		// Array form: custom_fields: [ { api_name: "cf_equipment_type", value: "furnace" }, ... ]
		if ( isset( $item['custom_fields'] ) && is_array( $item['custom_fields'] ) ) {
			foreach ( $item['custom_fields'] as $cf ) {
				if ( ! is_array( $cf ) ) {
					continue;
				}
				$api = (string) ( $cf['api_name'] ?? $cf['placeholder'] ?? '' );
				if ( '' === $api ) {
					continue;
				}
				$key = str_starts_with( $api, 'cf_' ) ? substr( $api, 3 ) : $api;
				$map[ $key ] = $cf['value'] ?? null;
			}
		}

		return $map;
	}

	private function first_value( array $item, array $cf, array $keys, mixed $default ): mixed {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $item ) && null !== $item[ $key ] && '' !== $item[ $key ] ) {
				return $item[ $key ];
			}
			if ( array_key_exists( $key, $cf ) && null !== $cf[ $key ] && '' !== $cf[ $key ] ) {
				return $cf[ $key ];
			}
		}
		return $default;
	}

	private function first_float( array $item, array $cf, array $keys ): float {
		return (float) $this->first_value( $item, $cf, $keys, 0 );
	}

	private function normalize_type( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9]+/', '_', $value );
		$value = trim( (string) $value, '_' );
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

	private function nullable_int( mixed $v ): ?int {
		if ( null === $v || '' === $v ) {
			return null;
		}
		return (int) $v;
	}

	private function nullable_float( mixed $v ): ?float {
		if ( null === $v || '' === $v ) {
			return null;
		}
		return (float) $v;
	}
}
