<?php
/**
 * Aero Cache Manager — Automated Flush Triggers
 *
 * Flush cache automatically on: plugin/theme update, page/post edit
 * (targeted per-URL Batcache flush), page/post delete, comment delete.
 * Each trigger is individually toggleable via aero_cm_options.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Full-flush action notice ─────────────────────────────────────────────────
// After any sequential full flush (plugin update, admin bar, Cache screen),
// tell the user what happened and where the rebuild is happening. Cron and
// system contexts are skipped inside the notice queue itself.
add_action( 'aero_cm_after_sequential_flush', 'aero_cm_notice_after_sequential_flush', 30, 2 );
function aero_cm_notice_after_sequential_flush( $results, $context ) {
	if ( ! function_exists( 'aero_ui_action_notice_add' ) ) {
		return;
	}

	$msg = sprintf(
		/* translators: %s: flush context/source */
		__( 'Flushed all caches sequentially (%s).', 'aero' ),
		'<b>' . esc_html( $context ) . '</b>'
	);

	$warmer_url = esc_url( admin_url( 'admin.php?page=aero-warmer' ) );
	if ( function_exists( 'aero_cw_enabled' ) && aero_cw_enabled() ) {
		$o = aero_cw_opts();
		if ( '1' === $o['auto_after_flush'] ) {
			$scope = ( 'all' === $o['limit'] )
				? __( 'all detectable URLs', 'aero' )
				/* translators: %d: URL limit */
				: sprintf( __( 'up to %d URLs', 'aero' ), (int) $o['limit'] );
			$msg .= ' ' . sprintf(
				/* translators: 1: scope text, 2: warmer URL */
				__( 'Cache Warmer is rebuilding %1$s. <a href="%2$s">View progress →</a>', 'aero' ),
				$scope,
				$warmer_url
			);
		} else {
			/* translators: %s: warmer URL */
			$msg .= ' ' . sprintf( __( 'Auto-warm is off — <a href="%s">warm the cache now →</a>', 'aero' ), $warmer_url );
		}
	} else {
		/* translators: %s: warmer URL */
		$msg .= ' ' . sprintf( __( 'The Cache Warmer is disabled — the next visitors regenerate pages. <a href="%s">Enable it →</a>', 'aero' ), $warmer_url );
	}

	aero_ui_action_notice_add( $msg );
}

// ─── Surgical URL-set purging ─────────────────────────────────────────────────

/**
 * Build the set of URLs whose cached copies go stale when a post changes:
 * the post itself, the homepage, its post-type archive, every taxonomy term
 * archive it belongs to, the author archive, and the main feed. Capped at
 * 20 URLs so "surgical" never degrades into a crawl.
 */
function aero_cm_related_urls_for_post( $post_id, $post = null, $context = 'edit' ) {
	$post = $post ? $post : get_post( $post_id );
	if ( ! $post ) {
		return array();
	}

	$urls = array();

	$permalink = get_permalink( $post_id );
	if ( $permalink ) {
		$urls[] = $permalink;
	}

	// ── Homepage: only when this change can actually appear there ──────
	// The homepage is the page that should be warm more than any other, so
	// it is purged conditionally, not reflexively:
	//   1. The front page lists latest posts and a post changed, OR
	//   2. The edited item IS the designated front page / posts page, OR
	//   3. The change is a membership transition (publish/trash/restore),
	//      which alters what any post list displays.
	// A plain content edit on a static-front-page site skips it entirely.
	$front_id = (int) get_option( 'page_on_front' );
	$blog_id  = (int) get_option( 'page_for_posts' );

	$include_home = ( 'posts' === get_option( 'show_on_front' ) && 'post' === $post->post_type )
		|| ( $post_id && in_array( (int) $post_id, array( $front_id, $blog_id ), true ) )
		|| ( 'transition' === $context );

	if ( $include_home ) {
		$urls[] = home_url( '/' );
	}

	// The designated posts page lists posts the same way a posts-homepage does.
	if ( $blog_id && 'post' === $post->post_type && (int) $post_id !== $blog_id ) {
		$blog_url = get_permalink( $blog_id );
		if ( $blog_url ) {
			$urls[] = $blog_url;
		}
	}

	$archive = get_post_type_archive_link( $post->post_type );
	if ( $archive ) {
		$urls[] = $archive;
	}

	foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
		if ( empty( $tax->public ) ) {
			continue;
		}
		$terms = get_the_terms( $post_id, $tax->name );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
		}
	}

	if ( post_type_supports( $post->post_type, 'author' ) && $post->post_author ) {
		$author = get_author_posts_url( (int) $post->post_author );
		if ( $author ) {
			$urls[] = $author;
		}
	}

	if ( 'post' === $post->post_type ) {
		$feed = get_feed_link();
		if ( $feed ) {
			$urls[] = $feed;
		}
	}

	return array_slice( array_values( array_unique( array_filter( $urls ) ) ), 0, 20 );
}

/**
 * Purge a URL set from Batcache (per-URL) and, when enabled, from the Edge
 * Cache (batched purge_uris_now). Falls back to a full object-cache flush
 * only if per-URL invalidation is unavailable. Returns the purged count.
 */
function aero_cm_purge_url_set( $urls, $reason = '' ) {
	$urls = array_values( array_unique( array_filter( (array) $urls ) ) );
	if ( empty( $urls ) ) {
		return 0;
	}

	global $batcache, $wp_object_cache;
	$can_target = isset( $batcache ) && is_object( $batcache )
		&& is_object( $wp_object_cache ) && method_exists( $wp_object_cache, 'incr' )
		&& class_exists( 'Aero_Batcache_Manager' );

	if ( $can_target ) {
		$batcache->configure_groups();
		foreach ( $urls as $url ) {
			Aero_Batcache_Manager::clear_url( $url );
		}
	} else {
		// No per-URL machinery on this stack — the sledgehammer is the only tool.
		wp_cache_flush();
	}

	if ( get_option( 'edge-cache-enabled' ) === 'enabled' && class_exists( 'Edge_Cache_Plugin' ) ) {
		$edge = Edge_Cache_Plugin::get_instance();
		if ( method_exists( $edge, 'purge_uris_now' ) ) {
			$edge->purge_uris_now( $urls );
		}
	}

	// Re-warm exactly what was purged (homepage first when present), so a
	// surgical purge costs seconds of coldness on a few URLs — never a
	// cold homepage waiting for a real visitor. The reason travels with
	// each URL so the Warmer screen can show WHY it was re-warmed.
	if ( function_exists( 'aero_cw_warm_urls' ) ) {
		aero_cw_warm_urls( $urls, $reason );
	}

	return count( $urls );
}

$aero_cm_trigger_options = aero_cm_get_options();

// ─── Trigger: plugin & theme update ──────────────────────────────────────────
if ( ! empty( $aero_cm_trigger_options['flush_cache_theme_plugin_checkbox'] ) ) {

	function aero_cm_plugins_themes_update_completed( $upgrader_object, $hook_extra ) {

		$type = isset( $hook_extra['type'] ) ? $hook_extra['type'] : '';

		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return;
		}
		// Installs and reinstalls don't invalidate existing pages — only updates do.
		if ( isset( $hook_extra['action'] ) && 'update' !== $hook_extra['action'] ) {
			return;
		}

		// ── Resolve what was updated (robust across shiny/bulk/auto paths) ──
		$names = array();

		if ( 'plugin' === $type ) {
			// get_plugin_data() is admin-only; auto-updates run in cron where
			// it may not be loaded yet.
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$files = array();
			if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
				$files = $hook_extra['plugins']; // bulk updates
			} elseif ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
				$files = array( $hook_extra['plugin'] ); // single "Update now"
			}
			foreach ( $files as $plugin_file ) {
				$path = WP_PLUGIN_DIR . '/' . $plugin_file;
				if ( file_exists( $path ) ) {
					$data = get_plugin_data( $path, false, false );
					if ( ! empty( $data['Name'] ) ) {
						$names[] = $data['Name'];
						continue;
					}
				}
				$names[] = dirname( $plugin_file ) !== '.' ? dirname( $plugin_file ) : $plugin_file;
			}
			if ( empty( $names ) && isset( $upgrader_object->skin->plugin_info['Name'] ) ) {
				$names[] = $upgrader_object->skin->plugin_info['Name'];
			}
		} else {
			$sheets = array();
			if ( isset( $hook_extra['themes'] ) && is_array( $hook_extra['themes'] ) ) {
				$sheets = $hook_extra['themes'];
			} elseif ( isset( $hook_extra['theme'] ) && is_string( $hook_extra['theme'] ) ) {
				$sheets = array( $hook_extra['theme'] );
			}
			foreach ( $sheets as $stylesheet ) {
				$theme   = wp_get_theme( $stylesheet );
				$names[] = $theme->exists() ? $theme->get( 'Name' ) : $stylesheet;
			}
			if ( empty( $names ) && isset( $upgrader_object->skin->theme_info ) && is_object( $upgrader_object->skin->theme_info ) ) {
				$names[] = $upgrader_object->skin->theme_info->get( 'Name' );
			}
		}

		if ( empty( $names ) ) {
			$label = ( 'plugin' === $type ) ? __( 'A plugin', 'aero' ) : __( 'A theme', 'aero' );
		} elseif ( count( $names ) <= 2 ) {
			$label = implode( ', ', $names );
		} else {
			/* translators: 1: first item name, 2: count of additional items */
			$label = sprintf( __( '%1$s + %2$d more', 'aero' ), $names[0], count( $names ) - 1 );
		}

		// ── The TRUE full flush ─────────────────────────────────────────────
		// Previously this was a raw wp_cache_flush(): object cache only. It
		// never cleared Aero's minified assets, never purged the Edge Cache
		// (which kept serving stale pages), and never fired the flush action
		// the auto-warmer listens for. The sequential engine does all three
		// in the configured order — and its completion hook starts the warm
		// run automatically.
		if ( function_exists( 'aero_cm_run_sequential_flush' ) ) {
			aero_cm_run_sequential_flush( 'update: ' . $label );
		} else {
			wp_cache_flush(); // degraded fallback if the engine is unavailable
		}

		$timestamp = gmdate( 'j M Y, g:ia' ) . ' UTC — <b>' . esc_html( $label ) . '</b> ' . esc_html__( 'updated — full flush + re-warm', 'aero' );
		update_option( 'flush-cache-theme-plugin-time-stamp', $timestamp );
	}

	add_action( 'upgrader_process_complete', 'aero_cm_plugins_themes_update_completed', 10, 2 );
}

// ─── Trigger: targeted Batcache flush on page/post edit ─────────────────────
if ( ! empty( $aero_cm_trigger_options['flush_cache_page_edit_checkbox'] ) ) {

	/**
	 * Flush Batcache only for the URL of the post that was just saved.
	 * Covers pages, posts, and all custom post types, including
	 * WooCommerce products saved via the REST API.
	 */
	function aero_cm_flush_batcache_on_page_edit( $post_id, $post, $update ) {

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		// Surgical: the edited URL plus everywhere its excerpt/link appears.
		// A brand-new publish is a membership transition (lists change);
		// editing an existing post is not — the homepage is only included
		// when it can actually display this change.
		$context = $update ? 'edit' : 'transition';
		$reason  = ( $update ? __( 'Page edit', 'aero' ) : __( 'Published', 'aero' ) ) . ' — ' . get_the_title( $post_id );
		$count   = aero_cm_purge_url_set( aero_cm_related_urls_for_post( $post_id, $post, $context ), $reason );
		if ( $count > 0 ) {
			update_option(
				'flush-cache-page-edit-time-stamp',
				gmdate( 'j M Y, g:ia' ) . ' UTC — <b>' . esc_html( get_the_title( $post_id ) ) . '</b> + ' . ( $count - 1 ) . ' related URLs'
			);
			if ( function_exists( 'aero_ui_action_notice_add' ) ) {
				$related    = $count - 1;
				$warmer_url = esc_url( admin_url( 'admin.php?page=aero-warmer#aero-surgical' ) );
				if ( 0 === $related ) {
					aero_ui_action_notice_add( sprintf(
						/* translators: %s: warmer URL */
						__( 'Purged this page\'s cache — re-warming now. <a href="%s">View Re-warms →</a>', 'aero' ),
						$warmer_url
					) );
				} else {
					aero_ui_action_notice_add( sprintf(
						/* translators: 1: related URL count, 2: warmer URL */
						_n(
							'Purged this page\'s cache + %1$d related URL — re-warming now. <a href="%2$s">View Re-warms →</a>',
							'Purged this page\'s cache + %1$d related URLs — re-warming now. <a href="%2$s">View Re-warms →</a>',
							$related,
							'aero'
						),
						$related,
						$warmer_url
					) );
				}
			}
		}
	}
	add_action( 'save_post', 'aero_cm_flush_batcache_on_page_edit', 10, 3 );
}

// ─── Trigger: page/post delete (trash / untrash) ─────────────────────────────
if ( ! empty( $aero_cm_trigger_options['flush_cache_on_page_post_delete_checkbox'] ) ) {

	function aero_cm_fire_on_page_post_delete( $post_ID, $post_after, $post_before ) {
		$trashed  = ( 'trash' === $post_after->post_status && 'publish' === $post_before->post_status );
		$restored = ( 'publish' === $post_after->post_status && 'trash' === $post_before->post_status );
		if ( ! $trashed && ! $restored ) {
			return;
		}

		// Surgical: the affected URL set only — not the whole site. The
		// permalink may already 404 after trashing, but its cached copy (and
		// every archive that listed it) is exactly what must go.
		$reason = ( $trashed ? __( 'Trashed', 'aero' ) : __( 'Restored', 'aero' ) ) . ' — ' . get_the_title( $post_ID );
		$count  = aero_cm_purge_url_set( aero_cm_related_urls_for_post( $post_ID, $trashed ? $post_before : $post_after, 'transition' ), $reason );

		update_option(
			'flush-cache-on-page-post-delete-time-stamp',
			gmdate( 'j M Y, g:ia' ) . ' UTC — <b>' . esc_html( get_the_title( $post_ID ) ) . '</b> ' . ( $trashed ? 'trashed' : 'restored' ) . ', ' . $count . ' URLs purged'
		);
		set_transient( 'aero-cm-page-post-delete-notice', true, 9 );

		if ( function_exists( 'aero_ui_action_notice_add' ) && $count > 0 ) {
			aero_ui_action_notice_add( sprintf(
				/* translators: 1: purged URL count, 2: post title, 3: warmer URL */
				__( 'Purged %1$d URLs after %2$s was %3$s — re-warming now. <a href="%4$s">View Re-warms →</a>', 'aero' ),
				$count,
				'<b>' . esc_html( get_the_title( $post_ID ) ) . '</b>',
				$trashed ? esc_html__( 'trashed', 'aero' ) : esc_html__( 'restored', 'aero' ),
				esc_url( admin_url( 'admin.php?page=aero-warmer#aero-surgical' ) )
			) );
		}
	}
	add_action( 'post_updated', 'aero_cm_fire_on_page_post_delete', 10, 3 );
}

// ─── Trigger: comment delete ──────────────────────────────────────────────────
if ( ! empty( $aero_cm_trigger_options['flush_cache_on_comment_delete_checkbox'] ) ) {

	function aero_cm_trash_comment_action( $comment_id, $comment ) {
		// Surgical: only the post the comment lived on — its count and thread
		// are the only things that changed. The homepage stays warm; sites
		// with a recent-comments widget there can add a homepage purge via
		// the aero_cm_comment_purge_urls filter.
		$urls = array();
		if ( $comment && ! empty( $comment->comment_post_ID ) ) {
			$permalink = get_permalink( (int) $comment->comment_post_ID );
			if ( $permalink ) {
				$urls[] = $permalink;
			}
		}
		$urls   = apply_filters( 'aero_cm_comment_purge_urls', $urls, $comment );
		$reason = __( 'Comment removed', 'aero' );
		if ( $comment && ! empty( $comment->comment_post_ID ) ) {
			$reason .= ' — ' . get_the_title( (int) $comment->comment_post_ID );
		}
		$count = aero_cm_purge_url_set( $urls, $reason );
		update_option( 'flush-cache-on-comment-delete-time-stamp', gmdate( 'j M Y, g:ia' ) . ' UTC — ' . $count . ' URLs purged' );

		if ( function_exists( 'aero_ui_action_notice_add' ) && $count > 0 ) {
			aero_ui_action_notice_add( sprintf(
				/* translators: %s: warmer URL */
				__( 'Purged the commented post\'s cache — re-warming now. <a href="%s">View Re-warms →</a>', 'aero' ),
				esc_url( admin_url( 'admin.php?page=aero-warmer#aero-surgical' ) )
			) );
		}
	}
	add_action( 'trash_comment', 'aero_cm_trash_comment_action', 10, 2 );
}
