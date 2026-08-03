<?php
/**
 * Zoho OAuth configuration page.
 *
 * Refresh token entry: stored encrypted via Security::set_zoho_refresh_token.
 * Input fields use type="password" and are never re-rendered after save.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Admin;

use TempControl\Estimate\Security;
use TempControl\Estimate\Zoho_API;
use TempControl\Estimate\Zoho_Cache;
use TempControl\Estimate\Capabilities;
use TempControl\Estimate\Equipment_Catalog;

defined( 'ABSPATH' ) || exit;

final class Settings_Page {

	private static ?Settings_Page $instance = null;

	private const OPT_CLIENT_ID     = 'tc_estimate_zoho_client_id';
	private const OPT_CLIENT_SECRET = 'tc_estimate_zoho_client_secret';
	private const OPT_ORG_ID        = 'tc_estimate_zoho_org_id';
	private const OPT_DC            = 'tc_estimate_zoho_dc';

	public static function instance(): Settings_Page {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'admin_post_tc_estimate_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_post_tc_estimate_refresh_catalog', array( $this, 'handle_refresh_catalog' ) );
		add_action( 'admin_post_tc_estimate_test_zoho', array( $this, 'handle_test_zoho' ) );
		add_action( 'admin_post_tc_estimate_test_books_items', array( $this, 'handle_test_books_items' ) );
		add_action( 'admin_post_tc_estimate_reinstall_caps', array( $this, 'handle_reinstall_caps' ) );
		// Inline template management — bypass the CPT edit screen.
		add_action( 'admin_post_tc_estimate_save_template', array( $this, 'handle_save_template' ) );
		add_action( 'admin_post_tc_estimate_delete_template', array( $this, 'handle_delete_template' ) );
	}

	/**
	 * Inline template save (create or update). Gated on the plugin admin capability.
	 * Bypasses the estimate_template CPT capability map, which
	 * has been a source of host-cache headaches on WP Engine.
	 */
	public function handle_save_template(): void {
		if ( ! Capabilities::instance()->current_user_can_admin() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tc-estimate' ) );
		}
		check_admin_referer( 'tc_estimate_save_template' );

		$template_id    = isset( $_POST['template_id'] ) ? (int) $_POST['template_id'] : 0;
		$title          = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '';
		$template_type  = isset( $_POST['template_type'] ) ? sanitize_key( (string) $_POST['template_type'] ) : 'full_replacement';
		$warranty_parts = isset( $_POST['warranty_parts'] ) ? max( 0, min( 25, (int) $_POST['warranty_parts'] ) ) : 10;
		$warranty_labor = isset( $_POST['warranty_labor'] ) ? max( 0, min( 25, (int) $_POST['warranty_labor'] ) ) : 10;
		$status         = isset( $_POST['status'] ) && 'draft' === $_POST['status'] ? 'draft' : 'publish';
		// Body intentionally NOT passed through wp_kses — Mustache templates use HTML
		// and are operator-authored content. Settings is gated on the plugin admin
		// capability;
		// the user has unfiltered_html-equivalent trust here.
		$body           = isset( $_POST['body'] ) ? wp_unslash( (string) $_POST['body'] ) : '';

		if ( '' === $title ) {
			$this->flash( 'error', __( 'Title is required.', 'tc-estimate' ) );
			wp_safe_redirect( $this->settings_redirect_url() );
			exit;
		}

		$valid_types = array( 'full_replacement', 'ac_only', 'furnace_only', 'maintenance', 'service_repair' );
		if ( ! in_array( $template_type, $valid_types, true ) ) {
			$template_type = 'full_replacement';
		}

		$post_data = array(
			'post_title'   => $title,
			'post_content' => $body,
			'post_status'  => $status,
			'post_type'    => \TempControl\Estimate\Template_CPT::POST_TYPE,
		);

		if ( $template_id > 0 ) {
			$existing = get_post( $template_id );
			if ( ! $existing || \TempControl\Estimate\Template_CPT::POST_TYPE !== $existing->post_type ) {
				$this->flash( 'error', __( 'Template not found.', 'tc-estimate' ) );
				wp_safe_redirect( $this->settings_redirect_url() );
				exit;
			}
			$post_data['ID'] = $template_id;
			$result          = wp_update_post( $post_data, true );
		} else {
			$result = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $result ) || ! $result ) {
			$msg = is_wp_error( $result ) ? $result->get_error_message() : __( 'Could not save template.', 'tc-estimate' );
			$this->flash( 'error', $msg );
			wp_safe_redirect( $this->settings_redirect_url() );
			exit;
		}

		$post_id = (int) $result;

		// Bump version on update so the audit log can pin which version was used.
		$current_meta    = \TempControl\Estimate\Template_Meta::instance()->hydrate( $post_id );
		$current_version = (int) ( $current_meta['version'] ?? 0 );
		$next_version    = $template_id > 0 ? max( 1, $current_version + 1 ) : 1;

		update_post_meta( $post_id, \TempControl\Estimate\Template_Meta::META_TYPE, $template_type );
		update_post_meta( $post_id, \TempControl\Estimate\Template_Meta::META_DEF_PARTS_YEARS, $warranty_parts );
		update_post_meta( $post_id, \TempControl\Estimate\Template_Meta::META_DEF_LABOR_YEARS, $warranty_labor );
		update_post_meta( $post_id, \TempControl\Estimate\Template_Meta::META_ACTIVE, 'publish' === $status ? 1 : 0 );
		update_post_meta( $post_id, \TempControl\Estimate\Template_Meta::META_VERSION, $next_version );

		$this->flash( 'success', $template_id > 0
			? __( 'Template updated.', 'tc-estimate' )
			: __( 'Template created.', 'tc-estimate' )
		);

		wp_safe_redirect( $this->settings_redirect_url() );
		exit;
	}

	/**
	 * Delete a template. Gated on the plugin admin capability; soft-delete via wp_trash_post for
	 * undo from the WP trash if the operator regrets it.
	 */
	public function handle_delete_template(): void {
		if ( ! Capabilities::instance()->current_user_can_admin() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tc-estimate' ) );
		}
		$template_id = isset( $_GET['template_id'] ) ? (int) $_GET['template_id'] : 0;
		check_admin_referer( 'tc_estimate_delete_template_' . $template_id );

		if ( $template_id > 0 ) {
			$post = get_post( $template_id );
			if ( $post && \TempControl\Estimate\Template_CPT::POST_TYPE === $post->post_type ) {
				wp_trash_post( $template_id );
				$this->flash( 'success', __( 'Template moved to trash.', 'tc-estimate' ) );
			}
		}

		wp_safe_redirect( $this->settings_redirect_url() );
		exit;
	}

	/**
	 * Helper: write a transient flash message for the next page render.
	 */
	private function flash( string $type, string $message ): void {
		set_transient(
			'tc_estimate_flash_' . get_current_user_id(),
			array( 'type' => $type, 'message' => $message ),
			30
		);
	}

	private function settings_redirect_url(): string {
		$referer = wp_get_referer();
		if ( $referer && false === strpos( $referer, 'admin-post.php' ) ) {
			return $referer;
		}

		return admin_url( 'admin.php?page=' . Admin_Menu::SLUG );
	}

	/**
	 * Recovery action: re-runs Capabilities::install_roles_and_caps(), grants the
	 * plugin's custom cap directly to the user who triggered this action (so per-user
	 * role drift doesn't block them), and stamps the plugin version.
	 *
	 * Only users with plugin admin access can reach this in the first place.
	 */
	public function handle_reinstall_caps(): void {
		if ( ! Capabilities::instance()->current_user_can_admin() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tc-estimate' ) );
		}
		check_admin_referer( 'tc_estimate_reinstall_caps' );

		\TempControl\Estimate\Capabilities::instance()->install_roles_and_caps();

		// Direct per-user grant — protects against the case where the user's role
		// doesn't match the Administrator role object (custom roles, multisite
		// super admins, stale object cache, etc.).
		$user = wp_get_current_user();
		if ( $user && $user->ID ) {
			$user->add_cap( TC_ESTIMATE_CAP );
		}

		update_option( 'tc_estimate_version', TC_ESTIMATE_VERSION );

		// Clear WP Engine's object cache so the new cap is visible on the next request.
		wp_cache_flush();

		set_transient(
			'tc_estimate_flash_' . get_current_user_id(),
			array(
				'type'    => 'success',
				'message' => __( 'Roles and capabilities reinstalled. You now have full access to the Estimate Builder.', 'tc-estimate' ),
			),
			30
		);

		wp_safe_redirect( $this->settings_redirect_url() );
		exit;
	}

	public function render(): void {
		if ( ! Capabilities::instance()->current_user_can_admin() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tc-estimate' ) );
		}

		if ( ! headers_sent() ) {
			nocache_headers();
		}

		$client_id     = (string) get_option( self::OPT_CLIENT_ID, '' );
		$has_secret    = '' !== (string) get_option( self::OPT_CLIENT_SECRET, '' );
		$has_refresh   = '' !== Security::instance()->get_zoho_refresh_token();
		$org_id        = (string) get_option( self::OPT_ORG_ID, '' );
		$dc            = (string) get_option( self::OPT_DC, 'com' );
		$webhook_url   = rest_url( TC_ESTIMATE_REST_NS . '/webhook/accepted' );
		$webhook_secret = Security::instance()->get_webhook_secret();

		$flash = get_transient( 'tc_estimate_flash_' . get_current_user_id() );
		if ( $flash ) {
			delete_transient( 'tc_estimate_flash_' . get_current_user_id() );
		}

		include TC_ESTIMATE_PLUGIN_DIR . 'admin/views/settings.php';
	}

	public function handle_save(): void {
		if ( ! Capabilities::instance()->current_user_can_admin() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tc-estimate' ) );
		}
		check_admin_referer( 'tc_estimate_save_settings' );

		$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['client_id'] ) ) : '';
		$client_secret = isset( $_POST['client_secret'] ) ? (string) wp_unslash( $_POST['client_secret'] ) : '';
		$refresh_token = isset( $_POST['refresh_token'] ) ? (string) wp_unslash( $_POST['refresh_token'] ) : '';
		$org_id        = isset( $_POST['org_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['org_id'] ) ) : '';
		$dc            = isset( $_POST['dc'] ) ? sanitize_key( (string) wp_unslash( $_POST['dc'] ) ) : 'com';
		$allowed_dc    = array( 'com', 'eu', 'in', 'com.au', 'com.cn', 'jp' );
		if ( ! in_array( $dc, $allowed_dc, true ) ) {
			$dc = 'com';
		}
		update_option( self::OPT_CLIENT_ID, $client_id, false );
		if ( '' !== $client_secret ) {
			update_option( self::OPT_CLIENT_SECRET, $client_secret, false );
		}
		if ( '' !== $refresh_token ) {
			Security::instance()->set_zoho_refresh_token( $refresh_token );
			Zoho_API::instance()->invalidate_access_token();
		}
		update_option( self::OPT_ORG_ID, $org_id, false );
		update_option( self::OPT_DC, $dc, false );
		Zoho_Cache::instance()->flush_all();

		$saved_client_id = (string) get_option( self::OPT_CLIENT_ID, '' );
		$saved_secret    = '' !== (string) get_option( self::OPT_CLIENT_SECRET, '' );
		$saved_refresh   = '' !== Security::instance()->get_zoho_refresh_token();
		$saved_org_id    = (string) get_option( self::OPT_ORG_ID, '' );

		$message = sprintf(
			/* translators: 1: client ID status, 2: client secret status, 3: refresh token status, 4: organization ID status. */
			__( 'Settings saved. Client ID: %1$s. Client Secret: %2$s. Refresh Token: %3$s. Org ID: %4$s.', 'tc-estimate' ),
			'' !== $saved_client_id ? __( 'saved', 'tc-estimate' ) : __( 'missing', 'tc-estimate' ),
			$saved_secret ? __( 'saved', 'tc-estimate' ) : __( 'missing', 'tc-estimate' ),
			$saved_refresh ? __( 'saved', 'tc-estimate' ) : __( 'missing', 'tc-estimate' ),
			'' !== $saved_org_id ? __( 'saved', 'tc-estimate' ) : __( 'missing', 'tc-estimate' )
		);

		set_transient( 'tc_estimate_flash_' . get_current_user_id(), array( 'type' => 'success', 'message' => $message ), 30 );

		wp_safe_redirect( $this->settings_redirect_url() );
		exit;
	}

	public function handle_refresh_catalog(): void {
		if ( ! Capabilities::instance()->current_user_can_admin() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tc-estimate' ) );
		}
		check_admin_referer( 'tc_estimate_refresh_catalog' );

		Zoho_Cache::instance()->flush_all();

		set_transient( 'tc_estimate_flash_' . get_current_user_id(), array( 'type' => 'success', 'message' => __( 'Catalog cache cleared.', 'tc-estimate' ) ), 30 );
		wp_safe_redirect( $this->settings_redirect_url() );
		exit;
	}

	public function handle_test_zoho(): void {
		if ( ! Capabilities::instance()->current_user_can_admin() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tc-estimate' ) );
		}
		check_admin_referer( 'tc_estimate_test_zoho' );

		$token = Zoho_API::instance()->get_access_token();
		if ( is_wp_error( $token ) ) {
			set_transient( 'tc_estimate_flash_' . get_current_user_id(), array(
				'type' => 'error',
				'message' => sprintf( __( 'Zoho connection failed: %s', 'tc-estimate' ), $token->get_error_message() ),
			), 30 );
		} else {
			set_transient( 'tc_estimate_flash_' . get_current_user_id(), array(
				'type' => 'success',
				'message' => __( 'Zoho connection OK — access token acquired.', 'tc-estimate' ),
			), 30 );
		}
		wp_safe_redirect( $this->settings_redirect_url() );
		exit;
	}

	public function handle_test_books_items(): void {
		if ( ! Capabilities::instance()->current_user_can_admin() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tc-estimate' ) );
		}
		check_admin_referer( 'tc_estimate_test_books_items' );

		Zoho_Cache::instance()->flush_all();
		$result = Equipment_Catalog::instance()->search( array( 'limit' => 100 ) );

		if ( is_wp_error( $result ) ) {
			$this->flash(
				'error',
				sprintf( __( 'Zoho Books item test failed: %s', 'tc-estimate' ), $result->get_error_message() )
			);
		} else {
			$count = count( $result );
			if ( 0 === $count ) {
				$this->flash( 'error', __( 'Zoho Books Items are reachable, but no active Items have cf_for_estimate checked.', 'tc-estimate' ) );
			} else {
				$this->flash(
					'success',
					sprintf(
						__( 'Zoho Books Items are reachable. Eligible cf_for_estimate items returned (up to 100): %d.', 'tc-estimate' ),
						$count
					)
				);
			}
		}

		wp_safe_redirect( $this->settings_redirect_url() );
		exit;
	}
}
