<?php
/**
 * جست‌وجوی محلی محصولات TisaCase برای پرکردن شناسه بدون درخواست بیرونی.
 *
 * چرا؟ چسباندن لینک محصول باعث می‌شد مرورگر یک لینک کامل را در POST بفرستد و
 * فایروال/CDN سایت آن را با 403/418 مسدود کند. جست‌وجوی نام یا SKU فقط از
 * دیتابیس همین سایت می‌خواند: نه درخواستی به همکار می‌رود و نه آدرسی در POST
 * ارسال می‌شود.
 *
 * سرعت: مسیر جست‌وجو به‌ترتیب از سریع‌ترین منبع جلو می‌رود و در هر مرحله فقط
 * وقتی نتیجه خالی باشد به مرحلهٔ بعد می‌رسد:
 *
 *   ۱) ایندکس آمادهٔ FiboSearch (اگر نصب باشد) — یک کوئری روی یک جدول کوچک،
 *      بدون JOIN.
 *   ۲) SKU دقیق یا پیشوند SKU روی جدول ایندکس ووکامرس (کلید یکتای sku).
 *   ۳) عنوان محصول با پیشوند، بعد «شامل» — بدون JOIN روی postmeta.
 *   ۴) واژه‌به‌واژه روی عنوان (AND سپس OR).
 *
 * نتیجه در Transient کوتاه نگه داشته می‌شود و ردیف‌ها یک‌جا (نه با
 * wc_get_product به‌ازای هر ردیف) ساخته می‌شوند.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Local_Search' ) ) {

	final class TCAS_Local_Search {
		const MIN_TERM  = 2;
		const LIMIT     = 12;
		const MAX_LIMIT = 40;
		/** عمر کوتاه کش نتیجه؛ محصول تازه‌ساخته باید سریع دیده شود. */
		const CACHE_TTL = 120;
		/** سقف ردیف‌های فهرست محلی (جست‌وجوی صفر-تأخیر در مرورگر). */
		const INDEX_LIMIT = 12000;
		const INDEX_TTL   = 300;
		const CACHE_KEY = 'tcas_psearch_';

		/**
		 * قلاب تست: اگر تنظیم شود، جای کوئری دیتابیس استفاده می‌شود.
		 *
		 * @var callable|null
		 */
		public static $finder = null;

		/** منبع آخرین جست‌وجو: fibo / sku / title / token / memo / cache / hook / none. */
		public static $last_source = '';

		/** مدت آخرین جست‌وجو به میلی‌ثانیه. */
		public static $last_ms = 0.0;

		/** یادداشت درون‌درخواستی؛ هر عبارت یک‌بار محاسبه می‌شود. */
		private static $memo = array();

		/** جدول‌های شناسایی‌شده (وجود جدول و ستون‌ها) در همین درخواست. */
		private static $tables = array();

		/**
		 * جست‌وجوی نام/SKU و برگرداندن ردیف‌های آمادهٔ نمایش.
		 *
		 * @param string $term  عبارت جست‌وجو.
		 * @param int    $limit حداکثر تعداد.
		 * @return array<int,array<string,mixed>>
		 */
		public static function search( $term, $limit = self::LIMIT ) {
			$started = microtime( true );
			$term    = trim( (string) $term );
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
			$cacheable = ! self::$finder;
			$key       = self::cache_key( $term, $limit );
			if ( $cacheable ) {
				if ( isset( self::$memo[ $key ] ) ) {
					self::$last_source = 'memo';
					self::$last_ms     = ( microtime( true ) - $started ) * 1000;
					return self::$memo[ $key ];
				}
				$stored = self::cache_get( $key );
				if ( is_array( $stored ) ) {
					self::$memo[ $key ] = $stored;
					self::$last_source  = 'cache';
					self::$last_ms      = ( microtime( true ) - $started ) * 1000;
					return $stored;
				}
			}
			$candidates = self::candidates( $term, $limit * 3 );
			if ( ! is_array( $candidates ) ) {
				$candidates = array();
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
			$rows = self::rank( $rows, $term );
			if ( $cacheable ) {
				// نتیجهٔ خالی کش نمی‌شود تا محصول تازه‌ساخته فوراً پیدا شود.
				if ( $rows ) {
					self::cache_set( $key, $rows );
				}
				self::$memo[ $key ] = $rows;
			}
			self::$last_ms = ( microtime( true ) - $started ) * 1000;
			return $rows;
		}

		/** کلید کش/یادداشت برای عبارت و سقف. */
		private static function cache_key( $term, $limit ) {
			$term = function_exists( 'mb_strtolower' ) ? mb_strtolower( $term ) : strtolower( $term );
			return md5( $term . '|' . (int) $limit );
		}

		private static function cache_get( $key ) {
			if ( ! function_exists( 'get_transient' ) ) {
				return null;
			}
			$value = get_transient( self::CACHE_KEY . $key );
			return is_array( $value ) ? $value : null;
		}

		private static function cache_set( $key, $rows ) {
			if ( ! function_exists( 'set_transient' ) ) {
				return;
			}
			set_transient( self::CACHE_KEY . $key, $rows, self::CACHE_TTL );
		}

		/** پاک‌کردن یادداشت و کش؛ پس از ساخت/ویرایش محصول یا در تست‌ها. */
		public static function flush_cache() {
			self::$memo   = array();
			self::$tables = array();
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
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
		 * کاندیدهای خام از سریع‌ترین منبع موجود؛ اولین منبعی که نتیجه بدهد برنده است.
		 *
		 * @param string $term  عبارت.
		 * @param int    $limit حداکثر تعداد کاندید.
		 * @return array<int,array<string,mixed>>
		 */
		private static function candidates( $term, $limit ) {
			if ( self::$finder ) {
				self::$last_source = 'hook';
				return call_user_func( self::$finder, $term, $limit );
			}
			if ( ! self::db_ready() ) {
				self::$last_source = 'none';
				return array();
			}
			$ids = self::id_candidates( $term, $limit );
			if ( ! $ids ) {
				$ids = self::title_candidates( $term, $limit );
			}
			if ( ! $ids ) {
				$ids = self::token_candidates( $term, $limit );
			}
			if ( ! $ids ) {
				self::$last_source = 'none';
				return array();
			}
			return self::hydrate( $ids );
		}

		/** دیتابیس و ووکامرس آماده‌اند؟ */
		private static function db_ready() {
			global $wpdb;
			return isset( $wpdb ) && is_object( $wpdb ) && is_callable( array( $wpdb, 'get_results' ) ) && is_callable( array( $wpdb, 'prepare' ) ) && function_exists( 'wc_get_product' );
		}

		/**
		 * مسیر سریع شناسه: ID، SKU دقیق، ایندکس FiboSearch و SKU با پیشوند.
		 *
		 * @param string $term  عبارت.
		 * @param int    $limit سقف.
		 * @return array<int,int> شناسه‌ها به ترتیب اولویت.
		 */
		private static function id_candidates( $term, $limit ) {
			$ids = array();
			// «ID:123» یا عدد خالص: مستقیم همان محصول.
			$numeric = preg_match( '/^(?:ID:)?([1-9][0-9]*)$/i', $term, $parts ) ? (int) $parts[1] : 0;
			if ( $numeric ) {
				self::$last_source = 'id';
				return array( $numeric );
			}
			$lookup = self::lookup_table();
			if ( '' !== $lookup ) {
				$exact = self::query_ids(
					"SELECT product_id FROM {$lookup} WHERE sku = %s LIMIT %d",
					array( $term, min( 3, $limit ) )
				);
				if ( $exact ) {
					self::$last_source = 'sku';
					return $exact;
				}
			}
			// ایندکس آمادهٔ FiboSearch: یک جدول کوچک، بدون JOIN.
			$fibo = self::fibo_candidates( $term, $limit );
			if ( $fibo ) {
				self::$last_source = 'fibo';
				$ids               = $fibo;
			}
			if ( $ids ) {
				return $ids;
			}
			if ( '' !== $lookup ) {
				// پیشوند SKU روی کلید یکتا؛ بعد «شامل» برای SKUهای وسط‌دار.
				foreach ( array( $term . '%', '%' . $term . '%' ) as $pattern ) {
					$found = self::query_ids(
						"SELECT product_id FROM {$lookup} WHERE sku LIKE %s ORDER BY product_id DESC LIMIT %d",
						array( $pattern, (int) $limit )
					);
					if ( $found ) {
						self::$last_source = 'sku';
						return $found;
					}
				}
			}
			return array();
		}

		/**
		 * جست‌وجو در ایندکس آمادهٔ FiboSearch (نسخهٔ حرفه‌ای یا هر ایندکس سازگار).
		 * ستون‌ها در زمان اجرا بررسی می‌شوند تا با نسخه‌های مختلف کار کند.
		 *
		 * @param string $term  عبارت.
		 * @param int    $limit سقف.
		 * @return array<int,int>
		 */
		private static function fibo_candidates( $term, $limit ) {
			global $wpdb;
			$table = self::fibo_table();
			if ( '' === $table ) {
				return array();
			}
			$columns = self::table_columns( $table );
			$like    = '%' . $wpdb->esc_like( $term ) . '%';
			$where   = array( 'name LIKE %s' );
			$values  = array( $like );
			foreach ( array( 'sku', 'sku_variations' ) as $column ) {
				if ( in_array( $column, $columns, true ) ) {
					$where[]  = $column . ' LIKE %s';
					$values[] = $like;
				}
			}
			$sql      = "SELECT post_id FROM {$table} WHERE ( " . implode( ' OR ', $where ) . ' ) ORDER BY id DESC LIMIT %d';
			$values[] = (int) $limit;
			return self::query_ids( $sql, $values );
		}

		/**
		 * عنوان محصول: اول پیشوند (سریع‌تر)، بعد «شامل»؛ بدون JOIN روی postmeta.
		 *
		 * @param string $term  عبارت.
		 * @param int    $limit سقف.
		 * @return array<int,int>
		 */
		private static function title_candidates( $term, $limit ) {
			global $wpdb;
			$prefix = self::query_ids(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status = 'publish' AND post_title LIKE %s ORDER BY post_type ASC, ID DESC LIMIT %d",
				array( $wpdb->esc_like( $term ) . '%', (int) $limit )
			);
			if ( $prefix ) {
				self::$last_source = 'title';
				return $prefix;
			}
			$contains = self::query_ids(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status = 'publish' AND post_title LIKE %s ORDER BY post_type ASC, ID DESC LIMIT %d",
				array( '%' . $wpdb->esc_like( $term ) . '%', (int) $limit )
			);
			if ( $contains ) {
				self::$last_source = 'title';
			}
			return $contains;
		}

		/**
		 * واژه‌به‌واژه روی عنوان: اول همهٔ واژه‌ها با هم، بعد هر واژه به‌تنهایی.
		 *
		 * @param string $term  عبارت.
		 * @param int    $limit سقف.
		 * @return array<int,int>
		 */
		private static function token_candidates( $term, $limit ) {
			global $wpdb;
			$tokens = self::terms( $term );
			if ( count( $tokens ) < 2 ) {
				return array();
			}
			foreach ( array( 'AND', 'OR' ) as $glue ) {
				$clauses = array();
				$values  = array();
				foreach ( $tokens as $token ) {
					$clauses[] = 'post_title LIKE %s';
					$values[]  = '%' . $wpdb->esc_like( $token ) . '%';
				}
				$values[] = (int) $limit;
				$sql      = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status = 'publish' AND ( "
					. implode( ' ' . ( 'OR' === $glue ? 'OR' : 'AND' ) . ' ', $clauses )
					. ' ) ORDER BY post_type ASC, ID DESC LIMIT %d';
				$found    = self::query_ids( $sql, $values );
				if ( $found ) {
					self::$last_source = 'token';
					return $found;
				}
			}
			return array();
		}

		/**
		 * اجرای یک کوئری شناسه با prepare امن.
		 *
		 * @param string $sql    کوئری با placeholder.
		 * @param array  $values مقادیر.
		 * @return array<int,int>
		 */
		private static function query_ids( $sql, $values ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- مقادیر با prepare و نام جدول از $wpdb.
			$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $values ) );
			$found    = $wpdb->get_results( $prepared );
			if ( ! is_array( $found ) ) {
				return array();
			}
			$ids = array();
			foreach ( $found as $row ) {
				$id = 0;
				if ( is_object( $row ) ) {
					$id = isset( $row->ID ) ? (int) $row->ID : ( isset( $row->post_id ) ? (int) $row->post_id : ( isset( $row->product_id ) ? (int) $row->product_id : 0 ) );
				} elseif ( is_array( $row ) ) {
					$id = isset( $row['ID'] ) ? (int) $row['ID'] : ( isset( $row['post_id'] ) ? (int) $row['post_id'] : ( isset( $row['product_id'] ) ? (int) $row['product_id'] : 0 ) );
				}
				if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
			return $ids;
		}

		/**
		 * ساخت ردیف‌های خام با دو کوئری IN (محصولات + جدول ایندکس ووکامرس)؛
		 * نه wc_get_product به‌ازای هر ردیف. اگر جدول ایندکس نبود، همان مسیر
		 * قبلی با ووکامرس می‌رود.
		 *
		 * @param array<int,int> $ids شناسه‌ها.
		 * @return array<int,array<string,mixed>>
		 */
		private static function hydrate( $ids ) {
			$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
			if ( ! $ids ) {
				return array();
			}
			$posts = self::posts_by_id( $ids );
			if ( ! $posts ) {
				return self::hydrate_with_products( $ids );
			}
			if ( '' === self::lookup_table() ) {
				// ووکامرس قدیمی: جدول ایندکس نیست، پس همان مسیر محصول.
				return self::hydrate_with_products( $ids );
			}
			$lookup   = self::lookup_rows( $ids );
			$types    = self::product_types( $ids );
			$parents  = array();
			foreach ( $posts as $post ) {
				if ( 'product_variation' === $post['type'] && $post['parent'] ) {
					$parents[ $post['parent'] ] = true;
				}
			}
			$parent_titles = $parents ? self::posts_by_id( array_keys( $parents ) ) : array();
			$rows          = array();
			foreach ( $ids as $id ) {
				if ( ! isset( $posts[ $id ] ) ) {
					continue;
				}
				$post   = $posts[ $id ];
				$stock  = isset( $lookup[ $id ] ) ? $lookup[ $id ] : array();
				$type   = isset( $types[ $id ] ) ? $types[ $id ] : $post['type'];
				$title  = $post['title'];
				$parent = $post['parent'];
				$name   = $title;
				$label  = '';
				if ( 'product_variation' === $post['type'] || 'variation' === $type ) {
					$type  = 'variation';
					$label = $title;
					if ( $parent && isset( $parent_titles[ $parent ] ) ) {
						$name = $parent_titles[ $parent ]['title'];
					}
				}
				$rows[] = array(
					'product_id' => $id,
					'parent_id'  => $parent,
					'type'       => $type,
					'name'       => $name,
					'label'      => $label,
					'sku'        => isset( $stock['sku'] ) ? (string) $stock['sku'] : '',
					'quantity'   => isset( $stock['quantity'] ) ? $stock['quantity'] : null,
					'status'     => isset( $stock['status'] ) ? (string) $stock['status'] : '',
				);
			}
			return $rows;
		}

		/**
		 * شناسه‌ها را به (شناسه => عنوان/نوع/والد) تبدیل می‌کند.
		 *
		 * @param array<int,int> $ids شناسه‌ها.
		 * @return array<int,array<string,mixed>>
		 */
		private static function posts_by_id( $ids ) {
			global $wpdb;
			$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
			if ( ! $ids ) {
				return array();
			}
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$sql          = "SELECT ID, post_title, post_type, post_parent FROM {$wpdb->posts} WHERE ID IN ( {$placeholders} )";
			$prepared     = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $ids ) );
			$found        = $wpdb->get_results( $prepared );
			$out          = array();
			foreach ( (array) $found as $row ) {
				$id = is_object( $row ) ? (int) $row->ID : (int) $row['ID'];
				if ( ! $id ) {
					continue;
				}
				$type = is_object( $row ) ? (string) $row->post_type : (string) $row['post_type'];
				$out[ $id ] = array(
					'title'  => is_object( $row ) ? (string) $row->post_title : (string) $row['post_title'],
					'type'   => $type,
					'parent' => is_object( $row ) ? (int) $row->post_parent : (int) $row['post_parent'],
				);
			}
			return $out;
		}

		/**
		 * نوع واقعی محصول (simple/variable/variation) با یک کوئری روی تاکسونومی
		 * product_type؛ اگر نتیجه نبود از post_type استفاده می‌شود.
		 *
		 * @param array<int,int> $ids شناسه‌ها.
		 * @return array<int,string>
		 */
		private static function product_types( $ids ) {
			global $wpdb;
			$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
			if ( ! $ids ) {
				return array();
			}
			$rel    = isset( $wpdb->term_relationships ) ? $wpdb->term_relationships : $wpdb->prefix . 'term_relationships';
			$tax    = isset( $wpdb->term_taxonomy ) ? $wpdb->term_taxonomy : $wpdb->prefix . 'term_taxonomy';
			$terms  = isset( $wpdb->terms ) ? $wpdb->terms : $wpdb->prefix . 'terms';
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$sql          = "SELECT tr.object_id AS object_id, t.slug AS slug
				FROM {$rel} tr
				INNER JOIN {$tax} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
				INNER JOIN {$terms} t ON t.term_id = tt.term_id
				WHERE tr.object_id IN ( {$placeholders} )";
			$prepared     = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $ids ) );
			$found        = $wpdb->get_results( $prepared );
			$out          = array();
			foreach ( (array) $found as $row ) {
				$row = (array) $row;
				$id  = isset( $row['object_id'] ) ? (int) $row['object_id'] : 0;
				$tag = isset( $row['slug'] ) ? (string) $row['slug'] : '';
				if ( $id && '' !== $tag ) {
					$out[ $id ] = $tag;
				}
			}
			return $out;
		}

		/**
		 * SKU و موجودی از جدول ایندکس ووکامرس؛ یک کوئری برای همهٔ شناسه‌ها.
		 *
		 * @param array<int,int> $ids شناسه‌ها.
		 * @return array<int,array<string,mixed>>
		 */
		private static function lookup_rows( $ids ) {
			global $wpdb;
			$table = self::lookup_table();
			if ( '' === $table ) {
				return array();
			}
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$sql          = "SELECT product_id, sku, stock_quantity, stock_status FROM {$table} WHERE product_id IN ( {$placeholders} )";
			$prepared     = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $ids ) );
			$found        = $wpdb->get_results( $prepared );
			$out          = array();
			foreach ( (array) $found as $row ) {
				$row     = (array) $row;
				$id      = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
				if ( ! $id ) {
					continue;
				}
				$out[ $id ] = array(
					'sku'      => isset( $row['sku'] ) ? (string) $row['sku'] : '',
					'quantity' => isset( $row['stock_quantity'] ) && '' !== $row['stock_quantity'] && is_numeric( $row['stock_quantity'] ) ? (float) $row['stock_quantity'] : null,
					'status'   => isset( $row['stock_status'] ) ? (string) $row['stock_status'] : '',
				);
			}
			return $out;
		}

		/**
		 * مسیر پشتیبان: اگر جدول‌های بالا خوانده نشدند، همان رفتار قبلی با ووکامرس.
		 *
		 * @param array<int,int> $ids شناسه‌ها.
		 * @return array<int,array<string,mixed>>
		 */
		private static function hydrate_with_products( $ids ) {
			$rows = array();
			foreach ( $ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product || ! is_object( $product ) ) {
					continue;
				}
				$type  = (string) $product->get_type();
				$title = trim( (string) $product->get_name() );
				if ( 'variation' === $type ) {
					$parent_id = (int) $product->get_parent_id();
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

		/** جدول ایندکس ووکامرس (SKU و وضعیت موجودی). */
		private static function lookup_table() {
			global $wpdb;
			if ( isset( self::$tables['lookup'] ) ) {
				return self::$tables['lookup'];
			}
			$candidates = array();
			if ( isset( $wpdb->wc_product_meta_lookup ) ) {
				$candidates[] = $wpdb->wc_product_meta_lookup;
			}
			$candidates[] = $wpdb->prefix . 'wc_product_meta_lookup';
			self::$tables['lookup'] = '';
			foreach ( $candidates as $table ) {
				$columns = self::table_columns( $table );
				if ( in_array( 'product_id', $columns, true ) && in_array( 'sku', $columns, true ) ) {
					self::$tables['lookup'] = $table;
					break;
				}
			}
			return self::$tables['lookup'];
		}

		/**
		 * جدول ایندکس FiboSearch، اگر نصب و ساخته شده باشد.
		 * نسخهٔ حرفه‌ای: {prefix}dgwt_wcas_index — نسخه‌های دیگر نام‌های مشابه دارند.
		 */
		private static function fibo_table() {
			global $wpdb;
			if ( isset( self::$tables['fibo'] ) ) {
				return self::$tables['fibo'];
			}
			self::$tables['fibo'] = '';
			$candidates           = array(
				$wpdb->prefix . 'dgwt_wcas_index',
				$wpdb->prefix . 'fibosearch_index',
				$wpdb->prefix . 'wcas_index',
			);
			foreach ( $candidates as $table ) {
				$columns = self::table_columns( $table );
				if ( in_array( 'post_id', $columns, true ) && in_array( 'name', $columns, true ) ) {
					self::$tables['fibo'] = $table;
					break;
				}
			}
			return self::$tables['fibo'];
		}

		/**
		 * ستون‌های یک جدول؛ اگر جدول نبود آرایهٔ خالی. نتیجه در همین درخواست کش می‌شود.
		 *
		 * @param string $table نام جدول (فقط از فهرست داخلی).
		 * @return array<int,string>
		 */
		private static function table_columns( $table ) {
			global $wpdb;
			$table = (string) $table;
			if ( '' === $table || ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'get_results' ) ) ) {
				return array();
			}
			if ( isset( self::$tables[ 'cols:' . $table ] ) ) {
				return self::$tables[ 'cols:' . $table ];
			}
			$columns = array();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- نام جدول از فهرست داخلی است.
			$found = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" );
			if ( is_array( $found ) ) {
				foreach ( $found as $row ) {
					$row  = (array) $row;
					$name = isset( $row['Field'] ) ? (string) $row['Field'] : ( isset( $row['field'] ) ? (string) $row['field'] : '' );
					if ( '' !== $name ) {
						$columns[] = $name;
					}
				}
			}
			self::$tables[ 'cols:' . $table ] = $columns;
			return $columns;
		}

		/**
		 * فهرست فشردهٔ همهٔ محصولات و Variationهای منتشرشده برای جست‌وجوی محلی در مرورگر.
		 *
		 * یک کوئری محدود با JOIN روی کلید اصلی جدول ایندکس ووکامرس (نه روی postmeta)
		 * و بدون LIKE؛ نتیجه ۵ دقیقه کش می‌شود. بعد از آن، مرورگر بدون هیچ درخواستی
		 * فیلتر می‌کند و نتیجه همان لحظه می‌آید.
		 *
		 * قالب هر ردیف: [ id, sku, title, parent_id, type, quantity, stock_status ]
		 *
		 * @param int $limit سقف ردیف‌ها.
		 * @return array{rows:array<int,array<int,mixed>>,more:bool,v:string,ms:float,source:string}
		 */
		public static function index( $limit = 0 ) {
			$started = microtime( true );
			$limit   = (int) $limit;
			if ( $limit < 1 || $limit > self::INDEX_LIMIT ) {
				$limit = self::INDEX_LIMIT;
			}
			$cache_key = 'tcas_pindex_' . $limit;
			if ( function_exists( 'get_transient' ) ) {
				$cached = get_transient( $cache_key );
				if ( is_array( $cached ) && isset( $cached['rows'] ) ) {
					self::$last_source = 'index-cache';
					self::$last_ms     = ( microtime( true ) - $started ) * 1000;
					$cached['ms']      = self::$last_ms;
					$cached['source']  = 'cache';
					return $cached;
				}
			}
			$rows = array();
			$more = false;
			if ( self::db_ready() ) {
				global $wpdb;
				$lookup = self::lookup_table();
				$join   = '' !== $lookup ? " LEFT JOIN {$lookup} l ON l.product_id = p.ID" : '';
				$select = '' !== $lookup ? 'l.sku, l.stock_quantity, l.stock_status' : "'' AS sku, NULL AS stock_quantity, '' AS stock_status";
				$sql    = "SELECT p.ID, p.post_title, p.post_type, p.post_parent, {$select}
					FROM {$wpdb->posts} p{$join}
					WHERE p.post_type IN ('product','product_variation') AND p.post_status = 'publish'
					ORDER BY p.ID DESC
					LIMIT %d";
				$found  = self::query_rows( $sql, array( (int) $limit + 1 ) );
				if ( count( $found ) > $limit ) {
					$more = true;
					$found = array_slice( $found, 0, $limit );
				}
				foreach ( $found as $row ) {
					$row = (array) $row;
					$id  = isset( $row['ID'] ) ? (int) $row['ID'] : 0;
					if ( ! $id ) {
						continue;
					}
					$quantity = isset( $row['stock_quantity'] ) && '' !== $row['stock_quantity'] && null !== $row['stock_quantity'] && is_numeric( $row['stock_quantity'] )
						? (float) $row['stock_quantity']
						: null;
					$rows[] = array(
						$id,
						isset( $row['sku'] ) ? (string) $row['sku'] : '',
						isset( $row['post_title'] ) ? (string) $row['post_title'] : '',
						isset( $row['post_parent'] ) ? (int) $row['post_parent'] : 0,
						'product_variation' === ( isset( $row['post_type'] ) ? $row['post_type'] : '' ) ? 'variation' : 'product',
						$quantity,
						isset( $row['stock_status'] ) ? (string) $row['stock_status'] : '',
					);
				}
			}
			$result = array(
				'rows'   => $rows,
				'more'   => $more,
				'v'      => ( $rows ? (string) $rows[0][0] : '0' ) . '-' . count( $rows ) . '-' . ( $more ? 'more' : 'all' ),
				'ms'     => ( microtime( true ) - $started ) * 1000,
				'source' => 'index',
			);
			if ( function_exists( 'set_transient' ) && $rows ) {
				// فهرست ناقص هم کوتاه‌مدت کش می‌شود تا در فروشگاه‌های بزرگ این کوئری
				// سنگین در هر بارگذاریٔ صفحه تکرار نشود.
				set_transient( $cache_key, $result, $more ? 60 : self::INDEX_TTL );
			}
			self::$last_source = 'index';
			self::$last_ms     = $result['ms'];
			return $result;
		}

		/**
		 * یک کوئری با prepare امن و بازگرداندن آرایهٔ ردیف‌های خام.
		 *
		 * @param string $sql    کوئری.
		 * @param array  $values مقادیر.
		 * @return array<int,mixed>
		 */
		private static function query_rows( $sql, $values ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- مقادیر با prepare و نام جدول از $wpdb.
			$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $values ) );
			$found    = $wpdb->get_results( $prepared, 'ARRAY_A' );
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
