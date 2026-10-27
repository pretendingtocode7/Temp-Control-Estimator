<?php
/** Server-side projection of offered alternatives into selected physical systems. */
declare( strict_types=1 );

namespace TempControl\Estimate;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Proposal_Choices {
	private static ?self $instance = null;
	private const MAX_SYSTEMS = 10;
	private const MAX_CHOICES = 6;
	private const MAX_ITEMS = 200;
	private const MAX_PRICE = 10000000;
	private const SLOTS = array( 'package', 'furnace', 'condenser', 'coil', 'air_handler', 'thermostat', 'humidifier', 'uv_purifier', 'water_heater', 'filter', 'part', 'permit', 'other' );

	public static function instance(): self { return self::$instance ??= new self(); }

	/** Bound and sanitize the wire payload before any catalog lookups. */
	public function normalize( array $body ): array|WP_Error {
		$body = array_intersect_key( $body, array_flip( array( 'template_id', 'customer', 'systems', 'options', 'pricing', 'books_edits' ) ) );
		$body = $this->strip_internal( $body );
		$systems = $body['systems'] ?? array();
		if ( ! is_array( $systems ) || ! array_is_list( $systems ) || count( $systems ) > self::MAX_SYSTEMS ) {
			return $this->invalid_equipment();
		}
		if ( isset( $body['options'] ) && ! is_array( $body['options'] ) ) {
			return $this->invalid_choices();
		}
		$options = $body['options'] ?? array();
		foreach ( array( 'include_maintenance', 'include_permits' ) as $flag ) {
			if ( array_key_exists( $flag, $options ) && ! is_bool( $options[ $flag ] ) ) {
				return new WP_Error( 'tc_estimate_bad_proposal_addon_selection', __( 'Proposal option selections must be true or false.', 'tc-estimate' ), array( 'status' => 400 ) );
			}
		}
		$normalized = array();
		$numbers = $choice_ids = array();
		$has_choices = false;
		foreach ( $systems as $index => $system ) {
			if ( ! is_array( $system ) ) { return $this->invalid_equipment(); }
			$number = $this->system_number( array_key_exists( 'system_number', $system ) ? $system['system_number'] : $index + 1 );
			if ( null === $number || isset( $numbers[ $number ] ) ) { return $this->invalid_equipment(); }
			$numbers[ $number ] = true;
			$label = $this->text( $system['system_label'] ?? 'System ' . $number, 60 );
			if ( null === $label ) { return $this->invalid_choices(); }
			$entry = array( 'system_number' => $number, 'system_label' => $label );
			if ( array_key_exists( 'choices', $system ) ) {
				$has_choices = true;
				if ( ! is_array( $system['choices'] ) || ! array_is_list( $system['choices'] ) || empty( $system['choices'] ) || count( $system['choices'] ) > self::MAX_CHOICES || ! $this->choice_id( $system['selected_choice_id'] ?? null ) ) {
					return $this->invalid_choices();
				}
				$entry['choices'] = array();
				$selected = null;
				foreach ( $system['choices'] as $choice ) {
					if ( ! is_array( $choice ) || ! $this->choice_id( $choice['choice_id'] ?? null ) || isset( $choice_ids[ $choice['choice_id'] ] ) ) { return $this->invalid_choices(); }
					$choice_ids[ $choice['choice_id'] ] = true;
					if ( ! is_string( $choice['label'] ?? null ) ) { return $this->invalid_choices(); }
					$choice_label = $this->text( $choice['label'], 60 );
					if ( null === $choice_label || '' === $choice_label ) { return $this->invalid_choices(); }
					$equipment = $this->equipment( $choice['equipment'] ?? null );
					if ( is_wp_error( $equipment ) ) { return $equipment; }
					$canonical = array( 'choice_id' => $choice['choice_id'], 'label' => $choice_label, 'equipment' => $equipment );
					if ( array_key_exists( 'sales_price', $choice ) ) {
						if ( ! $this->number( $choice['sales_price'] ) || $choice['sales_price'] < 0 || $choice['sales_price'] > self::MAX_PRICE ) { return $this->invalid_pricing(); }
						$canonical['sales_price'] = round( (float) $choice['sales_price'], 2 );
					}
					$entry['choices'][] = $canonical;
					if ( $canonical['choice_id'] === $system['selected_choice_id'] ) { $selected = $equipment; }
				}
				if ( null === $selected ) { return $this->invalid_choices(); }
				$entry['selected_choice_id'] = $system['selected_choice_id'];
				// Ignore the client's mirror completely: only the selected offered map wins.
				$entry['equipment'] = $selected;
			} else {
				$equipment = $this->equipment( $system['equipment'] ?? null );
				if ( is_wp_error( $equipment ) ) { return $equipment; }
				$entry['equipment'] = $equipment;
			}
			if ( true === ( $options['include_permits'] ?? false ) ) {
				$maps = isset( $entry['choices'] ) ? array_column( $entry['choices'], 'equipment' ) : array( $entry['equipment'] );
				foreach ( $maps as $map ) {
					if ( array_key_exists( 'permit', $map ) ) {
						return new WP_Error( 'tc_estimate_duplicate_permit_option', __( 'Remove the existing permit item to use the $0 paperwork option.', 'tc-estimate' ), array( 'status' => 400 ) );
					}
				}
			}
			$normalized[] = $entry;
		}
		$body['systems'] = $normalized;
		$body['options'] = $options;
		$pricing = $body['pricing'] ?? array();
		if ( ! is_array( $pricing ) ) { return $this->invalid_pricing(); }
		$body['pricing'] = array_intersect_key( $pricing, array_flip( array( 'subtotal', 'total', 'deposit_percent', 'project_adjustment' ) ) );
		if ( $has_choices ) {
			foreach ( array( 'total', 'project_adjustment' ) as $field ) {
				if ( array_key_exists( $field, $body['pricing'] ) && ! $this->number( $body['pricing'][ $field ] ) ) { return $this->invalid_pricing(); }
			}
			if ( isset( $body['pricing']['project_adjustment'] ) && abs( (float) $body['pricing']['project_adjustment'] ) > self::MAX_PRICE ) { return $this->invalid_pricing(); }
		}
		if ( count( $this->collect_item_ids( $body ) ) > self::MAX_ITEMS ) { return $this->invalid_choices(); }
		return $body;
	}

	/** All offered items must be freshly hydrated, including unselected alternatives. */
	public function collect_item_ids( array $body ): array {
		$ids = array();
		foreach ( $body['systems'] ?? array() as $system ) {
			$maps = isset( $system['choices'] ) ? array_column( $system['choices'], 'equipment' ) : array( $system['equipment'] );
			foreach ( $maps as $equipment ) {
				foreach ( $equipment as $item ) { $ids[ $item['item_id'] ] = $item['item_id']; }
			}
		}
		return array_values( $ids );
	}

	/** Validate every offer, then project only selected equipment into transaction input. */
	public function project( array $body, array $catalog_by_id ): array|WP_Error {
		$catalog = Equipment_Catalog::instance();
		$offered = $selected_systems = array();
		$has_choices = false;
		$selected_prices = $selected_catalog_subtotal = 0.0;
		foreach ( $body['systems'] as $system ) {
			$selected = array( 'system_number' => $system['system_number'], 'system_label' => $system['system_label'], 'equipment' => $system['equipment'] );
			if ( isset( $system['choices'] ) ) {
				$has_choices = true;
				foreach ( $system['choices'] as $index => $choice ) {
					$choice_system = array_merge( $selected, array( 'equipment' => $choice['equipment'] ) );
					$valid = $catalog->validate_selection( array( $choice_system ), $catalog_by_id );
					if ( is_wp_error( $valid ) ) { return $valid; }
					$sum = $this->catalog_sum( $choice['equipment'], $catalog_by_id );
					if ( is_wp_error( $sum ) ) { return $sum; }
					$price = $choice['sales_price'] ?? $sum;
					if ( ! $this->number( $price ) || $price < 0 || $price > self::MAX_PRICE ) { return $this->invalid_pricing(); }
					$system['choices'][ $index ]['sales_price'] = round( (float) $price, 2 );
					if ( $choice['choice_id'] === $system['selected_choice_id'] ) {
						$selected['equipment'] = $choice['equipment'];
						$selected_prices += $system['choices'][ $index ]['sales_price'];
					}
				}
			}
			$sum = $this->catalog_sum( $selected['equipment'], $catalog_by_id );
			if ( is_wp_error( $sum ) ) { return $sum; }
			$selected_catalog_subtotal += $sum;
			if ( ! isset( $system['choices'] ) ) { $selected_prices += $sum; }
			$selected_systems[] = $selected;
			$offered[] = $system;
		}
		$valid = $catalog->validate_selection( $selected_systems, $catalog_by_id );
		if ( is_wp_error( $valid ) ) { return $valid; }
		if ( $has_choices ) {
			$adjustment = array_key_exists( 'project_adjustment', $body['pricing'] ) ? $body['pricing']['project_adjustment'] : 0;
			if ( ! $this->number( $adjustment ) || abs( (float) $adjustment ) > self::MAX_PRICE ) { return $this->invalid_pricing(); }
			$adjustment = round( (float) $adjustment, 2 );
			$total = round( $selected_prices + $adjustment, 2 );
			if ( ! is_finite( $total ) || $total < 0 ) { return $this->invalid_pricing(); }
			if ( array_key_exists( 'total', $body['pricing'] ) && ( ! $this->number( $body['pricing']['total'] ) || abs( (float) $body['pricing']['total'] - $total ) > 0.010000001 ) ) { return $this->invalid_pricing(); }
			$body['pricing']['subtotal'] = round( $selected_catalog_subtotal, 2 );
			$body['pricing']['total'] = $total;
			$body['pricing']['project_adjustment'] = $adjustment;
		}
		$addons = $catalog->resolve_proposal_addons( $body['options'] );
		if ( is_wp_error( $addons ) ) { return $addons; }
		$body['systems'] = $selected_systems;
		$body['_proposal_systems'] = $offered;
		$body['_proposal_addons'] = $addons;
		return $body;
	}

	private function equipment( mixed $equipment ): array|WP_Error {
		if ( ! is_array( $equipment ) || empty( $equipment ) || count( $equipment ) > count( self::SLOTS ) ) { return $this->invalid_equipment(); }
		$result = array();
		foreach ( $equipment as $slot => $item ) {
			if ( ! is_string( $slot ) || ! in_array( $slot, self::SLOTS, true ) || ! is_array( $item ) || ! is_string( $item['item_id'] ?? null ) || 1 !== preg_match( '/^[0-9]{10,20}$/D', $item['item_id'] ) ) { return $this->invalid_equipment(); }
			$record = array( 'item_id' => $item['item_id'] );
			foreach ( array( 'description', 'notes' ) as $field ) {
				if ( ! array_key_exists( $field, $item ) ) { continue; }
				$text = $this->text( $item[ $field ], 2000, true );
				if ( null === $text ) { return $this->invalid_equipment(); }
				$record[ $field ] = $text;
			}
			if ( 'permit' === $slot && array_key_exists( 'rate', $item ) ) {
				if ( ! is_scalar( $item['rate'] ) || ! is_finite( (float) $item['rate'] ) ) { return $this->invalid_pricing(); }
				$record['rate'] = max( 0, min( 100000, round( (float) $item['rate'], 2 ) ) );
			}
			$result[ $slot ] = $record;
		}
		return $result;
	}

	private function catalog_sum( array $equipment, array $catalog ): float|WP_Error {
		$sum = 0.0;
		foreach ( $equipment as $slot => $selection ) {
			$rate = $catalog[ $selection['item_id'] ]['rate'] ?? null;
			if ( ! $this->number( $rate ) || $rate < 0 ) { return $this->invalid_pricing(); }
			$sum += 'permit' === $slot && isset( $selection['rate'] ) ? $selection['rate'] : $rate;
		}
		return is_finite( $sum ) ? round( $sum, 2 ) : $this->invalid_pricing();
	}

	private function strip_internal( array $body ): array {
		foreach ( $body as $key => $value ) {
			if ( is_string( $key ) && str_starts_with( $key, '_proposal' ) ) { unset( $body[ $key ] ); }
			elseif ( is_array( $value ) ) { $body[ $key ] = $this->strip_internal( $value ); }
		}
		return $body;
	}
	private function text( mixed $value, int $limit, bool $multiline = false ): ?string {
		if ( ! is_string( $value ) && ! is_numeric( $value ) && null !== $value ) { return null; }
		$text = $multiline ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
		return mb_substr( $text, 0, $limit );
	}
	private function choice_id( mixed $id ): bool { return is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', $id ); }
	private function number( mixed $value ): bool { return ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value ); }
	private function system_number( mixed $number ): ?int {
		if ( is_string( $number ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $number ) && ( strlen( $number ) < strlen( (string) PHP_INT_MAX ) || ( strlen( $number ) === strlen( (string) PHP_INT_MAX ) && strcmp( $number, (string) PHP_INT_MAX ) <= 0 ) ) ) { $number = (int) $number; }
		return is_int( $number ) && $number > 0 ? $number : null;
	}
	private function invalid_choices(): WP_Error { return new WP_Error( 'tc_estimate_bad_proposal_choices', __( 'Proposal choices are invalid or exceed the supported limits.', 'tc-estimate' ), array( 'status' => 400 ) ); }
	private function invalid_equipment(): WP_Error { return new WP_Error( 'tc_estimate_bad_equipment_selection', __( 'Equipment selections are invalid.', 'tc-estimate' ), array( 'status' => 400 ) ); }
	private function invalid_pricing(): WP_Error { return new WP_Error( 'tc_estimate_bad_pricing', __( 'Proposal sales prices and the project total must be finite numbers and match the selected choices plus project adjustment.', 'tc-estimate' ), array( 'status' => 400 ) ); }
}
