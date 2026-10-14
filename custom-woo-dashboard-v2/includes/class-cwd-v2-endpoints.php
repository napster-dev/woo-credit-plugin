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

		// Fix endpoint URLs and account menu items linking for credits
		add_filter('woocommerce_get_endpoint_url', array(__CLASS__, 'filter_endpoint_url'), 10, 4);
		add_filter('woocommerce_account_menu_items', array(__CLASS__, 'filter_account_menu_items'), 20);
		add_filter('wp_nav_menu_objects', array(__CLASS__, 'filter_nav_menu_items'), 20);
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
		return $vars;
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
	 * Remap 'credits' menu items to 'credit' in WooCommerce account menus
	 */
	public static function filter_account_menu_items($items)
	{
		if (is_array($items) && isset($items['credits'])) {
			$title = $items['credits'];
			unset($items['credits']);
			$items['credit'] = $title;
		}
		return $items;
	}

	/**
	 * Ensure navigation menu items pointing to /my-account/credits point safely to /my-account/credit/
	 */
	public static function filter_nav_menu_items($sorted_menu_items)
	{
		if (! is_array($sorted_menu_items)) {
			return $sorted_menu_items;
		}

		foreach ($sorted_menu_items as $item) {
			if (! is_object($item) || empty($item->url)) {
				continue;
			}
			$url = (string) $item->url;
			if (preg_match('#/my-account/credits/?$#i', $url)) {
				$item->url = preg_replace('#/my-account/credits/?$#i', '/my-account/credit/', $url);
			}
		}
		return $sorted_menu_items;
	}
}
