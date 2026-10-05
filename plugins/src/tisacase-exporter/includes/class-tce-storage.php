<?php
/**
 * ذخیره‌سازی فایل: مسیر امن (اول uploads، بعد tmp)، محافظت پوشه،
 * پوشهٔ تصادفی هر جلسه، پاک‌سازی خودکار (Cron Sweep) و کنترل مسیر مجاز برای دانلود.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Storage' ) ) {

	final class TisaCase_Exporter_Storage {

		/** همه مسیرهای پایه ممکن (uploads + tmp؛ برای اسکن/پاک‌سازی کرونی). */
		public static function base_dirs() {
			$dirs = array();

			$uploads = wp_upload_dir();
			if ( empty( $uploads['error'] ) ) {
				$dirs[] = trailingslashit( $uploads['basedir'] ) . TisaCase_Exporter::BASE_DIR_NAME;
			}

			// Fallback و محل نسخه‌های قبلی؛ اسکن کرون اینجا را هم پاک‌سازی می‌کند.
			$dirs[] = trailingslashit( get_temp_dir() ) . TisaCase_Exporter::BASE_DIR_NAME;

			return $dirs;
		}

		/** مسیرهای نسخهٔ ۱.x که باید پاک‌سازی شوند (مهاجرت). */
		public static function legacy_dirs() {
			$dirs    = array();
			$uploads = wp_upload_dir();

			if ( empty( $uploads['error'] ) ) {
				$dirs[] = trailingslashit( $uploads['basedir'] ) . TisaCase_Exporter::LEGACY_DIR_NAME;
			}

			$dirs[] = trailingslashit( get_temp_dir() ) . TisaCase_Exporter::LEGACY_DIR_NAME;

			return $dirs;
		}

		/** اولین مسیر پایه قابل‌نوشتن (ترجیحاً uploads). */
		public static function base_dir() {
			foreach ( self::base_dirs() as $dir ) {
				if ( wp_mkdir_p( $dir ) && wp_is_writable( $dir ) ) {
					self::protect_directory( $dir );
					return $dir;
				}
			}

			return new WP_Error( 'export_dir', __( 'پوشه قابل‌نوشتن برای خروجی پیدا نشد.', TisaCase_Exporter::TEXT_DOMAIN ) );
		}

		/** پیشوند پوشه‌های همین کاربر (برای پاک‌سازی جلسه‌های قبلی). */
		private static function user_prefix_value() {
			return 'u' . substr( hash( 'sha256', get_current_user_id() . '|' . wp_salt( 'auth' ) ), 0, 8 );
		}

		/**
		 * مسیر اختصاصی هر «جلسه».
		 *
		 * نام پوشه = پیشوند کاربر + شناسهٔ تصادفی run_id (۱۶ کاراکتری).
		 * چون run_id تصادفی است، حدس‌زدن URL مستقیم فایل روی هاست‌های Nginx (بدون .htaccess)
		 * عملاً ناممکن است و هر جلسه پوشهٔ خودش را دارد.
		 */
		public static function user_dir( $run_id = '' ) {
			$base = self::base_dir();

			if ( is_wp_error( $base ) ) {
				return $base;
			}

			$prefix = self::user_prefix_value();

			if ( ! is_string( $run_id ) || '' === $run_id ) {
				return trailingslashit( $base ) . $prefix;
			}

			return trailingslashit( $base ) . $prefix . '-' . preg_replace( '/[^a-z0-9]/', '', strtolower( $run_id ) );
		}

		/** پیشوند مشترک پوشه‌های این کاربر. */
		public static function user_dir_prefix() {
			$base = self::base_dir();

			if ( is_wp_error( $base ) ) {
				return '';
			}

			return trailingslashit( $base ) . self::user_prefix_value();
		}

		/** index.php خالی + htaccess با Deny برای جلوگیری از دسترسی مستقیم وب. */
		public static function protect_directory( $dir ) {
			if ( ! is_string( $dir ) || ! is_dir( $dir ) ) {
				return;
			}

			$index = trailingslashit( $dir ) . 'index.php';
			if ( ! file_exists( $index ) ) {
				@file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}

			$htaccess = trailingslashit( $dir ) . '.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				@file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					$htaccess,
					"Options -Indexes\n" .
					"<FilesMatch \"\\.(txt|tsv|csv|xls|xlsx|json|tmp|zip)$\">\n" .
					"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
					"<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" .
					"</FilesMatch>\n"
				);
			}
		}

		/** حذف بازگشتی یک دایرکتوری (مقاوم نسبت به Symlink). */
		public static function delete_directory( $dir ) {
			if ( ! is_string( $dir ) || '' === $dir || ! is_dir( $dir ) ) {
				return;
			}

			$items = @scandir( $dir );
			if ( ! is_array( $items ) ) {
				return;
			}

			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}

				$path = trailingslashit( $dir ) . $item;
				if ( is_dir( $path ) && ! is_link( $path ) ) {
					self::delete_directory( $path );
				} else {
					@unlink( $path );
				}
			}

			@rmdir( $dir );
		}

		/** ساخت پوشهٔ تمیز برای جلسهٔ جدید + پاک‌سازی جلسه‌های قبلی همین کاربر. */
		public static function create_clean_user_dir( $run_id = '' ) {
			$dir = self::user_dir( $run_id );

			if ( is_wp_error( $dir ) ) {
				return $dir;
			}

			/*
			 * شروع خروجی جدید فقط وقتی مجاز است که قفل آزاد باشد؛ بنابراین هیچ پردازش
			 * فعالی از همین کاربر در جریان نیست و پاک‌سازی پوشه‌های قبلیِ همین کاربر ایمن است.
			 */
			$prefix = self::user_dir_prefix();

			if ( '' !== $prefix && is_dir( dirname( $prefix ) ) ) {
				$entries = @scandir( dirname( $prefix ) );

				if ( is_array( $entries ) ) {
					$family = basename( $prefix );

					foreach ( $entries as $entry ) {
						if ( 0 === strpos( $entry, $family ) ) {
							self::delete_directory( trailingslashit( dirname( $prefix ) ) . $entry );
						}
					}
				}
			}

			if ( is_dir( $dir ) ) {
				self::delete_directory( $dir );
			}

			if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
				return new WP_Error( 'session_dir', __( 'امکان ساخت پوشه موقت خروجی وجود ندارد.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			self::protect_directory( $dir );
			return $dir;
		}

		/** حذف چند فایل (بی‌خطر نسبت به مقادیر نامعتبر). */
		public static function delete_files( array $paths ) {
			foreach ( $paths as $path ) {
				if ( is_string( $path ) && '' !== $path ) {
					@unlink( $path );
				}
			}
		}

		/** بستن همه Handle های باز. */
		public static function close_all( array $handles ) {
			foreach ( $handles as $handle ) {
				if ( is_resource( $handle ) ) {
					@fclose( $handle );
				}
			}
		}

		/** نگاشت برچسب → مسیر پایه (برای ذخیرهٔ مسیر نسبی در تاریخچه). */
		public static function base_map() {
			$map     = array();
			$uploads = wp_upload_dir();

			if ( empty( $uploads['error'] ) ) {
				$map['uploads'] = trailingslashit( $uploads['basedir'] ) . TisaCase_Exporter::BASE_DIR_NAME;
			}

			$map['tmp'] = trailingslashit( get_temp_dir() ) . TisaCase_Exporter::BASE_DIR_NAME;

			$legacy_uploads = wp_upload_dir();
			if ( empty( $legacy_uploads['error'] ) ) {
				$map['legacy-uploads'] = trailingslashit( $legacy_uploads['basedir'] ) . TisaCase_Exporter::LEGACY_DIR_NAME;
			}
			$map['legacy-tmp'] = trailingslashit( get_temp_dir() ) . TisaCase_Exporter::LEGACY_DIR_NAME;

			return $map;
		}

		/** نام نسبی یک پوشه (برای ذخیره در تاریخچه بدون مسیر مطلق). */
		public static function relative_name( $dir ) {
			$dir = trailingslashit( wp_normalize_path( (string) $dir ) );

			foreach ( self::base_map() as $label => $base ) {
				$base = trailingslashit( wp_normalize_path( $base ) );

				if ( 0 === strpos( $dir, $base ) ) {
					return $label . ':' . basename( rtrim( $dir, '/' ) );
				}
			}

			return '';
		}

		/** مسیر مطلق از نام نسبی ذخیره‌شده در تاریخچه. */
		public static function absolute_from_relative( $relative ) {
			$relative = (string) $relative;
			$pos      = strpos( $relative, ':' );

			if ( false === $pos ) {
				return '';
			}

			$label = substr( $relative, 0, $pos );
			$name  = basename( substr( $relative, $pos + 1 ) );
			$map   = self::base_map();

			if ( '' === $name || ! isset( $map[ $label ] ) ) {
				return '';
			}

			return trailingslashit( $map[ $label ] ) . $name;
		}

		/** آیا مسیر داده‌شده داخل یکی از پوشه‌های مجاز است؟ (برای دانلود) */
		public static function is_allowed_dir( $dir ) {
			$real = realpath( (string) $dir );

			if ( false === $real ) {
				return false;
			}

			foreach ( array_merge( self::base_dirs(), self::legacy_dirs() ) as $base ) {
				$base_real = realpath( $base );

				if ( false === $base_real ) {
					continue;
				}

				$base_real = trailingslashit( wp_normalize_path( $base_real ) );
				$real_norm = wp_normalize_path( $real );

				if ( 0 === strpos( trailingslashit( $real_norm ), $base_real ) && $real_norm !== rtrim( $base_real, '/' ) ) {
					return true;
				}
			}

			return false;
		}

		/** پاک‌سازی فایل‌های موقت قدیمی‌تر از FILE_TTL (یا همه، وقتی $force است) + مسیرهای نسخهٔ قبل. */
		public static function sweep_old_exports( $force = false ) {
			foreach ( array_merge( self::base_dirs(), self::legacy_dirs() ) as $base ) {
				if ( ! is_string( $base ) || ! is_dir( $base ) ) {
					continue;
				}

				$items = @scandir( $base );
				if ( ! is_array( $items ) ) {
					continue;
				}

				foreach ( $items as $item ) {
					if ( '.' === $item || '..' === $item ) {
						continue;
					}

					$path = trailingslashit( $base ) . $item;

					if ( ! is_dir( $path ) || is_link( $path ) ) {
						continue;
					}

					if ( $force || ( time() - (int) @filemtime( $path ) ) > TisaCase_Exporter::FILE_TTL ) {
						self::delete_directory( $path );
					}
				}
			}
		}
	}
}
