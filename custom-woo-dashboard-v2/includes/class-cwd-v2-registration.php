<?php

/**
 * Customer and Trade User Registration Handler
 */

if (! defined('ABSPATH')) {
	exit;
}

class CWD_V2_Registration
{

	public static function init()
	{
		add_shortcode('custom_woo_registration', array(__CLASS__, 'render_shortcode'));
		add_shortcode('cwd_user_registration', array(__CLASS__, 'render_shortcode'));
		add_action('template_redirect', array(__CLASS__, 'process_registration'));
	}

	/**
	 * Render the registration form shortcode
	 *
	 * @param array $atts
	 * @return string HTML
	 */
	public static function render_shortcode($atts = array())
	{
		if (is_user_logged_in()) {
			return '<div class="woocommerce-message woocommerce-message--info woocommerce-info">' .
				esc_html__('You are already logged in to your account.', 'custom-woo-dashboard') .
				' <a href="' . esc_url(wc_get_page_permalink('myaccount')) . '" class="button">' .
				esc_html__('Go to Dashboard', 'custom-woo-dashboard') . '</a></div>';
		}

		ob_start();
		self::render_form();
		return ob_get_clean();
	}

	/**
	 * Directly output registration form template
	 */
	public static function render_form()
	{
		$template_path = CWD_V2_PLUGIN_DIR . 'templates/register.php';
		if (file_exists($template_path)) {
			include $template_path;
		}
	}

	/**
	 * Handle registration submission
	 */
	public static function process_registration()
	{
		if (! isset($_POST['cwd_v2_register_user'])) {
			return;
		}

		// Anti-spam honeypot
		if (! empty($_POST['cwd_v2_extra_field'])) {
			wp_die(esc_html__('Spam submission detected.', 'custom-woo-dashboard'));
		}

		$nonce = isset($_POST['cwd_v2_register_nonce']) ? sanitize_text_field(wp_unslash($_POST['cwd_v2_register_nonce'])) : '';
		if (! wp_verify_nonce($nonce, 'cwd_v2_register_user_action')) {
			wc_add_notice(__('Security check failed. Please refresh and try again.', 'custom-woo-dashboard'), 'error');
			return;
		}

		$account_type = sanitize_key($_POST['cwd_account_type'] ?? 'retail');
		if (! in_array($account_type, array('retail', 'trade'), true)) {
			$account_type = 'retail';
		}

		$first_name = sanitize_text_field(wp_unslash($_POST['cwd_first_name'] ?? ''));
		$last_name  = sanitize_text_field(wp_unslash($_POST['cwd_last_name'] ?? ''));
		$email      = sanitize_email(wp_unslash($_POST['cwd_email'] ?? ''));
		$phone      = sanitize_text_field(wp_unslash($_POST['cwd_phone'] ?? ''));
		$password   = $_POST['cwd_password'] ?? '';
		$confirm_pw = $_POST['cwd_confirm_password'] ?? '';

		$company_name    = sanitize_text_field(wp_unslash($_POST['cwd_company_name'] ?? ''));
		$company_reg     = sanitize_text_field(wp_unslash($_POST['cwd_company_reg_number'] ?? ''));
		$vat_number      = sanitize_text_field(wp_unslash($_POST['cwd_vat_number'] ?? ''));
		$trade_type      = sanitize_text_field(wp_unslash($_POST['cwd_trade_type'] ?? ''));
		$apply_credit    = ! empty($_POST['cwd_apply_for_credit']);
		$requested_limit = isset($_POST['cwd_requested_limit']) ? (float) $_POST['cwd_requested_limit'] : 1000.0;

		$errors = array();

		if (empty($first_name)) {
			$errors[] = __('Please enter your first name.', 'custom-woo-dashboard');
		}

		if (empty($last_name)) {
			$errors[] = __('Please enter your last name.', 'custom-woo-dashboard');
		}

		if (empty($email) || ! is_email($email)) {
			$errors[] = __('Please enter a valid email address.', 'custom-woo-dashboard');
		} elseif (email_exists($email)) {
			$errors[] = __('An account is already registered with this email address. Please log in.', 'custom-woo-dashboard');
		}

		if (empty($phone)) {
			$errors[] = __('Please enter your phone number.', 'custom-woo-dashboard');
		}

		if (empty($password)) {
			$errors[] = __('Please enter a password.', 'custom-woo-dashboard');
		} elseif (strlen($password) < 8) {
			$errors[] = __('Password must be at least 8 characters long.', 'custom-woo-dashboard');
		} elseif ($password !== $confirm_pw) {
			$errors[] = __('Passwords do not match.', 'custom-woo-dashboard');
		}

		if ('trade' === $account_type && empty($company_name)) {
			$errors[] = __('Please enter your company or business trading name.', 'custom-woo-dashboard');
		}

		if (! empty($errors)) {
			foreach ($errors as $error) {
				wc_add_notice($error, 'error');
			}
			return;
		}

		// Generate clean username from email
		$base_username = sanitize_user(current(explode('@', $email)), true);
		$username      = $base_username;
		$suffix        = 1;
		while (username_exists($username)) {
			$username = $base_username . $suffix;
			$suffix++;
		}

		// Insert user
		$user_id = wp_insert_user(array(
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => $password,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => trim($first_name . ' ' . $last_name),
			'role'         => 'customer',
		));

		if (is_wp_error($user_id)) {
			wc_add_notice($user_id->get_error_message(), 'error');
			return;
		}

		// Update WooCommerce customer billing meta
		update_user_meta($user_id, 'billing_first_name', $first_name);
		update_user_meta($user_id, 'billing_last_name', $last_name);
		update_user_meta($user_id, 'billing_email', $email);
		update_user_meta($user_id, 'billing_phone', $phone);
		update_user_meta($user_id, 'shipping_first_name', $first_name);
		update_user_meta($user_id, 'shipping_last_name', $last_name);
		update_user_meta($user_id, '_account_type', $account_type);

		// Generate standardized EWS account number
		$acc_num = 'EWS-' . str_pad((string) $user_id, 6, '0', STR_PAD_LEFT);
		update_user_meta($user_id, 'ews_account_number', $acc_num);
		update_user_meta($user_id, 'cwd_ews_account_number', $acc_num);

		// Handle Trade Account specific fields
		if ('trade' === $account_type) {
			update_user_meta($user_id, 'billing_company', $company_name);
			update_user_meta($user_id, 'shipping_company', $company_name);
			update_user_meta($user_id, 'company_name', $company_name);
			update_user_meta($user_id, 'ews_company_name', $company_name);

			if (! empty($company_reg)) {
				update_user_meta($user_id, 'company_registration_number', $company_reg);
				update_user_meta($user_id, '_company_registration_number', $company_reg);
			}

			if (! empty($vat_number)) {
				update_user_meta($user_id, 'vat_number', $vat_number);
				update_user_meta($user_id, '_vat_number', $vat_number);
			}

			if (! empty($trade_type)) {
				update_user_meta($user_id, 'trade_type', $trade_type);
			}

			if ($apply_credit && class_exists('CWD_V2_Trade_Applications')) {
				update_user_meta($user_id, '_is_trade_applicant', 'yes');
				CWD_V2_Trade_Applications::register_trade_application_from_signup($user_id, array(
					'first_name'         => $first_name,
					'last_name'          => $last_name,
					'company_name'       => $company_name,
					'company_reg_number' => $company_reg,
					'vat_number'         => $vat_number,
					'trade_type'         => $trade_type,
					'phone'              => $phone,
					'requested_limit'    => $requested_limit,
				));
			}
		}

		// Fire user_register hook (triggers real-time sync with Odoo ERP)
		do_action('user_register', $user_id);

		// Auto-login newly registered user
		wp_set_current_user($user_id);
		wp_set_auth_cookie($user_id, true);
		$user = get_userdata($user_id);
		if ($user) {
			do_action('wp_login', $user->user_login, $user);
		}

		if ('trade' === $account_type && $apply_credit) {
			wc_add_notice(
				__('Welcome to EWS Supplies! Your account has been registered and your 30-Day Trade Credit application has been submitted for approval.', 'custom-woo-dashboard'),
				'success'
			);
		} else {
			wc_add_notice(
				__('Welcome to EWS Supplies! Your account has been created successfully.', 'custom-woo-dashboard'),
				'success'
			);
		}

		$redirect_url = wc_get_page_permalink('myaccount');
		wp_safe_redirect($redirect_url);
		exit;
	}
}
