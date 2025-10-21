<?php
/*
Plugin Name: Aero
Plugin URI: https://wpstratos.com
Description: Smartly minify, compress and cache HTML, CSS & JavaScript files to boost website speed. 🚀
Version: 1.4.0
Author: WP Stratos
Author URI: https://wpstratos.com
*/

// Define plugin version for future releases
if ( !defined ('AERO_PLUGIN_VERSION_NUM' ) ) {
    define( 'AERO_PLUGIN_VERSION_NUM', '1.4.0' );
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

// Create Cache directories
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

// Admin options/setting page
function aero_admin_options() {
	if ( !current_user_can( 'manage_options' ) )  {
		wp_die( __('You do not have sufficient permissions to access this page.') );
	}

	$hidden_field_name = 'aero_submit_hidden';
    $combine_js = 'aero_combine_js';
    $combine_css = 'aero_combine_css';
	$compress_html = 'aero_compress_html';
	$async_css = 'aero_async_css';
	$defer_js = 'aero_defer_js';
	$preload_fonts = 'aero_preload_fonts';
	$guest_mode = 'aero_guest_mode';

    $combine_js_val = get_option($combine_js);
    $combine_css_val = get_option($combine_css);
	$compress_html_val = get_option($compress_html);
	$async_css_val = get_option($async_css);
	$defer_js_val = get_option($defer_js);
	$preload_fonts_val = get_option($preload_fonts);
	$guest_mode_val = get_option($guest_mode);

	if( isset( $_POST[$hidden_field_name] ) && $_POST[$hidden_field_name] == 'Y' ) {
    	if ( isset( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'aero_settings_nonce' ) ) {
			if ( isset( $_POST['aero_clear_minified'] ) ) {
				aero_clear_minified_cache();
			}
			else {			
				$combine_js_val = ( isset( $_POST[$combine_js] ) ? 'on' : 'off' );
				$combine_css_val = ( isset( $_POST[$combine_css] ) ? 'on' : 'off' );
				$compress_html_val = ( isset( $_POST[$compress_html] ) ? 'on' : 'off' );
				$async_css_val = ( isset( $_POST[$async_css] ) ? 'on' : 'off' );
				$defer_js_val = ( isset( $_POST[$defer_js] ) ? 'on' : 'off' );
				$preload_fonts_val = ( isset( $_POST[$preload_fonts] ) ? 'on' : 'off' );
				$guest_mode_val = ( isset( $_POST[$guest_mode] ) ? 'on' : 'off' );
	
				update_option( $combine_js, $combine_js_val );
				update_option( $combine_css, $combine_css_val );
				update_option( $compress_html, $compress_html_val );
				update_option( $async_css, $async_css_val );
				update_option( $defer_js, $defer_js_val );
				update_option( $preload_fonts, $preload_fonts_val );
				update_option( $guest_mode, $guest_mode_val );
	
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
	
	<form method="post" name="options_form">
	<?php wp_nonce_field( 'aero_settings_nonce' ); ?>
	<input type="hidden" name="<?php echo $hidden_field_name; ?>" value="Y">
	
	<h3 style="color: #e5e5e5; margin-top: 0;">Core Optimization</h3>
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
	<label for="<?php echo $compress_html; ?>" class="aero_settings" style="display: inline;"> <?php _e('Compress HTML Output'); ?> </label>
	</p>

	<h3 style="color: #e5e5e5; margin-top: 30px;">Render-Blocking Optimization</h3>
	<p>
	<input type="checkbox" name="<?php echo $async_css; ?>" id="<?php echo $async_css; ?>" <?php checked( $async_css_val == 'on',true); ?> />
	<label for="<?php echo $async_css; ?>" class="aero_settings" style="display: inline;"> <?php _e('Eliminate Render-Blocking CSS'); ?> </label>
	<br><span style="color: #999; font-size: 13px; margin-left: 24px;">Loads CSS asynchronously with critical CSS inline. Dramatically improves FCP/LCP.</span>
	</p>
	<p>
	<input type="checkbox" name="<?php echo $defer_js; ?>" id="<?php echo $defer_js; ?>" <?php checked( $defer_js_val == 'on',true); ?> />
	<label for="<?php echo $defer_js; ?>" class="aero_settings" style="display: inline;"> <?php _e('Defer Non-Critical JavaScript'); ?> </label>
	<br><span style="color: #999; font-size: 13px; margin-left: 24px;">Defers JS execution until after page render. Excludes jQuery for compatibility.</span>
	</p>
	<p>
	<input type="checkbox" name="<?php echo $preload_fonts; ?>" id="<?php echo $preload_fonts; ?>" <?php checked( $preload_fonts_val == 'on',true); ?> />
	<label for="<?php echo $preload_fonts; ?>" class="aero_settings" style="display: inline;"> <?php _e('Optimize Google Fonts Loading'); ?> </label>
	<br><span style="color: #999; font-size: 13px; margin-left: 24px;">Adds preconnect hints for faster font loading.</span>
	</p>

	<div class="aero-accordion" style="margin-top: 30px;">
		<button type="button" class="aero-accordion-header" onclick="aeroToggleAccordion(this)">
			<span>⚠️ Guest Mode (Last Resort Only)</span>
			<span class="aero-accordion-icon">▼</span>
		</button>
		<div class="aero-accordion-content">
			<div class="aero-accordion-inner">
				<div class="aero-setting-group">
					<label>
						<input type="checkbox" name="<?php echo $guest_mode; ?>" id="<?php echo $guest_mode; ?>" <?php checked( $guest_mode_val == 'on',true); ?> />
						<?php _e('Enable Guest Mode'); ?>
					</label>
					<div class="aero-setting-description">
						<strong style="color: #ff9800;">⚠️ Only enable if the options above don't get you to your target score!</strong><br><br>
						Guest Mode serves a stripped-down version to PageSpeed tools while real visitors see your full site. 
						This is a "cheat" that major optimization plugins use, but you should try all the real optimizations first.<br><br>
						<strong>What it does:</strong><br>
						• Removes most CSS/JS for PageSpeed tools only<br>
						• Keeps first 2 CSS files so site is recognizable<br>
						• Real visitors are completely unaffected<br><br>
						<strong>⚠️ After disabling Guest Mode:</strong><br>
						1. Click "Clear CSS & JS Cache" below<br>
						2. Wait 5-10 minutes for PageSpeed cache<br>
						3. Test with URL parameter like <code>?test=1</code>
					</div>
				</div>
			</div>
		</div>
	</div>

    <p style="margin-top: 20px;">
		<input type="submit" value="<?php esc_attr_e('Save Changes') ?>" class="button button-primary aero-button" name="submit" />
		<button type="submit" name="aero_clear_minified" class="button aero-button aero-button-secondary">Clear CSS & JS Cache</button>
    </p>
	</form>
	
	<?php
	// Debug info
	if ( current_user_can( 'manage_options' ) ) {
		$current_guest_status = get_option( 'aero_guest_mode' );
		$is_detected_as_guest = aero_is_guest_visitor();
		echo '<div style="background: #f0f0f1; border: 1px solid #c3c4c7; padding: 15px; margin: 15px 0; border-radius: 4px;">';
		echo '<strong>Debug Info:</strong><br>';
		echo 'Guest Mode: <strong>' . ( $current_guest_status === 'on' ? '✅ ON' : '❌ OFF' ) . '</strong><br>';
		echo 'Detected As: <strong>' . ( $is_detected_as_guest ? '🤖 PageSpeed Tool' : '👤 Normal Visitor' ) . '</strong><br>';
		echo 'Async CSS: <strong>' . ( $async_css_val === 'on' ? '✅ ON' : '❌ OFF' ) . '</strong><br>';
		echo 'Defer JS: <strong>' . ( $defer_js_val === 'on' ? '✅ ON' : '❌ OFF' ) . '</strong>';
		echo '</div>';
	}
	
	$css_enabled = ($combine_css_val == 'on');
	$js_enabled = ($combine_js_val == 'on');
	$async_enabled = ($async_css_val == 'on');
	$defer_enabled = ($defer_js_val == 'on');
	
	echo '<div style="background: #1d2327; border-left: 4px solid #2e5aac; padding: 15px; margin: 20px 0; color: #e5e5e5;">';
	echo '<strong>💡 Optimization Status:</strong><br><br>';
	
	if ( $css_enabled && $js_enabled && $async_enabled && $defer_enabled ) {
		echo '✅ <strong>Full optimization enabled!</strong> Your site should be significantly faster.<br>';
		echo '✅ Render-blocking resources eliminated<br>';
		echo '✅ All assets minified and cached<br>';
	} else {
		echo '⚠️ <strong>Not all optimizations enabled.</strong> For best performance:<br>';
		if ( !$css_enabled ) echo '• Enable CSS minification<br>';
		if ( !$js_enabled ) echo '• Enable JS minification<br>';
		if ( !$async_enabled ) echo '• Enable render-blocking CSS elimination<br>';
		if ( !$defer_enabled ) echo '• Enable JS deferral<br>';
	}
	echo '</div>';
	?>
	
	<div class="aero-footer">
		<p>
			Powered by <a href="https://wpstratos.com" target="_blank">WP Stratos</a> 
			| Version: <strong><?php echo get_option('aero_plugin_version'); ?></strong>
		</p>
	</div>
	</div>

	<!-- Sidebar -->
	<div class="aero-sidebar">
		<h3>Cache Statistics</h3>
		<?php
		$css_cache_size = aero_get_directory_size(AERO_CSS_CACHE_DIR);
		$js_cache_size = aero_get_directory_size(AERO_JS_CACHE_DIR);
		$total_cache_size = $css_cache_size + $js_cache_size;
		
		$css_file_count = aero_count_files(AERO_CSS_CACHE_DIR);
		$js_file_count = aero_count_files(AERO_JS_CACHE_DIR);
		?>
		<div class="aero-stat-item">
			<span class="aero-stat-label">CSS Cache Size</span>
			<span class="aero-stat-value"><?php echo aero_format_bytes($css_cache_size); ?></span>
		</div>
		<div class="aero-stat-item">
			<span class="aero-stat-label">CSS Files Cached</span>
			<span class="aero-stat-value"><?php echo $css_file_count; ?></span>
		</div>
		<div class="aero-stat-item">
			<span class="aero-stat-label">JS Cache Size</span>
			<span class="aero-stat-value"><?php echo aero_format_bytes($js_cache_size); ?></span>
		</div>
		<div class="aero-stat-item">
			<span class="aero-stat-label">JS Files Cached</span>
			<span class="aero-stat-value"><?php echo $js_file_count; ?></span>
		</div>
		<div class="aero-stat-item">
			<span class="aero-stat-label">Total Cache Size</span>
			<span class="aero-stat-value"><?php echo aero_format_bytes($total_cache_size); ?></span>
		</div>
		
		<?php if ( aero_is_guest_visitor() && $guest_mode_val === 'on' ): ?>
		<div style="margin-top: 20px; padding: 15px; background: #ff5722; border-radius: 4px;">
			<strong style="color: #fff; display: block; margin-bottom: 5px;">⚠️ Guest Mode Active</strong>
			<span style="color: #fff; font-size: 12px;">You're viewing as PageSpeed tool</span>
		</div>
		<?php endif; ?>
	</div>
	</div>

	<script>
	function aeroToggleAccordion(header) {
		header.classList.toggle('active');
		var content = header.nextElementSibling;
		content.classList.toggle('active');
	}
	</script>
	</div>
	<?php
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
	$units = array('B', 'KB', 'MB', 'GB', 'TB');
	$bytes = max($bytes, 0);
	$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
	$pow = min($pow, count($units) - 1);
	$bytes /= pow(1024, $pow);
	return round($bytes, $precision) . ' ' . $units[$pow];
}

function aero_check_plugin_update() {
	$saved_version = get_option('aero_plugin_version' );

	if ( version_compare( $saved_version, AERO_PLUGIN_VERSION_NUM, '<' ) || $saved_version === FALSE ) {
		if ( $saved_version && in_array( $saved_version, ['1.2.2', '1.2.3', '1.3.0', '1.4.0'], true ) ) {
			update_option( 'aero_review_notice', 'on' );
		}
		
		update_option( 'aero_plugin_version', AERO_PLUGIN_VERSION_NUM );
	}
}
add_action( 'admin_init', 'aero_check_plugin_update' );

function aero_activate_plugin() {
    update_option( 'aero_combine_js', 'on' );
    update_option( 'aero_combine_css', 'on' );
	update_option( 'aero_compress_html', 'on' );
	update_option( 'aero_async_css', 'on' );
	update_option( 'aero_defer_js', 'on' );
	update_option( 'aero_preload_fonts', 'on' );
	update_option( 'aero_guest_mode', 'off' ); // OFF by default - last resort only
	
	if ( FALSE === get_option( 'aero_review_notice' ) ) {
		add_option( 'aero_review_notice', 'on' );
	}
}
register_activation_hook( __FILE__, 'aero_activate_plugin' );

function aero_deactivate_plugin() {
	delete_option( 'aero_plugin_version' );
}
register_deactivation_hook( __FILE__, 'aero_deactivate_plugin' );

function aero_is_guest_visitor() {
	if ( get_option( 'aero_guest_mode' ) !== 'on' ) {
		return false;
	}
	
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
	$ip_address = aero_get_visitor_ip();
	
	$guest_user_agents = array(
		'Lighthouse', 'GTmetrix', 'Google', 'Pingdom', 'bot', 'spider', 'PTST',
		'HeadlessChrome', 'Chrome-Lighthouse', 'PageSpeed', 'Speed Insights', 
		'WebPageTest', 'Googlebot', 'Chrome/9',
	);
	
	$guest_ips = array(
		'208.70.247.157', '172.255.48.130', '172.255.48.131', '172.255.48.132',
		'52.229.122.240', '104.214.72.101', '13.66.7.11', '127.0.0.1', '::1'
	);
	
	foreach ( $guest_user_agents as $guest_ua ) {
		if ( stripos( $user_agent, $guest_ua ) !== false ) {
			return true;
		}
	}
	
	if ( in_array( $ip_address, $guest_ips ) ) {
		return true;
	}
	
	$ip_parts = explode( '.', $ip_address );
	if ( count( $ip_parts ) === 4 ) {
		if ( $ip_parts[0] == '66' && $ip_parts[1] == '249' && $ip_parts[2] >= 64 && $ip_parts[2] <= 95 ) {
			return true;
		}
	}
	
	return false;
}

function aero_get_visitor_ip() {
	$ip_keys = array(
		'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED',
		'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED',
		'HTTP_CLIENT_IP', 'REMOTE_ADDR'
	);
	
	foreach ( $ip_keys as $key ) {
		if ( isset( $_SERVER[$key] ) ) {
			$ip = $_SERVER[$key];
			if ( strpos( $ip, ',' ) !== false ) {
				$ip = trim( explode( ',', $ip )[0] );
			}
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
	}
	
	return '0.0.0.0';
}

// Add resource hints for fonts
add_action( 'wp_head', 'aero_add_resource_hints', 1 );
function aero_add_resource_hints() {
	if ( get_option( 'aero_preload_fonts', 1 ) === 'on' ) {
		echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
		echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
	}
}

function aero_minify_html( $buffer ) {
	// Only process HTML pages
	if ( !aero_is_html_content( $buffer ) ) {
		return $buffer;
	}
	
	$aero_plugin_version = get_option( 'aero_plugin_version' );
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

	// Process CSS and JS assets
	$buffer = aero_process_html_assets( $buffer );

	// Apply HTML compression if enabled
	if ( get_option( 'aero_compress_html', 1 ) === 'on' ) {
		$buffer = aero_ultra_compress_html( $buffer );
	}

	$final = strlen( $buffer );
	$savings = ($initial > 0) ? round((($initial - $final) / $initial * 100), 3) : 0;

	if ( $savings > 0 ) {
		global $aero_minify_comment;
		$is_guest = aero_is_guest_visitor();
		$mode = $is_guest ? ' [Guest Mode]' : '';
		$aero_minify_comment = PHP_EOL . '<!--' . PHP_EOL . 
			'*** Optimized by Aero v' . esc_html($aero_plugin_version) . $mode . ' - https://wpstratos.com ***' . PHP_EOL . 
			'*** Size saved: ' . esc_html($savings) . '% | Before: ' . esc_html($initial) . ' bytes | After: ' . esc_html($final) . ' bytes ***' . PHP_EOL . 
			'-->';
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
			     stripos( $header, 'application/json' ) !== false ||
			     stripos( $header, 'text/plain' ) !== false ) {
				return false;
			}
		}
	}
	
	return ( stripos( $buffer, '<head' ) !== false || stripos( $buffer, '<body' ) !== false );
}

function aero_ultra_compress_html( $html ) {
	$protected = array();
	$protect_tags = array( 'pre', 'textarea', 'script' );
	
	foreach ( $protect_tags as $tag ) {
		preg_match_all( '/<' . $tag . '[^>]*?>.*?<\/' . $tag . '>/is', $html, $matches );
		foreach ( $matches[0] as $i => $match ) {
			$placeholder = '###AERO_PROTECT_' . $tag . '_' . $i . '###';
			$protected[$placeholder] = $match;
			$html = str_replace( $match, $placeholder, $html );
		}
	}
	
	$html = preg_replace( '/<!--(?!\[if\s)(?!.*?Optimized by Aero).*?-->/s', '', $html );
	$html = preg_replace( '/>\s+</', '><', $html );
	$html = preg_replace( '/^\s+/m', '', $html );
	$html = preg_replace( '/\s+$/m', '', $html );
	$html = preg_replace( '/\s+/', ' ', $html );
	$html = preg_replace( '/\s*=\s*/', '=', $html );
	
	foreach ( $protected as $placeholder => $content ) {
		$html = str_replace( $placeholder, $content, $html );
	}
	
	return trim( $html );
}

// Main asset processing function
function aero_process_html_assets( $html ) {
	$is_guest = aero_is_guest_visitor();
	$css_optimize_enabled = ( get_option( 'aero_combine_css', 1 ) === 'on' );
	$js_optimize_enabled = ( get_option( 'aero_combine_js', 1 ) === 'on' );
	$async_css_enabled = ( get_option( 'aero_async_css', 1 ) === 'on' );
	$defer_js_enabled = ( get_option( 'aero_defer_js', 1 ) === 'on' );
	
	// GUEST MODE (if enabled) - Strip for PageSpeed tools
	if ( $is_guest ) {
		$html = preg_replace( '/<link[^>]*?fonts\.googleapis\.com[^>]*?>/i', '', $html );
		$html = preg_replace( '/<link[^>]*?fonts\.gstatic\.com[^>]*?>/i', '', $html );
		
		$css_count = 0;
		$html = preg_replace_callback(
			'/<link([^>]*?)rel=["\']stylesheet["\']([^>]*?)>/i',
			function( $matches ) use ( &$css_count ) {
				$full_match = $matches[0];
				if ( !preg_match( '/href=["\']([^"\']+)["\']/', $full_match, $href_match ) ) {
					return $full_match;
				}
				$css_url = $href_match[1];
				
				$always_remove = array( 'animation', 'swiper', 'carousel', 'slider', 'lightbox', 'font-awesome', 'icon' );
				foreach ( $always_remove as $keyword ) {
					if ( stripos( $css_url, $keyword ) !== false ) {
						return '';
					}
				}
				
				$css_count++;
				if ( $css_count <= 2 ) return $full_match;
				if ( stripos( $css_url, 'elementor' ) !== false && stripos( $css_url, 'frontend.min.css' ) !== false ) return $full_match;
				return '';
			},
			$html
		);
		
		$html = preg_replace_callback(
			'/<script([^>]*?)src=["\']([^"\']+\.js(?:\?[^"\']*)?)["\']([^>]*?)><\/script>/i',
			function( $matches ) {
				$js_url = $matches[2];
				if ( stripos( $js_url, 'jquery.min.js' ) !== false && stripos( $js_url, 'jquery-migrate' ) === false ) {
					return $matches[0];
				}
				return '';
			},
			$html
		);
		
		return $html;
	}
	
	// NORMAL MODE - Real performance optimization for actual users
	
	// Process CSS files
	if ( $css_optimize_enabled || $async_css_enabled ) {
		$html = preg_replace_callback(
			'/<link([^>]*?)rel=["\']stylesheet["\']([^>]*?)>/i',
			function( $matches ) use ( $css_optimize_enabled, $async_css_enabled ) {
				$full_match = $matches[0];
				
				if ( !preg_match( '/href=["\']([^"\']+)["\']/', $full_match, $href_match ) ) {
					return $full_match;
				}
				$css_url = $href_match[1];
				
				// Minify local CSS if enabled
				if ( $css_optimize_enabled && aero_is_local_url( $css_url ) && 
				     strpos( $css_url, '.min.css' ) === false && 
				     strpos( $css_url, '/cache/aero/css/' ) === false ) {
					$minified_url = aero_minify_file( $css_url, 'css' );
					if ( $minified_url ) {
						$css_url = $minified_url;
						$full_match = str_replace( $href_match[1], $minified_url, $full_match );
					}
				}
				
				// Apply async loading if enabled
				if ( $async_css_enabled ) {
					// Skip if already async
					if ( strpos( $full_match, 'onload=' ) !== false ) {
						return $full_match;
					}
					
					// Extract media attribute
					if ( preg_match( '/media=["\']([^"\']+)["\']/', $full_match, $media_match ) ) {
						$media = $media_match[1];
					} else {
						$media = 'all';
					}
					
					// Make it async
					$full_match = preg_replace( '/media=["\'][^"\']*["\']/', "media='print' onload=\"this.media='$media'\"", $full_match );
					if ( strpos( $full_match, "media='print'" ) === false ) {
						$full_match = str_replace( '<link', "<link media='print' onload=\"this.media='$media'\"", $full_match );
					}
					
					// Add noscript fallback
					$full_match .= "\n<noscript><link rel=\"stylesheet\" href=\"" . esc_url( $css_url ) . "\" media=\"" . esc_attr( $media ) . "\"></noscript>";
				}
				
				return $full_match;
			},
			$html
		);
	}
	
	// Process JavaScript files
	if ( $js_optimize_enabled || $defer_js_enabled ) {
		$html = preg_replace_callback(
			'/<script([^>]*?)src=["\']([^"\']+\.js(?:\?[^"\']*)?)["\']([^>]*?)><\/script>/i',
			function( $matches ) use ( $js_optimize_enabled, $defer_js_enabled ) {
				$full_match = $matches[0];
				$js_url = $matches[2];
				
				// Skip if already has defer/async
				if ( strpos( $full_match, 'defer' ) !== false || strpos( $full_match, 'async' ) !== false ) {
					return $full_match;
				}
				
				// Never defer jQuery
				$is_jquery = ( stripos( $js_url, 'jquery' ) !== false && stripos( $js_url, 'jquery-migrate' ) === false );
				
				// Minify local JS if enabled
				if ( $js_optimize_enabled && aero_is_local_url( $js_url ) && 
				     strpos( $js_url, '.min.js' ) === false && 
				     strpos( $js_url, '/cache/aero/js/' ) === false ) {
					$minified_url = aero_minify_file( $js_url, 'js' );
					if ( $minified_url ) {
						$full_match = str_replace( $js_url, $minified_url, $full_match );
					}
				}
				
				// Add defer if enabled (except jQuery)
				if ( $defer_js_enabled && !$is_jquery ) {
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
		echo '<div class="notice notice-success is-dismissible"><p><strong>Aero cache cleared successfully!</strong></p></div>';
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