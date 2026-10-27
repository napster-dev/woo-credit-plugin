<?php

/**
 * Enterprise Orders Portal Template
 * Matches mlaccessories.co.uk (Knightsbridge) portal UX adapted to EW Supplies UK theme:
 * - Live Order Status, Unplaced Orders (Saved Baskets), Order History, and Backorders
 * - High-end responsive card grid for Order History & Live Orders with Delivery Estimate
 * - Dedicated product-level Backorders table with Delivery Estimate
 * - Seamless responsive navigation across desktop, tablet, and mobile screens
 * - 100% immune to 404 on any page URL or host
 */

if (! defined('ABSPATH')) {
	exit;
}

$current_user = wp_get_current_user();
$user_id      = (int) $current_user->ID;

// Helper: robust tab URL generator (never 404s on custom page slugs or un-flushed rewrite rules)
if (! function_exists('cwd_get_portal_tab_url')) {
	function cwd_get_portal_tab_url($tab_slug)
	{
		$request_uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
		$current_path = strtok($request_uri, '?');

		// Strip trailing endpoints like /live-orders/, /unplaced-orders/, etc.
		$base_path = preg_replace('#/(live-orders|unplaced-orders|saved-baskets|order-history|back-orders|all-orders)/?.*$#i', '/', $current_path);
		if (empty($base_path)) {
			$base_path = $current_path;
		}

		return add_query_arg('view', $tab_slug, $base_path);
	}
}

// Helper: compute delivery estimate HTML
if (! function_exists('cwd_get_order_delivery_estimate_html')) {
	function cwd_get_order_delivery_estimate_html($order)
	{
		if (! $order) {
			return '<span class="cwd-estimate-unavailable">' . esc_html__('Sorry, an estimate is currently unavailable.', 'custom-woo-dashboard') . '</span>';
		}

		$status = $order->get_status();
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

		// Custom estimate meta (set by WooCommerce Admin, Odoo ERP sync, or backorder update)
		$custom_estimate = $order->get_meta('_delivery_estimate');
		if (! empty($custom_estimate)) {
			return '<span class="cwd-estimate-custom">' . esc_html($custom_estimate) . '</span>';
		}

		// Completed orders
		if ('completed' === $status) {
			$completed_date = $order->get_date_completed();
			$date_str = $completed_date ? wc_format_datetime($completed_date, 'j M Y') : '';
			$label = $is_collection
				? ($date_str ? sprintf(__('Collected on %s', 'custom-woo-dashboard'), $date_str) : __('Collected from Warehouse', 'custom-woo-dashboard'))
				: ($date_str ? sprintf(__('Delivered on %s', 'custom-woo-dashboard'), $date_str) : __('Delivered', 'custom-woo-dashboard'));
			return '<span class="cwd-estimate-delivered">' .
				'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-2px; margin-right:5px;"><polyline points="20 6 9 17 4 12"/></svg>' .
				esc_html($label) .
				'</span>';
		}

		// Collection orders
		if ($is_collection) {
			return '<span class="cwd-estimate-collection" style="color:#0284c7; font-weight:600; display:inline-flex; align-items:center;">' .
				'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-2px; margin-right:5px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>' .
				esc_html__('Collection from EWS Warehouse', 'custom-woo-dashboard') .
				'</span>';
		}

		// Check for carrier tracking link
		if (class_exists('CWD_V2_Shipment_Tracking')) {
			$tracking_items = CWD_V2_Shipment_Tracking::get_tracking_items($order->get_id());
			if (! empty($tracking_items) && ! empty($tracking_items[0]['custom_link'])) {
				$provider = ! empty($tracking_items[0]['provider_label']) ? $tracking_items[0]['provider_label'] : __('Courier', 'custom-woo-dashboard');
				return '<span class="cwd-estimate-tracking"><a href="' . esc_url($tracking_items[0]['custom_link']) . '" target="_blank" rel="noopener">' .
					esc_html(sprintf(__('Dispatched via %s - Track Consignment', 'custom-woo-dashboard'), $provider)) . ' &rarr;</a></span>';
			}
		}

		if (in_array($status, array('pending', 'processing', 'on-hold'), true)) {
			return '<span class="cwd-estimate-processing" style="color:#0f172a; font-weight:500;">' . esc_html__('In preparation – 1–2 working days', 'custom-woo-dashboard') . '</span>';
		}

		// Fallback exact text
		return '<span class="cwd-estimate-unavailable">' . esc_html__('Estimated at dispatch', 'custom-woo-dashboard') . '</span>';
	}
}

// Fetch orders to compute counts and datasets
$all_customer_orders = wc_get_orders(
	array(
		'customer_id' => $user_id,
		'limit'       => 100,
		'orderby'     => 'date',
		'order'       => 'DESC',
	)
);

$live_orders_list    = array();
$history_orders_list = array();
$backorder_items     = array();

foreach ($all_customer_orders as $ord) {
	$st = $ord->get_status();

	if (in_array($st, array('pending', 'processing', 'on-hold'), true)) {
		$live_orders_list[] = $ord;

		// Check line items for backorders
		foreach ($ord->get_items() as $item) {
			$is_bo = ('yes' === $item->get_meta('_is_backorder'));
			$prod  = $item->get_product();
			if (! $is_bo && $prod && $prod->backorders_allowed() && $prod->is_on_backorder($item->get_quantity())) {
				$is_bo = true;
			}
			if ($is_bo) {
				$backorder_items[] = array(
					'order'    => $ord,
					'item'     => $item,
					'product'  => $prod,
					'sku'      => ($prod && $prod->get_sku()) ? $prod->get_sku() : $item->get_name(),
					'qty'      => $item->get_quantity(),
					'price'    => $ord->get_item_subtotal($item, false, true),
					'estimate' => $item->get_meta('_delivery_estimate') ?: $item->get_meta('_backorder_eta'),
				);
			}
		}
	} else {
		$history_orders_list[] = $ord;
	}
}

$live_count          = count($live_orders_list);
$cart_has_items      = (isset(WC()->cart) && ! WC()->cart->is_empty());
$saved_baskets_count = class_exists('CWD_V2_Saved_Baskets') ? CWD_V2_Saved_Baskets::get_user_baskets_count($user_id) : 0;
$unplaced_count      = ($cart_has_items ? 1 : 0) + $saved_baskets_count;
$history_count       = count($history_orders_list);
$backorders_count    = count($backorder_items);

// Determine active view
global $wp;
$view = 'live-orders';

if (isset($wp->query_vars['live-orders']) || (isset($_GET['view']) && 'live-orders' === $_GET['view'])) {
	$view = 'live-orders';
} elseif (isset($wp->query_vars['unplaced-orders']) || (isset($_GET['view']) && 'unplaced-orders' === $_GET['view']) || isset($wp->query_vars['saved-baskets']) || (isset($_GET['view']) && 'saved-baskets' === $_GET['view'])) {
	$view = 'unplaced-orders';
} elseif (isset($wp->query_vars['order-history']) || (isset($_GET['view']) && 'order-history' === $_GET['view'])) {
	$view = 'order-history';
} elseif (isset($wp->query_vars['back-orders']) || (isset($_GET['view']) && 'back-orders' === $_GET['view'])) {
	$view = 'back-orders';
} elseif (isset($wp->query_vars['orders'])) {
	// If visiting /orders/ directly
	$view = ($live_count > 0 || $history_count === 0) ? 'live-orders' : 'order-history';
}

// Titles and subheadings matching mlaccessories.co.uk
switch ($view) {
	case 'live-orders':
		$title         = __('Live Order Status', 'custom-woo-dashboard');
		$description   = __('In-progress orders that your organisation has recently placed.', 'custom-woo-dashboard');
		$active_orders = $live_orders_list;
		break;
	case 'unplaced-orders':
		$title         = __('Unplaced Orders', 'custom-woo-dashboard');
		$description   = __('Unplaced orders that your organisation has created.', 'custom-woo-dashboard');
		$active_orders = array();
		break;
	case 'back-orders':
		$title         = __('Backorders', 'custom-woo-dashboard');
		$description   = __('Products that were temporarily out of stock when ordered.', 'custom-woo-dashboard');
		$active_orders = array();
		break;
	case 'order-history':
	default:
		$title         = __('Order History', 'custom-woo-dashboard');
		$description   = __('Any orders that your organisation has previously placed.', 'custom-woo-dashboard');
		$active_orders = $history_orders_list;
		break;
}

// User details for avatar and company display
$user_display_name = $current_user->display_name ? $current_user->display_name : $current_user->user_login;
$user_clean_name   = ucwords(trim($user_display_name));
$user_initial      = strtoupper(substr($user_clean_name, 0, 1));
if (empty($user_initial)) {
	$user_initial = 'U';
}
$shop_url = apply_filters('woocommerce_return_to_shop_redirect', wc_get_page_permalink('shop'));
?>

<div class="cwd-orders-portal">
	<!-- Top Banner Header (EWS Navy Palette) -->
	<div class="cwd-portal-header">
		<div class="cwd-portal-title-block">
			<h2 class="cwd-portal-title"><?php echo esc_html($title); ?></h2>
			<p class="cwd-portal-subtitle"><?php echo esc_html($description); ?></p>
		</div>
		<div class="cwd-portal-header-actions">
			<a href="<?php echo esc_url($shop_url); ?>" class="cwd-btn-new-order">
				<span><?php esc_html_e('NEW ORDER', 'custom-woo-dashboard'); ?></span>
				<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<line x1="12" y1="5" x2="12" y2="19"></line>
					<line x1="5" y1="12" x2="19" y2="12"></line>
				</svg>
			</a>
		</div>
	</div>

	<!-- Main Body Layout: Sidebar Navigation + Content Panel -->
	<div class="cwd-portal-body">
		<!-- Navigation Tabs / Sidebar -->
		<aside class="cwd-portal-sidebar">
			<nav class="cwd-portal-nav" aria-label="<?php esc_attr_e('Order Views', 'custom-woo-dashboard'); ?>">
				<a href="<?php echo esc_url(cwd_get_portal_tab_url('live-orders')); ?>" class="cwd-nav-item <?php echo 'live-orders' === $view ? 'is-active' : ''; ?>">
					<span class="cwd-nav-label"><?php esc_html_e('Live Order Status', 'custom-woo-dashboard'); ?></span>
					<span class="cwd-nav-badge"><?php echo (int) $live_count; ?></span>
				</a>
				<a href="<?php echo esc_url(cwd_get_portal_tab_url('unplaced-orders')); ?>" class="cwd-nav-item <?php echo 'unplaced-orders' === $view ? 'is-active' : ''; ?>">
					<span class="cwd-nav-label"><?php esc_html_e('Unplaced Orders', 'custom-woo-dashboard'); ?></span>
					<span class="cwd-nav-badge"><?php echo (int) $unplaced_count; ?></span>
				</a>
				<a href="<?php echo esc_url(cwd_get_portal_tab_url('order-history')); ?>" class="cwd-nav-item <?php echo 'order-history' === $view ? 'is-active' : ''; ?>">
					<span class="cwd-nav-label"><?php esc_html_e('Order History', 'custom-woo-dashboard'); ?></span>
					<span class="cwd-nav-badge"><?php echo (int) $history_count; ?></span>
				</a>
				<a href="<?php echo esc_url(cwd_get_portal_tab_url('back-orders')); ?>" class="cwd-nav-item <?php echo 'back-orders' === $view ? 'is-active' : ''; ?>">
					<span class="cwd-nav-label"><?php esc_html_e('Backorders', 'custom-woo-dashboard'); ?></span>
					<span class="cwd-nav-badge"><?php echo (int) $backorders_count; ?></span>
				</a>
			</nav>
		</aside>

		<!-- Content Panel -->
		<main class="cwd-portal-content">
			<?php if ('unplaced-orders' === $view) : ?>
				<!-- Unplaced Orders (Saved Baskets) Embedded Directly -->
				<?php include CWD_V2_PLUGIN_DIR . 'templates/saved-baskets.php'; ?>

			<?php elseif ('back-orders' === $view) : ?>
				<!-- Backorders Product Table matching Knightsbridge MLA -->
				<div class="cwd-backorders-panel">
					<?php if (! empty($backorder_items)) : ?>
						<div class="cwd-table-responsive">
							<table class="cwd-backorders-table">
								<thead>
									<tr>
										<th class="cwd-col-product"><?php esc_html_e('Product Code', 'custom-woo-dashboard'); ?></th>
										<th class="cwd-col-qty"><?php esc_html_e('Quantity', 'custom-woo-dashboard'); ?></th>
										<th class="cwd-col-ref"><?php esc_html_e('Reference', 'custom-woo-dashboard'); ?></th>
										<th class="cwd-col-price"><?php esc_html_e('Price Each', 'custom-woo-dashboard'); ?></th>
										<th class="cwd-col-estimate"><?php esc_html_e('Delivery Estimate', 'custom-woo-dashboard'); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($backorder_items as $bo_item) : 
										$prod     = $bo_item['product'];
										$bo_order = $bo_item['order'];
										$sku      = $bo_item['sku'];
										$qty      = (int) $bo_item['qty'];
										$price    = $bo_item['price'];
										$est      = $bo_item['estimate'];
									?>
										<tr>
											<td class="cwd-col-product" data-title="<?php esc_attr_e('Product Code', 'custom-woo-dashboard'); ?>">
												<div class="cwd-product-cell">
													<div class="cwd-product-thumb">
														<?php if ($prod && $prod->get_image_id()) : ?>
															<?php echo wp_kses_post($prod->get_image(array(50, 50))); ?>
														<?php else : ?>
															<div class="cwd-placeholder-thumb">
																<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.8"><path d="m7.5 4.27 9 5.15M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>
															</div>
														<?php endif; ?>
													</div>
													<div class="cwd-product-info">
														<strong class="cwd-product-sku"><?php echo esc_html($sku); ?></strong>
														<?php if ($prod && $prod->get_name() !== $sku) : ?>
															<span class="cwd-product-name"><?php echo esc_html($prod->get_name()); ?></span>
														<?php endif; ?>
													</div>
												</div>
											</td>
											<td class="cwd-col-qty" data-title="<?php esc_attr_e('Quantity', 'custom-woo-dashboard'); ?>">
												<span class="cwd-qty-val"><?php echo $qty; ?></span>
											</td>
											<td class="cwd-col-ref" data-title="<?php esc_attr_e('Reference', 'custom-woo-dashboard'); ?>">
												<a href="<?php echo esc_url($bo_order->get_view_order_url()); ?>" class="cwd-ref-link">
													<?php 
													$po_number = $bo_order->get_meta('_po_number') ?: $bo_order->get_meta('po_number');
													echo esc_html($po_number ? $po_number : ('#' . $bo_order->get_order_number())); 
													?>
												</a>
											</td>
											<td class="cwd-col-price" data-title="<?php esc_attr_e('Price Each', 'custom-woo-dashboard'); ?>">
												<?php echo wp_strip_all_tags(wc_price($price, array('currency' => $bo_order->get_currency()))); ?>
											</td>
											<td class="cwd-col-estimate" data-title="<?php esc_attr_e('Delivery Estimate', 'custom-woo-dashboard'); ?>">
												<?php if (! empty($est)) : ?>
													<span class="cwd-estimate-custom"><?php echo esc_html($est); ?></span>
												<?php else : ?>
													<span class="cwd-estimate-unavailable"><?php esc_html_e('Sorry, an estimate is currently unavailable.', 'custom-woo-dashboard'); ?></span>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<div class="cwd-portal-footer-summary">
							<?php echo esc_html(sprintf(__('Showing %d %s', 'custom-woo-dashboard'), $backorders_count, _n('backorder.', 'backorders.', $backorders_count, 'custom-woo-dashboard'))); ?>
						</div>
					<?php else : ?>
						<div class="cwd-empty-portal-state">
							<div class="cwd-empty-icon-wrap">
								<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
									<rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
									<path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
								</svg>
							</div>
							<h3 class="cwd-empty-title"><?php esc_html_e('No backorders', 'custom-woo-dashboard'); ?></h3>
							<p class="cwd-empty-desc"><?php esc_html_e('Products that were temporarily out of stock when ordered will appear here.', 'custom-woo-dashboard'); ?></p>
						</div>
					<?php endif; ?>
				</div>

			<?php else : ?>
				<!-- Order History & Live Order Status Cards Grid -->
				<div class="cwd-orders-panel">
					<?php if (! empty($active_orders)) : ?>
						<!-- Search Filter Bar -->
						<div class="cwd-orders-search-wrap">
							<div class="cwd-search-inner">
								<svg class="cwd-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
									<circle cx="11" cy="11" r="8"></circle>
									<line x1="21" y1="21" x2="16.65" y2="16.65"></line>
								</svg>
								<input type="search" id="cwd-orders-search-input" class="cwd-search-input" placeholder="<?php echo 'order-history' === $view ? esc_attr__('Search order history...', 'custom-woo-dashboard') : esc_attr__('Search live orders...', 'custom-woo-dashboard'); ?>" aria-label="<?php esc_attr_e('Search orders', 'custom-woo-dashboard'); ?>" autocomplete="off" />
							</div>
						</div>

						<!-- Cards Grid -->
						<div class="cwd-orders-cards-grid" id="cwd-orders-cards-grid">
							<?php foreach ($active_orders as $order) : 
								$order_id      = $order->get_id();
								$order_num     = $order->get_order_number();
								$po_ref        = $order->get_meta('_po_number') ?: $order->get_meta('po_number');
								$has_po        = ! empty($po_ref);
								$card_title    = $has_po ? $po_ref : sprintf(__('Order #%s', 'custom-woo-dashboard'), $order_num);
								$card_sub      = $has_po ? sprintf(__('Order #%s', 'custom-woo-dashboard'), $order_num) : '';
								$status        = $order->get_status();
								$status_name   = wc_get_order_status_name($status);
								$created_date  = $order->get_date_created();
								$date_str      = $created_date ? wc_format_datetime($created_date, 'j M Y') : '—';
								$total_html    = wp_strip_all_tags(wc_price($order->get_total(), array('currency' => $order->get_currency())));
								$view_url      = $order->get_view_order_url();
								$items         = $order->get_items();
								$item_count    = $order->get_item_count();

								// Address
								$addr_name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
								$addr_comp = $order->get_shipping_company();
								$addr_1    = $order->get_shipping_address_1();
								$addr_2    = $order->get_shipping_address_2();
								$addr_city = $order->get_shipping_city();
								$addr_post = $order->get_shipping_postcode();

								if (empty($addr_1)) {
									$addr_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
									$addr_comp = $order->get_billing_company();
									$addr_1    = $order->get_billing_address_1();
									$addr_2    = $order->get_billing_address_2();
									$addr_city = $order->get_billing_city();
									$addr_post = $order->get_billing_postcode();
								}

								$address_lines = array_filter(array(
									! empty($addr_comp) ? strtoupper($addr_comp) : strtoupper($addr_name),
									! empty($addr_1) ? strtoupper($addr_1) : '',
									! empty($addr_2) ? strtoupper($addr_2) : '',
									! empty($addr_city) ? strtoupper($addr_city) : '',
									! empty($addr_post) ? strtoupper($addr_post) : '',
								));

								// Status badge class
								$status_badge_class = 'cwd-badge-default';
								if (in_array($status, array('completed'), true)) {
									$status_badge_class = 'cwd-badge-invoiced';
								} elseif (in_array($status, array('processing'), true)) {
									$status_badge_class = 'cwd-badge-processing';
								} elseif (in_array($status, array('on-hold'), true)) {
									$status_badge_class = 'cwd-badge-on-hold';
								} elseif (in_array($status, array('cancelled', 'failed', 'refunded'), true)) {
									$status_badge_class = 'cwd-badge-cancelled';
								}

								// Searchable data attributes
								$search_keywords = strtolower($card_title . ' ' . $order_num . ' ' . $status_name . ' ' . $date_str . ' ' . implode(' ', $address_lines));
								foreach ($items as $itm) {
									$search_keywords .= ' ' . strtolower($itm->get_name());
								}
							?>
								<div class="cwd-order-card" data-search="<?php echo esc_attr($search_keywords); ?>">
									<!-- Card Header -->
									<div class="cwd-card-header">
										<div class="cwd-card-ref-box">
											<span class="cwd-micro-label"><?php esc_html_e('SALES ORDER', 'custom-woo-dashboard'); ?></span>
											<h3 class="cwd-card-po-title"><?php echo esc_html($card_title); ?></h3>
											<?php if (! empty($card_sub)) : ?>
												<div class="cwd-card-order-sub"><?php echo esc_html($card_sub); ?></div>
											<?php endif; ?>
											<div class="cwd-card-status-pill <?php echo esc_attr($status_badge_class); ?>">
												<?php echo esc_html(strtoupper('completed' === $status ? __('INVOICED', 'custom-woo-dashboard') : $status_name)); ?>
											</div>
										</div>
										<div class="cwd-card-action-box" style="display:flex; flex-wrap:wrap; gap:6px; align-items:center;">
											<a href="<?php echo esc_url($view_url); ?>" class="cwd-btn-card-action">
												<?php echo esc_html(sprintf(__('VIEW ORDER #%s', 'custom-woo-dashboard'), $order_num)); ?>
											</a>
											<?php
											$inv_row      = class_exists('CWD_V2_Invoices') ? CWD_V2_Invoices::get_invoices_for_order($order->get_id()) : null;
											$odoo_inv_num = $order->get_meta('_odoo_invoice_number') ?: $order->get_meta('odoo_invoice_number');
											if ($inv_row && ! empty($inv_row->invoice_number)) {
												$odoo_inv_num = $inv_row->invoice_number;
											}
											$inv_target_url = '';
											if ($inv_row) {
												$inv_target_url = wc_get_endpoint_url('view-invoice', $inv_row->id);
											} elseif (! empty($order->get_meta('_odoo_invoice_url'))) {
												$raw_url = $order->get_meta('_odoo_invoice_url');
												if (stripos($raw_url, 'xero') === false && stripos($raw_url, 'localhost') === false) {
													$inv_target_url = $raw_url;
												}
											}

											if (! empty($inv_target_url)) :
												$btn_label = ! empty($odoo_inv_num) ? sprintf(__('INVOICE %s', 'custom-woo-dashboard'), $odoo_inv_num) : __('VAT INVOICE', 'custom-woo-dashboard');
											?>
												<a href="<?php echo esc_url($inv_target_url); ?>" class="cwd-btn-card-action" style="background:#0284c7; color:#fff; border-color:#0284c7;">
													<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px; margin-right:4px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
													<?php echo esc_html($btn_label); ?>
												</a>
											<?php endif; ?>
										</div>
									</div>

									<!-- Card Meta 3-Column Row -->
									<div class="cwd-card-meta-row">
										<div class="cwd-meta-cell">
											<span class="cwd-micro-label"><?php esc_html_e('ORDER PLACED', 'custom-woo-dashboard'); ?></span>
											<span class="cwd-meta-value"><?php echo esc_html($date_str); ?></span>
										</div>
										<div class="cwd-meta-cell">
											<span class="cwd-micro-label"><?php esc_html_e('PLACED BY', 'custom-woo-dashboard'); ?></span>
											<div class="cwd-placed-by-box">
												<span class="cwd-avatar-circle"><?php echo esc_html($user_initial); ?></span>
												<span class="cwd-placed-name"><?php echo esc_html($user_clean_name); ?></span>
												<span class="cwd-you-pill"><?php esc_html_e('YOU', 'custom-woo-dashboard'); ?></span>
											</div>
										</div>
										<div class="cwd-meta-cell">
											<span class="cwd-micro-label"><?php esc_html_e('ORDER TOTAL', 'custom-woo-dashboard'); ?></span>
											<span class="cwd-meta-value cwd-price-bold"><?php echo esc_html($total_html); ?></span>
										</div>
									</div>

									<!-- Card Body Row: Products & Shipping Address -->
									<div class="cwd-card-body-row">
										<div class="cwd-card-sub-col cwd-products-col">
											<span class="cwd-micro-label"><?php esc_html_e('PRODUCTS ON ORDER', 'custom-woo-dashboard'); ?></span>
											<div class="cwd-order-thumbs-row">
												<?php 
												$shown_count = 0;
												foreach ($items as $item) : 
													if ($shown_count >= 4) {
														break;
													}
													$prod     = $item->get_product();
													$item_qty = (int) $item->get_quantity();
													$shown_count++;
												?>
													<div class="cwd-thumb-item" title="<?php echo esc_attr($item->get_name()); ?>">
														<?php if ($prod && $prod->get_image_id()) : ?>
															<?php echo wp_kses_post($prod->get_image(array(44, 44))); ?>
														<?php else : ?>
															<div class="cwd-thumb-fallback">
																<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><path d="m7.5 4.27 9 5.15M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>
															</div>
														<?php endif; ?>
														<span class="cwd-thumb-badge"><?php echo $item_qty; ?></span>
													</div>
												<?php endforeach; ?>
												<span class="cwd-items-total-pill">
													<?php echo esc_html(sprintf(_n('%d item', '%d items', $item_count, 'custom-woo-dashboard'), $item_count)); ?>
												</span>
											</div>
										</div>

										<div class="cwd-card-sub-col cwd-address-col">
											<span class="cwd-micro-label"><?php esc_html_e('DISPATCHED TO', 'custom-woo-dashboard'); ?></span>
											<div class="cwd-address-text">
												<?php if (! empty($address_lines)) : ?>
													<?php foreach ($address_lines as $line) : ?>
														<div><?php echo esc_html($line); ?></div>
													<?php endforeach; ?>
												<?php else : ?>
													<div><?php esc_html_e('No delivery address specified.', 'custom-woo-dashboard'); ?></div>
												<?php endif; ?>
											</div>
										</div>
									</div>

									<!-- Card Delivery Estimate Strip (CTO Requirement) -->
									<div class="cwd-card-estimate-strip">
										<div class="cwd-estimate-label-box">
											<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
												<rect x="1" y="3" width="15" height="13"></rect>
												<polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
												<circle cx="5.5" cy="18.5" r="2.5"></circle>
												<circle cx="18.5" cy="18.5" r="2.5"></circle>
											</svg>
											<span class="cwd-micro-label" style="margin-bottom: 0 !important;"><?php esc_html_e('DELIVERY ESTIMATE', 'custom-woo-dashboard'); ?></span>
										</div>
										<div class="cwd-estimate-result">
											<?php echo cwd_get_order_delivery_estimate_html($order); ?>
										</div>
									</div>
								</div>
							<?php endforeach; ?>
						</div>

						<div id="cwd-no-search-results" class="cwd-no-results-msg" style="display: none;">
							<p><?php esc_html_e('No orders found matching your search.', 'custom-woo-dashboard'); ?></p>
						</div>

					<?php else : ?>
						<!-- Empty State -->
						<div class="cwd-empty-portal-state">
							<div class="cwd-empty-icon-wrap">
								<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
									<circle cx="12" cy="12" r="10"></circle>
									<polyline points="12 6 12 12 16 14"></polyline>
								</svg>
							</div>
							<h3 class="cwd-empty-title">
								<?php echo 'live-orders' === $view ? esc_html__('No orders in progress', 'custom-woo-dashboard') : esc_html__('No previous orders found', 'custom-woo-dashboard'); ?>
							</h3>
							<p class="cwd-empty-desc"><?php echo esc_html($description); ?></p>
							<a href="<?php echo esc_url($shop_url); ?>" class="cwd-btn-start-order">
								<?php esc_html_e('Start a New Order', 'custom-woo-dashboard'); ?> &rarr;
							</a>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</main>
	</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
	var searchInput = document.getElementById('cwd-orders-search-input');
	var cardsGrid   = document.getElementById('cwd-orders-cards-grid');
	var noResults   = document.getElementById('cwd-no-search-results');

	if (searchInput && cardsGrid) {
		var cards = cardsGrid.getElementsByClassName('cwd-order-card');
		searchInput.addEventListener('input', function() {
			var query = this.value.toLowerCase().trim();
			var visibleCount = 0;

			for (var i = 0; i < cards.length; i++) {
				var cardKeywords = cards[i].getAttribute('data-search') || '';
				if (!query || cardKeywords.indexOf(query) !== -1) {
					cards[i].style.display = '';
					visibleCount++;
				} else {
					cards[i].style.display = 'none';
				}
			}

			if (noResults) {
				noResults.style.display = (visibleCount === 0 && query.length > 0) ? 'block' : 'none';
			}
		});
	}
});
</script>