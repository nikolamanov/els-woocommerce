<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'els_wc_settings' );
wp_clear_scheduled_hook( 'els_wc_sync_statuses' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_els_wc_token_%' OR option_name LIKE '_transient_timeout_els_wc_token_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared

