<?php
/**
 * Shortcode to render custom dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CWD_V2_Shortcode {

	public static function init() {
		add_shortcode( 'custom_woo_dashboard_v2', array( __CLASS__, 'render' ) );
		// Need to filter woo commerce endpoints content if we are on the dashboard
		add_action( 'woocommerce_account_invoices_endpoint', array( __CLASS__, 'invoices_content' ) );
		add_action( 'woocommerce_account_returns_endpoint', array( __CLASS__, 'returns_content' ) );
		add_action( 'woocommerce_account_credit_endpoint', array( __CLASS__, 'credit_content' ) );
		add_action( 'woocommerce_account_change-password_endpoint', array( __CLASS__, 'change_password_content' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_change_password' ) );
	}

	public static function render( $atts ) {
		if ( ! is_user_logged_in() ) {
			// Show WooCommerce login form
			ob_start();
			echo '<div class="cwd-v2-login-wrapper">';
			woocommerce_login_form();
			echo '</div>';
			return ob_get_clean();
		}

		$current_user = wp_get_current_user();
		$roles = (array) $current_user->roles;

		// Check if user is customer or credit_account
		if ( ! in_array( 'customer', $roles ) && ! in_array( 'credit_account', $roles ) && ! current_user_can( 'manage_options' ) ) {
			return '<p>' . __( 'You do not have permission to view this dashboard.', 'custom-woo-dashboard' ) . '</p>';
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
			'returns',
			'credit',
			'change-password',
			'view-order',
			'ts-shipment-tracking',
			'lost-password'
		);

		foreach ( $endpoints as $endpoint ) {
			if ( isset( $wp->query_vars[ $endpoint ] ) ) {
				$is_endpoint = true;
				$current_endpoint = $endpoint;
				break;
			}
		}

		echo '<div class="cwd-v2-dashboard-container">';

		// Enqueue styles
		wp_enqueue_style( 'cwd-v2-dashboard-style', CWD_V2_PLUGIN_URL . 'assets/css/style.css', array(), '1.0.1' );

		if ( $is_endpoint ) {
			// We are on an endpoint page (e.g., Orders, Account Details)
			// Add a back button
			echo '<a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '" class="cwd-v2-back-button">&larr; ' . __( 'Back to Dashboard', 'custom-woo-dashboard' ) . '</a>';
			echo '<div class="cwd-v2-endpoint-content">';

			// Let WooCommerce handle standard endpoints, or we handle custom ones
			do_action( 'woocommerce_account_' . $current_endpoint . '_endpoint', $wp->query_vars[ $current_endpoint ] );

			echo '</div>';
		} else {
			// Main Dashboard View
			include CWD_V2_PLUGIN_DIR . 'templates/dashboard.php';
		}

		echo '</div>';

		return ob_get_clean();
	}

	public static function invoices_content() {
		include CWD_V2_PLUGIN_DIR . 'templates/invoices.php';
	}

	public static function returns_content() {
		include CWD_V2_PLUGIN_DIR . 'templates/returns.php';
	}

	public static function credit_content() {
		$current_user = wp_get_current_user();
		$roles = (array) $current_user->roles;
		if ( ! in_array( 'credit_account', $roles, true ) && ! current_user_can( 'manage_options' ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to view this dashboard.', 'custom-woo-dashboard' ) . '</p>';
			return;
		}

		include CWD_V2_PLUGIN_DIR . 'templates/credit.php';
	}

	public static function change_password_content() {
		include CWD_V2_PLUGIN_DIR . 'templates/change-password.php';
	}

	public static function handle_change_password() {
		if ( ! isset( $_POST['cwd_v2_change_password'] ) || ! is_user_logged_in() ) {
			return;
		}

		if ( ! isset( $_POST['cwd_v2_change_password_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cwd_v2_change_password_nonce'] ) ), 'cwd_v2_change_password' ) ) {
			wc_add_notice( __( 'Security check failed. Please try again.', 'custom-woo-dashboard' ), 'error' );
			return;
		}

		$current_password = isset( $_POST['cwd_v2_current_password'] ) ? (string) wp_unslash( $_POST['cwd_v2_current_password'] ) : '';
		$new_password = isset( $_POST['cwd_v2_new_password'] ) ? (string) wp_unslash( $_POST['cwd_v2_new_password'] ) : '';
		$confirm_password = isset( $_POST['cwd_v2_confirm_password'] ) ? (string) wp_unslash( $_POST['cwd_v2_confirm_password'] ) : '';
		$user = wp_get_current_user();

		if ( ! wp_check_password( $current_password, $user->user_pass, $user->ID ) ) {
			wc_add_notice( __( 'Your current password is incorrect.', 'custom-woo-dashboard' ), 'error' );
		} elseif ( strlen( $new_password ) < 8 ) {
			wc_add_notice( __( 'Your new password must be at least 8 characters long.', 'custom-woo-dashboard' ), 'error' );
		} elseif ( $new_password !== $confirm_password ) {
			wc_add_notice( __( 'The new passwords do not match.', 'custom-woo-dashboard' ), 'error' );
		} elseif ( $new_password === $current_password ) {
			wc_add_notice( __( 'Your new password must be different from your current password.', 'custom-woo-dashboard' ), 'error' );
		} else {
			wp_set_password( $new_password, $user->ID );
			wp_set_auth_cookie( $user->ID, false );
			wc_add_notice( __( 'Your password has been changed successfully.', 'custom-woo-dashboard' ), 'success' );
		}

		wp_safe_redirect( wc_get_endpoint_url( 'change-password', '', wc_get_page_permalink( 'myaccount' ) ) );
		exit;
	}
}
