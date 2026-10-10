<?php
/**
 * Plugin Name: TisaCase Pricing
 * Plugin URI:  https://tisacase.com/
 * Description: قیمت‌گذاری یکپارچهٔ ووکامرس برای TisaCase — قوانین داینامیک محصول/دسته/سراسری با حفظ قیمت همکاری و فروش ویژهٔ واقعی؛ تغییر گروهی امن قیمت عادی، فروش ویژه و عمده، از جمله نوشتن دائمی روی همهٔ محصولات (متغیر‌به‌متغیر) با ادامهٔ خودکار بعد از قطعی؛ پیش‌نمایش، گزارش، بازگردانی و CSV؛ مدیریت کدهای تخفیف.
 * Version:     1.3.5
 * Author:      TisaCase
 * License:     GPL-2.0-or-later
 * Text Domain: tisacase-pricing
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 *
 * @package TisaCase_Pricing
 */

defined( 'ABSPATH' ) || exit;

define( 'TCP_VERSION', '1.3.5' );
define( 'TCP_FILE', __FILE__ );
define( 'TCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'TCP_URL', plugin_dir_url( __FILE__ ) );
define( 'TCP_WHOLESALE_META', '_tisacase_wholesale_price' );

require_once TCP_DIR . 'includes/class-tcp-settings.php';
require_once TCP_DIR . 'includes/class-tcp-db.php';
require_once TCP_DIR . 'includes/class-tcp-round.php';
require_once TCP_DIR . 'includes/class-tcp-ops.php';
require_once TCP_DIR . 'includes/class-tcp-ajax.php';
require_once TCP_DIR . 'includes/class-tcp-scheduler.php';
require_once TCP_DIR . 'includes/class-tcp-rules.php';
require_once TCP_DIR . 'includes/class-tcp-coupon-phones.php';
require_once TCP_DIR . 'includes/class-tcp-coupon-reports.php';
require_once TCP_DIR . 'includes/class-tcp-coupons.php';
require_once TCP_DIR . 'includes/class-tcp-admin.php';

register_activation_hook( __FILE__, array( 'TCP_Settings', 'activate' ) );
add_action( 'plugins_loaded', array( 'TCP_Settings', 'instance' ), 20 );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
