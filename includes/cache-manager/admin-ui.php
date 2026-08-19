<?php
/**
 * Aero — Unified Admin UI
 *
 * One top-level "Aero" menu with submenu screens, all rendered inside a
 * shared shell (header + sidebar navigation + content) that follows the
 * WP Postman design language: pure dark surfaces, sharp corners, blue
 * accent, uppercase micro-labels.
 *
 * Screens:
 *   aero              → Optimization   (Aero's original settings)
 *   aero-cache        → Cache          (flush triggers, Batcache tools)
 *   aero-edge         → Edge Cache     (enable/disable/purge, Defensive Mode)
 *   aero-purge        → Purge & Schedule (sequential order, WP-Cron)
 *   aero-experimental → Experimental   (Guest Mode cache isolation)
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Screen registry. Each entry: slug → label, icon (feather-style SVG),
 * render callback, page description.
 */
function aero_ui_pages() {
	return array(
		'aero' => array(
			'label'  => __( 'Optimization', 'aero' ),
			'title'  => __( 'Optimization', 'aero' ),
			'desc'   => __( 'Front-end performance: minification, combination, deferral, fonts, Guest Mode and Batcache configuration.', 'aero' ),
			'icon'   => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>',
			'render' => 'aero_ui_render_optimization_screen',
		),
		'aero-images' => array(
			'label'  => __( 'Images', 'aero' ),
			'title'  => __( 'Images', 'aero' ),
			'desc'   => __( 'Local WebP & AVIF conversion and compression: bulk optimization, auto-processing of new uploads, format delivery and media replacement. Originals are never modified.', 'aero' ),
			'icon'   => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="0" ry="0"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
			'render' => 'aero_io_render_images_screen',
		),
		'aero-cache' => array(
			'label'  => __( 'Cache', 'aero' ),
			'title'  => __( 'Cache', 'aero' ),
			'desc'   => __( 'Automated flush triggers, per-page flushing, Batcache tools and manual purge controls.', 'aero' ),
			'icon'   => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>',
			'render' => 'aero_cm_render_cache_screen',
		),
		'aero-edge' => array(
			'label'  => __( 'Edge Cache', 'aero' ),
			'title'  => __( 'Edge Cache', 'aero' ),
			'desc'   => __( 'Serve cached pages from the server nearest your visitors, plus Defensive Mode for traffic spikes.', 'aero' ),
			'icon'   => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
			'render' => 'aero_cm_render_edge_screen',
		),
		'aero-purge' => array(
			'label'  => __( 'Purge & Schedule', 'aero' ),
			'title'  => __( 'Purge Order & Schedule', 'aero' ),
			'desc'   => __( 'Control the exact order caches are purged in, and automate full flushes via WP-Cron.', 'aero' ),
			'icon'   => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
			'render' => 'aero_cm_render_purge_screen',
		),
		'aero-warmer' => array(
			'label'  => __( 'Cache Warmer', 'aero' ),
			'title'  => __( 'Cache Warmer', 'aero' ),
			'desc'   => __( 'Rebuild the cache in the background after every flush, so real visitors never pay the regeneration cost.', 'aero' ),
			'icon'   => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
			'render' => 'aero_cw_render_warmer_screen',
		),
		'aero-debug' => array(
			'label'  => __( 'Debug', 'aero' ),
			'title'  => __( 'Debug', 'aero' ),
			'desc'   => __( 'Debug Mode and a copy-ready diagnostic report covering the entire plugin.', 'aero' ),
			'icon'   => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>',
			'render' => 'aero_cm_render_debug_screen',
		),
		'aero-experimental' => array(
			'label'  => __( 'Experimental', 'aero' ),
			'title'  => __( 'Experimental', 'aero' ),
			'desc'   => __( 'Features that are functional but still being validated on production infrastructure.', 'aero' ),
			'icon'   => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 2v7.31"/><path d="M14 9.3V2"/><path d="M8.5 2h7"/><path d="M14 9.3a6.5 6.5 0 1 1-4 0"/><path d="M5.52 16h12.96"/></svg>',
			'render' => 'aero_cm_render_experimental_screen',
		),
	);
}

/**
 * Aero header logo — the paper-plane-in-motion mark reused from Aero's
 * original settings header, scaled for the 34px chip.
 */
function aero_ui_logo_svg() {
	return '<svg width="17" height="17" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg"><g clip-path="url(#aero-ui-lg)"><path d="M0 46.536A12 12 0 0 0 1.25 48H0zM48 48H26.752a5.4 5.4 0 0 1-2.088-1.774c-.984-1.412-1.324-3.32-1.2-5.536.248-4.432 2.35-10.064 4.81-15.306s5.278-10.088 6.954-12.95c.418-.716.766-1.306 1.018-1.75q.19-.334.306-.55t.148-.314l.012-.046c.002-.01.004-.034-.01-.052a.06.06 0 0 0-.034-.022l-.032.002-.04.022a2 2 0 0 0-.236.242c-.222.254-.584.708-1.112 1.382C26.378 22.7 14.54 31.174 0 30.658V0h48z" fill="#fff"/></g><defs><clipPath id="aero-ui-lg"><path d="M0 0h48v48H10.5A10.5 10.5 0 0 1 0 37.5z" fill="#fff"/></clipPath></defs></svg>';
}

// ─── Menu registration ────────────────────────────────────────────────────────
add_action( 'admin_menu', 'aero_ui_register_menu', 9 );
function aero_ui_register_menu() {
	$pages = aero_ui_pages();

	// Top-level: menu icon = the Aero mark, currentColor so WP tints it.
	$menu_icon = 'data:image/svg+xml;base64,' . base64_encode(
		'<svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg"><path d="M48 48H26.752a5.4 5.4 0 0 1-2.088-1.774c-.984-1.412-1.324-3.32-1.2-5.536.248-4.432 2.35-10.064 4.81-15.306s5.278-10.088 6.954-12.95c.418-.716.766-1.306 1.018-1.75q.19-.334.306-.55t.148-.314l.012-.046c.002-.01.004-.034-.01-.052a.06.06 0 0 0-.034-.022l-.032.002-.04.022a2 2 0 0 0-.236.242c-.222.254-.584.708-1.112 1.382C26.378 22.7 14.54 31.174 0 30.658V0h48z" fill="#a7aaad"/></svg>'
	);

	add_menu_page(
		__( 'Aero', 'aero' ),
		__( 'Aero', 'aero' ),
		'manage_options',
		'aero',
		'aero_ui_route',
		$menu_icon,
		59
	);

	foreach ( $pages as $slug => $page ) {
		add_submenu_page(
			'aero',
			sprintf( __( 'Aero — %s', 'aero' ), $page['title'] ),
			$page['label'],
			'manage_options',
			$slug,
			'aero_ui_route'
		);
	}
}

/**
 * Single router callback: resolves the current screen and renders it
 * inside the shared shell.
 */
function aero_ui_route() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'aero' ) );
	}

	$pages = aero_ui_pages();
	$slug  = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'aero';
	if ( ! isset( $pages[ $slug ] ) ) {
		$slug = 'aero';
	}
	$page = $pages[ $slug ];

	aero_ui_shell_open( $slug, $page['title'], $page['desc'] );
	if ( is_callable( $page['render'] ) ) {
		call_user_func( $page['render'] );
	}
	aero_ui_shell_close();
}

/**
 * Is the current admin screen one of the Aero screens?
 */
function aero_ui_is_aero_screen() {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return false;
	}
	$screen = get_current_screen();
	if ( ! $screen ) {
		return false;
	}
	$ids = array(
		'toplevel_page_aero',
		'aero_page_aero-images',
		'aero_page_aero-cache',
		'aero_page_aero-edge',
		'aero_page_aero-purge',
		'aero_page_aero-warmer',
		'aero_page_aero-debug',
		'aero_page_aero-experimental',
	);
	return in_array( $screen->id, $ids, true );
}

// Add a stable body class on Aero screens so all admin CSS can scope to it.
add_filter( 'admin_body_class', 'aero_ui_admin_body_class' );
function aero_ui_admin_body_class( $classes ) {
	if ( aero_ui_is_aero_screen() ) {
		$classes .= ' aero-admin-page';
	}
	return $classes;
}

// ─── Assets ───────────────────────────────────────────────────────────────────
add_action( 'admin_enqueue_scripts', 'aero_ui_enqueue_assets' );
function aero_ui_enqueue_assets( $hook ) {
	$hooks = array(
		'toplevel_page_aero',
		'aero_page_aero-images',
		'aero_page_aero-cache',
		'aero_page_aero-edge',
		'aero_page_aero-purge',
		'aero_page_aero-warmer',
		'aero_page_aero-debug',
		'aero_page_aero-experimental',
	);
	if ( ! in_array( $hook, $hooks, true ) ) {
		return;
	}
	$css     = AERO_CM_DIR . 'assets/admin-ui.css';
	$version = file_exists( $css ) ? filemtime( $css ) : AERO_PLUGIN_VERSION_NUM;
	wp_enqueue_style( 'aero-admin-ui', AERO_CM_URL . 'assets/admin-ui.css', array(), $version );

	// Images screen assets (Image Optimizer module).
	if ( 'aero_page_aero-images' === $hook && defined( 'AERO_IO_DIR' ) ) {
		$io_css = AERO_IO_DIR . '/admin/assets/images.css';
		$io_js  = AERO_IO_DIR . '/admin/assets/images.js';
		wp_enqueue_style( 'aero-io-images', AERO_IO_URL . '/admin/assets/images.css', array( 'aero-admin-ui' ), file_exists( $io_css ) ? filemtime( $io_css ) : AERO_PLUGIN_VERSION_NUM );
		wp_enqueue_script( 'aero-io-images', AERO_IO_URL . '/admin/assets/images.js', array( 'jquery' ), file_exists( $io_js ) ? filemtime( $io_js ) : AERO_PLUGIN_VERSION_NUM, true );
	}
}

// ─── Shell ────────────────────────────────────────────────────────────────────

/**
 * Open the shell: header (logo + name + version + Batcache badge), sidebar
 * navigation, content area with page title/description and flash notices.
 */
function aero_ui_shell_open( $active, $title = '', $desc = '' ) {
	$pages = aero_ui_pages();
	?>
	<div class="wrap" id="aero-root">
		<div class="aero-header">
			<div class="aero-logo"><?php echo aero_ui_logo_svg(); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<div>
				<div class="aero-heading">Aero</div>
				<div class="aero-subheading"><?php esc_html_e( 'Performance & Cache', 'aero' ); ?> &mdash; v<?php echo esc_html( AERO_PLUGIN_VERSION_NUM ); ?></div>
			</div>
			<div class="aero-header-right">
				<?php
				if ( function_exists( 'aero_cm_render_batcache_badge' ) ) {
					aero_cm_render_batcache_badge();
				}
				?>
			</div>
		</div>
		<div class="aero-shell">
			<nav class="aero-sidebar-nav" aria-label="<?php esc_attr_e( 'Aero navigation', 'aero' ); ?>">
				<ul class="aero-nav">
					<?php foreach ( $pages as $slug => $page ) : ?>
						<?php $is_active = ( $slug === $active ) ? ' aero-nav-active' : ''; ?>
						<li class="aero-nav-item<?php echo esc_attr( $is_active ); ?>">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>" class="aero-nav-link<?php echo esc_attr( $is_active ); ?>">
								<span class="aero-nav-icon"><?php echo $page['icon']; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
								<span class="aero-nav-label"><?php echo esc_html( $page['label'] ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</nav>
			<div class="aero-content">
				<?php if ( $title ) : ?>
					<h2 class="aero-page-title"><?php echo esc_html( $title ); ?></h2>
				<?php endif; ?>
				<?php if ( $desc ) : ?>
					<p class="aero-page-desc"><?php echo esc_html( $desc ); ?></p>
				<?php endif; ?>
				<?php aero_ui_render_flash(); ?>
	<?php
}

function aero_ui_shell_close() {
	?>
			</div><!-- .aero-content -->
		</div><!-- .aero-shell -->
	</div><!-- #aero-root -->
	<?php
}

// ─── Action notices ──────────────────────────────────────────────────────────
// Contextual confirmations after purge-causing actions ("Purged X + 7 related
// URLs — re-warming now"), shown wherever the user lands next: alongside
// "Post updated." on the editor, or Aero-styled inside the shell. Per-user,
// max 3 queued, self-expiring.

function aero_ui_action_notice_add( $html ) {
	$uid = get_current_user_id();
	if ( ! $uid || wp_doing_cron() ) {
		return; // no user to show it to (cron/system context)
	}
	$key     = 'aero_action_notices_' . $uid;
	$notices = get_transient( $key );
	$notices = is_array( $notices ) ? $notices : array();
	$notices[] = $html;
	set_transient( $key, array_slice( $notices, -3 ), 2 * MINUTE_IN_SECONDS );
}

add_action( 'admin_notices', 'aero_ui_render_action_notices' );
function aero_ui_render_action_notices() {
	$uid = get_current_user_id();
	if ( ! $uid ) {
		return;
	}
	$key     = 'aero_action_notices_' . $uid;
	$notices = get_transient( $key );
	if ( empty( $notices ) || ! is_array( $notices ) ) {
		return;
	}
	delete_transient( $key );

	$on_aero = function_exists( 'aero_ui_is_aero_screen' ) && aero_ui_is_aero_screen();
	foreach ( $notices as $html ) {
		if ( $on_aero ) {
			echo '<div class="aero-notice aero-notice-success" style="margin:14px 20px 0 0;">' . wp_kses_post( $html ) . '</div>';
		} else {
			echo '<div class="notice notice-success is-dismissible"><p><strong style="letter-spacing:.6px;font-size:11px;">AERO</strong> &nbsp;' . wp_kses_post( $html ) . '</p></div>';
		}
	}
}

// ─── Flash notices (PRG-friendly) ─────────────────────────────────────────────

/**
 * Queue a flash message shown inside the shell on the next Aero screen load.
 *
 * @param string $message Message text (may include limited HTML).
 * @param string $type    success | error | warn | info.
 */
function aero_ui_flash_add( $message, $type = 'success' ) {
	$key   = 'aero_ui_flash_' . get_current_user_id();
	$queue = get_transient( $key );
	if ( ! is_array( $queue ) ) {
		$queue = array();
	}
	$queue[] = array(
		'message' => $message,
		'type'    => in_array( $type, array( 'success', 'error', 'warn', 'info' ), true ) ? $type : 'info',
	);
	set_transient( $key, $queue, 120 );
}

/**
 * Render + clear queued flash messages.
 */
function aero_ui_render_flash() {
	$key   = 'aero_ui_flash_' . get_current_user_id();
	$queue = get_transient( $key );
	if ( ! is_array( $queue ) || empty( $queue ) ) {
		return;
	}
	delete_transient( $key );
	foreach ( $queue as $flash ) {
		printf(
			'<div class="aero-notice aero-notice-%1$s">%2$s</div>',
			esc_attr( $flash['type'] ),
			wp_kses_post( $flash['message'] )
		);
	}
}

/**
 * Redirect back to an Aero screen after a POST (PRG pattern).
 */
function aero_ui_redirect( $slug ) {
	wp_safe_redirect( admin_url( 'admin.php?page=' . $slug ) );
	exit;
}

/**
 * Resolve which Aero screen a POST came from, for post-save redirects.
 */
function aero_ui_current_post_screen( $default = 'aero-cache' ) {
	if ( isset( $_POST['aero_ui_screen'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$slug = sanitize_key( wp_unslash( $_POST['aero_ui_screen'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( array_key_exists( $slug, aero_ui_pages() ) ) {
			return $slug;
		}
	}
	return $default;
}

// ─── Keep Aero screens free of third-party notice noise ──────────────────────
add_action( 'admin_notices', 'aero_ui_hide_other_notices', 1 );
function aero_ui_hide_other_notices() {
	if ( ! aero_ui_is_aero_screen() ) {
		return;
	}
	remove_all_actions( 'admin_notices' );
	remove_all_actions( 'all_admin_notices' );
	// Re-add only Aero's own notices.
	add_action( 'admin_notices', 'aero_ui_render_action_notices' );
	if ( function_exists( 'aero_cache_cleared_notice' ) ) {
		add_action( 'admin_notices', 'aero_cache_cleared_notice' );
	}
	if ( function_exists( 'aero_submit_review_notice' ) ) {
		add_action( 'admin_notices', 'aero_submit_review_notice' );
	}
}

// ─── Optimization screen wrapper ──────────────────────────────────────────────

/**
 * Renders Aero's original settings inside the shell. The heavy lifting
 * stays in aero_admin_options() (aero.php); this wrapper exists so the
 * router has a stable callback.
 */
function aero_ui_render_optimization_screen() {
	if ( function_exists( 'aero_admin_options' ) ) {
		aero_admin_options();
	}
}
