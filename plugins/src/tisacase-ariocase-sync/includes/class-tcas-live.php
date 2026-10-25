<?php
/**
 * خواندن زنده با کش فقط در همان Request و نگهبان خرید.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Live' ) ) {

	final class TCAS_Live {
		private static $states   = array();
		private static $applying = array();
		private static $notices  = array();

		public static function init() {
			foreach ( array( 'woocommerce_product_get_stock_quantity', 'woocommerce_product_variation_get_stock_quantity' ) as $hook ) {
				add_filter( $hook, array( __CLASS__, 'filter_quantity' ), 20, 2 );
			}
			foreach ( array( 'woocommerce_product_get_stock_status', 'woocommerce_product_variation_get_stock_status' ) as $hook ) {
				add_filter( $hook, array( __CLASS__, 'filter_status' ), 20, 2 );
			}
			foreach ( array( 'woocommerce_product_get_manage_stock', 'woocommerce_product_variation_get_manage_stock' ) as $hook ) {
				add_filter( $hook, array( __CLASS__, 'filter_manage_stock' ), 20, 2 );
			}
			add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 25, 6 );
			add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_cart' ), 25 );
			add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_checkout' ), 25, 2 );
		}

		private static function enabled() {
			$settings = TCAS_Core::settings();
			return 'yes' === $settings['enabled'] && TCAS_Core::is_configured( $settings );
		}

		private static function filter_context() {
			if ( ! self::enabled() ) {
				return false;
			}
			if ( is_admin() && ! wp_doing_ajax() ) {
				return false;
			}
			$settings = TCAS_Core::settings();
			if ( 'all' === $settings['live_scope'] || wp_doing_ajax() ) {
				return true;
			}
			return ( function_exists( 'is_product' ) && is_product() )
				|| ( function_exists( 'is_cart' ) && is_cart() )
				|| ( function_exists( 'is_checkout' ) && is_checkout() );
		}

		private static function product_id( $product ) {
			return is_object( $product ) && is_callable( array( $product, 'get_id' ) ) ? absint( $product->get_id() ) : 0;
		}

		/** واکشی یک بار در Request؛ برای محصولات نگاشت‌نشده null برمی‌گردد. */
		public static function state( $product_id, $fresh = false ) {
			$product_id = absint( $product_id );
			if ( ! $product_id || ! self::enabled() ) {
				return null;
			}
			if ( $fresh ) {
				unset( self::$states[ $product_id ] );
			}
			if ( array_key_exists( $product_id, self::$states ) ) {
				return self::$states[ $product_id ];
			}
			if ( ! empty( self::$applying[ $product_id ] ) ) {
				return null;
			}
			$product = wc_get_product( $product_id );
			if ( ! $product || $product->is_type( 'variable' ) || ! TCAS_Mapping::is_enabled( $product_id ) ) {
				self::$states[ $product_id ] = null;
				return null;
			}
			$code        = TCAS_Mapping::remote_code( $product_id );
			$identifiers = TCAS_Mapping::remote_identifiers( $product_id );
			$state       = TCAS_Api::fetch_stock( $code, $identifiers );
			self::$states[ $product_id ] = $state;
			if ( is_wp_error( $state ) ) {
				return $state;
			}

			TCAS_Mapping::remember( $product_id, $state );
			if ( ! empty( $state['product'] ) ) {
				$parent_remote_id = isset( $state['parent_remote_id'] ) ? $state['parent_remote_id'] : 0;
				TCAS_Mapping::remember_product( $product_id, $state['product'], $parent_remote_id );
			}
			$settings = TCAS_Core::settings();
			if ( 'yes' === $settings['persist_stock'] ) {
				self::$applying[ $product_id ] = true;
				$result = TCAS_Stock::apply( $product, $state );
				unset( self::$applying[ $product_id ] );
				if ( is_wp_error( $result ) ) {
					TCAS_Mapping::remember_error( $product_id, $result->get_error_message() );
				}
			}
			return $state;
		}

		public static function refresh( $product_id ) {
			return self::state( $product_id, true );
		}

		public static function filter_quantity( $value, $product ) {
			if ( ! self::filter_context() ) {
				return $value;
			}
			$state = self::state( self::product_id( $product ) );
			return is_array( $state ) && null !== $state['quantity'] ? $state['quantity'] : $value;
		}

		public static function filter_status( $value, $product ) {
			if ( ! self::filter_context() ) {
				return $value;
			}
			$state = self::state( self::product_id( $product ) );
			return is_array( $state ) && ! empty( $state['status'] ) ? $state['status'] : $value;
		}

		public static function filter_manage_stock( $value, $product ) {
			if ( ! self::filter_context() ) {
				return $value;
			}
			$state = self::state( self::product_id( $product ) );
			if ( ! is_array( $state ) ) {
				return $value;
			}
			return null !== $state['quantity'];
		}

		private static function validate( $product_id, $quantity ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || ! TCAS_Mapping::is_enabled( $product_id ) ) {
				return true;
			}
			$state = self::state( $product_id );
			if ( null === $state ) {
				return true;
			}
			if ( is_wp_error( $state ) ) {
				return new WP_Error( 'tcas_live_unavailable', sprintf( 'موجودی «%s» از ArioCase قابل بررسی نیست. لطفاً کمی بعد دوباره تلاش کنید.', $product->get_name() ) );
			}
			if ( 'instock' !== $state['status'] ) {
				return new WP_Error( 'tcas_live_outofstock', sprintf( '«%s» در ArioCase فعلاً موجود نیست.', $product->get_name() ) );
			}
			if ( null !== $state['quantity'] && (float) $quantity > (float) $state['quantity'] ) {
				$available = (float) $state['quantity'];
				$available = floor( $available ) === $available ? (string) (int) $available : (string) $available;
				return new WP_Error( 'tcas_live_quantity', sprintf( 'موجودی «%s» فقط %s عدد است؛ تعداد سبد را اصلاح کنید.', $product->get_name(), $available ) );
			}
			return true;
		}

		private static function cart_quantities() {
			$out = array();
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return $out;
			}
			foreach ( (array) WC()->cart->get_cart() as $item ) {
				$id = ! empty( $item['variation_id'] ) ? absint( $item['variation_id'] ) : absint( $item['product_id'] );
				if ( $id ) {
					$out[ $id ] = isset( $out[ $id ] ) ? $out[ $id ] + (float) $item['quantity'] : (float) $item['quantity'];
				}
			}
			return $out;
		}

		private static function notice_once( $key, $message ) {
			if ( isset( self::$notices[ $key ] ) || ! function_exists( 'wc_add_notice' ) ) {
				return;
			}
			self::$notices[ $key ] = true;
			wc_add_notice( $message, 'error' );
		}

		public static function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
			$settings = TCAS_Core::settings();
			if ( ! $passed || 'yes' !== $settings['checkout_guard'] || ! self::enabled() ) {
				return $passed;
			}
			$id      = $variation_id ? absint( $variation_id ) : absint( $product_id );
			$current = self::cart_quantities();
			$needed  = (float) $quantity + ( isset( $current[ $id ] ) ? $current[ $id ] : 0 );
			$result  = self::validate( $id, $needed );
			if ( is_wp_error( $result ) ) {
				self::notice_once( 'add-' . $id, $result->get_error_message() );
				return false;
			}
			return $passed;
		}

		public static function validate_cart() {
			$settings = TCAS_Core::settings();
			if ( 'yes' !== $settings['checkout_guard'] || ! self::enabled() ) {
				return;
			}
			foreach ( self::cart_quantities() as $id => $quantity ) {
				$result = self::validate( $id, $quantity );
				if ( is_wp_error( $result ) ) {
					self::notice_once( 'cart-' . $id, $result->get_error_message() );
				}
			}
		}

		public static function validate_checkout( $data, $errors ) {
			$settings = TCAS_Core::settings();
			if ( 'yes' !== $settings['checkout_guard'] || ! self::enabled() ) {
				return;
			}
			foreach ( self::cart_quantities() as $id => $quantity ) {
				$result = self::validate( $id, $quantity );
				if ( is_wp_error( $result ) ) {
					$errors->add( $result->get_error_code(), $result->get_error_message() );
				}
			}
		}
	}
}
