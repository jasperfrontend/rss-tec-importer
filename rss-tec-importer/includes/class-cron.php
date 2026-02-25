<?php
/**
 * RSS_TEC_Cron
 *
 * Registers custom cron schedules, manages the scheduled event, and
 * provides the cron callback that triggers the import.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RSS_TEC_Cron {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_custom_schedules' ] );
		add_action( RSS_TEC_IMPORTER_CRON_HOOK, [ __CLASS__, 'run_import' ] );
		add_action( 'update_option_' . RSS_TEC_IMPORTER_OPTION_KEY, [ __CLASS__, 'reschedule_if_needed' ], 10, 2 );
	}

	/**
	 * Add every_15_minutes and weekly schedules if not already defined.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array Modified schedules.
	 */
	public static function add_custom_schedules( array $schedules ): array {
		if ( ! isset( $schedules['every_15_minutes'] ) ) {
			$schedules['every_15_minutes'] = [
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 Minutes', 'rss-tec-importer' ),
			];
		}

		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = [
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Weekly', 'rss-tec-importer' ),
			];
		}

		return $schedules;
	}

	/**
	 * Cron callback — run the importer.
	 */
	public static function run_import(): void {
		$settings = get_option( RSS_TEC_IMPORTER_OPTION_KEY, [] );

		if ( empty( $settings['feed_url'] ) ) {
			return;
		}

		$result = RSS_TEC_Importer::run( $settings );

		if ( is_wp_error( $result ) ) {
			$msg = '[RSS TEC Importer] Cron import error: ' . $result->get_error_message();
			error_log( $msg );
			update_option( 'rss_tec_importer_last_error', $result->get_error_message() );
			update_option( 'rss_tec_importer_last_run', time() );
		}
	}

	/**
	 * When settings are saved, reschedule the cron event if the schedule changed.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 */
	public static function reschedule_if_needed( mixed $old_value, mixed $new_value ): void {
		$old_schedule = $old_value['cron_schedule'] ?? 'daily';
		$new_schedule = $new_value['cron_schedule'] ?? 'daily';

		if ( $old_schedule === $new_schedule ) {
			return;
		}

		// Clear the existing scheduled event and reschedule with the new interval.
		wp_clear_scheduled_hook( RSS_TEC_IMPORTER_CRON_HOOK );
		wp_schedule_event( time(), $new_schedule, RSS_TEC_IMPORTER_CRON_HOOK );
	}

	/**
	 * Ensure a cron event is scheduled (idempotent — safe to call multiple times).
	 *
	 * @param string $schedule Schedule key.
	 */
	public static function ensure_scheduled( string $schedule = 'daily' ): void {
		if ( ! wp_next_scheduled( RSS_TEC_IMPORTER_CRON_HOOK ) ) {
			wp_schedule_event( time(), $schedule, RSS_TEC_IMPORTER_CRON_HOOK );
		}
	}
}
