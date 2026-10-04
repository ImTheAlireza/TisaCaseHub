<?php
/**
 * موتور اصلی عملیات متغیرها: حل ویژگی، ساخت ترم‌ها، ضرب دکارتی ترکیب‌ها،
 * پاکسازی کامل ویژگی‌های قبلی و بازسازی تمیز متغیرها با قیمت یکپارچه.
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBVM_OPS' ) ) {

	final class TCBVM_OPS {

		const MAX_VARIATIONS_PER_PRODUCT = 3000;
		const MODE_REPLACE_ALL            = 'replace_all';
		const MODE_ADD_MISSING            = 'add_missing';
		const MODE_REMOVE_VALUES          = 'remove_values';

		/**
		 * اطمینان از ثبت بودن تمامی تاکسونومی‌های ویژگی ووکامرس در درخواست‌های ایجکس.
		 */
		public static function ensure_all_attribute_taxonomies_registered() {
			if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
				$taxonomies = wc_get_attribute_taxonomies();
				if ( ! empty( $taxonomies ) ) {
					foreach ( $taxonomies as $tax ) {
						$tax_name = wc_attribute_taxonomy_name( $tax->attribute_name );
						if ( ! taxonomy_exists( $tax_name ) ) {
							register_taxonomy(
								$tax_name,
								apply_filters( 'woocommerce_taxonomy_objects_' . $tax_name, array( 'product' ) ),
								apply_filters( 'woocommerce_taxonomy_args_' . $tax_name, array(
									'hierarchical' => false,
									'show_ui'      => false,
									'query_var'    => true,
									'rewrite'      => false,
									'label'        => ! empty( $tax->attribute_label ) ? $tax->attribute_label : $tax->attribute_name,
								) )
							);
						}
					}
				}
			}
		}

		/**
		 * حل دقیق ویژگی هدف روی خود محصول و شناسایی ویژگی‌های تکراری جهت پاکسازی قطعی.
		 *
		 * @param WC_Product $product         محصول هدف.
		 * @param string     $user_attr_label نام ویژگی واردشده توسط کاربر (مثلاً «مدل» یا «مدل گوشی»).
		 * @return array اطلاعات کلید، نوع تاکسونومی و کلیدهای تکراری که باید از محصول حذف شوند.
		 */
		public static function resolve_product_target_attribute( $product, $user_attr_label, $create_if_missing = true ) {
			self::ensure_all_attribute_taxonomies_registered();

			$user_attr_label = trim( (string) $user_attr_label );
			if ( '' === $user_attr_label ) {
				$user_attr_label = 'مدل گوشی';
			}
			$norm_target = TCBVM_DB::normalize_persian( $user_attr_label );

			$existing_attributes = $product instanceof WC_Product ? $product->get_attributes() : array();
			$matched_key         = null;
			$matched_is_taxonomy = false;
			$keys_to_remove      = array();

			// ۱) بررسی ویژگی‌های فعلی موجود روی خود این محصول
			foreach ( $existing_attributes as $key => $attr_obj ) {
				$attr_name  = $attr_obj instanceof WC_Product_Attribute ? $attr_obj->get_name() : $key;
				$attr_label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $attr_name, $product ) : $attr_name;

				$is_match = false;
				if ( TCBVM_DB::normalize_persian( $attr_label ) === $norm_target ) {
					$is_match = true;
				} elseif ( TCBVM_DB::normalize_persian( $attr_name ) === $norm_target ) {
					$is_match = true;
				} elseif ( TCBVM_DB::normalize_persian( str_replace( 'pa_', '', $attr_name ) ) === $norm_target ) {
					$is_match = true;
				} elseif ( sanitize_title( $attr_label ) === sanitize_title( $user_attr_label ) ) {
					$is_match = true;
				} elseif ( sanitize_title( $attr_name ) === sanitize_title( $user_attr_label ) ) {
					$is_match = true;
				} elseif ( 'pa_' . sanitize_title( $user_attr_label ) === $attr_name ) {
					$is_match = true;
				}

				if ( $is_match ) {
					$is_tax = ( $attr_obj instanceof WC_Product_Attribute && $attr_obj->is_taxonomy() ) || taxonomy_exists( $attr_name );
					if ( null === $matched_key ) {
						$matched_key         = $attr_name;
						$matched_is_taxonomy = $is_tax;
					} else {
						// اگر چند ویژگی با همین نام بود، اولویت قطعی با تاکسونومی سراسری است
						if ( $is_tax && ! $matched_is_taxonomy ) {
							$keys_to_remove[]    = $matched_key;
							$matched_key         = $attr_name;
							$matched_is_taxonomy = true;
						} else {
							$keys_to_remove[] = $attr_name;
						}
					}
				}
			}

			// ۲) اگر روی محصول نبود، بررسی ویژگی‌های عمومی تعریف‌شده در ووکامرس
			if ( null === $matched_key && function_exists( 'wc_get_attribute_taxonomies' ) ) {
				foreach ( (array) wc_get_attribute_taxonomies() as $wc_attr ) {
					if ( empty( $wc_attr->attribute_name ) ) {
						continue;
					}
					$tax_name   = wc_attribute_taxonomy_name( $wc_attr->attribute_name );
					$candidates = array(
						$wc_attr->attribute_name,
						isset( $wc_attr->attribute_label ) ? $wc_attr->attribute_label : '',
						urldecode( $wc_attr->attribute_name ),
					);
					foreach ( $candidates as $cand ) {
						if ( '' !== $cand && TCBVM_DB::normalize_persian( $cand ) === $norm_target ) {
							$matched_key         = $tax_name;
							$matched_is_taxonomy = true;
							break 2;
						}
					}
				}
			}

			// ۳) بررسی اسلاگ متعارف تاکسونومی
			if ( null === $matched_key ) {
				$guess = wc_attribute_taxonomy_name( sanitize_title( $user_attr_label ) );
				if ( taxonomy_exists( $guess ) ) {
					$matched_key         = $guess;
					$matched_is_taxonomy = true;
				} elseif ( taxonomy_exists( $user_attr_label ) ) {
					$matched_key         = $user_attr_label;
					$matched_is_taxonomy = true;
				}
			}

			// ۴) ویژگی ناشناخته فقط پس از پیش‌بررسی سقف، در صورت اجازه ساخته شود.
			if ( null === $matched_key ) {
				$slug = sanitize_title( $user_attr_label );
				if ( empty( $slug ) ) {
					$slug = 'attr_' . time();
				}
				$tax_name = wc_attribute_taxonomy_name( $slug );

				if ( $create_if_missing && function_exists( 'wc_create_attribute' ) ) {
					$created = wc_create_attribute( array(
						'name'         => $user_attr_label,
						'slug'         => $slug,
						'type'         => 'select',
						'order_by'     => 'menu_order',
						'has_archives' => false,
					) );
					if ( is_wp_error( $created ) || ! absint( $created ) ) {
						$existing_id = function_exists( 'wc_attribute_taxonomy_id_by_name' ) ? wc_attribute_taxonomy_id_by_name( $slug ) : 0;
						if ( ! $existing_id ) {
							$error_message = is_wp_error( $created ) ? $created->get_error_message() : 'شناسهٔ ویژگی سراسری ایجاد نشد.';
							return array( 'name' => $user_attr_label, 'is_taxonomy' => false, 'keys_to_remove' => array(), 'error' => $error_message );
						}
					}

					register_taxonomy(
						$tax_name,
						apply_filters( 'woocommerce_taxonomy_objects_' . $tax_name, array( 'product' ) ),
						apply_filters( 'woocommerce_taxonomy_args_' . $tax_name, array(
							'hierarchical' => false,
							'show_ui'      => false,
							'query_var'    => true,
							'rewrite'      => false,
						) )
					);
					$matched_key         = $tax_name;
					$matched_is_taxonomy = true;
				} else {
					$matched_key         = $user_attr_label;
					$matched_is_taxonomy = false;
				}
			}

			return array(
				'name'           => $matched_key,
				'is_taxonomy'    => $matched_is_taxonomy,
				'keys_to_remove' => array_values( array_unique( $keys_to_remove ) ),
			);
		}

		/**
		 * اطمینان از وجود یک ترم (مقدار ویژگی) در تاکسونومی و بازگرداندن آبجکت آن.
		 *
		 * @param string $name نام مقدار (مثلاً «iPhone 15 Pro Max»).
		 * @param string $taxonomy تاکسونومی هدف.
		 * @return WP_Term|null
		 */
		public static function ensure_term_exists( $name, $taxonomy ) {
			$name = trim( (string) $name );
			if ( '' === $name || ! taxonomy_exists( $taxonomy ) ) {
				return null;
			}

			// ۱) جستجو بر اساس نام دقیق
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}

			// ۲) جستجو با تطبیق نرمال‌شده فارسی
			$norm_name = TCBVM_DB::normalize_persian( $name );
			$all_terms = get_terms( array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			) );

			if ( ! is_wp_error( $all_terms ) && is_array( $all_terms ) ) {
				foreach ( $all_terms as $t ) {
					if ( TCBVM_DB::normalize_persian( $t->name ) === $norm_name ) {
						return $t;
					}
				}
			}

			// ۳) جستجو با اسلاگ
			$slug = sanitize_title( $name );
			if ( '' !== $slug ) {
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term;
				}
			}

			// ۴) در صورت نبودن، ترم در این تاکسونومی ساخته شود
			$args = array();
			if ( '' !== $slug ) {
				$args['slug'] = $slug;
			}

			$inserted = wp_insert_term( $name, $taxonomy, $args );
			if ( ! is_wp_error( $inserted ) && isset( $inserted['term_id'] ) ) {
				return get_term( (int) $inserted['term_id'], $taxonomy );
			}

			if ( is_wp_error( $inserted ) && isset( $inserted->error_data['term_exists'] ) ) {
				return get_term( (int) $inserted->error_data['term_exists'], $taxonomy );
			}

			return null;
		}

		/**
		 * محاسبه ضرب دکارتی تمام ترکیب‌ها برای ویژگی‌های متغیر.
		 *
		 * @param array $input آرایه کلید-مقدار از نام ویژگی به آرایه اسلاگ‌ها/مقادیر.
		 * @return array لیست ترکیب‌ها.
		 */
		public static function cartesian_product( array $input ) {
			$count = self::cartesian_product_count( $input, self::MAX_VARIATIONS_PER_PRODUCT );
			if ( $count > self::MAX_VARIATIONS_PER_PRODUCT ) {
				throw new RuntimeException( 'تعداد ترکیب‌ها از سقف مجاز عبور می‌کند؛ ماتریس ساخته نشد.' );
			}

			$result = array( array() );
			foreach ( $input as $key => $values ) {
				$append = array();
				foreach ( $result as $combination ) {
					foreach ( (array) $values as $value ) {
						$combination[ $key ] = $value;
						$append[]            = $combination;
					}
				}
				$result = $append;
			}
			return $result;
		}

		/**
		 * شمارش محدودشدهٔ ترکیب‌ها؛ ۳۰۰۱ به معنی «بیش از سقف» است.
		 * از ضرب مستقیم استفاده نمی‌شود تا قبل از ساخت ماتریس، overflow رخ ندهد.
		 *
		 * @param array $input آرایهٔ ویژگی‌ها و گزینه‌ها.
		 * @param int   $limit سقف شمارش.
		 * @return int تعداد دقیق تا سقف، یا limit + 1.
		 */
		public static function cartesian_product_count( array $input, $limit = self::MAX_VARIATIONS_PER_PRODUCT ) {
			$counts = array();
			foreach ( $input as $values ) {
				$counts[] = count( (array) $values );
			}
			return self::bounded_product_of_counts( $counts, $limit );
		}

		private static function bounded_product_of_counts( array $counts, $limit = self::MAX_VARIATIONS_PER_PRODUCT ) {
			$limit      = max( 1, absint( $limit ) );
			$over_limit = $limit < PHP_INT_MAX ? $limit + 1 : PHP_INT_MAX;
			$count      = 1;
			foreach ( $counts as $option_count ) {
				$option_count = absint( $option_count );
				if ( 0 === $option_count ) {
					return 0;
				}
				if ( $option_count > $limit || $count > intdiv( $limit, $option_count ) ) {
					return $over_limit;
				}
				$count *= $option_count;
				if ( $count > $limit ) {
					return $over_limit;
				}
			}
			return $count;
		}

		public static function sanitize_operation_mode( $mode ) {
			$mode = sanitize_key( (string) $mode );
			return in_array( $mode, array( self::MODE_REPLACE_ALL, self::MODE_ADD_MISSING, self::MODE_REMOVE_VALUES ), true ) ? $mode : self::MODE_REPLACE_ALL;
		}

		public static function operation_mode_label( $mode ) {
			$mode = self::sanitize_operation_mode( $mode );
			$labels = array(
				self::MODE_REPLACE_ALL   => 'جایگزینی کامل',
				self::MODE_ADD_MISSING   => 'افزودن ترکیب‌های جدید',
				self::MODE_REMOVE_VALUES => 'حذف مقادیر واردشده',
			);
			return $labels[ $mode ];
		}

		private static function attribute_key_aliases( $key ) {
			$key     = preg_replace( '/^attribute_/', '', (string) $key );
			$slug    = sanitize_title( $key );
			$aliases = array( $slug );
			if ( 0 === strpos( $slug, 'pa_' ) ) {
				$aliases[] = substr( $slug, 3 );
			} elseif ( '' !== $slug ) {
				$aliases[] = 'pa_' . $slug;
			}
			return array_values( array_unique( array_filter( $aliases ) ) );
		}

		private static function find_variation_attribute_value( array $attributes, $target_key ) {
			$target_aliases = self::attribute_key_aliases( $target_key );
			$matches        = array();
			foreach ( $attributes as $key => $value ) {
				if ( array_intersect( $target_aliases, self::attribute_key_aliases( $key ) ) ) {
					$matches[] = $value;
				}
			}
			if ( count( $matches ) > 1 ) {
				return array( 'found' => true, 'ambiguous' => true, 'value' => '' );
			}
			return array(
				'found'     => ! empty( $matches ),
				'ambiguous' => false,
				'value'     => empty( $matches ) ? '' : reset( $matches ),
			);
		}

		private static function normalize_variation_value( $value ) {
			if ( ! is_scalar( $value ) ) {
				return '';
			}
			$value = TCBVM_DB::normalize_persian( trim( (string) $value ) );
			$slug  = sanitize_title( $value );
			return '' !== $slug ? strtolower( $slug ) : strtolower( $value );
		}

		private static function combination_signature( array $attributes, array $keys ) {
			$normalized = array();
			foreach ( $keys as $key ) {
				$value = self::find_variation_attribute_value( $attributes, $key );
				if ( empty( $value['found'] ) || ! empty( $value['ambiguous'] ) ) {
					return false;
				}
				$normalized[ sanitize_title( preg_replace( '/^attribute_/', '', (string) $key ) ) ] = self::normalize_variation_value( $value['value'] );
			}
			ksort( $normalized );
			return hash( 'sha256', serialize( $normalized ) );
		}

		private static function find_existing_term_for_value( $value, $taxonomy ) {
			$term = get_term_by( 'name', (string) $value, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
			$slug = sanitize_title( (string) $value );
			if ( '' !== $slug ) {
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term;
				}
			}
			$normalized = TCBVM_DB::normalize_persian( (string) $value );
			$terms      = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
			if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
				foreach ( $terms as $candidate ) {
					if ( TCBVM_DB::normalize_persian( (string) $candidate->name ) === $normalized ) {
						return $candidate;
					}
				}
			}
			return false;
		}

		private static function comparison_values( array $values, $is_taxonomy, $taxonomy, $allow_missing_terms = false ) {
			$resolved = array();
			foreach ( $values as $value ) {
				if ( $is_taxonomy ) {
					$term = taxonomy_exists( $taxonomy ) ? self::find_existing_term_for_value( $value, $taxonomy ) : false;
					if ( $term ) {
						$resolved[] = self::normalize_variation_value( $term->slug );
					} elseif ( $allow_missing_terms ) {
						$resolved[] = self::normalize_variation_value( sanitize_title( (string) $value ) );
					}
				} else {
					$resolved[] = self::normalize_variation_value( $value );
				}
			}
			return array_values( array_unique( array_filter( $resolved ) ) );
		}

		private static function target_matrix_values( array $values, $is_taxonomy, $taxonomy ) {
			$matrix_values = array();
			foreach ( $values as $value ) {
				if ( $is_taxonomy ) {
					$term = taxonomy_exists( $taxonomy ) ? self::find_existing_term_for_value( $value, $taxonomy ) : false;
					$matrix_values[] = $term ? (string) $term->slug : sanitize_title( (string) $value );
				} else {
					$matrix_values[] = $value;
				}
			}
			return array_values( array_unique( array_filter( $matrix_values, 'strlen' ) ) );
		}

		private static function count_missing_combinations( $product, array $matrix ) {
			$candidate_count = self::cartesian_product_count( $matrix, self::MAX_VARIATIONS_PER_PRODUCT );
			if ( $candidate_count > self::MAX_VARIATIONS_PER_PRODUCT ) {
				return array( 'count' => 0, 'error' => sprintf( 'تعداد ترکیب‌ها از سقف %d بیشتر است.', self::MAX_VARIATIONS_PER_PRODUCT ) );
			}
			$combinations = self::cartesian_product( $matrix );
			$keys         = array_keys( $matrix );
			$existing     = array();
			if ( $product && $product->is_type( 'variable' ) ) {
				foreach ( (array) $product->get_children() as $child_id ) {
					$child = wc_get_product( $child_id );
					if ( ! $child || ! $child->is_type( 'variation' ) || absint( $child->get_parent_id() ) !== absint( $product->get_id() ) ) {
						continue;
					}
					$attributes = (array) $child->get_attributes();
					foreach ( $keys as $key ) {
						$value = self::find_variation_attribute_value( $attributes, $key );
						if ( ! empty( $value['ambiguous'] ) || empty( $value['found'] ) || '' === (string) $value['value'] ) {
							return array( 'count' => 0, 'error' => sprintf( 'variation #%d ترکیب کامل و یکتایی ندارد؛ افزودن امن ممکن نیست.', absint( $child_id ) ) );
						}
					}
					$signature = self::combination_signature( $attributes, $keys );
					if ( false === $signature ) {
						return array( 'count' => 0, 'error' => sprintf( 'ترکیب variation #%d قابل تطبیق نیست.', absint( $child_id ) ) );
					}
					$existing[ $signature ] = true;
				}
			}
			$missing = array();
			foreach ( $combinations as $combination ) {
				$signature = self::combination_signature( $combination, $keys );
				if ( false === $signature ) {
					return array( 'count' => 0, 'error' => 'یکی از ترکیب‌های درخواستی قابل تطبیق نیست.' );
				}
				if ( ! isset( $existing[ $signature ] ) ) {
					$missing[ $signature ] = true;
				}
			}
			return array( 'count' => count( $missing ), 'error' => '' );
		}

		private static function count_matching_variations( $product, $target_key, array $comparison_values ) {
			if ( ! $product || ! $product->is_type( 'variable' ) || empty( $comparison_values ) ) {
				return array( 'count' => 0, 'ambiguous' => false );
			}
			$count = 0;
			foreach ( (array) $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation || ! $variation->is_type( 'variation' ) || absint( $variation->get_parent_id() ) !== absint( $product->get_id() ) ) {
					continue;
				}
				$value = self::find_variation_attribute_value( (array) $variation->get_attributes(), $target_key );
				if ( ! empty( $value['ambiguous'] ) ) {
					return array( 'count' => $count, 'ambiguous' => true );
				}
				if ( ! empty( $value['found'] ) && in_array( self::normalize_variation_value( $value['value'] ), $comparison_values, true ) ) {
					$count++;
				}
			}
			return array( 'count' => $count, 'ambiguous' => false );
		}

		/**
		 * پاکسازی و تفکیک لیست مدل‌ها/متغیرها.
		 * پشتیبانی از خطوط جدید، خط عمودی | و حفظ کاما داخل نام مدل.
		 *
		 * @param string|array $raw ورودی متنی یا آرایه‌ای.
		 * @return array لیست مقادیر یکتا و مرتب.
		 */
		public static function sanitize_model_list( $raw ) {
			if ( is_array( $raw ) ) {
				$raw = implode( "\n", $raw );
			}
			$raw = (string) $raw;
			if ( '' === trim( $raw ) ) {
				return array();
			}

			if ( false !== strpos( $raw, "\n" ) || false !== strpos( $raw, "\r" ) || false !== strpos( $raw, '|' ) ) {
				$lines = preg_split( '/[\r\n|]+/', $raw );
			} else {
				$lines = preg_split( '/[,،]+/', $raw );
			}

			$clean = array();
			foreach ( $lines as $line ) {
				$line = trim( (string) $line );
				$line = trim( $line, "\"'`•- " );
				if ( '' !== $line && ! in_array( $line, $clean, true ) ) {
					$clean[] = $line;
				}
			}

			return array_values( $clean );
		}

		/**
		 * حذف کامل یک ویژگی از یک محصول همراه با متغیرهای وابسته به آن.
		 * ابتدا اسنپ‌شات کامل تهیه می‌شود تا عملیات از تاریخچه قابل Rollback باشد.
		 *
		 * @param int    $product_id شناسه محصول.
		 * @param string $run_id     شناسه نشست اجرا.
		 * @param array  $matches    خروجی TCBVM_DB::resolve_attribute_globally.
		 * @return array نتیجه عملیات (success, title, message, deleted).
		 */
		public static function purge_attribute_from_product( $product_id, $run_id, array $matches ) {
			if ( ! TCBVM_Backup::lock_run( $run_id ) ) {
				return array( 'success' => false, 'title' => "محصول #{$product_id}", 'message' => 'قفل اجرای هم‌زمان در دسترس نیست؛ هیچ تغییری اعمال نشد.', 'deleted' => 0 );
			}
			try {
				if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::run_is_active( $run_id ) ) {
					return array( 'success' => false, 'title' => "محصول #{$product_id}", 'message' => 'قفل اجرا یا نشست فعال نیست؛ هیچ تغییری اعمال نشد.', 'deleted' => 0 );
				}
				if ( ! TCBVM_Backup::lock_product( $product_id ) ) {
					return array( 'success' => false, 'title' => "محصول #{$product_id}", 'message' => 'قفل محصول در دسترس نیست؛ هیچ تغییری اعمال نشد.', 'deleted' => 0 );
				}
				try {
					if ( ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
						return array( 'success' => false, 'title' => "محصول #{$product_id}", 'message' => 'قفل محصول از دست رفت؛ هیچ تغییری ادامه پیدا نکرد.', 'deleted' => 0 );
					}
					return self::purge_attribute_from_product_locked( $product_id, $run_id, $matches );
				} finally {
					TCBVM_Backup::unlock_product( $product_id );
				}
			} finally {
				TCBVM_Backup::unlock_run( $run_id );
			}
		}

		private static function purge_attribute_from_product_locked( $product_id, $run_id, array $matches ) {
			// ۱) اسنپ‌شات کامل باید قبل از هرگونه تغییر با موفقیت ثبت شود.
			if ( ! TCBVM_Backup::snapshot_product( $run_id, $product_id ) ) {
				if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
					return array( 'success' => false, 'title' => "محصول #{$product_id}", 'message' => 'قفل اجرا/محصول از دست رفت؛ ثبت snapshot شکست خورد و پاکسازی متوقف شد.', 'deleted' => 0 );
				}
				return array( 'success' => false, 'title' => "محصول #{$product_id}", 'message' => 'ثبت snapshot ناموفق بود (احتمالاً اندازهٔ خام یا ذخیره‌شده از سقف ۶۴ MiB بیشتر است)؛ محصول بدون تغییر ماند.', 'deleted' => 0 );
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return array( 'success' => false, 'title' => "محصول #{$product_id}", 'message' => 'محصول در سیستم یافت نشد.', 'deleted' => 0 );
			}

			$title    = $product->get_name();
			$tax_keys = array();
			foreach ( (array) $matches['taxonomies'] as $t ) {
				if ( ! empty( $t['key'] ) ) {
					$tax_keys[] = (string) $t['key'];
				}
			}
			$local      = isset( $matches['local_name'] ) ? trim( (string) $matches['local_name'] ) : '';
			$local_norm = '' !== $local ? TCBVM_DB::normalize_persian( $local ) : '';

			// ۲) یافتن ویژگی‌های منطبق روی محصول (تاکسونومی یا محلی)
			$product_attributes = $product->get_attributes();
			$matched_attrs      = array(); // array_key => attr_name

			foreach ( $product_attributes as $k => $attr_obj ) {
				$aname = $attr_obj instanceof WC_Product_Attribute ? $attr_obj->get_name() : $k;
				$hit   = false;

				if ( in_array( $aname, $tax_keys, true ) || in_array( $k, $tax_keys, true ) ) {
					$hit = true;
				} elseif ( '' !== $local_norm && TCBVM_DB::normalize_persian( $aname ) === $local_norm ) {
					$hit = true;
				} elseif ( '' !== $local && sanitize_title( $aname ) === sanitize_title( $local ) ) {
					$hit = true;
				}

				if ( $hit ) {
					$matched_attrs[ $k ] = $aname;
				}
			}

			if ( empty( $matched_attrs ) ) {
				return array(
					'success' => true,
					'title'   => $title,
					'message' => 'ویژگی هدف روی این محصول نبود؛ بدون تغییر ماند.',
					'deleted' => 0,
				);
			}

			// ۳) کلیدهای متای متغیرها برای این ویژگی‌ها.
			$meta_keys = array();
			foreach ( $matched_attrs as $aname ) {
				$meta_keys[] = 'attribute_' . $aname;
				$st          = sanitize_title( $aname );
				if ( $st !== $aname ) {
					$meta_keys[] = 'attribute_' . $st;
				}
			}
			$meta_keys = array_values( array_unique( $meta_keys ) );

			// ۴) حذف/ویرایش variation فقط از طریق WooCommerce CRUD تا data store، cache و hookها اجرا شوند.
			$children     = (array) $product->get_children();
			$deleted_vars = 0;
			foreach ( $children as $child_index => $child_id ) {
				if ( 0 === ( $child_index % 10 ) && ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) ) {
					throw new RuntimeException( 'قفل اجرا/محصول از دست رفت؛ پاکسازی متوقف شد تا نوشتن هم‌زمان رخ ندهد.' );
				}
				$variation = wc_get_product( $child_id );
				if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
					continue;
				}
				if ( absint( $variation->get_parent_id() ) !== absint( $product_id ) ) {
					throw new RuntimeException( sprintf( 'variation #%d والد این محصول نیست؛ پاکسازی برای حفاظت از داده متوقف شد.', absint( $variation->get_id() ) ) );
				}

				$variation_attributes = (array) $variation->get_attributes();
				$has_explicit_value   = false;
				foreach ( $meta_keys as $meta_key ) {
					$attribute_name = 0 === strpos( $meta_key, 'attribute_' ) ? substr( $meta_key, 10 ) : $meta_key;
					$candidates     = array( $attribute_name, sanitize_title( $attribute_name ), $meta_key );
					$value          = null;
					foreach ( $candidates as $candidate_key ) {
						if ( array_key_exists( $candidate_key, $variation_attributes ) ) {
							$value = $variation_attributes[ $candidate_key ];
							break;
						}
					}
					if ( null === $value && metadata_exists( 'post', $variation->get_id(), $meta_key ) ) {
						$value = get_post_meta( $variation->get_id(), $meta_key, true );
					}
					if ( is_scalar( $value ) && '' !== (string) $value ) {
						$has_explicit_value = true;
						break;
					}
				}

				if ( $has_explicit_value ) {
					$variation_id = absint( $variation->get_id() );
					$variation->delete( true );
					clean_post_cache( $variation_id );
					if ( get_post( $variation_id ) ) {
						throw new RuntimeException( sprintf( 'حذف variation وابسته #%d از مسیر CRUD کامل نشد.', $variation_id ) );
					}
					$deleted_vars++;
					continue;
				}

				$changed_attributes = $variation_attributes;
				$changed           = false;
				foreach ( $meta_keys as $meta_key ) {
					$attribute_name = 0 === strpos( $meta_key, 'attribute_' ) ? substr( $meta_key, 10 ) : $meta_key;
					foreach ( array( $attribute_name, sanitize_title( $attribute_name ), $meta_key ) as $candidate_key ) {
						if ( array_key_exists( $candidate_key, $changed_attributes ) ) {
							unset( $changed_attributes[ $candidate_key ] );
							$changed = true;
						}
					}
					if ( metadata_exists( 'post', $variation->get_id(), $meta_key ) ) {
						$changed = true;
					}
				}
				if ( $changed ) {
					$variation->set_attributes( $changed_attributes );
					$variation->save();
					foreach ( $meta_keys as $meta_key ) {
						if ( metadata_exists( 'post', $variation->get_id(), $meta_key ) ) {
							throw new RuntimeException( sprintf( 'WooCommerce CRUD متای ویژگی %s را از variation #%d پاک نکرد.', $meta_key, $variation->get_id() ) );
						}
					}
				}
			}

			// ۵) برداشتن ویژگی از تعریف محصول و قطع اتصال ترم‌ها
			$new_attrs = array();
			foreach ( $product_attributes as $k => $attr_obj ) {
				if ( array_key_exists( $k, $matched_attrs ) ) {
					if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
						throw new RuntimeException( 'قفل اجرا/محصول پیش از پاکسازی رابطهٔ ویژگی از دست رفت.' );
					}
					$aname = $matched_attrs[ $k ];
					if ( taxonomy_exists( $aname ) ) {
						$terms_result = wp_set_object_terms( $product_id, array(), $aname, false );
					} elseif ( taxonomy_exists( $k ) ) {
						$terms_result = wp_set_object_terms( $product_id, array(), $k, false );
					} else {
						$terms_result = true;
					}
					if ( is_wp_error( $terms_result ) ) {
						throw new RuntimeException( $terms_result->get_error_message() );
					}
					if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
						throw new RuntimeException( 'قفل اجرا/محصول پس از پاکسازی رابطهٔ ویژگی از دست رفت.' );
					}
					continue;
				}
				$new_attrs[ $k ] = $attr_obj;
			}

			if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
				throw new RuntimeException( 'قفل اجرا/محصول از دست رفت؛ پاکسازی ویژگی متوقف شد.' );
			}
			$product->set_attributes( $new_attrs );
			$product->save();
			if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
				throw new RuntimeException( 'قفل اجرا/محصول پس از ذخیرهٔ ویژگی از دست رفت؛ عملیات متوقف شد.' );
			}

			if ( $product->is_type( 'variable' ) ) {
				WC_Product_Variable::sync( $product_id );
			}
			wc_delete_product_transients( $product_id );
			clean_post_cache( $product_id );

			return array(
				'success' => true,
				'title'   => $title,
				'message' => sprintf(
					'ویژگی «%s» از محصول برداشته شد و %d متغیر وابسته پاکسازی گردید.',
					implode( '، ', array_unique( array_values( $matched_attrs ) ) ),
					$deleted_vars
				),
				'deleted' => $deleted_vars,
			);
		}

		/**
		 * حذف سراسری تعریف ویژگی از ووکامرس: ابتدا تمام ترم‌های تاکسونومی پاک
		 * و سپس خود رکورد ویژگی (wc_attribute_taxonomies) حذف می‌شود.
		 *
		 * @param array $taxonomies لیست ['key'=>..., 'attribute_id'=>...].
		 * @return array گزارش نتیجه.
		 */
		public static function delete_attribute_globally( array $taxonomies ) {
			self::ensure_all_attribute_taxonomies_registered();

			$report      = array();
			$total_terms = 0;

			foreach ( $taxonomies as $t ) {
				$key          = is_array( $t ) && isset( $t['key'] ) ? (string) $t['key'] : (string) $t;
				$attribute_id = is_array( $t ) && isset( $t['attribute_id'] ) ? (int) $t['attribute_id'] : 0;

				if ( '' === $key || ! taxonomy_exists( $key ) ) {
					continue;
				}

				$terms = get_terms( array(
					'taxonomy'   => $key,
					'hide_empty' => false,
					'fields'     => 'ids',
				) );
				if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
					$terms = array();
				}

				if ( count( $terms ) > 5000 ) {
					return array(
						'success' => false,
						'message' => sprintf( 'تعداد ترم‌های «%s» (%d) از سقف ایمنی ۵۰۰۰ بیشتر است؛ حذف سراسری متوقف شد.', $key, count( $terms ) ),
					);
				}

				$deleted = 0;
				foreach ( $terms as $tid ) {
					if ( wp_delete_term( (int) $tid, $key ) ) {
						$deleted++;
					}
				}
				$total_terms += $deleted;

				if ( $attribute_id > 0 && function_exists( 'wc_delete_attribute' ) ) {
					wc_delete_attribute( $attribute_id );
				}
				delete_transient( 'wc_attribute_taxonomies' );

				$report[] = sprintf( 'تاکسونومی «%s»: %d ترم حذف و تعریف ویژگی پاک شد.', $key, $deleted );
			}

			if ( empty( $report ) ) {
				return array(
					'success' => true,
					'message' => 'تاکسونومی معتبری برای حذف سراسری یافت نشد (شاید قبلاً پاک شده باشد).',
					'terms'   => 0,
				);
			}

			return array(
				'success' => true,
				'message' => implode( ' ', $report ),
				'terms'   => $total_terms,
			);
		}

		/**
		 * پیش‌نمایش تغییرات و تخمین ترکیب‌ها پیش از اعمال قطعی.
		 *
		 * @param array  $product_ids شناسه محصولات.
		 * @param string $attr_name   نام ویژگی هدف (مثلاً «مدل گوشی»).
		 * @param array  $new_values  مقادیر جدید ویژگی.
		 * @param string $price       قیمت متغیرها.
		 * @param string $sale_price  قیمت حراج (اختیاری).
		 * @param bool   $combine_other ترکیب با سایر ویژگی‌های متغیر.
		 * @return array داده‌های پیش‌نمایش.
		 */
		public static function preview( array $product_ids, $attr_name, array $new_values, $price, $sale_price = '', $combine_other = true, $operation_mode = self::MODE_REPLACE_ALL ) {
			self::ensure_all_attribute_taxonomies_registered();
			$operation_mode = self::sanitize_operation_mode( $operation_mode );

			$attr_name = trim( (string) $attr_name );
			if ( '' === $attr_name ) {
				$attr_name = 'مدل گوشی';
			}

			$clean_vals           = self::sanitize_model_list( $new_values );
			$val_count            = count( $clean_vals );
			$price_num            = absint( preg_replace( '/[^\d]/', '', (string) $price ) );
			$sale_num             = absint( preg_replace( '/[^\d]/', '', (string) $sale_price ) );
			$samples              = array();
			$total_old            = 0;
			$total_new            = 0;
			$total_remove         = 0;
			$total_new_capped     = false;
			$preflight_error_count = 0;
			$over_limit_count      = 0;
			$preview_issues        = array();
			$issues_truncated      = false;
			$max_samples           = 25;
			$max_issue_ids         = 100;

			foreach ( $product_ids as $pid ) {
				$product_id = absint( $pid );
				$product    = $product_id ? wc_get_product( $product_id ) : false;
				if ( ! $product ) {
					$preflight_error_count++;
					if ( count( $preview_issues ) < $max_issue_ids ) {
						$preview_issues[] = array( 'id' => $product_id, 'reason' => 'missing_product' );
					} else {
						$issues_truncated = true;
					}
					if ( count( $samples ) < $max_samples ) {
						$samples[] = array(
							'id'              => $product_id,
							'name'            => sprintf( 'محصول #%d', $product_id ),
							'old_vars'        => 0,
							'new_vars'        => 0,
							'over_limit'      => false,
							'preflight_error' => 'محصول در دسترس نیست.',
							'other_attrs'     => 'نامشخص',
						);
					}
					continue;
				}

				$preflight_error = '';
				$old_count       = $product->is_type( 'variable' ) ? count( $product->get_children() ) : 0;
				$total_old      += $old_count;
				$target_info     = self::resolve_product_target_attribute( $product, $attr_name, false );
				$target_key      = $target_info['name'];
				if ( self::MODE_REMOVE_VALUES === $operation_mode ) {
					$remove_error = '';
					$remove_count = 0;
					if ( ! empty( $target_info['keys_to_remove'] ) ) {
						$remove_error = 'ویژگی هدف روی محصول تکراری است.';
					} else {
						$comparison_values = self::comparison_values( $clean_vals, ! empty( $target_info['is_taxonomy'] ), $target_key, false );
						$match_result      = self::count_matching_variations( $product, $target_key, $comparison_values );
						if ( ! empty( $match_result['ambiguous'] ) ) {
							$remove_error = 'مقدار ویژگی هدف در یکی از variationها مبهم است.';
						} else {
							$remove_count = absint( $match_result['count'] );
						}
					}
					if ( '' !== $remove_error ) {
						$preflight_error_count++;
						if ( count( $preview_issues ) < $max_issue_ids ) {
							$preview_issues[] = array( 'id' => $product_id, 'reason' => 'count_failed' );
						} else {
							$issues_truncated = true;
						}
						$preflight_error = $remove_error;
					} else {
						$total_remove += $remove_count;
					}
					if ( count( $samples ) < $max_samples ) {
						$samples[] = array(
							'id'              => $product_id,
							'name'            => $product->get_name(),
							'type'            => $product->get_type(),
							'old_vars'        => $old_count,
							'new_vars'        => 0,
							'remove_vars'     => $remove_count,
							'over_limit'      => false,
							'preflight_error' => $preflight_error,
							'multiplier'      => 0,
							'other_attrs'     => '—',
						);
					}
					continue;
				}
				$other_desc      = array();
				$other_counts    = array();
				$other_matrix    = array();
				$preflight_target_values = self::MODE_ADD_MISSING === $operation_mode && ! empty( $target_info['is_taxonomy'] )
				? self::target_matrix_values( $clean_vals, true, $target_key )
				: $clean_vals;

				if ( $combine_other && $product->is_type( 'variable' ) ) {
					$attrs = $product->get_attributes();
					foreach ( $attrs as $key => $attr_obj ) {
						if ( ! $attr_obj instanceof WC_Product_Attribute || ! $attr_obj->get_variation() ) {
							continue;
						}
						if ( $key === $target_key || $attr_obj->get_name() === $target_key || in_array( $key, $target_info['keys_to_remove'], true ) ) {
							continue;
						}

						if ( $attr_obj->is_taxonomy() ) {
							$options = wc_get_product_terms( $product_id, $attr_obj->get_name(), array( 'fields' => 'slugs' ) );
							if ( is_wp_error( $options ) || ! is_array( $options ) ) {
								$preflight_error = sprintf( 'خواندن گزینه‌های ویژگی «%s» ناموفق بود.', $attr_obj->get_name() );
								break;
							}
							$options = array_values( array_unique( $options ) );
							if ( ! empty( $options ) ) {
								$label        = wc_attribute_label( $attr_obj->get_name() );
								$other_desc[] = sprintf( '%s (%d گزینه)', $label ? $label : $attr_obj->get_name(), count( $options ) );
							}
						} else {
							$options = array_values( array_unique( (array) $attr_obj->get_options() ) );
							if ( ! empty( $options ) ) {
								$other_desc[] = sprintf( '%s (%d گزینه)', $attr_obj->get_name(), count( $options ) );
							}
						}

						$option_count = count( $options );
						if ( 0 === $option_count ) {
							$preflight_error = sprintf( 'ویژگی متغیر «%s» گزینه‌ای ندارد.', $attr_obj->get_name() );
							break;
						}
						$other_counts[] = $option_count;
						$other_matrix[ $attr_obj->get_name() ] = $options;
					}
				}

				if ( self::MODE_ADD_MISSING === $operation_mode && '' === $preflight_error ) {
					if ( empty( $preflight_target_values ) ) {
						$preflight_error = 'مقادیر ویژگی هدف به گزینه‌های معتبر قابل تبدیل نیستند.';
					} elseif ( ! empty( $target_info['keys_to_remove'] ) ) {
						$preflight_error = 'ویژگی هدف روی محصول تکراری است؛ افزودن امن ممکن نیست.';
					} elseif ( ! $combine_other ) {
						foreach ( $product->get_attributes() as $other_key => $other_attribute ) {
							if ( ! $other_attribute instanceof WC_Product_Attribute || ! $other_attribute->get_variation() ) {
								continue;
							}
							$other_name = $other_attribute->get_name();
							if ( ! array_intersect( self::attribute_key_aliases( $target_key ), self::attribute_key_aliases( $other_key ) ) && ! array_intersect( self::attribute_key_aliases( $target_key ), self::attribute_key_aliases( $other_name ) ) ) {
								$preflight_error = 'این محصول ویژگی متغیر دیگری هم دارد؛ برای افزودن امن، ترکیب با سایر ویژگی‌ها را روشن کنید.';
								break;
							}
						}
					}
				}

				$multiplier  = empty( $other_counts ) ? 1 : self::bounded_product_of_counts( $other_counts, self::MAX_VARIATIONS_PER_PRODUCT );
				$count_parts = array_merge( array( self::MODE_ADD_MISSING === $operation_mode ? count( $preflight_target_values ) : $val_count ), $other_counts );
				$new_count   = 0;
				$over_limit  = false;
				if ( '' !== $preflight_error ) {
					$preflight_error_count++;
					if ( count( $preview_issues ) < $max_issue_ids ) {
						$preview_issues[] = array( 'id' => $product_id, 'reason' => 'count_failed' );
					} else {
						$issues_truncated = true;
					}
				} else {
					$matrix_count = self::bounded_product_of_counts( $count_parts, self::MAX_VARIATIONS_PER_PRODUCT );
					$over_limit   = $matrix_count > self::MAX_VARIATIONS_PER_PRODUCT;
					if ( $over_limit ) {
						$over_limit_count++;
						$total_new_capped = true;
						if ( count( $preview_issues ) < $max_issue_ids ) {
							$preview_issues[] = array( 'id' => $product_id, 'reason' => 'over_limit' );
						} else {
							$issues_truncated = true;
						}
					} elseif ( self::MODE_ADD_MISSING === $operation_mode ) {
						$add_matrix = array( $target_key => $preflight_target_values );
						foreach ( $other_matrix as $matrix_key => $matrix_values ) {
							$add_matrix[ $matrix_key ] = $matrix_values;
						}
						$missing_result = self::count_missing_combinations( $product, $add_matrix );
						if ( '' !== $missing_result['error'] ) {
							$preflight_error = $missing_result['error'];
							$preflight_error_count++;
							if ( count( $preview_issues ) < $max_issue_ids ) {
								$preview_issues[] = array( 'id' => $product_id, 'reason' => 'count_failed' );
							} else {
								$issues_truncated = true;
							}
						} else {
							$new_count = absint( $missing_result['count'] );
						}
					} else {
						$new_count = $matrix_count;
					}
					if ( PHP_INT_MAX - $total_new < $new_count ) {
						$total_new        = PHP_INT_MAX;
						$total_new_capped = true;
					} else {
						$total_new += $new_count;
					}
				}

				if ( count( $samples ) < $max_samples ) {
					$samples[] = array(
						'id'              => $product_id,
						'name'            => $product->get_name(),
						'type'            => $product->get_type(),
						'old_vars'        => $old_count,
						'new_vars'        => $new_count,
						'over_limit'      => $over_limit,
						'preflight_error' => $preflight_error,
						'multiplier'      => $multiplier,
						'other_attrs'     => ! empty( $other_desc ) ? implode( ' + ', $other_desc ) : 'تک‌ویژگی',
					);
				}
			}

			return array(
				'total_products'             => count( $product_ids ),
				'operation_mode'             => $operation_mode,
				'operation_mode_label'       => self::operation_mode_label( $operation_mode ),
				'attr_name'                  => $attr_name,
				'new_values'                 => $clean_vals,
				'new_values_count'           => $val_count,
				'price'                      => $price_num,
				'sale_price'                 => $sale_num > 0 ? $sale_num : null,
				'total_old_vars'             => $total_old,
				'total_new_vars'             => $total_new,
				'total_remove_vars'          => $total_remove,
				'total_new_vars_capped'      => $total_new_capped,
				'preflight_error_count' => $preflight_error_count,
				'over_limit_count'      => $over_limit_count,
				'preview_issues'        => $preview_issues,
				'preview_issues_truncated' => $issues_truncated,
				'samples'               => $samples,
				'combine_other'              => (bool) $combine_other,
			);
		}

		/**
		 * اجرای یک بسته محصول در پس‌زمینهٔ ایجکس با پشتیبانی کامل از Rollback.
		 */
		public static function execute_batch( $run_id, array $batch_ids, $attr_name, array $new_values, $price, $sale_price = '', $stock_status = 'instock', $combine_other = true ) {
			if ( ! TCBVM_Backup::lock_run( $run_id ) ) {
				throw new RuntimeException( 'قفل اجرای هم‌زمان در دسترس نیست؛ این بسته اجرا نشد.' );
			}
			try {
				if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::run_is_active( $run_id ) ) {
					throw new RuntimeException( 'قفل/شناسهٔ اجرای فعال معتبر نیست؛ تغییری اعمال نشد.' );
				}
				$run_data       = TCBVM_Backup::get_run( $run_id );
				$run_meta       = is_array( $run_data ) && isset( $run_data['meta'] ) && is_array( $run_data['meta'] ) ? $run_data['meta'] : array();
				$operation_mode = self::sanitize_operation_mode( isset( $run_meta['operation_mode'] ) ? $run_meta['operation_mode'] : self::MODE_REPLACE_ALL );
				if ( ! is_array( $run_data ) || ! isset( $run_data['run_id'], $run_data['status'], $run_data['product_ids'] ) || $run_id !== $run_data['run_id'] || 'in_progress' !== $run_data['status'] || ! is_array( $run_data['product_ids'] ) ) {
					throw new RuntimeException( 'نشست معتبر یا فهرست محصولات مجاز یافت نشد؛ batch اجرا نشد.' );
				}
				$allowed_product_ids = array_fill_keys( array_values( array_unique( array_filter( array_map( 'absint', $run_data['product_ids'] ) ) ) ), true );
				foreach ( $batch_ids as $batch_product_id ) {
					$batch_product_id = absint( $batch_product_id );
					if ( ! $batch_product_id || ! isset( $allowed_product_ids[ $batch_product_id ] ) ) {
						throw new RuntimeException( 'این batch شامل محصولی خارج از نشست ثبت‌شده است؛ هیچ تغییری اعمال نشد.' );
					}
				}
				if ( isset( $run_meta['attr_name'] ) ) {
					$attr_name = $run_meta['attr_name'];
				}
				if ( isset( $run_meta['new_values'] ) && is_array( $run_meta['new_values'] ) ) {
					$new_values = $run_meta['new_values'];
				}
				if ( array_key_exists( 'price', $run_meta ) ) {
					$price = $run_meta['price'];
				}
				if ( array_key_exists( 'sale_price', $run_meta ) ) {
					$sale_price = $run_meta['sale_price'];
				}
				if ( isset( $run_meta['stock_status'] ) ) {
					$stock_status = $run_meta['stock_status'];
				}
				if ( array_key_exists( 'combine_other', $run_meta ) ) {
					$combine_other = (bool) $run_meta['combine_other'];
				}
				self::ensure_all_attribute_taxonomies_registered();

				$clean_values = self::sanitize_model_list( $new_values );
				if ( empty( $clean_values ) ) {
					throw new \InvalidArgumentException( 'لیست مقادیر ویژگی خالی است.' );
				}

				$attr_name = trim( (string) $attr_name );
				if ( '' === $attr_name ) {
					$attr_name = 'مدل گوشی';
				}

				$reg_price = absint( preg_replace( '/[^\d]/', '', (string) $price ) );
				$sal_price = absint( preg_replace( '/[^\d]/', '', (string) $sale_price ) );
				$stock_st  = in_array( $stock_status, array( 'instock', 'outofstock' ), true ) ? $stock_status : 'instock';

				$results = array(
					'processed' => 0,
					'success'   => 0,
					'failed'    => 0,
					'items'     => array(),
				);

				foreach ( $batch_ids as $batch_index => $product_id ) {
					if ( 0 === ( $batch_index % 10 ) && ! TCBVM_Backup::refresh_run_lock( $run_id ) ) {
						throw new RuntimeException( 'قفل اجرای هم‌زمان از دست رفت؛ batch متوقف شد.' );
					}
					$results['processed']++;

					try {
						$res = self::generate_product_variations(
							$product_id,
							$run_id,
							$attr_name,
							$clean_values,
							$reg_price,
							$sal_price > 0 ? $sal_price : '',
							$stock_st,
							(bool) $combine_other,
							$operation_mode
						);
						if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) ) {
							throw new RuntimeException( 'قفل اجرا پس از پردازش محصول از دست رفت؛ batch متوقف شد.' );
						}
						if ( empty( $res['success'] ) && ! empty( $res['message'] ) && false !== strpos( $res['message'], 'قفل' ) ) {
							throw new RuntimeException( $res['message'] );
						}

						if ( ! empty( $res['success'] ) ) {
							$results['success']++;
							$results['items'][] = array(
								'id'      => $product_id,
								'status'  => 'success',
								'title'   => $res['title'],
								'message' => $res['message'],
								'created' => $res['created'],
								'deleted' => $res['deleted'],
							);
						} else {
							$results['failed']++;
							$results['items'][] = array(
								'id'      => $product_id,
								'status'  => 'error',
								'title'   => isset( $res['title'] ) ? $res['title'] : "محصول #{$product_id}",
								'message' => isset( $res['message'] ) ? $res['message'] : 'عملیات ناموفق بود.',
							);
						}
					} catch ( \Throwable $e ) {
						if ( false !== strpos( $e->getMessage(), 'قفل' ) ) {
							throw $e;
						}
						$results['failed']++;
						$results['items'][] = array(
							'id'      => $product_id,
							'status'  => 'error',
							'title'   => "محصول #{$product_id}",
							'message' => 'خطا: ' . $e->getMessage(),
						);
					}
				}

				if ( ! TCBVM_Backup::flush( $run_id ) ) {
					throw new RuntimeException( 'قفل اجرا یا ثبت variationهای ساخته‌شده در snapshot ناموفق بود؛ batch متوقف شد.' );
				}
				return $results;
			} finally {
				TCBVM_Backup::unlock_run( $run_id );
			}
		}

		/**
		 * بازسازی کامل متغیرهای یک محصول:
		 * ۱. اسنپ‌شات کامل
		 * ۲. پاکسازی قطعی ویژگی‌های قبلی/تکراری و ترم‌های آن‌ها
		 * ۳. ثبت ویژگی هدف با تنها مقادیر جدید
		 * ۴. حذف تمام متغیرهای قبلی محصول
		 * ۵. تولید متغیرها از صفر طبق ترکیب صحیح با قیمت تعیین‌شده
		 *
		 * @param int    $product_id    شناسه محصول.
		 * @param string $run_id        شناسه نشست Rollback.
		 * @param string $attr_name     نام ویژگی واردشده توسط کاربر.
		 * @param array  $clean_values  مقادیر جدید ویژگی.
		 * @param int    $regular_price قیمت عادی.
		 * @param string $sale_price    قیمت فروش ویژه (اختیاری).
		 * @param string $stock_status  وضعیت انبار.
		 * @param bool   $combine_other ترکیب با سایر ویژگی‌ها.
		 * @return array نتیجه عملیات روی این محصول.
		 */
		private static function remove_selected_variations_locked( $product, $run_id, array $target_info, array $values ) {
			$product_id = absint( $product->get_id() );
			$title      = $product->get_name();
			$target_key = isset( $target_info['name'] ) ? (string) $target_info['name'] : '';
			$is_taxonomy = ! empty( $target_info['is_taxonomy'] );
			if ( '' === $target_key || ! empty( $target_info['keys_to_remove'] ) ) {
				return array( 'success' => false, 'title' => $title, 'message' => 'ویژگی هدف نامشخص یا تکراری است؛ حذف برای جلوگیری از اثرگذاری روی مقدار نادرست متوقف شد.' );
			}

			$comparison_values = self::comparison_values( $values, $is_taxonomy, $target_key, false );
			$matching_ids      = array();
			if ( $product->is_type( 'variable' ) && ! empty( $comparison_values ) ) {
				foreach ( (array) $product->get_children() as $index => $variation_id ) {
					if ( 0 === ( $index % 10 ) && ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) ) {
						return array( 'success' => false, 'title' => $title, 'message' => 'قفل اجرا/محصول هنگام بررسی مقادیر حذف از دست رفت؛ عملیات متوقف شد.' );
					}
					$variation = wc_get_product( $variation_id );
					if ( ! $variation || ! $variation->is_type( 'variation' ) || absint( $variation->get_parent_id() ) !== $product_id ) {
						continue;
					}
					$value = self::find_variation_attribute_value( (array) $variation->get_attributes(), $target_key );
					if ( ! empty( $value['ambiguous'] ) ) {
						return array( 'success' => false, 'title' => $title, 'message' => sprintf( 'variation #%d بیش از یک مقدار برای ویژگی هدف دارد؛ هیچ تغییری اعمال نشد.', absint( $variation_id ) ) );
					}
					if ( ! empty( $value['found'] ) && in_array( self::normalize_variation_value( $value['value'] ), $comparison_values, true ) ) {
					$matching_ids[] = absint( $variation_id );
					}
				}
			}

			$attributes       = $product->get_attributes();
			$target_attr_keys = array();
			foreach ( $attributes as $key => $attribute ) {
				$attribute_name = $attribute instanceof WC_Product_Attribute ? $attribute->get_name() : (string) $key;
				if ( array_intersect( self::attribute_key_aliases( $target_key ), self::attribute_key_aliases( $key ) ) || array_intersect( self::attribute_key_aliases( $target_key ), self::attribute_key_aliases( $attribute_name ) ) ) {
					$target_attr_keys[] = $key;
				}
			}
			if ( count( $target_attr_keys ) > 1 ) {
				return array( 'success' => false, 'title' => $title, 'message' => 'تعریف ویژگی هدف روی محصول تکراری است؛ حذف برای جلوگیری از پاکسازی ناقص متوقف شد.' );
			}

			$remaining_taxonomy_ids = array();
			$remove_target_options  = false;
			if ( $is_taxonomy && taxonomy_exists( $target_key ) ) {
				$current_term_ids = wp_get_object_terms( $product_id, $target_key, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $current_term_ids ) ) {
					return array( 'success' => false, 'title' => $title, 'message' => 'خواندن گزینه‌های ویژگی سراسری ناموفق بود؛ حذف انجام نشد.' );
				}
				foreach ( array_map( 'absint', (array) $current_term_ids ) as $term_id ) {
					$term = get_term( $term_id, $target_key );
					if ( ! $term || is_wp_error( $term ) ) {
						return array( 'success' => false, 'title' => $title, 'message' => sprintf( 'خواندن term شمارهٔ %d ناموفق بود؛ حذف برای جلوگیری از تغییر ناقص متوقف شد.', $term_id ) );
					}
					if ( in_array( self::normalize_variation_value( $term->slug ), $comparison_values, true ) ) {
					$remove_target_options = true;
				} else {
					$remaining_taxonomy_ids[] = $term_id;
				}
				}
			} elseif ( ! empty( $target_attr_keys ) ) {
				$key       = reset( $target_attr_keys );
				$attribute = $attributes[ $key ];
				if ( $attribute instanceof WC_Product_Attribute ) {
					foreach ( (array) $attribute->get_options() as $option ) {
						if ( in_array( self::normalize_variation_value( $option ), $comparison_values, true ) ) {
							$remove_target_options = true;
							break;
						}
					}
				}
			}

			if ( empty( $matching_ids ) && ! $remove_target_options ) {
				return array( 'success' => true, 'title' => $title, 'message' => 'هیچ‌یک از مقادیر واردشده در این محصول وجود نداشت؛ تغییری انجام نشد.', 'created' => 0, 'deleted' => 0 );
			}
			if ( ! TCBVM_Backup::snapshot_product( $run_id, $product_id ) ) {
				if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
					return array( 'success' => false, 'title' => $title, 'message' => 'قفل اجرا/محصول از دست رفت؛ snapshot و حذف متوقف شد.' );
				}
				return array( 'success' => false, 'title' => $title, 'message' => 'ثبت snapshot کامل ناموفق بود؛ مقادیر محصول بدون تغییر ماند.' );
			}
			if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
				return array( 'success' => false, 'title' => $title, 'message' => 'قفل اجرا/محصول پس از snapshot از دست رفت؛ هیچ تغییری ادامه پیدا نکرد.' );
			}

			$deleted_count = 0;
			foreach ( $matching_ids as $index => $variation_id ) {
				if ( 0 === ( $index % 10 ) && ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) ) {
					throw new RuntimeException( 'قفل اجرا/محصول از دست رفت؛ حذف variationها متوقف شد.' );
				}
				$variation = wc_get_product( $variation_id );
				if ( ! $variation ) {
					continue;
				}
				if ( ! $variation->is_type( 'variation' ) || absint( $variation->get_parent_id() ) !== $product_id ) {
					throw new RuntimeException( sprintf( 'variation #%d دیگر به محصول هدف تعلق ندارد؛ حذف متوقف شد.', $variation_id ) );
				}
				$variation->delete( true );
				clean_post_cache( $variation_id );
				if ( get_post( $variation_id ) ) {
					throw new RuntimeException( sprintf( 'حذف variation #%d از مسیر CRUD کامل نشد.', $variation_id ) );
				}
				$deleted_count++;
			}

			if ( ! empty( $target_attr_keys ) ) {
				$key       = reset( $target_attr_keys );
				$attribute = $attributes[ $key ];
				if ( $attribute instanceof WC_Product_Attribute ) {
					if ( $is_taxonomy ) {
						$attribute->set_options( $remaining_taxonomy_ids );
					} else {
						$remaining_options = array();
						foreach ( (array) $attribute->get_options() as $option ) {
							if ( ! in_array( self::normalize_variation_value( $option ), $comparison_values, true ) ) {
								$remaining_options[] = $option;
							}
						}
						$attribute->set_options( array_values( $remaining_options ) );
					}
					if ( empty( $attribute->get_options() ) ) {
						unset( $attributes[ $key ] );
					} else {
						$attributes[ $key ] = $attribute;
					}
				}
			}
			if ( $is_taxonomy && taxonomy_exists( $target_key ) ) {
				if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
					throw new RuntimeException( 'قفل اجرا/محصول پیش از پاکسازی termهای محصول از دست رفت.' );
				}
				$term_result = wp_set_object_terms( $product_id, $remaining_taxonomy_ids, $target_key, false );
				if ( is_wp_error( $term_result ) ) {
					throw new RuntimeException( $term_result->get_error_message() );
				}
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				throw new RuntimeException( 'بارگذاری محصول برای ذخیرهٔ مقادیر باقی‌مانده ناموفق بود.' );
			}
			if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
				throw new RuntimeException( 'قفل اجرا/محصول پیش از ذخیرهٔ مقادیر باقی‌مانده از دست رفت.' );
			}
			$product->set_attributes( $attributes );
			$product->save();
			if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
				throw new RuntimeException( 'قفل اجرا/محصول پس از ذخیرهٔ ویژگی‌های باقی‌مانده از دست رفت.' );
			}
			if ( $product->is_type( 'variable' ) ) {
				WC_Product_Variable::sync( $product_id );
			}
			wc_delete_product_transients( $product_id );
			clean_post_cache( $product_id );

			return array(
				'success' => true,
				'title'   => $title,
				'message' => sprintf( '%d متغیر با مقادیر واردشده حذف شد؛ سایر متغیرها حفظ شدند.', $deleted_count ),
				'created' => 0,
				'deleted' => $deleted_count,
			);
		}

		private static function generate_product_variations( $product_id, $run_id, $attr_name, array $clean_values, $regular_price, $sale_price, $stock_status, $combine_other, $operation_mode ) {
			if ( ! TCBVM_Backup::lock_product( $product_id ) ) {
				return array( 'success' => false, 'message' => 'قفل محصول در دسترس نیست؛ تغییر انجام نشد.' );
			}
			try {
				if ( ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
					return array( 'success' => false, 'message' => 'قفل محصول از دست رفت؛ تغییر انجام نشد.' );
				}
				return self::generate_product_variations_locked( $product_id, $run_id, $attr_name, $clean_values, $regular_price, $sale_price, $stock_status, $combine_other, $operation_mode );
			} finally {
				TCBVM_Backup::unlock_product( $product_id );
			}
		}

		private static function generate_product_variations_locked( $product_id, $run_id, $attr_name, array $clean_values, $regular_price, $sale_price, $stock_status, $combine_other, $operation_mode ) {
			$start_time = microtime( true );

			// ابتدا شمارش؛ محصولی که از سقف می‌گذرد نه snapshot می‌شود و نه تغییر می‌کند.
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return array( 'success' => false, 'message' => 'محصول در سیستم یافت نشد.' );
			}
			$title    = $product->get_name();
			$pre_info = self::resolve_product_target_attribute( $product, $attr_name, false );
			$pre_key  = $pre_info['name'];
			if ( self::MODE_REMOVE_VALUES === $operation_mode ) {
				return self::remove_selected_variations_locked( $product, $run_id, $pre_info, $clean_values );
			}
			if ( self::MODE_ADD_MISSING === $operation_mode && ! empty( $pre_info['keys_to_remove'] ) ) {
				return array( 'success' => false, 'title' => $title, 'message' => 'ویژگی هدف چند تعریف تکراری دارد؛ برای افزودن امن ابتدا تعارض ویژگی‌ها را در محصول برطرف کنید یا حالت جایگزینی کامل را انتخاب کنید.' );
			}
			$pre_attrs = array();

			foreach ( $product->get_attributes() as $pre_attr_key => $pre_attr ) {
				if ( in_array( $pre_attr_key, $pre_info['keys_to_remove'], true ) ) {
					continue;
				}
				if ( ! empty( $pre_info['is_taxonomy'] ) && ( $pre_attr_key === $attr_name || sanitize_title( $pre_attr_key ) === sanitize_title( $attr_name ) ) && $pre_attr_key !== $pre_key ) {
					continue;
				}
				$pre_attrs[ $pre_attr_key ] = $pre_attr;
			}

			if ( self::MODE_ADD_MISSING === $operation_mode && ! $combine_other ) {
				foreach ( $pre_attrs as $pre_attr_key => $pre_attr ) {
					if ( $pre_attr instanceof WC_Product_Attribute && $pre_attr->get_variation() && $pre_attr_key !== $pre_key && $pre_attr->get_name() !== $pre_key ) {
						return array( 'success' => false, 'title' => $title, 'message' => 'این محصول ویژگی متغیر دیگری هم دارد؛ برای افزودن بدون تداخل، گزینهٔ ترکیب با سایر ویژگی‌ها را روشن کنید.' );
					}
				}
			}

			$preflight_target_values = array_values( $clean_values );
			if ( self::MODE_ADD_MISSING === $operation_mode && ! empty( $pre_info['is_taxonomy'] ) ) {
				$preflight_target_values = self::target_matrix_values( $clean_values, true, $pre_key );
			}
			$preflight_matrix = array( $pre_key => $preflight_target_values );
			if ( $combine_other ) {
				foreach ( $pre_attrs as $pre_attr_key => $pre_attr ) {
					if ( ! $pre_attr instanceof WC_Product_Attribute || ! $pre_attr->get_variation() ) {
						continue;
					}
					if ( $pre_attr_key === $pre_key || $pre_attr->get_name() === $pre_key || in_array( $pre_attr_key, $pre_info['keys_to_remove'], true ) ) {
						continue;
					}

					if ( $pre_attr->is_taxonomy() ) {
						$pre_terms = wc_get_product_terms( $product_id, $pre_attr->get_name(), array( 'fields' => 'slugs' ) );
						if ( is_wp_error( $pre_terms ) ) {
							return array( 'success' => false, 'title' => $title, 'message' => sprintf( 'خواندن گزینه‌های ویژگی «%s» ناموفق بود؛ پیش‌بررسی و اجرا متوقف شد.', $pre_attr->get_name() ) );
						}
						$pre_options = (array) $pre_terms;
					} else {
						$pre_options = (array) $pre_attr->get_options();
					}
					$pre_options = array_values( array_unique( $pre_options ) );
					if ( empty( $pre_options ) ) {
						return array( 'success' => false, 'title' => $title, 'message' => sprintf( 'ویژگی متغیر «%s» گزینه‌ای ندارد؛ برای جلوگیری از شمارش نادرست اجرا متوقف شد.', $pre_attr->get_name() ) );
					}
					$preflight_matrix[ $pre_attr->get_name() ] = $pre_options;
				}
			}

			$preflight_count = self::cartesian_product_count( $preflight_matrix, self::MAX_VARIATIONS_PER_PRODUCT );
			if ( $preflight_count > self::MAX_VARIATIONS_PER_PRODUCT ) {
				return array(
					'success' => false,
					'title'   => $title,
					'message' => sprintf( 'تعداد ترکیب‌های این محصول بیش از سقف %d است؛ پیش از ساخت ماتریس، snapshot یا هر تغییری رد شد.', self::MAX_VARIATIONS_PER_PRODUCT ),
				);
			}

			$existing_signatures = array();
			if ( self::MODE_ADD_MISSING === $operation_mode ) {
				$requested_matrix = array( $pre_key => $preflight_target_values );
				foreach ( $preflight_matrix as $matrix_key => $matrix_values ) {
					if ( $matrix_key !== $pre_key ) {
						$requested_matrix[ $matrix_key ] = $matrix_values;
					}
				}
				$preflight_combinations = self::cartesian_product( $requested_matrix );
				$preflight_keys         = array_keys( $requested_matrix );
				if ( $product->is_type( 'variable' ) ) {
					foreach ( (array) $product->get_children() as $child_index => $child_id ) {
						if ( 0 === ( $child_index % 10 ) && ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) ) {
							return array( 'success' => false, 'title' => $title, 'message' => 'قفل اجرا/محصول هنگام تطبیق variationهای موجود از دست رفت؛ افزودن متوقف شد.' );
						}
						$child = wc_get_product( $child_id );
						if ( ! $child || ! $child->is_type( 'variation' ) || absint( $child->get_parent_id() ) !== absint( $product_id ) ) {
							continue;
						}
						$child_attributes = (array) $child->get_attributes();
						foreach ( $preflight_keys as $matrix_key ) {
							$child_value = self::find_variation_attribute_value( $child_attributes, $matrix_key );
							if ( ! empty( $child_value['ambiguous'] ) || empty( $child_value['found'] ) || '' === (string) $child_value['value'] ) {
								return array( 'success' => false, 'title' => $title, 'message' => sprintf( 'variation #%d مقدار یکتای ویژگی‌های لازم را ندارد؛ افزودن برای جلوگیری از ترکیب تکراری متوقف شد.', absint( $child_id ) ) );
							}
						}
						$signature = self::combination_signature( $child_attributes, $preflight_keys );
						if ( false === $signature ) {
							return array( 'success' => false, 'title' => $title, 'message' => sprintf( 'ترکیب variation #%d قابل تطبیق نیست؛ افزودن متوقف شد.', absint( $child_id ) ) );
						}
						$existing_signatures[ $signature ] = true;
					}
				}
				$pending_count         = 0;
				$requested_signatures = array();
				foreach ( $preflight_combinations as $candidate ) {
					$signature = self::combination_signature( $candidate, $preflight_keys );
					if ( false === $signature ) {
						return array( 'success' => false, 'title' => $title, 'message' => 'یکی از ترکیب‌های درخواستی قابل تطبیق نیست؛ افزودن متوقف شد.' );
					}
					if ( ! isset( $existing_signatures[ $signature ] ) && ! isset( $requested_signatures[ $signature ] ) ) {
						$pending_count++;
						$requested_signatures[ $signature ] = true;
					}
				}
				if ( 0 === $pending_count ) {
					return array( 'success' => true, 'title' => $title, 'message' => 'تمام ترکیب‌های واردشده از قبل وجود داشتند؛ variation یا قیمت موجود تغییر نکرد.', 'created' => 0, 'deleted' => 0 );
				}
			}

			if ( ! TCBVM_Backup::snapshot_product( $run_id, $product_id ) ) {
				if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
					return array( 'success' => false, 'title' => $title, 'message' => 'قفل اجرا/محصول از دست رفت؛ ثبت snapshot شکست خورد و batch متوقف شد.' );
				}
				return array( 'success' => false, 'title' => $title, 'message' => 'ثبت snapshot کامل ناموفق بود (احتمالاً اندازهٔ خام یا ذخیره‌شده از سقف ۶۴ MiB بیشتر است)؛ محصول بدون تغییر ماند.' );
			}
			if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
				return array( 'success' => false, 'title' => $title, 'message' => 'قفل اجرا/محصول از دست رفت؛ هیچ تغییری ادامه پیدا نکرد.' );
			}

			// ویژگی سراسریِ ناموجود فقط پس از پیش‌بررسی سقف و ثبت snapshot ساخته می‌شود.
			$target_info = self::resolve_product_target_attribute( $product, $attr_name, true );
			if ( ! empty( $target_info['error'] ) ) {
				return array( 'success' => false, 'title' => $title, 'message' => 'ساخت/یافتن ویژگی هدف ناموفق بود: ' . $target_info['error'] );
			}

			$target_key         = $target_info['name'];
			$is_taxonomy        = $target_info['is_taxonomy'];
			$keys_to_remove     = $target_info['keys_to_remove'];
			$existing_attributes = $product->get_attributes();
			$product_attributes  = array();
			$taxonomies_to_clear = array();

			// ویژگی‌های تکراری از ماتریس حذف می‌شوند؛ رابطهٔ ترم‌ها فقط پس از کنترل نهایی سقف پاک می‌شود.
			foreach ( $existing_attributes as $ekey => $eattr ) {
				if ( in_array( $ekey, $keys_to_remove, true ) ) {
					if ( taxonomy_exists( $ekey ) ) {
						$taxonomies_to_clear[] = $ekey;
					}
					continue;
				}
				if ( $is_taxonomy && ( $ekey === $attr_name || sanitize_title( $ekey ) === sanitize_title( $attr_name ) ) && $ekey !== $target_key ) {
					continue;
				}
				$product_attributes[ $ekey ] = $eattr;
			}

			$target_terms = array();
			$combo_matrix = array();
			if ( $is_taxonomy ) {
				foreach ( $clean_values as $term_index => $value ) {
					if ( 0 === ( $term_index % 10 ) && ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) ) {
						return array( 'success' => false, 'title' => $title, 'message' => 'قفل اجرا/محصول از دست رفت؛ ساخت term متوقف شد.' );
					}
					$term = self::ensure_term_exists( $value, $target_key );
					if ( is_wp_error( $term ) || ! is_object( $term ) || ! isset( $term->term_id, $term->slug, $term->name ) || ! absint( $term->term_id ) || '' === (string) $term->slug || '' === (string) $term->name ) {
						return array( 'success' => false, 'title' => $title, 'message' => sprintf( 'ساخت یا یافتن ترم «%s» ناموفق بود؛ محصول بدون تغییر ماند.', $value ) );
					}
					$term_id = absint( $term->term_id );
					$target_terms[ $term_id ] = array(
						'id'   => $term_id,
						'slug' => (string) $term->slug,
						'name' => (string) $term->name,
					);
				}
				$target_terms = array_values( $target_terms );
				if ( empty( $target_terms ) ) {
					return array( 'success' => false, 'title' => $title, 'message' => 'هیچ مقدار معتبری برای تاکسونومی ویژگی ایجاد نشد.' );
				}

				$term_ids   = wp_list_pluck( $target_terms, 'id' );
				$term_slugs = wp_list_pluck( $target_terms, 'slug' );
				if ( self::MODE_ADD_MISSING === $operation_mode ) {
					$existing_term_ids = wp_get_object_terms( $product_id, $target_key, array( 'fields' => 'ids' ) );
					if ( is_wp_error( $existing_term_ids ) ) {
						return array( 'success' => false, 'title' => $title, 'message' => 'خواندن مقادیر فعلی ویژگی هدف ناموفق بود؛ هیچ تغییری روی محصول اعمال نشد.' );
					}
					$term_ids = array_values( array_unique( array_merge( array_map( 'absint', (array) $existing_term_ids ), array_map( 'absint', $term_ids ) ) ) );
				}
				$target_attr_obj = new WC_Product_Attribute();
				$tax_id          = function_exists( 'wc_attribute_taxonomy_id_by_name' ) ? wc_attribute_taxonomy_id_by_name( $target_key ) : 0;
				$target_attr_obj->set_id( $tax_id );
				$target_attr_obj->set_name( $target_key );
				$target_attr_obj->set_options( $term_ids );
				$target_attr_obj->set_position( 0 );
				$target_attr_obj->set_visible( true );
				$target_attr_obj->set_variation( true );
				$product_attributes[ $target_key ] = $target_attr_obj;
				$combo_matrix[ $target_key ]       = $term_slugs;
			} else {
				$target_options = $clean_values;
				if ( self::MODE_ADD_MISSING === $operation_mode ) {
					foreach ( $existing_attributes as $existing_attribute ) {
						if ( $existing_attribute instanceof WC_Product_Attribute && ( $existing_attribute->get_name() === $target_key || sanitize_title( $existing_attribute->get_name() ) === sanitize_title( $target_key ) ) ) {
							$target_options = array_values( array_unique( array_merge( (array) $existing_attribute->get_options(), $clean_values ) ) );
							break;
						}
					}
				}
				$target_attr_obj = new WC_Product_Attribute();
				$target_attr_obj->set_id( 0 );
				$target_attr_obj->set_name( $target_key );
				$target_attr_obj->set_options( $target_options );
				$target_attr_obj->set_position( 0 );
				$target_attr_obj->set_visible( true );
				$target_attr_obj->set_variation( true );
				$product_attributes[ $target_key ] = $target_attr_obj;
				$combo_matrix[ $target_key ]       = $clean_values;
			}

			if ( $combine_other ) {
				foreach ( $product_attributes as $key => $attribute ) {
					if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) {
						continue;
					}
					if ( $key === $target_key || $attribute->get_name() === $target_key ) {
						continue;
					}

					if ( $attribute->is_taxonomy() ) {
						$terms = wc_get_product_terms( $product_id, $attribute->get_name(), array( 'fields' => 'slugs' ) );
						if ( is_wp_error( $terms ) ) {
							throw new RuntimeException( sprintf( 'خواندن گزینه‌های ویژگی «%s» پس از snapshot ناموفق بود؛ تغییری روی محصول اعمال نشد.', $attribute->get_name() ) );
						}
						$options = (array) $terms;
					} else {
						$options = (array) $attribute->get_options();
					}
					$options = array_values( array_unique( $options ) );
					if ( empty( $options ) ) {
						throw new RuntimeException( sprintf( 'گزینه‌های ویژگی متغیر «%s» پس از snapshot خالی شد؛ بازسازی متوقف شد.', $attribute->get_name() ) );
					}
					$combo_matrix[ $attribute->get_name() ] = $options;
				}
			}

			$final_count = self::cartesian_product_count( $combo_matrix, self::MAX_VARIATIONS_PER_PRODUCT );
			if ( $final_count > self::MAX_VARIATIONS_PER_PRODUCT ) {
				throw new RuntimeException( sprintf( 'تعداد ترکیب‌ها از سقف %d عبور کرده است؛ ماتریس ساخته نشد.', self::MAX_VARIATIONS_PER_PRODUCT ) );
			}
			if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
				throw new RuntimeException( 'قفل اجرا/محصول از دست رفت؛ ساخت ماتریس و ادامهٔ تغییرات متوقف شد.' );
			}

			$combinations = self::cartesian_product( $combo_matrix );
			if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
				throw new RuntimeException( 'قفل اجرا/محصول پیش از تغییر رابطهٔ ویژگی از دست رفت؛ اجرا متوقف شد.' );
			}
			foreach ( array_unique( $taxonomies_to_clear ) as $taxonomy_to_clear ) {
				$clear_result = wp_set_object_terms( $product_id, array(), $taxonomy_to_clear, false );
				if ( is_wp_error( $clear_result ) ) {
					throw new RuntimeException( $clear_result->get_error_message() );
				}
				if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
					throw new RuntimeException( 'قفل اجرا/محصول پس از تغییر رابطهٔ ویژگی از دست رفت؛ ادامه متوقف شد.' );
				}
			}

			if ( ! $product->is_type( 'variable' ) ) {
				$type_result = wp_set_object_terms( $product_id, 'variable', 'product_type', false );
				if ( is_wp_error( $type_result ) ) {
					throw new RuntimeException( $type_result->get_error_message() );
				}
				clean_post_cache( $product_id );
				$product = wc_get_product( $product_id );
				if ( ! $product instanceof WC_Product_Variable ) {
					throw new RuntimeException( 'تبدیل محصول به متغیر با خطا مواجه شد.' );
				}
			}

			if ( $is_taxonomy ) {
				$term_result = wp_set_object_terms( $product_id, $term_ids, $target_key, false );
				if ( is_wp_error( $term_result ) ) {
					throw new RuntimeException( $term_result->get_error_message() );
				}
			}
			$product->set_attributes( $product_attributes );
			$product->save();
			if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
				throw new RuntimeException( 'قفل اجرا/محصول پس از ذخیرهٔ ویژگی از دست رفت؛ ادامهٔ بازسازی متوقف شد.' );
			}

			// در حالت جایگزینی کامل، variationهای قبلی از مسیر CRUD ووکامرس حذف می‌شوند؛ حالت افزودن آن‌ها را نگه می‌دارد.
			$product = wc_get_product( $product_id );
			if ( ! $product || ! $product->is_type( 'variable' ) ) {
				throw new RuntimeException( 'بارگذاری دوبارهٔ محصول متغیر پس از ذخیره ناموفق بود؛ ادامه متوقف شد.' );
			}
			$old_children     = (array) $product->get_children();
			$deleted_count    = 0;
			$next_menu_order  = 0;
			if ( self::MODE_ADD_MISSING === $operation_mode ) {
				foreach ( $old_children as $old_id ) {
					$existing_variation = wc_get_product( $old_id );
					if ( $existing_variation && $existing_variation->is_type( 'variation' ) && absint( $existing_variation->get_parent_id() ) === absint( $product_id ) ) {
						$next_menu_order = max( $next_menu_order, (int) $existing_variation->get_menu_order() + 1 );
					}
				}
			}
			if ( self::MODE_REPLACE_ALL === $operation_mode ) {
				foreach ( $old_children as $old_index => $old_id ) {
					if ( 0 === ( $old_index % 10 ) && ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) ) {
						throw new RuntimeException( 'قفل اجرا/محصول از دست رفت؛ عملیات متوقف شد تا نوشتن هم‌زمان رخ ندهد.' );
					}
					$old_variation = wc_get_product( $old_id );
					if ( ! $old_variation || ! $old_variation->is_type( 'variation' ) ) {
						continue;
					}
					if ( absint( $old_variation->get_parent_id() ) !== absint( $product_id ) ) {
						throw new RuntimeException( sprintf( 'variation #%d والد این محصول نیست؛ بازسازی برای حفاظت از داده متوقف شد.', absint( $old_variation->get_id() ) ) );
					}
					$old_variation_id = absint( $old_variation->get_id() );
					$old_variation->delete( true );
					clean_post_cache( $old_variation_id );
					if ( get_post( $old_variation_id ) ) {
						throw new RuntimeException( sprintf( 'حذف variation قبلی #%d از مسیر CRUD کامل نشد.', $old_variation_id ) );
					}
					$deleted_count++;
				}
			}

			// ساخت variationها از مسیر CRUD ووکامرس و ثبت run_id صریح همین اجرا.
			$created_count = 0;
			$price_str     = (string) $regular_price;
			$sale_str      = '' !== (string) $sale_price ? (string) $sale_price : '';
			$final_price   = '' !== $sale_str && (float) $sale_str > 0 ? $sale_str : $price_str;
			$combination_keys = array_keys( $combo_matrix );
			foreach ( $combinations as $idx => $combination_attrs ) {
				if ( self::MODE_ADD_MISSING === $operation_mode ) {
					$signature = self::combination_signature( $combination_attrs, $combination_keys );
					if ( false === $signature ) {
						throw new RuntimeException( 'ترکیب variation برای تطبیق با موارد موجود نامعتبر شد؛ افزودن متوقف شد.' );
					}
					if ( isset( $existing_signatures[ $signature ] ) ) {
						continue;
					}
					$existing_signatures[ $signature ] = true;
				}
				if ( 0 === ( $idx % 10 ) && ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) ) {
					throw new RuntimeException( 'قفل اجرا/محصول از دست رفت؛ ایجاد variation متوقف شد.' );
				}
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product_id );
				$variation->set_status( 'publish' );
				$variation->set_menu_order( self::MODE_ADD_MISSING === $operation_mode ? $next_menu_order + (int) $idx : (int) $idx );
				$variation->set_attributes( $combination_attrs );
				$variation->set_regular_price( $price_str );
				$variation->set_sale_price( $sale_str );
				$variation->set_price( $final_price );
				$variation->set_stock_status( $stock_status );
				$variation->set_manage_stock( false );
				$variation->update_meta_data( TCBVM_Backup::CREATED_RUN_META, (string) $run_id );
				$saved_id    = $variation->save();
				$variation_id = absint( $variation->get_id() ? $variation->get_id() : $saved_id );
				if ( ! $variation_id ) {
					throw new RuntimeException( sprintf( 'ذخیره variation شمارهٔ %d از مسیر CRUD شکست خورد.', $idx + 1 ) );
				}
				if ( ! TCBVM_Backup::track_created_variation( $run_id, $product_id, $variation_id ) ) {
					throw new RuntimeException( sprintf( 'قفل اجرا/ثبت variation #%d در snapshot همین اجرا ناموفق بود؛ batch متوقف شد.', $variation_id ) );
				}
				clean_post_cache( $variation_id );
				$stored_post      = get_post( $variation_id );
				$stored_variation = wc_get_product( $variation_id );
				if ( ! $stored_post || 'product_variation' !== $stored_post->post_type || absint( $stored_post->post_parent ) !== absint( $product_id ) || ! $stored_variation || ! $stored_variation->is_type( 'variation' ) || absint( $stored_variation->get_parent_id() ) !== absint( $product_id ) ) {
					throw new RuntimeException( sprintf( 'variation #%d پس از CRUD به محصول موردنظر متصل نشد؛ batch متوقف شد.', $variation_id ) );
				}
				if ( (string) get_post_meta( $variation_id, TCBVM_Backup::CREATED_RUN_META, true ) !== (string) $run_id ) {
					throw new RuntimeException( sprintf( 'قفل/نشان run_id variation #%d ثبت نشد؛ batch متوقف شد تا rollback امن بماند.', $variation_id ) );
				}
				$created_count++;
			}
			if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
				throw new RuntimeException( 'قفل اجرا/محصول از دست رفت؛ ثبت نهایی عملیات متوقف شد.' );
			}

			WC_Product_Variable::sync( $product_id );
			wc_delete_product_transients( $product_id );
			delete_transient( 'wc_var_prices_' . $product_id );
			clean_post_cache( $product_id );
			if ( ! TCBVM_Backup::refresh_run_lock( $run_id ) || ! TCBVM_Backup::refresh_product_lock( $product_id ) ) {
				throw new RuntimeException( 'قفل اجرا/محصول در پایان عملیات از دست رفت؛ وضعیت در snapshot قابل بازبینی است.' );
			}

			$elapsed = round( microtime( true ) - $start_time, 2 );
			if ( self::MODE_ADD_MISSING === $operation_mode ) {
				$message = sprintf(
					'%d ترکیب جدید با قیمت %s تومان افزوده شد؛ متغیرهای قبلی و ترکیب‌های تکراری بدون تغییر حفظ شدند (زمان: %s ثانیه).',
					$created_count,
					number_format_i18n( $regular_price ),
					number_format_i18n( $elapsed, 2 )
				);
			} else {
				$message = sprintf(
					'ویژگی «%s» بروز شد؛ %d متغیر قبلی حذف و %d متغیر جدید با قیمت %s تومان ثبت گردید (زمان: %s ثانیه).',
					esc_html( $target_key ),
					$deleted_count,
					$created_count,
					number_format_i18n( $regular_price ),
					number_format_i18n( $elapsed, 2 )
				);
			}

			return array(
				'success'      => true,
				'title'        => $title,
				'created'      => $created_count,
				'deleted'      => $deleted_count,
				'message'      => $message,
				'elapsed'      => $elapsed,
				'target_attr'  => $target_key,
				'models_count' => count( $clean_values ),
			);
		}
	}
}
