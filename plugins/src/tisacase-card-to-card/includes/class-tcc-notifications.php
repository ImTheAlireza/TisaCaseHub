<?php
defined( 'ABSPATH' ) || exit;
class TCC_Notifications {
    public static function init() {
        add_action( 'tcc_notify', array( __CLASS__, 'send' ) );
        add_action( 'tcc_maintenance', array( __CLASS__, 'maintenance' ) );
    }
    public static function enqueue( $event ) {
        try {
            if ( function_exists( 'as_enqueue_async_action' ) ) { as_enqueue_async_action( 'tcc_notify', array( $event ), 'tisacase-card-to-card' ); }
            else { wp_schedule_single_event( time() + 10, 'tcc_notify', array( $event ) ); }
        } catch ( Throwable $e ) { /* Maintenance retries durable events. */ }
    }
    public static function send( $id ) {
        global $wpdb;
        $lock = new TCC_Lock();
        try { $lock->acquire( 'notify_' . $id ); } catch ( Throwable $e ) { return; }
        try {
            $repo = new TCC_Repository(); $event = $repo->event( $id ); if ( ! $event || (int) $event['next_notify'] > time() ) { return; }
            $retry = false;
            $order = wc_get_order( $event['order_id'] ); $a = $repo->attempt( $event['order_id'] ); if ( ! $order || ! $a ) { $wpdb->update( TCC_Repository::table( 'events' ), array( 'notify_admin' => 1, 'notify_customer' => 1 ), array( 'id' => $id ) ); return; }
            $settings = TCC_Settings::get(); $states = TCC_Helpers::states();
            $action = $event['action']; $title = isset( $states[$action] ) ? $states[$action] : ( 'reminder' === $action ? 'یادآوری ارسال رسید' : 'تمدید لینک پیگیری' );
            $current = $a['status'] === $action || in_array( $action, array( 'reminder', 'guest_renewed' ), true );
            $subject = 'کارت به کارت تیساکیس — سفارش ' . $order->get_order_number() . ' — ' . $title;
            if ( ! $event['notify_admin'] ) {
                $sent = true;
                if ( $current && 'under_review' === $action && 'yes' === $settings['notify_admin'] && $settings['recipients'] ) {
                    $sent = wp_mail( $settings['recipients'], $subject, "رسید جدید برای بررسی. این پیام تأیید پرداخت نیست.\n" . $order->get_edit_order_url() . '#tcc-order-payment' );
                }
                if ( $sent ) { $wpdb->update( TCC_Repository::table( 'events' ), array( 'notify_admin' => 1 ), array( 'id' => $id ) ); } else { $retry = true; }
            }
            if ( ! $event['notify_customer'] ) {
                $sent = true;
                if ( $current && in_array( $action, array( 'under_review', 'rejected', 'correction', 'reconcile', 'approved', 'reminder', 'guest_renewed' ), true ) && 'yes' === $settings['notify_customer'] && is_email( $order->get_billing_email() ) ) {
                    $message = $title . "\n" . $event['public_reason'] . "\n";
                    if ( 'under_review' === $action ) { $message .= "رسید ثبت شده است؛ پرداخت هنوز تأیید نشده است.\n"; }
                    $sent = wp_mail( $order->get_billing_email(), $subject, $message . "پیگیری سفارش:\n" . TCC_Access::url( $order ) );
                }
                if ( $sent ) { $wpdb->update( TCC_Repository::table( 'events' ), array( 'notify_customer' => 1 ), array( 'id' => $id ) ); } else { $retry = true; }
            }
            if ( $retry ) { $tries = min( 30, (int) $event['notify_attempts'] + 1 ); $wpdb->update( TCC_Repository::table( 'events' ), array( 'notify_attempts' => $tries, 'next_notify' => time() + min( DAY_IN_SECONDS, 300 * ( 2 ** min( $tries, 8 ) ) ) ), array( 'id' => $id ) ); }
        } finally { $lock->release( 'notify_' . $id ); }
    }
    public static function maintenance() {
        global $wpdb;
        $events = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . TCC_Repository::table( 'events' ) . ' WHERE (notify_admin=0 OR notify_customer=0) AND next_notify<=%d ORDER BY id ASC LIMIT 50', time() ) );
        foreach ( $events as $id ) { self::send( $id ); }
        $due = $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM ' . TCC_Repository::table( 'attempts' ) . " WHERE status='awaiting' AND reminded=0 AND expires_at<=%s ORDER BY expires_at ASC LIMIT 100", gmdate( 'Y-m-d H:i:s' ) ) );
        foreach ( $due as $id ) { try { TCC_Helpers::service()->deadline( $id ); } catch ( Throwable $e ) { /* Re-check on next bounded run; no forced cancellation. */ } }
    }
}
