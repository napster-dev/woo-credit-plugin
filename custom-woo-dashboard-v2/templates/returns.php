<?php
if (! defined('ABSPATH')) {
	exit;
}
$user_id = get_current_user_id();
$orders = CWD_V2_Returns::get_eligible_orders($user_id);
$returns = CWD_V2_Returns::get_for_user($user_id);
$labels = array('requested' => 'Return Requested', 'under_review' => 'Under Review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'returned' => 'Returned', 'refund_processing' => 'Refund Processing', 'refunded' => 'Refunded');
wc_print_notices();
?>
<div class="cwd-v2-returns-dashboard">
	<div class="cwd-v2-section-heading">
		<div>
			<h3><?php esc_html_e('Returns & Refunds', 'custom-woo-dashboard'); ?></h3>
			<p><?php esc_html_e('Request returns and follow their approval and refund status.', 'custom-woo-dashboard'); ?></p>
		</div>
	</div>

	<?php if ($orders) : ?><form method="post" class="cwd-v2-return-form"><?php wp_nonce_field('cwd_v2_submit_return', 'cwd_v2_return_nonce'); ?><p><label for="cwd_v2_return_order_id"><?php esc_html_e('Order', 'woocommerce'); ?></label><select name="cwd_v2_return_order_id" id="cwd_v2_return_order_id" required>
					<option value=""><?php esc_html_e('Select an order', 'custom-woo-dashboard'); ?></option><?php foreach ($orders as $order) : ?><option value="<?php echo esc_attr($order->get_id()); ?>">#<?php echo esc_html($order->get_order_number()); ?> - <?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></option><?php endforeach; ?>
				</select></p>
			<fieldset>
				<legend><?php esc_html_e('Products', 'custom-woo-dashboard'); ?></legend><?php foreach ($orders as $order) : foreach ($order->get_items() as $item) : ?><label><input type="checkbox" name="cwd_v2_return_items[]" value="<?php echo esc_attr($item->get_id()); ?>" /> <?php echo esc_html($item->get_name()); ?> (<?php echo esc_html($item->get_quantity()); ?>)</label><br /><?php endforeach;
																																																																																																	endforeach; ?>
			</fieldset>
			<p><label for="cwd_v2_return_reason"><?php esc_html_e('Reason', 'custom-woo-dashboard'); ?></label><textarea name="cwd_v2_return_reason" id="cwd_v2_return_reason" required></textarea></p><button type="submit" name="cwd_v2_submit_return" class="button button-primary"><?php esc_html_e('Submit Return Request', 'custom-woo-dashboard'); ?></button>
		</form><?php else : ?><p><?php esc_html_e('No eligible orders found for returns.', 'custom-woo-dashboard'); ?></p><?php endif; ?>
	<h4><?php esc_html_e('Return History', 'custom-woo-dashboard'); ?></h4>
	<?php if ($returns) : ?><table class="shop_table shop_table_responsive">
			<thead>
				<tr>
					<th><?php esc_html_e('Order', 'woocommerce'); ?></th>
					<th><?php esc_html_e('Requested', 'custom-woo-dashboard'); ?></th>
					<th><?php esc_html_e('Status', 'woocommerce'); ?></th>
					<th><?php esc_html_e('Refund', 'custom-woo-dashboard'); ?></th>
				</tr>
			</thead>
			<tbody><?php foreach ($returns as $return) : ?><tr>
						<td>#<?php echo esc_html($return->order_id); ?></td>
						<td><?php echo esc_html(date_i18n(wc_date_format(), strtotime($return->created_at))); ?></td>
						<td><?php echo esc_html($labels[$return->status] ?? ucfirst($return->status)); ?></td>
						<td><?php echo wp_kses_post(wc_price($return->refund_amount)); ?> (<?php echo esc_html(ucfirst($return->refund_status)); ?>)</td>
					</tr><?php endforeach; ?></tbody>
		</table><?php else : ?><p><?php esc_html_e('No return requests submitted yet.', 'custom-woo-dashboard'); ?></p><?php endif; ?>
</div>