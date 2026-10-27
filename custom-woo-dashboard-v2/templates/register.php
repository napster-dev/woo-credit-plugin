<?php

/**
 * Clean & Enterprise User Registration Template
 * Scoped styling guarantees flawless formatting across all themes and viewports.
 */

if (! defined('ABSPATH')) {
	exit;
}

$login_url = wc_get_page_permalink('myaccount');
?>
<style id="cwd-v2-register-critical-css">
.cwd-v2-register-wrapper {
	width: 100% !important;
	max-width: 620px !important;
	margin: 30px auto !important;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif !important;
	box-sizing: border-box !important;
}

.cwd-v2-register-wrapper *, 
.cwd-v2-register-wrapper *::before, 
.cwd-v2-register-wrapper *::after {
	box-sizing: border-box !important;
}

.cwd-register-card {
	background: #ffffff !important;
	border: 1px solid #e2e8f0 !important;
	border-radius: 12px !important;
	padding: 32px 36px !important;
	box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05) !important;
}

@media (max-width: 600px) {
	.cwd-register-card {
		padding: 24px 20px !important;
	}
}

.cwd-register-header {
	text-align: center !important;
	margin-bottom: 26px !important;
}

.cwd-register-header h2 {
	font-size: 24px !important;
	font-weight: 700 !important;
	color: #0f172a !important;
	margin: 0 0 8px 0 !important;
	line-height: 1.25 !important;
	letter-spacing: -0.02em !important;
}

.cwd-register-header p {
	font-size: 14px !important;
	color: #64748b !important;
	margin: 0 !important;
	line-height: 1.5 !important;
}

.cwd-v2-register-form {
	margin: 0 !important;
}

.cwd-form-row {
	display: flex !important;
	gap: 16px !important;
	margin-bottom: 16px !important;
}

@media (max-width: 600px) {
	.cwd-form-row {
		flex-direction: column !important;
		gap: 16px !important;
	}
}

.cwd-form-group {
	flex: 1 1 50% !important;
	width: 100% !important;
	margin-bottom: 0 !important;
}

.cwd-form-group.cwd-full-width {
	flex: 1 1 100% !important;
	margin-bottom: 16px !important;
}

.cwd-form-group label {
	display: block !important;
	font-size: 13px !important;
	font-weight: 600 !important;
	color: #334155 !important;
	margin-bottom: 6px !important;
	line-height: 1.3 !important;
}

.cwd-form-group label .required {
	color: #dc2626 !important;
	font-weight: 700 !important;
}

.cwd-form-group input.input-text,
.cwd-form-group input[type="text"],
.cwd-form-group input[type="email"],
.cwd-form-group input[type="tel"],
.cwd-form-group input[type="password"] {
	width: 100% !important;
	height: 44px !important;
	border: 1px solid #cbd5e1 !important;
	border-radius: 6px !important;
	padding: 10px 14px !important;
	font-size: 14px !important;
	color: #0f172a !important;
	background: #ffffff !important;
	outline: none !important;
	box-shadow: none !important;
	transition: border-color 0.15s ease, box-shadow 0.15s ease !important;
}

.cwd-form-group input.input-text:focus,
.cwd-form-group input[type="text"]:focus,
.cwd-form-group input[type="email"]:focus,
.cwd-form-group input[type="tel"]:focus,
.cwd-form-group input[type="password"]:focus {
	border-color: #0284c7 !important;
	box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15) !important;
}

.cwd-password-wrapper {
	position: relative !important;
	width: 100% !important;
}

.cwd-password-wrapper input {
	padding-right: 44px !important;
}

.cwd-toggle-pw {
	position: absolute !important;
	right: 12px !important;
	top: 50% !important;
	transform: translateY(-50%) !important;
	background: none !important;
	border: none !important;
	color: #94a3b8 !important;
	cursor: pointer !important;
	padding: 4px !important;
	display: flex !important;
	align-items: center !important;
	justify-content: center !important;
	line-height: 1 !important;
}

.cwd-toggle-pw:hover {
	color: #475569 !important;
}

.cwd-field-hint {
	display: block !important;
	font-size: 11.5px !important;
	color: #94a3b8 !important;
	margin-top: 5px !important;
}

.cwd-trade-notice-banner {
	background: #f8fafc !important;
	border: 1px solid #e2e8f0 !important;
	border-left: 4px solid #0284c7 !important;
	border-radius: 6px !important;
	padding: 14px 16px !important;
	margin-top: 20px !important;
	margin-bottom: 22px !important;
	display: flex !important;
	align-items: flex-start !important;
	gap: 12px !important;
}

.cwd-trade-notice-banner svg {
	flex-shrink: 0 !important;
	color: #0284c7 !important;
	margin-top: 2px !important;
}

.cwd-trade-notice-banner strong {
	display: block !important;
	font-size: 13.5px !important;
	color: #0f172a !important;
	font-weight: 600 !important;
	margin-bottom: 3px !important;
}

.cwd-trade-notice-banner span {
	font-size: 12.5px !important;
	color: #64748b !important;
	line-height: 1.45 !important;
	display: block !important;
}

.cwd-form-submit-row {
	margin-top: 10px !important;
}

.cwd-btn-submit-register {
	width: 100% !important;
	height: 46px !important;
	background: #0284c7 !important;
	color: #ffffff !important;
	border: none !important;
	border-radius: 6px !important;
	font-size: 15px !important;
	font-weight: 700 !important;
	cursor: pointer !important;
	display: flex !important;
	align-items: center !important;
	justify-content: center !important;
	text-decoration: none !important;
	transition: background-color 0.15s ease, box-shadow 0.15s ease !important;
	box-shadow: 0 1px 3px rgba(2, 132, 199, 0.25) !important;
}

.cwd-btn-submit-register:hover {
	background: #0369a1 !important;
	color: #ffffff !important;
	box-shadow: 0 2px 6px rgba(2, 132, 199, 0.35) !important;
}

.cwd-register-footer {
	text-align: center !important;
	margin-top: 22px !important;
	padding-top: 18px !important;
	border-top: 1px solid #f1f5f9 !important;
}

.cwd-register-footer p {
	font-size: 13.5px !important;
	color: #64748b !important;
	margin: 0 !important;
}

.cwd-register-footer a {
	color: #0284c7 !important;
	font-weight: 600 !important;
	text-decoration: none !important;
}

.cwd-register-footer a:hover {
	text-decoration: underline !important;
}
</style>

<div class="cwd-v2-register-wrapper">
	<div class="cwd-register-card">
		<div class="cwd-register-header">
			<h2><?php esc_html_e('Create an Account', 'custom-woo-dashboard'); ?></h2>
			<p><?php esc_html_e('Register for fast checkout, real-time shipment tracking, and order history.', 'custom-woo-dashboard'); ?></p>
		</div>

		<?php wc_print_notices(); ?>

		<form method="post" action="" class="cwd-v2-register-form" id="cwd-register-form">
			<?php wp_nonce_field('cwd_v2_register_user_action', 'cwd_v2_register_nonce'); ?>
			<input type="hidden" name="cwd_v2_register_user" value="1" />

			<!-- Anti-spam honeypot -->
			<div style="display:none !important;" aria-hidden="true">
				<input type="text" name="cwd_v2_extra_field" tabindex="-1" autocomplete="off" />
			</div>

			<!-- Personal Info Fields Grid -->
			<div class="cwd-form-row">
				<div class="cwd-form-group">
					<label for="cwd_first_name"><?php esc_html_e('First Name', 'custom-woo-dashboard'); ?> <span class="required">*</span></label>
					<input type="text" name="cwd_first_name" id="cwd_first_name" required autocomplete="given-name" class="input-text" />
				</div>
				<div class="cwd-form-group">
					<label for="cwd_last_name"><?php esc_html_e('Last Name', 'custom-woo-dashboard'); ?> <span class="required">*</span></label>
					<input type="text" name="cwd_last_name" id="cwd_last_name" required autocomplete="family-name" class="input-text" />
				</div>
			</div>

			<div class="cwd-form-row">
				<div class="cwd-form-group">
					<label for="cwd_email"><?php esc_html_e('Email Address', 'custom-woo-dashboard'); ?> <span class="required">*</span></label>
					<input type="email" name="cwd_email" id="cwd_email" required autocomplete="email" class="input-text" />
				</div>
				<div class="cwd-form-group">
					<label for="cwd_phone"><?php esc_html_e('Phone Number', 'custom-woo-dashboard'); ?> <span class="required">*</span></label>
					<input type="tel" name="cwd_phone" id="cwd_phone" required autocomplete="tel" placeholder="e.g. 07123 456789" class="input-text" />
				</div>
			</div>

			<!-- Optional Company Name Field for Trade / Business Customers -->
			<div class="cwd-form-group cwd-full-width">
				<label for="cwd_company_name"><?php esc_html_e('Company / Business Name', 'custom-woo-dashboard'); ?> <span style="font-weight:400; color:#94a3b8; font-size:12px;">(<?php esc_html_e('Optional', 'custom-woo-dashboard'); ?>)</span></label>
				<input type="text" name="cwd_company_name" id="cwd_company_name" autocomplete="organization" placeholder="<?php esc_attr_e('Enter your company name if purchasing for trade', 'custom-woo-dashboard'); ?>" class="input-text" />
			</div>

			<!-- Password Fields Grid -->
			<div class="cwd-form-row">
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

			<!-- Trade Credit Notice Callout -->
			<div class="cwd-trade-notice-banner">
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
				<div>
					<strong><?php esc_html_e('Looking for a 30-Day Trade Credit Account?', 'custom-woo-dashboard'); ?></strong>
					<span><?php esc_html_e('Register your account first. Once registered, trade customers can apply for official credit terms and limits directly from the Trade Credit dashboard or via our dedicated trade application form.', 'custom-woo-dashboard'); ?></span>
				</div>
			</div>

			<!-- Submit Button -->
			<div class="cwd-form-submit-row">
				<button type="submit" name="cwd_v2_register_user_btn" id="cwd-btn-register" class="cwd-btn-submit-register">
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
