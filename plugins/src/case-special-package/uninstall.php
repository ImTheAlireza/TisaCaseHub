<?php
/**
 * پاک‌سازی هنگام حذف افزونهٔ «پکیج ویژه قاب موبایل».
 *
 * حذف: تنظیمات و snapshot/state/version کش آمار و jobهای صف‌شدهٔ wcsp.
 *
 * توجه: متاهای محصول (`_wcsp_mode`, `_wcsp_package_price`) و متای آیتم سفارش
 * (`_wcsp_package_unit_price`, `_wcsp_package_total`) عمداً حذف نمی‌شوند —
 * اولی تنظیم محصول است (در صورت نصب مجدد برمی‌گردد) و دومی دادهٔ تاریخی
 * سفارش‌هاست که فاکتورها/گزارش‌ها به آن تکیه می‌کنند.
 *
 * @package Case_Special_Package
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wcsp_settings' );
delete_option( 'wcsp_stats_snapshot_v1' );
delete_option( 'wcsp_stats_build_v1' );
delete_option( 'wcsp_stats_data_version' );
delete_transient( 'wcsp_stats_v1' );
if ( function_exists( 'wp_unschedule_hook' ) ) {
	wp_unschedule_hook( 'wcsp_process_stats_batch' );
}
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'wcsp_process_stats_batch', null, 'wcsp-stats' );
}
