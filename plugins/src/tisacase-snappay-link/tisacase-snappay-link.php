<?php
/**
 * Plugin Name:       TisaCase — پرداخت اقساطی با لینک اسنپ‌پی
 * Plugin URI:        https://tisacase.com
 * Description:       روش پرداخت ووکامرس با لینک تنظیم‌پذیر اسنپ‌پی، افزودن کارمزد و بررسی دستی سفارش.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.2
 * Author:            علیرضا شعبان زاده
 * License:           GPL-2.0-or-later
 * Text Domain:       tisacase-snappay-link
 * TisaCase Hub:      key=snappay-link; title="پرداخت اقساطی با اسنپ‌پی"; icon=credit-card; group=orders; page=admin.php?page=wc-settings&tab=checkout&section=tisacase_snappay_link; parent=woocommerce; slug=wc-settings; cap=manage_woocommerce; desc="تنظیم لینک پرداخت، کارمزد و پیام‌های پرداخت اسنپ‌پی."
 */
defined( 'ABSPATH' ) || exit;
define( 'TSPAY_VERSION', '1.0.0' );
define( 'TSPAY_FILE', __FILE__ );
define( 'TSPAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'TSPAY_URL', plugin_dir_url( __FILE__ ) );
define( 'TSPAY_GATEWAY', 'tisacase_snappay_link' );

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            if ( current_user_can( 'activate_plugins' ) ) {
                echo '<div class="notice notice-warning"><p>پرداخت اقساطی با لینک اسنپ‌پی: برای استفاده، ووکامرس باید فعال باشد.</p></div>';
            }
        } );
        return;
    }
    require_once TSPAY_PATH . 'includes/class-tspay-gateway.php';
    add_filter( 'woocommerce_payment_gateways', function ( $gateways ) { $gateways[] = 'TSPAY_Gateway'; return $gateways; } );
    add_action( 'wp_enqueue_scripts', function () {
        if ( is_checkout() || is_account_page() ) {
            wp_enqueue_style( 'tspay', TSPAY_URL . 'assets/tspay.css', array(), TSPAY_VERSION );
        }
    } );
}, 20 );

add_action( 'woocommerce_blocks_loaded', function () {
    if ( class_exists( '\\Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
        require_once TSPAY_PATH . 'includes/class-tspay-blocks.php';
        add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) { $registry->register( new TSPAY_Blocks() ); } );
    }
} );

add_filter( 'tisacase_hub_items', function ( $items ) {
    $items['snappay-link'] = array(
        'title' => 'پرداخت اقساطی با اسنپ‌پی',
        'desc' => 'تنظیم لینک پرداخت، کارمزد و پیام‌های پرداخت اسنپ‌پی.',
        'group' => 'orders', 'icon' => 'credit-card', 'dir' => 'tisacase-snappay-link', 'cap' => 'manage_woocommerce',
        'pages' => array( array( 'label' => 'تنظیمات درگاه', 'path' => 'admin.php?page=wc-settings&tab=checkout&section=tisacase_snappay_link', 'screen' => 'woocommerce_page_wc-settings', 'parent' => 'woocommerce', 'slug' => 'wc-settings' ) ),
    );
    return $items;
} );
