<?php
/**
 * کش «خلاصهٔ آماری + فهرست سبک تصاویر» برای پرهیز از رمزگشایی کاتالوگ کامل در هر درخواست.
 *
 * چرا این فایل ساخته شد؟
 * در فروشگاه‌های بزرگ (۲۰٬۰۰۰ تصویر) فایل کاتالوگ حدود ۱۲۰ تا ۲۰۰ مگابایت در
 * حافظه می‌شود و هر بازکردن صفحهٔ داشبورد آن را کامل از نو رمزگشایی می‌کرد؛
 * حتی برای نمایش چند عدد. حالا مجموع اعداد و یک فهرست بسیار سبک (شناسه، حجم،
 * وضعیت استفاده، بهینه بودن) یک‌بار ساخته و ذخیره می‌شود و تا وقتی کاتالوگ
 * تغییر نکرده باشد همان خوانده می‌شود.
 *
 * اصل ایمنی: این فایل **هرگز منبع حقیقت نیست**؛ فقط میان‌بُر است. هر تغییری در
 * کاتالوگ آن را باطل می‌کند و در صورت نبود/باطل‌بودن، مسیر قبلی (خواندن کاتالوگ
 * کامل) اجرا می‌شود؛ پس هیچ عددی قدیمی یا نادرست نمایش داده نمی‌شود.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Catalog_Summary' ) ) {

	/**
	 * نگه‌داری و اعتبارسنجی خلاصهٔ کاتالوگ.
	 */
	final class TisaCase_Optimizer_Catalog_Summary {

		/** نام فایل خلاصه در پوشهٔ خصوصی ایندکس. */
		const FILE = 'overview-cache.json';

		/** نسخهٔ ساختار. */
		const SCHEMA = 2;

		/** @var array<string,mixed>|null کش درون‌درخواستی */
		private static $cache = null;

		/**
		 * مسیر فایل خلاصه.
		 *
		 * @return string
		 */
		public static function path() {
			$root = TisaCase_Optimizer_Vault::base_dir();
			if ( '' === $root ) {
				return '';
			}
			return $root . '/' . TisaCase_Optimizer_Vault::SUB_INDEX . '/' . self::FILE;
		}

		/**
		 * مهر زمانی فایل کاتالوگ (برای اعتبارسنجی خلاصه).
		 *
		 * @return int
		 */
		private static function catalog_mtime() {
			$file = TisaCase_Optimizer_Reference_Scanner::catalog_path();
			if ( '' === $file || ! is_file( $file ) ) {
				return 0;
			}
			return (int) @filemtime( $file );
		}

		/**
		 * خواندن خلاصه در صورت معتبر بودن.
		 *
		 * @return array<string,mixed>|null
		 */
		public static function read() {
			if ( is_array( self::$cache ) ) {
				return self::valid( self::$cache ) ? self::$cache : null;
			}
			if ( false === self::$cache ) {
				return null;
			}
			$file = self::path();
			if ( '' === $file || ! is_file( $file ) ) {
				self::$cache = false;
				return null;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$raw = @file_get_contents( $file );
			if ( ! is_string( $raw ) || '' === $raw ) {
				self::$cache = false;
				return null;
			}
			if ( strlen( $raw ) > 2 && "\x1f\x8b" === substr( $raw, 0, 2 ) && function_exists( 'gzdecode' ) ) {
				$raw = (string) @gzdecode( $raw );
			}
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) || ! isset( $decoded['v'] ) || (int) $decoded['v'] !== self::SCHEMA ) {
				self::$cache = false;
				return null;
			}
			self::$cache = $decoded;
			return self::valid( $decoded ) ? $decoded : null;
		}

		/**
		 * اعتبارسنجی: خلاصه باید مربوط به همین نسخهٔ کاتالوگ باشد.
		 *
		 * @param array<string,mixed> $data خلاصه.
		 * @return bool
		 */
		private static function valid( array $data ) {
			if ( ! isset( $data['catalog_mtime'] ) || ! isset( $data['overview'] ) || ! is_array( $data['overview'] ) ) {
				return false;
			}
			return (int) $data['catalog_mtime'] === self::catalog_mtime();
		}

		/**
		 * خلاصهٔ آماری (بدون اعداد خزانه که زنده اضافه می‌شوند).
		 *
		 * @return array<string,mixed>|null
		 */
		public static function overview() {
			$data = self::read();
			return is_array( $data ) && isset( $data['overview'] ) ? (array) $data['overview'] : null;
		}

		/**
		 * فهرست سبک تصاویر برای ساخت صف عملیات.
		 *
		 * هر ردیف: `array( id, total_bytes, usage_status, is_optimized, format, is_opaque_png, exists )`
		 *
		 * @return array<int,array<int,mixed>>|null
		 */
		public static function rows() {
			$data = self::read();
			return is_array( $data ) && isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : null;
		}

		/**
		 * نوشتن خلاصه (پس از محاسبهٔ کامل آمار).
		 *
		 * @param array<string,mixed> $overview آمار کامل (شامل اعداد خزانه در زمان ساخت).
		 * @param array<int,array<string,mixed>> $items آیتم‌های کاتالوگ برای ساخت فهرست سبک.
		 * @return void
		 */
		public static function write( array $overview, array $items ) {
			$file = self::path();
			if ( '' === $file ) {
				return;
			}
			$mtime = self::catalog_mtime();
			if ( $mtime <= 0 ) {
				return;
			}

			$rows = array();
			foreach ( $items as $it ) {
				if ( ! is_array( $it ) || empty( $it['id'] ) ) {
					continue;
				}
				$rows[] = array(
					(int) $it['id'],
					(int) ( isset( $it['total_bytes'] ) ? $it['total_bytes'] : 0 ),
					(string) ( isset( $it['usage_status'] ) ? $it['usage_status'] : 'used' ),
					! empty( $it['is_optimized'] ) ? 1 : 0,
					(string) ( isset( $it['format'] ) ? $it['format'] : 'OTHER' ),
					! empty( $it['is_opaque_png'] ) ? 1 : 0,
					! empty( $it['exists'] ) ? 1 : 0,
				);
			}

			// اعداد خزانه در فایل ذخیره نمی‌شوند (ممکن است بدون تغییر کاتالوگ عوض شوند).
			$ov = $overview;
			unset( $ov['vault'], $ov['lifetime_stats'] );

			$payload = wp_json_encode(
				array(
					'v'             => self::SCHEMA,
					'catalog_mtime' => $mtime,
					'overview'      => $ov,
					'rows'          => $rows,
				),
				JSON_UNESCAPED_UNICODE
			);
			if ( false === $payload ) {
				return;
			}
			$data = function_exists( 'gzencode' ) ? @gzencode( $payload, 6 ) : $payload;
			if ( ! is_string( $data ) || '' === $data ) {
				$data = $payload;
			}
			$tmp = $file . '.tmp.' . wp_rand( 1000, 999999 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === @file_put_contents( $tmp, $data ) ) {
				return;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! @rename( $tmp, $file ) ) {
				wp_delete_file( $tmp );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				@file_put_contents( $file, $data );
			}
			self::$cache = null;
		}

		/**
		 * باطل‌کردن خلاصه (پس از هر تغییر در کاتالوگ).
		 *
		 * @return void
		 */
		public static function invalidate() {
			self::$cache = null;
			$file        = self::path();
			if ( '' !== $file && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
}
