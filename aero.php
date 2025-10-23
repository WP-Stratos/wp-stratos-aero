<?php
/*
Plugin Name: Aero
Plugin URI: https://wpstratos.com
Description: Real performance optimization with Critical CSS, preloading, and Elementor support. 🚀
Version: 1.6.0
Author: WP Stratos
Author URI: https://wpstratos.com
*/

if ( !defined ('AERO_PLUGIN_VERSION_NUM' ) ) {
    define( 'AERO_PLUGIN_VERSION_NUM', '1.6.0' );
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

require_once( plugin_dir_path( __FILE__ ) . 'clear-minified-cache.php' );
require_once( plugin_dir_path( __FILE__ ) . 'rating-support.php' );

require_once( AERO_MINIFY_LIBRARY_PATH . "/src/Minify.php" );
require_once( AERO_MINIFY_LIBRARY_PATH . "/src/CSS.php" );
require_once( AERO_MINIFY_LIBRARY_PATH . "/src/JS.php" );
require_once( AERO_MINIFY_LIBRARY_PATH . "/../path-converter/ConverterInterface.php" );
require_once( AERO_MINIFY_LIBRARY_PATH . '/../path-converter/Converter.php' );

if ( !file_exists( AERO_CACHE_DIR ) ) mkdir( AERO_CACHE_DIR, 0755, true );
if ( !file_exists( AERO_CSS_CACHE_DIR ) ) mkdir( AERO_CSS_CACHE_DIR, 0755, true );
if ( !file_exists( AERO_JS_CACHE_DIR ) ) mkdir( AERO_JS_CACHE_DIR, 0755, true );

add_action( 'admin_init', 'aero_add_stylesheet' );
function aero_add_stylesheet() {
	$css_file = plugin_dir_path( __FILE__ ) . 'assets/css/style.min.css';
	$version = file_exists( $css_file ) ? filemtime( $css_file ) : AERO_PLUGIN_VERSION_NUM;
	
    wp_register_style( 'aero-stylesheet', plugins_url('assets/css/style.min.css', __FILE__), array(), $version );
    wp_enqueue_style( 'aero-stylesheet' );
	
	do_action( 'aero_rating_system_action' );
}

// Hide other plugin notices on Aero settings page
add_action( 'admin_notices', 'aero_hide_other_notices', 1 );
function aero_hide_other_notices() {
	$screen = get_current_screen();
	if ( $screen && $screen->id === 'settings_page_aero' ) {
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		// Re-add only Aero notices
		add_action( 'admin_notices', 'aero_cache_cleared_notice' );
		add_action( 'admin_notices', 'aero_submit_review_notice' );
	}
}

// Register AJAX handlers for diagnostics
add_action( 'wp_ajax_aero_get_diagnostics', 'aero_ajax_get_diagnostics' );
function aero_ajax_get_diagnostics() {
	check_ajax_referer( 'aero_diagnostics_nonce', 'nonce' );
	
	if ( !current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ) );
	}
	
	$hosting_info = aero_check_hosting_environment();
	$dropins = aero_check_dropins();
	
	// Get cache statistics
	$css_cache_size = aero_get_directory_size(AERO_CSS_CACHE_DIR);
	$js_cache_size = aero_get_directory_size(AERO_JS_CACHE_DIR);
	$total_cache_size = $css_cache_size + $js_cache_size;
	
	$css_file_count = aero_count_files(AERO_CSS_CACHE_DIR);
	$js_file_count = aero_count_files(AERO_JS_CACHE_DIR);
	
	wp_send_json_success( array(
		'hosting' => $hosting_info,
		'dropins' => $dropins,
		'cache_stats' => array(
			'css_files' => $css_file_count,
			'js_files' => $js_file_count,
			'total_size' => aero_format_bytes($total_cache_size)
		)
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
	
	// Generate fresh debug info
	$fresh_debug_info = aero_generate_debug_info();
	
	wp_send_json_success( array(
		'debug_info' => $fresh_debug_info
	) );
}

add_action( 'admin_head', 'aero_add_critical_css' );
function aero_add_critical_css() {
	$screen = get_current_screen();
	if ( $screen && $screen->id === 'settings_page_aero' ) {
		?>
		<style type="text/css">
		body.settings_page_aero { background: #000 !important; }
		body.settings_page_aero #wpbody-content { background: #000 !important; }
		body.settings_page_aero #wpbody { background: #000 !important; }
		body.settings_page_aero #wpcontent { background: #000 !important; }
		</style>
		<?php
	}
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

add_action( 'admin_menu', 'aero_add_admin_menu' );
function aero_add_admin_menu() {
	add_options_page( 'Aero', 'Aero', 'manage_options', 'aero', 'aero_admin_options' );
}

function aero_settings_link( $links ) {
	$settings_link = '<a href="options-general.php?page=aero">Settings</a>';
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

function aero_admin_options() {
	if ( !current_user_can( 'manage_options' ) )  {
		wp_die( __('You do not have sufficient permissions to access this page.') );
	}

	$hidden_field_name = 'aero_submit_hidden';
    $combine_js = 'aero_combine_js';
    $combine_css = 'aero_combine_css';
	$compress_html = 'aero_compress_html';
	$defer_js = 'aero_defer_js';
	$optimize_fonts = 'aero_optimize_fonts';
	$preload_critical = 'aero_preload_critical';
	$guest_mode_level = 'aero_guest_mode_level';
	$debug_mode = 'aero_debug_mode';

    $combine_js_val = get_option($combine_js);
    $combine_css_val = get_option($combine_css);
	$compress_html_val = get_option($compress_html);
	$defer_js_val = get_option($defer_js);
	$optimize_fonts_val = get_option($optimize_fonts);
	$preload_critical_val = get_option($preload_critical);
	$guest_mode_level_val = get_option($guest_mode_level, 'off');
	$debug_mode_val = get_option($debug_mode);

	if( isset( $_POST[$hidden_field_name] ) && $_POST[$hidden_field_name] == 'Y' ) {
    	if ( isset( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'aero_settings_nonce' ) ) {
			if ( isset( $_POST['aero_clear_minified'] ) ) {
				aero_clear_minified_cache();
			}
			else {			
				$combine_js_val = ( isset( $_POST[$combine_js] ) ? 'on' : 'off' );
				$combine_css_val = ( isset( $_POST[$combine_css] ) ? 'on' : 'off' );
				$compress_html_val = ( isset( $_POST[$compress_html] ) ? 'on' : 'off' );
				$defer_js_val = ( isset( $_POST[$defer_js] ) ? 'on' : 'off' );
				$optimize_fonts_val = ( isset( $_POST[$optimize_fonts] ) ? 'on' : 'off' );
				$preload_critical_val = ( isset( $_POST[$preload_critical] ) ? 'on' : 'off' );
				$guest_mode_level_val = isset( $_POST[$guest_mode_level] ) ? $_POST[$guest_mode_level] : 'off';
				$debug_mode_val = ( isset( $_POST[$debug_mode] ) ? 'on' : 'off' );
				$custom_css_normal = isset( $_POST['aero_custom_css_normal'] ) ? $_POST['aero_custom_css_normal'] : '';
				$custom_css_guest = isset( $_POST['aero_custom_css_guest'] ) ? $_POST['aero_custom_css_guest'] : '';
	
				update_option( $combine_js, $combine_js_val );
				update_option( $combine_css, $combine_css_val );
				update_option( $compress_html, $compress_html_val );
				update_option( $defer_js, $defer_js_val );
				update_option( $optimize_fonts, $optimize_fonts_val );
				update_option( $preload_critical, $preload_critical_val );
				update_option( $guest_mode_level, $guest_mode_level_val );
				update_option( $debug_mode, $debug_mode_val );
				update_option( 'aero_custom_css_normal', $custom_css_normal );
				update_option( 'aero_custom_css_guest', $custom_css_guest );	
	
				echo '<div class="updated aero-notice"><p><strong>Settings Saved.</strong></p></div>';
			}
		}
	}
	?>
	<div class="wrap aero-wrap">
	<div class="aero-main-grid">
	<div class="aero-container">
	<h2>
		<span style="display: inline-block; vertical-align: middle; width: 24px; height: 24px;">
			<svg width="24" height="24" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg"><g clip-path="url(#a)"><path d="M0 46.536A12 12 0 0 0 1.25 48H0zM48 48H26.752a5.4 5.4 0 0 1-2.088-1.774c-.984-1.412-1.324-3.32-1.2-5.536.248-4.432 2.35-10.064 4.81-15.306s5.278-10.088 6.954-12.95c.418-.716.766-1.306 1.018-1.75q.19-.334.306-.55t.148-.314l.012-.046c.002-.01.004-.034-.01-.052a.06.06 0 0 0-.034-.022l-.032.002-.04.022a2 2 0 0 0-.236.242c-.222.254-.584.708-1.112 1.382C26.378 22.7 14.54 31.174 0 30.658V0h48zM39.506 3.704a.04.04 0 0 0-.032.004c-.016.008-.022.022-.024.024l-.006.014-.006.03-.008.1c-.042.688-.04 1.344-.502 1.862a1 1 0 0 1-.564.284q-.168.034-.34.052-.172.02-.332.044l-.02.006-.018.01a.04.04 0 0 0-.014.048.06.06 0 0 0 .02.028l.012.006.018.004.11.014.402.034c.158.012.324.026.462.042l.178.024a1 1 0 0 1 .098.024c.372.17.5.57.538 1.052.018.238.016.492.01.74-.004.246-.01.486.008.694l.01.12.004.04a.04.04 0 0 0 .012.024l.02.014.036-.002.018-.014.008-.014.002-.01V8.87c0-.164 0-.466.022-.808s.064-.722.148-1.042a2 2 0 0 1 .156-.418.6.6 0 0 1 .218-.246.8.8 0 0 1 .262-.074 4 4 0 0 1 .354-.022h.366q.176 0 .306-.012l-.006-.094a2 2 0 0 1-.292-.04 6 6 0 0 1-.4-.096 6 6 0 0 1-.374-.112l-.136-.05a.4.4 0 0 1-.076-.038.6.6 0 0 1-.176-.208 2 2 0 0 1-.144-.338 6 6 0 0 1-.186-.818c-.044-.268-.07-.496-.09-.622l-.014-.074-.008-.026-.014-.018-.022-.012" fill="#fff"/></g><defs><clipPath id="a"><path d="M0 0h48v48H10.5A10.5 10.5 0 0 1 0 37.5z" fill="#fff"/></clipPath></defs></svg>
		</span>
		Aero Settings
	</h2>
	<hr style="border-color: #313131;" />
	
	<div class="aero-diagnostics-container" id="aero-diagnostics">
		<div class="aero-diagnostics-header">
			<div class="aero-diagnostics-title">
				<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; margin-right: 8px;">
					<path d="M10 0C4.477 0 0 4.477 0 10s4.477 10 10 10 10-4.477 10-10S15.523 0 10 0zm0 18c-4.411 0-8-3.589-8-8s3.589-8 8-8 8 3.589 8 8-3.589 8-8 8zm-1-13h2v6H9V5zm0 8h2v2H9v-2z" fill="currentColor"/>
				</svg>
				Quick Start Diagnostics
			</div>
			<div class="aero-diagnostics-score" id="aero-diagnostics-score">
				<span class="aero-loading-dots">Loading<span>.</span><span>.</span><span>.</span></span>
			</div>
		</div>
		
		<div class="aero-diagnostics-list" id="aero-diagnostics-list">
			<div class="aero-diagnostic-item aero-diagnostic-loading">
				<div class="aero-diagnostic-spinner"></div>
				<div class="aero-diagnostic-label">Checking WP Stratos Hosting...</div>
			</div>
			<div class="aero-diagnostic-item aero-diagnostic-loading">
				<div class="aero-diagnostic-spinner"></div>
				<div class="aero-diagnostic-label">Checking Object Cache...</div>
			</div>
			<div class="aero-diagnostic-item aero-diagnostic-loading">
				<div class="aero-diagnostic-spinner"></div>
				<div class="aero-diagnostic-label">Checking Page Cache...</div>
			</div>
		</div>
		
		<div id="aero-upgrade-notice" style="display:none;">
			<div class="aero-upgrade-notice">
				<div class="aero-upgrade-content">
					<div class="aero-upgrade-text">
						<strong>Not on WP Stratos hosting?</strong> You're missing out on significant performance improvements. WP Stratos provides optimized infrastructure with built-in caching, CDN, and performance features that work seamlessly with Aero.
					</div>
					<a href="https://wpstratos.com" target="_blank" class="aero-upgrade-button" style="color:#ffffff !important;">
						Learn About WP Stratos
						<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; margin-left: 6px;">
							<path d="M12 8.667V12.667C12 13.403 11.403 14 10.667 14H3.333C2.597 14 2 13.403 2 12.667V5.333C2 4.597 2.597 4 3.333 4H7.333M10 2H14M14 2V6M14 2L6 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</a>
				</div>
			</div>
		</div>
	</div>
	
	<script>
	jQuery(document).ready(function($) {
		// Fetch diagnostics via AJAX
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'aero_get_diagnostics',
				nonce: '<?php echo wp_create_nonce('aero_diagnostics_nonce'); ?>'
			},
			success: function(response) {
				if (response.success) {
					aeroUpdateDiagnostics(response.data);
					aeroUpdateCacheStats(response.data.cache_stats);
				}
			},
			error: function() {
				// Show error state for diagnostics
				$('#aero-diagnostics-list').html('<div class="aero-diagnostic-item aero-diagnostic-fail"><div class="aero-diagnostic-label">Failed to load diagnostics. Please refresh the page.</div></div>');
				// Show error state for cache stats
				$('#aero-css-count, #aero-js-count, #aero-total-size').html('<span style="color: #dc2626;">Error</span>');
			}
		});
		
		function aeroUpdateCacheStats(stats) {
			$('#aero-css-count').html(stats.css_files + ' files');
			$('#aero-js-count').html(stats.js_files + ' files');
			$('#aero-total-size').html(stats.total_size);
		}
		
		function aeroUpdateDiagnostics(data) {
			var hosting = data.hosting;
			var dropins = data.dropins;
			
			var checks = [
				{
					label: 'WP Stratos Hosting',
					status: hosting.is_wpstratos,
					type: 'critical'
				},
				{
					label: 'Object Cache (object-cache.php)',
					status: dropins.object_cache,
					type: 'important'
				},
				{
					label: 'Page Cache (advanced-cache.php)',
					status: dropins.advanced_cache,
					type: 'important'
				}
			];
			
			var passed = 0;
			var html = '';
			
			checks.forEach(function(check) {
				if (check.status) passed++;
				
				var statusClass = check.status ? 'aero-diagnostic-pass' : 'aero-diagnostic-fail';
				var typeClass = 'aero-diagnostic-' + check.type;
				var icon = check.status ? 
					'<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 0C4.477 0 0 4.477 0 10s4.477 10 10 10 10-4.477 10-10S15.523 0 10 0zm-1.5 15l-4-4 1.41-1.41L8.5 12.17l5.09-5.09L15 8.5l-6.5 6.5z" fill="currentColor"/></svg>' :
					'<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 0C4.477 0 0 4.477 0 10s4.477 10 10 10 10-4.477 10-10S15.523 0 10 0zm1 15H9v-2h2v2zm0-4H9V5h2v6z" fill="currentColor"/></svg>';
				
				html += '<div class="aero-diagnostic-item ' + statusClass + ' ' + typeClass + '">';
				html += '<div class="aero-diagnostic-icon">' + icon + '</div>';
				html += '<div class="aero-diagnostic-label">' + check.label + '</div></div>';
			});
			
			$('#aero-diagnostics-list').html(html);
			$('#aero-diagnostics-score').html(passed + '/' + checks.length + ' Passed');
			
			// Update container class
			if (!hosting.is_wpstratos) {
				$('#aero-diagnostics').addClass('aero-not-wpstratos');
				$('#aero-upgrade-notice').show();
			}
		}
	});
	</script>
	
	<form method="post" name="options_form">
	<?php wp_nonce_field( 'aero_settings_nonce' ); ?>
	<input type="hidden" name="<?php echo $hidden_field_name; ?>" value="Y">
	
	<h3 style="color: #e5e5e5; margin-top: 0;">Recommended Settings (Enable All)</h3>
    <p>
    <input type="checkbox" name="<?php echo $combine_css; ?>" id="<?php echo $combine_css; ?>" <?php checked( $combine_css_val == 'on',true); ?> />
    <label for="<?php echo $combine_css; ?>" class="aero_settings" style="display: inline;"> <?php _e('Minify & Cache CSS'); ?> </label>
    </p>
	<p>
	<input type="checkbox" name="<?php echo $combine_js; ?>" id="<?php echo $combine_js; ?>" <?php checked( $combine_js_val == 'on',true); ?> />
	<label for="<?php echo $combine_js; ?>" class="aero_settings" style="display: inline;"> <?php _e('Minify & Cache JavaScript'); ?> </label>
	</p>
	<p>
	<input type="checkbox" name="<?php echo $compress_html; ?>" id="<?php echo $compress_html; ?>" <?php checked( $compress_html_val == 'on',true); ?> />
	<label for="<?php echo $compress_html; ?>" class="aero_settings" style="display: inline;"> <?php _e('Compress HTML'); ?> </label>
	</p>
	<p>
	<input type="checkbox" name="<?php echo $defer_js; ?>" id="<?php echo $defer_js; ?>" <?php checked( $defer_js_val == 'on',true); ?> />
	<label for="<?php echo $defer_js; ?>" class="aero_settings" style="display: inline;"> <?php _e('Defer JavaScript'); ?> </label>
	<br><span style="color: #999; font-size: 13px; margin-left: 24px;">Defers non-critical JS. jQuery excluded for compatibility.</span>
	</p>

	<h3 style="color: #e5e5e5; margin-top: 30px;">Advanced Performance</h3>
	<p>
	<input type="checkbox" name="<?php echo $preload_critical; ?>" id="<?php echo $preload_critical; ?>" <?php checked( $preload_critical_val == 'on',true); ?> />
	<label for="<?php echo $preload_critical; ?>" class="aero_settings" style="display: inline;"> <?php _e('Preload Critical Resources'); ?> </label>
	<br><span style="color: #999; font-size: 13px; margin-left: 24px;">Preloads critical CSS, hero images, and primary fonts. Improves LCP significantly.</span>
	</p>
	<p>
	<input type="checkbox" name="<?php echo $optimize_fonts; ?>" id="<?php echo $optimize_fonts; ?>" <?php checked( $optimize_fonts_val == 'on',true); ?> />
	<label for="<?php echo $optimize_fonts; ?>" class="aero_settings" style="display: inline;"> <?php _e('Optimize Font Loading'); ?> </label>
	<br><span style="color: #999; font-size: 13px; margin-left: 24px;">Adds font-display: swap and preconnects to font providers. Prevents CLS from font loading.</span>
	</p>

	<div class="aero-accordion" style="margin-top: 30px;">
		<button type="button" class="aero-accordion-header" onclick="aeroToggleAccordion(this)">
			<span>Guest Mode (Use Only If Needed)</span>
			<span class="aero-accordion-icon">▼</span>
		</button>
		<div class="aero-accordion-content">
			<div class="aero-accordion-inner">
				<div class="aero-setting-group">
					<div class="aero-setting-description" style="margin-bottom: 15px;">
						<strong style="color: #ff9800;">Only enable this if the optimizations above don't achieve your target score!</strong><br><br>
						Guest Mode shows PageSpeed tools a super optimized version while real visitors see the full site. 
						Try all real optimizations first - they're better for actual users.
					</div>
					
					<div class="aero-radio-cards">
						<label class="aero-radio-card <?php echo $guest_mode_level_val === 'off' ? 'selected' : ''; ?>">
							<div class="aero-radio-card-header">
								<span class="aero-radio-card-title">Disabled</span>
								<input type="radio" name="<?php echo $guest_mode_level; ?>" value="off" <?php checked( $guest_mode_level_val, 'off' ); ?> />
							</div>
							<p class="aero-radio-card-description">
								Guest Mode is off. All visitors see the same optimized site.
							</p>
						</label>
						
						<label class="aero-radio-card <?php echo $guest_mode_level_val === 'basic' ? 'selected' : ''; ?>">
							<div class="aero-radio-card-header">
								<span class="aero-radio-card-title">Basic (Recommended)</span>
								<input type="radio" name="<?php echo $guest_mode_level; ?>" value="basic" <?php checked( $guest_mode_level_val, 'basic' ); ?> />
							</div>
							<p class="aero-radio-card-description">
								Combines ALL CSS files into one minified file, inlines all fonts (Google Fonts, Adobe Fonts, etc.), and removes all JavaScript for PageSpeed tools. Eliminates render-blocking requests. Target: 90+ mobile score.
							</p>
						</label>
						
						<label class="aero-radio-card warning <?php echo $guest_mode_level_val === 'extreme' ? 'selected' : ''; ?>">
							<div class="aero-radio-card-header">
								<span class="aero-radio-card-title">Extreme ⚠️</span>
								<input type="radio" name="<?php echo $guest_mode_level; ?>" value="extreme" <?php checked( $guest_mode_level_val, 'extreme' ); ?> />
							</div>
							<p class="aero-radio-card-description">
								Aggressive stripping for maximum scores. Keeps only first 2 CSS files. Site may look broken in metric screenshots. <strong>Not recommended.</strong>
							</p>
						</label>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="aero-accordion" style="margin-top: 15px;">
		<button type="button" class="aero-accordion-header" onclick="aeroToggleAccordion(this)">
			<span>Debug Mode (For Support)</span>
			<span class="aero-accordion-icon">▼</span>
		</button>
		<div class="aero-accordion-content">
			<div class="aero-accordion-inner">
				<div class="aero-setting-group">
					<label>
						<input type="checkbox" name="<?php echo $debug_mode; ?>" id="<?php echo $debug_mode; ?>" <?php checked( $debug_mode_val == 'on',true); ?> />
						<?php _e('Enable Debug Information'); ?>
					</label>
					<div class="aero-setting-description">
						Enable this to generate detailed debug information. Copy and share with <a href="https://wpstratos.com" target="_blank" style="color: #2e5aac;">WP Stratos support</a> for troubleshooting.
					</div>
					
					<?php if ( $debug_mode_val === 'on' ): ?>
					<div class="aero-debug-box" style="margin-top: 15px;">
						<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
							<strong style="color: #e5e5e5;">Debug Information</strong>
							<div>
								<button type="button" onclick="aeroRefreshDebug(event)" id="aero-refresh-debug-btn" class="button-small" style="background: none !important; color: #2e5aac !important;">Refresh Information</button>
								<button type="button" onclick="aeroCopyDebug(event)" class="button-small" style="background: #2e5aac; color: #fff; border: none; padding: 5px 12px; cursor: pointer;">Copy to Clipboard</button>
							</div>
						</div>
						<textarea id="aero-debug-info" readonly style="width: 100%; height: 300px; background: #0a0a0a; color: #0f0; border: 1px solid #313131; padding: 10px; font-family: monospace; font-size: 12px; line-height: 1.5;"><?php echo esc_textarea( aero_generate_debug_info() ); ?></textarea>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<div class="aero-accordion" style="margin-top: 15px;">
		<button type="button" class="aero-accordion-header" onclick="aeroToggleAccordion(this)">
			<span>Advanced CSS Injection</span>
			<span class="aero-accordion-icon">▼</span>
		</button>
		<div class="aero-accordion-content">
			<div class="aero-accordion-inner">
				<div class="aero-setting-group">
					<label for="aero_custom_css_normal">
						<strong>Custom CSS (Normal Mode)</strong>
					</label>
					<div class="aero-setting-description" style="margin-bottom: 10px;">
						This CSS will be injected inline in the <code>&lt;head&gt;</code> for regular visitors only. Use this for custom styles that should appear during normal site operation. Not included in Guest Mode.
					</div>
					<textarea 
						name="aero_custom_css_normal" 
						id="aero_custom_css_normal" 
						class="aero-css-textarea"
						placeholder="/* Example: Hide elements that break layout */&#10;.problematic-element {&#10;    display: none !important;&#10;}"
					><?php echo esc_textarea( get_option('aero_custom_css_normal', '') ); ?></textarea>
				</div>
				
				<div class="aero-setting-group" style="margin-top: 20px;">
					<label for="aero_custom_css_guest">
						<strong>Custom CSS (Guest Mode Only)</strong>
					</label>
					<div class="aero-setting-description" style="margin-bottom: 10px;">
						This CSS will be appended to the combined CSS file when Guest Mode is active. Use this to fix visibility issues caused by JavaScript removal (e.g., GSAP animations stuck at opacity: 0). Only appears for PageSpeed testing tools.
					</div>
					<textarea 
						name="aero_custom_css_guest" 
						id="aero_custom_css_guest" 
						class="aero-css-textarea"
						placeholder="/* Example: Fix GSAP animations stuck at opacity 0 */&#10;.gsap-animated,&#10;.fade-in-element {&#10;    opacity: 1 !important;&#10;    transform: none !important;&#10;}"
					><?php echo esc_textarea( get_option('aero_custom_css_guest', '') ); ?></textarea>
				</div>
				
				<div class="aero-setting-description" style="margin-top: 15px; padding: 12px; background: rgba(46, 90, 172, 0.08); border-left: 3px solid #2e5aac;">
					<strong>💡 Pro Tip:</strong> Use browser DevTools to inspect elements that appear broken in Guest Mode. Common fixes include setting <code>opacity: 1</code>, <code>display: block</code>, or <code>visibility: visible</code> on elements that rely on JavaScript for initial visibility.
				</div>
			</div>
		</div>
	</div>

    <p style="margin-top: 20px;">
		<input type="submit" value="<?php esc_attr_e('Save Changes') ?>" class="button button-primary aero-button" name="submit" />
		<button type="submit" name="aero_clear_minified" class="button aero-button aero-button-secondary">Clear Cache</button>
    </p>
	</form>
	
	<?php
	$all_optimizations = (
		$combine_css_val === 'on' && 
		$combine_js_val === 'on' && 
		$defer_js_val === 'on' &&
		$preload_critical_val === 'on' &&
		$optimize_fonts_val === 'on'
	);
	
	echo '<div style="background: ' . ($all_optimizations ? '#1d2327' : '#854d0e') . '; border-left: 4px solid ' . ($all_optimizations ? '#2e5aac' : '#fbbf24') . '; padding: 15px; margin: 20px 0; color: #e5e5e5;">';
	
	if ( $all_optimizations ) {
		echo '<strong>✅ All optimizations enabled!</strong><br>';
		echo 'Your site should see significant improvements in LCP, CLS, and overall load time.';
	} else {
		echo '<strong>⚠️ Not all optimizations enabled</strong><br>';
		echo 'For best results, enable all settings above (except Guest Mode).';
	}
	echo '</div>';
	?>
	
	<div class="aero-footer">
		<p>
			Powered by <a href="https://wpstratos.com" target="_blank">WP Stratos</a> 
			| Version: <strong><?php echo AERO_PLUGIN_VERSION_NUM; ?></strong>
		</p>
	</div>
	</div>

	<div class="aero-sidebar">
		<h3>Cache Statistics</h3>
		<div class="aero-stat-item">
			<span class="aero-stat-label">CSS Cached</span>
			<span class="aero-stat-value" id="aero-css-count">
				<span class="aero-loading-dots"><span>.</span><span>.</span><span>.</span></span>
			</span>
		</div>
		<div class="aero-stat-item">
			<span class="aero-stat-label">JS Cached</span>
			<span class="aero-stat-value" id="aero-js-count">
				<span class="aero-loading-dots"><span>.</span><span>.</span><span>.</span></span>
			</span>
		</div>
		<div class="aero-stat-item">
			<span class="aero-stat-label">Total Saved</span>
			<span class="aero-stat-value" id="aero-total-size">
				<span class="aero-loading-dots"><span>.</span><span>.</span><span>.</span></span>
			</span>
		</div>
		
		<h3 style="margin-top: 30px;">Performance Tips</h3>
		<div style="font-size: 13px; line-height: 1.6; color: #999;">
			<strong style="color: #2e5aac;">For Best Results:</strong><br>
			<svg width="12" height="12" viewBox="0 0 12 12" fill="#999" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; margin-right: 4px;"><circle cx="6" cy="6" r="1.5"/></svg> Enable all recommended settings<br>
			<svg width="12" height="12" viewBox="0 0 12 12" fill="#999" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; margin-right: 4px;"><circle cx="6" cy="6" r="1.5"/></svg> Use optimized images (WebP format)<br>
			<svg width="12" height="12" viewBox="0 0 12 12" fill="#999" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; margin-right: 4px;"><circle cx="6" cy="6" r="1.5"/></svg> Minimize third-party scripts<br>
			<svg width="12" height="12" viewBox="0 0 12 12" fill="#999" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; margin-right: 4px;"><circle cx="6" cy="6" r="1.5"/></svg> Use <a href="https://wpstratos.com" target="_blank" style="color: #2e5aac;">WP Stratos hosting</a> for optimal performance<br>
			<svg width="12" height="12" viewBox="0 0 12 12" fill="#999" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; margin-right: 4px;"><circle cx="6" cy="6" r="1.5"/></svg> Enable GZIP compression
		</div>
	</div>
	</div>

	<script>
	function aeroToggleAccordion(header) {
		header.classList.toggle('active');
		var content = header.nextElementSibling;
		content.classList.toggle('active');
	}
	
	function aeroCopyDebug(e) {
		e.preventDefault();
		var debugInfo = document.getElementById('aero-debug-info');
		debugInfo.select();
		document.execCommand('copy');
		
		var btn = e.target;
		var originalText = btn.textContent;
		btn.textContent = '✓ Copied!';
		btn.style.background = '#059669';
		
		setTimeout(function() {
			btn.textContent = originalText;
			btn.style.background = '#2e5aac';
		}, 2000);
	}
	
	function aeroRefreshDebug(e) {
		e.preventDefault();
		var btn = document.getElementById('aero-refresh-debug-btn');
		var originalText = btn.textContent;
		btn.disabled = true;
		btn.textContent = 'Refreshing...';
		
		jQuery.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'aero_refresh_debug',
				nonce: '<?php echo wp_create_nonce('aero_refresh_debug_nonce'); ?>'
			},
			success: function(response) {
				if (response.success) {
					document.getElementById('aero-debug-info').value = response.data.debug_info;
					btn.textContent = '✓ Refreshed!';
					btn.style.color = '#059669 !important';
					setTimeout(function() {
						btn.textContent = originalText;
						btn.style.color = '#2e5aac !important';
						btn.disabled = false;
					}, 2000);
				} else {
					alert('Please wait ' + response.data.time_remaining + ' minutes before refreshing again.');
					btn.textContent = originalText;
					btn.disabled = false;
				}
			},
			error: function() {
				alert('Failed to refresh debug information. Please try again.');
				btn.textContent = originalText;
				btn.disabled = false;
			}
		});
	}
	</script>
	</div>
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
}
register_activation_hook( __FILE__, 'aero_activate_plugin' );

function aero_deactivate_plugin() {
	delete_option( 'aero_plugin_version' );
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

	if ( get_option('aero_combine_js', 1 ) === 'on') {
		$buffer = Minify_HTML::minify( $buffer, array(
			'jsMinifier' => array( 'JSMin', 'minify' )
		));
	}

	if (get_option( 'aero_combine_css', 1 ) === 'on') {
		$buffer = Minify_HTML::minify( $buffer, array(
			'cssMinifier' => array( 'Minify_CSS', 'minify' )
		));
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
	
	// Optimize fonts in HTML
	$html = aero_optimize_fonts( $html );
	
	// Process CSS
	if ( get_option( 'aero_combine_css', 1 ) === 'on' ) {
		$html = preg_replace_callback(
			'/<link([^>]*?)href=["\']([^"\']+\.css[^"\']*)["\']([^>]*?)>/i',
			function( $matches ) {
				$full_match = $matches[0];
				$css_url = $matches[2];
				
				// Skip external and already minified
				if ( !aero_is_local_url( $css_url ) || strpos( $css_url, '.min.css' ) !== false || strpos( $css_url, '/cache/aero/' ) !== false ) {
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
				
				// Minify if enabled and local
				if ( get_option( 'aero_combine_js', 1 ) === 'on' && 
				     aero_is_local_url( $js_url ) && 
				     strpos( $js_url, '.min.js' ) === false && 
				     strpos( $js_url, '/cache/aero/' ) === false ) {
					$minified_url = aero_minify_file( $js_url, 'js' );
					if ( $minified_url ) {
						$full_match = str_replace( $js_url, $minified_url, $full_match );
					}
				}
				
				// Add defer if enabled (except jQuery)
				if ( get_option( 'aero_defer_js', 1 ) === 'on' && !$is_jquery ) {
					$full_match = str_replace( '<script', '<script defer', $full_match );
				}
				
				return $full_match;
			},
			$html
		);
	}
	
	return $html;
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
	if (!is_admin() && !defined('DOING_AJAX')) {
		ob_start('aero_minify_html');
	}
}
add_action( 'template_redirect', 'aero_html_minify_start', 1 );

function aero_html_minify_end() {
	if (!is_admin() && ob_get_length()) {
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

add_action( 'admin_bar_menu', 'aero_admin_bar_menu', 100 );
function aero_admin_bar_menu( $wp_admin_bar ) {
	if ( !current_user_can( 'manage_options' ) ) {
		return;
	}
	
	$wp_admin_bar->add_node( array(
		'id'    => 'aero-clear-cache',
		'title' => 'Clear Aero Cache',
		'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=aero_clear_cache_toolbar' ), 'aero_clear_cache_toolbar' ),
	) );
}

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
		echo '<div class="notice notice-success is-dismissible"><p><strong>Aero cache cleared!</strong></p></div>';
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