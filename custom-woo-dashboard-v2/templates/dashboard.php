<?php

/**
 * Dashboard template
 */
if (! defined('ABSPATH')) {
    exit;
}

$current_user = wp_get_current_user();
$roles = (array) $current_user->roles;
$is_credit_account = in_array('credit_account', $roles) || current_user_can('manage_options');

$user_email = $current_user->user_email;
$display_name = $current_user->display_name;
$company = get_user_meta($current_user->ID, 'billing_company', true);
$company = $company ? $company : __('your account', 'custom-woo-dashboard');
$initial = strtoupper(substr($display_name, 0, 1));
?>
<div class="cwd-v2-grid">
	<!-- Profile Card -->
	<div class="cwd-v2-card cwd-v2-profile-card">
		<div class="cwd-v2-card-content cwd-v2-profile-content">
			<div class="cwd-v2-avatar"><?php echo esc_html( $initial ); ?></div>
			<div class="cwd-v2-profile-info">
				<h2><?php echo esc_html( $display_name ); ?></h2>
				<p><span class="cwd-v2-badge">USER</span> for <?php echo esc_html( $company ); ?></p>
				<p class="cwd-v2-email"><?php echo esc_html( $user_email ); ?></p>
			</div>
		</div>
		<div class="cwd-v2-card-footer">
			<a href="<?php echo esc_url( wc_get_endpoint_url( 'edit-account' ) ); ?>"><?php _e( 'Edit Profile', 'custom-woo-dashboard' ); ?></a>
		</div>
	</div>

	<!-- Orders Card -->
	<div class="cwd-v2-card">
		<div class="cwd-v2-card-content">
			<div class="cwd-v2-icon cwd-v2-icon-orders">🚚</div>
			<div class="cwd-v2-card-text">
				<h3><?php _e( 'Orders', 'custom-woo-dashboard' ); ?></h3>
				<p><?php _e( 'Create, place or check the status of orders.', 'custom-woo-dashboard' ); ?></p>
			</div>
		</div>
		<div class="cwd-v2-card-footer">
			<a href="<?php echo esc_url( wc_get_endpoint_url( 'orders' ) ); ?>"><?php _e( 'Manage Orders', 'custom-woo-dashboard' ); ?></a>
		</div>
	</div>

	<!-- Tracking Card -->
	<div class="cwd-v2-card">
		<div class="cwd-v2-card-content">
			<div class="cwd-v2-icon cwd-v2-icon-tracking">📍</div>
			<div class="cwd-v2-card-text">
				<h3><?php _e( 'Tracking', 'custom-woo-dashboard' ); ?></h3>
				<p><?php _e( 'Track the status of your shipments.', 'custom-woo-dashboard' ); ?></p>
			</div>
		</div>
		<div class="cwd-v2-card-footer">
			<a href="<?php echo esc_url( wc_get_endpoint_url( 'ts-shipment-tracking' ) ); ?>"><?php _e( 'Track Shipments', 'custom-woo-dashboard' ); ?></a>
		</div>
	</div>

	<!-- Invoices Card -->
	<div class="cwd-v2-card">
		<div class="cwd-v2-card-content">
			<div class="cwd-v2-icon cwd-v2-icon-invoices">💷</div>
			<div class="cwd-v2-card-text">
				<h3><?php _e( 'Invoices', 'custom-woo-dashboard' ); ?></h3>
				<p><?php _e( 'View, print, and pay outstanding invoices.', 'custom-woo-dashboard' ); ?></p>
			</div>
		</div>
		<div class="cwd-v2-card-footer">
			<a href="<?php echo esc_url( wc_get_endpoint_url( 'invoices' ) ); ?>"><?php _e( 'View Invoices', 'custom-woo-dashboard' ); ?></a>
		</div>
	</div>

	<!-- Returns Card -->
	<div class="cwd-v2-card">
		<div class="cwd-v2-card-content">
			<div class="cwd-v2-icon cwd-v2-icon-returns">
				<svg viewBox="0 0 24 24" aria-hidden="true">
					<path d="M9 7 4 12l5 5" />
					<path d="M4 12h10a6 6 0 0 1 6 6" />
				</svg>
			</div>
			<div class="cwd-v2-card-text">
				<h3><?php _e( 'Returns', 'custom-woo-dashboard' ); ?></h3>
				<p><?php _e( 'View, track and initiate returns.', 'custom-woo-dashboard' ); ?></p>
			</div>
		</div>
		<div class="cwd-v2-card-footer">
			<a href="<?php echo esc_url( wc_get_endpoint_url( 'returns' ) ); ?>"><?php _e( 'Manage Returns', 'custom-woo-dashboard' ); ?></a>
		</div>
	</div>

	<!-- Account Details Card -->
	<div class="cwd-v2-card">
		<div class="cwd-v2-card-content">
			<div class="cwd-v2-icon cwd-v2-icon-account">📋</div>
			<div class="cwd-v2-card-text">
				<h3><?php _e( 'Account Details', 'custom-woo-dashboard' ); ?></h3>
				<p><?php _e( 'View your account information.', 'custom-woo-dashboard' ); ?></p>
			</div>
		</div>
		<div class="cwd-v2-card-footer">
			<a href="<?php echo esc_url( wc_get_endpoint_url( 'edit-account' ) ); ?>"><?php _e( 'View Account Details', 'custom-woo-dashboard' ); ?></a>
		</div>
	</div>
	<div class="cwd-v2-card-footer cwd-v2-orders-actions">
		<a href="<?php echo esc_url(wc_get_endpoint_url('live-orders')); ?>">
			<?php _e('Live Orders', 'custom-woo-dashboard'); ?>
		</a>
		<a href="<?php echo esc_url(wc_get_endpoint_url('back-orders')); ?>">
			<?php _e('Back Orders', 'custom-woo-dashboard'); ?>
		</a>
		<a href="<?php echo esc_url(wc_get_endpoint_url('order-history')); ?>">
			<?php _e('Order History', 'custom-woo-dashboard'); ?>
		</a>
		<a href="<?php echo esc_url(wc_get_endpoint_url('track-order')); ?>">
			<svg class="cwd-v2-action-icon" viewBox="0 0 24 24" aria-hidden="true">
				<circle cx="12" cy="12" r="8" />
				<circle cx="12" cy="12" r="3" />
				<path d="M12 4V2M20 12h2M12 20v2M4 12H2" />
			</svg>
			<?php _e('Track Order', 'custom-woo-dashboard'); ?>
		</a>
	</div>

	<!-- Change Password Card -->
	<div class="cwd-v2-card">
		<div class="cwd-v2-card-content">
			<div class="cwd-v2-icon cwd-v2-icon-password">🔐</div>
			<div class="cwd-v2-card-text">
				<h3><?php _e( 'Change Your Password', 'custom-woo-dashboard' ); ?></h3>
				<p><?php _e( 'Keep your account secure.', 'custom-woo-dashboard' ); ?></p>
			</div>
		</div>
		<div class="cwd-v2-card-footer">
			<a href="<?php echo esc_url( wc_get_endpoint_url( 'lost-password' ) ); ?>"><?php _e( 'Change Password', 'custom-woo-dashboard' ); ?></a>
		</div>
	</div>

	<?php if ( $is_credit_account ) : ?>
	<!-- Credit Dashboard Card -->
	<div class="cwd-v2-card cwd-v2-credit-card">
		<div class="cwd-v2-card-content">
			<div class="cwd-v2-icon cwd-v2-icon-credit">
				<svg viewBox="0 0 24 24" aria-hidden="true">
					<rect x="3" y="5" width="18" height="14" rx="2" />
					<path d="M3 10h18M7 15h4" />
				</svg>
			</div>
			<div class="cwd-v2-card-text">
				<h3><?php _e( 'Credit Dashboard', 'custom-woo-dashboard' ); ?></h3>
				<p><?php _e( 'View credit limit, balance, and pay off your account.', 'custom-woo-dashboard' ); ?></p>
			</div>
		</div>
		<div class="cwd-v2-card-footer">
			<a href="<?php echo esc_url( wc_get_endpoint_url( 'credit' ) ); ?>"><?php _e( 'Manage Credit', 'custom-woo-dashboard' ); ?></a>
		</div>
	</div>
	<?php endif; ?>
</div>