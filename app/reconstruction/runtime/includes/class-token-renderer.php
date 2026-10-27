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
			$template_body = $this->prepare_document_template( $template_body, $view );
			$render_view = $view;
			if ( ! empty( $view['has_proposal_choices'] ) && ! $this->template_has_proposal_choices_support( $template_body ) ) {
				// Unsupported layouts get one explicit alternatives section instead of
				// a selected-equipment copy plus a second, conflicting options list.
				$render_view['systems'] = array();
				$render_view['proposal_systems'] = array();
			}
			foreach ( array( 'maintenance', 'permits' ) as $key ) {
				if ( ! $this->template_has_addon_support( $template_body, $key ) ) {
					unset( $render_view['proposal_addons'][ $key ] );
				}
			}
			$rendered = $engine->render( $template_body, $render_view );
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
		foreach ( (array) ( $view['proposal_systems'] ?? array() ) as $system_index => $system ) {
			$view['proposal_systems'][ $system_index ] = array_intersect_key( $system, array_flip( array( 'system_number', 'system_label', 'selected_choice_id', 'choices' ) ) );
			foreach ( (array) ( $system['choices'] ?? array() ) as $choice_index => $choice ) {
				// The explicit installed sales price belongs to the option, never to
				// its equipment rows. All internal catalog financials remain hidden.
				$view['proposal_systems'][ $system_index ]['choices'][ $choice_index ] = array_intersect_key( $choice, array_flip( array( 'choice_id', 'label', 'sales_price', 'sales_price_formatted', 'is_selected', 'items' ) ) );
				$view['proposal_systems'][ $system_index ]['choices'][ $choice_index ]['items'] = $strip_financial_fields( (array) ( $choice['items'] ?? array() ) );
			}
		}
		$view['proposal_addons'] = $this->build_proposal_addon_views( (array) ( $view['proposal_addons'] ?? array() ) );
		// A proposal summarizes the project sale and its deposit, never the tool's
		// internal equipment subtotal. Rebates retain their existing separate view.
		$view['pricing'] = array_intersect_key( (array) ( $view['pricing'] ?? array() ), array_flip( array(
			'total', 'total_formatted', 'deposit_percent', 'deposit_amount', 'deposit_amount_formatted',
			'project_adjustment', 'project_adjustment_formatted', 'has_project_adjustment',
		) ) );
		return $view;
	}

	/** Adapt the verified legacy token layout on a copy, without editing stored posts. */
	private function prepare_document_template( string $template_body, array $view = array() ): string {
		// This exact sales-subtotal row occurs in both inspected live templates and
		// the seed layout. The customer summary keeps total and deposit only.
		$subtotal_row = '<tr><td style="padding:6px 0;">Subtotal</td><td style="text-align:right;">{{pricing.subtotal_formatted}}</td></tr>';
		$template_body = str_replace( $subtotal_row, '', $template_body );
		// Both inspected live templates use this exact unconditional warranty
		// section. Add conditions on this render copy, keeping custom layouts and
		// stored posts untouched and preserving positive warranty year values.
		$legacy_warranty = '<h3>Warranty</h3>' . "\n<ul>\n  "
			. '<li>{{warranty.parts_years}}-Year Manufacturer Parts Warranty</li>' . "\n  "
			. '<li>{{warranty.labor_years}}-Year Labor Warranty (requires active Temp Control maintenance plan)</li>' . "\n</ul>";
		$conditional_warranty = '{{#warranty.has_any}}<h3>Warranty</h3>' . "\n<ul>\n  "
			. '{{#warranty.has_parts}}<li>{{warranty.parts_years}}-Year Manufacturer Parts Warranty</li>{{/warranty.has_parts}}' . "\n  "
			. '{{#warranty.has_labor}}<li>{{warranty.labor_years}}-Year Labor Warranty (requires active Temp Control maintenance plan)</li>{{/warranty.has_labor}}' . "\n</ul>{{/warranty.has_any}}";
		foreach ( array( "\n", "\r\n" ) as $line_ending ) {
			$template_body = str_replace( str_replace( "\n", $line_ending, $legacy_warranty ), str_replace( "\n", $line_ending, $conditional_warranty ), $template_body );
		}
		// Avoid leaving the seed template's price-only permit separator behind.
		$template_body = str_replace( '{{#is_permit}} — {{rate_formatted}}{{/is_permit}}', '', $template_body );
		// The verified legacy condenser row closes a parenthesis even when SEER is
		// absent. Keep each optional specification self-contained on this copy.
		$legacy_specs = '{{#seer}} ({{seer}} SEER{{/seer}}{{#tons}}, {{tons}} ton{{/tons}}{{#refrigerant}}, {{refrigerant}}{{/refrigerant}})';
		$balanced_specs = '{{#seer}} ({{seer}} SEER){{/seer}}{{#tons}} — {{tons}} ton{{/tons}}{{#refrigerant}} — {{refrigerant}}{{/refrigerant}}';
		$template_body = (string) preg_replace_callback( '/\{\{#condenser\}\}(.*?)\{\{\/condenser\}\}/s', static fn ( array $match ): string => str_replace( $legacy_specs, $balanced_specs, $match[0] ), $template_body );
		if ( isset( $view['proposal_addons']['permits'] ) ) {
			$template_body = str_replace( ' and do not include any required permits beyond those standard for NJ residential HVAC work', '', $template_body );
		}
		if ( ! empty( $view['has_proposal_choices'] ) ) {
			if ( $this->template_has_proposal_choices_support( $template_body ) ) {
				return $template_body;
			}
			// Replace only a single self-contained equipment loop. The inspected
			// legacy templates put their heading/list inside this systems block.
			preg_match_all( '/\{\{#systems\}\}(.*?)\{\{\/systems\}\}/s', $template_body, $blocks, PREG_SET_ORDER );
			$equipment_blocks = array_values( array_filter( $blocks, static fn ( array $block ): bool =>
				str_contains( $block[1], '<h4' ) && str_contains( $block[1], '<ul' ) &&
				1 === preg_match( '/\{\{#(?:items|package|furnace|condenser|coil|has_other|other)\}\}/', $block[1] )
			) );
			if ( 1 === count( $equipment_blocks ) ) {
				return str_replace( $equipment_blocks[0][0], $this->proposal_choices_template(), $template_body );
			}
			return $template_body;
		}
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
		if ( ! empty( $view['has_proposal_choices'] ) && ! $this->template_has_proposal_choices_support( $template_body ) ) {
			$engine = $this->get_engine();
			if ( null !== $engine ) {
				$sections[] = '<h3>Equipment Options by System</h3>' . $engine->render( $this->proposal_choices_template(), $view );
			}
		}

		// Package is a known slot, so legacy #other loops no longer include it.
		// Only an explicit package reference or generic items loop renders it.
		if ( empty( $view['has_proposal_choices'] ) && ! $this->template_has_package_support( $template_body ) ) {
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

		if ( empty( $view['has_proposal_choices'] ) && ! isset( $view['proposal_addons']['permits'] ) && ! str_contains( $template_body, '{{#permit}}' ) && ! str_contains( $template_body, '{{#items}}' ) ) {
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

		$addon_sections = $this->proposal_addon_sections( $view, $template_body );
		if ( '' !== $addon_sections ) {
			if ( 1 === substr_count( $rendered, '<h3>Investment</h3>' ) ) {
				$rendered = str_replace( '<h3>Investment</h3>', $addon_sections . "\n<h3>Investment</h3>", $rendered );
			} else {
				$sections[] = $addon_sections;
			}
		}
		if ( ! empty( $view['has_proposal_choices'] ) && ! empty( $view['pricing']['has_project_adjustment'] ) && ! str_contains( $template_body, 'pricing.project_adjustment_formatted' ) ) {
			$adjustment = '<p><strong>Project adjustment: ' . esc_html( (string) $view['pricing']['project_adjustment_formatted'] ) . '</strong> — applies once to the selected project.</p>';
			if ( 1 === substr_count( $rendered, '<h3>Investment</h3>' ) ) {
				$rendered = str_replace( '<h3>Investment</h3>', '<h3>Investment</h3>' . "\n" . $adjustment, $rendered );
			} else {
				$sections[] = $adjustment;
			}
		}
		return empty( $sections ) ? $rendered : $rendered . "\n" . implode( "\n", $sections );
	}

	/** Customer option totals are deliberately distinct from equipment unit prices. */
	private function proposal_choices_template(): string {
		return '<p>The project total includes only the marked selection for each system.</p>{{#proposal_systems}}<section class="tc-proposal-system"><h4 style="color:#214c7a;margin-bottom:4px;">{{system_label}}</h4>'
			. '{{#choices}}<div class="tc-proposal-choice" style="margin:10px 0;padding:10px;border:1px solid #d1d5db;">'
			. '<p style="margin:0 0 6px;"><strong>{{label}}</strong>{{#is_selected}} — Selected for project total{{/is_selected}}</p>'
			. '<ul style="margin:4px 0;">{{#items}}<li>{{display_name}}{{#short_description}} — {{short_description}}{{/short_description}}</li>{{/items}}</ul>'
			. '<p style="margin:6px 0 0;"><strong>Option price: {{sales_price_formatted}}</strong></p></div>{{/choices}}</section>{{/proposal_systems}}';
	}

	private function template_has_proposal_choices_support( string $template_body ): bool {
		$template_body = (string) preg_replace( '/<!--[\s\S]*?-->/', '', $template_body );
		if ( preg_match_all( '/\{\{#proposal_systems\}\}(.*?)\{\{\/proposal_systems\}\}/s', $template_body, $systems ) ) {
			foreach ( $systems[1] as $system ) {
				if ( 1 === preg_match( '/\{\{#choices\}\}(.*?)\{\{\/choices\}\}/s', $system, $choice ) &&
					1 === preg_match( '/\{\{#items\}\}.*?\{\{\/items\}\}/s', $choice[1] ) &&
					1 === preg_match( '/\{\{sales_price(?:_formatted)?\}\}/', $choice[1] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private function template_has_addon_support( string $template_body, string $key ): bool {
		$template_body = (string) preg_replace( '/<!--[\s\S]*?-->/', '', $template_body );
		$token = preg_quote( 'proposal_addons.' . $key, '/' );
		return 1 === preg_match( '/\{\{#' . $token . '\}\}(.*?)\{\{\/' . $token . '\}\}/s', $template_body, $section ) &&
			str_contains( $section[1], '{{name}}' ) && str_contains( $section[1], '{{description}}' ) &&
			1 === preg_match( '/\{\{rate(?:_formatted)?\}\}/', $section[1] );
	}

	private function proposal_addon_sections( array $view, string $template_body ): string {
		$parts = array();
		if ( isset( $view['proposal_addons']['maintenance'] ) && ! $this->template_has_addon_support( $template_body, 'maintenance' ) ) {
			$parts[] = '<h3>New Residential System Maintenance Agreement</h3><p>Included: <strong>$0.00</strong></p><p>Renews at $349 for one unit.</p>';
		}
		if ( isset( $view['proposal_addons']['permits'] ) && ! $this->template_has_addon_support( $template_body, 'permits' ) ) {
			$parts[] = '<h3>Permits &amp; Inspections</h3><p>Included: <strong>$0.00</strong></p>';
			foreach ( $this->permit_paragraphs() as $paragraph ) {
				$parts[] = '<p>' . esc_html( $paragraph ) . '</p>';
			}
		}
		return implode( "\n", $parts );
	}

	private function permit_paragraphs(): array {
		return array(
			'Permit fees are not included in the project total.',
			'Temp Control will prepare and complete all required permit paperwork and submit the necessary documentation to the local township on the homeowner’s behalf.',
			'All work will be performed in accordance with local building codes and manufacturer specifications. Permit payment, pickup and scheduling of Inspections are the responsibility of the homeowner.',
		);
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

		$view = array(
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
				'has_parts' => $parts_years > 0,
				'has_labor' => $labor_years > 0,
				'has_any' => $parts_years > 0 || $labor_years > 0,
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
				'project_adjustment' => (float) ( $pricing['project_adjustment'] ?? 0 ),
				'project_adjustment_formatted' => $this->format_money( (float) ( $pricing['project_adjustment'] ?? 0 ) ),
				'has_project_adjustment' => 0.0 !== (float) ( $pricing['project_adjustment'] ?? 0 ),
			),
			'special_notes' => (string) ( $options['special_notes'] ?? '' ),
		);
		$view['proposal_systems'] = $this->build_proposal_system_views( $payload, $customer, $catalog_by_id, $template_meta, $branding );
		$view['has_proposal_choices'] = ! empty( $view['proposal_systems'] );
		$view['proposal_addons'] = $this->build_proposal_addon_views( (array) ( $payload['_proposal_addons'] ?? array() ) );
		return $view;
	}

	/**
	 * Keep all offered alternatives separate from the selected systems consumed by
	 * Books/CRM. Only explicit option sale totals cross into this public view.
	 */
	private function build_proposal_system_views( array $payload, array $customer, array $catalog_by_id, array $template_meta, array $branding ): array {
		$offered = (array) ( $payload['_proposal_systems'] ?? array() );
		$has_choices = false;
		foreach ( $offered as $system ) {
			if ( is_array( $system ) && ! empty( $system['choices'] ) && is_array( $system['choices'] ) ) {
				$has_choices = true;
			}
		}
		if ( ! $has_choices ) {
			return array();
		}
		$result = array();
		foreach ( $offered as $index => $system ) {
			if ( ! is_array( $system ) ) {
				continue;
			}
			$number = (int) ( $system['system_number'] ?? $index + 1 );
			$label = (string) ( $system['system_label'] ?? sprintf( 'System %d', $number ) );
			$choices = $system['choices'] ?? null;
			$legacy = ! is_array( $choices ) || empty( $choices );
			$selected_id = $legacy ? 'legacy_' . $number : (string) ( $system['selected_choice_id'] ?? '' );
			if ( $legacy ) {
				$choices = array( array( 'choice_id' => $selected_id, 'label' => 'Current selection', 'equipment' => (array) ( $system['equipment'] ?? array() ) ) );
			}
			$choice_views = array();
			foreach ( $choices as $choice ) {
				if ( ! is_array( $choice ) ) {
					continue;
				}
				// This nested view has no offered metadata, so it cannot recurse or
				// accidentally turn alternatives into additional physical systems.
				$nested = $this->build_payload_view( array( 'systems' => array( array(
					'system_number' => $number, 'system_label' => $label, 'equipment' => (array) ( $choice['equipment'] ?? array() ),
				) ) ), $customer, $catalog_by_id, $template_meta, $branding );
				$items = (array) ( $nested['systems'][0]['items'] ?? array() );
				$catalog_sales = array_sum( array_map( static fn ( array $item ): float => (float) ( $item['rate'] ?? 0 ), $items ) );
				$sale = $legacy ? $catalog_sales : (float) ( $choice['sales_price'] ?? 0 );
				$public_items = array();
				foreach ( $items as $item ) {
					$public_items[] = array_intersect_key( $item, array_flip( array(
						'item_id', 'slot', 'slot_label', 'name', 'display_name', 'brand', 'model', 'short_description', 'notes',
						'tons', 'btu_input', 'btu_output', 'afue', 'efficiency_percent', 'seer', 'refrigerant', 'category_2',
					) ) );
				}
				$choice_views[] = array(
					'choice_id' => (string) ( $choice['choice_id'] ?? '' ),
					'label' => (string) ( $choice['label'] ?? 'Option' ),
					'sales_price' => $sale,
					'sales_price_formatted' => $this->format_money( $sale ),
					'is_selected' => (string) ( $choice['choice_id'] ?? '' ) === $selected_id,
					'items' => $public_items,
				);
			}
			$result[] = array( 'system_number' => $number, 'system_label' => $label, 'selected_choice_id' => $selected_id, 'choices' => $choice_views );
		}
		return $result;
	}

	/** Canonical records are freshly resolved by the authenticated endpoint helper. */
	private function build_proposal_addon_views( array $records ): array {
		$names = array( 'maintenance' => 'New Residential System Maintenance Agreement', 'permits' => 'Permits & Inspections' );
		$result = array();
		foreach ( $names as $key => $name ) {
			$record = $records[ $key ] ?? null;
			if ( ! is_array( $record ) || true !== ( $record['available'] ?? null ) || ( $record['name'] ?? null ) !== $name ||
				! is_string( $record['item_id'] ?? null ) || 1 !== preg_match( '/^[0-9]{10,20}$/', $record['item_id'] ) ||
				! is_numeric( $record['rate'] ?? null ) || 0.0 !== (float) $record['rate'] || (int) ( $record['quantity'] ?? 0 ) !== 1 ) {
				continue;
			}
			$description = 'maintenance' === $key ? 'Renews at $349 for one unit.' : implode( "\n\n", $this->permit_paragraphs() );
			$result[ $key ] = array( 'available' => true, 'addon_key' => $key, 'item_id' => $record['item_id'], 'name' => $name,
				'rate' => 0.0, 'rate_formatted' => '$0.00', 'quantity' => 1, 'description' => $description );
			if ( 'maintenance' === $key ) {
				$result[ $key ]['renewal_rate'] = 349.0;
				$result[ $key ]['renewal_units'] = 1;
			}
		}
		return $result;
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
