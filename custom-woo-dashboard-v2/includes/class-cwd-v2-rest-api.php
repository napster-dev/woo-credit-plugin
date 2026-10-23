<?php

/**
 * REST API Integration for Odoo Credit Sync
 */

if (! defined('ABSPATH')) {
	exit;
}

class CWD_V2_REST_API
{

	public static function init()
	{
		// Register meta keys for user REST API visibility
		add_action('init', array(__CLASS__, 'register_user_meta_fields'));

		// Intercept WooCommerce REST API Customer inserts/updates (from Odoo sync)
		add_filter('woocommerce_rest_pre_insert_customer_object', array(__CLASS__, 'handle_rest_customer_save'), 10, 3);

		// Expose credit info in WooCommerce REST API Customer response
		add_filter('woocommerce_rest_prepare_customer', array(__CLASS__, 'prepare_rest_customer_response'), 10, 3);

		// Register a dedicated direct sync route for Odoo
		add_action('rest_api_init', array(__CLASS__, 'register_credit_sync_routes'));
	}

	/**
	 * Register meta keys so WordPress REST API recognizes them
	 */
	public static function register_user_meta_fields()
	{
		$meta_keys = array(
			'_credit_limit' => array(
				'type'        => 'number',
				'description' => 'Customer credit limit',
			),
			'_credit_balance' => array(
				'type'        => 'number',
				'description' => 'Current outstanding credit balance',
			),
			'_credit_due_date' => array(
				'type'        => 'string',
				'description' => 'Credit balance due date',
			),
			'ews_account_number' => array(
				'type'        => 'string',
				'description' => 'EWS trade account number',
			),
		);

		foreach ($meta_keys as $key => $args) {
			register_meta(
				'user',
				$key,
				array(
					'type'         => $args['type'],
					'description'  => $args['description'],
					'single'       => true,
					'show_in_rest' => true,
					'auth_callback' => array(__CLASS__, 'rest_meta_auth_callback'),
				)
			);
		}
	}

	/**
	 * Authorization callback for credit meta in REST API
	 */
	public static function rest_meta_auth_callback($request = null)
	{
		if (current_user_can('manage_woocommerce') || current_user_can('manage_options') || current_user_can('edit_shop_orders')) {
			return true;
		}

		if ($request instanceof WP_REST_Request) {
			// 1. Shared Secret Key check
			$auth_header = $request->get_header('X-Odoo-Secret') 
				?: $request->get_header('X-CWD-SECRET-KEY') 
				?: $request->get_header('X-ODOO-SECRET') 
				?: $request->get_param('secret_key');

			$secret_key = defined('CWD_ODOO_SECRET_KEY') ? CWD_ODOO_SECRET_KEY : get_option('cwd_odoo_secret_key', '');

			if (! empty($secret_key) && ! empty($auth_header) && hash_equals((string) $secret_key, (string) $auth_header)) {
				return true;
			}

			// 2. Direct Basic Auth parsing (fixes LiteSpeed/Hostinger/FastCGI stripping Authorization header)
			$raw_auth = '';
			if (! empty($_SERVER['HTTP_AUTHORIZATION'])) {
				$raw_auth = $_SERVER['HTTP_AUTHORIZATION'];
			} elseif (! empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
				$raw_auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
			} elseif ($request->get_header('authorization')) {
				$raw_auth = $request->get_header('authorization');
			} elseif ($request->get_header('X-Authorization')) {
				$raw_auth = $request->get_header('X-Authorization');
			}

			if (0 === stripos($raw_auth, 'basic ')) {
				$decoded = base64_decode(substr($raw_auth, 6));
				if (false !== $decoded && strpos($decoded, ':') !== false) {
					list($login_or_email, $password) = explode(':', $decoded, 2);
					$user = get_user_by('login', $login_or_email) ?: get_user_by('email', $login_or_email);
					if ($user) {
						$clean_pw = str_replace(' ', '', $password);
						if (class_exists('WP_Application_Passwords') && WP_Application_Passwords::is_enabled()) {
							$authenticated = WP_Application_Passwords::authenticate(null, $user->user_login, $clean_pw);
							if (! is_wp_error($authenticated) && $authenticated instanceof WP_User) {
								if (user_can($authenticated, 'manage_woocommerce') || user_can($authenticated, 'manage_options') || user_can($authenticated, 'edit_shop_orders')) {
									wp_set_current_user($authenticated->ID);
									return true;
								}
							}
						}
						if (wp_check_password($password, $user->user_pass, $user->ID) || wp_check_password($clean_pw, $user->user_pass, $user->ID)) {
							if (user_can($user, 'manage_woocommerce') || user_can($user, 'manage_options') || user_can($user, 'edit_shop_orders')) {
								wp_set_current_user($user->ID);
								return true;
							}
						}
					}
				}
			}

			// 2.5 WooCommerce API Keys check (matches active WooCommerce instance keys)
			$woo_ck = $request->get_header('X-Woo-Consumer-Key') ?: $request->get_param('consumer_key');
			$woo_cs = $request->get_header('X-Woo-Consumer-Secret') ?: $request->get_param('consumer_secret');
			if (empty($woo_ck) && 0 === stripos($raw_auth, 'basic ')) {
				$decoded_auth = base64_decode(substr($raw_auth, 6));
				if (false !== $decoded_auth && strpos($decoded_auth, ':') !== false) {
					list($maybe_ck, $maybe_cs) = explode(':', $decoded_auth, 2);
					if (0 === strpos($maybe_ck, 'ck_')) {
						$woo_ck = $maybe_ck;
						$woo_cs = $maybe_cs;
					}
				}
			}

			if (! empty($woo_ck) && ! empty($woo_cs) && function_exists('wc_api_hash')) {
				global $wpdb;
				$key = $wpdb->get_row($wpdb->prepare(
					"SELECT user_id, permissions, consumer_secret FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s",
					wc_api_hash($woo_ck)
				));
				if ($key && hash_equals($key->consumer_secret, $woo_cs)) {
					if (in_array($key->permissions, array('read_write', 'write'), true)) {
						if (! empty($key->user_id)) {
							wp_set_current_user($key->user_id);
						}
						return true;
					}
				}
			}

			// 3. Custom X-WP-App-User and X-WP-App-Password headers
			$custom_user = $request->get_header('X-WP-App-User') ?: $request->get_param('wp_username');
			$custom_pw   = $request->get_header('X-WP-App-Password') ?: $request->get_param('wp_app_password');
			if (! empty($custom_user) && ! empty($custom_pw)) {
				$user = get_user_by('login', $custom_user) ?: get_user_by('email', $custom_user);
				if ($user && class_exists('WP_Application_Passwords') && WP_Application_Passwords::is_enabled()) {
					$clean_pw = str_replace(' ', '', $custom_pw);
					$authenticated = WP_Application_Passwords::authenticate(null, $user->user_login, $clean_pw);
					if (! is_wp_error($authenticated) && $authenticated instanceof WP_User) {
						if (user_can($authenticated, 'manage_woocommerce') || user_can($authenticated, 'manage_options') || user_can($authenticated, 'edit_shop_orders')) {
							wp_set_current_user($authenticated->ID);
							return true;
						}
					}
				}
			}
		}

		return false;
	}

	/**
	 * Intercept WooCommerce REST API customer updates to capture credit metadata from Odoo
	 *
	 * @param WC_Customer $customer
	 * @param WP_REST_Request $request
	 * @param bool $creating
	 * @return WC_Customer
	 */
	public static function handle_rest_customer_save($customer, $request, $creating)
	{
		// 1. Process meta_data array from payload
		$meta_data = $request->get_param('meta_data');
		if (is_array($meta_data)) {
			foreach ($meta_data as $meta_item) {
				if (! isset($meta_item['key'], $meta_item['value'])) {
					continue;
				}
				$key   = sanitize_key($meta_item['key']);
				$value = $meta_item['value'];

				if (in_array($key, array('_credit_limit', 'credit_limit'), true)) {
					$limit = round((float) wc_format_decimal($value), 2);
					$customer->update_meta_data('_credit_limit', max(0, $limit));
					if (class_exists('CWD_V2_Credit_Logic') && $customer->get_id()) {
						CWD_V2_Credit_Logic::sync_user_credit_limit($customer->get_id(), max(0, $limit));
					}
				} elseif (in_array($key, array('_credit_balance', 'credit_balance'), true)) {
					$balance = round((float) wc_format_decimal($value), 2);
					$customer->update_meta_data('_credit_balance', max(0, $balance));
					if (class_exists('CWD_V2_Credit_Logic') && $customer->get_id()) {
						CWD_V2_Credit_Logic::sync_user_credit_balance($customer->get_id(), max(0, $balance));
					}
				} elseif (in_array($key, array('_credit_due_date', 'credit_due_date'), true)) {
					$customer->update_meta_data('_credit_due_date', sanitize_text_field($value));
				} elseif (in_array($key, array('ews_account_number', '_ews_account_number'), true)) {
					$customer->update_meta_data('ews_account_number', sanitize_text_field($value));
				}
			}
		}

		// 2. Process top-level params if provided directly
		if (null !== $request->get_param('credit_limit')) {
			$limit = round((float) wc_format_decimal($request->get_param('credit_limit')), 2);
			$customer->update_meta_data('_credit_limit', max(0, $limit));
			if (class_exists('CWD_V2_Credit_Logic') && $customer->get_id()) {
				CWD_V2_Credit_Logic::sync_user_credit_limit($customer->get_id(), max(0, $limit));
			}
		}
		if (null !== $request->get_param('credit_balance')) {
			$balance = round((float) wc_format_decimal($request->get_param('credit_balance')), 2);
			$customer->update_meta_data('_credit_balance', max(0, $balance));
			if (class_exists('CWD_V2_Credit_Logic') && $customer->get_id()) {
				CWD_V2_Credit_Logic::sync_user_credit_balance($customer->get_id(), max(0, $balance));
			}
		}
		if (null !== $request->get_param('ews_account_number')) {
			$customer->update_meta_data('ews_account_number', sanitize_text_field($request->get_param('ews_account_number')));
		}

		return $customer;
	}

	/**
	 * Append formatted credit fields to the WooCommerce Customer REST response
	 *
	 * @param WP_REST_Response $response
	 * @param WC_Customer $customer
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function prepare_rest_customer_response($response, $customer, $request)
	{
		$user_id = $customer->get_id();
		if (! $user_id) {
			return $response;
		}

		$credit_limit     = class_exists('CWD_V2_Credit_Logic') ? CWD_V2_Credit_Logic::get_user_credit_limit($user_id) : (float) get_user_meta($user_id, '_credit_limit', true);
		$credit_balance   = class_exists('CWD_V2_Credit_Logic') ? CWD_V2_Credit_Logic::get_user_credit_balance($user_id) : (float) get_user_meta($user_id, '_credit_balance', true);
		$available_credit = max(0, $credit_limit - $credit_balance);
		$due_date         = (string) get_user_meta($user_id, '_credit_due_date', true);
		$account_number   = (string) get_user_meta($user_id, 'ews_account_number', true);
		if (empty($account_number)) {
			$account_number = (string) get_user_meta($user_id, 'cwd_ews_account_number', true);
		}
		if (empty($account_number)) {
			$account_number = (string) get_user_meta($user_id, 'trade_number', true);
		}
		if (empty($account_number)) {
			$account_number = 'EWS-' . str_pad((string) $user_id, 6, '0', STR_PAD_LEFT);
		}
		if (get_user_meta($user_id, 'ews_account_number', true) !== $account_number) {
			update_user_meta($user_id, 'ews_account_number', $account_number);
		}

		$data = $response->get_data();
		$data['credit_account'] = array(
			'is_credit_customer' => in_array('credit_account', (array) $customer->get_role(), true) || in_array('credit_account', (array) get_userdata($user_id)->roles, true) || $credit_limit > 0,
			'credit_limit'       => $credit_limit,
			'credit_balance'     => $credit_balance,
			'available_credit'   => $available_credit,
			'credit_due_date'    => $due_date,
			'ews_account_number' => $account_number,
		);

		// Guarantee ews_account_number is also present in standard meta_data array
		$meta_data = isset($data['meta_data']) && is_array($data['meta_data']) ? $data['meta_data'] : array();
		$found_meta = false;
		foreach ($meta_data as &$m_entry) {
			if (isset($m_entry['key']) && $m_entry['key'] === 'ews_account_number') {
				$m_entry['value'] = $account_number;
				$found_meta = true;
				break;
			}
		}
		unset($m_entry);
		if (! $found_meta) {
			$meta_data[] = array(
				'id'    => 0,
				'key'   => 'ews_account_number',
				'value' => $account_number,
			);
		}
		$data['meta_data'] = $meta_data;

		$response->set_data($data);

		return $response;
	}

	/**
	 * Dedicated direct sync endpoint: POST /wp-json/cwd/v2/sync-credit
	 */
	public static function register_credit_sync_routes()
	{
		register_rest_route(
			'cwd/v2',
			'/sync-credit',
			array(
				'methods'             => 'POST',
				'callback'            => array(__CLASS__, 'handle_direct_credit_sync'),
				'permission_callback' => array(__CLASS__, 'rest_meta_auth_callback'),
				'args'                => array(
					'customer_id' => array(
						'type'        => 'integer',
						'required'    => false,
						'description' => 'WooCommerce customer ID',
					),
					'email' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => 'Customer email address',
					),
					'credit_limit' => array(
						'type'        => 'number',
						'required'    => false,
						'description' => 'Credit limit in pounds',
					),
					'credit_balance' => array(
						'type'        => 'number',
						'required'    => false,
						'description' => 'Current outstanding balance in pounds',
					),
					'ews_account_number' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => 'EWS trade account number',
					),
					'credit_due_date' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => 'Payment due date',
					),
					'enable_credit' => array(
						'type'        => 'boolean',
						'required'    => false,
						'description' => 'Assign credit_account role',
					),
				),
			)
		);

		register_rest_route(
			'cwd/v2',
			'/sync-invoice',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_direct_invoice_sync' ),
				'permission_callback' => array( __CLASS__, 'rest_meta_auth_callback' ),
				'args'                => array(
					'customer_id'     => array( 'type' => 'integer', 'required' => false ),
					'email'           => array( 'type' => 'string',  'required' => false ),
					'odoo_invoice_id' => array( 'type' => 'string',  'required' => true ),
					'invoice_number'  => array( 'type' => 'string',  'required' => false ),
					'invoice_date'    => array( 'type' => 'string',  'required' => false ),
					'due_date'        => array( 'type' => 'string',  'required' => false ),
					'amount_total'    => array( 'type' => 'number',  'required' => false ),
					'amount_paid'     => array( 'type' => 'number',  'required' => false ),
					'status'          => array( 'type' => 'string',  'required' => false ),
					'document_url'    => array( 'type' => 'string',  'required' => false ),
				),
			)
		);

		register_rest_route(
			'cwd/v2',
			'/sync-tracking',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_direct_tracking_sync' ),
				'permission_callback' => array( __CLASS__, 'rest_meta_auth_callback' ),
				'args'                => array(
					'order_id'         => array( 'type' => 'string',  'required' => true ),
					'carrier'          => array( 'type' => 'string',  'required' => false ),
					'tracking_number'  => array( 'type' => 'string',  'required' => true ),
					'tracking_url'     => array( 'type' => 'string',  'required' => false ),
					'notify_customer'  => array( 'type' => 'boolean', 'required' => false ),
				),
			)
		);
	}

	/**
	 * Process direct credit sync request from Odoo
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_direct_credit_sync($request)
	{
		$customer_id = (int) $request->get_param('customer_id');
		$email       = sanitize_email($request->get_param('email'));

		$user = null;
		if ($customer_id > 0) {
			$user = get_userdata($customer_id);
		}
		if (! $user && ! empty($email)) {
			$user = get_user_by('email', $email);
		}

		if (! $user) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __('Customer not found.', 'custom-woo-dashboard'),
				),
				404
			);
		}

		$user_id = $user->ID;

		if (null !== $request->get_param('credit_limit')) {
			$limit = round((float) wc_format_decimal($request->get_param('credit_limit')), 2);
			update_user_meta($user_id, '_credit_limit', max(0, $limit));
			if (class_exists('CWD_V2_Credit_Logic')) {
				CWD_V2_Credit_Logic::sync_user_credit_limit($user_id, max(0, $limit));
			}
		}

		if (null !== $request->get_param('credit_balance')) {
			$balance = round((float) wc_format_decimal($request->get_param('credit_balance')), 2);
			update_user_meta($user_id, '_credit_balance', max(0, $balance));
			if (class_exists('CWD_V2_Credit_Logic')) {
				CWD_V2_Credit_Logic::sync_user_credit_balance($user_id, max(0, $balance));
			}
		}

		if (null !== $request->get_param('credit_due_date')) {
			update_user_meta($user_id, '_credit_due_date', sanitize_text_field($request->get_param('credit_due_date')));
		}

		if (null !== $request->get_param('ews_account_number')) {
			update_user_meta($user_id, 'ews_account_number', sanitize_text_field($request->get_param('ews_account_number')));
		}

		if (true === $request->get_param('enable_credit') || (isset($limit) && $limit > 0)) {
			if (! in_array('credit_account', (array) $user->roles, true)) {
				$user->add_role('credit_account');
			}
		}

		if (class_exists('CWD_V2_Credits_Bridge')) {
			CWD_V2_Credits_Bridge::sync_user_to_credits_post($user_id);
		}

		$final_limit     = (float) get_user_meta($user_id, '_credit_limit', true);
		$final_balance   = (float) get_user_meta($user_id, '_credit_balance', true);
		$available_credit = max(0, $final_limit - $final_balance);

		return new WP_REST_Response(
			array(
				'success'          => true,
				'customer_id'      => $user_id,
				'email'            => $user->user_email,
				'credit_limit'     => $final_limit,
				'credit_balance'   => $final_balance,
				'available_credit' => $available_credit,
				'ews_account_number' => (string) get_user_meta($user_id, 'ews_account_number', true),
			),
			200
		);
	}

	/**
	 * Process direct invoice sync request from Odoo.
	 * POST /wp-json/cwd/v2/sync-invoice
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_direct_invoice_sync( $request )
	{
		$customer_id = (int) $request->get_param( 'customer_id' );
		$email       = sanitize_email( (string) $request->get_param( 'email' ) );

		$order_id = absint( $request->get_param( 'order_id' ) );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;

		$user = null;
		if ( $customer_id > 0 ) {
			$user = get_userdata( $customer_id );
		}
		if ( ! $user && ! empty( $email ) ) {
			$user = get_user_by( 'email', $email );
		}
		if ( ! $user && $order ) {
			if ( $order->get_user_id() ) {
				$user = get_userdata( $order->get_user_id() );
			}
			if ( ! $user && $order->get_billing_email() ) {
				$user = get_user_by( 'email', $order->get_billing_email() );
			}
		}

		if ( ! $user && ! $order ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Customer or order not found.', 'custom-woo-dashboard' ) ),
				404
			);
		}

		$user_id = $user ? $user->ID : ( $order ? (int) $order->get_user_id() : 0 );

		$row = array(
			'odoo_invoice_id' => $request->get_param( 'odoo_invoice_id' ),
			'order_id'        => $request->get_param( 'order_id' ),
			'invoice_number'  => $request->get_param( 'invoice_number' ),
			'invoice_date'    => $request->get_param( 'invoice_date' ),
			'due_date'        => $request->get_param( 'due_date' ),
			'amount_total'    => $request->get_param( 'amount_total' ),
			'amount_paid'     => $request->get_param( 'amount_paid' ),
			'status'          => $request->get_param( 'status' ),
			'document_url'    => $request->get_param( 'document_url' ),
		);

		$ok = CWD_V2_Invoices::upsert_odoo_invoice( $user_id, $row );

		$live_balance = 0.0;
		if ( $ok && $user_id > 0 && class_exists( 'CWD_V2_Credit_Logic' ) ) {
			$live_balance = CWD_V2_Credit_Logic::recalculate_and_sync_user_credit_balance( $user_id );
		}

		return new WP_REST_Response(
			array(
				'success'        => $ok,
				'customer_id'    => $user_id,
				'email'          => $user ? $user->user_email : ( $order ? $order->get_billing_email() : '' ),
				'credit_balance' => $live_balance,
			),
			$ok ? 200 : 500
		);
	}

	/**
	 * Process direct tracking sync request from Odoo
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_direct_tracking_sync($request)
	{
		$order_id        = sanitize_text_field($request->get_param('order_id'));
		$carrier         = sanitize_text_field($request->get_param('carrier') ?: 'Royal Mail');
		$tracking_number = sanitize_text_field($request->get_param('tracking_number'));
		$tracking_url    = esc_url_raw($request->get_param('tracking_url') ?: '');
		$notify_customer = (bool) $request->get_param('notify_customer');

		if (empty($order_id) || empty($tracking_number)) {
			return new WP_REST_Response(
				array('success' => false, 'message' => __('Order ID and tracking number are required.', 'custom-woo-dashboard')),
				400
			);
		}

		$order = wc_get_order($order_id);
		if (! $order instanceof WC_Order) {
			return new WP_REST_Response(
				array('success' => false, 'message' => __('Order not found.', 'custom-woo-dashboard')),
				404
			);
		}

		// 1. Save using CWD_V2_Shipment_Tracking if available
		if (class_exists('CWD_V2_Shipment_Tracking')) {
			CWD_V2_Shipment_Tracking::add_tracking_item(
				$order,
				array(
					'tracking_provider'    => $carrier,
					'tracking_number'      => $tracking_number,
					'custom_tracking_link' => $tracking_url,
					'date_shipped'         => current_time('Y-m-d'),
				),
				$notify_customer
			);
		}

		// 2. Set standard WooCommerce, AST, and ParcelPanel/ParcelWILL meta
		$order->update_meta_data('_tracking_number', $tracking_number);
		$order->update_meta_data('tracking_number', $tracking_number);
		$order->update_meta_data('_tracking_provider', $carrier);
		$order->update_meta_data('tracking_provider', $carrier);
		if (! empty($tracking_url)) {
			$order->update_meta_data('_custom_tracking_link', $tracking_url);
			$order->update_meta_data('custom_tracking_link', $tracking_url);
		}
		$order->update_meta_data('_parcelpanel_tracking_number', $tracking_number);
		$order->update_meta_data('_parcelpanel_carrier', $carrier);
		$order->update_meta_data('_parcelpanel_tracking_url', $tracking_url);

		// Also populate standard WooCommerce Shipment Tracking items array
		$existing_items = $order->get_meta('_wc_shipment_tracking_items', true);
		if (! is_array($existing_items)) {
			$existing_items = array();
		}
		$item_exists = false;
		foreach ($existing_items as $ei) {
			if (($ei['tracking_number'] ?? '') === $tracking_number) {
				$item_exists = true;
				break;
			}
		}
		if (! $item_exists) {
			$existing_items[] = array(
				'tracking_provider'    => $carrier,
				'tracking_number'      => $tracking_number,
				'custom_tracking_link' => $tracking_url,
				'date_shipped'         => current_time('Y-m-d'),
			);
			$order->update_meta_data('_wc_shipment_tracking_items', $existing_items);
			$order->update_meta_data('_shipment_tracking_items', $existing_items);
		}

		// Add note to order history
		$note = sprintf(
			__('Tracking details updated from Odoo: %1$s - %2$s', 'custom-woo-dashboard'),
			$carrier,
			$tracking_number
		);
		if (! empty($tracking_url)) {
			$note .= ' (' . $tracking_url . ')';
		}
		$order->add_order_note($note, $notify_customer);
		$order->save();

		return new WP_REST_Response(
			array(
				'success'         => true,
				'order_id'        => $order->get_id(),
				'tracking_number' => $tracking_number,
				'carrier'         => $carrier,
			),
			200
		);
	}
}
