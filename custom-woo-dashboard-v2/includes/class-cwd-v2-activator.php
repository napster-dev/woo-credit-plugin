<?php
/**
 * Fired during plugin activation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CWD_V2_Activator {

	public static function activate() {
		// Ensure WooCommerce is active. We check for a function that exists if Woo is loaded,
		// but since activation happens before plugins_loaded in some contexts, we just try our best.
		// We'll create the product using WP core functions if WC classes aren't available, or WC functions if they are.

		$product_id = get_option( 'cwd_v2_credit_payment_product_id' );

		// Check if product exists and is not trashed
		if ( $product_id && 'publish' === get_post_status( $product_id ) ) {
			// Product already exists
		} else {
			// Create the hidden "Credit Account Payment" product
			$post_data = array(
				'post_title'   => 'Credit Account Payment',
				'post_content' => 'Used for paying off credit balances.',
				'post_status'  => 'publish',
				'post_type'    => 'product',
			);

			$new_product_id = wp_insert_post( $post_data );

			if ( ! is_wp_error( $new_product_id ) ) {
				// Set it as a simple product
				wp_set_object_terms( $new_product_id, 'simple', 'product_type' );

				// Make it hidden from catalog and search
				update_post_meta( $new_product_id, '_visibility', 'hidden' );
				// WC 3.0+ uses product_visibility taxonomy
				wp_set_object_terms( $new_product_id, 'exclude-from-search', 'product_visibility' );
				wp_set_object_terms( $new_product_id, 'exclude-from-catalog', 'product_visibility' );

				// Other meta fields
				update_post_meta( $new_product_id, '_virtual', 'yes' );
				update_post_meta( $new_product_id, '_sold_individually', 'yes' );
				update_post_meta( $new_product_id, '_price', '0' );
				update_post_meta( $new_product_id, '_regular_price', '0' );
				update_post_meta( $new_product_id, '_manage_stock', 'no' );
				update_post_meta( $new_product_id, '_stock_status', 'instock' );

				update_option( 'cwd_v2_credit_payment_product_id', $new_product_id );
			}
		}

		// Add rewrite rules and flush
		// We will call the init function of endpoints to register them before flushing
		if ( class_exists( 'CWD_V2_Endpoints' ) ) {
			CWD_V2_Endpoints::add_endpoints();
		}

		flush_rewrite_rules();
	}
}
