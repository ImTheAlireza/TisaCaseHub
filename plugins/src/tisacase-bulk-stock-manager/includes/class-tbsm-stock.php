<?php
/**
 * موتور موجودی: نوشتن `_stock` روی متغیرها (یا محصول ساده) با همگام‌سازی وضعیت
 * والد، ترم‌های product_visibility و جدول lookup — به‌همراه یافتن متغیرهای یک
 * «مدل» (مقدار ویژگی) در بازهٔ شناسهٔ محصولات برای حالت گروهی.
 *
 * @package TisaCase_Bulk_Stock_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TBSM_Stock' ) ) {

	final class TBSM_Stock {

		/* ----------------------------------------------------------
		 * اعمال موجودی
		 * ---------------------------------------------------------- */

		/**
		 * موجودی را روی فهرست متغیرهای یک محصول اعمال می‌کند (حالت تک‌محصول).
		 *
		 * @param int   $product_id شناسهٔ محصول.
		 * @param array $items      آرایهٔ [ شناسهٔ متغیر => مقدار موجودی ].
		 * @return array            گزارش اعمال (ok, applied, report, errors, ...).
		 */
		public static function apply_stock( $product_id, array $items ) {
			$product_id = absint( $product_id );
			$product    = wc_get_product( $product_id );

			if ( ! $product || ! $product->exists() ) {
				return array(
					'ok'    => false,
					'error' => 'محصول انتخابی پیدا نشد.',
				);
			}

			$is_variable = 'variable' === $product->get_type();
			$allowed_ids = $is_variable
				? array_map( 'absint', (array) $product->get_children() )
				: array( $product_id );

			if ( empty( $items ) ) {
				return array(
					'ok'    => false,
					'error' => 'هیچ متغیری برای اعمال انتخاب نشده است.',
				);
			}

			if ( count( $items ) > TBSM_Core::MAX_ITEMS_APPLY ) {
				return array(
					'ok'    => false,
					'error' => sprintf( 'سقف هر بار اعمال %d متغیر است.', TBSM_Core::MAX_ITEMS_APPLY ),
				);
			}

			return self::run_apply( $items, $allowed_ids, false );
		}

		/**
		 * اعمال موجودی در حالت گروهی: فقط متغیرهایی که «ویژگی/مقدار مدل» و
		 * «بازهٔ شناسهٔ محصول» انتخاب‌شده را داشته باشند (اعتبارسنجی دوبارهٔ سمت سرور).
		 *
		 * @param array  $items    آرایهٔ [ شناسهٔ متغیر => مقدار موجودی ].
		 * @param string $attr     نام ویژگی (کلید متای متغیر بدون پیشوند attribute_).
		 * @param string $value    مقدار مدل انتخاب‌شده.
		 * @param string $sku      بازهٔ شناسهٔ محصول (خالی = همهٔ محصولات).
		 * @param string $sku_mode prefix | contains.
		 * @return array گزارش اعمال.
		 */
		public static function apply_model_stock( array $items, $attr, $value, $sku = '', $sku_mode = 'prefix' ) {
			$attr  = self::sanitize_attribute_key( $attr );
			$value = (string) $value;

			if ( '' === $attr || '' === $value ) {
				return array(
					'ok'    => false,
					'error' => 'ابتدا ویژگی و مقدار مدل را انتخاب کنید.',
				);
			}

			if ( empty( $items ) ) {
				return array(
					'ok'    => false,
					'error' => 'هیچ متغیری برای اعمال انتخاب نشده است.',
				);
			}

			if ( count( $items ) > TBSM_Core::MAX_ITEMS_APPLY ) {
				return array(
					'ok'    => false,
					'error' => sprintf( 'سقف هر بار اعمال %d متغیر است.', TBSM_Core::MAX_ITEMS_APPLY ),
				);
			}

			$sku      = trim( (string) $sku );
			$sku_mode = self::normalize_sku_mode( $sku_mode, $sku );

			$allowed = array();
			foreach ( array_keys( $items ) as $raw_id ) {
				$var_id = absint( $raw_id );
				if ( self::model_item_allowed( $var_id, $attr, $value, $sku, $sku_mode ) ) {
					$allowed[] = $var_id;
				}
			}

			return self::run_apply( $items, $allowed, true, 'متغیر #%d با مدل/بازهٔ شناسهٔ انتخاب‌شده هم‌خوان نیست؛ رد شد.' );
		}

		/**
		 * حلقهٔ مشترک اعمال: اعتبارسنجی مجازها، نوشتن هر متغیر و همگام‌سازی والدها.
		 *
		 * @param array  $items           [ var_id => spec ].
		 * @param array  $allowed_ids     شناسه‌های مجاز.
		 * @param bool   $with_product    نام محصول والد در گزارش بیاید؟
		 * @param string $reject_template الگوی پیام رد شدن (sprintf با شناسه).
		 * @return array
		 */
		private static function run_apply( array $items, array $allowed_ids, $with_product = false, $reject_template = '' ) {
			if ( '' === $reject_template ) {
				$reject_template = 'متغیر #%d متعلق به این محصول نیست؛ رد شد.';
			}

			$report         = array();
			$errors         = array();
			$enabled_manage = 0;

			foreach ( $items as $raw_id => $raw_spec ) {
				$var_id = absint( $raw_id );

				if ( ! in_array( $var_id, $allowed_ids, true ) ) {
					$errors[] = sprintf( $reject_template, $var_id );
					continue;
				}

				$variation = wc_get_product( $var_id );
				if ( ! $variation || ! $variation->exists() ) {
					$errors[] = sprintf( 'متغیر #%d پیدا نشد؛ رد شد.', $var_id );
					continue;
				}

				try {
					self::apply_one( $variation, $raw_spec, $errors, $report, $enabled_manage, $with_product );
				} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					$errors[] = sprintf( 'خطا در ذخیرهٔ متغیر #%d: %s', $var_id, $e->getMessage() );
				}
			}

			// همگام‌سازی محصول(های) والد: وضعیت + ترم‌های product_visibility + جدول lookup.
			$sync_ids = array();
			foreach ( $report as $row ) {
				$sync_id = isset( $row['sync_id'] ) ? absint( $row['sync_id'] ) : 0;
				if ( $sync_id > 0 ) {
					$sync_ids[ $sync_id ] = true;
				}
			}

			$statuses = array();
			foreach ( array_keys( $sync_ids ) as $sync_id ) {
				$sync_product = wc_get_product( (int) $sync_id );
				if ( $sync_product && $sync_product->exists() ) {
					$statuses[] = self::sync_parent( $sync_product );
				}
			}

			$result = array(
				'ok'             => ! empty( $report ),
				'applied'        => count( $report ),
				'enabled_manage' => $enabled_manage,
				'parent_status'  => ( 1 === count( $statuses ) ) ? $statuses[0] : null,
				'parents_synced' => count( $statuses ),
				'report'         => $report,
				'errors'         => $errors,
			);

			// اگر هیچ‌چیز اعمال نشد، یک پیام واحد برای لایهٔ بالای خود بساز.
			if ( empty( $report ) && ! empty( $errors ) ) {
				$result['error'] = 'هیچ متغیری اعمال نشد: ' . implode( ' ', $errors );
			}

			return $result;
		}

		/**
		 * اعمال یک مقدار روی یک متغیر و افزودن ردیف گزارش.
		 *
		 * @param \WC_Product $variation      متغیر (یا محصول ساده).
		 * @param mixed       $raw_spec       عدد (نسخهٔ قدیمی) یا آرایهٔ { mode, qty }.
		 * @param array       $errors         مرجع خطاها.
		 * @param array       $report         مرجع گزارش.
		 * @param int         $enabled_manage شمارندهٔ روشن‌شدن ردیابی.
		 * @param bool        $with_product   نام محصول والد در ردیف گزارش بیاید؟
		 * @return bool آیا اعمال شد؟
		 */
		private static function apply_one( $variation, $raw_spec, &$errors, &$report, &$enabled_manage, $with_product = false ) {
			$var_id = (int) $variation->get_id();

			// مقدار: عدد (نسخهٔ قدیمی) یا آرایهٔ { mode, qty }.
			// mode: qty = عددی، in_stock = موجود بدون تعداد، out_of_stock = ناموجود.
			$mode = 'qty';
			$qty  = null;
			if ( is_array( $raw_spec ) ) {
				$m = isset( $raw_spec['mode'] ) ? (string) $raw_spec['mode'] : 'qty';
				if ( in_array( $m, array( 'qty', 'in_stock', 'out_of_stock' ), true ) ) {
					$mode = $m;
				}
				if ( 'qty' === $mode ) {
					$q = isset( $raw_spec['qty'] ) ? $raw_spec['qty'] : null;
					if ( ! is_numeric( $q ) ) {
						$errors[] = sprintf( 'موجودی متغیر #%d عدد معتبر نبود؛ رد شد.', $var_id );
						return false;
					}
					$qty = max( 0, min( 99999999, (int) $q ) );
				}
			} elseif ( is_numeric( $raw_spec ) ) {
				$qty = max( 0, min( 99999999, (int) $raw_spec ) );
			} else {
				$errors[] = sprintf( 'مقدار متغیر #%d نامعتبر است؛ رد شد.', $var_id );
				return false;
			}

			$from        = (int) $variation->get_stock_quantity();
			$from_status = (string) $variation->get_stock_status();
			$was_managed = (bool) $variation->get_manage_stock();

			$to_qty    = null;
			$to_status = $from_status;

			switch ( $mode ) {
				case 'in_stock':
					// موجود بدون تعداد: بدون ردیابی، وضعیت دستی «موجود».
					$variation->set_manage_stock( false );
					$variation->set_stock_status( 'instock' );
					$to_status = 'instock';
					break;
				case 'out_of_stock':
					// ناموجود: بدون ردیابی، وضعیت دستی «ناموجود».
					$variation->set_manage_stock( false );
					$variation->set_stock_status( 'outofstock' );
					$to_status = 'outofstock';
					break;
				default:
					// qty: ردیابی عددی (خاموش‌ها خودکار روشن می‌شوند).
					if ( ! $was_managed ) {
						$variation->set_manage_stock( true );
						$enabled_manage++;
					}
					$variation->set_stock( $qty );
					$variation->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
					$to_status = $qty > 0 ? 'instock' : 'outofstock';
					$to_qty    = $qty;
			}

			$variation->save();

			$parent_id = (int) $variation->get_parent_id();

			$row = array(
				'id'          => $var_id,
				'name'        => self::variation_label( $variation ),
				'sku'         => (string) $variation->get_sku(),
				'mode'        => $mode,
				'from'        => $from,
				'to'          => $to_qty,
				'from_status' => $from_status,
				'to_status'   => $to_status,
				'enabled'     => ! $was_managed,
				'parent_id'   => $parent_id,
				'sync_id'     => $parent_id > 0 ? $parent_id : $var_id,
			);

			if ( $with_product && $parent_id > 0 ) {
				$row['product'] = get_the_title( $parent_id );
			}

			$report[] = $row;

			return true;
		}

		/**
		 * وضعیت موجودی والد را از روی فرزندهایش محاسبه و همگام می‌کند
		 * (متای `_stock_status`، ترم‌های product_visibility و جدول lookup).
		 *
		 * @param \WC_Product $product محصول.
		 * @return string وضعیت نهایی ('instock' | 'outofstock').
		 */
		public static function sync_parent( $product ) {
			if ( 'variable' !== $product->get_type() ) {
				return (string) $product->get_stock_status();
			}

			$has_stock = false;
			foreach ( (array) $product->get_children() as $child_id ) {
				$child = wc_get_product( (int) $child_id );
				if ( $child && $child->is_in_stock() ) {
					$has_stock = true;
					break;
				}
			}

			$status = $has_stock ? 'instock' : 'outofstock';

			try {
				$product->set_stock_status( $status );
				$product->save();
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// شکست ذخیرهٔ والد نباید نوشتن متغیرها را باطل کند.
			}

			// جدول wp_wc_product_meta_lookup (فیلترهای فروشگاه و آرشیوها).
			if ( function_exists( 'wc_update_product_lookup_tables' ) ) {
				wc_update_product_lookup_tables( (int) $product->get_id() );
			}

			return $status;
		}

		/* ----------------------------------------------------------
		 * حالت گروهی: کشف ویژگی‌ها و یافتن متغیرهای یک مدل
		 * ---------------------------------------------------------- */

		/**
		 * فهرست ویژگی‌های متغیرها به‌همراه مقادیرشان (برای سلکت «مدل متغیر»).
		 *
		 * ویژگی‌ها از خودِ متادیتای متغیرها کشف می‌شوند (`attribute_*`)، پس
		 * ویژگی‌های سراسری (pa_*) و ویژگی‌های سفارشی هر دو پوشش داده می‌شوند.
		 *
		 * @return array [{attr, label, values: [{value, label, count}]}]
		 */
		public static function list_model_attributes() {
			global $wpdb;

			$attributes = array();
			$prefix     = 'attribute_';
			$keys       = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s ORDER BY meta_key ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->esc_like( $prefix ) . '%',
					TBSM_Core::MAX_MODEL_ATTRS
				)
			);

			foreach ( (array) $keys as $meta_key ) {
				$meta_key = (string) $meta_key;
				$attr     = substr( $meta_key, strlen( $prefix ) );
				if ( '' === $attr ) {
					continue;
				}

				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_value AS value, COUNT(*) AS total FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' GROUP BY meta_value ORDER BY total DESC, meta_value ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$meta_key,
						TBSM_Core::MAX_MODEL_VALUES
					),
					ARRAY_A
				);

				$values = array();
				foreach ( (array) $rows as $row ) {
					$values[] = array(
						'value' => (string) $row['value'],
						'label' => self::clean_label( self::maybe_decode_label( (string) $row['value'] ) ),
						'count' => (int) $row['total'],
					);
				}

				if ( empty( $values ) ) {
					continue;
				}

				$attributes[ $attr ] = array(
					'attr'   => (string) $attr,
					'label'  => self::attribute_display_label( $attr ),
					'values' => $values,
				);
			}

			// ویژگی‌های سراسری: برچسب مقدارها از نام ترم‌های ووکامرس خوانده می‌شود.
			foreach ( $attributes as $attr => $data ) {
				if ( ! function_exists( 'wc_attribute_label' ) || ! taxonomy_exists( $attr ) ) {
					continue;
				}
				$terms = get_terms(
					array(
						'taxonomy'   => $attr,
						'hide_empty' => false,
					)
				);
				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					continue;
				}

				$map = array();
				foreach ( $terms as $term ) {
					$map[ (string) $term->slug ] = self::clean_label( self::maybe_decode_label( (string) $term->name ) );
				}

				$label = self::maybe_decode_label( (string) wc_attribute_label( $attr ) );
				$attributes[ $attr ]['label'] = self::clean_label( $label );

				foreach ( $attributes[ $attr ]['values'] as $index => $value ) {
					if ( isset( $map[ (string) $value['value'] ] ) && '' !== $map[ (string) $value['value'] ] ) {
						$attributes[ $attr ]['values'][ $index ]['label'] = $map[ (string) $value['value'] ];
					}
				}
			}

			$list = array_values( $attributes );
			usort(
				$list,
				static function ( $a, $b ) {
					return strcmp( (string) $a['label'], (string) $b['label'] );
				}
			);

			return $list;
		}

		/**
		 * یافتن متغیرهای یک مدل در میان محصولاتی که شناسه‌شان با بازه می‌خواند.
		 *
		 * @param string $attr     نام ویژگی.
		 * @param string $value    مقدار مدل.
		 * @param string $sku      بازهٔ شناسهٔ محصولات (خالی = همه).
		 * @param string $sku_mode prefix | contains.
		 * @return array {rows, products, truncated, error?}
		 */
		public static function find_model_rows( $attr, $value, $sku = '', $sku_mode = 'prefix' ) {
			$attr  = self::sanitize_attribute_key( $attr );
			$value = (string) $value;

			if ( '' === $attr || '' === $value ) {
				return array(
					'rows'      => array(),
					'products'  => 0,
					'truncated' => false,
					'error'     => 'ویژگی و مقدار مدل را انتخاب کنید.',
				);
			}

			$sku      = trim( (string) $sku );
			$sku_mode = self::normalize_sku_mode( $sku_mode, $sku );

			global $wpdb;

			$limit  = TBSM_Core::MAX_MODEL_ROWS + 1; // یکی بیشتر برای تشخیص بریدگی فهرست.
			$params = array( 'attribute_' . $attr, $value );

			// شناسهٔ والد با LEFT JOIN می‌آید تا محصول بدون SKU در حالت «همهٔ محصولات» حذف نشود.
			$sql = "SELECT DISTINCT v.ID AS vid, v.post_parent AS pid, sk.meta_value AS psku, (sk.meta_value IS NULL) AS psku_null
				FROM {$wpdb->posts} v
				INNER JOIN {$wpdb->postmeta} am ON am.post_id = v.ID AND am.meta_key = %s AND am.meta_value = %s
				INNER JOIN {$wpdb->posts} p ON p.ID = v.post_parent
				LEFT JOIN {$wpdb->postmeta} sk ON sk.post_id = p.ID AND sk.meta_key = '_sku'";

			$where = " WHERE v.post_type = 'product_variation' AND v.post_status IN ('publish','draft','private','pending')
				AND p.post_type = 'product' AND p.post_status IN ('publish','draft','pending')";

			if ( '' !== $sku ) {
				$like     = ( 'prefix' === $sku_mode ) ? $wpdb->esc_like( $sku ) . '%' : '%' . $wpdb->esc_like( $sku ) . '%';
				$where   .= ' AND sk.meta_value LIKE %s';
				$params[] = $like;
			}

			$sql     .= $where . ' ORDER BY psku_null ASC, psku ASC, vid ASC LIMIT %d';
			$params[] = $limit;

			$found = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$found = is_array( $found ) ? $found : array();

			$truncated = count( $found ) > TBSM_Core::MAX_MODEL_ROWS;
			if ( $truncated ) {
				$found = array_slice( $found, 0, TBSM_Core::MAX_MODEL_ROWS );
			}

			$rows     = array();
			$products = array();

			foreach ( $found as $item ) {
				$variation = wc_get_product( (int) $item['vid'] );
				$parent    = wc_get_product( (int) $item['pid'] );
				if ( ! $variation || ! $variation->exists() || ! $parent || ! $parent->exists() ) {
					continue;
				}
				$rows[] = self::model_row( $parent, $variation );
				$products[ (int) $item['pid'] ] = true;
			}

			return array(
				'rows'      => $rows,
				'products'  => count( $products ),
				'truncated' => $truncated,
			);
		}

		/**
		 * آیا این متغیر با مدل و بازهٔ شناسهٔ انتخابی هم‌خوان است؟ (اعتبارسنجی سرور)
		 *
		 * @param int    $var_id   شناسهٔ متغیر.
		 * @param string $attr     نام ویژگی.
		 * @param string $value    مقدار مدل.
		 * @param string $sku      بازهٔ شناسه.
		 * @param string $sku_mode prefix | contains.
		 * @return bool
		 */
		private static function model_item_allowed( $var_id, $attr, $value, $sku, $sku_mode ) {
			$var_id = absint( $var_id );
			if ( $var_id <= 0 || 'product_variation' !== get_post_type( $var_id ) ) {
				return false;
			}

			$stored = get_post_meta( $var_id, 'attribute_' . $attr, true );
			if ( (string) $stored !== (string) $value ) {
				return false;
			}

			if ( '' === $sku ) {
				return true;
			}

			$parent = wc_get_product( (int) wp_get_post_parent_id( $var_id ) );
			if ( ! $parent || ! $parent->exists() ) {
				return false;
			}

			$product_sku = (string) $parent->get_sku();
			if ( '' === $product_sku ) {
				return false;
			}

			return ( 'prefix' === $sku_mode )
				? ( 0 === stripos( $product_sku, $sku ) )
				: ( false !== stripos( $product_sku, $sku ) );
		}

		/**
		 * یک ردیف کارت برای حالت گروهی.
		 *
		 * @param \WC_Product $parent    محصول والد.
		 * @param \WC_Product $variation متغیر.
		 * @return array
		 */
		private static function model_row( $parent, $variation ) {
			$managed  = (bool) $variation->get_manage_stock();
			$status   = (string) $variation->get_stock_status();
			$image_id = $variation->get_image_id();
			if ( ! $image_id ) {
				$image_id = $parent->get_image_id();
			}

			return array(
				'id'           => (int) $variation->get_id(),
				'pid'          => (int) $parent->get_id(),
				'pname'        => get_the_title( $parent->get_id() ),
				'psku'         => (string) $parent->get_sku(),
				'name'         => self::variation_label( $variation ),
				'sku'          => (string) $variation->get_sku(),
				'stock'        => (int) $variation->get_stock_quantity(),
				'status'       => $status,
				'manage_stock' => $managed,
				'mode'         => $managed ? 'qty' : ( 'instock' === $status ? 'in_stock' : 'out_of_stock' ),
				'image'        => $image_id ? (string) wp_get_attachment_image_url( (int) $image_id, 'thumbnail' ) : '',
				'cat'          => '',
			);
		}

		/** نرمال‌سازی حالت تطبیق شناسه. */
		private static function normalize_sku_mode( $mode, $sku ) {
			$mode = (string) $mode;
			if ( '' === trim( (string) $sku ) ) {
				return 'all';
			}
			return in_array( $mode, array( 'prefix', 'contains' ), true ) ? $mode : 'prefix';
		}

		/** پاک‌سازی نام ویژگی برای استفاده به‌عنوان کلید متا. */
		private static function sanitize_attribute_key( $key ) {
			$key = (string) $key;
			$key = str_replace( array( "\0", "'", '"', ' ', "\t", "\n", "\r" ), '', $key );
			return substr( $key, 0, 190 );
		}

		/* ----------------------------------------------------------
		 * برچسب‌ها
		 * ---------------------------------------------------------- */

		/**
		 * برچسب نمایشی یک ویژگی (سراسری از ووکامرس، سفارشی از نام کلید).
		 *
		 * @param string $attr نام ویژگی.
		 * @return string
		 */
		private static function attribute_display_label( $attr ) {
			$label = '';
			if ( function_exists( 'wc_attribute_label' ) && taxonomy_exists( $attr ) ) {
				$label = (string) wc_attribute_label( $attr );
			}
			if ( '' === $label || $label === (string) $attr ) {
				$label = (string) $attr;
			}
			$label = self::clean_label( self::maybe_decode_label( $label ) );
			return ( '' !== $label ) ? $label : (string) $attr;
		}

		/**
		 * برچسب خوانا برای یک متغیر (ترکیب مقادیر ویژگی‌ها).
		 *
		 * @param \WC_Product $product شیء متغیر (یا محصول ساده).
		 * @return string
		 */
		public static function variation_label( $product ) {
			$parts = array();

			foreach ( (array) $product->get_attributes() as $tax => $value ) {
				if ( null === $value || '' === (string) $value ) {
					continue;
				}
				$label = $product->get_attribute( $tax );
				$clean = self::clean_label( ( '' !== (string) $label ) ? (string) $label : (string) $value );
				if ( '' !== $clean && ! in_array( $clean, $parts, true ) ) {
					$parts[] = $clean;
				}
			}

			if ( empty( $parts ) ) {
				return 'متغیر #' . (int) $product->get_id();
			}

			return implode( ' · ', $parts );
		}

		/**
		 * پاک‌سازی برچسب ویژگی: حذف کاراکترهای صفرعرض، فاصله‌های اضافی
		 * و جداکننده‌هایی که ممکن است از ابتدا/انتها آویزان باشند.
		 *
		 * @param string $label برچسب خام.
		 * @return string
		 */
		public static function clean_label( $label ) {
			$label = (string) $label;

			// کاراکترهای صفرعرض (RTL marks، ZWNJ و BOM).
			$label = (string) preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', $label );

			// جداکننده‌ها و فاصله‌های آویزان از ابتدا و انتها.
			$label = (string) preg_replace( '/^[\s\p{P}\p{S}]+/u', '', $label );
			$label = (string) preg_replace( '/[\s\p{P}\p{S}]+$/u', '', $label );

			return trim( $label );
		}

		/**
		 * بازیابی برچسبِ percent-encoded: برخی افزونه‌ها taxonomy ویژگی‌های
		 * فارسی را با نام URL-encoded می‌سازند (مثلاً d9%85d8%af%d9%84 به‌جای «مدل»).
		 * این encoding گاه ناقص است (نه هر بایت % دارد) و rawurldecode از آن UTF-8
		 * معتبر نمی‌سازد؛ پس اگر کلِ برچسب فقط از hex و % باشد، %ها را حذف، hex
		 * می‌کنیم و به‌صورت UTF-8 decode می‌کنیم. برچسب‌های عادی دست‌نخورده می‌مانند.
		 *
		 * @param string $label برچسب (ممکن است percent-encoded باشد).
		 * @return string
		 */
		public static function maybe_decode_label( $label ) {
			$label = (string) $label;
			if ( '' === $label || false === strpos( $label, '%' ) ) {
				return $label;
			}
			if ( 1 !== preg_match( '/^[0-9a-fA-F%]+$/', $label ) ) {
				return $label;
			}
			$hex = str_replace( '%', '', $label );
			if ( '' === $hex || ( strlen( $hex ) % 2 ) !== 0 ) {
				return $label;
			}
			$bytes = '';
			for ( $i = 0, $n = strlen( $hex ); $i < $n; $i += 2 ) {
				$bytes .= chr( (int) hexdec( substr( $hex, $i, 2 ) ) );
			}
			if ( '' !== $bytes && $bytes !== $label && self::is_valid_utf8( $bytes ) ) {
				return $bytes;
			}
			return $label;
		}

		/** آیا رشتهٔ ورودی UTF-8 معتبر است؟ */
		private static function is_valid_utf8( $s ) {
			$s = (string) $s;
			if ( function_exists( 'mb_check_encoding' ) ) {
				return (bool) mb_check_encoding( $s, 'UTF-8' );
			}
			return preg_match( '//u', $s ) === 1;
		}
	}
}
