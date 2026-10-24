<?php
defined( 'ABSPATH' ) || exit;
class TCC_Settings {
    public static function defaults() {
        return array( 'enabled' => 'no', 'cards' => array(), 'default_card' => '', 'instructions' => 'مبلغ دقیق سفارش را به کارت زیر واریز کنید و سپس رسید را همین‌جا بفرستید.', 'support' => '', 'review_text' => 'رسید شما توسط فروشگاه بررسی می‌شود.', 'receipt_required' => 'yes', 'reference_required' => 'no', 'max_mb' => 8, 'hours' => 24, 'guest_days' => 7, 'notify_admin' => 'yes', 'notify_customer' => 'yes', 'recipients' => get_option( 'admin_email' ), 'reject_reasons' => "رسید متعلق به این سفارش نیست\nواریز در حساب مقصد پیدا نشد\nمبلغ واریز با سفارش تطبیق ندارد", 'auto_cancel' => 'no' );
    }
    public static function get() { return wp_parse_args( (array) get_option( 'tcc_settings', array() ), self::defaults() ); }
    public static function primary_card() {
        $s = self::get();
        foreach ( $s['cards'] as $card ) { if ( ! empty( $card['active'] ) && $card['id'] === $s['default_card'] ) { return $card; } }
        foreach ( $s['cards'] as $card ) { if ( ! empty( $card['active'] ) ) { return $card; } }
        return null;
    }
    public static function sanitize( $input ) {
        $old = self::get(); $out = self::defaults();
        foreach ( array( 'enabled', 'receipt_required', 'reference_required', 'notify_admin', 'notify_customer', 'auto_cancel' ) as $key ) { $out[$key] = isset( $input[$key] ) && 'yes' === $input[$key] ? 'yes' : 'no'; }
        foreach ( array( 'instructions', 'support', 'review_text', 'reject_reasons' ) as $key ) { $out[$key] = sanitize_textarea_field( isset( $input[$key] ) && is_string( $input[$key] ) ? $input[$key] : '' ); }
        foreach ( array( 'max_mb' => array( 1, 8 ), 'hours' => array( 1, 168 ), 'guest_days' => array( 1, 30 ) ) as $key => $range ) { $out[$key] = max( $range[0], min( $range[1], absint( isset( $input[$key] ) ? $input[$key] : $old[$key] ) ) ); }
        $emails = preg_split( '/[,\s]+/', TCC_Helpers::input( $input, 'recipients' ) );
        $out['recipients'] = implode( ',', array_unique( array_filter( array_map( 'sanitize_email', $emails ), 'is_email' ) ) );
        foreach ( array_slice( isset( $input['cards'] ) && is_array( $input['cards'] ) ? $input['cards'] : array(), 0, 20 ) as $row ) {
            if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) { continue; }
            $number = TCC_Helpers::input( $row, 'number' );
            if ( '' === $number && '' === TCC_Helpers::input( $row, 'holder' ) ) { continue; }
            $number = TCC_Helpers::card( $number );
            if ( ! $number || ! TCC_Helpers::input( $row, 'holder' ) ) { throw new InvalidArgumentException( 'شماره کارت ۱۶رقمی معتبر و نام صاحب کارت برای هر ردیف لازم است؛ تنظیمات ذخیره نشد.' ); }
            $id = substr( hash( 'sha256', $number ), 0, 20 );
            $out['cards'][$id] = array( 'id' => $id, 'number' => $number, 'holder' => TCC_Helpers::input( $row, 'holder' ), 'bank' => TCC_Helpers::input( $row, 'bank' ), 'label' => TCC_Helpers::input( $row, 'label' ), 'active' => ! empty( $row['active'] ) );
        }
        $out['cards'] = array_values( $out['cards'] );
        $out['default_card'] = TCC_Helpers::input( $input, 'default_card' );
        return $out;
    }
}
