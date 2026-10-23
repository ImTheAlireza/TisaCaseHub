<?php
/**
 * ماژول ۳: شتاب‌دهندهٔ فرانت‌اند و حذف بار اضافی (اسکلت آمادهٔ فاز ۳).
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Module_Frontend' ) ) {

	/**
	 * ماژول ۳: شتاب‌دهندهٔ فرانت‌اند.
	 */
	final class TisaCase_Optimizer_Module_Frontend implements TisaCase_Optimizer_Module_Interface {

		/**
		 * @return string
		 */
		public function id() {
			return 'frontend';
		}

		/**
		 * @return array<string,mixed>
		 */
		public function meta() {
			return array(
				'id'       => 'frontend',
				'title'    => __( 'شتاب‌دهندهٔ فرانت‌اند', 'tisacase-optimizer' ),
				'subtitle' => __( 'Lazy Load هوشمند با محافظت خودکار از تصویر اول محصول (LCP)، رفع پرش صفحه (CLS)، و حذف اسکریپت‌های اضافی وردپرس/ووکامرس.', 'tisacase-optimizer' ),
				'badge'    => __( 'فاز ۳', 'tisacase-optimizer' ),
				'active'   => false,
				'icon'     => 'bolt',
				'roadmap'  => array(
					array(
						'title' => __( 'محافظت خودکار از تصویر شاخص محصول برای LCP + رفع CLS', 'tisacase-optimizer' ),
						'desc'  => __( 'افزودن fetchpriority="high" روی اولین تصویر بالای صفحه و تزریق خودکار ابعاد width/height به تگ‌های بدون ابعاد.', 'tisacase-optimizer' ),
					),
					array(
						'title' => __( 'حذف Bloat هستهٔ وردپرس و ووکامرس بدون شکستن قالب', 'tisacase-optimizer' ),
						'desc'  => __( 'غیرفعال‌سازی ایموجی، Embed، Dashicons مهمان، مهار WP Heartbeat و بهینه‌سازی هوشمند wc-cart-fragments.', 'tisacase-optimizer' ),
					),
					array(
						'title' => __( 'پیش‌بارگذاری فونت‌های محلی و DNS Prefetch', 'tisacase-optimizer' ),
						'desc'  => __( 'Preload فونت وزیرمتن محلی هاب و پیش‌اتصال دامنه‌های حیاتی برای کاهش زمان اولین رندر.', 'tisacase-optimizer' ),
					),
				),
			);
		}

		/**
		 * @return array<string,mixed>
		 */
		public function boot_payload() {
			return array();
		}
	}
}
