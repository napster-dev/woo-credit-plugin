<?php

/**
 * WooCommerce Blocks Payment Method Integration for Trade Credit Account
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (! defined('ABSPATH')) {
	exit;
}

final class CWD_V2_Blocks_Payment_Gateway extends AbstractPaymentMethodType
{
	protected $name = 'cwd_v2_credit_account';

	public function initialize()
	{
		$this->settings = get_option('woocommerce_cwd_v2_credit_account_settings', array());
	}

	public function is_active()
	{
		if (! is_user_logged_in()) {
			return false;
		}

		$user  = wp_get_current_user();
		$roles = (array) $user->roles;

		if (! in_array('credit_account', $roles, true) && ! current_user_can('manage_options') && ! current_user_can('manage_woocommerce')) {
			return false;
		}

		$enabled = $this->get_setting('enabled', 'yes');
		return 'yes' === $enabled;
	}

	public function get_payment_method_script_handles()
	{
		$script_url  = CWD_V2_PLUGIN_URL . 'assets/js/credit-account-blocks.js';
		$script_path = CWD_V2_PLUGIN_DIR . 'assets/js/credit-account-blocks.js';
		$version     = file_exists($script_path) ? filemtime($script_path) : '1.0.0';

		wp_register_script(
			'cwd-v2-credit-account-blocks',
			$script_url,
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			),
			$version,
			true
		);

		return array('cwd-v2-credit-account-blocks');
	}

	public function get_payment_method_data()
	{
		$user_id          = get_current_user_id();
		$credit_limit     = (float) get_user_meta($user_id, '_credit_limit', true);
		$credit_balance   = (float) get_user_meta($user_id, '_credit_balance', true);
		$available_credit = max(0, $credit_limit - $credit_balance);
		$due_date         = (string) get_user_meta($user_id, '_credit_due_date', true);

		$user            = wp_get_current_user();
		$roles           = (array) $user->roles;
		$has_credit_role = in_array('credit_account', $roles, true) || current_user_can('manage_options') || current_user_can('manage_woocommerce');

		return array(
			'title'               => $this->get_setting('title', __('Pay on Credit Account', 'custom-woo-dashboard')),
			'description'         => $this->get_setting('description', __('Your order will be charged to your trade credit account and settled according to your payment terms.', 'custom-woo-dashboard')),
			'supports'            => array('products'),
			'has_credit_role'     => $has_credit_role,
			'credit_limit'        => $credit_limit,
			'credit_balance'      => $credit_balance,
			'available_credit'    => $available_credit,
			'due_date'            => $due_date,
			'formatted_limit'     => wp_strip_all_tags(wc_price($credit_limit)),
			'formatted_balance'   => wp_strip_all_tags(wc_price($credit_balance)),
			'formatted_available' => wp_strip_all_tags(wc_price($available_credit)),
		);
	}
}
