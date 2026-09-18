<?php
/**
 * Invoice data access and automatic invoice generation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CWD_V2_Invoices {

	const STATUS_UNPAID  = 'unpaid';
	const STATUS_PAID    = 'paid';
	const STATUS_OVERDUE = 'overdue';
	const STATUS_VOID    = 'void';

	const SOURCE_WEBSITE = 'website';
	const SOURCE_ODOO    = 'odoo';

	public static function init() {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'generate_invoice_for_order' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'generate_invoice_for_order' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'generate_invoice_for_order' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'void_invoice_for_order' ) );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'void_invoice_for_order' ) );
	}

	/**
	 * @return string The fully-prefixed invoices table name.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'cwd_v2_invoices';
	}

	/**
	 * Creates exactly one invoice row for an order, the first time it reaches
	 * a "paid/processing" state. Safe to call multiple times for the same order.
	 *
	 * @param int $order_id
	 */
	public static function generate_invoice_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->get_meta( '_cwd_v2_invoice_generated' ) ) {
			return;
		}

		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			return;
		}

		global $wpdb;
		$table = self::table_name();

		// Belt-and-braces duplicate check in case the meta flag write below
		// didn't persist for some reason (e.g. two hooks firing in the same
		// request before either save() call completes).
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d", $order_id ) );
		if ( $existing ) {
			$order->update_meta_data( '_cwd_v2_invoice_generated', 'yes' );
			$order->save();
			return;
		}

		$is_credit_order = ( 'cwd_v2_credit_account' === $order->get_payment_method() );
		$due_days        = self::get_due_days();
		$invoice_date    = current_time( 'mysql' );
		$due_date        = $is_credit_order
			? gmdate( 'Y-m-d H:i:s', strtotime( $invoice_date . ' + ' . $due_days . ' days' ) )
			: $invoice_date;
		$status          = $is_credit_order ? self::STATUS_UNPAID : self::STATUS_PAID;
		$amount_total    = (float) $order->get_total();
		$amount_paid     = $is_credit_order ? 0.0 : $amount_total;

		$wpdb->insert(
			$table,
			array(
				'order_id'        => $order_id,
				'user_id'         => $user_id,
				'invoice_number'  => self::build_invoice_number( $order_id ),
				'source'          => self::SOURCE_WEBSITE,
				'odoo_invoice_id' => null,
				'invoice_date'    => $invoice_date,
				'due_date'        => $due_date,
				'status'          => $status,
				'amount_total'    => $amount_total,
				'amount_paid'     => $amount_paid,
				'created_at'      => current_time( 'mysql' ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s' )
		);

		$order->update_meta_data( '_cwd_v2_invoice_generated', 'yes' );
		$order->save();
	}

	/**
	 * Marks the invoice for an order as void (order cancelled or fully refunded
	 * outside of the normal payment flow).
	 *
	 * @param int $order_id
	 */
	public static function void_invoice_for_order( $order_id ) {
		global $wpdb;
		$table = self::table_name();

		$wpdb->update(
			$table,
			array(
				'status'     => self::STATUS_VOID,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'order_id' => $order_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param int $order_id
	 * @return string The invoice number, e.g. INV-000042.
	 */
	public static function build_invoice_number( $order_id ) {
		return 'INV-' . str_pad( (string) $order_id, 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Number of days credit-account invoices are given to be paid.
	 * Configurable via the 'cwd_v2_invoice_due_days' option (defaults to 30).
	 * There is no admin settings screen for this yet — override it with
	 * update_option( 'cwd_v2_invoice_due_days', 45 ) if a different value
	 * is needed later.
	 *
	 * @return int
	 */
	public static function get_due_days() {
		$days = get_option( 'cwd_v2_invoice_due_days', 30 );
		return absint( $days ) > 0 ? absint( $days ) : 30;
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
	public static function get_invoices_for_user( $user_id, $args = array() ) {
		global $wpdb;
		$table = self::table_name();

		$defaults = array(
			'status'  => '',
			'orderby' => 'invoice_date',
			'order'   => 'DESC',
			'limit'   => 50,
		);
		$args = wp_parse_args( $args, $defaults );

		$allowed_orderby = array( 'invoice_date', 'due_date', 'amount_total', 'status' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'invoice_date';
		$order_dir       = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		if ( ! empty( $args['status'] ) ) {
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

		return $wpdb->get_results( $sql );
	}

	/**
	 * @param int $invoice_id
	 * @param int $user_id Optional. If provided, only returns the invoice if it belongs to this user.
	 * @return object|null
	 */
	public static function get_invoice( $invoice_id, $user_id = 0 ) {
		global $wpdb;
		$table = self::table_name();

		if ( $user_id ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND user_id = %d", $invoice_id, $user_id ) );
		}

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $invoice_id ) );
	}

	/**
	 * @param int $user_id
	 * @param int $limit
	 * @return object[] Unpaid invoices for the user, soonest due date first.
	 */
	public static function get_outstanding_invoices_for_user( $user_id, $limit = 50 ) {
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
	public static function mark_invoice_paid( $invoice_id ) {
		global $wpdb;
		$table = self::table_name();

		$invoice = self::get_invoice( $invoice_id );
		if ( ! $invoice ) {
			return false;
		}

		return $wpdb->update(
			$table,
			array(
				'status'      => self::STATUS_PAID,
				'amount_paid' => $invoice->amount_total,
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $invoice_id ),
			array( '%s', '%f', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Whether an invoice should be displayed as overdue. This never changes
	 * the stored 'status' column — it is purely a display-time calculation.
	 *
	 * @param object $invoice A row object as returned by get_invoice() / get_invoices_for_user().
	 * @return bool
	 */
	public static function is_overdue( $invoice ) {
		if ( ! $invoice || self::STATUS_UNPAID !== $invoice->status ) {
			return false;
		}

		return strtotime( $invoice->due_date ) < current_time( 'timestamp' );
	}
}