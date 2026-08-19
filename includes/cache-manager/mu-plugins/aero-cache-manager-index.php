<?php
/**
 * Plugin Name: Aero Cache Manager (mu-plugins loader)
 * Description: Loads Aero Cache Manager must-use modules from the aero-cache-manager subdirectory.
 *
 * This file is copied to wp-content/mu-plugins/aero-cache-manager.php by the
 * Aero plugin. It is safe to delete if the Aero plugin has been removed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aero_cm_mu_dir = WP_CONTENT_DIR . '/mu-plugins/aero-cache-manager/';

if ( is_dir( $aero_cm_mu_dir ) ) {
	foreach ( glob( $aero_cm_mu_dir . '*.php' ) as $aero_cm_mu_file ) {
		require_once $aero_cm_mu_file;
	}
}
