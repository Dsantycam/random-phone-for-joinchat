<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package JoinChatRandomPhone
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'joinchat_random_phone_lists' );
delete_option( 'joinchat_random_phone_bags' );

// Statistics table and its options.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jcrp_stats" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_option( 'joinchat_random_phone_stats_db' );
delete_transient( 'jcrp_stats_install_retry' );
