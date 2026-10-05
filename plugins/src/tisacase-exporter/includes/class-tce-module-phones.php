<?php
/**
 * بخش «شماره تماس‌ها» — خروجی مو‌به‌موی نسخهٔ ۱.x، حالا با فیلتر تاریخ/وضعیت.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Module_Phones' ) ) {

	final class TisaCase_Exporter_Module_Phones extends TisaCase_Exporter_Module {

		public static function id() {
			return 'phones';
		}

		public static function meta() {
			return array(
				'title'          => __( 'شماره تماس‌ها', TisaCase_Exporter::TEXT_DOMAIN ),
				'sub'            => __( 'شماره تماس صورتحساب سفارش‌ها، تبدیل‌شده به 989xxxxxxxxx — بدون سرستون و فقط شماره.', TisaCase_Exporter::TEXT_DOMAIN ),
				'icon'           => '<rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18h2"/>',
				'badge'          => 'ready',
				'default_format' => 'txt',
				'skip_when'      => 'phone',
				'default_dedup'  => 'phone', // مثل نسخهٔ ۱.x: حذف تکراری‌ها پیش‌فرض روشن است.
				'unit'           => __( 'شماره', TisaCase_Exporter::TEXT_DOMAIN ),
				'kpi'            => array(
					'processed'  => __( 'سفارش بررسی‌شده', TisaCase_Exporter::TEXT_DOMAIN ),
					'exported'   => __( 'شماره یکتا', TisaCase_Exporter::TEXT_DOMAIN ),
					'skipped'    => __( 'بدون شماره معتبر', TisaCase_Exporter::TEXT_DOMAIN ),
					'duplicates' => __( 'تکراری حذف‌شده', TisaCase_Exporter::TEXT_DOMAIN ),
				),
			);
		}

		public static function columns_schema() {
			return array(
				'phone'    => array( 'label' => __( 'موبایل (989xxxxxxx)', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'phone', 'default' => true ),
				'order_id' => array( 'label' => __( 'شماره سفارش', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'num' ),
				'date'     => array( 'label' => __( 'تاریخ سفارش', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'date' ),
				'status'   => array( 'label' => __( 'وضعیت سفارش', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'status' ),
				'name'     => array( 'label' => __( 'نام مشتری', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text' ),
				'total'    => array( 'label' => __( 'مبلغ سفارش', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'money' ),
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
			);
		}

		public static function dedup_keys() {
			return array( 'phone' => __( 'شماره موبایل', TisaCase_Exporter::TEXT_DOMAIN ) );
		}

		public static function count( array $filters ) {
			global $wpdb;

			$statuses = ! empty( $filters['statuses'] ) ? $filters['statuses'] : self::default_statuses();
			list( $from, $to ) = self::gmt_bounds( $filters );

			if ( self::hpos_enabled() ) {
				$sql    = "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders"
					. " WHERE type = 'shop_order' AND status IN ( " . self::placeholders( $statuses ) . ' )';
				$params = $statuses;

				if ( '' !== $from ) {
					$sql     .= ' AND date_created_gmt >= %s';
					$params[] = $from;
				}
				if ( '' !== $to ) {
					$sql     .= ' AND date_created_gmt <= %s';
					$params[] = $to;
				}
			} else {
				$sql    = "SELECT COUNT(*) FROM {$wpdb->posts}"
					. " WHERE post_type = 'shop_order' AND post_status IN ( " . self::placeholders( $statuses ) . ' )';
				$params = $statuses;

				if ( '' !== $from ) {
					$sql     .= ' AND post_date_gmt >= %s';
					$params[] = $from;
				}
				if ( '' !== $to ) {
					$sql     .= ' AND post_date_gmt <= %s';
					$params[] = $to;
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}

		public static function fetch( array $filters, $cursor, $limit, array $columns = array() ) {
			return self::hpos_enabled()
				? self::fetch_hpos( $filters, $cursor, $limit )
				: self::fetch_legacy( $filters, $cursor, $limit );
		}

		private static function fetch_hpos( array $filters, $cursor, $limit ) {
			global $wpdb;

			$statuses = ! empty( $filters['statuses'] ) ? $filters['statuses'] : self::default_statuses();
			list( $from, $to ) = self::gmt_bounds( $filters );

			$sql = "SELECT o.id AS order_id, o.status AS status, o.date_created_gmt AS date,"
				. ' o.total_amount AS total, a.phone AS phone,'
				. " CONCAT_WS(' ', a.first_name, a.last_name) AS name"
				. " FROM {$wpdb->prefix}wc_orders o"
				. " LEFT JOIN {$wpdb->prefix}wc_order_addresses a ON a.order_id = o.id AND a.address_type = 'billing'"
				. " WHERE o.type = 'shop_order' AND o.status IN ( " . self::placeholders( $statuses ) . ' )'
				. ' AND o.id > %d';

			$params = array_merge( $statuses, array( absint( $cursor ) ) );

			if ( '' !== $from ) {
				$sql     .= ' AND o.date_created_gmt >= %s';
				$params[] = $from;
			}
			if ( '' !== $to ) {
				$sql     .= ' AND o.date_created_gmt <= %s';
				$params[] = $to;
			}

			$sql .= ' ORDER BY o.id ASC LIMIT %d';
			$params[] = $limit;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

			return self::pack( $rows, $limit );
		}

		private static function fetch_legacy( array $filters, $cursor, $limit ) {
			global $wpdb;

			$statuses = ! empty( $filters['statuses'] ) ? $filters['statuses'] : self::default_statuses();
			list( $from, $to ) = self::gmt_bounds( $filters );

			$sql = "SELECT ID AS order_id, post_status AS status, post_date_gmt AS date"
				. " FROM {$wpdb->posts}"
				. " WHERE post_type = 'shop_order' AND post_status IN ( " . self::placeholders( $statuses ) . ' )'
				. ' AND ID > %d';

			$params = array_merge( $statuses, array( absint( $cursor ) ) );

			if ( '' !== $from ) {
				$sql     .= ' AND post_date_gmt >= %s';
				$params[] = $from;
			}
			if ( '' !== $to ) {
				$sql     .= ' AND post_date_gmt <= %s';
				$params[] = $to;
			}

			$sql .= ' ORDER BY ID ASC LIMIT %d';
			$params[] = $limit;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
			$ids  = array();

			foreach ( (array) $rows as $row ) {
				$ids[] = (int) $row['order_id'];
			}

			$meta = self::legacy_meta_map( $ids, array( '_billing_phone', '_billing_first_name', '_billing_last_name', '_order_total' ) );

			foreach ( (array) $rows as $i => $row ) {
				$id  = (int) $row['order_id'];
				$m   = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
				$rows[ $i ]['phone'] = isset( $m['_billing_phone'] ) ? $m['_billing_phone'] : '';
				$rows[ $i ]['name']  = trim( ( isset( $m['_billing_first_name'] ) ? $m['_billing_first_name'] : '' ) . ' ' . ( isset( $m['_billing_last_name'] ) ? $m['_billing_last_name'] : '' ) );
				$rows[ $i ]['total'] = isset( $m['_order_total'] ) ? $m['_order_total'] : '';
			}

			return self::pack( $rows, $limit );
		}

		/** بسته‌بندی خروجی: cursor = آخرین شناسه، done = کمتر از limit. */
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
