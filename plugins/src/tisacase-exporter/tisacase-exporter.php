<?php
/**
 * Plugin Name: TisaCase Exporter — خروجی گرفتن
 * Plugin URI: https://tisacase.com
 * Description: مرکز خروجی گرفتن تیساکیس — پنج بخش (شماره‌ها، سفارش‌ها، مشتری‌ها، محصول‌ها و کدهای تخفیف) با فیلتر، انتخاب ستون، پیش‌نمایش ۲۵ ردیف، چهار قالب TXT/CSV/اکسل/JSON، حذف تکراری با حافظهٔ محدود، ادامه پس از قطعی، تاریخچهٔ اجراها و دستور WP-CLI.
 * Version: 2.0.0
 * Author: علیرضا شعبان زاده
 * Author URI: https://tisacase.com
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Requires Plugins: woocommerce
 * Text Domain: tisacase-exporter
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * TisaCase Hub: key=exporter; title="خروجی گرفتن"; icon=upload; group=orders; page=admin.php?page=tisacase-exporter; screen=woocommerce_page_tisacase-exporter; parent=woocommerce; slug=tisacase-exporter; desc="مرکز خروجی گرفتن تیساکیس — پنج بخش (شماره‌ها، سفارش‌ها، مشتری‌ها، محصول‌ها و کدهای تخفیف) با فیلتر، انتخاب ستون، پیش‌نمایش ۲۵ ردیف، چهار قالب TXT/CSV/اکسل/JSON، حذف تکراری با حافظهٔ محدود، ادامه پس از قطعی، تاریخچهٔ اجراها و دستور WP-CLI."
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TISA_EXPORTER_FILE', __FILE__ );
define( 'TISA_EXPORTER_VERSION', '2.0.0' );
define( 'TISA_EXPORTER_DIR', plugin_dir_path( __FILE__ ) );

/*
 * بارگذاری فقط در زمینه‌های لازم: پنل مدیریت (شامل admin-ajax و admin-post)، کرون و WP-CLI.
 * (پاک‌سازی هنگام حذف افزونه به‌صورت مستقل از طریق uninstall.php انجام می‌شود.)
 * در بازدیدهای عادی سایت، اجرای افزونه به همین چند خط خلاصه می‌شود؛ هیچ کلاس، هوک یا
 * کوئری‌ای روی سرعت فرانت‌اند اثر نمی‌گذارد.
 */
if ( ! ( is_admin()
	|| ( defined( 'DOING_CRON' ) && DOING_CRON )
	|| defined( 'WP_CLI' ) ) ) {
	return;
}

require_once TISA_EXPORTER_DIR . 'includes/class-tce-plugin.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-phone.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-format.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-module.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-module-phones.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-module-orders.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-module-customers.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-module-products.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-module-coupons.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-modules.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-storage.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-session.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-pipeline.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-history.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-ajax.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-download.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-admin-page.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-cli.php';
require_once TISA_EXPORTER_DIR . 'includes/class-tce-lifecycle.php';

TisaCase_Exporter::init();

register_activation_hook( __FILE__, array( 'TisaCase_Exporter_Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TisaCase_Exporter_Lifecycle', 'deactivate' ) );
// پاک‌سازی هنگام Uninstall از طریق uninstall.php (استاندارد وردپرس) انجام می‌شود.

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
	// نام رسمی جدید. «export-phones» به‌عنوان نام قدیمی نگه داشته می‌شود تا اسکریپت‌های موجود نشکنند.
	\WP_CLI::add_command( 'tisacase export', array( 'TisaCase_Exporter_Cli', 'cli_export' ) );
	\WP_CLI::add_command( 'tisacase export-phones', array( 'TisaCase_Exporter_Cli', 'cli_export' ) );
}
