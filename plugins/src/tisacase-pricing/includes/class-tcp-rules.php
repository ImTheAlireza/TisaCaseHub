<?php
/**
 * موتور قوانین داینامیک: قیمت‌ها را هنگام نمایش بر اساس قانون محصول ← دسته ← سراسری
 * محاسبه می‌کند، بدون نوشتن در دیتابیس. قیمت همکاری و فروش ویژهٔ واقعی دست نمی‌خورد.
 *
 * @package TisaCase_Pricing
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCP_Rules' ) ) {

	final class TCP_Rules {

		const OPTION                  = 'tcp_rules';
		const CACHE_VERSION           = 'tcp_rules_cache_version';
		const PRIORITY                = 99999;
		const FINAL_ACTIVE_PRICE_HOOK = 'tisacase_pricing_final_active_price';
		const WHOLESALE_ROLE          = 'tisacase_partner';
		const WHOLESALE_CAP            = 'tisacase_view_wholesale_prices';

		const ACTION_SAVE   = 'tcp_rules_save';
		const ACTION_SYNC   = 'tcp_rules_sync_all';
		const AJAX_PRODUCTS = 'tcp_rules_search_products';
		const AJAX_CATS     = 'tcp_rules_search_categories';
		const SEARCH_NONCE  = 'tcp_rules_search';

		private static $settings_cache = null;
		private static $rule_cache     = array();
		private static $category_ids   = array();
		private static $wholesale      = array();
		private static $prefix_ids     = array();

		/* -----------------------------------------------------------------
		 * راه‌اندازی
		 * --------------------------------------------------------------- */

		public static function activate() {
			if ( false === get_option( self::CACHE_VERSION, false ) ) {
				add_option( self::CACHE_VERSION, 1, '', false );
			}
		}

		public static function hooks() {
			add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'save' ) );
			add_action( 'admin_post_' . self::ACTION_SYNC, array( __CLASS__, 'sync_all' ) );
			add_action( 'wp_ajax_' . self::AJAX_PRODUCTS, array( __CLASS__, 'ajax_search_products' ) );
			add_action( 'wp_ajax_' . self::AJAX_CATS, array( __CLASS__, 'ajax_search_categories' ) );

			if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product' ) ) {
				return;
			}

			$p = self::PRIORITY;
			add_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'filter_regular_price' ), $p, 2 );
			add_filter( 'woocommerce_product_variation_get_regular_price', array( __CLASS__, 'filter_regular_price' ), $p, 2 );
			add_filter( 'woocommerce_product_get_sale_price', array( __CLASS__, 'filter_sale_price' ), $p, 2 );
			add_filter( 'woocommerce_product_variation_get_sale_price', array( __CLASS__, 'filter_sale_price' ), $p, 2 );
			add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'filter_active_price' ), $p, 2 );
			add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'filter_active_price' ), $p, 2 );

			add_filter( 'woocommerce_variation_prices_regular_price', array( __CLASS__, 'variation_regular_price' ), $p, 3 );
			add_filter( 'woocommerce_variation_prices_sale_price', array( __CLASS__, 'variation_sale_price' ), $p, 3 );
			add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'variation_active_price' ), $p, 3 );
			add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'variation_hash' ), $p, 3 );
			add_filter( 'woocommerce_product_is_on_sale', array( __CLASS__, 'filter_is_on_sale' ), $p, 2 );

			add_action( 'template_redirect', array( __CLASS__, 'protect_partner_cache' ), 1 );
		}

		/* -----------------------------------------------------------------
		 * تنظیمات قوانین
		 * --------------------------------------------------------------- */

		public static function defaults() {
			return array(
				'global'     => array( 'enabled' => 0, 'increase' => 10, 'sale' => 10, 'mode' => 'round' ),
				'products'   => array(),
				'categories' => array(),
				'prefixes'   => array(),
			);
		}

		private static function percent( $value, $max ) {
			$value = is_numeric( $value ) ? (float) $value : 0;
			return max( 0, min( $max, $value ) );
		}

		public static function modes() {
			return array(
				'none'   => 'بدون رند',
				'round'  => 'رند به ۸',
				'jitter' => 'تخفیف متغیر (رند به ۸)',
			);
		}

		private static function date( $v ) {
			$v = trim( (string) $v );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		}

		private static function money( $v ) {
			$v = TCP_Ops::number( $v );
			return ( null === $v || $v <= 0 ) ? 0 : (float) $v;
		}

		public static function normalize_rule( $rule ) {
			$rule = is_array( $rule ) ? $rule : array();
			$mode = isset( $rule['mode'] ) ? sanitize_key( $rule['mode'] ) : 'round';
			$min  = self::money( isset( $rule['min'] ) ? $rule['min'] : 0 );
			$max  = self::money( isset( $rule['max'] ) ? $rule['max'] : 0 );
			if ( $min && $max && $min > $max ) {
				$max = 0;
			}
			$exclude = ! empty( $rule['exclude'] );
			// استثنا همیشه فعال و بدون بازهٔ زمانی است؛ فهرست استثناها سوییچ «فعال» ندارد.
			return array(
				'enabled'  => ( $exclude || ! empty( $rule['enabled'] ) ) ? 1 : 0,
				'exclude'  => $exclude ? 1 : 0,
				'increase' => self::percent( isset( $rule['increase'] ) ? $rule['increase'] : 0, 500 ),
				'sale'     => self::percent( isset( $rule['sale'] ) ? $rule['sale'] : 0, 99.9 ),
				'mode'     => array_key_exists( $mode, self::modes() ) ? $mode : 'round',
				'from'     => $exclude ? '' : self::date( isset( $rule['from'] ) ? $rule['from'] : '' ),
				'to'       => $exclude ? '' : self::date( isset( $rule['to'] ) ? $rule['to'] : '' ),
				'min'      => $min,
				'max'      => $max,
			);
		}

		/**
		 * پیشوندهای SKU استثناشده: حروف ابتدای SKU (مثلاً CH). یکتا، بزرگ‌حرف، بدون فاصله.
		 *
		 * @param mixed $list آرایه یا رشتهٔ جداشده با ویرگول/خط جدید.
		 * @return string[]
		 */
		public static function normalize_prefixes( $list ) {
			if ( is_string( $list ) ) {
				$list = preg_split( '/[\s,،;]+/u', $list );
			}
			$out = array();
			foreach ( (array) $list as $item ) {
				if ( ! is_scalar( $item ) ) {
					continue;
				}
				$p = self::upper( preg_replace( '/\s+/u', '', trim( (string) $item ) ) );
				if ( '' !== $p && preg_match( '/^[\p{L}\p{N}_\-]{1,30}$/u', $p ) && ! in_array( $p, $out, true ) ) {
					$out[] = $p;
				}
			}
			return $out;
		}

		/**
		 * نقشهٔ قانون شناسه‌ها: «پیشوند => قانون». هر پیشوند یا استثناست یا قانون اختصاصی خودش را دارد.
		 * شکل قدیمی (فهرست ساده از پیشوندها) به‌عنوان استثنا خوانده می‌شود.
		 *
		 * @param mixed $raw ورودی ذخیره‌شده یا POST.
		 * @return array<string,array>
		 */
		public static function normalize_prefix_map( $raw ) {
			$out = array();
			if ( ! is_array( $raw ) ) {
				return $out;
			}
			foreach ( $raw as $key => $rule ) {
				if ( is_array( $rule ) ) {
					$prefix = $key;
					$rule   = $rule;
				} elseif ( is_scalar( $rule ) && is_int( $key ) ) {
					$prefix = $rule; // شکل قدیمی: ['CH', 'LP'] => استثنا.
					$rule   = array( 'exclude' => 1 );
				} else {
					continue;
				}
				$list = self::normalize_prefixes( array( $prefix ) );
				if ( empty( $list ) ) {
					continue;
				}
				$out[ $list[0] ] = self::normalize_rule( $rule );
			}
			return $out;
		}

		private static function upper( $text ) {
			return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( (string) $text, 'UTF-8' ) : strtoupper( (string) $text );
		}

		/** آیا قانون الان (با توجه به بازهٔ زمانی) فعال است؟ */
		public static function rule_live( $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				return false;
			}
			$today = current_time( 'Y-m-d' );
			if ( '' !== $rule['from'] && $today < $rule['from'] ) {
				return false;
			}
			if ( '' !== $rule['to'] && $today > $rule['to'] ) {
				return false;
			}
			return true;
		}

		public static function settings() {
			if ( null !== self::$settings_cache ) {
				return self::$settings_cache;
			}
			$raw      = get_option( self::OPTION, array() );
			$raw      = is_array( $raw ) ? $raw : array();
			$defaults = self::defaults();

			$settings = array(
				'global'     => self::normalize_rule( isset( $raw['global'] ) && is_array( $raw['global'] ) ? $raw['global'] : $defaults['global'] ),
				'products'   => array(),
				'categories' => array(),
				'prefixes'   => self::normalize_prefix_map( isset( $raw['prefixes'] ) ? $raw['prefixes'] : array() ),
			);
			foreach ( array( 'products', 'categories' ) as $group ) {
				foreach ( (array) ( isset( $raw[ $group ] ) ? $raw[ $group ] : array() ) as $id => $rule ) {
					$id = absint( $id );
					if ( $id && is_array( $rule ) ) {
						$settings[ $group ][ $id ] = self::normalize_rule( $rule );
					}
				}
			}
			self::$settings_cache = $settings;
			return $settings;
		}

		private static function persist( $settings ) {
			update_option( self::OPTION, $settings, false );
			$version = (int) get_option( self::CACHE_VERSION, 1 );
			update_option( self::CACHE_VERSION, max( 1, $version + 1 ), false );
			self::$settings_cache = null;
			self::$rule_cache     = array();
			self::$category_ids   = array();
			self::$prefix_ids     = array();
		}

		/* -----------------------------------------------------------------
		 * حل قانون برای یک محصول
		 * --------------------------------------------------------------- */

		private static function scope_product_id( $product ) {
			if ( ! $product instanceof WC_Product ) {
				return 0;
			}
			return $product->is_type( 'variation' ) ? absint( $product->get_parent_id() ) : absint( $product->get_id() );
		}

		private static function product_category_ids( $product_id ) {
			if ( ! array_key_exists( $product_id, self::$category_ids ) ) {
				$ids                                = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
				self::$category_ids[ $product_id ] = is_wp_error( $ids ) ? array() : array_map( 'absint', $ids );
			}
			return self::$category_ids[ $product_id ];
		}

		private static function category_matches( $category_id, $product_cats ) {
			$category_id = absint( $category_id );
			if ( ! $category_id || empty( $product_cats ) ) {
				return false;
			}
			static $expanded = array();
			if ( ! isset( $expanded[ $category_id ] ) ) {
				$children                 = get_term_children( $category_id, 'product_cat' );
				$children                 = is_wp_error( $children ) ? array() : array_map( 'absint', (array) $children );
				$children[]               = $category_id;
				$expanded[ $category_id ] = array_values( array_unique( $children ) );
			}
			return (bool) array_intersect( $expanded[ $category_id ], $product_cats );
		}

		/**
		 * مجموعهٔ شناسهٔ محصولاتی (والد) که SKUی با یکی از پیشوندهای استثنا دارند.
		 * SKU خود محصول یا SKU هر واریشنش ملاک است (واریشن‌ها معمولاً SKU جدا دارند).
		 * یک کوئری در هر درخواست؛ نتیجه کش می‌شود.
		 *
		 * @param string[] $prefixes پیشوندهای نرمال‌شده.
		 * @return array<int,int> شناسه => شناسه.
		 */
		public static function prefix_product_ids( $prefixes ) {
			$prefixes = self::normalize_prefixes( $prefixes );
			if ( empty( $prefixes ) ) {
				return array();
			}
			$key = implode( '|', $prefixes );
			if ( isset( self::$prefix_ids[ $key ] ) ) {
				return self::$prefix_ids[ $key ];
			}
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
				return array();
			}
			$likes = array();
			foreach ( $prefixes as $prefix ) {
				$likes[] = $wpdb->prepare( 'pm.meta_value LIKE %s', $wpdb->esc_like( $prefix ) . '%' );
			}
			$sql = "SELECT DISTINCT CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE p.ID END AS pid"
				. " FROM {$wpdb->posts} p"
				. " INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku'"
				. " WHERE p.post_type IN ('product','product_variation') AND p.post_status <> 'trash'"
				. ' AND (' . implode( ' OR ', $likes ) . ')';
			$ids = array();
			foreach ( (array) $wpdb->get_col( $sql ) as $id ) {
				$id = absint( $id );
				if ( $id ) {
					$ids[ $id ] = $id;
				}
			}
			self::$prefix_ids[ $key ] = $ids;
			return $ids;
		}

		/** تعداد محصولات (والد) یک پیشوند؛ برای نمایش در فهرست استثناها. */
		public static function prefix_product_count( $prefix ) {
			return count( self::prefix_product_ids( array( $prefix ) ) );
		}

		private static function resolve_rule( $product ) {
			$scope_id = self::scope_product_id( $product );
			if ( ! $scope_id ) {
				return null;
			}
			if ( array_key_exists( $scope_id, self::$rule_cache ) ) {
				return self::$rule_cache[ $scope_id ];
			}
			$s       = self::settings();
			$rule    = null;
			$decided = false;

			// ۱) قانون یا استثنای تکیِ محصول — همیشه برنده است، حتی اگر در دسته یا شناسهٔ استثناشده باشد.
			if ( isset( $s['products'][ $scope_id ] ) && self::rule_live( $s['products'][ $scope_id ] ) ) {
				$pr      = $s['products'][ $scope_id ];
				$rule    = ! empty( $pr['exclude'] ) ? false : $pr;
				$decided = true;
			}
			// ۲) قانون/استثنای شناسه (پیشوند SKU): بلندترین پیشوند منطبق برنده است.
			if ( ! $decided && ! empty( $s['prefixes'] ) ) {
				$live = array_filter(
					$s['prefixes'],
					static function ( $pr ) {
						return self::rule_live( $pr );
					}
				);
				uksort(
					$live,
					static function ( $a, $b ) {
						return strlen( $b ) - strlen( $a );
					}
				);
				foreach ( $live as $prefix => $pr ) {
					$ids = self::prefix_product_ids( array( (string) $prefix ) );
					if ( isset( $ids[ $scope_id ] ) ) {
						$rule    = ! empty( $pr['exclude'] ) ? false : $pr;
						$decided = true;
						break;
					}
				}
			}
			// ۳) اولین دستهٔ منطبق (استثنا یعنی هیچ قانونی، حتی سراسری).
			if ( ! $decided ) {
				$cats = self::product_category_ids( $scope_id );
				foreach ( $s['categories'] as $category_id => $cat_rule ) {
					if ( self::rule_live( $cat_rule ) && self::category_matches( $category_id, $cats ) ) {
						$rule    = ! empty( $cat_rule['exclude'] ) ? false : $cat_rule;
						$decided = true;
						break;
					}
				}
			}
			// ۴) سراسری.
			if ( ! $decided && self::rule_live( $s['global'] ) ) {
				$rule = $s['global'];
			}
			if ( false === $rule ) {
				$rule = null;
			}
			self::$rule_cache[ $scope_id ] = $rule;
			return $rule;
		}

		/* -----------------------------------------------------------------
		 * موتور قیمت
		 * --------------------------------------------------------------- */

		private static function can_apply( $product ) {
			if ( ! $product instanceof WC_Product ) {
				return false;
			}
			if ( is_admin() && function_exists( 'wp_doing_ajax' ) && ! wp_doing_ajax() ) {
				return false;
			}
			$scope_id = self::scope_product_id( $product );
			return $scope_id && 'publish' === get_post_status( $scope_id );
		}

		private static function has_real_sale( $product ) {
			$sale = $product->get_sale_price( 'edit' );
			return '' !== $sale && null !== $sale && is_numeric( $sale ) && (float) $sale > 0;
		}

		public static function is_partner() {
			if ( ! is_user_logged_in() ) {
				return false;
			}
			$user = wp_get_current_user();
			if ( ! $user instanceof WP_User ) {
				return false;
			}
			return in_array( self::WHOLESALE_ROLE, (array) $user->roles, true ) || user_can( $user, self::WHOLESALE_CAP );
		}

		private static function wholesale_price( $product ) {
			$id = absint( $product->get_id() );
			if ( ! $id ) {
				return false;
			}
			if ( array_key_exists( $id, self::$wholesale ) ) {
				return self::$wholesale[ $id ];
			}
			$value = get_post_meta( $id, TCP_WHOLESALE_META, true );
			if ( ( '' === $value || ! is_numeric( $value ) || (float) $value <= 0 ) && $product->is_type( 'variation' ) ) {
				$parent_id = absint( $product->get_parent_id() );
				if ( $parent_id ) {
					$value = get_post_meta( $parent_id, TCP_WHOLESALE_META, true );
				}
			}
			self::$wholesale[ $id ] = ( '' !== $value && is_numeric( $value ) && (float) $value > 0 ) ? (float) $value : false;
			return self::$wholesale[ $id ];
		}

		private static function calculated_regular( $product, $rule ) {
			if ( self::has_real_sale( $product ) ) {
				return '';
			}
			$regular = $product->get_regular_price( 'edit' );
			if ( '' === $regular || ! is_numeric( $regular ) || (float) $regular <= 0 ) {
				return '';
			}
			$v = (float) $regular * ( 1 + ( (float) $rule['increase'] / 100 ) );
			if ( ! empty( $rule['min'] ) && $v < $rule['min'] ) {
				$v = (float) $rule['min'];
			}
			if ( ! empty( $rule['max'] ) && $v > $rule['max'] ) {
				$v = (float) $rule['max'];
			}
			if ( 'none' === $rule['mode'] || $v < 2 * TCP_Round::step() ) {
				return round( $v );
			}
			return TCP_Round::nearest( $v );
		}

		private static function calculated_sale( $product, $rule ) {
			if ( self::has_real_sale( $product ) || (float) $rule['sale'] <= 0 ) {
				return '';
			}
			$regular = self::calculated_regular( $product, $rule );
			if ( '' === $regular || (float) $regular <= 0 ) {
				return '';
			}
			$mode = (float) $regular < 2 * TCP_Round::step() ? 'none' : $rule['mode'];
			$res  = TCP_Round::discount( (float) $regular, (float) $rule['sale'], $mode, $product->get_id() );
			$sale = 'none' === $mode ? round( $res['price'] ) : $res['price'];
			if ( $sale >= (float) $regular || $sale <= 0 ) {
				return '';
			}
			return $sale;
		}

		/** قانون قابل اعمال برای این محصول، یا null (شرط‌های مشترک همهٔ فیلترها). */
		private static function applicable_rule( $product ) {
			if ( ! self::can_apply( $product ) || self::has_real_sale( $product ) ) {
				return null;
			}
			return self::resolve_rule( $product );
		}

		private static function partner_locked( $product ) {
			return self::is_partner() && false !== self::wholesale_price( $product );
		}

		public static function filter_regular_price( $price, $product ) {
			$rule = self::applicable_rule( $product );
			if ( ! $rule ) {
				return $price;
			}
			$new = self::calculated_regular( $product, $rule );
			return '' !== $new ? $new : $price;
		}

		public static function filter_sale_price( $price, $product ) {
			$rule = self::applicable_rule( $product );
			if ( ! $rule || (float) $rule['sale'] <= 0 || self::partner_locked( $product ) ) {
				return $price;
			}
			$new = self::calculated_sale( $product, $rule );
			return '' !== $new ? $new : $price;
		}

		/**
		 * قیمت قابل پرداخت را پس از اعمال قانون داینامیک برمی‌گرداند.
		 *
		 * هوک FINAL_ACTIVE_PRICE_HOOK نقطهٔ اتصال برای افزونه‌هایی است که باید مبلغی
		 * را به قیمت فعال اضافه کنند؛ مقدار ورودی به آن، قیمت نهایی همین موتور است،
		 * نه قیمت خامِ ذخیره‌شده در متای محصول.
		 *
		 * @param mixed     $price   قیمت فعال اولیهٔ ووکامرس.
		 * @param WC_Product $product محصول جاری.
		 * @return mixed قیمت نهایی پس از تعدیل‌های وابسته.
		 */
		public static function filter_active_price( $price, $product ) {
			try {
				$active_price = $price;
				$rule         = self::applicable_rule( $product );

				if ( $rule && ! self::partner_locked( $product ) ) {
					$sale = (float) $rule['sale'] > 0 ? self::calculated_sale( $product, $rule ) : '';
					if ( '' !== $sale ) {
						$active_price = $sale;
					} else {
						$regular = self::calculated_regular( $product, $rule );
						if ( '' !== $regular ) {
							$active_price = $regular;
						}
					}
				}

				return apply_filters( self::FINAL_ACTIVE_PRICE_HOOK, $active_price, $product );
			} catch ( Throwable $error ) {
				// از شکستن صفحهٔ سبد جلوگیری کن؛ افزونهٔ متصل می‌تواند پرداخت این آیتم را مسدود کند.
				do_action( 'tisacase_pricing_active_price_calculation_failed', $product );
				return $price;
			}
		}

		public static function variation_regular_price( $price, $variation, $parent ) {
			return self::filter_regular_price( $price, $variation );
		}

		public static function variation_sale_price( $price, $variation, $parent ) {
			return self::filter_sale_price( $price, $variation );
		}

		public static function variation_active_price( $price, $variation, $parent ) {
			return self::filter_active_price( $price, $variation );
		}

		public static function filter_is_on_sale( $on_sale, $product ) {
			$rule = self::applicable_rule( $product );
			if ( ! $rule || (float) $rule['sale'] <= 0 || self::partner_locked( $product ) ) {
				return $on_sale;
			}
			if ( $product->is_type( 'variable' ) ) {
				return $on_sale;
			}
			return '' !== self::calculated_sale( $product, $rule ) ? true : $on_sale;
		}

		public static function variation_hash( $hash, $product, $for_display ) {
			$hash['tisacase_pricing'] = array(
				'plugin'  => TCP_VERSION,
				'version' => (int) get_option( self::CACHE_VERSION, 1 ),
				'mode'    => self::is_partner() ? 'partner' : 'retail',
				'day'     => current_time( 'Y-m-d' ), // بازه‌های زمانی قوانین بدون ذخیرهٔ مجدد اثر کنند.
			);
			return $hash;
		}

		public static function protect_partner_cache() {
			if ( ! self::is_partner() ) {
				return;
			}
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			if ( ! headers_sent() ) {
				nocache_headers();
			}
		}

		/* -----------------------------------------------------------------
		 * ذخیره / همگام‌سازی (admin-post)
		 * --------------------------------------------------------------- */

		private static function posted_group( $group, $type ) {
			$out = array();
			foreach ( (array) $group as $id => $rule ) {
				$id = absint( $id );
				if ( ! $id || ! is_array( $rule ) ) {
					continue;
				}
				if ( 'products' === $type && 'product' !== get_post_type( $id ) ) {
					continue;
				}
				if ( 'categories' === $type && ! term_exists( $id, 'product_cat' ) ) {
					continue;
				}
				$out[ $id ] = self::normalize_rule( $rule );
			}
			return $out;
		}

		private static function redirect( $flag ) {
			wp_safe_redirect( TCP_Admin::url( 'rules', array( $flag => 1 ) ) );
			exit;
		}

		public static function save() {
			if ( ! TCP_Settings::can() ) {
				wp_die( 'دسترسی غیرمجاز.' );
			}
			check_admin_referer( self::ACTION_SAVE );
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- در normalize_rule پاک‌سازی می‌شود.
			$settings = array(
				'global'     => self::normalize_rule( isset( $_POST['global'] ) ? wp_unslash( $_POST['global'] ) : array() ),
				'products'   => self::posted_group( isset( $_POST['products'] ) ? wp_unslash( $_POST['products'] ) : array(), 'products' ),
				'categories' => self::posted_group( isset( $_POST['categories'] ) ? wp_unslash( $_POST['categories'] ) : array(), 'categories' ),
				'prefixes'   => self::normalize_prefix_map( isset( $_POST['prefixes'] ) ? wp_unslash( $_POST['prefixes'] ) : array() ),
			);
			// phpcs:enable
			self::persist( $settings );
			self::redirect( 'saved' );
		}

		public static function sync_all() {
			if ( ! TCP_Settings::can() ) {
				wp_die( 'دسترسی غیرمجاز.' );
			}
			check_admin_referer( self::ACTION_SYNC );
			$settings           = self::settings();
			$settings['global'] = array_merge( $settings['global'], array( 'enabled' => 1, 'increase' => 10, 'sale' => 10 ) );
			self::persist( $settings );
			self::redirect( 'synced' );
		}

		/* -----------------------------------------------------------------
		 * جستجو (AJAX)
		 * --------------------------------------------------------------- */

		private static function ajax_term() {
			if ( ! TCP_Settings::can() ) {
				wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
			}
			check_ajax_referer( self::SEARCH_NONCE, 'nonce' );
			$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
			if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $term ) : strlen( $term ) ) < 2 ) {
				wp_send_json_success( array() );
			}
			return $term;
		}

		/**
		 * جستجوی محصول در کل کاتالوگ (نام/توضیح/SKU/شناسه) با صفحه‌بندی.
		 * خروجی: items/total/page/pages/per_page — فهرست دیگر به ۳۰ مورد ختم نمی‌شود.
		 */
		public static function ajax_search_products() {
			$term = self::ajax_term();
			$page = isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1;
			wp_send_json_success(
				TCP_Ops::search_products(
					$term,
					array(
						'page'     => $page,
						'per_page' => TCP_Ops::SEARCH_PER_PAGE,
					)
				)
			);
		}

		/** جستجوی دسته‌بندی با صفحه‌بندی؛ کل دسته‌های سایت قابل رسیدن هستند. */
		public static function ajax_search_categories() {
			$term = self::ajax_term();
			$page = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;
			$args = array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'name__like' => $term,
				'number'     => TCP_Ops::SEARCH_PER_PAGE,
				'offset'     => ( $page - 1 ) * TCP_Ops::SEARCH_PER_PAGE,
				'orderby'    => 'name',
				'order'      => 'ASC',
			);

			$terms = get_terms( $args );
			$count = get_terms( array_merge( $args, array( 'fields' => 'count', 'number' => 0, 'offset' => 0 ) ) );
			$total = is_wp_error( $count ) ? 0 : (int) $count;

			$items = array();
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $item ) {
					$edit_url = get_edit_term_link( (int) $item->term_id, 'product_cat' );
					$items[]  = array(
						'id'       => (int) $item->term_id,
						'name'     => $item->name,
						'path'     => TCP_Admin::cat_label( $item ),
						'count'    => (int) $item->count,
						'edit_url' => is_wp_error( $edit_url ) ? '' : (string) $edit_url,
					);
				}
			}

			// اگر شمارش کل خطا داد، حداقل به اندازهٔ همین صفحه نتیجه گزارش شود.
			$total  = max( $total, count( $items ) );
			$paging = TCP_Ops::search_paging( $page, $total, TCP_Ops::SEARCH_PER_PAGE );
			wp_send_json_success(
				array(
					'items'    => $items,
					'total'    => $total,
					'page'     => $paging['page'],
					'pages'    => $paging['pages'],
					'per_page' => TCP_Ops::SEARCH_PER_PAGE,
				)
			);
		}
	}
}
