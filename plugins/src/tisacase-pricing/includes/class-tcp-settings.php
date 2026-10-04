<?php
/**
 * هستهٔ افزونه: ثابت‌ها، تنظیمات عملیات گروهی، ثبت هوک‌ها و مهاجرت از دو افزونهٔ قبلی.
 *
 * @package TisaCase_Pricing
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCP_Settings' ) ) {

	final class TCP_Settings {

		const MAIN_PAGE  = 'tisacase-pricing';
		const NONCE      = 'tcp_nonce';
		const OPTION     = 'tcp_settings';
		const DB_VERSION = '1.1.1';

		const AJAX_PREVIEW        = 'tcp_preview';
		const AJAX_RUN            = 'tcp_run';
		const AJAX_FINISH         = 'tcp_run_finish';
		const AJAX_ROLLBACK_START = 'tcp_rollback_start';
		const AJAX_ROLLBACK_PAGE  = 'tcp_rollback_page';
		const AJAX_EXPORT         = 'tcp_export_csv';
		const AJAX_CANCEL             = 'tcp_cancel_scheduled';
		const AJAX_SEARCH             = 'tcp_search_wholesale_products';
		const AJAX_PRODUCT_NAME_SEARCH = 'tcp_search_products_by_name';
		const AJAX_PRODUCT_SKU_SEARCH  = 'tcp_search_products_by_sku';

		const CRON_TICK  = 'tcp_process_scheduled_tick';
		const CRON_CLEAN = 'tcp_daily_cleanup';
		const CRON_META  = 'tcp_cron_event_pending';
		/** به‌روزرسانی جدول lookup ووکامرس در پس‌زمینه (بعد از پایان هر اجرا). */
		const CRON_LOOKUP = 'tcp_refresh_product_lookup';
		const OPT_LOOKUP_PENDING = 'tcp_lookup_refresh_pending';
		/** ادامهٔ اجرای در جریان اگر مرورگر قطع شود. */
		const CRON_CONTINUE = 'tcp_continue_run';
		const OPT_CONTINUE  = 'tcp_continue_runs';
		const AJAX_CONTINUE = 'tcp_continue_run';

		/** بیشترین درصد مجاز برای عملیات درصدی (جلوگیری از overflow). */
		const PERCENT_CEIL = 100000.0;
		/** سقف نتیجهٔ هر محاسبهٔ قیمت. */
		const RESULT_CEIL = 1e14;

		/** افزونه‌های قدیمی که این افزونه جایگزینشان است. */
		const LEGACY_BULK  = 'tisacase-bulk-price-manager/tisacase-bulk-price-manager.php';
		const LEGACY_RULES = 'tisacase-pricing-manager/tisacase-pricing-manager.php';

		private static $instance = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_action( 'admin_menu', array( 'TCP_Admin', 'menus' ) );
			// اولویت ۲۰: بعد از ووکامرس تا هندل‌های select2/enhanced-select ثبت شده باشند.
			add_action( 'admin_enqueue_scripts', array( 'TCP_Admin', 'assets' ), 20 );
			add_action( 'admin_notices', array( 'TCP_Admin', 'notices' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( TCP_FILE ), array( 'TCP_Admin', 'action_links' ) );

			// عملیات گروهی.
			add_action( 'wp_ajax_' . self::AJAX_PREVIEW, array( 'TCP_Ajax', 'ajax_preview' ) );
			add_action( 'wp_ajax_' . self::AJAX_RUN, array( 'TCP_Ajax', 'ajax_run' ) );
			add_action( 'wp_ajax_' . self::AJAX_FINISH, array( 'TCP_Ajax', 'ajax_finish' ) );
			add_action( 'wp_ajax_' . self::AJAX_ROLLBACK_START, array( 'TCP_Ajax', 'ajax_rollback_start' ) );
			add_action( 'wp_ajax_' . self::AJAX_ROLLBACK_PAGE, array( 'TCP_Ajax', 'ajax_rollback_page' ) );
			add_action( 'wp_ajax_' . self::AJAX_EXPORT, array( 'TCP_Ajax', 'ajax_export_csv' ) );
			add_action( 'wp_ajax_' . self::AJAX_CANCEL, array( 'TCP_Ajax', 'ajax_cancel_scheduled' ) );
			add_action( 'wp_ajax_' . self::AJAX_SEARCH, array( 'TCP_Ajax', 'ajax_search_wholesale_products' ) );
			add_action( 'wp_ajax_' . self::AJAX_PRODUCT_NAME_SEARCH, array( 'TCP_Ajax', 'ajax_search_products_by_name' ) );
			add_action( 'wp_ajax_' . self::AJAX_PRODUCT_SKU_SEARCH, array( 'TCP_Ajax', 'ajax_search_products_by_sku' ) );
			add_action( 'init', array( 'TCP_DB', 'maybe_install' ), 5 );
		add_action( 'admin_init', array( 'TCP_DB', 'maybe_install' ) );
			add_action( 'admin_init', array( 'TCP_Admin', 'handle_settings_post' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ) );
			add_action( 'init', array( 'TCP_Scheduler', 'register_cron' ) );
			add_action( self::CRON_TICK, array( 'TCP_Scheduler', 'cron_tick' ) );
			add_action( self::CRON_CLEAN, array( 'TCP_DB', 'cron_cleanup' ) );
			add_action( self::CRON_LOOKUP, array( __CLASS__, 'refresh_lookup_tables' ) );
			add_action( self::CRON_CONTINUE, array( 'TCP_Scheduler', 'continue_due' ) );
			add_action( 'wp_ajax_' . self::AJAX_CONTINUE, array( 'TCP_Ajax', 'ajax_continue' ) );
			add_action( 'wp_ajax_nopriv_' . self::AJAX_CONTINUE, array( 'TCP_Ajax', 'ajax_continue' ) );

			// قوانین داینامیک (فیلترهای قیمت + ذخیره + جستجو).
			TCP_Rules::hooks();
			TCP_Coupons::hooks();
		}

		public static function activate() {
			TCP_DB::activate();
			TCP_Rules::activate();
			self::maybe_migrate();
		}

		public static function wc_active() {
			return function_exists( 'WC' ) && function_exists( 'wc_get_product' );
		}

		/* -----------------------------------------------------------------
		 * مهاجرت از دو افزونهٔ قبلی (یک‌بار، فقط اگر گزینهٔ ما هنوز ساخته نشده)
		 * --------------------------------------------------------------- */

		public static function maybe_migrate() {
			if ( false === get_option( self::OPTION, false ) ) {
				$legacy = get_option( 'tcbpm_settings', null );
				add_option( self::OPTION, is_array( $legacy ) ? $legacy : array(), '', false );
			}
			if ( false === get_option( TCP_Rules::OPTION, false ) ) {
				$legacy = get_option( 'tisacase_pricing_manager_rules_v1', null );
				add_option( TCP_Rules::OPTION, is_array( $legacy ) ? $legacy : TCP_Rules::defaults(), '', false );
			}
			self::upgrade_preview_sample_size();
		}

		/** یک‌بار مقدار پیش‌فرض قدیمیِ پیش‌نمایش را به نمونهٔ بزرگ‌تر ارتقا می‌دهد. */
		private static function upgrade_preview_sample_size() {
			$marker = 'tcp_preview_sample_size_v2_migrated';
			if ( get_option( $marker, false ) ) {
				return;
			}

			$settings = get_option( self::OPTION, array() );
			if ( is_array( $settings ) && isset( $settings['sample_size'] ) && 8 === absint( $settings['sample_size'] ) ) {
				$settings['sample_size'] = 30;
				update_option( self::OPTION, $settings );
			}
			add_option( $marker, 1, '', false );
		}

		/** آیا یکی از افزونه‌های قدیمی هنوز فعال است؟ (برای هشدار تداخل) */
		public static function legacy_active() {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$out = array();
			if ( is_plugin_active( self::LEGACY_BULK ) ) {
				$out[] = 'TisaCase Bulk Price Manager';
			}
			if ( is_plugin_active( self::LEGACY_RULES ) ) {
				$out[] = 'TisaCase Pricing Manager';
			}
			return $out;
		}

		/* -----------------------------------------------------------------
		 * تنظیمات عملیات گروهی
		 * --------------------------------------------------------------- */

		public static function defaults() {
			return array(
				'min_capability'    => 'manage_woocommerce',
				'batch_size'        => 5,
				'confirm_threshold' => 500,
				// با ثبت cursor بعد از هر محصول، اجرای زنده همیشه تازه می‌شود؛
				// ۵ دقیقه سکوت یعنی اجرا یتیم شده و باید «ناتمام» شود تا «ادامه» فعال شود.
				'lock_minutes'      => 5,
				'retention_days'    => 90,
				'logging'           => 1,
				'rollback'          => 1,
				'scheduled'         => 1,
				'max_amount'        => 1000000000,
				'sample_size'       => 30,
				'cron_pages'        => 20,
				'round_digit'       => 8,   // رقم پایانی قیمت‌های رند (…۸٬۰۰۰)
				'round_step'        => 0,   // ۰ = خودکار بر اساس واحد پول (ریال ۱۰۰٬۰۰۰ / تومان ۱۰٬۰۰۰)
				'jitter_percent'    => 5.0, // دامنهٔ تخفیف متغیر (±)
			);
		}

		public static function get_settings() {
			$saved = get_option( self::OPTION, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			return wp_parse_args( $saved, self::defaults() );
		}

		public static function setting( $key ) {
			$s = self::get_settings();
			return isset( $s[ $key ] ) ? $s[ $key ] : null;
		}

		public static function update_settings( $raw ) {
			$defaults = self::defaults();
			$booleans = array( 'logging', 'rollback', 'scheduled' );
			$clean    = array();
			foreach ( $defaults as $k => $v ) {
				$clean[ $k ] = $v;
				if ( in_array( $k, $booleans, true ) ) {
					$clean[ $k ] = empty( $raw[ $k ] ) ? 0 : 1;
					continue;
				}
				if ( ! isset( $raw[ $k ] ) || '' === $raw[ $k ] ) {
					continue;
				}
				if ( in_array( $k, array( 'round_digit', 'round_step' ), true ) ) {
					$clean[ $k ] = absint( $raw[ $k ] );
				} elseif ( 'sample_size' === $k ) {
					$clean[ $k ] = max( 1, min( 100, absint( $raw[ $k ] ) ) );
				} elseif ( is_int( $v ) ) {
					$clean[ $k ] = max( 1, absint( $raw[ $k ] ) );
				} elseif ( is_float( $v ) ) {
					$clean[ $k ] = max( 0, (float) $raw[ $k ] );
				}
			}
			$clean['round_digit']    = min( 9, $clean['round_digit'] );
			$clean['jitter_percent'] = max( 0.1, min( 50, (float) $clean['jitter_percent'] ) );
			$cap                     = isset( $raw['min_capability'] ) ? sanitize_key( $raw['min_capability'] ) : '';
			$clean['min_capability'] = in_array( $cap, array( 'manage_woocommerce', 'edit_products', 'manage_options' ), true ) ? $cap : $defaults['min_capability'];
			update_option( self::OPTION, $clean );
			return $clean;
		}

		public static function can() {
			return is_user_logged_in() && current_user_can( self::setting( 'min_capability' ) );
		}

		/**
		 * اجرای به‌روزرسانی جدول lookup ووکامرس در پس‌زمینه. پرچم pending با رسیدن
		 * رویداد پاک می‌شود؛ اگر رویداد اصلاً اجرا نشده باشد (کرون غیرفعال)،
		 * register_cron هنگام بارگذاری بعدی آن را دوباره زمان‌بندی می‌کند.
		 */
		public static function refresh_lookup_tables() {
			if ( ! get_option( self::OPT_LOOKUP_PENDING, false ) ) {
				return;
			}
			delete_option( self::OPT_LOOKUP_PENDING );
			if ( self::wc_active() && function_exists( 'wc_update_product_lookup_tables' ) ) {
				wc_update_product_lookup_tables();
			}
		}

		public static function logging_enabled() {
			return ! empty( self::setting( 'logging' ) );
		}

		public static function rollback_enabled() {
			return self::logging_enabled() && ! empty( self::setting( 'rollback' ) );
		}

		public static function batch_size() {
			return max( 1, min( 100, absint( self::setting( 'batch_size' ) ) ) );
		}

		public static function lock_minutes() {
			return max( 2, absint( self::setting( 'lock_minutes' ) ) );
		}

		public static function confirm_threshold() {
			return max( 0, absint( self::setting( 'confirm_threshold' ) ) );
		}

		public static function max_amount() {
			$v = (float) self::setting( 'max_amount' );
			return $v > 0 ? $v : 1000000000;
		}

		public static function sample_size() {
			return max( 1, min( 100, absint( self::setting( 'sample_size' ) ) ) );
		}

		public static function translation( $key ) {
			$labels = array(
				'bulk'        => 'اجرای گروهی',
				'scheduled'   => 'اجرای زمان‌بندی‌شده',
				'rollback'    => 'بازگردانی',
				'running'     => 'در حال اجرا',
				'queued'      => 'در صف زمان‌بندی',
				'done'        => 'کامل شد',
				'stopped'     => 'متوقف شد',
				'failed'      => 'ناموفق',
				'interrupted' => 'ناتمام (قابل ادامه)',
				'rolled_back' => 'بازگردانی شد',
				'cancelled'   => 'انصراف داده شد',
			);
			return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
		}

		/**
		 * محافظ CSV Injection: اگر مقدار با نویسه‌های فرمول‌ساز شروع شود، با یک '
		 * خنثی می‌شود تا هنگام باز شدن در اکسل به‌عنوان فرمول اجرا نشود.
		 * مقدارهای صرفاً عددی دست‌نخورده می‌مانند تا ماهیت عددی‌شان در اکسل حفظ شود.
		 */
		public static function csv_cell( $v ) {
			$v = (string) $v;
			if ( '' === $v || is_numeric( $v ) ) {
				return $v;
			}
			if ( false !== strpbrk( $v[0], "=+-@\t\r" ) ) {
				return "'" . $v;
			}
			return $v;
		}
	}
}
