<?php

/**
 * Handles multiple saved baskets for customers.
 * Customers can save their active shopping cart with custom titles,
 * manage them from the My Account Dashboard, and restore them to cart anytime.
 */

if (! defined('ABSPATH')) {
	exit;
}

class CWD_V2_Saved_Baskets
{
	public static function init()
	{
		// AJAX Actions
		add_action('wp_ajax_cwd_save_current_basket', array(__CLASS__, 'ajax_save_current_basket'));
		add_action('wp_ajax_cwd_load_saved_basket', array(__CLASS__, 'ajax_load_saved_basket'));
		add_action('wp_ajax_cwd_delete_saved_basket', array(__CLASS__, 'ajax_delete_saved_basket'));
		add_action('wp_ajax_cwd_rename_saved_basket', array(__CLASS__, 'ajax_rename_saved_basket'));

		// Inject "Save as Basket" on WooCommerce Cart page
		add_action('woocommerce_after_cart_table', array(__CLASS__, 'render_cart_save_button'), 25);
		add_action('wp_footer', array(__CLASS__, 'render_cart_save_modal_and_scripts'), 50);
	}

	/**
	 * Get table name
	 */
	public static function get_table_name()
	{
		global $wpdb;
		return $wpdb->prefix . 'cwd_v2_saved_baskets';
	}

	/**
	 * Get all saved baskets for a given user
	 */
	public static function get_user_baskets($user_id = 0)
	{
		global $wpdb;
		if (! $user_id) {
			$user_id = get_current_user_id();
		}
		if (! $user_id) {
			return array();
		}

		$table = self::get_table_name();
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC",
				$user_id
			),
			ARRAY_A
		);

		if (! $results) {
			return array();
		}

		foreach ($results as &$basket) {
			$basket['items'] = json_decode($basket['items_data'], true) ?: array();
		}

		return $results;
	}

	/**
	 * Get count of saved baskets for a user
	 */
	public static function get_user_baskets_count($user_id = 0)
	{
		global $wpdb;
		if (! $user_id) {
			$user_id = get_current_user_id();
		}
		if (! $user_id) {
			return 0;
		}

		$table = self::get_table_name();
		return (int) $wpdb->get_var(
			$wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id)
		);
	}

	/**
	 * Get a single saved basket by ID
	 */
	public static function get_basket($basket_id, $user_id = 0)
	{
		global $wpdb;
		if (! $user_id) {
			$user_id = get_current_user_id();
		}
		if (! $user_id || ! $basket_id) {
			return null;
		}

		$table = self::get_table_name();
		$row = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND user_id = %d", $basket_id, $user_id),
			ARRAY_A
		);

		if (! $row) {
			return null;
		}

		$row['items'] = json_decode($row['items_data'], true) ?: array();
		return $row;
	}

	/**
	 * Save current WooCommerce cart into a new named basket
	 */
	public static function save_basket_from_cart($user_id, $title)
	{
		global $wpdb;
		if (! $user_id || empty($title)) {
			return new WP_Error('invalid_params', __('Invalid basket title or user.', 'custom-woo-dashboard'));
		}

		if (! isset(WC()->cart) || WC()->cart->is_empty()) {
			return new WP_Error('empty_cart', __('Your cart is currently empty. Add products to your cart before saving.', 'custom-woo-dashboard'));
		}

		$cart_items = WC()->cart->get_cart();
		$items_data = array();
		$items_count = 0;
		$total_amount = 0.00;

		foreach ($cart_items as $cart_item_key => $item) {
			$product = $item['data'] ?? null;
			if (! $product || ! is_a($product, 'WC_Product')) {
				continue;
			}

			$product_id   = (int) $item['product_id'];
			$variation_id = (int) ($item['variation_id'] ?? 0);
			$quantity     = (int) ($item['quantity'] ?? 1);
			$line_subtotal= (float) ($item['line_subtotal'] ?? ($product->get_price() * $quantity));

			$items_count  += $quantity;
			$total_amount += $line_subtotal;

			// Product thumbnail
			$image_id  = $product->get_image_id();
			$image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src();

			$items_data[] = array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'name'         => $product->get_name(),
				'sku'          => $product->get_sku(),
				'price'        => (float) $product->get_price(),
				'formatted_price' => wp_strip_all_tags(wc_price($product->get_price())),
				'quantity'     => $quantity,
				'total'        => $line_subtotal,
				'image_url'    => $image_url,
				'variation'    => $item['variation'] ?? array(),
				'permalink'    => get_permalink($product_id),
			);
		}

		if (empty($items_data)) {
			return new WP_Error('no_items', __('No valid products found in the current cart.', 'custom-woo-dashboard'));
		}

		$table = self::get_table_name();
		$now = current_time('mysql');

		$inserted = $wpdb->insert(
			$table,
			array(
				'user_id'          => $user_id,
				'title'            => sanitize_text_field($title),
				'items_data'       => wp_json_encode($items_data),
				'items_count'      => $items_count,
				'total_amount'     => $total_amount,
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array('%d', '%s', '%s', '%d', '%f', '%s', '%s')
		);

		if (! $inserted) {
			return new WP_Error('db_error', __('Could not save basket. Please try again.', 'custom-woo-dashboard'));
		}

		return $wpdb->insert_id;
	}

	/**
	 * Load a saved basket back into the cart
	 */
	public static function load_basket_to_cart($basket_id, $user_id, $mode = 'replace')
	{
		$basket = self::get_basket($basket_id, $user_id);
		if (! $basket) {
			return new WP_Error('not_found', __('Saved basket not found.', 'custom-woo-dashboard'));
		}

		if (! isset(WC()->cart)) {
			return new WP_Error('no_cart', __('Cart service is unavailable.', 'custom-woo-dashboard'));
		}

		if ($mode === 'replace') {
			WC()->cart->empty_cart();
		}

		$added_count = 0;
		foreach ($basket['items'] as $item) {
			$product_id   = (int) ($item['product_id'] ?? 0);
			$variation_id = (int) ($item['variation_id'] ?? 0);
			$quantity     = (int) ($item['quantity'] ?? 1);
			$variation    = (array) ($item['variation'] ?? array());

			if (! $product_id) {
				continue;
			}

			$added = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation);
			if ($added) {
				$added_count++;
			}
		}

		if ($added_count === 0) {
			return new WP_Error('failed_add', __('Items could not be added to the cart (they may be out of stock or unavailable).', 'custom-woo-dashboard'));
		}

		return $added_count;
	}

	/**
	 * Delete a saved basket
	 */
	public static function delete_basket($basket_id, $user_id)
	{
		global $wpdb;
		$table = self::get_table_name();
		return $wpdb->delete(
			$table,
			array('id' => (int) $basket_id, 'user_id' => (int) $user_id),
			array('%d', '%d')
		);
	}

	/**
	 * Rename a saved basket
	 */
	public static function rename_basket($basket_id, $user_id, $new_title)
	{
		global $wpdb;
		$new_title = sanitize_text_field($new_title);
		if (empty($new_title)) {
			return false;
		}

		$table = self::get_table_name();
		return $wpdb->update(
			$table,
			array(
				'title'      => $new_title,
				'updated_at' => current_time('mysql'),
			),
			array('id' => (int) $basket_id, 'user_id' => (int) $user_id),
			array('%s', '%s'),
			array('%d', '%d')
		);
	}

	/* -------------------------------------------------------------------------
	 * AJAX ENDPOINTS
	 * ------------------------------------------------------------------------- */

	public static function ajax_save_current_basket()
	{
		check_ajax_referer('cwd_saved_baskets_nonce', 'nonce');

		if (! is_user_logged_in()) {
			wp_send_json_error(array('message' => __('Please log in to save baskets.', 'custom-woo-dashboard')), 403);
		}

		$title = sanitize_text_field($_POST['title'] ?? '');
		if (empty($title)) {
			wp_send_json_error(array('message' => __('Please enter a title for your saved basket.', 'custom-woo-dashboard')));
		}

		$user_id = get_current_user_id();
		$result  = self::save_basket_from_cart($user_id, $title);

		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
		}

		wp_send_json_success(array(
			'message'   => sprintf(__('Basket "%s" saved successfully!', 'custom-woo-dashboard'), esc_html($title)),
			'basket_id' => $result,
		));
	}

	public static function ajax_load_saved_basket()
	{
		check_ajax_referer('cwd_saved_baskets_nonce', 'nonce');

		if (! is_user_logged_in()) {
			wp_send_json_error(array('message' => __('Please log in to restore saved baskets.', 'custom-woo-dashboard')), 403);
		}

		$basket_id = (int) ($_POST['basket_id'] ?? 0);
		$mode      = sanitize_key($_POST['mode'] ?? 'replace'); // 'replace' or 'merge'
		$user_id   = get_current_user_id();

		$result = self::load_basket_to_cart($basket_id, $user_id, $mode);
		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
		}

		wp_send_json_success(array(
			'message'  => __('Basket loaded into your cart successfully!', 'custom-woo-dashboard'),
			'cart_url' => wc_get_cart_url(),
		));
	}

	public static function ajax_delete_saved_basket()
	{
		check_ajax_referer('cwd_saved_baskets_nonce', 'nonce');

		if (! is_user_logged_in()) {
			wp_send_json_error(array('message' => __('Unauthorized', 'custom-woo-dashboard')), 403);
		}

		$basket_id = (int) ($_POST['basket_id'] ?? 0);
		$user_id   = get_current_user_id();

		$deleted = self::delete_basket($basket_id, $user_id);
		if (! $deleted) {
			wp_send_json_error(array('message' => __('Could not delete basket. It may have already been removed.', 'custom-woo-dashboard')));
		}

		wp_send_json_success(array(
			'message'   => __('Basket deleted successfully.', 'custom-woo-dashboard'),
			'basket_id' => $basket_id,
		));
	}

	public static function ajax_rename_saved_basket()
	{
		check_ajax_referer('cwd_saved_baskets_nonce', 'nonce');

		if (! is_user_logged_in()) {
			wp_send_json_error(array('message' => __('Unauthorized', 'custom-woo-dashboard')), 403);
		}

		$basket_id = (int) ($_POST['basket_id'] ?? 0);
		$title     = sanitize_text_field($_POST['title'] ?? '');
		$user_id   = get_current_user_id();

		if (empty($title)) {
			wp_send_json_error(array('message' => __('Title cannot be empty.', 'custom-woo-dashboard')));
		}

		$updated = self::rename_basket($basket_id, $user_id, $title);
		if (false === $updated) {
			wp_send_json_error(array('message' => __('Could not update basket title.', 'custom-woo-dashboard')));
		}

		wp_send_json_success(array(
			'message'   => __('Basket title updated.', 'custom-woo-dashboard'),
			'basket_id' => $basket_id,
			'new_title' => $title,
		));
	}

	/* -------------------------------------------------------------------------
	 * FRONTEND CART INTEGRATION
	 * ------------------------------------------------------------------------- */

	public static function render_cart_save_button()
	{
		if (! is_user_logged_in() || ! isset(WC()->cart) || WC()->cart->is_empty()) {
			return;
		}
		?>
		<div class="cwd-save-cart-action-wrap" style="margin: 15px 0 25px 0; display: flex; align-items: center; justify-content: flex-end; gap: 12px;">
			<button type="button" id="cwd-open-save-basket-btn" class="button cwd-btn-secondary" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 6px; font-weight: 600; cursor: pointer; background: #f1f5f9; color: #1e293b; border: 1px solid #cbd5e1; font-size: 13.5px; transition: all 0.2s;">
				<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
					<polyline points="17 21 17 13 7 13 7 21"></polyline>
					<polyline points="7 3 7 8 15 8"></polyline>
				</svg>
				<?php esc_html_e('Save as Named Basket', 'custom-woo-dashboard'); ?>
			</button>
		</div>
		<?php
	}

	public static function render_cart_save_modal_and_scripts()
	{
		if (! is_user_logged_in()) {
			return;
		}
		?>
		<!-- CWD Save Basket Modal -->
		<div id="cwd-save-basket-modal" style="display: none; position: fixed; inset: 0; z-index: 99999; background: rgba(15, 23, 42, 0.6); align-items: center; justify-content: center; padding: 16px;">
			<div style="background: #ffffff; border-radius: 10px; width: 100%; max-width: 440px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); overflow: hidden; font-family: inherit;">
				<div style="padding: 18px 22px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
					<h3 style="margin: 0; font-size: 17px; font-weight: 700; color: #0f172a;"><?php esc_html_e('Save as Named Basket', 'custom-woo-dashboard'); ?></h3>
					<button type="button" id="cwd-modal-close-btn" style="background: none; border: none; font-size: 20px; line-height: 1; cursor: pointer; color: #94a3b8;">&times;</button>
				</div>
				<div style="padding: 22px;">
					<p style="margin: 0 0 14px 0; font-size: 13.5px; color: #64748b; line-height: 1.5;">
						<?php esc_html_e('Give this basket a title (e.g., "Van Stock", "Job: High St", "Weekly Essentials") to quickly reorder or view it from your account dashboard.', 'custom-woo-dashboard'); ?>
					</p>
					<label for="cwd-basket-title-input" style="display: block; font-size: 12.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; margin-bottom: 6px;">
						<?php esc_html_e('Basket Title', 'custom-woo-dashboard'); ?>
					</label>
					<input type="text" id="cwd-basket-title-input" placeholder="<?php esc_attr_e('e.g. Standard Van Stock', 'custom-woo-dashboard'); ?>" style="width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; box-sizing: border-box; margin-bottom: 18px;">
					
					<div id="cwd-basket-modal-msg" style="display: none; padding: 10px 12px; border-radius: 6px; font-size: 13px; margin-bottom: 14px;"></div>

					<div style="display: flex; justify-content: flex-end; gap: 10px;">
						<button type="button" id="cwd-modal-cancel-btn" style="padding: 9px 16px; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; color: #475569; font-size: 13.5px; font-weight: 600; cursor: pointer;">
							<?php esc_html_e('Cancel', 'custom-woo-dashboard'); ?>
						</button>
						<button type="button" id="cwd-modal-submit-btn" style="padding: 9px 18px; border-radius: 6px; border: none; background: #0284c7; color: #fff; font-size: 13.5px; font-weight: 600; cursor: pointer;">
							<?php esc_html_e('Save Basket', 'custom-woo-dashboard'); ?>
						</button>
					</div>
				</div>
			</div>
		</div>

		<script id="cwd-saved-baskets-js">
		document.addEventListener('DOMContentLoaded', function() {
			var modal = document.getElementById('cwd-save-basket-modal');
			var openBtn = document.getElementById('cwd-open-save-basket-btn');
			var closeBtn = document.getElementById('cwd-modal-close-btn');
			var cancelBtn = document.getElementById('cwd-modal-cancel-btn');
			var submitBtn = document.getElementById('cwd-modal-submit-btn');
			var titleInput = document.getElementById('cwd-basket-title-input');
			var msgBox = document.getElementById('cwd-basket-modal-msg');

			var ajaxUrl = <?php echo json_encode(admin_url('admin-ajax.php')); ?>;
			var nonce = <?php echo json_encode(wp_create_nonce('cwd_saved_baskets_nonce')); ?>;

			function showModal() {
				if (!modal) return;
				modal.style.display = 'flex';
				if (msgBox) { msgBox.style.display = 'none'; }
				if (titleInput) {
					titleInput.value = '';
					setTimeout(function() { titleInput.focus(); }, 100);
				}
			}

			function hideModal() {
				if (!modal) return;
				modal.style.display = 'none';
			}

			if (openBtn) {
				openBtn.addEventListener('click', showModal);
			}

			// Also allow other trigger buttons on page with class .cwd-trigger-save-basket
			document.querySelectorAll('.cwd-trigger-save-basket').forEach(function(btn) {
				btn.addEventListener('click', showModal);
			});

			if (closeBtn) closeBtn.addEventListener('click', hideModal);
			if (cancelBtn) cancelBtn.addEventListener('click', hideModal);

			if (modal) {
				modal.addEventListener('click', function(e) {
					if (e.target === modal) hideModal();
				});
			}

			if (submitBtn) {
				submitBtn.addEventListener('click', function() {
					var title = (titleInput ? titleInput.value : '').trim();
					if (!title) {
						if (msgBox) {
							msgBox.style.display = 'block';
							msgBox.style.background = '#fef2f2';
							msgBox.style.color = '#991b1b';
							msgBox.textContent = 'Please enter a basket title.';
						}
						return;
					}

					submitBtn.disabled = true;
					submitBtn.textContent = 'Saving...';

					var formData = new FormData();
					formData.append('action', 'cwd_save_current_basket');
					formData.append('nonce', nonce);
					formData.append('title', title);

					fetch(ajaxUrl, {
						method: 'POST',
						body: formData
					})
					.then(function(res) { return res.json(); })
					.then(function(data) {
						submitBtn.disabled = false;
						submitBtn.textContent = 'Save Basket';
						if (data && data.success) {
							if (msgBox) {
								msgBox.style.display = 'block';
								msgBox.style.background = '#f0fdf4';
								msgBox.style.color = '#166534';
								msgBox.textContent = data.data && data.data.message ? data.data.message : 'Basket saved!';
							}
							setTimeout(function() {
								hideModal();
								// If on saved baskets page, reload to show newly saved basket
								if (window.location.href.indexOf('saved-baskets') !== -1) {
									window.location.reload();
								}
							}, 900);
						} else {
							if (msgBox) {
								msgBox.style.display = 'block';
								msgBox.style.background = '#fef2f2';
								msgBox.style.color = '#991b1b';
								msgBox.textContent = data.data && data.data.message ? data.data.message : 'Could not save basket.';
							}
						}
					})
					.catch(function(err) {
						submitBtn.disabled = false;
						submitBtn.textContent = 'Save Basket';
						if (msgBox) {
							msgBox.style.display = 'block';
							msgBox.style.background = '#fef2f2';
							msgBox.style.color = '#991b1b';
							msgBox.textContent = 'A network error occurred. Please try again.';
						}
					});
				});
			}
		});
		</script>
		<?php
	}
}
