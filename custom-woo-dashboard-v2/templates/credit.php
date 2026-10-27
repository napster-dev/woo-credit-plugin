<?php
/**
 * Credit Dashboard Template
 *
 * Modern minimalist design inspired by Figma e-commerce systems.
 * Strictly flat UI, zero gradients, 2D inline SVGs, zero emojis.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

// 1. Fetch all trade applications and credit requests for this user first
$user_trade_requests     = array();
$pending_credit_request  = null;
$latest_rejected_request = null;
$latest_approved_limit   = 0.0;

if ( class_exists( 'CWD_V2_Trade_Applications' ) ) {
	global $wpdb;
	$apps_table = CWD_V2_Trade_Applications::get_table_name();
	CWD_V2_Trade_Applications::ensure_table_exists();

	$user_email          = $current_user->user_email;
	$user_trade_requests = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$apps_table} WHERE user_id = %d OR applicant_email = %s ORDER BY id DESC",
		$user_id,
		$user_email
	) );

	if ( ! empty( $user_trade_requests ) ) {
		foreach ( $user_trade_requests as $req ) {
			if ( 'pending' === $req->status && null === $pending_credit_request ) {
				$pending_credit_request = $req;
			}
			if ( 'approved' === $req->status && (float) $req->approved_limit > 0 && $latest_approved_limit <= 0 ) {
				$latest_approved_limit = (float) $req->approved_limit;
			}
		}
		if ( 'rejected' === $user_trade_requests[0]->status ) {
			$latest_rejected_request = $user_trade_requests[0];
		}
	}
}

// 2. Resolve approved credit limit dynamically from Credit Logic and database
$credit_limit = class_exists( 'CWD_V2_Credit_Logic' ) ? CWD_V2_Credit_Logic::get_user_credit_limit( $user_id ) : (float) get_user_meta( $user_id, '_credit_limit', true );
if ( $latest_approved_limit > 0 && $latest_approved_limit != $credit_limit ) {
	$credit_limit = $latest_approved_limit;
	if ( class_exists( 'CWD_V2_Credit_Logic' ) ) {
		CWD_V2_Credit_Logic::sync_user_credit_limit( $user_id, $credit_limit );
	}
}

// 3. Resolve live balance (amount owed) and compute available credit
$credit_balance   = class_exists( 'CWD_V2_Credit_Logic' ) ? CWD_V2_Credit_Logic::get_user_credit_balance( $user_id ) : (float) get_user_meta( $user_id, '_credit_balance', true );
$due_date         = (string) get_user_meta( $user_id, '_credit_due_date', true );
$user_terms       = get_user_meta( $user_id, '_credit_payment_terms', true ) ?: '30 Days End of Month';

// Auto-sync due date if agreed terms are End of Month / Net EOM
$terms_lower = strtolower( trim( (string) $user_terms ) );
if ( strpos( $terms_lower, 'month' ) !== false || strpos( $terms_lower, 'eom' ) !== false ) {
	$eom_date = date( 'Y-m-t', current_time( 'timestamp' ) );
	if ( empty( $due_date ) || strtotime( $due_date ) < strtotime( $eom_date ) ) {
		$due_date = $eom_date;
		update_user_meta( $user_id, '_credit_due_date', $due_date );

		if ( class_exists( 'CWD_V2_Invoices' ) ) {
			global $wpdb;
			$inv_tbl = CWD_V2_Invoices::table_name();
			if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $inv_tbl ) ) === $inv_tbl ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$inv_tbl} SET due_date = %s WHERE user_id = %d AND status = %s AND due_date < %s",
					$due_date . ' 23:59:59',
					$user_id,
					'unpaid',
					$due_date . ' 00:00:00'
				) );
			}
		}
	}
}

$account_number   = (string) get_user_meta( $user_id, 'ews_account_number', true );
$available_credit = max( 0, $credit_limit - $credit_balance );


// Fetch credit purchase history (orders made with credit payment gateways)
$recent_orders_raw = wc_get_orders( array(
	'customer_id' => $user_id,
	'limit'       => 30,
	'orderby'     => 'date',
	'order'       => 'DESC',
	'status'      => array( 'processing', 'completed', 'on-hold', 'pending' ),
) );

$credit_orders       = array();
$credit_method_slugs = array( 'cwd_v2_credit_account', 'credits', 'wc_cs', 'wc_cs_credits', 'fpc_credits', 'trade_credit' );

foreach ( $recent_orders_raw as $cand_order ) {
	$pm = strtolower( (string) $cand_order->get_payment_method() );
	$is_credit_ord = in_array( $pm, $credit_method_slugs, true )
		|| ( 'yes' === $cand_order->get_meta( '_paid_with_credit' ) )
		|| ( (float) $cand_order->get_meta( '_credit_charged_amount' ) > 0 );
	if ( $is_credit_ord ) {
		$credit_orders[] = $cand_order;
	}
}

// Print notices
wc_print_notices();
?>

<style id="cwd-v2-credit-critical-styles">
.cwd-v2-credit-dashboard {
    width: 100% !important;
    max-width: 1200px !important;
    margin: 0 auto !important;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif !important;
    color: #0f172a !important;
    box-sizing: border-box !important;
}
.cwd-v2-credit-dashboard *, .cwd-v2-credit-dashboard *::before, .cwd-v2-credit-dashboard *::after {
    box-sizing: border-box !important;
}
.cwd-v2-back-button {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    padding: 7px 14px !important;
    background: #f1f5f9 !important;
    color: #334155 !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 6px !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    text-decoration: none !important;
    margin-bottom: 20px !important;
    line-height: 1 !important;
    transition: all 0.15s ease !important;
}
.cwd-v2-back-button:hover {
    background: #e2e8f0 !important;
    color: #0f172a !important;
    text-decoration: none !important;
}
.cwd-v2-credit-header {
    display: flex !important;
    justify-content: space-between !important;
    align-items: flex-start !important;
    flex-wrap: wrap !important;
    gap: 16px !important;
    margin-bottom: 24px !important;
    padding-bottom: 20px !important;
    border-bottom: 1px solid #e2e8f0 !important;
}
.cwd-v2-credit-header h2 {
    font-size: 24px !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    margin: 0 0 6px 0 !important;
    line-height: 1.2 !important;
}
.cwd-v2-credit-header p {
    font-size: 14px !important;
    color: #64748b !important;
    margin: 0 !important;
}
.cwd-v2-account-pill {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    background: #f8fafc !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    padding: 6px 12px !important;
    font-size: 12.5px !important;
    color: #475569 !important;
}
.cwd-v2-account-pill strong {
    color: #0f172a !important;
    font-weight: 700 !important;
}
.cwd-v2-credit-summary-grid {
    display: grid !important;
    grid-template-columns: repeat(4, 1fr) !important;
    gap: 16px !important;
    margin-bottom: 28px !important;
}
@media (max-width: 1024px) {
    .cwd-v2-credit-summary-grid {
        grid-template-columns: repeat(2, 1fr) !important;
    }
}
@media (max-width: 600px) {
    .cwd-v2-credit-summary-grid {
        grid-template-columns: 1fr !important;
    }
}
.cwd-v2-stat-card {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 8px !important;
    padding: 20px !important;
    min-height: 128px !important;
    display: flex !important;
    flex-direction: column !important;
    justify-content: space-between !important;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04) !important;
}
.cwd-v2-stat-top {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    margin-bottom: 10px !important;
}
.cwd-v2-stat-label {
    font-size: 11.5px !important;
    font-weight: 600 !important;
    text-transform: uppercase !important;
    color: #64748b !important;
    letter-spacing: 0.05em !important;
    margin: 0 !important;
}
.cwd-v2-stat-icon {
    width: 20px !important;
    height: 20px !important;
    flex-shrink: 0 !important;
}
.cwd-v2-stat-value {
    font-size: 26px !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    line-height: 1.15 !important;
    letter-spacing: -0.02em !important;
    margin: 0 !important;
}
.cwd-v2-stat-value.cwd-val-danger { color: #dc2626 !important; }
.cwd-v2-stat-value.cwd-val-success { color: #16a34a !important; }
.cwd-v2-stat-value.cwd-val-date { font-size: 20px !important; }
.cwd-v2-stat-desc {
    font-size: 12px !important;
    color: #94a3b8 !important;
    margin-top: 6px !important;
    margin-bottom: 0 !important;
    line-height: 1.3 !important;
}
.cwd-v2-action-cards-grid {
    display: grid !important;
    grid-template-columns: repeat(2, 1fr) !important;
    gap: 20px !important;
    margin-bottom: 28px !important;
    align-items: stretch !important;
}
@media (max-width: 768px) {
    .cwd-v2-action-cards-grid {
        grid-template-columns: 1fr !important;
    }
}
.cwd-v2-action-card {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 8px !important;
    padding: 24px !important;
    display: flex !important;
    flex-direction: column !important;
    justify-content: space-between !important;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04) !important;
}
.cwd-v2-action-header {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    margin-bottom: 8px !important;
}
.cwd-v2-action-header h3 {
    font-size: 16px !important;
    font-weight: 600 !important;
    color: #0f172a !important;
    margin: 0 !important;
}
.cwd-v2-action-desc {
    font-size: 13px !important;
    color: #64748b !important;
    margin: 0 0 16px 0 !important;
    line-height: 1.45 !important;
}
.cwd-v2-btn-primary {
    background: #0f172a !important;
    color: #ffffff !important;
    border: 1px solid #0f172a !important;
    border-radius: 6px !important;
    padding: 10px 20px !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    text-decoration: none !important;
    line-height: 1.4 !important;
    transition: all 0.15s ease !important;
}
.cwd-v2-btn-primary:hover {
    background: #1e293b !important;
    border-color: #1e293b !important;
    color: #ffffff !important;
}
.cwd-v2-section-box {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 8px !important;
    padding: 24px !important;
    margin-bottom: 28px !important;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04) !important;
}
.cwd-v2-section-top {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    flex-wrap: wrap !important;
    gap: 12px !important;
    margin-bottom: 16px !important;
}
.cwd-v2-section-top h3 {
    font-size: 16px !important;
    font-weight: 600 !important;
    color: #0f172a !important;
    margin: 0 0 4px 0 !important;
}
.cwd-v2-section-top p {
    font-size: 12.5px !important;
    color: #64748b !important;
    margin: 0 !important;
}
.cwd-v2-table-wrapper {
    overflow-x: auto !important;
    -webkit-overflow-scrolling: touch !important;
    width: 100% !important;
    margin-top: 8px !important;
}
.cwd-v2-data-table {
    width: 100% !important;
    border-collapse: collapse !important;
    font-size: 13px !important;
    margin: 0 !important;
}
.cwd-v2-data-table thead tr th {
    padding: 12px 14px !important;
    font-weight: 600 !important;
    color: #64748b !important;
    font-size: 11.5px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.04em !important;
    border-bottom: 1px solid #e2e8f0 !important;
    border-top: none !important;
    border-left: none !important;
    border-right: none !important;
    background: #f8fafc !important;
    vertical-align: middle !important;
    white-space: nowrap !important;
    text-align: left !important;
}
.cwd-v2-data-table tbody tr td {
    padding: 12px 14px !important;
    vertical-align: middle !important;
    border-bottom: 1px solid #f1f5f9 !important;
    border-top: none !important;
    border-left: none !important;
    border-right: none !important;
    color: #334155 !important;
    background: transparent !important;
}
.cwd-v2-data-table tbody tr:hover td {
    background-color: #f8fafc !important;
}
.cwd-v2-data-table .th-num,
.cwd-v2-data-table .td-num {
    text-align: right !important;
}
.cwd-v2-status-pill {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 3px 10px !important;
    border-radius: 4px !important;
    font-size: 11.5px !important;
    font-weight: 600 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.02em !important;
    line-height: 1.3 !important;
    white-space: nowrap !important;
}
.cwd-v2-btn-view {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 4px !important;
    padding: 6px 14px !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    color: #334155 !important;
    text-decoration: none !important;
    line-height: 1 !important;
    transition: all 0.15s ease !important;
}
.cwd-v2-btn-view:hover {
    background: #f1f5f9 !important;
    border-color: #94a3b8 !important;
    color: #0f172a !important;
}
</style>

<div class="cwd-v2-credit-dashboard">
	
	<!-- Header Bar -->
	<div class="cwd-v2-credit-header">
		<div>
			<h2><?php esc_html_e( 'Trade Credit Account', 'custom-woo-dashboard' ); ?></h2>
			<p><?php esc_html_e( 'Overview of your trade facility, current usage, and settlement terms.', 'custom-woo-dashboard' ); ?></p>
		</div>
		<?php if ( $account_number ) : ?>
			<div class="cwd-v2-account-pill">
				<span><?php esc_html_e( 'Account:', 'custom-woo-dashboard' ); ?></span>
				<strong><?php echo esc_html( $account_number ); ?></strong>
			</div>
		<?php endif; ?>
	</div>

	<!-- 4-Box Summary Grid -->
	<div class="cwd-v2-credit-summary-grid">
		
		<!-- Box 1: Credit Limit -->
		<div class="cwd-v2-stat-card">
			<div class="cwd-v2-stat-top">
				<span class="cwd-v2-stat-label"><?php esc_html_e( 'Total Credit Limit', 'custom-woo-dashboard' ); ?></span>
				<svg class="cwd-v2-stat-icon" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<rect x="2" y="5" width="20" height="14" rx="2"></rect>
					<line x1="2" y1="10" x2="22" y2="10"></line>
				</svg>
			</div>
			<div class="cwd-v2-stat-value">
				<?php echo wp_kses_post( wc_price( $credit_limit ) ); ?>
			</div>
			<div class="cwd-v2-stat-desc">
				<?php esc_html_e( 'Approved facility limit', 'custom-woo-dashboard' ); ?>
			</div>
		</div>

		<!-- Box 2: Current Balance Owed -->
		<div class="cwd-v2-stat-card">
			<div class="cwd-v2-stat-top">
				<span class="cwd-v2-stat-label"><?php esc_html_e( 'Current Balance Owed', 'custom-woo-dashboard' ); ?></span>
				<svg class="cwd-v2-stat-icon" viewBox="0 0 24 24" fill="none" stroke="<?php echo $credit_balance > 0 ? '#dc2626' : '#64748b'; ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<circle cx="12" cy="12" r="10"></circle>
					<polyline points="12 6 12 12 16 14"></polyline>
				</svg>
			</div>
			<div class="cwd-v2-stat-value <?php echo $credit_balance > 0 ? 'cwd-val-danger' : ''; ?>">
				<?php echo wp_kses_post( wc_price( $credit_balance ) ); ?>
			</div>
			<div class="cwd-v2-stat-desc">
				<?php esc_html_e( 'Outstanding invoices & orders', 'custom-woo-dashboard' ); ?>
			</div>
		</div>

		<!-- Box 3: Available Credit -->
		<div class="cwd-v2-stat-card">
			<div class="cwd-v2-stat-top">
				<span class="cwd-v2-stat-label"><?php esc_html_e( 'Available Credit', 'custom-woo-dashboard' ); ?></span>
				<svg class="cwd-v2-stat-icon" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
					<polyline points="22 4 12 14.01 9 11.01"></polyline>
				</svg>
			</div>
			<div class="cwd-v2-stat-value cwd-val-success">
				<?php echo wp_kses_post( wc_price( $available_credit ) ); ?>
			</div>
			<div class="cwd-v2-stat-desc">
				<?php esc_html_e( 'Usable immediately at checkout', 'custom-woo-dashboard' ); ?>
			</div>
		</div>

		<!-- Box 4: Next Due Date -->
		<?php
		$user_terms   = get_user_meta( $user_id, '_credit_payment_terms', true ) ?: '30 Days End of Month';
		$is_overdue   = false;
		$due_display  = '';
		$due_desc     = '';
		$due_css      = '';
		$due_ts       = $due_date ? strtotime( $due_date ) : false;

		if ( $credit_balance > 0 && $due_ts && $due_ts < current_time( 'timestamp' ) ) {
			// Overdue balance
			$is_overdue  = true;
			$due_display = date_i18n( 'd M Y', $due_ts );
			$due_desc    = esc_html__( 'Payment overdue - please settle balance', 'custom-woo-dashboard' );
			$due_css     = 'color: #dc2626; font-size: 19px; font-weight: 800;';
		} elseif ( $credit_balance > 0 && $due_ts ) {
			// Active balance with future due date
			$days_left   = max( 0, (int) round( ( $due_ts - current_time( 'timestamp' ) ) / DAY_IN_SECONDS ) );
			$due_display = date_i18n( 'd M Y', $due_ts );
			$due_desc    = sprintf( esc_html__( '%d days remaining (%s)', 'custom-woo-dashboard' ), $days_left, $user_terms );
			$due_css     = 'color: #0f172a; font-size: 20px;';
		} elseif ( $due_ts && $due_ts >= current_time( 'timestamp' ) ) {
			// Scheduled statement date
			$due_display = date_i18n( 'd M Y', $due_ts );
			$due_desc    = sprintf( esc_html__( 'Terms: %s', 'custom-woo-dashboard' ), $user_terms );
			$due_css     = 'color: #0f172a; font-size: 20px;';
		} else {
			// Standard settlement schedule
			$due_display = esc_html( $user_terms );
			$due_desc    = esc_html__( 'Standard settlement schedule', 'custom-woo-dashboard' );
			$due_css     = 'color: #0f172a; font-size: 19px;';
		}
		?>
		<div class="cwd-v2-stat-card <?php echo $is_overdue ? 'cwd-card-overdue' : ''; ?>" <?php echo $is_overdue ? 'style="border-color: #fca5a5; background: #fff5f5 !important;"' : ''; ?>>
			<div class="cwd-v2-stat-top">
				<span class="cwd-v2-stat-label" <?php echo $is_overdue ? 'style="color: #dc2626 !important;"' : ''; ?>>
					<?php echo $is_overdue ? esc_html__( 'Payment Overdue', 'custom-woo-dashboard' ) : esc_html__( 'Next Statement Due', 'custom-woo-dashboard' ); ?>
				</span>
				<svg class="cwd-v2-stat-icon" viewBox="0 0 24 24" fill="none" stroke="<?php echo $is_overdue ? '#dc2626' : '#64748b'; ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
					<line x1="16" y1="2" x2="16" y2="6"></line>
					<line x1="8" y1="2" x2="8" y2="6"></line>
					<line x1="3" y1="10" x2="21" y2="10"></line>
				</svg>
			</div>
			<div class="cwd-v2-stat-value cwd-val-date" style="<?php echo esc_attr( $due_css ); ?>">
				<?php echo esc_html( $due_display ); ?>
			</div>
			<div class="cwd-v2-stat-desc" <?php echo $is_overdue ? 'style="color: #b91c1c !important; font-weight: 600;"' : ''; ?>>
				<?php echo esc_html( $due_desc ); ?>
			</div>
		</div>

	</div>

	<!-- Action Cards: Pay Off Balance & Request Increase -->
	<div class="cwd-v2-action-cards-grid">
		
		<!-- Card 1: Pay Balance -->
		<div class="cwd-v2-action-card">
			<div>
				<div class="cwd-v2-action-header">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0f172a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<rect x="2" y="5" width="20" height="14" rx="2"></rect>
						<line x1="2" y1="10" x2="22" y2="10"></line>
					</svg>
					<h3><?php esc_html_e( 'Settle Credit Balance', 'custom-woo-dashboard' ); ?></h3>
				</div>
				<p class="cwd-v2-action-desc">
					<?php esc_html_e( 'Pay down your outstanding balance via debit or credit card to restore your available credit.', 'custom-woo-dashboard' ); ?>
				</p>
			</div>

			<div>
				<?php if ( $credit_balance > 0 ) : ?>
					<form method="post" action="">
						<?php wp_nonce_field( 'cwd_v2_pay_credit_action', 'cwd_v2_pay_credit_nonce' ); ?>
						<div style="margin-bottom: 16px;">
							<label for="cwd_v2_pay_amount" style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
								<?php esc_html_e( 'Amount to Pay', 'custom-woo-dashboard' ); ?> (<?php echo esc_html( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ); ?>)
							</label>
							<input type="number" step="0.01" min="0.01" max="<?php echo esc_attr( $credit_balance ); ?>" name="cwd_v2_pay_amount" id="cwd_v2_pay_amount" value="<?php echo esc_attr( $credit_balance ); ?>" required style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; font-size: 14px; box-sizing: border-box; background: #ffffff;" />
						</div>
						<button type="submit" name="cwd_v2_pay_credit_balance" class="cwd-v2-btn-primary">
							<?php esc_html_e( 'Proceed to Settlement', 'custom-woo-dashboard' ); ?>
						</button>
					</form>
				<?php else : ?>
					<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 16px; font-size: 13px; color: #64748b; line-height: 1.45;">
						<?php esc_html_e( 'Your account has zero outstanding balance. No payment is currently due.', 'custom-woo-dashboard' ); ?>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<!-- Card 2: Apply for Trade Credit / Request Limit Increase -->
		<div class="cwd-v2-action-card">
			<div>
				<?php if ( $credit_limit > 0 ) : ?>
					<div class="cwd-v2-action-header">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0f172a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<line x1="12" y1="19" x2="12" y2="5"></line>
							<polyline points="5 12 12 5 19 12"></polyline>
						</svg>
						<h3><?php esc_html_e( 'Request Credit Limit Increase', 'custom-woo-dashboard' ); ?></h3>
					</div>
					<p class="cwd-v2-action-desc">
						<?php esc_html_e( 'Submit a formal request to our trade finance team to increase your facility.', 'custom-woo-dashboard' ); ?>
					</p>
				<?php else : ?>
					<div class="cwd-v2-action-header">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0f172a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
							<path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
						</svg>
						<h3><?php esc_html_e( 'Apply for Trade Credit Facility', 'custom-woo-dashboard' ); ?></h3>
					</div>
					<p class="cwd-v2-action-desc">
						<?php esc_html_e( 'Get pre-approved for an official trade credit facility on Net 30 terms with instant checkout access.', 'custom-woo-dashboard' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<div>
				<?php if ( $pending_credit_request ) : ?>
					<!-- Pending Request Box -->
					<div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 16px;">
						<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
							<strong style="color: #92400e; font-size: 13.5px;">
								<?php echo ( (int) $pending_credit_request->forminator_form_id === 0 && $credit_limit > 0 ) ? esc_html__( 'Credit Limit Request Under Review', 'custom-woo-dashboard' ) : esc_html__( 'Trade Application Under Review', 'custom-woo-dashboard' ); ?>
							</strong>
						</div>
						<p style="font-size: 13px; color: #78350f; margin: 0; line-height: 1.45;">
							<?php printf( esc_html__( 'You submitted a trade request for %s on %s. Our trade finance team is actively reviewing your facility.', 'custom-woo-dashboard' ), '<strong>' . wp_kses_post( wc_price( $pending_credit_request->requested_limit ) ) . '</strong>', esc_html( date_i18n( get_option( 'date_format' ), strtotime( $pending_credit_request->created_at ) ) ) ); ?>
						</p>
					</div>
				<?php else : ?>
					<?php if ( $latest_rejected_request ) : ?>
						<!-- Previous Rejection Notice -->
						<div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 14px 16px; margin-bottom: 16px;">
							<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
								<strong style="color: #991b1b; font-size: 13.5px;"><?php esc_html_e( 'Previous Request Not Approved', 'custom-woo-dashboard' ); ?></strong>
							</div>
							<p style="font-size: 13px; color: #7f1d1d; margin: 0; line-height: 1.4;">
								<?php printf( esc_html__( 'Your previous request for %s on %s was not approved.', 'custom-woo-dashboard' ), '<strong>' . wp_kses_post( wc_price( $latest_rejected_request->requested_limit ) ) . '</strong>', esc_html( date_i18n( get_option( 'date_format' ), strtotime( $latest_rejected_request->reviewed_at ?: $latest_rejected_request->created_at ) ) ) ); ?>
								<?php if ( ! empty( $latest_rejected_request->admin_note ) ) : ?>
									<br><span style="margin-top: 4px; display: inline-block;"><strong><?php esc_html_e( 'Reason:', 'custom-woo-dashboard' ); ?></strong> <?php echo esc_html( $latest_rejected_request->admin_note ); ?></span>
								<?php endif; ?>
							</p>
						</div>
					<?php endif; ?>

					<?php if ( $credit_limit > 0 ) : ?>
						<!-- Limit Increase Form -->
						<form method="post" action="" onsubmit="var btn = this.querySelector('button[type=submit]'); if (btn) { btn.style.pointerEvents='none'; btn.style.opacity='0.7'; btn.textContent='Submitting...'; }">
							<?php wp_nonce_field( 'cwd_v2_request_increase_action', 'cwd_v2_request_increase_nonce' ); ?>
							<input type="hidden" name="cwd_v2_request_increase" value="1" />
							<div style="margin-bottom: 12px;">
								<label for="cwd_v2_requested_amount" style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
									<?php esc_html_e( 'Requested New Limit', 'custom-woo-dashboard' ); ?> (<?php echo esc_html( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ); ?>)
								</label>
								<input type="number" step="any" min="1" name="cwd_v2_requested_amount" id="cwd_v2_requested_amount" placeholder="<?php esc_attr_e( 'Enter requested new limit', 'custom-woo-dashboard' ); ?>" required style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; font-size: 14px; box-sizing: border-box; background: #ffffff;" />
							</div>
							<div style="margin-bottom: 16px;">
								<label for="cwd_v2_request_reason" style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
									<?php esc_html_e( 'Reason / Trade Justification', 'custom-woo-dashboard' ); ?>
								</label>
								<textarea name="cwd_v2_request_reason" id="cwd_v2_request_reason" rows="2" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13.5px; box-sizing: border-box; background: #ffffff;" required></textarea>
							</div>
							<button type="submit" name="cwd_v2_request_increase" class="cwd-v2-btn-primary">
								<?php esc_html_e( 'Submit Request', 'custom-woo-dashboard' ); ?>
							</button>
						</form>
					<?php else : ?>
						<!-- Trade Credit Application Form -->
						<form method="post" action="" onsubmit="var btn = this.querySelector('button[type=submit]'); if (btn) { btn.style.pointerEvents='none'; btn.style.opacity='0.7'; btn.textContent='Submitting Application...'; }">
							<?php wp_nonce_field( 'cwd_v2_apply_trade_action', 'cwd_v2_apply_trade_nonce' ); ?>
							<input type="hidden" name="cwd_v2_submit_trade_app" value="1" />
							<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 12px;">
								<div>
									<label for="cwd_v2_company_name" style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px;">
										<?php esc_html_e( 'Company / Business Name', 'custom-woo-dashboard' ); ?> *
									</label>
									<input type="text" name="cwd_v2_company_name" id="cwd_v2_company_name" value="<?php echo esc_attr( get_user_meta( $user_id, 'billing_company', true ) ); ?>" required style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 9px 12px; font-size: 13.5px; box-sizing: border-box; background: #ffffff;" />
								</div>
								<div>
									<label for="cwd_v2_phone" style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px;">
										<?php esc_html_e( 'Contact Phone', 'custom-woo-dashboard' ); ?> *
									</label>
									<input type="tel" name="cwd_v2_phone" id="cwd_v2_phone" value="<?php echo esc_attr( get_user_meta( $user_id, 'billing_phone', true ) ); ?>" required style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 9px 12px; font-size: 13.5px; box-sizing: border-box; background: #ffffff;" />
								</div>
							</div>
							<div style="margin-bottom: 12px;">
								<label for="cwd_v2_requested_limit" style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px;">
									<?php esc_html_e( 'Requested Credit Facility', 'custom-woo-dashboard' ); ?> (<?php echo esc_html( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ); ?>) *
								</label>
								<input type="number" step="any" min="1" name="cwd_v2_requested_limit" id="cwd_v2_requested_limit" placeholder="<?php esc_attr_e( 'Enter requested facility limit', 'custom-woo-dashboard' ); ?>" required style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 9px 12px; font-size: 13.5px; box-sizing: border-box; background: #ffffff;" />
							</div>
							<div style="margin-bottom: 16px;">
								<label for="cwd_v2_trading_details" style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px;">
									<?php esc_html_e( 'Business Description / Trading Type', 'custom-woo-dashboard' ); ?>
								</label>
								<textarea name="cwd_v2_trading_details" id="cwd_v2_trading_details" rows="2" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13px; box-sizing: border-box; background: #ffffff;" placeholder="<?php esc_attr_e( 'e.g. Electrical contracting, construction supply, regular monthly trade volume...', 'custom-woo-dashboard' ); ?>"></textarea>
							</div>
							<button type="submit" name="cwd_v2_submit_trade_app" class="cwd-v2-btn-primary">
								<?php esc_html_e( 'Submit Trade Application', 'custom-woo-dashboard' ); ?>
							</button>
						</form>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>

	</div>

	<!-- Credit Purchase History & Payoff Table -->
	<div class="cwd-v2-section-box">
		<div class="cwd-v2-section-top">
			<div>
				<h3><?php esc_html_e( 'Trade Credit Orders & Early Payoff', 'custom-woo-dashboard' ); ?></h3>
				<p><?php esc_html_e( 'Select open credit orders below to pay them off immediately. Any unselected orders will be settled on your monthly schedule.', 'custom-woo-dashboard' ); ?></p>
			</div>
		</div>

		<?php if ( ! empty( $credit_orders ) ) : ?>
			<form method="post" action="" id="cwd-v2-pay-orders-form">
				<?php wp_nonce_field( 'cwd_v2_pay_credit_action', 'cwd_v2_pay_credit_nonce' ); ?>
				<input type="hidden" name="cwd_v2_pay_credit_balance" value="1" />
				<input type="hidden" name="cwd_v2_pay_selected_orders" value="1" />
				<input type="hidden" name="cwd_v2_pay_amount" id="cwd-orders-hidden-amount" value="0.00" />

				<div class="cwd-v2-table-wrapper">
					<table class="cwd-v2-data-table">
						<thead>
							<tr>
								<th style="width: 44px; text-align: center; vertical-align: middle;">
									<input type="checkbox" id="cwd-select-all-orders" title="<?php esc_attr_e( 'Select all unsettled orders', 'custom-woo-dashboard' ); ?>" style="cursor: pointer; width: 16px; height: 16px; accent-color: #0284c7; margin: 0; vertical-align: middle;" />
								</th>
								<th><?php esc_html_e( 'Order', 'woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Date', 'woocommerce' ); ?></th>
								<th style="text-align: center;"><?php esc_html_e( 'Credit Status', 'custom-woo-dashboard' ); ?></th>
								<th><?php esc_html_e( 'Order Status', 'woocommerce' ); ?></th>
								<th class="th-num"><?php esc_html_e( 'Total', 'woocommerce' ); ?></th>
								<th style="text-align: right;"><?php esc_html_e( 'Action', 'woocommerce' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$has_unsettled = false;
							foreach ( $credit_orders as $order ) :
								$is_settled = ( 'yes' === $order->get_meta( '_credit_order_settled' ) );
								if ( ! $is_settled && class_exists( 'CWD_V2_Invoices' ) ) {
									$linked_inv = CWD_V2_Invoices::get_invoices_for_order( $order->get_id() );
									if ( $linked_inv && CWD_V2_Invoices::STATUS_PAID === $linked_inv->status ) {
										$is_settled = true;
									}
								}
								if ( ! $is_settled ) {
									$has_unsettled = true;
								}
								$order_total_val = (float) $order->get_total();
								?>
								<tr>
									<td style="text-align: center; vertical-align: middle; width: 44px;">
										<?php if ( $is_settled ) : ?>
											<span style="color: #16a34a; font-weight: 700; font-size: 15px;" title="<?php esc_attr_e( 'Paid off / Settled', 'custom-woo-dashboard' ); ?>">✓</span>
										<?php else : ?>
											<input type="checkbox" name="cwd_v2_selected_order_ids[]" value="<?php echo esc_attr( $order->get_id() ); ?>" data-amount="<?php echo esc_attr( $order_total_val ); ?>" class="cwd-order-checkbox" style="cursor: pointer; width: 16px; height: 16px; accent-color: #0284c7; margin: 0; vertical-align: middle;" />
										<?php endif; ?>
									</td>
									<td style="font-weight: 600; color: #0f172a; white-space: nowrap;">
										<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" style="color: #0f172a; text-decoration: none; font-weight: 600;">
											#<?php echo esc_html( $order->get_order_number() ); ?>
										</a>
										<?php if ( ! empty($linked_inv) ) : ?>
											<a href="<?php echo esc_url( wc_get_endpoint_url( 'view-invoice', $linked_inv->id ) ); ?>" style="display: block; font-size: 11px; color: #6366f1; text-decoration: none; font-weight: 500; margin-top: 2px;" title="<?php esc_attr_e( 'View linked invoice', 'custom-woo-dashboard' ); ?>">
												<?php echo esc_html( $linked_inv->invoice_number ); ?>
											</a>
										<?php endif; ?>
									</td>
									<td style="color: #64748b; white-space: nowrap;">
										<time datetime="<?php echo esc_attr( $order->get_date_created()->date( 'c' ) ); ?>">
											<?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?>
										</time>
									</td>
									<td style="text-align: center; white-space: nowrap;">
										<?php if ( $is_settled ) : ?>
											<span class="cwd-v2-status-pill" style="background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 4px;">
												<?php esc_html_e( 'Settled', 'custom-woo-dashboard' ); ?>
											</span>
										<?php else : ?>
											<span class="cwd-v2-status-pill" style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 4px;">
												<?php esc_html_e( 'Open Credit', 'custom-woo-dashboard' ); ?>
											</span>
										<?php endif; ?>
									</td>
									<td style="white-space: nowrap;">
										<span class="cwd-v2-status-pill" style="background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 4px;">
											<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
										</span>
									</td>
									<td class="td-num" style="font-weight: 700; color: #0f172a; white-space: nowrap;">
										<?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>
									</td>
									<td style="text-align: right; white-space: nowrap;">
										<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" class="cwd-v2-btn-view">
											<?php esc_html_e( 'View', 'woocommerce' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<!-- Selected Orders Checkout Action Bar -->
				<div id="cwd-orders-pay-bar" style="display: flex; justify-content: space-between; align-items: center; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; margin-top: 14px; flex-wrap: wrap; gap: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
					<div>
						<div style="font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
							<span><?php esc_html_e( 'Pay Selected Credit Orders', 'custom-woo-dashboard' ); ?></span>
							<span id="cwd-selected-badge" style="background: #f1f5f9; color: #475569; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 12px; border: 1px solid #e2e8f0;">0 selected</span>
						</div>
						<div style="font-size: 12.5px; color: #64748b; margin-top: 4px;">
							<?php esc_html_e( 'Selected payoff amount:', 'custom-woo-dashboard' ); ?> <strong id="cwd-selected-total" style="color: #0f172a; font-size: 14px;"><?php echo esc_html( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ); ?>0.00</strong>
							<span style="font-size: 11.5px; color: #94a3b8; margin-left: 6px;">&bull; <?php esc_html_e( 'Unselected orders remain on standard monthly billing cycle', 'custom-woo-dashboard' ); ?></span>
						</div>
					</div>
					<div>
						<button type="submit" id="cwd-pay-selected-btn" class="cwd-v2-btn-primary" style="opacity: 0.5; pointer-events: none; padding: 10px 22px; font-size: 13.5px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; border-radius: 6px; cursor: pointer; border: none; background: #0284c7; color: #ffffff; transition: all 0.15s ease;" disabled>
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
							<span id="cwd-pay-btn-text"><?php esc_html_e( 'Proceed to Settlement', 'custom-woo-dashboard' ); ?></span>
						</button>
					</div>
				</div>
			</form>

			<script>
			(function() {
				var selectAll = document.getElementById('cwd-select-all-orders');
				var checkboxes = document.querySelectorAll('.cwd-order-checkbox');
				var countEl = document.getElementById('cwd-selected-badge');
				var totalEl = document.getElementById('cwd-selected-total');
				var payBtn = document.getElementById('cwd-pay-selected-btn');
				var btnText = document.getElementById('cwd-pay-btn-text');
				var hiddenAmount = document.getElementById('cwd-orders-hidden-amount');
				var currencySymbol = <?php echo json_encode( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ); ?>;

				function updateSelection() {
					var count = 0;
					var total = 0.0;
					checkboxes.forEach(function(cb) {
						if (cb.checked) {
							count++;
							total += parseFloat(cb.getAttribute('data-amount') || 0);
						}
					});

					if (countEl) {
						countEl.textContent = count + ' selected';
						countEl.style.background = count > 0 ? '#e0f2fe' : '#f1f5f9';
						countEl.style.color = count > 0 ? '#0369a1' : '#475569';
						countEl.style.borderColor = count > 0 ? '#bae6fd' : '#e2e8f0';
					}
					if (totalEl) {
						totalEl.textContent = currencySymbol + total.toFixed(2);
					}
					if (hiddenAmount) {
						hiddenAmount.value = total.toFixed(2);
					}

					if (payBtn) {
						if (count > 0 && total > 0) {
							payBtn.disabled = false;
							payBtn.style.opacity = '1';
							payBtn.style.pointerEvents = 'auto';
							if (btnText) {
								btnText.textContent = '<?php echo esc_js( __( 'Proceed to Settlement', 'custom-woo-dashboard' ) ); ?> (' + currencySymbol + total.toFixed(2) + ')';
							}
						} else {
							payBtn.disabled = true;
							payBtn.style.opacity = '0.5';
							payBtn.style.pointerEvents = 'none';
							if (btnText) {
								btnText.textContent = '<?php echo esc_js( __( 'Proceed to Settlement', 'custom-woo-dashboard' ) ); ?>';
							}
						}
					}

					if (selectAll && checkboxes.length > 0) {
						selectAll.checked = (count === checkboxes.length);
					}
				}

				if (selectAll) {
					selectAll.addEventListener('change', function() {
						checkboxes.forEach(function(cb) {
							cb.checked = selectAll.checked;
						});
						updateSelection();
					});
				}

				checkboxes.forEach(function(cb) {
					cb.addEventListener('change', updateSelection);
				});
			})();
			</script>
		<?php else : ?>
			<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px 20px; font-size: 13.5px; color: #64748b; display: flex; justify-content: space-between; align-items: center;">
				<span><?php esc_html_e( 'No trade credit purchases recorded yet.', 'custom-woo-dashboard' ); ?></span>
				<a href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>" style="color: #0f172a; font-weight: 600; text-decoration: underline;">
					<?php esc_html_e( 'Browse Products', 'woocommerce' ); ?>
				</a>
			</div>
		<?php endif; ?>
	</div>

	<!-- Trade Applications & Facility Requests Section (Bottom) -->
	<div class="cwd-v2-section-box">
		<div class="cwd-v2-section-top">
			<div>
				<h3><?php esc_html_e( 'Trade Applications & Limit Requests', 'custom-woo-dashboard' ); ?></h3>
				<p><?php esc_html_e( 'Track the real-time review status of your trade facility and credit limit adjustment requests.', 'custom-woo-dashboard' ); ?></p>
			</div>
			<span style="font-size: 12px; font-weight: 600; color: #475569; background: #f1f5f9; border-radius: 4px; padding: 4px 10px;">
				<?php printf( esc_html__( '%d Total Requests', 'custom-woo-dashboard' ), count( $user_trade_requests ) ); ?>
			</span>
		</div>

		<?php if ( ! empty( $user_trade_requests ) ) : ?>
			<div class="cwd-v2-table-wrapper">
				<table class="cwd-v2-data-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Request ID', 'custom-woo-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Date', 'custom-woo-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Type', 'custom-woo-dashboard' ); ?></th>
							<th class="th-num"><?php esc_html_e( 'Requested Limit', 'custom-woo-dashboard' ); ?></th>
							<th class="th-num"><?php esc_html_e( 'Approved Limit', 'custom-woo-dashboard' ); ?></th>
							<th style="text-align: center;"><?php esc_html_e( 'Status', 'custom-woo-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Notes / Decision', 'custom-woo-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $user_trade_requests as $req ) : ?>
							<?php
							$is_increase = ( (int) $req->forminator_form_id === 0 );
							$type_label  = $is_increase ? __( 'Credit Limit Increase', 'custom-woo-dashboard' ) : __( 'Trade Account Application', 'custom-woo-dashboard' );
							$badge_bg    = '#fef3c7';
							$badge_color = '#92400e';
							$badge_border = '#fde68a';
							if ( 'approved' === $req->status ) {
								$badge_bg     = '#f0fdf4';
								$badge_color  = '#166534';
								$badge_border = '#bbf7d0';
							} elseif ( 'rejected' === $req->status ) {
								$badge_bg     = '#fef2f2';
								$badge_color  = '#991b1b';
								$badge_border = '#fecaca';
							}
							?>
							<tr>
								<td style="font-weight: 600; color: #0f172a; white-space: nowrap;">
									#<?php echo esc_html( $req->id ); ?>
								</td>
								<td style="color: #64748b; white-space: nowrap;">
									<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $req->created_at ) ) ); ?>
								</td>
								<td style="color: #334155; font-weight: 500; white-space: nowrap;">
									<?php echo esc_html( $type_label ); ?>
								</td>
								<td class="td-num" style="font-weight: 600; color: #0f172a; white-space: nowrap;">
									<?php echo wp_kses_post( wc_price( $req->requested_limit ) ); ?>
								</td>
								<td class="td-num" style="font-weight: 600; color: #0f172a; white-space: nowrap;">
									<?php echo ( 'approved' === $req->status && $req->approved_limit !== null ) ? wp_kses_post( wc_price( $req->approved_limit ) ) : '&ndash;'; ?>
								</td>
								<td style="text-align: center; white-space: nowrap;">
									<span class="cwd-v2-status-pill" style="background: <?php echo esc_attr( $badge_bg ); ?>; color: <?php echo esc_attr( $badge_color ); ?>; border: 1px solid <?php echo esc_attr( $badge_border ); ?>;">
										<?php echo esc_html( ucfirst( $req->status ) ); ?>
									</span>
								</td>
								<td style="color: #64748b; min-width: 180px; max-width: 280px; word-break: break-word; line-height: 1.4;">
									<?php echo ! empty( $req->admin_note ) ? esc_html( $req->admin_note ) : '&ndash;'; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php else : ?>
			<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 16px; font-size: 13px; color: #64748b;">
				<?php esc_html_e( 'No previous trade credit requests submitted.', 'custom-woo-dashboard' ); ?>
			</div>
		<?php endif; ?>
	</div>

</div>
