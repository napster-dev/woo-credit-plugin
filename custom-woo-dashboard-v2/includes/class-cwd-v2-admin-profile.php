<?php

/**
 * Admin User Profile handler for Credit Accounts
 */

if (! defined('ABSPATH')) {
	exit;
}

class CWD_V2_Admin_Profile
{

	public static function init()
	{
		// Render fields in user edit screen
		add_action('show_user_profile', array(__CLASS__, 'render_credit_fields'), 20);
		add_action('edit_user_profile', array(__CLASS__, 'render_credit_fields'), 20);

		// Save fields on update
		add_action('personal_options_update', array(__CLASS__, 'save_credit_fields'));
		add_action('edit_user_profile_update', array(__CLASS__, 'save_credit_fields'));
	}

	/**
	 * Render Credit Account management section in WordPress user profile
	 *
	 * @param WP_User $user
	 */
	public static function render_credit_fields($user)
	{
		if (! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
			return;
		}

		$user_id          = $user->ID;
		$credit_limit     = (float) get_user_meta($user_id, '_credit_limit', true);
		$credit_balance   = (float) get_user_meta($user_id, '_credit_balance', true);
		$due_date         = (string) get_user_meta($user_id, '_credit_due_date', true);
		$account_number   = (string) get_user_meta($user_id, 'ews_account_number', true);
		$available_credit = max(0, $credit_limit - $credit_balance);

		$user_roles       = (array) $user->roles;
		$is_credit_account = in_array('credit_account', $user_roles, true);
		?>
		<div class="cwd-admin-credit-wrap" style="margin-top: 30px; margin-bottom: 30px;">
			<h2 style="display: flex; align-items: center; gap: 8px; font-size: 1.3em; font-weight: 600; color: #0f172a; margin-bottom: 6px;">
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0f172a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<rect x="2" y="5" width="20" height="14" rx="2"></rect>
					<line x1="2" y1="10" x2="22" y2="10"></line>
				</svg>
				<?php esc_html_e('Trade & Credit Account Management', 'custom-woo-dashboard'); ?>
			</h2>
			<p class="description" style="color: #64748b; margin-top: 0; margin-bottom: 16px;">
				<?php esc_html_e('Manage trade customer credit limits, outstanding balances, payment terms, and trade account numbers synced with Odoo.', 'custom-woo-dashboard'); ?>
			</p>

			<?php wp_nonce_field('cwd_v2_save_credit_admin', 'cwd_v2_credit_admin_nonce'); ?>

			<table class="form-table" role="presentation" style="border: 1px solid #e2e8f0; background: #ffffff; border-radius: 6px; padding: 12px 18px;">
				<tr>
					<th scope="row">
						<label for="cwd_is_credit_account"><?php esc_html_e('Credit Account Status', 'custom-woo-dashboard'); ?></label>
					</th>
					<td>
						<label for="cwd_is_credit_account" style="font-weight: 500; color: #0f172a;">
							<input type="checkbox" name="cwd_is_credit_account" id="cwd_is_credit_account" value="1" <?php checked($is_credit_account); ?> />
							<?php esc_html_e('Enable Credit Account for this customer (assigns role: credit_account)', 'custom-woo-dashboard'); ?>
						</label>
						<p class="description"><?php esc_html_e('Customers with this status can pay via credit at checkout up to their available limit.', 'custom-woo-dashboard'); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="cwd_credit_limit"><?php esc_html_e('Credit Limit', 'custom-woo-dashboard'); ?> (<?php echo esc_html(get_woocommerce_currency_symbol()); ?>)</label>
					</th>
					<td>
						<input type="number" step="0.01" min="0" name="cwd_credit_limit" id="cwd_credit_limit" value="<?php echo esc_attr(number_format($credit_limit, 2, '.', '')); ?>" class="regular-text" style="max-width: 200px;" />
						<p class="description"><?php esc_html_e('Maximum allowable credit balance synced with Odoo.', 'custom-woo-dashboard'); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="cwd_credit_balance"><?php esc_html_e('Current Balance / Credit Used', 'custom-woo-dashboard'); ?> (<?php echo esc_html(get_woocommerce_currency_symbol()); ?>)</label>
					</th>
					<td>
						<input type="number" step="0.01" min="0" name="cwd_credit_balance" id="cwd_credit_balance" value="<?php echo esc_attr(number_format($credit_balance, 2, '.', '')); ?>" class="regular-text" style="max-width: 200px;" />
						<p class="description"><?php esc_html_e('Current outstanding balance owed by customer. Increases on credit orders, decreases on settlement.', 'custom-woo-dashboard'); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<?php esc_html_e('Available Credit', 'custom-woo-dashboard'); ?>
					</th>
					<td>
						<span style="display: inline-block; padding: 4px 10px; font-weight: 600; font-size: 13px; color: #166534; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px;">
							<?php echo wp_kses_post(wc_price($available_credit)); ?>
						</span>
						<p class="description"><?php esc_html_e('Calculated automatically as (Credit Limit - Current Balance).', 'custom-woo-dashboard'); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="cwd_credit_due_date"><?php esc_html_e('Next Payment Due Date', 'custom-woo-dashboard'); ?></label>
					</th>
					<td>
						<input type="date" name="cwd_credit_due_date" id="cwd_credit_due_date" value="<?php echo esc_attr($due_date); ?>" class="regular-text" style="max-width: 200px;" />
						<p class="description"><?php esc_html_e('Due date for outstanding credit balance settlement.', 'custom-woo-dashboard'); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="cwd_ews_account_number"><?php esc_html_e('EWS Trade Account Number', 'custom-woo-dashboard'); ?></label>
					</th>
					<td>
						<input type="text" name="cwd_ews_account_number" id="cwd_ews_account_number" value="<?php echo esc_attr($account_number); ?>" class="regular-text" style="max-width: 250px;" />
						<p class="description"><?php esc_html_e('Odoo trade partner account reference (e.g. EWS-T0001).', 'custom-woo-dashboard'); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Save credit fields on user profile submission
	 *
	 * @param int $user_id
	 */
	public static function save_credit_fields($user_id)
	{
		if (! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
			return;
		}

		if (! isset($_POST['cwd_v2_credit_admin_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cwd_v2_credit_admin_nonce'])), 'cwd_v2_save_credit_admin')) {
			return;
		}

		// Save Credit Limit
		if (isset($_POST['cwd_credit_limit'])) {
			$limit = round((float) wc_format_decimal(wp_unslash($_POST['cwd_credit_limit'])), 2);
			if ($limit < 0) {
				$limit = 0.0;
			}
			update_user_meta($user_id, '_credit_limit', $limit);
		}

		// Save Credit Balance
		if (isset($_POST['cwd_credit_balance'])) {
			$balance = round((float) wc_format_decimal(wp_unslash($_POST['cwd_credit_balance'])), 2);
			if ($balance < 0) {
				$balance = 0.0;
			}
			update_user_meta($user_id, '_credit_balance', $balance);
		}

		// Save Due Date
		if (isset($_POST['cwd_credit_due_date'])) {
			$due_date = sanitize_text_field(wp_unslash($_POST['cwd_credit_due_date']));
			update_user_meta($user_id, '_credit_due_date', $due_date);
		}

		// Save EWS Account Number
		if (isset($_POST['cwd_ews_account_number'])) {
			$acc_num = sanitize_text_field(wp_unslash($_POST['cwd_ews_account_number']));
			update_user_meta($user_id, 'ews_account_number', $acc_num);
		}

		// Handle Role Toggle
		$user = get_userdata($user_id);
		if ($user) {
			$wants_credit_role = ! empty($_POST['cwd_is_credit_account']);
			$has_credit_role   = in_array('credit_account', (array) $user->roles, true);

			if ($wants_credit_role && ! $has_credit_role) {
				$user->add_role('credit_account');
			} elseif (! $wants_credit_role && $has_credit_role) {
				// Don't remove if administrator to prevent lockout
				if (! in_array('administrator', (array) $user->roles, true)) {
					$user->remove_role('credit_account');
				}
			}
		}
	}
}
