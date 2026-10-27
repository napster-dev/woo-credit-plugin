<?php

/**
 * Invoice data access and automatic invoice generation.
 */

if (! defined('ABSPATH')) {
	exit;
}

class CWD_V2_Invoices
{

	const STATUS_UNPAID      = 'unpaid';
	const STATUS_PAID        = 'paid';
	const STATUS_OVERDUE     = 'overdue';
	const STATUS_VOID        = 'void';
	const STATUS_WRITTEN_OFF = 'written_off';
	const STATUS_MARKED_OFF  = 'marked_off';

	const SOURCE_WEBSITE = 'website';
	const SOURCE_ODOO    = 'odoo';

	public static function init()
	{
		add_action('woocommerce_payment_complete', array(__CLASS__, 'generate_invoice_for_order'));
		add_action('woocommerce_order_status_processing', array(__CLASS__, 'generate_invoice_for_order'));
		add_action('woocommerce_order_status_completed', array(__CLASS__, 'generate_invoice_for_order'));
		add_action('woocommerce_order_status_cancelled', array(__CLASS__, 'void_invoice_for_order'));
		add_action('woocommerce_order_status_refunded', array(__CLASS__, 'void_invoice_for_order'));
		add_action('cwd_v2_sync_odoo_invoices', array(__CLASS__, 'sync_odoo_invoices'));
		add_action('add_meta_boxes', array(__CLASS__, 'register_order_invoice_meta_box'), 25);
		add_action('template_redirect', array(__CLASS__, 'handle_direct_invoice_download'));
		// Run one-time legacy invoice due-date heal safely
		if ('yes' !== get_option('cwd_v2_same_day_due_dates_repaired_v2')) {
			try {
				self::repair_same_day_due_dates();
				update_option('cwd_v2_same_day_due_dates_repaired_v2', 'yes');
			} catch (\Throwable $t) {
				error_log('CWD Repair Due Dates Error: ' . $t->getMessage());
			}
		}
	}

	/**
	 * @return string The fully-prefixed invoices table name.
	 */
	public static function table_name()
	{
		global $wpdb;
		return $wpdb->prefix . 'cwd_v2_invoices';
	}

	/**
	 * Creates exactly one invoice row for an order, the first time it reaches
	 * a "paid/processing" state. Safe to call multiple times for the same order.
	 *
	 * @param int $order_id
	 */
	public static function generate_invoice_for_order($order_id)
	{
		$order = wc_get_order($order_id);
		if (! $order) {
			return;
		}

		$payment_product_id = (int) get_option('cwd_v2_credit_payment_product_id');
		foreach ($order->get_items() as $item) {
			if ($payment_product_id && $item->get_product_id() === $payment_product_id) {
				return;
			}
		}

		if ($order->get_meta('_cwd_v2_invoice_generated')) {
			return;
		}

		$user_id = $order->get_user_id();
		if (! $user_id) {
			return;
		}

		global $wpdb;
		$table = self::table_name();

		// Belt-and-braces duplicate check in case the meta flag write below
		// didn't persist for some reason (e.g. two hooks firing in the same
		// request before either save() call completes).
		$existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE order_id = %d", $order_id));
		if ($existing) {
			$order->update_meta_data('_cwd_v2_invoice_generated', 'yes');
			$order->save();
			return;
		}

		$is_credit_order = in_array($order->get_payment_method(), array('cwd_v2_credit_account', 'credits'), true) || ('yes' === $order->get_meta('_paid_with_credit'));
		$is_paid_order   = method_exists($order, 'is_paid') && $order->is_paid();
		$due_days        = self::get_due_days();
		$invoice_date    = current_time('mysql');
		$due_date        = $is_credit_order || ! $is_paid_order
			? self::calculate_due_date_for_user($user_id, $invoice_date)
			: $invoice_date;
		$status          = $is_credit_order || ! $is_paid_order ? self::STATUS_UNPAID : self::STATUS_PAID;
		$amount_total    = (float) $order->get_total();
		$amount_paid     = $is_credit_order || ! $is_paid_order ? 0.0 : $amount_total;

		$wpdb->insert(
			$table,
			array(
				'order_id'        => $order_id,
				'user_id'         => $user_id,
				'invoice_number'  => self::build_invoice_number($order_id),
				'source'          => self::SOURCE_WEBSITE,
				'odoo_invoice_id' => null,
				'document_url'    => null,
				'invoice_date'    => $invoice_date,
				'due_date'        => $due_date,
				'status'          => $status,
				'amount_total'    => $amount_total,
				'amount_paid'     => $amount_paid,
				'created_at'      => current_time('mysql'),
				'updated_at'      => current_time('mysql'),
			),
			array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s')
		);

		$order->update_meta_data('_cwd_v2_invoice_generated', 'yes');
		$order->save();

		// Keep user's active due date synchronized
		if ($user_id) {
			self::update_user_earliest_due_date($user_id);
		}
	}

	/**
	 * Marks the invoice for an order as void (order cancelled or fully refunded
	 * outside of the normal payment flow).
	 *
	 * @param int $order_id
	 */
	public static function void_invoice_for_order($order_id)
	{
		global $wpdb;
		$table = self::table_name();

		$wpdb->update(
			$table,
			array(
				'status'     => self::STATUS_VOID,
				'updated_at' => current_time('mysql'),
			),
			array('order_id' => $order_id),
			array('%s', '%s'),
			array('%d')
		);

		$order = wc_get_order($order_id);
		if ($order && $order->get_user_id()) {
			self::update_user_earliest_due_date($order->get_user_id());
		}
	}

	/**
	 * @param int $order_id
	 * @return string The invoice number, e.g. INV-000042.
	 */
	public static function build_invoice_number($order_id)
	{
		return 'INV-' . str_pad((string) $order_id, 6, '0', STR_PAD_LEFT);
	}

	/**
	 * Number of days credit-account invoices are given to be paid.
	 * Configurable via the 'cwd_v2_invoice_due_days' option (defaults to 30).
	 *
	 * @return int
	 */
	public static function get_due_days()
	{
		$days = get_option('cwd_v2_invoice_due_days', 30);
		return absint($days) > 0 ? absint($days) : 30;
	}

	/**
	 * Compute due date based on user's specific payment terms.
	 * Supports:
	 *  - "30 Days End of Month", "30 Days EOM", "EOM" (Standard UK trade)
	 *  - "Net 14 Days", "14 Days", "Net 30 Days", "Net 60 Days", "7 Days", etc.
	 *  - "Due on Receipt", "Immediate"
	 *  - Custom days
	 *
	 * @param int $user_id
	 * @param string|null $from_date
	 * @return string Y-m-d H:i:s
	 */
	public static function calculate_due_date_for_user($user_id, $from_date = null)
	{
		$terms = trim((string) get_user_meta($user_id, '_credit_payment_terms', true));
		if (empty($terms)) {
			$terms = '30 Days End of Month';
		}
		return self::calculate_due_date_from_terms($terms, $from_date);
	}

	/**
	 * Calculate due date datetime from a payment terms string.
	 *
	 * @param string $terms
	 * @param string|int|null $from_date
	 * @return string Y-m-d H:i:s
	 */
	public static function calculate_due_date_from_terms($terms, $from_date = null)
	{
		$from_ts = $from_date ? (is_numeric($from_date) ? (int) $from_date : strtotime($from_date)) : current_time('timestamp');
		if (! $from_ts) {
			$from_ts = current_time('timestamp');
		}

		$terms_lower = strtolower(trim((string) $terms));

		// 1. End of Month variants (e.g. "30 Days EOM", "30 Days End of Month", "EOM")
		if (strpos($terms_lower, 'eom') !== false || strpos($terms_lower, 'end of month') !== false) {
			if (preg_match('/(\d+)\s*days?/i', $terms_lower, $m)) {
				$days_after_eom = (int) $m[1];
				$eom_ts = strtotime(date('Y-m-t 23:59:59', $from_ts));
				return date('Y-m-d 23:59:59', strtotime("+{$days_after_eom} days", $eom_ts));
			} else {
				return date('Y-m-t 23:59:59', $from_ts);
			}
		}

		// 2. Specific calendar days (e.g. "Net 14", "14 Days", "Net 30 Days", "60 Days Net", "7 Days", "45 Days")
		if (preg_match('/(\d+)\s*days?/i', $terms_lower, $m) || preg_match('/net\s*(\d+)/i', $terms_lower, $m)) {
			$days = (int) $m[1];
			return date('Y-m-d 23:59:59', strtotime("+{$days} days", $from_ts));
		}

		// 3. Due on receipt / immediate
		if (strpos($terms_lower, 'immediate') !== false || strpos($terms_lower, 'receipt') !== false) {
			return date('Y-m-d 23:59:59', $from_ts);
		}

		// 4. Numeric value entered directly
		if (is_numeric($terms) && (int) $terms > 0) {
			$days = (int) $terms;
			return date('Y-m-d 23:59:59', strtotime("+{$days} days", $from_ts));
		}

		// 5. Default fallback to global due days (30 days)
		$fallback = self::get_due_days();
		return date('Y-m-d 23:59:59', strtotime("+{$fallback} days", $from_ts));
	}

	/**
	 * Keep the user's `_credit_due_date` meta synchronized with their earliest unpaid invoice.
	 *
	 * @param int $user_id
	 */
	public static function update_user_earliest_due_date($user_id)
	{
		$user_id = (int) $user_id;
		if (! $user_id) {
			return;
		}

		global $wpdb;
		$table = self::table_name();
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
			return;
		}

		$earliest = $wpdb->get_var($wpdb->prepare(
			"SELECT MIN(due_date) FROM {$table}
			 WHERE user_id = %d AND status = %s AND (amount_total - amount_paid) > 0",
			$user_id,
			self::STATUS_UNPAID
		));

		if ($earliest) {
			update_user_meta($user_id, '_credit_due_date', date('Y-m-d', strtotime($earliest)));
		} else {
			delete_user_meta($user_id, '_credit_due_date');
		}
	}

	/**
	 * @param int   $user_id
	 * @param array $args {
	 *     @type string $status  Filter by exact status. Empty string = all statuses.
	 *     @type string $orderby One of: invoice_date, due_date, amount_total, status.
	 *     @type string $order   ASC or DESC.
	 *     @type int    $limit
	 * }
	 * @return object[] Array of raw row objects from the invoices table.
	 */
	public static function get_invoices_for_user($user_id, $args = array())
	{
		global $wpdb;
		$table = self::table_name();

		$defaults = array(
			'status'  => '',
			'orderby' => 'invoice_date',
			'order'   => 'DESC',
			'limit'   => 50,
		);
		$args = wp_parse_args($args, $defaults);

		$allowed_orderby = array('invoice_date', 'due_date', 'amount_total', 'status');
		$orderby         = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'invoice_date';
		$order_dir       = 'ASC' === strtoupper($args['order']) ? 'ASC' : 'DESC';

		if (! empty($args['status'])) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND status = %s ORDER BY {$orderby} {$order_dir} LIMIT %d",
				$user_id,
				$args['status'],
				(int) $args['limit']
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY {$orderby} {$order_dir} LIMIT %d",
				$user_id,
				(int) $args['limit']
			);
		}

		$results = $wpdb->get_results($sql);
		if (! empty($results)) {
			foreach ($results as $inv) {
				// Auto-heal any legacy invoice where due_date was mistakenly set to same calendar day as invoice_date
				if (! empty($inv->invoice_date) && ! empty($inv->due_date)) {
					$inv_day = substr($inv->invoice_date, 0, 10);
					$due_day = substr($inv->due_date, 0, 10);
					if ($due_day <= $inv_day) {
						$calc_due = self::calculate_due_date_for_user((int) $inv->user_id, $inv->invoice_date);
						if ($calc_due) {
							$inv->due_date = $calc_due;
							$wpdb->update($table, array('due_date' => $calc_due), array('id' => (int) $inv->id));
						}
					}
				}
				// Enrich with Odoo metadata from linked WooCommerce order
				self::enrich_invoice_with_odoo_data($inv);
			}
		}

		return $results;
	}

	/**
	 * Upserts a single Odoo-sourced invoice row, matched on odoo_invoice_id.
	 * Shared by the REST push endpoint and the legacy filter-based sync path.
	 *
	 * @param int   $user_id
	 * @param array $row Expected keys: odoo_invoice_id, invoice_number, invoice_date,
	 *                    due_date, amount_total, amount_paid, status, document_url (optional).
	 * @return bool True if a row was inserted or updated.
	 */
	public static function upsert_odoo_invoice( $user_id, $row )
	{
		$odoo_id = isset( $row['odoo_invoice_id'] ) ? sanitize_text_field( $row['odoo_invoice_id'] ) : '';
		$order_id = ! empty( $row['order_id'] ) ? absint( $row['order_id'] ) : 0;
		if ( '' === $odoo_id && $order_id <= 0 ) {
			return false;
		}

		global $wpdb;
		$table = self::table_name();

		$total  = isset( $row['amount_total'] ) ? (float) $row['amount_total'] : 0.0;
		$paid   = min( $total, max( 0.0, isset( $row['amount_paid'] ) ? (float) $row['amount_paid'] : 0.0 ) );
		$raw_status = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : self::STATUS_UNPAID;
		$valid_statuses = array( self::STATUS_UNPAID, self::STATUS_PAID, self::STATUS_VOID, self::STATUS_WRITTEN_OFF, self::STATUS_MARKED_OFF, 'written_off', 'marked_off' );
		$status = in_array( $raw_status, $valid_statuses, true ) ? $raw_status : self::STATUS_UNPAID;
		$now    = current_time( 'mysql' );

		// If marked off / written off in Odoo, strictly preserve written_off status
		if ( in_array( $status, array( self::STATUS_WRITTEN_OFF, self::STATUS_MARKED_OFF, 'written_off', 'marked_off' ), true ) ) {
			$final_status = self::STATUS_WRITTEN_OFF;
		} elseif ( self::STATUS_VOID === $status ) {
			$final_status = self::STATUS_VOID;
		} elseif ( $paid >= $total && $total > 0 ) {
			$final_status = self::STATUS_PAID;
		} else {
			$final_status = $status;
		}

		$inv_date = sanitize_text_field( $row['invoice_date'] ?? $now );
		$incoming_due = sanitize_text_field( $row['due_date'] ?? '' );
		// If due_date is missing, invalid, or identical to invoice_date (e.g. Odoo defaulted without payment terms),
		// calculate the true due date using the customer's trade credit terms (defaulting to 30 Days End of Month).
		if ( empty( $incoming_due ) || substr( $incoming_due, 0, 10 ) <= substr( $inv_date, 0, 10 ) ) {
			if ( $user_id > 0 ) {
				$incoming_due = self::calculate_due_date_for_user( (int) $user_id, $inv_date );
			}
		}

		$data = array(
			'user_id'        => (int) $user_id,
			'invoice_number' => sanitize_text_field( $row['invoice_number'] ?? $odoo_id ),
			'source'         => self::SOURCE_ODOO,
			'invoice_date'   => $inv_date,
			'due_date'       => ! empty( $incoming_due ) ? $incoming_due : $inv_date,
			'status'         => $final_status,
			'amount_total'   => $total,
			'amount_paid'    => $paid,
			'document_url'   => ! empty( $row['document_url'] ) ? esc_url_raw( $row['document_url'] ) : null,
			'updated_at'     => $now,
		);

		if ( $order_id > 0 ) {
			$data['order_id'] = $order_id;
		}

		$updated_or_inserted = false;

		if ( $user_id > 0 ) {
			// 1. Check if row exists for this odoo_invoice_id
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE odoo_invoice_id = %s", $odoo_id ) );

			// 2. If not found by odoo_invoice_id, check if existing invoice exists for this WooCommerce order
			if ( ! $existing && $order_id > 0 ) {
				$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d", $order_id ) );
			}

			if ( $existing ) {
				$data['odoo_invoice_id'] = $odoo_id;
				$updated_or_inserted = false !== $wpdb->update(
					$table,
					$data,
					array( 'id' => (int) $existing )
				);
			} else {
				$insert_data = array_merge( $data, array(
					'odoo_invoice_id' => $odoo_id,
					'order_id'        => $order_id > 0 ? $order_id : null,
					'created_at'      => $now,
				) );
				$updated_or_inserted = false !== $wpdb->insert( $table, $insert_data );
			}

			self::update_user_earliest_due_date( $user_id );
		}

		// Always update WooCommerce order metadata and log an order note
		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->update_meta_data( '_odoo_invoice_id', $odoo_id );
				$order->update_meta_data( '_odoo_invoice_number', $data['invoice_number'] );
				$order->update_meta_data( 'odoo_invoice_number', $data['invoice_number'] );
				$order->update_meta_data( '_odoo_invoice_status', $data['status'] );
				if ( ! empty( $data['document_url'] ) ) {
					$order->update_meta_data( '_odoo_invoice_url', $data['document_url'] );
				}
				$order->add_order_note(
					sprintf(
						__( 'Odoo Invoice %s linked successfully (Amount: £%s, Status: %s).', 'custom-woo-dashboard' ),
						$data['invoice_number'],
						number_format( $total, 2 ),
						ucfirst( $data['status'] )
					)
				);
				$order->save();
				$updated_or_inserted = true;
			}
		}

		return $updated_or_inserted;
	}

	/**
	 * Register Admin Meta Box for Odoo Invoice Details on Order Edit screen
	 */
	public static function register_order_invoice_meta_box()
	{
		$screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') &&
				  wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
				  ? wc_get_page_screen_id('shop-order')
				  : 'shop_order';

		add_meta_box(
			'cwd_v2_odoo_invoice_box',
			__('Odoo Invoice Details', 'custom-woo-dashboard'),
			array(__CLASS__, 'render_admin_order_invoice_box'),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Render Odoo Invoice meta box on order page
	 */
	public static function render_admin_order_invoice_box($post_or_order)
	{
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order(is_object($post_or_order) ? $post_or_order->ID : $post_or_order);
		if (! $order) {
			return;
		}

		$invoice_number = $order->get_meta('_odoo_invoice_number') ?: $order->get_meta('odoo_invoice_number');
		$odoo_id        = $order->get_meta('_odoo_invoice_id');
		$invoice_url    = $order->get_meta('_odoo_invoice_url');
		$status         = $order->get_meta('_odoo_invoice_status') ?: 'unpaid';

		if ($invoice_number) {
			$badge_color = 'paid' === strtolower($status) ? '#10b981' : '#f59e0b';
			?>
			<div style="font-size: 13px; line-height: 1.6;">
				<p style="margin: 0 0 8px;">
					<strong><?php esc_html_e('Invoice #:', 'custom-woo-dashboard'); ?></strong>
					<span style="font-size: 15px; font-weight: 700; color: #0284c7;"><?php echo esc_html($invoice_number); ?></span>
				</p>
				<?php if ($odoo_id) : ?>
					<p style="margin: 0 0 8px;">
						<strong><?php esc_html_e('Odoo Move ID:', 'custom-woo-dashboard'); ?></strong>
						<code>#<?php echo esc_html($odoo_id); ?></code>
					</p>
				<?php endif; ?>
				<p style="margin: 0 0 12px;">
					<strong><?php esc_html_e('Payment Status:', 'custom-woo-dashboard'); ?></strong>
					<span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #fff; background-color: <?php echo esc_attr($badge_color); ?>;">
						<?php echo esc_html($status); ?>
					</span>
				</p>
				<?php if (! empty($invoice_url)) : ?>
					<p style="margin: 0;">
						<a href="<?php echo esc_url($invoice_url); ?>" target="_blank" class="button button-secondary" style="width: 100%; text-align: center;">
							<?php esc_html_e('View in Odoo Portal ↗', 'custom-woo-dashboard'); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
			<?php
		} else {
			?>
			<p style="color: #64748b; font-size: 13px; margin: 0 0 6px;">
				<?php esc_html_e('No Odoo invoice linked to this order yet.', 'custom-woo-dashboard'); ?>
			</p>
			<p style="color: #94a3b8; font-size: 12px; margin: 0;">
				<?php esc_html_e('It will link automatically when confirmed or synced from Odoo.', 'custom-woo-dashboard'); ?>
			</p>
			<?php
		}
	}

	/**
	 * Display Odoo Invoice badge under General Order details
	 */
	public static function display_admin_order_invoice_meta($order)
	{
		$invoice_number = $order->get_meta('_odoo_invoice_number') ?: $order->get_meta('odoo_invoice_number');
		if ($invoice_number) {
			echo '<p class="form-field form-field-wide" style="margin-top: 10px; padding: 8px 12px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 4px;">' .
				 '<strong>' . esc_html__('Odoo Invoice:', 'custom-woo-dashboard') . '</strong> ' .
				 '<span style="font-weight: 700; color: #0369a1; margin-left: 6px;">' . esc_html($invoice_number) . '</span>' .
				 '</p>';
		}
	}

	/**
	 * Imports the customer's Odoo invoices through the site's Odoo connector.
	 * The connector supplies normalized rows through the filter and may run this
	 * method from a cron job or webhook handler for automatic synchronization.
	 *
	 * Each row must contain: odoo_invoice_id, invoice_number, invoice_date,
	 * due_date, amount_total, amount_paid, and status. Optional document_url
	 * values are passed through the invoice document URL filter.
	 *
	 * @param int $user_id
	 * @return int Number of rows inserted or updated.
	 */
	public static function sync_odoo_invoices( $user_id = 0 )
	{
		if ( ! $user_id ) {
			return 0;
		}
		$rows = apply_filters( 'cwd_v2_odoo_invoices', array(), (int) $user_id );
		if ( ! is_array( $rows ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $rows as $row ) {
			if ( self::upsert_odoo_invoice( $user_id, $row ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Enriches an invoice object with Odoo ERP data from linked WooCommerce order metadata
	 * if it originated as a website invoice or is missing direct Odoo references.
	 *
	 * @param object $inv
	 * @return object
	 */
	public static function enrich_invoice_with_odoo_data($inv)
	{
		if (! is_object($inv)) {
			return $inv;
		}

		$order_id = ! empty($inv->order_id) ? (int) $inv->order_id : 0;
		if ($order_id > 0) {
			$order = wc_get_order($order_id);
			if ($order) {
				$odoo_inv_num = $order->get_meta('_odoo_invoice_number') ?: $order->get_meta('odoo_invoice_number');
				$odoo_id      = $order->get_meta('_odoo_invoice_id');
				$odoo_url     = $order->get_meta('_odoo_invoice_url');
				$odoo_status  = $order->get_meta('_odoo_invoice_status');

				$needs_db_sync = false;

				if (! empty($odoo_inv_num) && $inv->invoice_number !== $odoo_inv_num) {
					$inv->invoice_number = sanitize_text_field($odoo_inv_num);
					$inv->source         = self::SOURCE_ODOO;
					$needs_db_sync       = true;
				}

				if (! empty($odoo_id) && empty($inv->odoo_invoice_id)) {
					$inv->odoo_invoice_id = sanitize_text_field($odoo_id);
					$inv->source          = self::SOURCE_ODOO;
					$needs_db_sync        = true;
				}

				if (! empty($odoo_url) && empty($inv->document_url)) {
					$inv->document_url = esc_url_raw($odoo_url);
					$needs_db_sync     = true;
				}

				if (! empty($odoo_status) && in_array(strtolower($odoo_status), array(self::STATUS_PAID, self::STATUS_UNPAID, self::STATUS_VOID, self::STATUS_WRITTEN_OFF), true)) {
					if ($inv->status !== strtolower($odoo_status)) {
						$inv->status   = strtolower($odoo_status);
						$needs_db_sync = true;
					}
				}

				if ($needs_db_sync && ! empty($inv->id)) {
					global $wpdb;
					$wpdb->update(
						self::table_name(),
						array(
							'invoice_number'  => $inv->invoice_number,
							'source'          => $inv->source,
							'odoo_invoice_id' => $inv->odoo_invoice_id,
							'document_url'    => $inv->document_url,
							'status'          => $inv->status,
							'updated_at'      => current_time('mysql'),
						),
						array('id' => (int) $inv->id)
					);
				}
			}
		}

		// If document_url is still empty but odoo_invoice_id exists, build portal download link
		if (empty($inv->document_url) && ! empty($inv->odoo_invoice_id)) {
			$odoo_base = defined('CWD_ODOO_BASE_URL') ? CWD_ODOO_BASE_URL : get_option('cwd_odoo_base_url', '');
			$odoo_base = apply_filters('cwd_v2_odoo_base_url', $odoo_base);
			if (! empty($odoo_base)) {
				$inv->document_url = rtrim($odoo_base, '/') . '/my/invoices/' . rawurlencode($inv->odoo_invoice_id) . '?report_type=pdf&download=true';
			}
		}

		return $inv;
	}

	/**
	 * Get total due / overdue credit amount for a user.
	 *
	 * @param int $user_id
	 * @return float
	 */
	public static function get_user_due_credit_amount($user_id)
	{
		$user_id = (int) $user_id;
		if (! $user_id) {
			return 0.0;
		}

		global $wpdb;
		$table = self::table_name();
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
			return 0.0;
		}

		$now = current_time('mysql');

		// Query unpaid invoices past due date
		$overdue_sum = $wpdb->get_var($wpdb->prepare(
			"SELECT SUM(amount_total - amount_paid) FROM {$table}
			 WHERE user_id = %d AND status = %s AND due_date <= %s",
			$user_id,
			self::STATUS_UNPAID,
			$now
		));

		if ($overdue_sum && (float) $overdue_sum > 0.01) {
			return round((float) $overdue_sum, 2);
		}

		// Fallback: if user has any unpaid invoices at all, check if due date passed
		$unpaid_sum = $wpdb->get_var($wpdb->prepare(
			"SELECT SUM(amount_total - amount_paid) FROM {$table}
			 WHERE user_id = %d AND status = %s",
			$user_id,
			self::STATUS_UNPAID
		));

		if ($unpaid_sum && (float) $unpaid_sum > 0.01) {
			return round((float) $unpaid_sum, 2);
		}

		$credit_balance = class_exists('CWD_V2_Credit_Logic') ? CWD_V2_Credit_Logic::get_user_credit_balance($user_id) : (float) get_user_meta($user_id, '_credit_balance', true);
		return round(max(0.0, (float) $credit_balance), 2);
	}

	/**
	 * Apply payment across user's unpaid invoices using FIFO (First In, First Out).
	 *
	 * @param int   $user_id
	 * @param float $amount
	 * @return int Number of invoices fully or partially settled.
	 */
	public static function apply_fifo_payment_to_unpaid_invoices($user_id, $amount)
	{
		$user_id   = (int) $user_id;
		$remaining = round((float) $amount, 2);
		if ($user_id <= 0 || $remaining <= 0) {
			return 0;
		}

		$unpaid_invoices = self::get_outstanding_invoices_for_user($user_id, 100);
		if (empty($unpaid_invoices)) {
			return 0;
		}

		$settled_count = 0;
		foreach ($unpaid_invoices as $inv) {
			if ($remaining <= 0) {
				break;
			}
			$outstanding = round(max(0.0, (float) $inv->amount_total - (float) $inv->amount_paid), 2);
			if ($outstanding <= 0) {
				continue;
			}

			$pay_amount = min($remaining, $outstanding);
			if (self::apply_invoice_payment($inv->id, $user_id, $pay_amount)) {
				$remaining = round($remaining - $pay_amount, 2);
				$settled_count++;
			}
		}

		self::update_user_earliest_due_date($user_id);
		return $settled_count;
	}

	/**
	 * @param int $invoice_id
	 * @param int $user_id Optional. If provided, only returns the invoice if it belongs to this user.
	 * @return object|null
	 */
	public static function get_invoice($invoice_id, $user_id = 0)
	{
		global $wpdb;
		$table = self::table_name();

		if ($user_id) {
			$inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND user_id = %d", $invoice_id, $user_id));
		} else {
			$inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $invoice_id));
		}

		if ($inv && ! empty($inv->invoice_date) && ! empty($inv->due_date)) {
			$inv_day = substr($inv->invoice_date, 0, 10);
			$due_day = substr($inv->due_date, 0, 10);
			if ($due_day <= $inv_day) {
				$calc_due = self::calculate_due_date_for_user((int) $inv->user_id, $inv->invoice_date);
				if ($calc_due) {
					$inv->due_date = $calc_due;
					$wpdb->update($table, array('due_date' => $calc_due), array('id' => (int) $inv->id));
				}
			}
		}

		if ($inv) {
			self::enrich_invoice_with_odoo_data($inv);
		}

		return $inv;
	}

	public static function get_invoices_for_order($order_id)
	{
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE order_id = %d LIMIT 1', (int) $order_id));
	}

	/**
	 * @param int $user_id
	 * @param int $limit
	 * @return object[] Unpaid invoices for the user, soonest due date first.
	 */
	public static function get_outstanding_invoices_for_user($user_id, $limit = 50)
	{
		return self::get_invoices_for_user(
			$user_id,
			array(
				'status'  => self::STATUS_UNPAID,
				'orderby' => 'due_date',
				'order'   => 'ASC',
				'limit'   => $limit,
			)
		);
	}

	/**
	 * @param int $invoice_id
	 * @return bool|int False on failure, number of rows updated on success.
	 */
	public static function mark_invoice_paid($invoice_id)
	{
		$invoice = self::get_invoice($invoice_id);
		if (! $invoice) {
			return false;
		}

		return self::apply_invoice_payment($invoice_id, (int) $invoice->user_id, (float) $invoice->amount_total - (float) $invoice->amount_paid);
	}

	/**
	 * Applies a payment only while the invoice is still unpaid and the amount
	 * fits within its outstanding balance. The conditional update prevents two
	 * checkout callbacks from settling the same invoice twice.
	 */
	public static function apply_invoice_payment($invoice_id, $user_id, $amount)
	{
		global $wpdb;
		$amount = round((float) $amount, 2);
		if ($amount <= 0) {
			return false;
		}

		$table = self::table_name();
		$updated = $wpdb->query($wpdb->prepare(
			"UPDATE {$table}
			 SET amount_paid = amount_paid + %f,
			     status = CASE WHEN amount_paid + %f >= amount_total THEN %s ELSE %s END,
			     updated_at = %s
			 WHERE id = %d AND user_id = %d AND status = %s
			   AND amount_paid + %f <= amount_total",
			$amount,
			$amount,
			self::STATUS_PAID,
			self::STATUS_UNPAID,
			current_time('mysql'),
			(int) $invoice_id,
			(int) $user_id,
			self::STATUS_UNPAID,
			$amount
		));

		if ($updated) {
			self::update_user_earliest_due_date($user_id);
		}

		return 1 === (int) $updated;
	}

	/**
	 * Reverses a previously applied invoice payment, only once and only up to
	 * the amount currently recorded as paid.
	 */
	public static function reverse_invoice_payment($invoice_id, $user_id, $amount)
	{
		global $wpdb;
		$amount = round((float) $amount, 2);
		if ($amount <= 0) {
			return false;
		}

		$table = self::table_name();
		$updated = $wpdb->query($wpdb->prepare(
			"UPDATE {$table}
			 SET amount_paid = amount_paid - %f,
			     status = %s,
			     updated_at = %s
			 WHERE id = %d AND user_id = %d AND amount_paid >= %f",
			$amount,
			self::STATUS_UNPAID,
			current_time('mysql'),
			(int) $invoice_id,
			(int) $user_id,
			$amount
		));

		if ($updated) {
			self::update_user_earliest_due_date($user_id);
		}

		return 1 === (int) $updated;
	}

	/**
	 * Whether an invoice should be displayed as overdue. This never changes
	 * the stored 'status' column — it is purely a display-time calculation.
	 *
	 * @param object $invoice A row object as returned by get_invoice() / get_invoices_for_user().
	 * @return bool
	 */
	public static function is_overdue($invoice)
	{
		if (! $invoice || self::STATUS_UNPAID !== $invoice->status) {
			return false;
		}

		return strtotime($invoice->due_date) < current_time('timestamp');
	}

	/**
	 * Auto-correct any legacy or Odoo invoices where due_date was mistakenly recorded
	 * on the same calendar day as invoice_date.
	 */
	public static function repair_same_day_due_dates()
	{
		try {
			global $wpdb;
			if (! $wpdb) {
				return;
			}
			$table = self::table_name();
			if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
				return;
			}

			$rows = $wpdb->get_results(
				"SELECT id, user_id, invoice_date, due_date FROM {$table}
				 WHERE DATE(due_date) <= DATE(invoice_date) AND user_id > 0 LIMIT 200"
			);

			if (! empty($rows)) {
				foreach ($rows as $r) {
					$new_due = self::calculate_due_date_for_user((int) $r->user_id, $r->invoice_date);
					if ($new_due && $new_due !== $r->due_date) {
						$wpdb->update($table, array('due_date' => $new_due), array('id' => (int) $r->id));
					}
				}
			}
		} catch (\Throwable $e) {
			error_log('CWD repair_same_day_due_dates error: ' . $e->getMessage());
		}
	}

	/**
	 * Generate a secure direct PDF download URL for an invoice.
	 *
	 * @param int $invoice_id
	 * @param int $order_id
	 * @return string
	 */
	public static function get_invoice_download_url($invoice_id = 0, $order_id = 0)
	{
		$invoice_id = (int) $invoice_id;
		$order_id   = (int) $order_id;
		$nonce      = wp_create_nonce('cwd_download_invoice_' . $invoice_id . '_' . $order_id);

		$base = wc_get_endpoint_url('invoices');
		if (empty($base)) {
			$base = wc_get_page_permalink('myaccount');
		}

		return add_query_arg(
			array(
				'cwd_action' => 'download_invoice',
				'invoice_id' => $invoice_id,
				'order_id'   => $order_id,
				'_cwd_nonce' => $nonce,
			),
			$base
		);
	}

	/**
	 * Handle direct invoice download request.
	 * Fetches the official PDF directly from Odoo and streams it to the user as a file download.
	 */
	public static function handle_direct_invoice_download()
	{
		if (! isset($_GET['cwd_action']) || 'download_invoice' !== $_GET['cwd_action']) {
			return;
		}

		if (! is_user_logged_in()) {
			auth_redirect();
			exit;
		}

		$current_user_id = (int) get_current_user_id();
		$invoice_id      = isset($_GET['invoice_id']) ? absint($_GET['invoice_id']) : 0;
		$order_id        = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
		$nonce           = isset($_GET['_cwd_nonce']) ? sanitize_text_field(wp_unslash($_GET['_cwd_nonce'])) : '';

		if (! wp_verify_nonce($nonce, 'cwd_download_invoice_' . $invoice_id . '_' . $order_id)) {
			wp_die(esc_html__('Invalid or expired download link.', 'custom-woo-dashboard'));
		}

		$invoice = null;
		$order   = null;

		if ($invoice_id > 0) {
			$invoice = self::get_invoice($invoice_id, current_user_can('manage_woocommerce') ? 0 : $current_user_id);
			if ($invoice && ! empty($invoice->order_id)) {
				$order = wc_get_order($invoice->order_id);
			}
		}

		if (! $invoice && $order_id > 0) {
			$order = wc_get_order($order_id);
			if ($order) {
				if (! current_user_can('manage_woocommerce') && (int) $order->get_customer_id() !== $current_user_id) {
					wp_die(esc_html__('You do not have permission to view this invoice.', 'custom-woo-dashboard'), '', array('response' => 403));
				}
				$inv_row = self::get_invoices_for_order($order_id);
				if ($inv_row) {
					$invoice = $inv_row;
				}
			}
		}

		if (! $invoice && ! $order) {
			wp_die(esc_html__('Invoice not found.', 'custom-woo-dashboard'), '', array('response' => 404));
		}

		// Resolve Odoo Invoice ID & Document URL
		$odoo_id        = '';
		$document_url   = '';
		$invoice_number = '';

		if ($invoice) {
			$odoo_id        = (string) ($invoice->odoo_invoice_id ?? '');
			$document_url   = (string) ($invoice->document_url ?? '');
			$invoice_number = (string) ($invoice->invoice_number ?? '');
		}

		if ($order) {
			if (empty($odoo_id)) {
				$odoo_id = (string) $order->get_meta('_odoo_invoice_id');
			}
			if (empty($document_url)) {
				$document_url = (string) $order->get_meta('_odoo_invoice_url');
			}
			if (empty($invoice_number)) {
				$invoice_number = (string) ($order->get_meta('_odoo_invoice_number') ?: $order->get_meta('odoo_invoice_number'));
			}
		}

		if (empty($invoice_number)) {
			$invoice_number = 'Invoice-' . ($invoice_id ?: $order_id);
		}

		// Suppress broken third-party Xero or localhost URLs
		if (! empty($document_url) && (stripos($document_url, 'xero') !== false || stripos($document_url, 'localhost') !== false)) {
			$document_url = '';
		}

		$odoo_base = defined('CWD_ODOO_BASE_URL') ? CWD_ODOO_BASE_URL : get_option('cwd_odoo_base_url', '');
		$odoo_base = apply_filters('cwd_v2_odoo_base_url', $odoo_base);
		if (empty($odoo_base)) {
			$odoo_base = 'https://lahoreanalytica-ewsupplies-uk-uat-38561474.dev.odoo.com';
		}
		$odoo_base = rtrim($odoo_base, '/');

		// Candidate URLs to fetch Odoo PDF
		$candidates = array();
		if (! empty($document_url)) {
			$candidates[] = add_query_arg(array('report_type' => 'pdf', 'download' => 'true'), $document_url);
		}
		if (! empty($odoo_id)) {
			// 1. Direct custom template report endpoint
			$candidates[] = $odoo_base . '/report/pdf/ews_invoice.report_ews_invoice/' . rawurlencode($odoo_id);
			// 2. Portal download endpoint
			$candidates[] = $odoo_base . '/my/invoices/' . rawurlencode($odoo_id) . '?report_type=pdf&download=true';
			// 3. Standard account report endpoint
			$candidates[] = $odoo_base . '/report/pdf/account.report_invoice/' . rawurlencode($odoo_id);
		}

		$clean_filename = sanitize_file_name(str_replace('/', '-', $invoice_number)) . '.pdf';

		// Attempt to fetch PDF binary directly from Odoo
		foreach ($candidates as $cand_url) {
			$response = wp_remote_get($cand_url, array(
				'timeout'     => 15,
				'sslverify'   => false,
				'redirection' => 5,
				'headers'     => array(
					'Accept' => 'application/pdf',
				),
			));

			if (! is_wp_error($response)) {
				$code = wp_remote_retrieve_response_code($response);
				$body = wp_remote_retrieve_body($response);
				$content_type = wp_remote_retrieve_header($response, 'content-type');

				// Check if response is valid PDF
				if (200 === $code && (strpos($body, '%PDF') === 0 || stripos((string) $content_type, 'application/pdf') !== false)) {
					// Serve as direct file download
					nocache_headers();
					header('Content-Type: application/pdf');
					header('Content-Disposition: attachment; filename="' . $clean_filename . '"');
					header('Content-Length: ' . strlen($body));
					header('Cache-Control: private, max-age=0, must-revalidate');
					header('Pragma: public');
					echo $body;
					exit;
				}
			}
		}

		// Fallback: If direct binary streaming was redirected, redirect browser directly to the candidate portal download URL
		if (! empty($candidates)) {
			wp_redirect($candidates[0]);
			exit;
		}

		wp_die(esc_html__('Unable to retrieve invoice PDF at this time. Please contact support.', 'custom-woo-dashboard'));
	}
}
