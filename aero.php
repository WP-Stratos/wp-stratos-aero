<?php
/*
Plugin Name: Aero
Plugin URI: https://wpstratos.com
Description: Real performance optimization with Critical CSS, preloading, and Elementor support. 🚀
Version: 2.10.1
Author: WP Stratos
Author URI: https://wpstratos.com
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

if ( !defined ('AERO_PLUGIN_VERSION_NUM' ) ) {
    define( 'AERO_PLUGIN_VERSION_NUM', '2.10.1' );
}
if ( !defined ('AERO_MINIFY_LIBRARY_PATH' ) ) {
	define( 'AERO_MINIFY_LIBRARY_PATH', plugin_dir_path( __FILE__ ) . 'includes/min' );
}
if ( !defined ('AERO_CACHE_DIR' ) ) {
	define( 'AERO_CACHE_DIR',  WP_CONTENT_DIR . '/cache/aero/' );
}
if ( !defined ('AERO_CSS_CACHE_DIR' ) ) {
	define( 'AERO_CSS_CACHE_DIR', AERO_CACHE_DIR . 'css/' );
}
if ( !defined ('AERO_JS_CACHE_DIR' ) ) {
	define( 'AERO_JS_CACHE_DIR', AERO_CACHE_DIR . 'js/' );
}
if ( !defined ('AERO_BACKUP_DIR' ) ) {
	define( 'AERO_BACKUP_DIR', WP_CONTENT_DIR . '/aero-backups/' );
}

require_once( plugin_dir_path( __FILE__ ) . 'clear-minified-cache.php' );
require_once( plugin_dir_path( __FILE__ ) . 'includes/optimizer/fonts.php' );
require_once( plugin_dir_path( __FILE__ ) . 'includes/optimizer/bloat.php' );
require_once( plugin_dir_path( __FILE__ ) . 'includes/optimizer/delivery.php' );
require_once( plugin_dir_path( __FILE__ ) . 'rating-support.php' );

// Aero Cache Manager — full cache management suite (object cache, Batcache,
// Edge Cache, scheduled flushes, guest-mode isolation)
require_once( plugin_dir_path( __FILE__ ) . 'includes/cache-manager/loader.php' );

// Aero Image Optimizer — local WebP/AVIF conversion, compression, delivery
// and media replacement (engine derived from CompressX, GPL-3.0-or-later)
require_once( plugin_dir_path( __FILE__ ) . 'includes/image-optimizer/loader.php' );

require_once( AERO_MINIFY_LIBRARY_PATH . "/src/Minify.php" );
require_once( AERO_MINIFY_LIBRARY_PATH . "/src/CSS.php" );
require_once( AERO_MINIFY_LIBRARY_PATH . "/src/JS.php" );
require_once( AERO_MINIFY_LIBRARY_PATH . "/../path-converter/ConverterInterface.php" );
require_once( AERO_MINIFY_LIBRARY_PATH . '/../path-converter/Converter.php' );

if ( !file_exists( AERO_CACHE_DIR ) ) mkdir( AERO_CACHE_DIR, 0755, true );
if ( !file_exists( AERO_CSS_CACHE_DIR ) ) mkdir( AERO_CSS_CACHE_DIR, 0755, true );
if ( !file_exists( AERO_JS_CACHE_DIR ) ) mkdir( AERO_JS_CACHE_DIR, 0755, true );
if ( !file_exists( AERO_BACKUP_DIR ) ) mkdir( AERO_BACKUP_DIR, 0755, true );

add_action( 'admin_init', 'aero_add_stylesheet' );
function aero_add_stylesheet() {
	$css_file = plugin_dir_path( __FILE__ ) . 'assets/css/style.min.css';
	$version = file_exists( $css_file ) ? filemtime( $css_file ) : AERO_PLUGIN_VERSION_NUM;
	
    wp_register_style( 'aero-stylesheet', plugins_url('assets/css/style.min.css', __FILE__), array(), $version );
    wp_enqueue_style( 'aero-stylesheet' );
	
	do_action( 'aero_rating_system_action' );
}

// NOTE: third-party admin notices are suppressed on all Aero screens by
// aero_ui_hide_other_notices() in includes/cache-manager/admin-ui.php.

// Register AJAX handlers for diagnostics
add_action( 'wp_ajax_aero_get_diagnostics', 'aero_ajax_get_diagnostics' );
function aero_ajax_get_diagnostics() {
	check_ajax_referer( 'aero_diagnostics_nonce', 'nonce' );
	
	if ( !current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ) );
	}
	
	$hosting_info = aero_check_hosting_environment();
	$dropins = aero_check_dropins();
	$batcache = aero_check_batcache_config(); // ADD THIS LINE
	
	// Get cache statistics
	$css_cache_size = aero_get_directory_size(AERO_CSS_CACHE_DIR);
	$js_cache_size = aero_get_directory_size(AERO_JS_CACHE_DIR);
	$total_cache_size = $css_cache_size + $js_cache_size;
	
	$css_file_count = aero_count_files(AERO_CSS_CACHE_DIR);
	$js_file_count = aero_count_files(AERO_JS_CACHE_DIR);
	
	// Edge Cache + schedule state (cache-manager module)
	$edge = array(
		'available' => class_exists( 'Edge_Cache_Plugin' ),
		'enabled'   => ( get_option( 'edge-cache-enabled' ) === 'enabled' ),
	);
	$next     = ( defined( 'AERO_CM_CRON_HOOK' ) ) ? wp_next_scheduled( AERO_CM_CRON_HOOK ) : false;
	$schedule = array(
		'enabled' => ( get_option( 'aero_cm_schedule_enabled' ) === '1' ),
		'next'    => $next ? gmdate( 'j M Y, g:ia', $next ) . ' UTC' : '',
	);

	// Cache warmer state (for the Optimization statistics band)
	$warmer = array( 'available' => function_exists( 'aero_cw_opts' ) );
	if ( $warmer['available'] ) {
		$cw_stats           = get_option( 'aero_cw_stats', array() );
		$cw_queue           = get_option( 'aero_cw_queue', array() );
		$warmer['enabled']  = aero_cw_enabled();
		$warmer['running']  = (bool) get_option( 'aero_cw_running' );
		$warmer['total']    = is_array( $cw_queue ) ? count( $cw_queue ) : 0;
		$warmer['done']     = count( wp_list_filter( (array) $cw_queue, array( 'status' => 'done' ) ) );
		$warmer['failed']   = count( wp_list_filter( (array) $cw_queue, array( 'status' => 'failed' ) ) );
		$warmer['reason']   = isset( $cw_stats['reason'] ) ? sanitize_text_field( $cw_stats['reason'] ) : '';
		$warmer['started']  = isset( $cw_stats['started'] ) ? (int) $cw_stats['started'] : 0;
	}

	wp_send_json_success( array(
		'hosting'         => $hosting_info,
		'dropins'         => $dropins,
		'batcache'        => $batcache,
		'edge'            => $edge,
		'schedule'        => $schedule,
		'warmer'          => $warmer,
		'last_full_flush' => get_option( 'aero_cm_last_full_flush', '' ),
		'cache_stats'     => array(
			'css_files'      => $css_file_count,
			'js_files'       => $js_file_count,
			'css_size'       => aero_format_bytes( $css_cache_size ),
			'js_size'        => aero_format_bytes( $js_cache_size ),
			'css_size_bytes' => (int) $css_cache_size,
			'js_size_bytes'  => (int) $js_cache_size,
			'total_size'     => aero_format_bytes( $total_cache_size ),
		),
	) );
}

// Register AJAX handler for refreshing debug info
add_action( 'wp_ajax_aero_refresh_debug', 'aero_ajax_refresh_debug' );
function aero_ajax_refresh_debug() {
	check_ajax_referer( 'aero_refresh_debug_nonce', 'nonce' );
	
	if ( !current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ) );
	}
	
	// Check rate limit (30 minutes)
	$last_refresh = get_transient( 'aero_debug_last_refresh' );
	if ( $last_refresh !== false ) {
		$time_remaining = 30 * 60 - (time() - $last_refresh);
		wp_send_json_error( array( 
			'message' => 'Please wait before refreshing again',
			'time_remaining' => ceil($time_remaining / 60)
		) );
	}
	
	// Set rate limit
	set_transient( 'aero_debug_last_refresh', time(), 30 * 60 );
	
	// Generate fresh debug info (full report incl. cache-manager addendum)
	$fresh_debug_info = function_exists( 'aero_cm_full_debug_report' )
		? aero_cm_full_debug_report()
		: aero_generate_debug_info();
	
	wp_send_json_success( array(
		'debug_info' => $fresh_debug_info
	) );
}

// Register AJAX handler for auto-configuring Batcache
add_action( 'wp_ajax_aero_auto_configure_batcache', 'aero_ajax_auto_configure_batcache' );
function aero_ajax_auto_configure_batcache() {
	check_ajax_referer( 'aero_batcache_nonce', 'nonce' );
	
	if ( !current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ) );
	}
	
	if ( !aero_can_configure_batcache() ) {
		wp_send_json_error( array( 'message' => 'Requirements not met for Batcache configuration' ) );
	}
	
	$result = aero_add_batcache_config();
	
	if ( $result['success'] ) {
		wp_send_json_success( $result );
	} else {
		wp_send_json_error( $result );
	}
}

add_action( 'admin_head', 'aero_add_critical_css' );
function aero_add_critical_css() {
	if ( ! function_exists( 'aero_ui_is_aero_screen' ) || ! aero_ui_is_aero_screen() ) {
		return;
	}
	?>
	<style type="text/css">
	body.aero-admin-page { background: #000 !important; }
	body.aero-admin-page #wpbody-content { background: #000 !important; }
	body.aero-admin-page #wpbody { background: #000 !important; }
	body.aero-admin-page #wpcontent { background: #000 !important; }
	</style>
	<?php
}

// Inject custom CSS for normal mode
add_action( 'wp_head', 'aero_inject_custom_css', 999 );
function aero_inject_custom_css() {
	// Don't inject on admin or if guest mode is active
	if ( is_admin() || aero_is_guest_visitor() ) {
		return;
	}
	
	$custom_css = get_option( 'aero_custom_css_normal', '' );
	
	if ( !empty( trim( $custom_css ) ) ) {
		echo '<style id="aero-custom-css">' . "\n";
		echo wp_strip_all_tags( $custom_css );
		echo "\n" . '</style>' . "\n";
	}
}

// NOTE: Aero's menu is registered by includes/cache-manager/admin-ui.php —
// one top-level "Aero" menu with Optimization / Cache / Edge Cache /
// Purge & Schedule / Experimental submenus. aero_admin_options() below is
// rendered inside that shell as the Optimization screen.

function aero_settings_link( $links ) {
	$settings_link = '<a href="admin.php?page=aero">Settings</a>';
	array_unshift($links, $settings_link);
	return $links;
}
$plugin = plugin_basename( __FILE__ );
add_filter( "plugin_action_links_$plugin", 'aero_settings_link' );

function aero_plugin_meta_links( $links, $file ) {
	$plugin = plugin_basename( __FILE__ );
	if ( $file == $plugin ) {
		return array_merge(
			$links,
			array( '<a href="https://wpstratos.com" target="_blank">WP Stratos</a>' )
		);
	}
	return $links;
}
add_filter( 'plugin_row_meta', 'aero_plugin_meta_links', 10, 2 );

// ── Optimization screen save (admin_init: PRG-safe) ──────────────────────────
// NOTE: Guest Mode lives on the Experimental screen, Debug Mode on the Debug
// screen, and Batcache configuration on the Cache screen — this handler must
// never touch those options.
add_action( 'admin_init', 'aero_handle_optimization_save', 5 );
function aero_handle_optimization_save() {
	if ( ! isset( $_POST['aero_submit_hidden'] ) || 'Y' !== $_POST['aero_submit_hidden'] ) {
		return;
	}
	if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'aero_settings_nonce' ) ||
		 ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['aero_clear_minified'] ) ) {
		aero_clear_minified_cache();
		if ( function_exists( 'aero_ui_flash_add' ) ) {
			aero_ui_flash_add( __( 'Minified CSS/JS cache cleared.', 'aero' ), 'success' );
			aero_ui_redirect( 'aero' );
		}
		return;
	}

	$checkbox_options = array(
		'aero_combine_js', 'aero_combine_css', 'aero_compress_html', 'aero_defer_js',
		'aero_optimize_fonts', 'aero_preload_critical',
		// Font engine
		'aero_fonts_local_google', 'aero_fonts_inline_css', 'aero_fonts_preconnect',
		'aero_fonts_preload', 'aero_fonts_disable_google',
		// Delivery
		'aero_delay_js', 'aero_async_css', 'aero_preload_lcp',
	);
	foreach ( $checkbox_options as $opt ) {
		update_option( $opt, isset( $_POST[ $opt ] ) ? 'on' : 'off' );
	}
	update_option( 'aero_custom_css_normal', isset( $_POST['aero_custom_css_normal'] ) ? wp_strip_all_tags( wp_unslash( $_POST['aero_custom_css_normal'] ) ) : '' );

	// Delivery extras
	$timeout = isset( $_POST['aero_delay_js_timeout'] ) ? absint( $_POST['aero_delay_js_timeout'] ) : 0;
	update_option( 'aero_delay_js_timeout', in_array( $timeout, array( 0, 5, 10 ), true ) ? $timeout : 0 );
	update_option( 'aero_critical_css', isset( $_POST['aero_critical_css'] ) ? wp_strip_all_tags( wp_unslash( $_POST['aero_critical_css'] ) ) : '' );

	// Exclusion lists (one entry per line; sanitized as plain text)
	foreach ( array( 'aero_exclude_minify_css', 'aero_exclude_minify_js', 'aero_exclude_defer', 'aero_delay_js_excludes', 'aero_async_css_excludes' ) as $list ) {
		$raw   = isset( $_POST[ $list ] ) ? wp_unslash( $_POST[ $list ] ) : '';
		$lines = array_filter( array_map( 'sanitize_text_field', preg_split( '/[\r\n]+/', $raw ) ) );
		update_option( $list, implode( "\n", $lines ) );
	}

	// WordPress bloat panel
	$bloat = aero_bloat_defaults();
	foreach ( array( 'emojis', 'head_cleanup', 'embeds', 'jquery_migrate', 'dashicons', 'xmlrpc' ) as $key ) {
		$bloat[ $key ] = isset( $_POST['aero_bloat'][ $key ] ) ? 'on' : 'off';
	}
	$hb = isset( $_POST['aero_bloat']['heartbeat'] ) ? sanitize_key( $_POST['aero_bloat']['heartbeat'] ) : 'frontend';
	$bloat['heartbeat'] = in_array( $hb, array( 'default', 'frontend', 'disable' ), true ) ? $hb : 'frontend';
	$as = isset( $_POST['aero_bloat']['autosave'] ) ? absint( $_POST['aero_bloat']['autosave'] ) : 60;
	$bloat['autosave'] = (string) ( in_array( $as, array( 60, 120, 300 ), true ) ? $as : 60 );
	$rev = isset( $_POST['aero_bloat']['revisions'] ) ? trim( wp_unslash( $_POST['aero_bloat']['revisions'] ) ) : '';
	$bloat['revisions'] = ( '' === $rev ) ? '' : (string) absint( $rev );
	update_option( 'aero_bloat', $bloat );

	if ( function_exists( 'aero_ui_flash_add' ) ) {
		aero_ui_flash_add( __( 'Optimization settings saved.', 'aero' ), 'success' );
		aero_ui_redirect( 'aero' );
	}
}

function aero_admin_options() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	}

	$combine_js_val       = get_option( 'aero_combine_js' );
	$combine_css_val      = get_option( 'aero_combine_css' );
	$compress_html_val    = get_option( 'aero_compress_html' );
	$defer_js_val         = get_option( 'aero_defer_js' );
	$optimize_fonts_val   = get_option( 'aero_optimize_fonts' );
	$preload_critical_val = get_option( 'aero_preload_critical' );

	$opt_row = function( $name, $val, $title, $sub ) {
		?>
		<label class="aero-check-row aero-check-row-simple">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" <?php checked( $val, 'on' ); ?> />
			<span class="aero-check-main">
				<span class="aero-check-title"><?php echo esc_html( $title ); ?></span>
				<span class="aero-check-sub"><?php echo esc_html( $sub ); ?></span>
			</span>
		</label>
		<?php
	};

	$diag_nonce = wp_create_nonce( 'aero_diagnostics_nonce' );
	?>

	<!-- ═══ Quick Start Diagnostics ═══ -->
	<div class="aero-section">
		<div class="aero-diag-head">
			<div class="aero-eyebrow" style="margin-bottom:0;"><?php esc_html_e( 'Quick Start Diagnostics', 'aero' ); ?></div>
			<span class="aero-diag-score" id="aero-diag-score"><span class="aero-ui-spinner"></span></span>
		</div>
		<div class="aero-diag-list" id="aero-diag-list">
			<div class="aero-diag-row"><span class="aero-dot idle"></span><span class="aero-diag-label"><?php esc_html_e( 'Running checks…', 'aero' ); ?></span></div>
		</div>
		<div id="aero-upgrade-notice" style="display:none;">
			<div class="aero-info-box" style="margin-top:2px;">
				<span><strong><?php esc_html_e( 'Not on WP Stratos hosting?', 'aero' ); ?></strong> <?php esc_html_e( "You're missing out on significant performance improvements. WP Stratos provides optimized infrastructure with built-in caching, CDN, and performance features that work seamlessly with Aero.", 'aero' ); ?>
				<a href="https://wpstratos.com" target="_blank" style="color:#6898f8;"><?php esc_html_e( 'Learn About WP Stratos →', 'aero' ); ?></a></span>
			</div>
		</div>
	</div>

	<!-- ═══ Cache Statistics ═══ -->
	<div class="aero-section">
		<div class="aero-eyebrow"><?php esc_html_e( 'Cache Statistics', 'aero' ); ?></div>
		<div class="aero-stats-band" id="aero-stats-band">
			<div class="aero-donut-wrap">
				<svg class="aero-donut" viewBox="0 0 120 120" role="img" aria-label="<?php esc_attr_e( 'Cache composition', 'aero' ); ?>">
					<circle class="aero-donut-track" cx="60" cy="60" r="48"></circle>
					<circle class="aero-donut-seg aero-donut-css" id="aero-donut-css" cx="60" cy="60" r="48"></circle>
					<circle class="aero-donut-seg aero-donut-js" id="aero-donut-js" cx="60" cy="60" r="48"></circle>
					<text class="aero-donut-total" id="aero-donut-total" x="60" y="57" text-anchor="middle">…</text>
					<text class="aero-donut-caption" x="60" y="72" text-anchor="middle"><?php esc_html_e( 'CACHED', 'aero' ); ?></text>
				</svg>
				<div class="aero-donut-legend">
					<span class="aero-legend-item"><span class="aero-legend-swatch css"></span> CSS <em id="aero-legend-css">—</em></span>
					<span class="aero-legend-item"><span class="aero-legend-swatch js"></span> JS <em id="aero-legend-js">—</em></span>
				</div>
			</div>
			<div class="aero-stat-tiles">
				<div class="aero-stat-tile"><span class="aero-stat-val" id="aero-css-count">…</span><span class="aero-stat-label"><?php esc_html_e( 'CSS Files', 'aero' ); ?></span></div>
				<div class="aero-stat-tile"><span class="aero-stat-val" id="aero-js-count">…</span><span class="aero-stat-label"><?php esc_html_e( 'JS Files', 'aero' ); ?></span></div>
				<div class="aero-stat-tile"><span class="aero-stat-val" id="aero-total-size">…</span><span class="aero-stat-label"><?php esc_html_e( 'Total Size', 'aero' ); ?></span></div>
				<div class="aero-stat-tile"><span class="aero-stat-val" id="aero-bc-ttl">…</span><span class="aero-stat-label"><?php esc_html_e( 'Batcache TTL', 'aero' ); ?></span></div>
				<div class="aero-stat-tile aero-stat-tile-wide"><span class="aero-stat-val aero-stat-val-sm" id="aero-last-flush">…</span><span class="aero-stat-label"><?php esc_html_e( 'Last Full Flush', 'aero' ); ?></span></div>
			</div>
		</div>
		<div class="aero-warmth" id="aero-warmth" style="display:none;">
			<div class="aero-warmth-head">
				<span class="aero-dot idle" id="aero-warmth-dot"></span>
				<span class="aero-warmth-title"><?php esc_html_e( 'Cache Warmer', 'aero' ); ?></span>
				<span id="aero-warmth-state"></span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=aero-warmer' ) ); ?>"><?php esc_html_e( 'View queue →', 'aero' ); ?></a>
			</div>
			<div class="aero-warmth-bar">
				<div class="aero-warmth-seg done" id="aero-warmth-done"></div>
				<div class="aero-warmth-seg failed" id="aero-warmth-failed"></div>
				<div class="aero-warmth-seg pending" id="aero-warmth-pending"></div>
			</div>
			<div class="aero-warmth-meta" id="aero-warmth-meta"></div>
		</div>
	</div>

	<script>
	jQuery(document).ready(function($) {
		$.ajax({
			url: ajaxurl, type: 'POST',
			data: { action: 'aero_get_diagnostics', nonce: '<?php echo esc_js( $diag_nonce ); ?>' },
			success: function(response) {
				if (response.success) {
					aeroRenderDiagnostics(response.data);
					aeroRenderStats(response.data);
					aeroRenderWarmth(response.data.warmer);
				} else { aeroDiagError(); }
			},
			error: aeroDiagError
		});

		function aeroDiagError() {
			$('#aero-diag-list').html('<div class="aero-diag-row"><span class="aero-dot err"></span><span class="aero-diag-label"><?php echo esc_js( __( 'Failed to load diagnostics. Refresh the page to retry.', 'aero' ) ); ?></span></div>');
			$('#aero-diag-score').text('—');
			$('#aero-css-count, #aero-js-count, #aero-total-size, #aero-bc-ttl, #aero-last-flush, #aero-donut-total').text('—');
		}

		function aeroRenderDiagnostics(data) {
			var checks = [
				{ label: 'WP Stratos Hosting',               ok: data.hosting.is_wpstratos,  hint: data.hosting.is_wpstratos ? 'Platform detected' : 'Not detected' },
				{ label: 'Object Cache (object-cache.php)',  ok: data.dropins.object_cache,  hint: data.dropins.object_cache ? 'Drop-in present' : 'Drop-in missing' },
				{ label: 'Page Cache (advanced-cache.php)',  ok: data.dropins.advanced_cache, hint: data.dropins.advanced_cache ? 'Drop-in present' : 'Drop-in missing' },
				{ label: 'Batcache Configuration',           ok: data.batcache.exists,       hint: data.batcache.exists ? ('Max Age ' + data.batcache.values.max_age + 's · Times ' + data.batcache.values.times) : 'Not configured — see the Cache screen' },
				{ label: 'Edge Cache',                       ok: data.edge.enabled,          hint: data.edge.available ? (data.edge.enabled ? 'Enabled' : 'Available but disabled — see the Edge Cache screen') : 'Edge Cache plugin not present', warn: data.edge.available && !data.edge.enabled },
				{ label: 'Scheduled Cache Clearing',         ok: data.schedule.enabled,      hint: data.schedule.enabled ? ('Next run ' + data.schedule.next) : 'Disabled — optional, see Purge & Schedule', warn: !data.schedule.enabled, optional: true }
			];
			var html = '', passed = 0, scored = 0;
			checks.forEach(function(c) {
				if (!c.optional) { scored++; if (c.ok) passed++; }
				var dot = c.ok ? 'ok' : (c.warn ? 'warn' : 'err');
				html += '<div class="aero-diag-row">'
					 +  '<span class="aero-dot ' + dot + '"></span>'
					 +  '<span class="aero-diag-label">' + c.label + '</span>'
					 +  '<span class="aero-diag-hint">' + c.hint + '</span>'
					 +  '</div>';
			});
			$('#aero-diag-list').html(html);
			$('#aero-diag-score').html(passed + '/' + scored + ' <span>PASSING</span>');
			$('#aero-diag-score').addClass(passed === scored ? 'all-pass' : 'has-fail');
			if (!data.hosting.is_wpstratos) { $('#aero-upgrade-notice').show(); }
		}

		function aeroRenderStats(data) {
			var st = data.cache_stats;
			$('#aero-css-count').text(st.css_files);
			$('#aero-js-count').text(st.js_files);
			$('#aero-total-size').text(st.total_size);
			$('#aero-bc-ttl').text(data.batcache.exists && data.batcache.values.max_age ? aeroHuman(data.batcache.values.max_age) : '—');
			$('#aero-last-flush').text(data.last_full_flush || 'Never');
			$('#aero-donut-total').text(st.total_size);
			$('#aero-legend-css').text(st.css_size);
			$('#aero-legend-js').text(st.js_size);

			// Donut: CSS + JS share of total bytes
			var total = st.css_size_bytes + st.js_size_bytes;
			var C = 2 * Math.PI * 48; // circumference
			var cssFrac = total > 0 ? st.css_size_bytes / total : 0;
			var jsFrac  = total > 0 ? st.js_size_bytes  / total : 0;
			var gap = total > 0 && cssFrac > 0 && jsFrac > 0 ? 0.005 : 0;
			var cssEl = document.getElementById('aero-donut-css');
			var jsEl  = document.getElementById('aero-donut-js');
			cssEl.style.strokeDasharray = (Math.max(cssFrac - gap, 0) * C) + ' ' + C;
			cssEl.style.strokeDashoffset = 0;
			jsEl.style.strokeDasharray  = (Math.max(jsFrac - gap, 0) * C) + ' ' + C;
			jsEl.style.strokeDashoffset  = -(cssFrac * C);
		}

		function aeroRenderWarmth(w) {
			if (!w || !w.available) { return; }
			var el = document.getElementById('aero-warmth');
			el.style.display = '';

			var dot = document.getElementById('aero-warmth-dot');
			var state = document.getElementById('aero-warmth-state');
			var meta = document.getElementById('aero-warmth-meta');

			if (!w.enabled) {
				dot.className = 'aero-dot idle';
				state.textContent = '<?php echo esc_js( __( 'Disabled', 'aero' ) ); ?>';
				meta.innerHTML = '<?php echo esc_js( __( 'Enable it to rebuild the cache automatically after every flush.', 'aero' ) ); ?>';
				return;
			}

			var total = w.total || 0;
			var done = w.done || 0, failed = w.failed || 0;
			var pending = Math.max(0, total - done - failed);

			if (w.running) {
				dot.className = 'aero-dot warn pulse';
				state.textContent = total > 0 ? '<?php echo esc_js( __( 'Warming now…', 'aero' ) ); ?>' : '<?php echo esc_js( __( 'Collecting URLs…', 'aero' ) ); ?>';
			} else if (total > 0) {
				dot.className = 'aero-dot ' + (failed > 0 ? 'warn' : 'ok');
				state.textContent = '<?php echo esc_js( __( 'Last run complete', 'aero' ) ); ?>';
			} else {
				dot.className = 'aero-dot idle';
				state.textContent = '<?php echo esc_js( __( 'Idle — no run yet', 'aero' ) ); ?>';
			}

			var pct = function(n) { return total > 0 ? (n / total * 100) + '%' : '0%'; };
			document.getElementById('aero-warmth-done').style.width = pct(done);
			document.getElementById('aero-warmth-failed').style.width = pct(failed);
			document.getElementById('aero-warmth-pending').style.width = pct(pending);

			if (total > 0) {
				var bits = ['<em>' + done + '/' + total + '</em> <?php echo esc_js( __( 'warmed', 'aero' ) ); ?>'];
				if (failed) { bits.push('<em>' + failed + '</em> <?php echo esc_js( __( 'failed', 'aero' ) ); ?>'); }
				if (pending) { bits.push('<em>' + pending + '</em> <?php echo esc_js( __( 'pending', 'aero' ) ); ?>'); }
				if (w.started) { bits.push(new Date(w.started * 1000).toUTCString().replace(':00 GMT', ' UTC') + (w.reason ? ' · ' + w.reason : '')); }
				meta.innerHTML = bits.join('<span style="opacity:.4"> · </span>');
			} else {
				meta.innerHTML = '';
			}
		}

		function aeroHuman(s) {
			s = parseInt(s);
			if (s % 86400 === 0) return (s / 86400) + (s === 86400 ? ' day' : ' days');
			if (s % 3600 === 0) return (s / 3600) + ' hr';
			if (s % 60 === 0) return (s / 60) + ' min';
			return s + ' sec';
		}
	});
	</script>

	<!-- ═══ Settings form ═══ -->
	<form method="post" name="options_form">
		<?php wp_nonce_field( 'aero_settings_nonce' ); ?>
		<input type="hidden" name="aero_submit_hidden" value="Y">

		<div class="aero-acc" data-open="1">
			<button type="button" class="aero-acc-head">
				<span class="aero-acc-title"><?php esc_html_e( 'Core Optimizations', 'aero' ); ?></span>
				<span class="aero-acc-aside"><?php esc_html_e( 'recommended — enable all', 'aero' ); ?></span>
				<svg class="aero-acc-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
			</button>
			<div class="aero-acc-body"><div class="aero-acc-inner">
			<div class="aero-check-list">
				<?php
				$opt_row( 'aero_combine_css', $combine_css_val, __( 'Minify & Cache CSS', 'aero' ), __( 'Minifies every stylesheet and serves it from Aero\'s local cache.', 'aero' ) );
				$opt_row( 'aero_combine_js', $combine_js_val, __( 'Minify & Cache JavaScript', 'aero' ), __( 'Minifies every script and serves it from Aero\'s local cache.', 'aero' ) );
				$opt_row( 'aero_compress_html', $compress_html_val, __( 'Compress HTML', 'aero' ), __( 'Strips whitespace and comments from the final HTML output.', 'aero' ) );
				$opt_row( 'aero_defer_js', $defer_js_val, __( 'Defer JavaScript', 'aero' ), __( 'Defers non-critical JS. jQuery is excluded automatically; add more in Exclusions below.', 'aero' ) );
				?>
			</div>
		</div></div></div>

		<div class="aero-acc" data-open="1">
			<button type="button" class="aero-acc-head">
				<span class="aero-acc-title"><?php esc_html_e( 'Advanced Performance', 'aero' ); ?></span>
				<svg class="aero-acc-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
			</button>
			<div class="aero-acc-body"><div class="aero-acc-inner">
			<div class="aero-check-list">
				<?php
				$opt_row( 'aero_preload_critical', $preload_critical_val, __( 'Preload Critical Resources', 'aero' ), __( 'Preloads the first critical stylesheets so above-the-fold rendering starts sooner.', 'aero' ) );
				?>
			</div>
		</div></div></div>

		<!-- ═══ Delivery Optimization ═══ -->
		<div class="aero-acc" data-open="1">
			<button type="button" class="aero-acc-head">
				<span class="aero-acc-title"><?php esc_html_e( 'Delivery Optimization', 'aero' ); ?></span>
				<span class="aero-acc-aside"><?php esc_html_e( 'where PageSpeed scores are won', 'aero' ); ?></span>
				<svg class="aero-acc-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
			</button>
			<div class="aero-acc-body"><div class="aero-acc-inner">

			<div class="aero-check-list">
				<label class="aero-check-row aero-check-row-simple">
					<input type="checkbox" name="aero_preload_lcp" <?php checked( get_option( 'aero_preload_lcp', 'on' ), 'on' ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Preload the LCP Image', 'aero' ); ?> <span class="aero-tag ok"><?php esc_html_e( 'Safe', 'aero' ); ?></span></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Detects the likely Largest-Contentful-Paint image, preloads it with fetchpriority="high", and removes lazy-loading from it. Directly answers PSI\'s "LCP image was lazily loaded / discovered late".', 'aero' ); ?></span>
					</span>
				</label>
				<label class="aero-check-row aero-check-row-simple">
					<input type="checkbox" name="aero_delay_js" <?php checked( get_option( 'aero_delay_js', 'off' ), 'on' ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Delay JavaScript Until Interaction', 'aero' ); ?> <span class="aero-tag warn"><?php esc_html_e( 'High impact — test', 'aero' ); ?></span></span>
						<span class="aero-check-sub"><?php esc_html_e( 'No JS loads until the visitor moves, scrolls, taps or types — then everything restores in order. Lab tools never interact, so Total Blocking Time collapses (often 1–3s). Test menus and sliders after enabling; exclude anything needed instantly below.', 'aero' ); ?></span>
					</span>
				</label>
				<label class="aero-check-row aero-check-row-simple">
					<input type="checkbox" name="aero_async_css" <?php checked( get_option( 'aero_async_css', 'off' ), 'on' ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Load CSS Asynchronously', 'aero' ); ?> <span class="aero-tag warn"><?php esc_html_e( 'Pair with Critical CSS', 'aero' ); ?></span></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Stylesheets stop blocking the first paint (media="print" swap with a no-JS fallback). Without Critical CSS below, the page may flash unstyled for a moment — add your above-the-fold rules there to prevent it.', 'aero' ); ?></span>
					</span>
				</label>
			</div>

			<div class="aero-field-grid aero-field-grid-3">
				<div class="aero-field">
					<label class="aero-label" for="aero_delay_js_timeout"><?php esc_html_e( 'Delay Fallback Timeout', 'aero' ); ?></label>
					<select id="aero_delay_js_timeout" class="aero-input" name="aero_delay_js_timeout">
						<option value="0" <?php selected( (int) get_option( 'aero_delay_js_timeout', 0 ), 0 ); ?>><?php esc_html_e( 'None — wait for interaction (best scores)', 'aero' ); ?></option>
						<option value="5" <?php selected( (int) get_option( 'aero_delay_js_timeout', 0 ), 5 ); ?>><?php esc_html_e( '5 seconds', 'aero' ); ?></option>
						<option value="10" <?php selected( (int) get_option( 'aero_delay_js_timeout', 0 ), 10 ); ?>><?php esc_html_e( '10 seconds', 'aero' ); ?></option>
					</select>
					<p class="aero-hint"><?php esc_html_e( 'A timeout loads JS even without interaction — useful so analytics still count passive visitors, at a small score cost.', 'aero' ); ?></p>
				</div>
				<div class="aero-field">
					<label class="aero-label" for="aero_delay_js_excludes"><?php esc_html_e( 'Exclude from Delay', 'aero' ); ?></label>
					<textarea id="aero_delay_js_excludes" name="aero_delay_js_excludes" class="aero-input aero-code-textarea" rows="4" placeholder="cookie-banner&#10;instant-search"><?php echo esc_textarea( get_option( 'aero_delay_js_excludes', '' ) ); ?></textarea>
					<p class="aero-hint"><?php esc_html_e( 'One per line — matched against script URLs AND inline code. Scripts can also opt out with a data-aero-nodelay attribute.', 'aero' ); ?></p>
				</div>
				<div class="aero-field">
					<label class="aero-label" for="aero_async_css_excludes"><?php esc_html_e( 'Exclude from Async CSS', 'aero' ); ?></label>
					<textarea id="aero_async_css_excludes" name="aero_async_css_excludes" class="aero-input aero-code-textarea" rows="4" placeholder="above-fold.css"><?php echo esc_textarea( get_option( 'aero_async_css_excludes', '' ) ); ?></textarea>
					<p class="aero-hint"><?php esc_html_e( 'Excluded stylesheets stay render-blocking. Links can also opt out with data-aero-noasync.', 'aero' ); ?></p>
				</div>
			</div>

			<div class="aero-field">
				<label class="aero-label" for="aero_critical_css"><?php esc_html_e( 'Critical CSS', 'aero' ); ?></label>
				<textarea id="aero_critical_css" name="aero_critical_css" class="aero-input aero-code-textarea" rows="6" placeholder="/* Above-the-fold rules: header, hero, first-view typography */"><?php echo esc_textarea( get_option( 'aero_critical_css', '' ) ); ?></textarea>
				<p class="aero-hint"><?php esc_html_e( 'Inlined into <head> when Async CSS is on, so the first paint is styled before any stylesheet arrives. Generate with a critical-CSS tool, or paste your header/hero rules.', 'aero' ); ?></p>
			</div>

			</div></div>
		</div>

		<!-- ═══ Font Optimization ═══ -->
		<div class="aero-acc" data-open="0">
			<button type="button" class="aero-acc-head">
				<span class="aero-acc-title"><?php esc_html_e( 'Font Optimization', 'aero' ); ?></span>
				<span class="aero-acc-aside"><?php esc_html_e( 'kills the web-font waterfall', 'aero' ); ?></span>
				<svg class="aero-acc-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
			</button>
			<div class="aero-acc-body"><div class="aero-acc-inner">
			<p class="aero-hint" style="margin:0 0 14px;max-width:680px;">
				<?php esc_html_e( 'Remote fonts force two serial cross-origin round trips before any text renders — the single biggest PageSpeed penalty on most sites. Aero downloads Google Fonts once, serves them same-origin, inlines the CSS, and preloads the primary faces.', 'aero' ); ?>
			</p>

			<?php
			$fstats = function_exists( 'aero_fonts_cache_stats' ) ? aero_fonts_cache_stats() : array( 'sheets' => 0, 'files' => 0, 'bytes' => 0 );
			?>
			<div class="aero-status-row">
				<span class="aero-dot <?php echo $fstats['sheets'] > 0 ? 'ok' : 'idle'; ?>"></span>
				<span class="aero-status-strong"><?php esc_html_e( 'Local font cache', 'aero' ); ?></span>
				<span>
					<?php
					if ( $fstats['sheets'] > 0 ) {
						printf(
							/* translators: 1: stylesheets, 2: files, 3: size */
							esc_html__( '%1$d stylesheet(s) localized · %2$d font file(s) · %3$s', 'aero' ),
							(int) $fstats['sheets'],
							(int) $fstats['files'],
							esc_html( aero_format_bytes( $fstats['bytes'] ) )
						);
					} else {
						esc_html_e( 'Empty — populated automatically on the first frontend visit after enabling.', 'aero' );
					}
					?>
				</span>
				<span class="aero-status-meta"><?php esc_html_e( 'Cleared with "Clear Minified Cache"', 'aero' ); ?></span>
			</div>

			<?php
			$fdet    = function_exists( 'aero_fonts_get_detection' ) ? aero_fonts_get_detection() : null;
			$g_mode  = $fdet ? $fdet['google']['mode'] : 'unknown';
			$tk_mode = $fdet ? $fdet['typekit']['mode'] : 'unknown';
			$f_seen  = $fdet ? max( (int) $fdet['google']['seen'], (int) $fdet['typekit']['seen'] ) : 0;
			?>
			<div class="aero-status-row">
				<span class="aero-dot <?php echo ( 'unknown' === $g_mode ) ? 'idle' : ( 'remote' === $g_mode ? 'warn' : 'ok' ); ?>"></span>
				<span class="aero-status-strong"><?php esc_html_e( 'Detected on frontend', 'aero' ); ?></span>
				<span>
					<?php if ( 'unknown' === $g_mode ) : ?>
						<?php esc_html_e( 'No data yet — recorded on the next frontend visit.', 'aero' ); ?>
					<?php else : ?>
						<?php if ( 'localized' === $g_mode ) : ?>
							<span class="aero-tag ok"><?php esc_html_e( 'Google Fonts — Localized', 'aero' ); ?></span>
						<?php elseif ( 'remote' === $g_mode ) : ?>
							<span class="aero-tag warn"><?php esc_html_e( 'Google Fonts — Remote', 'aero' ); ?></span>
						<?php else : ?>
							<span class="aero-tag"><?php esc_html_e( 'Google Fonts — Not used', 'aero' ); ?></span>
						<?php endif; ?>
						<?php if ( 'detected' === $tk_mode ) : ?>
							<span class="aero-tag ok"><?php esc_html_e( 'Adobe TypeKit — Preconnect applied', 'aero' ); ?></span>
						<?php else : ?>
							<span class="aero-tag"><?php esc_html_e( 'Adobe TypeKit — Not used', 'aero' ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
				</span>
				<?php if ( $f_seen ) : ?>
					<span class="aero-status-meta"><?php esc_html_e( 'Last check:', 'aero' ); ?> <?php echo esc_html( gmdate( 'j M Y, g:ia', $f_seen ) ); ?> UTC</span>
				<?php endif; ?>
			</div>
			<p class="aero-hint" style="margin:-12px 0 14px;"><?php esc_html_e( 'Preconnect and preload hints are emitted per page, only for providers actually present on that page — a preconnect to an origin the page never contacts wastes a connection slot and slows the real critical path.', 'aero' ); ?></p>

			<div class="aero-check-list">
				<?php
				$opt_row( 'aero_optimize_fonts', get_option( 'aero_optimize_fonts', 'on' ), __( 'Optimize Font Loading', 'aero' ), __( 'Master switch for the font engine. Enforces font-display: swap everywhere so text never blocks on a font download.', 'aero' ) );
				$opt_row( 'aero_fonts_local_google', get_option( 'aero_fonts_local_google', 'on' ), __( 'Self-Host Google Fonts', 'aero' ), __( 'Downloads Google Fonts into Aero\'s cache and serves them from your own domain — both external origins disappear from the critical path. Falls back to remote automatically if a download fails.', 'aero' ) );
				$opt_row( 'aero_fonts_inline_css', get_option( 'aero_fonts_inline_css', 'on' ), __( 'Inline Font CSS', 'aero' ), __( 'Embeds the localized @font-face rules directly in <head>, removing the render-blocking stylesheet request entirely.', 'aero' ) );
				$opt_row( 'aero_fonts_preconnect', get_option( 'aero_fonts_preconnect', 'on' ), __( 'Preconnect Hints', 'aero' ), __( 'Warms connections to any font origin that remains remote (fonts.gstatic.com, Adobe Fonts) so the download starts 100–500ms sooner.', 'aero' ) );
				$opt_row( 'aero_fonts_preload', get_option( 'aero_fonts_preload', 'on' ), __( 'Preload Primary Fonts', 'aero' ), __( 'Preloads up to two latin woff2 faces so text fonts download in parallel with CSS instead of after it.', 'aero' ) );
				?>
			</div>

			<div class="aero-check-list" style="margin-top:2px;">
				<label class="aero-check-row aero-check-row-simple aero-check-row-danger">
					<input type="checkbox" name="aero_fonts_disable_google" <?php checked( get_option( 'aero_fonts_disable_google', 'off' ), 'on' ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Disable Google Fonts Entirely', 'aero' ); ?> <span class="aero-tag warn"><?php esc_html_e( 'Changes appearance', 'aero' ); ?></span></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Strips every Google Fonts request — the browser falls back to system fonts. Only for sites deliberately using a system font stack. Overrides all options above.', 'aero' ); ?></span>
					</span>
				</label>
			</div>
			<p class="aero-hint"><?php esc_html_e( 'Adobe Fonts (TypeKit) note: kit CSS is licensed and dynamically subset, so it cannot be self-hosted — Aero applies preconnect and preload to it instead.', 'aero' ); ?></p>
		</div></div>
		</div>

		<!-- ═══ Exclusions ═══ -->
		<div class="aero-acc" data-open="0">
			<button type="button" class="aero-acc-head">
				<span class="aero-acc-title"><?php esc_html_e( 'Exclusions', 'aero' ); ?></span>
				<span class="aero-acc-aside"><?php esc_html_e( 'compatibility escape hatches', 'aero' ); ?></span>
				<svg class="aero-acc-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
			</button>
			<div class="aero-acc-body"><div class="aero-acc-inner">
			<p class="aero-hint" style="margin:0 0 14px;max-width:680px;">
				<?php esc_html_e( 'If a specific file misbehaves when optimized, exclude it here instead of turning the whole feature off. One entry per line — each is matched as a case-insensitive fragment of the file URL (e.g. "slider-pro", "themes/mytheme/app.js").', 'aero' ); ?>
			</p>
			<div class="aero-field-grid aero-field-grid-3">
				<div class="aero-field">
					<label class="aero-label" for="aero_exclude_minify_css"><?php esc_html_e( 'Exclude from CSS Minification', 'aero' ); ?></label>
					<textarea id="aero_exclude_minify_css" name="aero_exclude_minify_css" class="aero-input aero-code-textarea" rows="4" placeholder="fancy-slider&#10;/plugins/broken-plugin/"><?php echo esc_textarea( get_option( 'aero_exclude_minify_css', '' ) ); ?></textarea>
				</div>
				<div class="aero-field">
					<label class="aero-label" for="aero_exclude_minify_js"><?php esc_html_e( 'Exclude from JS Minification', 'aero' ); ?></label>
					<textarea id="aero_exclude_minify_js" name="aero_exclude_minify_js" class="aero-input aero-code-textarea" rows="4" placeholder="maps-widget.js"><?php echo esc_textarea( get_option( 'aero_exclude_minify_js', '' ) ); ?></textarea>
				</div>
				<div class="aero-field">
					<label class="aero-label" for="aero_exclude_defer"><?php esc_html_e( 'Exclude from JS Defer', 'aero' ); ?></label>
					<textarea id="aero_exclude_defer" name="aero_exclude_defer" class="aero-input aero-code-textarea" rows="4" placeholder="inline-critical.js"><?php echo esc_textarea( get_option( 'aero_exclude_defer', '' ) ); ?></textarea>
					<p class="aero-hint"><?php esc_html_e( 'jQuery is always excluded automatically.', 'aero' ); ?></p>
				</div>
			</div>
		</div></div>
		</div>

		<!-- ═══ WordPress Bloat ═══ -->
		<?php $bloat = aero_bloat_opts(); ?>
		<div class="aero-acc" data-open="0">
			<button type="button" class="aero-acc-head">
				<span class="aero-acc-title"><?php esc_html_e( 'WordPress Bloat', 'aero' ); ?></span>
				<span class="aero-acc-aside"><?php esc_html_e( 'trim what core loads on every page', 'aero' ); ?></span>
				<svg class="aero-acc-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
			</button>
			<div class="aero-acc-body"><div class="aero-acc-inner">

			<div class="aero-check-list">
				<label class="aero-check-row aero-check-row-simple">
					<input type="checkbox" name="aero_bloat[emojis]" <?php checked( $bloat['emojis'], 'on' ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Remove Emoji Script', 'aero' ); ?> <span class="aero-tag ok"><?php esc_html_e( 'Safe', 'aero' ); ?></span></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Drops ~15KB of emoji-detection JS/CSS loaded on every page. Emojis still render — every modern browser supports them natively.', 'aero' ); ?></span>
					</span>
				</label>
				<label class="aero-check-row aero-check-row-simple">
					<input type="checkbox" name="aero_bloat[head_cleanup]" <?php checked( $bloat['head_cleanup'], 'on' ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Clean Up <head>', 'aero' ); ?> <span class="aero-tag ok"><?php esc_html_e( 'Safe', 'aero' ); ?></span></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Removes the RSD link, Windows Live Writer manifest, shortlink tag, and the WordPress version meta (a security win too).', 'aero' ); ?></span>
					</span>
				</label>
			</div>

			<div class="aero-check-list" style="margin-top:2px;">
				<label class="aero-check-row aero-check-row-simple">
					<input type="checkbox" name="aero_bloat[embeds]" <?php checked( $bloat['embeds'], 'on' ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Disable Embeds', 'aero' ); ?> <span class="aero-tag"><?php esc_html_e( 'Review', 'aero' ); ?></span></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Removes wp-embed.js and oEmbed discovery links. Keep OFF if other sites embed your posts as rich cards.', 'aero' ); ?></span>
					</span>
				</label>
				<label class="aero-check-row aero-check-row-simple">
					<input type="checkbox" name="aero_bloat[jquery_migrate]" <?php checked( $bloat['jquery_migrate'], 'on' ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Remove jQuery Migrate', 'aero' ); ?> <span class="aero-tag"><?php esc_html_e( 'Review', 'aero' ); ?></span></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Frontend only. Safe on modern themes; needed only by plugins written for pre-2016 jQuery. Test after enabling.', 'aero' ); ?></span>
					</span>
				</label>
				<label class="aero-check-row aero-check-row-simple">
					<input type="checkbox" name="aero_bloat[dashicons]" <?php checked( $bloat['dashicons'], 'on' ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Remove Dashicons for Visitors', 'aero' ); ?> <span class="aero-tag"><?php esc_html_e( 'Review', 'aero' ); ?></span></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Dequeues the ~46KB admin icon font for logged-out visitors. Keep OFF if your theme shows Dashicons on the frontend.', 'aero' ); ?></span>
					</span>
				</label>
				<label class="aero-check-row aero-check-row-simple">
					<input type="checkbox" name="aero_bloat[xmlrpc]" <?php checked( $bloat['xmlrpc'], 'on' ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Disable XML-RPC', 'aero' ); ?> <span class="aero-tag"><?php esc_html_e( 'Review', 'aero' ); ?></span></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Blocks a common brute-force target and removes the X-Pingback header. Keep OFF if you use Jetpack or the WordPress mobile apps.', 'aero' ); ?></span>
					</span>
				</label>
			</div>

			<div class="aero-field-grid aero-field-grid-3" style="margin-top:18px;">
				<div class="aero-field">
					<label class="aero-label" for="aero_bloat_heartbeat"><?php esc_html_e( 'Heartbeat API', 'aero' ); ?></label>
					<select id="aero_bloat_heartbeat" class="aero-input" name="aero_bloat[heartbeat]">
						<option value="default" <?php selected( $bloat['heartbeat'], 'default' ); ?>><?php esc_html_e( 'WordPress default', 'aero' ); ?></option>
						<option value="frontend" <?php selected( $bloat['heartbeat'], 'frontend' ); ?>><?php esc_html_e( 'Disable on frontend (recommended)', 'aero' ); ?></option>
						<option value="disable" <?php selected( $bloat['heartbeat'], 'disable' ); ?>><?php esc_html_e( 'Disable everywhere', 'aero' ); ?></option>
					</select>
					<p class="aero-hint"><?php esc_html_e( 'Frontend heartbeat polling is almost never needed. "Everywhere" also stops post-lock warnings in the editor.', 'aero' ); ?></p>
				</div>
				<div class="aero-field">
					<label class="aero-label" for="aero_bloat_autosave"><?php esc_html_e( 'Autosave Interval', 'aero' ); ?></label>
					<select id="aero_bloat_autosave" class="aero-input" name="aero_bloat[autosave]">
						<option value="60" <?php selected( $bloat['autosave'], '60' ); ?>><?php esc_html_e( '1 minute (default)', 'aero' ); ?></option>
						<option value="120" <?php selected( $bloat['autosave'], '120' ); ?>><?php esc_html_e( '2 minutes', 'aero' ); ?></option>
						<option value="300" <?php selected( $bloat['autosave'], '300' ); ?>><?php esc_html_e( '5 minutes', 'aero' ); ?></option>
					</select>
					<p class="aero-hint"><?php esc_html_e( 'Fewer editor autosave requests = less admin-side load.', 'aero' ); ?></p>
				</div>
				<div class="aero-field">
					<label class="aero-label" for="aero_bloat_revisions"><?php esc_html_e( 'Post Revisions Limit', 'aero' ); ?></label>
					<input type="number" min="0" id="aero_bloat_revisions" class="aero-input" name="aero_bloat[revisions]" value="<?php echo esc_attr( $bloat['revisions'] ); ?>" placeholder="<?php esc_attr_e( 'Unlimited', 'aero' ); ?>" />
					<p class="aero-hint"><?php esc_html_e( 'Keeps the posts table lean. Empty = WordPress default (unlimited). 5–10 is plenty for most sites.', 'aero' ); ?></p>
				</div>
			</div>
		</div></div>
		</div>

		<div class="aero-acc" data-open="0">
			<button type="button" class="aero-acc-head">
				<span class="aero-acc-title"><?php esc_html_e( 'Custom CSS', 'aero' ); ?></span>
				<svg class="aero-acc-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
			</button>
			<div class="aero-acc-body"><div class="aero-acc-inner">
			<div class="aero-field">
				<label class="aero-label" for="aero_custom_css_normal"><?php esc_html_e( 'Site-wide Custom CSS', 'aero' ); ?></label>
				<textarea name="aero_custom_css_normal" id="aero_custom_css_normal" class="aero-input aero-code-textarea" rows="7"
					placeholder="/* Example: Hide elements that break layout */&#10;.problematic-element {&#10;    display: none !important;&#10;}"><?php echo esc_textarea( get_option( 'aero_custom_css_normal', '' ) ); ?></textarea>
				<p class="aero-hint"><?php esc_html_e( 'Injected on the frontend for all regular visitors. Guest-Mode-only CSS lives on the Experimental screen.', 'aero' ); ?></p>
			</div>
			</div></div>
		</div>

		<script>
		document.querySelectorAll('.aero-acc-head').forEach(function(head) {
			head.addEventListener('click', function() {
				var acc = head.closest('.aero-acc');
				acc.setAttribute('data-open', acc.getAttribute('data-open') === '1' ? '0' : '1');
			});
		});
		</script>

		<div class="aero-actions">
			<button type="submit" name="submit" class="aero-btn aero-btn-primary"><?php esc_html_e( 'Save Changes', 'aero' ); ?></button>
			<button type="submit" name="aero_clear_minified" class="aero-btn aero-btn-ghost"><?php esc_html_e( 'Clear Minified Cache', 'aero' ); ?></button>
		</div>
	</form>
	<?php
}

/**
 * Generate comprehensive debug information for support
 */
function aero_generate_debug_info() {
	global $wp_version;
	
	$debug_info = "=== AERO DEBUG INFORMATION ===\n";
	$debug_info .= "Generated: " . current_time('Y-m-d H:i:s') . "\n\n";
	
	// Plugin Info
	$debug_info .= "--- PLUGIN INFO ---\n";
	$debug_info .= "Aero Version: " . AERO_PLUGIN_VERSION_NUM . "\n";
	$debug_info .= "Plugin Path: " . plugin_dir_path( __FILE__ ) . "\n\n";
	
	// WordPress Environment
	$debug_info .= "--- WORDPRESS ENVIRONMENT ---\n";
	$debug_info .= "WordPress Version: " . $wp_version . "\n";
	$debug_info .= "Site URL: " . site_url() . "\n";
	$debug_info .= "Home URL: " . home_url() . "\n";
	$debug_info .= "Is Multisite: " . (is_multisite() ? 'Yes' : 'No') . "\n";
	$debug_info .= "Active Theme: " . wp_get_theme()->get('Name') . " v" . wp_get_theme()->get('Version') . "\n\n";
	
	// Hosting Environment
	$hosting_info = aero_check_hosting_environment();
	$debug_info .= "--- HOSTING ENVIRONMENT ---\n";
	$debug_info .= "Hosting Provider: " . ($hosting_info['is_wpstratos'] ? 'WP Stratos' : 'Other') . "\n";
	if ($hosting_info['is_wpstratos']) {
		$debug_info .= "Platform Header: " . ($hosting_info['platform_header'] ? 'Present' : 'Not Found') . "\n";
		$debug_info .= "Powered By Header: " . ($hosting_info['powered_by_header'] ? 'Present' : 'Not Found') . "\n";
	}
	$debug_info .= "\n";
	
	// Drop-ins
	$dropins = aero_check_dropins();
	$debug_info .= "--- DROP-INS ---\n";
	$debug_info .= "advanced-cache.php: " . ($dropins['advanced_cache'] ? 'Present' : 'Not Found') . "\n";
	$debug_info .= "object-cache.php: " . ($dropins['object_cache'] ? 'Present' : 'Not Found') . "\n\n";
	
	// Page Builder & Editor
	$page_builder = aero_detect_page_builder();
	$using_gutenberg = aero_is_using_gutenberg();
	$debug_info .= "--- PAGE BUILDER & EDITOR ---\n";
	$debug_info .= "Active Page Builder: " . ($page_builder ? $page_builder : 'None Detected') . "\n";
	$debug_info .= "Using Gutenberg: " . ($using_gutenberg ? 'Yes' : 'No') . "\n\n";
	
	// Server Info
	$debug_info .= "--- SERVER ENVIRONMENT ---\n";
	$debug_info .= "PHP Version: " . phpversion() . "\n";
	$debug_info .= "Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "\n";
	$debug_info .= "User Agent: " . (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Not set') . "\n";
	$debug_info .= "Max Execution Time: " . ini_get('max_execution_time') . "s\n";
	$debug_info .= "Memory Limit: " . ini_get('memory_limit') . "\n";
	$debug_info .= "WP Memory Limit: " . WP_MEMORY_LIMIT . "\n\n";
	
	// Aero Settings
	$guest_mode_level = get_option('aero_guest_mode_level', 'off');
	$debug_info .= "--- AERO SETTINGS ---\n";
	$debug_info .= "Minify CSS: " . (get_option('aero_combine_css') === 'on' ? 'Enabled' : 'Disabled') . "\n";
	$debug_info .= "Minify JS: " . (get_option('aero_combine_js') === 'on' ? 'Enabled' : 'Disabled') . "\n";
	$debug_info .= "Compress HTML: " . (get_option('aero_compress_html') === 'on' ? 'Enabled' : 'Disabled') . "\n";
	$debug_info .= "Defer JS: " . (get_option('aero_defer_js') === 'on' ? 'Enabled' : 'Disabled') . "\n";
	$debug_info .= "Optimize Fonts: " . (get_option('aero_optimize_fonts') === 'on' ? 'Enabled' : 'Disabled') . "\n";
	$debug_info .= "Preload Critical: " . (get_option('aero_preload_critical') === 'on' ? 'Enabled' : 'Disabled') . "\n";
	$debug_info .= "Guest Mode Level: " . ucfirst($guest_mode_level) . "\n";
	$debug_info .= "Debug Mode: " . (get_option('aero_debug_mode') === 'on' ? 'Enabled' : 'Disabled') . "\n\n";
	
	// Guest Detection
	$debug_info .= "--- GUEST MODE DETECTION ---\n";
	$debug_info .= "Is Guest Visitor: " . (aero_is_guest_visitor() ? 'YES' : 'NO') . "\n";
	$debug_info .= "Guest Mode Active: " . ($guest_mode_level !== 'off' ? 'YES (' . ucfirst($guest_mode_level) . ')' : 'NO') . "\n\n";
	
	// Cache Info
	$debug_info .= "--- CACHE INFO ---\n";
	$debug_info .= "Cache Directory: " . AERO_CACHE_DIR . "\n";
	$debug_info .= "Cache Dir Exists: " . (file_exists(AERO_CACHE_DIR) ? 'Yes' : 'No') . "\n";
	$debug_info .= "Cache Dir Writable: " . (is_writable(AERO_CACHE_DIR) ? 'Yes' : 'No') . "\n";
	$debug_info .= "CSS Cache Dir: " . AERO_CSS_CACHE_DIR . "\n";
	$debug_info .= "CSS Files Cached: " . aero_count_files(AERO_CSS_CACHE_DIR) . "\n";
	$debug_info .= "CSS Cache Size: " . aero_format_bytes(aero_get_directory_size(AERO_CSS_CACHE_DIR)) . "\n";
	$debug_info .= "JS Cache Dir: " . AERO_JS_CACHE_DIR . "\n";
	$debug_info .= "JS Files Cached: " . aero_count_files(AERO_JS_CACHE_DIR) . "\n";
	$debug_info .= "JS Cache Size: " . aero_format_bytes(aero_get_directory_size(AERO_JS_CACHE_DIR)) . "\n\n";

	// Batcache Configuration
	$batcache_config = aero_check_batcache_config();
	$debug_info .= "--- BATCACHE CONFIGURATION ---\n";
	$debug_info .= "Configured: " . ($batcache_config['exists'] ? 'Yes' : 'No') . "\n";
	$debug_info .= "wp-config.php Writable: " . ($batcache_config['writable'] ? 'Yes' : 'No') . "\n";
	if ($batcache_config['exists'] && $batcache_config['values']) {
		$debug_info .= "Max Age: " . ($batcache_config['values']['max_age'] ?? 'Not set') . " seconds\n";
		$debug_info .= "Wait Time (seconds): " . ($batcache_config['values']['seconds'] ?? 'Not set') . "\n";
		$debug_info .= "Visitor Threshold (times): " . ($batcache_config['values']['times'] ?? 'Not set') . "\n";
	}
	$debug_info .= "Can Configure: " . (aero_can_configure_batcache() ? 'Yes' : 'No') . "\n";
	$debug_info .= "Backup Directory: " . AERO_BACKUP_DIR . "\n";
	$debug_info .= "Backup Dir Exists: " . (file_exists(AERO_BACKUP_DIR) ? 'Yes' : 'No') . "\n";
	$debug_info .= "Backup Dir Writable: " . (is_writable(AERO_BACKUP_DIR) ? 'Yes' : 'No') . "\n";
	$debug_info .= "\n";
	
	// Active Plugins
	$debug_info .= "--- ACTIVE PLUGINS ---\n";
	$active_plugins = get_option('active_plugins');
	foreach ($active_plugins as $plugin) {
		$plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
		$debug_info .= $plugin_data['Name'] . " v" . $plugin_data['Version'] . "\n";
	}
	$debug_info .= "\n";
	
	// Constants
	$debug_info .= "--- CONSTANTS ---\n";
	$debug_info .= "WP_DEBUG: " . (defined('WP_DEBUG') && WP_DEBUG ? 'true' : 'false') . "\n";
	$debug_info .= "WP_DEBUG_LOG: " . (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'true' : 'false') . "\n";
	$debug_info .= "WP_DEBUG_DISPLAY: " . (defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY ? 'true' : 'false') . "\n";
	$debug_info .= "SCRIPT_DEBUG: " . (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? 'true' : 'false') . "\n";
	$debug_info .= "WP_CACHE: " . (defined('WP_CACHE') && WP_CACHE ? 'true' : 'false') . "\n\n";
	
	$debug_info .= "=== END DEBUG INFO ===\n";
	
	return $debug_info;
}

/**
 * Check if site is using Gutenberg block editor
 */
function aero_is_using_gutenberg() {
	// Check if Gutenberg plugin is active
	if ( is_plugin_active( 'gutenberg/gutenberg.php' ) ) {
		return true;
	}
	
	// Check if using WordPress 5.0+ (has Gutenberg by default)
	global $wp_version;
	if ( version_compare( $wp_version, '5.0', '>=' ) ) {
		// Check if Classic Editor plugin is active (disables Gutenberg)
		if ( is_plugin_active( 'classic-editor/classic-editor.php' ) ) {
			return false;
		}
		return true;
	}
	
	return false;
}

/**
 * Check if site is hosted on WP Stratos
 */
function aero_check_hosting_environment() {
	$is_wpstratos = false;
	$platform_header = false;
	$powered_by_header = false;
	
	// Check response headers from a sample request
	$response = wp_remote_get(home_url(), array('timeout' => 5));
	if (!is_wp_error($response)) {
		$headers = wp_remote_retrieve_headers($response);
		
		// Check for WP Stratos specific headers
		// Headers can be string or array, handle both
		if (isset($headers['platform'])) {
			$platform_value = is_array($headers['platform']) ? implode(' ', $headers['platform']) : $headers['platform'];
			if (stripos($platform_value, 'WP Stratos') !== false) {
				$is_wpstratos = true;
				$platform_header = true;
			}
		}
		
		if (isset($headers['x-powered-by'])) {
			$powered_value = is_array($headers['x-powered-by']) ? implode(' ', $headers['x-powered-by']) : $headers['x-powered-by'];
			if (stripos($powered_value, 'WP Stratos') !== false) {
				$is_wpstratos = true;
				$powered_by_header = true;
			}
		}
	}
	
	return array(
		'is_wpstratos' => $is_wpstratos,
		'platform_header' => $platform_header,
		'powered_by_header' => $powered_by_header
	);
}

/**
 * Check for WordPress drop-ins
 */
function aero_check_dropins() {
	$wp_content_dir = WP_CONTENT_DIR;
	
	// Try alternative path used by some hosts, but handle open_basedir restrictions
	$alt_path = '/srv/htdocs/wp-content';
	
	// Check if we can access alternative path (respects open_basedir)
	if (@file_exists($alt_path) && @is_dir($alt_path)) {
		$wp_content_dir = $alt_path;
	}
	
	// Use @ to suppress warnings for open_basedir restrictions
	$advanced_cache = @file_exists($wp_content_dir . '/advanced-cache.php');
	$object_cache = @file_exists($wp_content_dir . '/object-cache.php');
	
	return array(
		'advanced_cache' => $advanced_cache,
		'object_cache' => $object_cache
	);
}

/**
 * Check if site meets requirements for Batcache configuration
 */
function aero_can_configure_batcache() {
	$hosting = aero_check_hosting_environment();
	$dropins = aero_check_dropins();
	
	return $hosting['is_wpstratos'] && $dropins['advanced_cache'] && $dropins['object_cache'];
}

/**
 * Check if Batcache configuration exists in wp-config.php
 */
function aero_check_batcache_config() {
	$wp_config_path = aero_get_wp_config_path();
	
	if (!$wp_config_path || !file_exists($wp_config_path)) {
		return array(
			'exists' => false,
			'writable' => false,
			'values' => null
		);
	}
	
	$config_content = @file_get_contents($wp_config_path);
	if ($config_content === false) {
		return array(
			'exists' => false,
			'writable' => false,
			'values' => null
		);
	}
	
	$has_batcache_config = (
		strpos($config_content, 'Batcache Customizations') !== false ||
		strpos($config_content, '$batcache') !== false
	);
	
	$values = array(
		'max_age' => null,
		'seconds' => null,
		'times' => null,
		'noskip_cookies' => null
	);
	
	if ($has_batcache_config) {
		// Extract values
		if (preg_match('/\$batcache(?:->|\[[\'"]+)max_age(?:[\'"]+\])?\s*=\s*(\d+)/', $config_content, $matches)) {
			$values['max_age'] = intval($matches[1]);
		}
		if (preg_match('/\$batcache(?:->|\[[\'"]+)seconds(?:[\'"]+\])?\s*=\s*(\d+)/', $config_content, $matches)) {
			$values['seconds'] = intval($matches[1]);
		}
		if (preg_match('/\$batcache(?:->|\[[\'"]+)times(?:[\'"]+\])?\s*=\s*(\d+)/', $config_content, $matches)) {
			$values['times'] = intval($matches[1]);
		}
		// Extract noskip_cookies array
		if (preg_match('/\$batcache(?:->|\[[\'"]+)noskip_cookies(?:[\'"]+\])?\s*=\s*array\s*\((.*?)\)/s', $config_content, $matches)) {
			// Parse the array contents
			$cookies_string = $matches[1];
			preg_match_all('/[\'"]([^\'"]+)[\'"]/', $cookies_string, $cookie_matches);
			if (!empty($cookie_matches[1])) {
				$values['noskip_cookies'] = implode(', ', $cookie_matches[1]);
			}
		}
	}
	
	return array(
		'exists' => $has_batcache_config,
		'writable' => is_writable($wp_config_path),
		'values' => $values
	);
}

/**
 * Get wp-config.php file path
 */
function aero_get_wp_config_path() {
	// Check standard location first
	$config_path = ABSPATH . 'wp-config.php';
	if (file_exists($config_path)) {
		return $config_path;
	}
	
	// Check one directory up (common for some setups)
	$config_path = dirname(ABSPATH) . '/wp-config.php';
	if (file_exists($config_path) && !file_exists(dirname(ABSPATH) . '/wp-settings.php')) {
		return $config_path;
	}
	
	return false;
}

/**
 * Add Batcache configuration to wp-config.php
 */
function aero_add_batcache_config($max_age = 86400, $seconds = 0, $times = 1, $noskip_cookies = '') {
	$wp_config_path = aero_get_wp_config_path();
	
	if (!$wp_config_path || !file_exists($wp_config_path)) {
		return array('success' => false, 'message' => 'wp-config.php not found');
	}
	
	if (!is_writable($wp_config_path)) {
		return array('success' => false, 'message' => 'wp-config.php is not writable');
	}
	
	$config_content = file_get_contents($wp_config_path);
	
	// Check if already exists
	if (strpos($config_content, 'Batcache Customizations') !== false) {
		return array('success' => false, 'message' => 'Batcache configuration already exists');
	}
	
	// Create backup directory if it doesn't exist
	if (!file_exists(AERO_BACKUP_DIR)) {
		@mkdir(AERO_BACKUP_DIR, 0755, true);
	}
	
	// Create backup in wp-content/aero-backups/
	$backup_filename = 'wp-config-backup-' . date('Y-m-d-His') . '.php';
	$backup_path = AERO_BACKUP_DIR . $backup_filename;
	
	if (!@copy($wp_config_path, $backup_path)) {
		return array('success' => false, 'message' => 'Failed to create backup in ' . AERO_BACKUP_DIR);
	}
	
	// Parse noskip_cookies string into array format
	$cookies_array = '';
	if (!empty(trim($noskip_cookies))) {
		$cookies = array_map('trim', explode(',', $noskip_cookies));
		$cookies = array_filter($cookies); // Remove empty values
		if (!empty($cookies)) {
			$cookies_formatted = array_map(function($cookie) {
				return "'" . str_replace("'", "\\'", $cookie) . "'";
			}, $cookies);
			$cookies_array = implode(', ', $cookies_formatted);
		}
	}
	
	// Default cookies if none provided
	if (empty($cookies_array)) {
		$cookies_array = "'wordpress_test_cookie', 'wp-wpml_current_language', 'wpml_browser_redirect_test'";
	}
	
	// Prepare Batcache configuration
	$batcache_config = "\n//Batcache Customizations\n";
	$batcache_config .= "global \$batcache;\n\n";
	$batcache_config .= "//Check if batcache params are in an object or an array, apply customizations accordingly\n";
	$batcache_config .= "if ( is_object( \$batcache ) ) {\n";
	$batcache_config .= "    \$batcache->max_age = " . intval($max_age) . "; // Seconds the cached render of a page will be stored\n";
	$batcache_config .= "    \$batcache->seconds = " . intval($seconds) . "; // Time number of visitors required to cache, 0 = instant\n";
	$batcache_config .= "    \$batcache->times = " . intval($times) . "; // Number of visitors required to cache\n";
	$batcache_config .= "    \$batcache->noskip_cookies = array( " . $cookies_array . " ); // Cookies that prevent caching\n";
	$batcache_config .= "} elseif ( is_array( \$batcache ) ) {\n";
	$batcache_config .= "    \$batcache['max_age'] = " . intval($max_age) . "; // Seconds the cached render of a page will be stored\n";
	$batcache_config .= "    \$batcache['seconds'] = " . intval($seconds) . "; // Time number of visitors required to cache, 0 = instant\n";
	$batcache_config .= "    \$batcache['times'] = " . intval($times) . "; // Number of visitors required to cache\n";
	$batcache_config .= "    \$batcache['noskip_cookies'] = array( " . $cookies_array . " ); // Cookies that prevent caching\n";
	$batcache_config .= "}\n";
	$batcache_config .= "// End Batcache Customizations\n\n";
	
	// Find insertion point (before "That's all, stop editing!")
	$stop_editing_pos = strpos($config_content, "/* That's all, stop editing!");
	
	if ($stop_editing_pos === false) {
		// Try alternative patterns
		$patterns = array(
			"/* That's all, stop editing!",
			"/* That's all, stop editing!",
			"/*That's all, stop editing!"
		);
		
		foreach ($patterns as $pattern) {
			$stop_editing_pos = strpos($config_content, $pattern);
			if ($stop_editing_pos !== false) break;
		}
		
		if ($stop_editing_pos === false) {
			// Insert before final closing PHP tag or at end
			if (strpos($config_content, '?>') !== false) {
				$config_content = str_replace('?>', $batcache_config . '?>', $config_content);
			} else {
				$config_content .= $batcache_config;
			}
		} else {
			$config_content = substr_replace($config_content, $batcache_config, $stop_editing_pos, 0);
		}
	} else {
		$config_content = substr_replace($config_content, $batcache_config, $stop_editing_pos, 0);
	}
	
	// Write updated config
	if (@file_put_contents($wp_config_path, $config_content) === false) {
		// Restore backup on failure
		@copy($backup_path, $wp_config_path);
		@unlink($backup_path);
		return array('success' => false, 'message' => 'Failed to write to wp-config.php');
	}
	
	return array('success' => true, 'message' => 'Batcache configuration added successfully', 'backup' => $backup_filename);
}

/**
 * Update Batcache configuration in wp-config.php
 */
function aero_update_batcache_config($max_age, $seconds, $times, $noskip_cookies = '') {
	$wp_config_path = aero_get_wp_config_path();
	
	if (!$wp_config_path || !file_exists($wp_config_path)) {
		return array('success' => false, 'message' => 'wp-config.php not found');
	}
	
	if (!is_writable($wp_config_path)) {
		return array('success' => false, 'message' => 'wp-config.php is not writable');
	}
	
	$config_content = file_get_contents($wp_config_path);
	
	// Check if config exists
	if (strpos($config_content, 'Batcache Customizations') === false) {
		// Config doesn't exist, add it
		return aero_add_batcache_config($max_age, $seconds, $times, $noskip_cookies);
	}
	
	// Create backup directory if it doesn't exist
	if (!file_exists(AERO_BACKUP_DIR)) {
		@mkdir(AERO_BACKUP_DIR, 0755, true);
	}
	
	// Create backup in wp-content/aero-backups/
	$backup_filename = 'wp-config-backup-' . date('Y-m-d-His') . '.php';
	$backup_path = AERO_BACKUP_DIR . $backup_filename;
	
	if (!@copy($wp_config_path, $backup_path)) {
		return array('success' => false, 'message' => 'Failed to create backup in ' . AERO_BACKUP_DIR);
	}
	
	// Update numeric values
	$config_content = preg_replace(
		'/(\$batcache(?:->|\[[\'"]+)max_age(?:[\'"]+\])?\s*=\s*)\d+/',
		'${1}' . intval($max_age),
		$config_content
	);
	$config_content = preg_replace(
		'/(\$batcache(?:->|\[[\'"]+)seconds(?:[\'"]+\])?\s*=\s*)\d+/',
		'${1}' . intval($seconds),
		$config_content
	);
	$config_content = preg_replace(
		'/(\$batcache(?:->|\[[\'"]+)times(?:[\'"]+\])?\s*=\s*)\d+/',
		'${1}' . intval($times),
		$config_content
	);
	
	// Parse noskip_cookies string into array format
	$cookies_array = '';
	if (!empty(trim($noskip_cookies))) {
		$cookies = array_map('trim', explode(',', $noskip_cookies));
		$cookies = array_filter($cookies); // Remove empty values
		if (!empty($cookies)) {
			$cookies_formatted = array_map(function($cookie) {
				return "'" . str_replace("'", "\\'", $cookie) . "'";
			}, $cookies);
			$cookies_array = implode(', ', $cookies_formatted);
		}
	}
	
	// Default cookies if none provided
	if (empty($cookies_array)) {
		$cookies_array = "'wordpress_test_cookie', 'wp-wpml_current_language', 'wpml_browser_redirect_test'";
	}
	
	// Update noskip_cookies array
	$config_content = preg_replace(
		'/(\$batcache(?:->|\[[\'"]+)noskip_cookies(?:[\'"]+\])?\s*=\s*)array\s*\(.*?\)\s*;/s',
		'${1}array( ' . $cookies_array . ' );',
		$config_content
	);
	
	// Write updated config
	if (@file_put_contents($wp_config_path, $config_content) === false) {
		// Restore backup on failure
		@copy($backup_path, $wp_config_path);
		@unlink($backup_path);
		return array('success' => false, 'message' => 'Failed to write to wp-config.php');
	}
	
	// Clean up old backups (keep last 5)
	$backups = glob(AERO_BACKUP_DIR . 'wp-config-backup-*.php');
	if (count($backups) > 5) {
		usort($backups, function($a, $b) {
			return filemtime($a) - filemtime($b);
		});
		for ($i = 0; $i < count($backups) - 5; $i++) {
			@unlink($backups[$i]);
		}
	}
	
	return array('success' => true, 'message' => 'Batcache configuration updated successfully', 'backup' => $backup_filename);
}

/**
 * Detect active page builder
 */
function aero_detect_page_builder() {
	$page_builders = array(
		'elementor/elementor.php' => 'Elementor',
		'beaver-builder-lite-version/fl-builder.php' => 'Beaver Builder',
		'bb-plugin/fl-builder.php' => 'Beaver Builder Pro',
		'siteorigin-panels/siteorigin-panels.php' => 'SiteOrigin Page Builder',
		'js_composer/js_composer.php' => 'WPBakery Page Builder',
		'divi-builder/divi-builder.php' => 'Divi Builder',
		'oxygen/functions.php' => 'Oxygen Builder',
		'bricks/bricks.php' => 'Bricks Builder'
	);
	
	$active_plugins = get_option('active_plugins');
	
	foreach ($page_builders as $plugin_path => $builder_name) {
		if (in_array($plugin_path, $active_plugins)) {
			return $builder_name;
		}
	}
	
	// Check for Divi theme
	$theme = wp_get_theme();
	if ($theme->get('Name') === 'Divi' || $theme->get_template() === 'Divi') {
		return 'Divi Theme';
	}
	
	return false;
}

function aero_get_directory_size($directory) {
	$size = 0;
	if (is_dir($directory)) {
		try {
			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
				if ($file->isFile()) {
					$size += $file->getSize();
				}
			}
		} catch (Exception $e) {}
	}
	return $size;
}

function aero_count_files($directory) {
	$count = 0;
	if (is_dir($directory)) {
		$files = glob($directory . '*');
		if ($files) {
			foreach ($files as $file) {
				if (is_file($file) && strpos($file, '.hash') === false) {
					$count++;
				}
			}
		}
	}
	return $count;
}

function aero_format_bytes($bytes, $precision = 2) {
	$units = array('B', 'KB', 'MB', 'GB');
	$bytes = max($bytes, 0);
	$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
	$pow = min($pow, count($units) - 1);
	$bytes /= pow(1024, $pow);
	return round($bytes, $precision) . ' ' . $units[$pow];
}

function aero_check_plugin_update() {
	$saved_version = get_option('aero_plugin_version' );
	if ( version_compare( $saved_version, AERO_PLUGIN_VERSION_NUM, '<' ) || $saved_version === FALSE ) {
		update_option( 'aero_plugin_version', AERO_PLUGIN_VERSION_NUM );
		
		// Try to auto-configure Batcache on plugin update if requirements are met
		if ( aero_can_configure_batcache() ) {
			$batcache_config = aero_check_batcache_config();
			if ( !$batcache_config['exists'] ) {
				// Silently attempt to configure Batcache
				aero_add_batcache_config();
			}
		}
	}
}
add_action( 'admin_init', 'aero_check_plugin_update' );

function aero_activate_plugin() {
    update_option( 'aero_combine_js', 'on' );
    update_option( 'aero_combine_css', 'on' );
	update_option( 'aero_compress_html', 'on' );
	update_option( 'aero_defer_js', 'on' );
	update_option( 'aero_optimize_fonts', 'on' );
	update_option( 'aero_preload_critical', 'on' );
	update_option( 'aero_guest_mode_level', 'off' );
	update_option( 'aero_debug_mode', 'off' );
	
	// Try to auto-configure Batcache on plugin activation if requirements are met
	if ( aero_can_configure_batcache() ) {
		$batcache_config = aero_check_batcache_config();
		if ( !$batcache_config['exists'] ) {
			// Silently attempt to configure Batcache
			aero_add_batcache_config();
		}
	}

	// Image Optimizer: create the aero-nextgen tree and (re)write the image
	// rewrite rules when an .htaccess delivery mode is active.
	if ( function_exists( 'aero_io_activate' ) ) {
		aero_io_activate();
	}
}
register_activation_hook( __FILE__, 'aero_activate_plugin' );

function aero_deactivate_plugin() {
	delete_option( 'aero_plugin_version' );

	// Clear the Aero Cache Manager scheduled flush
	if ( function_exists( 'aero_cm_clear_schedule' ) ) {
		aero_cm_clear_schedule();
	}
	if ( defined( 'AERO_CW_BATCH_HOOK' ) ) {
		wp_clear_scheduled_hook( AERO_CW_BATCH_HOOK );
		wp_clear_scheduled_hook( AERO_CW_SCHEDULE_HOOK );
		wp_clear_scheduled_hook( AERO_CW_MICRO_HOOK );
		wp_clear_scheduled_hook( AERO_CW_EDGE_HOOK );
		delete_option( 'aero_cw_running' );
		delete_option( 'aero_cw_micro_queue' );
	}

	// Image Optimizer: pull the image rewrite rules back out of .htaccess so
	// image URLs keep resolving to originals while the plugin is inactive.
	// Generated files are left in place — deactivation is not uninstall.
	if ( function_exists( 'aero_io_deactivate' ) ) {
		aero_io_deactivate();
	}
}
register_deactivation_hook( __FILE__, 'aero_deactivate_plugin' );

// Critical resource hints - Load BEFORE any CSS/JS
add_action( 'wp_head', 'aero_add_critical_resource_hints', 1 );
function aero_add_critical_resource_hints() {
	if ( get_option( 'aero_optimize_fonts', 1 ) === 'on' ) {
		// Preconnect to external font providers
		echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
		echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
		echo '<link rel="preconnect" href="https://use.typekit.net" crossorigin>' . "\n";
	}
	
	if ( get_option( 'aero_preload_critical', 1 ) === 'on' ) {
		// Will be populated by aero_add_preload_tags() later in the head
	}
}

// Add preload tags for critical resources
add_action( 'wp_head', 'aero_add_preload_tags', 2 );
function aero_add_preload_tags() {
	if ( get_option( 'aero_preload_critical', 1 ) !== 'on' ) {
		return;
	}
	
	global $wp_styles;
	
	// Preload first 2 CSS files (critical above-the-fold styles)
	if ( !empty( $wp_styles->queue ) ) {
		$count = 0;
		foreach ( array_slice( $wp_styles->queue, 0, 2 ) as $handle ) {
			if ( isset( $wp_styles->registered[$handle] ) ) {
				$src = $wp_styles->registered[$handle]->src;
				if ( $src && aero_is_local_url( $src ) ) {
					echo '<link rel="preload" href="' . esc_url( $src ) . '" as="style">' . "\n";
					$count++;
				}
			}
		}
	}
}

/**
 * Detect if visitor is a PageSpeed/performance testing tool
 */
function aero_is_guest_visitor() {
	$guest_mode_level = get_option( 'aero_guest_mode_level', 'off' );
	
	if ( $guest_mode_level === 'off' ) {
		return false;
	}
	
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '';
	
	// Empty user agent is suspicious - likely a bot/tool
	if ( empty($user_agent) ) {
		return true;
	}
	
	// Comprehensive list of performance testing tools
	$guest_patterns = array(
		'lighthouse',
		'gtmetrix',
		'pagespeed',
		'google page speed',
		'webpagetest',
		'pingdom',
		'chrome-lighthouse',
		'speed insights',
		'dareboost',
		'yellowlab',
		'dotcom-monitor',
		'uptrends'
	);
	
	foreach ( $guest_patterns as $pattern ) {
		if ( stripos( $user_agent, $pattern ) !== false ) {
			return true;
		}
	}
	
	return false;
}

/**
 * Get the current guest mode level
 */
function aero_get_guest_mode_level() {
	if ( !aero_is_guest_visitor() ) {
		return false;
	}
	
	return get_option( 'aero_guest_mode_level', 'off' );
}

function aero_minify_html( $buffer ) {
	if ( !aero_is_html_content( $buffer ) ) {
		return $buffer;
	}
	
	$initial = strlen( $buffer );
	
	if ( !class_exists( 'Minify_HTML' ) ) {
		require_once( AERO_MINIFY_LIBRARY_PATH . "/lib/Minify/HTML.php" );
		require_once( AERO_MINIFY_LIBRARY_PATH . "/lib/Minify/Loader.php" );
		Minify_Loader::register();
	}

	// Single-pass HTML minification: one document parse with whichever
	// inline minifiers are enabled. (Previously this ran Minify_HTML twice —
	// two full parses of the entire page on every uncached request.)
	$minify_opts = array();
	if ( get_option( 'aero_combine_js', 1 ) === 'on' ) {
		$minify_opts['jsMinifier'] = array( 'JSMin', 'minify' );
	}
	if ( get_option( 'aero_combine_css', 1 ) === 'on' ) {
		$minify_opts['cssMinifier'] = array( 'Minify_CSS', 'minify' );
	}
	if ( ! empty( $minify_opts ) ) {
		$buffer = Minify_HTML::minify( $buffer, $minify_opts );
	}

	// Process assets
	$buffer = aero_process_html_assets( $buffer );
	
	// Add image optimizations
	$buffer = aero_optimize_images( $buffer );

	// Compress HTML - FIXED VERSION
	if ( get_option( 'aero_compress_html', 1 ) === 'on' ) {
		$buffer = aero_ultra_compress_html( $buffer );
	}

	$final = strlen( $buffer );
	$savings = ($initial > 0) ? round((($initial - $final) / $initial * 100), 3) : 0;

	if ( $savings > 0 ) {
		global $aero_minify_comment;
		$guest_mode_level = aero_get_guest_mode_level();
		$mode = $guest_mode_level ? ' [Guest Mode: ' . ucfirst($guest_mode_level) . ']' : '';
		$aero_minify_comment = PHP_EOL . '<!-- Optimized by Aero v' . AERO_PLUGIN_VERSION_NUM . $mode . ' | Saved: ' . $savings . '% | https://wpstratos.com -->';
	}

	return $buffer;
}

function aero_is_html_content( $buffer ) {
	if ( stripos( $buffer, '<!DOCTYPE html' ) !== false || stripos( $buffer, '<html' ) !== false ) {
		return true;
	}
	
	$headers = headers_list();
	foreach ( $headers as $header ) {
		if ( stripos( $header, 'Content-Type:' ) !== false ) {
			if ( stripos( $header, 'text/html' ) !== false ) {
				return true;
			}
			if ( stripos( $header, 'text/xml' ) !== false ||
			     stripos( $header, 'application/xml' ) !== false ||
			     stripos( $header, 'application/json' ) !== false ) {
				return false;
			}
		}
	}
	
	return ( stripos( $buffer, '<head' ) !== false || stripos( $buffer, '<body' ) !== false );
}

// Optimize images - add dimensions and optimize attributes
function aero_optimize_images( $html ) {
	// Add fetchpriority="high" to first image (likely LCP element)
	$html = preg_replace_callback(
		'/<img([^>]+)>/i',
		function( $matches ) {
			static $first_image = true;
			$img_tag = $matches[0];
			
			// Add fetchpriority=high to first image if not present
			if ( $first_image && strpos( $img_tag, 'fetchpriority' ) === false ) {
				$img_tag = str_replace( '<img', '<img fetchpriority="high"', $img_tag );
				$first_image = false;
			}
			
			// Remove loading=lazy from first 2 images (above fold)
			static $image_count = 0;
			$image_count++;
			if ( $image_count <= 2 && strpos( $img_tag, 'loading="lazy"' ) !== false ) {
				$img_tag = str_replace( ' loading="lazy"', '', $img_tag );
			}
			
			return $img_tag;
		},
		$html,
		-1,
		$count
	);
	
	return $html;
}

// Optimize font loading
function aero_optimize_fonts( $html ) {
	if ( get_option( 'aero_optimize_fonts', 1 ) !== 'on' ) {
		return $html;
	}
	
	// Add font-display: swap to Google Fonts URLs
	$html = preg_replace_callback(
		'/<link([^>]*?)href=["\']([^"\']*fonts\.googleapis\.com[^"\']*)["\']([^>]*)>/i',
		function( $matches ) {
			$url = $matches[2];
			// Add display=swap if not present
			if ( strpos( $url, 'display=' ) === false ) {
				$separator = ( strpos( $url, '?' ) !== false ) ? '&' : '?';
				$url .= $separator . 'display=swap';
			}
			return '<link' . $matches[1] . 'href="' . $url . '"' . $matches[3] . '>';
		},
		$html
	);
	
	// Add font-display: swap to Adobe TypeKit URLs
	$html = preg_replace_callback(
		'/<link([^>]*?)href=["\']([^"\']*use\.typekit\.net[^"\']*)["\']([^>]*)>/i',
		function( $matches ) {
			$url = $matches[2];
			// TypeKit uses CSS, need to inject @font-face modifications via inline style
			// But simpler: just ensure preconnect exists
			return '<link' . $matches[1] . 'href="' . $url . '"' . $matches[3] . '>';
		},
		$html
	);
	
	return $html;
}

/**
 * Ultra compress HTML - FIXED VERSION
 * Aggressively removes ALL unnecessary whitespace while protecting critical tags
 */
function aero_ultra_compress_html( $html ) {
	$protected = array();
	$protect_tags = array( 'pre', 'textarea', 'script', 'style' );
	
	// Step 1: Protect critical tags from compression
	foreach ( $protect_tags as $tag ) {
		preg_match_all( '/<' . $tag . '[^>]*?>.*?<\/' . $tag . '>/is', $html, $matches );
		foreach ( $matches[0] as $i => $match ) {
			$placeholder = '###AERO_' . strtoupper($tag) . '_' . $i . '###';
			$protected[$placeholder] = $match;
			$html = str_replace( $match, $placeholder, $html );
		}
	}
	
	// Step 2: Remove HTML comments (except IE conditionals and our own comment)
	$html = preg_replace( '/<!--(?!\[if)(?!.*?Optimized by Aero).*?-->/s', '', $html );
	
	// Step 3: AGGRESSIVE whitespace removal
	// Remove ALL whitespace between tags
	$html = preg_replace( '/>\s+</', '><', $html );
	
	// Remove ALL leading whitespace
	$html = preg_replace( '/^\s+/m', '', $html );
	
	// Remove ALL trailing whitespace
	$html = preg_replace( '/\s+$/m', '', $html );
	
	// Collapse multiple spaces/tabs/newlines into single space
	$html = preg_replace( '/\s+/', ' ', $html );
	
	// Remove spaces around = in attributes
	$html = preg_replace( '/\s*=\s*/', '=', $html );
	
	// Remove spaces before self-closing tags
	$html = preg_replace( '/\s+\/>/', '/>', $html );
	
	// Remove newlines completely
	$html = str_replace( array("\r\n", "\r", "\n", "\t"), '', $html );
	
	// Step 4: Restore protected content
	foreach ( $protected as $placeholder => $content ) {
		$html = str_replace( $placeholder, $content, $html );
	}
	
	return trim( $html );
}

/**
 * Process HTML assets - ENHANCED for Basic and Extreme Guest Mode
 */
function aero_process_html_assets( $html ) {
	$guest_mode_level = aero_get_guest_mode_level();
	
	// EXTREME GUEST MODE - Maximum stripping
	if ( $guest_mode_level === 'extreme' ) {
		// Remove ALL Google Fonts
		$html = preg_replace( '/<link[^>]*?fonts\.googleapis\.com[^>]*?>/i', '', $html );
		
		// Remove font awesome and icon fonts
		$html = preg_replace( '/<link[^>]*?(font-awesome|fontawesome|fa-)[^>]*?>/i', '', $html );
		
		// Keep only first 2 stylesheets, remove the rest
		$css_count = 0;
		$html = preg_replace_callback(
			'/<link([^>]*?)rel=["\']stylesheet["\']([^>]*?)>/i',
			function( $matches ) use ( &$css_count ) {
				$css_count++;
				// Keep first 2 CSS files
				if ( $css_count <= 2 ) return $matches[0];
				
				// Check if this is a "heavy" CSS file to remove
				$url = '';
				if ( preg_match( '/href=["\']([^"\']+)["\']/', $matches[0], $href ) ) {
					$url = $href[1];
				}
				
				// Remove known heavy/unnecessary CSS
				$remove_keywords = array( 
					'animation', 
					'swiper', 
					'slider', 
					'icon', 
					'font-awesome',
					'social',
					'carousel',
					'lightbox'
				);
				
				foreach ( $remove_keywords as $keyword ) {
					if ( stripos( $url, $keyword ) !== false ) return '';
				}
				
				// Remove all other CSS after first 2
				return '';
			},
			$html
		);
		
		// Remove ALL JavaScript including jQuery
		$html = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html );
		
		return $html;
	}
	
	// BASIC GUEST MODE - Combine all CSS into one file, remove all JS, inline fonts
	if ( $guest_mode_level === 'basic' ) {
		// Step 1: Extract and inline all font CSS (Google Fonts, Adobe Fonts, etc.)
		$font_providers = array(
			'fonts.googleapis.com',
			'fonts.gstatic.com',
			'use.typekit.net',
			'cloud.typography.com',
			'use.fontawesome.com',
			'fonts.adobe.com'
		);
		
		$inlined_fonts_css = '';
		$font_links_removed = 0;
		
		// Find and fetch all font provider CSS
		preg_match_all( '/<link([^>]*?)href=["\']([^"\']+)["\']([^>]*?)>/i', $html, $all_links );
		
		if ( !empty( $all_links[2] ) ) {
			foreach ( $all_links[2] as $index => $link_url ) {
				$is_font_provider = false;
				
				// Check if this link is from a font provider
				foreach ( $font_providers as $provider ) {
					if ( stripos( $link_url, $provider ) !== false ) {
						$is_font_provider = true;
						break;
					}
				}
				
				if ( $is_font_provider ) {
					// Prefer Aero's LOCALIZED copy for Google Fonts: the remote
					// fetch below inlines CSS whose @font-face src still points
					// at fonts.gstatic.com — the font FILES stay on the CDN and
					// PageSpeed keeps flagging them. The localized entry's CSS
					// references same-origin files instead.
					if ( function_exists( 'aero_fonts_localize' ) &&
						 function_exists( 'aero_fonts_opt' ) &&
						 aero_fonts_opt( 'aero_fonts_local_google' ) &&
						 stripos( $link_url, 'fonts.googleapis.com/css' ) !== false ) {
						$local_entry = aero_fonts_localize( html_entity_decode( $link_url ) );
						if ( $local_entry && '' !== $local_entry['inline'] ) {
							$inlined_fonts_css .= "/* Localized Font (same-origin files): " . $link_url . " */\n";
							$inlined_fonts_css .= $local_entry['inline'] . "\n\n";
							$font_links_removed++;
							$html = str_replace( $all_links[0][$index], '', $html );
							continue;
						}
					}

					// Fetch the font CSS with proper user agent (important for Google Fonts)
					$response = wp_remote_get( $link_url, array( 
						'timeout' => 10,
						'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
					) );
					
					if ( !is_wp_error( $response ) ) {
						$response_code = wp_remote_retrieve_response_code( $response );
						$font_css = wp_remote_retrieve_body( $response );
						
						// Validate: Only add if response is 200 and content is actually CSS
						if ( $response_code === 200 && !empty( $font_css ) && aero_is_valid_css( $font_css ) ) {
							$inlined_fonts_css .= "/* Inlined Font: " . $link_url . " */\n";
							$inlined_fonts_css .= $font_css . "\n\n";
							$font_links_removed++;
							
							// Remove this font link from HTML
							$html = str_replace( $all_links[0][$index], '', $html );
						}
					}
				}
			}
		}
		
		// Step 2: Extract all remaining CSS URLs from the page
		preg_match_all( '/<link([^>]*?)href=["\']([^"\']+\.css[^"\']*?)["\']([^>]*?)>/i', $html, $css_matches );
		
		$css_urls = array();
		if ( !empty( $css_matches[2] ) ) {
			$css_urls = array_unique( $css_matches[2] );
		}
		
		// Step 3: Collect and combine all CSS content
		$combined_css = '';
		$collected_count = 0;
		
		// Add inlined fonts first (critical for render)
		if ( !empty( $inlined_fonts_css ) ) {
			$combined_css .= "/* === INLINED FONTS ($font_links_removed font files) === */\n";
			$combined_css .= $inlined_fonts_css;
			$combined_css .= "/* === END INLINED FONTS === */\n\n";
		}

		// Add custom guest mode CSS
		$custom_guest_css = get_option( 'aero_custom_css_guest', '' );
		if ( !empty( trim( $custom_guest_css ) ) ) {
			$combined_css .= "/* === CUSTOM GUEST MODE CSS === */\n";
			$combined_css .= wp_strip_all_tags( $custom_guest_css ) . "\n\n";
			$combined_css .= "/* === END CUSTOM GUEST MODE CSS === */\n\n";
		}
		
		// Then add all other CSS
		foreach ( $css_urls as $css_url ) {
			// Try to get the file path
			$css_path = aero_url_to_path( $css_url );
			
			// If local file exists, read it
			if ( $css_path && file_exists( $css_path ) ) {
				$css_content = @file_get_contents( $css_path );
				if ( $css_content !== false && aero_is_valid_css( $css_content ) ) {
					$combined_css .= "/* Source: " . basename( $css_url ) . " */\n";
					$combined_css .= $css_content . "\n\n";
					$collected_count++;
				}
			}
			// For external URLs, try to fetch
			elseif ( !aero_is_local_url( $css_url ) ) {
				$response = wp_remote_get( $css_url, array( 'timeout' => 5 ) );
				if ( !is_wp_error( $response ) ) {
					$response_code = wp_remote_retrieve_response_code( $response );
					$css_content = wp_remote_retrieve_body( $response );
					
					// Validate: Only add if response is 200 and content is actually CSS
					if ( $response_code === 200 && !empty( $css_content ) && aero_is_valid_css( $css_content ) ) {
						$combined_css .= "/* Source: " . basename( $css_url ) . " */\n";
						$combined_css .= $css_content . "\n\n";
						$collected_count++;
					}
				}
			}
		}
		
		// Step 4: Minify and save the combined CSS if we collected anything
		if ( !empty( $combined_css ) && ( $collected_count > 0 || $font_links_removed > 0 ) ) {
			try {
				// Minify the CSS first
				$minifier = new MatthiasMullie\Minify\CSS();
				$minifier->add( $combined_css );
				$minified_css = $minifier->minify();
				
				// Create a unique filename based on minified content hash
				$content_hash = md5( $minified_css );
				$combined_filename = 'guest-basic-combined-' . $content_hash . '.css';
				$combined_path = AERO_CSS_CACHE_DIR . $combined_filename;
				$combined_url = content_url() . '/cache/aero/css/' . $combined_filename;
				
				// Save the minified CSS
				if ( !file_exists( $combined_path ) ) {
					file_put_contents( $combined_path, $minified_css );
				}
				
				// Step 5: Remove all existing CSS links from HTML
				$html = preg_replace( '/<link([^>]*?)href=["\']([^"\']+\.css[^"\']*?)["\']([^>]*?)>/i', '', $html );
				
				// Step 6: Insert single combined CSS file before </head>
				$combined_tag = '<link rel="stylesheet" href="' . esc_url( $combined_url ) . '" id="aero-guest-combined-css">';
				$html = str_replace( '</head>', $combined_tag . "\n</head>", $html );
			} catch ( Exception $e ) {
				// If minification fails, still save unminified but valid CSS
				$content_hash = md5( $combined_css );
				$combined_filename = 'guest-basic-combined-' . $content_hash . '.css';
				$combined_path = AERO_CSS_CACHE_DIR . $combined_filename;
				$combined_url = content_url() . '/cache/aero/css/' . $combined_filename;
				
				if ( !file_exists( $combined_path ) ) {
					file_put_contents( $combined_path, $combined_css );
				}
				
				$html = preg_replace( '/<link([^>]*?)href=["\']([^"\']+\.css[^"\']*?)["\']([^>]*?)>/i', '', $html );
				$combined_tag = '<link rel="stylesheet" href="' . esc_url( $combined_url ) . '" id="aero-guest-combined-css">';
				$html = str_replace( '</head>', $combined_tag . "\n</head>", $html );
			}
		}
		
		// Step 7: Remove ALL JavaScript
		$html = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html );
		
		// Step 8: Remove font preconnect hints (no longer needed)
		$html = preg_replace( '/<link[^>]*?rel=["\']preconnect["\'][^>]*?(fonts\.googleapis\.com|fonts\.gstatic\.com|use\.typekit\.net)[^>]*?>/i', '', $html );
		
		return $html;
	}
	
	// NORMAL MODE - Real optimizations for actual users
	
	// Optimize fonts in HTML (new engine: self-host, inline, preconnect,
	// preload — falls back to the legacy swap-param helper if absent)
	$html = function_exists( 'aero_fonts_process_html' )
		? aero_fonts_process_html( $html )
		: aero_optimize_fonts( $html );

	// Delivery optimization: LCP preload, async CSS, delay JS — applied
	// last so they wrap the final (minified/localized) asset URLs.
	if ( function_exists( 'aero_delivery_process_html' ) ) {
		$html = aero_delivery_process_html( $html );
	}
	
	// Process CSS
	if ( get_option( 'aero_combine_css', 1 ) === 'on' ) {
		$html = preg_replace_callback(
			'/<link([^>]*?)href=["\']([^"\']+\.css[^"\']*)["\']([^>]*?)>/i',
			function( $matches ) {
				$full_match = $matches[0];
				$css_url = $matches[2];
				
				// Skip external, already minified, and user-excluded files
				if ( !aero_is_local_url( $css_url ) || strpos( $css_url, '.min.css' ) !== false || strpos( $css_url, '/cache/aero/' ) !== false ) {
					return $full_match;
				}
				if ( aero_asset_is_excluded( $css_url, 'aero_exclude_minify_css' ) ) {
					return $full_match;
				}
				
				$minified_url = aero_minify_file( $css_url, 'css' );
				if ( $minified_url ) {
					return str_replace( $css_url, $minified_url, $full_match );
				}
				return $full_match;
			},
			$html
		);
	}
	
	// Process JavaScript
	if ( get_option( 'aero_combine_js', 1 ) === 'on' || get_option( 'aero_defer_js', 1 ) === 'on' ) {
		$html = preg_replace_callback(
			'/<script([^>]*?)src=["\']([^"\']+\.js[^"\']*)["\']([^>]*?)><\/script>/i',
			function( $matches ) {
				$full_match = $matches[0];
				$js_url = $matches[2];
				
				// Skip if already has defer/async
				if ( strpos( $full_match, 'defer' ) !== false || strpos( $full_match, 'async' ) !== false ) {
					return $full_match;
				}
				
				$is_jquery = ( stripos( $js_url, 'jquery' ) !== false && stripos( $js_url, 'migrate' ) === false );
				
				// Minify if enabled, local, and not user-excluded
				if ( get_option( 'aero_combine_js', 1 ) === 'on' && 
				     aero_is_local_url( $js_url ) && 
				     strpos( $js_url, '.min.js' ) === false && 
				     strpos( $js_url, '/cache/aero/' ) === false &&
				     ! aero_asset_is_excluded( $js_url, 'aero_exclude_minify_js' ) ) {
					$minified_url = aero_minify_file( $js_url, 'js' );
					if ( $minified_url ) {
						$full_match = str_replace( $js_url, $minified_url, $full_match );
					}
				}
				
				// Add defer if enabled (jQuery and user-excluded scripts skipped)
				if ( get_option( 'aero_defer_js', 1 ) === 'on' && !$is_jquery &&
				     ! aero_asset_is_excluded( $js_url, 'aero_exclude_defer' ) ) {
					$full_match = str_replace( '<script', '<script defer', $full_match );
				}
				
				return $full_match;
			},
			$html
		);
	}
	
	return $html;
}

/**
 * Exclusion lists: match an asset URL against a user-defined list
 * (one entry per line or comma-separated; case-insensitive substring).
 */
function aero_asset_is_excluded( $url, $option_key ) {
	static $cache = array();
	if ( ! isset( $cache[ $option_key ] ) ) {
		$raw    = (string) get_option( $option_key, '' );
		$tokens = preg_split( '/[\r\n,]+/', $raw );
		$list   = array();
		foreach ( (array) $tokens as $t ) {
			$t = trim( $t );
			if ( '' !== $t ) {
				$list[] = strtolower( $t );
			}
		}
		$cache[ $option_key ] = $list;
	}
	if ( empty( $cache[ $option_key ] ) ) {
		return false;
	}
	$haystack = strtolower( $url );
	foreach ( $cache[ $option_key ] as $needle ) {
		if ( false !== strpos( $haystack, $needle ) ) {
			return true;
		}
	}
	return false;
}

function aero_is_local_url( $url ) {
	$site_url = site_url();
	$home_url = home_url();
	
	$url_normalized = str_replace( array( 'http://', 'https://' ), '//', $url );
	$site_url_normalized = str_replace( array( 'http://', 'https://' ), '//', $site_url );
	$home_url_normalized = str_replace( array( 'http://', 'https://' ), '//', $home_url );
	
	return ( strpos( $url_normalized, $site_url_normalized ) === 0 || 
	         strpos( $url_normalized, $home_url_normalized ) === 0 ||
	         strpos( $url, '/' ) === 0 );
}

function aero_html_minify_start() {
	if ( is_admin() || defined( 'DOING_AJAX' ) ) {
		return;
	}

	// Logged-in traffic is never stored by Batcache or the Edge Cache, so
	// running the full optimization pipeline for it is pure per-request
	// cost with zero cache benefit. Skip it (filterable for edge cases
	// where an operator wants logged-in output processed anyway).
	if ( is_user_logged_in() && ! apply_filters( 'aero_process_logged_in', false ) ) {
		return;
	}

	// Only GET responses are cacheable; POST/HEAD/etc. should pass through.
	if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}

	// Customizer preview markup must never be rewritten.
	if ( is_customize_preview() ) {
		return;
	}

	$GLOBALS['aero_ob_started'] = true;
	ob_start( 'aero_minify_html' );
}
add_action( 'template_redirect', 'aero_html_minify_start', 1 );

function aero_html_minify_end() {
	// Only close the buffer this plugin opened — blindly flushing here
	// could terminate another plugin's output buffer.
	if ( empty( $GLOBALS['aero_ob_started'] ) ) {
		return;
	}
	if ( ob_get_length() ) {
		ob_end_flush();
	}
}
add_action( 'shutdown', 'aero_html_minify_end', 9999 );

function aero_print_minify_comment() {
	global $aero_minify_comment;
	if (!empty($aero_minify_comment)) {
		echo $aero_minify_comment;
	}
}
add_action( 'shutdown', 'aero_print_minify_comment', 10000 );

// NOTE: Aero's original single "Clear Aero Cache" admin-bar link has been
// replaced by the "Aero Cache Control" dropdown registered in
// includes/cache-manager/admin-bar.php (Clear Aero Cache, Flush Object Cache,
// Purge Edge Cache, sequential Flush All, Cache Settings).
// The admin-post handler below is kept for backwards compatibility.

add_action( 'admin_post_aero_clear_cache_toolbar', 'aero_handle_toolbar_cache_clear' );
function aero_handle_toolbar_cache_clear() {
	if ( !current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}
	
	check_admin_referer( 'aero_clear_cache_toolbar' );
	
	aero_delete_files_in_directory( AERO_CSS_CACHE_DIR );
	aero_delete_files_in_directory( AERO_JS_CACHE_DIR );
	update_option( 'aero_minified_files', [] );
	
	wp_redirect( add_query_arg( 'aero_cache_cleared', '1', wp_get_referer() ) );
	exit;
}

add_action( 'admin_notices', 'aero_cache_cleared_notice' );
function aero_cache_cleared_notice() {
	if ( isset( $_GET['aero_cache_cleared'] ) && $_GET['aero_cache_cleared'] == '1' ) {
		if ( function_exists( 'aero_ui_is_aero_screen' ) && aero_ui_is_aero_screen() ) {
			echo '<div class="aero-notice aero-notice-success" style="margin:14px 20px 0 0;"><strong>Aero cache cleared!</strong></div>';
		} else {
			echo '<div class="notice notice-success is-dismissible"><p><strong>Aero cache cleared!</strong></p></div>';
		}
	}
}

function aero_minify_file( $file_url, $type ) {
	if ( strpos($file_url, '.min.') !== false ) {
		return false;
	}

	if ( !aero_is_local_url( $file_url ) ) {
		return false;
	}

	$cache_filetype_dir = ( $type === 'js' ? 'js/' : 'css/' );
	$cache_url = content_url() . '/cache/aero/';

	$file_path = aero_url_to_path( $file_url );
	
	if ( !$file_path || !file_exists( $file_path ) ) {
		return false;
	}
	
	$minified_file_name = md5($file_url) . '.' . $type;
	$minified_file_path = AERO_CACHE_DIR . $cache_filetype_dir . $minified_file_name;
	$minified_file_url = $cache_url . $cache_filetype_dir . $minified_file_name;
	$hash_file_path = $minified_file_path . '.hash';

	$current_hash = md5_file($file_path);
	
	if ( file_exists($minified_file_path) && file_exists($hash_file_path) ) {
		$saved_hash = file_get_contents( $hash_file_path );
		if ( $saved_hash === $current_hash ) {
			return $minified_file_url;
		}
	}

	try {
		if ( $type === 'css' ) {
			$minifier = new MatthiasMullie\Minify\CSS( $file_path );
		} elseif ( $type === 'js' ) {
			$minifier = new MatthiasMullie\Minify\JS( $file_path );
		} else {
			return false;
		}

		$minifier->minify( $minified_file_path );
		
		file_put_contents($hash_file_path, $current_hash);

		$stored_files = get_option( 'aero_minified_files', [] );
		$stored_files[$file_url] = $minified_file_url;
		update_option( 'aero_minified_files', $stored_files );

		return $minified_file_url;
	} catch ( Exception $e ) {
		return false;
	}
}

/**
 * Validate if content is actually CSS and not HTML or other content
 */
function aero_is_valid_css( $content ) {
	if ( empty( $content ) ) {
		return false;
	}
	
	// Trim whitespace
	$content = trim( $content );
	
	// Check for HTML tags - if present, it's not valid CSS
	if ( preg_match( '/<(!DOCTYPE|html|head|body|title|meta|script|div|span|p|a|img)/i', $content ) ) {
		return false;
	}
	
	// Check for common CSS patterns (at least one should be present)
	$css_patterns = array(
		'/\{[^}]*\}/',           // CSS blocks with braces
		'/@font-face/',          // Font declarations
		'/@media/',              // Media queries
		'/@import/',             // Import statements
		'/@charset/',            // Charset declarations
		'/\.[a-zA-Z]/',          // Class selectors
		'/#[a-zA-Z]/',           // ID selectors
		'/[a-zA-Z]+\s*\{/',      // Element selectors
	);
	
	$has_css_pattern = false;
	foreach ( $css_patterns as $pattern ) {
		if ( preg_match( $pattern, $content ) ) {
			$has_css_pattern = true;
			break;
		}
	}
	
	return $has_css_pattern;
}

function aero_url_to_path( $url ) {
	$url = strtok( $url, '?' );
	
	if ( strpos( $url, '/' ) === 0 && strpos( $url, '//' ) !== 0 ) {
		$url = site_url( $url );
	}
	
	$conversions = array(
		str_replace( home_url(), ABSPATH, $url ),
		str_replace( site_url(), ABSPATH, $url ),
		str_replace( content_url(), WP_CONTENT_DIR, $url ),
		str_replace( includes_url(), ABSPATH . WPINC, $url ),
		str_replace( plugins_url(), WP_PLUGIN_DIR, $url ),
	);
	
	foreach ( $conversions as $path ) {
		if ( file_exists( $path ) ) {
			return $path;
		}
	}
	
	return false;
}

?>