<?php
/**
 * Aero Cache Manager — Scheduled Cache Clearing (WP-Cron)
 *
 * Runs the full sequential flush (in the configured purge order) on a
 * recurring schedule. Configurable in the Cache Manager settings.
 *
 * Options:
 *   aero_cm_schedule_enabled   '1' | ''
 *   aero_cm_schedule_interval  'hourly' | 'twicedaily' | 'daily' | 'weekly' |
 *                              'aero_cm_every_6_hours' | 'aero_cm_every_12_hours'
 *
 * @package Aero
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const AERO_CM_CRON_HOOK = 'aero_cm_scheduled_flush';

// ─── Custom intervals ─────────────────────────────────────────────────────────
add_filter( 'cron_schedules', 'aero_cm_add_cron_intervals' );
function aero_cm_add_cron_intervals( $schedules ) {
	if ( ! isset( $schedules['weekly'] ) ) {
		$schedules['weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'aero' ),
		);
	}
	$schedules['aero_cm_every_6_hours'] = array(
		'interval' => 6 * HOUR_IN_SECONDS,
		'display'  => __( 'Every 6 Hours', 'aero' ),
	);
	$schedules['aero_cm_every_12_hours'] = array(
		'interval' => 12 * HOUR_IN_SECONDS,
		'display'  => __( 'Every 12 Hours', 'aero' ),
	);
	return $schedules;
}

/**
 * Allowed interval slugs (whitelist for the settings form).
 */
function aero_cm_allowed_schedule_intervals() {
	return array(
		'hourly'                 => __( 'Every Hour', 'aero' ),
		'aero_cm_every_6_hours'  => __( 'Every 6 Hours', 'aero' ),
		'twicedaily'             => __( 'Twice Daily', 'aero' ),
		'aero_cm_every_12_hours' => __( 'Every 12 Hours', 'aero' ),
		'daily'                  => __( 'Once Daily', 'aero' ),
		'weekly'                 => __( 'Once Weekly', 'aero' ),
	);
}

// ─── The scheduled task ───────────────────────────────────────────────────────
add_action( AERO_CM_CRON_HOOK, 'aero_cm_run_scheduled_flush' );
function aero_cm_run_scheduled_flush() {
	$results = aero_cm_run_sequential_flush( 'aero-scheduled-cron-flush' );

	$summary = array();
	foreach ( $results as $r ) {
		$summary[] = ( $r['success'] ? '✓' : '✗' ) . ' ' . $r['message'];
	}
	update_option(
		'aero_cm_last_scheduled_flush',
		gmdate( 'j M Y, g:ia' ) . ' UTC — ' . implode( ' | ', $summary )
	);
}

/**
 * (Re)schedule or clear the cron event to match current settings.
 * Runs on admin_init after settings save, and safe to call repeatedly.
 */
function aero_cm_sync_schedule() {
	$enabled  = get_option( 'aero_cm_schedule_enabled', '' );
	$interval = get_option( 'aero_cm_schedule_interval', 'daily' );

	$allowed = array_keys( aero_cm_allowed_schedule_intervals() );
	if ( ! in_array( $interval, $allowed, true ) ) {
		$interval = 'daily';
	}

	$scheduled = wp_next_scheduled( AERO_CM_CRON_HOOK );

	if ( '1' === $enabled ) {
		// Determine currently scheduled recurrence, if any.
		$current_recurrence = null;
		if ( $scheduled ) {
			$event = wp_get_scheduled_event( AERO_CM_CRON_HOOK );
			$current_recurrence = $event ? $event->schedule : null;
		}

		if ( ! $scheduled ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, AERO_CM_CRON_HOOK );
		} elseif ( $current_recurrence !== $interval ) {
			// Interval changed — reschedule
			wp_clear_scheduled_hook( AERO_CM_CRON_HOOK );
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, AERO_CM_CRON_HOOK );
		}
	} else {
		if ( $scheduled ) {
			wp_clear_scheduled_hook( AERO_CM_CRON_HOOK );
		}
	}
}
add_action( 'admin_init', 'aero_cm_sync_schedule', 30 );

/**
 * Clear the schedule on plugin deactivation (called from aero.php).
 */
function aero_cm_clear_schedule() {
	wp_clear_scheduled_hook( AERO_CM_CRON_HOOK );
}
