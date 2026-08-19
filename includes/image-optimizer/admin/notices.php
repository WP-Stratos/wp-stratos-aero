<?php
/**
 * Aero Image Optimizer — first-run notice.
 *
 * The optimizer stays switched off on any site that was not already using it,
 * which includes every site updating from a version that predates the module.
 * That is the safe default, but a feature nobody knows about is no feature at
 * all, so the administrator is told once that it exists and how to turn it on.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the first-run notice should still be shown.
 *
 * @return bool
 */
function aero_io_optin_notice_pending() {
	if ( get_option( 'aero_io_optin_notice' ) !== '1' ) {
		return false;
	}
	// Turning the optimizer on answers the notice by itself.
	if ( aero_io_is_enabled() ) {
		return false;
	}
	return true;
}

/**
 * Render the first-run notice.
 *
 * @return void
 */
function aero_io_optin_notice() {
	if ( ! current_user_can( 'manage_options' ) || ! aero_io_optin_notice_pending() ) {
		return;
	}

	// Not on the Images screen itself: the master switch is right there.
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && false !== strpos( (string) $screen->id, 'aero-images' ) ) {
		return;
	}

	if ( get_user_meta( get_current_user_id(), 'aero_io_optin_dismissed', true ) === '1' ) {
		return;
	}

	$conflicts = function_exists( 'aero_io_active_conflicts' ) ? aero_io_active_conflicts() : array();

	$dismiss_url = wp_nonce_url(
		add_query_arg( 'aero_io_dismiss_optin', '1' ),
		'aero_io_dismiss_optin'
	);
	?>
	<div class="notice notice-info aero-io-optin-notice">
		<p>
			<strong><?php esc_html_e( 'Aero can now optimize your images.', 'aero' ); ?></strong>
		</p>
		<p>
			<?php esc_html_e( 'This version adds local WebP and AVIF conversion with bulk optimization, automatic processing of new uploads, and next-gen delivery. Because it changes how images are stored and served, it is switched off until you turn it on.', 'aero' ); ?>
		</p>
		<?php if ( ! empty( $conflicts ) ) : ?>
		<p>
			<?php
			printf(
				/* translators: %s: comma separated plugin names */
				esc_html__( 'Worth knowing before you do: %s is already handling images on this site. Run one optimizer, not two.', 'aero' ),
				esc_html( implode( ', ', array_values( $conflicts ) ) )
			);
			?>
		</p>
		<?php endif; ?>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=aero-images' ) ); ?>"><?php esc_html_e( 'Review Image Settings', 'aero' ); ?></a>
			<a class="button-link" href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:8px;"><?php esc_html_e( 'Not now', 'aero' ); ?></a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'aero_io_optin_notice' );

/**
 * Handle the dismiss link. Dismissal is per user, so one administrator
 * hiding it does not hide it from the others.
 *
 * @return void
 */
function aero_io_handle_optin_dismiss() {
	if ( ! isset( $_GET['aero_io_dismiss_optin'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'aero_io_dismiss_optin' );

	update_user_meta( get_current_user_id(), 'aero_io_optin_dismissed', '1' );

	wp_safe_redirect( remove_query_arg( array( 'aero_io_dismiss_optin', '_wpnonce' ) ) );
	exit;
}
add_action( 'admin_init', 'aero_io_handle_optin_dismiss' );
