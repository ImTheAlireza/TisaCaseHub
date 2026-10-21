<?php
/**
 * ممیزی و تکمیل خودکار سئوی تصاویر (Alt و Title از روی محصول متصل ووکامرس).
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Image_SEO' ) ) {

	/**
	 * موتور سئوی تصاویر محصولات و رسانه.
	 */
	final class TisaCase_Optimizer_Image_SEO {

		/**
		 * گزارش ممیزی سئوی تصاویر به همراه ردیف‌های نیازمند اصلاح.
		 *
		 * @param string $filter all | missing_alt | bad_title.
		 * @param int    $page   شمارهٔ صفحه.
		 * @return array<string,mixed>
		 */
		public static function audit( $filter = 'missing_alt', $page = 1 ) {
			$cat        = TisaCase_Optimizer_Media_Inspector::build_catalog( false );
			$items      = $cat['items'];
			$missing    = 0;
			$bad_titles = 0;
			$matched    = array();

			foreach ( $items as $it ) {
				$no_alt    = ( '' === $it['alt'] );
				$raw_title = self::looks_like_camera_title( $it['title'] );
				if ( $no_alt ) {
					++$missing;
				}
				if ( $raw_title ) {
					++$bad_titles;
				}

				if ( 'missing_alt' === $filter && ! $no_alt ) {
					continue;
				}
				if ( 'bad_title' === $filter && ! $raw_title ) {
					continue;
				}

				$it['bad_title']     = $raw_title;
				$it['suggested_alt'] = self::build_text_for_attachment(
					(int) $it['id'],
					(string) TisaCase_Optimizer_Settings::get( 'seo_alt_pattern', '{product_title} — {site_name}' ),
					$it
				);
				$matched[]           = $it;
			}

			$per_page = 25;
			$page     = max( 1, (int) $page );
			$total    = count( $matched );

			return array(
				'summary' => array(
					'total'        => count( $items ),
					'missing_alt'  => $missing,
					'bad_titles'   => $bad_titles,
					'healthy_alt'  => max( 0, count( $items ) - $missing ),
					'coverage_pct' => count( $items ) > 0 ? round( ( ( count( $items ) - $missing ) / count( $items ) ) * 100 ) : 100,
				),
				'total'   => $total,
				'page'    => $page,
				'pages'   => max( 1, (int) ceil( $total / $per_page ) ),
				'rows'    => array_slice( $matched, ( $page - 1 ) * $per_page, $per_page ),
			);
		}

		/**
		 * ذخیرهٔ مستقیم متن Alt و عنوان یک پیوست از جدول مدیریت.
		 *
		 * @param int    $attachment_id شناسهٔ پیوست.
		 * @param string $alt           متن جایگزین جدید.
		 * @param string $title         عنوان جدید (اختیاری).
		 * @return bool
		 */
		public static function update_single( $attachment_id, $alt, $title = null ) {
			$attachment_id = absint( $attachment_id );
			if ( $attachment_id <= 0 ) {
				return false;
			}
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
			if ( null !== $title && '' !== trim( (string) $title ) ) {
				wp_update_post(
					array(
						'ID'         => $attachment_id,
						'post_title' => sanitize_text_field( (string) $title ),
					)
				);
			}
			TisaCase_Optimizer_Settings::bump_stats( array( 'seo_updated' => 1 ) );
			TisaCase_Optimizer_Media_Inspector::flush_runtime_cache();
			return true;
		}

		/**
		 * تکمیل گروهی خودکار Alt و Title بر اساس الگوی پویا.
		 *
		 * @param int[] $ids        شناسه‌های هدف (خالی = همهٔ تصاویر واجد شرایط).
		 * @param bool  $only_empty فقط Altهای خالی پر شوند.
		 * @return int تعداد تصاویر به‌روزرسانی‌شده.
		 */
		public static function bulk_generate( array $ids = array(), $only_empty = true ) {
			$cat         = TisaCase_Optimizer_Media_Inspector::build_catalog( false );
			$alt_pattern = (string) TisaCase_Optimizer_Settings::get( 'seo_alt_pattern', '{product_title} — {site_name}' );
			$ttl_pattern = (string) TisaCase_Optimizer_Settings::get( 'seo_title_pattern', '{product_title}' );
			$id_filter   = ! empty( $ids ) ? array_fill_keys( array_map( 'absint', $ids ), true ) : null;
			$updated     = 0;

			foreach ( $cat['items'] as $aid => $it ) {
				if ( null !== $id_filter && ! isset( $id_filter[ $aid ] ) ) {
					continue;
				}
				if ( $only_empty && '' !== $it['alt'] ) {
					continue;
				}

				$new_alt = self::build_text_for_attachment( $aid, $alt_pattern, $it );
				if ( '' === $new_alt ) {
					continue;
				}
				update_post_meta( $aid, '_wp_attachment_image_alt', $new_alt );

				if ( self::looks_like_camera_title( $it['title'] ) ) {
					$new_title = self::build_text_for_attachment( $aid, $ttl_pattern, $it );
					if ( '' !== $new_title ) {
						wp_update_post(
							array(
								'ID'         => $aid,
								'post_title' => $new_title,
							)
						);
					}
				}
				++$updated;
			}

			if ( $updated > 0 ) {
				TisaCase_Optimizer_Settings::bump_stats( array( 'seo_updated' => $updated ) );
				TisaCase_Optimizer_Media_Inspector::flush_runtime_cache();
			}
			return $updated;
		}

		/**
		 * ساخت متن نهایی از روی الگوی متغیردار و محصول/نوشتهٔ متصل.
		 *
		 * @param int                 $attachment_id شناسهٔ پیوست.
		 * @param string              $pattern       الگوی کاربر.
		 * @param array<string,mixed> $item          دادهٔ کاتالوگ.
		 * @return string
		 */
		public static function build_text_for_attachment( $attachment_id, $pattern, array $item = array() ) {
			$product_id = 0;
			if ( ! empty( $item['refs'] ) ) {
				foreach ( $item['refs'] as $ref ) {
					if ( ! empty( $ref['post_id'] ) ) {
						$product_id = absint( $ref['post_id'] );
						break;
					}
				}
			}
			if ( $product_id <= 0 && ! empty( $item['parent_id'] ) ) {
				$product_id = absint( $item['parent_id'] );
			}

			$product_title = '';
			$category_name = '';
			$sku           = '';

			if ( $product_id > 0 ) {
				$p = get_post( $product_id );
				if ( $p ) {
					if ( 'product_variation' === $p->post_type && $p->post_parent > 0 ) {
						$parent        = get_post( $p->post_parent );
						$product_title = $parent ? (string) $parent->post_title : (string) $p->post_title;
						$product_id    = $parent ? (int) $parent->ID : $product_id;
					} else {
						$product_title = (string) $p->post_title;
					}
					$sku   = (string) get_post_meta( $product_id, '_sku', true );
					$terms = get_the_terms( $product_id, 'product_cat' );
					if ( is_array( $terms ) && ! empty( $terms ) ) {
						$category_name = (string) $terms[0]->name;
					}
				}
			}

			if ( '' === $product_title ) {
				$fallback_name = ! empty( $item['title'] ) && ! self::looks_like_camera_title( $item['title'] )
					? (string) $item['title']
					: preg_replace( '/[-_]+/', ' ', pathinfo( isset( $item['filename'] ) ? (string) $item['filename'] : '', PATHINFO_FILENAME ) );
				$product_title = trim( (string) $fallback_name );
			}

			$site_name = (string) get_bloginfo( 'name' );
			if ( '' === $site_name ) {
				$site_name = 'تیساکیس';
			}

			$out = strtr(
				$pattern,
				array(
					'{product_title}' => $product_title,
					'{category}'      => $category_name,
					'{sku}'           => $sku,
					'{site_name}'     => $site_name,
				)
			);
			// پاک‌سازی جداکننده‌های اضافی در صورت خالی بودن یکی از متغیرها.
			$out = preg_replace( '/\s*[—|\-]\s*([—|\-])\s*/u', ' $1 ', (string) $out );
			$out = trim( (string) $out, " \t\n\r\0\x0B-|—" );
			return sanitize_text_field( $out );
		}

		/**
		 * تشخیص عناوین خام دوربین، تلگرام یا UUID بی‌معنا.
		 *
		 * @param string $title عنوان فعلی پیوست.
		 * @return bool
		 */
		public static function looks_like_camera_title( $title ) {
			$t = trim( (string) $title );
			if ( '' === $t ) {
				return true;
			}
			return (bool) preg_match( '/^(?:IMG|DSC|PXL|photo|image|Screenshot|WhatsApp|Telegram|file|document|[0-9a-f]{12,})[\s_\-0-9]*/i', $t );
		}
	}
}
