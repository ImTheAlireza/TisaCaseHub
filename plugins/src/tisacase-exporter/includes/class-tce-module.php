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

		/**
		 * WC_DateTime → رشتهٔ زمان GMT.
		 *
		 * Format::value برای نوع date فرض می‌کند ورودی GMT است و آن را به وقت محلی سایت
		 * تبدیل می‌کند؛ پس اینجا باید زمان واقعی UTC برگردد، نه `$date->date()` (که خودش
		 * وقت محلی می‌دهد و باعث تبدیل دوباره و ساعت اشتباه می‌شد).
		 *
		 * @param mixed $date شیء WC_DateTime.
		 * @return string
		 */
		protected static function gmt_string( $date ) {
			if ( is_object( $date ) && method_exists( $date, 'getTimestamp' ) ) {
				return gmdate( 'Y-m-d H:i:s', (int) $date->getTimestamp() );
			}

			if ( is_object( $date ) && method_exists( $date, 'date' ) ) {
				return (string) $date->date( 'Y-m-d H:i:s' );
			}

			return '';
		}

		/**
		 * وضعیت‌های مؤثر برای کوئری.
		 *
		 * قاعده:
		 * - اگر کاربر هیچ وضعیتی ارسال نکرده باشد (فیلد در فرم نبوده) ⇒ پیش‌فرض همهٔ وضعیت‌ها.
		 * - اگر کاربر «هیچ‌کدام» را زده باشد، فیلتر یک آرایهٔ خالی می‌شود و باید هیچ ردیفی
		 *   برنگردد (قبلاً در این حالت اشتباهاً «همهٔ وضعیت‌ها» خروجی می‌گرفت).
		 *
		 * @param array $filters فیلترهای نرمال‌شده.
		 * @return array<int,string>
		 */
		public static function effective_statuses( array $filters ) {
			if ( empty( $filters['statuses'] ) ) {
				if ( array_key_exists( 'statuses', $filters ) && is_array( $filters['statuses'] ) ) {
					// «هیچ‌کدام»: با یک مقدار بی‌همتا هیچ ردیفی مطابقت نمی‌کند.
					return array( '__tisacase_none__' );
				}

				return self::default_statuses();
			}

			return array_values( array_map( 'strval', (array) $filters['statuses'] ) );
		}

		/**
		 * شمارش سفارش‌ها به تفکیک وضعیت (بدون فیلتر تاریخ).
		 *
		 * @return array<string,int> به‌همراه کلید ویژهٔ `__sources__` برای مقایسهٔ HPOS/قدیمی.
		 */
		public static function order_status_counts() {
			global $wpdb;

			$out = array();

			if ( self::hpos_enabled() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					"SELECT status AS k, COUNT(*) AS c FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' GROUP BY status",
					ARRAY_A
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					"SELECT post_status AS k, COUNT(*) AS c FROM {$wpdb->posts} WHERE post_type = 'shop_order' GROUP BY post_status",
					ARRAY_A
				);
			}

			foreach ( (array) $rows as $row ) {
				$out[ (string) $row['k'] ] = (int) $row['c'];
			}

			$out['__sources__'] = self::storage_counts();

			return $out;
		}

		/** تعداد کل سفارش‌ها در هر دو منبع داده (برای مقایسه). */
		protected static function storage_counts() {
			global $wpdb;

			$prefix = $wpdb->prefix;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$hpos = self::table_exists( 'wc_orders' )
				? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}wc_orders WHERE type = 'shop_order'" )
				: 0;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$legacy = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order'" );

			return array(
				'hpos'   => $hpos,
				'legacy' => $legacy,
			);
		}

		/**
		 * آمار موبایل در محدودهٔ وضعیت/تاریخ جاری: کل، دارای شماره و شمارهٔ یکتا.
		 *
		 * @param array $filters فیلترها (وجود has_phone/min_total/نوع مشتری مهم نیست).
		 * @return array{total:int,with_phone:int,unique:int}
		 */
		public static function phone_stats( array $filters ) {
			global $wpdb;

			$statuses          = self::effective_statuses( $filters );
			list( $from, $to ) = self::gmt_bounds( $filters );
			$params            = array_merge( $statuses, array() );
			$extra             = '';

			if ( '' !== $from ) {
				$extra   .= ' AND date_created_gmt >= %s';
				$params[] = $from;
			}
			if ( '' !== $to ) {
				$extra   .= ' AND date_created_gmt <= %s';
				$params[] = $to;
			}

			if ( self::hpos_enabled() ) {
				// شماره‌ها را پس از یک لایهٔ داخلی به قالب canonical تبدیل می‌کنیم.
				$digits    = self::phone_digits_sql( static::hpos_phone_expr() );
				$canonical = self::phone_canonical_sql( 'raw.phone_digits' );

				$sql = "SELECT COUNT(DISTINCT source.order_id) AS total,"
					. " COUNT(DISTINCT CASE WHEN source.phone <> '' THEN source.order_id END ) AS with_phone,"
					. " COUNT(DISTINCT CASE WHEN source.phone <> '' THEN source.phone END ) AS uniq"
					. ' FROM ( SELECT raw.order_id, ' . $canonical . ' AS phone'
					. " FROM ( SELECT o.id AS order_id, {$digits} AS phone_digits"
					. " FROM {$wpdb->prefix}wc_orders o"
					. " LEFT JOIN {$wpdb->prefix}wc_order_addresses a ON a.order_id = o.id AND a.address_type = 'billing'"
					. " WHERE o.type = 'shop_order' AND o.status IN ( " . self::placeholders( $statuses ) . ' )' . $extra
					. ' ) AS raw ) AS source';
			} else {
				$extra2    = str_replace( 'date_created_gmt', 'p.post_date_gmt', $extra );
				$digits    = self::phone_digits_sql( "COALESCE( ph.meta_value, '' )" );
				$canonical = self::phone_canonical_sql( 'raw.phone_digits' );

				$sql = "SELECT COUNT(DISTINCT source.order_id) AS total,"
					. " COUNT(DISTINCT CASE WHEN source.phone <> '' THEN source.order_id END ) AS with_phone,"
					. " COUNT(DISTINCT CASE WHEN source.phone <> '' THEN source.phone END ) AS uniq"
					. ' FROM ( SELECT raw.order_id, ' . $canonical . ' AS phone'
					. " FROM ( SELECT p.ID AS order_id, {$digits} AS phone_digits"
					. " FROM {$wpdb->posts} p"
					. " LEFT JOIN {$wpdb->postmeta} ph ON ph.post_id = p.ID AND ph.meta_key = '_billing_phone'"
					. " WHERE p.post_type = 'shop_order' AND p.post_status IN ( " . self::placeholders( $statuses ) . ' )' . $extra2
					. ' ) AS raw ) AS source';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );

			return array(
				'total'      => isset( $row['total'] ) ? (int) $row['total'] : 0,
				'with_phone' => isset( $row['with_phone'] ) ? (int) $row['with_phone'] : 0,
				'unique'     => isset( $row['uniq'] ) ? (int) $row['uniq'] : 0,
			);
		}


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

		/* -----------------------------------------------------------------
		 * خواندن داده‌های صورتحساب در HPOS (با پشتیبان‌های چندمنبعی)
		 * ----------------------------------------------------------------- */

		/**
		 * پاک‌کردن رقم‌های فارسی/عربی و جداکننده‌های رایج از عبارت شماره.
		 * این عبارت در لایهٔ داخلی یک derived table به `phone_digits` نام‌گذاری می‌شود؛
		 * مرحلهٔ canonical بعدی فقط به alias کوتاه ارجاع می‌دهد و SQL باد نمی‌کند.
		 *
		 * @param string $expr عبارت SQL خام شماره.
		 * @return string عبارت SQL شامل ارقام ASCII و بدون جداکننده.
		 */
		protected static function phone_digits_sql( $expr ) {
			$sql = "COALESCE( ( {$expr} ), '' )";
			$fa  = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
			$ar  = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
			$en  = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );

			foreach ( array( $fa, $ar ) as $digit_set ) {
				foreach ( $digit_set as $index => $digit ) {
					$sql = "REPLACE( {$sql}, '{$digit}', '{$en[ $index ]}' )";
				}
			}

			foreach ( array( '+', ' ', '-', '(', ')', '.', '/', ',', "\xC2\xA0", "\xE2\x80\x8C", "\xE2\x80\x8E", "\xE2\x80\x8F" ) as $separator ) {
				$sql = "REPLACE( {$sql}, '{$separator}', '' )";
			}

			return "REPLACE( REPLACE( REPLACE( {$sql}, CHAR(9), '' ), CHAR(10), '' ), CHAR(13), '' )";
		}

		/**
		 * تبدیل alias ارقام به شمارهٔ canonical ایران (989xxxxxxxxx)، ورودی نامعتبر = خالی.
		 *
		 * @param string $expr alias SQL مانند `source.phone_digits`.
		 * @return string عبارت SQL.
		 */
		protected static function phone_canonical_sql( $expr ) {
			$number = (string) $expr;
			$number = "CASE WHEN LEFT({$number}, 2) = '00' THEN SUBSTRING({$number}, 3) ELSE {$number} END";
			$number = "CASE WHEN LEFT({$number}, 3) = '098' THEN SUBSTRING({$number}, 2) ELSE {$number} END";
			$number = "CASE WHEN LEFT({$number}, 2) = '98' AND LENGTH({$number}) > 10 THEN SUBSTRING({$number}, 3) ELSE {$number} END";
			$number = "CASE WHEN LEFT({$number}, 2) = '98' AND LENGTH({$number}) > 10 THEN SUBSTRING({$number}, 3) ELSE {$number} END";
			$number = "CASE WHEN LEFT({$number}, 2) = '98' AND LENGTH({$number}) > 10 THEN SUBSTRING({$number}, 3) ELSE {$number} END";
			$number = "CASE WHEN LEFT({$number}, 1) = '0' THEN SUBSTRING({$number}, 2) ELSE {$number} END";

			return "CASE WHEN LENGTH({$number}) = 10 AND LEFT({$number}, 1) = '9' THEN CONCAT('98', {$number}) ELSE '' END";
		}

		/**
		 * عبارت شمارهٔ موبایل صورتحساب در HPOS با سه منبع پشتیبان.
		 *
		 * ترتیب خواندن: جدول آدرس‌های سفارش (`wc_order_addresses.phone`) ← متای سفارش
		 * (`wc_orders_meta._billing_phone`) ← متای نوشتهٔ قدیمی (`wp_postmeta._billing_phone`).
		 *
		 * چرا؟ چون در سایت‌هایی که سفارش‌ها را ایمپورت/مهاجرت کرده‌اند یا فقط یک سمت داده
		 * پر است، خواندن تک‌منبعی باعث می‌شود سفارش‌های دارای شماره «بدون شماره» شمرده شوند
		 * (مثلاً ۳۰۰ سفارش در حال انجام ولی خروجی ۲۷ ردیف).
		 *
		 * پیش‌شرط: جدول‌ها با نام‌های `o` (سفارش) و `a` (آدرس) در کوئری حاضر باشند.
		 *
		 * @return string عبارت SQL.
		 */
		protected static function hpos_phone_expr() {
			global $wpdb;

			$meta  = $wpdb->prefix . 'wc_orders_meta';
			$posts = $wpdb->postmeta;

			return "COALESCE( NULLIF(a.phone, ''),"
				. " ( SELECT hm.meta_value FROM {$meta} hm WHERE hm.order_id = o.id AND hm.meta_key = '_billing_phone' LIMIT 1 ),"
				. " ( SELECT lp.meta_value FROM {$posts} lp WHERE lp.post_id = o.id AND lp.meta_key = '_billing_phone' LIMIT 1 ), '' )";
		}

		/**
		 * یک بخش از نام صورتحساب (نام یا نام خانوادگی) در HPOS با همان زنجیرهٔ پشتیبان.
		 *
		 * @param string $address_column ستون جدول آدرس‌ها (first_name/last_name).
		 * @param string $meta_key       کلید متا (_billing_first_name/…).
		 * @return string عبارت SQL.
		 */
		protected static function hpos_name_part_expr( $address_column, $meta_key ) {
			global $wpdb;

			$meta  = $wpdb->prefix . 'wc_orders_meta';
			$posts = $wpdb->postmeta;
			$col   = preg_replace( '/[^a-z_]/', '', (string) $address_column );
			$key   = esc_sql( (string) $meta_key );

			return "COALESCE( NULLIF(a.{$col}, ''),"
				. " ( SELECT hm.meta_value FROM {$meta} hm WHERE hm.order_id = o.id AND hm.meta_key = '{$key}' LIMIT 1 ),"
				. " ( SELECT lp.meta_value FROM {$posts} lp WHERE lp.post_id = o.id AND lp.meta_key = '{$key}' LIMIT 1 ), '' )";
		}

		/**
		 * شرط «سفارش شمارهٔ موبایل دارد» در HPOS — دقیقاً همان زنجیرهٔ `hpos_phone_expr()`،
		 * تا شمارش (KPI/پیش‌نمایش) و خروجی واقعی همیشه یک عدد بدهند.
		 *
		 * @return string شرط SQL (بدون AND ابتدایی).
		 */
		protected static function hpos_has_phone_condition() {
			return static::hpos_phone_expr() . " <> ''";
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
