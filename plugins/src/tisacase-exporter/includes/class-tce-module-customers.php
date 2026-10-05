<?php
/**
 * بخش «مشتری‌ها» — تجمیع سفارش‌ها بر اساس شماره موبایل صورتحساب.
 * یک ردیف برای هر شماره: تعداد سفارش، مجموع خرید، آخرین/اولین خرید و مشخصات.
 * مهمان‌ها هم حساب می‌شوند (چون کلید تجمیع، شماره است نه حساب کاربری).
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Module_Customers' ) ) {

	final class TisaCase_Exporter_Module_Customers extends TisaCase_Exporter_Module {

		public static function id() {
			return 'customers';
		}

		public static function meta() {
			return array(
				'title'          => __( 'مشتری‌ها', TisaCase_Exporter::TEXT_DOMAIN ),
				'sub'            => __( 'یک ردیف برای هر شماره موبایل: تعداد سفارش، مجموع خرید و آخرین خرید — شامل مشتری مهمان.', TisaCase_Exporter::TEXT_DOMAIN ),
				'icon'           => '<path d="M12 12a4 4 0 100-8 4 4 0 000 8zM4 21c0-4 3.6-6 8-6s8 2 8 6"/>',
				'badge'          => 'ready',
				'default_format' => 'csv',
				'unit'           => __( 'مشتری', TisaCase_Exporter::TEXT_DOMAIN ),
				'kpi'            => array(
					'processed'  => __( 'گروه شماره بررسی‌شده', TisaCase_Exporter::TEXT_DOMAIN ),
					'exported'   => __( 'مشتری یکتا', TisaCase_Exporter::TEXT_DOMAIN ),
					'skipped'    => __( 'بدون مقدار معتبر', TisaCase_Exporter::TEXT_DOMAIN ),
					'duplicates' => __( 'تکراری حذف‌شده', TisaCase_Exporter::TEXT_DOMAIN ),
				),
			);
		}

		public static function columns_schema() {
			return array(
				'phone'        => array( 'label' => __( 'موبایل (989xxxxxxx)', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'phone', 'default' => true ),
				'name'         => array( 'label' => __( 'نام', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text', 'default' => true ),
				'orders_count' => array( 'label' => __( 'تعداد سفارش', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'num', 'default' => true ),
				'total_spent'  => array( 'label' => __( 'مجموع خرید', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'money', 'default' => true ),
				'last_order'   => array( 'label' => __( 'آخرین خرید', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'date', 'default' => true ),
				'first_order'  => array( 'label' => __( 'اولین خرید', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'date' ),
				'email'        => array( 'label' => __( 'ایمیل', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text' ),
				'city'         => array( 'label' => __( 'شهر', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text' ),
				'state'        => array( 'label' => __( 'استان', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text' ),
				'company'      => array( 'label' => __( 'شرکت', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'text' ),
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
						'guest'      => __( 'فقط مهمان', TisaCase_Exporter::TEXT_DOMAIN ),
						'registered' => __( 'فقط کاربر ثبت‌نام‌شده', TisaCase_Exporter::TEXT_DOMAIN ),
					),
					'default' => 'all',
				),
				array( 'name' => 'min_orders', 'type' => 'number', 'label' => __( 'حداقل تعداد سفارش', TisaCase_Exporter::TEXT_DOMAIN ), 'default' => 0 ),
			);
		}

		public static function dedup_keys() {
			return array( 'phone' => __( 'شماره موبایل', TisaCase_Exporter::TEXT_DOMAIN ) );
		}

		/**
		 * عبارت SQL شمارهٔ موبایل در HPOS: اول جدول آدرس‌ها، بعد پشتیبان `wc_orders_meta`.
		 * (سایت‌هایی که سفارش‌ها را ایمپورت/مهاجرت کرده‌اند ممکن است ردیف آدرس نداشته باشند.)
		 */
		private static function phone_expr() {
			return self::hpos_phone_expr();
		}

		/** عبارت SQL یک بخش از نام (نام/نام خانوادگی) با زنجیرهٔ پشتیبان. */
		private static function name_part_expr( $address_column, $meta_key ) {
			return self::hpos_name_part_expr( $address_column, $meta_key );
		}

		/** شرط‌های WHERE/HAVING مشترک. */
		private static function sql_parts( array $filters, array &$params ) {			$statuses = self::effective_statuses( $filters );
			list( $from, $to ) = self::gmt_bounds( $filters );

			$params = array_merge( $params, $statuses );

			$where = ' AND o.status IN ( ' . self::placeholders( $statuses ) . ' )';

			if ( 'guest' === ( isset( $filters['customer_type'] ) ? $filters['customer_type'] : 'all' ) ) {
				$where .= ' AND o.customer_id = 0';
			} elseif ( 'registered' === ( isset( $filters['customer_type'] ) ? $filters['customer_type'] : 'all' ) ) {
				$where .= ' AND o.customer_id > 0';
			}
			if ( '' !== $from ) {
				$where   .= ' AND o.date_created_gmt >= %s';
				$params[] = $from;
			}
			if ( '' !== $to ) {
				$where   .= ' AND o.date_created_gmt <= %s';
				$params[] = $to;
			}

			$having = '';
			if ( ! empty( $filters['min_orders'] ) ) {
				$having = ' HAVING COUNT(DISTINCT o.id) >= %d';
			}

			return array( $where, $having );
		}

		private static function sql_parts_legacy( array $filters, array &$params ) {
			global $wpdb;

			$statuses = self::effective_statuses( $filters );
			list( $from, $to ) = self::gmt_bounds( $filters );

			$params = array_merge( $params, $statuses );

			$where = ' AND p.post_status IN ( ' . self::placeholders( $statuses ) . ' )';

			if ( 'guest' === ( isset( $filters['customer_type'] ) ? $filters['customer_type'] : 'all' ) ) {
				$where .= " AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} cu WHERE cu.post_id = p.ID AND cu.meta_key = '_customer_user' AND CAST(cu.meta_value AS UNSIGNED) = 0 )";
			} elseif ( 'registered' === ( isset( $filters['customer_type'] ) ? $filters['customer_type'] : 'all' ) ) {
				$where .= " AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} cu WHERE cu.post_id = p.ID AND cu.meta_key = '_customer_user' AND CAST(cu.meta_value AS UNSIGNED) > 0 )";
			}
			if ( '' !== $from ) {
				$where   .= ' AND p.post_date_gmt >= %s';
				$params[] = $from;
			}
			if ( '' !== $to ) {
				$where   .= ' AND p.post_date_gmt <= %s';
				$params[] = $to;
			}

			$having = '';
			if ( ! empty( $filters['min_orders'] ) ) {
				$having = ' HAVING COUNT(DISTINCT p.ID) >= %d';
			}

			return array( $where, $having );
		}

		public static function count( array $filters ) {
			global $wpdb;

			if ( self::hpos_enabled() ) {
				$params    = array();
				list( $where ) = self::sql_parts( $filters, $params );
				$digits    = self::phone_digits_sql( self::phone_expr() );
				$canonical = self::phone_canonical_sql( 'raw.phone_digits' );

				$sql = 'SELECT COUNT(*) FROM ('
					. ' SELECT normalized.phone FROM ('
					. ' SELECT raw.order_id, ' . $canonical . ' AS phone'
					. " FROM ( SELECT o.id AS order_id, {$digits} AS phone_digits"
					. " FROM {$wpdb->prefix}wc_orders o"
					. " LEFT JOIN {$wpdb->prefix}wc_order_addresses a ON a.order_id = o.id AND a.address_type = 'billing'"
					. " WHERE o.type = 'shop_order'" . $where . ' ) AS raw'
					. " ) AS normalized WHERE normalized.phone <> ''"
					. ' GROUP BY normalized.phone'
					. ( ! empty( $filters['min_orders'] ) ? ' HAVING COUNT(DISTINCT normalized.order_id) >= %d' : '' )
					. ' ) AS grouped';
			} else {
				$params    = array();
				list( $where ) = self::sql_parts_legacy( $filters, $params );
				$digits    = self::phone_digits_sql( 'pm.meta_value' );
				$canonical = self::phone_canonical_sql( 'raw.phone_digits' );

				$sql = 'SELECT COUNT(*) FROM ('
					. ' SELECT normalized.phone FROM ('
					. ' SELECT raw.order_id, ' . $canonical . ' AS phone'
					. " FROM ( SELECT p.ID AS order_id, {$digits} AS phone_digits"
					. " FROM {$wpdb->posts} p"
					. " INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_billing_phone' AND pm.meta_value <> ''"
					. " WHERE p.post_type = 'shop_order'" . $where . ' ) AS raw'
					. " ) AS normalized WHERE normalized.phone <> ''"
					. ' GROUP BY normalized.phone'
					. ( ! empty( $filters['min_orders'] ) ? ' HAVING COUNT(DISTINCT normalized.order_id) >= %d' : '' )
					. ' ) AS grouped';
			}

			if ( ! empty( $filters['min_orders'] ) ) {
				$params[] = (int) $filters['min_orders'];
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

			$params    = array();
			list( $where ) = self::sql_parts( $filters, $params );

			$phone     = self::phone_expr();
			$digits    = self::phone_digits_sql( $phone );
			$canonical = self::phone_canonical_sql( 'raw.phone_digits' );
			$first     = self::name_part_expr( 'first_name', '_billing_first_name' );
			$last      = self::name_part_expr( 'last_name', '_billing_last_name' );

			$raw_sql = "SELECT o.id AS order_id, {$digits} AS phone_digits,"
				. " {$first} AS first_name, {$last} AS last_name,"
				. ' a.email AS email, o.total_amount AS total_spent, o.date_created_gmt AS order_date,'
				. ' a.city AS city, a.state AS state, a.company AS company'
				. " FROM {$wpdb->prefix}wc_orders o"
				. " LEFT JOIN {$wpdb->prefix}wc_order_addresses a ON a.order_id = o.id AND a.address_type = 'billing'"
				. " WHERE o.type = 'shop_order'" . $where;

			$normalized_sql = 'SELECT raw.order_id, ' . $canonical . ' AS phone,'
				. " CONCAT_WS(' ', raw.first_name, raw.last_name) AS name, raw.email, raw.total_spent, raw.order_date,"
				. ' raw.city, raw.state, raw.company'
				. ' FROM (' . $raw_sql . ') AS raw';

			$sql = 'SELECT source.phone AS phone, MAX(source.name) AS name, MAX(source.email) AS email,'
				. ' COUNT(DISTINCT source.order_id) AS orders_count, SUM(source.total_spent) AS total_spent,'
				. ' MAX(source.order_date) AS last_order, MIN(source.order_date) AS first_order,'
				. ' MAX(source.city) AS city, MAX(source.state) AS state, MAX(source.company) AS company'
				. ' FROM (' . $normalized_sql . ') AS source'
				. " WHERE source.phone <> '' GROUP BY source.phone";

			$having_sql    = ' HAVING source.phone > %s';
			$having_params = array( (string) $cursor );

			if ( ! empty( $filters['min_orders'] ) ) {
				$having_sql     .= ' AND COUNT(DISTINCT source.order_id) >= %d';
				$having_params[] = (int) $filters['min_orders'];
			}

			$sql .= $having_sql . ' ORDER BY source.phone ASC LIMIT %d';

			$all_params = array_merge( $params, $having_params, array( (int) $limit ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $all_params ), ARRAY_A );

			return self::pack( $rows, $limit );
		}

		private static function fetch_legacy( array $filters, $cursor, $limit ) {
			global $wpdb;

			$params    = array();
			list( $where ) = self::sql_parts_legacy( $filters, $params );

			$digits    = self::phone_digits_sql( 'pm.meta_value' );
			$canonical = self::phone_canonical_sql( 'raw.phone_digits' );

			$raw_sql = "SELECT p.ID AS order_id, {$digits} AS phone_digits,"
				. ' fn.meta_value AS first_name, ln.meta_value AS last_name, em.meta_value AS email,'
				. ' tt.meta_value AS raw_total, p.post_date_gmt AS order_date,'
				. ' ci.meta_value AS city, st.meta_value AS state, co.meta_value AS company'
				. " FROM {$wpdb->posts} p"
				. " INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_billing_phone' AND pm.meta_value <> ''"
				. " LEFT JOIN {$wpdb->postmeta} tt ON tt.post_id = p.ID AND tt.meta_key = '_order_total'"
				. " LEFT JOIN {$wpdb->postmeta} em ON em.post_id = p.ID AND em.meta_key = '_billing_email'"
				. " LEFT JOIN {$wpdb->postmeta} fn ON fn.post_id = p.ID AND fn.meta_key = '_billing_first_name'"
				. " LEFT JOIN {$wpdb->postmeta} ln ON ln.post_id = p.ID AND ln.meta_key = '_billing_last_name'"
				. " LEFT JOIN {$wpdb->postmeta} ci ON ci.post_id = p.ID AND ci.meta_key = '_billing_city'"
				. " LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_billing_state'"
				. " LEFT JOIN {$wpdb->postmeta} co ON co.post_id = p.ID AND co.meta_key = '_billing_company'"
				. " WHERE p.post_type = 'shop_order'" . $where;

			$normalized_sql = 'SELECT raw.order_id, ' . $canonical . ' AS phone,'
				. " CONCAT_WS(' ', raw.first_name, raw.last_name) AS name, raw.email, raw.raw_total, raw.order_date,"
				. ' raw.city, raw.state, raw.company'
				. ' FROM (' . $raw_sql . ') AS raw';

			$sql = 'SELECT source.phone AS phone, MAX(source.name) AS name, MAX(source.email) AS email,'
				. ' COUNT(DISTINCT source.order_id) AS orders_count,'
				. " SUM(CAST(COALESCE(source.raw_total, '0') AS DECIMAL(20,6))) AS total_spent,"
				. ' MAX(source.order_date) AS last_order, MIN(source.order_date) AS first_order,'
				. ' MAX(source.city) AS city, MAX(source.state) AS state, MAX(source.company) AS company'
				. ' FROM (' . $normalized_sql . ') AS source'
				. " WHERE source.phone <> '' GROUP BY source.phone";

			$having_sql    = ' HAVING source.phone > %s';
			$having_params = array( (string) $cursor );

			if ( ! empty( $filters['min_orders'] ) ) {
				$having_sql     .= ' AND COUNT(DISTINCT source.order_id) >= %d';
				$having_params[] = (int) $filters['min_orders'];
			}

			$sql .= $having_sql . ' ORDER BY source.phone ASC LIMIT %d';

			$all_params = array_merge( $params, $having_params, array( (int) $limit ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $all_params ), ARRAY_A );

			return self::pack( $rows, $limit );
		}

		private static function pack( $rows, $limit ) {
			$rows   = is_array( $rows ) ? $rows : array();
			$cursor = '';

			foreach ( $rows as $row ) {
				$cursor = (string) $row['phone'];
			}

			return array(
				'rows'   => $rows,
				'cursor' => $cursor,
				'done'   => count( $rows ) < (int) $limit,
			);
		}
	}
}
