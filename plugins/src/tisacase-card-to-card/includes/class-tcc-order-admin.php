<?php
defined( 'ABSPATH' ) || exit;
class TCC_Order_Admin {
    private static $pending = array();
    private static $handled = array();
    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
        add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'capture_native' ), PHP_INT_MAX, 2 );
        add_filter( 'redirect_post_location', array( __CLASS__, 'finalize_native' ), PHP_INT_MAX, 2 );
        add_filter( 'woocommerce_redirect_order_location', array( __CLASS__, 'finalize_native' ), PHP_INT_MAX, 2 );
        add_action( 'shutdown', array( __CLASS__, 'unfinished_native' ) );
        add_action( 'admin_post_tcc_review', array( __CLASS__, 'review' ) );
        add_action( 'wp_ajax_tcc_review', array( __CLASS__, 'review' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
    }
    public static function assets() {
        $screen = get_current_screen();
        if ( $screen && in_array( $screen->id, array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders', 'woocommerce_page_tisacase-card-to-card' ), true ) ) {
            wp_enqueue_style( 'tcc', TCC_URL . 'assets/tcc.css', array(), TCC_VERSION );
            wp_enqueue_style( 'tcc-admin', TCC_URL . 'assets/admin.css', array( 'tcc' ), TCC_VERSION );
            wp_enqueue_script( 'tcc-admin', TCC_URL . 'assets/admin.js', array(), TCC_VERSION, true );
        }
    }
    public static function boxes() {
        $screen = get_current_screen();
        $id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : ( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
        $order = $id ? wc_get_order( $id ) : false;
        if ( $screen && $order && TCC_GATEWAY === $order->get_payment_method() && in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) { add_meta_box( 'tcc-order-payment', TCC_TITLE, array( __CLASS__, 'box' ), $screen->id, 'normal', 'high' ); }
    }
    public static function box( $source ) {
        $order = $source instanceof WC_Order ? $source : wc_get_order( $source->ID );
        if ( ! $order || TCC_GATEWAY !== $order->get_payment_method() ) { echo '<p>این سفارش با کارت به کارت تیساکیس ثبت نشده است.</p>'; return; }
        if ( ! TCC_Helpers::reviewer( $order ) ) { echo '<p>برای دیدن رسید و تصمیم مالی، مجوز بازبینی کارت‌به‌کارت لازم است.</p>'; return; }
        $repo = new TCC_Repository(); $a = $repo->attempt( $order->get_id() );
        if ( ! $a ) { echo '<p>برای این سفارش اطلاعات کارت‌به‌کارت ثبت نشده است.</p>'; return; }
        $card = json_decode( $a['card'], true ); $s = $a['latest_id'] ? $repo->submission( $a['latest_id'] ) : null;
        $native = array( 'order_id' => $order->get_id(), 'revision' => $a['revision'], 'fingerprint' => TCC_Helpers::fingerprint( $order ), 'nonce' => wp_create_nonce( 'tcc_review_' . $order->get_id() ) );
        $notice_key = 'tcc_native_notice_' . get_current_user_id() . '_' . $order->get_id();
        $notice = get_transient( $notice_key ); if ( $notice ) { delete_transient( $notice_key ); }
        $can_decide = $s && ( ( ! $order->is_paid() && $order->has_status( array( 'on-hold', 'pending' ) ) && ! in_array( $a['status'], array( 'approved', 'approving' ), true ) ) || ( $order->is_paid() && 'approving' === $a['status'] ) );
        $matches = TCC_Helpers::matches( $order, $a );
        include TCC_PATH . 'templates/order-review.php';
    }
    private static function native_notice( $id, $message, $error = true ) {
        set_transient( 'tcc_native_notice_' . get_current_user_id() . '_' . $id, array( 'message' => $message, 'error' => $error ), 5 * MINUTE_IN_SECONDS );
    }
    /** Stage only. Woo still has later HPOS/custom-meta saves at this point. */
    public static function capture_native( $id, $source = null ) {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! isset( $_POST['tcc_review'] ) || ! is_array( $_POST['tcc_review'] ) ) { return; }
        $data = wp_unslash( $_POST['tcc_review'] ); $decision = TCC_Helpers::input( $data, 'decision' );
        if ( '' === $decision || isset( self::$handled[$id] ) ) { return; }
        self::$handled[$id] = true;
        try {
            if ( (string) $id !== TCC_Helpers::input( $data, 'order_id' ) || ! wp_verify_nonce( TCC_Helpers::input( $data, 'nonce' ), 'tcc_review_' . $id ) ) { TCC_Helpers::failure( 'درخواست معتبر نیست؛ صفحه سفارش را دوباره باز کنید.' ); }
            if ( ! in_array( $decision, array( 'approve', 'reject', 'correct', 'reconcile', 'renew' ), true ) ) { TCC_Helpers::failure( 'تصمیم انتخاب‌شده معتبر نیست.' ); }
            $order = wc_get_order( $id );
            if ( ! $order || TCC_GATEWAY !== $order->get_payment_method() || ! TCC_Helpers::reviewer( $order ) ) { TCC_Helpers::failure( 'مجوز بررسی این سفارش را ندارید.' ); }
            $data['decision'] = $decision; self::$pending[$id] = $data;
        } catch ( Throwable $e ) { self::native_notice( $id, $e instanceof RuntimeException ? $e->getMessage() : 'تصمیم انتخاب‌شده قابل ثبت نیست؛ صفحه را دوباره باز کنید.' ); }
    }
    /** Run only at the successful native redirect, AFTER every Woo order save. */
    public static function finalize_native( $location, $id ) {
        if ( ! isset( self::$pending[$id] ) ) { return $location; }
        $data = self::$pending[$id]; unset( self::$pending[$id] ); // Never replay on recursive hooks.
        try {
            if ( class_exists( 'WC_Admin_Meta_Boxes' ) && ! empty( WC_Admin_Meta_Boxes::$meta_box_errors ) ) { TCC_Helpers::failure( 'سفارش خطای ذخیره دارد؛ تصمیم مالی اجرا نشد.' ); }
            if ( ! wp_verify_nonce( TCC_Helpers::input( $data, 'nonce' ), 'tcc_review_' . $id ) ) { TCC_Helpers::failure( 'اعتبار درخواست تمام شده؛ صفحه سفارش را دوباره باز کنید.' ); }
            // The service re-reads the saved order and enforces role/order permission,
            // revision, original amount/currency, confirmation, locks and durable intent.
            TCC_Helpers::service()->decide( $id, $data );
            $labels = array( 'approve' => 'تأیید پرداخت', 'reject' => 'رد رسید', 'correct' => 'درخواست اصلاح رسید', 'reconcile' => 'درخواست تطبیق', 'renew' => 'تمدید لینک' );
            self::native_notice( $id, $labels[$data['decision']] . ' همراه سفارش ثبت شد.', false );
        } catch ( Throwable $e ) {
            self::native_notice( $id, 'تصمیم مالی کامل نشد؛ وضعیت سفارش و سابقه را بررسی کنید. ' . ( $e instanceof RuntimeException ? $e->getMessage() : 'قبل از تکرار تأیید، نتیجه قبلی را بررسی کنید.' ) );
        }
        return $location;
    }
    /** A failed/aborted native save never becomes a background financial job. */
    public static function unfinished_native() {
        foreach ( self::$pending as $id => $data ) { self::native_notice( $id, 'ذخیره سفارش کامل نشد؛ تصمیم مالی اجرا نشد. صفحه را دوباره باز کنید.' ); }
        self::$pending = array();
    }
    /** Old tabs must refresh; the retired endpoint cannot bypass native Update. */
    public static function review() {
        $id = absint( TCC_Helpers::post( 'order_id' ) ); $error = '';
        try {
            if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! wp_verify_nonce( TCC_Helpers::post( '_wpnonce' ), 'tcc_review_' . $id ) ) { TCC_Helpers::failure( 'درخواست معتبر نیست؛ صفحه را تازه کنید.' ); }
            $order = wc_get_order( $id );
            if ( ! $order || TCC_GATEWAY !== $order->get_payment_method() || ! TCC_Helpers::reviewer( $order ) ) { TCC_Helpers::failure( 'مجوز بررسی این سفارش را ندارید.' ); }
            TCC_Helpers::failure( 'روش ثبت تصمیم تغییر کرده است؛ صفحه سفارش را دوباره باز کنید و تصمیم را با «به‌روزرسانی سفارش» ثبت کنید.' );
        } catch ( Throwable $e ) { $error = $e instanceof RuntimeException ? $e->getMessage() : 'صفحه سفارش را دوباره باز کنید؛ ثبت تصمیم از به‌روزرسانی سفارش انجام می‌شود.'; }
        if ( wp_doing_ajax() ) { wp_send_json_error( array( 'message' => $error ), 400 ); }
        wp_die( esc_html( $error ), '', array( 'response' => 400, 'back_link' => true ) );
    }
}
