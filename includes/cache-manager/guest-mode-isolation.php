<?php
/**
 * Aero Cache Manager — Guest Mode Cache Isolation (EXPERIMENTAL)
 *
 * PROBLEM: Aero's Guest Mode serves a stripped-down "super optimized" page to
 * performance-testing bots (PageSpeed, Lighthouse, GTmetrix…). If such a bot
 * is the first visitor after a cache flush, Batcache stores the Guest Mode
 * render and the Edge Cache serves that stale/static Guest Mode page to REAL
 * visitors afterwards — broken layouts, missing JS, the lot.
 *
 * FIX (two layers — both are needed for full protection):
 *
 * 1. PLUGIN LEVEL (this file): when the visitor is a guest (bot), Guest Mode
 *    is on, and isolation is enabled:
 *      • define DONOTCACHEPAGE — Batcache checks this constant before
 *        STORING the rendered page, so guest output never enters the cache.
 *      • batcache_cancel() — belt and braces for older Batcache versions.
 *      • Send Cache-Control: no-store — the Edge Cache/CDN will not store
 *        the response either.
 *    ➜ This stops guest-mode pages from POISONING the shared cache pool.
 *
 * 2. WP-CONFIG LEVEL (snippet, shown in the Experimental tab): Batcache SERVES
 *    cached pages from advanced-cache.php, BEFORE any plugin loads — so plugin
 *    code cannot prevent an already-cached page from being served to a bot,
 *    nor can it vary the cache bucket at serve time. The snippet separates the
 *    Batcache bucket by user-agent class ($batcache['unique']) so bots and
 *    humans can never share a cache entry, even if layer 1 is bypassed.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is the isolation feature enabled? (Experimental tab toggle)
 */
function aero_cm_guest_isolation_enabled() {
	return '1' === get_option( 'aero_cm_guest_isolation', '' );
}

/**
 * Layer 1 — prevent guest-mode responses from being cached anywhere.
 *
 * Runs very early on template_redirect (before Aero's HTML buffer starts at
 * priority 1) and again as a safety net on send_headers.
 */
function aero_cm_guest_isolation_no_store() {
	if ( ! aero_cm_guest_isolation_enabled() ) {
		return;
	}
	if ( is_admin() ) {
		return;
	}
	if ( ! function_exists( 'aero_is_guest_visitor' ) || ! aero_is_guest_visitor() ) {
		return;
	}

	// Batcache checks DONOTCACHEPAGE before storing the page.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}

	// Older Batcache builds: explicit cancel.
	if ( function_exists( 'batcache_cancel' ) ) {
		batcache_cancel();
	}

	// Edge Cache / CDN: never store this response.
	if ( ! headers_sent() ) {
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Aero-Guest-Isolation: no-store' );
	}
}
add_action( 'template_redirect', 'aero_cm_guest_isolation_no_store', 0 );
add_action( 'send_headers', 'aero_cm_guest_isolation_no_store', 0 );

/**
 * Layer 2 — the wp-config.php snippet for Batcache bucket separation.
 *
 * Must live in wp-config.php (or a file loaded before advanced-cache.php)
 * because Batcache serves cached pages before plugins load. The snippet keys
 * the cache bucket on the visitor class, so a page cached for a bot can never
 * be served to a human and vice versa.
 */
function aero_cm_guest_isolation_wpconfig_snippet() {
	return <<<'SNIPPET'
/* ── Aero Guest Mode cache isolation (place ABOVE "That's all, stop editing!") ── */
if ( ! isset( $batcache ) || ! is_array( $batcache ) ) { $batcache = array(); }
$aero_ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( $_SERVER['HTTP_USER_AGENT'] ) : '';
$aero_guest_patterns = array(
	'lighthouse', 'gtmetrix', 'pagespeed', 'google page speed', 'webpagetest',
	'pingdom', 'chrome-lighthouse', 'speed insights', 'dareboost', 'yellowlab',
	'dotcom-monitor', 'uptrends',
);
$aero_variant = ( '' === $aero_ua ) ? 'guest' : 'human';
foreach ( $aero_guest_patterns as $aero_pat ) {
	if ( false !== stripos( $aero_ua, $aero_pat ) ) { $aero_variant = 'guest'; break; }
}
$batcache['unique']['aero_variant'] = $aero_variant;
unset( $aero_ua, $aero_guest_patterns, $aero_variant, $aero_pat );
/* ── End Aero Guest Mode cache isolation ── */
SNIPPET;
}

/**
 * Remove the Layer 2 snippet from wp-config.php. Marker-based: everything
 * between the Aero guest-isolation start/end comments goes, with a timestamped
 * backup written first. Returns true on success, false when wp-config is
 * missing, unwritable, or the snippet isn't present.
 */
function aero_cm_guest_isolation_remove_snippet() {
	$config = ABSPATH . 'wp-config.php';
	if ( ! file_exists( $config ) && file_exists( dirname( ABSPATH ) . '/wp-config.php' ) ) {
		$config = dirname( ABSPATH ) . '/wp-config.php';
	}
	if ( ! file_exists( $config ) || ! is_writable( $config ) ) {
		return false;
	}

	$contents = file_get_contents( $config );
	if ( false === $contents ) {
		return false;
	}

	$pattern = '#\n?/\* ── Aero Guest Mode cache isolation.*?/\* ── End Aero Guest Mode cache isolation ── \*/\n?#s';
	if ( ! preg_match( $pattern, $contents ) ) {
		return false; // nothing to remove
	}

	// Backup first (same convention as the Batcache configurator).
	$backup_dir = WP_CONTENT_DIR . '/aero-backups/';
	if ( ! is_dir( $backup_dir ) ) {
		wp_mkdir_p( $backup_dir );
	}
	if ( is_dir( $backup_dir ) && is_writable( $backup_dir ) ) {
		copy( $config, $backup_dir . 'wp-config-' . gmdate( 'Ymd-His' ) . '.php.bak' );
	}

	$new = preg_replace( $pattern, "\n", $contents, 1 );
	if ( null === $new || false === file_put_contents( $config, $new ) ) {
		return false;
	}
	return ! aero_cm_guest_isolation_snippet_installed();
}

/**
 * Detect whether the wp-config snippet appears to be installed.
 */
function aero_cm_guest_isolation_snippet_installed() {
	if ( ! function_exists( 'aero_get_wp_config_path' ) ) {
		return false;
	}
	$config_path = aero_get_wp_config_path();
	if ( ! $config_path || ! is_readable( $config_path ) ) {
		return false;
	}
	$contents = file_get_contents( $config_path );
	return ( false !== strpos( $contents, "aero_variant" ) );
}
