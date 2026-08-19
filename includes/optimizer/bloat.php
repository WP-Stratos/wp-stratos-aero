<?php
/**
 * Aero — WordPress Bloat Removal
 *
 * A Perfmatters-style panel of surgical remove_action toggles. Two tiers:
 *
 *  SAFE (on by default — no realistic site can break):
 *   - Emoji script & styles (~15KB of JS/CSS nobody needs since UTF-8)
 *   - Head cleanup: RSD link, wlwmanifest, shortlink, WP generator meta
 *
 *  REVIEW (off by default — safe for most, but each has a legitimate use):
 *   - oEmbed/embeds (wp-embed.js + discovery links)
 *   - jQuery Migrate (needed only by pre-2016 jQuery code)
 *   - Dashicons for logged-out visitors (some themes use them frontend)
 *   - XML-RPC (Jetpack and the mobile apps still use it)
 *
 *  TUNING:
 *   - Heartbeat behavior (default / frontend disabled / fully disabled)
 *   - Autosave interval
 *   - Post revision limit
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Option access with tiered defaults ───────────────────────────────────────

function aero_bloat_defaults() {
	return array(
		'emojis'         => 'on',
		'head_cleanup'   => 'on',
		'embeds'         => 'off',
		'jquery_migrate' => 'off',
		'dashicons'      => 'off',
		'xmlrpc'         => 'off',
		'heartbeat'      => 'frontend',  // default | frontend | disable
		'autosave'       => '60',        // seconds
		'revisions'      => '',          // '' = WP default (unlimited)
	);
}

function aero_bloat_opts() {
	$saved = get_option( 'aero_bloat', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), aero_bloat_defaults() );
}

function aero_bloat_on( $key ) {
	$opts = aero_bloat_opts();
	return isset( $opts[ $key ] ) && 'on' === $opts[ $key ];
}

// ─── Emojis ───────────────────────────────────────────────────────────────────
add_action( 'init', 'aero_bloat_disable_emojis', 5 );
function aero_bloat_disable_emojis() {
	if ( ! aero_bloat_on( 'emojis' ) ) {
		return;
	}
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

	add_filter( 'tiny_mce_plugins', function( $plugins ) {
		return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
	} );
	add_filter( 'wp_resource_hints', function( $urls, $relation_type ) {
		if ( 'dns-prefetch' === $relation_type ) {
			$urls = array_diff( $urls, array( 'https://s.w.org/images/core/emoji/' ) );
			foreach ( $urls as $i => $u ) {
				$href = is_array( $u ) && isset( $u['href'] ) ? $u['href'] : $u;
				if ( is_string( $href ) && false !== strpos( $href, 's.w.org' ) ) {
					unset( $urls[ $i ] );
				}
			}
		}
		return $urls;
	}, 10, 2 );
}

// ─── Head cleanup ─────────────────────────────────────────────────────────────
add_action( 'init', 'aero_bloat_head_cleanup', 5 );
function aero_bloat_head_cleanup() {
	if ( ! aero_bloat_on( 'head_cleanup' ) ) {
		return;
	}
	remove_action( 'wp_head', 'rsd_link' );                 // Really Simple Discovery
	remove_action( 'wp_head', 'wlwmanifest_link' );          // Windows Live Writer
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );      // <link rel=shortlink>
	remove_action( 'wp_head', 'wp_generator' );              // WP version meta
	add_filter( 'the_generator', '__return_empty_string' );
}

// ─── Embeds / oEmbed ──────────────────────────────────────────────────────────
add_action( 'init', 'aero_bloat_disable_embeds', 9999 );
function aero_bloat_disable_embeds() {
	if ( ! aero_bloat_on( 'embeds' ) ) {
		return;
	}
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'wp_head', 'wp_oembed_add_host_js' );
	remove_action( 'rest_api_init', 'wp_oembed_register_route' );
	remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );

	add_action( 'wp_enqueue_scripts', function() {
		wp_deregister_script( 'wp-embed' );
	}, 100 );
	add_filter( 'tiny_mce_plugins', function( $plugins ) {
		return is_array( $plugins ) ? array_diff( $plugins, array( 'wpembed' ) ) : array();
	} );
}

// ─── jQuery Migrate ───────────────────────────────────────────────────────────
add_action( 'wp_default_scripts', 'aero_bloat_remove_jquery_migrate' );
function aero_bloat_remove_jquery_migrate( $scripts ) {
	if ( ! aero_bloat_on( 'jquery_migrate' ) || is_admin() ) {
		return;
	}
	if ( isset( $scripts->registered['jquery'] ) ) {
		$scripts->registered['jquery']->deps = array_diff(
			$scripts->registered['jquery']->deps,
			array( 'jquery-migrate' )
		);
	}
}

// ─── Dashicons for logged-out visitors ────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'aero_bloat_dequeue_dashicons', 100 );
function aero_bloat_dequeue_dashicons() {
	if ( ! aero_bloat_on( 'dashicons' ) || is_user_logged_in() ) {
		return;
	}
	wp_dequeue_style( 'dashicons' );
	wp_deregister_style( 'dashicons' );
}

// ─── XML-RPC ──────────────────────────────────────────────────────────────────
add_action( 'init', 'aero_bloat_disable_xmlrpc', 5 );
function aero_bloat_disable_xmlrpc() {
	if ( ! aero_bloat_on( 'xmlrpc' ) ) {
		return;
	}
	add_filter( 'xmlrpc_enabled', '__return_false' );
	add_filter( 'wp_headers', function( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	} );
	remove_action( 'wp_head', 'rsd_link' ); // covered by head cleanup too; harmless twice
}

// ─── Heartbeat ────────────────────────────────────────────────────────────────
add_action( 'init', 'aero_bloat_heartbeat', 5 );
function aero_bloat_heartbeat() {
	$opts = aero_bloat_opts();
	$mode = isset( $opts['heartbeat'] ) ? $opts['heartbeat'] : 'frontend';

	if ( 'disable' === $mode ) {
		wp_deregister_script( 'heartbeat' );
		return;
	}
	if ( 'frontend' === $mode && ! is_admin() ) {
		wp_deregister_script( 'heartbeat' );
	}
}

// ─── Autosave interval ────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'aero_bloat_autosave_interval' );
function aero_bloat_autosave_interval() {
	$opts     = aero_bloat_opts();
	$interval = max( 60, (int) $opts['autosave'] );
	if ( 60 === $interval ) {
		return; // WP default — nothing to do
	}
	if ( ! defined( 'AUTOSAVE_INTERVAL' ) ) {
		define( 'AUTOSAVE_INTERVAL', $interval );
	}
	// Block editor reads its own setting.
	add_filter( 'block_editor_settings_all', function( $settings ) use ( $interval ) {
		$settings['autosaveInterval'] = $interval;
		return $settings;
	} );
}

// ─── Post revisions limit ─────────────────────────────────────────────────────
add_filter( 'wp_revisions_to_keep', 'aero_bloat_revisions_limit', 10, 1 );
function aero_bloat_revisions_limit( $num ) {
	$opts = aero_bloat_opts();
	if ( '' === $opts['revisions'] || ! is_numeric( $opts['revisions'] ) ) {
		return $num;
	}
	return max( 0, (int) $opts['revisions'] );
}
