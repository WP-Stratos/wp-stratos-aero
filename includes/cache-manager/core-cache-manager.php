<?php
/**
 * Aero Cache Manager — Core Sequential Flush Engine
 *
 * Executes cache purges in a customizable, strictly sequential order.
 * Default: Aero (minified CSS/JS) → Batcache / Object Cache → Edge Cache.
 *
 * Each step returns a human-readable message; the engine collects and
 * returns them so callers (admin bar, settings page, cron) can report.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Step: Aero minified CSS/JS cache ────────────────────────────────────────
function aero_cm_step_flush_aero() {
	$deleted = 0;
	if ( function_exists( 'aero_delete_files_in_directory_count' ) ) {
		$deleted  = aero_delete_files_in_directory_count( AERO_CSS_CACHE_DIR );
		$deleted += aero_delete_files_in_directory_count( AERO_JS_CACHE_DIR );
	}
	update_option( 'aero_minified_files', array() );
	update_option( 'aero-cache-flush-time-stamp', gmdate( 'j M Y, g:ia' ) . ' UTC' );

	return array(
		'success' => true,
		'message' => $deleted > 0
			? sprintf( __( 'Aero Cache cleared (%d minified files removed).', 'aero' ), $deleted )
			: __( 'Aero Cache cleared (no minified files present).', 'aero' ),
	);
}

// ─── Step: Batcache + Object Cache ───────────────────────────────────────────
function aero_cm_step_flush_batcache() {
	// Flush WP Object Cache (Redis / Memcached)
	wp_cache_flush();

	// Flush Batcache page cache if available
	if ( function_exists( 'batcache_clear_cache' ) ) {
		batcache_clear_cache();
	}

	// Other page-cache plugins (parity with PCM's flush behavior)
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache(); // WP Super Cache
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all(); // W3 Total Cache
	}
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain(); // WP Rocket
	}

	// Integration hook for other plugins
	do_action( 'aero_cm_flush_all_cache' );

	// Clear the cached Batcache status so the badge re-probes
	do_action( 'aero_cm_after_object_cache_flush' );
	delete_transient( 'aero_cm_batcache_status' );

	update_option( 'flush-obj-cache-time-stamp', gmdate( 'j M Y, g:ia' ) . ' UTC' );

	return array(
		'success' => true,
		'message' => __( 'Object Cache & Batcache flushed successfully.', 'aero' ),
	);
}

// ─── Step: Edge Cache ────────────────────────────────────────────────────────
function aero_cm_step_flush_edge( $context = 'aero-sequential-purge' ) {
	if ( ! class_exists( 'Edge_Cache_Plugin' ) ) {
		return array(
			'success' => false,
			'message' => __( 'Edge Cache Plugin not found; skipping Edge Cache purge.', 'aero' ),
		);
	}

	$edge_cache = Edge_Cache_Plugin::get_instance();

	// Auto-enable if disabled (mirrors PCM's dashboard purge behavior)
	$status_method = method_exists( $edge_cache, 'get_ec_status' ) ? 'get_ec_status' : null;
	$enable_method = method_exists( $edge_cache, 'enable_ec' ) ? 'enable_ec' : null;
	$server_status = $status_method ? $edge_cache->$status_method() : null;
	$auto_enabled  = false;

	if ( defined( 'Edge_Cache_Plugin::EC_DISABLED' ) && Edge_Cache_Plugin::EC_DISABLED === $server_status ) {
		if ( null !== $enable_method && $edge_cache->$enable_method() ) {
			$auto_enabled = true;
			sleep( 2 );
		} else {
			return array(
				'success' => false,
				'message' => __( 'Edge Cache is disabled and could not be auto-enabled; purge skipped.', 'aero' ),
			);
		}
	}

	if ( ! method_exists( $edge_cache, 'purge_domain_now' ) ) {
		return array(
			'success' => false,
			'message' => __( 'Edge Cache Plugin active, but purge method unavailable.', 'aero' ),
		);
	}

	$result = $edge_cache->purge_domain_now( $context );

	if ( $result ) {
		update_option( 'edge-cache-purge-time-stamp', gmdate( 'j M Y, g:ia' ) . ' UTC' );
		// Batcache is implicitly invalidated too — force the badge to re-probe.
		do_action( 'aero_cm_after_edge_cache_purge' );
		delete_transient( 'aero_cm_batcache_status' );

		return array(
			'success' => true,
			'message' => $auto_enabled
				? __( 'Edge Cache was disabled; it has been auto-enabled and purged successfully.', 'aero' )
				: __( 'Edge Cache purged successfully.', 'aero' ),
		);
	}

	return array(
		'success' => false,
		'message' => __( 'Edge Cache purge failed (possibly disabled or rate-limited).', 'aero' ),
	);
}

/**
 * Run the full sequential flush in the user-configured order.
 *
 * @param string $context  Identifier passed to Edge Cache purge for audit.
 * @param array  $only     Optional subset of steps to run (still in configured order).
 * @return array[] List of step results: [ step, success, message ].
 */
function aero_cm_run_sequential_flush( $context = 'aero-sequential-purge', $only = array() ) {
	$order   = aero_cm_get_purge_order();
	$results = array();

	foreach ( $order as $step ) {
		if ( ! empty( $only ) && ! in_array( $step, $only, true ) ) {
			continue;
		}
		switch ( $step ) {
			case 'aero':
				$r = aero_cm_step_flush_aero();
				break;
			case 'batcache':
				$r = aero_cm_step_flush_batcache();
				break;
			case 'edge':
				$r = aero_cm_step_flush_edge( $context );
				break;
			default:
				continue 2;
		}
		$r['step']  = $step;
		$results[]  = $r;
	}

	update_option( 'aero_cm_last_full_flush', gmdate( 'j M Y, g:ia' ) . ' UTC — ' . sanitize_text_field( $context ) );
	do_action( 'aero_cm_after_sequential_flush', $results, $context );

	return $results;
}

/**
 * Human-readable label for each purge step.
 */
function aero_cm_step_label( $step ) {
	$labels = array(
		'aero'     => __( 'Aero Cache (minified CSS/JS)', 'aero' ),
		'batcache' => __( 'Batcache / Object Cache', 'aero' ),
		'edge'     => __( 'Edge Cache', 'aero' ),
	);
	return isset( $labels[ $step ] ) ? $labels[ $step ] : $step;
}
