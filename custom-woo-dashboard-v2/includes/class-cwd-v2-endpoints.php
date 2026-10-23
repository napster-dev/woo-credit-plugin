<?php

/**
 * Registers custom endpoints for WooCommerce My Account
 */

if (! defined('ABSPATH')) {
	exit;
}

class CWD_V2_Endpoints
{

	public static function init()
	{
		add_action('init', array(__CLASS__, 'add_endpoints'));
		add_filter('query_vars', array(__CLASS__, 'add_query_vars'));

		// Map endpoint titles.
		add_filter('woocommerce_endpoint_invoices_title', array(__CLASS__, 'invoices_title'));
		add_filter('woocommerce_endpoint_view-invoice_title', array(__CLASS__, 'view_invoice_title'));
		add_filter('woocommerce_endpoint_returns_title', array(__CLASS__, 'returns_title'));
		add_filter('woocommerce_endpoint_statements_title', array(__CLASS__, 'statements_title'));
		add_filter('woocommerce_endpoint_credit_title', array(__CLASS__, 'credit_title'));
		add_filter('woocommerce_endpoint_credits_title', array(__CLASS__, 'credit_title'));
		add_filter('woocommerce_endpoint_change-password_title', array(__CLASS__, 'change_password_title'));
		add_filter('woocommerce_endpoint_live-orders_title', array(__CLASS__, 'live_orders_title'));
		add_filter('woocommerce_endpoint_back-orders_title', array(__CLASS__, 'back_orders_title'));
		add_filter('woocommerce_endpoint_order-history_title', array(__CLASS__, 'order_history_title'));
		add_filter('woocommerce_endpoint_track-order_title', array(__CLASS__, 'track_order_title'));
		add_filter('woocommerce_endpoint_wfls-2fa_title', array(__CLASS__, 'wfls_2fa_title'));
		add_action('woocommerce_account_wfls-2fa_endpoint', array(__CLASS__, 'endpoint_wfls_2fa'));

		// Fix endpoint URLs and account menu items linking for credits
		add_filter('woocommerce_get_endpoint_url', array(__CLASS__, 'filter_endpoint_url'), 10, 4);
		add_filter('woocommerce_account_menu_items', array(__CLASS__, 'filter_account_menu_items'), 9999);
		add_filter('wp_nav_menu_objects', array(__CLASS__, 'filter_nav_menu_items'), 9999);
		add_filter('woodmart_my_account_links', array(__CLASS__, 'filter_account_menu_items'), 9999);

		// Hide Wordfence 2FA from header and dropdown menus via CSS
		add_action('wp_head', array(__CLASS__, 'hide_wordfence_dropdown_css'), 999);

		// Render Wordfence 2FA on the account details page at the bottom
		add_action('woocommerce_after_edit_account_form', array(__CLASS__, 'render_wordfence_2fa_section'), 99);

		// Ensure endpoints are flushed once after this update
		if ('yes' !== get_option('cwd_v2_endpoints_flushed_wfls_v4')) {
			flush_rewrite_rules(false);
			update_option('cwd_v2_endpoints_flushed_wfls_v4', 'yes');
		}
	}

	public static function hide_wordfence_dropdown_css()
	{
		echo '<style id="cwd-hide-2fa-dropdown">
			.woocommerce-MyAccount-navigation-link--wfls-2fa,
			.woocommerce-MyAccount-navigation-link--wfls-passkeys,
			.woocommerce-MyAccount-navigation-link--wordfence-2fa,
			.woocommerce-MyAccount-navigation-link--2fa,
			.menu-item-wfls-2fa,
			.woodmart-my-account-links a[href*="wfls"],
			.woodmart-my-account-links a[href*="2fa"],
			.wd-dropdown-my-account a[href*="wfls"],
			.wd-dropdown-my-account a[href*="2fa"],
			.sub-menu-dropdown a[href*="wfls"],
			.sub-menu-dropdown a[href*="2fa"],
			.wd-sub-menu a[href*="wfls"],
			.wd-sub-menu a[href*="2fa"],
			.my-account-dropdown a[href*="wfls"],
			.my-account-dropdown a[href*="2fa"] {
				display: none !important;
			}
		</style>';
	}

	public static function render_wordfence_2fa_section()
	{
		static $rendered = false;
		if ($rendered) {
			return;
		}
		$rendered = true;

		echo '<div class="cwd-v2-2fa-section" style="margin-top: 35px; border-top: 2px solid #e2e8f0; padding-top: 25px; clear: both;">';
		echo '<h3 style="font-size: 18px; font-weight: 700; color: #1e293b; margin-bottom: 8px;">' . esc_html__('Two-Factor Authentication (2FA)', 'custom-woo-dashboard') . '</h3>';
		echo '<p style="font-size: 13.5px; color: #64748b; margin-bottom: 18px;">' . esc_html__('Manage your Wordfence two-factor authentication security settings below.', 'custom-woo-dashboard') . '</p>';

		$sc_out = do_shortcode('[wordfence_2fa_management]');
		if (! empty(trim($sc_out)) && false === strpos($sc_out, '[wordfence_2fa_management]')) {
			echo $sc_out;
		} else {
			echo '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px 20px;">';
			echo '<strong style="color: #0f172a; font-size: 14.5px; display: block; margin-bottom: 4px;">' . esc_html__('Two-Factor Authentication Protection', 'custom-woo-dashboard') . '</strong>';
			echo '<p style="color: #64748b; font-size: 13px; margin: 0; line-height: 1.5;">' . esc_html__('Your account is protected by Wordfence Login Security. Two-factor verification will be prompted at sign-in when enforced for your account.', 'custom-woo-dashboard') . '</p>';
			echo '</div>';
		}

		echo '</div>';
	}

	public static function add_endpoints()
	{
		add_rewrite_endpoint('invoices', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('view-invoice', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('returns', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('statements', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('credit', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('credits', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('change-password', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('live-orders', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('back-orders', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('order-history', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('track-order', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('wfls-2fa', EP_ROOT | EP_PAGES);
	}

	public static function add_query_vars($vars)
	{
		$vars[] = 'invoices';
		$vars[] = 'view-invoice';
		$vars[] = 'returns';
		$vars[] = 'statements';
		$vars[] = 'credit';
		$vars[] = 'credits';
		$vars[] = 'change-password';
		$vars[] = 'live-orders';
		$vars[] = 'back-orders';
		$vars[] = 'order-history';
		$vars[] = 'track-order';
		$vars[] = 'wfls-2fa';
		return $vars;
	}

	public static function wfls_2fa_title()
	{
		return __('Two-Factor Authentication', 'custom-woo-dashboard');
	}

	public static function endpoint_wfls_2fa()
	{
		echo '<div class="cwd-v2-section-heading" style="margin-bottom: 24px;">';
		echo '<h3 style="margin: 0 0 6px 0; font-size: 20px; font-weight: 700; color: #0f172a;">' . esc_html__('Two-Factor Authentication (2FA)', 'custom-woo-dashboard') . '</h3>';
		echo '<p style="margin: 0; font-size: 13.5px; color: #64748b;">' . esc_html__('Protect your account by managing your Wordfence two-factor authentication security settings.', 'custom-woo-dashboard') . '</p>';
		echo '</div>';



		$rendered_sc = do_shortcode('[wordfence_2fa_management]');
		if (! empty(trim($rendered_sc)) && false === strpos($rendered_sc, '[wordfence_2fa_management]')) {
			echo $rendered_sc;
		} else {
			echo '<div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 22px;">';
			echo '<h4 style="margin: 0 0 10px 0; font-size: 16px; color: #1e293b;">' . esc_html__('Two-Factor Authentication Setup', 'custom-woo-dashboard') . '</h4>';
			echo '<p style="font-size: 13.5px; color: #64748b; line-height: 1.6; margin-bottom: 12px;">' . esc_html__('To enable Two-Factor Authentication for your account, please ensure "2FA Management Shortcode" and "WooCommerce Account page" are enabled in your WordPress dashboard under Wordfence > Login Security > Settings.', 'custom-woo-dashboard') . '</p>';
			if (current_user_can('manage_options')) {
				echo '<a href="' . esc_url(admin_url('admin.php?page=WFLS#top#settings')) . '" class="button" style="padding: 8px 16px; border-radius: 6px;">' . esc_html__('Configure Wordfence Login Security Settings', 'custom-woo-dashboard') . '</a>';
			}
			echo '</div>';
		}
	}

	public static function invoices_title()
	{
		return __('Invoices', 'custom-woo-dashboard');
	}

	public static function view_invoice_title()
	{
		return __('Invoice', 'custom-woo-dashboard');
	}

	public static function returns_title()
	{
		return __('Returns', 'custom-woo-dashboard');
	}

	public static function statements_title()
	{
		return __('Account Statements', 'custom-woo-dashboard');
	}

	public static function credit_title()
	{
		return __('Credit Dashboard', 'custom-woo-dashboard');
	}

	public static function change_password_title()
	{
		return __('Change Password', 'custom-woo-dashboard');
	}

	public static function live_orders_title()
	{
		return __('Live Orders', 'custom-woo-dashboard');
	}

	public static function back_orders_title()
	{
		return __('Back Orders', 'custom-woo-dashboard');
	}

	public static function order_history_title()
	{
		return __('Order History', 'custom-woo-dashboard');
	}

	public static function track_order_title()
	{
		return __('Track Order', 'custom-woo-dashboard');
	}

	private static $filtering_endpoint = false;

	/**
	 * Ensure any call to wc_get_endpoint_url for 'credits' or related variants
	 * resolves safely to the credit dashboard endpoint URL without recursion.
	 */
	public static function filter_endpoint_url($url, $endpoint, $value, $permalink)
	{
		if (self::$filtering_endpoint) {
			return $url;
		}

		if (in_array($endpoint, array('credits', 'wc_cs_credits', 'my-credits'), true)) {
			self::$filtering_endpoint = true;
			$target = str_replace('/credits', '/credit', (string) $url);
			if (strpos($target, '/credit') === false && function_exists('wc_get_page_permalink')) {
				$myaccount = wc_get_page_permalink('myaccount');
				if ($myaccount) {
					$target = trailingslashit($myaccount) . 'credit/';
				}
			}
			self::$filtering_endpoint = false;
			return $target;
		}

		return $url;
	}

	/**
	 * Remap 'credits' menu items to 'credit' in WooCommerce account menus,
	 * and remove Wishlist & Wordfence 2FA from user account menu dropdown.
	 */
	public static function filter_account_menu_items($items)
	{
		if (! is_array($items)) {
			return $items;
		}

		if (isset($items['credits'])) {
			$title = $items['credits'];
			unset($items['credits']);
			$items['credit'] = $title;
		}

		// Remove wishlist and 2FA from user menu dropdown
		$remove_keys = array('wishlist', 'tinv_wishlist', 'yith_wcwl', 'wordfence-2fa', '2fa', 'two-factor-auth', 'wfls-2fa', 'wfls-passkeys', 'wfls');
		foreach ($remove_keys as $k) {
			unset($items[$k]);
		}

		foreach (array_keys($items) as $key) {
			$lower = strtolower((string) $key);
			if (strpos($lower, 'wishlist') !== false || strpos($lower, 'wordfence') !== false || strpos($lower, '2fa') !== false || strpos($lower, 'wfls') !== false) {
				unset($items[$key]);
			}
		}

		return $items;
	}

	/**
	 * Ensure navigation menu items pointing to /my-account/credits point safely to /my-account/credit/,
	 * and remove Wishlist and Wordfence 2FA from dropdown navigation menus.
	 */
	public static function filter_nav_menu_items($sorted_menu_items)
	{
		if (! is_array($sorted_menu_items)) {
			return $sorted_menu_items;
		}

		$filtered = array();
		foreach ($sorted_menu_items as $item) {
			if (! is_object($item) || empty($item->url)) {
				$filtered[] = $item;
				continue;
			}
			$url   = strtolower((string) $item->url);
			$title = strtolower((string) ($item->title ?? ''));

			// Exclude wishlist and wordfence 2fa from dropdowns
			if (strpos($url, 'wishlist') !== false || strpos($title, 'wishlist') !== false ||
				strpos($url, 'wordfence') !== false || strpos($url, 'wfls') !== false ||
				strpos($title, '2fa') !== false || strpos($title, 'two-factor') !== false) {
				continue;
			}

			if (preg_match('#/my-account/credits/?$#i', (string) $item->url)) {
				$item->url = preg_replace('#/my-account/credits/?$#i', '/my-account/credit/', (string) $item->url);
			}
			$filtered[] = $item;
		}
		return $filtered;
	}
}
