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

		/** آیا این اجرا مربوط به وردپرس چندسایته است؟ */
		private static function is_multisite_installation() {
			return function_exists( 'is_multisite' ) && is_multisite();
		}

		/** شناسهٔ سایت جاری؛ در وردپرس تک‌سایته ۱. */
		private static function site_id() {
			return function_exists( 'get_current_blog_id' ) ? max( 1, (int) get_current_blog_id() ) : 1;
		}

		/** مسیر tmp مخصوص سایت جاری؛ نصب تک‌سایته همان مسیر قدیمی را حفظ می‌کند. */
		private static function temp_site_base( $dirname ) {
			$base = trailingslashit( get_temp_dir() ) . $dirname;

			if ( self::is_multisite_installation() ) {
				$base = trailingslashit( $base ) . 'site-' . self::site_id();
			}

			return $base;
		}

		/** همه مسیرهای پایه ممکن (uploads + tmp؛ برای اسکن/پاک‌سازی کرونی). */
		public static function base_dirs() {
			$dirs    = array();
			$uploads = wp_upload_dir();

			if ( empty( $uploads['error'] ) ) {
				$dirs[] = trailingslashit( $uploads['basedir'] ) . TisaCase_Exporter::BASE_DIR_NAME;
			}

			// در Multisite، fallback مشترک در زیرپوشهٔ سایت جاری قرار می‌گیرد.
			$dirs[] = self::temp_site_base( TisaCase_Exporter::BASE_DIR_NAME );

			return $dirs;
		}

		/** مسیرهای نسخهٔ ۱.x که با اطمینان می‌توان اسکن/پاک‌سازی کرد. */
		public static function legacy_dirs() {
			$dirs    = array();
			$uploads = wp_upload_dir();

			if ( empty( $uploads['error'] ) ) {
				$dirs[] = trailingslashit( $uploads['basedir'] ) . TisaCase_Exporter::LEGACY_DIR_NAME;
			}

			/*
			 * مسیر قدیمی tmp در Multisite بین سایت‌ها مشترک بود؛ هیچ سایتِ منفردی
			 * حق sweep/uninstall کردن ریشهٔ آن را ندارد. شاخه‌های قدیمیِ uploads
			 * همچنان مخصوص سایت جاری‌اند و قابل پاک‌سازی هستند.
			 */
			if ( ! self::is_multisite_installation() ) {
				$dirs[] = trailingslashit( get_temp_dir() ) . TisaCase_Exporter::LEGACY_DIR_NAME;
			}

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
			return 'u' . substr( hash( 'sha256', self::site_id() . '|' . get_current_user_id() . '|' . wp_salt( 'auth' ) ), 0, 8 );
		}

		/** پیشوند تولیدشده پیش از جداسازی Multisite (فقط برای دانلود تاریخچهٔ قدیمی). */
		private static function legacy_user_prefix_value() {
			return 'u' . substr( hash( 'sha256', get_current_user_id() . '|' . wp_salt( 'auth' ) ), 0, 8 );
		}

		/** نام شاخهٔ قدیمی فقط وقتی متعلق به کاربر جاری باشد قابل استفاده است. */
		private static function is_legacy_user_dir_name( $name ) {
			$prefix = self::legacy_user_prefix_value();
			$name   = (string) $name;

			return $name === $prefix || 0 === strpos( $name, $prefix . '-' );
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
			if ( ! is_string( $dir ) || '' === $dir || ! is_dir( $dir ) || is_link( $dir ) ) {
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
		 * پاک‌سازی جلسه‌های قدیمیِ همین کاربر.
		 *
		 * قبلاً هر شروع خروجی، همهٔ پوشه‌های قبلی را حذف می‌کرد؛ نتیجه این بود که با ساختن
		 * یک خروجی جدید، فایل‌های خروجی قبلی (که در «تاریخچه» دکمهٔ دانلود دارند) از بین
		 * می‌رفت و کاربر «فایل‌ها پاک شده‌اند» می‌دید. الان جدیدترین جلسه‌ها نگه داشته
		 * می‌شوند و بقیه فقط اگر از پنجرهٔ نگهداری کوتاه گذشته باشند پاک می‌شوند
		 * (پاک‌سازی نهایی طبق FILE_TTL با کرون ساعتی انجام می‌شود).
		 */
			self::prune_user_dirs( $dir );

			if ( is_dir( $dir ) ) {
				self::delete_directory( $dir );
			}

			if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
				return new WP_Error( 'session_dir', __( 'امکان ساخت پوشه موقت خروجی وجود ندارد.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			self::protect_directory( $dir );
			return $dir;
		}

		/**
		 * پاک‌سازی ملایم پوشه‌های قبلیِ همین کاربر.
		 *
		 * @param string $keep_dir پوشهٔ جلسهٔ فعلی که در هر حالت می‌ماند.
		 */
		private static function prune_user_dirs( $keep_dir ) {
			$prefix = self::user_dir_prefix();

			if ( '' === $prefix || ! is_dir( dirname( $prefix ) ) ) {
				return;
			}

			$parent  = dirname( $prefix );
			$family  = basename( $prefix );
			$entries = @scandir( $parent );

			if ( ! is_array( $entries ) ) {
				return;
			}

			$candidates = array();

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry || 0 !== strpos( $entry, $family ) ) {
					continue;
				}

				$path = trailingslashit( $parent ) . $entry;

				if ( ! is_dir( $path ) || is_link( $path ) || $path === $keep_dir ) {
					continue;
				}

				$candidates[] = array(
					'path'  => $path,
					'mtime' => (int) @filemtime( $path ),
				);
			}

			if ( empty( $candidates ) ) {
				return;
			}

			// تازه‌ترین جلسه‌ها همیشه می‌مانند تا دانلود از تاریخچه کار کند.
			usort(
				$candidates,
				static function ( $a, $b ) {
					return $b['mtime'] <=> $a['mtime'];
				}
			);

			$keep_recent = array_slice( $candidates, 0, 2 );
			$keep_paths  = array();

			foreach ( $keep_recent as $item ) {
				$keep_paths[] = $item['path'];
			}

			foreach ( $candidates as $item ) {
				if ( in_array( $item['path'], $keep_paths, true ) ) {
					continue;
				}

				// پنجرهٔ ارفاق: جلسه‌های تازه (یک ساعت اخیر) هم دست‌نخورده می‌مانند.
				if ( $item['mtime'] > 0 && ( time() - $item['mtime'] ) < HOUR_IN_SECONDS ) {
					continue;
				}

				self::delete_directory( $item['path'] );
			}
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

			$map['tmp'] = self::temp_site_base( TisaCase_Exporter::BASE_DIR_NAME );

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

			$path = trailingslashit( $map[ $label ] ) . $name;

			if ( self::is_multisite_installation() && self::is_legacy_user_dir_name( $name ) ) {
				// دانلود تاریخچهٔ قدیمی را حفظ می‌کنیم، اما فقط برای پوشهٔ خودِ کاربر.
				if ( 'tmp' === $label && ! is_dir( $path ) ) {
					$old_base = trailingslashit( get_temp_dir() ) . TisaCase_Exporter::BASE_DIR_NAME;
					$old_path = trailingslashit( $old_base ) . $name;

					if ( is_dir( $old_path ) ) {
						return $old_path;
					}
				}

				if ( 'legacy-tmp' === $label && is_dir( $path ) ) {
					return $path;
				}
			}

			return $path;
		}

		/** آیا مسیر داده‌شده داخل یکی از پوشه‌های مجاز است؟ (برای دانلود) */
		public static function is_allowed_dir( $dir ) {
			$real = realpath( (string) $dir );

			if ( false === $real ) {
				return false;
			}

			$real_norm = wp_normalize_path( $real );

			foreach ( array_merge( self::base_dirs(), self::legacy_dirs() ) as $base ) {
				$base_real = realpath( $base );

				if ( false === $base_real ) {
					continue;
				}

				$base_real = trailingslashit( wp_normalize_path( $base_real ) );

				if ( 0 === strpos( trailingslashit( $real_norm ), $base_real ) && $real_norm !== rtrim( $base_real, '/' ) ) {
					return true;
				}
			}

			if ( self::is_multisite_installation() && self::is_legacy_user_dir_name( basename( $real_norm ) ) ) {
				$shared_roots = array(
					trailingslashit( get_temp_dir() ) . TisaCase_Exporter::BASE_DIR_NAME,
					trailingslashit( get_temp_dir() ) . TisaCase_Exporter::LEGACY_DIR_NAME,
				);

				foreach ( $shared_roots as $root ) {
					$root_real = realpath( $root );

					if ( false !== $root_real && dirname( $real_norm ) === wp_normalize_path( $root_real ) ) {
						return true;
					}
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
