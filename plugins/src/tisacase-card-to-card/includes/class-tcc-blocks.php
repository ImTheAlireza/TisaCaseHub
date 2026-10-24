<?php
defined( 'ABSPATH' ) || exit;
class TCC_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
    protected $name = 'tisacase_card_to_card';
    public function initialize() { $this->settings = TCC_Settings::get(); }
    public function is_active() { return ( new TCC_Gateway() )->is_available(); }
    public function get_payment_method_script_handles() {
        wp_register_script( 'tcc-blocks', TCC_URL . 'assets/blocks.js', array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ), TCC_VERSION, true );
        return array( 'tcc-blocks' );
    }
    public function get_payment_method_data() { return array( 'title' => TCC_TITLE, 'description' => 'پس از ثبت سفارش واریز کنید و رسید را در سایت بفرستید. پرداخت با بررسی فروشگاه تأیید می‌شود.', 'supports' => array( 'products' ) ); }
}
