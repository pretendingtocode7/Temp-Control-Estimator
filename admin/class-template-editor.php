<?php
/**
 * Enhancements to the WP editor for the estimate_template CPT.
 *
 * Light-touch for Phase 1 — just ensures the editor screen has a clear page title and
 * removes irrelevant screen noise. Phase 2 can add live-preview buttons here.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate\Admin;

use TempControl\Estimate\Template_CPT;

defined( 'ABSPATH' ) || exit;

final class Template_Editor {

	private static ?Template_Editor $instance = null;

	public static function instance(): Template_Editor {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );
		add_action( 'admin_head-post.php', array( $this, 'editor_help' ) );
		add_action( 'admin_head-post-new.php', array( $this, 'editor_help' ) );
	}

	public function title_placeholder( string $placeholder, \WP_Post $post ): string {
		if ( Template_CPT::POST_TYPE === $post->post_type ) {
			return __( 'Template name — e.g., "Full Replacement — Coleman High Efficiency"', 'tc-estimate' );
		}
		return $placeholder;
	}

	public function editor_help(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Template_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}
		?>
		<style>
			#postdivrich { border-left: 4px solid #214c7a; }
			#titlediv input { font-weight: 600; }
		</style>
		<?php
	}
}
