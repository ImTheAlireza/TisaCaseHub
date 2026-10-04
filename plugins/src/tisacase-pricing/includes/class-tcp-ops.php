<?php
/**
 * منطق افزونه: فهرست عملیات، اعتبارسنجی ورودی، محاسبهٔ قیمت (منبع واحد برای پیش‌نمایش و اجرا)،
 * اجرای دسته‌ای، بازگردانی و توکن امنیتی.
 *
 * @package TisaCase_Pricing
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCP_Unsafe_Exception' ) ) {
	class TCP_Unsafe_Exception extends RuntimeException {}
}

if ( ! class_exists( 'TCP_Ops' ) ) {

	final class TCP_Ops {

		/** نوع‌های ورودی هر عملیات. */
		const KIND_PERCENT = 'percent';
		const KIND_AMOUNT  = 'amount';
		const KIND_SET     = 'set';
		const KIND_NONE    = 'none';

		/** سقف ورودی‌های استثنا برای جلوگیری از درخواست/کوئری بسیار بزرگ. */
		const MAX_EXCLUDED_PRODUCTS   = 500;
		const MAX_EXCLUDED_CATEGORIES = 100;

		/** حالت رند جاری برای این درخواست/صفحه: none | round | jitter (از args می‌آید). */
		public static $round_mode = 'none';

		/** نشانِ «این اجرا این شیء را نوشته» تا قطعی وسط save درصد را دوباره اعمال نکند. */
		const GUARD_META = '_tcp_bulk_guard';

		public static function set_round_mode( $mode ) {
			$mode = sanitize_key( (string) $mode );
			self::$round_mode = in_array( $mode, array( 'none', 'round', 'jitter' ), true ) ? $mode : 'none';
		}

		/* -----------------------------------------------------------------
		 * فهرست ثابتِ اهداف + بودجهٔ زمانی درخواست‌ها (مقاومت در برابر قطعی)
		 * --------------------------------------------------------------- */

		/**
		 * کدگذاری فهرست شناسه‌های محصول مادر به رشتهٔ کوچک «1,2,3» برای ذخیره در args.
		 * فهرست در شروع اجرا یک‌بار محاسبه و یخ می‌خورد تا وسط اجرا (که فیلترهای
		 * قیمت/فروش ویژه بر اساس همان متاها تغییر کرده‌اند) جابه‌جا نشود و محصولی جا نماند.
		 */
		public static function encode_parent_ids( $ids ) {
			$out = array();
			foreach ( (array) $ids as $id ) {
				$id = absint( $id );
				if ( $id ) {
					$out[] = $id;
				}
			}
			return implode( ',', array_unique( $out ) );
		}

		/** کدگذاری معکوسِ encode_parent_ids؛ ترتیب ورودی حفظ می‌شود. */
		public static function decode_parent_ids( $raw ) {
			if ( is_array( $raw ) ) {
				return array_values( array_filter( array_map( 'absint', $raw ) ) );
			}
			if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
				return array();
			}
			return array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );
		}

		/**
		 * بودجهٔ زمانی (ثانیه) هر درخواست پردازش؛ عمداً کوتاه می‌ماند تا هر مرحله
		 * پاسخ سریع برگرداند و اجرای کاتالوگ به چند درخواست سبک تقسیم شود.
		 * اگر محدودیتی تعریف نشده باشد (0) سقف هر مرحله ۸ ثانیه است.
		 */
		public static function time_budget() {
			$t = (int) ini_get( 'max_execution_time' );
			if ( $t <= 0 ) {
				return 8;
			}
			return max( 5, min( 8, $t - 5 ) );
		}

		/**
		 * آماده‌سازی حافظه/زمان اجرای درخواست‌های سنگین (مشابه prepare_runtime
		 * در افزونهٔ تغییر متغیرها): حافظهٔ ادمین، حذف سقف زمان و نادیده‌گرفتن
		 * قطع اتصال کلاینت تا پایانِ همین محصول مادر تعهدات ثبت شوند.
		 */

		/** اجرای «کل کاتالوگ» یا دستهٔ بزرگ که با کلیدست (نه فهرست شناسه) پیش می‌رود. */
		public static function is_catalog_run( $args ) {
			if ( ! is_array( $args ) ) {
				return false;
			}
			if ( isset( $args['target_type'] ) && 'all' === $args['target_type'] ) {
				return true;
			}
			return isset( $args['scan']['mode'] ) && 'keyset' === $args['scan']['mode'];
		}

		/**
		 * شناسه‌های باقی‌مانده بعد از cursor، به ترتیب صعودی.
		 * cursor شناسهٔ آخرین شیءِ کاملاً تمام‌شده است؛ خودش دوباره انجام نمی‌شود.
		 */
		public static function pending_object_ids( $object_ids, $after_object ) {
			$ids = array();
			foreach ( (array) $object_ids as $id ) {
				$id = absint( $id );
				if ( $id ) {
					$ids[ $id ] = $id;
				}
			}
			$ids   = array_values( $ids );
			sort( $ids, SORT_NUMERIC );
			$after = absint( $after_object );
			if ( ! $after ) {
				return $ids;
			}
			$out = array();
			foreach ( $ids as $id ) {
				if ( $id > $after ) {
					$out[] = $id;
				}
			}
			return $out;
		}

		public static function guard_encode( $run_id, $before, $after ) {
			$payload = array(
				'r' => absint( $run_id ),
				'b' => (string) $before,
				'a' => (string) $after,
			);
			$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );
			return is_string( $json ) ? $json : '';
		}

		public static function guard_decode( $raw ) {
			$data = json_decode( (string) $raw, true );
			if ( ! is_array( $data ) || ! isset( $data['r'] ) ) {
				return null;
			}
			return array(
				'run'    => absint( $data['r'] ),
				'before' => isset( $data['b'] ) ? (string) $data['b'] : '',
				'after'  => isset( $data['a'] ) ? (string) $data['a'] : '',
			);
		}

		public static function prices_equal( $a, $b ) {
			$a = (string) $a;
			$b = (string) $b;
			if ( $a === $b ) {
				return true;
			}
			if ( '' === $a || '' === $b ) {
				return false;
			}
			if ( function_exists( 'wc_format_decimal' ) ) {
				return (string) wc_format_decimal( $a ) === (string) wc_format_decimal( $b );
			}
			return is_numeric( $a ) && is_numeric( $b ) && (float) $a === (float) $b;
		}

		/**
		 * true یعنی قیمت این اجرا قبلاً نشسته و نباید دوباره محاسبه شود.
		 * اگر نشان هست ولی قیمت هنوز همان «قبل» است، save کامل نشده و باید اعمال شود.
		 */
		public static function guard_should_skip( $raw, $current, $run_id ) {
			$run_id = absint( $run_id );
			$guard  = self::guard_decode( $raw );
			if ( ! $run_id || ! $guard || $guard['run'] !== $run_id ) {
				return false;
			}
			return ! self::prices_equal( $current, $guard['before'] );
		}

		public static function runtime_boost() {
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'admin' );
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- بودجهٔ زمانی داخلی مانع از اجرای بی‌پایان می‌شود.
			}
			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}
		}

		/** کاهش سربار کش/شمارش در درخواست‌های کاتالوگ بزرگ. */
		public static function begin_batch_runtime() {
			self::runtime_boost();
			if ( function_exists( 'wp_defer_term_counting' ) ) {
				wp_defer_term_counting( true );
			}
			if ( function_exists( 'wp_defer_comment_counting' ) ) {
				wp_defer_comment_counting( true );
			}
			if ( function_exists( 'wp_suspend_cache_invalidation' ) ) {
				wp_suspend_cache_invalidation( true );
			}
		}

		public static function end_batch_runtime() {
			if ( function_exists( 'wp_suspend_cache_invalidation' ) ) {
				wp_suspend_cache_invalidation( false );
			}
			if ( function_exists( 'wp_defer_term_counting' ) ) {
				wp_defer_term_counting( false );
			}
			if ( function_exists( 'wp_defer_comment_counting' ) ) {
				wp_defer_comment_counting( false );
			}
		}

		/**
		 * اعمال حالت رند روی نتیجهٔ محاسبه.
		 * - round: نزدیک‌ترین رقم ۸ (بالا یا پایین؛ فاصلهٔ برابر به پایین).
		 * - jitter: فقط برای عملیات «درصد تخفیف» → درصد متغیر ±J به‌ازای هر آیتم؛ سایر عملیات مثل round.
		 */
		private static function finish( $op, $new, $base, $value, $seed ) {
			if ( 'none' === self::$round_mode || null === $new || ! is_finite( (float) $new ) ) {
				return $new;
			}
			// «تعیین قیمت دقیق» یعنی همان عددی که کاربر زده؛ رند نمی‌شود.
			if ( self::KIND_SET === self::op_kind( $op ) ) {
				return $new;
			}
			// قیمت‌های کوچک‌تر از دو گام رند (مثلاً ۱۵٬۰۰۰ با گام ۱۰٬۰۰۰) قابل رند نیستند؛ دست‌نخورده.
			if ( (float) $new < 2 * TCP_Round::step() ) {
				return $new;
			}
			$discount_ops = array( 'regular_decrease_percent', 'sale_discount_percent', 'wholesale_decrease_percent', 'wholesale_from_retail_percent' );
			if ( 'jitter' === self::$round_mode && in_array( $op, $discount_ops, true ) && (float) $base > 0 ) {
				$r = TCP_Round::jittered_discount( (float) $base, (float) $value, $seed );
				return $r['price'] > 0 ? $r['price'] : $new;
			}
			$rounded = TCP_Round::nearest( (float) $new );
			return $rounded > 0 ? $rounded : $new;
		}

		/* -----------------------------------------------------------------
		 * فهرست عملیات
		 * --------------------------------------------------------------- */

		public static function ops() {
			return array(
				// slug => array( label, kind, group )
				'regular_increase_percent'   => array( 'افزایش قیمت عادی به صورت درصدی', self::KIND_PERCENT, 'regular' ),
				'regular_decrease_percent'   => array( 'کاهش قیمت عادی به صورت درصدی', self::KIND_PERCENT, 'regular' ),
				'regular_increase_fixed'     => array( 'افزایش قیمت عادی به مبلغ ثابت', self::KIND_AMOUNT, 'regular' ),
				'regular_decrease_fixed'     => array( 'کاهش قیمت عادی به مبلغ ثابت', self::KIND_AMOUNT, 'regular' ),
				'regular_set'                => array( 'تعیین قیمت عادی دقیق', self::KIND_SET, 'regular' ),
				'sale_set'                   => array( 'تعیین قیمت فروش ویژه دقیق', self::KIND_SET, 'sale' ),
				'sale_discount_percent'      => array( 'تعیین فروش ویژه با درصد تخفیف از قیمت عادی', self::KIND_PERCENT, 'sale' ),
				'sale_remove'                => array( 'حذف قیمت فروش ویژه', self::KIND_NONE, 'sale' ),

				'wholesale_increase_percent' => array( 'افزایش قیمت عمده به صورت درصدی', self::KIND_PERCENT, 'wholesale' ),
				'wholesale_decrease_percent' => array( 'کاهش قیمت عمده به صورت درصدی', self::KIND_PERCENT, 'wholesale' ),
				'wholesale_increase_fixed'   => array( 'افزایش قیمت عمده به مبلغ ثابت', self::KIND_AMOUNT, 'wholesale' ),
				'wholesale_decrease_fixed'   => array( 'کاهش قیمت عمده به مبلغ ثابت', self::KIND_AMOUNT, 'wholesale' ),
				'wholesale_set'              => array( 'تعیین قیمت عمده دقیق', self::KIND_SET, 'wholesale' ),
				'wholesale_from_retail_percent' => array( 'تعیین قیمت عمده با درصد کمتر از قیمت فروش فعلی', self::KIND_PERCENT, 'wholesale' ),
				'wholesale_clear'            => array( 'حذف قیمت عمده', self::KIND_NONE, 'wholesale' ),
			);
		}

		public static function op_valid( $op ) {
			return isset( self::ops()[ $op ] );
		}

		public static function op_label( $op ) {
			$o = self::ops();
			return isset( $o[ $op ] ) ? $o[ $op ][0] : $op;
		}

		public static function op_kind( $op ) {
			$o = self::ops();
			return isset( $o[ $op ] ) ? $o[ $op ][1] : '';
		}

		public static function op_group( $op ) {
			$o = self::ops();
			return isset( $o[ $op ] ) ? $o[ $op ][2] : '';
		}

		public static function is_wholesale_op( $op ) { return 'wholesale' === self::op_group( $op ); }
		public static function is_regular_op( $op )  { return 'regular' === self::op_group( $op ); }
		public static function is_sale_op( $op )     { return 'sale' === self::op_group( $op ); }

		/**
		 * عملیات «درصدِ کاهش/تخفیف» که سقف ۱۰۰ دارند.
		 */
		private static function percent_cap_for( $op ) {
			$cap100 = array( 'regular_decrease_percent', 'sale_discount_percent', 'wholesale_decrease_percent', 'wholesale_from_retail_percent' );
			return in_array( $op, $cap100, true ) ? 100.0 : TCP_Settings::PERCENT_CEIL;
		}

		/* -----------------------------------------------------------------
		 * عدد و اعتبارسنجی
		 * --------------------------------------------------------------- */

		public static function number( $raw ) {
			$v = trim( (string) $raw );
			$v = str_replace(
				array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩','٬','،',' ' ),
				array( '0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9','','','' ),
				$v
			);
			$v = str_replace( ',', '', $v );
			return ( is_numeric( $v ) && '' !== $v ) ? (float) $v : null;
		}

		public static function round_price( $price, $min_zero = true ) {
			$dec = function_exists( 'wc_get_price_decimals' ) ? absint( wc_get_price_decimals() ) : 0;
			if ( ! is_numeric( $price ) || ! is_finite( (float) $price ) ) {
				return false;
			}
			$v = round( (float) $price, $dec );
			if ( $min_zero && $v < 0 ) {
				$v = 0;
			}
			return $v;
		}

		/**
		 * اعتبارسنجی + ساخت آرگومان‌های متعارف از $_POST.
		 *
		 * @return array|WP_Error
		 */
		public static function args_from_post( $post ) {
			if ( ! TCP_Settings::can() ) {
				return new WP_Error( 'forbidden', 'دسترسی غیرمجاز است.' );
			}
			if ( empty( $post['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $post['nonce'] ) ), TCP_Settings::NONCE ) ) {
				return new WP_Error( 'nonce', 'درخواست معتبر نیست؛ صفحه را تازه‌سازی کن.' );
			}
			if ( ! TCP_Settings::wc_active() ) {
				return new WP_Error( 'woo', 'ووکامرس فعال نیست.' );
			}

			$op = isset( $post['operation'] ) ? sanitize_key( wp_unslash( $post['operation'] ) ) : '';
			if ( ! self::op_valid( $op ) ) {
				return new WP_Error( 'op', 'نوع عملیات معتبر نیست.' );
			}
			$target = isset( $post['target_type'] ) ? sanitize_key( wp_unslash( $post['target_type'] ) ) : '';
			if ( ! in_array( $target, array( 'category', 'products', 'all' ), true ) ) {
				return new WP_Error( 'target', 'نوع انتخاب محصولات معتبر نیست.' );
			}

			$args = array(
				'operation'                  => $op,
				'target_type'                => $target,
				'category_ids'               => self::ids( isset( $post['category_ids'] ) ? $post['category_ids'] : '' ),
				'product_ids'                => self::ids( isset( $post['product_ids'] ) ? $post['product_ids'] : '' ),
				'include_children'           => ! empty( $post['include_children'] ),
				'excluded_category_ids'      => self::ids( isset( $post['excluded_category_ids'] ) ? $post['excluded_category_ids'] : '' ),
				'excluded_product_ids'       => self::ids( isset( $post['excluded_product_ids'] ) ? $post['excluded_product_ids'] : '' ),
				'exclude_category_children'  => ! empty( $post['exclude_category_children'] ),
				'round_mode'                 => isset( $post['round_mode'] ) && in_array( sanitize_key( wp_unslash( $post['round_mode'] ) ), array( 'none', 'round', 'jitter' ), true ) ? sanitize_key( wp_unslash( $post['round_mode'] ) ) : 'none',
			);

			if ( 'category' === $target ) {
				if ( empty( $args['category_ids'] ) ) {
					return new WP_Error( 'empty', 'هیچ دسته‌بندی‌ای انتخاب نشده است.' );
				}
				foreach ( $args['category_ids'] as $cid ) {
					$t = get_term( absint( $cid ), 'product_cat' );
					if ( ! $t || is_wp_error( $t ) ) {
						return new WP_Error( 'cat', 'دسته‌بندی انتخاب‌شده معتبر نیست.' );
					}
				}
			} elseif ( 'products' === $target ) {
				if ( empty( $args['product_ids'] ) ) {
					return new WP_Error( 'empty', 'هیچ محصولی انتخاب نشده است.' );
				}
			}

			if ( count( $args['excluded_product_ids'] ) > self::MAX_EXCLUDED_PRODUCTS ) {
				return new WP_Error( 'exclude_limit', 'حداکثر ' . self::MAX_EXCLUDED_PRODUCTS . ' محصول را می‌توان از عملیات مستثنا کرد.' );
			}
			if ( count( $args['excluded_category_ids'] ) > self::MAX_EXCLUDED_CATEGORIES ) {
				return new WP_Error( 'exclude_limit', 'حداکثر ' . self::MAX_EXCLUDED_CATEGORIES . ' دسته را می‌توان از عملیات مستثنا کرد.' );
			}
			foreach ( $args['excluded_category_ids'] as $cid ) {
				$t = get_term( absint( $cid ), 'product_cat' );
				if ( ! $t || is_wp_error( $t ) ) {
					return new WP_Error( 'excluded_cat', 'دسته‌بندی مستثناشده معتبر نیست؛ صفحه را تازه‌سازی کن.' );
				}
			}

			$kind = self::op_kind( $op );
			if ( self::KIND_NONE === $kind ) {
				$args['value'] = null;
			} else {
				$value = isset( $post['value'] ) ? self::number( wp_unslash( $post['value'] ) ) : null;
				if ( null === $value || $value < 0 || ! is_finite( $value ) ) {
					return new WP_Error( 'value', 'مقدار واردشده معتبر نیست.' );
				}
				if ( self::KIND_PERCENT === $kind ) {
					$cap = self::percent_cap_for( $op );
					if ( $value > $cap ) {
						return new WP_Error( 'value', $cap <= 100
							? 'مقدار درصد باید بین 0 تا 100 باشد.'
							: 'مقدار درصد بیش از حد مجاز است.' );
					}
				} else {
					if ( $value > TCP_Settings::max_amount() ) {
						return new WP_Error( 'value', 'مبلغ واردشده از سقف مجاز (' . number_format( TCP_Settings::max_amount() ) . ') بیشتر است.' );
					}
				}
				$args['value'] = $value;
			}

			// فیلترها: ارسال‌شده به صورت JSON.
			$args['filters'] = self::parse_filters( isset( $post['filters'] ) ? $post['filters'] : '' );

			return $args;
		}

		public static function parse_filters( $raw ) {
			$filters = array(
				'types'         => array(),
				'statuses'      => array( 'publish', 'private', 'draft', 'pending' ),
				'only_sale'     => false,
				'only_wholesale' => false,
				'price_min'     => null,
				'price_max'     => null,
			);
			if ( is_array( $raw ) ) {
				$data = $raw;
			} else {
				$data = json_decode( (string) $raw, true );
				if ( ! is_array( $data ) ) {
					return $filters;
				}
			}

			$types = isset( $data['types'] ) && is_array( $data['types'] ) ? array_map( 'sanitize_key', $data['types'] ) : array();
			$filters['types'] = array_values( array_filter( $types, array( __CLASS__, 'type_allowed' ) ) );

			if ( ! empty( $data['statuses'] ) && is_array( $data['statuses'] ) ) {
				$allowed = array( 'publish', 'private', 'draft', 'pending', 'future' );
				$filters['statuses'] = array_values( array_intersect( $allowed, array_map( 'sanitize_key', $data['statuses'] ) ) );
			}

			$filters['only_sale']     = ! empty( $data['only_sale'] );
			$filters['only_wholesale'] = ! empty( $data['only_wholesale'] );

			$min = isset( $data['price_min'] ) && '' !== $data['price_min'] && null !== $data['price_min'] ? (float) $data['price_min'] : null;
			$max = isset( $data['price_max'] ) && '' !== $data['price_max'] && null !== $data['price_max'] ? (float) $data['price_max'] : null;
			if ( null !== $min && $min < 0 ) { $min = null; }
			if ( null !== $max && $max < 0 ) { $max = null; }
			if ( null !== $min && null !== $max && $min > $max ) {
				// در صورت خطای کاربر، حد پایین را مبنا می‌گیریم.
				$max = null;
			}
			$filters['price_min'] = $min;
			$filters['price_max'] = $max;
			return $filters;
		}

		public static function type_allowed( $t ) {
			return in_array( $t, array( 'simple', 'variable', 'grouped', 'external' ), true );
		}

		public static function ids( $raw ) {
			$parts = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
			$ids   = array();
			foreach ( $parts as $part ) {
				if ( ! is_scalar( $part ) ) {
					continue;
				}
				$part = trim( (string) $part );
				if ( '' === $part || ! preg_match( '/^\\d+$/', $part ) ) {
					continue;
				}
				$id = absint( $part );
				if ( $id ) {
					$ids[] = $id;
				}
			}
			return array_values( array_unique( $ids ) );
		}

		/**
		 * توکن/امضای آرگومان‌ها. از پنجرهٔ زمانی حذف شد تا اجراهای طولانی قطع نشوند.
		 */
		private static function canonical( $args, $with_user, $snapshot = array() ) {
			$cats             = isset( $args['category_ids'] ) ? (array) $args['category_ids'] : array();
			$prods            = isset( $args['product_ids'] ) ? (array) $args['product_ids'] : array();
			$excluded_cats     = isset( $args['excluded_category_ids'] ) ? (array) $args['excluded_category_ids'] : array();
			$excluded_products = isset( $args['excluded_product_ids'] ) ? (array) $args['excluded_product_ids'] : array();
			$target_terms      = array_values( array_unique( array_filter( array_map( 'absint', isset( $snapshot['terms'] ) ? (array) $snapshot['terms'] : array() ) ) ) );
			$excluded_terms    = array_values( array_unique( array_filter( array_map( 'absint', isset( $snapshot['excluded_terms'] ) ? (array) $snapshot['excluded_terms'] : array() ) ) ) );
			sort( $cats, SORT_NUMERIC );
			sort( $prods, SORT_NUMERIC );
			sort( $excluded_cats, SORT_NUMERIC );
			sort( $excluded_products, SORT_NUMERIC );
			sort( $target_terms, SORT_NUMERIC );
			sort( $excluded_terms, SORT_NUMERIC );
			$filters = isset( $args['filters'] ) ? (array) $args['filters'] : array();
			$types   = isset( $filters['types'] ) ? $filters['types'] : array();
			sort( $types );
			$statuses = isset( $filters['statuses'] ) ? $filters['statuses'] : array();
			sort( $statuses );

			$num = function ( $v ) {
				return ( null === $v || '' === $v || false === $v ) ? '' : number_format( (float) $v, 6, '.', '' );
			};

			return implode( '|', array(
				$with_user ? (string) get_current_user_id() : '',
				isset( $args['target_type'] ) ? (string) $args['target_type'] : '',
				implode( ',', $cats ),
				implode( ',', $prods ),
				! empty( $args['include_children'] ) ? '1' : '0',
				implode( ',', $excluded_cats ),
				implode( ',', $excluded_products ),
				! empty( $args['exclude_category_children'] ) ? '1' : '0',
				isset( $args['round_mode'] ) ? (string) $args['round_mode'] : 'none',
				isset( $args['operation'] ) ? (string) $args['operation'] : '',
				$num( isset( $args['value'] ) ? $args['value'] : null ),
				! empty( $filters['only_sale'] ) ? '1' : '0',
				! empty( $filters['only_wholesale'] ) ? '1' : '0',
				$num( isset( $filters['price_min'] ) ? $filters['price_min'] : null ),
				$num( isset( $filters['price_max'] ) ? $filters['price_max'] : null ),
				implode( ',', $types ),
				implode( ',', $statuses ),
				absint( isset( $snapshot['parents'] ) ? $snapshot['parents'] : 0 ),
				absint( isset( $snapshot['ceiling'] ) ? $snapshot['ceiling'] : 0 ),
				implode( ',', $target_terms ),
				implode( ',', $excluded_terms ),
			) );
		}

		public static function args_hash( $args ) {
			return hash( 'sha256', self::canonical( $args, false ) );
		}

		public static function make_token( $args, $snapshot = array() ) {
			return hash_hmac( 'sha256', self::canonical( $args, true, $snapshot ), wp_salt( 'nonce' ) );
		}

		public static function verify_token( $args, $token, $snapshot = array() ) {
			$token = (string) $token;
			if ( '' === $token ) {
				return false;
			}
			$expected = self::make_token( $args, $snapshot );
			return hash_equals( $expected, $token );
		}

		/* -----------------------------------------------------------------
		 * محاسبهٔ قیمت‌ها — منبع واحد، هم برای پیش‌نمایش هم برای اجرا
		 * --------------------------------------------------------------- */

		/**
		 * قیمت عادی جدید.
		 * @return array{ok:bool,new:string,msg:string}
		 */
		private static function calc_regular( $op, $current_raw, $value, $seed = 0 ) {
			$current = '' === $current_raw ? 0.0 : (float) $current_raw;
			$value   = (float) $value;
			$new     = null;
			$skip_no_current = 'regular_set' !== $op && '' === (string) $current_raw;

			switch ( $op ) {
				case 'regular_increase_percent': $new = $current * ( 1 + $value / 100 ); break;
				case 'regular_decrease_percent': $new = $current * ( 1 - $value / 100 ); break;
				case 'regular_increase_fixed':   $new = $current + $value; break;
				case 'regular_decrease_fixed':   $new = $current - $value; break;
				case 'regular_set':              $new = $value; break;
			}
			$new = self::finish( $op, $new, $current, $value, $seed );
			$new = self::round_price( $new );
			if ( false === $new ) {
				return array( 'ok' => false, 'new' => '', 'msg' => 'نتیجهٔ محاسبه نامعتبر است (عدد بیش از حد بزرگ).' );
			}
			if ( $new > TCP_Settings::RESULT_CEIL ) {
				return array( 'ok' => false, 'new' => '', 'msg' => 'نتیجهٔ محاسبه بیش از حد مجاز است.' );
			}
			return array( 'ok' => true, 'new' => (string) $new, 'msg' => $skip_no_current ? 'قیمت عادی ندارد.' : '' );
		}

		/**
		 * قیمت فروش ویژهٔ جدید.
		 */
		private static function calc_sale( $op, $regular_raw, $value, $sale_current = '', $seed = 0 ) {
			if ( 'sale_remove' === $op ) {
				return array( 'ok' => true, 'new' => '', 'msg' => '' );
			}
			if ( '' === (string) $regular_raw ) {
				return array( 'ok' => false, 'new' => '', 'msg' => 'قیمت عادی ندارد.' );
			}
			$regular = (float) $regular_raw;
			if ( 'sale_set' === $op ) {
				$new = self::round_price( (float) $value );
			} else { // sale_discount_percent
				$new = self::round_price( self::finish( $op, $regular * ( 1 - (float) $value / 100 ), $regular, $value, $seed ) );
			}
			if ( false === $new ) {
				return array( 'ok' => false, 'new' => '', 'msg' => 'نتیجهٔ محاسبه نامعتبر است.' );
			}
			if ( $regular > 0 && $new >= $regular ) {
				return array( 'ok' => false, 'new' => '', 'msg' => 'فروش ویژه باید کمتر از قیمت عادی ' . wc_format_decimal( $regular ) . ' باشد.' );
			}
			return array( 'ok' => true, 'new' => (string) $new, 'msg' => '' );
		}

		/**
		 * قیمت عمدهٔ جدید (فراخوانی‌کننده باید مطمئن شود که قبلاً قیمت عمده داشته).
		 */
		private static function calc_wholesale( $op, $current_raw, $retail_raw, $value, $seed = 0 ) {
			$current = '' === $current_raw ? 0.0 : (float) $current_raw;
			$value   = (float) $value;
			$new     = null;

			switch ( $op ) {
				case 'wholesale_increase_percent': $new = $current * ( 1 + $value / 100 ); break;
				case 'wholesale_decrease_percent': $new = $current * ( 1 - $value / 100 ); break;
				case 'wholesale_increase_fixed':   $new = $current + $value; break;
				case 'wholesale_decrease_fixed':   $new = $current - $value; break;
				case 'wholesale_set':              $new = $value; break;
				case 'wholesale_from_retail_percent':
					if ( '' === (string) $retail_raw || (float) $retail_raw <= 0 ) {
						return array( 'ok' => false, 'new' => '', 'msg' => 'قیمت فروش فعال ندارد.' );
					}
					$new = (float) $retail_raw * ( 1 - $value / 100 );
					break;
			}
			$new = self::finish( $op, $new, 'wholesale_from_retail_percent' === $op ? (float) $retail_raw : $current, $value, $seed );
			$new = self::round_price( $new );
			if ( false === $new ) {
				return array( 'ok' => false, 'new' => '', 'msg' => 'نتیجهٔ محاسبه نامعتبر است.' );
			}
			if ( $new <= 0 ) {
				return array( 'ok' => false, 'new' => '', 'msg' => 'قیمت عمدهٔ جدید باید بیشتر از صفر باشد.' );
			}
			if ( $new > TCP_Settings::RESULT_CEIL ) {
				return array( 'ok' => false, 'new' => '', 'msg' => 'نتیجهٔ محاسبه بیش از حد مجاز است.' );
			}
			return array( 'ok' => true, 'new' => (string) $new, 'msg' => '' );
		}

		/* -----------------------------------------------------------------
		 * اجرا روی محصول
		 * --------------------------------------------------------------- */

		/**
		 * @return array{status:string,message:string,entry:?array}
		 */
		private static function change_regular_sale_object( $product, $op, $value, $run_id = 0 ) {
			$id      = $product->get_id();
			$object_type = self::is_sale_op( $op ) ? 'sale' : 'regular';
			$before   = '';
			$after    = '';
			$entry    = null;
			$run_id   = absint( $run_id );
			$probe    = 'sale' === $object_type ? (string) $product->get_sale_price( 'edit' ) : (string) $product->get_regular_price( 'edit' );
			$replay   = self::replay_if_done( $product, $run_id, $probe, $object_type );
			if ( $replay ) {
				return $replay;
			}

			try {
				if ( self::is_regular_op( $op ) ) {
					$cur   = $product->get_regular_price( 'edit' );
					if ( 'regular_set' !== $op && '' === $cur ) {
						return array( 'status' => 'skip', 'message' => '#' . $id . ' قیمت عادی ندارد.', 'entry' => null );
					}
					$res   = self::calc_regular( $op, $cur, $value, $id );
					if ( ! $res['ok'] ) {
						return array( 'status' => 'skip', 'message' => '#' . $id . ' ' . $res['msg'], 'entry' => null );
					}
					$before = '' === $cur ? '' : wc_format_decimal( $cur );
					$after  = wc_format_decimal( $res['new'] );
					if ( $before === $after ) {
						return array( 'status' => 'skip', 'message' => '#' . $id . ' قیمت تغییری نکرد.', 'entry' => null );
					}
					self::stamp_guard( $product, $run_id, $before, $after );
					$product->set_regular_price( $res['new'] );
					$product->save();
					$entry = array( 'object_type' => $object_type, 'parent_id' => self::parent_of( $product ), 'object_id' => $id, 'before' => $before, 'after' => $after );
					return array( 'status' => 'updated', 'message' => '#' . $id . ' قیمت عادی تغییر کرد.', 'entry' => $entry );
				}

				if ( 'sale_remove' === $op ) {
					$cur = $product->get_sale_price( 'edit' );
					if ( '' === $cur ) {
						return array( 'status' => 'skip', 'message' => '#' . $id . ' فروش ویژه ندارد.', 'entry' => null );
					}
					$before = wc_format_decimal( $cur );
					self::stamp_guard( $product, $run_id, $before, '' );
					$product->set_sale_price( '' );
					$product->set_date_on_sale_from( null );
					$product->set_date_on_sale_to( null );
					$product->save();
					$entry = array( 'object_type' => 'sale', 'parent_id' => self::parent_of( $product ), 'object_id' => $id, 'before' => $before, 'after' => '' );
					return array( 'status' => 'updated', 'message' => '#' . $id . ' فروش ویژه حذف شد.', 'entry' => $entry );
				}

				$regular = $product->get_regular_price( 'edit' );
				$sale_cur = $product->get_sale_price( 'edit' );
				$res = self::calc_sale( $op, $regular, $value, $sale_cur, $id );
				if ( ! $res['ok'] ) {
					return array( 'status' => 'error', 'message' => '#' . $id . ' ' . $res['msg'], 'entry' => null );
				}
				$before = '' === $sale_cur ? '' : wc_format_decimal( $sale_cur );
				$after  = wc_format_decimal( $res['new'] );
				if ( $before === $after ) {
					return array( 'status' => 'skip', 'message' => '#' . $id . ' قیمت تغییری نکرد.', 'entry' => null );
				}
				self::stamp_guard( $product, $run_id, $before, $after );
				$product->set_sale_price( $res['new'] );
				$product->set_date_on_sale_from( null );
				$product->set_date_on_sale_to( null );
				$product->save();
				$entry = array( 'object_type' => 'sale', 'parent_id' => self::parent_of( $product ), 'object_id' => $id, 'before' => $before, 'after' => $after );
				return array( 'status' => 'updated', 'message' => '#' . $id . ' فروش ویژه تغییر کرد.', 'entry' => $entry );

			} catch ( Throwable $e ) {
				return array( 'status' => 'error', 'message' => '#' . $id . ' خطا: ' . $e->getMessage(), 'entry' => null, 'unsafe' => true );
			}
		}

		private static function parent_of( $product ) {
			if ( method_exists( $product, 'get_parent_id' ) ) {
				$pid = $product->get_parent_id();
				return $pid ? absint( $pid ) : $product->get_id();
			}
			return $product->get_id();
		}

		public static function wholesale_price_raw( $product_id ) {
			$raw = get_post_meta( absint( $product_id ), TCP_WHOLESALE_META, true );
			$raw = wc_format_decimal( $raw );
			return ( '' !== $raw && (float) $raw > 0 ) ? $raw : '';
		}

		public static function update_wholesale_meta( $product_id, $value ) {
			$product_id = absint( $product_id );
			if ( ! $product_id ) {
				return false;
			}
			if ( '' === $value ) {
				if ( metadata_exists( 'post', $product_id, TCP_WHOLESALE_META ) ) {
					delete_post_meta( $product_id, TCP_WHOLESALE_META );
					clean_post_cache( $product_id );
					return true;
				}
				return false;
			}
			$value = wc_format_decimal( self::round_price( $value ) );
			if ( '' === $value || (float) $value <= 0 ) {
				return false;
			}
			$current = (string) get_post_meta( $product_id, TCP_WHOLESALE_META, true );
			if ( (string) $current === (string) $value ) {
				return false;
			}
			update_post_meta( $product_id, TCP_WHOLESALE_META, $value );
			clean_post_cache( $product_id );
			return true;
		}

		private static function change_wholesale_object( $product, $op, $value, $run_id = 0 ) {
			$id      = $product->get_id();
			$current = self::wholesale_price_raw( $id );
			$run_id  = absint( $run_id );
			$replay  = self::replay_if_done( $product, $run_id, $current, 'wholesale' );
			if ( $replay ) {
				return $replay;
			}

			if ( '' === $current ) {
				return array( 'status' => 'skip', 'message' => '#' . $id . ' قیمت عمده ندارد.', 'entry' => null );
			}
			try {
				if ( 'wholesale_clear' === $op ) {
					self::stamp_guard_meta( $id, $run_id, $current, '' );
					$changed = self::update_wholesale_meta( $id, '' );
					if ( ! $changed ) {
						return array( 'status' => 'skip', 'message' => '#' . $id . ' قیمت عمده‌ای برای حذف وجود ندارد.', 'entry' => null );
					}
					$entry = array( 'object_type' => 'wholesale', 'parent_id' => self::parent_of( $product ), 'object_id' => $id, 'before' => $current, 'after' => '' );
					return array( 'status' => 'updated', 'message' => '#' . $id . ' قیمت عمده حذف شد.', 'entry' => $entry );
				}

				$retail = $product->get_price( 'edit' );
				$res    = self::calc_wholesale( $op, $current, $retail, $value, $id );
				if ( ! $res['ok'] ) {
					return array( 'status' => 'error', 'message' => '#' . $id . ' ' . $res['msg'], 'entry' => null );
				}
				self::stamp_guard_meta( $id, $run_id, $current, wc_format_decimal( $res['new'] ) );
				$changed = self::update_wholesale_meta( $id, $res['new'] );
				if ( ! $changed ) {
					return array( 'status' => 'skip', 'message' => '#' . $id . ' قیمت عمده تغییری نکرد.', 'entry' => null );
				}
				$entry = array( 'object_type' => 'wholesale', 'parent_id' => self::parent_of( $product ), 'object_id' => $id, 'before' => $current, 'after' => wc_format_decimal( $res['new'] ) );
				return array( 'status' => 'updated', 'message' => '#' . $id . ' قیمت عمده به ' . $res['new'] . ' تغییر کرد.', 'entry' => $entry );
			} catch ( Throwable $e ) {
				return array( 'status' => 'error', 'message' => '#' . $id . ' خطای قیمت عمده: ' . $e->getMessage(), 'entry' => null, 'unsafe' => true );
			}
		}

		/**
		 * اجرای یک محصول مادر (و وریشن‌هایش).
		 *
		 * $ctx اختیاری:
		 * - run_id: برای نشانِ ضدِ اعمال دوباره و لاگ
		 * - after_object: آخرین شیءِ تمام‌شده؛ از شیء بعدی ادامه بده
		 * - deadline: microtime؛ اگر رد شود والد ناتمام برمی‌گردد
		 * - on_object: callback( $object_id, $result ) بعد از هر شیء
		 *
		 * @return array{updated:int,skipped:int,errors:array,entries:array,complete:bool,sync_parent:bool}
		 */
		public static function process_parent( $product_id, $op, $value, $ctx = array() ) {
			$ctx      = is_array( $ctx ) ? $ctx : array();
			$run_id   = isset( $ctx['run_id'] ) ? absint( $ctx['run_id'] ) : 0;
			$after    = isset( $ctx['after_object'] ) ? absint( $ctx['after_object'] ) : 0;
			$deadline = isset( $ctx['deadline'] ) ? (float) $ctx['deadline'] : 0;
			$r        = array(
				'updated'     => 0,
				'skipped'     => 0,
				'errors'      => array(),
				'entries'     => array(),
				'complete'    => true,
				'sync_parent' => false,
			);
			$product_id = absint( $product_id );

			$p = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
			if ( ! $p ) {
				self::emit( $ctx, $r, $product_id, array( 'status' => 'error', 'message' => '#' . $product_id . ' محصول پیدا نشد.', 'entry' => null ) );
				return $r;
			}
			if ( $p->is_type( 'grouped' ) ) {
				self::emit( $ctx, $r, $product_id, array( 'status' => 'skip', 'message' => '', 'entry' => null ) );
				return $r;
			}

			$is_variable = $p->is_type( 'variable' );
			if ( $is_variable ) {
				$object_ids = class_exists( 'TCP_DB' ) ? TCP_DB::child_ids( $product_id ) : array_map( 'absint', (array) $p->get_children() );
			} else {
				$object_ids = array( $product_id );
			}
			$object_ids = self::pending_object_ids( $object_ids, $after );

			if ( $is_variable && empty( $object_ids ) && ! $after ) {
				self::emit( $ctx, $r, $product_id, array( 'status' => 'skip', 'message' => '', 'entry' => null ) );
				return $r;
			}
			if ( empty( $object_ids ) ) {
				$r['sync_parent'] = $is_variable;
				return $r;
			}

			self::prime_objects( $object_ids );
			$logged = array();
			if ( $run_id && class_exists( 'TCP_DB' ) ) {
				$logged = array_flip( TCP_DB::logged_object_ids( $run_id, $product_id ) );
			}

			foreach ( $object_ids as $oid ) {
				if ( isset( $logged[ $oid ] ) ) {
					self::emit( $ctx, $r, $oid, array( 'status' => 'already', 'message' => '', 'entry' => null ) );
				} else {
					$object = ( ! $is_variable && $oid === $product_id ) ? $p : ( function_exists( 'wc_get_product' ) ? wc_get_product( $oid ) : null );
					if ( ! $object ) {
						self::emit( $ctx, $r, $oid, array( 'status' => 'skip', 'message' => '#' . $oid . ' پیدا نشد.', 'entry' => null ) );
					} elseif ( self::is_wholesale_op( $op ) ) {
						self::emit( $ctx, $r, $oid, self::change_wholesale_object( $object, $op, $value, $run_id ) );
					} else {
						self::emit( $ctx, $r, $oid, self::change_regular_sale_object( $object, $op, $value, $run_id ) );
					}
				}
				if ( $deadline && microtime( true ) >= $deadline ) {
					$r['complete']    = false;
					$r['sync_parent'] = $is_variable && $r['updated'] > 0;
					return $r;
				}
			}

			$r['complete']    = true;
			$r['sync_parent'] = $is_variable;
			return $r;
		}

		/** ثبت نتیجهٔ یک شیء در آمار و، اگر خواسته شده، در callback ادامه. */
		private static function emit( $ctx, &$r, $object_id, $result ) {
			self::tally( $r, $result );
			if ( ! empty( $ctx['on_object'] ) && is_callable( $ctx['on_object'] ) ) {
				call_user_func( $ctx['on_object'], absint( $object_id ), $result );
			}
		}

		private static function prime_objects( $ids ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
			if ( empty( $ids ) ) {
				return;
			}
			foreach ( array_chunk( $ids, 200 ) as $chunk ) {
				if ( function_exists( 'update_meta_cache' ) ) {
					update_meta_cache( 'post', $chunk );
				}
				if ( function_exists( '_prime_post_caches' ) ) {
					_prime_post_caches( $chunk, false, true );
				}
			}
		}

		private static function replay_if_done( $product, $run_id, $current, $field ) {
			$run_id = absint( $run_id );
			if ( ! $run_id || ! is_object( $product ) || ! method_exists( $product, 'get_meta' ) ) {
				return null;
			}
			$raw = (string) $product->get_meta( self::GUARD_META, true );
			if ( ! self::guard_should_skip( $raw, $current, $run_id ) ) {
				return null;
			}
			$guard = self::guard_decode( $raw );
			return array(
				'status'  => 'updated',
				'message' => '',
				'entry'   => array(
					'object_type' => $field,
					'parent_id'   => self::parent_of( $product ),
					'object_id'   => $product->get_id(),
					'before'      => $guard['before'],
					'after'       => $guard['after'],
				),
			);
		}

		private static function stamp_guard( $product, $run_id, $before, $after ) {
			$run_id = absint( $run_id );
			if ( ! $run_id || ! is_object( $product ) || ! method_exists( $product, 'update_meta_data' ) ) {
				return;
			}
			$product->update_meta_data( self::GUARD_META, self::guard_encode( $run_id, $before, $after ) );
		}

		private static function stamp_guard_meta( $object_id, $run_id, $before, $after ) {
			$run_id = absint( $run_id );
			$object_id = absint( $object_id );
			if ( ! $run_id || ! $object_id || ! function_exists( 'update_post_meta' ) ) {
				return;
			}
			update_post_meta( $object_id, self::GUARD_META, self::guard_encode( $run_id, $before, $after ) );
		}

		private static function tally( &$r, $x ) {
			$status = isset( $x['status'] ) ? $x['status'] : '';
			if ( 'updated' === $status ) {
				$r['updated']++;
				if ( ! empty( $x['entry'] ) ) {
					$r['entries'][] = $x['entry'];
				}
			} elseif ( 'error' === $status ) {
				$r['errors'][] = isset( $x['message'] ) ? $x['message'] : '';
			} elseif ( 'skip' === $status ) {
				$r['skipped']++;
			}
		}

		/* -----------------------------------------------------------------
		 * رانر اجرای صفحه (مشترک بین AJAX و WP-Cron)
		 * --------------------------------------------------------------- */

		/**
		 * یک «صفحه» (batch) از یک اجرا را پردازش می‌کند.
		 *
		 * @param array $run سطر اجرا (باید status=running باشد و قفل گرفته شده باشد).
		 * @return array{ok:bool,msg:string,data:array}
		 */
		public static function run_next_page( $run ) {
			$args = json_decode( (string) $run['args'], true );
			if ( ! is_array( $args ) ) {
				return array( 'ok' => false, 'msg' => 'دادهٔ اجرا خراب است؛ اجرا را از نو بساز.', 'data' => array() );
			}
			if ( isset( $args['target_type'] ) && 'all' === $args['target_type'] && ! TCP_DB::log_table_ready() ) {
				throw new RuntimeException( 'جدول لاگ در دسترس نیست؛ برای جلوگیری از تغییر بدون قابلیت بازگردانی، اجرا متوقف شد.' );
			}
			self::set_round_mode( isset( $args['round_mode'] ) ? $args['round_mode'] : 'none' );
			$settings   = TCP_Settings::get_settings();
			// دسته از زمان شروعِ اجرا ذخیره شده تا با تغییر تنظیمات وسط اجرا جابه‌جا نشود.
			$batch_size = isset( $args['batch'] ) ? absint( $args['batch'] ) : absint( $settings['batch_size'] );
			$batch_size = max( 1, min( 100, $batch_size ) );

		// Catalog-wide and very large category runs do not keep every ID in args.
		if ( self::is_catalog_run( $args ) ) {
			return self::run_catalog_page( $run, $args, $batch_size );
		}


			// اجرای قدیمیِ بدون فهرست ثابت (شروع‌شده قبل از این نسخه): از همین‌جا یک‌بار یخ می‌خورد.
			if ( ! isset( $args['parent_ids'] ) && isset( $args['operation'] ) ) {
				$run  = self::freeze_run_args( $run, $args, $batch_size );
				$args = json_decode( (string) $run['args'], true );
			}
			if ( is_array( $args ) && isset( $args['parent_ids'] ) ) {
				return self::run_frozen_page( $run, $args, self::decode_parent_ids( $args['parent_ids'] ), $batch_size );
			}

			// فالبک قدیمی (فقط اجرای خراب/نیمه‌کاره بدون operation): محاسبهٔ پویای انتخاب.
			$parents = TCP_DB::selection_parent_ids( $args );
			$total   = count( $parents );
			$pages   = max( 1, (int) ceil( $total / $batch_size ) );

			if ( empty( $parents ) || (int) $run['page'] >= $pages ) {
				return array( 'ok' => true, 'msg' => '', 'data' => array( 'done' => true, 'progress' => 100, 'page' => (int) $run['page'], 'pages' => $pages, 'parents' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() ) );
			}

			$slice = array_slice( $parents, (int) $run['page'] * $batch_size, $batch_size );
			$updated = $skipped = 0;
			$errors  = array();
			$entries = array();

			foreach ( $slice as $pid ) {
				$x = self::process_parent( absint( $pid ), $args['operation'], $args['value'] );
				$updated += $x['updated'];
				$skipped += $x['skipped'];
				foreach ( $x['errors'] as $m ) {
					$errors[] = wp_strip_all_tags( $m );
				}
				foreach ( $x['entries'] as $e ) {
					$entries[] = $e;
				}
			}

			TCP_DB::insert_log( (int) $run['id'], $entries );

			$next_page = (int) $run['page'] + 1;
			$done      = $next_page >= $pages;

			$counts = array(
				'page'          => $next_page,
				'total_pages'   => $pages,
				'total_parents' => max( (int) $run['total_parents'], $total ),
				'count_updated' => (int) $run['count_updated'] + $updated,
				'count_skipped' => (int) $run['count_skipped'] + $skipped,
				'count_errors'  => (int) $run['count_errors'] + count( $errors ),
				'updated_at'    => TCP_DB::now(),
			);
			TCP_DB::update_run( (int) $run['id'], $counts );

			$progress = $done ? 100 : min( 99, round( ( $next_page / max( 1, $pages ) ) * 100, 1 ) );

			return array(
				'ok'   => true,
				'msg'  => '',
				'data' => array(
					'done'     => $done,
					'progress' => $progress,
					'page'     => $next_page,
					'pages'    => $pages,
					'parents'  => count( $slice ),
					'updated'  => $updated,
					'skipped'  => $skipped,
					'errors'   => array_slice( $errors, 0, 100 ),
				),
			);
		}

		/**
		 * اجرای قدیمی را از نقطهٔ فعلی به حالت «فهرست ثابت» منتقل می‌کند (مهاجرت یک‌باره).
		 * cursor جدید = تعداد والدهایی که صفحه‌بندی قدیمی عملاً پردازش کرده است.
		 */
		private static function freeze_run_args( $run, $args, $batch_size ) {
			if ( self::is_catalog_run( $args ) ) {
				return $run;
			}
			$parents = TCP_DB::selection_parent_ids( $args );
			$total   = count( $parents );
			$cursor  = min( (int) $run['page'] * $batch_size, $total );

			$args['batch']      = $batch_size;
			$args['parent_ids'] = self::encode_parent_ids( $parents );
			$new_args           = wp_json_encode( $args );

			TCP_DB::update_run(
				(int) $run['id'],
				array(
					'args'          => $new_args,
					'page'          => $cursor,
					'total_pages'   => $total,
					'total_parents' => $total,
					'updated_at'    => TCP_DB::now(),
				)
			);

			$run['args']         = $new_args;
			$run['page']         = $cursor;
			$run['total_pages']  = $total;
			$run['total_parents'] = $total;
			return $run;
		}

		/**
		 * پردازش یک بازهٔ کوتاه از فهرست ثابتِ اجرا با بودجهٔ زمانی.
		 *
		 * page = تعداد والدِ تمام‌شده. اگر یک مادر متغیر وسط بودجه قطع شود،
		 * scan_pending/scan_object همان‌جا ذخیره می‌شود و درخواست بعد از همان متغیر ادامه می‌دهد.
		 */
		private static function run_frozen_page( $run, $args, $parents, $batch_size ) {
			$total  = count( $parents );
			$cursor = min( max( 0, (int) $run['page'] ), $total );
			if ( ! $total || $cursor >= $total ) {
				return array( 'ok' => true, 'msg' => '', 'data' => array( 'done' => true, 'progress' => 100, 'page' => $cursor, 'pages' => $total, 'parents' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array(), 'partial' => false ) );
			}
			$slice  = array_slice( array_values( $parents ), $cursor, $batch_size );
			$result = self::drain_parents( $run, $args, $slice );
			$done   = $result['page'] >= $total;
			return self::queue_response( $result, $done, $total );
		}

		/**
		 * پیمایش کلیدستِ کاتالوگ: هر درخواست فقط یک صفحهٔ کوچک از ID > cursor می‌خواند.
		 * مجموعه با سقف شناسهٔ شروع اجرا یخ می‌خورد، بدون نگه داشتن همهٔ شناسه‌ها در حافظه.
		 */
		private static function run_catalog_page( $run, $args, $batch_size ) {
			$ceiling = isset( $run['scan_ceiling'] ) ? absint( $run['scan_ceiling'] ) : 0;
			if ( ! $ceiling && isset( $args['scan']['ceiling'] ) ) {
				$ceiling = absint( $args['scan']['ceiling'] );
			}
			if ( ! $ceiling ) {
				return array( 'ok' => false, 'msg' => 'سقف شناسهٔ این اجرا ثبت نشده؛ اجرا را از نو بساز.', 'data' => array() );
			}
			$after   = isset( $run['scan_after'] ) ? absint( $run['scan_after'] ) : 0;
			$pending = isset( $run['scan_pending'] ) ? absint( $run['scan_pending'] ) : 0;
			$fetch_n = max( 1, min( 100, absint( $batch_size ) ) );
			$queue   = array();
			if ( $pending > $after ) {
				$queue[] = $pending;
			}
			$fetched = TCP_DB::keyset_parent_ids( $args, max( $after, $pending ), $ceiling, $fetch_n );
			if ( null === $fetched ) {
				return array( 'ok' => false, 'retry' => true, 'msg' => 'خواندن صفحهٔ بعدی کاتالوگ ناموفق بود؛ اجرا تمام نشده و باید ادامه پیدا کند.', 'data' => array() );
			}
			foreach ( $fetched as $id ) {
				if ( $id !== $pending ) {
					$queue[] = $id;
				}
			}
			$total = max( 0, (int) $run['total_parents'] );
			if ( empty( $queue ) ) {
				return array( 'ok' => true, 'msg' => '', 'data' => array( 'done' => true, 'progress' => 100, 'page' => (int) $run['page'], 'pages' => $total, 'parents' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array(), 'partial' => false ) );
			}
			$may_have_more = count( $fetched ) >= $fetch_n;
			$result        = self::drain_parents( $run, $args, $queue );
			$done          = $result['finished_queue'] && ! $may_have_more;
			return self::queue_response( $result, $done, $total );
		}

		/** پاسخ یکدست برای هر دو مسیر اجرا. */
		private static function queue_response( $result, $done, $total ) {
			$page     = (int) $result['page'];
			$progress = $done ? 100 : ( $total > 0 ? min( 99, round( ( $page / $total ) * 100, 1 ) ) : 0 );
			return array(
				'ok'   => true,
				'msg'  => '',
				'data' => array(
					'done'     => $done,
					'progress' => $progress,
					'page'     => $page,
					'pages'    => $total,
					'parents'  => (int) $result['delta']['parents'],
					'updated'  => (int) $result['delta']['updated'],
					'skipped'  => (int) $result['delta']['skipped'],
					'errors'   => array_slice( $result['delta']['errors'], 0, 100 ),
					'partial'  => ! empty( $result['partial'] ),
				),
			);
		}

		/**
		 * یک صف از مادرها را با بودجهٔ زمانی و ثبت بعد از هر متغیر پردازش می‌کند.
		 * قطع شدن درخواست حداکثر یک متغیرِ در حال save را دوباره می‌بیند و نشانِ guard جلوی درصدِ دوباره را می‌گیرد.
		 */
		private static function drain_parents( $run, $args, $queue ) {
			$run_id  = (int) $run['id'];
			$pending = isset( $run['scan_pending'] ) ? absint( $run['scan_pending'] ) : 0;
			$obj     = isset( $run['scan_object'] ) ? absint( $run['scan_object'] ) : 0;
			$page    = (int) $run['page'];
			$counts  = array(
				'updated' => (int) $run['count_updated'],
				'skipped' => (int) $run['count_skipped'],
				'errors'  => (int) $run['count_errors'],
			);
			$delta = array( 'updated' => 0, 'skipped' => 0, 'errors' => array(), 'parents' => 0 );
			$op        = $args['operation'];
			$value     = isset( $args['value'] ) ? $args['value'] : null;
			$force_log = isset( $args['target_type'] ) && 'all' === $args['target_type'];
			$touched   = array();
			$stopped = false;
			$partial = false;

			self::begin_batch_runtime();
			$deadline = microtime( true ) + self::time_budget();
			try {
				foreach ( (array) $queue as $pid ) {
					$pid    = absint( $pid );
					$resume = ( $pending === $pid ) ? $obj : 0;
					$x      = self::process_parent(
						$pid,
						$op,
						$value,
						array(
							'run_id'       => $run_id,
							'after_object' => $resume,
							'deadline'     => $deadline,
							'on_object'    => function ( $object_id, $result ) use ( $run_id, $pid, $force_log, &$counts, &$delta ) {
								self::absorb_result( $run_id, $pid, $object_id, $result, $counts, $delta, $force_log );
							},
						)
					);
					if ( ! empty( $x['sync_parent'] ) ) {
						$touched[] = $pid;
					}
					if ( ! empty( $x['complete'] ) ) {
						$page++;
						$delta['parents']++;
						$pending = 0;
						$obj     = 0;
						TCP_DB::update_run(
							$run_id,
							array(
								'page'         => $page,
								'scan_after'   => $pid,
								'scan_pending' => 0,
								'scan_object'  => 0,
								'updated_at'   => TCP_DB::now(),
							)
						);
						if ( microtime( true ) >= $deadline ) {
							$stopped = true;
							break;
						}
					} else {
						$stopped = true;
						$partial = true;
						break;
					}
				}
			} finally {
				self::end_batch_runtime();
				self::sync_parents( $touched );
			}

			return array(
				'page'           => $page,
				'stopped_early'  => $stopped,
				'finished_queue' => ! $stopped,
				'partial'        => $partial,
				'delta'          => $delta,
				'counts'         => $counts,
			);
		}

		/** لاگ + آمار + cursor را بعد از هر شیء می‌نویسد تا قطعی، کار را از همان‌جا ادامه دهد. */
		private static function absorb_result( $run_id, $parent_id, $object_id, $result, &$counts, &$delta, $force_log = false ) {
			$status = isset( $result['status'] ) ? $result['status'] : 'skip';
			if ( 'updated' === $status ) {
				$must_log = $force_log || TCP_Settings::logging_enabled();
				if ( $must_log && empty( $result['entry'] ) ) {
					throw new RuntimeException( 'تغییر قیمت انجام شد اما جزئیات لازم برای ثبت لاگ در دسترس نیست؛ اجرا متوقف شد.' );
				}
				if ( ! empty( $result['entry'] ) && ! TCP_DB::insert_log( $run_id, array( $result['entry'] ), $force_log ) ) {
					// Cursor را جلو نمی‌بریم؛ guard باعث می‌شود ادامهٔ همان شیء را بدون اعمال دوباره ثبت کنیم.
					throw new RuntimeException( 'ثبت لاگ قیمت ناموفق بود؛ اجرا متوقف شد تا امکان بازگردانی از بین نرود.' );
				}
				$counts['updated']++;
				$delta['updated']++;
				} elseif ( 'error' === $status ) {
					$msg = isset( $result['message'] ) ? wp_strip_all_tags( $result['message'] ) : '';
					if ( ! empty( $result['unsafe'] ) && ( $force_log || TCP_Settings::logging_enabled() ) ) {
						throw new TCP_Unsafe_Exception( 'وضعیت ذخیرهٔ محصول پس از خطای قیمت نامطمئن است؛ اجرا متوقف شد تا از اعمال دوباره جلوگیری شود. ' . $msg );
					}
					if ( '' !== $msg ) {
					$delta['errors'][] = $msg;
				}
				$counts['errors']++;
			} elseif ( 'skip' === $status ) {
				$counts['skipped']++;
				$delta['skipped']++;
			}
			TCP_DB::update_run(
				$run_id,
				array(
					'scan_pending'  => absint( $parent_id ),
					'scan_object'   => absint( $object_id ),
					'count_updated' => $counts['updated'],
					'count_skipped' => $counts['skipped'],
					'count_errors'  => $counts['errors'],
					'updated_at'    => TCP_DB::now(),
				)
			);
		}

		/** همگام‌سازی قیمت والد متغیر بعد از آزاد شدن کش. */
		private static function sync_parents( $ids ) {
			$ids = array_unique( array_filter( array_map( 'absint', (array) $ids ) ) );
			foreach ( $ids as $parent_id ) {
				$p = function_exists( 'wc_get_product' ) ? wc_get_product( $parent_id ) : null;
				if ( $p && $p->is_type( 'variable' ) && class_exists( 'WC_Product_Variable' ) ) {
					WC_Product_Variable::sync( $parent_id );
				}
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $parent_id );
				}
				if ( function_exists( 'clean_post_cache' ) ) {
					clean_post_cache( $parent_id );
				}
			}
		}

		/**
		 * صفحهٔ بازگردانی: ردیف‌های لاگ منبع را به ترتیب برمی‌گرداند.
		 */
		public static function rollback_next_page( $run ) {
			$source_id = absint( $run['parent_run_id'] );
			if ( ! $source_id ) {
				return array( 'ok' => false, 'msg' => 'منبع بازگردانی مشخص نیست.', 'data' => array() );
			}
			$settings = TCP_Settings::get_settings();
			$batch_size = 0;
			if ( ! empty( $run['args'] ) ) {
				$decoded = json_decode( (string) $run['args'], true );
				if ( is_array( $decoded ) && ! empty( $decoded['batch'] ) ) {
					$batch_size = absint( $decoded['batch'] );
				}
			}
			if ( ! $batch_size ) {
				$batch_size = absint( $settings['batch_size'] );
			}
			$batch_size  = max( 1, min( 100, $batch_size ) );
			$total_rows  = TCP_DB::count_log( $source_id );
			// page = تعداد ردیف‌های برگشت‌داده‌شده (cursor)؛ اجرای قدیمی‌تر «شماره صفحه» بود
			// که با همان فرمول جدید هم فقط چند ردیف دوباره انجام می‌شود (بازگردانی idempotent است).
			$offset = (int) $run['page'];

			if ( $total_rows < 1 || $offset >= $total_rows ) {
				return array( 'ok' => true, 'msg' => '', 'data' => array( 'done' => true, 'progress' => 100, 'updated' => 0, 'skipped' => 0, 'errors' => array() ) );
			}

			$rows = TCP_DB::get_log_page( $source_id, $offset, $batch_size );
			if ( empty( $rows ) ) {
				return array( 'ok' => true, 'msg' => '', 'data' => array( 'done' => true, 'progress' => 100, 'updated' => 0, 'skipped' => 0, 'errors' => array() ) );
			}

			self::runtime_boost();
			$budget  = self::time_budget();
			$started = microtime( true );

			$count_updated = (int) $run['count_updated'];
			$count_skipped = (int) $run['count_skipped'];
			$count_errors  = (int) $run['count_errors'];
			$updated = $skipped = $processed = 0;
			$errors  = array();
			$entries = array();
			$touched_parents = array();

			foreach ( $rows as $row ) {
				$oid   = absint( $row['object_id'] );
				$field = $row['object_type'];
				$restore = (string) $row['before_value'];
				if ( ! in_array( $field, array( 'regular', 'sale', 'wholesale' ), true ) || ! $oid ) {
					$skipped++;
					$count_skipped++;
					$processed++;
					continue;
				}
				$current = self::read_field_value( $oid, $field );
				if ( self::apply_field_value( $oid, $field, $restore ) ) {
					$updated++;
					$count_updated++;
					$entries[] = array(
						'object_type' => $field,
						'parent_id'   => absint( $row['parent_id'] ),
						'object_id'   => $oid,
						'before'      => $current,
						'after'       => $restore,
					);
				} else {
					$skipped++;
					$count_skipped++;
				}
				$processed++;
				if ( absint( $row['parent_id'] ) ) {
					$touched_parents[] = absint( $row['parent_id'] );
				}
				if ( ( microtime( true ) - $started ) >= $budget && $processed < count( $rows ) ) {
					break; // بودجه تمام شد؛ ادامه در درخواست بعدی از همین cursor.
				}
			}

			TCP_DB::insert_log( (int) $run['id'], $entries );

			// همگام‌سازی والدهای متغیر در همین بازه.
			foreach ( array_unique( $touched_parents ) as $parent_id ) {
				$p = wc_get_product( $parent_id );
				if ( $p && $p->is_type( 'variable' ) && class_exists( 'WC_Product_Variable' ) ) {
					WC_Product_Variable::sync( $parent_id );
				}
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $parent_id );
				}
				clean_post_cache( $parent_id );
			}

			$next_cursor = $offset + $processed;
			$done        = $next_cursor >= $total_rows;

			TCP_DB::update_run( (int) $run['id'], array(
				'page'          => $next_cursor,
				'total_pages'   => max( $total_rows, (int) $run['total_pages'] ),
				'count_updated' => $count_updated,
				'count_skipped' => $count_skipped,
				'count_errors'  => $count_errors,
				'updated_at'    => TCP_DB::now(),
			) );

			return array(
				'ok'   => true,
				'msg'  => '',
				'data' => array(
					'done'     => $done,
					'progress' => $done ? 100 : min( 99, round( ( $next_cursor / max( 1, $total_rows ) ) * 100, 1 ) ),
					'updated'  => $updated,
					'skipped'  => $skipped,
					'errors'   => array_slice( $errors, 0, 100 ),
				),
			);
		}

		/**
		 * نهایی‌کردن یک اجرا: تغییر وضعیت + زمان‌بندی به‌روزرسانی جدول lookup ووکامرس.
		 *
		 * به‌روزرسانی جدول lookup روی کاتالوگ بزرگ ممکن است دقیقه‌ها طول بکشد؛
		 * اگر همین‌جا اجرا می‌شد، پاسخ «تمام شد»ِ آخرین مرحله هرگز به کلاینت نمی‌رسید
		 * و کاربر خطای «قطع ارتباط» روی ۹۹٪ می‌دید. حالا در رویداد تک‌بارهٔ WP-Cron
		 * در پس‌زمینه انجام می‌شود (پرچم pending ضامن تکرار اگر کرون اجرا نشده باشد).
		 */
		public static function finalize_run( $run_id, $status ) {
			$run = TCP_DB::get_run( $run_id );
			if ( $run && in_array( $run['status'], array( 'running', 'interrupted' ), true ) ) {
				TCP_DB::update_run( $run_id, array(
					'status'      => $status,
					'finished_at' => TCP_DB::now(),
					'updated_at'  => TCP_DB::now(),
				) );
			}
			self::schedule_lookup_refresh();
		}

		/** علامت‌گذاری و زمان‌بندی به‌روزرسانی جدول lookup در پس‌زمینه. */
		public static function schedule_lookup_refresh() {
			if ( ! TCP_Settings::wc_active() || ! function_exists( 'wc_update_product_lookup_tables' ) ) {
				return;
			}
			update_option( TCP_Settings::OPT_LOOKUP_PENDING, 1, false );
			if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( TCP_Settings::CRON_LOOKUP ) ) {
				wp_schedule_single_event( time() + 5, TCP_Settings::CRON_LOOKUP );
			}
		}

		/* -----------------------------------------------------------------
		 * بازگردانی: خواندن/اعمال یک فیلد روی یک شیء
		 * --------------------------------------------------------------- */

		public static function read_field_value( $object_id, $field ) {
			$object_id = absint( $object_id );
			if ( 'wholesale' === $field ) {
				return self::wholesale_price_raw( $object_id );
			}
			$p = wc_get_product( $object_id );
			if ( ! $p ) {
				return '';
			}
			return 'sale' === $field ? (string) $p->get_sale_price( 'edit' ) : (string) $p->get_regular_price( 'edit' );
		}

		public static function apply_field_value( $object_id, $field, $value ) {
			$object_id = absint( $object_id );
			$value     = (string) $value;
			if ( 'wholesale' === $field ) {
				return self::update_wholesale_meta( $object_id, $value );
			}
			$p = wc_get_product( $object_id );
			if ( ! $p ) {
				return false;
			}
			try {
				if ( 'sale' === $field ) {
					$p->set_sale_price( '' === $value ? '' : wc_format_decimal( $value ) );
					$p->set_date_on_sale_from( null );
					$p->set_date_on_sale_to( null );
				} else {
					$p->set_regular_price( '' === $value ? '' : wc_format_decimal( $value ) );
				}
				$p->save();
				return true;
			} catch ( Throwable $e ) {
				return false;
			}
		}

		/* -----------------------------------------------------------------
		 * نمونهٔ پیش‌نمایش (قبل → بعد) با همان فرمول اجرا
		 * --------------------------------------------------------------- */

		public static function sample_row( $object_id, $sample_info, $op, $value ) {
			$p = wc_get_product( absint( $object_id ) );
			$label = '—';
			if ( $p ) {
				$label = $p->get_name();
			}
			$type_map = array(
				'simple'    => 'ساده',
				'variable'  => 'متغیر (والد)',
				'variation' => 'وریشن',
				'grouped'   => 'گروهی',
				'external'  => 'خارجی',
			);
			$type = isset( $sample_info['type'] ) ? $sample_info['type'] : 'simple';
			$row  = array(
				'object_id' => absint( $object_id ),
				'parent_id' => isset( $sample_info['parent_id'] ) ? absint( $sample_info['parent_id'] ) : 0,
				'label'     => $label,
				'type'      => isset( $type_map[ $type ] ) ? $type_map[ $type ] : $type,
				'before'    => '',
				'after'     => '',
				'state'     => 'updated',
				'note'      => '',
			);
			if ( ! $p ) {
				$row['state'] = 'error';
				$row['note']  = 'محصول پیدا نشد.';
				return $row;
			}

			if ( self::is_wholesale_op( $op ) ) {
				$current = self::wholesale_price_raw( $p->get_id() );
				if ( '' === $current ) {
					$row['state'] = 'skip';
					$row['note']  = 'قیمت عمده ندارد.';
					return $row;
				}
				$row['before'] = $current;
				if ( 'wholesale_clear' === $op ) {
					$row['after'] = '';
					return $row;
				}
				$retail = $p->get_price( 'edit' );
				$res    = self::calc_wholesale( $op, $current, $retail, $value, $p->get_id() );
				if ( ! $res['ok'] ) {
					$row['state'] = 'error';
					$row['note']  = $res['msg'];
					$row['after'] = $row['before'];
					return $row;
				}
				$row['after'] = wc_format_decimal( $res['new'] );
				if ( $row['after'] === $row['before'] ) {
					$row['state'] = 'skip';
					$row['note']  = 'بدون تغییر.';
				}
				return $row;
			}

			if ( self::is_regular_op( $op ) ) {
				$cur = (string) $p->get_regular_price( 'edit' );
				$row['before'] = '' === $cur ? '' : wc_format_decimal( $cur );
				$res = self::calc_regular( $op, $cur, $value, $p->get_id() );
				if ( '' === $cur && 'regular_set' !== $op ) {
					$row['state'] = 'skip';
					$row['note']  = 'قیمت عادی ندارد.';
					return $row;
				}
				if ( ! $res['ok'] ) {
					$row['state'] = 'error';
					$row['note']  = $res['msg'];
					return $row;
				}
				$row['after'] = wc_format_decimal( $res['new'] );
				return $row;
			}

			// sale
			$regular_raw = (string) $p->get_regular_price( 'edit' );
			$sale_cur    = (string) $p->get_sale_price( 'edit' );
			$row['before'] = '' === $sale_cur ? '' : wc_format_decimal( $sale_cur );
			if ( 'sale_remove' === $op ) {
				if ( '' === $sale_cur ) {
					$row['state'] = 'skip';
					$row['note']  = 'فروش ویژه ندارد.';
					return $row;
				}
				$row['after'] = '';
				return $row;
			}
			if ( '' === $regular_raw ) {
				$row['state'] = 'skip';
				$row['note']  = 'قیمت عادی ندارد.';
				return $row;
			}
			$res = self::calc_sale( $op, $regular_raw, $value, $sale_cur, $p->get_id() );
			if ( ! $res['ok'] ) {
				$row['state'] = 'error';
				$row['note']  = $res['msg'];
				return $row;
			}
			$row['after'] = wc_format_decimal( $res['new'] );
			if ( $row['after'] === $row['before'] ) {
				$row['state'] = 'skip';
				$row['note']  = 'بدون تغییر.';
			}
			return $row;
		}
	}
}
