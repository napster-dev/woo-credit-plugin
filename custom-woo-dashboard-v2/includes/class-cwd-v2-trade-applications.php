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

		// Throttle: don't allow creating requests less than 60 seconds apart for same customer
		$recent = $wpdb->get_var($wpdb->prepare(
			"SELECT id FROM {$table} WHERE user_id = %d AND forminator_form_id = 0 AND created_at >= %s LIMIT 1",
			$user_id,
			gmdate('Y-m-d H:i:s', time() - 60)
		));
		if ($recent) {
			return (int) $recent;
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

		$insert_format = array('%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%s', '%s');

		return $wpdb->insert($table, $insert_data, $insert_format);
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
	 * Register the admin menu page under WooCommerce
	 */
	public static function register_admin_menu()
	{
		add_submenu_page(
			'woocommerce',
			__('Trade Applications', 'custom-woo-dashboard'),
			__('Trade Applications', 'custom-woo-dashboard'),
			'manage_woocommerce',
			'cwd-v2-trade-applications',
			array(__CLASS__, 'render_admin_page')
		);
	}

	/**
	 * Add pending count bubble to the Trade Applications menu item
	 */
	public static function add_pending_count_bubble()
	{
		global $submenu;
		if (! isset($submenu['woocommerce'])) {
			return;
		}

		$pending = self::get_pending_count();
		if ($pending < 1) {
			return;
		}

		foreach ($submenu['woocommerce'] as &$item) {
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

	/**
	 * Handle approve/reject admin POST actions
	 */
	public static function handle_admin_actions()
	{
		if (! current_user_can('manage_woocommerce')) {
			return;
		}

		// Approve action
		if (isset($_POST['cwd_v2_approve_application'])) {
			self::process_approval();
		}

		// Reject action
		if (isset($_POST['cwd_v2_reject_application'])) {
			self::process_rejection();
		}
	}

	/**
	 * Process application approval
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

		if ($approved_limit <= 0) {
			$approved_limit = $app->requested_limit;
		}

		// Find or create WP user
		$user = get_user_by('email', $app->applicant_email);
		if (! $user) {
			$username = sanitize_user(strtolower(str_replace(' ', '.', $app->applicant_name ?: explode('@', $app->applicant_email)[0])), true);
			// Ensure unique username
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

			// Send password setup email
			wp_new_user_notification($user_id, null, 'user');
		}

		$user_id = $user->ID;

		// Assign credit_account role
		if (! in_array('credit_account', (array) $user->roles, true)) {
			$user->add_role('credit_account');
		}

		// Set credit limit and initialize balance
		update_user_meta($user_id, '_credit_limit', $approved_limit);
		// Initialize balance to 0 if not yet set (metadata_exists checks DB, not value)
		if (! metadata_exists('user', $user_id, '_credit_balance')) {
			update_user_meta($user_id, '_credit_balance', 0);
		}

		// Set EWS trade account number if not already set
		if (! get_user_meta($user_id, 'ews_account_number', true)) {
			// Generate a simple sequential trade number
			$last_num = (int) get_option('cwd_v2_last_trade_number', 0);
			$last_num++;
			$trade_number = 'EWS-T' . str_pad($last_num, 4, '0', STR_PAD_LEFT);
			update_user_meta($user_id, 'ews_account_number', $trade_number);
			update_option('cwd_v2_last_trade_number', $last_num);
		}

		// Company name stored as billing company
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

		wp_redirect(admin_url('admin.php?page=cwd-v2-trade-applications&notice=approved'));
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

		wp_redirect(admin_url('admin.php?page=cwd-v2-trade-applications&notice=rejected'));
		exit;
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
		$where         = '';
		if (in_array($status_filter, array(self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED), true)) {
			$where = $wpdb->prepare(" WHERE status = %s", $status_filter);
		}

		$applications = $wpdb->get_results("SELECT * FROM {$table}{$where} ORDER BY created_at DESC LIMIT 100");

		// Counts for tabs
		$count_all      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
		$count_pending  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_PENDING));
		$count_approved = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_APPROVED));
		$count_rejected = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_REJECTED));

		// Admin notice
		$notice = isset($_GET['notice']) ? sanitize_key($_GET['notice']) : '';
		?>
		<div class="wrap">
			<h1 style="display: flex; align-items: center; gap: 10px; font-weight: 700; color: #0f172a;">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0f172a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
				<?php esc_html_e('Trade Credit Applications', 'custom-woo-dashboard'); ?>
			</h1>

			<?php if ($notice === 'approved') : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Application approved successfully. Customer account has been provisioned with credit.', 'custom-woo-dashboard'); ?></p></div>
			<?php elseif ($notice === 'rejected') : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e('Application has been rejected.', 'custom-woo-dashboard'); ?></p></div>
			<?php elseif ($notice === 'invalid') : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e('Invalid or already processed application.', 'custom-woo-dashboard'); ?></p></div>
			<?php endif; ?>

			<!-- Status Filter Tabs -->
			<ul class="subsubsub" style="margin-bottom: 12px;">
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications')); ?>" class="<?php echo $status_filter === '' ? 'current' : ''; ?>"><?php printf(esc_html__('All (%d)', 'custom-woo-dashboard'), $count_all); ?></a> |</li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&status=pending')); ?>" class="<?php echo $status_filter === 'pending' ? 'current' : ''; ?>" style="color: #b45309;"><?php printf(esc_html__('Pending (%d)', 'custom-woo-dashboard'), $count_pending); ?></a> |</li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&status=approved')); ?>" class="<?php echo $status_filter === 'approved' ? 'current' : ''; ?>" style="color: #166534;"><?php printf(esc_html__('Approved (%d)', 'custom-woo-dashboard'), $count_approved); ?></a> |</li>
				<li><a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&status=rejected')); ?>" class="<?php echo $status_filter === 'rejected' ? 'current' : ''; ?>" style="color: #991b1b;"><?php printf(esc_html__('Rejected (%d)', 'custom-woo-dashboard'), $count_rejected); ?></a></li>
			</ul>

			<table class="wp-list-table widefat fixed striped" style="margin-top: 8px; border: 1px solid #e2e8f0;">
				<thead>
					<tr>
						<th style="width: 50px;"><?php esc_html_e('ID', 'custom-woo-dashboard'); ?></th>
						<th><?php esc_html_e('Date', 'custom-woo-dashboard'); ?></th>
						<th><?php esc_html_e('Company', 'custom-woo-dashboard'); ?></th>
						<th><?php esc_html_e('Applicant', 'custom-woo-dashboard'); ?></th>
						<th><?php esc_html_e('Email', 'custom-woo-dashboard'); ?></th>
						<th><?php esc_html_e('Requested Limit', 'custom-woo-dashboard'); ?></th>
						<th><?php esc_html_e('Status', 'custom-woo-dashboard'); ?></th>
						<th style="width: 100px;"><?php esc_html_e('Action', 'custom-woo-dashboard'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($applications)) : ?>
						<tr><td colspan="8" style="text-align: center; padding: 20px; color: #64748b;"><?php esc_html_e('No applications found.', 'custom-woo-dashboard'); ?></td></tr>
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
								<td>
									<a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications&view=' . $app->id)); ?>" class="button button-small" style="font-size: 12px;">
										<?php echo $app->status === self::STATUS_PENDING ? esc_html__('Review', 'custom-woo-dashboard') : esc_html__('View', 'custom-woo-dashboard'); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Forminator Form ID Configuration -->
			<div style="margin-top: 30px; padding: 20px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px;">
				<h3 style="margin-top: 0; font-size: 14px; font-weight: 600; color: #0f172a;">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0f172a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: text-bottom; margin-right: 4px;" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
					<?php esc_html_e('Forminator Form Configuration', 'custom-woo-dashboard'); ?>
				</h3>
				<form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
					<?php
					settings_fields('cwd_v2_trade_settings');
					$current_form_id = get_option('cwd_v2_trade_application_form_id', '');
					?>
					<label for="cwd_v2_trade_application_form_id" style="font-size: 13px; color: #334155; font-weight: 500;">
						<?php esc_html_e('Forminator Form ID:', 'custom-woo-dashboard'); ?>
					</label>
					<input type="number" name="cwd_v2_trade_application_form_id" id="cwd_v2_trade_application_form_id" value="<?php echo esc_attr($current_form_id); ?>" class="small-text" style="margin-left: 6px;" />
					<?php submit_button(__('Save', 'custom-woo-dashboard'), 'secondary', 'submit', false); ?>
					<p class="description" style="margin-top: 6px;">
						<?php esc_html_e('Enter the Forminator Form ID used for trade credit applications. Leave empty (0) to capture all Forminator submissions that contain an email field.', 'custom-woo-dashboard'); ?>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single application detail view with approve/reject actions
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

		$form_data   = json_decode($app->form_data, true) ?: array();
		$is_pending  = ($app->status === self::STATUS_PENDING);
		$reviewed_by = $app->reviewed_by ? get_userdata($app->reviewed_by) : null;
		$linked_user = $app->user_id ? get_userdata($app->user_id) : null;
		?>
		<div class="wrap">
			<h1 style="display: flex; align-items: center; gap: 10px;">
				<a href="<?php echo esc_url(admin_url('admin.php?page=cwd-v2-trade-applications')); ?>" style="text-decoration: none; color: #64748b; font-size: 14px;">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
					<?php esc_html_e('Back to Applications', 'custom-woo-dashboard'); ?>
				</a>
			</h1>

			<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 16px;">

				<!-- Left Column: Application Details -->
				<div>
					<!-- Application Summary Card -->
					<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; margin-bottom: 20px;">
						<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
							<div>
								<h2 style="margin: 0 0 4px; font-size: 20px; font-weight: 700; color: #0f172a;">
									<?php printf(esc_html__('Application #%d', 'custom-woo-dashboard'), $app->id); ?>
								</h2>
								<p style="margin: 0; font-size: 13px; color: #64748b;">
									<?php printf(esc_html__('Submitted on %s', 'custom-woo-dashboard'), esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($app->created_at)))); ?>
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
							<span style="display: inline-block; padding: 4px 14px; border-radius: 4px; font-size: 13px; font-weight: 600; <?php echo esc_attr($style); ?>">
								<?php echo esc_html(ucfirst($app->status)); ?>
							</span>
						</div>

						<!-- Applicant Key Info Grid -->
						<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
							<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
								<span style="display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em; margin-bottom: 4px;">
									<?php esc_html_e('Applicant Name', 'custom-woo-dashboard'); ?>
								</span>
								<span style="font-size: 15px; font-weight: 600; color: #0f172a;">
									<?php echo esc_html($app->applicant_name ?: '--'); ?>
								</span>
							</div>
							<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
								<span style="display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em; margin-bottom: 4px;">
									<?php esc_html_e('Company Name', 'custom-woo-dashboard'); ?>
								</span>
								<span style="font-size: 15px; font-weight: 600; color: #0f172a;">
									<?php echo esc_html($app->company_name ?: '--'); ?>
								</span>
							</div>
							<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
								<span style="display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em; margin-bottom: 4px;">
									<?php esc_html_e('Email Address', 'custom-woo-dashboard'); ?>
								</span>
								<a href="mailto:<?php echo esc_attr($app->applicant_email); ?>" style="font-size: 15px; font-weight: 600; color: #0f172a; text-decoration: none;">
									<?php echo esc_html($app->applicant_email); ?>
								</a>
							</div>
							<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
								<span style="display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em; margin-bottom: 4px;">
									<?php esc_html_e('Requested Credit Limit', 'custom-woo-dashboard'); ?>
								</span>
								<span style="font-size: 18px; font-weight: 700; color: #0f172a;">
									<?php echo wp_kses_post(wc_price($app->requested_limit)); ?>
								</span>
							</div>
						</div>

						<?php if ($app->phone) : ?>
							<div style="margin-bottom: 16px;">
								<span style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #64748b;"><?php esc_html_e('Phone:', 'custom-woo-dashboard'); ?></span>
								<span style="margin-left: 6px; font-size: 14px; color: #0f172a; font-weight: 500;"><?php echo esc_html($app->phone); ?></span>
							</div>
						<?php endif; ?>

						<!-- Full Form Submission Data -->
						<?php if (! empty($form_data)) : ?>
							<div style="border-top: 1px solid #e2e8f0; padding-top: 20px; margin-top: 10px;">
								<h3 style="font-size: 14px; font-weight: 600; color: #0f172a; margin: 0 0 12px;">
									<?php esc_html_e('Complete Form Submission', 'custom-woo-dashboard'); ?>
								</h3>
								<table class="widefat fixed" style="border: 1px solid #e2e8f0;">
									<thead>
										<tr>
											<th style="width: 35%; font-weight: 600; font-size: 12px; text-transform: uppercase; color: #64748b;"><?php esc_html_e('Field', 'custom-woo-dashboard'); ?></th>
											<th style="font-weight: 600; font-size: 12px; text-transform: uppercase; color: #64748b;"><?php esc_html_e('Value', 'custom-woo-dashboard'); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($form_data as $field_key => $field_value) : ?>
											<tr>
												<td style="font-weight: 500; color: #334155;">
													<?php echo esc_html(ucwords(str_replace(array('-', '_'), ' ', $field_key))); ?>
												</td>
												<td style="color: #0f172a;">
													<?php
													if (is_array($field_value)) {
														echo esc_html(wp_json_encode($field_value));
													} else {
														echo wp_kses_post(nl2br(esc_html((string) $field_value)));
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

					<!-- Review Outcome (for already processed applications) -->
					<?php if (! $is_pending && $reviewed_by) : ?>
						<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;">
							<h3 style="font-size: 14px; font-weight: 600; color: #0f172a; margin: 0 0 12px;">
								<?php esc_html_e('Review Decision', 'custom-woo-dashboard'); ?>
							</h3>
							<table class="form-table" style="margin: 0;">
								<tr><th><?php esc_html_e('Decision', 'custom-woo-dashboard'); ?></th><td><strong><?php echo esc_html(ucfirst($app->status)); ?></strong></td></tr>
								<?php if ($app->status === self::STATUS_APPROVED) : ?>
									<tr><th><?php esc_html_e('Approved Limit', 'custom-woo-dashboard'); ?></th><td><?php echo wp_kses_post(wc_price($app->approved_limit)); ?></td></tr>
								<?php endif; ?>
								<?php if ($app->admin_note) : ?>
									<tr><th><?php esc_html_e('Admin Note', 'custom-woo-dashboard'); ?></th><td><?php echo esc_html($app->admin_note); ?></td></tr>
								<?php endif; ?>
								<tr><th><?php esc_html_e('Reviewed By', 'custom-woo-dashboard'); ?></th><td><?php echo esc_html($reviewed_by->display_name); ?></td></tr>
								<tr><th><?php esc_html_e('Reviewed On', 'custom-woo-dashboard'); ?></th><td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($app->reviewed_at))); ?></td></tr>
								<?php if ($linked_user) : ?>
									<tr><th><?php esc_html_e('Linked Customer', 'custom-woo-dashboard'); ?></th><td><a href="<?php echo esc_url(get_edit_user_link($linked_user->ID)); ?>"><?php echo esc_html($linked_user->display_name . ' (' . $linked_user->user_email . ')'); ?></a></td></tr>
								<?php endif; ?>
							</table>
						</div>
					<?php endif; ?>
				</div>

				<!-- Right Column: Action Panel -->
				<div>
					<?php if ($is_pending) : ?>
						<!-- Approve Form -->
						<div style="background: #ffffff; border: 1px solid #bbf7d0; border-radius: 8px; padding: 20px; margin-bottom: 16px;">
							<h3 style="display: flex; align-items: center; gap: 6px; font-size: 15px; font-weight: 600; color: #166534; margin: 0 0 14px;">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
								<?php esc_html_e('Approve Application', 'custom-woo-dashboard'); ?>
							</h3>
							<form method="post" action="">
								<?php wp_nonce_field('cwd_v2_trade_app_action', 'cwd_v2_trade_app_nonce'); ?>
								<input type="hidden" name="application_id" value="<?php echo esc_attr($app->id); ?>" />

								<div style="margin-bottom: 14px;">
									<label for="approved_limit" style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
										<?php esc_html_e('Approved Credit Limit', 'custom-woo-dashboard'); ?> (<?php echo esc_html(get_woocommerce_currency_symbol()); ?>)
									</label>
									<input type="number" step="0.01" min="0" name="approved_limit" id="approved_limit" value="<?php echo esc_attr(number_format((float) $app->requested_limit, 2, '.', '')); ?>" class="regular-text" style="width: 100%; max-width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; box-sizing: border-box;" />
									<p class="description" style="margin-top: 4px;"><?php esc_html_e('Adjust if needed. Defaults to the applicant\'s requested amount.', 'custom-woo-dashboard'); ?></p>
								</div>

								<div style="margin-bottom: 14px;">
									<label for="approve_note" style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
										<?php esc_html_e('Internal Note (Optional)', 'custom-woo-dashboard'); ?>
									</label>
									<textarea name="admin_note" id="approve_note" rows="2" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; box-sizing: border-box; font-size: 13px;"></textarea>
								</div>

								<button type="submit" name="cwd_v2_approve_application" class="button button-primary" style="background: #16a34a; border-color: #16a34a; border-radius: 6px; padding: 8px 20px; font-weight: 600; width: 100%;">
									<?php esc_html_e('Approve and Provision Account', 'custom-woo-dashboard'); ?>
								</button>
								<p class="description" style="margin-top: 8px; text-align: center;"><?php esc_html_e('This will create the customer account (if new), assign the credit_account role, and set the approved credit limit.', 'custom-woo-dashboard'); ?></p>
							</form>
						</div>

						<!-- Reject Form -->
						<div style="background: #ffffff; border: 1px solid #fca5a5; border-radius: 8px; padding: 20px;">
							<h3 style="display: flex; align-items: center; gap: 6px; font-size: 15px; font-weight: 600; color: #991b1b; margin: 0 0 14px;">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
								<?php esc_html_e('Reject Application', 'custom-woo-dashboard'); ?>
							</h3>
							<form method="post" action="">
								<?php wp_nonce_field('cwd_v2_trade_app_action', 'cwd_v2_trade_app_nonce'); ?>
								<input type="hidden" name="application_id" value="<?php echo esc_attr($app->id); ?>" />

								<div style="margin-bottom: 14px;">
									<label for="reject_note" style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
										<?php esc_html_e('Reason for Rejection (Optional)', 'custom-woo-dashboard'); ?>
									</label>
									<textarea name="admin_note" id="reject_note" rows="2" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; box-sizing: border-box; font-size: 13px;" placeholder="<?php esc_attr_e('e.g. Credit check unsuccessful, insufficient trading history', 'custom-woo-dashboard'); ?>"></textarea>
								</div>

								<button type="submit" name="cwd_v2_reject_application" class="button" style="background: #fef2f2; border-color: #fca5a5; color: #991b1b; border-radius: 6px; padding: 8px 20px; font-weight: 600; width: 100%;" onclick="return confirm('<?php esc_attr_e('Are you sure you want to reject this application?', 'custom-woo-dashboard'); ?>');">
									<?php esc_html_e('Reject Application', 'custom-woo-dashboard'); ?>
								</button>
							</form>
						</div>
					<?php else : ?>
						<!-- Already Processed -->
						<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; text-align: center;">
							<p style="color: #64748b; font-size: 14px; margin: 0;">
								<?php esc_html_e('This application has already been processed.', 'custom-woo-dashboard'); ?>
							</p>
							<?php if ($linked_user) : ?>
								<a href="<?php echo esc_url(get_edit_user_link($linked_user->ID)); ?>" class="button" style="margin-top: 12px;">
									<?php esc_html_e('View Customer Profile', 'custom-woo-dashboard'); ?>
								</a>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Register settings for the form ID configuration
	 */
	public static function register_settings()
	{
		register_setting('cwd_v2_trade_settings', 'cwd_v2_trade_application_form_id', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		));
	}
}
