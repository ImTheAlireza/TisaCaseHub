<?php
/**
 * جست‌وجوی محلی محصولات TisaCase برای پرکردن شناسه بدون درخواست بیرونی.
 *
 * چرا؟ چسباندن لینک محصول باعث می‌شد مرورگر یک لینک کامل را در POST بفرستد و
 * فایروال/CDN سایت آن را با 403/418 مسدود کند. جست‌وجوی نام یا SKU فقط از
 * دیتابیس همین سایت می‌خواند: نه درخواستی به همکار می‌رود و نه آدرسی در POST
 * ارسال می‌شود.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Local_Search' ) ) {

	final class TCAS_Local_Search {
		const MIN_TERM = 2;
		const LIMIT    = 12;
		const MAX_LIMIT = 40;

		/**
		 * قلاب تست: اگر تنظیم شود، جای کوئری دیتابیس استفاده می‌شود.
		 *
		 * @var callable|null
		 */
		public static $finder = null;

		/**
		 * جست‌وجوی نام/SKU و برگرداندن ردیف‌های آمادهٔ نمایش.
		 *
		 * @param string $term  عبارت جست‌وجو.
		 * @param int    $limit حداکثر تعداد.
		 * @return array<int,array<string,mixed>>
		 */
		public static function search( $term, $limit = self::LIMIT ) {
			$term = trim( (string) $term );
			if ( self::too_short( $term ) ) {
				return array();
			}
			$limit = (int) $limit;
			if ( $limit < 1 ) {
				$limit = self::LIMIT;
			}
			if ( $limit > self::MAX_LIMIT ) {
				$limit = self::MAX_LIMIT;
			}
			$candidates = self::$finder ? call_user_func( self::$finder, $term, $limit ) : self::query( $term, $limit );
			if ( ! is_array( $candidates ) ) {
				return array();
			}
			$rows = array();
			$seen = array();
			foreach ( $candidates as $candidate ) {
				$row = self::row( $candidate );
				if ( ! $row || isset( $seen[ $row['reference'] ] ) ) {
					continue;
				}
				$seen[ $row['reference'] ] = true;
				$rows[]                    = $row;
				if ( count( $rows ) >= $limit ) {
					break;
				}
			}
			return self::rank( $rows, $term );
		}

		/** عبارت کوتاه‌تر از دو حرف جست‌وجو نمی‌شود. */
		public static function too_short( $term ) {
			$term = trim( (string) $term );
			if ( function_exists( 'mb_strlen' ) ) {
				return mb_strlen( $term ) < self::MIN_TERM;
			}
			return strlen( $term ) < self::MIN_TERM;
		}

		/**
		 * یک کاندید خام را به ردیف نمایشی تبدیل می‌کند؛ بدون تماس با ووکامرس.
		 *
		 * @param mixed $candidate آرایهٔ خام.
		 * @return array<string,mixed>|null
		 */
		public static function row( $candidate ) {
			if ( ! is_array( $candidate ) ) {
				return null;
			}
			$product_id = isset( $candidate['product_id'] ) ? (int) $candidate['product_id'] : 0;
			if ( $product_id <= 0 ) {
				return null;
			}
			$type = isset( $candidate['type'] ) ? (string) $candidate['type'] : 'simple';
			$sku  = isset( $candidate['sku'] ) ? trim( (string) $candidate['sku'] ) : '';
			// Variation تیساکیس همیشه با ID یکتا می‌رود تا SKU مشترک والد مانع نشود.
			$reference = 'variation' === $type ? 'ID:' . $product_id : ( '' !== $sku ? $sku : 'ID:' . $product_id );
			$parent_id = isset( $candidate['parent_id'] ) ? (int) $candidate['parent_id'] : 0;
			$name      = isset( $candidate['name'] ) ? trim( (string) $candidate['name'] ) : '';
			$label     = isset( $candidate['label'] ) ? trim( (string) $candidate['label'] ) : '';
			$quantity  = isset( $candidate['quantity'] ) && is_numeric( $candidate['quantity'] ) ? (float) $candidate['quantity'] : null;
			return array(
				'reference' => $reference,
				'product_id'=> $product_id,
				'parent_id' => $parent_id,
				'type'      => $type,
				'sku'       => $sku,
				'name'      => '' !== $name ? $name : $reference,
				'label'     => $label,
				'stock'     => array(
					'quantity' => $quantity,
					'status'   => isset( $candidate['status'] ) ? (string) $candidate['status'] : '',
				),
			);
		}

		/** تطبیق دقیق SKU/شناسه اول می‌آید تا انتخاب سریع‌تر باشد. */
		private static function rank( $rows, $term ) {
			$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $term ) : strtolower( $term );
			$exact  = array();
			$rest   = array();
			foreach ( $rows as $row ) {
				$sku    = function_exists( 'mb_strtolower' ) ? mb_strtolower( $row['sku'] ) : strtolower( $row['sku'] );
				$ref    = function_exists( 'mb_strtolower' ) ? mb_strtolower( $row['reference'] ) : strtolower( $row['reference'] );
				if ( ( '' !== $sku && $sku === $needle ) || $ref === $needle ) {
					$exact[] = $row;
				} else {
					$rest[] = $row;
				}
			}
			return array_merge( $exact, $rest );
		}

		/**
		 * کوئری مستقیم روی محصولات و Variationهای همین سایت.
		 *
		 * @param string $term  عبارت.
		 * @param int    $limit حداکثر.
		 * @return array<int,array<string,mixed>>
		 */
		private static function query( $term, $limit ) {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! function_exists( 'wc_get_product' ) ) {
				return array();
			}
			$term = (string) $term;
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$id   = ctype_digit( $term ) ? (int) $term : 0;
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- نام جداول از $wpdb خوانده می‌شود.
			$sql = $wpdb->prepare(
				"SELECT DISTINCT p.ID, p.post_type, p.post_parent
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
				WHERE p.post_type IN ('product','product_variation')
					AND p.post_status = 'publish'
					AND ( p.post_title LIKE %s OR m.meta_value LIKE %s OR p.ID = %d )
				ORDER BY p.post_type ASC, p.ID DESC
				LIMIT %d",
				$like,
				$like,
				$id,
				(int) $limit * 3
			);
			$found = $wpdb->get_results( $sql );
			// phpcs:enable
			if ( ! is_array( $found ) ) {
				return array();
			}
			$candidates = array();
			foreach ( $found as $post ) {
				$product_id = isset( $post->ID ) ? (int) $post->ID : 0;
				$product    = $product_id ? wc_get_product( $product_id ) : false;
				if ( ! $product || ! is_object( $product ) ) {
					continue;
				}
				$type  = (string) $product->get_type();
				$title = trim( (string) $product->get_name() );
				if ( 'variation' === $type ) {
					$parent_id = isset( $post->post_parent ) ? (int) $post->post_parent : 0;
					$parent    = $parent_id ? wc_get_product( $parent_id ) : false;
					$candidates[] = array(
						'product_id' => $product_id,
						'parent_id'  => $parent_id,
						'type'       => 'variation',
						'name'       => $parent && is_object( $parent ) ? trim( (string) $parent->get_name() ) : $title,
						'label'      => $title,
						'sku'        => (string) $product->get_sku(),
						'quantity'   => $product->get_stock_quantity(),
						'status'     => (string) $product->get_stock_status(),
					);
					continue;
				}
				$candidates[] = array(
					'product_id' => $product_id,
					'parent_id'  => 0,
					'type'       => $type,
					'name'       => $title,
					'label'      => '',
					'sku'        => (string) $product->get_sku(),
					'quantity'   => $product->get_stock_quantity(),
					'status'     => (string) $product->get_stock_status(),
				);
			}
			return $candidates;
		}

		/**
		 * جزئیات کامل یک محصول محلی (شامل Variationها) برای انتخاب از فهرست جست‌وجو.
		 *
		 * @param int $product_id شناسه.
		 * @return array|WP_Error
		 */
		public static function detail( $product_id ) {
			$product_id = (int) $product_id;
			if ( $product_id <= 0 || ! class_exists( 'TCAS_Link_Resolver' ) ) {
				return new WP_Error( 'tcas_search_unavailable', 'جزئیات محصول در این نصب قابل خواندن نیست.' );
			}
			return TCAS_Link_Resolver::resolve_local_reference( 'ID:' . $product_id );
		}
	}
}
