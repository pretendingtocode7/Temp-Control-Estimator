<?php
/**
 * Runs when the plugin is DELETED (not just deactivated).
 *
 * Removes options, custom role, transients, and audit table. Template posts are kept intentionally —
 * operators must explicitly opt into losing that content by removing them through the CPT UI first.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Options.
$options = array(
	'tc_estimate_version',
	'tc_estimate_zoho_client_id',
	'tc_estimate_zoho_client_secret',
	'tc_estimate_zoho_refresh_token_enc',
	'tc_estimate_zoho_org_id',
	'tc_estimate_zoho_dc',
	'tc_estimate_zoho_circuit',
	'tc_estimate_webhook_secret',
	'tc_estimate_cache_index',
	'tc_estimate_audit_db_version',
);
foreach ( $options as $opt ) {
	delete_option( $opt );
}

// Transients (cache + rate limits + access token).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tc_zoho_%' OR option_name LIKE '_transient_timeout_tc_zoho_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tc_rl_%' OR option_name LIKE '_transient_timeout_tc_rl_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tc_estimate_%' OR option_name LIKE '_transient_timeout_tc_estimate_%'" );

// Audit table.
$audit_table = $wpdb->prefix . 'tc_estimate_audit';
$wpdb->query( "DROP TABLE IF EXISTS {$audit_table}" );

// Technician role — only if empty of users.
$role = 'tc_technician';
$users = get_users( array( 'role' => $role, 'number' => 1 ) );
if ( empty( $users ) ) {
	remove_role( $role );
}

// Strip the capability from Administrator.
$admin = get_role( 'administrator' );
if ( $admin && $admin->has_cap( 'manage_tc_estimates' ) ) {
	$admin->remove_cap( 'manage_tc_estimates' );
}
