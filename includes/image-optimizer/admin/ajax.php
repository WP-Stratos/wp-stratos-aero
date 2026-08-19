<?php
/**
 * Aero — Image Optimizer AJAX endpoints
 *
 * Thin, hardened wrappers around the Aero_IO_ engine for the Images screen:
 * scan, bulk optimization (media library + custom folders), progress/log
 * polling, delivery test, restore, folder management and log management.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Scan (media library) ─────────────────────────────────────────────────────
add_action( 'wp_ajax_aero_io_start_scan', 'aero_io_ajax_start_scan' );
function aero_io_ajax_start_scan() {
	aero_io_ajax_check_security();
	aero_io_ajax_require_enabled();

	$force  = ( isset( $_POST['force'] ) && '1' === sanitize_key( wp_unslash( $_POST['force'] ) ) );
	$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;

	$ret = Aero_IO_Image_Scanner::scan_unoptimized_images_v2( $force, $offset );

	echo wp_json_encode( $ret );
	die();
}

// ─── Bulk optimization (media library) ────────────────────────────────────────
add_action( 'wp_ajax_aero_io_init_bulk', 'aero_io_ajax_init_bulk' );
function aero_io_ajax_init_bulk() {
	aero_io_ajax_check_security();
	aero_io_ajax_require_enabled();

	$force = ( isset( $_POST['force'] ) && '1' === sanitize_key( wp_unslash( $_POST['force'] ) ) );

	delete_transient( 'aero_io_bulk_purge_done' );
	delete_option( aero_io_cancel_flag( AERO_IO_CRON_MEDIA ) );

	$task = new Aero_IO_ImgOptim_Task();
	$ret  = $task->init_task( $force );

	if ( isset( $ret['result'] ) && 'success' === $ret['result'] ) {
		// The cron chain is the sole processor; arm it right away so the
		// task proceeds even if the tab is closed immediately.
		aero_io_schedule_runner( AERO_IO_CRON_MEDIA, 1 );
		aero_io_spawn_cron();
	}

	echo wp_json_encode( $ret );
	die();
}

add_action( 'wp_ajax_aero_io_run_optimize', 'aero_io_ajax_run_optimize' );
function aero_io_ajax_run_optimize() {
	aero_io_ajax_check_security();
	aero_io_ajax_require_enabled();

	// Processing lives in the cron chain (see loader). This endpoint only
	// makes sure the chain is armed and pokes cron to fire now.
	$task = new Aero_IO_ImgOptim_Task();
	$ret  = $task->get_task_status();

	if ( 'success' === $ret['result'] && isset( $ret['status'] ) && in_array( $ret['status'], array( 'completed', 'running' ), true ) ) {
		aero_io_schedule_runner( AERO_IO_CRON_MEDIA, 1 );
		aero_io_spawn_cron();
	}

	echo wp_json_encode( $ret );
	die();
}

add_action( 'wp_ajax_aero_io_get_progress', 'aero_io_ajax_get_progress' );
function aero_io_ajax_get_progress() {
	aero_io_ajax_check_security();

	$task = new Aero_IO_ImgOptim_Task();
	$ret  = $task->get_task_progress_ex();

	// Self-heal + drive: re-arm the chain if the runner vanished, and spawn
	// cron on every poll while work remains — a due event never fires on a
	// traffic-less site unless something knocks on wp-cron.php.
	if ( 'success' === $ret['result'] && empty( $ret['finished'] ) ) {
		if ( ! wp_next_scheduled( AERO_IO_CRON_MEDIA ) ) {
			aero_io_schedule_runner( AERO_IO_CRON_MEDIA, 1 );
		}
		aero_io_spawn_cron();
	}

	if ( ! empty( $ret['finished'] ) ) {
		$ret['tree_size'] = size_format( aero_io_generated_tree_size(), 1 );
	}

	echo wp_json_encode( $ret );
	die();
}

add_action( 'wp_ajax_aero_io_cancel_bulk', 'aero_io_ajax_cancel_bulk' );
function aero_io_ajax_cancel_bulk() {
	aero_io_ajax_check_security();

	$task = new Aero_IO_ImgOptim_Task();
	aero_io_request_cancel( $task, AERO_IO_CRON_MEDIA );

	echo wp_json_encode( array( 'result' => 'success' ) );
	die();
}

add_action( 'wp_ajax_aero_io_get_task_log', 'aero_io_ajax_get_task_log' );
function aero_io_ajax_get_task_log() {
	aero_io_ajax_check_security();

	$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;

	$task      = new Aero_IO_ImgOptim_Task();
	$file_name = $task->get_log_file();

	$log = Aero_IO_Log_Ex::get_instance();
	if ( empty( $file_name ) ) {
		$log->OpenLogFile();
	} else {
		$log->OpenLogFile( $file_name );
	}
	$ret = $log->get_log_content( $offset );

	echo wp_json_encode( $ret );
	die();
}

// ─── Custom folders (wp-content outside uploads) ──────────────────────────────

/**
 * Validate a custom-folder path: must resolve inside wp-content, must exist,
 * and must not be the uploads tree, Aero's own trees, or another plugin dir.
 *
 * @return string|false Normalized absolute path or false.
 */
function aero_io_validate_custom_folder( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return false;
	}
	// Accept absolute paths or paths relative to wp-content.
	$candidate = ( 0 === strpos( $raw, '/' ) || preg_match( '#^[A-Za-z]:[\\\\/]#', $raw ) )
		? $raw
		: trailingslashit( WP_CONTENT_DIR ) . ltrim( $raw, '/\\' );

	$real    = realpath( $candidate );
	$content = realpath( WP_CONTENT_DIR );
	if ( false === $real || false === $content || ! is_dir( $real ) ) {
		return false;
	}

	$real_n    = str_replace( '\\', '/', $real );
	$content_n = str_replace( '\\', '/', $content );

	if ( 0 !== strpos( $real_n . '/', $content_n . '/' ) ) {
		return false; // outside wp-content
	}
	if ( $real_n === $content_n ) {
		return false; // wp-content itself is too broad
	}

	$uploads   = wp_get_upload_dir();
	$uploads_n = str_replace( '\\', '/', (string) realpath( $uploads['basedir'] ) );
	if ( $uploads_n && 0 === strpos( $real_n . '/', $uploads_n . '/' ) ) {
		return false; // media library is handled by the main scanner
	}

	$blocked = array( 'aero-nextgen', 'aero-images', 'aero-backups', 'cache', 'upgrade', 'plugins/wp-stratos-aero' );
	foreach ( $blocked as $frag ) {
		if ( false !== strpos( $real_n . '/', $content_n . '/' . $frag . '/' ) || $real_n === $content_n . '/' . $frag ) {
			return false;
		}
	}

	return $real_n;
}

add_action( 'wp_ajax_aero_io_add_custom_folder', 'aero_io_ajax_add_custom_folder' );
function aero_io_ajax_add_custom_folder() {
	aero_io_ajax_check_security();

	$raw  = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
	$path = aero_io_validate_custom_folder( $raw );

	if ( false === $path ) {
		echo wp_json_encode(
			array(
				'result' => 'failed',
				'error'  => __( 'That folder is not usable. It must exist inside wp-content, outside the media library, and outside cache/plugin directories.', 'aero' ),
			)
		);
		die();
	}

	$includes = Aero_IO_Options::get_option( 'aero_io_custom_includes', array() );
	$includes = is_array( $includes ) ? $includes : array();
	if ( ! in_array( $path, $includes, true ) ) {
		$includes[] = $path;
		Aero_IO_Options::update_option( 'aero_io_custom_includes', $includes );
	}

	echo wp_json_encode(
		array(
			'result'  => 'success',
			'folders' => array_values( $includes ),
		)
	);
	die();
}

add_action( 'wp_ajax_aero_io_remove_custom_folder', 'aero_io_ajax_remove_custom_folder' );
function aero_io_ajax_remove_custom_folder() {
	aero_io_ajax_check_security();

	$path     = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
	$includes = Aero_IO_Options::get_option( 'aero_io_custom_includes', array() );
	$includes = is_array( $includes ) ? $includes : array();

	$includes = array_values(
		array_filter(
			$includes,
			function ( $item ) use ( $path ) {
				return $item !== $path;
			}
		)
	);
	Aero_IO_Options::update_option( 'aero_io_custom_includes', $includes );

	echo wp_json_encode(
		array(
			'result'  => 'success',
			'folders' => $includes,
		)
	);
	die();
}

add_action( 'wp_ajax_aero_io_scan_custom', 'aero_io_ajax_scan_custom' );
function aero_io_ajax_scan_custom() {
	aero_io_ajax_check_security();
	aero_io_ajax_require_enabled();

	$includes = Aero_IO_Options::get_option( 'aero_io_custom_includes', array() );
	if ( empty( $includes ) || ! is_array( $includes ) ) {
		echo wp_json_encode(
			array(
				'result' => 'failed',
				'error'  => __( 'Add at least one folder before scanning.', 'aero' ),
			)
		);
		die();
	}

	$images = array();
	foreach ( $includes as $include ) {
		// Re-validate stored paths so a stale/tampered option can never widen the scan.
		$valid = aero_io_validate_custom_folder( $include );
		if ( false !== $valid ) {
			aero_io_collect_folder_images( $images, $valid );
		}
	}

	Aero_IO_Options::update_option( 'aero_io_need_optimized_custom_images', $images );

	echo wp_json_encode(
		array(
			'result'   => 'success',
			'found'    => count( $images ),
			'finished' => true,
		)
	);
	die();
}

/**
 * Recursively collect optimizable images under a folder.
 */
function aero_io_collect_folder_images( &$images, $path ) {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$handler = opendir( $path );
	if ( false === $handler ) {
		return;
	}
	while ( false !== ( $filename = readdir( $handler ) ) ) {
		if ( '.' === $filename || '..' === $filename ) {
			continue;
		}
		$full = $path . DIRECTORY_SEPARATOR . $filename;
		if ( is_dir( $full ) ) {
			aero_io_collect_folder_images( $images, $full );
		} else {
			$ext = strtolower( pathinfo( $full, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp', 'avif' ), true ) && file_exists( $full ) ) {
				$images[] = $full;
			}
		}
	}
	closedir( $handler );
}

add_action( 'wp_ajax_aero_io_init_custom_bulk', 'aero_io_ajax_init_custom_bulk' );
function aero_io_ajax_init_custom_bulk() {
	aero_io_ajax_check_security();
	aero_io_ajax_require_enabled();

	$force = ( isset( $_POST['force'] ) && '1' === sanitize_key( wp_unslash( $_POST['force'] ) ) );

	delete_option( aero_io_cancel_flag( AERO_IO_CRON_CUSTOM ) );

	$task = new Aero_IO_Custom_ImgOptim_Task();
	$ret  = $task->init_task( $force );

	if ( isset( $ret['result'] ) && 'success' === $ret['result'] ) {
		aero_io_schedule_runner( AERO_IO_CRON_CUSTOM, 1 );
		aero_io_spawn_cron();
	}

	echo wp_json_encode( $ret );
	die();
}

add_action( 'wp_ajax_aero_io_run_custom_optimize', 'aero_io_ajax_run_custom_optimize' );
function aero_io_ajax_run_custom_optimize() {
	aero_io_ajax_check_security();
	aero_io_ajax_require_enabled();

	$task = new Aero_IO_Custom_ImgOptim_Task();
	$ret  = $task->get_task_status();

	if ( 'success' === $ret['result'] && isset( $ret['status'] ) && in_array( $ret['status'], array( 'completed', 'running' ), true ) ) {
		aero_io_schedule_runner( AERO_IO_CRON_CUSTOM, 1 );
		aero_io_spawn_cron();
	}

	echo wp_json_encode( $ret );
	die();
}

add_action( 'wp_ajax_aero_io_get_custom_progress', 'aero_io_ajax_get_custom_progress' );
function aero_io_ajax_get_custom_progress() {
	aero_io_ajax_check_security();

	$task = new Aero_IO_Custom_ImgOptim_Task();
	$ret  = $task->get_task_progress();

	if ( 'success' === $ret['result'] && empty( $ret['finished'] ) ) {
		if ( ! wp_next_scheduled( AERO_IO_CRON_CUSTOM ) ) {
			aero_io_schedule_runner( AERO_IO_CRON_CUSTOM, 1 );
		}
		aero_io_spawn_cron();
	}

	echo wp_json_encode( $ret );
	die();
}

// ─── Stats ────────────────────────────────────────────────────────────────────
// The engine caches aggregated stats in an option guarded by a freshness
// transient that batch completion deletes. A hard reset forces the next
// stats run to recompute from the meta table.
add_action( 'wp_ajax_aero_io_reset_stats', 'aero_io_ajax_reset_stats' );
function aero_io_ajax_reset_stats() {
	aero_io_ajax_check_security();

	delete_transient( 'aero_io_set_global_stats' );
	delete_option( 'aero_io_global_stats' );
	delete_option( 'aero_io_stats_progress' );

	echo wp_json_encode( array( 'result' => 'success' ) );
	die();
}

/**
 * Refuse work-starting endpoints while the master switch is off, so a stale
 * page (or a direct request) cannot queue a task the cron driver will then
 * refuse to process, leaving a task stuck at zero forever.
 *
 * @return void
 */
function aero_io_ajax_require_enabled() {
	if ( aero_io_is_enabled() ) {
		return;
	}
	echo wp_json_encode(
		array(
			'result' => 'failed',
			'error'  => __( 'The image optimizer is switched off. Turn it on to run this.', 'aero' ),
		)
	);
	die();
}

// ─── Master switch ────────────────────────────────────────────────────────────
add_action( 'wp_ajax_aero_io_toggle_enabled', 'aero_io_ajax_toggle_enabled' );
function aero_io_ajax_toggle_enabled() {
	aero_io_ajax_check_security();

	$on = ( isset( $_POST['enabled'] ) && '1' === sanitize_key( wp_unslash( $_POST['enabled'] ) ) );
	update_option( 'aero_io_enabled', $on ? '1' : '0' );

	if ( ! $on ) {
		// Stop all background work immediately and drop the processed
		// stylesheets, so the front end returns to untouched markup at once.
		aero_io_clear_runner( AERO_IO_CRON_MEDIA );
		aero_io_clear_runner( AERO_IO_CRON_CUSTOM );
		if ( function_exists( 'aero_io_clear_css_cache' ) ) {
			aero_io_clear_css_cache();
		}
	} else {
		aero_io_ensure_dirs();
		Aero_IO_Image_Meta_V2::ensure_table();
		// The first-run notice has served its purpose once the optimizer is
		// on; clear it for every user rather than leaving it to be dismissed.
		delete_option( 'aero_io_optin_notice' );
		delete_metadata( 'user', 0, 'aero_io_optin_dismissed', '', true );
	}

	// Delivery changed either way, so stale pages must go.
	if ( function_exists( 'aero_cm_run_sequential_flush' ) ) {
		aero_cm_run_sequential_flush( 'aero-images-master-switch' );
	}

	echo wp_json_encode(
		array(
			'result'  => 'success',
			'enabled' => $on,
		)
	);
	die();
}

// ─── Processed stylesheet cache ───────────────────────────────────────────────
add_action( 'wp_ajax_aero_io_clear_css_cache', 'aero_io_ajax_clear_css_cache' );
function aero_io_ajax_clear_css_cache() {
	aero_io_ajax_check_security();

	if ( function_exists( 'aero_io_clear_css_cache' ) ) {
		aero_io_clear_css_cache();
	} else {
		// Picture mode may be off, so delivery.php was never loaded.
		require_once AERO_IO_DIR . '/delivery.php';
		aero_io_clear_css_cache();
	}

	if ( function_exists( 'aero_cm_run_sequential_flush' ) ) {
		aero_cm_run_sequential_flush( 'aero-images-css-rebuild' );
	}

	echo wp_json_encode( array( 'result' => 'success' ) );
	die();
}

// ─── Delivery test ────────────────────────────────────────────────────────────
add_action( 'wp_ajax_aero_io_test_rewrite', 'aero_io_ajax_test_rewrite' );
function aero_io_ajax_test_rewrite() {
	aero_io_ajax_check_security();

	aero_io_ensure_dirs();
	$mode   = aero_io_delivery_mode();
	$server = aero_io_server_type();

	if ( 'picture' === $mode ) {
		// Picture delivery happens in PHP at render time — no rewrite to
		// test. Verify the pipeline is usable instead: engine present and
		// the output tree writable.
		$writable = wp_is_writable( WP_CONTENT_DIR . '/aero-nextgen' );
		echo wp_json_encode(
			array(
				'result'  => 'success',
				'mode'    => 'picture',
				'working' => $writable,
				'server'  => $server,
			)
		);
		die();
	}

	$checker = new Aero_IO_Rewrite_Checker();
	$working = $checker->test();

	echo wp_json_encode(
		array(
			'result'  => 'success',
			'mode'    => $mode,
			'working' => (bool) $working,
			'server'  => $server,
		)
	);
	die();
}

// ─── Restore (delete every generated file + all optimization data) ────────────
add_action( 'wp_ajax_aero_io_delete_files', 'aero_io_ajax_delete_files' );
function aero_io_ajax_delete_files() {
	aero_io_ajax_check_security();

	global $wpdb;

	// Legacy per-post meta keys (kept for engine compatibility).
	$meta_keys = array(
		'aero_io_image_meta_status',
		'aero_io_image_meta_webp_converted',
		'aero_io_image_meta_avif_converted',
		'aero_io_image_meta_compressed',
		'aero_io_image_meta_og_file_size',
		'aero_io_image_meta_webp_converted_size',
		'aero_io_image_meta_avif_converted_size',
		'aero_io_image_meta_compressed_size',
		'aero_io_image_meta',
	);
	foreach ( $meta_keys as $key ) {
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ) );
	}

	delete_transient( 'aero_io_set_global_stats' );
	delete_option( 'aero_io_global_stats' );
	delete_option( 'aero_io_stats_progress' );
	delete_option( 'aero_io_image_opt_task' );
	delete_option( 'aero_io_custom_image_opt_task' );
	delete_option( 'aero_io_need_optimized_custom_images' );

	// Custom-folder meta table.
	$files_table = $wpdb->prefix . 'aero_io_files_opt_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $files_table ) ) === $files_table ) {
		$wpdb->query( "TRUNCATE TABLE {$files_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	Aero_IO_Image_Meta_V2::delete_all_image_meta();

	aero_io_clear_runner( AERO_IO_CRON_MEDIA );
	aero_io_clear_runner( AERO_IO_CRON_CUSTOM );

	aero_io_delete_generated_tree();
	aero_io_ensure_dirs();

	echo wp_json_encode( array( 'result' => 'success' ) );
	die();
}

/**
 * Remove the aero-nextgen tree (all generated WebP/AVIF files).
 */
function aero_io_delete_generated_tree() {
	aero_io_rrmdir( WP_CONTENT_DIR . '/aero-nextgen' );
}

function aero_io_rrmdir( $dir ) {
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
			aero_io_rrmdir( $full );
		} else {
			@unlink( $full );
		}
	}
	@rmdir( $dir );
}

// ─── Logs ─────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_aero_io_get_logs_list', 'aero_io_ajax_get_logs_list' );
function aero_io_ajax_get_logs_list() {
	aero_io_ajax_check_security();

	$log  = new Aero_IO_Log();
	$path = $log->GetSaveLogFolder();

	$list = array();
	if ( is_dir( $path ) ) {
		$handler = opendir( $path );
		if ( false !== $handler ) {
			while ( false !== ( $filename = readdir( $handler ) ) ) {
				if ( '.' === $filename || '..' === $filename ) {
					continue;
				}
				if ( ! preg_match( '#^aero_io.*_log\.txt$#', $filename ) ) {
					continue;
				}
				$full   = $path . $filename;
				$list[] = array(
					'file' => $filename,
					'size' => size_format( (float) filesize( $full ), 2 ),
					'time' => gmdate( 'Y-m-d H:i', (int) filemtime( $full ) ),
					'mt'   => (int) filemtime( $full ),
				);
			}
			closedir( $handler );
		}
	}
	usort(
		$list,
		function ( $a, $b ) {
			return $b['mt'] <=> $a['mt'];
		}
	);

	echo wp_json_encode(
		array(
			'result' => 'success',
			'logs'   => array_slice( $list, 0, 20 ),
		)
	);
	die();
}

/**
 * Resolve a user-supplied log filename to a safe path inside the log folder.
 *
 * @return string|false
 */
function aero_io_safe_log_path( $filename ) {
	$filename = basename( (string) $filename );
	if ( ! preg_match( '#^aero_io.*_log\.txt$#', $filename ) ) {
		return false;
	}
	$log  = new Aero_IO_Log();
	$path = $log->GetSaveLogFolder() . $filename;
	return file_exists( $path ) ? $path : false;
}

add_action( 'wp_ajax_aero_io_view_log', 'aero_io_ajax_view_log' );
function aero_io_ajax_view_log() {
	aero_io_ajax_check_security();

	$file = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
	$path = aero_io_safe_log_path( $file );
	if ( false === $path ) {
		echo wp_json_encode(
			array(
				'result' => 'failed',
				'error'  => __( 'Log file not found.', 'aero' ),
			)
		);
		die();
	}

	$content = file_get_contents( $path, false, null, 0, 512 * 1024 );

	echo wp_json_encode(
		array(
			'result'  => 'success',
			'content' => (string) $content,
		)
	);
	die();
}

add_action( 'wp_ajax_aero_io_download_log', 'aero_io_ajax_download_log' );
function aero_io_ajax_download_log() {
	aero_io_ajax_check_security();

	$file = isset( $_REQUEST['file'] ) ? sanitize_file_name( wp_unslash( $_REQUEST['file'] ) ) : '';
	$path = aero_io_safe_log_path( $file );
	if ( false === $path ) {
		wp_die( esc_html__( 'Log file not found.', 'aero' ) );
	}

	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
	header( 'Content-Length: ' . (string) filesize( $path ) );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	die();
}

add_action( 'wp_ajax_aero_io_delete_log', 'aero_io_ajax_delete_log' );
function aero_io_ajax_delete_log() {
	aero_io_ajax_check_security();

	$file = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '';
	$path = aero_io_safe_log_path( $file );
	if ( false !== $path ) {
		@unlink( $path );
	}

	echo wp_json_encode( array( 'result' => 'success' ) );
	die();
}

add_action( 'wp_ajax_aero_io_delete_all_logs', 'aero_io_ajax_delete_all_logs' );
function aero_io_ajax_delete_all_logs() {
	aero_io_ajax_check_security();

	$log  = new Aero_IO_Log();
	$path = $log->GetSaveLogFolder();
	if ( is_dir( $path ) ) {
		$handler = opendir( $path );
		if ( false !== $handler ) {
			while ( false !== ( $filename = readdir( $handler ) ) ) {
				if ( preg_match( '#^aero_io.*_log\.txt$#', $filename ) ) {
					@unlink( $path . $filename );
				}
			}
			closedir( $handler );
		}
	}

	echo wp_json_encode( array( 'result' => 'success' ) );
	die();
}

// ─── Media replace ────────────────────────────────────────────────────────────
add_action( 'wp_ajax_aero_io_replace_media', 'aero_io_ajax_replace_media' );
function aero_io_ajax_replace_media() {
	aero_io_ajax_check_security();

	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json( array( 'result' => 'failed', 'error' => __( 'Insufficient permissions.', 'aero' ) ) );
	}

	if ( empty( $_FILES['image'] ) || ! is_array( $_FILES['image'] ) ) {
		wp_send_json( array( 'result' => 'failed', 'error' => __( 'No file uploaded.', 'aero' ) ) );
	}

	$attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
	if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
		wp_send_json( array( 'result' => 'failed', 'error' => __( 'Invalid attachment.', 'aero' ) ) );
	}

	$allowed_mimes = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'avif'         => 'image/avif',
	);

	if ( ! function_exists( 'wp_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	add_filter( 'upload_dir', 'aero_io_replace_tmp_dir' );
	$uploaded = wp_handle_upload(
		$_FILES['image'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		array(
			'test_form' => false,
			'mimes'     => $allowed_mimes,
		)
	);
	remove_filter( 'upload_dir', 'aero_io_replace_tmp_dir' );

	if ( isset( $uploaded['error'] ) ) {
		wp_send_json( array( 'result' => 'failed', 'error' => $uploaded['error'] ) );
	}

	$options = Aero_IO_Options::get_option( 'aero_io_media_replace', array() );

	// Old derivatives must go before the swap so nothing stale is served.
	if ( Aero_IO_Image_Meta_V2::is_image_optimized( $attachment_id ) || Aero_IO_Image_Meta_V2::has_optimized_file( $attachment_id ) ) {
		Aero_IO_Image_Opt_Method::delete_image( $attachment_id );
	}

	$media_replace = new Aero_IO_Media_Replace();
	$ret           = $media_replace->replace( $attachment_id, $uploaded['file'], $options );

	if ( isset( $ret['result'] ) && 'success' === $ret['result'] ) {
		$auto_re_optimize = isset( $options['auto_re_optimize'] ) ? (bool) $options['auto_re_optimize'] : true;
		if ( $auto_re_optimize ) {
			aero_io_reoptimize_attachment( $attachment_id );
		}
		// The old file may be cached across the whole stack.
		if ( function_exists( 'aero_cm_run_sequential_flush' ) ) {
			aero_cm_run_sequential_flush( 'aero-images-media-replace' );
		}
	}

	echo wp_json_encode( $ret );
	die();
}

/**
 * Temp dir filter for replacement uploads (keeps them out of the library tree).
 */
function aero_io_replace_tmp_dir( $dirs ) {
	$tmp             = WP_CONTENT_DIR . '/aero-nextgen/tmp';
	if ( ! is_dir( $tmp ) ) {
		wp_mkdir_p( $tmp );
	}
	$dirs['path']    = $tmp;
	$dirs['url']     = content_url( 'aero-nextgen/tmp' );
	$dirs['subdir']  = '';
	$dirs['basedir'] = $tmp;
	$dirs['baseurl'] = $dirs['url'];
	return $dirs;
}

/**
 * Re-run conversion for one attachment after replacement.
 */
function aero_io_reoptimize_attachment( $attachment_id ) {
	$general = Aero_IO_Options::get_general_settings();
	$quality = Aero_IO_Options::get_quality_option();
	$options = array_merge( $general, $quality );

	$file_path = get_attached_file( $attachment_id );
	if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
		Aero_IO_Image_Meta_V2::update_image_failed( $attachment_id, 'File missing after replacement.' );
		return;
	}

	Aero_IO_Image_Meta_V2::update_image_progressing( $attachment_id );

	$image = new Aero_IO_Image( $attachment_id, $options );
	if ( $image->convert() ) {
		Aero_IO_Image_Meta_V2::update_image_meta_status( $attachment_id, 'optimized' );
		do_action( 'aero_io_after_optimize_image', $attachment_id );
	} else {
		Aero_IO_Image_Meta_V2::update_image_meta_status( $attachment_id, 'failed' );
	}

	Aero_IO_Image_Meta_V2::delete_image_progressing( $attachment_id );
}
