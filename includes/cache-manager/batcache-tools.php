<?php
/**
 * Aero Cache Manager — Batcache Tools (mu-plugin management)
 *
 * Copies/removes mu-plugin modules based on aero_cm_options toggles:
 *   • Extend Batcache by 24 hours
 *   • Exclude pages from Batcache & Edge Cache
 *   • Cache pages that set wpp_ cookies
 *   • Exclude the gclid query string from Batcache
 *   • Flush Batcache for WooCommerce product pages (Aero_Batcache_Manager)
 *
 * All mu-plugin modules live in wp-content/mu-plugins/aero-cache-manager/
 * and are loaded via wp-content/mu-plugins/aero-cache-manager.php.
 * Source files are always synced (md5 compare) so fixes deploy on update.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ensure the mu-plugins loader index + subdirectory exist.
 */
function aero_cm_ensure_mu_scaffolding() {
	if ( ! file_exists( WP_CONTENT_DIR . '/mu-plugins/' ) ) {
		wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins/' );
	}
	if ( ! file_exists( AERO_CM_MU_DIR ) ) {
		wp_mkdir_p( AERO_CM_MU_DIR );
	}
	$index_src = AERO_CM_DIR . 'mu-plugins/aero-cache-manager-index.php';
	if ( ! file_exists( AERO_CM_MU_INDEX ) || md5_file( $index_src ) !== md5_file( AERO_CM_MU_INDEX ) ) {
		@copy( $index_src, AERO_CM_MU_INDEX );
	}
}

/**
 * Sync a single mu-plugin module on/off.
 *
 * @param bool   $enabled  Whether the feature toggle is on.
 * @param string $src      Source filename inside includes/cache-manager/mu-plugins/.
 * @param string $dest     Destination filename inside mu-plugins/aero-cache-manager/.
 * @return string 'installed'|'updated'|'removed'|'unchanged'
 */
function aero_cm_sync_mu_module( $enabled, $src, $dest ) {
	$src_path  = AERO_CM_DIR . 'mu-plugins/' . $src;
	$dest_path = AERO_CM_MU_DIR . $dest;

	if ( $enabled ) {
		aero_cm_ensure_mu_scaffolding();

		if ( ! file_exists( $src_path ) ) {
			return 'unchanged';
		}

		if ( ! file_exists( $dest_path ) ) {
			// Fresh enable — copy and flush so the change takes effect
			if ( @copy( $src_path, $dest_path ) ) {
				wp_cache_flush();
				return 'installed';
			}
			return 'unchanged';
		}

		// Always sync so any updates to the source take effect immediately
		if ( md5_file( $src_path ) !== md5_file( $dest_path ) ) {
			@copy( $src_path, $dest_path );
			return 'updated';
		}

		return 'unchanged';
	}

	// Feature OFF — remove and flush
	if ( file_exists( $dest_path ) ) {
		@unlink( $dest_path );
		wp_cache_flush();
		return 'removed';
	}
	return 'unchanged';
}

/**
 * Sync all mu-plugin modules according to current options.
 * Runs on admin_init so toggles apply immediately after saving settings.
 */
function aero_cm_sync_all_mu_modules() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$options = aero_cm_get_options();

	$installed = array();

	// Extend Batcache by 24 hours
	$r = aero_cm_sync_mu_module(
		! empty( $options['extend_batcache_checkbox'] ),
		'extend-batcache.php',
		'aero-extend-batcache.php'
	);
	if ( 'installed' === $r ) {
		$installed[] = __( 'Batcache storage extended to 24 hours.', 'aero' );
	}

	// Exclude pages from Batcache & Edge Cache (only needed when list non-empty)
	aero_cm_sync_mu_module(
		! empty( $options['exempt_from_batcache'] ),
		'exclude-pages-from-batcache.php',
		'aero-exclude-pages-from-batcache.php'
	);

	// Cache pages that set wpp_ cookies
	aero_cm_sync_mu_module(
		! empty( $options['cache_wpp_cookies_pages'] ),
		'cache-wpp-cookie-pages.php',
		'aero-cache-wpp-cookie-pages.php'
	);

	// Exclude gclid query string
	aero_cm_sync_mu_module(
		! empty( $options['exclude_query_string_gclid'] ),
		'exclude-gclid-query-string.php',
		'aero-exclude-gclid-query-string.php'
	);

	// WooCommerce product-page Batcache flush — the batcache manager lib is
	// deployed as an mu-plugin so REST API product updates (which may not load
	// regular plugins in all contexts) still trigger targeted invalidation.
	$woo_src  = AERO_CM_DIR . 'batcache-manager-lib.php';
	$woo_dest = AERO_CM_MU_DIR . 'aero-batcache-manager.php';
	if ( ! empty( $options['flush_batcache_for_woo_product_page'] ) ) {
		aero_cm_ensure_mu_scaffolding();
		$needs_update = ! file_exists( $woo_dest )
			|| ( file_exists( $woo_src ) && md5_file( $woo_src ) !== md5_file( $woo_dest ) );
		if ( $needs_update && file_exists( $woo_src ) ) {
			$fresh = ! file_exists( $woo_dest );
			if ( @copy( $woo_src, $woo_dest ) && $fresh ) {
				wp_cache_flush();
				update_option( 'aero_cm_woo_activate_notice', 'activating' );
			}
		}
	} else {
		update_option( 'aero_cm_woo_activate_notice', 'activating' );
		if ( file_exists( $woo_dest ) ) {
			@unlink( $woo_dest );
			wp_cache_flush();
		}
	}

	if ( ! empty( $installed ) ) {
		update_option( 'aero_cm_mu_install_notice_pending', $installed );
	}
}
add_action( 'admin_init', 'aero_cm_sync_all_mu_modules', 20 );

// ─── One-time notices after fresh enables ─────────────────────────────────────
add_action( 'admin_notices', 'aero_cm_mu_install_notices' );
function aero_cm_mu_install_notices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || false === strpos( $screen->id, 'aero-cache-manager' ) ) {
		return;
	}

	$pending = get_option( 'aero_cm_mu_install_notice_pending' );
	if ( ! empty( $pending ) && is_array( $pending ) ) {
		delete_option( 'aero_cm_mu_install_notice_pending' );
		foreach ( $pending as $msg ) {
			aero_cm_branded_notice( $msg, '#22c55e' );
		}
	}

	// Woo activation notice (shows once)
	if ( 'activating' === get_option( 'aero_cm_woo_activate_notice' ) ) {
		$options = aero_cm_get_options();
		if ( ! empty( $options['flush_batcache_for_woo_product_page'] ) ) {
			update_option( 'aero_cm_woo_activate_notice', 'activated' );
			aero_cm_branded_notice(
				__( 'Flush Batcache for WooCommerce Product Pages — Enabled. Individual pages, including products updated via the WooCommerce API, will be flushed automatically.', 'aero' ),
				'#22c55e'
			);
		}
	}
}

/**
 * Remove all Aero Cache Manager mu-plugins (used on uninstall).
 */
function aero_cm_remove_all_mu_modules() {
	if ( is_dir( AERO_CM_MU_DIR ) ) {
		foreach ( glob( AERO_CM_MU_DIR . '*.php' ) as $f ) {
			@unlink( $f );
		}
		@rmdir( AERO_CM_MU_DIR );
	}
	if ( file_exists( AERO_CM_MU_INDEX ) ) {
		@unlink( AERO_CM_MU_INDEX );
	}
}
