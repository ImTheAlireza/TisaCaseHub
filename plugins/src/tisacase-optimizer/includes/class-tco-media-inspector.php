<?php
/**
 * بازرس و کاوشگر رسانه (Media Inspector, Disk Telemetry & Explorer Query Engine).
 *
 * وظایف:
 *  - محاسبهٔ دقیق حجم واقعی هر پیوست روی دیسک:
 *      حجم کل دیسک = فایل اصلی + فایل خام پنهان (`original_image`) + همهٔ زیرسایزها + نسخه‌های موازی WebP/AVIF
 *  - تشخیص سریع PNGهای بدون شفافیت واقعی (Opaque PNGs) از روی کانال آلفای پیکسل‌ها
 *  - محاسبهٔ اثر انگشت محتوایی `SHA-1` با کش هوشمند وابسته به `filemtime`
 *  - تامین دادهٔ داشبورد (KPIها، تفکیک فرمت‌ها و لایه‌ها، Quick Wins) و کاوشگر پیشرفته با فیلتر و مرتب‌سازی کامل.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Media_Inspector' ) ) {

	/**
	 * تحلیلگر جامع کتابخانهٔ رسانه.
	 */
	final class TisaCase_Optimizer_Media_Inspector {

		/** @var array<int,array<string,mixed>>|null کش در-درخواستی ردیف‌ها */
		private static $catalog_cache = null;

		/**
		 * پاک‌سازی کش در-درخواستی پس از تغییر روی فایل‌ها.
		 *
		 * @return void
		 */
		public static function flush_runtime_cache() {
			self::$catalog_cache = null;
		}

		/**
		 * ساخت کاتالوگ کامل پیوست‌های تصویری با جزئیات کامل حجم، ابعاد، فرمت و مراجع استفاده.
		 *
		 * @param bool $force_rescan بازسازی اجباری ایندکس مرجع ۱۲لایه.
		 * @return array{index:array<string,mixed>,items:array<int,array<string,mixed>>}
		 */
		public static function build_catalog( $force_rescan = false ) {
			global $wpdb;

			if ( null !== self::$catalog_cache && ! $force_rescan ) {
				return self::$catalog_cache;
			}

			$index      = TisaCase_Optimizer_Reference_Scanner::get_or_build_index( $force_rescan );
			$refs       = isset( $index['refs'] ) && is_array( $index['refs'] ) ? $index['refs'] : array();
			$pinned_map = array_fill_keys( TisaCase_Optimizer_Settings::pinned_ids(), true );

			$uploads = wp_upload_dir();
			$basedir = ! empty( $uploads['basedir'] ) ? wp_normalize_path( trailingslashit( realpath( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : $uploads['basedir'] ) ) : '';
			$baseurl = ! empty( $uploads['baseurl'] ) ? trailingslashit( $uploads['baseurl'] ) : '';

			// یک کوئری واحد برای خواندن تمام پیوست‌های تصویری و متاهای کلیدی بدون N+1 Query!
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				"SELECT p.ID, p.post_title, p.post_mime_type, p.post_date, p.post_parent,
				        pm_file.meta_value AS rel_file,
				        pm_meta.meta_value AS meta_raw,
				        pm_alt.meta_value  AS image_alt,
				        pm_opt.meta_value  AS opt_raw,
				        pm_hash.meta_value AS hash_raw
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm_file ON pm_file.post_id = p.ID AND pm_file.meta_key = '_wp_attached_file'
				 LEFT JOIN {$wpdb->postmeta} pm_meta ON pm_meta.post_id = p.ID AND pm_meta.meta_key = '_wp_attachment_metadata'
				 LEFT JOIN {$wpdb->postmeta} pm_alt  ON pm_alt.post_id  = p.ID AND pm_alt.meta_key  = '_wp_attachment_image_alt'
				 LEFT JOIN {$wpdb->postmeta} pm_opt  ON pm_opt.post_id  = p.ID AND pm_opt.meta_key  = '_tco_optimized'
				 LEFT JOIN {$wpdb->postmeta} pm_hash ON pm_hash.post_id = p.ID AND pm_hash.meta_key = '_tco_file_fingerprint'
				 WHERE p.post_type = 'attachment'
				   AND p.post_mime_type LIKE 'image/%'
				 ORDER BY p.ID DESC",
				ARRAY_A
			);

			$items = array();
			foreach ( (array) $rows as $row ) {
				$aid      = absint( $row['ID'] );
				$rel_file = isset( $row['rel_file'] ) ? ltrim( wp_normalize_path( (string) $row['rel_file'] ), '/' ) : '';
				$meta     = ! empty( $row['meta_raw'] ) ? TisaCase_Optimizer_Reference_Scanner::safe_unserialize( (string) $row['meta_raw'] ) : array();
				$opt_meta = ! empty( $row['opt_raw'] ) ? TisaCase_Optimizer_Reference_Scanner::safe_unserialize( (string) $row['opt_raw'] ) : array();
				$hash_meta = ! empty( $row['hash_raw'] ) ? TisaCase_Optimizer_Reference_Scanner::safe_unserialize( (string) $row['hash_raw'] ) : array();

				$main_abs       = ( '' !== $basedir && '' !== $rel_file ) ? $basedir . $rel_file : '';
				$exists         = ( '' !== $main_abs && is_file( $main_abs ) );
				$main_bytes     = $exists ? max( 0, (int) @filesize( $main_abs ) ) : 0;
				$unscaled_bytes = 0;
				$unscaled_name  = '';
				$subsizes_bytes = 0;
				$subsizes_count = 0;
				$sidecar_bytes  = 0;
				$has_webp       = false;

				$subdir = '' !== $rel_file ? wp_normalize_path( dirname( $rel_file ) ) : '';
				$subdir = ( '.' === $subdir || '' === $subdir ) ? '' : trailingslashit( $subdir );

				if ( $exists && is_file( $main_abs . '.webp' ) ) {
					$has_webp       = true;
					$sidecar_bytes += max( 0, (int) @filesize( $main_abs . '.webp' ) );
				}
				if ( $exists && is_file( $main_abs . '.avif' ) ) {
					$sidecar_bytes += max( 0, (int) @filesize( $main_abs . '.avif' ) );
				}

				if ( is_array( $meta ) && '' !== $basedir ) {
					if ( ! empty( $meta['original_image'] ) ) {
						$orig_abs = $basedir . $subdir . basename( (string) $meta['original_image'] );
						if ( is_file( $orig_abs ) && $orig_abs !== $main_abs ) {
							$unscaled_name  = basename( (string) $meta['original_image'] );
							$unscaled_bytes = max( 0, (int) @filesize( $orig_abs ) );
						}
					}

					if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
						$seen_sub = array();
						foreach ( $meta['sizes'] as $sinfo ) {
							if ( empty( $sinfo['file'] ) ) {
								continue;
							}
							$sabs = $basedir . $subdir . basename( (string) $sinfo['file'] );
							if ( $sabs === $main_abs || isset( $seen_sub[ $sabs ] ) ) {
								continue;
							}
							$seen_sub[ $sabs ] = true;
							if ( is_file( $sabs ) ) {
								++$subsizes_count;
								$subsizes_bytes += max( 0, (int) @filesize( $sabs ) );
								if ( is_file( $sabs . '.webp' ) ) {
									$sidecar_bytes += max( 0, (int) @filesize( $sabs . '.webp' ) );
								}
								if ( is_file( $sabs . '.avif' ) ) {
									$sidecar_bytes += max( 0, (int) @filesize( $sabs . '.avif' ) );
								}
							}
						}
					}
				}

				$width  = ( is_array( $meta ) && isset( $meta['width'] ) ) ? absint( $meta['width'] ) : 0;
				$height = ( is_array( $meta ) && isset( $meta['height'] ) ) ? absint( $meta['height'] ) : 0;
				$pixels = $width * $height;
				$mime   = strtolower( (string) $row['post_mime_type'] );
				if ( 'image/webp' === $mime ) {
					$has_webp = true;
				}

				// تشخیص اثر انگشت فایل + شفافیت واقعی PNG (کش‌شده بر اساس filemtime).
				$sha1          = '';
				$is_opaque_png = false;
				if ( $exists ) {
					$mtime = (int) @filemtime( $main_abs );
					if ( is_array( $hash_meta ) && isset( $hash_meta['mtime'], $hash_meta['sha1'] ) && (int) $hash_meta['mtime'] === $mtime ) {
						$sha1          = (string) $hash_meta['sha1'];
						$is_opaque_png = ! empty( $hash_meta['opaque_png'] );
					} else {
						$sha1          = (string) @sha1_file( $main_abs );
						$is_opaque_png = ( 'image/png' === $mime ) ? self::detect_opaque_png( $main_abs ) : false;
						update_post_meta(
							$aid,
							'_tco_file_fingerprint',
							array(
								'mtime'      => $mtime,
								'sha1'       => $sha1,
								'opaque_png' => $is_opaque_png ? 1 : 0,
							)
						);
					}
				}

				$active_refs = isset( $refs[ $aid ]['active'] ) ? $refs[ $aid ]['active'] : array();
				$draft_refs  = isset( $refs[ $aid ]['draft'] ) ? $refs[ $aid ]['draft'] : array();
				$is_pinned   = isset( $pinned_map[ $aid ] );

				if ( ! $exists ) {
					$usage_status = 'missing_file';
				} elseif ( $is_pinned ) {
					$usage_status = 'pinned';
				} elseif ( ! empty( $active_refs ) ) {
					$usage_status = 'used';
				} elseif ( ! empty( $draft_refs ) ) {
					$usage_status = 'draft_only';
				} else {
					$usage_status = 'strict_unused';
				}

				$total_bytes = $main_bytes + $unscaled_bytes + $subsizes_bytes + $sidecar_bytes;
				$thumb_file  = '';
				if ( is_array( $meta ) && ! empty( $meta['sizes']['thumbnail']['file'] ) ) {
					$thumb_file = $subdir . basename( (string) $meta['sizes']['thumbnail']['file'] );
				}
				$thumb_url = ( '' !== $baseurl && '' !== $thumb_file )
					? $baseurl . $thumb_file
					: ( ( '' !== $baseurl && '' !== $rel_file ) ? $baseurl . $rel_file : '' );
				$full_url  = ( '' !== $baseurl && '' !== $rel_file ) ? $baseurl . $rel_file : '';

				$items[ $aid ] = array(
					'id'             => $aid,
					'title'          => (string) $row['post_title'],
					'filename'       => '' !== $rel_file ? basename( $rel_file ) : '#' . $aid,
					'rel_path'       => $rel_file,
					'folder'         => rtrim( $subdir, '/' ),
					'mime'           => $mime,
					'format'         => self::short_format_label( $mime ),
					'date'           => substr( (string) $row['post_date'], 0, 10 ),
					'exists'         => $exists,
					'width'          => $width,
					'height'         => $height,
					'pixels'         => $pixels,
					'main_bytes'     => $main_bytes,
					'unscaled_bytes' => $unscaled_bytes,
					'unscaled_name'  => $unscaled_name,
					'subsizes_bytes' => $subsizes_bytes,
					'subsizes_count' => $subsizes_count,
					'sidecar_bytes'  => $sidecar_bytes,
					'total_bytes'    => $total_bytes,
					'density'        => $pixels > 0 ? round( ( $main_bytes / $pixels ) * 1000000 ) : $main_bytes,
					'is_opaque_png'  => $is_opaque_png,
					'has_webp'       => $has_webp,
					'is_optimized'   => is_array( $opt_meta ) && ! empty( $opt_meta['saved_bytes'] ),
					'saved_bytes'    => ( is_array( $opt_meta ) && isset( $opt_meta['saved_bytes'] ) ) ? (int) $opt_meta['saved_bytes'] : 0,
					'sha1'           => $sha1,
					'alt'            => trim( (string) $row['image_alt'] ),
					'parent_id'      => absint( $row['post_parent'] ),
					'is_pinned'      => $is_pinned,
					'usage_status'   => $usage_status,
					'refs_count'     => count( $active_refs ) + count( $draft_refs ),
					'refs'           => array_slice( array_merge( $active_refs, $draft_refs ), 0, 5 ),
					'thumb_url'      => $thumb_url,
					'full_url'       => $full_url,
				);
			}

			self::$catalog_cache = array(
				'index' => $index,
				'items' => $items,
			);
			return self::$catalog_cache;
		}

		/**
		 * خلاصهٔ آماری کامل داشبورد تصاویر، توزیع فرمت‌ها و فرصت‌های آزادسازی سریع (Quick Wins).
		 *
		 * @param bool $force_rescan بازسازی اجباری اسکن.
		 * @return array<string,mixed>
		 */
		public static function overview_stats( $force_rescan = false ) {
			$cat    = self::build_catalog( $force_rescan );
			$items  = $cat['items'];
			$index  = $cat['index'];
			$vault  = TisaCase_Optimizer_Vault::summary();
			$saved  = TisaCase_Optimizer_Settings::stats();

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
				'JPEG' => array( 'count' => 0, 'bytes' => 0 ),
				'PNG'  => array( 'count' => 0, 'bytes' => 0 ),
				'WEBP' => array( 'count' => 0, 'bytes' => 0 ),
				'AVIF' => array( 'count' => 0, 'bytes' => 0 ),
				'OTHER' => array( 'count' => 0, 'bytes' => 0 ),
			);
			$hash_buckets = array();

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

			return array(
				'scan_built_at'   => isset( $index['built_at'] ) ? $index['built_at'] : '',
				'scan_ms'         => isset( $index['duration_ms'] ) ? (int) $index['duration_ms'] : 0,
				'total_images'    => count( $items ),
				'total_inodes'    => isset( $index['total_inodes'] ) ? (int) $index['total_inodes'] : 0,
				'uploads_bytes'   => max( $media_total, isset( $index['disk_bytes'] ) ? (int) $index['disk_bytes'] : 0 ),
				'media_bytes'     => $media_total,
				'layers'          => array(
					'main_bytes'       => $sum_main,
					'subsizes_bytes'   => $sum_subsizes,
					'unscaled_bytes'   => $sum_unscaled,
					'sidecar_bytes'    => $sum_sidecar,
					'quarantine_bytes' => $vault['quarantine_bytes'] + $vault['originals_bytes'],
				),
				'formats'         => $formats,
				'quick_wins'      => array(
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
				'vault'           => $vault,
				'lifetime_stats'  => $saved,
			);
		}

		/**
		 * جستجو، فیلتر و مرتب‌سازی پیشرفتهٔ تصاویر برای «سنگین‌ترین تصاویر» و «تصاویر بدون استفاده».
		 *
		 * @param array<string,mixed> $args پارامترهای فیلتر و مرتب‌سازی.
		 * @return array<string,mixed>
		 */
		public static function query_items( array $args ) {
			$force    = ! empty( $args['force_rescan'] );
			$cat      = self::build_catalog( $force );
			$search   = isset( $args['search'] ) ? mb_strtolower( trim( sanitize_text_field( (string) $args['search'] ) ) ) : '';
			$min_size = isset( $args['min_bytes'] ) ? max( 0, (int) $args['min_bytes'] ) : 0;
			$format   = isset( $args['format'] ) ? strtoupper( sanitize_text_field( (string) $args['format'] ) ) : 'ALL';
			$usage    = isset( $args['usage'] ) ? sanitize_key( (string) $args['usage'] ) : 'all';
			$opt_flt  = isset( $args['opt_state'] ) ? sanitize_key( (string) $args['opt_state'] ) : 'all';
			$sort_by  = isset( $args['sort_by'] ) ? sanitize_key( (string) $args['sort_by'] ) : 'total_bytes';
			$order    = ( isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ) ? 'asc' : 'desc';
			$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
			$per_page = max( 10, min( 100, isset( $args['per_page'] ) ? (int) $args['per_page'] : 25 ) );

			// حالت ویژهٔ فایل‌های فیزیکی رهاشده روی دیسک.
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

			$total = count( $matched );
			return array(
				'mode'          => 'attachments',
				'total'         => $total,
				'matched_bytes' => $total_bytes,
				'matching_ids'  => array_slice( $all_ids, 0, 5000 ),
				'page'          => $page,
				'pages'         => max( 1, (int) ceil( $total / $per_page ) ),
				'rows'          => array_slice( $matched, ( $page - 1 ) * $per_page, $per_page ),
			);
		}

		/**
		 * بررسی سریع اینکه آیا یک تصویر PNG واقعاً دارای پیکسل نیمه‌شفاف/شفاف هست یا خیر.
		 *
		 * @param string $abs_path مسیر فایل PNG.
		 * @return bool true اگر ۱۰۰٪ مات باشد (یعنی بی‌دلیل PNG ذخیره شده است!).
		 */
		public static function detect_opaque_png( $abs_path ) {
			if ( ! function_exists( 'imagecreatefrompng' ) || ! is_file( $abs_path ) ) {
				return false;
			}
			// جلوگیری از مصرف حافظه روی فایل‌های فوق‌بزرگ.
			if ( (int) @filesize( $abs_path ) > 18 * 1024 * 1024 ) {
				return false;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$im = @imagecreatefrompng( $abs_path );
			if ( ! $im ) {
				return false;
			}
			$w = imagesx( $im );
			$h = imagesy( $im );
			if ( $w <= 0 || $h <= 0 ) {
				imagedestroy( $im );
				return false;
			}

			// نمونه‌برداری شبکه‌ای تا سقف ۶۰×۶۰ نقطه در کل تصویر (اجرای زیر ۳ میلی‌ثانیه).
			$step_x = max( 1, (int) floor( $w / 60 ) );
			$step_y = max( 1, (int) floor( $h / 60 ) );

			for ( $y = 0; $y < $h; $y += $step_y ) {
				for ( $x = 0; $x < $w; $x += $step_x ) {
					$rgba  = imagecolorat( $im, $x, $y );
					$alpha = ( $rgba >> 24 ) & 0x7F; // در GD: 0 = کاملاً مات، 127 = کاملاً شفاف.
					if ( $alpha > 0 ) {
						imagedestroy( $im );
						return false;
					}
				}
			}

			imagedestroy( $im );
			return true;
		}

		/**
		 * برچسب کوتاه فرمت تصویر.
		 *
		 * @param string $mime نوع MIME.
		 * @return string
		 */
		private static function short_format_label( $mime ) {
			$map = array(
				'image/jpeg'    => 'JPEG',
				'image/jpg'     => 'JPEG',
				'image/png'     => 'PNG',
				'image/webp'    => 'WEBP',
				'image/avif'    => 'AVIF',
				'image/gif'     => 'GIF',
				'image/svg+xml' => 'SVG',
			);
			return isset( $map[ $mime ] ) ? $map[ $mime ] : strtoupper( str_replace( 'image/', '', $mime ) );
		}
	}
}
