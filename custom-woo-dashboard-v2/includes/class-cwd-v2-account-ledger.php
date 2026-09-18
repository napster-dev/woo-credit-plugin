<?php
/**
 * Account transaction ledger — records every credit-account balance change
 * (charges, payments, refunds) so a full account statement can be shown later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CWD_V2_Account_Ledger {

	const TYPE_CHARGE     = 'charge';
	const TYPE_PAYMENT    = 'payment';
	const TYPE_REFUND     = 'refund';
	const TYPE_ADJUSTMENT = 'adjustment';

	/**
	 * @return string The fully-prefixed account transactions table name.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'cwd_v2_account_transactions';
	}

	/**
	 * Records one ledger entry. Call this AFTER update_user_meta() has already
	 * written the new '_credit_balance' value, so that balance_after is accurate.
	 *
	 * @param int    $user_id
	 * @param string $type            One of the TYPE_* constants above.
	 * @param float  $amount          Always pass a positive number.
	 * @param string $reference_type  'order', 'invoice', or 'manual'. Optional.
	 * @param int    $reference_id    The related order or invoice ID. Optional.
	 * @param string $note            Short human-readable description. Optional.
	 * @return int|false The number of rows inserted, or false on error.
	 */
	public static function record( $user_id, $type, $amount, $reference_type = '', $reference_id = 0, $note = '' ) {
		global $wpdb;

		$balance_after = (float) get_user_meta( $user_id, '_credit_balance', true );

		return $wpdb->insert(
			self::table_name(),
			array(
				'user_id'        => (int) $user_id,
				'type'           => $type,
				'amount'         => abs( (float) $amount ),
				'balance_after'  => $balance_after,
				'reference_type' => $reference_type,
				'reference_id'   => (int) $reference_id,
				'note'           => $note,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%f', '%f', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * @param int $user_id
	 * @param int $limit
	 * @return object[] Newest first.
	 */
	public static function get_transactions_for_user( $user_id, $limit = 50 ) {
		global $wpdb;
		$table = self::table_name();

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d", (int) $user_id, (int) $limit )
		);
	}
}