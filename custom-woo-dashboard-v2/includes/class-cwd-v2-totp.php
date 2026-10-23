<?php
/**
 * Standalone RFC 6238 TOTP Two-Factor Authentication Engine.
 * Compatible with Google Authenticator, Microsoft Authenticator, 1Password, and Authy.
 */

if (! defined('ABSPATH')) {
	exit;
}

class CWD_V2_TOTP
{
	private static $base32_chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	public static function init()
	{
		add_action('init', array(__CLASS__, 'handle_frontend_2fa_actions'));
		add_filter('authenticate', array(__CLASS__, 'intercept_login_2fa'), 30, 3);
		add_action('login_form', array(__CLASS__, 'render_login_2fa_field'));
	}

	public static function is_2fa_active_for_user($user_id)
	{
		if (! $user_id) {
			return false;
		}

		// Check native CWD TOTP
		if (get_user_meta($user_id, '_cwd_2fa_enabled', true)) {
			return true;
		}

		// Check Wordfence Login Security
		if (class_exists('\WordfenceLS\Controller_Users')) {
			try {
				$u = get_userdata($user_id);
				if ($u && \WordfenceLS\Controller_Users::shared()->has_2fa_active($u)) {
					return true;
				}
			} catch (\Exception $e) {
				// Fallback
			}
		}

		return (bool) get_user_meta($user_id, 'wfls_2fa_active', true);
	}

	public static function generate_secret($length = 16)
	{
		$secret = '';
		for ($i = 0; $i < $length; $i++) {
			$secret .= self::$base32_chars[random_int(0, 31)];
		}
		return $secret;
	}

	public static function verify_code($secret, $code, $discrepancy = 1)
	{
		$clean_code = trim((string) $code);
		if (strlen($clean_code) !== 6 || ! ctype_digit($clean_code)) {
			return false;
		}

		$time_slice = floor(time() / 30);
		for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
			$calculated = self::calculate_code($secret, $time_slice + $i);
			if (hash_equals((string) $calculated, (string) $clean_code)) {
				return true;
			}
		}
		return false;
	}

	public static function calculate_code($secret, $time_slice)
	{
		$secret_key = self::base32_decode($secret);
		$time = pack('N*', 0) . pack('N*', $time_slice);
		$hmac = hash_hmac('sha1', $time, $secret_key, true);
		$offset = ord(substr($hmac, -1)) & 0x0F;
		$hash_part = substr($hmac, $offset, 4);
		$value = unpack('N', $hash_part)[1] & 0x7FFFFFFF;
		$modulo = pow(10, 6);
		return str_pad($value % $modulo, 6, '0', STR_PAD_LEFT);
	}

	private static function base32_decode($b32)
	{
		$b32 = strtoupper($b32);
		$b32 = preg_replace('/[^A-Z2-7]/', '', $b32);
		if (empty($b32)) {
			return '';
		}
		$binary_string = '';
		for ($i = 0; $i < strlen($b32); $i++) {
			$val = strpos(self::$base32_chars, $b32[$i]);
			$binary_string .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
		}
		$bytes = '';
		$chunks = str_split($binary_string, 8);
		foreach ($chunks as $chunk) {
			if (strlen($chunk) === 8) {
				$bytes .= chr(bindec($chunk));
			}
		}
		return $bytes;
	}

	public static function handle_frontend_2fa_actions()
	{
		if (! is_user_logged_in()) {
			return;
		}

		$user_id = get_current_user_id();

		// Activate 2FA Form submission
		if (isset($_POST['cwd_v2_action']) && $_POST['cwd_v2_action'] === 'activate_2fa') {
			if (! isset($_POST['cwd_2fa_nonce']) || ! wp_verify_nonce($_POST['cwd_2fa_nonce'], 'cwd_2fa_setup_nonce')) {
				wc_add_notice(__('Security check failed. Please refresh and try again.', 'custom-woo-dashboard'), 'error');
				return;
			}

			$secret = sanitize_text_field(wp_unslash($_POST['cwd_2fa_secret'] ?? ''));
			$code   = sanitize_text_field(wp_unslash($_POST['cwd_2fa_code'] ?? ''));

			if (empty($secret) || empty($code)) {
				wc_add_notice(__('Please enter the 6-digit code from your authenticator app.', 'custom-woo-dashboard'), 'error');
				return;
			}

			if (self::verify_code($secret, $code)) {
				update_user_meta($user_id, '_cwd_2fa_secret', $secret);
				update_user_meta($user_id, '_cwd_2fa_enabled', 1);
				delete_user_meta($user_id, '_cwd_2fa_pending_secret');

				// Generate 5 backup codes
				$backup_codes = array();
				for ($b = 0; $b < 5; $b++) {
					$backup_codes[] = wp_generate_password(8, false);
				}
				update_user_meta($user_id, '_cwd_2fa_backup_codes', $backup_codes);

				wc_add_notice(__('Two-Factor Authentication (2FA) is now ACTIVE and protecting your account!', 'custom-woo-dashboard'), 'success');
			} else {
				wc_add_notice(__('Invalid 6-digit code. Please verify the time on your phone and try again.', 'custom-woo-dashboard'), 'error');
			}
		}

		// Deactivate 2FA Form submission
		if (isset($_POST['cwd_v2_action']) && $_POST['cwd_v2_action'] === 'deactivate_2fa') {
			if (! isset($_POST['cwd_2fa_nonce']) || ! wp_verify_nonce($_POST['cwd_2fa_nonce'], 'cwd_2fa_setup_nonce')) {
				wc_add_notice(__('Security check failed. Please refresh and try again.', 'custom-woo-dashboard'), 'error');
				return;
			}

			delete_user_meta($user_id, '_cwd_2fa_secret');
			delete_user_meta($user_id, '_cwd_2fa_enabled');
			delete_user_meta($user_id, '_cwd_2fa_backup_codes');

			wc_add_notice(__('Two-Factor Authentication has been deactivated for your account.', 'custom-woo-dashboard'), 'notice');
		}
	}

	public static function render_2fa_setup_ui($user_id)
	{
		$is_active = self::is_2fa_active_for_user($user_id);
		$user = get_userdata($user_id);
		$email = $user ? $user->user_email : 'user@ewsupplies.co.uk';

		echo '<div class="cwd-totp-container" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; margin-top: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">';

		if ($is_active) {
			echo '<div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">';
			echo '<div>';
			echo '<div style="display: inline-flex; align-items: center; gap: 8px; background: #dcfce7; color: #166534; font-weight: 700; font-size: 13.5px; padding: 6px 14px; border-radius: 9999px; border: 1px solid #86efac; margin-bottom: 8px;">';
			echo '&#10004; ' . esc_html__('2FA Enabled & Protecting Your Account', 'custom-woo-dashboard');
			echo '</div>';
			echo '<p style="margin: 0; color: #475569; font-size: 13.5px; line-height: 1.5;">' . esc_html__('Your account requires a 6-digit TOTP code from your mobile authenticator app whenever signing in.', 'custom-woo-dashboard') . '</p>';
			echo '</div>';

			echo '<form method="post" action="" onsubmit="return confirm(\'' . esc_attr__('Are you sure you want to deactivate Two-Factor Authentication?', 'custom-woo-dashboard') . '\');">';
			wp_nonce_field('cwd_2fa_setup_nonce', 'cwd_2fa_nonce');
			echo '<input type="hidden" name="cwd_v2_action" value="deactivate_2fa" />';
			echo '<button type="submit" class="button" style="background: #fee2e2; border-color: #fca5a5; color: #991b1b; padding: 8px 16px; border-radius: 6px; font-weight: 600;">' . esc_html__('Deactivate 2FA', 'custom-woo-dashboard') . '</button>';
			echo '</form>';
			echo '</div>';

			// Display Backup Codes if available
			$backup_codes = get_user_meta($user_id, '_cwd_2fa_backup_codes', true);
			if (! empty($backup_codes) && is_array($backup_codes)) {
				echo '<div style="margin-top: 18px; padding-top: 16px; border-top: 1px solid #f1f5f9;">';
				echo '<strong style="font-size: 13px; color: #334155; display: block; margin-bottom: 6px;">' . esc_html__('Your Backup Recovery Codes (Save these in a safe place):', 'custom-woo-dashboard') . '</strong>';
				echo '<div style="display: flex; gap: 10px; flex-wrap: wrap;">';
				foreach ($backup_codes as $code) {
					echo '<code style="background: #f1f5f9; padding: 4px 10px; border-radius: 4px; font-weight: 700; color: #0f172a; font-size: 13px;">' . esc_html($code) . '</code>';
				}
				echo '</div>';
				echo '</div>';
			}

		} else {
			// Generate or retrieve pending secret
			$pending_secret = get_user_meta($user_id, '_cwd_2fa_pending_secret', true);
			if (empty($pending_secret) || strlen($pending_secret) !== 16) {
				$pending_secret = self::generate_secret(16);
				update_user_meta($user_id, '_cwd_2fa_pending_secret', $pending_secret);
			}

			$site_name = get_bloginfo('name') ?: 'EWSupplies';
			$otp_url = 'otpauth://totp/' . rawurlencode($site_name) . ':' . rawurlencode($email) . '?secret=' . $pending_secret . '&issuer=' . rawurlencode($site_name);
			$qr_url  = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . rawurlencode($otp_url);

			echo '<h4 style="margin: 0 0 8px 0; font-size: 16px; font-weight: 700; color: #0f172a;">' . esc_html__('Set Up Two-Factor Authentication', 'custom-woo-dashboard') . '</h4>';
			echo '<p style="margin: 0 0 20px 0; font-size: 13.5px; color: #64748b; line-height: 1.5;">' . esc_html__('Use Google Authenticator, Microsoft Authenticator, 1Password, or Authy on your smartphone to scan the QR code below.', 'custom-woo-dashboard') . '</p>';

			echo '<div style="display: flex; gap: 24px; align-items: flex-start; flex-wrap: wrap;">';
			echo '<div style="background: #ffffff; padding: 8px; border: 1px solid #cbd5e1; border-radius: 8px; text-align: center;">';
			echo '<img src="' . esc_url($qr_url) . '" alt="2FA QR Code" width="180" height="180" style="display: block; border-radius: 4px;" />';
			echo '</div>';

			echo '<div style="flex: 1; min-width: 260px;">';
			echo '<p style="font-size: 13px; color: #475569; margin: 0 0 10px 0;"><strong>' . esc_html__('Can\'t scan QR code? Manual Entry Secret:', 'custom-woo-dashboard') . '</strong></p>';
			echo '<code style="background: #f1f5f9; padding: 6px 12px; border-radius: 6px; font-size: 15px; font-weight: 700; color: #0284c7; letter-spacing: 2px; display: inline-block; margin-bottom: 18px;">' . esc_html(chunk_split($pending_secret, 4, ' ')) . '</code>';

			echo '<form method="post" action="">';
			wp_nonce_field('cwd_2fa_setup_nonce', 'cwd_2fa_nonce');
			echo '<input type="hidden" name="cwd_v2_action" value="activate_2fa" />';
			echo '<input type="hidden" name="cwd_2fa_secret" value="' . esc_attr($pending_secret) . '" />';

			echo '<div style="margin-bottom: 14px;">';
			echo '<label for="cwd_2fa_code" style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;">' . esc_html__('Enter 6-Digit Verification Code from App:', 'custom-woo-dashboard') . '</label>';
			echo '<input type="text" name="cwd_2fa_code" id="cwd_2fa_code" placeholder="e.g. 123456" maxlength="6" pattern="[0-9]{6}" required style="max-width: 220px; font-size: 18px; letter-spacing: 4px; text-align: center; font-weight: 700; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; display: block;" />';
			echo '</div>';

			echo '<button type="submit" class="button button-primary" style="background: #008060; border-color: #008060; color: #fff; padding: 8px 20px; border-radius: 6px; font-weight: 600; font-size: 14px;">' . esc_html__('Activate Two-Factor Authentication', 'custom-woo-dashboard') . ' &rarr;</button>';
			echo '</form>';
			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	public static function render_login_2fa_field()
	{
		echo '<p class="cwd-2fa-login-prompt" style="margin-top: 15px;">';
		echo '<label for="cwd_2fa_code_login">' . esc_html__('2FA Authenticator Code (if enabled):', 'custom-woo-dashboard') . '<br />';
		echo '<input type="text" name="cwd_2fa_code_login" id="cwd_2fa_code_login" class="input" value="" size="20" placeholder="6-digit code" maxlength="8" autocomplete="one-time-code" style="letter-spacing: 2px; font-weight: 600;" />';
		echo '</label>';
		echo '</p>';
	}

	public static function intercept_login_2fa($user, $username, $password)
	{
		if ($user instanceof WP_User) {
			$user_id = $user->ID;
			if (get_user_meta($user_id, '_cwd_2fa_enabled', true)) {
				$secret = (string) get_user_meta($user_id, '_cwd_2fa_secret', true);
				$entered_code = isset($_POST['cwd_2fa_code_login']) ? sanitize_text_field(wp_unslash($_POST['cwd_2fa_code_login'])) : '';

				if (empty($entered_code)) {
					return new WP_Error('cwd_2fa_required', __('<strong>Security Verification Required</strong>: Please enter your 6-digit 2FA authenticator code.', 'custom-woo-dashboard'));
				}

				// Check backup codes first
				$backup_codes = (array) get_user_meta($user_id, '_cwd_2fa_backup_codes', true);
				if (in_array($entered_code, $backup_codes, true)) {
					// Consume backup code
					$remaining = array_values(array_diff($backup_codes, array($entered_code)));
					update_user_meta($user_id, '_cwd_2fa_backup_codes', $remaining);
					return $user;
				}

				if (! self::verify_code($secret, $entered_code)) {
					return new WP_Error('cwd_2fa_invalid', __('<strong>Invalid 2FA Code</strong>: The 6-digit authenticator code you entered is invalid or expired.', 'custom-woo-dashboard'));
				}
			}
		}
		return $user;
	}
}

CWD_V2_TOTP::init();
