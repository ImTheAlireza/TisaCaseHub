<?php
/**
 * قالب‌های خروجی: تبدیل مقدار، ساخت خط TSV داخلی و استریم فایل نهایی
 * (TXT · CSV · XLS یعنی SpreadsheetML 2003 · JSON · PDF) — بدون هیچ کتابخانهٔ بیرونی.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Format' ) ) {

	final class TisaCase_Exporter_Format {

		/** قالب‌های پشتیبانی‌شده: کلید => برچسب. */
		public static function formats() {
			return array(
				'txt'  => __( 'متن ساده (TXT)', TisaCase_Exporter::TEXT_DOMAIN ),
				'csv'  => __( 'CSV (اکسل/گوگل‌شیت)', TisaCase_Exporter::TEXT_DOMAIN ),
				'xls'  => __( 'اکسل (XLS)', TisaCase_Exporter::TEXT_DOMAIN ),
				'json' => __( 'JSON (مصرف ماشینی)', TisaCase_Exporter::TEXT_DOMAIN ),
				'pdf'  => __( 'PDF (چاپ و اشتراک‌گذاری)', TisaCase_Exporter::TEXT_DOMAIN ),
			);
		}

		/** اعتبارسنجی کلید قالب. */
		public static function is_valid( $format ) {
			$formats = self::formats();
			return is_string( $format ) && array_key_exists( $format, $formats );
		}

		/** پسوند فایل. */
		public static function ext( $format ) {
			$map = array( 'txt' => 'txt', 'csv' => 'csv', 'xls' => 'xls', 'json' => 'json', 'pdf' => 'pdf' );
			return isset( $map[ $format ] ) ? $map[ $format ] : 'txt';
		}

		/** پاک‌سازی سلول: بدون تب/خط جدید (تا ساختار TSV نشکند) و بدون تگ HTML. */
		public static function clean_cell( $value ) {
			$value = is_scalar( $value ) || null === $value ? (string) $value : '';
			$value = wp_strip_all_tags( $value );
			$value = str_replace( array( "\t", "\r\n", "\r", "\n" ), ' ', $value );
			$value = preg_replace( '/\s{2,}/u', ' ', $value );

			return trim( (string) $value );
		}

		/**
		 * تبدیل مقدار خام به مقدار قابل خروجی بر اساس نوع ستون.
		 *
		 * @param mixed  $value مقدار خام.
		 * @param string $type  text|phone|money|date|num|status|stock|coupon_type|bool|code.
		 * @return string
		 */
		public static function value( $value, $type = 'text' ) {
			switch ( $type ) {
				case 'phone':
					$phone = TisaCase_Exporter_Phone::normalize_phone( $value );
					return '' !== $phone ? $phone : self::clean_cell( $value );

				case 'money':
					$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
					return number_format( (float) $value, $decimals, '.', '' );

				case 'num':
					return (string) (int) $value;

				case 'date':
					$value = trim( (string) $value );
					if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
						return '';
					}
					$local = function_exists( 'get_date_from_gmt' ) ? get_date_from_gmt( $value, 'Y-m-d H:i' ) : $value;
					return self::clean_cell( $local );

				case 'status':
					$value = (string) $value;
					if ( function_exists( 'wc_get_order_status_name' ) ) {
						return self::clean_cell( wc_get_order_status_name( $value ) );
					}
					return self::clean_cell( str_replace( 'wc-', '', $value ) );

				case 'stock':
					return self::clean_cell( self::stock_label( $value ) );

				case 'coupon_type':
					if ( function_exists( 'wc_get_coupon_types' ) ) {
						$types = wc_get_coupon_types();
						if ( isset( $types[ $value ] ) ) {
							return self::clean_cell( $types[ $value ] );
						}
					}
					return self::clean_cell( $value );

				case 'bool':
					return ! empty( $value )
						? __( 'بله', TisaCase_Exporter::TEXT_DOMAIN )
						: __( 'خیر', TisaCase_Exporter::TEXT_DOMAIN );

				case 'code':
					return trim( (string) $value );

				default: // text
					return self::clean_cell( $value );
			}
		}

		/** برچسب وضعیت موجودی. */
		private static function stock_label( $status ) {
			$map = array(
				'instock'     => __( 'موجود', TisaCase_Exporter::TEXT_DOMAIN ),
				'outofstock'  => __( 'ناموجود', TisaCase_Exporter::TEXT_DOMAIN ),
				'onbackorder' => __( 'پیش‌خرید', TisaCase_Exporter::TEXT_DOMAIN ),
			);

			return isset( $map[ $status ] ) ? $map[ $status ] : (string) $status;
		}

		/** یک ردیف → خط TSV داخلی (بر اساس تعریف ستون‌ها). */
		public static function row_to_tsv( array $row, array $columns ) {
			$cells = array();

			foreach ( $columns as $col ) {
				$key   = isset( $col['key'] ) ? (string) $col['key'] : '';
				$type  = isset( $col['type'] ) ? (string) $col['type'] : 'text';
				$value = isset( $row[ $key ] ) ? $row[ $key ] : '';
				$cells[] = self::value( $value, $type );
			}

			return implode( "\t", $cells );
		}

		/** مقدار یک ستون مشخص از ردیف، به‌شکل نهایی (برای کلید یکتاسازی). */
		public static function column_value( array $row, $key, array $columns ) {
			foreach ( $columns as $col ) {
				if ( isset( $col['key'] ) && $col['key'] === $key ) {
					$value = isset( $row[ $key ] ) ? $row[ $key ] : '';
					return self::value( $value, isset( $col['type'] ) ? $col['type'] : 'text' );
				}
			}

			return '';
		}

		/** خطوط TSV → آرایهٔ مقادیر (برای جدول پیش‌نمایش/دانلود). */
		public static function split_tsv( $line ) {
			return explode( "\t", (string) $line );
		}

		/* -----------------------------------------------------------------
		 * استریم فایل نهایی
		 * قالب و Handle خروجی در stream_open() تعیین می‌شود و stream_row() /
		 * stream_close() از همان وضعیت استفاده می‌کنند (بنابراین دانلود
		 * می‌تواند همان جریان را روی یک فایل موقت هم بنویسد).
		 * ----------------------------------------------------------------- */

		/**
		 * شروع استریم فایل نهایی.
		 *
		 * @param string   $format txt|csv|xls|json|pdf
		 * @param array    $labels برچسب ستون‌ها (هدر CSV/XLS/PDF).
		 * @param array    $keys   کلید لاتین ستون‌ها (کلید شیء در JSON).
		 * @param resource $handle مقصد (اختیاری؛ پیش‌فرض خروجی استاندارد).
		 * @param array    $meta   فراداده (عنوان بخش، سایت، تاریخ — برای PDF).
		 */
		public static function stream_open( $format, array $labels, array $keys = array(), $handle = null, array $meta = array() ) {
			self::$format = self::is_valid( $format ) ? $format : 'csv';
			self::$keys   = array_values( array_map( 'strval', $keys ) );
			self::$meta   = $meta;
			self::$json_first = true;
			self::$out    = is_resource( $handle ) ? $handle : null;

			switch ( self::$format ) {
				case 'csv':
					self::write( "\xEF\xBB\xBF" ); // BOM تا اکسل فارسی درست باز کند.
					fputcsv( self::stdout(), $labels );
					break;

				case 'xls':
					self::write( '<?xml version="1.0" encoding="UTF-8"?>' );
					self::write( '<?mso-application progid="Excel.Sheet"?>' );
					self::write( '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' );
					self::write( ' xmlns:o="urn:schemas-microsoft-com:office:office"' );
					self::write( ' xmlns:x="urn:schemas-microsoft-com:office:excel"' );
					self::write( ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' );
					self::write( '<Worksheet ss:Name="Export"><Table>' );
					if ( ! empty( $labels ) ) {
						$cells = '';
						foreach ( $labels as $label ) {
							$cells .= '<Cell><Data ss:Type="String">' . self::xml_escape( $label ) . '</Data></Cell>';
						}
						self::write( '<Row>' . $cells . '</Row>' );
					}
					break;

				case 'json':
					self::write( '[' );
					break;

				case 'pdf':
					TisaCase_Exporter_Pdf::open( self::stdout(), $labels, self::$keys, self::$meta );
					break;

				default: // txt — بدون هدر (سازگاری کامل با خروجی نسخهٔ ۱.x).
					break;
			}
		}

		/**
		 * نوشتن یک ردیف در استریم باز.
		 *
		 * @param array $values مقادیر (به ترتیب ستون‌ها).
		 * @param array $keys   کلیدهای JSON (اختیاری؛ پیش‌فرض کلیدهای stream_open).
		 */
		public static function stream_row( array $values, array $keys = array() ) {
			if ( empty( $keys ) ) {
				$keys = self::$keys;
			}

			switch ( self::$format ) {
				case 'csv':
					fputcsv( self::stdout(), $values );
					break;

				case 'xls':
					$cells = '';
					foreach ( $values as $value ) {
						$cells .= '<Cell><Data ss:Type="String">' . self::xml_escape( $value ) . '</Data></Cell>';
					}
					self::write( '<Row>' . $cells . '</Row>' );
					break;

				case 'pdf':
					TisaCase_Exporter_Pdf::row( $values );
					break;

				case 'json':
					$assoc = array();
					foreach ( $values as $i => $value ) {
						$key = isset( $keys[ $i ] ) ? (string) $keys[ $i ] : (string) $i;
						$assoc[ $key ] = $value;
					}
					$json = wp_json_encode( $assoc, JSON_UNESCAPED_UNICODE );
					self::write( ( self::$json_first ? '' : ',' ) . ( false === $json ? '{}' : $json ) );
					self::$json_first = false;
					break;

				default: // txt — یک ردیف در هر خط، ستون‌ها با تب (تک‌ستونی = خروجی نسخهٔ ۱.x).
					self::write( implode( "\t", $values ) . "\n" );
					break;
			}
		}

		/** پایان استریم (بستن ساختار JSON/XLS). */
		public static function stream_close() {
			switch ( self::$format ) {
				case 'xls':
					self::write( '</Table></Worksheet></Workbook>' );
					break;

				case 'json':
					self::write( ']' );
					break;

				case 'pdf':
					TisaCase_Exporter_Pdf::close();
					break;

				default:
					break;
			}

			if ( is_resource( self::$out ) ) {
				fflush( self::$out );
			}

			self::$out = null;
		}

		/** خروجی استاندارد (در تست قابل جایگزینی با TISA_EXPORTER_STDOUT). */
		private static function stdout() {
			if ( is_resource( self::$out ) ) {
				return self::$out;
			}

			if ( defined( 'TISA_EXPORTER_STDOUT' ) && is_resource( TISA_EXPORTER_STDOUT ) ) {
				return TISA_EXPORTER_STDOUT;
			}

			if ( ! is_resource( self::$fallback ) ) {
				self::$fallback = fopen( 'php://output', 'wb' );
			}

			return self::$fallback;
		}

		/** نوشتن یک رشته در مقصد جاری. */
		private static function write( $string ) {
			$handle = self::stdout();

			if ( is_resource( $handle ) ) {
				fwrite( $handle, (string) $string );
			}
		}

		/** نوع MIME هر قالب. */
		public static function mime( $format ) {
			$map = array(
				'txt'  => 'text/plain; charset=UTF-8',
				'csv'  => 'text/csv; charset=UTF-8',
				'xls'  => 'application/vnd.ms-excel; charset=UTF-8',
				'json' => 'application/json; charset=UTF-8',
				'pdf'  => 'application/pdf',
			);

			return isset( $map[ $format ] ) ? $map[ $format ] : 'text/plain; charset=UTF-8';
		}

		private static function xml_escape( $value ) {
			return htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		}

		/** قالب جاری استریم. */
		private static $format = 'csv';

		/** کلیدهای JSON. */
		private static $keys = array();

		/** فرادادهٔ خروجی (عنوان بخش، سایت…). */
		private static $meta = array();

		/** مقصد جاری (null = خروجی استاندارد). */
		private static $out = null;

		/** Handle خروجی استاندارد. */
		private static $fallback = null;

		/** وضعیت «اولین ردیف» برای JSON. */
		private static $json_first = true;
	}
}
