<?php
defined( 'ABSPATH' ) || exit;
class TCC_Helpers {
    public static function digits( $value ) {
        return strtr( (string) $value, array_combine( preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ), str_split( '01234567890123456789' ) ) );
    }
    public static function card( $number ) {
        $number = preg_replace( '/[\s-]+/u', '', self::digits( $number ) );
        if ( ! preg_match( '/^[0-9]{16}$/D', $number ) || preg_match( '/^(.)\1{15}$/', $number ) ) { return ''; }
        $sum = 0;
        for ( $i = 0; $i < 16; $i++ ) { $n = (int) $number[$i] * ( 0 === $i % 2 ? 2 : 1 ); $sum += $n > 9 ? $n - 9 : $n; }
        return 0 === $sum % 10 ? $number : '';
    }
    public static function states() {
        return array( 'awaiting' => 'منتظر رسید', 'under_review' => 'منتظر بررسی', 'rejected' => 'رسید ردشده', 'correction' => 'نیازمند اصلاح', 'reconcile' => 'نیازمند تطبیق', 'approving' => 'تأیید ناتمام؛ نیازمند تطبیق', 'approved' => 'پرداخت تأییدشده' );
    }
    public static function badge( $state ) {
        $states = array_merge( self::states(), array( 'closed' => 'سفارش بسته شده', 'paid_external' => 'پرداخت در ووکامرس ثبت شده؛ تطبیق سابقه' ) );
        return '<span class="tcc-badge tcc-' . esc_attr( $state ) . '">' . esc_html( isset( $states[$state] ) ? $states[$state] : 'نامشخص' ) . '</span>';
    }
    public static function display_badge( $order, $a ) {
        if ( $order->has_status( array( 'cancelled', 'failed', 'refunded', 'trash' ) ) ) { return self::badge( 'closed' ); }
        if ( $order->is_paid() && ! in_array( $a['status'], array( 'approved', 'approving' ), true ) ) { return self::badge( 'paid_external' ); }
        return self::badge( $a['status'] );
    }
    public static function event_label( $action ) {
        $labels = array_merge( self::states(), array( 'approval_intent' => 'شروع ثبت تأیید؛ پیش از اجرای ووکامرس', 'guest_renewed' => 'تمدید لینک پیگیری', 'reminder' => 'یادآوری ارسال رسید', 'settings_requested' => 'درخواست ذخیره تنظیمات' ) );
        return isset( $labels[$action] ) ? $labels[$action] : $action;
    }
    public static function clip( $text, $length = 1000 ) {
        if ( function_exists( 'mb_substr' ) ) { return mb_substr( $text, 0, $length ); }
        $chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
        return $chars ? implode( '', array_slice( $chars, 0, $length ) ) : '';
    }
    public static function orders_url() {
        $hpos = is_callable( array( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        return admin_url( $hpos ? 'admin.php?page=wc-orders' : 'edit.php?post_type=shop_order' );
    }
    public static function amount( $order ) { return wc_format_decimal( $order->get_total(), 8 ); }
    public static function matches( $order, $attempt ) {
        return self::amount( $order ) === wc_format_decimal( $attempt['amount'], 8 ) && $order->get_currency() === $attempt['currency'];
    }
    public static function fingerprint( $order ) { return hash( 'sha256', self::amount( $order ) . '|' . $order->get_currency() ); }
    public static function service() { return new TCC_Service( new TCC_Repository(), new TCC_Lock(), new TCC_Storage() ); }
    public static function reviewer( $order ) {
        return current_user_can( 'tcc_review_payments' ) && current_user_can( 'edit_shop_order', $order->get_id() );
    }
    public static function failure( $message ) { throw new RuntimeException( $message ); }
    public static function post( $key ) { return isset( $_POST[$key] ) && is_scalar( $_POST[$key] ) ? sanitize_text_field( wp_unslash( $_POST[$key] ) ) : ''; }
    public static function input( $data, $key ) { return isset( $data[$key] ) && is_scalar( $data[$key] ) ? sanitize_text_field( (string) $data[$key] ) : ''; }
}
/** Connection-owned advisory locks die with the DB connection, never by an unsafe TTL steal. */
class TCC_Lock {
    private function name( $id ) { global $wpdb; return 'tcc_' . substr( hash( 'sha256', $wpdb->prefix . '|' . $id ), 0, 48 ); }
    public function acquire( $id ) {
        global $wpdb;
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $this->name( $id ) ) ) ) {
            TCC_Helpers::failure( 'عملیات دیگری در حال انجام است یا قفل دیتابیس در دسترس نیست؛ کمی بعد دوباره تلاش کنید.' );
        }
    }
    public function release( $id ) { global $wpdb; $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name( $id ) ) ); }
}
