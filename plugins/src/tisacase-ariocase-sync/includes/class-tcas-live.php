<?php
/**
 * نمایش موجودی از Snapshot محلی، تازه‌سازی پس‌زمینه و نگهبان زندهٔ خرید.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Live' ) ) {

	final class TCAS_Live {
		const REFRESH_HOOK             = 'tcas_refresh_mapped_stock';
		const REFRESH_TRANSIENT_PREFIX = 'tcas_stock_refresh_';
		const CACHE_TTL                = 300;
		const REFRESH_DELAY            = 5;
		const REFRESH_LOCK_TTL         = 300;
		const REFRESH_RETRY_TTL        = 300;
		const MAX_REFRESHES_PER_REQUEST  = 10;

		private static $states               = array();
		private static $cached_states        = array();
		private static $applying             = array();
		private static $notices              = array();
		private static $scheduled_refreshes  = 0;

		public static function init() {
			add_action( self::REFRESH_HOOK, array( __CLASS__, 'process_scheduled_refresh' ), 10, 1 );
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
			return 'yes' === $settings['enabled'];
		}

		/** false = no override, live = synchronous API (explicit opt-in), cached = local Snapshot. */
		private static function filter_context() {
			if ( ! self::enabled() ) {
				return false;
			}
			if ( is_admin() && ! wp_doing_ajax() ) {
				return false;
			}
			$settings = TCAS_Core::settings();
			return 'all' === $settings['live_scope'] ? 'live' : 'cached';
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
				unset( self::$states[ $product_id ], self::$cached_states[ $product_id ] );
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
			$partner_id  = TCAS_Mapping::partner_id( $product_id );
			if ( class_exists( 'TCAS_Partners' ) && ! TCAS_Partners::is_configured( $partner_id ) ) {
				self::$states[ $product_id ] = null;
				return null;
			}
			$state = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::fetch_stock( $partner_id, $code, $identifiers ) : TCAS_Api::fetch_stock( $code, $identifiers );
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
			unset( self::$cached_states[ $product_id ] );
			return $state;
		}

		/** Return the last good remote Snapshot without blocking the page request. */
		private static function cached_state( $product_id, $product = null ) {
			$product_id = absint( $product_id );
			if ( ! $product_id || ! self::enabled() || ! TCAS_Mapping::is_enabled( $product_id ) ) {
				return null;
			}
			if ( array_key_exists( $product_id, self::$cached_states ) ) {
				return self::$cached_states[ $product_id ];
			}
			$product = is_object( $product ) ? $product : wc_get_product( $product_id );
			if ( ! $product || $product->is_type( 'variable' ) ) {
				return null;
			}

			$raw_quantity = get_post_meta( $product_id, TCAS_Mapping::META_QTY, true );
			$raw_status   = (string) get_post_meta( $product_id, TCAS_Mapping::META_STATUS, true );
			$fetched_at   = absint( get_post_meta( $product_id, TCAS_Mapping::META_FETCHED_AT, true ) );
			$quantity     = '' !== (string) $raw_quantity && is_numeric( $raw_quantity ) ? (float) $raw_quantity : null;
			$status       = in_array( $raw_status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $raw_status : '';

			if ( null === $quantity && '' === $status ) {
				self::schedule_refresh( $product_id );
				self::$cached_states[ $product_id ] = null;
				return null;
			}
			if ( ! $fetched_at || time() - $fetched_at >= self::CACHE_TTL ) {
				self::schedule_refresh( $product_id );
			}
			self::$cached_states[ $product_id ] = array( 'quantity' => $quantity, 'status' => $status );
			return self::$cached_states[ $product_id ];
		}

		private static function refresh_transient_key( $product_id ) {
			return self::REFRESH_TRANSIENT_PREFIX . absint( $product_id );
		}

		/** Schedule at most a few refreshes per page and never call the remote API inline. */
		private static function schedule_refresh( $product_id ) {
			$product_id = absint( $product_id );
			if ( ! $product_id || self::$scheduled_refreshes >= self::MAX_REFRESHES_PER_REQUEST
				|| ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'wp_next_scheduled' )
				|| ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
				return false;
			}

			$key = self::refresh_transient_key( $product_id );
			if ( false !== get_transient( $key ) ) {
				return false;
			}
			$args = array( $product_id );
			set_transient( $key, 'queued', self::REFRESH_LOCK_TTL );
			if ( wp_next_scheduled( self::REFRESH_HOOK, $args ) ) {
				return true;
			}

			$scheduled = wp_schedule_single_event( time() + self::REFRESH_DELAY, self::REFRESH_HOOK, $args );
			if ( ! $scheduled && ! wp_next_scheduled( self::REFRESH_HOOK, $args ) ) {
				delete_transient( $key );
				return false;
			}
			self::$scheduled_refreshes++;
			return true;
		}

		private static function settle_refresh( $product_id, $state ) {
			$key = self::refresh_transient_key( $product_id );
			if ( is_wp_error( $state ) ) {
				TCAS_Mapping::remember_error( $product_id, $state->get_error_message() );
				set_transient( $key, 'failed', self::REFRESH_RETRY_TTL );
				return;
			}
			delete_transient( $key );
		}

		/** WP-Cron callback: network work happens outside the customer-facing request. */
		public static function process_scheduled_refresh( $product_id ) {
			$product_id = absint( $product_id );
			if ( ! $product_id ) {
				return;
			}
			$state = self::state( $product_id, true );
			self::settle_refresh( $product_id, $state );
		}

		public static function refresh( $product_id ) {
			$product_id = absint( $product_id );
			$state      = self::state( $product_id, true );
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( self::REFRESH_HOOK, array( $product_id ) );
			}
			self::settle_refresh( $product_id, $state );
			return $state;
		}

		private static function filtered_state( $product ) {
			$mode = self::filter_context();
			if ( false === $mode ) {
				return null;
			}
			$product_id = self::product_id( $product );
			return 'live' === $mode ? self::state( $product_id ) : self::cached_state( $product_id, $product );
		}

		/** در حالت flag عدد انبار محلی نمایش داده و دست‌کاری نمی‌شود. */
		private static function quantity_mode() {
			return class_exists( 'TCAS_Stock' ) && TCAS_Stock::MODE_QUANTITY === TCAS_Stock::mode();
		}

		public static function filter_quantity( $value, $product ) {
			if ( ! self::quantity_mode() ) {
				return $value;
			}
			$state = self::filtered_state( $product );
			return is_array( $state ) && null !== $state['quantity'] ? $state['quantity'] : $value;
		}

		public static function filter_status( $value, $product ) {
			$state = self::filtered_state( $product );
			return is_array( $state ) && ! empty( $state['status'] ) ? $state['status'] : $value;
		}

		public static function filter_manage_stock( $value, $product ) {
			if ( ! self::quantity_mode() ) {
				return $value;
			}
			$state = self::filtered_state( $product );
			if ( ! is_array( $state ) ) {
				return $value;
			}
			return null !== $state['quantity'];
		}

		/** سیاست «همکار در دسترس نیست»: باز (فروش با Snapshot آخر) یا بسته. */
		private static function fail_open() {
			$settings = TCAS_Core::settings();
			return ! isset( $settings['fail_mode'] ) || 'open' === $settings['fail_mode'];
		}

		/**
		 * موجودی تازه برای کنترل خرید. اگر همکار در Backoff باشد درخواست تازه زده
		 * نمی‌شود و همان Snapshot محلی معیار است تا فایروال بیشتر تحریک نشود.
		 */
		private static function purchase_state( $product_id ) {
			if ( class_exists( 'TCAS_Sweeper' ) && TCAS_Sweeper::in_backoff() ) {
				return self::cached_state( $product_id );
			}
			return self::state( $product_id );
		}

		private static function validate( $product_id, $quantity ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || ! TCAS_Mapping::is_enabled( $product_id ) ) {
				return true;
			}
			$state = self::purchase_state( $product_id );
			if ( null === $state ) {
				return true;
			}
			if ( is_wp_error( $state ) ) {
				$partner_label = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::label( TCAS_Mapping::partner_id( $product_id ) ) : 'ArioCase';
				if ( self::fail_open() ) {
					// فروش سالم با آخرین Snapshot معتبر ادامه می‌یابد؛ خطا فقط ثبت می‌شود.
					TCAS_Mapping::remember_error( $product_id, $state->get_error_message() );
					self::notice_degraded( $partner_label, $state->get_error_message() );
					$fallback = self::cached_state( $product_id );
					if ( is_array( $fallback ) && ! empty( $fallback['status'] ) ) {
						$state = $fallback;
					} else {
						return true;
					}
				} else {
					return new WP_Error( 'tcas_live_unavailable', sprintf( 'موجودی «%s» از %s قابل بررسی نیست. لطفاً کمی بعد دوباره تلاش کنید.', $product->get_name(), $partner_label ) );
				}
			}
			if ( empty( $state['status'] ) ) {
				// Snapshot ذخیره‌شده هم وضعیت ندارد؛ جلوی فروش گرفته نمی‌شود.
				return true;
			}
			if ( 'instock' !== $state['status'] ) {
				$partner_label = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::label( TCAS_Mapping::partner_id( $product_id ) ) : 'ArioCase';
				return new WP_Error( 'tcas_live_outofstock', sprintf( '«%s» نزد %s فعلاً موجود نیست.', $product->get_name(), $partner_label ) );
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

		/** یک بار در هر صفحه: همکار پاسخ نداد و با آخرین موجودی ذخیره‌شده ادامه شد. */
		private static function notice_degraded( $partner_label, $error ) {
			self::notice_once( 'degraded', sprintf( 'موجودی %s همین حالا قابل بررسی نیست؛ کنترل خرید با آخرین موجودی ثبت‌شده ادامه یافت. (%s)', $partner_label, $error ), 'notice' );
		}

		private static function notice_once( $key, $message, $type = 'error' ) {
			if ( isset( self::$notices[ $key ] ) || ! function_exists( 'wc_add_notice' ) ) {
				return;
			}
			self::$notices[ $key ] = true;
			wc_add_notice( $message, $type );
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
