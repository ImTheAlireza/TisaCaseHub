<?php
/**
 * پاک‌سازی هنگام حذف افزونهٔ «کد رهگیری پستی».
 *
 * هنگام حذف: تنظیمات، آمار جارو، transientهای ضدسیل/Jobها/شمارنده‌ها،
 * قفل‌ها و زمان‌بندی جارو پاک می‌شوند. در نسخهٔ ۳ فایل موقت CSV وجود ندارد.
 *
 * توجه: متای `_tracking_code` / `_tracking_codes` روی سفارش‌ها عمداً حذف نمی‌شود —
 * دادهٔ سفارش است و ممکن است قالب/ایمیل فروشگاه به آن مراجعه کند.
 *
 * @package Bulk_Tracking_Upload
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

if ( isset( $wpdb->options ) ) {
	$patterns = array(
		'\_transient\_bwt\_track\_fail\_%',
		'\_transient\_timeout\_bwt\_track\_fail\_%',
		'\_transient\_bwt\_sync\_job\_%',
		'\_transient\_timeout\_bwt\_sync\_job\_%',
		'\_transient\_bwt\_cleanup\_job\_%',
		'\_transient\_timeout\_bwt\_cleanup\_job\_%',
		'\_transient\_bwt\_count\_%',
		'\_transient\_timeout\_bwt\_count\_%',
	);
	$where = array();
	foreach ( $patterns as $pattern ) {
		$where[] = $wpdb->prepare( 'option_name LIKE %s', $pattern );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE " . implode( ' OR ', $where ) . " OR option_name LIKE 'bwt\\_lock\\_%'" );
}

foreach ( array( 'bwt_settings', 'bwt_last_sweep' ) as $option ) {
	delete_option( $option );
}

if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
	wp_clear_scheduled_hook( 'bwt_tracking_sweep' );
}
