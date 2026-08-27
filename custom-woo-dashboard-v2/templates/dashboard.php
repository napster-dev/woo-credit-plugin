<?php
/**
 * Dashboard template
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_user = wp_get_current_user();
$roles = (array) $current_user->roles;
$is_credit_account = in_array( 'credit_account', $roles ) || current_user_can( 'manage_options' );

$user_email = $current_user->user_email;
$display_name = $current_user->display_name;
$company = get_user_meta( $current_user->ID, 'billing_company', true );
$initial = strtoupper( substr( $display_name, 0, 1 ) );
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

	<!-- Invoices Card -->
	<div class="cwd-v2-card">
		<div class="cwd-v2-card-content">
			<div class="cwd-v2-icon cwd-v2-icon-invoices">💷</div>
			<div class="cwd-v2-card-text">
				<h3><?php _e( 'Invoices', 'custom-woo-dashboard' ); ?></h3>
				<p><?php _e( 'View invoices for your account.', 'custom-woo-dashboard' ); ?></p>
			</div>
		</div>
		<div class="cwd-v2-card-footer">
			<a href="<?php echo esc_url( wc_get_endpoint_url( 'invoices' ) ); ?>"><?php _e( 'View Invoices', 'custom-woo-dashboard' ); ?></a>
		</div>
	</div>

	<!-- Returns Card -->
	<div class="cwd-v2-card">
		<div class="cwd-v2-card-content">
			<div class="cwd-v2-icon cwd-v2-icon-returns">↩️</div>
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

	<!-- Products Card -->
	<div class="cwd-v2-card">
		<div class="cwd-v2-card-content">
			<div class="cwd-v2-icon cwd-v2-icon-products">📦</div>
			<div class="cwd-v2-card-text">
				<h3><?php _e( 'Products', 'custom-woo-dashboard' ); ?></h3>
				<p><?php _e( 'Search our list of products.', 'custom-woo-dashboard' ); ?></p>
			</div>
		</div>
		<div class="cwd-v2-card-footer">
			<!-- Link to shop page -->
			<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>"><?php _e( 'View Products', 'custom-woo-dashboard' ); ?></a>
		</div>
	</div>

	<?php if ( $is_credit_account ) : ?>
	<!-- Credit Dashboard Card -->
	<div class="cwd-v2-card cwd-v2-credit-card">
		<div class="cwd-v2-card-content">
			<div class="cwd-v2-icon cwd-v2-icon-credit">💳</div>
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
