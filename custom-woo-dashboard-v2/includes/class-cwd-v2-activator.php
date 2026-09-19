<?php

/**
 * Fired during plugin activation
 */

if (! defined('ABSPATH')) {
	exit;
}

class CWD_V2_Activator
{

	public static function activate()
	{
		self::create_tables();

		// Ensure WooCommerce is active. We check for a function that exists if Woo is loaded,
		// but since activation happens before plugins_loaded in some contexts, we just try our best.
		// We'll create the product using WP core functions if WC classes aren't available, or WC functions if they are.

		$product_id = get_option('cwd_v2_credit_payment_product_id');

		// Check if product exists and is not trashed
		if ($product_id && 'publish' === get_post_status($product_id)) {
			// Product already exists
		} else {
			// Create the hidden "Credit Account Payment" product
			$post_data = array(
				'post_title'   => 'Credit Account Payment',
				'post_content' => 'Used for paying off credit balances.',
				'post_status'  => 'publish',
				'post_type'    => 'product',
			);

			$new_product_id = wp_insert_post($post_data);

			if (! is_wp_error($new_product_id)) {
				// Set it as a simple product
				wp_set_object_terms($new_product_id, 'simple', 'product_type');

				// Make it hidden from catalog and search
				update_post_meta($new_product_id, '_visibility', 'hidden');
				// WC 3.0+ uses product_visibility taxonomy
				wp_set_object_terms($new_product_id, 'exclude-from-search', 'product_visibility');
				wp_set_object_terms($new_product_id, 'exclude-from-catalog', 'product_visibility');

				// Other meta fields
				update_post_meta($new_product_id, '_virtual', 'yes');
				update_post_meta($new_product_id, '_sold_individually', 'yes');
				update_post_meta($new_product_id, '_price', '0');
				update_post_meta($new_product_id, '_regular_price', '0');
				update_post_meta($new_product_id, '_manage_stock', 'no');
				update_post_meta($new_product_id, '_stock_status', 'instock');

				update_option('cwd_v2_credit_payment_product_id', $new_product_id);
			}
		}

		// Add rewrite rules and flush
		// We will call the init function of endpoints to register them before flushing
		if (class_exists('CWD_V2_Endpoints')) {
			CWD_V2_Endpoints::add_endpoints();
		}

		flush_rewrite_rules();
	}

	private static function create_tables()
	{
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$invoices_table = $wpdb->prefix . 'cwd_v2_invoices';
		$sql_invoices = "CREATE TABLE {$invoices_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NULL DEFAULT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			invoice_number VARCHAR(50) NOT NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'website',
			odoo_invoice_id VARCHAR(100) NULL DEFAULT NULL,
			document_url TEXT NULL,
			invoice_date DATETIME NOT NULL,
			due_date DATETIME NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
			amount_total DECIMAL(18,2) NOT NULL DEFAULT 0,
			amount_paid DECIMAL(18,2) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			UNIQUE KEY odoo_invoice_id (odoo_invoice_id),
			KEY user_id (user_id),
			KEY status (status)
		) {$charset_collate};";
		dbDelta($sql_invoices);

		$transactions_table = $wpdb->prefix . 'cwd_v2_account_transactions';
		$sql_transactions = "CREATE TABLE {$transactions_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL,
			amount DECIMAL(18,2) NOT NULL,
			balance_after DECIMAL(18,2) NOT NULL,
			reference_type VARCHAR(20) NULL DEFAULT NULL,
			reference_id BIGINT UNSIGNED NULL DEFAULT NULL,
			note VARCHAR(255) NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset_collate};";
		dbDelta($sql_transactions);

		$returns_table = $wpdb->prefix . 'cwd_v2_returns';
		$sql_returns = "CREATE TABLE {$returns_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL,
			invoice_id BIGINT UNSIGNED NULL DEFAULT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'requested',
			reason TEXT NOT NULL,
			items LONGTEXT NOT NULL,
			refund_status VARCHAR(30) NOT NULL DEFAULT 'pending',
			refund_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			odoo_return_id VARCHAR(100) NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY order_id (order_id),
			KEY status (status),
			UNIQUE KEY odoo_return_id (odoo_return_id)
		) {$charset_collate};";
		dbDelta($sql_returns);
	}
}
