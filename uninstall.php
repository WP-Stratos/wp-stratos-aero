<?php
/**
 * Runs on uninstall of Aero.
 *
 * Removes Aero Cache Manager mu-plugins and every option the cache-manager
 * module has written, plus Aero's own options.
 *
 * @package Aero
 */

// Exit if uninstall constant is not defined (security check)
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ─── Remove Aero Cache Manager mu-plugins ─────────────────────────────────────
$aero_cm_mu_dir   = WP_CONTENT_DIR . '/mu-plugins/aero-cache-manager/';
$aero_cm_mu_index = WP_CONTENT_DIR . '/mu-plugins/aero-cache-manager.php';

if ( is_dir( $aero_cm_mu_dir ) ) {
	foreach ( glob( $aero_cm_mu_dir . '*.php' ) as $aero_cm_mu_file ) {
		@unlink( $aero_cm_mu_file );
	}
	@rmdir( $aero_cm_mu_dir );
}
if ( file_exists( $aero_cm_mu_index ) ) {
	@unlink( $aero_cm_mu_index );
}

// ─── Clear scheduled events ───────────────────────────────────────────────────
wp_clear_scheduled_hook( 'aero_cm_scheduled_flush' );

// ─── Delete options ───────────────────────────────────────────────────────────
$aero_options_to_delete = array(

	// ── Cache Manager settings ────────────────────────────────────────────────
	'aero_cm_options',
	'aero_cm_purge_order',
	'aero_cm_schedule_enabled',
	'aero_cm_schedule_interval',
	'aero_cm_guest_isolation',
	'aero_cm_last_full_flush',
	'aero_cm_last_scheduled_flush',
	'aero_cm_mu_install_notice_pending',
	'aero_cm_woo_activate_notice',

	// ── Flush timestamps ──────────────────────────────────────────────────────
	'aero-cache-flush-time-stamp',
	'flush-obj-cache-time-stamp',
	'flush-cache-theme-plugin-time-stamp',
	'flush-cache-page-edit-time-stamp',
	'flush-cache-on-page-post-delete-time-stamp',
	'flush-cache-on-comment-delete-time-stamp',

	// ── Individual page flush ─────────────────────────────────────────────────
	'flush-object-cache-for-single-page-time-stamp',
	'single-page-url-flushed',
	'single-page-edge-cache-purge-time-stamp',
	'page-title',

	// ── Edge cache ────────────────────────────────────────────────────────────
	'edge-cache-enabled',
	'edge-cache-status',
	'edge-cache-purge-time-stamp',
	'edge-cache-single-page-url-purged',
	'edge-cache-defensive-mode-active',
	'edge-cache-defensive-mode-slug',
	'edge-cache-defensive-mode-expires-at',
	'edge-cache-defensive-mode-set-at',

	// ── Aero core options ─────────────────────────────────────────────────────
	'aero_combine_js',
	'aero_combine_css',
	'aero_compress_html',
	'aero_defer_js',
	'aero_optimize_fonts',
	'aero_preload_critical',
	'aero_guest_mode_level',
	'aero_debug_mode',
	'aero_custom_css_normal',
	'aero_custom_css_guest',
	'aero_minified_files',
	'aero_plugin_version',
	'aero_activation_date',
	'aero_review_notice',
	// Optimizer modules
	'aero_fonts_local_google',
	'aero_fonts_inline_css',
	'aero_fonts_preconnect',
	'aero_fonts_preload',
	'aero_fonts_disable_google',
	'aero_fonts_map',
	'aero_fonts_detected',
	'aero_bloat',
	'aero_cw_options',
	'aero_cw_queue',
	'aero_cw_stats',
	'aero_cw_running',
	'aero_cw_micro_queue',
	'aero_cw_micro_stats',
	'aero_cw_micro_log',
	'aero_cw_edge_priority',
	'aero_cw_edge_status',
	'aero_exclude_minify_css',
	'aero_exclude_minify_js',
	'aero_exclude_defer',
	'aero_delay_js',
	'aero_delay_js_timeout',
	'aero_delay_js_excludes',
	'aero_async_css',
	'aero_async_css_excludes',
	'aero_critical_css',
	'aero_preload_lcp',
);

foreach ( $aero_options_to_delete as $aero_opt ) {
	delete_option( $aero_opt );
}

// ─── Delete transients ────────────────────────────────────────────────────────
delete_transient( 'aero_cm_batcache_status' );

// Flash-notice transients are per-user; remove any stragglers directly.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_aero_ui_flash_%' OR option_name LIKE '_transient_timeout_aero_ui_flash_%'" ); // phpcs:ignore
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_aero_fonts_fail_%' OR option_name LIKE '_transient_timeout_aero_fonts_fail_%' OR option_name LIKE 'aero_fonts_lock_%' OR option_name LIKE '_transient%aero_cw_llms_check'" ); // phpcs:ignore

// Localized font files
$aero_fonts_dir = WP_CONTENT_DIR . '/cache/aero/fonts/';
if ( is_dir( $aero_fonts_dir ) ) {
	foreach ( (array) glob( $aero_fonts_dir . '*' ) as $aero_font_file ) {
		if ( is_file( $aero_font_file ) ) {
			unlink( $aero_font_file );
		}
	}
	rmdir( $aero_fonts_dir );
}
delete_transient( 'aero_cm_ec_status_cache' );
delete_transient( 'aero_debug_last_refresh' );
delete_transient( 'aero-cm-page-post-delete-notice' );

// ═══ Image Optimizer — restore originals (CompressX-derived engine) ═══════════
// Conversion never touches originals, so restoring means: delete every
// generated WebP/AVIF file, the meta table, all options and postmeta.

// Options.
$aero_io_options = array(
	'aero_io_general_settings',
	'aero_io_quality',
	'aero_io_converter_method',
	'aero_io_output_format_webp',
	'aero_io_output_format_avif',
	'aero_io_auto_optimize',
	'aero_io_media_excludes',
	'aero_io_custom_includes',
	'aero_io_need_optimized_custom_images',
	'aero_io_image_opt_task',
	'aero_io_custom_image_opt_task',
	'aero_io_media_replace',
	'aero_io_purge_after_bulk',
	'aero_io_global_stats',
	'aero_io_stats_progress',
	'aero_io_delivery_checked',
	'aero_io_enabled',
	'aero_io_optin_notice',
	'aero_io_global_stats_ex',
	'aero_io_css_files',
	'aero_io_lazy_bg',
	'aero_io_show_review',
	'aero_io_rating_dismiss',
	'aero_io_need_optimized_images',
	'aero_io_cancel_' . md5( 'aero_io_process_media_task' ),
	'aero_io_cancel_' . md5( 'aero_io_process_custom_task' ),
);
foreach ( $aero_io_options as $aero_io_opt ) {
	delete_option( $aero_io_opt );
}
// Per-user notice dismissals.
delete_metadata( 'user', 0, 'aero_io_conflict_dismissed', '', true );
delete_metadata( 'user', 0, 'aero_io_optin_dismissed', '', true );

delete_transient( 'aero_io_set_global_stats' );
delete_transient( 'aero_io_bulk_purge_done' );
wp_clear_scheduled_hook( 'aero_io_process_media_task' );
wp_clear_scheduled_hook( 'aero_io_process_custom_task' );

// Legacy per-post meta keys.
$aero_io_meta_keys = array(
	'aero_io_image_meta_status',
	'aero_io_image_meta_webp_converted',
	'aero_io_image_meta_avif_converted',
	'aero_io_image_meta_compressed',
	'aero_io_image_meta_og_file_size',
	'aero_io_image_meta_webp_converted_size',
	'aero_io_image_meta_avif_converted_size',
	'aero_io_image_meta_compressed_size',
	'aero_io_image_meta',
	'aero_io_image_meta_progressing',
);
foreach ( $aero_io_meta_keys as $aero_io_key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $aero_io_key ) );
}

// Tables.
$aero_io_meta_table  = $wpdb->base_prefix . 'aero_io_images_meta';
$aero_io_files_table = $wpdb->prefix . 'aero_io_files_opt_meta';
$wpdb->query( "DROP TABLE IF EXISTS `{$aero_io_meta_table}`" );  // phpcs:ignore
$wpdb->query( "DROP TABLE IF EXISTS `{$aero_io_files_table}`" ); // phpcs:ignore

// Generated files + logs.
if ( ! function_exists( 'aero_uninstall_rrmdir' ) ) {
	function aero_uninstall_rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$full = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $full ) ) {
				aero_uninstall_rrmdir( $full );
			} else {
				@unlink( $full );
			}
		}
		@rmdir( $dir );
	}
}
aero_uninstall_rrmdir( WP_CONTENT_DIR . '/aero-nextgen' );
aero_uninstall_rrmdir( WP_CONTENT_DIR . '/aero-images' );

// Rewrite rules out of .htaccess (wp-content + uploads).
if ( ! function_exists( 'insert_with_markers' ) ) {
	require_once ABSPATH . 'wp-admin/includes/misc.php';
}
$aero_io_uploads = wp_get_upload_dir();
foreach ( array( WP_CONTENT_DIR . '/.htaccess', $aero_io_uploads['basedir'] . '/.htaccess' ) as $aero_io_ht ) {
	if ( file_exists( $aero_io_ht ) ) {
		insert_with_markers( $aero_io_ht, 'Aero Images', '' );
	}
}

// Rewrite-test files copied into uploads by the delivery test.
foreach ( array( 'aero_io_test.png', 'aero_io_test.png.webp' ) as $aero_io_tf ) {
	$aero_io_tp = $aero_io_uploads['basedir'] . '/' . $aero_io_tf;
	if ( file_exists( $aero_io_tp ) ) {
		@unlink( $aero_io_tp );
	}
}
