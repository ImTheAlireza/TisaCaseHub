<?php
/**
 * منطق افزونه: فهرست عملیات، اعتبارسنجی ورودی، محاسبهٔ قیمت (منبع واحد برای پیش‌نمایش و اجرا)،
 * اجرای دسته‌ای، بازگردانی و توکن امنیتی.
 *
 * @package TisaCase_Pricing
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCP_Ops' ) ) {

	final class TCP_Ops {

		/** نوع‌های ورودی هر عملیات. */
		const KIND_PERCENT = 'percent';
		const KIND_AMOUNT  = 'amount';
		const KIND_SET     = 'set';
		const KIND_NONE    = 'none';

		/** حالت رند جاری برای این درخواست/صفحه: none | round | jitter (از args می‌آید). */
		public static $round_mode = 'none';

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
		 * بودجهٔ زمانی (ثانیه) هر درخواست پردازش؛ عمداً زیر مرز max_execution_time
		 * می‌ماند تا پاسخ همیشه به کلاینت برسد و حلقهٔ اجرا با «قطع ارتباط با سرور» نشکند.
		 * اگر محدودیتی تعریف نشده باشد (0) پیش‌فرض ۲۰ ثانیه است.
		 */
		public static function time_budget() {
			$t = (int) ini_get( 'max_execution_time' );
			if ( $t <= 0 ) {
				return 20;
			}
			return max( 5, min( 20, $t - 5 ) );
		}

		/**
		 * آماده‌سازی حافظه/زمان اجرای درخواست‌های سنگین (مشابه prepare_runtime
		 * در افزونهٔ تغییر متغیرها): حافظهٔ ادمین، حذف سقف زمان و نادیده‌گرفتن
		 * قطع اتصال کلاینت تا پایانِ همین محصول مادر تعهدات ثبت شوند.
		 */
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

		/**
		 * اعمال حالت رند روی نتیجهٔ محاسبه.
		 * - round: به پایین روی رقم ۸.
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
			$rounded = TCP_Round::down( (float) $new );
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
			if ( ! in_array( $target, array( 'category', 'products' ), true ) ) {
				return new WP_Error( 'target', 'نوع انتخاب محصولات معتبر نیست.' );
			}

			$args = array(
				'operation'        => $op,
				'target_type'      => $target,
				'category_ids'     => self::ids( isset( $post['category_ids'] ) ? $post['category_ids'] : '' ),
				'product_ids'      => self::ids( isset( $post['product_ids'] ) ? $post['product_ids'] : '' ),
				'include_children' => ! empty( $post['include_children'] ),
				'round_mode'       => isset( $post['round_mode'] ) && in_array( sanitize_key( wp_unslash( $post['round_mode'] ) ), array( 'none', 'round', 'jitter' ), true ) ? sanitize_key( wp_unslash( $post['round_mode'] ) ) : 'none',
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
			} else {
				if ( empty( $args['product_ids'] ) ) {
					return new WP_Error( 'empty', 'هیچ محصولی انتخاب نشده است.' );
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
			return array_values( array_unique( array_filter( array_map( 'absint', $parts ) ) ) );
		}

		/**
		 * توکن/امضای آرگومان‌ها. از پنجرهٔ زمانی حذف شد تا اجراهای طولانی قطع نشوند.
		 */
		private static function canonical( $args, $with_user ) {
			$cats = $args['category_ids'];
			$prods = $args['product_ids'];
			sort( $cats, SORT_NUMERIC );
			sort( $prods, SORT_NUMERIC );
			$filters = $args['filters'];
			$types   = isset( $filters['types'] ) ? $filters['types'] : array();
			sort( $types );
			$statuses = isset( $filters['statuses'] ) ? $filters['statuses'] : array();
			sort( $statuses );

			$num = function ( $v ) {
				return ( null === $v || '' === $v || false === $v ) ? '' : number_format( (float) $v, 6, '.', '' );
			};

			return implode( '|', array(
				$with_user ? (string) get_current_user_id() : '',
				(string) $args['target_type'],
				implode( ',', $cats ),
				implode( ',', $prods ),
				! empty( $args['include_children'] ) ? '1' : '0',
				isset( $args['round_mode'] ) ? (string) $args['round_mode'] : 'none',
				(string) $args['operation'],
				$num( $args['value'] ),
				! empty( $filters['only_sale'] ) ? '1' : '0',
				! empty( $filters['only_wholesale'] ) ? '1' : '0',
				$num( $filters['price_min'] ),
				$num( $filters['price_max'] ),
				implode( ',', $types ),
				implode( ',', $statuses ),
			) );
		}

		public static function args_hash( $args ) {
			return hash( 'sha256', self::canonical( $args, false ) );
		}

		public static function make_token( $args ) {
			return hash_hmac( 'sha256', self::canonical( $args, true ), wp_salt( 'nonce' ) );
		}

		public static function verify_token( $args, $token ) {
			$token = (string) $token;
			if ( '' === $token ) {
				return false;
			}
			$expected = self::make_token( $args );
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
		private static function change_regular_sale_object( $product, $op, $value ) {
			$id      = $product->get_id();
			$object_type = self::is_sale_op( $op ) ? 'sale' : 'regular';
			$before   = '';
			$after    = '';
			$entry    = null;

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
				$product->set_sale_price( $res['new'] );
				$product->set_date_on_sale_from( null );
				$product->set_date_on_sale_to( null );
				$product->save();
				$entry = array( 'object_type' => 'sale', 'parent_id' => self::parent_of( $product ), 'object_id' => $id, 'before' => $before, 'after' => $after );
				return array( 'status' => 'updated', 'message' => '#' . $id . ' فروش ویژه تغییر کرد.', 'entry' => $entry );

			} catch ( Throwable $e ) {
				return array( 'status' => 'error', 'message' => '#' . $id . ' خطا: ' . $e->getMessage(), 'entry' => null );
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

		private static function change_wholesale_object( $product, $op, $value ) {
			$id      = $product->get_id();
			$current = self::wholesale_price_raw( $id );

			if ( '' === $current ) {
				return array( 'status' => 'skip', 'message' => '#' . $id . ' قیمت عمده ندارد.', 'entry' => null );
			}
			try {
				if ( 'wholesale_clear' === $op ) {
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
				$changed = self::update_wholesale_meta( $id, $res['new'] );
				if ( ! $changed ) {
					return array( 'status' => 'skip', 'message' => '#' . $id . ' قیمت عمده تغییری نکرد.', 'entry' => null );
				}
				$entry = array( 'object_type' => 'wholesale', 'parent_id' => self::parent_of( $product ), 'object_id' => $id, 'before' => $current, 'after' => wc_format_decimal( $res['new'] ) );
				return array( 'status' => 'updated', 'message' => '#' . $id . ' قیمت عمده به ' . $res['new'] . ' تغییر کرد.', 'entry' => $entry );
			} catch ( Throwable $e ) {
				return array( 'status' => 'error', 'message' => '#' . $id . ' خطای قیمت عمده: ' . $e->getMessage(), 'entry' => null );
			}
		}

		/**
		 * اجرای یک محصول مادر (و وریشن‌هایش) و برگرداندن آمار + رکوردهای لاگ.
		 *
		 * @return array{updated:int,skipped:int,errors:array,entries:array}
		 */
		public static function process_parent( $product_id, $op, $value ) {
			$r = array( 'updated' => 0, 'skipped' => 0, 'errors' => array(), 'entries' => array() );

			if ( self::is_wholesale_op( $op ) ) {
				return self::process_wholesale_parent( $product_id, $op, $value, $r );
			}

			$p = wc_get_product( $product_id );
			if ( ! $p ) {
				$r['errors'][] = '#' . $product_id . ' محصول پیدا نشد.';
				return $r;
			}

			if ( $p->is_type( 'variable' ) ) {
				$children = $p->get_children();
				if ( empty( $children ) ) {
					$r['skipped']++;
					return $r; // متغیر بدون وریشن: بی‌اثر (skip)، نه خطا.
				}
				foreach ( $children as $vid ) {
					$v = wc_get_product( $vid );
					if ( ! $v ) {
						$r['skipped']++;
						continue;
					}
					$x = self::change_regular_sale_object( $v, $op, $value );
					self::tally( $r, $x );
				}
				if ( class_exists( 'WC_Product_Variable' ) ) {
					WC_Product_Variable::sync( $product_id );
				}
			} elseif ( $p->is_type( 'grouped' ) ) {
				$r['skipped']++;
				return $r; // محصول گروهی: پشتیبانی نمی‌شود → skip.
			} else {
				$x = self::change_regular_sale_object( $p, $op, $value );
				self::tally( $r, $x );
			}

			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $product_id );
			}
			clean_post_cache( $product_id );
			return $r;
		}

		private static function process_wholesale_parent( $product_id, $op, $value, $r ) {
			$p = wc_get_product( $product_id );
			if ( ! $p ) {
				$r['errors'][] = '#' . $product_id . ' محصول پیدا نشد.';
				return $r;
			}

			if ( $p->is_type( 'variable' ) ) {
				$children = $p->get_children();
				$wholesale_children = array();
				foreach ( $children as $vid ) {
					if ( '' !== self::wholesale_price_raw( $vid ) ) {
						$wholesale_children[] = absint( $vid );
					}
				}
				if ( empty( $wholesale_children ) ) {
					$r['skipped']++;
					return $r;
				}
				update_meta_cache( 'post', $wholesale_children );
				foreach ( $wholesale_children as $vid ) {
					$v = wc_get_product( $vid );
					if ( ! $v ) {
						$r['skipped']++;
						continue;
					}
					$x = self::change_wholesale_object( $v, $op, $value );
					self::tally( $r, $x );
				}
			} elseif ( $p->is_type( 'grouped' ) ) {
				$r['skipped']++;
				return $r;
			} else {
				if ( '' === self::wholesale_price_raw( $product_id ) ) {
					$r['skipped']++;
					return $r;
				}
				$x = self::change_wholesale_object( $p, $op, $value );
				self::tally( $r, $x );
			}

			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $product_id );
			}
			clean_post_cache( $product_id );
			return $r;
		}

		private static function tally( &$r, $x ) {
			if ( 'updated' === $x['status'] ) {
				$r['updated']++;
				if ( ! empty( $x['entry'] ) ) {
					$r['entries'][] = $x['entry'];
				}
			} elseif ( 'error' === $x['status'] ) {
				$r['errors'][] = $x['message'];
			} else {
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
			self::set_round_mode( isset( $args['round_mode'] ) ? $args['round_mode'] : 'none' );
			$settings   = TCP_Settings::get_settings();
			// دسته از زمان شروعِ اجرا ذخیره شده تا با تغییر تنظیمات وسط اجرا جابه‌جا نشود.
			$batch_size = isset( $args['batch'] ) ? absint( $args['batch'] ) : absint( $settings['batch_size'] );
			$batch_size = max( 1, min( 100, $batch_size ) );

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
		 * در این حالت `page` سطر اجرا «تعداد والد پردازش‌شده» (cursor) است و بعد از
		 * هر محصول مادر، cursor + آمار + لاگ در دیتابیس ثبت می‌شود؛ بنابراین بعد از
		 * هر قطع ارتباط یا تایم‌اوت، ادامه دقیقاً از همان‌جا انجام می‌شود — نه هیچ
		 * محصولی جا می‌ماند، نه درصد دوباره روی محصول قبلی اعمال می‌شود.
		 * فهرست هم ثابت است: فیلترهای قیمت/فروش که خودِ اجرا عوض‌شان می‌کند،
		 * دیگر باعث کوچک‌شدن مجموعه و «تکمیل زودهنگام با نصف محصولات» نمی‌شوند.
		 */
		private static function run_frozen_page( $run, $args, $parents, $batch_size ) {
			$run_id = (int) $run['id'];
			$total  = count( $parents );
			$cursor = min( max( 0, (int) $run['page'] ), $total );

			if ( ! $total ) {
				return array( 'ok' => true, 'msg' => '', 'data' => array( 'done' => true, 'progress' => 100, 'page' => 0, 'pages' => 0, 'parents' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() ) );
			}
			if ( $cursor >= $total ) {
				return array( 'ok' => true, 'msg' => '', 'data' => array( 'done' => true, 'progress' => 100, 'page' => $cursor, 'pages' => $total, 'parents' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() ) );
			}

			self::runtime_boost();
			$budget  = self::time_budget();
			$started = microtime( true );

			$count_updated = (int) $run['count_updated'];
			$count_skipped = (int) $run['count_skipped'];
			$count_errors  = (int) $run['count_errors'];
			$updated = $skipped = $processed = 0;
			$errors  = array();

			while ( $cursor < $total && $processed < $batch_size ) {
				$x = self::process_parent( absint( $parents[ $cursor ] ), $args['operation'], isset( $args['value'] ) ? $args['value'] : null );

				$updated       += $x['updated'];
				$skipped       += $x['skipped'];
				$count_updated += $x['updated'];
				$count_skipped += $x['skipped'];
				foreach ( $x['errors'] as $m ) {
					$errors[] = wp_strip_all_tags( $m );
				}
				$count_errors += count( $x['errors'] );
				$cursor++;
				$processed++;

				// ثبت لحظه‌ای بعد از هر محصول مادر: لاگ تغییرات + cursor + آمار.
				TCP_DB::insert_log( $run_id, $x['entries'] );
				TCP_DB::update_run(
					$run_id,
					array(
						'page'          => $cursor,
						'count_updated' => $count_updated,
						'count_skipped' => $count_skipped,
						'count_errors'  => $count_errors,
						'updated_at'    => TCP_DB::now(),
					)
				);

				// بودجه تمام شد؟ درخواست بعدی از همین‌جا ادامه می‌دهد.
				if ( ( microtime( true ) - $started ) >= $budget && $cursor < $total ) {
					break;
				}
			}

			$done     = $cursor >= $total;
			$progress = $done ? 100 : min( 99, round( ( $cursor / $total ) * 100, 1 ) );

			return array(
				'ok'   => true,
				'msg'  => '',
				'data' => array(
					'done'     => $done,
					'progress' => $progress,
					'page'     => $cursor,
					'pages'    => $total,
					'parents'  => $processed,
					'updated'  => $updated,
					'skipped'  => $skipped,
					'errors'   => array_slice( $errors, 0, 100 ),
				),
			);
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
