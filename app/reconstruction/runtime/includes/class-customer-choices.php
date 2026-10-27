<?php
/** Scoped customer choices for an existing, unaccepted Books estimate. */
declare( strict_types=1 );
namespace TempControl\Estimate;
use WP_Error;
defined( 'ABSPATH' ) || exit;

final class Customer_Choices {
	private static ?self $instance = null;
	private const PREFIX = 'tc_estimate_choice_';
	private const MAX_BYTES = 524288;
	private const MAX_REQUESTS = 20;
	private const TTL = 2592000;
	private const CRM_CORE_FIELDS = array( 'Slot', 'System_Number', 'Zoho_Item_ID' );
	private const CRM_FIELDS = array( 'Slot', 'System_Number', 'System_Label', 'Name', 'Brand', 'Model', 'Zoho_Item_ID', 'CRM_Equipment_ID', 'Rate', 'Short_Description', 'SEER', 'AFUE', 'Tons', 'BTU_Input', 'BTU_Output', 'Refrigerant' );
	private ?array $held_lock = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	/** Called only by the staff endpoint or the existing owner-bound send job. */
	public function link_for_estimate( string $estimate_id, int $owner_user_id ): array|WP_Error {
		if ( ! $this->valid_id( $estimate_id ) || ! $this->owner_allowed( $owner_user_id ) ) { return $this->error( 'unavailable', 403 ); }
		$row = Audit_Log::instance()->find_by_estimate_action( $estimate_id, 'generate', $owner_user_id );
		if ( ! is_array( $row ) || 'success' !== ( $row['status'] ?? '' ) || (string) ( $row['zoho_estimate_id'] ?? '' ) !== $estimate_id || (int) ( $row['user_id'] ?? $owner_user_id ) !== $owner_user_id ) { return $this->error( 'unavailable', 403 ); }
		$org = $this->org();
		if ( '' === $org ) { return $this->error( 'unavailable', 503 ); }
		$payload_json = (string) ( $row['payload'] ?? '' );
		if ( strlen( $payload_json ) > self::MAX_BYTES ) { return $this->error( 'unavailable', 400 ); }
		$source = json_decode( $payload_json, true );
		if ( ! is_array( $source ) || ! $this->has_choices( $source['_proposal_systems'] ?? array() ) ) { return array( 'available' => false ); }
		$source_hash = hash( 'sha256', $payload_json );
		$customer_id = $this->customer_id_from_row( $row );
		if ( is_wp_error( $customer_id ) ) { return $customer_id; }
		$index = $this->index_key( $org, $estimate_id );
		$existing_id = get_option( $index, '' );
		if ( is_string( $existing_id ) && preg_match( '/^[a-f0-9]{32}$/D', $existing_id ) ) {
			$existing = get_option( self::PREFIX . $existing_id, null );
			if ( is_array( $existing ) && $this->record_allowed( $existing ) && hash_equals( (string) $existing['source_hash'], $source_hash ) && (int) $existing['owner_id'] === $owner_user_id ) {
				$current = $this->get_estimate( $estimate_id, $org );
				if ( is_wp_error( $current ) ) { return $current; }
				if ( $customer_id !== $existing['customer_id'] || ! $this->safe_estimate( $current, $estimate_id, $customer_id ) || ! hash_equals( $existing['baseline'], $this->financial_fingerprint( $current ) ) || is_array( $existing['pending'] ) ) { return $this->error( 'changed', 409 ); }
				$deal = $this->get_deal( $existing );
				if ( is_wp_error( $deal ) ) { return $deal; }
				if ( ! $this->crm_baseline_matches( $deal, $existing ) ) { return $this->error( 'changed', 409 ); }
				return $this->link_response( $existing );
			}
			// Expired/revoked links are never silently replaced by a retry.
			return $this->error( 'unavailable', 409 );
		}
		$source['systems'] = $source['_proposal_systems'];
		unset( $source['books_edits'] );
		$source['options'] = array_intersect_key( (array) ( $source['options'] ?? array() ), array_flip( array( 'warranty_parts_years', 'warranty_labor_years', 'special_notes', 'rebates', 'financing_requested', 'financing_term_months', 'credit_card_fee_applies', 'financing_fee_applies', 'include_maintenance', 'include_permits' ) ) );
		$normalized = Proposal_Choices::instance()->normalize( $source );
		if ( is_wp_error( $normalized ) ) { return $this->error( 'unavailable', 409 ); }
		$catalog = array();
		foreach ( Proposal_Choices::instance()->collect_item_ids( $normalized ) as $id ) {
			$item = Equipment_Catalog::instance()->get_item( $id );
			if ( is_wp_error( $item ) ) { return $this->error( 'unavailable', 409 ); }
			$catalog[ $id ] = $this->catalog_snapshot( $item );
		}
		$projected = Proposal_Choices::instance()->project( $normalized, $catalog );
		if ( is_wp_error( $projected ) ) { return $this->error( 'unavailable', 409 ); }
		$estimate = $this->get_estimate( $estimate_id, $org );
		if ( is_wp_error( $estimate ) ) { return $estimate; }
		if ( ! $this->safe_estimate( $estimate, $estimate_id, $customer_id ) ) { return $this->error( 'changed', 409 ); }
		$meta = Template_Meta::instance()->hydrate( (int) ( $row['template_id'] ?? $source['template_id'] ?? 0 ) );
		$customer = array( 'id' => (string) ( $row['zoho_account_id'] ?? '' ), 'books_contact_id' => $customer_id );
		$view = Token_Renderer::instance()->build_payload_view( $projected, $customer, $catalog, $meta );
		$preview = Estimate_Generator::instance()->build_books_preview( $view, $customer, $meta );
		$lines = $this->preview_lines( $preview );
		$total = (float) ( $projected['pricing']['total'] ?? 0 );
		if ( empty( $lines ) || ! $this->matches_lines( $estimate, $lines, $total ) ) { return $this->error( 'changed', 409 ); }
		$now = time();
		$expiry = $now + self::TTL;
		if ( ! empty( $row['created_at'] ) ) {
			$created = strtotime( (string) $row['created_at'] . ' UTC' );
			if ( false !== $created ) { $expiry = min( $expiry, $created + self::TTL ); }
		}
		if ( ! empty( $estimate['expiry_date'] ) ) {
			$quote_expiry = strtotime( (string) $estimate['expiry_date'] . ' 23:59:59 UTC' );
			if ( false !== $quote_expiry ) { $expiry = min( $expiry, $quote_expiry ); }
		}
		if ( $expiry <= $now ) { return $this->error( 'unavailable', 409 ); }
		$id = bin2hex( random_bytes( 16 ) );
		$record = array(
			'id' => $id, 'owner_id' => $owner_user_id, 'org_id' => $org, 'estimate_id' => $estimate_id,
			'customer_id' => $customer_id, 'deal_id' => (string) ( $row['zoho_deal_id'] ?? '' ),
			'created_at' => $now, 'expires_at' => $expiry, 'revoked' => false, 'source_hash' => $source_hash,
			'source_body' => $normalized, 'catalog' => $catalog, 'customer' => $customer, 'template_meta' => $meta,
			'systems' => $this->public_systems( $view ), 'addons' => $this->public_addons( $view ),
			'selected' => $this->selection_map( $projected['_proposal_systems'] ),
			'project_adjustment' => (float) ( $projected['pricing']['project_adjustment'] ?? 0 ),
			'total' => $total, 'deposit_percent' => (int) ( $projected['pricing']['deposit_percent'] ?? 35 ),
			'baseline' => $this->financial_fingerprint( $estimate ), 'current_keys' => array_column( $lines, 'key' ),
			'revision' => 1, 'confirmed' => false, 'state' => 'ready', 'requests' => array(), 'pending' => null,
			'review_url' => $this->review_url( $estimate ),
		);
		$deal = $this->get_deal( $record );
		if ( is_wp_error( $deal ) ) { return $deal; }
		$initial_rows = $this->crm_rows( $projected, $catalog );
		$contract = $this->crm_contract( $deal, $initial_rows );
		if ( false === $contract ) { return $this->error( 'changed', 409 ); }
		$record['crm_field_contract'] = $contract;
		$crm_baseline = $this->crm_snapshot( $deal, $record, $initial_rows, $total, null, true );
		if ( false === $crm_baseline ) { return $this->error( 'changed', 409 ); }
		$record['crm_baseline'] = $crm_baseline;
		$record['signature_hash'] = hash( 'sha256', $this->signature( $record ) );
		if ( strlen( wp_json_encode( $record ) ) > self::MAX_BYTES || ! add_option( self::PREFIX . $id, $record, '', false ) ) { return $this->error( 'unavailable', 503 ); }
		if ( ! add_option( $index, $id, '', false ) ) {
			delete_option( self::PREFIX . $id );
			return $this->error( 'in_flight', 409 );
		}
		return $this->link_response( $record );
	}

	public function revoke_for_estimate( string $estimate_id, int $owner ): array|WP_Error {
		if ( ! $this->valid_id( $estimate_id ) || ! $this->owner_allowed( $owner ) ) { return $this->error( 'unavailable', 403 ); }
		$audit = Audit_Log::instance()->find_by_estimate_action( $estimate_id, 'generate', $owner );
		if ( ! is_array( $audit ) || 'success' !== ( $audit['status'] ?? '' ) ) { return $this->error( 'unavailable', 403 ); }
		$id = get_option( $this->index_key( $this->org(), $estimate_id ), '' );
		$record = is_string( $id ) ? get_option( self::PREFIX . $id, null ) : null;
		if ( ! is_array( $record ) || (int) ( $record['owner_id'] ?? 0 ) !== $owner ) { return $this->error( 'unavailable', 404 ); }
		$lock_key = self::PREFIX . 'lock_' . $record['id'];
		$lock = bin2hex( random_bytes( 12 ) );
		$acquired = $this->acquire_lock( $lock_key, $lock );
		if ( false === $acquired ) { return $this->error( 'in_flight', 409 ); }
		try {
			$record = get_option( self::PREFIX . $id, null );
			if ( ! is_array( $record ) || (int) $record['owner_id'] !== $owner ) { return $this->error( 'unavailable', 404 ); }
			$record['revoked'] = true;
			if ( ! $this->persist( $record ) ) { return $this->error( 'unavailable', 503 ); }
			return array( 'revoked' => true );
		} finally {
			$current_lock = get_option( $lock_key, null );
			if ( is_array( $current_lock ) && hash_equals( $lock, (string) ( $current_lock['token'] ?? '' ) ) ) { delete_option( $lock_key ); }
			$this->held_lock = null;
		}
	}

	/** Does not contact or mutate either provider. */
	public function view( string $token ): array|WP_Error {
		$record = $this->authenticate( $token );
		if ( is_wp_error( $record ) ) { return $record; }
		$limited = $this->rate_limit( 'view_' . $record['id'], 60 );
		if ( is_wp_error( $limited ) ) { return $limited; }
		return $this->public_view( $record );
	}

	/** The only public mutation: replace this quote's choices, never create or accept. */
	public function apply( string $token, int $revision, string $request_id, array $selections ): array|WP_Error {
		$record = $this->authenticate( $token );
		if ( is_wp_error( $record ) ) { return $record; }
		if ( $revision < 1 || ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/D', $request_id ) ) { return $this->error( 'bad_selection', 400 ); }
		$selected = $this->validated_selections( $record, $selections );
		if ( is_wp_error( $selected ) ) { return $selected; }
		$hash = hash( 'sha256', wp_json_encode( array( 'revision' => $revision, 'selections' => $selected ) ) );
		if ( isset( $record['requests'][ $request_id ] ) ) {
			$prior = $record['requests'][ $request_id ];
			if ( ! hash_equals( (string) $prior['hash'], $hash ) ) { return $this->error( 'bad_selection', 409 ); }
			if ( isset( $prior['result'] ) ) { return $prior['result']; }
		}
		if ( is_array( $record['pending'] ) && (string) $record['pending']['request_id'] !== $request_id ) { return $this->error( 'in_flight', 409 ); }
		if ( ! is_array( $record['pending'] ) && $revision !== (int) $record['revision'] ) { return $this->error( 'stale_revision', 409 ); }
		$limited = $this->rate_limit( 'apply_' . $record['id'], 10 );
		if ( is_wp_error( $limited ) ) { return $limited; }
		$lock_key = self::PREFIX . 'lock_' . $record['id'];
		$lock = bin2hex( random_bytes( 12 ) );
		$acquired = $this->acquire_lock( $lock_key, $lock );
		if ( false === $acquired ) { return $this->error( 'in_flight', 409 ); }
		try {
			// Reload after the atomic lock; the earlier read cannot authorize a race.
			$record = $this->authenticate( $token );
			if ( is_wp_error( $record ) ) { return $record; }
			if ( isset( $record['requests'][ $request_id ]['result'] ) ) {
				if ( ! hash_equals( (string) $record['requests'][ $request_id ]['hash'], $hash ) ) { return $this->error( 'bad_selection', 409 ); }
				return $record['requests'][ $request_id ]['result'];
			}
			if ( is_array( $record['pending'] ) ) {
				if ( $record['pending']['request_id'] !== $request_id || ! hash_equals( $record['pending']['hash'], $hash ) ) { return $this->error( 'in_flight', 409 ); }
				return $this->reconcile( $record );
			}
			if ( $revision !== (int) $record['revision'] ) { return $this->error( 'stale_revision', 409 ); }
			if ( count( $record['requests'] ) >= self::MAX_REQUESTS ) { return $this->error( 'rate_limited', 429 ); }
			$current = $this->get_estimate( $record['estimate_id'], $record['org_id'] );
			if ( is_wp_error( $current ) ) { return $current; }
			if ( ! $this->safe_estimate( $current, $record['estimate_id'], $record['customer_id'] ) || ! hash_equals( $record['baseline'], $this->financial_fingerprint( $current ) ) ) { return $this->error( 'changed', 409 ); }
			$deal = $this->get_deal( $record );
			if ( is_wp_error( $deal ) ) { return $deal; }
			if ( ! $this->crm_baseline_matches( $deal, $record ) ) { return $this->error( 'changed', 409 ); }
			$prepared = $this->prepare_selection( $record, $selected, $current );
			if ( is_wp_error( $prepared ) ) { return $prepared; }
			$record['requests'][ $request_id ] = array( 'hash' => $hash );
			$record['pending'] = array(
				'request_id' => $request_id, 'hash' => $hash, 'revision' => $revision, 'selections' => $selected,
				'update' => $prepared['update'], 'lines' => $prepared['lines'], 'total' => $prepared['total'],
				'crm_rows' => $prepared['crm_rows'], 'phase' => 'prepared', 'started_at' => time(),
			);
			$record['state'] = 'pending';
			if ( ! $this->persist( $record ) ) { return $this->error( 'unavailable', 503 ); }
			if ( $this->matches_lines( $current, $prepared['lines'], $prepared['total'] ) && $this->matches_update_text( $current, $prepared['update'] ) ) { return $this->confirm_books( $record, $current ); }
			$record['pending']['phase'] = 'put_started';
			if ( ! $this->persist( $record ) ) { return $this->error( 'unavailable', 503 ); }
			// Full-array replacement is idempotent financially, but an unknown result is
			// still reconciled by GET before any subsequent mutation is permitted.
			$result = Zoho_API::instance()->put_once( Zoho_API::SERVICE_BOOKS, '/estimates/' . $record['estimate_id'] . '?organization_id=' . rawurlencode( $record['org_id'] ) . '&send=false', $prepared['update'] );
			if ( is_wp_error( $result ) || ( isset( $result['code'] ) && 0 !== (int) $result['code'] ) ) {
				$record['pending']['phase'] = 'uncertain';
				$this->persist( $record );
				return $this->pending_result( $record );
			}
			return $this->reconcile( $record );
		} finally {
			$current_lock = get_option( $lock_key, null );
			if ( is_array( $current_lock ) && hash_equals( $lock, (string) ( $current_lock['token'] ?? '' ) ) ) { delete_option( $lock_key ); }
			$this->held_lock = null;
		}
	}

	private function reconcile( array $record ): array|WP_Error {
		$estimate = $this->get_estimate( $record['estimate_id'], $record['org_id'] );
		if ( is_wp_error( $estimate ) ) { return $this->pending_result( $record ); }
		if ( ! $this->safe_estimate( $estimate, $record['estimate_id'], $record['customer_id'] ) ) { return $this->error( 'changed', 409 ); }
		if ( ! $this->matches_lines( $estimate, $record['pending']['lines'], $record['pending']['total'] ) || ! $this->matches_update_text( $estimate, $record['pending']['update'] ) ) {
			// Neither a stale read nor a failed/uncertain request authorizes another PUT.
			return $this->pending_result( $record );
		}
		return $this->confirm_books( $record, $estimate );
	}

	private function confirm_books( array $record, array $estimate ): array|WP_Error {
		$pending = $record['pending'];
		if ( empty( $pending['books_confirmed'] ) ) {
			$record['baseline'] = $this->financial_fingerprint( $estimate );
			$record['selected'] = $pending['selections'];
			$record['total'] = $pending['total'];
			$record['current_keys'] = array_column( $pending['lines'], 'key' );
			$record['revision']++;
			$record['confirmed'] = true;
			$record['review_url'] = $this->review_url( $estimate );
			$record['state'] = 'books_updated';
			$record['pending']['books_confirmed'] = true;
			$record['pending']['phase'] = 'crm_pending';
			if ( ! $this->persist( $record ) ) { return $this->pending_result( $record ); }
		}
		$deal = $this->get_deal( $record );
		if ( is_wp_error( $deal ) ) { return $this->saved_result( $record, true ); }
		$desired_crm = $this->crm_snapshot( $deal, $record, $pending['crm_rows'], (float) $record['total'], $record['crm_baseline'] );
		if ( false === $desired_crm ) {
			// A write with an unknown outcome is GET-reconciled only. New rows without
			// IDs append in CRM v3+, so repeating an uncertain request is unsafe.
			if ( in_array( $record['pending']['phase'], array( 'crm_put_started', 'crm_uncertain' ), true ) || ! $this->crm_baseline_matches( $deal, $record ) ) { return $this->saved_result( $record, true ); }
			$rows = $this->crm_update_rows( $record, $pending['crm_rows'], $deal );
			if ( false === $rows ) { return $this->saved_result( $record, true ); }
			$record['pending']['phase'] = 'crm_put_started';
			if ( ! $this->persist( $record ) ) { return $this->saved_result( $record, true ); }
			Zoho_API::instance()->put_once( Zoho_API::SERVICE_CRM, '/Deals/' . $record['deal_id'], array( 'data' => array( array( 'id' => $record['deal_id'], 'Amount' => (float) $record['total'], 'Quoted_Equipment' => $rows ) ), 'trigger' => array() ) );
			$record['pending']['phase'] = 'crm_uncertain';
			$this->persist( $record );
			$deal = $this->get_deal( $record );
			$desired_crm = is_wp_error( $deal ) ? false : $this->crm_snapshot( $deal, $record, $pending['crm_rows'], (float) $record['total'], $record['crm_baseline'] );
			if ( false === $desired_crm ) { return $this->saved_result( $record, true ); }
		}
		$record['crm_baseline'] = $desired_crm;
		$record['state'] = 'saved';
		$result = $this->saved_result( $record, false );
		$record['requests'][ $pending['request_id'] ]['result'] = $result;
		$record['pending'] = null;
		if ( ! $this->persist( $record ) ) {
			// The durable CRM-pending record remains reconcilable after local failure.
			$record['pending'] = $pending;
			$record['pending']['books_confirmed'] = true;
			$record['pending']['phase'] = 'crm_uncertain';
			$record['state'] = 'books_updated';
			return $this->saved_result( $record, true );
		}
		return $result;
	}

	private function prepare_selection( array $record, array $selected, array $current ): array|WP_Error {
		$body = $record['source_body'];
		foreach ( $body['systems'] as &$system ) {
			$number = (int) $system['system_number'];
			if ( isset( $system['choices'] ) ) { $system['selected_choice_id'] = $selected[ $number ]; }
		}
		unset( $system );
		$body['pricing']['total'] = $this->selected_total( $record, $selected );
		$normalized = Proposal_Choices::instance()->normalize( $body );
		if ( is_wp_error( $normalized ) ) { return $this->error( 'bad_selection', 400 ); }
		// Check currently chosen identity/specification and eligibility. Retail prices
		// remain the immutable quoted values, not a fresh market-price substitution.
		foreach ( $normalized['systems'] as $system ) {
			foreach ( $system['equipment'] as $selection ) {
				$id = $selection['item_id'];
				$fresh = Equipment_Catalog::instance()->get_item( $id );
				if ( is_wp_error( $fresh ) || ! isset( $record['catalog'][ $id ] ) || ! $this->same_identity( $record['catalog'][ $id ], $fresh ) ) { return $this->error( 'changed', 409 ); }
				$raw = Zoho_API::instance()->get( Zoho_API::SERVICE_BOOKS, '/items/' . $id, array( 'organization_id' => $record['org_id'] ) );
				if ( is_wp_error( $raw ) || ! isset( $raw['item'] ) || ! $this->safe_item_tax( $raw['item'] ) ) { return $this->error( 'changed', 409 ); }
			}
		}
		$projected = Proposal_Choices::instance()->project( $normalized, $record['catalog'] );
		if ( is_wp_error( $projected ) ) { return $this->error( 'changed', 409 ); }
		foreach ( (array) ( $projected['_proposal_addons'] ?? array() ) as $key => $addon ) {
			$old = null;
			foreach ( $record['addons'] as $stored ) { if ( $stored['addon_key'] === $key ) { $old = $stored; break; } }
			if ( ! is_array( $old ) || (string) $addon['item_id'] !== (string) ( $old['_item_id'] ?? '' ) ) { return $this->error( 'changed', 409 ); }
		}
		$view = Token_Renderer::instance()->build_payload_view( $projected, $record['customer'], $record['catalog'], $record['template_meta'] );
		$preview = Estimate_Generator::instance()->build_books_preview( $view, $record['customer'], $record['template_meta'] );
		$lines = $this->preview_lines( $preview );
		if ( empty( $lines ) || count( $lines ) > 50 ) { return $this->error( 'changed', 409 ); }
		$rate_sum = 0.0;
		$updates = array();
		$old_by_key = array();
		foreach ( $record['current_keys'] as $index => $key ) { $old_by_key[ $key ] = $current['line_items'][ $index ] ?? array(); }
		foreach ( $lines as $line ) {
			$rate_sum += $line['rate'];
			$update = array_intersect_key( $line, array_flip( array( 'item_id', 'name', 'description', 'rate', 'quantity' ) ) );
			$old = $old_by_key[ $line['key'] ] ?? array();
			if ( (string) ( $old['item_id'] ?? '' ) === $line['item_id'] ) {
				$unchanged_choice = true;
				foreach ( $record['systems'] as $offered ) {
					$number = $offered['system_number'];
					if ( str_starts_with( $line['key'], 's' . $number . ':' ) || str_starts_with( $line['key'], 'system_' . $number . '_' ) ) { $unchanged_choice = $record['selected'][ $number ] === $selected[ $number ]; break; }
				}
				foreach ( $unchanged_choice ? array( 'line_item_id', 'name', 'description', 'unit' ) : array( 'line_item_id', 'unit' ) as $field ) { if ( isset( $old[ $field ] ) ) { $update[ $field ] = $old[ $field ]; } }
			}
			$updates[] = $update;
		}
		$total = (float) $projected['pricing']['total'];
		$update = array( 'customer_id' => $record['customer_id'], 'line_items' => $updates, 'adjustment' => round( $total - $rate_sum, 2 ), 'adjustment_description' => 0.0 === round( $total - $rate_sum, 2 ) ? '' : 'Project price adjustment', 'notes' => (string) ( $current['notes'] ?? '' ), 'terms' => (string) ( $current['terms'] ?? '' ) );
		foreach ( array( 'currency_id', 'exchange_rate', 'is_inclusive_tax', 'tax_exemption_id', 'tax_exemption_code', 'reference_number', 'date', 'expiry_date' ) as $field ) { if ( isset( $current[ $field ] ) ) { $update[ $field ] = $current[ $field ]; } }
		return array( 'update' => $update, 'lines' => $lines, 'total' => $total, 'crm_rows' => $this->crm_rows( $projected, $record['catalog'] ) );
	}

	private function public_view( array $record ): array {
		$systems = $record['systems'];
		foreach ( $systems as &$system ) { $system['selected_choice_id'] = $record['selected'][ $system['system_number'] ]; }
		unset( $system );
		$result = array( 'revision' => (int) $record['revision'], 'state' => $record['state'], 'systems' => $systems, 'project_adjustment' => (float) $record['project_adjustment'], 'total' => (float) $record['total'], 'deposit_percent' => (int) $record['deposit_percent'], 'addons' => array_map( static fn( array $addon ): array => array_diff_key( $addon, array_flip( array( '_item_id' ) ) ), $record['addons'] ), 'review_url' => $record['confirmed'] && 'saved' === $record['state'] ? (string) $record['review_url'] : '', 'expires_at' => (int) $record['expires_at'], 'crm_sync_pending' => 'books_updated' === $record['state'] );
		if ( is_array( $record['pending'] ) ) { $result['pending'] = $this->public_pending( $record['pending'] ); }
		return $result;
	}
	private function saved_result( array $record, bool $crm_pending ): array {
		$result = array( 'saved' => true, 'state' => $crm_pending ? 'books_updated' : 'saved', 'revision' => (int) $record['revision'], 'total' => (float) $record['total'], 'selections' => $this->selection_list( $record['selected'] ), 'review_url' => $crm_pending ? '' : (string) $record['review_url'], 'crm_sync_pending' => $crm_pending );
		if ( $crm_pending && is_array( $record['pending'] ) ) { $result['pending'] = $this->public_pending( $record['pending'] ); }
		return $result;
	}
	private function pending_result( array $record ): array {
		return array( 'saved' => false, 'state' => 'pending', 'revision' => (int) $record['revision'], 'total' => (float) $record['total'], 'review_url' => '', 'pending' => $this->public_pending( $record['pending'] ) );
	}
	private function public_pending( array $pending ): array { return array( 'request_id' => $pending['request_id'], 'revision' => (int) $pending['revision'], 'selections' => $this->selection_list( $pending['selections'] ), 'status' => $pending['phase'] ); }

	private function authenticate( string $token ): array|WP_Error {
		if ( ! preg_match( '/^([a-f0-9]{32})\.([a-f0-9]{64})$/D', $token, $match ) ) { return $this->error( 'unavailable', 401 ); }
		$record = get_option( self::PREFIX . $match[1], null );
		if ( ! is_array( $record ) || ! $this->record_allowed( $record ) || ! hash_equals( (string) $record['signature_hash'], hash( 'sha256', $match[2] ) ) || ! hash_equals( $this->signature( $record ), $match[2] ) ) { return $this->error( 'unavailable', 401 ); }
		return $record;
	}
	private function record_allowed( array $r ): bool { return isset( $r['id'], $r['owner_id'], $r['org_id'], $r['estimate_id'], $r['expires_at'], $r['source_hash'], $r['signature_hash'] ) && empty( $r['revoked'] ) && (int) $r['expires_at'] > time() && hash_equals( (string) $r['org_id'], $this->org() ) && $this->owner_allowed( (int) $r['owner_id'] ); }
	private function signature( array $r ): string { return hash_hmac( 'sha256', implode( '|', array( 'tc-estimate-choices-v1', $r['id'], $r['owner_id'], $r['org_id'], $r['estimate_id'], $r['expires_at'], $r['source_hash'] ) ), wp_salt( 'auth' ) ); }
	private function link_response( array $r ): array { return array( 'available' => true, 'url' => home_url( '/?tc_estimate_choices=1&ver=' . rawurlencode( TC_ESTIMATE_VERSION ) ) . '#token=' . $r['id'] . '.' . $this->signature( $r ), 'expires_at' => (int) $r['expires_at'], 'revision' => (int) $r['revision'] ); }
	private function owner_allowed( int $owner ): bool { return $owner > 0 && get_userdata( $owner ) && ( user_can( $owner, TC_ESTIMATE_CAP ) || user_can( $owner, 'manage_options' ) ); }
	private function org(): string { $org = trim( (string) get_option( 'tc_estimate_zoho_org_id', '' ) ); return preg_match( '/^[0-9]{5,20}$/D', $org ) ? $org : ''; }
	private function valid_id( string $id ): bool { return 1 === preg_match( '/^[0-9]{15,20}$/D', $id ); }
	private function valid_item_id( string $id ): bool { return 1 === preg_match( '/^[0-9]{10,20}$/D', $id ); }
	private function index_key( string $org, string $id ): string { return self::PREFIX . 'estimate_' . md5( $org . ':' . $id ); }
	private function persist( array $r ): bool {
		if ( strlen( wp_json_encode( $r ) ) > self::MAX_BYTES ) { return false; }
		if ( is_array( $this->held_lock ) ) {
			$current = get_option( $this->held_lock['key'], null );
			if ( ! is_array( $current ) || ! hash_equals( $this->held_lock['token'], (string) ( $current['token'] ?? '' ) ) ) { return false; }
		}
		update_option( self::PREFIX . $r['id'], $r, false );
		return get_option( self::PREFIX . $r['id'], null ) === $r;
	}
	/** No time-based takeover: a paused PHP worker could still resume a provider write. */
	private function acquire_lock( string $key, string $token ): string|false {
		$value = array( 'token' => $token, 'created_at' => time() );
		if ( add_option( $key, $value, '', false ) ) { $this->held_lock = array( 'key' => $key, 'token' => $token ); return 'fresh'; }
		return false;
	}
	private function has_choices( mixed $systems ): bool { if ( ! is_array( $systems ) ) { return false; } foreach ( $systems as $system ) { if ( is_array( $system ) && ! empty( $system['choices'] ) ) { return true; } } return false; }
	/** Independently bind the original CRM addressee, never adopt the quote's current contact. */
	private function customer_id_from_row( array $row ): string|WP_Error {
		$id = (string) ( $row['zoho_account_id'] ?? '' );
		if ( ! $this->valid_id( $id ) ) { return $this->error( 'changed', 409 ); }
		$customer = Customer_Search::instance()->get_account( $id );
		if ( is_wp_error( $customer ) || (string) ( $customer['id'] ?? '' ) !== $id ) { return $this->error( 'unavailable', 502 ); }
		$contact = (string) ( $customer['books_contact_id'] ?? '' );
		if ( $this->valid_id( $contact ) ) { return $contact; }
		$email = strtolower( trim( (string) ( $customer['email'] ?? '' ) ) );
		if ( ! is_email( $email ) ) { return $this->error( 'changed', 409 ); }
		$response = Zoho_API::instance()->get( Zoho_API::SERVICE_BOOKS, '/contacts', array( 'organization_id' => $this->org(), 'contact_type' => 'customer', 'email' => $email, 'per_page' => 200 ) );
		if ( is_wp_error( $response ) || ! is_array( $response['contacts'] ?? null ) || ! empty( $response['page_context']['has_more_page'] ) ) { return $this->error( 'provider', 502 ); }
		$matches = array();
		foreach ( $response['contacts'] as $candidate ) {
			if ( ! is_array( $candidate ) || ! $this->valid_id( (string) ( $candidate['contact_id'] ?? '' ) ) || ( isset( $candidate['status'] ) && 'active' !== strtolower( (string) $candidate['status'] ) ) ) { continue; }
			$emails = array( $candidate['email'] ?? '', $candidate['primary_contact_email'] ?? '' );
			foreach ( (array) ( $candidate['contact_persons'] ?? array() ) as $person ) { if ( is_array( $person ) ) { $emails[] = $person['email'] ?? ''; } }
			foreach ( $emails as $value ) { if ( strtolower( trim( (string) $value ) ) === $email ) { $matches[ (string) $candidate['contact_id'] ] = true; break; } }
		}
		return 1 === count( $matches ) ? (string) array_key_first( $matches ) : $this->error( 'changed', 409 );
	}

	private function get_estimate( string $id, string $org ): array|WP_Error {
		$response = Zoho_API::instance()->get( Zoho_API::SERVICE_BOOKS, '/estimates/' . $id, array( 'organization_id' => $org ) );
		if ( is_wp_error( $response ) || ( isset( $response['code'] ) && 0 !== (int) $response['code'] ) || ! is_array( $response['estimate'] ?? null ) ) { return $this->error( 'provider', 502 ); }
		return $response['estimate'];
	}
	private function safe_estimate( array $e, string $id, string $customer ): bool {
		if ( (string) ( $e['estimate_id'] ?? '' ) !== $id || ! $this->valid_id( $customer ) || (string) ( $e['customer_id'] ?? '' ) !== $customer || ! in_array( strtolower( (string) ( $e['status'] ?? '' ) ), array( 'draft', 'sent' ), true ) || empty( $e['line_items'] ) || count( $e['line_items'] ) > 50 || ! $this->money( $e['total'] ?? null ) || ! $this->money( $e['adjustment'] ?? 0, true ) ) { return false; }
		if ( ! empty( $e['expiry_date'] ) && strtotime( (string) $e['expiry_date'] . ' 23:59:59 UTC' ) < time() ) { return false; }
		if ( isset( $e['currency_code'] ) && 'USD' !== $e['currency_code'] ) { return false; }
		foreach ( array( 'tax_total', 'discount_total', 'discount_amount', 'discount', 'shipping_charge' ) as $field ) { if ( ! $this->zero( $e[ $field ] ?? 0 ) ) { return false; } }
		if ( isset( $e['exchange_rate'] ) && ( ! is_numeric( $e['exchange_rate'] ) || abs( (float) $e['exchange_rate'] - 1 ) > 0.000001 ) ) { return false; }
		foreach ( (array) ( $e['taxes'] ?? array() ) as $tax ) { if ( ! is_array( $tax ) || ! $this->zero( $tax['tax_amount'] ?? $tax['amount'] ?? null ) ) { return false; } }
		$sum = 0.0;
		foreach ( $e['line_items'] as $line ) {
			if ( ! is_array( $line ) || ! $this->valid_item_id( (string) ( $line['item_id'] ?? '' ) ) || ! $this->money( $line['rate'] ?? null ) || ! is_numeric( $line['quantity'] ?? null ) || abs( (float) $line['quantity'] - 1 ) > 0.000001 || ! $this->zero( $line['discount'] ?? 0 ) || ! $this->zero( $line['tax_amount'] ?? 0 ) || ! $this->zero( $line['tax_percentage'] ?? 0 ) || ! empty( $line['tax_id'] ) ) { return false; }
			$sum += (float) $line['rate'];
		}
		return abs( round( $sum + (float) ( $e['adjustment'] ?? 0 ), 2 ) - (float) $e['total'] ) <= 0.010000001;
	}
	private function safe_item_tax( array $item ): bool { return empty( $item['tax_id'] ) && $this->zero( $item['tax_percentage'] ?? 0 ); }
	private function financial_fingerprint( array $e ): string {
		$lines = array();
		foreach ( $e['line_items'] as $line ) { $lines[] = array( 'item_id' => (string) $line['item_id'], 'rate' => number_format( (float) $line['rate'], 6, '.', '' ), 'quantity' => number_format( (float) $line['quantity'], 6, '.', '' ), 'tax_id' => (string) ( $line['tax_id'] ?? '' ), 'discount' => (string) ( $line['discount'] ?? 0 ) ); }
		return hash( 'sha256', wp_json_encode( array( 'customer_id' => (string) $e['customer_id'], 'currency_id' => (string) ( $e['currency_id'] ?? '' ), 'currency_code' => (string) ( $e['currency_code'] ?? 'USD' ), 'exchange_rate' => (string) ( $e['exchange_rate'] ?? 1 ), 'is_inclusive_tax' => (bool) ( $e['is_inclusive_tax'] ?? false ), 'tax_exemption_id' => (string) ( $e['tax_exemption_id'] ?? '' ), 'lines' => $lines, 'adjustment' => number_format( (float) ( $e['adjustment'] ?? 0 ), 2, '.', '' ), 'total' => number_format( (float) $e['total'], 2, '.', '' ) ) ) );
	}
	private function matches_lines( array $e, array $lines, float $total ): bool {
		if ( count( $e['line_items'] ?? array() ) !== count( $lines ) || abs( (float) ( $e['total'] ?? -1 ) - $total ) > 0.010000001 ) { return false; }
		$sum = 0.0;
		foreach ( $lines as $i => $line ) {
			$current = $e['line_items'][ $i ];
			if ( (string) ( $current['item_id'] ?? '' ) !== $line['item_id'] || abs( (float) ( $current['rate'] ?? -1 ) - $line['rate'] ) > 0.000001 || abs( (float) ( $current['quantity'] ?? 0 ) - 1 ) > 0.000001 ) { return false; }
			$sum += $line['rate'];
		}
		return abs( (float) ( $e['adjustment'] ?? 0 ) - round( $total - $sum, 2 ) ) <= 0.010000001;
	}
	private function matches_update_text( array $estimate, array $update ): bool {
		if ( count( $estimate['line_items'] ?? array() ) !== count( $update['line_items'] ?? array() ) ) { return false; }
		foreach ( $update['line_items'] as $index => $line ) {
			foreach ( array( 'name', 'description' ) as $field ) { if ( isset( $line[ $field ] ) && (string) ( $estimate['line_items'][ $index ][ $field ] ?? '' ) !== (string) $line[ $field ] ) { return false; } }
		}
		return true;
	}
	private function preview_lines( array $preview ): array {
		$result = array();
		foreach ( (array) ( $preview['line_items'] ?? array() ) as $line ) {
			if ( ! is_array( $line ) || ! $this->valid_item_id( (string) ( $line['item_id'] ?? '' ) ) || ! $this->money( $line['rate'] ?? null ) ) { return array(); }
			$result[] = array( 'key' => (string) ( $line['key'] ?? '' ), 'item_id' => (string) $line['item_id'], 'name' => (string) ( $line['name'] ?? '' ), 'description' => (string) ( $line['description'] ?? '' ), 'rate' => (float) $line['rate'], 'quantity' => 1 );
		}
		return $result;
	}
	private function catalog_snapshot( array $item ): array { return array_intersect_key( $item, array_flip( array( 'item_id', 'name', 'model', 'brand', 'sku', 'short_description', 'category_2', 'selection_category', 'equipment_type', 'is_composite', 'rate', 'efficiency_percent', 'afue', 'seer', 'tons', 'btu_input', 'btu_output', 'refrigerant', 'for_estimate', 'package_tagged', 'package_tag_id', 'package_tag_option_id' ) ) ); }
	private function same_identity( array $old, array $fresh ): bool { foreach ( array( 'item_id', 'name', 'model', 'brand', 'category_2', 'selection_category', 'is_composite', 'efficiency_percent', 'afue', 'seer', 'tons', 'btu_input', 'btu_output' ) as $key ) { if ( ( $old[ $key ] ?? null ) !== ( $fresh[ $key ] ?? null ) ) { return false; } } return true; }
	private function public_systems( array $view ): array {
		$result = array();
		foreach ( (array) ( $view['proposal_systems'] ?? array() ) as $system ) {
			$choices = array();
			foreach ( $system['choices'] as $choice ) {
				$items = array();
				foreach ( $choice['items'] as $item ) { $items[] = array_intersect_key( $item, array_flip( array( 'name', 'display_name', 'brand', 'model', 'short_description', 'category_2', 'selection_category', 'efficiency_percent', 'afue', 'seer', 'tons', 'btu_input', 'btu_output' ) ) ); }
				$choices[] = array( 'choice_id' => (string) $choice['choice_id'], 'label' => (string) $choice['label'], 'sales_price' => (float) $choice['sales_price'], 'items' => $items );
			}
			$result[] = array( 'system_number' => (int) $system['system_number'], 'system_label' => (string) $system['system_label'], 'selected_choice_id' => (string) $system['selected_choice_id'], 'choices' => $choices );
		}
		return $result;
	}
	private function public_addons( array $view ): array {
		$result = array();
		foreach ( (array) ( $view['proposal_addons'] ?? array() ) as $key => $addon ) {
			$record = array_intersect_key( $addon, array_flip( array( 'addon_key', 'name', 'description', 'rate', 'quantity', 'renewal_rate', 'renewal_units' ) ) );
			$record['addon_key'] = $key;
			$record['_item_id'] = (string) $addon['item_id'];
			$result[] = $record;
		}
		return $result;
	}
	private function selection_map( array $systems ): array { $out = array(); foreach ( $systems as $system ) { $n = (int) $system['system_number']; $out[ $n ] = isset( $system['choices'] ) ? (string) $system['selected_choice_id'] : 'legacy_' . $n; } ksort( $out, SORT_NUMERIC ); return $out; }
	private function selection_list( array $map ): array { $out = array(); foreach ( $map as $n => $id ) { $out[] = array( 'system_number' => (int) $n, 'choice_id' => $id ); } return $out; }
	private function validated_selections( array $record, array $input ): array|WP_Error {
		if ( ! array_is_list( $input ) || count( $input ) !== count( $record['systems'] ) || count( $input ) > 10 ) { return $this->error( 'bad_selection', 400 ); }
		$result = array();
		foreach ( $input as $selection ) {
			if ( ! is_array( $selection ) || array_diff( array_keys( $selection ), array( 'system_number', 'choice_id' ) ) || ! is_int( $selection['system_number'] ?? null ) || ! is_string( $selection['choice_id'] ?? null ) || isset( $result[ $selection['system_number'] ] ) ) { return $this->error( 'bad_selection', 400 ); }
			$system = null;
			foreach ( $record['systems'] as $offered ) { if ( $offered['system_number'] === $selection['system_number'] ) { $system = $offered; break; } }
			if ( ! is_array( $system ) || ! in_array( $selection['choice_id'], array_column( $system['choices'], 'choice_id' ), true ) ) { return $this->error( 'bad_selection', 400 ); }
			$result[ $selection['system_number'] ] = $selection['choice_id'];
		}
		ksort( $result, SORT_NUMERIC );
		return $result;
	}
	private function selected_total( array $record, array $selected ): float { $total = (float) $record['project_adjustment']; foreach ( $record['systems'] as $system ) { foreach ( $system['choices'] as $choice ) { if ( $choice['choice_id'] === $selected[ $system['system_number'] ] ) { $total += $choice['sales_price']; } } } return round( $total, 2 ); }
	private function crm_rows( array $body, array $catalog ): array {
		$rows = array();
		foreach ( $body['systems'] as $system ) {
			foreach ( $system['equipment'] as $slot => $selection ) {
				$item = $catalog[ $selection['item_id'] ];
				$row = array( 'Slot' => $slot, 'System_Number' => (int) $system['system_number'], 'System_Label' => (string) $system['system_label'], 'Name' => (string) ( $item['name'] ?? '' ), 'Brand' => (string) ( $item['brand'] ?? '' ), 'Model' => (string) ( $item['model'] ?? '' ), 'Zoho_Item_ID' => $selection['item_id'], 'CRM_Equipment_ID' => $selection['item_id'], 'Rate' => (float) ( $selection['rate'] ?? $item['rate'] ), 'Short_Description' => (string) ( $selection['description'] ?? $item['short_description'] ?? '' ) );
				foreach ( array( 'SEER' => 'seer', 'AFUE' => 'afue', 'Tons' => 'tons', 'BTU_Input' => 'btu_input', 'BTU_Output' => 'btu_output', 'Refrigerant' => 'refrigerant' ) as $target => $field ) { $row[ $target ] = (string) ( $item[ $field ] ?? '' ); }
				$rows[] = $row;
			}
		}
		return $rows;
	}
	private function get_deal( array $record ): array|WP_Error {
		if ( ! $this->valid_id( (string) $record['deal_id'] ) ) { return $this->error( 'changed', 409 ); }
		$response = Zoho_API::instance()->get( Zoho_API::SERVICE_CRM, '/Deals/' . $record['deal_id'] );
		$deal = is_array( $response ) ? ( $response['data'][0] ?? null ) : null;
		if ( is_wp_error( $response ) || ! is_array( $deal ) ) { return $this->error( 'provider', 502 ); }
		if ( (string) ( $deal['id'] ?? '' ) !== $record['deal_id'] || (string) ( $deal['Account_Name']['id'] ?? '' ) !== $record['customer']['id'] || (string) ( $deal['Books_Estimate_ID'] ?? '' ) !== $record['estimate_id'] || ! is_array( $deal['Quoted_Equipment'] ?? null ) || count( $deal['Quoted_Equipment'] ) > 100 || ! $this->money( $deal['Amount'] ?? null ) ) { return $this->error( 'changed', 409 ); }
		return $deal;
	}
	private function crm_key( array $row ): string { return (int) ( $row['System_Number'] ?? 0 ) . ':' . (string) ( $row['Slot'] ?? '' ); }
	/** Presence is evidence of support; unknown aliases and provider metadata are excluded. */
	private function crm_contract( array $deal, array $expected ): array|false {
		$fields = self::CRM_FIELDS;
		foreach ( $expected as $want ) {
			$key = $this->crm_key( $want );
			$matches = array_values( array_filter( $deal['Quoted_Equipment'], fn( mixed $row ): bool => is_array( $row ) && $key === $this->crm_key( $row ) ) );
			if ( 1 !== count( $matches ) || ! $this->valid_id( (string) ( $matches[0]['id'] ?? '' ) ) || array_diff( self::CRM_CORE_FIELDS, array_keys( $matches[0] ) ) ) { return false; }
			$fields = array_values( array_intersect( $fields, array_keys( $matches[0] ) ) );
		}
		return ! empty( $expected ) && ! array_diff( self::CRM_CORE_FIELDS, $fields ) ? $fields : false;
	}
	private function crm_fields( array $row, array $contract ): array|false {
		if ( ! array_is_list( $contract ) || count( $contract ) !== count( array_unique( $contract ) ) || array_diff( self::CRM_CORE_FIELDS, $contract ) || array_diff( $contract, self::CRM_FIELDS ) || array_diff( $contract, array_keys( $row ) ) ) { return false; }
		if ( ! is_string( $row['Slot'] ) || '' === $row['Slot'] || ! is_numeric( $row['System_Number'] ) || (float) $row['System_Number'] < 1 || (float) $row['System_Number'] > 10 || floor( (float) $row['System_Number'] ) !== (float) $row['System_Number'] || ! is_string( $row['Zoho_Item_ID'] ) || ! $this->valid_item_id( $row['Zoho_Item_ID'] ) ) { return false; }
		$out = array();
		foreach ( $contract as $key ) {
			$value = $row[ $key ];
			if ( null !== $value && ! is_scalar( $value ) ) { return false; }
			if ( in_array( $key, array( 'System_Number', 'Rate', 'SEER', 'AFUE', 'Tons', 'BTU_Input', 'BTU_Output' ), true ) && is_numeric( $value ) ) { $value = number_format( (float) $value, 6, '.', '' ); }
			$out[ $key ] = (string) $value;
		}
		return $out;
	}
	/** Verify all owned rows, while leaving independent subform rows outside the snapshot. */
	private function crm_snapshot( array $deal, array $record, array $expected, float $total, ?array $previous = null, bool $initial = false ): array|false {
		if ( abs( (float) $deal['Amount'] - $total ) > 0.010000001 ) { return false; }
		$contract = (array) ( $record['crm_field_contract'] ?? array() );
		$rows = array();
		foreach ( $expected as $want ) {
			$key = $this->crm_key( $want );
			$matches = array_values( array_filter( $deal['Quoted_Equipment'], fn( mixed $row ): bool => is_array( $row ) && $key === $this->crm_key( $row ) ) );
			if ( isset( $rows[ $key ] ) || 1 !== count( $matches ) || ! $this->valid_id( (string) ( $matches[0]['id'] ?? '' ) ) ) { return false; }
			if ( array_values( array_intersect( self::CRM_FIELDS, array_keys( $matches[0] ) ) ) !== $contract ) { return false; }
			$actual_fields = $this->crm_fields( $matches[0], $contract );
			$wanted_fields = $this->crm_fields( $want, $contract );
			if ( false === $actual_fields || false === $wanted_fields ) { return false; }
			foreach ( $contract as $field ) {
				// Legacy optional descriptors may be unknown. Capture that exact empty
				// baseline; never relax required identity or later drift comparisons.
				if ( $initial && ! in_array( $field, self::CRM_CORE_FIELDS, true ) && '' === $actual_fields[ $field ] ) { continue; }
				if ( $actual_fields[ $field ] !== $wanted_fields[ $field ] ) { return false; }
			}
			// Existing owned slots must keep their row identity, never silently append.
			if ( isset( $previous['rows'][ $key ] ) && (string) $matches[0]['id'] !== $previous['rows'][ $key ]['id'] ) { return false; }
			$rows[ $key ] = array( 'id' => (string) $matches[0]['id'], 'fields' => $actual_fields );
		}
		foreach ( (array) ( $previous['rows'] ?? array() ) as $key => $old ) {
			if ( isset( $rows[ $key ] ) ) { continue; }
			foreach ( $deal['Quoted_Equipment'] as $row ) { if ( is_array( $row ) && (string) ( $row['id'] ?? '' ) === $old['id'] ) { return false; } }
		}
		ksort( $rows, SORT_STRING );
		return array( 'amount' => round( $total, 2 ), 'rows' => $rows );
	}
	private function crm_baseline_matches( array $deal, array $record ): bool {
		$baseline = $record['crm_baseline'] ?? null;
		if ( ! is_array( $baseline ) ) { return false; }
		$expected = array_map( static fn( array $row ): array => $row['fields'], $baseline['rows'] );
		$current = $this->crm_snapshot( $deal, $record, $expected, (float) $baseline['amount'], $baseline );
		return false !== $current && $current === $baseline;
	}
	private function crm_update_rows( array $record, array $desired, array $deal ): array|false {
		$rows = array();
		$used = array();
		foreach ( $desired as $row ) {
			$key = $this->crm_key( $row );
			if ( false === $this->crm_fields( $row, (array) ( $record['crm_field_contract'] ?? array() ) ) ) { return false; }
			$row = array_intersect_key( $row, array_flip( $record['crm_field_contract'] ) );
			$old = $record['crm_baseline']['rows'][ $key ] ?? null;
			if ( is_array( $old ) ) { $row['id'] = $old['id']; }
			else {
				// Do not adopt or overwrite an independently added row in a new slot.
				foreach ( $deal['Quoted_Equipment'] as $current ) { if ( is_array( $current ) && $key === $this->crm_key( $current ) ) { return false; } }
			}
			$rows[] = $row;
			$used[ $key ] = true;
		}
		foreach ( $record['crm_baseline']['rows'] as $key => $old ) { if ( ! isset( $used[ $key ] ) ) { $rows[] = array( 'id' => $old['id'], '_delete' => null ); } }
		return $rows;
	}
	private function review_url( array $estimate ): string {
		foreach ( array( 'estimate_url', 'customer_url', 'public_url', 'accept_url', 'portal_url', 'share_link' ) as $field ) {
			$url = (string) ( $estimate[ $field ] ?? '' );
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) || 1 !== preg_match( '/^(?:books|securebooks)\.zoho\.(?:com|eu|in|com\.au|jp|ca|com\.cn|sa)$/D', strtolower( (string) ( $parts['host'] ?? '' ) ) ) || preg_match( '#/(?:app|admin)(?:/|$)#i', (string) ( $parts['path'] ?? '' ) ) || str_contains( (string) ( $parts['fragment'] ?? '' ), '/' ) ) { continue; }
			return esc_url_raw( $url );
		}
		return '';
	}
	private function rate_limit( string $bucket, int $limit ): bool|WP_Error {
		$key = self::PREFIX . 'rate_' . md5( $bucket );
		$entry = get_transient( $key );
		if ( ! is_array( $entry ) || time() >= (int) ( $entry['until'] ?? 0 ) ) { $entry = array( 'count' => 0, 'until' => time() + 60 ); }
		$entry['count']++;
		set_transient( $key, $entry, max( 1, $entry['until'] - time() ) );
		return $entry['count'] <= $limit ? true : $this->error( 'rate_limited', 429 );
	}
	private function money( mixed $value, bool $signed = false ): bool { return is_numeric( $value ) && ! is_bool( $value ) && is_finite( (float) $value ) && ( $signed || (float) $value >= 0 ); }
	private function zero( mixed $value ): bool { return $this->money( $value, true ) && abs( (float) $value ) < 0.000001; }
	private function error( string $code, int $status ): WP_Error {
		$messages = array( 'unavailable' => 'This equipment selection link is unavailable or expired. Please contact Temp Control.', 'changed' => 'This estimate needs review by Temp Control before its equipment can be changed.', 'stale_revision' => 'The estimate changed. Refresh the equipment options before submitting again.', 'bad_selection' => 'Choose one offered option for every system.', 'in_flight' => 'An estimate update is already being checked. Refresh its status before trying again.', 'rate_limited' => 'Too many requests. Please try again shortly.', 'provider' => 'The estimate is temporarily unavailable. Please try again later.' );
		return new WP_Error( 'tc_estimate_choices_' . $code, __( $messages[ $code ] ?? $messages['unavailable'], 'tc-estimate' ), array( 'status' => $status, 'retry_after' => 429 === $status ? 60 : 0 ) );
	}
}
