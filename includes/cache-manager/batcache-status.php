<?php
/**
 * Aero Cache Manager — Batcache Status Badge
 *
 * WHY BROWSER-SIDE: wp_remote_get() is a server-side loopback request.
 * Pressable's infrastructure routes loopback requests directly to PHP,
 * bypassing the Batcache/CDN layer entirely — so x-nananana is never present
 * regardless of the real cache state. The browser is the only client that
 * sees the actual CDN response headers.
 *
 * SOLUTION: JS fetches the homepage with cache:'reload' (fresh CDN response)
 * and Pragma: no-cache (forces Edge Cache BYPASS), reads x-nananana directly,
 * then reports the result back to PHP via AJAX. PHP only stores/returns the
 * transient — it never probes the URL itself.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function aero_cm_get_batcache_status() {
	$cached = get_transient( 'aero_cm_batcache_status' );
	return ( false !== $cached ) ? $cached : 'unknown';
}

function aero_cm_batcache_status_labels() {
	return array(
		'active'     => __( 'Batcache Active', 'aero' ),
		'cloudflare' => __( 'Cloudflare Detected', 'aero' ),
		'broken'     => __( 'Batcache Broken', 'aero' ),
		'unknown'    => __( 'Checking…', 'aero' ),
	);
}

// ── AJAX: browser reports the header value it observed ────────────────────────
function aero_cm_ajax_report_batcache_header() {
	check_ajax_referer( 'aero_cm_batcache_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	$raw = isset( $_POST['x_nananana'] ) ? sanitize_text_field( wp_unslash( $_POST['x_nananana'] ) ) : '';
	$val = strtolower( trim( $raw ) );

	if ( false !== strpos( $val, 'batcache' ) ) {
		$status = 'active';
	} elseif ( isset( $_POST['is_cloudflare'] ) && '1' === $_POST['is_cloudflare'] ) {
		$status = 'cloudflare';
	} else {
		$status = 'broken';
	}

	// Active: 24 hrs — prevents the badge falsely flipping to broken after a
	// few minutes. Broken: 2 min — re-probe frequently until resolved.
	$ttl = ( 'active' === $status ) ? DAY_IN_SECONDS : 2 * MINUTE_IN_SECONDS;
	set_transient( 'aero_cm_batcache_status', $status, $ttl );

	$labels = aero_cm_batcache_status_labels();

	wp_send_json_success( array(
		'status' => $status,
		'label'  => $labels[ $status ],
	) );
}
add_action( 'wp_ajax_aero_cm_report_batcache_header', 'aero_cm_ajax_report_batcache_header' );

// ── AJAX: return current stored status (badge refresh without re-fetching) ────
function aero_cm_ajax_get_batcache_status() {
	check_ajax_referer( 'aero_cm_batcache_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	$status = aero_cm_get_batcache_status();
	$labels = aero_cm_batcache_status_labels();

	wp_send_json_success( array(
		'status' => $status,
		'label'  => isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['broken'],
	) );
}
add_action( 'wp_ajax_aero_cm_get_batcache_status', 'aero_cm_ajax_get_batcache_status' );

/**
 * Clear the cached status immediately after any cache flush so the badge
 * re-probes on next page load. Also cleared after a full Edge Cache purge:
 * Batcache is implicitly invalidated, so the next probe correctly detects
 * the transitional 'broken' state instead of showing a stale 'active'.
 */
function aero_cm_clear_batcache_status_transient() {
	delete_transient( 'aero_cm_batcache_status' );
}
add_action( 'aero_cm_after_object_cache_flush', 'aero_cm_clear_batcache_status_transient' );
add_action( 'aero_cm_after_batcache_flush', 'aero_cm_clear_batcache_status_transient' );
add_action( 'aero_cm_after_edge_cache_purge', 'aero_cm_clear_batcache_status_transient' );

/**
 * Render the badge markup + probe JS. Called from the settings page.
 */
function aero_cm_render_batcache_badge() {
	$status = aero_cm_get_batcache_status();
	$labels = aero_cm_batcache_status_labels();
	$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['unknown'];
	$nonce  = wp_create_nonce( 'aero_cm_batcache_nonce' );
	?>
	<span class="aero-bc-badge-wrap">
		<span id="aero-cm-bc-badge" class="aero-badge <?php echo ( 'active' === $status ) ? 'ok' : ( ( 'unknown' === $status ) ? 'idle' : 'err' ); ?>">
			<span class="aero-dot dot <?php echo ( 'active' === $status ) ? 'ok' : ( ( 'unknown' === $status ) ? 'idle' : 'err' ); ?>"></span>
			<span id="aero-cm-bc-label"><?php echo esc_html( $label ); ?></span>
		</span>
		<span class="aero-bc-badge-ttl">
			<?php esc_html_e( 'Page cache TTL:', 'aero' ); ?> <span id="aero-cm-ttl-value">&mdash;</span>
		</span>
	</span>
	<script>
	(function() {
		var aeroCmSiteUrl       = <?php echo wp_json_encode( trailingslashit( get_site_url() ) ); ?>;
		var aeroCmBatcacheNonce = <?php echo wp_json_encode( $nonce ); ?>;
		var aeroCmAjaxUrl       = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

		function aeroCmApplyStatus(res) {
			if (!res || !res.success) return null;
			var badge = document.getElementById('aero-cm-bc-badge');
			var label = document.getElementById('aero-cm-bc-label');
			if (!badge || !label) return null;
			label.textContent = res.data.label;
			var dot = badge.querySelector('.dot');
			['ok','err','idle','warn'].forEach(function(cls){
				badge.classList.remove(cls);
				if (dot) dot.classList.remove(cls);
			});
			var cls = res.data.status === 'active' ? 'ok' : 'err';
			badge.classList.add(cls);
			if (dot) dot.classList.add(cls);
			return res.data.status;
		}

		function aeroCmSecondsToHuman(s) {
			s = parseInt(s);
			if (s <= 0) return '0 sec';
			if (s < 60) return s + ' sec';
			if (s < 3600) { var m = Math.floor(s/60), sec = s%60; return sec > 0 ? m+' min '+sec+' sec' : m+' min'; }
			if (s < 86400) { var h = Math.floor(s/3600), m2 = Math.floor((s%3600)/60); return m2 > 0 ? h+' hr '+m2+' min' : h+' hr'; }
			var d = Math.floor(s/86400), h2 = Math.floor((s%86400)/3600);
			return h2 > 0 ? d+' day'+(d!==1?'s':'')+' '+h2+' hr' : d+' day'+(d!==1?'s':'');
		}

		// Core: browser fetches homepage, reads header, reports to PHP.
		// cache:'reload' bypasses the browser cache for a fresh CDN response.
		// Pragma: no-cache forces the Atomic Edge Cache to BYPASS (x-ac: BYPASS)
		// so we observe Batcache's own header rather than the edge copy.
		function aeroCmProbeAndReport() {
			fetch(aeroCmSiteUrl, {
				method: 'GET',
				cache: 'reload',
				credentials: 'omit',
				redirect: 'follow',
				headers: { 'Pragma': 'no-cache' }
			})
			.then(function(resp) {
				var xNananana    = resp.headers.get('x-nananana') || '';
				var serverHdr    = resp.headers.get('server') || '';
				var cacheControl = resp.headers.get('cache-control') || '';
				var isCloudflare = serverHdr.toLowerCase().indexOf('cloudflare') !== -1 ? '1' : '0';

				var maxAgeMatch = cacheControl.match(/max-age=(\d+)/i);
				if (maxAgeMatch) {
					var ttlEl = document.getElementById('aero-cm-ttl-value');
					if (ttlEl) ttlEl.textContent = aeroCmSecondsToHuman(parseInt(maxAgeMatch[1]));
				}

				var body = 'action=aero_cm_report_batcache_header'
						 + '&nonce='         + encodeURIComponent(aeroCmBatcacheNonce)
						 + '&x_nananana='    + encodeURIComponent(xNananana)
						 + '&is_cloudflare=' + isCloudflare;
				return fetch(aeroCmAjaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body
				});
			})
			.then(function(r){ return r.json(); })
			.then(function(res){ aeroCmApplyStatus(res); })
			.catch(function(){ /* keep current badge state */ });
		}

		<?php if ( 'unknown' === $status || 'broken' === $status ) : ?>
		aeroCmProbeAndReport();
		<?php endif; ?>
		window.aeroCmProbeAndReport = aeroCmProbeAndReport;
	})();
	</script>
	<?php
}
