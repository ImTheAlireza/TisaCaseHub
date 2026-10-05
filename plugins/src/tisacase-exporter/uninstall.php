<?php
/**
 * پاک‌سازی کامل ردپاهای افزونه هنگام حذف (Uninstall).
 * وردپرس این فایل را به‌صورت مستقل اجرا می‌کند (فایل اصلی افزونه بارگذاری نمی‌شود).
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-tce-plugin.php';
require_once __DIR__ . '/includes/class-tce-storage.php';
require_once __DIR__ . '/includes/class-tce-lifecycle.php';

TisaCase_Exporter_Lifecycle::uninstall();
