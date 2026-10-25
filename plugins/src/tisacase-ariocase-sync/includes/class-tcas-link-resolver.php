<?php
/**
 * تبدیل لینک محصول TisaCase یا ArioCase به SKU واقعی.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Link_Resolver' ) ) {

	final class TCAS_Link_Resolver {
		private static function error( $code, $message ) {
			return new WP_Error( $code, $message );
		}

		private static function host_variants( $host ) {
			$host  = strtolower( trim( (string) $host ) );
			$plain = preg_replace( '/^www\./', '', $host );
			return '' === $host ? array() : array_unique( array( $host, $plain, 'www.' . $plain ) );
		}

		private static function hosts_for( $urls ) {
			$hosts = array();
			foreach ( (array) $urls as $url ) {
				$parts = wp_parse_url( (string) $url );
				if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
					$hosts = array_merge( $hosts, self::host_variants( $parts['host'] ) );
				}
			}
			return array_values( array_unique( $hosts ) );
		}

		private static function variation_label( $attributes, $fallback = '' ) {
			$values = array();
			foreach ( (array) $attributes as $attribute ) {
				if ( is_array( $attribute ) ) {
					if ( isset( $attribute['option'] ) ) {
						$attribute = $attribute['option'];
					} elseif ( isset( $attribute['value'] ) ) {
						$attribute = $attribute['value'];
					} elseif ( ! empty( $attribute['options'] ) && is_array( $attribute['options'] ) ) {
						$attribute = reset( $attribute['options'] );
					}
				}
				if ( is_scalar( $attribute ) && '' !== trim( (string) $attribute ) ) {
					$values[] = trim( (string) $attribute );
				}
			}
			$values = array_values( array_unique( $values ) );
			return $values ? implode( ' / ', $values ) : trim( (string) $fallback );
		}

		private static function local_result( $product_id ) {
			$product_id = absint( $product_id );
			$product    = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
			if ( ! $product || ! is_object( $product ) ) {
				return self::error( 'tcas_local_product_missing', 'این لینک به یک محصول موجود در TisaCase اشاره نمی‌کند.' );
			}
			$sku        = trim( (string) $product->get_sku() );
			$type       = (string) $product->get_type();
			$variations = array();
			if ( 'variable' === $type ) {
				// فقط Variationهای واقعی همان والد؛ Attribute option ساخته نمی‌شود و In-stock/Visible فیلتر نیست.
				foreach ( TCAS_Local_Variation_Reader::ids( $product, $product_id ) as $variation_id ) {
					$variation_id = absint( $variation_id );
					$variation    = $variation_id ? wc_get_product( $variation_id ) : false;
					if ( ! TCAS_Local_Variation_Reader::belongs_to( $variation, $product_id ) ) {
						continue;
					}
					$variation_sku = trim( (string) $variation->get_sku() );
					$variations[]  = array(
						'product_id' => $variation_id,
						'parent_id'  => $product_id,
						'sku'        => $variation_sku,
						'reference'  => 'ID:' . $variation_id,
						'label'      => self::variation_label( TCAS_Local_Variation_Reader::attributes( $variation ), $variation->get_name() ),
					);
				}
			}
			if ( '' === $sku && 'variable' !== $type ) {
				return self::error( 'tcas_local_sku_missing', 'برای این محصول در TisaCase شناسهٔ SKU ثبت نشده است.' );
			}
			return array(
				'shop'       => 'tisacase',
				'shop_label' => 'TisaCase',
				'sku'        => $sku,
				'reference'  => $sku,
				'name'       => (string) $product->get_name(),
				'product_id' => $product_id,
				'type'       => $type,
				'variations' => $variations,
			);
		}

		public static function resolve_local_reference( $reference ) {
			$reference = trim( (string) $reference );
			if ( '' === $reference || strlen( $reference ) > 190 ) {
				return self::error( 'tcas_invalid_local_reference', 'SKU یا ID محصول والد TisaCase معتبر نیست.' );
			}
			if ( preg_match( '/^ID:/i', $reference ) ) {
				$matches = array();
				if ( ! preg_match( '/^ID:([1-9][0-9]*)$/i', $reference, $matches ) ) {
					return self::error( 'tcas_invalid_local_reference', 'قالب ID محصول TisaCase معتبر نیست.' );
				}
				return self::local_result( absint( $matches[1] ) );
			}
			if ( ! class_exists( 'TCAS_Mapping' ) ) {
				return self::error( 'tcas_local_resolver_missing', 'امکان جست‌وجوی SKU در این نصب وردپرس نیست.' );
			}
			$product_id = TCAS_Mapping::local_by_sku( $reference );
			if ( is_wp_error( $product_id ) ) {
				return $product_id;
			}
			if ( ! $product_id ) {
				return self::error( 'tcas_local_product_missing', 'محصولی با این SKU در TisaCase پیدا نشد.' );
			}
			return self::local_result( $product_id );
		}

		private static function local_product_id_from_path( $path ) {
			if ( ! function_exists( 'get_page_by_path' ) ) {
				return 0;
			}
			$path = trim( (string) $path, '/' );
			$base = 'product';
			if ( function_exists( 'get_option' ) ) {
				$permalinks = get_option( 'woocommerce_permalinks' );
				if ( is_array( $permalinks ) && isset( $permalinks['product_base'] ) && is_scalar( $permalinks['product_base'] ) && '' !== trim( (string) $permalinks['product_base'] ) ) {
					$base = (string) $permalinks['product_base'];
				}
			}
			$base = trim( $base, '/' );
			if ( '' === $base || false !== strpos( $base, '%' ) || 0 !== strpos( $path, $base . '/' ) ) {
				return 0;
			}
			$slug = substr( $path, strlen( $base ) + 1 );
			if ( '' === $slug || false !== strpos( $slug, '/' ) ) {
				return 0;
			}
			// Rewrite ممکن است نامک فارسی/Encoded را نشناسد؛ هر دو شکل ذخیرهٔ رایج post_name دقیق بررسی می‌شوند.
			foreach ( array_unique( array( $slug, rawurldecode( $slug ) ) ) as $candidate ) {
				if ( '' === $candidate || false !== strpos( $candidate, '/' ) || false !== strpos( $candidate, "\0" ) ) {
					continue;
				}
				$post = get_page_by_path( $candidate, OBJECT, 'product' );
				if ( is_object( $post ) && ! empty( $post->ID ) ) {
					return absint( $post->ID );
				}
			}
			return 0;
		}

		private static function resolve_local( $url, $parts ) {
			$query = array();
			if ( isset( $parts['query'] ) ) {
				parse_str( $parts['query'], $query );
			}
			if ( isset( $query['p'] ) && is_scalar( $query['p'] ) && ctype_digit( (string) $query['p'] ) && absint( $query['p'] ) ) {
				return self::local_result( absint( $query['p'] ) );
			}
			if ( isset( $query['product'] ) && is_scalar( $query['product'] ) && '' !== trim( (string) $query['product'] ) ) {
				$post = get_page_by_path( trim( (string) $query['product'] ), OBJECT, 'product' );
				return is_object( $post ) && ! empty( $post->ID ) ? self::local_result( $post->ID ) : self::error( 'tcas_local_product_missing', 'محصول این لینک در TisaCase پیدا نشد.' );
			}
			if ( ! function_exists( 'url_to_postid' ) || ! function_exists( 'home_url' ) ) {
				return self::error( 'tcas_local_resolver_missing', 'امکان تشخیص لینک TisaCase در این نصب وردپرس نیست.' );
			}
			$path      = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
			$local_url = home_url( $path );
			if ( isset( $parts['query'] ) && '' !== (string) $parts['query'] ) {
				$local_url .= '?' . $parts['query'];
			}
			$product_id = absint( url_to_postid( $local_url ) );
			if ( ! $product_id ) {
				$product_id = self::local_product_id_from_path( $path );
			}
			return $product_id ? self::local_result( $product_id ) : self::error( 'tcas_local_product_missing', 'محصول این لینک در TisaCase پیدا نشد.' );
		}

		private static function remote_result( $product ) {
			if ( ! is_array( $product ) ) {
				return self::error( 'tcas_remote_product_missing', 'ساختار محصول ArioCase معتبر نیست.' );
			}
			$product_id = isset( $product['remote_id'] ) ? absint( $product['remote_id'] ) : 0;
			$sku        = isset( $product['code'] ) ? trim( (string) $product['code'] ) : '';
			$type       = isset( $product['type'] ) ? (string) $product['type'] : '';
			$reference  = '' !== $sku ? $sku : ( $product_id ? 'ID:' . $product_id : '' );
			if ( '' === $reference ) {
				return self::error( 'tcas_remote_id_missing', 'محصول ArioCase باید SKU یا Product ID داشته باشد.' );
			}
			$variations = array();
			if ( 'variable' === $type ) {
				foreach ( (array) $product['variations'] as $variation ) {
					$variation_id  = ! empty( $variation['remote_id'] ) ? absint( $variation['remote_id'] ) : 0;
					$parent_id     = ! empty( $variation['parent_remote_id'] ) ? absint( $variation['parent_remote_id'] ) : $product_id;
					$variation_sku = isset( $variation['code'] ) ? trim( (string) $variation['code'] ) : '';
					$variations[]  = array(
						'product_id' => $variation_id,
						'parent_id'  => $parent_id,
						'sku'        => $variation_sku,
						'reference'  => '' !== $variation_sku ? $variation_sku : ( $parent_id && $variation_id ? 'ID:' . $parent_id . '/' . $variation_id : '' ),
						'label'      => self::variation_label( isset( $variation['variation_attributes'] ) ? $variation['variation_attributes'] : array(), isset( $variation['name'] ) ? $variation['name'] : '' ),
					);
				}
			}
			return array(
				'shop'       => 'ariocase',
				'shop_label' => 'ArioCase',
				'sku'        => $sku,
				'reference'  => $reference,
				'name'       => isset( $product['name'] ) ? (string) $product['name'] : '',
				'product_id' => $product_id,
				'type'       => $type,
				'variations' => $variations,
			);
		}

		public static function resolve_remote_reference( $reference ) {
			$reference = trim( (string) $reference );
			if ( '' === $reference || strlen( $reference ) > 2048 ) {
				return self::error( 'tcas_invalid_remote_reference', 'لینک، SKU یا ID محصول ArioCase معتبر نیست.' );
			}
			if ( preg_match( '#^https?://#i', $reference ) ) {
				$result = self::resolve( $reference );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return ! empty( $result['shop'] ) && 'ariocase' === $result['shop'] ? $result : self::error( 'tcas_remote_wrong_shop', 'مرجع ArioCase باید متعلق به فروشگاه ArioCase باشد.' );
			}
			if ( strlen( $reference ) > 190 ) {
				return self::error( 'tcas_invalid_remote_reference', 'SKU یا ID محصول ArioCase بسیار طولانی است.' );
			}
			$product = TCAS_Api::fetch_reference( $reference );
			return is_wp_error( $product ) ? $product : self::remote_result( $product );
		}

		public static function resolve( $url ) {
			$url = trim( (string) $url );
			if ( '' === $url || strlen( $url ) > 2048 || ! preg_match( '#^https?://#i', $url ) ) {
				return self::error( 'tcas_invalid_link', 'یک لینک کامل و معتبر وارد کنید.' );
			}
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
				return self::error( 'tcas_invalid_link', 'ساختار لینک معتبر نیست.' );
			}
			$allow_http = defined( 'TISACASE_ARIOCASE_ALLOW_HTTP' ) && TISACASE_ARIOCASE_ALLOW_HTTP;
			$scheme     = strtolower( (string) $parts['scheme'] );
			if ( ( 'https' !== $scheme && ! ( $allow_http && 'http' === $scheme ) ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
				return self::error( 'tcas_insecure_link', 'لینک باید HTTPS و بدون اطلاعات ورود یا Port باشد.' );
			}
			$host         = strtolower( (string) $parts['host'] );
			$local_hosts  = self::hosts_for( array( home_url(), site_url() ) );
			$settings     = TCAS_Core::settings();
			$remote_hosts = self::hosts_for( array( isset( $settings['base_url'] ) ? $settings['base_url'] : '' ) );
			if ( in_array( $host, $local_hosts, true ) ) {
				return self::resolve_local( $url, $parts );
			}
			if ( in_array( $host, $remote_hosts, true ) ) {
				$product = TCAS_Api::fetch_reference( $url );
				return is_wp_error( $product ) ? $product : self::remote_result( $product );
			}
			return self::error( 'tcas_unknown_shop', 'دامنهٔ لینک با TisaCase یا ArioCase تنظیم‌شده یکسان نیست.' );
		}
	}
}
