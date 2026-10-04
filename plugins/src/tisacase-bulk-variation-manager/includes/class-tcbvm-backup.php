<?php
/**
 * اسنپ‌شات کامل، ثبت تاریخچه و بازگردانی تغییرات متغیرهای ووکامرس.
 *
 * هر اجرا دادهٔ مستقل دارد؛ تغییرات اسنپ‌شات با قفل اتمیکِ مختص run_id نوشته
 * می‌شوند تا اجرای موازی نتواند دادهٔ اجرای دیگری را بازنویسی کند.
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBVM_Backup' ) ) {

	final class TCBVM_Backup {

		const OPTION_RUNS_LIST  = 'tcbvm_history_runs';
		const MAX_RUNS_SAVED    = 30;
		const SNAPSHOT_VERSION  = 4;
		const CREATED_RUN_META  = '_tcbvm_created_run';
		const RESTORE_RUN_META  = '_tcbvm_restored_run';
		const RESTORE_FROM_META = '_tcbvm_restored_from';
		const LOCK_TTL          = 300;
		const MAX_SNAPSHOT_BYTES = 67108864;

		/** متغیرهای ساخته‌شده، در حافظه بر اساس شناسهٔ اجرای واقعی دسته‌بندی می‌شوند. */
		private static $created_vars = array();

		/** قفل‌های re-entrant همین درخواست. */
		private static $held_locks = array();

		/** فهرست محصولات هر اجرا برای اعتبارسنجی سریع run_id در مسیرهای پرتکرار. */
		private static $run_products = array();

		private static function valid_run_id( $run_id ) {
			return is_string( $run_id ) && 1 === preg_match( '/^run_[A-Za-z0-9_-]{1,100}$/', $run_id );
		}

		private static function product_snapshot_option_name( $run_id, $product_id ) {
			return 'tcbvm_snap_' . $run_id . '_product_' . absint( $product_id );
		}

		/** حذف همهٔ payloadهای محصول یک اجرا، شامل payload يتیمی که قبل از index شدن قطع شده باشد. */
		private static function delete_run_snapshot_payloads( $run_id ) {
			if ( ! self::valid_run_id( $run_id ) ) {
				return;
			}
			global $wpdb;
			if ( ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'esc_like' ) ) {
				return;
			}
			$prefix       = 'tcbvm_snap_' . $run_id . '_product_';
			$option_names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
			foreach ( (array) $option_names as $option_name ) {
				delete_option( $option_name );
			}
		}

		private static function lock_option_name( $scope ) {
			return 'tcbvm_lock_' . substr( hash( 'sha256', (string) $scope ), 0, 40 );
		}

		/** قفل option با add_option (قید یکتای option_name در DB)؛ قفل stale قابل بازیابی است. */
		private static function lock_scope( $scope ) {
			$option_name = self::lock_option_name( $scope );
			if ( isset( self::$held_locks[ $option_name ] ) ) {
				$last_refresh = isset( self::$held_locks[ $option_name ]['refreshed_at'] ) ? (int) self::$held_locks[ $option_name ]['refreshed_at'] : 0;
				if ( time() - $last_refresh >= intdiv( self::LOCK_TTL, 2 ) && ! self::refresh_scope_lock( $scope ) ) {
					return false;
				}
				self::$held_locks[ $option_name ]['depth']++;
				return true;
			}

			$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'tcbvm_', true );
			$value = array(
				'token'   => (string) $token,
				'expires' => time() + self::LOCK_TTL,
			);

			for ( $attempt = 0; $attempt < 20; $attempt++ ) {
				if ( add_option( $option_name, $value, '', false ) ) {
					self::$held_locks[ $option_name ] = array(
						'value'        => $value,
						'depth'        => 1,
						'refreshed_at' => time(),
					);
					return true;
				}

				$current = get_option( $option_name, false );
				if ( is_array( $current ) && ! empty( $current['expires'] ) && (int) $current['expires'] < time() ) {
					self::delete_lock_option_if_value_matches( $option_name, $current );
					continue;
				}
				usleep( 100000 );
			}

			return false;
		}

		private static function unlock_scope( $scope ) {
			$option_name = self::lock_option_name( $scope );
			if ( empty( self::$held_locks[ $option_name ] ) ) {
				return;
			}

			self::$held_locks[ $option_name ]['depth']--;
			if ( self::$held_locks[ $option_name ]['depth'] > 0 ) {
				return;
			}

			$value = self::$held_locks[ $option_name ]['value'];
			unset( self::$held_locks[ $option_name ] );
			self::delete_lock_option_if_value_matches( $option_name, $value );
		}

		/** پاک‌کردن شرطی از روی مقدار دقیق مانع حذف قفل تازهٔ درخواست دیگری می‌شود. */
		private static function delete_lock_option_if_value_matches( $option_name, $value ) {
			global $wpdb;
			if ( isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) ) {
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					$option_name,
					maybe_serialize( $value )
				)
				);
			}
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $option_name, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
			}
		}

		public static function lock_run( $run_id ) {
			if ( ! self::valid_run_id( $run_id ) ) {
				return false;
			}
			return self::lock_scope( 'run:' . $run_id );
		}

		public static function unlock_run( $run_id ) {
			if ( self::valid_run_id( $run_id ) ) {
				self::unlock_scope( 'run:' . $run_id );
			}
		}

		public static function lock_product( $product_id ) {
			$product_id = absint( $product_id );
			return $product_id && self::lock_scope( 'product:' . $product_id );
		}

		public static function unlock_product( $product_id ) {
			$product_id = absint( $product_id );
			if ( $product_id ) {
				self::unlock_scope( 'product:' . $product_id );
			}
		}

		private static function refresh_scope_lock( $scope ) {
			$option_name = self::lock_option_name( $scope );
			if ( empty( self::$held_locks[ $option_name ] ) ) {
				return false;
			}
			global $wpdb;
			if ( ! isset( $wpdb->options ) ) {
				return false;
			}
			$old_value = self::$held_locks[ $option_name ]['value'];
			$stored    = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name ) );
			$current   = maybe_unserialize( $stored );
			if ( ! is_array( $current ) || empty( $current['token'] ) || $current['token'] !== $old_value['token'] ) {
				return false;
			}
			$new_value            = $current;
			$new_value['expires'] = time() + self::LOCK_TTL;
			$updated              = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					maybe_serialize( $new_value ),
					$option_name,
					maybe_serialize( $current )
				)
			);
			if ( false === $updated ) {
				return false;
			}
			if ( 0 === (int) $updated ) {
				$verified = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name ) ) );
				if ( ! is_array( $verified ) || empty( $verified['token'] ) || $verified['token'] !== $old_value['token'] || (int) $verified['expires'] < (int) $new_value['expires'] ) {
					return false;
				}
				$new_value = $verified;
			}
			self::$held_locks[ $option_name ]['value']        = $new_value;
			self::$held_locks[ $option_name ]['refreshed_at'] = time();
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $option_name, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
			}
			return true;
		}

		public static function refresh_run_lock( $run_id ) {
			return self::valid_run_id( $run_id ) && self::refresh_scope_lock( 'run:' . $run_id );
		}

		public static function refresh_product_lock( $product_id ) {
			$product_id = absint( $product_id );
			return $product_id && self::refresh_scope_lock( 'product:' . $product_id );
		}

		private static function new_run_id() {
			for ( $i = 0; $i < 5; $i++ ) {
				$random = function_exists( 'wp_generate_password' ) ? wp_generate_password( 12, false, false ) : uniqid();
				$run_id = 'run_' . gmdate( 'Ymd_His' ) . '_' . $random;
				if ( false === get_option( 'tcbvm_snap_' . $run_id, false ) ) {
					return $run_id;
				}
			}
			return '';
		}

		/**
		 * ایجاد نشست جدید. تاریخچهٔ مشترک با قفل محافظت می‌شود و snapshot ابتدا ساخته می‌شود.
		 *
		 * @return string|false شناسهٔ اجرا، یا false در صورت شکست ثبت.
		 */
		public static function create_run_session( $operation_name, array $product_ids, array $extra_meta = array() ) {
			if ( ! self::lock_scope( 'history' ) ) {
				return false;
			}

			try {
				$run_id = self::new_run_id();
				if ( ! $run_id ) {
					return false;
				}

				$user       = wp_get_current_user();
				$user_login = ( $user && $user->exists() ) ? $user->user_login : 'مدیر سیستم';
				$clean_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
				if ( empty( $clean_ids ) ) {
					return false;
				}
				$snap_key = 'tcbvm_snap_' . $run_id;
				$initial    = array(
					'schema_version' => self::SNAPSHOT_VERSION,
					'run_id'         => $run_id,
					'snapshots'      => array(),
					'created_vars'   => array(),
				);

				if ( ! add_option( $snap_key, $initial, '', false ) ) {
					return false;
				}

				$runs = get_option( self::OPTION_RUNS_LIST, array() );
				if ( ! is_array( $runs ) ) {
					$runs = array();
				}

				$runs[ $run_id ] = array(
					'run_id'         => $run_id,
					'operation'      => sanitize_text_field( $operation_name ),
					'created_at'     => current_time( 'mysql' ),
					'completed_at'   => '',
					'user_login'     => $user_login,
					'total_products' => count( $clean_ids ),
					'product_ids'    => $clean_ids,
					'status'         => 'in_progress',
					'created_count'  => 0,
					'deleted_count'  => 0,
					'meta'           => $extra_meta,
					'items'          => array(),
				);

				$old_run_ids = array();
				if ( count( $runs ) > self::MAX_RUNS_SAVED ) {
					foreach ( array_keys( $runs ) as $candidate_id ) {
						if ( count( $runs ) <= self::MAX_RUNS_SAVED ) {
							break;
						}
						if ( $run_id === $candidate_id || 'in_progress' === ( isset( $runs[ $candidate_id ]['status'] ) ? $runs[ $candidate_id ]['status'] : '' ) ) {
							continue;
						}
						$candidate_lock = get_option( self::lock_option_name( 'run:' . $candidate_id ), false );
						if ( is_array( $candidate_lock ) && ! empty( $candidate_lock['token'] ) && ! empty( $candidate_lock['expires'] ) && (int) $candidate_lock['expires'] >= time() ) {
							continue;
						}
						unset( $runs[ $candidate_id ] );
						$old_run_ids[] = $candidate_id;
					}
				}

				$saved = update_option( self::OPTION_RUNS_LIST, $runs, false );
				$check = get_option( self::OPTION_RUNS_LIST, array() );
				if ( ! $saved && ( ! is_array( $check ) || ! isset( $check[ $run_id ] ) ) ) {
					delete_option( $snap_key );
					return false;
				}

				foreach ( $old_run_ids as $old_run_id ) {
					$old_lock = get_option( self::lock_option_name( 'run:' . $old_run_id ), false );
					if ( is_array( $old_lock ) && ! empty( $old_lock['token'] ) && ! empty( $old_lock['expires'] ) && (int) $old_lock['expires'] >= time() ) {
						continue;
					}
					self::delete_run_snapshot_payloads( $old_run_id );
					delete_option( 'tcbvm_snap_' . $old_run_id );
				}
				self::$created_vars[ $run_id ] = array();
				return $run_id;
			} finally {
				self::unlock_scope( 'history' );
			}
		}

		public static function run_is_active( $run_id ) {
			if ( ! self::valid_run_id( $run_id ) ) {
				return false;
			}
			$run  = self::get_run( $run_id );
			$snap = get_option( 'tcbvm_snap_' . $run_id, false );
			return is_array( $run ) && isset( $run['run_id'], $run['status'] ) && $run_id === $run['run_id'] && 'in_progress' === $run['status']
				&& is_array( $snap ) && isset( $snap['run_id'] ) && $run_id === $snap['run_id'];
		}

		/**
		 * ثبت snapshot کامل والد و variationها. در شکست هر مرحله، عملیات باید پیش از تغییر متوقف شود.
		 */
		public static function snapshot_product( $run_id, $product_id ) {
			$product_id         = absint( $product_id );
			$product_lock_option = self::lock_option_name( 'product:' . $product_id );
			if ( ! $product_id || ! self::valid_run_id( $run_id ) || empty( self::$held_locks[ $product_lock_option ] ) || ! self::lock_run( $run_id ) ) {
				return false;
			}

			try {
				if ( ! self::refresh_run_lock( $run_id ) || ! self::run_is_active( $run_id ) || ! self::run_contains_product( $run_id, $product_id ) ) {
					return false;
				}
				$snap_key  = 'tcbvm_snap_' . $run_id;
				$snap_data = get_option( $snap_key, array() );
				if ( ! is_array( $snap_data ) || ! isset( $snap_data['run_id'], $snap_data['schema_version'], $snap_data['snapshots'] ) || ! is_array( $snap_data['snapshots'] ) || $run_id !== $snap_data['run_id'] || self::SNAPSHOT_VERSION !== (int) $snap_data['schema_version'] ) {
					return false;
				}
				if ( isset( $snap_data['snapshots'][ $product_id ] ) ) {
					$existing_snapshot = get_option( self::product_snapshot_option_name( $run_id, $product_id ), false );
					$decoded_snapshot = self::decode_product_snapshot( $existing_snapshot );
					return is_array( $decoded_snapshot ) && self::is_valid_product_snapshot( $product_id, $decoded_snapshot ) && self::refresh_run_lock( $run_id ) && self::refresh_product_lock( $product_id );
				}

				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					return false;
				}

				$product_snap = array(
					'type'              => $product->get_type(),
					'attribute_objects' => self::snapshot_attributes( $product->get_attributes() ),
					'terms'             => array(),
					'variations'        => array(),
				);

				foreach ( (array) get_object_taxonomies( 'product', 'names' ) as $taxonomy ) {
					$term_ids = wp_get_object_terms( $product_id, $taxonomy, array( 'fields' => 'ids' ) );
					if ( is_wp_error( $term_ids ) ) {
						return false;
					}
					$product_snap['terms'][ $taxonomy ] = array_values( array_map( 'absint', (array) $term_ids ) );
				}
				$estimated_snapshot_bytes = strlen( serialize( $product_snap ) );
				if ( $estimated_snapshot_bytes > self::MAX_SNAPSHOT_BYTES ) {
					return false;
				}

				if ( $product->is_type( 'variable' ) ) {
					foreach ( (array) $product->get_children() as $variation_index => $variation_id ) {
						if ( 0 === ( $variation_index % 10 ) ) {
							if ( ! self::refresh_run_lock( $run_id ) ) {
								return false;
							}
							$product_lock_option = self::lock_option_name( 'product:' . $product_id );
							if ( isset( self::$held_locks[ $product_lock_option ] ) && ! self::refresh_product_lock( $product_id ) ) {
								return false;
							}
						}
						$variation_id = absint( $variation_id );
						$variation    = wc_get_product( $variation_id );
						if ( ! $variation || ! $variation->is_type( 'variation' ) || absint( $variation->get_parent_id() ) !== $product_id ) {
							return false;
						}
						$variation_snap = self::snapshot_variation( $variation, $product_id );
						if ( ! is_array( $variation_snap ) ) {
							return false;
						}
					$entry_bytes = strlen( serialize( array( $variation_id => $variation_snap ) ) ) + 32;
					if ( $estimated_snapshot_bytes > self::MAX_SNAPSHOT_BYTES - $entry_bytes ) {
						return false;
					}
					$estimated_snapshot_bytes += $entry_bytes;
					$product_snap['variations'][ $variation_id ] = $variation_snap;
					}
				}

				$product_lock_option = self::lock_option_name( 'product:' . $product_id );
				if ( ! self::refresh_run_lock( $run_id ) || ( isset( self::$held_locks[ $product_lock_option ] ) && ! self::refresh_product_lock( $product_id ) ) ) {
					return false;
				}
				$encoded_snapshot = self::encode_product_snapshot( $product_snap );
				if ( ! is_array( $encoded_snapshot ) ) {
					return false;
				}
				$product_snap_key = self::product_snapshot_option_name( $run_id, $product_id );
				// حذف payload یتیم از تلاش قبلی؛ تا index شدن، هیچ تغییری روی محصول مجاز نیست.
				delete_option( $product_snap_key );
				if ( ! add_option( $product_snap_key, $encoded_snapshot, '', false ) ) {
					return false;
				}
				if ( ! self::refresh_run_lock( $run_id ) || ! self::refresh_product_lock( $product_id ) ) {
					delete_option( $product_snap_key );
					return false;
				}

				$snap_data['schema_version']          = self::SNAPSHOT_VERSION;
				$snap_data['snapshots'][ $product_id ] = true;
				$saved   = update_option( $snap_key, $snap_data, false );
				$check   = get_option( $snap_key, array() );
				$indexed = is_array( $check ) && isset( $check['run_id'], $check['schema_version'], $check['snapshots'][ $product_id ] )
					&& $run_id === $check['run_id'] && self::SNAPSHOT_VERSION === (int) $check['schema_version'] && true === $check['snapshots'][ $product_id ];
				if ( $indexed ) {
					return self::refresh_run_lock( $run_id ) && self::refresh_product_lock( $product_id );
				}
				if ( ! $saved ) {
					delete_option( $product_snap_key );
				}
				return false;
			} finally {
				self::unlock_run( $run_id );
			}
		}

		private static function snapshot_attributes( $attributes ) {
			$out = array();
			foreach ( (array) $attributes as $key => $attribute ) {
				if ( $attribute instanceof WC_Product_Attribute ) {
					$out[ $key ] = $attribute->get_data();
				} elseif ( is_array( $attribute ) ) {
					$out[ $key ] = $attribute;
				}
			}
			return $out;
		}

		/** فشرده‌سازی هر محصول جداگانه، برای کاهش حجم دیتابیس و جلوگیری از نگهداری snapshot کل سایت در حافظه. */
		private static function encode_product_snapshot( array $snapshot ) {
			$serialized = serialize( $snapshot );
			if ( strlen( $serialized ) > self::MAX_SNAPSHOT_BYTES ) {
				return false;
			}
			if ( function_exists( 'gzcompress' ) ) {
				$compressed = gzcompress( $serialized, 3 );
				if ( false !== $compressed ) {
					$payload = base64_encode( $compressed );
					if ( strlen( $payload ) > self::MAX_SNAPSHOT_BYTES ) {
						return false;
					}
					return array(
						'encoding' => 'gz+b64',
						'payload'  => $payload,
					);
				}
			}
			$payload = base64_encode( $serialized );
			if ( strlen( $payload ) > self::MAX_SNAPSHOT_BYTES ) {
				return false;
			}
			return array(
				'encoding' => 'php+b64',
				'payload'  => $payload,
			);
		}

		private static function decode_product_snapshot( $stored ) {
			if ( ! is_array( $stored ) || empty( $stored['encoding'] ) || ! isset( $stored['payload'] ) || ! is_string( $stored['payload'] ) || strlen( $stored['payload'] ) > self::MAX_SNAPSHOT_BYTES ) {
				return false;
			}
			$payload = base64_decode( $stored['payload'], true );
			if ( false === $payload ) {
				return false;
			}
			if ( 'gz+b64' === $stored['encoding'] ) {
				if ( ! function_exists( 'gzuncompress' ) ) {
					return false;
				}
				$serialized = @gzuncompress( $payload, self::MAX_SNAPSHOT_BYTES );
			} elseif ( 'php+b64' === $stored['encoding'] ) {
				$serialized = $payload;
			} else {
				return false;
			}
			if ( ! is_string( $serialized ) || strlen( $serialized ) > self::MAX_SNAPSHOT_BYTES ) {
				return false;
			}
			$snapshot = @unserialize( $serialized, array( 'allowed_classes' => true ) );
			return is_array( $snapshot ) ? $snapshot : false;
		}

		private static function snapshot_variation( $variation, $product_id ) {
			$post = get_post( $variation->get_id(), ARRAY_A );
			if ( ! is_array( $post ) ) {
				return false;
			}

			$getters = array(
				'attributes'        => 'get_attributes',
				'regular_price'     => 'get_regular_price',
				'sale_price'        => 'get_sale_price',
				'price'             => 'get_price',
				'stock_status'      => 'get_stock_status',
				'manage_stock'      => 'get_manage_stock',
				'stock_quantity'    => 'get_stock_quantity',
				'sku'               => 'get_sku',
				'status'            => 'get_status',
				'image_id'          => 'get_image_id',
				'tax_status'        => 'get_tax_status',
				'tax_class'         => 'get_tax_class',
				'shipping_class_id' => 'get_shipping_class_id',
				'weight'            => 'get_weight',
				'length'            => 'get_length',
				'width'             => 'get_width',
				'height'            => 'get_height',
				'backorders'        => 'get_backorders',
				'low_stock_amount'  => 'get_low_stock_amount',
				'virtual'           => 'get_virtual',
				'downloadable'      => 'get_downloadable',
				'download_limit'    => 'get_download_limit',
				'download_expiry'   => 'get_download_expiry',
				'description'       => 'get_description',
				'menu_order'        => 'get_menu_order',
				'post_password'     => 'get_post_password',
				'date_on_sale_from' => 'get_date_on_sale_from',
				'date_on_sale_to'   => 'get_date_on_sale_to',
			);
			if ( method_exists( $variation, 'get_global_unique_id' ) ) {
				$getters['global_unique_id'] = 'get_global_unique_id';
			}

			$props = array( 'parent_id' => absint( $product_id ) );
			foreach ( $getters as $key => $getter ) {
				if ( ! method_exists( $variation, $getter ) ) {
					continue;
				}
				$value = $variation->{$getter}( 'edit' );
				if ( false !== strpos( $key, 'date_' ) && is_object( $value ) && method_exists( $value, 'date' ) ) {
					$value = $value->date( 'Y-m-d H:i:s' );
				} elseif ( $value instanceof DateTimeInterface ) {
					$value = $value->format( 'Y-m-d H:i:s' );
				}
				$props[ $key ] = $value;
			}

			$post_fields = array();
			foreach ( array( 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_excerpt', 'post_title', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_mime_type', 'to_ping', 'pinged', 'guid', 'comment_count', 'menu_order' ) as $field ) {
				if ( array_key_exists( $field, $post ) ) {
					$post_fields[ $field ] = $post[ $field ];
				}
			}

			$terms = array();
			foreach ( (array) get_object_taxonomies( 'product_variation', 'names' ) as $taxonomy ) {
				$term_ids = wp_get_object_terms( $variation->get_id(), $taxonomy, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $term_ids ) ) {
					return false;
				}
				$terms[ $taxonomy ] = array_values( array_map( 'absint', (array) $term_ids ) );
			}

			return array(
				'id'          => absint( $variation->get_id() ),
				'props'       => $props,
				'post_fields' => $post_fields,
				'meta'        => get_post_meta( $variation->get_id() ),
				'terms'       => $terms,
			);
		}

		/** run_id صریح است؛ نشان متا هم در save همان variation ذخیره می‌شود تا قطع وسط batch قابل‌بازیابی باشد. */
		public static function track_created_variation( $run_id, $product_id, $variation_id ) {
			$product_id          = absint( $product_id );
			$variation_id        = absint( $variation_id );
			$product_lock_option = self::lock_option_name( 'product:' . $product_id );
			if ( ! $product_id || ! $variation_id || ! self::valid_run_id( $run_id ) || empty( self::$held_locks[ $product_lock_option ] ) || ! self::lock_run( $run_id ) ) {
				return false;
			}
			try {
				if ( ! self::run_is_active( $run_id ) || ! self::run_contains_product( $run_id, $product_id ) ) {
					return false;
				}
				$snap_data = get_option( 'tcbvm_snap_' . $run_id, false );
				if ( ! is_array( $snap_data ) || ! isset( $snap_data['run_id'], $snap_data['schema_version'], $snap_data['snapshots'] ) || ! is_array( $snap_data['snapshots'] ) || ! isset( $snap_data['snapshots'][ $product_id ] ) || $run_id !== $snap_data['run_id'] || self::SNAPSHOT_VERSION !== (int) $snap_data['schema_version'] || true !== $snap_data['snapshots'][ $product_id ] ) {
					return false;
				}
				self::$created_vars[ $run_id ][ $product_id ][] = $variation_id;
				return true;
			} finally {
				self::unlock_run( $run_id );
			}
		}

		/** ثبت شناسه‌های ساخته‌شده در snapshot اختصاصی همان اجرا؛ marker متا پوششِ fatal بین save و flush را می‌دهد. */
		public static function flush( $run_id = '' ) {
			if ( '' === $run_id ) {
				$run_ids = array_keys( self::$created_vars );
			} elseif ( self::valid_run_id( $run_id ) ) {
				$run_ids = array( $run_id );
			} else {
				return false;
			}
			$all_ok  = true;
			foreach ( $run_ids as $current_run_id ) {
				if ( empty( self::$created_vars[ $current_run_id ] ) ) {
					continue;
				}
				if ( ! self::lock_run( $current_run_id ) ) {
					$all_ok = false;
					continue;
				}
				try {
					if ( ! self::refresh_run_lock( $current_run_id ) ) {
						$all_ok = false;
						continue;
					}
					$snap_key  = 'tcbvm_snap_' . $current_run_id;
					$snap_data = get_option( $snap_key, array() );
					if ( ! is_array( $snap_data ) || ! isset( $snap_data['run_id'], $snap_data['schema_version'], $snap_data['snapshots'] ) || ! is_array( $snap_data['snapshots'] ) || $current_run_id !== $snap_data['run_id'] || self::SNAPSHOT_VERSION !== (int) $snap_data['schema_version'] ) {
						$all_ok = false;
						continue;
					}

					$invalid_tracking = false;
					foreach ( self::$created_vars[ $current_run_id ] as $product_id => $variation_ids ) {
						if ( ! self::run_contains_product( $current_run_id, $product_id ) || ! isset( $snap_data['snapshots'][ $product_id ] ) || true !== $snap_data['snapshots'][ $product_id ] ) {
							$invalid_tracking = true;
							break;
						}
						foreach ( (array) $variation_ids as $variation_id ) {
							if ( ! absint( $variation_id ) ) {
								$invalid_tracking = true;
								break 2;
							}
						}
					}
					if ( $invalid_tracking ) {
						$all_ok = false;
						continue;
					}

					$rows             = array();
					$invalid_saved_row = false;
					foreach ( isset( $snap_data['created_vars'] ) ? (array) $snap_data['created_vars'] : array() as $entry ) {
						if ( ! is_array( $entry ) || empty( $entry['product_id'] ) || empty( $entry['variation_id'] ) ) {
							$invalid_saved_row = true;
							break;
						}
						$product_id   = absint( $entry['product_id'] );
						$variation_id = absint( $entry['variation_id'] );
						if ( ! $variation_id || ! self::run_contains_product( $current_run_id, $product_id ) || ! isset( $snap_data['snapshots'][ $product_id ] ) || true !== $snap_data['snapshots'][ $product_id ] ) {
							$invalid_saved_row = true;
							break;
						}
						$key = $product_id . ':' . $variation_id;
						$rows[ $key ] = array( 'product_id' => $product_id, 'variation_id' => $variation_id );
					}
					if ( $invalid_saved_row ) {
						$all_ok = false;
						continue;
					}
					foreach ( self::$created_vars[ $current_run_id ] as $product_id => $variation_ids ) {
						foreach ( (array) $variation_ids as $variation_id ) {
							$key = absint( $product_id ) . ':' . absint( $variation_id );
							$rows[ $key ] = array( 'product_id' => absint( $product_id ), 'variation_id' => absint( $variation_id ) );
						}
					}
					$snap_data['created_vars'] = array_values( $rows );
					$saved = update_option( $snap_key, $snap_data, false );
					$check = get_option( $snap_key, array() );
					if ( $saved || $check === $snap_data ) {
						unset( self::$created_vars[ $current_run_id ] );
					} else {
						$all_ok = false;
					}
				} finally {
					self::unlock_run( $current_run_id );
				}
			}
			return $all_ok;
		}

		public static function finish_run_session( $run_id, $status = 'completed', array $extra_data = array() ) {
			if ( ! self::valid_run_id( $run_id ) || ! self::lock_run( $run_id ) ) {
				return false;
			}
			try {
				if ( ! self::refresh_run_lock( $run_id ) || ! self::flush( $run_id ) ) {
					return false;
				}
				$snap_data = get_option( 'tcbvm_snap_' . $run_id, false );
				if ( ! is_array( $snap_data ) || ! isset( $snap_data['run_id'], $snap_data['schema_version'] ) || $run_id !== $snap_data['run_id'] || self::SNAPSHOT_VERSION !== (int) $snap_data['schema_version'] ) {
					return false;
				}
				if ( ! self::lock_scope( 'history' ) ) {
					return false;
				}
				try {
					if ( ! self::refresh_run_lock( $run_id ) ) {
						return false;
					}
					$runs = get_option( self::OPTION_RUNS_LIST, array() );
					if ( ! is_array( $runs ) || ! isset( $runs[ $run_id ]['run_id'] ) || $run_id !== $runs[ $run_id ]['run_id'] || 'rolled_back' === ( isset( $runs[ $run_id ]['status'] ) ? $runs[ $run_id ]['status'] : '' ) ) {
						return false;
					}
					$status = sanitize_key( $status );
					if ( ! in_array( $status, array( 'completed', 'completed_with_errors', 'cancelled', 'failed' ), true ) ) {
						$status = 'failed';
					}
					$runs[ $run_id ]['status']       = $status;
					$runs[ $run_id ]['completed_at'] = current_time( 'mysql' );
					if ( isset( $extra_data['created_count'] ) ) {
						$runs[ $run_id ]['created_count'] = absint( $extra_data['created_count'] );
					}
					if ( isset( $extra_data['deleted_count'] ) ) {
						$runs[ $run_id ]['deleted_count'] = absint( $extra_data['deleted_count'] );
					}
					if ( ! empty( $extra_data['items'] ) && is_array( $extra_data['items'] ) ) {
						$runs[ $run_id ]['items'] = $extra_data['items'];
					}
					$saved = update_option( self::OPTION_RUNS_LIST, $runs, false );
					$check = get_option( self::OPTION_RUNS_LIST, array() );
					return $saved || ( is_array( $check ) && isset( $check[ $run_id ] ) && $check[ $run_id ] === $runs[ $run_id ] );
				} finally {
					self::unlock_scope( 'history' );
				}
			} finally {
				self::unlock_run( $run_id );
			}
		}

		public static function get_all_runs() {
			$runs = get_option( self::OPTION_RUNS_LIST, array() );
			if ( ! is_array( $runs ) || empty( $runs ) ) {
				return array();
			}
			return array_values( array_reverse( $runs ) );
		}

		public static function get_run( $run_id ) {
			$runs = get_option( self::OPTION_RUNS_LIST, array() );
			return is_array( $runs ) && isset( $runs[ $run_id ] ) ? $runs[ $run_id ] : null;
		}

		private static function run_contains_product( $run_id, $product_id ) {
			if ( ! isset( self::$run_products[ $run_id ] ) ) {
				$run = self::get_run( $run_id );
				if ( ! is_array( $run ) || ! isset( $run['run_id'], $run['product_ids'] ) || $run_id !== $run['run_id'] || ! is_array( $run['product_ids'] ) ) {
					return false;
				}
				$product_ids = array_filter( array_map( 'absint', $run['product_ids'] ) );
				self::$run_products[ $run_id ] = array_fill_keys( array_values( array_unique( $product_ids ) ), true );
			}
			return isset( self::$run_products[ $run_id ][ absint( $product_id ) ] );
		}

		private static function restore_post_fields( $variation_id, $product_id, array $fields ) {
			$allowed = array( 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_excerpt', 'post_title', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_mime_type', 'to_ping', 'pinged', 'guid', 'comment_count', 'menu_order' );
			$postarr = array( 'ID' => absint( $variation_id ), 'post_parent' => absint( $product_id ), 'edit_date' => true );
			foreach ( $allowed as $field ) {
				if ( array_key_exists( $field, $fields ) ) {
					$postarr[ $field ] = $fields[ $field ];
				}
			}
			$result = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( $result->get_error_message() );
			}
			if ( absint( $result ) !== absint( $variation_id ) ) {
				throw new RuntimeException( 'ذخیرهٔ فیلدهای post variation در وردپرس کامل نشد.' );
			}
		}

		private static function restore_variation_meta( $variation_id, array $snapshot_meta, $run_id, $source_id, $skip_sku = false, $mark_restored = true ) {
			$preserve_keys = $mark_restored ? array( self::RESTORE_RUN_META, self::RESTORE_FROM_META ) : array();
			$current_meta  = get_post_meta( $variation_id );
			foreach ( array_keys( (array) $current_meta ) as $meta_key ) {
				if ( $skip_sku && '_sku' === $meta_key ) {
					delete_post_meta( $variation_id, $meta_key );
				} elseif ( ! array_key_exists( $meta_key, $snapshot_meta ) && ! in_array( $meta_key, $preserve_keys, true ) ) {
					delete_post_meta( $variation_id, $meta_key );
				}
			}
			foreach ( $snapshot_meta as $meta_key => $values ) {
				if ( $skip_sku && '_sku' === $meta_key ) {
					continue;
				}
				delete_post_meta( $variation_id, $meta_key );
				$values = is_array( $values ) ? $values : array( $values );
				foreach ( $values as $value ) {
					if ( false === add_post_meta( $variation_id, $meta_key, $value, false ) ) {
						throw new RuntimeException( 'بازیابی یکی از متاهای variation ناموفق بود: ' . $meta_key );
					}
				}
			}
			if ( $mark_restored ) {
				update_post_meta( $variation_id, self::RESTORE_RUN_META, (string) $run_id );
				update_post_meta( $variation_id, self::RESTORE_FROM_META, absint( $source_id ) );
				if ( (string) get_post_meta( $variation_id, self::RESTORE_RUN_META, true ) !== (string) $run_id || absint( get_post_meta( $variation_id, self::RESTORE_FROM_META, true ) ) !== absint( $source_id ) ) {
					throw new RuntimeException( 'نشان idempotent بازگردانی variation ثبت نشد؛ اجرای دوبارهٔ rollback ممکن است دستی نیاز باشد.' );
				}
			}
		}

		private static function restore_variation_terms( $variation_id, array $terms ) {
			foreach ( (array) get_object_taxonomies( 'product_variation', 'names' ) as $taxonomy ) {
				if ( ! array_key_exists( $taxonomy, $terms ) && taxonomy_exists( $taxonomy ) ) {
					$result = wp_set_object_terms( $variation_id, array(), $taxonomy, false );
					if ( is_wp_error( $result ) ) {
						throw new RuntimeException( $result->get_error_message() );
					}
				}
			}
			foreach ( $terms as $taxonomy => $term_ids ) {
				if ( ! taxonomy_exists( $taxonomy ) ) {
					throw new RuntimeException( 'تاکسونومی variation برای بازگردانی در دسترس نیست: ' . $taxonomy );
				}
				$result = wp_set_object_terms( $variation_id, array_map( 'absint', (array) $term_ids ), $taxonomy, false );
				if ( is_wp_error( $result ) ) {
					throw new RuntimeException( $result->get_error_message() );
				}
			}
		}

		private static function restore_variation( $product_id, array $vdata, $run_id, array &$restored_by_source ) {
			if ( ! class_exists( 'WC_Product_Variation' ) ) {
				throw new RuntimeException( 'کلاس variation ووکامرس در دسترس نیست.' );
			}
			$source_id = isset( $vdata['id'] ) ? absint( $vdata['id'] ) : 0;
			if ( ! $source_id ) {
				throw new RuntimeException( 'شناسهٔ variation در snapshot معتبر نیست.' );
			}
			$variation = wc_get_product( $source_id );
			if ( $variation && ( ! $variation->is_type( 'variation' ) || absint( $variation->get_parent_id() ) !== absint( $product_id ) ) ) {
				throw new RuntimeException( sprintf( 'شناسهٔ variation اصلی #%d اکنون به رکورد یا والد دیگری تعلق دارد؛ برای حفاظت از داده، بازگردانی آن متوقف شد.', $source_id ) );
			}
			$recreated = false;
			if ( isset( $restored_by_source[ $source_id ] ) ) {
				$mapped_variation = $restored_by_source[ $source_id ];
				if ( $variation && absint( $variation->get_id() ) !== absint( $mapped_variation->get_id() ) ) {
					throw new RuntimeException( sprintf( 'برای variation اصلی #%d هم شناسهٔ اصلی و هم نسخهٔ بازسازی‌شده وجود دارد؛ موارد تکراری را بررسی کنید و rollback متوقف شد.', $source_id ) );
				}
				if ( ! $variation ) {
					$variation = $mapped_variation;
					$recreated = true;
				}
			}
			$is_new        = ! $variation;
			$mark_restored = $is_new || $recreated;
			if ( $is_new ) {
				$variation = new WC_Product_Variation();
			}

			$props = isset( $vdata['props'] ) && is_array( $vdata['props'] ) ? $vdata['props'] : array(
				'parent_id'      => $product_id,
				'attributes'     => isset( $vdata['attributes'] ) ? $vdata['attributes'] : array(),
				'regular_price'  => isset( $vdata['regular_price'] ) ? $vdata['regular_price'] : '',
				'sale_price'     => isset( $vdata['sale_price'] ) ? $vdata['sale_price'] : '',
				'price'          => isset( $vdata['price'] ) ? $vdata['price'] : '',
				'stock_status'   => isset( $vdata['stock_status'] ) ? $vdata['stock_status'] : 'instock',
				'manage_stock'   => ! empty( $vdata['manage_stock'] ),
				'stock_quantity' => isset( $vdata['stock_qty'] ) ? $vdata['stock_qty'] : null,
				'sku'            => isset( $vdata['sku'] ) ? $vdata['sku'] : '',
				'status'         => isset( $vdata['status'] ) ? $vdata['status'] : 'publish',
			);
			$setters = array(
				'parent_id'          => 'set_parent_id',
				'attributes'         => 'set_attributes',
				'regular_price'      => 'set_regular_price',
				'sale_price'         => 'set_sale_price',
				'price'              => 'set_price',
				'stock_status'       => 'set_stock_status',
				'manage_stock'       => 'set_manage_stock',
				'stock_quantity'     => 'set_stock_quantity',
				'status'             => 'set_status',
				'image_id'           => 'set_image_id',
				'tax_status'         => 'set_tax_status',
				'tax_class'          => 'set_tax_class',
				'shipping_class_id'  => 'set_shipping_class_id',
				'backorders'         => 'set_backorders',
				'low_stock_amount'   => 'set_low_stock_amount',
				'weight'             => 'set_weight',
				'length'             => 'set_length',
				'width'              => 'set_width',
				'height'             => 'set_height',
				'virtual'            => 'set_virtual',
				'downloadable'       => 'set_downloadable',
				'download_limit'     => 'set_download_limit',
				'download_expiry'    => 'set_download_expiry',
				'description'        => 'set_description',
				'menu_order'         => 'set_menu_order',
				'post_password'      => 'set_post_password',
				'date_on_sale_from'  => 'set_date_on_sale_from',
				'date_on_sale_to'    => 'set_date_on_sale_to',
				'global_unique_id'   => 'set_global_unique_id',
			);
			foreach ( $setters as $key => $setter ) {
				if ( array_key_exists( $key, $props ) && method_exists( $variation, $setter ) && 'sku' !== $key ) {
					$variation->{$setter}( $props[ $key ] );
				}
			}

			$sku_conflict = false;
			$sku = isset( $props['sku'] ) ? (string) $props['sku'] : '';
			if ( method_exists( $variation, 'set_sku' ) ) {
				if ( '' === $sku ) {
					$variation->set_sku( '' );
				} else {
					$owner = function_exists( 'wc_get_product_id_by_sku' ) ? absint( wc_get_product_id_by_sku( $sku ) ) : 0;
					if ( $owner && $owner !== absint( $variation->get_id() ) ) {
						$sku_conflict = true;
					} else {
						$variation->set_sku( $sku );
					}
				}
			}
			if ( $is_new ) {
				$variation->update_meta_data( self::RESTORE_RUN_META, (string) $run_id );
				$variation->update_meta_data( self::RESTORE_FROM_META, $source_id );
			}

			$saved_id = $variation->save();
			$variation_id = absint( $variation->get_id() ? $variation->get_id() : $saved_id );
			if ( ! $variation_id ) {
				throw new RuntimeException( 'ووکامرس variation اسنپ‌شات را ذخیره نکرد.' );
			}
			if ( $mark_restored ) {
				$restored_by_source[ $source_id ] = $variation;
			}

			if ( ! empty( $vdata['post_fields'] ) && is_array( $vdata['post_fields'] ) ) {
				self::restore_post_fields( $variation_id, $product_id, $vdata['post_fields'] );
			}
			if ( isset( $vdata['meta'] ) && is_array( $vdata['meta'] ) ) {
				self::restore_variation_meta( $variation_id, $vdata['meta'], $run_id, $source_id, $sku_conflict, $mark_restored );
			}
			if ( isset( $vdata['terms'] ) && is_array( $vdata['terms'] ) ) {
				self::restore_variation_terms( $variation_id, $vdata['terms'] );
			}
			clean_post_cache( $variation_id );
			$restored = wc_get_product( $variation_id );
			if ( ! $restored || ! $restored->is_type( 'variation' ) || absint( $restored->get_parent_id() ) !== absint( $product_id ) ) {
				throw new RuntimeException( 'variation پس از بازسازی از ووکامرس بارگذاری نشد یا والد آن نادرست است.' );
			}
			if ( $sku_conflict ) {
				throw new RuntimeException( sprintf( 'SKU «%s» به محصول دیگری تعلق گرفته است؛ سایر اطلاعات بازیابی شد، اما SKU را آزاد و بازگردانی را دوباره اجرا کنید.', $sku ) );
			}
			return $restored;
		}

		private static function restore_parent_attributes( $product_id, $product, array $snap ) {
			if ( isset( $snap['attribute_objects'] ) && is_array( $snap['attribute_objects'] ) && class_exists( 'WC_Product_Attribute' ) ) {
				$attributes = array();
				foreach ( $snap['attribute_objects'] as $key => $data ) {
					if ( $data instanceof WC_Product_Attribute ) {
						$attributes[ $key ] = $data;
						continue;
					}
					if ( ! is_array( $data ) || ! isset( $data['name'] ) ) {
						continue;
					}
					$attribute = new WC_Product_Attribute();
					$attribute->set_id( isset( $data['id'] ) ? absint( $data['id'] ) : 0 );
					$attribute->set_name( (string) $data['name'] );
					$attribute->set_options( isset( $data['options'] ) ? (array) $data['options'] : array() );
					$attribute->set_position( isset( $data['position'] ) ? absint( $data['position'] ) : 0 );
					$attribute->set_visible( ! empty( $data['visible'] ) );
					$attribute->set_variation( ! empty( $data['variation'] ) );
					$attributes[ $key ] = $attribute;
				}
				$product->set_attributes( $attributes );
				$product->save();
			} elseif ( array_key_exists( 'attributes', $snap ) ) {
				update_post_meta( $product_id, '_product_attributes', $snap['attributes'] );
			}
		}

		private static function restore_parent_terms( $product_id, array $terms ) {
			foreach ( (array) get_object_taxonomies( 'product', 'names' ) as $taxonomy ) {
				if ( ! array_key_exists( $taxonomy, $terms ) && taxonomy_exists( $taxonomy ) ) {
					$result = wp_set_object_terms( $product_id, array(), $taxonomy, false );
					if ( is_wp_error( $result ) ) {
						throw new RuntimeException( $result->get_error_message() );
					}
				}
			}
			foreach ( $terms as $taxonomy => $term_ids ) {
				if ( ! taxonomy_exists( $taxonomy ) ) {
					throw new RuntimeException( 'تاکسونومی محصول برای بازگردانی در دسترس نیست: ' . $taxonomy );
				}
				$result = wp_set_object_terms( $product_id, array_map( 'absint', (array) $term_ids ), $taxonomy, false );
				if ( is_wp_error( $result ) ) {
					throw new RuntimeException( $result->get_error_message() );
				}
			}
		}

		private static function is_valid_product_snapshot( $product_id, array $snapshot ) {
			if ( empty( $snapshot['type'] ) || ! is_string( $snapshot['type'] ) || ! isset( $snapshot['attribute_objects'], $snapshot['terms'], $snapshot['variations'] ) || ! is_array( $snapshot['attribute_objects'] ) || ! is_array( $snapshot['terms'] ) || ! is_array( $snapshot['variations'] ) ) {
				return false;
			}

			foreach ( $snapshot['attribute_objects'] as $key => $attribute ) {
				if ( ! is_string( $key ) || ! is_array( $attribute ) || ! isset( $attribute['name'], $attribute['options'] ) || ! is_string( $attribute['name'] ) || '' === $attribute['name'] || ! is_array( $attribute['options'] ) ) {
					return false;
				}
				foreach ( $attribute['options'] as $option ) {
					if ( ! is_scalar( $option ) && null !== $option ) {
						return false;
					}
				}
				if ( isset( $attribute['id'] ) && ! is_numeric( $attribute['id'] ) ) {
					return false;
				}
				if ( isset( $attribute['position'] ) && ! is_numeric( $attribute['position'] ) ) {
					return false;
				}
			}
			foreach ( $snapshot['terms'] as $taxonomy => $term_ids ) {
				if ( ! is_string( $taxonomy ) || ! is_array( $term_ids ) ) {
					return false;
				}
				foreach ( $term_ids as $term_id ) {
					if ( ! is_numeric( $term_id ) || ! absint( $term_id ) ) {
						return false;
					}
				}
			}
			foreach ( $snapshot['variations'] as $variation_key => $variation ) {
				$variation_id = absint( $variation_key );
				if ( ! $variation_id || ! is_array( $variation ) || empty( $variation['id'] ) || absint( $variation['id'] ) !== $variation_id || ! isset( $variation['props'], $variation['post_fields'], $variation['meta'], $variation['terms'] ) || ! is_array( $variation['props'] ) || ! isset( $variation['props']['parent_id'], $variation['props']['attributes'] ) || absint( $variation['props']['parent_id'] ) !== absint( $product_id ) || ! is_array( $variation['props']['attributes'] ) || ! is_array( $variation['post_fields'] ) || ! is_array( $variation['meta'] ) || ! is_array( $variation['terms'] ) ) {
					return false;
				}
				foreach ( $variation['props'] as $prop_key => $prop_value ) {
					if ( 'attributes' === $prop_key ) {
						foreach ( $prop_value as $attribute_value ) {
							if ( ! is_scalar( $attribute_value ) && null !== $attribute_value ) {
								return false;
							}
						}
					} elseif ( ! is_scalar( $prop_value ) && null !== $prop_value ) {
						return false;
					}
				}
				foreach ( $variation['post_fields'] as $field_value ) {
					if ( ! is_scalar( $field_value ) && null !== $field_value ) {
						return false;
					}
				}
				foreach ( $variation['meta'] as $meta_values ) {
					if ( ! is_array( $meta_values ) ) {
						return false;
					}
				}
				foreach ( $variation['terms'] as $taxonomy => $term_ids ) {
					if ( ! is_string( $taxonomy ) || ! is_array( $term_ids ) ) {
						return false;
					}
					foreach ( $term_ids as $term_id ) {
						if ( ! is_numeric( $term_id ) || ! absint( $term_id ) ) {
							return false;
						}
					}
				}
			}
			return true;
		}

		private static function collect_snapshot_taxonomy_dependencies( array $snapshot, array &$taxonomies, array &$term_ids ) {
			$term_sets = array( $snapshot['terms'] );
			foreach ( $snapshot['variations'] as $variation ) {
				$term_sets[] = $variation['terms'];
			}
			foreach ( $term_sets as $terms ) {
				foreach ( $terms as $taxonomy => $ids ) {
					$taxonomies[ $taxonomy ] = true;
					foreach ( $ids as $term_id ) {
						$term_id = absint( $term_id );
						$term_ids[ $taxonomy ][ $term_id ] = $term_id;
					}
				}
			}
			foreach ( $snapshot['attribute_objects'] as $attribute ) {
				if ( empty( $attribute['id'] ) ) {
					continue;
				}
				$taxonomy = $attribute['name'];
				$taxonomies[ $taxonomy ] = true;
				foreach ( $attribute['options'] as $option ) {
					if ( is_numeric( $option ) && absint( $option ) ) {
						$term_id = absint( $option );
						$term_ids[ $taxonomy ][ $term_id ] = $term_id;
					}
				}
			}
		}

		private static function snapshot_taxonomy_dependencies_are_available( array $taxonomies, array $term_ids ) {
			foreach ( $taxonomies as $taxonomy => $unused ) {
				if ( ! taxonomy_exists( $taxonomy ) ) {
					return false;
				}
			}
			foreach ( $term_ids as $taxonomy => $ids ) {
				if ( ! taxonomy_exists( $taxonomy ) ) {
					return false;
				}
				$chunks = array_chunk( array_values( $ids ), 500 );
				foreach ( $chunks as $chunk ) {
					$found = get_terms( array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'include'    => $chunk,
						'fields'     => 'ids',
					) );
					if ( is_wp_error( $found ) || count( array_unique( array_map( 'absint', (array) $found ) ) ) !== count( $chunk ) ) {
						return false;
					}
				}
			}
			return true;
		}

		/** بازگردانی اجرا. Snapshotهای نسخهٔ قدیمی فاقد دادهٔ لازم برای rollback کامل‌اند؛ عمداً fail-closed می‌شوند. */
		public static function rollback_run( $run_id ) {
			if ( ! self::valid_run_id( $run_id ) || ! self::lock_run( $run_id ) ) {
				return array( 'success' => false, 'message' => 'قفل اجرای موردنظر در دسترس نیست؛ احتمالاً عملیات دیگری در جریان است.' );
			}

			try {
				if ( ! self::refresh_run_lock( $run_id ) ) {
					return array( 'success' => false, 'message' => 'قفل اجرا از دست رفت؛ بازگردانی آغاز نشد.' );
				}
				$runs = get_option( self::OPTION_RUNS_LIST, array() );
				if ( ! is_array( $runs ) || ! isset( $runs[ $run_id ] ) ) {
					return array( 'success' => false, 'message' => 'شناسه اجرای موردنظر در تاریخچه یافت نشد.' );
				}
				$run_data = $runs[ $run_id ];
				if ( ! is_array( $run_data ) || ! isset( $run_data['run_id'] ) || $run_id !== $run_data['run_id'] ) {
					return array( 'success' => false, 'message' => 'رکورد تاریخچه با شناسهٔ اجرای درخواستی هم‌خوان نیست؛ بازگردانی متوقف شد.' );
				}
				$status   = isset( $run_data['status'] ) ? $run_data['status'] : '';
				if ( 'rolled_back' === $status ) {
					return array( 'success' => false, 'message' => 'این عملیات قبلاً بازگردانی شده است.' );
				}
				if ( ! class_exists( 'WC_Product_Variation' ) || ! class_exists( 'WC_Product_Attribute' ) || ! class_exists( 'WC_Product_Variable' ) || ! function_exists( 'wc_get_product' ) ) {
					return array( 'success' => false, 'message' => 'WooCommerce CRUD در دسترس نیست؛ بازگردانی پیش از هر تغییری متوقف شد.' );
				}

				$snap_data = get_option( 'tcbvm_snap_' . $run_id, array() );
				if ( ! is_array( $snap_data ) || ! isset( $snap_data['snapshots'] ) || ! is_array( $snap_data['snapshots'] ) ) {
					return array( 'success' => false, 'message' => 'اطلاعات اسنپ‌شات این اجرا یافت نشد یا ساختار آن نامعتبر است.' );
				}
				if ( ! isset( $snap_data['run_id'] ) || $run_id !== $snap_data['run_id'] ) {
					return array( 'success' => false, 'message' => 'شناسهٔ اجرای ذخیره‌شده با snapshot هم‌خوان نیست؛ برای جلوگیری از دسترسی به اجرای دیگر، بازگردانی انجام نشد.' );
				}
				$stored_schema = isset( $snap_data['schema_version'] ) && is_numeric( $snap_data['schema_version'] ) ? (int) $snap_data['schema_version'] : 0;
				if ( self::SNAPSHOT_VERSION !== $stored_schema ) {
					$stored_schema_label = $stored_schema > 0 ? (string) $stored_schema : 'نامشخص/نامعتبر';
					return array(
						'success' => false,
						'message' => sprintf( 'نسخهٔ schema snapshot این اجرا «%s» است؛ این نسخه از افزونه فقط schema %d را برای بازگردانی امن می‌پذیرد. برای جلوگیری از نسبت‌دادن اشتباه variationها و متادیتا، هیچ تغییری انجام نشد.', $stored_schema_label, self::SNAPSHOT_VERSION ),
					);
				}

				if ( ! isset( $run_data['product_ids'] ) || ! is_array( $run_data['product_ids'] ) ) {
					return array( 'success' => false, 'message' => 'فهرست محصولات اجرای تاریخچه نامعتبر است؛ هیچ تغییری انجام نشد.' );
				}
				if ( empty( $snap_data['snapshots'] ) && ( ! isset( $snap_data['created_vars'] ) || ! is_array( $snap_data['created_vars'] ) || ! empty( $snap_data['created_vars'] ) ) ) {
					return array( 'success' => false, 'message' => 'snapshot خالی است اما فهرست variationهای ساخته‌شده تأیید نشد؛ برای جلوگیری از بازگردانی ناقص هیچ تغییری انجام نشد.' );
				}
				$allowed_product_ids = array_fill_keys( array_values( array_unique( array_filter( array_map( 'absint', $run_data['product_ids'] ) ) ) ), true );
				if ( empty( $allowed_product_ids ) ) {
					return array( 'success' => false, 'message' => 'فهرست محصولات اجرای تاریخچه خالی یا نامعتبر است؛ هیچ تغییری انجام نشد.' );
				}
				$stored_snapshots       = $snap_data['snapshots'];
				$validated_product_ids   = array();
				$validated_variation_ids = array();
				foreach ( $stored_snapshots as $stored_product_id => $is_indexed ) {
					$product_id = absint( $stored_product_id );
					if ( ! $product_id || true !== $is_indexed || ! isset( $allowed_product_ids[ $product_id ] ) || isset( $validated_product_ids[ $product_id ] ) ) {
						return array( 'success' => false, 'message' => 'فهرست snapshot محصول خراب یا متعلق به اجرای دیگری است؛ هیچ تغییری انجام نشد.' );
					}
					$stored_snapshot  = get_option( self::product_snapshot_option_name( $run_id, $product_id ), false );
					$product_snapshot = self::decode_product_snapshot( $stored_snapshot );
					if ( ! is_array( $product_snapshot ) || ! self::is_valid_product_snapshot( $product_id, $product_snapshot ) ) {
						return array( 'success' => false, 'message' => 'یکی از snapshotهای فشرده خراب یا ناقص است؛ برای جلوگیری از بازگردانی ناقص هیچ تغییری انجام نشد.' );
					}
					if ( ! wc_get_product( $product_id ) ) {
						return array( 'success' => false, 'message' => sprintf( 'محصول والد #%d دیگر در دسترس نیست؛ هیچ محصولی بازگردانی نشد.', $product_id ) );
					}
					foreach ( $product_snapshot['variations'] as $variation_data ) {
						$source_id = absint( $variation_data['id'] );
						if ( isset( $validated_variation_ids[ $source_id ] ) ) {
							return array( 'success' => false, 'message' => sprintf( 'شناسهٔ variation #%d در چند snapshot تکرار شده است؛ هیچ محصولی بازگردانی نشد.', $source_id ) );
						}
						$validated_variation_ids[ $source_id ] = $product_id;
						$current_product = wc_get_product( $source_id );
						if ( $current_product && ( ! $current_product->is_type( 'variation' ) || absint( $current_product->get_parent_id() ) !== $product_id ) ) {
							return array( 'success' => false, 'message' => sprintf( 'شناسهٔ variation اصلی #%d اکنون به رکورد یا والد دیگری تعلق دارد؛ هیچ محصولی بازگردانی نشد.', $source_id ) );
						}
					}
					$required_taxonomies = array();
					$required_term_ids   = array();
					self::collect_snapshot_taxonomy_dependencies( $product_snapshot, $required_taxonomies, $required_term_ids );
					if ( ! self::snapshot_taxonomy_dependencies_are_available( $required_taxonomies, $required_term_ids ) ) {
						return array( 'success' => false, 'message' => sprintf( 'taxonomy یا term لازم برای snapshot محصول #%d دیگر در دسترس نیست؛ هیچ محصولی بازگردانی نشد.', $product_id ) );
					}
					$validated_product_ids[ $product_id ] = true;
					unset( $required_taxonomies, $required_term_ids, $product_snapshot );
				}

				$created_by_parent = array();
				foreach ( isset( $snap_data['created_vars'] ) ? (array) $snap_data['created_vars'] : array() as $entry ) {
					if ( ! is_array( $entry ) || empty( $entry['product_id'] ) || empty( $entry['variation_id'] ) ) {
						return array( 'success' => false, 'message' => 'فهرست variationهای ساخته‌شده در snapshot ناقص است؛ برای جلوگیری از rollback ناقص هیچ تغییری انجام نشد.' );
					}
					$product_id   = absint( $entry['product_id'] );
					$variation_id = absint( $entry['variation_id'] );
					if ( ! isset( $validated_product_ids[ $product_id ] ) || ! $variation_id ) {
						return array( 'success' => false, 'message' => 'فهرست variationهای ساخته‌شده به محصول همین اجرا وصل نیست؛ هیچ تغییری انجام نشد.' );
					}
					if ( isset( $validated_variation_ids[ $variation_id ] ) ) {
						if ( $validated_variation_ids[ $variation_id ] !== $product_id ) {
							return array( 'success' => false, 'message' => sprintf( 'شناسهٔ variation #%d با snapshot محصول دیگری تداخل دارد؛ هیچ محصولی بازگردانی نشد.', $variation_id ) );
						}
						continue;
					}
					$current_post      = get_post( $variation_id );
					$current_variation = wc_get_product( $variation_id );
					if ( $current_post && ( ! $current_variation || 'product_variation' !== $current_post->post_type || absint( $current_post->post_parent ) !== $product_id || ! $current_variation->is_type( 'variation' ) || absint( $current_variation->get_parent_id() ) !== $product_id || (string) $current_variation->get_meta( self::CREATED_RUN_META, true ) !== (string) $run_id ) ) {
						return array( 'success' => false, 'message' => sprintf( 'رکورد #%d در فهرست ساخته‌های این اجرا هست اما مالکیت/نشان آن تأیید نشد؛ هیچ محصولی بازگردانی نشد.', $variation_id ) );
					}
					$created_by_parent[ $product_id ][ $variation_id ] = $variation_id;
				}

				$errors         = array();
				$restored_count = 0;
				$stop_on_lock   = false;
				foreach ( $stored_snapshots as $product_id => $is_indexed ) {
					$product_id = absint( $product_id );
					if ( ! self::refresh_run_lock( $run_id ) ) {
						$errors[]      = 'قفل اجرا از دست رفت؛ ادامهٔ بازگردانی متوقف شد.';
						$stop_on_lock = true;
						break;
					}
					if ( ! self::lock_product( $product_id ) ) {
						$errors[]      = sprintf( 'محصول #%d در عملیات دیگری قفل است؛ بازگردانی همین‌جا متوقف شد.', $product_id );
						$stop_on_lock = true;
						break;
					}

					try {
						if ( ! self::refresh_product_lock( $product_id ) ) {
							$stop_on_lock = true;
							throw new RuntimeException( 'قفل محصول از دست رفت؛ بازگردانی متوقف شد.' );
						}
						$snap = self::decode_product_snapshot( get_option( self::product_snapshot_option_name( $run_id, $product_id ), false ) );
						if ( ! is_array( $snap ) ) {
							throw new RuntimeException( 'snapshot فشردهٔ این محصول قابل بازخوانی نیست.' );
						}
						$product = wc_get_product( $product_id );
						if ( ! $product ) {
							$errors[] = sprintf( 'محصول والد #%d برای بازگردانی پیدا نشد.', $product_id );
							continue;
						}

						$created_ids       = isset( $created_by_parent[ $product_id ] ) ? $created_by_parent[ $product_id ] : array();
						$restored_by_source = array();
						foreach ( array_keys( $created_ids ) as $created_id ) {
							if ( isset( $snap['variations'][ absint( $created_id ) ] ) ) {
								unset( $created_ids[ $created_id ] );
							}
						}
						// یک پیمایش: بازیابی markerها پس از قطع بین save و flush و ساخت index برای retry کم‌هزینه.
						foreach ( (array) $product->get_children() as $child_index => $candidate_id ) {
							if ( 0 === ( $child_index % 10 ) && ( ! self::refresh_run_lock( $run_id ) || ! self::refresh_product_lock( $product_id ) ) ) {
								$stop_on_lock = true;
								throw new RuntimeException( 'قفل اجرا/محصول هنگام بررسی variationها از دست رفت.' );
							}
							$candidate_id = absint( $candidate_id );
							$candidate    = wc_get_product( $candidate_id );
							if ( ! $candidate || ! $candidate->is_type( 'variation' ) || absint( $candidate->get_parent_id() ) !== $product_id ) {
								continue;
							}
							$restore_source_id = absint( $candidate->get_meta( self::RESTORE_FROM_META, true ) );
							if ( (string) $candidate->get_meta( self::RESTORE_RUN_META, true ) === (string) $run_id && isset( $snap['variations'][ $restore_source_id ] ) ) {
								if ( isset( $restored_by_source[ $restore_source_id ] ) && absint( $restored_by_source[ $restore_source_id ]->get_id() ) !== $candidate_id ) {
									throw new RuntimeException( sprintf( 'برای variation اصلی #%d چند نسخهٔ بازسازی‌شده وجود دارد؛ موارد تکراری را بررسی کنید.', $restore_source_id ) );
								}
								$restored_by_source[ $restore_source_id ] = $candidate;
							}
							if ( ! isset( $snap['variations'][ $candidate_id ] ) && (string) $candidate->get_meta( self::CREATED_RUN_META, true ) === (string) $run_id ) {
								$created_ids[ $candidate_id ] = $candidate_id;
							}
						}

						$created_index = 0;
						foreach ( $created_ids as $variation_id ) {
							if ( 0 === ( $created_index % 10 ) && ( ! self::refresh_run_lock( $run_id ) || ! self::refresh_product_lock( $product_id ) ) ) {
								$stop_on_lock = true;
								throw new RuntimeException( 'قفل اجرا/محصول از دست رفت؛ ادامهٔ حذف متوقف شد.' );
							}
							$created_index++;
							$variation_id = absint( $variation_id );
							$variation    = wc_get_product( $variation_id );
							if ( ! $variation ) {
								continue;
							}
							if ( ! $variation->is_type( 'variation' ) || absint( $variation->get_parent_id() ) !== $product_id || (string) $variation->get_meta( self::CREATED_RUN_META, true ) !== (string) $run_id ) {
								throw new RuntimeException( sprintf( 'مالکیت یا نشان variation #%d با این اجرا هم‌خوان نیست و حذف نشد.', $variation_id ) );
							}
							$variation->delete( true );
							clean_post_cache( $variation_id );
							if ( get_post( $variation_id ) ) {
								throw new RuntimeException( sprintf( 'حذف variation جدید #%d از مسیر CRUD کامل نشد.', $variation_id ) );
							}
						}

						self::restore_parent_attributes( $product_id, $product, $snap );
						if ( isset( $snap['terms'] ) && is_array( $snap['terms'] ) ) {
							self::restore_parent_terms( $product_id, $snap['terms'] );
						}
						if ( ! empty( $snap['type'] ) && taxonomy_exists( 'product_type' ) ) {
							$type_result = wp_set_object_terms( $product_id, sanitize_key( $snap['type'] ), 'product_type', false );
							if ( is_wp_error( $type_result ) ) {
								throw new RuntimeException( $type_result->get_error_message() );
							}
						}
						clean_post_cache( $product_id );

						$restore_index = 0;
						foreach ( $snap['variations'] as $variation_id => $variation_data ) {
							if ( 0 === ( $restore_index % 10 ) && ( ! self::refresh_run_lock( $run_id ) || ! self::refresh_product_lock( $product_id ) ) ) {
								$stop_on_lock = true;
								throw new RuntimeException( 'قفل اجرا/محصول از دست رفت؛ بازسازی variationها متوقف شد.' );
							}
							$restore_index++;
							self::restore_variation( $product_id, $variation_data, $run_id, $restored_by_source );
						}

						clean_post_cache( $product_id );
						$product = wc_get_product( $product_id );
						if ( $product && $product->is_type( 'variable' ) && class_exists( 'WC_Product_Variable' ) ) {
							WC_Product_Variable::sync( $product_id );
						}
						wc_delete_product_transients( $product_id );
						if ( ! self::refresh_run_lock( $run_id ) || ! self::refresh_product_lock( $product_id ) ) {
							$stop_on_lock = true;
							throw new RuntimeException( 'قفل اجرا/محصول پس از بازگردانی محصول از دست رفت.' );
						}
						$restored_count++;
					} catch ( Throwable $e ) {
						$errors[] = sprintf( 'محصول #%d: %s', $product_id, $e->getMessage() );
						if ( false !== strpos( $e->getMessage(), 'قفل' ) || false !== strpos( $e->getMessage(), 'idempotent' ) ) {
							$stop_on_lock = true;
						}
					} finally {
						self::unlock_product( $product_id );
					}
					if ( $stop_on_lock ) {
						break;
					}
				}

				if ( ! empty( $errors ) ) {
					return array(
						'success'  => false,
						'restored' => $restored_count,
						'message'  => 'بازگردانی کامل نشد؛ خطاها حفظ شده‌اند تا پس از رفع علت دوباره تلاش کنید. ' . implode( ' | ', array_slice( $errors, 0, 10 ) ),
					);
				}

				if ( ! self::refresh_run_lock( $run_id ) || ! self::lock_scope( 'history' ) ) {
					return array( 'success' => false, 'restored' => $restored_count, 'message' => 'داده‌ها بازیابی شدند اما ثبت وضعیت تاریخچه قفل بود؛ بازگردانی را دوباره اجرا کنید تا وضعیت ثبت شود.' );
				}
				try {
					if ( ! self::refresh_run_lock( $run_id ) ) {
						return array( 'success' => false, 'restored' => $restored_count, 'message' => 'قفل اجرا هنگام ثبت تاریخچه از دست رفت؛ وضعیت بازگردانی هنوز ثبت نشده است.' );
					}
					$runs = get_option( self::OPTION_RUNS_LIST, array() );
					if ( ! is_array( $runs ) || ! isset( $runs[ $run_id ] ) ) {
						return array( 'success' => false, 'restored' => $restored_count, 'message' => 'محصول‌ها بازیابی شدند اما رکورد اجرای تاریخچه یافت نشد.' );
					}
					$runs[ $run_id ]['status']         = 'rolled_back';
					$runs[ $run_id ]['rolled_back_at'] = current_time( 'mysql' );
					$saved = update_option( self::OPTION_RUNS_LIST, $runs, false );
					$check = get_option( self::OPTION_RUNS_LIST, array() );
					if ( ! $saved && ( ! is_array( $check ) || ! isset( $check[ $run_id ]['status'] ) || 'rolled_back' !== $check[ $run_id ]['status'] ) ) {
						return array( 'success' => false, 'restored' => $restored_count, 'message' => 'محصول‌ها بازیابی شدند اما ثبت وضعیت نهایی موفق نبود؛ می‌توان دوباره اجرا کرد.' );
					}
				} finally {
					self::unlock_scope( 'history' );
				}

				return array(
					'success'  => true,
					'restored' => $restored_count,
					'message'  => sprintf( 'تغییرات با موفقیت از snapshot کامل بازگردانی شد (%d محصول).', $restored_count ),
				);
			} finally {
				self::unlock_run( $run_id );
			}
		}

	}
}
