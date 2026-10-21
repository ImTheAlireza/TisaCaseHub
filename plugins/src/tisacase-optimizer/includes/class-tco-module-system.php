<?php
/**
 * ماژول ۴: کرون، سلامت هاست و خلبان خودکار (همراه با رادار زندهٔ وضعیت سرور در همین نسخه!).
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Module_System' ) ) {

	/**
	 * ماژول ۴: سلامت سرور و کرون.
	 */
	final class TisaCase_Optimizer_Module_System implements TisaCase_Optimizer_Module_Interface {

		/**
		 * @return string
		 */
		public function id() {
			return 'system';
		}

		/**
		 * @return array<string,mixed>
		 */
		public function meta() {
			return array(
				'id'       => 'system',
				'title'    => __( 'سلامت سرور و کرون', 'tisacase-optimizer' ),
				'subtitle' => __( 'رادار زندهٔ موتور تصویر سرور (Imagick / GD / WebP / AVIF)، حافظهٔ PHP، OPcache، کش آبجکت و رویدادهای WP-Cron.', 'tisacase-optimizer' ),
				'badge'    => __( 'رادار زنده + فاز ۴', 'tisacase-optimizer' ),
				'active'   => true,
				'icon'     => 'activity',
			);
		}

		/**
		 * اطلاعات تشخیصی واقعی سرور برای اطمینان از سلامت پردازش تصویر و منابع هاست.
		 *
		 * @return array<string,mixed>
		 */
		public function boot_payload() {
			$caps   = TisaCase_Optimizer_Image_Processor::capabilities();
			$crons  = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
			$events = 0;
			if ( is_array( $crons ) ) {
				foreach ( $crons as $hooks ) {
					if ( is_array( $hooks ) ) {
						$events += count( $hooks );
					}
				}
			}

			return array(
				'diagnostics' => array(
					'php_version'    => PHP_VERSION,
					'wp_version'     => get_bloginfo( 'version' ),
					'wc_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : '—',
					'memory_limit'   => (string) ini_get( 'memory_limit' ),
					'max_execution'  => (string) ini_get( 'max_execution_time' ) . 's',
					'upload_max'     => size_format( wp_max_upload_size() ),
					'image_engine'   => $caps['engine'],
					'webp_supported' => (bool) $caps['webp'],
					'avif_supported' => (bool) $caps['avif'],
					'icc_supported'  => (bool) $caps['icc'],
					'opcache_active' => function_exists( 'opcache_get_status' ) && false !== @opcache_get_status( false ),
					'object_cache'   => (bool) wp_using_ext_object_cache(),
					'cron_events'    => $events,
					'next_vault_gc'  => wp_next_scheduled( TisaCase_Optimizer_Vault::CRON_PURGE_HOOK )
						? gmdate( 'Y-m-d H:i', (int) wp_next_scheduled( TisaCase_Optimizer_Vault::CRON_PURGE_HOOK ) )
						: '—',
				),
			);
		}
	}
}
