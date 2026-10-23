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
define('CWD_V2_VERSION', '2.2.14');

// Include activator right away for the hook
require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-activator.php';
register_activation_hook(__FILE__, array('CWD_V2_Activator', 'activate'));

// Ensure WooCommerce phone field is set to required
add_action('init', function () {
	if (get_option('woocommerce_checkout_phone_field') !== 'required') {
		update_option('woocommerce_checkout_phone_field', 'required');
	}
}, 5);

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
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-credits-bridge.php';
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-trade-number-migrator.php';
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-blink-express.php';
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-shipment-tracking.php';

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
			CWD_V2_Credits_Bridge::init();
			CWD_V2_Blink_Express::init();
			CWD_V2_Shipment_Tracking::init();

			// Enqueue dashboard styles across all front-end pages
			add_action('wp_enqueue_scripts', 'cwd_v2_enqueue_frontend_styles', 20);

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

			// Instant Order Sync with Odoo ERP
			add_action('woocommerce_checkout_order_processed', 'cwd_v2_notify_odoo_order_event', 10, 1);
			add_action('woocommerce_payment_complete', 'cwd_v2_notify_odoo_order_event', 10, 1);
			add_action('woocommerce_order_status_changed', 'cwd_v2_notify_odoo_order_event', 10, 1);

			// Instant Customer & Trade Account Sync with Odoo ERP
			add_action('user_register', 'cwd_v2_notify_odoo_customer_event', 20, 1);
			add_action('profile_update', 'cwd_v2_notify_odoo_customer_event', 20, 1);
			add_action('set_user_role', function ($user_id) {
				cwd_v2_notify_odoo_customer_event($user_id);
			}, 20, 1);
		}
	}
}

if (! function_exists('cwd_v2_refresh_rewrite_rules')) {
	function cwd_v2_refresh_rewrite_rules()
	{
		$rewrite_version = '6';

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

if (! function_exists('cwd_v2_enqueue_frontend_styles')) {
	function cwd_v2_enqueue_frontend_styles()
	{
		// Only enqueue on account, checkout, cart, or single product pages to keep all other shop pages fast
		if (is_account_page() || is_checkout() || is_cart() || is_singular('product')) {
			wp_enqueue_style('cwd-v2-dashboard-style', CWD_V2_PLUGIN_URL . 'assets/css/style.css', array(), CWD_V2_VERSION);
		}
	}
}

// Redirect any /my-account/credits request to canonical /my-account/credit/
add_action('template_redirect', 'cwd_v2_canonical_credit_redirect');
if (! function_exists('cwd_v2_canonical_credit_redirect')) {
	function cwd_v2_canonical_credit_redirect()
	{
		if (function_exists('is_account_page') && is_account_page()) {
			global $wp;
			if (isset($wp->query_vars['credits'])) {
				wp_safe_redirect(wc_get_endpoint_url('credit', '', wc_get_page_permalink('myaccount')));
				exit;
			}
		}
	}
}

// Client-side linker for dropdown menus next to user avatar/pfp
add_action('wp_footer', 'cwd_v2_fix_user_menu_credits_links', 99);
if (! function_exists('cwd_v2_fix_user_menu_credits_links')) {
	function cwd_v2_fix_user_menu_credits_links()
	{
		if (! is_user_logged_in()) {
			return;
		}
		$myaccount_id = function_exists('wc_get_page_id') ? wc_get_page_id('myaccount') : get_option('woocommerce_myaccount_page_id');
		$myaccount_url = $myaccount_id ? get_permalink($myaccount_id) : home_url('/my-account/');
		$credit_url = esc_url(trailingslashit($myaccount_url) . 'credit/');
		?>
		<script id="cwd-v2-header-credits-linker">
		document.addEventListener('DOMContentLoaded', function() {
			var creditTargetUrl = <?php echo json_encode($credit_url); ?>;
			if (!creditTargetUrl) return;

			function fixCreditsLinks() {
				var links = document.querySelectorAll('a');
				for (var i = 0; i < links.length; i++) {
					var link = links[i];
					var text = (link.textContent || '').trim().toLowerCase();
					var href = link.getAttribute('href') || '';
					
					// If link text is "credits" or "credit" (e.g. in dropdown next to user pfp) or ends with /credits or /credits/
					if ((text === 'credits' || text === 'credit' || /[\/]credits[\/]?$/i.test(href)) && !href.includes('admin.php') && !href.includes('wp-admin')) {
						link.setAttribute('href', creditTargetUrl);
					}
				}
			}

			fixCreditsLinks();
			setTimeout(fixCreditsLinks, 400);
			setTimeout(fixCreditsLinks, 1200);
		});
		</script>
		<?php
	}
}

if (! function_exists('cwd_v2_notify_odoo_order_event')) {
	/**
	 * Instant order event notification to Odoo ERP.
	 * Dispatched asynchronously without blocking customer checkout.
	 *
	 * @param int $order_id WooCommerce Order ID
	 */
	function cwd_v2_notify_odoo_order_event($order_id)
	{
		if (! $order_id) {
			return;
		}

		$order = wc_get_order($order_id);
		$acc_num = '';
		if ($order instanceof WC_Order) {
			$acc_num = (string) $order->get_meta('ews_account_number');
			$user_id = $order->get_customer_id();
			if (empty($acc_num) && $user_id) {
				$acc_num = (string) get_user_meta($user_id, 'ews_account_number', true);
				if (empty($acc_num)) {
					$acc_num = (string) get_user_meta($user_id, 'cwd_ews_account_number', true);
				}
				if (empty($acc_num)) {
					$acc_num = 'EWS-' . str_pad((string) $user_id, 6, '0', STR_PAD_LEFT);
					update_user_meta($user_id, 'ews_account_number', $acc_num);
				}
				$order->update_meta_data('ews_account_number', $acc_num);
				$order->save();
			}
		}

		$odoo_url = defined('CWD_ODOO_BASE_URL') ? CWD_ODOO_BASE_URL : get_option('cwd_odoo_base_url', '');
		$odoo_url = apply_filters('cwd_v2_odoo_base_url', $odoo_url);
		if (empty($odoo_url)) {
			return;
		}

		$secret = defined('CWD_ODOO_SECRET_KEY') ? CWD_ODOO_SECRET_KEY : get_option('cwd_odoo_secret_key', '');
		$endpoint = rtrim($odoo_url, '/') . '/la_woocommerce/webhook/order';

		// Fire non-blocking asynchronous request so customer checkout is never delayed
		wp_remote_post($endpoint, array(
			'timeout'   => 5,
			'blocking'  => false,
			'headers'   => array(
				'Content-Type'  => 'application/json',
				'X-Odoo-Secret' => $secret,
			),
			'body'      => wp_json_encode(array(
				'order_id'           => (int) $order_id,
				'action'             => 'created',
				'ews_account_number' => $acc_num,
			)),
		));
	}
}

if (! function_exists('cwd_v2_attach_account_number_to_order')) {
	/**
	 * Automatically attach customer's authoritative EWS trade account number to new WooCommerce orders.
	 */
	function cwd_v2_attach_account_number_to_order($order, $data = null)
	{
		if (! $order instanceof WC_Order) {
			return;
		}
		$user_id = $order->get_customer_id();
		if ($user_id) {
			$acc = (string) get_user_meta($user_id, 'ews_account_number', true);
			if (empty($acc)) {
				$acc = (string) get_user_meta($user_id, 'cwd_ews_account_number', true);
			}
			if (empty($acc)) {
				$acc = 'EWS-' . str_pad((string) $user_id, 6, '0', STR_PAD_LEFT);
				update_user_meta($user_id, 'ews_account_number', $acc);
			}
			$order->update_meta_data('ews_account_number', $acc);
		}
	}
	add_action('woocommerce_checkout_create_order', 'cwd_v2_attach_account_number_to_order', 10, 2);
}

if (! function_exists('cwd_v2_notify_odoo_customer_event')) {
	/**
	 * Instant customer sync notification to Odoo ERP.
	 * Dispatched asynchronously without blocking page loads or user checkout.
	 *
	 * @param int $user_id WordPress User ID
	 */
	function cwd_v2_notify_odoo_customer_event($user_id)
	{
		if (! $user_id) {
			return;
		}

		$user = get_userdata($user_id);
		if (! $user) {
			return;
		}

		$odoo_url = defined('CWD_ODOO_BASE_URL') ? CWD_ODOO_BASE_URL : get_option('cwd_odoo_base_url', '');
		$odoo_url = apply_filters('cwd_v2_odoo_base_url', $odoo_url);
		if (empty($odoo_url)) {
			$odoo_url = 'https://lahoreanalytica-ewsupplies-uk-uat-38561474.dev.odoo.com';
		}

		$endpoint = rtrim($odoo_url, '/') . '/la_woocommerce/webhook/customer';

		$credit_limit   = class_exists('CWD_V2_Credit_Logic') ? CWD_V2_Credit_Logic::get_user_credit_limit($user_id) : (float) get_user_meta($user_id, '_credit_limit', true);
		$credit_balance = class_exists('CWD_V2_Credit_Logic') ? CWD_V2_Credit_Logic::get_user_credit_balance($user_id) : (float) get_user_meta($user_id, '_credit_balance', true);
		$due_date       = (string) get_user_meta($user_id, '_credit_due_date', true);
		$account_number = (string) get_user_meta($user_id, 'ews_account_number', true);
		if (empty($account_number)) {
			$account_number = 'EWS-' . str_pad($user_id, 6, '0', STR_PAD_LEFT);
			update_user_meta($user_id, 'ews_account_number', $account_number);
		}

		$roles = (array) $user->roles;
		$primary_role = in_array('credit_account', $roles, true) ? 'credit_account' : ($roles[0] ?? 'customer');

		wp_remote_post($endpoint, array(
			'timeout'  => 5,
			'blocking' => false,
			'headers'  => array(
				'Content-Type' => 'application/json',
			),
			'body'     => wp_json_encode(array(
				'user_id'            => (int) $user_id,
				'email'              => $user->user_email,
				'username'           => $user->user_login,
				'first_name'         => $user->first_name,
				'last_name'          => $user->last_name,
				'phone'              => (string) (get_user_meta($user_id, 'billing_phone', true) ?: ''),
				'company'            => (string) (get_user_meta($user_id, 'billing_company', true) ?: ''),
				'ews_account_number' => $account_number,
				'role'               => $primary_role,
				'roles'              => $roles,
				'credit_limit'       => $credit_limit,
				'credit_balance'     => $credit_balance,
				'credit_due_date'    => $due_date,
			)),
		));
	}
}

if (! function_exists('cwd_v2_ensure_wordfence_2fa_configuration')) {
	/**
	 * Automatically grant 2FA management capability to staff roles and enable 2FA for all roles in Wordfence.
	 * Eliminates 'Sorry, you are not allowed to access this page' and ensures 2FA setup appears on user profiles.
	 */
	function cwd_v2_ensure_wordfence_2fa_configuration()
	{
		// 1. Grant 2FA management capability to administrator and shop_manager
		foreach (array('administrator', 'shop_manager') as $role_slug) {
			$role = get_role($role_slug);
			if ($role && ! $role->has_cap('wf_manage_login_security')) {
				$role->add_cap('wf_manage_login_security');
			}
		}

		// 2. Programmatically configure Wordfence Login Security settings if available
		if (class_exists('\WordfenceLS\Controller_Settings')) {
			try {
				$settings = \WordfenceLS\Controller_Settings::shared();
				$allowed_roles = $settings->get_array(\WordfenceLS\Controller_Settings::KEY_2FA_ROLES, array());
				$all_roles = array_keys(wp_roles()->roles);

				// Ensure all user roles (including customer, credit_account, shop_manager) can use 2FA
				$diff = array_diff($all_roles, $allowed_roles);
				if (! empty($diff)) {
					$merged = array_unique(array_merge($allowed_roles, $all_roles));
					$settings->set_array(\WordfenceLS\Controller_Settings::KEY_2FA_ROLES, $merged);
				}

				if (! $settings->get_bool(\WordfenceLS\Controller_Settings::KEY_ENABLE_2FA_MANAGEMENT_SHORTCODE)) {
					$settings->set_bool(\WordfenceLS\Controller_Settings::KEY_ENABLE_2FA_MANAGEMENT_SHORTCODE, true);
				}
				if (! $settings->get_bool(\WordfenceLS\Controller_Settings::KEY_ENABLE_WOOCOMMERCE_INTEGRATION)) {
					$settings->set_bool(\WordfenceLS\Controller_Settings::KEY_ENABLE_WOOCOMMERCE_INTEGRATION, true);
				}
			} catch (\Exception $e) {
				// Non-blocking
			}
		}
	}
	add_action('admin_init', 'cwd_v2_ensure_wordfence_2fa_configuration');
}





