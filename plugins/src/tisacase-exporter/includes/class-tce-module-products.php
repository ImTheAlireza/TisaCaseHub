<?php
/**
 * بخش «محصول‌ها» — یک ردیف برای هر محصول (و به‌اختیار، هر متغیر یک ردیف جدا).
 * از API خود ووکامرس استفاده می‌کند؛ برای کاتالوگ‌های متوسط/بزرگ هم امن است.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Module_Products' ) ) {

	final class TisaCase_Exporter_Module_Products extends TisaCase_Exporter_Module {

		public static function id() {
			return 'products';
		}

		public static function meta() {
			return array(
				'title'          => __( 'محصول‌ها', TisaCase_Exporter::TEXT_DOMAIN ),
				'sub'            => __( 'شناسه، نام، SKU، قیمت‌ها، موجودی و دستهٔ محصول‌ها — با گزینهٔ «هر متغیر یک ردیف».', TisaCase_Exporter::TEXT_DOMAIN ),
				'icon'           => '<path d="M3 8l9-5 9 5v8l-9 5-9-5zM3 8l9 5 9-5M12 13v8"/>',
				'badge'          => 'ready',
				'default_format' => 'csv',
				'unit'           => __( 'محصول', TisaCase_Exporter::TEXT_DOMAIN ),
				'kpi'            => array(
					'processed'  => __( 'محصول بررسی‌شده', TisaCase_Exporter::TEXT_DOMAIN ),
					'exported'   => __( 'ردیف خروجی', TisaCase_Exporter::TEXT_DOMAIN ),
					'skipped'    => __( 'ردیف کنارگذاشته', TisaCase_Exporter::TEXT_DOMAIN ),
					'duplicates' => __( 'تکراری حذف‌شده', TisaCase_Exporter::TEXT_DOMAIN ),
				),
			);
		}

		public static function columns_schema() {
			return array(
				'product_id'   => array( 'label' => __( 'شناسه', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'num', 'default' => true ),
				'name'         => array( 'label' => __( 'نام محصول', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text', 'default' => true ),
				'sku'          => array( 'label' => __( 'SKU', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'code', 'default' => true ),
				'type'         => array( 'label' => __( 'نوع', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text', 'default' => true ),
				'price'        => array( 'label' => __( 'قیمت', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'money', 'default' => true ),
				'regular_price' => array( 'label' => __( 'قیمت عادی', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'money', 'default' => true ),
				'sale_price'   => array( 'label' => __( 'قیمت فروش ویژه', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'money', 'default' => true ),
				'stock'        => array( 'label' => __( 'موجودی', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'num', 'default' => true ),
				'stock_status' => array( 'label' => __( 'وضعیت موجودی', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'stock', 'default' => true ),
				'categories'   => array( 'label' => __( 'دسته‌ها', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text', 'default' => true ),
				'date_created' => array( 'label' => __( 'تاریخ ایجاد', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'date' ),
				'parent_id'    => array( 'label' => __( 'شناسهٔ محصول والد', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'num' ),
				'attributes'   => array( 'label' => __( 'ویژگی‌ها', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text' ),
			);
		}

		public static function filters_schema() {
			$statuses = function_exists( 'get_post_statuses' ) ? get_post_statuses() : array();
			$options  = array( 'all' => __( 'همه', TisaCase_Exporter::TEXT_DOMAIN ) );
			$options['publish'] = __( 'منتشرشده', TisaCase_Exporter::TEXT_DOMAIN );
			$options['draft']   = __( 'پیش‌نویس', TisaCase_Exporter::TEXT_DOMAIN );
			$options['private'] = __( 'خصوصی', TisaCase_Exporter::TEXT_DOMAIN );
			$options['pending'] = __( 'در انتظار بررسی', TisaCase_Exporter::TEXT_DOMAIN );
			unset( $statuses );

			return array(
				array(
					'name'    => 'ptype',
					'type'    => 'select',
					'label'   => __( 'نوع محصول', TisaCase_Exporter::TEXT_DOMAIN ),
					'options' => array(
						'all'      => __( 'همه', TisaCase_Exporter::TEXT_DOMAIN ),
						'simple'   => __( 'ساده', TisaCase_Exporter::TEXT_DOMAIN ),
						'variable' => __( 'متغیر', TisaCase_Exporter::TEXT_DOMAIN ),
					),
					'default' => 'all',
				),
				array(
					'name'    => 'pstatus',
					'type'    => 'select',
					'label'   => __( 'وضعیت انتشار', TisaCase_Exporter::TEXT_DOMAIN ),
					'options' => $options,
					'default' => 'publish',
				),
				array(
					'name'    => 'stock',
					'type'    => 'select',
					'label'   => __( 'وضعیت موجودی', TisaCase_Exporter::TEXT_DOMAIN ),
					'options' => array(
						'all'         => __( 'همه', TisaCase_Exporter::TEXT_DOMAIN ),
						'instock'     => __( 'موجود', TisaCase_Exporter::TEXT_DOMAIN ),
						'outofstock'  => __( 'ناموجود', TisaCase_Exporter::TEXT_DOMAIN ),
						'onbackorder' => __( 'پیش‌خرید', TisaCase_Exporter::TEXT_DOMAIN ),
					),
					'default' => 'all',
				),
				array( 'name' => 'category', 'type' => 'text', 'label' => __( 'شناسه یا اسلاگ دسته (اختیاری)', TisaCase_Exporter::TEXT_DOMAIN ) ),
				array( 'name' => 'each_variation', 'type' => 'switch', 'label' => __( 'هر متغیر یک ردیف جدا', TisaCase_Exporter::TEXT_DOMAIN ), 'default' => false ),
			);
		}

		public static function dedup_keys() {
			return array( 'sku' => __( 'SKU', TisaCase_Exporter::TEXT_DOMAIN ) );
		}

		/** آرگومان‌های مشترک کوئری ووکامرس. */
		private static function query_args( array $filters, $limit, $page ) {
			$args = array(
				'limit'    => $limit,
				'page'     => max( 1, (int) $page ),
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'paginate' => false,
				'return'   => 'objects',
				'status'   => 'any',
			);

			if ( 'all' !== $filters['pstatus'] && '' !== $filters['pstatus'] ) {
				$args['status'] = $filters['pstatus'];
			}

			if ( 'all' !== $filters['ptype'] && '' !== $filters['ptype'] ) {
				$args['type'] = $filters['ptype'];
			}

			if ( 'all' !== $filters['stock'] && '' !== $filters['stock'] ) {
				$args['stock_status'] = $filters['stock'];
			}

			if ( '' !== (string) $filters['category'] ) {
				$category = sanitize_title( $filters['category'] );
				if ( '' === $category ) {
					$category = (string) absint( $filters['category'] );
				}
				if ( '' !== $category ) {
					$args['category'] = array( $category );
				}
			}

			return $args;
		}

		public static function count( array $filters ) {
			if ( ! function_exists( 'wc_get_products' ) ) {
				return 0;
			}

			$args             = self::query_args( $filters, 1, 1 );
			$args['paginate'] = true;

			$result = wc_get_products( $args );

			if ( is_object( $result ) && isset( $result->total ) ) {
				return (int) $result->total;
			}

			return is_array( $result ) ? count( $result ) : 0;
		}

		public static function fetch( array $filters, $cursor, $limit, array $columns = array() ) {
			if ( ! function_exists( 'wc_get_products' ) ) {
				return array( 'rows' => array(), 'cursor' => 1, 'done' => true );
			}

			$page     = max( 1, (int) $cursor );
			$products = wc_get_products( self::query_args( $filters, $limit, $page ) );
			$rows     = array();
			$each     = ! empty( $filters['each_variation'] );

			foreach ( (array) $products as $product ) {
				if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
					continue;
				}

				$rows[] = self::product_row( $product, false );

				if ( $each && $product->is_type( 'variable' ) ) {
					foreach ( (array) $product->get_children() as $child_id ) {
						$child = wc_get_product( $child_id );
						if ( is_object( $child ) ) {
							$rows[] = self::product_row( $child, true );
						}
					}
				}
			}

			return array(
				'rows'   => $rows,
				'cursor' => $page + 1,
				'done'   => count( (array) $products ) < (int) $limit,
			);
		}

		/** یک ردیف محصول/متغیر. */
		private static function product_row( $product, $is_variation ) {
			$parent_id = $is_variation ? (int) $product->get_parent_id() : 0;

			return array(
				'product_id'   => (int) $product->get_id(),
				'name'         => $product->get_name(),
				'parent_id'    => $parent_id,
				'sku'          => $product->get_sku(),
				'type'         => self::type_label( $product->get_type() ),
				'price'        => $product->get_price(),
				'regular_price' => $product->get_regular_price(),
				'sale_price'   => $product->get_sale_price(),
				'stock'        => null === $product->get_stock_quantity() ? '' : $product->get_stock_quantity(),
				'stock_status' => $product->get_stock_status(),
				'categories'   => self::categories_label( $product ),
				'date_created' => self::gmt_date( $product->get_date_created() ),
				'attributes'   => self::attributes_label( $product ),
			);
		}

		/** برچسب نوع محصول. */
		private static function type_label( $type ) {
			if ( function_exists( 'wc_get_product_types' ) ) {
				$types = wc_get_product_types();
				if ( isset( $types[ $type ] ) ) {
					return $types[ $type ];
				}
			}

			return (string) $type;
		}

		/** نام دسته‌های محصول، جدا‌شده با ویرگول فارسی. */
		private static function categories_label( $product ) {
			if ( ! function_exists( 'wp_get_post_terms' ) ) {
				return '';
			}

			$terms = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );

			return is_array( $terms ) ? implode( '، ', $terms ) : '';
		}

		/**
		 * تاریخ WC_DateTime → رشتهٔ GMT.
		 * (Format::value نوع date همین را به وقت محلی سایت تبدیل می‌کند؛ اگر اینجا وقت
		 * محلی برگردانده شود، تبدیل دوباره انجام و ساعت گزارش اشتباه می‌شد.)
		 */
		private static function gmt_date( $date ) {
			return self::gmt_string( $date );
		}

		/** خلاصهٔ ویژگی‌ها: «رنگ: قرمز، سایز: XL». */
		private static function attributes_label( $product ) {
			$out = array();

			foreach ( (array) $product->get_attributes() as $attribute ) {
				if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_name' ) ) {
					continue;
				}

				$name = wc_attribute_label( $attribute->get_name() );
				$value = '';

				if ( method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() && method_exists( $attribute, 'get_options' ) ) {
					$terms = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
					$value = implode( '، ', (array) $terms );
				} elseif ( method_exists( $attribute, 'get_options' ) ) {
					$value = implode( '، ', array_map( 'strval', (array) $attribute->get_options() ) );
				}

				$out[] = trim( $name . ': ' . $value );
			}

			return implode( ' | ', array_filter( $out ) );
		}
	}
}
