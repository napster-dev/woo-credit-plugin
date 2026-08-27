<?php
/**
 * Registers custom endpoints for WooCommerce My Account
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CWD_V2_Endpoints {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoints' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );

		// Map endpoint titles
		add_filter( 'woocommerce_endpoint_invoices_title', array( __CLASS__, 'invoices_title' ) );
		add_filter( 'woocommerce_endpoint_returns_title', array( __CLASS__, 'returns_title' ) );
		add_filter( 'woocommerce_endpoint_credit_title', array( __CLASS__, 'credit_title' ) );
	}

	public static function add_endpoints() {
		add_rewrite_endpoint( 'invoices', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'returns', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'credit', EP_ROOT | EP_PAGES );
	}

	public static function add_query_vars( $vars ) {
		$vars[] = 'invoices';
		$vars[] = 'returns';
		$vars[] = 'credit';
		return $vars;
	}

	public static function invoices_title() {
		return __( 'Invoices', 'custom-woo-dashboard' );
	}

	public static function returns_title() {
		return __( 'Returns', 'custom-woo-dashboard' );
	}

	public static function credit_title() {
		return __( 'Credit Dashboard', 'custom-woo-dashboard' );
	}
}
