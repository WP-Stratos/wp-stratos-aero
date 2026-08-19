<?php
/**
 * Aero Cache Manager — Extend Batcache (mu-plugin)
 * Extends Batcache storage time to 24 hours.
 */

// Batcache Customizations
global $batcache;

// Batcache params may be an object or an array; apply customizations accordingly
if ( is_object( $batcache ) ) {
	$batcache->max_age = 86400; // Seconds the cached render of a page will be stored
	$batcache->seconds = 1200;  // Window in which at least N visitors are required to trigger caching
} elseif ( is_array( $batcache ) ) {
	$batcache['max_age'] = 86400;
	$batcache['seconds'] = 1200;
}
