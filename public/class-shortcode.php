<?php
/**
 * [tc_estimate_builder] shortcode — renders the React app mount point.
 *
 * Phase 2: renders the root div with boot data. Enqueue is handled by
 * TempControl\Estimate\PublicSite\Enqueue, which only loads scripts when this
 * shortcode is actually present on the page.
 *
 * If the React bundle is missing on disk (e.g. fresh checkout without `npm run build`),
 * we render a build-required notice so the page fails loudly instead of silently.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\PublicSite;

use TempControl\Estimate\Capabilities;
use TempControl\Estimate\Admin\Settings_Page;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	private static ?Shortcode $instance = null;

	public const TAG = 'tc_estimate_builder';
	public const SETTINGS_TAG = 'tc_estimate_settings';

	public static function instance(): Shortcode {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
		add_shortcode( self::SETTINGS_TAG, array( $this, 'render_settings' ) );
		add_action( 'wp_ajax_tc_estimate_rest_nonce', array( $this, 'ajax_rest_nonce' ) );
	}

	public function ajax_rest_nonce(): void {
		if ( ! is_user_logged_in() || ! Capabilities::instance()->current_user_can_use() ) {
			wp_send_json_error(
				array( 'message' => __( 'Authentication required.', 'tc-estimate' ) ),
				401
			);
		}

		nocache_headers();
		wp_send_json_success(
			array(
				'restUrl' => esc_url_raw( rest_url( TC_ESTIMATE_REST_NS ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public function render_settings(): string {
		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<p style="padding:20px;background:#f6f7f7;border-left:4px solid #214c7a;"><a href="%s">%s</a></p>',
				esc_url( wp_login_url( get_permalink() ) ),
				esc_html__( 'Please log in to configure the Estimate Builder.', 'tc-estimate' )
			);
		}

		if ( ! Capabilities::instance()->current_user_can_admin() ) {
			return sprintf(
				'<p style="padding:20px;background:#fcf0f1;border-left:4px solid #d63638;">%s</p>',
				esc_html__( 'Your account does not have permission to configure the Estimate Builder.', 'tc-estimate' )
			);
		}

		ob_start();
		Settings_Page::instance()->render();
		return (string) ob_get_clean();
	}

	public function render( $atts = array() ): string {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}

		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<p style="padding:20px;background:#f6f7f7;border-left:4px solid #214c7a;"><a href="%s">%s</a></p>',
				esc_url( wp_login_url( get_permalink() ) ),
				esc_html__( 'Please log in to use the Estimate Builder.', 'tc-estimate' )
			);
		}

		if ( ! Capabilities::instance()->current_user_can_use() ) {
			return sprintf(
				'<p style="padding:20px;background:#fcf0f1;border-left:4px solid #d63638;">%s</p>',
				esc_html__( 'Your account does not have the Estimate Builder capability. Ask an administrator to assign the Technician role or grant manage_tc_estimates.', 'tc-estimate' )
			);
		}

		// Nonce + REST root for the React app to consume.
		$boot = array(
			'restUrl'  => esc_url_raw( rest_url( TC_ESTIMATE_REST_NS ) ),
			'ajaxUrl'  => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'brand'    => array(
				'primary' => '#214c7a',
				'name'    => 'Temp Control Heating & Air Conditioning',
			),
		);

		$bundle_path = TC_ESTIMATE_PLUGIN_DIR . 'app/dist/estimate-builder.js';
		$needs_build = ! file_exists( $bundle_path );

		ob_start();
		?>
		<div
			id="tc-estimate-builder-root"
			data-boot="<?php echo esc_attr( wp_json_encode( $boot ) ); ?>"
		>
			<?php if ( $needs_build ) : ?>
				<noscript>
					<div style="padding:20px;background:#fcf0f1;border-left:4px solid #d63638;">
						<?php esc_html_e( 'JavaScript is required for the Estimate Builder.', 'tc-estimate' ); ?>
					</div>
				</noscript>
				<div style="padding:20px;background:#fff8e5;border-left:4px solid #dba617;">
					<strong><?php esc_html_e( 'Estimate Builder: bundle not built.', 'tc-estimate' ); ?></strong><br>
					<code>cd app && npm install && npm run build</code>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
