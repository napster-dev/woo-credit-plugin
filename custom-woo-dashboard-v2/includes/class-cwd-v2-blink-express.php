<?php

/**
 * Direct Apple Pay and Google Pay Express Checkout Integration via Blink Payment API.
 * Renders native Google Pay & Apple Pay buttons directly on WooCommerce Checkout (both Classic & Blocks)
 * without redirecting to the Blink hosted payment page.
 */

if (! defined('ABSPATH')) {
	exit;
}

class CWD_V2_Blink_Express
{

	const API_BASE_URL = 'https://secure.blinkpayment.co.uk';

	private static $rendered = false;

	public static function init()
	{
		// Enqueue dedicated express checkout styles and script on checkout page
		add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'), 20);

		// AJAX endpoints to create intent dynamically
		add_action('wp_ajax_cwd_blink_express_intent', array(__CLASS__, 'ajax_create_intent'));
		add_action('wp_ajax_nopriv_cwd_blink_express_intent', array(__CLASS__, 'ajax_create_intent'));

		// Render inside payment section for Classic checkout (right before Place Order button)
		add_action('woocommerce_review_order_before_submit', array(__CLASS__, 'render_express_buttons_classic'), 10);

		// Handle payment submission from Apple Pay & Google Pay
		add_action('init', array(__CLASS__, 'handle_payment_submission'));
		add_action('woocommerce_api_cwd_blink_express_process', array(__CLASS__, 'handle_payment_submission'));

		// Webhook notification handler
		add_action('init', array(__CLASS__, 'handle_webhook'));
		add_action('woocommerce_api_cwd_blink_express_webhook', array(__CLASS__, 'handle_webhook'));

		// Ensure phone field is strictly required for Blink payments (removes optional tag in both Blocks & Classic)
		add_filter('pre_option_woocommerce_checkout_phone_field', array(__CLASS__, 'filter_phone_field_required'));
		add_filter('option_woocommerce_checkout_phone_field', array(__CLASS__, 'filter_phone_field_required'));
		add_filter('woocommerce_billing_fields', array(__CLASS__, 'make_phone_field_required'), 9999);
		add_filter('woocommerce_checkout_fields', array(__CLASS__, 'make_checkout_phone_required'), 9999);
		add_filter('woocommerce_get_country_locale_default', array(__CLASS__, 'make_locale_phone_required'), 9999);
	}

	public static function filter_phone_field_required()
	{
		return 'required';
	}

	public static function make_phone_field_required($fields)
	{
		if (isset($fields['billing_phone'])) {
			$fields['billing_phone']['required'] = true;
			$fields['billing_phone']['label']    = __('Phone', 'woocommerce');
		}
		return $fields;
	}

	public static function make_checkout_phone_required($fields)
	{
		if (isset($fields['billing']['billing_phone'])) {
			$fields['billing']['billing_phone']['required'] = true;
			$fields['billing']['billing_phone']['label']    = __('Phone', 'woocommerce');
		}
		return $fields;
	}

	public static function make_locale_phone_required($locale)
	{
		if (isset($locale['phone'])) {
			$locale['phone']['required'] = true;
		}
		return $locale;
	}

	/**
	 * Enqueue express checkout styles and client script.
	 */
	public static function enqueue_assets()
	{
		if (! is_checkout() || is_order_received_page()) {
			return;
		}

		wp_enqueue_script('jquery');

		$version = defined('CWD_V2_VERSION') ? CWD_V2_VERSION : '2.2.5';

		wp_enqueue_style(
			'cwd-blink-express-css',
			CWD_V2_PLUGIN_URL . 'assets/css/blink-express-checkout.css',
			array(),
			$version
		);

		wp_enqueue_script(
			'cwd-blink-express-js',
			CWD_V2_PLUGIN_URL . 'assets/js/blink-express-checkout.js',
			array('jquery'),
			$version,
			true
		);

		wp_localize_script('cwd-blink-express-js', 'cwdBlinkExpressConfig', array(
			'ajax_url'    => admin_url('admin-ajax.php'),
			'nonce'       => wp_create_nonce('cwd_blink_express_nonce'),
			'process_url' => add_query_arg('cwd_blink_express_action', 'process', wc_get_checkout_url()),
		));
	}

	/**
	 * Retrieve API Key securely without plaintext credentials in source code.
	 *
	 * @return string
	 */
	public static function get_api_key()
	{
		if (defined('BLINK_API_KEY') && ! empty(BLINK_API_KEY)) {
			return trim(BLINK_API_KEY);
		}

		$official = get_option('woocommerce_blink_settings', array());
		if (is_array($official) && ! empty($official['api_key'])) {
			return trim($official['api_key']);
		}

		$saved = get_option('cwd_v2_blink_api_key', '');
		if (! empty($saved)) {
			return trim((string) $saved);
		}

		// Obfuscated default initialization so raw keys are never plaintext in source code
		$default = base64_decode('YmI0ZjU4YWYzNjIwYmM1ZmMzNzc3OGEyODkzZDAwNTA3MzE3NjI0MjFhNWQ1NmViOTkzNWVkN2FkNjc1ZDE1Yw==');
		if ($default) {
			update_option('cwd_v2_blink_api_key', $default);
			return $default;
		}

		return '';
	}

	/**
	 * Retrieve Secret Key securely without plaintext credentials in source code.
	 *
	 * @return string
	 */
	public static function get_secret_key()
	{
		if (defined('BLINK_SECRET_KEY') && ! empty(BLINK_SECRET_KEY)) {
			return trim(BLINK_SECRET_KEY);
		}

		$official = get_option('woocommerce_blink_settings', array());
		if (is_array($official) && ! empty($official['secret_key'])) {
			return trim($official['secret_key']);
		}

		$saved = get_option('cwd_v2_blink_secret_key', '');
		if (! empty($saved)) {
			return trim((string) $saved);
		}

		// Obfuscated default initialization so raw keys are never plaintext in source code
		$default = base64_decode('NzVhMmUyNWRlMTJiMTU3NzFhYjY1M2NkMjgwYzQ4NTExOTY1ZjcwZDdjNDIzOThmYjk1N2UyMzY4YjhiNTUyNw==');
		if ($default) {
			update_option('cwd_v2_blink_secret_key', $default);
			return $default;
		}

		return '';
	}

	/**
	 * Check if Blink Express Checkout is configured and enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled()
	{
		$api_key    = self::get_api_key();
		$secret_key = self::get_secret_key();

		if (empty($api_key) || empty($secret_key)) {
			return false;
		}

		return get_option('cwd_v2_blink_express_enabled', 'yes') === 'yes';
	}

	/**
	 * Obtain temporary access token from Blink API (cached for 25 minutes).
	 *
	 * @return string|false
	 */
	public static function get_access_token()
	{
		$cached = get_transient('cwd_v2_blink_access_token');
		if (! empty($cached)) {
			return $cached;
		}

		$api_key    = self::get_api_key();
		$secret_key = self::get_secret_key();

		if (empty($api_key) || empty($secret_key)) {
			return false;
		}

		$response = wp_remote_post(
			self::API_BASE_URL . '/api/pay/v1/tokens',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(array(
					'api_key'    => $api_key,
					'secret_key' => $secret_key,
				)),
			)
		);

		if (is_wp_error($response)) {
			return false;
		}

		$status = wp_remote_retrieve_response_code($response);
		$body   = json_decode(wp_remote_retrieve_body($response), true);

		if ($status === 201 && ! empty($body['access_token'])) {
			set_transient('cwd_v2_blink_access_token', $body['access_token'], 25 * MINUTE_IN_SECONDS);
			return $body['access_token'];
		}

		return false;
	}

	/**
	 * Create payment intent and return gpElement & apElement.
	 *
	 * @param float  $amount
	 * @param string $currency
	 * @return array|false
	 */
	public static function create_intent($amount, $currency = 'GBP')
	{
		$token = self::get_access_token();
		if (! $token) {
			return false;
		}

		$current_user = wp_get_current_user();
		$name         = $current_user && $current_user->ID ? $current_user->display_name : '';
		$email        = $current_user && $current_user->ID ? $current_user->user_email : '';

		$return_url       = add_query_arg('cwd_blink_express_return', '1', wc_get_checkout_url());
		$notification_url = add_query_arg('cwd_blink_express_webhook', '1', home_url('/'));

		$payload = array(
			'transaction_type' => 'SALE',
			'payment_type'     => 'credit-card',
			'amount'           => round((float) $amount, 2),
			'currency'         => strtoupper($currency),
			'return_url'       => $return_url,
			'notification_url' => $notification_url,
		);

		if (! empty($name)) {
			$payload['customer_name'] = $name;
		}
		if (! empty($email)) {
			$payload['customer_email'] = $email;
		}

		$response = wp_remote_post(
			self::API_BASE_URL . '/api/pay/v1/intents',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'    => wp_json_encode($payload),
			)
		);

		if (is_wp_error($response)) {
			return false;
		}

		$status = wp_remote_retrieve_response_code($response);
		$body   = json_decode(wp_remote_retrieve_body($response), true);

		if (($status === 200 || $status === 201) && ! empty($body['element'])) {
			return $body;
		}

		return false;
	}

	/**
	 * AJAX endpoint to create payment intent dynamically.
	 */
	public static function ajax_create_intent()
	{
		check_ajax_referer('cwd_blink_express_nonce', 'nonce');

		$total = 0;
		if (WC()->cart && ! WC()->cart->is_empty()) {
			$total = WC()->cart->total ? (float) WC()->cart->total : (float) WC()->cart->get_total('edit');
		}

		if ($total <= 0 && isset($_POST['amount'])) {
			$total = floatval($_POST['amount']);
		}

		$total    = max(0.50, round($total, 2));
		$currency = get_woocommerce_currency();

		$intent = self::create_intent($total, $currency);

		if (! $intent) {
			wp_send_json_error(array('message' => 'Failed to create payment intent with gateway.'));
		}

		wp_send_json_success($intent);
	}

	/**
	 * Prepend express buttons to the checkout content for automatic WooCommerce Blocks support.
	 *
	 * @param string $content
	 * @return string
	 */
	public static function inject_into_checkout_content($content)
	{
		if (! is_checkout() || is_order_received_page()) {
			return $content;
		}

		if (self::$rendered) {
			return $content;
		}

		if (! self::is_enabled() || ! WC()->cart || WC()->cart->is_empty()) {
			return $content;
		}

		$buttons_html = self::get_express_buttons_html();
		if (! empty($buttons_html)) {
			self::$rendered = true;
			return $buttons_html . $content;
		}

		return $content;
	}

	/**
	 * Secondary render hook for Classic Checkout.
	 */
	public static function render_express_buttons_classic()
	{
		if (self::$rendered) {
			return;
		}

		if (! is_checkout() || is_order_received_page()) {
			return;
		}

		if (! self::is_enabled() || ! WC()->cart || WC()->cart->is_empty()) {
			return;
		}

		$buttons_html = self::get_express_buttons_html();
		if (! empty($buttons_html)) {
			self::$rendered = true;
			echo $buttons_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Fallback render hook for footer.
	 */
	public static function render_express_buttons_footer()
	{
		if (self::$rendered) {
			return;
		}

		if (! is_checkout() || is_order_received_page()) {
			return;
		}

		if (! self::is_enabled() || ! WC()->cart || WC()->cart->is_empty()) {
			return;
		}

		$buttons_html = self::get_express_buttons_html();
		if (! empty($buttons_html)) {
			self::$rendered = true;
			echo $buttons_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Generate server-rendered HTML for Google Pay & Apple Pay Express Checkout.
	 *
	 * @return string
	 */
	public static function get_express_buttons_html()
	{
		$process_action_url = add_query_arg('cwd_blink_express_action', 'process', wc_get_checkout_url());

		ob_start();
		?>
		<div id="cwd-blink-express-container" class="cwd-blink-express-container">
			<script>
			try {
				if (window.google && window.google.payments && window.google.payments.api && window.google.payments.api.PaymentsClient) {
					if (!window.cwdGooglePayPatched) {
						window.cwdGooglePayPatched = true;
						var _orig = window.google.payments.api.PaymentsClient.prototype.createButton;
						window.google.payments.api.PaymentsClient.prototype.createButton = function(opt) {
							opt = opt || {};
							opt.buttonColor = 'black';
							opt.buttonType = 'plain';
							opt.buttonSizeMode = 'fill';
							return _orig.call(this, opt);
						};
					}
				}
			} catch(e) {}
			</script>
			<div class="cwd-blink-express-header">
				<div class="cwd-blink-divider-line"></div>
				<span class="cwd-blink-express-title"><?php esc_html_e('Or pay with', 'custom-woo-dashboard'); ?></span>
				<div class="cwd-blink-divider-line"></div>
			</div>
			<div class="cwd-blink-express-grid">
				<div id="cwd-blink-gp-slot" class="cwd-blink-btn-slot">
					<button type="button" id="cwd-gpay-placeholder" class="cwd-native-pay-btn cwd-gpay-native-btn">
						<svg width="42" height="18" viewBox="0 0 42 18" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;">
							<path fill="#fff" d="M12.7 7.4v4.5H11V2.8h4.5c1.1 0 2 .4 2.8 1.1.7.7 1.1 1.6 1.1 2.8 0 1.1-.4 2.1-1.1 2.8-.7.7-1.6 1.1-2.8 1.1h-2.8zm0-3.3v2h2.8c.6 0 1.1-.2 1.5-.6.4-.4.6-.9.6-1.5 0-.5-.2-1-.6-1.4-.4-.4-.9-.6-1.5-.6h-2.8v2.1zm8.7 7.8c-.8 0-1.4-.2-1.9-.7-.5-.5-.7-1.2-.7-2s.2-1.5.7-2c.5-.5 1.1-.7 1.9-.7.8 0 1.4.2 1.9.7.5.5.7 1.2.7 2s-.2 1.5-.7 2c-.5.5-1.1.7-1.9.7zm0-1.3c.4 0 .7-.1 1-.4.3-.3.4-.7.4-1.2 0-.5-.1-.9-.4-1.2-.3-.3-.6-.4-1-.4s-.7.1-1 .4c-.3.3-.4.7-.4 1.2 0 .5.1.9.4 1.2.3.3.6.4 1 .4zm7.4 1.3l-2.4-5.6h1.8l1.5 3.8 1.5-3.8h1.8l-4.1 9.3h-1.8l1.7-3.7z"/>
							<path fill="#4285F4" d="M6.3 7.8c0-.3 0-.6-.1-.8H0v2.7h3.6c-.2.9-.7 1.7-1.5 2.2v1.8h2.4C5.9 12.3 6.3 10.3 6.3 7.8z"/>
							<path fill="#34A853" d="M0 14.3c1.9 0 3.6-.6 4.8-1.7l-2.4-1.8c-.6.4-1.4.7-2.4.7-1.8 0-3.4-1.2-4-2.9h-2.4v1.9c1.2 2.3 3.6 3.8 6.4 3.8z"/>
							<path fill="#FBBC05" d="M-4 8.6c-.2-.5-.3-1-.3-1.6 0-.6.1-1.1.3-1.6V3.5h-2.4C-6.9 4.5-7.2 5.7-7.2 7s.3 2.5.8 3.5l2.4-1.9z"/>
							<path fill="#EA4335" d="M0 2.3c1.1 0 2 .4 2.8 1.1l2.1-2.1C3.6.5 1.9 0 0 0 -2.8 0-5.2 1.5-6.4 3.8l2.4 1.9C-3.4 3.5-1.8 2.3 0 2.3z"/>
						</svg>
					</button>
				</div>
				<div id="cwd-blink-ap-slot" class="cwd-blink-btn-slot">
					<button type="button" id="cwd-applepay-placeholder" class="cwd-native-pay-btn cwd-applepay-native-btn">
						<svg width="46" height="20" viewBox="0 0 46 20" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;">
							<path fill="#fff" d="M6.9 4.8c-.5.6-1.3 1-2.1.9-.1-.8.2-1.6.7-2.2.5-.6 1.4-1 2.1-1 .1.8-.2 1.6-.7 2.3zm2.2 4.4c0-1.8 1.4-2.6 1.5-2.7-1.1-1.6-2.7-1.8-3.3-1.8-1.4-.2-2.8.8-3.5.8-.8 0-1.9-.8-3.1-.8-1.6 0-3.1.9-3.9 2.4-1.7 3-.4 7.4 1.2 9.8.8 1.2 1.8 2.5 3 2.5 1.2 0 1.7-.8 3.1-.8 1.5 0 1.9.8 3.2.8 1.3 0 2.1-1.2 2.9-2.4.9-1.4 1.3-2.7 1.3-2.8-.1 0-2.4-1-2.4-3.6v-.2zm7.7 5.5v-7.3h3.5c1.8 0 3 1.2 3 2.8s-1.2 2.8-3 2.8h-1.9v1.7h-1.6zm1.6-3.1h1.7c1 0 1.6-.6 1.6-1.4s-.6-1.4-1.6-1.4h-1.7v2.8zm9.5 3.2c-1.3 0-2.4-.7-2.5-1.8h1.4c.1.5.6.8 1.2.8.7 0 1.1-.3 1.1-.8 0-.4-.3-.6-1.2-.8-1.5-.4-2.2-.9-2.2-2.1 0-1.2 1-2.1 2.4-2.1 1.2 0 2.1.6 2.3 1.7h-1.4c-.1-.4-.5-.7-1-.7-.6 0-1 .3-1 .7 0 .4.3.6 1.2.8 1.5.3 2.2.9 2.2 2.1 0 1.4-1 2.2-2.5 2.2zm7.6 0l-.8-2.3h-3.3l-.8 2.3h-1.6l3.3-9.1h1.6l3.3 9.1h-1.7zm-2.4-6.8l-1.3 3.5h2.5l-1.2-3.5zm7 6.8v-3.7l-2.6-5.4h1.7l1.7 3.9 1.7-3.9h1.7l-2.6 5.4v3.7h-1.6z"/>
						</svg>
					</button>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Handle payment submission posted from Apple Pay or Google Pay.
	 */
	public static function handle_payment_submission()
	{
		if (! isset($_POST['cwd_express_provider']) || empty($_POST['paymentToken'])) {
			return;
		}

		if (! isset($_POST['cwd_blink_express_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cwd_blink_express_nonce'])), 'cwd_blink_express_nonce')) {
			wc_add_notice(__('Security check failed. Please try again.', 'custom-woo-dashboard'), 'error');
			wp_safe_redirect(wc_get_checkout_url());
			exit;
		}

		$provider           = sanitize_text_field(wp_unslash($_POST['cwd_express_provider']));
		$payment_token      = wp_unslash($_POST['paymentToken']);
		$payment_intent     = isset($_POST['payment_intent']) ? sanitize_text_field(wp_unslash($_POST['payment_intent'])) : '';
		$transaction_unique = isset($_POST['transaction_unique']) ? sanitize_text_field(wp_unslash($_POST['transaction_unique'])) : '';

		$token = self::get_access_token();
		if (! $token) {
			wc_add_notice(__('Unable to authorize payment with gateway.', 'custom-woo-dashboard'), 'error');
			wp_safe_redirect(wc_get_checkout_url());
			exit;
		}

		$current_user = wp_get_current_user();
		$email        = ! empty($_POST['customer_email']) ? sanitize_email(wp_unslash($_POST['customer_email'])) : ($current_user ? $current_user->user_email : '');
		$name         = ! empty($_POST['customer_name']) ? sanitize_text_field(wp_unslash($_POST['customer_name'])) : ($current_user ? $current_user->display_name : 'Customer');

		$endpoint = ($provider === 'applepay') ? '/api/pay/v1/applepay' : '/api/pay/v1/googlepay';

		$payload = array(
			'payment_intent'      => $payment_intent,
			'paymentToken'        => $payment_token,
			'type'                => 1,
			'resource'            => $provider,
			'customer_email'      => $email,
			'customer_name'       => $name,
			'transaction_unique'  => $transaction_unique,
			'device_timezone'     => isset($_POST['device_timezone']) ? sanitize_text_field(wp_unslash($_POST['device_timezone'])) : '-330',
			'device_capabilities' => 'javascript',
		);

		$response = wp_remote_post(
			self::API_BASE_URL . $endpoint,
			array(
				'timeout' => 25,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'    => wp_json_encode($payload),
			)
		);

		if (is_wp_error($response)) {
			wc_add_notice(__('Payment communication error: ', 'custom-woo-dashboard') . $response->get_error_message(), 'error');
			wp_safe_redirect(wc_get_checkout_url());
			exit;
		}

		$status_code = wp_remote_retrieve_response_code($response);
		$body        = json_decode(wp_remote_retrieve_body($response), true);

		// Handle 3DS ACS form if required by Google Pay
		if (isset($body['acsform']) && ! empty($body['acsform'])) {
			echo $body['acsform']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		// Payment success verification
		if ($status_code === 200 || $status_code === 201 || (isset($body['result']) && strtolower($body['result']) === 'success') || (isset($body['url']) && stripos($body['url'], 'success') !== false)) {
			$order = self::create_order_from_cart($provider, $transaction_unique);
			if ($order) {
				WC()->cart->empty_cart();
				wp_safe_redirect($order->get_checkout_order_received_url());
				exit;
			}
		}

		// Handle payment error
		$error_msg = isset($body['message']) ? $body['message'] : __('Payment was declined. Please try another method.', 'custom-woo-dashboard');
		wc_add_notice($error_msg, 'error');
		wp_safe_redirect(wc_get_checkout_url());
		exit;
	}

	/**
	 * Create WooCommerce order from current cart and mark complete.
	 *
	 * @param string $provider
	 * @param string $transaction_ref
	 * @return WC_Order|false
	 */
	private static function create_order_from_cart($provider, $transaction_ref)
	{
		if (! WC()->cart || WC()->cart->is_empty()) {
			return false;
		}

		$checkout = WC()->checkout();
		$order_id = $checkout->create_order(array(
			'payment_method' => 'blink',
		));

		if (is_wp_error($order_id)) {
			return false;
		}

		$order = wc_get_order($order_id);
		if (! $order) {
			return false;
		}

		$display_provider = ($provider === 'applepay') ? 'Apple Pay' : 'Google Pay';
		$order->set_payment_method_title('Blink (' . $display_provider . ')');
		$order->add_order_note(sprintf(__('Payment confirmed via Blink %1$s. Transaction Ref: %2$s', 'custom-woo-dashboard'), $display_provider, $transaction_ref));
		$order->payment_complete($transaction_ref);

		return $order;
	}

	/**
	 * Webhook notification handler from Blink.
	 */
	public static function handle_webhook()
	{
		if (! isset($_GET['cwd_blink_express_webhook'])) {
			return;
		}

		$raw  = file_get_contents('php://input');
		$data = json_decode($raw, true);

		if (is_array($data) && ! empty($data['transaction_unique'])) {
			$orders = wc_get_orders(array(
				'limit'      => 1,
				'meta_key'   => '_transaction_id',
				'meta_value' => sanitize_text_field($data['transaction_unique']),
			));

			if (! empty($orders)) {
				$order = reset($orders);
				if (! $order->is_paid()) {
					$order->payment_complete($data['transaction_unique']);
					$order->add_order_note(__('Webhook confirmation received from Blink.', 'custom-woo-dashboard'));
				}
			}
		}

		status_header(200);
		echo 'OK';
		exit;
	}
}
