<?php

/**
 * Trade Credit Applications Manager
 *
 * Captures Forminator form submissions for trade credit applications,
 * provides an admin queue under WooCommerce for reviewing, approving,
 * or rejecting applications, and provisions credit accounts on approval.
 */

if (! defined('ABSPATH')) {
	exit;
}

class CWD_V2_Trade_Applications
{

	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_REJECTED = 'rejected';

	public static function init()
	{
		// Capture Forminator submissions
		add_action('forminator_form_after_save_entry', array(__CLASS__, 'capture_forminator_submission'), 10, 2);

		// Register WP Admin menu under WooCommerce
		add_action('admin_menu', array(__CLASS__, 'register_admin_menu'));

		// Handle approve/reject actions
		add_action('admin_init', array(__CLASS__, 'handle_admin_actions'));

		// Add pending count bubble to the admin menu
		add_action('admin_menu', array(__CLASS__, 'add_pending_count_bubble'), 99);
	}

	/**
	 * Get the database table name
	 *
	 * @return string
	 */
	public static function get_table_name()
	{
		global $wpdb;
		return $wpdb->prefix . 'cwd_v2_trade_applications';
	}

	/**
	 * Create the applications table (called from CWD_V2_Activator)
	 */
	public static function create_table()
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			forminator_form_id BIGINT UNSIGNED NULL DEFAULT NULL,
			forminator_entry_id BIGINT UNSIGNED NULL DEFAULT NULL,
			applicant_email VARCHAR(255) NOT NULL,
			applicant_name VARCHAR(255) NOT NULL DEFAULT '',
			company_name VARCHAR(255) NOT NULL DEFAULT '',
			phone VARCHAR(50) NOT NULL DEFAULT '',
			requested_limit DECIMAL(18,2) NOT NULL DEFAULT 0,
			form_data LONGTEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			approved_limit DECIMAL(18,2) NULL DEFAULT NULL,
			admin_note TEXT NULL DEFAULT NULL,
			reviewed_by BIGINT UNSIGNED NULL DEFAULT NULL,
			reviewed_at DATETIME NULL DEFAULT NULL,
			user_id BIGINT UNSIGNED NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY applicant_email (applicant_email),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";
		dbDelta($sql);
	}

	/**
	 * Ensure table exists in database before queries.
	 */
	public static function ensure_table_exists()
	{
		global $wpdb;
		$table_name = self::get_table_name();
		if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
			self::create_table();
		}
	}

	/**
	 * Record a credit limit increase request submitted from the customer credit dashboard.
	 *
	 * @param int    $user_id          WordPress user ID
	 * @param float  $requested_amount Requested new credit limit
	 * @param string $reason           Justification or reason
	 * @return int|false
	 */
	public static function record_credit_increase_request($user_id, $requested_amount, $reason)
	{
		self::ensure_table_exists();

		$user = get_userdata($user_id);
		if (! $user) {
			return false;
		}

		global $wpdb;
		$table = self::get_table_name();
		$now   = current_time('mysql');

		// Deduplication: prevent duplicate pending requests for the same customer
		$existing_pending = $wpdb->get_var($wpdb->prepare(
			"SELECT id FROM {$table} WHERE user_id = %d AND status = %s AND forminator_form_id = 0 LIMIT 1",
			$user_id,
			self::STATUS_PENDING
		));
		if ($existing_pending) {
			return (int) $existing_pending;
		}

		$current_limit   = (float) get_user_meta($user_id, '_credit_limit', true);
		$current_balance = (float) get_user_meta($user_id, '_credit_balance', true);
		$company_name    = get_user_meta($user_id, 'billing_company', true) ?: (get_user_meta($user_id, 'ews_company_name', true) ?: '');
		$phone           = get_user_meta($user_id, 'billing_phone', true) ?: '';
		$applicant_name  = trim($user->first_name . ' ' . $user->last_name);
		if (empty($applicant_name)) {
			$applicant_name = $user->display_name;
		}

		$form_data = array(
			'application_type' => 'credit_limit_increase',
			'current_limit'    => $current_limit,
			'current_balance'  => $current_balance,
			'requested_amount' => (float) $requested_amount,
			'reason'           => $reason,
			'source'           => 'customer_credit_dashboard',
		);

		$insert_data = array(
			'forminator_form_id'  => 0,
			'forminator_entry_id' => 0,
			'applicant_email'     => sanitize_email($user->user_email),
			'applicant_name'      => sanitize_text_field($applicant_name),
			'company_name'        => sanitize_text_field($company_name),
			'phone'               => sanitize_text_field($phone),
			'requested_limit'     => (float) $requested_amount,
			'form_data'           => wp_json_encode($form_data),
			'status'              => self::STATUS_PENDING,
			'user_id'             => $user_id,
			'created_at'          => $now,
			'updated_at'          => $now,
		);

		$wpdb->insert($table, $insert_data, $insert_format);
		$inserted_id = $wpdb->insert_id;

		if ($inserted_id && class_exists('CWD_V2_Credits_Bridge')) {
			CWD_V2_Credits_Bridge::forward_application_to_credits_plugin(
				$inserted_id,
				$user_id,
				$user->user_email,
				$applicant_name,
				$company_name,
				$phone,
				(float) $requested_amount,
				$form_data
			);
		}

		return $inserted_id;
	}

	/**
	 * Capture a Forminator form submission as a trade application.
	 *
	 * Maps standard field name patterns to application columns.
	 * The admin can configure the form ID via Settings, or we auto-detect
	 * by checking if the form contains an email + credit/limit field.
	 *
	 * @param int   $form_id  Forminator form ID
	 * @param array $response Submission response containing entry_id
	 */
	public static function capture_forminator_submission($form_id, $response)
	{
		// Check if this is the configured trade application form
		$configured_form_id = (int) get_option('cwd_v2_trade_application_form_id', 0);
		if ($configured_form_id > 0 && (int) $form_id !== $configured_form_id) {
			return;
		}

		if (! isset($response['success']) || ! $response['success'] || ! isset($response['entry_id'])) {
			return;
		}

		// Forminator_API must be available
		if (! class_exists('Forminator_API')) {
			return;
		}

		$entry_id = (int) $response['entry_id'];
		$entry    = Forminator_API::get_entry($form_id, $entry_id);
		if (! $entry || is_wp_error($entry)) {
			return;
		}

		$meta = isset($entry->meta_data) ? $entry->meta_data : array();

		// Flatten meta into key => value for easier mapping
		$flat = array();
		foreach ($meta as $key => $data) {
			if (is_array($data) && isset($data['value'])) {
				$flat[$key] = $data['value'];
			} elseif (is_string($data)) {
				$flat[$key] = $data;
			} else {
				$flat[$key] = $data;
			}
		}

		// Expand field labels so all field names and human labels are stored and forwarded
		if (class_exists('Forminator_API') && method_exists('Forminator_API', 'get_form_fields')) {
			$form_fields = Forminator_API::get_form_fields((int) $form_id);
			if (is_array($form_fields)) {
				foreach ($form_fields as $f) {
					$fid    = is_object($f) ? ($f->slug ?? ($f->element_id ?? '')) : ($f['element_id'] ?? '');
					$flabel = is_object($f) ? ($f->raw['field_label'] ?? ($f->field_label ?? '')) : ($f['field_label'] ?? '');
					if ($fid && $flabel && array_key_exists($fid, $flat)) {
						$flat[$flabel] = $flat[$fid];
					}
				}
			}
		}

		// Map fields using common Forminator field name patterns
		$email           = self::find_field_value($flat, array('email', 'email-1', 'applicant_email', 'contact_email'));
		$name            = self::find_field_value($flat, array('name', 'name-1', 'applicant_name', 'contact_name', 'full_name'));
		$company         = self::find_field_value($flat, array('company', 'company_name', 'company-1', 'business_name', 'text-1'));
		$phone           = self::find_field_value($flat, array('phone', 'phone-1', 'telephone', 'contact_phone'));
		$requested_limit = self::find_field_value($flat, array('credit_limit', 'requested_limit', 'requested_credit_limit', 'number-1', 'currency-1'));

		// If no email found, skip (not a valid trade application)
		if (empty($email)) {
			return;
		}

		// Handle name field that may be an array (first/last)
		if (is_array($name)) {
			$name = trim(($name['first_name'] ?? ($name['prefix'] ?? '')) . ' ' . ($name['last_name'] ?? ($name['suffix'] ?? '')));
		}

		$requested_limit_val = 0.0;
		if (! empty($requested_limit)) {
			$requested_limit_val = (float) preg_replace('/[^0-9.]/', '', (string) $requested_limit);
		}

		// Check if an existing user matches
		$user    = get_user_by('email', sanitize_email($email));
		$user_id = $user ? $user->ID : null;

		self::ensure_table_exists();

		global $wpdb;
		$table = self::get_table_name();
		$now   = current_time('mysql');

		$insert_data = array(
			'forminator_form_id'  => $form_id,
			'forminator_entry_id' => $entry_id,
			'applicant_email'     => sanitize_email($email),
			'applicant_name'      => sanitize_text_field((string) $name),
			'company_name'        => sanitize_text_field((string) $company),
			'phone'               => sanitize_text_field((string) $phone),
			'requested_limit'     => $requested_limit_val,
			'form_data'           => wp_json_encode($flat),
			'status'              => self::STATUS_PENDING,
			'created_at'          => $now,
			'updated_at'          => $now,
		);
		$insert_format = array('%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s');

		// Only include user_id if we found a matching user
		if ($user_id) {
			$insert_data['user_id'] = $user_id;
			$insert_format[]        = '%d';
		}

		$wpdb->insert($table, $insert_data, $insert_format);
		$app_id = $wpdb->insert_id;

		if ($app_id && class_exists('CWD_V2_Credits_Bridge')) {
			CWD_V2_Credits_Bridge::forward_application_to_credits_plugin(
				$app_id,
				$user_id,
				$email,
				$name,
				$company,
				$phone,
				$requested_limit_val,
				$flat
			);
		}
	}

	/**
	 * Search for a field value across multiple possible field key names
	 *
	 * @param array $data       Flattened form data
	 * @param array $candidates Possible field keys to check
	 * @return mixed|string
	 */
	private static function find_field_value(array $data, array $candidates)
	{
		foreach ($candidates as $key) {
			if (isset($data[$key]) && $data[$key] !== '') {
				return $data[$key];
			}
		}
		// Also do a partial match for Forminator's numbered fields
		foreach ($data as $field_key => $value) {
			foreach ($candidates as $candidate) {
				$base = explode('-', $candidate)[0];
				if (stripos($field_key, $base) === 0 && $value !== '') {
					return $value;
				}
			}
		}
		return '';
	}

	/**
	 * Get the count of pending applications
	 *
	 * @return int
	 */
	public static function get_pending_count()
	{
		self::ensure_table_exists();

		global $wpdb;
		$table = self::get_table_name();
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE status = %s",
			self::STATUS_PENDING
		));
	}

	/**
	 * Register the admin menu pages
	 */
	public static function register_admin_menu()
	{
		$pending_count = self::get_pending_count();
		$menu_title    = __('Trade & Credit', 'custom-woo-dashboard');
		if ($pending_count > 0) {
			$menu_title .= sprintf(' <span class="awaiting-mod update-plugins count-%d"><span class="pending-count">%d</span></span>', $pending_count, $pending_count);
		}

		// 1. Dedicated Top-Level Menu "Trade & Credit"
		add_menu_page(
			__('Trade & Credit Management', 'custom-woo-dashboard'),
			$menu_title,
			'manage_woocommerce',
			'cwd-v2-trade-applications',
			array(__CLASS__, 'render_admin_page'),
			'dashicons-money-alt',
			56
		);

		add_submenu_page(
			'cwd-v2-trade-applications',
			__('Applications & Requests', 'custom-woo-dashboard'),
			__('Applications & Requests', 'custom-woo-dashboard'),
			'manage_woocommerce',
			'cwd-v2-trade-applications',
			array(__CLASS__, 'render_admin_page')
		);

		add_submenu_page(
			'cwd-v2-trade-applications',
			__('Credit Accounts', 'custom-woo-dashboard'),
			__('Credit Accounts', 'custom-woo-dashboard'),
			'manage_woocommerce',
			'cwd-v2-credit-accounts',
			array(__CLASS__, 'render_credit_accounts_page')
		);

		add_submenu_page(
			'cwd-v2-trade-applications',
			__('Settings', 'custom-woo-dashboard'),
			__('Settings', 'custom-woo-dashboard'),
			'manage_woocommerce',
			'cwd-v2-trade-settings',
			array(__CLASS__, 'render_settings_page')
		);

		// 2. Also register under WooCommerce for convenience
		add_submenu_page(
			'woocommerce',
			__('Trade Applications', 'custom-woo-dashboard'),
			__('Trade Applications', 'custom-woo-dashboard'),
			'manage_woocommerce',
			'cwd-v2-trade-applications-wc',
			array(__CLASS__, 'render_admin_page')
		);
	}

	/**
	 * Add pending count bubble to the admin menus
	 */
	public static function add_pending_count_bubble()
	{
		global $submenu;
		$pending = self::get_pending_count();
		if ($pending < 1) {
			return;
		}

		// Update Trade Applications submenu
		if (isset($submenu['cwd-v2-trade-applications'])) {
			foreach ($submenu['cwd-v2-trade-applications'] as &$item) {
				if (isset($item[2]) && $item[2] === 'cwd-v2-trade-applications') {
					$item[0] .= sprintf(
						' <span class="awaiting-mod update-plugins count-%d"><span class="pending-count">%d</span></span>',
						$pending,
						$pending
					);
					break;
				}
			}
		}

		// Update WooCommerce submenu
		if (isset($submenu['woocommerce'])) {
			foreach ($submenu['woocommerce'] as &$item) {
				if (isset($item[2]) && ($item[2] === 'cwd-v2-trade-applications' || $item[2] === 'cwd-v2-trade-applications-wc')) {
					$item[0] .= sprintf(
						' <span class="awaiting-mod update-plugins count-%d"><span class="pending-count">%d</span></span>',
						$pending,
						$pending
					);
					break;
				}
			}
		}
	}

	/**
	 * Handle admin POST actions
	 */
	public static function handle_admin_actions()
	{
		if (! current_user_can('manage_woocommerce')) {
			return;
		}

		// Approve application
		if (isset($_POST['cwd_v2_approve_application'])) {
			self::process_approval();
		}

		// Reject application
		if (isset($_POST['cwd_v2_reject_application'])) {
			self::process_rejection();
		}

		// Update approved application limit
		if (isset($_POST['cwd_v2_update_limit'])) {
			self::process_update_limit();
		}

		// Adjust user credit limit from Credit Accounts screen
		if (isset($_POST['cwd_v2_adjust_user_credit_limit'])) {
			self::process_adjust_user_credit_limit();
		}

		// Add manual credit user
		if (isset($_POST['cwd_v2_add_manual_credit_user'])) {
			self::process_add_manual_credit_user();
		}
	}

	/**
	 * Process application approval and provision account
	 */
	private static function process_approval()
	{
		if (! isset($_POST['cwd_v2_trade_app_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cwd_v2_trade_app_nonce'])), 'cwd_v2_trade_app_action')) {
			wp_die(__('Security check failed.', 'custom-woo-dashboard'));
		}

		$app_id = isset($_POST['application_id']) ? (int) $_POST['application_id'] : 0;
		if ($app_id < 1) {
			return;
		}

		global $wpdb;
		$table = self::get_table_name();
		$app   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $app_id));

		if (! $app || $app->status !== self::STATUS_PENDING) {
			wp_redirect(admin_url('admin.php?page=cwd-v2-trade-applications&notice=invalid'));
			exit;
		}

		$approved_limit = isset($_POST['approved_limit']) ? round((float) wc_format_decimal(wp_unslash($_POST['approved_limit'])), 2) : $app->requested_limit;
		$admin_note     = isset($_POST['admin_note']) ? sanitize_textarea_field(wp_unslash($_POST['admin_note'])) : '';
		$payment_terms  = isset($_POST['payment_terms']) ? sanitize_text_field(wp_unslash($_POST['payment_terms'])) : '30 Days End of Month';

		if ($approved_limit <= 0) {
			$approved_limit = $app->requested_limit;
		}

		// Find or create WP user
		$user = get_user_by('email', $app->applicant_email);
		if (! $user) {
			$username = sanitize_user(strtolower(str_replace(' ', '.', $app->applicant_name ?: explode('@', $app->applicant_email)[0])), true);
			$base_username = $username;
			$counter       = 1;
			while (username_exists($username)) {
				$username = $base_username . $counter;
				$counter++;
			}
			$password = wp_generate_password(12, true, true);
			$user_id  = wp_create_user($username, $password, $app->applicant_email);

			if (is_wp_error($user_id)) {
				wp_redirect(admin_url('admin.php?page=cwd-v2-trade-applications&view=' . $app_id . '&notice=user_error'));
				exit;
			}

			$user = get_userdata($user_id);
			wp_update_user(array(
				'ID'           => $user_id,
				'display_name' => $app->applicant_name ?: $username,
				'first_name'   => explode(' ', $app->applicant_name)[0] ?? '',
				'last_name'    => count(explode(' ', $app->applicant_name)) > 1 ? explode(' ', $app->applicant_name, 2)[1] : '',
			));

			// Send new user notification
			wp_new_user_notification($user_id, null, 'user');
		}

		$user_id = $user->ID;

		// Assign credit_account role
		if (! in_array('credit_account', (array) $user->roles, true)) {
			$user->add_role('credit_account');
		}

		// Set credit limit and initialize balance
		if (class_exists('CWD_V2_Credit_Logic')) {
			CWD_V2_Credit_Logic::sync_user_credit_limit($user_id, $approved_limit);
			if (! metadata_exists('user', $user_id, '_credit_balance')) {
				CWD_V2_Credit_Logic::sync_user_credit_balance($user_id, 0);
			}
		} else {
			update_user_meta($user_id, '_credit_limit', $approved_limit);
			if (! metadata_exists('user', $user_id, '_credit_balance')) {
				update_user_meta($user_id, '_credit_balance', 0);
			}
		}

		// Set payment terms
		update_user_meta($user_id, '_credit_payment_terms', $payment_terms);

		// Set EWS trade account number if not already set
		if (! get_user_meta($user_id, 'ews_account_number', true)) {
			$last_num = (int) get_option('cwd_v2_last_trade_number', 0) + 1;
			$trade_number = 'EWS-T' . str_pad($last_num, 4, '0', STR_PAD_LEFT);
			update_user_meta($user_id, 'ews_account_number', $trade_number);
			update_option('cwd_v2_last_trade_number', $last_num);
		}

		// Store billing details from application
		if ($app->company_name) {
			update_user_meta($user_id, 'billing_company', $app->company_name);
		}
		if ($app->phone) {
			update_user_meta($user_id, 'billing_phone', $app->phone);
		}

		// Update application record
		$wpdb->update(
			$table,
			array(
				'status'         => self::STATUS_APPROVED,
				'approved_limit' => $approved_limit,
				'admin_note'     => $admin_note,
				'reviewed_by'    => get_current_user_id(),
				'reviewed_at'    => current_time('mysql'),
				'user_id'        => $user_id,
				'updated_at'     => current_time('mysql'),
			),
			array('id' => $app_id),
			array('%s', '%f', '%s', '%d', '%s', '%d', '%s'),
			array('%d')
		);

		// Sync approval to Credits plugin bridge if present
		if (class_exists('CWD_V2_Credits_Bridge')) {
			CWD_V2_Credits_Bridge::sync_credits_plugin_from_trade_approval($app_id, $user_id, $approved_limit);
		}

		// Send approval notification email to applicant
		if (! empty($app->applicant_email)) {
			$blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
			$login_url = wc_get_page_permalink('myaccount');
			$subject = sprintf(__('Your Trade Credit Account has been Approved - %s', 'custom-woo-dashboard'), $blogname);
			$body  = sprintf(__("Hello %s,\n\n", 'custom-woo-dashboard'), $app->applicant_name ?: 'Valued Customer');
			$body .= sprintf(__("Great news! Your trade credit application for %s has been approved.\n\n", 'custom-woo-dashboard'), $app->company_name ?: $blogname);
			$body .= sprintf(__("Approved Credit Facility: %s\n", 'custom-woo-dashboard'), wc_price($approved_limit));
			$body .= sprintf(__("Payment Terms: %s\n", 'custom-woo-dashboard'), $payment_terms);
			$body .= sprintf(__("Trade Account Number: %s\n", 'custom-woo-dashboard'), get_user_meta($user_id, 'ews_account_number', true));
			if (! empty($admin_note)) {
				$body .= sprintf(__("Account Notes: %s\n", 'custom-woo-dashboard'), $admin_note);
			}
			$body .= sprintf(__("\nYou can now purchase on credit account at checkout by selecting 'Pay on Credit Account'.\n\nAccess your credit dashboard here:\n%s\n\n", 'custom-woo-dashboard'), $login_url);
			$body .= sprintf(__("Kind regards,\n%s Team\n", 'custom-woo-dashboard'), $blogname);

			wp_mail($app->applicant_email, $subject, $body);
		}

		wp_redirect(admin_url('admin.php?page=cwd-v2-trade-applications&view=' . $app_id . '&notice=approved'));
		exit;
	}

	/**
	 * Process application rejection
	 */
	private static function process_rejection()
	{
		if (! isset($_POST['cwd_v2_trade_app_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cwd_v2_trade_app_nonce'])), 'cwd_v2_trade_app_action')) {
			wp_die(__('Security check failed.', 'custom-woo-dashboard'));
		}

		$app_id = isset($_POST['application_id']) ? (int) $_POST['application_id'] : 0;
		if ($app_id < 1) {
			return;
		}

		global $wpdb;
		$table      = self::get_table_name();
		$app        = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $app_id));
		$admin_note = isset($_POST['admin_note']) ? sanitize_textarea_field(wp_unslash($_POST['admin_note'])) : '';

		if (! $app || $app->status !== self::STATUS_PENDING) {
			wp_redirect(admin_url('admin.php?page=cwd-v2-trade-applications&notice=invalid'));
			exit;
		}

		$wpdb->update(
			$table,
			array(
				'status'      => self::STATUS_REJECTED,
				'admin_note'  => $admin_note,
				'reviewed_by' => get_current_user_id(),
				'reviewed_at' => current_time('mysql'),
				'updated_at'  => current_time('mysql'),
			),
			array('id' => $app_id),
			array('%s', '%s', '%d', '%s', '%s'),
			array('%d')
		);

		// Send rejection notification email to applicant
		if (! empty($app->applicant_email)) {
			$blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
			$is_credit_increase = ((int) $app->forminator_form_id === 0);

			if ($is_credit_increase) {
				$subject = sprintf(__('Credit Limit Increase Update - %s', 'custom-woo-dashboard'), $blogname);
				$body  = sprintf(__("Hello %s,\n\n", 'custom-woo-dashboard'), $app->applicant_name ?: 'Customer');
				$body .= sprintf(__("Thank you for your request to increase your trade credit facility to %s.\n\n", 'custom-woo-dashboard'), wc_price($app->requested_limit));
				$body .= __("After reviewing your account, we are unable to approve an increase to your credit facility at this time.\n", 'custom-woo-dashboard');
			} else {
				$subject = sprintf(__('Trade Credit Application Update - %s', 'custom-woo-dashboard'), $blogname);
				$body  = sprintf(__("Hello %s,\n\n", 'custom-woo-dashboard'), $app->applicant_name ?: 'Applicant');
				$body .= __("Thank you for applying for a trade credit account with us.\n\n", 'custom-woo-dashboard');
				$body .= __("After careful review of your application, we regret to inform you that we are unable to approve your trade credit facility at this time.\n", 'custom-woo-dashboard');
			}

			if (! empty($admin_note)) {
				$body .= sprintf(__("\nReason / Notes:\n%s\n", 'custom-woo-dashboard'), $admin_note);
			}

			$body .= __("\nIf you have questions or would like to discuss this further, please feel free to reach out to our team.\n\n", 'custom-woo-dashboard');
			$body .= sprintf(__("Kind regards,\n%s Team\n", 'custom-woo-dashboard'), $blogname);

			wp_mail($app->applicant_email, $subject, $body);
		}

		wp_redirect(admin_url('admin.php?page=cwd-v2-trade-applications&view=' . $app_id . '&notice=rejected'));
		exit;
	}

	/**
	 * Process update limit for already approved application
	 */
	private static function process_update_limit()
	{
		if (! isset($_POST['cwd_v2_trade_app_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cwd_v2_trade_app_nonce'])), 'cwd_v2_trade_app_action')) {
			wp_die(__('Security check failed.', 'custom-woo-dashboard'));
		}

		$app_id = isset($_POST['application_id']) ? (int) $_POST['application_id'] : 0;
		if ($app_id < 1) {
			return;
		}

		global $wpdb;
		$table = self::get_table_name();
		$app   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $app_id));
		if (! $app) {
			return;
		}

		$new_limit  = isset($_POST['approved_limit']) ? round((float) wc_format_decimal(wp_unslash($_POST['approved_limit'])), 2) : 0.0;
		$admin_note = isset($_POST['admin_note']) ? sanitize_textarea_field(wp_unslash($_POST['admin_note'])) : $app->admin_note;

		$wpdb->update(
			$table,
			array(
				'approved_limit' => $new_limit,
				'admin_note'     => $admin_note,
				'updated_at'     => current_time('mysql'),
			),
			array('id' => $app_id),
			array('%f', '%s', '%s'),
			array('%d')
		);

		if ($app->user_id > 0) {
			if (class_exists('CWD_V2_Credit_Logic')) {
				CWD_V2_Credit_Logic::sync_user_credit_limit($app->user_id, $new_limit);
			} else {
				update_user_meta($app->user_id, '_credit_limit', $new_limit);
			}
		}

		wp_redirect(admin_url('admin.php?page=cwd-v2-trade-applications&view=' . $app_id . '&notice=limit_updated'));
		exit;
	}

	/**
	 * Adjust user credit limit directly from Credit Accounts screen
	 */
	private static function process_adjust_user_credit_limit()
	{
		check_admin_referer('cwd_v2_credit_accounts_action', 'cwd_v2_credit_nonce');

		$user_id   = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
		$new_limit = isset($_POST['new_credit_limit']) ? round((float) wc_format_decimal(wp_unslash($_POST['new_credit_limit'])), 2) : 0.0;

		if ($user_id > 0) {
			$user = get_userdata($user_id);
			if ($user) {
				if (! in_array('credit_account', (array) $user->roles, true) && $new_limit > 0) {
					$user->add_role('credit_account');
				}
				if (class_exists('CWD_V2_Credit_Logic')) {
					CWD_V2_Credit_Logic::sync_user_credit_limit($user_id, $new_limit);
				} else {
					update_user_meta($user_id, '_credit_limit', $new_limit);
				}

				// Update corresponding trade application
				global $wpdb;
				$table = self::get_table_name();
				$wpdb->query($wpdb->prepare(
					"UPDATE {$table} SET approved_limit = %f, updated_at = %s WHERE user_id = %d AND status = %s ORDER BY id DESC LIMIT 1",
					$new_limit,
					current_time('mysql'),
					$user_id,
					self::STATUS_APPROVED
				));
			}
		}

		wp_redirect(admin_url('admin.php?page=cwd-v2-credit-accounts&notice=limit_updated'));
		exit;
	}

	/**
	 * Manually add a credit user
	 */
	private static function process_add_manual_credit_user()
	{
		check_admin_referer('cwd_v2_credit_accounts_action', 'cwd_v2_credit_nonce');

		$user_email = isset($_POST['user_email']) ? sanitize_email(wp_unslash($_POST['user_email'])) : '';
		$limit      = isset($_POST['credit_limit']) ? round((float) wc_format_decimal(wp_unslash($_POST['credit_limit'])), 2) : 500.0;
		$company    = isset($_POST['company_name']) ? sanitize_text_field(wp_unslash($_POST['company_name'])) : '';

		if (empty($user_email)) {
			wp_redirect(admin_url('admin.php?page=cwd-v2-credit-accounts&notice=invalid_email'));
			exit;
		}

		$user = get_user_by('email', $user_email);
		if (! $user) {
			$username = sanitize_user(explode('@', $user_email)[0], true);
			$base = $username;
			$c = 1;
			while (username_exists($username)) {
				$username = $base . $c;
				$c++;
			}
			$pass = wp_generate_password(12, true, true);
			$uid = wp_create_user($username, $pass, $user_email);
			if (is_wp_error($uid)) {
				wp_redirect(admin_url('admin.php?page=cwd-v2-credit-accounts&notice=create_error'));
				exit;
			}
			$user = get_userdata($uid);
		}

		$user_id = $user->ID;
		if (! in_array('credit_account', (array) $user->roles, true)) {
			$user->add_role('credit_account');
		}

		if (class_exists('CWD_V2_Credit_Logic')) {
			CWD_V2_Credit_Logic::sync_user_credit_limit($user_id, $limit);
			if (! metadata_exists('user', $user_id, '_credit_balance')) {
				CWD_V2_Credit_Logic::sync_user_credit_balance($user_id, 0);
			}
		} else {
			update_user_meta($user_id, '_credit_limit', $limit);
			update_user_meta($user_id, '_credit_balance', 0);
		}

		if (! get_user_meta($user_id, 'ews_account_number', true)) {
			$last_num = (int) get_option('cwd_v2_last_trade_number', 0) + 1;
			update_user_meta($user_id, 'ews_account_number', 'EWS-T' . str_pad($last_num, 4, '0', STR_PAD_LEFT));
			update_option('cwd_v2_last_trade_number', $last_num);
		}

		if ($company) {
			update_user_meta($user_id, 'billing_company', $company);
		}

		wp_redirect(admin_url('admin.php?page=cwd-v2-credit-accounts&notice=user_added'));
		exit;
	}

	/**
	 * Extract the 11 key business details from an application record
	 *
	 * @param object $app
	 * @param array  $form_data
	 * @return array
	 */
	public static function extract_application_basic_details($app, $form_data = array())
	{
		if (empty($form_data) && ! empty($app->form_data)) {
			$form_data = json_decode((string) $app->form_data, true) ?: array();
		}

		$pick = function ($regex, $default = 'n/a') use ($form_data) {
			if (! is_array($form_data) || empty($form_data)) {
				return $default;
			}
			foreach ($form_data as $key => $val) {
				$norm = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $key)), '_');
				if (preg_match($regex, $norm)) {
					if (is_array($val)) {
						$val = implode(', ', array_filter(array_map('strval', array_filter($val, 'is_scalar'))));
					}
					$val = trim((string) $val);
					if ('' !== $val) {
						return $val;
					}
				}
			}
			return $default;
		};

		$requested_limit  = $app->requested_limit > 0 ? $app->requested_limit : (float) ($pick('/(limit|amount|facility)/i', 0.0));
		$monthly_spend    = $pick('/spend/i', 'n/a');
		$business_sector  = $pick('/(sector|industry|business_?type)/i', 'n/a');
		$trading_duration = $pick('/(trading|how_?long|duration|years)/i', 'n/a');
		$trade_ref_name   = $pick('/ref.*name/i', 'n/a');
		$trade_ref_addr   = $pick('/ref.*addr/i', 'n/a');
		$trade_ref_tel    = $pick('/ref.*(tel|phone)/i', 'n/a');
		$company_name     = ! empty($app->company_name) ? $app->company_name : $pick('/company/i', 'n/a');
		$company_reg      = $pick('/(company_?reg|reg.*number)/i', 'n/a');
		$vat_reg          = $pick('/vat/i', 'n/a');
		$parent_company   = $pick('/parent/i', 'n/a');

		return array(
			'credit_limit'     => $requested_limit,
			'monthly_spend'    => $monthly_spend,
			'business_sector'  => $business_sector,
			'trading_duration' => $trading_duration,
			'trade_ref_name'   => $trade_ref_name,
			'trade_ref_addr'   => $trade_ref_addr,
			'trade_ref_tel'    => $trade_ref_tel,
			'company_name'     => $company_name,
			'company_reg'      => $company_reg,
			'vat_reg'          => $vat_reg,
			'parent_company'   => $parent_company,
		);
	}

	/**
	 * Render the admin page (list view or single application view)
	 */
	public static function render_admin_page()
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die(__('You do not have permission to access this page.', 'custom-woo-dashboard'));
		}

		self::ensure_table_exists();

		// Single application review view
		if (isset($_GET['view']) && (int) $_GET['view'] > 0) {
			self::render_single_application((int) $_GET['view']);
			return;
		}

		// List view
		self::render_applications_list();
	}

	/**
	 * Render the applications list table
	 */
	private static function render_applications_list()
	{
		global $wpdb;
		$table = self::get_table_name();

		// Status filter
		$status_filter = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
		$search_query  = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

		$where_clauses = array();
		if (in_array($status_filter, array(self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED), true)) {
			$where_clauses[] = $wpdb->prepare("status = %s", $status_filter);
		}
		if (! empty($search_query)) {
			$like = '%' . $wpdb->esc_like($search_query) . '%';
			$where_clauses[] = $wpdb->prepare("(company_name LIKE %s OR applicant_name LIKE %s OR applicant_email LIKE %s OR phone LIKE %s)", $like, $like, $like, $like);
		}

		$where = ! empty($where_clauses) ? ' WHERE ' . implode(' AND ', $where_clauses) : '';

		$applications = $wpdb->get_results("SELECT * FROM {$table}{$where} ORDER BY created_at DESC LIMIT 150");

		// Counts for tabs
		$count_all      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
		$count_pending  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_PENDING));
		$count_approved = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_APPROVED));
		$count_rejected = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_REJECTED));

		// Admin notice
		$notice = isset($_GET['notice']) ? sanitize_key($_GET['notice']) : '';
		?>
		<div class="wrap">
			<h1 style="display: flex; align-items: center; justify-content: space-between; font-weight: 700; color: #0f172a; margin-bottom: 16px;">
				<span style="display: flex; align-items: center; gap: 10px;">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0f172a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
					<?php esc_html_e('Trade Credit Applications & Requests', 'custom-woo-dashboard'); ?>
				</span>
				<a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-credit-accounts')); ?>" class="page-title-action" style="font-weight: 600;">
					<?php esc_html_e('View Active Credit Accounts', 'custom-woo-dashboard'); ?>
				</a>
			</h1>

			<?php if ($notice === 'approved') : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Application approved successfully. Customer account has been provisioned with trade credit.', 'custom-woo-dashboard'); ?></p></div>
			<?php elseif ($notice === 'rejected') : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e('Application has been rejected.', 'custom-woo-dashboard'); ?></p></div>
			<?php elseif ($notice === 'limit_updated') : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Credit limit updated successfully.', 'custom-woo-dashboard'); ?></p></div>
			<?php elseif ($notice === 'invalid') : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e('Invalid or already processed application.', 'custom-woo-dashboard'); ?></p></div>
			<?php endif; ?>

			<!-- Status Filter Tabs & Search -->
			<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 12px; gap: 12px;">
				<ul class="subsubsub" style="margin: 0;">
					<li><a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications')); ?>" class="<?php echo $status_filter === '' ? 'current' : ''; ?>"><?php printf(esc_html__('All (%d)', 'custom-woo-dashboard'), $count_all); ?></a> |</li>
					<li><a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&status=pending')); ?>" class="<?php echo $status_filter === 'pending' ? 'current' : ''; ?>" style="color: #b45309; font-weight: <?php echo $count_pending > 0 ? '700' : 'normal'; ?>;"><?php printf(esc_html__('Pending Review (%d)', 'custom-woo-dashboard'), $count_pending); ?></a> |</li>
					<li><a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&status=approved')); ?>" class="<?php echo $status_filter === 'approved' ? 'current' : ''; ?>" style="color: #166534;"><?php printf(esc_html__('Approved (%d)', 'custom-woo-dashboard'), $count_approved); ?></a> |</li>
					<li><a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&status=rejected')); ?>" class="<?php echo $status_filter === 'rejected' ? 'current' : ''; ?>" style="color: #991b1b;"><?php printf(esc_html__('Rejected (%d)', 'custom-woo-dashboard'), $count_rejected); ?></a></li>
				</ul>

				<form method="get" action="" style="display: flex; gap: 6px;">
					<input type="hidden" name="page" value="cwd-v2-trade-applications" />
					<?php if ($status_filter) : ?>
						<input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>" />
					<?php endif; ?>
					<input type="search" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="<?php esc_attr_e('Search company, name, email...', 'custom-woo-dashboard'); ?>" style="padding: 4px 10px; border-radius: 4px; border: 1px solid #cbd5e1;" />
					<button type="submit" class="button"><?php esc_html_e('Search', 'custom-woo-dashboard'); ?></button>
				</form>
			</div>

			<table class="wp-list-table widefat fixed striped" style="border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
				<thead>
					<tr style="background: #f8fafc;">
						<th style="width: 50px; font-weight: 600;"><?php esc_html_e('ID', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 600;"><?php esc_html_e('Date', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 600;"><?php esc_html_e('Company', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 600;"><?php esc_html_e('Applicant', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 600;"><?php esc_html_e('Email', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 600;"><?php esc_html_e('Requested Facility', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 600;"><?php esc_html_e('Approved Limit', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 600; width: 110px;"><?php esc_html_e('Status', 'custom-woo-dashboard'); ?></th>
						<th style="width: 100px; font-weight: 600; text-align: right;"><?php esc_html_e('Action', 'custom-woo-dashboard'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($applications)) : ?>
						<tr><td colspan="9" style="text-align: center; padding: 30px; color: #64748b; font-size: 14px;"><?php esc_html_e('No trade applications found.', 'custom-woo-dashboard'); ?></td></tr>
					<?php else : ?>
						<?php foreach ($applications as $app) : ?>
							<tr>
								<td><strong>#<?php echo esc_html($app->id); ?></strong></td>
								<td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($app->created_at))); ?></td>
								<td>
									<strong><?php echo esc_html($app->company_name ?: '--'); ?></strong>
									<?php if ((int) $app->forminator_form_id === 0) : ?>
										<div style="font-size: 11px; color: #0284c7; font-weight: 600; margin-top: 2px;">
											<?php esc_html_e('Credit Increase Request', 'custom-woo-dashboard'); ?>
										</div>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html($app->applicant_name ?: '--'); ?></td>
								<td><a href="mailto:<?php echo esc_attr($app->applicant_email); ?>"><?php echo esc_html($app->applicant_email); ?></a></td>
								<td style="font-weight: 600;"><?php echo wp_kses_post(wc_price($app->requested_limit)); ?></td>
								<td>
									<?php if ($app->status === self::STATUS_APPROVED && (float) $app->approved_limit > 0) : ?>
										<strong style="color: #166534;"><?php echo wp_kses_post(wc_price($app->approved_limit)); ?></strong>
									<?php else : ?>
										<span style="color: #94a3b8;">--</span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									$badge_styles = array(
										self::STATUS_PENDING  => 'background: #fef3c7; color: #92400e; border: 1px solid #fcd34d;',
										self::STATUS_APPROVED => 'background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0;',
										self::STATUS_REJECTED => 'background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5;',
									);
									$style = $badge_styles[$app->status] ?? '';
									?>
									<span style="display: inline-block; padding: 2px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; <?php echo esc_attr($style); ?>">
										<?php echo esc_html(ucfirst($app->status)); ?>
									</span>
								</td>
								<td style="text-align: right;">
									<a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&view=' . $app->id)); ?>" class="button <?php echo $app->status === self::STATUS_PENDING ? 'button-primary' : 'button-secondary'; ?>" style="font-size: 12px;">
										<?php echo $app->status === self::STATUS_PENDING ? esc_html__('Review', 'custom-woo-dashboard') : esc_html__('View Details', 'custom-woo-dashboard'); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render a single application detail view with full Basic Details and action forms
	 *
	 * @param int $app_id Application ID
	 */
	private static function render_single_application($app_id)
	{
		global $wpdb;
		$table = self::get_table_name();
		$app   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $app_id));

		if (! $app) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__('Application not found.', 'custom-woo-dashboard') . '</p></div></div>';
			return;
		}

		$form_data     = json_decode((string) $app->form_data, true) ?: array();
		$basic_details = self::extract_application_basic_details($app, $form_data);
		$is_pending    = ($app->status === self::STATUS_PENDING);
		$reviewed_by   = $app->reviewed_by ? get_userdata($app->reviewed_by) : null;
		$linked_user   = $app->user_id ? get_userdata($app->user_id) : get_user_by('email', $app->applicant_email);
		$notice        = isset($_GET['notice']) ? sanitize_key($_GET['notice']) : '';
		?>
		<div class="wrap">
			<h1 style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
				<a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications')); ?>" style="text-decoration: none; color: #475569; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
					<span class="dashicons dashicons-arrow-left-alt2"></span> <?php esc_html_e('Back to Applications Queue', 'custom-woo-dashboard'); ?>
				</a>
				<?php if ($linked_user) : ?>
					<a href="<?php echo esc_url(get_edit_user_link($linked_user->ID)); ?>" class="page-title-action" target="_blank">
						<?php esc_html_e('Open Customer WordPress Profile', 'custom-woo-dashboard'); ?> &rarr;
					</a>
				<?php endif; ?>
			</h1>

			<?php if ($notice === 'approved') : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Application approved! Account has been provisioned with trade credit facility.', 'custom-woo-dashboard'); ?></p></div>
			<?php elseif ($notice === 'rejected') : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e('Application has been rejected.', 'custom-woo-dashboard'); ?></p></div>
			<?php elseif ($notice === 'limit_updated') : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Credit facility limit updated successfully.', 'custom-woo-dashboard'); ?></p></div>
			<?php endif; ?>

			<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; align-items: start;">

				<!-- Left Column: Details -->
				<div>
					<!-- Header Summary Card -->
					<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
						<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
							<div>
								<span style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #0284c7;">
									<?php echo (int) $app->forminator_form_id === 0 ? esc_html__('Credit Increase Request', 'custom-woo-dashboard') : esc_html__('Trade Credit Application', 'custom-woo-dashboard'); ?>
								</span>
								<h2 style="margin: 4px 0 2px; font-size: 22px; font-weight: 700; color: #0f172a;">
									<?php echo esc_html($app->company_name ?: ($app->applicant_name ?: 'Applicant #' . $app->id)); ?>
								</h2>
								<p style="margin: 0; font-size: 13px; color: #64748b;">
									<?php printf(esc_html__('Submitted on %s by %s (%s)', 'custom-woo-dashboard'), esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($app->created_at))), esc_html($app->applicant_name), esc_html($app->applicant_email)); ?>
								</p>
							</div>

							<?php
							$badge_styles = array(
								self::STATUS_PENDING  => 'background: #fef3c7; color: #92400e; border: 1px solid #fcd34d;',
								self::STATUS_APPROVED => 'background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0;',
								self::STATUS_REJECTED => 'background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5;',
							);
							$style = $badge_styles[$app->status] ?? '';
							?>
							<span style="display: inline-block; padding: 4px 14px; border-radius: 4px; font-size: 13px; font-weight: 700; <?php echo esc_attr($style); ?>">
								<?php echo esc_html(ucfirst($app->status)); ?>
							</span>
						</div>

						<!-- Quick metrics -->
						<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 16px; padding-top: 16px; border-top: 1px solid #f1f5f9;">
							<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px;">
								<span style="display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b;"><?php esc_html_e('Requested Facility', 'custom-woo-dashboard'); ?></span>
								<span style="font-size: 18px; font-weight: 700; color: #0f172a;"><?php echo wp_kses_post(wc_price($basic_details['credit_limit'])); ?></span>
							</div>
							<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px;">
								<span style="display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b;"><?php esc_html_e('Est. Monthly Spend', 'custom-woo-dashboard'); ?></span>
								<span style="font-size: 18px; font-weight: 700; color: #0f172a;">
									<?php echo is_numeric($basic_details['monthly_spend']) ? wp_kses_post(wc_price($basic_details['monthly_spend'])) : esc_html($basic_details['monthly_spend']); ?>
								</span>
							</div>
							<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px;">
								<span style="display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b;"><?php esc_html_e('Phone Number', 'custom-woo-dashboard'); ?></span>
								<span style="font-size: 15px; font-weight: 600; color: #0f172a;"><?php echo esc_html($app->phone ?: 'n/a'); ?></span>
							</div>
						</div>
					</div>

					<!-- 11 Basic Details Table Card (Standardized view matching customer application form) -->
					<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
						<h3 style="margin: 0 0 16px; font-size: 16px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
							<span class="dashicons dashicons-clipboard"></span> <?php esc_html_e('Basic Details', 'custom-woo-dashboard'); ?>
						</h3>

						<table class="widefat striped" style="border: 1px solid #e2e8f0; border-radius: 4px;">
							<tbody>
								<tr>
									<td style="width: 45%; font-weight: 600; color: #334155;"><?php esc_html_e('Credit Limit £', 'custom-woo-dashboard'); ?></td>
									<td style="font-weight: 700; color: #0f172a;"><?php echo is_numeric($basic_details['credit_limit']) ? esc_html(number_format((float) $basic_details['credit_limit'], 0)) : esc_html($basic_details['credit_limit']); ?></td>
								</tr>
								<tr>
									<td style="font-weight: 600; color: #334155;"><?php esc_html_e('Estimated monthly spend £', 'custom-woo-dashboard'); ?></td>
									<td style="font-weight: 600; color: #0f172a;"><?php echo esc_html($basic_details['monthly_spend']); ?></td>
								</tr>
								<tr>
									<td style="font-weight: 600; color: #334155;"><?php esc_html_e('Type of business Sector / Industry?', 'custom-woo-dashboard'); ?></td>
									<td style="color: #0f172a;"><?php echo esc_html($basic_details['business_sector']); ?></td>
								</tr>
								<tr>
									<td style="font-weight: 600; color: #334155;"><?php esc_html_e('How long has the company been trading?', 'custom-woo-dashboard'); ?></td>
									<td style="color: #0f172a;"><?php echo esc_html($basic_details['trading_duration']); ?></td>
								</tr>
								<tr>
									<td style="font-weight: 600; color: #334155;"><?php esc_html_e('Trade Reference 1, Name', 'custom-woo-dashboard'); ?></td>
									<td style="color: #0f172a;"><?php echo esc_html($basic_details['trade_ref_name']); ?></td>
								</tr>
								<tr>
									<td style="font-weight: 600; color: #334155;"><?php esc_html_e('Trade Reference 1, Address', 'custom-woo-dashboard'); ?></td>
									<td style="color: #0f172a;"><?php echo esc_html($basic_details['trade_ref_addr']); ?></td>
								</tr>
								<tr>
									<td style="font-weight: 600; color: #334155;"><?php esc_html_e('Trade Reference 1, Tel', 'custom-woo-dashboard'); ?></td>
									<td style="color: #0f172a;"><?php echo esc_html($basic_details['trade_ref_tel']); ?></td>
								</tr>
								<tr>
									<td style="font-weight: 600; color: #334155;"><?php esc_html_e('Company name', 'custom-woo-dashboard'); ?></td>
									<td style="font-weight: 700; color: #0f172a;"><?php echo esc_html($basic_details['company_name']); ?></td>
								</tr>
								<tr>
									<td style="font-weight: 600; color: #334155;"><?php esc_html_e('Company Reg Number', 'custom-woo-dashboard'); ?></td>
									<td style="color: #0f172a;"><?php echo esc_html($basic_details['company_reg']); ?></td>
								</tr>
								<tr>
									<td style="font-weight: 600; color: #334155;"><?php esc_html_e('VAT Reg Number', 'custom-woo-dashboard'); ?></td>
									<td style="color: #0f172a;"><?php echo esc_html($basic_details['vat_reg']); ?></td>
								</tr>
								<tr>
									<td style="font-weight: 600; color: #334155;"><?php esc_html_e('Parent Company Name', 'custom-woo-dashboard'); ?></td>
									<td style="color: #0f172a;"><?php echo esc_html($basic_details['parent_company']); ?></td>
								</tr>
							</tbody>
						</table>
					</div>

					<!-- Complete Raw Form Data -->
					<?php if (! empty($form_data)) : ?>
						<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; margin-bottom: 20px;">
							<h3 style="margin: 0 0 14px; font-size: 15px; font-weight: 700; color: #0f172a;">
								<?php esc_html_e('All Submitted Form Fields', 'custom-woo-dashboard'); ?>
							</h3>
							<table class="widefat fixed striped" style="border: 1px solid #e2e8f0;">
								<thead>
									<tr>
										<th style="width: 40%; font-weight: 600;"><?php esc_html_e('Field', 'custom-woo-dashboard'); ?></th>
										<th style="font-weight: 600;"><?php esc_html_e('Submitted Value', 'custom-woo-dashboard'); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($form_data as $fk => $fv) : ?>
										<tr>
											<td style="font-weight: 600; color: #475569;"><?php echo esc_html(ucwords(str_replace(array('_', '-'), ' ', (string) $fk))); ?></td>
											<td style="color: #0f172a;">
												<?php
												if (is_array($fv)) {
													echo esc_html(wp_json_encode($fv));
												} else {
													echo wp_kses_post(nl2br(esc_html((string) $fv)));
												}
												?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>

				<!-- Right Column: Actions & Decisions -->
				<div>
					<?php if ($is_pending) : ?>
						<!-- Approval Action Box -->
						<div style="background: #ffffff; border: 1px solid #bbf7d0; border-radius: 8px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
							<h3 style="margin: 0 0 14px; font-size: 16px; font-weight: 700; color: #166534; display: flex; align-items: center; gap: 8px;">
								<span class="dashicons dashicons-yes-alt" style="color: #16a34a;"></span>
								<?php esc_html_e('Approve Credit Facility', 'custom-woo-dashboard'); ?>
							</h3>

							<form method="post" action="">
								<?php wp_nonce_field('cwd_v2_trade_app_action', 'cwd_v2_trade_app_nonce'); ?>
								<input type="hidden" name="application_id" value="<?php echo esc_attr($app->id); ?>" />

								<div style="margin-bottom: 14px;">
									<label for="approved_limit" style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;">
										<?php esc_html_e('Approved Credit Limit (£)', 'custom-woo-dashboard'); ?>
									</label>
									<input type="number" step="0.01" min="0" name="approved_limit" id="approved_limit" value="<?php echo esc_attr(number_format((float) $app->requested_limit, 2, '.', '')); ?>" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 15px; font-weight: 700;" required />
								</div>

								<div style="margin-bottom: 14px;">
									<label for="payment_terms" style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;">
										<?php esc_html_e('Payment Terms', 'custom-woo-dashboard'); ?>
									</label>
									<input type="text" name="payment_terms" id="payment_terms" value="30 Days End of Month" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13px;" />
								</div>

								<div style="margin-bottom: 16px;">
									<label for="admin_note" style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;">
										<?php esc_html_e('Internal Admin Note (Optional)', 'custom-woo-dashboard'); ?>
									</label>
									<textarea name="admin_note" id="admin_note" rows="2" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13px;" placeholder="<?php esc_attr_e('e.g. Approved based on verified business registration', 'custom-woo-dashboard'); ?>"></textarea>
								</div>

								<button type="submit" name="cwd_v2_approve_application" class="button button-primary" style="background: #16a34a; border-color: #16a34a; width: 100%; padding: 8px 16px; font-weight: 700; font-size: 14px; height: auto;">
									<?php esc_html_e('Approve & Provision Account', 'custom-woo-dashboard'); ?>
								</button>
								<p class="description" style="margin-top: 8px; text-align: center;">
									<?php esc_html_e('Assigns credit_account role, sets credit limit, generates EWS trade account #, and sends confirmation email.', 'custom-woo-dashboard'); ?>
								</p>
							</form>
						</div>

						<!-- Rejection Action Box -->
						<div style="background: #ffffff; border: 1px solid #fca5a5; border-radius: 8px; padding: 20px;">
							<h3 style="margin: 0 0 12px; font-size: 15px; font-weight: 700; color: #991b1b; display: flex; align-items: center; gap: 8px;">
								<span class="dashicons dashicons-dismiss" style="color: #dc2626;"></span>
								<?php esc_html_e('Reject Application', 'custom-woo-dashboard'); ?>
							</h3>

							<form method="post" action="">
								<?php wp_nonce_field('cwd_v2_trade_app_action', 'cwd_v2_trade_app_nonce'); ?>
								<input type="hidden" name="application_id" value="<?php echo esc_attr($app->id); ?>" />

								<div style="margin-bottom: 14px;">
									<label for="reject_note" style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;">
										<?php esc_html_e('Reason for Rejection', 'custom-woo-dashboard'); ?>
									</label>
									<textarea name="admin_note" id="reject_note" rows="2" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13px;" placeholder="<?php esc_attr_e('e.g. Incomplete trading history or credit check unverified', 'custom-woo-dashboard'); ?>"></textarea>
								</div>

								<button type="submit" name="cwd_v2_reject_application" class="button" style="background: #fef2f2; border-color: #fca5a5; color: #991b1b; width: 100%; padding: 6px 16px; font-weight: 600; height: auto;" onclick="return confirm('<?php esc_attr_e('Are you sure you want to reject this trade credit application?', 'custom-woo-dashboard'); ?>');">
									<?php esc_html_e('Reject Application', 'custom-woo-dashboard'); ?>
								</button>
							</form>
						</div>

					<?php else : ?>
						<!-- Decision Record Card -->
						<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; margin-bottom: 20px;">
							<h3 style="margin: 0 0 14px; font-size: 16px; font-weight: 700; color: #0f172a;">
								<?php esc_html_e('Review Decision', 'custom-woo-dashboard'); ?>
							</h3>

							<table class="form-table" style="margin: 0;">
								<tr>
									<th style="padding: 6px 0; width: 120px;"><?php esc_html_e('Status', 'custom-woo-dashboard'); ?></th>
									<td style="padding: 6px 0;"><strong><?php echo esc_html(ucfirst($app->status)); ?></strong></td>
								</tr>
								<?php if ($app->status === self::STATUS_APPROVED) : ?>
									<tr>
										<th style="padding: 6px 0;"><?php esc_html_e('Approved Limit', 'custom-woo-dashboard'); ?></th>
										<td style="padding: 6px 0;"><strong style="color: #166534; font-size: 16px;"><?php echo wp_kses_post(wc_price($app->approved_limit)); ?></strong></td>
									</tr>
								<?php endif; ?>
								<?php if ($app->admin_note) : ?>
									<tr>
										<th style="padding: 6px 0;"><?php esc_html_e('Admin Note', 'custom-woo-dashboard'); ?></th>
										<td style="padding: 6px 0;"><?php echo esc_html($app->admin_note); ?></td>
									</tr>
								<?php endif; ?>
								<tr>
									<th style="padding: 6px 0;"><?php esc_html_e('Reviewed By', 'custom-woo-dashboard'); ?></th>
									<td style="padding: 6px 0;"><?php echo esc_html($reviewed_by ? $reviewed_by->display_name : 'Admin'); ?></td>
								</tr>
								<tr>
									<th style="padding: 6px 0;"><?php esc_html_e('Reviewed On', 'custom-woo-dashboard'); ?></th>
									<td style="padding: 6px 0;"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($app->reviewed_at))); ?></td>
								</tr>
							</table>

							<?php if ($app->status === self::STATUS_APPROVED) : ?>
								<!-- Modify limit form -->
								<div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #f1f5f9;">
									<h4 style="margin: 0 0 10px; font-size: 13px; font-weight: 700; color: #334155;">
										<?php esc_html_e('Adjust Approved Credit Limit', 'custom-woo-dashboard'); ?>
									</h4>
									<form method="post" action="">
										<?php wp_nonce_field('cwd_v2_trade_app_action', 'cwd_v2_trade_app_nonce'); ?>
										<input type="hidden" name="application_id" value="<?php echo esc_attr($app->id); ?>" />
										<div style="display: flex; gap: 8px; margin-bottom: 8px;">
											<input type="number" step="0.01" min="0" name="approved_limit" value="<?php echo esc_attr(number_format((float) $app->approved_limit, 2, '.', '')); ?>" style="flex: 1; border: 1px solid #cbd5e1; border-radius: 4px; padding: 6px 10px; font-weight: 700;" />
											<button type="submit" name="cwd_v2_update_limit" class="button button-secondary">
												<?php esc_html_e('Update', 'custom-woo-dashboard'); ?>
											</button>
										</div>
									</form>
								</div>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Render the Credit Accounts dashboard (Complete replacement for third-party Credits plugin)
	 */
	public static function render_credit_accounts_page()
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die(__('You do not have permission to access this page.', 'custom-woo-dashboard'));
		}

		$search_query = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
		$notice       = isset($_GET['notice']) ? sanitize_key($_GET['notice']) : '';

		// Query all users who have credit_account role or a positive credit limit
		$user_args = array(
			'role__in' => array('credit_account'),
			'number'   => 150,
			'search'   => $search_query ? '*' . $search_query . '*' : '',
		);

		$credit_users = get_users($user_args);

		// Also discover any users with _credit_limit > 0 that might not have role yet
		if (empty($search_query)) {
			global $wpdb;
			$meta_uids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_credit_limit' AND CAST(meta_value AS DECIMAL(10,2)) > 0");
			if (! empty($meta_uids)) {
				$existing_ids = wp_list_pluck($credit_users, 'ID');
				foreach ($meta_uids as $muid) {
					if (! in_array((int) $muid, $existing_ids, true)) {
						$u = get_userdata((int) $muid);
						if ($u) {
							$credit_users[] = $u;
						}
					}
				}
			}
		}

		// Calculate overview metrics
		$total_accounts    = count($credit_users);
		$total_credit_limit = 0.0;
		$total_balance_owed = 0.0;

		foreach ($credit_users as $u) {
			$lim = class_exists('CWD_V2_Credit_Logic') ? CWD_V2_Credit_Logic::get_user_credit_limit($u->ID) : (float) get_user_meta($u->ID, '_credit_limit', true);
			$bal = class_exists('CWD_V2_Credit_Logic') ? CWD_V2_Credit_Logic::get_user_credit_balance($u->ID) : (float) get_user_meta($u->ID, '_credit_balance', true);
			$total_credit_limit += $lim;
			$total_balance_owed += $bal;
		}

		$total_available = max(0, $total_credit_limit - $total_balance_owed);
		?>
		<div class="wrap">
			<h1 style="display: flex; align-items: center; justify-content: space-between; font-weight: 700; color: #0f172a; margin-bottom: 20px;">
				<span style="display: flex; align-items: center; gap: 10px;">
					<span class="dashicons dashicons-businessman" style="font-size: 26px; width: 26px; height: 26px;"></span>
					<?php esc_html_e('Active Trade Credit Accounts', 'custom-woo-dashboard'); ?>
				</span>
				<a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications')); ?>" class="page-title-action">
					&larr; <?php esc_html_e('Back to Applications Queue', 'custom-woo-dashboard'); ?>
				</a>
			</h1>

			<?php if ($notice === 'limit_updated') : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Credit limit updated successfully.', 'custom-woo-dashboard'); ?></p></div>
			<?php elseif ($notice === 'user_added') : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Trade credit account created successfully.', 'custom-woo-dashboard'); ?></p></div>
			<?php elseif ($notice === 'invalid_email') : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e('Please enter a valid customer email address.', 'custom-woo-dashboard'); ?></p></div>
			<?php endif; ?>

			<!-- Overview Metrics Cards -->
			<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px;">
				<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
					<span style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em;"><?php esc_html_e('Total Credit Accounts', 'custom-woo-dashboard'); ?></span>
					<span style="font-size: 24px; font-weight: 800; color: #0f172a; margin-top: 4px; display: block;"><?php echo esc_html($total_accounts); ?></span>
				</div>
				<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
					<span style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #166534; letter-spacing: 0.05em;"><?php esc_html_e('Total Credit Facility', 'custom-woo-dashboard'); ?></span>
					<span style="font-size: 24px; font-weight: 800; color: #166534; margin-top: 4px; display: block;"><?php echo wp_kses_post(wc_price($total_credit_limit)); ?></span>
				</div>
				<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
					<span style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #991b1b; letter-spacing: 0.05em;"><?php esc_html_e('Total Outstanding Balance', 'custom-woo-dashboard'); ?></span>
					<span style="font-size: 24px; font-weight: 800; color: #991b1b; margin-top: 4px; display: block;"><?php echo wp_kses_post(wc_price($total_balance_owed)); ?></span>
				</div>
				<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
					<span style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #0284c7; letter-spacing: 0.05em;"><?php esc_html_e('Total Available Credit', 'custom-woo-dashboard'); ?></span>
					<span style="font-size: 24px; font-weight: 800; color: #0284c7; margin-top: 4px; display: block;"><?php echo wp_kses_post(wc_price($total_available)); ?></span>
				</div>
			</div>

			<!-- Search and Filter Bar -->
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
				<form method="get" action="" style="display: flex; gap: 8px;">
					<input type="hidden" name="page" value="cwd-v2-credit-accounts" />
					<input type="search" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="<?php esc_attr_e('Search credit customer...', 'custom-woo-dashboard'); ?>" style="padding: 5px 12px; border-radius: 4px; border: 1px solid #cbd5e1; width: 280px;" />
					<button type="submit" class="button"><?php esc_html_e('Search', 'custom-woo-dashboard'); ?></button>
				</form>
			</div>

			<!-- Customer Accounts Table -->
			<table class="wp-list-table widefat fixed striped" style="border: 1px solid #e2e8f0; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
				<thead>
					<tr style="background: #f8fafc;">
						<th style="font-weight: 700;"><?php esc_html_e('Customer / Username', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700;"><?php esc_html_e('Company Name', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700;"><?php esc_html_e('Trade Account #', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700;"><?php esc_html_e('Credit Limit', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700;"><?php esc_html_e('Outstanding Balance', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700;"><?php esc_html_e('Available Credit', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700;"><?php esc_html_e('Payment Terms', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700; text-align: right; width: 200px;"><?php esc_html_e('Actions', 'custom-woo-dashboard'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($credit_users)) : ?>
						<tr><td colspan="8" style="text-align: center; padding: 30px; color: #64748b;"><?php esc_html_e('No credit account customers found.', 'custom-woo-dashboard'); ?></td></tr>
					<?php else : ?>
						<?php foreach ($credit_users as $u) : ?>
							<?php
							$uid      = $u->ID;
							$limit    = class_exists('CWD_V2_Credit_Logic') ? CWD_V2_Credit_Logic::get_user_credit_limit($uid) : (float) get_user_meta($uid, '_credit_limit', true);
							$balance  = class_exists('CWD_V2_Credit_Logic') ? CWD_V2_Credit_Logic::get_user_credit_balance($uid) : (float) get_user_meta($uid, '_credit_balance', true);
							$avail    = max(0, $limit - $balance);
							$company  = get_user_meta($uid, 'billing_company', true) ?: (get_user_meta($uid, 'ews_company_name', true) ?: '--');
							$acc_num  = get_user_meta($uid, 'ews_account_number', true) ?: 'EWS-T' . str_pad($uid, 4, '0', STR_PAD_LEFT);
							$terms    = get_user_meta($uid, '_credit_payment_terms', true) ?: '30 Days EOM';
							?>
							<tr>
								<td>
									<strong><?php echo esc_html($u->display_name ?: $u->user_login); ?></strong>
									<div style="font-size: 12px; color: #64748b; margin-top: 2px;">
										<a href="mailto:<?php echo esc_attr($u->user_email); ?>"><?php echo esc_html($u->user_email); ?></a>
									</div>
								</td>
								<td><strong><?php echo esc_html($company); ?></strong></td>
								<td><code style="font-weight: 600; color: #0284c7;"><?php echo esc_html($acc_num); ?></code></td>
								<td style="font-weight: 700; color: #166534;"><?php echo wp_kses_post(wc_price($limit)); ?></td>
								<td style="font-weight: 600; color: <?php echo $balance > 0 ? '#991b1b' : '#64748b'; ?>;"><?php echo wp_kses_post(wc_price($balance)); ?></td>
								<td style="font-weight: 700; color: #0f172a;"><?php echo wp_kses_post(wc_price($avail)); ?></td>
								<td><span style="font-size: 12px; color: #475569;"><?php echo esc_html($terms); ?></span></td>
								<td style="text-align: right;">
									<details style="display: inline-block; position: relative;">
										<summary class="button button-small" style="font-size: 12px; cursor: pointer;">
											<?php esc_html_e('Adjust Limit', 'custom-woo-dashboard'); ?>
										</summary>
										<div style="position: absolute; right: 0; top: 100%; margin-top: 4px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px; z-index: 100; box-shadow: 0 4px 12px rgba(0,0,0,0.15); width: 220px; text-align: left;">
											<form method="post" action="">
												<?php wp_nonce_field('cwd_v2_credit_accounts_action', 'cwd_v2_credit_nonce'); ?>
												<input type="hidden" name="user_id" value="<?php echo esc_attr($uid); ?>" />
												<label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 4px;">
													<?php esc_html_e('New Limit (£):', 'custom-woo-dashboard'); ?>
												</label>
												<input type="number" step="0.01" min="0" name="new_credit_limit" value="<?php echo esc_attr(number_format((float) $limit, 2, '.', '')); ?>" style="width: 100%; margin-bottom: 8px; font-weight: 700;" required />
												<button type="submit" name="cwd_v2_adjust_user_credit_limit" class="button button-primary button-small" style="width: 100%;">
													<?php esc_html_e('Save New Limit', 'custom-woo-dashboard'); ?>
												</button>
											</form>
										</div>
									</details>
									<a href="<?php echo esc_url(get_edit_user_link($uid)); ?>" class="button button-small" style="font-size: 12px; margin-left: 4px;" target="_blank">
										<?php esc_html_e('Profile', 'custom-woo-dashboard'); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Add Credit Account Manually -->
			<div style="margin-top: 32px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
				<h3 style="margin: 0 0 8px; font-size: 16px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
					<span class="dashicons dashicons-plus-alt2" style="color: #0284c7;"></span>
					<?php esc_html_e('Manually Provision Trade Credit Account', 'custom-woo-dashboard'); ?>
				</h3>
				<p style="margin: 0 0 16px; font-size: 13px; color: #64748b;">
					<?php esc_html_e('Activate trade credit facility directly for an existing customer or new applicant email.', 'custom-woo-dashboard'); ?>
				</p>

				<form method="post" action="" style="display: grid; grid-template-columns: repeat(3, 1fr) auto; gap: 14px; align-items: end;">
					<?php wp_nonce_field('cwd_v2_credit_accounts_action', 'cwd_v2_credit_nonce'); ?>
					<div>
						<label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 4px;">
							<?php esc_html_e('Customer Email Address', 'custom-woo-dashboard'); ?>
						</label>
						<input type="email" name="user_email" placeholder="customer@company.co.uk" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 4px; padding: 6px 10px;" required />
					</div>
					<div>
						<label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 4px;">
							<?php esc_html_e('Company Name (Optional)', 'custom-woo-dashboard'); ?>
						</label>
						<input type="text" name="company_name" placeholder="Business Name Ltd" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 4px; padding: 6px 10px;" />
					</div>
					<div>
						<label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 4px;">
							<?php esc_html_e('Credit Facility Limit (£)', 'custom-woo-dashboard'); ?>
						</label>
						<input type="number" step="0.01" min="0" name="credit_limit" value="1000.00" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 4px; padding: 6px 10px; font-weight: 700;" required />
					</div>
					<div>
						<button type="submit" name="cwd_v2_add_manual_credit_user" class="button button-primary" style="padding: 6px 20px; font-weight: 600; height: auto;">
							<?php esc_html_e('Provision Credit Account', 'custom-woo-dashboard'); ?>
						</button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Settings Page
	 */
	public static function render_settings_page()
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die(__('You do not have permission to access this page.', 'custom-woo-dashboard'));
		}
		?>
		<div class="wrap">
			<h1 style="font-weight: 700; color: #0f172a; margin-bottom: 20px;">
				<?php esc_html_e('Trade & Credit Settings', 'custom-woo-dashboard'); ?>
			</h1>

			<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; max-width: 800px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
				<form method="post" action="options.php">
					<?php
					settings_fields('cwd_v2_trade_settings');
					do_settings_sections('cwd_v2_trade_settings');
					$form_id       = get_option('cwd_v2_trade_application_form_id', 0);
					$default_limit = get_option('cwd_v2_default_credit_limit', 500);
					$default_terms = get_option('cwd_v2_default_payment_terms', '30 Days End of Month');
					?>
					<table class="form-table" style="margin: 0;">
						<tr>
							<th scope="row"><label for="cwd_v2_trade_application_form_id"><?php esc_html_e('Forminator Form ID', 'custom-woo-dashboard'); ?></label></th>
							<td>
								<input type="number" name="cwd_v2_trade_application_form_id" id="cwd_v2_trade_application_form_id" value="<?php echo esc_attr($form_id); ?>" class="small-text" />
								<p class="description"><?php esc_html_e('Leave 0 to automatically capture all Forminator form submissions containing applicant email and business details.', 'custom-woo-dashboard'); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="cwd_v2_default_credit_limit"><?php esc_html_e('Default Credit Limit (£)', 'custom-woo-dashboard'); ?></label></th>
							<td>
								<input type="number" step="0.01" name="cwd_v2_default_credit_limit" id="cwd_v2_default_credit_limit" value="<?php echo esc_attr($default_limit); ?>" class="regular-text" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="cwd_v2_default_payment_terms"><?php esc_html_e('Default Payment Terms', 'custom-woo-dashboard'); ?></label></th>
							<td>
								<input type="text" name="cwd_v2_default_payment_terms" id="cwd_v2_default_payment_terms" value="<?php echo esc_attr($default_terms); ?>" class="regular-text" />
							</td>
						</tr>
					</table>
					<?php submit_button(__('Save Settings', 'custom-woo-dashboard')); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Register settings
	 */
	public static function register_settings()
	{
		register_setting('cwd_v2_trade_settings', 'cwd_v2_trade_application_form_id', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		));
		register_setting('cwd_v2_trade_settings', 'cwd_v2_default_credit_limit', array(
			'type'              => 'number',
			'sanitize_callback' => 'floatval',
			'default'           => 500,
		));
		register_setting('cwd_v2_trade_settings', 'cwd_v2_default_payment_terms', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '30 Days End of Month',
		));
	}
}
