<?php
/**
 * بخش «سفارش‌ها» — یک ردیف برای هر سفارش، با فیلتر تاریخ/وضعیت/نوع مشتری/مبلغ.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Module_Orders' ) ) {

	final class TisaCase_Exporter_Module_Orders extends TisaCase_Exporter_Module {

		public static function id() {
			return 'orders';
		}

		public static function meta() {
			return array(
				'title'          => __( 'سفارش‌ها', TisaCase_Exporter::TEXT_DOMAIN ),
				'sub'            => __( 'یک ردیف برای هر سفارش: شناسه، تاریخ، وضعیت، مشتری، مبلغ، پرداخت و نشانی.', TisaCase_Exporter::TEXT_DOMAIN ),
				'icon'           => '<path d="M6 3h8l4 4v14H6zM14 3v4h4M9 12h6M9 16h6"/>',
				'badge'          => 'ready',
				'default_format' => 'csv',
				'unit'           => __( 'سفارش', TisaCase_Exporter::TEXT_DOMAIN ),
				'kpi'            => array(
					'processed'  => __( 'سفارش بررسی‌شده', TisaCase_Exporter::TEXT_DOMAIN ),
					'exported'   => __( 'ردیف خروجی', TisaCase_Exporter::TEXT_DOMAIN ),
					'skipped'    => __( 'ردیف کنارگذاشته', TisaCase_Exporter::TEXT_DOMAIN ),
					'duplicates' => __( 'تکراری حذف‌شده', TisaCase_Exporter::TEXT_DOMAIN ),
				),
			);
		}

		public static function columns_schema() {
			return array(
				'order_id'       => array( 'label' => __( 'شماره سفارش', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'num', 'default' => true ),
				'date'           => array( 'label' => __( 'تاریخ ثبت', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'date', 'default' => true ),
				'status'         => array( 'label' => __( 'وضعیت', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'status', 'default' => true ),
				'customer_name'  => array( 'label' => __( 'نام مشتری', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text', 'default' => true ),
				'phone'          => array( 'label' => __( 'موبایل (989xxxxxxx)', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'phone', 'default' => true ),
				'total'          => array( 'label' => __( 'مبلغ کل', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'money', 'default' => true ),
				'payment_method' => array( 'label' => __( 'روش پرداخت', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text', 'default' => true ),
				'items_count'    => array( 'label' => __( 'تعداد اقلام', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'num', 'default' => true ),
				'email'          => array( 'label' => __( 'ایمیل', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text' ),
				'city'           => array( 'label' => __( 'شهر', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text' ),
				'state'          => array( 'label' => __( 'استان', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text' ),
				'address'        => array( 'label' => __( 'نشانی', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text' ),
				'postcode'       => array( 'label' => __( 'کد پستی', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'code' ),
				'company'        => array( 'label' => __( 'شرکت', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text' ),
				'customer_note'  => array( 'label' => __( 'یادداشت مشتری', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text' ),
				'created_via'    => array( 'label' => __( 'کانال ثبت', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text' ),
			);
		}

		public static function filters_schema() {
			return array(
				array(
					'name'    => 'date_mode',
					'type'    => 'select',
					'label'   => __( 'بازهٔ تاریخ', TisaCase_Exporter::TEXT_DOMAIN ),
					'options' => array(
						'all'   => __( 'بدون محدودیت تاریخ (همه)', TisaCase_Exporter::TEXT_DOMAIN ),
						'range' => __( 'فقط بازهٔ زیر', TisaCase_Exporter::TEXT_DOMAIN ),
					),
					'default' => 'all',
				),
				array( 'name' => 'date_from', 'type' => 'date', 'label' => __( 'از تاریخ', TisaCase_Exporter::TEXT_DOMAIN ) ),
				array( 'name' => 'date_to', 'type' => 'date', 'label' => __( 'تا تاریخ', TisaCase_Exporter::TEXT_DOMAIN ) ),
				array(
					'name'    => 'statuses',
					'type'    => 'multiselect',
					'label'   => __( 'وضعیت سفارش', TisaCase_Exporter::TEXT_DOMAIN ),
					'options' => self::order_status_options(),
					'default' => self::default_statuses(),
				),
				array(
					'name'    => 'customer_type',
					'type'    => 'select',
					'label'   => __( 'نوع مشتری', TisaCase_Exporter::TEXT_DOMAIN ),
					'options' => array(
						'all'        => __( 'همه', TisaCase_Exporter::TEXT_DOMAIN ),
						'guest'      => __( 'مهمان', TisaCase_Exporter::TEXT_DOMAIN ),
						'registered' => __( 'کاربر ثبت‌نام‌شده', TisaCase_Exporter::TEXT_DOMAIN ),
					),
					'default' => 'all',
				),
				array( 'name' => 'min_total', 'type' => 'number', 'label' => __( 'حداقل مبلغ سفارش', TisaCase_Exporter::TEXT_DOMAIN ), 'default' => 0 ),
				array( 'name' => 'has_phone', 'type' => 'switch', 'label' => __( 'فقط سفارش‌های دارای موبایل', TisaCase_Exporter::TEXT_DOMAIN ), 'default' => false ),
			);
		}

		/** یکتاسازی پیش‌فرض ندارد (هر سفارش یک ردیف است). */
		public static function dedup_keys() {
			return array( 'phone' => __( 'شماره موبایل', TisaCase_Exporter::TEXT_DOMAIN ) );
		}

		public static function count( array $filters ) {
			global $wpdb;

			$statuses = ! empty( $filters['statuses'] ) ? $filters['statuses'] : self::default_statuses();
			list( $from, $to ) = self::gmt_bounds( $filters );
			$params   = array_merge( $statuses, array() );
			$sql      = '';

			if ( self::hpos_enabled() ) {
				$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders o";

				if ( ! empty( $filters['has_phone'] ) ) {
					$sql .= " INNER JOIN {$wpdb->prefix}wc_order_addresses a ON a.order_id = o.id AND a.address_type = 'billing'";
				}

				$sql .= " WHERE o.type = 'shop_order' AND o.status IN ( " . self::placeholders( $statuses ) . ' )';

				if ( ! empty( $filters['has_phone'] ) ) {
					$sql .= " AND a.phone <> ''";
				}
				if ( 'guest' === $filters['customer_type'] ) {
					$sql .= ' AND o.customer_id = 0';
				} elseif ( 'registered' === $filters['customer_type'] ) {
					$sql .= ' AND o.customer_id > 0';
				}
				if ( ! empty( $filters['min_total'] ) ) {
					$sql     .= ' AND o.total_amount >= %f';
					$params[] = (float) $filters['min_total'];
				}
				if ( '' !== $from ) {
					$sql     .= ' AND o.date_created_gmt >= %s';
					$params[] = $from;
				}
				if ( '' !== $to ) {
					$sql     .= ' AND o.date_created_gmt <= %s';
					$params[] = $to;
				}
			} else {
				$sql = "SELECT COUNT(*) FROM {$wpdb->posts} p"
					. " WHERE p.post_type = 'shop_order' AND p.post_status IN ( " . self::placeholders( $statuses ) . ' )';

				$sql .= self::legacy_filter_sql( $filters, $params, $from, $to );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}

		/** شرط‌های مشترک حالت Legacy (با EXISTS؛ بدون GROUP BY). */
		private static function legacy_filter_sql( array $filters, array &$params, $from, $to ) {
			global $wpdb;

			$sql = '';

			if ( ! empty( $filters['has_phone'] ) ) {
				$sql .= " AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ph WHERE ph.post_id = p.ID AND ph.meta_key = '_billing_phone' AND ph.meta_value <> '' )";
			}
			if ( 'guest' === $filters['customer_type'] ) {
				$sql .= " AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} cu WHERE cu.post_id = p.ID AND cu.meta_key = '_customer_user' AND CAST(cu.meta_value AS UNSIGNED) = 0 )";
			} elseif ( 'registered' === $filters['customer_type'] ) {
				$sql .= " AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} cu WHERE cu.post_id = p.ID AND cu.meta_key = '_customer_user' AND CAST(cu.meta_value AS UNSIGNED) > 0 )";
			}
			if ( ! empty( $filters['min_total'] ) ) {
				$sql     .= " AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} tt WHERE tt.post_id = p.ID AND tt.meta_key = '_order_total' AND CAST(tt.meta_value AS DECIMAL(20,6)) >= %f )";
				$params[] = (float) $filters['min_total'];
			}
			if ( '' !== $from ) {
				$sql     .= ' AND p.post_date_gmt >= %s';
				$params[] = $from;
			}
			if ( '' !== $to ) {
				$sql     .= ' AND p.post_date_gmt <= %s';
				$params[] = $to;
			}

			return $sql;
		}

		public static function fetch( array $filters, $cursor, $limit, array $columns = array() ) {
			return self::hpos_enabled()
				? self::fetch_hpos( $filters, $cursor, $limit, $columns )
				: self::fetch_legacy( $filters, $cursor, $limit, $columns );
		}

		private static function fetch_hpos( array $filters, $cursor, $limit, array $columns ) {
			global $wpdb;

			$statuses = ! empty( $filters['statuses'] ) ? $filters['statuses'] : self::default_statuses();
			list( $from, $to ) = self::gmt_bounds( $filters );
			$want     = static function ( $key ) use ( $columns ) {
				return empty( $columns ) || in_array( $key, $columns, true );
			};

			$sql = "SELECT o.id AS order_id, o.status AS status, o.date_created_gmt AS date,"
				. ' o.total_amount AS total, o.billing_email AS email, o.payment_method_title AS payment_method,'
				. ' o.customer_note AS customer_note, a.phone AS phone,'
				. " CONCAT_WS(' ', a.first_name, a.last_name) AS customer_name,"
				. ' a.city AS city, a.state AS state, a.address_1 AS address, a.postcode AS postcode, a.company AS company';

			if ( $want( 'items_count' ) ) {
				$sql .= ", ( SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_items i"
					. " WHERE i.order_id = o.id AND i.order_item_type = 'line_item' ) AS items_count";
			}

			if ( $want( 'created_via' ) && self::table_exists( 'wc_order_operational_data' ) ) {
				$sql .= ', op.created_via AS created_via';
			} else {
				$sql .= ", '' AS created_via";
			}

			$sql .= " FROM {$wpdb->prefix}wc_orders o"
				. " LEFT JOIN {$wpdb->prefix}wc_order_addresses a ON a.order_id = o.id AND a.address_type = 'billing'";

			if ( $want( 'created_via' ) && self::table_exists( 'wc_order_operational_data' ) ) {
				$sql .= " LEFT JOIN {$wpdb->prefix}wc_order_operational_data op ON op.order_id = o.id";
			}

			$sql .= " WHERE o.type = 'shop_order' AND o.status IN ( " . self::placeholders( $statuses ) . ' ) AND o.id > %d';
			$params = array_merge( $statuses, array( absint( $cursor ) ) );

			if ( ! empty( $filters['has_phone'] ) ) {
				$sql .= " AND a.phone <> ''";
			}
			if ( 'guest' === $filters['customer_type'] ) {
				$sql .= ' AND o.customer_id = 0';
			} elseif ( 'registered' === $filters['customer_type'] ) {
				$sql .= ' AND o.customer_id > 0';
			}
			if ( ! empty( $filters['min_total'] ) ) {
				$sql     .= ' AND o.total_amount >= %f';
				$params[] = (float) $filters['min_total'];
			}
			if ( '' !== $from ) {
				$sql     .= ' AND o.date_created_gmt >= %s';
				$params[] = $from;
			}
			if ( '' !== $to ) {
				$sql     .= ' AND o.date_created_gmt <= %s';
				$params[] = $to;
			}

			$sql     .= ' ORDER BY o.id ASC LIMIT %d';
			$params[] = $limit;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

			return self::pack( $rows, $limit );
		}

		private static function fetch_legacy( array $filters, $cursor, $limit, array $columns ) {
			global $wpdb;

			$statuses = ! empty( $filters['statuses'] ) ? $filters['statuses'] : self::default_statuses();
			list( $from, $to ) = self::gmt_bounds( $filters );
			$params   = array_merge( $statuses, array( absint( $cursor ) ) );

			$sql = "SELECT p.ID AS order_id, p.post_status AS status, p.post_date_gmt AS date"
				. " FROM {$wpdb->posts} p"
				. " WHERE p.post_type = 'shop_order' AND p.post_status IN ( " . self::placeholders( $statuses ) . ' )'
				. ' AND p.ID > %d';

			$sql .= self::legacy_filter_sql( $filters, $params, $from, $to );
			$sql .= ' ORDER BY p.ID ASC LIMIT %d';
			$params[] = $limit;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
			$ids  = array();

			foreach ( (array) $rows as $row ) {
				$ids[] = (int) $row['order_id'];
			}

			$meta = self::legacy_meta_map(
				$ids,
				array(
					'_billing_phone',
					'_billing_first_name',
					'_billing_last_name',
					'_billing_email',
					'_billing_city',
					'_billing_state',
					'_billing_address_1',
					'_billing_postcode',
					'_billing_company',
					'_order_total',
					'_payment_method_title',
					'_created_via',
					'_customer_note',
				)
			);

			$counts = ( empty( $columns ) || in_array( 'items_count', $columns, true ) ) ? self::items_counts( $ids ) : array();

			foreach ( (array) $rows as $i => $row ) {
				$id = (int) $row['order_id'];
				$m  = isset( $meta[ $id ] ) ? $meta[ $id ] : array();

				$rows[ $i ]['phone']          = isset( $m['_billing_phone'] ) ? $m['_billing_phone'] : '';
				$rows[ $i ]['customer_name']  = trim( ( isset( $m['_billing_first_name'] ) ? $m['_billing_first_name'] : '' ) . ' ' . ( isset( $m['_billing_last_name'] ) ? $m['_billing_last_name'] : '' ) );
				$rows[ $i ]['email']          = isset( $m['_billing_email'] ) ? $m['_billing_email'] : '';
				$rows[ $i ]['city']           = isset( $m['_billing_city'] ) ? $m['_billing_city'] : '';
				$rows[ $i ]['state']          = isset( $m['_billing_state'] ) ? $m['_billing_state'] : '';
				$rows[ $i ]['address']        = isset( $m['_billing_address_1'] ) ? $m['_billing_address_1'] : '';
				$rows[ $i ]['postcode']       = isset( $m['_billing_postcode'] ) ? $m['_billing_postcode'] : '';
				$rows[ $i ]['company']        = isset( $m['_billing_company'] ) ? $m['_billing_company'] : '';
				$rows[ $i ]['total']          = isset( $m['_order_total'] ) ? $m['_order_total'] : '';
				$rows[ $i ]['payment_method'] = isset( $m['_payment_method_title'] ) ? $m['_payment_method_title'] : '';
				$rows[ $i ]['created_via']    = isset( $m['_created_via'] ) ? $m['_created_via'] : '';
				$rows[ $i ]['customer_note']  = isset( $m['_customer_note'] ) ? $m['_customer_note'] : '';
				$rows[ $i ]['items_count']    = isset( $counts[ $id ] ) ? $counts[ $id ] : 0;
			}

			return self::pack( $rows, $limit );
		}

		private static function pack( $rows, $limit ) {
			$rows   = is_array( $rows ) ? $rows : array();
			$cursor = 0;

			foreach ( $rows as $row ) {
				$cursor = max( $cursor, (int) $row['order_id'] );
			}

			return array(
				'rows'   => $rows,
				'cursor' => $cursor,
				'done'   => count( $rows ) < (int) $limit,
			);
		}
	}
}
