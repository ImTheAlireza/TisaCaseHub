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

		/** شناسهٔ همهٔ کوپن‌ها (تعداد کوپن‌ها کوچک است؛ فیلترها در PHP اعمال می‌شود). */
		private static function all_ids() {
			if ( ! function_exists( 'get_posts' ) ) {
				return array();
			}

			return (array) get_posts(
				array(
					'post_type'      => 'shop_coupon',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
		}

		/** اعمال فیلترهای نوع/اعتبار روی یک کوپن. */
		private static function matches( $coupon, array $filters ) {
			if ( ! is_object( $coupon ) ) {
				return false;
			}

			if ( 'all' !== $filters['ctype'] && '' !== $filters['ctype'] && $coupon->get_discount_type() !== $filters['ctype'] ) {
				return false;
			}

			if ( 'all' !== $filters['cstatus'] && '' !== $filters['cstatus'] ) {
				$expires = $coupon->get_date_expires();
				$is_expired = false;

				if ( is_object( $expires ) && method_exists( $expires, 'getTimestamp' ) ) {
					$is_expired = $expires->getTimestamp() < time();
				}

				if ( 'expired' === $filters['cstatus'] && ! $is_expired ) {
					return false;
				}
				if ( 'active' === $filters['cstatus'] && $is_expired ) {
					return false;
				}
			}

			return true;
		}

		public static function count( array $filters ) {
			if ( ! class_exists( 'WC_Coupon' ) ) {
				return 0;
			}

			$total = 0;

			foreach ( self::all_ids() as $id ) {
				$coupon = new WC_Coupon( $id );
				if ( self::matches( $coupon, $filters ) ) {
					$total++;
				}
			}

			return $total;
		}

		public static function fetch( array $filters, $cursor, $limit, array $columns = array() ) {
			if ( ! class_exists( 'WC_Coupon' ) ) {
				return array( 'rows' => array(), 'cursor' => 1, 'done' => true );
			}

			$picked = array();

			foreach ( self::all_ids() as $id ) {
				$coupon = new WC_Coupon( $id );
				if ( self::matches( $coupon, $filters ) ) {
					$picked[] = $id;
				}
			}

			$total  = count( $picked );
			$offset = max( 0, absint( $cursor ) );
			$slice  = array_slice( $picked, $offset, (int) $limit );
			$rows   = array();

			foreach ( $slice as $id ) {
				$coupon = new WC_Coupon( $id );
				$rows[] = self::coupon_row( $coupon );
			}

			return array(
				'rows'   => $rows,
				'cursor' => $offset + (int) $limit,
				'done'   => ( $offset + (int) $limit ) >= $total,
			);
		}

		/** یک ردیف کوپن. */
		private static function coupon_row( $coupon ) {
			$expires = $coupon->get_date_expires();
			$created = $coupon->get_date_created();

			return array(
				'code'                 => $coupon->get_code(),
				'type'                 => $coupon->get_discount_type(),
				'amount'               => $coupon->get_amount(),
				'usage_count'          => (int) $coupon->get_usage_count(),
				'usage_limit'          => null === $coupon->get_usage_limit() ? '' : (int) $coupon->get_usage_limit(),
				'usage_limit_per_user' => null === $coupon->get_usage_limit_per_user() ? '' : (int) $coupon->get_usage_limit_per_user(),
				'minimum_amount'       => (float) $coupon->get_minimum_amount(),
				'maximum_amount'       => (float) $coupon->get_maximum_amount(),
				'date_expires'         => self::gmt_date( $expires ),
				'date_created'         => self::gmt_date( $created ),
				'free_shipping'        => $coupon->get_free_shipping(),
			);
		}

		/** تاریخ WC_DateTime → رشتهٔ GMT. */
		private static function gmt_date( $date ) {
			if ( is_object( $date ) && method_exists( $date, 'date' ) ) {
				return $date->date( 'Y-m-d H:i:s' );
			}

			return '';
		}
	}
}
