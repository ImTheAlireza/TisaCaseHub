<?php
/**
 * Plugin Name:       TisaCase — اتصال محصول و موجودی ArioCase
 * Plugin URI:        https://tisacase.com
 * Description:       ایمپورت کدمحور از ArioCase، اتصال محصولات موجود، خواندن زندهٔ موجودی و کنترل آن در سبد و تسویه‌حساب.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 5.0
 * Author:            TisaCase
 * Author URI:        https://tisacase.com
 * Text Domain:       tisacase-ariocase-sync
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * TisaCase Hub:      key=ariocase-sync; title="اتصال ArioCase"; icon=box; group=products; page=admin.php?page=tisacase-ariocase-sync; screen=woocommerce_page_tisacase-ariocase-sync; parent=woocommerce; slug=tisacase-ariocase-sync; desc="ایمپورت و اتصال محصولات با کد مشخص، خواندن زندهٔ موجودی از ArioCase و کنترل موجودی هنگام خرید."
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

define( 'TCAS_VERSION', '1.1.0' );
define( 'TCAS_FILE', __FILE__ );
define( 'TCAS_PATH', plugin_dir_path( __FILE__ ) );
define( 'TCAS_URL', plugin_dir_url( __FILE__ ) );

require_once TCAS_PATH . 'includes/class-tcas-core.php';
require_once TCAS_PATH . 'includes/class-tcas-normalizer.php';
require_once TCAS_PATH . 'includes/class-tcas-api.php';
require_once TCAS_PATH . 'includes/class-tcas-mapping.php';
require_once TCAS_PATH . 'includes/class-tcas-stock.php';
require_once TCAS_PATH . 'includes/class-tcas-live.php';
require_once TCAS_PATH . 'includes/class-tcas-importer.php';
require_once TCAS_PATH . 'includes/class-tcas-logger.php';
require_once TCAS_PATH . 'includes/class-tcas-ajax.php';
require_once TCAS_PATH . 'includes/class-tcas-admin.php';

register_activation_hook( __FILE__, array( 'TCAS_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TCAS_Core', 'deactivate' ) );

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_filter( 'tisacase_hub_items', function( $items ) {
	if ( is_array( $items ) && ! isset( $items['ariocase-sync'] ) ) {
		$items['ariocase-sync'] = array(
			'title' => 'اتصال ArioCase',
			'desc'  => 'ایمپورت و اتصال محصولات با کد مشخص، خواندن زندهٔ موجودی و کنترل خرید.',
			'group' => 'products',
			'icon'  => 'box',
			'dir'   => 'tisacase-ariocase-sync',
			'cap'   => 'manage_woocommerce',
			'pages' => array(
				array(
					'label'  => 'باز کردن',
					'path'   => 'admin.php?page=tisacase-ariocase-sync',
					'screen' => 'woocommerce_page_tisacase-ariocase-sync',
					'parent' => 'woocommerce',
					'slug'   => 'tisacase-ariocase-sync',
				),
			),
		);
	}
	return $items;
} );

add_action( 'plugins_loaded', array( 'TCAS_Core', 'instance' ), 25 );
