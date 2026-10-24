<?php
defined( 'ABSPATH' ) || exit;
class TCC_Order_Admin {
    private static $forms = array();
    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
        add_action( 'admin_footer', array( __CLASS__, 'forms' ) );
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
        $form = 'tcc-review-' . $order->get_id();
        self::$forms[$order->get_id()] = array( 'a' => $a, 'fingerprint' => TCC_Helpers::fingerprint( $order ) );
        $can_decide = $s && ( ( ! $order->is_paid() && $order->has_status( array( 'on-hold', 'pending' ) ) && ! in_array( $a['status'], array( 'approved', 'approving' ), true ) ) || ( $order->is_paid() && 'approving' === $a['status'] ) );
        $matches = TCC_Helpers::matches( $order, $a );
        include TCC_PATH . 'templates/order-review.php';
    }
    /** Outside Woo's main form: HTML5 form-associated controls avoid nested forms. */
    public static function forms() {
        foreach ( self::$forms as $id => $data ) {
            echo '<form id="tcc-review-' . esc_attr( $id ) . '" class="tcc-review-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"><input type="hidden" name="action" value="tcc_review"><input type="hidden" name="order_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="revision" value="' . esc_attr( $data['a']['revision'] ) . '"><input type="hidden" name="fingerprint" value="' . esc_attr( $data['fingerprint'] ) . '">';
            wp_nonce_field( 'tcc_review_' . $id ); echo '</form>';
        }
    }
    public static function review() {
        $id = absint( TCC_Helpers::post( 'order_id' ) ); $error = '';
        try {
            if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! wp_verify_nonce( TCC_Helpers::post( '_wpnonce' ), 'tcc_review_' . $id ) ) { TCC_Helpers::failure( 'درخواست معتبر نیست؛ صفحه سفارش را دوباره باز کنید.' ); }
            $data = array(); foreach ( array( 'revision', 'fingerprint', 'decision', 'reason', 'private_note', 'confirmed' ) as $key ) { $data[$key] = TCC_Helpers::post( $key ); }
            TCC_Helpers::service()->decide( $id, $data );
        } catch ( Throwable $e ) { $error = $e instanceof RuntimeException ? $e->getMessage() : 'عملیات کامل نشد؛ وضعیت سفارش و سابقه را بررسی کنید و تأیید را کورکورانه تکرار نکنید.'; }
        if ( wp_doing_ajax() ) { if ( $error ) { wp_send_json_error( array( 'message' => $error ), 400 ); } wp_send_json_success( array( 'message' => 'تصمیم ثبت شد؛ صفحه سفارش به‌روز می‌شود.' ) ); }
        if ( $error ) { wp_die( esc_html( $error ), '', array( 'response' => 400, 'back_link' => true ) ); }
        $order = wc_get_order( $id ); wp_safe_redirect( $order->get_edit_order_url() . '#tcc-order-payment' ); exit;
    }
}
