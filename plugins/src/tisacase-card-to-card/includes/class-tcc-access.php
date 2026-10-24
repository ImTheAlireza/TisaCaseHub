<?php
defined( 'ABSPATH' ) || exit;
class TCC_Access {
    public static function token( $order, $seed ) { return hash_hmac( 'sha256', $order->get_id() . '|' . $order->get_order_key() . '|' . $seed, wp_salt( 'auth' ) ); }
    public static function credentials( $order ) {
        $seed = bin2hex( random_bytes( 24 ) );
        return array( 'access_seed' => $seed, 'access_hash' => hash( 'sha256', self::token( $order, $seed ) ), 'access_expires' => time() + TCC_Settings::get()['guest_days'] * DAY_IN_SECONDS );
    }
    public static function can_view( $order, $a, $token = '' ) {
        if ( ! $order || ! $a || TCC_GATEWAY !== $order->get_payment_method() ) { return false; }
        if ( TCC_Helpers::reviewer( $order ) ) { return true; }
        if ( $order->get_customer_id() > 0 ) { return get_current_user_id() > 0 && (int) $order->get_customer_id() === get_current_user_id(); }
        return time() <= (int) $a['access_expires'] && is_string( $token ) && 64 === strlen( $token ) && hash_equals( $a['access_hash'], hash( 'sha256', self::token( $order, $a['access_seed'] ) ) ) && hash_equals( $a['access_hash'], hash( 'sha256', $token ) );
    }
    public static function request_token() {
        $value = isset( $_REQUEST['tcc_token'] ) && is_string( $_REQUEST['tcc_token'] ) ? wp_unslash( $_REQUEST['tcc_token'] ) : '';
        return preg_match( '/^[a-f0-9]{64}$/D', $value ) ? $value : '';
    }
    public static function url( $order ) {
        $a = ( new TCC_Repository() )->attempt( $order->get_id() );
        $url = $order->get_checkout_order_received_url();
        return $a ? add_query_arg( 'tcc_token', self::token( $order, $a['access_seed'] ), $url ) : $url;
    }
    public static function file_url( $order, $submission, $a ) {
        return add_query_arg( array( 'action' => 'tcc_receipt', 'submission' => $submission['id'], 'tcc_token' => self::token( $order, $a['access_seed'] ), '_wpnonce' => wp_create_nonce( 'tcc_file_' . $submission['id'] ) ), admin_url( 'admin-post.php' ) );
    }
}
