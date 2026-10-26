<?php
/**
 * هسته، تنظیمات و قراردادهای عمومی افزونه.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Core' ) ) {

	final class TCAS_Core {
		const PAGE            = 'tisacase-ariocase-sync';
		const NONCE           = 'tcas_admin_nonce';
		const OPTION_SETTINGS         = 'tcas_ariocase_settings';
		const OPTION_SETTINGS_VERSION = 'tcas_ariocase_settings_version';
		const SETTINGS_VERSION        = 2;
		const OPTION_LOGS            = 'tcas_ariocase_logs';
		const MAX_CODES              = 50;

		private static $instance = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			self::maybe_upgrade_settings();
			add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
			if ( class_exists( 'WooCommerce' ) ) {
				TCAS_Live::init();
			}
			if ( is_admin() ) {
				TCAS_Admin::init();
				TCAS_Ajax::init();
			}
		}

		public static function load_textdomain() {
			load_plugin_textdomain( 'tisacase-ariocase-sync', false, dirname( plugin_basename( TCAS_FILE ) ) . '/languages' );
		}

		public static function can() {
			return current_user_can( 'manage_woocommerce' );
		}

		public static function defaults() {
			return array(
				'enabled'             => 'yes',
				'live_scope'          => 'purchase',
				'persist_stock'       => 'yes',
				'checkout_guard'      => 'yes',
				'base_url'            => 'https://ariocase.com/wp-json/wc/v3',
				'product_endpoint'    => 'products?status=publish&per_page=100&sku={code}',
				'slug_endpoint'       => 'products?status=publish&per_page=100&slug={slug}',
				'stock_endpoint'      => '',
				'code_parameter'      => 'sku',
				'auth_type'           => 'bearer',
				'auth_header'         => 'Authorization',
				'auth_query'          => 'api_key',
				'api_key'             => '',
				'timeout'             => 8,
				'item_path'           => '',
				'items_path'          => '',
				'field_code'          => '',
				'field_name'          => '',
				'field_stock'         => '',
				'field_stock_status'  => '',
				'field_variations'    => '',
				'field_attributes'    => '',
				'field_images'        => '',
				'field_price'         => '',
				'field_description'   => '',
			);
		}

		public static function settings() {
			$saved    = get_option( self::OPTION_SETTINGS, array() );
			$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
			if ( ! in_array( $settings['live_scope'], array( 'all', 'purchase' ), true ) ) {
				$settings['live_scope'] = 'purchase';
			}
			return $settings;
		}

		public static function api_key( $settings = null ) {
			if ( defined( 'TISACASE_ARIOCASE_API_KEY' ) && '' !== (string) TISACASE_ARIOCASE_API_KEY ) {
				return (string) TISACASE_ARIOCASE_API_KEY;
			}
			$settings = is_array( $settings ) ? $settings : self::settings();
			return isset( $settings['api_key'] ) ? (string) $settings['api_key'] : '';
		}

		public static function is_configured( $settings = null ) {
			$settings = is_array( $settings ) ? $settings : self::settings();
			if ( empty( $settings['base_url'] ) || empty( $settings['product_endpoint'] ) ) {
				return false;
			}
			return 'none' === $settings['auth_type'] || '' !== self::api_key( $settings );
		}

		public static function sanitize_settings( $input, $current = array() ) {
			$input    = is_array( $input ) ? $input : array();
			$current  = wp_parse_args( is_array( $current ) ? $current : array(), self::defaults() );
			$clean    = self::defaults();
			$booleans = array( 'enabled', 'persist_stock', 'checkout_guard' );
			foreach ( $booleans as $key ) {
				$clean[ $key ] = ! empty( $input[ $key ] ) ? 'yes' : 'no';
			}
			$live_scope          = isset( $input['live_scope'] ) ? sanitize_key( $input['live_scope'] ) : 'purchase';
			$clean['live_scope'] = in_array( $live_scope, array( 'all', 'purchase' ), true ) ? $live_scope : 'purchase';

			$url_keys = array( 'base_url', 'product_endpoint', 'slug_endpoint', 'stock_endpoint' );
			foreach ( $url_keys as $key ) {
				$clean[ $key ] = isset( $input[ $key ] ) ? trim( sanitize_text_field( $input[ $key ] ) ) : '';
			}
			$clean['base_url'] = untrailingslashit( $clean['base_url'] );

			$text_keys = array(
				'code_parameter', 'auth_header', 'auth_query', 'item_path', 'items_path',
				'field_code', 'field_name', 'field_stock', 'field_stock_status',
				'field_variations', 'field_attributes', 'field_images', 'field_price', 'field_description',
			);
			foreach ( $text_keys as $key ) {
				$clean[ $key ] = isset( $input[ $key ] ) ? trim( sanitize_text_field( $input[ $key ] ) ) : '';
			}
			if ( '' === $clean['code_parameter'] ) {
				$clean['code_parameter'] = 'sku';
			}
			if ( '' === $clean['auth_header'] ) {
				$clean['auth_header'] = 'Authorization';
			}
			if ( '' === $clean['auth_query'] ) {
				$clean['auth_query'] = 'api_key';
			}
			$auth_type = isset( $input['auth_type'] ) ? sanitize_key( $input['auth_type'] ) : 'bearer';
			$clean['auth_type'] = in_array( $auth_type, array( 'bearer', 'header', 'query', 'none' ), true ) ? $auth_type : 'bearer';
			$clean['timeout']   = isset( $input['timeout'] ) ? max( 2, min( 20, absint( $input['timeout'] ) ) ) : 8;

			// خالی‌گذاشتن فیلد کلید یعنی حفظ مقدار قبلی؛ حذف فقط با چک‌باکس صریح.
			$clean['api_key'] = isset( $current['api_key'] ) ? (string) $current['api_key'] : '';
			if ( ! empty( $input['clear_api_key'] ) ) {
				$clean['api_key'] = '';
			} elseif ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) ) {
				$clean['api_key'] = trim( sanitize_text_field( $input['api_key'] ) );
			}
			return $clean;
		}

		public static function parse_codes( $raw ) {
			if ( is_array( $raw ) ) {
				$parts = $raw;
			} else {
				$parts = preg_split( '/[\s,،;]+/u', (string) $raw );
			}
			$out = array();
			foreach ( (array) $parts as $part ) {
				$code = trim( sanitize_text_field( $part ) );
				if ( '' === $code || strlen( $code ) > 190 ) {
					continue;
				}
				$out[ $code ] = $code;
				if ( count( $out ) >= self::MAX_CODES ) {
					break;
				}
			}
			return array_values( $out );
		}

		public static function parse_pairs( $raw ) {
			$lines = is_array( $raw ) ? $raw : preg_split( '/\r\n|\r|\n/', (string) $raw );
			$out = array(); $seen = array(); $locals = array(); $remotes = array();
			foreach ( (array) $lines as $line ) {
				$line = trim( sanitize_text_field( $line ) );
				if ( '' === $line ) {
					continue;
				}
				if ( count( $out ) >= self::MAX_CODES ) {
					break;
				}
				$entry = array( 'local_code' => '', 'remote_code' => '', 'error' => '' );
				$parts = preg_split( '/\s+-\s+/u', $line );
				if ( 2 !== count( $parts ) ) {
					$entry['local_code'] = $line;
					$entry['error'] = 'قالب جفت معتبر نیست؛ بنویسید: SKU تیساکیس - لینک یا SKU محصول ArioCase';
					$out[] = $entry;
					continue;
				}
				$local  = trim( (string) $parts[0] );
				$remote = trim( (string) $parts[1] );
				$entry['local_code'] = $local;
				$entry['remote_code'] = $remote;
				$remote_limit = preg_match( '#^https?://#i', $remote ) ? 2048 : 190;
				if ( '' === $local || '' === $remote || strlen( $local ) > 190 || strlen( $remote ) > $remote_limit ) {
					$entry['error'] = 'SKU یا مرجع ArioCase خالی/بسیار طولانی است.';
					$out[] = $entry;
					continue;
				}
				$fingerprint = json_encode( array( $local, $remote ) );
				if ( isset( $seen[ $fingerprint ] ) ) {
					continue;
				}
				if ( isset( $locals[ $local ] ) && $locals[ $local ] !== $remote ) {
					$entry['error'] = 'این SKU تیساکیس در همین دسته با کد دیگری جفت شده است.';
					$out[] = $entry;
					continue;
				}
				if ( isset( $remotes[ $remote ] ) && $remotes[ $remote ] !== $local ) {
					$entry['error'] = 'این کد ArioCase در همین دسته با SKU دیگری جفت شده است.';
					$out[] = $entry;
					continue;
				}
				$seen[ $fingerprint ] = true;
				$locals[ $local ] = $remote;
				$remotes[ $remote ] = $local;
				$out[] = $entry;
			}
			return $out;
		}

		/**
		 * قدیمی‌ترین نسخهٔ تنظیمات، Getterهای محصول را زنده می‌خواند. مهاجرت به
		 * Snapshot محلی، درخواست‌های عمومی فروشگاه را از API خارجی جدا می‌کند.
		 */
		public static function maybe_upgrade_settings() {
			$version = absint( get_option( self::OPTION_SETTINGS_VERSION, 0 ) );
			if ( $version >= self::SETTINGS_VERSION ) {
				return;
			}

			$saved = get_option( self::OPTION_SETTINGS, false );
			if ( is_array( $saved ) ) {
				$saved['live_scope'] = 'purchase';
				update_option( self::OPTION_SETTINGS, $saved, false );
			}
			update_option( self::OPTION_SETTINGS_VERSION, self::SETTINGS_VERSION, false );
		}

		public static function activate() {
			self::maybe_upgrade_settings();
			if ( false === get_option( self::OPTION_SETTINGS, false ) ) {
				add_option( self::OPTION_SETTINGS, self::defaults(), '', 'no' );
			}
			if ( false === get_option( self::OPTION_LOGS, false ) ) {
				add_option( self::OPTION_LOGS, array(), '', 'no' );
			}
			update_option( self::OPTION_SETTINGS_VERSION, self::SETTINGS_VERSION, false );
		}

		public static function deactivate() {
			// نگاشت‌ها و تنظیمات با غیرفعال‌سازی حفظ می‌شوند.
		}
	}
}
