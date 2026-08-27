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
			'view-order'
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
		wp_enqueue_style( 'cwd-v2-dashboard-style', CWD_V2_PLUGIN_URL . 'assets/css/style.css', array(), '1.0.0' );

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
		echo '<h3>' . __( 'Invoices', 'custom-woo-dashboard' ) . '</h3>';
		echo '<p>' . __( 'View your synced invoices here.', 'custom-woo-dashboard' ) . '</p>';
		// Placeholder for Xero/Odoo iframe or list
	}

	public static function returns_content() {
		echo '<h3>' . __( 'Returns', 'custom-woo-dashboard' ) . '</h3>';
		echo '<p>' . __( 'View, track, and initiate returns.', 'custom-woo-dashboard' ) . '</p>';
		// Placeholder for Returns integration
	}

	public static function credit_content() {
		// Loaded via credit logic class template inclusion
		include CWD_V2_PLUGIN_DIR . 'templates/credit.php';
	}
}
