<?php
/**
 * هستهٔ اصلی افزونه: تنظیمات، الگوهای پیش‌فرض، مدیریت هوک‌ها و سطح دسترسی.
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBVM_Core' ) ) {

	final class TCBVM_Core {

		const PAGE_SLUG       = 'tisacase-bulk-variation-manager';
		const NONCE_ACTION    = 'tcbvm_action_nonce';
		const OPTION_SETTINGS = 'tcbvm_settings';
		const OPTION_PRESETS  = 'tcbvm_presets';
		const OPTION_RUNS     = 'tcbvm_history_runs';
		const MAX_RUNS_SAVED  = 20;

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
			TCBVM_Admin::init();
			TCBVM_Ajax::init();
		}

		public static function load_textdomain() {
			load_plugin_textdomain(
				'tisacase-bvm',
				false,
				dirname( plugin_basename( TCBVM_FILE ) ) . '/languages'
			);
		}

		/**
		 * بررسی سطح دسترسی کاربر برای اجرای عملیات‌ها.
		 */
		public static function can() {
			return current_user_can( 'manage_woocommerce' );
		}

		/**
		 * تنظیمات پیش‌فرض افزونه.
		 */
		public static function default_settings() {
			return array(
				'batch_size'            => 2,
				'target_attr_name'      => 'مدل گوشی',
				'backup_retention_days' => 90,
			);
		}

		public static function get_settings() {
			$saved    = get_option( self::OPTION_SETTINGS, array() );
			$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::default_settings() );
			// Runtime work bounds cap each request at two products even if an older
			// saved option contains the former 1–50 range.
			$settings['batch_size'] = max( 1, min( 2, absint( $settings['batch_size'] ) ) );
			return $settings;
		}

		public static function get_batch_size() {
			$settings = self::get_settings();
			return isset( $settings['batch_size'] ) ? max( 1, min( 2, absint( $settings['batch_size'] ) ) ) : 2;
		}

		public static function update_settings( $new_settings ) {
			return update_option( self::OPTION_SETTINGS, $new_settings );
		}

		/**
		 * الگوهای آماده مدل‌های گوشی مخصوص قاب‌های اسپیس و چاپی تیساکیس.
		 */
		public static function default_presets() {
			return array(
				'iphone-all' => array(
					'id'          => 'iphone-all',
					'name'        => 'سری کامل آیفون (۱۱ تا ۱۶ پرو مکس)',
					'description' => 'پرفروش‌ترین مدل‌های آیفون شامل سری ۱۱ تا سری ۱۶',
					'is_builtin'  => true,
					'models'      => array(
						'iPhone 16 Pro Max',
						'iPhone 16 Pro',
						'iPhone 16 Plus',
						'iPhone 16',
						'iPhone 15 Pro Max',
						'iPhone 15 Pro',
						'iPhone 15 Plus',
						'iPhone 15',
						'iPhone 14 Pro Max',
						'iPhone 14 Pro',
						'iPhone 14 Plus',
						'iPhone 14',
						'iPhone 13 Pro Max',
						'iPhone 13 Pro',
						'iPhone 13',
						'iPhone 13 mini',
						'iPhone 12 Pro Max',
						'iPhone 12 Pro',
						'iPhone 12',
						'iPhone 11 Pro Max',
						'iPhone 11',
					),
				),
				'iphone-new' => array(
					'id'          => 'iphone-new',
					'name'        => 'فقط سری جدید آیفون (۱۵ و ۱۶)',
					'description' => 'مدل‌های جدید آیفون برای اضافه کردن سریع به محصولات قدیمی',
					'is_builtin'  => true,
					'models'      => array(
						'iPhone 16 Pro Max',
						'iPhone 16 Pro',
						'iPhone 16 Plus',
						'iPhone 16',
						'iPhone 15 Pro Max',
						'iPhone 15 Pro',
						'iPhone 15 Plus',
						'iPhone 15',
					),
				),
				'samsung-flagship' => array(
					'id'          => 'samsung-flagship',
					'name'        => 'پرچمداران سامسونگ (سری S و Z)',
					'description' => 'مدل‌های رده‌بالای سامسونگ گلکسی سری S21 تا S24 Ultra',
					'is_builtin'  => true,
					'models'      => array(
						'Galaxy S24 Ultra',
						'Galaxy S24 Plus',
						'Galaxy S24',
						'Galaxy S23 Ultra',
						'Galaxy S23 Plus',
						'Galaxy S23',
						'Galaxy S23 FE',
						'Galaxy S22 Ultra',
						'Galaxy S22 Plus',
						'Galaxy S22',
						'Galaxy S21 FE',
						'Galaxy Z Fold 5',
						'Galaxy Z Flip 5',
					),
				),
				'samsung-a-series' => array(
					'id'          => 'samsung-a-series',
					'name'        => 'سری محبوب سامسونگ گلکسی A',
					'description' => 'پرفروش‌ترین گوشی‌های میان‌رده و اقتصادی سامسونگ',
					'is_builtin'  => true,
					'models'      => array(
						'Galaxy A55',
						'Galaxy A54',
						'Galaxy A35',
						'Galaxy A34',
						'Galaxy A25',
						'Galaxy A24',
						'Galaxy A15',
						'Galaxy A14',
						'Galaxy A05s',
						'Galaxy A05',
					),
				),
				'xiaomi-popular' => array(
					'id'          => 'xiaomi-popular',
					'name'        => 'شیائومی و پوکو پرفروش',
					'description' => 'سری‌های محبوب ردمی نوت و پوکو',
					'is_builtin'  => true,
					'models'      => array(
						'Redmi Note 13 Pro Plus',
						'Redmi Note 13 Pro',
						'Redmi Note 13 4G/5G',
						'Redmi Note 12 Pro',
						'Redmi Note 12 4G',
						'Poco X6 Pro',
						'Poco X6',
						'Poco M6 Pro',
						'Poco F6',
					),
				),
			);
		}

		public static function get_presets() {
			$custom = get_option( self::OPTION_PRESETS, array() );
			if ( ! is_array( $custom ) ) {
				$custom = array();
			}
			$defaults = self::default_presets();
			return array_merge( $defaults, $custom );
		}

		public static function save_custom_preset( $id, $name, $description, array $models ) {
			$id = sanitize_key( $id );
			if ( empty( $id ) || empty( $name ) || empty( $models ) ) {
				return false;
			}
			$custom = get_option( self::OPTION_PRESETS, array() );
			if ( ! is_array( $custom ) ) {
				$custom = array();
			}
			$clean_models = array_values( array_filter( array_map( 'sanitize_text_field', $models ) ) );
			$custom[ $id ] = array(
				'id'          => $id,
				'name'        => sanitize_text_field( $name ),
				'description' => sanitize_text_field( $description ),
				'is_builtin'  => false,
				'models'      => $clean_models,
				'updated_at'  => current_time( 'mysql' ),
			);
			return update_option( self::OPTION_PRESETS, $custom );
		}

		public static function delete_custom_preset( $id ) {
			$id = sanitize_key( $id );
			$custom = get_option( self::OPTION_PRESETS, array() );
			if ( isset( $custom[ $id ] ) ) {
				unset( $custom[ $id ] );
				return update_option( self::OPTION_PRESETS, $custom );
			}
			return false;
		}

		public static function activate() {
			if ( false === get_option( self::OPTION_SETTINGS ) ) {
				update_option( self::OPTION_SETTINGS, self::default_settings() );
			}
			if ( false === get_option( self::OPTION_PRESETS ) ) {
				update_option( self::OPTION_PRESETS, array() );
			}
		}

		public static function deactivate() {
			// Do not delete options or history runs on normal deactivation.
		}
	}
}
