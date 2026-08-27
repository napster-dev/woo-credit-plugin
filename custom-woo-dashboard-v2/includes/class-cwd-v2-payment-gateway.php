<?php
/**
 * Custom Payment Gateway for Credit Account
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Gateway_Credit_Account_V2 extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'cwd_v2_credit_account';
		$this->icon               = '';
		$this->has_fields         = false;
		$this->method_title       = __( 'Pay on Credit Account', 'custom-woo-dashboard' );
		$this->method_description = __( 'Allows credit/trade customers to pay using their credit limit.', 'custom-woo-dashboard' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'custom-woo-dashboard' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Pay on Credit Account', 'custom-woo-dashboard' ),
				'default' => 'yes',
			),
			'title' => array(
				'title'       => __( 'Title', 'custom-woo-dashboard' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'custom-woo-dashboard' ),
				'default'     => __( 'Pay on Credit Account', 'custom-woo-dashboard' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'custom-woo-dashboard' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description that the customer will see on your checkout.', 'custom-woo-dashboard' ),
				'default'     => __( 'Your order will be charged to your credit account.', 'custom-woo-dashboard' ),
			),
		);
	}

	public function is_available() {
		// Only available if the user has the credit_account role
		if ( is_user_logged_in() ) {
			$current_user = wp_get_current_user();
			$roles = (array) $current_user->roles;
			if ( in_array( 'credit_account', $roles ) || current_user_can( 'manage_options' ) ) {

				// We also need to check if the user has enough available credit
				$user_id = $current_user->ID;
				$credit_limit = (float) get_user_meta( $user_id, '_credit_limit', true );
				$credit_balance = (float) get_user_meta( $user_id, '_credit_balance', true ); // How much they OWE

				$available_credit = $credit_limit - $credit_balance;

				// Check if total order amount exceeds available credit
				// Note: WC()->cart may not be available in admin backend, so check if it exists
				if ( isset( WC()->cart ) && WC()->cart ) {
					$total = WC()->cart->get_total( 'edit' );
					if ( $total > $available_credit ) {
						// Don't show gateway if they don't have enough credit, or show it and let it fail in process_payment?
						// It's usually better to hide it or show a message. For simplicity, we hide it.
						// return false;
						// Actually, let's keep it visible but let it fail so they know why.
					}
				}

				return parent::is_available();
			}
		}
		return false;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$user_id = $order->get_user_id();

		if ( ! $user_id ) {
			wc_add_notice( __( 'You must be logged in to use this payment method.', 'custom-woo-dashboard' ), 'error' );
			return;
		}

		$order_total = $order->get_total();
		$credit_limit = (float) get_user_meta( $user_id, '_credit_limit', true );
		$credit_balance = (float) get_user_meta( $user_id, '_credit_balance', true );
		$available_credit = $credit_limit - $credit_balance;

		// Check if they are paying off their credit balance instead of making a new purchase.
		// If the cart contains the 'Credit Account Payment' product, we shouldn't increase their balance.
		// That logic is handled in CWD_V2_Credit_Logic, so here we must check for it.
		$is_credit_payment_order = false;
		$credit_product_id = (int) get_option( 'cwd_v2_credit_payment_product_id' );
		foreach ( $order->get_items() as $item ) {
			if ( $item->get_product_id() === $credit_product_id ) {
				$is_credit_payment_order = true;
				break;
			}
		}

		if ( $is_credit_payment_order ) {
			wc_add_notice( __( 'You cannot use the Credit Account to pay off your Credit Account balance. Please choose another payment method.', 'custom-woo-dashboard' ), 'error' );
			return;
		}

		if ( $order_total > $available_credit ) {
			wc_add_notice( sprintf( __( 'Insufficient available credit. Your available credit is %s.', 'custom-woo-dashboard' ), wc_price( $available_credit ) ), 'error' );
			return;
		}

		// Increase the user's credit balance
		$new_balance = $credit_balance + $order_total;
		update_user_meta( $user_id, '_credit_balance', $new_balance );

		// Mark as on-hold (or processing, depending on your flow)
		$order->update_status( 'processing', __( 'Payment made via Credit Account.', 'custom-woo-dashboard' ) );

		// Reduce stock levels
		wc_reduce_stock_levels( $order_id );

		// Remove cart
		WC()->cart->empty_cart();

		// Return thankyou redirect
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}
