<?php

/**
 * Handles WooCommerce product backorder detection, customer notices,
 * order line item metadata, customer order views, and live sync endpoints.
 */

if (! defined('ABSPATH')) {
	exit;
}

class CWD_V2_Backorders
{
	const DEFAULT_CUSTOMER_MESSAGE = 'This item is currently out of stock and will be fulfilled when inventory is replenished.';

	public static function init()
	{
		// Product availability text and classes
		add_filter('woocommerce_get_availability_text', array(__CLASS__, 'custom_backorder_availability_text'), 20, 2);
		add_filter('woocommerce_get_availability_class', array(__CLASS__, 'custom_backorder_availability_class'), 20, 2);

		// Single product notice
		add_action('woocommerce_single_product_summary', array(__CLASS__, 'display_single_product_backorder_notice'), 25);

		// Cart and Checkout item notices
		add_filter('woocommerce_get_item_data', array(__CLASS__, 'add_cart_item_backorder_data'), 20, 2);
		add_filter('woocommerce_cart_item_name', array(__CLASS__, 'add_cart_item_backorder_badge'), 20, 3);

		// Save backorder metadata when order is placed
		add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'attach_backorder_meta_to_line_item'), 20, 4);

		// Customer order details (My Account & Thank You page)
		add_action('woocommerce_order_item_meta_end', array(__CLASS__, 'display_order_item_backorder_notice'), 20, 3);
		add_filter('woocommerce_order_item_name', array(__CLASS__, 'add_order_item_backorder_badge'), 20, 2);

		// Email notification notices
		add_action('woocommerce_email_after_order_table', array(__CLASS__, 'display_email_backorder_summary'), 20, 4);

		// REST API route for Odoo live sync updates
		add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
	}

	/**
	 * Customize product availability text for backordered items.
	 */
	public static function custom_backorder_availability_text($text, $product)
	{
		if ($product && $product->backorders_allowed()) {
			$stock_qty = $product->get_stock_quantity();
			if ($product->managing_stock() && $stock_qty !== null && $stock_qty <= 0) {
				return self::DEFAULT_CUSTOMER_MESSAGE;
			} elseif ($product->get_stock_status() === 'onbackorder') {
				return self::DEFAULT_CUSTOMER_MESSAGE;
			}
		}
		return $text;
	}

	/**
	 * Add CSS class to availability wrapper.
	 */
	public static function custom_backorder_availability_class($class, $product)
	{
		if ($product && $product->backorders_allowed()) {
			$stock_qty = $product->get_stock_quantity();
			if (($product->managing_stock() && $stock_qty !== null && $stock_qty <= 0) || $product->get_stock_status() === 'onbackorder') {
				$class .= ' cwd-item-backordered';
			}
		}
		return $class;
	}

	/**
	 * Display an informative banner on single product pages when backorder is active.
	 */
	public static function display_single_product_backorder_notice()
	{
		global $product;
		if (! $product) {
			return;
		}

		$is_backorder = false;
		if ($product->backorders_allowed()) {
			$stock_qty = $product->get_stock_quantity();
			if (($product->managing_stock() && $stock_qty !== null && $stock_qty <= 0) || $product->get_stock_status() === 'onbackorder') {
				$is_backorder = true;
			}
		}

		if ($is_backorder) {
			echo '<div class="cwd-backorder-product-banner" style="margin: 15px 0; padding: 12px 16px; background-color: #fff9db; border-left: 4px solid #f59f00; color: #7f4f00; font-size: 14px; border-radius: 4px; line-height: 1.5;">' .
				'<strong>📦 ' . esc_html__('Backorder Notice:', 'custom-woo-dashboard') . '</strong> ' .
				esc_html(self::DEFAULT_CUSTOMER_MESSAGE) .
				'</div>';
		}
	}

	/**
	 * Add backorder information to cart and checkout item data.
	 */
	public static function add_cart_item_backorder_data($item_data, $cart_item)
	{
		$product = isset($cart_item['data']) ? $cart_item['data'] : null;
		if ($product && $product->backorders_allowed()) {
			$stock_qty = $product->get_stock_quantity();
			$cart_qty  = isset($cart_item['quantity']) ? (int) $cart_item['quantity'] : 1;

			if (($product->managing_stock() && $stock_qty !== null && ($stock_qty <= 0 || $cart_qty > $stock_qty)) || $product->get_stock_status() === 'onbackorder') {
				$item_data[] = array(
					'key'     => __('Status', 'custom-woo-dashboard'),
					'value'   => __('Backorder (Awaiting Stock)', 'custom-woo-dashboard'),
					'display' => '<span style="color:#d97706; font-weight:600;">⏳ ' . esc_html__('Backorder - Fulfilled upon restock', 'custom-woo-dashboard') . '</span>',
				);
			}
		}
		return $item_data;
	}

	/**
	 * Append badge next to cart item title.
	 */
	public static function add_cart_item_backorder_badge($product_name, $cart_item, $cart_item_key)
	{
		$product = isset($cart_item['data']) ? $cart_item['data'] : null;
		if ($product && $product->backorders_allowed()) {
			$stock_qty = $product->get_stock_quantity();
			$cart_qty  = isset($cart_item['quantity']) ? (int) $cart_item['quantity'] : 1;

			if (($product->managing_stock() && $stock_qty !== null && ($stock_qty <= 0 || $cart_qty > $stock_qty)) || $product->get_stock_status() === 'onbackorder') {
				$product_name .= ' <span class="badge-backorder" style="display:inline-block; padding:2px 8px; font-size:11px; font-weight:600; background:#fef3c7; color:#92400e; border:1px solid #fcd34d; border-radius:4px; vertical-align:middle;">' . esc_html__('Backorder', 'custom-woo-dashboard') . '</span>';
			}
		}
		return $product_name;
	}

	/**
	 * Attach backorder metadata to WooCommerce order line item at checkout.
	 */
	public static function attach_backorder_meta_to_line_item($item, $cart_item_key, $values, $order)
	{
		$product = $item->get_product();
		if (! $product) {
			return;
		}

		$is_backorder = false;
		$stock_qty    = $product->get_stock_quantity();
		$item_qty     = (int) $item->get_quantity();

		if ($product->backorders_allowed()) {
			if ($product->managing_stock()) {
				if ($stock_qty === null || $stock_qty <= 0 || $item_qty > (int) $stock_qty) {
					$is_backorder = true;
				}
			} elseif ($product->get_stock_status() === 'onbackorder') {
				$is_backorder = true;
			}
		}

		if ($is_backorder) {
			$item->add_meta_data('_is_backorder', 'yes', true);
			$item->add_meta_data('_backorder_status', 'waiting_stock', true);
			$item->add_meta_data('_backorder_customer_message', self::DEFAULT_CUSTOMER_MESSAGE, true);
			$item->add_meta_data('_backorder_ordered_qty', $item_qty, true);
			$item->add_meta_data('_backorder_available_qty', max(0, (int) $stock_qty), true);
			$item->add_meta_data('Backorder Status', 'Awaiting Stock Replenishment', true);

			// Mark order as containing backorders
			$order->update_meta_data('_has_backorders', 'yes');
		} else {
			$item->add_meta_data('_is_backorder', 'no', true);
			$item->add_meta_data('_backorder_status', 'available', true);
		}
	}

	/**
	 * Append badge to order item in thank you page and customer portal.
	 */
	public static function add_order_item_backorder_badge($item_name, $item)
	{
		if (is_object($item) && method_exists($item, 'get_meta')) {
			$is_backorder = $item->get_meta('_is_backorder');
			if ('yes' === $is_backorder) {
				$status = $item->get_meta('_backorder_status');
				$label  = ('fulfilled' === $status) ? __('Backorder Fulfilled', 'custom-woo-dashboard') : (('stock_received' === $status || 'reserved' === $status) ? __('Stock Ready / Processing', 'custom-woo-dashboard') : __('Backorder: Awaiting Stock', 'custom-woo-dashboard'));
				$bg     = ('fulfilled' === $status) ? '#d1fae5' : (('stock_received' === $status || 'reserved' === $status) ? '#e0e7ff' : '#fef3c7');
				$color  = ('fulfilled' === $status) ? '#065f46' : (('stock_received' === $status || 'reserved' === $status) ? '#3730a3' : '#92400e');
				$border = ('fulfilled' === $status) ? '#a7f3d0' : (('stock_received' === $status || 'reserved' === $status) ? '#c7d2fe' : '#fcd34d');

				$item_name .= ' <span style="display:inline-block; padding:2px 8px; font-size:11px; font-weight:600; background:' . esc_attr($bg) . '; color:' . esc_attr($color) . '; border:1px solid ' . esc_attr($border) . '; border-radius:4px; margin-left:6px; vertical-align:middle;">' . esc_html($label) . '</span>';
			}
		}
		return $item_name;
	}

	/**
	 * Display customer reassurance message below order item meta on order details page.
	 */
	public static function display_order_item_backorder_notice($item_id, $item, $order)
	{
		if (! is_object($item) || ! method_exists($item, 'get_meta')) {
			return;
		}

		$is_backorder = $item->get_meta('_is_backorder');
		if ('yes' === $is_backorder) {
			$status  = $item->get_meta('_backorder_status');
			$message = $item->get_meta('_backorder_customer_message');
			if (empty($message)) {
				$message = self::DEFAULT_CUSTOMER_MESSAGE;
			}

			if ('fulfilled' === $status) {
				echo '<div style="margin-top:6px; font-size:12px; color:#065f46; background:#ecfdf5; padding:6px 10px; border-left:3px solid #10b981; border-radius:2px;">' .
					'✅ ' . esc_html__('Stock replenished and order fulfilled.', 'custom-woo-dashboard') .
					'</div>';
			} elseif ('stock_received' === $status || 'reserved' === $status) {
				echo '<div style="margin-top:6px; font-size:12px; color:#1e40af; background:#eff6ff; padding:6px 10px; border-left:3px solid #3b82f6; border-radius:2px;">' .
					'📦 ' . esc_html__('Stock received! Order is now being reserved and prepared for fulfillment.', 'custom-woo-dashboard') .
					'</div>';
			} else {
				echo '<div style="margin-top:6px; font-size:12px; color:#854d0e; background:#fefce8; padding:6px 10px; border-left:3px solid #eab308; border-radius:2px;">' .
					'⏳ ' . esc_html($message) .
					'</div>';
			}
		}
	}

	/**
	 * Display clear summary block in order confirmation email.
	 */
	public static function display_email_backorder_summary($order, $sent_to_admin, $plain_text, $email)
	{
		if (! $order) {
			return;
		}

		$has_backorder = false;
		foreach ($order->get_items() as $item) {
			if ('yes' === $item->get_meta('_is_backorder')) {
				$has_backorder = true;
				break;
			}
		}

		if ($has_backorder) {
			if ($plain_text) {
				echo "\n" . __('NOTICE: One or more items in your order are currently on backorder and will be dispatched as soon as stock arrives.', 'custom-woo-dashboard') . "\n\n";
			} else {
				echo '<div style="margin: 20px 0; padding: 14px 18px; background-color: #fefce8; border: 1px solid #fef08a; border-left: 4px solid #eab308; border-radius: 4px; color: #713f12; font-family: inherit;">' .
					'<h4 style="margin: 0 0 6px 0; color: #854d0e; font-size: 15px;">📦 ' . esc_html__('Backorder Notice', 'custom-woo-dashboard') . '</h4>' .
					'<p style="margin: 0; font-size: 13px; line-height: 1.5;">' .
					esc_html__('One or more items in this order are currently on backorder. Our warehouse will automatically fulfill and dispatch your items as soon as inventory replenishment is received.', 'custom-woo-dashboard') .
					'</p>' .
					'</div>';
			}
		}
	}

	/**
	 * Register REST API routes for Odoo live sync updates.
	 */
	public static function register_rest_routes()
	{
		register_rest_route('cwd/v1', '/backorder-update', array(
			'methods'             => 'POST',
			'callback'            => array(__CLASS__, 'handle_odoo_backorder_update'),
			'permission_callback' => array(__CLASS__, 'check_rest_permissions'),
		));
	}

	/**
	 * Verify secret key or basic auth for Odoo sync updates.
	 */
	public static function check_rest_permissions($request)
	{
		$secret_key = get_option('cwd_odoo_secret_key', '');
		$auth_header = $request->get_header('X-Odoo-Secret');

		if (! empty($secret_key) && $auth_header === $secret_key) {
			return true;
		}

		// Fallback to manage_woocommerce capability for authenticated users
		return current_user_can('manage_woocommerce');
	}

	/**
	 * Live update callback called by Odoo when stock is received or order line fulfilled.
	 */
	public static function handle_odoo_backorder_update($request)
	{
		$params          = $request->get_json_params();
		$woo_order_id    = isset($params['woo_order_id']) ? (int) $params['woo_order_id'] : 0;
		$woo_line_id     = isset($params['woo_line_id']) ? (int) $params['woo_line_id'] : 0;
		$sku             = isset($params['sku']) ? sanitize_text_field($params['sku']) : '';
		$status          = isset($params['backorder_status']) ? sanitize_text_field($params['backorder_status']) : '';
		$note            = isset($params['note']) ? sanitize_textarea_field($params['note']) : '';

		if (! $woo_order_id) {
			return new WP_Error('missing_order_id', __('Missing woo_order_id', 'custom-woo-dashboard'), array('status' => 400));
		}

		$order = wc_get_order($woo_order_id);
		if (! $order) {
			return new WP_Error('invalid_order', __('Order not found', 'custom-woo-dashboard'), array('status' => 404));
		}

		$updated = false;
		foreach ($order->get_items() as $item) {
			if (($woo_line_id && $item->get_id() === $woo_line_id) || (! empty($sku) && $item->get_product() && $item->get_product()->get_sku() === $sku)) {
				if (! empty($status)) {
					$item->update_meta_data('_backorder_status', $status);
					if ('fulfilled' === $status) {
						$item->update_meta_data('Backorder Status', 'Fulfilled');
					} elseif ('stock_received' === $status || 'reserved' === $status) {
						$item->update_meta_data('Backorder Status', 'Stock Reserved');
					}
					$item->save();
					$updated = true;
				}
			}
		}

		if (! empty($note)) {
			$order->add_order_note($note, false, true);
		}

		// Check if all lines are fulfilled
		$all_fulfilled = true;
		foreach ($order->get_items() as $item) {
			if ('yes' === $item->get_meta('_is_backorder') && 'fulfilled' !== $item->get_meta('_backorder_status')) {
				$all_fulfilled = false;
				break;
			}
		}

		if ($all_fulfilled && $order->get_meta('_has_backorders') === 'yes') {
			$order->update_meta_data('_backorders_all_fulfilled', 'yes');
		}

		$order->save();

		return rest_ensure_response(array(
			'success' => true,
			'order_id' => $woo_order_id,
			'line_updated' => $updated,
			'all_fulfilled' => $all_fulfilled,
		));
	}
}
