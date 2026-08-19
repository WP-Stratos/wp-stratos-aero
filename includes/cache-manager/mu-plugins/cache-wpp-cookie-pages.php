<?php
/**
 * Aero Cache Manager — Cache pages that set wpp_ cookies (mu-plugin)
 *
 * Batcache by default skips all cookies starting with "wp", so pages that set
 * wpp_ cookies (e.g. Wonder Plugin) are never cached. Collect all wpp_ cookies
 * and add them to Batcache's noskip list so those pages can be cached.
 */

$aero_cm_all_wpp_cookies = array();
if ( is_array( $_COOKIE ) && ! empty( $_COOKIE ) ) {
	foreach ( array_keys( $_COOKIE ) as $maybe_wpp ) {
		if ( substr( $maybe_wpp, 0, 4 ) === 'wpp_' ) {
			$aero_cm_all_wpp_cookies[] = $maybe_wpp;
		}
	}
}

// Only add cookies to noskip if we found any starting with wpp_.
// wordpress_test_cookie is the default entry.
if ( count( $aero_cm_all_wpp_cookies ) > 0 ) {
	global $batcache;
	if ( is_array( $batcache ) ) {
		$batcache['noskip_cookies'] = array_merge( array( 'wordpress_test_cookie' ), $aero_cm_all_wpp_cookies );
	} elseif ( is_object( $batcache ) ) {
		$batcache->noskip_cookies = array_merge( array( 'wordpress_test_cookie' ), $aero_cm_all_wpp_cookies );
	}
}
