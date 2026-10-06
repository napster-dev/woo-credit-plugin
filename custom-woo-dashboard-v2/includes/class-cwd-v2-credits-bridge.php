<?php

/**
 * Two-way Unified Bridge between Trade Applications and the WooCommerce Credits plugin.
 *
 * Ensures:
 * 1. Trade applications submitted on the site (via Forminator or customer dashboard)
 *    are immediately forwarded and registered as pending in the third-party Credits plugin.
 * 2. Approvals, status updates, or credit limit changes made inside the Credits plugin
 *    (Credits > Credits admin panel) instantly update the Trade Applications table to approved.
 * 3. Approvals made inside WooCommerce > Trade Applications instantly update and activate the
 *    user inside the Credits plugin.
 * 4. Automatic reconciliation runs on demand to prevent state drift and eliminate redundancy.
 */

if (! defined('ABSPATH')) {
	exit;
}

class CWD_V2_Credits_Bridge
{
	/**
	 * Guard against recursive sync loops
	 *
	 * @var bool
	 */
	private static $is_syncing = false;

	/**
	 * Known user meta keys used by Credits plugins (Flintop / FantasticPlugins / WooCommerce Credits)
	 *
	 * @var array
	 */
	private static $credit_status_keys = array(
		'_credit_status',
		'_credits_status',
		'_fpc_credit_status',
		'_fpc_user_status',
		'_fpc_status',
		'credits_status',
		'credit_status',
		'_credit_application_status',
		'_user_credit_status',
		'_account_credit_status',
	);

	private static $credit_limit_keys = array(
		'_credit_limit',
		'_credits_limit',
		'_fpc_credit_limit',
		'_fpc_limit',
		'credit_limit',
		'credits_limit',
		'fpc_credit_limit',
		'_fp_credit_limit',
		'_user_credit_limit',
	);

	/**
	 * Initialize event listeners
	 */
	public static function init()
	{
		// 1. Hook into WordPress user meta updates (fires whenever an admin or plugin updates credit meta)
		add_action('updated_user_meta', array(__CLASS__, 'on_user_meta_updated'), 10, 4);
		add_action('added_user_meta', array(__CLASS__, 'on_user_meta_updated'), 10, 4);

		// 2. Hook into role changes (if user is granted credit_account role elsewhere)
		add_action('set_user_role', array(__CLASS__, 'on_user_role_changed'), 10, 3);

		// 3. Hook into post status changes (if Credits plugin uses custom post types for applications)
		add_action('transition_post_status', array(__CLASS__, 'on_post_status_transition'), 10, 3);
		add_action('save_post', array(__CLASS__, 'on_post_saved'), 10, 2);

		// 4. Hook into admin page loads to perform automatic reconciliation
		add_action('admin_init', array(__CLASS__, 'maybe_reconcile_admin_requests'));
	}

	/**
	 * Forward a newly submitted trade application into the Credits plugin ecosystem.
	 *
	 * @param int         $app_id           Trade application ID
	 * @param int|null    $user_id          WordPress user ID (if exists)
	 * @param string      $applicant_email  Email of applicant
	 * @param string      $applicant_name   Full name of applicant
	 * @param string      $company_name     Company name
	 * @param string      $phone            Contact phone
	 * @param float       $requested_limit  Requested credit limit
	 * @param array       $form_data        Submitted form data
	 * @return int|null   User ID associated with application
	 */
	public static function forward_application_to_credits_plugin($app_id, $user_id, $applicant_email, $applicant_name, $company_name, $phone, $requested_limit, $form_data = array())
	{
		if (self::$is_syncing) {
			return $user_id;
		}

		self::$is_syncing = true;
		global $wpdb;

		// 1. Ensure a WordPress user exists so the Credits plugin can track them
		if (! $user_id) {
			$existing_user = get_user_by('email', sanitize_email($applicant_email));
			if ($existing_user) {
				$user_id = $existing_user->ID;
			} else {
				$base_user = sanitize_user(strtolower(str_replace(' ', '.', $applicant_name ?: explode('@', $applicant_email)[0])), true);
				$username  = $base_user ?: 'customer';
				$counter   = 1;
				while (username_exists($username)) {
					$username = $base_user . $counter;
					$counter++;
				}
				$password = wp_generate_password(12, true, true);
				$created_id = wp_create_user($username, $password, sanitize_email($applicant_email));

				if (! is_wp_error($created_id)) {
					$user_id = $created_id;
					wp_update_user(array(
						'ID'           => $user_id,
						'display_name' => $applicant_name ?: $username,
						'first_name'   => explode(' ', $applicant_name)[0] ?? '',
						'last_name'    => count(explode(' ', $applicant_name)) > 1 ? explode(' ', $applicant_name, 2)[1] : '',
					));
				}
			}
		}

		if ($user_id) {
			// Update application with user_id in trade applications table
			if ($app_id && class_exists('CWD_V2_Trade_Applications')) {
				$apps_table = CWD_V2_Trade_Applications::get_table_name();
				$wpdb->update($apps_table, array('user_id' => $user_id), array('id' => $app_id), array('%d'), array('%d'));
			}

			// Store company & phone in billing meta if not already populated
			if ($company_name && ! get_user_meta($user_id, 'billing_company', true)) {
				update_user_meta($user_id, 'billing_company', sanitize_text_field($company_name));
			}
			if ($phone && ! get_user_meta($user_id, 'billing_phone', true)) {
				update_user_meta($user_id, 'billing_phone', sanitize_text_field($phone));
			}

			// 2. Sync all Credits plugin user_meta status keys to 'pending'
			foreach (self::$credit_status_keys as $status_key) {
				update_user_meta($user_id, $status_key, 'pending');
			}

			// Set requested limit & trace metadata
			update_user_meta($user_id, '_requested_credit_limit', (float) $requested_limit);
			update_user_meta($user_id, '_fpc_requested_limit', (float) $requested_limit);
			update_user_meta($user_id, '_trade_application_id', (int) $app_id);

			// 3. Dynamically forward to external Credits database tables if present
			self::sync_to_external_credits_tables($user_id, 'pending', (float) $requested_limit, $applicant_email, $company_name);

			// 4. Dynamically forward to custom post types if present
			self::sync_to_external_credits_cpt($user_id, 'pending', (float) $requested_limit, $applicant_name, $applicant_email, $company_name);

			// 5. Fire standardized action hooks for third-party integrations
			do_action('cwd_v2_trade_application_received', $app_id, $user_id, $requested_limit);
			do_action('credits_application_submitted', $user_id, $requested_limit, $form_data);
			do_action('fpc_new_credit_application', $user_id, $requested_limit);
		}

		self::$is_syncing = false;
		return $user_id;
	}

	/**
	 * Synchronize Trade Applications when an approval occurs in the Credits plugin.
	 *
	 * @param int         $user_id          User ID that was approved
	 * @param float|null  $approved_limit   Approved credit limit
	 * @param string      $source_reason    Source/reason description
	 * @return bool
	 */
	public static function sync_trade_application_from_credits_approval($user_id, $approved_limit = null, $source_reason = 'Credits plugin')
	{
		$user_id = (int) $user_id;
		if (! $user_id || ! class_exists('CWD_V2_Trade_Applications')) {
			return false;
		}

		$user = get_userdata($user_id);
		if (! $user) {
			return false;
		}

		if (self::$is_syncing) {
			return true;
		}
		self::$is_syncing = true;

		global $wpdb;
		$apps_table = CWD_V2_Trade_Applications::get_table_name();
		CWD_V2_Trade_Applications::ensure_table_exists();

		// Fetch any pending application for this user
		$pending_apps = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$apps_table} WHERE (user_id = %d OR applicant_email = %s) AND status = %s ORDER BY id DESC",
			$user_id,
			$user->user_email,
			CWD_V2_Trade_Applications::STATUS_PENDING
		));

		// Resolve approved credit limit
		if ($approved_limit === null || (float) $approved_limit <= 0) {
			$approved_limit = (float) get_user_meta($user_id, '_credit_limit', true);
			if ($approved_limit <= 0 && class_exists('CWD_V2_Credit_Logic')) {
				$approved_limit = CWD_V2_Credit_Logic::get_user_credit_limit($user_id);
			}
		}

		$now = current_time('mysql');

		if (! empty($pending_apps)) {
			foreach ($pending_apps as $app) {
				$limit_to_set = ($approved_limit > 0) ? $approved_limit : $app->requested_limit;
				$wpdb->update(
					$apps_table,
					array(
						'status'         => CWD_V2_Trade_Applications::STATUS_APPROVED,
						'approved_limit' => $limit_to_set,
						'admin_note'     => sprintf(__('Approved and synchronized via %s', 'custom-woo-dashboard'), $source_reason),
						'reviewed_at'    => $now,
						'user_id'        => $user_id,
						'updated_at'     => $now,
					),
					array('id' => $app->id),
					array('%s', '%f', '%s', '%s', '%d', '%s'),
					array('%d')
				);
			}
		} else {
			// If no trade application record exists at all, create an approved record so customer dashboard sees it
			$has_any = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$apps_table} WHERE user_id = %d OR applicant_email = %s",
				$user_id,
				$user->user_email
			));

			if ($has_any === 0 && $approved_limit > 0) {
				$applicant_name = trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name;
				$company_name   = get_user_meta($user_id, 'billing_company', true) ?: '';
				$phone          = get_user_meta($user_id, 'billing_phone', true) ?: '';

				$wpdb->insert(
					$apps_table,
					array(
						'forminator_form_id'  => 0,
						'forminator_entry_id' => 0,
						'applicant_email'     => $user->user_email,
						'applicant_name'      => $applicant_name,
						'company_name'        => $company_name,
						'phone'               => $phone,
						'requested_limit'     => $approved_limit,
						'form_data'           => wp_json_encode(array('source' => 'credits_plugin_direct_registration')),
						'status'              => CWD_V2_Trade_Applications::STATUS_APPROVED,
						'approved_limit'      => $approved_limit,
						'admin_note'          => sprintf(__('Created and approved via %s', 'custom-woo-dashboard'), $source_reason),
						'reviewed_at'         => $now,
						'user_id'             => $user_id,
						'created_at'          => $now,
						'updated_at'          => $now,
					),
					array('%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%f', '%s', '%s', '%d', '%s', '%s')
				);
			}
		}

		// Ensure credit_account role
		if (! in_array('credit_account', (array) $user->roles, true)) {
			$user->add_role('credit_account');
		}

		// Sync credit limit across all keys
		if ($approved_limit > 0 && class_exists('CWD_V2_Credit_Logic')) {
			CWD_V2_Credit_Logic::sync_user_credit_limit($user_id, $approved_limit);
			if (! metadata_exists('user', $user_id, '_credit_balance')) {
				CWD_V2_Credit_Logic::sync_user_credit_balance($user_id, 0);
			}
		}

		// Ensure EWS trade account number
		if (! get_user_meta($user_id, 'ews_account_number', true)) {
			$last_num = (int) get_option('cwd_v2_last_trade_number', 0);
			$last_num++;
			$trade_number = 'EWS-T' . str_pad($last_num, 4, '0', STR_PAD_LEFT);
			update_user_meta($user_id, 'ews_account_number', $trade_number);
			update_option('cwd_v2_last_trade_number', $last_num);
		}

		self::$is_syncing = false;
		return true;
	}

	/**
	 * Synchronize the Credits plugin when an approval occurs in WooCommerce > Trade Applications.
	 *
	 * @param int   $app_id          Trade application ID
	 * @param int   $user_id         User ID
	 * @param float $approved_limit  Approved credit limit
	 */
	public static function sync_credits_plugin_from_trade_approval($app_id, $user_id, $approved_limit)
	{
		$user_id = (int) $user_id;
		if (! $user_id) {
			return;
		}

		if (self::$is_syncing) {
			return;
		}
		self::$is_syncing = true;

		$user = get_userdata($user_id);
		if (! $user) {
			self::$is_syncing = false;
			return;
		}

		// 1. Sync all Credits plugin status meta keys to 'active'
		foreach (self::$credit_status_keys as $status_key) {
			update_user_meta($user_id, $status_key, 'active');
		}

		// 2. Sync all credit limit keys
		if (class_exists('CWD_V2_Credit_Logic')) {
			CWD_V2_Credit_Logic::sync_user_credit_limit($user_id, $approved_limit);
			if (! metadata_exists('user', $user_id, '_credit_balance')) {
				CWD_V2_Credit_Logic::sync_user_credit_balance($user_id, 0);
			}
		}

		// 3. Ensure role
		if (! in_array('credit_account', (array) $user->roles, true)) {
			$user->add_role('credit_account');
		}

		// 4. Update external custom tables to active
		self::sync_to_external_credits_tables($user_id, 'active', (float) $approved_limit, $user->user_email);

		// 5. Update external custom post types to publish
		self::sync_to_external_credits_cpt($user_id, 'active', (float) $approved_limit, $user->display_name, $user->user_email);

		// 6. Fire action hooks
		do_action('credits_user_activated', $user_id, $approved_limit);
		do_action('fpc_user_credit_approved', $user_id, $approved_limit);
		do_action('credits_credit_limit_updated', $user_id, $approved_limit);

		self::$is_syncing = false;
	}

	/**
	 * Intercept WordPress user meta changes in real time
	 */
	public static function on_user_meta_updated($meta_id, $object_id, $meta_key, $_meta_value)
	{
		if (self::$is_syncing) {
			return;
		}

		$user_id = (int) $object_id;
		if (! $user_id) {
			return;
		}

		// Check if the updated key is a credit status key
		if (in_array($meta_key, self::$credit_status_keys, true)) {
			$val = is_string($_meta_value) ? strtolower(trim($_meta_value)) : '';
			if (in_array($val, array('active', 'approved', 'publish'), true)) {
				self::sync_trade_application_from_credits_approval($user_id, null, 'Credits plugin (' . $meta_key . ' = active)');
			} elseif (in_array($val, array('rejected', 'declined'), true)) {
				self::mark_trade_application_rejected($user_id, 'Rejected via Credits plugin');
			}
			return;
		}

		// Check if the updated key is a credit limit key
		if (in_array($meta_key, self::$credit_limit_keys, true)) {
			$limit = (float) $_meta_value;
			if ($limit > 0) {
				self::sync_trade_application_from_credits_approval($user_id, $limit, 'Credits plugin (' . $meta_key . ' limit updated)');
			}
		}
	}

	/**
	 * Intercept role changes
	 */
	public static function on_user_role_changed($user_id, $role, $old_roles)
	{
		if (self::$is_syncing) {
			return;
		}
		if ('credit_account' === $role) {
			self::sync_trade_application_from_credits_approval($user_id, null, 'credit_account role change');
		}
	}

	/**
	 * Intercept custom post status transitions
	 */
	public static function on_post_status_transition($new_status, $old_status, $post)
	{
		if (self::$is_syncing || ! $post) {
			return;
		}

		$post_type = $post->post_type;
		if (preg_match('/(credit|fpc)/i', $post_type) && ! preg_match('/cwd_v2/i', $post_type)) {
			if (in_array($new_status, array('publish', 'active', 'approved'), true)) {
				$user_id = (int) $post->post_author;
				if (! $user_id) {
					$user_id = (int) get_post_meta($post->ID, '_user_id', true) ?: (int) get_post_meta($post->ID, 'user_id', true);
				}
				if ($user_id) {
					self::sync_trade_application_from_credits_approval($user_id, null, 'Credits post status (' . $post_type . ' -> ' . $new_status . ')');
				}
			}
		}
	}

	/**
	 * Intercept save_post for credit custom post types
	 */
	public static function on_post_saved($post_id, $post)
	{
		if (self::$is_syncing || ! $post || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
			return;
		}
		if (preg_match('/(credit|fpc)/i', $post->post_type) && ! preg_match('/cwd_v2/i', $post->post_type)) {
			if (in_array($post->post_status, array('publish', 'active', 'approved'), true)) {
				$user_id = (int) $post->post_author ?: (int) get_post_meta($post_id, '_user_id', true);
				if ($user_id) {
					self::sync_trade_application_from_credits_approval($user_id, null, 'Credits post save');
				}
			}
		}
	}

	/**
	 * Mark pending applications as rejected
	 */
	private static function mark_trade_application_rejected($user_id, $reason = '')
	{
		if (! class_exists('CWD_V2_Trade_Applications')) {
			return;
		}
		global $wpdb;
		$apps_table = CWD_V2_Trade_Applications::get_table_name();
		$wpdb->update(
			$apps_table,
			array(
				'status'      => CWD_V2_Trade_Applications::STATUS_REJECTED,
				'admin_note'  => $reason ?: __('Application declined', 'custom-woo-dashboard'),
				'reviewed_at' => current_time('mysql'),
				'updated_at'  => current_time('mysql'),
			),
			array(
				'user_id' => $user_id,
				'status'  => CWD_V2_Trade_Applications::STATUS_PENDING,
			),
			array('%s', '%s', '%s', '%s'),
			array('%d', '%s')
		);
	}

	/**
	 * Dynamic table synchronization with any external Credits tables
	 */
	private static function sync_to_external_credits_tables($user_id, $status = 'pending', $limit = 0.0, $email = '', $company = '')
	{
		global $wpdb;
		$tables = $wpdb->get_col("SHOW TABLES LIKE '%credit%'");
		$fpc_tables = $wpdb->get_col("SHOW TABLES LIKE '%fpc%'");
		$candidates = array_unique(array_merge($tables, $fpc_tables));

		foreach ($candidates as $table) {
			// Skip internal tables
			if (strpos($table, 'cwd_v2') !== false || strpos($table, 'woocommerce') !== false || strpos($table, 'posts') !== false || strpos($table, 'comments') !== false) {
				continue;
			}

			$columns = $wpdb->get_col("DESCRIBE {$table}");
			if (empty($columns)) {
				continue;
			}

			$has_user_col = in_array('user_id', $columns, true) ? 'user_id' : (in_array('customer_id', $columns, true) ? 'customer_id' : false);
			if (! $has_user_col) {
				continue;
			}

			// Check if row exists for user
			$row_exists = (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table} WHERE {$has_user_col} = %d LIMIT 1", $user_id));

			$status_val = ('active' === $status) ? (in_array('status', $columns, true) ? 'active' : 1) : (in_array('status', $columns, true) ? 'pending' : 0);

			if ($row_exists) {
				$update_data = array();
				$update_format = array();
				if (in_array('status', $columns, true)) {
					$update_data['status'] = $status_val;
					$update_format[] = is_numeric($status_val) ? '%d' : '%s';
				}
				if ($limit > 0) {
					foreach (array('credit_limit', 'limit', 'credit_amount', 'amount') as $lcol) {
						if (in_array($lcol, $columns, true)) {
							$update_data[$lcol] = (float) $limit;
							$update_format[] = '%f';
							break;
						}
					}
				}
				if (! empty($update_data)) {
					$wpdb->update($table, $update_data, array($has_user_col => $user_id), $update_format, array('%d'));
				}
			} else {
				$insert_data = array($has_user_col => $user_id);
				$insert_format = array('%d');
				if (in_array('status', $columns, true)) {
					$insert_data['status'] = $status_val;
					$insert_format[] = is_numeric($status_val) ? '%d' : '%s';
				}
				if ($limit > 0) {
					foreach (array('credit_limit', 'limit', 'credit_amount', 'amount') as $lcol) {
						if (in_array($lcol, $columns, true)) {
							$insert_data[$lcol] = (float) $limit;
							$insert_format[] = '%f';
							break;
						}
					}
				}
				if (in_array('email', $columns, true) && $email) {
					$insert_data['email'] = sanitize_email($email);
					$insert_format[] = '%s';
				}
				if (in_array('created_at', $columns, true)) {
					$insert_data['created_at'] = current_time('mysql');
					$insert_format[] = '%s';
				}
				$wpdb->insert($table, $insert_data, $insert_format);
			}
		}
	}

	/**
	 * Dynamic CPT synchronization with any external Credits post types
	 */
	private static function sync_to_external_credits_cpt($user_id, $status = 'pending', $limit = 0.0, $name = '', $email = '', $company = '')
	{
		$post_types = get_post_types();
		$matching_cpts = array();
		foreach ($post_types as $pt) {
			if (preg_match('/(credit|fpc)/i', $pt) && ! preg_match('/cwd_v2/i', $pt)) {
				$matching_cpts[] = $pt;
			}
		}

		if (empty($matching_cpts)) {
			return;
		}

		$post_status = ('active' === $status) ? 'publish' : 'pending';

		foreach ($matching_cpts as $cpt) {
			// Find if post exists
			$existing_post = get_posts(array(
				'post_type'   => $cpt,
				'post_status' => 'any',
				'author'      => $user_id,
				'numberposts' => 1,
			));

			if (! empty($existing_post)) {
				wp_update_post(array(
					'ID'          => $existing_post[0]->ID,
					'post_status' => $post_status,
				));
				if ($limit > 0) {
					update_post_meta($existing_post[0]->ID, '_credit_limit', (float) $limit);
					update_post_meta($existing_post[0]->ID, 'credit_limit', (float) $limit);
				}
			} else {
				$post_title = ($name ?: 'Applicant') . ' (' . ($email ?: ('User #' . $user_id)) . ')';
				$new_id = wp_insert_post(array(
					'post_title'   => $post_title,
					'post_type'    => $cpt,
					'post_status'  => $post_status,
					'post_author'  => $user_id,
				));
				if ($new_id && ! is_wp_error($new_id)) {
					update_post_meta($new_id, '_user_id', $user_id);
					update_post_meta($new_id, '_applicant_email', $email);
					update_post_meta($new_id, '_company_name', $company);
					if ($limit > 0) {
						update_post_meta($new_id, '_credit_limit', (float) $limit);
						update_post_meta($new_id, '_requested_limit', (float) $limit);
					}
				}
			}
		}
	}

	/**
	 * Reconcile pending trade applications against the Credits plugin state
	 *
	 * @param int $user_id Optional user ID to reconcile. 0 = all pending.
	 */
	public static function reconcile_pending_applications($user_id = 0)
	{
		if (! class_exists('CWD_V2_Trade_Applications')) {
			return;
		}

		global $wpdb;
		$apps_table = CWD_V2_Trade_Applications::get_table_name();
		CWD_V2_Trade_Applications::ensure_table_exists();

		if ($user_id > 0) {
			$pending = $wpdb->get_results($wpdb->prepare(
				"SELECT * FROM {$apps_table} WHERE user_id = %d AND status = %s",
				$user_id,
				CWD_V2_Trade_Applications::STATUS_PENDING
			));
		} else {
			$pending = $wpdb->get_results($wpdb->prepare(
				"SELECT * FROM {$apps_table} WHERE status = %s ORDER BY id DESC LIMIT 50",
				CWD_V2_Trade_Applications::STATUS_PENDING
			));
		}

		if (empty($pending)) {
			return;
		}

		foreach ($pending as $app) {
			$uid = (int) $app->user_id;
			if (! $uid && ! empty($app->applicant_email)) {
				$u = get_user_by('email', $app->applicant_email);
				if ($u) {
					$uid = $u->ID;
					$wpdb->update($apps_table, array('user_id' => $uid), array('id' => $app->id), array('%d'), array('%d'));
				}
			}

			if (! $uid) {
				continue;
			}

			// Check if user is approved in Credits plugin via meta or limit
			$is_approved = false;
			$detected_limit = 0.0;

			// Check credit limits
			foreach (self::$credit_limit_keys as $lkey) {
				$val = (float) get_user_meta($uid, $lkey, true);
				if ($val > 0) {
					$detected_limit = $val;
					$is_approved    = true;
					break;
				}
			}

			// Check status keys
			if (! $is_approved) {
				foreach (self::$credit_status_keys as $skey) {
					$sval = strtolower(trim((string) get_user_meta($uid, $skey, true)));
					if (in_array($sval, array('active', 'approved', 'publish'), true)) {
						$is_approved = true;
						break;
					}
				}
			}

			// Check user role
			if (! $is_approved) {
				$u = get_userdata($uid);
				if ($u && in_array('credit_account', (array) $u->roles, true)) {
					$is_approved = true;
				}
			}

			if ($is_approved) {
				self::sync_trade_application_from_credits_approval(
					$uid,
					($detected_limit > 0) ? $detected_limit : $app->requested_limit,
					'Automatic Credits Reconciliation'
				);
			}
		}
	}

	/**
	 * Run reconciliation on admin page view if viewing Trade Applications or Credits
	 */
	public static function maybe_reconcile_admin_requests()
	{
		if (! is_admin() || ! current_user_can('manage_woocommerce')) {
			return;
		}

		$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
		$post_type = isset($_GET['post_type']) ? sanitize_text_field(wp_unslash($_GET['post_type'])) : '';

		if ('cwd-v2-trade-applications' === $page || preg_match('/credit/i', $page) || preg_match('/credit/i', $post_type)) {
			self::reconcile_pending_applications();
		}
	}
}
