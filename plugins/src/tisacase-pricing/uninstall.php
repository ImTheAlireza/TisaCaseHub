<?php
/**
 * حذف کامل داده‌های افزونه هنگام uninstall.
 *
 * @package TisaCase_Pricing
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

wp_clear_scheduled_hook( 'tcp_process_scheduled_tick' );
wp_clear_scheduled_hook( 'tcp_daily_cleanup' );

delete_option( 'tcp_settings' );
delete_option( 'tcp_preview_sample_size_v2_migrated' );
delete_option( 'tcp_db_version' );
delete_option( 'tcp_rules' );
delete_option( 'tcp_rules_cache_version' );

$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'tcp_runs' ); // phpcs:ignore WordPress.DB
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'tcp_log' ); // phpcs:ignore WordPress.DB
