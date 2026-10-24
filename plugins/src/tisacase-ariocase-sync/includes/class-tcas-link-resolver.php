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

		private static function local_result( $product_id ) {
			$product_id = absint( $product_id );
			$product    = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
			if ( ! $product || ! is_object( $product ) ) {
				return self::error( 'tcas_local_product_missing', 'این لینک به یک محصول موجود در TisaCase اشاره نمی‌کند.' );
			}
			$sku = trim( (string) $product->get_sku() );
			if ( '' === $sku ) {
				return self::error( 'tcas_local_sku_missing', 'برای این محصول در TisaCase شناسهٔ SKU ثبت نشده است.' );
			}
			return array(
				'shop'       => 'tisacase',
				'shop_label' => 'TisaCase',
				'sku'        => $sku,
				'name'       => (string) $product->get_name(),
				'product_id' => $product_id,
				'type'       => (string) $product->get_type(),
			);
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
			return $product_id ? self::local_result( $product_id ) : self::error( 'tcas_local_product_missing', 'محصول این لینک در TisaCase پیدا نشد.' );
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
				if ( is_wp_error( $product ) ) {
					return $product;
				}
				return array(
					'shop'       => 'ariocase',
					'shop_label' => 'ArioCase',
					'sku'        => (string) $product['code'],
					'name'       => (string) $product['name'],
					'product_id' => absint( $product['remote_id'] ),
					'type'       => (string) $product['type'],
				);
			}
			return self::error( 'tcas_unknown_shop', 'دامنهٔ لینک با TisaCase یا ArioCase تنظیم‌شده یکسان نیست.' );
		}
	}
}
