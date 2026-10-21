<?php
/**
 * قرارداد ماژول‌های مرکز بهینه‌سازی تیساکیس.
 *
 * هر بخش (تصاویر، دیتابیس، فرانت‌اند، سلامت سیستم، قرنطینه) این اینترفیس را
 * پیاده‌سازی می‌کند تا افزودن یا توسعهٔ بخش‌ها بدون دست‌زدن به هسته ممکن باشد.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'TisaCase_Optimizer_Module_Interface' ) ) {

	/**
	 * قرارداد واحد ماژول‌ها.
	 */
	interface TisaCase_Optimizer_Module_Interface {

		/**
		 * شناسهٔ یکتای ماژول (مثلاً images | database | frontend | system | vault).
		 *
		 * @return string
		 */
		public function id();

		/**
		 * مشخصات نمایشی ماژول برای نوار ناوبری و کارت معرفی.
		 *
		 * @return array<string,mixed>
		 */
		public function meta();

		/**
		 * داده‌های اولیهٔ ماژول برای بوت UI.
		 *
		 * @return array<string,mixed>
		 */
		public function boot_payload();
	}
}
