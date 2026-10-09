<?php

/**
 * Enterprise Customer Order & Shipment Tracking View.
 * Displays interactive delivery status, 5-stage progress milestone stepper,
 * direct courier tracking links, order items overview, and search filtering.
 */

if (! defined('ABSPATH')) {
	exit;
}

require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-shipment-tracking.php';

$current_user = wp_get_current_user();
$is_logged_in = is_user_logged_in();
$lookup_order = null;
$error_msg    = '';

// Support guest / direct lookup via Order ID + Billing Email
if (isset($_GET['cwd_track_order_id'])) {
	$lookup_id    = absint($_GET['cwd_track_order_id']);
	$lookup_email = isset($_GET['cwd_track_email']) ? sanitize_email(wp_unslash($_GET['cwd_track_email'])) : '';

	if ($lookup_id > 0) {
		$candidate = wc_get_order($lookup_id);
		if ($candidate) {
			if ($is_logged_in && (int) $candidate->get_user_id() === (int) $current_user->ID) {
				$lookup_order = $candidate;
			} elseif (! empty($lookup_email) && strtolower(trim($candidate->get_billing_email())) === strtolower(trim($lookup_email))) {
				$lookup_order = $candidate;
			} else {
				$error_msg = __('Could not find an order matching those details. Please check the order number and email address.', 'custom-woo-dashboard');
			}
		} else {
			$error_msg = __('Invalid order number provided.', 'custom-woo-dashboard');
		}
	}
}

$orders = array();
if ($lookup_order) {
	$orders = array($lookup_order);
} elseif ($is_logged_in) {
	$orders = wc_get_orders(
		array(
			'customer_id' => (int) $current_user->ID,
			'limit'       => 50,
			'orderby'     => 'date',
			'order'       => 'DESC',
		)
	);
}

// Compute counts for tab badges
$count_all        = count($orders);
$count_dispatched = 0;
$count_processing = 0;
$count_delivered  = 0;

foreach ($orders as $o) {
	$st = $o->get_status();
	$tr = CWD_V2_Shipment_Tracking::get_tracking_items($o);
	if ($st === 'completed') {
		$count_delivered++;
	} elseif (! empty($tr) || $st === 'shipped') {
		$count_dispatched++;
	} elseif ($st === 'processing' || $st === 'on-hold' || $st === 'pending') {
		$count_processing++;
	}
}

$providers = CWD_V2_Shipment_Tracking::get_providers();
?>

<div class="cwd-v2-track-dashboard">
	<!-- Page Header -->
	<div class="cwd-v2-section-heading">
		<div>
			<h3 style="margin: 0 0 4px 0; font-size: 20px; font-weight: 700; color: #0f172a;">
				<?php esc_html_e('Track Orders & Shipments', 'custom-woo-dashboard'); ?>
			</h3>
			<p style="margin: 0; color: #64748b; font-size: 13.5px;">
				<?php esc_html_e('Check live delivery progress, courier tracking numbers, and warehouse collection status.', 'custom-woo-dashboard'); ?>
			</p>
		</div>
	</div>

	<?php if (! empty($error_msg)) : ?>
		<div class="woocommerce-error" style="margin: 16px 0; padding: 12px 16px; border-radius: 6px;">
			<?php echo esc_html($error_msg); ?>
		</div>
	<?php endif; ?>

	<!-- Guest Order Lookup (if logged out and no order looked up) -->
	<?php if (! $is_logged_in && ! $lookup_order) : ?>
		<div class="cwd-v2-guest-track-card" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; margin: 20px 0; max-width: 540px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
			<h4 style="margin: 0 0 8px 0; font-size: 16px; color: #0f172a;">
				<?php esc_html_e('Track a Specific Order', 'custom-woo-dashboard'); ?>
			</h4>
			<p style="font-size: 13px; color: #64748b; margin: 0 0 16px 0;">
				<?php esc_html_e('Enter your order number and billing email address to view real-time shipment status.', 'custom-woo-dashboard'); ?>
			</p>
			<form method="GET" action="<?php echo esc_url(wc_get_endpoint_url('track-order')); ?>">
				<div style="margin-bottom: 12px;">
					<label for="cwd_track_order_id" style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 4px;">
						<?php esc_html_e('Order Number', 'custom-woo-dashboard'); ?>
					</label>
					<input type="number" id="cwd_track_order_id" name="cwd_track_order_id" required placeholder="e.g. 7604" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 14px;" />
				</div>
				<div style="margin-bottom: 16px;">
					<label for="cwd_track_email" style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 4px;">
						<?php esc_html_e('Billing Email', 'custom-woo-dashboard'); ?>
					</label>
					<input type="email" id="cwd_track_email" name="cwd_track_email" required placeholder="email@example.com" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 14px;" />
				</div>
				<button type="submit" class="button" style="background: #0284c7; color: #fff; border: none; padding: 9px 18px; border-radius: 6px; font-size: 13.5px; font-weight: 600; cursor: pointer;">
					<?php esc_html_e('Track Order', 'custom-woo-dashboard'); ?>
				</button>
			</form>
		</div>
	<?php endif; ?>

	<?php if (! empty($orders)) : ?>
		<!-- Live Search and Filter Bar -->
		<div class="cwd-v2-tracking-toolbar" style="margin: 20px 0 16px 0;">
			<div style="display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; align-items: center;">
				<!-- Search Input -->
				<div style="flex: 1 1 280px; position: relative;">
					<input type="text" id="cwd-v2-track-search" placeholder="<?php esc_attr_e('Search by order #, tracking #, or carrier...', 'custom-woo-dashboard'); ?>" style="width: 100%; padding: 9px 12px 9px 36px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px; background: #ffffff;" />
					<svg style="position: absolute; left: 12px; top: 11px; width: 16px; height: 16px; fill: none; stroke: #94a3b8; stroke-width: 2;" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
				</div>

				<!-- Filter Tabs -->
				<div class="cwd-v2-track-tabs" style="display: inline-flex; background: #f1f5f9; padding: 3px; border-radius: 8px; gap: 4px;">
					<button type="button" class="cwd-track-tab-btn active" data-filter="all" style="padding: 6px 14px; border-radius: 6px; font-size: 12.5px; font-weight: 600; border: none; background: #ffffff; color: #0f172a; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,0.06);">
						<?php esc_html_e('All', 'custom-woo-dashboard'); ?> (<?php echo esc_html($count_all); ?>)
					</button>
					<button type="button" class="cwd-track-tab-btn" data-filter="dispatched" style="padding: 6px 14px; border-radius: 6px; font-size: 12.5px; font-weight: 600; border: none; background: transparent; color: #64748b; cursor: pointer;">
						<?php esc_html_e('Dispatched', 'custom-woo-dashboard'); ?> (<?php echo esc_html($count_dispatched); ?>)
					</button>
					<button type="button" class="cwd-track-tab-btn" data-filter="processing" style="padding: 6px 14px; border-radius: 6px; font-size: 12.5px; font-weight: 600; border: none; background: transparent; color: #64748b; cursor: pointer;">
						<?php esc_html_e('Processing', 'custom-woo-dashboard'); ?> (<?php echo esc_html($count_processing); ?>)
					</button>
					<button type="button" class="cwd-track-tab-btn" data-filter="delivered" style="padding: 6px 14px; border-radius: 6px; font-size: 12.5px; font-weight: 600; border: none; background: transparent; color: #64748b; cursor: pointer;">
						<?php esc_html_e('Delivered', 'custom-woo-dashboard'); ?> (<?php echo esc_html($count_delivered); ?>)
					</button>
				</div>
			</div>
		</div>

		<!-- Tracking Orders List -->
		<div class="cwd-v2-track-orders-container" style="display: flex; flex-direction: column; gap: 20px;">
			<?php foreach ($orders as $order) : ?>
				<?php
				$order_id       = $order->get_id();
				$order_number   = $order->get_order_number();
				$status         = $order->get_status();
				$status_name    = wc_get_order_status_name($status);
				$total          = $order->get_formatted_order_total();
				$date_created   = $order->get_date_created() ? $order->get_date_created()->date_i18n(get_option('date_format')) : '';
				$tracking_items = CWD_V2_Shipment_Tracking::get_tracking_items($order);
				$timeline       = CWD_V2_Shipment_Tracking::get_order_timeline($order);
				$is_pickup      = $timeline['is_pickup'];
				$shipping_name  = $order->get_shipping_method() ?: ($is_pickup ? __('Shop Pickup', 'custom-woo-dashboard') : __('Standard Shipping', 'custom-woo-dashboard'));

				// Category filter tag
				$filter_tag = 'processing';
				if ($status === 'completed') {
					$filter_tag = 'delivered';
				} elseif (! empty($tracking_items) || $status === 'shipped') {
					$filter_tag = 'dispatched';
				}

				// Status Badge Colors
				$badge_bg    = '#f1f5f9';
				$badge_color = '#475569';
				if ($status === 'completed') {
					$badge_bg    = '#ecfdf5';
					$badge_color = '#059669';
				} elseif ($status === 'processing' || $status === 'shipped') {
					$badge_bg    = '#eff6ff';
					$badge_color = '#0284c7';
				} elseif ($status === 'on-hold') {
					$badge_bg    = '#fefce8';
					$badge_color = '#ca8a04';
				} elseif ($status === 'cancelled' || $status === 'failed') {
					$badge_bg    = '#fef2f2';
					$badge_color = '#dc2626';
				}
				?>

				<div class="cwd-v2-order-tracking-card" data-category="<?php echo esc_attr($filter_tag); ?>" data-order-number="<?php echo esc_attr($order_number); ?>" data-tracking-numbers="<?php echo esc_attr(wp_json_encode(wp_list_pluck($tracking_items, 'tracking_number'))); ?>" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04); transition: box-shadow 0.2s ease;">
					<!-- Card Header -->
					<div style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 14px 20px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px;">
						<div style="display: flex; flex-wrap: wrap; align-items: center; gap: 14px;">
							<div>
								<span style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: 0.5px; display: block;">
									<?php esc_html_e('Order', 'custom-woo-dashboard'); ?>
								</span>
								<a href="<?php echo esc_url($order->get_view_order_url()); ?>" style="font-size: 15px; font-weight: 700; color: #0284c7; text-decoration: none;">
									#<?php echo esc_html($order_number); ?>
								</a>
							</div>
							<div style="border-left: 1px solid #cbd5e1; padding-left: 14px;">
								<span style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: 0.5px; display: block;">
									<?php esc_html_e('Date Placed', 'custom-woo-dashboard'); ?>
								</span>
								<span style="font-size: 13.5px; font-weight: 600; color: #1e293b;">
									<?php echo esc_html($date_created); ?>
								</span>
							</div>
							<div style="border-left: 1px solid #cbd5e1; padding-left: 14px;">
								<span style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: 0.5px; display: block;">
									<?php esc_html_e('Total', 'custom-woo-dashboard'); ?>
								</span>
								<span style="font-size: 13.5px; font-weight: 700; color: #0f172a;">
									<?php echo wp_kses_post($total); ?>
								</span>
							</div>
						</div>

						<!-- Status Badges -->
						<div style="display: flex; align-items: center; gap: 8px;">
							<?php if ($is_pickup) : ?>
								<span style="background: #f1f5f9; color: #475569; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
									<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
									<?php esc_html_e('Shop Pickup', 'custom-woo-dashboard'); ?>
								</span>
							<?php else : ?>
								<span style="background: #f1f5f9; color: #475569; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
									<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
									<?php echo esc_html($shipping_name); ?>
								</span>
							<?php endif; ?>

							<span style="background: <?php echo esc_attr($badge_bg); ?>; color: <?php echo esc_attr($badge_color); ?>; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700;">
								<?php echo esc_html($status_name); ?>
							</span>
						</div>
					</div>

					<!-- Visual Timeline Stepper -->
					<div style="padding: 24px 20px 20px 20px; border-bottom: 1px solid #f1f5f9;">
						<div class="cwd-v2-timeline-stepper" style="display: flex; justify-content: space-between; position: relative;">
							<!-- Background Connecting Line -->
							<div class="cwd-v2-timeline-line" style="position: absolute; top: 16px; left: 24px; right: 24px; height: 3px; background: #e2e8f0; z-index: 1;"></div>

							<?php foreach ($timeline['steps'] as $idx => $step) : ?>
								<?php
								$is_done   = ! empty($step['completed']);
								$is_active = ! empty($step['active']);

								$circle_bg     = $is_done ? '#0284c7' : ($is_active ? '#0284c7' : '#ffffff');
								$circle_border = $is_done || $is_active ? '#0284c7' : '#cbd5e1';
								$text_color    = $is_done || $is_active ? '#0f172a' : '#94a3b8';
								?>
								<div class="cwd-v2-timeline-step" style="position: relative; z-index: 2; text-align: center; flex: 1;">
									<!-- Step Dot -->
									<div style="width: 32px; height: 32px; margin: 0 auto 8px auto; border-radius: 50%; background: <?php echo esc_attr($circle_bg); ?>; border: 2px solid <?php echo esc_attr($circle_border); ?>; display: flex; align-items: center; justify-content: center; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
										<?php if ($is_done) : ?>
											<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
										<?php elseif ($is_active) : ?>
											<div style="width: 10px; height: 10px; border-radius: 50%; background: #ffffff;"></div>
										<?php else : ?>
											<div style="width: 8px; height: 8px; border-radius: 50%; background: #cbd5e1;"></div>
										<?php endif; ?>
									</div>
									<!-- Title -->
									<div style="font-size: 12.5px; font-weight: 700; color: <?php echo esc_attr($text_color); ?>; margin-bottom: 2px;">
										<?php echo esc_html($step['title']); ?>
									</div>
									<!-- Date Timestamp if present -->
									<?php if (! empty($step['date'])) : ?>
										<div style="font-size: 11px; color: #64748b;">
											<?php echo esc_html($step['date']); ?>
										</div>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					</div>

					<!-- Courier Tracking Numbers Section (if available) -->
					<?php if (! empty($tracking_items)) : ?>
						<div style="padding: 16px 20px; background: #fdfefe; border-bottom: 1px solid #f1f5f9;">
							<div style="display: flex; flex-direction: column; gap: 10px;">
								<?php foreach ($tracking_items as $tr) : ?>
									<?php
									$prv  = $tr['tracking_provider'] ?? 'custom';
									$name = $providers[$prv]['label'] ?? ($tr['provider_label'] ?? ucfirst($prv));
									$num  = $tr['tracking_number'] ?? '';
									$lnk  = $tr['custom_tracking_link'] ?? CWD_V2_Shipment_Tracking::get_tracking_link($prv, $num);
									$date = $tr['date_shipped'] ?? '';
									?>
									<div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; padding: 12px 16px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; gap: 12px;">
										<div style="display: flex; align-items: center; gap: 12px;">
											<!-- Package Icon -->
											<div style="width: 36px; height: 36px; border-radius: 8px; background: #e0f2fe; display: flex; align-items: center; justify-content: center; color: #0284c7;">
												<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16.5 9.4 7.55 4.24a1.78 1.78 0 0 0-1.8 0l-3.3 1.9A1.78 1.78 0 0 0 1.5 7.7v8.6a1.78 1.78 0 0 0 .95 1.56l7.5 4.33a1.78 1.78 0 0 0 1.8 0l7.5-4.33a1.78 1.78 0 0 0 .95-1.56V7.7a1.78 1.78 0 0 0-.95-1.56L16.5 9.4z"/><polyline points="3.29 7 12 12 20.71 7"/><line x1="12" y1="22" x2="12" y2="12"/></svg>
											</div>
											<div>
												<div style="font-size: 13.5px; font-weight: 700; color: #0f172a;">
													<?php echo esc_html($name); ?>
												</div>
												<div style="display: flex; align-items: center; gap: 8px; margin-top: 2px;">
													<span style="font-family: monospace; font-size: 14px; font-weight: 600; color: #0284c7;">
														<?php echo esc_html($num); ?>
													</span>
													<button type="button" class="cwd-copy-btn" data-copy="<?php echo esc_attr($num); ?>" title="<?php esc_attr_e('Copy tracking number', 'custom-woo-dashboard'); ?>" style="background: transparent; border: none; cursor: pointer; padding: 2px; color: #94a3b8;">
														<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
													</button>
													<?php if ($date) : ?>
														<span style="font-size: 11.5px; color: #64748b;">
															&bull; <?php echo esc_html(sprintf(__('Shipped on %s', 'custom-woo-dashboard'), $date)); ?>
														</span>
													<?php endif; ?>
												</div>
											</div>
										</div>

										<!-- Live Tracking Action -->
										<div>
											<?php if ($lnk) : ?>
												<a href="<?php echo esc_url($lnk); ?>" target="_blank" rel="noopener noreferrer" style="display: inline-flex; align-items: center; gap: 6px; background: #0284c7; color: #ffffff; padding: 8px 16px; border-radius: 6px; font-size: 12.5px; font-weight: 700; text-decoration: none; box-shadow: 0 1px 2px rgba(2, 132, 199, 0.2);">
													<span><?php esc_html_e('Track Parcel', 'custom-woo-dashboard'); ?></span>
													<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
												</a>
											<?php endif; ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>

					<!-- Items Overview & Actions -->
					<div style="padding: 16px 20px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 14px;">
						<!-- Line Item Thumbnails / Snippets -->
						<div style="display: flex; align-items: center; gap: 10px; overflow-x: auto; max-width: 100%;">
							<?php
							$items_count = $order->get_item_count();
							$shown       = 0;
							foreach ($order->get_items() as $item) :
								if ($shown >= 4) {
									break;
								}
								$product = $item->get_product();
								$img     = $product ? $product->get_image('thumbnail', array('style' => 'width: 44px; height: 44px; object-fit: cover; border-radius: 6px; border: 1px solid #e2e8f0;')) : '';
								if ($img) :
									?>
									<div title="<?php echo esc_attr($item->get_name()); ?>" style="flex-shrink: 0; position: relative;">
										<?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php if ($item->get_quantity() > 1) : ?>
											<span style="position: absolute; bottom: -4px; right: -4px; background: #0f172a; color: #ffffff; font-size: 10px; font-weight: 700; border-radius: 10px; padding: 1px 5px;">
												x<?php echo esc_html($item->get_quantity()); ?>
											</span>
										<?php endif; ?>
									</div>
									<?php
									$shown++;
								endif;
							endforeach;
							?>
							<div style="font-size: 12.5px; color: #64748b; margin-left: 4px;">
								<?php echo esc_html(sprintf(_n('%d item', '%d items', $items_count, 'custom-woo-dashboard'), $items_count)); ?>
							</div>
						</div>

						<!-- Action Buttons -->
						<div style="display: flex; align-items: center; gap: 8px;">
							<a href="<?php echo esc_url($order->get_view_order_url()); ?>" class="button view" style="font-size: 12.5px; font-weight: 600; padding: 7px 14px; border-radius: 6px; border: 1px solid #cbd5e1; background: #ffffff; color: #1e293b; text-decoration: none;">
								<?php esc_html_e('View Order Details', 'custom-woo-dashboard'); ?>
							</a>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<!-- Empty Search State -->
		<div id="cwd-v2-track-empty" style="display: none; text-align: center; padding: 40px 20px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; margin-top: 20px;">
			<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" style="margin: 0 auto 12px auto; display: block;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
			<h4 style="margin: 0 0 6px 0; color: #0f172a; font-size: 16px;"><?php esc_html_e('No matching shipments found', 'custom-woo-dashboard'); ?></h4>
			<p style="margin: 0; color: #64748b; font-size: 13px;"><?php esc_html_e('Try adjusting your search terms or filter selection.', 'custom-woo-dashboard'); ?></p>
		</div>

		<!-- Client-Side Filter & Search Script -->
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var searchInput = document.getElementById('cwd-v2-track-search');
			var tabButtons = document.querySelectorAll('.cwd-track-tab-btn');
			var cards = document.querySelectorAll('.cwd-v2-order-tracking-card');
			var emptyNotice = document.getElementById('cwd-v2-track-empty');
			var currentFilter = 'all';

			function applyFilter() {
				var term = (searchInput ? searchInput.value.toLowerCase().trim() : '');
				var visibleCount = 0;

				cards.forEach(function(card) {
					var cat = card.getAttribute('data-category') || '';
					var num = card.getAttribute('data-order-number') || '';
					var trackingRaw = card.getAttribute('data-tracking-numbers') || '';
					var cardText = card.textContent.toLowerCase();

					var matchesTab = (currentFilter === 'all' || cat === currentFilter);
					var matchesSearch = true;

					if (term.length > 0) {
						matchesSearch = (cardText.indexOf(term) !== -1 || num.indexOf(term) !== -1 || trackingRaw.toLowerCase().indexOf(term) !== -1);
					}

					if (matchesTab && matchesSearch) {
						card.style.display = 'block';
						visibleCount++;
					} else {
						card.style.display = 'none';
					}
				});

				if (emptyNotice) {
					emptyNotice.style.display = (visibleCount === 0) ? 'block' : 'none';
				}
			}

			if (searchInput) {
				searchInput.addEventListener('input', applyFilter);
			}

			tabButtons.forEach(function(btn) {
				btn.addEventListener('click', function() {
					tabButtons.forEach(function(b) {
						b.classList.remove('active');
						b.style.background = 'transparent';
						b.style.color = '#64748b';
						b.style.boxShadow = 'none';
					});
					btn.classList.add('active');
					btn.style.background = '#ffffff';
					btn.style.color = '#0f172a';
					btn.style.boxShadow = '0 1px 2px rgba(0,0,0,0.06)';

					currentFilter = btn.getAttribute('data-filter') || 'all';
					applyFilter();
				});
			});

			// Copy to clipboard
			document.querySelectorAll('.cwd-copy-btn').forEach(function(copyBtn) {
				copyBtn.addEventListener('click', function() {
					var text = copyBtn.getAttribute('data-copy');
					if (navigator.clipboard && text) {
						navigator.clipboard.writeText(text).then(function() {
							var oldSvg = copyBtn.innerHTML;
							copyBtn.innerHTML = '<span style="font-size: 11px; color: #059669; font-weight: 700;">Copied!</span>';
							setTimeout(function() { copyBtn.innerHTML = oldSvg; }, 2000);
						});
					}
				});
			});
		});
		</script>
	<?php else : ?>
		<div class="woocommerce-message woocommerce-message--info woocommerce-info" style="margin-top: 20px;">
			<?php esc_html_e('You do not have any active shipments or order tracking records yet.', 'custom-woo-dashboard'); ?>
		</div>
	<?php endif; ?>
</div>