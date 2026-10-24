<?php
/**
 * پیش‌نمایش، اتصال محصول موجود و ایمپورت پیش‌نویس از ArioCase.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Importer' ) ) {

	final class TCAS_Importer {

		private static function public_row( $code, $status, $message, $extra = array() ) {
			return array_merge( array(
				'code'       => $code,
				'status'     => $status,
				'message'    => $message,
				'selectable' => in_array( $status, array( 'import', 'connect' ), true ),
				'name'       => '',
				'local_id'   => 0,
				'local_type' => '',
				'stock'      => null,
			), $extra );
		}

		public static function preview( $code ) {
			$local_id = TCAS_Mapping::local_by_sku( $code );
			if ( is_wp_error( $local_id ) ) {
				return array( 'row' => self::public_row( $code, 'error', $local_id->get_error_message() ), 'remote' => null );
			}
			$remote = TCAS_Api::fetch_product( $code );
			if ( is_wp_error( $remote ) ) {
				return array( 'row' => self::public_row( $code, 'error', $remote->get_error_message() ), 'remote' => null );
			}
			$extra      = array(
				'name'  => $remote['name'],
				'stock' => $remote['stock'],
			);
			$mapped_ids = TCAS_Mapping::ids_by_remote_code( $code );
			if ( ! $local_id && $mapped_ids ) {
				$extra['local_id'] = 1 === count( $mapped_ids ) ? $mapped_ids[0] : 0;
				$status            = 1 === count( $mapped_ids ) ? 'connected' : 'error';
				$message           = 1 === count( $mapped_ids ) ? 'این کد ArioCase قبلاً به یک محصول متصل شده است؛ ایمپورت تکراری لازم نیست.' : 'این کد ArioCase به چند محصول متصل است؛ ابتدا نگاشت‌ها را اصلاح کنید.';
				return array( 'row' => self::public_row( $code, $status, $message, $extra ), 'remote' => $remote );
			}
			foreach ( $mapped_ids as $mapped_id ) {
				if ( $local_id && $mapped_id !== (int) $local_id ) {
					return array( 'row' => self::public_row( $code, 'error', sprintf( 'کد ArioCase از قبل به محصول #%d متصل است.', $mapped_id ), $extra ), 'remote' => $remote );
				}
			}
			$remote_variable = 'variable' === $remote['type'] || ! empty( $remote['variations'] ) || ! empty( $remote['variations_missing'] );
			if ( ! empty( $remote['variations_missing'] ) ) {
				return array( 'row' => self::public_row( $code, 'error', 'جزئیات همهٔ تنوع‌ها در پاسخ نیست؛ ایمپورت ناقص انجام نشد.', $extra ), 'remote' => $remote );
			}
			if ( $remote_variable && empty( $remote['variations'] ) ) {
				return array( 'row' => self::public_row( $code, 'error', 'این محصول متغیر است ولی جزئیات تنوع‌ها در پاسخ API نیست.', $extra ), 'remote' => $remote );
			}
			if ( ! $local_id ) {
				$row = self::public_row( $code, 'import', $remote_variable ? 'محصول متغیر جدید به‌صورت پیش‌نویس ساخته می‌شود.' : 'محصول سادهٔ جدید به‌صورت پیش‌نویس ساخته می‌شود.', $extra );
				return array( 'row' => $row, 'remote' => $remote );
			}

			$product = wc_get_product( $local_id );
			if ( ! $product ) {
				return array( 'row' => self::public_row( $code, 'error', 'محصول محلی پیدا نشد.', $extra ), 'remote' => $remote );
			}
			$extra['local_id']   = $local_id;
			$extra['local_type'] = $product->get_type();
			$current             = TCAS_Mapping::remote_code( $local_id );
			if ( '' !== $current ) {
				$status  = $current === $code ? 'connected' : 'error';
				$message = $current === $code ? 'این محصول قبلاً به همین کد متصل شده است.' : 'محصول محلی به کد ArioCase دیگری متصل است.';
				return array( 'row' => self::public_row( $code, $status, $message, $extra ), 'remote' => $remote );
			}
			$local_variable = $product->is_type( 'variable' );
			if ( $local_variable !== $remote_variable ) {
				return array( 'row' => self::public_row( $code, 'error', 'نوع محصول ساده/متغیر در دو فروشگاه یکسان نیست؛ اتصال خودکار انجام نشد.', $extra ), 'remote' => $remote );
			}
			if ( ! $local_variable && ! in_array( $product->get_type(), array( 'simple', 'variation' ), true ) ) {
				return array( 'row' => self::public_row( $code, 'error', 'اتصال خودکار فقط برای محصول ساده، متغیر و Variation پشتیبانی می‌شود.', $extra ), 'remote' => $remote );
			}
			$message = $local_variable ? 'والد و تنوع‌های دارای SKU یکسان متصل می‌شوند.' : 'محصول موجود حفظ و فقط به ArioCase متصل می‌شود.';
			return array( 'row' => self::public_row( $code, 'connect', $message, $extra ), 'remote' => $remote );
		}

		private static function apply_existing_stock( $product, $remote, &$warnings ) {
			if ( ! TCAS_Stock::valid( $remote['stock'] ) ) {
				$warnings[] = 'موجودی در پاسخ محصول نبود؛ اولین خواندن زنده باید آن را دریافت کند.';
				return;
			}
			$result = TCAS_Stock::apply( $product, $remote['stock'] );
			if ( is_wp_error( $result ) ) {
				$warnings[] = $result->get_error_message();
			} else {
				TCAS_Mapping::remember( $product->get_id(), $remote['stock'] );
			}
		}

		private static function connect_existing( $local_id, $remote, $options ) {
			$product  = wc_get_product( $local_id );
			$warnings = array();
			if ( ! $product ) {
				return new WP_Error( 'tcas_local_missing', 'محصول محلی پیدا نشد.' );
			}
			if ( ! $product->is_type( 'variable' ) ) {
				$mapped = TCAS_Mapping::map( $local_id, $remote['code'], true );
				if ( is_wp_error( $mapped ) ) {
					return $mapped;
				}
				TCAS_Mapping::remember_product( $local_id, $remote );
				self::apply_existing_stock( $product, $remote, $warnings );
				return array( 'product_id' => $local_id, 'message' => 'محصول موجود به ArioCase متصل شد.', 'warnings' => $warnings );
			}

			// ابتدا همهٔ کاندیداها بررسی و بعد والد قفل می‌شود؛ خطای نگاشت والد نباید
			// بعد از نوشته‌شدن اتصال فرزندان رخ دهد.
			$candidates = array();
			foreach ( (array) $remote['variations'] as $variation ) {
				$code = trim( (string) $variation['code'] );
				if ( '' === $code ) {
					$warnings[] = 'یک تنوع بدون کد رد شد.';
					continue;
				}
				$child_id = TCAS_Mapping::local_by_sku( $code );
				if ( is_wp_error( $child_id ) ) {
					$warnings[] = $code . ': ' . $child_id->get_error_message();
					continue;
				}
				$child = $child_id ? wc_get_product( $child_id ) : null;
				if ( ! $child || ! $child->is_type( 'variation' ) || (int) $child->get_parent_id() !== (int) $local_id ) {
					$warnings[] = $code . ': تنوع هم‌کد داخل همین والد پیدا نشد.';
					continue;
				}
				$candidates[] = array( 'product' => $child, 'code' => $code, 'remote' => $variation );
			}
			if ( ! $candidates ) {
				return new WP_Error( 'tcas_no_variation_mapping', 'هیچ‌کدام از تنوع‌ها SKU یکسان داخل این محصول نداشتند؛ اتصالی ساخته نشد.' );
			}
			$mapped = TCAS_Mapping::map( $local_id, $remote['code'], false ); // والد فقط نگهدارندهٔ ارتباط است.
			if ( is_wp_error( $mapped ) ) {
				return $mapped;
			}
			TCAS_Mapping::remember_product( $local_id, $remote );
			$mapped_children = 0;
			foreach ( $candidates as $candidate ) {
				$child  = $candidate['product'];
				$mapped = TCAS_Mapping::map( $child->get_id(), $candidate['code'], true );
				if ( is_wp_error( $mapped ) ) {
					$warnings[] = $candidate['code'] . ': ' . $mapped->get_error_message();
					continue;
				}
				$mapped_children++;
				TCAS_Mapping::remember_product( $child->get_id(), $candidate['remote'], $remote['remote_id'] );
				self::apply_existing_stock( $child, $candidate['remote'], $warnings );
			}
			if ( ! $mapped_children ) {
				TCAS_Mapping::forget( $local_id );
				return new WP_Error( 'tcas_no_variation_mapping', 'اتصال تنوع‌ها ساخته نشد.' );
			}
			if ( class_exists( 'WC_Product_Variable' ) && is_callable( array( 'WC_Product_Variable', 'sync_stock_status' ) ) ) {
				WC_Product_Variable::sync_stock_status( $local_id, true );
			}
			return array(
				'product_id' => $local_id,
				'message'    => sprintf( 'محصول متغیر متصل شد؛ %d تنوع نگاشت شد.', $mapped_children ),
				'warnings'   => $warnings,
			);
		}

		private static function set_common_fields( $product, $remote, $options ) {
			$product->set_name( $remote['name'] );
			$product->set_sku( $remote['code'] );
			$product->set_status( 'draft' );
			if ( '' !== $remote['description'] ) {
				$product->set_description( $remote['description'] );
			}
			if ( ! empty( $options['prices'] ) && null !== $remote['price'] ) {
				$product->set_regular_price( (string) $remote['price'] );
			}
		}

		private static function attribute_definitions( $remote ) {
			$definitions = array();
			foreach ( (array) $remote['attributes'] as $attribute ) {
				$name = trim( (string) $attribute['name'] );
				if ( '' !== $name ) {
					$definitions[ $name ] = isset( $attribute['options'] ) ? array_values( (array) $attribute['options'] ) : array();
				}
			}
			foreach ( (array) $remote['variations'] as $variation ) {
				foreach ( (array) $variation['variation_attributes'] as $name => $value ) {
					if ( ! isset( $definitions[ $name ] ) ) {
						$definitions[ $name ] = array();
					}
					$definitions[ $name ][] = $value;
				}
			}
			foreach ( $definitions as $name => $options ) {
				$definitions[ $name ] = array_values( array_unique( array_filter( $options, 'strlen' ) ) );
			}
			return array_filter( $definitions );
		}

		private static function parent_attributes( $definitions ) {
			$out      = array();
			$position = 0;
			foreach ( $definitions as $name => $options ) {
				$attribute = new WC_Product_Attribute();
				$attribute->set_id( 0 );
				$attribute->set_name( $name );
				$attribute->set_options( $options );
				$attribute->set_position( $position++ );
				$attribute->set_visible( true );
				$attribute->set_variation( true );
				$out[ sanitize_title( $name ) ] = $attribute;
			}
			return $out;
		}

		private static function create_simple( $remote, $options ) {
			$product = new WC_Product();
			self::set_common_fields( $product, $remote, $options );
			if ( TCAS_Stock::valid( $remote['stock'] ) ) {
				$populated = TCAS_Stock::populate( $product, $remote['stock'] );
				if ( is_wp_error( $populated ) ) {
					return $populated;
				}
			} else {
				$product->set_manage_stock( false );
				$product->set_stock_status( 'outofstock' );
			}
			$product_id = 0;
			try {
				$product_id = $product->save();
				$mapped = TCAS_Mapping::map( $product_id, $remote['code'], true );
				if ( is_wp_error( $mapped ) ) {
					throw new Exception( $mapped->get_error_message() );
				}
				TCAS_Mapping::remember_product( $product_id, $remote );
				if ( TCAS_Stock::valid( $remote['stock'] ) ) {
					TCAS_Mapping::remember( $product_id, $remote['stock'] );
				}
				$warnings = ! empty( $options['images'] ) ? self::attach_images( $product_id, $remote ) : array();
				return array( 'product_id' => $product_id, 'message' => 'محصول ساده با وضعیت پیش‌نویس ساخته و متصل شد.', 'warnings' => $warnings );
			} catch ( Throwable $e ) {
				if ( $product_id ) {
					wp_delete_post( $product_id, true );
				}
				return new WP_Error( 'tcas_import_failed', $e->getMessage() );
			}
		}

		private static function create_variable( $remote, $options ) {
			$definitions = self::attribute_definitions( $remote );
			if ( ! $definitions ) {
				return new WP_Error( 'tcas_attributes_missing', 'ویژگی‌های تنوع در پاسخ API مشخص نیست؛ ایمپورت متغیر انجام نشد.' );
			}
			$seen = array();
			foreach ( (array) $remote['variations'] as $variation ) {
				$code = trim( (string) $variation['code'] );
				if ( '' === $code || isset( $seen[ $code ] ) ) {
					return new WP_Error( 'tcas_variation_codes', 'همهٔ تنوع‌ها باید کد یکتا و غیرخالی داشته باشند.' );
				}
				$seen[ $code ] = true;
				$local_id      = TCAS_Mapping::local_by_sku( $code );
				if ( is_wp_error( $local_id ) ) {
					return $local_id;
				}
				if ( $local_id ) {
					return new WP_Error( 'tcas_variation_sku_used', sprintf( 'SKU تنوع «%s» از قبل در ووکامرس وجود دارد.', $code ) );
				}
				if ( empty( $variation['variation_attributes'] ) ) {
					return new WP_Error( 'tcas_variation_attributes', sprintf( 'ویژگی تنوع «%s» در پاسخ API نیست.', $code ) );
				}
			}

			$product = new WC_Product_Variable();
			self::set_common_fields( $product, $remote, $options );
			$product->set_attributes( self::parent_attributes( $definitions ) );
			$product_id = 0;
			try {
				$product_id = $product->save();
				$mapped = TCAS_Mapping::map( $product_id, $remote['code'], false );
				if ( is_wp_error( $mapped ) ) {
					throw new Exception( $mapped->get_error_message() );
				}
				TCAS_Mapping::remember_product( $product_id, $remote );
				foreach ( (array) $remote['variations'] as $remote_variation ) {
					$variation = new WC_Product_Variation();
					$variation->set_parent_id( $product_id );
					$variation->set_status( 'publish' );
					$variation->set_sku( $remote_variation['code'] );
					if ( ! empty( $options['prices'] ) && null !== $remote_variation['price'] ) {
						$variation->set_regular_price( (string) $remote_variation['price'] );
					} elseif ( ! empty( $options['prices'] ) && null !== $remote['price'] ) {
						$variation->set_regular_price( (string) $remote['price'] );
					}
					$attributes = array();
					foreach ( (array) $remote_variation['variation_attributes'] as $name => $value ) {
						// ویژگی‌های Custom باید دقیقاً با Option والد تطبیق داشته باشند؛ Slug کردن مقدار، تطبیق تنوع را خراب می‌کند.
						$attributes[ sanitize_title( $name ) ] = sanitize_text_field( $value );
					}
					$variation->set_attributes( $attributes );
					if ( TCAS_Stock::valid( $remote_variation['stock'] ) ) {
						$populated = TCAS_Stock::populate( $variation, $remote_variation['stock'] );
						if ( is_wp_error( $populated ) ) {
							throw new Exception( $populated->get_error_message() );
						}
					} else {
						$variation->set_manage_stock( false );
						$variation->set_stock_status( 'outofstock' );
					}
					$variation_id = $variation->save();
					foreach ( $attributes as $key => $value ) {
						update_post_meta( $variation_id, 'attribute_' . $key, $value );
					}
					$mapped = TCAS_Mapping::map( $variation_id, $remote_variation['code'], true );
					if ( is_wp_error( $mapped ) ) {
						throw new Exception( $mapped->get_error_message() );
					}
					TCAS_Mapping::remember_product( $variation_id, $remote_variation, $remote['remote_id'] );
					if ( TCAS_Stock::valid( $remote_variation['stock'] ) ) {
						TCAS_Mapping::remember( $variation_id, $remote_variation['stock'] );
					}
				}
				if ( class_exists( 'WC_Product_Variable' ) ) {
					WC_Product_Variable::sync( $product_id );
					if ( is_callable( array( 'WC_Product_Variable', 'sync_stock_status' ) ) ) {
						WC_Product_Variable::sync_stock_status( $product_id, true );
					}
				}
				$warnings = ! empty( $options['images'] ) ? self::attach_images( $product_id, $remote ) : array();
				return array( 'product_id' => $product_id, 'message' => sprintf( 'محصول متغیر با %d تنوع به‌صورت پیش‌نویس ساخته شد.', count( $remote['variations'] ) ), 'warnings' => $warnings );
			} catch ( Throwable $e ) {
				if ( $product_id ) {
					foreach ( (array) get_children( array( 'post_parent' => $product_id, 'post_type' => 'product_variation', 'post_status' => 'any', 'fields' => 'ids' ) ) as $child_id ) {
						wp_delete_post( $child_id, true );
					}
					wp_delete_post( $product_id, true );
				}
				return new WP_Error( 'tcas_import_failed', $e->getMessage() );
			}
		}

		private static function attach_images( $product_id, $remote ) {
			$warnings = array();
			$urls     = array_slice( (array) $remote['images'], 0, 4 );
			if ( ! $urls ) {
				return $warnings;
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$gallery  = array();
			$attached = 0;
			foreach ( $urls as $url ) {
				$url = esc_url_raw( $url );
				if ( ! preg_match( '#^https?://#i', $url ) ) {
					$warnings[] = 'یک آدرس تصویر نامعتبر رد شد.';
					continue;
				}
				if ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $url ) ) {
					$warnings[] = 'یک تصویر با آدرس غیرمجاز رد شد.';
					continue;
				}
				$attachment_id = media_sideload_image( $url, $product_id, $remote['name'], 'id' );
				if ( is_wp_error( $attachment_id ) ) {
					$warnings[] = 'یک تصویر دانلود نشد؛ محصول و بقیهٔ تصاویر حفظ شدند.';
					continue;
				}
				if ( 0 === $attached ) {
					set_post_thumbnail( $product_id, $attachment_id );
				} else {
					$gallery[] = $attachment_id;
				}
				$attached++;
			}
			if ( $gallery ) {
				update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery ) );
			}
			return $warnings;
		}

		public static function import( $preview, $options = array() ) {
			if ( ! is_array( $preview ) || empty( $preview['row'] ) || empty( $preview['remote'] ) ) {
				return new WP_Error( 'tcas_preview_missing', 'اطلاعات پیش‌نمایش این کد در دسترس نیست.' );
			}
			$row     = $preview['row'];
			$remote  = $preview['remote'];
			$options = wp_parse_args( $options, array( 'prices' => true, 'images' => true ) );
			if ( empty( $row['selectable'] ) || ! in_array( $row['status'], array( 'import', 'connect' ), true ) ) {
				return new WP_Error( 'tcas_not_importable', 'این ردیف قابل ایمپورت یا اتصال نیست.' );
			}
			// SKU دوباره هنگام اجرا چک می‌شود تا Preview کهنه باعث تکرار نشود.
			$local_id = TCAS_Mapping::local_by_sku( $row['code'] );
			if ( is_wp_error( $local_id ) ) {
				return $local_id;
			}
			if ( 'connect' === $row['status'] ) {
				if ( ! $local_id ) {
					return new WP_Error( 'tcas_preview_stale', 'محصول محلی بعد از پیش‌نمایش حذف شده است؛ پیش‌نمایش را تازه کنید.' );
				}
				return self::connect_existing( $local_id, $remote, $options );
			}
			if ( $local_id ) {
				return new WP_Error( 'tcas_preview_stale', 'بعد از پیش‌نمایش محصولی با همین SKU ساخته شده است؛ ایمپورت تکراری انجام نشد.' );
			}
			return $remote['variations'] ? self::create_variable( $remote, $options ) : self::create_simple( $remote, $options );
		}
	}
}
