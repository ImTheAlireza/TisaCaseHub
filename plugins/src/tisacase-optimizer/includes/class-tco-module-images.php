<?php
/**
 * ماژول ۱ (فعال و کامل): استودیو بهینه‌سازی و مدیریت جامع تصاویر و رسانه.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Module_Images' ) ) {

	/**
	 * ماژول تصاویر و رسانه.
	 */
	final class TisaCase_Optimizer_Module_Images implements TisaCase_Optimizer_Module_Interface {

		/**
		 * @return string
		 */
		public function id() {
			return 'images';
		}

		/**
		 * @return array<string,mixed>
		 */
		public function meta() {
			return array(
				'id'       => 'images',
				'title'    => __( 'تصاویر و رسانه', 'tisacase-optimizer' ),
				'subtitle' => __( 'تحلیل دقیق حجم دیسک و Inode، کاوشگر سنگین‌ترین تصاویر، اسکنر ۱۲لایهٔ تصاویر بدون استفاده، فشرده‌سازی و WebP، ادغام هوشمند تکراری‌ها، مدیریت زیرسایزها و سئوی Alt خودکار.', 'tisacase-optimizer' ),
				'badge'    => __( 'فعال · ۹ ابزار کامل', 'tisacase-optimizer' ),
				'active'   => true,
				'icon'     => 'image',
				'subtabs'  => array(
					'overview'   => __( 'نمای کلی دیسک', 'tisacase-optimizer' ),
					'explorer'   => __( 'سنگین‌ترین تصاویر', 'tisacase-optimizer' ),
					'unused'     => __( 'تصاویر بدون استفاده', 'tisacase-optimizer' ),
					'whitelist'  => __( 'لیست سفید (پین‌شده‌ها و پوشه‌ها)', 'tisacase-optimizer' ),
					'restore'    => __( 'پشتیبان و بازگردانی', 'tisacase-optimizer' ),
					'compress'   => __( 'فشرده‌سازی و WebP', 'tisacase-optimizer' ),
					'duplicates' => __( 'تصاویر تکراری', 'tisacase-optimizer' ),
					'subsizes'   => __( 'زیرسایزها و بازسازی', 'tisacase-optimizer' ),
					'seo'        => __( 'سئو و Alt خودکار', 'tisacase-optimizer' ),
					'settings'   => __( 'تنظیمات و اتوماسیون', 'tisacase-optimizer' ),
				),
			);
		}

		/**
		 * @return array<string,mixed>
		 */
		public function boot_payload() {
			return array(
				'capabilities' => TisaCase_Optimizer_Image_Processor::capabilities(),
			);
		}
	}
}
