<?php
/**
 * چرخه حیات افزونه: فعال‌سازی، غیرفعال‌سازی و حذف کامل (Uninstall).
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Lifecycle' ) ) {

	final class TisaCase_Exporter_Lifecycle {

		public static function activate() {
			if ( ! wp_next_scheduled( TisaCase_Exporter::CRON_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', TisaCase_Exporter::CRON_HOOK );
			}
		}

		/**
		 * غیرفعال‌سازی: فقط کرون پاک می‌شود.
		 *
		 * عمداً هیچ فایلی حذف نمی‌شود: غیرفعال‌سازی برای عیب‌یابی/آزمایش انجام می‌شود و
		 * نباید تاریخچهٔ خروجی‌ها و فایل‌های آمادهٔ دانلود کاربران را از بین ببرد
		 * (قبلاً sweep اجباری همهٔ پوشه‌ها را پاک می‌کرد). پاک‌سازی کامل فقط در Uninstall.
		 */
		public static function deactivate() {
			wp_clear_scheduled_hook( TisaCase_Exporter::CRON_HOOK );
		}

		/** حذف کامل ردپاها هنگام Uninstall (فایل‌ها، کرون و transientهای جدول options). */
		public static function uninstall() {
			wp_clear_scheduled_hook( TisaCase_Exporter::CRON_HOOK );

			$dirs = array_merge( TisaCase_Exporter_Storage::base_dirs(), TisaCase_Exporter_Storage::legacy_dirs() );

			foreach ( $dirs as $base ) {
				if ( is_string( $base ) && is_dir( $base ) ) {
					TisaCase_Exporter_Storage::delete_directory( $base );
				}
			}

			global $wpdb;

			/*
			 * فقط کلیدهای همین افزونه پاک می‌شوند (پیشوند اختصاصی tisacase_exporter_).
			 * باقی‌ماندهٔ نام‌های قدیمی (tisacase_state_/tisacase_lock_/tisacase_cancel_) هم پاک می‌شود
			 * تا مهاجرت از نسخهٔ ۱.x هیچ ردی در options نگذارد.
			 */
			$prefixes = array(
				'tisacase_exporter_history_',
				'_transient_tisacase_exporter_',
				'_transient_timeout_tisacase_exporter_',
				'_transient_tisacase_state_',
				'_transient_timeout_tisacase_state_',
				'_transient_tisacase_lock_',
				'_transient_timeout_tisacase_lock_',
				'_transient_tisacase_cancel_',
				'_transient_timeout_tisacase_cancel_',
			);
			foreach ( $prefixes as $prefix ) {
				$like = $wpdb->esc_like( $prefix ) . '%';
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
			}

			/*
			 * نکته: اگر Object Cache خارجی فعال باشد، transientهای آن به‌صورت طبیعی با TTL
			 * منقضی می‌شوند. عمداً هیچ کشی flush نمی‌شود تا به کارایی سایت آسیب نرسد.
			 */
		}
	}
}
