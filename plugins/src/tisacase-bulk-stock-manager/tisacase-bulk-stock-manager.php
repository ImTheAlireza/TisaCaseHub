<?php
/**
 * Plugin Name:       TisaCase — مدیریت انبوه موجودی
 * Plugin URI:        https://tisacase.com
 * Description:       انتخاب محصول با جست‌وجو، نمایش متغیرها به‌صورت کارت‌های کوچک، فیلتر دسته با سلکت و اعمال یکجا موجودی روی متغیرهای انتخاب‌شده.
 * Version:           1.1.4
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 5.0
 * Author:            علیرضا شعبان زاده
 * Author URI:        https://tisacase.com
 * Text Domain:       tisacase-bsm
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * TisaCase Hub:      key=bsm; title="مدیریت انبوه موجودی"; icon=box; group=products; page=admin.php?page=tisacase-bulk-stock-manager; screen=woocommerce_page_tisacase-bulk-stock-manager; parent=woocommerce; slug=tisacase-bulk-stock-manager; desc="جست‌وجوی محصول، کارت‌های کوچک متغیرها، فیلتر دسته با سلکت و اعمال یکجا موجودی روی انتخاب‌ها."
 *
 * @package TisaCase_Bulk_Stock_Manager
 */

defined( 'ABSPATH' ) || exit;

define( 'TBSM_VERSION', '1.1.4' );
define( 'TBSM_FILE', __FILE__ );
define( 'TBSM_PATH', plugin_dir_path( __FILE__ ) );
define( 'TBSM_URL', plugin_dir_url( __FILE__ ) );

/**
 * HPOS Compatibility declaration for WooCommerce.
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Register in TisaCase Hub filter.
 */
add_filter( 'tisacase_hub_items', function( $items ) {
	if ( is_array( $items ) && ! isset( $items['bsm'] ) ) {
		$items['bsm'] = array(
			'title' => __( 'مدیریت انبوه موجودی', 'tisacase-bsm' ),
			'desc'  => __( 'جست‌وجوی محصول، کارت‌های کوچک متغیرها، فیلتر دسته با سلکت و اعمال یکجا موجودی روی انتخاب‌ها.', 'tisacase-bsm' ),
			'group' => 'products',
			'icon'  => 'box',
			'dir'   => 'tisacase-bulk-stock-manager',
			'cap'   => 'manage_woocommerce',
			'pages' => array(
				array(
					'label'  => __( 'باز کردن', 'tisacase-bsm' ),
					'path'   => 'admin.php?page=tisacase-bulk-stock-manager',
					'screen' => 'woocommerce_page_tisacase-bulk-stock-manager',
					'parent' => 'woocommerce',
					'slug'   => 'tisacase-bulk-stock-manager',
				),
			),
		);
	}
	return $items;
} );

require_once TBSM_PATH . 'includes/class-tbsm-core.php';
require_once TBSM_PATH . 'includes/class-tbsm-stock.php';
require_once TBSM_PATH . 'includes/class-tbsm-ajax.php';
require_once TBSM_PATH . 'includes/class-tbsm-admin.php';

register_activation_hook( __FILE__, array( 'TBSM_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TBSM_Core', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'TBSM_Core', 'instance' ), 20 );
