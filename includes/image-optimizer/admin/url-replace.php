<?php
/**
 * Aero Image Optimizer — image URL replace tool.
 *
 * Replacing a media file is only half the job: the old URL is usually baked
 * into post content, widget options and page builder payloads. This tool
 * rewrites one image URL to another across posts, post meta, term meta and
 * options.
 *
 * Two details make or break a tool like this:
 *
 *   1. Serialized data. A naive string replace corrupts serialized arrays
 *      whenever the replacement has a different length, because the embedded
 *      s:<len> prefixes stop matching. Values are unserialized, replaced
 *      recursively, and re-serialized instead.
 *   2. Escaped-slash JSON. Elementor stores its entire page tree as JSON in
 *      the _elementor_data meta with forward slashes escaped, so the URL
 *      appears as https:\/\/site.com\/…. That variant is replaced as well,
 *      which is why this works on Elementor pages at all.
 *
 * Every run is preceded by a dry run that reports exactly how many rows would
 * change, and nothing is written until the change is confirmed.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the search/replace variants for a URL pair.
 *
 * Root-relative and protocol-relative forms are included because themes and
 * builders store all three shapes.
 *
 * @param string $from Source URL.
 * @param string $to   Target URL.
 * @return array<int,array{0:string,1:string}>
 */
function aero_io_url_variants( $from, $to ) {
	$variants = array( array( $from, $to ) );

	// Escaped-slash JSON (Elementor, several block editors).
	$variants[] = array( str_replace( '/', '\\/', $from ), str_replace( '/', '\\/', $to ) );

	// Protocol-relative.
	$from_pr = preg_replace( '#^https?://#i', '//', $from );
	$to_pr   = preg_replace( '#^https?://#i', '//', $to );
	if ( $from_pr !== $from ) {
		$variants[] = array( $from_pr, $to_pr );
		$variants[] = array( str_replace( '/', '\\/', $from_pr ), str_replace( '/', '\\/', $to_pr ) );
	}

	// Root-relative (same host only — the caller validates the host).
	$from_path = wp_parse_url( $from, PHP_URL_PATH );
	$to_path   = wp_parse_url( $to, PHP_URL_PATH );
	if ( $from_path && $to_path ) {
		$variants[] = array( $from_path, $to_path );
		$variants[] = array( str_replace( '/', '\\/', $from_path ), str_replace( '/', '\\/', $to_path ) );
	}

	// Longest search string first, so the absolute form is consumed before
	// its own path substring can match inside it.
	usort(
		$variants,
		function ( $a, $b ) {
			return strlen( $b[0] ) - strlen( $a[0] );
		}
	);

	$seen = array();
	$out  = array();
	foreach ( $variants as $pair ) {
		if ( '' === $pair[0] || $pair[0] === $pair[1] || isset( $seen[ $pair[0] ] ) ) {
			continue;
		}
		$seen[ $pair[0] ] = true;
		$out[]            = $pair;
	}

	return $out;
}

/**
 * Recursively replace inside any value, keeping serialized data valid.
 *
 * @param mixed $value    Value to walk.
 * @param array $variants Search/replace pairs.
 * @param bool  $changed  Set to true when anything was replaced.
 * @return mixed
 */
function aero_io_deep_replace( $value, $variants, &$changed ) {
	if ( is_array( $value ) ) {
		$out = array();
		foreach ( $value as $key => $item ) {
			$out[ $key ] = aero_io_deep_replace( $item, $variants, $changed );
		}
		return $out;
	}

	if ( is_object( $value ) ) {
		// Only plain objects are safe to walk; anything else is left as-is.
		if ( $value instanceof stdClass ) {
			$out = clone $value;
			foreach ( get_object_vars( $out ) as $key => $item ) {
				$out->$key = aero_io_deep_replace( $item, $variants, $changed );
			}
			return $out;
		}
		return $value;
	}

	if ( ! is_string( $value ) ) {
		return $value;
	}

	// A serialized string nested inside another value: recurse into it.
	// Class instantiation is disabled while unserializing, so a hostile
	// payload in the database cannot construct objects here. Any value that
	// did contain a class instance is left completely alone, since it cannot
	// be re-serialized faithfully once the class was refused.
	if ( is_serialized( $value, true ) ) {
		$inner = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false !== $inner || 'b:0;' === $value ) {
			if ( aero_io_contains_incomplete_class( $inner ) ) {
				return $value;
			}
			$sub     = false;
			$updated = aero_io_deep_replace( $inner, $variants, $sub );
			if ( $sub ) {
				$changed = true;
				return serialize( $updated ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
			return $value;
		}
	}

	foreach ( $variants as $pair ) {
		if ( false !== strpos( $value, $pair[0] ) ) {
			$value   = str_replace( $pair[0], $pair[1], $value );
			$changed = true;
		}
	}

	return $value;
}

/**
 * Detect refused class instances left behind by a guarded unserialize.
 *
 * @param mixed $value Value to inspect.
 * @return bool
 */
function aero_io_contains_incomplete_class( $value ) {
	if ( is_object( $value ) ) {
		return ( '__PHP_Incomplete_Class' === get_class( $value ) );
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $item ) {
			if ( aero_io_contains_incomplete_class( $item ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Tables and columns the tool walks.
 *
 * @return array<int,array>
 */
function aero_io_replace_targets() {
	global $wpdb;

	return array(
		array(
			'table'  => $wpdb->posts,
			'key'    => 'ID',
			'fields' => array( 'post_content', 'post_excerpt' ),
		),
		array(
			'table'  => $wpdb->postmeta,
			'key'    => 'meta_id',
			'fields' => array( 'meta_value' ),
		),
		array(
			'table'  => $wpdb->termmeta,
			'key'    => 'meta_id',
			'fields' => array( 'meta_value' ),
		),
		array(
			'table'  => $wpdb->options,
			'key'    => 'option_id',
			'fields' => array( 'option_value' ),
		),
	);
}

/**
 * Walk every target row that contains any variant.
 *
 * @param array    $variants Search/replace pairs.
 * @param bool     $apply    Write changes when true; count only when false.
 * @return array{rows:int,tables:array<string,int>}
 */
function aero_io_run_url_replace( $variants, $apply ) {
	global $wpdb;

	$rows   = 0;
	$tables = array();

	foreach ( aero_io_replace_targets() as $target ) {
		$table   = $target['table'];
		$key     = $target['key'];
		$matched = 0;

		foreach ( $target['fields'] as $field ) {
			// A single LIKE on the longest (absolute) variant would miss the
			// escaped and relative shapes, so every variant is OR'd in.
			$where  = array();
			$params = array();
			foreach ( $variants as $pair ) {
				$where[]  = "{$field} LIKE %s";
				$params[] = '%' . $wpdb->esc_like( $pair[0] ) . '%';
			}

			$sql = "SELECT {$key}, {$field} FROM {$table} WHERE " . implode( ' OR ', $where );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$results = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

			if ( empty( $results ) ) {
				continue;
			}

			foreach ( $results as $row ) {
				$changed = false;
				$updated = aero_io_deep_replace( $row[ $field ], $variants, $changed );

				if ( ! $changed ) {
					continue;
				}

				$matched++;

				if ( $apply ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$table,
						array( $field => $updated ),
						array( $key => $row[ $key ] ),
						array( '%s' ),
						array( '%d' )
					);
				}
			}
		}

		if ( $matched > 0 ) {
			$tables[ $table ] = $matched;
			$rows            += $matched;
		}
	}

	return array(
		'rows'   => $rows,
		'tables' => $tables,
	);
}

/**
 * Validate a submitted URL pair.
 *
 * @param string $from Source URL.
 * @param string $to   Target URL.
 * @return array|WP_Error
 */
function aero_io_validate_url_pair( $from, $to ) {
	$from = trim( $from );
	$to   = trim( $to );

	if ( '' === $from || '' === $to ) {
		return new WP_Error( 'aero_io_empty', __( 'Both the current URL and the replacement URL are required.', 'aero' ) );
	}

	if ( $from === $to ) {
		return new WP_Error( 'aero_io_same', __( 'The two URLs are identical, so there is nothing to replace.', 'aero' ) );
	}

	// Keep the tool pointed at images: a general search-and-replace across
	// the database is a very different (and much riskier) tool.
	foreach ( array( $from, $to ) as $url ) {
		if ( ! preg_match( '/\.(png|jpe?g|gif|webp|avif|svg)(\?.*)?$/i', $url ) ) {
			return new WP_Error( 'aero_io_not_image', __( 'Both URLs must point at an image file (jpg, png, gif, webp, avif or svg).', 'aero' ) );
		}
	}

	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	$from_host = wp_parse_url( $from, PHP_URL_HOST );
	if ( $from_host && $site_host && strtolower( $from_host ) !== strtolower( $site_host ) ) {
		return new WP_Error( 'aero_io_host', __( 'The current URL does not belong to this site.', 'aero' ) );
	}

	return array( $from, $to );
}

/**
 * Dry run: report how many rows would change.
 */
add_action( 'wp_ajax_aero_io_scan_url_replace', 'aero_io_ajax_scan_url_replace' );
function aero_io_ajax_scan_url_replace() {
	aero_io_ajax_check_security();

	$pair = aero_io_validate_url_pair(
		isset( $_POST['from'] ) ? esc_url_raw( wp_unslash( $_POST['from'] ) ) : '',
		isset( $_POST['to'] ) ? esc_url_raw( wp_unslash( $_POST['to'] ) ) : ''
	);

	if ( is_wp_error( $pair ) ) {
		echo wp_json_encode(
			array(
				'result' => 'failed',
				'error'  => $pair->get_error_message(),
			)
		);
		die();
	}

	$variants = aero_io_url_variants( $pair[0], $pair[1] );
	$report   = aero_io_run_url_replace( $variants, false );

	echo wp_json_encode(
		array(
			'result' => 'success',
			'rows'   => $report['rows'],
			'tables' => $report['tables'],
		)
	);
	die();
}

/**
 * Apply the replacement, then flush Aero's caches through the sequential
 * engine so the old URL stops being served from any layer.
 */
add_action( 'wp_ajax_aero_io_apply_url_replace', 'aero_io_ajax_apply_url_replace' );
function aero_io_ajax_apply_url_replace() {
	aero_io_ajax_check_security();

	$pair = aero_io_validate_url_pair(
		isset( $_POST['from'] ) ? esc_url_raw( wp_unslash( $_POST['from'] ) ) : '',
		isset( $_POST['to'] ) ? esc_url_raw( wp_unslash( $_POST['to'] ) ) : ''
	);

	if ( is_wp_error( $pair ) ) {
		echo wp_json_encode(
			array(
				'result' => 'failed',
				'error'  => $pair->get_error_message(),
			)
		);
		die();
	}

	$variants = aero_io_url_variants( $pair[0], $pair[1] );
	$report   = aero_io_run_url_replace( $variants, true );

	if ( $report['rows'] > 0 ) {
		if ( function_exists( 'aero_io_clear_css_cache' ) ) {
			aero_io_clear_css_cache();
		}
		if ( function_exists( 'aero_cm_run_sequential_flush' ) ) {
			aero_cm_run_sequential_flush( 'aero-images-url-replace' );
		}
	}

	echo wp_json_encode(
		array(
			'result' => 'success',
			'rows'   => $report['rows'],
			'tables' => $report['tables'],
		)
	);
	die();
}
