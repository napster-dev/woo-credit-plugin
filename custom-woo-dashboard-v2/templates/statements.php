<?php
if (! defined('ABSPATH')) {
    exit;
}
$user_id = get_current_user_id();
$credit_limit = class_exists('CWD_V2_Credit_Logic') ? CWD_V2_Credit_Logic::get_user_credit_limit($user_id) : (float) get_user_meta($user_id, '_credit_limit', true);
$balance = class_exists('CWD_V2_Credit_Logic') ? CWD_V2_Credit_Logic::get_user_credit_balance($user_id) : (float) get_user_meta($user_id, '_credit_balance', true);
$available_credit = max(0, $credit_limit - $balance);
$invoices = class_exists('CWD_V2_Invoices') ? CWD_V2_Invoices::get_invoices_for_user($user_id, array('limit' => 100)) : array();
$transactions = class_exists('CWD_V2_Account_Ledger') ? CWD_V2_Account_Ledger::get_transactions_for_user($user_id, 100) : array();
$outstanding = 0;
foreach ($invoices as $invoice) {
    if (CWD_V2_Invoices::STATUS_PAID === $invoice->status || CWD_V2_Invoices::STATUS_VOID === $invoice->status) {
        continue;
    }
    $outstanding += max(0, (float) $invoice->amount_total - (float) $invoice->amount_paid);
}
?>
<div class="cwd-v2-statements-dashboard" id="cwd-v2-statement-print-area">
    <div class="cwd-v2-section-heading cwd-v2-no-print" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
        <div>
            <h3 style="margin: 0 0 4px 0; font-size: 20px; font-weight: 700; color: #0f172a;"><?php esc_html_e('Account Statement', 'custom-woo-dashboard'); ?></h3>
            <p style="margin: 0; font-size: 13px; color: #64748b;"><?php esc_html_e('Your current account position and transaction ledger.', 'custom-woo-dashboard'); ?></p>
        </div>
        <button type="button" class="cwd-v2-print-button" onclick="window.print();"><?php esc_html_e('Print / Save as PDF', 'custom-woo-dashboard'); ?></button>
    </div>
    
    <div class="cwd-v2-credit-summary-grid">
        <div class="cwd-v2-stat-card">
            <span class="cwd-v2-stat-label"><?php esc_html_e('Current Balance', 'custom-woo-dashboard'); ?></span>
            <div class="cwd-v2-stat-value <?php echo $balance > 0 ? 'cwd-val-danger' : ''; ?>"><?php echo wp_kses_post(wc_price($balance)); ?></div>
            <div class="cwd-v2-stat-desc"><?php esc_html_e('Total amount owed', 'custom-woo-dashboard'); ?></div>
        </div>
        <div class="cwd-v2-stat-card">
            <span class="cwd-v2-stat-label"><?php esc_html_e('Available Credit', 'custom-woo-dashboard'); ?></span>
            <div class="cwd-v2-stat-value cwd-val-success"><?php echo wp_kses_post(wc_price($available_credit)); ?></div>
            <div class="cwd-v2-stat-desc"><?php esc_html_e('Usable for purchases', 'custom-woo-dashboard'); ?></div>
        </div>
        <div class="cwd-v2-stat-card">
            <span class="cwd-v2-stat-label"><?php esc_html_e('Credit Limit', 'custom-woo-dashboard'); ?></span>
            <div class="cwd-v2-stat-value"><?php echo wp_kses_post(wc_price($credit_limit)); ?></div>
            <div class="cwd-v2-stat-desc"><?php esc_html_e('Approved facility', 'custom-woo-dashboard'); ?></div>
        </div>
        <div class="cwd-v2-stat-card">
            <span class="cwd-v2-stat-label"><?php esc_html_e('Outstanding Invoices', 'custom-woo-dashboard'); ?></span>
            <div class="cwd-v2-stat-value"><?php echo wp_kses_post(wc_price($outstanding)); ?></div>
            <div class="cwd-v2-stat-desc"><?php esc_html_e('Unsettled invoice amount', 'custom-woo-dashboard'); ?></div>
        </div>
    </div>

    <div class="cwd-v2-section-box">
        <div class="cwd-v2-section-top">
            <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: #0f172a;"><?php esc_html_e('Account Transactions', 'custom-woo-dashboard'); ?></h3>
        </div>
        <?php if ($transactions) : ?>
            <div class="cwd-v2-table-wrapper">
                <table class="cwd-v2-data-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'custom-woo-dashboard'); ?></th>
                            <th><?php esc_html_e('Type', 'custom-woo-dashboard'); ?></th>
                            <th><?php esc_html_e('Description', 'custom-woo-dashboard'); ?></th>
                            <th class="th-num"><?php esc_html_e('Amount', 'custom-woo-dashboard'); ?></th>
                            <th class="th-num"><?php esc_html_e('Balance', 'custom-woo-dashboard'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $transaction) : ?>
                            <tr>
                                <td style="white-space: nowrap; color: #64748b;"><?php echo esc_html(date_i18n(wc_date_format(), strtotime($transaction->created_at))); ?></td>
                                <td style="white-space: nowrap; font-weight: 600; color: #0f172a;"><?php echo esc_html(ucfirst($transaction->type)); ?></td>
                                <td style="color: #334155; line-height: 1.4;"><?php echo esc_html($transaction->note); ?></td>
                                <td class="td-num" style="white-space: nowrap; font-weight: 600;"><?php echo wp_kses_post(wc_price($transaction->amount)); ?></td>
                                <td class="td-num" style="white-space: nowrap; font-weight: 700; color: #0f172a;"><?php echo wp_kses_post(wc_price($transaction->balance_after)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 16px; font-size: 13px; color: #64748b;">
                <?php esc_html_e('No account transactions found.', 'custom-woo-dashboard'); ?>
            </div>
        <?php endif; ?>
    </div>
</div>