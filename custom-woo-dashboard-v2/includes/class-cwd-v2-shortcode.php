<?php

/**
 * Shortcode to render custom dashboard
 */

if (! defined('ABSPATH')) {
	exit;
}

class CWD_V2_Shortcode
{

	public static function init()
	{
		add_shortcode('custom_woo_dashboard_v2', array(__CLASS__, 'render'));
		// Need to filter WooCommerce endpoint content if we are on the dashboard.
		add_action('woocommerce_account_orders_endpoint', array(__CLASS__, 'orders_content'), 5);
		add_action('woocommerce_account_invoices_endpoint', array(__CLASS__, 'invoices_content'));
		add_action('woocommerce_account_view-invoice_endpoint', array(__CLASS__, 'view_invoice_content'));
		add_action('woocommerce_account_returns_endpoint', array(__CLASS__, 'returns_content'));
		add_action('woocommerce_account_statements_endpoint', array(__CLASS__, 'statements_content'));
		add_action('woocommerce_account_credit_endpoint', array(__CLASS__, 'credit_content'));
		add_action('woocommerce_account_credits_endpoint', array(__CLASS__, 'credit_content'));
		add_action('woocommerce_account_change-password_endpoint', array(__CLASS__, 'change_password_content'));
		add_action('woocommerce_account_live-orders_endpoint', array(__CLASS__, 'live_orders_content'));
		add_action('woocommerce_account_back-orders_endpoint', array(__CLASS__, 'back_orders_content'));
		add_action('woocommerce_account_order-history_endpoint', array(__CLASS__, 'order_history_content'));
		add_action('woocommerce_account_track-order_endpoint', array(__CLASS__, 'track_order_content'));
		add_action('woocommerce_account_saved-baskets_endpoint', array(__CLASS__, 'saved_baskets_content'));
		add_action('woocommerce_account_unplaced-orders_endpoint', array(__CLASS__, 'unplaced_orders_content'));
		add_action('template_redirect', array(__CLASS__, 'handle_change_password'));
	}

	public static function render($atts)
	{
		if (! is_user_logged_in()) {
			$action           = isset($_GET['action']) && 'register' === $_GET['action'] ? 'register' : 'login';
			$account_url      = wc_get_page_permalink('myaccount');
			$login_tab_url    = remove_query_arg('action', $account_url);
			$register_tab_url = add_query_arg('action', 'register', $account_url);

			ob_start();
			?>
			<div class="cwd-v2-auth-container">
				<div class="cwd-auth-tabs-nav">
					<a href="<?php echo esc_url($login_tab_url); ?>" class="cwd-auth-tab <?php echo 'login' === $action ? 'active' : ''; ?>">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" y1="12" x2="3" y2="12"></line></svg>
						<?php esc_html_e('Sign In', 'custom-woo-dashboard'); ?>
					</a>
					<a href="<?php echo esc_url($register_tab_url); ?>" class="cwd-auth-tab <?php echo 'register' === $action ? 'active' : ''; ?>">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
						<?php esc_html_e('Create Account / Trade', 'custom-woo-dashboard'); ?>
					</a>
				</div>

				<?php if ('register' === $action) : ?>
					<div class="cwd-auth-tab-content">
						<?php
						if (class_exists('CWD_V2_Registration')) {
							CWD_V2_Registration::render_form();
						}
						?>
					</div>
				<?php else : ?>
					<div class="cwd-auth-tab-content cwd-v2-login-wrapper">
						<?php wc_print_notices(); ?>
						<?php woocommerce_login_form(); ?>
						<div class="cwd-auth-switch-prompt" style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #e2e8f0; text-align: center; font-size: 14px; color: #64748b;">
							<?php esc_html_e('New to EWS Supplies?', 'custom-woo-dashboard'); ?>
							<a href="<?php echo esc_url($register_tab_url); ?>" style="color: #0284c7; font-weight: 600; text-decoration: none; margin-left: 4px;">
								<?php esc_html_e('Create an Account or Apply for Trade &rarr;', 'custom-woo-dashboard'); ?>
							</a>
						</div>
					</div>
				<?php endif; ?>
			</div>
			<?php
			return ob_get_clean();
		}

		$current_user = wp_get_current_user();
		$roles = (array) $current_user->roles;

		// Check if user is customer or credit_account
		if (! in_array('customer', $roles) && ! in_array('credit_account', $roles) && ! current_user_can('manage_options')) {
			return '<p>' . __('You do not have permission to view this dashboard.', 'custom-woo-dashboard') . '</p>';
		}

		ob_start();

		global $wp;

		// Determine which endpoint we are on.
		$is_endpoint = false;
		$current_endpoint = '';

		$endpoints = array(
			'orders',
			'downloads',
			'edit-address',
			'edit-account',
			'payment-methods',
			'invoices',
			'view-invoice',
			'returns',
			'statements',
			'credit',
			'credits',
			'change-password',
			'view-order',
			'live-orders',
			'back-orders',
			'order-history',
			'track-order',
			'saved-baskets',
			'unplaced-orders'
		);

		foreach ($endpoints as $endpoint) {
			if (isset($wp->query_vars[$endpoint])) {
				$is_endpoint = true;
				$current_endpoint = $endpoint;
				break;
			}
		}

		echo '<div class="cwd-v2-dashboard-container">';

		// Enqueue styles with version constant
		$ver = defined('CWD_V2_VERSION') ? CWD_V2_VERSION : '2.2.2';
		wp_enqueue_style('cwd-v2-dashboard-style', CWD_V2_PLUGIN_URL . 'assets/css/style.css', array(), $ver);

		if ($is_endpoint) {
			// We are on an endpoint page (e.g., Orders, Account Details)
			// Add a back button
			echo '<a href="' . esc_url(wc_get_page_permalink('myaccount')) . '" class="cwd-v2-back-button">&larr; ' . __('Back to Dashboard', 'custom-woo-dashboard') . '</a>';
			echo '<div class="cwd-v2-endpoint-content">';

			// Let WooCommerce handle standard endpoints, or we handle custom ones
			do_action('woocommerce_account_' . $current_endpoint . '_endpoint', $wp->query_vars[$current_endpoint]);

			echo '</div>';
		} else {
			// Main Dashboard View
			include CWD_V2_PLUGIN_DIR . 'templates/dashboard.php';
		}

		echo '</div>';

		return ob_get_clean();
	}

	public static function invoices_content()
	{
		include CWD_V2_PLUGIN_DIR . 'templates/invoices.php';
	}

	public static function view_invoice_content()
	{
		include CWD_V2_PLUGIN_DIR . 'templates/view-invoice.php';
	}

	public static function returns_content()
	{
		include CWD_V2_PLUGIN_DIR . 'templates/returns.php';
	}

	public static function statements_content()
	{
		include CWD_V2_PLUGIN_DIR . 'templates/statements.php';
	}

	public static function credit_content()
	{
		include CWD_V2_PLUGIN_DIR . 'templates/credit.php';
	}

	public static function change_password_content()
	{
		include CWD_V2_PLUGIN_DIR . 'templates/change-password.php';
	}

	public static function orders_content()
	{
		remove_action('woocommerce_account_orders_endpoint', 'woocommerce_account_orders');
		include CWD_V2_PLUGIN_DIR . 'templates/orders.php';
	}

	public static function live_orders_content()
	{
		include CWD_V2_PLUGIN_DIR . 'templates/orders.php';
	}

	public static function back_orders_content()
	{
		include CWD_V2_PLUGIN_DIR . 'templates/orders.php';
	}

	public static function order_history_content()
	{
		include CWD_V2_PLUGIN_DIR . 'templates/orders.php';
	}

	public static function track_order_content()
	{
		include CWD_V2_PLUGIN_DIR . 'templates/track-order.php';
	}

	public static function saved_baskets_content()
	{
		include CWD_V2_PLUGIN_DIR . 'templates/saved-baskets.php';
	}

	public static function unplaced_orders_content()
	{
		include CWD_V2_PLUGIN_DIR . 'templates/saved-baskets.php';
	}

	public static function handle_change_password()
	{
		if (! isset($_POST['cwd_v2_change_password']) || ! is_user_logged_in()) {
			return;
		}

		if (! isset($_POST['cwd_v2_change_password_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cwd_v2_change_password_nonce'])), 'cwd_v2_change_password')) {
			wc_add_notice(__('Security check failed. Please try again.', 'custom-woo-dashboard'), 'error');
			return;
		}

		$current_password = isset($_POST['cwd_v2_current_password']) ? (string) wp_unslash($_POST['cwd_v2_current_password']) : '';
		$new_password = isset($_POST['cwd_v2_new_password']) ? (string) wp_unslash($_POST['cwd_v2_new_password']) : '';
		$confirm_password = isset($_POST['cwd_v2_confirm_password']) ? (string) wp_unslash($_POST['cwd_v2_confirm_password']) : '';
		$user = wp_get_current_user();

		if (! wp_check_password($current_password, $user->user_pass, $user->ID)) {
			wc_add_notice(__('Your current password is incorrect.', 'custom-woo-dashboard'), 'error');
		} elseif (strlen($new_password) < 8) {
			wc_add_notice(__('Your new password must be at least 8 characters long.', 'custom-woo-dashboard'), 'error');
		} elseif ($new_password !== $confirm_password) {
			wc_add_notice(__('The new passwords do not match.', 'custom-woo-dashboard'), 'error');
		} elseif ($new_password === $current_password) {
			wc_add_notice(__('Your new password must be different from your current password.', 'custom-woo-dashboard'), 'error');
		} else {
			wp_set_password($new_password, $user->ID);
			wp_destroy_other_sessions();
			wp_set_auth_cookie($user->ID, false);
			wc_add_notice(__('Your password has been changed successfully.', 'custom-woo-dashboard'), 'success');
		}

		wp_safe_redirect(wc_get_endpoint_url('change-password', '', wc_get_page_permalink('myaccount')));
		exit;
	}
}
