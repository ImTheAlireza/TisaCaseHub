<?php
/**
 * هستهٔ اصلی افزونه: ثابت‌ها، راه‌اندازی هوک‌ها و سطح دسترسی.
 *
 * @package TisaCase_Bulk_Stock_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TBSM_Core' ) ) {

	final class TBSM_Core {

		const PAGE_SLUG       = 'tisacase-bulk-stock-manager';
		const NONCE_ACTION    = 'tbsm_action_nonce';
		const MAX_ITEMS_APPLY = 2000;
		const MAX_SEARCH_HITS = 20;

		private static $instance = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			$this->init_hooks();
		}

		private function init_hooks() {
			add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
			TBSM_Admin::init();
			TBSM_Ajax::init();
		}

		public static function load_textdomain() {
			load_plugin_textdomain(
				'tisacase-bsm',
				false,
				dirname( plugin_basename( TBSM_FILE ) ) . '/languages'
			);
		}

		/**
		 * بررسی سطح دسترسی کاربر برای صفحه و عملیات‌ها.
		 */
		public static function can() {
			return current_user_can( 'manage_woocommerce' );
		}

		/**
		 * فعال‌سازی: هیچ آپشن پایدار تعریف نمی‌شود (افزونهٔ بدون تنظیمات).
		 */
		public static function activate() {
			// Nothing to install.
		}

		/**
		 * غیرفعال‌سازی: داده‌ای پاک نمی‌شود.
		 */
		public static function deactivate() {
			// Nothing to clean on deactivation; uninstall.php handles removal.
		}
	}
}
