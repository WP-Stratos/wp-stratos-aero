<?php
/**
 * Aero — Delivery Optimization
 *
 * The three levers that move lab scores (PageSpeed/Lighthouse) rather than
 * TTFB. All run inside Aero's existing HTML output buffer, normal mode only.
 *
 *  1. Delay JavaScript — scripts don't load until the first user interaction
 *     (pointer, key, touch, scroll). Lab tools never interact, so delayed
 *     scripts contribute zero Total Blocking Time. Execution order is
 *     preserved by restoring scripts sequentially.
 *
 *  2. Async CSS — stylesheets load with media="print" and swap to media="all"
 *     onload, taking them off the render-blocking path. Pairs with the
 *     Critical CSS field, which is inlined in <head> to prevent a flash of
 *     unstyled content.
 *
 *  3. LCP Image Preload — a scoring heuristic picks the likely
 *     Largest-Contentful-Paint image from early <body> markup, preloads it
 *     with fetchpriority="high", strips loading="lazy" from it, and marks
 *     the tag itself high priority.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Options ──────────────────────────────────────────────────────────────────

function aero_delivery_on( $key ) {
	$defaults = array(
		'aero_delay_js'    => 'off',
		'aero_async_css'   => 'off',
		'aero_preload_lcp' => 'on',
	);
	$default = isset( $defaults[ $key ] ) ? $defaults[ $key ] : 'off';
	return get_option( $key, $default ) === 'on';
}

// ─── Pipeline entry ───────────────────────────────────────────────────────────

/**
 * Applied last in the normal-mode buffer, after minify/fonts, so it wraps
 * the final asset URLs.
 */
function aero_delivery_process_html( $html ) {
	if ( aero_delivery_on( 'aero_preload_lcp' ) ) {
		$html = aero_delivery_preload_lcp( $html );
	}
	if ( aero_delivery_on( 'aero_async_css' ) ) {
		$html = aero_delivery_async_css( $html );
	}
	if ( aero_delivery_on( 'aero_delay_js' ) ) {
		$html = aero_delivery_delay_js( $html );
	}
	return $html;
}

// ─── 1. Delay JavaScript ──────────────────────────────────────────────────────

function aero_delivery_delay_js( $html ) {
	$excl_raw = (string) get_option( 'aero_delay_js_excludes', '' );
	$excludes = array();
	foreach ( preg_split( '/[\r\n,]+/', $excl_raw ) as $t ) {
		$t = trim( $t );
		if ( '' !== $t ) {
			$excludes[] = strtolower( $t );
		}
	}

	$delayed = 0;

	$html = preg_replace_callback( '/<script\b([^>]*)>(.*?)<\/script>/is', function( $m ) use ( $excludes, &$delayed ) {
		$attrs  = $m[1];
		$inline = $m[2];

		// Only plain JavaScript gets delayed. JSON-LD, templates, modules,
		// importmaps and speculation rules pass through untouched.
		if ( preg_match( '/type\s*=\s*["\']([^"\']+)["\']/i', $attrs, $t ) ) {
			$type = strtolower( trim( $t[1] ) );
			if ( ! in_array( $type, array( 'text/javascript', 'application/javascript' ), true ) ) {
				return $m[0];
			}
		}

		// Explicit opt-out attribute for theme/plugin authors.
		if ( false !== stripos( $attrs, 'data-aero-nodelay' ) ) {
			return $m[0];
		}

		// User exclusions match against the src URL and inline contents.
		$haystack = strtolower( $attrs . ' ' . substr( $inline, 0, 2000 ) );
		foreach ( $excludes as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return $m[0];
			}
		}

		$delayed++;

		if ( preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/i', $attrs, $src ) ) {
			// External: park the src, neutralize the type.
			$new_attrs = preg_replace( '/\bsrc\s*=\s*(["\']).*?\1/i', 'data-aero-src=' . $src[1] . $src[2] . $src[1], $attrs );
			$new_attrs = preg_replace( '/\btype\s*=\s*(["\']).*?\1/i', '', $new_attrs );
			return '<script type="aero/delay"' . $new_attrs . '></script>';
		}

		// Inline: neutralize the type; contents kept verbatim.
		$new_attrs = preg_replace( '/\btype\s*=\s*(["\']).*?\1/i', '', $attrs );
		return '<script type="aero/delay"' . $new_attrs . '>' . $inline . '</script>';
	}, $html );

	if ( 0 === $delayed ) {
		return $html;
	}

	$timeout = (int) get_option( 'aero_delay_js_timeout', 0 );

	// Loader: on first interaction (or the optional timeout), restore every
	// delayed script sequentially in document order, preserving dependency
	// chains (jQuery before jQuery plugins before inline snippets).
	$loader = '<script data-aero-nodelay>(function(){var f=0;'
			. 'function go(){if(f)return;f=1;'
			. 'var e=["pointerdown","keydown","touchstart","wheel","mousemove","scroll"];'
			. 'e.forEach(function(v){window.removeEventListener(v,go,{passive:true})});'
			. 'var s=[].slice.call(document.querySelectorAll(\'script[type="aero/delay"]\'));'
			. '(function next(){if(!s.length)return;var o=s.shift(),n=document.createElement("script");'
			. 'for(var i=0;i<o.attributes.length;i++){var a=o.attributes[i];'
			. 'if(a.name==="type"||a.name==="data-aero-src")continue;n.setAttribute(a.name,a.value);}'
			. 'if(o.getAttribute("data-aero-src")){n.src=o.getAttribute("data-aero-src");'
			. 'n.onload=n.onerror=next;o.parentNode.replaceChild(n,o);}'
			. 'else{n.text=o.text;o.parentNode.replaceChild(n,o);next();}})();'
			. 'window.dispatchEvent(new Event("aero:delayed-js-loaded"));}'
			. 'var e=["pointerdown","keydown","touchstart","wheel","mousemove","scroll"];'
			. 'e.forEach(function(v){window.addEventListener(v,go,{passive:true})});'
			. ( $timeout > 0 ? 'setTimeout(go,' . ( $timeout * 1000 ) . ');' : '' )
			. '})();</script>';

	if ( false !== stripos( $html, '</body>' ) ) {
		$html = preg_replace( '/<\/body>/i', $loader . '</body>', $html, 1 );
	} else {
		$html .= $loader;
	}

	return $html;
}

// ─── 2. Async CSS ─────────────────────────────────────────────────────────────

function aero_delivery_async_css( $html ) {
	$excl_raw = (string) get_option( 'aero_async_css_excludes', '' );
	$excludes = array();
	foreach ( preg_split( '/[\r\n,]+/', $excl_raw ) as $t ) {
		$t = trim( $t );
		if ( '' !== $t ) {
			$excludes[] = strtolower( $t );
		}
	}

	$html = preg_replace_callback( '/<link\b[^>]*rel=["\']stylesheet["\'][^>]*>/i', function( $m ) use ( $excludes ) {
		$tag = $m[0];

		// Already async, print-only, or explicitly opted out.
		if ( preg_match( '/\bmedia\s*=\s*["\']print["\']/i', $tag ) || false !== stripos( $tag, 'data-aero-noasync' ) || false !== stripos( $tag, 'onload=' ) ) {
			return $tag;
		}

		$lower = strtolower( $tag );
		foreach ( $excludes as $needle ) {
			if ( false !== strpos( $lower, $needle ) ) {
				return $tag;
			}
		}

		// Preserve an existing media query: swap back to it onload.
		$target = 'all';
		if ( preg_match( '/\bmedia\s*=\s*["\']([^"\']+)["\']/i', $tag, $mm ) ) {
			$target = $mm[1];
			$async  = preg_replace( '/\bmedia\s*=\s*["\'][^"\']+["\']/i', 'media="print"', $tag );
		} else {
			$async = preg_replace( '/<link\b/i', '<link media="print"', $tag, 1 );
		}
		$async = preg_replace(
			'/>$/',
			' onload="this.media=\'' . esc_attr( $target ) . '\';this.onload=null;">',
			$async
		);

		return $async . '<noscript>' . $tag . '</noscript>';
	}, $html );

	// Critical CSS: inline it so the async swap never paints unstyled.
	$critical = trim( (string) get_option( 'aero_critical_css', '' ) );
	if ( '' !== $critical ) {
		$critical = str_ireplace( '</style', '', $critical );
		$html     = preg_replace( '/<head([^>]*)>/i', '<head$1><style id="aero-critical-css">' . $critical . '</style>', $html, 1 );
	}

	return $html;
}

// ─── 3. LCP image preload ─────────────────────────────────────────────────────

/**
 * Heuristic: among the first 8 <img> tags after <body>, score candidates —
 * penalize obvious chrome (logos, icons, avatars), reward size signals and
 * hero-ish naming — then preload the winner with fetchpriority="high" and
 * un-lazy it.
 */
function aero_delivery_preload_lcp( $html ) {
	$body_pos = stripos( $html, '<body' );
	if ( false === $body_pos ) {
		return $html;
	}
	$scan = substr( $html, $body_pos, 60000 );

	if ( ! preg_match_all( '/<img\b[^>]*>/i', $scan, $imgs ) ) {
		return $html;
	}

	$best       = null;
	$best_score = -100;

	foreach ( array_slice( $imgs[0], 0, 8 ) as $tag ) {
		if ( ! preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $srcm ) ) {
			continue;
		}
		$src = $srcm[1];
		if ( 0 === strpos( $src, 'data:' ) ) {
			continue;
		}

		$score = 0;
		$hay   = strtolower( $tag );

		// Chrome, not content.
		if ( preg_match( '/logo|icon|avatar|emoji|badge|sprite/', $hay ) ) {
			$score -= 5;
		}
		// Hero-ish signals.
		if ( preg_match( '/hero|banner|featured|cover|slide|背景|masthead/', $hay ) ) {
			$score += 3;
		}
		if ( preg_match( '/\bwidth\s*=\s*["\']?(\d+)/i', $tag, $w ) ) {
			$score += ( (int) $w[1] >= 500 ) ? 3 : ( ( (int) $w[1] >= 300 ) ? 1 : -2 );
		}
		if ( false !== strpos( $hay, 'srcset' ) ) {
			$score += 2;
		}
		if ( false !== strpos( $hay, '.svg' ) ) {
			$score -= 2;
		}

		if ( $score > $best_score ) {
			$best_score = $score;
			$best       = array( 'tag' => $tag, 'src' => $src );
		}
	}

	if ( null === $best || $best_score < 0 ) {
		return $html;
	}

	// Preload hint (with responsive attributes when present).
	$preload = '<link rel="preload" as="image" href="' . esc_url( $best['src'] ) . '" fetchpriority="high"';
	if ( preg_match( '/\bsrcset\s*=\s*["\']([^"\']+)["\']/i', $best['tag'], $ss ) ) {
		$preload .= ' imagesrcset="' . esc_attr( $ss[1] ) . '"';
		if ( preg_match( '/\bsizes\s*=\s*["\']([^"\']+)["\']/i', $best['tag'], $sz ) ) {
			$preload .= ' imagesizes="' . esc_attr( $sz[1] ) . '"';
		}
	}
	$preload .= '>';
	$html     = preg_replace( '/<head([^>]*)>/i', '<head$1>' . $preload, $html, 1 );

	// The tag itself: high priority, never lazy.
	$upgraded = $best['tag'];
	$upgraded = preg_replace( '/\sloading\s*=\s*["\']lazy["\']/i', '', $upgraded );
	if ( false === stripos( $upgraded, 'fetchpriority' ) ) {
		$upgraded = preg_replace( '/<img\b/i', '<img fetchpriority="high"', $upgraded, 1 );
	}
	if ( $upgraded !== $best['tag'] ) {
		$html = str_replace( $best['tag'], $upgraded, $html );
	}

	return $html;
}
