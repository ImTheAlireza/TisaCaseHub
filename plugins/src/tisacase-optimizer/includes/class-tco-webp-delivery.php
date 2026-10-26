<?php
/**
 * تحویل فوق‌سبک نسخه‌های موازی WebP / AVIF در فرانت‌اند (`<picture>` و قوانین `.htaccess`).
 *
 * این کلاس در بازدیدهای عادی سایت فقط چند فیلتر رشته‌ای بدون هیچ کوئری دیتابیس اجرا می‌کند
 * و در یک بلوک محافظ `try/catch` بسته شده تا هرگز ریسکی روی رندر یا چکاوت فروشگاه نداشته باشد.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_WebP_Delivery' ) ) {

	/**
	 * بازنویس تگ `<picture>` و مدیریت `.htaccess`.
	 */
	final class TisaCase_Optimizer_WebP_Delivery {

		const HTACCESS_MARKER = 'TisaCase Optimizer WebP Delivery';

		/**
		 * راه‌اندازی فیلترهای فرانت‌اند فقط اگر روش `<picture>` فعال باشد.
		 *
		 * @return void
		 */
		public static function init_frontend() {
			if ( is_admin() || is_feed() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}
			$settings = get_option( TisaCase_Optimizer_Settings::OPTION_SETTINGS, array() );
			if ( ! is_array( $settings ) || empty( $settings['delivery_method'] ) || 'picture' !== $settings['delivery_method'] ) {
				return;
			}

			add_filter( 'the_content', array( __CLASS__, 'rewrite_html_images' ), 99 );
			add_filter( 'post_thumbnail_html', array( __CLASS__, 'rewrite_html_images' ), 99 );
			add_filter( 'woocommerce_product_get_image', array( __CLASS__, 'rewrite_html_images' ), 99 );
			add_filter( 'woocommerce_single_product_image_thumbnail_html', array( __CLASS__, 'rewrite_html_images' ), 99 );
		}

		/**
		 * پیچیدن تگ‌های `<img>` دارای فایل موازی `.avif`/`.webp` داخل تگ استاندارد `<picture>`.
		 *
		 * @param string $html محتوای HTML.
		 * @return string
		 */
		public static function rewrite_html_images( $html ) {
			if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '<img' ) ) {
				return $html;
			}

			try {
				$uploads = wp_upload_dir();
				if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
					return $html;
				}
				$baseurl       = trailingslashit( $uploads['baseurl'] );
				$inside_picture = false;
				$pattern        = "~<!--[\\s\\S]*?-->|<script\\b(?:[^>\"']|\"[^\"]*\"|'[^']*')*>[\\s\\S]*?</script\\s*>|<style\\b(?:[^>\"']|\"[^\"]*\"|'[^']*')*>[\\s\\S]*?</style\\s*>|</?picture\\b(?:[^>\"']|\"[^\"]*\"|'[^']*')*>|<img\\b(?:[^>\"']|\"[^\"]*\"|'[^']*')*>~i";

				return (string) preg_replace_callback(
					$pattern,
					static function ( $match ) use ( $baseurl, &$inside_picture ) {
						$token = $match[0];
						if ( 0 === stripos( $token, '<!--' ) || 0 === stripos( $token, '<script' ) || 0 === stripos( $token, '<style' ) ) {
							return $token;
						}
						if ( preg_match( '/^<picture\\b/i', $token ) ) {
							$inside_picture = true;
							return $token;
						}
						if ( preg_match( '/^<\\/picture\\b/i', $token ) ) {
							$inside_picture = false;
							return $token;
						}
						// تصاویر picture موجود را دست‌نخورده می‌گذاریم؛ wrapper تو‌در‌تو
						// HTML نامعتبر می‌سازد و می‌تواند art direction قالب را خراب کند.
						if ( $inside_picture || false !== stripos( $token, 'data-no-webp' ) ) {
							return $token;
						}
						return self::wrap_image_tag( $token, $baseurl );
					},
					$html
				);
			} catch ( Throwable $e ) {
				return $html;
			}
		}

		/**
		 * افزودن sourceهای موجود به یک img مستقل (نه img داخل picture موجود).
		 *
		 * @param string $img     تگ تصویر.
		 * @param string $baseurl آدرس uploads با اسلش نهایی.
		 * @return string
		 */
		private static function wrap_image_tag( $img, $baseurl ) {
			$src_match = array();
			if ( ! preg_match( "/\\bsrc\\s*=\\s*([\"'])([^\"']+)\\1/i", $img, $src_match ) ) {
				return $img;
			}

			$src_url  = html_entity_decode( $src_match[2], ENT_QUOTES, 'UTF-8' );
			$path_end = strcspn( $src_url, '?#' );
			$src_path = substr( $src_url, 0, $path_end );
			if ( 0 !== strpos( $src_path, $baseurl ) || ! preg_match( '/\\.(?:jpe?g|png)$/i', $src_path ) ) {
				return $img;
			}

			$rel = ltrim( wp_normalize_path( rawurldecode( substr( $src_path, strlen( $baseurl ) ) ) ), '/' );
			if ( '' === $rel || in_array( '..', explode( '/', $rel ), true ) ) {
				return $img;
			}

			$sources = '';
			// فهرست سبک نسخه‌های موازی: یک خواندن فایل به‌جای دو is_file
			// برای هر تصویر در هر بازدید. اگر فهرست نباشد، همان is_file
			// (با حافظهٔ موقت) اجرا می‌شود.
			if ( TisaCase_Optimizer_Sidecar_Index::has( $rel, 'avif' ) ) {
				$sources .= '<source type="image/avif" srcset="' . esc_url( self::sidecar_url( $src_url, 'avif' ) ) . '">';
			}
			if ( TisaCase_Optimizer_Sidecar_Index::has( $rel, 'webp' ) ) {
				$sources .= '<source type="image/webp" srcset="' . esc_url( self::sidecar_url( $src_url, 'webp' ) ) . '">';
			}
			return '' === $sources ? $img : '<picture>' . $sources . $img . '</picture>';
		}

		/** افزودن پسوند نسخهٔ جانبی پیش از query string یا fragment. */
		private static function sidecar_url( $url, $ext ) {
			$split = strcspn( (string) $url, '?#' );
			return substr( (string) $url, 0, $split ) . '.' . $ext . substr( (string) $url, $split );
		}

		/** قوانین امن مذاکره و کش برای نسخه‌های موازی تصویر. */
		public static function htaccess_rules() {
			return array(
				// اگر هدر Vary قابل تنظیم نباشد، rewrite هم خاموش می‌ماند تا
				// cache مشترک نسخهٔ AVIF/WebP را به مرورگر ناسازگار تحویل ندهد.
				'<IfModule mod_headers.c>',
				'<IfModule mod_rewrite.c>',
				'RewriteEngine On',
				'RewriteCond %{HTTP_ACCEPT} image/avif [NC]',
				'RewriteCond %{HTTP_ACCEPT} !(^|,)[[:space:]]*image/avif[^,]*;[[:space:]]*q[[:space:]]*=[[:space:]]*0([.]0+)?([[:space:]]*;[^,]*)?[[:space:]]*(,|$) [NC]',
				'RewriteCond %{REQUEST_FILENAME}\\.avif -f',
				'RewriteRule ^(.+)\\.(jpe?g|png)$ $1.$2.avif [T=image/avif,L]',
				'RewriteCond %{HTTP_ACCEPT} image/webp [NC]',
				'RewriteCond %{HTTP_ACCEPT} !(^|,)[[:space:]]*image/webp[^,]*;[[:space:]]*q[[:space:]]*=[[:space:]]*0([.]0+)?([[:space:]]*;[^,]*)?[[:space:]]*(,|$) [NC]',
				'RewriteCond %{REQUEST_FILENAME}\\.webp -f',
				'RewriteRule ^(.+)\\.(jpe?g|png)$ $1.$2.webp [T=image/webp,L]',
				'</IfModule>',
				'Header merge Vary Accept',
				'</IfModule>',
			);
		}

		/**
		 * افزودن/حذف کنترل‌شدهٔ بلوک rewrite داخل uploads/.htaccess.
		 *
		 * @param bool $enable فعال یا غیرفعال کردن قوانین.
		 * @return bool
		 */
		public static function sync_htaccess( $enable ) {
			$uploads = wp_upload_dir();
			if ( empty( $uploads['basedir'] ) ) {
				return ! $enable;
			}
			$file = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . '.htaccess' );
			if ( ! is_dir( $uploads['basedir'] ) ) {
				return ! $enable && ! file_exists( $file );
			}

			// غیرفعال‌کردن وقتی فایل وجود ندارد باید بی‌صدا موفق شود و فایل تازه نسازد.
			if ( ! $enable && ! file_exists( $file ) ) {
				return true;
			}
			if ( is_link( $file ) || ( file_exists( $file ) && ! is_writable( $file ) ) || ( ! file_exists( $file ) && ! is_writable( dirname( $file ) ) ) ) {
				return false;
			}

			if ( ! function_exists( 'insert_with_markers' ) ) {
				require_once ABSPATH . 'wp-admin/includes/misc.php';
			}
			$ok = (bool) insert_with_markers( $file, self::HTACCESS_MARKER, $enable ? self::htaccess_rules() : array() );
			if ( ! $ok ) {
				return false;
			}
			// تأیید محتوا پس از نوشتن/پاک‌کردن؛ از موفقیت کاذب رابط در هاست‌های
			// read-only یا پیاده‌سازی‌هایی که marker را واقعاً تغییر نداده‌اند جلوگیری می‌کند.
			$written = @file_get_contents( $file );
			if ( ! is_string( $written ) ) {
				return false;
			}
			if ( ! $enable ) {
				return false === strpos( $written, '# BEGIN ' . self::HTACCESS_MARKER )
					&& false === strpos( $written, '# END ' . self::HTACCESS_MARKER );
			}
			return false !== strpos( $written, '# BEGIN ' . self::HTACCESS_MARKER )
				&& false !== strpos( $written, 'Header merge Vary Accept' );
		}
	}
}
