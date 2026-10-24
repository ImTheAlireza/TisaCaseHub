<?php
defined( 'ABSPATH' ) || exit;
class TCC_Gateway extends WC_Payment_Gateway {
    public function __construct() {
        $this->id = TCC_GATEWAY; $this->method_title = TCC_TITLE; $this->method_description = 'رسید خصوصی · تأیید و رد داخل سفارش';
        $this->has_fields = false; $this->supports = array( 'products' );
        $s = TCC_Settings::get(); $this->enabled = $s['enabled']; $this->title = TCC_TITLE;
        $this->description = 'بررسی دستی پرداخت؛ پس از ثبت سفارش، واریز کنید و رسید را در سایت بفرستید.';
        $this->order_button_text = 'ثبت سفارش و ادامه کارت‌به‌کارت';
    }
    public function is_available() {
        if ( ! parent::is_available() || ! TCC_Settings::primary_card() || get_option( 'tcc_db_version' ) !== TCC_VERSION || ! in_array( get_woocommerce_currency(), array( 'IRR', 'IRT' ), true ) ) { return false; }
        try { ( new TCC_Storage() )->base(); return true; } catch ( Throwable $e ) { return false; }
    }
    public function admin_options() { echo '<h2>' . esc_html( TCC_TITLE ) . '</h2><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=tisacase-card-to-card' ) ) . '">تنظیمات کارت به کارت تیساکیس</a>'; }
    public function process_payment( $order_id ) {
        try {
            TCC_Helpers::service()->begin( $order_id );
            if ( WC()->cart ) { WC()->cart->empty_cart(); }
            return array( 'result' => 'success', 'redirect' => TCC_Access::url( wc_get_order( $order_id ) ) );
        } catch ( Throwable $e ) { wc_add_notice( $e->getMessage(), 'error' ); return array( 'result' => 'failure' ); }
    }
}
