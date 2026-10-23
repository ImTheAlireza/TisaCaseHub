<?php
/**
 * ماژول ۲: بهینه‌سازی و پاک‌سازی دیتابیس و ووکامرس (اسکلت آمادهٔ فاز ۲ + شمارش زندهٔ وضعیت).
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Module_Database' ) ) {

	/**
	 * ماژول ۲: دیتابیس و ووکامرس.
	 */
	final class TisaCase_Optimizer_Module_Database implements TisaCase_Optimizer_Module_Interface {

		/**
		 * @return string
		 */
		public function id() {
			return 'database';
		}

		/**
		 * @return array<string,mixed>
		 */
		public function meta() {
			return array(
				'id'       => 'database',
				'title'    => __( 'دیتابیس و ووکامرس', 'tisacase-optimizer' ),
				'subtitle' => __( 'پاک‌سازی امن رونوشت‌ها، Action Scheduler ووکامرس، متاهای یتیم، تحلیل سنگین‌ترین Autoloadها و بهینه‌سازی جداول.', 'tisacase-optimizer' ),
				'badge'    => __( 'فاز ۲', 'tisacase-optimizer' ),
				'active'   => false,
				'icon'     => 'database',
				'roadmap'  => array(
					array(
						'title' => __( 'پاک‌سازی جداول حجیم Action Scheduler و سشن‌های قدیمی ووکامرس', 'tisacase-optimizer' ),
						'desc'  => __( 'تخلیهٔ امن میلیون‌ها لاگ تکمیل‌شده/منقضی‌شده در wp_actionscheduler_logs و wp_woocommerce_sessions بدون اختلال در سفارش‌ها.', 'tisacase-optimizer' ),
					),
					array(
						'title' => __( 'پروفایلر Autoload جدول wp_options با کلید بازگردانی', 'tisacase-optimizer' ),
						'desc'  => __( 'شناسایی آپشن‌های سنگین به‌جامانده از افزونه‌های قبلی که در تک‌تک صفحات سایت در رم بارگذاری می‌شوند.', 'tisacase-optimizer' ),
					),
					array(
						'title' => __( 'حذف رونوشت‌های قدیمی، پیش‌نویس‌های خودکار و متاهای یتیم', 'tisacase-optimizer' ),
						'desc'  => __( 'نگهداری N رونوشت آخر محصولات، پاک‌سازی orphan postmeta/termmeta و بازسازی جداول Lookup ووکامرس.', 'tisacase-optimizer' ),
					),
				),
			);
		}

		/**
		 * شمارش سبک و فقط-خواندنی وضعیت فعلی دیتابیس برای دید سریع مدیر سایت.
		 *
		 * @return array<string,mixed>
		 */
		public function boot_payload() {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$revisions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$autodrafts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$autoload_bytes = (int) $wpdb->get_var( "SELECT COALESCE(SUM(LENGTH(option_value)), 0) FROM {$wpdb->options} WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')" );

			return array(
				'snapshot' => array(
					'revisions'      => $revisions,
					'auto_drafts'    => $autodrafts,
					'autoload_bytes' => $autoload_bytes,
				),
			);
		}
	}
}
