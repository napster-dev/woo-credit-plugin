<?php
/**
 * Invoices Endpoint Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// Fetch all orders for the user as a stand-in for invoices
$args = array(
	'customer_id' => $user_id,
	'limit' => 20,
);
$orders = wc_get_orders( $args );

?>
<div class="cwd-v2-invoices-dashboard">
	<h3><?php _e( 'Invoices', 'custom-woo-dashboard' ); ?></h3>
	<p><?php _e( 'View and download invoices for your past orders. These are synced from your accounting system.', 'custom-woo-dashboard' ); ?></p>

	<?php if ( ! empty( $orders ) ) : ?>
		<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
			<thead>
				<tr>
					<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-number"><span class="nobr"><?php _e( 'Invoice / Order', 'woocommerce' ); ?></span></th>
					<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-date"><span class="nobr"><?php _e( 'Date', 'woocommerce' ); ?></span></th>
					<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-status"><span class="nobr"><?php _e( 'Status', 'woocommerce' ); ?></span></th>
					<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-total"><span class="nobr"><?php _e( 'Total', 'woocommerce' ); ?></span></th>
					<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-actions"><span class="nobr">&nbsp;</span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $orders as $order ) : ?>
					<tr class="woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr( $order->get_status() ); ?> order">
						<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-number" data-title="<?php esc_attr_e( 'Invoice / Order', 'woocommerce' ); ?>">
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
							<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" class="woocommerce-button button view"><?php _e( 'View Details', 'woocommerce' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">
			<?php esc_html_e( 'No invoices found.', 'custom-woo-dashboard' ); ?>
		</div>
	<?php endif; ?>
</div>
