<?php
/**
 * ماژول ۵ (فعال و عملیاتی): خزانهٔ قرنطینهٔ ۳۰ روزه، پشتیبان‌ها و تاریخچهٔ عملیات‌ها.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Module_Vault' ) ) {

	/**
	 * ماژول قرنطینه، پشتیبان و تاریخچه.
	 */
	final class TisaCase_Optimizer_Module_Vault implements TisaCase_Optimizer_Module_Interface {

		/**
		 * @return string
		 */
		public function id() {
			return 'vault';
		}

		/**
		 * @return array<string,mixed>
		 */
		public function meta() {
			return array(
				'id'       => 'vault',
				'title'    => __( 'قرنطینه و تاریخچه', 'tisacase-optimizer' ),
				'subtitle' => __( 'بازگردانی تک‌کلیکی تصاویر قرنطینه‌شده به همان ID و مسیر اصلی، بازیابی فایل اولیهٔ تصاویر فشرده‌شده، و گزارش کامل تاریخچهٔ عملیات‌ها.', 'tisacase-optimizer' ),
				'badge'    => __( 'فعال', 'tisacase-optimizer' ),
				'active'   => true,
				'icon'     => 'shield',
			);
		}

		/**
		 * @return array<string,mixed>
		 */
		public function boot_payload() {
			return array(
				'summary' => TisaCase_Optimizer_Vault::summary(),
			);
		}
	}
}
