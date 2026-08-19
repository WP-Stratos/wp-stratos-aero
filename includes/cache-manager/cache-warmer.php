<?php
/**
 * Aero — Cache Warmer
 *
 * After a purge, the next real visitor (or the next PageSpeed run) pays the
 * full regeneration cost. The warmer pays it instead: it collects your most
 * important URLs (homepage → priority list → sitemap), queues them, and
 * visits each one in background WP-Cron batches so Batcache and the Edge
 * Cache are hot before anyone arrives.
 *
 * When Guest Mode cache isolation is active, each URL can be warmed twice —
 * once as a regular visitor and once as a PageSpeed-style bot — so both
 * cache buckets are ready.
 *
 * Design notes:
 *  - Queue and stats live in non-autoloaded options.
 *  - Batches self-chain via wp_schedule_single_event; a lock option keeps
 *    runs exclusive; items stuck "warming" past 5 minutes are failed.
 *  - Requests are standard loopback GETs with a X-Aero-Warmer header,
 *    recorded with HTTP status + duration for the queue UI.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AERO_CW_BATCH_HOOK', 'aero_cw_process_batch' );
define( 'AERO_CW_SCHEDULE_HOOK', 'aero_cw_scheduled_warm' );
define( 'AERO_CW_MICRO_HOOK', 'aero_cw_micro_warm' );
define( 'AERO_CW_EDGE_HOOK', 'aero_cw_edge_priority_warm' );

// ─── Options ──────────────────────────────────────────────────────────────────

function aero_cw_defaults() {
	return array(
		'enabled'          => '1',
		'auto_after_flush' => '1',
		'warm_guest'       => '1',
		'sitemap_url'      => '',
		'use_llms'         => '1',
		'limit'            => '50',
		'batch_size'       => '5',
		'priority_urls'    => '',
		'excludes'         => '',
		'schedule_enabled' => '',
		'schedule_interval' => 'daily',
		'edge_priority_enabled' => '1',
	);
}

function aero_cw_opts() {
	$saved = get_option( 'aero_cw_options', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), aero_cw_defaults() );
}

function aero_cw_enabled() {
	$o = aero_cw_opts();
	return '1' === $o['enabled'];
}

// ─── llms.txt source ──────────────────────────────────────────────────────────

/**
 * Does this site publish an llms.txt? Cached for an hour so screen loads
 * stay instant; warm runs always fetch fresh.
 */
function aero_cw_llms_status() {
	$cached = get_transient( 'aero_cw_llms_check' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$resp   = wp_remote_head( home_url( '/llms.txt' ), array( 'timeout' => 5, 'sslverify' => false, 'redirection' => 2 ) );
	$exists = ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp );
	$status = array( 'exists' => $exists, 'checked' => time() );
	set_transient( 'aero_cw_llms_check', $status, HOUR_IN_SECONDS );
	return $status;
}

/**
 * Extract same-host URLs from llms.txt. Handles the standard markdown-link
 * format ([Title](url)) plus bare URLs.
 */
function aero_cw_urls_from_llms() {
	$resp = wp_remote_get( home_url( '/llms.txt' ), array( 'timeout' => 8, 'sslverify' => false ) );
	if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
		return array();
	}
	$body = wp_remote_retrieve_body( $resp );
	if ( '' === trim( $body ) ) {
		return array();
	}

	$urls = array();
	// Markdown links first (preserves the file's own ordering)
	if ( preg_match_all( '/\]\((https?:\/\/[^)\s]+)\)/i', $body, $md ) ) {
		$urls = $md[1];
	}
	// Bare URLs as a fallback / supplement
	if ( preg_match_all( '/(?<![\](])(https?:\/\/[^\s)\]"\'<>]+)/i', $body, $bare ) ) {
		$urls = array_merge( $urls, $bare[1] );
	}

	$local = array();
	foreach ( $urls as $u ) {
		if ( aero_cw_is_local( $u ) ) {
			$local[] = $u;
		}
	}
	return array_values( array_unique( $local ) );
}

// ─── URL collection ───────────────────────────────────────────────────────────

/**
 * Build the warm list: homepage first, then priority URLs, then the sitemap,
 * de-duplicated, exclusion-filtered, capped at the configured limit.
 */
function aero_cw_collect_urls() {
	$o        = aero_cw_opts();
	$warm_all = ( 'all' === $o['limit'] );
	// Safety ceiling even in "all" mode — a runaway sitemap should never
	// queue an unbounded crawl of the site.
	$limit = $warm_all ? 5000 : max( 1, (int) $o['limit'] );
	$urls  = array( home_url( '/' ) );

	// Priority URLs (always warmed first, in order)
	foreach ( preg_split( '/[\r\n]+/', (string) $o['priority_urls'] ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		if ( 0 === strpos( $line, '/' ) ) {
			$line = home_url( $line );
		}
		if ( aero_cw_is_local( $line ) ) {
			$urls[] = $line;
		}
	}

	// llms.txt — curated, high-signal URLs; always included when present.
	if ( '1' === $o['use_llms'] ) {
		$urls = array_merge( $urls, aero_cw_urls_from_llms() );
	}

	// Sitemap
	if ( count( $urls ) < $limit ) {
		$urls = array_merge( $urls, aero_cw_urls_from_sitemap( $limit, $warm_all ) );
	}

	// Fallback: recent content, if the sitemap produced nothing beyond home
	if ( count( $urls ) < 2 ) {
		$recent = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'fields'         => 'ids',
		) );
		foreach ( $recent as $pid ) {
			$urls[] = get_permalink( $pid );
		}
	}

	// De-dupe (normalize trailing slash), filter excludes + locality, cap.
	$excludes = array();
	foreach ( preg_split( '/[\r\n,]+/', (string) $o['excludes'] ) as $t ) {
		$t = trim( $t );
		if ( '' !== $t ) {
			$excludes[] = strtolower( $t );
		}
	}

	$seen  = array();
	$clean = array();
	foreach ( $urls as $url ) {
		$url = trailingslashit( strtok( $url, '#' ) );
		$k   = strtolower( $url );
		if ( isset( $seen[ $k ] ) || ! aero_cw_is_local( $url ) ) {
			continue;
		}
		$skip = false;
		foreach ( $excludes as $ex ) {
			if ( false !== strpos( $k, $ex ) ) {
				$skip = true;
				break;
			}
		}
		if ( $skip ) {
			continue;
		}
		$seen[ $k ] = true;
		$clean[]    = $url;
		if ( count( $clean ) >= $limit ) {
			break;
		}
	}
	return $clean;
}

function aero_cw_is_local( $url ) {
	$home = wp_parse_url( home_url(), PHP_URL_HOST );
	$host = wp_parse_url( $url, PHP_URL_HOST );
	return $host && $home && strtolower( $host ) === strtolower( $home );
}

/**
 * Pull URLs out of the sitemap (index or plain). Handles WordPress core
 * sitemaps, Yoast/RankMath style indexes, and plain url sets.
 */
function aero_cw_urls_from_sitemap( $limit, $follow_all = false ) {
	$o   = aero_cw_opts();
	$map = trim( (string) $o['sitemap_url'] );
	if ( '' === $map ) {
		$map = function_exists( 'get_sitemap_url' ) ? get_sitemap_url( 'index' ) : home_url( '/wp-sitemap.xml' );
		if ( ! $map ) {
			$map = home_url( '/wp-sitemap.xml' );
		}
	}

	$found   = array();
	$queue   = array( $map );
	$fetched = 0;

	$max_fetches = $follow_all ? 50 : 8;
	while ( ! empty( $queue ) && count( $found ) < $limit && $fetched < $max_fetches ) {
		$target = array_shift( $queue );
		$fetched++;

		$resp = wp_remote_get( $target, array( 'timeout' => 8, 'sslverify' => false ) );
		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			continue;
		}
		$body = wp_remote_retrieve_body( $resp );
		if ( '' === $body || false === strpos( $body, '<' ) ) {
			continue;
		}

		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $body );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( false === $xml ) {
			continue;
		}

		$name = strtolower( $xml->getName() );
		if ( 'sitemapindex' === $name ) {
			foreach ( $xml->sitemap as $child ) {
				if ( isset( $child->loc ) ) {
					$queue[] = (string) $child->loc;
				}
			}
		} elseif ( 'urlset' === $name ) {
			foreach ( $xml->url as $entry ) {
				if ( isset( $entry->loc ) ) {
					$found[] = (string) $entry->loc;
					if ( count( $found ) >= $limit ) {
						break;
					}
				}
			}
		}
	}
	return $found;
}

/**
 * The bot cache bucket only exists when isolation is on AND Guest Mode has
 * an active level. Warming a bot variant outside that state is pure waste.
 */
function aero_cw_guest_bucket_active() {
	return function_exists( 'aero_cm_guest_isolation_enabled' )
		&& aero_cm_guest_isolation_enabled()
		&& 'off' !== get_option( 'aero_guest_mode_level', 'off' );
}

/**
 * One warm request. Shared by the main queue worker and the surgical
 * micro-warmer so headers, UAs and freshness semantics never diverge.
 */
function aero_cw_request( $url, $variant = 'human', $fresh = true ) {
	$headers = array( 'X-Aero-Warmer' => '1' );
	if ( $fresh ) {
		// RETIRED for warming/verification: this stack's Batcache fork has
		// unreliable semantics for request no-cache (regenerates without a
		// dependable store), and any Edge-served response (HIT/STALE)
		// carries the FROZEN snapshot headers from admission anyway — so
		// piercing bought nothing trustworthy. All warm and verify traffic
		// is now plain visitor traffic; the stale-while-revalidate Edge is
		// handled by REPEATING requests (fresh is served on subsequent
		// requests, by design). Kept only as a diagnostic option.
		$headers['Cache-Control'] = 'no-cache';
		$headers['Pragma']        = 'no-cache';
	}

	$args = array(
		'timeout'     => 10,
		'sslverify'   => false,
		'redirection' => 3,
		'headers'     => $headers,
		'user-agent'  => ( 'bot' === $variant )
			? 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Chrome-Lighthouse'
			: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 AeroWarmer/1.0',
	);

	$t0   = microtime( true );
	$resp = wp_remote_get( $url, $args );
	$err  = is_wp_error( $resp );

	$bc_header = $err ? '' : (string) wp_remote_retrieve_header( $resp, 'x-nananana' );
	$xac       = $err ? '' : strtoupper( (string) wp_remote_retrieve_header( $resp, 'x-ac' ) );
	// The x-ac value can arrive as e.g. "3.dca _atomic_batcache HIT" — the
	// state token is what matters.
	foreach ( array( 'HIT', 'STALE', 'EXPIRED', 'UPDATING', 'MISS', 'BYPASS' ) as $state ) {
		if ( '' !== $xac && false !== strpos( $xac, $state ) ) {
			$xac = $state;
			break;
		}
	}

	// x-nananana: "Batcache-Hit" = served FROM cache; "Batcache-set" = this
	// very request generated AND STORED the page (it is warm from now on).
	$bc = 0;
	if ( false !== stripos( $bc_header, 'hit' ) ) {
		$bc = 1;
	} elseif ( false !== stripos( $bc_header, 'set' ) ) {
		$bc = 2;
	}

	return array(
		'code' => $err ? 0 : (int) wp_remote_retrieve_response_code( $resp ),
		'ms'   => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
		'bc'   => $bc,
		'xac'  => $xac,
	);
}

/**
 * SWR-aware cache-state evaluation.
 *
 * x-nananana is only live truth when the response actually came from ORIGIN.
 * When the Edge answers (HIT/STALE/EXPIRED), headers are the frozen snapshot
 * from admission — reading Batcache state off them is meaningless. And per
 * stale-while-revalidate, a STALE answer means fresh cache is being built
 * and served on SUBSEQUENT requests — so the right response to STALE is to
 * request again, not to report failure.
 *
 * Returns: 'edge' | 'bc-hit' | 'bc-set' | 'refreshing' | 'none'
 */
function aero_cw_eval_state( $xac, $bc ) {
	if ( 'HIT' === $xac ) {
		return 'edge'; // best case — served from the Edge tier
	}
	if ( in_array( $xac, array( 'STALE', 'EXPIRED', 'UPDATING' ), true ) ) {
		return 'refreshing'; // SWR at work; a follow-up request lands fresh
	}
	// Origin-sourced response (MISS / BYPASS / no edge header): bc is live.
	if ( 1 === $bc ) {
		return 'bc-hit';
	}
	if ( 2 === $bc ) {
		return 'bc-set'; // this very request stored it — warm from now on
	}
	return 'none';
}

// ─── Queue engine ─────────────────────────────────────────────────────────────

/**
 * Build + start a warm run. Returns the number of queue items, or false
 * when disabled / already running / nothing to warm.
 */
function aero_cw_start( $reason = 'manual' ) {
	if ( ! aero_cw_enabled() ) {
		return false;
	}
	if ( get_option( 'aero_cw_running' ) ) {
		return false; // one run at a time
	}

	// Deliberately lightweight: URL collection involves sitemap fetches, so
	// it happens inside the first cron batch — never inside the request
	// (often a "Flush All" click) that triggered the run.
	update_option( 'aero_cw_queue', array(), false );
	update_option( 'aero_cw_running', time(), false );
	update_option( 'aero_cw_stats', array(
		'reason'  => sanitize_text_field( $reason ),
		'started' => time(),
		'ended'   => 0,
		'total'   => 0, // 0 while collecting; set once the queue is built
		'done'    => 0,
		'failed'  => 0,
	), false );

	wp_clear_scheduled_hook( AERO_CW_BATCH_HOOK );
	wp_schedule_single_event( time(), AERO_CW_BATCH_HOOK );
	spawn_cron();

	return true;
}

/**
 * Stop the current run: pending items become "skipped", state closes out.
 */
function aero_cw_cancel() {
	wp_clear_scheduled_hook( AERO_CW_BATCH_HOOK );
	$queue = get_option( 'aero_cw_queue', array() );
	foreach ( $queue as &$item ) {
		if ( in_array( $item['status'], array( 'pending', 'warming' ), true ) ) {
			$item['status'] = 'skipped';
		}
	}
	unset( $item );
	update_option( 'aero_cw_queue', $queue, false );
	aero_cw_close_run();
}

function aero_cw_close_run() {
	wp_clear_scheduled_hook( AERO_CW_BATCH_HOOK );
	if ( function_exists( 'aero_cw_edge_priority_active' ) && aero_cw_edge_priority_active() ) {
		// Unique arg: without it, WP's 10-minute duplicate rule silently drops
		// this because the recurring event shares the hook.
		wp_schedule_single_event( time() + 5, AERO_CW_EDGE_HOOK, array( 'post-run-' . time() ) );
	}
	$stats = get_option( 'aero_cw_stats', array() );
	if ( is_array( $stats ) && empty( $stats['ended'] ) ) {
		$queue           = get_option( 'aero_cw_queue', array() );
		$stats['done']   = count( wp_list_filter( $queue, array( 'status' => 'done' ) ) );
		$stats['failed'] = count( wp_list_filter( $queue, array( 'status' => 'failed' ) ) );
		$stats['ended']  = time();
		update_option( 'aero_cw_stats', $stats, false );
	}
	delete_option( 'aero_cw_running' );
}

/**
 * Cron worker: warm the next batch, then self-chain until the queue drains.
 */
add_action( AERO_CW_BATCH_HOOK, 'aero_cw_run_batch' );
function aero_cw_run_batch() {
	if ( ! get_option( 'aero_cw_running' ) ) {
		return;
	}

	// RESCUE EVENT — scheduled BEFORE any work happens. If this worker dies
	// mid-batch (a hanging URL, a killed cron request, a fatal), the chain
	// used to break permanently because the next event was only scheduled at
	// the end. Now a fallback event at +120s always exists; a clean finish
	// removes it and chains at the normal +15s cadence instead.
	$rescue_ts = time() + 120;
	wp_schedule_single_event( $rescue_ts, AERO_CW_BATCH_HOOK );

	$o     = aero_cw_opts();
	$size  = max( 1, min( 10, (int) $o['batch_size'] ) );
	$queue = get_option( 'aero_cw_queue', array() );
	$stats = get_option( 'aero_cw_stats', array() );

	// First pass of the run: collect URLs and build the queue here, in cron.
	if ( empty( $queue ) && isset( $stats['total'] ) && 0 === (int) $stats['total'] ) {
		$urls = aero_cw_collect_urls();
		if ( empty( $urls ) ) {
			aero_cw_close_run();
			return;
		}
		$warm_guest = ( '1' === $o['warm_guest'] ) && aero_cw_guest_bucket_active();

		foreach ( $urls as $url ) {
			$queue[] = array( 'url' => $url, 'variant' => 'human', 'status' => 'pending', 'code' => 0, 'ms' => 0, 'time' => 0, 'bc' => -1, 'xac' => '' );
			if ( $warm_guest ) {
				$queue[] = array( 'url' => $url, 'variant' => 'bot', 'status' => 'pending', 'code' => 0, 'ms' => 0, 'time' => 0, 'bc' => -1, 'xac' => '' );
			}
		}
		// Storage guard: the queue lives in an option, and memcached-backed
		// option caches cap values around 1MB. 4,000 items stays safely under
		// that even with long permalinks.
		if ( count( $queue ) > 4000 ) {
			$queue = array_slice( $queue, 0, 4000 );
		}
		$stats['total'] = count( $queue );
		update_option( 'aero_cw_queue', $queue, false );
		update_option( 'aero_cw_stats', $stats, false );
	}

	$now  = time();
	$done = 0;

	// Wall-clock budget: never let a single worker run long enough to be
	// killed by the environment. Whatever doesn't fit chains to the next batch.
	$budget_end = time() + 20;

	foreach ( $queue as $i => &$item ) {
		// Recover items stuck in "warming" from a died worker.
		if ( 'warming' === $item['status'] && ( $now - (int) $item['time'] ) > 300 ) {
			$item['status'] = 'failed';
		}
		if ( 'pending' !== $item['status'] || $done >= $size ) {
			continue;
		}
		if ( time() > $budget_end ) {
			break;
		}

		$item['status'] = 'warming';
		$item['time']   = time();
		update_option( 'aero_cw_queue', $queue, false ); // visible to the live UI

		$result         = aero_cw_request( $item['url'], $item['variant'], false );
		$code           = $result['code'];
		$item['code']   = $code;
		$item['ms']     = $result['ms'];
		$item['time']   = time();
		$item['status'] = ( $code >= 200 && $code < 400 ) ? 'done' : 'failed';

		// VERIFICATION, SWR-aware: HTTP 200 only proves the request ran.
		// A follow-up visitor request reads the layered state; if the Edge
		// answers STALE/EXPIRED/UPDATING, stale-while-revalidate means the
		// NEXT request gets the fresh copy — so we take one more hit and
		// record the final observed state instead of crying wolf.
		$item['bc']  = -1;
		$item['xac'] = '';
		if ( 'done' === $item['status'] && time() < $budget_end ) {
			$verify = aero_cw_request( $item['url'], $item['variant'], false );
			if ( 'refreshing' === aero_cw_eval_state( $verify['xac'], $verify['bc'] ) && time() < $budget_end ) {
				usleep( 700000 );
				$verify = aero_cw_request( $item['url'], $item['variant'], false );
			}
			$item['bc']  = $verify['bc'];
			$item['xac'] = $verify['xac'];
		}
		$done++;
	}
	unset( $item );

	update_option( 'aero_cw_queue', $queue, false );

	// Live progress in stats (previously only written at close, which made
	// the Debug report show 0/N mid-run).
	$stats                  = get_option( 'aero_cw_stats', array() );
	$stats['done']          = count( wp_list_filter( $queue, array( 'status' => 'done' ) ) );
	$stats['failed']        = count( wp_list_filter( $queue, array( 'status' => 'failed' ) ) );
	$stats['last_activity'] = time();
	update_option( 'aero_cw_stats', $stats, false );

	// Clean finish: remove the rescue event, then chain normally. (Without
	// removing it first, WP's 10-minute duplicate rule would silently refuse
	// the +15s event and the cadence would degrade to the rescue interval.)
	wp_unschedule_event( $rescue_ts, AERO_CW_BATCH_HOOK );

	$pending = count( wp_list_filter( $queue, array( 'status' => 'pending' ) ) );
	if ( $pending > 0 ) {
		wp_schedule_single_event( time() + 15, AERO_CW_BATCH_HOOK );
		spawn_cron();
	} else {
		aero_cw_close_run();
	}
}

// ─── Watchdog ─────────────────────────────────────────────────────────────────

/**
 * Self-healing for broken chains. Runs on every admin page load and on
 * every live-status poll from the Warmer screen, so a stuck run recovers
 * the moment anyone looks at the admin.
 *
 *  - Run active but NO batch event scheduled → the chain broke; reschedule.
 *  - Event exists but is >90s overdue → WP-Cron isn't firing; nudge it.
 *  - Run older than 2 hours → something is deeply wrong; close it out so
 *    the lock never wedges "Warm Now" permanently.
 */
function aero_cw_watchdog() {
	$started = (int) get_option( 'aero_cw_running' );
	if ( ! $started ) {
		return;
	}
	if ( ( time() - $started ) > 2 * HOUR_IN_SECONDS ) {
		aero_cw_cancel();
		return;
	}
	$next = wp_next_scheduled( AERO_CW_BATCH_HOOK );
	if ( ! $next ) {
		wp_schedule_single_event( time(), AERO_CW_BATCH_HOOK );
		spawn_cron();
	} elseif ( $next < ( time() - 90 ) ) {
		spawn_cron();
	}
}

// Self-heal the Priority Edge recurring event (cheap: runs inside the
// existing admin_init watchdog cadence).
add_action( 'admin_init', function() {
	if ( function_exists( 'aero_cw_edge_priority_active' ) && aero_cw_edge_priority_active() && ! wp_next_scheduled( AERO_CW_EDGE_HOOK ) ) {
		aero_cw_edge_sync_schedule();
	}
}, 100 );
add_action( 'admin_init', 'aero_cw_watchdog', 99 );

// ─── Surgical micro-warmer ────────────────────────────────────────────────────

/**
 * Re-warm a small, specific URL set — the companion to surgical purging.
 * Called right after a URL-set purge so the purged pages (homepage first)
 * are regenerated within seconds instead of waiting for a real visitor.
 *
 * Deliberately tiny and separate from the main queue: no stats, no UI run,
 * capped at 40 URLs, one shot ~10s after the purge settles. When guest
 * isolation is active and the bot bucket is enabled, each URL warms twice.
 */
function aero_cw_warm_urls( $urls, $reason = '' ) {
	if ( ! aero_cw_enabled() ) {
		return;
	}
	// NOTE: deliberately NOT guarded on aero_cw_running. A URL purged
	// mid-run may already have been warmed earlier in that run — the run
	// will NOT revisit it, so skipping here leaves it cold. The micro pass
	// runs concurrently; a harmless double-warm beats a cold page.
	$urls = array_values( array_unique( array_filter( (array) $urls ) ) );
	if ( empty( $urls ) ) {
		return;
	}

	// Homepage floats to the front: it's the entry every visitor hits.
	$home = trailingslashit( home_url( '/' ) );
	usort( $urls, function( $a, $b ) use ( $home ) {
		return ( trailingslashit( $a ) === $home ? 0 : 1 ) - ( trailingslashit( $b ) === $home ? 0 : 1 );
	} );

	$reason  = sanitize_text_field( $reason );
	$pending = get_option( 'aero_cw_micro_queue', array() );
	$pending = is_array( $pending ) ? $pending : array();

	// Merge by URL: a re-purge of a queued URL refreshes its reason.
	$by_url = array();
	foreach ( $pending as $item ) {
		if ( isset( $item['url'] ) ) {
			$by_url[ $item['url'] ] = $item;
		}
	}
	foreach ( $urls as $url ) {
		$by_url[ $url ] = array(
			'url'    => $url,
			'reason' => $reason,
			'status' => 'pending',
			'added'  => time(),
		);
	}
	update_option( 'aero_cw_micro_queue', array_slice( array_values( $by_url ), 0, 40 ), false );

	// +10s: lets the Batcache group increment and the Edge purge settle so
	// the warm request stores the fresh render, never a race remnant.
	if ( ! wp_next_scheduled( AERO_CW_MICRO_HOOK ) ) {
		wp_schedule_single_event( time() + 10, AERO_CW_MICRO_HOOK );
		spawn_cron();
	}
}

add_action( AERO_CW_MICRO_HOOK, 'aero_cw_run_micro_warm' );
function aero_cw_run_micro_warm() {
	$pending = get_option( 'aero_cw_micro_queue', array() );
	if ( empty( $pending ) || ! is_array( $pending ) ) {
		delete_option( 'aero_cw_micro_queue' );
		return;
	}
	$o          = aero_cw_opts();
	$warm_guest = ( '1' === $o['warm_guest'] ) && aero_cw_guest_bucket_active();

	$budget_end = time() + 20;
	$warmed     = 0;
	$log        = get_option( 'aero_cw_micro_log', array() );
	$log        = is_array( $log ) ? $log : array();

	while ( ! empty( $pending ) && time() < $budget_end ) {
		// Mark the head item "warming" and persist, so the Warmer screen's
		// live poll shows exactly what is being regenerated right now.
		$pending[0]['status'] = 'warming';
		update_option( 'aero_cw_micro_queue', $pending, false );

		$item = array_shift( $pending );
		$res  = aero_cw_request( $item['url'], 'human', false );
		$vars = 1;
		if ( $warm_guest && time() < $budget_end ) {
			aero_cw_request( $item['url'], 'bot', false );
			$vars = 2;
		}
		// SWR-aware verification (see run_batch note).
		$bc  = -1;
		$vx  = '';
		if ( $res['code'] >= 200 && $res['code'] < 400 && time() < $budget_end ) {
			$verify = aero_cw_request( $item['url'], 'human', false );
			if ( 'refreshing' === aero_cw_eval_state( $verify['xac'], $verify['bc'] ) && time() < $budget_end ) {
				usleep( 700000 );
				$verify = aero_cw_request( $item['url'], 'human', false );
			}
			$bc = $verify['bc'];
			$vx = $verify['xac'];
		}
		$warmed++;

		array_unshift( $log, array(
			'url'      => $item['url'],
			'reason'   => isset( $item['reason'] ) ? $item['reason'] : '',
			'code'     => $res['code'],
			'ms'       => $res['ms'],
			'variants' => $vars,
			'bc'       => $bc,
			'xac'      => $vx,
			'time'     => time(),
		) );

		update_option( 'aero_cw_micro_queue', $pending, false );
	}

	update_option( 'aero_cw_micro_log', array_slice( $log, 0, 50 ), false );
	update_option( 'aero_cw_micro_stats', array(
		'time'   => time(),
		'warmed' => $warmed,
		'guest'  => $warm_guest ? 1 : 0,
	), false );

	if ( ! empty( $pending ) ) {
		wp_schedule_single_event( time() + 15, AERO_CW_MICRO_HOOK );
		spawn_cron();
	} else {
		delete_option( 'aero_cw_micro_queue' );
	}
}

// ─── Automatic warm after flushes ─────────────────────────────────────────────
/**
 * A full or Batcache flush makes the re-warm history meaningless — those
 * verifications describe cache entries that no longer exist. Wipe the list.
 */
function aero_cw_clear_micro() {
	delete_option( 'aero_cw_micro_queue' );
	delete_option( 'aero_cw_micro_log' );
	delete_option( 'aero_cw_micro_stats' );
	wp_clear_scheduled_hook( AERO_CW_MICRO_HOOK );
}
add_action( 'aero_cm_after_sequential_flush', 'aero_cw_clear_micro', 15 );

add_action( 'aero_cm_after_sequential_flush', 'aero_cw_after_flush', 20, 2 );
function aero_cw_after_flush( $results, $context ) {
	$o = aero_cw_opts();
	if ( '1' !== $o['auto_after_flush'] ) {
		return;
	}
	aero_cw_restart( 'after-flush: ' . $context );
}

/**
 * Stale-proofing: if a flush lands while a warm run is in progress, URLs
 * warmed BEFORE the flush were just purged again — the run's "done" marks
 * are lies. Cancel the stale run and start over so every completed item
 * is guaranteed to hold post-flush content.
 */
function aero_cw_restart( $reason ) {
	if ( get_option( 'aero_cw_running' ) ) {
		aero_cw_cancel();
	}
	return aero_cw_start( $reason );
}

/**
 * Explicit trigger for standalone flush contexts (manual object flush from
 * the Cache screen, admin-bar object flush). Deliberately NOT hooked to
 * aero_cm_after_object_cache_flush: that action also fires inside sequential
 * runs, which would start warming mid-flush — before the Edge purge — and
 * the purge would immediately invalidate the freshly warmed entries.
 */
function aero_cw_maybe_auto_start( $reason ) {
	aero_cw_clear_micro(); // standalone Batcache flush: re-warm history is stale
	$o = aero_cw_opts();
	if ( '1' === $o['auto_after_flush'] ) {
		aero_cw_restart( $reason );
	}
}

// ─── Priority Edge ────────────────────────────────────────────────────────────
// Batcache admits a page after 1 hit; WP Stratos Edge needs 2 hits within 2
// minutes before it serves from Edge — a drastically faster tier. This keeps
// up to 10 hand-picked URLs admitted at the Edge at all times: each pass
// sends two visitor-like hits in quick succession (hit 1 populates Batcache,
// hit 2 satisfies the Edge admission rule), then a third request reads the
// x-ac header to verify what the Edge is actually doing.

function aero_cw_edge_priority_urls() {
	$urls = get_option( 'aero_cw_edge_priority', false );
	if ( false === $urls ) {
		// First run: homepage pre-seeded (removable — deleting it persists []).
		$urls = array( home_url( '/' ) );
		update_option( 'aero_cw_edge_priority', $urls, false );
	}
	return is_array( $urls ) ? array_slice( $urls, 0, 10 ) : array();
}

function aero_cw_edge_priority_active() {
	$o = aero_cw_opts();
	return aero_cw_enabled() && '1' === $o['edge_priority_enabled'] && count( aero_cw_edge_priority_urls() ) > 0;
}

add_filter( 'cron_schedules', function( $schedules ) {
	$schedules['aero_cw_10min'] = array(
		'interval' => 10 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 10 minutes (Aero Priority Edge)', 'aero' ),
	);
	return $schedules;
} );

function aero_cw_edge_sync_schedule() {
	wp_clear_scheduled_hook( AERO_CW_EDGE_HOOK );
	if ( aero_cw_edge_priority_active() ) {
		wp_schedule_event( time() + 30, 'aero_cw_10min', AERO_CW_EDGE_HOOK );
	}
}

add_action( AERO_CW_EDGE_HOOK, 'aero_cw_run_edge_priority', 10, 1 );
function aero_cw_run_edge_priority( $trigger = '' ) {
	if ( ! aero_cw_edge_priority_active() ) {
		return;
	}

	$status     = get_option( 'aero_cw_edge_status', array() );
	$status     = is_array( $status ) ? $status : array();
	$budget_end = time() + 25;

	foreach ( aero_cw_edge_priority_urls() as $url ) {
		if ( time() > $budget_end ) {
			break; // recurring schedule picks the rest up next tick
		}

		// Hit 1: populate Batcache. Hit 2: quick succession — the Edge's
		// second qualifying hit. Both visitor-like (no bypass headers).
		aero_cw_request( $url, 'human', false );
		aero_cw_request( $url, 'human', false );

		// Brief settle, then verify what the Edge reports. Per SWR, a
		// STALE/EXPIRED/UPDATING answer means the fresh copy is served on
		// SUBSEQUENT requests — so keep requesting (up to 2 extra) until
		// the Edge lands on HIT, and record the FINAL state.
		usleep( 500000 );
		$verify   = aero_cw_request( $url, 'human', false );
		$attempts = 0;
		while ( $attempts < 2
			&& in_array( $verify['xac'], array( 'STALE', 'EXPIRED', 'UPDATING' ), true )
			&& time() < $budget_end ) {
			usleep( 700000 );
			$verify = aero_cw_request( $url, 'human', false );
			$attempts++;
		}

		$status[ $url ] = array(
			'xac'  => $verify['xac'],
			'bc'   => $verify['bc'],
			'code' => $verify['code'],
			'ms'   => $verify['ms'],
			'time' => time(),
		);
		update_option( 'aero_cw_edge_status', $status, false );
	}

	// Drop statuses for URLs no longer on the list.
	$keep = array_flip( aero_cw_edge_priority_urls() );
	update_option( 'aero_cw_edge_status', array_intersect_key( $status, $keep ), false );
}

// ─── Scheduled (periodic) warming ─────────────────────────────────────────────
add_action( AERO_CW_SCHEDULE_HOOK, function() {
	aero_cw_start( 'scheduled' );
} );

function aero_cw_sync_schedule() {
	$o = aero_cw_opts();
	wp_clear_scheduled_hook( AERO_CW_SCHEDULE_HOOK );
	if ( '1' === $o['schedule_enabled'] && aero_cw_enabled() ) {
		$interval  = $o['schedule_interval'];
		$intervals = function_exists( 'aero_cm_allowed_schedule_intervals' ) ? aero_cm_allowed_schedule_intervals() : array( 'daily' => 'Daily' );
		if ( ! array_key_exists( $interval, $intervals ) ) {
			$interval = 'daily';
		}
		wp_schedule_event( time() + 300, $interval, AERO_CW_SCHEDULE_HOOK );
	}
}

// ─── Save handler ─────────────────────────────────────────────────────────────
add_action( 'admin_init', 'aero_cw_handle_settings_save', 5 );
function aero_cw_handle_settings_save() {
	if ( ! isset( $_POST['aero_cw_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aero_cw_nonce'] ) ), 'aero_cw_save' ) ||
		 ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$o = aero_cw_defaults();
	foreach ( array( 'enabled', 'auto_after_flush', 'warm_guest', 'schedule_enabled' ) as $k ) {
		// Value-aware: disabled states post their saved value via a hidden
		// field ('' when off), which isset() alone would misread as checked.
		$o[ $k ] = ( isset( $_POST['aero_cw'][ $k ] ) && '' !== $_POST['aero_cw'][ $k ] ) ? '1' : '';
	}
	$o['sitemap_url'] = isset( $_POST['aero_cw']['sitemap_url'] ) ? esc_url_raw( wp_unslash( $_POST['aero_cw']['sitemap_url'] ) ) : '';

	$o['use_llms'] = isset( $_POST['aero_cw']['use_llms'] ) ? '1' : '';

	$limit_raw = isset( $_POST['aero_cw']['limit'] ) ? sanitize_key( wp_unslash( $_POST['aero_cw']['limit'] ) ) : '50';
	if ( 'all' === $limit_raw ) {
		$o['limit'] = 'all';
	} else {
		$limit      = absint( $limit_raw );
		$o['limit'] = (string) ( in_array( $limit, array( 10, 25, 50, 100, 250, 500 ), true ) ? $limit : 50 );
	}

	$batch           = isset( $_POST['aero_cw']['batch_size'] ) ? absint( $_POST['aero_cw']['batch_size'] ) : 5;
	$o['batch_size'] = (string) ( in_array( $batch, array( 2, 5, 8 ), true ) ? $batch : 5 );

	foreach ( array( 'priority_urls', 'excludes' ) as $list ) {
		$raw         = isset( $_POST['aero_cw'][ $list ] ) ? wp_unslash( $_POST['aero_cw'][ $list ] ) : '';
		$lines       = array_filter( array_map( 'sanitize_text_field', preg_split( '/[\r\n]+/', $raw ) ) );
		$o[ $list ]  = implode( "\n", $lines );
	}

	$iv                      = isset( $_POST['aero_cw']['schedule_interval'] ) ? sanitize_key( $_POST['aero_cw']['schedule_interval'] ) : 'daily';
	$intervals               = function_exists( 'aero_cm_allowed_schedule_intervals' ) ? aero_cm_allowed_schedule_intervals() : array( 'daily' => 'Daily' );
	$o['schedule_interval']  = array_key_exists( $iv, $intervals ) ? $iv : 'daily';

	$o['edge_priority_enabled'] = isset( $_POST['aero_cw']['edge_priority_enabled'] ) ? '1' : '';

	update_option( 'aero_cw_options', $o );
	aero_cw_sync_schedule();
	aero_cw_edge_sync_schedule();

	if ( function_exists( 'aero_cm_branded_notice' ) ) {
		aero_cm_branded_notice( __( 'Cache Warmer settings saved.', 'aero' ), '#22c55e' );
	}
	if ( function_exists( 'aero_ui_redirect' ) ) {
		aero_ui_redirect( 'aero-warmer' );
	}
}

// ─── AJAX: start / cancel / live status ───────────────────────────────────────
add_action( 'wp_ajax_aero_cw_start_now', function() {
	check_ajax_referer( 'aero_cw_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error();
	}
	$started = aero_cw_start( 'manual' );
	if ( false === $started ) {
		wp_send_json_error( array( 'message' => aero_cw_enabled()
			? __( 'A warm run is already in progress.', 'aero' )
			: __( 'The Cache Warmer is disabled — enable it below first.', 'aero' ) ) );
	}
	wp_send_json_success();
} );

add_action( 'wp_ajax_aero_cw_cancel', function() {
	check_ajax_referer( 'aero_cw_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error();
	}
	aero_cw_cancel();
	wp_send_json_success();
} );

add_action( 'wp_ajax_aero_cw_status', function() {
	check_ajax_referer( 'aero_cw_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error();
	}
	aero_cw_watchdog(); // a stuck run heals itself while the page is watching
	wp_send_json_success( aero_cw_status_payload() );
} );

/**
 * Everything the live UI needs in one payload.
 */
function aero_cw_status_payload() {
	$queue   = get_option( 'aero_cw_queue', array() );
	$stats   = get_option( 'aero_cw_stats', array() );
	$running = (bool) get_option( 'aero_cw_running' );

	$counts = array( 'pending' => 0, 'warming' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0 );
	$rows   = array();
	foreach ( $queue as $item ) {
		if ( isset( $counts[ $item['status'] ] ) ) {
			$counts[ $item['status'] ]++;
		}
		$rows[] = array(
			'url'     => esc_url( $item['url'] ),
			'path'    => esc_html( wp_parse_url( $item['url'], PHP_URL_PATH ) ?: '/' ),
			'variant' => esc_html( $item['variant'] ),
			'status'  => esc_html( $item['status'] ),
			'code'    => (int) $item['code'],
			'ms'      => (int) $item['ms'],
			'bc'      => isset( $item['bc'] ) ? (int) $item['bc'] : -1,
			'xac'     => esc_html( isset( $item['xac'] ) ? $item['xac'] : '' ),
		);
	}

	// Surgical re-warm activity: active items first, then recent results.
	$micro_rows  = array();
	$micro_queue = get_option( 'aero_cw_micro_queue', array() );
	foreach ( (array) $micro_queue as $mi ) {
		if ( ! isset( $mi['url'] ) ) {
			continue;
		}
		$micro_rows[] = array(
			'url'    => esc_url( $mi['url'] ),
			'path'   => esc_html( wp_parse_url( $mi['url'], PHP_URL_PATH ) ?: '/' ),
			'reason' => esc_html( isset( $mi['reason'] ) ? $mi['reason'] : '' ),
			'status' => esc_html( isset( $mi['status'] ) ? $mi['status'] : 'pending' ),
			'code'   => 0,
			'ms'     => 0,
			'vars'   => 0,
			'when'   => '',
		);
	}
	foreach ( array_slice( (array) get_option( 'aero_cw_micro_log', array() ), 0, 30 ) as $ml ) {
		if ( ! isset( $ml['url'] ) ) {
			continue;
		}
		$code         = (int) $ml['code'];
		$micro_rows[] = array(
			'url'    => esc_url( $ml['url'] ),
			'path'   => esc_html( wp_parse_url( $ml['url'], PHP_URL_PATH ) ?: '/' ),
			'reason' => esc_html( isset( $ml['reason'] ) ? $ml['reason'] : '' ),
			'status' => ( $code >= 200 && $code < 400 ) ? 'done' : 'failed',
			'code'   => $code,
			'ms'     => (int) $ml['ms'],
			'vars'   => (int) $ml['variants'],
			'bc'     => isset( $ml['bc'] ) ? (int) $ml['bc'] : -1,
			'xac'    => esc_html( isset( $ml['xac'] ) ? $ml['xac'] : '' ),
			'when'   => esc_html( human_time_diff( (int) $ml['time'] ) . ' ' . __( 'ago', 'aero' ) ),
		);
	}

	return array(
		'running' => $running,
		'total'   => count( $queue ),
		'counts'  => $counts,
		'stats'   => $stats,
		'rows'    => array_slice( $rows, 0, 2000 ),
		'more'    => max( 0, count( $rows ) - 2000 ),
		'micro'   => array(
			'active' => ! empty( $micro_queue ),
			'rows'   => $micro_rows,
		),
		'edge'    => aero_cw_edge_payload(),
	);
}

/**
 * Priority Edge state for the live UI.
 */
function aero_cw_edge_payload() {
	$o      = aero_cw_opts();
	$status = get_option( 'aero_cw_edge_status', array() );
	$rows   = array();
	foreach ( aero_cw_edge_priority_urls() as $url ) {
		$st     = isset( $status[ $url ] ) && is_array( $status[ $url ] ) ? $status[ $url ] : array();
		$rows[] = array(
			'url'  => esc_url( $url ),
			'path' => esc_html( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' ),
			'xac'  => esc_html( isset( $st['xac'] ) ? $st['xac'] : '' ),
			'bc'   => isset( $st['bc'] ) ? (int) $st['bc'] : -1,
			'ms'   => isset( $st['ms'] ) ? (int) $st['ms'] : 0,
			'when' => isset( $st['time'] ) ? esc_html( human_time_diff( (int) $st['time'] ) . ' ' . __( 'ago', 'aero' ) ) : '',
		);
	}
	return array(
		'enabled' => '1' === $o['edge_priority_enabled'],
		'rows'    => $rows,
		'slots'   => 10,
	);
}

add_action( 'wp_ajax_aero_cw_verify_refresh', function() {
	check_ajax_referer( 'aero_cw_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error();
	}

	// Rate limit: these are real requests against the site.
	$lock = get_transient( 'aero_cw_verify_lock' );
	if ( $lock ) {
		$wait = max( 1, (int) $lock - time() );
		wp_send_json_error( array( 'message' => sprintf(
			/* translators: %d: seconds remaining */
			__( 'Cache status was just refreshed — try again in %ds.', 'aero' ),
			$wait
		), 'wait' => $wait ) );
	}
	set_transient( 'aero_cw_verify_lock', time() + 60, 60 );

	// Re-verify the most recent run's completed items with Edge-piercing
	// requests, so the answer reflects live origin Batcache state — not an
	// Edge snapshot. A cold page gets warmed by its own check.
	$queue      = get_option( 'aero_cw_queue', array() );
	$budget_end = time() + 15;
	$checked    = 0;
	foreach ( $queue as &$item ) {
		if ( $checked >= 20 || time() > $budget_end ) {
			break;
		}
		if ( ! in_array( $item['status'], array( 'done', 'failed' ), true ) ) {
			continue;
		}
		$verify = aero_cw_request( $item['url'], $item['variant'], false );
		if ( 'refreshing' === aero_cw_eval_state( $verify['xac'], $verify['bc'] ) && time() < $budget_end ) {
			usleep( 500000 );
			$verify = aero_cw_request( $item['url'], $item['variant'], false );
		}
		$item['bc']    = $verify['bc'];
		$item['xac']   = $verify['xac'];
		if ( $verify['code'] >= 200 && $verify['code'] < 400 && 'failed' === $item['status'] ) {
			$item['status'] = 'done'; // it answers now — reflect reality
			$item['code']   = $verify['code'];
		}
		$checked++;
	}
	unset( $item );
	update_option( 'aero_cw_queue', $queue, false );

	wp_send_json_success( aero_cw_status_payload() );
} );

add_action( 'wp_ajax_aero_cw_edge_add', function() {
	check_ajax_referer( 'aero_cw_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error();
	}
	$url = isset( $_POST['url'] ) ? trim( wp_unslash( $_POST['url'] ) ) : '';
	if ( 0 === strpos( $url, '/' ) ) {
		$url = home_url( $url );
	}
	$url = esc_url_raw( $url );
	if ( '' === $url || ! aero_cw_is_local( $url ) ) {
		wp_send_json_error( array( 'message' => __( 'Only URLs on this site can be Edge priorities.', 'aero' ) ) );
	}
	$url  = trailingslashit( strtok( $url, '#' ) );
	$urls = aero_cw_edge_priority_urls();
	if ( in_array( $url, $urls, true ) ) {
		wp_send_json_error( array( 'message' => __( 'That URL is already on the list.', 'aero' ) ) );
	}
	if ( count( $urls ) >= 10 ) {
		wp_send_json_error( array( 'message' => __( 'The Priority Edge list is full (10 max) — remove one first.', 'aero' ) ) );
	}
	$urls[] = $url;
	update_option( 'aero_cw_edge_priority', $urls, false );
	aero_cw_edge_sync_schedule();
	wp_schedule_single_event( time() + 2, AERO_CW_EDGE_HOOK, array( 'add-' . time() ) );
	spawn_cron();
	wp_send_json_success();
} );

add_action( 'wp_ajax_aero_cw_edge_remove', function() {
	check_ajax_referer( 'aero_cw_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error();
	}
	$url  = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
	$urls = array_values( array_diff( aero_cw_edge_priority_urls(), array( $url ) ) );
	update_option( 'aero_cw_edge_priority', $urls, false ); // [] persists: homepage default is removable
	$status = get_option( 'aero_cw_edge_status', array() );
	unset( $status[ $url ] );
	update_option( 'aero_cw_edge_status', $status, false );
	aero_cw_edge_sync_schedule();
	wp_send_json_success();
} );

add_action( 'wp_ajax_aero_cw_edge_warm_now', function() {
	check_ajax_referer( 'aero_cw_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error();
	}
	if ( ! aero_cw_edge_priority_active() ) {
		wp_send_json_error( array( 'message' => __( 'Priority Edge is disabled or the list is empty.', 'aero' ) ) );
	}
	wp_schedule_single_event( time(), AERO_CW_EDGE_HOOK, array( 'manual-' . time() ) );
	spawn_cron();
	wp_send_json_success();
} );

add_action( 'wp_ajax_aero_cw_llms_rescan', function() {
	check_ajax_referer( 'aero_cw_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error();
	}
	delete_transient( 'aero_cw_llms_check' );
	$status = aero_cw_llms_status(); // fresh check, re-caches
	wp_send_json_success( array( 'exists' => (bool) $status['exists'] ) );
} );

add_action( 'wp_ajax_aero_cw_llms_preview', function() {
	check_ajax_referer( 'aero_cw_ajax', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error();
	}
	$resp = wp_remote_get( home_url( '/llms.txt' ), array( 'timeout' => 8, 'sslverify' => false ) );
	if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
		wp_send_json_error( array( 'message' => __( 'llms.txt could not be fetched. Re-scan to refresh detection.', 'aero' ) ) );
	}
	$body = wp_remote_retrieve_body( $resp );
	if ( strlen( $body ) > 200000 ) { // preview sanity cap
		$body = substr( $body, 0, 200000 );
	}

	$selected = aero_cw_urls_from_llms();

	// External/skipped count: everything URL-shaped that wasn't selected.
	$all = array();
	if ( preg_match_all( '/https?:\/\/[^\s)\]"\'<>]+/i', $body, $m ) ) {
		$all = array_unique( $m[0] );
	}
	$skipped = count( array_diff( $all, $selected ) );

	wp_send_json_success( array(
		'content'  => $body,
		'selected' => array_values( $selected ),
		'skipped'  => (int) $skipped,
	) );
} );

// ─── Screen render ────────────────────────────────────────────────────────────
function aero_cw_render_warmer_screen() {
	$o       = aero_cw_opts();
	$payload = aero_cw_status_payload();
	$stats   = $payload['stats'];
	$next    = wp_next_scheduled( AERO_CW_SCHEDULE_HOOK );
	?>

	<!-- ═══ Status + controls ═══ -->
	<div class="aero-section">
		<div class="aero-diag-head">
			<div class="aero-eyebrow" style="margin-bottom:0;"><?php esc_html_e( 'Warm Run', 'aero' ); ?></div>
			<div class="aero-actions" style="padding-top:0;">
				<button type="button" id="aero-cw-cancel" class="aero-btn aero-btn-ghost" style="<?php echo $payload['running'] ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Cancel Run', 'aero' ); ?></button>
				<button type="button" id="aero-cw-start" class="aero-btn aero-btn-primary" <?php disabled( $payload['running'] ); ?>><?php esc_html_e( 'Warm Now', 'aero' ); ?></button>
			</div>
		</div>

		<div class="aero-status-row" id="aero-cw-statusrow">
			<span class="aero-dot idle" id="aero-cw-dot"></span>
			<span class="aero-status-strong" id="aero-cw-state"><?php esc_html_e( 'Idle', 'aero' ); ?></span>
			<span id="aero-cw-summary"><?php esc_html_e( 'No warm run yet.', 'aero' ); ?></span>
			<span class="aero-status-meta" id="aero-cw-lastrun"></span>
		</div>

		<div class="aero-progress" id="aero-cw-progress" style="display:none;">
			<div class="aero-progress-fill" id="aero-cw-progress-fill" style="width:0%;"></div>
		</div>
		<div class="aero-cw-counts" id="aero-cw-counts" style="display:none;">
			<span class="aero-cw-count"><span class="aero-dot ok"></span> <em id="aero-cw-n-done">0</em> <?php esc_html_e( 'warmed', 'aero' ); ?></span>
			<span class="aero-cw-count"><span class="aero-dot warn"></span> <em id="aero-cw-n-warming">0</em> <?php esc_html_e( 'warming', 'aero' ); ?></span>
			<span class="aero-cw-count"><span class="aero-dot idle"></span> <em id="aero-cw-n-pending">0</em> <?php esc_html_e( 'pending', 'aero' ); ?></span>
			<span class="aero-cw-count"><span class="aero-dot err"></span> <em id="aero-cw-n-failed">0</em> <?php esc_html_e( 'failed', 'aero' ); ?></span>
		</div>
	</div>

	<!-- ═══ Queue ═══ -->
	<div class="aero-section">
		<div class="aero-diag-head">
			<div class="aero-eyebrow" style="margin-bottom:0;"><?php esc_html_e( 'Queue', 'aero' ); ?> <span class="aero-eyebrow-aside"><?php esc_html_e( 'most recent run', 'aero' ); ?></span></div>
			<div class="aero-actions" style="padding-top:0;">
				<button type="button" id="aero-cw-verify" class="aero-btn aero-btn-ghost aero-btn-sm" title="<?php esc_attr_e( 'Re-checks the Batcache-Hit header for up to 20 completed items. A cold page is warmed by its own check.', 'aero' ); ?>"><?php esc_html_e( 'Refresh Cache Status', 'aero' ); ?></button>
			</div>
		</div>
		<div class="aero-cw-toolbar" id="aero-cw-toolbar" style="display:none;">
			<div class="aero-search">
				<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
				<input type="search" id="aero-cw-search" class="aero-search-input" placeholder="<?php esc_attr_e( 'Filter URLs…', 'aero' ); ?>" autocomplete="off" />
			</div>
			<div class="aero-pager">
				<span class="aero-pager-info" id="aero-cw-pageinfo"></span>
				<button type="button" class="aero-pager-btn" id="aero-cw-prev" aria-label="<?php esc_attr_e( 'Previous page', 'aero' ); ?>">&larr;</button>
				<button type="button" class="aero-pager-btn" id="aero-cw-next" aria-label="<?php esc_attr_e( 'Next page', 'aero' ); ?>">&rarr;</button>
			</div>
		</div>
		<div id="aero-cw-queue-wrap">
			<div class="aero-cw-empty" id="aero-cw-empty" <?php echo ! empty( $payload['rows'] ) ? 'style="display:none;"' : ''; ?>>
				<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
				<p><?php esc_html_e( 'Nothing here yet. Hit "Warm Now" — or flush any cache with auto-warm enabled — and the queue fills in live.', 'aero' ); ?></p>
			</div>
			<table class="aero-table" id="aero-cw-table" <?php echo empty( $payload['rows'] ) ? 'style="display:none;"' : ''; ?>>
				<thead>
					<tr>
						<th style="width:18px;"></th>
						<th><?php esc_html_e( 'URL', 'aero' ); ?></th>
							<th style="width:70px;"><?php esc_html_e( 'Variant', 'aero' ); ?></th>
						<th style="width:60px;"><?php esc_html_e( 'HTTP', 'aero' ); ?></th>
						<th style="width:76px;" title="<?php esc_attr_e( 'Verified via the x-nananana Batcache-Hit header on a second request', 'aero' ); ?>"><?php esc_html_e( 'Cache', 'aero' ); ?></th>
						<th style="width:70px;"><?php esc_html_e( 'Time', 'aero' ); ?></th>
					</tr>
				</thead>
				<tbody id="aero-cw-tbody"></tbody>
			</table>
			<p class="aero-hint" id="aero-cw-more" style="display:none;"></p>
		</div>
	</div>

	<!-- ═══ Re-warms ═══ -->
	<div class="aero-section" id="aero-surgical">
		<div class="aero-diag-head">
			<div class="aero-eyebrow" style="margin-bottom:0;"><?php esc_html_e( 'Re-warms', 'aero' ); ?> <span class="aero-eyebrow-aside"><?php esc_html_e( 'purged by a trigger, re-warmed automatically — cleared on every full flush', 'aero' ); ?></span></div>
		</div>
		<div class="aero-cw-empty" id="aero-micro-empty" style="padding:24px 20px;">
			<p><?php esc_html_e( 'Nothing yet. When a page edit, publish, trash, comment change or per-page flush purges a URL set, each URL appears here — pending, warming, then done — with the reason it was purged. The list resets whenever a full flush makes it obsolete.', 'aero' ); ?></p>
		</div>
		<table class="aero-table" id="aero-micro-table" style="display:none;">
			<thead>
				<tr>
					<th style="width:18px;"></th>
					<th><?php esc_html_e( 'URL', 'aero' ); ?></th>
					<th><?php esc_html_e( 'Why', 'aero' ); ?></th>
					<th style="width:64px;"><?php esc_html_e( 'Variants', 'aero' ); ?></th>
					<th style="width:56px;"><?php esc_html_e( 'HTTP', 'aero' ); ?></th>
					<th style="width:76px;" title="<?php esc_attr_e( 'Verified via the x-nananana Batcache-Hit header on a second request', 'aero' ); ?>"><?php esc_html_e( 'Cache', 'aero' ); ?></th>
					<th style="width:64px;"><?php esc_html_e( 'Time', 'aero' ); ?></th>
					<th style="width:110px;"><?php esc_html_e( 'Completed', 'aero' ); ?></th>
				</tr>
			</thead>
			<tbody id="aero-micro-tbody"></tbody>
		</table>
	</div>

	<!-- ═══ Priority Edge ═══ -->
	<?php $edge_payload = aero_cw_edge_payload(); ?>
	<div class="aero-section" id="aero-priority-edge">
		<div class="aero-diag-head">
			<div class="aero-eyebrow" style="margin-bottom:0;"><?php esc_html_e( 'Priority Edge', 'aero' ); ?> <span class="aero-eyebrow-aside"><?php esc_html_e( 'served from the Edge at all times', 'aero' ); ?> · <em id="aero-edge-count" style="font-style:normal;font-family:var(--mono);"><?php echo esc_html( count( $edge_payload['rows'] ) . '/10' ); ?></em></span></div>
			<div class="aero-actions" style="padding-top:0;">
				<button type="button" id="aero-edge-warm" class="aero-btn aero-btn-ghost aero-btn-sm"><?php esc_html_e( 'Warm Edge Now', 'aero' ); ?></button>
			</div>
		</div>

		<p class="aero-hint" style="margin-top:0;"><?php esc_html_e( 'Batcache admits a page after 1 visit — the Edge needs 2 visits within 2 minutes before it serves from Edge, the fastest tier there is. Every 10 minutes, each URL below gets two quick visitor-like hits (Batcache, then Edge admission) and a third that verifies the x-ac header.', 'aero' ); ?></p>

		<div class="aero-edge-addbar">
			<div class="aero-edge-addwrap">
				<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
				<input type="text" id="aero-edge-add-input" placeholder="<?php esc_attr_e( '/pricing/ — or paste a full URL on this site', 'aero' ); ?>" autocomplete="off" spellcheck="false" />
				<button type="button" id="aero-edge-add-btn" class="aero-btn aero-btn-primary aero-btn-sm"><?php esc_html_e( 'Add to Edge', 'aero' ); ?></button>
			</div>
			<p class="aero-hint" style="margin:6px 0 0;"><?php esc_html_e( 'Press Enter to add. Relative paths resolve to this site; external URLs are rejected.', 'aero' ); ?></p>
		</div>

		<div class="aero-cw-empty" id="aero-edge-empty" style="padding:22px 20px;<?php echo ! empty( $edge_payload['rows'] ) ? 'display:none;' : ''; ?>">
			<p><?php esc_html_e( 'No priority URLs. Add up to 10 — they\'ll be kept admitted at the Edge around the clock.', 'aero' ); ?></p>
		</div>
		<div class="aero-edge-list" id="aero-edge-list" <?php echo empty( $edge_payload['rows'] ) ? 'style="display:none;"' : ''; ?>></div>
	</div>

	<hr class="aero-divider" />

	<!-- ═══ Settings ═══ -->
	<form method="post">
		<?php wp_nonce_field( 'aero_cw_save', 'aero_cw_nonce' ); ?>

		<div class="aero-section">
			<div class="aero-eyebrow"><?php esc_html_e( 'Warmer Settings', 'aero' ); ?></div>
			<div class="aero-check-list">
				<label class="aero-check-row aero-check-row-simple">
					<input type="checkbox" name="aero_cw[enabled]" <?php checked( $o['enabled'], '1' ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Enable Cache Warmer', 'aero' ); ?> <span class="aero-tag ok"><?php esc_html_e( 'Recommended', 'aero' ); ?></span></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Master switch. When off, nothing warms — manually or automatically.', 'aero' ); ?></span>
					</span>
				</label>
				<label class="aero-check-row aero-check-row-simple">
					<input type="checkbox" name="aero_cw[auto_after_flush]" <?php checked( $o['auto_after_flush'], '1' ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Warm automatically after every flush', 'aero' ); ?> <span class="aero-tag ok"><?php esc_html_e( 'Recommended', 'aero' ); ?></span></span>
						<span class="aero-check-sub"><?php esc_html_e( 'The moment any full flush completes — manual, admin bar, automated trigger, or scheduled — the warmer rebuilds the cache before real visitors arrive.', 'aero' ); ?></span>
					</span>
				</label>
				<?php $guest_bucket = aero_cw_guest_bucket_active(); ?>
				<label class="aero-check-row aero-check-row-simple<?php echo $guest_bucket ? '' : ' aero-check-row-muted'; ?>">
					<?php if ( $guest_bucket ) : ?>
						<input type="checkbox" name="aero_cw[warm_guest]" <?php checked( $o['warm_guest'], '1' ); ?> />
					<?php else : ?>
						<input type="checkbox" disabled <?php checked( $o['warm_guest'], '1' ); ?> />
						<input type="hidden" name="aero_cw[warm_guest]" value="<?php echo esc_attr( '1' === $o['warm_guest'] ? '1' : '' ); ?>" />
					<?php endif; ?>
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Also warm the Guest (bot) cache bucket', 'aero' ); ?>
							<?php if ( ! $guest_bucket ) : ?>
								<span class="aero-tag"><?php esc_html_e( 'Guest Mode off', 'aero' ); ?></span>
							<?php endif; ?>
						</span>
						<span class="aero-check-sub"><?php esc_html_e( 'When Guest Mode cache isolation is active, each URL is warmed twice — as a regular visitor and as a PageSpeed-style bot — so both buckets are hot. Doubles the request count. Ignored when isolation is off.', 'aero' ); ?></span>
					</span>
				</label>
				<?php $llms = aero_cw_llms_status(); ?>
				<label class="aero-check-row aero-check-row-simple<?php echo $llms['exists'] ? '' : ' aero-check-row-muted'; ?>" id="aero-llms-row">
					<?php if ( $llms['exists'] ) : ?>
						<input type="checkbox" id="aero-llms-checkbox" name="aero_cw[use_llms]" <?php checked( $o['use_llms'], '1' ); ?> />
					<?php else : ?>
						<input type="checkbox" id="aero-llms-checkbox" disabled <?php checked( $o['use_llms'], '1' ); ?> />
						<input type="hidden" id="aero-llms-hidden" name="aero_cw[use_llms]" value="<?php echo esc_attr( '1' === $o['use_llms'] ? '1' : '' ); ?>" />
					<?php endif; ?>
					<span class="aero-check-main">
						<span class="aero-check-title">
							<?php esc_html_e( 'Include URLs from llms.txt', 'aero' ); ?>
							<?php if ( $llms['exists'] ) : ?>
								<span class="aero-tag ok" id="aero-llms-tag"><?php esc_html_e( 'Detected', 'aero' ); ?></span>
							<?php else : ?>
								<span class="aero-tag" id="aero-llms-tag"><?php esc_html_e( 'Not detected', 'aero' ); ?></span>
							<?php endif; ?>
						</span>
						<span class="aero-check-sub"><?php esc_html_e( 'llms.txt is a curated list of your most important pages — exactly the ones worth warming first. Its URLs are always included ahead of the sitemap when the file exists at /llms.txt.', 'aero' ); ?></span>
					</span>
				</label>
			</div>

			<div class="aero-check-list" style="margin-top:0;">
				<label class="aero-check-row aero-check-row-simple">
					<input type="checkbox" name="aero_cw[edge_priority_enabled]" <?php checked( $o['edge_priority_enabled'], '1' ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Priority Edge — keep top URLs admitted at the Edge', 'aero' ); ?> <span class="aero-tag ok"><?php esc_html_e( 'Recommended', 'aero' ); ?></span></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Runs the Priority Edge pass every 10 minutes for the URLs in the list above. With just the homepage that\'s 3 tiny requests per cycle — negligible load for the fastest possible serve.', 'aero' ); ?></span>
					</span>
				</label>
			</div>

			<div class="aero-llms-bar">
				<button type="button" id="aero-llms-rescan" class="aero-btn aero-btn-ghost aero-btn-sm"><?php esc_html_e( 'Re-scan llms.txt', 'aero' ); ?></button>
				<button type="button" id="aero-llms-preview-btn" class="aero-btn aero-btn-ghost aero-btn-sm" <?php disabled( ! $llms['exists'] ); ?>><?php esc_html_e( 'Preview llms.txt', 'aero' ); ?></button>
				<span class="aero-llms-checked" id="aero-llms-checked">
					<?php
					if ( ! empty( $llms['checked'] ) ) {
						/* translators: %s: human time diff */
						printf( esc_html__( 'Last checked %s ago', 'aero' ), esc_html( human_time_diff( (int) $llms['checked'] ) ) );
					}
					?>
				</span>
			</div>

			<div class="aero-llms-preview" id="aero-llms-preview" style="display:none;">
				<div class="aero-llms-preview-head">
					<span class="aero-eyebrow" style="margin:0;"><?php esc_html_e( 'llms.txt', 'aero' ); ?></span>
					<span class="aero-llms-summary" id="aero-llms-summary"></span>
					<button type="button" class="aero-llms-close" id="aero-llms-close" aria-label="<?php esc_attr_e( 'Close preview', 'aero' ); ?>">&times;</button>
				</div>
				<div class="aero-llms-legend">
					<span><mark class="aero-llms-hit aero-llms-hit-demo"><?php esc_html_e( 'highlighted', 'aero' ); ?></mark> <?php esc_html_e( '= selected for warming', 'aero' ); ?></span>
				</div>
				<pre class="aero-llms-code" id="aero-llms-code"></pre>
			</div>

			<div class="aero-field-grid aero-field-grid-3">
				<div class="aero-field">
					<label class="aero-label" for="aero-cw-limit"><?php esc_html_e( 'URLs per warm run', 'aero' ); ?></label>
					<select id="aero-cw-limit" class="aero-input" name="aero_cw[limit]">
						<?php foreach ( array( 10, 25, 50, 100, 250, 500 ) as $n ) : ?>
							<option value="<?php echo esc_attr( $n ); ?>" <?php selected( $o['limit'], (string) $n ); ?>><?php echo esc_html( $n ); ?></option>
						<?php endforeach; ?>
						<option value="all" <?php selected( $o['limit'], 'all' ); ?>><?php esc_html_e( 'All detectable URLs', 'aero' ); ?></option>
					</select>
					<p class="aero-hint"><?php esc_html_e( 'Homepage and priority URLs come first, then llms.txt, then the sitemap fills the rest. With the Guest bucket enabled, each URL is warmed twice — 50 URLs means 100 requests.', 'aero' ); ?></p>
					<div class="aero-info-box warn" id="aero-cw-all-warning" style="margin:10px 0 0;<?php echo 'all' === $o['limit'] ? '' : 'display:none;'; ?>">
						<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
						<span><strong><?php esc_html_e( 'Warming everything', 'aero' ); ?></strong> — <?php esc_html_e( 'every URL in your sitemap gets requested (capped at 5,000 for safety). On large sites a run can take a long while and generate real server load; use a gentle batch size, and prefer scheduling this off-peak.', 'aero' ); ?></span>
					</div>
					<script>
					document.getElementById('aero-cw-limit').addEventListener('change', function() {
						document.getElementById('aero-cw-all-warning').style.display = this.value === 'all' ? '' : 'none';
					});
					</script>
				</div>
				<div class="aero-field">
					<label class="aero-label" for="aero-cw-batch"><?php esc_html_e( 'Requests per batch', 'aero' ); ?></label>
					<select id="aero-cw-batch" class="aero-input" name="aero_cw[batch_size]">
						<option value="2" <?php selected( $o['batch_size'], '2' ); ?>><?php esc_html_e( '2 — gentle', 'aero' ); ?></option>
						<option value="5" <?php selected( $o['batch_size'], '5' ); ?>><?php esc_html_e( '5 — balanced', 'aero' ); ?></option>
						<option value="8" <?php selected( $o['batch_size'], '8' ); ?>><?php esc_html_e( '8 — aggressive', 'aero' ); ?></option>
					</select>
					<p class="aero-hint"><?php esc_html_e( 'Batches run ~15s apart via WP-Cron. Lower is kinder to busy servers.', 'aero' ); ?></p>
				</div>
				<div class="aero-field">
					<label class="aero-label" for="aero-cw-sitemap"><?php esc_html_e( 'Sitemap URL', 'aero' ); ?></label>
					<input type="url" id="aero-cw-sitemap" class="aero-input" name="aero_cw[sitemap_url]" value="<?php echo esc_attr( $o['sitemap_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/wp-sitemap.xml' ) ); ?>" />
					<p class="aero-hint"><?php esc_html_e( 'Leave empty to auto-detect. Sitemap indexes (Yoast, RankMath, core) are followed automatically.', 'aero' ); ?></p>
				</div>
			</div>

			<div class="aero-field-grid">
				<div class="aero-field">
					<label class="aero-label" for="aero-cw-priority"><?php esc_html_e( 'Priority URLs — warmed first', 'aero' ); ?></label>
					<textarea id="aero-cw-priority" class="aero-input aero-code-textarea" name="aero_cw[priority_urls]" rows="4" placeholder="/pricing/&#10;/contact/"><?php echo esc_textarea( $o['priority_urls'] ); ?></textarea>
					<p class="aero-hint"><?php esc_html_e( 'One per line. Relative paths or full URLs on this domain. The homepage is always first regardless.', 'aero' ); ?></p>
				</div>
				<div class="aero-field">
					<label class="aero-label" for="aero-cw-excludes"><?php esc_html_e( 'Exclude from warming', 'aero' ); ?></label>
					<textarea id="aero-cw-excludes" class="aero-input aero-code-textarea" name="aero_cw[excludes]" rows="4" placeholder="/cart/&#10;/my-account/"><?php echo esc_textarea( $o['excludes'] ); ?></textarea>
					<p class="aero-hint"><?php esc_html_e( 'One per line — case-insensitive URL fragments. Dynamic pages (cart, account) never benefit from warming.', 'aero' ); ?></p>
				</div>
			</div>
		</div>

		<div class="aero-section">
			<div class="aero-eyebrow"><?php esc_html_e( 'Scheduled Warming', 'aero' ); ?> <span class="aero-eyebrow-aside"><?php esc_html_e( 'keep the cache warm before it expires', 'aero' ); ?></span></div>
			<div class="aero-check-list">
				<label class="aero-check-row aero-check-row-simple">
					<input type="checkbox" name="aero_cw[schedule_enabled]" <?php checked( $o['schedule_enabled'], '1' ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Warm on a schedule', 'aero' ); ?></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Runs a full warm pass at the interval below, so pages whose cache TTL expired are regenerated before visitors notice. Pair the interval with your Batcache max_age.', 'aero' ); ?></span>
					</span>
				</label>
			</div>
			<div class="aero-field" style="max-width:280px;">
				<label class="aero-label" for="aero-cw-interval"><?php esc_html_e( 'Interval', 'aero' ); ?></label>
				<select id="aero-cw-interval" class="aero-input" name="aero_cw[schedule_interval]">
					<?php
					$intervals = function_exists( 'aero_cm_allowed_schedule_intervals' ) ? aero_cm_allowed_schedule_intervals() : array( 'daily' => __( 'Daily', 'aero' ) );
					foreach ( $intervals as $slug => $label ) :
					?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $o['schedule_interval'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( $next ) : ?>
					<p class="aero-hint"><strong><?php esc_html_e( 'Next scheduled warm:', 'aero' ); ?></strong> <?php echo esc_html( gmdate( 'j M Y, g:ia', $next ) ); ?> UTC</p>
				<?php endif; ?>
			</div>
		</div>

		<div class="aero-actions">
			<button type="submit" class="aero-btn aero-btn-primary"><?php esc_html_e( 'Save Warmer Settings', 'aero' ); ?></button>
		</div>
	</form>

	<script>
	(function() {
		var nonce   = '<?php echo esc_js( wp_create_nonce( 'aero_cw_ajax' ) ); ?>';
		var polling = null;

		function render(d) {
			var running = d.running;
			var total   = d.total || 0;
			var done    = d.counts.done + d.counts.failed + d.counts.skipped;
			var pct     = total > 0 ? Math.round((done / total) * 100) : 0;

			document.getElementById('aero-cw-dot').className = 'aero-dot ' + (running ? 'warn' : (total > 0 ? (d.counts.failed > 0 ? 'warn' : 'ok') : 'idle'));
			var state = '<?php echo esc_js( __( 'Idle', 'aero' ) ); ?>';
			if (running) { state = total > 0 ? '<?php echo esc_js( __( 'Warming…', 'aero' ) ); ?>' : '<?php echo esc_js( __( 'Collecting URLs…', 'aero' ) ); ?>'; }
			else if (total > 0) { state = '<?php echo esc_js( __( 'Complete', 'aero' ) ); ?>'; }
			document.getElementById('aero-cw-state').textContent = state;

			var summary = '<?php echo esc_js( __( 'No warm run yet.', 'aero' ) ); ?>';
			if (total > 0) {
				summary = d.counts.done + '/' + total + ' <?php echo esc_js( __( 'URLs warmed', 'aero' ) ); ?>' + (d.counts.failed ? ' · ' + d.counts.failed + ' <?php echo esc_js( __( 'failed', 'aero' ) ); ?>' : '');
			}
			document.getElementById('aero-cw-summary').textContent = summary;

			if (d.stats && d.stats.started) {
				var when = new Date(d.stats.started * 1000);
				document.getElementById('aero-cw-lastrun').textContent = (d.stats.reason || '') + ' · ' + when.toUTCString().replace(':00 GMT',' UTC');
			}

			document.getElementById('aero-cw-progress').style.display = total > 0 ? '' : 'none';
			document.getElementById('aero-cw-progress-fill').style.width = pct + '%';
			document.getElementById('aero-cw-counts').style.display = total > 0 ? '' : 'none';
			document.getElementById('aero-cw-n-done').textContent = d.counts.done;
			document.getElementById('aero-cw-n-warming').textContent = d.counts.warming;
			document.getElementById('aero-cw-n-pending').textContent = d.counts.pending;
			document.getElementById('aero-cw-n-failed').textContent = d.counts.failed;

			document.getElementById('aero-cw-start').disabled = running;
			document.getElementById('aero-cw-cancel').style.display = running ? '' : 'none';

			// Queue table: warming pinned on top, live filter, client pagination.
			allRows = d.rows || [];
			serverMore = d.more || 0;
			renderQueue();
			renderMicro(d.micro);
			renderEdge(d.edge);

		}

		// ── Queue view state (search + pagination survive live polls) ──
		var allRows = <?php echo wp_json_encode( $payload['rows'] ); ?> || [];
		var serverMore = <?php echo (int) $payload['more']; ?>;
		var queueQuery = '';
		var queuePage  = 1;
		var PER_PAGE   = 25;

		function renderQueue() {
			var q = queueQuery.toLowerCase();
			var filtered = q === '' ? allRows.slice() : allRows.filter(function(r) {
				return r.path.toLowerCase().indexOf(q) !== -1
					|| String(r.code).indexOf(q) !== -1
					|| r.variant.indexOf(q) !== -1
					|| r.status.indexOf(q) !== -1;
			});

			// Whatever is warming right now floats to the top; everything else
			// keeps its natural queue order (stable sort by original index).
			filtered.sort(function(a, b) {
				var aw = a.status === 'warming' ? 0 : 1;
				var bw = b.status === 'warming' ? 0 : 1;
				if (aw !== bw) { return aw - bw; }
				return allRows.indexOf(a) - allRows.indexOf(b);
			});

			var pages = Math.max(1, Math.ceil(filtered.length / PER_PAGE));
			if (queuePage > pages) { queuePage = pages; }
			var slice = filtered.slice((queuePage - 1) * PER_PAGE, queuePage * PER_PAGE);

			var hasAny = allRows.length > 0;
			document.getElementById('aero-cw-toolbar').style.display = hasAny ? '' : 'none';
			document.getElementById('aero-cw-empty').style.display = hasAny ? 'none' : '';
			document.getElementById('aero-cw-table').style.display = slice.length ? '' : 'none';

			var dots = { done: 'ok', warming: 'warn', pending: 'idle', failed: 'err', skipped: 'idle' };
			var body = '';
			slice.forEach(function(r) {
				body += '<tr' + (r.status === 'warming' ? ' class="aero-tr-warming"' : '') + '>'
					 +  '<td><span class="aero-dot ' + (dots[r.status] || 'idle') + '"></span></td>'
					 +  '<td class="aero-td-url" title="' + r.url + '">' + r.path + '</td>'
					 +  '<td><span class="aero-tag' + (r.variant === 'bot' ? '' : ' ok') + '">' + (r.variant === 'bot' ? 'BOT' : 'HUMAN') + '</span></td>'
					 +  '<td class="aero-td-mono">' + (r.code || '—') + '</td>'
					 +  '<td>' + aeroCacheBadge(r.bc, r.xac) + '</td>'
					 +  '<td class="aero-td-mono">' + (r.ms ? r.ms + 'ms' : '—') + '</td>'
					 +  '</tr>';
			});
			document.getElementById('aero-cw-tbody').innerHTML = body;

			// Pager + counts
			var info;
			if (filtered.length === 0) {
				info = q ? '<?php echo esc_js( __( 'No matches', 'aero' ) ); ?>' : '';
			} else {
				info = ((queuePage - 1) * PER_PAGE + 1) + '–' + Math.min(queuePage * PER_PAGE, filtered.length)
					 + ' <?php echo esc_js( __( 'of', 'aero' ) ); ?> ' + filtered.length
					 + (q ? ' (<?php echo esc_js( __( 'filtered', 'aero' ) ); ?>)' : '');
			}
			document.getElementById('aero-cw-pageinfo').textContent = info;
			document.getElementById('aero-cw-prev').disabled = queuePage <= 1;
			document.getElementById('aero-cw-next').disabled = queuePage >= pages;

			var moreEl = document.getElementById('aero-cw-more');
			moreEl.style.display = serverMore > 0 ? '' : 'none';
			if (serverMore > 0) { moreEl.textContent = '+ ' + serverMore + ' <?php echo esc_js( __( 'additional items not shown in the live view', 'aero' ) ); ?>'; }

			var table = document.getElementById('aero-cw-table');
			table.style.display = hasAny && slice.length ? '' : 'none';
			if (hasAny && !slice.length && q) {
				document.getElementById('aero-cw-empty').style.display = 'none';
			}
		}

		document.getElementById('aero-cw-search').addEventListener('input', function() {
			queueQuery = this.value.trim();
			queuePage = 1;
			renderQueue();
		});
		document.getElementById('aero-cw-prev').addEventListener('click', function() {
			if (queuePage > 1) { queuePage--; renderQueue(); }
		});
		document.getElementById('aero-cw-next').addEventListener('click', function() {
			queuePage++; renderQueue();
		});

		// Layered, source-aware cache badge. x-nananana is only live truth on
		// origin-sourced responses; when the Edge answers, IT is the state.
		function aeroCacheBadge(bc, xac) {
			if (xac === 'HIT') { return '<span class="aero-tag ok" title="<?php echo esc_attr( __( 'Served from the Edge Cache — the fastest tier. Batcache state is not readable from Edge responses (headers are the admitted snapshot), and does not matter while the Edge is serving.', 'aero' ) ); ?>">EDGE HIT</span>'; }
			if (xac === 'STALE' || xac === 'EXPIRED' || xac === 'UPDATING') { return '<span class="aero-tag warn" title="<?php echo esc_attr( __( 'Stale-while-revalidate in progress: this response was served from (stale) cache instantly while a fresh copy is built — subsequent requests get the fresh one. This is the Edge working as designed, not a failure.', 'aero' ) ); ?>">REFRESHING</span>'; }
			if (bc === 1) { return '<span class="aero-tag ok">BC HIT</span>'; }
			if (bc === 2) { return '<span class="aero-tag ok" title="<?php echo esc_attr( __( 'The verification request itself generated and stored this page (x-nananana: Batcache-set) — it is warm now; the next request is a HIT.', 'aero' ) ); ?>">BC SET</span>'; }
			if (bc === 0) { return '<span class="aero-tag warn" title="<?php echo esc_attr( __( 'An origin-sourced follow-up did NOT come back from Batcache — this page is not cached at origin (no-store, cookies, or an exclusion).', 'aero' ) ); ?>">NO HIT</span>'; }
			return '<span class="aero-td-mono" style="color:#5a5a5a;">—</span>';
		}

		var verifyTimer = null;
		document.getElementById('aero-cw-verify').addEventListener('click', function() {
			var btn = this;
			btn.disabled = true;
			btn.textContent = '<?php echo esc_js( __( 'Checking…', 'aero' ) ); ?>';
			jQuery.post(ajaxurl, { action: 'aero_cw_verify_refresh', nonce: nonce }, function(r) {
				if (r && r.success) {
					render(r.data);
					startVerifyCooldown(btn, 60);
				} else {
					var wait = (r && r.data && r.data.wait) || 0;
					if (wait > 0) { startVerifyCooldown(btn, wait); }
					else {
						btn.disabled = false;
						btn.textContent = '<?php echo esc_js( __( 'Refresh Cache Status', 'aero' ) ); ?>';
					}
				}
			});
		});
		function startVerifyCooldown(btn, secs) {
			if (verifyTimer) { clearInterval(verifyTimer); }
			var left = secs;
			btn.disabled = true;
			btn.textContent = '<?php echo esc_js( __( 'Refreshed', 'aero' ) ); ?> · ' + left + 's';
			verifyTimer = setInterval(function() {
				left--;
				if (left <= 0) {
					clearInterval(verifyTimer); verifyTimer = null;
					btn.disabled = false;
					btn.textContent = '<?php echo esc_js( __( 'Refresh Cache Status', 'aero' ) ); ?>';
				} else {
					btn.textContent = '<?php echo esc_js( __( 'Refreshed', 'aero' ) ); ?> · ' + left + 's';
				}
			}, 1000);
		}

		function renderEdge(edge) {
			if (!edge) { return; }
			var rows = edge.rows || [];
			document.getElementById('aero-edge-count').textContent = rows.length + '/' + (edge.slots || 10);
			document.getElementById('aero-edge-empty').style.display = rows.length ? 'none' : '';
			document.getElementById('aero-edge-list').style.display = rows.length ? '' : 'none';
			var xacMeta = {
				'HIT':      { cls: 'hit',   label: 'EDGE HIT' },
				'STALE':    { cls: 'mid',   label: 'STALE' },
				'EXPIRED':  { cls: 'mid',   label: 'EXPIRED' },
				'UPDATING': { cls: 'mid',   label: 'UPDATING' },
				'MISS':     { cls: 'miss',  label: 'MISS' },
				'BYPASS':   { cls: 'off',   label: 'BYPASS' }
			};
			var html = '';
			rows.forEach(function(r) {
				var m = xacMeta[r.xac] || { cls: 'unknown', label: '<?php echo esc_js( __( 'Not verified yet', 'aero' ) ); ?>' };
				html += '<div class="aero-edge-row">'
					 +  '<span class="aero-edge-chip ' + m.cls + '">' + m.label + '</span>'
					 +  '<span class="aero-edge-path" title="' + r.url + '">' + r.path + '</span>'
					 +  '<span class="aero-edge-meta" title="<?php echo esc_attr( __( 'Batcache indicator. Hidden on EDGE HIT: responses served from the Edge carry the admitted snapshot\'s headers, so the Batcache header is not meaningful there.', 'aero' ) ); ?>">' + (r.xac === 'HIT' ? '' : (r.bc === 1 || r.bc === 2 ? 'BC ✓' : (r.bc === 0 ? 'BC ✗' : ''))) + '</span>'
					 +  '<span class="aero-edge-meta">' + (r.ms ? r.ms + 'ms' : '') + '</span>'
					 +  '<span class="aero-edge-meta">' + (r.when || '') + '</span>'
					 +  '<button type="button" class="aero-edge-remove" data-url="' + r.url + '" aria-label="<?php echo esc_attr( __( 'Remove', 'aero' ) ); ?>">&times;</button>'
					 +  '</div>';
			});
			document.getElementById('aero-edge-list').innerHTML = html;
			document.querySelectorAll('.aero-edge-remove').forEach(function(btn) {
				btn.addEventListener('click', function() {
					jQuery.post(ajaxurl, { action: 'aero_cw_edge_remove', nonce: nonce, url: btn.getAttribute('data-url') }, function() { poll(); });
				});
			});
		}

		document.getElementById('aero-edge-add-btn').addEventListener('click', function() {
			var input = document.getElementById('aero-edge-add-input');
			var val = input.value.trim();
			if (!val) { return; }
			jQuery.post(ajaxurl, { action: 'aero_cw_edge_add', nonce: nonce, url: val }, function(r) {
				if (r && r.success) { input.value = ''; poll(); }
				else {
					var msg = (r && r.data && r.data.message) || '<?php echo esc_js( __( 'Could not add URL.', 'aero' ) ); ?>';
					window.aeroCmShowModal ? window.aeroCmShowModal(msg) : alert(msg);
				}
			});
		});
		document.getElementById('aero-edge-add-input').addEventListener('keydown', function(e) {
			if (e.key === 'Enter') { e.preventDefault(); document.getElementById('aero-edge-add-btn').click(); }
		});
		document.getElementById('aero-edge-warm').addEventListener('click', function() {
			var btn = this;
			btn.disabled = true;
			btn.textContent = '<?php echo esc_js( __( 'Warming…', 'aero' ) ); ?>';
			jQuery.post(ajaxurl, { action: 'aero_cw_edge_warm_now', nonce: nonce }, function(r) {
				setTimeout(function() {
					btn.disabled = false;
					btn.textContent = '<?php echo esc_js( __( 'Warm Edge Now', 'aero' ) ); ?>';
					poll();
				}, 2500);
				if (r && !r.success) {
					var msg = (r.data && r.data.message) || '';
					if (msg) { window.aeroCmShowModal ? window.aeroCmShowModal(msg) : alert(msg); }
				}
			});
		});

		function renderMicro(micro) {
			var rows = (micro && micro.rows) || [];
			document.getElementById('aero-micro-empty').style.display = rows.length ? 'none' : '';
			document.getElementById('aero-micro-table').style.display = rows.length ? '' : 'none';
			if (!rows.length) { return; }
			var dots = { done: 'ok', warming: 'warn', pending: 'idle', failed: 'err' };
			var labels = {
				pending: '<?php echo esc_js( __( 'Pending', 'aero' ) ); ?>',
				warming: '<?php echo esc_js( __( 'Warming…', 'aero' ) ); ?>'
			};
			var body = '';
			rows.forEach(function(r) {
				var cls = r.status === 'warming' ? ' class="aero-tr-warming"' : '';
				body += '<tr' + cls + '>'
					 +  '<td><span class="aero-dot ' + (dots[r.status] || 'idle') + (r.status === 'warming' ? ' pulse' : '') + '"></span></td>'
					 +  '<td class="aero-td-url" title="' + r.url + '">' + r.path + '</td>'
					 +  '<td class="aero-td-reason">' + (r.reason || '—') + '</td>'
					 +  '<td class="aero-td-mono">' + (r.vars ? (r.vars === 2 ? 'H+B' : 'H') : '—') + '</td>'
					 +  '<td class="aero-td-mono">' + (r.code || '—') + '</td>'
					 +  '<td>' + aeroCacheBadge(r.bc, r.xac) + '</td>'
					 +  '<td class="aero-td-mono">' + (r.ms ? r.ms + 'ms' : '—') + '</td>'
					 +  '<td class="aero-td-mono">' + (r.when || labels[r.status] || '') + '</td>'
					 +  '</tr>';
			});
			document.getElementById('aero-micro-tbody').innerHTML = body;
		}

		function poll() {
			jQuery.post(ajaxurl, { action: 'aero_cw_status', nonce: nonce }, function(r) {
				if (r && r.success) { render(r.data); }
			});
		}

		document.getElementById('aero-cw-start').addEventListener('click', function() {
			this.disabled = true;
			jQuery.post(ajaxurl, { action: 'aero_cw_start_now', nonce: nonce }, function(r) {
				if (r && r.success) { poll(); }
				else {
					window.aeroCmShowModal ? window.aeroCmShowModal((r.data && r.data.message) || 'Unable to start.') : alert((r.data && r.data.message) || 'Unable to start.');
					document.getElementById('aero-cw-start').disabled = false;
				}
			});
		});

		document.getElementById('aero-cw-cancel').addEventListener('click', function() {
			jQuery.post(ajaxurl, { action: 'aero_cw_cancel', nonce: nonce }, function() { poll(); });
		});

		// ── llms.txt: re-scan + on-demand preview with selection highlights ──
		var llmsOpen = false;

		function aeroEscHtml(t) {
			var d = document.createElement('div');
			d.appendChild(document.createTextNode(t));
			return d.innerHTML;
		}

		document.getElementById('aero-llms-rescan').addEventListener('click', function() {
			var btn = this;
			btn.disabled = true;
			btn.textContent = '<?php echo esc_js( __( 'Scanning…', 'aero' ) ); ?>';
			jQuery.post(ajaxurl, { action: 'aero_cw_llms_rescan', nonce: nonce }, function(r) {
				btn.disabled = false;
				btn.textContent = '<?php echo esc_js( __( 'Re-scan llms.txt', 'aero' ) ); ?>';
				if (!r || !r.success) { return; }
				var tag = document.getElementById('aero-llms-tag');
				var row = document.getElementById('aero-llms-row');
				var cb  = document.getElementById('aero-llms-checkbox');
				var pv  = document.getElementById('aero-llms-preview-btn');
				if (r.data.exists) {
					tag.className = 'aero-tag ok';
					tag.textContent = '<?php echo esc_js( __( 'Detected', 'aero' ) ); ?>';
					row.classList.remove('aero-check-row-muted');
					cb.disabled = false;
					// The disabled-state markup posts via a hidden field; once
					// live, the checkbox takes over so toggling it saves.
					cb.name = 'aero_cw[use_llms]';
					var hid = document.getElementById('aero-llms-hidden');
					if (hid) { hid.disabled = true; }
					pv.disabled = false;
				} else {
					tag.className = 'aero-tag';
					tag.textContent = '<?php echo esc_js( __( 'Not detected', 'aero' ) ); ?>';
					row.classList.add('aero-check-row-muted');
					cb.disabled = true;
					pv.disabled = true;
					if (llmsOpen) { closeLlms(); }
				}
				document.getElementById('aero-llms-checked').textContent = '<?php echo esc_js( __( 'Last checked just now', 'aero' ) ); ?>';
			});
		});

		function closeLlms() {
			llmsOpen = false;
			document.getElementById('aero-llms-preview').style.display = 'none';
			document.getElementById('aero-llms-preview-btn').textContent = '<?php echo esc_js( __( 'Preview llms.txt', 'aero' ) ); ?>';
		}

		document.getElementById('aero-llms-close').addEventListener('click', closeLlms);

		document.getElementById('aero-llms-preview-btn').addEventListener('click', function() {
			if (llmsOpen) { closeLlms(); return; }
			var btn = this;
			btn.disabled = true;
			btn.textContent = '<?php echo esc_js( __( 'Loading…', 'aero' ) ); ?>';
			jQuery.post(ajaxurl, { action: 'aero_cw_llms_preview', nonce: nonce }, function(r) {
				btn.disabled = false;
				if (!r || !r.success) {
					btn.textContent = '<?php echo esc_js( __( 'Preview llms.txt', 'aero' ) ); ?>';
					var msg = (r && r.data && r.data.message) || '<?php echo esc_js( __( 'Preview failed.', 'aero' ) ); ?>';
					window.aeroCmShowModal ? window.aeroCmShowModal(msg) : alert(msg);
					return;
				}
				// Tokenize URLs in the RAW text first (longest-first, so a URL
				// that is a prefix of another can never match inside it), then
				// escape, then expand tokens into highlight marks. Direct
				// replace-after-escape nests marks when one URL prefixes another.
				var raw  = r.data.content;
				var urls = (r.data.selected || []).slice().sort(function(a, b) { return b.length - a.length; });
				urls.forEach(function(u, i) {
					raw = raw.split(u).join('\u0001' + i + '\u0001');
				});
				var html = aeroEscHtml(raw);
				urls.forEach(function(u, i) {
					html = html.split('\u0001' + i + '\u0001')
							   .join('<mark class="aero-llms-hit">' + aeroEscHtml(u) + '</mark>');
				});
				document.getElementById('aero-llms-code').innerHTML = html;
				document.getElementById('aero-llms-summary').textContent =
					(r.data.selected || []).length + ' <?php echo esc_js( __( 'URLs selected for warming', 'aero' ) ); ?>'
					+ (r.data.skipped ? ' · ' + r.data.skipped + ' <?php echo esc_js( __( 'skipped (external or invalid)', 'aero' ) ); ?>' : '');
				document.getElementById('aero-llms-preview').style.display = '';
				btn.textContent = '<?php echo esc_js( __( 'Hide preview', 'aero' ) ); ?>';
				llmsOpen = true;
			});
		});

		render(<?php echo wp_json_encode( $payload ); ?>);
		// Continuous poll: surgical re-warms happen while no run is active
		// (a page edit in another tab), so the screen always stays live.
		polling = setInterval(poll, 4000);
	})();
	</script>
	<?php
}
