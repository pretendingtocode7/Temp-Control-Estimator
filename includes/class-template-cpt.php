<?php
/**
 * estimate_template custom post type.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate;

defined( 'ABSPATH' ) || exit;

final class Template_CPT {

	private static ?Template_CPT $instance = null;

	public const POST_TYPE = 'estimate_template';

	public static function instance(): Template_CPT {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		$labels = array(
			'name'               => __( 'Estimate Templates', 'tc-estimate' ),
			'singular_name'      => __( 'Estimate Template', 'tc-estimate' ),
			'menu_name'          => __( 'Templates', 'tc-estimate' ),
			'add_new_item'       => __( 'Add New Template', 'tc-estimate' ),
			'edit_item'          => __( 'Edit Template', 'tc-estimate' ),
			'new_item'           => __( 'New Template', 'tc-estimate' ),
			'view_item'          => __( 'View Template', 'tc-estimate' ),
			'search_items'       => __( 'Search Templates', 'tc-estimate' ),
			'not_found'          => __( 'No templates found.', 'tc-estimate' ),
		);

		register_post_type( self::POST_TYPE, array(
			'labels'       => $labels,
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => false, // Appears under our top-level menu instead.
			'show_in_rest' => false, // We expose via our own REST namespace.
			'supports'     => array( 'title', 'editor', 'revisions', 'author' ),
			// Gate the editor on the plugin capability so custom operator roles can
			// manage templates without requiring broad WordPress settings access.
			'capabilities' => array(
				'edit_post'          => TC_ESTIMATE_CAP,
				'read_post'          => TC_ESTIMATE_CAP,
				'delete_post'        => TC_ESTIMATE_CAP,
				'edit_posts'         => TC_ESTIMATE_CAP,
				'edit_others_posts'  => TC_ESTIMATE_CAP,
				'publish_posts'      => TC_ESTIMATE_CAP,
				'read_private_posts' => TC_ESTIMATE_CAP,
			),
			'map_meta_cap' => true,
		) );
	}
}
