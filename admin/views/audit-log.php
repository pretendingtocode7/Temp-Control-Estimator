<?php
/**
 * Audit log view.
 *
 * Variables in scope: $rows, $status_filter
 *
 * @package TempControlEstimateBuilder
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1 style="border-bottom: 3px solid #214c7a; padding-bottom: 10px;"><?php esc_html_e( 'Estimate Builder — Audit Log', 'tc-estimate' ); ?></h1>

	<form method="get" style="margin: 16px 0;">
		<input type="hidden" name="page" value="tc-estimate-audit" />
		<label><?php esc_html_e( 'Filter by status:', 'tc-estimate' ); ?>
			<select name="status" onchange="this.form.submit()">
				<option value=""><?php esc_html_e( 'All', 'tc-estimate' ); ?></option>
				<?php foreach ( array( 'success', 'pending', 'error' ) as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status_filter, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
	</form>

	<?php if ( empty( $rows ) ) : ?>
		<p><em><?php esc_html_e( 'No audit entries yet. Entries appear once /generate is called.', 'tc-estimate' ); ?></em></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'tc-estimate' ); ?></th>
					<th><?php esc_html_e( 'User', 'tc-estimate' ); ?></th>
					<th><?php esc_html_e( 'Action', 'tc-estimate' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tc-estimate' ); ?></th>
					<th><?php esc_html_e( 'Template', 'tc-estimate' ); ?></th>
					<th><?php esc_html_e( 'Account', 'tc-estimate' ); ?></th>
					<th><?php esc_html_e( 'Estimate / Deal', 'tc-estimate' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'tc-estimate' ); ?></th>
					<th><?php esc_html_e( 'Error', 'tc-estimate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$dc = (string) get_option( 'tc_estimate_zoho_dc', 'com' );
				foreach ( $rows as $row ) :
					$user = get_userdata( (int) $row['user_id'] );
					$user_label = $user ? $user->display_name : sprintf( '#%d', (int) $row['user_id'] );
					$status_color = 'success' === $row['status'] ? '#00a32a' : ( 'error' === $row['status'] ? '#d63638' : '#8a6d3b' );
					$estimate_url = ! empty( $row['zoho_estimate_id'] ) ? sprintf( 'https://books.zoho.%s/app/#/estimates/%s', $dc, rawurlencode( $row['zoho_estimate_id'] ) ) : '';
					$deal_url     = ! empty( $row['zoho_deal_id'] ) ? sprintf( 'https://crm.zoho.%s/crm/tab/Potentials/%s', $dc, rawurlencode( $row['zoho_deal_id'] ) ) : '';
					?>
					<tr>
						<td><code><?php echo esc_html( $row['created_at'] ); ?></code></td>
						<td><?php echo esc_html( $user_label ); ?></td>
						<td><?php echo esc_html( $row['action'] ); ?></td>
						<td><strong style="color:<?php echo esc_attr( $status_color ); ?>"><?php echo esc_html( $row['status'] ); ?></strong></td>
						<td>#<?php echo esc_html( (string) $row['template_id'] ); ?> v<?php echo esc_html( (string) $row['template_version'] ); ?></td>
						<td><code style="font-size:11px;"><?php echo esc_html( $row['zoho_account_id'] ); ?></code></td>
						<td style="font-size:12px;">
							<?php if ( $estimate_url ) : ?>
								<a href="<?php echo esc_url( $estimate_url ); ?>" target="_blank" rel="noopener">Estimate <?php echo esc_html( substr( $row['zoho_estimate_id'], -6 ) ); ?></a><br>
							<?php endif; ?>
							<?php if ( $deal_url ) : ?>
								<a href="<?php echo esc_url( $deal_url ); ?>" target="_blank" rel="noopener">Deal <?php echo esc_html( substr( $row['zoho_deal_id'], -6 ) ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $row['duration_ms'] ); ?>ms</td>
						<td style="max-width:240px;font-size:12px;color:#646970;"><?php echo esc_html( $row['error_message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
