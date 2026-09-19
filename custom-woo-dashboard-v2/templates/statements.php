<?php
if (! defined('ABSPATH')) {
    exit;
}
$user_id = get_current_user_id();
$credit_limit = (float) get_user_meta($user_id, '_credit_limit', true);
$balance = (float) get_user_meta($user_id, '_credit_balance', true);
$invoices = CWD_V2_Invoices::get_invoices_for_user($user_id, array('limit' => 100));
$transactions = CWD_V2_Account_Ledger::get_transactions_for_user($user_id, 100);
$outstanding = 0;
foreach ($invoices as $invoice) {
    if (CWD_V2_Invoices::STATUS_PAID === $invoice->status || CWD_V2_Invoices::STATUS_VOID === $invoice->status) {
        continue;
    }
    $outstanding += max(0, (float) $invoice->amount_total - (float) $invoice->amount_paid);
}
?>
<div class="cwd-v2-statements-dashboard" id="cwd-v2-statement-print-area">
    <div class="cwd-v2-section-heading cwd-v2-no-print">
        <div>
            <h3><?php esc_html_e('Account Statement', 'custom-woo-dashboard'); ?></h3>
            <p><?php esc_html_e('Your current account position and transaction history.', 'custom-woo-dashboard'); ?></p>
        </div><button type="button" onclick="window.print();"><?php esc_html_e('Print / Save as PDF', 'custom-woo-dashboard'); ?></button>
    </div>
    <div class="cwd-v2-credit-summary">
        <div><strong><?php esc_html_e('Current Balance', 'custom-woo-dashboard'); ?></strong>
            <p><?php echo wp_kses_post(wc_price($balance)); ?></p>
        </div>
        <div><strong><?php esc_html_e('Available Credit', 'custom-woo-dashboard'); ?></strong>
            <p><?php echo wp_kses_post(wc_price(max(0, $credit_limit - $balance))); ?></p>
        </div>
        <div><strong><?php esc_html_e('Credit Limit', 'custom-woo-dashboard'); ?></strong>
            <p><?php echo wp_kses_post(wc_price($credit_limit)); ?></p>
        </div>
        <div><strong><?php esc_html_e('Outstanding Invoices', 'custom-woo-dashboard'); ?></strong>
            <p><?php echo wp_kses_post(wc_price($outstanding)); ?></p>
        </div>
    </div>
    <h4><?php esc_html_e('Account Transactions', 'custom-woo-dashboard'); ?></h4>
    <?php if ($transactions) : ?><table class="shop_table shop_table_responsive">
            <thead>
                <tr>
                    <th><?php esc_html_e('Date', 'custom-woo-dashboard'); ?></th>
                    <th><?php esc_html_e('Type', 'custom-woo-dashboard'); ?></th>
                    <th><?php esc_html_e('Description', 'custom-woo-dashboard'); ?></th>
                    <th><?php esc_html_e('Amount', 'custom-woo-dashboard'); ?></th>
                    <th><?php esc_html_e('Balance', 'custom-woo-dashboard'); ?></th>
                </tr>
            </thead>
            <tbody><?php foreach ($transactions as $transaction) : ?><tr>
                        <td><?php echo esc_html(date_i18n(wc_date_format(), strtotime($transaction->created_at))); ?></td>
                        <td><?php echo esc_html(ucfirst($transaction->type)); ?></td>
                        <td><?php echo esc_html($transaction->note); ?></td>
                        <td><?php echo wp_kses_post(wc_price($transaction->amount)); ?></td>
                        <td><?php echo wp_kses_post(wc_price($transaction->balance_after)); ?></td>
                    </tr><?php endforeach; ?></tbody>
        </table><?php else : ?><p><?php esc_html_e('No account transactions found.', 'custom-woo-dashboard'); ?></p><?php endif; ?>
</div>