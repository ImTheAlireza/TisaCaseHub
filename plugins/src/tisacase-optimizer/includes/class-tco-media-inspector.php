<?php
/**
 * بازرس و کاوشگر فوق‌سریع رسانه (Instant Cached Media Inspector & In-Place Catalog Mutator).
 *
 * تمام خوانش‌های داشبورد، مرتب‌سازی سنگین‌ترین تصاویر، فیلتر بدون استفاده‌ها، تکراری‌ها و زیرسایزها
 * در کمتر از ۲۵ میلی‌ثانیه از روی کاتالوگ ساخته‌شدهٔ مرحله‌ای (`catalog-v2.json`) انجام می‌شود و پس از
 * هر حذف، قرنطینه، فشرده‌سازی یا بازیابی، همان آیتم‌ها درجا در کاتالوگ به‌روز می‌شوند (بدون نیاز به اسکن مجدد!).
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Media_Inspector' ) ) {

	/**
	 * تحلیلگر و جستجوگر سریع کاتالوگ رسانه.
	 */
	final class TisaCase_Optimizer_Media_Inspector {

		/** نام آپشن صف حذف‌های معلق (غیر-autoload). */
		const OPTION_PENDING = 'tisacase_optimizer_pending_removals';

		/** @var array<string,mixed>|null کش در-درخواستی */
		private static $catalog_cache = null;

		/**
		 * پاک‌سازی کش حافظهٔ درخواست جاری.
		 *
		 * @return void
		 */
		public static function flush_runtime_cache() {
			self::$catalog_cache = null;
		}

		/**
		 * آیا کاتالوگ آماده روی دیسک موجود است؟
		 *
		 * @return bool
		 */
		public static function has_ready_catalog() {
			if ( null !== self::$catalog_cache ) {
				return true;
			}
			return null !== TisaCase_Optimizer_Reference_Scanner::read_persisted_catalog();
		}

		/**
		 * دریافت کاتالوگ آماده (یا اجرای اسکن کامل در محیط WP-CLI در صورت نبود).
		 *
		 * @param bool $force_rescan بازسازی کامل از صفر.
		 * @return array{index:array<string,mixed>,items:array<int,array<string,mixed>>}
		 */
		public static function build_catalog( $force_rescan = false ) {
			if ( null !== self::$catalog_cache && ! $force_rescan ) {
				return self::$catalog_cache;
			}

			$raw = ! $force_rescan ? TisaCase_Optimizer_Reference_Scanner::read_persisted_catalog() : null;
			if ( ! is_array( $raw ) ) {
				$raw = TisaCase_Optimizer_Reference_Scanner::get_or_build_index( $force_rescan );
			}

			$pinned_map = array_fill_keys( TisaCase_Optimizer_Settings::pinned_ids(), true );
			$items      = array();
			if ( ! empty( $raw['items'] ) && is_array( $raw['items'] ) ) {
				foreach ( $raw['items'] as $k => $it ) {
					$aid               = isset( $it['id'] ) ? (int) $it['id'] : (int) $k;
					$is_pinned         = isset( $pinned_map[ $aid ] );
					$it['is_pinned']   = $is_pinned;
					if ( $is_pinned && ! empty( $it['exists'] ) ) {
						$it['usage_status'] = 'pinned';
					} elseif ( 'pinned' === $it['usage_status'] && ! $is_pinned ) {
						$it['usage_status'] = ! empty( $it['refs_count'] ) ? 'used' : 'strict_unused';
					}
					$items[ $aid ] = $it;
				}
			}

			self::$catalog_cache = array(
				'index' => $raw,
				'items' => $items,
			);
			return self::$catalog_cache;
		}

		/**
		 * ثبت حذف‌های معلق (بدون نوشتن کاتالوگ).
		 *
		 * چرا؟ نوشتن فایل کاتالوگ برای هر بستهٔ ۲۵تایی، در فروشگاه با ۱۵۰۰ تصویر
		 * یعنی ۶۰ بار خواندن و نوشتن یک JSON چندمگابایتی — یکی از دلایل اصلی
		 * کندی و قطع‌شدن عملیات. اکنون فقط در پایان کار یک‌بار نوشته می‌شود.
		 *
		 * @param int[]    $ids   شناسه‌ها.
		 * @param string[] $paths مسیرها.
		 * @return void
		 */
		public static function queue_pending_removals( array $ids, array $paths = array() ) {
			$pending = get_option( self::OPTION_PENDING, array() );
			if ( ! is_array( $pending ) ) {
				$pending = array();
			}
			$ids_map   = isset( $pending['ids'] ) && is_array( $pending['ids'] ) ? array_fill_keys( array_map( 'intval', $pending['ids'] ), true ) : array();
			$paths_map = isset( $pending['paths'] ) && is_array( $pending['paths'] ) ? array_fill_keys( (array) $pending['paths'], true ) : array();

			foreach ( $ids as $id ) {
				$id = absint( $id );
				if ( $id > 0 ) {
					$ids_map[ $id ] = true;
				}
			}
			foreach ( $paths as $path ) {
				$path = ltrim( wp_normalize_path( (string) $path ), '/' );
				if ( '' !== $path ) {
					$paths_map[ $path ] = true;
				}
			}

			update_option(
				self::OPTION_PENDING,
				array(
					'ids'   => array_map( 'intval', array_keys( $ids_map ) ),
					'paths' => array_keys( $paths_map ),
				),
				false
			);
		}

		/**
		 * اعمال یک‌بارهٔ حذف‌های معلق روی کاتالوگ.
		 *
		 * @return int تعداد آیتم‌های اعمال‌شده.
		 */
		public static function flush_pending_removals() {
			$pending = get_option( self::OPTION_PENDING, array() );
			if ( ! is_array( $pending ) || ( empty( $pending['ids'] ) && empty( $pending['paths'] ) ) ) {
				delete_option( self::OPTION_PENDING );
				return 0;
			}

			$ids   = isset( $pending['ids'] ) ? (array) $pending['ids'] : array();
			$paths = isset( $pending['paths'] ) ? (array) $pending['paths'] : array();

			// پاک‌کردن قبل از نوشتن تا اگر نوشتن کاتالوگ نیمه‌کاره ماند، دوباره اعمال شود.
			delete_option( self::OPTION_PENDING );
			self::remove_from_cached_catalog( $ids, $paths, false );

			return count( $ids ) + count( $paths );
		}

		/**
		 * حذف فوری آیتم‌ها یا فایل‌های یتیم از کاتالوگ کش‌شده روی دیسک (در ۵ میلی‌ثانیه بدون نیاز به اسکن مجدد کل سایت!).
		 *
		 * @param int[]    $removed_ids   شناسه‌های پیوست حذف/قرنطینه‌شده.
		 * @param string[] $removed_paths مسیرهای نسبی فایل‌های یتیم حذف‌شده.
		 * @param bool     $defer         اگر true باشد، به‌جای بازنویسی فوری کل JSON
		 *                                (که برای هر ۲۵ تصویر یک نوشتن سنگین بود) فقط
		 *                                در «صف حذف معلق» ثبت می‌شود و در پایان کار
		 *                                یک‌جا اعمال می‌گردد.
		 * @return void
		 */
		public static function remove_from_cached_catalog( array $removed_ids, array $removed_paths = array(), $defer = false ) {
			if ( $defer ) {
				self::queue_pending_removals( $removed_ids, $removed_paths );
				return;
			}

			$raw = TisaCase_Optimizer_Reference_Scanner::read_persisted_catalog();
			if ( ! is_array( $raw ) ) {
				self::flush_runtime_cache();
				return;
			}

			$freed_bytes  = 0;
			$freed_inodes = 0;

			foreach ( $removed_ids as $id ) {
				$aid = absint( $id );
				if ( isset( $raw['items'][ $aid ] ) ) {
					$freed_bytes  += (int) $raw['items'][ $aid ]['total_bytes'];
					$freed_inodes += 1 + (int) $raw['items'][ $aid ]['subsizes_count'];
					unset( $raw['items'][ $aid ] );
				} elseif ( isset( $raw['items'][ (string) $aid ] ) ) {
					$freed_bytes  += (int) $raw['items'][ (string) $aid ]['total_bytes'];
					$freed_inodes += 1 + (int) $raw['items'][ (string) $aid ]['subsizes_count'];
					unset( $raw['items'][ (string) $aid ] );
				}
			}

			if ( ! empty( $removed_paths ) && ! empty( $raw['orphans'] ) && is_array( $raw['orphans'] ) ) {
				$path_map    = array_fill_keys( $removed_paths, true );
				$next_orphan = array();
				foreach ( $raw['orphans'] as $o ) {
					if ( isset( $path_map[ $o['rel_path'] ] ) ) {
						$freed_bytes += (int) $o['bytes'];
						++$freed_inodes;
						continue;
					}
					$next_orphan[] = $o;
				}
				$raw['orphans'] = $next_orphan;
			}

			$raw['disk_bytes']   = max( 0, (int) $raw['disk_bytes'] - $freed_bytes );
			$raw['total_inodes'] = max( 0, (int) $raw['total_inodes'] - $freed_inodes );
			TisaCase_Optimizer_Reference_Scanner::write_persisted_catalog( $raw );
			self::flush_runtime_cache();
		}

		/**
		 * به‌روزرسانی درجای مشخصات چند پیوست در کاتالوگ کش‌شده (پس از فشرده‌سازی، پاک‌سازی -scaled، بازیابی یا تغییر Alt).
		 *
		 * @param int[] $attachment_ids شناسه‌های تغییرکرده.
		 * @return void
		 */
		public static function refresh_items_in_cached_catalog( array $attachment_ids ) {
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ) );
			if ( empty( $ids ) ) {
				return;
			}
			$raw = TisaCase_Optimizer_Reference_Scanner::read_persisted_catalog();
			if ( ! is_array( $raw ) ) {
				self::flush_runtime_cache();
				return;
			}

			$uploads = wp_upload_dir();
			$basedir = ! empty( $uploads['basedir'] ) ? wp_normalize_path( trailingslashit( realpath( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : $uploads['basedir'] ) ) : '';
			$baseurl = ! empty( $uploads['baseurl'] ) ? trailingslashit( $uploads['baseurl'] ) : '';

			foreach ( $ids as $aid ) {
				$post = get_post( $aid );
				if ( ! $post || 'attachment' !== $post->post_type ) {
					unset( $raw['items'][ $aid ], $raw['items'][ (string) $aid ] );
					continue;
				}

				$prev     = isset( $raw['items'][ $aid ] ) ? $raw['items'][ $aid ] : ( isset( $raw['items'][ (string) $aid ] ) ? $raw['items'][ (string) $aid ] : array() );
				$rel_file = ltrim( wp_normalize_path( (string) get_post_meta( $aid, '_wp_attached_file', true ) ), '/' );
				$meta     = wp_get_attachment_metadata( $aid );
				$opt_meta = get_post_meta( $aid, '_tco_optimized', true );
				$alt_txt  = trim( (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ) );
				$mime     = strtolower( (string) $post->post_mime_type );

				$subdir   = '' !== $rel_file ? wp_normalize_path( dirname( $rel_file ) ) : '';
				$subdir   = ( '.' === $subdir || '' === $subdir ) ? '' : trailingslashit( $subdir );
				$main_abs = ( '' !== $basedir && '' !== $rel_file ) ? $basedir . $rel_file : '';
				$exists   = ( '' !== $main_abs && is_file( $main_abs ) );
				$main_b   = $exists ? max( 0, (int) @filesize( $main_abs ) ) : 0;
				$unsc_b   = 0;
				$unsc_n   = '';
				$sub_b    = 0;
				$sub_c    = 0;
				$side_b   = 0;
				$has_webp = ( 'image/webp' === $mime );

				if ( $exists && is_file( $main_abs . '.webp' ) ) {
					$has_webp = true;
					$side_b  += max( 0, (int) @filesize( $main_abs . '.webp' ) );
				}
				if ( is_array( $meta ) && '' !== $basedir ) {
					if ( ! empty( $meta['original_image'] ) ) {
						$oabs = $basedir . $subdir . basename( (string) $meta['original_image'] );
						if ( $oabs !== $main_abs && is_file( $oabs ) ) {
							$unsc_n = basename( (string) $meta['original_image'] );
							$unsc_b = max( 0, (int) @filesize( $oabs ) );
						}
					}
					if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
						$seen = array();
						foreach ( $meta['sizes'] as $sv ) {
							if ( empty( $sv['file'] ) ) {
								continue;
							}
							$sabs = $basedir . $subdir . basename( (string) $sv['file'] );
							if ( $sabs !== $main_abs && ! isset( $seen[ $sabs ] ) && is_file( $sabs ) ) {
								$seen[ $sabs ] = true;
								++$sub_c;
								$sub_b += max( 0, (int) @filesize( $sabs ) );
								if ( is_file( $sabs . '.webp' ) ) {
									$side_b += max( 0, (int) @filesize( $sabs . '.webp' ) );
								}
							}
						}
					}
				}

				$w      = ( is_array( $meta ) && isset( $meta['width'] ) ) ? absint( $meta['width'] ) : 0;
				$h      = ( is_array( $meta ) && isset( $meta['height'] ) ) ? absint( $meta['height'] ) : 0;
				$pixels = $w * $h;
				$tot_b  = $main_b + $unsc_b + $sub_b + $side_b;
				$th_f   = ( is_array( $meta ) && ! empty( $meta['sizes']['thumbnail']['file'] ) ) ? $subdir . basename( (string) $meta['sizes']['thumbnail']['file'] ) : '';

				$raw['items'][ $aid ] = array(
					'id'             => $aid,
					'title'          => (string) $post->post_title,
					'filename'       => '' !== $rel_file ? basename( $rel_file ) : '#' . $aid,
					'rel_path'       => $rel_file,
					'folder'         => rtrim( $subdir, '/' ),
					'mime'           => $mime,
					'format'         => TisaCase_Optimizer_Reference_Scanner::short_format_label( $mime ),
					'date'           => substr( (string) $post->post_date, 0, 10 ),
					'exists'         => $exists,
					'mtime'          => $exists ? (int) @filemtime( $main_abs ) : 0,
					'width'          => $w,
					'height'         => $h,
					'pixels'         => $pixels,
					'main_bytes'     => $main_b,
					'unscaled_bytes' => $unsc_b,
					'unscaled_name'  => $unsc_n,
					'subsizes_bytes' => $sub_b,
					'subsizes_count' => $sub_c,
					'sidecar_bytes'  => $side_b,
					'total_bytes'    => $tot_b,
					'density'        => $pixels > 0 ? round( ( $main_b / $pixels ) * 1000000 ) : $main_b,
					'is_opaque_png'  => ( $exists && 'image/png' === $mime ) ? TisaCase_Optimizer_Reference_Scanner::fast_detect_opaque_png( $main_abs ) : false,
					'has_webp'       => $has_webp,
					'is_optimized'   => is_array( $opt_meta ) && ! empty( $opt_meta['saved_bytes'] ),
					'saved_bytes'    => ( is_array( $opt_meta ) && isset( $opt_meta['saved_bytes'] ) ) ? (int) $opt_meta['saved_bytes'] : 0,
					'sha1'           => isset( $prev['sha1'] ) ? (string) $prev['sha1'] : '',
					'alt'            => $alt_txt,
					'parent_id'      => absint( $post->post_parent ),
					'is_pinned'      => ! empty( $prev['is_pinned'] ),
					'usage_status'   => isset( $prev['usage_status'] ) ? (string) $prev['usage_status'] : ( $exists ? 'strict_unused' : 'missing_file' ),
					'refs_count'     => isset( $prev['refs_count'] ) ? (int) $prev['refs_count'] : 0,
					'refs'           => isset( $prev['refs'] ) && is_array( $prev['refs'] ) ? $prev['refs'] : array(),
					'thumb_url'      => ( '' !== $baseurl && '' !== $th_f ) ? $baseurl . $th_f : ( ( '' !== $baseurl && '' !== $rel_file ) ? $baseurl . $rel_file : '' ),
					'full_url'       => ( '' !== $baseurl && '' !== $rel_file ) ? $baseurl . $rel_file : '',
				);
			}

			TisaCase_Optimizer_Reference_Scanner::write_persisted_catalog( $raw );
			self::flush_runtime_cache();
		}

		/**
		 * خلاصهٔ آماری کامل داشبورد تصاویر و Quick Wins.
		 *
		 * @param bool $force_rescan بازسازی اجباری اسکن.
		 * @return array<string,mixed>
		 */
		public static function overview_stats( $force_rescan = false ) {
			// میان‌بُر: اگر خلاصهٔ معتبر روی دیسک باشد، کاتالوگ سنگین اصلاً
			// رمزگشایی نمی‌شود. اعداد زندهٔ خزانه و آمار عمر افزونه همیشه تازه
			// اضافه می‌شوند تا هیچ عددی قدیمی نماند.
			if ( ! $force_rescan ) {
				$cached_ov = TisaCase_Optimizer_Catalog_Summary::overview();
				if ( is_array( $cached_ov ) ) {
					$vault_live = TisaCase_Optimizer_Vault::summary();
					$cached_ov['vault']            = $vault_live;
					$cached_ov['lifetime_stats']   = TisaCase_Optimizer_Settings::stats();
					if ( isset( $cached_ov['layers'] ) && is_array( $cached_ov['layers'] ) ) {
						$cached_ov['layers']['quarantine_bytes'] = (int) $vault_live['quarantine_bytes'] + (int) $vault_live['originals_bytes'];
					}
					$cached_ov['next_actions'] = self::build_next_actions( $cached_ov );
					return $cached_ov;
				}
			}

			$cat   = self::build_catalog( $force_rescan );
			$items = $cat['items'];
			$index = $cat['index'];
			$vault = TisaCase_Optimizer_Vault::summary();
			$saved = TisaCase_Optimizer_Settings::stats();

			$sum_main       = 0;
			$sum_unscaled   = 0;
			$sum_subsizes   = 0;
			$sum_sidecar    = 0;
			$unused_count   = 0;
			$unused_bytes   = 0;
			$draft_count    = 0;
			$draft_bytes    = 0;
			$missing_count  = 0;
			$unscaled_count = 0;
			$heavy_count    = 0;
			$heavy_est_save = 0;
			$opaque_png_cnt = 0;
			$missing_alt    = 0;
			$formats        = array(
				'JPEG'  => array( 'count' => 0, 'bytes' => 0 ),
				'PNG'   => array( 'count' => 0, 'bytes' => 0 ),
				'WEBP'  => array( 'count' => 0, 'bytes' => 0 ),
				'AVIF'  => array( 'count' => 0, 'bytes' => 0 ),
				'OTHER' => array( 'count' => 0, 'bytes' => 0 ),
			);
			$hash_buckets   = array();

			foreach ( $items as $it ) {
				$sum_main     += $it['main_bytes'];
				$sum_unscaled += $it['unscaled_bytes'];
				$sum_subsizes += $it['subsizes_bytes'];
				$sum_sidecar  += $it['sidecar_bytes'];

				$f = isset( $formats[ $it['format'] ] ) ? $it['format'] : 'OTHER';
				++$formats[ $f ]['count'];
				$formats[ $f ]['bytes'] += $it['total_bytes'];

				if ( 'strict_unused' === $it['usage_status'] ) {
					++$unused_count;
					$unused_bytes += $it['total_bytes'];
				} elseif ( 'draft_only' === $it['usage_status'] ) {
					++$draft_count;
					$draft_bytes += $it['total_bytes'];
				} elseif ( 'missing_file' === $it['usage_status'] ) {
					++$missing_count;
				}

				if ( $it['unscaled_bytes'] > 0 ) {
					++$unscaled_count;
				}
				if ( $it['is_opaque_png'] ) {
					++$opaque_png_cnt;
				}
				if ( '' === $it['alt'] ) {
					++$missing_alt;
				}
				if ( ! $it['is_optimized'] && ( $it['main_bytes'] > 300 * 1024 || $it['is_opaque_png'] ) ) {
					++$heavy_count;
					$ratio           = $it['is_opaque_png'] ? 0.78 : ( 'WEBP' === $it['format'] ? 0.20 : 0.48 );
					$heavy_est_save += (int) round( ( $it['main_bytes'] + $it['subsizes_bytes'] ) * $ratio );
				}
				if ( '' !== $it['sha1'] && $it['exists'] ) {
					$hash_buckets[ $it['sha1'] ][] = $it;
				}
			}

			$dup_groups     = 0;
			$dup_extra_cnt  = 0;
			$dup_save_bytes = 0;
			foreach ( $hash_buckets as $group ) {
				if ( count( $group ) < 2 ) {
					continue;
				}
				++$dup_groups;
				$dup_extra_cnt += count( $group ) - 1;
				for ( $i = 1, $n = count( $group ); $i < $n; $i++ ) {
					$dup_save_bytes += $group[ $i ]['total_bytes'];
				}
			}

			$orphans      = isset( $index['orphans'] ) && is_array( $index['orphans'] ) ? $index['orphans'] : array();
			$orphan_bytes = 0;
			foreach ( $orphans as $o ) {
				$orphan_bytes += (int) $o['bytes'];
			}

			$media_total = $sum_main + $sum_unscaled + $sum_subsizes + $sum_sidecar;

			$overview = array(
				'needs_scan'     => false,
				'scan_built_at'  => isset( $index['built_at'] ) ? $index['built_at'] : '',
				// سن کاتالوگ (ساعت) — برای یادآوری مهربان «اسکن مجدد» وقتی قدیمی شده.
				'scan_age_hours' => isset( $index['timestamp'] )
					? (int) round( max( 0, ( microtime( true ) - (float) $index['timestamp'] ) / HOUR_IN_SECONDS ) )
					: null,
				'scan_ms'        => isset( $index['duration_ms'] ) ? (int) $index['duration_ms'] : 0,
				'total_images'   => count( $items ),
				'total_inodes'   => max( count( $items ), isset( $index['total_inodes'] ) ? (int) $index['total_inodes'] : 0 ),
				'uploads_bytes'  => max( $media_total, isset( $index['disk_bytes'] ) ? (int) $index['disk_bytes'] : 0 ),
				'media_bytes'    => $media_total,
				'layers'         => array(
					'main_bytes'       => $sum_main,
					'subsizes_bytes'   => $sum_subsizes,
					'unscaled_bytes'   => $sum_unscaled,
					'sidecar_bytes'    => $sum_sidecar,
					'quarantine_bytes' => $vault['quarantine_bytes'] + $vault['originals_bytes'],
				),
				'formats'        => $formats,
				'quick_wins'     => array(
					'unused'     => array(
						'count'        => $unused_count,
						'bytes'        => $unused_bytes,
						'draft_count'  => $draft_count,
						'draft_bytes'  => $draft_bytes,
						'orphan_count' => count( $orphans ),
						'orphan_bytes' => $orphan_bytes,
						'missing_db'   => $missing_count,
					),
					'unscaled'   => array(
						'count' => $unscaled_count,
						'bytes' => $sum_unscaled,
					),
					'compress'   => array(
						'count'      => $heavy_count,
						'opaque_png' => $opaque_png_cnt,
						'est_bytes'  => $heavy_est_save,
					),
					'duplicates' => array(
						'groups' => $dup_groups,
						'count'  => $dup_extra_cnt,
						'bytes'  => $dup_save_bytes,
					),
					'seo'        => array(
						'missing_alt' => $missing_alt,
					),
				),
				'vault'          => $vault,
				'lifetime_stats' => $saved,
			);

			$overview['next_actions'] = self::build_next_actions( $overview );

			// نوشتن خلاصه برای درخواست‌های بعدی (میان‌بُر امن؛ با هر تغییر کاتالوگ باطل می‌شود).
			TisaCase_Optimizer_Catalog_Summary::write( $overview, $items );
			return $overview;
		}

		/**
		 * «قدم بعدی چیست؟» — فهرست کوتاه و اولویت‌دار کارهای پیشنهادی.
		 *
		 * چرا؟ داشبورد کلی عدد نشان می‌داد ولی کاربر باید خودش حدس می‌زد از کجا
		 * شروع کند. اینجا فقط کارهایی می‌آید که واقعاً کاری برای انجام دادن هست،
		 * هر کدام با تعداد، حجم تقریبی و دکمهٔ رفتن به همان جای درست.
		 *
		 * @param array<string,mixed> $ov آمار کلی.
		 * @return array<int,array<string,mixed>>
		 */
		public static function build_next_actions( array $ov ) {
			$actions = array();

			if ( ! empty( $ov['needs_scan'] ) ) {
				$actions[] = array(
					'key'   => 'scan',
					'tone'  => 'warn',
					'title' => __( 'اول اسکن کامل تصاویر را اجرا کنید', 'tisacase-optimizer' ),
					'desc'  => __( 'بدون اسکن کامل، هیچ حذفی انجام نمی‌شود (برای محافظت از تصاویر در حال استفاده).', 'tisacase-optimizer' ),
					'count' => 0,
					'bytes' => 0,
					'cta'   => 'scan',
					'label' => __( 'شروع اسکن کامل', 'tisacase-optimizer' ),
				);
				return $actions;
			}

			$qw = isset( $ov['quick_wins'] ) && is_array( $ov['quick_wins'] ) ? $ov['quick_wins'] : array();
			$vault = isset( $ov['vault'] ) && is_array( $ov['vault'] ) ? $ov['vault'] : array();

			$unused = isset( $qw['unused'] ) ? $qw['unused'] : array();
			if ( ! empty( $unused['count'] ) ) {
				$actions[] = array(
					'key'   => 'unused',
					'tone'  => 'ok',
					'title' => __( 'تصاویر مطلقاً بدون استفاده را مرور کنید', 'tisacase-optimizer' ),
					'desc'  => __( 'هیچ‌جای سایت استفاده نمی‌شوند؛ انتقال به قرنطینه ۳۰ روزه با یک کلیک قابل بازگشت است.', 'tisacase-optimizer' ),
					'count' => (int) $unused['count'],
					'bytes' => (int) $unused['bytes'],
					'cta'   => 'unused',
					'label' => __( 'دیدن فهرست', 'tisacase-optimizer' ),
				);
			}

			// رکوردهای بدون فایل: عکس در صفحه شکسته (۴۰۴) دیده می‌شود ولی فایلی
			// برای آزادکردن نیست؛ ارزش کار «مرتب‌کردن کتابخانه» است، نه فضا.
			if ( ! empty( $unused['missing_db'] ) ) {
				$actions[] = array(
					'key'   => 'missing_file',
					'tone'  => 'warn',
					'title' => __( 'رکوردهای بدون فایل (۴۰۴) را مرتب کنید', 'tisacase-optimizer' ),
					/* translators: %d: number of attachment records without a file. */
					'desc'  => sprintf(
						__( '%d رکورد در کتابخانه هست ولی فایلش روی هاست نیست؛ در صفحهٔ سایت جای عکس، تصویر شکسته دیده می‌شود. با یک کلیک فقط رکورد پاک/قرنطینه می‌شود (فایلی برای حذف وجود ندارد).', 'tisacase-optimizer' ),
						(int) $unused['missing_db']
					),
					'count' => (int) $unused['missing_db'],
					'bytes' => 0,
					'cta'   => 'missing_file',
					'label' => __( 'دیدن فهرست', 'tisacase-optimizer' ),
				);
			}

			$compress = isset( $qw['compress'] ) ? $qw['compress'] : array();
			if ( ! empty( $compress['count'] ) ) {
				$actions[] = array(
					'key'   => 'compress',
					'tone'  => 'ok',
					'title' => __( 'تصاویر سنگین را فشرده کنید', 'tisacase-optimizer' ),
					'desc'  => __( 'با «فشرده‌سازی و جایگزینی» فایل سبک‌تر می‌شود و حجم دیسک واقعاً کم می‌شود.', 'tisacase-optimizer' ),
					'count' => (int) $compress['count'],
					'bytes' => (int) ( isset( $compress['est_bytes'] ) ? $compress['est_bytes'] : 0 ),
					'cta'   => 'optimize_all',
					'label' => __( 'فشرده‌سازی همه', 'tisacase-optimizer' ),
				);
			}

			$unscaled = isset( $qw['unscaled'] ) ? $qw['unscaled'] : array();
			if ( ! empty( $unscaled['count'] ) ) {
				$actions[] = array(
					'key'   => 'unscaled',
					'tone'  => 'ok',
					'title' => __( 'فایل‌های خام «-scaled» را پاک کنید', 'tisacase-optimizer' ),
					'desc'  => __( 'وردپرس نسخهٔ اصلی سنگین را نگه می‌دارد؛ اگر به آن نیاز ندارید، پاک شود.', 'tisacase-optimizer' ),
					'count' => (int) $unscaled['count'],
					'bytes' => (int) $unscaled['bytes'],
					'cta'   => 'clean_unscaled',
					'label' => __( 'پاک‌سازی فایل‌های خام', 'tisacase-optimizer' ),
				);
			}

			$dups = isset( $qw['duplicates'] ) ? $qw['duplicates'] : array();
			if ( ! empty( $dups['groups'] ) ) {
				$actions[] = array(
					'key'   => 'duplicates',
					'tone'  => 'ok',
					'title' => __( 'تصاویر تکراری را ادغام کنید', 'tisacase-optimizer' ),
					'desc'  => __( 'فایل‌هایی با هش یکسان؛ ادغام بدون شکستن لینک‌ها انجام می‌شود.', 'tisacase-optimizer' ),
					'count' => (int) $dups['count'],
					'bytes' => (int) $dups['bytes'],
					'cta'   => 'duplicates',
					'label' => __( 'بررسی تکراری‌ها', 'tisacase-optimizer' ),
				);
			}

			$seo = isset( $qw['seo'] ) ? $qw['seo'] : array();
			if ( ! empty( $seo['missing_alt'] ) ) {
				$actions[] = array(
					'key'   => 'seo',
					'tone'  => 'ok',
					'title' => __( 'برای تصاویر متن جایگزین (alt) بنویسید', 'tisacase-optimizer' ),
					'desc'  => __( 'هم سئوی گوگل و هم دسترس‌پذیری بهتر می‌شود؛ پیشنهاد خودکار آماده است.', 'tisacase-optimizer' ),
					'count' => (int) $seo['missing_alt'],
					'bytes' => 0,
					'cta'   => 'seo',
					'label' => __( 'تکمیل متن‌ها', 'tisacase-optimizer' ),
				);
			}

			if ( ! empty( $vault['quarantine_count'] ) ) {
				$actions[] = array(
					'key'   => 'quarantine',
					'tone'  => 'info',
					'title' => __( 'قرنطینه را بازبینی کنید', 'tisacase-optimizer' ),
					'desc'  => __( 'موارد قرنطینه‌شده تا ۳۰ روز با همان شناسه و زیرسایزها قابل بازگردانی هستند.', 'tisacase-optimizer' ),
					'count' => (int) $vault['quarantine_count'],
					'bytes' => (int) $vault['quarantine_bytes'],
					'cta'   => 'quarantine',
					'label' => __( 'باز کردن قرنطینه', 'tisacase-optimizer' ),
				);
			}

			if ( empty( $actions ) ) {
				$actions[] = array(
					'key'   => 'done',
					'tone'  => 'ok',
					'title' => __( 'همه‌چیز مرتب است', 'tisacase-optimizer' ),
					'desc'  => __( 'در این لحظه کار ضروری باقی نمانده؛ با «اسکن کامل» وضعیت را تازه نگه دارید.', 'tisacase-optimizer' ),
					'count' => 0,
					'bytes' => 0,
					'cta'   => 'scan',
					'label' => __( 'اسکن تازه', 'tisacase-optimizer' ),
				);
			}

			return $actions;
		}

		/**
		 * جستجو، فیلتر و مرتب‌سازی فوری تصاویر از روی کاتالوگ حافظه/دیسک.
		 *
		 * @param array<string,mixed> $args پارامترهای کوئری.
		 * @return array<string,mixed>
		 */
		public static function query_items( array $args ) {
			$cat      = self::build_catalog( false );
			$search   = isset( $args['search'] ) ? mb_strtolower( trim( sanitize_text_field( (string) $args['search'] ) ) ) : '';
			$min_size = isset( $args['min_bytes'] ) ? max( 0, (int) $args['min_bytes'] ) : 0;
			$format   = isset( $args['format'] ) ? strtoupper( sanitize_text_field( (string) $args['format'] ) ) : 'ALL';
			$usage    = isset( $args['usage'] ) ? sanitize_key( (string) $args['usage'] ) : 'all';
			$opt_flt  = isset( $args['opt_state'] ) ? sanitize_key( (string) $args['opt_state'] ) : 'all';
			$sort_by  = isset( $args['sort_by'] ) ? sanitize_key( (string) $args['sort_by'] ) : 'total_bytes';
			$order    = ( isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ) ? 'asc' : 'desc';
			$page = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );

			// «تعداد در هر صفحه» انتخاب کاربر است (پیش‌فرض ۲۵). صفر = همهٔ موارد
			// (فقط در فهرست‌های سبک استفاده می‌شود). سقف ۵۰۰ برای محافظت از حافظه.
			$per_page_raw = isset( $args['per_page'] ) && '' !== $args['per_page']
				? (int) $args['per_page']
				: (int) TisaCase_Optimizer_Settings::get( 'per_page', 25 );
			$per_page = $per_page_raw > 0 ? max( 10, min( 500, $per_page_raw ) ) : 0; // 0 = همهٔ موارد

			if ( 'unregistered_disk' === $usage ) {
				$orphans = isset( $cat['index']['orphans'] ) ? (array) $cat['index']['orphans'] : array();
				if ( '' !== $search ) {
					$orphans = array_values(
						array_filter(
							$orphans,
							static function ( $o ) use ( $search ) {
								return false !== mb_strpos( mb_strtolower( (string) $o['rel_path'] ), $search );
							}
						)
					);
				}
				$total       = count( $orphans );
				$per_page    = $per_page > 0 ? $per_page : max( 1, $total );
				$total_bytes = 0;
				foreach ( $orphans as $o ) {
					$total_bytes += (int) $o['bytes'];
				}
				return array(
					'mode'          => 'disk_orphans',
					'total'         => $total,
					'matched_bytes' => $total_bytes,
					'page'          => $page,
					'pages'         => max( 1, (int) ceil( $total / $per_page ) ),
					'per_page'      => (int) $per_page,
					'rows'          => array_slice( $orphans, ( $page - 1 ) * $per_page, $per_page ),
				);
			}

			$matched = array();
			foreach ( $cat['items'] as $it ) {
				if ( $min_size > 0 && $it['total_bytes'] < $min_size ) {
					continue;
				}
				if ( 'OPAQUE_PNG' === $format && ! $it['is_opaque_png'] ) {
					continue;
				} elseif ( ! in_array( $format, array( 'ALL', '', 'OPAQUE_PNG' ), true ) && $it['format'] !== $format ) {
					continue;
				}
				if ( 'all' !== $usage && '' !== $usage && $it['usage_status'] !== $usage ) {
					continue;
				}
				if ( 'unoptimized' === $opt_flt && $it['is_optimized'] ) {
					continue;
				} elseif ( 'optimized' === $opt_flt && ! $it['is_optimized'] ) {
					continue;
				} elseif ( 'not_webp' === $opt_flt && ( 'WEBP' === $it['format'] || 'image/webp' === $it['mime'] ) ) {
					// «هنوز با WebP جایگزین نشده»: چه بهینه‌شده باشد (نسخهٔ موازی) چه نباشد.
					continue;
				} elseif ( 'has_unscaled' === $opt_flt && $it['unscaled_bytes'] <= 0 ) {
					continue;
				} elseif ( 'missing_alt' === $opt_flt && '' !== $it['alt'] ) {
					continue;
				}

				if ( '' !== $search ) {
					$haystack = mb_strtolower(
						$it['filename'] . ' ' . $it['title'] . ' #' . $it['id'] . ' ' . $it['rel_path'] . ' ' . $it['alt']
					);
					foreach ( $it['refs'] as $r ) {
						$haystack .= ' ' . mb_strtolower( (string) $r['label'] );
					}
					if ( false === mb_strpos( $haystack, $search ) ) {
						continue;
					}
				}

				$matched[] = $it;
			}

			$valid_sorts = array( 'total_bytes', 'main_bytes', 'unscaled_bytes', 'pixels', 'subsizes_count', 'density', 'id' );
			if ( ! in_array( $sort_by, $valid_sorts, true ) ) {
				$sort_by = 'total_bytes';
			}

			usort(
				$matched,
				static function ( $a, $b ) use ( $sort_by, $order ) {
					$cmp = $a[ $sort_by ] <=> $b[ $sort_by ];
					if ( 0 === $cmp ) {
						$cmp = $a['id'] <=> $b['id'];
					}
					return 'asc' === $order ? $cmp : -$cmp;
				}
			);

			$total_bytes = 0;
			$all_ids     = array();
			foreach ( $matched as $m ) {
				$total_bytes += $m['total_bytes'];
				$all_ids[]    = $m['id'];
			}

			$total    = count( $matched );
			$per_page = $per_page > 0 ? $per_page : max( 1, $total ); // «همه» یعنی یک صفحه
			return array(
				'mode'          => 'attachments',
				'total'         => $total,
				'matched_bytes' => $total_bytes,
				'matching_ids'  => array_slice( $all_ids, 0, 5000 ),
				'page'          => $page,
				'pages'         => max( 1, (int) ceil( $total / $per_page ) ),
				'per_page'      => (int) $per_page,
				'rows'          => array_slice( $matched, ( $page - 1 ) * $per_page, $per_page ),
			);
		}
	}
}
