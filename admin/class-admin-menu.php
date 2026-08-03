<?php
/**
 * Top-level admin menu: Estimate Builder → Settings | Templates | Audit Log | Equipment.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Admin;

use TempControl\Estimate\Template_CPT;

defined( 'ABSPATH' ) || exit;

final class Admin_Menu {

	private static ?Admin_Menu $instance = null;

	public const SLUG = 'tc-estimate';

	public static function instance(): Admin_Menu {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menus' ) );
	}

	public function add_menus(): void {
		// Use the plugin capability so custom operator roles can access this area
		// without requiring the broader manage_options capability.
		add_menu_page(
			__( 'Estimate Builder', 'tc-estimate' ),
			__( 'Estimate Builder', 'tc-estimate' ),
			TC_ESTIMATE_CAP,
			self::SLUG,
			array( Settings_Page::instance(), 'render' ),
			'dashicons-clipboard',
			58
		);

		// Settings (also the default landing page).
		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'tc-estimate' ),
			__( 'Settings', 'tc-estimate' ),
			TC_ESTIMATE_CAP,
			self::SLUG,
			array( Settings_Page::instance(), 'render' )
		);

		// Templates — link straight to the CPT list.
		add_submenu_page(
			self::SLUG,
			__( 'Templates', 'tc-estimate' ),
			__( 'Templates', 'tc-estimate' ),
			TC_ESTIMATE_CAP,
			'edit.php?post_type=' . Template_CPT::POST_TYPE,
			''
		);

		// Audit log.
		add_submenu_page(
			self::SLUG,
			__( 'Audit Log', 'tc-estimate' ),
			__( 'Audit Log', 'tc-estimate' ),
			TC_ESTIMATE_CAP,
			'tc-estimate-audit',
			array( Audit_Viewer::instance(), 'render' )
		);
	}
}
