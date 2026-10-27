<?php
/** Registry and dispatch layer for inventory partners.
 *
 * @package TisaCase_Inventory_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Partners' ) ) {
	final class TCAS_Partners {
		const OPTION_SETTINGS = 'tcas_partner_provider_settings';
		const DEFAULT_ID      = 'ariocase';

		private static $providers = null;

		/** Registered providers are objects implementing TCAS_Partner_Provider. */
		public static function all() {
			if ( null !== self::$providers ) {
				return self::$providers;
			}
			$providers = array( self::DEFAULT_ID => new TCAS_ArioCase_Provider() );
			$providers = apply_filters( 'tcas_partner_providers', $providers );
			$valid     = array();
			foreach ( (array) $providers as $provider ) {
				if ( ! $provider instanceof TCAS_Partner_Provider ) {
					continue;
				}
				$id = sanitize_key( $provider->id() );
				if ( '' === $id || $id !== $provider->id() || isset( $valid[ $id ] ) ) {
					continue;
				}
				$valid[ $id ] = $provider;
			}
			self::$providers = $valid;
			return self::$providers;
		}

		public static function get( $id = self::DEFAULT_ID ) {
			$id        = sanitize_key( $id );
			$providers = self::all();
			return isset( $providers[ $id ] ) ? $providers[ $id ] : null;
		}

		public static function label( $id = self::DEFAULT_ID ) {
			$provider = self::get( $id );
			return $provider ? (string) $provider->label() : (string) $id;
		}

		public static function ids() {
			return array_keys( self::all() );
		}

		public static function active_id() {
			$settings = get_option( TCAS_Core::OPTION_SETTINGS, array() );
			$id       = is_array( $settings ) && isset( $settings['active_partner_id'] ) ? sanitize_key( $settings['active_partner_id'] ) : self::DEFAULT_ID;
			return self::get( $id ) ? $id : self::DEFAULT_ID;
		}

		public static function selected_id() {
			$id = isset( $_REQUEST['partner_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['partner_id'] ) ) : self::active_id(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return self::get( $id ) ? $id : self::active_id();
		}

		public static function settings( $id = self::DEFAULT_ID ) {
			$id = sanitize_key( $id );
			$provider = self::get( $id );
			if ( ! $provider ) {
				return array();
			}
			if ( self::DEFAULT_ID === $id ) {
				return TCAS_Core::settings();
			}
			$all     = get_option( self::OPTION_SETTINGS, array() );
			$stored  = is_array( $all ) && isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();
			$common  = TCAS_Core::settings();
			$provider_defaults = $provider->defaults();
			$provider_defaults = is_array( $provider_defaults ) ? $provider_defaults : array();
			$defaults = array_merge( $provider_defaults, array_intersect_key( $common, array_flip( array( 'enabled', 'live_scope', 'persist_stock', 'checkout_guard', 'stock_mode', 'fail_mode', 'sweep_interval', 'sweep_batch' ) ) ) );
			return array_merge( $defaults, $stored, array_intersect_key( $common, array_flip( array( 'enabled', 'live_scope', 'persist_stock', 'checkout_guard', 'stock_mode', 'fail_mode', 'sweep_interval', 'sweep_batch' ) ) ) );
		}

		public static function is_configured( $id = self::DEFAULT_ID ) {
			$provider = self::get( $id );
			return $provider ? (bool) $provider->is_configured( self::settings( $id ) ) : false;
		}

		public static function save_settings( $id, $input ) {
			$id = sanitize_key( $id );
			$provider = self::get( $id );
			if ( ! $provider ) {
				return new WP_Error( 'tcas_unknown_partner', 'همکار انتخاب‌شده ثبت نشده است.' );
			}
			$input = is_array( $input ) ? $input : array();
			if ( self::DEFAULT_ID === $id ) {
				$current  = TCAS_Core::settings();
				$settings = TCAS_Core::sanitize_settings( $input, $current );
				$settings['active_partner_id'] = self::DEFAULT_ID;
				update_option( TCAS_Core::OPTION_SETTINGS, $settings, false );
				return true;
			}

			$current  = self::settings( $id );
			$settings = $provider->sanitize_settings( $input, $current );
			if ( ! is_array( $settings ) ) {
				return new WP_Error( 'tcas_invalid_partner_settings', 'تنظیمات همکار قابل ذخیره‌سازی نیست.' );
			}
			$all = get_option( self::OPTION_SETTINGS, array() );
			$all = is_array( $all ) ? $all : array();
			$all[ $id ] = $settings;
			update_option( self::OPTION_SETTINGS, $all, false );

			// Shared stock behavior remains in the historic option, avoiding a breaking migration.
			$current_common = TCAS_Core::settings();
			$common = TCAS_Core::sanitize_settings( $input, $current_common );
			foreach ( array( 'enabled', 'live_scope', 'persist_stock', 'checkout_guard', 'stock_mode', 'fail_mode', 'sweep_interval', 'sweep_batch' ) as $key ) {
				$current_common[ $key ] = $common[ $key ];
			}
			$current_common['active_partner_id'] = $id;
			update_option( TCAS_Core::OPTION_SETTINGS, $current_common, false );
			return true;
		}

		private static function invoke( $id, $method, $args ) {
			$provider = self::get( $id );
			if ( ! $provider ) {
				return new WP_Error( 'tcas_unknown_partner', 'همکار انتخاب‌شده در دسترس نیست.' );
			}
			if ( ! self::is_configured( $id ) ) {
				return new WP_Error( 'tcas_partner_not_configured', 'ابتدا تنظیمات اتصال این همکار را کامل کنید.' );
			}
			try {
				$result = call_user_func_array( array( $provider, $method ), array_merge( $args, array( self::settings( $id ) ) ) );
			} catch ( Throwable $error ) {
				return new WP_Error( 'tcas_partner_exception', 'در اجرای اتصال همکار خطای داخلی رخ داد.' );
			}
			if ( is_wp_error( $result ) ) {
				$code = $result->get_error_code();
				$message = $provider->redact( $result->get_error_message(), self::settings( $id ) );
				return new WP_Error( $code, $message, $result->get_error_data( $code ) );
			}
			if ( ! is_array( $result ) ) {
				return new WP_Error( 'tcas_partner_invalid_result', 'پاسخ همکار با قرارداد یکپارچهٔ افزونه سازگار نیست.' );
			}
			if ( 'fetch_reference' === $method ) {
				$required = array( 'code', 'remote_id', 'name', 'type', 'stock', 'variations' );
				foreach ( $required as $key ) {
					if ( ! array_key_exists( $key, $result ) ) {
						return new WP_Error( 'tcas_partner_invalid_product', 'پاسخ محصول همکار با قرارداد نرمال‌شده سازگار نیست.' );
					}
				}
				if ( ! is_scalar( $result['code'] ) || ! is_scalar( $result['name'] ) || ! is_scalar( $result['type'] ) || ! is_array( $result['stock'] ) || ! is_array( $result['variations'] ) ) {
					return new WP_Error( 'tcas_partner_invalid_product', 'ساختار محصول یا موجودی همکار معتبر نیست.' );
				}
				$result['parent_remote_id'] = isset( $result['parent_remote_id'] ) ? absint( $result['parent_remote_id'] ) : 0;
			}
			if ( 'fetch_stock' === $method && ! array_key_exists( 'quantity', $result ) && empty( $result['status'] ) ) {
				return new WP_Error( 'tcas_partner_invalid_stock', 'پاسخ موجودی همکار قابل تشخیص نیست.' );
			}
			if ( 'resolve_link' === $method ) {
				foreach ( array( 'product_id', 'name', 'type', 'variations' ) as $key ) {
					if ( ! array_key_exists( $key, $result ) ) {
						return new WP_Error( 'tcas_partner_invalid_link', 'پاسخ Resolve همکار با قرارداد نرمال‌شده سازگار نیست.' );
					}
				}
				if ( ! is_array( $result['variations'] ) || ! is_scalar( $result['name'] ) || ! is_scalar( $result['type'] ) ) {
					return new WP_Error( 'tcas_partner_invalid_link', 'ساختار محصول Resolve‌شدهٔ همکار معتبر نیست.' );
				}
				$result['product_id'] = absint( $result['product_id'] );
				$result['reference'] = isset( $result['reference'] ) ? (string) $result['reference'] : ( ! empty( $result['code'] ) ? (string) $result['code'] : ( $result['product_id'] ? 'ID:' . $result['product_id'] : '' ) );
				if ( '' === $result['reference'] ) {
					return new WP_Error( 'tcas_partner_invalid_link', 'پاسخ همکار شناسه یا مرجع قابل استفاده ندارد.' );
				}
			}
			if ( in_array( $method, array( 'fetch_reference', 'resolve_link' ), true ) ) {
				$result['partner_id'] = $id;
				$result['partner_label'] = self::label( $id );
				$result['shop'] = $id;
				$result['shop_label'] = self::label( $id );
			}
			return $result;
		}

		public static function fetch_reference( $id, $reference ) {
			return self::invoke( $id, 'fetch_reference', array( $reference ) );
		}

		public static function fetch_stock( $id, $reference, $identifiers = array() ) {
			return self::invoke( $id, 'fetch_stock', array( $reference, $identifiers ) );
		}

		public static function resolve_link( $id, $reference ) {
			return self::invoke( $id, 'resolve_link', array( $reference ) );
		}

		public static function normalize_id( $id ) {
			$id = sanitize_key( $id );
			// Only an omitted legacy identifier means ArioCase; never route an unknown ID to another API.
			return '' === $id ? self::DEFAULT_ID : $id;
		}
	}
}
