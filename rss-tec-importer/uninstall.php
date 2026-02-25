<?php
/**
 * Uninstall script — runs when the plugin is deleted via the WordPress admin.
 *
 * Removes all plugin-related options from the database.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'rss_tec_importer_settings' );
delete_option( 'rss_tec_importer_last_run' );
delete_option( 'rss_tec_importer_last_result' );
delete_option( 'rss_tec_importer_last_error' );
delete_transient( 'rss_tec_github_release' );
