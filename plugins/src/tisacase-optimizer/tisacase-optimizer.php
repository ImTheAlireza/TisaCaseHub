<?php
/**
 * Plugin Name:          مرکز بهینه‌سازی تیساکیس (TisaCase Optimizer)
 * Plugin URI:           https://tisacase.com
 * Description:          مرکز چندبخشی بهینه‌سازی و نگهداری فروشگاه تیساکیس — تحلیل دقیق حجم واقعی دیسک و Inode، کاوشگر سنگین‌ترین تصاویر، اسکنر ۱۲لایهٔ تصاویر بدون استفاده با راستی‌آزمایی زندهٔ پیش از حذف و قرنطینهٔ ۳۰ روزه، فشرده‌سازی بومی WebP/AVIF، ادغام هوشمند تکراری‌ها، مدیریت زیرسایزها و سئوی خودکار.
 * Version:              1.0.6
 * Requires at least:    5.8
 * Requires PHP:         7.4
 * Author:               علیرضا شعبان زاده
 * Author URI:           https://tisacase.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          tisacase-optimizer
 * Domain Path:          /languages
 * WC requires at least: 7.0
 * WC tested up to:      9.5
 * TisaCase Hub:         key=optimizer; title="مرکز بهینه‌سازی تیساکیس"; icon=bolt; group=maintenance; page=admin.php?page=tisacase-optimizer; screen=woocommerce_page_tisacase-optimizer; parent=woocommerce; slug=tisacase-optimizer; desc="تحلیل حجم واقعی دیسک و Inode، کاوشگر سنگین‌ترین تصاویر، اسکنر ۱۲لایهٔ تصاویر بدون استفاده با قرنطینهٔ ۳۰ روزه، فشرده‌سازی و WebP، ادغام هوشمند تکراری‌ها و سئوی خودکار."
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

define( 'TCO_VERSION', '1.0.6' );
define( 'TCO_FILE', __FILE__ );
define( 'TCO_DIR', plugin_dir_path( __FILE__ ) );
define( 'TCO_URL', plugin_dir_url( __FILE__ ) );
define( 'TCO_BASENAME', plugin_basename( __FILE__ ) );

/**
 * بارگذاری تنبل کلاس‌ها تا در بازدیدهای فرانت‌اند هیچ فایل مدیریتی پارس نشود.
 *
 * @param string $class نام کلاس.
 * @return void
 */
function tco_autoload( $class ) {
	static $map = array(
		'TisaCase_Optimizer_Settings'          => 'class-tco-settings.php',
		'TisaCase_Optimizer_Session'           => 'class-tco-session.php',
		'TisaCase_Optimizer_Module_Interface'  => 'interface-tco-module.php',
		'TisaCase_Optimizer_Modules'           => 'class-tco-modules.php',
		'TisaCase_Optimizer_Vault'             => 'class-tco-vault.php',
		'TisaCase_Optimizer_Usage'             => 'class-tco-usage.php',
		'TisaCase_Optimizer_Job'               => 'class-tco-job.php',
		'TisaCase_Optimizer_Reference_Scanner' => 'class-tco-reference-scanner.php',
		'TisaCase_Optimizer_Media_Inspector'   => 'class-tco-media-inspector.php',
		'TisaCase_Optimizer_Image_Processor'   => 'class-tco-image-processor.php',
		'TisaCase_Optimizer_Deduplicator'      => 'class-tco-deduplicator.php',
		'TisaCase_Optimizer_Subsizes'          => 'class-tco-subsizes.php',
		'TisaCase_Optimizer_Image_SEO'         => 'class-tco-image-seo.php',
		'TisaCase_Optimizer_WebP_Delivery'     => 'class-tco-webp-delivery.php',
		'TisaCase_Optimizer_Module_Images'     => 'class-tco-module-images.php',
		'TisaCase_Optimizer_Module_Vault'      => 'class-tco-module-vault.php',
		'TisaCase_Optimizer_Module_Database'   => 'class-tco-module-database.php',
		'TisaCase_Optimizer_Module_Frontend'   => 'class-tco-module-frontend.php',
		'TisaCase_Optimizer_Module_System'     => 'class-tco-module-system.php',
		'TisaCase_Optimizer_Ajax'              => 'class-tco-ajax.php',
		'TisaCase_Optimizer_Admin_Page'        => 'class-tco-admin-page.php',
		'TisaCase_Optimizer_CLI'               => 'class-tco-cli.php',
	);

	if ( isset( $map[ $class ] ) ) {
		require_once TCO_DIR . 'includes/' . $map[ $class ];
	}
}
spl_autoload_register( 'tco_autoload' );

/**
 * فعال‌سازی افزونه: ایمن‌سازی پوشهٔ خزانه و زمان‌بندی پاک‌سازی روزانهٔ قرنطینهٔ ۳۰ روزه.
 *
 * @return void
 */
function tco_activate() {
	TisaCase_Optimizer_Vault::base_dir();
	if ( ! wp_next_scheduled( TisaCase_Optimizer_Vault::CRON_PURGE_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', TisaCase_Optimizer_Vault::CRON_PURGE_HOOK );
	}
}
register_activation_hook( __FILE__, 'tco_activate' );

/**
 * غیرفعال‌سازی افزونه: لغو کرون‌ها و برداشتن قوانین `.htaccess` در صورت وجود.
 *
 * @return void
 */
function tco_deactivate() {
	wp_clear_scheduled_hook( TisaCase_Optimizer_Vault::CRON_PURGE_HOOK );
	TisaCase_Optimizer_WebP_Delivery::sync_htaccess( false );
}
register_deactivation_hook( __FILE__, 'tco_deactivate' );

/**
 * اعلام سازگاری کامل با جداول سفارشی سفارش‌ها (HPOS) و بلوک‌های سبد ووکامرس.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TCO_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', TCO_FILE, true );
		}
	}
);

/**
 * بوت محافظه‌کارانه با پوشش کامل خطا تا فروشگاه هرگز دچار اختلال نشود.
 *
 * @return void
 */
function tco_boot() {
	try {
		load_plugin_textdomain( 'tisacase-optimizer', false, dirname( TCO_BASENAME ) . '/languages' );

		// فیلتر سبک غیرفعال‌سازی زیرسایزهای اضافی و بهینه‌سازی خودکار آپلود (از جمله ایمپورت ربات تلگرام).
		add_filter( 'intermediate_image_sizes_advanced', array( 'TisaCase_Optimizer_Subsizes', 'filter_intermediate_sizes' ), 20 );
		add_filter( 'wp_generate_attachment_metadata', array( 'TisaCase_Optimizer_Image_Processor', 'maybe_auto_optimize_upload' ), 20, 2 );
		add_action( TisaCase_Optimizer_Vault::CRON_PURGE_HOOK, array( 'TisaCase_Optimizer_Vault', 'purge_expired_quarantine' ) );

		if ( is_admin() ) {
			TisaCase_Optimizer_Ajax::init();
			TisaCase_Optimizer_Admin_Page::init();
		} else {
			TisaCase_Optimizer_WebP_Delivery::init_frontend();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'tisacase optimize', array( 'TisaCase_Optimizer_CLI', 'handle' ) );
		}
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'TisaCase Optimizer boot error: ' . $e->getMessage() );
		}
	}
}
add_action( 'plugins_loaded', 'tco_boot', 20 );
