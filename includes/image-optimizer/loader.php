<?php
/**
 * Aero — Image Optimizer module bootstrap
 *
 * Local WebP/AVIF conversion, compression, delivery and media replacement.
 * The engine layer is derived from CompressX 0.9.39 (GPL-3.0-or-later,
 * © WPvivid Team), ported under the Aero_IO_ prefix and integrated with
 * Aero's cache stack. Generated files live in wp-content/aero-nextgen/
 * so originals are never touched; "restore" simply deletes the tree.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'AERO_IO_DIR' ) ) {
	define( 'AERO_IO_DIR', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
}
if ( ! defined( 'AERO_IO_URL' ) ) {
	define( 'AERO_IO_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
}
if ( ! defined( 'AERO_IO_SLUG' ) ) {
	define( 'AERO_IO_SLUG', 'aero-images' );
}

// ─── Engine ───────────────────────────────────────────────────────────────────
require_once AERO_IO_DIR . '/engine/class-aero-io-image-method.php';
require_once AERO_IO_DIR . '/engine/class-aero-io-image-opt-method.php';
require_once AERO_IO_DIR . '/engine/class-aero-io-image-meta.php';
require_once AERO_IO_DIR . '/engine/class-aero-io-image-meta-v2.php';
require_once AERO_IO_DIR . '/engine/class-aero-io-custom-image-meta.php';
require_once AERO_IO_DIR . '/engine/class-aero-io-options.php';
require_once AERO_IO_DIR . '/engine/class-aero-io-image.php';
require_once AERO_IO_DIR . '/engine/class-aero-io-image-scanner.php';
require_once AERO_IO_DIR . '/engine/class-aero-io-imgoptim-task.php';
require_once AERO_IO_DIR . '/engine/class-aero-io-custom-imgoptim-task.php';
require_once AERO_IO_DIR . '/engine/class-aero-io-default-folder.php';
require_once AERO_IO_DIR . '/engine/class-aero-io-webp-rewrite.php';
require_once AERO_IO_DIR . '/engine/class-aero-io-rewrite-checker.php';
require_once AERO_IO_DIR . '/engine/class-aero-io-media-replace.php';
require_once AERO_IO_DIR . '/engine/class-aero-io-picture-load.php';
require_once AERO_IO_DIR . '/engine/class-aero-io-auto-optimization.php';
require_once AERO_IO_DIR . '/engine/class-aero-io-stats-manager.php';

// WP_List_Table-dependent log helpers only exist in admin context.
if ( is_admin() || wp_doing_cron() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
	if ( ! class_exists( 'WP_List_Table' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	}
}
require_once AERO_IO_DIR . '/engine/class-aero-io-log.php';

/**
 * Shared AJAX security gate for every Image Optimizer endpoint.
 * Mirrors the engine's original contract (nonce + manage_options).
 *
 * @param string $role Unused capability hint kept for engine compatibility.
 */
function aero_io_ajax_check_security( $role = 'manage_options' ) {
	check_ajax_referer( 'aero_io_ajax', 'nonce' );
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		die();
	}
}

/**
 * Evidence that this site was already running Aero's image optimizer before
 * the master switch existed.
 *
 * Versions 2.9.x shipped the optimizer with no on/off control, so on those
 * installs "present" and "active" were the same thing. Every marker checked
 * here is one that only the 2.9.x module could have produced: settings it
 * wrote, tasks it created, stats it computed, or files it generated. The
 * current version does write some of these too, which is exactly why the
 * decision below runs at module-load time — before activation, before the
 * delivery migration on admin_init, and before any screen render — so it can
 * never mistake its own footprints for a previous installation.
 *
 * @return bool
 */
function aero_io_has_prior_image_data() {
	$markers = array(
		'aero_io_delivery_checked',
		'aero_io_general_settings',
		'aero_io_quality',
		'aero_io_converter_method',
		'aero_io_output_format_webp',
		'aero_io_output_format_avif',
		'aero_io_auto_optimize',
		'aero_io_media_excludes',
		'aero_io_media_replace',
		'aero_io_custom_includes',
		'aero_io_purge_after_bulk',
		'aero_io_image_opt_task',
		'aero_io_custom_image_opt_task',
		'aero_io_global_stats',
		'aero_io_global_stats_ex',
		'aero_io_stats_progress',
		'aero_io_show_review',
	);

	foreach ( $markers as $marker ) {
		if ( null !== get_option( $marker, null ) ) {
			return true;
		}
	}

	// Rows in the meta table mean images were actually scanned or converted.
	// The table itself is created by activation, so its mere existence proves
	// nothing; only its contents do.
	global $wpdb;
	$table = class_exists( 'Aero_IO_Image_Meta_V2' )
		? Aero_IO_Image_Meta_V2::table_name()
		: $wpdb->base_prefix . 'aero_io_images_meta';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) {
			return true;
		}
	}

	// Generated derivatives on disk. ensure_dirs() creates the folder, its
	// .htaccess and the css subfolder, so those are ignored.
	$tree = WP_CONTENT_DIR . '/aero-nextgen';
	if ( is_dir( $tree ) ) {
		$entries = @scandir( $tree ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( is_array( $entries ) ) {
			$ignore = array( '.', '..', '.htaccess', 'index.php', 'css' );
			if ( array_diff( $entries, $ignore ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Decide, once per site, whether the image optimizer starts on or off.
 *
 * Image optimization is a large behavioural change: it rewrites delivery and
 * processes the whole media library. Switching that on by itself during a
 * routine plugin update would be a genuinely unwelcome surprise, and on a
 * site already running another optimizer it would create exactly the conflict
 * Aero now warns about. So the module stays off unless there is evidence the
 * site was already using it, in which case nothing changes for that site.
 *
 * The result is written to the database, so this is a one-time decision
 * rather than an implicit default that could be re-evaluated later against
 * different state.
 *
 * @return bool
 */
function aero_io_provision_enabled() {
	$stored = get_option( 'aero_io_enabled', null );

	if ( null !== $stored ) {
		return ( '1' === $stored );
	}

	$prior = aero_io_has_prior_image_data();

	update_option( 'aero_io_enabled', $prior ? '1' : '0' );

	if ( ! $prior ) {
		// Flag the one-time "this feature exists and is off" notice, so the
		// module is discoverable rather than silently dormant.
		update_option( 'aero_io_optin_notice', '1', false );
	}

	return $prior;
}

/**
 * Master switch for the whole Image Optimizer.
 *
 * When off, Aero performs no conversion, no background processing and no
 * delivery rewriting. Files already generated are left on disk untouched,
 * so flipping the switch back on resumes exactly where things stood.
 */
function aero_io_is_enabled() {
	return aero_io_provision_enabled();
}

// Lock the decision in before anything else in this request can write the
// markers it inspects.
aero_io_provision_enabled();

/**
 * Optimization coverage snapshot, used to render the bulk panel in its
 * resting state. When nothing is left to do and work has actually been done,
 * the progress bar should read as solidly complete on every page load rather
 * than resetting to an empty bar that implies the library is untouched.
 *
 * @return array{remaining:int,optimized:int,complete:bool}
 */
function aero_io_coverage() {
	global $wpdb;

	$remaining = (int) Aero_IO_Image_Scanner::get_need_optimize_images_count( false );

	Aero_IO_Image_Meta_V2::ensure_table();
	$table = Aero_IO_Image_Meta_V2::table_name();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$optimized = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(attachment_id) FROM {$table} WHERE status = %s", 'optimized' ) );

	return array(
		'remaining' => $remaining,
		'optimized' => $optimized,
		'complete'  => ( 0 === $remaining && $optimized > 0 ),
	);
}

/**
 * Ensure the aero-nextgen output tree (and its .htaccess) exists.
 */
function aero_io_ensure_dirs() {
	$dir = new Aero_IO_default_folder();
	$dir->create_uploads_dir();

	$css = WP_CONTENT_DIR . '/aero-nextgen/css';
	if ( ! is_dir( $css ) ) {
		wp_mkdir_p( $css );
	}
}

/**
 * Current delivery mode: htaccess | compat_htaccess | picture.
 */
function aero_io_delivery_mode() {
	$options = Aero_IO_Options::get_option( 'aero_io_general_settings', array() );
	$mode    = isset( $options['image_load'] ) ? $options['image_load'] : 'htaccess';
	if ( ! in_array( $mode, array( 'htaccess', 'compat_htaccess', 'picture' ), true ) ) {
		$mode = 'htaccess';
	}
	return $mode;
}

/**
 * (Re)apply the delivery plumbing for the current mode: write rewrite rules
 * for the htaccess modes, remove them for picture mode.
 */
function aero_io_apply_delivery_mode() {
	$mode    = aero_io_delivery_mode();
	$rewrite = new Aero_IO_Webp_Rewrite();
	if ( 'htaccess' === $mode ) {
		$rewrite->create_rewrite_rules();
	} elseif ( 'compat_htaccess' === $mode ) {
		$rewrite->create_rewrite_rules_ex();
	} else {
		$rewrite->remove_rewrite_rule();
	}
}

/**
 * Activation tasks (called from aero_activate_plugin).
 */
function aero_io_activate() {
	// The provisioning decision has already run at module load, so this
	// reads the settled value rather than racing it.
	if ( ! aero_io_is_enabled() ) {
		// Nothing is installed for a disabled module: no rewrite rules and
		// no delivery mode changes. The table and folders are still created
		// so switching the module on later needs no repair step.
		aero_io_ensure_dirs();
		Aero_IO_Image_Meta_V2::ensure_table();
		return;
	}

	aero_io_ensure_dirs();
	Aero_IO_Image_Meta_V2::ensure_table();
	aero_io_enforce_server_delivery();
	$mode = aero_io_delivery_mode();
	if ( ( 'htaccess' === $mode || 'compat_htaccess' === $mode ) && aero_io_htaccess_supported() ) {
		aero_io_apply_delivery_mode();
	}
}

/**
 * Deactivation tasks (called from aero_deactivate_plugin): pull the image
 * rewrite rules back out of .htaccess so image URLs keep resolving to the
 * originals. Generated files are intentionally left in place — deactivation
 * is not uninstall.
 */
function aero_io_deactivate() {
	wp_clear_scheduled_hook( AERO_IO_CRON_MEDIA );
	wp_clear_scheduled_hook( AERO_IO_CRON_CUSTOM );
	if ( class_exists( 'Aero_IO_Webp_Rewrite' ) ) {
		$rewrite = new Aero_IO_Webp_Rewrite();
		$rewrite->remove_rewrite_rule();
	}
}

/**
 * Flush Aero's caches through the sequential engine after optimization
 * work completes, so pages start serving the new formats immediately.
 * Never calls wp_cache_flush() directly. The engine fires
 * 'aero_io_purge_cache' when a bulk finishes and after manual single-image
 * conversions; the debounce collapses bursts into one flush.
 */
function aero_io_purge_after_bulk() {
	if ( get_option( 'aero_io_purge_after_bulk', '1' ) !== '1' ) {
		return;
	}
	if ( get_transient( 'aero_io_bulk_purge_done' ) ) {
		return;
	}
	set_transient( 'aero_io_bulk_purge_done', 1, 2 * MINUTE_IN_SECONDS );
	if ( function_exists( 'aero_cm_run_sequential_flush' ) ) {
		aero_cm_run_sequential_flush( 'aero-images-bulk-complete' );
	}
}
add_action( 'aero_io_purge_cache', 'aero_io_purge_after_bulk' );

// ─── Server detection & delivery defaults ────────────────────────────────────

/**
 * Detect the web server: apache | litespeed | nginx | unknown.
 */
function aero_io_server_type() {
	$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
	if ( false !== strpos( $software, 'nginx' ) ) {
		return 'nginx';
	}
	if ( false !== strpos( $software, 'litespeed' ) ) {
		return 'litespeed';
	}
	if ( false !== strpos( $software, 'apache' ) ) {
		return 'apache';
	}
	return 'unknown';
}

/**
 * Whether .htaccess rewrite delivery can work on this server.
 * Nginx (Aero's usual home) and unknown servers ignore .htaccess entirely,
 * so delivery must happen in PHP via <picture> markup instead.
 */
function aero_io_htaccess_supported() {
	$type = aero_io_server_type();
	return ( 'apache' === $type || 'litespeed' === $type );
}

/**
 * Force a working delivery mode for the current server. On Nginx (or when
 * the server is unrecognized) any .htaccess mode silently does nothing, so
 * the mode is switched to picture-tag delivery, which is pure PHP: no
 * php.ini, no server config, no wp-config edits needed.
 */
function aero_io_enforce_server_delivery() {
	if ( aero_io_htaccess_supported() ) {
		return;
	}
	$options = Aero_IO_Options::get_option( 'aero_io_general_settings', array() );
	$options = is_array( $options ) ? $options : array();
	$mode    = isset( $options['image_load'] ) ? $options['image_load'] : 'htaccess';
	if ( 'picture' === $mode ) {
		return;
	}
	$options['image_load'] = 'picture';
	Aero_IO_Options::update_option( 'aero_io_general_settings', $options );
	// Remove any stale rewrite markers so image URLs resolve normally.
	if ( class_exists( 'Aero_IO_Webp_Rewrite' ) ) {
		$rewrite = new Aero_IO_Webp_Rewrite();
		$rewrite->remove_rewrite_rule();
	}
}

// ─── Background task engine (WP-Cron chain) ──────────────────────────────────
// The optimizer must keep working after the browser tab closes or reloads.
// A single-driver cron chain owns all processing: each event handles one
// engine batch (~5 images / up to 90s) and schedules the next. A rescue
// event is armed BEFORE each batch so a fatal mid-batch cannot kill the
// chain. The AJAX layer only schedules/observes — it never processes.

define( 'AERO_IO_CRON_MEDIA', 'aero_io_process_media_task' );
define( 'AERO_IO_CRON_CUSTOM', 'aero_io_process_custom_task' );

/**
 * Ensure a runner event is scheduled for the given hook.
 */
function aero_io_schedule_runner( $hook, $delay = 1 ) {
	if ( ! wp_next_scheduled( $hook ) ) {
		wp_schedule_single_event( time() + max( 0, (int) $delay ), $hook );
	}
}

/**
 * Ask WP-Cron to fire as soon as possible (non-blocking loopback).
 */
function aero_io_spawn_cron() {
	if ( function_exists( 'spawn_cron' ) ) {
		spawn_cron( microtime( true ) );
	}
}

/**
 * Clear every scheduled runner for a hook.
 */
function aero_io_clear_runner( $hook ) {
	wp_clear_scheduled_hook( $hook );
}

/**
 * Shared batch driver for both task types.
 *
 * @param object $task Aero_IO_ImgOptim_Task or Aero_IO_Custom_ImgOptim_Task.
 * @param string $hook Runner hook to (re)schedule.
 */
function aero_io_task_option_key( $task ) {
	return ( $task instanceof Aero_IO_Custom_ImgOptim_Task ) ? 'aero_io_custom_image_opt_task' : 'aero_io_image_opt_task';
}

/**
 * A cancel request is stored as a flag rather than relying on option
 * deletion alone: the engine writes the whole task back per processed
 * image, which resurrects a deleted task mid-batch. The driver honors the
 * flag at every batch boundary and deletes whatever came back.
 */
function aero_io_cancel_flag( $hook ) {
	return 'aero_io_cancel_' . md5( $hook );
}

function aero_io_request_cancel( $task, $hook ) {
	update_option( aero_io_cancel_flag( $hook ), '1', false );
	Aero_IO_Options::delete_option( aero_io_task_option_key( $task ) );
	aero_io_clear_runner( $hook );
	delete_transient( 'aero_io_set_global_stats' );
}

function aero_io_consume_cancel( $task, $hook ) {
	if ( get_option( aero_io_cancel_flag( $hook ) ) !== '1' ) {
		return false;
	}
	Aero_IO_Options::delete_option( aero_io_task_option_key( $task ) );
	delete_option( aero_io_cancel_flag( $hook ) );
	aero_io_clear_runner( $hook );
	return true;
}

function aero_io_drive_task( $task, $hook ) {
	if ( aero_io_consume_cancel( $task, $hook ) ) {
		return;
	}

	$status = $task->get_task_status();

	if ( 'success' !== $status['result'] || ! isset( $status['status'] ) ) {
		// No task / already reported done — nothing to drive.
		return;
	}
	if ( 'finished' === $status['status'] ) {
		return;
	}
	if ( 'running' === $status['status'] ) {
		// Another runner is mid-batch. Check staleness before assuming life:
		// a fatal mid-batch leaves status=running forever. The engine treats
		// >180s as dead; use the same threshold, then let the timeout logic
		// in do_optimize_image()/check_timeout() decide whether to skip the
		// poisoned image and carry on.
		$raw  = Aero_IO_Options::get_option( aero_io_task_option_key( $task ), array() );
		$last = isset( $raw['last_update_time'] ) ? (int) $raw['last_update_time'] : 0;
		if ( $last && ( time() - $last ) < 180 ) {
			aero_io_schedule_runner( $hook, 30 );
			return;
		}
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 );
	}
	if ( function_exists( 'ignore_user_abort' ) ) {
		@ignore_user_abort( true );
	}

	// WP-Cron cannot respawn itself while its own lock is held, so on a
	// quiet site the next scheduled event would wait for the next external
	// trigger. Chain batches inside this single event instead: each engine
	// batch is ~5 images / up to 90s, looped under a wall-clock budget.
	$budget = time() + 100;

	while ( true ) {
		// Rescue event first: if this request dies mid-batch, the chain
		// survives and picks the task back up.
		aero_io_schedule_runner( $hook, 150 );

		$task->do_optimize_image();

		if ( aero_io_consume_cancel( $task, $hook ) ) {
			return;
		}

		$status = $task->get_task_status();
		$more   = ( 'success' === $status['result'] && isset( $status['status'] ) && in_array( $status['status'], array( 'completed', 'running' ), true ) );

		if ( ! $more || time() >= $budget ) {
			break;
		}
	}

	// Replace the rescue slot with the real next step (or stop).
	aero_io_clear_runner( $hook );

	if ( $more ) {
		aero_io_schedule_runner( $hook, 2 );
		aero_io_spawn_cron();
	}
	// finished → engine already fired aero_io_purge_cache and cleared the
	// stats cache; error → stop and let the UI surface the message.
}

function aero_io_cron_process_media() {
	if ( ! aero_io_is_enabled() ) {
		return;
	}
	$task = new Aero_IO_ImgOptim_Task();
	aero_io_drive_task( $task, AERO_IO_CRON_MEDIA );
}
add_action( AERO_IO_CRON_MEDIA, 'aero_io_cron_process_media' );

function aero_io_cron_process_custom() {
	if ( ! aero_io_is_enabled() || ! class_exists( 'Aero_IO_Custom_ImgOptim_Task' ) ) {
		return;
	}
	$task = new Aero_IO_Custom_ImgOptim_Task();
	aero_io_drive_task( $task, AERO_IO_CRON_CUSTOM );
}
add_action( AERO_IO_CRON_CUSTOM, 'aero_io_cron_process_custom' );

// ─── Frontend + upload pipeline ───────────────────────────────────────────────
// Everything below is behind the master switch: with the optimizer off, no
// hooks are registered at all, on the front end or in the upload pipeline.
if ( aero_io_is_enabled() ) {
	// Extended delivery: CSS backgrounds, lazy-load attributes, stylesheets.
	require_once AERO_IO_DIR . '/delivery.php';

	// Picture_Load self-guards (admin/ajax/cron/multisite/htaccess bail out).
	new Aero_IO_Picture_Load();
	new Aero_IO_Auto_Optimization();
}

// One-time-per-version guard: existing installs whose stored mode cannot work
// on this server (e.g. .htaccess mode on Nginx) are migrated automatically.
function aero_io_maybe_migrate_delivery() {
	if ( ! aero_io_is_enabled() ) {
		return;
	}
	if ( get_option( 'aero_io_delivery_checked' ) === AERO_PLUGIN_VERSION_NUM ) {
		return;
	}
	update_option( 'aero_io_delivery_checked', AERO_PLUGIN_VERSION_NUM );
	aero_io_enforce_server_delivery();
}
add_action( 'admin_init', 'aero_io_maybe_migrate_delivery', 4 );

// ─── Admin surface ────────────────────────────────────────────────────────────
if ( is_admin() ) {
	require_once AERO_IO_DIR . '/engine/class-aero-io-manual-optimization.php';
	require_once AERO_IO_DIR . '/engine/class-aero-io-media-lib.php';
	require_once AERO_IO_DIR . '/admin/ajax.php';
	require_once AERO_IO_DIR . '/admin/images-screen.php';
	require_once AERO_IO_DIR . '/admin/conflicts.php';
	require_once AERO_IO_DIR . '/admin/notices.php';
	require_once AERO_IO_DIR . '/admin/url-replace.php';

	// Per-image actions in the media library follow the master switch: with
	// the optimizer off there is nothing meaningful to offer there.
	if ( aero_io_is_enabled() ) {
		new Aero_IO_Manual_Optimization();
		new Aero_IO_Custom_Media_Lib();

		// Media library assets (list mode column + attachment edit box).
		add_action( 'admin_enqueue_scripts', 'aero_io_enqueue_media_assets' );
	}

	Aero_IO_Stats_Manager::init();
}

function aero_io_enqueue_media_assets( $hook ) {
	if ( ! in_array( $hook, array( 'upload.php', 'post.php' ), true ) ) {
		return;
	}
	if ( 'post.php' === $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'attachment' !== $screen->id ) {
			return;
		}
	}
	$js  = AERO_IO_DIR . '/admin/assets/media.js';
	$css = AERO_IO_DIR . '/admin/assets/media.css';
	wp_enqueue_style( 'aero-io-media', AERO_IO_URL . '/admin/assets/media.css', array(), file_exists( $css ) ? filemtime( $css ) : AERO_PLUGIN_VERSION_NUM );
	wp_enqueue_script( 'aero-io-media', AERO_IO_URL . '/admin/assets/media.js', array( 'jquery' ), file_exists( $js ) ? filemtime( $js ) : AERO_PLUGIN_VERSION_NUM, true );
	wp_localize_script(
		'aero-io-media',
		'aeroIoMedia',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'aero_io_ajax' ),
		)
	);
}
