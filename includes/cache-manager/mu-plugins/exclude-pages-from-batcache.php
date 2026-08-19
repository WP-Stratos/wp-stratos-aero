<?php
/**
 * Aero Cache Manager — Exclude pages from Batcache and Edge Cache (mu-plugin)
 */

add_action( 'init', 'aero_cm_mu_cancel_the_cache' );

function aero_cm_mu_cancel_the_cache() {
	if ( ! function_exists( 'batcache_cancel' ) ) {
		return;
	}

	$options        = get_option( 'aero_cm_options' );
	$exempted_pages = isset( $options['exempt_from_batcache'] ) ? $options['exempt_from_batcache'] : '';

	if ( empty( $exempted_pages ) ) {
		return;
	}

	// Convert stored options into an array and trim spaces
	$exempted_pages = array_map( 'trim', explode( ',', $exempted_pages ) );

	// Get current URI without query parameters
	$uri = strtok( $_SERVER['REQUEST_URI'], '?' );

	// Always exclude homepage if listed explicitly
	if ( $uri === '/' && in_array( '/', $exempted_pages, true ) ) {
		batcache_cancel();
		aero_cm_mu_disable_edge_cache();
		return;
	}

	// Loop through exempted pages
	foreach ( $exempted_pages as $page ) {
		if ( '' === $page ) {
			continue;
		}
		// Match exact page or paginated versions (e.g., /about/, /about/page/2/)
		if ( $uri === $page || preg_match( '#^' . preg_quote( $page, '#' ) . '(/page/\d+/?)?$#i', $uri ) ) {
			batcache_cancel();
			aero_cm_mu_disable_edge_cache();
			return;
		}
	}
}

function aero_cm_mu_disable_edge_cache() {
	if ( ! headers_sent() ) {
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	}
}
