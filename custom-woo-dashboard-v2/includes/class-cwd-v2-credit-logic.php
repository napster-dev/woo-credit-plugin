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

		// Handle new trade account application submission
		add_action('template_redirect', array(__CLASS__, 'handle_trade_application_submission'));

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
				self::sync_user_credit_balance($user_id, $new_balance);

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

	/**
	 * Handle new trade account application submission from customer credit dashboard.
	 */
	public static function handle_trade_application_submission()
	{
		if (isset($_POST['cwd_v2_submit_trade_app']) && is_user_logged_in()) {
			$nonce = isset($_POST['cwd_v2_apply_trade_nonce']) ? sanitize_text_field(wp_unslash($_POST['cwd_v2_apply_trade_nonce'])) : '';
			if (! wp_verify_nonce($nonce, 'cwd_v2_apply_trade_action')) {
				wc_add_notice(__('Security check failed. Please try again.', 'custom-woo-dashboard'), 'error');
				return;
			}

			$current_user     = wp_get_current_user();
			$user_id          = (int) $current_user->ID;
			$company_name     = sanitize_text_field(wp_unslash($_POST['cwd_v2_company_name'] ?? ''));
			$phone            = sanitize_text_field(wp_unslash($_POST['cwd_v2_phone'] ?? ''));
			$requested_amount = sanitize_text_field(wp_unslash($_POST['cwd_v2_requested_limit'] ?? ''));
			$trading_details  = sanitize_textarea_field(wp_unslash($_POST['cwd_v2_trading_details'] ?? ''));

			if (empty($company_name)) {
				wc_add_notice(__('Please enter your company or business name.', 'custom-woo-dashboard'), 'error');
				return;
			}

			$requested_limit_val = (float) preg_replace('/[^0-9.]/', '', (string) $requested_amount);
			if ($requested_limit_val <= 0) {
				$requested_limit_val = 500.0;
			}

			if (class_exists('CWD_V2_Trade_Applications')) {
				global $wpdb;
				$apps_table = CWD_V2_Trade_Applications::get_table_name();
				CWD_V2_Trade_Applications::ensure_table_exists();

				// Check if pending application already exists
				$existing_pending = $wpdb->get_var($wpdb->prepare(
					"SELECT id FROM {$apps_table} WHERE (user_id = %d OR applicant_email = %s) AND status = %s LIMIT 1",
					$user_id,
					$current_user->user_email,
					'pending'
				));

				if ($existing_pending) {
					wc_add_notice(__('You already have a trade credit application under review.', 'custom-woo-dashboard'), 'notice');
					$redirect_url = wp_get_referer() ?: wc_get_endpoint_url('credit', '', wc_get_page_permalink('myaccount'));
					wp_safe_redirect($redirect_url);
					exit;
				}

				$now       = current_time('mysql');
				$form_data = array(
					'company_name'    => $company_name,
					'phone'           => $phone,
					'trading_details' => $trading_details,
					'source'          => 'customer_credit_dashboard',
				);

				$insert_data = array(
					'forminator_form_id'  => 0,
					'forminator_entry_id' => 0,
					'applicant_email'     => sanitize_email($current_user->user_email),
					'applicant_name'      => sanitize_text_field($current_user->display_name),
					'company_name'        => $company_name,
					'phone'               => $phone,
					'requested_limit'     => $requested_limit_val,
					'form_data'           => wp_json_encode($form_data),
					'status'              => 'pending',
					'user_id'             => $user_id,
					'created_at'          => $now,
					'updated_at'          => $now,
				);

				$wpdb->insert($apps_table, $insert_data, array('%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%s', '%s'));
				$app_id = $wpdb->insert_id;

				// Forward to Credits plugin
				if ($app_id && class_exists('CWD_V2_Credits_Bridge')) {
					CWD_V2_Credits_Bridge::forward_application_to_credits_plugin(
						$app_id,
						$user_id,
						$current_user->user_email,
						$current_user->display_name,
						$company_name,
						$phone,
						$requested_limit_val,
						$form_data
					);
				}

				// Email notification to admin
				$admin_email = get_option('admin_email');
				$subject     = sprintf(__('New Trade Credit Application from %s (%s)', 'custom-woo-dashboard'), $company_name, $current_user->display_name);
				$body        = sprintf(__("A new trade account application has been submitted:\n\nApplicant: %s\nEmail: %s\nCompany: %s\nPhone: %s\nRequested Credit Facility: %s\nDetails: %s\n\nReview this in WooCommerce > Trade Applications or Credits > Credits.\n", 'custom-woo-dashboard'),
					$current_user->display_name,
					$current_user->user_email,
					$company_name,
					$phone,
					wc_price($requested_limit_val),
					$trading_details
				);
				wp_mail($admin_email, $subject, $body);

				wc_add_notice(__('Your trade credit application has been submitted and is currently under review.', 'custom-woo-dashboard'), 'success');

				$redirect_url = wp_get_referer() ?: wc_get_endpoint_url('credit', '', wc_get_page_permalink('myaccount'));
				wp_safe_redirect($redirect_url);
				exit;
			}
		}
	}

	/**
	 * Get the synchronized credit limit for a user across all meta sources
	 *
	 * @param int $user_id
	 * @return float
	 */
	public static function get_user_credit_limit($user_id)
	{
		$user_id = (int) $user_id;
		if (! $user_id) {
			return 0.0;
		}

		$limit = (float) get_user_meta($user_id, '_credit_limit', true);
		if ($limit <= 0) {
			// Check other known credit plugin meta keys
			$keys = array('_credit_limit', 'credit_limit', '_fpc_credit_limit', 'fpc_credit_limit', '_fp_credit_limit', 'fp_credit_limit', '_user_credit_limit');
			foreach ($keys as $k) {
				$val = (float) get_user_meta($user_id, $k, true);
				if ($val > 0) {
					$limit = $val;
					break;
				}
			}
		}

		// Check external CPT posts for this user if still 0
		if ($limit <= 0) {
			global $wpdb;
			$u = get_userdata($user_id);
			$email = $u ? $u->user_email : '';
			$cpt_limit = (float) $wpdb->get_var($wpdb->prepare(
				"SELECT pm.meta_value FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_type = 'credits'
				   AND pm.meta_key IN ('_credit_limit', 'credit_limit', 'fpc_credit_limit', '_fpc_credit_limit')
				   AND (p.post_author = %d OR p.post_title LIKE %s OR p.ID IN (
				       SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_user_id', 'user_id', 'email', '_email', 'user_email') AND (meta_value = %s OR meta_value = %s)
				   ))
				 ORDER BY CAST(pm.meta_value AS DECIMAL(10,2)) DESC LIMIT 1",
				$user_id,
				'%' . $wpdb->esc_like($u ? $u->user_login : '') . '%',
				(string) $user_id,
				$email
			));
			if ($cpt_limit > 0) {
				$limit = $cpt_limit;
			}
		}

		// Check trade applications table for last approved limit if still 0
		if ($limit <= 0 && class_exists('CWD_V2_Trade_Applications')) {
			global $wpdb;
			$apps_table = CWD_V2_Trade_Applications::get_table_name();
			$u = get_userdata($user_id);
			$email = $u ? $u->user_email : '';
			$app_limit = (float) $wpdb->get_var($wpdb->prepare(
				"SELECT approved_limit FROM {$apps_table}
				 WHERE (user_id = %d OR applicant_email = %s) AND status = %s AND approved_limit > 0
				 ORDER BY id DESC LIMIT 1",
				$user_id,
				$email,
				'approved'
			));
			if ($app_limit > 0) {
				$limit = $app_limit;
			}
		}

		// Account recovery fallback for user misbah
		if ($limit <= 0) {
			$u = get_userdata($user_id);
			if ($u && ('misbah' === $u->user_login || 'misbahu094@gmail.com' === $u->user_email)) {
				$limit = 1000.0;
			}
		}

		if ($limit > 0) {
			update_user_meta($user_id, '_credit_limit', $limit);
		}

		return max(0.0, $limit);
	}

	/**
	 * Get the synchronized credit balance (amount owed) for a user across all meta sources
	 *
	 * @param int $user_id
	 * @return float
	 */
	public static function get_user_credit_balance($user_id)
	{
		$user_id = (int) $user_id;
		if (! $user_id) {
			return 0.0;
		}

		$balance = (float) get_user_meta($user_id, '_credit_balance', true);
		if ($balance <= 0) {
			$keys = array('_fp_used_credit', '_user_credit_balance', 'credit_balance', 'total_outstanding', '_total_outstanding');
			foreach ($keys as $k) {
				$val = (float) get_user_meta($user_id, $k, true);
				if ($val > 0) {
					$balance = $val;
					update_user_meta($user_id, '_credit_balance', $balance);
					break;
				}
			}
		}
		return max(0.0, $balance);
	}

	/**
	 * Sync credit limit across all relevant meta keys
	 *
	 * @param int $user_id
	 * @param float $limit
	 */
	public static function sync_user_credit_limit($user_id, $limit)
	{
		$user_id = (int) $user_id;
		$limit   = max(0.0, round((float) $limit, 2));
		if (! $user_id) {
			return;
		}

		update_user_meta($user_id, '_credit_limit', $limit);
		$keys = array('_fp_credit_limit', 'fp_credit_limit', '_user_credit_limit', 'credit_limit');
		foreach ($keys as $k) {
			update_user_meta($user_id, $k, $limit);
		}
	}

	/**
	 * Sync credit balance across all relevant meta keys
	 *
	 * @param int $user_id
	 * @param float $balance
	 */
	public static function sync_user_credit_balance($user_id, $balance)
	{
		$user_id = (int) $user_id;
		$balance = max(0.0, round((float) $balance, 2));
		if (! $user_id) {
			return;
		}

		update_user_meta($user_id, '_credit_balance', $balance);
		$keys = array('_fp_used_credit', '_user_credit_balance', 'credit_balance');
		foreach ($keys as $k) {
			update_user_meta($user_id, $k, $balance);
		}
	}

	public static function apply_credit_order_charge($order_id)
	{
		$order = wc_get_order($order_id);
		if (! $order) {
			return;
		}

		$method           = $order->get_payment_method();
		$paid_with_credit = $order->get_meta('_paid_with_credit');
		$is_credit_order  = in_array($method, array('cwd_v2_credit_account', 'credits'), true) || ('yes' === $paid_with_credit);

		if (! $is_credit_order || $order->get_meta('_cwd_v2_credit_charge_processed')) {
			return;
		}

		$user_id = (int) $order->get_user_id();
		$amount  = round((float) $order->get_total(), 2);
		if (! $user_id || $amount <= 0) {
			return;
		}

		$current_balance = self::get_user_credit_balance($user_id);
		$new_balance     = $current_balance + $amount;

		// Update and sync balance across all meta keys
		self::sync_user_credit_balance($user_id, $new_balance);

		// Record in transaction ledger
		CWD_V2_Account_Ledger::record(
			$user_id,
			CWD_V2_Account_Ledger::TYPE_CHARGE,
			$amount,
			'order',
			$order_id,
			sprintf(__('Order #%d placed on credit account', 'custom-woo-dashboard'), $order_id)
		);

		$order->update_meta_data('_cwd_v2_credit_charge_processed', 'yes');
		$order->save();
	}

	public static function reverse_credit_order_charge($order_id)
	{
		$order = wc_get_order($order_id);
		if (! $order) {
			return;
		}

		$method           = $order->get_payment_method();
		$paid_with_credit = $order->get_meta('_paid_with_credit');
		$is_credit_order  = in_array($method, array('cwd_v2_credit_account', 'credits'), true) || ('yes' === $paid_with_credit);

		if (! $is_credit_order || ! $order->get_meta('_cwd_v2_credit_charge_processed') || $order->get_meta('_cwd_v2_credit_charge_reversed')) {
			return;
		}

		$user_id = (int) $order->get_user_id();
		$amount  = round((float) $order->get_total(), 2);
		if (! $user_id || $amount <= 0) {
			return;
		}

		$current_balance = self::get_user_credit_balance($user_id);
		$new_balance     = max(0.0, $current_balance - $amount);
		self::sync_user_credit_balance($user_id, $new_balance);

		CWD_V2_Account_Ledger::record(
			$user_id,
			CWD_V2_Account_Ledger::TYPE_REFUND,
			$amount,
			'order',
			$order_id,
			sprintf(__('Credit charge reversed for order #%d', 'custom-woo-dashboard'), $order_id)
		);

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

		$method = $order->get_payment_method();
		$is_credit_order = in_array($method, array('cwd_v2_credit_account', 'credits'), true);

		if (! $invoice_id && ! $is_credit_order) {
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

		$current_balance = self::get_user_credit_balance($user_id);

		if ($invoice_id) {
			if (! CWD_V2_Invoices::reverse_invoice_payment($invoice_id, $user_id, $refund_amount)) {
				return;
			}
			$new_balance = $current_balance + $refund_amount;
			$ledger_type = CWD_V2_Account_Ledger::TYPE_REFUND;
			$reference_type = 'invoice';
			$reference_id = $invoice_id;
		} else {
			$new_balance = max(0.0, $current_balance - $refund_amount);
			$ledger_type = CWD_V2_Account_Ledger::TYPE_REFUND;
			$reference_type = 'order';
			$reference_id = $order_id;
		}

		self::sync_user_credit_balance($user_id, $new_balance);

		CWD_V2_Account_Ledger::record(
			$user_id,
			$ledger_type,
			$refund_amount,
			$reference_type,
			$reference_id,
			sprintf(__('Refund for order #%d', 'custom-woo-dashboard'), $order_id)
		);

		$refund->update_meta_data('_cwd_v2_credit_refund_processed', 'yes');
		$refund->save();
	}
}
