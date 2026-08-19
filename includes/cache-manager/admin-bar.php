<?php
/**
 * Aero Cache Manager — Admin Bar "Cache Control" Dropdown
 *
 * Replaces Aero's original single "Clear Aero Cache" toolbar link with the
 * PCM-style dropdown, under Aero branding:
 *   • Clear Aero Cache            (minified CSS/JS)
 *   • Flush Object Cache          (Object + Batcache)
 *   • Purge Edge Cache            (shown only when Edge Cache is enabled)
 *   • Flush All Caches (Sequential)  — Aero → Batcache → Edge (custom order)
 *   • Cache Settings
 *
 * Browser alert() is replaced with an Aero-branded dark-theme modal.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function aero_cm_abar_can_view() {
	return current_user_can( 'administrator' ) || current_user_can( 'editor' ) || current_user_can( 'manage_woocommerce' );
}

// ─── Admin Bar Menu ───────────────────────────────────────────────────────────
add_action( 'admin_bar_menu', 'aero_cm_abar_add_menu', 100 );
function aero_cm_abar_add_menu( $wp_admin_bar ) {
	if ( is_network_admin() || ! aero_cm_abar_can_view() ) {
		return;
	}

	// Detect Edge Cache state
	$edge_cache_is_enabled = false;
	if ( class_exists( 'Edge_Cache_Plugin' ) ) {
		$ec            = Edge_Cache_Plugin::get_instance();
		$server_status = method_exists( $ec, 'get_ec_status' ) ? $ec->get_ec_status() : null;
		if ( defined( 'Edge_Cache_Plugin::EC_ENABLED' ) && Edge_Cache_Plugin::EC_ENABLED === $server_status ) {
			$edge_cache_is_enabled = true;
		} elseif ( get_option( 'edge-cache-enabled' ) === 'enabled' ) {
			$edge_cache_is_enabled = true;
		}
	}

	// Parent
	$wp_admin_bar->add_node( array(
		'id'    => 'aero-cache-control',
		'title' => __( 'Aero Cache Control', 'aero' ),
	) );

	// Clear Aero Cache (minified CSS/JS)
	$wp_admin_bar->add_menu( array(
		'id'     => 'aero-cc-clear-aero',
		'title'  => __( 'Clear Aero Cache', 'aero' ),
		'parent' => 'aero-cache-control',
		'href'   => '#',
		'meta'   => array( 'class' => 'aero-cm-toolbar-child' ),
	) );

	// Flush Object Cache
	$wp_admin_bar->add_menu( array(
		'id'     => 'aero-cc-flush-object',
		'title'  => __( 'Flush Object Cache', 'aero' ),
		'parent' => 'aero-cache-control',
		'href'   => '#',
		'meta'   => array( 'class' => 'aero-cm-toolbar-child' ),
	) );

	// Edge Cache options (only if enabled)
	if ( $edge_cache_is_enabled ) {
		$wp_admin_bar->add_menu( array(
			'id'     => 'aero-cc-purge-edge',
			'title'  => __( 'Purge Edge Cache', 'aero' ),
			'parent' => 'aero-cache-control',
			'href'   => '#',
			'meta'   => array( 'class' => 'aero-cm-toolbar-child' ),
		) );
	}

	// Sequential full flush — always available (edge step self-skips gracefully)
	$wp_admin_bar->add_menu( array(
		'id'     => 'aero-cc-flush-all',
		'title'  => __( 'Flush All Caches (Sequential)', 'aero' ),
		'parent' => 'aero-cache-control',
		'href'   => '#',
		'meta'   => array( 'class' => 'aero-cm-toolbar-child' ),
	) );

	// Cache Settings (admin only)
	if ( current_user_can( 'administrator' ) ) {
		$wp_admin_bar->add_menu( array(
			'id'     => 'aero-cc-settings',
			'title'  => __( 'Cache Settings', 'aero' ),
			'parent' => 'aero-cache-control',
			'href'   => admin_url( 'admin.php?page=aero-cache' ),
			'meta'   => array( 'class' => 'aero-cm-toolbar-child' ),
		) );
	}
}

// ─── Branded dark-theme modal + click handlers (admin + frontend) ────────────
add_action( 'admin_footer', 'aero_cm_abar_modal_and_js' );
add_action( 'wp_footer', 'aero_cm_abar_modal_and_js' );
function aero_cm_abar_modal_and_js() {
	if ( ! aero_cm_abar_can_view() || ! is_admin_bar_showing() ) {
		return;
	}

	static $printed = false;
	if ( $printed ) {
		return;
	}
	$printed = true;

	$nonce = wp_create_nonce( 'aero_cm_abar_nonce' );
	?>
	<div id="aero-cm-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:999999;align-items:center;justify-content:center;">
		<div style="background:#171717;border:1px solid #313131;border-radius:0;padding:0;max-width:460px;width:90%;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;position:relative;">
			<div style="display:flex;align-items:center;gap:12px;padding:15px 20px;border-bottom:1px solid #313131;background:#111;">
				<div style="width:22px;height:22px;background:#4a80f0;display:grid;place-items:center;flex-shrink:0;"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></div>
				<span style="color:#f0f0f0;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;">Aero Cache Control</span>
			</div>
			<div id="aero-cm-modal-message" style="font-size:13px;color:#b0b0b0;line-height:1.7;white-space:pre-line;padding:20px;"></div>
			<div style="padding:0 20px 20px;">
				<button id="aero-cm-modal-ok" style="background:#4a80f0;color:#fff;border:1px solid #4a80f0;border-radius:0;padding:9px 26px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;letter-spacing:.6px;text-transform:uppercase;transition:background .15s;">OK</button>
			</div>
		</div>
	</div>
	<script>
	(function($){
		function aeroCmShowModal(msg) {
			$('#aero-cm-modal-message').text(msg);
			$('#aero-cm-modal-overlay').css('display','flex');
		}
		$(document).on('click', '#aero-cm-modal-ok, #aero-cm-modal-overlay', function(e){
			if (e.target === this) $('#aero-cm-modal-overlay').hide();
		});
		$(document).on('mouseenter', '#aero-cm-modal-ok', function(){ $(this).css('background','#6898f8'); })
		           .on('mouseleave', '#aero-cm-modal-ok', function(){ $(this).css('background','#4a80f0'); });
		window.aeroCmShowModal = aeroCmShowModal;

		var aeroCmAjaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var aeroCmNonce   = <?php echo wp_json_encode( $nonce ); ?>;

		function aeroCmPost(action, workingMsg) {
			aeroCmShowModal(workingMsg || '<?php echo esc_js( __( 'Working…', 'aero' ) ); ?>');
			$.ajax({
				url: aeroCmAjaxUrl, type: 'POST',
				data: { action: action, nonce: aeroCmNonce },
				success: function(r){ aeroCmShowModal((r || '').toString().trim()); },
				error:   function(){ aeroCmShowModal('<?php echo esc_js( __( 'An error occurred during the cache request.', 'aero' ) ); ?>'); }
			});
		}

		$(document).on('click', '#wp-admin-bar-aero-cc-clear-aero .ab-item', function(e){
			e.preventDefault();
			aeroCmPost('aero_cm_abar_clear_aero', '<?php echo esc_js( __( 'Clearing Aero Cache…', 'aero' ) ); ?>');
		});
		$(document).on('click', '#wp-admin-bar-aero-cc-flush-object .ab-item', function(e){
			e.preventDefault();
			aeroCmPost('aero_cm_abar_flush_object', '<?php echo esc_js( __( 'Flushing Object Cache…', 'aero' ) ); ?>');
		});
		$(document).on('click', '#wp-admin-bar-aero-cc-purge-edge .ab-item', function(e){
			e.preventDefault();
			aeroCmPost('aero_cm_abar_purge_edge', '<?php echo esc_js( __( 'Purging Edge Cache…', 'aero' ) ); ?>');
		});
		$(document).on('click', '#wp-admin-bar-aero-cc-flush-all .ab-item', function(e){
			e.preventDefault();
			aeroCmPost('aero_cm_abar_flush_all', '<?php echo esc_js( __( 'Running sequential flush…', 'aero' ) ); ?>');
		});
	})(jQuery);
	</script>
	<?php
}

// ─── AJAX callbacks ───────────────────────────────────────────────────────────
add_action( 'wp_ajax_aero_cm_abar_clear_aero', 'aero_cm_abar_clear_aero_callback' );
add_action( 'wp_ajax_aero_cm_abar_flush_object', 'aero_cm_abar_flush_object_callback' );
add_action( 'wp_ajax_aero_cm_abar_purge_edge', 'aero_cm_abar_purge_edge_callback' );
add_action( 'wp_ajax_aero_cm_abar_flush_all', 'aero_cm_abar_flush_all_callback' );

function aero_cm_abar_verify() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'aero_cm_abar_nonce' ) ) {
		echo esc_html__( 'Security check failed. Please reload the page.', 'aero' );
		wp_die();
	}
	if ( ! aero_cm_abar_can_view() ) {
		echo esc_html__( 'You do not have permission to manage caches.', 'aero' );
		wp_die();
	}
}

function aero_cm_abar_clear_aero_callback() {
	aero_cm_abar_verify();
	$r = aero_cm_step_flush_aero();
	echo esc_html( $r['message'] );
	wp_die();
}

function aero_cm_abar_flush_object_callback() {
	aero_cm_abar_verify();
	$r = aero_cm_step_flush_batcache();
	if ( function_exists( 'aero_cw_maybe_auto_start' ) ) {
		aero_cw_maybe_auto_start( 'after-object-flush (admin bar)' );
	}
	echo esc_html( $r['message'] );
	wp_die();
}

function aero_cm_abar_purge_edge_callback() {
	aero_cm_abar_verify();
	$r = aero_cm_step_flush_edge( 'admin-bar-edge-purge' );
	echo esc_html( $r['message'] );
	wp_die();
}

function aero_cm_abar_flush_all_callback() {
	aero_cm_abar_verify();
	$results = aero_cm_run_sequential_flush( 'admin-bar-sequential-purge' );
	$lines   = array();
	foreach ( $results as $r ) {
		$lines[] = ( $r['success'] ? '✓ ' : '✗ ' ) . $r['message'];
	}
	echo esc_html( implode( "\n", $lines ) );
	wp_die();
}
