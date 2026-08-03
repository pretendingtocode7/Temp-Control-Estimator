<?php
/**
 * Audit log for estimate generation attempts and Zoho-side errors.
 *
 * Custom DB table because we need queryable structured data (status + date filters + retries).
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate;

defined( 'ABSPATH' ) || exit;

final class Audit_Log {

	private static ?Audit_Log $instance = null;

	private const TABLE       = 'tc_estimate_audit';
	private const DB_VERSION  = '1.0.0';
	private const VERSION_OPT = 'tc_estimate_audit_db_version';

	public static function instance(): Audit_Log {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create or upgrade the table. Idempotent — uses dbDelta.
	 */
	public function maybe_install(): void {
		$current = get_option( self::VERSION_OPT, '' );
		if ( $current === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = $this->table();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			idempotency_key VARCHAR(64) NOT NULL DEFAULT '',
			action VARCHAR(32) NOT NULL,
			status VARCHAR(16) NOT NULL,
			template_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			template_version INT UNSIGNED NOT NULL DEFAULT 0,
			zoho_account_id VARCHAR(32) NOT NULL DEFAULT '',
			zoho_estimate_id VARCHAR(32) NOT NULL DEFAULT '',
			zoho_deal_id VARCHAR(32) NOT NULL DEFAULT '',
			payload LONGTEXT NOT NULL,
			error_message TEXT NOT NULL,
			duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status),
			KEY user_id (user_id),
			KEY idempotency_key (idempotency_key)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::VERSION_OPT, self::DB_VERSION, false );
	}

	/**
	 * Insert a log row. Returns insert_id.
	 *
	 * Payload is passed as an array and JSON-encoded — never insert raw user strings directly.
	 */
	public function record( array $row ): int {
		global $wpdb;
		$defaults = array(
			'created_at'       => current_time( 'mysql', true ),
			'user_id'          => get_current_user_id(),
			'idempotency_key'  => '',
			'action'           => 'generate',
			'status'           => 'pending',
			'template_id'      => 0,
			'template_version' => 0,
			'zoho_account_id'  => '',
			'zoho_estimate_id' => '',
			'zoho_deal_id'     => '',
			'payload'          => array(),
			'error_message'    => '',
			'duration_ms'      => 0,
		);
		$row = array_merge( $defaults, $row );

		// JSON-encode payload and trim error to keep rows bounded.
		$row['payload']       = wp_json_encode( $row['payload'] );
		$row['error_message'] = mb_substr( (string) $row['error_message'], 0, 2000 );

		$wpdb->insert(
			$this->table(),
			$row,
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing log row (e.g., from pending → success).
	 */
	public function update( int $id, array $fields ): void {
		global $wpdb;
		$format = array();
		$map = array(
			'status' => '%s', 'zoho_estimate_id' => '%s', 'zoho_deal_id' => '%s',
			'error_message' => '%s', 'duration_ms' => '%d',
		);
		foreach ( $fields as $k => $v ) {
			$format[] = $map[ $k ] ?? '%s';
			if ( 'error_message' === $k ) {
				$fields[ $k ] = mb_substr( (string) $v, 0, 2000 );
			}
		}
		$wpdb->update( $this->table(), $fields, array( 'id' => $id ), $format, array( '%d' ) );
	}

	/**
	 * Look up an existing row by idempotency key. Returns the full row or null.
	 */
	public function find_by_idempotency_key( string $key, int $user_id ): ?array {
		if ( '' === $key ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE idempotency_key = %s AND user_id = %d ORDER BY id DESC LIMIT 1",
				$key,
				$user_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * List recent entries, optionally filtered by status and date.
	 *
	 * @param array{status?:string, since?:string, limit?:int} $args
	 */
	public function list( array $args = array() ): array {
		global $wpdb;
		$limit = isset( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 100;

		$where   = array( '1=1' );
		$params  = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( ! empty( $args['since'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = (string) $args['since'];
		}

		$sql = "SELECT * FROM {$this->table()} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$params[] = $limit;
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}
