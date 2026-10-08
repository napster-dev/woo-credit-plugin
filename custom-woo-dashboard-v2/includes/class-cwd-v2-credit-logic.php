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

		// Hook into order checkout and status changes to immediately deduct available credit and charge account
		add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'apply_credit_order_charge'), 10, 1);
		add_action('woocommerce_payment_complete', array(__CLASS__, 'apply_credit_order_charge'), 10, 1);
		add_action('woocommerce_order_status_processing', array(__CLASS__, 'apply_credit_order_charge'), 10, 1);
		add_action('woocommerce_order_status_completed', array(__CLASS__, 'apply_credit_order_charge'), 10, 1);
		add_action('woocommerce_order_status_on-hold', array(__CLASS__, 'apply_credit_order_charge'), 10, 1);
		add_action('woocommerce_order_status_cancelled', array(__CLASS__, 'reverse_credit_order_charge'), 10, 1);
		add_action('woocommerce_order_status_failed', array(__CLASS__, 'reverse_credit_order_charge'), 10, 1);

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

		// 1. Authoritative check: If _credit_limit is set in usermeta, it is the absolute source of truth.
		// Never override an explicitly set admin limit with older legacy values.
		if (metadata_exists('user', $user_id, '_credit_limit')) {
			return max(0.0, (float) get_user_meta($user_id, '_credit_limit', true));
		}

		// 2. Initial discovery: Only when _credit_limit has never been initialized for this user
		$discovered_limit = 0.0;

		// Check all known credit plugin meta keys to discover existing limits
		$keys = array(
			'credit_limit',
			'_credits_limit',
			'credits_limit',
			'_fpc_credit_limit',
			'fpc_credit_limit',
			'_fp_credit_limit',
			'fp_credit_limit',
			'_user_credit_limit',
			'_wc_cs_credit_limit',
			'wc_cs_credit_limit',
			'_approved_credits',
			'approved_credits',
		);
		foreach ($keys as $k) {
			$val = (float) get_user_meta($user_id, $k, true);
			if ($val > $discovered_limit) {
				$discovered_limit = $val;
			}
		}

		// Check latest approved application in trade applications table
		if (class_exists('CWD_V2_Trade_Applications')) {
			global $wpdb;
			$apps_table = CWD_V2_Trade_Applications::get_table_name();
			$u = get_userdata($user_id);
			$email = $u ? $u->user_email : '';
			$app_limit = (float) $wpdb->get_var($wpdb->prepare(
				"SELECT approved_limit FROM {$apps_table}
				 WHERE (user_id = %d OR (applicant_email = %s AND applicant_email != '')) AND status = %s AND approved_limit > 0
				 ORDER BY id DESC LIMIT 1",
				$user_id,
				$email,
				'approved'
			));
			if ($app_limit > $discovered_limit) {
				$discovered_limit = $app_limit;
			}
		}

		// Check external CPT posts in 'credits' / 'wc_cs_credits' / 'fpc_credits'
		global $wpdb;
		$u = get_userdata($user_id);
		$email = $u ? $u->user_email : '';
		$login = $u ? $u->user_login : '';
		$cpt_limit = (float) $wpdb->get_var($wpdb->prepare(
			"SELECT pm.meta_value FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type IN ('wc_cs_credits', 'credits', 'fpc_credits')
			   AND p.post_status != 'trash'
			   AND pm.meta_key IN (
			       'approved_credits', '_approved_credits', 'wc_cs_approved_credits',
			       'credit_limit', '_credit_limit', 'wc_cs_credit_limit', '_wc_cs_credit_limit',
			       'fpc_credit_limit', '_fpc_credit_limit', '31596', '41978'
			   )
			   AND CAST(pm.meta_value AS DECIMAL(10,2)) > 0
			   AND (
			       p.post_author = %d
			       OR p.post_title = %s
			       OR p.post_title = %s
			       OR p.ID IN (
			           SELECT post_id FROM {$wpdb->postmeta}
			           WHERE meta_key IN (
				           '_user_id', 'user_id', 'wc_cs_user_id', '_wc_cs_user_id',
				           'customer_id', '_customer_id', 'customer_user', '_customer_user',
				           'email', '_email', 'user_email', '_user_email', 'wc_cs_user_email', '_wc_cs_user_email'
			           ) AND (meta_value = %s OR meta_value = %s OR meta_value = %s)
			       )
			   )
			 ORDER BY CAST(pm.meta_value AS DECIMAL(10,2)) DESC LIMIT 1",
			$user_id,
			$login,
			$email,
			(string) $user_id,
			$email,
			$login
		));
		if ($cpt_limit > $discovered_limit) {
			$discovered_limit = $cpt_limit;
		}

		// Initialize authoritative usermeta so future lookups are immediate and consistent
		if ($discovered_limit > 0) {
			update_user_meta($user_id, '_credit_limit', $discovered_limit);
			self::sync_user_credit_limit($user_id, $discovered_limit);
		}

		return max(0.0, $discovered_limit);
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

		if (metadata_exists('user', $user_id, '_credit_balance')) {
			return max(0.0, (float) get_user_meta($user_id, '_credit_balance', true));
		}

		$balance = 0.0;
		$keys = array(
			'total_outstanding_amount', '_total_outstanding_amount',
			'wc_cs_total_outstanding_amount', '_wc_cs_total_outstanding_amount',
			'_fp_used_credit', '_user_credit_balance', 'credit_balance',
			'total_outstanding', '_total_outstanding'
		);
		foreach ($keys as $k) {
			$val = (float) get_user_meta($user_id, $k, true);
			if ($val > 0) {
				$balance = $val;
				update_user_meta($user_id, '_credit_balance', $balance);
				break;
			}
		}

		// Fallback to unpaid invoices in cwd_v2_invoices table if balance is 0
		if ($balance <= 0 && class_exists('CWD_V2_Invoices')) {
			global $wpdb;
			$invoices_table = CWD_V2_Invoices::table_name();
			if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $invoices_table)) === $invoices_table) {
				$unpaid_invoices_total = (float) $wpdb->get_var($wpdb->prepare(
					"SELECT SUM(GREATEST(0, amount_total - amount_paid)) FROM {$invoices_table}
					 WHERE user_id = %d AND status NOT IN ('paid', 'void')",
					$user_id
				));
				if ($unpaid_invoices_total > 0) {
					$balance = $unpaid_invoices_total;
					update_user_meta($user_id, '_credit_balance', $balance);
				}
			}
		}

		// Fallback to external Credits CPT postmeta if still 0
		if ($balance <= 0) {
			global $wpdb;
			$u = get_userdata($user_id);
			$email = $u ? $u->user_email : '';
			$login = $u ? $u->user_login : '';
			$cpt_balance = (float) $wpdb->get_var($wpdb->prepare(
				"SELECT pm.meta_value FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_type IN ('wc_cs_credits', 'credits', 'fpc_credits')
				   AND pm.meta_key IN (
				       'total_outstanding_amount', '_total_outstanding_amount',
				       'wc_cs_total_outstanding_amount', '_wc_cs_total_outstanding_amount',
				       'total_outstanding', '_total_outstanding',
				       'wc_cs_total_outstanding', '_wc_cs_total_outstanding',
				       'outstanding_credits', 'wc_cs_outstanding_credits',
				       'used_credits', '_used_credits'
				   )
				   AND CAST(pm.meta_value AS DECIMAL(10,2)) > 0
				   AND (
				       p.post_author = %d
				       OR p.post_title = %s
				       OR p.post_title = %s
				       OR p.ID IN (
				           SELECT post_id FROM {$wpdb->postmeta}
				           WHERE meta_key IN (
				               '_user_id', 'user_id', 'wc_cs_user_id', '_wc_cs_user_id',
				               'customer_id', '_customer_id', 'customer_user', '_customer_user',
				               'fpc_user_id', 'user_email', '_user_email', 'wc_cs_user_email',
				               '_wc_cs_user_email', 'email', '_email'
				           ) AND (meta_value = %s OR meta_value = %s OR meta_value = %s)
				       )
				   )
				 ORDER BY CAST(pm.meta_value AS DECIMAL(10,2)) DESC LIMIT 1",
				$user_id,
				$login,
				$email,
				(string) $user_id,
				$email,
				$login
			));
			if ($cpt_balance > 0) {
				$balance = $cpt_balance;
				update_user_meta($user_id, '_credit_balance', $balance);
			}
		}

		return max(0.0, $balance);
	}

	/**
	 * Sync credit limit across all relevant meta keys and CPT post
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

		// 1. Authoritative user meta
		update_user_meta($user_id, '_credit_limit', $limit);

		// 2. All legacy & plugin-specific user meta keys
		$keys = array(
			'_fp_credit_limit', 'fp_credit_limit', '_user_credit_limit', 'credit_limit',
			'_wc_cs_credit_limit', 'wc_cs_credit_limit',
			'_approved_credits', 'approved_credits',
			'_approved_credit', 'approved_credit',
			'_wc_cs_approved_credits', 'wc_cs_approved_credits',
			'_fpc_credit_limit', 'fpc_credit_limit',
			'_credits_limit', 'credits_limit',
		);
		foreach ($keys as $k) {
			update_user_meta($user_id, $k, $limit);
		}

		// 3. Available credit calculations
		$current_balance = (float) get_user_meta($user_id, '_credit_balance', true);
		$available = max(0.0, $limit - $current_balance);
		$avail_keys = array(
			'_available_credits', 'available_credits',
			'_wc_cs_available_credits', 'wc_cs_available_credits',
			'fpc_available_credits', 'available_credit'
		);
		foreach ($avail_keys as $ak) {
			update_user_meta($user_id, $ak, $available);
		}

		// 4. Synchronize trade application rows in wp_cwd_v2_trade_applications
		if (class_exists('CWD_V2_Trade_Applications')) {
			global $wpdb;
			$apps_table = CWD_V2_Trade_Applications::get_table_name();
			if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $apps_table)) === $apps_table) {
				$u = get_userdata($user_id);
				$email = $u ? $u->user_email : '';
				$wpdb->query($wpdb->prepare(
					"UPDATE {$apps_table} 
					 SET approved_limit = %f, user_id = %d, updated_at = %s 
					 WHERE (user_id = %d OR (applicant_email = %s AND applicant_email != ''))",
					$limit,
					$user_id,
					current_time('mysql'),
					$user_id,
					$email
				));
			}
		}

		// 5. Synchronize credit post in Credits > Credits with explicit limit value
		if (class_exists('CWD_V2_Credits_Bridge') && method_exists('CWD_V2_Credits_Bridge', 'sync_user_to_credits_post')) {
			CWD_V2_Credits_Bridge::sync_user_to_credits_post($user_id, $limit);
		}
	}

	/**
	 * Sync credit balance across all relevant meta keys and external CPT post
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
		$keys = array(
			'_fp_used_credit', '_user_credit_balance', 'credit_balance',
			'total_outstanding', '_total_outstanding', 'wc_cs_total_outstanding',
			'_wc_cs_total_outstanding', 'outstanding_credits', 'wc_cs_outstanding_credits',
			'total_outstanding_amount', '_total_outstanding_amount',
			'wc_cs_total_outstanding_amount', '_wc_cs_total_outstanding_amount',
			'used_credits', '_used_credits', '_credit_used'
		);
		foreach ($keys as $k) {
			update_user_meta($user_id, $k, $balance);
		}

		$limit = self::get_user_credit_limit($user_id);
		$avail = max(0.0, round($limit - $balance, 2));
		$avail_keys = array(
			'_available_credits', 'available_credits',
			'_wc_cs_available_credits', 'wc_cs_available_credits',
			'fpc_available_credits', 'available_credit'
		);
		foreach ($avail_keys as $ak) {
			update_user_meta($user_id, $ak, $avail);
		}

		// Update external CPT posts in 'credits' / 'wc_cs_credits' / 'fpc_credits'
		global $wpdb;
		$u = get_userdata($user_id);
		$email = $u ? $u->user_email : '';
		$login = $u ? $u->user_login : '';
		$post_ids = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type IN ('credits', 'wc_cs_credits', 'fpc_credits')
			   AND (p.post_author = %d OR (pm.meta_key IN ('_user_id', 'user_id', 'wc_cs_user_id', '_wc_cs_user_id', 'fpc_user_id', 'customer_id', 'email', 'user_email', 'wc_cs_user_email') AND (pm.meta_value = %s OR pm.meta_value = %s OR pm.meta_value = %s)))",
			$user_id,
			(string) $user_id,
			$email,
			$login
		));

		if (! empty($post_ids)) {
			foreach ($post_ids as $pid) {
				update_post_meta((int) $pid, 'total_outstanding_amount', $balance);
				update_post_meta((int) $pid, '_total_outstanding_amount', $balance);
				update_post_meta((int) $pid, 'wc_cs_total_outstanding_amount', $balance);
				update_post_meta((int) $pid, '_wc_cs_total_outstanding_amount', $balance);
				update_post_meta((int) $pid, 'total_outstanding', $balance);
				update_post_meta((int) $pid, '_total_outstanding', $balance);
				update_post_meta((int) $pid, 'wc_cs_total_outstanding', $balance);
				update_post_meta((int) $pid, '_wc_cs_total_outstanding', $balance);
				update_post_meta((int) $pid, 'outstanding_credits', $balance);
				update_post_meta((int) $pid, 'wc_cs_outstanding_credits', $balance);
				update_post_meta((int) $pid, 'used_credits', $balance);
				update_post_meta((int) $pid, '_used_credits', $balance);
				update_post_meta((int) $pid, 'available_credits', $avail);
				update_post_meta((int) $pid, '_available_credits', $avail);
				update_post_meta((int) $pid, 'wc_cs_available_credits', $avail);
				update_post_meta((int) $pid, '_wc_cs_available_credits', $avail);
				update_post_meta((int) $pid, 'fpc_available_credits', $avail);
				clean_post_cache((int) $pid);
			}
		}
	}

	/**
	 * Recalculate live outstanding credit balance from unpaid invoices & orders, and sync
	 *
	 * @param int $user_id
	 * @return float
	 */
	public static function recalculate_and_sync_user_credit_balance($user_id)
	{
		$user_id = (int) $user_id;
		if (! $user_id) {
			return 0.0;
		}

		global $wpdb;
		$total_unpaid = 0.0;

		// 1. Calculate unpaid invoices from invoices table
		if (class_exists('CWD_V2_Invoices')) {
			$invoices_table = CWD_V2_Invoices::table_name();
			if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $invoices_table)) === $invoices_table) {
				$inv_total = (float) $wpdb->get_var($wpdb->prepare(
					"SELECT SUM(GREATEST(0, amount_total - amount_paid)) FROM {$invoices_table}
					 WHERE user_id = %d AND status NOT IN ('paid', 'void')",
					$user_id
				));
				$total_unpaid += $inv_total;
			}
		}

		// 2. If no invoices, use stored balance or fallback
		if ($total_unpaid <= 0) {
			$total_unpaid = (float) get_user_meta($user_id, '_credit_balance', true);
		}

		self::sync_user_credit_balance($user_id, $total_unpaid);
		return $total_unpaid;
	}

	public static function apply_credit_order_charge($order_id)
	{
		$order = wc_get_order($order_id);
		if (! $order) {
			return;
		}

		// Prevent duplicate deduction
		if ($order->get_meta('_cwd_v2_credit_charge_processed')) {
			return;
		}

		$method = strtolower((string) $order->get_payment_method());
		$is_credit_method = in_array($method, array('cwd_v2_credit_account', 'credits', 'wc_cs', 'wc_cs_credits', 'fpc_credits', 'wc_credit_gateway', 'credits_gateway', 'woocommerce_credits', 'trade_credit'), true)
			|| (strpos($method, 'credit') !== false && strpos($method, 'card') === false && strpos($method, 'stripe') === false);

		$paid_with_credit = $order->get_meta('_paid_with_credit');
		$credits_used_meta = (float) ($order->get_meta('_wc_cs_credits_used')
			?: ($order->get_meta('wc_cs_credits_used')
			?: ($order->get_meta('_wc_cs_credit_amount')
			?: ($order->get_meta('wc_cs_credit_amount')
			?: ($order->get_meta('_credits_used')
			?: ($order->get_meta('credits_used')
			?: ($order->get_meta('_fpc_credit_amount')
			?: $order->get_meta('fpc_credits_used'))))))));

		$has_credit_fee = false;
		$fee_credit_amount = 0.0;
		foreach ($order->get_fees() as $fee) {
			if (preg_match('/credit/i', $fee->get_name())) {
				$has_credit_fee = true;
				$fee_credit_amount += abs((float) $fee->get_total());
			}
		}

		$is_credit_order = $is_credit_method || ('yes' === $paid_with_credit) || ($credits_used_meta > 0) || $has_credit_fee;
		if (! $is_credit_order) {
			return;
		}

		$user_id = (int) $order->get_user_id();
		if (! $user_id) {
			return;
		}

		$order_total = round((float) $order->get_total(), 2);
		$amount = $order_total;
		if (! $is_credit_method && $credits_used_meta > 0) {
			$amount = round($credits_used_meta, 2);
		} elseif (! $is_credit_method && $fee_credit_amount > 0) {
			$amount = round($fee_credit_amount, 2);
		}

		if ($amount <= 0) {
			return;
		}

		$current_balance = self::get_user_credit_balance($user_id);
		$new_balance     = round($current_balance + $amount, 2);

		// Flag order as processed immediately
		$order->update_meta_data('_cwd_v2_credit_charge_processed', 'yes');
		$order->update_meta_data('_paid_with_credit', 'yes');
		$order->update_meta_data('_credit_charged_amount', $amount);
		$order->update_meta_data('_credit_balance_prior_order', $current_balance);
		$order->update_meta_data('_credit_balance_after_order', $new_balance);
		$order->save();

		// Update and sync balance across all meta keys, tables, and external CPT
		self::sync_user_credit_balance($user_id, $new_balance);

		// Record in transaction ledger
		if (class_exists('CWD_V2_Account_Ledger')) {
			CWD_V2_Account_Ledger::record(
				$user_id,
				CWD_V2_Account_Ledger::TYPE_CHARGE,
				$amount,
				'order',
				$order_id,
				sprintf(__('Order #%d placed on credit account', 'custom-woo-dashboard'), $order_id)
			);
		}

		// Ensure unpaid invoice is immediately generated in CWD_V2_Invoices
		if (class_exists('CWD_V2_Invoices')) {
			CWD_V2_Invoices::generate_invoice_for_order($order_id);
		}

		do_action('cwd_v2_credit_charged', $user_id, $amount, $new_balance, $order_id);
		do_action('wc_cs_credit_debited', $user_id, $amount, $new_balance, $order_id);
	}

	public static function reverse_credit_order_charge($order_id)
	{
		$order = wc_get_order($order_id);
		if (! $order) {
			return;
		}

		$method = strtolower((string) $order->get_payment_method());
		$is_credit_method = in_array($method, array('cwd_v2_credit_account', 'credits', 'wc_cs', 'wc_cs_credits', 'fpc_credits', 'wc_credit_gateway', 'credits_gateway', 'woocommerce_credits', 'trade_credit'), true)
			|| (strpos($method, 'credit') !== false && strpos($method, 'card') === false && strpos($method, 'stripe') === false);

		$paid_with_credit = $order->get_meta('_paid_with_credit');
		$is_credit_order  = $is_credit_method || ('yes' === $paid_with_credit) || $order->get_meta('_credit_charged_amount');

		if (! $is_credit_order || ! $order->get_meta('_cwd_v2_credit_charge_processed') || $order->get_meta('_cwd_v2_credit_charge_reversed')) {
			return;
		}

		$user_id = (int) $order->get_user_id();
		$charged = (float) $order->get_meta('_credit_charged_amount');
		$amount  = $charged > 0 ? $charged : round((float) $order->get_total(), 2);
		if (! $user_id || $amount <= 0) {
			return;
		}

		$current_balance = self::get_user_credit_balance($user_id);
		$new_balance     = max(0.0, round($current_balance - $amount, 2));
		self::sync_user_credit_balance($user_id, $new_balance);

		if (class_exists('CWD_V2_Account_Ledger')) {
			CWD_V2_Account_Ledger::record(
				$user_id,
				CWD_V2_Account_Ledger::TYPE_REFUND,
				$amount,
				'order',
				$order_id,
				sprintf(__('Credit charge reversed for order #%d', 'custom-woo-dashboard'), $order_id)
			);
		}

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
