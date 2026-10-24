<?php
/**
 * Plugin Name:       TisaCase — کارت به کارت تیساکیس
 * Plugin URI:        https://tisacase.com
 * Description:       کارت‌به‌کارت با رسید خصوصی، نمایش روش پرداخت در لیست سفارش‌ها و تأیید/رد مستقیم داخل سفارش ووکامرس.
 * Version:           1.2.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.2
 * Author:            علیرضا شعبان زاده
 * License:           GPL-2.0-or-later
 * Text Domain:       tisacase-card-to-card
 * TisaCase Hub:      key=card-to-card; title="کارت به کارت تیساکیس"; icon=doc; group=orders; page=admin.php?page=tisacase-card-to-card; screen=woocommerce_page_tisacase-card-to-card; parent=woocommerce; slug=tisacase-card-to-card; cap=manage_options; desc="تنظیمات کارت‌به‌کارت؛ بررسی، تأیید و رد رسید مستقیماً از داخل سفارش."
 */
defined( 'ABSPATH' ) || exit;
define( 'TCC_VERSION', '1.2.0' );
define( 'TCC_FILE', __FILE__ );
define( 'TCC_PATH', plugin_dir_path( __FILE__ ) );
define( 'TCC_URL', plugin_dir_url( __FILE__ ) );
define( 'TCC_GATEWAY', 'tisacase_card_to_card' );
define( 'TCC_TITLE', 'کارت به کارت تیساکیس' );
foreach ( array( 'helpers', 'settings', 'permissions', 'repository', 'storage', 'access', 'service', 'notifications', 'frontend', 'order-admin', 'order-list', 'admin' ) as $tcc_part ) {
    require_once TCC_PATH . 'includes/class-tcc-' . $tcc_part . '.php';
}
register_activation_hook( __FILE__, array( 'TCC_Repository', 'install' ) );
register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'tcc_maintenance' );
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( 'tcc_notify', null, 'tisacase-card-to-card' );
    }
} );
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            if ( current_user_can( 'activate_plugins' ) ) {
                echo '<div class="notice notice-warning"><p>کارت به کارت تیساکیس: ووکامرس باید فعال باشد.</p></div>';
            }
        } );
        return;
    }
    if ( get_option( 'tcc_db_version' ) !== TCC_VERSION ) { TCC_Repository::install(); }
    require_once TCC_PATH . 'includes/class-tcc-gateway.php';
    add_filter( 'woocommerce_payment_gateways', function ( $gateways ) { $gateways[] = 'TCC_Gateway'; return $gateways; } );
    TCC_Frontend::init();
    TCC_Order_Admin::init();
    TCC_Order_List::init();
    TCC_Admin::init();
    TCC_Notifications::init();
}, 20 );
add_action( 'woocommerce_blocks_loaded', function () {
    if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        require_once TCC_PATH . 'includes/class-tcc-blocks.php';
        add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) { $registry->register( new TCC_Blocks() ); } );
    }
} );
// No blanket compatibility declaration before real WooCommerce staging validation.
add_filter( 'tisacase_hub_items', function ( $items ) {
    $items['card-to-card'] = array(
        'title' => TCC_TITLE, 'desc' => 'تنظیمات کارت‌به‌کارت؛ بررسی و تأیید/رد داخل سفارش.',
        'group' => 'orders', 'icon' => 'doc', 'dir' => 'tisacase-card-to-card', 'cap' => 'manage_options',
        'pages' => array( array( 'label' => 'تنظیمات', 'path' => 'admin.php?page=tisacase-card-to-card', 'screen' => 'woocommerce_page_tisacase-card-to-card', 'parent' => 'woocommerce', 'slug' => 'tisacase-card-to-card' ) ),
    );
    return $items;
} );
