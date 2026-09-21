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
	public static function rest_meta_auth_callback()
	{
		return current_user_can('manage_woocommerce') || current_user_can('manage_options');
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
				} elseif (in_array($key, array('_credit_balance', 'credit_balance'), true)) {
					$balance = round((float) wc_format_decimal($value), 2);
					$customer->update_meta_data('_credit_balance', max(0, $balance));
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
		}
		if (null !== $request->get_param('credit_balance')) {
			$balance = round((float) wc_format_decimal($request->get_param('credit_balance')), 2);
			$customer->update_meta_data('_credit_balance', max(0, $balance));
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

		$credit_limit     = (float) get_user_meta($user_id, '_credit_limit', true);
		$credit_balance   = (float) get_user_meta($user_id, '_credit_balance', true);
		$available_credit = max(0, $credit_limit - $credit_balance);
		$due_date         = (string) get_user_meta($user_id, '_credit_due_date', true);
		$account_number   = (string) get_user_meta($user_id, 'ews_account_number', true);

		$data = $response->get_data();
		$data['credit_account'] = array(
			'is_credit_customer' => in_array('credit_account', (array) $customer->get_role(), true) || in_array('credit_account', (array) get_userdata($user_id)->roles, true),
			'credit_limit'       => $credit_limit,
			'credit_balance'     => $credit_balance,
			'available_credit'   => $available_credit,
			'credit_due_date'    => $due_date,
			'ews_account_number' => $account_number,
		);
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
		}

		if (null !== $request->get_param('credit_balance')) {
			$balance = round((float) wc_format_decimal($request->get_param('credit_balance')), 2);
			update_user_meta($user_id, '_credit_balance', max(0, $balance));
		}

		if (null !== $request->get_param('credit_due_date')) {
			update_user_meta($user_id, '_credit_due_date', sanitize_text_field($request->get_param('credit_due_date')));
		}

		if (null !== $request->get_param('ews_account_number')) {
			update_user_meta($user_id, 'ews_account_number', sanitize_text_field($request->get_param('ews_account_number')));
		}

		if (true === $request->get_param('enable_credit')) {
			if (! in_array('credit_account', (array) $user->roles, true)) {
				$user->add_role('credit_account');
			}
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
}
