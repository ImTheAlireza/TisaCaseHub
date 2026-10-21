<?php
/**
 * مسیرهای AJAX افزونهٔ مدیریت انبوه موجودی:
 * جست‌وجوی محصول، بارگذاری متغیرها/دسته‌ها، اعمال موجودی، و حالت گروهی
 * (محدودهٔ محصولات ← ویژگی/مقدار مدل ← کارت‌ها و اعمال).
 *
 * @package TisaCase_Bulk_Stock_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TBSM_Ajax' ) ) {

	final class TBSM_Ajax {

		public static function init() {
			// هر اندپوینت از میان گارد() رد می‌شود: هیچ خطای PHP داخل افزونه
			// نباید به «صفحهٔ خطای کشندهٔ وردپرس» و پیام مبهم در مرورگر تبدیل
			// شود — پیام واقعی خطا به‌صورت JSON برمی‌گردد.
			foreach ( self::endpoints() as $action => $method ) {
				add_action(
					'wp_ajax_' . $action,
					static function () use ( $method ) {
						self::guard( $method );
					}
				);
			}
		}

		/**
		 * فهرست اندپوینت‌ها: نام اکشن → نام متد.
		 *
		 * @return array
		 */
		private static function endpoints() {
			return array(
				'tbsm_search_products'  => 'ajax_search_products',
				'tbsm_load_product'     => 'ajax_load_product',
				'tbsm_apply_stock'      => 'ajax_apply_stock',
				// حالت گروهی: محدودهٔ محصولات ← ویژگی/مقدار مدل ← اعمال.
				'tbsm_scope_options'    => 'ajax_scope_options',
				'tbsm_scope_stats'      => 'ajax_scope_stats',
				'tbsm_scope_attributes' => 'ajax_scope_attributes',
				'tbsm_scope_values'     => 'ajax_scope_values',
				'tbsm_find_scope'       => 'ajax_find_scope',
				'tbsm_apply_scope_stock' => 'ajax_apply_scope_stock',
				// توکن تازه برای صفحه‌هایی که مدتی باز مانده‌اند.
				'tbsm_nonce'            => 'ajax_nonce',
			);
		}

		/**
		 * گارد مرکزی: هر Throwable را به پاسخ JSON با علت واقعی تبدیل می‌کند
		 * (نوع خطا، متن، فایل و شمارهٔ خط) — به‌جای خطای کشندهٔ PHP.
		 *
		 * @param string $method نام متد اندپوینت.
		 * @return void
		 */
		private static function guard( $method ) {
			self::install_fatal_guard();

			try {
				call_user_func( array( __CLASS__, $method ) );
			} catch ( \Throwable $e ) {
				$where = basename( (string) $e->getFile() ) . ' خط ' . (int) $e->getLine();
				self::send_error(
					sprintf(
						/* translators: 1: error class, 2: message, 3: file and line */
						'خطای اجرای افزونه: %1$s — %2$s (%3$s)',
						get_class( $e ),
						$e->getMessage(),
						$where
					),
					'php_exception',
					array( 'type' => get_class( $e ), 'where' => $where )
				);
			}
		}

		/** @var bool آیا پاسخ JSON همین حالا فرستاده شده است؟ */
		private static $sent = false;

		/**
		 * نگهبان مشترک: دسترسی + توکن + حضور ووکامرس.
		 */
		private static function check_auth() {
			self::install_fatal_guard();

			if ( ! TBSM_Core::can() ) {
				self::send_error( 'دسترسی غیرمجاز است.', 'no_cap' );
			}
			if ( ! function_exists( 'wc_get_product' ) ) {
				self::send_error( 'ووکامرس نصب یا فعال نیست.', 'no_wc' );
			}

			// توکن را خودمان بررسی می‌کنیم تا منقضی‌شدنش به «پاسخ HTML آدم‌کش»
			// و پیام مبهم «بررسی محدوده ناموفق بود» تبدیل نشود.
			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, TBSM_Core::NONCE_ACTION ) ) {
				self::send_error(
					'توکن امنیتی صفحه منقضی شده بود؛ خودکار تازه شد. لطفاً دوباره دکمه را بزنید.',
					'bad_nonce',
					array( 'new_nonce' => wp_create_nonce( TBSM_Core::NONCE_ACTION ) )
				);
			}
		}

		/**
		 * فرستادن پاسخ موفق با مُهر نسخه (برای تشخیص ناهم‌خوانی کش/فایل‌ها).
		 *
		 * @param array $data دادهٔ پاسخ.
		 * @return void
		 */
		private static function send_success( $data ) {
			self::$sent = true;
			$data       = is_array( $data ) ? $data : array();
			$data['version'] = TBSM_VERSION;

			wp_send_json_success( $data );
		}

		/**
		 * فرستادن پاسخ خطا با «کد» و توضیح فنی — تا رابط کاربری بتواند به‌جای
		 * پیام مبهم، علت واقعی را نشان دهد.
		 *
		 * @param string $message پیام فارسی.
		 * @param string $code    کد خطا.
		 * @param array  $extra   دادهٔ اضافه.
		 * @return void
		 */
		private static function send_error( $message, $code = 'error', $extra = array() ) {
			self::$sent = true;

			$data = array(
				'message' => (string) $message,
				'code'    => (string) $code,
				'version' => TBSM_VERSION,
			);
			if ( is_array( $extra ) && ! empty( $extra ) ) {
				$data = array_merge( $data, $extra );
			}

			error_log( '[TBSM] ' . $code . ': ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			wp_send_json_error( $data );
		}

		/**
		 * نگهبان خطای کشندهٔ PHP: اگر در میانهٔ این درخواست خطای کشنده رخ دهد،
		 * به‌جای صفحهٔ HTML وردپرس («بررسی محدوده ناموفق بود»)، پاسخ JSON با متن
		 * دقیق خطا (فایل/خط/پیام) برگردانده می‌شود.
		 */
		private static function install_fatal_guard() {
			static $installed = false;
			if ( $installed ) {
				return;
			}
			$installed = true;

			register_shutdown_function( array( __CLASS__, 'fatal_guard' ) );
		}

		public static function fatal_guard() {
			$err = error_get_last();
			if ( ! is_array( $err ) ) {
				return;
			}

			$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
			if ( ! in_array( (int) $err['type'], $fatal_types, true ) ) {
				return;
			}
			if ( self::$sent ) {
				return;
			}

			self::$sent = true;

			$detail = sprintf(
				'%s — %s خط %d',
				(string) $err['message'],
				basename( (string) $err['file'] ),
				(int) $err['line']
			);

			error_log( '[TBSM] php_fatal: ' . $detail ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			if ( ! headers_sent() ) {
				status_header( 500 );
				nocache_headers();
				header( 'Content-Type: application/json; charset=utf-8' );
			}

			echo wp_json_encode(
				array(
					'success' => false,
					'data'    => array(
						'message' => 'خطای PHP در افزونه: ' . $detail,
						'code'    => 'php_fatal',
						'version' => TBSM_VERSION,
					),
				)
			);
		}

		/**
		 * آماده‌سازی اجرای ایجکس سنگین (اعمال انبوه).
		 */
		private static function prepare_runtime() {
			@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_ignore_user_abort
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		/**
		 * جست‌وجوی زندهٔ محصول بر اساس نام، SKU یا شناسه.
		 */
		public static function ajax_search_products() {
			self::check_auth();

			$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $term, 'UTF-8' ) : strlen( $term );

			if ( $len < 2 ) {
				self::send_success( array( 'results' => array() ) );
			}

			$found = array();
			$order = array();

			// ۱) اگر عدد خالص بود، شناسهٔ مستقیم اولویت دارد.
			if ( ctype_digit( $term ) ) {
				$direct = absint( $term );
				if ( 'product' === get_post_type( $direct ) ) {
					$found[ $direct ] = true;
					$order[]          = $direct;
				}
			}

			// ۲) جست‌وجو در عنوان.
			$query_title = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'draft', 'pending' ),
					's'              => $term,
					'posts_per_page' => 15,
					'fields'         => 'ids',
				)
			);
			foreach ( (array) $query_title->posts as $id ) {
				if ( ! isset( $found[ $id ] ) ) {
					$found[ $id ] = true;
					$order[]      = $id;
				}
			}

			// ۳) جست‌وجو در SKU (LIKE).
			$query_sku = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'draft', 'pending' ),
					'posts_per_page' => 10,
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => '_sku',
							'value'   => $term,
							'compare' => 'LIKE',
						),
					),
				)
			);
			foreach ( (array) $query_sku->posts as $id ) {
				if ( ! isset( $found[ $id ] ) ) {
					$found[ $id ] = true;
					$order[]      = $id;
				}
			}

			$order = array_slice( $order, 0, TBSM_Core::MAX_SEARCH_HITS );

			$results = array();
			foreach ( $order as $id ) {
				$product = wc_get_product( (int) $id );
				if ( ! $product || ! $product->exists() ) {
					continue;
				}
				// خودِ متغیرها را نشان نده؛ فقط محصول والد.
				if ( 'variation' === $product->get_type() ) {
					continue;
				}
				$results[] = self::product_brief( $product );
			}

			self::send_success( array( 'results' => $results ) );
		}

		/**
		 * خلاصهٔ یک محصول برای لیست جست‌وجو.
		 *
		 * @param \WC_Product $product شیء محصول.
		 * @return array
		 */
		private static function product_brief( $product ) {
			$children = ( 'variable' === $product->get_type() ) ? $product->get_children() : array();
			$image_id = $product->get_image_id();

			return array(
				'id'         => (int) $product->get_id(),
				'name'       => get_the_title( $product->get_id() ),
				'sku'        => (string) $product->get_sku(),
				'type'       => (string) $product->get_type(),
				'status'     => (string) $product->get_status(),
				'variations' => count( (array) $children ),
				'image'      => $image_id ? (string) wp_get_attachment_image_url( (int) $image_id, 'thumbnail' ) : '',
			);
		}

		/**
		 * بارگذاری کامل یک محصول: متغیرها + دسته‌های ویژگی (برای سلکت).
		 */
		public static function ajax_load_product() {
			self::check_auth();

			$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$product    = wc_get_product( $product_id );

			if ( ! $product || ! $product->exists() ) {
				self::send_error( 'محصول انتخابی پیدا نشد.', 'bad_product' );
			}

			$is_variable = 'variable' === $product->get_type();
			$children    = $is_variable ? (array) $product->get_children() : array();

			$variations = array();
			$counts     = array(); // tax => slug => تعداد

			foreach ( $children as $child_id ) {
				$variation = wc_get_product( (int) $child_id );
				if ( ! $variation || ! $variation->exists() ) {
					continue;
				}

				$attr_map = array();
				$labels   = array();

				foreach ( (array) $variation->get_attributes() as $tax => $slug ) {
					if ( null === $slug || '' === (string) $slug ) {
						continue;
					}
					$tax  = (string) $tax;
					$slug = (string) $slug;

					$attr_map[ $tax ] = $slug;

					if ( ! isset( $counts[ $tax ][ $slug ] ) ) {
						$counts[ $tax ][ $slug ] = 0;
					}
					$counts[ $tax ][ $slug ]++;

					$label = $variation->get_attribute( $tax );
					$clean = TBSM_Stock::clean_label( TBSM_Stock::maybe_decode_label( ( '' !== (string) $label ) ? (string) $label : $slug ) );
					if ( '' !== $clean && ! in_array( $clean, $labels, true ) ) {
						$labels[] = $clean;
					}
				}

					$image_id = $variation->get_image_id();
					$managed  = (bool) $variation->get_manage_stock();

					$variations[] = array(
						'id'           => (int) $variation->get_id(),
						'name'         => empty( $labels ) ? ( 'متغیر #' . (int) $variation->get_id() ) : implode( ' · ', $labels ),
						'sku'          => (string) $variation->get_sku(),
						'stock'        => (int) $variation->get_stock_quantity(),
						'status'       => (string) $variation->get_stock_status(),
						'manage_stock' => $managed,
						'mode'         => $managed ? 'qty' : ( 'instock' === (string) $variation->get_stock_status() ? 'in_stock' : 'out_of_stock' ),
						'image'        => $image_id ? (string) wp_get_attachment_image_url( (int) $image_id, 'thumbnail' ) : '',
						'cat'          => implode( '|', array_map(
						static function( $tax ) use ( $attr_map ) {
							return $tax . ':' . $attr_map[ $tax ];
						},
						array_keys( $attr_map )
					) ),
				);
			}

			// دسته‌ها برای سلکت: گروه ویژگی ← مقادیر آن با برچسب و تعداد.
			$categories = array();
			foreach ( $counts as $tax => $slugs ) {
				$values = array();
				foreach ( $slugs as $slug => $count ) {
					$term  = get_term_by( 'slug', $slug, $tax );
					$label = ( $term instanceof WP_Term ) ? $term->name : $slug;
					$values[] = array(
						'label' => (string) $label,
						'slug'  => (string) $slug,
						'count' => (int) $count,
					);
				}
				// برخی افزونه‌ها taxonomy ویژگی‌های فارسی را با نام percent-encoded می‌سازند
				// (مثلاً pa_d9%85d8%af%d9%84 به‌جای «مدل») — برچسب را بازیابی می‌کنیم.
				$label = TBSM_Stock::maybe_decode_label( (string) wc_attribute_label( $tax ) );
				$label = TBSM_Stock::clean_label( $label );
				$categories[ $tax ] = array(
					'label'  => (string) $label,
					'values' => $values,
				);
			}

		// محصول ساده: خودش یک «کارت» است تا موجودی‌اش هم از همین صفحه قابل ویرایش باشد.
		if ( ! $is_variable ) {
			$image_id = $product->get_image_id();
			$managed  = (bool) $product->get_manage_stock();
			$variations = array(
				array(
					'id'           => (int) $product->get_id(),
					'name'         => get_the_title( $product->get_id() ),
					'sku'          => (string) $product->get_sku(),
					'stock'        => (int) $product->get_stock_quantity(),
					'status'       => (string) $product->get_stock_status(),
					'manage_stock' => $managed,
					'mode'         => $managed ? 'qty' : ( 'instock' === (string) $product->get_stock_status() ? 'in_stock' : 'out_of_stock' ),
					'image'        => $image_id ? (string) wp_get_attachment_image_url( (int) $image_id, 'thumbnail' ) : '',
					'cat'          => '',
				),
			);
		}

			self::send_success(
				array(
					'product'    => self::product_brief( $product ),
					'variations' => $variations,
					'categories' => $categories,
				)
			);
		}

		/**
		 * اعمال موجودی روی متغیرهای انتخاب‌شده + همگام‌سازی والد.
		 */
		public static function ajax_apply_stock() {
			self::check_auth();
			self::prepare_runtime();

			$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$items      = ( isset( $_POST['items'] ) && is_array( $_POST['items'] ) )
				? wp_unslash( $_POST['items'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				: array();

			// کلید‌ها باید ایدی باشند؛ مقادیر بعداً در موتور اعتبارسنجی می‌شوند.
			$clean = array();
			foreach ( $items as $key => $value ) {
				if ( ctype_digit( (string) $key ) ) {
					$clean[ absint( $key ) ] = $value;
				}
			}

			try {
				$result = TBSM_Stock::apply_stock( $product_id, $clean );
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				$result = array(
					'ok'    => false,
					'error' => 'خطای پیش‌بینی‌نشده هنگام اعمال: ' . $e->getMessage(),
				);
			}

			// دستهٔ «پردازش‌شده» (دارای report) همیشه پاسخ موفق است؛ حتی اگر
			// همهٔ آیتم‌هایش رد شده باشند — دلیل هرکدام در rejections می‌آید
			// و کلاینت نباید آن را مثل خطای سرور نصف‌ونصف دوباره بفرستد.
			if ( ! empty( $result['ok'] ) || isset( $result['report'] ) ) {
				self::send_success( $result );
			}
			self::send_error( isset( $result['error'] ) ? $result['error'] : 'اعمال موجودی ناموفق بود.', 'apply_failed', $result );
		}

		/* ----------------------------------------------------------
		 * حالت گروهی — «محدودهٔ محصولات ← ویژگی/مقدار مدل»
		 * ---------------------------------------------------------- */

		/**
		 * پاک‌سازی نام ویژگی/مقدار مدل بدون آسیب به کاراکترهای percent-encoded و فاصله‌ها.
		 *
		 * `sanitize_text_field()` توالی‌های %xx را حذف می‌کند و همین باعث می‌شد
		 * ویژگی‌های کدشده (مثل d9%85d8%af%d9%84 = «مدل») با هیچ متغیری تطبیق نکنند؛
		 * فاصله‌ها هم بخشی از مقدار واقعی‌اند (مثل «iPhone 15 Pro Max»). این مقادیر
		 * هرگز مستقیم در SQL نمی‌نشینند (پارامترِ $wpdb->prepare) و در خروجی هم
		 * escape می‌شوند؛ پس فقط کاراکترهای کنترلی حذف و طول محدود می‌شود.
		 *
		 * @param mixed $raw مقدار خام درخواست.
		 * @return string
		 */
		private static function model_raw_text( $raw ) {
			$text = (string) wp_unslash( (string) $raw );
			$text = (string) preg_replace( '/[\x00-\x1F\x7F]+/', '', $text );
			return substr( $text, 0, 190 );
		}

		/**
		 * خواندن یک فهرست از درخواست (کلید ویژگی یا مقدار مدل) با پاک‌سازی مخصوص
		 * و سقف تعداد. عناصر خالی/تکراری حذف می‌شوند.
		 *
		 * @param string $key   نام پارامتر.
		 * @param int    $limit سقف تعداد.
		 * @return array
		 */
		private static function model_raw_list( $key, $limit ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				return array();
			}

			$raw  = (array) wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$out  = array();
			foreach ( $raw as $item ) {
				if ( is_array( $item ) ) {
					continue;
				}
				$text = self::model_raw_text( $item );
				if ( '' !== $text && ! in_array( $text, $out, true ) ) {
					$out[] = $text;
				}
				if ( count( $out ) >= (int) $limit ) {
					break;
				}
			}

			return $out;
		}

		/**
		 * خواندن پارامترهای مشترک «محدودهٔ محصولات» از درخواست.
		 *
		 * @return array محدودهٔ خام (بعداً با TBSM_Stock::normalize_scope نرمال می‌شود).
		 */
		private static function scope_params() {
			$type = isset( $_POST['scope_type'] ) ? sanitize_key( wp_unslash( $_POST['scope_type'] ) ) : 'all';
			$ids  = ( isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) )
				? (array) wp_unslash( $_POST['product_ids'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				: array();
			$cat  = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
			$sku  = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '';
			$mode = isset( $_POST['sku_mode'] ) ? sanitize_key( wp_unslash( $_POST['sku_mode'] ) ) : 'prefix';

			return array(
				'type'        => $type,
				'product_ids' => array_map( 'absint', $ids ),
				'category_id' => $cat,
				'sku'         => $sku,
				'sku_mode'    => $mode,
			);
		}

		/**
		 * داده‌های توصیفی محدوده برای پاسخ‌ها (برچسب + شمارش‌ها).
		 *
		 * @param array $scope محدودهٔ نرمال‌شده.
		 * @return array
		 */
		private static function scope_payload( $scope ) {
			$summary = TBSM_Stock::scope_summary( $scope );

			return array(
				'type'        => (string) $scope['type'],
				'label'       => TBSM_Stock::scope_label( $scope ),
				'product_ids' => array_map( 'absint', (array) $scope['product_ids'] ),
				'category_id' => (int) $scope['category_id'],
				'sku'         => (string) $scope['sku'],
				'sku_mode'    => (string) $scope['sku_mode'],
				'products'    => (int) $summary['products'],
				'variations'  => (int) $summary['variations'],
			);
		}

		/**
		 * گزینه‌های گام «محدوده»: دسته‌بندی‌های محصول.
		 */
		public static function ajax_scope_options() {
			self::check_auth();
			self::prepare_runtime();

			self::send_success(
				array(
					'categories' => TBSM_Stock::list_product_categories(),
					'limits'     => array(
						'products' => TBSM_Core::MAX_SCOPE_PRODUCTS,
						'rows'     => TBSM_Core::MAX_MODEL_ROWS,
					),
				)
			);
		}

		/**
		 * ویژگی‌های موجود در محدودهٔ انتخاب‌شده (گام ۲).
		 */
		public static function ajax_scope_attributes() {
			self::check_auth();
			self::prepare_runtime();

			$scope  = TBSM_Stock::normalize_scope( self::scope_params() );
			$error  = TBSM_Stock::scope_error( $scope );
			if ( '' !== $error ) {
				self::send_error( (string) $error, 'bad_scope' );
			}

			$attributes = TBSM_Stock::list_scope_attributes( $scope );

			self::send_success(
				array(
					'scope'      => self::scope_payload( $scope ),
					'attributes' => $attributes,
				)
			);
		}

		/**
		 * شمارش سبک یک محدوده (محصول/متغیر) — برای نشان‌دادن «همهٔ محصولات»
		 * پیش از محاسبهٔ سنگین ویژگی‌ها.
		 */
		public static function ajax_scope_stats() {
			self::check_auth();
			self::prepare_runtime();

			$scope = TBSM_Stock::normalize_scope( self::scope_params() );
			$error = TBSM_Stock::scope_error( $scope );
			if ( '' !== $error ) {
				self::send_error( (string) $error, 'bad_scope' );
			}

			self::send_success( array( 'scope' => self::scope_payload( $scope ) ) );
		}

		/**
		 * مقدارهای یک ویژگی در محدودهٔ انتخاب‌شده.
		 */
		public static function ajax_scope_values() {
			self::check_auth();
			self::prepare_runtime();

			$keys  = self::model_raw_list( 'attr_keys', TBSM_Core::MAX_MODEL_ATTRS );
			$scope = TBSM_Stock::normalize_scope( self::scope_params() );

			if ( empty( $keys ) ) {
				self::send_error( 'ابتدا ویژگی مدل را انتخاب کنید.', 'no_attr' );
			}

			$pack = TBSM_Stock::list_scope_values( $scope, $keys );

			self::send_success(
				array(
					'attr_keys' => $keys,
					'values'    => isset( $pack['values'] ) ? $pack['values'] : array(),
					'groups'    => isset( $pack['groups'] ) ? $pack['groups'] : array(),
					'truncated' => ! empty( $pack['truncated'] ),
				)
			);
		}

		/**
		 * فهرست متغیرهای یک مدل در محدودهٔ انتخاب‌شده.
		 */
		public static function ajax_find_scope() {
			self::check_auth();
			self::prepare_runtime();

			$scope  = TBSM_Stock::normalize_scope( self::scope_params() );
			$keys   = self::model_raw_list( 'attr_keys', TBSM_Core::MAX_MODEL_ATTRS );
			$values = self::model_raw_list( 'term_values', TBSM_Core::MAX_MODEL_VALUES );
			$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;

			if ( empty( $keys ) || empty( $values ) ) {
				self::send_error( 'ابتدا ویژگی و مقدار مدل را انتخاب کنید.', 'no_model' );
			}

			$found = TBSM_Stock::find_scope_rows( $scope, $keys, $values, $offset );

			if ( ! empty( $found['error'] ) ) {
				self::send_error( (string) $found['error'], 'scope_rows_error' );
			}

			self::send_success(
				array(
					'rows'      => $found['rows'],
					'total'     => count( $found['rows'] ),
					'products'  => (int) $found['products'],
					'truncated' => ! empty( $found['truncated'] ),
					'limit'     => TBSM_Core::MAX_MODEL_ROWS,
					'offset'    => $offset,
					'scope'     => self::scope_payload( $scope ),
					'attr_keys' => $keys,
					'values'    => $values,
				)
			);
		}

		/**
		 * اعمال موجودی گروهی روی متغیرهای همان مدل و همان محدودهٔ محصولات.
		 */
		public static function ajax_apply_scope_stock() {
			self::check_auth();
			self::prepare_runtime();

			$scope  = self::scope_params();
			$keys   = self::model_raw_list( 'attr_keys', TBSM_Core::MAX_MODEL_ATTRS );
			$values = self::model_raw_list( 'term_values', TBSM_Core::MAX_MODEL_VALUES );
			$items  = ( isset( $_POST['items'] ) && is_array( $_POST['items'] ) )
				? wp_unslash( $_POST['items'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				: array();

			// کلید‌ها باید ایدی باشند؛ مقادیر بعداً در موتور اعتبارسنجی می‌شوند.
			$clean = array();
			foreach ( $items as $key => $spec ) {
				if ( ctype_digit( (string) $key ) ) {
					$clean[ absint( $key ) ] = $spec;
				}
			}

			try {
				$result = TBSM_Stock::apply_scope_stock( $clean, $keys, $values, $scope );
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				$result = array(
					'ok'    => false,
					'error' => 'خطای پیش‌بینی‌نشده هنگام اعمال گروهی: ' . $e->getMessage(),
				);
			}

			// مثل اعمال تکی: دستهٔ پردازش‌شده پاسخ موفق است و دلیل رد شدن
			// تک‌تک آیتم‌ها در rejections می‌رسد، نه در قالب خطای کلی درخواست.
			if ( ! empty( $result['ok'] ) || isset( $result['report'] ) ) {
				self::send_success( $result );
			}
			self::send_error( isset( $result['error'] ) ? $result['error'] : 'اعمال گروهی ناموفق بود.', 'apply_failed', $result );
		}

		/**
		 * توکن تازه برای صفحه‌هایی که مدتی باز مانده‌اند (12/24 ساعت).
		 */
		public static function ajax_nonce() {
			if ( ! TBSM_Core::can() ) {
				self::send_error( 'دسترسی غیرمجاز است.', 'no_cap' );
			}

			self::send_success( array( 'nonce' => wp_create_nonce( TBSM_Core::NONCE_ACTION ) ) );
		}

	}
}
