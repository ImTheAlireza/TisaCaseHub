<?php
/**
 * Plugin Name:       TisaCase — گروه‌بندی متغیرها بر اساس برند
 * Plugin URI:        https://tisacase.com
 * Description:       دسته‌بندی گزینه‌های متغیر (مثلاً «مدل») بر اساس برند در صفحهٔ محصول — آیفون، جداکننده، سامسونگ، جداکننده، شیائومی — به‌همراه پنل جستجوپذیر، رنگ برند و سواچ رنگ. حالت تست: اول فقط روی محصول‌های انتخاب‌شده. فقط نمایش در سمت کاربر؛ هیچ محصول، متغیر یا ویژگی‌ای نوشته/تغییر نمی‌شود.
 * Version:           1.5.4
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 5.0
 * Author:            علیرضا شعبان زاده
 * Author URI:        https://tisacase.com
 * Text Domain:       tisacase-brand-variations
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * TisaCase Hub:      title="گروه‌بندی متغیرها بر اساس برند"; icon=grid; group=products; page=admin.php?page=tisacase-brand-variations; screen=woocommerce_page_tisacase-brand-variations; parent=woocommerce; slug=tisacase-brand-variations; desc="مدل‌ها را به برند (آیفون/سامسونگ/شیائومی) دسته‌بندی و در صفحهٔ محصول مرتب می‌کند؛ همراه پنل جستجو و سواچ رنگ."
 *
 * @package TisaCase_Brand_Variations
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'TCBV_VERSION' ) ) {
	$tcbv_header = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
	define( 'TCBV_VERSION', ! empty( $tcbv_header['Version'] ) ? $tcbv_header['Version'] : '1.5.4' );
}
define( 'TCBV_FILE', __FILE__ );
define( 'TCBV_PATH', plugin_dir_path( __FILE__ ) );
define( 'TCBV_URL', plugin_dir_url( __FILE__ ) );

require_once TCBV_PATH . 'includes/class-tcbv-settings.php';
require_once TCBV_PATH . 'includes/class-tcbv-rules.php';
require_once TCBV_PATH . 'includes/class-tcbv-frontend.php';
require_once TCBV_PATH . 'includes/class-tcbv-admin.php';

/**
 * راه‌اندازی: لایهٔ نمایش همیشه، پنل مدیریت فقط در پیشخوان.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-warning"><p><strong>TisaCase — گروه‌بندی متغیرها:</strong> ووکامرس فعال نیست، پس افزونه کاری انجام نمی‌دهد.</p></div>';
				}
			);
			return;
		}

		TCBV_Frontend::init();

		if ( is_admin() ) {
			TCBV_Admin::init();
		}
	},
	20
);

/**
 * فعال‌سازی: فقط اگر تنظیمات وجود نداشت، پیش‌فرض‌ها نوشته می‌شوند.
 * (هیچ جدول، متا یا محصولی ساخته نمی‌شود.)
 */
register_activation_hook(
	__FILE__,
	function () {
		if ( ! is_array( get_option( TCBV_Settings::OPTION ) ) ) {
			add_option( TCBV_Settings::OPTION, TCBV_Settings::defaults() );
		}
	}
);

/**
 * ثبت در هاب تیساکیس (فاز ۳: افزونه خودش را معرفی می‌کند).
 */
add_filter(
	'tisacase_hub_items',
	function ( $items ) {
		if ( is_array( $items ) && ! isset( $items['brandvars'] ) ) {
			$items['brandvars'] = array(
				'title' => __( 'گروه‌بندی متغیرها بر اساس برند', 'tisacase-brand-variations' ),
				'desc'  => __( 'آیفون، جداکننده، سامسونگ، جداکننده، شیائومی — دسته‌بندی مدل‌ها در صفحهٔ محصول با پنل جستجو و سواچ رنگ.', 'tisacase-brand-variations' ),
				'group' => 'products',
				'icon'  => 'grid',
				'dir'   => 'tisacase-brand-variations',
				'cap'   => 'manage_woocommerce',
				'pages' => array(
					array(
						'label'  => __( 'برندها', 'tisacase-brand-variations' ),
						'path'   => 'admin.php?page=tisacase-brand-variations',
						'screen' => 'woocommerce_page_tisacase-brand-variations',
						'parent' => 'woocommerce',
						'slug'   => 'tisacase-brand-variations',
					),
					array(
						'label'  => __( 'نمایش', 'tisacase-brand-variations' ),
						'path'   => 'admin.php?page=tisacase-brand-variations&tab=display',
						'screen' => 'woocommerce_page_tisacase-brand-variations',
					),
					array(
						'label'  => __( 'رنگ‌ها', 'tisacase-brand-variations' ),
						'path'   => 'admin.php?page=tisacase-brand-variations&tab=colors',
						'screen' => 'woocommerce_page_tisacase-brand-variations',
					),
					array(
						'label'  => __( 'پیشرفته', 'tisacase-brand-variations' ),
						'path'   => 'admin.php?page=tisacase-brand-variations&tab=advanced',
						'screen' => 'woocommerce_page_tisacase-brand-variations',
					),
				),
			);
		}
		return $items;
	}
);
