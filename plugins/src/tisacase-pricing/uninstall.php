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
wp_clear_scheduled_hook( 'tcp_refresh_product_lookup' );
wp_clear_scheduled_hook( 'tcp_continue_run' );

delete_option( 'tcp_settings' );
delete_option( 'tcp_preview_sample_size_v2_migrated' );
delete_option( 'tcp_db_version' );
delete_option( 'tcp_rules' );
delete_option( 'tcp_rules_cache_version' );
delete_option( 'tcp_lookup_refresh_pending' );
delete_option( 'tcp_continue_runs' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tcp_rl_%' OR option_name LIKE 'tcp_beat_%'" ); // phpcs:ignore WordPress.DB

$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'tcp_runs' ); // phpcs:ignore WordPress.DB
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'tcp_log' ); // phpcs:ignore WordPress.DB
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_tcp_bulk_guard' ) ); // phpcs:ignore WordPress.DB

// Keep tcp_coupon_uses, tcp_coupon_uses_schema and coupon policies intentionally.
// Woo coupons/orders survive uninstall; deleting only this ledger would silently
// restore already-spent quotas on reinstall. See COUPON-PHONES.md for retention.
// The tcp_coupon_orders report index and tcp_coupon_report_schema are retained too;
// they contain order/coupon IDs only, and can be rebuilt from surviving orders.
