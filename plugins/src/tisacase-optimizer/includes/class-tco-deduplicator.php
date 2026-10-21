<?php
/**
 * تشخیص تصاویر تکراری بایت‌به‌بایت (`SHA-1`) و موتور «ادغام هوشمند بدون خرابی عکس محصول».
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Deduplicator' ) ) {

	/**
	 * گروه‌بندی و ادغام هوشمند تصاویر تکراری.
	 */
	final class TisaCase_Optimizer_Deduplicator {

		/**
		 * استخراج گروه‌های تکراری مرتب‌شده بر اساس بیشترین فضای قابل آزادسازی.
		 *
		 * @param bool $force_rescan بازسازی اجباری کاتالوگ.
		 * @return array<string,mixed>
		 */
		public static function find_duplicate_groups( $force_rescan = false ) {
			$cat     = TisaCase_Optimizer_Media_Inspector::build_catalog( $force_rescan );
			$buckets = array();

			foreach ( $cat['items'] as $item ) {
				if ( empty( $item['exists'] ) || '' === $item['sha1'] ) {
					continue;
				}
				$buckets[ $item['sha1'] ][] = $item;
			}

			$groups      = array();
			$total_extra = 0;
			$total_waste = 0;

			foreach ( $buckets as $sha1 => $members ) {
				if ( count( $members ) < 2 ) {
					continue;
				}

				// انتخاب بهترین تصویر مرجع (پراستفاده‌ترین، و در شرایط برابر قدیمی‌ترین ID).
				usort(
					$members,
					static function ( $a, $b ) {
						if ( $b['refs_count'] !== $a['refs_count'] ) {
							return $b['refs_count'] <=> $a['refs_count'];
						}
						return $a['id'] <=> $b['id'];
					}
				);

				$master = $members[0];
				$waste  = 0;
				$dup_ids = array();
				for ( $i = 1, $n = count( $members ); $i < $n; $i++ ) {
					$waste    += (int) $members[ $i ]['total_bytes'];
					$dup_ids[] = (int) $members[ $i ]['id'];
				}

				$total_extra += count( $dup_ids );
				$total_waste += $waste;

				$groups[] = array(
					'hash'        => $sha1,
					'short_hash'  => substr( $sha1, 0, 12 ),
					'master_id'   => (int) $master['id'],
					'dup_ids'     => $dup_ids,
					'count'       => count( $members ),
					'waste_bytes' => $waste,
					'dimensions'  => $master['width'] . '×' . $master['height'],
					'format'      => $master['format'],
					'thumb_url'   => $master['thumb_url'],
					'members'     => $members,
				);
			}

			usort(
				$groups,
				static function ( $a, $b ) {
					return $b['waste_bytes'] <=> $a['waste_bytes'];
				}
			);

			return array(
				'groups_count'     => count( $groups ),
				'duplicates_count' => $total_extra,
				'waste_bytes'      => $total_waste,
				'groups'           => $groups,
			);
		}

		/**
		 * ادغام هوشمند یک گروه تکراری:
		 * تمام محصولات، گالری‌ها، متغیرها، دسته‌ها، محتوای پست‌ها و المنتور به `$master_id`
		 * منتقل می‌شوند و سپس نسخه‌های تکراری قرنطینه یا حذف قطعی می‌گردند!
		 *
		 * @param int    $master_id   شناسهٔ پیوست مرجع که باقی می‌ماند.
		 * @param int[]  $dup_ids     شناسه‌های پیوست‌های تکراری که ادغام و حذف می‌شوند.
		 * @param string $delete_mode quarantine | permanent.
		 * @return array<string,mixed>|WP_Error
		 */
		public static function merge_group( $master_id, array $dup_ids, $delete_mode = 'quarantine' ) {
			global $wpdb;

			$master_id = absint( $master_id );
			$master    = get_post( $master_id );
			if ( ! $master || 'attachment' !== $master->post_type ) {
				return new WP_Error( 'invalid_master', __( 'تصویر مرجع معتبر نیست.', 'tisacase-optimizer' ) );
			}

			$master_url = (string) wp_get_attachment_url( $master_id );
			$relinked   = 0;
			$saved      = 0;
			$processed  = 0;
			$touched_products = array();

			foreach ( $dup_ids as $raw_id ) {
				$dup_id = absint( $raw_id );
				if ( $dup_id <= 0 || $dup_id === $master_id ) {
					continue;
				}
				$dup_post = get_post( $dup_id );
				if ( ! $dup_post || 'attachment' !== $dup_post->post_type ) {
					continue;
				}

				$dup_url = (string) wp_get_attachment_url( $dup_id );

				// ۱) انتقال تمام متاهای تک‌مقداری (_thumbnail_id و متاهای سئو).
				$single_keys = array( '_thumbnail_id', '_yoast_wpseo_opengraph-image-id', 'rank_math_facebook_image_id' );
				$s_ph        = implode( ', ', array_fill( 0, count( $single_keys ), '%s' ) );

				// ثبت محصولات درگیر برای پاک کردن کش ووکامرس.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$affected_pids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s",
						(string) $dup_id
					)
				);
				foreach ( (array) $affected_pids as $apid ) {
					$touched_products[ absint( $apid ) ] = true;
				}

				$sql_args = array_merge( array( (string) $master_id, (string) $dup_id ), $single_keys );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$relinked += max( 0, (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_value = %s AND meta_key IN ( {$s_ph} )", $sql_args ) ) );

				// ۲) انتقال در گالری‌های چندمقداری ووکامرس و متغیرها (همراه با حذف شناسهٔ تکراری داخل لیست کامادار!).
				$csv_keys = array( '_product_image_gallery', '_wc_additional_variation_images', 'woodmart_wc_video_gallery', '_variation_image_gallery' );
				$c_ph     = implode( ', ', array_fill( 0, count( $csv_keys ), '%s' ) );
				$c_args   = array_merge( $csv_keys, array( '%' . $wpdb->esc_like( (string) $dup_id ) . '%' ) );

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$gal_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( {$c_ph} ) AND meta_value LIKE %s",
						$c_args
					),
					ARRAY_A
				);
				foreach ( (array) $gal_rows as $gr ) {
					$parts   = array_filter( array_map( 'absint', explode( ',', (string) $gr['meta_value'] ) ) );
					$changed = false;
					$next    = array();
					foreach ( $parts as $pid_val ) {
						if ( $pid_val === $dup_id ) {
							$pid_val = $master_id;
							$changed = true;
						}
						$next[ $pid_val ] = true;
					}
					if ( $changed ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update(
							$wpdb->postmeta,
							array( 'meta_value' => implode( ',', array_keys( $next ) ) ),
							array( 'meta_id' => absint( $gr['meta_id'] ) )
						);
						$touched_products[ absint( $gr['post_id'] ) ] = true;
						++$relinked;
					}
				}

				// ۳) انتقال در متای دسته‌بندی‌ها، برندها و سواچ‌ها (`wp_termmeta`).
				if ( ! empty( $wpdb->termmeta ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$relinked += max(
						0,
						(int) $wpdb->query(
							$wpdb->prepare(
								"UPDATE {$wpdb->termmeta} SET meta_value = %s WHERE meta_value = %s",
								(string) $master_id,
								(string) $dup_id
							)
						)
					);
				}

				// ۴) انتقال در تنظیمات اصلی سایت (`site_icon`, `custom_logo`, `woocommerce_placeholder_image`).
				foreach ( array( 'site_icon', 'custom_logo', 'woocommerce_placeholder_image' ) as $opt_key ) {
					if ( absint( get_option( $opt_key, 0 ) ) === $dup_id ) {
						update_option( $opt_key, $master_id );
						++$relinked;
					}
				}

				// ۵) اصلاح کلاس wp-image و URL در محتوا و دیتای المنتور.
				if ( '' !== $dup_url && '' !== $master_url && $dup_url !== $master_url ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$wpdb->posts}
							 SET post_content = REPLACE( REPLACE( post_content, %s, %s ), %s, %s )
							 WHERE post_content LIKE %s OR post_content LIKE %s",
							'wp-image-' . $dup_id,
							'wp-image-' . $master_id,
							$dup_url,
							$master_url,
							'%wp-image-' . $dup_id . '%',
							'%' . $wpdb->esc_like( $dup_url ) . '%'
						)
					);

					$dup_esc    = str_replace( '/', '\\/', $dup_url );
					$master_esc = str_replace( '/', '\\/', $master_url );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$wpdb->postmeta}
							 SET meta_value = REPLACE( meta_value, %s, %s )
							 WHERE meta_key = '_elementor_data' AND meta_value LIKE %s",
							$dup_esc,
							$master_esc,
							'%' . $wpdb->esc_like( $dup_esc ) . '%'
						)
					);
				}

				// ۶) اکنون با خیال راحت کپی تکراری را به قرنطینه بفرست یا حذف قطعی کن.
				if ( 'permanent' === $delete_mode ) {
					$del = TisaCase_Optimizer_Vault::delete_attachment_permanently( $dup_id );
					if ( ! is_wp_error( $del ) ) {
						$saved += (int) $del['saved_bytes'];
						++$processed;
					}
				} else {
					$q = TisaCase_Optimizer_Vault::quarantine_attachment( $dup_id, 'duplicate' );
					if ( ! is_wp_error( $q ) ) {
						$saved += (int) $q['total_bytes'];
						++$processed;
					}
				}
			}

			// پاک‌سازی کش محصولات لمس‌شده در ووکامرس.
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				foreach ( array_keys( $touched_products ) as $tpid ) {
					wc_delete_product_transients( $tpid );
					clean_post_cache( $tpid );
				}
			}

			if ( $processed > 0 ) {
				TisaCase_Optimizer_Settings::bump_stats( array( 'merged_duplicates' => $processed ) );
			}
			TisaCase_Optimizer_Media_Inspector::remove_from_cached_catalog( $dup_ids );

			return array(
				'master_id'   => $master_id,
				'merged'      => $processed,
				'relinked'    => $relinked,
				'saved_bytes' => $saved,
			);
		}
	}
}
