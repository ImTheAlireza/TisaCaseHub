<?php
/**
 * لایهٔ داده‌ها: جستجو، فیلتر چندلایه، نرمال‌سازی متن فارسی و استخراج ویژگی‌ها و متغیرها.
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBVM_DB' ) ) {

	final class TCBVM_DB {

		/**
		 * نرمال‌سازی کاراکترهای فارسی و عربی (ی، ک، نیم‌فاصله).
		 */
		public static function normalize_persian( $str ) {
			if ( ! is_string( $str ) || '' === $str ) {
				return '';
			}
			$arabic_chars  = array( 'ي', 'ك', 'ة', 'ۀ', "\xC2\xA0", "\xE2\x80\x8C" );
			$persian_chars = array( 'ی', 'ک', 'ه', 'ه', ' ', ' ' );
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
			$mode = isset( $filters['mode'] ) ? sanitize_key( $filters['mode'] ) : 'filters';

			// اگر کاربر شناسه‌ها را به صورت مستقیم وارد کرده باشد
			if ( 'manual' === $mode || ! empty( $filters['manual_ids'] ) ) {
				$raw_ids = is_array( $filters['manual_ids'] )
					? $filters['manual_ids']
					: explode( ',', (string) $filters['manual_ids'] );
				$ids = array_values( array_filter( array_map( 'absint', $raw_ids ) ) );
				if ( ! empty( $ids ) ) {
					return self::filter_valid_products( $ids, $filters );
				}
			}

			// اگر فیلتر اختصاصی بر اساس شناسه / کد محصول (SKU) باشد
			if ( 'sku' === $mode && ! empty( $filters['sku'] ) ) {
				$sku_mode = isset( $filters['sku_mode'] ) ? sanitize_key( $filters['sku_mode'] ) : 'starts_with';
				$sku_ids  = self::query_product_ids_by_sku( $filters['sku'], $sku_mode );
				if ( empty( $sku_ids ) ) {
					return array();
				}
				return self::filter_valid_products( $sku_ids, $filters );
			}

			$post_types = array( 'product' );
			$post_statuses = isset( $filters['statuses'] ) && is_array( $filters['statuses'] )
				? array_map( 'sanitize_key', $filters['statuses'] )
				: array( 'publish', 'draft', 'private' );

			$tax_query = array( 'relation' => 'AND' );

			// فیلتر دسته‌بندی
			if ( ! empty( $filters['category_ids'] ) ) {
				$cat_ids = array_values( array_filter( array_map( 'absint', (array) $filters['category_ids'] ) ) );
				if ( ! empty( $cat_ids ) ) {
					$include_children = ! empty( $filters['include_children'] );
					$tax_query[] = array(
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => $cat_ids,
						'include_children' => $include_children,
						'operator'         => 'IN',
					);
				}
			}

			// فیلتر نوع محصول (به طور پیش‌فرض متغیر و ساده)
			$product_types = isset( $filters['product_types'] ) && is_array( $filters['product_types'] )
				? array_map( 'sanitize_key', $filters['product_types'] )
				: array( 'variable', 'simple' );

			if ( ! empty( $product_types ) && ! in_array( 'all', $product_types, true ) ) {
				$tax_query[] = array(
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => $product_types,
					'operator' => 'IN',
				);
			}

			$query_args = array(
				'post_type'              => $post_types,
				'post_status'            => $post_statuses,
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			if ( count( $tax_query ) > 1 ) {
				$query_args['tax_query'] = $tax_query;
			}

			$wp_query = new WP_Query( $query_args );
			$raw_ids  = array_map( 'absint', (array) $wp_query->posts );

			if ( empty( $raw_ids ) ) {
				return array();
			}

			// اگر فیلتر SKU در کنار سایر فیلترها تعیین شده بود
			if ( ! empty( $filters['sku'] ) ) {
				$sku_mode = isset( $filters['sku_mode'] ) ? sanitize_key( $filters['sku_mode'] ) : 'starts_with';
				$sku_ids  = self::query_product_ids_by_sku( $filters['sku'], $sku_mode );
				$raw_ids  = array_values( array_intersect( $raw_ids, $sku_ids ) );
				if ( empty( $raw_ids ) ) {
					return array();
				}
			}

			// اعمال فیلترهای کلمات کلیدی، استثناها و ویژگی‌ها
			return self::refine_product_ids( $raw_ids, $filters );
		}

		/**
		 * استخراج محصولات بر اساس پیشوند یا تطبیق شناسه (SKU).
		 * چه SKU روی خود محصول والد تنظیم شده باشد چه روی متغیرهای آن.
		 *
		 * @param string $sku_text متن یا پیشوند SKU (مثلاً CH).
		 * @param string $mode     حالت تطبیق: starts_with یا contains یا exact.
		 * @return array لیست شناسه‌های یکتا.
		 */
		public static function query_product_ids_by_sku( $sku_text, $mode = 'starts_with' ) {
			global $wpdb;
			$sku_text = trim( (string) $sku_text );
			if ( '' === $sku_text ) {
				return array();
			}

			if ( 'exact' === $mode ) {
				$sku_pattern = $sku_text;
				$operator    = '=';
			} elseif ( 'contains' === $mode ) {
				$sku_pattern = '%' . $wpdb->esc_like( $sku_text ) . '%';
				$operator    = 'LIKE';
			} else { // starts_with
				$sku_pattern = $wpdb->esc_like( $sku_text ) . '%';
				$operator    = 'LIKE';
			}

			$sql = "SELECT DISTINCT IF(p.post_type = 'product_variation', p.post_parent, p.ID) AS pid
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE p.post_type IN ('product', 'product_variation')
					  AND p.post_status NOT IN ('trash', 'auto-draft')
					  AND pm.meta_key = '_sku'
					  AND pm.meta_value {$operator} %s";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ids = $wpdb->get_col( $wpdb->prepare( $sql, $sku_pattern ) );
			return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
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

			$total      = count( $ids );
			$slice_ids  = array_slice( $ids, $offset, $limit );
			$items      = array();

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
					$variation_ids = $product->get_children();
					$variation_count = count( $variation_ids );

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
			foreach ( $product_ids as $pid ) {
				$map[ $pid ] = array( 'children' => array(), 'attrs' => array() );
			}

			// ۱) شناسهٔ variationهای هر محصول.
			$variation_ids = array();
			foreach ( array_chunk( $product_ids, 400 ) as $chunk ) {
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
