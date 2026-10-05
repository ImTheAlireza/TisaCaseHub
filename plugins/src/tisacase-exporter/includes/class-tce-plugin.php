<?php
/**
 * هسته افزونهٔ «خروجی گرفتن»: ثابت‌ها، تنظیمات قابل‌فیلتر و اتصال هوک‌ها.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter' ) ) {

	final class TisaCase_Exporter {

		/* -----------------------------------------------------------------
		 * تنظیمات
		 * ----------------------------------------------------------------- */

		const MENU_SLUG         = 'tisacase-exporter';
		const AJAX_START        = 'tisacase_export_start';
		const AJAX_PROCESS      = 'tisacase_export_process';
		const AJAX_CANCEL       = 'tisacase_export_cancel';
		const AJAX_PREVIEW      = 'tisacase_export_preview';
		const AJAX_DIAGNOSE     = 'tisacase_export_diagnose';
		const AJAX_HISTORY      = 'tisacase_export_history_clear';
		const DOWNLOAD          = 'tisacase_export_download';
		const DOWNLOAD_ZIP      = 'tisacase_export_download_zip';
		const NONCE_ACTION      = 'tisacase_export_nonce';
		const CRON_HOOK         = 'tisacase_export_sweep';
		const STATE_TTL         = 43200;   // اعتبار جلسه خروجی: ۱۲ ساعت.
		const LOCK_TTL          = 120;     // قفل همزمانی: حداکثر ۲ دقیقه بین دو Batch.
		const FILE_TTL          = 86400;   // پاک‌سازی خودکار فایل‌های قدیمی‌تر از ۲۴ ساعت.
		const HISTORY_MAX       = 20;      // تعداد اجراهای نگه‌داشته‌شده در تاریخچه.
		const PREVIEW_ROWS      = 25;      // تعداد ردیف پیش‌نمایش.
		const BASE_DIR_NAME     = 'tisacase-private-exports';
		const LEGACY_DIR_NAME   = 'tisacase-private-phone-exports'; // مسیر نسخهٔ ۱.x — فقط برای پاک‌سازی.
		const WORKING_FILE      = 'working.tsv';
		const PART_PREFIX       = 'part-';
		const CHUNK_LINES       = 100000;  // حداکثر خطوط هر تکهٔ مرتب‌سازی (سقف حافظهٔ Dedup).
		const WRITE_BUF         = 65536;   // بافر نوشتن (بایت).
		const MIN_BATCH         = 100;
		const MAX_BATCH         = 2000;
		const CLI_BATCH        = 2000;   // اندازه Batch در WP-CLI (بدون سقف زمان اجرای وب).
		const TEXT_DOMAIN       = 'tisacase-exporter';

		/** اندازه هر Batch (با فیلتر قابل تنظیم برای قدرت هاست‌های مختلف). */
		public static function batch_size() {
			$size = (int) apply_filters( 'tisacase_exporter_batch_size', 1000 );
			return max( self::MIN_BATCH, min( self::MAX_BATCH, $size ) );
		}

		/** تعداد ردیف در هر فایل خروجی (با فیلتر قابل تنظیم). */
		public static function file_size() {
			$size = (int) apply_filters( 'tisacase_exporter_file_size', 10000 );
			return max( 100, $size );
		}

		/** مسیر پوشهٔ افزونه. */
		public static function dir() {
			return TISA_EXPORTER_DIR;
		}

		/** آدرس دارایی‌ها. */
		public static function url( $file = '' ) {
			return plugins_url( 'assets/' . ltrim( (string) $file, '/' ), TISA_EXPORTER_FILE );
		}

		/* -----------------------------------------------------------------
		 * راه‌اندازی
		 * ----------------------------------------------------------------- */

		public static function init() {
			if ( is_admin() ) {
				add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
				add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
				add_action( 'wp_ajax_' . self::AJAX_START, array( 'TisaCase_Exporter_Ajax', 'ajax_start' ) );
				add_action( 'wp_ajax_' . self::AJAX_PROCESS, array( 'TisaCase_Exporter_Ajax', 'ajax_process' ) );
				add_action( 'wp_ajax_' . self::AJAX_CANCEL, array( 'TisaCase_Exporter_Ajax', 'ajax_cancel' ) );
				add_action( 'wp_ajax_' . self::AJAX_PREVIEW, array( 'TisaCase_Exporter_Ajax', 'ajax_preview' ) );
				add_action( 'wp_ajax_' . self::AJAX_DIAGNOSE, array( 'TisaCase_Exporter_Ajax', 'ajax_diagnose' ) );
				add_action( 'wp_ajax_' . self::AJAX_HISTORY, array( 'TisaCase_Exporter_Ajax', 'ajax_history_clear' ) );
				add_action( 'admin_post_' . self::DOWNLOAD, array( 'TisaCase_Exporter_Download', 'download_file' ) );
				add_action( 'admin_post_' . self::DOWNLOAD_ZIP, array( 'TisaCase_Exporter_Download', 'download_zip' ) );
				add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_hpos_compatibility' ) );

				if ( function_exists( 'load_plugin_textdomain' ) ) {
					load_plugin_textdomain(
						self::TEXT_DOMAIN,
						false,
						dirname( plugin_basename( TISA_EXPORTER_FILE ) ) . '/languages'
					);
				}
			}

			// پاک‌سازی ساعتیِ فایل‌های موقت قدیمی (حریم خصوصی + دیسک).
			add_action( self::CRON_HOOK, array( 'TisaCase_Exporter_Storage', 'sweep_old_exports' ) );
		}

		/** اعلام سازگاری با HPOS (جدول‌های سفارش اختصاصی ووکامرس). */
		public static function declare_hpos_compatibility() {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
					'custom_order_tables',
					TISA_EXPORTER_FILE,
					true
				);
			}
		}

		/**
		 * آدرس لایهٔ توکن/کامپوننت مشترک هاب (اگر نصب باشد).
		 * اگر هاب نباشد، صفحه با استایل مستقل خود افزونه خوانا می‌ماند
		 * (کلاس `is-standalone` روی رپپر).
		 */
		public static function hub_ui() {
			if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
				return '';
			}

			if ( ! file_exists( WP_PLUGIN_DIR . '/tisacase-hub/assets/tisacase-ui.css' ) ) {
				return '';
			}

			return plugins_url( 'tisacase-hub/assets/tisacase-ui.css' );
		}

		/** استایل و اسکریپت صفحهٔ خودمان — بعد از لایهٔ توکن هاب اگر فعال باشد. */
		public static function admin_assets( $hook ) {
			if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
				return;
			}

			$hub = self::hub_ui();

			if ( '' !== $hub && ! wp_style_is( 'tisacase-ui', 'enqueued' ) ) {
				wp_enqueue_style( 'tisacase-ui', $hub, array(), TISA_EXPORTER_VERSION );
			}

			$deps = '' !== $hub ? array( 'tisacase-ui' ) : array();
			wp_enqueue_style( 'tisacase-exporter', self::url( 'admin.css' ), $deps, TISA_EXPORTER_VERSION );

			wp_enqueue_script( 'tisacase-exporter', self::url( 'admin.js' ), array(), TISA_EXPORTER_VERSION, true );
			wp_localize_script( 'tisacase-exporter', 'TisaExp', TisaCase_Exporter_Admin_Page::script_config() );
		}

		public static function admin_menu() {
			add_submenu_page(
				'woocommerce',
				__( 'خروجی گرفتن', self::TEXT_DOMAIN ),
				__( 'خروجی گرفتن', self::TEXT_DOMAIN ),
				'manage_woocommerce',
				self::MENU_SLUG,
				array( 'TisaCase_Exporter_Admin_Page', 'admin_page' )
			);
		}
	}
}
