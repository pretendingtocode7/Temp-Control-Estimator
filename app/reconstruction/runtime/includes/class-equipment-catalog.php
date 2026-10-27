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
	private const CACHE_POLICY = 'package_tag_v4';
	private const LIST_CACHE_TTL = 900;
	private const MAX_COMPOSITE_COMPONENTS = 100;
	private const SELECTOR_TYPES = array(
		'package' => 'package', 'ac' => 'condenser', 'furnace' => 'furnace', 'coil' => 'evaporator_coil',
	);
	public const CATEGORY2_VALUES = array(
		'Standard-Efficiency SILVER', 'High-Efficiency SILVER',
		'Standard-Efficiency GOLD', 'High-Efficiency GOLD',
		'Standard-Efficiency PLATINUM', 'High-Efficiency PLATINUM',
	);
	private const PROPOSAL_ADDONS = array(
		'maintenance' => 'New Residential System Maintenance Agreement',
		'permits' => 'Permits & Inspections',
	);

	/** Resolved from Books once per organization during this PHP request. */
	private array $package_selections = array();

	public const TYPES = array(
		'package', 'furnace', 'condenser', 'evaporator_coil', 'air_handler', 'thermostat',
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
			if ( '' !== $type && $entry['equipment_type'] !== $type ) {
				continue;
			}
			if ( '' !== $brand && 0 !== strcasecmp( $entry['brand'], $brand ) ) {
				continue;
			}
			if ( ! $this->matches_text( $entry, $q ) ) {
				continue;
			}
			$matches[] = $entry;
			if ( count( $matches ) >= $limit ) {
				break;
			}
		}

		return $matches;
	}

	/** Filter the complete eligible catalog before slicing a selector page. */
	public function search_selector( array $args = array() ): array|WP_Error {
		$category = $args['category'] ?? 'package';
		if ( ! is_string( $category ) || ! isset( self::SELECTOR_TYPES[ $category ] ) ) {
			return $this->invalid_selector_filter();
		}
		$category2 = $args['category2'] ?? '';
		if ( ! is_string( $category2 ) || ( '' !== $category2 && ! in_array( $category2, self::CATEGORY2_VALUES, true ) ) ) {
			return $this->invalid_selector_filter();
		}
		foreach ( array( 'q', 'brand' ) as $field ) {
			if ( isset( $args[ $field ] ) && ! is_string( $args[ $field ] ) ) {
				return $this->invalid_selector_filter();
			}
		}
		$q = sanitize_text_field( $args['q'] ?? '' );
		$brand = sanitize_text_field( $args['brand'] ?? '' );
		$page = $this->positive_integer_argument( $args['page'] ?? 1 );
		$limit = $this->positive_integer_argument( $args['limit'] ?? 50 );
		if ( null === $page || null === $limit ) {
			return $this->invalid_selector_filter();
		}
		$limit = min( 100, $limit );
		$filters = array();
		foreach ( array( 'btu' => 'btu', 'tons' => 'tons', 'efficiency' => 'percent' ) as $key => $kind ) {
			$value = $args[ $key ] ?? null;
			$filters[ $key ] = null;
			if ( null !== $value && '' !== $value ) {
				$filters[ $key ] = $this->parse_rating( $value, $kind );
				if ( null === $filters[ $key ] ) {
					return $this->invalid_selector_filter();
				}
			}
		}
		$items = $this->eligible_items();
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$matches = array();
		$btus = array();
		$tons = array();
		$efficiencies = array();
		$category2_values = array();
		foreach ( $items as $entry ) {
			if ( $entry['selection_category'] !== $category ) {
				continue;
			}
			if ( null !== $entry['btu_input'] ) {
				$btus[] = $entry['btu_input'];
			}
			if ( null !== $entry['tons'] ) {
				$tons[] = $entry['tons'];
			}
			if ( null !== $entry['efficiency_percent'] ) {
				$efficiencies[] = $entry['efficiency_percent'];
			}
			if ( null !== $entry['category_2'] ) {
				$category2_values[] = $entry['category_2'];
			}
			if ( ( '' !== $brand && 0 !== strcasecmp( $entry['brand'], $brand ) ) ||
				( '' !== $category2 && $entry['category_2'] !== $category2 ) ||
				! $this->matches_text( $entry, $q ) ||
				( null !== $filters['btu'] && $entry['btu_input'] !== $filters['btu'] ) ||
				( null !== $filters['tons'] && ( null === $entry['tons'] || (float) $entry['tons'] !== $filters['tons'] ) ) ||
				( null !== $filters['efficiency'] && ( null === $entry['efficiency_percent'] || (float) $entry['efficiency_percent'] !== $filters['efficiency'] ) ) ) {
				continue;
			}
			$matches[] = $entry;
		}
		$btus = array_values( array_unique( $btus, SORT_NUMERIC ) );
		$tons = array_values( array_unique( $tons, SORT_NUMERIC ) );
		$efficiencies = array_values( array_unique( $efficiencies, SORT_NUMERIC ) );
		sort( $btus, SORT_NUMERIC );
		sort( $tons, SORT_NUMERIC );
		sort( $efficiencies, SORT_NUMERIC );
		$category2_values = array_values( array_filter( self::CATEGORY2_VALUES, static fn ( string $value ): bool => in_array( $value, $category2_values, true ) ) );
		$total = count( $matches );
		// Avoid overflowing an offset for an otherwise valid, very distant page.
		$offset = $page > intdiv( PHP_INT_MAX, $limit ) ? $total : ( $page - 1 ) * $limit;
		return array(
			'items' => array_slice( $matches, $offset, $limit ),
			'total' => $total,
			'page' => $page,
			'per_page' => $limit,
			'has_more' => $offset < $total && $limit < $total - $offset,
			'facets' => array( 'btus' => $btus, 'tons' => $tons, 'efficiencies' => $efficiencies, 'category2' => $category2_values ),
		);
	}

	/**
	 * Validate freshly hydrated items, never client-supplied category/rate metadata.
	 * @return true|WP_Error
	 */
	public function validate_selection( array $systems, array $catalog_by_id ): bool|WP_Error {
		if ( ! array_is_list( $systems ) ) {
			return $this->invalid_selection();
		}
		$core_slots = array( 'package' => 'package', 'condenser' => 'ac', 'furnace' => 'furnace', 'coil' => 'coil' );
		$accessory_slots = array_diff( self::TYPES, array( 'package', 'condenser', 'furnace', 'evaporator_coil' ) );
		$system_numbers = array();
		foreach ( $systems as $index => $system ) {
			if ( ! is_array( $system ) || ! isset( $system['equipment'] ) || ! is_array( $system['equipment'] ) ) {
				return $this->invalid_selection();
			}
			$number = $this->positive_integer_argument( array_key_exists( 'system_number', $system ) ? $system['system_number'] : $index + 1 );
			if ( null === $number || isset( $system_numbers[ $number ] ) ) {
				return $this->invalid_selection();
			}
			$system_numbers[ $number ] = true;
			$seen = array();
			$has_package = false;
			$has_component = false;
			foreach ( $system['equipment'] as $slot => $selection ) {
				if ( ! is_string( $slot ) || ! is_array( $selection ) || ! $this->valid_item_id( $selection['item_id'] ?? null ) ) {
					return $this->invalid_selection();
				}
				$id = $selection['item_id'];
				$item = $catalog_by_id[ $id ] ?? null;
				if ( isset( $seen[ $id ] ) || ! is_array( $item ) || ( $item['item_id'] ?? null ) !== $id ||
					true !== ( $item['for_estimate'] ?? null ) || true !== ( $item['package_tagged'] ?? null ) ||
					! is_bool( $item['is_composite'] ?? null ) ) {
					return $this->invalid_selection();
				}
				$seen[ $id ] = true;
				if ( isset( $core_slots[ $slot ] ) ) {
					$category = $core_slots[ $slot ];
					if ( ( $item['selection_category'] ?? null ) !== $category ||
						( $item['equipment_type'] ?? null ) !== self::SELECTOR_TYPES[ $category ] ||
						$item['is_composite'] !== ( 'package' === $category ) ) {
						return $this->invalid_selection();
					}
					$has_package = $has_package || 'package' === $slot;
					$has_component = $has_component || 'package' !== $slot;
				} elseif ( ! in_array( $slot, $accessory_slots, true ) ||
					( $item['equipment_type'] ?? null ) !== $slot ||
					'' !== ( $item['selection_category'] ?? null ) || $item['is_composite'] ) {
					return $this->invalid_selection();
				}
			}
			if ( $has_package && $has_component ) {
				return $this->invalid_selection();
			}
		}
		return true;
	}

	private function matches_text( array $entry, string $query ): bool {
		$haystack = array();
		foreach ( array( 'name', 'sku', 'brand', 'model', 'short_description', 'js_part_number', 'mfg_part_number' ) as $field ) {
			$haystack[] = $entry[ $field ] ?? '';
		}
		return '' === trim( $query ) || false !== stripos( implode( ' ', $haystack ), trim( $query ) );
	}

	private function positive_integer_argument( mixed $value ): ?int {
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) &&
			strlen( $value ) <= strlen( (string) PHP_INT_MAX ) &&
			( strlen( $value ) < strlen( (string) PHP_INT_MAX ) || strcmp( $value, (string) PHP_INT_MAX ) <= 0 ) ) {
			$value = (int) $value;
		}
		return is_int( $value ) && $value >= 1 ? $value : null;
	}

	private function invalid_selector_filter(): WP_Error {
		return new WP_Error( 'tc_estimate_bad_equipment_filter', __( 'Invalid equipment filter.', 'tc-estimate' ), array( 'status' => 400 ) );
	}

	private function invalid_selection(): WP_Error {
		return new WP_Error( 'tc_estimate_bad_equipment_selection', __( 'Select a package or matching individual equipment without duplicate or conflicting items.', 'tc-estimate' ), array( 'status' => 400 ) );
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

	/** Separate authenticated proposal options; these do not enter the tagged catalog. */
	public function proposal_addons(): array {
		$result = array();
		foreach ( self::PROPOSAL_ADDONS as $key => $name ) {
			$addon = $this->get_proposal_addon( $key );
			$result[ $key ] = is_wp_error( $addon )
				? array( 'available' => false, 'addon_key' => $key, 'item_id' => null, 'name' => $name, 'rate' => 0.0, 'error_code' => $addon->get_error_code() )
				: $addon;
		}
		return $result;
	}

	/** Only the two fixed names can be resolved, with a fresh detail identity check. */
	public function get_proposal_addon( string $key ): array|WP_Error {
		if ( ! isset( self::PROPOSAL_ADDONS[ $key ] ) ) {
			return new WP_Error( 'tc_estimate_bad_proposal_addon', __( 'Unknown proposal option.', 'tc-estimate' ), array( 'status' => 400 ) );
		}
		$org_id = $this->organization_id();
		if ( is_wp_error( $org_id ) ) {
			return $this->unavailable_proposal_addon();
		}
		$name = self::PROPOSAL_ADDONS[ $key ];
		$matches = array();
		$complete = false;
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$response = Zoho_API::instance()->get( Zoho_API::SERVICE_BOOKS, '/items', array(
				'organization_id' => $org_id, 'filter_by' => 'Status.Active', 'name_contains' => $name,
				'page' => $page, 'per_page' => self::PER_PAGE, 'sort_column' => 'name',
			) );
			if ( is_wp_error( $response ) || $this->provider_failed( $response ) ||
				! isset( $response['items'] ) || ! is_array( $response['items'] ) || ! array_is_list( $response['items'] ) ||
				! is_bool( $response['page_context']['has_more_page'] ?? null ) ) {
				return $this->unavailable_proposal_addon();
			}
			foreach ( $response['items'] as $item ) {
				if ( is_array( $item ) && ( $item['name'] ?? null ) === $name && $this->is_active_item( $item ) ) {
					$matches[] = $item;
				}
			}
			if ( count( $matches ) > 1 ) {
				return $this->unavailable_proposal_addon();
			}
			if ( ! $response['page_context']['has_more_page'] ) {
				$complete = true;
				break;
			}
		}
		if ( ! $complete || 1 !== count( $matches ) || ! $this->valid_proposal_addon( $matches[0], $name ) ) {
			return $this->unavailable_proposal_addon();
		}
		$id = $matches[0]['item_id'];
		$response = Zoho_API::instance()->get( Zoho_API::SERVICE_BOOKS, '/items/' . $id, array( 'organization_id' => $org_id ) );
		if ( is_wp_error( $response ) || $this->provider_failed( $response ) ||
			! isset( $response['item'] ) || ! is_array( $response['item'] ) ||
			( $response['item']['item_id'] ?? null ) !== $id || ! $this->valid_proposal_addon( $response['item'], $name ) ) {
			return $this->unavailable_proposal_addon();
		}
		$result = array(
			'available' => true, 'addon_key' => $key, 'item_id' => $id, 'name' => $name,
			'rate' => 0.0, 'quantity' => 1, 'source' => 'zoho_books',
		);
		if ( 'maintenance' === $key ) {
			// Renewal is explicit proposal policy, not a price inferred from provider cost.
			$result['renewal_rate'] = 349.0;
			$result['renewal_units'] = 1;
		}
		return $result;
	}

	/** Resolve server-validated option flags; no client item ID or price is accepted. */
	public function resolve_proposal_addons( array $options ): array|WP_Error {
		foreach ( self::PROPOSAL_ADDONS as $key => $name ) {
			$flag = 'include_' . $key;
			if ( array_key_exists( $flag, $options ) && ! is_bool( $options[ $flag ] ) ) {
				return new WP_Error( 'tc_estimate_bad_proposal_addon_selection', __( 'Proposal option selections must be true or false.', 'tc-estimate' ), array( 'status' => 400 ) );
			}
		}
		$result = array();
		foreach ( self::PROPOSAL_ADDONS as $key => $name ) {
			if ( true !== ( $options[ 'include_' . $key ] ?? false ) ) {
				continue;
			}
			$addon = $this->get_proposal_addon( $key );
			if ( is_wp_error( $addon ) ) {
				return $addon;
			}
			$result[ $key ] = $addon;
		}
		return $result;
	}

	private function valid_proposal_addon( array $item, string $name ): bool {
		return $this->valid_item_id( $item['item_id'] ?? null ) && ( $item['name'] ?? null ) === $name &&
			$this->is_active_item( $item ) && false === ( $item['is_combo_product'] ?? null ) &&
			0.0 === $this->nonnegative_number( $item['rate'] ?? null );
	}

	private function unavailable_proposal_addon(): WP_Error {
		return new WP_Error( 'tc_estimate_proposal_addon_unavailable', __( 'A selected proposal option is unavailable or could not be verified. Refresh its availability before continuing.', 'tc-estimate' ), array( 'status' => 400 ) );
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
			foreach ( array( 'name', 'sku', 'brand', 'model', 'equipment_type', 'selection_category', 'short_description', 'js_part_number', 'mfg_part_number' ) as $field ) {
				if ( ! isset( $item[ $field ] ) || ! is_string( $item[ $field ] ) ) {
					return false;
				}
			}
			if ( ! isset( $item['rate'] ) || ( ! is_int( $item['rate'] ) && ! is_float( $item['rate'] ) ) ) {
				return false;
			}
			$category = $item['selection_category'];
			if ( ! is_bool( $item['is_composite'] ?? null ) ||
				( '' !== $category && ( ! isset( self::SELECTOR_TYPES[ $category ] ) || self::SELECTOR_TYPES[ $category ] !== $item['equipment_type'] ) ) ||
				$item['is_composite'] !== ( 'package' === $category ) ||
				( '' === $category && in_array( $item['equipment_type'], self::SELECTOR_TYPES, true ) ) ) {
				return false;
			}
			foreach ( array( 'btu_input', 'btu_output', 'tons' ) as $field ) {
				if ( ! array_key_exists( $field, $item ) ||
					( null !== $item[ $field ] &&
						( ( 'tons' === $field ? ( ! is_float( $item[ $field ] ) && ! is_int( $item[ $field ] ) ) : ! is_int( $item[ $field ] ) ) ||
						! is_finite( (float) $item[ $field ] ) || $item[ $field ] <= 0 ) ) ) {
					return false;
				}
			}
			foreach ( array( 'efficiency_percent', 'purchase_cost' ) as $field ) {
				if ( ! array_key_exists( $field, $item ) ||
					( null !== $item[ $field ] &&
						( ( ! is_int( $item[ $field ] ) && ! is_float( $item[ $field ] ) ) ||
						! is_finite( (float) $item[ $field ] ) ||
						( 'efficiency_percent' === $field ? ( $item[ $field ] <= 0 || $item[ $field ] > 100 ) : $item[ $field ] < 0 ) ) ) ) {
					return false;
				}
			}
			if ( ( $item['afue'] ?? null ) !== $item['efficiency_percent'] ) {
				return false;
			}
			if ( ! array_key_exists( 'category_2', $item ) || ( null !== $item['category_2'] && ! in_array( $item['category_2'], self::CATEGORY2_VALUES, true ) ) ) {
				return false;
			}
			if ( null === $item['purchase_cost'] ) {
				if ( null !== ( $item['purchase_cost_source'] ?? null ) || 'unknown' !== ( $item['purchase_cost_status'] ?? null ) ) {
					return false;
				}
			} elseif ( 'known' !== ( $item['purchase_cost_status'] ?? null ) ||
				! in_array( $item['purchase_cost_source'] ?? null, array( 'books_purchase_rate', 'books_mapped_items' ), true ) ||
				( 'books_mapped_items' === $item['purchase_cost_source'] && ! $item['is_composite'] ) ||
				( 'books_purchase_rate' === $item['purchase_cost_source'] && $item['is_composite'] && $item['purchase_cost'] <= 0 ) ) {
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
		$classification = $this->classify_item( $item, $type, $name );
		$tons = $this->item_rating( $item, array( 'cf_tons', 'tons' ), 'tons', $name );
		$btu_input = $this->item_rating( $item, array( 'cf_btu_input', 'btu_input' ), 'btu', $name );
		// An unlabeled name BTU value is never evidence of output capacity.
		$btu_output = $this->item_rating( $item, array( 'cf_btu_output', 'btu_output' ), 'btu', '' );
		$efficiency = $this->item_rating( $item, array( 'cf_afue', 'afue', 'cf_afue_percent', 'afue_percent' ), 'percent', $name );
		$cost = $this->item_purchase_cost( $item );
		$desc  = (string) $this->first_value( $item, $cf, array( 'description', 'Description', 'cf_short_description', 'short_description', 'Short_Description' ), '' );

		return array(
			'item_id'                => (string) ( $item['item_id'] ?? $item['id'] ?? '' ),
			'name'                   => $name,
			'sku'                    => $sku,
			'rate'                   => (float) $this->first_value( $item, $cf, array( 'rate', 'Rate', 'sales_rate', 'Sales_Price' ), 0 ),
			'stock_on_hand'          => (float) $this->first_value( $item, $cf, array( 'stock_on_hand', 'available_stock', 'Qty_in_Stock' ), 0 ),
			'equipment_type'         => $classification['equipment_type'],
			'selection_category'     => $classification['selection_category'],
			'category_2'             => $this->item_category2( $item ),
			'is_composite'           => $classification['is_composite'],
			'brand'                  => $brand,
			'model'                  => $model,
			'afue'                   => $efficiency,
			'efficiency_percent'     => $efficiency,
			'seer'                   => $this->nullable_float( $this->first_value( $item, $cf, array( 'cf_seer', 'seer', 'SEER', 'SEER_Rating' ), null ) ),
			'tons'                   => $tons,
			'btu_input'              => $btu_input,
			'btu_output'             => $btu_output,
			'stages'                 => (string) $this->first_value( $item, $cf, array( 'cf_stages', 'stages', 'Stages' ), '' ),
			'refrigerant'            => (string) $this->first_value( $item, $cf, array( 'cf_refrigerant', 'refrigerant', 'Refrigerant' ), '' ),
			'width_inches'           => $this->nullable_float( $this->first_value( $item, $cf, array( 'cf_width', 'width_inches', 'Width' ), null ) ),
			'warranty_parts_years'   => $this->nullable_int( $this->first_value( $item, $cf, array( 'cf_warranty_parts_years', 'warranty_parts_years', 'Warranty_Parts_Years' ), null ) ),
			'warranty_labor_years'   => $this->nullable_int( $this->first_value( $item, $cf, array( 'cf_warranty_labor_years', 'warranty_labor_years', 'Warranty_Labor_Years' ), null ) ),
			'short_description'      => $desc,
			'long_description'       => $desc,
			'purchase_cost'          => $cost['value'],
			'purchase_cost_source'   => $cost['source'],
			'purchase_cost_status'   => null === $cost['value'] ? 'unknown' : 'known',
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
	 * Pull custom fields from inline cf_* keys, the hash, and custom_fields array.
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
		if ( isset( $item['custom_field_hash'] ) && is_array( $item['custom_field_hash'] ) ) {
			foreach ( $item['custom_field_hash'] as $name => $value ) {
				if ( ! is_string( $name ) ) {
					continue;
				}
				$key = $this->field_key( $name );
				$map[ $name ] = $value;
				$map[ $key ] = $value;
				if ( str_starts_with( $key, 'cf_' ) ) {
					$map[ substr( $key, 3 ) ] = $value;
				}
			}
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

	/** The raw composite boolean establishes package identity, never eligibility. */
	private function classify_item( array $item, string $type, string $name ): array {
		$unknown = array( 'equipment_type' => '', 'selection_category' => '', 'is_composite' => false );
		if ( ! is_bool( $item['is_combo_product'] ?? null ) ) {
			return $unknown;
		}
		if ( $item['is_combo_product'] ) {
			return array( 'equipment_type' => 'package', 'selection_category' => 'package', 'is_composite' => true );
		}
		if ( in_array( $type, self::TYPES, true ) ) {
			if ( 'package' === $type ) {
				return $unknown;
			}
			$category = array_search( $type, self::SELECTOR_TYPES, true );
			return array( 'equipment_type' => $type, 'selection_category' => false === $category ? '' : $category, 'is_composite' => false );
		}
		$categories = array();
		$descriptors = array(
			'ac' => '/\b(?:a\s*\/\s*c|ac|air\s+condition(?:er|ing)|condenser)\b/i',
			'furnace' => '/\bfurnaces?\b/i',
			'coil' => '/\b(?:evaporator\s+coil|evap\.?\s+coil|coil)\b/i',
		);
		foreach ( $descriptors as $category => $pattern ) {
			if ( 1 === preg_match( $pattern, $name ) ) {
				$categories[] = $category;
			}
		}
		if ( 1 !== count( $categories ) ) {
			return $unknown;
		}
		$category = $categories[0];
		return array( 'equipment_type' => self::SELECTOR_TYPES[ $category ], 'selection_category' => $category, 'is_composite' => false );
	}

	private function field_key( string $name ): string {
		return strtolower( (string) preg_replace( '/[^a-zA-Z0-9]+/', '_', trim( $name ) ) );
	}

	/** Preserve exact verified Category 2 strings; names and reporting tags are not substitutes. */
	private function item_category2( array $item ): ?string {
		$values = array();
		foreach ( array( $item, $item['custom_field_hash'] ?? array() ) as $fields ) {
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $key => $value ) {
				if ( is_string( $key ) && 'cf_category_2' === $this->field_key( $key ) ) {
					$values[] = $value;
				}
			}
		}
		if ( isset( $item['custom_fields'] ) && is_array( $item['custom_fields'] ) ) {
			foreach ( $item['custom_fields'] as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$key = $field['api_name'] ?? $field['placeholder'] ?? $field['label'] ?? '';
				if ( is_string( $key ) && in_array( $this->field_key( $key ), array( 'cf_category_2', 'category_2' ), true ) ) {
					$values[] = $field['value'] ?? null;
				}
			}
		}
		$result = null;
		foreach ( $values as $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			if ( ! is_string( $value ) || ! in_array( $value, self::CATEGORY2_VALUES, true ) || ( null !== $result && $result !== $value ) ) {
				return null;
			}
			$result = $value;
		}
		return $result;
	}

	/** Invalid/conflicting structured values suppress the explicit-name fallback. */
	private function item_rating( array $item, array $keys, string $kind, string $name ): int|float|null {
		$values = array();
		foreach ( $item as $key => $value ) {
			if ( is_string( $key ) && in_array( $this->field_key( $key ), $keys, true ) ) {
				$values[] = $value;
			}
		}
		if ( isset( $item['custom_field_hash'] ) && is_array( $item['custom_field_hash'] ) ) {
			foreach ( $item['custom_field_hash'] as $key => $value ) {
				if ( is_string( $key ) && in_array( $this->field_key( $key ), $keys, true ) ) {
					$values[] = $value;
				}
			}
		}
		if ( isset( $item['custom_fields'] ) && is_array( $item['custom_fields'] ) ) {
			foreach ( $item['custom_fields'] as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$key = $field['api_name'] ?? $field['placeholder'] ?? $field['label'] ?? '';
				if ( is_string( $key ) && in_array( $this->field_key( $key ), $keys, true ) ) {
					$values[] = $field['value'] ?? null;
				}
			}
		}
		$rating = null;
		$present = false;
		foreach ( $values as $value ) {
			if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
				continue;
			}
			$present = true;
			$parsed = $this->parse_rating( $value, $kind );
			if ( null === $parsed || ( null !== $rating && $rating !== $parsed ) ) {
				return null;
			}
			$rating = $parsed;
		}
		return $present ? $rating : $this->name_rating( $name, $kind );
	}

	/** Complete tokens only: no parseInt/float prefix coercion or model decoding. */
	private function parse_rating( mixed $value, string $kind ): int|float|null {
		if ( is_int( $value ) || is_float( $value ) ) {
			$number = (float) $value;
		} elseif ( is_string( $value ) ) {
			$numeric = '(?:[0-9]{1,3}(?:,[0-9]{3})+|[0-9]+)(?:\.[0-9]+)?|\.[0-9]+';
			$pattern = 'percent' === $kind
				? '/^(' . $numeric . ')\s*%?$/D'
				: ( 'tons' === $kind
					? '/^(' . $numeric . ')\s*(?:-?\s*tons?)?$/iD'
					: '/^(' . $numeric . ')\s*(k)?\s*(?:-?\s*btu(?:\s*\/\s*h(?:r|our)?)?)?$/iD' );
			if ( 1 !== preg_match( $pattern, trim( $value ), $match ) ) {
				return null;
			}
			$number = (float) str_replace( ',', '', $match[1] );
			if ( 'btu' === $kind && ! empty( $match[2] ) ) {
				$number *= 1000;
			}
		} else {
			return null;
		}
		if ( ! is_finite( $number ) || $number <= 0 ) {
			return null;
		}
		if ( 'percent' === $kind && $number > 100 ) {
			return null;
		}
		if ( 'btu' === $kind ) {
			return $number < PHP_INT_MAX && floor( $number ) === $number ? (int) $number : null;
		}
		return $number;
	}

	private function name_rating( string $name, string $kind ): int|float|null {
		if ( 'percent' === $kind ) {
			return $this->name_efficiency( $name );
		}
		$numeric = '(?:[0-9]{1,3}(?:,[0-9]{3})+|[0-9]+)(?:\.[0-9]+)?|\.[0-9]+';
		$unit = 'tons' === $kind ? 'tons?' : 'k?\s*(?:-\s*)?btu(?:\s*\/\s*h(?:r|our)?)?';
		$pattern = '/(?<![a-zA-Z0-9.,+\/-])(' . $numeric . ')\s*(?:-\s*)?' . $unit . '(?![a-zA-Z0-9])/i';
		preg_match_all( $pattern, $name, $matches, PREG_OFFSET_CAPTURE );
		$rating = null;
		foreach ( $matches[0] as $match ) {
			$prefix = substr( $name, 0, $match[1] );
			if ( 1 === preg_match( '/[0-9]\s*k?\s*(?:[-–—\/]|to|or)\s*$/iu', $prefix ) ) {
				return null;
			}
			$parsed = $this->parse_rating( $match[0], $kind );
			if ( null === $parsed || ( null !== $rating && $rating !== $parsed ) ) {
				return null;
			}
			$rating = $parsed;
		}
		return $rating;
	}

	/** Only an explicitly labeled AFUE percentage is an efficiency fallback. */
	private function name_efficiency( string $name ): ?float {
		$numeric = '[0-9]+(?:\.[0-9]+)?';
		$patterns = array(
			'/(?<![a-zA-Z0-9.,+\/-])(' . $numeric . ')\s*%\s*AFUE\b/i',
			'/\bAFUE\s*[:=]?\s*(' . $numeric . ')\s*%(?![a-zA-Z0-9])/i',
		);
		$rating = null;
		foreach ( $patterns as $pattern ) {
			preg_match_all( $pattern, $name, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
			foreach ( $matches as $match ) {
				$prefix = substr( $name, 0, $match[0][1] );
				if ( 1 === preg_match( '/[0-9]\s*%?\s*(?:[-–—\/]|to|or)\s*$/iu', $prefix ) ) {
					return null;
				}
				$parsed = $this->parse_rating( $match[1][0], 'percent' );
				if ( null === $parsed || ( null !== $rating && $rating !== $parsed ) ) {
					return null;
				}
				$rating = $parsed;
			}
		}
		return $rating;
	}

	/**
	 * Use actual purchase rates only. A zero-priced composite list row does not
	 * establish cost; its detail must contain a complete, flat component mapping.
	 * This normalization never fetches details or substitutes any sales amount.
	 */
	private function item_purchase_cost( array $item ): array {
		$unknown = array( 'value' => null, 'source' => null );
		$parent_cost = $this->nonnegative_number( $item['purchase_rate'] ?? null );
		if ( null === $parent_cost ) {
			return $unknown;
		}
		if ( $parent_cost > 0 || false === ( $item['is_combo_product'] ?? null ) ) {
			return array( 'value' => $parent_cost, 'source' => 'books_purchase_rate' );
		}
		$components = $item['mapped_items'] ?? null;
		if ( true !== ( $item['is_combo_product'] ?? null ) || ! is_array( $components ) ||
			! array_is_list( $components ) || 0 === count( $components ) || count( $components ) > self::MAX_COMPOSITE_COMPONENTS ) {
			return $unknown;
		}
		$total = 0.0;
		$seen = array();
		foreach ( $components as $component ) {
			if ( ! is_array( $component ) || true !== ( $component['is_component'] ?? null ) ||
				false !== ( $component['is_combo_product'] ?? null ) ||
				! $this->valid_item_id( $component['item_id'] ?? null ) ||
				( $component['item_id'] ?? null ) === ( $item['item_id'] ?? null ) ||
				isset( $seen[ $component['item_id'] ] ) || ! empty( $component['mapped_items'] ) ) {
				return $unknown;
			}
			$cost = $this->nonnegative_number( $component['purchase_rate'] ?? null );
			$quantity = $this->nonnegative_number( $component['mapped_quantity'] ?? null );
			if ( null === $cost || null === $quantity || $quantity <= 0 ) {
				return $unknown;
			}
			$seen[ $component['item_id'] ] = true;
			$total += $cost * $quantity;
			if ( ! is_finite( $total ) ) {
				return $unknown;
			}
		}
		return array( 'value' => $total, 'source' => 'books_mapped_items' );
	}

	private function nonnegative_number( mixed $value ): ?float {
		if ( is_string( $value ) ) {
			if ( 1 !== preg_match( '/^(?:[0-9]{1,3}(?:,[0-9]{3})+|[0-9]+)(?:\.[0-9]+)?$/D', trim( $value ) ) ) {
				return null;
			}
			$value = (float) str_replace( ',', '', trim( $value ) );
		}
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return null;
		}
		return is_finite( (float) $value ) && $value >= 0 ? (float) $value : null;
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
