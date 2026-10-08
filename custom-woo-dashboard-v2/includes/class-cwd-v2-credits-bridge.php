<?php

/**
 * Two-way Unified Bridge between Trade Applications and the WooCommerce Credits plugin (wc_cs / Credits for WooCommerce).
 *
 * Ensures:
 * 1. Trade applications submitted on the site (via Forminator or customer dashboard)
 *    are immediately forwarded and registered in the Credits plugin with full submitted details.
 * 2. Approvals, status updates, or credit limit changes made inside Credits > Credits
 *    instantly update the Trade Applications table, activate the customer role, and sync to Odoo.
 * 3. Approvals made inside WooCommerce > Trade Applications instantly update and activate
 *    the customer inside the Credits plugin.
 * 4. Credit posts are strictly authored as the customer user ID with all user ID meta keys set,
 *    preventing the "Register Credits to New User" modal popup and correctly displaying customer logins.
 * 5. All 11 Basic Details fields are dynamically populated from each customer's actual submitted application.
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

		// 3. Hook into post status changes and saves in wp-admin
		add_action('transition_post_status', array(__CLASS__, 'on_post_status_transition'), 10, 3);
		add_action('save_post', array(__CLASS__, 'on_post_saved'), 20, 2);

		// 4. Hook into postmeta updates on credit posts (catches metabox/inline credit limit and status updates)
		add_action('updated_post_meta', array(__CLASS__, 'on_post_meta_updated'), 10, 4);
		add_action('added_post_meta', array(__CLASS__, 'on_post_meta_updated'), 10, 4);

		// 5. Hook into post editing load to ensure all 11 Basic Details are present and prevent modal redirection
		add_action('load-post.php', array(__CLASS__, 'on_load_post_edit'));

		// 6. Suppress "Register Credits to New User" popup modal on edit screen and inject customer identification
		add_action('admin_head', array(__CLASS__, 'inject_credits_edit_screen_assets'));
		add_action('edit_form_top', array(__CLASS__, 'inject_credit_post_hidden_user_fields'));

		// 7. Hook into Forminator submission to create/update credit post immediately
		add_action('forminator_form_after_save_entry', array(__CLASS__, 'on_forminator_submission'), 20, 2);
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

			// Dynamically forward to custom post types with all 11 form fields
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
	 * Hook on post.php edit load: ensures postmeta is complete before screen loads to prevent modal redirection
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
	 * Inject admin CSS and JS on post.php for credits to suppress the "Register Credits to New User" modal
	 */
	public static function inject_credits_edit_screen_assets()
	{
		global $pagenow, $post;
		if ('post.php' !== $pagenow || ! $post || ! in_array($post->post_type, array('credits', 'wc_cs_credits', 'fpc_credits'), true)) {
			return;
		}

		?>
		<style type="text/css">
			/* Suppress "Register Credits to New User" modal popup on existing post edit screen */
			.post-php.post-type-credits .wc_cs_modal[style*="display"],
			.post-php.post-type-credits .fpc_modal[style*="display"],
			.post-php.post-type-credits .ui-dialog[aria-describedby*="user"] {
				display: none !important;
			}
		</style>
		<script type="text/javascript">
			jQuery(function($) {
				function suppressNewUserModal() {
					// Detect and dismiss any modal asking to search or register credits to a new user
					$('div, section').filter(function() {
						var $el = $(this);
						if ($el.children().length > 0) {
							var txt = $el.find('h1, h2, h3, h4, .title, p, div').first().text();
							return txt && (txt.indexOf('Register Credits to New User') !== -1 || txt.indexOf('Search for a user') !== -1);
						}
						return false;
					}).closest('.modal, .wc_cs_modal, .fpc_modal, .ui-dialog, div[style*="z-index"]').each(function() {
						$(this).hide().css('display', 'none !important');
						$('.modal-backdrop, .wc_cs_modal_backdrop, .ui-widget-overlay').hide().css('display', 'none !important');
					});
				}
				suppressNewUserModal();
				setTimeout(suppressNewUserModal, 50);
				setTimeout(suppressNewUserModal, 200);
				setTimeout(suppressNewUserModal, 600);
				setTimeout(suppressNewUserModal, 1500);
				$(document).ajaxComplete(suppressNewUserModal);
			});
		</script>
		<?php
	}

	/**
	 * Inject hidden user identification fields on credit post edit screen so third-party scripts recognize user
	 */
	public static function inject_credit_post_hidden_user_fields($post)
	{
		if (! $post || ! in_array($post->post_type, array('credits', 'wc_cs_credits', 'fpc_credits'), true)) {
			return;
		}

		$user_id = self::resolve_user_id_for_credit_post($post);
		if ($user_id) {
			$u = get_userdata($user_id);
			$email = $u ? $u->user_email : '';
			$username = $u ? $u->user_login : '';
			?>
			<input type="hidden" name="wc_cs_user_id" id="wc_cs_user_id" value="<?php echo esc_attr($user_id); ?>" />
			<input type="hidden" name="_wc_cs_user_id" value="<?php echo esc_attr($user_id); ?>" />
			<input type="hidden" name="user_id" id="user_id" value="<?php echo esc_attr($user_id); ?>" />
			<input type="hidden" name="_user_id" value="<?php echo esc_attr($user_id); ?>" />
			<input type="hidden" name="customer_id" id="customer_id" value="<?php echo esc_attr($user_id); ?>" />
			<input type="hidden" name="fpc_user_id" id="fpc_user_id" value="<?php echo esc_attr($user_id); ?>" />
			<input type="hidden" name="credit_user_id" id="credit_user_id" value="<?php echo esc_attr($user_id); ?>" />
			<input type="hidden" name="wc_cs_user_email" value="<?php echo esc_attr($email); ?>" />
			<input type="hidden" name="user_email" value="<?php echo esc_attr($email); ?>" />
			<input type="hidden" name="username" value="<?php echo esc_attr($username); ?>" />
			<?php
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
		$id_keys = array(
			'user_id', '_user_id', 'wc_cs_user_id', '_wc_cs_user_id',
			'customer_id', '_customer_id', 'customer_user', '_customer_user',
			'fpc_user_id', '_fpc_user_id', 'fpc_user', '_fpc_user',
			'credit_user_id', '_credit_user_id'
		);
		foreach ($id_keys as $k) {
			$uid = (int) get_post_meta($post_id, $k, true);
			if ($uid > 0 && get_userdata($uid)) {
				return $uid;
			}
		}

		// 2. Post author if customer assigned
		if ((int) $post->post_author > 0 && get_userdata((int) $post->post_author)) {
			$u = get_userdata((int) $post->post_author);
			if ($u && ! in_array('administrator', (array) $u->roles, true)) {
				return (int) $post->post_author;
			}
		}

		// 3. Postmeta email keys
		$email_keys = array(
			'user_email', '_user_email', 'wc_cs_user_email', '_wc_cs_user_email',
			'email', '_email', 'fpc_user_email', '_fpc_user_email',
			'fpc_email', 'applicant_email', '_applicant_email'
		);
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

		// 5. Check if post_title or post_name is username
		if ($post->post_title) {
			$u = get_user_by('login', trim($post->post_title));
			if ($u) {
				return $u->ID;
			}
		}
		if (! empty($post->post_name)) {
			$u = get_user_by('login', trim($post->post_name));
			if ($u) {
				return $u->ID;
			}
		}
		if (! empty($post->post_excerpt)) {
			$ex = trim($post->post_excerpt);
			$u = is_email($ex) ? get_user_by('email', $ex) : get_user_by('login', $ex);
			if ($u) {
				return $u->ID;
			}
		}

		// 6. Match company name in trade applications
		$company = get_post_meta($post_id, 'company_name', true) ?: (get_post_meta($post_id, 'company', true) ?: $post->post_title);
		$dummy_companies = array('5+', '100', 'trade / wholesale', 'trade', 'wholesale', 'n/a', 'na', 'none', 'null');
		if ($company && ! in_array(strtolower(trim($company)), $dummy_companies, true)) {
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

		// Fallback to post_author if exists
		if ((int) $post->post_author > 0 && get_userdata((int) $post->post_author)) {
			return (int) $post->post_author;
		}

		return 0;
	}

	/**
	 * Core synchronization method linking a user to their 'credits' post.
	 *
	 * Authors the post as the customer user ID with all metadata set so the Credits list
	 * displays real usernames and directly opens the edit screen.
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

			$monthly_spend    = $get_field_val(array('31595', '41979', 'estimated_monthly_spend', 'monthly_spend', 'estimated_spend'), '/spend/i', '');
			$business_sector  = $get_field_val(array('31583', '41980', 'type_of_business_sector', 'business_sector', 'industry', 'business_type'), '/(sector|industry|business_?type)/i', '');
			$trading_duration = $get_field_val(array('31584', '41981', 'how_long_trading', 'trading_duration', 'trading_length', 'company_trading_time', 'years_trading'), '/(trading|how_?long|duration)/i', '');
			$trade_ref_name   = $get_field_val(array('31588', 'trade_reference_1_name', 'trade_ref_name'), '/ref.*name/i', '');
			$trade_ref_addr   = $get_field_val(array('31589', 'trade_reference_1_address', 'trade_ref_address'), '/ref.*addr/i', '');
			$trade_ref_tel    = $get_field_val(array('31590', 'trade_reference_1_tel', 'trade_ref_tel'), '/ref.*(tel|phone)/i', '');
			$company_name     = $company ?: $get_field_val(array('23162', 'company_name', 'company', 'billing_company'), '/company/i', $name);
			$company_reg      = $get_field_val(array('25894', '41982', 'company_reg_number', 'company_registration_number', 'reg_number'), '/(company_?reg|reg.*number)/i', '');
			$vat_reg          = $get_field_val(array('25895', '41983', 'vat_reg_number', 'vat_number', 'tax_number'), '/vat/i', '');
			$parent_company   = $get_field_val(array('23163', '41984', 'parent_company_name', 'parent_company'), '/parent/i', '');

			// Retrieve the user's actual live balance (amount owed) and compute available credits dynamically
			$has_explicit_user_balance = metadata_exists('user', $user_id, '_credit_balance');
			$live_user_balance = class_exists('CWD_V2_Credit_Logic')
				? CWD_V2_Credit_Logic::get_user_credit_balance($user_id)
				: (float) get_user_meta($user_id, '_credit_balance', true);

			if ($has_explicit_user_balance) {
				$user_balance = max(0.0, $live_user_balance);
			} else {
				$existing_post_balance = (float) get_post_meta($target_post_id, 'total_outstanding', true)
					?: (float) get_post_meta($target_post_id, '_total_outstanding', true)
					?: (float) get_post_meta($target_post_id, 'wc_cs_total_outstanding', true);
				$user_balance = max($existing_post_balance, $live_user_balance);
				if ($user_balance > 0) {
					update_user_meta($user_id, '_credit_balance', $user_balance);
				}
			}

			$avail_credit = max(0.0, (float) $limit - $user_balance);

			// Populate comprehensive meta keys covering all FantasticPlugins / WooCommerce Credits standards
			$meta_map = array(
				// User & Identity (crucial: sets all user identifiers so the "Register Credits to New User" modal never appears)
				'_user_id'                               => $user_id,
				'user_id'                                => $user_id,
				'fpc_user_id'                            => $user_id,
				'_fpc_user_id'                           => $user_id,
				'customer_id'                            => $user_id,
				'_customer_id'                           => $user_id,
				'fpc_user'                               => $user_id,
				'_fpc_user'                              => $user_id,
				'credit_user_id'                         => $user_id,
				'_credit_user_id'                        => $user_id,
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
				'available_credits'                      => $avail_credit,
				'_available_credits'                     => $avail_credit,
				'fpc_available_credits'                  => $avail_credit,
				'available_credit'                       => $avail_credit,
				'total_outstanding'                      => $user_balance,
				'_total_outstanding'                     => $user_balance,
				'outstanding_credits'                    => $user_balance,
				'approved_credits'                       => (float) $limit,
				'_approved_credits'                      => (float) $limit,
				'approved_credit'                        => (float) $limit,
				'_approved_credit'                       => (float) $limit,

				// wc_cs direct keys
				'wc_cs_user_id'                          => $user_id,
				'_wc_cs_user_id'                         => $user_id,
				'wc_cs_user'                             => $user_id,
				'_wc_cs_user'                            => $user_id,
				'wc_cs_customer_id'                      => $user_id,
				'_wc_cs_customer_id'                     => $user_id,
				'wc_cs_user_email'                       => $email,
				'_wc_cs_user_email'                      => $email,
				'wc_cs_approved_credits'                 => (float) $limit,
				'_wc_cs_approved_credits'                => (float) $limit,
				'wc_cs_approved_credit'                  => (float) $limit,
				'_wc_cs_approved_credit'                 => (float) $limit,
				'wc_cs_credit_limit'                     => (float) $limit,
				'_wc_cs_credit_limit'                    => (float) $limit,
				'wc_cs_available_credits'                => $avail_credit,
				'_wc_cs_available_credits'               => $avail_credit,
				'wc_cs_total_outstanding'                => $user_balance,
				'_wc_cs_total_outstanding'               => $user_balance,
				'wc_cs_outstanding_credits'              => $user_balance,
				'_wc_cs_outstanding_credits'             => $user_balance,
				'wc_cs_status'                           => ('publish' === $post_status) ? 'active' : 'pending',
				'_wc_cs_status'                          => ('publish' === $post_status) ? 'active' : 'pending',
				'wc_cs_credit_status'                    => ('publish' === $post_status) ? 'active' : 'pending',
				'_wc_cs_credit_status'                   => ('publish' === $post_status) ? 'active' : 'pending',

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

			// Dynamically discover and populate all registered wc_cs_form_field posts from the live database
			$dynamic_fields = $wpdb->get_results(
				"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'wc_cs_form_field' AND post_status = 'publish'"
			);
			if (! empty($dynamic_fields)) {
				foreach ($dynamic_fields as $df) {
					$fid   = (int) $df->ID;
					$title = strtolower((string) $df->post_title);
					$fval  = '';
					if (preg_match('/(limit|facility)/i', $title)) {
						$fval = (float) $limit;
					} elseif (preg_match('/spend/i', $title)) {
						$fval = $monthly_spend;
					} elseif (preg_match('/(sector|industry|business)/i', $title)) {
						$fval = $business_sector;
					} elseif (preg_match('/(trading|how.*long|duration)/i', $title)) {
						$fval = $trading_duration;
					} elseif (preg_match('/ref.*name/i', $title)) {
						$fval = $trade_ref_name;
					} elseif (preg_match('/ref.*addr/i', $title)) {
						$fval = $trade_ref_addr;
					} elseif (preg_match('/ref.*(tel|phone)/i', $title)) {
						$fval = $trade_ref_tel;
					} elseif (preg_match('/(company_?reg|reg.*number)/i', $title)) {
						$fval = $company_reg;
					} elseif (preg_match('/vat/i', $title)) {
						$fval = $vat_reg;
					} elseif (preg_match('/parent/i', $title)) {
						$fval = $parent_company;
					} elseif (preg_match('/company/i', $title)) {
						$fval = $company_name;
					}

					if ('' !== $fval && null !== $fval) {
						update_post_meta($target_post_id, (string) $fid, $fval);
						update_post_meta($target_post_id, "wc_cs_field_{$fid}", $fval);
						update_post_meta($target_post_id, "wc_cs_form_field_{$fid}", $fval);
						update_post_meta($target_post_id, "field_{$fid}", $fval);
						update_post_meta($target_post_id, "_{$fid}", $fval);
					}
				}
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
				 WHERE user_id = %d OR LOWER(applicant_email) = LOWER(%s)
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
				"SELECT DISTINCT entry_id FROM {$frmt_table} WHERE LOWER(meta_value) = LOWER(%s) ORDER BY entry_id DESC LIMIT 1",
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

		// 3. User profile meta fallbacks
		if ($user_id > 0) {
			if (empty($results['company_name'])) {
				$b_comp = get_user_meta($user_id, 'billing_company', true);
				if ($b_comp) {
					$results['company_name'] = $b_comp;
					$results['company']      = $b_comp;
				}
			}
			if (empty($results['phone'])) {
				$b_phone = get_user_meta($user_id, 'billing_phone', true);
				if ($b_phone) {
					$results['phone'] = $b_phone;
				}
			}
		}

		return $results;
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
			return;
		}

		// Check if the updated key is a credit balance key
		$balance_keys = array('_credit_balance', 'credit_balance', 'total_outstanding', '_total_outstanding', 'total_outstanding_amount', '_total_outstanding_amount');
		if (in_array($meta_key, $balance_keys, true)) {
			$bal = max(0.0, (float) $_meta_value);
			if (class_exists('CWD_V2_Credit_Logic')) {
				self::$is_syncing = true;
				try {
					CWD_V2_Credit_Logic::sync_user_credit_balance($user_id, $bal);
				} finally {
					self::$is_syncing = false;
				}
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
	 * Intercept postmeta updates on credit posts (catches metabox/inline credit limit and status updates)
	 */
	public static function on_post_meta_updated($meta_id, $object_id, $meta_key, $_meta_value)
	{
		if (self::$is_syncing) {
			return;
		}

		$post = get_post((int) $object_id);
		if (! $post || ! in_array($post->post_type, array('credits', 'wc_cs_credits', 'fpc_credits'), true)) {
			return;
		}

		$status_meta_keys  = array('_credit_status', 'credit_status', '_status', 'status', 'fpc_status', '_fpc_status', '_wc_cs_status', 'wc_cs_status', '_wc_cs_credit_status', 'wc_cs_credit_status');
		$limit_meta_keys   = array('_credit_limit', 'credit_limit', 'fpc_credit_limit', '_fpc_credit_limit', 'approved_credits', '_approved_credits', 'wc_cs_credit_limit', '_wc_cs_credit_limit', 'wc_cs_approved_credits', '_wc_cs_approved_credits');
		$balance_meta_keys = array('total_outstanding_amount', '_total_outstanding_amount', 'wc_cs_total_outstanding_amount', '_wc_cs_total_outstanding_amount', 'total_outstanding', '_total_outstanding', 'wc_cs_total_outstanding', '_wc_cs_total_outstanding', 'outstanding_credits', 'wc_cs_outstanding_credits', 'used_credits', '_used_credits');

		if (! in_array($meta_key, $status_meta_keys, true) && ! in_array($meta_key, $limit_meta_keys, true) && ! in_array($meta_key, $balance_meta_keys, true)) {
			return;
		}

		$user_id = self::resolve_user_id_for_credit_post($post);
		if (! $user_id) {
			return;
		}

		if (in_array($meta_key, $status_meta_keys, true)) {
			$val = is_string($_meta_value) ? strtolower(trim($_meta_value)) : '';
			if (in_array($val, array('active', 'approved', 'publish'), true)) {
				self::sync_trade_application_from_credits_approval($user_id, null, 'Credits post meta (' . $meta_key . ' = ' . $val . ')');
			} elseif (in_array($val, array('rejected', 'declined'), true)) {
				self::mark_trade_application_rejected($user_id, 'Rejected via Credits post meta');
			}
		}

		if (in_array($meta_key, $limit_meta_keys, true)) {
			$limit = (float) $_meta_value;
			if ($limit > 0) {
				self::sync_trade_application_from_credits_approval($user_id, $limit, 'Credits post meta (' . $meta_key . ' = ' . $limit . ')');
			}
		}

		if (in_array($meta_key, $balance_meta_keys, true)) {
			$bal = max(0.0, (float) $_meta_value);
			if (class_exists('CWD_V2_Credit_Logic')) {
				self::$is_syncing = true;
				try {
					CWD_V2_Credit_Logic::sync_user_credit_balance($user_id, $bal);
				} finally {
					self::$is_syncing = false;
				}
			}
		}
	}

	/**
	 * Intercept custom post status transitions (e.g. admin publishing or approving a credit post)
	 */
	public static function on_post_status_transition($new_status, $old_status, $post)
	{
		if (self::$is_syncing || ! $post) {
			return;
		}

		$post_type = $post->post_type;
		if (in_array($post_type, array('credits', 'wc_cs_credits', 'fpc_credits'), true)) {
			$user_id = self::resolve_user_id_for_credit_post($post);
			if (! $user_id) {
				return;
			}

			if (in_array($new_status, array('publish', 'active', 'approved'), true)) {
				self::sync_trade_application_from_credits_approval($user_id, null, 'Credits post status (' . $post_type . ' -> ' . $new_status . ')');
			} elseif (in_array($new_status, array('trash', 'rejected'), true)) {
				self::mark_trade_application_rejected($user_id, 'Credits post status (' . $post_type . ' -> ' . $new_status . ')');
			}
		}
	}

	/**
	 * Intercept save_post for credit custom post types
	 */
	public static function on_post_saved($post_id, $post)
	{
		if (self::$is_syncing || ! $post || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}

		if (in_array($post->post_type, array('credits', 'wc_cs_credits', 'fpc_credits'), true)) {
			$user_id = self::resolve_user_id_for_credit_post($post);
			if (! $user_id) {
				return;
			}

			// Ensure post_author is customer user ID
			if ((int) $post->post_author !== (int) $user_id) {
				global $wpdb;
				$wpdb->update($wpdb->posts, array('post_author' => (int) $user_id), array('ID' => $post_id), array('%d'), array('%d'));
				clean_post_cache($post_id);
			}

			$limit = (float) get_post_meta($post_id, 'credit_limit', true)
				?: ((float) get_post_meta($post_id, '_credit_limit', true)
				?: (float) get_post_meta($post_id, 'approved_credits', true));

			if ($limit <= 0 && isset($_POST['credit_limit']) && is_numeric($_POST['credit_limit'])) {
				$limit = (float) $_POST['credit_limit'];
			}
			if ($limit <= 0 && isset($_POST['approved_credits']) && is_numeric($_POST['approved_credits'])) {
				$limit = (float) $_POST['approved_credits'];
			}

			$status_val = strtolower($post->post_status);
			if (isset($_POST['credit_status'])) {
				$status_val = strtolower(sanitize_text_field(wp_unslash($_POST['credit_status'])));
			} elseif (isset($_POST['status'])) {
				$status_val = strtolower(sanitize_text_field(wp_unslash($_POST['status'])));
			} elseif (isset($_POST['wc_cs_status'])) {
				$status_val = strtolower(sanitize_text_field(wp_unslash($_POST['wc_cs_status'])));
			}

			if (in_array($status_val, array('rejected', 'declined'), true)) {
				self::mark_trade_application_rejected($user_id, 'Rejected in Credits post');
			} elseif (in_array($status_val, array('publish', 'active', 'approved'), true) || $limit > 0) {
				self::sync_trade_application_from_credits_approval($user_id, ($limit > 0 ? $limit : null), 'Credits post save');
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
	 * Self-healing routine:
	 * 1. Scans every existing credit post in 'credits' and ensures post_author is the customer.
	 * 2. Links the post to the correct customer and fills all 11 Basic Details fields
	 *    using the customer's actual submitted application without hardcoding.
	 * 3. Ensures every credit account user has their corresponding credit post.
	 */
	public static function repair_and_sync_all()
	{
		if (self::$is_syncing) {
			return;
		}
		self::$is_syncing = true;
		@set_time_limit(120);

		try {
			global $wpdb;

			// 1. Discover and synchronize ALL existing posts in CPT 'credits' / 'wc_cs_credits' / 'fpc_credits'
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
						// Attempt to find user by email meta or title
						$email = get_post_meta($post_id, 'email', true)
							?: (get_post_meta($post_id, 'user_email', true)
							?: (get_post_meta($post_id, '_email', true)
							?: (get_post_meta($post_id, 'fpc_user_email', true)
							?: get_post_meta($post_id, 'wc_cs_user_email', true))));
						if ($email && is_email($email)) {
							$user_by_email = get_user_by('email', sanitize_email($email));
							if ($user_by_email) {
								$uid = $user_by_email->ID;
							}
						}
					}

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

					// Retrieve Credit Limit from Credit Plugin postmeta
					$post_limit = 0.0;
					$limit_keys = array(
						'approved_credits', '_approved_credits', 'wc_cs_approved_credits',
						'credit_limit', '_credit_limit', 'wc_cs_credit_limit', '_wc_cs_credit_limit',
						'31596', '41978', 'fpc_credit_limit'
					);
					foreach ($limit_keys as $lk) {
						$val = (float) get_post_meta($post_id, $lk, true);
						if ($val > 0) {
							$post_limit = $val;
							break;
						}
					}

					// Check latest approved application limit
					$app_limit = 0.0;
					$apps_table = $wpdb->prefix . 'cwd_v2_trade_applications';
					if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $apps_table)) === $apps_table) {
						$app_limit = (float) $wpdb->get_var($wpdb->prepare(
							"SELECT approved_limit FROM {$apps_table} WHERE (user_id = %d OR applicant_email = %s) AND status = 'approved' AND approved_limit > 0 ORDER BY id DESC LIMIT 1",
							$uid,
							$u->user_email
						));
					}

					$user_existing_limit = (float) get_user_meta($uid, '_credit_limit', true);
					$limit = max($user_existing_limit, max($post_limit, $app_limit));

					// Retrieve Outstanding Balance from Credit Plugin postmeta
					$post_outstanding = 0.0;
					$bal_keys = array(
						'total_outstanding_amount', '_total_outstanding_amount',
						'wc_cs_total_outstanding_amount', '_wc_cs_total_outstanding_amount',
						'total_outstanding', '_total_outstanding',
						'wc_cs_total_outstanding', '_wc_cs_total_outstanding',
						'outstanding_credits', 'wc_cs_outstanding_credits',
						'used_credits', '_used_credits'
					);
					foreach ($bal_keys as $bk) {
						$val = (float) get_post_meta($post_id, $bk, true);
						if ($val > 0) {
							$post_outstanding = $val;
							break;
						}
					}

					$user_balance = (float) get_user_meta($uid, '_credit_balance', true);
					$final_balance = ($post_outstanding > 0) ? $post_outstanding : $user_balance;

					// ALWAYS permanently store outstanding balance into WordPress database independently
					if ($final_balance > 0) {
						update_user_meta($uid, '_credit_balance', $final_balance);
						if (class_exists('CWD_V2_Account_Ledger')) {
							$ledger_table = CWD_V2_Account_Ledger::get_table_name();
							if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $ledger_table)) === $ledger_table) {
								$has_ledger = (int) $wpdb->get_var($wpdb->prepare(
									"SELECT COUNT(*) FROM {$ledger_table} WHERE user_id = %d",
									$uid
								));
								if ($has_ledger === 0) {
									CWD_V2_Account_Ledger::record(
										$uid,
										CWD_V2_Account_Ledger::TYPE_CHARGE,
										$final_balance,
										'opening_balance',
										0,
										'Opening balance imported from Credits Plugin'
									);
								}
							}
						}
					}

					// Check if the Credit Plugin post is an APPROVED credit account
					$status_meta = strtolower(trim((string) (
						get_post_meta($post_id, 'credit_status', true)
						?: (get_post_meta($post_id, '_credit_status', true)
						?: (get_post_meta($post_id, 'wc_cs_status', true)
						?: (get_post_meta($post_id, '_wc_cs_status', true)
						?: (get_post_meta($post_id, 'fpc_status', true)
						?: get_post_meta($post_id, '_status', true)))))
					)));

					// Explicitly check for rejected/declined/trash status
					if (in_array($status_meta, array('rejected', 'declined', 'trash'), true) || 'trash' === $cp->post_status) {
						update_user_meta($uid, '_credit_status', 'rejected');
						if (! in_array('administrator', (array) $u->roles, true) && ! in_array('shop_manager', (array) $u->roles, true)) {
							$u->remove_role('credit_account');
						}
						continue;
					}

					// Only consider approved if positive limit AND active/publish status or approved in trade applications table
					$is_approved_credit_user = ($limit > 0) && (
						in_array($status_meta, array('active', 'approved', 'publish'), true)
						|| ('publish' === $cp->post_status && ! in_array($status_meta, array('pending', 'draft', 'rejected', 'declined'), true))
						|| $app_limit > 0
					);

					if (! $is_approved_credit_user) {
						// User is NOT an approved credit account: DO NOT assign credit_account role
						if (! in_array('administrator', (array) $u->roles, true) && ! in_array('shop_manager', (array) $u->roles, true)) {
							$u->remove_role('credit_account');
						}
						if ('pending' === $status_meta || 'pending' === $cp->post_status) {
							update_user_meta($uid, '_credit_status', 'pending');
						} else {
							update_user_meta($uid, '_credit_status', 'inactive');
						}
						continue;
					}

					// User IS an approved credit user: assign credit_account role
					if (! in_array('credit_account', (array) $u->roles, true)) {
						$u->add_role('credit_account');
					}
					foreach (self::$credit_status_keys as $skey) {
						update_user_meta($uid, $skey, 'active');
					}

					// Permanent independent storage: synchronize credit limit into user meta
					if (class_exists('CWD_V2_Credit_Logic')) {
						CWD_V2_Credit_Logic::sync_user_credit_limit($uid, $limit);
						CWD_V2_Credit_Logic::sync_user_credit_balance($uid, $final_balance);
					} else {
						update_user_meta($uid, '_credit_limit', $limit);
						update_user_meta($uid, '_credit_balance', $final_balance);
					}

					$company = get_post_meta($post_id, 'company_name', true)
						?: (get_post_meta($post_id, 'company', true)
						?: get_user_meta($uid, 'billing_company', true));
					$phone   = get_post_meta($post_id, 'phone', true)
						?: (get_post_meta($post_id, 'billing_phone', true)
						?: get_user_meta($uid, 'billing_phone', true));
					$terms   = get_post_meta($post_id, 'payment_terms', true)
						?: (get_user_meta($uid, '_credit_payment_terms', true) ?: 'Net 30 Days');

					$dummy_companies = array('5+', '100', 'trade / wholesale', 'trade', 'wholesale', 'n/a', 'na', 'none', 'null', '--', '-', '0');
					if (in_array(strtolower(trim((string) $company)), $dummy_companies, true)) {
						$company = '';
						delete_user_meta($uid, 'billing_company', '5+');
						delete_user_meta($uid, 'billing_company', '100');
					}

					if ($company) {
						update_user_meta($uid, 'billing_company', $company);
					}
					if ($phone) {
						update_user_meta($uid, 'billing_phone', $phone);
					}
					update_user_meta($uid, '_credit_payment_terms', $terms);

					// Permanently store approved record in cwd_v2_trade_applications so data survives Credit Plugin deletion
					if ($apps_table && $limit > 0) {
						$has_approved_app = (int) $wpdb->get_var($wpdb->prepare(
							"SELECT COUNT(*) FROM {$apps_table} WHERE (user_id = %d OR applicant_email = %s) AND status = 'approved'",
							$uid,
							$u->user_email
						));
						if ($has_approved_app === 0) {
							$now = current_time('mysql');
							$wpdb->insert(
								$apps_table,
								array(
									'forminator_form_id'  => 0,
									'forminator_entry_id' => 0,
									'applicant_email'     => $u->user_email,
									'applicant_name'      => $u->display_name,
									'company_name'        => $company ?: '',
									'phone'               => $phone ?: '',
									'requested_limit'     => $limit,
									'form_data'           => wp_json_encode(array('source' => 'credits_plugin_import')),
									'status'              => 'approved',
									'approved_limit'      => $limit,
									'admin_note'          => 'Approved credit account imported from Credits Plugin',
									'reviewed_at'         => $now,
									'user_id'             => $uid,
									'created_at'          => $now,
									'updated_at'          => $now,
								),
								array('%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%f', '%s', '%s', '%d', '%s', '%s')
							);
						}
					}

					// Update essential user identification & balance keys directly without heavy re-sync loop
					update_post_meta($post_id, 'user_id', (int) $uid);
					update_post_meta($post_id, '_user_id', (int) $uid);
					update_post_meta($post_id, 'wc_cs_user_id', (int) $uid);
					update_post_meta($post_id, '_wc_cs_user_id', (int) $uid);
					update_post_meta($post_id, 'customer_id', (int) $uid);
					update_post_meta($post_id, 'fpc_user_id', (int) $uid);
					update_post_meta($post_id, 'wc_cs_user_email', $u->user_email);
					update_post_meta($post_id, 'user_email', $u->user_email);
					update_post_meta($post_id, 'total_outstanding_amount', $final_balance);
					update_post_meta($post_id, '_total_outstanding_amount', $final_balance);
					update_post_meta($post_id, 'wc_cs_total_outstanding_amount', $final_balance);
					update_post_meta($post_id, '_wc_cs_total_outstanding_amount', $final_balance);
					update_post_meta($post_id, 'total_outstanding', $final_balance);
					update_post_meta($post_id, '_total_outstanding', $final_balance);
					update_post_meta($post_id, 'wc_cs_total_outstanding', $final_balance);
					update_post_meta($post_id, 'outstanding_credits', $final_balance);
					update_post_meta($post_id, 'wc_cs_outstanding_credits', $final_balance);
					update_post_meta($post_id, 'available_credits', max(0.0, $limit - $final_balance));
					update_post_meta($post_id, '_available_credits', max(0.0, $limit - $final_balance));
					update_post_meta($post_id, 'wc_cs_available_credits', max(0.0, $limit - $final_balance));
					clean_post_cache($post_id);
				}
			}

			// 2. Discover any additional approved credit users without an existing post
			$user_ids = array();

			// Only include users who already have credit_account role or approved application
			$credit_users = get_users(array('role' => 'credit_account', 'fields' => 'ID'));
			foreach ($credit_users as $uid) {
				$user_ids[] = (int) $uid;
			}

			$apps_table = $wpdb->prefix . 'cwd_v2_trade_applications';
			if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $apps_table)) === $apps_table) {
				// Strictly select users with APPROVED applications and positive limit
				$app_uids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$apps_table} WHERE user_id > 0 AND status = 'approved' AND approved_limit > 0");
				foreach ($app_uids as $uid) {
					$user_ids[] = (int) $uid;
				}
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

				$status = (string) get_user_meta($uid, '_credit_status', true);
				if (in_array($status, array('rejected', 'declined'), true)) {
					if (! in_array('administrator', (array) $u->roles, true) && ! in_array('shop_manager', (array) $u->roles, true)) {
						$u->remove_role('credit_account');
					}
					continue;
				}

				$limit = class_exists('CWD_V2_Credit_Logic')
					? CWD_V2_Credit_Logic::get_user_credit_limit($uid)
					: (float) get_user_meta($uid, '_credit_limit', true);

				if ($limit <= 0) {
					// Strip credit_account role if user has zero limit and no approved application
					if (! in_array('administrator', (array) $u->roles, true) && ! in_array('shop_manager', (array) $u->roles, true)) {
						$u->remove_role('credit_account');
					}
					continue;
				}

				// Ensure approved role
				if (! in_array('credit_account', (array) $u->roles, true)) {
					$u->add_role('credit_account');
				}
				update_user_meta($uid, '_credit_status', 'active');

				self::sync_to_external_credits_cpt(
					$uid,
					'active',
					$limit,
					$u->display_name,
					$u->user_email,
					get_user_meta($uid, 'billing_company', true)
				);
			}
		} catch (\Throwable $e) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('CWD_V2 repair_and_sync_all error: ' . $e->getMessage());
			}
		} finally {
			self::$is_syncing = false;
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
				   AND (post_author = %d OR ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_user_id', 'user_id', 'customer_id') AND meta_value = %s))
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
}
