<?php
/**
 * لایهٔ داده‌ها: جستجو، فیلتر چندلایه، نرمال‌سازی متن فارسی و استخراج ویژگی‌ها و متغیرها.
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBVM_DB' ) ) {

	final class TCBVM_DB {

		const MAX_VARIATIONS_PER_PRODUCT = 3000;

		/**
		 * نرمال‌سازی کاراکترهای فارسی و عربی (ی، ک، نیم‌فاصله).
		 */
		public static function normalize_persian( $str ) {
			if ( ! is_string( $str ) || '' === $str ) {
				return '';
			}
			$arabic_chars  = array( 'ي', 'ك', 'ة', 'ۀ', 'ؤ', "\xC2\xA0", "\xE2\x80\x8C" );
			$persian_chars = array( 'ی', 'ک', 'ه', 'ه', 'و', ' ', ' ' );
			$clean         = str_replace( $arabic_chars, $persian_chars, $str );
			return trim( preg_replace( '/\s+/', ' ', $clean ) );
		}

		/**
		 * جستجو و استخراج شناسه‌های محصولات بر اساس فیلترهای مشخص‌شده.
		 *
		 * @param array $filters آرایه فیلترها (دسته‌بندی، کلمه کلیدی، استثناها، شناسه‌ها، نوع محصول، پیشوند SKU).
		 * @return array لیست شناسه‌های یکتا و مرتب‌شده محصولات.
		 */
		public static function query_product_ids( array $filters ) {
			$page = self::query_product_ids_page( $filters, 0, 50 );
			return $page['ids'];
		}

		/**
		 * جستجوی keyset؛ در هر درخواست فقط یک صفحه محدود از شناسه‌ها خوانده می‌شود.
		 * cursor برای محصولات مرتب‌شده، ID آخرین ردیف مصرف‌شده است؛ برای فهرست دستی
		 * cursor اندیس ورودی است.
		 *
		 * @return array{ids:array,next_cursor:int,has_more:bool,scanned:int}
		 */
		public static function query_product_ids_page( array $filters, $cursor = 0, $limit = 50 ) {
			$cursor = max( 0, absint( $cursor ) );
			$limit  = min( 50, max( 1, absint( $limit ) ) );
			$mode   = isset( $filters['mode'] ) ? sanitize_key( $filters['mode'] ) : 'filters';

			if ( 'manual' === $mode || ! empty( $filters['manual_ids'] ) ) {
				$raw_ids = is_array( $filters['manual_ids'] ) ? $filters['manual_ids'] : explode( ',', str_replace( array( '،', "\n", "\r", '|' ), ',', (string) $filters['manual_ids'] ) );
				$raw_ids = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );
				$truncated = count( $raw_ids ) > 5000;
				$raw_ids = array_slice( $raw_ids, 0, 5000 );
				$input_page = array_slice( $raw_ids, $cursor, $limit + 1 );
				$has_more   = count( $input_page ) > $limit;
				if ( $has_more ) array_pop( $input_page );
				$ids = self::filter_valid_products( $input_page, $filters );
				return array( 'ids' => $ids, 'next_cursor' => $cursor + count( $input_page ), 'has_more' => $has_more, 'scanned' => count( $input_page ), 'truncated' => $truncated );
			}

			if ( 'sku' === $mode && ! empty( $filters['sku'] ) ) {
				$sku_mode = isset( $filters['sku_mode'] ) ? sanitize_key( $filters['sku_mode'] ) : 'starts_with';
				$page     = self::query_sku_product_ids_page( $filters['sku'], $sku_mode, $cursor, $limit );
				$ids      = self::filter_valid_products( $page['ids'], $filters );
				if ( ! empty( $filters['keywords'] ) || ! empty( $filters['exclude_keywords'] ) || ! empty( $filters['model_term'] ) ) {
					$ids = self::refine_product_ids( $ids, $filters );
				}
				$page['ids'] = $ids;
				return $page;
			}

			$post_statuses = isset( $filters['statuses'] ) && is_array( $filters['statuses'] )
				? array_values( array_filter( array_map( 'sanitize_key', $filters['statuses'] ) ) )
				: array( 'publish', 'draft', 'private' );
			$product_types = isset( $filters['product_types'] ) && is_array( $filters['product_types'] )
				? array_map( 'sanitize_key', $filters['product_types'] )
				: array( 'variable', 'simple' );
			$tax_query = array( 'relation' => 'AND' );
			if ( ! empty( $filters['category_ids'] ) ) {
				$cat_ids = array_values( array_filter( array_map( 'absint', (array) $filters['category_ids'] ) ) );
				if ( $cat_ids ) {
					$tax_query[] = array(
						'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_ids,
						'include_children' => ! empty( $filters['include_children'] ), 'operator' => 'IN',
					);
				}
			}
			if ( $product_types && ! in_array( 'all', $product_types, true ) ) {
				$tax_query[] = array( 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => $product_types, 'operator' => 'IN' );
			}

			$query_args = array(
				'post_type' => 'product', 'post_status' => $post_statuses, 'fields' => 'ids',
				'posts_per_page' => $limit + 1, 'orderby' => 'ID', 'order' => 'DESC',
				'ignore_sticky_posts' => true, 'no_found_rows' => true,
				'update_post_meta_cache' => false, 'update_post_term_cache' => false,
				'suppress_filters' => false, 'tcbvm_keyset_cursor' => $cursor,
			);
			if ( count( $tax_query ) > 1 ) $query_args['tax_query'] = $tax_query;
			$cursor_filter = static function( $where, $query ) use ( $cursor ) {
				if ( $query->get( 'tcbvm_keyset_cursor' ) && $cursor > 0 ) {
					global $wpdb;
					$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID < %d", $cursor );
				}
				return $where;
			};
			add_filter( 'posts_where', $cursor_filter, 10, 2 );
			try {
				$query = new WP_Query( $query_args );
			} finally {
				remove_filter( 'posts_where', $cursor_filter, 10 );
			}
			$raw_ids  = array_map( 'absint', (array) $query->posts );
			$has_more = count( $raw_ids ) > $limit;
			if ( $has_more ) array_pop( $raw_ids );
			$next_cursor = $raw_ids ? (int) end( $raw_ids ) : $cursor;
			$scanned    = count( $raw_ids );

			if ( ! empty( $filters['sku'] ) && $raw_ids ) {
				$sku_mode = isset( $filters['sku_mode'] ) ? sanitize_key( $filters['sku_mode'] ) : 'starts_with';
				$sku_ids  = self::query_sku_ids_for_products( $raw_ids, $filters['sku'], $sku_mode );
				$raw_ids  = array_values( array_intersect( $raw_ids, $sku_ids ) );
			}
			$ids = self::refine_product_ids( $raw_ids, $filters );
			return array( 'ids' => $ids, 'next_cursor' => $next_cursor, 'has_more' => $has_more, 'scanned' => $scanned );
		}

		private static function query_sku_product_ids_page( $sku_text, $mode, $cursor, $limit ) {
			global $wpdb;
			$sku_text = trim( (string) $sku_text );
			if ( '' === $sku_text ) return array( 'ids' => array(), 'next_cursor' => 0, 'has_more' => false, 'scanned' => 0 );
			if ( 'exact' === $mode ) { $operator = '='; $pattern = $sku_text; }
			elseif ( 'contains' === $mode ) { $operator = 'LIKE'; $pattern = '%' . $wpdb->esc_like( $sku_text ) . '%'; }
			else { $operator = 'LIKE'; $pattern = $wpdb->esc_like( $sku_text ) . '%'; }
			$sql = "SELECT matched.pid FROM (SELECT DISTINCT IF(p.post_type='product_variation',p.post_parent,p.ID) AS pid FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('trash','auto-draft') AND pm.meta_key='_sku' AND pm.meta_value {$operator} %s) matched INNER JOIN {$wpdb->posts} parent ON parent.ID=matched.pid WHERE parent.post_type='product' AND parent.post_status NOT IN ('trash','auto-draft')";
			$args = array( $pattern );
			if ( $cursor > 0 ) { $sql .= ' AND matched.pid < %d'; $args[] = $cursor; }
			$sql .= ' ORDER BY matched.pid DESC LIMIT %d'; $args[] = $limit + 1;
			$ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
			$has_more = count( $ids ) > $limit;
			if ( $has_more ) array_pop( $ids );
			return array( 'ids' => $ids, 'next_cursor' => $ids ? (int) end( $ids ) : $cursor, 'has_more' => $has_more, 'scanned' => count( $ids ) );
		}

		private static function query_sku_ids_for_products( array $product_ids, $sku_text, $mode ) {
			global $wpdb;
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
			if ( ! $ids ) return array();
			if ( 'exact' === $mode ) { $operator = '='; $pattern = (string) $sku_text; }
			elseif ( 'contains' === $mode ) { $operator = 'LIKE'; $pattern = '%' . $wpdb->esc_like( (string) $sku_text ) . '%'; }
			else { $operator = 'LIKE'; $pattern = $wpdb->esc_like( (string) $sku_text ) . '%'; }
			$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$sql = "SELECT DISTINCT IF(p.post_type='product_variation',p.post_parent,p.ID) AS pid FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('trash','auto-draft') AND pm.meta_key='_sku' AND pm.meta_value {$operator} %s AND ((p.post_type='product' AND p.ID IN ($ph)) OR (p.post_type='product_variation' AND p.post_parent IN ($ph)))";
			$args = array_merge( array( $pattern ), $ids, $ids );
			return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
		}

		/**
		 * استخراج محصولات بر اساس پیشوند یا تطبیق شناسه (SKU).
		 * چه SKU روی خود محصول والد تنظیم شده باشد چه روی متغیرهای آن.
		 *
		 * @param string $sku_text متن یا پیشوند SKU (مثلاً CH).
		 * @param string $mode     حالت تطبیق: starts_with یا contains یا exact.
		 * @return array لیست شناسه‌های یکتا.
		 */
		/** Count existing variations with bounded result memory. */
		public static function get_variation_counts( array $product_ids ) {
			global $wpdb;
			$counts = array();
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
			foreach ( $ids as $id ) $counts[ $id ] = 0;
			foreach ( array_chunk( $ids, 400 ) as $chunk ) {
				$ph = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_parent, COUNT(ID) AS variation_count FROM {$wpdb->posts} WHERE post_type='product_variation' AND post_status IN ('publish','private') AND post_parent IN ($ph) GROUP BY post_parent", $chunk ) );
				foreach ( $rows as $row ) $counts[ (int) $row->post_parent ] = (int) $row->variation_count;
			}
			return $counts;
		}

		public static function query_product_ids_by_sku( $sku_text, $mode = 'starts_with' ) {
			// Compatibility wrapper: deliberately return only one bounded page.
			$page = self::query_sku_product_ids_page( $sku_text, $mode, 0, 50 );
			return $page['ids'];
		}

		/**
		 * پالایش و فیلتر دقیق شناسه‌ها بر اساس کلمات کلیدی، استثناها و متغیرها.
		 */
		private static function refine_product_ids( array $ids, array $filters ) {
			if ( empty( $ids ) ) {
				return array();
			}

			global $wpdb;
			$include_keywords = isset( $filters['keywords'] ) ? self::parse_keywords( $filters['keywords'] ) : array();
			$exclude_keywords = isset( $filters['exclude_keywords'] ) ? self::parse_keywords( $filters['exclude_keywords'] ) : array();
			$match_mode       = isset( $filters['match_mode'] ) ? sanitize_key( $filters['match_mode'] ) : 'contains';
			$has_model_filter = ! empty( $filters['model_term'] );
			$target_model     = $has_model_filter ? trim( (string) $filters['model_term'] ) : '';

			$passed_ids = array();

			// دسته‌بندی برای بهینه‌سازی خواندن عناوین
			$chunks = array_chunk( $ids, 500 );
			foreach ( $chunks as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$chunk
					)
				);

				foreach ( $rows as $row ) {
					$pid   = (int) $row->ID;
					$title = self::normalize_persian( $row->post_title );

					// بررسی کلمات مثبت (باید حداقل یکی یا همه را داشته باشد)
					if ( ! empty( $include_keywords ) ) {
						$matched = false;
						foreach ( $include_keywords as $kw ) {
							$kw = self::normalize_persian( $kw );
							if ( '' === $kw ) {
								continue;
							}
							if ( 'starts_with' === $match_mode ) {
								if ( 0 === mb_stripos( $title, $kw, 0, 'UTF-8' ) ) {
									$matched = true;
									break;
								}
							} else {
								if ( false !== mb_stripos( $title, $kw, 0, 'UTF-8' ) ) {
									$matched = true;
									break;
								}
							}
						}
						if ( ! $matched ) {
							continue;
						}
					}

					// بررسی کلمات منفی/استثنا
					if ( ! empty( $exclude_keywords ) ) {
						$excluded = false;
						foreach ( $exclude_keywords as $ekw ) {
							$ekw = self::normalize_persian( $ekw );
							if ( '' !== $ekw && false !== mb_stripos( $title, $ekw, 0, 'UTF-8' ) ) {
								$excluded = true;
								break;
							}
						}
						if ( $excluded ) {
							continue;
						}
					}

					// اگر فیلتر داشتن یک مدل خاص تنظیم شده باشد
					if ( $has_model_filter ) {
						if ( ! self::product_has_variation_term( $pid, $target_model ) ) {
							continue;
						}
					}

					$passed_ids[] = $pid;
				}
			}

			return array_values( array_unique( $passed_ids ) );
		}

		/**
		 * اعتبارسنجی شناسه‌های دستی وارد شده.
		 */
		private static function filter_valid_products( array $ids, array $filters ) {
			if ( empty( $ids ) ) {
				return array();
			}
			global $wpdb;
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$valid_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE ID IN ($placeholders) AND post_type = 'product'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$ids
				)
			);
			return array_map( 'absint', (array) $valid_ids );
		}

		/**
		 * تبدیل متن کلمات کلیدی به آرایه.
		 */
		public static function parse_keywords( $raw ) {
			if ( is_array( $raw ) ) {
				return array_values( array_filter( array_map( 'trim', $raw ) ) );
			}
			$clean = str_replace( array( '،', "\n", "\r", '|' ), ',', (string) $raw );
			$parts = explode( ',', $clean );
			return array_values( array_filter( array_map( 'trim', $parts ) ) );
		}

		/**
		 * بررسی اینکه آیا محصول موردنظر مدل خاصی را دارد یا خیر.
		 */
		public static function product_has_variation_term( $product_id, $term_name ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || ! $product->is_type( 'variable' ) ) {
				return false;
			}
			$normalized_term = mb_strtolower( self::normalize_persian( $term_name ), 'UTF-8' );

			$attributes = $product->get_attributes();
			foreach ( $attributes as $attr ) {
				if ( $attr->is_taxonomy() ) {
					$terms = wc_get_product_terms( $product_id, $attr->get_name(), array( 'fields' => 'names' ) );
					foreach ( $terms as $name ) {
						if ( mb_strtolower( self::normalize_persian( $name ), 'UTF-8' ) === $normalized_term ) {
							return true;
						}
					}
				} else {
					$options = (array) $attr->get_options();
					foreach ( $options as $opt ) {
						if ( mb_strtolower( self::normalize_persian( $opt ), 'UTF-8' ) === $normalized_term ) {
							return true;
						}
					}
				}
			}
			return false;
		}

		/**
		 * حل سراسری عنوان ویژگی: یافتن همهٔ تاکسونومی‌های ویژگی منطبق با نام، برچسب یا اسلاگ.
		 * همواره نام ورودی به‌عنوان نامزد «ویژگی محلی» نیز نگهداری می‌شود تا جستجو
		 * ویژگی‌های غیرتاکسونومی ذخیره‌شده در _product_attributes را هم پوشش دهد.
		 *
		 * @param string $label عنوان ویژگی واردشده توسط کاربر (مثلاً «مدل گوشی»).
		 * @return array آرایه حاوی taxonomies (key, attribute_id, label) و local_name.
		 */
		public static function resolve_attribute_globally( $label ) {
			$label   = trim( (string) $label );
			$matches = array(
				'taxonomies' => array(),
				'local_name' => $label,
			);

			if ( '' === $label ) {
				return $matches;
			}

			if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
				$norm = self::normalize_persian( $label );
				foreach ( (array) wc_get_attribute_taxonomies() as $tax ) {
					if ( empty( $tax->attribute_name ) ) {
						continue;
					}
					$tax_name = wc_attribute_taxonomy_name( $tax->attribute_name );
					$tax_slug_readable = str_replace( array( 'pa_', '-', '_' ), array( '', ' ', ' ' ), $tax_name );
					$cands = array(
						(string) $tax->attribute_name,
						urldecode( (string) $tax->attribute_name ),
						isset( $tax->attribute_label ) ? (string) $tax->attribute_label : '',
						$tax_name,
						$tax_slug_readable,
					);
					$hit = false;
					foreach ( $cands as $c ) {
						if ( '' === $c ) {
							continue;
						}
						if ( self::normalize_persian( $c ) === $norm ) {
							$hit = true;
							break;
						}
						if ( sanitize_title( $c ) === sanitize_title( $label ) ) {
							$hit = true;
							break;
						}
					}
					if ( $hit ) {
						$matches['taxonomies'][] = array(
							'key'          => $tax_name,
							'attribute_id' => isset( $tax->attribute_id ) ? (int) $tax->attribute_id : 0,
							'label'        => ! empty( $tax->attribute_label ) ? $tax->attribute_label : $tax->attribute_name,
						);
					}
				}
			}

			// اگر خود ورودی مستقیماً نام یک تاکسونومی ویژگی معتبر باشد
			if ( empty( $matches['taxonomies'] ) && taxonomy_exists( $label ) && 0 === strpos( $label, 'pa_' ) ) {
				$matches['taxonomies'][] = array(
					'key'          => $label,
					'attribute_id' => 0,
					'label'        => $label,
				);
			}

			return $matches;
		}

		/**
		 * یافتن همهٔ محصولاتی که یک ویژگی مشخص را دارند (هم از مسیر ترم‌ها و هم متا).
		 *
		 * @param array $matches خروجی resolve_attribute_globally.
		 * @return array لیست یکتای شناسه‌های محصولات.
		 */
		public static function query_products_with_attribute( array $matches ) {
			global $wpdb;

			$tax_keys = array();
			foreach ( (array) $matches['taxonomies'] as $t ) {
				if ( ! empty( $t['key'] ) ) {
					$tax_keys[] = (string) $t['key'];
				}
			}
			$local = isset( $matches['local_name'] ) ? trim( (string) $matches['local_name'] ) : '';

			$ids = array();

			if ( ! empty( $tax_keys ) ) {
				$ph = implode( ',', array_fill( 0, count( $tax_keys ), '%s' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sql  = $wpdb->prepare(
					"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
					 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					 WHERE p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft') AND tt.taxonomy IN ($ph)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$tax_keys
				);
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$ids = array_merge( $ids, (array) $wpdb->get_col( $sql ) );

				// محصولاتی که ویژگی در متا دارند ولی ترمی (دیگر) به آن‌ها متصل نیست
				foreach ( $tax_keys as $tk ) {
					$like = '%' . $wpdb->esc_like( '"' . $tk . '"' ) . '%';
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$ids = array_merge(
						$ids,
						(array) $wpdb->get_col(
							$wpdb->prepare(
								"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
								 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
								 WHERE p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft')
								   AND pm.meta_key = '_product_attributes' AND pm.meta_value LIKE %s",
								$like
							)
						)
					);
				}
			}

			if ( '' !== $local ) {
				// الگوی سریالایز: "name";s:N:"<عنوان>"
				$pattern = 's:[0-9]+:"name";s:[0-9]+:"' . preg_quote( $local, '/' ) . '"';
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$ids = array_merge(
					$ids,
					(array) $wpdb->get_col(
						$wpdb->prepare(
							"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
							 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
							 WHERE p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft')
							   AND pm.meta_key = '_product_attributes' AND pm.meta_value REGEXP %s",
							$pattern
						)
					)
				);
			}

			$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
			return $ids;
		}

		/**
		 * شمارش مختصر مصرف یک ویژگی: تعداد متغیرهای وابسته (دارای مقدار صریح)
		 * و تعداد ترم‌های متصل‌شده به هر محصول.
		 *
		 * @param array $product_ids شناسه‌های محصول.
		 * @param array $matches     خروجی resolve_attribute_globally.
		 * @return array نگاشت pid => ['linked_vars'=>int, 'terms'=>int].
		 */
		public static function attribute_usage_summary( array $product_ids, array $matches ) {
			global $wpdb;
			$summary = array();
			if ( empty( $product_ids ) ) {
				return $summary;
			}

			$tax_keys = array();
			foreach ( (array) $matches['taxonomies'] as $t ) {
				if ( ! empty( $t['key'] ) ) {
					$tax_keys[] = (string) $t['key'];
				}
			}
			$local = isset( $matches['local_name'] ) ? trim( (string) $matches['local_name'] ) : '';

			$variation_meta_keys = array();
			foreach ( $tax_keys as $tk ) {
				$variation_meta_keys[] = 'attribute_' . $tk;
			}
			if ( '' !== $local ) {
				$variation_meta_keys[] = 'attribute_' . sanitize_title( $local );
			}
			$variation_meta_keys = array_values( array_unique( $variation_meta_keys ) );

			$chunks = array_chunk( $product_ids, 400 );

			if ( ! empty( $variation_meta_keys ) ) {
				foreach ( $chunks as $chunk ) {
					$ph_ids  = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
					$ph_keys = implode( ',', array_fill( 0, count( $variation_meta_keys ), '%s' ) );
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$rows = (array) $wpdb->get_results(
						$wpdb->prepare(
							"SELECT p.post_parent AS pid, COUNT(*) AS c FROM {$wpdb->posts} p
							 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
							 WHERE p.post_type = 'product_variation' AND pm.meta_key IN ($ph_keys)
							   AND pm.meta_value != '' AND p.post_parent IN ($ph_ids)
							 GROUP BY p.post_parent", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							array_merge( $variation_meta_keys, $chunk )
						)
					);
					foreach ( $rows as $row ) {
						$pid = (int) $row->pid;
						if ( ! isset( $summary[ $pid ] ) ) {
							$summary[ $pid ] = array( 'linked_vars' => 0, 'terms' => 0 );
						}
						$summary[ $pid ]['linked_vars'] += (int) $row->c;
					}
				}
			}

			if ( ! empty( $tax_keys ) ) {
				foreach ( $chunks as $chunk ) {
					$ph_ids = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
					$ph_tax = implode( ',', array_fill( 0, count( $tax_keys ), '%s' ) );
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$rows = (array) $wpdb->get_results(
						$wpdb->prepare(
							"SELECT tr.object_id AS pid, COUNT(DISTINCT tt.term_id) AS c FROM {$wpdb->term_relationships} tr
							 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
							 WHERE tt.taxonomy IN ($ph_tax) AND tr.object_id IN ($ph_ids)
							 GROUP BY tr.object_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							array_merge( $tax_keys, $chunk )
						)
					);
					foreach ( $rows as $row ) {
						$pid = (int) $row->pid;
						if ( ! isset( $summary[ $pid ] ) ) {
							$summary[ $pid ] = array( 'linked_vars' => 0, 'terms' => 0 );
						}
						$summary[ $pid ]['terms'] += (int) $row->c;
					}
				}
			}

			return $summary;
		}

		/**
		 * دریافت نقشه سبک id => post_title برای لیست نتایج.
		 */
		public static function get_products_name_map( array $ids ) {
			global $wpdb;
			$map = array();
			if ( empty( $ids ) ) {
				return $map;
			}
			foreach ( array_chunk( $ids, 500 ) as $chunk ) {
				$ph = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows = (array) $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($ph)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$chunk
					)
				);
				foreach ( $rows as $row ) {
					$map[ (int) $row->ID ] = (string) $row->post_title;
				}
			}
			return $map;
		}

		/**
		 * دریافت اطلاعات کامل یک لیست از محصولات برای نمایش جدول زنده در فرانت‌اند.
		 */
		public static function get_products_summary( array $ids, $limit = 50, $offset = 0 ) {
			if ( empty( $ids ) ) {
				return array(
					'total' => 0,
					'items' => array(),
				);
			}

			$limit      = min( 50, max( 1, absint( $limit ) ) );
			$offset     = max( 0, absint( $offset ) );
			$total      = count( $ids );
			$slice_ids  = array_slice( $ids, $offset, $limit );
			$items      = array();
			$variation_counts = self::get_variation_counts( $slice_ids );

			// پرایم گروهی کش پست/متا/ترم تا صفحه‌های بزرگ (۳۰۰تایی) هم با چند کوئری
			// لود شوند و به سقف زمان سرور نرسند.
			if ( function_exists( '_prime_post_caches' ) && ! empty( $slice_ids ) ) {
				_prime_post_caches( $slice_ids );
			}

			foreach ( $slice_ids as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					continue;
				}

				// استخراج دسته‌بندی‌ها
				$cats = wc_get_product_category_list( $pid, '، ' );

				// استخراج مدل‌های فعلی و تعداد متغیرها
				$variation_count = 0;
				$current_models  = array();

				if ( $product->is_type( 'variable' ) ) {
					$variation_count = isset( $variation_counts[ $pid ] ) ? (int) $variation_counts[ $pid ] : 0;

					// خواندن نام مدل‌ها از ویژگی‌ها
					$attributes = $product->get_attributes();
					foreach ( $attributes as $attr ) {
						if ( $attr->get_variation() ) {
							if ( $attr->is_taxonomy() ) {
								$terms = wc_get_product_terms( $pid, $attr->get_name(), array( 'fields' => 'names' ) );
								$current_models = array_merge( $current_models, $terms );
							} else {
								$current_models = array_merge( $current_models, (array) $attr->get_options() );
							}
						}
					}
				}

				// تصویر شاخص
				$img_id  = $product->get_image_id();
				$img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : wc_placeholder_img_src();

				$items[] = array(
					'id'              => $pid,
					'name'            => $product->get_name(),
					'sku'             => $product->get_sku() ? $product->get_sku() : '—',
					'type'            => $product->get_type(),
					'status'          => $product->get_status(),
					'cats'            => wp_strip_all_tags( $cats ),
					'variation_count' => $variation_count,
					'models'          => array_slice( array_unique( $current_models ), 0, 15 ),
					'models_total'    => count( array_unique( $current_models ) ),
					'image_url'       => $img_url,
					'edit_url'        => get_edit_post_link( $pid, '' ),
					'view_url'        => $product->get_permalink(),
				);
			}

			return array(
				'total' => $total,
				'items' => $items,
			);
		}

		/**
		 * دریافت فهرست تمام دسته‌بندی‌های محصولات جهت انتخاب در فرم فیلتر.
		 */
		public static function get_all_product_categories() {
			$terms = get_terms( array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			) );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				return array();
			}

			$list = array();
			foreach ( $terms as $t ) {
				$list[] = array(
					'id'     => $t->term_id,
					'name'   => $t->name,
					'count'  => $t->count,
					'parent' => $t->parent,
				);
			}
			return $list;
		}

		/**
		 * خواندن گروهی variationها و ویژگی‌هایشان برای چند محصول با حداقل کوئری.
		 *
		 * خروجی دقیقاً معادل حلقهٔ «get_children + wc_get_product + get_attributes» است،
		 * با این تفاوت که به‌جای هزاران لود آبجکت، فقط کوئری‌های گروهی اجرا می‌شود؛
		 * همین باعث می‌شود پیش‌نمایش روی صدها محصول هم در چند ثانیه تمام شود.
		 * کلید ویژگی‌ها بدون پیشوند attribute_ و مقادیر خالی («هر مقدار») حفظ می‌شوند؛
		 * فیلتر وضعیت هم عین get_children ووکامرس است (فقط publish و private).
		 *
		 * @param array $product_ids شناسه‌های محصول والد.
		 * @return array نگاشت pid => array( 'children' => [vid...], 'attrs' => [vid => [key => value]] ).
		 */
		public static function get_variations_bulk( array $product_ids ) {
			global $wpdb;
			$map         = array();
			$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
			if ( empty( $product_ids ) ) {
				return $map;
			}
			$variation_counts = self::get_variation_counts( $product_ids );
			$eligible_ids = array();
			foreach ( $product_ids as $pid ) {
				$too_many = isset( $variation_counts[ $pid ] ) && $variation_counts[ $pid ] > self::MAX_VARIATIONS_PER_PRODUCT;
				$map[ $pid ] = array( 'children' => array(), 'attrs' => array(), 'too_many' => $too_many, 'variation_count' => isset( $variation_counts[ $pid ] ) ? (int) $variation_counts[ $pid ] : 0 );
				if ( ! $too_many ) $eligible_ids[] = $pid;
			}
			if ( ! $eligible_ids ) return $map;

			// ۱) فقط برای محصولات زیر سقف، شناسه‌های variation خوانده می‌شود.
			$variation_ids = array();
			foreach ( array_chunk( $eligible_ids, 400 ) as $chunk ) {
				$ph = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows = (array) $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_status IN ('publish','private') AND post_parent IN ($ph) ORDER BY post_parent ASC, menu_order ASC, ID ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$chunk
					)
				);
				foreach ( $rows as $row ) {
					$vid = (int) $row->ID;
					$pid = (int) $row->post_parent;
					if ( isset( $map[ $pid ] ) ) {
						$map[ $pid ]['children'][]    = $vid;
						$map[ $pid ]['attrs'][ $vid ] = array();
						$variation_ids[]              = $vid;
					}
				}
			}

			// ۲) متاهای attribute_* همهٔ variationها در چند کوئری گروهی.
			$variation_ids = array_values( array_unique( $variation_ids ) );
			if ( empty( $variation_ids ) ) {
				return $map;
			}
			$parent_of = array();
			foreach ( $map as $pid => $entry ) {
				foreach ( $entry['children'] as $vid ) {
					$parent_of[ $vid ] = $pid;
				}
			}
			foreach ( array_chunk( $variation_ids, 2000 ) as $vchunk ) {
				$ph = implode( ',', array_fill( 0, count( $vchunk ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows = (array) $wpdb->get_results(
					$wpdb->prepare(
						"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($ph) AND meta_key LIKE 'attribute\\_%' ORDER BY meta_id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$vchunk
					)
				);
				foreach ( $rows as $row ) {
					$vid = (int) $row->post_id;
					if ( ! isset( $parent_of[ $vid ] ) ) {
						continue;
					}
					$key = substr( (string) $row->meta_key, 10 ); // حذف پیشوند attribute_
					if ( '' === $key ) {
						continue;
					}
					$pid = $parent_of[ $vid ];
					// اگر کلید تکراری بود، قدیمی‌ترین سطر معیار است؛ مشابه get_post_meta تکی.
					if ( ! array_key_exists( $key, $map[ $pid ]['attrs'][ $vid ] ) ) {
						$map[ $pid ]['attrs'][ $vid ][ $key ] = isset( $row->meta_value ) ? (string) $row->meta_value : '';
					}
				}
			}

			return $map;
		}

		/**
		 * دریافت لیست تمام تاکسونومی‌های ویژگی عمومی ووکامرس (مثل pa_model).
		 */
		public static function get_attribute_taxonomies() {
			$taxonomies = wc_get_attribute_taxonomies();
			$res        = array();
			if ( ! empty( $taxonomies ) ) {
				foreach ( $taxonomies as $tax ) {
					$res[] = array(
						'name'  => wc_attribute_taxonomy_name( $tax->attribute_name ),
						'label' => $tax->attribute_label ? $tax->attribute_label : $tax->attribute_name,
					);
				}
			}
			return $res;
		}
	}
}
