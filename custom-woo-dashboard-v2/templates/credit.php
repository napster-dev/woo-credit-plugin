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
.cwd-v2-credit-dashboard input::placeholder,
.cwd-v2-credit-dashboard textarea::placeholder {
    color: #94a3b8 !important;
    opacity: 1 !important;
}
.cwd-v2-credit-dashboard input::-webkit-input-placeholder,
.cwd-v2-credit-dashboard textarea::-webkit-input-placeholder {
    color: #94a3b8 !important;
}
.cwd-v2-credit-dashboard input::-moz-placeholder,
.cwd-v2-credit-dashboard textarea::-moz-placeholder {
    color: #94a3b8 !important;
    opacity: 1 !important;
}
.cwd-v2-credit-dashboard input:-ms-input-placeholder,
.cwd-v2-credit-dashboard textarea:-ms-input-placeholder {
    color: #94a3b8 !important;
}

/* Balanced 2-Column Form Layout with Perfect Horizontal Baseline Alignment */
.cwd-v2-form-grid {
    display: grid !important;
    grid-template-columns: repeat(2, 1fr) !important;
    gap: 16px 24px !important;
    align-items: end !important;
}
@media (max-width: 768px) {
    .cwd-v2-form-grid {
        grid-template-columns: 1fr !important;
        gap: 14px !important;
    }
}
.cwd-v2-field {
    display: flex !important;
    flex-direction: column !important;
    justify-content: flex-end !important;
    width: 100% !important;
    margin: 0 !important;
}
.cwd-v2-field.cwd-span-full {
    grid-column: 1 / -1 !important;
}
.cwd-v2-field label {
    display: flex !important;
    align-items: baseline !important;
    justify-content: space-between !important;
    flex-wrap: wrap !important;
    gap: 4px !important;
    font-size: 12.5px !important;
    font-weight: 600 !important;
    color: #334155 !important;
    margin: 0 0 6px 0 !important;
    line-height: 1.3 !important;
}
.cwd-v2-field label .cwd-hint {
    font-size: 11px !important;
    color: #64748b !important;
    font-weight: 400 !important;
    margin-left: 4px !important;
}
.cwd-v2-field input[type="text"],
.cwd-v2-field input[type="email"],
.cwd-v2-field input[type="tel"],
.cwd-v2-field input[type="number"],
.cwd-v2-field select {
    width: 100% !important;
    height: 42px !important;
    min-height: 42px !important;
    max-height: 42px !important;
    line-height: 40px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    padding: 0 12px !important;
    font-size: 13.5px !important;
    box-sizing: border-box !important;
    background: #ffffff !important;
    margin: 0 !important;
    transition: border-color 0.15s ease, box-shadow 0.15s ease !important;
}
.cwd-v2-field input:focus,
.cwd-v2-field select:focus,
.cwd-v2-field textarea:focus {
    border-color: #0284c7 !important;
    outline: none !important;
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15) !important;
}
.cwd-v2-field textarea {
    width: 100% !important;
    min-height: 64px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    padding: 8px 12px !important;
    font-size: 13px !important;
    box-sizing: border-box !important;
    background: #ffffff !important;
    margin: 0 !important;
    resize: vertical !important;
    transition: border-color 0.15s ease, box-shadow 0.15s ease !important;
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

	<!-- Action Cards: Pay Off Balance & Request Increase / Application -->
	<?php if ( $credit_limit > 0 ) : ?>
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

			<!-- Card 2: Request Credit Limit Increase -->
			<div class="cwd-v2-action-card">
	<?php else : ?>
		<?php if ( $credit_balance > 0 ) : ?>
			<!-- Settle Outstanding Balance Card (Full Width) -->
			<div class="cwd-v2-action-card" style="margin-bottom: 24px; border-left: 4px solid #dc2626;">
				<div class="cwd-v2-action-header">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<rect x="2" y="5" width="20" height="14" rx="2"></rect>
						<line x1="2" y1="10" x2="22" y2="10"></line>
					</svg>
					<h3 style="color: #dc2626;"><?php esc_html_e( 'Settle Outstanding Balance', 'custom-woo-dashboard' ); ?></h3>
				</div>
				<p class="cwd-v2-action-desc" style="margin-bottom: 16px;">
					<?php esc_html_e( 'You have an outstanding balance due on your trade account. Settle your balance via debit or credit card below.', 'custom-woo-dashboard' ); ?>
				</p>
				<form method="post" action="">
					<?php wp_nonce_field( 'cwd_v2_pay_credit_action', 'cwd_v2_pay_credit_nonce' ); ?>
					<div style="margin-bottom: 16px; max-width: 360px;">
						<label for="cwd_v2_pay_amount_solo" style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
							<?php esc_html_e( 'Amount to Pay', 'custom-woo-dashboard' ); ?> (<?php echo esc_html( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ); ?>)
						</label>
						<input type="number" step="0.01" min="0.01" max="<?php echo esc_attr( $credit_balance ); ?>" name="cwd_v2_pay_amount" id="cwd_v2_pay_amount_solo" value="<?php echo esc_attr( $credit_balance ); ?>" required style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; font-size: 14px; box-sizing: border-box; background: #ffffff;" />
					</div>
					<button type="submit" name="cwd_v2_pay_credit_balance" class="cwd-v2-btn-primary">
						<?php esc_html_e( 'Proceed to Settlement', 'custom-woo-dashboard' ); ?>
					</button>
				</form>
			</div>
		<?php endif; ?>

		<!-- Application Card (Full Width) -->
		<div class="cwd-v2-action-card" style="margin-bottom: 28px;">
	<?php endif; ?>
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
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
							<path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
						</svg>
						<h3 style="font-size: 18px; font-weight: 700; color: #0f172a;"><?php esc_html_e( 'Apply for Official Trade Credit Facility', 'custom-woo-dashboard' ); ?></h3>
					</div>
					<p class="cwd-v2-action-desc" style="font-size: 13.5px; color: #475569; margin-bottom: 20px;">
						<?php esc_html_e( 'Apply for an EWS business trade credit facility with Net 30 trade terms. Complete the required company and trade details below. Your application will be reviewed by our trade finance department.', 'custom-woo-dashboard' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<div>
				<?php if ( $pending_credit_request ) : ?>
					<!-- Pending Request Box -->
					<div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 18px 20px;">
						<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
							<strong style="color: #92400e; font-size: 15px;">
								<?php echo ( (int) $pending_credit_request->forminator_form_id === 0 && $credit_limit > 0 ) ? esc_html__( 'Credit Limit Request Under Review', 'custom-woo-dashboard' ) : esc_html__( 'Trade Application Under Review', 'custom-woo-dashboard' ); ?>
							</strong>
						</div>
						<p style="font-size: 13.5px; color: #78350f; margin: 0 0 14px 0; line-height: 1.5;">
							<?php printf( esc_html__( 'You submitted a trade credit application requesting a %s facility on %s. Our trade finance team is actively reviewing your application.', 'custom-woo-dashboard' ), '<strong>' . wp_kses_post( wc_price( $pending_credit_request->requested_limit ) ) . '</strong>', esc_html( date_i18n( get_option( 'date_format' ), strtotime( $pending_credit_request->created_at ) ) ) ); ?>
						</p>

						<!-- Permanent Record: Complete Submitted Application Details -->
						<?php
						$sub_data = json_decode( (string) $pending_credit_request->form_data, true ) ?: array();
						if ( ! empty( $sub_data ) ) :
						?>
							<div style="background: #ffffff; border: 1px solid #fcd34d; border-radius: 6px; padding: 16px; margin-top: 10px;">
								<h4 style="margin: 0 0 12px 0; font-size: 13.5px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
									<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
									<?php esc_html_e( 'Your Submitted Application Details (Permanent Record)', 'custom-woo-dashboard' ); ?>
								</h4>
								<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 10px 18px; font-size: 12.5px; color: #334155;">
									<div><span style="color: #64748b; font-weight: 500;"><?php esc_html_e( 'Company Name:', 'custom-woo-dashboard' ); ?></span> <strong style="color: #0f172a;"><?php echo esc_html( $sub_data['company_name'] ?? ( $pending_credit_request->company_name ?: '--' ) ); ?></strong></div>
									<div><span style="color: #64748b; font-weight: 500;"><?php esc_html_e( 'Company Reg No:', 'custom-woo-dashboard' ); ?></span> <strong style="color: #0f172a;"><?php echo esc_html( $sub_data['company_reg_number'] ?? '--' ); ?></strong></div>
									<div><span style="color: #64748b; font-weight: 500;"><?php esc_html_e( 'VAT Reg No:', 'custom-woo-dashboard' ); ?></span> <strong style="color: #0f172a;"><?php echo esc_html( ! empty( $sub_data['vat_number'] ) ? $sub_data['vat_number'] : 'N/A' ); ?></strong></div>
									<div><span style="color: #64748b; font-weight: 500;"><?php esc_html_e( 'Parent Company:', 'custom-woo-dashboard' ); ?></span> <strong style="color: #0f172a;"><?php echo esc_html( ! empty( $sub_data['parent_company'] ) ? $sub_data['parent_company'] : 'N/A' ); ?></strong></div>
									<div><span style="color: #64748b; font-weight: 500;"><?php esc_html_e( 'Business Sector:', 'custom-woo-dashboard' ); ?></span> <strong style="color: #0f172a;"><?php echo esc_html( $sub_data['business_sector'] ?? '--' ); ?></strong></div>
									<div><span style="color: #64748b; font-weight: 500;"><?php esc_html_e( 'Trading Duration:', 'custom-woo-dashboard' ); ?></span> <strong style="color: #0f172a;"><?php echo esc_html( $sub_data['trading_duration'] ?? '--' ); ?></strong></div>
									<div><span style="color: #64748b; font-weight: 500;"><?php esc_html_e( 'Requested Facility:', 'custom-woo-dashboard' ); ?></span> <strong style="color: #0f172a;"><?php echo wp_kses_post( wc_price( $pending_credit_request->requested_limit ) ); ?></strong></div>
									<div><span style="color: #64748b; font-weight: 500;"><?php esc_html_e( 'Est. Monthly Spend:', 'custom-woo-dashboard' ); ?></span> <strong style="color: #0f172a;"><?php echo is_numeric( $sub_data['estimated_monthly_spend'] ?? null ) ? wp_kses_post( wc_price( $sub_data['estimated_monthly_spend'] ) ) : esc_html( $sub_data['estimated_monthly_spend'] ?? '--' ); ?></strong></div>
									<div style="grid-column: 1 / -1;"><span style="color: #64748b; font-weight: 500;"><?php esc_html_e( 'Registered Address:', 'custom-woo-dashboard' ); ?></span> <strong style="color: #0f172a;"><?php echo esc_html( trim( ( $sub_data['street_address'] ?? '' ) . ', ' . ( $sub_data['city'] ?? '' ) . ', ' . ( $sub_data['county'] ?? '' ) . ' ' . ( $sub_data['postcode'] ?? '' ), ', ' ) ); ?></strong></div>
									<div><span style="color: #64748b; font-weight: 500;"><?php esc_html_e( 'Contact Phone:', 'custom-woo-dashboard' ); ?></span> <strong style="color: #0f172a;"><?php echo esc_html( $sub_data['phone'] ?? ( $pending_credit_request->phone ?: '--' ) ); ?></strong></div>
									<div><span style="color: #64748b; font-weight: 500;"><?php esc_html_e( 'Contact Email:', 'custom-woo-dashboard' ); ?></span> <strong style="color: #0f172a;"><?php echo esc_html( $sub_data['applicant_email'] ?? ( $pending_credit_request->applicant_email ?: '--' ) ); ?></strong></div>
									<div style="grid-column: 1 / -1; border-top: 1px dashed #e2e8f0; padding-top: 10px; margin-top: 4px;">
										<span style="color: #64748b; font-weight: 500;"><?php esc_html_e( 'Trade Reference 1:', 'custom-woo-dashboard' ); ?></span>
										<strong style="color: #0f172a;"><?php echo esc_html( $sub_data['trade_ref_name'] ?? '--' ); ?></strong> &bull;
										<span><?php echo esc_html( $sub_data['trade_ref_tel'] ?? '--' ); ?></span> &bull;
										<span><?php echo esc_html( $sub_data['trade_ref_address'] ?? '--' ); ?></span>
									</div>
									<?php if ( ! empty( $sub_data['trade_ref_2_name'] ) || ! empty( $sub_data['trade_ref_2_tel'] ) ) : ?>
										<div style="grid-column: 1 / -1; border-top: 1px dashed #e2e8f0; padding-top: 8px;">
											<span style="color: #64748b; font-weight: 500;"><?php esc_html_e( 'Trade Reference 2 (optional):', 'custom-woo-dashboard' ); ?></span>
											<strong style="color: #0f172a;"><?php echo esc_html( $sub_data['trade_ref_2_name'] ?? '--' ); ?></strong> &bull;
											<span><?php echo esc_html( $sub_data['trade_ref_2_tel'] ?? '--' ); ?></span> &bull;
											<span><?php echo esc_html( $sub_data['trade_ref_2_address'] ?? '--' ); ?></span>
										</div>
									<?php endif; ?>
									<?php if ( ! empty( $sub_data['trade_ref_3_name'] ) || ! empty( $sub_data['trade_ref_3_tel'] ) ) : ?>
										<div style="grid-column: 1 / -1; border-top: 1px dashed #e2e8f0; padding-top: 8px;">
											<span style="color: #64748b; font-weight: 500;"><?php esc_html_e( 'Trade Reference 3 (optional):', 'custom-woo-dashboard' ); ?></span>
											<strong style="color: #0f172a;"><?php echo esc_html( $sub_data['trade_ref_3_name'] ?? '--' ); ?></strong> &bull;
											<span><?php echo esc_html( $sub_data['trade_ref_3_tel'] ?? '--' ); ?></span> &bull;
											<span><?php echo esc_html( $sub_data['trade_ref_3_address'] ?? '--' ); ?></span>
										</div>
									<?php endif; ?>
									<?php
									$cust_sig_src = ! empty( $sub_data['signature_url'] ) ? $sub_data['signature_url'] : ( ! empty( $sub_data['signature_data'] ) ? $sub_data['signature_data'] : '' );
									if ( empty( $cust_sig_src ) ) {
										$cust_sig_src = get_user_meta( $user_id, '_trade_signature_url', true );
									}
									if ( ! empty( $cust_sig_src ) && class_exists( 'CWD_V2_Credit_Logic' ) ) {
										$cust_sig_src = CWD_V2_Credit_Logic::get_secure_signature_url( $cust_sig_src, $user_id );
									}
									if ( ! empty( $cust_sig_src ) ) :
									?>
										<div style="grid-column: 1 / -1; border-top: 1px dashed #e2e8f0; padding-top: 10px; margin-top: 4px;">
											<span style="color: #64748b; font-weight: 500; display: block; margin-bottom: 6px;"><?php esc_html_e( 'Authorised Signature on File:', 'custom-woo-dashboard' ); ?></span>
											<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 12px; display: inline-block; max-width: 280px;">
												<img src="<?php echo esc_url( $cust_sig_src ); ?>" alt="<?php esc_attr_e( 'Digital Signature', 'custom-woo-dashboard' ); ?>" style="max-height: 60px; max-width: 100%; display: block;" />
											</div>
										</div>
									<?php endif; ?>
								</div>
							</div>
						<?php endif; ?>
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
						<!-- Complete Native Trade Credit Application Form -->
						<?php
						$pre_company   = get_user_meta( $user_id, 'billing_company', true );
						$pre_reg_num   = get_user_meta( $user_id, '_company_reg_number', true );
						$pre_vat_num   = get_user_meta( $user_id, '_vat_number', true );
						$pre_parent_co = get_user_meta( $user_id, '_parent_company', true );
						$pre_sector    = get_user_meta( $user_id, '_business_sector', true );
						$pre_duration  = get_user_meta( $user_id, '_trading_duration', true );
						$pre_spend     = get_user_meta( $user_id, '_estimated_monthly_spend', true );
						$pre_limit     = get_user_meta( $user_id, '_requested_credit_limit', true );
						$pre_phone     = get_user_meta( $user_id, 'billing_phone', true );
						$pre_email     = $current_user->user_email;
						$pre_street    = get_user_meta( $user_id, 'billing_address_1', true );
						$pre_city      = get_user_meta( $user_id, 'billing_city', true );
						$pre_county    = get_user_meta( $user_id, 'billing_state', true );
						$pre_postcode  = get_user_meta( $user_id, 'billing_postcode', true );
						$pre_ref_name    = get_user_meta( $user_id, '_trade_ref_name', true );
						$pre_ref_addr    = get_user_meta( $user_id, '_trade_ref_address', true );
						$pre_ref_tel     = get_user_meta( $user_id, '_trade_ref_tel', true );
						$pre_ref_2_name  = get_user_meta( $user_id, '_trade_ref_2_name', true );
						$pre_ref_2_addr  = get_user_meta( $user_id, '_trade_ref_2_address', true );
						$pre_ref_2_tel   = get_user_meta( $user_id, '_trade_ref_2_tel', true );
						$pre_ref_3_name  = get_user_meta( $user_id, '_trade_ref_3_name', true );
						$pre_ref_3_addr  = get_user_meta( $user_id, '_trade_ref_3_address', true );
						$pre_ref_3_tel   = get_user_meta( $user_id, '_trade_ref_3_tel', true );
						$pre_sig_name    = get_user_meta( $user_id, '_trade_signatory_name', true ) ?: ($current_user->display_name ?: trim($current_user->first_name . ' ' . $current_user->last_name));
						$pre_sig_title   = get_user_meta( $user_id, '_trade_signatory_title', true );
						?>
						<form method="post" action="" id="cwd_v2_trade_application_form">
							<?php wp_nonce_field( 'cwd_v2_apply_trade_action', 'cwd_v2_apply_trade_nonce' ); ?>
							<input type="hidden" name="cwd_v2_submit_trade_app" value="1" />

							<!-- Group 1: Company & Business Details -->
							<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px 20px; margin-bottom: 20px;">
								<h4 style="margin: 0 0 16px 0; font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path><path d="M19 21V11l-6-4"></path><path d="M9 9h1"></path><path d="M9 13h1"></path><path d="M9 17h1"></path></svg>
									<?php esc_html_e( '1. Company & Business Information', 'custom-woo-dashboard' ); ?>
								</h4>
								<div class="cwd-v2-form-grid">
									<div class="cwd-v2-field">
										<label for="cwd_v2_company_name">
											<span><?php esc_html_e( 'Company Name', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444;">*</span></span>
										</label>
										<input type="text" name="cwd_v2_company_name" id="cwd_v2_company_name" value="<?php echo esc_attr( $pre_company ); ?>" required placeholder="<?php esc_attr_e( 'Registered Company Name', 'custom-woo-dashboard' ); ?>" />
									</div>

									<div class="cwd-v2-field">
										<label for="cwd_v2_company_reg_number">
											<span><?php esc_html_e( 'Company Reg Number', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444;">*</span></span>
											<span class="cwd-hint"><?php esc_html_e( '(Companies House 8 chars)', 'custom-woo-dashboard' ); ?></span>
										</label>
										<input type="text" name="cwd_v2_company_reg_number" id="cwd_v2_company_reg_number" value="<?php echo esc_attr( $pre_reg_num ); ?>" required maxlength="8" placeholder="<?php esc_attr_e( 'e.g. 12345678 or SC123456', 'custom-woo-dashboard' ); ?>" style="text-transform: uppercase;" />
									</div>

									<div class="cwd-v2-field">
										<label for="cwd_v2_vat_number">
											<span><?php esc_html_e( 'VAT Reg Number', 'custom-woo-dashboard' ); ?></span>
											<span class="cwd-hint"><?php esc_html_e( '(optional - HMRC standard)', 'custom-woo-dashboard' ); ?></span>
										</label>
										<input type="text" name="cwd_v2_vat_number" id="cwd_v2_vat_number" value="<?php echo esc_attr( $pre_vat_num ); ?>" placeholder="<?php esc_attr_e( 'e.g. GB123456789', 'custom-woo-dashboard' ); ?>" style="text-transform: uppercase;" />
									</div>

									<div class="cwd-v2-field">
										<label for="cwd_v2_parent_company">
											<span><?php esc_html_e( 'Parent Company Name', 'custom-woo-dashboard' ); ?></span>
											<span class="cwd-hint"><?php esc_html_e( '(optional)', 'custom-woo-dashboard' ); ?></span>
										</label>
										<input type="text" name="cwd_v2_parent_company" id="cwd_v2_parent_company" value="<?php echo esc_attr( $pre_parent_co ); ?>" placeholder="<?php esc_attr_e( 'Holding or Parent Company (if applicable)', 'custom-woo-dashboard' ); ?>" />
									</div>

									<div class="cwd-v2-field">
										<label for="cwd_v2_business_sector">
											<span><?php esc_html_e( 'Type of Business Sector / Industry?', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444;">*</span></span>
										</label>
										<select name="cwd_v2_business_sector" id="cwd_v2_business_sector" required>
											<option value=""><?php esc_html_e( 'Select sector...', 'custom-woo-dashboard' ); ?></option>
											<?php
											$sectors = array( 'Retail', 'Wholesale', 'Manufacturing', 'Construction', 'Hospitality', 'Professional services', 'Other' );
											foreach ( $sectors as $sec ) :
											?>
												<option value="<?php echo esc_attr( $sec ); ?>" <?php selected( $pre_sector, $sec ); ?>><?php echo esc_html( $sec ); ?></option>
											<?php endforeach; ?>
										</select>
									</div>

									<div class="cwd-v2-field">
										<label for="cwd_v2_trading_duration">
											<span><?php esc_html_e( 'How long has the company been trading?', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444;">*</span></span>
										</label>
										<select name="cwd_v2_trading_duration" id="cwd_v2_trading_duration" required>
											<option value=""><?php esc_html_e( 'Select duration...', 'custom-woo-dashboard' ); ?></option>
											<?php
											$durations = array( 'Under 6 months', '6 - 12 months', '1 - 2 years', '2 - 5 years', 'Over 5 years' );
											foreach ( $durations as $dur ) :
											?>
												<option value="<?php echo esc_attr( $dur ); ?>" <?php selected( $pre_duration, $dur ); ?>><?php echo esc_html( $dur ); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>
							</div>

							<!-- Group 2: Registered Trading Address & Contact -->
							<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px 20px; margin-bottom: 20px;">
								<h4 style="margin: 0 0 16px 0; font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
									<?php esc_html_e( '2. Registered Address & Contact Information', 'custom-woo-dashboard' ); ?>
								</h4>
								<div class="cwd-v2-form-grid">
									<div class="cwd-v2-field cwd-span-full">
										<label for="cwd_v2_street_address">
											<span><?php esc_html_e( 'Street address', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444;">*</span></span>
										</label>
										<input type="text" name="cwd_v2_street_address" id="cwd_v2_street_address" value="<?php echo esc_attr( $pre_street ); ?>" required placeholder="<?php esc_attr_e( 'House/Building number and street name', 'custom-woo-dashboard' ); ?>" />
									</div>

									<div class="cwd-v2-field">
										<label for="cwd_v2_city">
											<span><?php esc_html_e( 'Town / City', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444;">*</span></span>
										</label>
										<input type="text" name="cwd_v2_city" id="cwd_v2_city" value="<?php echo esc_attr( $pre_city ); ?>" required placeholder="<?php esc_attr_e( 'e.g. London', 'custom-woo-dashboard' ); ?>" />
									</div>

									<div class="cwd-v2-field">
										<label for="cwd_v2_county">
											<span><?php esc_html_e( 'County', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444;">*</span></span>
										</label>
										<select name="cwd_v2_county" id="cwd_v2_county" required>
											<option value=""><?php esc_html_e( 'Select UK County...', 'custom-woo-dashboard' ); ?></option>
											<?php
											$uk_counties = class_exists( 'CWD_V2_Credit_Logic' ) ? CWD_V2_Credit_Logic::get_uk_counties() : array();
											foreach ( $uk_counties as $nation => $c_group ) :
											?>
												<optgroup label="<?php echo esc_attr( $nation ); ?>">
													<?php foreach ( $c_group as $c_name ) : ?>
														<option value="<?php echo esc_attr( $c_name ); ?>" <?php selected( strtolower( (string) $pre_county ), strtolower( $c_name ) ); ?>>
															<?php echo esc_html( $c_name ); ?>
														</option>
													<?php endforeach; ?>
												</optgroup>
											<?php endforeach; ?>
										</select>
									</div>

									<div class="cwd-v2-field">
										<label for="cwd_v2_postcode">
											<span><?php esc_html_e( 'Postcode', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444;">*</span></span>
											<span class="cwd-hint"><?php esc_html_e( '(UK Postcode)', 'custom-woo-dashboard' ); ?></span>
										</label>
										<input type="text" name="cwd_v2_postcode" id="cwd_v2_postcode" value="<?php echo esc_attr( $pre_postcode ); ?>" required maxlength="10" placeholder="<?php esc_attr_e( 'e.g. SW1A 1AA', 'custom-woo-dashboard' ); ?>" style="text-transform: uppercase;" />
									</div>

									<div class="cwd-v2-field">
										<label for="cwd_v2_phone">
											<span><?php esc_html_e( 'Phone', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444;">*</span></span>
											<span class="cwd-hint"><?php esc_html_e( '(Must begin with +44)', 'custom-woo-dashboard' ); ?></span>
										</label>
										<?php
										$initial_phone = $pre_phone ? ( strpos( $pre_phone, '+44' ) === 0 ? $pre_phone : '+44 ' . ltrim( $pre_phone, '0' ) ) : '+44 ';
										?>
										<input type="tel" name="cwd_v2_phone" id="cwd_v2_phone" value="<?php echo esc_attr( $initial_phone ); ?>" required maxlength="15" pattern="^\+44[\s0-9]{10,12}$" placeholder="<?php esc_attr_e( '+44 7123 456789', 'custom-woo-dashboard' ); ?>" />
									</div>

									<div class="cwd-v2-field cwd-span-full">
										<label for="cwd_v2_email">
											<span><?php esc_html_e( 'Email address', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444;">*</span></span>
										</label>
										<input type="email" name="cwd_v2_email" id="cwd_v2_email" value="<?php echo esc_attr( $pre_email ); ?>" required placeholder="<?php esc_attr_e( 'accounts@company.com', 'custom-woo-dashboard' ); ?>" />
									</div>
								</div>
							</div>

							<!-- Group 3: Credit Facility & Spending Requirements -->
							<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px 20px; margin-bottom: 20px;">
								<h4 style="margin: 0 0 16px 0; font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
									<?php esc_html_e( '3. Credit Facility & Spending Requirements', 'custom-woo-dashboard' ); ?>
								</h4>
								<div class="cwd-v2-form-grid">
									<div class="cwd-v2-field">
										<label for="cwd_v2_requested_limit">
											<span><?php esc_html_e( 'Requested Credit Limit', 'custom-woo-dashboard' ); ?> (<?php echo esc_html( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ); ?>) <span style="color: #ef4444;">*</span></span>
										</label>
										<input type="number" step="any" min="1" name="cwd_v2_requested_limit" id="cwd_v2_requested_limit" value="<?php echo esc_attr( $pre_limit ); ?>" required placeholder="<?php esc_attr_e( 'e.g. 5000', 'custom-woo-dashboard' ); ?>" style="font-weight: 700;" />
									</div>

									<div class="cwd-v2-field">
										<label for="cwd_v2_estimated_monthly_spend">
											<span><?php esc_html_e( 'Estimated monthly spend', 'custom-woo-dashboard' ); ?> (<?php echo esc_html( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ); ?>) <span style="color: #ef4444;">*</span></span>
										</label>
										<input type="number" step="any" min="1" name="cwd_v2_estimated_monthly_spend" id="cwd_v2_estimated_monthly_spend" value="<?php echo esc_attr( $pre_spend ); ?>" required placeholder="<?php esc_attr_e( 'e.g. 2500', 'custom-woo-dashboard' ); ?>" style="font-weight: 700;" />
									</div>
								</div>
							</div>

							<!-- Group 4: Trade Reference 1 (Mandatory) -->
							<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px 20px; margin-bottom: 20px;">
								<h4 style="margin: 0 0 16px 0; font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
									<?php esc_html_e( '4. Trade Reference 1', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444; font-size: 12px; font-weight: 500;">*</span>
								</h4>
								<div class="cwd-v2-form-grid">
									<div class="cwd-v2-field">
										<label for="cwd_v2_trade_ref_name">
											<span><?php esc_html_e( 'Trade Reference 1, Name', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444;">*</span></span>
										</label>
										<input type="text" name="cwd_v2_trade_ref_name" id="cwd_v2_trade_ref_name" value="<?php echo esc_attr( $pre_ref_name ); ?>" required placeholder="<?php esc_attr_e( 'Supplier company name or contact', 'custom-woo-dashboard' ); ?>" />
									</div>

									<div class="cwd-v2-field">
										<label for="cwd_v2_trade_ref_tel">
											<span><?php esc_html_e( 'Trade Reference 1, Tel', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444;">*</span></span>
											<span class="cwd-hint"><?php esc_html_e( '(Must begin with +44)', 'custom-woo-dashboard' ); ?></span>
										</label>
										<input type="tel" name="cwd_v2_trade_ref_tel" id="cwd_v2_trade_ref_tel" value="<?php echo esc_attr( $pre_ref_tel ); ?>" required maxlength="15" placeholder="<?php esc_attr_e( 'e.g. +44 7123 456789', 'custom-woo-dashboard' ); ?>" />
									</div>

									<div class="cwd-v2-field cwd-span-full">
										<label for="cwd_v2_trade_ref_address">
											<span><?php esc_html_e( 'Trade Reference 1, Address', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444;">*</span></span>
										</label>
										<textarea name="cwd_v2_trade_ref_address" id="cwd_v2_trade_ref_address" rows="2" required placeholder="<?php esc_attr_e( 'Full address of trade reference supplier', 'custom-woo-dashboard' ); ?>"><?php echo esc_textarea( $pre_ref_addr ); ?></textarea>
									</div>
								</div>
							</div>

							<!-- Group 5: Trade Reference 2 (Optional) -->
							<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px 20px; margin-bottom: 20px;">
								<h4 style="margin: 0 0 16px 0; font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
									<?php esc_html_e( '5. Trade Reference 2', 'custom-woo-dashboard' ); ?> <span style="color: #94a3b8; font-weight: 400; font-size: 12px;"><?php esc_html_e( '(optional)', 'custom-woo-dashboard' ); ?></span>
								</h4>
								<div class="cwd-v2-form-grid">
									<div class="cwd-v2-field">
										<label for="cwd_v2_trade_ref_2_name">
											<span><?php esc_html_e( 'Trade Reference 2, Name', 'custom-woo-dashboard' ); ?></span>
											<span class="cwd-hint"><?php esc_html_e( '(optional)', 'custom-woo-dashboard' ); ?></span>
										</label>
										<input type="text" name="cwd_v2_trade_ref_2_name" id="cwd_v2_trade_ref_2_name" value="<?php echo esc_attr( $pre_ref_2_name ); ?>" placeholder="<?php esc_attr_e( 'Supplier company name or contact', 'custom-woo-dashboard' ); ?>" />
									</div>

									<div class="cwd-v2-field">
										<label for="cwd_v2_trade_ref_2_tel">
											<span><?php esc_html_e( 'Trade Reference 2, Tel', 'custom-woo-dashboard' ); ?></span>
											<span class="cwd-hint"><?php esc_html_e( '(optional)', 'custom-woo-dashboard' ); ?></span>
										</label>
										<input type="tel" name="cwd_v2_trade_ref_2_tel" id="cwd_v2_trade_ref_2_tel" value="<?php echo esc_attr( $pre_ref_2_tel ); ?>" maxlength="15" placeholder="<?php esc_attr_e( 'e.g. +44 7123 456789', 'custom-woo-dashboard' ); ?>" />
									</div>

									<div class="cwd-v2-field cwd-span-full">
										<label for="cwd_v2_trade_ref_2_address">
											<span><?php esc_html_e( 'Trade Reference 2, Address', 'custom-woo-dashboard' ); ?></span>
											<span class="cwd-hint"><?php esc_html_e( '(optional)', 'custom-woo-dashboard' ); ?></span>
										</label>
										<textarea name="cwd_v2_trade_ref_2_address" id="cwd_v2_trade_ref_2_address" rows="2" placeholder="<?php esc_attr_e( 'Full address of trade reference supplier', 'custom-woo-dashboard' ); ?>"><?php echo esc_textarea( $pre_ref_2_addr ); ?></textarea>
									</div>
								</div>
							</div>

							<!-- Group 6: Trade Reference 3 (Optional) -->
							<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px 20px; margin-bottom: 24px;">
								<h4 style="margin: 0 0 16px 0; font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
									<?php esc_html_e( '6. Trade Reference 3', 'custom-woo-dashboard' ); ?> <span style="color: #94a3b8; font-weight: 400; font-size: 12px;"><?php esc_html_e( '(optional)', 'custom-woo-dashboard' ); ?></span>
								</h4>
								<div class="cwd-v2-form-grid">
									<div class="cwd-v2-field">
										<label for="cwd_v2_trade_ref_3_name">
											<span><?php esc_html_e( 'Trade Reference 3, Name', 'custom-woo-dashboard' ); ?></span>
											<span class="cwd-hint"><?php esc_html_e( '(optional)', 'custom-woo-dashboard' ); ?></span>
										</label>
										<input type="text" name="cwd_v2_trade_ref_3_name" id="cwd_v2_trade_ref_3_name" value="<?php echo esc_attr( $pre_ref_3_name ); ?>" placeholder="<?php esc_attr_e( 'Supplier company name or contact', 'custom-woo-dashboard' ); ?>" />
									</div>

									<div class="cwd-v2-field">
										<label for="cwd_v2_trade_ref_3_tel">
											<span><?php esc_html_e( 'Trade Reference 3, Tel', 'custom-woo-dashboard' ); ?></span>
											<span class="cwd-hint"><?php esc_html_e( '(optional)', 'custom-woo-dashboard' ); ?></span>
										</label>
										<input type="tel" name="cwd_v2_trade_ref_3_tel" id="cwd_v2_trade_ref_3_tel" value="<?php echo esc_attr( $pre_ref_3_tel ); ?>" maxlength="15" placeholder="<?php esc_attr_e( 'e.g. +44 7123 456789', 'custom-woo-dashboard' ); ?>" />
									</div>

									<div class="cwd-v2-field cwd-span-full">
										<label for="cwd_v2_trade_ref_3_address">
											<span><?php esc_html_e( 'Trade Reference 3, Address', 'custom-woo-dashboard' ); ?></span>
											<span class="cwd-hint"><?php esc_html_e( '(optional)', 'custom-woo-dashboard' ); ?></span>
										</label>
										<textarea name="cwd_v2_trade_ref_3_address" id="cwd_v2_trade_ref_3_address" rows="2" placeholder="<?php esc_attr_e( 'Full address of trade reference supplier', 'custom-woo-dashboard' ); ?>"><?php echo esc_textarea( $pre_ref_3_addr ); ?></textarea>
									</div>
								</div>
							</div>

							<!-- Group 7: Trade Credit Agreement & Authorised Signature -->
							<div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 22px 22px; margin-bottom: 24px;">
								<h4 style="margin: 0 0 8px 0; font-size: 15px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
									<?php esc_html_e( '7. Agreement & Authorised Declaration', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444; font-size: 13px; font-weight: 600;">*</span>
								</h4>
								<p style="margin: 0 0 16px 0; font-size: 12.5px; color: #64748b; line-height: 1.5;">
									<?php esc_html_e( 'Please review the declaration below, provide signatory details, and sign within the box. By submitting, you confirm that you are an authorised officer of the applicant company.', 'custom-woo-dashboard' ); ?>
								</p>

								<!-- Agreement Terms Box -->
								<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 16px; margin-bottom: 18px; max-height: 135px; overflow-y: auto; font-size: 12px; color: #475569; line-height: 1.6;">
									<strong style="color: #0f172a; display: block; margin-bottom: 6px; font-size: 12.5px;"><?php esc_html_e( 'Trade Credit Facility Undertaking & Authority to Verify', 'custom-woo-dashboard' ); ?></strong>
									<ul style="margin: 0; padding-left: 18px;">
										<li><?php esc_html_e( 'I/We warrant that all information provided in this application is true, accurate, and complete in all respects.', 'custom-woo-dashboard' ); ?></li>
										<li><?php esc_html_e( 'I/We authorise the company to conduct commercial credit checks with recognised UK credit reference agencies (e.g. Experian, Equifax, Creditsafe) and verify references provided.', 'custom-woo-dashboard' ); ?></li>
										<li><?php esc_html_e( 'I/We agree to strictly adhere to the agreed credit terms and settle all invoices on or before the due date as stated on each trade invoice.', 'custom-woo-dashboard' ); ?></li>
										<li><?php esc_html_e( 'I/We understand that title to all goods remains with the seller until paid for in full, and any default in payment may result in suspension or withdrawal of the credit facility.', 'custom-woo-dashboard' ); ?></li>
									</ul>
								</div>

								<!-- Mandatory Agreement Checkbox -->
								<div style="margin-bottom: 18px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 14px;">
									<label style="display: flex; align-items: flex-start; gap: 8px; font-size: 12.5px; font-weight: 600; color: #1e293b; cursor: pointer;">
										<input type="checkbox" name="cwd_v2_terms_accepted" id="cwd_v2_terms_accepted" value="1" required style="margin-top: 2px;" />
										<span><?php esc_html_e( 'I confirm that I have read and agree to the Trade Credit Terms & Conditions and warrant that I am a Director, Partner, or authorised officer.', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444;">*</span></span>
									</label>
								</div>

								<!-- Signatory Details Grid -->
								<div class="cwd-v2-form-grid" style="margin-bottom: 20px;">
									<div class="cwd-v2-field">
										<label for="cwd_v2_signatory_name">
											<span><?php esc_html_e( 'Authorised Signatory Full Name', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444;">*</span></span>
										</label>
										<input type="text" name="cwd_v2_signatory_name" id="cwd_v2_signatory_name" value="<?php echo esc_attr( $pre_sig_name ); ?>" required placeholder="<?php esc_attr_e( 'e.g. John Smith', 'custom-woo-dashboard' ); ?>" />
									</div>

									<div class="cwd-v2-field">
										<label for="cwd_v2_signatory_title">
											<span><?php esc_html_e( 'Job Title / Position', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444;">*</span></span>
										</label>
										<input type="text" name="cwd_v2_signatory_title" id="cwd_v2_signatory_title" value="<?php echo esc_attr( $pre_sig_title ); ?>" required placeholder="<?php esc_attr_e( 'e.g. Managing Director, Company Secretary', 'custom-woo-dashboard' ); ?>" />
									</div>

									<div class="cwd-v2-field cwd-span-full">
										<label>
											<span><?php esc_html_e( 'Date of Signing', 'custom-woo-dashboard' ); ?></span>
										</label>
										<input type="text" value="<?php echo esc_attr( date_i18n( get_option( 'date_format' ) ) ); ?>" readonly style="background: #f1f5f9 !important; color: #64748b !important; cursor: not-allowed;" />
									</div>
								</div>

								<!-- Interactive Signature Pad -->
								<div>
									<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
										<label style="display: block; font-size: 12.5px; font-weight: 600; color: #334155;">
											<?php esc_html_e( 'Draw Your Signature', 'custom-woo-dashboard' ); ?> <span style="color: #ef4444;">*</span>
											<span style="font-size: 11px; font-weight: 400; color: #64748b; margin-left: 6px;"><?php esc_html_e( '(Use mouse, finger, or stylus)', 'custom-woo-dashboard' ); ?></span>
										</label>
										<button type="button" id="cwd_v2_clear_signature_btn" class="button button-small" style="font-size: 11.5px; padding: 2px 10px; height: 26px; line-height: 24px; border-radius: 4px; color: #dc2626; border-color: #fca5a5; background: #ffffff; cursor: pointer;">
											<span class="dashicons dashicons-trash" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span> <?php esc_html_e( 'Clear Signature', 'custom-woo-dashboard' ); ?>
										</button>
									</div>

									<div style="position: relative; background: #ffffff; border: 2px dashed #cbd5e1; border-radius: 8px; width: 100%; box-sizing: border-box; overflow: hidden; touch-action: none;">
										<canvas id="cwd_v2_signature_canvas" style="display: block; width: 100%; height: 160px; cursor: crosshair; touch-action: none; background: #ffffff;"></canvas>
										<div style="position: absolute; bottom: 28px; left: 24px; right: 24px; border-bottom: 1px dashed #cbd5e1; pointer-events: none;"></div>
										<div style="position: absolute; bottom: 8px; left: 24px; font-size: 11px; color: #94a3b8; pointer-events: none; user-select: none;">
											✕ <?php esc_html_e( 'Sign on the line above', 'custom-woo-dashboard' ); ?>
										</div>
									</div>
									<input type="hidden" name="cwd_v2_signature_data" id="cwd_v2_signature_data" value="" />
									<p id="cwd_v2_sig_error" style="display: none; color: #dc2626; font-size: 12px; font-weight: 600; margin: 6px 0 0;"><?php esc_html_e( 'Please draw your signature in the box above before submitting.', 'custom-woo-dashboard' ); ?></p>
								</div>
							</div>

							<button type="submit" name="cwd_v2_submit_trade_app" class="cwd-v2-btn-primary" style="padding: 12px 28px; font-size: 14.5px; font-weight: 700; width: 100%; max-width: 320px;">
								<?php esc_html_e( 'Submit Trade Application', 'custom-woo-dashboard' ); ?> &rarr;
							</button>
						</form>

						<script>
						document.addEventListener('DOMContentLoaded', function() {
							function setupUKPhoneField(inputEl, isRequired) {
								if (!inputEl) return;
								inputEl.setAttribute('maxlength', '15');

								// On focus: if required and empty, auto-seed '+44 '
								inputEl.addEventListener('focus', function() {
									if (!this.value || this.value.trim() === '') {
										if (isRequired) {
											this.value = '+44 ';
										}
									}
								});

								// On blur: if only '+44' or whitespace remains, clear it
								inputEl.addEventListener('blur', function() {
									var trimmed = this.value.trim();
									if (trimmed === '+44' || trimmed === '') {
										this.value = '';
									}
								});

								// Block '+' key from being typed anywhere inside the field
								inputEl.addEventListener('keydown', function(e) {
									if (e.key === '+') {
										e.preventDefault();
										return;
									}
									// Prevent backspacing into the '+44 ' prefix
									if (e.key === 'Backspace' && this.selectionStart <= 4 && this.selectionEnd <= 4 && this.value.indexOf('+44') === 0) {
										e.preventDefault();
									}
								});

								// Format input live to +44 XXXX XXXXXX (max 10 subscriber digits)
								inputEl.addEventListener('input', function() {
									var val = this.value;
									if (!val || val.trim() === '') {
										return;
									}
									// Remove any '+' characters typed or pasted
									val = val.replace(/\+/g, '');
									// Extract all numeric digits
									var digits = val.replace(/[^0-9]/g, '');
									// If it starts with UK country code 44, strip it to isolate subscriber digits
									if (digits.indexOf('44') === 0) {
										digits = digits.substring(2);
									}
									// If domestic leading 0 is typed (e.g. 07123...), strip the leading 0
									if (digits.indexOf('0') === 0) {
										digits = digits.substring(1);
									}
									// UK subscriber numbers have max 10 digits
									digits = digits.slice(0, 10);

									if (digits.length > 4) {
										this.value = '+44 ' + digits.substring(0, 4) + ' ' + digits.substring(4);
									} else if (digits.length > 0) {
										this.value = '+44 ' + digits;
									} else {
										this.value = isRequired ? '+44 ' : '';
									}
								});

								// Format existing value on page load if present
								if (inputEl.value && inputEl.value.trim() !== '') {
									var rawVal = inputEl.value.replace(/\+/g, '');
									var digits = rawVal.replace(/[^0-9]/g, '');
									if (digits.indexOf('44') === 0) digits = digits.substring(2);
									if (digits.indexOf('0') === 0) digits = digits.substring(1);
									digits = digits.slice(0, 10);
									if (digits.length > 4) {
										inputEl.value = '+44 ' + digits.substring(0, 4) + ' ' + digits.substring(4);
									} else if (digits.length > 0) {
										inputEl.value = '+44 ' + digits;
									}
								}
							}

							// 1. Applicant Contact Phone (Mandatory)
							setupUKPhoneField(document.getElementById('cwd_v2_phone'), true);

							// 2. Trade Reference 1 Telephone (Mandatory)
							setupUKPhoneField(document.getElementById('cwd_v2_trade_ref_tel'), true);

							// 3. Trade Reference 2 Telephone (Optional)
							setupUKPhoneField(document.getElementById('cwd_v2_trade_ref_2_tel'), false);

							// 4. Trade Reference 3 Telephone (Optional)
							setupUKPhoneField(document.getElementById('cwd_v2_trade_ref_3_tel'), false);

							// 5. Signature Pad Drawing & Touch Handler
							var sigCanvas = document.getElementById('cwd_v2_signature_canvas');
							var sigInput  = document.getElementById('cwd_v2_signature_data');
							var clearBtn  = document.getElementById('cwd_v2_clear_signature_btn');
							var sigError  = document.getElementById('cwd_v2_sig_error');

							if (sigCanvas) {
								var ctx = sigCanvas.getContext('2d');
								var isDrawing = false;
								var hasDrawn = false;

								function resizeCanvas() {
									var rect = sigCanvas.getBoundingClientRect();
									var dpr = window.devicePixelRatio || 1;
									// Save current drawing if any
									var prevData = (hasDrawn && sigInput && sigInput.value) ? sigInput.value : null;
									sigCanvas.width = rect.width * dpr;
									sigCanvas.height = 160 * dpr;
									ctx.scale(dpr, dpr);
									ctx.strokeStyle = '#0f172a';
									ctx.lineWidth = 2.4;
									ctx.lineCap = 'round';
									ctx.lineJoin = 'round';

									if (prevData) {
										var img = new Image();
										img.onload = function() {
											ctx.drawImage(img, 0, 0, rect.width, 160);
										};
										img.src = prevData;
									}
								}
								resizeCanvas();
								window.addEventListener('resize', resizeCanvas);

								function getPos(e) {
									var rect = sigCanvas.getBoundingClientRect();
									var clientX = e.clientX;
									var clientY = e.clientY;
									if (e.touches && e.touches.length > 0) {
										clientX = e.touches[0].clientX;
										clientY = e.touches[0].clientY;
									}
									return {
										x: clientX - rect.left,
										y: clientY - rect.top
									};
								}

								function startDraw(e) {
									if (e.type === 'touchstart') {
										e.preventDefault();
									}
									isDrawing = true;
									hasDrawn = true;
									if (sigError) sigError.style.display = 'none';
									var pos = getPos(e);
									ctx.beginPath();
									ctx.moveTo(pos.x, pos.y);
								}

								function draw(e) {
									if (!isDrawing) return;
									if (e.type === 'touchmove') {
										e.preventDefault();
									}
									var pos = getPos(e);
									ctx.lineTo(pos.x, pos.y);
									ctx.stroke();
								}

								function stopDraw(e) {
									if (!isDrawing) return;
									isDrawing = false;
									if (sigInput) {
										sigInput.value = sigCanvas.toDataURL('image/png');
									}
								}

								// Desktop Mouse
								sigCanvas.addEventListener('mousedown', startDraw);
								sigCanvas.addEventListener('mousemove', draw);
								window.addEventListener('mouseup', stopDraw);

								// Mobile & Tablet Touch
								sigCanvas.addEventListener('touchstart', startDraw, { passive: false });
								sigCanvas.addEventListener('touchmove', draw, { passive: false });
								window.addEventListener('touchend', stopDraw);

								// Clear Signature
								if (clearBtn) {
									clearBtn.addEventListener('click', function(e) {
										e.preventDefault();
										var rect = sigCanvas.getBoundingClientRect();
										ctx.clearRect(0, 0, rect.width, rect.height);
										hasDrawn = false;
										if (sigInput) sigInput.value = '';
									});
								}

								// Validate Signature on Form Submit
								var form = document.getElementById('cwd_v2_trade_application_form') || sigCanvas.closest('form');
								if (form) {
									form.addEventListener('submit', function(e) {
										if (!hasDrawn || !sigInput.value || sigInput.value.length < 50) {
											e.preventDefault();
											if (sigError) {
												sigError.style.display = 'block';
												sigError.scrollIntoView({ behavior: 'smooth', block: 'center' });
											}
											var btn = form.querySelector('button[type=submit]');
											if (btn) {
												btn.disabled = false;
												btn.style.pointerEvents = 'auto';
												btn.style.opacity = '1';
												btn.innerHTML = '<?php esc_html_e( 'Submit Trade Application', 'custom-woo-dashboard' ); ?> &rarr;';
											}
											return false;
										}
										var btn = form.querySelector('button[type=submit]');
										if (btn) {
											btn.disabled = true;
											btn.style.pointerEvents = 'none';
											btn.style.opacity = '0.7';
											btn.textContent = '<?php esc_html_e( 'Submitting Application...', 'custom-woo-dashboard' ); ?>';
										}
									});
								}
							}
						});
						</script>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>

	<?php if ( $credit_limit > 0 ) : ?>
	</div><!-- close cwd-v2-action-cards-grid -->
	<?php endif; ?>

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
