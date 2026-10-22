<?php
/**
 * چکاپ پیش از عملیات (Preflight Self-Check).
 *
 * چرا؟ بیشتر شکست‌های نیمه‌کاره در هاست‌های اشتراکی ریشهٔ ساده دارند: حافظهٔ کم،
 * نبود WebP در GD/Imagick، پوشهٔ غیرقابل‌نوشتن، فضای دیسک کم یا اسکن کهنه.
 * این کلاس قبل از شروع، همه را در یک نگاه بررسی می‌کند و برای هر مورد، یک
 * توصیهٔ روشن فارسی می‌دهد؛ هیچ عملیاتی خودسرانه تغییر نمی‌دهد.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Preflight' ) ) {

	/**
	 * اجرای چکاپ و ساخت گزارش خوانا.
	 */
	final class TisaCase_Optimizer_Preflight {

		/**
		 * تبدیل «128M» به بایت.
		 *
		 * @param string|int $value مقدار ini.
		 * @return int
		 */
		private static function to_bytes( $value ) {
			$value = trim( (string) $value );
			if ( '' === $value || '-1' === $value ) {
				return -1;
			}
			$unit = strtolower( substr( $value, -1 ) );
			$num  = (float) $value;
			if ( 'g' === $unit ) {
				$num *= 1024 * 1024 * 1024;
			} elseif ( 'm' === $unit ) {
				$num *= 1024 * 1024;
			} elseif ( 'k' === $unit ) {
				$num *= 1024;
			}
			return (int) $num;
		}

		/**
		 * اجرای همهٔ بررسی‌ها.
		 *
		 * @return array{score:int,level:string,checked_at:string,checks:array<int,array<string,mixed>>}
		 */
		public static function run() {
			$checks = array();

			$add = static function ( $key, $label, $status, $value, $advice ) use ( &$checks ) {
				$checks[] = array(
					'key'    => $key,
					'label'  => $label,
					'status' => $status,
					'value'  => $value,
					'advice' => $advice,
				);
			};

			// ۱) نسخهٔ PHP.
			$php_ok = version_compare( PHP_VERSION, '7.4', '>=' );
			$add(
				'php',
				__( 'نسخهٔ PHP', 'tisacase-optimizer' ),
				$php_ok ? ( version_compare( PHP_VERSION, '8.0', '>=' ) ? 'ok' : 'warn' ) : 'fail',
				PHP_VERSION,
				$php_ok
					? ( version_compare( PHP_VERSION, '8.0', '>=' ) ? __( 'نسخهٔ مناسب است.', 'tisacase-optimizer' ) : __( 'کار می‌کند، ولی PHP 8 یا بالاتر سریع‌تر و امن‌تر است.', 'tisacase-optimizer' ) )
					: __( 'افزونه به PHP 7.4 یا بالاتر نیاز دارد؛ از پنل هاست ارتقا دهید.', 'tisacase-optimizer' )
			);

			// ۲) حافظهٔ PHP.
			$mem_bytes = self::to_bytes( ini_get( 'memory_limit' ) );
			if ( -1 === $mem_bytes ) {
				$add( 'memory', __( 'حافظهٔ PHP', 'tisacase-optimizer' ), 'ok', __( 'بی‌نهایت', 'tisacase-optimizer' ), __( 'محدودیتی اعمال نشده است.', 'tisacase-optimizer' ) );
			} elseif ( $mem_bytes < 128 * 1024 * 1024 ) {
				$add(
					'memory',
					__( 'حافظهٔ PHP', 'tisacase-optimizer' ),
					'fail',
					size_format( $mem_bytes ),
					__( 'برای بهینه‌سازی چند تصویر هم‌زمان کم است؛ حداقل ۲۵۶ مگابایت تعیین کنید (در wp-config.php یا پنل هاست).', 'tisacase-optimizer' )
				);
			} elseif ( $mem_bytes < 256 * 1024 * 1024 ) {
				$add(
					'memory',
					__( 'حافظهٔ PHP', 'tisacase-optimizer' ),
					'warn',
					size_format( $mem_bytes ),
					__( 'عملیات انجام می‌شود ولی در تصاویر بزرگ ممکن است کند شود؛ ۲۵۶ مگابایت توصیه می‌شود.', 'tisacase-optimizer' )
				);
			} else {
				$add( 'memory', __( 'حافظهٔ PHP', 'tisacase-optimizer' ), 'ok', size_format( $mem_bytes ), __( 'مقدار کافی است.', 'tisacase-optimizer' ) );
			}

			// ۳) سقف زمان اجرا.
			$max_exec = (int) ini_get( 'max_execution_time' );
			$add(
				'max_execution',
				__( 'سقف زمان هر درخواست', 'tisacase-optimizer' ),
				0 === $max_exec || $max_exec >= 30 ? 'ok' : ( $max_exec >= 15 ? 'warn' : 'fail' ),
				0 === $max_exec ? __( 'بدون محدودیت', 'tisacase-optimizer' ) : $max_exec . 's',
				0 === $max_exec || $max_exec >= 30
					? __( 'هر تیک عملیات حداکثر ۴ ثانیه است؛ این مقدار کافی است.', 'tisacase-optimizer' )
					: __( 'هر تیک کوتاه است ولی برای اطمینان، سقف را روی ۳۰ ثانیه بگذارید.', 'tisacase-optimizer' )
			);

			// ۴) موتور تصویر و کدک‌ها.
			$caps = TisaCase_Optimizer_Image_Processor::capabilities();
			if ( 'None' === $caps['engine'] ) {
				$add(
					'engine',
					__( 'موتور پردازش تصویر', 'tisacase-optimizer' ),
					'fail',
					__( 'هیچ‌کدام', 'tisacase-optimizer' ),
					__( 'نه Imagick و نه GD روی PHP فعال است؛ از پشتیبانی هاست بخواهید یکی را فعال کند.', 'tisacase-optimizer' )
				);
			} else {
				$add(
					'engine',
					__( 'موتور پردازش تصویر', 'tisacase-optimizer' ),
					$caps['webp'] ? 'ok' : 'fail',
					$caps['engine'] . ' · WebP: ' . ( $caps['webp'] ? __( 'بله', 'tisacase-optimizer' ) : __( 'خیر', 'tisacase-optimizer' ) ),
					$caps['webp']
						? ( $caps['avif'] ? __( 'WebP و AVIF پشتیبانی می‌شوند.', 'tisacase-optimizer' ) : __( 'WebP پشتیبانی می‌شود؛ AVIF نیست (لازم هم نیست).', 'tisacase-optimizer' ) )
						: __( 'برای فشرده‌سازی نسل جدید، باید WebP در GD/Imagick فعال باشد.', 'tisacase-optimizer' )
				);
			}

			// ۵) دسترسی نوشتن.
			$uploads = wp_upload_dir();
			$writable_uploads = ! empty( $uploads['basedir'] ) && is_writable( $uploads['basedir'] );
			$add(
				'uploads_writable',
				__( 'اجازهٔ نوشتن در پوشهٔ uploads', 'tisacase-optimizer' ),
				$writable_uploads ? 'ok' : 'fail',
				$writable_uploads ? __( 'دارد', 'tisacase-optimizer' ) : __( 'ندارد', 'tisacase-optimizer' ),
				$writable_uploads
					? __( 'فایل‌های سبک‌تر و نسخهٔ WebP کنار فایل اصلی ساخته می‌شوند.', 'tisacase-optimizer' )
					: __( 'بدون این اجازه، هیچ فایلی بهینه نمی‌شود؛ مجوز پوشه را روی ۷۵۵ یا مالکیت درست تنظیم کنید.', 'tisacase-optimizer' )
			);

			$vault_base = TisaCase_Optimizer_Vault::base_dir();
			$vault_ok   = '' !== $vault_base && is_dir( $vault_base ) && is_writable( $vault_base );
			$add(
				'vault_writable',
				__( 'پوشهٔ امن قرنطینه و پشتیبان', 'tisacase-optimizer' ),
				$vault_ok ? 'ok' : 'fail',
				$vault_ok ? __( 'آماده', 'tisacase-optimizer' ) : __( 'ساخته نشد', 'tisacase-optimizer' ),
				$vault_ok ? __( 'بازگردانی و undo همیشه در دسترس است.', 'tisacase-optimizer' ) : __( 'برای حذف ایمن و بازگردانی، این پوشه باید قابل نوشتن باشد.', 'tisacase-optimizer' )
			);

			// ۶) فضای دیسک.
			$free = function_exists( 'disk_free_space' ) && ! empty( $uploads['basedir'] ) ? @disk_free_space( $uploads['basedir'] ) : false;
			if ( false !== $free ) {
				$free = (int) $free;
				$add(
					'disk_space',
					__( 'فضای آزاد دیسک', 'tisacase-optimizer' ),
					$free < 200 * 1024 * 1024 ? 'fail' : ( $free < 1024 * 1024 * 1024 ? 'warn' : 'ok' ),
					size_format( $free ),
					$free < 200 * 1024 * 1024
						? __( 'فضای دیسک تقریباً پر است؛ پیش از بهینه‌سازی یا بازگردانی، فضا آزاد کنید.', 'tisacase-optimizer' )
						: __( 'فضای کافی برای ساخت نسخه‌های جدید وجود دارد.', 'tisacase-optimizer' )
				);
			}

			// ۷) وضعیت کاتالوگ/اسکن.
			$catalog = TisaCase_Optimizer_Reference_Scanner::read_persisted_catalog();
			if ( ! is_array( $catalog ) ) {
				$add(
					'catalog',
					__( 'کاتالوگ اسکن', 'tisacase-optimizer' ),
					'warn',
					__( 'هنوز ساخته نشده', 'tisacase-optimizer' ),
					__( 'یک‌بار «اسکن کامل تصاویر» را اجرا کنید تا فهرست دقیق ساخته شود.', 'tisacase-optimizer' )
				);
			} else {
				$age   = isset( $catalog['built_at'] ) ? human_time_diff( strtotime( (string) $catalog['built_at'] ) ) : '';
				$count = isset( $catalog['items'] ) ? count( (array) $catalog['items'] ) : 0;
				$stale = isset( $catalog['built_at'] ) && ( time() - (int) strtotime( (string) $catalog['built_at'] ) ) > DAY_IN_SECONDS;
				$add(
					'catalog',
					__( 'کاتالوگ اسکن', 'tisacase-optimizer' ),
					$stale ? 'warn' : 'ok',
					sprintf(
						/* translators: 1: image count, 2: age. */
						__( '%1$s تصویر · %2$s پیش', 'tisacase-optimizer' ),
						number_format_i18n( $count ),
						$age
					),
					$stale
						? __( 'کاتالوگ قدیمی است؛ با یک اسکن تازه، اعداد داشبورد دقیق می‌شوند.', 'tisacase-optimizer' )
						: __( 'کاتالوگ تازه است؛ آمار داشبورد دقیق است.', 'tisacase-optimizer' )
				);
			}

			// ۸) قرنطینه.
			$vault   = TisaCase_Optimizer_Vault::summary();
			$v_bytes = (int) $vault['quarantine_bytes'] + (int) $vault['originals_bytes'];
			$add(
				'quarantine',
				__( 'حجم قرنطینه و پشتیبان', 'tisacase-optimizer' ),
				$v_bytes > 500 * 1024 * 1024 ? 'warn' : 'ok',
				sprintf(
					/* translators: 1: items, 2: bytes. */
					__( '%1$s مورد · %2$s', 'tisacase-optimizer' ),
					number_format_i18n( (int) $vault['quarantine_count'] + (int) $vault['originals_count'] ),
					size_format( $v_bytes )
				),
				$v_bytes > 500 * 1024 * 1024
					? __( 'فضای قابل توجهی در قرنطینه است؛ موارد لازم را بازگردانید و بقیه را برای همیشه پاک کنید.', 'tisacase-optimizer' )
					: __( 'قرنطینه فضای زیادی اشغال نکرده است.', 'tisacase-optimizer' )
			);

			// ۹) کرون وردپرس.
			$cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
			$add(
				'cron',
				__( 'زمان‌بند وردپرس (WP-Cron)', 'tisacase-optimizer' ),
				$cron_off ? 'warn' : 'ok',
				$cron_off ? __( 'خاموش', 'tisacase-optimizer' ) : __( 'فعال', 'tisacase-optimizer' ),
				$cron_off
					? __( 'پاک‌سازی خودکار قرنطینه اجرا نمی‌شود؛ اگر کرون سرور تنظیم نکرده‌اید، آن را فعال کنید.', 'tisacase-optimizer' )
					: __( 'پاک‌سازی خودکار قرنطینهٔ منقضی انجام می‌شود.', 'tisacase-optimizer' )
			);

			// امتیاز کلی.
			$score = 100;
			foreach ( $checks as $c ) {
				if ( 'fail' === $c['status'] ) {
					$score -= 25;
				} elseif ( 'warn' === $c['status'] ) {
					$score -= 8;
				}
			}
			$score = max( 0, min( 100, $score ) );

			return array(
				'score'      => $score,
				'level'      => $score >= 90 ? 'ok' : ( $score >= 60 ? 'warn' : 'fail' ),
				'checked_at' => current_time( 'mysql' ),
				'checks'     => $checks,
			);
		}
	}
}
