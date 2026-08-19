<?php
/**
 * Aero — Debug Screen
 *
 * Debug Mode toggle plus a comprehensive, copy-ready diagnostic report
 * covering the entire plugin: core Aero settings, cache directories,
 * Batcache configuration, the full cache-manager module state (triggers,
 * purge order, schedule, Edge Cache, Defensive Mode, Guest Mode isolation,
 * deployed mu-plugins), and the server environment.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Save handler: Debug Mode toggle ─────────────────────────────────────────
add_action( 'admin_init', 'aero_cm_handle_debug_save', 5 );
function aero_cm_handle_debug_save() {
	if ( ! isset( $_POST['aero_cm_debug_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aero_cm_debug_nonce'] ) ), 'aero_cm_debug_save' ) ||
		 ! current_user_can( 'manage_options' ) ) {
		return;
	}

	update_option( 'aero_debug_mode', isset( $_POST['aero_debug_mode'] ) ? 'on' : 'off' );
	aero_cm_branded_notice( __( 'Debug settings saved.', 'aero' ), '#22c55e' );

	if ( function_exists( 'aero_ui_redirect' ) ) {
		aero_ui_redirect( 'aero-debug' );
	}
}

// ─── Cache-manager debug addendum ─────────────────────────────────────────────

/**
 * Everything the cache-manager module knows, as a plain-text report block.
 * Appended to aero_generate_debug_info() so one copy-paste covers the
 * whole plugin.
 */
function aero_cm_generate_debug_addendum() {
	$out  = "=== AERO CACHE MANAGER ===\n\n";

	// Options / triggers
	$opts = aero_cm_get_options();
	$out .= "--- FLUSH TRIGGERS & TOOLS ---\n";
	$map  = array(
		'flush_cache_theme_plugin_checkbox'        => 'Flush on plugin/theme update',
		'flush_cache_page_edit_checkbox'           => 'Targeted flush on page edit',
		'flush_cache_on_page_post_delete_checkbox' => 'Flush on page/post delete',
		'flush_cache_on_comment_delete_checkbox'   => 'Flush on comment delete',
		'flush_object_cache_for_single_page'       => 'Per-page flush (row action + toolbar)',
		'flush_batcache_for_woo_product_page'      => 'Woo product page flush (mu)',
		'extend_batcache_checkbox'                 => 'Extend Batcache to 24h (mu)',
		'cache_wpp_cookies_pages'                  => 'Cache wpp_ cookie pages (mu)',
		'exclude_query_string_gclid'               => 'Ignore gclid query strings (mu)',
	);
	foreach ( $map as $key => $label ) {
		$out .= $label . ': ' . ( ! empty( $opts[ $key ] ) ? 'Enabled' : 'Disabled' ) . "\n";
	}
	$out .= 'Batcache exclusions: ' . ( ! empty( $opts['exempt_from_batcache'] ) ? $opts['exempt_from_batcache'] : 'None' ) . "\n\n";

	// Trigger timestamps
	$out .= "--- LAST TRIGGER RUNS ---\n";
	$stamps = array(
		'flush-cache-theme-plugin-time-stamp'         => 'Plugin/theme update flush',
		'flush-cache-page-edit-time-stamp'            => 'Page edit flush',
		'flush-cache-on-page-post-delete-time-stamp'  => 'Page delete flush',
		'flush-cache-on-comment-delete-time-stamp'    => 'Comment delete flush',
		'flush-object-cache-for-single-page-time-stamp' => 'Per-page flush',
		'flush-obj-cache-time-stamp'                  => 'Manual object cache flush',
		'edge-cache-purge-time-stamp'                 => 'Edge cache purge',
	);
	foreach ( $stamps as $key => $label ) {
		$v    = get_option( $key, '' );
		$out .= $label . ': ' . ( $v ? wp_strip_all_tags( $v ) : 'Never' ) . "\n";
	}
	$out .= 'Last full sequential flush: ' . get_option( 'aero_cm_last_full_flush', 'Never' ) . "\n";
	$out .= 'Last scheduled flush: ' . get_option( 'aero_cm_last_scheduled_flush', 'Never' ) . "\n\n";

	// Purge order + schedule
	$out .= "--- PURGE ORDER & SCHEDULE ---\n";
	$out .= 'Sequential purge order: ' . implode( ' -> ', array_map( 'aero_cm_step_label', aero_cm_get_purge_order() ) ) . "\n";
	$out .= 'Schedule enabled: ' . ( get_option( 'aero_cm_schedule_enabled' ) === '1' ? 'Yes' : 'No' ) . "\n";
	$out .= 'Schedule interval: ' . get_option( 'aero_cm_schedule_interval', 'daily' ) . "\n";
	$next = wp_next_scheduled( AERO_CM_CRON_HOOK );
	$out .= 'Next scheduled run: ' . ( $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : 'Not scheduled' ) . "\n";
	$out .= 'WP-Cron disabled: ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'Yes (server cron expected)' : 'No' ) . "\n\n";

	// Edge Cache
	$out .= "--- EDGE CACHE ---\n";
	$out .= 'Edge Cache plugin present: ' . ( class_exists( 'Edge_Cache_Plugin' ) ? 'Yes' : 'No' ) . "\n";
	$out .= 'Edge Cache enabled: ' . ( get_option( 'edge-cache-enabled' ) === 'enabled' ? 'Yes' : 'No' ) . "\n";
	$out .= 'Last backend status: ' . get_option( 'edge-cache-status', 'Unknown' ) . "\n";
	$dm_active  = get_option( 'edge-cache-defensive-mode-active' ) === 'yes';
	$dm_expires = (int) get_option( 'edge-cache-defensive-mode-expires-at', 0 );
	$out .= 'Defensive Mode: ' . ( $dm_active && $dm_expires > time() ? 'ACTIVE until ' . gmdate( 'Y-m-d H:i:s', $dm_expires ) . ' UTC' : 'Inactive' ) . "\n";
	$out .= 'Defensive Mode set at: ' . ( get_option( 'edge-cache-defensive-mode-set-at' ) ? get_option( 'edge-cache-defensive-mode-set-at' ) : '—' ) . "\n\n";

	// Batcache status probe
	$out .= "--- BATCACHE STATUS (browser probe) ---\n";
	if ( function_exists( 'aero_cm_get_batcache_status' ) ) {
		$out .= 'Detected status: ' . aero_cm_get_batcache_status() . "\n";
	}
	$out .= "\n";

	// Guest Mode + isolation
	$out .= "--- GUEST MODE & ISOLATION ---\n";
	$out .= 'Guest Mode level: ' . ucfirst( get_option( 'aero_guest_mode_level', 'off' ) ) . "\n";
	$out .= 'Layer 1 (plugin no-store): ' . ( function_exists( 'aero_cm_guest_isolation_enabled' ) && aero_cm_guest_isolation_enabled() ? 'Enabled' : 'Disabled' ) . "\n";
	$out .= 'Layer 2 (wp-config snippet): ' . ( function_exists( 'aero_cm_guest_isolation_snippet_installed' ) && aero_cm_guest_isolation_snippet_installed() ? 'Detected' : 'Not detected' ) . "\n";
	$out .= 'Guest custom CSS set: ' . ( trim( get_option( 'aero_custom_css_guest', '' ) ) !== '' ? 'Yes' : 'No' ) . "\n\n";

	// Deployed mu-plugins
	$out .= "--- DEPLOYED MU-PLUGIN MODULES ---\n";
	$out .= 'Loader index (' . basename( AERO_CM_MU_INDEX ) . '): ' . ( file_exists( AERO_CM_MU_INDEX ) ? 'Present' : 'Absent' ) . "\n";
	if ( is_dir( AERO_CM_MU_DIR ) ) {
		$modules = glob( AERO_CM_MU_DIR . '*.php' );
		if ( $modules ) {
			foreach ( $modules as $m ) {
				$out .= '  - ' . basename( $m ) . ' (' . size_format( filesize( $m ) ) . ")\n";
			}
		} else {
			$out .= "  (module directory empty)\n";
		}
	} else {
		$out .= "  (module directory not created)\n";
	}
	$out .= "\n";

	// Font optimization
	$out .= "--- FONT OPTIMIZATION ---\n";
	if ( function_exists( 'aero_fonts_opt' ) ) {
		$out .= 'Master (Optimize Font Loading): ' . ( function_exists( 'aero_fonts_master_on' ) && aero_fonts_master_on() ? 'Enabled' : 'Disabled' ) . "\n";
		foreach ( array(
			'aero_fonts_local_google'   => 'Self-host Google Fonts',
			'aero_fonts_inline_css'     => 'Inline font CSS',
			'aero_fonts_preconnect'     => 'Preconnect hints',
			'aero_fonts_preload'        => 'Preload primary fonts',
			'aero_fonts_disable_google' => 'Disable Google Fonts entirely',
		) as $fk => $fl ) {
			$out .= $fl . ': ' . ( aero_fonts_opt( $fk ) ? 'Enabled' : 'Disabled' ) . "\n";
		}
		if ( function_exists( 'aero_fonts_get_detection' ) ) {
			$fd   = aero_fonts_get_detection();
			$out .= 'Frontend detection — Google Fonts: ' . $fd['google']['mode']
				  . ' | TypeKit: ' . $fd['typekit']['mode']
				  . ( $fd['google']['seen'] ? ' (last check ' . gmdate( 'Y-m-d H:i:s', (int) $fd['google']['seen'] ) . ' UTC)' : '' ) . "\n";
		}
		if ( function_exists( 'aero_fonts_cache_stats' ) ) {
			$fs   = aero_fonts_cache_stats();
			$out .= 'Local font cache: ' . $fs['sheets'] . ' stylesheet(s), ' . $fs['files'] . ' file(s), ' . size_format( $fs['bytes'] ) . "\n";
			$out .= 'Fonts dir writable: ' . ( is_dir( AERO_FONTS_CACHE_DIR ) ? ( is_writable( AERO_FONTS_CACHE_DIR ) ? 'Yes' : 'NO' ) : 'Not created yet' ) . "\n";
		}
	} else {
		$out .= "Module not loaded\n";
	}
	$out .= "\n";

	// WordPress bloat
	$out .= "--- WORDPRESS BLOAT ---\n";
	if ( function_exists( 'aero_bloat_opts' ) ) {
		$b = aero_bloat_opts();
		foreach ( array(
			'emojis'         => 'Remove emoji script',
			'head_cleanup'   => 'Head cleanup',
			'embeds'         => 'Disable embeds',
			'jquery_migrate' => 'Remove jQuery Migrate',
			'dashicons'      => 'Remove visitor Dashicons',
			'xmlrpc'         => 'Disable XML-RPC',
		) as $bk => $bl ) {
			$out .= $bl . ': ' . ( 'on' === $b[ $bk ] ? 'Enabled' : 'Disabled' ) . "\n";
		}
		$out .= 'Heartbeat: ' . $b['heartbeat'] . "\n";
		$out .= 'Autosave interval: ' . $b['autosave'] . "s\n";
		$out .= 'Revisions limit: ' . ( '' === $b['revisions'] ? 'WP default' : $b['revisions'] ) . "\n";
	} else {
		$out .= "Module not loaded\n";
	}
	$out .= "\n";

	// Delivery optimization
	$out .= "--- DELIVERY OPTIMIZATION ---\n";
	if ( function_exists( 'aero_delivery_on' ) ) {
		$out .= 'Preload LCP image: ' . ( aero_delivery_on( 'aero_preload_lcp' ) ? 'Enabled' : 'Disabled' ) . "\n";
		$out .= 'Delay JavaScript: ' . ( aero_delivery_on( 'aero_delay_js' ) ? 'Enabled' : 'Disabled' ) . "\n";
		$out .= 'Delay fallback timeout: ' . ( (int) get_option( 'aero_delay_js_timeout', 0 ) > 0 ? get_option( 'aero_delay_js_timeout' ) . 's' : 'None' ) . "\n";
		$out .= 'Async CSS: ' . ( aero_delivery_on( 'aero_async_css' ) ? 'Enabled' : 'Disabled' ) . "\n";
		$out .= 'Critical CSS set: ' . ( '' !== trim( (string) get_option( 'aero_critical_css', '' ) ) ? 'Yes (' . strlen( trim( (string) get_option( 'aero_critical_css', '' ) ) ) . ' chars)' : 'No' ) . "\n";
	} else {
		$out .= "Module not loaded\n";
	}
	$out .= "\n";

	// Exclusion lists
	$out .= "--- EXCLUSION LISTS ---\n";
	foreach ( array(
		'aero_exclude_minify_css' => 'CSS minify exclusions',
		'aero_exclude_minify_js'  => 'JS minify exclusions',
		'aero_exclude_defer'      => 'Defer exclusions',
		'aero_delay_js_excludes'  => 'Delay-JS exclusions',
		'aero_async_css_excludes' => 'Async-CSS exclusions',
	) as $ek => $el ) {
		$v    = trim( (string) get_option( $ek, '' ) );
		$out .= $el . ': ' . ( '' === $v ? 'None' : str_replace( "\n", ' | ', $v ) ) . "\n";
	}
	$out .= "\n";

	// Cache warmer
	$out .= "--- CACHE WARMER ---\n";
	if ( function_exists( 'aero_cw_opts' ) ) {
		$cw   = aero_cw_opts();
		$out .= 'Enabled: ' . ( '1' === $cw['enabled'] ? 'Yes' : 'No' ) . "\n";
		$out .= 'Auto-warm after flush: ' . ( '1' === $cw['auto_after_flush'] ? 'Yes' : 'No' ) . "\n";
		$out .= 'Warm guest bucket: ' . ( '1' === $cw['warm_guest'] ? 'Yes' : 'No' ) . "\n";
		$out .= 'URL limit / batch size: ' . ( 'all' === $cw['limit'] ? 'ALL (cap 5000)' : $cw['limit'] ) . ' / ' . $cw['batch_size'] . "\n";
		$out .= 'Sitemap: ' . ( '' !== $cw['sitemap_url'] ? $cw['sitemap_url'] : 'auto-detect' ) . "\n";
		if ( function_exists( 'aero_cw_llms_status' ) ) {
			$llms = aero_cw_llms_status();
			$out .= 'llms.txt source: ' . ( '1' === $cw['use_llms'] ? 'Enabled' : 'Disabled' )
				  . ' (' . ( $llms['exists'] ? 'detected' : 'not detected' ) . ")\n";
		}
		$out .= 'Scheduled warming: ' . ( '1' === $cw['schedule_enabled'] ? $cw['schedule_interval'] : 'Off' ) . "\n";
		$cw_next = wp_next_scheduled( AERO_CW_SCHEDULE_HOOK );
		$out .= 'Next scheduled warm: ' . ( $cw_next ? gmdate( 'Y-m-d H:i:s', $cw_next ) . ' UTC' : 'None' ) . "\n";
		$out .= 'Run in progress: ' . ( get_option( 'aero_cw_running' ) ? 'Yes' : 'No' ) . "\n";
		$micro = get_option( 'aero_cw_micro_stats', array() );
		if ( ! empty( $micro['time'] ) ) {
			$out .= 'Last re-warm: ' . gmdate( 'Y-m-d H:i:s', (int) $micro['time'] ) . ' UTC — '
				  . (int) $micro['warmed'] . ' URL(s)' . ( ! empty( $micro['guest'] ) ? ' × 2 variants' : '' ) . "\n";
		} else {
			$out .= "Last re-warm: Never\n";
		}
		$mq = get_option( 'aero_cw_micro_queue', array() );
		if ( ! empty( $mq ) ) {
			$out .= 'Re-warm pending: ' . count( (array) $mq ) . " URL(s)\n";
		}
		if ( function_exists( 'aero_cw_edge_priority_urls' ) ) {
			$ep     = aero_cw_edge_priority_urls();
			$ep_on  = function_exists( 'aero_cw_edge_priority_active' ) && aero_cw_edge_priority_active();
			$out   .= 'Priority Edge: ' . ( $ep_on ? 'Active' : 'Off' ) . ' — ' . count( $ep ) . "/10 URLs\n";
			$ep_next = wp_next_scheduled( AERO_CW_EDGE_HOOK );
			$out    .= 'Next Priority Edge pass: ' . ( $ep_next ? gmdate( 'H:i:s', $ep_next ) . ' UTC' : 'None' ) . "\n";
			$ep_status = get_option( 'aero_cw_edge_status', array() );
			foreach ( $ep as $ep_url ) {
				$st   = isset( $ep_status[ $ep_url ] ) ? $ep_status[ $ep_url ] : array();
				$out .= '  - ' . ( wp_parse_url( $ep_url, PHP_URL_PATH ) ?: '/' ) . ': '
					  . ( isset( $st['xac'] ) && '' !== $st['xac'] ? 'x-ac ' . $st['xac'] : 'not verified' )
					  . ( isset( $st['bc'] ) && 1 === (int) $st['bc'] ? ', Batcache-Hit' : '' ) . "\n";
			}
		}
		$mlog = get_option( 'aero_cw_micro_log', array() );
		if ( ! empty( $mlog ) && is_array( $mlog ) ) {
			$out .= 'Re-warm log (' . count( $mlog ) . " most recent):\n";
			foreach ( array_slice( $mlog, 0, 5 ) as $ml ) {
				$out .= '  - ' . gmdate( 'H:i:s', (int) $ml['time'] ) . ' UTC  HTTP ' . (int) $ml['code'] . '  '
					  . ( wp_parse_url( $ml['url'], PHP_URL_PATH ) ?: '/' )
					  . ( '' !== $ml['reason'] ? '  (' . $ml['reason'] . ')' : '' ) . "\n";
			}
		}
		$cw_stats = get_option( 'aero_cw_stats', array() );
		if ( ! empty( $cw_stats['started'] ) ) {
			$out .= 'Last run: ' . gmdate( 'Y-m-d H:i:s', (int) $cw_stats['started'] ) . ' UTC (' . ( isset( $cw_stats['reason'] ) ? $cw_stats['reason'] : '?' ) . ') — '
				  . ( isset( $cw_stats['done'] ) ? (int) $cw_stats['done'] : 0 ) . '/' . ( isset( $cw_stats['total'] ) ? (int) $cw_stats['total'] : 0 ) . ' warmed, '
				  . ( isset( $cw_stats['failed'] ) ? (int) $cw_stats['failed'] : 0 ) . " failed\n";
		} else {
			$out .= "Last run: Never\n";
		}
	} else {
		$out .= "Module not loaded\n";
	}
	$out .= "\n";

	// Admin bar / toolbar surfaces
	$out .= "--- UI SURFACES ---\n";
	$out .= 'Admin bar Cache Control: ' . ( function_exists( 'aero_cm_abar_can_view' ) ? 'Registered' : 'Not loaded' ) . "\n";
	$out .= 'Plugin version: ' . AERO_PLUGIN_VERSION_NUM . "\n";

	return $out;
}

/**
 * Full combined report: Aero core + cache-manager addendum.
 */
function aero_cm_full_debug_report() {
	$report = function_exists( 'aero_generate_debug_info' ) ? aero_generate_debug_info() : '';
	return rtrim( $report ) . "\n\n" . aero_cm_generate_debug_addendum();
}

// ─── Screen render ────────────────────────────────────────────────────────────
function aero_cm_render_debug_screen() {
	$debug_on = ( get_option( 'aero_debug_mode' ) === 'on' );
	?>

	<div class="aero-section">
		<div class="aero-eyebrow"><?php esc_html_e( 'Debug Mode', 'aero' ); ?></div>
		<form method="post">
			<?php wp_nonce_field( 'aero_cm_debug_save', 'aero_cm_debug_nonce' ); ?>
			<div class="aero-check-list">
				<label class="aero-check-row aero-check-row-simple">
					<input type="checkbox" name="aero_debug_mode" value="1" <?php checked( $debug_on ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Enable Debug Information', 'aero' ); ?></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Generates the detailed report below. Copy and share it with WP Stratos support for troubleshooting.', 'aero' ); ?></span>
					</span>
				</label>
			</div>
			<div class="aero-actions">
				<button type="submit" class="aero-btn aero-btn-primary"><?php esc_html_e( 'Save Debug Settings', 'aero' ); ?></button>
			</div>
		</form>
	</div>

	<?php if ( $debug_on ) : ?>
		<hr class="aero-divider" />

		<div class="aero-section">
			<div class="aero-diag-head">
				<div class="aero-eyebrow" style="margin-bottom:0;"><?php esc_html_e( 'Full Debug Report', 'aero' ); ?></div>
				<div class="aero-actions" style="padding-top:0;">
					<button type="button" id="aero-refresh-debug-btn" class="aero-btn aero-btn-ghost" onclick="aeroRefreshDebug(event)"><?php esc_html_e( 'Refresh', 'aero' ); ?></button>
					<button type="button" id="aero-copy-debug-btn" class="aero-btn aero-btn-primary" onclick="aeroCopyDebug(event)"><?php esc_html_e( 'Copy to Clipboard', 'aero' ); ?></button>
				</div>
			</div>
			<p class="aero-hint" style="margin:0 0 12px;"><?php esc_html_e( 'Covers the entire plugin: WordPress & server environment, hosting detection, drop-ins, Aero optimization settings, cache directories, Batcache configuration and live status, flush triggers with last-run times, purge order, schedule, Edge Cache & Defensive Mode, Guest Mode isolation, and deployed mu-plugin modules.', 'aero' ); ?></p>
			<div class="aero-field">
				<textarea id="aero-debug-info" readonly class="aero-input aero-code-textarea aero-debug-terminal" rows="24" onclick="this.select();"><?php echo esc_textarea( aero_cm_full_debug_report() ); ?></textarea>
			</div>
		</div>

		<script>
		function aeroCopyDebug(e) {
			e.preventDefault();
			var ta  = document.getElementById('aero-debug-info');
			var btn = document.getElementById('aero-copy-debug-btn');
			var orig = btn.textContent;
			var done = function() {
				btn.textContent = '<?php echo esc_js( __( '✓ Copied', 'aero' ) ); ?>';
				setTimeout(function(){ btn.textContent = orig; }, 2000);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(ta.value).then(done);
			} else {
				ta.select();
				document.execCommand('copy');
				done();
			}
		}

		function aeroRefreshDebug(e) {
			e.preventDefault();
			var btn = document.getElementById('aero-refresh-debug-btn');
			var orig = btn.textContent;
			btn.disabled = true;
			btn.textContent = '<?php echo esc_js( __( 'Refreshing…', 'aero' ) ); ?>';
			jQuery.ajax({
				url: ajaxurl, type: 'POST',
				data: {
					action: 'aero_refresh_debug',
					nonce: '<?php echo esc_js( wp_create_nonce( 'aero_refresh_debug_nonce' ) ); ?>'
				},
				success: function(response) {
					if (response.success) {
						document.getElementById('aero-debug-info').value = response.data.debug_info;
						btn.textContent = '<?php echo esc_js( __( '✓ Refreshed', 'aero' ) ); ?>';
						setTimeout(function(){ btn.textContent = orig; btn.disabled = false; }, 2000);
					} else {
						alert('<?php echo esc_js( __( 'Please wait', 'aero' ) ); ?> ' + response.data.time_remaining + ' <?php echo esc_js( __( 'minutes before refreshing again.', 'aero' ) ); ?>');
						btn.textContent = orig;
						btn.disabled = false;
					}
				},
				error: function() {
					alert('<?php echo esc_js( __( 'Failed to refresh debug information. Please try again.', 'aero' ) ); ?>');
					btn.textContent = orig;
					btn.disabled = false;
				}
			});
		}
		</script>
	<?php endif; ?>
	<?php
}
