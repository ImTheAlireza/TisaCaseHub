<?php
/**
 * موتور بومی فشرده‌سازی، تغییر اندازه، تبدیل به WebP/AVIF و پاک‌سازی نسخهٔ خام `-scaled`.
 *
 * ویژگی‌ها:
 *  - پشتیبانی کامل از `Imagick` (اولویت اول با حفظ پروفایل رنگ `ICC` برای ثبات رنگ قاب‌ها) و `GD`.
 *  - پشتیبانی هر دو حالت WebP/AVIF:
 *      ۱) `sidecar`: ساخت فایل `.webp`/`.avif` کنار فایل اصلی و تمام زیرسایزها (ایمن‌ترین حالت).
 *      ۲) `replace`: جایگزینی مستقیم فایل اصلی و زیرسایزها با `.webp` + بازنویسی خودکار
 *         `_wp_attached_file`، متادیتا، `post_content` و `_elementor_data` (حداکثر آزادسازی فضا).
 *  - تشخیص PNGهای بدون شفافیت و تبدیل با کاهش حجم ۸۰٪ تا ۹۲٪ + حفظ کامل شفافیت برای PNGهای شفاف.
 *  - گارد ضدتورم: اگر فایل خروجی از ورودی بزرگ‌تر شد، فایل اصلی دست‌نخورده می‌ماند.
 *  - هوک خودکار روی `wp_generate_attachment_metadata` برای بهینه‌سازی آنی آپلودهای دستی و ربات تلگرام.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Image_Processor' ) ) {

	/**
	 * موتور پردازش و بهینه‌سازی تصاویر.
	 */
	final class TisaCase_Optimizer_Image_Processor {

		/** @var bool جلوگیری از حلقهٔ بازگشتی هنگام تولید متادیتای جدید */
		private static $in_guard = false;

		/**
		 * گزارش قابلیت‌های واقعی پردازش تصویر روی هاست فعلی.
		 *
		 * @return array<string,mixed>
		 */
		public static function capabilities() {
			$has_imagick = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
			$has_gd      = extension_loaded( 'gd' ) && function_exists( 'gd_info' );
			$webp        = false;
			$avif        = false;

			if ( $has_imagick ) {
				try {
					$formats = Imagick::queryFormats();
					$webp    = in_array( 'WEBP', (array) $formats, true );
					$avif    = in_array( 'AVIF', (array) $formats, true );
				} catch ( Throwable $e ) {
					$has_imagick = false;
				}
			}

			if ( $has_gd ) {
				$gd_info = gd_info();
				if ( ! $webp && ! empty( $gd_info['WebP Support'] ) && function_exists( 'imagewebp' ) ) {
					$webp = true;
				}
				if ( ! $avif && ! empty( $gd_info['AVIF Support'] ) && function_exists( 'imageavif' ) ) {
					$avif = true;
				}
			}

			return array(
				'engine'  => $has_imagick ? 'Imagick' : ( $has_gd ? 'GD' : 'None' ),
				'imagick' => $has_imagick,
				'gd'      => $has_gd,
				'webp'    => $webp,
				'avif'    => $avif,
				'icc'     => $has_imagick,
			);
		}

		/**
		 * هوک بهینه‌سازی خودکار در لحظهٔ آپلود یا ایمپورت ربات تلگرام.
		 *
		 * @param array<string,mixed> $metadata      متادیتای تولیدشدهٔ وردپرس.
		 * @param int                 $attachment_id شناسهٔ پیوست.
		 * @return array<string,mixed>
		 */
		public static function maybe_auto_optimize_upload( $metadata, $attachment_id ) {
			if ( self::$in_guard || ! is_array( $metadata ) ) {
				return $metadata;
			}
			if ( ! TisaCase_Optimizer_Settings::get( 'auto_optimize_upload', 1 ) ) {
				return $metadata;
			}
			$mime = (string) get_post_mime_type( $attachment_id );
			if ( 0 !== strpos( $mime, 'image/' ) || 'image/svg+xml' === $mime || 'image/gif' === $mime ) {
				return $metadata;
			}

			self::$in_guard = true;
			try {
				$res = self::optimize_attachment( (int) $attachment_id );
				if ( ! is_wp_error( $res ) ) {
					$fresh = wp_get_attachment_metadata( (int) $attachment_id );
					if ( is_array( $fresh ) && ! empty( $fresh ) ) {
						$metadata = $fresh;
					}
				}
				if ( TisaCase_Optimizer_Settings::get( 'auto_clean_unscaled', 0 ) ) {
					self::clean_unscaled_original( (int) $attachment_id );
				}
			} catch ( Throwable $e ) {
				self::$in_guard = false;
				return $metadata;
			}
			self::$in_guard = false;

			return $metadata;
		}

		/**
		 * بهینه‌سازی کامل یک پیوست (فشرده‌سازی، سقف ابعاد، WebP/AVIF موازی یا جایگزین مستقیم).
		 *
		 * @param int                 $attachment_id شناسهٔ پیوست.
		 * @param array<string,mixed> $overrides     تنظیمات سفارشی برای این اجرا (اختیاری).
		 * @return array<string,mixed>|WP_Error
		 */
		public static function optimize_attachment( $attachment_id, array $overrides = array() ) {
			$attachment_id = absint( $attachment_id );
			$post          = get_post( $attachment_id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				return new WP_Error( 'not_found', __( 'پیوست مورد نظر یافت نشد.', 'tisacase-optimizer' ) );
			}

			$mime = strtolower( (string) $post->post_mime_type );
			if ( in_array( $mime, array( 'image/svg+xml', 'image/gif' ), true ) ) {
				return new WP_Error( 'unsupported_animated_or_vector', __( 'فایل‌های برداری SVG و GIF متحرک برای حفظ سلامت انیمیشن دست‌نخورده می‌مانند.', 'tisacase-optimizer' ) );
			}

			$uploads  = wp_upload_dir();
			$basedir  = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
			$rel_main = ltrim( wp_normalize_path( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) ), '/' );
			$main_abs = TisaCase_Optimizer_Vault::assert_inside_uploads( $basedir . $rel_main );

			if ( false === $main_abs || ! is_file( $main_abs ) ) {
				return new WP_Error( 'missing_file', __( 'فایل فیزیکی تصویر روی دیسک یافت نشد.', 'tisacase-optimizer' ) );
			}

			$cfg  = array_merge( TisaCase_Optimizer_Settings::get_all(), $overrides );
			$caps = self::capabilities();
			if ( 'None' === $caps['engine'] ) {
				return new WP_Error( 'no_image_engine', __( 'هیچ‌یک از افزونه‌های Imagick یا GD روی سرور PHP فعال نیست.', 'tisacase-optimizer' ) );
			}

			// محاسبهٔ حجم کل پیش از بهینه‌سازی.
			$before_files = TisaCase_Optimizer_Vault::collect_attachment_files( $attachment_id );
			$before_bytes = 0;
			foreach ( $before_files as $bf ) {
				if ( 0 !== strpos( $bf['role'], 'sidecar:' ) ) {
					$before_bytes += $bf['bytes'];
				}
			}

			// تهیهٔ پشتیبان اولیه در خزانه در صورت روشن بودن تنظیم.
			if ( ! empty( $cfg['backup_originals'] ) ) {
				TisaCase_Optimizer_Vault::backup_original_before_optimize( $attachment_id );
			}

			$nextgen = (string) $cfg['nextgen_format'];
			if ( 'avif' === $nextgen && ! $caps['avif'] ) {
				$nextgen = $caps['webp'] ? 'webp' : 'none';
			} elseif ( 'webp' === $nextgen && ! $caps['webp'] ) {
				$nextgen = 'none';
			}

			$webp_mode   = ( 'none' !== $nextgen && 'replace' === (string) $cfg['webp_mode'] ) ? 'replace' : 'sidecar';
			$max_w       = ! empty( $cfg['max_dimension_enabled'] ) ? max( 400, (int) $cfg['max_width'] ) : 0;
			$max_h       = ! empty( $cfg['max_dimension_enabled'] ) ? max( 400, (int) $cfg['max_height'] ) : 0;
			$is_opaque   = ( 'image/png' === $mime ) ? TisaCase_Optimizer_Reference_Scanner::fast_detect_opaque_png( $main_abs ) : false;

			if ( 'replace' === $webp_mode && 'none' !== $nextgen && $mime !== 'image/' . $nextgen ) {
				$convert_res = self::replace_attachment_format( $attachment_id, $main_abs, $rel_main, $nextgen, $cfg, $max_w, $max_h );
				if ( is_wp_error( $convert_res ) ) {
					return $convert_res;
				}
			} else {
				// ۱) فشرده‌سازی و سقف ابعاد فایل اصلی (و تبدیل PNG مات به JPG اگر فرمت جایگزین WebP نباشد).
				$target_fmt = ( $is_opaque && ! empty( $cfg['convert_opaque_png'] ) && 'none' === $nextgen ) ? 'jpeg' : self::ext_for_mime( $mime );
				self::reencode_in_place( $main_abs, $target_fmt, $cfg, $max_w, $max_h );

				// ۲) فشرده‌سازی تمام زیرسایزهای فعلی + تولید نسخهٔ موازی WebP/AVIF.
				$meta   = wp_get_attachment_metadata( $attachment_id );
				$subdir = wp_normalize_path( dirname( $rel_main ) );
				$subdir = ( '.' === $subdir ) ? '' : trailingslashit( $subdir );

				if ( 'none' !== $nextgen ) {
					self::encode_to_file( $main_abs, $main_abs . '.' . $nextgen, $nextgen, $cfg, 0, 0 );
				}

				if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
					foreach ( $meta['sizes'] as $sinfo ) {
						if ( empty( $sinfo['file'] ) ) {
							continue;
						}
						$sabs = $basedir . $subdir . basename( (string) $sinfo['file'] );
						if ( is_file( $sabs ) && $sabs !== $main_abs ) {
							$smime = ! empty( $sinfo['mime-type'] ) ? (string) $sinfo['mime-type'] : $mime;
							self::reencode_in_place( $sabs, self::ext_for_mime( $smime ), $cfg, 0, 0 );
							if ( 'none' !== $nextgen ) {
								self::encode_to_file( $sabs, $sabs . '.' . $nextgen, $nextgen, $cfg, 0, 0 );
							}
						}
					}
				}
			}

			clearstatcache();
			$after_files = TisaCase_Optimizer_Vault::collect_attachment_files( $attachment_id );
			$after_bytes = 0;
			foreach ( $after_files as $af ) {
				if ( 0 !== strpos( $af['role'], 'sidecar:' ) ) {
					$after_bytes += $af['bytes'];
				}
			}

			$saved_bytes = max( 0, $before_bytes - $after_bytes );
			update_post_meta(
				$attachment_id,
				'_tco_optimized',
				array(
					'before_bytes' => $before_bytes,
					'after_bytes'  => $after_bytes,
					'saved_bytes'  => $saved_bytes,
					'mode'         => $webp_mode,
					'nextgen'      => $nextgen,
					'at'           => current_time( 'mysql' ),
				)
			);
			delete_post_meta( $attachment_id, '_tco_file_fingerprint' );

			TisaCase_Optimizer_Settings::bump_stats(
				array(
					'optimized_count' => 1,
					'webp_count'      => 'none' !== $nextgen ? 1 : 0,
					'saved_bytes'     => $saved_bytes,
				)
			);
			TisaCase_Optimizer_Media_Inspector::flush_runtime_cache();

			return array(
				'attachment_id' => $attachment_id,
				'before_bytes'  => $before_bytes,
				'after_bytes'   => $after_bytes,
				'saved_bytes'   => $saved_bytes,
				'nextgen'       => $nextgen,
				'mode'          => $webp_mode,
			);
		}

		/**
		 * حذف ایمن فایل خام پنهان (`original_image`) پشت تصاویر `-scaled` با حفظ کامل تصویر نمایشی سایت.
		 *
		 * @param int $attachment_id شناسهٔ پیوست.
		 * @return array<string,mixed>|WP_Error
		 */
		public static function clean_unscaled_original( $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$meta          = wp_get_attachment_metadata( $attachment_id );
			$rel_main      = ltrim( wp_normalize_path( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) ), '/' );

			if ( ! is_array( $meta ) || empty( $meta['original_image'] ) || '' === $rel_main ) {
				return new WP_Error( 'no_unscaled', __( 'فایل خام جداگانه‌ای برای این تصویر وجود ندارد.', 'tisacase-optimizer' ) );
			}

			$uploads  = wp_upload_dir();
			$basedir  = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
			$subdir   = wp_normalize_path( dirname( $rel_main ) );
			$subdir   = ( '.' === $subdir ) ? '' : trailingslashit( $subdir );
			$main_abs = $basedir . $rel_main;
			$orig_abs = TisaCase_Optimizer_Vault::assert_inside_uploads( $basedir . $subdir . basename( (string) $meta['original_image'] ) );

			if ( ! is_file( $main_abs ) ) {
				return new WP_Error( 'main_missing', __( 'فایل اصلی غایب است؛ برای ایمنی، فایل خام پاک نشد.', 'tisacase-optimizer' ) );
			}

			$freed = 0;
			if ( false !== $orig_abs && $orig_abs !== $main_abs && is_file( $orig_abs ) ) {
				$freed = max( 0, (int) @filesize( $orig_abs ) );
				wp_delete_file( $orig_abs );
			}

			unset( $meta['original_image'] );
			wp_update_attachment_metadata( $attachment_id, $meta );

			if ( $freed > 0 ) {
				TisaCase_Optimizer_Settings::bump_stats(
					array(
						'unscaled_cleaned' => 1,
						'saved_bytes'      => $freed,
					)
				);
			}
			TisaCase_Optimizer_Media_Inspector::flush_runtime_cache();

			return array(
				'attachment_id' => $attachment_id,
				'saved_bytes'   => $freed,
			);
		}

		/**
		 * جایگزینی کامل فایل پیوست با فرمت نسل جدید (`.webp` / `.avif`) + بازسازی زیرسایزها + اصلاح خودکار لینک‌ها.
		 *
		 * @param int                 $attachment_id شناسهٔ پیوست.
		 * @param string              $old_abs       مسیر مطلق فعلی.
		 * @param string              $old_rel       مسیر نسبی فعلی.
		 * @param string              $format        webp | avif.
		 * @param array<string,mixed> $cfg           تنظیمات.
		 * @param int                 $max_w         سقف عرض.
		 * @param int                 $max_h         سقف ارتفاع.
		 * @return true|WP_Error
		 */
		private static function replace_attachment_format( $attachment_id, $old_abs, $old_rel, $format, array $cfg, $max_w, $max_h ) {
			global $wpdb;

			$uploads = wp_upload_dir();
			$basedir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
			$baseurl = trailingslashit( $uploads['baseurl'] );

			$new_rel = preg_replace( '/\.[a-z0-9]+$/i', '.' . $format, $old_rel );
			$new_abs = $basedir . $new_rel;

			$encoded = self::encode_to_file( $old_abs, $new_abs, $format, $cfg, $max_w, $max_h );
			if ( ! $encoded || ! is_file( $new_abs ) ) {
				return new WP_Error( 'encode_failed', __( 'تبدیل تصویر به فرمت جدید روی این سرور ناموفق بود.', 'tisacase-optimizer' ) );
			}

			$old_files = TisaCase_Optimizer_Vault::collect_attachment_files( $attachment_id );
			$old_meta  = wp_get_attachment_metadata( $attachment_id );

			update_attached_file( $attachment_id, $new_rel );
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => 'image/' . $format,
				)
			);

			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			self::$in_guard = true;
			$new_meta       = wp_generate_attachment_metadata( $attachment_id, $new_abs );
			self::$in_guard = false;

			if ( is_array( $new_meta ) && ! empty( $new_meta ) ) {
				wp_update_attachment_metadata( $attachment_id, $new_meta );
			}

			// جمع‌آوری جفت‌های URL قدیم ← جدید (اصلی و زیرسایزها) برای اصلاح بی‌نقص محتوا و المنتور.
			$replacements = array(
				$baseurl . $old_rel => $baseurl . $new_rel,
			);
			$subdir = wp_normalize_path( dirname( $old_rel ) );
			$subdir = ( '.' === $subdir ) ? '' : trailingslashit( $subdir );

			if ( is_array( $old_meta ) && ! empty( $old_meta['sizes'] ) && is_array( $new_meta ) && ! empty( $new_meta['sizes'] ) ) {
				foreach ( $old_meta['sizes'] as $skey => $sval ) {
					if ( ! empty( $sval['file'] ) && ! empty( $new_meta['sizes'][ $skey ]['file'] ) ) {
						$replacements[ $baseurl . $subdir . $sval['file'] ] = $baseurl . $subdir . $new_meta['sizes'][ $skey ]['file'];
					}
				}
			}

			foreach ( $replacements as $old_url => $new_url ) {
				if ( $old_url === $new_url ) {
					continue;
				}
				// ۱) اصلاح در محتوای نوشته‌ها و محصولات.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->posts}
						 SET post_content = REPLACE( post_content, %s, %s )
						 WHERE post_content LIKE %s",
						$old_url,
						$new_url,
						'%' . $wpdb->esc_like( $old_url ) . '%'
					)
				);

				// ۲) اصلاح در دیتای JSON المنتور (با اسلش Escapeشده `\/`!).
				$old_esc = str_replace( '/', '\\/', $old_url );
				$new_esc = str_replace( '/', '\\/', $new_url );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->postmeta}
						 SET meta_value = REPLACE( meta_value, %s, %s )
						 WHERE meta_key = '_elementor_data' AND meta_value LIKE %s",
						$old_esc,
						$new_esc,
						'%' . $wpdb->esc_like( $old_esc ) . '%'
					)
				);
			}

			// پاک‌سازی فایل‌های قدیمی از دیسک برای آزادسازی واقعی فضا.
			foreach ( $old_files as $of ) {
				if ( $of['abs'] !== $new_abs && is_file( $of['abs'] ) ) {
					wp_delete_file( $of['abs'] );
				}
			}

			return true;
		}

		/**
		 * فشرده‌سازی درجا با گارد عدم افزایش حجم.
		 *
		 * @param string              $abs_path مسیر مطلق.
		 * @param string              $format   فرمت هدف.
		 * @param array<string,mixed> $cfg      تنظیمات.
		 * @param int                 $max_w    سقف عرض.
		 * @param int                 $max_h    سقف ارتفاع.
		 * @return bool
		 */
		private static function reencode_in_place( $abs_path, $format, array $cfg, $max_w, $max_h ) {
			$orig_size = max( 0, (int) @filesize( $abs_path ) );
			$tmp_path  = $abs_path . '.tco-tmp.' . $format;

			$ok = self::encode_to_file( $abs_path, $tmp_path, $format, $cfg, $max_w, $max_h );
			if ( ! $ok || ! is_file( $tmp_path ) ) {
				if ( is_file( $tmp_path ) ) {
					wp_delete_file( $tmp_path );
				}
				return false;
			}

			clearstatcache( true, $tmp_path );
			$new_size = max( 0, (int) @filesize( $tmp_path ) );

			// گارد ضدتورم: اگر فایل بزرگ‌تر شد و تغییر ابعاد هم رخ نداده بود، فایل اصلی را نگه دار.
			if ( $new_size <= 0 || ( $new_size >= $orig_size && 0 === $max_w ) ) {
				wp_delete_file( $tmp_path );
				return false;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$moved = @rename( $tmp_path, $abs_path );
			if ( ! $moved ) {
				wp_delete_file( $tmp_path );
				return false;
			}
			return true;
		}

		/**
		 * تبدیل و فشرده‌سازی تصویر از فایل مبدأ به فایل مقصد با Imagick یا GD.
		 *
		 * @param string              $src    فایل ورودی.
		 * @param string              $dst    فایل خروجی.
		 * @param string              $format jpeg | png | webp | avif.
		 * @param array<string,mixed> $cfg    تنظیمات.
		 * @param int                 $max_w  سقف عرض (۰ = بدون تغییر سایز).
		 * @param int                 $max_h  سقف ارتفاع (۰ = بدون تغییر سایز).
		 * @return bool
		 */
		public static function encode_to_file( $src, $dst, $format, array $cfg, $max_w = 0, $max_h = 0 ) {
			$quality = self::quality_for_format( $format, $cfg );

			if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
				try {
					$im = new Imagick( $src );
					if ( method_exists( $im, 'autoOrient' ) ) {
						$im->autoOrient();
					}

					$icc = null;
					if ( ! empty( $cfg['keep_icc_profile'] ) ) {
						$profiles = $im->getImageProfiles( 'icc', true );
						if ( ! empty( $profiles['icc'] ) ) {
							$icc = $profiles['icc'];
						}
					}

					if ( $max_w > 0 && $max_h > 0 ) {
						$w = $im->getImageWidth();
						$h = $im->getImageHeight();
						if ( $w > $max_w || $h > $max_h ) {
							$im->thumbnailImage( $max_w, $max_h, true, false );
						}
					}

					if ( ! empty( $cfg['strip_exif'] ) ) {
						$im->stripImage();
						if ( null !== $icc ) {
							$im->profileImage( 'icc', $icc );
						}
					}

					$im->setImageFormat( $format );
					$im->setImageCompressionQuality( $quality );
					if ( 'jpeg' === $format ) {
						$im->setInterlaceScheme( Imagick::INTERLACE_PLANE );
					}
					$written = $im->writeImage( $dst );
					$im->clear();
					$im->destroy();
					if ( $written && is_file( $dst ) ) {
						return true;
					}
				} catch ( Throwable $e ) {
					// ادامه با موتور پشتیبان GD.
				}
			}

			return self::encode_with_gd( $src, $dst, $format, $quality, $max_w, $max_h );
		}

		/**
		 * موتور پشتیبان GD با حفظ کامل کانال آلفا و اصلاح چرخش EXIF.
		 *
		 * @param string $src     فایل ورودی.
		 * @param string $dst     فایل خروجی.
		 * @param string $format  فرمت خروجی.
		 * @param int    $quality کیفیت.
		 * @param int    $max_w   سقف عرض.
		 * @param int    $max_h   سقف ارتفاع.
		 * @return bool
		 */
		private static function encode_with_gd( $src, $dst, $format, $quality, $max_w, $max_h ) {
			if ( ! function_exists( 'getimagesize' ) ) {
				return false;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$info = @getimagesize( $src );
			if ( ! is_array( $info ) || empty( $info['mime'] ) ) {
				return false;
			}

			$im = false;
			switch ( $info['mime'] ) {
				case 'image/jpeg':
				case 'image/jpg':
					$im = function_exists( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $src ) : false;
					if ( $im && function_exists( 'exif_read_data' ) ) {
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						$exif = @exif_read_data( $src );
						if ( ! empty( $exif['Orientation'] ) ) {
							$orient = (int) $exif['Orientation'];
							if ( 3 === $orient ) {
								$im = imagerotate( $im, 180, 0 );
							} elseif ( 6 === $orient ) {
								$im = imagerotate( $im, -90, 0 );
							} elseif ( 8 === $orient ) {
								$im = imagerotate( $im, 90, 0 );
							}
						}
					}
					break;
				case 'image/png':
					$im = function_exists( 'imagecreatefrompng' ) ? @imagecreatefrompng( $src ) : false;
					break;
				case 'image/webp':
					$im = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $src ) : false;
					break;
				case 'image/avif':
					$im = function_exists( 'imagecreatefromavif' ) ? @imagecreatefromavif( $src ) : false;
					break;
			}

			if ( ! $im ) {
				return false;
			}

			if ( function_exists( 'imagepalettetotruecolor' ) ) {
				imagepalettetotruecolor( $im );
			}
			imagealphablending( $im, false );
			imagesavealpha( $im, true );

			$w = imagesx( $im );
			$h = imagesy( $im );
			if ( $max_w > 0 && $max_h > 0 && ( $w > $max_w || $h > $max_h ) ) {
				$scale = min( $max_w / $w, $max_h / $h );
				$nw    = max( 1, (int) round( $w * $scale ) );
				$nh    = max( 1, (int) round( $h * $scale ) );
				$canvas = imagecreatetruecolor( $nw, $nh );
				imagealphablending( $canvas, false );
				imagesavealpha( $canvas, true );
				$transparent = imagecolorallocatealpha( $canvas, 255, 255, 255, 127 );
				imagefilledrectangle( $canvas, 0, 0, $nw, $nh, $transparent );
				imagecopyresampled( $canvas, $im, 0, 0, 0, 0, $nw, $nh, $w, $h );
				imagedestroy( $im );
				$im = $canvas;
			}

			$saved = false;
			if ( 'webp' === $format && function_exists( 'imagewebp' ) ) {
				$saved = @imagewebp( $im, $dst, $quality );
			} elseif ( 'avif' === $format && function_exists( 'imageavif' ) ) {
				$saved = @imageavif( $im, $dst, $quality );
			} elseif ( 'png' === $format && function_exists( 'imagepng' ) ) {
				$saved = @imagepng( $im, $dst, 8 );
			} elseif ( function_exists( 'imagejpeg' ) ) {
				imageinterlace( $im, true );
				$saved = @imagejpeg( $im, $dst, $quality );
			}

			imagedestroy( $im );
			return (bool) $saved;
		}

		/**
		 * عدد کیفیت متناسب با فرمت هدف.
		 *
		 * @param string              $format فرمت هدف.
		 * @param array<string,mixed> $cfg    تنظیمات.
		 * @return int
		 */
		private static function quality_for_format( $format, array $cfg ) {
			if ( 'webp' === $format ) {
				return max( 45, min( 98, (int) $cfg['webp_quality'] ) );
			}
			if ( 'avif' === $format ) {
				return max( 35, min( 95, (int) $cfg['avif_quality'] ) );
			}
			return max( 45, min( 98, (int) $cfg['jpeg_quality'] ) );
		}

		/**
		 * نام استاندارد فرمت بر اساس MIME.
		 *
		 * @param string $mime نوع MIME.
		 * @return string
		 */
		private static function ext_for_mime( $mime ) {
			if ( 'image/png' === $mime ) {
				return 'png';
			}
			if ( 'image/webp' === $mime ) {
				return 'webp';
			}
			if ( 'image/avif' === $mime ) {
				return 'avif';
			}
			return 'jpeg';
		}
	}
}
