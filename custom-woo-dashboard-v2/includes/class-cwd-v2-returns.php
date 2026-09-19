<?php

/**
 * Customer return requests and Odoo return synchronization.
 */

if (! defined('ABSPATH')) {
    exit;
}

class CWD_V2_Returns
{

    const STATUS_REQUESTED = 'requested';
    const STATUS_REVIEW = 'under_review';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_RETURNED = 'returned';
    const STATUS_REFUND_PROCESSING = 'refund_processing';
    const STATUS_REFUNDED = 'refunded';

    public static function init()
    {
        add_action('template_redirect', array(__CLASS__, 'handle_request'));
        add_action('woocommerce_order_refunded', array(__CLASS__, 'mark_refunded'), 20, 2);
    }

    public static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'cwd_v2_returns';
    }

    public static function get_for_user($user_id, $limit = 50)
    {
        self::sync_odoo_returns($user_id);
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE user_id = %d ORDER BY created_at DESC LIMIT %d', (int) $user_id, (int) $limit));
    }

    public static function get_eligible_orders($user_id, $limit = 50)
    {
        return wc_get_orders(array(
            'customer_id' => (int) $user_id,
            'limit' => (int) $limit,
            'status' => array('wc-processing', 'wc-completed'),
            'orderby' => 'date',
            'order' => 'DESC',
        ));
    }

    public static function get_order_for_user($order_id, $user_id)
    {
        $order = wc_get_order(absint($order_id));
        return $order && (int) $order->get_user_id() === (int) $user_id ? $order : false;
    }

    public static function handle_request()
    {
        if (empty($_POST['cwd_v2_submit_return']) || ! is_user_logged_in()) {
            return;
        }

        $nonce = isset($_POST['cwd_v2_return_nonce']) ? sanitize_text_field(wp_unslash($_POST['cwd_v2_return_nonce'])) : '';
        if (! wp_verify_nonce($nonce, 'cwd_v2_submit_return')) {
            wc_add_notice(__('Security check failed. Please try again.', 'custom-woo-dashboard'), 'error');
            return;
        }

        $user_id = get_current_user_id();
        $order = self::get_order_for_user(isset($_POST['cwd_v2_return_order_id']) ? absint($_POST['cwd_v2_return_order_id']) : 0, $user_id);
        $reason = isset($_POST['cwd_v2_return_reason']) ? sanitize_textarea_field(wp_unslash($_POST['cwd_v2_return_reason'])) : '';
        $item_ids = isset($_POST['cwd_v2_return_items']) && is_array($_POST['cwd_v2_return_items']) ? array_map('absint', wp_unslash($_POST['cwd_v2_return_items'])) : array();

        if (! $order || '' === $reason || empty($item_ids)) {
            wc_add_notice(__('Select an eligible order, at least one product, and provide a reason.', 'custom-woo-dashboard'), 'error');
            return;
        }

        $items = array();
        foreach ($item_ids as $item_id) {
            $item = $order->get_item($item_id);
            if (! $item || $item->get_quantity() < 1) {
                continue;
            }
            $items[] = array(
                'item_id' => $item_id,
                'name' => $item->get_name(),
                'quantity' => (int) $item->get_quantity(),
                'total' => (float) $item->get_total(),
            );
        }
        if (empty($items)) {
            wc_add_notice(__('The selected products are not valid for this order.', 'custom-woo-dashboard'), 'error');
            return;
        }

        global $wpdb;
        $now = current_time('mysql');
        $inserted = $wpdb->insert(self::table_name(), array(
            'user_id' => $user_id,
            'order_id' => $order->get_id(),
            'invoice_id' => self::invoice_id_for_order($order->get_id()),
            'status' => self::STATUS_REQUESTED,
            'reason' => $reason,
            'items' => wp_json_encode($items),
            'refund_status' => 'pending',
            'refund_amount' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ), array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s'));

        if (false === $inserted) {
            wc_add_notice(__('Your return request could not be saved. Please try again.', 'custom-woo-dashboard'), 'error');
            return;
        }
        $order->add_order_note(sprintf(__('Customer return request #%d submitted.', 'custom-woo-dashboard'), $wpdb->insert_id));
        do_action('cwd_v2_return_requested', (int) $wpdb->insert_id, $order, $items, $reason);
        wc_add_notice(__('Your return request has been submitted and is under review.', 'custom-woo-dashboard'), 'success');
        wp_safe_redirect(wc_get_endpoint_url('returns', '', wc_get_page_permalink('myaccount')));
        exit;
    }

    public static function invoice_id_for_order($order_id)
    {
        if (class_exists('CWD_V2_Invoices')) {
            $invoices = CWD_V2_Invoices::get_invoices_for_order($order_id);
            return $invoices ? (int) $invoices->id : 0;
        }
        return 0;
    }

    public static function mark_refunded($order_id, $refund_id)
    {
        global $wpdb;
        $refund = wc_get_order($refund_id);
        if (! $refund) {
            return;
        }
        $wpdb->update(self::table_name(), array(
            'status' => self::STATUS_REFUNDED,
            'refund_status' => 'processed',
            'refund_amount' => abs((float) $refund->get_total()),
            'updated_at' => current_time('mysql'),
        ), array('order_id' => (int) $order_id, 'status' => self::STATUS_REFUND_PROCESSING), array('%s', '%s', '%f', '%s'), array('%d', '%s'));
    }

    public static function sync_odoo_returns($user_id = 0)
    {
        $rows = apply_filters('cwd_v2_odoo_returns', array(), (int) $user_id);
        if (! is_array($rows)) {
            return 0;
        }
        global $wpdb;
        $count = 0;
        foreach ($rows as $row) {
            $odoo_id = sanitize_text_field($row['odoo_return_id'] ?? '');
            if ('' === $odoo_id || (int) ($row['user_id'] ?? 0) !== (int) $user_id) {
                continue;
            }
            $status = sanitize_key($row['status'] ?? self::STATUS_REVIEW);
            $allowed = array(self::STATUS_REQUESTED, self::STATUS_REVIEW, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_RETURNED, self::STATUS_REFUND_PROCESSING, self::STATUS_REFUNDED);
            if (! in_array($status, $allowed, true)) {
                continue;
            }
            $data = array('status' => $status, 'refund_status' => sanitize_key($row['refund_status'] ?? 'pending'), 'refund_amount' => (float) ($row['refund_amount'] ?? 0), 'odoo_return_id' => $odoo_id, 'updated_at' => current_time('mysql'));
            $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table_name() . ' WHERE odoo_return_id = %s AND user_id = %d', $odoo_id, (int) $user_id));
            if ($existing) {
                $wpdb->update(self::table_name(), $data, array('id' => (int) $existing), array('%s', '%s', '%f', '%s'), array('%d'));
                $count++;
                continue;
            }

            $order_id = absint($row['order_id'] ?? 0);
            if (! self::get_order_for_user($order_id, $user_id)) {
                continue;
            }
            $inserted = $wpdb->insert(self::table_name(), array(
                'user_id' => (int) $user_id,
                'order_id' => $order_id,
                'invoice_id' => absint($row['invoice_id'] ?? 0),
                'status' => $status,
                'reason' => sanitize_textarea_field($row['reason'] ?? __('Imported from Odoo', 'custom-woo-dashboard')),
                'items' => wp_json_encode(is_array($row['items'] ?? null) ? $row['items'] : array()),
                'refund_status' => $data['refund_status'],
                'refund_amount' => $data['refund_amount'],
                'odoo_return_id' => $odoo_id,
                'created_at' => current_time('mysql'),
                'updated_at' => $data['updated_at'],
            ), array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s'));
            if (false !== $inserted) {
                $count++;
            }
        }
        return $count;
    }
}
