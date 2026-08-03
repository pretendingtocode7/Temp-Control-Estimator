<?php
/**
 * Capabilities and custom roles.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the `manage_tc_estimates` capability to Administrator and creates the Technician role on activation.
 *
 * Pillar 01-04: Access Control — least privilege, distinct role for field techs, capability-gated endpoints.
 */
final class Capabilities {

	private static ?Capabilities $instance = null;

	public const TECHNICIAN_ROLE = 'tc_technician';

	public const CAP = TC_ESTIMATE_CAP;

	public static function instance(): Capabilities {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Idempotent — safe to call on every activation.
	 */
	public function install_roles_and_caps(): void {
		// Add cap to Administrator (and anyone with manage_options).
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAP ) ) {
			$admin->add_cap( self::CAP );
		}

		// Create Technician role if it doesn't exist.
		if ( ! get_role( self::TECHNICIAN_ROLE ) ) {
			add_role(
				self::TECHNICIAN_ROLE,
				__( 'Technician', 'tc-estimate' ),
				array(
					'read'            => true,
					self::CAP         => true,
				)
			);
		} else {
			$tech = get_role( self::TECHNICIAN_ROLE );
			if ( $tech && ! $tech->has_cap( self::CAP ) ) {
				$tech->add_cap( self::CAP );
			}
		}
	}

	/**
	 * Checks whether the current user can use the builder.
	 */
	public function current_user_can_use(): bool {
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}

	/**
	 * Admin-only check for settings / audit-log-retry endpoints.
	 */
	public function current_user_can_admin(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( self::CAP );
	}
}
