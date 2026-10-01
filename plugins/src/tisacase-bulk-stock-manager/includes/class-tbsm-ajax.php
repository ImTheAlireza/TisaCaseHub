<?php
/**
 * مسیرهای AJAX افزونهٔ مدیریت انبوه موجودی:
 * جست‌وجوی محصول، بارگذاری متغیرها/دسته‌ها و اعمال موجودی.
 *
 * @package TisaCase_Bulk_Stock_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TBSM_Ajax' ) ) {

	final class TBSM_Ajax {

		public static function init() {
			add_action( 'wp_ajax_tbsm_search_products', array( __CLASS__, 'ajax_search_products' ) );
			add_action( 'wp_ajax_tbsm_load_product', array( __CLASS__, 'ajax_load_product' ) );
			add_action( 'wp_ajax_tbsm_apply_stock', array( __CLASS__, 'ajax_apply_stock' ) );
		}

		/**
		 * نگهبان مشترک: دسترسی + توکن + حضور ووکامرس.
		 */
		private static function check_auth() {
			if ( ! TBSM_Core::can() ) {
				wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز است.' ), 403 );
			}
			if ( ! function_exists( 'wc_get_product' ) ) {
				wp_send_json_error( array( 'message' => 'ووکامرس نصب یا فعال نیست.' ) );
			}
			check_ajax_referer( TBSM_Core::NONCE_ACTION, 'nonce' );
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
				wp_send_json_success( array( 'results' => array() ) );
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
				$results[] = self::product_brief( $product );
			}

			wp_send_json_success( array( 'results' => $results ) );
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
				wp_send_json_error( array( 'message' => 'محصول انتخابی پیدا نشد.' ) );
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
					$labels[] = ( '' !== (string) $label ) ? (string) $label : $slug;
				}

				$image_id = $variation->get_image_id();

				$variations[] = array(
					'id'           => (int) $variation->get_id(),
					'name'         => empty( $labels ) ? ( 'متغیر #' . (int) $variation->get_id() ) : implode( ' · ', $labels ),
					'sku'          => (string) $variation->get_sku(),
					'stock'        => (int) $variation->get_stock_quantity(),
					'status'       => (string) $variation->get_stock_status(),
					'manage_stock' => (bool) $variation->get_manage_stock(),
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
				$categories[ $tax ] = array(
					'label'  => (string) wc_attribute_label( $tax ),
					'values' => $values,
				);
			}

			// محصول ساده: خودش یک «کارت» است تا موجودی‌اش هم از همین صفحه قابل ویرایش باشد.
			if ( ! $is_variable ) {
				$image_id = $product->get_image_id();
				$variations = array(
					array(
						'id'           => (int) $product->get_id(),
						'name'         => get_the_title( $product->get_id() ),
						'sku'          => (string) $product->get_sku(),
						'stock'        => (int) $product->get_stock_quantity(),
						'status'       => (string) $product->get_stock_status(),
						'manage_stock' => (bool) $product->get_manage_stock(),
						'image'        => $image_id ? (string) wp_get_attachment_image_url( (int) $image_id, 'thumbnail' ) : '',
						'cat'          => '',
					),
				);
			}

			wp_send_json_success(
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

			if ( ! empty( $result['ok'] ) ) {
				wp_send_json_success( $result );
			}
			wp_send_json_error( $result );
		}
	}
}
