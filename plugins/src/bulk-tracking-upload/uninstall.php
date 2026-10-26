<?php
/**
 * پاک‌سازی هنگام حذف افزونهٔ «آپلود انبوه کد رهگیری».
 *
 * هنگام حذف، transientهای محدودسازی تلاش و Jobهای آپلود، زمان‌بندی پاک‌سازی
 * و فایل‌های موقت خصوصی CSV/گزارش خطا پاک می‌شوند.
 *
 * توجه: متای `_tracking_code` روی سفارش‌ها عمداً حذف نمی‌شود — دادهٔ سفارش است
 * و ممکن است قالب/ایمیل فروشگاه به آن مراجعه کند؛ حذفش خلل ایجاد می‌کند.
 *
 * @package Bulk_Tracking_Upload
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// transientها در جدول options با پیشوند _transient_ ذخیره می‌شوند.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_bwt\_track\_fail\_%' OR option_name LIKE '\_transient\_timeout\_bwt\_track\_fail\_%' OR option_name LIKE '\_transient\_bwt\_upload\_job\_%' OR option_name LIKE '\_transient\_timeout\_bwt\_upload\_job\_%'" );

if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
	wp_clear_scheduled_hook( 'bwt_cleanup_upload_temp_files' );
}

// فقط مسیرهای موقت خارج از ریشهٔ عمومی وردپرس را می‌گردیم و فایل‌های نام‌دار خودمان را حذف می‌کنیم.
$temp_dirs = array();
if ( function_exists( 'get_temp_dir' ) ) {
	$temp_dirs[] = get_temp_dir();
}
if ( function_exists( 'sys_get_temp_dir' ) ) {
	$temp_dirs[] = sys_get_temp_dir();
}
$site_identity = function_exists( 'get_option' ) ? get_option( 'siteurl' ) : ABSPATH;
if ( ! is_string( $site_identity ) || '' === $site_identity ) {
	$site_identity = ABSPATH;
}
$temp_prefix = 'bwt-upload-job-' . substr( md5( $site_identity ), 0, 12 ) . '-';

$public_roots = array( ABSPATH );
if ( defined( 'WP_CONTENT_DIR' ) ) {
	$public_roots[] = WP_CONTENT_DIR;
}
foreach ( array_unique( $temp_dirs ) as $candidate ) {
	$dir = realpath( $candidate );
	if ( false === $dir || ! is_dir( $dir ) || ! is_writable( $dir ) ) {
		continue;
	}
	$is_public = false;
	foreach ( $public_roots as $root ) {
		$root = realpath( $root );
		if ( false !== $root && ( $dir === $root || 0 === strpos( $dir, rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR ) ) ) {
			$is_public = true;
			break;
		}
	}
	if ( $is_public ) {
		continue;
	}
	$files = glob( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $temp_prefix . '*' );
	if ( ! is_array( $files ) ) {
		continue;
	}
	foreach ( $files as $file ) {
		$real_file = realpath( $file );
		if ( ! is_link( $file ) && false !== $real_file && is_file( $real_file ) && dirname( $real_file ) === $dir ) {
			@unlink( $real_file );
		}
	}
}
