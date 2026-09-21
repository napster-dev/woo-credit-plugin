<?php

/**
 * Custom Payment Gateway for Trade Credit Account
 */

if (! defined('ABSPATH')) {
	exit;
}

class WC_Gateway_Credit_Account_V2 extends WC_Payment_Gateway
{

	public function __construct()
	{
		$this->id                 = 'cwd_v2_credit_account';
		$this->icon               = '';
		$this->has_fields         = true;
		$this->method_title       = __('Pay on Credit Account', 'custom-woo-dashboard');
		$this->method_description = __('Allows approved trade and credit customers to pay using their agreed credit limit.', 'custom-woo-dashboard');
		$this->order_button_text  = __('Pay with Credit Account', 'custom-woo-dashboard');

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option('title');
		$this->description = $this->get_option('description');

		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
	}

	public function init_form_fields()
	{
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __('Enable/Disable', 'custom-woo-dashboard'),
				'type'    => 'checkbox',
				'label'   => __('Enable Pay on Credit Account', 'custom-woo-dashboard'),
				'default' => 'yes',
			),
			'title' => array(
				'title'       => __('Title', 'custom-woo-dashboard'),
				'type'        => 'text',
				'description' => __('Payment method title displayed during checkout.', 'custom-woo-dashboard'),
				'default'     => __('Pay on Credit Account', 'custom-woo-dashboard'),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __('Description', 'custom-woo-dashboard'),
				'type'        => 'textarea',
				'description' => __('Payment method description displayed when selected at checkout.', 'custom-woo-dashboard'),
				'default'     => __('Your order will be charged to your trade credit account and settled according to your payment terms.', 'custom-woo-dashboard'),
			),
		);
	}

	/**
	 * Check if the gateway is available for the current customer
	 */
	public function is_available()
	{
		if (! is_user_logged_in()) {
			return false;
		}

		$current_user = wp_get_current_user();
		$roles        = (array) $current_user->roles;

		// Only available for credit_account role or administrators/shop managers
		if (! in_array('credit_account', $roles, true) && ! current_user_can('manage_options') && ! current_user_can('manage_woocommerce')) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * Render payment fields at checkout (Modern flat UI, 2D SVGs, zero gradients, no emojis)
	 */
	public function payment_fields()
	{
		if (! is_user_logged_in()) {
			echo '<p style="color: #64748b; font-size: 14px;">' . esc_html__('Please log in to view credit details.', 'custom-woo-dashboard') . '</p>';
			return;
		}

		$user_id          = get_current_user_id();
		$credit_limit     = (float) get_user_meta($user_id, '_credit_limit', true);
		$credit_balance   = (float) get_user_meta($user_id, '_credit_balance', true);
		$available_credit = max(0, $credit_limit - $credit_balance);
		$due_date         = (string) get_user_meta($user_id, '_credit_due_date', true);

		$cart_total = 0.0;
		if (isset(WC()->cart) && WC()->cart) {
			$cart_total = (float) WC()->cart->get_total('edit');
		}

		$has_insufficient_credit = ($cart_total > $available_credit);
		?>
		<div class="cwd-credit-checkout-box" style="margin-top: 10px; margin-bottom: 12px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 16px;">
			<?php if (! empty($this->description)) : ?>
				<p style="margin: 0 0 12px 0; color: #475569; font-size: 13.5px; line-height: 1.45;">
					<?php echo wp_kses_post(wpautop(wptexturize($this->description))); ?>
				</p>
			<?php endif; ?>

			<!-- Stat Breakdown Grid -->
			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; margin-bottom: 12px;">
				<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px;">
					<span style="display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em;">
						<?php esc_html_e('Credit Limit', 'custom-woo-dashboard'); ?>
					</span>
					<span style="display: block; font-size: 16px; font-weight: 700; color: #0f172a; margin-top: 4px;">
						<?php echo wp_kses_post(wc_price($credit_limit)); ?>
					</span>
				</div>

				<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px;">
					<span style="display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em;">
						<?php esc_html_e('Current Balance', 'custom-woo-dashboard'); ?>
					</span>
					<span style="display: block; font-size: 16px; font-weight: 700; color: #dc2626; margin-top: 4px;">
						<?php echo wp_kses_post(wc_price($credit_balance)); ?>
					</span>
				</div>

				<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px;">
					<span style="display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em;">
						<?php esc_html_e('Available Credit', 'custom-woo-dashboard'); ?>
					</span>
					<span style="display: block; font-size: 16px; font-weight: 700; color: #16a34a; margin-top: 4px;">
						<?php echo wp_kses_post(wc_price($available_credit)); ?>
					</span>
				</div>

				<?php if ($due_date) : ?>
					<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px;">
						<span style="display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em;">
							<?php esc_html_e('Next Due Date', 'custom-woo-dashboard'); ?>
						</span>
						<span style="display: block; font-size: 14px; font-weight: 600; color: #334155; margin-top: 6px;">
							<?php echo esc_html($due_date); ?>
						</span>
					</div>
				<?php endif; ?>
			</div>

			<!-- Status Banner (Flat, No Gradients, 2D SVGs) -->
			<?php if ($has_insufficient_credit) : ?>
				<div style="display: flex; align-items: flex-start; gap: 10px; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 6px; padding: 10px 12px; color: #991b1b; font-size: 13px; line-height: 1.4;">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 1px;" aria-hidden="true">
						<circle cx="12" cy="12" r="10"></circle>
						<line x1="12" y1="8" x2="12" y2="12"></line>
						<line x1="12" y1="16" x2="12.01" y2="16"></line>
					</svg>
					<div>
						<strong><?php esc_html_e('Insufficient Available Credit:', 'custom-woo-dashboard'); ?></strong>
						<?php
						printf(
							/* translators: 1: available credit, 2: order total */
							esc_html__('Your available credit is %1$s, but this order is %2$s. Please settle your outstanding balance or choose another payment method.', 'custom-woo-dashboard'),
							wp_strip_all_tags(wc_price($available_credit)),
							wp_strip_all_tags(wc_price($cart_total))
						);
						?>
					</div>
				</div>
			<?php else : ?>
				<div style="display: flex; align-items: center; gap: 8px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 8px 12px; color: #166534; font-size: 13px;">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;" aria-hidden="true">
						<polyline points="20 6 9 17 4 12"></polyline>
					</svg>
					<span>
						<?php esc_html_e('Sufficient credit available. This order will be charged to your trade account upon confirmation.', 'custom-woo-dashboard'); ?>
					</span>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Validate fields before placing order
	 *
	 * @return bool
	 */
	public function validate_fields()
	{
		if (! is_user_logged_in()) {
			wc_add_notice(__('You must be logged in to use this payment method.', 'custom-woo-dashboard'), 'error');
			return false;
		}

		$user_id = get_current_user_id();
		$roles   = (array) wp_get_current_user()->roles;

		if (! in_array('credit_account', $roles, true) && ! current_user_can('manage_options') && ! current_user_can('manage_woocommerce')) {
			wc_add_notice(__('Your account is not approved for credit purchases.', 'custom-woo-dashboard'), 'error');
			return false;
		}

		// Prevent paying credit off with credit
		$credit_product_id = (int) get_option('cwd_v2_credit_payment_product_id');
		if ($credit_product_id && isset(WC()->cart) && WC()->cart) {
			foreach (WC()->cart->get_cart() as $cart_item) {
				if ((int) ($cart_item['product_id'] ?? 0) === $credit_product_id) {
					wc_add_notice(__('You cannot use the Credit Account to pay off your credit balance. Please select another payment method.', 'custom-woo-dashboard'), 'error');
					return false;
				}
			}
		}

		$credit_limit     = (float) get_user_meta($user_id, '_credit_limit', true);
		$credit_balance   = (float) get_user_meta($user_id, '_credit_balance', true);
		$available_credit = max(0, $credit_limit - $credit_balance);

		$cart_total = isset(WC()->cart) && WC()->cart ? (float) WC()->cart->get_total('edit') : 0.0;

		if ($cart_total > $available_credit) {
			wc_add_notice(
				sprintf(
					/* translators: 1: available credit, 2: order total */
					__('Insufficient available credit. Your available credit is %1$s, but your order total is %2$s.', 'custom-woo-dashboard'),
					wc_price($available_credit),
					wc_price($cart_total)
				),
				'error'
			);
			return false;
		}

		return true;
	}

	/**
	 * Process payment on order submission
	 *
	 * @param int $order_id
	 * @return array
	 */
	public function process_payment($order_id)
	{
		$order = wc_get_order($order_id);
		if (! $order) {
			return array('result' => 'fail');
		}

		$user_id = $order->get_user_id();
		if (! $user_id) {
			wc_add_notice(__('You must be logged in to use this payment method.', 'custom-woo-dashboard'), 'error');
			return array('result' => 'fail');
		}

		$order_total      = (float) $order->get_total();
		$credit_limit     = (float) get_user_meta($user_id, '_credit_limit', true);
		$credit_balance   = (float) get_user_meta($user_id, '_credit_balance', true);
		$available_credit = max(0, $credit_limit - $credit_balance);

		// Prevent paying credit off with credit
		$credit_product_id = (int) get_option('cwd_v2_credit_payment_product_id');
		foreach ($order->get_items() as $item) {
			if ($item->get_product_id() === $credit_product_id) {
				wc_add_notice(__('You cannot use the Credit Account to pay off your credit balance. Please select another payment method.', 'custom-woo-dashboard'), 'error');
				return array('result' => 'fail');
			}
		}

		if ($order_total > $available_credit) {
			wc_add_notice(
				sprintf(
					/* translators: 1: available credit, 2: order total */
					__('Insufficient available credit. Your available credit is %1$s, but your order total is %2$s.', 'custom-woo-dashboard'),
					wc_price($available_credit),
					wc_price($order_total)
				),
				'error'
			);
			return array('result' => 'fail');
		}

		// Flag order as paid on trade credit
		$order->update_meta_data('_paid_with_credit', 'yes');
		$order->update_meta_data('_credit_balance_at_order', $credit_balance);
		$order->save();

		// Mark order as processing
		$order->update_status(
			'processing',
			sprintf(
				/* translators: 1: available credit */
				__('Payment placed via Trade Credit Account. Available credit prior to charge: %s.', 'custom-woo-dashboard'),
				wp_strip_all_tags(wc_price($available_credit))
			)
		);

		// Reduce inventory stock levels
		wc_reduce_stock_levels($order_id);

		// Empty cart
		if (isset(WC()->cart) && WC()->cart) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url($order),
		);
	}
}
