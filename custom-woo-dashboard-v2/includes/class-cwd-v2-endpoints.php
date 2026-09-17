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

		// Map endpoint titles
		add_filter('woocommerce_endpoint_invoices_title', array(__CLASS__, 'invoices_title'));
		add_filter('woocommerce_endpoint_returns_title', array(__CLASS__, 'returns_title'));
		add_filter('woocommerce_endpoint_credit_title', array(__CLASS__, 'credit_title'));
		add_filter('woocommerce_endpoint_change-password_title', array(__CLASS__, 'change_password_title'));
		add_filter('woocommerce_endpoint_live-orders_title', array(__CLASS__, 'live_orders_title'));
		add_filter('woocommerce_endpoint_back-orders_title', array(__CLASS__, 'back_orders_title'));
		add_filter('woocommerce_endpoint_order-history_title', array(__CLASS__, 'order_history_title'));
		add_filter('woocommerce_endpoint_track-order_title', array(__CLASS__, 'track_order_title'));
	}

	public static function add_endpoints()
	{
		add_rewrite_endpoint('invoices', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('returns', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('credit', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('change-password', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('live-orders', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('back-orders', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('order-history', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('track-order', EP_ROOT | EP_PAGES);
	}

	public static function add_query_vars($vars)
	{
		$vars[] = 'invoices';
		$vars[] = 'returns';
		$vars[] = 'credit';
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

	public static function returns_title()
	{
		return __('Returns', 'custom-woo-dashboard');
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
}
