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
			.cwd-passkeys-wrapper,
			#wfls-passkeys,
			.wfls-passkeys,
			.wfls-passkeys-container,
			.wfls-notice-passkeys-disabled,
			#wfls-passkeys-management,
			.woocommerce-MyAccount-navigation-link--wfls-passkeys,
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

		try {
			echo '<div class="cwd-v2-2fa-section" style="margin-top: 35px; border-top: 2px solid #e2e8f0; padding-top: 25px; clear: both;">';
			echo '<h3 style="font-size: 18px; font-weight: 700; color: #1e293b; margin-bottom: 8px;">' . esc_html__('Two-Factor Authentication (2FA)', 'custom-woo-dashboard') . '</h3>';
			echo '<p style="font-size: 13.5px; color: #64748b; margin-bottom: 14px;">' . esc_html__('Protect your account with an authenticator app (Google Authenticator, Microsoft Authenticator, Authy).', 'custom-woo-dashboard') . '</p>';

			$sc_out = do_shortcode('[wordfence_2fa_management]');
			$has_rendered = false;

			if (! empty(trim($sc_out)) && false === strpos($sc_out, '[wordfence_2fa_management]')) {
				echo $sc_out;
				$has_rendered = true;
			}

			if (! $has_rendered) {
				$wfls_url = wc_get_account_endpoint_url('wfls-2fa');
				echo '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px 20px;">';
				echo '<strong style="color: #0f172a; font-size: 14.5px; display: block; margin-bottom: 4px;">' . esc_html__('Two-Factor Authentication Protection', 'custom-woo-dashboard') . '</strong>';
				echo '<p style="color: #64748b; font-size: 13px; margin: 0 0 12px 0; line-height: 1.5;">' . esc_html__('Enhance your account security with two-factor authentication.', 'custom-woo-dashboard') . '</p>';
				echo '<a href="' . esc_url($wfls_url) . '" class="button cwd-btn-primary" style="background: #008060; color: #fff; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 13px; display: inline-block;">' . esc_html__('Configure 2FA Settings', 'custom-woo-dashboard') . ' &rarr;</a>';
				echo '</div>';
			}

			echo '</div>';
		} catch (\Throwable $e) {
			error_log('CWD 2FA Render Error: ' . $e->getMessage());
		}
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
		return __('Two-Factor Authentication (2FA)', 'custom-woo-dashboard');
	}

	public static function endpoint_wfls_2fa()
	{
		try {
			echo '<div class="cwd-v2-section-heading" style="margin-bottom: 24px;">';
			echo '<h3 style="margin: 0 0 6px 0; font-size: 20px; font-weight: 700; color: #0f172a;">' . esc_html__('Two-Factor Authentication (2FA)', 'custom-woo-dashboard') . '</h3>';
			echo '<p style="margin: 0; font-size: 13.5px; color: #64748b;">' . esc_html__('Protect your account with mobile authenticator apps (Google Authenticator, Microsoft Authenticator, Authy).', 'custom-woo-dashboard') . '</p>';
			echo '</div>';

			$rendered_2fa = do_shortcode('[wordfence_2fa_management]');
			$has_rendered = false;

			if (! empty(trim($rendered_2fa)) && false === strpos($rendered_2fa, '[wordfence_2fa_management]')) {
				echo '<div class="cwd-2fa-wrapper">' . $rendered_2fa . '</div>';
				$has_rendered = true;
			}

			if (! $has_rendered) {
				echo '<div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 22px;">';
				echo '<h4 style="margin: 0 0 10px 0; font-size: 16px; color: #1e293b;">' . esc_html__('Two-Factor Authentication Setup', 'custom-woo-dashboard') . '</h4>';
				echo '<p style="font-size: 13.5px; color: #64748b; line-height: 1.6; margin-bottom: 12px;">' . esc_html__('To enable Two-Factor Authentication for customer accounts, please ensure 2FA is enabled for Customer roles under Wordfence > Login Security > Settings.', 'custom-woo-dashboard') . '</p>';
				if (current_user_can('manage_options')) {
					echo '<a href="' . esc_url(admin_url('admin.php?page=WFLS#top#settings')) . '" class="button button-primary" style="padding: 8px 16px; border-radius: 6px;">' . esc_html__('Configure Wordfence Login Security Settings', 'custom-woo-dashboard') . '</a>';
				}
				echo '</div>';
			}
		} catch (\Throwable $e) {
			error_log('CWD WFLS 2FA Endpoint Error: ' . $e->getMessage());
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
	 * remove Wishlist, and ensure Two-Factor Auth (2FA) is clearly accessible in customer navigation.
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

		// Remove wishlist from user menu
		$remove_keys = array('wishlist', 'tinv_wishlist', 'yith_wcwl');
		foreach ($remove_keys as $k) {
			unset($items[$k]);
		}

		foreach (array_keys($items) as $key) {
			$lower = strtolower((string) $key);
			if (strpos($lower, 'wishlist') !== false) {
				unset($items[$key]);
			}
		}

		// Place Two-Factor Auth (2FA) right before customer-logout so every customer can easily configure it
		if (! isset($items['wfls-2fa'])) {
			$new_items = array();
			foreach ($items as $k => $v) {
				if ($k === 'customer-logout') {
					$new_items['wfls-2fa'] = __('Two-Factor Auth (2FA)', 'custom-woo-dashboard');
				}
				$new_items[$k] = $v;
			}
			if (! isset($new_items['wfls-2fa'])) {
				$new_items['wfls-2fa'] = __('Two-Factor Auth (2FA)', 'custom-woo-dashboard');
			}
			$items = $new_items;
		} else {
			$items['wfls-2fa'] = __('Two-Factor Auth (2FA)', 'custom-woo-dashboard');
		}

		return $items;
	}

	/**
	 * Ensure navigation menu items pointing to /my-account/credits point safely to /my-account/credit/,
	 * and remove Wishlist from dropdown navigation menus.
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

			// Exclude wishlist from dropdowns
			if (strpos($url, 'wishlist') !== false || strpos($title, 'wishlist') !== false) {
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
