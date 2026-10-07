<?php
/**
 * مدیریت درخواست‌های ناهمگام (AJAX): جستجوی محصولات، پیش‌نمایش، پردازش دسته‌ای،
 * نوسازی کش و بازگردانی خودکار (Rollback).
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBVM_Ajax' ) ) {

	final class TCBVM_Ajax {

		/**
		 * وضعیت ارسال پاسخ JSON (برای محافظ خطای مهلک).
		 *
		 * @var bool
		 */
		private static $responded = false;

		/**
		 * بافر خروجی مستقل.
		 *
		 * @var bool
		 */
		private static $own_buffer = false;

		public static function init() {
			add_action( 'wp_ajax_tcbvm_search_products', array( __CLASS__, 'ajax_search_products' ) );
			add_action( 'wp_ajax_tcbvm_products_summary_page', array( __CLASS__, 'ajax_products_summary_page' ) );
			add_action( 'wp_ajax_tcbvm_search_single_products', array( __CLASS__, 'ajax_search_single_products' ) );
			add_action( 'wp_ajax_tcbvm_get_attributes', array( __CLASS__, 'ajax_get_attributes' ) );
			add_action( 'wp_ajax_tcbvm_preview', array( __CLASS__, 'ajax_preview' ) );
			add_action( 'wp_ajax_tcbvm_preview_page', array( __CLASS__, 'ajax_preview_page' ) );
			add_action( 'wp_ajax_tcbvm_start_run', array( __CLASS__, 'ajax_start_run' ) );
			add_action( 'wp_ajax_tcbvm_execute_batch', array( __CLASS__, 'ajax_execute_batch' ) );
			add_action( 'wp_ajax_tcbvm_finish_run', array( __CLASS__, 'ajax_finish_run' ) );
			add_action( 'wp_ajax_tcbvm_get_run_details', array( __CLASS__, 'ajax_get_run_details' ) );
			add_action( 'wp_ajax_tcbvm_rollback', array( __CLASS__, 'ajax_rollback' ) );
			add_action( 'wp_ajax_tcbvm_save_preset', array( __CLASS__, 'ajax_save_preset' ) );
			add_action( 'wp_ajax_tcbvm_delete_preset', array( __CLASS__, 'ajax_delete_preset' ) );
			add_action( 'wp_ajax_tcbvm_flush_cache', array( __CLASS__, 'ajax_flush_cache' ) );
			add_action( 'wp_ajax_tcbvm_purge_attr_search', array( __CLASS__, 'ajax_purge_attr_search' ) );
			add_action( 'wp_ajax_tcbvm_purge_attr_start', array( __CLASS__, 'ajax_purge_attr_start' ) );
			add_action( 'wp_ajax_tcbvm_purge_attr_batch', array( __CLASS__, 'ajax_purge_attr_batch' ) );
			add_action( 'wp_ajax_tcbvm_purge_attr_global', array( __CLASS__, 'ajax_purge_attr_global' ) );
		}

		private static function check_auth() {
			if ( ! TCBVM_Core::can() ) {
				wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز است.' ), 403 );
			}
			$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, TCBVM_Core::NONCE_ACTION ) ) {
				wp_send_json_error( array( 'message' => 'توکن امنیتی منقضی شده است. لطفاً صفحه را تازه‌سازی کنید.' ), 403 );
			}
		}

		/**
		 * آماده‌سازی حافظه و زمان اجرا جهت پردازش ایمن بسته‌ها.
		 */
		private static function prepare_runtime() {
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'admin' );
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			@ini_set( 'max_execution_time', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.max_execution_time_Blacklist
			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}

			if ( ! ob_get_level() ) {
				ob_start();
				self::$own_buffer = true;
			}

			register_shutdown_function( array( __CLASS__, 'shutdown_guard' ) );
		}

		private static function clean_output() {
			if ( self::$own_buffer && ob_get_level() ) {
				@ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				self::$own_buffer = false;
			}
		}

		private static function send_error_json( array $payload ) {
			self::clean_output();
			self::$responded = true;
			wp_send_json_error( $payload, 500 );
		}

		public static function shutdown_guard() {
			$error       = error_get_last();
			$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
			$is_fatal    = ( $error && in_array( $error['type'], $fatal_types, true ) );

			if ( class_exists( 'TCBVM_Backup' ) ) {
				TCBVM_Backup::flush();
			}

			if ( self::$responded || ! $is_fatal ) {
				return;
			}

			$can_see_details = function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' );
			$message         = $can_see_details
				? sprintf( 'خطای مهلک PHP در میانهٔ اجرا: %s در %s خط %d', $error['message'], basename( (string) $error['file'] ), (int) $error['line'] )
				: 'سرور در میانهٔ اجرا با خطای مهلک متوقف شد.';

			self::clean_output();

			$payload = wp_json_encode(
				array(
					'success' => false,
					'data'    => array(
						'message' => $message,
						'fatal'   => true,
					),
				)
			);

			if ( ! headers_sent() ) {
				status_header( 500 );
				header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
			}
			echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			self::$responded = true;
		}

		/**
		 * جستجوی گروهی محصولات بر اساس فیلترها (دسته‌بندی، شناسه‌ها، کلمات کلیدی).
		 */
		public static function ajax_search_products() {
			self::check_auth();

			$filters = isset( $_POST['filters'] ) && is_array( $_POST['filters'] )
				? wp_unslash( $_POST['filters'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				: array();

			// اطمینان از پذیرش انواع محصولات (هم متغیر و هم ساده برای تبدیل)
			if ( empty( $filters['product_types'] ) ) {
				$filters['product_types'] = array( 'variable', 'simple' );
			}

			$ids     = TCBVM_DB::query_product_ids( $filters );
			$total   = count( $ids );
			$summary = TCBVM_DB::get_products_summary( $ids, 100, 0 );

			wp_send_json_success( array(
				'total'   => $total,
				'ids'     => $ids,
				'items'   => $summary['items'],
				'message' => sprintf( '%d محصول منطبق یافت شد.', $total ),
			) );
		}

		/**
		 * واکشی صفحه‌ای اطلاعات محصولات جهت تکمیل تدریجی لیست انتخاب.
		 * صفحهٔ اول (۱۰۰ تای نخست) در پاسخ جستجو ارسال می‌شود و این مسیر
		 * جزئیات باقی شناسه‌ها را بسته‌به‌بسته می‌آورد تا هیچ سقفی در انتخاب نباشد.
		 */
		public static function ajax_products_summary_page() {
			self::check_auth();

			$ids    = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
			$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

			if ( empty( $ids ) ) {
				wp_send_json_success( array( 'items' => array() ) );
			}

			$summary = TCBVM_DB::get_products_summary( $ids, 100, $offset );

			wp_send_json_success( array(
				'items' => $summary['items'],
			) );
		}

		/**
		 * جستجوی زنده برای انتخاب تکی محصول (Autocomplete برای افزودن مستقیم).
		 */
		public static function ajax_search_single_products() {
			self::check_auth();

			$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
			if ( '' === $term ) {
				wp_send_json_success( array( 'results' => array() ) );
			}

			$args = array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 20,
				's'              => $term,
				'fields'         => 'ids',
			);

			// اگر عدد بود، مستقیماً با شناسه چک شود
			if ( is_numeric( $term ) ) {
				$direct_id = absint( $term );
				if ( 'product' === get_post_type( $direct_id ) ) {
					$args = array(
						'post_type' => 'product',
						'post__in'  => array( $direct_id ),
						'fields'    => 'ids',
					);
				}
			}

			$query = new WP_Query( $args );
			$ids   = array_map( 'absint', (array) $query->posts );

			$summary = TCBVM_DB::get_products_summary( $ids, 20, 0 );
			$results = array();

			foreach ( $summary['items'] as $item ) {
				$results[] = array(
					'id'              => $item['id'],
					'text'            => sprintf( '#%d — %s (%s)', $item['id'], $item['name'], $item['sku'] ),
					'name'            => $item['name'],
					'sku'             => $item['sku'],
					'type'            => $item['type'],
					'image_url'       => $item['image_url'],
					'variation_count' => $item['variation_count'],
					'cats'            => $item['cats'],
				);
			}

			wp_send_json_success( array( 'results' => $results ) );
		}

		/**
		 * استخراج ویژگی‌های موجود فروشگاه برای Autocomplete نام ویژگی.
		 */
		public static function ajax_get_attributes() {
			self::check_auth();

			$attrs = array();
			if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
				foreach ( (array) wc_get_attribute_taxonomies() as $attribute ) {
					if ( ! empty( $attribute->attribute_name ) ) {
						$label = ! empty( $attribute->attribute_label ) ? $attribute->attribute_label : $attribute->attribute_name;
						$attrs[] = array(
							'name'  => wc_attribute_taxonomy_name( $attribute->attribute_name ),
							'label' => $label,
						);
					}
				}
			}

			wp_send_json_success( array( 'attributes' => $attrs ) );
		}

		/**
		 * پیش‌نمایش تولید متغیرها قبل از اجرا.
		 */
		public static function ajax_preview() {
			self::check_auth();
			self::prepare_runtime();

			$product_ids    = isset( $_POST['product_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $_POST['product_ids'] ) ) ) ) : array();
			$attr_name      = isset( $_POST['attr_name'] ) ? sanitize_text_field( wp_unslash( $_POST['attr_name'] ) ) : 'مدل گوشی';
			$new_values     = isset( $_POST['new_values'] ) ? (array) $_POST['new_values'] : array();
			$price          = isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '';
			$sale_price     = isset( $_POST['sale_price'] ) ? sanitize_text_field( wp_unslash( $_POST['sale_price'] ) ) : '';
			$combine_other  = ! empty( $_POST['combine_other'] );
			$operation_mode = isset( $_POST['operation_mode'] ) ? TCBVM_OPS::sanitize_operation_mode( wp_unslash( $_POST['operation_mode'] ) ) : TCBVM_OPS::MODE_REPLACE_ALL;

			if ( empty( $product_ids ) ) {
				wp_send_json_error( array( 'message' => 'لطفاً ابتدا حداقل یک محصول را انتخاب کنید.' ) );
			}

			$clean_values = TCBVM_OPS::sanitize_model_list( $new_values );
			if ( empty( $clean_values ) ) {
				wp_send_json_error( array( 'message' => 'لطفاً حداقل یک مقدار ویژگی وارد کنید.' ) );
			}

			if ( TCBVM_OPS::MODE_REMOVE_VALUES !== $operation_mode && '' === trim( $price ) ) {
				wp_send_json_error( array( 'message' => 'وارد کردن قیمت متغیرها الزامی است.' ) );
			}

			try {
				$preview = TCBVM_OPS::preview( $product_ids, $attr_name, $clean_values, $price, $sale_price, $combine_other, $operation_mode );
			} catch ( \Throwable $e ) {
				self::send_error_json( array(
					'message' => 'خطا در محاسبه پیش‌نمایش: ' . $e->getMessage(),
					'fatal'   => true,
				) );
			}

			self::clean_output();
			self::$responded = true;
			wp_send_json_success( $preview );
		}

		/**
		 * پیش‌نمایش صفحه‌بندی‌شده: یک برش از محصولات (offset/limit) با همان ساختار خروجی.
		 * فرانت‌اند برای انتخاب‌های بزرگ، صفحه‌به‌صفحه صدا می‌زند و جمع می‌بندد تا هیچ
		 * درخواستی به سقف زمان/حافظهٔ سرور نرسد.
		 */
		public static function ajax_preview_page() {
			self::check_auth();
			self::prepare_runtime();

			$product_ids    = isset( $_POST['product_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $_POST['product_ids'] ) ) ) ) : array();
			$attr_name      = isset( $_POST['attr_name'] ) ? sanitize_text_field( wp_unslash( $_POST['attr_name'] ) ) : 'مدل گوشی';
			$new_values     = isset( $_POST['new_values'] ) ? (array) $_POST['new_values'] : array();
			$price          = isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '';
			$sale_price     = isset( $_POST['sale_price'] ) ? sanitize_text_field( wp_unslash( $_POST['sale_price'] ) ) : '';
			$combine_other  = ! empty( $_POST['combine_other'] );
			$operation_mode = isset( $_POST['operation_mode'] ) ? TCBVM_OPS::sanitize_operation_mode( wp_unslash( $_POST['operation_mode'] ) ) : TCBVM_OPS::MODE_REPLACE_ALL;
			$offset         = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
			$limit          = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 50;
			$limit          = min( max( $limit, 1 ), 200 );

			if ( empty( $product_ids ) ) {
				wp_send_json_error( array( 'message' => 'لطفاً ابتدا حداقل یک محصول را انتخاب کنید.' ) );
			}

			$clean_values = TCBVM_OPS::sanitize_model_list( $new_values );
			if ( empty( $clean_values ) ) {
				wp_send_json_error( array( 'message' => 'لطفاً حداقل یک مقدار ویژگی وارد کنید.' ) );
			}

			if ( TCBVM_OPS::MODE_REMOVE_VALUES !== $operation_mode && '' === trim( $price ) ) {
				wp_send_json_error( array( 'message' => 'وارد کردن قیمت متغیرها الزامی است.' ) );
			}

			try {
				$preview = TCBVM_OPS::preview( $product_ids, $attr_name, $clean_values, $price, $sale_price, $combine_other, $operation_mode, $offset, $limit );
			} catch ( \Throwable $e ) {
				self::send_error_json( array(
					'message' => 'خطا در محاسبه پیش‌نمایش: ' . $e->getMessage(),
					'fatal'   => true,
				) );
			}

			self::clean_output();
			self::$responded = true;
			wp_send_json_success( $preview );
		}

		/**
		 * ایجاد نشست جدید و تقسیم‌بندی محصولات به بسته‌های اجرایی.
		 */
		public static function ajax_start_run() {
			self::check_auth();
			self::prepare_runtime();

			$product_ids    = isset( $_POST['product_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $_POST['product_ids'] ) ) ) ) : array();
			$attr_name      = isset( $_POST['attr_name'] ) ? sanitize_text_field( wp_unslash( $_POST['attr_name'] ) ) : 'مدل گوشی';
			$new_values     = isset( $_POST['new_values'] ) ? (array) $_POST['new_values'] : array();
			$price          = isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '';
			$sale_price     = isset( $_POST['sale_price'] ) ? sanitize_text_field( wp_unslash( $_POST['sale_price'] ) ) : '';
			$stock_status   = isset( $_POST['stock_status'] ) ? sanitize_key( $_POST['stock_status'] ) : 'instock';
			$combine_other  = ! empty( $_POST['combine_other'] );
			$operation_mode = isset( $_POST['operation_mode'] ) ? TCBVM_OPS::sanitize_operation_mode( wp_unslash( $_POST['operation_mode'] ) ) : TCBVM_OPS::MODE_REPLACE_ALL;

			if ( empty( $product_ids ) ) {
				wp_send_json_error( array( 'message' => 'محصولی برای اجرا انتخاب نشده است.' ) );
			}

			$clean_values = TCBVM_OPS::sanitize_model_list( $new_values );
			if ( empty( $clean_values ) ) {
				wp_send_json_error( array( 'message' => 'فهرست مقادیر ویژگی خالی است.' ) );
			}

			if ( TCBVM_OPS::MODE_REMOVE_VALUES !== $operation_mode && '' === trim( $price ) ) {
				wp_send_json_error( array( 'message' => 'قیمت متغیرها مشخص نشده است.' ) );
			}

			$run_title = sprintf( '%s — ویژگی «%s»', TCBVM_OPS::operation_mode_label( $operation_mode ), $attr_name );

			$run_id = TCBVM_Backup::create_run_session( $run_title, $product_ids, array(
				'attr_name'     => $attr_name,
				'new_values'    => $clean_values,
				'price'         => $price,
				'sale_price'    => $sale_price,
				'stock_status'  => $stock_status,
				'combine_other' => $combine_other,
				'operation_mode' => $operation_mode,
			) );
			if ( ! $run_id ) {
				wp_send_json_error( array( 'message' => 'ثبت امن نشست اجرا ناموفق بود یا قفل تاریخچه در دسترس نیست؛ اجرا آغاز نشد.' ) );
			}

			$settings   = TCBVM_Core::get_settings();
			$batch_size = max( 1, (int) $settings['batch_size'] );
			$batches    = array_chunk( $product_ids, $batch_size );

			wp_send_json_success( array(
				'run_id'        => $run_id,
				'total_items'   => count( $product_ids ),
				'batch_size'    => $batch_size,
				'total_batches' => count( $batches ),
				'batches'       => $batches,
			) );
		}

		/**
		 * اجرای پردازش یک بسته (Batch).
		 */
		public static function ajax_execute_batch() {
			self::check_auth();
			self::prepare_runtime();

			$run_id        = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
			$batch_ids     = isset( $_POST['batch_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $_POST['batch_ids'] ) ) ) ) : array();
			$attr_name     = isset( $_POST['attr_name'] ) ? sanitize_text_field( wp_unslash( $_POST['attr_name'] ) ) : 'مدل گوشی';
			$new_values    = isset( $_POST['new_values'] ) ? (array) $_POST['new_values'] : array();
			$price         = isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '';
			$sale_price    = isset( $_POST['sale_price'] ) ? sanitize_text_field( wp_unslash( $_POST['sale_price'] ) ) : '';
			$stock_status  = isset( $_POST['stock_status'] ) ? sanitize_key( $_POST['stock_status'] ) : 'instock';
			$combine_other = ! empty( $_POST['combine_other'] );

			if ( empty( $run_id ) || empty( $batch_ids ) ) {
				wp_send_json_error( array( 'message' => 'اطلاعات بسته یا شناسه اجرا ناقص است.' ) );
			}

			try {
				$batch_result = TCBVM_OPS::execute_batch(
					$run_id,
					$batch_ids,
					$attr_name,
					$new_values,
					$price,
					$sale_price,
					$stock_status,
					$combine_other
				);
			} catch ( \Throwable $e ) {
				TCBVM_Backup::flush( $run_id );
				self::send_error_json( array(
					'message' => 'خطا در پردازش بسته: ' . $e->getMessage(),
					'fatal'   => true,
				) );
			}

			self::clean_output();
			self::$responded = true;
			wp_send_json_success( $batch_result );
		}

		/**
		 * پایان موفق یا اتمام نشست اجرایی.
		 */
		public static function ajax_finish_run() {
			self::check_auth();

			$run_id        = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
			$status        = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'completed';
			$created_count = isset( $_POST['created_count'] ) ? absint( $_POST['created_count'] ) : 0;
			$deleted_count = isset( $_POST['deleted_count'] ) ? absint( $_POST['deleted_count'] ) : 0;
			$items         = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( empty( $run_id ) ) {
				wp_send_json_error( array( 'message' => 'شناسه اجرا ارسال نشده است.' ) );
			}

			if ( ! TCBVM_Backup::finish_run_session( $run_id, $status, array(
				'created_count' => $created_count,
				'deleted_count' => $deleted_count,
				'items'         => $items,
			) ) ) {
				wp_send_json_error( array( 'message' => 'ثبت پایان اجرا ناموفق بود؛ تاریخچه دست‌کاری نشد و می‌توانید دوباره تلاش کنید.' ) );
			}

			// پاکسازی ترنزینت‌های سراسری قیمت ووکامرس
			wc_delete_product_transients();
			delete_transient( 'wc_var_prices' );

			wp_send_json_success( array(
				'message' => 'عملیات با موفقیت پایان یافت، گزارش در تاریخچه ثبت شد و متغیرها نوسازی گردیدند.',
			) );
		}

		/**
		 * دریافت جزئیات لاگ و گزارش کامل یک اجرا.
		 */
		public static function ajax_get_run_details() {
			self::check_auth();

			$run_id = isset( $_GET['run_id'] ) ? sanitize_text_field( wp_unslash( $_GET['run_id'] ) ) : '';
			if ( empty( $run_id ) ) {
				wp_send_json_error( array( 'message' => 'شناسه اجرا مشخص نشده است.' ) );
			}

			$run = TCBVM_Backup::get_run( $run_id );
			if ( ! $run ) {
				wp_send_json_error( array( 'message' => 'گزارش این اجرا یافت نشد.' ) );
			}

			wp_send_json_success( array(
				'run' => $run,
			) );
		}

		/**
		 * بازگردانی خودکار (Rollback) به وضعیت قبل از اجرا.
		 */
		public static function ajax_rollback() {
			self::check_auth();
			self::prepare_runtime();

			$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
			if ( empty( $run_id ) ) {
				wp_send_json_error( array( 'message' => 'شناسه اجرا مشخص نیست.' ) );
			}

			$result = TCBVM_Backup::rollback_run( $run_id );
			if ( ! empty( $result['success'] ) ) {
				wc_delete_product_transients();
				delete_transient( 'wc_var_prices' );
				wp_send_json_success( $result );
			} else {
				wp_send_json_error( $result );
			}
		}

		/**
		 * ذخیره الگوی سفارشی.
		 */
		public static function ajax_save_preset() {
			self::check_auth();

			$id          = isset( $_POST['id'] ) ? sanitize_key( $_POST['id'] ) : '';
			$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
			$description = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';
			$models_raw  = isset( $_POST['models'] ) ? wp_unslash( $_POST['models'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			$models = TCBVM_OPS::sanitize_model_list( $models_raw );

			if ( empty( $id ) ) {
				$id = 'custom_' . time();
			}
			if ( empty( $name ) || empty( $models ) ) {
				wp_send_json_error( array( 'message' => 'نام الگو و حداقل یک مقدار الزامی است.' ) );
			}

			$saved = TCBVM_Core::save_custom_preset( $id, $name, $description, $models );
			if ( $saved ) {
				wp_send_json_success( array(
					'message' => 'الگو با موفقیت ذخیره شد.',
					'preset'  => array(
						'id'          => $id,
						'name'        => $name,
						'description' => $description,
						'models'      => $models,
					),
				) );
			} else {
				wp_send_json_error( array( 'message' => 'ذخیره الگو با خطا مواجه شد.' ) );
			}
		}

		/**
		 * حذف الگوی سفارشی.
		 */
		public static function ajax_delete_preset() {
			self::check_auth();

			$id = isset( $_POST['id'] ) ? sanitize_key( $_POST['id'] ) : '';
			if ( empty( $id ) ) {
				wp_send_json_error( array( 'message' => 'شناسه الگو ارسال نشده است.' ) );
			}

			$deleted = TCBVM_Core::delete_custom_preset( $id );
			if ( $deleted ) {
				wp_send_json_success( array( 'message' => 'الگو با موفقیت حذف شد.' ) );
			} else {
				wp_send_json_error( array( 'message' => 'الگوهای پیش‌فرض سیستم قابل حذف نیستند یا الگو یافت نشد.' ) );
			}
		}

		/**
		 * جستجوی محصولات دارای یک ویژگی مشخص جهت پاکسازی (مرحله اول ابزار حذف ویژگی).
		 */
		public static function ajax_purge_attr_search() {
			self::check_auth();

			$label = isset( $_POST['attr_name'] ) ? sanitize_text_field( wp_unslash( $_POST['attr_name'] ) ) : '';
			if ( '' === trim( $label ) ) {
				wp_send_json_error( array( 'message' => 'عنوان ویژگی را وارد کنید.' ) );
			}

			TCBVM_OPS::ensure_all_attribute_taxonomies_registered();

			$matches = TCBVM_DB::resolve_attribute_globally( $label );
			$ids     = TCBVM_DB::query_products_with_attribute( $matches );

			if ( count( $ids ) > 5000 ) {
				wp_send_json_error( array(
					'message' => sprintf( 'تعداد محصولات دارای این ویژگی (%d) از سقف ایمنی ۵۰۰۰ محصول بیشتر است. به دلیل حجم بالا، پاکسازی خودکار متوقف شد؛ لطفاً ابتدا با مدیر فنی تیساکیس برای اجرای کنترل‌شده هماهنگ کنید.', count( $ids ) ),
				) );
			}

			if ( empty( $ids ) ) {
				wp_send_json_success( array(
					'matches' => $matches,
					'items'   => array(),
					'totals'  => array( 'products' => 0, 'linked_vars' => 0, 'terms' => 0 ),
					'message' => 'هیچ محصولی این ویژگی را ندارد.',
				) );
			}

			$usage = TCBVM_DB::attribute_usage_summary( $ids, $matches );
			$names = TCBVM_DB::get_products_name_map( $ids );

			$items            = array();
			$total_linked     = 0;
			$total_terms_seen = 0;

			foreach ( $ids as $pid ) {
				$linked = isset( $usage[ $pid ] ) ? (int) $usage[ $pid ]['linked_vars'] : 0;
				$terms  = isset( $usage[ $pid ] ) ? (int) $usage[ $pid ]['terms'] : 0;
				$total_linked     += $linked;
				$total_terms_seen += $terms;

				$items[] = array(
					'id'          => $pid,
					'name'        => isset( $names[ $pid ] ) ? $names[ $pid ] : "محصول #{$pid}",
					'edit_url'    => admin_url( 'post.php?post=' . $pid . '&action=edit' ),
					'linked_vars' => $linked,
					'terms'       => $terms,
				);
			}

			wp_send_json_success( array(
				'matches' => $matches,
				'items'   => $items,
				'totals'  => array(
					'products'    => count( $ids ),
					'linked_vars' => $total_linked,
					'terms'       => $total_terms_seen,
				),
				'message' => sprintf( '%d محصول دارای این ویژگی پیدا شد؛ مجموعاً %d متغیر وابسته.', count( $ids ), $total_linked ),
			) );
		}

		/**
		 * آغاز نشست پاکسازی ویژگی و ساخت بسته‌های پردازشی.
		 */
		public static function ajax_purge_attr_start() {
			self::check_auth();
			self::prepare_runtime();

			$product_ids = isset( $_POST['product_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $_POST['product_ids'] ) ) ) ) : array();
			$attr_name   = isset( $_POST['attr_name'] ) ? sanitize_text_field( wp_unslash( $_POST['attr_name'] ) ) : '';

			if ( empty( $product_ids ) ) {
				wp_send_json_error( array( 'message' => 'محصولی برای پاکسازی انتخاب نشده است.' ) );
			}
			if ( '' === trim( $attr_name ) ) {
				wp_send_json_error( array( 'message' => 'عنوان ویژگی ارسال نشده است.' ) );
			}

			$run_id = TCBVM_Backup::create_run_session(
				sprintf( 'پاکسازی ویژگی «%s» از محصولات', $attr_name ),
				$product_ids,
				array( 'attr_name' => $attr_name, 'operation_type' => 'purge_attribute' )
			);
			if ( ! $run_id ) {
				wp_send_json_error( array( 'message' => 'ثبت امن نشست پاکسازی ناموفق بود یا قفل تاریخچه در دسترس نیست؛ پاکسازی آغاز نشد.' ) );
			}

			$settings   = TCBVM_Core::get_settings();
			$batch_size = max( 1, (int) $settings['batch_size'] );
			$batches    = array_chunk( $product_ids, $batch_size );

			wp_send_json_success( array(
				'run_id'        => $run_id,
				'total_items'   => count( $product_ids ),
				'total_batches' => count( $batches ),
				'batches'       => $batches,
			) );
		}

		/**
		 * اجرای یک بسته پاکسازی ویژگی روی چند محصول.
		 */
		public static function ajax_purge_attr_batch() {
			self::check_auth();
			self::prepare_runtime();

			$run_id    = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
			$batch_ids = isset( $_POST['batch_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $_POST['batch_ids'] ) ) ) ) : array();
			$attr_name = isset( $_POST['attr_name'] ) ? sanitize_text_field( wp_unslash( $_POST['attr_name'] ) ) : '';

			if ( empty( $run_id ) || empty( $batch_ids ) || '' === trim( $attr_name ) ) {
				wp_send_json_error( array( 'message' => 'اطلاعات بسته پاکسازی ناقص است.' ) );
			}

			TCBVM_OPS::ensure_all_attribute_taxonomies_registered();
			$matches = TCBVM_DB::resolve_attribute_globally( $attr_name );
			if ( ! TCBVM_Backup::lock_run( $run_id ) ) {
				self::send_error_json( array( 'message' => 'قفل اجرا در دسترس نیست؛ بسته پاکسازی متوقف شد.', 'fatal' => true ) );
			}

			$items         = array();
			$deleted       = 0;
			$stop          = false;
			$run_lock_lost = false;
			$stop_message  = '';
			try {
				if ( ! TCBVM_Backup::run_is_active( $run_id ) ) {
					$stop         = true;
					$stop_message = 'اجرای پاکسازی فعال نیست؛ ادامه داده نشد.';
				}
				foreach ( $batch_ids as $batch_index => $product_id ) {
					if ( $stop ) {
						break;
					}
					if ( 0 === ( $batch_index % 10 ) && ! TCBVM_Backup::refresh_run_lock( $run_id ) ) {
						$stop          = true;
						$run_lock_lost = true;
						$stop_message  = 'قفل اجرا از دست رفت؛ ادامهٔ بسته متوقف شد.';
						break;
					}
					if ( ! TCBVM_Backup::run_is_active( $run_id ) ) {
						$stop         = true;
						$stop_message = 'اجرای پاکسازی دیگر فعال نیست؛ ادامه داده نشد.';
						break;
					}

					try {
						$res = TCBVM_OPS::purge_attribute_from_product( $product_id, $run_id, $matches );
					} catch ( \Throwable $e ) {
						$res = array(
							'success' => false,
							'title'   => "محصول #{$product_id}",
							'message' => 'خطا: ' . $e->getMessage(),
							'deleted' => 0,
						);
					}

					$item_message = isset( $res['message'] ) ? (string) $res['message'] : '';
					$deleted     += isset( $res['deleted'] ) ? (int) $res['deleted'] : 0;
					$items[]      = array(
						'id'      => $product_id,
						'status'  => ! empty( $res['success'] ) ? 'success' : 'error',
						'title'   => isset( $res['title'] ) ? $res['title'] : "محصول #{$product_id}",
						'message' => $item_message,
						'deleted' => isset( $res['deleted'] ) ? (int) $res['deleted'] : 0,
					);
					if ( empty( $res['success'] ) && false !== strpos( $item_message, 'قفل' ) ) {
						$stop         = true;
						$stop_message = $item_message;
						if ( false !== strpos( $item_message, 'قفل اجرا' ) || false !== strpos( $item_message, 'قفل اجرای' ) ) {
							$run_lock_lost = true;
						}
					}
				}

				if ( ! $run_lock_lost && ! TCBVM_Backup::flush( $run_id ) ) {
					$stop         = true;
					$stop_message = 'ثبت امن وضعیت batch ناموفق بود؛ ادامهٔ پاکسازی متوقف شد.';
				}
			} finally {
				TCBVM_Backup::unlock_run( $run_id );
			}

			self::clean_output();
			self::$responded = true;
			wp_send_json_success( array(
				'items'         => $items,
				'deleted_count' => $deleted,
				'stop'          => $stop,
				'message'       => $stop_message,
			) );
		}

		/**
		 * حذف سراسری تعریف ویژگی و تمام ترم‌هایش از فروشگاه (پس از پاکسازی محصولات).
		 */
		public static function ajax_purge_attr_global() {
			self::check_auth();
			self::prepare_runtime();

			$attr_name = isset( $_POST['attr_name'] ) ? sanitize_text_field( wp_unslash( $_POST['attr_name'] ) ) : '';
			if ( '' === trim( $attr_name ) ) {
				wp_send_json_error( array( 'message' => 'عنوان ویژگی ارسال نشده است.' ) );
			}

			TCBVM_OPS::ensure_all_attribute_taxonomies_registered();
			$matches = TCBVM_DB::resolve_attribute_globally( $attr_name );

			if ( empty( $matches['taxonomies'] ) ) {
				wp_send_json_error( array( 'message' => 'تاکسونومی سراسری منطبق با این عنوان یافت نشد (ویژگی محلی به صورت سراسری قابل حذف نیست).' ) );
			}

			$result = TCBVM_OPS::delete_attribute_globally( $matches['taxonomies'] );

			if ( ! empty( $result['success'] ) ) {
				self::clean_output();
				self::$responded = true;
				wp_send_json_success( $result );
			} else {
				self::send_error_json( $result );
			}
		}

		/**
		 * پاکسازی کش قیمت‌ها و ترنزینت‌های ووکامرس.
		 */
		public static function ajax_flush_cache() {
			self::check_auth();

			wc_delete_product_transients();
			delete_transient( 'wc_var_prices' );

			wp_send_json_success( array(
				'message' => 'کش قیمت‌ها و ترنزینت‌های محصولات با موفقیت نوسازی شد.',
			) );
		}
	}
}
