<?php
/**
 * مدیریت تنظیمات، آمار تجمعی، لیست سفید (پین‌شده‌ها) و سایزهای غیرفعال.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Settings' ) ) {

	/**
	 * منبع واحد تنظیمات مرکز بهینه‌سازی تیساکیس.
	 */
	final class TisaCase_Optimizer_Settings {

		/** @var array<string,mixed>|null کش تنظیمات در همین درخواست */
		private static $cache_settings = null;

		/** @var int[]|null کش فهرست پین‌ها در همین درخواست */
		private static $cache_pinned = null;

		/** @var string[]|null کش پوشه‌های محافظت‌شده در همین درخواست */
		private static $cache_folders = null;

		const OPTION_SETTINGS = 'tisacase_optimizer_settings';
		const OPTION_STATS    = 'tisacase_optimizer_stats';
		const OPTION_PINNED   = 'tisacase_optimizer_pinned_ids';
		const OPTION_FOLDERS  = 'tisacase_optimizer_whitelist_folders';

		/** سقف تعداد پوشه‌های محافظت‌شده (بیش از این عدد فقط مقایسه را کند می‌کند). */
		const MAX_WHITELIST_FOLDERS = 100;

		/**
		 * مقادیر پیش‌فرض تنظیمات.
		 *
		 * @return array<string,mixed>
		 */
		public static function defaults() {
			return array(
				// ایمنی حذف و قرنطینه.
				'delete_mode'            => 'quarantine', // quarantine | permanent
				'quarantine_days'        => 30,
				// موتور کار قابل‌ادامه: سقف زمان هر تیک، حداکثر آیتم در تیک و
				// فاصلهٔ تنفس بین تصاویر برای جلوگیری از اشباع IOPS هاست.
				'delete_tick_ms'         => 4000,
				'delete_tick_max'        => 40,
				'delete_throttle_ms'     => 15,
				// اندازهٔ هر گام «بازرسی ایمنی پیش از حذف». کوچک بماند تا هیچ تیک
				// با کوئری‌های چندصدشرطی تایم‌اوت نشود (باگ «۰ از ۱۴۸۰»).
				// اندازهٔ بستهٔ بازرسی ایمنی پیش از حذف (کوچک = درخواست کوتاه‌تر).
				'verify_chunk'           => 20,
				// اجرای عملیات روی خود هاست (کرون وردپرس) تا بستن مرورگر یا قطع
				// اینترنت کار را متوقف نکند. سقف هر تیک بزرگ‌تر از قبل (۱۲ ثانیه /
				// ۶۰ مورد) ولی همچنان کمتر از سقف ۲۸ ثانیه هاست؛ حین کار هم
				// «زنجیرهٔ توربو» تیک‌ها را پشت‌سرهم می‌کند تا کار سریع تمام شود.
				'server_runner'          => 1,
				'runner_interval'        => 30,
				'runner_tick_ms'         => 12000,
				'runner_tick_max'        => 60,
				// تعداد ردیف در هر صفحهٔ فهرست‌ها (کاربر選択؛ پیش‌فرض ۲۵).
				'per_page'               => 25,
				'include_draft_in_scan'  => 1,
				'scan_unregistered_disk' => 1,
				// راستی‌آزمایی زندهٔ استفاده پیش از هر حذف (ضدحذف اشتباه عکس در حال استفاده).
				'verify_before_delete'   => 1,
				'verify_fresh_hours'     => 72,
				// کاتالوگ کهنه‌تر از این مقدار (ساعت) هرگز مبنای حذف نمی‌شود.
				'verify_max_age_hours'   => 168,
				'excluded_dirs'          => "tisacase-private-exports\nbdc-backups\ntisa-opt-vault\nwoocommerce_uploads\nelementor",

				// فشرده‌سازی، تغییر اندازه و نسل جدید تصویر.
				'auto_optimize_upload'   => 1,
				'quality_preset'         => 'balanced', // lossless_near | balanced | max_saving | custom
				'jpeg_quality'           => 82,
				'webp_quality'           => 80,
				'avif_quality'           => 68,
				'nextgen_format'         => 'webp',     // none | webp | avif
				'webp_mode'              => 'sidecar',  // sidecar | replace
				'delivery_method'        => 'picture',  // none | picture | htaccess
				'convert_opaque_png'     => 1,
				'strip_exif'             => 1,
				'keep_icc_profile'       => 1,
				'max_dimension_enabled'  => 1,
				'max_width'              => 1920,
				'max_height'             => 1920,
				'backup_originals'       => 1,
				'auto_clean_unscaled'    => 0,

				// زیرسایزهای غیرفعال‌شده.
				'disabled_sizes'         => array(),

				// سئوی خودکار تصاویر.
				'seo_alt_pattern'        => '{product_title} — {site_name}',
				'seo_title_pattern'      => '{product_title}',
				'seo_only_empty'         => 1,
				'seo_auto_on_save'       => 0,
			);
		}

		/**
		 * خواندن کل تنظیمات (ادغام‌شده با پیش‌فرض‌ها).
		 *
		 * @return array<string,mixed>
		 */
		public static function get_all() {
			// کش درون‌درخواستی: این متد در حلقه‌های سنگین (بازرسی ایمنی، ساخت
			// فهرست صف و رندر جدول) ده‌ها بار صدا زده می‌شود.
			if ( is_array( self::$cache_settings ) ) {
				return self::$cache_settings;
			}
			$saved = get_option( self::OPTION_SETTINGS, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			self::$cache_settings = array_merge( self::defaults(), $saved );
			return self::$cache_settings;
		}

		/**
		 * پاک‌کردن کش درون‌درخواستی تنظیمات (پس از هر ذخیره).
		 *
		 * @return void
		 */
		public static function flush_cache() {
			self::$cache_settings = null;
			self::$cache_pinned   = null;
			self::$cache_folders  = null;
		}

		/**
		 * خواندن یک کلید تنظیمات.
		 *
		 * @param string $key     نام کلید.
		 * @param mixed  $default مقدار پیش‌فرض اختیاری.
		 * @return mixed
		 */
		public static function get( $key, $default = null ) {
			$all = self::get_all();
			if ( array_key_exists( $key, $all ) ) {
				return $all[ $key ];
			}
			return $default;
		}

		/**
		 * محدودکردن «تعداد در هر صفحه» به بازهٔ امن.
		 *
		 * ۰ یعنی «همهٔ موارد» (فقط برای فهرست‌های سبک مثل گروه‌های تکراری).
		 *
		 * @param mixed $value مقدار ورودی.
		 * @return int
		 */
		public static function clamp_per_page( $value ) {
			$value = (int) $value;
			if ( 0 === $value ) {
				return 0;
			}
			return max( 10, min( 500, $value ) );
		}

		/**
		 * اعتبارسنجی و ذخیرهٔ تنظیمات ورودی (جزئی یا کامل).
		 *
		 * @param array<string,mixed> $raw ورودی خام.
		 * @return array<string,mixed> تنظیمات نهایی ذخیره‌شده.
		 */
		public static function update( array $raw ) {
			$current = self::get_all();
			$clean   = $current;

			if ( isset( $raw['delete_mode'] ) ) {
				$mode                 = sanitize_key( (string) $raw['delete_mode'] );
				$clean['delete_mode'] = in_array( $mode, array( 'quarantine', 'permanent' ), true ) ? $mode : 'quarantine';
			}

			if ( isset( $raw['delete_tick_ms'] ) ) {
				$clean['delete_tick_ms'] = max( 1500, min( 15000, (int) $raw['delete_tick_ms'] ) );
			}
			if ( isset( $raw['delete_tick_max'] ) ) {
				$clean['delete_tick_max'] = max( 5, min( 200, (int) $raw['delete_tick_max'] ) );
			}
			if ( isset( $raw['delete_throttle_ms'] ) ) {
				$clean['delete_throttle_ms'] = max( 0, min( 250, (int) $raw['delete_throttle_ms'] ) );
			}
			if ( isset( $raw['verify_chunk'] ) ) {
				$clean['verify_chunk'] = max( 5, min( 50, (int) $raw['verify_chunk'] ) );
			}
			if ( isset( $raw['runner_interval'] ) ) {
				// فقط ۳۰ یا ۶۰ ثانیه؛ کمتر از ۳۰ روی هاست اشتراکی بی‌ادبانه است.
				$clean['runner_interval'] = 60 === (int) $raw['runner_interval'] ? 60 : 30;
			}
			if ( isset( $raw['runner_tick_ms'] ) ) {
				$clean['runner_tick_ms'] = max( 1500, min( 16000, (int) $raw['runner_tick_ms'] ) );
			}
			if ( isset( $raw['runner_tick_max'] ) ) {
				$clean['runner_tick_max'] = max( 5, min( 250, (int) $raw['runner_tick_max'] ) );
			}
			if ( isset( $raw['per_page'] ) ) {
				$clean['per_page'] = self::clamp_per_page( $raw['per_page'] );
			}

			if ( isset( $raw['quarantine_days'] ) ) {
				$clean['quarantine_days'] = max( 1, min( 365, (int) $raw['quarantine_days'] ) );
			}

			$bool_keys = array(
				'include_draft_in_scan',
				'scan_unregistered_disk',
				'verify_before_delete',
				'auto_optimize_upload',
				'convert_opaque_png',
				'strip_exif',
				'keep_icc_profile',
				'max_dimension_enabled',
				'backup_originals',
				'auto_clean_unscaled',
				'server_runner',
				'seo_only_empty',
				'seo_auto_on_save',
			);
			foreach ( $bool_keys as $bk ) {
				if ( array_key_exists( $bk, $raw ) ) {
					$clean[ $bk ] = ! empty( $raw[ $bk ] ) ? 1 : 0;
				}
			}

			if ( isset( $raw['verify_fresh_hours'] ) ) {
				$clean['verify_fresh_hours'] = max( 1, min( 720, (int) $raw['verify_fresh_hours'] ) );
			}

			if ( isset( $raw['verify_max_age_hours'] ) ) {
				$clean['verify_max_age_hours'] = max( 24, min( 2160, (int) $raw['verify_max_age_hours'] ) );
			}

			if ( isset( $raw['excluded_dirs'] ) ) {
				$lines = preg_split( '/\r\n|\r|\n/', (string) $raw['excluded_dirs'] );
				$kept  = array();
				foreach ( (array) $lines as $line ) {
					$line = trim( str_replace( array( '..', '\\' ), array( '', '/' ), (string) $line ), " \t\n\r\0\x0B/" );
					if ( '' !== $line ) {
						$kept[] = sanitize_text_field( $line );
					}
				}
				$clean['excluded_dirs'] = implode( "\n", array_values( array_unique( $kept ) ) );
			}

			if ( isset( $raw['quality_preset'] ) ) {
				$preset                  = sanitize_key( (string) $raw['quality_preset'] );
				$clean['quality_preset'] = in_array( $preset, array( 'lossless_near', 'balanced', 'max_saving', 'custom' ), true )
					? $preset
					: 'balanced';

				if ( 'lossless_near' === $clean['quality_preset'] ) {
					$clean['jpeg_quality'] = 90;
					$clean['webp_quality'] = 88;
					$clean['avif_quality'] = 78;
				} elseif ( 'balanced' === $clean['quality_preset'] ) {
					$clean['jpeg_quality'] = 82;
					$clean['webp_quality'] = 80;
					$clean['avif_quality'] = 68;
				} elseif ( 'max_saving' === $clean['quality_preset'] ) {
					$clean['jpeg_quality'] = 72;
					$clean['webp_quality'] = 70;
					$clean['avif_quality'] = 58;
				}
			}

			if ( isset( $raw['jpeg_quality'] ) && ( ! isset( $raw['quality_preset'] ) || 'custom' === $clean['quality_preset'] ) ) {
				$clean['jpeg_quality'] = max( 45, min( 98, (int) $raw['jpeg_quality'] ) );
			}
			if ( isset( $raw['webp_quality'] ) && ( ! isset( $raw['quality_preset'] ) || 'custom' === $clean['quality_preset'] ) ) {
				$clean['webp_quality'] = max( 45, min( 98, (int) $raw['webp_quality'] ) );
			}
			if ( isset( $raw['avif_quality'] ) && ( ! isset( $raw['quality_preset'] ) || 'custom' === $clean['quality_preset'] ) ) {
				$clean['avif_quality'] = max( 35, min( 95, (int) $raw['avif_quality'] ) );
			}

			if ( isset( $raw['nextgen_format'] ) ) {
				$fmt                     = sanitize_key( (string) $raw['nextgen_format'] );
				$clean['nextgen_format'] = in_array( $fmt, array( 'none', 'webp', 'avif' ), true ) ? $fmt : 'webp';
			}

			if ( isset( $raw['webp_mode'] ) ) {
				$wm                 = sanitize_key( (string) $raw['webp_mode'] );
				$clean['webp_mode'] = in_array( $wm, array( 'sidecar', 'replace' ), true ) ? $wm : 'sidecar';
			}

			if ( isset( $raw['delivery_method'] ) ) {
				$dm                       = sanitize_key( (string) $raw['delivery_method'] );
				$clean['delivery_method'] = in_array( $dm, array( 'none', 'picture', 'htaccess' ), true ) ? $dm : 'picture';
			}

			if ( isset( $raw['max_width'] ) ) {
				$clean['max_width'] = max( 400, min( 6000, (int) $raw['max_width'] ) );
			}
			if ( isset( $raw['max_height'] ) ) {
				$clean['max_height'] = max( 400, min( 6000, (int) $raw['max_height'] ) );
			}

			if ( isset( $raw['disabled_sizes'] ) && is_array( $raw['disabled_sizes'] ) ) {
				$sizes = array();
				foreach ( $raw['disabled_sizes'] as $s ) {
					$sk = sanitize_key( (string) $s );
					if ( '' !== $sk ) {
						$sizes[] = $sk;
					}
				}
				$clean['disabled_sizes'] = array_values( array_unique( $sizes ) );
			}

			if ( isset( $raw['seo_alt_pattern'] ) ) {
				$alt                      = sanitize_text_field( (string) $raw['seo_alt_pattern'] );
				$clean['seo_alt_pattern'] = '' !== $alt ? $alt : '{product_title} — {site_name}';
			}
			if ( isset( $raw['seo_title_pattern'] ) ) {
				$ttl                        = sanitize_text_field( (string) $raw['seo_title_pattern'] );
				$clean['seo_title_pattern'] = '' !== $ttl ? $ttl : '{product_title}';
			}

			// ذخیره بدون بار تحمیلی روی autoload فرانت‌اند در صورت عدم نیاز.
			$needs_frontend = ! empty( $clean['delivery_method'] ) && 'picture' === $clean['delivery_method'];
			update_option( self::OPTION_SETTINGS, $clean, $needs_frontend );
			self::$cache_settings = null;

			// همگام‌سازی قوانین .htaccess در صورت انتخاب سرو از طریق وب‌سرور.
			if ( class_exists( 'TisaCase_Optimizer_WebP_Delivery' ) ) {
				if ( 'htaccess' === $clean['delivery_method'] ) {
					TisaCase_Optimizer_WebP_Delivery::sync_htaccess( true );
				} else {
					TisaCase_Optimizer_WebP_Delivery::sync_htaccess( false );
				}
			}

			return $clean;
		}

		/**
		 * فهرست پوشه‌های مستثنی‌شده در اسکن دیسک.
		 *
		 * @return string[]
		 */
		public static function excluded_dirs() {
			$raw   = (string) self::get( 'excluded_dirs', '' );
			$lines = preg_split( '/\r\n|\r|\n/', $raw );
			$out   = array( 'tisa-opt-vault' );
			foreach ( (array) $lines as $line ) {
				$line = trim( (string) $line, " \t\n\r\0\x0B/" );
				if ( '' !== $line ) {
					$out[] = $line;
				}
			}
			return array_values( array_unique( $out ) );
		}

		/**
		 * شناسه‌های پین‌شده در لیست سفید («هرگز حذف نشود»).
		 *
		 * @return int[]
		 */
		public static function pinned_ids() {
			// کش درون‌درخواستی (پیش‌تر در هر تکرار حلقهٔ بازرسی، آپشن دوباره
			// خوانده و مرتب می‌شد).
			if ( is_array( self::$cache_pinned ) ) {
				return self::$cache_pinned;
			}
			$raw                  = get_option( self::OPTION_PINNED, array() );
			self::$cache_pinned   = is_array( $raw )
				? array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) )
				: array();
			return self::$cache_pinned;
		}

		/**
		 * تغییر وضعیت پین یک یا چند تصویر در لیست سفید.
		 *
		 * @param int[] $ids  شناسه‌های پیوست.
		 * @param bool  $pin  true = افزودن به لیست سفید، false = خروج از لیست سفید.
		 * @return int[] فهرست جدید شناسه‌های پین‌شده.
		 */
		public static function set_pinned( array $ids, $pin = true ) {
			$map = array_fill_keys( self::pinned_ids(), true );
			foreach ( $ids as $id ) {
				$id = absint( $id );
				if ( $id <= 0 ) {
					continue;
				}
				if ( $pin ) {
					$map[ $id ] = true;
				} else {
					unset( $map[ $id ] );
				}
			}
			$final = array_values( array_map( 'intval', array_keys( $map ) ) );
			update_option( self::OPTION_PINNED, $final, false );
			self::$cache_pinned = null;
			return $final;
		}

		/**
		 * نرمال‌سازی یک مسیر پوشه برای «لیست سفید پوشه‌ها».
		 *
		 * کاربر ممکن است هر یک از این شکل‌ها را بنویسد و همه باید یکی شوند:
		 * `/uploads/receipt_upload`، `uploads/receipt_upload/`،
		 * `https://site.com/wp-content/uploads/receipt_upload`، `receipt_upload`.
		 * خروجی همیشه «مسیر نسبی داخل uploads، بدون اسلش ابتدا/انتها» است.
		 *
		 * @param string $raw ورودی کاربر.
		 * @return string مسیر نرمال یا رشتهٔ خالی.
		 */
		public static function normalize_folder_path( $raw ) {
			$path = trim( (string) $raw );
			if ( '' === $path ) {
				return '';
			}

			// اگر آدرس کامل چسبانده شده بود، بخش uploads را جدا می‌کنیم.
			$path = preg_replace( '#^[a-z]+://[^/]+#i', '', $path );
			$path = str_replace( '\\', '/', $path );
			$path = preg_replace( '#/+#', '/', (string) $path );
			$path = rawurldecode( $path );

			$pos = strpos( $path, 'wp-content/uploads/' );
			if ( false !== $pos ) {
				$path = substr( $path, $pos + strlen( 'wp-content/uploads/' ) );
			} else {
				$path = preg_replace( '#^/?uploads/#', '', $path );
			}

			// پیمایش به بالا و کاراکترهای کنترلی جایی ندارند.
			$path = str_replace( array( '..', "\0" ), '', (string) $path );
			$path = wp_normalize_path( $path );
			$path = trim( $path, '/' );

			if ( '' === $path || '.' === $path ) {
				return '';
			}

			// هر بخش با فیلتر وردپرس پاک می‌شود؛ نام پوشه فارسی هم مجاز است.
			$segments = array();
			foreach ( explode( '/', $path ) as $seg ) {
				$seg = trim( sanitize_text_field( $seg ) );
				$seg = trim( $seg, '.' );
				if ( '' !== $seg ) {
					$segments[] = $seg;
				}
			}
			$normalized = implode( '/', $segments );

			// پوشهٔ خودِ خزانهٔ افزونه محافظت‌پذیر نیست؛ در غیر این صورت
			// فایل‌های قرنطینه از فهرست‌ها ناپدید می‌شدند و مدیریتشان گم می‌شد.
			if ( false !== stripos( $normalized, 'tisa-opt-vault' ) ) {
				return '';
			}
			return $normalized;
		}

		/**
		 * فهرست پوشه‌های محافظت‌شده («هرگز در فهرست‌های پاک‌سازی نیایند»).
		 *
		 * @return string[]
		 */
		public static function whitelisted_folders() {
			if ( is_array( self::$cache_folders ) ) {
				return self::$cache_folders;
			}
			$raw = get_option( self::OPTION_FOLDERS, array() );
			$out = array();
			if ( is_array( $raw ) ) {
				foreach ( $raw as $item ) {
					$norm = self::normalize_folder_path( (string) $item );
					if ( '' !== $norm && ! in_array( $norm, $out, true ) ) {
						$out[] = $norm;
					}
				}
			}
			self::$cache_folders = $out;
			return $out;
		}

		/**
		 * ذخیرهٔ فهرست پوشه‌های محافظت‌شده.
		 *
		 * @param array $folders مسیرها (خام یا نرمال).
		 * @return string[] فهرست نرمال‌شدهٔ نهایی.
		 */
		public static function set_whitelisted_folders( array $folders ) {
			$clean = array();
			foreach ( $folders as $f ) {
				$norm = self::normalize_folder_path( $f );
				if ( '' !== $norm && ! in_array( $norm, $clean, true ) ) {
					$clean[] = $norm;
				}
			}
			// سقف منطقی: از ۱۰۰ پوشه بیشتر لازم نمی‌شود و ردیف‌های بیش از آن
			// فقط مقایسهٔ هر فایل را کند می‌کند.
			$clean = array_slice( $clean, 0, self::MAX_WHITELIST_FOLDERS );
			update_option( self::OPTION_FOLDERS, $clean, false );
			self::$cache_folders = null;
			return $clean;
		}

		/**
		 * پوشه‌ای که این مسیر نسبی داخل آن است (یا رشتهٔ خالی).
		 *
		 * منطق تطبیق: مسیر با هر پوشه‌ای که «خودِ مسیر»، «شروع مسیر» یا
		 * «یک بخش میانی مسیر» باشد منطبق می‌شود؛ پس هم
		 * `receipt_upload` (پوشه‌ای با این نام در هر عمقی) و هم
		 * `2026/05/receipts` (مسیر دقیق) کار می‌کند. مقایسه بی‌توجه به
		 * بزرگ/کوچک‌بودن حروف است تا روی هاست‌های حساس به حروف هم امن بماند.
		 *
		 * @param string $rel_path مسیر نسبی داخل `uploads/`.
		 * @return string نام پوشهٔ منطبق یا رشتهٔ خالی.
		 */
		public static function matched_whitelist_folder( $rel_path ) {
			$folders = self::whitelisted_folders();
			if ( empty( $folders ) ) {
				return '';
			}
			$rel = strtolower( ltrim( wp_normalize_path( (string) $rel_path ), '/' ) );
			if ( '' === $rel ) {
				return '';
			}
			foreach ( $folders as $folder ) {
				$needle = strtolower( $folder );
				if ( '' === $needle ) {
					continue;
				}
				if ( $rel === $needle || 0 === strpos( $rel, $needle . '/' ) || false !== strpos( $rel, '/' . $needle . '/' ) ) {
					return $folder;
				}
			}
			return '';
		}

		/**
		 * آیا این مسیر داخل یکی از پوشه‌های محافظت‌شده است؟
		 *
		 * @param string $rel_path مسیر نسبی.
		 * @return bool
		 */
		public static function is_whitelisted_path( $rel_path ) {
			return '' !== self::matched_whitelist_folder( $rel_path );
		}

		/**
		 * آمار تجمعی صرفه‌جویی از ابتدای نصب افزونه.
		 *
		 * @return array<string,int>
		 */
		public static function stats() {
			$defaults = array(
				'saved_bytes'       => 0,
				'optimized_count'   => 0,
				'webp_count'        => 0,
				'quarantined_count' => 0,
				'deleted_count'     => 0,
				'merged_duplicates' => 0,
				'unscaled_cleaned'  => 0,
				'seo_updated'       => 0,
				'restored_count'    => 0,
			);
			$saved = get_option( self::OPTION_STATS, array() );
			return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
		}

		/**
		 * افزایش اتمی مقادیر آمار تجمعی.
		 *
		 * @param array<string,int> $deltas تغییرات مثبت.
		 * @return array<string,int>
		 */
		public static function bump_stats( array $deltas ) {
			$current = self::stats();
			foreach ( $deltas as $k => $v ) {
				if ( isset( $current[ $k ] ) ) {
					$current[ $k ] = max( 0, (int) $current[ $k ] + (int) $v );
				}
			}
			update_option( self::OPTION_STATS, $current, false );
			return $current;
		}
	}
}
