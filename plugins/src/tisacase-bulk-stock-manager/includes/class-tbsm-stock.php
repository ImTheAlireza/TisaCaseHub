<?php
/**
 * موتور موجودی: نوشتن `_stock` روی متغیرها (یا محصول ساده) با همگام‌سازی وضعیت
 * والد، ترم‌های product_visibility و جدول lookup — به‌همراه حالت گروهی:
 * «محدودهٔ محصولات» (چند محصول / دسته / شناسه) ← ویژگی‌ها و مقدارهای همان
 * محدوده ← یافتن متغیرهای یک مدل و اعمال موجودی روی آن‌ها.
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
		 * اعمال موجودی در حالت گروهی: فقط متغیرهایی که هم «مقدار مدل» انتخاب‌شده را
		 * داشته باشند و هم محصول والدشان در «محدودهٔ محصولات» باشد (اعتبارسنجی دوبارهٔ
		 * سمت سرور — کلاینت فقط پیشنهاد می‌دهد).
		 *
		 * @param array $items     آرایهٔ [ شناسهٔ متغیر => مقدار موجودی ].
		 * @param array $attr_keys کلیدهای متای ویژگی که همه به یک ویژگی اشاره می‌کنند
		 *                         (مثل pa_model و model و d9%85d8%af%d9%84).
		 * @param array $values    مقدارهای خام هم‌معنا (نوشتارهای مختلف یک مقدار).
		 * @param array $scope     محدوده (خروجی normalize_scope).
		 * @return array گزارش اعمال.
		 */
		public static function apply_scope_stock( array $items, array $attr_keys, array $values, array $scope ) {
			$attr_keys = self::sanitize_key_list( $attr_keys, TBSM_Core::MAX_MODEL_ATTRS );
			$values    = self::sanitize_value_list( $values, TBSM_Core::MAX_MODEL_VALUES );
			$scope     = self::normalize_scope( $scope );

			if ( empty( $attr_keys ) || empty( $values ) ) {
				return array(
					'ok'    => false,
					'error' => 'ابتدا ویژگی و مقدار مدل را انتخاب کنید.',
				);
			}

			$scope_error = self::scope_error( $scope );
			if ( '' !== $scope_error ) {
				return array(
					'ok'    => false,
					'error' => $scope_error,
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

			$allowed = array();
			foreach ( array_keys( $items ) as $raw_id ) {
				$var_id = absint( $raw_id );
				if ( self::scope_item_allowed( $var_id, $scope, $attr_keys, $values ) ) {
					$allowed[] = $var_id;
				}
			}

			return self::run_apply( $items, $allowed, true, 'متغیر #%d با مدل/محدودهٔ انتخاب‌شده هم‌خوان نیست؛ رد شد.' );
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
		 * فهرست دسته‌بندی‌های محصول (product_cat) با عمق و شمارش، برای گام
		 * «محدودهٔ محصولات» — مرتب‌شده به شکل درختی (والد، بعد فرزندان).
		 *
		 * @return array [{id, name, parent, depth, count}]
		 */
		public static function list_product_categories() {
			if ( ! taxonomy_exists( 'product_cat' ) ) {
				return array();
			}

			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
				)
			);
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				return array();
			}

			$rows = array();
			foreach ( $terms as $term ) {
				$rows[] = array(
					'id'     => (int) $term->term_id,
					'name'   => self::clean_label( self::maybe_decode_label( (string) $term->name ) ),
					'parent' => (int) $term->parent,
					'depth'  => count( (array) get_ancestors( (int) $term->term_id, 'product_cat' ) ),
					'count'  => (int) $term->count,
				);
			}

			$by_parent = array();
			foreach ( $rows as $row ) {
				$by_parent[ (int) $row['parent'] ][] = $row;
			}

			$sorted = array();
			$walk   = function ( $parent ) use ( &$walk, &$by_parent, &$sorted ) {
				if ( empty( $by_parent[ $parent ] ) ) {
					return;
				}
				foreach ( $by_parent[ $parent ] as $row ) {
					$sorted[] = $row;
					$walk( (int) $row['id'] );
				}
			};

			$walk( 0 );

			return ! empty( $sorted ) ? $sorted : $rows;
		}

		/* ----------------------------------------------------------
		 * محدودهٔ محصولات (scope)
		 * ---------------------------------------------------------- */

		/**
		 * نرمال‌سازی «محدودهٔ محصولات» از پارامترهای خام.
		 *
		 * type: all | products (چند محصول) | category (دسته‌بندی) | sku (شناسه).
		 *
		 * @param array $raw محدودهٔ خام.
		 * @return array {type, product_ids, category_id, sku, sku_mode}
		 */
		public static function normalize_scope( array $raw ) {
			$type = isset( $raw['type'] ) ? strtolower( trim( (string) $raw['type'] ) ) : 'all';
			if ( ! in_array( $type, array( 'all', 'products', 'category', 'sku' ), true ) ) {
				$type = 'all';
			}

			$ids = array();
			foreach ( (array) ( isset( $raw['product_ids'] ) ? $raw['product_ids'] : array() ) as $id ) {
				$id = absint( $id );
				if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
			if ( count( $ids ) > TBSM_Core::MAX_SCOPE_PRODUCTS ) {
				$ids = array_slice( $ids, 0, TBSM_Core::MAX_SCOPE_PRODUCTS );
			}

			$category = absint( isset( $raw['category_id'] ) ? $raw['category_id'] : 0 );
			$sku      = trim( (string) ( isset( $raw['sku'] ) ? $raw['sku'] : '' ) );
			$mode     = self::normalize_sku_mode( isset( $raw['sku_mode'] ) ? $raw['sku_mode'] : 'prefix', $sku );

			// بازهٔ شناسهٔ خالی = همهٔ محصولات.
			if ( 'sku' === $type && '' === $sku ) {
				$type = 'all';
			}

			return array(
				'type'        => $type,
				'product_ids' => $ids,
				'category_id' => $category,
				'sku'         => $sku,
				'sku_mode'    => $mode,
			);
		}

		/**
		 * پیام خطای محدودهٔ ناقص — رشتهٔ خالی یعنی محدوده معتبر است.
		 *
		 * @param array $scope محدوده.
		 * @return string
		 */
		public static function scope_error( $scope ) {
			if ( 'products' === $scope['type'] && empty( $scope['product_ids'] ) ) {
				return 'حداقل یک محصول را انتخاب کنید.';
			}
			if ( 'category' === $scope['type'] && (int) $scope['category_id'] <= 0 ) {
				return 'یک دسته‌بندی انتخاب کنید.';
			}
			return '';
		}

		/**
		 * برچسب خوانا برای محدوده (خط اطلاعات بالای کارت‌ها).
		 *
		 * @param array $scope محدوده.
		 * @return string
		 */
		public static function scope_label( $scope ) {
			switch ( $scope['type'] ) {
				case 'products':
					return self::fa_digits( sprintf( '%d محصول انتخابی', count( (array) $scope['product_ids'] ) ) );
				case 'category':
					$term = get_term( (int) $scope['category_id'], 'product_cat' );
					$name = ( $term instanceof WP_Term ) ? self::clean_label( self::maybe_decode_label( (string) $term->name ) ) : '';
					return ( '' !== $name ) ? 'دستهٔ «' . $name . '»' : 'دستهٔ انتخابی';
				case 'sku':
					return 'شناسهٔ «' . $scope['sku'] . '»' . ( 'contains' === $scope['sku_mode'] ? ' (شامل می‌شود)' : ' (شروع می‌شود با)' );
				default:
					return 'همهٔ محصولات';
			}
		}

		/**
		 * شمارش محصولات و متغیرهای یک محدوده.
		 *
		 * @param array $scope محدوده.
		 * @return array {products, variations}
		 */
		public static function scope_summary( $scope ) {
			global $wpdb;

			$scope = self::normalize_scope( $scope );
			$sql   = self::scope_sql( $scope );

			$query = "SELECT COUNT(DISTINCT p.ID) AS products, COUNT(DISTINCT v.ID) AS variations
				FROM {$wpdb->posts} v
				INNER JOIN {$wpdb->posts} p ON p.ID = v.post_parent AND p.post_type = 'product' AND p.post_status IN ('publish','draft','pending')
				{$sql['join']}
				WHERE v.post_type = 'product_variation' AND v.post_status IN ('publish','draft','private','pending') {$sql['where']}";

			$row = empty( $sql['params'] )
				? $wpdb->get_row( $query, ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				: $wpdb->get_row( $wpdb->prepare( $query, $sql['params'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			return array(
				'products'   => (int) ( isset( $row['products'] ) ? $row['products'] : 0 ),
				'variations' => (int) ( isset( $row['variations'] ) ? $row['variations'] : 0 ),
			);
		}

		/**
		 * ویژگی‌های موجود در «محدودهٔ انتخابی» به‌همراه شمارش متغیر/محصول.
		 *
		 * ویژگی‌هایی که برچسب خوانایشان یکی است (مثل pa_model، مدل و
		 * d9%85d8%af%d9%84 که همه «مدل» خوانده می‌شوند) در **یک ردیف** ادغام
		 * می‌شوند و کلیدهای خامشان در `keys` می‌ماند؛ اعمال و فهرست‌کردن هم روی
		 * همان مجموعه انجام می‌شود تا کاربر چند ردیف تکراری و گیج‌کننده نبیند.
		 *
		 * @param array $scope محدوده.
		 * @return array [{label, keys, products, variations}]
		 */
		public static function list_scope_attributes( $scope ) {
			global $wpdb;

			$scope = self::normalize_scope( $scope );
			$sql   = self::scope_sql( $scope );
			$base  = self::scope_base();

			$params   = array_merge( array( $wpdb->esc_like( 'attribute_' ) . '%' ), $sql['params'] );
			$params[] = TBSM_Core::MAX_MODEL_ATTRS;

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT am.meta_key AS meta_key,
						COUNT(DISTINCT am.post_id) AS variations,
						COUNT(DISTINCT p.ID) AS products
					{$base} {$sql['join']}
					WHERE am.meta_key LIKE %s AND am.meta_value <> '' {$sql['where']}
					GROUP BY am.meta_key
					ORDER BY products DESC, variations DESC, am.meta_key ASC
					LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$params
				),
				ARRAY_A
			);
			$rows = is_array( $rows ) ? $rows : array();

			$groups = array();
			foreach ( $rows as $row ) {
				$meta_key = (string) $row['meta_key'];
				$attr     = substr( $meta_key, strlen( 'attribute_' ) );
				if ( '' === $attr ) {
					continue;
				}

				$label = self::attribute_display_label( $attr );
				$gid   = self::label_identity( $label );
				if ( '' === $gid ) {
					$gid = 'key:' . $attr;
				}

				if ( ! isset( $groups[ $gid ] ) ) {
					$groups[ $gid ] = array(
						'label'      => $label,
						'keys'       => array(),
						'products'   => 0,
						'variations' => 0,
					);
				}

				$groups[ $gid ]['label']       = self::richer_label( $groups[ $gid ]['label'], $label );
				$groups[ $gid ]['keys'][]      = $attr;
				$groups[ $gid ]['products']   += (int) $row['products'];
				$groups[ $gid ]['variations'] += (int) $row['variations'];
			}

			// ادغام‌شده‌ها: شمارش دقیق (بدون دوباره‌شمردن محصول مشترک).
			foreach ( $groups as $gid => $group ) {
				if ( count( $group['keys'] ) < 2 ) {
					continue;
				}
				$count = self::scope_count( $scope, $group['keys'] );
				$groups[ $gid ]['products']   = $count['products'];
				$groups[ $gid ]['variations'] = $count['variations'];
			}

			$list = self::sort_labels_naturally( array_values( $groups ) );

			return array(
				'values'    => $list,
				'groups'    => self::brand_groups( $list, $attr_keys ),
				'truncated' => $truncated,
			);
		}

		/**
		 * مرتب‌سازی طبیعی (عددی) بر اساس برچسب: «A06» قبل از «A11» و «iPhone 9»
		 * قبل از «iPhone 12» می‌ایستد — همان ترتیبی که کاربر انتظار دارد.
		 *
		 * @param array $rows ردیف‌های دارای `label`.
		 * @return array
		 */
		private static function sort_labels_naturally( array $rows ) {
			usort(
				$rows,
				static function ( $a, $b ) {
					$la = self::label_identity( (string) $a['label'] );
					$lb = self::label_identity( (string) $b['label'] );
					$cmp = strnatcasecmp( $la, $lb );
					if ( 0 === $cmp ) {
						$cmp = strcmp( (string) $a['label'], (string) $b['label'] );
					}

					return $cmp;
				}
			);

			return array_values( $rows );
		}

		/**
		 * گروه‌بندی برندی مقدارها با موتور افزونهٔ «گروه‌بندی متغیرها بر اساس برند»
		 * (اگر فعال باشد) — همان تجربهٔ صفحهٔ محصول: گروه آیفون/سامسونگ/… .
		 * وقتی آن افزونه نباشد یا گروه‌بندی برای این ویژگی معنی نداشته باشد،
		 * فهرست تختِ مرتب‌شده برگردانده می‌شود.
		 *
		 * @param array $list      فهرست مقدارهای ادغام‌شده (دارای `label`).
		 * @param array $attr_keys کلیدهای ویژگی همان ردیف.
		 * @return array [{label, indexes}]
		 */
		private static function brand_groups( array $list, array $attr_keys ) {
			if ( ! class_exists( 'TCBV_Rules' ) || ! class_exists( 'TCBV_Settings' ) ) {
				return array();
			}
			if ( ! method_exists( 'TCBV_Rules', 'group' ) || count( $list ) < 2 ) {
				return array();
			}

			$labels = array();
			foreach ( $list as $row ) {
				$labels[] = (string) $row['label'];
			}

			$attr = isset( $attr_keys[0] ) ? (string) $attr_keys[0] : '';

			// تماس با کد افزونهٔ سوم: هم امضای سازگار لازم است و هم هیچ خطای
			// احتمالی‌اش نباید این درخواست را بیندازد — گارد امضا + try/catch.
			try {
				if ( ! self::tcbv_arity( 'should_group' ) || ! self::tcbv_arity( 'group', 1 ) ) {
					return array();
				}

				if ( method_exists( 'TCBV_Rules', 'should_group' ) && ! TCBV_Rules::should_group( $attr, $labels ) ) {
					return array();
				}

				$buckets = TCBV_Rules::group( $labels );
			} catch ( \Throwable $e ) {
				return array();
			}

			if ( ! is_array( $buckets ) || empty( $buckets ) ) {
				return array();
			}

			// نگاشت «هویت برچسب ← اندیس‌های فهرست» (برچسب تکراری هم پوشش داده می‌شود).
			$index_of = array();
			foreach ( $list as $index => $row ) {
				$index_of[ self::label_identity( (string) $row['label'] ) ][] = (int) $index;
			}

			$groups = array();
			foreach ( $buckets as $bucket ) {
				$indexes = array();
				foreach ( (array) $bucket['values'] as $label ) {
					$key = self::label_identity( (string) $label );
					if ( empty( $index_of[ $key ] ) ) {
						continue;
					}
					$index     = (int) array_shift( $index_of[ $key ] );
					$indexes[] = $index;
				}

				if ( empty( $indexes ) ) {
					continue;
				}

				$groups[] = array(
					'label'   => (string) $bucket['label'],
					'indexes' => array_values( array_unique( $indexes ) ),
				);
			}

			// گروه‌بندی فقط وقتی سودمند است که همهٔ مقدارها را پوشش دهد.
			$covered = 0;
			foreach ( $groups as $group ) {
				$covered += count( $group['indexes'] );
			}

			return ( $covered === count( $list ) ) ? $groups : array();
		}

		/**
		 * مقدارهای یک ویژگی (یا مجموعهٔ کلیدهای یک ویژگی ادغام‌شده) در محدوده.
		 *
		 * مقدارهایی که نوشتار خام یکسان یا برچسب یکسان دارند در **یک گزینه** ادغام
		 * می‌شوند و `values` همهٔ نوشتارهای خام را نگه می‌دارد.
		 *
		 * ترتیب فهرست **طبیعی/الفبایی** است نه بر اساس تعداد: در فهرست‌های بلند
		 * (مثل ۱۵۰+ مدل گوشی) مرتب‌سازی بر پایهٔ عددِ تکراری عملاً تصادفی به نظر
		 * می‌رسد و پیدا کردن مدل را سخت می‌کند. اگر افزونهٔ «گروه‌بندی متغیرها بر
		 * اساس برند» فعال باشد، همان گروه‌بندی برندِ صفحهٔ محصول (آیفون/سامسونگ/…)
		 * هم برگردانده می‌شود تا گزینه‌ها با `optgroup` دسته‌بندی شوند.
		 *
		 * @param array $scope     محدوده.
		 * @param array $attr_keys کلیدهای ویژگی.
		 * @return array {values: array, groups: array, truncated: bool}
		 */
		public static function list_scope_values( $scope, $attr_keys ) {
			$empty     = array(
				'values'    => array(),
				'groups'    => array(),
				'truncated' => false,
			);
			$attr_keys = self::sanitize_key_list( $attr_keys, TBSM_Core::MAX_MODEL_ATTRS );
			if ( empty( $attr_keys ) ) {
				return $empty;
			}

			global $wpdb;

			$scope = self::normalize_scope( $scope );
			$sql   = self::scope_sql( $scope );
			$base  = self::scope_base();

			$keys_ph = implode( ', ', array_fill( 0, count( $attr_keys ), '%s' ) );
			$params  = array_merge(
				array_map(
					static function ( $key ) {
						return 'attribute_' . $key;
					},
					$attr_keys
				),
				$sql['params']
			);
			$params[] = TBSM_Core::MAX_MODEL_VALUES + 1; // یکی بیشتر برای تشخیص بریدگی.

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT am.meta_value AS value,
						COUNT(DISTINCT am.post_id) AS variations,
						COUNT(DISTINCT p.ID) AS products
					{$base} {$sql['join']}
					WHERE am.meta_key IN ({$keys_ph}) AND am.meta_value <> '' {$sql['where']}
					GROUP BY am.meta_value
					ORDER BY products DESC, variations DESC, am.meta_value ASC
					LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$params
				),
				ARRAY_A
			);
			$rows = is_array( $rows ) ? $rows : array();

			$truncated = count( $rows ) > TBSM_Core::MAX_MODEL_VALUES;
			if ( $truncated ) {
				$rows = array_slice( $rows, 0, TBSM_Core::MAX_MODEL_VALUES );
			}

			$term_labels = self::global_term_labels( $attr_keys );

			// ادغام مقدارها: نوشتار خام یکسان **یا** برچسب یکسان → یک گزینه.
			$groups      = array();
			$by_value    = array();
			$by_identity = array();

			foreach ( $rows as $row ) {
				$value = (string) $row['value'];
				$label = ( isset( $term_labels[ $value ] ) && '' !== $term_labels[ $value ] )
					? $term_labels[ $value ]
					: self::clean_label( self::maybe_decode_label( $value ) );

				$identity = self::label_identity( $label );
				$index    = null;

				if ( isset( $by_value[ $value ] ) ) {
					$index = $by_value[ $value ];
				} elseif ( '' !== $identity && isset( $by_identity[ $identity ] ) ) {
					$index = $by_identity[ $identity ];
				}

				if ( null === $index ) {
					$index                = count( $groups );
					$groups[ $index ]     = array(
						'label'      => $label,
						'values'     => array(),
						'products'   => 0,
						'variations' => 0,
					);
				}

				if ( ! isset( $by_value[ $value ] ) ) {
					$by_value[ $value ] = $index;
				}
				if ( '' !== $identity && ! isset( $by_identity[ $identity ] ) ) {
					$by_identity[ $identity ] = $index;
				}

				$groups[ $index ]['label'] = self::richer_label( $groups[ $index ]['label'], $label );

				if ( ! in_array( $value, $groups[ $index ]['values'], true ) ) {
					$groups[ $index ]['values'][] = $value;
				}
				$groups[ $index ]['products']   += (int) $row['products'];
				$groups[ $index ]['variations'] += (int) $row['variations'];
			}

			foreach ( $groups as $index => $group ) {
				if ( count( $group['values'] ) < 2 ) {
					continue;
				}
				$count = self::scope_count( $scope, $attr_keys, $group['values'] );
				$groups[ $index ]['products']   = $count['products'];
				$groups[ $index ]['variations'] = $count['variations'];
			}

			$list = array_values( $groups );
			usort(
				$list,
				static function ( $a, $b ) {
					if ( $a['products'] === $b['products'] ) {
						return strcmp( (string) $a['label'], (string) $b['label'] );
					}
					return ( $a['products'] > $b['products'] ) ? -1 : 1;
				}
			);

			return $list;
		}

		/**
		 * شمارش دقیق محصول/متغیر برای مجموعه‌ای از کلیدهای ویژگی و (اختیاری)
		 * مقدارها در محدوده — برای ردیف‌های ادغام‌شده.
		 *
		 * @param array $scope     محدوده.
		 * @param array $attr_keys کلیدهای ویژگی.
		 * @param array $values    مقدارهای خام (خالی = همهٔ مقدارها).
		 * @return array {products, variations}
		 */
		private static function scope_count( $scope, array $attr_keys, array $values = array() ) {
			global $wpdb;

			$attr_keys = self::sanitize_key_list( $attr_keys, TBSM_Core::MAX_MODEL_ATTRS );
			$values    = self::sanitize_value_list( $values, TBSM_Core::MAX_MODEL_VALUES );
			if ( empty( $attr_keys ) ) {
				return array( 'products' => 0, 'variations' => 0 );
			}

			$sql = self::scope_sql( $scope );
			$base = self::scope_base();

			$meta_keys = array();
			foreach ( $attr_keys as $key ) {
				$meta_keys[] = 'attribute_' . $key;
			}

			$params  = array_merge( $meta_keys, $sql['params'] );
			$values_sql = '';
			if ( ! empty( $values ) ) {
				$values_sql = ' AND am.meta_value IN (' . implode( ', ', array_fill( 0, count( $values ), '%s' ) ) . ')';
				$params     = array_merge( $params, $values );
			}

			$keys_ph = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
			$query   = "SELECT COUNT(DISTINCT am.post_id) AS variations, COUNT(DISTINCT p.ID) AS products
				{$base} {$sql['join']}
				WHERE am.meta_key IN ({$keys_ph}){$values_sql} {$sql['where']}";

			$row = $wpdb->get_row( $wpdb->prepare( $query, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			return array(
				'products'   => (int) ( isset( $row['products'] ) ? $row['products'] : 0 ),
				'variations' => (int) ( isset( $row['variations'] ) ? $row['variations'] : 0 ),
			);
		}

		/**
		 * یافتن متغیرهای یک مدل (ویژگی + مقدار) در محدودهٔ انتخابی.
		 *
		 * @param array $scope      محدوده.
		 * @param array $attr_keys  کلیدهای ویژگی (هم‌معنا در یک ردیف ادغام‌شده).
		 * @param array $values     مقدارهای خام هم‌معنا.
		 * @return array {rows, products, truncated, error?}
		 */
		public static function find_scope_rows( $scope, array $attr_keys, array $values ) {
			$attr_keys = self::sanitize_key_list( $attr_keys, TBSM_Core::MAX_MODEL_ATTRS );
			$values    = self::sanitize_value_list( $values, TBSM_Core::MAX_MODEL_VALUES );

			if ( empty( $attr_keys ) || empty( $values ) ) {
				return array(
					'rows'      => array(),
					'products'  => 0,
					'truncated' => false,
					'error'     => 'ویژگی و مقدار مدل را انتخاب کنید.',
				);
			}

			$scope = self::normalize_scope( $scope );
			$error = self::scope_error( $scope );
			if ( '' !== $error ) {
				return array(
					'rows'      => array(),
					'products'  => 0,
					'truncated' => false,
					'error'     => $error,
				);
			}

			global $wpdb;

			$sql    = self::scope_sql( $scope );
			$limit  = TBSM_Core::MAX_MODEL_ROWS + 1; // یکی بیشتر برای تشخیص بریدگی فهرست.
			$params = array();

			$meta_keys = array();
			foreach ( $attr_keys as $key ) {
				$meta_keys[] = 'attribute_' . $key;
			}
			$params = array_merge( $params, $meta_keys, $values );

			$keys_ph   = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
			$values_ph = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
			$params    = array_merge( $params, $sql['params'] );
			$params[]  = $limit;

			// شناسهٔ والد با LEFT JOIN می‌آید تا محصول بدون SKU هم (در حالت «همه») حذف نشود.
			$query = "SELECT DISTINCT v.ID AS vid, v.post_parent AS pid, sk.meta_value AS psku, (sk.meta_value IS NULL) AS psku_null
				FROM {$wpdb->postmeta} am
				INNER JOIN {$wpdb->posts} v ON v.ID = am.post_id AND v.post_type = 'product_variation' AND v.post_status IN ('publish','draft','private','pending')
				INNER JOIN {$wpdb->posts} p ON p.ID = v.post_parent AND p.post_type = 'product' AND p.post_status IN ('publish','draft','pending')
				{$sql['join']}
				LEFT JOIN {$wpdb->postmeta} sk ON sk.post_id = p.ID AND sk.meta_key = '_sku'
				WHERE am.meta_key IN ({$keys_ph}) AND am.meta_value IN ({$values_ph}) {$sql['where']}
				ORDER BY psku_null ASC, psku ASC, vid ASC
				LIMIT %d";

			$found = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
		 * آیا این متغیر با مدل و محدودهٔ انتخابی هم‌خوان است؟ (اعتبارسنجی سرور)
		 *
		 * متغیر باید تحت **یکی از کلیدهای ویژگی** همان ردیف ادغام‌شده، یکی از
		 * مقدارهای انتخاب‌شده را داشته باشد و والدش هم در محدوده باشد.
		 *
		 * @param int   $var_id    شناسهٔ متغیر.
		 * @param array $scope     محدوده.
		 * @param array $attr_keys کلیدهای ویژگی.
		 * @param array $values    مقدارهای خام.
		 * @return bool
		 */
		private static function scope_item_allowed( $var_id, $scope, array $attr_keys, array $values ) {
			$var_id = absint( $var_id );
			if ( $var_id <= 0 || 'product_variation' !== get_post_type( $var_id ) ) {
				return false;
			}

			$matched = false;
			foreach ( $attr_keys as $attr_key ) {
				$stored = (string) get_post_meta( $var_id, 'attribute_' . $attr_key, true );
				if ( '' !== $stored && in_array( $stored, $values, true ) ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				return false;
			}

			$parent_id = (int) wp_get_post_parent_id( $var_id );
			if ( $parent_id <= 0 || 'product' !== get_post_type( $parent_id ) ) {
				return false;
			}

			switch ( $scope['type'] ) {
				case 'products':
					return in_array( $parent_id, array_map( 'absint', (array) $scope['product_ids'] ), true );

				case 'category':
					$term_ids = self::category_term_ids( (int) $scope['category_id'] );
					return ! empty( $term_ids ) && has_term( $term_ids, 'product_cat', $parent_id );

				case 'sku':
					$parent = wc_get_product( $parent_id );
					if ( ! $parent || ! $parent->exists() ) {
						return false;
					}
					$product_sku = (string) $parent->get_sku();
					if ( '' === $product_sku ) {
						return false;
					}
					return ( 'prefix' === $scope['sku_mode'] )
						? ( 0 === stripos( $product_sku, $scope['sku'] ) )
						: ( false !== stripos( $product_sku, $scope['sku'] ) );

				default:
					return true;
			}
		}

		/**
		 * شرط‌های SQL محدوده (جدول‌های posts/postmeta). جدول‌های اصلی:
		 * p = محصول والد، v = متغیر، am = متای ویژگی.
		 *
		 * @param array $scope محدودهٔ نرمال‌شده.
		 * @return array {join, where, params}
		 */
		private static function scope_sql( $scope ) {
			global $wpdb;

			$join   = '';
			$where  = '';
			$params = array();

			if ( 'products' === $scope['type'] && ! empty( $scope['product_ids'] ) ) {
				$ids    = array_map( 'absint', (array) $scope['product_ids'] );
				$where .= ' AND p.ID IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')';
				$params = array_merge( $params, $ids );
			} elseif ( 'category' === $scope['type'] ) {
				$term_ids = self::category_term_ids( (int) $scope['category_id'] );
				if ( empty( $term_ids ) ) {
					$where .= ' AND 1 = 0'; // دستهٔ نامعتبر → نتیجهٔ خالی
				} else {
					$join  .= " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID"
						. " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'";
					$where .= ' AND tt.term_id IN (' . implode( ', ', array_fill( 0, count( $term_ids ), '%d' ) ) . ')';
					$params = array_merge( $params, array_map( 'absint', $term_ids ) );
				}
			} elseif ( 'sku' === $scope['type'] && '' !== $scope['sku'] ) {
				$join    .= " INNER JOIN {$wpdb->postmeta} ssk ON ssk.post_id = p.ID AND ssk.meta_key = '_sku'";
				$where   .= ' AND ssk.meta_value LIKE %s';
				$params[] = ( 'prefix' === $scope['sku_mode'] )
					? $wpdb->esc_like( $scope['sku'] ) . '%'
					: '%' . $wpdb->esc_like( $scope['sku'] ) . '%';
			}

			return array(
				'join'   => $join,
				'where'  => $where,
				'params' => $params,
			);
		}

		/**
		 * FROM مشترک حالت گروهی: متای ویژگی ← متغیر ← محصول والد.
		 *
		 * @return string
		 */
		private static function scope_base() {
			global $wpdb;

			return "FROM {$wpdb->postmeta} am
				INNER JOIN {$wpdb->posts} v ON v.ID = am.post_id AND v.post_type = 'product_variation' AND v.post_status IN ('publish','draft','private','pending')
				INNER JOIN {$wpdb->posts} p ON p.ID = v.post_parent AND p.post_type = 'product' AND p.post_status IN ('publish','draft','pending')";
		}

		/**
		 * شناسهٔ یک دسته و همهٔ زیردسته‌هایش.
		 *
		 * @param int $term_id شناسهٔ دسته.
		 * @return int[]
		 */
		private static function category_term_ids( $term_id ) {
			$term_id = absint( $term_id );
			if ( $term_id <= 0 ) {
				return array();
			}

			$ids = array( $term_id );
			if ( function_exists( 'get_term_children' ) ) {
				$children = get_term_children( $term_id, 'product_cat' );
				if ( ! is_wp_error( $children ) ) {
					foreach ( (array) $children as $child ) {
						$ids[] = absint( $child );
					}
				}
			}

			return array_values( array_unique( array_filter( $ids ) ) );
		}

		/**
		 * نگاشت «مقدار متا ← نام ترم» برای ویژگی‌های سراسری ووکامرس (pa_*).
		 *
		 * @param string|array $attrs یک کلید یا فهرست کلیدهای ویژگی.
		 * @return array
		 */
		private static function global_term_labels( $attrs ) {
			$map = array();
			if ( ! function_exists( 'wc_attribute_label' ) ) {
				return $map;
			}

			foreach ( (array) $attrs as $attr ) {
				$attr = (string) $attr;
				if ( '' === $attr || ! taxonomy_exists( $attr ) ) {
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

				foreach ( $terms as $term ) {
					$slug = (string) $term->slug;
					if ( ! isset( $map[ $slug ] ) ) {
						$map[ $slug ] = self::clean_label( self::maybe_decode_label( (string) $term->name ) );
					}
				}
			}

			return $map;
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

		/**
		 * ارقام لاتین → فارسی برای متن‌های فارسی رابط (هماهنگ با fmt() در JS).
		 *
		 * @param string $text متن.
		 * @return string
		 */
		private static function fa_digits( $text ) {
			return str_replace(
				array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
				array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ),
				(string) $text
			);
		}

		/**
		 * از میان دو نوشتار هم‌هویت، نوشتار «غنی‌تر» (کامل‌تر) را برمی‌گرداند تا
		 * نیم‌فاصله/نویسهٔ نامرئیِ نوشتار بهتر در برچسب نهایی بماند.
		 *
		 * @param string $current   برچسب فعلی گروه.
		 * @param string $candidate برچسب تازه‌رسیده.
		 * @return string
		 */

		/**
		 * نسخهٔ کوتاه و تک‌خطی یک کوئری، برای گزارش تشخیص افزونه.
		 *
		 * @param string $query کوئری خام.
		 * @return string
		 */
		public static function short_query( $query ) {
			$query = (string) $query;
			$query = (string) preg_replace( '/\s+/', ' ', $query );
			if ( strlen( $query ) > 300 ) {
				$query = substr( $query, 0, 300 ) . '…';
			}

			return $query;
		}

		/**
		 * از میان دو نوشتار هم‌هویت، نوشتار «غنی‌تر» (کامل‌تر) را برمی‌گرداند تا
		 * نیم‌فاصله/نویسهٔ نامرئیِ نوشتار بهتر در برچسب نهایی بماند.
		 *
		 * @param string $current   برچسب فعلی گروه.
		 * @param string $candidate برچسب تازه‌رسیده.
		 * @return string
		 */
		private static function richer_label( $current, $candidate ) {
			$len_a = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $candidate, 'UTF-8' ) : strlen( (string) $candidate );
			$len_b = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $current, 'UTF-8' ) : strlen( (string) $current );
			if ( $len_a > $len_b ) {
				return (string) $candidate;
			}

			return (string) $current;
		}

		/**
		 * آیا متد افزونهٔ سوم با تعداد پارامترهای اجباریِ سازگار تعریف شده است؟
		 *
		 * اگر نسخهٔ نصب‌شدهٔ آن افزونه امضای دیگری داشته باشد، فراخوانی ما
		 * `ArgumentCountError` می‌دهد و درخواست می‌خوابد؛ این گارد جلوی آن را
		 * می‌گیرد و در بدترین حالت فهرست تختِ مرتب نمایش داده می‌شود.
		 *
		 * @param string $method       نام متد در TCBV_Rules.
		 * @param int    $max_required بیشترین پارامتر اجباری قابل‌قبول.
		 * @return bool
		 */
		private static function tcbv_arity( $method, $max_required = 2 ) {
			if ( ! class_exists( 'TCBV_Rules' ) || ! method_exists( 'TCBV_Rules', $method ) ) {
				return false;
			}

			try {
				$ref = new \ReflectionMethod( 'TCBV_Rules', $method );
			} catch ( \ReflectionException $e ) {
				return false;
			}

			return $ref->getNumberOfRequiredParameters() <= (int) $max_required;
		}

		/**
		 * «هویت» یک برچسب: دو نوشتار که به یک ویژگی/مقدار اشاره می‌کنند یک هویت
		 * می‌گیرند — «مدل»، «مدلِ» کدشده و «مدل» با نویسه‌های عربی/فاصلهٔ متفاوت.
		 *
		 * @param string $label برچسب.
		 * @return string
		 */
		private static function label_identity( $label ) {
			$label = self::clean_label( self::maybe_decode_label( self::strip_invisible( (string) $label ) ) );
			// در «هویت»، نیم‌فاصله/چسبان و نشانه‌های نامرئی تفاوتی ایجاد نمی‌کنند.
			$label = (string) preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}]/u', '', $label );
			if ( '' === $label ) {
				return '';
			}

			// نویسه‌های عربی/فارسی هم‌معنا (ي/ك عربی ← ی/ک فارسی، ة/ۀ ← ه) و فاصله‌های
			// چندگانه؛ نیم‌فاصله (ZWNJ) و کاراکترهای نامرئی پیش‌تر در strip_invisible()
			// حذف شده‌اند.
			$label = str_replace(
				array( 'ي', 'ك', 'ة', 'ۀ', 'ؤ' ),
				array( 'ی', 'ک', 'ه', 'ه', 'و' ),
				$label
			);
			$label = (string) preg_replace( '/\s+/u', ' ', $label );
			$label = trim( $label );

			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label );
		}

		/**
		 * پاک‌سازی فهرست کلیدهای ویژگی (حذف تکراری/خالی + سقف).
		 *
		 * @param array $keys  کلیدها.
		 * @param int   $limit سقف.
		 * @return array
		 */
		private static function sanitize_key_list( $keys, $limit ) {
			$out = array();
			foreach ( (array) $keys as $key ) {
				$key = self::sanitize_attribute_key( $key );
				if ( '' !== $key && ! in_array( $key, $out, true ) ) {
					$out[] = $key;
				}
				if ( count( $out ) >= (int) $limit ) {
					break;
				}
			}
			return $out;
		}

		/**
		 * پاک‌سازی فهرست مقدارهای خام (بدون آسیب به کاراکترهای کدشده/فاصله‌ها).
		 *
		 * @param array $values مقدارها.
		 * @param int   $limit  سقف.
		 * @return array
		 */
		private static function sanitize_value_list( $values, $limit ) {
			$out = array();
			foreach ( (array) $values as $value ) {
				$value = (string) preg_replace( '/[\x00-\x1F\x7F]+/', '', (string) $value );
				$value = substr( $value, 0, 190 );
				if ( '' !== $value && ! in_array( $value, $out, true ) ) {
					$out[] = $value;
				}
				if ( count( $out ) >= (int) $limit ) {
					break;
				}
			}
			return $out;
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
				$clean = self::clean_label( self::maybe_decode_label( ( '' !== (string) $label ) ? (string) $label : (string) $value ) );
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
			$label = self::strip_invisible( (string) $label );

			// جداکننده‌ها و فاصله‌های آویزان از ابتدا و انتها.
			$label = (string) preg_replace( '/^[\s\p{P}\p{S}]+/u', '', $label );
			$label = (string) preg_replace( '/[\s\p{P}\p{S}]+$/u', '', $label );

			return trim( $label );
		}

		/**
		 * حذف کاراکترهای صفرعرض/کنترلی (ZWSP، RTL marks، BOM) — بدون دست‌زدن
		 * به `%` و نویسه‌های داده، چون پیش از decode استفاده می‌شود. نیم‌فاصله (ZWNJ)
		 * و چسبان (ZWJ) حذف **نمی‌شوند** چون بخشی از نوشتار درست فارسی‌اند.
		 *
		 * @param string $label برچسب خام.
		 * @return string
		 */
		public static function strip_invisible( $label ) {
			$label = (string) $label;
			$label = (string) preg_replace( '/[\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $label );
			return $label;
		}

		/**
		 * بازیابی برچسبِ کدشده: برخی افزونه‌ها نام/برچسب ویژگی‌ها و مقادیر فارسی را
		 * percent-encoded می‌سازند — آن هم به شکل «ناقص»:
		 *   - نه هر بایت `%` دارد: d9%85d8%af%d9%84 (mdl)
		 *   - پیشوند داخلی ووکامرس چسبیده است: pa_d9%85d8%af%d9%84 / attribute_…
		 *   - جداکننده‌ها (`_`، `-`، `+`، فاصله) جای فاصله نشسته‌اند
		 *   - رشته ممکن است وسط یک نویسهٔ چندبایتی بریده باشد (طول فرد/UTF-8 ناتمام)
		 *   - گاهی دو بار کد شده است (double-encoding)
		 *
		 * decode فقط وقتی تلاش می‌شود که رشته واقعاً «شبیه blobِ hexی» باشد
		 * (حداقل یک `%` و ≥۹۰٪ نویسه‌های hex/جداکننده) و خروجی هم UTF-8 معتبر و
		 * متنِ چاپ‌پذیر باشد؛ در غیر این‌صورت برچسب عادی دست‌نخورده برمی‌گردد.
		 *
		 * @param string $label برچسب (ممکن است کدشده باشد).
		 * @return string
		 */
		public static function maybe_decode_label( $label ) {
			$raw = (string) $label;
			if ( '' === $raw ) {
				return $raw;
			}

			$work = self::strip_invisible( $raw );
			if ( '' === $work ) {
				return $raw;
			}
			if ( false === strpos( $work, '%' ) ) {
				return $work;
			}

			// پیشوندهای داخلی (pa_/attribute_) بخشی از برچسب نمایشی نیستند.
			$body = $work;
			if ( 0 === stripos( $body, 'attribute_' ) ) {
				$body = substr( $body, 10 );
			}
			if ( 0 === stripos( $body, 'pa_' ) ) {
				$body = substr( $body, 3 );
			}

			if ( '' !== $body && $body !== $work ) {
				$decoded = self::decode_label_text( $body );
				if ( '' !== $decoded ) {
					return $decoded;
				}
			}

			// تلاش با خود رشتهٔ کامل (شاید پیشوند هم بخشی از دادهٔ کدشده باشد).
			$decoded = self::decode_label_text( $work );
			if ( '' !== $decoded ) {
				return $decoded;
			}

			return $work;
		}

		/**
		 * تبدیل یک رشتهٔ کدشده به متن؛ رشتهٔ خالی یعنی «کدشده نبود/قابل‌بازیابی نبود».
		 *
		 * @param string $text رشته.
		 * @return string
		 */
		private static function decode_label_text( $text ) {
			$text = (string) $text;
			if ( ! self::looks_encoded( $text ) ) {
				return '';
			}

			$current = $text;
			for ( $pass = 0; $pass < 3; $pass++ ) {
				$decoded = self::decode_label_once( $current );
				if ( '' === $decoded ) {
					return ( $pass > 0 ) ? $current : '';
				}
				$current = $decoded;
				if ( ! self::looks_encoded( $current ) ) {
					break;
				}
			}

			return $current;
		}

		/**
		 * آیا رشته شبیه blobِ کدشدهٔ hexی است؟
		 *
		 * @param string $text رشته.
		 * @return bool
		 */
		private static function looks_encoded( $text ) {
			$text = (string) $text;
			if ( false === strpos( $text, '%' ) || strlen( $text ) < 4 ) {
				return false;
			}
			$hexed = (int) preg_match_all( '/[0-9a-fA-F]/', $text );
			$seps  = (int) preg_match_all( '/[%+ _\-\.~]/', $text );
			return ( $hexed + $seps ) >= (int) ceil( strlen( $text ) * 0.9 );
		}

		/**
		 * یک پاس decode (+ تلاش برای نجات رشته‌ای که وسط نویسهٔ چندبایتی بریده شده).
		 *
		 * @param string $text رشتهٔ کدشده.
		 * @return string
		 */
		private static function decode_label_once( $text ) {
			$marks = substr_count( $text, '%' );
			$len   = strlen( $text );

			// ۱) decode استاندارد (فقط %xx بایت می‌شود و بقیه دست‌نخورده می‌ماند) —
			//    لازم برای کدشده‌های کامل و double-encoded مثل %25d9%2585…
			$strict = rawurldecode( $text );
			if ( $strict !== $text && self::is_valid_utf8( $strict ) && self::is_displayable_text( $strict, $marks ) ) {
				$clean = self::clean_label( $strict );
				if ( '' !== $clean ) {
					return $clean;
				}
			}

			// ۲) decode جفت‌های hex (کدشدهٔ ناقص) + نجات رشتهٔ بریده‌شده
			for ( $drop = 0; $drop <= 6; $drop++ ) {
				$src = ( $drop > 0 ) ? substr( $text, 0, max( 0, $len - $drop ) ) : $text;
				if ( '' === $src ) {
					break;
				}
				$bytes = self::hex_stream( (string) $src );
				if ( '' === $bytes || ! self::is_valid_utf8( $bytes ) ) {
					continue;
				}
				if ( ! self::is_displayable_text( $bytes, $marks ) ) {
					continue;
				}
				$clean = self::clean_label( $bytes );
				if ( '' !== $clean ) {
					return $clean;
				}
			}

			return '';
		}

		/**
		 * جریان کدشده → بایت‌ها: `%xx` و جفت‌های hex بدون `%` هر دو بایت می‌شوند،
		 * جداکننده‌ها (`_ - +` و فاصله) فاصله می‌شوند و بقیه دور ریخته می‌شود.
		 *
		 * @param string $src رشتهٔ کدشده.
		 * @return string
		 */
		private static function hex_stream( $src ) {
			$out = '';
			$len = strlen( $src );
			$i   = 0;

			while ( $i < $len ) {
				$c = $src[ $i ];

				if ( '%' === $c ) {
					$pair = substr( $src, $i + 1, 2 );
					if ( 2 === strlen( $pair ) && ctype_xdigit( $pair ) ) {
						$out .= chr( (int) hexdec( $pair ) );
						$i   += 3;
						continue;
					}
					$i++;
					continue;
				}

				if ( ctype_xdigit( $c ) && ( $i + 1 ) < $len && ctype_xdigit( $src[ $i + 1 ] ) ) {
					$out .= chr( (int) hexdec( substr( $src, $i, 2 ) ) );
					$i   += 2;
					continue;
				}

				if ( ' ' === $c || "\t" === $c || '+' === $c || '-' === $c || '_' === $c ) {
					$out .= ' ';
					$i++;
					continue;
				}

				$i++;
			}

			return $out;
		}

		/**
		 * آیا بایت‌های decode‌شده متنِ نمایشی‌اند؟ (کنترل‌کاراکتر/آشغال نباشند)
		 *
		 * @param string $bytes بایت‌ها.
		 * @param int    $marks تعداد `%` در ورودی (برای پذیرش متن لاتینِ کدشده).
		 * @return bool
		 */
		private static function is_displayable_text( $bytes, $marks = 0 ) {
			$bytes = (string) $bytes;
			if ( '' === $bytes ) {
				return false;
			}
			if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $bytes ) ) {
				return false;
			}
			if ( preg_match( '/[\x80-\xFF]/', $bytes ) ) {
				return true;
			}
			// خروجی کاملاً ASCII: فقط با نشانهٔ قویِ کدشدگی می‌پذیریم.
			return ( $marks >= 3 && 1 === preg_match( '/[A-Za-z0-9]/', $bytes ) );
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
