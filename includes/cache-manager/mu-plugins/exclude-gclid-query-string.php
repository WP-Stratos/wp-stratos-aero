<?php
/**
 * Aero Cache Manager — Exclude gclid query string from Batcache (mu-plugin)
 *
 * Batcache by default creates a new cached page per query parameter. Google
 * Ads appends a unique gclid to every click, which would fragment the cache;
 * ignore it so ad traffic hits the same cached page.
 */

if ( ! function_exists( 'aero_cm_mu_exclude_gclid_from_batcache' ) ) {
	function aero_cm_mu_exclude_gclid_from_batcache() {
		global $batcache;
		if ( is_object( $batcache ) ) {
			$batcache->ignored_query_args = array( 'gclid' );
		} elseif ( is_array( $batcache ) ) {
			$batcache['ignored_query_args'] = array( 'gclid' );
		}
	}
}

add_action( 'plugins_loaded', 'aero_cm_mu_exclude_gclid_from_batcache' );
