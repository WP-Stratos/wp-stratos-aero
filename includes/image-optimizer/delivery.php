<?php
/**
 * Aero Image Optimizer — next-gen delivery for everything that is not an <img>.
 *
 * The engine's picture pass only rewrites <img> tags. Real sites put a large
 * share of their imagery in CSS: hero sections, section overlays, and page
 * builder backgrounds (Elementor, Divi, WPBakery, Beaver Builder, Bricks,
 * Oxygen). On Apache the .htaccess rewrite covers those at the HTTP layer;
 * on Nginx, where Aero normally lives, nothing did.
 *
 * Three surfaces are handled here, all in picture (PHP) mode:
 *
 *   1. Inline style attributes and <style> blocks — every url() token inside
 *      a background declaration is wrapped in image-set(), preserving
 *      gradients and multi-layer stacks.
 *   2. Lazy-load data attributes (WP Rocket, Elementor, Smush, Jetpack and
 *      the common data-bg family) — companion attributes are emitted and a
 *      tiny capability-detecting shim swaps them client-side.
 *   3. Local stylesheet files, including the CSS page builders generate into
 *      the uploads folder — processed once into a cached copy.
 *
 * Everything here is capability-detected in the browser, never from the
 * Accept header, so the HTML and CSS a visitor receives are byte-identical
 * for everyone. That is what keeps Batcache and Edge Cache correct: neither
 * varies on Accept, and a header-sniffing implementation would poison them.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Map a wp-content image URL to the derivatives that exist on disk.
 *
 * @param string $url Absolute or root-relative image URL.
 * @return array|false ['webp' => url|null, 'avif' => url|null] or false.
 */
function aero_io_background_derivatives( $url ) {
	static $cache = array();

	if ( isset( $cache[ $url ] ) ) {
		return $cache[ $url ];
	}
	// Bound the per-request cache; a page with hundreds of distinct
	// backgrounds should not turn into hundreds of stat() calls.
	if ( count( $cache ) > 400 ) {
		return false;
	}

	$clean = strtok( $url, '?#' );
	if ( ! preg_match( '/\.(png|jpe?g)$/i', $clean ) ) {
		$cache[ $url ] = false;
		return false;
	}

	$content_url  = content_url();
	$content_path = wp_parse_url( $content_url, PHP_URL_PATH );

	if ( 0 === stripos( $clean, $content_url ) ) {
		$rel = substr( $clean, strlen( $content_url ) );
	} elseif ( 0 === stripos( $clean, '//' ) && false !== strpos( $clean, '/wp-content/' ) ) {
		// Protocol-relative URL on this host.
		$host = wp_parse_url( $content_url, PHP_URL_HOST );
		if ( ! $host || false === stripos( $clean, '//' . $host . '/' ) ) {
			$cache[ $url ] = false;
			return false;
		}
		$rel = substr( $clean, strpos( $clean, $content_path ) + strlen( $content_path ) );
	} elseif ( $content_path && 0 === strpos( $clean, $content_path . '/' ) ) {
		// Root-relative URL (/wp-content/…).
		$rel = substr( $clean, strlen( $content_path ) );
	} else {
		$cache[ $url ] = false;
		return false;
	}

	if ( '' === $rel || false !== strpos( $rel, '..' ) || 0 === strpos( $rel, '/aero-nextgen/' ) ) {
		$cache[ $url ] = false;
		return false;
	}

	$out  = array(
		'webp' => null,
		'avif' => null,
	);
	$base = WP_CONTENT_DIR . '/aero-nextgen' . $rel;

	if ( file_exists( $base . '.avif' ) ) {
		$out['avif'] = $content_url . '/aero-nextgen' . $rel . '.avif';
	}
	if ( file_exists( $base . '.webp' ) ) {
		$out['webp'] = $content_url . '/aero-nextgen' . $rel . '.webp';
	}

	if ( ! $out['webp'] && ! $out['avif'] ) {
		$cache[ $url ] = false;
		return false;
	}

	$cache[ $url ] = $out;
	return $out;
}

/**
 * Build an image-set() value for one URL, falling back to the original.
 *
 * @param string $url Original URL as written in the CSS.
 * @param array  $deriv Derivative map.
 * @param string $q Quote character safe in this context.
 * @return string
 */
function aero_io_build_image_set( $url, $deriv, $q ) {
	$sources = array();
	if ( $deriv['avif'] ) {
		$sources[] = 'url(' . $q . $deriv['avif'] . $q . ') type(' . $q . 'image/avif' . $q . ')';
	}
	if ( $deriv['webp'] ) {
		$sources[] = 'url(' . $q . $deriv['webp'] . $q . ') type(' . $q . 'image/webp' . $q . ')';
	}
	$sources[] = 'url(' . $q . $url . $q . ')';

	return 'image-set(' . implode( ',', $sources ) . ')';
}

/**
 * Augment background declarations inside a CSS fragment.
 *
 * Each url() token in a background value is replaced by an image-set(), and
 * the rewritten value is appended as a duplicate of the SAME property. A
 * browser that understands image-set() with type() applies the duplicate; one
 * that does not drops it entirely and keeps the original declaration. Because
 * only the url() tokens change, gradients, overlays, positions and multi-layer
 * stacks all survive untouched — which is exactly what page builder sections
 * (Elementor overlays in particular) are built from.
 *
 * @param string $css CSS fragment.
 * @param string $q   Quote character safe inside this context.
 * @return string
 */
function aero_io_augment_css_backgrounds( $css, $q ) {
	return preg_replace_callback(
		'/(background(?:-image)?)(\s*:\s*)([^;{}]*url\([^;{}]*)/i',
		function ( $m ) use ( $q ) {
			$prop  = $m[1];
			$value = $m[3];

			// Leave anything carrying a data URI or an existing image-set alone.
			if ( false !== stripos( $value, 'data:' ) || false !== stripos( $value, 'image-set' ) ) {
				return $m[0];
			}

			$changed  = false;
			$new_value = preg_replace_callback(
				'/url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i',
				function ( $u ) use ( $q, &$changed ) {
					$deriv = aero_io_background_derivatives( $u[1] );
					if ( ! $deriv ) {
						return $u[0];
					}
					$changed = true;
					return aero_io_build_image_set( $u[1], $deriv, $q );
				},
				$value
			);

			if ( ! $changed ) {
				return $m[0];
			}

			return $m[0] . ';' . $prop . ':' . $new_value;
		},
		$css
	);
}

/**
 * Lazy-load attributes that hold a bare background image URL.
 *
 * These are set into element.style by the plugin's own JavaScript, so a
 * server-side image-set() never reaches them. Aero emits companion
 * attributes instead and swaps them in the browser once support is known.
 */
function aero_io_lazy_bg_attributes() {
	return apply_filters(
		'aero_io_lazy_bg_attributes',
		array(
			'data-bg',
			'data-bg-image',
			'data-background',
			'data-background-image',
			'data-bg-url',
			'data-lazy-bg',
			'data-lazy-background',
			'data-ll-background',
			'data-src-bg',
		)
	);
}

/**
 * Track whether the request emitted any companion attributes.
 *
 * @param bool|null $set Pass true to flag; null to read.
 * @return bool
 */
function aero_io_shim_needed( $set = null ) {
	static $needed = false;
	if ( true === $set ) {
		$needed = true;
	}
	return $needed;
}

/**
 * Add data-aero-webp / data-aero-avif next to lazy background attributes.
 *
 * @param string $html Full document.
 * @return string
 */
function aero_io_rewrite_lazy_backgrounds( $html ) {
	$attrs = aero_io_lazy_bg_attributes();
	if ( empty( $attrs ) ) {
		return $html;
	}

	$pattern = '/(\s(?:' . implode( '|', array_map( 'preg_quote', $attrs ) ) . ')\s*=\s*)(["\'])(.*?)\2/is';

	return preg_replace_callback(
		$pattern,
		function ( $m ) {
			$value = trim( $m[3] );

			// Some plugins store url(...) in the attribute, others a bare URL.
			$raw = $value;
			if ( preg_match( '/url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i', $value, $inner ) ) {
				$raw = $inner[1];
			}

			$deriv = aero_io_background_derivatives( $raw );
			if ( ! $deriv ) {
				return $m[0];
			}

			$extra = '';
			if ( $deriv['avif'] ) {
				$extra .= ' data-aero-avif="' . esc_attr( $deriv['avif'] ) . '"';
			}
			if ( $deriv['webp'] ) {
				$extra .= ' data-aero-webp="' . esc_attr( $deriv['webp'] ) . '"';
			}

			aero_io_shim_needed( true );

			return $m[0] . $extra;
		},
		$html
	);
}

/**
 * The client shim: detect format support once, then rewrite the lazy
 * attributes that Aero tagged before the lazy-loader reads them. Injected
 * only when at least one companion attribute was emitted, so pages without
 * lazy backgrounds carry no extra bytes.
 *
 * @return string
 */
function aero_io_delivery_shim() {
	$attrs = wp_json_encode( array_values( aero_io_lazy_bg_attributes() ) );

	$js = <<<JS
(function(){
var A=$attrs;
function swap(fmt){
	var els=document.querySelectorAll('[data-aero-'+fmt+']');
	for(var i=0;i<els.length;i++){
		var el=els[i],next=el.getAttribute('data-aero-'+fmt);
		if(!next){continue;}
		for(var j=0;j<A.length;j++){
			var cur=el.getAttribute(A[j]);
			if(cur===null){continue;}
			el.setAttribute(A[j],cur.indexOf('url(')===-1?next:cur.replace(/url\\(\\s*['"]?[^'")\\s]+['"]?\\s*\\)/i,'url("'+next+'")'));
		}
	}
}
function test(fmt,data,cb){
	var img=new Image();
	img.onload=function(){cb(img.width>0&&img.height>0);};
	img.onerror=function(){cb(false);};
	img.src='data:image/'+fmt+';base64,'+data;
}
test('avif','AAAAIGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZk1BMUIAAADybWV0YQAAAAAAAAAoaGRscgAAAAAAAAAAcGljdAAAAAAAAAAAAAAAAGxpYmF2aWYAAAAADnBpdG0AAAAAAAEAAAAeaWxvYwAAAABEAAABAAEAAAABAAABGgAAAB0AAAAoaWluZgAAAAAAAQAAABppbmZlAgAAAAABAABhdjAxQ29sb3IAAAAAamlwcnAAAABLaXBjbwAAABRpc3BlAAAAAAAAAAIAAAACAAAAEHBpeGkAAAAAAwgICAAAAAxhdjFDgQ0MAAAAABNjb2xybmNseAACAAIAAYAAAAAXaXBtYQAAAAAAAAABAAEEAQKDBAAAACVtZGF0EgAKCBgANogQEAwgMg8f8D///8WfhwB8+ErK42A=',function(ok){
	if(ok){swap('avif');return;}
	test('webp','UklGRh4AAABXRUJQVlA4TBEAAAAvAAAAAAfQ//73v/+BiOh/AAA=',function(ok2){if(ok2){swap('webp');}});
});
})();
JS;

	return '<script id="aero-io-delivery">' . $js . '</script>';
}

/**
 * Main filter on the picture-mode output buffer.
 *
 * @param string $html Document HTML.
 * @return string
 */
function aero_io_rewrite_css_backgrounds( $html ) {
	if ( ! is_string( $html ) || '' === $html ) {
		return $html;
	}

	if ( false !== stripos( $html, 'background' ) ) {
		// Inline style attributes.
		$html = preg_replace_callback(
			'/(\sstyle\s*=\s*)(["\'])(.*?)\2/is',
			function ( $m ) {
				if ( false === stripos( $m[3], 'url(' ) || false === stripos( $m[3], 'background' ) ) {
					return $m[0];
				}
				$q = ( '"' === $m[2] ) ? "'" : '"';
				return $m[1] . $m[2] . aero_io_augment_css_backgrounds( $m[3], $q ) . $m[2];
			},
			$html
		);

		// <style> blocks: theme customizer output, page builder widget CSS.
		$html = preg_replace_callback(
			'/(<style\b[^>]*>)(.*?)(<\/style>)/is',
			function ( $m ) {
				if ( false === stripos( $m[2], 'url(' ) || false === stripos( $m[2], 'background' ) ) {
					return $m[0];
				}
				return $m[1] . aero_io_augment_css_backgrounds( $m[2], '"' ) . $m[3];
			},
			$html
		);
	}

	// Lazy-load background attributes.
	if ( get_option( 'aero_io_lazy_bg', '1' ) === '1' && false !== stripos( $html, 'data-' ) ) {
		$html = aero_io_rewrite_lazy_backgrounds( $html );
	}

	if ( aero_io_shim_needed() && false !== stripos( $html, '</body>' ) ) {
		$html = preg_replace( '/<\/body>/i', aero_io_delivery_shim() . '</body>', $html, 1 );
	}

	return $html;
}
add_filter( 'aero_io_picture_content', 'aero_io_rewrite_css_backgrounds' );

// ─── Stylesheet file processing ──────────────────────────────────────────────
// Page builders write their section CSS to real files: Elementor to
// uploads/elementor/css, Divi to et-cache, WPBakery and Bricks to their own
// folders. Those files never pass through the output buffer, so their
// backgrounds keep serving originals. Each local stylesheet is processed once
// into wp-content/aero-nextgen/css/, with relative url() paths made absolute
// first (the copy lives in a different folder) and background urls wrapped in
// image-set(). The processed file is keyed by source mtime, so any rebuild by
// the builder produces a new key and the stale copy is simply never requested.

/**
 * Absolute filesystem path for a local URL under wp-content, or false.
 *
 * @param string $url Stylesheet URL.
 * @return string|false
 */
function aero_io_local_css_path( $url ) {
	$clean = strtok( $url, '?#' );

	$content_url  = content_url();
	$content_path = wp_parse_url( $content_url, PHP_URL_PATH );

	if ( 0 === stripos( $clean, $content_url ) ) {
		$rel = substr( $clean, strlen( $content_url ) );
	} elseif ( $content_path && 0 === strpos( $clean, $content_path . '/' ) ) {
		$rel = substr( $clean, strlen( $content_path ) );
	} else {
		return false;
	}

	if ( false !== strpos( $rel, '..' ) || 0 === strpos( $rel, '/aero-nextgen/' ) ) {
		return false;
	}

	$path = WP_CONTENT_DIR . $rel;

	return ( file_exists( $path ) && is_readable( $path ) ) ? $path : false;
}

/**
 * Resolve url() and @import targets against the original stylesheet folder,
 * then augment backgrounds. Fonts and other assets are only made absolute,
 * never rewritten — they simply have to keep resolving from the new location.
 *
 * @param string $css      Stylesheet contents.
 * @param string $base_url Directory URL of the ORIGINAL stylesheet.
 * @return string
 */
function aero_io_process_stylesheet( $css, $base_url ) {
	$base_url = trailingslashit( $base_url );

	$css = preg_replace_callback(
		'/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
		function ( $m ) use ( $base_url ) {
			$url = trim( $m[2] );

			if ( '' === $url
				|| 0 === stripos( $url, 'data:' )
				|| 0 === stripos( $url, 'http://' )
				|| 0 === stripos( $url, 'https://' )
				|| 0 === strpos( $url, '//' )
				|| 0 === strpos( $url, '/' )
				|| 0 === strpos( $url, '#' ) ) {
				return $m[0];
			}

			// Collapse ../ segments against the source directory.
			$absolute = $base_url . $url;
			$scheme   = '';
			if ( preg_match( '#^(https?://[^/]+)(/.*)$#i', $absolute, $parts ) ) {
				$scheme   = $parts[1];
				$absolute = $parts[2];
			}
			$segments = array();
			foreach ( explode( '/', $absolute ) as $segment ) {
				if ( '.' === $segment || '' === $segment ) {
					continue;
				}
				if ( '..' === $segment ) {
					array_pop( $segments );
					continue;
				}
				$segments[] = $segment;
			}

			return 'url("' . $scheme . '/' . implode( '/', $segments ) . '")';
		},
		$css
	);

	return aero_io_augment_css_backgrounds( $css, '"' );
}

/**
 * Swap enqueued stylesheet URLs for processed copies.
 *
 * @param string $src    Stylesheet URL.
 * @param string $handle Handle name.
 * @return string
 */
function aero_io_filter_style_src( $src, $handle ) {
	static $generated = 0;

	if ( is_admin() || empty( $src ) ) {
		return $src;
	}

	$path = aero_io_local_css_path( $src );
	if ( ! $path ) {
		return $src;
	}

	$size = filesize( $path );
	if ( $size < 32 || $size > 2097152 ) {
		return $src;
	}

	$mtime = filemtime( $path );
	$key   = md5( $path . '|' . $mtime . '|' . AERO_PLUGIN_VERSION_NUM );
	$file  = WP_CONTENT_DIR . '/aero-nextgen/css/' . $key . '.css';

	if ( file_exists( $file ) ) {
		// A zero-byte marker means "nothing to rewrite in this file".
		if ( 0 === filesize( $file ) ) {
			return $src;
		}
		return content_url() . '/aero-nextgen/css/' . $key . '.css?ver=' . $mtime;
	}

	// Cap generation per request so the first uncached page view does not
	// stall on a theme with dozens of stylesheets.
	if ( $generated >= 4 ) {
		return $src;
	}

	$css = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false === $css || false === stripos( $css, 'url(' ) || false === stripos( $css, 'background' ) ) {
		aero_io_write_css_cache( $file, '' );
		return $src;
	}

	$base_url  = trailingslashit( dirname( strtok( $src, '?#' ) ) );
	$processed = aero_io_process_stylesheet( $css, $base_url );

	if ( false === stripos( $processed, 'image-set(' ) ) {
		// No derivative existed for anything in this file.
		aero_io_write_css_cache( $file, '' );
		return $src;
	}

	if ( ! aero_io_write_css_cache( $file, $processed ) ) {
		return $src;
	}
	$generated++;

	return content_url() . '/aero-nextgen/css/' . $key . '.css?ver=' . $mtime;
}

/**
 * Write a processed stylesheet (or an empty "nothing to do" marker).
 *
 * @param string $file     Target path.
 * @param string $contents Contents.
 * @return bool
 */
function aero_io_write_css_cache( $file, $contents ) {
	$dir = dirname( $file );
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return false;
	}
	return ( false !== file_put_contents( $file, $contents ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

/**
 * Delete every processed stylesheet copy.
 *
 * @return void
 */
function aero_io_clear_css_cache() {
	$dir = WP_CONTENT_DIR . '/aero-nextgen/css';
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$files = glob( $dir . '/*.css' );
	if ( ! is_array( $files ) ) {
		return;
	}
	foreach ( $files as $file ) {
		wp_delete_file( $file );
	}
}

/**
 * Register the stylesheet filter when the option is on and picture delivery
 * is the active mode (rewrite modes already cover CSS at the HTTP layer).
 *
 * @return void
 */
function aero_io_maybe_filter_stylesheets() {
	if ( is_admin() || is_customize_preview() ) {
		return;
	}
	if ( ! aero_io_is_enabled() ) {
		return;
	}
	if ( 'picture' !== aero_io_delivery_mode() ) {
		return;
	}
	if ( get_option( 'aero_io_css_files', '1' ) !== '1' ) {
		return;
	}
	add_filter( 'style_loader_src', 'aero_io_filter_style_src', 999, 2 );
}
add_action( 'wp_enqueue_scripts', 'aero_io_maybe_filter_stylesheets', 1 );
