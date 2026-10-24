<?php
/**
 * کلاینت امن وب‌سرویس WooCommerce/JWT در ArioCase.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Api' ) ) {

	final class TCAS_Api {

		private static function error( $code, $message ) {
			return new WP_Error( $code, $message );
		}

		public static function redact( $message, $settings = null ) {
			$settings = is_array( $settings ) ? $settings : TCAS_Core::settings();
			$message  = (string) $message;
			$key      = TCAS_Core::api_key( $settings );
			if ( '' !== $key ) {
				$message = str_replace( $key, '[API-KEY]', $message );
				$message = str_replace( rawurlencode( $key ), '[API-KEY]', $message );
			}
			if ( ! empty( $settings['auth_query'] ) ) {
				$message = preg_replace( '/([?&]' . preg_quote( $settings['auth_query'], '/' ) . '=)[^&\s]+/i', '$1[API-KEY]', $message );
			}
			return $message;
		}

		private static function validated_base_url( $settings ) {
			$base = isset( $settings['base_url'] ) ? untrailingslashit( trim( $settings['base_url'] ) ) : '';
			if ( '' === $base ) {
				return self::error( 'tcas_no_base_url', 'آدرس وب‌سرویس ArioCase تنظیم نشده است.' );
			}
			$parts = wp_parse_url( $base );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
				return self::error( 'tcas_invalid_base_url', 'آدرس وب‌سرویس معتبر نیست.' );
			}
			$allow_http = defined( 'TISACASE_ARIOCASE_ALLOW_HTTP' ) && TISACASE_ARIOCASE_ALLOW_HTTP;
			$scheme     = strtolower( (string) $parts['scheme'] );
			if ( 'https' !== $scheme && ! ( $allow_http && 'http' === $scheme ) ) {
				return self::error( 'tcas_https_required', 'آدرس API باید با HTTPS شروع شود.' );
			}
			if ( isset( $parts['query'] ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				return self::error( 'tcas_invalid_base_url', 'آدرس پایه نباید Query، Fragment یا اطلاعات ورود داشته باشد.' );
			}
			return $base;
		}

		private static function build_url( $code, $kind, $settings ) {
			$base = self::validated_base_url( $settings );
			if ( is_wp_error( $base ) ) {
				return $base;
			}
			$endpoint = 'stock' === $kind && ! empty( $settings['stock_endpoint'] ) ? $settings['stock_endpoint'] : $settings['product_endpoint'];
			$endpoint = trim( (string) $endpoint );
			if ( '' === $endpoint ) {
				return self::error( 'tcas_no_endpoint', 'Endpoint محصول تنظیم نشده است.' );
			}
			if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $endpoint ) || false !== strpos( $endpoint, '..' ) ) {
				return self::error( 'tcas_invalid_endpoint', 'Endpoint باید مسیر نسبی و بدون «..» باشد.' );
			}
			$encoded_code    = rawurlencode( (string) $code );
			$has_placeholder = false !== strpos( $endpoint, '{code}' ) || false !== strpos( $endpoint, '%7Bcode%7D' );
			$endpoint        = str_replace( array( '{code}', '%7Bcode%7D' ), $encoded_code, $endpoint );
			$url             = $base . '/' . ltrim( $endpoint, '/' );
			if ( ! $has_placeholder ) {
				$parameter = ! empty( $settings['code_parameter'] ) ? $settings['code_parameter'] : 'sku';
				$url       = add_query_arg( $parameter, (string) $code, $url );
			}
			return $url;
		}

		/** مسیرهای استاندارد WooCommerce برای دریافت با ID و جزئیات تنوع‌ها. */
		private static function build_id_url( $product_id, $variation_id, $settings, $list = false, $page = 1 ) {
			$base = self::validated_base_url( $settings );
			if ( is_wp_error( $base ) ) {
				return $base;
			}
			$product_id   = absint( $product_id );
			$variation_id = absint( $variation_id );
			if ( ! $product_id ) {
				return self::error( 'tcas_remote_id_missing', 'شناسهٔ عددی محصول ArioCase معتبر نیست.' );
			}
			if ( $list ) {
				$url = $base . '/products/' . $product_id . '/variations?status=publish&per_page=100';
				return add_query_arg( 'page', max( 1, absint( $page ) ), $url );
			}
			if ( $variation_id ) {
				return $base . '/products/' . $product_id . '/variations/' . $variation_id;
			}
			return $base . '/products/' . $product_id;
		}

		private static function request( $code, $kind = 'product' ) {
			$settings = TCAS_Core::settings();
			if ( ! TCAS_Core::is_configured( $settings ) ) {
				return self::error( 'tcas_not_configured', 'تنظیمات API کامل نیست؛ آدرس، Endpoint و روش احراز هویت را بررسی کنید.' );
			}
			$url = self::build_url( $code, $kind, $settings );
			return is_wp_error( $url ) ? $url : self::request_url( $url, $settings );
		}

		private static function request_by_id( $product_id, $variation_id = 0, $list = false, $page = 1 ) {
			$settings = TCAS_Core::settings();
			if ( ! TCAS_Core::is_configured( $settings ) ) {
				return self::error( 'tcas_not_configured', 'تنظیمات API کامل نیست.' );
			}
			$url = self::build_id_url( $product_id, $variation_id, $settings, $list, $page );
			return is_wp_error( $url ) ? $url : self::request_url( $url, $settings );
		}

		private static function request_url( $url, $settings ) {
			$headers = array(
				'Accept'     => 'application/json',
				'User-Agent' => 'TisaCase-ArioCase-Sync/' . TCAS_VERSION,
			);
			$key = TCAS_Core::api_key( $settings );
			if ( 'bearer' === $settings['auth_type'] ) {
				$headers['Authorization'] = 'Bearer ' . $key;
			} elseif ( 'header' === $settings['auth_type'] ) {
				$headers[ $settings['auth_header'] ] = $key;
			} elseif ( 'query' === $settings['auth_type'] ) {
				$url = add_query_arg( $settings['auth_query'], $key, $url );
			}

			$response = wp_remote_get(
				$url,
				array(
					'timeout'             => max( 2, min( 20, absint( $settings['timeout'] ) ) ),
					'redirection'         => 0, // کلید به مقصد Redirect منتقل نشود.
					'sslverify'           => true,
					'limit_response_size' => 5 * 1024 * 1024 + 1,
					'headers'             => $headers,
				)
			);
			if ( is_wp_error( $response ) ) {
				return self::error( 'tcas_http_error', 'ارتباط با ArioCase برقرار نشد: ' . self::redact( $response->get_error_message(), $settings ) );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( 404 === $status ) {
				return self::error( 'tcas_remote_not_found', 'محصول در ArioCase پیدا نشد (HTTP 404).' );
			}
			if ( in_array( $status, array( 401, 403 ), true ) ) {
				return self::error( 'tcas_auth_error', 'احراز هویت ArioCase پذیرفته نشد (HTTP ' . $status . ').' );
			}
			if ( 429 === $status ) {
				return self::error( 'tcas_rate_limited', 'محدودیت تعداد درخواست ArioCase فعال شده است (HTTP 429).' );
			}
			if ( $status < 200 || $status >= 300 ) {
				return self::error( 'tcas_http_status', 'پاسخ ناموفق ArioCase (HTTP ' . $status . ').' );
			}
			$body = (string) wp_remote_retrieve_body( $response );
			if ( strlen( $body ) > 5 * 1024 * 1024 ) {
				return self::error( 'tcas_response_too_large', 'حجم پاسخ ArioCase بیش از حد مجاز است.' );
			}
			$payload = json_decode( $body, true );
			if ( ! is_array( $payload ) || JSON_ERROR_NONE !== json_last_error() ) {
				return self::error( 'tcas_invalid_json', 'پاسخ ArioCase JSON معتبر نیست.' );
			}
			return $payload;
		}

		private static function normalize_product_response( $payload, $code ) {
			$product = TCAS_Normalizer::product( $payload, (string) $code, TCAS_Core::settings() );
			if ( is_wp_error( $product ) ) {
				return $product;
			}
			if ( '' !== $code && ( '' === $product['code'] || $product['code'] !== $code ) ) {
				return self::error( 'tcas_code_mismatch', 'کد محصول در پاسخ ArioCase با کد درخواست‌شده یکسان نیست.' );
			}
			$needs_variations = ! empty( $product['variations_missing'] ) || ( 'variable' === $product['type'] && empty( $product['variations'] ) );
			if ( $needs_variations && ! empty( $product['remote_id'] ) ) {
				$variations = self::fetch_variations( $product['remote_id'] );
				if ( is_wp_error( $variations ) ) {
					return $variations;
				}
				$product['variations'] = $variations;
				unset( $product['variations_missing'] );
			}
			return $product;
		}

		private static function fetch_variations( $parent_remote_id ) {
			$out = array();
			for ( $page = 1; $page <= 21; $page++ ) {
				$payload = self::request_by_id( $parent_remote_id, 0, true, $page );
				if ( is_wp_error( $payload ) ) {
					return $payload;
				}
				if ( ! TCAS_Normalizer::is_list( $payload ) ) {
					return self::error( 'tcas_invalid_variations', 'فهرست تنوع‌های ArioCase ساختار معتبری ندارد.' );
				}
				if ( 21 === $page ) {
					// صفحهٔ ۲۱ فقط برای تشخیص بیش از ۲۰۰۰ تنوع خوانده می‌شود.
					return empty( $payload ) ? $out : self::error( 'tcas_too_many_variations', 'تعداد تنوع‌ها بیش از سقف امن ایمپورت است؛ ابتدا آن را دسته‌بندی کنید.' );
				}
				foreach ( $payload as $raw_variation ) {
					$variation = TCAS_Normalizer::variation( $raw_variation, TCAS_Core::settings() );
					if ( is_wp_error( $variation ) ) {
						return $variation;
					}
					$variation['parent_remote_id'] = absint( $parent_remote_id );
					$out[] = $variation;
				}
				if ( count( $payload ) < 100 ) {
					return $out;
				}
			}
			return self::error( 'tcas_too_many_variations', 'تعداد تنوع‌ها بیش از سقف امن ایمپورت است؛ ابتدا آن را دسته‌بندی کنید.' );
		}

		public static function fetch_product( $code ) {
			$code = trim( (string) $code );
			if ( '' === $code ) {
				return self::error( 'tcas_empty_code', 'کد محصول خالی است.' );
			}
			$payload = self::request( $code, 'product' );
			return is_wp_error( $payload ) ? $payload : self::normalize_product_response( $payload, $code );
		}

		public static function fetch_product_by_id( $remote_id, $code = '' ) {
			$payload = self::request_by_id( $remote_id );
			return is_wp_error( $payload ) ? $payload : self::normalize_product_response( $payload, trim( (string) $code ) );
		}

		private static function find_variation( $parent, $code ) {
			foreach ( (array) $parent['variations'] as $variation ) {
				if ( isset( $variation['code'] ) && $variation['code'] === $code ) {
					return $variation;
				}
			}
			return self::error( 'tcas_variation_not_found', 'تنوع با این کد دقیق در محصول والد ArioCase پیدا نشد.' );
		}

		private static function stock_result( $product, $code, $parent_remote_id = 0 ) {
			if ( '' === $product['code'] || $product['code'] !== $code ) {
				return self::error( 'tcas_code_mismatch', 'کد پاسخ موجودی ArioCase با کد درخواست‌شده یکسان نیست.' );
			}
			$stock = $product['stock'];
			if ( null === $stock['quantity'] && null === $stock['status'] ) {
				foreach ( (array) $product['variations'] as $variation ) {
					if ( $variation['code'] === $code ) {
						$stock = $variation['stock'];
						break;
					}
				}
			}
			if ( null === $stock['quantity'] && null === $stock['status'] ) {
				return self::error( 'tcas_stock_missing', 'موجودی در پاسخ ArioCase پیدا نشد؛ مسیر فیلد موجودی را در تنظیمات پیشرفته مشخص کنید.' );
			}
			return array(
				'code'             => $code,
				'quantity'         => $stock['quantity'],
				'status'           => $stock['status'],
				'product'          => $product,
				'parent_remote_id' => absint( $parent_remote_id ),
			);
		}

		public static function fetch_stock( $code, $identifiers = array() ) {
			$code = trim( (string) $code );
			if ( '' === $code ) {
				return self::error( 'tcas_empty_code', 'کد محصول خالی است.' );
			}
			$identifiers = wp_parse_args( is_array( $identifiers ) ? $identifiers : array(), array(
				'type' => '', 'remote_id' => 0, 'parent_remote_id' => 0, 'parent_code' => '',
			) );
			$settings         = TCAS_Core::settings();
			$remote_id        = absint( $identifiers['remote_id'] );
			$parent_remote_id = absint( $identifiers['parent_remote_id'] );
			$parent_code      = trim( (string) $identifiers['parent_code'] );
			$is_variation     = 'variation' === $identifiers['type'];
			$product          = null;
			$stock_parent_id  = 0;

			// Endpoint موجودی سفارشی اولویت دارد؛ در قرارداد فعلی ArioCase این فیلد خالی است.
			if ( empty( $settings['stock_endpoint'] ) ) {
				if ( $is_variation && $remote_id && $parent_remote_id ) {
					$payload = self::request_by_id( $parent_remote_id, $remote_id );
					if ( is_wp_error( $payload ) ) {
						return $payload;
					}
					$product = self::normalize_product_response( $payload, $code );
					$stock_parent_id = $parent_remote_id;
				} elseif ( $is_variation && ( $parent_remote_id || '' !== $parent_code ) ) {
					$parent = $parent_remote_id ? self::fetch_product_by_id( $parent_remote_id, $parent_code ) : self::fetch_product( $parent_code );
					if ( is_wp_error( $parent ) ) {
						return $parent;
					}
					$product = self::find_variation( $parent, $code );
					$stock_parent_id = ! empty( $parent['remote_id'] ) ? absint( $parent['remote_id'] ) : $parent_remote_id;
				} elseif ( ! $is_variation && $remote_id ) {
					$product = self::fetch_product_by_id( $remote_id, $code );
				}
				if ( is_wp_error( $product ) ) {
					return $product;
				}
			}

			if ( null === $product ) {
				$payload = self::request( $code, 'stock' );
				if ( is_wp_error( $payload ) ) {
					return $payload;
				}
				$product = self::normalize_product_response( $payload, $code );
			}
			if ( is_wp_error( $product ) ) {
				return $product;
			}
			return self::stock_result( $product, $code, $stock_parent_id );
		}
	}
}
