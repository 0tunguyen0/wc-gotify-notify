<?php
/**
 * Uninstall handler for WooCommerce to Gotify Notifications.
 * Removes plugin settings, transients, scheduled actions, and order meta data.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 1. Delete plugin options.
delete_option( 'wc_gotify_settings' );

// 2. Delete plugin transients.
delete_transient( 'wc_gotify_sidebar_data' );
delete_transient( 'wc_gotify_sidebar_data_lock' );

// 3. Cancel pending Action Scheduler jobs.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'wc_gotify_send_async' );
}

// 4. Delete legacy post meta data (for non-HPOS setups).
delete_post_meta_by_key( '_wc_gotify_sent' );
delete_post_meta_by_key( '_wc_gotify_sent_error' );
delete_post_meta_by_key( '_wc_gotify_queued' );

// 5. Delete HPOS order meta data (for modern WooCommerce setups).
// delete_post_meta_by_key() does not work on custom order tables, so we query directly.
if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_enabled() ) {
	global $wpdb;
	$table = $wpdb->prefix . 'wc_orders_meta';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE meta_key IN (%s, %s, %s)",
				'_wc_gotify_sent',
				'_wc_gotify_sent_error',
				'_wc_gotify_queued'
			)
		);
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
