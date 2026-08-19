<?php
/**
 * Aero Cache Manager — Screens
 *
 * Renders the Cache, Edge Cache, Purge & Schedule and Experimental screens
 * inside the shared Aero admin shell (see admin-ui.php). Menu registration
 * also lives in admin-ui.php; this file owns the save/flush handlers and
 * per-screen markup.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Save handler ─────────────────────────────────────────────────────────────
add_action( 'admin_init', 'aero_cm_handle_settings_save', 5 );
function aero_cm_handle_settings_save() {
	if ( ! isset( $_POST['aero_cm_settings_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aero_cm_settings_nonce'] ) ), 'aero_cm_settings_save' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$tab = isset( $_POST['aero_cm_tab'] ) ? sanitize_key( $_POST['aero_cm_tab'] ) : 'cache';

	if ( 'cache' === $tab ) {
		$checkboxes = array(
			'extend_batcache_checkbox',
			'flush_cache_theme_plugin_checkbox',
			'flush_cache_page_edit_checkbox',
			'flush_cache_on_page_post_delete_checkbox',
			'flush_cache_on_comment_delete_checkbox',
			'flush_object_cache_for_single_page',
			'flush_batcache_for_woo_product_page',
			'cache_wpp_cookies_pages',
			'exclude_query_string_gclid',
		);
		$opts = aero_cm_get_options();
		foreach ( $checkboxes as $key ) {
			$opts[ $key ] = isset( $_POST['aero_cm_options'][ $key ] ) ? '1' : '';
		}
		// Exclusion list: comma separated relative paths only
		$raw   = isset( $_POST['aero_cm_options']['exempt_from_batcache'] ) ? wp_unslash( $_POST['aero_cm_options']['exempt_from_batcache'] ) : '';
		$parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		$clean = array();
		foreach ( $parts as $p ) {
			$p = '/' . ltrim( $p, '/' );                    // ensure leading slash
			$p = preg_replace( '/[^a-zA-Z0-9\-_\/\.]/', '', $p ); // strip junk
			if ( false === strpos( $p, '..' ) ) {
				$clean[] = $p;
			}
		}
		$opts['exempt_from_batcache'] = implode( ', ', $clean );
		update_option( 'aero_cm_options', $opts );
	}

	if ( 'order' === $tab ) {
		// Purge order — comma-separated, whitelist validated
		$raw   = isset( $_POST['aero_cm_purge_order'] ) ? sanitize_text_field( wp_unslash( $_POST['aero_cm_purge_order'] ) ) : '';
		$parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		$valid = array( 'aero', 'batcache', 'edge' );
		$order = array_values( array_unique( array_intersect( $parts, $valid ) ) );
		if ( empty( $order ) ) {
			$order = array( 'aero', 'batcache', 'edge' );
		}
		update_option( 'aero_cm_purge_order', $order );

		// Schedule
		update_option( 'aero_cm_schedule_enabled', isset( $_POST['aero_cm_schedule_enabled'] ) ? '1' : '' );
		$interval = isset( $_POST['aero_cm_schedule_interval'] ) ? sanitize_key( $_POST['aero_cm_schedule_interval'] ) : 'daily';
		if ( ! array_key_exists( $interval, aero_cm_allowed_schedule_intervals() ) ) {
			$interval = 'daily';
		}
		update_option( 'aero_cm_schedule_interval', $interval );
		aero_cm_sync_schedule();
	}

	if ( 'experimental' === $tab ) {
		// BEFORE state, for transition detection across all guest layers.
		$was_iso   = function_exists( 'aero_cm_guest_isolation_enabled' ) && aero_cm_guest_isolation_enabled();
		$was_level = get_option( 'aero_guest_mode_level', 'off' );

		$level = isset( $_POST['aero_guest_mode_level'] ) ? sanitize_key( $_POST['aero_guest_mode_level'] ) : 'off';
		if ( ! in_array( $level, array( 'off', 'basic', 'extreme' ), true ) ) {
			$level = 'off';
		}

		// ── Guest Mode is governed by the LEVEL. The isolation layers exist
		// only to serve it, so they can never outlive it: when the level is
		// Off, isolation is forced off regardless of the checkbox state.
		$now_iso = isset( $_POST['aero_cm_guest_isolation'] ) && 'off' !== $level;

		update_option( 'aero_cm_guest_isolation', $now_iso ? '1' : '' );
		update_option( 'aero_guest_mode_level', $level );
		update_option( 'aero_custom_css_guest', isset( $_POST['aero_custom_css_guest'] ) ? wp_strip_all_tags( wp_unslash( $_POST['aero_custom_css_guest'] ) ) : '' );

		$guest_changed = ( $was_level !== $level ) || ( $was_iso !== $now_iso );

		// ── Snippet lifecycle: whenever isolation ends up OFF and the
		// wp-config snippet is still present, remove it — including drifted
		// states left behind by older versions (level off + snippet present).
		$removed = false;
		$stuck   = false;
		if ( ! $now_iso
			&& function_exists( 'aero_cm_guest_isolation_snippet_installed' )
			&& aero_cm_guest_isolation_snippet_installed() ) {
			$removed = function_exists( 'aero_cm_guest_isolation_remove_snippet' ) && aero_cm_guest_isolation_remove_snippet();
			$stuck   = ! $removed && aero_cm_guest_isolation_snippet_installed();
			$guest_changed = true; // layer state changed even if options didn't
		}

		// ── ANY guest-layer change invalidates the cache coherently:
		// variant buckets, stripped-vs-full content, edge copies. Flush all
		// layers in order; the warmer rebuilds automatically right after.
		if ( $guest_changed && function_exists( 'aero_cm_run_sequential_flush' ) ) {
			$ctx = ( 'off' === $level && ! $now_iso ) ? 'guest-mode-deactivated' : 'guest-mode-changed';
			aero_cm_run_sequential_flush( $ctx );
		}

		if ( $guest_changed && function_exists( 'aero_ui_action_notice_add' ) ) {
			if ( $removed ) {
				aero_ui_action_notice_add( __( 'Guest Mode change applied: the wp-config isolation snippet was removed automatically and all cache layers were flushed clean.', 'aero' ) );
			} elseif ( $stuck ) {
				aero_ui_action_notice_add( __( '<b>Guest Mode changed, but the wp-config isolation snippet could not be removed automatically</b> (wp-config.php not writable). Remove it manually — until then, bots are still keyed into a separate cache bucket. All caches were flushed.', 'aero' ) );
			} else {
				aero_ui_action_notice_add( __( 'Guest Mode change applied — all cache layers flushed clean and re-warming.', 'aero' ) );
			}
		}
	}

	aero_cm_branded_notice( __( 'Settings saved.', 'aero' ), '#22c55e' );

	if ( function_exists( 'aero_ui_redirect' ) ) {
		$map = array(
			'cache'        => 'aero-cache',
			'order'        => 'aero-purge',
			'experimental' => 'aero-experimental',
		);
		aero_ui_redirect( isset( $map[ $tab ] ) ? $map[ $tab ] : 'aero-cache' );
	}
}

// ─── Manual sequential flush from the settings page ───────────────────────────
add_action( 'admin_init', 'aero_cm_handle_manual_full_flush', 6 );
function aero_cm_handle_manual_full_flush() {
	if ( ! isset( $_POST['aero_cm_full_flush_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aero_cm_full_flush_nonce'] ) ), 'aero_cm_full_flush' ) ||
		 ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$results = aero_cm_run_sequential_flush( 'aero-dashboard-sequential-purge' );
	foreach ( $results as $r ) {
		aero_cm_branded_notice( $r['message'], $r['success'] ? '#22c55e' : '#ef4444' );
	}

	if ( function_exists( 'aero_ui_redirect' ) ) {
		aero_ui_redirect( aero_ui_current_post_screen( 'aero-cache' ) );
	}
}

// Manual object cache flush (parity with PCM's Flush Object Cache button)
add_action( 'admin_init', 'aero_cm_handle_manual_object_flush', 6 );
function aero_cm_handle_manual_object_flush() {
	if ( ! isset( $_POST['aero_cm_object_flush_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aero_cm_object_flush_nonce'] ) ), 'aero_cm_object_flush' ) ||
		 ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$r = aero_cm_step_flush_batcache();
	aero_cm_branded_notice( $r['message'], '#22c55e' );

	if ( function_exists( 'aero_cw_maybe_auto_start' ) ) {
		aero_cw_maybe_auto_start( 'after-object-flush' );
	}

	if ( function_exists( 'aero_ui_redirect' ) ) {
		aero_ui_redirect( aero_ui_current_post_screen( 'aero-cache' ) );
	}
}

// ─── Batcache configuration save (moved from the old Optimization page) ──────
add_action( 'admin_init', 'aero_cm_handle_batcache_config_save', 5 );
function aero_cm_handle_batcache_config_save() {
	if ( ! isset( $_POST['aero_cm_bc_cfg_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aero_cm_bc_cfg_nonce'] ) ), 'aero_cm_bc_cfg_save' ) ||
		 ! current_user_can( 'manage_options' ) ||
		 ! function_exists( 'aero_can_configure_batcache' ) ||
		 ! aero_can_configure_batcache() ) {
		return;
	}

	$max_age        = isset( $_POST['aero_batcache_max_age'] ) ? intval( $_POST['aero_batcache_max_age'] ) : 86400;
	$seconds        = isset( $_POST['aero_batcache_seconds'] ) ? intval( $_POST['aero_batcache_seconds'] ) : 0;
	$times          = isset( $_POST['aero_batcache_times'] ) ? intval( $_POST['aero_batcache_times'] ) : 1;
	$noskip_cookies = isset( $_POST['aero_batcache_noskip_cookies'] ) ? sanitize_text_field( wp_unslash( $_POST['aero_batcache_noskip_cookies'] ) ) : '';

	$result = aero_update_batcache_config( $max_age, $seconds, $times, $noskip_cookies );
	aero_cm_branded_notice( $result['message'], $result['success'] ? '#22c55e' : '#ef4444' );

	if ( function_exists( 'aero_ui_redirect' ) ) {
		aero_ui_redirect( 'aero-cache' );
	}
}

// ─── Shared render helpers ────────────────────────────────────────────────────

/**
 * A toggle row inside an .aero-check-list: checkbox + title + sub +
 * optional "last run" timestamp column.
 */
function aero_cm_check_row( $name, $checked, $title, $sub = '', $ts_option = '' ) {
	$has_ts = ( '' !== $ts_option );
	?>
	<label class="aero-check-row<?php echo $has_ts ? ' aero-check-row-cols' : ' aero-check-row-simple'; ?>">
		<span class="aero-check-col-main">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked, '1' ); ?> />
			<span class="aero-check-main">
				<span class="aero-check-title"><?php echo esc_html( $title ); ?></span>
				<?php if ( $sub ) : ?>
					<span class="aero-check-sub"><?php echo esc_html( $sub ); ?></span>
				<?php endif; ?>
			</span>
		</span>
		<?php if ( $has_ts ) :
			$v = get_option( $ts_option, '' );
		?>
			<span class="aero-check-col-ts">
				<span class="aero-check-ts">
					<strong><?php esc_html_e( 'Last completed', 'aero' ); ?></strong>
					<?php
					// Timestamps may carry limited markup (e.g. "<b>Plugin X was updated</b>").
					echo wp_kses(
						$v ? $v : __( 'Never', 'aero' ),
						array(
							'b'      => array(),
							'strong' => array(),
							'em'     => array(),
							'br'     => array(),
						)
					);
					?>
				</span>
			</span>
		<?php endif; ?>
	</label>
	<?php
}

/**
 * Timestamp option → display string.
 */
function aero_cm_ts( $option_key ) {
	$v = get_option( $option_key, '' );
	return $v ? $v : __( 'Never', 'aero' );
}

// ═══════════════════════════════════════════════════════════════════════════════
// SCREEN: Cache
// ═══════════════════════════════════════════════════════════════════════════════
function aero_cm_render_cache_screen() {
	$options      = aero_cm_get_options();
	$order_labels = array_map( 'aero_cm_step_label', aero_cm_get_purge_order() );
	?>

	<!-- Manual flush actions -->
	<div class="aero-section">
		<div class="aero-eyebrow"><?php esc_html_e( 'Manual Purge', 'aero' ); ?></div>
		<div class="aero-action-cards">
			<div class="aero-action-card">
				<div class="aero-action-title"><?php esc_html_e( 'Flush All Caches — Sequential', 'aero' ); ?></div>
				<div class="aero-action-meta"><span class="aero-chain"><?php echo esc_html( implode( ' → ', $order_labels ) ); ?></span></div>
				<div class="aero-action-meta"><?php esc_html_e( 'Last full flush:', 'aero' ); ?> <?php echo esc_html( aero_cm_ts( 'aero_cm_last_full_flush' ) ); ?></div>
				<form method="post">
					<?php wp_nonce_field( 'aero_cm_full_flush', 'aero_cm_full_flush_nonce' ); ?>
					<input type="hidden" name="aero_ui_screen" value="aero-cache" />
					<button type="submit" class="aero-btn aero-btn-primary"><?php esc_html_e( 'Flush All Caches', 'aero' ); ?></button>
				</form>
			</div>
			<div class="aero-action-card">
				<div class="aero-action-title"><?php esc_html_e( 'Flush Object Cache & Batcache', 'aero' ); ?></div>
				<div class="aero-action-meta"><?php esc_html_e( 'Object cache + full Batcache only — Aero minified files and Edge Cache are untouched.', 'aero' ); ?></div>
				<div class="aero-action-meta"><?php esc_html_e( 'Last:', 'aero' ); ?> <?php echo esc_html( aero_cm_ts( 'flush-obj-cache-time-stamp' ) ); ?></div>
				<form method="post">
					<?php wp_nonce_field( 'aero_cm_object_flush', 'aero_cm_object_flush_nonce' ); ?>
					<input type="hidden" name="aero_ui_screen" value="aero-cache" />
					<button type="submit" class="aero-btn aero-btn-ghost"><?php esc_html_e( 'Flush Object Cache', 'aero' ); ?></button>
				</form>
			</div>
		</div>
	</div>

	<form method="post">
		<?php wp_nonce_field( 'aero_cm_settings_save', 'aero_cm_settings_nonce' ); ?>
		<input type="hidden" name="aero_cm_tab" value="cache" />

		<!-- Automated flush triggers -->
		<div class="aero-section">
			<div class="aero-eyebrow"><?php esc_html_e( 'Automated Flush Triggers', 'aero' ); ?></div>
			<div class="aero-check-list">
				<?php
				aero_cm_check_row(
					'aero_cm_options[flush_cache_theme_plugin_checkbox]',
					$options['flush_cache_theme_plugin_checkbox'],
					__( 'Flush cache on plugin & theme updates', 'aero' ),
					__( 'Full sequential flush — deliberately. Updates change assets sitewide, so every layer purges in order (Aero, Batcache, Edge), and the Cache Warmer rebuilds the site right after.', 'aero' ),
					'flush-cache-theme-plugin-time-stamp'
				);
				aero_cm_check_row(
					'aero_cm_options[flush_cache_page_edit_checkbox]',
					$options['flush_cache_page_edit_checkbox'],
					__( 'Targeted flush on page/post edit', 'aero' ),
					__( 'Surgical: purges the edited URL, archives, author page and feed. The homepage is included only when the edit can actually appear there — and everything purged is re-warmed within seconds.', 'aero' ),
					'flush-cache-page-edit-time-stamp'
				);
				aero_cm_check_row(
					'aero_cm_options[flush_cache_on_page_post_delete_checkbox]',
					$options['flush_cache_on_page_post_delete_checkbox'],
					__( 'Flush cache on page/post delete', 'aero' ),
					__( 'Surgical: purges the affected URL set (homepage included — list membership changed), then re-warms it automatically. The rest of the cache never cools.', 'aero' ),
					'flush-cache-on-page-post-delete-time-stamp'
				);
				aero_cm_check_row(
					'aero_cm_options[flush_cache_on_comment_delete_checkbox]',
					$options['flush_cache_on_comment_delete_checkbox'],
					__( 'Flush cache on comment delete', 'aero' ),
					__( 'Surgical: purges only the commented post — the homepage stays warm. Re-warmed automatically after the purge.', 'aero' ),
					'flush-cache-on-comment-delete-time-stamp'
				);
				?>
			</div>
		</div>

		<!-- Per-page flushing -->
		<div class="aero-section">
			<div class="aero-eyebrow"><?php esc_html_e( 'Per-Page Flushing', 'aero' ); ?></div>
			<div class="aero-check-list">
				<?php
				aero_cm_check_row(
					'aero_cm_options[flush_object_cache_for_single_page]',
					$options['flush_object_cache_for_single_page'],
					__( 'Flush Batcache for individual pages', 'aero' ),
					__( 'Adds a "Flush Cache" row action on the Pages/Posts lists and a toolbar button on the frontend.', 'aero' ),
					'flush-object-cache-for-single-page-time-stamp'
				);
				aero_cm_check_row(
					'aero_cm_options[flush_batcache_for_woo_product_page]',
					$options['flush_batcache_for_woo_product_page'],
					__( 'Flush Batcache for WooCommerce product pages', 'aero' ),
					__( 'Product pages updated via the WooCommerce REST API are flushed individually. Deployed as an mu-plugin.', 'aero' )
				);
				?>
			</div>
		</div>

		<!-- Batcache behaviour -->
		<div class="aero-section">
			<div class="aero-eyebrow"><?php esc_html_e( 'Batcache Behaviour', 'aero' ); ?> <span style="text-transform:none;letter-spacing:0;">(<?php esc_html_e( 'via mu-plugins', 'aero' ); ?>)</span></div>
			<div class="aero-check-list">
				<?php
				aero_cm_check_row(
					'aero_cm_options[extend_batcache_checkbox]',
					$options['extend_batcache_checkbox'],
					__( 'Extend Batcache storage to 24 hours', 'aero' ),
					__( 'Raises max_age to 86400s so cached pages survive a full day instead of the platform default.', 'aero' )
				);
				aero_cm_check_row(
					'aero_cm_options[cache_wpp_cookies_pages]',
					$options['cache_wpp_cookies_pages'],
					__( 'Cache pages that set wpp_ cookies', 'aero' ),
					__( 'Allows Batcache to store pages that would otherwise be skipped because of wpp_ cookies.', 'aero' )
				);
				aero_cm_check_row(
					'aero_cm_options[exclude_query_string_gclid]',
					$options['exclude_query_string_gclid'],
					__( 'Ignore gclid query strings', 'aero' ),
					__( 'Stops Google Ads click IDs from fragmenting the cache into thousands of one-off entries.', 'aero' )
				);
				?>
			</div>

			<div class="aero-field">
				<label class="aero-label" for="aero-cm-exempt"><?php esc_html_e( 'Exclude Pages from Batcache & Edge Cache', 'aero' ); ?></label>
				<input type="text" id="aero-cm-exempt" class="aero-input" name="aero_cm_options[exempt_from_batcache]"
					value="<?php echo esc_attr( $options['exempt_from_batcache'] ); ?>" placeholder="/checkout/, /my-account/" />
				<p class="aero-hint"><?php esc_html_e( 'Comma-separated relative paths, e.g. /about-us/, /info/. Matching pages are served uncached (no-store) via mu-plugin.', 'aero' ); ?></p>
			</div>
		</div>

		<div class="aero-actions">
			<button type="submit" class="aero-btn aero-btn-primary"><?php esc_html_e( 'Save Cache Settings', 'aero' ); ?></button>
		</div>
	</form>

	<?php
	// ── Page Cache Configuration (Batcache) — WP Stratos hosting only ──
	if ( function_exists( 'aero_can_configure_batcache' ) && aero_can_configure_batcache() ) :
		$bc       = aero_check_batcache_config();
		$locked   = ( ! $bc['writable'] && $bc['exists'] );
		$defaults = array( 'max_age' => 86400, 'seconds' => 0, 'times' => 1, 'noskip_cookies' => '' );
		$vals     = $bc['exists'] && is_array( $bc['values'] ) ? array_merge( $defaults, array_filter( $bc['values'], function( $v ) { return null !== $v; } ) ) : $defaults;
	?>
	<hr class="aero-divider" />

	<div class="aero-section">
		<div class="aero-eyebrow"><?php esc_html_e( 'Page Cache Configuration', 'aero' ); ?> <span class="aero-eyebrow-aside"><?php esc_html_e( 'Batcache — written to wp-config.php', 'aero' ); ?></span></div>

		<div class="aero-status-row">
			<span class="aero-dot <?php echo $bc['exists'] ? 'ok' : 'warn'; ?>"></span>
			<span class="aero-status-strong"><?php echo $bc['exists'] ? esc_html__( 'Configured', 'aero' ) : esc_html__( 'Not configured', 'aero' ); ?></span>
			<span><?php echo $bc['writable'] ? esc_html__( 'wp-config.php is writable.', 'aero' ) : esc_html__( 'wp-config.php is NOT writable — values shown read-only.', 'aero' ); ?></span>
			<?php if ( ! $bc['exists'] ) : ?>
				<span class="aero-status-meta">
					<button type="button" id="aero-cm-bc-autoconf" class="aero-btn aero-btn-primary" style="padding:7px 16px;"><?php esc_html_e( 'Auto-Configure', 'aero' ); ?></button>
				</span>
			<?php endif; ?>
		</div>

		<form method="post">
			<?php wp_nonce_field( 'aero_cm_bc_cfg_save', 'aero_cm_bc_cfg_nonce' ); ?>
			<div class="aero-field-grid">
				<div class="aero-field">
					<label class="aero-label" for="aero_batcache_max_age"><?php esc_html_e( 'Cache Duration — max_age (seconds)', 'aero' ); ?></label>
					<input type="number" min="0" class="aero-input" id="aero_batcache_max_age" name="aero_batcache_max_age" value="<?php echo esc_attr( $vals['max_age'] ); ?>" <?php disabled( $locked ); ?> />
					<p class="aero-hint"><?php esc_html_e( 'How long to store cached pages. Default 86400 (24h). Recommended: 86400–604800 (1–7 days).', 'aero' ); ?></p>
				</div>
				<div class="aero-field">
					<label class="aero-label" for="aero_batcache_seconds"><?php esc_html_e( 'Wait Time — seconds', 'aero' ); ?></label>
					<input type="number" min="0" class="aero-input" id="aero_batcache_seconds" name="aero_batcache_seconds" value="<?php echo esc_attr( $vals['seconds'] ); ?>" <?php disabled( $locked ); ?> />
					<p class="aero-hint"><?php esc_html_e( 'How long to wait before caching. Recommended: 0 (cache immediately).', 'aero' ); ?></p>
				</div>
				<div class="aero-field">
					<label class="aero-label" for="aero_batcache_times"><?php esc_html_e( 'Visitor Threshold — times', 'aero' ); ?></label>
					<input type="number" min="1" class="aero-input" id="aero_batcache_times" name="aero_batcache_times" value="<?php echo esc_attr( $vals['times'] ); ?>" <?php disabled( $locked ); ?> />
					<p class="aero-hint"><?php esc_html_e( 'Visitors required before a page is cached. Recommended: 1 (cache after first visit).', 'aero' ); ?></p>
				</div>
				<div class="aero-field">
					<label class="aero-label" for="aero_batcache_noskip_cookies"><?php esc_html_e( 'No-Skip Cookies', 'aero' ); ?></label>
					<input type="text" class="aero-input" id="aero_batcache_noskip_cookies" name="aero_batcache_noskip_cookies" value="<?php echo esc_attr( (string) $vals['noskip_cookies'] ); ?>" placeholder="cookie_one, cookie_two" <?php disabled( $locked ); ?> />
					<p class="aero-hint"><?php esc_html_e( 'Comma-separated cookies that should NOT bypass the cache.', 'aero' ); ?></p>
				</div>
			</div>
			<div class="aero-actions">
				<button type="submit" class="aero-btn aero-btn-primary" <?php disabled( $locked ); ?>><?php esc_html_e( 'Save Batcache Configuration', 'aero' ); ?></button>
			</div>
		</form>

		<?php if ( ! $bc['exists'] ) : ?>
		<script>
		(function(){
			var btn = document.getElementById('aero-cm-bc-autoconf');
			if (!btn) return;
			btn.addEventListener('click', function() {
				btn.disabled = true;
				btn.textContent = '<?php echo esc_js( __( 'Configuring…', 'aero' ) ); ?>';
				jQuery.ajax({
					url: ajaxurl, type: 'POST',
					data: { action: 'aero_auto_configure_batcache', nonce: '<?php echo esc_js( wp_create_nonce( 'aero_batcache_nonce' ) ); ?>' },
					success: function(r) {
						if (r.success) { window.location.reload(); }
						else { alert(r.data && r.data.message ? r.data.message : '<?php echo esc_js( __( 'Auto-configure failed.', 'aero' ) ); ?>'); btn.disabled = false; btn.textContent = '<?php echo esc_js( __( 'Auto-Configure', 'aero' ) ); ?>'; }
					},
					error: function() { alert('<?php echo esc_js( __( 'Auto-configure failed.', 'aero' ) ); ?>'); btn.disabled = false; btn.textContent = '<?php echo esc_js( __( 'Auto-Configure', 'aero' ) ); ?>'; }
				});
			});
		})();
		</script>
		<?php endif; ?>
	</div>
	<?php endif; ?>
	<?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// SCREEN: Edge Cache
// ═══════════════════════════════════════════════════════════════════════════════
function aero_cm_render_edge_screen() {
	$edge_available = class_exists( 'Edge_Cache_Plugin' );
	$edge_enabled   = ( get_option( 'edge-cache-enabled' ) === 'enabled' );
	$dm_active      = ( get_option( 'edge-cache-defensive-mode-active' ) === 'yes' );
	$dm_expires     = (int) get_option( 'edge-cache-defensive-mode-expires-at', 0 );
	?>

	<?php if ( ! $edge_available ) : ?>
		<div class="aero-notice aero-notice-warn"><?php esc_html_e( 'The Edge Cache Plugin (Pressable infrastructure) is not active on this site — Edge Cache controls are unavailable.', 'aero' ); ?></div>
	<?php endif; ?>

	<div class="aero-section">
		<div class="aero-eyebrow"><?php esc_html_e( 'Edge Cache', 'aero' ); ?></div>

		<div class="aero-status-row">
			<span class="aero-dot <?php echo $edge_enabled ? 'ok' : 'idle'; ?>"></span>
			<span class="aero-status-strong"><?php echo $edge_enabled ? esc_html__( 'Enabled', 'aero' ) : esc_html__( 'Disabled', 'aero' ); ?></span>
			<span><?php echo $edge_enabled ? esc_html__( 'Pages are served from the edge server nearest each visitor.', 'aero' ) : esc_html__( 'Purging while disabled will auto-enable Edge Cache first.', 'aero' ); ?></span>
			<span class="aero-status-meta"><?php esc_html_e( 'Last purge:', 'aero' ); ?> <?php echo esc_html( aero_cm_ts( 'edge-cache-purge-time-stamp' ) ); ?></span>
		</div>

		<div class="aero-actions">
			<form method="post" style="margin:0;">
				<?php wp_nonce_field( 'aero_enable_edge_cache_nonce', 'aero_enable_edge_cache_nonce' ); ?>
				<button type="submit" class="aero-btn aero-btn-primary" <?php disabled( ! $edge_available || $edge_enabled ); ?>><?php esc_html_e( 'Enable Edge Cache', 'aero' ); ?></button>
			</form>
			<form method="post" style="margin:0;">
				<?php wp_nonce_field( 'aero_purge_edge_cache_nonce', 'aero_purge_edge_cache_nonce' ); ?>
				<button type="submit" class="aero-btn aero-btn-ghost" <?php disabled( ! $edge_available ); ?>><?php esc_html_e( 'Purge Edge Cache', 'aero' ); ?></button>
			</form>
			<form method="post" style="margin:0;">
				<?php wp_nonce_field( 'aero_disable_edge_cache_nonce', 'aero_disable_edge_cache_nonce' ); ?>
				<button type="submit" class="aero-btn aero-btn-danger" <?php disabled( ! $edge_available || ! $edge_enabled ); ?>><?php esc_html_e( 'Disable Edge Cache', 'aero' ); ?></button>
			</form>
		</div>
	</div>

	<hr class="aero-divider" />

	<div class="aero-section">
		<div class="aero-eyebrow"><?php esc_html_e( 'Defensive Mode', 'aero' ); ?></div>
		<p class="aero-hint" style="margin:0 0 14px;max-width:640px;">
			<?php esc_html_e( 'Under attack or expecting a traffic spike? Defensive Mode instructs the Edge Cache to serve aggressively cached pages for a set duration, shielding PHP and the database.', 'aero' ); ?>
		</p>

		<?php if ( $dm_active && $dm_expires > time() ) : ?>
			<div class="aero-status-row" id="aero-cm-dm-status">
				<span class="aero-dot warn"></span>
				<span class="aero-status-strong"><?php esc_html_e( 'Defensive Mode ACTIVE', 'aero' ); ?></span>
				<span><?php esc_html_e( 'expires', 'aero' ); ?> <?php echo esc_html( gmdate( 'j M Y, g:ia', $dm_expires ) ); ?> UTC</span>
				<?php $set_at = get_option( 'edge-cache-defensive-mode-set-at', '' ); ?>
				<?php if ( $set_at ) : ?>
					<span class="aero-status-meta"><?php esc_html_e( 'Set:', 'aero' ); ?> <?php echo esc_html( $set_at ); ?></span>
				<?php endif; ?>
			</div>
			<form method="post" style="margin:0;">
				<?php wp_nonce_field( 'aero_disable_defensive_mode_nonce', 'aero_disable_defensive_mode_nonce' ); ?>
				<button type="submit" class="aero-btn aero-btn-danger"><?php esc_html_e( 'Disable Defensive Mode', 'aero' ); ?></button>
			</form>
		<?php else : ?>
			<form method="post" style="margin:0;">
				<?php wp_nonce_field( 'aero_enable_defensive_mode_nonce', 'aero_enable_defensive_mode_nonce' ); ?>
				<div class="aero-field" style="max-width:280px;">
					<label class="aero-label" for="aero-cm-dm-duration"><?php esc_html_e( 'Duration', 'aero' ); ?></label>
					<select id="aero-cm-dm-duration" class="aero-input" name="aero_defensive_mode_duration">
						<?php foreach ( aero_cm_defensive_mode_durations() as $slug => $d ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $d['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="aero-actions">
					<button type="submit" class="aero-btn aero-btn-primary" <?php disabled( ! $edge_available ); ?>><?php esc_html_e( 'Enable Defensive Mode', 'aero' ); ?></button>
				</div>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// SCREEN: Purge Order & Schedule
// ═══════════════════════════════════════════════════════════════════════════════
function aero_cm_render_purge_screen() {
	$order            = aero_cm_get_purge_order();
	$schedule_enabled = get_option( 'aero_cm_schedule_enabled', '' );
	$interval         = get_option( 'aero_cm_schedule_interval', 'daily' );
	$next             = wp_next_scheduled( AERO_CM_CRON_HOOK );

	$step_subs = array(
		'aero'     => __( 'Minified CSS / JS', 'aero' ),
		'batcache' => __( 'Object cache + pages', 'aero' ),
		'edge'     => __( 'CDN edge nodes', 'aero' ),
	);
	?>

	<form method="post">
		<?php wp_nonce_field( 'aero_cm_settings_save', 'aero_cm_settings_nonce' ); ?>
		<input type="hidden" name="aero_cm_tab" value="order" />

		<div class="aero-section">
			<div class="aero-eyebrow"><?php esc_html_e( 'Sequential Purge Order', 'aero' ); ?></div>
			<p class="aero-hint" style="margin:0 0 14px;max-width:640px;">
				<?php esc_html_e( 'When "Flush All Caches" runs — manually, from the admin bar, or on schedule — caches are purged strictly in this order. Innermost-out by default, so upstream layers never re-cache stale content. Drag to reorder.', 'aero' ); ?>
			</p>

			<ul id="aero-cm-order-list" class="aero-order-list">
				<?php foreach ( $order as $step ) : ?>
					<li class="aero-order-item" data-step="<?php echo esc_attr( $step ); ?>" draggable="true">
						<span class="aero-order-grip"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="8" x2="20" y2="8"/><line x1="4" y1="16" x2="20" y2="16"/></svg></span>
						<span class="aero-order-label"><?php echo esc_html( aero_cm_step_label( $step ) ); ?></span>
						<span class="aero-order-sub"><?php echo isset( $step_subs[ $step ] ) ? esc_html( $step_subs[ $step ] ) : ''; ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<input type="hidden" id="aero-cm-order-input" name="aero_cm_purge_order" value="<?php echo esc_attr( implode( ',', $order ) ); ?>" />

			<script>
			(function(){
				var list = document.getElementById('aero-cm-order-list');
				var input = document.getElementById('aero-cm-order-input');
				var dragged = null;
				list.addEventListener('dragstart', function(e){
					dragged = e.target.closest('li');
					if (dragged) { dragged.classList.add('aero-dragging'); }
				});
				list.addEventListener('dragover', function(e){
					e.preventDefault();
					var over = e.target.closest('li');
					if (!over || over === dragged) return;
					var rect = over.getBoundingClientRect();
					var after = (e.clientY - rect.top) > rect.height / 2;
					list.insertBefore(dragged, after ? over.nextSibling : over);
				});
				list.addEventListener('drop', sync);
				list.addEventListener('dragend', function(){
					if (dragged) { dragged.classList.remove('aero-dragging'); }
					sync();
				});
				function sync(){
					var steps = [];
					list.querySelectorAll('li').forEach(function(li){ steps.push(li.getAttribute('data-step')); });
					input.value = steps.join(',');
				}
			})();
			</script>
		</div>

		<hr class="aero-divider" />

		<div class="aero-section">
			<div class="aero-eyebrow"><?php esc_html_e( 'Scheduled Cache Clearing', 'aero' ); ?></div>

			<div class="aero-check-list">
				<?php
				aero_cm_check_row(
					'aero_cm_schedule_enabled',
					$schedule_enabled,
					__( 'Run the full sequential flush automatically', 'aero' ),
					__( 'Executes via WP-Cron at the interval below, following the purge order above.', 'aero' )
				);
				?>
			</div>

			<div class="aero-field" style="max-width:280px;">
				<label class="aero-label" for="aero-cm-interval"><?php esc_html_e( 'Interval', 'aero' ); ?></label>
				<select id="aero-cm-interval" class="aero-input" name="aero_cm_schedule_interval">
					<?php foreach ( aero_cm_allowed_schedule_intervals() as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $interval, $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="aero-hint">
					<?php if ( $next ) : ?>
						<strong><?php esc_html_e( 'Next run:', 'aero' ); ?></strong> <?php echo esc_html( gmdate( 'j M Y, g:ia', $next ) ); ?> UTC<br/>
					<?php endif; ?>
					<strong><?php esc_html_e( 'Last run:', 'aero' ); ?></strong> <?php echo esc_html( get_option( 'aero_cm_last_scheduled_flush', __( 'Never', 'aero' ) ) ); ?>
				</p>
			</div>
		</div>

		<div class="aero-actions">
			<button type="submit" class="aero-btn aero-btn-primary"><?php esc_html_e( 'Save Order & Schedule', 'aero' ); ?></button>
		</div>
	</form>
	<?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// SCREEN: Experimental
// ═══════════════════════════════════════════════════════════════════════════════
function aero_cm_render_experimental_screen() {
	$iso_on            = aero_cm_guest_isolation_enabled();
	$guest_mode_level  = get_option( 'aero_guest_mode_level', 'off' );
	$snippet_installed = aero_cm_guest_isolation_snippet_installed();
	?>

	<div class="aero-info-box warn">
		<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
		<span><strong><?php esc_html_e( 'Experimental features', 'aero' ); ?></strong> — <?php esc_html_e( 'functional but still being validated on production infrastructure. Test on staging first.', 'aero' ); ?></span>
	</div>

	<form method="post">
		<?php wp_nonce_field( 'aero_cm_settings_save', 'aero_cm_settings_nonce' ); ?>
		<input type="hidden" name="aero_cm_tab" value="experimental" />

		<!-- ═══ Guest Mode ═══ -->
		<div class="aero-section">
			<div class="aero-eyebrow"><?php esc_html_e( 'Guest Mode', 'aero' ); ?> <span class="aero-eyebrow-aside"><?php esc_html_e( 'use only if needed', 'aero' ); ?></span></div>
			<p class="aero-hint" style="margin:0 0 14px;max-width:680px;">
				<strong style="color:var(--warn);"><?php esc_html_e( "Only enable this if regular optimizations don't achieve your target score.", 'aero' ); ?></strong>
				<?php esc_html_e( 'Guest Mode shows PageSpeed tools a super-optimized version while real visitors see the full site. Try all real optimizations first — they benefit actual users.', 'aero' ); ?>
			</p>

			<div class="aero-radio-grid">
				<label class="aero-radio-tile">
					<input type="radio" name="aero_guest_mode_level" value="off" <?php checked( $guest_mode_level, 'off' ); ?> />
					<span class="aero-radio-tile-body">
						<span class="aero-radio-tile-title"><?php esc_html_e( 'Disabled', 'aero' ); ?></span>
						<span class="aero-radio-tile-desc"><?php esc_html_e( 'Guest Mode is off. All visitors see the same optimized site.', 'aero' ); ?></span>
					</span>
				</label>
				<label class="aero-radio-tile">
					<input type="radio" name="aero_guest_mode_level" value="basic" <?php checked( $guest_mode_level, 'basic' ); ?> />
					<span class="aero-radio-tile-body">
						<span class="aero-radio-tile-title"><?php esc_html_e( 'Basic', 'aero' ); ?> <span class="aero-radio-tile-tag"><?php esc_html_e( 'Recommended', 'aero' ); ?></span></span>
						<span class="aero-radio-tile-desc"><?php esc_html_e( 'Combines ALL CSS into one minified file, inlines all fonts, and removes all JavaScript for PageSpeed tools. Eliminates render-blocking requests. Target: 90+ mobile score.', 'aero' ); ?></span>
					</span>
				</label>
				<label class="aero-radio-tile aero-radio-tile-warn">
					<input type="radio" name="aero_guest_mode_level" value="extreme" <?php checked( $guest_mode_level, 'extreme' ); ?> />
					<span class="aero-radio-tile-body">
						<span class="aero-radio-tile-title"><?php esc_html_e( 'Extreme', 'aero' ); ?> <span class="aero-radio-tile-tag warn"><?php esc_html_e( 'Not recommended', 'aero' ); ?></span></span>
						<span class="aero-radio-tile-desc"><?php esc_html_e( 'Aggressive stripping for maximum scores. Keeps only the first 2 CSS files. The site may look broken in metric screenshots.', 'aero' ); ?></span>
					</span>
				</label>
			</div>

			<div class="aero-field" style="margin-top:18px;">
				<label class="aero-label" for="aero_custom_css_guest"><?php esc_html_e( 'Custom CSS — Guest Mode Only', 'aero' ); ?></label>
				<textarea name="aero_custom_css_guest" id="aero_custom_css_guest" class="aero-input aero-code-textarea" rows="6"
					placeholder="/* Example: Fix GSAP animations stuck at opacity 0 */&#10;.gsap-animated,&#10;.fade-in-element {&#10;    opacity: 1 !important;&#10;    transform: none !important;&#10;}"><?php echo esc_textarea( get_option( 'aero_custom_css_guest', '' ) ); ?></textarea>
				<p class="aero-hint"><?php esc_html_e( 'Appended to the combined CSS when Guest Mode is active — only PageSpeed-style tools ever see it. Use DevTools to find elements stuck invisible after JS removal; common fixes are opacity: 1, display: block, or visibility: visible.', 'aero' ); ?></p>
			</div>
		</div>

		<hr class="aero-divider" />

		<!-- ═══ Guest Mode Cache Isolation ═══ -->
		<div class="aero-section">
			<div class="aero-eyebrow"><?php esc_html_e( 'Guest Mode Cache Isolation', 'aero' ); ?></div>
			<p class="aero-hint" style="margin:0 0 14px;max-width:680px;">
				<?php esc_html_e( 'Without isolation, if a bot is the first visitor after a flush, Batcache or the Edge Cache can store the guest render and serve it to real visitors. Isolation keeps guest responses out of the shared cache pool.', 'aero' ); ?>
			</p>

			<?php if ( 'off' === $guest_mode_level ) : ?>
				<div class="aero-notice aero-notice-info"><?php esc_html_e( 'Guest Mode is currently OFF (above) — isolation has no effect until Guest Mode is enabled.', 'aero' ); ?></div>
			<?php endif; ?>

			<div class="aero-check-list">
				<?php
				aero_cm_check_row(
					'aero_cm_guest_isolation',
					$iso_on ? '1' : '',
					__( 'Layer 1 — Plugin-level isolation', 'aero' ),
					__( 'Send no-store headers and set DONOTCACHEPAGE for guest (bot) visitors, preventing guest renders from being STORED in Batcache or the Edge Cache.', 'aero' )
				);
				?>
			</div>
		</div>

		<div class="aero-actions">
			<button type="submit" class="aero-btn aero-btn-primary"><?php esc_html_e( 'Save Experimental Settings', 'aero' ); ?></button>
		</div>
	</form>

	<hr class="aero-divider" />

	<div class="aero-section">
		<div class="aero-eyebrow"><?php esc_html_e( 'Layer 2 — wp-config.php Bucket Separation', 'aero' ); ?> <span class="aero-eyebrow-aside"><?php esc_html_e( 'recommended', 'aero' ); ?></span></div>
		<p class="aero-hint" style="margin:0 0 14px;max-width:680px;">
			<?php esc_html_e( 'Batcache serves cached pages from advanced-cache.php BEFORE any plugin loads, so plugin code alone cannot fully separate bot and human traffic at serve time. Add this snippet to wp-config.php (above "That\'s all, stop editing!") to key the Batcache bucket on the visitor class — bots and humans then never share a cache entry.', 'aero' ); ?>
		</p>

		<div class="aero-status-row">
			<span class="aero-dot <?php echo $snippet_installed ? 'ok' : 'err'; ?>"></span>
			<span class="aero-status-strong"><?php esc_html_e( 'Snippet status', 'aero' ); ?></span>
			<span><?php echo $snippet_installed ? esc_html__( 'Detected in wp-config.php', 'aero' ) : esc_html__( 'Not detected in wp-config.php', 'aero' ); ?></span>
		</div>

		<div class="aero-field">
			<textarea readonly rows="14" class="aero-input aero-code-textarea" onclick="this.select();"><?php echo esc_textarea( aero_cm_guest_isolation_wpconfig_snippet() ); ?></textarea>
			<p class="aero-hint"><?php esc_html_e( 'Click the box to select all, then copy. After adding the snippet, run "Flush All Caches" once so existing mixed entries are cleared.', 'aero' ); ?></p>
		</div>
	</div>
	<?php
}
