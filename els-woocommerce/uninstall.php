<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'els_wc_sync_statuses' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_els_wc_token_%' OR option_name LIKE '_transient_timeout_els_wc_token_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared

// Settings are kept unless "Delete settings on uninstall" was enabled, so
// replacing the plugin by delete + upload does not wipe the ELS configuration.
$els_wc_settings = get_option( 'els_wc_settings', array() );
if ( is_array( $els_wc_settings ) && 'yes' === ( $els_wc_settings['delete_data'] ?? 'no' ) ) {
	delete_option( 'els_wc_settings' );
}
