<?php
/**
 * Aero — Images screen
 *
 * WebP/AVIF conversion and compression, rendered inside the Aero shell in
 * the WP Postman design language. Sections: capability + savings stats band,
 * bulk optimization with live progress, conversion settings, delivery mode
 * with a live rewrite test, excluded/custom folders, media replacement,
 * restore, and logs.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Settings save (PRG, mirrors the cache-manager screens) ──────────────────
add_action( 'admin_init', 'aero_io_handle_settings_save', 5 );
function aero_io_handle_settings_save() {
	if ( ! isset( $_POST['aero_io_settings_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aero_io_settings_nonce'] ) ), 'aero_io_settings_save' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// ── Formats ──
	$converter = isset( $_POST['aero_io_converter_method'] ) ? sanitize_key( wp_unslash( $_POST['aero_io_converter_method'] ) ) : 'gd';
	if ( ! in_array( $converter, array( 'gd', 'imagick' ), true ) ) {
		$converter = 'gd';
	}
	Aero_IO_Options::update_option( 'aero_io_converter_method', $converter );
	Aero_IO_Options::update_option( 'aero_io_output_format_webp', isset( $_POST['aero_io_webp'] ) ? 1 : 0 );
	Aero_IO_Options::update_option( 'aero_io_output_format_avif', isset( $_POST['aero_io_avif'] ) ? 1 : 0 );

	// ── Quality ──
	$quality_options            = array();
	$preset                     = isset( $_POST['aero_io_quality'] ) ? sanitize_key( wp_unslash( $_POST['aero_io_quality'] ) ) : 'lossy';
	$allowed_presets            = array( 'lossless', 'lossy_minus', 'lossy', 'lossy_plus', 'lossy_super', 'custom' );
	$quality_options['quality'] = in_array( $preset, $allowed_presets, true ) ? $preset : 'lossy';
	$quality_options['quality_webp'] = isset( $_POST['aero_io_quality_webp'] ) ? min( 100, max( 1, absint( wp_unslash( $_POST['aero_io_quality_webp'] ) ) ) ) : 80;
	$quality_options['quality_avif'] = isset( $_POST['aero_io_quality_avif'] ) ? min( 100, max( 1, absint( wp_unslash( $_POST['aero_io_quality_avif'] ) ) ) ) : 60;
	Aero_IO_Options::update_option( 'aero_io_quality', $quality_options );

	// ── General settings (single array the engine reads everywhere) ──
	$general = Aero_IO_Options::get_option( 'aero_io_general_settings', array() );
	$general = is_array( $general ) ? $general : array();

	$general['remove_exif']               = isset( $_POST['aero_io_remove_exif'] );
	$general['auto_remove_larger_format'] = isset( $_POST['aero_io_auto_remove_larger'] );
	$general['exclude_png']               = isset( $_POST['aero_io_exclude_png'] );
	$general['exclude_png_webp']          = isset( $_POST['aero_io_exclude_png_webp'] );
	$general['exclude_jpg_avif']          = isset( $_POST['aero_io_exclude_jpg_avif'] );
	$general['exclude_jpg_webp']          = isset( $_POST['aero_io_exclude_jpg_webp'] );

	$general['resize']           = array(
		'enable' => isset( $_POST['aero_io_resize_enable'] ),
		'width'  => isset( $_POST['aero_io_resize_width'] ) ? min( 10000, max( 100, absint( wp_unslash( $_POST['aero_io_resize_width'] ) ) ) ) : 2560,
		'height' => isset( $_POST['aero_io_resize_height'] ) ? min( 10000, max( 100, absint( wp_unslash( $_POST['aero_io_resize_height'] ) ) ) ) : 2560,
	);
	$general['scan_images_page'] = isset( $_POST['aero_io_scan_batch'] ) ? min( 2000, max( 50, absint( wp_unslash( $_POST['aero_io_scan_batch'] ) ) ) ) : 500;

	// Thumbnail sizes to skip.
	$skip_sizes = array();
	if ( isset( $_POST['aero_io_skip_size'] ) && is_array( $_POST['aero_io_skip_size'] ) ) {
		$registered = get_intermediate_image_sizes();
		foreach ( wp_unslash( $_POST['aero_io_skip_size'] ) as $size ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$size = sanitize_key( $size );
			if ( in_array( $size, $registered, true ) ) {
				$skip_sizes[] = $size;
			}
		}
	}
	$general['skip_size'] = $skip_sizes;

	update_option( 'aero_io_css_files', isset( $_POST['aero_io_css_files'] ) ? '1' : '0' );
	update_option( 'aero_io_lazy_bg', isset( $_POST['aero_io_lazy_bg'] ) ? '1' : '0' );

	// Delivery mode.
	$mode = isset( $_POST['aero_io_image_load'] ) ? sanitize_key( wp_unslash( $_POST['aero_io_image_load'] ) ) : 'htaccess';
	if ( ! in_array( $mode, array( 'htaccess', 'compat_htaccess', 'picture' ), true ) ) {
		$mode = 'htaccess';
	}
	// .htaccess modes silently do nothing on Nginx/unknown servers — never
	// persist a mode that cannot work here.
	if ( ! aero_io_htaccess_supported() && 'picture' !== $mode ) {
		$mode = 'picture';
	}
	$general['image_load'] = $mode;

	Aero_IO_Options::update_option( 'aero_io_general_settings', $general );

	// ── Auto-optimize new uploads ──
	Aero_IO_Options::update_option( 'aero_io_auto_optimize', isset( $_POST['aero_io_auto_optimize'] ) );

	// ── Post-bulk cache purge through the sequential engine ──
	update_option( 'aero_io_purge_after_bulk', isset( $_POST['aero_io_purge_after_bulk'] ) ? '1' : '0' );

	// ── Excluded media-library folders (one relative path per line) ──
	$excludes = array();
	if ( isset( $_POST['aero_io_media_excludes'] ) ) {
		$uploads = wp_get_upload_dir();
		$lines   = explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['aero_io_media_excludes'] ) ) );
		foreach ( $lines as $line ) {
			$line = trim( str_replace( '\\', '/', $line ) );
			$line = trim( $line, '/' );
			if ( '' === $line || false !== strpos( $line, '..' ) ) {
				continue;
			}
			$excludes[] = trailingslashit( str_replace( '\\', '/', $uploads['basedir'] ) ) . $line;
		}
	}
	Aero_IO_Options::update_option( 'aero_io_media_excludes', array_values( array_unique( $excludes ) ) );

	// ── Media replacement ──
	Aero_IO_Options::update_option(
		'aero_io_media_replace',
		array(
			'enable'           => isset( $_POST['aero_io_replace_enable'] ),
			'auto_re_optimize' => isset( $_POST['aero_io_replace_reoptimize'] ),
		)
	);

	// Apply the delivery plumbing that matches the saved mode.
	aero_io_ensure_dirs();
	aero_io_apply_delivery_mode();

	aero_ui_flash_add( __( 'Image optimization settings saved.', 'aero' ), 'success' );
	aero_ui_redirect( 'aero-images' );
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Server capability snapshot for the stats band and settings hints.
 */
function aero_io_capabilities() {
	$gd_webp      = Aero_IO_Image_Opt_Method::is_support_gd_webp();
	$gd_avif      = Aero_IO_Image_Opt_Method::is_support_gd_avif();
	$imagick      = Aero_IO_Image_Opt_Method::is_support_imagick();
	$imagick_webp = Aero_IO_Image_Opt_Method::is_support_imagick_webp();
	$imagick_avif = $imagick && Aero_IO_Image_Opt_Method::is_support_imagick_avif();

	return array(
		'gd'           => Aero_IO_Image_Opt_Method::is_support_gd(),
		'gd_webp'      => $gd_webp,
		'gd_avif'      => $gd_avif,
		'imagick'      => $imagick,
		'imagick_webp' => $imagick_webp,
		'imagick_avif' => $imagick_avif,
		'webp'         => ( $gd_webp || $imagick_webp ),
		'avif'         => ( $gd_avif || $imagick_avif ),
	);
}

/**
 * Directory size of the generated tree (cheap enough at render time; the
 * tree only contains converted derivatives).
 */
function aero_io_generated_tree_size() {
	$dir = WP_CONTENT_DIR . '/aero-nextgen';
	if ( ! is_dir( $dir ) ) {
		return 0;
	}
	$size = 0;
	try {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() ) {
				$size += $file->getSize();
			}
		}
	} catch ( Exception $e ) {
		return 0;
	}
	return $size;
}

// ─── Screen render ───────────────────────────────────────────────────────────
function aero_io_render_images_screen() {
	aero_io_ensure_dirs();
	Aero_IO_Image_Meta_V2::ensure_table();

	$caps        = aero_io_capabilities();
	$general     = Aero_IO_Options::get_general_settings();
	$general_raw = Aero_IO_Options::get_option( 'aero_io_general_settings', array() );
	$quality     = Aero_IO_Options::get_quality_option();
	$mode        = aero_io_delivery_mode();
	$server      = aero_io_server_type();
	$ht_ok       = aero_io_htaccess_supported();

	$converter = Aero_IO_Options::get_option( 'aero_io_converter_method', false );
	if ( empty( $converter ) ) {
		$converter = Aero_IO_Options::set_default_compress_server();
	}

	$webp_on = (int) Aero_IO_Options::get_option( 'aero_io_output_format_webp', 'not init' );
	if ( 'not init' === Aero_IO_Options::get_option( 'aero_io_output_format_webp', 'not init' ) ) {
		$webp_on = (int) Aero_IO_Options::set_default_output_format_webp();
	}
	$avif_on = (int) Aero_IO_Options::get_option( 'aero_io_output_format_avif', 'not init' );
	if ( 'not init' === Aero_IO_Options::get_option( 'aero_io_output_format_avif', 'not init' ) ) {
		$avif_on = (int) Aero_IO_Options::set_default_output_format_avif();
	}

	$auto_optimize    = (bool) Aero_IO_Options::get_option( 'aero_io_auto_optimize', false );
	$purge_after_bulk = ( get_option( 'aero_io_purge_after_bulk', '1' ) === '1' );

	$excludes    = Aero_IO_Options::get_option( 'aero_io_media_excludes', array() );
	$uploads     = wp_get_upload_dir();
	$upload_base = trailingslashit( str_replace( '\\', '/', $uploads['basedir'] ) );
	$exclude_rel = array();
	foreach ( (array) $excludes as $ex ) {
		$exclude_rel[] = ltrim( str_replace( $upload_base, '', str_replace( '\\', '/', $ex ) ), '/' );
	}

	$custom_folders = Aero_IO_Options::get_option( 'aero_io_custom_includes', array() );
	$custom_folders = is_array( $custom_folders ) ? array_values( $custom_folders ) : array();

	$replace_opts       = Aero_IO_Options::get_option( 'aero_io_media_replace', array() );
	$replace_enable     = isset( $replace_opts['enable'] ) ? (bool) $replace_opts['enable'] : true;
	$replace_reoptimize = isset( $replace_opts['auto_re_optimize'] ) ? (bool) $replace_opts['auto_re_optimize'] : true;

	$tree_size = aero_io_generated_tree_size();
	$enabled   = aero_io_is_enabled();
	$coverage  = aero_io_coverage();
	$conflicts = function_exists( 'aero_io_active_conflicts' ) ? aero_io_active_conflicts() : array();
	$css_files = ( get_option( 'aero_io_css_files', '1' ) === '1' );
	$lazy_bg   = ( get_option( 'aero_io_lazy_bg', '1' ) === '1' );

	$sizes         = get_intermediate_image_sizes();
	$skipped_sizes = isset( $general['skip_size'] ) ? (array) $general['skip_size'] : array();

	// Resume detection: a task is mid-flight if the stored status is one of
	// the working states — the cron chain keeps it moving whether or not
	// this page stays open.
	$media_task    = Aero_IO_Options::get_option( 'aero_io_image_opt_task', array() );
	$media_active  = ( is_array( $media_task ) && isset( $media_task['status'] ) && in_array( $media_task['status'], array( 'init', 'running', 'completed', 'timeout' ), true ) );
	$custom_task   = Aero_IO_Options::get_option( 'aero_io_custom_image_opt_task', array() );
	$custom_active = ( is_array( $custom_task ) && isset( $custom_task['status'] ) && in_array( $custom_task['status'], array( 'init', 'running', 'completed', 'timeout' ), true ) );

	$server_labels = array(
		'apache'    => 'Apache',
		'litespeed' => 'LiteSpeed',
		'nginx'     => 'Nginx',
		'unknown'   => __( 'Unknown server', 'aero' ),
	);
	$mode_labels   = array(
		'htaccess'        => __( '.htaccess rewrite', 'aero' ),
		'compat_htaccess' => __( '.htaccess rewrite (compat)', 'aero' ),
		'picture'         => __( 'Picture tags (PHP)', 'aero' ),
	);

	$boot = array(
		'ajaxurl'      => admin_url( 'admin-ajax.php' ),
		'nonce'        => wp_create_nonce( 'aero_io_ajax' ),
		'mediaActive'  => $media_active,
		'customActive' => $custom_active,
		'enabled'      => $enabled,
		'complete'     => $coverage['complete'],
		'i18n'         => array(
			'scanning'       => __( 'Scanning media library…', 'aero' ),
			'scanDone'       => __( 'Scan complete', 'aero' ),
			'noImages'       => __( 'Nothing to do', 'aero' ),
			'optimizing'     => __( 'Optimizing in background…', 'aero' ),
			'resumed'        => __( 'Resumed — optimization is running in the background', 'aero' ),
			'bgNote'         => __( 'You can close this page; the optimizer keeps running on the server.', 'aero' ),
			'done'           => __( 'Bulk optimization finished', 'aero' ),
			'cancelled'      => __( 'Cancelled', 'aero' ),
			'failed'         => __( 'Request failed — check the log for details.', 'aero' ),
			'restoreDone'    => __( 'All generated WebP/AVIF files and optimization data were removed.', 'aero' ),
			'testWorkingRw'  => __( 'Delivery is working — the browser received the converted file.', 'aero' ),
			'testWorkingPic' => __( 'Picture delivery is active — image markup is rewritten in PHP at render time. No server rules needed.', 'aero' ),
			'testBrokenRw'   => __( 'Rewrite delivery is NOT working on this server. Switch to picture-tag delivery below, or add the rules to the server config.', 'aero' ),
			'testBrokenPic'  => __( 'The output folder is not writable — conversions cannot be stored.', 'aero' ),
			'confirmForce'   => __( 'Re-optimize ALL images from scratch? Existing WebP/AVIF files will be rebuilt.', 'aero' ),
			'confirmRestore' => __( 'Delete every generated WebP/AVIF file and all optimization data? Originals are untouched. This cannot be undone.', 'aero' ),
			'confirmLogs'    => __( 'Delete all Image Optimizer logs?', 'aero' ),
			'folderAdded'    => __( 'Folder added.', 'aero' ),
			'folderRemoved'  => __( 'Folder removed.', 'aero' ),
			'customScanned'  => __( 'custom images found.', 'aero' ),
			'customDone'     => __( 'Custom folder optimization finished', 'aero' ),
			'statsPending'   => __( 'calculating…', 'aero' ),
			'allOptimized'   => __( 'All images optimized', 'aero' ),
			'nothingLeft'    => __( 'Every image in the library has been converted. New uploads are handled automatically.', 'aero' ),
			'cssCleared'     => __( 'Processed stylesheets cleared. They are rebuilt on the next page view.', 'aero' ),
			'replaceScan'    => __( 'Checking where this image URL appears…', 'aero' ),
			'replaceNone'    => __( 'That URL was not found anywhere in the database.', 'aero' ),
			'replaceApplied' => __( 'Replacement complete.', 'aero' ),
			'replaceConfirm' => __( 'Replace this image URL everywhere it appears? Content, custom fields and options will be rewritten. Take a database backup first if you are unsure.', 'aero' ),
			'toggleOn'       => __( 'Switching the image optimizer on…', 'aero' ),
			'toggleOff'      => __( 'Switching the image optimizer off…', 'aero' ),
		),
	);
	?>
	<script>window.aeroIo = <?php echo wp_json_encode( $boot ); ?>;</script>

	<!-- ═══ Master switch ═══ -->
	<div class="aero-section">
		<div class="aero-eyebrow"><?php esc_html_e( 'Image Optimizer', 'aero' ); ?></div>
		<div class="aero-io-master <?php echo $enabled ? 'is-on' : 'is-off'; ?>">
			<label class="aero-io-switch">
				<input type="checkbox" id="aero-io-master-toggle" <?php checked( $enabled ); ?> />
				<span class="aero-io-switch-track"><span class="aero-io-switch-thumb"></span></span>
			</label>
			<div class="aero-io-master-copy">
				<div class="aero-io-master-title" id="aero-io-master-title">
					<?php echo $enabled ? esc_html__( 'Image optimization is on', 'aero' ) : esc_html__( 'Image optimization is off', 'aero' ); ?>
				</div>
				<div class="aero-io-master-sub" id="aero-io-master-sub">
					<?php
					echo $enabled
						? esc_html__( 'Aero converts images, processes uploads in the background and serves next-gen formats.', 'aero' )
						: esc_html__( 'Nothing is converted and no delivery rewriting happens. Files already generated stay on disk, so switching back on resumes exactly where you left off.', 'aero' );
					?>
				</div>
			</div>
		</div>

		<?php if ( ! empty( $conflicts ) ) : ?>
		<div class="aero-info-box warn" style="margin-top:16px;">
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
			<span>
				<strong><?php esc_html_e( 'Another image optimizer is active.', 'aero' ); ?></strong>
				<?php
				printf(
					/* translators: %s: comma separated plugin names */
					esc_html__( 'Aero found %s running alongside its own optimizer. Two plugins converting the same files and rewriting the same delivery layer will interfere with each other. Keep one: either deactivate the other plugin, or switch Aero\'s optimizer off above.', 'aero' ),
					esc_html( implode( ', ', array_values( $conflicts ) ) )
				);
				?>
			</span>
		</div>
		<?php endif; ?>
	</div>

	<!-- ═══ Image Statistics — same band pattern as the Optimization screen ═══ -->
	<div class="aero-section">
		<div class="aero-eyebrow"><?php esc_html_e( 'Image Statistics', 'aero' ); ?></div>
		<div class="aero-stats-band" id="aero-io-band">
			<div class="aero-donut-wrap">
				<svg class="aero-donut" viewBox="0 0 120 120" role="img" aria-label="<?php esc_attr_e( 'Optimized image composition', 'aero' ); ?>">
					<circle class="aero-donut-track" cx="60" cy="60" r="48"></circle>
					<circle class="aero-donut-seg aero-io-donut-webp" id="aero-io-donut-webp" cx="60" cy="60" r="48"></circle>
					<circle class="aero-donut-seg aero-io-donut-avif" id="aero-io-donut-avif" cx="60" cy="60" r="48"></circle>
					<text class="aero-donut-total" id="aero-io-donut-total" x="60" y="57" text-anchor="middle">…</text>
					<text class="aero-donut-caption" x="60" y="72" text-anchor="middle"><?php esc_html_e( 'OPTIMIZED', 'aero' ); ?></text>
				</svg>
				<div class="aero-donut-legend">
					<span class="aero-legend-item"><span class="aero-legend-swatch aero-io-sw-webp"></span> WEBP <em id="aero-io-legend-webp">—</em></span>
					<span class="aero-legend-item"><span class="aero-legend-swatch aero-io-sw-avif"></span> AVIF <em id="aero-io-legend-avif">—</em></span>
				</div>
			</div>
			<div class="aero-stat-tiles">
				<div class="aero-stat-tile"><span class="aero-stat-val" id="aero-io-stat-saved">…</span><span class="aero-stat-label"><?php esc_html_e( 'Space Saved', 'aero' ); ?></span></div>
				<div class="aero-stat-tile"><span class="aero-stat-val" id="aero-io-stat-ratio">…</span><span class="aero-stat-label"><?php esc_html_e( 'Avg. Compression', 'aero' ); ?></span></div>
				<div class="aero-stat-tile"><span class="aero-stat-val" id="aero-io-stat-tree"><?php echo esc_html( size_format( $tree_size, 1 ) ); ?></span><span class="aero-stat-label"><?php esc_html_e( 'Generated Files', 'aero' ); ?></span></div>
				<div class="aero-stat-tile"><span class="aero-stat-val <?php echo ( $caps['webp'] || $caps['avif'] ) ? '' : 'aero-io-err'; ?>"><?php echo esc_html( strtoupper( $converter ) ); ?></span><span class="aero-stat-label"><?php echo esc_html( sprintf( /* translators: 1: WebP yes/no 2: AVIF yes/no */ __( 'Engine · WebP %1$s · AVIF %2$s', 'aero' ), $caps['webp'] ? '✓' : '✕', $caps['avif'] ? '✓' : '✕' ) ); ?></span></div>
				<div class="aero-stat-tile aero-stat-tile-wide">
					<span class="aero-stat-val aero-stat-val-sm" id="aero-io-stat-delivery"><?php echo esc_html( $mode_labels[ $mode ] . ' · ' . $server_labels[ $server ] ); ?></span>
					<span class="aero-stat-label"><?php esc_html_e( 'Delivery', 'aero' ); ?></span>
				</div>
			</div>
		</div>
	</div>

	<!-- ═══ Bulk optimization ═══ -->
	<div class="aero-section">
		<div class="aero-eyebrow"><?php esc_html_e( 'Bulk Optimization', 'aero' ); ?><span class="aero-eyebrow-aside"><?php esc_html_e( 'runs server-side — closing this page will not stop it', 'aero' ); ?></span></div>

		<?php
		// Resting state. A finished library must read as finished on every
		// load: a bar reset to zero would suggest nothing had been done.
		$rest_complete = $coverage['complete'] && ! $media_active;
		$rest_pct      = $rest_complete ? 100 : 0;
		$rest_title    = $rest_complete ? __( 'All images optimized', 'aero' ) : __( 'Ready', 'aero' );
		$rest_text     = $rest_complete
			? sprintf(
				/* translators: %s: number of optimized images */
				__( '%s images converted. New uploads are handled automatically.', 'aero' ),
				number_format_i18n( $coverage['optimized'] )
			)
			: __( 'Scan the library first, then start optimization.', 'aero' );
		?>
		<div class="aero-io-panel<?php echo $rest_complete ? ' is-complete' : ''; ?>" id="aero-io-bulk-panel">
			<div class="aero-io-progress-head">
				<div class="aero-io-progress-title" id="aero-io-bulk-title"><?php echo esc_html( $rest_title ); ?></div>
				<div class="aero-io-progress-pct" id="aero-io-bulk-pct"><?php echo (int) $rest_pct; ?>%</div>
			</div>
			<div class="aero-progress aero-io-progress"><div class="aero-progress-fill" id="aero-io-bulk-fill" style="width:<?php echo (int) $rest_pct; ?>%"></div></div>
			<div class="aero-io-progress-meta">
				<span id="aero-io-bulk-text"><?php echo esc_html( $rest_text ); ?></span>
				<span id="aero-io-bulk-sub"></span>
			</div>
			<div class="aero-io-counts" id="aero-io-counts" style="display:none;">
				<span><?php esc_html_e( 'Optimized', 'aero' ); ?> <strong id="aero-io-count-ok">0</strong></span>
				<span><?php esc_html_e( 'Remaining', 'aero' ); ?> <strong id="aero-io-count-rem">0</strong></span>
				<span><?php esc_html_e( 'Failed', 'aero' ); ?> <strong id="aero-io-count-fail">0</strong></span>
			</div>

			<div class="aero-actions" style="margin-top:16px;">
				<button type="button" class="aero-btn aero-btn-ghost" id="aero-io-scan"><?php esc_html_e( 'Scan Library', 'aero' ); ?></button>
				<button type="button" class="aero-btn aero-btn-primary" id="aero-io-start"><?php esc_html_e( 'Optimize All', 'aero' ); ?></button>
				<button type="button" class="aero-btn aero-btn-ghost" id="aero-io-force"><?php esc_html_e( 'Re-optimize Everything', 'aero' ); ?></button>
				<button type="button" class="aero-btn aero-btn-danger" id="aero-io-cancel" style="display:none;"><?php esc_html_e( 'Cancel', 'aero' ); ?></button>
				<button type="button" class="aero-btn aero-btn-ghost aero-btn-sm" id="aero-io-toggle-log"><?php esc_html_e( 'View Log', 'aero' ); ?></button>
			</div>
			<pre class="aero-io-log" id="aero-io-live-log" style="display:none;"></pre>
		</div>
	</div>

	<hr class="aero-divider" />

	<!-- ═══ Settings ═══ -->
	<form method="post" action="">
		<?php wp_nonce_field( 'aero_io_settings_save', 'aero_io_settings_nonce' ); ?>
		<input type="hidden" name="aero_ui_screen" value="aero-images" />

		<div class="aero-section">
			<div class="aero-eyebrow"><?php esc_html_e( 'Formats & Quality', 'aero' ); ?></div>

			<div class="aero-check-list">
				<label class="aero-check-row">
					<input type="checkbox" name="aero_io_webp" <?php checked( $webp_on && $caps['webp'] ); ?> <?php disabled( ! $caps['webp'] ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Convert to WebP', 'aero' ); ?></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Generate a .webp derivative for every JPG/PNG (and compress originals already in WebP). Universally supported by modern browsers.', 'aero' ); ?></span>
					</span>
				</label>
				<label class="aero-check-row">
					<input type="checkbox" name="aero_io_avif" <?php checked( $avif_on && $caps['avif'] ); ?> <?php disabled( ! $caps['avif'] ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Convert to AVIF', 'aero' ); ?></span>
						<span class="aero-check-sub"><?php echo $caps['avif'] ? esc_html__( 'Smaller than WebP at the same visual quality; AVIF is served first when the browser accepts it.', 'aero' ) : esc_html__( 'This server cannot encode AVIF (no GD/Imagick AVIF support).', 'aero' ); ?></span>
					</span>
				</label>
			</div>

			<div class="aero-field-grid aero-field-grid-3">
				<div class="aero-field">
					<label class="aero-label" for="aero-io-quality"><?php esc_html_e( 'Quality Preset', 'aero' ); ?></label>
					<select class="aero-input" name="aero_io_quality" id="aero-io-quality">
						<option value="lossless" <?php selected( $quality['quality'], 'lossless' ); ?>><?php esc_html_e( 'Lossless', 'aero' ); ?></option>
						<option value="lossy_minus" <?php selected( $quality['quality'], 'lossy_minus' ); ?>><?php esc_html_e( 'Lossy — Gentle', 'aero' ); ?></option>
						<option value="lossy" <?php selected( $quality['quality'], 'lossy' ); ?>><?php esc_html_e( 'Lossy — Balanced (recommended)', 'aero' ); ?></option>
						<option value="lossy_plus" <?php selected( $quality['quality'], 'lossy_plus' ); ?>><?php esc_html_e( 'Lossy — Strong', 'aero' ); ?></option>
						<option value="lossy_super" <?php selected( $quality['quality'], 'lossy_super' ); ?>><?php esc_html_e( 'Lossy — Maximum', 'aero' ); ?></option>
						<option value="custom" <?php selected( $quality['quality'], 'custom' ); ?>><?php esc_html_e( 'Custom', 'aero' ); ?></option>
					</select>
					<p class="aero-hint"><?php esc_html_e( 'Balanced is visually indistinguishable for photos while cutting 60–80% of the bytes.', 'aero' ); ?></p>
				</div>
				<div class="aero-field aero-io-custom-q" <?php echo ( 'custom' === $quality['quality'] ) ? '' : 'style="display:none;"'; ?>>
					<label class="aero-label" for="aero-io-qw"><?php esc_html_e( 'WebP Quality (1–100)', 'aero' ); ?></label>
					<input class="aero-input" type="number" min="1" max="100" name="aero_io_quality_webp" id="aero-io-qw" value="<?php echo esc_attr( isset( $quality['quality_webp'] ) ? $quality['quality_webp'] : 80 ); ?>" />
				</div>
				<div class="aero-field aero-io-custom-q" <?php echo ( 'custom' === $quality['quality'] ) ? '' : 'style="display:none;"'; ?>>
					<label class="aero-label" for="aero-io-qa"><?php esc_html_e( 'AVIF Quality (1–100)', 'aero' ); ?></label>
					<input class="aero-input" type="number" min="1" max="100" name="aero_io_quality_avif" id="aero-io-qa" value="<?php echo esc_attr( isset( $quality['quality_avif'] ) ? $quality['quality_avif'] : 60 ); ?>" />
				</div>
			</div>

			<div class="aero-field-grid">
				<div class="aero-field">
					<label class="aero-label" for="aero-io-converter"><?php esc_html_e( 'Converter', 'aero' ); ?></label>
					<select class="aero-input" name="aero_io_converter_method" id="aero-io-converter">
						<option value="gd" <?php selected( $converter, 'gd' ); ?> <?php disabled( ! $caps['gd'] ); ?>>GD<?php echo $caps['gd'] ? '' : ' — ' . esc_html__( 'not available', 'aero' ); ?></option>
						<option value="imagick" <?php selected( $converter, 'imagick' ); ?> <?php disabled( ! $caps['imagick'] ); ?>>Imagick<?php echo $caps['imagick'] ? '' : ' — ' . esc_html__( 'not available', 'aero' ); ?></option>
					</select>
					<p class="aero-hint">
						<?php
						printf(
							/* translators: 1: GD WebP yes/no, 2: GD AVIF yes/no, 3: Imagick WebP yes/no, 4: Imagick AVIF yes/no */
							esc_html__( 'Detected — GD: WebP %1$s, AVIF %2$s · Imagick: WebP %3$s, AVIF %4$s', 'aero' ),
							$caps['gd_webp'] ? '✓' : '✕',
							$caps['gd_avif'] ? '✓' : '✕',
							$caps['imagick_webp'] ? '✓' : '✕',
							$caps['imagick_avif'] ? '✓' : '✕'
						);
						?>
					</p>
				</div>
				<div class="aero-field">
					<label class="aero-label" for="aero-io-batch"><?php esc_html_e( 'Scan Batch Size', 'aero' ); ?></label>
					<input class="aero-input" type="number" min="50" max="2000" name="aero_io_scan_batch" id="aero-io-batch" value="<?php echo esc_attr( isset( $general_raw['scan_images_page'] ) ? $general_raw['scan_images_page'] : 500 ); ?>" />
					<p class="aero-hint"><?php esc_html_e( 'Attachments examined per scan request. Lower it on constrained servers.', 'aero' ); ?></p>
				</div>
			</div>
		</div>

		<hr class="aero-divider" />

		<div class="aero-section">
			<div class="aero-eyebrow"><?php esc_html_e( 'Processing Rules', 'aero' ); ?></div>

			<div class="aero-check-list">
				<label class="aero-check-row">
					<input type="checkbox" name="aero_io_auto_optimize" <?php checked( $auto_optimize ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Auto-optimize new uploads', 'aero' ); ?></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Every image added to the media library is converted immediately after WordPress finishes generating thumbnails.', 'aero' ); ?></span>
					</span>
				</label>
				<label class="aero-check-row">
					<input type="checkbox" name="aero_io_auto_remove_larger" <?php checked( ! empty( $general['auto_remove_larger_format'] ) ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Auto-remove larger conversions', 'aero' ); ?></span>
						<span class="aero-check-sub"><?php esc_html_e( 'If a converted file ends up bigger than the original (common with tiny PNGs), delete it so the smaller original keeps being served.', 'aero' ); ?></span>
					</span>
				</label>
				<label class="aero-check-row">
					<input type="checkbox" name="aero_io_remove_exif" <?php checked( ! empty( $general['remove_exif'] ) ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Strip EXIF metadata', 'aero' ); ?></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Remove camera metadata (GPS, device, timestamps) from converted files — smaller and more private.', 'aero' ); ?></span>
					</span>
				</label>
				<label class="aero-check-row">
					<input type="checkbox" name="aero_io_resize_enable" id="aero-io-resize-enable" <?php checked( ! empty( $general['resize_enable'] ) ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Resize oversized originals', 'aero' ); ?></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Downscale huge camera uploads before conversion. The original file itself is resized.', 'aero' ); ?></span>
					</span>
				</label>
				<label class="aero-check-row">
					<input type="checkbox" name="aero_io_purge_after_bulk" <?php checked( $purge_after_bulk ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Flush Aero caches after optimization', 'aero' ); ?></span>
						<span class="aero-check-sub"><?php esc_html_e( 'When optimization work finishes, run the sequential purge (object cache → Batcache → Edge) so pages immediately serve the new formats.', 'aero' ); ?></span>
					</span>
				</label>
			</div>

			<div class="aero-field-grid">
				<div class="aero-field">
					<label class="aero-label" for="aero-io-rw"><?php esc_html_e( 'Max Width (px)', 'aero' ); ?></label>
					<input class="aero-input" type="number" min="100" max="10000" name="aero_io_resize_width" id="aero-io-rw" value="<?php echo esc_attr( isset( $general['resize_width'] ) ? $general['resize_width'] : 2560 ); ?>" />
				</div>
				<div class="aero-field">
					<label class="aero-label" for="aero-io-rh"><?php esc_html_e( 'Max Height (px)', 'aero' ); ?></label>
					<input class="aero-input" type="number" min="100" max="10000" name="aero_io_resize_height" id="aero-io-rh" value="<?php echo esc_attr( isset( $general['resize_height'] ) ? $general['resize_height'] : 2560 ); ?>" />
				</div>
			</div>

			<div class="aero-field">
				<span class="aero-label"><?php esc_html_e( 'Format Exceptions', 'aero' ); ?></span>
				<div class="aero-check-list">
					<label class="aero-check-row">
						<input type="checkbox" name="aero_io_exclude_png" <?php checked( ! empty( $general['exclude_png'] ) ); ?> />
						<span class="aero-check-main"><span class="aero-check-title"><?php esc_html_e( 'Skip PNG entirely', 'aero' ); ?></span><span class="aero-check-sub"><?php esc_html_e( 'Never convert PNG files (logos and screenshots with sharp text sometimes fare better untouched).', 'aero' ); ?></span></span>
					</label>
					<label class="aero-check-row">
						<input type="checkbox" name="aero_io_exclude_png_webp" <?php checked( ! empty( $general['exclude_png_webp'] ) ); ?> />
						<span class="aero-check-main"><span class="aero-check-title"><?php esc_html_e( 'PNG → skip WebP only', 'aero' ); ?></span><span class="aero-check-sub"><?php esc_html_e( 'PNGs still get AVIF but no WebP derivative.', 'aero' ); ?></span></span>
					</label>
					<label class="aero-check-row">
						<input type="checkbox" name="aero_io_exclude_jpg_webp" <?php checked( ! empty( $general['exclude_jpg_webp'] ) ); ?> />
						<span class="aero-check-main"><span class="aero-check-title"><?php esc_html_e( 'JPG → skip WebP only', 'aero' ); ?></span><span class="aero-check-sub"><?php esc_html_e( 'JPGs still get AVIF but no WebP derivative.', 'aero' ); ?></span></span>
					</label>
					<label class="aero-check-row">
						<input type="checkbox" name="aero_io_exclude_jpg_avif" <?php checked( ! empty( $general['exclude_jpg_avif'] ) ); ?> />
						<span class="aero-check-main"><span class="aero-check-title"><?php esc_html_e( 'JPG → skip AVIF only', 'aero' ); ?></span><span class="aero-check-sub"><?php esc_html_e( 'JPGs still get WebP but no AVIF derivative.', 'aero' ); ?></span></span>
					</label>
				</div>
			</div>

			<?php if ( ! empty( $sizes ) ) : ?>
			<div class="aero-field">
				<span class="aero-label"><?php esc_html_e( 'Skip Thumbnail Sizes', 'aero' ); ?></span>
				<div class="aero-io-size-grid">
					<?php foreach ( $sizes as $size ) : ?>
						<label class="aero-io-size-chip">
							<input type="checkbox" name="aero_io_skip_size[]" value="<?php echo esc_attr( $size ); ?>" <?php checked( in_array( $size, $skipped_sizes, true ) ); ?> />
							<span><?php echo esc_html( $size ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="aero-hint"><?php esc_html_e( 'Checked sizes are excluded from conversion. The original ("og") is always processed.', 'aero' ); ?></p>
			</div>
			<?php endif; ?>

			<div class="aero-field">
				<label class="aero-label" for="aero-io-excludes"><?php esc_html_e( 'Excluded Media Folders', 'aero' ); ?></label>
				<textarea class="aero-input aero-code-textarea" name="aero_io_media_excludes" id="aero-io-excludes" rows="3" placeholder="2023/private&#10;client-assets"><?php echo esc_textarea( implode( "\n", $exclude_rel ) ); ?></textarea>
				<p class="aero-hint"><?php esc_html_e( 'One folder per line, relative to the uploads directory. Images inside these folders are never processed — by bulk runs or on upload.', 'aero' ); ?></p>
			</div>
		</div>

		<hr class="aero-divider" />

		<div class="aero-section">
			<div class="aero-eyebrow"><?php esc_html_e( 'Delivery', 'aero' ); ?><span class="aero-eyebrow-aside"><?php echo esc_html( sprintf( /* translators: %s: server name */ __( 'detected server: %s', 'aero' ), $server_labels[ $server ] ) ); ?></span></div>

			<?php if ( ! $ht_ok ) : ?>
			<div class="aero-info-box" style="margin-bottom:16px;">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
				<span><?php echo esc_html( sprintf( /* translators: %s: server name */ __( '%s ignores .htaccess files, so Aero delivers converted images through picture tags: pure PHP at render time, no server configuration, no php.ini and no wp-config changes needed. This was selected automatically.', 'aero' ), $server_labels[ $server ] ) ); ?></span>
			</div>
			<?php endif; ?>

			<div class="aero-field">
				<label class="aero-label" for="aero-io-mode"><?php esc_html_e( 'Delivery Method', 'aero' ); ?></label>
				<select class="aero-input" name="aero_io_image_load" id="aero-io-mode">
					<option value="picture" <?php selected( $mode, 'picture' ); ?>><?php esc_html_e( 'Picture tags — PHP render-time rewrite (works on every server)', 'aero' ); ?></option>
					<option value="htaccess" <?php selected( $mode, 'htaccess' ); ?> <?php disabled( ! $ht_ok ); ?>><?php echo esc_html( __( '.htaccess rewrite — same URLs, server negotiates the format', 'aero' ) . ( $ht_ok ? '' : ' — ' . __( 'unavailable on this server', 'aero' ) ) ); ?></option>
					<option value="compat_htaccess" <?php selected( $mode, 'compat_htaccess' ); ?> <?php disabled( ! $ht_ok ); ?>><?php echo esc_html( __( '.htaccess rewrite — compatibility variant (CDN / unusual roots)', 'aero' ) . ( $ht_ok ? '' : ' — ' . __( 'unavailable on this server', 'aero' ) ) ); ?></option>
				</select>
				<p class="aero-hint"><?php esc_html_e( 'Picture mode wraps content images in picture elements with AVIF and WebP sources, and augments CSS backgrounds in inline styles and style blocks with image-set() so the browser picks the best format it supports. Backgrounds referenced inside external stylesheet files keep their original URLs. Rewrite modes keep original URLs and cover everything transparently, but require Apache or LiteSpeed.', 'aero' ); ?></p>
			</div>

			<div class="aero-check-list" style="margin-top:16px;">
				<label class="aero-check-row">
					<input type="checkbox" name="aero_io_css_files" <?php checked( $css_files ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Rewrite backgrounds in stylesheet files', 'aero' ); ?></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Page builders write their section CSS to real files (Elementor into the uploads folder, Divi into et-cache, and so on) which never pass through the page output. Aero processes each local stylesheet once into a cached copy, making relative paths absolute and wrapping background images in image-set(). Only applies in picture mode.', 'aero' ); ?></span>
					</span>
				</label>
				<label class="aero-check-row">
					<input type="checkbox" name="aero_io_lazy_bg" <?php checked( $lazy_bg ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Handle lazy-loaded background attributes', 'aero' ); ?></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Lazy loaders keep background URLs in data attributes and apply them with their own JavaScript, out of reach of any server-side rewrite. Aero tags those elements with the derivatives that exist, and a small script swaps them once it knows which formats the browser can decode.', 'aero' ); ?></span>
					</span>
				</label>
			</div>

			<div class="aero-actions">
				<button type="button" class="aero-btn aero-btn-ghost" id="aero-io-test-delivery"><?php esc_html_e( 'Test Delivery', 'aero' ); ?></button>
				<button type="button" class="aero-btn aero-btn-ghost" id="aero-io-clear-css"><?php esc_html_e( 'Rebuild Stylesheet Cache', 'aero' ); ?></button>
				<span class="aero-io-test-result" id="aero-io-test-result"></span>
			</div>

			<?php if ( 'nginx' === $server ) : ?>
			<details class="aero-io-details">
				<summary><?php esc_html_e( 'Advanced: serve rewrites from Nginx directly (optional)', 'aero' ); ?></summary>
				<p class="aero-hint"><?php esc_html_e( 'If you control the Nginx config, content-negotiated rewrites are slightly faster than picture tags. Add the map to the http block and the location to the server block, then switch nothing here — Aero detects working rewrites via Test Delivery. Note the fallback goes straight to the original when the preferred format is missing.', 'aero' ); ?></p>
				<pre class="aero-io-log" style="display:block;margin-top:10px;">map $http_accept $aero_img_suffix {
    default        "";
    "~image/avif"  ".avif";
    "~image/webp"  ".webp";
}

location ~* ^/wp-content/(?!aero-nextgen/)(.+\.(?:jpe?g|png|webp))$ {
    add_header Vary Accept;
    try_files /wp-content/aero-nextgen/$1$aero_img_suffix $uri =404;
}</pre>
			</details>
			<?php endif; ?>
		</div>

		<hr class="aero-divider" />

		<div class="aero-section">
			<div class="aero-eyebrow"><?php esc_html_e( 'Media Replacement', 'aero' ); ?></div>
			<div class="aero-check-list">
				<label class="aero-check-row">
					<input type="checkbox" name="aero_io_replace_enable" <?php checked( $replace_enable ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Enable "Replace Media" on attachment pages', 'aero' ); ?></span>
						<span class="aero-check-sub"><?php esc_html_e( 'Swap the file behind an attachment without changing its URL or ID. Thumbnails are regenerated automatically.', 'aero' ); ?></span>
					</span>
				</label>
				<label class="aero-check-row">
					<input type="checkbox" name="aero_io_replace_reoptimize" <?php checked( $replace_reoptimize ); ?> />
					<span class="aero-check-main">
						<span class="aero-check-title"><?php esc_html_e( 'Re-optimize after replacement', 'aero' ); ?></span>
						<span class="aero-check-sub"><?php esc_html_e( 'The replacement is resized, converted and compressed with the rules above, and Aero caches are flushed so the new image shows up everywhere.', 'aero' ); ?></span>
					</span>
				</label>
			</div>
		</div>

		<div class="aero-actions">
			<button type="submit" class="aero-btn aero-btn-primary"><?php esc_html_e( 'Save Settings', 'aero' ); ?></button>
		</div>
	</form>

	<hr class="aero-divider" />

	<!-- ═══ Image URL replace ═══ -->
	<div class="aero-section">
		<div class="aero-eyebrow"><?php esc_html_e( 'Image URL Replace', 'aero' ); ?><span class="aero-eyebrow-aside"><?php esc_html_e( 'swap one image for another everywhere it is used', 'aero' ); ?></span></div>

		<p class="aero-hint" style="margin-bottom:14px;">
			<?php esc_html_e( 'Point every reference to one image at a different image. Post content, custom fields, term meta and options are all rewritten, including page builder payloads: Elementor stores its layout as JSON with escaped slashes, and that form is handled too. Serialized values are unpacked and rebuilt rather than string-replaced, so nothing is corrupted when the new URL is a different length.', 'aero' ); ?>
		</p>

		<div class="aero-field-grid">
			<div class="aero-field">
				<label class="aero-label" for="aero-io-url-from"><?php esc_html_e( 'Current image URL', 'aero' ); ?></label>
				<input class="aero-input" type="url" id="aero-io-url-from" placeholder="<?php echo esc_attr( content_url() . '/uploads/2024/05/old-hero.jpg' ); ?>" />
			</div>
			<div class="aero-field">
				<label class="aero-label" for="aero-io-url-to"><?php esc_html_e( 'Replacement image URL', 'aero' ); ?></label>
				<input class="aero-input" type="url" id="aero-io-url-to" placeholder="<?php echo esc_attr( content_url() . '/uploads/2026/08/new-hero.jpg' ); ?>" />
			</div>
		</div>

		<div class="aero-actions">
			<button type="button" class="aero-btn aero-btn-ghost" id="aero-io-url-scan"><?php esc_html_e( 'Check Usage', 'aero' ); ?></button>
			<button type="button" class="aero-btn aero-btn-primary" id="aero-io-url-apply" disabled><?php esc_html_e( 'Replace Everywhere', 'aero' ); ?></button>
		</div>
		<div class="aero-io-test-result" id="aero-io-url-result"></div>
	</div>

	<hr class="aero-divider" />

	<!-- ═══ Custom folders ═══ -->
	<div class="aero-section">
		<div class="aero-eyebrow"><?php esc_html_e( 'Custom Folders', 'aero' ); ?><span class="aero-eyebrow-aside"><?php esc_html_e( 'optimize images outside the media library', 'aero' ); ?></span></div>

		<div class="aero-io-addbar">
			<input type="text" class="aero-input" id="aero-io-custom-path" placeholder="<?php echo esc_attr( 'themes/my-theme/images  —  ' . __( 'relative to wp-content, or an absolute path', 'aero' ) ); ?>" />
			<button type="button" class="aero-btn aero-btn-ghost" id="aero-io-custom-add"><?php esc_html_e( 'Add Folder', 'aero' ); ?></button>
		</div>

		<table class="aero-table aero-io-custom-table" id="aero-io-custom-table" <?php echo empty( $custom_folders ) ? 'style="display:none;"' : ''; ?>>
			<thead><tr><th><?php esc_html_e( 'Folder', 'aero' ); ?></th><th style="width:90px;"></th></tr></thead>
			<tbody id="aero-io-custom-body">
				<?php foreach ( $custom_folders as $folder ) : ?>
					<tr data-path="<?php echo esc_attr( $folder ); ?>"><td><code><?php echo esc_html( $folder ); ?></code></td><td><button type="button" class="aero-btn aero-btn-danger aero-btn-sm aero-io-custom-remove"><?php esc_html_e( 'Remove', 'aero' ); ?></button></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="aero-io-panel" style="margin-top:14px;">
			<div class="aero-io-progress-head">
				<div class="aero-io-progress-title" id="aero-io-custom-title"><?php esc_html_e( 'Ready', 'aero' ); ?></div>
				<div class="aero-io-progress-pct" id="aero-io-custom-pct">0%</div>
			</div>
			<div class="aero-progress aero-io-progress"><div class="aero-progress-fill" id="aero-io-custom-fill" style="width:0%"></div></div>
			<div class="aero-io-progress-meta"><span id="aero-io-custom-text"><?php esc_html_e( 'Scan the folders above, then optimize.', 'aero' ); ?></span></div>
			<div class="aero-actions" style="margin-top:16px;">
				<button type="button" class="aero-btn aero-btn-ghost" id="aero-io-custom-scan"><?php esc_html_e( 'Scan Folders', 'aero' ); ?></button>
				<button type="button" class="aero-btn aero-btn-primary" id="aero-io-custom-start"><?php esc_html_e( 'Optimize Custom Folders', 'aero' ); ?></button>
			</div>
		</div>
	</div>

	<hr class="aero-divider" />

	<!-- ═══ Logs ═══ -->
	<div class="aero-section">
		<div class="aero-eyebrow"><?php esc_html_e( 'Logs', 'aero' ); ?></div>
		<div class="aero-actions" style="margin-bottom:12px;">
			<button type="button" class="aero-btn aero-btn-ghost aero-btn-sm" id="aero-io-logs-refresh"><?php esc_html_e( 'Refresh List', 'aero' ); ?></button>
			<button type="button" class="aero-btn aero-btn-danger aero-btn-sm" id="aero-io-logs-clear"><?php esc_html_e( 'Delete All Logs', 'aero' ); ?></button>
		</div>
		<table class="aero-table" id="aero-io-logs-table" style="display:none;">
			<thead><tr><th><?php esc_html_e( 'File', 'aero' ); ?></th><th style="width:120px;"><?php esc_html_e( 'Modified', 'aero' ); ?></th><th style="width:80px;"><?php esc_html_e( 'Size', 'aero' ); ?></th><th style="width:200px;"></th></tr></thead>
			<tbody id="aero-io-logs-body"></tbody>
		</table>
		<div class="aero-io-empty" id="aero-io-logs-empty"><?php esc_html_e( 'No logs yet.', 'aero' ); ?></div>
		<pre class="aero-io-log" id="aero-io-log-view" style="display:none;"></pre>
	</div>

	<hr class="aero-divider" />

	<!-- ═══ Restore ═══ -->
	<div class="aero-section">
		<div class="aero-eyebrow"><?php esc_html_e( 'Restore', 'aero' ); ?><span class="aero-eyebrow-aside"><?php esc_html_e( 'originals are never modified — this simply deletes the generated files', 'aero' ); ?></span></div>
		<div class="aero-actions">
			<button type="button" class="aero-btn aero-btn-danger" id="aero-io-restore"><?php esc_html_e( 'Delete All Generated Files', 'aero' ); ?></button>
			<span id="aero-io-restore-result"></span>
		</div>
	</div>
	<?php
}

// ─── Replace Media box (attachment edit screen) ──────────────────────────────
add_action( 'attachment_submitbox_misc_actions', 'aero_io_render_replace_box', 20 );
function aero_io_render_replace_box() {
	$opts   = Aero_IO_Options::get_option( 'aero_io_media_replace', array() );
	$enable = isset( $opts['enable'] ) ? (bool) $opts['enable'] : true;
	if ( ! $enable || ! current_user_can( 'upload_files' ) ) {
		return;
	}

	global $post;
	if ( ! $post || ! wp_attachment_is_image( $post->ID ) ) {
		return;
	}
	?>
	<div class="misc-pub-section aero-io-replace-box">
		<label class="aero-io-replace-label"><strong><?php esc_html_e( 'Replace Media', 'aero' ); ?></strong></label><br />
		<input type="file" id="aero-io-replace-file" accept=".jpg,.jpeg,.png,.gif,.webp,.avif" style="max-width:100%;" />
		<span id="aero-io-replace-name"></span>
		<button type="button" class="button" id="aero-io-replace-go" data-id="<?php echo esc_attr( $post->ID ); ?>" disabled><?php esc_html_e( 'Replace', 'aero' ); ?></button>
		<span id="aero-io-replace-status"></span>
		<p class="description" style="margin-top:6px;"><?php esc_html_e( 'Same URL, same ID — thumbnails regenerate and the image is re-optimized automatically.', 'aero' ); ?></p>
	</div>
	<?php
}
