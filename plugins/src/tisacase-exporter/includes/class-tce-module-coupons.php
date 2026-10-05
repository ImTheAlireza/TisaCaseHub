<?php
/**
 * بخش «کدهای تخفیف» — کد، نوع، مقدار، استفاده و محدودیت‌ها.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Module_Coupons' ) ) {

	final class TisaCase_Exporter_Module_Coupons extends TisaCase_Exporter_Module {

		public static function id() {
			return 'coupons';
		}

		public static function meta() {
			return array(
				'title'          => __( 'کدهای تخفیف', TisaCase_Exporter::TEXT_DOMAIN ),
				'sub'            => __( 'کد، نوع تخفیف، مقدار، تعداد استفاده، تاریخ انقضا و محدودیت‌های هر کوپن.', TisaCase_Exporter::TEXT_DOMAIN ),
				'icon'           => '<path d="M12 3H4v8l9 9 8-8zM8 8h.01"/>',
				'badge'          => 'ready',
				'default_format' => 'csv',
				'unit'           => __( 'کد', TisaCase_Exporter::TEXT_DOMAIN ),
				'kpi'            => array(
					'processed'  => __( 'کد بررسی‌شده', TisaCase_Exporter::TEXT_DOMAIN ),
					'exported'   => __( 'ردیف خروجی', TisaCase_Exporter::TEXT_DOMAIN ),
					'skipped'    => __( 'ردیف کنارگذاشته', TisaCase_Exporter::TEXT_DOMAIN ),
					'duplicates' => __( 'تکراری حذف‌شده', TisaCase_Exporter::TEXT_DOMAIN ),
				),
			);
		}

		public static function columns_schema() {
			return array(
				'code'                 => array( 'label' => __( 'کد', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'code', 'default' => true ),
				'type'                 => array( 'label' => __( 'نوع تخفیف', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'coupon_type', 'default' => true ),
				'amount'               => array( 'label' => __( 'مقدار', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'money', 'default' => true ),
				'usage_count'          => array( 'label' => __( 'تعداد استفاده', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'num', 'default' => true ),
				'usage_limit'          => array( 'label' => __( 'سقف استفاده', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'num', 'default' => true ),
				'usage_limit_per_user' => array( 'label' => __( 'سقف استفاده هر کاربر', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'num' ),
				'minimum_amount'       => array( 'label' => __( 'حداقل سبد', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'money' ),
				'maximum_amount'       => array( 'label' => __( 'حداکثر سبد', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'money' ),
				'date_expires'         => array( 'label' => __( 'تاریخ انقضا', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'date', 'default' => true ),
				'date_created'         => array( 'label' => __( 'تاریخ ساخت', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'date' ),
				'free_shipping'        => array( 'label' => __( 'ارسال رایگان', TisaCase_Exporter::TEXT_DOMAIN ), 'type' => 'bool' ),
			);
		}

		public static function filters_schema() {
			$types = array( 'all' => __( 'همه', TisaCase_Exporter::TEXT_DOMAIN ) );

			if ( function_exists( 'wc_get_coupon_types' ) ) {
				foreach ( wc_get_coupon_types() as $key => $label ) {
					$types[ $key ] = $label;
				}
			}

			return array(
				array(
					'name'    => 'ctype',
					'type'    => 'select',
					'label'   => __( 'نوع تخفیف', TisaCase_Exporter::TEXT_DOMAIN ),
					'options' => $types,
					'default' => 'all',
				),
				array(
					'name'    => 'cstatus',
					'type'    => 'select',
					'label'   => __( 'وضعیت اعتبار', TisaCase_Exporter::TEXT_DOMAIN ),
					'options' => array(
						'all'     => __( 'همه', TisaCase_Exporter::TEXT_DOMAIN ),
						'active'  => __( 'فقط منقضی‌نشده‌ها', TisaCase_Exporter::TEXT_DOMAIN ),
						'expired' => __( 'فقط منقضی‌شده‌ها', TisaCase_Exporter::TEXT_DOMAIN ),
					),
					'default' => 'all',
				),
			);
		}

		public static function dedup_keys() {
			return array( 'code' => __( 'کد کوپن', TisaCase_Exporter::TEXT_DOMAIN ) );
		}

		/** کلیدهای متا که برای شمارش/خروجی کوپن‌ها لازم است (بدون بارگذاری شیء ووکامرس). */
		private static function meta_keys() {
			return array(
				'discount_type',
				'_discount_type',
				'date_expires',
				'expiry_date',
				'coupon_amount',
				'usage_count',
				'usage_limit',
				'usage_limit_per_user',
				'minimum_amount',
				'maximum_amount',
				'free_shipping',
			);
		}

		/**
		 * کوئری مشترک شمارش/خواندن: هر ردیف یک کوپن با متاهای لازم.
		 *
		 * قبلاً برای هر کوپن یک شیء WC_Coupon ساخته می‌شد و در هر Batch هم همهٔ کوپن‌ها
		 * دوباره خوانده می‌شدند (روی کاتالوگ‌های بزرگ ⇒ کندی/تایم‌اوت). الان فیلتر
		 * نوع/انقضا در همین کوئری اعمال می‌شود و صفحه‌بندی بر اساس ID است.
		 *
		 * @param array $filters فیلترها.
		 * @param int   $cursor  آخرین ID دیده‌شده (۰ = از ابتدا).
		 * @param int   $limit   تعداد ردیف (۰ = بدون LIMIT، برای شمارش).
		 * @return array{0:string,1:array}
		 */
		private static function query( array $filters, $cursor, $limit ) {
			global $wpdb;

			$keys = self::meta_keys();
			// کلیدها دو بار در SQL می‌آیند: یک‌بار در ستون‌های SELECT و یک‌بار در شرط JOIN.
			$params = array_merge( $keys, $keys, array( absint( $cursor ) ) );
			$where  = self::placeholders( $keys );
			$having = array();

			$ctype = isset( $filters['ctype'] ) ? (string) $filters['ctype'] : 'all';

			if ( 'all' !== $ctype && '' !== $ctype ) {
				$having[] = 'discount_type = %s';
				$params[] = $ctype;
			}

			$cstatus = isset( $filters['cstatus'] ) ? (string) $filters['cstatus'] : 'all';

			if ( 'active' === $cstatus || 'expired' === $cstatus ) {
				// فقط مقادیر عددی (timestamp) انقضا معتبرند؛ مثل خواندن خودِ ووکامرس.
				$expired  = "( expires REGEXP '^[0-9]+$' AND CAST( expires AS UNSIGNED ) < UNIX_TIMESTAMP() )";
				$having[] = ( 'expired' === $cstatus ) ? $expired : "NOT {$expired}";
			}

			$sql = 'SELECT p.ID AS coupon_id, p.post_title AS code, p.post_date_gmt AS date_created,'
				. " MAX( CASE WHEN pm.meta_key IN ( %s, %s ) THEN pm.meta_value END ) AS discount_type,"
				. " MAX( CASE WHEN pm.meta_key IN ( %s, %s ) THEN pm.meta_value END ) AS expires,"
				. " MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS amount,"
				. " MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS usage_count,"
				. " MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS usage_limit,"
				. " MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS usage_limit_per_user,"
				. " MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS minimum_amount,"
				. " MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS maximum_amount,"
				. " MAX( CASE WHEN pm.meta_key = %s THEN pm.meta_value END ) AS free_shipping"
				. " FROM {$wpdb->posts} p"
				. " LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ( {$where} )"
				. " WHERE p.post_type = 'shop_coupon' AND p.post_status NOT IN ( 'trash', 'auto-draft' ) AND p.ID > %d"
				. ' GROUP BY p.ID';

			if ( ! empty( $having ) ) {
				$sql .= ' HAVING ' . implode( ' AND ', $having );
			}

			$sql .= ' ORDER BY p.ID ASC';

			if ( (int) $limit > 0 ) {
				$sql     .= ' LIMIT %d';
				$params[] = (int) $limit;
			}

			return array( $sql, $params );
		}

		public static function count( array $filters ) {
			global $wpdb;

			list( $sql, $params ) = self::query( $filters, 0, 0 );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ( {$sql} ) AS coupons", $params ) );
		}

		public static function fetch( array $filters, $cursor, $limit, array $columns = array() ) {
			global $wpdb;

			list( $sql, $params ) = self::query( $filters, $cursor, $limit );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
			$out  = array();
			$last = 0;

			foreach ( (array) $rows as $row ) {
				$out[] = self::coupon_row( $row );
				$last  = max( $last, (int) $row['coupon_id'] );
			}

			return array(
				'rows'   => $out,
				'cursor' => $last,
				'done'   => count( $out ) < (int) $limit,
			);
		}

		/** یک ردیف کوپن از دادهٔ خام دیتابیس. */
		private static function coupon_row( array $row ) {
			$expires = isset( $row['expires'] ) ? (string) $row['expires'] : '';
			$created = isset( $row['date_created'] ) ? (string) $row['date_created'] : '';
			$amount  = isset( $row['amount'] ) ? (string) $row['amount'] : '';

			return array(
				'code'                 => isset( $row['code'] ) ? (string) $row['code'] : '',
				'type'                 => isset( $row['discount_type'] ) ? (string) $row['discount_type'] : '',
				'amount'               => ( '' === $amount ) ? '' : (float) $amount,
				'usage_count'          => isset( $row['usage_count'] ) ? (int) $row['usage_count'] : 0,
				'usage_limit'          => ( isset( $row['usage_limit'] ) && '' !== (string) $row['usage_limit'] ) ? (int) $row['usage_limit'] : '',
				'usage_limit_per_user' => ( isset( $row['usage_limit_per_user'] ) && '' !== (string) $row['usage_limit_per_user'] ) ? (int) $row['usage_limit_per_user'] : '',
				'minimum_amount'       => ( isset( $row['minimum_amount'] ) && '' !== (string) $row['minimum_amount'] ) ? (float) $row['minimum_amount'] : '',
				'maximum_amount'       => ( isset( $row['maximum_amount'] ) && '' !== (string) $row['maximum_amount'] ) ? (float) $row['maximum_amount'] : '',
				'date_expires'         => ( '' !== $expires && ctype_digit( $expires ) ) ? gmdate( 'Y-m-d H:i:s', (int) $expires ) : '',
				'date_created'         => ( '' !== $created && '0000-00-00 00:00:00' !== $created ) ? $created : '',
				'free_shipping'        => isset( $row['free_shipping'] ) && 'yes' === (string) $row['free_shipping'],
			);
		}

	}
}
