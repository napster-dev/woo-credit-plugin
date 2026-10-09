<?php

/**
 * Enterprise Shipment Tracking System for Custom WooCommerce Dashboard.
 * Provides carrier tracking links, order timeline stepper, customer notifications,
 * admin tracking management metabox, and automated sync REST API endpoints.
 */

if (! defined('ABSPATH')) {
	exit;
}

class CWD_V2_Shipment_Tracking
{
	const META_KEY = '_wc_shipment_tracking_items';

	/**
	 * Supported carriers and their official tracking URL formats.
	 */
	public static function get_providers()
	{
		return array(
			'royal-mail'  => array(
				'label' => 'Royal Mail',
				'url'   => 'https://www.royalmail.com/track-your-item#/tracking-details/%s',
			),
			'dpd-uk'      => array(
				'label' => 'DPD UK',
				'url'   => 'https://track.dpd.co.uk/parcels/%s',
			),
			'dhl-express' => array(
				'label' => 'DHL Express',
				'url'   => 'https://www.dhl.com/gb-en/home/tracking/tracking-express.html?submit=1&tracking-id=%s',
			),
			'dhl-parcel'  => array(
				'label' => 'DHL Parcel UK',
				'url'   => 'https://track.dhlparcel.co.uk/?tracking=%s',
			),
			'evri'        => array(
				'label' => 'Evri (Hermes)',
				'url'   => 'https://www.evri.com/track/parcel/%s',
			),
			'parcelforce' => array(
				'label' => 'Parcelforce',
				'url'   => 'https://www.parcelforce.com/track-trace?trackNumber=%s',
			),
			'ups'         => array(
				'label' => 'UPS',
				'url'   => 'https://www.ups.com/track?tracknum=%s',
			),
			'fedex'       => array(
				'label' => 'FedEx UK',
				'url'   => 'https://www.fedex.com/fedextrack/?trknbr=%s',
			),
			'dx'          => array(
				'label' => 'DX Delivery',
				'url'   => 'https://www.dxdelivery.com/tracking?tracking_number=%s',
			),
			'apc'         => array(
				'label' => 'APC Overnight',
				'url'   => 'https://apc-overnight.com/receiving-a-parcel/tracking?consignment=%s',
			),
			'ews-fleet'   => array(
				'label' => 'EWS Dedicated Fleet / Van',
				'url'   => '',
			),
			'custom'      => array(
				'label' => 'Other Courier',
				'url'   => '',
			),
		);
	}

	public static function init()
	{
		// Admin Meta Box on WooCommerce Order Edit screen (HPOS + Traditional CPT)
		add_action('add_meta_boxes', array(__CLASS__, 'register_order_meta_box'), 30);
		add_action('woocommerce_process_shop_order_meta', array(__CLASS__, 'save_admin_order_tracking'), 20, 1);
		add_action('wp_ajax_cwd_v2_delete_tracking_item', array(__CLASS__, 'ajax_delete_tracking_item'));

		// Inject tracking card into customer order emails
		add_action('woocommerce_email_after_order_table', array(__CLASS__, 'display_email_tracking_card'), 15, 4);

		// Order tracking REST API for live warehouse / Odoo sync
		add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
	}

	/**
	 * Build carrier tracking link.
	 *
	 * @param string $provider
	 * @param string $number
	 * @param string $custom_link
	 * @return string
	 */
	public static function get_tracking_link($provider, $number, $custom_link = '')
	{
		if (! empty($custom_link)) {
			return esc_url($custom_link);
		}

		$providers = self::get_providers();
		if (isset($providers[$provider]) && ! empty($providers[$provider]['url'])) {
			return sprintf($providers[$provider]['url'], rawurlencode(trim($number)));
		}

		return '';
	}

	/**
	 * Retrieve all tracking items for an order.
	 *
	 * @param WC_Order|int $order
	 * @return array
	 */
	public static function get_tracking_items($order)
	{
		if (is_numeric($order)) {
			$order = wc_get_order($order);
		}
		if (! $order instanceof WC_Order) {
			return array();
		}

		$items = $order->get_meta(self::META_KEY, true);
		if (! is_array($items)) {
			$items = array();
		}

		// Also check standalone meta fields if populated by other gateways/sync
		if (empty($items)) {
			$single_num = $order->get_meta('_tracking_number', true) ?: $order->get_meta('tracking_number', true);
			$single_prv = $order->get_meta('_tracking_provider', true) ?: $order->get_meta('tracking_provider', true);
			$single_lnk = $order->get_meta('_custom_tracking_link', true) ?: $order->get_meta('custom_tracking_link', true);

			if (! empty($single_num)) {
				$items[] = array(
					'tracking_id'          => md5($single_num . time()),
					'tracking_provider'    => $single_prv ?: 'custom',
					'tracking_number'      => sanitize_text_field($single_num),
					'date_shipped'         => current_time('Y-m-d'),
					'custom_tracking_link' => $single_lnk ? esc_url($single_lnk) : self::get_tracking_link($single_prv, $single_num),
				);
			}
		}

		return $items;
	}

	/**
	 * Save tracking items to an order.
	 *
	 * @param WC_Order|int $order
	 * @param array        $items
	 * @return bool
	 */
	public static function set_tracking_items($order, array $items)
	{
		if (is_numeric($order)) {
			$order = wc_get_order($order);
		}
		if (! $order instanceof WC_Order) {
			return false;
		}

		$order->update_meta_data(self::META_KEY, $items);
		$order->save();
		return true;
	}

	/**
	 * Add a tracking item to an order.
	 *
	 * @param WC_Order|int $order
	 * @param array        $data
	 * @param bool         $notify_customer
	 * @return array|false
	 */
	public static function add_tracking_item($order, array $data, $notify_customer = false)
	{
		if (is_numeric($order)) {
			$order = wc_get_order($order);
		}
		if (! $order instanceof WC_Order) {
			return false;
		}

		$raw_provider = sanitize_text_field($data['tracking_provider'] ?? 'custom');
		$provider     = self::normalize_provider_slug($raw_provider);
		$number       = sanitize_text_field($data['tracking_number'] ?? '');
		$date_shipped = sanitize_text_field($data['date_shipped'] ?? current_time('Y-m-d'));
		$custom_link  = esc_url_raw($data['custom_tracking_link'] ?? '');

		if (empty($number)) {
			return false;
		}

		$link = ! empty($custom_link) ? $custom_link : self::get_tracking_link($provider, $number);

		$providers = self::get_providers();
		$label     = ! empty($data['provider_label']) ? sanitize_text_field($data['provider_label']) : ($providers[$provider]['label'] ?? ucfirst($provider));

		$new_item = array(
			'tracking_id'          => md5($provider . $number . microtime()),
			'tracking_provider'    => $provider,
			'provider_label'       => $label,
			'tracking_number'      => $number,
			'date_shipped'         => $date_shipped,
			'custom_tracking_link' => $link,
		);

		$items   = self::get_tracking_items($order);
		$items[] = $new_item;
		self::set_tracking_items($order, $items);

		// Record customer-visible order note
		$note = sprintf(
			/* translators: 1: Carrier label, 2: Tracking number */
			__('Shipment tracking added: %1$s — %2$s', 'custom-woo-dashboard'),
			$label,
			$number
		);

		if (! empty($link)) {
			$note .= ' (' . $link . ')';
		}

		$order->add_order_note($note, $notify_customer ? 1 : 0);

		if ($notify_customer && $order->get_status() !== 'completed') {
			$order->update_status('completed', __('Order completed with shipment tracking.', 'custom-woo-dashboard'));
		}

		return $new_item;
	}

	/**
	 * Register admin order meta box.
	 */
	public static function register_order_meta_box()
	{
		$screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') &&
				  wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
				  ? wc_get_page_screen_id('shop-order')
				  : 'shop_order';

		add_meta_box(
			'cwd_v2_shipment_tracking_box',
			__('Shipment Tracking (EWS)', 'custom-woo-dashboard'),
			array(__CLASS__, 'render_admin_order_meta_box'),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Render admin meta box content.
	 *
	 * @param WP_Post|WC_Order $post_or_order
	 */
	public static function render_admin_order_meta_box($post_or_order)
	{
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
		if (! $order) {
			return;
		}

		$items     = self::get_tracking_items($order);
		$providers = self::get_providers();
		wp_nonce_field('cwd_v2_save_tracking', 'cwd_v2_tracking_nonce');
		?>
		<div class="cwd-admin-tracking-box">
			<?php if (! empty($items)) : ?>
				<div class="cwd-admin-tracking-list" style="margin-bottom: 15px;">
					<?php foreach ($items as $item) : ?>
						<?php
						$item_id  = $item['tracking_id'] ?? '';
						$provider = $item['tracking_provider'] ?? 'custom';
						$label    = $providers[$provider]['label'] ?? ($item['provider_label'] ?? ucfirst($provider));
						$number   = $item['tracking_number'] ?? '';
						$link     = $item['custom_tracking_link'] ?? self::get_tracking_link($provider, $number);
						$date     = $item['date_shipped'] ?? '';
						?>
						<div class="cwd-admin-tracking-row" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; margin-bottom: 8px;">
							<div style="font-weight: 600; font-size: 13px; color: #0f172a;">
								<?php echo esc_html($label); ?>
							</div>
							<div style="font-size: 12px; margin: 3px 0;">
								<?php if ($link) : ?>
									<a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener noreferrer" style="font-family: monospace; font-weight: 600;">
										<?php echo esc_html($number); ?>
									</a>
								<?php else : ?>
									<span style="font-family: monospace; font-weight: 600;"><?php echo esc_html($number); ?></span>
								<?php endif; ?>
							</div>
							<?php if ($date) : ?>
								<div style="font-size: 11px; color: #64748b;">
									<?php echo esc_html(sprintf(__('Shipped: %s', 'custom-woo-dashboard'), $date)); ?>
								</div>
							<?php endif; ?>
							<div style="text-align: right; margin-top: 4px;">
								<button type="button" class="button-link-delete cwd-delete-tracking-btn" data-order-id="<?php echo esc_attr($order->get_id()); ?>" data-tracking-id="<?php echo esc_attr($item_id); ?>" style="font-size: 11px; color: #dc2626; cursor: pointer;">
									<?php esc_html_e('Remove', 'custom-woo-dashboard'); ?>
								</button>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="cwd-admin-add-tracking-form" style="border-top: 1px solid #e2e8f0; padding-top: 10px;">
				<h4 style="margin: 0 0 10px 0; font-size: 13px;"><?php esc_html_e('Add Tracking Number', 'custom-woo-dashboard'); ?></h4>
				
				<p style="margin: 0 0 8px 0;">
					<label for="cwd_tracking_provider" style="display:block; font-size: 12px; font-weight: 500; margin-bottom: 2px;"><?php esc_html_e('Courier / Provider', 'custom-woo-dashboard'); ?></label>
					<select name="cwd_tracking_provider" id="cwd_tracking_provider" style="width: 100%;">
						<?php foreach ($providers as $key => $prov) : ?>
							<option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($prov['label']); ?></option>
						<?php endforeach; ?>
					</select>
				</p>

				<p style="margin: 0 0 8px 0;">
					<label for="cwd_tracking_number" style="display:block; font-size: 12px; font-weight: 500; margin-bottom: 2px;"><?php esc_html_e('Tracking Number', 'custom-woo-dashboard'); ?></label>
					<input type="text" name="cwd_tracking_number" id="cwd_tracking_number" placeholder="e.g. 15502345678GB" style="width: 100%;" />
				</p>

				<p style="margin: 0 0 8px 0;">
					<label for="cwd_date_shipped" style="display:block; font-size: 12px; font-weight: 500; margin-bottom: 2px;"><?php esc_html_e('Date Shipped', 'custom-woo-dashboard'); ?></label>
					<input type="date" name="cwd_date_shipped" id="cwd_date_shipped" value="<?php echo esc_attr(current_time('Y-m-d')); ?>" style="width: 100%;" />
				</p>

				<p style="margin: 0 0 8px 0;">
					<label for="cwd_custom_tracking_link" style="display:block; font-size: 12px; font-weight: 500; margin-bottom: 2px;"><?php esc_html_e('Custom Link (optional)', 'custom-woo-dashboard'); ?></label>
					<input type="url" name="cwd_custom_tracking_link" id="cwd_custom_tracking_link" placeholder="https://..." style="width: 100%;" />
				</p>

				<p style="margin: 0 0 10px 0;">
					<label style="font-size: 12px; display: inline-flex; align-items: center; gap: 4px;">
						<input type="checkbox" name="cwd_notify_customer" value="1" checked="checked" />
						<?php esc_html_e('Email tracking link to customer', 'custom-woo-dashboard'); ?>
					</label>
				</p>
			</div>
		</div>

		<script>
		jQuery(function($) {
			$(document).on('click', '.cwd-delete-tracking-btn', function(e) {
				e.preventDefault();
				if (!confirm('<?php echo esc_js(__('Remove this shipment tracking item?', 'custom-woo-dashboard')); ?>')) {
					return;
				}
				var $btn = $(this);
				var orderId = $btn.data('order-id');
				var trackingId = $btn.data('tracking-id');

				$btn.text('...');
				$.post(ajaxurl, {
					action: 'cwd_v2_delete_tracking_item',
					order_id: orderId,
					tracking_id: trackingId,
					nonce: '<?php echo esc_js(wp_create_nonce('cwd_v2_delete_tracking')); ?>'
				}, function(res) {
					if (res.success) {
						$btn.closest('.cwd-admin-tracking-row').fadeOut(300, function() { $(this).remove(); });
					} else {
						alert(res.data || 'Failed to remove tracking');
						$btn.text('<?php echo esc_js(__('Remove', 'custom-woo-dashboard')); ?>');
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Save tracking submitted via admin order form.
	 *
	 * @param int $order_id
	 */
	public static function save_admin_order_tracking($order_id)
	{
		if (! isset($_POST['cwd_v2_tracking_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cwd_v2_tracking_nonce'])), 'cwd_v2_save_tracking')) {
			return;
		}

		if (empty($_POST['cwd_tracking_number'])) {
			return;
		}

		$order = wc_get_order($order_id);
		if (! $order) {
			return;
		}

		$tracking_data = array(
			'tracking_provider'    => isset($_POST['cwd_tracking_provider']) ? sanitize_text_field(wp_unslash($_POST['cwd_tracking_provider'])) : 'custom',
			'tracking_number'      => sanitize_text_field(wp_unslash($_POST['cwd_tracking_number'])),
			'date_shipped'         => isset($_POST['cwd_date_shipped']) ? sanitize_text_field(wp_unslash($_POST['cwd_date_shipped'])) : current_time('Y-m-d'),
			'custom_tracking_link' => isset($_POST['cwd_custom_tracking_link']) ? esc_url_raw(wp_unslash($_POST['cwd_custom_tracking_link'])) : '',
		);

		$notify = ! empty($_POST['cwd_notify_customer']);
		self::add_tracking_item($order, $tracking_data, $notify);
	}

	/**
	 * AJAX handler to delete a tracking item.
	 */
	public static function ajax_delete_tracking_item()
	{
		check_ajax_referer('cwd_v2_delete_tracking', 'nonce');

		if (! current_user_can('edit_shop_orders')) {
			wp_send_json_error(__('Unauthorized', 'custom-woo-dashboard'));
		}

		$order_id    = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		$tracking_id = isset($_POST['tracking_id']) ? sanitize_text_field(wp_unslash($_POST['tracking_id'])) : '';

		$order = wc_get_order($order_id);
		if (! $order) {
			wp_send_json_error(__('Order not found', 'custom-woo-dashboard'));
		}

		$items     = self::get_tracking_items($order);
		$new_items = array();
		$found     = false;

		foreach ($items as $item) {
			if (($item['tracking_id'] ?? '') === $tracking_id) {
				$found = true;
				continue;
			}
			$new_items[] = $item;
		}

		if ($found) {
			self::set_tracking_items($order, $new_items);
			$order->add_order_note(__('Shipment tracking item removed.', 'custom-woo-dashboard'), 0);
			wp_send_json_success();
		}

		wp_send_json_error(__('Tracking item not found', 'custom-woo-dashboard'));
	}

	/**
	 * Display tracking card inside order confirmation & completed emails.
	 */
	public static function display_email_tracking_card($order, $sent_to_admin, $plain_text, $email)
	{
		if ($sent_to_admin || ! $order instanceof WC_Order) {
			return;
		}

		$items = self::get_tracking_items($order);
		if (empty($items)) {
			return;
		}

		$providers = self::get_providers();

		if ($plain_text) {
			echo "\n" . esc_html__('--- SHIPMENT TRACKING ---', 'custom-woo-dashboard') . "\n";
			foreach ($items as $item) {
				$prv  = $item['tracking_provider'] ?? 'custom';
				$name = $providers[$prv]['label'] ?? ($item['provider_label'] ?? ucfirst($prv));
				$num  = $item['tracking_number'] ?? '';
				$lnk  = $item['custom_tracking_link'] ?? self::get_tracking_link($prv, $num);
				echo esc_html($name . ': ' . $num) . ($lnk ? ' (' . esc_url($lnk) . ')' : '') . "\n";
			}
			echo "\n";
			return;
		}
		?>
		<div style="margin: 25px 0 20px 0; padding: 18px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
			<h3 style="margin: 0 0 10px 0; color: #0f172a; font-size: 15px; font-weight: 700;">
				<?php esc_html_e('Shipment Tracking', 'custom-woo-dashboard'); ?>
			</h3>
			<?php foreach ($items as $item) : ?>
				<?php
				$prv  = $item['tracking_provider'] ?? 'custom';
				$name = $providers[$prv]['label'] ?? ($item['provider_label'] ?? ucfirst($prv));
				$num  = $item['tracking_number'] ?? '';
				$lnk  = $item['custom_tracking_link'] ?? self::get_tracking_link($prv, $num);
				?>
				<div style="margin-bottom: 10px; padding: 10px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px;">
					<div style="font-size: 13px; font-weight: 600; color: #1e293b;"><?php echo esc_html($name); ?></div>
					<div style="font-size: 14px; font-family: monospace; color: #0284c7; margin: 4px 0;">
						<?php echo esc_html($num); ?>
					</div>
					<?php if ($lnk) : ?>
						<a href="<?php echo esc_url($lnk); ?>" target="_blank" rel="noopener noreferrer" style="display: inline-block; margin-top: 6px; padding: 6px 14px; background: #0284c7; color: #ffffff; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: 600;">
							<?php esc_html_e('Track Parcel', 'custom-woo-dashboard'); ?> &rarr;
						</a>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Normalize carrier provider name / slug to recognized system provider key.
	 *
	 * @param string $provider
	 * @return string
	 */
	public static function normalize_provider_slug($provider)
	{
		$slug  = strtolower(trim((string) $provider));
		$clean = preg_replace('/[^a-z0-9]+/', '-', $slug);
		$clean = trim((string) $clean, '-');

		$map = array(
			'royal-mail'   => 'royal-mail',
			'royalmail'    => 'royal-mail',
			'dpd'          => 'dpd-uk',
			'dpd-uk'       => 'dpd-uk',
			'dpd-local'    => 'dpd-uk',
			'dhl'          => 'dhl-express',
			'dhl-express'  => 'dhl-express',
			'dhl-parcel'   => 'dhl-parcel',
			'evri'         => 'evri',
			'hermes'       => 'evri',
			'parcelforce'  => 'parcelforce',
			'ups'          => 'ups',
			'fedex'        => 'fedex',
			'fedex-uk'     => 'fedex',
			'dx'           => 'dx',
			'apc'          => 'apc',
			'ews-fleet'    => 'ews-fleet',
			'ews'          => 'ews-fleet',
			'fleet'        => 'ews-fleet',
			'van'          => 'ews-fleet',
		);

		return $map[$clean] ?? ($clean ?: 'custom');
	}

	/**
	 * Register REST routes for external / warehouse / Odoo automated updates.
	 */
	public static function register_rest_routes()
	{
		$namespaces = array('cwd/v1', 'cwd/v2', 'cwd-v2/v1');
		foreach ($namespaces as $ns) {
			register_rest_route(
				$ns,
				'/orders/(?P<id>\d+)/tracking',
				array(
					'methods'             => 'POST',
					'callback'            => array(__CLASS__, 'rest_add_tracking'),
					'permission_callback' => array(__CLASS__, 'rest_check_permission'),
					'args'                => array(
						'id'              => array('required' => false, 'type' => 'integer'),
						'tracking_number' => array('required' => false, 'type' => 'string'),
					),
				)
			);
		}

		// Durable Outbox / Event bridge endpoint for Odoo WooDashboardOutbox
		foreach (array('cwd/v2', 'cwd/v1') as $ns) {
			register_rest_route(
				$ns,
				'/odoo-events',
				array(
					'methods'             => 'POST',
					'callback'            => array(__CLASS__, 'rest_handle_odoo_events'),
					'permission_callback' => array(__CLASS__, 'rest_check_permission'),
				)
			);
		}
	}

	public static function rest_check_permission($request)
	{
		if (current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce') || current_user_can('manage_options')) {
			return true;
		}

		$auth_header = $request->get_header('X-Odoo-Secret') 
			?: $request->get_header('X-CWD-SECRET-KEY') 
			?: $request->get_header('X-ODOO-SECRET') 
			?: $request->get_param('secret_key');

		$secret_key = defined('CWD_ODOO_SECRET_KEY') ? CWD_ODOO_SECRET_KEY : get_option('cwd_odoo_secret_key', '');

		if (! empty($secret_key) && ! empty($auth_header) && hash_equals((string) $secret_key, (string) $auth_header)) {
			return true;
		}

		return new WP_Error('rest_forbidden', __('Invalid credentials for tracking API.', 'custom-woo-dashboard'), array('status' => 403));
	}

	public static function rest_add_tracking($request)
	{
		$order_id = absint($request->get_param('id') ?: $request->get_param('order_id') ?: $request->get_param('woo_order_id'));
		$order    = $order_id ? wc_get_order($order_id) : null;

		if (! $order) {
			return new WP_Error('not_found', __('Order not found', 'custom-woo-dashboard'), array('status' => 404));
		}

		$provider = sanitize_text_field(
			$request->get_param('carrier') 
			?: $request->get_param('tracking_provider') 
			?: $request->get_param('carrier_name') 
			?: 'custom'
		);
		$number   = sanitize_text_field(
			$request->get_param('tracking_number') 
			?: $request->get_param('carrier_tracking_ref') 
			?: $request->get_param('tracking_reference') 
			?: $request->get_param('reference') 
			?: ''
		);
		$date     = sanitize_text_field(
			$request->get_param('date_shipped') 
			?: $request->get_param('shipped_at') 
			?: $request->get_param('date_done') 
			?: current_time('Y-m-d')
		);
		$link     = esc_url_raw(
			$request->get_param('custom_link') 
			?: $request->get_param('custom_tracking_link') 
			?: $request->get_param('tracking_url') 
			?: ''
		);

		$tracking_data = array(
			'tracking_provider'    => self::normalize_provider_slug($provider),
			'tracking_number'      => $number,
			'date_shipped'         => $date,
			'custom_tracking_link' => $link,
		);

		$notify = $request->get_param('notify_customer') !== null ? (bool) $request->get_param('notify_customer') : true;
		$result = self::add_tracking_item($order, $tracking_data, $notify);

		if (! $result) {
			return new WP_Error('error', __('Failed to add tracking item', 'custom-woo-dashboard'), array('status' => 400));
		}

		return rest_ensure_response(array(
			'success'  => true,
			'order_id' => $order->get_id(),
			'tracking' => $result,
		));
	}

	/**
	 * Handle incoming Odoo events dispatched by WooDashboardOutbox.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_handle_odoo_events($request)
	{
		$operation = sanitize_text_field(
			$request->get_header('X-Odoo-Operation') 
			?: $request->get_param('operation') 
			?: $request->get_param('event') 
			?: 'general'
		);
		$params = $request->get_json_params() ?: $request->get_params();

		// 1. Shipment Tracking events
		if (in_array($operation, array('shipment_tracking', 'tracking_update', 'order_shipped'), true) || 
			isset($params['tracking_number']) || isset($params['carrier_tracking_ref'])) {
			
			$order_id = absint($params['woo_order_id'] ?? $params['order_id'] ?? 0);
			$order    = $order_id ? wc_get_order($order_id) : null;
			if (! $order) {
				return new WP_Error('not_found', __('Order not found', 'custom-woo-dashboard'), array('status' => 404));
			}

			$provider = sanitize_text_field($params['carrier'] ?? $params['tracking_provider'] ?? $params['carrier_name'] ?? 'custom');
			$number   = sanitize_text_field($params['tracking_number'] ?? $params['carrier_tracking_ref'] ?? $params['tracking_reference'] ?? '');
			$date     = sanitize_text_field($params['shipped_at'] ?? $params['date_shipped'] ?? $params['date_done'] ?? current_time('Y-m-d'));
			$link     = esc_url_raw($params['custom_tracking_link'] ?? $params['tracking_url'] ?? $params['custom_link'] ?? '');
			$notify   = isset($params['notify_customer']) ? (bool) $params['notify_customer'] : true;

			$tracking_data = array(
				'tracking_provider'    => self::normalize_provider_slug($provider),
				'tracking_number'      => $number,
				'date_shipped'         => $date,
				'custom_tracking_link' => $link,
			);

			$result = self::add_tracking_item($order, $tracking_data, $notify);
			if (! $result) {
				return new WP_Error('error', __('Failed to add tracking item', 'custom-woo-dashboard'), array('status' => 400));
			}

			return rest_ensure_response(array(
				'success'   => true,
				'operation' => $operation,
				'order_id'  => $order->get_id(),
				'tracking'  => $result,
			));
		}

		// 2. Backorder update delegation
		if ('backorder_update' === $operation || isset($params['backorder_status'])) {
			if (class_exists('CWD_V2_Backorders')) {
				return CWD_V2_Backorders::handle_odoo_backorder_update($request);
			}
		}

		// 3. Invoice sync delegation
		if ('sync_invoice' === $operation || isset($params['odoo_invoice_id'])) {
			if (class_exists('CWD_V2_REST_API')) {
				return CWD_V2_REST_API::handle_direct_invoice_sync($request);
			}
		}

		// 4. Credit sync delegation
		if ('sync_credit' === $operation || isset($params['credit_limit'])) {
			if (class_exists('CWD_V2_REST_API')) {
				return CWD_V2_REST_API::handle_direct_credit_sync($request);
			}
		}

		// Default acknowledgement for heartbeat / ping
		return rest_ensure_response(array(
			'success'   => true,
			'operation' => $operation,
			'received'  => current_time('mysql'),
		));
	}

	/**
	 * Compute tracking progress milestones for display on frontend.
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	public static function get_order_timeline(WC_Order $order)
	{
		$status       = $order->get_status();
		$date_created = $order->get_date_created() ? $order->get_date_created()->date_i18n(get_option('date_format') . ' ' . get_option('time_format')) : '';
		$date_paid    = $order->get_date_paid() ? $order->get_date_paid()->date_i18n(get_option('date_format') . ' ' . get_option('time_format')) : '';
		$date_comp    = $order->get_date_completed() ? $order->get_date_completed()->date_i18n(get_option('date_format') . ' ' . get_option('time_format')) : '';
		$tracking     = self::get_tracking_items($order);
		$has_tracking = ! empty($tracking);

		$shipping_method = $order->get_shipping_method();
		$is_pickup       = stripos($shipping_method, 'pickup') !== false || stripos($shipping_method, 'collection') !== false;

		// Determine step progression:
		// 1: Placed, 2: Processing, 3: Dispatched / Ready, 4: Out for Delivery / In Transit, 5: Delivered / Completed
		$step_placed    = true;
		$step_process   = in_array($status, array('processing', 'completed', 'shipped'), true);
		$step_shipped   = in_array($status, array('completed', 'shipped'), true) || ($status === 'processing' && $has_tracking);
		$step_transit   = in_array($status, array('completed', 'shipped'), true);
		$step_delivered = $status === 'completed';

		return array(
			'is_pickup' => $is_pickup,
			'steps'     => array(
				array(
					'id'        => 'placed',
					'title'     => __('Order Placed', 'custom-woo-dashboard'),
					'active'    => true,
					'completed' => true,
					'date'      => $date_created,
				),
				array(
					'id'        => 'processing',
					'title'     => __('Processing', 'custom-woo-dashboard'),
					'active'    => $step_process || $status === 'pending' || $status === 'on-hold',
					'completed' => $step_shipped || $step_delivered,
					'date'      => $date_paid ?: $date_created,
				),
				array(
					'id'        => 'shipped',
					'title'     => $is_pickup ? __('Ready for Pickup', 'custom-woo-dashboard') : __('Dispatched', 'custom-woo-dashboard'),
					'active'    => $step_shipped || $has_tracking,
					'completed' => $step_delivered,
					'date'      => ! empty($tracking[0]['date_shipped']) ? $tracking[0]['date_shipped'] : ($date_comp ?: ''),
				),
				array(
					'id'        => 'transit',
					'title'     => $is_pickup ? __('Awaiting Collection', 'custom-woo-dashboard') : __('In Transit', 'custom-woo-dashboard'),
					'active'    => $step_transit,
					'completed' => $step_delivered,
					'date'      => '',
				),
				array(
					'id'        => 'delivered',
					'title'     => $is_pickup ? __('Collected', 'custom-woo-dashboard') : __('Delivered', 'custom-woo-dashboard'),
					'active'    => $step_delivered,
					'completed' => $step_delivered,
					'date'      => $date_comp,
				),
			),
		);
	}
}
