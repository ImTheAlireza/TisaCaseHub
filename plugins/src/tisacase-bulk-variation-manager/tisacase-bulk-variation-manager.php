<?php
/**
 * Plugin Name:       TisaCase — مدیریت گروهی متغیرها و مدل‌ها
 * Plugin URI:        https://tisacase.com
 * Description:       افزونه مدیریت، افزودن، حذف و جایگزینی گروهی متغیرها و قیمت‌گذاری محصولات ووکامرس.
 * Version:           2.1.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 5.0
 * Author:            علیرضا شعبان زاده
 * Author URI:        https://tisacase.com
 * Text Domain:       tisacase-bvm
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * TisaCase Hub:      title="مدیریت متغیرها و مدل‌ها"; icon=layers; group=products; page=admin.php?page=tisacase-bulk-variation-manager; screen=woocommerce_page_tisacase-bulk-variation-manager; parent=woocommerce; slug=tisacase-bulk-variation-manager; desc="تغییر، تولید و بازسازی گروهی متغیرها، ضرب ترکیب‌ها و قیمت‌گذاری یکپارچه."
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

defined( 'ABSPATH' ) || exit;

define( 'TCBVM_VERSION', '2.1.1' );
define( 'TCBVM_FILE', __FILE__ );
define( 'TCBVM_PATH', plugin_dir_path( __FILE__ ) );
define( 'TCBVM_URL', plugin_dir_url( __FILE__ ) );

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
	if ( is_array( $items ) && ! isset( $items['bvm'] ) ) {
		$items['bvm'] = array(
			'title' => __( 'مدیریت متغیرها و مدل‌ها', 'tisacase-bvm' ),
			'desc'  => __( 'مدیریت، افزودن، تغییر نام، حذف و همگام‌سازی گروهی ویژگی‌ها و متغیرهای قاب و محصولات.', 'tisacase-bvm' ),
			'group' => 'products',
			'icon'  => 'layers',
			'dir'   => 'tisacase-bulk-variation-manager',
			'cap'   => 'manage_woocommerce',
			'pages' => array(
				array(
					'label'  => __( 'عملیات گروهی', 'tisacase-bvm' ),
					'path'   => 'admin.php?page=tisacase-bulk-variation-manager',
					'screen' => 'woocommerce_page_tisacase-bulk-variation-manager',
					'parent' => 'woocommerce',
					'slug'   => 'tisacase-bulk-variation-manager',
				),
				array(
					'label'  => __( 'الگوهای مدل', 'tisacase-bvm' ),
					'path'   => 'admin.php?page=tisacase-bulk-variation-manager&tab=presets',
					'screen' => 'woocommerce_page_tisacase-bulk-variation-manager',
				),
				array(
					'label'  => __( 'تاریخچه و بازگردانی', 'tisacase-bvm' ),
					'path'   => 'admin.php?page=tisacase-bulk-variation-manager&tab=runs',
					'screen' => 'woocommerce_page_tisacase-bulk-variation-manager',
				),
			),
		);
	}
	return $items;
} );

require_once TCBVM_PATH . 'includes/class-tcbvm-core.php';
require_once TCBVM_PATH . 'includes/class-tcbvm-db.php';
require_once TCBVM_PATH . 'includes/class-tcbvm-ops.php';
require_once TCBVM_PATH . 'includes/class-tcbvm-backup.php';
require_once TCBVM_PATH . 'includes/class-tcbvm-ajax.php';
require_once TCBVM_PATH . 'includes/class-tcbvm-admin.php';

register_activation_hook( __FILE__, array( 'TCBVM_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TCBVM_Core', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'TCBVM_Core', 'instance' ), 20 );
