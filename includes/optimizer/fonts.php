<?php
/**
 * Aero — Font Optimization Engine
 *
 * Eliminates the classic web-font waterfall (render-blocking cross-origin
 * CSS → second-origin font download) that dominates PageSpeed reports.
 *
 * Features (each individually toggleable):
 *  1. Self-host Google Fonts — fetches the Google CSS server-side with a
 *     woff2 user agent, downloads every referenced font file into Aero's
 *     cache, rewrites @font-face URLs and serves everything same-origin.
 *  2. Inline the localized font CSS straight into <head>, removing the
 *     render-blocking stylesheet request entirely.
 *  3. Preconnect hints for any font origin that remains remote
 *     (fonts.gstatic.com, use.typekit.net, p.typekit.net).
 *  4. Preload the primary woff2 files (latin subset preferred) so text
 *     fonts download in parallel with CSS instead of after it.
 *  5. Disable Google Fonts entirely — strips every Google Fonts tag for
 *     system-font-stack sites.
 *
 * font-display: swap is enforced in every localized @font-face block, and
 * appended as a URL parameter to any Google stylesheet left remote.
 *
 * Runs inside Aero's existing HTML output buffer (normal mode only — Guest
 * Mode has its own font inlining). Localization happens at most once per
 * unique Google URL: results persist in the aero_fonts_map option, failures
 * back off for 12 hours, and a lock transient prevents thundering herds.
 * The cache is cleared alongside "Clear Minified Cache".
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'AERO_FONTS_CACHE_DIR' ) ) {
	define( 'AERO_FONTS_CACHE_DIR', AERO_CACHE_DIR . 'fonts/' );
}

// ─── Option helpers (safe features default ON) ───────────────────────────────

/**
 * Font sub-option with default. All safe features ship enabled; the only
 * destructive option (disable Google Fonts) defaults off.
 */
function aero_fonts_opt( $key ) {
	$defaults = array(
		'aero_fonts_local_google'   => 'on',
		'aero_fonts_inline_css'    => 'on',
		'aero_fonts_preconnect'    => 'on',
		'aero_fonts_preload'       => 'on',
		'aero_fonts_disable_google' => 'off',
	);
	$default = isset( $defaults[ $key ] ) ? $defaults[ $key ] : 'off';
	return get_option( $key, $default ) === 'on';
}

/** Master switch — the existing "Optimize Font Loading" toggle, now default ON. */
function aero_fonts_master_on() {
	return get_option( 'aero_optimize_fonts', 'on' ) === 'on';
}

// ─── HTML pipeline entry point ────────────────────────────────────────────────

/**
 * Process fonts in the buffered HTML. Called from aero_minify_html()
 * (normal mode) in place of the legacy aero_optimize_fonts().
 */
function aero_fonts_process_html( $html ) {
	// 5. Disable Google Fonts entirely — independent of the master switch,
	//    since it's a removal rather than an optimization.
	if ( aero_fonts_opt( 'aero_fonts_disable_google' ) ) {
		return aero_fonts_strip_google( $html );
	}

	if ( ! aero_fonts_master_on() ) {
		return $html;
	}

	$preloads       = array();
	$remote_google  = false;
	$google_sheets  = 0;

	// ── Google Fonts stylesheet links ──
	if ( preg_match_all( '/<link[^>]*?href=["\']([^"\']*fonts\.googleapis\.com\/css[^"\']*)["\'][^>]*>/i', $html, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $match ) {
			$tag = $match[0];

			// Only touch actual stylesheet tags — leave preloads/preconnects alone.
			if ( preg_match( '/rel=["\'](?:preconnect|dns-prefetch|preload)["\']/i', $tag ) ) {
				continue;
			}
			$google_sheets++;

			$url   = html_entity_decode( $match[1] );
			$entry = aero_fonts_opt( 'aero_fonts_local_google' ) ? aero_fonts_localize( $url ) : false;

			if ( $entry ) {
				// 1 & 2. Localized: swap in inline CSS or a same-origin link.
				if ( aero_fonts_opt( 'aero_fonts_inline_css' ) && '' !== $entry['inline'] ) {
					$replacement = '<style id="aero-fonts-' . esc_attr( $entry['key'] ) . '">' . $entry['inline'] . '</style>';
				} else {
					$replacement = '<link rel="stylesheet" id="aero-fonts-' . esc_attr( $entry['key'] ) . '" href="' . esc_url( $entry['css_url'] ) . '" media="all">';
				}
				$html = str_replace( $tag, $replacement, $html );

				if ( ! empty( $entry['preload'] ) ) {
					$preloads = array_merge( $preloads, $entry['preload'] );
				}
			} else {
				// Left remote (feature off, or fetch backing off): at minimum
				// enforce display=swap on the URL.
				$remote_google = true;
				if ( false === strpos( $url, 'display=' ) ) {
					$swapped = $url . ( false !== strpos( $url, '?' ) ? '&' : '?' ) . 'display=swap';
					$html    = str_replace( $tag, str_replace( $match[1], esc_url( $swapped ), $tag ), $html );
				}
			}
		}
	}

	// ── Detect Adobe Fonts (TypeKit) — kit CSS link OR JS embed ──
	$typekit_css = null;
	if ( preg_match( '/<link[^>]*?href=["\']([^"\']*use\.typekit\.net[^"\']*\.css[^"\']*)["\'][^>]*>/i', $html, $tk ) ) {
		$typekit_css = html_entity_decode( $tk[1] );
	}
	$typekit_present = ( null !== $typekit_css ) || ( false !== stripos( $html, 'use.typekit.net' ) );

	// Record what the frontend actually loads, for the backend status panel.
	$google_present = ( $google_sheets > 0 );
	aero_fonts_record_detection(
		$google_present ? ( $remote_google ? 'remote' : 'localized' ) : 'none',
		$typekit_present
	);

	// ── Build head hints — ONLY for providers actually present on this page.
	// A preconnect to an origin the page never contacts wastes a connection
	// slot and slows the real critical path down.
	$hints = '';

	if ( aero_fonts_opt( 'aero_fonts_preconnect' ) ) {
		if ( $remote_google ) {
			// Warm both Google origins in parallel (gstatic needs crossorigin).
			if ( false === stripos( $html, 'rel="preconnect" href="https://fonts.googleapis.com' ) &&
				 false === stripos( $html, "rel='preconnect' href='https://fonts.googleapis.com" ) ) {
				$hints .= '<link rel="preconnect" href="https://fonts.googleapis.com">';
			}
			if ( false === stripos( $html, 'href="https://fonts.gstatic.com"' ) &&
				 false === stripos( $html, "href='https://fonts.gstatic.com'" ) ) {
				$hints .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
			}
		}

		// Adobe Fonts (TypeKit): the kit CSS can't be rewritten (dynamic,
		// licensed), so warm its origins and pull the kit CSS forward —
		// but only when the kit is genuinely on this page (link or JS embed).
		if ( $typekit_present ) {
			if ( ! preg_match( '/<link[^>]*rel=["\']preconnect["\'][^>]*use\.typekit\.net/i', $html ) &&
				 ! preg_match( '/<link[^>]*use\.typekit\.net[^>]*rel=["\']preconnect["\']/i', $html ) ) {
				$hints .= '<link rel="preconnect" href="https://use.typekit.net" crossorigin>';
			}
			if ( ! preg_match( '/<link[^>]*p\.typekit\.net[^>]*>/i', $html ) ) {
				$hints .= '<link rel="preconnect" href="https://p.typekit.net" crossorigin>';
			}
			if ( null !== $typekit_css &&
				 aero_fonts_opt( 'aero_fonts_preload' ) &&
				 ! preg_match( '/rel=["\']preload["\'][^>]*use\.typekit\.net/i', $html ) ) {
				$hints .= '<link rel="preload" href="' . esc_url( $typekit_css ) . '" as="style">';
			}
		}
	}

	// 4. Preload primary woff2 files (localized fonts only — same-origin).
	if ( aero_fonts_opt( 'aero_fonts_preload' ) && ! empty( $preloads ) ) {
		foreach ( array_slice( array_unique( $preloads ), 0, 2 ) as $font_url ) {
			$hints .= '<link rel="preload" href="' . esc_url( $font_url ) . '" as="font" type="font/woff2" crossorigin>';
		}
	}

	// When Google Fonts were fully localized, remote Google preconnects that
	// themes commonly hardcode are dead weight — remove them.
	if ( ! $remote_google ) {
		$html = preg_replace( '/<link[^>]*?rel=["\'](?:preconnect|dns-prefetch)["\'][^>]*?(?:fonts\.googleapis\.com|fonts\.gstatic\.com)[^>]*?>\s*/i', '', $html );
		$html = preg_replace( '/<link[^>]*?(?:fonts\.googleapis\.com|fonts\.gstatic\.com)[^>]*?rel=["\'](?:preconnect|dns-prefetch)["\'][^>]*?>\s*/i', '', $html );
	}

	if ( '' !== $hints ) {
		$html = preg_replace( '/<head([^>]*)>/i', '<head$1>' . $hints, $html, 1 );
	}

	return $html;
}

/**
 * Strip every Google Fonts reference: stylesheet links, preconnects,
 * dns-prefetch hints and @import rules.
 */
function aero_fonts_strip_google( $html ) {
	$html = preg_replace( '/<link[^>]*?(?:fonts\.googleapis\.com|fonts\.gstatic\.com)[^>]*?>\s*/i', '', $html );
	$html = preg_replace( '/@import\s+(?:url\()?["\']?https?:\/\/fonts\.googleapis\.com[^;]+;/i', '', $html );
	return $html;
}

// ─── Localization core ────────────────────────────────────────────────────────

/**
 * Localize a single Google Fonts CSS URL. Returns the map entry
 * (key, css_url, inline, preload[], files, bytes) or false.
 *
 * Idempotent and stampede-safe: results persist in aero_fonts_map,
 * failures back off 12h, a 30s lock prevents concurrent fetches.
 */
function aero_fonts_localize( $url ) {
	$key = substr( md5( $url ), 0, 12 );
	$map = get_option( 'aero_fonts_map', array() );

	if ( isset( $map[ $key ] ) && file_exists( AERO_FONTS_CACHE_DIR . $key . '.css' ) ) {
		return $map[ $key ];
	}

	if ( get_transient( 'aero_fonts_fail_' . $key ) ) {
		return false;
	}
	// Lock: first request does the work, concurrent ones serve remote once.
	if ( false === add_option( 'aero_fonts_lock_' . $key, time(), '', 'no' ) ) {
		$lock = (int) get_option( 'aero_fonts_lock_' . $key );
		if ( $lock && ( time() - $lock ) < 30 ) {
			return false;
		}
		update_option( 'aero_fonts_lock_' . $key, time(), 'no' );
	}

	$entry = aero_fonts_do_localize( $url, $key );

	delete_option( 'aero_fonts_lock_' . $key );

	if ( false === $entry ) {
		set_transient( 'aero_fonts_fail_' . $key, 1, 12 * HOUR_IN_SECONDS );
		return false;
	}

	$map[ $key ] = $entry;
	update_option( 'aero_fonts_map', $map );
	return $entry;
}

/**
 * The actual fetch → download → rewrite work.
 */
function aero_fonts_do_localize( $url, $key ) {
	if ( ! is_dir( AERO_FONTS_CACHE_DIR ) && ! wp_mkdir_p( AERO_FONTS_CACHE_DIR ) ) {
		return false;
	}

	// Ask as a modern browser so Google returns woff2 @font-face with
	// unicode-range subsets.
	$response = wp_remote_get( $url, array(
		'timeout'    => 6,
		'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
	) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return false;
	}
	$css = wp_remote_retrieve_body( $response );
	if ( '' === trim( $css ) || false === strpos( $css, '@font-face' ) ) {
		return false;
	}

	// Download every referenced font file.
	if ( ! preg_match_all( '/url\((https:\/\/fonts\.gstatic\.com\/[^)]+)\)/i', $css, $fm ) ) {
		return false;
	}
	$font_urls   = array_unique( $fm[1] );
	$local_names = array();
	$bytes       = 0;

	foreach ( $font_urls as $font_url ) {
		$ext = strtolower( pathinfo( wp_parse_url( $font_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'woff2', 'woff', 'ttf', 'otf', 'eot', 'svg' ), true ) ) {
			$ext = 'woff2';
		}
		$name = $key . '-' . substr( md5( $font_url ), 0, 10 ) . '.' . $ext;
		$path = AERO_FONTS_CACHE_DIR . $name;

		if ( ! file_exists( $path ) ) {
			$font_resp = wp_remote_get( $font_url, array( 'timeout' => 10 ) );
			if ( is_wp_error( $font_resp ) || 200 !== wp_remote_retrieve_response_code( $font_resp ) ) {
				return false; // abort whole set: never ship half-broken @font-face
			}
			$body = wp_remote_retrieve_body( $font_resp );
			if ( '' === $body || false === file_put_contents( $path, $body ) ) {
				return false;
			}
		}
		$bytes += (int) filesize( $path );
		$local_names[ $font_url ] = $name;
	}

	// Rewrite URLs to same-origin.
	$fonts_url = content_url() . '/cache/aero/fonts/';
	foreach ( $local_names as $remote => $name ) {
		$css = str_replace( $remote, $fonts_url . $name, $css );
	}

	// Enforce font-display: swap in every @font-face block.
	$css = preg_replace_callback( '/@font-face\s*\{[^}]*\}/i', function( $block ) {
		if ( false !== stripos( $block[0], 'font-display' ) ) {
			return preg_replace( '/font-display\s*:\s*[a-z]+/i', 'font-display:swap', $block[0] );
		}
		return preg_replace( '/@font-face\s*\{/i', '@font-face{font-display:swap;', $block[0], 1 );
	}, $css );

	// Minify lightly: strip comments + collapse whitespace.
	$inline = trim( preg_replace( '/\s+/', ' ', preg_replace( '/\/\*.*?\*\//s', '', $css ) ) );
	$inline = str_ireplace( '</style', '', $inline ); // defense-in-depth for inline <style> output

	if ( false === file_put_contents( AERO_FONTS_CACHE_DIR . $key . '.css', $inline ) ) {
		return false;
	}

	return array(
		'key'     => $key,
		'source'  => $url,
		'css_url' => $fonts_url . $key . '.css',
		'inline'  => $inline,
		'preload' => aero_fonts_pick_preloads( $css, $fonts_url ),
		'files'   => count( $local_names ),
		'bytes'   => $bytes,
	);
}

/**
 * Pick up to two woff2 files worth preloading: latin-subset text faces
 * first (unicode-range containing U+0000), then any woff2.
 */
function aero_fonts_pick_preloads( $css, $fonts_url ) {
	$latin = array();
	$any   = array();

	if ( preg_match_all( '/@font-face\s*\{[^}]*\}/i', $css, $blocks ) ) {
		foreach ( $blocks[0] as $block ) {
			if ( ! preg_match( '/url\((' . preg_quote( $fonts_url, '/' ) . '[^)]+\.woff2)\)/i', $block, $u ) ) {
				continue;
			}
			if ( preg_match( '/unicode-range\s*:[^;]*U\+0000/i', $block ) ) {
				$latin[] = $u[1];
			} else {
				$any[] = $u[1];
			}
		}
	}

	return array_slice( array_unique( array_merge( $latin, $any ) ), 0, 2 );
}

// ─── Frontend provider detection (backend status panel) ──────────────────────

/**
 * Record which font providers the frontend actually loads and how they were
 * handled. Written at most when the state changes or the record is >6h old,
 * so cached/anonymous traffic causes no extra DB writes.
 *
 * @param string $google_mode  'localized' | 'remote' | 'none'
 * @param bool   $typekit      Kit CSS link or JS embed present.
 */
function aero_fonts_record_detection( $google_mode, $typekit ) {
	$detected = get_option( 'aero_fonts_detected', array() );
	$now      = time();

	$new = array(
		'google'  => array( 'mode' => $google_mode, 'seen' => $now ),
		'typekit' => array( 'mode' => $typekit ? 'detected' : 'none', 'seen' => $now ),
	);

	$stale   = empty( $detected['google']['seen'] ) || ( $now - (int) $detected['google']['seen'] ) > 6 * HOUR_IN_SECONDS;
	$changed = ( ! isset( $detected['google']['mode'] ) || $detected['google']['mode'] !== $google_mode )
			|| ( ! isset( $detected['typekit']['mode'] ) || $detected['typekit']['mode'] !== $new['typekit']['mode'] );

	if ( $changed || $stale ) {
		update_option( 'aero_fonts_detected', $new );
	}
}

/**
 * Last recorded provider detection, for the settings UI.
 */
function aero_fonts_get_detection() {
	$d = get_option( 'aero_fonts_detected', array() );
	return wp_parse_args( is_array( $d ) ? $d : array(), array(
		'google'  => array( 'mode' => 'unknown', 'seen' => 0 ),
		'typekit' => array( 'mode' => 'unknown', 'seen' => 0 ),
	) );
}

// ─── Stats for the settings UI / debug report ─────────────────────────────────

/**
 * Summary of the localized font cache: families, files, bytes.
 */
function aero_fonts_cache_stats() {
	$map   = get_option( 'aero_fonts_map', array() );
	$files = 0;
	$bytes = 0;
	foreach ( $map as $entry ) {
		$files += isset( $entry['files'] ) ? (int) $entry['files'] : 0;
		$bytes += isset( $entry['bytes'] ) ? (int) $entry['bytes'] : 0;
	}
	return array(
		'sheets' => count( $map ),
		'files'  => $files,
		'bytes'  => $bytes,
	);
}

// ─── Cache lifecycle ──────────────────────────────────────────────────────────

/**
 * Wipe the localized font cache (files + map + backoff transients).
 * Hooked to "Clear Minified Cache" so one button refreshes everything.
 */
function aero_fonts_clear_cache() {
	if ( is_dir( AERO_FONTS_CACHE_DIR ) ) {
		foreach ( (array) glob( AERO_FONTS_CACHE_DIR . '*' ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}
	$map = get_option( 'aero_fonts_map', array() );
	foreach ( array_keys( $map ) as $key ) {
		delete_transient( 'aero_fonts_fail_' . $key );
		delete_option( 'aero_fonts_lock_' . $key );
	}
	delete_option( 'aero_fonts_map' );
}
add_action( 'aero_minified_cache_cleared', 'aero_fonts_clear_cache' );
