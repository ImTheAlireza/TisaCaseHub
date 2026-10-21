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
			if ( ! is_string( $html ) || '' === $html || false === strpos( $html, '<img' ) ) {
				return $html;
			}

			try {
				$uploads = wp_upload_dir();
				if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
					return $html;
				}
				$baseurl = trailingslashit( $uploads['baseurl'] );
				$basedir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );

				return (string) preg_replace_callback(
					'/(?<!<picture>)\s*<img\b[^>]+>/i',
					static function ( $match ) use ( $baseurl, $basedir ) {
						$img = $match[0];
						if ( false !== strpos( $img, 'data-no-webp' ) ) {
							return $img;
						}
						$src_match = array();
						if ( ! preg_match( '/\bsrc=["\']([^"\']+\.(?:jpe?g|png))["\']/i', $img, $src_match ) ) {
							return $img;
						}
						$src_url = $src_match[1];
						if ( 0 !== strpos( $src_url, $baseurl ) ) {
							return $img;
						}

						$rel = ltrim( substr( $src_url, strlen( $baseurl ) ), '/' );
						$abs = $basedir . $rel;

						$sources = '';
						if ( is_file( $abs . '.avif' ) ) {
							$sources .= '<source type="image/avif" srcset="' . esc_url( $src_url . '.avif' ) . '">';
						}
						if ( is_file( $abs . '.webp' ) ) {
							$sources .= '<source type="image/webp" srcset="' . esc_url( $src_url . '.webp' ) . '">';
						}
						if ( '' === $sources ) {
							return $img;
						}
						return '<picture>' . $sources . trim( $img ) . '</picture>';
					},
					$html
				);
			} catch ( Throwable $e ) {
				return $html;
			}
		}

		/**
		 * افزودن یا حذف قوانین بازنویسی مستقیم WebP/AVIF در `wp-content/uploads/.htaccess`.
		 *
		 * @param bool $enable فعال یا غیرفعال کردن قوانین.
		 * @return bool
		 */
		public static function sync_htaccess( $enable ) {
			$uploads = wp_upload_dir();
			if ( empty( $uploads['basedir'] ) ) {
				return false;
			}
			$file  = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . '.htaccess' );
			$lines = array();

			if ( $enable ) {
				$lines = array(
					'<IfModule mod_rewrite.c>',
					'RewriteEngine On',
					'RewriteCond %{HTTP_ACCEPT} image/avif',
					'RewriteCond %{REQUEST_FILENAME}\.avif -f',
					'RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.avif [T=image/avif,L]',
					'RewriteCond %{HTTP_ACCEPT} image/webp',
					'RewriteCond %{REQUEST_FILENAME}\.webp -f',
					'RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.webp [T=image/webp,L]',
					'</IfModule>',
					'<IfModule mod_headers.c>',
					'Header append Vary Accept env=REDIRECT_accept',
					'</IfModule>',
				);
			}

			if ( ! function_exists( 'insert_with_markers' ) ) {
				require_once ABSPATH . 'wp-admin/includes/misc.php';
			}

			return (bool) insert_with_markers( $file, self::HTACCESS_MARKER, $lines );
		}
	}
}
