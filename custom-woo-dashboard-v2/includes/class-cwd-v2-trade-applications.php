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

	private static $table_checked = false;

	/**
	 * Ensure table exists in database before queries.
	 */
	public static function ensure_table_exists()
	{
		if (self::$table_checked) {
			return;
		}
		global $wpdb;
		$table_name = self::get_table_name();
		if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
			self::create_table();
		}
		self::$table_checked = true;
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

		// 1. Dedicated Top-Level Menu "Trade & Credit" (Opens User-Based Dashboard)
		add_menu_page(
			__('Trade & Credit Management', 'custom-woo-dashboard'),
			$menu_title,
			'manage_woocommerce',
			'cwd-v2-trade-applications',
			array(__CLASS__, 'render_admin_page'),
			'dashicons-money-alt',
			56
		);

		// Submenu 1: Primary User-Based Trade Accounts Dashboard
		add_submenu_page(
			'cwd-v2-trade-applications',
			__('Trade Accounts & Credit', 'custom-woo-dashboard'),
			__('Trade Accounts & Credit', 'custom-woo-dashboard'),
			'manage_woocommerce',
			'cwd-v2-trade-applications',
			array(__CLASS__, 'render_admin_page')
		);

		// Submenu 2: Applications & Submissions Queue
		add_submenu_page(
			'cwd-v2-trade-applications',
			__('Applications Queue', 'custom-woo-dashboard'),
			__('Applications Queue', 'custom-woo-dashboard'),
			'manage_woocommerce',
			'cwd-v2-trade-queue',
			array(__CLASS__, 'render_applications_queue_page')
		);

		// Submenu 3: Settings
		add_submenu_page(
			'cwd-v2-trade-applications',
			__('Settings', 'custom-woo-dashboard'),
			__('Settings', 'custom-woo-dashboard'),
			'manage_woocommerce',
			'cwd-v2-trade-settings',
			array(__CLASS__, 'render_settings_page')
		);

		// Alias submenu for legacy credit-accounts URL so any bookmarked links work seamlessly
		add_submenu_page(
			null,
			__('Credit Accounts', 'custom-woo-dashboard'),
			__('Credit Accounts', 'custom-woo-dashboard'),
			'manage_woocommerce',
			'cwd-v2-credit-accounts',
			array(__CLASS__, 'render_admin_page')
		);

		// 2. Also register under WooCommerce for convenience
		add_submenu_page(
			'woocommerce',
			__('Trade & Credit Accounts', 'custom-woo-dashboard'),
			__('Trade & Credit Accounts', 'custom-woo-dashboard'),
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

		// Update Trade Applications top-level and Queue submenus
		if (isset($submenu['cwd-v2-trade-applications'])) {
			foreach ($submenu['cwd-v2-trade-applications'] as &$item) {
				if (isset($item[2]) && ($item[2] === 'cwd-v2-trade-applications' || $item[2] === 'cwd-v2-trade-queue')) {
					$item[0] .= sprintf(
						' <span class="awaiting-mod update-plugins count-%d"><span class="pending-count">%d</span></span>',
						$pending,
						$pending
					);
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

		// Synchronize credit plugin data into database
		if (isset($_GET['action']) && 'sync_credits' === $_GET['action'] && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'cwd_v2_sync_credits')) {
			if (class_exists('CWD_V2_Credits_Bridge')) {
				CWD_V2_Credits_Bridge::repair_and_sync_all();
			}
			wp_safe_redirect(admin_url('admin.php?page=cwd-v2-trade-applications&notice=synced'));
			exit;
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

		wp_redirect(admin_url('admin.php?page=cwd-v2-trade-applications&tab=accounts&notice=limit_updated'));
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
			wp_redirect(admin_url('admin.php?page=cwd-v2-trade-applications&tab=provision&notice=invalid_email'));
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
				wp_redirect(admin_url('admin.php?page=cwd-v2-trade-applications&tab=provision&notice=create_error'));
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

		wp_redirect(admin_url('admin.php?page=cwd-v2-trade-applications&tab=accounts&notice=user_added'));
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
	 * Discover all trade credit customers, pre-registered credit users, and applicants.
	 * Consolidates data so each user is represented exactly once without clustering.
	 *
	 * @param string $search Search query
	 * @param string $filter Filter type ('all', 'active', 'pending', 'overdue')
	 * @return array Array containing 'accounts' (filtered list) and 'totals' (aggregate metrics)
	 */
	public static function get_all_trade_accounts($search = '', $filter = '')
	{
		global $wpdb;
		$user_ids   = array();
		$apps_table = self::get_table_name();
		$inv_table  = $wpdb->prefix . 'cwd_v2_invoices';

		self::ensure_table_exists();

		// 1. All users with role 'credit_account'
		$role_users = get_users(array('role' => 'credit_account', 'fields' => 'ID'));
		foreach ($role_users as $uid) {
			$user_ids[(int) $uid] = (int) $uid;
		}

		// 2. Discover users with approved applications or recorded in trade applications table
		$has_app_tbl = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $apps_table)) === $apps_table);
		if ($has_app_tbl) {
			$app_uids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$apps_table} WHERE user_id IS NOT NULL AND user_id > 0");
			if (! empty($app_uids)) {
				foreach ($app_uids as $uid) {
					$user_ids[(int) $uid] = (int) $uid;
				}
			}
		}

		// 3. Discover users with positive credit limit in usermeta
		$meta_uids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ('_credit_limit', 'credit_limit', '_credits_limit', 'fpc_credit_limit') AND CAST(meta_value AS DECIMAL(10,2)) > 0");
		if (! empty($meta_uids)) {
			foreach ($meta_uids as $uid) {
				$user_ids[(int) $uid] = (int) $uid;
			}
		}

		// 4. Authors of credit posts in wc_cs_credits (single fast indexed query)
		$cpt_uids = $wpdb->get_col("SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type IN ('wc_cs_credits', 'credits', 'fpc_credits') AND post_author > 0");
		if (! empty($cpt_uids)) {
			foreach ($cpt_uids as $uid) {
				$user_ids[(int) $uid] = (int) $uid;
			}
		}

		$user_ids = array_unique(array_filter($user_ids));

		// Pre-fetch applications data in ONE single fast batch query
		$pending_by_uid = array();
		$pending_by_email = array();
		$latest_app_company_by_uid = array();
		if ($has_app_tbl) {
			$app_rows = $wpdb->get_results("SELECT id, user_id, applicant_email, company_name, status, requested_limit, created_at, forminator_form_id FROM {$apps_table} ORDER BY id DESC LIMIT 500");
			if (! empty($app_rows)) {
				foreach ($app_rows as $ar) {
					if ($ar->user_id > 0 && ! isset($latest_app_company_by_uid[$ar->user_id]) && ! empty($ar->company_name)) {
						$latest_app_company_by_uid[$ar->user_id] = $ar->company_name;
					}
					if ($ar->status === self::STATUS_PENDING) {
						if ($ar->user_id > 0 && ! isset($pending_by_uid[$ar->user_id])) {
							$pending_by_uid[$ar->user_id] = $ar;
						}
						if (! empty($ar->applicant_email) && ! isset($pending_by_email[strtolower($ar->applicant_email)])) {
							$pending_by_email[strtolower($ar->applicant_email)] = $ar;
						}
					}
				}
			}
		}

		// Pre-fetch earliest due dates from invoices in ONE single batch query
		$has_inv_tbl = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $inv_table)) === $inv_table);
		$due_dates_by_uid = array();
		if ($has_inv_tbl) {
			$due_rows = $wpdb->get_results(
				"SELECT user_id, MIN(due_date) as earliest_due FROM {$inv_table}
				 WHERE status IN ('unpaid', 'overdue', 'partially_paid') AND due_date IS NOT NULL
				 GROUP BY user_id"
			);
			if (! empty($due_rows)) {
				foreach ($due_rows as $dr) {
					$due_dates_by_uid[(int) $dr->user_id] = $dr->earliest_due;
				}
			}
		}

		$all_accounts = array();
		$dummy_companies = array('5+', '100', 'trade / wholesale', 'trade', 'wholesale', 'n/a', 'na', 'none', 'null', '--', '-', '0');

		// Build user-based account objects with ZERO SQL queries in loop (all cached by WP user meta)
		foreach ($user_ids as $uid) {
			$user = get_userdata($uid);
			if (! $user) {
				continue;
			}

			$limit   = class_exists('CWD_V2_Credit_Logic') ? CWD_V2_Credit_Logic::get_user_credit_limit($uid) : (float) get_user_meta($uid, '_credit_limit', true);
			$balance = class_exists('CWD_V2_Credit_Logic') ? CWD_V2_Credit_Logic::get_user_credit_balance($uid) : (float) get_user_meta($uid, '_credit_balance', true);

			if ($limit > 0) {
				if (! in_array('credit_account', (array) $user->roles, true)) {
					$user->add_role('credit_account');
				}
			}

			$available = max(0, $limit - $balance);

			// Clean company name
			$company = get_user_meta($uid, 'billing_company', true) ?: (get_user_meta($uid, 'ews_company_name', true) ?: '');
			if (in_array(strtolower(trim((string) $company)), $dummy_companies, true)) {
				$company = '';
			}
			if (empty($company) && isset($latest_app_company_by_uid[$uid])) {
				$cand = $latest_app_company_by_uid[$uid];
				if (! in_array(strtolower(trim((string) $cand)), $dummy_companies, true)) {
					$company = $cand;
				}
			}

			$phone   = get_user_meta($uid, 'billing_phone', true) ?: '';
			$acc_num = get_user_meta($uid, 'ews_account_number', true) ?: ('EWS-T' . str_pad($uid, 4, '0', STR_PAD_LEFT));
			$terms   = get_user_meta($uid, '_credit_payment_terms', true) ?: '30 Days EOM';

			// Due date from pre-fetched batch
			$earliest_due = $due_dates_by_uid[$uid] ?? null;
			$is_overdue   = false;
			if ($earliest_due) {
				$due_ts = strtotime($earliest_due);
				if ($due_ts && $due_ts < current_time('timestamp')) {
					$is_overdue = true;
				}
			}

			// Pending request from pre-fetched batch
			$pending_req = $pending_by_uid[$uid] ?? ($pending_by_email[strtolower($user->user_email)] ?? null);

			$account_type = (in_array('credit_account', (array) $user->roles, true) || $limit > 0) ? __('Trade Account', 'custom-woo-dashboard') : __('Standard User', 'custom-woo-dashboard');
			if ($pending_req && $limit <= 0) {
				$account_type = __('Trade Applicant', 'custom-woo-dashboard');
			}

			$status = 'active';
			if ($pending_req) {
				$status = 'pending';
			} elseif ($is_overdue) {
				$status = 'overdue';
			} elseif ($limit <= 0) {
				$status = 'inactive';
			}

			$all_accounts['user_' . $uid] = array(
				'key'              => 'user_' . $uid,
				'type'             => 'user',
				'user_id'          => $uid,
				'username'         => $user->user_login,
				'name'             => $user->display_name ?: $user->user_login,
				'email'            => $user->user_email,
				'phone'            => $phone,
				'company'          => $company ?: '--',
				'account_number'   => $acc_num,
				'account_type'     => $account_type,
				'terms'            => $terms,
				'credit_limit'     => (float) $limit,
				'credit_used'      => (float) $balance,
				'credit_available' => (float) $available,
				'due_date'         => $earliest_due,
				'is_overdue'       => $is_overdue,
				'pending_request'  => $pending_req,
				'status'           => $status,
			);
		}

		// Also discover any guest trade applications (unregistered email submissions)
		if ($has_app_tbl) {
			$guest_apps = $wpdb->get_results("SELECT * FROM {$apps_table} WHERE (user_id IS NULL OR user_id = 0) ORDER BY created_at DESC");
			foreach ($guest_apps as $gapp) {
				$existing_u = get_user_by('email', $gapp->applicant_email);
				if ($existing_u) {
					continue;
				}
				$key = 'guest_' . $gapp->id;
				$all_accounts[$key] = array(
					'key'              => $key,
					'type'             => 'guest_applicant',
					'user_id'          => 0,
					'username'         => 'guest',
					'name'             => $gapp->applicant_name ?: 'Applicant #' . $gapp->id,
					'email'            => $gapp->applicant_email,
					'phone'            => $gapp->phone ?: '',
					'company'          => $gapp->company_name ?: '--',
					'account_number'   => 'Pending',
					'account_type'     => __('New Applicant', 'custom-woo-dashboard'),
					'terms'            => '30 Days EOM',
					'credit_limit'     => 0.0,
					'credit_used'      => 0.0,
					'credit_available' => 0.0,
					'due_date'         => null,
					'is_overdue'       => false,
					'pending_request'  => ($gapp->status === self::STATUS_PENDING) ? $gapp : null,
					'status'           => $gapp->status,
					'raw_app'          => $gapp,
				);
			}
		}

		// Compute metrics across all discovered accounts
		$totals = array(
			'total_accounts' => count($all_accounts),
			'total_facility' => 0.0,
			'total_used'     => 0.0,
			'total_avail'    => 0.0,
			'count_active'   => 0,
			'count_pending'  => 0,
			'count_overdue'  => 0,
		);

		foreach ($all_accounts as $acc) {
			$totals['total_facility'] += $acc['credit_limit'];
			$totals['total_used']     += $acc['credit_used'];
			if ($acc['credit_limit'] > 0) {
				$totals['count_active']++;
			}
			if (! empty($acc['pending_request'])) {
				$totals['count_pending']++;
			}
			if (! empty($acc['is_overdue'])) {
				$totals['count_overdue']++;
			}
		}
		$totals['total_avail'] = max(0, $totals['total_facility'] - $totals['total_used']);

		// Filter accounts
		$filtered_accounts = array();
		foreach ($all_accounts as $acc) {
			// Tab Filter
			if ($filter === 'active' && $acc['credit_limit'] <= 0) {
				continue;
			}
			if ($filter === 'pending' && empty($acc['pending_request']) && $acc['status'] !== 'pending') {
				continue;
			}
			if ($filter === 'overdue' && empty($acc['is_overdue'])) {
				continue;
			}

			// Search Filter
			if (! empty($search)) {
				$s = strtolower($search);
				$match = (
					stripos($acc['name'], $s) !== false ||
					stripos($acc['username'], $s) !== false ||
					stripos($acc['email'], $s) !== false ||
					stripos($acc['company'], $s) !== false ||
					stripos($acc['phone'], $s) !== false ||
					stripos($acc['account_number'], $s) !== false
				);
				if (! $match) {
					continue;
				}
			}

			$filtered_accounts[] = $acc;
		}

		return array(
			'accounts' => $filtered_accounts,
			'totals'   => $totals,
		);
	}

	/**
	 * Primary Render handler for Admin Page
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

		$tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'accounts';
		// If tab is one of the filter keys (e.g. active, pending, overdue), route to accounts tab
		if (in_array($tab, array('all', 'active', 'pending', 'overdue'), true)) {
			$tab = 'accounts';
		}

		self::render_tabbed_dashboard($tab);
	}

	/**
	 * Render the unified Section & Tabs Dashboard
	 *
	 * @param string $active_tab 'accounts' | 'queue' | 'provision' | 'settings'
	 */
	public static function render_tabbed_dashboard($active_tab = 'accounts')
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die(__('You do not have permission to access this page.', 'custom-woo-dashboard'));
		}

		self::ensure_table_exists();

		$allowed_tabs = array('accounts', 'queue', 'provision', 'settings');
		if (! in_array($active_tab, $allowed_tabs, true)) {
			$active_tab = 'accounts';
		}

		// Notice handling
		$notice = isset($_GET['notice']) ? sanitize_key($_GET['notice']) : '';
		if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
			$notice = 'settings_saved';
		}

		// Global counts for tab badges
		$pending_count = self::get_pending_count();
		$trade_data    = self::get_all_trade_accounts('', 'all');
		$total_accs    = $trade_data['totals']['total_accounts'] ?? 0;
		?>
		<div class="wrap" style="max-width: 1400px; margin-top: 18px;">
			<!-- Dashboard Header -->
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 14px;">
				<div>
					<h1 style="display: flex; align-items: center; gap: 10px; margin: 0 0 4px; font-size: 24px; font-weight: 700; color: #0f172a;">
						<span class="dashicons dashicons-money-alt" style="font-size: 28px; width: 28px; height: 28px; color: #0284c7;"></span>
						<?php esc_html_e('Trade & Credit Management', 'custom-woo-dashboard'); ?>
					</h1>
					<p style="margin: 0; color: #64748b; font-size: 13px;">
						<?php esc_html_e('Manage trade accounts, customer credit facilities, Forminator trade applications, and provisioning in one unified dashboard.', 'custom-woo-dashboard'); ?>
					</p>
				</div>
				<div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
					<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=cwd-v2-trade-applications&action=sync_credits'), 'cwd_v2_sync_credits')); ?>" class="button" style="font-weight: 600; display: inline-flex; align-items: center; gap: 6px; border-color: #cbd5e1; background: #ffffff;" title="<?php esc_attr_e('Synchronize and import all credit accounts, limits, and outstanding balances from the credit plugin into WordPress database', 'custom-woo-dashboard'); ?>">
						<span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px;"></span>
						<?php esc_html_e('Sync Credit Plugin', 'custom-woo-dashboard'); ?>
					</a>
					<?php if ($active_tab !== 'provision') : ?>
						<a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&tab=provision')); ?>" class="button button-primary" style="font-weight: 600; background: #0284c7; border-color: #0284c7; display: inline-flex; align-items: center; gap: 6px;">
							<span class="dashicons dashicons-plus-alt2" style="font-size: 16px; width: 16px; height: 16px;"></span>
							<?php esc_html_e('Provision Trade Account', 'custom-woo-dashboard'); ?>
						</a>
					<?php endif; ?>
					<?php if ($active_tab !== 'queue' && $pending_count > 0) : ?>
						<a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&tab=queue&status=pending')); ?>" class="button" style="font-weight: 600; border-color: #f59e0b; color: #b45309; background: #fffbeb; display: inline-flex; align-items: center; gap: 6px;">
							<span class="dashicons dashicons-bell" style="font-size: 16px; width: 16px; height: 16px;"></span>
							<?php printf(esc_html__('Review Pending (%d)', 'custom-woo-dashboard'), $pending_count); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>

			<!-- Flash Notices -->
			<?php if ($notice === 'synced') : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Credit accounts, limits, and outstanding balances successfully synchronized into WordPress database.', 'custom-woo-dashboard'); ?></p></div>
			<?php elseif ($notice === 'limit_updated') : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Credit facility limit updated successfully.', 'custom-woo-dashboard'); ?></p></div>
			<?php elseif ($notice === 'approved') : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Application approved and trade credit account provisioned.', 'custom-woo-dashboard'); ?></p></div>
			<?php elseif ($notice === 'rejected') : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e('Application rejected.', 'custom-woo-dashboard'); ?></p></div>
			<?php elseif ($notice === 'user_added') : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Customer provisioned with trade credit facility successfully.', 'custom-woo-dashboard'); ?></p></div>
			<?php elseif ($notice === 'invalid_email') : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e('Please enter a valid customer email address.', 'custom-woo-dashboard'); ?></p></div>
			<?php elseif ($notice === 'settings_saved') : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved successfully.', 'custom-woo-dashboard'); ?></p></div>
			<?php endif; ?>

			<!-- Tab Navigation Bar -->
			<nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom: 22px; border-bottom: 1px solid #cbd5e1;">
				<a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&tab=accounts')); ?>" class="nav-tab <?php echo $active_tab === 'accounts' ? 'nav-tab-active' : ''; ?>" style="font-size: 13px; font-weight: 600; padding: 7px 16px; display: inline-flex; align-items: center; gap: 6px;">
					<span class="dashicons dashicons-businessman" style="font-size: 18px; width: 18px; height: 18px;"></span>
					<?php esc_html_e('Trade Accounts Directory', 'custom-woo-dashboard'); ?>
					<span style="background: <?php echo $active_tab === 'accounts' ? '#0284c7' : '#e2e8f0'; ?>; color: <?php echo $active_tab === 'accounts' ? '#ffffff' : '#334155'; ?>; border-radius: 10px; padding: 1px 7px; font-size: 11px; font-weight: 700; margin-left: 2px;">
						<?php echo (int) $total_accs; ?>
					</span>
				</a>
				<a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&tab=queue')); ?>" class="nav-tab <?php echo $active_tab === 'queue' ? 'nav-tab-active' : ''; ?>" style="font-size: 13px; font-weight: 600; padding: 7px 16px; display: inline-flex; align-items: center; gap: 6px;">
					<span class="dashicons dashicons-list-view" style="font-size: 18px; width: 18px; height: 18px;"></span>
					<?php esc_html_e('Applications Queue', 'custom-woo-dashboard'); ?>
					<?php if ($pending_count > 0) : ?>
						<span style="background: #ef4444; color: #ffffff; border-radius: 10px; padding: 1px 7px; font-size: 11px; font-weight: 700; margin-left: 2px;">
							<?php echo (int) $pending_count; ?>
						</span>
					<?php endif; ?>
				</a>
				<a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&tab=provision')); ?>" class="nav-tab <?php echo $active_tab === 'provision' ? 'nav-tab-active' : ''; ?>" style="font-size: 13px; font-weight: 600; padding: 7px 16px; display: inline-flex; align-items: center; gap: 6px;">
					<span class="dashicons dashicons-plus-alt2" style="font-size: 18px; width: 18px; height: 18px;"></span>
					<?php esc_html_e('Provision Account', 'custom-woo-dashboard'); ?>
				</a>
				<a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&tab=settings')); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>" style="font-size: 13px; font-weight: 600; padding: 7px 16px; display: inline-flex; align-items: center; gap: 6px;">
					<span class="dashicons dashicons-admin-settings" style="font-size: 18px; width: 18px; height: 18px;"></span>
					<?php esc_html_e('Settings', 'custom-woo-dashboard'); ?>
				</a>
			</nav>

			<!-- Active Section Content -->
			<div class="cwd-dashboard-section-content">
				<?php
				switch ($active_tab) {
					case 'queue':
						self::render_applications_queue_section();
						break;
					case 'provision':
						self::render_provision_account_section();
						break;
					case 'settings':
						self::render_settings_section();
						break;
					case 'accounts':
					default:
						self::render_trade_accounts_section();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Section 1: Trade Accounts Directory (User-Centric)
	 */
	public static function render_trade_accounts_section()
	{
		$filter = isset($_GET['filter']) ? sanitize_key($_GET['filter']) : 'all';
		if ($filter === 'all' && isset($_GET['tab']) && in_array($_GET['tab'], array('active', 'pending', 'overdue'), true)) {
			$filter = sanitize_key($_GET['tab']);
		}
		$search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

		$data     = self::get_all_trade_accounts($search, $filter);
		$accounts = $data['accounts'];
		$totals   = $data['totals'];
		?>
		<!-- KPI Cards -->
		<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
			<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
				<span style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em;"><?php esc_html_e('Total Credit Accounts', 'custom-woo-dashboard'); ?></span>
				<div style="margin-top: 6px;">
					<span style="font-size: 26px; font-weight: 800; color: #0f172a;"><?php echo esc_html($totals['total_accounts']); ?></span>
				</div>
			</div>

			<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
				<span style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #166534; letter-spacing: 0.05em;"><?php esc_html_e('Total Credit Facility', 'custom-woo-dashboard'); ?></span>
				<div style="margin-top: 6px;">
					<span style="font-size: 26px; font-weight: 800; color: #166534;"><?php echo wp_kses_post(wc_price($totals['total_facility'])); ?></span>
				</div>
			</div>

			<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
				<span style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #991b1b; letter-spacing: 0.05em;"><?php esc_html_e('Credit Used / Outstanding', 'custom-woo-dashboard'); ?></span>
				<div style="margin-top: 6px;">
					<span style="font-size: 26px; font-weight: 800; color: #991b1b;"><?php echo wp_kses_post(wc_price($totals['total_used'])); ?></span>
				</div>
			</div>

			<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
				<span style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #0284c7; letter-spacing: 0.05em;"><?php esc_html_e('Total Available Credit', 'custom-woo-dashboard'); ?></span>
				<div style="margin-top: 6px;">
					<span style="font-size: 26px; font-weight: 800; color: #0284c7;"><?php echo wp_kses_post(wc_price($totals['total_avail'])); ?></span>
				</div>
			</div>
		</div>

		<!-- Filter Pills & Search Bar -->
		<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
			<ul class="subsubsub" style="margin: 0;">
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&tab=accounts&filter=all')); ?>" class="<?php echo $filter === 'all' ? 'current' : ''; ?>"><?php printf(esc_html__('All Accounts (%d)', 'custom-woo-dashboard'), $totals['total_accounts']); ?></a> |</li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&tab=accounts&filter=active')); ?>" class="<?php echo $filter === 'active' ? 'current' : ''; ?>" style="color: #166534;"><?php printf(esc_html__('Active Credit Users (%d)', 'custom-woo-dashboard'), $totals['count_active']); ?></a> |</li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&tab=accounts&filter=pending')); ?>" class="<?php echo $filter === 'pending' ? 'current' : ''; ?>" style="color: #b45309; font-weight: <?php echo $totals['count_pending'] > 0 ? '700' : 'normal'; ?>;"><?php printf(esc_html__('Pending Review (%d)', 'custom-woo-dashboard'), $totals['count_pending']); ?></a> |</li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&tab=accounts&filter=overdue')); ?>" class="<?php echo $filter === 'overdue' ? 'current' : ''; ?>" style="color: #991b1b; font-weight: <?php echo $totals['count_overdue'] > 0 ? '700' : 'normal'; ?>;"><?php printf(esc_html__('Overdue (%d)', 'custom-woo-dashboard'), $totals['count_overdue']); ?></a></li>
			</ul>

			<form method="get" action="" style="display: flex; gap: 6px;">
				<input type="hidden" name="page" value="cwd-v2-trade-applications" />
				<input type="hidden" name="tab" value="accounts" />
				<?php if ($filter !== 'all') : ?>
					<input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>" />
				<?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search customer, company, trade #...', 'custom-woo-dashboard'); ?>" style="padding: 4px 10px; border-radius: 4px; border: 1px solid #cbd5e1; width: 280px;" />
				<button type="submit" class="button"><?php esc_html_e('Search', 'custom-woo-dashboard'); ?></button>
			</form>
		</div>

		<!-- User-Centric Accounts Responsive Table -->
		<div class="cwd-table-responsive" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 24px;">
			<table class="wp-list-table widefat striped cwd-trade-accounts-table" style="width: 100%; min-width: 1180px; border-collapse: collapse; border: none; margin: 0; table-layout: auto;">
				<thead>
					<tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
						<th style="font-weight: 700; width: 20%; min-width: 200px; padding: 12px 14px; text-align: left; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Customer / User', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700; width: 16%; min-width: 150px; padding: 12px 14px; text-align: left; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Company Name', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700; width: 10%; min-width: 110px; padding: 12px 12px; text-align: left; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Trade #', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700; width: 12%; min-width: 120px; padding: 12px 12px; text-align: left; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Account Type', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700; width: 10%; min-width: 110px; padding: 12px 12px; text-align: right; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Credit Limit', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700; width: 10%; min-width: 110px; padding: 12px 12px; text-align: right; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Credit Used', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700; width: 10%; min-width: 110px; padding: 12px 12px; text-align: right; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Available', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700; width: 11%; min-width: 120px; padding: 12px 12px; text-align: center; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Due Date', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700; width: 11%; min-width: 120px; padding: 12px 12px; text-align: center; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Status & Requests', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700; width: 10%; min-width: 120px; padding: 12px 14px; text-align: right; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Actions', 'custom-woo-dashboard'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($accounts)) : ?>
						<tr><td colspan="10" style="text-align: center; padding: 36px; color: #64748b;"><?php esc_html_e('No trade credit customers or applicants found matching criteria.', 'custom-woo-dashboard'); ?></td></tr>
					<?php else : ?>
						<?php foreach ($accounts as $acc) : ?>
							<?php
							$used_pct = ($acc['credit_limit'] > 0) ? min(100, round(($acc['credit_used'] / $acc['credit_limit']) * 100)) : 0;
							$bar_color = '#16a34a';
							if ($used_pct > 90) {
								$bar_color = '#dc2626';
							} elseif ($used_pct > 70) {
								$bar_color = '#f59e0b';
							}
							?>
							<tr style="border-bottom: 1px solid #f1f5f9;">
								<!-- Customer / User -->
								<td style="padding: 12px 14px; vertical-align: middle;">
									<strong style="color: #0f172a; font-size: 13px;"><?php echo esc_html($acc['name']); ?></strong>
									<div style="font-size: 11px; color: #64748b; margin-top: 2px;">
										<a href="mailto:<?php echo esc_attr($acc['email']); ?>" style="color: #0284c7; text-decoration: none;"><?php echo esc_html($acc['email']); ?></a>
									</div>
									<?php if (! empty($acc['phone'])) : ?>
										<div style="font-size: 11px; color: #94a3b8; margin-top: 1px;"><?php echo esc_html($acc['phone']); ?></div>
									<?php endif; ?>
								</td>

								<!-- Company -->
								<td style="padding: 12px 14px; vertical-align: middle;">
									<?php if (! empty($acc['company']) && $acc['company'] !== '--') : ?>
										<strong style="color: #1e293b; font-size: 13px;"><?php echo esc_html($acc['company']); ?></strong>
									<?php else : ?>
										<span style="color: #94a3b8; font-weight: normal;">&mdash;</span>
									<?php endif; ?>
								</td>

								<!-- Trade Account # -->
								<td style="padding: 12px 12px; vertical-align: middle;">
									<code style="font-family: monospace; font-size: 11px; font-weight: 700; color: #0284c7; background: #f0f9ff; padding: 3px 7px; border-radius: 4px; border: 1px solid #bae6fd; white-space: nowrap;">
										<?php echo esc_html($acc['account_number']); ?>
									</code>
								</td>

								<!-- Account Type & Terms -->
								<td style="padding: 12px 12px; vertical-align: middle;">
									<?php
									$type_bg = '#f1f5f9';
									$type_color = '#475569';
									if ($acc['account_type'] === 'Trade Account') {
										$type_bg = '#eff6ff';
										$type_color = '#1d4ed8';
									} elseif ($acc['account_type'] === 'Trade Applicant' || $acc['account_type'] === 'New Applicant') {
										$type_bg = '#fef3c7';
										$type_color = '#92400e';
									}
									?>
									<span style="display: inline-block; padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: 700; white-space: nowrap; background: <?php echo esc_attr($type_bg); ?>; color: <?php echo esc_attr($type_color); ?>;">
										<?php echo esc_html($acc['account_type']); ?>
									</span>
									<div style="font-size: 11px; color: #64748b; margin-top: 3px;"><?php echo esc_html($acc['terms']); ?></div>
								</td>

								<!-- Credit Limit -->
								<td style="padding: 12px 12px; text-align: right; vertical-align: middle;">
									<strong style="color: #166534; font-size: 13px; font-variant-numeric: tabular-nums;"><?php echo wp_kses_post(wc_price($acc['credit_limit'])); ?></strong>
								</td>

								<!-- Credit Used (Outstanding + Progress Bar) -->
								<td style="padding: 12px 12px; text-align: right; vertical-align: middle;">
									<strong style="color: <?php echo $acc['credit_used'] > 0 ? '#991b1b' : '#64748b'; ?>; font-size: 13px; font-variant-numeric: tabular-nums;">
										<?php echo wp_kses_post(wc_price($acc['credit_used'])); ?>
									</strong>
									<?php if ($acc['credit_used'] > 0 && $acc['credit_limit'] > 0) : ?>
										<div style="background: #e2e8f0; border-radius: 3px; height: 4px; width: 100%; margin-top: 4px; overflow: hidden;" title="<?php echo esc_attr($used_pct . '% used'); ?>">
											<div style="background: <?php echo esc_attr($bar_color); ?>; width: <?php echo esc_attr($used_pct); ?>%; height: 100%;"></div>
										</div>
										<div style="font-size: 10px; color: #64748b; margin-top: 2px;"><?php echo esc_html($used_pct . '% used'); ?></div>
									<?php endif; ?>
								</td>

								<!-- Available Credit -->
								<td style="padding: 12px 12px; text-align: right; vertical-align: middle;">
									<strong style="color: #0f172a; font-size: 13px; font-variant-numeric: tabular-nums;"><?php echo wp_kses_post(wc_price($acc['credit_available'])); ?></strong>
								</td>

								<!-- Due Date -->
								<td style="padding: 12px 12px; text-align: center; vertical-align: middle;">
									<?php
									if ($acc['due_date']) {
										$due_time = strtotime($acc['due_date']);
										$date_formatted = date_i18n('d M Y', $due_time);
										if ($acc['is_overdue']) {
											echo '<span style="display: inline-block; background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; padding: 2px 7px; border-radius: 4px; font-weight: 700; font-size: 11px; white-space: nowrap;">⚠️ ' . esc_html__('Overdue', 'custom-woo-dashboard') . '<br><small>' . esc_html($date_formatted) . '</small></span>';
										} else {
											$days_diff = round(($due_time - current_time('timestamp')) / DAY_IN_SECONDS);
											$due_note = ($days_diff <= 7 && $days_diff >= 0) ? ' (' . sprintf(__('%dd left', 'custom-woo-dashboard'), $days_diff) . ')' : '';
											echo '<span style="font-weight: 600; color: #0f172a; font-size: 12px; white-space: nowrap;">' . esc_html($date_formatted) . '</span><span style="font-size: 10px; color: #64748b; display: block;">' . esc_html($due_note) . '</span>';
										}
									} elseif ($acc['credit_used'] <= 0) {
										echo '<span style="color: #166534; font-weight: 600; font-size: 11px; display: inline-flex; align-items: center; justify-content: center; gap: 3px; white-space: nowrap;"><span class="dashicons dashicons-yes" style="font-size: 14px; width: 14px; height: 14px;"></span> ' . esc_html__('Nil Balance', 'custom-woo-dashboard') . '</span>';
									} else {
										echo '<span style="color: #64748b; font-size: 11px; white-space: nowrap;">' . esc_html($acc['terms'] ?: __('30 Days EOM', 'custom-woo-dashboard')) . '</span>';
									}
									?>
								</td>

								<!-- Status & Pending Requests -->
								<td style="padding: 12px 12px; text-align: center; vertical-align: middle;">
									<?php if (! empty($acc['pending_request'])) : ?>
										<?php
										$preq = $acc['pending_request'];
										$is_increase = ((int) $preq->forminator_form_id === 0);
										$badge_title = $is_increase ? sprintf(__('Increase: %s', 'custom-woo-dashboard'), wc_price($preq->requested_limit)) : sprintf(__('App: %s', 'custom-woo-dashboard'), wc_price($preq->requested_limit));
										?>
										<a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&view=' . $preq->id)); ?>" class="button button-small" style="background: #fef3c7; border-color: #f59e0b; color: #92400e; font-weight: 700; font-size: 11px; padding: 2px 8px; height: auto; white-space: nowrap;">
											<span class="dashicons dashicons-bell" style="font-size: 12px; width: 12px; height: 12px; line-height: 14px; vertical-align: middle;"></span>
											<?php echo wp_kses_post($badge_title); ?> &rarr;
										</a>
									<?php else : ?>
										<?php
										$status_styles = array(
											'active'   => 'background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0;',
											'overdue'  => 'background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5;',
											'pending'  => 'background: #fef3c7; color: #92400e; border: 1px solid #fcd34d;',
											'inactive' => 'background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0;',
										);
										$st_style = $status_styles[$acc['status']] ?? 'background: #f8fafc; color: #64748b;';
										?>
										<span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap; <?php echo esc_attr($st_style); ?>">
											<?php echo esc_html(ucfirst($acc['status'])); ?>
										</span>
									<?php endif; ?>
								</td>

								<!-- Actions -->
								<td style="padding: 12px 14px; text-align: right; vertical-align: middle; white-space: nowrap;">
									<div style="display: inline-flex; align-items: center; justify-content: flex-end; gap: 4px;">
										<?php if ($acc['user_id'] > 0) : ?>
											<details style="display: inline-block; position: relative;">
												<summary class="button button-small" style="font-size: 11px; cursor: pointer;">
													<?php esc_html_e('Limit', 'custom-woo-dashboard'); ?>
												</summary>
												<div style="position: absolute; right: 0; top: 100%; margin-top: 4px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px; z-index: 100; box-shadow: 0 4px 12px rgba(0,0,0,0.15); width: 220px; text-align: left;">
													<form method="post" action="">
														<?php wp_nonce_field('cwd_v2_credit_accounts_action', 'cwd_v2_credit_nonce'); ?>
														<input type="hidden" name="user_id" value="<?php echo esc_attr($acc['user_id']); ?>" />
														<label style="display: block; font-size: 11px; font-weight: 600; color: #334155; margin-bottom: 4px;">
															<?php esc_html_e('New Limit (£):', 'custom-woo-dashboard'); ?>
														</label>
														<input type="number" step="0.01" min="0" name="new_credit_limit" value="<?php echo esc_attr(number_format((float) $acc['credit_limit'], 2, '.', '')); ?>" style="width: 100%; margin-bottom: 8px; font-weight: 700;" required />
														<button type="submit" name="cwd_v2_adjust_user_credit_limit" class="button button-primary button-small" style="width: 100%;">
															<?php esc_html_e('Save New Limit', 'custom-woo-dashboard'); ?>
														</button>
													</form>
												</div>
											</details>

											<a href="<?php echo esc_url(get_edit_user_link($acc['user_id'])); ?>" class="button button-small" style="font-size: 11px;" target="_blank">
												<?php esc_html_e('Profile', 'custom-woo-dashboard'); ?>
											</a>
										<?php elseif (! empty($acc['raw_app'])) : ?>
											<a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&view=' . $acc['raw_app']->id)); ?>" class="button button-primary button-small" style="font-size: 11px;">
												<?php esc_html_e('Review', 'custom-woo-dashboard'); ?>
											</a>
										<?php endif; ?>
									</div>
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
	 * Section 2: Forminator Applications & Increase Requests Queue
	 */
	public static function render_applications_queue_section()
	{
		global $wpdb;
		$table = self::get_table_name();

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

		$count_all      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
		$count_pending  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_PENDING));
		$count_approved = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_APPROVED));
		$count_rejected = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_REJECTED));
		?>
		<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 16px; gap: 12px;">
			<ul class="subsubsub" style="margin: 0;">
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&tab=queue')); ?>" class="<?php echo $status_filter === '' ? 'current' : ''; ?>"><?php printf(esc_html__('All (%d)', 'custom-woo-dashboard'), $count_all); ?></a> |</li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&tab=queue&status=pending')); ?>" class="<?php echo $status_filter === 'pending' ? 'current' : ''; ?>" style="color: #b45309; font-weight: <?php echo $count_pending > 0 ? '700' : 'normal'; ?>;"><?php printf(esc_html__('Pending Review (%d)', 'custom-woo-dashboard'), $count_pending); ?></a> |</li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&tab=queue&status=approved')); ?>" class="<?php echo $status_filter === 'approved' ? 'current' : ''; ?>" style="color: #166534;"><?php printf(esc_html__('Approved (%d)', 'custom-woo-dashboard'), $count_approved); ?></a> |</li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&tab=queue&status=rejected')); ?>" class="<?php echo $status_filter === 'rejected' ? 'current' : ''; ?>" style="color: #991b1b;"><?php printf(esc_html__('Rejected (%d)', 'custom-woo-dashboard'), $count_rejected); ?></a></li>
			</ul>

			<form method="get" action="" style="display: flex; gap: 6px;">
				<input type="hidden" name="page" value="cwd-v2-trade-applications" />
				<input type="hidden" name="tab" value="queue" />
				<?php if ($status_filter) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>" />
				<?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="<?php esc_attr_e('Search company, name, email...', 'custom-woo-dashboard'); ?>" style="padding: 4px 10px; border-radius: 4px; border: 1px solid #cbd5e1; width: 280px;" />
				<button type="submit" class="button"><?php esc_html_e('Search', 'custom-woo-dashboard'); ?></button>
			</form>
		</div>

		<div class="cwd-table-responsive" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 24px;">
			<table class="wp-list-table widefat striped cwd-applications-queue-table" style="width: 100%; min-width: 1100px; border-collapse: collapse; border: none; margin: 0; table-layout: auto;">
				<thead>
					<tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
						<th style="width: 60px; font-weight: 700; padding: 12px 14px; text-align: left; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('ID', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700; padding: 12px 14px; text-align: left; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Date', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700; padding: 12px 14px; text-align: left; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Company', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700; padding: 12px 14px; text-align: left; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Applicant', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700; padding: 12px 14px; text-align: left; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Email', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700; padding: 12px 14px; text-align: right; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Requested Facility', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700; padding: 12px 14px; text-align: right; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Approved Limit', 'custom-woo-dashboard'); ?></th>
						<th style="font-weight: 700; width: 120px; padding: 12px 14px; text-align: center; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Status', 'custom-woo-dashboard'); ?></th>
						<th style="width: 110px; font-weight: 700; padding: 12px 14px; text-align: right; vertical-align: middle; white-space: nowrap;"><?php esc_html_e('Action', 'custom-woo-dashboard'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($applications)) : ?>
						<tr><td colspan="9" style="text-align: center; padding: 36px; color: #64748b; font-size: 14px;"><?php esc_html_e('No applications found.', 'custom-woo-dashboard'); ?></td></tr>
					<?php else : ?>
						<?php foreach ($applications as $app) : ?>
							<tr style="border-bottom: 1px solid #f1f5f9;">
								<td style="padding: 12px 14px; vertical-align: middle;"><strong>#<?php echo esc_html($app->id); ?></strong></td>
								<td style="padding: 12px 14px; vertical-align: middle; white-space: nowrap;"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($app->created_at))); ?></td>
								<td style="padding: 12px 14px; vertical-align: middle;">
									<strong><?php echo esc_html($app->company_name ?: '--'); ?></strong>
									<?php if ((int) $app->forminator_form_id === 0) : ?>
										<div style="font-size: 11px; color: #0284c7; font-weight: 600; margin-top: 2px;">
											<?php esc_html_e('Credit Increase Request', 'custom-woo-dashboard'); ?>
										</div>
									<?php endif; ?>
								</td>
								<td style="padding: 12px 14px; vertical-align: middle;"><strong><?php echo esc_html($app->applicant_name ?: '--'); ?></strong></td>
								<td style="padding: 12px 14px; vertical-align: middle;"><a href="mailto:<?php echo esc_attr($app->applicant_email); ?>" style="color: #0284c7; text-decoration: none;"><?php echo esc_html($app->applicant_email); ?></a></td>
								<td style="padding: 12px 14px; text-align: right; vertical-align: middle; font-weight: 600;"><?php echo wp_kses_post(wc_price($app->requested_limit)); ?></td>
								<td style="padding: 12px 14px; text-align: right; vertical-align: middle;">
									<?php if ($app->status === self::STATUS_APPROVED && (float) $app->approved_limit > 0) : ?>
										<strong style="color: #166534;"><?php echo wp_kses_post(wc_price($app->approved_limit)); ?></strong>
									<?php else : ?>
										<span style="color: #94a3b8;">--</span>
									<?php endif; ?>
								</td>
								<td style="padding: 12px 14px; text-align: center; vertical-align: middle;">
									<?php
									$badge_styles = array(
										self::STATUS_PENDING  => 'background: #fef3c7; color: #92400e; border: 1px solid #fcd34d;',
										self::STATUS_APPROVED => 'background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0;',
										self::STATUS_REJECTED => 'background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5;',
									);
									$style = $badge_styles[$app->status] ?? '';
									?>
									<span style="display: inline-block; padding: 2px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap; <?php echo esc_attr($style); ?>">
										<?php echo esc_html(ucfirst($app->status)); ?>
									</span>
								</td>
								<td style="padding: 12px 14px; text-align: right; vertical-align: middle; white-space: nowrap;">
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
	 * Section 3: Provision Trade Account
	 */
	public static function render_provision_account_section()
	{
		?>
		<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; align-items: start;">
			<!-- Provisioning Form Card -->
			<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 28px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
				<h3 style="margin: 0 0 8px; font-size: 18px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
					<span class="dashicons dashicons-plus-alt2" style="color: #0284c7; font-size: 22px; width: 22px; height: 22px;"></span>
					<?php esc_html_e('Provision Trade Credit Account', 'custom-woo-dashboard'); ?>
				</h3>
				<p style="margin: 0 0 24px; font-size: 13px; color: #64748b; line-height: 1.5;">
					<?php esc_html_e('Instantly activate or grant a trade credit facility for an existing customer or a new trade client. If the user does not exist, an account is created with an automated EWS Trade Account Number.', 'custom-woo-dashboard'); ?>
				</p>

				<form method="post" action="">
					<?php wp_nonce_field('cwd_v2_credit_accounts_action', 'cwd_v2_credit_nonce'); ?>

					<div style="margin-bottom: 18px;">
						<label style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;">
							<?php esc_html_e('Customer Email Address', 'custom-woo-dashboard'); ?> <span style="color: #ef4444;">*</span>
						</label>
						<input type="email" name="user_email" placeholder="trade.customer@company.co.uk" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 14px;" required />
						<p class="description" style="margin-top: 4px;"><?php esc_html_e('If an account already exists with this email, the credit facility is assigned to it.', 'custom-woo-dashboard'); ?></p>
					</div>

					<div style="margin-bottom: 18px;">
						<label style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;">
							<?php esc_html_e('Company / Trading Name (Optional)', 'custom-woo-dashboard'); ?>
						</label>
						<input type="text" name="company_name" placeholder="Electrical Wholesale Supplies Ltd" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 14px;" />
					</div>

					<div style="margin-bottom: 24px;">
						<label style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;">
							<?php esc_html_e('Credit Facility Limit (£)', 'custom-woo-dashboard'); ?> <span style="color: #ef4444;">*</span>
						</label>
						<input type="number" step="0.01" min="0" name="credit_limit" value="1000.00" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 16px; font-weight: 700; color: #166534;" required />
						<p class="description" style="margin-top: 4px;"><?php esc_html_e('Credit limit is securely saved and will never be automatically reduced or overwritten.', 'custom-woo-dashboard'); ?></p>
					</div>

					<button type="submit" name="cwd_v2_add_manual_credit_user" class="button button-primary" style="padding: 8px 24px; font-weight: 700; font-size: 14px; height: auto; background: #0284c7; border-color: #0284c7;">
						<span class="dashicons dashicons-yes" style="font-size: 18px; line-height: 20px; vertical-align: top;"></span>
						<?php esc_html_e('Provision Trade Facility', 'custom-woo-dashboard'); ?>
					</button>
				</form>
			</div>

			<!-- Helper Guide Box -->
			<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 22px;">
				<h4 style="margin: 0 0 12px; font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
					<span class="dashicons dashicons-info" style="color: #0284c7;"></span>
					<?php esc_html_e('How Provisioning Works', 'custom-woo-dashboard'); ?>
				</h4>
				<ul style="margin: 0; padding-left: 18px; color: #475569; font-size: 12px; line-height: 1.7;">
					<li><strong><?php esc_html_e('Role Assignment:', 'custom-woo-dashboard'); ?></strong> <?php esc_html_e('Automatically grants the credit_account user role.', 'custom-woo-dashboard'); ?></li>
					<li><strong><?php esc_html_e('EWS Trade Number:', 'custom-woo-dashboard'); ?></strong> <?php esc_html_e('Generates a unique Trade # (e.g. EWS-T0042) for easy invoice reference.', 'custom-woo-dashboard'); ?></li>
					<li><strong><?php esc_html_e('Checkout Integration:', 'custom-woo-dashboard'); ?></strong> <?php esc_html_e('Enables Pay on Credit Account at checkout up to their limit.', 'custom-woo-dashboard'); ?></li>
					<li><strong><?php esc_html_e('Protected Balance:', 'custom-woo-dashboard'); ?></strong> <?php esc_html_e('User limit is preserved and protected against auto-updates.', 'custom-woo-dashboard'); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Section 4: Settings
	 */
	public static function render_settings_section()
	{
		?>
		<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 26px; max-width: 800px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
			<h3 style="margin: 0 0 18px; font-size: 16px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
				<span class="dashicons dashicons-admin-settings" style="color: #0284c7;"></span>
				<?php esc_html_e('Trade & Credit Configuration', 'custom-woo-dashboard'); ?>
			</h3>

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
				<div style="margin-top: 20px;">
					<?php submit_button(__('Save Settings', 'custom-woo-dashboard')); ?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Backwards compatibility aliases
	 */
	public static function render_trade_accounts_dashboard()
	{
		self::render_tabbed_dashboard('accounts');
	}

	public static function render_applications_queue_page()
	{
		self::render_tabbed_dashboard('queue');
	}

	public static function render_credit_accounts_page()
	{
		self::render_tabbed_dashboard('accounts');
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
		<div class="wrap" style="max-width: 1400px; margin-top: 18px;">
			<h1 style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
				<div style="display: flex; align-items: center; gap: 10px;">
					<a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&tab=accounts')); ?>" style="text-decoration: none; color: #475569; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
						<span class="dashicons dashicons-arrow-left-alt2"></span> <?php esc_html_e('Accounts Directory', 'custom-woo-dashboard'); ?>
					</a>
					<span style="color: #cbd5e1;">|</span>
					<a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&tab=queue')); ?>" style="text-decoration: none; color: #475569; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
						<?php esc_html_e('Applications Queue', 'custom-woo-dashboard'); ?>
					</a>
				</div>
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
	 * Render Settings Page
	 */
	public static function render_settings_page()
	{
		self::render_tabbed_dashboard('settings');
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
