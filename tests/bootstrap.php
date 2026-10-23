<?php
/**
 * PHPUnit bootstrap.
 *
 * Supports two modes:
 *   1. Standalone unit tests — no WordPress. Runs tests that only need plugin code (Token_Renderer with
 *      Mustache available through composer).
 *   2. WP-PHPUnit integration mode — when WP_TESTS_DIR is set, loads the WordPress test suite so
 *      tests can use WP API (transients, posts, users).
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

// Composer autoload — always needed.
$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

// Mode detection.
$tests_dir = getenv( 'WP_TESTS_DIR' );
if ( $tests_dir && file_exists( $tests_dir . '/includes/functions.php' ) ) {
	// ---- WP-PHPUnit integration mode ----
	require_once $tests_dir . '/includes/functions.php';

	tests_add_filter( 'muplugins_loaded', function (): void {
		require dirname( __DIR__ ) . '/temp-control-estimate-builder.php';
	} );

	require $tests_dir . '/includes/bootstrap.php';
} else {
	// ---- Standalone unit mode ----
	// Minimal WP function shims so plugin files that use them at class-load time don't fatal.
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'TC_ESTIMATE_VERSION' ) ) {
		define( 'TC_ESTIMATE_VERSION', '0.4.8-test' );
	}
	if ( ! defined( 'TC_ESTIMATE_PLUGIN_DIR' ) ) {
		define( 'TC_ESTIMATE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	}
	if ( ! defined( 'TC_ESTIMATE_PLUGIN_URL' ) ) {
		define( 'TC_ESTIMATE_PLUGIN_URL', 'http://example.test/wp-content/plugins/temp-control-estimate-builder/' );
	}
	if ( ! defined( 'TC_ESTIMATE_REST_NS' ) ) {
		define( 'TC_ESTIMATE_REST_NS', 'tc-estimate/v1' );
	}
	if ( ! defined( 'TC_ESTIMATE_CAP' ) ) {
		define( 'TC_ESTIMATE_CAP', 'manage_tc_estimates' );
	}
	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
		define( 'MINUTE_IN_SECONDS', 60 );
	}
	if ( ! defined( 'TC_ESTIMATE_CACHE_TTL_CATALOG' ) ) {
		define( 'TC_ESTIMATE_CACHE_TTL_CATALOG', 900 );
	}
	if ( ! defined( 'TC_ESTIMATE_CACHE_TTL_TEMPLATES' ) ) {
		define( 'TC_ESTIMATE_CACHE_TTL_TEMPLATES', 600 );
	}
	if ( ! defined( 'TC_ESTIMATE_CACHE_TTL_CUSTOMER' ) ) {
		define( 'TC_ESTIMATE_CACHE_TTL_CUSTOMER', 300 );
	}
	if ( ! defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) ) {
		define( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES', 24 );
	}
	if ( ! defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' ) ) {
		define( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES', 32 );
	}

	require_once __DIR__ . '/standalone-shims.php';
	if ( ! class_exists( 'Mustache_Engine' ) ) {
		require_once dirname( __DIR__ ) . '/includes/class-mustache-shim.php';
	}

	// Each test includes only the plugin classes it needs. Do not load the WordPress
	// plugin bootstrap or configure real Zoho/email services in standalone mode.
}
