<?php
/**
 * Plugin Name: Custom WooCommerce Dashboard
 * Description: Replaces the default WooCommerce My Account page with a custom dashboard and adds credit account features.
 * Version: 1.0.0
 * Author: Jules
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'CWD_V2_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CWD_V2_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include activator right away for the hook
require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-activator.php';
register_activation_hook( __FILE__, array( 'CWD_V2_Activator', 'activate' ) );

// Init Plugin when plugins are loaded
add_action( 'plugins_loaded', 'cwd_v2_init_plugin' );
if ( ! function_exists( 'cwd_v2_init_plugin' ) ) {
	function cwd_v2_init_plugin() {
		if ( class_exists( 'WooCommerce' ) ) {
			// Include files only when WooCommerce is confirmed to be loaded
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-shortcode.php';
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-endpoints.php';
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-payment-gateway.php';
			require_once CWD_V2_PLUGIN_DIR . 'includes/class-cwd-v2-credit-logic.php';

			CWD_V2_Shortcode::init();
			CWD_V2_Endpoints::init();
			CWD_V2_Credit_Logic::init();

			// Add custom payment gateway
			add_filter( 'woocommerce_payment_gateways', 'cwd_v2_add_payment_gateway' );
		}
	}
}

if ( ! function_exists( 'cwd_v2_add_payment_gateway' ) ) {
	function cwd_v2_add_payment_gateway( $methods ) {
		$methods[] = 'WC_Gateway_Credit_Account_V2';
		return $methods;
	}
}
