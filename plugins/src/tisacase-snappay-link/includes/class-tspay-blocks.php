<?php
defined( 'ABSPATH' ) || exit;
class TSPAY_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
    protected $name = TSPAY_GATEWAY;
    public function initialize() { $this->settings = get_option( 'woocommerce_' . TSPAY_GATEWAY . '_settings', array() ); }
    public function is_active() { return ( new TSPAY_Gateway() )->is_available(); }
    public function get_payment_method_script_handles() {
        wp_register_script( 'tspay-blocks', TSPAY_URL . 'assets/blocks.js', array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ), TSPAY_VERSION, true );
        return array( 'tspay-blocks' );
    }
    public function get_payment_method_data() {
        $settings = $this->settings;
        return array(
            'title' => isset( $settings['title'] ) ? $settings['title'] : 'پرداخت اقساطی با اسنپ‌پی',
            'description' => isset( $settings['description'] ) ? $settings['description'] : '',
            'warning' => isset( $settings['warning'] ) ? $settings['warning'] : '',
            'fee_percent' => isset( $settings['fee_percent'] ) ? (float) $settings['fee_percent'] : 12,
            'fee_hint' => isset( $settings['fee_hint'] ) ? $settings['fee_hint'] : 'کارمزد اسنپ‌پی (%s٪) به مبلغ کل سفارش افزوده می‌شود.',
            'supports' => array( 'products' ),
        );
    }
}
