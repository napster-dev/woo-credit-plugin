<?php

/**
 * User & Trade Registration Template
 */

if (! defined('ABSPATH')) {
	exit;
}

$login_url = wc_get_page_permalink('myaccount');
?>
<div class="cwd-v2-register-wrapper">
	<div class="cwd-register-card">
		<div class="cwd-register-header">
			<h2><?php esc_html_e('Create an Account', 'custom-woo-dashboard'); ?></h2>
			<p><?php esc_html_e('Join EWS Supplies for rapid order dispatch, trade pricing, and invoice management.', 'custom-woo-dashboard'); ?></p>
		</div>

		<?php wc_print_notices(); ?>

		<form method="post" action="" class="cwd-v2-register-form" id="cwd-register-form">
			<?php wp_nonce_field('cwd_v2_register_user_action', 'cwd_v2_register_nonce'); ?>
			<!-- Anti-spam honeypot -->
			<div style="display:none !important;" aria-hidden="true">
				<input type="text" name="cwd_v2_extra_field" tabindex="-1" autocomplete="off" />
			</div>

			<!-- Account Type Switcher -->
			<div class="cwd-form-group cwd-account-type-group">
				<label class="cwd-field-label"><?php esc_html_e('Account Type', 'custom-woo-dashboard'); ?></label>
				<div class="cwd-account-type-selector">
					<label class="cwd-type-option cwd-type-retail active">
						<input type="radio" name="cwd_account_type" value="retail" checked="checked" />
						<div class="cwd-type-content">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
							<strong><?php esc_html_e('Retail / Individual', 'custom-woo-dashboard'); ?></strong>
							<span><?php esc_html_e('Homeowners & DIY customers', 'custom-woo-dashboard'); ?></span>
						</div>
					</label>

					<label class="cwd-type-option cwd-type-trade">
						<input type="radio" name="cwd_account_type" value="trade" />
						<div class="cwd-type-content">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
							<strong><?php esc_html_e('Trade / Business', 'custom-woo-dashboard'); ?></strong>
							<span><?php esc_html_e('Electricians, contractors & builders', 'custom-woo-dashboard'); ?></span>
						</div>
					</label>
				</div>
			</div>

			<!-- Personal Info Fields Grid -->
			<div class="cwd-form-row cwd-grid-2">
				<div class="cwd-form-group">
					<label for="cwd_first_name"><?php esc_html_e('First Name', 'custom-woo-dashboard'); ?> <span class="required">*</span></label>
					<input type="text" name="cwd_first_name" id="cwd_first_name" required autocomplete="given-name" class="input-text" />
				</div>
				<div class="cwd-form-group">
					<label for="cwd_last_name"><?php esc_html_e('Last Name', 'custom-woo-dashboard'); ?> <span class="required">*</span></label>
					<input type="text" name="cwd_last_name" id="cwd_last_name" required autocomplete="family-name" class="input-text" />
				</div>
			</div>

			<div class="cwd-form-row cwd-grid-2">
				<div class="cwd-form-group">
					<label for="cwd_email"><?php esc_html_e('Email Address', 'custom-woo-dashboard'); ?> <span class="required">*</span></label>
					<input type="email" name="cwd_email" id="cwd_email" required autocomplete="email" class="input-text" />
				</div>
				<div class="cwd-form-group">
					<label for="cwd_phone"><?php esc_html_e('Phone Number', 'custom-woo-dashboard'); ?> <span class="required">*</span></label>
					<input type="tel" name="cwd_phone" id="cwd_phone" required autocomplete="tel" placeholder="e.g. 07123 456789" class="input-text" />
				</div>
			</div>

			<!-- Trade Specific Fields (Shown when Trade is active) -->
			<div class="cwd-trade-fields-container" id="cwd-trade-fields" style="display: none;">
				<div class="cwd-trade-section-banner">
					<h4><?php esc_html_e('Company & Trade Details', 'custom-woo-dashboard'); ?></h4>
					<p><?php esc_html_e('Help us verify your business for trade discounts and wholesale pricing.', 'custom-woo-dashboard'); ?></p>
				</div>

				<div class="cwd-form-row cwd-grid-2">
					<div class="cwd-form-group">
						<label for="cwd_company_name"><?php esc_html_e('Company / Trading Name', 'custom-woo-dashboard'); ?> <span class="required">*</span></label>
						<input type="text" name="cwd_company_name" id="cwd_company_name" autocomplete="organization" class="input-text" />
					</div>
					<div class="cwd-form-group">
						<label for="cwd_company_reg_number"><?php esc_html_e('Company Reg. Number (Optional)', 'custom-woo-dashboard'); ?></label>
						<input type="text" name="cwd_company_reg_number" id="cwd_company_reg_number" placeholder="e.g. 12345678" class="input-text" />
					</div>
				</div>

				<div class="cwd-form-row cwd-grid-2">
					<div class="cwd-form-group">
						<label for="cwd_vat_number"><?php esc_html_e('VAT Number (Optional)', 'custom-woo-dashboard'); ?></label>
						<input type="text" name="cwd_vat_number" id="cwd_vat_number" placeholder="GB123456789" class="input-text" />
					</div>
					<div class="cwd-form-group">
						<label for="cwd_trade_type"><?php esc_html_e('Primary Trade', 'custom-woo-dashboard'); ?></label>
						<select name="cwd_trade_type" id="cwd_trade_type" class="cwd-select">
							<option value="electrical_contractor"><?php esc_html_e('Electrical Contractor', 'custom-woo-dashboard'); ?></option>
							<option value="builder_developer"><?php esc_html_e('Builder / Developer', 'custom-woo-dashboard'); ?></option>
							<option value="facilities_management"><?php esc_html_e('Facilities Management', 'custom-woo-dashboard'); ?></option>
							<option value="wholesaler_retailer"><?php esc_html_e('Wholesaler / Reseller', 'custom-woo-dashboard'); ?></option>
							<option value="other"><?php esc_html_e('Other Trade / Commercial', 'custom-woo-dashboard'); ?></option>
						</select>
					</div>
				</div>

				<!-- Trade Credit Application Opt-in -->
				<div class="cwd-trade-credit-box">
					<label class="cwd-checkbox-label">
						<input type="checkbox" name="cwd_apply_for_credit" id="cwd_apply_for_credit" value="1" />
						<div>
							<strong><?php esc_html_e('Apply for a 30-Day Trade Credit Account', 'custom-woo-dashboard'); ?></strong>
							<span><?php esc_html_e('Get agreed credit limits and 30-day End of Month settlement terms on approval.', 'custom-woo-dashboard'); ?></span>
						</div>
					</label>

					<div class="cwd-credit-limit-wrapper" id="cwd-credit-limit-row" style="display: none; margin-top: 12px;">
						<label for="cwd_requested_limit"><?php esc_html_e('Requested Credit Limit (£)', 'custom-woo-dashboard'); ?></label>
						<input type="number" step="100" min="500" max="100000" name="cwd_requested_limit" id="cwd_requested_limit" value="1000" class="input-text" style="max-width: 200px;" />
					</div>
				</div>
			</div>

			<!-- Password Fields Grid -->
			<div class="cwd-form-row cwd-grid-2">
				<div class="cwd-form-group">
					<label for="cwd_password"><?php esc_html_e('Password', 'custom-woo-dashboard'); ?> <span class="required">*</span></label>
					<div class="cwd-password-wrapper">
						<input type="password" name="cwd_password" id="cwd_password" required minlength="8" autocomplete="new-password" class="input-text" />
						<button type="button" class="cwd-toggle-pw" aria-label="Toggle password visibility">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
						</button>
					</div>
					<small class="cwd-field-hint"><?php esc_html_e('At least 8 characters long.', 'custom-woo-dashboard'); ?></small>
				</div>

				<div class="cwd-form-group">
					<label for="cwd_confirm_password"><?php esc_html_e('Confirm Password', 'custom-woo-dashboard'); ?> <span class="required">*</span></label>
					<div class="cwd-password-wrapper">
						<input type="password" name="cwd_confirm_password" id="cwd_confirm_password" required minlength="8" autocomplete="new-password" class="input-text" />
					</div>
				</div>
			</div>

			<!-- Submit Button -->
			<div class="cwd-form-submit-row">
				<button type="submit" name="cwd_v2_register_user" id="cwd-btn-register" class="cwd-v2-button cwd-v2-button-primary">
					<?php esc_html_e('Create Account', 'custom-woo-dashboard'); ?>
				</button>
			</div>
		</form>

		<div class="cwd-register-footer">
			<p><?php esc_html_e('Already have an account?', 'custom-woo-dashboard'); ?> <a href="<?php echo esc_url($login_url); ?>"><?php esc_html_e('Log in here &rarr;', 'custom-woo-dashboard'); ?></a></p>
		</div>
	</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
	var retailOpt = document.querySelector('.cwd-type-retail');
	var tradeOpt = document.querySelector('.cwd-type-trade');
	var tradeContainer = document.getElementById('cwd-trade-fields');
	var creditCheck = document.getElementById('cwd_apply_for_credit');
	var creditRow = document.getElementById('cwd-credit-limit-row');
	var compInput = document.getElementById('cwd_company_name');

	function updateAccountType(type) {
		if (type === 'trade') {
			tradeContainer.style.display = 'block';
			tradeOpt.classList.add('active');
			retailOpt.classList.remove('active');
			if (compInput) compInput.setAttribute('required', 'required');
		} else {
			tradeContainer.style.display = 'none';
			retailOpt.classList.add('active');
			tradeOpt.classList.remove('active');
			if (compInput) compInput.removeAttribute('required');
		}
	}

	var radios = document.querySelectorAll('input[name="cwd_account_type"]');
	radios.forEach(function(radio) {
		radio.addEventListener('change', function() {
			updateAccountType(this.value);
		});
	});

	if (creditCheck && creditRow) {
		creditCheck.addEventListener('change', function() {
			creditRow.style.display = this.checked ? 'block' : 'none';
		});
	}

	// Toggle password visibility
	var pwToggles = document.querySelectorAll('.cwd-toggle-pw');
	pwToggles.forEach(function(btn) {
		btn.addEventListener('click', function() {
			var wrapper = this.closest('.cwd-password-wrapper');
			var input = wrapper.querySelector('input');
			if (input.type === 'password') {
				input.type = 'text';
			} else {
				input.type = 'password';
			}
		});
	});
});
</script>
