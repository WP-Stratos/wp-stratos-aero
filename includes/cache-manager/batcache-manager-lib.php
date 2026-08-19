<?php
/**
 * Aero Cache Manager — Batcache Manager
 *
 * Targeted per-URL Batcache invalidation. Based on Batcache Manager 2.0.2
 * by Jonathan Harris (GPL), extended with WooCommerce product support and
 * Edge Cache per-URL purging, integrated into Aero.
 *
 * This file is also copied into mu-plugins when "Flush Batcache for
 * WooCommerce Product Pages" is enabled, so it must remain self-contained.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Aero_Batcache_Manager' ) ) {

	class Aero_Batcache_Manager {

		/** @var array List of feeds */
		private $feeds = array( 'rss', 'rss2', 'rdf', 'atom' );

		/** @var array List of links to process */
		private $links = array();

		/** @var object Instance of this class */
		protected static $instance = null;

		private function __construct() {

			global $batcache, $wp_object_cache;

			// Do not load if advanced-cache.php isn't loaded
			if ( ! isset( $batcache ) || ! is_object( $batcache ) || ! method_exists( $wp_object_cache, 'incr' ) ) {
				return;
			}

			$batcache->configure_groups();

			// Posts
			add_action( 'clean_post_cache', array( $this, 'action_clean_post_cache' ), 15 );
			// Terms
			add_action( 'clean_term_cache', array( $this, 'action_clean_term_cache' ), 10, 3 );
			// Comments
			add_action( 'clean_comment_cache', array( $this, 'action_update_comment' ) );
			add_action( 'comment_post', array( $this, 'action_update_comment' ) );
			add_action( 'wp_set_comment_status', array( $this, 'action_update_comment' ) );
			add_action( 'edit_comment', array( $this, 'action_update_comment' ) );
			// Users
			add_action( 'clean_user_cache', array( $this, 'action_update_user' ) );
			add_action( 'profile_update', array( $this, 'action_update_user' ) );
			// Widgets
			add_filter( 'widget_update_callback', array( $this, 'action_update_widget' ), 50 );
			// Customizer
			add_action( 'customize_save_after', array( $this, 'flush_all' ) );
			// Theme
			add_action( 'switch_theme', array( $this, 'flush_all' ) );
			// Nav
			add_action( 'wp_update_nav_menu', array( $this, 'flush_all' ) );

			// Add site aliases to list of links
			add_filter( 'aero_batcache_manager_links', array( $this, 'add_site_alias' ) );

			// Do the flush of the urls on shutdown
			add_action( 'shutdown', array( $this, 'clear_urls' ) );
		}

		public static function get_instance() {
			if ( null == self::$instance ) {
				self::$instance = new self;
			}
			return self::$instance;
		}

		public function is_post_type_viewable_check( $post_type ) {
			$post_type_object = get_post_type_object( $post_type );
			if ( empty( $post_type_object ) ) {
				return false;
			}
			return $post_type_object->publicly_queryable || ( $post_type_object->_builtin && $post_type_object->public );
		}

		public function is_taxonomy_viewable_check( $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return false;
			}
			$taxonomy = get_taxonomy( $taxonomy );
			return $taxonomy->public;
		}

		/**
		 * Clear post on post update — targeted: only the permalink of the
		 * specific post that changed. Shared URLs (archives, feeds, homepage)
		 * are intentionally NOT flushed here.
		 */
		public function action_clean_post_cache( $post_id ) {

			$post = get_post( $post_id );
			if ( $post && $post->post_type && ! $this->is_post_type_viewable_check( $post->post_type ) || ! in_array( get_post_status( $post_id ), array( 'publish', 'trash' ), true ) ) {
				return;
			}

			$permalink = get_permalink( $post );
			if ( ! empty( $permalink ) ) {
				$this->links[] = $permalink;
			}

			// WooCommerce product: also purge Edge Cache for the product URL
			if ( 'product' === $post->post_type ) {
				$product_url = get_permalink( $post );

				// 1. Flush the specific product URL (Batcache)
				self::clear_url( $product_url );

				// 2. Store the product URL in the option table
				update_option( 'edge-cache-single-page-url-purged', $product_url );

				// 3. Purge Edge Cache for the specific URL
				if ( class_exists( 'Edge_Cache_Plugin' ) ) {
					$edge_cache = Edge_Cache_Plugin::get_instance();
					if ( method_exists( $edge_cache, 'purge_uris_now' ) ) {
						$edge_cache->purge_uris_now( array( $product_url ) );
						update_option( 'single-page-edge-cache-purge-time-stamp', gmdate( 'jS F Y g:ia' ) . ' UTC' );
					}
				}
			}
		}

		public function action_clean_term_cache( $ids, $taxonomy, $clean_taxonomy = true ) {
			if ( ! $clean_taxonomy ) {
				return;
			}
			if ( ! $this->is_taxonomy_viewable_check( $taxonomy ) ) {
				return;
			}
			foreach ( $ids as $term ) {
				$this->setup_term_urls( $term, $taxonomy );
			}
		}

		public function action_update_comment( $comment_id ) {
			$comment = get_comment( $comment_id );
			if ( ! $comment ) {
				return;
			}
			$post_id = $comment->comment_post_ID;
			$this->setup_post_urls( $post_id );
			$this->setup_post_comment_urls( $post_id, $comment_id );
		}

		public function action_update_user( $user_id ) {
			$this->setup_author_urls( $user_id );
		}

		public function flush_all() {
			if ( function_exists( 'batcache_flush_all' ) ) {
				batcache_flush_all();
			}
		}

		public function action_update_widget( $instance ) {
			$this->flush_all();
			return $instance;
		}

		public function setup_term_urls( $term, $taxonomy ) {
			$term_link = get_term_link( $term, $taxonomy );
			if ( ! is_wp_error( $term_link ) ) {
				$this->links[] = $term_link;
			}
			foreach ( $this->feeds as $feed ) {
				$term_link_feed = get_term_feed_link( $term, $taxonomy, $feed );
				if ( $term_link_feed ) {
					$this->links[] = $term_link_feed;
				}
			}
			$taxonomy_object = get_taxonomy( $taxonomy );
			if ( $taxonomy_object->show_in_rest && $taxonomy_object->rest_base ) {
				$base          = $taxonomy_object->rest_base;
				$this->links[] = get_rest_url( null, '/wp/v2/' . $base );
				$this->links[] = get_rest_url( null, '/wp/v2/' . $base . '/' . $term );
			}
		}

		public function setup_site_urls() {
			if ( get_option( 'show_on_front' ) == 'page' ) {
				$this->links[] = get_permalink( get_option( 'page_for_posts' ) );
			}
			$this->links[] = home_url( '/' );
			foreach ( $this->feeds as $feed ) {
				$this->links[] = get_feed_link( $feed );
			}
		}

		public function setup_post_urls( $post ) {
			$post = get_post( $post );

			$this->links[] = get_permalink( $post );
			if ( $post->post_type == 'post' ) {
				$year          = get_the_time( 'Y', $post );
				$month         = get_the_time( 'm', $post );
				$day           = get_the_time( 'd', $post );
				$this->links[] = get_year_link( $year );
				$this->links[] = get_month_link( $year, $month );
				$this->links[] = get_day_link( $year, $month, $day );
			} elseif ( ! in_array( $post->post_type, get_post_types( array( 'public' => true ) ), true ) ) {
				if ( $archive_link = get_post_type_archive_link( $post->post_type ) ) {
					$this->links[] = $archive_link;
				}
				foreach ( $this->feeds as $feed ) {
					if ( $archive_link_feed = get_post_type_archive_feed_link( $post->post_type, $feed ) ) {
						$this->links[] = $archive_link_feed;
					}
				}
			}
			$post_type = get_post_type_object( $post->post_type );
			if ( $post_type->show_in_rest && $post_type->rest_base ) {
				$base          = $post_type->rest_base;
				$this->links[] = get_rest_url( null, '/wp/v2/' . $base );
				$this->links[] = get_rest_url( null, '/wp/v2/' . $base . '/' . $post->ID );
			}
		}

		public function setup_author_urls( $author_id ) {
			$this->links[] = get_author_posts_url( $author_id );
			foreach ( $this->feeds as $feed ) {
				$this->links[] = get_author_feed_link( $author_id, $feed );
			}
			$this->links[] = get_rest_url( null, '/wp/v2/users' );
			$this->links[] = get_rest_url( null, '/wp/v2/users/' . $author_id );
		}

		public function setup_post_comment_urls( $post_id, $comment_id = 0 ) {
			foreach ( $this->feeds as $feed ) {
				$this->links[] = get_post_comments_feed_link( $post_id, $feed );
			}
			foreach ( $this->feeds as $feed ) {
				$this->links[] = get_feed_link( 'comments_' . $feed );
			}
			$this->links[] = get_rest_url( null, '/wp/v2/comments' );
			$this->links[] = get_rest_url( null, '/wp/v2/comments/' . $comment_id );
		}

		public function add_site_alias( $links ) {
			$home = parse_url( home_url(), PHP_URL_HOST );

			$compare_urls = array(
				parse_url( get_option( 'home' ), PHP_URL_HOST ),
				parse_url( get_option( 'siteurl' ), PHP_URL_HOST ),
				parse_url( site_url(), PHP_URL_HOST ),
			);

			foreach ( $compare_urls as $compare_url ) {
				if ( $compare_url != $home ) {
					foreach ( $links as $url ) {
						$links[] = str_replace( $home, $compare_url, $url );
					}
				}
			}
			return $links;
		}

		public function clear_urls() {
			if ( empty( $this->get_links() ) ) {
				return;
			}
			foreach ( $this->get_links() as $url ) {
				self::clear_url( $url );
			}
			$this->links = array();

			update_option( 'flush-object-cache-for-single-page-time-stamp', gmdate( 'jS F Y g:ia' ) . ' UTC' );
		}

		/**
		 * Invalidate the Batcache entry for a single URL by incrementing
		 * its version key in the object cache.
		 */
		public static function clear_url( $url ) {
			global $batcache, $wp_object_cache;

			$url = apply_filters( 'aero_batcache_manager_link', $url );

			if ( empty( $url ) ) {
				return false;
			}

			do_action( 'aero_batcache_manager_before_flush', $url );

			// Force to http (Batcache keys on the http scheme)
			$url = set_url_scheme( $url, 'http' );

			$url_key = md5( $url );

			wp_cache_add( "{$url_key}_version", 0, $batcache->group );
			$retval = wp_cache_incr( "{$url_key}_version", 1, $batcache->group );

			$batcache_no_remote_group_key = property_exists( $wp_object_cache, 'no_remote_groups' )
				? array_search( $batcache->group, (array) $wp_object_cache->no_remote_groups )
				: false;

			if ( false !== $batcache_no_remote_group_key ) {
				// The *_version key needs to be replicated remotely, otherwise invalidation won't work.
				unset( $wp_object_cache->no_remote_groups[ $batcache_no_remote_group_key ] );
				$retval = wp_cache_set( "{$url_key}_version", $retval, $batcache->group );
				$wp_object_cache->no_remote_groups[ $batcache_no_remote_group_key ] = $batcache->group;
			}

			do_action( 'aero_batcache_manager_after_flush', $url, $retval );

			return $retval;
		}

		public function get_links() {
			$this->links = apply_filters( 'aero_batcache_manager_links', $this->links );
			return array_unique( $this->links );
		}
	}

	global $aero_batcache_manager;
	$aero_batcache_manager = Aero_Batcache_Manager::get_instance();
}
