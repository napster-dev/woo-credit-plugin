<?php

/**
 * Unified Credit System Bridge
 *
 * Connects customer credit applications and dashboard data directly with
 * the WooCommerce Credit System (Credits for WooCommerce / wc_cs) and Odoo.
 *
 * Responsibilities:
 * 1. Synchronizes trade applications into native 'credits' posts matching all wc_cs_form_field keys.
 * 2. Ensures each customer has a properly linked 'credits' post (fixing the "Add new credit user" redirect bug).
 * 3. Populates all 27 wc_cs_form_field meta keys (Credit Limit £, Estimated monthly spend, Sector, Trade refs, etc.)
 *    so the "Basic Details" metabox displays full live data on "Edit credits".
 * 4. Listens for Credit plugin status/limit changes and updates user meta & role automatically.
 * 5. Syncs live credit limit & balance changes from Odoo into both WooCommerce user meta and the Credits post.
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
	 * Known form field mappings from wc_cs_form_field CPT
	 *
	 * @var array
	 */
	private static $known_form_fields = array(
		'credit_limit' => array(
			'ids'   => array(31596, 41978),
			'label' => 'Credit Limit £',
			'keys'  => array('credit_limit', 'limit', 'credit_amount', 'requested_limit', 'requested_credit_limit', '_credit_limit'),
		),
		'monthly_spend' => array(
			'ids'   => array(31595, 41979),
			'label' => 'Estimated monthly spend £',
			'keys'  => array('estimated_monthly_spend', 'monthly_spend', 'estimated_spend'),
		),
		'business_sector' => array(
			'ids'   => array(31583, 41980),
			'label' => 'Type of business Sector / Industry?',
			'keys'  => array('type_of_business_sector', 'business_sector', 'industry', 'business_type'),
		),
		'trading_duration' => array(
			'ids'   => array(31584, 41981),
			'label' => 'How long has the company been trading?',
			'keys'  => array('how_long_trading', 'how_long_has_the_company_been_trading', 'trading_duration', 'trading_length', 'company_trading_time', 'years_trading'),
		),
		'trade_ref_name' => array(
			'ids'   => array(31588),
			'label' => 'Trade Reference 1, Name',
			'keys'  => array('trade_reference_1_name', 'trade_reference_name', 'trade_ref_1_name', 'reference_1_name', 'trade_ref_name'),
		),
		'trade_ref_addr' => array(
			'ids'   => array(31589),
			'label' => 'Trade Reference 1, Address',
			'keys'  => array('trade_reference_1_address', 'trade_reference_address', 'trade_ref_1_address', 'reference_1_address', 'trade_ref_address'),
		),
		'trade_ref_tel' => array(
			'ids'   => array(31590),
			'label' => 'Trade Reference 1, Tel',
			'keys'  => array('trade_reference_1_tel', 'trade_reference_1_phone', 'trade_reference_tel', 'trade_ref_1_tel', 'reference_1_tel', 'trade_ref_tel'),
		),
		'company_name' => array(
			'ids'   => array(23162),
			'label' => 'Company name',
			'keys'  => array('company_name', 'company', 'billing_company', 'business_name'),
		),
		'company_reg' => array(
			'ids'   => array(25894, 41982),
			'label' => 'Company Reg Number',
			'keys'  => array('company_reg_number', 'company_registration_number', 'company_reg_no', 'reg_number'),
		),
		'vat_reg' => array(
			'ids'   => array(25895, 41983),
			'label' => 'VAT Reg Number',
			'keys'  => array('vat_reg_number', 'vat_number', 'vat_no', 'tax_number'),
		),
		'parent_company' => array(
			'ids'   => array(23163, 41984),
			'label' => 'Parent Company Name',
			'keys'  => array('parent_company_name', 'parent_company'),
		),
		'street_address' => array(
			'ids'   => array(23165),
			'label' => 'Street address',
			'keys'  => array('street_address', 'address_1', 'billing_address_1'),
		),
		'street_address_2' => array(
			'ids'   => array(23166),
			'label' => 'Street address 2',
			'keys'  => array('street_address_2', 'address_2', 'billing_address_2'),
		),
		'city' => array(
			'ids'   => array(23167),
			'label' => 'Town / City',
			'keys'  => array('city', 'town_city', 'billing_city'),
		),
		'county' => array(
			'ids'   => array(23168),
			'label' => 'County',
			'keys'  => array('county', 'state', 'billing_state'),
		),
		'postcode' => array(
			'ids'   => array(23169),
			'label' => 'Postcode',
			'keys'  => array('postcode', 'billing_postcode'),
		),
		'country' => array(
			'ids'   => array(23164),
			'label' => 'Country/Region',
			'keys'  => array('country', 'country_region', 'billing_country'),
		),
		'phone' => array(
			'ids'   => array(23170),
			'label' => 'Phone',
			'keys'  => array('phone', 'billing_phone', 'contact_phone'),
		),
		'email' => array(
			'ids'   => array(23171),
			'label' => 'Email address',
			'keys'  => array('email', 'email_address', 'user_email', 'billing_email'),
		),
	);

	/**
	 * Initialize hooks
	 */
	public static function init()
	{
		// 1. Hook into Credit post saves (when admin edits/approves in Credits > Credits)
		add_action('save_post_credits', array(__CLASS__, 'on_credits_post_saved'), 20, 3);
		add_action('save_post', array(__CLASS__, 'maybe_on_any_post_saved'), 20, 2);

		// 2. Hook into Forminator submission to forward straight into Credits plugin
		add_action('forminator_form_after_save_entry', array(__CLASS__, 'on_forminator_submission'), 20, 2);

		// 3. Hook into user meta changes (bidirectional sync from Odoo / WooCommerce)
		add_action('updated_user_meta', array(__CLASS__, 'on_user_meta_updated'), 10, 4);

		// 4. Hook into post.php screen load to ensure complete postmeta for the current credit post
		add_action('load-post.php', array(__CLASS__, 'on_load_post_edit'));

		// 5. Automatic self-healing on admin initialization (runs once per request)
		add_action('admin_init', array(__CLASS__, 'maybe_self_heal'));
	}

	/**
	 * Self-healing routine to repair missing credit links and form fields
	 */
	public static function maybe_self_heal()
	{
		if (! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
			return;
		}

		// Throttle self-healing to run at most once per 60 seconds
		$last_run = (int) get_transient('cwd_v2_credits_self_heal_last');
		if ($last_run && (time() - $last_run) < 60) {
			return;
		}
		set_transient('cwd_v2_credits_self_heal_last', time(), 60);

		self::repair_and_sync_all_users();
	}

	/**
	 * Ensure every user with a trade application or credit account has a valid 'credits' post
	 * with full wc_cs_form_field meta populated.
	 */
	public static function repair_and_sync_all_users()
	{
		if (self::$is_syncing) {
			return;
		}
		self::$is_syncing = true;

		global $wpdb;

		// 1. Find all users that either have a trade application or role 'credit_account' or credit limit > 0
		$user_ids = array();

		// From credit_account role
		$credit_users = get_users(array('role' => 'credit_account', 'fields' => 'ID'));
		foreach ($credit_users as $uid) {
			$user_ids[] = (int) $uid;
		}

		// From trade applications table if exists
		$apps_table = $wpdb->prefix . 'cwd_v2_trade_applications';
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $apps_table)) === $apps_table) {
			$app_uids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$apps_table} WHERE user_id > 0");
			foreach ($app_uids as $uid) {
				$user_ids[] = (int) $uid;
			}
		}

		// From user meta _credit_limit > 0
		$meta_uids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_credit_limit' AND meta_value > 0");
		foreach ($meta_uids as $uid) {
			$user_ids[] = (int) $uid;
		}

		$user_ids = array_unique(array_filter($user_ids));

		foreach ($user_ids as $uid) {
			self::sync_user_to_credits_post($uid);
		}

		self::$is_syncing = false;
	}

	/**
	 * Synchronize a specific WordPress user to their native 'credits' post.
	 *
	 * @param int   $user_id   User ID
	 * @param array $form_data Optional explicit form data
	 * @return int|false Post ID or false
	 */
	public static function sync_user_to_credits_post($user_id, $form_data = array())
	{
		$user = get_userdata((int) $user_id);
		if (! $user) {
			return false;
		}

		global $wpdb;
		$user_id  = (int) $user->ID;
		$email    = sanitize_email($user->user_email);
		$username = $user->user_login;
		$name     = trim($user->first_name . ' ' . $user->last_name) ?: ($user->display_name ?: $username);
		$company  = get_user_meta($user_id, 'billing_company', true) ?: '';

		// Resolve credit limit
		$live_limit = class_exists('CWD_V2_Credit_Logic')
			? CWD_V2_Credit_Logic::get_user_credit_limit($user_id)
			: (float) get_user_meta($user_id, '_credit_limit', true);

		// Resolve status
		$status = ($live_limit > 0 || in_array('credit_account', (array) $user->roles, true)) ? 'publish' : 'pending';

		// If no form data provided, look up their latest trade application form_data
		if (empty($form_data)) {
			$form_data = self::get_user_application_form_data($user_id, $email);
		}

		// Find existing 'credits' post for this user
		$existing_ids = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm_u ON p.ID = pm_u.post_id AND pm_u.meta_key IN ('_user_id', 'user_id', 'fpc_user_id', '_customer_id', 'customer_id')
			 LEFT JOIN {$wpdb->postmeta} pm_e ON p.ID = pm_e.post_id AND pm_e.meta_key IN ('_email', 'email', 'user_email', '_user_email', 'fpc_user_email')
			 WHERE p.post_type = 'credits'
			   AND (
			     pm_u.meta_value = %s
			     OR pm_e.meta_value = %s
			     OR p.post_title LIKE %s
			     OR p.post_author = %d
			   )
			 ORDER BY p.ID DESC",
			(string) $user_id,
			$email,
			'%' . $wpdb->esc_like($email) . '%',
			$user_id
		));

		$post_title = $username . ' / ' . $email;

		if (! empty($existing_ids)) {
			$post_id = (int) $existing_ids[0];
			wp_update_post(array(
				'ID'          => $post_id,
				'post_title'  => $post_title,
				'post_status' => $status,
				'post_author' => $user_id,
			));
		} else {
			$post_id = wp_insert_post(array(
				'post_title'   => $post_title,
				'post_type'    => 'credits',
				'post_status'  => $status,
				'post_author'  => $user_id,
			));
		}

		if (! $post_id || is_wp_error($post_id)) {
			return false;
		}

		// 1. Core user identification meta
		$user_meta_map = array(
			'_user_id'          => $user_id,
			'user_id'           => $user_id,
			'fpc_user_id'       => $user_id,
			'_fpc_user_id'      => $user_id,
			'customer_id'       => $user_id,
			'_customer_id'      => $user_id,
			'user_email'        => $email,
			'_user_email'       => $email,
			'email'             => $email,
			'_email'            => $email,
			'fpc_user_email'    => $email,
			'fpc_email'         => $email,
			'username'          => $username,
			'_username'         => $username,
			'user_login'        => $username,
			'customer_name'     => $name,
			'_customer_name'    => $name,
			'first_name'        => $user->first_name ?: $username,
			'last_name'         => $user->last_name ?: '',
			'credit_limit'      => (float) $live_limit,
			'_credit_limit'     => (float) $live_limit,
			'fpc_credit_limit'  => (float) $live_limit,
			'credit_amount'     => (float) $live_limit,
			'_credit_amount'    => (float) $live_limit,
			'available_credits' => (float) $live_limit,
			'status'            => ('publish' === $status) ? 'active' : 'pending',
			'_status'           => ('publish' === $status) ? 'active' : 'pending',
		);

		foreach ($user_meta_map as $mk => $mv) {
			update_post_meta($post_id, $mk, $mv);
		}

		// 2. Populate all 27 wc_cs_form_field keys from application data
		self::populate_form_fields_for_post($post_id, $user_id, $user, $form_data, $live_limit);

		return $post_id;
	}

	/**
	 * Populate all wc_cs_form_field meta keys on a credit post
	 */
	private static function populate_form_fields_for_post($post_id, $user_id, $user, $form_data, $live_limit)
	{
		// Values resolver helper
		$pick = function ($regex, $default = '') use ($form_data, $post_id) {
			$val = self::pick_value_from_data($form_data, $regex);
			if ('' === $val || null === $val) {
				$val = $default;
			}
			return $val;
		};

		$credit_limit_val    = $live_limit > 0 ? $live_limit : ($pick('/(limit|amount)/i') ?: 0.0);
		$monthly_spend_val   = $pick('/spend/i');
		$business_sector_val = $pick('/(sector|industry|business_?type)/i');
		$trading_val         = $pick('/(trading|how_?long|duration)/i');
		$trade_ref_name_val  = $pick('/ref.*name/i');
		$trade_ref_addr_val  = $pick('/ref.*addr/i');
		$trade_ref_tel_val   = $pick('/ref.*(tel|phone)/i');
		$company_name_val    = $pick('/company/i') ?: (get_user_meta($user_id, 'billing_company', true) ?: '');
		$company_reg_val     = $pick('/(company_?reg|reg.*number)/i', '');
		$vat_reg_val         = $pick('/vat/i', '');
		$parent_company_val  = $pick('/parent/i', '');
		$street_1_val        = $pick('/address_?1/i') ?: get_user_meta($user_id, 'billing_address_1', true);
		$street_2_val        = $pick('/address_?2/i') ?: get_user_meta($user_id, 'billing_address_2', true);
		$city_val            = $pick('/city/i') ?: get_user_meta($user_id, 'billing_city', true);
		$county_val          = $pick('/county/i') ?: get_user_meta($user_id, 'billing_state', true);
		$postcode_val        = $pick('/postcode/i') ?: get_user_meta($user_id, 'billing_postcode', true);
		$phone_val           = $pick('/phone/i') ?: get_user_meta($user_id, 'billing_phone', true);
		$email_val           = $user->user_email;

		$resolved_values = array(
			'credit_limit'     => $credit_limit_val,
			'monthly_spend'    => $monthly_spend_val,
			'business_sector'  => $business_sector_val,
			'trading_duration' => $trading_val,
			'trade_ref_name'   => $trade_ref_name_val,
			'trade_ref_addr'   => $trade_ref_addr_val,
			'trade_ref_tel'    => $trade_ref_tel_val,
			'company_name'     => $company_name_val,
			'company_reg'      => $company_reg_val,
			'vat_reg'          => $vat_reg_val,
			'parent_company'   => $parent_company_val,
			'street_address'   => $street_1_val,
			'street_address_2' => $street_2_val,
			'city'             => $city_val,
			'county'           => $county_val,
			'postcode'         => $postcode_val,
			'phone'            => $phone_val,
			'email'            => $email_val,
		);

		foreach (self::$known_form_fields as $field_slug => $def) {
			$val = $resolved_values[$field_slug] ?? '';
			if ('' === $val || null === $val) {
				// Don't overwrite existing non-empty meta with empty
				$existing = get_post_meta($post_id, (string) ($def['ids'][0] ?? ''), true);
				if (! empty($existing)) {
					continue;
				}
				$val = 'n/a';
			}

			// 1. Write under all field post IDs
			foreach ($def['ids'] as $fid) {
				update_post_meta($post_id, (string) $fid, $val);
				update_post_meta($post_id, "wc_cs_field_{$fid}", $val);
				update_post_meta($post_id, "field_{$fid}", $val);
				update_post_meta($post_id, "_{$fid}", $val);
			}

			// 2. Write under all semantic keys
			foreach ($def['keys'] as $k) {
				update_post_meta($post_id, $k, $val);
				update_post_meta($post_id, "_{$k}", $val);
				update_post_meta($post_id, "wc_cs_{$k}", $val);
				update_post_meta($post_id, "fpc_{$k}", $val);
			}
		}
	}

	/**
	 * Extract field value by regex from arbitrary form data array
	 */
	private static function pick_value_from_data($data, $regex)
	{
		if (! is_array($data) || empty($data)) {
			return '';
		}

		foreach ($data as $key => $val) {
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
		return '';
	}

	/**
	 * Fetch trade application form data for a user
	 */
	public static function get_user_application_form_data($user_id, $email = '')
	{
		global $wpdb;
		$table = $wpdb->prefix . 'cwd_v2_trade_applications';
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
			return array();
		}

		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT form_data, forminator_form_id FROM {$table}
			 WHERE (user_id = %d OR applicant_email = %s)
			 ORDER BY id DESC LIMIT 5",
			(int) $user_id,
			(string) $email
		));

		foreach ((array) $rows as $row) {
			$data = json_decode((string) $row->form_data, true);
			if (is_array($data) && ! empty($data)) {
				// Map Forminator IDs to human labels if Forminator_API exists
				if (class_exists('Forminator_API') && method_exists('Forminator_API', 'get_form_fields') && ! empty($row->forminator_form_id)) {
					$fields = Forminator_API::get_form_fields((int) $row->forminator_form_id);
					if (is_array($fields)) {
						foreach ($fields as $f) {
							$fid    = is_object($f) ? ($f->slug ?? ($f->element_id ?? '')) : ($f['element_id'] ?? '');
							$flabel = is_object($f) ? ($f->raw['field_label'] ?? ($f->field_label ?? '')) : ($f['field_label'] ?? '');
							if ($fid && $flabel && array_key_exists($fid, $data)) {
								$data[$flabel] = $data[$fid];
							}
						}
					}
				}
				return $data;
			}
		}
		return array();
	}

	/**
	 * Intercept Forminator form submission to immediately forward into Credits plugin
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
		if (! $entry || is_wp_error($entry) || empty($entry->meta_data)) {
			return;
		}

		$flat = array();
		foreach ($entry->meta_data as $key => $d) {
			$flat[$key] = is_array($d) && isset($d['value']) ? $d['value'] : $d;
		}

		// Find email
		$email = self::pick_value_from_data($flat, '/email/i');
		if (empty($email)) {
			return;
		}

		$user = get_user_by('email', sanitize_email($email));
		if (! $user) {
			$name     = self::pick_value_from_data($flat, '/name/i') ?: explode('@', $email)[0];
			$username = sanitize_user(strtolower(str_replace(' ', '.', $name)), true) ?: 'customer';
			$counter  = 1;
			while (username_exists($username)) {
				$username = $username . $counter;
				$counter++;
			}
			$created_id = wp_create_user($username, wp_generate_password(12, true, true), sanitize_email($email));
			if (! is_wp_error($created_id)) {
				$user = get_userdata($created_id);
			}
		}

		if ($user) {
			self::sync_user_to_credits_post($user->ID, $flat);
		}
	}

	/**
	 * Hook on post.php edit load: ensures postmeta is complete before screen renders
	 */
	public static function on_load_post_edit()
	{
		if (! is_admin() || ! isset($_GET['post'])) {
			return;
		}

		$post_id = (int) $_GET['post'];
		$post    = get_post($post_id);
		if (! $post || 'credits' !== $post->post_type) {
			return;
		}

		$user_id = (int) (get_post_meta($post_id, 'user_id', true) ?: (get_post_meta($post_id, '_user_id', true) ?: $post->post_author));
		if (! $user_id && $post->post_title) {
			if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $post->post_title, $m)) {
				$u = get_user_by('email', $m[1]);
				if ($u) {
					$user_id = $u->ID;
				}
			}
		}

		if ($user_id) {
			self::sync_user_to_credits_post($user_id);
		}
	}

	/**
	 * Intercept admin saving/approving in Credits > Credits
	 */
	public static function on_credits_post_saved($post_id, $post, $update)
	{
		if (self::$is_syncing || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}

		$user_id = (int) (get_post_meta($post_id, 'user_id', true) ?: (get_post_meta($post_id, '_user_id', true) ?: $post->post_author));
		if (! $user_id) {
			return;
		}

		$user = get_userdata($user_id);
		if (! $user) {
			return;
		}

		$limit = (float) get_post_meta($post_id, 'credit_limit', true) ?: (float) get_post_meta($post_id, '_credit_limit', true);

		// If post is published or limit > 0, activate user credit account
		if ('publish' === $post->post_status || $limit > 0) {
			if (! in_array('credit_account', (array) $user->roles, true)) {
				$user->add_role('credit_account');
			}
			if ($limit > 0 && class_exists('CWD_V2_Credit_Logic')) {
				CWD_V2_Credit_Logic::sync_user_credit_limit($user_id, $limit);
			}
		}
	}

	public static function maybe_on_any_post_saved($post_id, $post)
	{
		if ($post && 'credits' === $post->post_type) {
			self::on_credits_post_saved($post_id, $post, true);
		}
	}

	/**
	 * Listen for user meta updates (e.g. from Odoo direct sync)
	 */
	public static function on_user_meta_updated($meta_id, $object_id, $meta_key, $_meta_value)
	{
		if (self::$is_syncing) {
			return;
		}

		if (in_array($meta_key, array('_credit_limit', '_credit_balance', 'ews_account_number'), true)) {
			$user_id = (int) $object_id;
			if ($user_id > 0) {
				self::sync_user_to_credits_post($user_id);
			}
		}
	}
}
