<?php
/**
 * Aero Cache Manager — Module Loader
 *
 * Integrates full cache management (object cache, Batcache, Edge Cache,
 * scheduled flushes, guest-mode isolation) into Aero.
 *
 * Purge philosophy: caches are flushed sequentially, innermost-out:
 *   Aero (minified CSS/JS) → Batcache / Object Cache → Edge Cache
 * The order is customizable via the aero_cm_purge_order option.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'AERO_CM_DIR' ) ) {
	define( 'AERO_CM_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'AERO_CM_URL' ) ) {
	define( 'AERO_CM_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'AERO_CM_MU_DIR' ) ) {
	define( 'AERO_CM_MU_DIR', WP_CONTENT_DIR . '/mu-plugins/aero-cache-manager/' );
}
if ( ! defined( 'AERO_CM_MU_INDEX' ) ) {
	define( 'AERO_CM_MU_INDEX', WP_CONTENT_DIR . '/mu-plugins/aero-cache-manager.php' );
}

/**
 * Main options getter with defaults.
 */
function aero_cm_get_options() {
	$defaults = array(
		'extend_batcache_checkbox'                   => '',
		'flush_cache_theme_plugin_checkbox'          => '',
		'flush_cache_page_edit_checkbox'             => '',
		'flush_cache_on_page_post_delete_checkbox'   => '',
		'flush_cache_on_comment_delete_checkbox'     => '',
		'flush_object_cache_for_single_page'         => '',
		'flush_batcache_for_woo_product_page'        => '',
		'exempt_from_batcache'                       => '',
		'cache_wpp_cookies_pages'                    => '',
		'exclude_query_string_gclid'                 => '',
	);
	$opts = get_option( 'aero_cm_options', array() );
	if ( ! is_array( $opts ) ) {
		$opts = array();
	}
	return wp_parse_args( $opts, $defaults );
}

/**
 * The configurable sequential purge order.
 * Valid steps: aero, batcache, edge.
 */
function aero_cm_get_purge_order() {
	$order = get_option( 'aero_cm_purge_order', array( 'aero', 'batcache', 'edge' ) );
	if ( ! is_array( $order ) || empty( $order ) ) {
		$order = array( 'aero', 'batcache', 'edge' );
	}
	// Sanitize: only known steps, no duplicates.
	$valid = array( 'aero', 'batcache', 'edge' );
	$order = array_values( array_unique( array_intersect( $order, $valid ) ) );
	if ( empty( $order ) ) {
		$order = array( 'aero', 'batcache', 'edge' );
	}
	return $order;
}

// ─── Core engine (always loaded — needed by cron, triggers, AJAX) ────────────
require_once AERO_CM_DIR . 'core-cache-manager.php';
require_once AERO_CM_DIR . 'batcache-manager-lib.php';
require_once AERO_CM_DIR . 'scheduled-flush.php';
require_once AERO_CM_DIR . 'cache-warmer.php';
require_once AERO_CM_DIR . 'guest-mode-isolation.php';

// ─── Front-end + admin cache flush triggers ──────────────────────────────────
require_once AERO_CM_DIR . 'flush-triggers.php';
require_once AERO_CM_DIR . 'single-page-flush.php';
require_once AERO_CM_DIR . 'admin-bar.php';

// ─── Admin-only includes ─────────────────────────────────────────────────────
if ( is_admin() ) {
	require_once AERO_CM_DIR . 'admin-ui.php';
	require_once AERO_CM_DIR . 'edge-cache.php';
	require_once AERO_CM_DIR . 'batcache-status.php';
	require_once AERO_CM_DIR . 'batcache-tools.php';
	require_once AERO_CM_DIR . 'settings-page.php';
	require_once AERO_CM_DIR . 'debug-page.php';
}
