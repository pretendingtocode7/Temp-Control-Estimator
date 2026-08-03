<?php
/**
 * Audit log viewer under Estimate Builder → Audit Log.
 *
 * Admin-only. Displays the last N generation attempts with status filter.
 * Phase 2 will add the "Retry failed" action; scaffolding is present here.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Admin;

use TempControl\Estimate\Audit_Log;
use TempControl\Estimate\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Audit_Viewer {

	private static ?Audit_Viewer $instance = null;

	public static function instance(): Audit_Viewer {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		// Nothing to hook up at load time. Render method wired via admin menu.
	}

	public function render(): void {
		if ( ! Capabilities::instance()->current_user_can_admin() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tc-estimate' ) );
		}

		$status_filter = isset( $_GET['status'] ) ? sanitize_key( (string) wp_unslash( $_GET['status'] ) ) : '';
		$rows = Audit_Log::instance()->list( array(
			'status' => $status_filter,
			'limit'  => 100,
		) );

		include TC_ESTIMATE_PLUGIN_DIR . 'admin/views/audit-log.php';
	}
}
