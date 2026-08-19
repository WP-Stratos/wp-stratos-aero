<?php
/**
 * Aero Cache Manager — Edge Cache Controls
 *
 * Enable/disable, purge, and Defensive Mode for the Pressable Edge Cache,
 * driven through the host's Edge_Cache_Plugin API. Purge auto-enables Edge
 * Cache when the server reports it disabled.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Shared branded notice helper ─────────────────────────────────────────────
// Routes messages into the Aero UI flash queue (rendered inside the shell on
// the next screen load). Colors map to notice types for back-compat with the
// original signature. Falls back to a dark inline notice if the UI layer is
// unavailable.
if ( ! function_exists( 'aero_cm_branded_notice' ) ) {
	function aero_cm_branded_notice( $message, $border_color = '#22c55e', $is_html = false ) {
		$type = 'info';
		if ( in_array( $border_color, array( '#22c55e', '#34d399' ), true ) ) {
			$type = 'success';
		} elseif ( in_array( $border_color, array( '#ef4444', '#f87171' ), true ) ) {
			$type = 'error';
		} elseif ( in_array( $border_color, array( '#fbbf24', '#f59e0b' ), true ) ) {
			$type = 'warn';
		}

		if ( function_exists( 'aero_ui_flash_add' ) ) {
			aero_ui_flash_add( $is_html ? $message : esc_html( $message ), $type );
			return;
		}

		// Fallback (UI layer not loaded): echo a dark, on-brand inline notice.
		$colors = array(
			'success' => '#34d399',
			'error'   => '#f87171',
			'warn'    => '#fbbf24',
			'info'    => '#4a80f0',
		);
		$c = $colors[ $type ];
		echo '<div style="display:flex;align-items:flex-start;gap:10px;padding:12px 16px;margin:10px 20px 10px 0;'
		   . 'font-size:12.5px;border-left:3px solid ' . esc_attr( $c ) . ';background:#1f1f1f;color:' . esc_attr( $c ) . ';'
		   . "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;line-height:1.6;" . '">';
		echo $is_html ? $message : esc_html( $message );
		echo '</div>';
	}
}

// ─── Error notice helper ──────────────────────────────────────────────────────
function aero_cm_edge_cache_error_msg( $error_message = '' ) {
	$msg = empty( $error_message )
		? esc_html__( 'Something went wrong trying to communicate with the Edge Cache system. Try again.', 'aero' )
		: esc_html( $error_message );
	aero_cm_branded_notice( $msg, '#ef4444' );
}

// ─── Enable Edge Cache ────────────────────────────────────────────────────────
function aero_cm_enable_edge_cache() {
	if ( isset( $_POST['aero_enable_edge_cache_nonce'] ) &&
		 wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aero_enable_edge_cache_nonce'] ) ), 'aero_enable_edge_cache_nonce' ) &&
		 current_user_can( 'manage_options' ) ) {

		if ( class_exists( 'Edge_Cache_Plugin' ) ) {
			$edge_cache = Edge_Cache_Plugin::get_instance();
			$result     = $edge_cache->query_ec_backend( 'on', array( 'wp_action' => 'manual_dashboard_set' ) );

			if ( is_wp_error( $result ) ) {
				update_option( 'edge-cache-status', 'Error' );
				update_option( 'edge-cache-enabled', 'disabled' );
				aero_cm_edge_cache_error_msg( $result->get_error_message() );
			} else {
				update_option( 'edge-cache-status', 'Success' );
				update_option( 'edge-cache-enabled', 'enabled' );
				delete_transient( 'aero_cm_ec_status_cache' );
				$html = '<span><strong>' . esc_html__( 'Edge Cache Enabled!', 'aero' ) . '</strong> &mdash; '
					   . esc_html__( 'Edge Cache improves Time to First Byte (TTFB) by serving page cache from the server nearest to your visitors.', 'aero' )
					   . '</span>';
				aero_cm_branded_notice( $html, '#22c55e', true );
			}
		} else {
			aero_cm_edge_cache_error_msg( __( 'Required Edge Cache dependency is not available.', 'aero' ) );
		}
	
		if ( function_exists( 'aero_ui_redirect' ) && ! wp_doing_ajax() ) {
			aero_ui_redirect( 'aero-edge' );
		}
	}
}
add_action( 'init', 'aero_cm_enable_edge_cache' );

// ─── Disable Edge Cache ───────────────────────────────────────────────────────
function aero_cm_disable_edge_cache() {
	if ( isset( $_POST['aero_disable_edge_cache_nonce'] ) &&
		 wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aero_disable_edge_cache_nonce'] ) ), 'aero_disable_edge_cache_nonce' ) &&
		 current_user_can( 'manage_options' ) ) {

		if ( class_exists( 'Edge_Cache_Plugin' ) ) {
			$edge_cache = Edge_Cache_Plugin::get_instance();
			$result     = $edge_cache->query_ec_backend( 'off', array( 'wp_action' => 'manual_dashboard_set' ) );

			if ( is_wp_error( $result ) ) {
				update_option( 'edge-cache-status', 'Error' );
				update_option( 'edge-cache-enabled', 'enabled' ); // stays enabled on failure
				aero_cm_edge_cache_error_msg( $result->get_error_message() );
			} else {
				update_option( 'edge-cache-status', 'Success' );
				update_option( 'edge-cache-enabled', 'disabled' );
				delete_transient( 'aero_cm_ec_status_cache' );
				aero_cm_branded_notice( esc_html__( 'Edge Cache Deactivated.', 'aero' ), '#22c55e' );
			}
		} else {
			aero_cm_edge_cache_error_msg( __( 'Required Edge Cache dependency is not available.', 'aero' ) );
		}
	
		if ( function_exists( 'aero_ui_redirect' ) && ! wp_doing_ajax() ) {
			aero_ui_redirect( 'aero-edge' );
		}
	}
}
add_action( 'init', 'aero_cm_disable_edge_cache' );

// ─── Purge Edge Cache (settings-page button, POST + nonce) ───────────────────
function aero_cm_edge_cache_purge_handler() {
	if ( ! isset( $_POST['aero_purge_edge_cache_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aero_purge_edge_cache_nonce'] ) ), 'aero_purge_edge_cache_nonce' ) ||
		 ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$result = aero_cm_step_flush_edge( 'aero-dashboard-purge' );

	aero_cm_branded_notice( $result['message'], $result['success'] ? '#22c55e' : '#ef4444' );

		if ( function_exists( 'aero_ui_redirect' ) && ! wp_doing_ajax() ) {
			aero_ui_redirect( 'aero-edge' );
		}
}
add_action( 'init', 'aero_cm_edge_cache_purge_handler' );

// ─── Defensive Mode ───────────────────────────────────────────────────────────

/** Duration map: slug => [ label, seconds ] */
function aero_cm_defensive_mode_durations() {
	$durations = array(
		'30-minutes' => array( 'label' => '30 minutes', 'seconds' => 30 * MINUTE_IN_SECONDS ),
		'45-minutes' => array( 'label' => '45 minutes', 'seconds' => 45 * MINUTE_IN_SECONDS ),
	);
	for ( $h = 1; $h <= 23; $h++ ) {
		$slug               = $h . '-hour' . ( $h > 1 ? 's' : '' );
		$durations[ $slug ] = array(
			'label'   => $h . ' hour' . ( $h > 1 ? 's' : '' ),
			'seconds' => $h * HOUR_IN_SECONDS,
		);
	}
	for ( $d = 1; $d <= 7; $d++ ) {
		$slug               = $d . '-day' . ( $d > 1 ? 's' : '' );
		$durations[ $slug ] = array(
			'label'   => $d . ' day' . ( $d > 1 ? 's' : '' ),
			'seconds' => $d * DAY_IN_SECONDS,
		);
	}
	return $durations;
}

// ─── Enable Defensive Mode ────────────────────────────────────────────────────
function aero_cm_enable_defensive_mode() {
	if ( ! isset( $_POST['aero_enable_defensive_mode_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aero_enable_defensive_mode_nonce'] ) ), 'aero_enable_defensive_mode_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$durations = aero_cm_defensive_mode_durations();
	$slug      = isset( $_POST['aero_defensive_mode_duration'] )
		? sanitize_text_field( wp_unslash( $_POST['aero_defensive_mode_duration'] ) )
		: '30-minutes';

	if ( ! array_key_exists( $slug, $durations ) ) {
		$slug = '30-minutes';
	}

	if ( ! class_exists( 'Edge_Cache_Plugin' ) ) {
		aero_cm_branded_notice( esc_html__( 'Error: Edge Cache Plugin is not active.', 'aero' ), '#ef4444' );
		if ( function_exists( 'aero_ui_redirect' ) && ! wp_doing_ajax() ) {
			aero_ui_redirect( 'aero-edge' );
		}
		return;
	}

	$expires_at = time() + $durations[ $slug ]['seconds'];

	$edge_cache = Edge_Cache_Plugin::get_instance();
	$result     = $edge_cache->query_ec_backend( 'ddos_until', array(
		'body' => array(
			'timestamp' => $expires_at,
			'wp_action' => 'manual_dashboard_set',
		),
	) );

	if ( is_array( $result ) && false === $result['success'] ) {
		$err = ! empty( $result['error'] ) ? $result['error'] : esc_html__( 'Unknown error enabling Defensive Mode.', 'aero' );
		aero_cm_branded_notice( $err, '#ef4444' );
	} else {
		update_option( 'edge-cache-defensive-mode-active', 'yes' );
		update_option( 'edge-cache-defensive-mode-slug', $slug );
		update_option( 'edge-cache-defensive-mode-expires-at', $expires_at );
		update_option( 'edge-cache-defensive-mode-set-at', gmdate( 'j M Y, g:ia' ) . ' UTC' );
		delete_transient( 'aero_cm_ec_status_cache' );
		do_action( 'aero_cm_after_defensive_mode_change' );

		$label = $durations[ $slug ]['label'];
		aero_cm_branded_notice(
				sprintf( esc_html__( 'Defensive Mode enabled for %s.', 'aero' ), $label ),
				'#22c55e'
			);
	}

		if ( function_exists( 'aero_ui_redirect' ) && ! wp_doing_ajax() ) {
			aero_ui_redirect( 'aero-edge' );
		}
}
add_action( 'init', 'aero_cm_enable_defensive_mode' );

// ─── Disable Defensive Mode ───────────────────────────────────────────────────
function aero_cm_disable_defensive_mode() {
	if ( ! isset( $_POST['aero_disable_defensive_mode_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aero_disable_defensive_mode_nonce'] ) ), 'aero_disable_defensive_mode_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! class_exists( 'Edge_Cache_Plugin' ) ) {
		aero_cm_branded_notice( esc_html__( 'Error: Edge Cache Plugin is not active.', 'aero' ), '#ef4444' );
		if ( function_exists( 'aero_ui_redirect' ) && ! wp_doing_ajax() ) {
			aero_ui_redirect( 'aero-edge' );
		}
		return;
	}

	$edge_cache = Edge_Cache_Plugin::get_instance();
	$result     = $edge_cache->query_ec_backend( 'ddos_until', array(
		'body' => array(
			'timestamp' => 0,
			'wp_action' => 'manual_dashboard_set',
		),
	) );

	if ( is_array( $result ) && false === $result['success'] ) {
		$err = ! empty( $result['error'] ) ? $result['error'] : esc_html__( 'Unknown error disabling Defensive Mode.', 'aero' );
		aero_cm_branded_notice( $err, '#ef4444' );
	} else {
		update_option( 'edge-cache-defensive-mode-active', 'no' );
		update_option( 'edge-cache-defensive-mode-slug', '' );
		update_option( 'edge-cache-defensive-mode-expires-at', 0 );
		update_option( 'edge-cache-defensive-mode-set-at', '' );
		delete_transient( 'aero_cm_ec_status_cache' );
		do_action( 'aero_cm_after_defensive_mode_change' );

		aero_cm_branded_notice( esc_html__( 'Defensive Mode disabled.', 'aero' ), '#22c55e' );
	}

		if ( function_exists( 'aero_ui_redirect' ) && ! wp_doing_ajax() ) {
			aero_ui_redirect( 'aero-edge' );
		}
}
add_action( 'init', 'aero_cm_disable_defensive_mode' );

// ─── AJAX: check defensive mode status from server ────────────────────────────
function aero_cm_ajax_check_defensive_mode_status() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		return;
	}

	if ( ! class_exists( 'Edge_Cache_Plugin' ) ) {
		wp_send_json_error( array( 'message' => 'Edge Cache Plugin not available.' ) );
		return;
	}

	$edge_cache = Edge_Cache_Plugin::get_instance();
	if ( ! method_exists( $edge_cache, 'get_ec_ddos_until' ) ) {
		wp_send_json_error( array( 'message' => 'Defensive Mode status method unavailable.' ) );
		return;
	}

	$ddos_until = $edge_cache->get_ec_ddos_until(); // int timestamp, or EC_ERROR (-1)

	if ( defined( 'Edge_Cache_Plugin::EC_ERROR' ) && Edge_Cache_Plugin::EC_ERROR === $ddos_until ) {
		wp_send_json_error( array( 'message' => 'Could not retrieve Defensive Mode status from server.' ) );
		return;
	}

	$is_defensive = $ddos_until > time();

	if ( $is_defensive ) {
		update_option( 'edge-cache-defensive-mode-active', 'yes' );
		update_option( 'edge-cache-defensive-mode-expires-at', $ddos_until );

		wp_send_json_success( array(
			'defensive_active' => true,
			'set_at'           => get_option( 'edge-cache-defensive-mode-set-at', '' ),
			'expires_at'       => gmdate( 'j M Y, g:ia', $ddos_until ) . ' UTC',
		) );
	} else {
		update_option( 'edge-cache-defensive-mode-active', 'no' );
		update_option( 'edge-cache-defensive-mode-slug', '' );
		update_option( 'edge-cache-defensive-mode-expires-at', 0 );
		update_option( 'edge-cache-defensive-mode-set-at', '' );

		wp_send_json_success( array(
			'defensive_active' => false,
			'set_at'           => '',
			'expires_at'       => '',
		) );
	}
}
add_action( 'wp_ajax_aero_cm_check_defensive_mode_status', 'aero_cm_ajax_check_defensive_mode_status' );
