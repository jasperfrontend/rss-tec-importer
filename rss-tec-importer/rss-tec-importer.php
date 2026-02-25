<?php
/**
 * Plugin Name:       RSS TEC Importer
 * Plugin URI:        https://github.com/
 * Description:       Imports events from an RSS feed (The Events Calendar format) into The Events Calendar on this site.
 * Version:           1.0.8
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Jasper
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rss-tec-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RSS_TEC_IMPORTER_VERSION', '1.0.8' );
define( 'RSS_TEC_IMPORTER_FILE', __FILE__ );
define( 'RSS_TEC_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'RSS_TEC_IMPORTER_URL', plugin_dir_url( __FILE__ ) );
define( 'RSS_TEC_IMPORTER_OPTION_KEY', 'rss_tec_importer_settings' );
define( 'RSS_TEC_IMPORTER_CRON_HOOK', 'rss_tec_importer_run' );

// ---------------------------------------------------------------------------
// Bootstrap — runs on plugins_loaded so all plugins are available.
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', 'rss_tec_importer_bootstrap', 10 );

function rss_tec_importer_bootstrap(): void {
	// Hard dependency: The Events Calendar must be active.
	if ( ! class_exists( 'Tribe__Events__Main' ) ) {
		add_action( 'admin_notices', 'rss_tec_importer_notice_no_tec' );
		return;
	}

	// Load classes.
	require_once RSS_TEC_IMPORTER_DIR . 'includes/class-feed-parser.php';
	require_once RSS_TEC_IMPORTER_DIR . 'includes/class-importer.php';
	require_once RSS_TEC_IMPORTER_DIR . 'includes/class-cron.php';
	require_once RSS_TEC_IMPORTER_DIR . 'includes/class-admin.php';

	// Wire up hooks for each component.
	RSS_TEC_Importer::init();
	RSS_TEC_Cron::init();
	RSS_TEC_Admin::init();
}

/**
 * Admin notice shown when The Events Calendar is not active.
 */
function rss_tec_importer_notice_no_tec(): void {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__(
		'RSS TEC Importer requires The Events Calendar plugin to be installed and active.',
		'rss-tec-importer'
	);
	echo '</p></div>';
}

// ---------------------------------------------------------------------------
// Activation hook — schedule cron after verifying TEC is present.
// ---------------------------------------------------------------------------

register_activation_hook( RSS_TEC_IMPORTER_FILE, 'rss_tec_importer_activate' );

function rss_tec_importer_activate(): void {
	if ( ! class_exists( 'Tribe__Events__Main' ) ) {
		// Cannot proceed without TEC; deactivate gracefully.
		deactivate_plugins( plugin_basename( RSS_TEC_IMPORTER_FILE ) );
		wp_die(
			esc_html__(
				'RSS TEC Importer requires The Events Calendar to be installed and active.',
				'rss-tec-importer'
			),
			esc_html__( 'Plugin Activation Error', 'rss-tec-importer' ),
			[ 'back_link' => true ]
		);
	}

	// Load Cron class so we can schedule on activation.
	require_once RSS_TEC_IMPORTER_DIR . 'includes/class-cron.php';

	$settings = get_option( RSS_TEC_IMPORTER_OPTION_KEY, [] );
	$schedule = $settings['cron_schedule'] ?? 'daily';

	if ( ! wp_next_scheduled( RSS_TEC_IMPORTER_CRON_HOOK ) ) {
		wp_schedule_event( time(), $schedule, RSS_TEC_IMPORTER_CRON_HOOK );
	}
}

// ---------------------------------------------------------------------------
// Deactivation hook — remove scheduled cron event.
// ---------------------------------------------------------------------------

register_deactivation_hook( RSS_TEC_IMPORTER_FILE, 'rss_tec_importer_deactivate' );

function rss_tec_importer_deactivate(): void {
	wp_clear_scheduled_hook( RSS_TEC_IMPORTER_CRON_HOOK );
}
