<?php
/**
 * Change password endpoint template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="cwd-v2-change-password">
	<h3><?php esc_html_e( 'Change Password', 'custom-woo-dashboard' ); ?></h3>
	<?php wc_print_notices(); ?>
	<form method="post">
		<p>
			<label for="cwd_v2_current_password"><?php esc_html_e( 'Current password', 'custom-woo-dashboard' ); ?></label>
			<input type="password" name="cwd_v2_current_password" id="cwd_v2_current_password" autocomplete="current-password" required />
		</p>
		<p>
			<label for="cwd_v2_new_password"><?php esc_html_e( 'New password', 'custom-woo-dashboard' ); ?></label>
			<input type="password" name="cwd_v2_new_password" id="cwd_v2_new_password" minlength="8" autocomplete="new-password" required />
		</p>
		<p>
			<label for="cwd_v2_confirm_password"><?php esc_html_e( 'Confirm new password', 'custom-woo-dashboard' ); ?></label>
			<input type="password" name="cwd_v2_confirm_password" id="cwd_v2_confirm_password" minlength="8" autocomplete="new-password" required />
		</p>
		<?php wp_nonce_field( 'cwd_v2_change_password', 'cwd_v2_change_password_nonce' ); ?>
		<button type="submit" name="cwd_v2_change_password" class="button button-primary">
			<?php esc_html_e( 'Save password', 'custom-woo-dashboard' ); ?>
		</button>
	</form>
</div>