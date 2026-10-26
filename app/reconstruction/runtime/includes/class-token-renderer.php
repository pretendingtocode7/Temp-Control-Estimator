<?php
/**
 * Template token rendering (Option 3 in the plan — loop blocks).
 *
 * Uses Mustache.php which:
 *   - HTML-escapes variables by default (mitigates template-author XSS).
 *   - Supports {{#each list}}, {{#if flag}}, {{nested.field}} natively via sections.
 *
 * Build_payload_view() transforms the /preview and /generate request shapes into
 * the nested array Mustache expects. Keeps templating concerns out of endpoints.
 *
 * @package TempControlEstimateBuilder
 */

declare( strict_types=1 );

namespace TempControl\Estimate;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Token_Renderer {

	private static ?Token_Renderer $instance = null;

	private ?\Mustache_Engine $engine = null;

	public static function instance(): Token_Renderer {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Render a template body against a payload, returning rendered HTML.
	 *
	 * @return string|WP_Error
	 */
	public function render( string $template_body, array $view ): string|WP_Error {
		$engine = $this->get_engine();
		if ( null === $engine ) {
			return new WP_Error( 'tc_estimate_no_mustache', __( 'Mustache.php not installed. Run composer install.', 'tc-estimate' ), array( 'status' => 500 ) );
		}
		try {
			// Customer documents use a separate copy. Catalog sales rates/costs remain
			// available to the authenticated editor and Books transaction builder.
			$view = $this->build_document_view( $view );
			// The bundled fallback accepts compact identifier tokens. Normalize only
			// their whitespace on this render copy; saved template posts stay intact.
			$template_body = (string) preg_replace( '/\{\{(\{?)\s*([#^\/]?)\s*([a-zA-Z0-9_.]+)\s*(\}?)\}\}/', '{{$1$2$3$4}}', $template_body );
			$template_body = $this->prepare_document_template( $template_body );
			$rendered = $engine->render( $template_body, $view );
			return $this->append_compatibility_sections( $rendered, $template_body, $view );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'tc_estimate_render_failed',
				sprintf( __( 'Template render failed: %s', 'tc-estimate' ), $e->getMessage() ),
				array( 'status' => 500 )
			);
		}
	}

	/** Keep structured equipment prices and internal costs out of every template alias. */
	private function build_document_view( array $view ): array {
		$strip_financial_fields = static function ( array $value ) use ( &$strip_financial_fields ): array {
			foreach ( $value as $key => $field ) {
				if ( is_string( $key ) && preg_match( '/(?:^|_)(?:rate|cost|price|markup)(?:_|$)/i', $key ) ) {
					unset( $value[ $key ] );
				} elseif ( is_array( $field ) ) {
					$value[ $key ] = $strip_financial_fields( $field );
				}
			}
			if ( 'package' === ( $value['slot'] ?? null ) && '' !== trim( (string) ( $value['short_description'] ?? '' ) ) &&
				in_array( trim( (string) $value['short_description'] ), array( trim( (string) ( $value['name'] ?? '' ) ), trim( (string) ( $value['display_name'] ?? '' ) ) ), true ) ) {
				$value['short_description'] = '';
			}
			return $value;
		};
		$view['systems'] = $strip_financial_fields( (array) ( $view['systems'] ?? array() ) );
		// A proposal summarizes the project sale and its deposit, never the tool's
		// internal equipment subtotal. Rebates retain their existing separate view.
		$view['pricing'] = array_intersect_key( (array) ( $view['pricing'] ?? array() ), array_flip( array(
			'total', 'total_formatted', 'deposit_percent', 'deposit_amount', 'deposit_amount_formatted',
		) ) );
		return $view;
	}

	/** Adapt the verified legacy token layout on a copy, without editing stored posts. */
	private function prepare_document_template( string $template_body ): string {
		// This exact sales-subtotal row occurs in both inspected live templates and
		// the seed layout. The customer summary keeps total and deposit only.
		$subtotal_row = '<tr><td style="padding:6px 0;">Subtotal</td><td style="text-align:right;">{{pricing.subtotal_formatted}}</td></tr>';
		$template_body = str_replace( $subtotal_row, '', $template_body );
		// Avoid leaving the seed template's price-only permit separator behind.
		$template_body = str_replace( '{{#is_permit}} — {{rate_formatted}}{{/is_permit}}', '', $template_body );
		// The verified legacy condenser row closes a parenthesis even when SEER is
		// absent. Keep each optional specification self-contained on this copy.
		$legacy_specs = '{{#seer}} ({{seer}} SEER{{/seer}}{{#tons}}, {{tons}} ton{{/tons}}{{#refrigerant}}, {{refrigerant}}{{/refrigerant}})';
		$balanced_specs = '{{#seer}} ({{seer}} SEER){{/seer}}{{#tons}} — {{tons}} ton{{/tons}}{{#refrigerant}} — {{refrigerant}}{{/refrigerant}}';
		$template_body = (string) preg_replace_callback( '/\{\{#condenser\}\}(.*?)\{\{\/condenser\}\}/s', static fn ( array $match ): string => str_replace( $legacy_specs, $balanced_specs, $match[0] ), $template_body );
		if ( $this->template_has_package_support( $template_body ) ) {
			return $template_body;
		}
		// The inspected legacy equipment list has #has_other/#other inside each
		// #systems block. Insert at that token anchor, not by guessing HTML headings.
		$package_row = '{{#package}}<li><strong>Package</strong> — {{display_name}}{{#short_description}} — {{short_description}}{{/short_description}}</li>{{/package}}' . "\n";
		return (string) preg_replace_callback( '/\{\{#systems\}\}(.*?)\{\{\/systems\}\}/s', static function ( array $match ) use ( $package_row ): string {
			if ( 1 !== substr_count( $match[1], '{{#has_other}}' ) || ! str_contains( $match[1], '{{#other}}' ) ) {
				return $match[0];
			}
			return '{{#systems}}' . str_replace( '{{#has_other}}', $package_row . '{{#has_other}}', $match[1] ) . '{{/systems}}';
		}, $template_body );
	}

	/**
	 * Keep older saved templates useful when new proposal options are introduced.
	 * Existing operator-authored template HTML is never overwritten; only selected
	 * notes, package or permit details that the template cannot render are appended.
	 */
	private function append_compatibility_sections( string $rendered, string $template_body, array $view ): string {
		$sections = array();

		// Package is a known slot, so legacy #other loops no longer include it.
		// Only an explicit package reference or generic items loop renders it.
		if ( ! $this->template_has_package_support( $template_body ) ) {
			$package_items = array();
			foreach ( (array) ( $view['systems'] ?? array() ) as $system ) {
				if ( ! is_array( $system ) || empty( $system['package'] ) || ! is_array( $system['package'] ) ) {
					continue;
				}
				$package     = $system['package'];
				$name        = trim( (string) ( $package['display_name'] ?? $package['name'] ?? 'Package' ) );
				$description = trim( (string) ( $package['short_description'] ?? '' ) );
				$label       = trim( (string) ( $system['system_label'] ?? '' ) );
				$detail      = '' !== $label ? '<strong>' . esc_html( $label ) . ':</strong> ' : '';
				$detail     .= esc_html( $name );
				if ( '' !== $description && $description !== $name && $description !== trim( (string) ( $package['name'] ?? '' ) ) ) {
					$detail .= ' — ' . esc_html( $description );
				}
				$package_items[] = '<li>' . $detail . '</li>';
			}
			if ( ! empty( $package_items ) ) {
				$sections[] = '<h3>Package</h3><ul>' . implode( '', $package_items ) . '</ul>';
			}
		}

		if ( ! str_contains( $template_body, 'proposal_notes' ) && ! empty( $view['proposal_notes'] ) && is_array( $view['proposal_notes'] ) ) {
			$items = array();
			foreach ( $view['proposal_notes'] as $note ) {
				$text = is_array( $note ) ? trim( (string) ( $note['text'] ?? '' ) ) : '';
				if ( '' !== $text ) {
					$items[] = '<li>' . esc_html( $text ) . '</li>';
				}
			}
			if ( ! empty( $items ) ) {
				$sections[] = '<h3>Proposal Notes</h3><ul>' . implode( '', $items ) . '</ul>';
			}
		}

		if ( ! str_contains( $template_body, '{{#permit}}' ) && ! str_contains( $template_body, '{{#items}}' ) ) {
			$permit_items = array();
			foreach ( (array) ( $view['systems'] ?? array() ) as $system ) {
				if ( ! is_array( $system ) || empty( $system['permit'] ) || ! is_array( $system['permit'] ) ) {
					continue;
				}
				$permit      = $system['permit'];
				$name        = trim( (string) ( $permit['display_name'] ?? $permit['name'] ?? 'Permit' ) );
				$description = trim( (string) ( $permit['short_description'] ?? '' ) );
				$detail      = esc_html( $name );
				if ( '' !== $description ) {
					$detail .= ' — ' . esc_html( $description );
				}
				$permit_items[] = '<li>' . $detail . '</li>';
			}
			if ( ! empty( $permit_items ) ) {
				$sections[] = '<h3>Permit</h3><ul>' . implode( '', $permit_items ) . '</ul>';
			}
		}

		return empty( $sections ) ? $rendered : $rendered . "\n" . implode( "\n", $sections );
	}

	/** Recognize positive equipment tokens, not prose, comments or inverted blocks. */
	private function template_has_package_support( string $template_body ): bool {
		// A commented-out layout cannot make the selected package visible.
		$template_body = (string) preg_replace( '/<!--[\s\S]*?-->/', '', $template_body );
		// Package/items are available inside a systems iteration. Dotted references
		// such as package.display_name work there; systems.package does not, since
		// systems is a list and must be iterated rather than treated as one object.
		if ( preg_match_all( '/\{\{#systems\}\}(.*?)\{\{\/systems\}\}/s', $template_body, $systems ) ) {
			foreach ( $systems[1] as $system_template ) {
				if ( preg_match( '/\{\{(?:#items|#package|\{?package\.[a-zA-Z0-9_.]+)\}\}\}?/', $system_template ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Shape the payload into the nested view Mustache consumes.
	 *
	 * Input shape matches the /generate body documented in section 6.1. Output shape is what
	 * the Mustache template authors see:
	 *   {
	 *     branding: { logo_url, company_name, address_line, phone, license, tagline, primary_color },
	 *     customer: { name, billing_address_full, phone, email },
	 *     today: "April 23, 2026",
	 *     template: { name, type },
	 *     systems: [
	 *       { system_number, system_label, furnace: {...}, condenser: {...}, coil: {...}, other: [...] }
	 *     ],
	 *     warranty: { parts_years, labor_years },
	 *     rebates: [ { name, amount, amount_formatted } ],
	 *     financing: { requested, term_months },
	 *     pricing: { subtotal, subtotal_formatted, total, total_formatted, deposit_percent, deposit_amount_formatted },
	 *     special_notes: "…",
	 *     has_rebates: true/false,
	 *     has_financing: true/false,
	 *   }
	 *
	 * @param array<string,mixed> $payload       Request body from /preview or /generate.
	 * @param array<string,mixed> $customer      Hydrated customer from Customer_Search.
	 * @param array<string,mixed> $catalog_by_id Map of item_id => normalized item record.
	 * @param array<string,mixed> $template_meta Hydrated template meta (from Template_Meta::hydrate).
	 * @param array<string,mixed> $branding      Optional branding overrides (logo_url, address_line, license, etc.).
	 *
	 * @return array<string,mixed>
	 */
	public function build_payload_view( array $payload, array $customer, array $catalog_by_id, array $template_meta, array $branding = array() ): array {
		$options = isset( $payload['options'] ) && is_array( $payload['options'] ) ? $payload['options'] : array();
		$pricing = isset( $payload['pricing'] ) && is_array( $payload['pricing'] ) ? $payload['pricing'] : array();

		$rebates = array();
		if ( ! empty( $options['rebates'] ) && is_array( $options['rebates'] ) ) {
			foreach ( $options['rebates'] as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$amount = (float) ( $r['amount'] ?? 0 );
				$rebates[] = array(
					'name'             => (string) ( $r['name'] ?? '' ),
					'amount'           => $amount,
					'amount_formatted' => $this->format_money( $amount ),
				);
			}
		}

		$systems = array();
		if ( ! empty( $payload['systems'] ) && is_array( $payload['systems'] ) ) {
			foreach ( $payload['systems'] as $idx => $sys ) {
				if ( ! is_array( $sys ) ) {
					continue;
				}
				$system_number = (int) ( $sys['system_number'] ?? ( $idx + 1 ) );
				$label         = (string) ( $sys['system_label'] ?? sprintf( 'System %d', $system_number ) );
				$equipment     = isset( $sys['equipment'] ) && is_array( $sys['equipment'] ) ? $sys['equipment'] : array();

				$entry = array(
					'system_number' => $system_number,
					'system_label'  => $label,
				);

				// Known slot keys get their own top-level keys (furnace, condenser, coil, air_handler, thermostat).
				// Anything else goes into "other" for templates to iterate.
				$known = array( 'package', 'furnace', 'condenser', 'coil', 'air_handler', 'thermostat', 'humidifier', 'uv_purifier', 'water_heater', 'filter', 'part', 'permit' );
				$other = array();
				$items = array();
				foreach ( $equipment as $slot_key => $slot ) {
					if ( ! is_array( $slot ) ) {
						continue;
					}
					$item_id = (string) ( $slot['item_id'] ?? '' );
					$notes   = (string) ( $slot['notes'] ?? '' );
					$item    = $catalog_by_id[ $item_id ] ?? null;
					if ( ! $item ) {
						continue;
					}
					if ( array_key_exists( 'description', $slot ) ) {
						$description = (string) $slot['description'];
						$item['short_description'] = $description;
						$item['long_description']  = $description;
						$item['description']       = $description;
					}
					if ( 'permit' === $slot_key && array_key_exists( 'rate', $slot ) ) {
						$item['rate'] = max( 0, min( 100000, round( (float) $slot['rate'], 2 ) ) );
					}
					$item['notes'] = $notes;
					$item['slot'] = (string) $slot_key;
					$item['slot_label'] = ucwords( str_replace( '_', ' ', (string) $slot_key ) );
					$name  = trim( (string) ( $item['name'] ?? '' ) );
					$model = trim( (string) ( $item['model'] ?? '' ) );
					$item['display_name'] = $name;
					if ( '' === $item['display_name'] ) {
						$item['display_name'] = trim( sprintf( '%s %s', (string) ( $item['brand'] ?? '' ), $model ) );
					} elseif ( '' !== $model && false === stripos( $item['display_name'], $model ) ) {
						$item['display_name'] .= ' — Model ' . $model;
					}
					$item['rate_formatted'] = $this->format_money( (float) ( $item['rate'] ?? 0 ) );
					$item['is_permit'] = 'permit' === $slot_key;
					$items[] = $item;
					if ( in_array( $slot_key, $known, true ) ) {
						$entry[ $slot_key ] = $item;
					} else {
						$other[] = array_merge( array( 'slot' => $slot_key ), $item );
					}
				}
				$entry['other']     = $other;
				$entry['has_other'] = ! empty( $other );
				$entry['items']     = $items;
				$systems[] = $entry;
			}
		}

		$parts_years = isset( $options['warranty_parts_years'] ) ? (int) $options['warranty_parts_years'] : (int) ( $template_meta['default_warranty_parts'] ?? 0 );
		$labor_years = isset( $options['warranty_labor_years'] ) ? (int) $options['warranty_labor_years'] : (int) ( $template_meta['default_warranty_labor'] ?? 0 );
		$credit_card_fee = ! empty( $options['credit_card_fee_applies'] );
		$financing_fee   = ! empty( $options['financing_fee_applies'] );
		$proposal_notes  = array();
		if ( 10 === $parts_years ) {
			$proposal_notes[] = array( 'text' => __( 'Includes a 10-year manufacturer parts warranty.', 'tc-estimate' ) );
		}
		if ( 10 === $labor_years ) {
			$proposal_notes[] = array( 'text' => __( 'Includes a 10-year labor warranty with an active Temp Control maintenance agreement.', 'tc-estimate' ) );
		}
		if ( $credit_card_fee ) {
			$proposal_notes[] = array( 'text' => __( 'An additional processing fee applies to credit card payments.', 'tc-estimate' ) );
		}
		if ( $financing_fee ) {
			$proposal_notes[] = array( 'text' => __( 'An additional financing fee applies when the project is financed.', 'tc-estimate' ) );
		}

		$subtotal = (float) ( $pricing['subtotal'] ?? 0 );
		$total    = (float) ( $pricing['total'] ?? $subtotal );
		$deposit_pct = (int) ( $pricing['deposit_percent'] ?? 0 );
		$deposit_amount = $deposit_pct > 0 ? ( $total * $deposit_pct / 100 ) : 0;

		$billing = isset( $customer['billing_address'] ) && is_array( $customer['billing_address'] ) ? $customer['billing_address'] : array();

		$brand = array_merge( $this->get_default_branding(), $branding );

		return array(
			'branding' => $brand,
			'customer' => array(
				'name'                => (string) ( $customer['name'] ?? '' ),
				'phone'               => (string) ( $customer['phone'] ?? '' ),
				'email'               => (string) ( $customer['email'] ?? '' ),
				'billing_street'      => (string) ( $billing['street'] ?? '' ),
				'billing_city'        => (string) ( $billing['city'] ?? '' ),
				'billing_state'       => (string) ( $billing['state'] ?? '' ),
				'billing_zip'         => (string) ( $billing['zip'] ?? '' ),
				'billing_address_full' => $this->format_address( $billing ),
			),
			'today'         => date_i18n( 'F j, Y' ),
			'template'      => array(
				'name' => (string) ( $template_meta['name'] ?? '' ),
				'type' => (string) ( $template_meta['template_type'] ?? '' ),
			),
			'systems'       => $systems,
			'system_count'  => count( $systems ),
			'is_multi_system' => count( $systems ) > 1,
			'warranty'      => array(
				'parts_years' => $parts_years,
				'labor_years' => $labor_years,
			),
			'rebates'       => $rebates,
			'has_rebates'   => ! empty( $rebates ),
			'financing'     => array(
				'requested'    => ! empty( $options['financing_requested'] ),
				'term_months'  => (int) ( $options['financing_term_months'] ?? 0 ),
			),
			'has_financing' => ! empty( $options['financing_requested'] ),
			'credit_card_fee_applies' => $credit_card_fee,
			'financing_fee_applies'   => $financing_fee,
			'proposal_notes'           => $proposal_notes,
			'has_proposal_notes'       => ! empty( $proposal_notes ),
			'pricing'       => array(
				'subtotal'                => $subtotal,
				'subtotal_formatted'      => $this->format_money( $subtotal ),
				'total'                   => $total,
				'total_formatted'         => $this->format_money( $total ),
				'deposit_percent'         => $deposit_pct,
				'deposit_amount'          => $deposit_amount,
				'deposit_amount_formatted' => $this->format_money( $deposit_amount ),
			),
			'special_notes' => (string) ( $options['special_notes'] ?? '' ),
		);
	}

	private function format_money( float $amount ): string {
		return '$' . number_format( $amount, 2, '.', ',' );
	}

	/**
	 * Default Temp Control branding. Overridable via the $branding parameter on build_payload_view.
	 *
	 * logo_url resolves to the plugin's assets/logo.png when running under WordPress,
	 * or falls back to a relative "assets/logo.png" path for standalone test renders.
	 */
	private function get_default_branding(): array {
		$logo_url = defined( 'TC_ESTIMATE_PLUGIN_URL' )
			? TC_ESTIMATE_PLUGIN_URL . 'assets/logo.jpg'
			: 'assets/logo.jpg';

		return array(
			'company_name'  => 'Temp Control Heating & Air Conditioning',
			'tagline'       => "It's All About Your Comfort",
			'logo_url'      => $logo_url,
			'address_line'  => '209 Main Street, Woodbridge, NJ 07095',
			'phone'         => '',
			'email'         => '',
			'license'       => 'NJ Contractor License 19HC00277400',
			'primary_color' => '#214c7a',
		);
	}

	private function format_address( array $billing ): string {
		$parts = array_filter( array(
			trim( (string) ( $billing['street'] ?? '' ) ),
			trim( implode( ', ', array_filter( array(
				(string) ( $billing['city'] ?? '' ),
				trim( ( (string) ( $billing['state'] ?? '' ) ) . ' ' . ( (string) ( $billing['zip'] ?? '' ) ) ),
			) ) ) ),
		) );
		return implode( "\n", $parts );
	}

	/**
	 * Lazily create the Mustache engine. Returns null if Mustache isn't autoloaded
	 * so callers can surface a clear setup error.
	 */
	private function get_engine(): ?\Mustache_Engine {
		if ( null !== $this->engine ) {
			return $this->engine;
		}
		if ( ! class_exists( '\Mustache_Engine' ) ) {
			return null;
		}
		$this->engine = new \Mustache_Engine( array(
			'entity_flags' => ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
			'charset'      => 'UTF-8',
			'strict_callables' => true,
			// No template loader — we always render inline strings. Loader unset = safer.
		) );
		return $this->engine;
	}
}
