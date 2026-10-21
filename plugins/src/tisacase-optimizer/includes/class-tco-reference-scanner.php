<?php
/**
 * اسکنر مرجع‌یاب ۱۲لایهٔ رسانه (12-Layer Deep Reference Index Engine).
 *
 * چرا حیاتی است:
 *  - تفکیک دقیق «مطلقاً بدون استفاده در کل سایت (۰ مرجع)» از «متصل فقط به پیش‌نویس/زباله‌دان»
 *    تا تصاویر محصولات تازه ایمپورت‌شده از ربات تلگرام (`tisa-product-importer`) هرگز اشتباهی
 *    پاک نشوند.
 *  - پوشش کامل `wp_termmeta` (تصویر دسته‌ها، برندها و سواچ‌های رنگ/مدل).
 *  - پوشش دیتای المنتور (`_elementor_data`) شامل مسیرهای JSON-escaped با `\/` و آبجکت‌های `"id":123`.
 *  - نگاشت معکوس زیرسایزهای برش‌خورده (`banner-600x600.jpg` ← پیوست مادر `banner.jpg`).
 *  - اسکن فایل‌های فیزیکی رهاشده روی دیسک (`unregistered_disk`) و رکوردهای شکسته (`missing_file`).
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Reference_Scanner' ) ) {

	/**
	 * موتور ساخت ایندکس مرجع تصاویر و فایل‌های دیسک.
	 */
	final class TisaCase_Optimizer_Reference_Scanner {

		const INDEX_FILE = 'reference-index-v1.json';
		const INDEX_TTL  = 1800; // ۳۰ دقیقه کش معتبر تا جابه‌جایی صفحات آنی باشد.

		/**
		 * خواندن ایندکس کش‌شده یا ساخت خودکار آن در صورت نبود/انقضا.
		 *
		 * @param bool $force_rebuild بازسازی اجباری ایندکس.
		 * @return array<string,mixed>
		 */
		public static function get_or_build_index( $force_rebuild = false ) {
			$file = self::index_path();
			if ( ! $force_rebuild && '' !== $file && is_file( $file ) ) {
				$age = time() - (int) @filemtime( $file );
				if ( $age >= 0 && $age < self::INDEX_TTL ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					$decoded = json_decode( (string) @file_get_contents( $file ), true );
					if ( is_array( $decoded ) && isset( $decoded['refs'] ) ) {
						return $decoded;
					}
				}
			}
			return self::build_full_index();
		}

		/**
		 * باطل کردن کش ایندکس پس از حذف، ادغام یا بازیابی.
		 *
		 * @return void
		 */
		public static function invalidate_cache() {
			$file = self::index_path();
			if ( '' !== $file && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}

		/**
		 * مسیر فایل JSON ایندکس داخل خزانهٔ خصوصی.
		 *
		 * @return string
		 */
		private static function index_path() {
			$root = TisaCase_Optimizer_Vault::base_dir();
			if ( '' === $root ) {
				return '';
			}
			return $root . '/' . TisaCase_Optimizer_Vault::SUB_INDEX . '/' . self::INDEX_FILE;
		}

		/**
		 * اجرای کامل اسکن ۱۲لایه و ذخیرهٔ نتیجه روی دیسک خصوصی.
		 *
		 * @return array<string,mixed>
		 */
		public static function build_full_index() {
			global $wpdb;

			$started_at = microtime( true );
			$refs       = array(); // attachment_id => array( 'active' => [...], 'draft' => [...] ).
			$known_abs  = array(); // مسیرهای شناخته شده روی دیسک برای شکار فایل‌های یتیم.
			$stem_map   = array(); // '2026/10/filename' => attachment_id (بدون پسوند و ابعاد).
			$rel_map    = array(); // '2026/10/filename.jpg' => attachment_id.

			$uploads = wp_upload_dir();
			$basedir = ! empty( $uploads['basedir'] ) ? wp_normalize_path( trailingslashit( realpath( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : $uploads['basedir'] ) ) : '';

			// گام ۰: فهرست تمام پیوست‌های تصویری و ساخت جدول نگاشت مسیر/زیرسایز.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$att_rows = $wpdb->get_results(
				"SELECT p.ID, pm.meta_value AS rel_file, md.meta_value AS meta_raw
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
				 LEFT JOIN {$wpdb->postmeta} md ON md.post_id = p.ID AND md.meta_key = '_wp_attachment_metadata'
				 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'",
				ARRAY_A
			);

			$valid_att_ids = array();
			foreach ( (array) $att_rows as $ar ) {
				$aid = absint( $ar['ID'] );
				if ( $aid <= 0 ) {
					continue;
				}
				$valid_att_ids[ $aid ] = true;
				$rel                   = isset( $ar['rel_file'] ) ? ltrim( wp_normalize_path( (string) $ar['rel_file'] ), '/' ) : '';
				if ( '' !== $rel ) {
					$rel_lower             = strtolower( $rel );
					$rel_map[ $rel_lower ] = $aid;
					$no_ext                = preg_replace( '/\.[a-z0-9]+$/i', '', $rel_lower );
					$base_stem             = preg_replace( '/-scaled$/i', '', (string) $no_ext );
					$stem_map[ $base_stem ] = $aid;

					if ( '' !== $basedir ) {
						$known_abs[ strtolower( $basedir . $rel ) ]         = true;
						$known_abs[ strtolower( $basedir . $rel . '.webp' ) ] = true;
						$known_abs[ strtolower( $basedir . $rel . '.avif' ) ] = true;
					}

					$subdir = wp_normalize_path( dirname( $rel ) );
					$subdir = ( '.' === $subdir ) ? '' : trailingslashit( $subdir );
					$meta   = ! empty( $ar['meta_raw'] ) ? self::safe_unserialize( (string) $ar['meta_raw'] ) : array();
					if ( is_array( $meta ) ) {
						if ( ! empty( $meta['original_image'] ) ) {
							$orig_rel = $subdir . basename( (string) $meta['original_image'] );
							$rel_map[ strtolower( $orig_rel ) ] = $aid;
							if ( '' !== $basedir ) {
								$known_abs[ strtolower( $basedir . $orig_rel ) ] = true;
							}
						}
						if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
							foreach ( $meta['sizes'] as $sinfo ) {
								if ( ! empty( $sinfo['file'] ) ) {
									$srel = $subdir . basename( (string) $sinfo['file'] );
									$rel_map[ strtolower( $srel ) ] = $aid;
									if ( '' !== $basedir ) {
										$known_abs[ strtolower( $basedir . $srel ) ]          = true;
										$known_abs[ strtolower( $basedir . $srel . '.webp' ) ] = true;
										$known_abs[ strtolower( $basedir . $srel . '.avif' ) ] = true;
									}
								}
							}
						}
					}
				}
			}

			$add_ref = static function ( $aid, $bucket, $source_label, $edit_id = 0 ) use ( &$refs, $valid_att_ids ) {
				$aid = absint( $aid );
				if ( $aid <= 0 || ! isset( $valid_att_ids[ $aid ] ) ) {
					return;
				}
				if ( ! isset( $refs[ $aid ] ) ) {
					$refs[ $aid ] = array(
						'active' => array(),
						'draft'  => array(),
					);
				}
				if ( count( $refs[ $aid ][ $bucket ] ) < 8 ) {
					$refs[ $aid ][ $bucket ][] = array(
						'label'   => $source_label,
						'post_id' => absint( $edit_id ),
					);
				}
			};

			// لایهٔ ۱، ۲ و ۳: تصاویر شاخص، گالری ووکامرس و متغیرهای محصول (با تفکیک فعال در برابر پیش‌نویس/زباله‌دان).
			$media_meta_keys = array(
				'_thumbnail_id',
				'_product_image_gallery',
				'_wc_additional_variation_images',
				'woodmart_wc_video_gallery',
				'_variation_image_gallery',
				'_yoast_wpseo_opengraph-image-id',
				'rank_math_facebook_image_id',
			);
			$placeholders = implode( ', ', array_fill( 0, count( $media_meta_keys ), '%s' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$pm_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type, p.post_status, p.post_parent,
					        parent.post_status AS parent_status, parent.post_title AS parent_title
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent
					 WHERE pm.meta_key IN ( {$placeholders} )
					   AND pm.meta_value <> '' AND pm.meta_value <> '0'",
					$media_meta_keys
				),
				ARRAY_A
			);

			foreach ( (array) $pm_rows as $row ) {
				$ptype        = (string) $row['post_type'];
				$pstatus      = (string) $row['post_status'];
				$parent_id    = absint( $row['post_parent'] );
				$eff_status   = ( 'product_variation' === $ptype && ! empty( $row['parent_status'] ) ) ? (string) $row['parent_status'] : $pstatus;
				$bucket       = self::is_active_status( $eff_status ) ? 'active' : 'draft';
				$target_post  = ( 'product_variation' === $ptype && $parent_id > 0 ) ? $parent_id : absint( $row['post_id'] );
				$display_name = ! empty( $row['post_title'] ) ? (string) $row['post_title'] : ( ! empty( $row['parent_title'] ) ? (string) $row['parent_title'] : '#' . $target_post );
				$role_prefix  = self::role_label_for_meta( (string) $row['meta_key'], $ptype );
				$label        = $role_prefix . ': ' . wp_html_excerpt( $display_name, 38, '…' );

				foreach ( explode( ',', (string) $row['meta_value'] ) as $piece ) {
					$cid = absint( trim( $piece ) );
					if ( $cid > 0 ) {
						$add_ref( $cid, $bucket, $label, $target_post );
					}
				}
			}

			// لایهٔ ۴: متای دسته‌ها، برندها و سواچ‌های ویژگی (`wp_termmeta`).
			if ( ! empty( $wpdb->termmeta ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$tm_rows = $wpdb->get_results(
					"SELECT tm.term_id, tm.meta_key, tm.meta_value, t.name AS term_name
					 FROM {$wpdb->termmeta} tm
					 LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
					 WHERE tm.meta_value <> '' AND tm.meta_value <> '0'",
					ARRAY_A
				);
				foreach ( (array) $tm_rows as $tr ) {
					$val   = trim( (string) $tr['meta_value'] );
					$tname = ! empty( $tr['term_name'] ) ? (string) $tr['term_name'] : '#' . absint( $tr['term_id'] );
					if ( ctype_digit( $val ) ) {
						$add_ref( (int) $val, 'active', 'دسته/برند/سواچ: ' . wp_html_excerpt( $tname, 34, '…' ), 0 );
					} else {
						$extracted = self::extract_ids_from_text_or_data( $val, $rel_map, $stem_map );
						foreach ( $extracted as $eid ) {
							$add_ref( $eid, 'active', 'دسته/برند/سواچ: ' . wp_html_excerpt( $tname, 34, '…' ), 0 );
						}
					}
				}
			}

			// لایهٔ ۵: محتوای نوشته‌ها، محصولات، برگه‌ها، بلوک‌های گوتنبرگ و شورت‌کدهای گالری.
			$last_id = 0;
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$posts_batch = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID, post_title, post_type, post_status, post_content, post_excerpt
						 FROM {$wpdb->posts}
						 WHERE ID > %d
						   AND post_type NOT IN ( 'revision', 'attachment', 'nav_menu_item' )
						   AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s )
						 ORDER BY ID ASC
						 LIMIT 400",
						$last_id,
						'%uploads/%',
						'%wp-image-%',
						'%gallery%',
						'%uploads/%'
					),
					ARRAY_A
				);
				foreach ( (array) $posts_batch as $pb ) {
					$last_id = absint( $pb['ID'] );
					$bucket  = self::is_active_status( (string) $pb['post_status'] ) ? 'active' : 'draft';
					$blob    = (string) $pb['post_content'] . "\n" . (string) $pb['post_excerpt'];
					$found   = self::extract_ids_from_text_or_data( $blob, $rel_map, $stem_map );
					$title   = ! empty( $pb['post_title'] ) ? (string) $pb['post_title'] : '#' . $last_id;
					foreach ( $found as $fid ) {
						$add_ref( $fid, $bucket, 'محتوای نوشته: ' . wp_html_excerpt( $title, 36, '…' ), $last_id );
					}
				}
			} while ( ! empty( $posts_batch ) && count( $posts_batch ) >= 400 );

			// لایهٔ ۶ و ۷: دیتای صفحه‌سازها (`_elementor_data` شامل JSON-escaped `\/`) و سایر متاهای آدرسی/سریالایزشده.
			$last_meta_id = 0;
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$meta_batch = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_status
						 FROM {$wpdb->postmeta} pm
						 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
						 WHERE pm.meta_id > %d
						   AND p.post_type <> 'attachment'
						   AND ( pm.meta_key = '_elementor_data' OR pm.meta_value LIKE %s OR pm.meta_value LIKE %s )
						 ORDER BY pm.meta_id ASC
						 LIMIT 400",
						$last_meta_id,
						'%uploads/%',
						'%uploads\\/%'
					),
					ARRAY_A
				);
				foreach ( (array) $meta_batch as $mb ) {
					$last_meta_id = absint( $mb['meta_id'] );
					$pid          = absint( $mb['post_id'] );
					$bucket       = self::is_active_status( (string) $mb['post_status'] ) ? 'active' : 'draft';
					$is_el        = ( '_elementor_data' === (string) $mb['meta_key'] );
					$found        = self::extract_ids_from_text_or_data( (string) $mb['meta_value'], $rel_map, $stem_map, $is_el );
					$prefix       = $is_el ? 'صفحه‌ساز المنتور: ' : 'متای سفارشی: ';
					$title        = ! empty( $mb['post_title'] ) ? (string) $mb['post_title'] : '#' . $pid;
					foreach ( $found as $fid ) {
						$add_ref( $fid, $bucket, $prefix . wp_html_excerpt( $title, 34, '…' ), $pid );
					}
				}
			} while ( ! empty( $meta_batch ) && count( $meta_batch ) >= 400 );

			// لایهٔ ۸: تنظیمات حیاتی سایت، قالب، وودمارت و ابزارک‌ها (`wp_options`).
			$direct_option_keys = array(
				'site_icon'                     => 'فاوآیکون سایت',
				'custom_logo'                   => 'لوگوی اصلی سایت',
				'woocommerce_placeholder_image' => 'تصویر پیش‌فرض ووکامرس',
			);
			foreach ( $direct_option_keys as $opt_key => $opt_label ) {
				$opt_val = absint( get_option( $opt_key, 0 ) );
				if ( $opt_val > 0 ) {
					$add_ref( $opt_val, 'active', $opt_label, 0 );
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$opt_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value
					 FROM {$wpdb->options}
					 WHERE option_name NOT LIKE %s
					   AND option_name NOT IN ( %s, %s )
					   AND ( option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_value LIKE %s OR option_value LIKE %s )
					 LIMIT 600",
					'_transient%',
					TisaCase_Optimizer_Vault::OPTION_QUARANTINE,
					TisaCase_Optimizer_Vault::OPTION_ORIG_INDEX,
					'theme_mods_%',
					'widget_%',
					'xts-%',
					'%uploads/%',
					'%uploads\\/%'
				),
				ARRAY_A
			);
			foreach ( (array) $opt_rows as $orow ) {
				$found = self::extract_ids_from_text_or_data( (string) $orow['option_value'], $rel_map, $stem_map, true );
				foreach ( $found as $fid ) {
					$add_ref( $fid, 'active', 'تنظیمات قالب/ابزارک (' . sanitize_key( (string) $orow['option_name'] ) . ')', 0 );
				}
			}

			// لایهٔ ۹: آواتارها و متاهای کاربران (`wp_usermeta`).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$um_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_key, meta_value FROM {$wpdb->usermeta}
					 WHERE meta_key LIKE %s OR meta_value LIKE %s
					 LIMIT 500",
					'%avatar%',
					'%uploads/%'
				),
				ARRAY_A
			);
			foreach ( (array) $um_rows as $ur ) {
				$uv = trim( (string) $ur['meta_value'] );
				if ( ctype_digit( $uv ) ) {
					$add_ref( (int) $uv, 'active', 'تصویر پروفایل کاربر', 0 );
				} else {
					foreach ( self::extract_ids_from_text_or_data( $uv, $rel_map, $stem_map ) as $fid ) {
						$add_ref( $fid, 'active', 'تصویر پروفایل کاربر', 0 );
					}
				}
			}

			// لایهٔ ۱۰: فیلتر توسعه‌پذیر برای افزونه‌های ثالث.
			$extra_ids = apply_filters( 'tisacase_optimizer_extra_used_ids', array() );
			foreach ( (array) $extra_ids as $xid ) {
				$add_ref( absint( $xid ), 'active', 'محافظت‌شده با فیلتر سفارشی', 0 );
			}

			// لایهٔ ۱۱ و ۱۲: شمارش کل فایل‌های فیزیکی (Inodes) و شکار فایل‌های تصویری ثبت‌نشده روی دیسک.
			$disk_scan = self::scan_uploads_directory( $basedir, $known_abs );

			$payload = array(
				'built_at'     => current_time( 'mysql' ),
				'timestamp'    => time(),
				'duration_ms'  => round( ( microtime( true ) - $started_at ) * 1000 ),
				'total_inodes' => $disk_scan['inodes'],
				'disk_bytes'   => $disk_scan['bytes'],
				'orphans'      => $disk_scan['orphans'],
				'refs'         => $refs,
			);

			$idx_file = self::index_path();
			if ( '' !== $idx_file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				@file_put_contents( $idx_file, wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) );
			}

			return $payload;
		}

		/**
		 * استخراج تمام شناسه‌های پیوست از متن HTML، گوتنبرگ، JSON المنتور یا دادهٔ سریالایزشده.
		 *
		 * @param string             $raw      دادهٔ خام.
		 * @param array<string,int>  $rel_map  جدول مسیر نسبی دقیق.
		 * @param array<string,int>  $stem_map جدول ریشهٔ نام فایل بدون سایز.
		 * @param bool               $parse_json_ids استخراج `"id":123` از JSON صفحه‌سازها.
		 * @return int[]
		 */
		private static function extract_ids_from_text_or_data( $raw, array $rel_map, array $stem_map, $parse_json_ids = false ) {
			if ( '' === $raw ) {
				return array();
			}
			$found = array();
			$norm  = str_replace( '\\/', '/', $raw );

			// ۱) کلاس استاندارد وردپرس wp-image-123.
			$matches = array();
			if ( preg_match_all( '/wp-image-([0-9]{1,10})/i', $norm, $matches ) ) {
				foreach ( $matches[1] as $m ) {
					$found[ (int) $m ] = true;
				}
			}

			// ۲) شورت‌کدهای گالری [gallery ids="12,34,56"].
			$g_matches = array();
			if ( preg_match_all( '/\[gallery[^\]]*ids=["\']?([0-9,\s]+)["\']?/i', $norm, $g_matches ) ) {
				foreach ( $g_matches[1] as $csv ) {
					foreach ( explode( ',', $csv ) as $id_str ) {
						$gid = absint( trim( $id_str ) );
						if ( $gid > 0 ) {
							$found[ $gid ] = true;
						}
					}
				}
			}

			// ۳) بلوک‌های گوتنبرگ و آبجکت‌های تصویر المنتور ("id":123 کنار url یا داخل wp:image).
			$b_matches = array();
			if ( $parse_json_ids ) {
				if ( preg_match_all( '/"id"\s*:\s*"?([0-9]{1,10})"?/', $norm, $b_matches ) ) {
					foreach ( $b_matches[1] as $bid ) {
						$found[ (int) $bid ] = true;
					}
				}
			} elseif ( preg_match_all( '/wp:(?:image|media-text|cover)[^{]*\{[^}]*"id"\s*:\s*([0-9]{1,10})/i', $norm, $b_matches ) ) {
				foreach ( $b_matches[1] as $bid ) {
					$found[ (int) $bid ] = true;
				}
			}

			// ۴) آدرس‌های فایل در uploads/ (هم اصلی و هم زیرسایزهای برش‌خورده مثل foo-600x600.jpg).
			$u_matches = array();
			if ( preg_match_all( '#uploads/([A-Za-z0-9_\-./]+?\.(?:jpe?g|png|webp|avif|gif|svg))#i', $norm, $u_matches ) ) {
				foreach ( $u_matches[1] as $rel_hit ) {
					$clean_rel = strtolower( ltrim( $rel_hit, '/' ) );
					$clean_rel = preg_replace( '/\.(webp|avif)$/i', '', (string) $clean_rel ) === $clean_rel
						? $clean_rel
						: $clean_rel;

					if ( isset( $rel_map[ $clean_rel ] ) ) {
						$found[ $rel_map[ $clean_rel ] ] = true;
						continue;
					}

					// حذف پسوندهای موازی و ابعاد برش `-300x300` یا `-scaled` برای یافتن پیوست مادر.
					$stripped = preg_replace( '/\.(?:webp|avif)$/i', '', $clean_rel );
					$stripped = preg_replace( '/\.[a-z0-9]+$/i', '', (string) $stripped );
					$stripped = preg_replace( '/-\d+x\d+$/i', '', (string) $stripped );
					$stripped = preg_replace( '/-scaled$/i', '', (string) $stripped );
					if ( isset( $stem_map[ $stripped ] ) ) {
						$found[ $stem_map[ $stripped ] ] = true;
					}
				}
			}

			return array_keys( $found );
		}

		/**
		 * پیمایش ایمن پوشهٔ `uploads/` برای شمارش دقیق Inodeها و یافتن تصاویر رهاشدهٔ بدون رکورد دیتابیس.
		 *
		 * @param string             $basedir   مسیر ریشهٔ uploads با اسلش پایانی.
		 * @param array<string,bool> $known_abs مسیرهای شناخته‌شدهٔ دیتابیس (با حروف کوچک).
		 * @return array{inodes:int,bytes:int,orphans:array<int,array<string,mixed>>}
		 */
		private static function scan_uploads_directory( $basedir, array $known_abs ) {
			$result = array(
				'inodes'  => 0,
				'bytes'   => 0,
				'orphans' => array(),
			);

			if ( '' === $basedir || ! is_dir( $basedir ) ) {
				return $result;
			}

			$excluded    = TisaCase_Optimizer_Settings::excluded_dirs();
			$scan_orphan = (bool) TisaCase_Optimizer_Settings::get( 'scan_unregistered_disk', 1 );
			$image_exts  = array( 'jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'svg' );

			try {
				$dir_iter = new RecursiveDirectoryIterator( $basedir, FilesystemIterator::SKIP_DOTS );
				$filter   = new RecursiveCallbackFilterIterator(
					$dir_iter,
					static function ( $current ) use ( $basedir, $excluded ) {
						$path = wp_normalize_path( $current->getPathname() );
						$rel  = ltrim( substr( $path, strlen( $basedir ) ), '/' );
						foreach ( $excluded as $ex ) {
							if ( $rel === $ex || 0 === strpos( $rel, $ex . '/' ) ) {
								return false;
							}
						}
						return true;
					}
				);

				$it = new RecursiveIteratorIterator( $filter );
				foreach ( $it as $file ) {
					if ( ! $file->isFile() ) {
						continue;
					}
					++$result['inodes'];
					$fsize            = max( 0, (int) $file->getSize() );
					$result['bytes'] += $fsize;

					if ( ! $scan_orphan || count( $result['orphans'] ) >= 1000 ) {
						continue;
					}

					$ext = strtolower( (string) $file->getExtension() );
					if ( ! in_array( $ext, $image_exts, true ) ) {
						continue;
					}

					$norm_abs = wp_normalize_path( $file->getPathname() );
					if ( ! isset( $known_abs[ strtolower( $norm_abs ) ] ) ) {
						$rel_path          = ltrim( substr( $norm_abs, strlen( $basedir ) ), '/' );
						$result['orphans'][] = array(
							'rel_path' => $rel_path,
							'filename' => basename( $rel_path ),
							'ext'      => strtoupper( $ext ),
							'bytes'    => $fsize,
							'modified' => gmdate( 'Y-m-d H:i', (int) $file->getMTime() ),
						);
					}
				}
			} catch ( Throwable $e ) {
				return $result;
			}

			usort(
				$result['orphans'],
				static function ( $a, $b ) {
					return (int) $b['bytes'] <=> (int) $a['bytes'];
				}
			);

			return $result;
		}

		/**
		 * آیا وضعیت نوشته، یک نوشتهٔ فعال در سایت محسوب می‌شود؟
		 *
		 * @param string $status وضعیت نوشته.
		 * @return bool
		 */
		private static function is_active_status( $status ) {
			return in_array( $status, array( 'publish', 'future', 'private', 'inherit' ), true );
		}

		/**
		 * برچسب خوانای نقش متا برای ستون «کجا استفاده شده؟».
		 *
		 * @param string $meta_key  کلید متا.
		 * @param string $post_type نوع نوشته.
		 * @return string
		 */
		private static function role_label_for_meta( $meta_key, $post_type ) {
			if ( 'product_variation' === $post_type ) {
				return 'متغیر محصول';
			}
			if ( '_product_image_gallery' === $meta_key ) {
				return 'گالری محصول';
			}
			if ( '_thumbnail_id' === $meta_key ) {
				return 'product' === $post_type ? 'شاخص محصول' : 'تصویر شاخص';
			}
			return 'گالری/متا';
		}

		/**
		 * آن‌سریالایز امن بدون ساخت آبجکت کلاس‌های دلخواه (جلوگیری از PHP Object Injection).
		 *
		 * @param string $raw دادهٔ خام.
		 * @return mixed
		 */
		public static function safe_unserialize( $raw ) {
			if ( ! is_serialized( $raw ) ) {
				return $raw;
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged
			return @unserialize( trim( $raw ), array( 'allowed_classes' => false ) );
		}
	}
}
