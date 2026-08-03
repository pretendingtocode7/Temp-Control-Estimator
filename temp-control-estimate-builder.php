<?php
/**
 * Plugin Name:       Temp Control Estimate Builder
 * Plugin URI:        https://tempcontrolhvac.com
 * Description:       Mobile-first estimate builder for field techs. Pulls templates and equipment from Zoho, generates Books estimates + CRM Deals with one tap.
 * Version:           0.3.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Seven Degrees LLC
 * Author URI:        https://sevendegrees.co
 * License:           Proprietary
 * Text Domain:       tc-estimate
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Version constant for asset cache busting and migrations.
define( 'TC_ESTIMATE_VERSION', '0.3.0' );
define( 'TC_ESTIMATE_PLUGIN_FILE', __FILE__ );
define( 'TC_ESTIMATE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TC_ESTIMATE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// REST namespace — used in every endpoint registration.
define( 'TC_ESTIMATE_REST_NS', 'tc-estimate/v1' );

// Custom capability name — granted to Administrator and Technician roles on activation.
define( 'TC_ESTIMATE_CAP', 'manage_tc_estimates' );

// Transient cache TTLs (seconds).
define( 'TC_ESTIMATE_CACHE_TTL_CATALOG', 15 * MINUTE_IN_SECONDS );
define( 'TC_ESTIMATE_CACHE_TTL_TEMPLATES', 10 * MINUTE_IN_SECONDS );
define( 'TC_ESTIMATE_CACHE_TTL_CUSTOMER', 5 * MINUTE_IN_SECONDS );

// Composer autoload — preferred path. Real bobthecow/mustache.php gives spec-conforming
// rendering when operators run `composer install --no-dev`.
$autoload = TC_ESTIMATE_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

// Bundled Mustache fallback — registers Mustache_Engine in the global namespace ONLY
// if Composer didn't already provide it. Lets the plugin run on hosts where
// `composer install` hasn't been run (the common WP Engine deploy flow), with no
// loss of template rendering for the templates this plugin ships.
if ( ! class_exists( 'Mustache_Engine' ) ) {
	require_once TC_ESTIMATE_PLUGIN_DIR . 'includes/class-mustache-shim.php';
}

// Core class loader.
require_once TC_ESTIMATE_PLUGIN_DIR . 'includes/class-plugin.php';

/**
 * Bootstrap the plugin on plugins_loaded so roles and CPTs register at the right point.
 */
function tc_estimate_bootstrap(): void {
	\TempControl\Estimate\Plugin::instance()->run();
}
add_action( 'plugins_loaded', 'tc_estimate_bootstrap', 5 );

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=tc-estimate' ) ),
				esc_html__( 'Settings', 'tc-estimate' )
			)
		);
		return $links;
	}
);

/**
 * Activation — register capabilities, flush rewrites, seed version option.
 */
register_activation_hook( __FILE__, function (): void {
	require_once TC_ESTIMATE_PLUGIN_DIR . 'includes/class-plugin.php';
	\TempControl\Estimate\Plugin::instance()->activate();
} );

/**
 * Deactivation — flush rewrites and clear transients. Does NOT delete data.
 */
register_deactivation_hook( __FILE__, function (): void {
	require_once TC_ESTIMATE_PLUGIN_DIR . 'includes/class-plugin.php';
	\TempControl\Estimate\Plugin::instance()->deactivate();
} );
