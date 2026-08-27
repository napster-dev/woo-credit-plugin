<?php
/**
 * Credit Dashboard Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

$credit_limit = (float) get_user_meta( $user_id, '_credit_limit', true );
$credit_balance = (float) get_user_meta( $user_id, '_credit_balance', true ); // Amount owed
$due_date = get_user_meta( $user_id, '_credit_due_date', true );
$available_credit = max( 0, $credit_limit - $credit_balance );

// Fetch credit purchase history (orders made with cwd_v2_credit_account gateway)
$args = array(
	'customer_id' => $user_id,
	'payment_method' => 'cwd_v2_credit_account',
	'limit' => 10,
);
$credit_orders = wc_get_orders( $args );

// Print notices
wc_print_notices();
?>

<div class="cwd-v2-credit-dashboard">
	<h3><?php _e( 'Credit Overview', 'custom-woo-dashboard' ); ?></h3>

	<div class="cwd-v2-credit-summary" style="display: flex; gap: 20px; margin-bottom: 30px;">
		<div class="cwd-v2-summary-box" style="background: #f9f9f9; padding: 20px; border-radius: 8px; flex: 1;">
			<h4><?php _e( 'Credit Limit', 'custom-woo-dashboard' ); ?></h4>
			<p style="font-size: 24px; font-weight: bold;"><?php echo wc_price( $credit_limit ); ?></p>
		</div>
		<div class="cwd-v2-summary-box" style="background: #f9f9f9; padding: 20px; border-radius: 8px; flex: 1;">
			<h4><?php _e( 'Current Balance (Owed)', 'custom-woo-dashboard' ); ?></h4>
			<p style="font-size: 24px; font-weight: bold; color: #d9534f;"><?php echo wc_price( $credit_balance ); ?></p>
		</div>
		<div class="cwd-v2-summary-box" style="background: #f9f9f9; padding: 20px; border-radius: 8px; flex: 1;">
			<h4><?php _e( 'Available Credit', 'custom-woo-dashboard' ); ?></h4>
			<p style="font-size: 24px; font-weight: bold; color: #5cb85c;"><?php echo wc_price( $available_credit ); ?></p>
		</div>
		<div class="cwd-v2-summary-box" style="background: #f9f9f9; padding: 20px; border-radius: 8px; flex: 1;">
			<h4><?php _e( 'Next Due Date', 'custom-woo-dashboard' ); ?></h4>
			<p style="font-size: 20px; font-weight: bold;"><?php echo $due_date ? esc_html( $due_date ) : __( 'N/A', 'custom-woo-dashboard' ); ?></p>
		</div>
	</div>

	<!-- Pay Off Balance -->
	<div class="cwd-v2-pay-balance" style="margin-bottom: 40px; padding: 20px; border: 1px solid #eaeaea; border-radius: 8px;">
		<h3><?php _e( 'Pay Balance Before Due Date', 'custom-woo-dashboard' ); ?></h3>
		<form method="post" action="">
			<?php wp_nonce_field( 'cwd_v2_pay_credit_action', 'cwd_v2_pay_credit_nonce' ); ?>
			<p>
				<label for="cwd_v2_pay_amount"><?php _e( 'Amount to Pay', 'custom-woo-dashboard' ); ?></label><br/>
				<input type="number" step="0.01" min="0.01" max="<?php echo esc_attr( $credit_balance ); ?>" name="cwd_v2_pay_amount" id="cwd_v2_pay_amount" value="<?php echo esc_attr( $credit_balance ); ?>" required />
			</p>
			<button type="submit" name="cwd_v2_pay_credit_balance" class="button button-primary"><?php _e( 'Proceed to Checkout', 'custom-woo-dashboard' ); ?></button>
		</form>
	</div>

	<!-- Request Limit Increase -->
	<div class="cwd-v2-request-increase" style="margin-bottom: 40px; padding: 20px; border: 1px solid #eaeaea; border-radius: 8px;">
		<h3><?php _e( 'Request Credit Limit Increase', 'custom-woo-dashboard' ); ?></h3>
		<form method="post" action="">
			<?php wp_nonce_field( 'cwd_v2_request_increase_action', 'cwd_v2_request_increase_nonce' ); ?>
			<p>
				<label for="cwd_v2_requested_amount"><?php _e( 'Requested New Limit', 'custom-woo-dashboard' ); ?></label><br/>
				<input type="text" name="cwd_v2_requested_amount" id="cwd_v2_requested_amount" required />
			</p>
			<p>
				<label for="cwd_v2_request_reason"><?php _e( 'Reason for Request', 'custom-woo-dashboard' ); ?></label><br/>
				<textarea name="cwd_v2_request_reason" id="cwd_v2_request_reason" rows="3" style="width: 100%;" required></textarea>
			</p>
			<button type="submit" name="cwd_v2_request_increase" class="button"><?php _e( 'Submit Request', 'custom-woo-dashboard' ); ?></button>
		</form>
	</div>

	<!-- Credit History -->
	<div class="cwd-v2-credit-history">
		<h3><?php _e( 'Credit Purchase History', 'custom-woo-dashboard' ); ?></h3>
		<?php if ( ! empty( $credit_orders ) ) : ?>
			<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
				<thead>
					<tr>
						<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-number"><span class="nobr"><?php _e( 'Order', 'woocommerce' ); ?></span></th>
						<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-date"><span class="nobr"><?php _e( 'Date', 'woocommerce' ); ?></span></th>
						<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-status"><span class="nobr"><?php _e( 'Status', 'woocommerce' ); ?></span></th>
						<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-total"><span class="nobr"><?php _e( 'Total', 'woocommerce' ); ?></span></th>
						<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-actions"><span class="nobr">&nbsp;</span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $credit_orders as $order ) : ?>
						<tr class="woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr( $order->get_status() ); ?> order">
							<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-number" data-title="<?php esc_attr_e( 'Order', 'woocommerce' ); ?>">
								<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">
									#<?php echo esc_html( $order->get_order_number() ); ?>
								</a>
							</td>
							<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-date" data-title="<?php esc_attr_e( 'Date', 'woocommerce' ); ?>">
								<time datetime="<?php echo esc_attr( $order->get_date_created()->date( 'c' ) ); ?>"><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></time>
							</td>
							<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-status" data-title="<?php esc_attr_e( 'Status', 'woocommerce' ); ?>">
								<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
							</td>
							<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-total" data-title="<?php esc_attr_e( 'Total', 'woocommerce' ); ?>">
								<?php echo wp_kses_post( sprintf( _x( '%1$s for %2$s item(s)', 'Order total formatted with items', 'woocommerce' ), $order->get_formatted_order_total(), $order->get_item_count() ) ); ?>
							</td>
							<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-actions" data-title="<?php esc_attr_e( 'Actions', 'woocommerce' ); ?>">
								<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" class="woocommerce-button button view"><?php _e( 'View', 'woocommerce' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">
				<a class="woocommerce-Button button" href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>"><?php esc_html_e( 'Browse products', 'woocommerce' ); ?></a>
				<?php esc_html_e( 'No credit purchases have been made yet.', 'custom-woo-dashboard' ); ?>
			</div>
		<?php endif; ?>
	</div>
</div>
