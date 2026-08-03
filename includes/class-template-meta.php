<?php
/**
 * Meta boxes for estimate_template: template type, required slots, warranty defaults, active flag.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate;

defined( 'ABSPATH' ) || exit;

final class Template_Meta {

	private static ?Template_Meta $instance = null;

	public const META_TYPE              = '_template_type';
	public const META_SLOTS             = '_required_slots';
	public const META_SUPPORTS_REBATES  = '_supports_rebates';
	public const META_SUPPORTS_FINANCE  = '_supports_financing';
	public const META_DEF_PARTS_YEARS   = '_default_warranty_parts';
	public const META_DEF_LABOR_YEARS   = '_default_warranty_labor';
	public const META_ACTIVE            = '_active';
	public const META_VERSION           = '_version';

	public const TYPES = array(
		'full_replacement' => 'Full Replacement',
		'ac_only'          => 'AC Only',
		'furnace_only'     => 'Furnace Only',
		'maintenance'      => 'Maintenance',
		'service_repair'   => 'Service / Repair',
	);

	public static function instance(): Template_Meta {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . Template_CPT::POST_TYPE, array( $this, 'save_meta' ), 10, 2 );
	}

	public function add_meta_box(): void {
		add_meta_box(
			'tc_estimate_template_meta',
			__( 'Template Configuration', 'tc-estimate' ),
			array( $this, 'render_meta_box' ),
			Template_CPT::POST_TYPE,
			'normal',
			'high'
		);
	}

	public function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'tc_estimate_template_meta', 'tc_estimate_template_nonce' );

		$type     = (string) get_post_meta( $post->ID, self::META_TYPE, true );
		$slots    = (string) get_post_meta( $post->ID, self::META_SLOTS, true );
		$rebates  = (bool) get_post_meta( $post->ID, self::META_SUPPORTS_REBATES, true );
		$finance  = (bool) get_post_meta( $post->ID, self::META_SUPPORTS_FINANCE, true );
		$parts    = (int) get_post_meta( $post->ID, self::META_DEF_PARTS_YEARS, true );
		$labor    = (int) get_post_meta( $post->ID, self::META_DEF_LABOR_YEARS, true );
		$active   = get_post_meta( $post->ID, self::META_ACTIVE, true );
		$active   = '' === $active ? true : (bool) $active;
		$version  = (int) get_post_meta( $post->ID, self::META_VERSION, true );

		if ( '' === $slots ) {
			$slots = wp_json_encode( array(
				array( 'type' => 'furnace', 'min' => 1, 'max' => 3 ),
				array( 'type' => 'condenser', 'min' => 1, 'max' => 3 ),
				array( 'type' => 'coil', 'min' => 1, 'max' => 3 ),
			), JSON_PRETTY_PRINT );
		}

		include TC_ESTIMATE_PLUGIN_DIR . 'admin/views/template-meta.php';
	}

	public function save_meta( int $post_id, \WP_Post $post ): void {
		// Nonce + cap + not-autosave checks.
		if ( ! isset( $_POST['tc_estimate_template_nonce'] ) || ! wp_verify_nonce( (string) $_POST['tc_estimate_template_nonce'], 'tc_estimate_template_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( TC_ESTIMATE_CAP, $post_id ) ) {
			return;
		}

		$type = isset( $_POST['tc_template_type'] ) ? sanitize_key( (string) $_POST['tc_template_type'] ) : '';
		if ( ! array_key_exists( $type, self::TYPES ) ) {
			$type = 'full_replacement';
		}
		update_post_meta( $post_id, self::META_TYPE, $type );

		// Slots — must be valid JSON array; reject gracefully.
		$slots_raw = isset( $_POST['tc_required_slots'] ) ? (string) wp_unslash( $_POST['tc_required_slots'] ) : '[]';
		$decoded = json_decode( $slots_raw, true );
		if ( is_array( $decoded ) ) {
			$sanitized = array();
			foreach ( $decoded as $slot ) {
				if ( ! is_array( $slot ) || empty( $slot['type'] ) ) {
					continue;
				}
				$sanitized[] = array(
					'type' => sanitize_key( (string) $slot['type'] ),
					'min'  => max( 0, (int) ( $slot['min'] ?? 0 ) ),
					'max'  => max( 0, (int) ( $slot['max'] ?? 1 ) ),
				);
			}
			update_post_meta( $post_id, self::META_SLOTS, wp_json_encode( $sanitized ) );
		}

		update_post_meta( $post_id, self::META_SUPPORTS_REBATES, ! empty( $_POST['tc_supports_rebates'] ) ? 1 : 0 );
		update_post_meta( $post_id, self::META_SUPPORTS_FINANCE, ! empty( $_POST['tc_supports_financing'] ) ? 1 : 0 );
		update_post_meta( $post_id, self::META_DEF_PARTS_YEARS, max( 0, (int) ( $_POST['tc_default_warranty_parts'] ?? 0 ) ) );
		update_post_meta( $post_id, self::META_DEF_LABOR_YEARS, max( 0, (int) ( $_POST['tc_default_warranty_labor'] ?? 0 ) ) );
		update_post_meta( $post_id, self::META_ACTIVE, ! empty( $_POST['tc_active'] ) ? 1 : 0 );

		// Auto-bump version on every save so audit-log replays can pin a specific revision.
		$prev = (int) get_post_meta( $post_id, self::META_VERSION, true );
		update_post_meta( $post_id, self::META_VERSION, $prev + 1 );
	}

	/**
	 * Build the serialized metadata view for a given template post. Used by endpoints.
	 */
	public function hydrate( int $post_id ): array {
		$slots_raw = (string) get_post_meta( $post_id, self::META_SLOTS, true );
		$slots = json_decode( $slots_raw, true );
		if ( ! is_array( $slots ) ) {
			$slots = array();
		}
		$type = (string) get_post_meta( $post_id, self::META_TYPE, true );
		if ( '' === $type ) {
			$type = (string) get_post_meta( $post_id, '_tc_template_type', true );
		}
		$parts = get_post_meta( $post_id, self::META_DEF_PARTS_YEARS, true );
		if ( '' === $parts ) {
			$parts = get_post_meta( $post_id, '_tc_default_warranty_parts', true );
		}
		$labor = get_post_meta( $post_id, self::META_DEF_LABOR_YEARS, true );
		if ( '' === $labor ) {
			$labor = get_post_meta( $post_id, '_tc_default_warranty_labor', true );
		}
		$active = get_post_meta( $post_id, self::META_ACTIVE, true );
		if ( '' === $active ) {
			$active = 'publish' === get_post_status( $post_id ) ? 1 : 0;
		}
		$version = get_post_meta( $post_id, self::META_VERSION, true );
		if ( '' === $version ) {
			$version = get_post_meta( $post_id, '_tc_template_version', true );
		}
		return array(
			'id'                     => $post_id,
			'name'                   => get_the_title( $post_id ),
			'template_type'          => $type,
			'required_slots'         => $slots,
			'supports_rebates'       => (bool) get_post_meta( $post_id, self::META_SUPPORTS_REBATES, true ),
			'supports_financing'     => (bool) get_post_meta( $post_id, self::META_SUPPORTS_FINANCE, true ),
			'default_warranty_parts' => (int) $parts,
			'default_warranty_labor' => (int) $labor,
			'active'                 => (bool) $active,
			'version'                => (int) $version,
		);
	}
}
