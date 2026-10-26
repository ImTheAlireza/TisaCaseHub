<?php
/**
 * حذف تنظیمات و نگاشت‌ها؛ خود محصولات و موجودی ووکامرس حذف نمی‌شوند.
 *
 * @package TisaCase_ArioCase_Sync
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'tcas_ariocase_settings' );
delete_option( 'tcas_ariocase_settings_version' );
delete_option( 'tcas_ariocase_logs' );

global $wpdb;
foreach ( array( '_tcas_remote_code', '_tcas_sync_enabled', '_tcas_remote_stock', '_tcas_remote_stock_status', '_tcas_remote_fetched_at', '_tcas_last_error', '_tcas_remote_product_id', '_tcas_remote_parent_id' ) as $meta_key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ), array( '%s' ) );
}

// Transientهای عملیات موقت هم پاک می‌شوند.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tcas_job_%' OR option_name LIKE '_transient_timeout_tcas_job_%' OR option_name LIKE '_transient_tcas_stock_refresh_%' OR option_name LIKE '_transient_timeout_tcas_stock_refresh_%'"
);
