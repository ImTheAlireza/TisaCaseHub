<?php
defined( 'ABSPATH' ) || exit;
class TCC_Frontend {
    private static $rendered = array();
    public static function init() {
        add_action( 'woocommerce_thankyou_' . TCC_GATEWAY, array( __CLASS__, 'render' ) );
        add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'details' ) );
        foreach ( array( 'admin_post_tcc_submit', 'admin_post_nopriv_tcc_submit', 'wp_ajax_tcc_submit', 'wp_ajax_nopriv_tcc_submit' ) as $hook ) { add_action( $hook, array( __CLASS__, 'submit' ) ); }
        foreach ( array( 'admin_post_tcc_receipt', 'admin_post_nopriv_tcc_receipt' ) as $hook ) { add_action( $hook, array( __CLASS__, 'file' ) ); }
        add_action( 'template_redirect', array( __CLASS__, 'private_page' ), 1 );
        add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_link' ), 20, 4 );
        add_action( 'wp_enqueue_scripts', function () {
            if ( is_checkout() || is_account_page() ) {
                wp_enqueue_style( 'tcc', TCC_URL . 'assets/tcc.css', array(), TCC_VERSION );
                wp_enqueue_style( 'tcc-customer', TCC_URL . 'assets/customer.css', array( 'tcc' ), TCC_VERSION );
                wp_enqueue_script( 'tcc-frontend', TCC_URL . 'assets/frontend.js', array(), TCC_VERSION, true );
            }
        } );
    }
    public static function email_link( $order, $sent_to_admin, $plain_text, $email ) {
        if ( $sent_to_admin || ! $order || TCC_GATEWAY !== $order->get_payment_method() || ! $order->has_status( array( 'pending', 'on-hold' ) ) || ! ( new TCC_Repository() )->attempt( $order->get_id() ) ) { return; }
        $url = TCC_Access::url( $order );
        if ( $plain_text ) { echo "\nکارت به کارت تیساکیس: ثبت سفارش، تأیید پرداخت نیست. واریز/ارسال رسید و پیگیری:\n" . esc_url_raw( $url ) . "\n"; }
        else { echo '<p>کارت به کارت تیساکیس: ثبت سفارش، تأیید پرداخت نیست. <a href="' . esc_url( $url ) . '">صفحه واریز، ارسال رسید و پیگیری سفارش</a></p>'; }
    }
    public static function private_page() {
        if ( is_order_received_page() || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' ) ) ) {
            if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
            nocache_headers(); header( 'Referrer-Policy: no-referrer' ); header( 'X-Robots-Tag: noindex, nofollow' );
        }
    }
    public static function details( $order ) { if ( TCC_GATEWAY === $order->get_payment_method() ) { self::render( $order->get_id() ); } }
    public static function render( $id ) {
        if ( isset( self::$rendered[$id] ) ) { return; } self::$rendered[$id] = true;
        $repo = new TCC_Repository(); $order = wc_get_order( $id ); $a = $repo->attempt( $id );
        if ( ! TCC_Access::can_view( $order, $a, TCC_Access::request_token() ) ) { echo '<p class="woocommerce-info">برای دیدن پرداخت، وارد حساب صاحب سفارش شوید یا از لینک معتبر پرداخت استفاده کنید. برای لینک مهمان منقضی‌شده با پشتیبانی تماس بگیرید.</p>'; return; }
        $settings = TCC_Settings::get(); $card = json_decode( $a['card'], true );
        $latest = $a['latest_id'] ? $repo->submission( $a['latest_id'] ) : null;
        $events = $repo->history( $id ); $reason = '';
        foreach ( $events as $event ) { if ( (int) $event['submission_id'] === (int) $a['latest_id'] && $event['public_reason'] ) { $reason = $event['public_reason']; break; } }
        $editable = ! $order->is_paid() && $order->has_status( array( 'on-hold', 'pending' ) ) && ! in_array( $a['status'], array( 'approved', 'approving' ), true ) && TCC_Helpers::matches( $order, $a );
        $flash = null;
        $flash_key = isset( $_GET['tcc_notice'] ) && is_string( $_GET['tcc_notice'] ) ? sanitize_key( wp_unslash( $_GET['tcc_notice'] ) ) : '';
        if ( preg_match( '/^[a-f0-9]{32}$/D', $flash_key ) ) { $flash = get_transient( 'tcc_flash_' . $id . '_' . $flash_key ); delete_transient( 'tcc_flash_' . $id . '_' . $flash_key ); }
        include TCC_PATH . 'templates/payment.php';
    }
    public static function submit() {
        $id = absint( TCC_Helpers::post( 'order_id' ) ); $order = wc_get_order( $id ); $a = ( new TCC_Repository() )->attempt( $id ); $error = '';
        try {
            if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! wp_verify_nonce( TCC_Helpers::post( '_wpnonce' ), 'tcc_submit_' . $id ) || ! TCC_Access::can_view( $order, $a, TCC_Access::request_token() ) ) { TCC_Helpers::failure( 'دسترسی معتبر نیست؛ صفحه پرداخت را دوباره باز کنید.' ); }
            TCC_Helpers::service()->submit( $id, array( 'token' => TCC_Access::request_token(), 'request_key' => TCC_Helpers::post( 'request_key' ), 'reference' => TCC_Helpers::post( 'reference' ), 'last4' => TCC_Helpers::post( 'last4' ), 'note' => TCC_Helpers::post( 'note' ) ), isset( $_FILES['receipt'] ) ? $_FILES['receipt'] : null );
        } catch ( Throwable $e ) { $error = $e instanceof RuntimeException ? $e->getMessage() : 'ارسال رسید انجام نشد؛ صفحه را باز کنید و دوباره تلاش کنید.'; }
        if ( wp_doing_ajax() ) {
            if ( $error ) { wp_send_json_error( array( 'message' => $error ), 400 ); }
            wp_send_json_success( array( 'message' => 'رسید ثبت شد؛ پرداخت هنوز تأیید نشده است.', 'redirect' => TCC_Access::url( $order ) ) );
        }
        if ( ! TCC_Access::can_view( $order, $a, TCC_Access::request_token() ) ) { wp_die( esc_html( $error ), '', array( 'response' => 403 ) ); }
        $key = bin2hex( random_bytes( 16 ) );
        set_transient( 'tcc_flash_' . $id . '_' . $key, array( 'error' => (bool) $error, 'message' => $error ?: 'رسید ثبت شد و منتظر بررسی فروشگاه است؛ پرداخت هنوز تأیید نشده است.' ), 300 );
        wp_safe_redirect( add_query_arg( 'tcc_notice', $key, TCC_Access::url( $order ) ) ); exit;
    }
    public static function file() {
        $id = isset( $_GET['submission'] ) ? absint( $_GET['submission'] ) : 0;
        $repo = new TCC_Repository(); $s = $repo->submission( $id );
        try {
            if ( ! $s ) { TCC_Helpers::failure( 'رسید در دسترس نیست.' ); }
            $order = wc_get_order( $s['order_id'] ); $a = $repo->attempt( $s['order_id'] );
            if ( ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : '', 'tcc_file_' . $id ) || ! TCC_Access::can_view( $order, $a, TCC_Access::request_token() ) ) { TCC_Helpers::failure( 'دسترسی رسید معتبر نیست.' ); }
            if ( ! in_array( $s['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) { TCC_Helpers::failure( 'نوع فایل مجاز نیست.' ); }
            $path = ( new TCC_Storage() )->path( $s['file_key'] );
            nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'Referrer-Policy: no-referrer' ); header( 'X-Content-Type-Options: nosniff' ); header( 'X-Robots-Tag: noindex, nofollow' );
            header( 'Content-Type: ' . $s['mime'] ); header( 'Content-Length: ' . filesize( $path ) ); header( 'Content-Disposition: inline; filename="receipt-' . $id . '.' . pathinfo( $path, PATHINFO_EXTENSION ) . '"' );
            readfile( $path ); exit;
        } catch ( Throwable $e ) { wp_die( 'رسید در دسترس نیست یا اجازه مشاهده ندارید.', '', array( 'response' => 403 ) ); }
    }
}
