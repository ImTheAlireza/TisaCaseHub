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

		const OPTION_SETTINGS = 'tisacase_optimizer_settings';
		const OPTION_STATS    = 'tisacase_optimizer_stats';
		const OPTION_PINNED   = 'tisacase_optimizer_pinned_ids';

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
				'include_draft_in_scan'  => 1,
				'scan_unregistered_disk' => 1,
				// راستی‌آزمایی زندهٔ استفاده پیش از هر حذف (ضدحذف اشتباه عکس در حال استفاده).
				'verify_before_delete'   => 1,
				'verify_fresh_hours'     => 72,
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
			$saved = get_option( self::OPTION_SETTINGS, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			return array_merge( self::defaults(), $saved );
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
			$raw = get_option( self::OPTION_PINNED, array() );
			if ( ! is_array( $raw ) ) {
				return array();
			}
			return array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
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
			return $final;
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
