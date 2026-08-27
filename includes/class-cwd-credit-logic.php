<?php
/**
 * Credit Logic class
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CWD_Credit_Logic {

	public static function init() {
		// Handle pay balance submission
		add_action( 'template_redirect', array( __CLASS__, 'handle_pay_balance' ) );

		// Handle credit limit increase request
		add_action( 'template_redirect', array( __CLASS__, 'handle_credit_increase_request' ) );

		// Set price of credit payment product in cart
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_credit_payment_price' ), 10, 1 );

		// Hook into order payment to decrease balance
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'reduce_credit_balance_on_payment' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'reduce_credit_balance_on_payment' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'reduce_credit_balance_on_payment' ) );
	}

	public static function handle_pay_balance() {
		if ( isset( $_POST['cwd_pay_credit_balance'] ) && isset( $_POST['cwd_pay_amount'] ) && is_user_logged_in() ) {
			if ( ! wp_verify_nonce( $_POST['cwd_pay_credit_nonce'], 'cwd_pay_credit_action' ) ) {
				wc_add_notice( __( 'Security check failed.', 'custom-woo-dashboard' ), 'error' );
				return;
			}

			$amount = floatval( $_POST['cwd_pay_amount'] );
			if ( $amount <= 0 ) {
				wc_add_notice( __( 'Please enter a valid amount.', 'custom-woo-dashboard' ), 'error' );
				return;
			}

			$product_id = (int) get_option( 'cwd_credit_payment_product_id' );
			if ( ! $product_id ) {
				wc_add_notice( __( 'Credit payment product not configured.', 'custom-woo-dashboard' ), 'error' );
				return;
			}

			// Empty cart to ensure only this payment is processed? Optional, but cleaner.
			WC()->cart->empty_cart();

			// Add product to cart with custom price data
			WC()->cart->add_to_cart( $product_id, 1, 0, array(), array( 'cwd_custom_price' => $amount ) );

			// Redirect to checkout
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
	}

	public static function set_credit_payment_price( $cart_obj ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart_obj->get_cart() as $key => $value ) {
			if ( isset( $value['cwd_custom_price'] ) ) {
				$value['data']->set_price( $value['cwd_custom_price'] );
			}
		}
	}

	public static function reduce_credit_balance_on_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;

		// Check if already processed to avoid double reduction
		if ( $order->get_meta( '_cwd_credit_payment_processed' ) ) {
			return;
		}

		$product_id = (int) get_option( 'cwd_credit_payment_product_id' );
		$is_credit_payment = false;
		$payment_amount = 0;

		foreach ( $order->get_items() as $item ) {
			if ( $item->get_product_id() === $product_id ) {
				$is_credit_payment = true;
				$payment_amount += $item->get_total();
			}
		}

		if ( $is_credit_payment ) {
			$user_id = $order->get_user_id();
			if ( $user_id ) {
				$current_balance = (float) get_user_meta( $user_id, '_credit_balance', true );
				$new_balance = max( 0, $current_balance - $payment_amount );
				update_user_meta( $user_id, '_credit_balance', $new_balance );

				// Mark as processed
				$order->update_meta_data( '_cwd_credit_payment_processed', 'yes' );
				$order->save();
			}
		}
	}

	public static function handle_credit_increase_request() {
		if ( isset( $_POST['cwd_request_increase'] ) && is_user_logged_in() ) {
			if ( ! wp_verify_nonce( $_POST['cwd_request_increase_nonce'], 'cwd_request_increase_action' ) ) {
				wc_add_notice( __( 'Security check failed.', 'custom-woo-dashboard' ), 'error' );
				return;
			}

			$current_user = wp_get_current_user();
			$requested_amount = sanitize_text_field( $_POST['cwd_requested_amount'] );
			$reason = sanitize_textarea_field( $_POST['cwd_request_reason'] );

			$admin_email = get_option( 'admin_email' );
			$subject = sprintf( __( 'Credit Limit Increase Request from %s', 'custom-woo-dashboard' ), $current_user->display_name );

			$message = sprintf( __( "Customer: %s (%s)\n", 'custom-woo-dashboard' ), $current_user->display_name, $current_user->user_email );
			$message .= sprintf( __( "Requested New Limit: %s\n", 'custom-woo-dashboard' ), $requested_amount );
			$message .= sprintf( __( "Reason:\n%s\n", 'custom-woo-dashboard' ), $reason );

			wp_mail( $admin_email, $subject, $message );

			wc_add_notice( __( 'Your request for a credit limit increase has been sent to the administrator.', 'custom-woo-dashboard' ), 'success' );
		}
	}
}
