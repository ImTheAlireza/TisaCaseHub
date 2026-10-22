<?php
/**
 * فهرست سبک «نسخه‌های موازی WebP/AVIF» برای تحویل بدون هزینه در فرانت‌اند.
 *
 * چرا این فایل ساخته شد؟
 * تحویل `<picture>` پیش‌تر برای هر تگ `<img>` در هر بازدید، دو فراخوانی
 * `is_file()` روی دیسک می‌زد (`.webp` و `.avif`). در صفحه‌ای با ۲۰۰ تصویر و
 * ۴۰۰ بازدید روزانه، این یعنی ده‌ها هزار فراخوانی سیستمی اضافی روی هاست
 * اشتراکی. اکنون یک فایل فهرست کوچک (فقط فایل‌هایی که نسخهٔ موازی دارند) یک‌بار
 * خوانده و در همان درخواست کش می‌شود؛ نتیجه: حداکثر یک خواندن فایل به‌جای دو
 * تماس دیسکی برای هر تصویر.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Sidecar_Index' ) ) {

	/**
	 * فهرست فایل‌های دارای نسخهٔ موازی + نگهداری خودکار آن.
	 */
	final class TisaCase_Optimizer_Sidecar_Index {

		/** نام فایل فهرست در پوشهٔ خصوصی ایندکس. */
		const FILE = 'sidecar-index.json';

		/** نسخهٔ ساختار داده. */
		const SCHEMA = 1;

		/** بیت‌های نوع نسخهٔ موازی. */
		const BIT_WEBP = 1;
		const BIT_AVIF = 2;

		/**
		 * فهرست بارگذاری‌شده در همین درخواست (null = هنوز خوانده نشده).
		 *
		 * @var array<string,int>|null
		 */
		private static $cache = null;

		/** آیا فایل فهرست روی دیسک وجود دارد؟ */
		private static $exists = null;

		/**
		 * حافظهٔ موقت نتیجهٔ is_file در همین درخواست (برای حالت بدون فهرست).
		 *
		 * @var array<string,bool>
		 */
		private static $probe = array();

		/**
		 * مسیر فایل فهرست.
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
		 * خواندن فهرست از دیسک (یک‌بار در هر درخواست).
		 *
		 * @return array<string,int>
		 */
		public static function load() {
			if ( is_array( self::$cache ) ) {
				return self::$cache;
			}
			self::$cache = array();
			$file        = self::path();
			if ( '' === $file || ! is_file( $file ) ) {
				self::$exists = false;
				return self::$cache;
			}
			self::$exists = true;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$raw = @file_get_contents( $file );
			if ( ! is_string( $raw ) || '' === $raw ) {
				return self::$cache;
			}
			if ( strlen( $raw ) > 2 && "\x1f\x8b" === substr( $raw, 0, 2 ) && function_exists( 'gzdecode' ) ) {
				$raw = (string) @gzdecode( $raw );
			}
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) || ! isset( $decoded['files'] ) || ! is_array( $decoded['files'] ) ) {
				return self::$cache;
			}
			foreach ( $decoded['files'] as $rel => $bits ) {
				self::$cache[ (string) $rel ] = (int) $bits;
			}
			return self::$cache;
		}

		/**
		 * آیا برای این مسیر نسبی، نسخهٔ موازی از نوع خواسته‌شده وجود دارد؟
		 *
		 * اگر فهرست ساخته نشده باشد، برای اطمینان از صحت، همان `is_file` قبلی
		 * انجام می‌شود (با حافظهٔ موقت تا تکرار نشود).
		 *
		 * @param string $rel مسیر نسبی داخل uploads (مثلاً `2026/03/a.jpg`).
		 * @param string $ext پسوند نسخهٔ موازی: `webp` یا `avif`.
		 * @return bool
		 */
		public static function has( $rel, $ext = 'webp' ) {
			$rel = ltrim( wp_normalize_path( (string) $rel ), '/' );
			if ( '' === $rel ) {
				return false;
			}
			$bit  = ( 'avif' === $ext ) ? self::BIT_AVIF : self::BIT_WEBP;
			$list = self::load();

			if ( self::$exists ) {
				return isset( $list[ $rel ] ) && ( $list[ $rel ] & $bit );
			}

			// فهرست موجود نیست: رفتار قبلی (is_file) با حافظهٔ درون‌درخواستی.
			$key = $rel . '|' . $ext;
			if ( ! isset( self::$probe[ $key ] ) ) {
				$uploads = wp_upload_dir();
				$abs     = ! empty( $uploads['basedir'] )
					? wp_normalize_path( trailingslashit( $uploads['basedir'] ) . $rel )
					: '';
				self::$probe[ $key ] = ( '' !== $abs && is_file( $abs . '.' . $ext ) );
			}
			return self::$probe[ $key ];
		}

		/**
		 * ثبت یک فایل به‌عنوان دارای نسخهٔ موازی (یا اصلاح وضعیت آن).
		 *
		 * @param string $rel مسیر نسبی.
		 * @param string $ext نوع نسخهٔ موازی.
		 * @param bool   $on  وجود دارد؟
		 * @return void
		 */
		public static function set( $rel, $ext = 'webp', $on = true ) {
			$rel = ltrim( wp_normalize_path( (string) $rel ), '/' );
			if ( '' === $rel ) {
				return;
			}
			$bit  = ( 'avif' === $ext ) ? self::BIT_AVIF : self::BIT_WEBP;
			$list = self::load();
			$bits = isset( $list[ $rel ] ) ? (int) $list[ $rel ] : 0;
			$new  = $on ? ( $bits | $bit ) : ( $bits & ~$bit );
			if ( $new === $bits ) {
				return;
			}
			self::$cache[ $rel ] = $new;
			self::persist();
		}

		/**
		 * بازخوانی وضعیت نسخهٔ موازی برای چند پیوست (پس از بهینه‌سازی/بازگردانی).
		 *
		 * @param int[] $attachment_ids شناسه‌های پیوست.
		 * @return void
		 */
		public static function refresh_for_ids( array $attachment_ids ) {
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ) );
			if ( empty( $ids ) ) {
				return;
			}
			$uploads = wp_upload_dir();
			if ( empty( $uploads['basedir'] ) ) {
				return;
			}
			$basedir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
			$list    = self::load();
			$changed = false;

			foreach ( $ids as $aid ) {
				$rel = ltrim( wp_normalize_path( (string) get_post_meta( $aid, '_wp_attached_file', true ) ), '/' );
				if ( '' === $rel ) {
					continue;
				}
				$abs  = $basedir . $rel;
				$bits = 0;
				if ( is_file( $abs . '.webp' ) ) {
					$bits |= self::BIT_WEBP;
				}
				if ( is_file( $abs . '.avif' ) ) {
					$bits |= self::BIT_AVIF;
				}
				$old = isset( $list[ $rel ] ) ? (int) $list[ $rel ] : 0;
				if ( $old === $bits ) {
					continue;
				}
				if ( 0 === $bits ) {
					unset( self::$cache[ $rel ] );
				} else {
					self::$cache[ $rel ] = $bits;
				}
				$changed = true;
			}

			if ( $changed ) {
				self::persist();
			}
		}

		/**
		 * حذف چند مسیر از فهرست (مثلاً پس از حذف تصویر).
		 *
		 * @param string[] $rels مسیرهای نسبی.
		 * @return void
		 */
		public static function forget_paths( array $rels ) {
			$list    = self::load();
			$changed = false;
			foreach ( $rels as $rel ) {
				$rel = ltrim( wp_normalize_path( (string) $rel ), '/' );
				if ( '' !== $rel && isset( self::$cache[ $rel ] ) ) {
					unset( self::$cache[ $rel ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				self::persist();
			}
		}

		/**
		 * ساخت کامل فهرست از کاتالوگ ذخیره‌شده (یک‌بار پس از هر اسکن کامل).
		 *
		 * @return array{files:int,checked:int}
		 */
		public static function rebuild() {
			$catalog = TisaCase_Optimizer_Reference_Scanner::read_persisted_catalog();
			self::$cache  = array();
			self::$exists = true;

			$checked = 0;
			if ( is_array( $catalog ) && ! empty( $catalog['items'] ) && is_array( $catalog['items'] ) ) {
				$uploads = wp_upload_dir();
				$basedir = ! empty( $uploads['basedir'] ) ? wp_normalize_path( trailingslashit( $uploads['basedir'] ) ) : '';
				if ( '' !== $basedir ) {
					foreach ( $catalog['items'] as $item ) {
						if ( ! is_array( $item ) || empty( $item['rel_path'] ) ) {
							continue;
						}
						++$checked;
						$abs  = $basedir . ltrim( wp_normalize_path( (string) $item['rel_path'] ), '/' );
						$bits = 0;
						if ( is_file( $abs . '.webp' ) ) {
							$bits |= self::BIT_WEBP;
						}
						if ( is_file( $abs . '.avif' ) ) {
							$bits |= self::BIT_AVIF;
						}
						if ( 0 !== $bits ) {
							self::$cache[ ltrim( wp_normalize_path( (string) $item['rel_path'] ), '/' ) ] = $bits;
						}
					}
				}
			}

			self::persist();
			return array(
				'files'   => count( self::$cache ),
				'checked' => $checked,
			);
		}

		/**
		 * نوشتن فهرست روی دیسک (فشرده، اتمیک و بی‌صدا در صورت خطا).
		 *
		 * @return bool
		 */
		private static function persist() {
			$file = self::path();
			if ( '' === $file || ! is_array( self::$cache ) ) {
				return false;
			}
			$payload = wp_json_encode(
				array(
					'v'     => self::SCHEMA,
					'files' => (object) self::$cache,
				),
				JSON_UNESCAPED_UNICODE
			);
			if ( false === $payload ) {
				return false;
			}
			$data = function_exists( 'gzencode' ) ? @gzencode( $payload, 6 ) : $payload;
			if ( ! is_string( $data ) || '' === $data ) {
				$data = $payload;
			}
			$tmp = $file . '.tmp.' . wp_rand( 1000, 999999 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === @file_put_contents( $tmp, $data ) ) {
				return false;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! @rename( $tmp, $file ) ) {
				wp_delete_file( $tmp );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return false !== @file_put_contents( $file, $data );
			}
			self::$exists = true;
			return true;
		}

		/**
		 * پاک‌سازی حافظهٔ درون‌درخواستی (برای تست و پس از تغییر بزرگ).
		 *
		 * @return void
		 */
		public static function flush_runtime_cache() {
			self::$cache  = null;
			self::$exists = null;
			self::$probe  = array();
		}

		/**
		 * حذف کامل فایل فهرست.
		 *
		 * @return void
		 */
		public static function delete_file() {
			$file = self::path();
			if ( '' !== $file && is_file( $file ) ) {
				wp_delete_file( $file );
			}
			self::flush_runtime_cache();
		}
	}
}
