<?php

/**
 * Invoices Endpoint Template
 */

if (! defined('ABSPATH')) {
	exit;
}

$current_user = wp_get_current_user();
$user_id      = (int) $current_user->ID;
$view         = (isset($_GET['cwd_status']) && 'all' === $_GET['cwd_status']) ? 'all' : 'open';

if ('all' === $view) {
	$invoices = CWD_V2_Invoices::get_invoices_for_user(
		$user_id,
		array(
			'orderby' => 'invoice_date',
			'order'   => 'DESC',
			'limit'   => 100,
		)
	);
} else {
	$invoices = CWD_V2_Invoices::get_outstanding_invoices_for_user($user_id, 100);
}

$open_url = esc_url(wc_get_endpoint_url('invoices'));
$all_url  = esc_url(add_query_arg('cwd_status', 'all', wc_get_endpoint_url('invoices')));
?>
<div class="cwd-v2-invoices-dashboard">
	<div class="cwd-v2-section-heading">
		<div>
			<h3><?php _e('Invoices', 'custom-woo-dashboard'); ?></h3>
			<p><?php _e('View your invoices, check what is still owed, and pay off individual invoices.', 'custom-woo-dashboard'); ?></p>
		</div>
		<nav class="cwd-v2-section-links" aria-label="<?php esc_attr_e('Invoice views', 'custom-woo-dashboard'); ?>">
			<a href="<?php echo $open_url; ?>"><?php esc_html_e('Open', 'custom-woo-dashboard'); ?></a>
			<a href="<?php echo $all_url; ?>"><?php esc_html_e('All', 'custom-woo-dashboard'); ?></a>
		</nav>
	</div>

	<?php if (! empty($invoices)) : ?>
		<div class="cwd-v2-table-wrapper">
			<table class="cwd-v2-data-table woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
				<thead>
					<tr>
						<th><?php esc_html_e('Invoice #', 'custom-woo-dashboard'); ?></th>
						<th><?php esc_html_e('Source', 'custom-woo-dashboard'); ?></th>
						<th><?php esc_html_e('Date', 'custom-woo-dashboard'); ?></th>
						<th><?php esc_html_e('Due Date', 'custom-woo-dashboard'); ?></th>
						<th class="th-num"><?php esc_html_e('Outstanding', 'custom-woo-dashboard'); ?></th>
						<th style="text-align: center;"><?php esc_html_e('Status', 'woocommerce'); ?></th>
						<th style="text-align: right;"><?php esc_html_e('Actions', 'woocommerce'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($invoices as $invoice) : ?>
						<?php
						$is_written_off = in_array(strtolower((string) $invoice->status), array('written_off', 'marked_off'), true);
						$is_overdue    = ! $is_written_off && CWD_V2_Invoices::is_overdue($invoice);
						$status_label  = $is_written_off ? __('MARKED OFF', 'custom-woo-dashboard') : ucfirst($invoice->status);
						$status_class  = $is_written_off ? 'cwd-v2-status-written_off' : ('cwd-v2-status-' . esc_attr($invoice->status));
						if ($is_overdue) {
							$status_label = __('Overdue', 'custom-woo-dashboard');
							$status_class = 'cwd-v2-status-overdue';
						}
						$view_url = esc_url(wc_get_endpoint_url('view-invoice', $invoice->id));
						?>
						<tr>
							<td data-title="<?php esc_attr_e('Invoice #', 'custom-woo-dashboard'); ?>" style="font-weight: 600; white-space: nowrap;">
								<a href="<?php echo $view_url; ?>" style="color: #0f172a; text-decoration: none;"><?php echo esc_html($invoice->invoice_number); ?></a>
								<?php if ( ! empty($invoice->order_id) ) : ?>
									<span style="font-size: 11px; color: #64748b; display: block; font-weight: 400; margin-top: 2px;">
										<?php printf( esc_html__( 'Order #%s', 'custom-woo-dashboard' ), esc_html( $invoice->order_id ) ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td data-title="<?php esc_attr_e('Source', 'custom-woo-dashboard'); ?>" style="white-space: nowrap;">
								<?php if (CWD_V2_Invoices::SOURCE_ODOO === $invoice->source) : ?>
									<span style="background: #ede9fe; color: #5b21b6; font-size: 11px; font-weight: 600; padding: 2px 7px; border-radius: 4px; border: 1px solid #ddd6fe;">Odoo</span>
								<?php else : ?>
									<span style="background: #f1f5f9; color: #475569; font-size: 11px; font-weight: 600; padding: 2px 7px; border-radius: 4px; border: 1px solid #e2e8f0;">Website</span>
								<?php endif; ?>
							</td>
							<td data-title="<?php esc_attr_e('Date', 'custom-woo-dashboard'); ?>" style="color: #64748b; white-space: nowrap;">
								<?php echo esc_html(date_i18n(wc_date_format(), strtotime($invoice->invoice_date))); ?>
							</td>
							<td data-title="<?php esc_attr_e('Due Date', 'custom-woo-dashboard'); ?>" style="color: #64748b; white-space: nowrap;">
								<?php echo esc_html(date_i18n(wc_date_format(), strtotime($invoice->due_date))); ?>
							</td>
							<td class="td-num" data-title="<?php esc_attr_e('Outstanding', 'custom-woo-dashboard'); ?>" style="font-weight: 600; color: #0f172a; white-space: nowrap;">
								<?php echo wp_kses_post(wc_price($is_written_off ? 0 : max(0, (float) $invoice->amount_total - (float) $invoice->amount_paid))); ?>
							</td>
							<td data-title="<?php esc_attr_e('Status', 'woocommerce'); ?>" style="text-align: center; white-space: nowrap;">
								<span class="cwd-v2-status-badge <?php echo $status_class; ?>"><?php echo esc_html($status_label); ?></span>
							</td>
							<td data-title="<?php esc_attr_e('Actions', 'woocommerce'); ?>" style="text-align: right; white-space: nowrap;">
								<a href="<?php echo $view_url; ?>" class="cwd-v2-btn-view"><?php esc_html_e('View', 'woocommerce'); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php else : ?>
		<div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">
			<?php if ('open' === $view) : ?>
				<?php esc_html_e('You have no open invoices.', 'custom-woo-dashboard'); ?>
			<?php else : ?>
				<?php esc_html_e('No invoices found.', 'custom-woo-dashboard'); ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>