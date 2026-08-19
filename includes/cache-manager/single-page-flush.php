<?php
/**
 * Aero Cache Manager — Single-Page Flush
 *
 * 1. "Flush Cache" row action link on Pages/Posts list tables.
 * 2. "Flush Cache" node on the frontend admin toolbar (page preview) that
 *    fires the sequential Batcache → Edge Cache flush for the current URL.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function aero_cm_single_page_can_view() {
	return current_user_can( 'administrator' ) || current_user_can( 'editor' ) || current_user_can( 'manage_woocommerce' );
}

$aero_cm_sp_options = aero_cm_get_options();

if ( ! empty( $aero_cm_sp_options['flush_object_cache_for_single_page'] ) ) {

	// ─── Frontend toolbar + AJAX endpoints ──────────────────────────────────
	if ( ! class_exists( 'Aero_Flush_Cache_Adminbar' ) ) {

		class Aero_Flush_Cache_Adminbar {

			public function add() {
				if ( ! is_admin() && is_admin_bar_showing() ) {
					add_action( 'wp_before_admin_bar_render', array( $this, 'toolbar_for_page_preview' ) );
					add_action( 'wp_enqueue_scripts', array( $this, 'load_toolbar_assets' ) );
				}
				if ( is_admin() ) {
					add_action( 'admin_enqueue_scripts', array( $this, 'load_toolbar_assets' ) );
				}

				// AJAX: flush Batcache for current page
				add_action( 'wp_ajax_aero_cm_delete_current_page_cache', array( $this, 'delete_current_page_cache' ) );
				// AJAX: purge Edge Cache for current page
				add_action( 'wp_ajax_aero_cm_purge_current_page_edge_cache', array( $this, 'purge_current_page_edge_cache' ) );
			}

			public function delete_current_page_cache() {
				if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'aero_cm_nonce' ) ) {
					wp_send_json_error( array( 'reason' => 'Security Error!' ), 403 );
				}
				if ( ! aero_cm_single_page_can_view() ) {
					wp_send_json_error( array( 'reason' => 'Unauthorized' ), 403 );
				}

				global $batcache, $wp_object_cache;

				if ( ! isset( $batcache ) || ! is_object( $batcache ) || ! method_exists( $wp_object_cache, 'incr' ) ) {
					wp_send_json_error( array( 'reason' => 'Batcache not available' ) );
				}

				$batcache->configure_groups();
				$path = isset( $_GET['path'] ) ? urldecode( esc_url_raw( wp_unslash( $_GET['path'] ) ) ) : '';

				if ( preg_match( '/\.{2,}/', $path ) ) {
					wp_send_json_error( array( 'reason' => 'Suspected Directory Traversal Attack' ), 400 );
				}

				$url = get_home_url() . $path;
				update_option( 'single-page-url-flushed', $url );

				if ( class_exists( 'Aero_Batcache_Manager' ) ) {
					Aero_Batcache_Manager::clear_url( $url );

				if ( function_exists( 'aero_cw_warm_urls' ) ) {
					aero_cw_warm_urls( array( $url ), __( 'Per-page flush', 'aero' ) );
				}
				}

				do_action( 'aero_cm_after_batcache_flush' );
				delete_transient( 'aero_cm_batcache_status' );
				update_option( 'flush-object-cache-for-single-page-time-stamp', gmdate( 'j M Y, g:ia' ) . ' UTC' );
				wp_send_json_success( array( 'flushed' => 'batcache' ) );
			}

			public function purge_current_page_edge_cache() {
				if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'aero_cm_nonce' ) ) {
					wp_send_json_error( array( 'reason' => 'Security Error!' ), 403 );
				}
				if ( ! aero_cm_single_page_can_view() ) {
					wp_send_json_error( array( 'reason' => 'Unauthorized' ), 403 );
				}

				$path = isset( $_GET['path'] ) ? urldecode( esc_url_raw( wp_unslash( $_GET['path'] ) ) ) : '';
				if ( preg_match( '/\.{2,}/', $path ) ) {
					wp_send_json_error( array( 'reason' => 'Suspected Directory Traversal Attack' ), 400 );
				}

				$url = get_home_url() . $path;
				update_option( 'edge-cache-single-page-url-purged', $url );

				if ( class_exists( 'Edge_Cache_Plugin' ) ) {
					$edge_cache = Edge_Cache_Plugin::get_instance();
					if ( method_exists( $edge_cache, 'purge_uris_now' ) ) {
						$edge_cache->purge_uris_now( array( $url ) );
						update_option( 'single-page-edge-cache-purge-time-stamp', gmdate( 'j M Y, g:ia' ) . ' UTC' );
						wp_send_json_success( array( 'flushed' => 'edge-cache' ) );
					}
				}

				wp_send_json_error( array( 'reason' => 'Edge_Cache_Plugin not available' ) );
			}

			public function load_toolbar_assets() {
				if ( ! aero_cm_single_page_can_view() ) {
					return;
				}

				wp_enqueue_style( 'aero-cm-toolbar',
					AERO_CM_URL . 'assets/toolbar.css',
					array(), AERO_PLUGIN_VERSION_NUM, 'all' );

				wp_enqueue_script( 'aero-cm-toolbar',
					AERO_CM_URL . 'assets/toolbar.js',
					array( 'jquery' ), AERO_PLUGIN_VERSION_NUM, true );

				// Nonce + edge-cache state, reliable on both admin and frontend.
				$edge_on = ( get_option( 'edge-cache-enabled' ) === 'enabled' ) ? '1' : '0';
				wp_localize_script( 'aero-cm-toolbar', 'aeroCmToolbarData', array(
					'nonce'     => wp_create_nonce( 'aero_cm_nonce' ),
					'ajaxurl'   => admin_url( 'admin-ajax.php' ),
					'flushEdge' => $edge_on,
				) );
			}

			public function toolbar_for_page_preview() {
				global $wp_admin_bar;

				$edge_cache_on = ( get_option( 'edge-cache-enabled' ) === 'enabled' );

				// Single label: include Edge Cache in the title when it is active
				$flush_label = $edge_cache_on
					? __( 'Flush Cache for This Page', 'aero' )
					: __( 'Flush Batcache for This Page', 'aero' );

				$wp_admin_bar->add_node( array(
					'id'    => 'aero-cm-toolbar-parent',
					'title' => __( 'Flush Cache', 'aero' ),
				) );

				// Combined item — JS fires Batcache then Edge Cache flush in sequence
				$wp_admin_bar->add_menu( array(
					'id'     => 'aero-cm-flush-cache-of-this-page',
					'title'  => $flush_label,
					'parent' => 'aero-cm-toolbar-parent',
					'meta'   => array( 'class' => 'aero-cm-toolbar-child' ),
				) );
			}
		}
	}

	add_action( 'init', 'aero_cm_show_flush_cache_option_for_single_page' );
	function aero_cm_show_flush_cache_option_for_single_page() {
		if ( aero_cm_single_page_can_view() ) {
			$toolbar = new Aero_Flush_Cache_Adminbar();
			$toolbar->add();
		}
	}

	// ─── Row action "Flush Cache" link on Pages/Posts list tables ──────────
	if ( ! class_exists( 'Aero_Flush_Cache_Page_Column' ) ) {
		class Aero_Flush_Cache_Page_Column {

			public function add() {
				add_filter( 'post_row_actions', array( $this, 'add_flush_cache_link' ), 10, 2 );
				add_filter( 'page_row_actions', array( $this, 'add_flush_cache_link' ), 10, 2 );
				add_action( 'admin_enqueue_scripts', array( $this, 'load_js' ) );
				add_action( 'wp_ajax_aero_cm_flush_cache_column', array( $this, 'flush_cache_column' ) );
			}

			public function add_flush_cache_link( $actions, $post ) {
				if ( aero_cm_single_page_can_view() ) {
					$actions['aero_cm_flush_cache_url'] =
						'<a data-id="' . esc_attr( $post->ID ) . '"'
						. ' data-nonce="' . wp_create_nonce( 'aero-cm-flush-cache_' . $post->ID ) . '"'
						. ' class="aero-cm-flush-cache-link"'
						. ' id="aero-cm-flush-cache-url-' . esc_attr( $post->ID ) . '"'
						. ' style="cursor:pointer;">'
						. esc_html__( 'Flush Cache', 'aero' ) . '</a>';
				}
				return $actions;
			}

			public function load_js( $hook ) {
				if ( 'edit.php' !== $hook ) {
					return;
				}
				wp_enqueue_script( 'aero-cm-column',
					AERO_CM_URL . 'assets/column.js',
					array( 'jquery' ), AERO_PLUGIN_VERSION_NUM, true );
				wp_localize_script( 'aero-cm-column', 'aeroCmColumnData', array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
				) );
			}

			public function flush_cache_column() {
				if ( ! aero_cm_single_page_can_view() ) {
					wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
				}

				$post_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;

				if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'aero-cm-flush-cache_' . $post_id ) ) {
					wp_send_json_error( array( 'message' => 'Nonce verification failed' ), 403 );
				}

				$url = get_permalink( $post_id );
				update_option( 'page-title', get_the_title( $post_id ) );

				global $batcache, $wp_object_cache;
				if ( ! isset( $batcache ) || ! is_object( $batcache ) || ! method_exists( $wp_object_cache, 'incr' ) ) {
					wp_send_json_error( array( 'message' => 'Batcache not available' ) );
				}

				$batcache->configure_groups();

				if ( empty( $url ) ) {
					wp_send_json_error( array( 'message' => 'Empty URL' ) );
				}

				if ( class_exists( 'Aero_Batcache_Manager' ) ) {
					Aero_Batcache_Manager::clear_url( $url );
				}

				if ( function_exists( 'aero_cw_warm_urls' ) ) {
					aero_cw_warm_urls( array( $url ), __( 'Per-page flush', 'aero' ) );
				}

				// Also purge Edge Cache for this URL if the Edge Cache is on
				if ( get_option( 'edge-cache-enabled' ) === 'enabled' && class_exists( 'Edge_Cache_Plugin' ) ) {
					$edge_cache = Edge_Cache_Plugin::get_instance();
					if ( method_exists( $edge_cache, 'purge_uris_now' ) ) {
						$edge_cache->purge_uris_now( array( $url ) );
						update_option( 'single-page-edge-cache-purge-time-stamp', gmdate( 'j M Y, g:ia' ) . ' UTC' );
					}
				}

				do_action( 'aero_cm_after_batcache_flush' );
				delete_transient( 'aero_cm_batcache_status' );
				update_option( 'flush-object-cache-for-single-page-time-stamp', gmdate( 'j M Y, g:ia' ) . ' UTC' );

				wp_send_json_success( array( 'message' => __( 'Cache flushed for this page.', 'aero' ) ) );
			}
		}
	}

	add_action( 'init', 'aero_cm_show_flush_cache_column' );
	function aero_cm_show_flush_cache_column() {
		if ( aero_cm_single_page_can_view() ) {
			$column = new Aero_Flush_Cache_Page_Column();
			$column->add();
		}
	}
}
