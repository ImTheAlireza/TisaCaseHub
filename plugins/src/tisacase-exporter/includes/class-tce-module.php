<?php
/**
 * کلاس پایهٔ «بخش»‌های خروجی (Module).
 *
 * هر بخش: طرح فیلتر، طرح ستون، کلیدهای یکتاسازی، شمارش و واکشی صفحه‌ای.
 * افزودن بخش جدید = یک کلاس که از همین کلاس ارث می‌برد + ثبت در TisaCase_Exporter_Modules.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Module' ) ) {

	abstract class TisaCase_Exporter_Module {

		/* -----------------------------------------------------------------
		 * قرارداد هر بخش
		 * ----------------------------------------------------------------- */

		/** شناسهٔ یکتای بخش (کلید در رجیستری). */
		abstract public static function id();

		/**
		 * عنوان، توضیح، آیکون، برچسب KPIها، فرمت پیش‌فرض و…
		 *
		 * @return array
		 */
		abstract public static function meta();

		/** طرح ستون‌ها: key => array( label, type, default ). */
		abstract public static function columns_schema();

		/** شمارش کل ردیف‌های خروجی با فیلترهای داده‌شده. */
		abstract public static function count( array $filters );

		/**
		 * واکشی یک صفحه.
		 *
		 * @return array { rows: array<int,array>, cursor: int|string, done: bool }
		 */
		abstract public static function fetch( array $filters, $cursor, $limit );

		/** طرح فیلترها برای UI و اعتبارسنجی. */
		public static function filters_schema() {
			return array();
		}

		/** کلیدهای یکتاسازی: key => label (خالی = بخش بدون یکتاسازی). */
		public static function dedup_keys() {
			return array();
		}

		/* -----------------------------------------------------------------
		* کمکی‌های عمومی
		* ----------------------------------------------------------------- */

		/** عنوان بخش. */
		public static function label() {
			$meta = static::meta();
			return isset( $meta['title'] ) ? (string) $meta['title'] : static::id();
		}

		/** همهٔ ستون‌ها با پیش‌فرض‌ها. */
		public static function columns() {
			$out = array();

			foreach ( static::columns_schema() as $key => $col ) {
				$out[ $key ] = array(
					'key'     => $key,
					'label'   => isset( $col['label'] ) ? (string) $col['label'] : $key,
					'type'    => isset( $col['type'] ) ? (string) $col['type'] : 'text',
					'default' => ! empty( $col['default'] ),
				);
			}

			return $out;
		}

		/** کلید ستون‌های پیش‌فرض (اگر هیچ‌کدام default نداشتند، سه ستون اول). */
		public static function default_columns( $format = '' ) {
			$keys = array();

			foreach ( static::columns() as $key => $col ) {
				if ( ! empty( $col['default'] ) ) {
					$keys[] = $key;
				}
			}

			if ( empty( $keys ) ) {
				$keys = array_slice( array_keys( static::columns() ), 0, 3 );
			}

			// TXT تک‌ستونه است؛ اگر فرمت پیش‌فرض بخش TXT بود و چند ستون انتخاب شده،
			// فقط اولین ستون پیش‌فرض می‌شود تا خروجی مثل نسخهٔ قبل تمیز بماند.
			if ( 'txt' === $format && count( $keys ) > 1 ) {
				$keys = array( $keys[0] );
			}

			return $keys;
		}

		/** پاک‌سازی انتخاب ستون‌ها (کلیدهای معتبر، به ترتیب طرح). */
		public static function sanitize_columns( $keys ) {
			$valid = array_keys( static::columns() );
			$keys  = is_array( $keys ) ? $keys : array();
			$out   = array();

			foreach ( $valid as $key ) {
				if ( in_array( $key, $keys, true ) ) {
					$out[] = $key;
				}
			}

			if ( empty( $out ) ) {
				$out = static::default_columns();
			}

			return $out;
		}

		/** تعریف کامل ستون‌های انتخاب‌شده (برای State/تاریخچه/هدر). */
		public static function columns_def( array $keys ) {
			$all = static::columns();
			$out = array();

			foreach ( $keys as $key ) {
				if ( isset( $all[ $key ] ) ) {
					$out[] = $all[ $key ];
				}
			}

			return $out;
		}

		/** برچسب ستون‌ها برای هدر فایل. */
		public static function headers( array $keys ) {
			$out = array();

			foreach ( static::columns_def( $keys ) as $col ) {
				$out[] = $col['label'];
			}

			return $out;
		}

		/* ---------------- فیلترها ---------------- */

		/**
		 * نرمال‌سازی فیلترهای خام (POST/CLI) بر اساس طرح.
		 *
		 * @param array $raw ورودی خام.
		 * @return array فیلترهای پاک.
		 */
		public static function normalize_filters( $raw ) {
			$raw  = is_array( $raw ) ? $raw : array();
			$out  = array();
			$plan = static::filters_schema();

			foreach ( $plan as $field ) {
				$name = isset( $field['name'] ) ? (string) $field['name'] : '';
				if ( '' === $name ) {
					continue;
				}

				$type    = isset( $field['type'] ) ? (string) $field['type'] : 'text';
				$value   = isset( $raw[ $name ] ) ? $raw[ $name ] : null;
				$default = isset( $field['default'] ) ? $field['default'] : '';

				switch ( $type ) {
					case 'date':
						$value = is_string( $value ) ? trim( $value ) : '';
						$out[ $name ] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
						break;

					case 'select':
						$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
						$value   = is_string( $value ) ? sanitize_key( $value ) : '';
						$out[ $name ] = array_key_exists( $value, $options ) ? $value : (string) $default;
						break;

					case 'multiselect':
						$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
						$value   = is_array( $value ) ? $value : array();
						$picked  = array();
						foreach ( $value as $item ) {
							$item = is_string( $item ) ? sanitize_key( $item ) : '';
							if ( '' !== $item && array_key_exists( $item, $options ) ) {
								$picked[] = $item;
							}
						}
						$out[ $name ] = array_values( array_unique( $picked ) );
						break;

					case 'number':
						$out[ $name ] = ( $value === null || '' === $value ) ? (float) $default : max( 0, (float) $value );
						break;

					case 'switch':
						$out[ $name ] = ! empty( $value );
						break;

					default: // text
						$out[ $name ] = is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';
						break;
				}
			}

			return $out;
		}

		/** خلاصهٔ فیلترهای فعال برای نمایش در تاریخچه (آرایهٔ رشته‌ها). */
		public static function filter_summary( array $filters ) {
			$out = array();

			foreach ( static::filters_schema() as $field ) {
				$name  = isset( $field['name'] ) ? (string) $field['name'] : '';
				$label = isset( $field['label'] ) ? (string) $field['label'] : $name;
				if ( '' === $name || ! isset( $filters[ $name ] ) ) {
					continue;
				}

				$value = $filters[ $name ];
				$type  = isset( $field['type'] ) ? (string) $field['type'] : 'text';

				switch ( $type ) {
					case 'switch':
						if ( ! empty( $value ) ) {
							$out[] = $label;
						}
						break;

					case 'multiselect':
						if ( ! empty( $value ) ) {
							$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
							$names   = array();
							foreach ( (array) $value as $item ) {
								$names[] = isset( $options[ $item ] ) ? wp_strip_all_tags( (string) $options[ $item ] ) : (string) $item;
							}
							$out[] = $label . ': ' . implode( '، ', $names );
						}
						break;

					case 'select':
						$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
						$default = isset( $field['default'] ) ? (string) $field['default'] : '';
						if ( (string) $value !== $default && isset( $options[ $value ] ) ) {
							$out[] = $label . ': ' . wp_strip_all_tags( (string) $options[ $value ] );
						}
						break;

					case 'number':
						if ( (float) $value > 0 ) {
							$out[] = $label . ': ' . number_format_i18n( (float) $value );
						}
						break;

					default:
						if ( '' !== (string) $value ) {
							$out[] = $label . ': ' . (string) $value;
						}
						break;
				}
			}

			return $out;
		}

		/* ---------------- ابزار دیتابیس ---------------- */

		/** تشخیص حافظه authoritative سفارش‌ها (HPOS یا Posts قدیمی). */
		public static function hpos_enabled() {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
				return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			}

			return false;
		}

		/** برچسب ذخیره‌سازی برای نمایش. */
		public static function storage_label() {
			return self::hpos_enabled() ? 'HPOS' : 'Legacy';
		}

		/** تبدیل تاریخ محلی به بازهٔ GMT. */
		protected static function gmt_bounds( array $filters ) {
			$from = '';
			$to   = '';

			/* «بدون محدودیت تاریخ» یعنی هر ردیف، هرچند قدیمی؛ پس بازه نادیده می‌رود. */
			if ( isset( $filters['date_mode'] ) && 'all' === $filters['date_mode'] ) {
				return array( '', '' );
			}

			if ( ! empty( $filters['date_from'] ) ) {
				$from = get_gmt_from_date( $filters['date_from'] . ' 00:00:00' );
			}

			if ( ! empty( $filters['date_to'] ) ) {
				$to = get_gmt_from_date( $filters['date_to'] . ' 23:59:59' );
			}

			return array( $from, $to );
		}

		/** فهرست همهٔ وضعیت‌های سفارش ووکامرس: key => label. */
		protected static function order_status_options() {
			$options = array();

			if ( function_exists( 'wc_get_order_statuses' ) ) {
				foreach ( wc_get_order_statuses() as $key => $label ) {
					$options[ $key ] = wp_strip_all_tags( (string) $label );
				}
			}

			return $options;
		}

		/** وضعیت‌های پیش‌فرض: همه، جز سطل زباله و سبدهای رهاشده. */
		protected static function default_statuses() {
			$skip = array( 'wc-trash', 'wc-checkout-draft', 'trash' );
			$out  = array();

			foreach ( self::order_status_options() as $key => $label ) {
				if ( ! in_array( $key, $skip, true ) ) {
					$out[] = $key;
				}
			}

			return $out;
		}

		/** ساخت رشتهٔ placeholder برای IN. */
		protected static function placeholders( array $values ) {
			return implode( ', ', array_fill( 0, max( 1, count( $values ) ), '%s' ) );
		}

		/** آیا جدول (با پیشوند) وجود دارد؟ نتیجه در همان درخواست کش می‌شود. */
		protected static function table_exists( $suffix ) {
			static $cache = array();
			global $wpdb;

			if ( isset( $cache[ $suffix ] ) ) {
				return $cache[ $suffix ];
			}

			$table = $wpdb->prefix . $suffix;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			$cache[ $suffix ] = ( $table === $found );

			return $cache[ $suffix ];
		}

		/**
		 * خواندن گروهی متای سفارش‌های Legacy برای یک Batch.
		 *
		 * @param array $ids  شناسهٔ سفارش‌ها.
		 * @param array $keys کلیدهای متا.
		 * @return array<int,array<string,string>> post_id => key => value
		 */
		protected static function legacy_meta_map( array $ids, array $keys ) {
			global $wpdb;

			$ids  = array_values( array_filter( array_map( 'absint', $ids ) ) );
			$keys = array_values( array_filter( array_map( 'strval', $keys ) ) );

			if ( empty( $ids ) || empty( $keys ) ) {
				return array();
			}

			$sql = 'SELECT post_id, meta_key, meta_value FROM ' . $wpdb->postmeta
				. ' WHERE post_id IN ( ' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ' )'
				. ' AND meta_key IN ( ' . self::placeholders( $keys ) . ' )';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $ids, $keys ) ), ARRAY_A );
			$map  = array();

			foreach ( (array) $rows as $row ) {
				$map[ (int) $row['post_id'] ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
			}

			return $map;
		}

		/**
		 * تعداد اقلام هر سفارش (جدول آیتم‌های ووکامرس) برای یک Batch.
		 *
		 * @param array $ids شناسهٔ سفارش‌ها.
		 * @return array<int,int>
		 */
		protected static function items_counts( array $ids ) {
			global $wpdb;

			$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

			if ( empty( $ids ) ) {
				return array();
			}

			$sql = 'SELECT order_id, COUNT(*) AS c FROM ' . $wpdb->prefix . 'woocommerce_order_items'
				. ' WHERE order_id IN ( ' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ' )'
				. " AND order_item_type = 'line_item' GROUP BY order_id";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A );
			$out  = array();

			foreach ( (array) $rows as $row ) {
				$out[ (int) $row['order_id'] ] = (int) $row['c'];
			}

			return $out;
		}
	}
}
