<?php
/**
 * Settings page view.
 *
 * Variables available: $client_id, $has_secret, $has_refresh, $org_id, $dc, $webhook_url, $webhook_secret, $flash
 *
 * @package TempControlEstimateBuilder
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap" style="max-width: 900px;">
	<h1 style="border-bottom: 3px solid #214c7a; padding-bottom: 10px;">
		<?php esc_html_e( 'Estimate Builder — Settings', 'tc-estimate' ); ?>
	</h1>

	<?php if ( is_array( $flash ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $flash['type'] ); ?> is-dismissible" style="margin-top: 16px;">
			<p><?php echo esc_html( $flash['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! defined( 'TC_ESTIMATE_ENC_KEY' ) ) : ?>
		<div class="notice notice-warning" style="margin-top: 16px;">
			<p>
				<strong><?php esc_html_e( 'Encryption key not set.', 'tc-estimate' ); ?></strong>
				<?php esc_html_e( 'Add a dedicated encryption key to wp-config.php for the Zoho refresh token. Without it, the plugin derives a key from AUTH_KEY — functional but less robust to salt rotation.', 'tc-estimate' ); ?>
			</p>
			<p><code style="background:#f6f7f7;padding:6px 10px;display:inline-block;">define( 'TC_ESTIMATE_ENC_KEY', '<?php echo esc_html( bin2hex( random_bytes( 32 ) ) ); ?>' );</code></p>
			<p style="font-size:12px;color:#646970;"><?php esc_html_e( '(Copy that line into wp-config.php above the "That\'s all, stop editing" marker. That example key is regenerated every page load — save one copy and keep it.)', 'tc-estimate' ); ?></p>
		</div>
	<?php endif; ?>

	<h2 style="margin-top: 24px;"><?php esc_html_e( 'Zoho OAuth Configuration', 'tc-estimate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Register a self-client at api-console.zoho.com. Required scopes: ZohoCRM.modules.ALL and ZohoBooks.fullaccess.all. Generate a refresh token via the grant-token flow and paste it below — it is encrypted at rest.', 'tc-estimate' ); ?>
	</p>

	<table class="widefat striped" style="max-width: 720px; margin: 14px 0 18px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Credential', 'tc-estimate' ); ?></th>
				<th><?php esc_html_e( 'Saved status', 'tc-estimate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><?php esc_html_e( 'Client ID', 'tc-estimate' ); ?></td>
				<td>
					<?php if ( '' !== $client_id ) : ?>
						<strong style="color:#008a20;"><?php esc_html_e( 'Saved', 'tc-estimate' ); ?></strong>
						<code style="margin-left:6px;"><?php echo esc_html( substr( $client_id, 0, 8 ) . '...' . substr( $client_id, -4 ) ); ?></code>
					<?php else : ?>
						<strong style="color:#b32d2e;"><?php esc_html_e( 'Missing', 'tc-estimate' ); ?></strong>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Client Secret', 'tc-estimate' ); ?></td>
				<td><?php echo $has_secret ? '<strong style="color:#008a20;">' . esc_html__( 'Saved', 'tc-estimate' ) . '</strong>' : '<strong style="color:#b32d2e;">' . esc_html__( 'Missing', 'tc-estimate' ) . '</strong>'; ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Refresh Token', 'tc-estimate' ); ?></td>
				<td><?php echo $has_refresh ? '<strong style="color:#008a20;">' . esc_html__( 'Saved', 'tc-estimate' ) . '</strong>' : '<strong style="color:#b32d2e;">' . esc_html__( 'Missing', 'tc-estimate' ) . '</strong>'; ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Organization ID', 'tc-estimate' ); ?></td>
				<td><?php echo '' !== $org_id ? '<strong style="color:#008a20;">' . esc_html__( 'Saved', 'tc-estimate' ) . '</strong>' : '<strong style="color:#b32d2e;">' . esc_html__( 'Missing', 'tc-estimate' ) . '</strong>'; ?></td>
			</tr>
		</tbody>
	</table>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tc_estimate_save_settings" />
		<?php wp_nonce_field( 'tc_estimate_save_settings' ); ?>

		<table class="form-table">
			<tr>
				<th scope="row"><label for="tc_dc"><?php esc_html_e( 'Data Center', 'tc-estimate' ); ?></label></th>
				<td>
					<select id="tc_dc" name="dc">
						<?php foreach ( array( 'com' => 'United States (.com)', 'eu' => 'Europe (.eu)', 'in' => 'India (.in)', 'com.au' => 'Australia (.com.au)', 'com.cn' => 'China (.com.cn)', 'jp' => 'Japan (.jp)' ) as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $dc, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tc_client_id"><?php esc_html_e( 'Client ID', 'tc-estimate' ); ?></label></th>
				<td><input type="text" id="tc_client_id" name="client_id" value="<?php echo esc_attr( $client_id ); ?>" class="regular-text" autocomplete="off" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="tc_client_secret"><?php esc_html_e( 'Client Secret', 'tc-estimate' ); ?></label></th>
				<td>
					<input type="password" id="tc_client_secret" name="client_secret" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_secret ? esc_attr__( '(saved — leave blank to keep)', 'tc-estimate' ) : ''; ?>" />
					<p class="description"><?php esc_html_e( 'This field is intentionally blank after saving. Use the saved-status table above to confirm it is stored.', 'tc-estimate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tc_refresh_token"><?php esc_html_e( 'Refresh Token', 'tc-estimate' ); ?></label></th>
				<td>
					<input type="password" id="tc_refresh_token" name="refresh_token" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_refresh ? esc_attr__( '(saved — leave blank to keep)', 'tc-estimate' ) : ''; ?>" />
					<p class="description"><?php esc_html_e( 'Encrypted with libsodium before storage. This field is intentionally blank after saving.', 'tc-estimate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tc_org_id"><?php esc_html_e( 'Zoho Books Organization ID', 'tc-estimate' ); ?></label></th>
				<td>
					<input type="text" id="tc_org_id" name="org_id" value="<?php echo esc_attr( $org_id ); ?>" class="regular-text" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'Required for loading eligible Books items and creating estimates.', 'tc-estimate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Estimate Item Source', 'tc-estimate' ); ?></th>
				<td>
					<strong><?php esc_html_e( 'Zoho Books Items', 'tc-estimate' ); ?></strong>
					<p class="description"><?php esc_html_e( 'Only active Items with the cf_for_estimate checkbox selected are available in the builder. Selected Items become the estimate line items.', 'tc-estimate' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary" style="background:#214c7a;border-color:#214c7a;">
				<?php esc_html_e( 'Save Settings', 'tc-estimate' ); ?>
			</button>
		</p>
	</form>

	<hr style="margin: 32px 0;" />

	<h2><?php esc_html_e( 'Diagnostics', 'tc-estimate' ); ?></h2>
	<div style="display:flex;gap:12px;flex-wrap:wrap;">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tc_estimate_test_zoho" />
			<?php wp_nonce_field( 'tc_estimate_test_zoho' ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Test Zoho Connection', 'tc-estimate' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tc_estimate_refresh_catalog" />
			<?php wp_nonce_field( 'tc_estimate_refresh_catalog' ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Clear Catalog Cache', 'tc-estimate' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tc_estimate_test_books_items" />
			<?php wp_nonce_field( 'tc_estimate_test_books_items' ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Test Eligible Books Items', 'tc-estimate' ); ?></button>
		</form>
	</div>

	<hr style="margin: 32px 0;" />

	<h2><?php esc_html_e( 'Templates', 'tc-estimate' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Mustache-based proposal templates. Each template is one estimate type (full replacement, AC only, etc.). Field techs see a list of these in the wizard.', 'tc-estimate' ); ?></p>

	<?php
	// Inline template management uses direct DB writes via wp_insert_post()/wp_update_post(),
	// gated on the Settings page's manage_options check. This bypasses the CPT capability
	// chain entirely — useful when register_post_type's cap mapping isn't picking up
	// correctly due to host-level caching.
	$tc_estimate_templates = get_posts( array(
		'post_type'   => \TempControl\Estimate\Template_CPT::POST_TYPE,
		'post_status' => array( 'publish', 'draft' ),
		'numberposts' => -1,
		'orderby'     => 'title',
		'order'       => 'ASC',
	) );
	$tc_editing_id = isset( $_GET['tc_template_edit'] ) ? (int) $_GET['tc_template_edit'] : 0;
	$tc_editing    = $tc_editing_id > 0 ? get_post( $tc_editing_id ) : null;
	$tc_editing_meta = $tc_editing ? \TempControl\Estimate\Template_Meta::instance()->hydrate( $tc_editing->ID ) : array();
	?>

	<?php if ( ! empty( $tc_estimate_templates ) ) : ?>
		<table class="widefat striped" style="margin-top: 12px; max-width: 100%;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'tc-estimate' ); ?></th>
					<th><?php esc_html_e( 'Type', 'tc-estimate' ); ?></th>
					<th><?php esc_html_e( 'Version', 'tc-estimate' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tc-estimate' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'tc-estimate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tc_estimate_templates as $tpl ) :
					$meta = \TempControl\Estimate\Template_Meta::instance()->hydrate( $tpl->ID );
					?>
					<tr>
						<td><strong><?php echo esc_html( $tpl->post_title ); ?></strong></td>
						<td><code><?php echo esc_html( $meta['template_type'] ?? '—' ); ?></code></td>
						<td>v<?php echo esc_html( (string) ( $meta['version'] ?? 1 ) ); ?></td>
						<td><?php echo esc_html( $tpl->post_status ); ?></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( 'tc_template_edit', $tpl->ID, admin_url( 'admin.php?page=tc-estimate' ) ) ); ?>"><?php esc_html_e( 'Edit', 'tc-estimate' ); ?></a>
							 |
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tc_estimate_delete_template&template_id=' . $tpl->ID ), 'tc_estimate_delete_template_' . $tpl->ID ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this template?', 'tc-estimate' ) ); ?>');" style="color:#d63638;"><?php esc_html_e( 'Delete', 'tc-estimate' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p style="margin-top:12px;"><em><?php esc_html_e( 'No templates yet. Create one below.', 'tc-estimate' ); ?></em></p>
	<?php endif; ?>

	<h3 style="margin-top: 24px;">
		<?php echo $tc_editing
			? esc_html( sprintf( __( 'Edit Template: %s', 'tc-estimate' ), $tc_editing->post_title ) )
			: esc_html__( 'Create Template', 'tc-estimate' ); ?>
		<?php if ( $tc_editing ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=tc-estimate' ) ); ?>" class="button button-small" style="margin-left:8px;"><?php esc_html_e( 'Cancel edit', 'tc-estimate' ); ?></a>
		<?php endif; ?>
	</h3>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tc_estimate_save_template" />
		<input type="hidden" name="template_id" value="<?php echo esc_attr( (string) $tc_editing_id ); ?>" />
		<?php wp_nonce_field( 'tc_estimate_save_template' ); ?>

		<table class="form-table">
			<tr>
				<th scope="row"><label for="tc_tpl_title"><?php esc_html_e( 'Title', 'tc-estimate' ); ?></label></th>
				<td>
					<input id="tc_tpl_title" type="text" name="title" class="regular-text" required
						value="<?php echo esc_attr( $tc_editing ? $tc_editing->post_title : '' ); ?>"
						placeholder="Full Replacement — Coleman" />
					<p class="description"><?php esc_html_e( 'Shown in the field-tech wizard.', 'tc-estimate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tc_tpl_type"><?php esc_html_e( 'Type', 'tc-estimate' ); ?></label></th>
				<td>
					<?php
					$tc_current_type = $tc_editing_meta['template_type'] ?? 'full_replacement';
					$tc_types = array(
						'full_replacement' => __( 'Full Replacement', 'tc-estimate' ),
						'ac_only'          => __( 'AC Only', 'tc-estimate' ),
						'furnace_only'     => __( 'Furnace Only', 'tc-estimate' ),
						'maintenance'      => __( 'Maintenance', 'tc-estimate' ),
						'service_repair'   => __( 'Service / Repair', 'tc-estimate' ),
					);
					?>
					<select id="tc_tpl_type" name="template_type">
						<?php foreach ( $tc_types as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $tc_current_type, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tc_tpl_warranty_parts"><?php esc_html_e( 'Default Warranty (Parts)', 'tc-estimate' ); ?></label></th>
				<td>
					<input id="tc_tpl_warranty_parts" type="number" name="warranty_parts" min="0" max="25" class="small-text"
						value="<?php echo esc_attr( (string) ( $tc_editing_meta['default_warranty_parts'] ?? 10 ) ); ?>" />
					<?php esc_html_e( 'years', 'tc-estimate' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tc_tpl_warranty_labor"><?php esc_html_e( 'Default Warranty (Labor)', 'tc-estimate' ); ?></label></th>
				<td>
					<input id="tc_tpl_warranty_labor" type="number" name="warranty_labor" min="0" max="25" class="small-text"
						value="<?php echo esc_attr( (string) ( $tc_editing_meta['default_warranty_labor'] ?? 10 ) ); ?>" />
					<?php esc_html_e( 'years', 'tc-estimate' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tc_tpl_status"><?php esc_html_e( 'Status', 'tc-estimate' ); ?></label></th>
				<td>
					<?php $tc_current_status = $tc_editing ? $tc_editing->post_status : 'publish'; ?>
					<select id="tc_tpl_status" name="status">
						<option value="publish" <?php selected( $tc_current_status, 'publish' ); ?>><?php esc_html_e( 'Active (visible to techs)', 'tc-estimate' ); ?></option>
						<option value="draft" <?php selected( $tc_current_status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'tc-estimate' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tc_tpl_body"><?php esc_html_e( 'Mustache Body', 'tc-estimate' ); ?></label></th>
				<td>
					<textarea id="tc_tpl_body" name="body" rows="20" style="width:100%;font-family:ui-monospace,'SF Mono',Menlo,monospace;font-size:12px;" placeholder="Paste the Mustache template body. Use {{variable}} for HTML-escaped output and {{#section}}...{{/section}} for blocks."><?php echo esc_textarea( $tc_editing ? $tc_editing->post_content : '' ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Available variables: customer.*, branding.*, systems[], pricing.*, warranty.*, financing.*, rebates[], today, special_notes. See seed-templates/full-replacement.mustache in the plugin source.', 'tc-estimate' ); ?></p>
				</td>
			</tr>
		</table>

		<p>
			<button type="submit" class="button button-primary" style="background:#214c7a;border-color:#214c7a;">
				<?php echo $tc_editing
					? esc_html__( 'Update Template', 'tc-estimate' )
					: esc_html__( 'Create Template', 'tc-estimate' ); ?>
			</button>
		</p>
	</form>

	<hr style="margin: 32px 0;" />

	<h2><?php esc_html_e( 'Books Acceptance Webhook (Phase 3)', 'tc-estimate' ); ?></h2>
	<p class="description"><?php esc_html_e( 'When Phase 3 ships, configure Zoho Books to POST to this URL on estimate acceptance with the signature header below.', 'tc-estimate' ); ?></p>
	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e( 'Webhook URL', 'tc-estimate' ); ?></th>
			<td><code style="user-select:all;"><?php echo esc_html( $webhook_url ); ?></code></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Signature Header', 'tc-estimate' ); ?></th>
			<td><code>X-TC-Signature: &lt;hmac_sha256(body, secret)&gt;</code></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Shared Secret', 'tc-estimate' ); ?></th>
			<td>
				<code style="user-select:all;"><?php echo esc_html( $webhook_secret ); ?></code>
				<p class="description"><?php esc_html_e( 'Auto-generated on first view. Do not share outside Zoho Books webhook configuration.', 'tc-estimate' ); ?></p>
			</td>
		</tr>
	</table>

	<hr style="margin: 32px 0;" />

	<h2><?php esc_html_e( 'Maintenance', 'tc-estimate' ); ?></h2>
	<p class="description"><?php esc_html_e( 'If the Estimate Builder menu items ever show "Sorry, you are not allowed to access this page", use the button below to re-grant the Administrator and Technician roles their capabilities. Safe to run multiple times.', 'tc-estimate' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
		<input type="hidden" name="action" value="tc_estimate_reinstall_caps" />
		<?php wp_nonce_field( 'tc_estimate_reinstall_caps' ); ?>
		<button type="submit" class="button"><?php esc_html_e( 'Reinstall Roles and Capabilities', 'tc-estimate' ); ?></button>
	</form>
</div>
