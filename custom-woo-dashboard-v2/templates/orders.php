<?php

/**
 * Customer order views: live orders, back orders, and order history.
 */
if (! defined('ABSPATH')) {
    exit;
}

$current_user = wp_get_current_user();
$user_id      = (int) $current_user->ID;
$view         = 'all-orders';

global $wp;
if (isset($wp->query_vars['live-orders']) || (isset($_GET['view']) && 'live-orders' === $_GET['view'])) {
    $view = 'live-orders';
} elseif (isset($wp->query_vars['back-orders']) || (isset($_GET['view']) && 'back-orders' === $_GET['view'])) {
    $view = 'back-orders';
} elseif (isset($wp->query_vars['order-history']) || (isset($_GET['view']) && 'order-history' === $_GET['view'])) {
    $view = 'order-history';
} elseif (isset($wp->query_vars['orders'])) {
    $view = 'all-orders';
}

$orders = wc_get_orders(
    array(
        'customer_id' => $user_id,
        'limit'       => 50,
        'orderby'     => 'date',
        'order'       => 'DESC',
    )
);

switch ($view) {
    case 'live-orders':
        $title       = __('Live Orders', 'custom-woo-dashboard');
        $description = __('Orders currently being prepared or awaiting payment.', 'custom-woo-dashboard');
        break;
    case 'back-orders':
        $title       = __('Back Orders', 'custom-woo-dashboard');
        $description = __('Orders containing products that WooCommerce currently identifies as backordered.', 'custom-woo-dashboard');
        break;
    case 'order-history':
        $title       = __('Order History', 'custom-woo-dashboard');
        $description = __('Your completed and previously placed orders.', 'custom-woo-dashboard');
        break;
    case 'all-orders':
    default:
        $title       = __('Manage Orders', 'custom-woo-dashboard');
        $description = __('View and manage all your orders, track active shipments and history.', 'custom-woo-dashboard');
        break;
}
$matching_orders = array();

foreach ($orders as $order) {
    $status = $order->get_status();
    $include = false;

    if ('all-orders' === $view) {
        $include = true;
    } elseif ('live-orders' === $view) {
        $include = in_array($status, array('pending', 'processing', 'on-hold'), true);
    } elseif ('order-history' === $view) {
        $include = in_array($status, array('completed', 'cancelled', 'refunded', 'failed'), true);
    } else {
        $include = false;
        if (in_array($status, array('pending', 'processing', 'on-hold'), true)) {
            if ($order->get_meta('_has_backorders') === 'yes') {
                $include = true;
            } else {
                foreach ($order->get_items() as $item) {
                    if ($item->get_meta('_is_backorder') === 'yes') {
                        $include = true;
                        break;
                    }
                    $product = $item->get_product();
                    if ($product && $product->is_on_backorder($item->get_quantity())) {
                        $include = true;
                        break;
                    }
                }
            }
        }
    }

    if ($include) {
        $matching_orders[] = $order;
    }
}
?>
<div class="cwd-v2-orders-dashboard">
    <div class="cwd-v2-section-heading">
        <div>
            <h3><?php echo esc_html($title); ?></h3>
            <p><?php echo esc_html($description); ?></p>
        </div>
        <nav class="cwd-v2-section-links" aria-label="<?php esc_attr_e('Order views', 'custom-woo-dashboard'); ?>">
            <a href="<?php echo esc_url(wc_get_endpoint_url('orders')); ?>" class="<?php echo 'all-orders' === $view ? 'cwd-v2-active-link' : ''; ?>"><?php esc_html_e('All Orders', 'custom-woo-dashboard'); ?></a>
            <a href="<?php echo esc_url(wc_get_endpoint_url('live-orders')); ?>" class="<?php echo 'live-orders' === $view ? 'cwd-v2-active-link' : ''; ?>"><?php esc_html_e('Live Orders', 'custom-woo-dashboard'); ?></a>
            <a href="<?php echo esc_url(wc_get_endpoint_url('back-orders')); ?>" class="<?php echo 'back-orders' === $view ? 'cwd-v2-active-link' : ''; ?>"><?php esc_html_e('Back Orders', 'custom-woo-dashboard'); ?></a>
            <a href="<?php echo esc_url(wc_get_endpoint_url('order-history')); ?>" class="<?php echo 'order-history' === $view ? 'cwd-v2-active-link' : ''; ?>"><?php esc_html_e('Order History', 'custom-woo-dashboard'); ?></a>
        </nav>
    </div>

    <?php if (! empty($matching_orders)) : ?>
        <table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Order', 'woocommerce'); ?></th>
                    <th><?php esc_html_e('Date', 'woocommerce'); ?></th>
                    <th><?php esc_html_e('Status', 'woocommerce'); ?></th>
                    <th><?php esc_html_e('Total', 'woocommerce'); ?></th>
                    <th><?php esc_html_e('Actions', 'woocommerce'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($matching_orders as $order) : ?>
                    <?php $created = $order->get_date_created(); ?>
                    <tr class="woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr($order->get_status()); ?> order">
                        <td data-title="<?php esc_attr_e('Order', 'woocommerce'); ?>"><a href="<?php echo esc_url($order->get_view_order_url()); ?>">#<?php echo esc_html($order->get_order_number()); ?></a></td>
                        <td data-title="<?php esc_attr_e('Date', 'woocommerce'); ?>">
                            <?php if ($created) : ?><time datetime="<?php echo esc_attr($created->date('c')); ?>"><?php echo esc_html(wc_format_datetime($created)); ?></time><?php endif; ?>
                        </td>
                        <td data-title="<?php esc_attr_e('Status', 'woocommerce'); ?>"><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
                        <td data-title="<?php esc_attr_e('Total', 'woocommerce'); ?>"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
                        <td data-title="<?php esc_attr_e('Actions', 'woocommerce'); ?>"><a class="woocommerce-button button view" href="<?php echo esc_url($order->get_view_order_url()); ?>"><?php esc_html_e('View Details', 'woocommerce'); ?></a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <div class="woocommerce-message woocommerce-message--info woocommerce-info"><?php esc_html_e('No orders found in this view.', 'custom-woo-dashboard'); ?></div>
    <?php endif; ?>
</div>