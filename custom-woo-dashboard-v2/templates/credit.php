<?php
/**
 * Credit Dashboard Template
 *
 * Modern minimalist design inspired by Figma e-commerce systems.
 * Strictly flat UI, zero gradients, 2D inline SVGs, zero emojis.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

$credit_limit     = (float) get_user_meta( $user_id, '_credit_limit', true );
$credit_balance   = (float) get_user_meta( $user_id, '_credit_balance', true ); // Amount owed
$due_date         = (string) get_user_meta( $user_id, '_credit_due_date', true );
$account_number   = (string) get_user_meta( $user_id, 'ews_account_number', true );
$available_credit = max( 0, $credit_limit - $credit_balance );

// Fetch credit purchase history (orders made with cwd_v2_credit_account gateway)
$args = array(
	'customer_id'    => $user_id,
	'payment_method' => 'cwd_v2_credit_account',
	'limit'          => 10,
);
$credit_orders = wc_get_orders( $args );

// Check for pending and recently rejected requests
$pending_credit_request  = null;
$latest_rejected_request = null;
if ( class_exists( 'CWD_V2_Trade_Applications' ) ) {
	global $wpdb;
	$apps_table             = CWD_V2_Trade_Applications::get_table_name();
	$pending_credit_request = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$apps_table} WHERE user_id = %d AND status = %s AND forminator_form_id = 0 ORDER BY created_at DESC LIMIT 1",
		$user_id,
		'pending'
	) );
	$latest_rejected_request = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$apps_table} WHERE user_id = %d AND status = %s AND forminator_form_id = 0 ORDER BY reviewed_at DESC, id DESC LIMIT 1",
		$user_id,
		'rejected'
	) );
}

// Print notices
wc_print_notices();
?>

<div class="cwd-v2-credit-dashboard" style="max-width: 100%; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
	
	<!-- Header Bar -->
	<div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #e2e8f0;">
		<div>
			<h2 style="font-size: 22px; font-weight: 700; color: #0f172a; margin: 0 0 4px 0; letter-spacing: -0.02em;">
				<?php esc_html_e( 'Trade Credit Account', 'custom-woo-dashboard' ); ?>
			</h2>
			<p style="font-size: 13.5px; color: #64748b; margin: 0;">
				<?php esc_html_e( 'Overview of your trade facility, current usage, and statement schedule.', 'custom-woo-dashboard' ); ?>
			</p>
		</div>
		<?php if ( $account_number ) : ?>
			<div style="background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 12px; font-size: 12px; font-weight: 600; color: #334155;">
				<span style="color: #64748b; margin-right: 4px;"><?php esc_html_e( 'Account:', 'custom-woo-dashboard' ); ?></span>
				<?php echo esc_html( $account_number ); ?>
			</div>
		<?php endif; ?>
	</div>

	<!-- 4-Box Summary Grid (Flat, No Gradients, 2D SVGs) -->
	<div class="cwd-v2-credit-summary" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 32px;">
		
		<!-- Box 1: Credit Limit -->
		<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;">
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
				<span style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em;">
					<?php esc_html_e( 'Total Credit Limit', 'custom-woo-dashboard' ); ?>
				</span>
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<rect x="2" y="5" width="20" height="14" rx="2"></rect>
					<line x1="2" y1="10" x2="22" y2="10"></line>
				</svg>
			</div>
			<div style="font-size: 26px; font-weight: 700; color: #0f172a; line-height: 1.1;">
				<?php echo wp_kses_post( wc_price( $credit_limit ) ); ?>
			</div>
			<div style="font-size: 12px; color: #94a3b8; margin-top: 6px;">
				<?php esc_html_e( 'Approved facility limit', 'custom-woo-dashboard' ); ?>
			</div>
		</div>

		<!-- Box 2: Current Balance Owed -->
		<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;">
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
				<span style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em;">
					<?php esc_html_e( 'Current Balance Owed', 'custom-woo-dashboard' ); ?>
				</span>
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="<?php echo $credit_balance > 0 ? '#dc2626' : '#64748b'; ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<circle cx="12" cy="12" r="10"></circle>
					<polyline points="12 6 12 12 16 14"></polyline>
				</svg>
			</div>
			<div style="font-size: 26px; font-weight: 700; color: <?php echo $credit_balance > 0 ? '#dc2626' : '#0f172a'; ?>; line-height: 1.1;">
				<?php echo wp_kses_post( wc_price( $credit_balance ) ); ?>
			</div>
			<div style="font-size: 12px; color: #94a3b8; margin-top: 6px;">
				<?php esc_html_e( 'Outstanding invoices & orders', 'custom-woo-dashboard' ); ?>
			</div>
		</div>

		<!-- Box 3: Available Credit -->
		<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;">
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
				<span style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em;">
					<?php esc_html_e( 'Available Credit', 'custom-woo-dashboard' ); ?>
				</span>
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
					<polyline points="22 4 12 14.01 9 11.01"></polyline>
				</svg>
			</div>
			<div style="font-size: 26px; font-weight: 700; color: #16a34a; line-height: 1.1;">
				<?php echo wp_kses_post( wc_price( $available_credit ) ); ?>
			</div>
			<div style="font-size: 12px; color: #94a3b8; margin-top: 6px;">
				<?php esc_html_e( 'Usable immediately at checkout', 'custom-woo-dashboard' ); ?>
			</div>
		</div>

		<!-- Box 4: Next Due Date -->
		<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;">
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
				<span style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em;">
					<?php esc_html_e( 'Next Statement Due', 'custom-woo-dashboard' ); ?>
				</span>
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
					<line x1="16" y1="2" x2="16" y2="6"></line>
					<line x1="8" y1="2" x2="8" y2="6"></line>
					<line x1="3" y1="10" x2="21" y2="10"></line>
				</svg>
			</div>
			<div style="font-size: 22px; font-weight: 700; color: #0f172a; line-height: 1.1; margin-top: 2px;">
				<?php echo $due_date ? esc_html( $due_date ) : esc_html__( 'Net 30 Days', 'custom-woo-dashboard' ); ?>
			</div>
			<div style="font-size: 12px; color: #94a3b8; margin-top: 6px;">
				<?php esc_html_e( 'Standard settlement schedule', 'custom-woo-dashboard' ); ?>
			</div>
		</div>

	</div>

	<!-- Action Cards: Pay Off Balance & Request Increase -->
	<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 36px;">
		
		<!-- Card 1: Pay Balance -->
		<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px;">
			<h3 style="display: flex; align-items: center; gap: 8px; font-size: 16px; font-weight: 600; color: #0f172a; margin: 0 0 8px 0;">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0f172a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<line x1="12" y1="1" x2="12" y2="23"></line>
					<path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
				</svg>
				<?php esc_html_e( 'Settle Credit Balance', 'custom-woo-dashboard' ); ?>
			</h3>
			<p style="font-size: 13px; color: #64748b; margin: 0 0 16px 0;">
				<?php esc_html_e( 'Pay down your outstanding balance via debit or credit card to restore your available credit.', 'custom-woo-dashboard' ); ?>
			</p>

			<?php if ( $credit_balance > 0 ) : ?>
				<form method="post" action="">
					<?php wp_nonce_field( 'cwd_v2_pay_credit_action', 'cwd_v2_pay_credit_nonce' ); ?>
					<div style="margin-bottom: 16px;">
						<label for="cwd_v2_pay_amount" style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
							<?php esc_html_e( 'Amount to Pay', 'custom-woo-dashboard' ); ?> (<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>)
						</label>
						<input type="number" step="0.01" min="0.01" max="<?php echo esc_attr( $credit_balance ); ?>" name="cwd_v2_pay_amount" id="cwd_v2_pay_amount" value="<?php echo esc_attr( $credit_balance ); ?>" required style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; font-size: 14px; box-sizing: border-box; background: #ffffff;" />
					</div>
					<button type="submit" name="cwd_v2_pay_credit_balance" class="button button-primary" style="background: #0f172a; color: #ffffff; border: 1px solid #0f172a; border-radius: 6px; padding: 10px 18px; font-size: 13.5px; font-weight: 600; cursor: pointer;">
						<?php esc_html_e( 'Proceed to Settlement', 'custom-woo-dashboard' ); ?>
					</button>
				</form>
			<?php else : ?>
				<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 14px; font-size: 13px; color: #64748b;">
					<?php esc_html_e( 'Your account has zero outstanding balance. No payment is currently due.', 'custom-woo-dashboard' ); ?>
				</div>
			<?php endif; ?>
		</div>

		<!-- Card 2: Request Limit Increase -->
		<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px;">
			<h3 style="display: flex; align-items: center; gap: 8px; font-size: 16px; font-weight: 600; color: #0f172a; margin: 0 0 8px 0;">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0f172a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<line x1="12" y1="19" x2="12" y2="5"></line>
					<polyline points="5 12 12 5 19 12"></polyline>
				</svg>
				<?php esc_html_e( 'Request Credit Limit Increase', 'custom-woo-dashboard' ); ?>
			</h3>
			<p style="font-size: 13px; color: #64748b; margin: 0 0 16px 0;">
				<?php esc_html_e( 'Submit a formal request to our trade finance team to increase your facility.', 'custom-woo-dashboard' ); ?>
			</p>

			<?php if ( $pending_credit_request ) : ?>
				<!-- Pending Request Box -->
				<div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 16px;">
					<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
						<strong style="color: #92400e; font-size: 13.5px;"><?php esc_html_e( 'Request Currently Under Review', 'custom-woo-dashboard' ); ?></strong>
					</div>
					<p style="font-size: 13px; color: #78350f; margin: 0; line-height: 1.4;">
						<?php printf( esc_html__( 'You submitted a request for %s on %s. Our trade finance team is actively reviewing your facility.', 'custom-woo-dashboard' ), '<strong>' . wp_kses_post( wc_price( $pending_credit_request->requested_limit ) ) . '</strong>', esc_html( date_i18n( get_option( 'date_format' ), strtotime( $pending_credit_request->created_at ) ) ) ); ?>
					</p>
				</div>
			<?php else : ?>
				<?php if ( $latest_rejected_request ) : ?>
					<!-- Previous Rejection Notice -->
					<div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 14px 16px; margin-bottom: 16px;">
						<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
							<strong style="color: #991b1b; font-size: 13.5px;"><?php esc_html_e( 'Previous Request Not Approved', 'custom-woo-dashboard' ); ?></strong>
						</div>
						<p style="font-size: 13px; color: #7f1d1d; margin: 0; line-height: 1.4;">
							<?php printf( esc_html__( 'Your previous request for %s on %s was not approved.', 'custom-woo-dashboard' ), '<strong>' . wp_kses_post( wc_price( $latest_rejected_request->requested_limit ) ) . '</strong>', esc_html( date_i18n( get_option( 'date_format' ), strtotime( $latest_rejected_request->reviewed_at ?: $latest_rejected_request->created_at ) ) ) ); ?>
							<?php if ( ! empty( $latest_rejected_request->admin_note ) ) : ?>
								<br><span style="margin-top: 4px; display: inline-block;"><strong><?php esc_html_e( 'Reason:', 'custom-woo-dashboard' ); ?></strong> <?php echo esc_html( $latest_rejected_request->admin_note ); ?></span>
							<?php endif; ?>
						</p>
					</div>
				<?php endif; ?>

				<form method="post" action="" onsubmit="var btn = this.querySelector('button[type=submit]'); if (btn) { btn.style.pointerEvents='none'; btn.style.opacity='0.7'; btn.textContent='Submitting...'; }">
					<?php wp_nonce_field( 'cwd_v2_request_increase_action', 'cwd_v2_request_increase_nonce' ); ?>
					<input type="hidden" name="cwd_v2_request_increase" value="1" />
					<div style="margin-bottom: 12px;">
						<label for="cwd_v2_requested_amount" style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
							<?php esc_html_e( 'Requested New Limit', 'custom-woo-dashboard' ); ?> (<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>)
						</label>
						<input type="number" step="50" min="100" name="cwd_v2_requested_amount" id="cwd_v2_requested_amount" placeholder="<?php echo esc_attr( max( 500, $credit_limit + 500 ) ); ?>" required style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; font-size: 14px; box-sizing: border-box; background: #ffffff;" />
					</div>
					<div style="margin-bottom: 16px;">
						<label for="cwd_v2_request_reason" style="display: block; font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 6px;">
							<?php esc_html_e( 'Reason / Trade Justification', 'custom-woo-dashboard' ); ?>
						</label>
						<textarea name="cwd_v2_request_reason" id="cwd_v2_request_reason" rows="2" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13.5px; box-sizing: border-box; background: #ffffff;" required></textarea>
					</div>
					<button type="submit" name="cwd_v2_request_increase" class="button" style="background: #f1f5f9; color: #0f172a; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 18px; font-size: 13.5px; font-weight: 600; cursor: pointer;">
						<?php esc_html_e( 'Submit Request', 'custom-woo-dashboard' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</div>

	</div>

	<!-- Credit Purchase History Table -->
	<div class="cwd-v2-credit-history" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px;">
		<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
			<h3 style="font-size: 16px; font-weight: 600; color: #0f172a; margin: 0;">
				<?php esc_html_e( 'Recent Trade Credit Orders', 'custom-woo-dashboard' ); ?>
			</h3>
			<span style="font-size: 12.5px; color: #64748b;">
				<?php esc_html_e( 'Showing last 10 credit purchases', 'custom-woo-dashboard' ); ?>
			</span>
		</div>

		<?php if ( ! empty( $credit_orders ) ) : ?>
			<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table" style="width: 100%; border-collapse: collapse; font-size: 13.5px;">
				<thead>
					<tr style="border-bottom: 1px solid #e2e8f0; text-align: left;">
						<th style="padding: 10px 12px; font-weight: 600; color: #64748b; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em;"><?php esc_html_e( 'Order', 'woocommerce' ); ?></th>
						<th style="padding: 10px 12px; font-weight: 600; color: #64748b; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em;"><?php esc_html_e( 'Date', 'woocommerce' ); ?></th>
						<th style="padding: 10px 12px; font-weight: 600; color: #64748b; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em;"><?php esc_html_e( 'Status', 'woocommerce' ); ?></th>
						<th style="padding: 10px 12px; font-weight: 600; color: #64748b; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em;"><?php esc_html_e( 'Total', 'woocommerce' ); ?></th>
						<th style="padding: 10px 12px; font-weight: 600; color: #64748b; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; text-align: right;"><?php esc_html_e( 'Action', 'woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $credit_orders as $order ) : ?>
						<tr style="border-bottom: 1px solid #f1f5f9;">
							<td style="padding: 12px 12px; font-weight: 600; color: #0f172a;">
								<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" style="color: #0f172a; text-decoration: none;">
									#<?php echo esc_html( $order->get_order_number() ); ?>
								</a>
							</td>
							<td style="padding: 12px 12px; color: #64748b;">
								<time datetime="<?php echo esc_attr( $order->get_date_created()->date( 'c' ) ); ?>">
									<?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?>
								</time>
							</td>
							<td style="padding: 12px 12px;">
								<span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0;">
									<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
								</span>
							</td>
							<td style="padding: 12px 12px; font-weight: 600; color: #0f172a;">
								<?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>
							</td>
							<td style="padding: 12px 12px; text-align: right;">
								<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" class="woocommerce-button button view" style="display: inline-block; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 4px; padding: 5px 12px; font-size: 12px; font-weight: 600; color: #334155; text-decoration: none;">
									<?php esc_html_e( 'View', 'woocommerce' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px 20px; font-size: 13.5px; color: #64748b; display: flex; justify-content: space-between; align-items: center;">
				<span><?php esc_html_e( 'No trade credit purchases recorded yet.', 'custom-woo-dashboard' ); ?></span>
				<a href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>" style="color: #0f172a; font-weight: 600; text-decoration: underline;">
					<?php esc_html_e( 'Browse Products', 'woocommerce' ); ?>
				</a>
			</div>
		<?php endif; ?>
	</div>

</div>
