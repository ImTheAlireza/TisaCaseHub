<?php
/**
 * تبدیل پاسخ‌های متداول JSON به قرارداد داخلی افزونه.
 * این کلاس مستقل از ووکامرس است تا با Fixture قابل آزمون باشد.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Normalizer' ) ) {

	final class TCAS_Normalizer {

		public static function is_list( $value ) {
			if ( ! is_array( $value ) ) {
				return false;
			}
			if ( array() === $value ) {
				return true;
			}
			return array_keys( $value ) === range( 0, count( $value ) - 1 );
		}

		/** خواندن مسیر مثل data.product.stock_quantity؛ اندیس عددی هم پذیرفته می‌شود. */
		public static function path( $source, $path, &$found = null ) {
			$found = false;
			if ( '' === trim( (string) $path ) || ! is_array( $source ) ) {
				return null;
			}
			$parts = preg_split( '/\.+/', trim( (string) $path ), -1, PREG_SPLIT_NO_EMPTY );
			$value = $source;
			foreach ( (array) $parts as $part ) {
				if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
					return null;
				}
				$value = $value[ $part ];
			}
			$found = true;
			return $value;
		}

		private static function configured_value( $source, $settings, $key, $aliases = array() ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$found = false;
				$value = self::path( $source, $settings[ $key ], $found );
				return array( $found, $value );
			}
			foreach ( (array) $aliases as $alias ) {
				$found = false;
				$value = self::path( $source, $alias, $found );
				if ( $found ) {
					return array( true, $value );
				}
			}
			return array( false, null );
		}

		private static function looks_like_product( $source, $settings ) {
			if ( ! is_array( $source ) || self::is_list( $source ) ) {
				return false;
			}
			foreach ( array( 'field_code', 'field_name', 'field_stock', 'field_stock_status' ) as $key ) {
				if ( ! empty( $settings[ $key ] ) ) {
					$found = false;
					self::path( $source, $settings[ $key ], $found );
					if ( $found ) {
						return true;
					}
				}
			}
			$keys = array( 'sku', 'code', 'product_code', 'name', 'title', 'stock_quantity', 'quantity', 'qty', 'stock', 'stock_status', 'inventory', 'available', 'in_stock', 'is_in_stock' );
			foreach ( $keys as $key ) {
				if ( array_key_exists( $key, $source ) ) {
					return true;
				}
			}
			return false;
		}

		private static function raw_code( $source, $settings ) {
			list( $found, $value ) = self::configured_value( $source, $settings, 'field_code', array( 'sku', 'code', 'product_code', 'productCode' ) );
			if ( ! $found || ! is_scalar( $value ) ) {
				return '';
			}
			return trim( (string) $value );
		}

		private static function choose_item( $items, $code, $settings ) {
			if ( ! is_array( $items ) ) {
				return new WP_Error( 'tcas_invalid_items', 'فهرست محصولات در پاسخ ArioCase ساختار معتبری ندارد.' );
			}
			if ( 1 === count( $items ) ) {
				$only = reset( $items );
				if ( ! is_array( $only ) ) {
					return new WP_Error( 'tcas_invalid_item', 'محصول دریافتی از ArioCase ساختار معتبری ندارد.' );
				}
				if ( '' !== $code && self::raw_code( $only, $settings ) !== $code ) {
					return new WP_Error( 'tcas_code_mismatch', 'SKU تک‌نتیجهٔ ArioCase با کد درخواست‌شده یکسان نیست.' );
				}
				return $only;
			}
			foreach ( $items as $item ) {
				if ( is_array( $item ) && '' !== $code && self::raw_code( $item, $settings ) === $code ) {
					return $item;
				}
			}
			return new WP_Error( 'tcas_remote_not_found', 'محصول با این کد در پاسخ ArioCase پیدا نشد.' );
		}

		private static function extract_recursive( $payload, $code, $settings, $depth = 0 ) {
			if ( $depth > 5 || ! is_array( $payload ) ) {
				return new WP_Error( 'tcas_invalid_response', 'ساختار پاسخ ArioCase قابل تشخیص نیست.' );
			}
			if ( self::is_list( $payload ) ) {
				return self::choose_item( $payload, $code, $settings );
			}
			if ( self::looks_like_product( $payload, $settings ) ) {
				return $payload;
			}
			foreach ( array( 'product', 'item', 'result', 'data' ) as $wrapper ) {
				if ( isset( $payload[ $wrapper ] ) && is_array( $payload[ $wrapper ] ) ) {
					$result = self::extract_recursive( $payload[ $wrapper ], $code, $settings, $depth + 1 );
					if ( ! is_wp_error( $result ) ) {
						return $result;
					}
				}
			}
			foreach ( array( 'products', 'items', 'results', 'list' ) as $key ) {
				if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
					return self::choose_item( $payload[ $key ], $code, $settings );
				}
			}
			return new WP_Error( 'tcas_invalid_response', 'محصول در ساختار JSON پاسخ ArioCase پیدا نشد؛ مسیرهای پیشرفته را تنظیم کنید.' );
		}

		private static function extract( $payload, $code, $settings ) {
			if ( ! empty( $settings['item_path'] ) ) {
				$found = false;
				$item  = self::path( $payload, $settings['item_path'], $found );
				return $found && is_array( $item ) ? $item : new WP_Error( 'tcas_item_path', 'مسیر محصول (Item path) در پاسخ پیدا نشد.' );
			}
			if ( ! empty( $settings['items_path'] ) ) {
				$found = false;
				$items = self::path( $payload, $settings['items_path'], $found );
				return $found ? self::choose_item( $items, $code, $settings ) : new WP_Error( 'tcas_items_path', 'مسیر فهرست محصولات (Items path) در پاسخ پیدا نشد.' );
			}
			return self::extract_recursive( $payload, $code, $settings );
		}

		public static function normalize_status( $value ) {
			if ( is_bool( $value ) ) {
				return $value ? 'instock' : 'outofstock';
			}
			if ( ! is_scalar( $value ) ) {
				return null;
			}
			$status = strtolower( trim( (string) $value ) );
			$status = str_replace( array( '-', ' ' ), '_', $status );
			if ( in_array( $status, array( '1', 'true', 'yes', 'available', 'in_stock', 'instock', 'موجود' ), true ) ) {
				return 'instock';
			}
			if ( in_array( $status, array( '0', 'false', 'no', 'unavailable', 'out_of_stock', 'outofstock', 'ناموجود' ), true ) ) {
				return 'outofstock';
			}
			if ( in_array( $status, array( 'onbackorder', 'on_backorder', 'backorder' ), true ) ) {
				return 'onbackorder';
			}
			return null;
		}

		public static function normalize_number( $value, $allow_negative = false ) {
			if ( is_bool( $value ) || null === $value || ! is_scalar( $value ) ) {
				return null;
			}
			$text = trim( (string) $value );
			if ( '' === $text ) {
				return null;
			}
			$text = strtr( $text, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ) );
			$text = str_replace( array( ',', '٬', '،', ' ' ), '', $text );
			if ( ! is_numeric( $text ) ) {
				return null;
			}
			$number = (float) $text;
			if ( ! is_finite( $number ) || ( ! $allow_negative && $number < 0 ) || abs( $number ) > 1000000000 ) {
				return null;
			}
			return $number;
		}

		public static function stock( $source, $settings = array() ) {
			$out = array( 'quantity' => null, 'status' => null );
			if ( ! is_array( $source ) ) {
				return $out;
			}
			list( $stock_found, $raw_stock ) = self::configured_value( $source, $settings, 'field_stock', array( 'stock_quantity', 'quantity', 'qty', 'available_stock', 'inventory_quantity', 'stock', 'inventory', 'available' ) );
			$stock_source = is_array( $raw_stock ) ? $raw_stock : array();
			if ( $stock_found && ! is_array( $raw_stock ) ) {
				$quantity = self::normalize_number( $raw_stock );
				if ( null !== $quantity ) {
					$out['quantity'] = $quantity;
				} else {
					$out['status'] = self::normalize_status( $raw_stock );
				}
			}
			if ( $stock_found && is_array( $raw_stock ) ) {
				foreach ( array( 'quantity', 'qty', 'stock_quantity', 'available', 'count', 'value' ) as $key ) {
					if ( array_key_exists( $key, $stock_source ) ) {
						$quantity = self::normalize_number( $stock_source[ $key ] );
						if ( null !== $quantity ) {
							$out['quantity'] = $quantity;
							break;
						}
					}
				}
			}
			list( $status_found, $raw_status ) = self::configured_value( $source, $settings, 'field_stock_status', array( 'stock_status', 'in_stock', 'is_in_stock', 'availability', 'available' ) );
			if ( $status_found ) {
				$status = self::normalize_status( $raw_status );
				if ( null !== $status ) {
					$out['status'] = $status;
				}
			}
			if ( is_array( $raw_stock ) ) {
				foreach ( array( 'status', 'stock_status', 'in_stock', 'available' ) as $key ) {
					if ( array_key_exists( $key, $stock_source ) ) {
						$status = self::normalize_status( $stock_source[ $key ] );
						if ( null !== $status ) {
							$out['status'] = $status;
							break;
						}
					}
				}
			}
			if ( null !== $out['quantity'] ) {
				if ( $out['quantity'] <= 0 ) {
					$out['status'] = 'outofstock';
				} elseif ( null === $out['status'] ) {
					$out['status'] = 'instock';
				}
			}
			return $out;
		}

		private static function string_value( $source, $settings, $key, $aliases ) {
			list( $found, $value ) = self::configured_value( $source, $settings, $key, $aliases );
			return $found && is_scalar( $value ) ? trim( (string) $value ) : '';
		}

		private static function attributes( $raw ) {
			$out = array();
			if ( is_string( $raw ) ) {
				return $out;
			}
			if ( ! is_array( $raw ) ) {
				return $out;
			}
			if ( ! self::is_list( $raw ) ) {
				foreach ( $raw as $name => $options ) {
					if ( is_int( $name ) ) {
						continue;
					}
					$name = trim( (string) $name );
					if ( '' === $name ) {
						continue;
					}
					$options = is_array( $options ) ? array_values( $options ) : array( $options );
					$options = array_values( array_filter( array_map( function( $option ) {
						return is_scalar( $option ) ? trim( (string) $option ) : '';
					}, $options ), 'strlen' ) );
					if ( $options ) {
						$out[] = array( 'name' => $name, 'options' => array_values( array_unique( $options ) ) );
					}
				}
				return $out;
			}
			foreach ( $raw as $attribute ) {
				if ( ! is_array( $attribute ) ) {
					continue;
				}
				$name = '';
				foreach ( array( 'name', 'attribute', 'label', 'title' ) as $key ) {
					if ( isset( $attribute[ $key ] ) && is_scalar( $attribute[ $key ] ) ) {
						$name = trim( (string) $attribute[ $key ] );
						break;
					}
				}
				$options = array();
				foreach ( array( 'options', 'values', 'value', 'option' ) as $key ) {
					if ( array_key_exists( $key, $attribute ) ) {
						$options = is_array( $attribute[ $key ] ) ? array_values( $attribute[ $key ] ) : array( $attribute[ $key ] );
						break;
					}
				}
				$options = array_values( array_filter( array_map( function( $option ) {
					return is_scalar( $option ) ? trim( (string) $option ) : '';
				}, $options ), 'strlen' ) );
				if ( '' !== $name && $options ) {
					$out[] = array( 'name' => $name, 'options' => array_values( array_unique( $options ) ) );
				}
			}
			return $out;
		}

		private static function variation_attributes( $attributes ) {
			$out = array();
			foreach ( (array) $attributes as $attribute ) {
				if ( ! empty( $attribute['name'] ) && ! empty( $attribute['options'] ) ) {
					$out[ $attribute['name'] ] = (string) reset( $attribute['options'] );
				}
			}
			return $out;
		}

		private static function images( $raw ) {
			$out = array();
			if ( is_scalar( $raw ) ) {
				$raw = array( $raw );
			}
			if ( ! is_array( $raw ) ) {
				return $out;
			}
			if ( ! self::is_list( $raw ) ) {
				foreach ( array( 'src', 'url', 'image', 'thumbnail' ) as $key ) {
					if ( isset( $raw[ $key ] ) ) {
						$raw = array( $raw );
						break;
					}
				}
			}
			foreach ( $raw as $image ) {
				$url = '';
				if ( is_scalar( $image ) ) {
					$url = trim( (string) $image );
				} elseif ( is_array( $image ) ) {
					foreach ( array( 'src', 'url', 'image', 'thumbnail' ) as $key ) {
						if ( isset( $image[ $key ] ) && is_scalar( $image[ $key ] ) ) {
							$url = trim( (string) $image[ $key ] );
							break;
						}
					}
				}
				if ( '' !== $url ) {
					$out[] = $url;
				}
			}
			return array_values( array_unique( $out ) );
		}

		private static function normalize_item( $source, $requested_code, $settings ) {
			list( $id_found, $raw_id ) = self::configured_value( $source, $settings, '_no_id_setting', array( 'id' ) );
			$remote_id = $id_found && is_scalar( $raw_id ) && preg_match( '/^\d+$/', trim( (string) $raw_id ) ) ? (int) $raw_id : 0;
			list( $parent_found, $raw_parent_id ) = self::configured_value( $source, $settings, '_no_parent_id_setting', array( 'parent_id' ) );
			$parent_remote_id = $parent_found && is_scalar( $raw_parent_id ) && preg_match( '/^\d+$/', trim( (string) $raw_parent_id ) ) ? (int) $raw_parent_id : 0;
			$code = self::raw_code( $source, $settings );
			if ( '' === $code ) {
				$code = (string) $requested_code;
			}
			$name = self::string_value( $source, $settings, 'field_name', array( 'name', 'title', 'product_name' ) );
			$type = self::string_value( $source, $settings, '_no_type_setting', array( 'type', 'product_type' ) );
			list( $price_found, $raw_price ) = self::configured_value( $source, $settings, 'field_price', array( 'regular_price', 'price', 'sale_price' ) );
			$price = $price_found ? self::normalize_number( $raw_price ) : null;
			list( $attr_found, $raw_attributes ) = self::configured_value( $source, $settings, 'field_attributes', array( 'attributes', 'attrs' ) );
			$attributes = $attr_found ? self::attributes( $raw_attributes ) : array();
			list( $images_found, $raw_images ) = self::configured_value( $source, $settings, 'field_images', array( 'images', 'image', 'image_url', 'thumbnail' ) );
			return array(
				'remote_id'   => $remote_id,
				'parent_remote_id' => $parent_remote_id,
				'code'        => $code,
				'slug'        => self::string_value( $source, $settings, '_no_slug_setting', array( 'slug' ) ),
				'name'        => '' !== $name ? $name : $code,
				'type'        => strtolower( $type ),
				'description' => self::string_value( $source, $settings, 'field_description', array( 'description', 'short_description' ) ),
				'price'       => $price,
				'stock'       => self::stock( $source, $settings ),
				'attributes'  => $attributes,
				'variation_attributes' => self::variation_attributes( $attributes ),
				'images'      => $images_found ? self::images( $raw_images ) : array(),
				'variations'  => array(),
			);
		}

		public static function variation( $payload, $settings = array() ) {
			if ( ! is_array( $payload ) ) {
				return new WP_Error( 'tcas_invalid_variation', 'ساختار تنوع در پاسخ ArioCase معتبر نیست.' );
			}
			$settings = wp_parse_args( is_array( $settings ) ? $settings : array(), TCAS_Core::defaults() );
			return self::normalize_item( $payload, '', $settings );
		}

		public static function product( $payload, $requested_code, $settings = array() ) {
			$settings = wp_parse_args( is_array( $settings ) ? $settings : array(), TCAS_Core::defaults() );
			$source   = self::extract( $payload, (string) $requested_code, $settings );
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			$product = self::normalize_item( $source, (string) $requested_code, $settings );
			list( $found, $raw_variations ) = self::configured_value( $source, $settings, 'field_variations', array( 'variations', 'children', 'variants' ) );
			if ( $found && is_array( $raw_variations ) ) {
				foreach ( $raw_variations as $raw_variation ) {
					if ( ! is_array( $raw_variation ) ) {
						// خروجی ووکامرس گاهی فقط ID تنوع را می‌دهد؛ برای بررسی کامل باید جزئیات آن خوانده شود.
						$product['variations_missing'] = true;
						continue;
					}
					$product['variations'][] = self::normalize_item( $raw_variation, '', $settings );
				}
			}
			if ( $product['variations'] ) {
				$product['type'] = 'variable';
			}
			return $product;
		}
	}
}
