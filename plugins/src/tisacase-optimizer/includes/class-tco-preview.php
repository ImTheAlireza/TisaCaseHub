<?php
/**
 * پیش‌نمایش و بندانگشتی امن فایل‌های خزانه (قرنطینه و پشتیبان‌های اولیه).
 *
 * چرا این فایل ساخته شد؟
 * در بخش «پشتیبان و بازگردانی» فایل‌ها عمداً از `uploads/` بیرون و داخل پوشهٔ
 * محافظت‌شدهٔ خزانه هستند؛ پس آدرس عمومی ندارند و کاربر قبل از بازگردانی
 * نمی‌توانست ببیند کدام تصویر را برمی‌گرداند. این کلاس یک نقطهٔ پایانی (endpoint)
 * کوچک روی `admin-ajax.php` می‌سازد که:
 *   ۱) فقط برای مدیر مجاز و با nonce معتبر کار می‌کند،
 *   ۲) فایل را با بررسی `realpath` فقط از داخل پوشهٔ خزانه می‌خواند،
 *   ۳) برای بندانگشتی، یک نسخهٔ کوچک (حداکثر ۳۲۰ پیکسل) می‌سازد و روی دیسک
 *      کش می‌کند تا هر تصویر فقط یک‌بار پردازش شود (بار سرور نزدیک صفر).
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Preview' ) ) {

	/**
	 * سرو کردن پیش‌نمایش و بندانگشتی فایل‌های خزانه.
	 */
	final class TisaCase_Optimizer_Preview {

		/** نام اکشن جدا (به‌جای مسیر JSON، چون پاسخ خروجی باینری تصویر است). */
		const ACTION = 'tisacase_optimizer_preview';

		/** ضلع بزرگ‌ترین بندانگشتی (پیکسل). */
		const THUMB_MAX = 320;

		/** سقف حجم فایلی که برای بندانگشتی رمزگشایی می‌شود (۲۵ مگابایت). */
		const THUMB_MAX_BYTES = 26214400;

		/** زیرپوشهٔ کش بندانگشتی داخل پوشهٔ ایندکس خزانه. */
		const THUMB_DIR = 'thumbs';

		/**
		 * ثبت اکشن.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'serve' ) );
		}

		/**
		 * ساخت آدرس پیش‌نمایش یک فایل خزانه.
		 *
		 * @param string $kind  نوع: `quarantine` (بستهٔ قرنطینه).
		 * @param string $item  شناسهٔ بسته.
		 * @param string $file  مسیر نسبی فایل داخل بسته (خالی = فایل اصلی).
		 * @param bool   $thumb آیا نسخهٔ بندانگشتی.
		 * @return string
		 */
		public static function url( $kind, $item, $file = '', $thumb = true ) {
			return add_query_arg(
				array(
					'action' => self::ACTION,
					'nonce'  => wp_create_nonce( TisaCase_Optimizer_Ajax::NONCE_NAME ),
					'kind'   => (string) $kind,
					// add_query_arg خودش مقدارها را کدگذاری می‌کند؛ کدگذاری دوباره
					// باعث `%25` و آدرس خراب می‌شد.
					'item'   => (string) $item,
					'file'   => (string) $file,
					'thumb'  => $thumb ? 1 : 0,
				),
				admin_url( 'admin-ajax.php' )
			);
		}

		/**
		 * سرو کردن فایل درخواستی (با هدرهای امن و بدون هیچ خروجی اضافه).
		 *
		 * @return void
		 */
		public static function serve() {
			if ( ! check_ajax_referer( TisaCase_Optimizer_Ajax::NONCE_NAME, 'nonce', false ) ) {
				status_header( 403 );
				exit;
			}
			if ( ! TisaCase_Optimizer_Ajax::user_can_access() ) {
				status_header( 403 );
				exit;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$kind  = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( (string) $_GET['kind'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$item  = isset( $_GET['item'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['item'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$file  = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['file'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$thumb = ! empty( $_GET['thumb'] );

			$abs = self::resolve_path( $kind, $item, $file );
			if ( '' === $abs || ! is_file( $abs ) ) {
				status_header( 404 );
				exit;
			}

			$is_image = self::is_image_file( $abs );
			if ( $thumb && $is_image ) {
				$cached = self::thumb_path( $abs );
				if ( '' !== $cached && is_file( $cached ) ) {
					self::stream( $cached, 'image/jpeg' );
				}
				$made = self::make_thumb( $abs, $cached );
				if ( $made && is_file( $cached ) ) {
					self::stream( $cached, 'image/jpeg' );
				}
			}

			// اگر بندانگشتی ممکن نشد، خودِ فایل سرو می‌شود تا جای عکس خالی نماند.
			$mime = $is_image ? self::mime_of( $abs ) : 'application/octet-stream';
			self::stream( $abs, $mime );
		}

		/**
		 * تبدیل (نوع، شناسه، فایل) به مسیر مطلق امن داخل خزانه.
		 *
		 * @param string $kind نوع.
		 * @param string $item شناسه.
		 * @param string $file مسیر نسبی.
		 * @return string مسیر امن یا رشتهٔ خالی.
		 */
		private static function resolve_path( $kind, $item, $file ) {
			if ( 'quarantine' === $kind ) {
				$dir = self::quarantine_dir( $item );
				if ( '' === $dir ) {
					return '';
				}
				// پیش‌فرض: فایل اصلی مانیفست؛ اگر نبود، نخستین فایل تصویری بسته.
				if ( '' === $file ) {
					$file = self::main_file_of( $dir );
				}
				return self::inside( $dir . '/files', $file );
			}

			if ( 'original' === $kind ) {
				$aid = absint( $item );
				if ( $aid <= 0 ) {
					return '';
				}
				$root = TisaCase_Optimizer_Vault::base_dir();
				if ( '' === $root ) {
					return '';
				}
				$dir = $root . '/' . TisaCase_Optimizer_Vault::SUB_ORIGINALS . '/' . $aid;
				if ( '' === $file ) {
					$file = self::first_file_in( $dir );
				}
				return self::inside( $dir, $file );
			}

			return '';
		}

		/**
		 * مسیر بستهٔ قرنطینه با اعتبارسنجی شناسه.
		 *
		 * @param string $item شناسهٔ بسته.
		 * @return string
		 */
		private static function quarantine_dir( $item ) {
			$item = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $item );
			if ( '' === $item ) {
				return '';
			}
			$root = TisaCase_Optimizer_Vault::base_dir();
			if ( '' === $root ) {
				return '';
			}
			$dir = $root . '/' . TisaCase_Optimizer_Vault::SUB_QUARANTINE . '/' . $item;
			return is_dir( $dir ) ? $dir : '';
		}

		/**
		 * فایل اصلی بسته از روی مانیفست.
		 *
		 * @param string $dir پوشهٔ بسته.
		 * @return string
		 */
		private static function main_file_of( $dir ) {
			$manifest = $dir . '/manifest.json';
			if ( is_file( $manifest ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$raw = @file_get_contents( $manifest );
				$arr = is_string( $raw ) ? json_decode( $raw, true ) : null;
				if ( is_array( $arr ) && ! empty( $arr['files'] ) && is_array( $arr['files'] ) ) {
					$main = isset( $arr['rel_path'] ) ? (string) $arr['rel_path'] : '';
					foreach ( $arr['files'] as $f ) {
						if ( ! is_array( $f ) || empty( $f['rel'] ) ) {
							continue;
						}
						if ( '' !== $main && (string) $f['rel'] === $main ) {
							return (string) $f['rel'];
						}
					}
					foreach ( $arr['files'] as $f ) {
						if ( is_array( $f ) && ! empty( $f['rel'] ) && self::is_image_file( $dir . '/files/' . $f['rel'] ) ) {
							return (string) $f['rel'];
						}
					}
					foreach ( $arr['files'] as $f ) {
						if ( is_array( $f ) && ! empty( $f['rel'] ) ) {
							return (string) $f['rel'];
						}
					}
				}
			}
			return self::first_file_in( $dir . '/files' );
		}

		/**
		 * نخستین فایل داخل یک پوشه (با پیمایش کم‌عمق).
		 *
		 * @param string $dir پوشه.
		 * @return string مسیر نسبی به همان پوشه.
		 */
		private static function first_file_in( $dir ) {
			if ( ! is_dir( $dir ) ) {
				return '';
			}
			$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! is_array( $entries ) ) {
				return '';
			}
			foreach ( $entries as $e ) {
				if ( '.' === $e || '..' === $e ) {
					continue;
				}
				$path = $dir . '/' . $e;
				if ( is_file( $path ) ) {
					return $e;
				}
				if ( is_dir( $path ) ) {
					$sub = self::first_file_in( $path );
					if ( '' !== $sub ) {
						return $e . '/' . $sub;
					}
				}
			}
			return '';
		}

		/**
		 * ترکیب مسیر پایه و مسیر نسبی با محافظت در برابر خروج از پوشه (`..`).
		 *
		 * @param string $base پوشهٔ مجاز.
		 * @param string $rel  مسیر نسبی.
		 * @return string
		 */
		private static function inside( $base, $rel ) {
			$rel = ltrim( wp_normalize_path( str_replace( array( '..', "\0" ), '', (string) $rel ) ), '/' );
			if ( '' === $rel ) {
				return '';
			}
			$abs  = wp_normalize_path( trailingslashit( $base ) . $rel );
			$real = realpath( $abs );
			$root = realpath( $base );
			if ( false === $real || false === $root ) {
				return '';
			}
			$real = wp_normalize_path( $real );
			$root = trailingslashit( wp_normalize_path( $root ) );
			return 0 === strpos( $real, $root ) ? $real : '';
		}

		/**
		 * مسیر کش بندانگشتی برای یک فایل.
		 *
		 * @param string $abs مسیر فایل اصلی.
		 * @return string
		 */
		private static function thumb_path( $abs ) {
			$root = TisaCase_Optimizer_Vault::base_dir();
			if ( '' === $root ) {
				return '';
			}
			$dir = $root . '/' . TisaCase_Optimizer_Vault::SUB_INDEX . '/' . self::THUMB_DIR;
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return '';
			}
			$key = md5( $abs . '|' . (int) @filemtime( $abs ) . '|' . (int) @filesize( $abs ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $dir . '/' . $key . '.jpg';
		}

		/**
		 * ساخت بندانگشتی (یک‌بار برای هر فایل؛ بعد از آن از کش خوانده می‌شود).
		 *
		 * @param string $src مسیر فایل اصلی.
		 * @param string $dst مسیر خروجی.
		 * @return bool
		 */
		private static function make_thumb( $src, $dst ) {
			if ( '' === $dst ) {
				return false;
			}
			if ( (int) @filesize( $src ) > self::THUMB_MAX_BYTES ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return false;
			}
			// کلیدها همان چیزی است که موتور تصویر انتظار دارد؛ کیفیت ۷۰ برای
			// بندانگشتی کافی است و حجم پاسخ را کوچک نگه می‌دارد.
			$cfg = array(
				'jpeg_quality'     => 70,
				'webp_quality'     => 70,
				'avif_quality'     => 55,
				'keep_icc_profile' => false,
				'strip_exif'       => true,
			);
			$ok = TisaCase_Optimizer_Image_Processor::encode_to_file( $src, $dst, 'jpeg', $cfg, self::THUMB_MAX, self::THUMB_MAX );
			return (bool) $ok && is_file( $dst );
		}

		/**
		 * آیا فایل تصویری است که مرورگر می‌تواند نمایش دهد؟
		 *
		 * @param string $abs مسیر.
		 * @return bool
		 */
		private static function is_image_file( $abs ) {
			$ext = strtolower( (string) pathinfo( $abs, PATHINFO_EXTENSION ) );
			return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp' ), true );
		}

		/**
		 * نوع MIME فایل تصویری.
		 *
		 * @param string $abs مسیر.
		 * @return string
		 */
		private static function mime_of( $abs ) {
			$ext  = strtolower( (string) pathinfo( $abs, PATHINFO_EXTENSION ) );
			$map  = array(
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'png'  => 'image/png',
				'gif'  => 'image/gif',
				'webp' => 'image/webp',
				'avif' => 'image/avif',
				'bmp'  => 'image/bmp',
			);
			return isset( $map[ $ext ] ) ? $map[ $ext ] : 'application/octet-stream';
		}

		/**
		 * ارسال فایل به مرورگر و پایان درخواست.
		 *
		 * @param string $path مسیر فایل.
		 * @param string $mime نوع محتوا.
		 * @return void
		 */
		private static function stream( $path, $mime ) {
			$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			nocache_headers();
			header( 'Content-Type: ' . $mime );
			header( 'Content-Length: ' . $size );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Content-Disposition: inline; filename="' . rawurlencode( basename( $path ) ) . '"' );
			// کش مرورگر کوتاه است تا پس از بازگردانی/حذف، تصویر قدیمی نماند.
			header( 'Cache-Control: private, max-age=300' );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile, WordPress.Security.EscapeOutput.OutputNotEscaped
			readfile( $path );
			exit;
		}
	}
}
