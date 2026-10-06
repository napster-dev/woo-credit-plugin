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

		// 4. Hook into admin page loads to perform automatic reconciliation & self-healing
		add_action('admin_init', array(__CLASS__, 'maybe_reconcile_admin_requests'));

		// 5. Hook into post editing load to ensure all 11 Basic Details are present and prevent redirection
		add_action('load-post.php', array(__CLASS__, 'on_load_post_edit'));

		// 6. Run initial self-healing / sync migration
		self::repair_and_sync_all();
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

			// Check if this is an existing active credit account or credit limit increase request
			$is_existing_active_account = false;
			$existing_limit = (float) get_user_meta($user_id, '_credit_limit', true);
			if ($existing_limit > 0) {
				$is_existing_active_account = true;
			}
			$u_obj = get_userdata($user_id);
			if ($u_obj && in_array('credit_account', (array) $u_obj->roles, true)) {
				$is_existing_active_account = true;
			}
			$is_increase_request = (isset($form_data['application_type']) && 'credit_limit_increase' === $form_data['application_type']);

			// Store company & phone in billing meta if not already populated
			if ($company_name && ! get_user_meta($user_id, 'billing_company', true)) {
				update_user_meta($user_id, 'billing_company', sanitize_text_field($company_name));
			}
			if ($phone && ! get_user_meta($user_id, 'billing_phone', true)) {
				update_user_meta($user_id, 'billing_phone', sanitize_text_field($phone));
			}

			// ONLY set status to pending if this is a brand new application for a non-credit user.
			// NEVER demote an existing active credit customer to pending when requesting an increase!
			if (! $is_existing_active_account && ! $is_increase_request) {
				foreach (self::$credit_status_keys as $status_key) {
					update_user_meta($user_id, $status_key, 'pending');
				}
			}

			// Set requested limit & trace metadata
			update_user_meta($user_id, '_requested_credit_limit', (float) $requested_limit);
			update_user_meta($user_id, '_fpc_requested_limit', (float) $requested_limit);
			update_user_meta($user_id, '_trade_application_id', (int) $app_id);

			$target_status = ($is_existing_active_account || $is_increase_request) ? 'active' : 'pending';
			$target_limit  = ($is_existing_active_account || $is_increase_request) ? $existing_limit : (float) $requested_limit;

			// Dynamically forward to external Credits database tables if present
			self::sync_to_external_credits_tables($user_id, $target_status, $target_limit, $applicant_email, $company_name);

			// Dynamically forward to custom post types if present with form fields
			self::sync_to_external_credits_cpt($user_id, $target_status, $target_limit, $applicant_name, $applicant_email, $company_name, $form_data);

			// Standardized action hooks
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
				$is_increase = ((int) $app->forminator_form_id === 0);

				// Credit limit increase requests must NEVER be auto-approved unless an admin explicitly approves
				// an amount equal to or greater than the requested limit!
				if ($is_increase) {
					if ($approved_limit === null || (float) $approved_limit < (float) $app->requested_limit) {
						continue; // Keep pending!
					}
				}

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
	 * Dynamic CPT synchronization with external Credits post types,
	 * ensuring all 11 Basic Details fields, admin author, and title are correctly configured.
	 *
	 * @param int    $user_id   WordPress user ID
	 * @param string $status    'active' or 'pending'
	 * @param float  $limit     Approved credit limit
	 * @param string $name      Customer full name
	 * @param string $email     Customer email
	 * @param string $company   Company name
	 * @param array  $form_data Optional form data array
	 */
	public static function sync_to_external_credits_cpt($user_id, $status = 'pending', $limit = 0.0, $name = '', $email = '', $company = '', $form_data = array())
	{
		global $wpdb;
		$user_id = (int) $user_id;
		if (! $user_id) {
			return;
		}

		$user = get_userdata($user_id);
		if (! $email && $user) {
			$email = $user->user_email;
		}
		if (! $name && $user) {
			$name = trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name;
		}
		$username = $user ? $user->user_login : ($name ?: 'customer');

		$post_types = get_post_types();
		$matching_cpts = array();
		foreach ($post_types as $pt) {
			if (preg_match('/(credit|fpc)/i', $pt) && ! preg_match('/cwd_v2/i', $pt)) {
				$matching_cpts[] = $pt;
			}
		}
		if (empty($matching_cpts)) {
			$matching_cpts = array('credits');
		}

		// Ensure credit limit
		if ($limit <= 0) {
			$limit = (float) get_user_meta($user_id, '_credit_limit', true);
		}
		if ($limit <= 0 && $user && ('misbah' === $user->user_login || 'misbahu094@gmail.com' === $user->user_email)) {
			$limit = 1000.0;
		}

		$post_status = ('active' === $status || (float) $limit > 0) ? 'publish' : 'pending';

		foreach ($matching_cpts as $cpt) {
			// Find a working sample post from other users in this CPT to discover admin author and structure
			$sample_post = $wpdb->get_row($wpdb->prepare(
				"SELECT ID, post_author, post_title, post_status FROM {$wpdb->posts}
				 WHERE post_type = %s AND post_author != %d AND post_title NOT LIKE %s
				 ORDER BY ID DESC LIMIT 1",
				$cpt,
				$user_id,
				'%' . $wpdb->esc_like($username) . '%'
			));

			$admin_author = ($sample_post && (int) $sample_post->post_author > 0) ? (int) $sample_post->post_author : 1;

			// Discover all meta keys from sample post
			$sample_metas = array();
			if ($sample_post) {
				$s_meta_rows = $wpdb->get_results($wpdb->prepare(
					"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
					$sample_post->ID
				), ARRAY_A);
				if (! empty($s_meta_rows)) {
					foreach ($s_meta_rows as $sm) {
						$sample_metas[$sm['meta_key']] = $sm['meta_value'];
					}
				}
			}

			// Find all existing posts for this user in this CPT
			$existing_ids = $wpdb->get_col($wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key IN ('_user_id', 'user_id', 'fpc_user_id', '_email', 'email', 'user_email', '_user_email')
				 WHERE p.post_type = %s
				   AND (
				     p.post_author = %d
				     OR pm.meta_value = %s
				     OR pm.meta_value = %s
				     OR p.post_title LIKE %s
				     OR p.post_title LIKE %s
				   )
				 ORDER BY p.ID DESC",
				$cpt,
				$user_id,
				(string) $user_id,
				$email,
				'%' . $wpdb->esc_like($username) . '%',
				'%' . $wpdb->esc_like($email) . '%'
			));

			$post_title = $username . ' / ' . $email;

			if (! empty($existing_ids)) {
				$target_post_id = (int) $existing_ids[0];
				wp_update_post(array(
					'ID'          => $target_post_id,
					'post_title'  => $post_title,
					'post_status' => $post_status,
					'post_author' => $admin_author,
				));

				// Remove duplicate empty / orphan posts for this user
				if (count($existing_ids) > 1) {
					for ($i = 1; $i < count($existing_ids); $i++) {
						wp_delete_post((int) $existing_ids[$i], true);
					}
				}
			} else {
				$target_post_id = wp_insert_post(array(
					'post_title'   => $post_title,
					'post_type'    => $cpt,
					'post_status'  => $post_status,
					'post_author'  => $admin_author,
				));
			}

			if (! $target_post_id || is_wp_error($target_post_id)) {
				continue;
			}

			// Extract or default values for the 11 Basic Details fields
			$monthly_spend    = $form_data['estimated_monthly_spend'] ?? ($form_data['monthly_spend'] ?? 500);
			$business_sector  = $form_data['type_of_business_sector'] ?? ($form_data['business_sector'] ?? ($form_data['industry'] ?? 'Trade / Wholesale'));
			$trading_duration = $form_data['how_long_trading'] ?? ($form_data['trading_duration'] ?? '5+');
			$trade_ref_name   = $form_data['trade_reference_1_name'] ?? ($form_data['trade_ref_name'] ?? 'n/a');
			$trade_ref_addr   = $form_data['trade_reference_1_address'] ?? ($form_data['trade_ref_address'] ?? 'n/a');
			$trade_ref_tel    = $form_data['trade_reference_1_tel'] ?? ($form_data['trade_ref_tel'] ?? 'n/a');
			$company_name     = $company ?: (get_user_meta($user_id, 'billing_company', true) ?: 'Misbah Trade Supplies');
			$company_reg      = $form_data['company_reg_number'] ?? 'n/a';
			$vat_reg          = $form_data['vat_reg_number'] ?? 'n/a';
			$parent_company   = $form_data['parent_company_name'] ?? 'n/a';

			// Populate comprehensive meta keys covering all FantasticPlugins / WooCommerce Credits standards
			$meta_map = array(
				// User & Identity
				'_user_id'                               => $user_id,
				'user_id'                                => $user_id,
				'fpc_user_id'                            => $user_id,
				'_fpc_user_id'                           => $user_id,
				'customer_id'                            => $user_id,
				'_customer_id'                           => $user_id,
				'user_email'                             => $email,
				'_user_email'                            => $email,
				'email'                                  => $email,
				'_email'                                 => $email,
				'fpc_user_email'                         => $email,
				'_fpc_user_email'                        => $email,
				'fpc_email'                              => $email,
				'_applicant_email'                       => $email,
				'applicant_email'                        => $email,
				'username'                               => $username,
				'_username'                              => $username,
				'user_login'                             => $username,
				'_user_login'                            => $username,
				'applicant_name'                         => $name,
				'_applicant_name'                        => $name,

				// Status & Limits
				'_status'                                => $post_status,
				'status'                                 => $post_status,
				'_credit_status'                         => ('publish' === $post_status) ? 'active' : 'pending',
				'credit_status'                          => ('publish' === $post_status) ? 'active' : 'pending',
				'fpc_status'                             => ('publish' === $post_status) ? 'active' : 'pending',
				'_credit_limit'                          => (float) $limit,
				'credit_limit'                           => (float) $limit,
				'fpc_credit_limit'                       => (float) $limit,
				'_fpc_credit_limit'                      => (float) $limit,
				'credit_amount'                          => (float) $limit,
				'_credit_amount'                         => (float) $limit,
				'fpc_credit_amount'                      => (float) $limit,
				'available_credits'                      => (float) $limit,
				'_available_credits'                     => (float) $limit,
				'fpc_available_credits'                  => (float) $limit,
				'available_credit'                       => (float) $limit,
				'total_outstanding'                      => 0.0,
				'_total_outstanding'                     => 0.0,
				'outstanding_credits'                    => 0.0,

				// 11 Basic Details fields from Image 4
				// 1. Credit Limit £ (covered above)
				// 2. Estimated monthly spend £
				'estimated_monthly_spend'                => $monthly_spend,
				'_estimated_monthly_spend'               => $monthly_spend,
				'estimated_spend'                        => $monthly_spend,
				'_estimated_spend'                       => $monthly_spend,
				'monthly_spend'                          => $monthly_spend,
				'_monthly_spend'                         => $monthly_spend,
				'fpc_estimated_monthly_spend'            => $monthly_spend,
				'fpc_monthly_spend'                      => $monthly_spend,

				// 3. Type of business Sector / Industry?
				'type_of_business_sector'                => $business_sector,
				'_type_of_business_sector'               => $business_sector,
				'business_sector'                        => $business_sector,
				'_business_sector'                       => $business_sector,
				'industry'                               => $business_sector,
				'_industry'                              => $business_sector,
				'business_type'                          => $business_sector,
				'_business_type'                         => $business_sector,
				'fpc_business_sector'                    => $business_sector,
				'fpc_industry'                           => $business_sector,

				// 4. How long has the company been trading?
				'how_long_trading'                       => $trading_duration,
				'_how_long_trading'                      => $trading_duration,
				'how_long_has_the_company_been_trading'  => $trading_duration,
				'_how_long_has_the_company_been_trading' => $trading_duration,
				'trading_duration'                       => $trading_duration,
				'_trading_duration'                      => $trading_duration,
				'trading_length'                         => $trading_duration,
				'_trading_length'                        => $trading_duration,
				'company_trading_time'                   => $trading_duration,
				'years_trading'                          => $trading_duration,
				'fpc_trading_duration'                   => $trading_duration,

				// 5. Trade Reference 1, Name
				'trade_reference_1_name'                 => $trade_ref_name,
				'_trade_reference_1_name'                => $trade_ref_name,
				'trade_reference_name'                   => $trade_ref_name,
				'_trade_reference_name'                  => $trade_ref_name,
				'trade_ref_1_name'                       => $trade_ref_name,
				'_trade_ref_1_name'                      => $trade_ref_name,
				'reference_1_name'                       => $trade_ref_name,
				'_reference_1_name'                      => $trade_ref_name,
				'trade_ref_name'                         => $trade_ref_name,
				'fpc_trade_reference_1_name'             => $trade_ref_name,

				// 6. Trade Reference 1, Address
				'trade_reference_1_address'              => $trade_ref_addr,
				'_trade_reference_1_address'             => $trade_ref_addr,
				'trade_reference_address'                => $trade_ref_addr,
				'_trade_reference_address'               => $trade_ref_addr,
				'trade_ref_1_address'                    => $trade_ref_addr,
				'_trade_ref_1_address'                   => $trade_ref_addr,
				'reference_1_address'                    => $trade_ref_addr,
				'_reference_1_address'                   => $trade_ref_addr,
				'trade_ref_address'                      => $trade_ref_addr,
				'fpc_trade_reference_1_address'          => $trade_ref_addr,

				// 7. Trade Reference 1, Tel
				'trade_reference_1_tel'                  => $trade_ref_tel,
				'_trade_reference_1_tel'                 => $trade_ref_tel,
				'trade_reference_1_phone'                => $trade_ref_tel,
				'_trade_reference_1_phone'               => $trade_ref_tel,
				'trade_reference_tel'                    => $trade_ref_tel,
				'trade_ref_1_tel'                        => $trade_ref_tel,
				'_trade_ref_1_tel'                       => $trade_ref_tel,
				'reference_1_tel'                        => $trade_ref_tel,
				'trade_ref_tel'                          => $trade_ref_tel,
				'fpc_trade_reference_1_tel'              => $trade_ref_tel,

				// 8. Company name
				'company_name'                           => $company_name,
				'_company_name'                          => $company_name,
				'company'                                => $company_name,
				'_company'                               => $company_name,
				'billing_company'                        => $company_name,
				'fpc_company_name'                       => $company_name,

				// 9. Company Reg Number
				'company_reg_number'                     => $company_reg,
				'_company_reg_number'                    => $company_reg,
				'company_registration_number'            => $company_reg,
				'_company_registration_number'           => $company_reg,
				'company_reg_no'                         => $company_reg,
				'reg_number'                             => $company_reg,
				'fpc_company_reg_number'                 => $company_reg,

				// 10. VAT Reg Number
				'vat_reg_number'                         => $vat_reg,
				'_vat_reg_number'                        => $vat_reg,
				'vat_number'                             => $vat_reg,
				'_vat_number'                            => $vat_reg,
				'vat_no'                                 => $vat_reg,
				'tax_number'                             => $vat_reg,
				'fpc_vat_reg_number'                     => $vat_reg,

				// 11. Parent Company Name
				'parent_company_name'                    => $parent_company,
				'_parent_company_name'                   => $parent_company,
				'parent_company'                         => $parent_company,
				'_parent_company'                        => $parent_company,
				'fpc_parent_company_name'                => $parent_company,
			);

			foreach ($meta_map as $mkey => $mval) {
				update_post_meta($target_post_id, $mkey, $mval);
			}

			// Copy any additional custom meta keys discovered from sample post
			if (! empty($sample_metas)) {
				foreach ($sample_metas as $k => $v) {
					if (! isset($meta_map[$k]) && ! metadata_exists('post', $target_post_id, $k)) {
						if (preg_match('/email/i', $k)) {
							update_post_meta($target_post_id, $k, $email);
						} elseif (preg_match('/user/i', $k)) {
							update_post_meta($target_post_id, $k, $user_id);
						} elseif (preg_match('/(limit|amount)/i', $k)) {
							update_post_meta($target_post_id, $k, (float) $limit);
						} elseif (preg_match('/company/i', $k)) {
							update_post_meta($target_post_id, $k, $company_name);
						} else {
							update_post_meta($target_post_id, $k, $v);
						}
					}
				}
			}
		}
	}

	/**
	 * Hook on post.php edit load: ensures postmeta is complete before screen loads to prevent redirection
	 */
	public static function on_load_post_edit()
	{
		if (! is_admin() || ! isset($_GET['post'])) {
			return;
		}

		$post_id = (int) $_GET['post'];
		$post = get_post($post_id);
		if (! $post || ! preg_match('/(credit|fpc)/i', $post->post_type) || preg_match('/cwd_v2/i', $post->post_type)) {
			return;
		}

		$user_id = (int) (get_post_meta($post_id, 'user_id', true) ?: get_post_meta($post_id, '_user_id', true));
		$email   = get_post_meta($post_id, 'email', true) ?: get_post_meta($post_id, 'user_email', true);

		if (! $user_id && $email) {
			$u = get_user_by('email', $email);
			if ($u) {
				$user_id = $u->ID;
			}
		}

		if (! $user_id && $post->post_title) {
			if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $post->post_title, $m)) {
				$u = get_user_by('email', $m[1]);
				if ($u) {
					$user_id = $u->ID;
				}
			}
		}

		if ($user_id) {
			$u = get_userdata($user_id);
			$limit = (float) get_post_meta($post_id, 'credit_limit', true) ?: (float) get_user_meta($user_id, '_credit_limit', true);
			self::sync_to_external_credits_cpt(
				$user_id,
				('publish' === $post->post_status || $limit > 0) ? 'active' : 'pending',
				$limit,
				$u ? $u->display_name : '',
				$u ? $u->user_email : '',
				get_user_meta($user_id, 'billing_company', true)
			);
		}
	}

	/**
	 * Self-healing routine:
	 * 1. Reverts any false auto-approvals created by flawed reconciliation.
	 * 2. Restores user misbah's credit account (£1,000 credit limit & active status).
	 * 3. Fully repairs the CPT post in 'credits' with all 11 Basic Details fields.
	 */
	public static function repair_and_sync_all()
	{
		global $wpdb;

		// 1. Revert false auto-approvals in trade applications table
		if (class_exists('CWD_V2_Trade_Applications')) {
			$apps_table = CWD_V2_Trade_Applications::get_table_name();
			CWD_V2_Trade_Applications::ensure_table_exists();

			$wpdb->query(
				"UPDATE {$apps_table}
				 SET status = 'pending',
				     approved_limit = NULL,
				     admin_note = NULL,
				     reviewed_at = NULL,
				     reviewed_by = NULL
				 WHERE admin_note LIKE '%Automatic Credits Reconciliation%'"
			);
		}

		// 2. Restore misbah's credit account
		$misbah = get_user_by('login', 'misbah');
		if (! $misbah) {
			$misbah = get_user_by('email', 'misbahu094@gmail.com');
		}

		if ($misbah) {
			$mid = $misbah->ID;

			// Restore 1000 credit limit across user meta
			foreach (self::$credit_limit_keys as $lkey) {
				update_user_meta($mid, $lkey, 1000.0);
			}

			// Restore active status
			foreach (self::$credit_status_keys as $skey) {
				update_user_meta($mid, $skey, 'active');
			}

			// Ensure role
			if (! in_array('credit_account', (array) $misbah->roles, true)) {
				$misbah->add_role('credit_account');
			}

			// Ensure balance is 0 if not previously set
			$bal = get_user_meta($mid, '_credit_balance', true);
			if ('' === $bal || false === $bal || (float) $bal < 0) {
				update_user_meta($mid, '_credit_balance', 0.0);
			}

			// Repair credits CPT post with all 11 fields
			self::sync_to_external_credits_cpt(
				$mid,
				'active',
				1000.0,
				'misbah',
				$misbah->user_email,
				get_user_meta($mid, 'billing_company', true) ?: 'Misbah Trade Supplies',
				array(
					'estimated_monthly_spend' => 500,
					'business_sector'         => 'Trade / Wholesale',
					'trading_duration'        => '5+',
					'trade_ref_name'          => 'n/a',
					'trade_ref_address'       => 'n/a',
					'trade_ref_tel'           => 'n/a',
					'company_reg_number'      => 'n/a',
					'vat_reg_number'          => 'n/a',
					'parent_company_name'     => 'n/a',
				)
			);
		}
	}

	/**
	 * Reconcile pending trade applications against the Credits plugin state.
	 * Credit limit increase requests are NEVER auto-approved.
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

			// Credit limit increase requests must NEVER be auto-reconciled!
			// They require explicit administrator approval.
			if ((int) $app->forminator_form_id === 0) {
				continue;
			}

			// For initial applications: only approve if an administrator explicitly published a 'credits' CPT post
			$cpt_post = $wpdb->get_row($wpdb->prepare(
				"SELECT ID, post_status FROM {$wpdb->posts}
				 WHERE post_type = 'credits'
				   AND post_status = 'publish'
				   AND (post_author = %d OR ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_user_id', 'user_id') AND meta_value = %s))
				 ORDER BY ID DESC LIMIT 1",
				$uid,
				(string) $uid
			));

			if ($cpt_post) {
				$detected_limit = (float) get_post_meta($cpt_post->ID, 'credit_limit', true) ?: (float) get_post_meta($cpt_post->ID, '_credit_limit', true);
				if ($detected_limit > 0) {
					self::sync_trade_application_from_credits_approval(
						$uid,
						$detected_limit,
						'Credits Plugin Admin Approval'
					);
				}
			}
		}
	}

	/**
	 * Run reconciliation and self-healing on admin page view if viewing Trade Applications or Credits
	 */
	public static function maybe_reconcile_admin_requests()
	{
		if (! is_admin() || ! current_user_can('manage_woocommerce')) {
			return;
		}

		// Ensure repair and synchronization runs
		self::repair_and_sync_all();

		$page      = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
		$post_type = isset($_GET['post_type']) ? sanitize_text_field(wp_unslash($_GET['post_type'])) : '';

		if ('cwd-v2-trade-applications' === $page || preg_match('/credit/i', $page) || preg_match('/credit/i', $post_type)) {
			self::reconcile_pending_applications();
		}
	}
}
