<?php

/**
 * Plugin Name: Custom WooCommerce Dashboard
 * Description: Replaces the default WooCommerce My Account page with a custom dashboard and adds credit account features.
 * Version: 1.0.0
 * Author: Jules
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

define('CWD_V2_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CWD_V2_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include activator right away for the hook
require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-activator.php';
register_activation_hook(__FILE__, array('CWD_V2_Activator', 'activate'));

// Init Plugin when plugins are loaded
add_action('plugins_loaded', 'cwd_v2_init_plugin');
if (! function_exists('cwd_v2_init_plugin')) {
	function cwd_v2_init_plugin()
	{
		if (class_exists('WooCommerce')) {
			// Include files only when WooCommerce is confirmed to be loaded
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-shortcode.php';
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-endpoints.php';
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-payment-gateway.php';
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-credit-logic.php';
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-invoices.php';
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-account-ledger.php';
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-returns.php';
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-backorders.php';
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-admin-profile.php';
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-rest-api.php';
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-trade-applications.php';

			CWD_V2_Activator::register_roles();
			CWD_V2_Shortcode::init();
			CWD_V2_Endpoints::init();
			CWD_V2_Credit_Logic::init();
			CWD_V2_Invoices::init();
			CWD_V2_Returns::init();
			CWD_V2_Backorders::init();
			CWD_V2_Admin_Profile::init();
			CWD_V2_REST_API::init();
			CWD_V2_Trade_Applications::init();

			// Register trade application settings
			add_action('admin_init', array('CWD_V2_Trade_Applications', 'register_settings'));

			// Refresh rewrite rules after WordPress has initialized its rewrite object.
			add_action('init', 'cwd_v2_refresh_rewrite_rules', 20);

			// Create the invoices/ledger/trade application tables for sites where the plugin was
			// already active before this feature was added.
			$tables_version = '5';
			if (get_option('cwd_v2_tables_version') !== $tables_version) {
				CWD_V2_Activator::activate();
				update_option('cwd_v2_tables_version', $tables_version);
			}

			// Add custom payment gateway
			add_filter('woocommerce_payment_gateways', 'cwd_v2_add_payment_gateway');

			// Ensure payment gateway is enabled by default if not explicitly saved
			$gateway_settings = get_option('woocommerce_cwd_v2_credit_account_settings');
			if (! is_array($gateway_settings) || empty($gateway_settings)) {
				update_option('woocommerce_cwd_v2_credit_account_settings', array(
					'enabled'     => 'yes',
					'title'       => 'Pay on Credit Account',
					'description' => 'Your order will be charged to your trade credit account and settled according to your payment terms.',
				));
			}

			// Register WooCommerce Blocks payment method integration
			add_action('woocommerce_blocks_loaded', 'cwd_v2_register_credit_gateway_blocks_support');
		}
	}
}

if (! function_exists('cwd_v2_refresh_rewrite_rules')) {
	function cwd_v2_refresh_rewrite_rules()
	{
		$rewrite_version = '5';

		if (get_option('cwd_v2_rewrite_version') === $rewrite_version) {
			return;
		}

		flush_rewrite_rules();
		update_option('cwd_v2_rewrite_version', $rewrite_version);
	}
}

if (! function_exists('cwd_v2_add_payment_gateway')) {
	function cwd_v2_add_payment_gateway($methods)
	{
		$methods[] = 'WC_Gateway_Credit_Account_V2';
		return $methods;
	}
}

if (! function_exists('cwd_v2_register_credit_gateway_blocks_support')) {
	function cwd_v2_register_credit_gateway_blocks_support()
	{
		if (! class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
			return;
		}

		require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-blocks-payment-gateway.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ($payment_method_registry) {
				if (is_object($payment_method_registry) && method_exists($payment_method_registry, 'register')) {
					$payment_method_registry->register(new CWD_V2_Blocks_Payment_Gateway());
				}
			}
		);
	}
}
