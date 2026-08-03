<?php
/**
 * Template meta box view.
 *
 * Variables in scope from render_meta_box: $type, $slots, $rebates, $finance, $parts, $labor, $active, $version
 *
 * @package TempControlEstimateBuilder
 */

defined( 'ABSPATH' ) || exit;
?>
<style>
	.tc-tpl-meta th { width: 220px; padding-top: 14px; text-align: left; }
	.tc-tpl-meta textarea { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
	.tc-tpl-tokens { background:#f6f7f7; padding:12px 16px; border-left:4px solid #214c7a; margin-top:16px; }
	.tc-tpl-tokens code { background:#fff; padding:2px 6px; border-radius:3px; }
</style>

<table class="form-table tc-tpl-meta">
	<tr>
		<th><label for="tc_template_type"><?php esc_html_e( 'Template Type', 'tc-estimate' ); ?></label></th>
		<td>
			<select id="tc_template_type" name="tc_template_type">
				<?php foreach ( \TempControl\Estimate\Template_Meta::TYPES as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
	<tr>
		<th><label for="tc_required_slots"><?php esc_html_e( 'Required Equipment Slots', 'tc-estimate' ); ?></label></th>
		<td>
			<textarea id="tc_required_slots" name="tc_required_slots" rows="8" cols="60" style="width:100%;max-width:600px;"><?php echo esc_textarea( $slots ); ?></textarea>
			<p class="description"><?php esc_html_e( 'JSON array. Each entry: { type, min, max }. Valid types: furnace, condenser, coil, air_handler, thermostat, humidifier, uv_purifier, water_heater, filter.', 'tc-estimate' ); ?></p>
		</td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'Options', 'tc-estimate' ); ?></th>
		<td>
			<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="tc_supports_rebates" value="1" <?php checked( $rebates ); ?> /> <?php esc_html_e( 'Supports rebates', 'tc-estimate' ); ?></label>
			<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="tc_supports_financing" value="1" <?php checked( $finance ); ?> /> <?php esc_html_e( 'Supports financing', 'tc-estimate' ); ?></label>
			<label style="display:block;"><input type="checkbox" name="tc_active" value="1" <?php checked( $active ); ?> /> <?php esc_html_e( 'Active (visible to techs)', 'tc-estimate' ); ?></label>
		</td>
	</tr>
	<tr>
		<th><label for="tc_default_warranty_parts"><?php esc_html_e( 'Default Warranty (Parts Years)', 'tc-estimate' ); ?></label></th>
		<td><input type="number" id="tc_default_warranty_parts" name="tc_default_warranty_parts" value="<?php echo esc_attr( (string) $parts ); ?>" min="0" max="25" /></td>
	</tr>
	<tr>
		<th><label for="tc_default_warranty_labor"><?php esc_html_e( 'Default Warranty (Labor Years)', 'tc-estimate' ); ?></label></th>
		<td><input type="number" id="tc_default_warranty_labor" name="tc_default_warranty_labor" value="<?php echo esc_attr( (string) $labor ); ?>" min="0" max="25" /></td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'Version', 'tc-estimate' ); ?></th>
		<td><code><?php echo esc_html( (string) $version ); ?></code> <span class="description"><?php esc_html_e( '(Auto-incremented on every save. Pinned in audit log for replay.)', 'tc-estimate' ); ?></span></td>
	</tr>
</table>

<div class="tc-tpl-tokens">
	<strong><?php esc_html_e( 'Available tokens (Mustache syntax):', 'tc-estimate' ); ?></strong>
	<p style="margin:10px 0 6px;"><em><?php esc_html_e( 'Branding:', 'tc-estimate' ); ?></em> <code>{{branding.logo_url}}</code> <code>{{branding.company_name}}</code> <code>{{branding.address_line}}</code> <code>{{branding.license}}</code> <code>{{branding.tagline}}</code> <code>{{branding.primary_color}}</code></p>
	<p style="margin:10px 0 6px;"><em><?php esc_html_e( 'Top-level:', 'tc-estimate' ); ?></em> <code>{{customer.name}}</code> <code>{{customer.billing_street}}</code> <code>{{customer.billing_city}}</code> <code>{{customer.billing_state}}</code> <code>{{customer.billing_zip}}</code> <code>{{today}}</code> <code>{{template.name}}</code> <code>{{warranty.parts_years}}</code> <code>{{warranty.labor_years}}</code> <code>{{pricing.total_formatted}}</code> <code>{{pricing.deposit_percent}}</code> <code>{{special_notes}}</code></p>
	<p style="margin:10px 0 6px;"><em><?php esc_html_e( 'Iteration:', 'tc-estimate' ); ?></em> <code>{{#systems}} ... {{/systems}}</code> gives access to <code>{{system_label}}</code>, <code>{{furnace.brand}}</code>, <code>{{furnace.model}}</code>, <code>{{furnace.afue}}</code>, <code>{{condenser.seer}}</code>, <code>{{condenser.tons}}</code>, <code>{{condenser.refrigerant}}</code>, <code>{{coil.model}}</code>.</p>
	<p style="margin:10px 0 6px;"><em><?php esc_html_e( 'Conditionals:', 'tc-estimate' ); ?></em> <code>{{#has_rebates}} ... {{/has_rebates}}</code>, <code>{{#has_financing}} ... {{/has_financing}}</code>, <code>{{#is_multi_system}} ... {{/is_multi_system}}</code></p>
	<p style="margin:10px 0 0;"><em><?php esc_html_e( 'Rebates loop:', 'tc-estimate' ); ?></em> <code>{{#rebates}}{{name}} — {{amount_formatted}}{{/rebates}}</code></p>
</div>
