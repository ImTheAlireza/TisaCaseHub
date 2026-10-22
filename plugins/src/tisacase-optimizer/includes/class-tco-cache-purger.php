<?php
/**
 * پاک‌سازی کش صفحه/تصویر و CDN پس از تغییر واقعی فایل‌ها.
 *
 * چرا؟ وقتی فایل تصویر با نسخهٔ سبک‌تر «جایگزین» می‌شود، آدرس فایل عوض نمی‌شود؛
 * بنابراین کش صفحه، کش تصویر (Optimole/Imagify) و CDN همچنان نسخهٔ قدیمی را
 * نگه می‌دارند و کاربر فکر می‌کند عملیات انجام نشده. این کلاس فقط پس از عملیات
 * موفق و در صورت وجود افزونهٔ کش، پاک‌سازی استاندارد را صدا می‌زند؛ هیچ افزونه‌ای
 * اجباری نیست و در نبود آن‌ها بی‌صدا کاری نمی‌کند.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Cache_Purger' ) ) {

	/**
	 * پاک‌سازی سازگار با کش‌های رایج.
	 */
	final class TisaCase_Optimizer_Cache_Purger {

		/**
		 * پاک‌سازی کش پس از عملیات دسته‌ای.
		 *
		 * @param int[]    $attachment_ids پیوست‌های تغییرکرده.
		 * @param string[] $urls           نشانی فایل‌های تغییرکرده (اختیاری).
		 * @return string[] نام سرویس‌هایی که پاک‌سازی شدند (برای گزارش به کاربر).
		 */
		public static function purge( array $attachment_ids = array(), array $urls = array() ) {
			$done = array();

			try {
				// قلاب عمومی برای هر افزونه‌ای که بخواهد هماهنگ شود.
				do_action( 'tisacase_optimizer_after_images_changed', $attachment_ids, $urls );

				// WP Rocket — پاک‌سازی هدفمند فایل‌ها + صفحهٔ اصلی و آرشیوها.
				if ( function_exists( 'rocket_clean_files' ) ) {
					if ( ! empty( $urls ) ) {
						rocket_clean_files( $urls );
					}
					if ( function_exists( 'rocket_clean_home' ) ) {
						rocket_clean_home();
					}
					$done[] = 'WP Rocket';
				}

				// LiteSpeed Cache.
				if ( has_action( 'litespeed_purge_all' ) || class_exists( 'LiteSpeed_Cache' ) ) {
					do_action( 'litespeed_purge_all' );
					$done[] = 'LiteSpeed Cache';
				}

				// W3 Total Cache.
				if ( function_exists( 'w3tc_flush_all' ) ) {
					w3tc_flush_all();
					$done[] = 'W3 Total Cache';
				}

				// WP Fastest Cache.
				if ( function_exists( 'wpfc_clear_all_cache' ) ) {
					wpfc_clear_all_cache( true );
					$done[] = 'WP Fastest Cache';
				} elseif ( has_action( 'wpfc_clear_all_cache' ) ) {
					do_action( 'wpfc_clear_all_cache', true );
					$done[] = 'WP Fastest Cache';
				}

				// Autoptimize (فقط کدها) — برای اطمینان از به‌روز شدن آدرس‌ها.
				if ( has_action( 'autoptimize_flush_css_js' ) ) {
					do_action( 'autoptimize_flush_css_js' );
					$done[] = 'Autoptimize';
				}

				// SiteGround Optimizer.
				if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
					sg_cachepress_purge_cache();
					$done[] = 'SiteGround Optimizer';
				}

				// Cloudflare (افزونهٔ رسمی) — پاک‌سازی کامل صفحه‌ها.
				if ( has_action( 'cloudflare_purge_everything' ) ) {
					do_action( 'cloudflare_purge_everything' );
					$done[] = 'Cloudflare';
				}

				// کش تصویر ووکامرس/وردپرس در حافظهٔ درخواست.
				if ( function_exists( 'wp_cache_clean_cache' ) === false ) {
					wp_cache_delete( 'alloptions', 'options' );
				}
			} catch ( Throwable $e ) {
				unset( $e );
			}

			return $done;
		}
	}
}
