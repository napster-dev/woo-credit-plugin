<?php

/**
 * Single Invoice View Template
 */

if (! defined('ABSPATH')) {
	exit;
}

global $wp;

$current_user = wp_get_current_user();
$user_id      = (int) $current_user->ID;
$invoice_id   = isset($wp->query_vars['view-invoice']) ? absint($wp->query_vars['view-invoice']) : 0;
$invoice      = $invoice_id ? CWD_V2_Invoices::get_invoice($invoice_id, $user_id) : null;

wc_print_notices();

if (! $invoice) {
?>
	<div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">
		<?php esc_html_e('Invoice not found.', 'custom-woo-dashboard'); ?>
	</div>
	<a href="<?php echo esc_url(wc_get_endpoint_url('invoices')); ?>" class="cwd-v2-back-button">&larr; <?php esc_html_e('Back to Invoices', 'custom-woo-dashboard'); ?></a>
<?php
	return;
}

$order = wc_get_order($invoice->order_id);

$is_written_off = in_array(strtolower((string) $invoice->status), array('written_off', 'marked_off'), true);
$is_overdue   = ! $is_written_off && CWD_V2_Invoices::is_overdue($invoice);
$status_label = $is_written_off ? __('MARKED OFF', 'custom-woo-dashboard') : ucfirst($invoice->status);
$status_class = $is_written_off ? 'cwd-v2-status-written_off' : ('cwd-v2-status-' . esc_attr($invoice->status));
if ($is_overdue) {
	$status_label = __('Overdue', 'custom-woo-dashboard');
	$status_class = 'cwd-v2-status-overdue';
}

$amount_due  = $is_written_off ? 0 : max(0, (float) $invoice->amount_total - (float) $invoice->amount_paid);
$roles       = (array) $current_user->roles;
$can_pay     = ! $is_written_off && (CWD_V2_Invoices::STATUS_UNPAID === $invoice->status) && in_array('credit_account', $roles, true);
$document_url = apply_filters('cwd_v2_invoice_document_url', $invoice->document_url ?? '', $invoice);
if (empty($document_url) && $order) {
	$document_url = $order->get_meta('_odoo_invoice_url');
}
if (empty($document_url) && ! empty($invoice->odoo_invoice_id)) {
	$odoo_base = defined('CWD_ODOO_BASE_URL') ? CWD_ODOO_BASE_URL : get_option('cwd_odoo_base_url', '');
	$odoo_base = apply_filters('cwd_v2_odoo_base_url', $odoo_base);
	if (! empty($odoo_base)) {
		$document_url = rtrim($odoo_base, '/') . '/my/invoices/' . rawurlencode($invoice->odoo_invoice_id) . '?report_type=pdf&download=true';
	}
}
?>
<div class="cwd-v2-invoice-view" id="cwd-v2-invoice-print-area">
	<div class="cwd-v2-section-heading cwd-v2-no-print">
		<div>
			<h3><?php printf(esc_html__('Invoice %s', 'custom-woo-dashboard'), esc_html($invoice->invoice_number)); ?></h3>
			<p><span class="cwd-v2-status-badge <?php echo $status_class; ?>"><?php echo esc_html($status_label); ?></span></p>
		</div>
		<nav class="cwd-v2-section-links" aria-label="<?php esc_attr_e('Invoice actions', 'custom-woo-dashboard'); ?>">
			<?php if ($document_url) : ?>
				<a href="<?php echo esc_url($document_url); ?>" class="cwd-v2-button cwd-v2-button-primary" target="_blank" rel="noopener">
					<span class="dashicons dashicons-pdf" style="vertical-align: middle; margin-right: 4px;"></span><?php esc_html_e('Download Odoo Invoice (PDF)', 'custom-woo-dashboard'); ?>
				</a>
			<?php endif; ?>
		</nav>
	</div>

	<div class="cwd-v2-invoice-meta">
		<p><strong><?php esc_html_e('Invoice Date:', 'custom-woo-dashboard'); ?></strong> <?php echo esc_html(date_i18n(wc_date_format(), strtotime($invoice->invoice_date))); ?></p>
		<p><strong><?php esc_html_e('Due Date:', 'custom-woo-dashboard'); ?></strong> <?php echo esc_html(date_i18n(wc_date_format(), strtotime($invoice->due_date))); ?></p>
		<p>
			<strong><?php esc_html_e('Source:', 'custom-woo-dashboard'); ?></strong> 
			<?php if (CWD_V2_Invoices::SOURCE_ODOO === $invoice->source) : ?>
				<span style="background: #ede9fe; color: #5b21b6; font-size: 11px; font-weight: 600; padding: 2px 7px; border-radius: 4px; border: 1px solid #ddd6fe;">Odoo ERP</span>
				<?php if (! empty($invoice->odoo_invoice_id)) : ?>
					<span style="font-size: 12px; color: #64748b; margin-left: 4px;">(#<?php echo esc_html($invoice->odoo_invoice_id); ?>)</span>
				<?php endif; ?>
			<?php else : ?>
				<span>Website</span>
			<?php endif; ?>
		</p>
		<?php if ($order) : ?>
			<p><strong><?php esc_html_e('Linked Order:', 'custom-woo-dashboard'); ?></strong> <a href="<?php echo esc_url($order->get_view_order_url()); ?>" style="font-weight: 600; color: #0284c7;">#<?php echo esc_html($order->get_order_number()); ?></a></p>
		<?php endif; ?>
		<p><strong><?php esc_html_e('Billed To:', 'custom-woo-dashboard'); ?></strong> <?php echo esc_html($current_user->display_name); ?> (<?php echo esc_html($current_user->user_email); ?>)</p>
	</div>

	<?php if ($order) : ?>
		<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table cwd-v2-invoice-items">
			<thead>
				<tr>
					<th><?php esc_html_e('Item', 'woocommerce'); ?></th>
					<th><?php esc_html_e('Quantity', 'woocommerce'); ?></th>
					<th><?php esc_html_e('Line Total', 'woocommerce'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($order->get_items() as $item) : ?>
					<tr>
						<td data-title="<?php esc_attr_e('Item', 'woocommerce'); ?>"><?php echo esc_html($item->get_name()); ?></td>
						<td data-title="<?php esc_attr_e('Quantity', 'woocommerce'); ?>"><?php echo esc_html($item->get_quantity()); ?></td>
						<td data-title="<?php esc_attr_e('Line Total', 'woocommerce'); ?>"><?php echo wp_kses_post(wc_price($item->get_total())); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th colspan="2"><?php esc_html_e('Total', 'woocommerce'); ?></th>
					<td><?php echo wp_kses_post(wc_price($invoice->amount_total)); ?></td>
				</tr>
				<tr>
					<th colspan="2"><?php esc_html_e('Amount Paid', 'custom-woo-dashboard'); ?></th>
					<td><?php echo wp_kses_post(wc_price($invoice->amount_paid)); ?></td>
				</tr>
				<tr>
					<th colspan="2"><?php esc_html_e('Amount Due', 'custom-woo-dashboard'); ?></th>
					<td><?php echo wp_kses_post(wc_price($amount_due)); ?></td>
				</tr>
			</tfoot>
		</table>
	<?php endif; ?>

	<?php if ($can_pay && $amount_due > 0) : ?>
		<div class="cwd-v2-pay-balance cwd-v2-no-print">
			<h3><?php esc_html_e('Pay This Invoice', 'custom-woo-dashboard'); ?></h3>
			<form method="post" action="">
				<?php wp_nonce_field('cwd_v2_pay_credit_action', 'cwd_v2_pay_credit_nonce'); ?>
				<input type="hidden" name="cwd_v2_pay_invoice_id" value="<?php echo esc_attr($invoice->id); ?>" />
				<p>
					<label for="cwd_v2_pay_amount"><?php esc_html_e('Amount to Pay', 'custom-woo-dashboard'); ?></label><br />
					<input type="number" step="0.01" name="cwd_v2_pay_amount" id="cwd_v2_pay_amount" value="<?php echo esc_attr($amount_due); ?>" readonly />
				</p>
				<button type="submit" name="cwd_v2_pay_credit_balance" class="button button-primary"><?php esc_html_e('Proceed to Checkout', 'custom-woo-dashboard'); ?></button>
			</form>
		</div>
	<?php endif; ?>

	<a href="<?php echo esc_url(wc_get_endpoint_url('invoices')); ?>" class="cwd-v2-back-button cwd-v2-no-print">&larr; <?php esc_html_e('Back to Invoices', 'custom-woo-dashboard'); ?></a>
</div>