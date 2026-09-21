<?php

/**
 * Credit Logic class
 */

if (! defined('ABSPATH')) {
	exit;
}

class CWD_V2_Credit_Logic
{

	public static function init()
	{
		// Handle pay balance submission
		add_action('template_redirect', array(__CLASS__, 'handle_pay_balance'));

		// Handle credit limit increase request
		add_action('template_redirect', array(__CLASS__, 'handle_credit_increase_request'));

		// Set price of credit payment product in cart
		add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'set_credit_payment_price'), 10, 1);
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_account_payment_gateways' ) );

		// Hook into order payment to decrease balance
		add_action('woocommerce_payment_complete', array(__CLASS__, 'reduce_credit_balance_on_payment'));
		add_action('woocommerce_order_status_processing', array(__CLASS__, 'reduce_credit_balance_on_payment'));
		add_action('woocommerce_order_status_completed', array(__CLASS__, 'reduce_credit_balance_on_payment'));
		add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'add_payment_item_metadata'), 10, 4);
		add_action('woocommerce_order_status_processing', array(__CLASS__, 'apply_credit_order_charge'));
		add_action('woocommerce_order_status_completed', array(__CLASS__, 'apply_credit_order_charge'));
		add_action('woocommerce_order_status_cancelled', array(__CLASS__, 'reverse_credit_order_charge'));
		add_action('woocommerce_order_status_failed', array(__CLASS__, 'reverse_credit_order_charge'));

		// Hook into refunds on credit-paid orders to decrease the outstanding balance.
		add_action('woocommerce_order_refunded', array(__CLASS__, 'handle_credit_order_refund'), 10, 2);
	}

	public static function filter_account_payment_gateways( $gateways ) {
		if ( ! WC()->cart ) {
			return $gateways;
		}
		$product_id = (int) get_option( 'cwd_v2_credit_payment_product_id' );
		$is_account_payment = false;
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( $product_id && (int) ( $cart_item['product_id'] ?? 0 ) === $product_id ) {
				$is_account_payment = true;
				break;
			}
		}
		if ( ! $is_account_payment ) {
			return $gateways;
		}
		foreach ( array( 'cod', 'bacs', 'cheque', 'cwd_v2_credit_account' ) as $gateway_id ) {
			unset( $gateways[ $gateway_id ] );
		}
		return $gateways;
	}

	public static function handle_pay_balance()
	{
		if (isset($_POST['cwd_v2_pay_credit_balance']) && isset($_POST['cwd_v2_pay_amount']) && is_user_logged_in()) {
			$roles = (array) wp_get_current_user()->roles;
			if (! in_array('credit_account', $roles, true) && ! current_user_can('manage_options')) {
				wc_add_notice(__('You are not authorized to make credit account payments.', 'custom-woo-dashboard'), 'error');
				return;
			}
			$nonce = isset($_POST['cwd_v2_pay_credit_nonce']) ? sanitize_text_field(wp_unslash($_POST['cwd_v2_pay_credit_nonce'])) : '';
			if (! wp_verify_nonce($nonce, 'cwd_v2_pay_credit_action')) {
				wc_add_notice(__('Security check failed.', 'custom-woo-dashboard'), 'error');
				return;
			}

			$amount = round((float) wc_format_decimal(wp_unslash($_POST['cwd_v2_pay_amount'])), 2);
			if ($amount <= 0) {
				wc_add_notice(__('Please enter a valid amount.', 'custom-woo-dashboard'), 'error');
				return;
			}

			$product_id = (int) get_option('cwd_v2_credit_payment_product_id');
			if (! $product_id) {
				wc_add_notice(__('Credit payment product not configured.', 'custom-woo-dashboard'), 'error');
				return;
			}

			$cart_item_data = array('cwd_v2_custom_price' => $amount);

			// If this payment was triggered from a specific invoice's "Pay This Invoice"
			// form, carry the invoice ID through so it can be marked paid once the
			// resulting order completes.
			if (! empty($_POST['cwd_v2_pay_invoice_id'])) {
				$invoice_id = absint($_POST['cwd_v2_pay_invoice_id']);
				$invoice    = CWD_V2_Invoices::get_invoice($invoice_id, get_current_user_id());
				$amount_due = $invoice ? max(0, (float) $invoice->amount_total - (float) $invoice->amount_paid) : 0;
				$current_balance = (float) get_user_meta(get_current_user_id(), '_credit_balance', true);

				if (! $invoice || CWD_V2_Invoices::STATUS_UNPAID !== $invoice->status || $amount > round($amount_due, 2) || $amount > round($current_balance, 2)) {
					wc_add_notice(__('This invoice is no longer available to pay.', 'custom-woo-dashboard'), 'error');
					return;
				}

				$cart_item_data['cwd_v2_invoice_id'] = $invoice_id;
			} elseif ($amount > (float) get_user_meta(get_current_user_id(), '_credit_balance', true)) {
				wc_add_notice(__('The payment amount cannot exceed your outstanding balance.', 'custom-woo-dashboard'), 'error');
				return;
			}

			// Empty cart to ensure only this payment is processed? Optional, but cleaner.
			WC()->cart->empty_cart();
					if ( ! $order->is_paid() ) {
						return;
					}

			// Add product to cart with custom price data
			WC()->cart->add_to_cart($product_id, 1, 0, array(), $cart_item_data);

			// Redirect to checkout
			wp_safe_redirect(wc_get_checkout_url());
			exit;
		}
	}

	public static function add_payment_item_metadata($item, $cart_item_key, $values, $order)
	{
		if (! empty($values['cwd_v2_invoice_id'])) {
			$item->add_meta_data('cwd_v2_invoice_id', absint($values['cwd_v2_invoice_id']), true);
		}

		if (isset($values['cwd_v2_custom_price'])) {
			$item->add_meta_data('cwd_v2_payment_amount', round((float) $values['cwd_v2_custom_price'], 2), true);
		}
	}

	public static function set_credit_payment_price($cart_obj)
	{
		if (is_admin() && ! defined('DOING_AJAX')) {
			return;
		}

		foreach ($cart_obj->get_cart() as $key => $value) {
			if (isset($value['cwd_v2_custom_price'])) {
				$value['data']->set_price($value['cwd_v2_custom_price']);
			}
		}
	}

	public static function reduce_credit_balance_on_payment($order_id)
	{
		$order = wc_get_order($order_id);
		if (! $order) return;

		// Check if already processed to avoid double reduction
		if ($order->get_meta('_cwd_v2_credit_payment_processed')) {
			return;
		}

		$product_id = (int) get_option('cwd_v2_credit_payment_product_id');
		$is_credit_payment = false;
		$payment_amount = 0;

		$invoice_id = 0;

		foreach ($order->get_items() as $item) {
			if ($item->get_product_id() === $product_id) {
				$is_credit_payment = true;
				$payment_amount = round((float) $order->get_total(), 2);

				$item_invoice_id = $item->get_meta('cwd_v2_invoice_id');
				if ($item_invoice_id) {
					$invoice_id = absint($item_invoice_id);
				}
			}
		}

		if ($is_credit_payment) {
			$user_id = $order->get_user_id();
			if ($user_id) {
				global $wpdb;
				$wpdb->query('START TRANSACTION');
				$wpdb->get_var($wpdb->prepare('SELECT ID FROM ' . $wpdb->users . ' WHERE ID = %d FOR UPDATE', (int) $user_id));
				$current_balance = (float) get_user_meta($user_id, '_credit_balance', true);
				if ($payment_amount <= 0 || $payment_amount > $current_balance) {
					$wpdb->query('ROLLBACK');
					return;
				}

				$payment_applied = true;
				if ($invoice_id) {
					$payment_applied = CWD_V2_Invoices::apply_invoice_payment($invoice_id, $user_id, $payment_amount);
				}

				if (! $payment_applied) {
					$wpdb->query('ROLLBACK');
					return;
				}

				$new_balance = max(0, $current_balance - $payment_amount);
				if (false === update_user_meta($user_id, '_credit_balance', $new_balance)) {
					$wpdb->query('ROLLBACK');
					return;
				}

				if ($invoice_id) {
					$ledger_result = CWD_V2_Account_Ledger::record(
						$user_id,
						CWD_V2_Account_Ledger::TYPE_PAYMENT,
						$payment_amount,
						'invoice',
						$invoice_id,
						sprintf(__('Payment for invoice #%d', 'custom-woo-dashboard'), $invoice_id)
					);
				} else {
					$ledger_result = CWD_V2_Account_Ledger::record(
						$user_id,
						CWD_V2_Account_Ledger::TYPE_PAYMENT,
						$payment_amount,
						'order',
						$order_id,
						sprintf(__('Payment made via order #%d', 'custom-woo-dashboard'), $order_id)
					);
				}

				if (false === $ledger_result) {
					$wpdb->query('ROLLBACK');
					return;
				}
				$wpdb->query('COMMIT');

				// Mark as processed
				$order->update_meta_data('_cwd_v2_credit_payment_processed', 'yes');
				$order->save();
			}
		}
	}

	public static function handle_credit_increase_request()
	{
		if ((isset($_POST['cwd_v2_request_increase']) || isset($_POST['cwd_v2_requested_amount'])) && is_user_logged_in()) {
			$nonce = isset($_POST['cwd_v2_request_increase_nonce']) ? sanitize_text_field(wp_unslash($_POST['cwd_v2_request_increase_nonce'])) : '';
			if (! wp_verify_nonce($nonce, 'cwd_v2_request_increase_action')) {
				wc_add_notice(__('Security check failed.', 'custom-woo-dashboard'), 'error');
				return;
			}

			$current_user = wp_get_current_user();
			$requested_amount = sanitize_text_field(wp_unslash($_POST['cwd_v2_requested_amount'] ?? ''));
			$reason = sanitize_textarea_field(wp_unslash($_POST['cwd_v2_request_reason'] ?? ''));
			if (! is_numeric($requested_amount) || (float) $requested_amount <= 0) {
				wc_add_notice(__('Enter a valid positive credit limit.', 'custom-woo-dashboard'), 'error');
				return;
			}

			// Check if user already has an active pending request or submitted very recently
			if (class_exists('CWD_V2_Trade_Applications')) {
				global $wpdb;
				$apps_table = CWD_V2_Trade_Applications::get_table_name();
				$existing_pending = $wpdb->get_var($wpdb->prepare(
					"SELECT id FROM {$apps_table} WHERE user_id = %d AND status = %s AND forminator_form_id = 0 LIMIT 1",
					$current_user->ID,
					'pending'
				));
				if ($existing_pending) {
					wc_add_notice(__('You already have a credit increase request under review.', 'custom-woo-dashboard'), 'notice');
					$redirect_url = wp_get_referer() ?: wc_get_endpoint_url('credit', '', wc_get_page_permalink('myaccount'));
					wp_safe_redirect($redirect_url);
					exit;
				}
			}

			$admin_email = get_option('admin_email');
			$subject = sprintf(__('Credit Limit Increase Request from %s', 'custom-woo-dashboard'), $current_user->display_name);

			$message = sprintf(__("Customer: %s (%s)\n", 'custom-woo-dashboard'), $current_user->display_name, $current_user->user_email);
			$message .= sprintf(__("Requested New Limit: %s\n", 'custom-woo-dashboard'), $requested_amount);
			$message .= sprintf(__("Reason:\n%s\n", 'custom-woo-dashboard'), $reason);

			wp_mail($admin_email, $subject, $message);

			// Also record in Trade Applications queue for admin review & approval
			if (class_exists('CWD_V2_Trade_Applications')) {
				CWD_V2_Trade_Applications::record_credit_increase_request(
					$current_user->ID,
					(float) $requested_amount,
					$reason
				);
			}

			wc_add_notice(__('Your request for a credit limit increase has been submitted for review.', 'custom-woo-dashboard'), 'success');

			// Post-Redirect-Get: Redirect to GET so refreshing page never re-submits POST form
			$redirect_url = wp_get_referer() ?: wc_get_endpoint_url('credit', '', wc_get_page_permalink('myaccount'));
			wp_safe_redirect($redirect_url);
			exit;
		}
	}

	public static function apply_credit_order_charge($order_id)
	{
		$order = wc_get_order($order_id);
		if (! $order || 'cwd_v2_credit_account' !== $order->get_payment_method() || $order->get_meta('_cwd_v2_credit_charge_processed')) {
			return;
		}
		$user_id = (int) $order->get_user_id();
		$amount = round((float) $order->get_total(), 2);
		if (! $user_id || $amount <= 0) {
			return;
		}
		global $wpdb;
		$wpdb->query('START TRANSACTION');
		$wpdb->get_var($wpdb->prepare('SELECT ID FROM ' . $wpdb->users . ' WHERE ID = %d FOR UPDATE', $user_id));
		$current_balance = (float) get_user_meta($user_id, '_credit_balance', true);
		$limit = (float) get_user_meta($user_id, '_credit_limit', true);
		if ($amount > max(0, $limit - $current_balance)) {
			$wpdb->query('ROLLBACK');
			$order->add_order_note(__('Credit charge was not applied because the available credit is insufficient.', 'custom-woo-dashboard'));
			return;
		}
		if (false === update_user_meta($user_id, '_credit_balance', $current_balance + $amount)) {
			$wpdb->query('ROLLBACK');
			return;
		}
		if (false === CWD_V2_Account_Ledger::record($user_id, CWD_V2_Account_Ledger::TYPE_CHARGE, $amount, 'order', $order_id, sprintf(__('Order #%d placed on credit account', 'custom-woo-dashboard'), $order_id))) {
			$wpdb->query('ROLLBACK');
			return;
		}
		$wpdb->query('COMMIT');
		$order->update_meta_data('_cwd_v2_credit_charge_processed', 'yes');
		$order->save();
	}

	public static function reverse_credit_order_charge($order_id)
	{
		$order = wc_get_order($order_id);
		if (! $order || 'cwd_v2_credit_account' !== $order->get_payment_method() || ! $order->get_meta('_cwd_v2_credit_charge_processed') || $order->get_meta('_cwd_v2_credit_charge_reversed')) {
			return;
		}
		$user_id = (int) $order->get_user_id();
		$amount = round((float) $order->get_total(), 2);
		global $wpdb;
		$wpdb->query('START TRANSACTION');
		$wpdb->get_var($wpdb->prepare('SELECT ID FROM ' . $wpdb->users . ' WHERE ID = %d FOR UPDATE', $user_id));
		$current_balance = (float) get_user_meta($user_id, '_credit_balance', true);
		if (false === update_user_meta($user_id, '_credit_balance', max(0, $current_balance - $amount))) {
			$wpdb->query('ROLLBACK');
			return;
		}
		if (false === CWD_V2_Account_Ledger::record($user_id, CWD_V2_Account_Ledger::TYPE_REFUND, $amount, 'order', $order_id, sprintf(__('Credit charge reversed for order #%d', 'custom-woo-dashboard'), $order_id))) {
			$wpdb->query('ROLLBACK');
			return;
		}
		$wpdb->query('COMMIT');
		$order->update_meta_data('_cwd_v2_credit_charge_reversed', 'yes');
		$order->save();
	}

	/**
	 * Reconcile refunds for both credit orders and invoice-payment orders.
	 * Invoice payments restore the balance and reopen the invoice; credit-order
	 * refunds reduce the balance created by the original purchase.
	 *
	 * @param int $order_id
	 * @param int $refund_id
	 */
	public static function handle_credit_order_refund($order_id, $refund_id)
	{
		$order = wc_get_order($order_id);
		$refund = wc_get_order($refund_id);
		if (! $order || ! $refund || $refund->get_meta('_cwd_v2_credit_refund_processed')) {
			return;
		}

		$invoice_id = 0;
		$product_id = (int) get_option('cwd_v2_credit_payment_product_id');
		foreach ($order->get_items() as $item) {
			if ($product_id && $item->get_product_id() === $product_id && $item->get_meta('cwd_v2_invoice_id')) {
				$invoice_id = absint($item->get_meta('cwd_v2_invoice_id'));
				break;
			}
		}

		if (! $invoice_id && 'cwd_v2_credit_account' !== $order->get_payment_method()) {
			return;
		}

		$refund_amount = method_exists($refund, 'get_amount') ? abs((float) $refund->get_amount()) : abs((float) $refund->get_total());
		$refund_amount = min($refund_amount, abs((float) $order->get_total()));
		if ($refund_amount <= 0) {
			return;
		}

		$user_id = $order->get_user_id();
		if (! $user_id) {
			return;
		}

		global $wpdb;
		$wpdb->query('START TRANSACTION');
		$wpdb->get_var($wpdb->prepare('SELECT ID FROM ' . $wpdb->users . ' WHERE ID = %d FOR UPDATE', (int) $user_id));
		$current_balance = (float) get_user_meta($user_id, '_credit_balance', true);

		if ($invoice_id) {
			if (! CWD_V2_Invoices::reverse_invoice_payment($invoice_id, $user_id, $refund_amount)) {
				$wpdb->query('ROLLBACK');
				return;
			}
			$new_balance = $current_balance + $refund_amount;
			$ledger_type = CWD_V2_Account_Ledger::TYPE_REFUND;
			$reference_type = 'invoice';
			$reference_id = $invoice_id;
		} else {
			$new_balance = max(0, $current_balance - $refund_amount);
			$ledger_type = CWD_V2_Account_Ledger::TYPE_REFUND;
			$reference_type = 'order';
			$reference_id = $order_id;
		}

		if (false === update_user_meta($user_id, '_credit_balance', $new_balance)) {
			$wpdb->query('ROLLBACK');
			return;
		}

		$ledger_result = CWD_V2_Account_Ledger::record(
			$user_id,
			$ledger_type,
			$refund_amount,
			$reference_type,
			$reference_id,
			sprintf(__('Refund for order #%d', 'custom-woo-dashboard'), $order_id)
		);
		if (false === $ledger_result) {
			$wpdb->query('ROLLBACK');
			return;
		}

		$wpdb->query('COMMIT');
		$refund->update_meta_data('_cwd_v2_credit_refund_processed', 'yes');
		$refund->save();
	}
}
