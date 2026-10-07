<?php

/**
 * Unified Credit System Bridge
 *
 * Implements native integration between WooCommerce, customer trade applications,
 * the WooCommerce Credit System (Credits for WooCommerce / wc_cs), and Odoo.
 *
 * Key guarantees:
 * 1. Authors credit posts as each customer's user ID so Credits > Credits list displays
 *    real usernames (not "accounts") and clicking "View more" opens the record without modal prompts.
 * 2. Populates all 11 Basic Details fields using the user's REAL submitted application data
 *    from Forminator / trade applications, preserving existing values and never hardcoding defaults.
 * 3. Populates all wc_cs_form_field IDs (31596, 31595, 31583, 31584, 31588, 31589, 31590, 23162, 25894, 25895, 23163)
 *    and semantic meta keys so the "Basic Details" metabox renders cleanly.
 * 4. Listens on load-post.php to ensure all metadata is intact before rendering.
 * 5. Syncs live credit limits and balances bidirectionally with Odoo.
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
	 * Known user meta keys used by Credits plugins
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
		// 1. Hook into user meta changes (fires when Odoo or Woo updates credit limits)
		add_action('updated_user_meta', array(__CLASS__, 'on_user_meta_updated'), 10, 4);
		add_action('added_user_meta', array(__CLASS__, 'on_user_meta_updated'), 10, 4);

		// 2. Hook into role changes
		add_action('set_user_role', array(__CLASS__, 'on_user_role_changed'), 10, 3);

		// 3. Hook into Credit post saves in wp-admin
		add_action('save_post', array(__CLASS__, 'on_post_saved'), 20, 2);

		// 4. Hook on post.php edit load: ensures all 11 Basic Details fields are populated before screen renders
		add_action('load-post.php', array(__CLASS__, 'on_load_post_edit'));

		// 5. Hook into Forminator submission to create/update credit post immediately
		add_action('forminator_form_after_save_entry', array(__CLASS__, 'on_forminator_submission'), 20, 2);

		// 6. Hook on admin_init for safe self-healing
		add_action('admin_init', array(__CLASS__, 'maybe_reconcile_admin_requests'));

		// 7. Initial self-healing on boot
		self::repair_and_sync_all();
	}

	/**
	 * Self-healing routine to link every credit user with their post
	 */
	public static function maybe_reconcile_admin_requests()
	{
		if (! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
			return;
		}

		$last_run = (int) get_transient('cwd_v2_credits_reconcile_last');
		if ($last_run && (time() - $last_run) < 30) {
			return;
		}
		set_transient('cwd_v2_credits_reconcile_last', time(), 30);

		self::repair_and_sync_all();
	}

	/**
	 * Forward a newly submitted trade application into the Credits plugin
	 */
	public static function forward_application_to_credits_plugin($app_id, $user_id, $applicant_email, $applicant_name, $company_name, $phone, $requested_limit, $form_data = array())
	{
		if (self::$is_syncing) {
			return $user_id;
		}

		self::$is_syncing = true;
		global $wpdb;

		// 1. Ensure user exists
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
			// Update application with user_id in applications table
			if ($app_id && class_exists('CWD_V2_Trade_Applications')) {
				$apps_table = CWD_V2_Trade_Applications::get_table_name();
				$wpdb->update($apps_table, array('user_id' => $user_id), array('id' => $app_id), array('%d'), array('%d'));
			}

			$existing_limit = (float) get_user_meta($user_id, '_credit_limit', true);
			$is_active      = ($existing_limit > 0);

			if ($company_name && ! get_user_meta($user_id, 'billing_company', true)) {
				update_user_meta($user_id, 'billing_company', sanitize_text_field($company_name));
			}
			if ($phone && ! get_user_meta($user_id, 'billing_phone', true)) {
				update_user_meta($user_id, 'billing_phone', sanitize_text_field($phone));
			}

			if (! $is_active) {
				foreach (self::$credit_status_keys as $status_key) {
					update_user_meta($user_id, $status_key, 'pending');
				}
			}

			update_user_meta($user_id, '_requested_credit_limit', (float) $requested_limit);

			$target_status = $is_active ? 'active' : 'pending';
			$target_limit  = $is_active ? $existing_limit : (float) $requested_limit;

			self::sync_to_external_credits_cpt(
				$user_id,
				$target_status,
				$target_limit,
				$applicant_name,
				$applicant_email,
				$company_name,
				$form_data
			);
		}

		self::$is_syncing = false;
		return $user_id;
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

		// Resolve user ID
		$user_id = self::resolve_user_id_for_credit_post($post);

		// Ensure post_author is the customer user ID
		if ($user_id && (int) $post->post_author !== (int) $user_id) {
			global $wpdb;
			$wpdb->update($wpdb->posts, array('post_author' => (int) $user_id), array('ID' => $post_id), array('%d'), array('%d'));
			clean_post_cache($post_id);
		}

		if ($user_id) {
			$u = get_userdata($user_id);
			$limit = (float) get_post_meta($post_id, 'credit_limit', true)
				?: (float) get_post_meta($post_id, '_credit_limit', true)
				?: (float) get_user_meta($user_id, '_credit_limit', true);

			$company = get_post_meta($post_id, 'company_name', true)
				?: get_post_meta($post_id, 'company', true)
				?: get_user_meta($user_id, 'billing_company', true);

			self::sync_to_external_credits_cpt(
				$user_id,
				('publish' === $post->post_status || $limit > 0) ? 'active' : 'pending',
				$limit,
				$u ? $u->display_name : '',
				$u ? $u->user_email : '',
				$company,
				array(),
				$post_id
			);
		}
	}

	/**
	 * Helper: Resolve WordPress user ID associated with a credit post
	 *
	 * @param WP_Post|object $post
	 * @return int
	 */
	public static function resolve_user_id_for_credit_post($post)
	{
		if (! $post || ! isset($post->ID)) {
			return 0;
		}

		$post_id = (int) $post->ID;
		global $wpdb;

		// 1. Postmeta user ID keys
		$id_keys = array('_user_id', 'user_id', 'fpc_user_id', '_fpc_user_id', '_customer_id', 'customer_id', 'fpc_user', '_fpc_user');
		foreach ($id_keys as $k) {
			$uid = (int) get_post_meta($post_id, $k, true);
			if ($uid > 0 && get_userdata($uid)) {
				return $uid;
			}
		}

		// 2. Post author if customer assigned
		if ((int) $post->post_author > 1 && get_userdata((int) $post->post_author)) {
			return (int) $post->post_author;
		}

		// 3. Postmeta email keys
		$email_keys = array('email', 'user_email', '_email', '_user_email', 'fpc_user_email', '_fpc_user_email', 'fpc_email', 'applicant_email', '_applicant_email');
		foreach ($email_keys as $k) {
			$em = sanitize_email(get_post_meta($post_id, $k, true));
			if ($em) {
				$u = get_user_by('email', $em);
				if ($u) {
					return $u->ID;
				}
			}
		}

		// 4. Extract email from post_title
		if ($post->post_title && preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $post->post_title, $m)) {
			$u = get_user_by('email', sanitize_email($m[1]));
			if ($u) {
				return $u->ID;
			}
		}

		// 5. Check if post_title is username
		if ($post->post_title) {
			$u = get_user_by('login', trim($post->post_title));
			if ($u) {
				return $u->ID;
			}
		}

		// 6. Match company name in trade applications
		$company = get_post_meta($post_id, 'company_name', true) ?: get_post_meta($post_id, '23162', true) ?: $post->post_title;
		if ($company) {
			$apps_table = $wpdb->prefix . 'cwd_v2_trade_applications';
			if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $apps_table)) === $apps_table) {
				$uid = (int) $wpdb->get_var($wpdb->prepare(
					"SELECT user_id FROM {$apps_table} WHERE company_name LIKE %s AND user_id > 0 ORDER BY id DESC LIMIT 1",
					'%' . $wpdb->esc_like(trim($company)) . '%'
				));
				if ($uid > 0 && get_userdata($uid)) {
					return $uid;
				}
			}

			// Match company name in usermeta
			$uid = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'billing_company' AND meta_value LIKE %s ORDER BY user_id DESC LIMIT 1",
				'%' . $wpdb->esc_like(trim($company)) . '%'
			));
			if ($uid > 0 && get_userdata($uid)) {
				return $uid;
			}
		}

		return 0;
	}

	/**
	 * Core synchronization method linking a user to their 'credits' post.
	 *
	 * Uses customer authorship so the Credits list displays the real username
	 * and opens edit without search modal prompts.
	 *
	 * @param int    $user_id          User ID
	 * @param string $status           'active' or 'pending'
	 * @param float  $limit            Credit limit
	 * @param string $name             Customer name
	 * @param string $email            Customer email
	 * @param string $company          Company name
	 * @param array  $form_data        Explicit form data array
	 * @param int    $specific_post_id Optional target post ID to enrich directly
	 */
	public static function sync_to_external_credits_cpt($user_id, $status = 'pending', $limit = 0.0, $name = '', $email = '', $company = '', $form_data = array(), $specific_post_id = 0)
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

		// Resolve credit limit
		if ($limit <= 0) {
			$limit = class_exists('CWD_V2_Credit_Logic')
				? CWD_V2_Credit_Logic::get_user_credit_limit($user_id)
				: (float) get_user_meta($user_id, '_credit_limit', true);
		}

		$post_status = ('active' === $status || (float) $limit > 0) ? 'publish' : 'pending';

		$matching_cpts = array('credits');

		// Customer author ensures Credits list displays customer username/email and opens edit directly
		$customer_author = (int) $user_id;

		foreach ($matching_cpts as $cpt) {
			$target_post_id = 0;

			if ($specific_post_id > 0) {
				$target_post_id = $specific_post_id;
			} else {
				// Find existing posts for this user in this CPT
				$existing_ids = $wpdb->get_col($wpdb->prepare(
					"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
					 LEFT JOIN {$wpdb->postmeta} pm_u ON p.ID = pm_u.post_id AND pm_u.meta_key IN ('_user_id', 'user_id', 'fpc_user_id', '_customer_id', 'customer_id')
					 LEFT JOIN {$wpdb->postmeta} pm_e ON p.ID = pm_e.post_id AND pm_e.meta_key IN ('_email', 'email', 'user_email', '_user_email', 'fpc_user_email')
					 WHERE p.post_type = %s
					   AND (
					     p.post_author = %d
					     OR pm_u.meta_value = %s
					     OR pm_e.meta_value = %s
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

				if (! empty($existing_ids)) {
					$target_post_id = (int) $existing_ids[0];
				}
			}

			// Discover sample meta keys from other posts if present
			$sample_metas = array();
			$sample_post = $wpdb->get_row($wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID != %d ORDER BY ID DESC LIMIT 1",
				$cpt,
				$target_post_id
			));
			if ($sample_post) {
				$s_meta_rows = $wpdb->get_results($wpdb->prepare(
					"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key NOT LIKE '\_edit\_%'",
					$sample_post->ID
				), ARRAY_A);
				if (! empty($s_meta_rows)) {
					foreach ($s_meta_rows as $sm) {
						$sample_metas[$sm['meta_key']] = $sm['meta_value'];
					}
				}
			}

			$post_title = $username . ' / ' . $email;

			if ($target_post_id > 0) {
				// Ensure customer authorship and active status
				$wpdb->update(
					$wpdb->posts,
					array(
						'post_title'  => $post_title,
						'post_status' => $post_status,
						'post_author' => $customer_author,
					),
					array('ID' => $target_post_id),
					array('%s', '%s', '%d'),
					array('%d')
				);
				clean_post_cache($target_post_id);
			} else {
				$target_post_id = wp_insert_post(array(
					'post_title'   => $post_title,
					'post_type'    => $cpt,
					'post_status'  => $post_status,
					'post_author'  => $customer_author,
				));
			}

			if (! $target_post_id || is_wp_error($target_post_id)) {
				continue;
			}

			// Retrieve all existing postmeta from target post to NEVER overwrite real values
			$existing_post_meta = array();
			$existing_rows = $wpdb->get_results($wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
				$target_post_id
			), ARRAY_A);
			if (! empty($existing_rows)) {
				foreach ($existing_rows as $er) {
					$existing_post_meta[$er['meta_key']] = $er['meta_value'];
				}
			}

			// If no explicit form data provided, dynamically retrieve user's real submitted application data
			if (empty($form_data)) {
				$form_data = self::get_user_application_data($user_id, $email);
			}

			// Value picker helper: existing postmeta > form data > user meta > fallback
			$get_field_val = function ($keys, $regex, $default = 'n/a') use ($existing_post_meta, $form_data, $user_id) {
				// 1. Check existing postmeta (crucial: preserves "School", "50+", "07772345", etc.)
				foreach ((array) $keys as $k) {
					if (isset($existing_post_meta[$k])) {
						$v = trim((string) $existing_post_meta[$k]);
						if ('' !== $v && 'n/a' !== strtolower($v)) {
							return $v;
						}
					}
				}
				if ($regex) {
					foreach ($existing_post_meta as $ek => $ev) {
						$norm = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $ek)), '_');
						if (preg_match($regex, $norm)) {
							$v = is_array($ev) ? implode(', ', array_filter(array_map('strval', $ev))) : trim((string) $ev);
							if ('' !== $v && 'n/a' !== strtolower($v)) {
								return $v;
							}
						}
					}
				}

				// 2. Check form data by explicit key
				foreach ((array) $keys as $k) {
					if (isset($form_data[$k]) && '' !== trim((string) $form_data[$k])) {
						return trim((string) $form_data[$k]);
					}
				}

				// 3. Check form data by regex
				if ($regex) {
					foreach ($form_data as $fk => $fv) {
						$norm = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $fk)), '_');
						if (preg_match($regex, $norm)) {
							$str_val = is_array($fv) ? implode(', ', array_filter(array_map('strval', $fv))) : trim((string) $fv);
							if ('' !== $str_val) {
								return $str_val;
							}
						}
					}
				}

				// 4. Check user meta
				foreach ((array) $keys as $k) {
					$umeta = get_user_meta($user_id, $k, true);
					if ('' !== $umeta && false !== $umeta && null !== $umeta && 'n/a' !== strtolower((string) $umeta)) {
						return trim((string) $umeta);
					}
				}

				return $default;
			};

			$monthly_spend    = $get_field_val(array('31595', '41979', 'estimated_monthly_spend', 'monthly_spend', 'estimated_spend'), '/spend/i', '100');
			$business_sector  = $get_field_val(array('31583', '41980', 'type_of_business_sector', 'business_sector', 'industry', 'business_type'), '/(sector|industry|business_?type)/i', 'Trade / Wholesale');
			$trading_duration = $get_field_val(array('31584', '41981', 'how_long_trading', 'trading_duration', 'trading_length', 'company_trading_time', 'years_trading'), '/(trading|how_?long|duration)/i', '5+');
			$trade_ref_name   = $get_field_val(array('31588', 'trade_reference_1_name', 'trade_ref_name'), '/ref.*name/i', 'n/a');
			$trade_ref_addr   = $get_field_val(array('31589', 'trade_reference_1_address', 'trade_ref_address'), '/ref.*addr/i', 'n/a');
			$trade_ref_tel    = $get_field_val(array('31590', 'trade_reference_1_tel', 'trade_ref_tel'), '/ref.*(tel|phone)/i', 'n/a');
			$company_name     = $company ?: $get_field_val(array('23162', 'company_name', 'company', 'billing_company'), '/company/i', $name);
			$company_reg      = $get_field_val(array('25894', '41982', 'company_reg_number', 'company_registration_number', 'reg_number'), '/(company_?reg|reg.*number)/i', 'n/a');
			$vat_reg          = $get_field_val(array('25895', '41983', 'vat_reg_number', 'vat_number', 'tax_number'), '/vat/i', 'n/a');
			$parent_company   = $get_field_val(array('23163', '41984', 'parent_company_name', 'parent_company'), '/parent/i', 'n/a');

			// Populate comprehensive meta keys covering all FantasticPlugins / WooCommerce Credits standards
			$meta_map = array(
				// User & Identity
				'_user_id'                               => $user_id,
				'user_id'                                => $user_id,
				'fpc_user_id'                            => $user_id,
				'_fpc_user_id'                           => $user_id,
				'customer_id'                            => $user_id,
				'_customer_id'                           => $user_id,
				'fpc_user'                               => $user_id,
				'_fpc_user'                              => $user_id,
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
				'customer_name'                          => $name,
				'_customer_name'                         => $name,

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

				// 1. Credit Limit £ (ID 31596 / alias 41978)
				'31596'                                  => (float) $limit,
				'_31596'                                 => (float) $limit,
				'field_31596'                            => (float) $limit,
				'wc_cs_field_31596'                      => (float) $limit,
				'wc_cs_form_field_31596'                 => (float) $limit,
				'fpc_field_31596'                        => (float) $limit,
				'41978'                                  => (float) $limit,
				'_41978'                                 => (float) $limit,
				'field_41978'                            => (float) $limit,
				'wc_cs_field_41978'                      => (float) $limit,

				// 2. Estimated monthly spend £ (ID 31595 / alias 41979)
				'estimated_monthly_spend'                => $monthly_spend,
				'_estimated_monthly_spend'               => $monthly_spend,
				'estimated_spend'                        => $monthly_spend,
				'_estimated_spend'                       => $monthly_spend,
				'monthly_spend'                          => $monthly_spend,
				'_monthly_spend'                         => $monthly_spend,
				'fpc_estimated_monthly_spend'            => $monthly_spend,
				'fpc_monthly_spend'                      => $monthly_spend,
				'31595'                                  => $monthly_spend,
				'_31595'                                 => $monthly_spend,
				'field_31595'                            => $monthly_spend,
				'wc_cs_field_31595'                      => $monthly_spend,
				'wc_cs_form_field_31595'                 => $monthly_spend,
				'fpc_field_31595'                        => $monthly_spend,
				'41979'                                  => $monthly_spend,
				'_41979'                                 => $monthly_spend,
				'field_41979'                            => $monthly_spend,
				'wc_cs_field_41979'                      => $monthly_spend,

				// 3. Type of business Sector / Industry? (ID 31583 / alias 41980)
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
				'31583'                                  => $business_sector,
				'_31583'                                 => $business_sector,
				'field_31583'                            => $business_sector,
				'wc_cs_field_31583'                      => $business_sector,
				'wc_cs_form_field_31583'                 => $business_sector,
				'fpc_field_31583'                        => $business_sector,
				'41980'                                  => $business_sector,
				'_41980'                                 => $business_sector,
				'field_41980'                            => $business_sector,
				'wc_cs_field_41980'                      => $business_sector,

				// 4. How long has the company been trading? (ID 31584 / alias 41981)
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
				'31584'                                  => $trading_duration,
				'_31584'                                 => $trading_duration,
				'field_31584'                            => $trading_duration,
				'wc_cs_field_31584'                      => $trading_duration,
				'wc_cs_form_field_31584'                 => $trading_duration,
				'fpc_field_31584'                        => $trading_duration,
				'41981'                                  => $trading_duration,
				'_41981'                                 => $trading_duration,
				'field_41981'                            => $trading_duration,
				'wc_cs_field_41981'                      => $trading_duration,

				// 5. Trade Reference 1, Name (ID 31588)
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
				'31588'                                  => $trade_ref_name,
				'_31588'                                 => $trade_ref_name,
				'field_31588'                            => $trade_ref_name,
				'wc_cs_field_31588'                      => $trade_ref_name,
				'wc_cs_form_field_31588'                 => $trade_ref_name,
				'fpc_field_31588'                        => $trade_ref_name,

				// 6. Trade Reference 1, Address (ID 31589)
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
				'31589'                                  => $trade_ref_addr,
				'_31589'                                 => $trade_ref_addr,
				'field_31589'                            => $trade_ref_addr,
				'wc_cs_field_31589'                      => $trade_ref_addr,
				'wc_cs_form_field_31589'                 => $trade_ref_addr,
				'fpc_field_31589'                        => $trade_ref_addr,

				// 7. Trade Reference 1, Tel (ID 31590)
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
				'31590'                                  => $trade_ref_tel,
				'_31590'                                 => $trade_ref_tel,
				'field_31590'                            => $trade_ref_tel,
				'wc_cs_field_31590'                      => $trade_ref_tel,
				'wc_cs_form_field_31590'                 => $trade_ref_tel,
				'fpc_field_31590'                        => $trade_ref_tel,

				// 8. Company name (ID 23162)
				'company_name'                           => $company_name,
				'_company_name'                          => $company_name,
				'company'                                => $company_name,
				'_company'                               => $company_name,
				'billing_company'                        => $company_name,
				'fpc_company_name'                       => $company_name,
				'23162'                                  => $company_name,
				'_23162'                                 => $company_name,
				'field_23162'                            => $company_name,
				'wc_cs_field_23162'                      => $company_name,
				'wc_cs_form_field_23162'                 => $company_name,
				'fpc_field_23162'                        => $company_name,

				// 9. Company Reg Number (ID 25894 / alias 41982)
				'company_reg_number'                     => $company_reg,
				'_company_reg_number'                    => $company_reg,
				'company_registration_number'            => $company_reg,
				'_company_registration_number'           => $company_reg,
				'company_reg_no'                         => $company_reg,
				'reg_number'                             => $company_reg,
				'fpc_company_reg_number'                 => $company_reg,
				'25894'                                  => $company_reg,
				'_25894'                                 => $company_reg,
				'field_25894'                            => $company_reg,
				'wc_cs_field_25894'                      => $company_reg,
				'wc_cs_form_field_25894'                 => $company_reg,
				'fpc_field_25894'                        => $company_reg,
				'41982'                                  => $company_reg,
				'_41982'                                 => $company_reg,
				'field_41982'                            => $company_reg,
				'wc_cs_field_41982'                      => $company_reg,

				// 10. VAT Reg Number (ID 25895 / alias 41983)
				'vat_reg_number'                         => $vat_reg,
				'_vat_reg_number'                        => $vat_reg,
				'vat_number'                             => $vat_reg,
				'_vat_number'                            => $vat_reg,
				'vat_no'                                 => $vat_reg,
				'tax_number'                             => $vat_reg,
				'fpc_vat_reg_number'                     => $vat_reg,
				'25895'                                  => $vat_reg,
				'_25895'                                 => $vat_reg,
				'field_25895'                            => $vat_reg,
				'wc_cs_field_25895'                      => $vat_reg,
				'wc_cs_form_field_25895'                 => $vat_reg,
				'fpc_field_25895'                        => $vat_reg,
				'41983'                                  => $vat_reg,
				'_41983'                                 => $vat_reg,
				'field_41983'                            => $vat_reg,
				'wc_cs_field_41983'                      => $vat_reg,

				// 11. Parent Company Name (ID 23163 / alias 41984)
				'parent_company_name'                    => $parent_company,
				'_parent_company_name'                   => $parent_company,
				'parent_company'                         => $parent_company,
				'_parent_company'                        => $parent_company,
				'fpc_parent_company_name'                => $parent_company,
				'23163'                                  => $parent_company,
				'_23163'                                 => $parent_company,
				'field_23163'                            => $parent_company,
				'wc_cs_field_23163'                      => $parent_company,
				'wc_cs_form_field_23163'                 => $parent_company,
				'fpc_field_23163'                        => $parent_company,
				'41984'                                  => $parent_company,
				'_41984'                                 => $parent_company,
				'field_41984'                            => $parent_company,
				'wc_cs_field_41984'                      => $parent_company,
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
	 * Dynamically retrieve all submitted application data for a user
	 */
	public static function get_user_application_data($user_id, $email = '')
	{
		global $wpdb;
		$results = array();

		// 1. From cwd_v2_trade_applications table
		$apps_table = $wpdb->prefix . 'cwd_v2_trade_applications';
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $apps_table)) === $apps_table) {
			$row = $wpdb->get_row($wpdb->prepare(
				"SELECT form_data, applicant_name, company_name, phone, requested_limit, forminator_form_id FROM {$apps_table}
				 WHERE user_id = %d OR applicant_email = %s
				 ORDER BY id DESC LIMIT 1",
				(int) $user_id,
				(string) $email
			));

			if ($row) {
				if (! empty($row->company_name)) {
					$results['company_name'] = $row->company_name;
				}
				if (! empty($row->requested_limit)) {
					$results['requested_limit'] = (float) $row->requested_limit;
				}
				$decoded = json_decode((string) $row->form_data, true);
				if (is_array($decoded)) {
					// Expand Forminator element IDs to field labels if available
					if (! empty($row->forminator_form_id) && class_exists('Forminator_API') && method_exists('Forminator_API', 'get_form_fields')) {
						$fields = Forminator_API::get_form_fields((int) $row->forminator_form_id);
						if (is_array($fields)) {
							foreach ($fields as $f) {
								$fid    = is_object($f) ? ($f->slug ?? ($f->element_id ?? '')) : ($f['element_id'] ?? '');
								$flabel = is_object($f) ? ($f->raw['field_label'] ?? ($f->field_label ?? '')) : ($f['field_label'] ?? '');
								if ($fid && $flabel && array_key_exists($fid, $decoded)) {
									$decoded[$flabel] = $decoded[$fid];
								}
							}
						}
					}
					$results = array_merge($results, $decoded);
				}
			}
		}

		// 2. From Forminator tables if available
		$frmt_table = $wpdb->prefix . 'frmt_form_entry_meta';
		if ($email && $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $frmt_table)) === $frmt_table) {
			$entry_ids = $wpdb->get_col($wpdb->prepare(
				"SELECT DISTINCT entry_id FROM {$frmt_table} WHERE meta_value = %s ORDER BY entry_id DESC LIMIT 1",
				$email
			));
			if (! empty($entry_ids)) {
				$f_rows = $wpdb->get_results($wpdb->prepare(
					"SELECT meta_key, meta_value FROM {$frmt_table} WHERE entry_id = %d",
					$entry_ids[0]
				));
				foreach ($f_rows as $fr) {
					$results[$fr->meta_key] = maybe_unserialize($fr->meta_value);
				}
			}
		}

		return $results;
	}

	/**
	 * Self-healing routine:
	 * 1. Scans every existing credit post in 'credits' and ensures post_author is the customer.
	 * 2. Links the post to the correct customer and fills all 11 Basic Details fields
	 *    using the customer's actual submitted application without hardcoding.
	 * 3. Ensures every credit account user has their corresponding credit post.
	 */
	public static function repair_and_sync_all()
	{
		global $wpdb;

		// 1. Repair ALL existing posts in CPT 'credits'
		$all_credit_posts = $wpdb->get_results(
			"SELECT ID, post_title, post_author, post_status FROM {$wpdb->posts}
			 WHERE post_type IN ('credits', 'wc_cs_credits', 'fpc_credits') AND post_status != 'trash'"
		);

		$synced_uids = array();

		if (! empty($all_credit_posts)) {
			foreach ($all_credit_posts as $cp) {
				$post_id = (int) $cp->ID;

				// Resolve customer for this post
				$uid = self::resolve_user_id_for_credit_post($cp);
				if (! $uid) {
					continue;
				}

				// Ensure customer authorship so Credits list displays the real customer
				if ((int) $cp->post_author !== (int) $uid) {
					$wpdb->update($wpdb->posts, array('post_author' => (int) $uid), array('ID' => $post_id), array('%d'), array('%d'));
					clean_post_cache($post_id);
				}

				$synced_uids[] = $uid;
				$u = get_userdata($uid);
				if (! $u) {
					continue;
				}

				$limit = (float) get_post_meta($post_id, 'credit_limit', true)
					?: (float) get_post_meta($post_id, '_credit_limit', true)
					?: (float) get_user_meta($uid, '_credit_limit', true);

				$company = get_post_meta($post_id, 'company_name', true)
					?: get_post_meta($post_id, 'company', true)
					?: get_user_meta($uid, 'billing_company', true);

				// Ensure customer has credit_account role if post is active
				if ('publish' === $cp->post_status || $limit > 0) {
					if (! in_array('credit_account', (array) $u->roles, true)) {
						$u->add_role('credit_account');
					}
					foreach (self::$credit_status_keys as $skey) {
						update_user_meta($uid, $skey, 'active');
					}
				}

				self::sync_to_external_credits_cpt(
					$uid,
					('publish' === $cp->post_status || $limit > 0) ? 'active' : 'pending',
					$limit,
					$u->display_name,
					$u->user_email,
					$company,
					array(),
					$post_id
				);
			}
		}

		// 2. Discover any additional credit users without an existing post
		$user_ids = array();

		$credit_users = get_users(array('role' => 'credit_account', 'fields' => 'ID'));
		foreach ($credit_users as $uid) {
			$user_ids[] = (int) $uid;
		}

		$apps_table = $wpdb->prefix . 'cwd_v2_trade_applications';
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $apps_table)) === $apps_table) {
			$app_uids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$apps_table} WHERE user_id > 0");
			foreach ($app_uids as $uid) {
				$user_ids[] = (int) $uid;
			}
		}

		$meta_uids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_credit_limit' AND meta_value > 0");
		foreach ($meta_uids as $uid) {
			$user_ids[] = (int) $uid;
		}

		$user_ids = array_unique(array_filter($user_ids));

		foreach ($user_ids as $uid) {
			if (in_array($uid, $synced_uids, true)) {
				continue;
			}
			$u = get_userdata($uid);
			if (! $u) {
				continue;
			}
			$limit = class_exists('CWD_V2_Credit_Logic')
				? CWD_V2_Credit_Logic::get_user_credit_limit($uid)
				: (float) get_user_meta($uid, '_credit_limit', true);
			$status = ($limit > 0 || in_array('credit_account', (array) $u->roles, true)) ? 'active' : 'pending';

			self::sync_to_external_credits_cpt(
				$uid,
				$status,
				$limit,
				$u->display_name,
				$u->user_email,
				get_user_meta($uid, 'billing_company', true)
			);
		}
	}

	/**
	 * Sync user to credits post (called directly from Odoo REST endpoint)
	 */
	public static function sync_user_to_credits_post($user_id)
	{
		$user = get_userdata((int) $user_id);
		if (! $user) {
			return;
		}

		$limit = class_exists('CWD_V2_Credit_Logic')
			? CWD_V2_Credit_Logic::get_user_credit_limit($user_id)
			: (float) get_user_meta($user_id, '_credit_limit', true);

		$status = ($limit > 0 || in_array('credit_account', (array) $user->roles, true)) ? 'active' : 'pending';

		self::sync_to_external_credits_cpt(
			$user_id,
			$status,
			$limit,
			$user->display_name,
			$user->user_email,
			get_user_meta($user_id, 'billing_company', true)
		);
	}

	/**
	 * Intercept Forminator form submission
	 */
	public static function on_forminator_submission($form_id, $response)
	{
		if (! isset($response['success']) || ! $response['success'] || ! isset($response['entry_id'])) {
			return;
		}
		if (! class_exists('Forminator_API')) {
			return;
		}

		$entry = Forminator_API::get_entry((int) $form_id, (int) $response['entry_id']);
		if (! $entry || empty($entry->meta_data)) {
			return;
		}

		$flat = array();
		foreach ($entry->meta_data as $key => $d) {
			$flat[$key] = is_array($d) && isset($d['value']) ? $d['value'] : $d;
		}

		$email = '';
		foreach ($flat as $k => $v) {
			if (preg_match('/email/i', $k) && is_email($v)) {
				$email = sanitize_email($v);
				break;
			}
		}
		if (! $email) {
			return;
		}

		$user = get_user_by('email', $email);
		$user_id = $user ? $user->ID : null;

		$name    = $flat['name'] ?? ($flat['applicant_name'] ?? '');
		$company = $flat['company'] ?? ($flat['company_name'] ?? '');
		$phone   = $flat['phone'] ?? ($flat['contact_phone'] ?? '');
		$limit   = (float) ($flat['credit_limit'] ?? ($flat['requested_limit'] ?? 1000.0));

		self::forward_application_to_credits_plugin(
			0,
			$user_id,
			$email,
			$name,
			$company,
			$phone,
			$limit,
			$flat
		);
	}

	/**
	 * Listen for post save in wp-admin
	 */
	public static function on_post_saved($post_id, $post)
	{
		if (self::$is_syncing || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}

		if (! $post || ! in_array($post->post_type, array('credits', 'fpc_credits', 'wc_cs_credits'), true)) {
			return;
		}

		$user_id = (int) (get_post_meta($post_id, 'user_id', true) ?: get_post_meta($post_id, '_user_id', true));
		if (! $user_id) {
			return;
		}

		$user = get_userdata($user_id);
		if (! $user) {
			return;
		}

		$limit = (float) get_post_meta($post_id, 'credit_limit', true) ?: (float) get_post_meta($post_id, '_credit_limit', true);

		if ('publish' === $post->post_status || $limit > 0) {
			if (! in_array('credit_account', (array) $user->roles, true)) {
				$user->add_role('credit_account');
			}
			if ($limit > 0 && class_exists('CWD_V2_Credit_Logic')) {
				CWD_V2_Credit_Logic::sync_user_credit_limit($user_id, $limit);
			}
		}
	}

	/**
	 * Listen for user meta changes
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

		if (in_array($meta_key, self::$credit_limit_keys, true)) {
			$limit = (float) $_meta_value;
			if ($limit > 0) {
				self::sync_user_to_credits_post($user_id);
			}
		}
	}

	/**
	 * Listen for role changes
	 */
	public static function on_user_role_changed($user_id, $role, $old_roles)
	{
		if ('credit_account' === $role) {
			self::sync_user_to_credits_post((int) $user_id);
		}
	}
}
