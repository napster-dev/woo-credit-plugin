<?php

/**
 * Invoice data access and automatic invoice generation.
 */

if (! defined('ABSPATH')) {
	exit;
}

class CWD_V2_Invoices
{

	const STATUS_UNPAID  = 'unpaid';
	const STATUS_PAID    = 'paid';
	const STATUS_OVERDUE = 'overdue';
	const STATUS_VOID    = 'void';

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

		return $wpdb->get_results($sql);
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
		if ( '' === $odoo_id || ! $user_id ) {
			return false;
		}

		global $wpdb;
		$table = self::table_name();

		$total  = isset( $row['amount_total'] ) ? (float) $row['amount_total'] : 0.0;
		$paid   = min( $total, max( 0.0, isset( $row['amount_paid'] ) ? (float) $row['amount_paid'] : 0.0 ) );
		$status = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : self::STATUS_UNPAID;
		$status = in_array( $status, array( self::STATUS_UNPAID, self::STATUS_PAID, self::STATUS_VOID ), true ) ? $status : self::STATUS_UNPAID;
		$now    = current_time( 'mysql' );

		$data = array(
			'user_id'        => (int) $user_id,
			'invoice_number' => sanitize_text_field( $row['invoice_number'] ?? $odoo_id ),
			'source'         => self::SOURCE_ODOO,
			'invoice_date'   => sanitize_text_field( $row['invoice_date'] ?? $now ),
			'due_date'       => sanitize_text_field( $row['due_date'] ?? $now ),
			'status'         => $paid >= $total && $total > 0 ? self::STATUS_PAID : $status,
			'amount_total'   => $total,
			'amount_paid'    => $paid,
			'document_url'   => ! empty( $row['document_url'] ) ? esc_url_raw( $row['document_url'] ) : null,
			'updated_at'     => $now,
		);

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE odoo_invoice_id = %s", $odoo_id ) );
		if ( $existing ) {
			return false !== $wpdb->update(
				$table,
				$data,
				array( 'id' => (int) $existing ),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s' ),
				array( '%d' )
			);
		}

		return false !== $wpdb->insert(
			$table,
			array_merge( $data, array( 'odoo_invoice_id' => $odoo_id, 'order_id' => null, 'created_at' => $now ) ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s' )
		);
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
	 * @param int $invoice_id
	 * @param int $user_id Optional. If provided, only returns the invoice if it belongs to this user.
	 * @return object|null
	 */
	public static function get_invoice($invoice_id, $user_id = 0)
	{
		global $wpdb;
		$table = self::table_name();

		if ($user_id) {
			return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND user_id = %d", $invoice_id, $user_id));
		}

		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $invoice_id));
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
}
