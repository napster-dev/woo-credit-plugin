<?php

/**
 * Customer order tracking view.
 */
if (! defined('ABSPATH')) {
    exit;
}

$current_user = wp_get_current_user();
$orders       = wc_get_orders(
    array(
        'customer_id' => (int) $current_user->ID,
        'limit'       => 50,
        'orderby'     => 'date',
        'order'       => 'DESC',
    )
);
?>
<div class="cwd-v2-track-dashboard">
    <div class="cwd-v2-section-heading">
        <div>
            <h3><?php esc_html_e('Track Order', 'custom-woo-dashboard'); ?></h3>
            <p><?php esc_html_e('Check delivery status and available shipment tracking for your orders.', 'custom-woo-dashboard'); ?></p>
        </div>
    </div>

    <?php if (! empty($orders)) : ?>
        <div class="cwd-v2-track-list">
            <?php foreach ($orders as $order) : ?>
                <?php
                $tracking_items = $order->get_meta('_wc_shipment_tracking_items', true);
                $tracking_items = is_array($tracking_items) ? $tracking_items : array();
                ?>
                <article class="cwd-v2-track-item">
                    <div>
                        <h4><a href="<?php echo esc_url($order->get_view_order_url()); ?>">#<?php echo esc_html($order->get_order_number()); ?></a></h4>
                        <p><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></p>
                    </div>
                    <div class="cwd-v2-track-details">
                        <?php if (! empty($tracking_items)) : ?>
                            <?php foreach ($tracking_items as $tracking) : ?>
                                <?php
                                $tracking_number = isset($tracking['tracking_number']) ? sanitize_text_field($tracking['tracking_number']) : '';
                                $tracking_provider = isset($tracking['tracking_provider']) ? sanitize_text_field($tracking['tracking_provider']) : '';
                                $tracking_link = isset($tracking['custom_tracking_link']) ? esc_url($tracking['custom_tracking_link']) : '';
                                ?>
                                <p>
                                    <?php echo esc_html($tracking_provider ? $tracking_provider . ': ' : ''); ?>
                                    <?php if ($tracking_link && $tracking_number) : ?><a href="<?php echo esc_url($tracking_link); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($tracking_number); ?></a><?php else : ?><?php echo esc_html($tracking_number); ?><?php endif; ?>
                                </p>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <p><?php esc_html_e('Tracking will appear here when shipment information is added.', 'custom-woo-dashboard'); ?></p>
                        <?php endif; ?>
                        <a class="woocommerce-button button view" href="<?php echo esc_url($order->get_view_order_url()); ?>"><?php esc_html_e('View Order', 'woocommerce'); ?></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else : ?>
        <div class="woocommerce-message woocommerce-message--info woocommerce-info"><?php esc_html_e('No orders are available to track.', 'custom-woo-dashboard'); ?></div>
    <?php endif; ?>
</div>