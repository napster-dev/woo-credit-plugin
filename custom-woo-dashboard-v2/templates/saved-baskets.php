<?php

/**
 * Saved Baskets / Unplaced Orders Endpoint Template
 * Displays customer's active checkout cart session alongside saved baskets.
 * Matches mlaccessories.co.uk (Knightsbridge) enterprise portal architecture.
 */

if (! defined('ABSPATH')) {
	exit;
}

$user_id         = get_current_user_id();
$baskets         = class_exists('CWD_V2_Saved_Baskets') ? CWD_V2_Saved_Baskets::get_user_baskets($user_id) : array();
$cart_has_items  = isset(WC()->cart) && ! WC()->cart->is_empty();
$total_unplaced  = ($cart_has_items ? 1 : 0) + count($baskets);
$ajax_url        = admin_url('admin-ajax.php');
$nonce           = wp_create_nonce('cwd_saved_baskets_nonce');
?>

<div class="cwd-v2-saved-baskets-wrap" style="max-width: 1000px; margin: 0 auto; font-family: inherit;">
	<!-- Page Header / Actions -->
	<div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 24px; <?php echo ! empty($view) ? '' : 'border-bottom: 1px solid #e2e8f0; padding-bottom: 18px;'; ?>">
		<?php if (empty($view)) : ?>
			<div>
				<h2 style="margin: 0 0 6px 0; font-size: 24px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 10px;">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path>
						<path d="M3 6h18"></path>
						<path d="M16 10a4 4 0 0 1-8 0"></path>
					</svg>
					<?php esc_html_e('Unplaced Orders', 'custom-woo-dashboard'); ?>
				</h2>
				<p style="margin: 0; font-size: 14px; color: #64748b;">
					<?php esc_html_e('Unplaced orders that your organisation has created.', 'custom-woo-dashboard'); ?>
				</p>
			</div>
		<?php else : ?>
			<div style="font-size: 14px; color: #475569; font-weight: 600;">
				<?php
				if ($cart_has_items && count($baskets) > 0) {
					echo esc_html(sprintf(__('Showing 1 active checkout order and %d saved %s', 'custom-woo-dashboard'), count($baskets), _n('basket.', 'baskets.', count($baskets), 'custom-woo-dashboard')));
				} else {
					echo esc_html(sprintf(__('Showing %d unplaced %s', 'custom-woo-dashboard'), $total_unplaced, _n('order.', 'orders.', $total_unplaced, 'custom-woo-dashboard')));
				}
				?>
			</div>
		<?php endif; ?>

		<?php if ($cart_has_items) : ?>
			<button type="button" class="button cwd-trigger-save-basket" style="background: #0284c7; color: #fff; border: none; padding: 10px 18px; border-radius: 6px; font-weight: 600; font-size: 13.5px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<line x1="12" y1="5" x2="12" y2="19"></line>
					<line x1="5" y1="12" x2="19" y2="12"></line>
				</svg>
				<?php esc_html_e('Save Current Cart as New Basket', 'custom-woo-dashboard'); ?>
			</button>
		<?php endif; ?>
	</div>

	<?php if (! $cart_has_items && empty($baskets)) : ?>
		<!-- Empty State -->
		<div style="background: #ffffff; border: 2px dashed #cbd5e1; border-radius: 12px; padding: 50px 20px; text-align: center; margin: 30px 0;">
			<div style="width: 64px; height: 64px; margin: 0 auto 16px auto; background: #f0f9ff; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #0284c7;">
				<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path>
					<path d="M3 6h18"></path>
					<path d="M16 10a4 4 0 0 1-8 0"></path>
				</svg>
			</div>
			<h3 style="margin: 0 0 8px 0; font-size: 18px; font-weight: 700; color: #1e293b;">
				<?php esc_html_e('No Unplaced Orders Yet', 'custom-woo-dashboard'); ?>
			</h3>
			<p style="margin: 0 auto 20px auto; max-width: 480px; font-size: 14px; color: #64748b; line-height: 1.5;">
				<?php esc_html_e('Your checkout cart is empty and you haven\'t saved any baskets yet. Add items to your cart anytime to view them here or save standard materials handy.', 'custom-woo-dashboard'); ?>
			</p>
			<a href="<?php echo esc_url(apply_filters('woocommerce_return_to_shop_redirect', wc_get_page_permalink('shop'))); ?>" class="button" style="background: #0284c7; color: #fff; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-block;">
				<?php esc_html_e('Browse Products & Start Cart', 'custom-woo-dashboard'); ?>
			</a>
		</div>
	<?php else : ?>
		<!-- Unplaced Orders List -->
		<div style="display: flex; flex-direction: column; gap: 20px;">

			<?php if ($cart_has_items) :
				$cart             = WC()->cart;
				$cart_contents    = $cart->get_cart();
				$cart_items_count = $cart->get_cart_contents_count();
				$cart_total       = $cart->get_total();
				$checkout_url     = wc_get_checkout_url();
				$cart_url         = wc_get_cart_url();
			?>
				<!-- ROW 1: ACTIVE CHECKOUT CART (Knightsbridge MLA Architecture) -->
				<div class="cwd-current-order-card" style="background: #ffffff; border: 1px solid #fed7aa; border-left: 4px solid #f59e0b; border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.03); overflow: hidden;">
					<!-- Card Header -->
					<div style="padding: 18px 20px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px; background: #fffbeb; border-bottom: 1px solid #fef3c7;">
						<div>
							<div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
								<span style="background: #fef3c7; color: #b45309; border: 1px solid #fde68a; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.05em; display: inline-flex; align-items: center; gap: 6px;">
									<span style="width: 7px; height: 7px; border-radius: 50%; background: #f59e0b; display: inline-block;"></span>
									<?php esc_html_e('CURRENT ORDER', 'custom-woo-dashboard'); ?>
								</span>
								<h3 style="margin: 0; font-size: 17px; font-weight: 700; color: #0f172a;">
									<?php esc_html_e('Active Checkout Basket', 'custom-woo-dashboard'); ?>
								</h3>
							</div>
							<div style="font-size: 13px; color: #64748b; margin-top: 5px;">
								<span><?php echo esc_html(sprintf(_n('%d item', '%d items', $cart_items_count, 'custom-woo-dashboard'), $cart_items_count)); ?></span>
								<span style="margin: 0 6px;">•</span>
								<strong style="color: #0f172a;"><?php echo wp_strip_all_tags($cart_total); ?></strong>
								<span style="margin: 0 6px;">•</span>
								<span style="color: #0284c7; font-weight: 600;"><?php esc_html_e('Ready in your active checkout cart', 'custom-woo-dashboard'); ?></span>
							</div>
						</div>

						<!-- Action Buttons -->
						<div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
							<a href="<?php echo esc_url($checkout_url); ?>" class="button" style="background: #0284c7; color: #fff; border: none; padding: 9px 18px; border-radius: 6px; font-size: 13.5px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
								<span><?php esc_html_e('Proceed to Checkout', 'custom-woo-dashboard'); ?></span>
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
									<line x1="5" y1="12" x2="19" y2="12"></line>
									<polyline points="12 5 19 12 12 19"></polyline>
								</svg>
							</a>

							<a href="<?php echo esc_url($cart_url); ?>" class="button" style="background: #ffffff; color: #1e293b; border: 1px solid #cbd5e1; padding: 9px 14px; border-radius: 6px; font-size: 13.5px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
									<circle cx="8" cy="21" r="1"></circle>
									<circle cx="19" cy="21" r="1"></circle>
									<path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"></path>
								</svg>
								<?php esc_html_e('View / Edit Cart', 'custom-woo-dashboard'); ?>
							</a>

							<button type="button" class="button cwd-trigger-save-basket" style="background: #f8fafc; color: #334155; border: 1px solid #cbd5e1; padding: 9px 14px; border-radius: 6px; font-size: 13.5px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
									<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
									<polyline points="17 21 17 13 7 13 7 21"></polyline>
									<polyline points="7 3 7 8 15 8"></polyline>
								</svg>
								<?php esc_html_e('Save as Named Basket', 'custom-woo-dashboard'); ?>
							</button>
						</div>
					</div>

					<!-- Items List Table -->
					<div style="padding: 12px 20px; overflow-x: auto;">
						<table style="width: 100%; border-collapse: collapse; font-size: 13px;">
							<thead>
								<tr style="border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 11.5px; text-transform: uppercase; text-align: left;">
									<th style="padding: 8px 6px;"><?php esc_html_e('Product', 'custom-woo-dashboard'); ?></th>
									<th style="padding: 8px 6px; width: 90px; text-align: center;"><?php esc_html_e('Qty', 'custom-woo-dashboard'); ?></th>
									<th style="padding: 8px 6px; width: 100px; text-align: right;"><?php esc_html_e('Price', 'custom-woo-dashboard'); ?></th>
									<th style="padding: 8px 6px; width: 110px; text-align: right;"><?php esc_html_e('Subtotal', 'custom-woo-dashboard'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($cart_contents as $cart_item_key => $cart_item) :
									$_product = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);
									if (! $_product || ! $_product->exists() || $cart_item['quantity'] <= 0) {
										continue;
									}
									$product_permalink = apply_filters('woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink($cart_item) : '', $cart_item, $cart_item_key);
									$thumbnail         = $_product->get_image(array(38, 38), array('style' => 'width:38px; height:38px; object-fit:cover; border-radius:6px; border:1px solid #e2e8f0; flex-shrink:0;'));
									$name              = $_product->get_name();
									$sku               = $_product->get_sku();
									$price             = WC()->cart->get_product_price($_product);
									$subtotal          = WC()->cart->get_product_subtotal($_product, $cart_item['quantity']);
								?>
									<tr style="border-bottom: 1px solid #f8fafc;">
										<td style="padding: 10px 6px;">
											<div style="display: flex; align-items: center; gap: 12px;">
												<?php echo $thumbnail; ?>
												<div>
													<?php if ($product_permalink) : ?>
														<a href="<?php echo esc_url($product_permalink); ?>" style="color: #1e293b; font-weight: 600; text-decoration: none; line-height: 1.3; display: block;">
															<?php echo esc_html($name); ?>
														</a>
													<?php else : ?>
														<span style="color: #1e293b; font-weight: 600; line-height: 1.3; display: block;">
															<?php echo esc_html($name); ?>
														</span>
													<?php endif; ?>
													<?php if ($sku) : ?>
														<span style="font-size: 11.5px; color: #94a3b8;"><?php echo esc_html__('SKU:', 'custom-woo-dashboard') . ' ' . esc_html($sku); ?></span>
													<?php endif; ?>
												</div>
											</div>
										</td>
										<td style="padding: 10px 6px; text-align: center; font-weight: 600; color: #334155;">
											<?php echo (int) $cart_item['quantity']; ?>
										</td>
										<td style="padding: 10px 6px; text-align: right; color: #64748b;">
											<?php echo wp_strip_all_tags($price); ?>
										</td>
										<td style="padding: 10px 6px; text-align: right; font-weight: 600; color: #0f172a;">
											<?php echo wp_strip_all_tags($subtotal); ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			<?php endif; ?>

			<?php if ($cart_has_items && empty($baskets)) : ?>
				<!-- Friendly Tip when cart exists but no permanent saved baskets yet -->
				<div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
					<div style="font-size: 13.5px; color: #64748b;">
						<strong style="color: #0f172a;"><?php esc_html_e('Tip:', 'custom-woo-dashboard'); ?></strong>
						<?php esc_html_e('Save recurring job supplies or van stock into permanent Named Baskets to reorder anytime.', 'custom-woo-dashboard'); ?>
					</div>
					<button type="button" class="button cwd-trigger-save-basket" style="background: #ffffff; color: #0284c7; border: 1px solid #0284c7; padding: 6px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
						<?php esc_html_e('Save Current Cart Now', 'custom-woo-dashboard'); ?>
					</button>
				</div>
			<?php endif; ?>

			<!-- SAVED BASKETS -->
			<?php foreach ($baskets as $basket) :
				$basket_id   = (int) $basket['id'];
				$title       = esc_html($basket['title']);
				$items_count = (int) $basket['items_count'];
				$total       = (float) $basket['total_amount'];
				$date_str    = date_i18n(get_option('date_format'), strtotime($basket['updated_at']));
				$items       = (array) $basket['items'];
			?>
				<div class="cwd-basket-card" id="cwd-basket-card-<?php echo esc_attr($basket_id); ?>" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.03); overflow: hidden; transition: all 0.2s;">
					<!-- Card Header / Summary -->
					<div style="padding: 18px 20px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px; background: #fafafa; border-bottom: 1px solid #e2e8f0;">
						<div>
							<div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
								<span style="background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.05em;">
									<?php esc_html_e('SAVED BASKET', 'custom-woo-dashboard'); ?>
								</span>
								<h3 class="cwd-basket-title-text" style="margin: 0; font-size: 17px; font-weight: 700; color: #0f172a;">
									<?php echo $title; ?>
								</h3>
								<button type="button" class="cwd-rename-basket-btn" data-id="<?php echo esc_attr($basket_id); ?>" data-title="<?php echo esc_attr($basket['title']); ?>" title="<?php esc_attr_e('Rename Basket', 'custom-woo-dashboard'); ?>" style="background: none; border: none; cursor: pointer; color: #64748b; padding: 2px;">
									<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
										<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"></path>
									</svg>
								</button>
							</div>
							<div style="font-size: 13px; color: #64748b; margin-top: 5px;">
								<span><?php echo esc_html(sprintf(_n('%d item', '%d items', $items_count, 'custom-woo-dashboard'), $items_count)); ?></span>
								<span style="margin: 0 6px;">•</span>
								<strong style="color: #0f172a;"><?php echo wp_strip_all_tags(wc_price($total)); ?></strong>
								<span style="margin: 0 6px;">•</span>
								<span><?php echo esc_html(sprintf(__('Updated %s', 'custom-woo-dashboard'), $date_str)); ?></span>
							</div>
						</div>

						<!-- Action Buttons -->
						<div style="display: flex; align-items: center; gap: 8px;">
							<button type="button" class="cwd-load-basket-btn button" data-id="<?php echo esc_attr($basket_id); ?>" data-mode="replace" style="background: #0284c7; color: #fff; border: none; padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
									<circle cx="8" cy="21" r="1"></circle>
									<circle cx="19" cy="21" r="1"></circle>
									<path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"></path>
								</svg>
								<?php esc_html_e('Load into Cart', 'custom-woo-dashboard'); ?>
							</button>

							<button type="button" class="cwd-delete-basket-btn button" data-id="<?php echo esc_attr($basket_id); ?>" title="<?php esc_attr_e('Delete Basket', 'custom-woo-dashboard'); ?>" style="background: #fff; color: #dc2626; border: 1px solid #fca5a5; padding: 8px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center;">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
									<path d="M3 6h18"></path>
									<path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path>
									<path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path>
								</svg>
							</button>
						</div>
					</div>

					<!-- Items List Table -->
					<div style="padding: 12px 20px; overflow-x: auto;">
						<table style="width: 100%; border-collapse: collapse; font-size: 13px;">
							<thead>
								<tr style="border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 11.5px; text-transform: uppercase; text-align: left;">
									<th style="padding: 8px 6px;"><?php esc_html_e('Product', 'custom-woo-dashboard'); ?></th>
									<th style="padding: 8px 6px; width: 90px; text-align: center;"><?php esc_html_e('Qty', 'custom-woo-dashboard'); ?></th>
									<th style="padding: 8px 6px; width: 100px; text-align: right;"><?php esc_html_e('Price', 'custom-woo-dashboard'); ?></th>
									<th style="padding: 8px 6px; width: 100px; text-align: right;"><?php esc_html_e('Subtotal', 'custom-woo-dashboard'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($items as $item) :
									$img_url  = esc_url($item['image_url'] ?? wc_placeholder_img_src());
									$name     = esc_html($item['name'] ?? __('Product', 'custom-woo-dashboard'));
									$sku      = esc_html($item['sku'] ?? '');
									$qty      = (int) ($item['quantity'] ?? 1);
									$price    = (float) ($item['price'] ?? 0);
									$subtotal = (float) ($item['total'] ?? ($price * $qty));
									$link     = esc_url($item['permalink'] ?? '#');
								?>
									<tr style="border-bottom: 1px solid #f8fafc;">
										<td style="padding: 10px 6px;">
											<div style="display: flex; align-items: center; gap: 12px;">
												<img src="<?php echo $img_url; ?>" alt="<?php echo $name; ?>" style="width: 38px; height: 38px; object-fit: cover; border-radius: 6px; border: 1px solid #e2e8f0; flex-shrink: 0;">
												<div>
													<a href="<?php echo $link; ?>" style="color: #1e293b; font-weight: 600; text-decoration: none; line-height: 1.3; display: block;">
														<?php echo $name; ?>
													</a>
													<?php if ($sku) : ?>
														<span style="font-size: 11.5px; color: #94a3b8;"><?php echo esc_html__('SKU:', 'custom-woo-dashboard') . ' ' . $sku; ?></span>
													<?php endif; ?>
												</div>
											</div>
										</td>
										<td style="padding: 10px 6px; text-align: center; font-weight: 600; color: #334155;">
											<?php echo $qty; ?>
										</td>
										<td style="padding: 10px 6px; text-align: right; color: #64748b;">
											<?php echo wp_strip_all_tags(wc_price($price)); ?>
										</td>
										<td style="padding: 10px 6px; text-align: right; font-weight: 600; color: #0f172a;">
											<?php echo wp_strip_all_tags(wc_price($subtotal)); ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>

<!-- Rename Modal -->
<div id="cwd-rename-basket-modal" style="display: none; position: fixed; inset: 0; z-index: 99999; background: rgba(15, 23, 42, 0.6); align-items: center; justify-content: center; padding: 16px;">
	<div style="background: #ffffff; border-radius: 10px; width: 100%; max-width: 400px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); overflow: hidden;">
		<div style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
			<h4 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a;"><?php esc_html_e('Rename Basket', 'custom-woo-dashboard'); ?></h4>
			<button type="button" id="cwd-rename-close-btn" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #94a3b8;">&times;</button>
		</div>
		<div style="padding: 20px;">
			<input type="hidden" id="cwd-rename-id" value="">
			<label for="cwd-rename-input" style="display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; color: #475569; margin-bottom: 6px;">
				<?php esc_html_e('New Basket Title', 'custom-woo-dashboard'); ?>
			</label>
			<input type="text" id="cwd-rename-input" style="width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; box-sizing: border-box; margin-bottom: 16px;">
			<div style="display: flex; justify-content: flex-end; gap: 10px;">
				<button type="button" id="cwd-rename-cancel-btn" style="padding: 8px 14px; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; color: #475569; font-size: 13px; font-weight: 600; cursor: pointer;">
					<?php esc_html_e('Cancel', 'custom-woo-dashboard'); ?>
				</button>
				<button type="button" id="cwd-rename-submit-btn" style="padding: 8px 16px; border-radius: 6px; border: none; background: #0284c7; color: #fff; font-size: 13px; font-weight: 600; cursor: pointer;">
					<?php esc_html_e('Save Changes', 'custom-woo-dashboard'); ?>
				</button>
			</div>
		</div>
	</div>
</div>

<script id="cwd-saved-baskets-view-js">
document.addEventListener('DOMContentLoaded', function() {
	var ajaxUrl = <?php echo json_encode($ajax_url); ?>;
	var nonce = <?php echo json_encode($nonce); ?>;

	// Load to Cart
	document.querySelectorAll('.cwd-load-basket-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var basketId = btn.getAttribute('data-id');
			var mode = btn.getAttribute('data-mode') || 'replace';
			var originalText = btn.innerHTML;
			btn.disabled = true;
			btn.textContent = 'Loading into Cart...';

			var formData = new FormData();
			formData.append('action', 'cwd_load_saved_basket');
			formData.append('nonce', nonce);
			formData.append('basket_id', basketId);
			formData.append('mode', mode);

			fetch(ajaxUrl, {
				method: 'POST',
				body: formData
			})
			.then(function(res) { return res.json(); })
			.then(function(data) {
				if (data && data.success) {
					btn.textContent = 'Loaded!';
					window.location.href = data.data && data.data.cart_url ? data.data.cart_url : '/cart/';
				} else {
					alert(data.data && data.data.message ? data.data.message : 'Could not load basket into cart.');
					btn.disabled = false;
					btn.innerHTML = originalText;
				}
			})
			.catch(function(err) {
				alert('An error occurred. Please try again.');
				btn.disabled = false;
				btn.innerHTML = originalText;
			});
		});
	});

	// Delete Basket
	document.querySelectorAll('.cwd-delete-basket-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var basketId = btn.getAttribute('data-id');
			if (!confirm('Are you sure you want to delete this saved basket? This action cannot be undone.')) {
				return;
			}

			var card = document.getElementById('cwd-basket-card-' + basketId);
			if (card) { card.style.opacity = '0.5'; }

			var formData = new FormData();
			formData.append('action', 'cwd_delete_saved_basket');
			formData.append('nonce', nonce);
			formData.append('basket_id', basketId);

			fetch(ajaxUrl, {
				method: 'POST',
				body: formData
			})
			.then(function(res) { return res.json(); })
			.then(function(data) {
				if (data && data.success) {
					if (card) {
						card.style.transition = 'all 0.3s ease';
						card.style.transform = 'scale(0.95)';
						card.style.opacity = '0';
						setTimeout(function() {
							card.remove();
							if (document.querySelectorAll('.cwd-basket-card').length === 0) {
								window.location.reload();
							}
						}, 300);
					}
				} else {
					alert(data.data && data.data.message ? data.data.message : 'Could not delete basket.');
					if (card) { card.style.opacity = '1'; }
				}
			})
			.catch(function(err) {
				alert('An error occurred while deleting.');
				if (card) { card.style.opacity = '1'; }
			});
		});
	});

	// Rename Modal
	var renameModal = document.getElementById('cwd-rename-basket-modal');
	var renameInput = document.getElementById('cwd-rename-input');
	var renameId = document.getElementById('cwd-rename-id');
	var renameSubmit = document.getElementById('cwd-rename-submit-btn');
	var renameCancel = document.getElementById('cwd-rename-cancel-btn');
	var renameClose = document.getElementById('cwd-rename-close-btn');

	function hideRenameModal() {
		if (renameModal) renameModal.style.display = 'none';
	}

	if (renameClose) renameClose.addEventListener('click', hideRenameModal);
	if (renameCancel) renameCancel.addEventListener('click', hideRenameModal);
	if (renameModal) {
		renameModal.addEventListener('click', function(e) {
			if (e.target === renameModal) hideRenameModal();
		});
	}

	document.querySelectorAll('.cwd-rename-basket-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var id = btn.getAttribute('data-id');
			var currentTitle = btn.getAttribute('data-title');
			if (renameId) renameId.value = id;
			if (renameInput) renameInput.value = currentTitle;
			if (renameModal) renameModal.style.display = 'flex';
			setTimeout(function() { if (renameInput) renameInput.focus(); }, 100);
		});
	});

	if (renameSubmit) {
		renameSubmit.addEventListener('click', function() {
			var id = renameId.value;
			var newTitle = (renameInput.value || '').trim();
			if (!newTitle) {
				alert('Please enter a valid title.');
				return;
			}

			renameSubmit.disabled = true;
			renameSubmit.textContent = 'Saving...';

			var formData = new FormData();
			formData.append('action', 'cwd_rename_saved_basket');
			formData.append('nonce', nonce);
			formData.append('basket_id', id);
			formData.append('title', newTitle);

			fetch(ajaxUrl, {
				method: 'POST',
				body: formData
			})
			.then(function(res) { return res.json(); })
			.then(function(data) {
				renameSubmit.disabled = false;
				renameSubmit.textContent = 'Save Changes';
				if (data && data.success) {
					hideRenameModal();
					var card = document.getElementById('cwd-basket-card-' + id);
					if (card) {
						var titleEl = card.querySelector('.cwd-basket-title-text');
						if (titleEl) titleEl.textContent = newTitle;
						var renameBtn = card.querySelector('.cwd-rename-basket-btn');
						if (renameBtn) renameBtn.setAttribute('data-title', newTitle);
					}
				} else {
					alert(data.data && data.data.message ? data.data.message : 'Could not rename basket.');
				}
			})
			.catch(function(err) {
				renameSubmit.disabled = false;
				renameSubmit.textContent = 'Save Changes';
				alert('An error occurred.');
			});
		});
	}
});
</script>
