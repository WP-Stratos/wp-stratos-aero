<?php
/*
Plugin Name: Aero
Plugin URI: https://wpstratos.com
Description: Smartly minify, compress and cache HTML, CSS & JavaScript files to boost website speed. 🚀
Version: 1.2.2
Author: WP Stratos
Author URI: https://wpstratos.com
*/

// Define plugin version for future releases
if ( !defined ('AERO_PLUGIN_VERSION_NUM' ) ) {
    define( 'AERO_PLUGIN_VERSION_NUM', '1.2.2' );
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

// Create Cache directories and sub-directories for CSS and JS
if ( !file_exists( AERO_CACHE_DIR ) ) mkdir( AERO_CACHE_DIR, 0755, true );
if ( !file_exists( AERO_CSS_CACHE_DIR ) ) mkdir( AERO_CSS_CACHE_DIR, 0755, true );
if ( !file_exists( AERO_JS_CACHE_DIR ) ) mkdir( AERO_JS_CACHE_DIR, 0755, true );

// Register with hook 'wp_enqueue_scripts', which can be used for front end CSS and JavaScript
add_action( 'admin_init', 'aero_add_stylesheet' );
function aero_add_stylesheet() {
	// Add version based on file modification time for cache busting
	$css_file = plugin_dir_path( __FILE__ ) . 'assets/css/style.min.css';
	$version = file_exists( $css_file ) ? filemtime( $css_file ) : AERO_PLUGIN_VERSION_NUM;
	
    wp_register_style( 'aero-stylesheet', plugins_url('assets/css/style.min.css', __FILE__), array(), $version );
    wp_enqueue_style( 'aero-stylesheet' );
	
	do_action( 'aero_rating_system_action' );
}

// Add inline critical CSS for immediate styling
add_action( 'admin_head', 'aero_add_critical_css' );
function aero_add_critical_css() {
	// Only on Aero settings page
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

// Register admin menu
add_action( 'admin_menu', 'aero_add_admin_menu' );
function aero_add_admin_menu() {
	add_options_page( 'Aero', 'Aero', 'manage_options', 'aero', 'aero_admin_options' );
}

// Add settings link on plugin page
function aero_settings_link( $links ) {
	$settings_link = '<a href="options-general.php?page=aero">Settings</a>';
	array_unshift($links, $settings_link);
	return $links;
}
$plugin = plugin_basename( __FILE__ );
add_filter( "plugin_action_links_$plugin", 'aero_settings_link' );

// Adding WordPress plugin meta links
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

	// Variables for the field and option names
	$hidden_field_name = 'aero_submit_hidden';
    $combine_js = 'aero_combine_js';
    $combine_css = 'aero_combine_css';
	$async_css = 'aero_async_css';

    // Read in existing option value from database
    $combine_js_val = get_option($combine_js);
    $combine_css_val = get_option($combine_css);
	$async_css_val = get_option($async_css);

	// See if the user has posted us some information
	if( isset( $_POST[$hidden_field_name] ) && $_POST[$hidden_field_name] == 'Y' ) {
		// CSRF Check
    	if ( isset( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'aero_settings_nonce' ) ) {
			if ( isset( $_POST['aero_clear_minified'] ) ) {
				aero_clear_minified_cache();
			}
			else {			
				$combine_js_val = ( isset( $_POST[$combine_js] ) ? sanitize_text_field( $_POST[$combine_js] ) : "" );
				$combine_css_val = ( isset( $_POST[$combine_css] ) ? sanitize_text_field( $_POST[$combine_css] ) : "" );
				$async_css_val = ( isset( $_POST[$async_css] ) ? sanitize_text_field( $_POST[$async_css] ) : "" );
	
				update_option( $combine_js, $combine_js_val );
				update_option( $combine_css, $combine_css_val );
				update_option( $async_css, $async_css_val );
	
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
    <p>
    <input type="checkbox" name="<?php echo $combine_css; ?>" id="<?php echo $combine_css; ?>" <?php checked( $combine_css_val == 'on',true); ?> />
    <label for="<?php echo $combine_css; ?>" class="aero_settings" style="display: inline;"> <?php _e('Optimize CSS'); ?> </label>
    </p>
	<p>
	<input type="checkbox" name="<?php echo $combine_js; ?>" id="<?php echo $combine_js; ?>" <?php checked( $combine_js_val == 'on',true); ?> />
	<label for="<?php echo $combine_js; ?>" class="aero_settings" style="display: inline;"> <?php _e('Optimize JavaScript'); ?> </label>
	</p>

	<!-- Advanced Settings Accordion -->
	<div class="aero-accordion">
		<button type="button" class="aero-accordion-header" onclick="aeroToggleAccordion(this)">
			<span>Advanced Settings</span>
			<span class="aero-accordion-icon">▼</span>
		</button>
		<div class="aero-accordion-content">
			<div class="aero-accordion-inner">
				<div class="aero-setting-group">
					<label>
						<input type="checkbox" name="<?php echo $async_css; ?>" id="<?php echo $async_css; ?>" <?php checked( $async_css_val == 'on',true); ?> />
						<?php _e('Enable Non-Render-Blocking CSS'); ?>
					</label>
					<div class="aero-setting-description">
						Load minified CSS files asynchronously to eliminate render-blocking warnings in Google PageSpeed Insights. 
						This improves your LCP (Largest Contentful Paint) score. Disable if you experience compatibility issues.
					</div>
				</div>
			</div>
		</div>
	</div>

    <p>
		<input type="submit" value="<?php esc_attr_e('Save Changes') ?>" class="button button-primary aero-button" name="submit" />
		<button type="submit" name="aero_clear_minified" class="button aero-button aero-button-secondary">Clear CSS & JS Cache</button>
    </p>
	</form>
	<p> &nbsp; </p>
	<?php
	$message = "";
	$css_enabled = ($combine_css_val == 'on');
	$js_enabled = ($combine_js_val == 'on');
	
	$templates = [
		'css_js'	=>	"✅ Aero now minifies, compresses, and caches all %s files. Enable '<em>Optimize %s</em>' to further boost your site's performance.",
		'both'		=>	"✅ Aero now minifies, compresses, and caches all CSS & JavaScript files — making your site lighter, faster, and more optimized than ever! 🚀",
		'none'		=>	"<span style='color: #ff4444 !important;'>❗ You haven't selected any options above — Aero isn't currently optimizing your site.
						<br /> <br />If you're not debugging or troubleshooting errors, consider enabling the options above to boost your site's performance.</span>",
	];
	$hassle_free_updates = "✅ Enjoy a seamless experience — Minified files are automatically updated whenever the original files are modified.";
	
	if ( $js_enabled && !$css_enabled ) {
		$message = sprintf($templates['css_js'], "JavaScript", "CSS") . '<br /> <br />' . $hassle_free_updates;
	} elseif ( $css_enabled && !$js_enabled ) {
		$message = sprintf($templates['css_js'], "CSS", "JS") . '<br /> <br />' . $hassle_free_updates;
	} elseif ( $js_enabled && $css_enabled ) {
		$message = $templates['both'] . '<br /> <br />' . $hassle_free_updates;
	} else {
		$message = $templates['none'];
	}
	echo '<p style="color:#5080d0; font-weight: bold;">' . $message . '</p>';
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

// Helper function to get directory size
function aero_get_directory_size($directory) {
	$size = 0;
	if (is_dir($directory)) {
		try {
			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
				if ($file->isFile()) {
					$size += $file->getSize();
				}
			}
		} catch (Exception $e) {
			// Directory might be empty or inaccessible
		}
	}
	return $size;
}

// Helper function to count files
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

// Helper function to format bytes
function aero_format_bytes($bytes, $precision = 2) {
	$units = array('B', 'KB', 'MB', 'GB', 'TB');
	$bytes = max($bytes, 0);
	$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
	$pow = min($pow, count($units) - 1);
	$bytes /= pow(1024, $pow);
	return round($bytes, $precision) . ' ' . $units[$pow];
}

// Check if the plugin has been updated
function aero_check_plugin_update() {
	$saved_version = get_option('aero_plugin_version' );

	if ( version_compare( $saved_version, AERO_PLUGIN_VERSION_NUM, '<' ) || $saved_version === FALSE ) {
		if ( $saved_version && in_array( $saved_version, ['1.2.2'], true ) ) {
			update_option( 'aero_review_notice', 'on' );
		}
		
		update_option( 'aero_plugin_version', AERO_PLUGIN_VERSION_NUM );
	}
}
add_action( 'admin_init', 'aero_check_plugin_update' );

// Make the default value of enable javascript and enable CSS to true on plugin activation
function aero_activate_plugin() {
    update_option( 'aero_combine_js', 'on' );
    update_option( 'aero_combine_css', 'on' );
	update_option( 'aero_async_css', 'on' );
	
	if ( FALSE === get_option( 'aero_review_notice' ) ) {
		add_option( 'aero_review_notice', 'on' );
	}
}
register_activation_hook( __FILE__, 'aero_activate_plugin' );

// Remove filters/functions on plugin deactivation
function aero_deactivate_plugin() {
	delete_option( 'aero_plugin_version' );
}
register_deactivation_hook( __FILE__, 'aero_deactivate_plugin' );

function aero_minify_html( $buffer ) {
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

	$final = strlen( $buffer );
	if ($initial > 0) {
		$savings = round((($initial - $final) / $initial * 100), 3);
	} else {
		$savings = 0;
	}

	if ( $savings > 0 ) {
		global $aero_minify_comment;
		$aero_minify_comment = PHP_EOL . '<!--' . PHP_EOL . 
			'*** Optimized by Aero v' . esc_html($aero_plugin_version) . ' - https://wpstratos.com ***' . PHP_EOL . 
			'*** Total size saved: ' . esc_html($savings) . '% | Size before: ' . esc_html($initial) . ' bytes | Size after: ' . esc_html($final) . ' bytes ***' . PHP_EOL . 
			'-->';
	}

	return $buffer;
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

add_action( 'wp_enqueue_scripts', 'aero_minify_enqueue_scripts', 999 );
function aero_minify_enqueue_scripts() {
	global $wp_styles, $wp_scripts;

	if ( !empty( $wp_styles->queue ) && get_option( 'aero_combine_css', 1 ) === 'on' ) {
		foreach ($wp_styles->queue as $handle) {
			$src = $wp_styles->registered[$handle]->src;
			if ($src) {
				$minified_src = aero_minify_file($src, 'css');
				if ($minified_src) {
					$wp_styles->registered[$handle]->src = $minified_src;
				}
			}
		}
	}

	if ( !empty( $wp_scripts->queue ) && get_option( 'aero_combine_js', 1 ) === 'on' ) {
		foreach ($wp_scripts->queue as $handle) {
			$src = $wp_scripts->registered[$handle]->src;
			if ($src) {
				$minified_src = aero_minify_file($src, 'js');
				if ($minified_src) {
					$wp_scripts->registered[$handle]->src = $minified_src;
				}
			}
		}
	}
}

add_filter( 'style_loader_tag', 'aero_async_css', 10, 4 );
function aero_async_css( $html, $handle, $href, $media ) {
	if ( get_option( 'aero_async_css', 1 ) !== 'on' ) {
		return $html;
	}
	
	if ( strpos( $href, '/cache/aero/css/' ) !== false ) {
		$html = str_replace( "media='$media'", "media='print' onload=\"this.media='all'; this.onload=null;\"", $html );
		$html .= '<noscript><link rel="stylesheet" href="' . esc_url( $href ) . '" media="' . esc_attr( $media ) . '"></noscript>';
	}
	return $html;
}

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
		return $file_url;
	}

	$cache_filetype_dir = ( $type === 'js' ? 'js/' : 'css/' );
	$cache_url = content_url() . '/cache/aero/';

	$file_path = str_replace(home_url(), ABSPATH, $file_url);
	$minified_file_name = md5($file_url) . '.' . $type;
	$minified_file_path = AERO_CACHE_DIR . $cache_filetype_dir . $minified_file_name;
	$minified_file_url = $cache_url . $cache_filetype_dir . $minified_file_name;
	$hash_file_path = $minified_file_path . '.hash';

	if ( !file_exists( $file_path ) ) {
		return false;
	}
	
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

?>