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

		/**
		 * شکستن عبارت به واژه‌ها؛ «قاب رویایی پروانه آبی» با ترتیب دیگر هم پیدا می‌شود.
		 * نویسه‌های کنترلی راست‌به‌چپ و نیم‌فاصله حذف می‌شوند و حداکثر ۶ واژه جست‌وجو می‌شود.
		 *
		 * @param string $term عبارت.
		 * @return array<int,string>
		 */
		public static function terms( $term ) {
			$term = trim( (string) $term );
			if ( '' === $term ) {
				return array();
			}
			if ( preg_match( '/[\x{200c}\x{200e}\x{200f}]/u', $term ) ) {
				$term = preg_replace( '/[\x{200c}\x{200e}\x{200f}]+/u', ' ', $term );
			}
			$term = preg_replace( '/\s+/u', ' ', (string) $term );
			// جداکننده‌های رایج در نام محصول: «-»، «/»، «|»، ویرگول فارسی و لاتین.
			$parts = preg_split( '/[\s,،;؛\|\/\\\\]+/u', (string) $term );
			if ( ! is_array( $parts ) ) {
				return array();
			}
			$total  = count( $parts );
			$tokens = array();
			foreach ( $parts as $part ) {
				$part = trim( $part, " \t\n\r\0\x0B-_()[]{}\"'.”“" );
				if ( '' === $part ) {
					continue;
				}
				$length = function_exists( 'mb_strlen' ) ? mb_strlen( $part ) : strlen( $part );
				// واژه‌های تک‌حرفی نویز می‌سازند، مگر اینکه کل عبارت یک واژه باشد.
				if ( $length < 2 && $total > 1 ) {
					continue;
				}
				$tokens[] = $part;
			}
			$tokens = array_values( array_unique( $tokens ) );
			return array_slice( $tokens, 0, 6 );
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
			$tokens = self::terms( $term );
			$id     = ctype_digit( (string) $term ) ? (int) $term : 0;
			if ( ! $tokens && ! $id ) {
				return array();
			}
			$candidates = array();
			// اول همهٔ واژه‌ها با هم (دقیق‌تر)، بعد هر واژه به‌تنهایی (نتیجهٔ بیشتر مثل جست‌وجوی معمول).
			foreach ( array( 'AND', 'OR' ) as $glue ) {
				$candidates = self::posts_for( $tokens, $id, $glue, (int) $limit * 3 );
				if ( $candidates ) {
					break;
				}
			}
			if ( ! $candidates ) {
				return array();
			}
			$rows = array();
			foreach ( $candidates as $post ) {
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
					$rows[]    = array(
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
				$rows[] = array(
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
			return $rows;
		}

		/**
		 * کوئری آماده‌شده برای واژه‌ها؛ LIKE هر واژه با esc_like ساخته می‌شود.
		 *
		 * @param array<int,string> $tokens واژه‌ها.
		 * @param int               $id     شناسهٔ عددی (اگر عبارت عدد باشد).
		 * @param string            $glue   AND یا OR.
		 * @param int               $limit  سقف.
		 * @return array<int,mixed>
		 */
		private static function posts_for( $tokens, $id, $glue, $limit ) {
			global $wpdb;
			$clauses = array();
			$values  = array();
			foreach ( $tokens as $token ) {
				$like      = '%' . $wpdb->esc_like( $token ) . '%';
				$clauses[] = '( p.post_title LIKE %s OR m.meta_value LIKE %s )';
				$values[]  = $like;
				$values[]  = $like;
			}
			if ( $id ) {
				$clauses[] = 'p.ID = %d';
				$values[]  = $id;
			}
			if ( ! $clauses ) {
				return array();
			}
			$values[] = (int) $limit;
			$where    = implode( ' ' . ( 'OR' === $glue ? 'OR' : 'AND' ) . ' ', $clauses );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- نام جداول از $wpdb و مقادیر با placeholders.
			$query = "SELECT DISTINCT p.ID, p.post_type, p.post_parent
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
				WHERE p.post_type IN ('product','product_variation')
					AND p.post_status = 'publish'
					AND ( {$where} )
				ORDER BY p.post_type ASC, p.ID DESC
				LIMIT %d";
			$sql = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $query ), $values ) );
			$found = $wpdb->get_results( $sql );
			// phpcs:enable
			return is_array( $found ) ? $found : array();
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
