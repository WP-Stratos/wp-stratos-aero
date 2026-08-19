<?php
/**
 * Aero Image Optimizer — conflicting plugin detection.
 *
 * Two image optimizers running at once is a genuinely bad state: both hook
 * the upload pipeline, both rewrite delivery, and each can convert the other's
 * output or restore files the other just replaced. The result usually looks
 * like "the optimizer is broken" rather than "two plugins are fighting", so
 * the warning is shown across wp-admin rather than only on the Images screen.
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dedicated image optimization plugins. Any of these being active is a
 * straight conflict: converting images is their entire purpose.
 *
 * @return array<string,string>
 */
function aero_io_known_optimizers() {
	return apply_filters(
		'aero_io_known_optimizers',
		array(
			'wp-smushit/wp-smush.php'                                  => 'Smush',
			'wp-smush-pro/wp-smush.php'                                => 'Smush Pro',
			'ewww-image-optimizer/ewww-image-optimizer.php'             => 'EWWW Image Optimizer',
			'ewww-image-optimizer-cloud/ewww-image-optimizer-cloud.php' => 'EWWW Image Optimizer Cloud',
			'shortpixel-image-optimiser/wp-shortpixel.php'              => 'ShortPixel Image Optimizer',
			'shortpixel-adaptive-images/short-pixel-ai.php'             => 'ShortPixel Adaptive Images',
			'imagify/imagify.php'                                       => 'Imagify',
			'optimole-wp/optimole-wp.php'                               => 'Optimole',
			'webp-converter-for-media/webp-converter-for-media.php'     => 'Converter for Media',
			'compressx/compressx.php'                                   => 'CompressX',
			'webp-express/webp-express.php'                             => 'WebP Express',
			'resmushit-image-optimizer/resmushit.php'                   => 'reSmush.it Image Optimizer',
			'robin-image-optimizer/robin-image-optimizer.php'           => 'Robin Image Optimizer',
			'tiny-compress-images/tiny-compress-images.php'             => 'TinyPNG JPEG & PNG Optimization',
			'kraken-image-optimizer/kraken.php'                         => 'Kraken.io Image Optimizer',
			'imagerecycle-pdf-image-compression/wp-image-recycle.php'   => 'ImageRecycle',
			'image-optimization/image-optimization.php'                 => 'Image Optimization by Elementor',
			'wp-webp/wp-webp.php'                                       => 'WP WebP',
		)
	);
}

/**
 * Plugins that ship an OPTIONAL image module. Their presence alone is not a
 * conflict — plenty of sites run LiteSpeed Cache purely for caching — so each
 * is paired with a check for whether the image feature is actually switched
 * on. When a check cannot confirm it, nothing is reported: false alarms here
 * would only teach people to ignore the warning.
 *
 * @return array<string,array>
 */
function aero_io_optional_optimizer_modules() {
	return apply_filters(
		'aero_io_optional_optimizer_modules',
		array(
			'litespeed-cache/litespeed-cache.php' => array(
				'name'  => 'LiteSpeed Cache',
				'label' => __( 'image optimization / WebP delivery', 'aero' ),
				'check' => 'aero_io_litespeed_images_on',
			),
			'wp-optimize/wp-optimize.php'         => array(
				'name'  => 'WP-Optimize',
				'label' => __( 'image compression', 'aero' ),
				'check' => 'aero_io_wpoptimize_images_on',
			),
			'autoptimize/autoptimize.php'         => array(
				'name'  => 'Autoptimize',
				'label' => __( 'image optimization', 'aero' ),
				'check' => 'aero_io_autoptimize_images_on',
			),
			'jetpack/jetpack.php'                 => array(
				'name'  => 'Jetpack',
				'label' => __( 'Site Accelerator image CDN', 'aero' ),
				'check' => 'aero_io_jetpack_photon_on',
			),
			'imsanity/imsanity.php'               => array(
				'name'  => 'Imsanity',
				'label' => __( 'upload resizing', 'aero' ),
				'check' => 'aero_io_always_conflicts',
			),
		)
	);
}

/**
 * Imsanity always resizes originals on upload, which changes what Aero
 * converts, so its presence is always worth flagging.
 *
 * @return bool
 */
function aero_io_always_conflicts() {
	return true;
}

/**
 * LiteSpeed stores its settings as discrete litespeed.conf.* options.
 *
 * @return bool
 */
function aero_io_litespeed_images_on() {
	foreach ( array( 'litespeed.conf.img_optm-auto', 'litespeed.conf.img_optm-webp', 'litespeed.conf.media-webp' ) as $key ) {
		$value = get_option( $key, null );
		if ( null !== $value && ! empty( $value ) ) {
			return true;
		}
	}
	return false;
}

/**
 * WP-Optimize keeps image compression under its own settings arrays.
 *
 * @return bool
 */
function aero_io_wpoptimize_images_on() {
	$settings = get_option( 'wpo_smush_settings', array() );
	if ( is_array( $settings ) && ! empty( $settings['compression_server'] ) ) {
		return true;
	}
	$scheduled = get_option( 'wpo_smush_scheduler_settings', array() );
	return ( is_array( $scheduled ) && ! empty( $scheduled ) );
}

/**
 * Autoptimize's image module is a checkbox inside its own option array.
 *
 * @return bool
 */
function aero_io_autoptimize_images_on() {
	$settings = get_option( 'autoptimize_imgopt_settings', array() );
	return ( is_array( $settings ) && ! empty( $settings['autoptimize_imgopt_checkbox_field_1'] ) );
}

/**
 * Jetpack's image CDN (Photon) is a module toggle.
 *
 * @return bool
 */
function aero_io_jetpack_photon_on() {
	$active = get_option( 'jetpack_active_modules', array() );
	return ( is_array( $active ) && in_array( 'photon', $active, true ) );
}

/**
 * Active conflicting plugins, as [file => name].
 *
 * @return array<string,string>
 */
function aero_io_active_conflicts() {
	static $found = null;
	if ( null !== $found ) {
		return $found;
	}

	$found = array();

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	foreach ( aero_io_known_optimizers() as $file => $name ) {
		if ( is_plugin_active( $file ) ) {
			$found[ $file ] = $name;
		}
	}

	foreach ( aero_io_optional_optimizer_modules() as $file => $module ) {
		if ( ! is_plugin_active( $file ) ) {
			continue;
		}
		// Only a conflict when the image feature itself is switched on.
		if ( ! is_callable( $module['check'] ) || ! call_user_func( $module['check'] ) ) {
			continue;
		}
		$found[ $file ] = $module['name'] . ' (' . $module['label'] . ')';
	}

	return $found;
}

/**
 * A fingerprint of the current conflict set, so dismissing the notice for
 * one plugin does not silence a different one activated later.
 *
 * @param array $conflicts Conflict map.
 * @return string
 */
function aero_io_conflict_signature( $conflicts ) {
	$keys = array_keys( $conflicts );
	sort( $keys );
	return md5( implode( '|', $keys ) );
}

/**
 * Print the cross-admin warning.
 *
 * @return void
 */
function aero_io_conflict_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	if ( ! aero_io_is_enabled() ) {
		return;
	}

	$conflicts = aero_io_active_conflicts();
	if ( empty( $conflicts ) ) {
		return;
	}

	$signature = aero_io_conflict_signature( $conflicts );
	if ( get_user_meta( get_current_user_id(), 'aero_io_conflict_dismissed', true ) === $signature ) {
		return;
	}

	$names = implode( ', ', array_map( 'esc_html', array_values( $conflicts ) ) );
	$count = count( $conflicts ) + 1;

	$dismiss_url = wp_nonce_url(
		add_query_arg( 'aero_io_dismiss_conflict', $signature ),
		'aero_io_dismiss_conflict'
	);
	?>
	<div class="notice notice-warning aero-io-conflict-notice">
		<p>
			<strong><?php esc_html_e( 'Aero: more than one image optimization plugin is active.', 'aero' ); ?></strong>
		</p>
		<p>
			<?php
			printf(
				/* translators: 1: number of active optimizers, 2: comma separated plugin names */
				esc_html__( 'This site is running %1$d image optimization plugins at once: Aero Images and %2$s. Two optimizers hooking the same upload pipeline and delivery layer will fight each other, which typically shows up as images converting twice, conversions being reverted, wrong formats being served, or statistics that never add up.', 'aero' ),
				(int) $count,
				$names // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
			);
			?>
		</p>
		<p>
			<?php esc_html_e( 'Keep one of them. If you want to keep the other plugin, switch Aero\'s image optimizer off with the master switch on the Images screen; if you want Aero to handle images, deactivate the other plugin.', 'aero' ); ?>
		</p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=aero-images' ) ); ?>"><?php esc_html_e( 'Open Aero Images', 'aero' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Manage Plugins', 'aero' ); ?></a>
			<a class="button-link" href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:8px;"><?php esc_html_e( 'Dismiss', 'aero' ); ?></a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'aero_io_conflict_notice' );

/**
 * Handle the dismiss link. The signature is stored, so a different set of
 * conflicting plugins raises the warning again.
 *
 * @return void
 */
function aero_io_handle_conflict_dismiss() {
	if ( ! isset( $_GET['aero_io_dismiss_conflict'] ) ) {
		return;
	}
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	check_admin_referer( 'aero_io_dismiss_conflict' );

	$signature = sanitize_text_field( wp_unslash( $_GET['aero_io_dismiss_conflict'] ) );
	update_user_meta( get_current_user_id(), 'aero_io_conflict_dismissed', $signature );

	wp_safe_redirect( remove_query_arg( array( 'aero_io_dismiss_conflict', '_wpnonce' ) ) );
	exit;
}
add_action( 'admin_init', 'aero_io_handle_conflict_dismiss' );
