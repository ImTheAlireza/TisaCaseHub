<?php
/**
 * مدیریت زیرسایزهای بندانگشتی ثبت‌شده، پاک‌سازی سایزهای غیرفعال و بازسازی دسته‌ای.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Subsizes' ) ) {

	/**
	 * بازرس و مدیر سایزهای تصویر وردپرس/ووکامرس/قالب.
	 */
	final class TisaCase_Optimizer_Subsizes {

		/**
		 * فیلتر هستهٔ وردپرس برای جلوگیری از تولید زیرسایزهای غیرفعال‌شده در آپلودهای جدید.
		 *
		 * @param array<string,array<string,mixed>> $sizes سایزهای فعال.
		 * @return array<string,array<string,mixed>>
		 */
		public static function filter_intermediate_sizes( $sizes ) {
			if ( ! is_array( $sizes ) ) {
				return $sizes;
			}
			$disabled = TisaCase_Optimizer_Settings::get( 'disabled_sizes', array() );
			if ( empty( $disabled ) || ! is_array( $disabled ) ) {
				return $sizes;
			}
			foreach ( $disabled as $key ) {
				unset( $sizes[ $key ] );
			}
			return $sizes;
		}

		/**
		 * گزارش فوری تمام سایزهای ثبت‌شده از روی کاتالوگ کش‌شده بدون هیچ Syscall اضافی روی دیسک!
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public static function inspect_all_sizes() {
			$registered = function_exists( 'wp_get_registered_image_subsizes' )
				? wp_get_registered_image_subsizes()
				: array();
			$disabled   = array_fill_keys( (array) TisaCase_Optimizer_Settings::get( 'disabled_sizes', array() ), true );
			$cat        = TisaCase_Optimizer_Media_Inspector::build_catalog( false );
			$cached     = isset( $cat['index']['subsize_stats'] ) && is_array( $cat['index']['subsize_stats'] )
				? $cat['index']['subsize_stats']
				: array();

			$stats = array();
			foreach ( $registered as $key => $info ) {
				$stats[ $key ] = array(
					'key'         => $key,
					'width'       => isset( $info['width'] ) ? absint( $info['width'] ) : 0,
					'height'      => isset( $info['height'] ) ? absint( $info['height'] ) : 0,
					'crop'        => ! empty( $info['crop'] ),
					'source'      => self::source_group( $key ),
					'essential'   => in_array( $key, array( 'thumbnail', 'woocommerce_thumbnail', 'woocommerce_single' ), true ),
					'enabled'     => ! isset( $disabled[ $key ] ),
					'files_count' => isset( $cached[ $key ]['files_count'] ) ? (int) $cached[ $key ]['files_count'] : 0,
					'bytes'       => isset( $cached[ $key ]['bytes'] ) ? (int) $cached[ $key ]['bytes'] : 0,
				);
			}

			foreach ( $cached as $skey => $sval ) {
				if ( isset( $stats[ $skey ] ) ) {
					continue;
				}
				$stats[ $skey ] = array(
					'key'         => (string) $skey,
					'width'       => isset( $sval['width'] ) ? absint( $sval['width'] ) : 0,
					'height'      => isset( $sval['height'] ) ? absint( $sval['height'] ) : 0,
					'crop'        => false,
					'source'      => __( 'به‌جامانده از قالب/افزونهٔ قبلی', 'tisacase-optimizer' ),
					'essential'   => false,
					'enabled'     => ! isset( $disabled[ $skey ] ),
					'files_count' => isset( $sval['files_count'] ) ? (int) $sval['files_count'] : 0,
					'bytes'       => isset( $sval['bytes'] ) ? (int) $sval['bytes'] : 0,
				);
			}

			$list = array_values( $stats );
			usort(
				$list,
				static function ( $a, $b ) {
					return $b['bytes'] <=> $a['bytes'];
				}
			);
			return $list;
		}

		/**
		 * حذف دسته‌ای سریع فایل‌های زیرسایزهای غیرفعال‌شده از روی دیسک و متادیتا.
		 *
		 * @param string[] $target_keys کلید سایزهای غیرفعال برای پاک‌سازی.
		 * @return array{deleted_files:int,saved_bytes:int}
		 */
		public static function clean_disabled_subsize_files( array $target_keys ) {
			global $wpdb;

			$keys_map = array();
			foreach ( $target_keys as $k ) {
				$sk = sanitize_key( (string) $k );
				if ( '' !== $sk && 'thumbnail' !== $sk ) {
					$keys_map[ $sk ] = true;
				}
			}
			if ( empty( $keys_map ) ) {
				return array(
					'deleted_files' => 0,
					'saved_bytes'   => 0,
				);
			}

			$uploads       = wp_upload_dir();
			$basedir       = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
			$deleted_files = 0;
			$saved_bytes   = 0;
			$last_id       = 0;

			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$batch = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT p.ID, pm_f.meta_value AS rel_file, pm_m.meta_value AS meta_raw
						 FROM {$wpdb->posts} p
						 INNER JOIN {$wpdb->postmeta} pm_f ON pm_f.post_id = p.ID AND pm_f.meta_key = '_wp_attached_file'
						 INNER JOIN {$wpdb->postmeta} pm_m ON pm_m.post_id = p.ID AND pm_m.meta_key = '_wp_attachment_metadata'
						 WHERE p.ID > %d AND p.post_type = 'attachment' AND p.post_mime_type LIKE %s
						 ORDER BY p.ID ASC
						 LIMIT 500",
						$last_id,
						'image/%'
					),
					ARRAY_A
				);

				foreach ( (array) $batch as $row ) {
					$aid     = absint( $row['ID'] );
					$last_id = $aid;
					$rel     = ltrim( wp_normalize_path( (string) $row['rel_file'] ), '/' );
					$meta    = TisaCase_Optimizer_Reference_Scanner::safe_unserialize( (string) $row['meta_raw'] );
					if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) || '' === $rel ) {
						continue;
					}

					$subdir           = wp_normalize_path( dirname( $rel ) );
					$subdir           = ( '.' === $subdir ) ? '' : trailingslashit( $subdir );
					$still_used_files = array( basename( $rel ) => true );

					foreach ( $meta['sizes'] as $skey => $sval ) {
						if ( ! isset( $keys_map[ $skey ] ) && ! empty( $sval['file'] ) ) {
							$still_used_files[ basename( (string) $sval['file'] ) ] = true;
						}
					}

					$changed = false;
					foreach ( array_keys( $keys_map ) as $dkey ) {
						if ( ! isset( $meta['sizes'][ $dkey ] ) ) {
							continue;
						}
						$fname = isset( $meta['sizes'][ $dkey ]['file'] ) ? basename( (string) $meta['sizes'][ $dkey ]['file'] ) : '';
						unset( $meta['sizes'][ $dkey ] );
						$changed = true;

						if ( '' !== $fname && ! isset( $still_used_files[ $fname ] ) ) {
							$sabs = TisaCase_Optimizer_Vault::assert_inside_uploads( $basedir . $subdir . $fname );
							if ( false !== $sabs && is_file( $sabs ) ) {
								$saved_bytes += max( 0, (int) @filesize( $sabs ) );
								wp_delete_file( $sabs );
								++$deleted_files;
							}
							foreach ( array( '.webp', '.avif' ) as $ext ) {
								if ( false !== $sabs && is_file( $sabs . $ext ) ) {
									$saved_bytes += max( 0, (int) @filesize( $sabs . $ext ) );
									wp_delete_file( $sabs . $ext );
								}
							}
						}
					}

					if ( $changed ) {
						wp_update_attachment_metadata( $aid, $meta );
					}
				}
			} while ( ! empty( $batch ) && count( $batch ) >= 500 );

			$cat_raw = TisaCase_Optimizer_Reference_Scanner::read_persisted_catalog();
			if ( is_array( $cat_raw ) && isset( $cat_raw['subsize_stats'] ) ) {
				foreach ( array_keys( $keys_map ) as $dkey ) {
					if ( isset( $cat_raw['subsize_stats'][ $dkey ] ) ) {
						$cat_raw['subsize_stats'][ $dkey ]['files_count'] = 0;
						$cat_raw['subsize_stats'][ $dkey ]['bytes']       = 0;
					}
				}
				$cat_raw['disk_bytes']   = max( 0, (int) $cat_raw['disk_bytes'] - $saved_bytes );
				$cat_raw['total_inodes'] = max( 0, (int) $cat_raw['total_inodes'] - $deleted_files );
				TisaCase_Optimizer_Reference_Scanner::write_persisted_catalog( $cat_raw );
			}

			if ( $saved_bytes > 0 ) {
				TisaCase_Optimizer_Settings::bump_stats( array( 'saved_bytes' => $saved_bytes ) );
			}
			TisaCase_Optimizer_Media_Inspector::flush_runtime_cache();

			return array(
				'deleted_files' => $deleted_files,
				'saved_bytes'   => $saved_bytes,
			);
		}

		/**
		 * بازسازی زیرسایزهای فعال برای یک پیوست (Regenerate Thumbnails).
		 *
		 * @param int $attachment_id شناسهٔ پیوست.
		 * @return bool|WP_Error
		 */
		public static function regenerate_one( $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$file          = get_attached_file( $attachment_id );
			if ( ! $file || ! is_file( $file ) ) {
				return new WP_Error( 'missing_file', __( 'فایل اصلی تصویر روی دیسک یافت نشد.', 'tisacase-optimizer' ) );
			}
			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			$meta = wp_generate_attachment_metadata( $attachment_id, $file );
			if ( is_wp_error( $meta ) || ! is_array( $meta ) ) {
				return new WP_Error( 'regen_failed', __( 'بازسازی بندانگشتی ناموفق بود.', 'tisacase-optimizer' ) );
			}
			wp_update_attachment_metadata( $attachment_id, $meta );
			return true;
		}

		/**
		 * تشخیص منشأ سایز ثبت‌شده.
		 *
		 * @param string $key نام سایز.
		 * @return string
		 */
		private static function source_group( $key ) {
			if ( in_array( $key, array( 'thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048' ), true ) ) {
				return __( 'هستهٔ وردپرس', 'tisacase-optimizer' );
			}
			if ( 0 === strpos( $key, 'woocommerce_' ) || 0 === strpos( $key, 'shop_' ) ) {
				return __( 'ووکامرس', 'tisacase-optimizer' );
			}
			return __( 'قالب / افزونه', 'tisacase-optimizer' );
		}
	}
}
