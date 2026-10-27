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
define('CWD_V2_VERSION', '2.2.18');

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
		if (! class_exists('WooCommerce')) {
			return;
		}

		try {
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
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-saved-baskets.php';
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-registration.php';

			CWD_V2_Activator::register_roles();
			CWD_V2_Shortcode::init();
			CWD_V2_Registration::init();
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
			CWD_V2_Saved_Baskets::init();

			// Enqueue dashboard styles across all front-end pages
			add_action('wp_enqueue_scripts', 'cwd_v2_enqueue_frontend_styles', 20);

			// Register trade application settings
			add_action('admin_init', array('CWD_V2_Trade_Applications', 'register_settings'));

			// Refresh rewrite rules after WordPress has initialized its rewrite object.
			add_action('init', 'cwd_v2_refresh_rewrite_rules', 20);

			// Create the invoices/ledger/trade application/saved baskets tables for sites where the plugin was
			// already active before this feature was added.
			$tables_version = '6';
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

			// Instant Order Sync with Odoo ERP (Only fires when payment completed or status changed)
			add_action('woocommerce_payment_complete', 'cwd_v2_notify_odoo_order_event', 10, 1);
			add_action('woocommerce_order_status_changed', 'cwd_v2_notify_odoo_order_event', 10, 1);

			// Instant Customer & Trade Account Sync with Odoo ERP
			add_action('user_register', 'cwd_v2_notify_odoo_customer_event', 20, 1);
			add_action('profile_update', 'cwd_v2_notify_odoo_customer_event', 20, 1);
			add_action('set_user_role', function ($user_id) {
				cwd_v2_notify_odoo_customer_event($user_id);
			}, 20, 1);

			// Render Delivery Estimate on Customer Order Details Page
			add_action('woocommerce_order_details_before_order_table', 'cwd_v2_render_order_details_delivery_estimate', 5, 1);

			// Render Odoo Invoice Card on Customer Order Details Page
			add_action('woocommerce_order_details_after_order_table', 'cwd_v2_render_order_details_odoo_invoice', 10, 1);
		} catch (\Throwable $e) {
			error_log('CWD Plugin Initialization Error: ' . $e->getMessage());
		}
	}
}

if (! function_exists('cwd_v2_render_order_details_delivery_estimate')) {
	function cwd_v2_render_order_details_delivery_estimate($order)
	{
		if (! $order) {
			return;
		}

		$status = $order->get_status();
		$custom_estimate = $order->get_meta('_delivery_estimate');
		$shipping_title = $order->get_shipping_method();
		$is_collection = false;

		if (! empty($shipping_title) && (stripos($shipping_title, 'collection') !== false || stripos($shipping_title, 'pickup') !== false || stripos($shipping_title, 'pick up') !== false)) {
			$is_collection = true;
		} else {
			foreach ($order->get_shipping_methods() as $shipping_item) {
				$method_id = $shipping_item->get_method_id();
				if (strpos($method_id, 'pickup') !== false || strpos($method_id, 'local') !== false) {
					$is_collection = true;
					break;
				}
			}
		}

		$badge_title = $is_collection ? __('COLLECTION ESTIMATE', 'custom-woo-dashboard') : __('DELIVERY ESTIMATE', 'custom-woo-dashboard');
		$estimate_html = '';

		if (! empty($custom_estimate)) {
			$estimate_html = '<span style="font-weight:600; color:#0f172a;">' . esc_html($custom_estimate) . '</span>';
		} elseif ('completed' === $status) {
			$completed_date = $order->get_date_completed();
			$date_str = $completed_date ? wc_format_datetime($completed_date, 'j M Y') : '';
			$label = $is_collection
				? ($date_str ? sprintf(__('Collected on %s', 'custom-woo-dashboard'), $date_str) : __('Collected from Warehouse', 'custom-woo-dashboard'))
				: ($date_str ? sprintf(__('Delivered on %s', 'custom-woo-dashboard'), $date_str) : __('Delivered', 'custom-woo-dashboard'));
			$estimate_html = '<span style="color:#16a34a; font-weight:700; display:inline-flex; align-items:center;">' .
				'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px; margin-right:5px;"><polyline points="20 6 9 17 4 12"/></svg>' .
				esc_html($label) .
				'</span>';
		} elseif ($is_collection) {
			$estimate_html = '<span style="color:#0284c7; font-weight:600; display:inline-flex; align-items:center;">' .
				'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px; margin-right:6px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>' .
				esc_html__('Collection from EWS Warehouse (Ready during warehouse hours)', 'custom-woo-dashboard') .
				'</span>';
		} elseif (class_exists('CWD_V2_Shipment_Tracking') && ($tracking_items = CWD_V2_Shipment_Tracking::get_tracking_items($order->get_id())) && ! empty($tracking_items[0]['custom_link'])) {
			$provider = ! empty($tracking_items[0]['provider_label']) ? $tracking_items[0]['provider_label'] : __('Courier', 'custom-woo-dashboard');
			$estimate_html = '<span style="color:#0284c7; font-weight:600;"><a href="' . esc_url($tracking_items[0]['custom_link']) . '" target="_blank" rel="noopener" style="color:#0284c7; text-decoration:none;">' .
				esc_html(sprintf(__('Dispatched via %s - Track Consignment', 'custom-woo-dashboard'), $provider)) . ' &rarr;</a></span>';
		} elseif (in_array($status, array('processing', 'on-hold'), true)) {
			$estimate_html = '<span style="color:#0f172a; font-weight:500;">' . esc_html__('In preparation – Standard Delivery (1–2 working days)', 'custom-woo-dashboard') . '</span>';
		} else {
			$estimate_html = '<span style="color:#64748b; font-size:13px;">' . esc_html__('Estimated at dispatch', 'custom-woo-dashboard') . '</span>';
		}

		$icon_svg = $is_collection
			? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>'
			: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>';

		echo '<div class="cwd-view-order-estimate-card" style="background:#ffffff; border:1px solid #e2e8f0; border-left:4px solid #0284c7; border-radius:8px; padding:16px 20px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.03); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">';
		echo '<div style="display:flex; align-items:center; gap:8px;">';
		echo $icon_svg;
		echo '<span style="font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:#0284c7;">' . esc_html($badge_title) . '</span>';
		echo '</div>';
		echo '<div>' . $estimate_html . '</div>';
		echo '</div>';
	}
}

if (! function_exists('cwd_v2_render_order_details_odoo_invoice')) {
	function cwd_v2_render_order_details_odoo_invoice($order)
	{
		if (! $order) {
			return;
		}

		$odoo_inv_url = $order->get_meta('_odoo_invoice_url');
		$odoo_inv_num = $order->get_meta('_odoo_invoice_number') ?: $order->get_meta('odoo_invoice_number');
		$odoo_id      = $order->get_meta('_odoo_invoice_id');
		$inv_id       = 0;

		if (class_exists('CWD_V2_Invoices')) {
			$inv_row = CWD_V2_Invoices::get_invoices_for_order($order->get_id());
			if ($inv_row) {
				$inv_id = (int) $inv_row->id;
				if (empty($odoo_inv_url) && ! empty($inv_row->document_url)) {
					$odoo_inv_url = $inv_row->document_url;
				}
				if (empty($odoo_inv_num) && ! empty($inv_row->invoice_number)) {
					$odoo_inv_num = $inv_row->invoice_number;
				}
			}
		}

		if (empty($odoo_inv_url) && ! empty($odoo_id)) {
			$odoo_base = defined('CWD_ODOO_BASE_URL') ? CWD_ODOO_BASE_URL : get_option('cwd_odoo_base_url', '');
			$odoo_base = apply_filters('cwd_v2_odoo_base_url', $odoo_base);
			if (! empty($odoo_base)) {
				$odoo_inv_url = rtrim($odoo_base, '/') . '/my/invoices/' . rawurlencode($odoo_id) . '?report_type=pdf&download=true';
			}
		}

		// Suppress broken Xero or localhost document URLs
		if (! empty($odoo_inv_url) && (stripos($odoo_inv_url, 'xero') !== false || stripos($odoo_inv_url, 'localhost') !== false)) {
			$odoo_inv_url = '';
		}

		// Always suppress broken third-party Xero links and cluttering tax buttons on order details
		echo '<style>
			a[href*="xero-invoice"], a[href*="xero"], .xero-invoice-button, .wc-xero-invoice, a.invoice-button[href*="xero"], a[href*="download-tax"], .tax-document-button {
				display: none !important;
			}
		</style>';
		echo '<script>
			(function() {
				function hideBadLinks() {
					var links = document.querySelectorAll("a[href*=\'xero-invoice\'], a[href*=\'xero\'], .xero-invoice-button, .wc-xero-invoice, a[href*=\'download-tax\']");
					links.forEach(function(el) {
						if (!el.closest(".cwd-view-order-invoice-card")) {
							el.style.setProperty("display", "none", "important");
						}
					});
				}
				if (document.readyState === "loading") {
					document.addEventListener("DOMContentLoaded", hideBadLinks);
				} else {
					hideBadLinks();
				}
				setTimeout(hideBadLinks, 400);
				setTimeout(hideBadLinks, 1200);
			})();
		</script>';

		if (empty($odoo_inv_num) && empty($odoo_inv_url) && $inv_id <= 0) {
			return;
		}

		$download_url = class_exists('CWD_V2_Invoices') ? CWD_V2_Invoices::get_invoice_download_url($inv_id, $order->get_id()) : $odoo_inv_url;
		if (empty($download_url)) {
			return;
		}

		echo '<div class="cwd-view-order-invoice-card" style="background:#ffffff; border:1px solid #e2e8f0; border-left:4px solid #0284c7; border-radius:8px; padding:16px 20px; margin-top:20px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.03); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">';
		echo '<div style="display:flex; align-items:center; gap:12px;">';
		echo '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>';
		echo '<div>';
		echo '<span style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#0284c7;">' . esc_html__('OFFICIAL VAT INVOICE', 'custom-woo-dashboard') . '</span>';
		if (! empty($odoo_inv_num)) {
			echo '<span style="font-size:15px; font-weight:700; color:#0f172a;">' . esc_html($odoo_inv_num) . '</span>';
		}
		echo '</div>';
		echo '</div>';
		echo '<div>';
		echo '<a href="' . esc_url($download_url) . '" class="button" style="background:#0284c7; color:#fff; border-color:#0284c7; display:inline-flex; align-items:center; gap:6px; font-weight:600; font-size:13px; padding:8px 16px; text-decoration:none; border-radius:6px; box-shadow:0 1px 2px rgba(2,132,199,0.2);" download>';
		echo '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>';
		echo esc_html__('Download Invoice', 'custom-woo-dashboard');
		echo '</a>';
		echo '</div>';
		echo '</div>';
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

		try {
			if (! function_exists('wc_get_order')) {
				return;
			}
			$order = wc_get_order($order_id);
			if (! $order || ! is_a($order, 'WC_Order')) {
				return;
			}

			// Only notify Odoo if payment has successfully completed on website
			$status = strtolower((string) $order->get_status());
			$allowed_paid_statuses = array('processing', 'completed');
			$is_paid_credit = in_array($order->get_payment_method(), array('cwd_v2_credit_account', 'trade_credit'), true) && in_array($status, array('processing', 'completed', 'on-hold'), true);
			if (! in_array($status, $allowed_paid_statuses, true) && ! $is_paid_credit) {
				return;
			}

			$acc_num = '';
			if ($order && is_a($order, 'WC_Order')) {
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
		} catch (\Throwable $t) {
			error_log('CWD Odoo Order Event Error: ' . $t->getMessage());
		}
	}
}

if (! function_exists('cwd_v2_attach_account_number_to_order')) {
	/**
	 * Automatically attach customer's authoritative EWS trade account number to new WooCommerce orders.
	 */
	function cwd_v2_attach_account_number_to_order($order, $data = null)
	{
		try {
			if (! $order || ! is_a($order, 'WC_Order')) {
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
		} catch (\Throwable $t) {
			error_log('CWD Attach Account Error: ' . $t->getMessage());
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

		try {
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
		} catch (\Throwable $t) {
			error_log('CWD Odoo Customer Event Error: ' . $t->getMessage());
		}
	}
}





