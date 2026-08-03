<?php
/**
 * Main plugin orchestrator.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton orchestrator. Loads components in dependency order.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — singleton. Files are loaded here so autoload-less environments still work.
	 */
	private function __construct() {
		$this->load_files();
	}

	/**
	 * Load component files. If Composer autoload is present this is redundant but harmless.
	 */
	private function load_files(): void {
		$dir = TC_ESTIMATE_PLUGIN_DIR;

		$files = array(
			// Core infrastructure.
			'includes/class-capabilities.php',
			'includes/class-security.php',
			'includes/class-zoho-cache.php',
			'includes/class-zoho-api.php',
			'includes/class-rate-limiter.php',
			'includes/class-audit-log.php',

			// Domain.
			'includes/class-template-cpt.php',
			'includes/class-template-meta.php',
			'includes/class-equipment-catalog.php',
			'includes/class-customer-search.php',
			'includes/class-token-renderer.php',
			'includes/class-estimate-generator.php',

			// REST.
			'includes/class-rest-controller.php',
			'endpoints/class-endpoint-base.php',
			'endpoints/class-search-customers.php',
			'endpoints/class-search-equipment.php',
			'endpoints/class-get-templates.php',
			'endpoints/class-preview-estimate.php',
			'endpoints/class-deluge-payload.php',
			'endpoints/class-generate-estimate.php',
			'endpoints/class-acceptance-webhook.php',

			// Admin.
			'admin/class-admin-menu.php',
			'admin/class-settings-page.php',
			'admin/class-template-editor.php',
			'admin/class-audit-viewer.php',

			// Public.
			'public/class-shortcode.php',
			'public/class-enqueue.php',
		);

		foreach ( $files as $rel ) {
			$path = $dir . $rel;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Wire up all hooks. Called on plugins_loaded.
	 */
	public function run(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Load text domain for i18n.
		load_plugin_textdomain( 'tc-estimate', false, dirname( plugin_basename( TC_ESTIMATE_PLUGIN_FILE ) ) . '/languages' );

		// Register the CPT + meta on 'init'.
		//
		// These MUST run on 'init', not directly during plugins_loaded. WordPress's
		// $wp_rewrite global is not fully set up at plugins_loaded time on some hosts
		// (notably WP Engine), which makes register_post_type() crash with:
		//   "Call to a member function add_rewrite_tag() on null"
		// Registering on init at priority 5 keeps the CPT available well before any
		// REST or admin code needs it while staying within the documented WP lifecycle.
		add_action(
			'init',
			static function (): void {
				Template_CPT::instance()->register();
				Template_Meta::instance()->register();
			},
			5
		);

		// Audit log table — check/migrate on every load (cheap; uses version option).
		// Safe to call on plugins_loaded because it only touches $wpdb, which is already
		// initialized by this point.
		Audit_Log::instance()->maybe_install();

		// REST routes — Rest_Controller itself hooks into rest_api_init internally.
		Rest_Controller::instance()->register();

		// Admin UI.
		if ( is_admin() ) {
			Admin\Admin_Menu::instance()->register();
			Admin\Settings_Page::instance()->register();
			Admin\Template_Editor::instance()->register();
			Admin\Audit_Viewer::instance()->register();
		}

		// Public shortcode + enqueue.
		PublicSite\Shortcode::instance()->register();
		PublicSite\Enqueue::instance()->register();

		// Self-heal roles, caps, and schema on version change. Handles the case where
		// the plugin was overwritten without reactivation (common deployment flow), or
		// where the activation hook aborted before install_roles_and_caps() finished.
		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'maybe_run_migrations' ) );
		}
	}

	/**
	 * Runs on every admin_init, but only does work when the stored version differs
	 * from the current plugin version. Cheap enough to gate on a single option read.
	 *
	 * Idempotent — all the installers check-then-apply.
	 */
	public function maybe_run_migrations(): void {
		$stored = (string) get_option( 'tc_estimate_version', '0.0.0' );
		if ( version_compare( $stored, TC_ESTIMATE_VERSION, '>=' ) ) {
			return;
		}

		Capabilities::instance()->install_roles_and_caps();
		Audit_Log::instance()->maybe_install();

		update_option( 'tc_estimate_version', TC_ESTIMATE_VERSION );
	}

	/**
	 * Activation — runs once on plugin activation. Idempotent.
	 *
	 * WordPress fires the activation hook synchronously during admin page loads,
	 * so $wp_rewrite is normally already set up by the time we get here. On WP Engine
	 * that assumption is not always safe, so we guard by running the CPT registration
	 * on the next init tick if $wp_rewrite isn't ready yet. flush_rewrite_rules()
	 * has the same dependency, so it moves too.
	 */
	public function activate(): void {
		Capabilities::instance()->install_roles_and_caps();
		Audit_Log::instance()->maybe_install();

		global $wp_rewrite;
		if ( $wp_rewrite instanceof \WP_Rewrite ) {
			// Normal path — finish registration and flush rewrites immediately.
			Template_CPT::instance()->register();
			flush_rewrite_rules();
		} else {
			// Defensive path — defer to init, then flush once rewrites are primed.
			add_action(
				'init',
				static function (): void {
					Template_CPT::instance()->register();
					flush_rewrite_rules();
				},
				5
			);
		}

		// Stamp current version for future migrations.
		update_option( 'tc_estimate_version', TC_ESTIMATE_VERSION );
	}

	/**
	 * Deactivation — conservative: flush rewrites and clear transients. No data deletion.
	 */
	public function deactivate(): void {
		flush_rewrite_rules();
		Zoho_Cache::instance()->flush_all();
	}
}
