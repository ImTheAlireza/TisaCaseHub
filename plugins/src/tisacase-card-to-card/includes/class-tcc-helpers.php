<?php
defined( 'ABSPATH' ) || exit;
class TCC_Helpers {
    public static function digits( $value ) {
        return strtr( (string) $value, array_combine( preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ), str_split( '01234567890123456789' ) ) );
    }
    /** Presentation only: never changes the stored order/snapshot currency. No floats. */
    public static function rial_amount( $amount, $currency ) {
        if ( ! is_scalar( $amount ) || ! in_array( $currency, array( 'IRR', 'IRT' ), true ) ) { return ''; }
        $amount = self::digits( (string) $amount );
        if ( ! preg_match( '/^([0-9]{1,18})(?:\.([0-9]{1,8}))?$/D', $amount, $parts ) ) { return ''; }
        $whole = ltrim( $parts[1], '0' ); $fraction = isset( $parts[2] ) ? $parts[2] : '';
        if ( 'IRT' === $currency ) { $whole .= '' !== $fraction ? $fraction[0] : '0'; $fraction = substr( $fraction, 1 ); }
        $whole = ltrim( $whole, '0' ); $fraction = rtrim( $fraction, '0' );
        return ( '' === $whole ? '0' : $whole ) . ( '' !== $fraction ? '.' . $fraction : '' );
    }
    public static function persian_number( $amount ) {
        if ( ! is_string( $amount ) || ! preg_match( '/^[0-9]+(?:\.[0-9]+)?$/D', $amount ) ) { return ''; }
        $parts = explode( '.', $amount, 2 );
        $text = preg_replace( '/\B(?=(?:[0-9]{3})+(?![0-9]))/', '٬', $parts[0] );
        if ( isset( $parts[1] ) ) { $text .= '٫' . $parts[1]; }
        return strtr( $text, array_combine( str_split( '0123456789' ), preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY ) ) );
    }
    /** Identification catalog, not a bank licence register or a BIN lookup. */
    public static function bank_catalog() {
        return array(
            'eghtesad' => array( 'name' => 'اقتصاد نوین', 'group' => 'banks', 'aliases' => array(  ) ),
            'iranzamin' => array( 'name' => 'ایران زمین', 'group' => 'banks', 'aliases' => array(  ) ),
            'iran-venezuela' => array( 'name' => 'ایران و ونزوئلا', 'group' => 'banks', 'aliases' => array( 'ایران ونزوئلا', 'ایران-ونزوئلا', 'مشترک ایران و ونزوئلا', 'مشترک ایران ونزوئلا' ) ),
            'parsian' => array( 'name' => 'پارسیان', 'group' => 'banks', 'aliases' => array(  ) ),
            'pasargad' => array( 'name' => 'پاسارگاد', 'group' => 'banks', 'aliases' => array(  ) ),
            'post' => array( 'name' => 'پست بانک', 'group' => 'banks', 'aliases' => array( 'پست', 'پست ایران', 'پست بانک ایران' ) ),
            'tejarat' => array( 'name' => 'تجارت', 'group' => 'banks', 'aliases' => array(  ) ),
            'tosetaavon' => array( 'name' => 'توسعه تعاون', 'group' => 'banks', 'aliases' => array(  ) ),
            'tosesaderat' => array( 'name' => 'توسعه صادرات', 'group' => 'banks', 'aliases' => array( 'توسعه صادرات ایران' ) ),
            'khavarmianeh' => array( 'name' => 'خاورمیانه', 'group' => 'banks', 'aliases' => array(  ) ),
            'day' => array( 'name' => 'دی', 'group' => 'banks', 'aliases' => array(  ) ),
            'refahkargaran' => array( 'name' => 'رفاه کارگران', 'group' => 'banks', 'aliases' => array( 'رفاه' ) ),
            'resalat' => array( 'name' => 'رسالت', 'group' => 'banks', 'aliases' => array( 'قرض الحسنه رسالت' ) ),
            'saman' => array( 'name' => 'سامان', 'group' => 'banks', 'aliases' => array(  ) ),
            'sarmaye' => array( 'name' => 'سرمایه', 'group' => 'banks', 'aliases' => array(  ) ),
            'sepah' => array( 'name' => 'سپه', 'group' => 'banks', 'aliases' => array(  ) ),
            'sina' => array( 'name' => 'سینا', 'group' => 'banks', 'aliases' => array(  ) ),
            'shahr' => array( 'name' => 'شهر', 'group' => 'banks', 'aliases' => array(  ) ),
            'sanatmadan' => array( 'name' => 'صنعت و معدن', 'group' => 'banks', 'aliases' => array( 'صنعت معدن' ) ),
            'saderat' => array( 'name' => 'صادرات ایران', 'group' => 'banks', 'aliases' => array( 'صادرات' ) ),
            'karafarin' => array( 'name' => 'کارآفرین', 'group' => 'banks', 'aliases' => array(  ) ),
            'keshavarzi' => array( 'name' => 'کشاورزی', 'group' => 'banks', 'aliases' => array(  ) ),
            'gardeshgari' => array( 'name' => 'گردشگری', 'group' => 'banks', 'aliases' => array(  ) ),
            'maskan' => array( 'name' => 'مسکن', 'group' => 'banks', 'aliases' => array(  ) ),
            'mellat' => array( 'name' => 'ملت', 'group' => 'banks', 'aliases' => array(  ) ),
            'melal' => array( 'name' => 'ملل', 'group' => 'banks', 'aliases' => array( 'مؤسسه اعتباری ملل', 'موسسه اعتباری ملل' ) ),
            'melli' => array( 'name' => 'ملی ایران', 'group' => 'banks', 'aliases' => array( 'ملی' ) ),
            'mehriran' => array( 'name' => 'مهر ایران', 'group' => 'banks', 'aliases' => array( 'مهر ایرانیان', 'قرض الحسنه مهر ایران' ) ),
            'blu' => array( 'name' => 'بلوبانک', 'group' => 'digital', 'aliases' => array( 'بلو', 'بلو بانک', 'blu bank', 'blubank' ) ),
            'ayande' => array( 'name' => 'آینده', 'group' => 'legacy', 'aliases' => array( 'اینده' ) ),
            'ansar' => array( 'name' => 'انصار', 'group' => 'legacy', 'aliases' => array(  ) ),
            'tose' => array( 'name' => 'توسعه', 'group' => 'legacy', 'aliases' => array( 'مؤسسه اعتباری توسعه', 'موسسه اعتباری توسعه' ) ),
            'hekmat' => array( 'name' => 'حکمت ایرانیان', 'group' => 'legacy', 'aliases' => array( 'حکمت' ) ),
            'ghavvamin' => array( 'name' => 'قوامین', 'group' => 'legacy', 'aliases' => array( 'ghavamin' ) ),
            'kosar' => array( 'name' => 'کوثر', 'group' => 'legacy', 'aliases' => array( 'مؤسسه اعتباری کوثر', 'موسسه اعتباری کوثر' ) ),
            'mehreghtesad' => array( 'name' => 'مهر اقتصاد', 'group' => 'legacy', 'aliases' => array(  ) ),
            'noor' => array( 'name' => 'نور', 'group' => 'legacy', 'aliases' => array( 'مؤسسه اعتباری نور', 'موسسه اعتباری نور' ) ),
        );
    }
    private static function bank_token( $name ) {
        $name = str_replace( array( 'ي', 'ى', 'ك' ), array( 'ی', 'ی', 'ک' ), $name );
        $name = preg_replace( '/[\s\p{Z}\p{Cf}\p{M}\x{0640}]+/u', '', $name );
        return is_string( $name ) ? strtolower( $name ) : '';
    }
    public static function bank_key( $name ) {
        if ( ! is_string( $name ) || strlen( $name ) > 512 ) { return ''; }
        static $aliases = null;
        if ( null === $aliases ) {
            $aliases = array();
            foreach ( self::bank_catalog() as $key => $bank ) {
                foreach ( array_merge( array( $key, $bank['name'] ), $bank['aliases'] ) as $alias ) {
                    $aliases[self::bank_token( $alias )] = $key;
                    $aliases[self::bank_token( 'بانک' . $alias )] = $key;
                }
            }
        }
        $token = self::bank_token( $name );
        return isset( $aliases[$token] ) ? $aliases[$token] : '';
    }
    /** Fixed local assets only; never turn a merchant-provided name into a path. */
    public static function bank_logo( $name ) {
        $key = self::bank_key( $name );
        return '' !== $key ? $key . '.svg' : '';
    }
    public static function ui_icon( $name ) {
        $paths = array(
            'card' => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18M7 15h3"/>',
            'copy' => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
            'check' => '<path d="m5 12 4 4L19 6"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'upload' => '<path d="M12 16V4m-4 4 4-4 4 4M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>',
            'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 7h6M9 11h6M9 15h3"/>',
            'chevron' => '<path d="m6 9 6 6 6-6"/>',
            'refresh' => '<path d="M20 7v5h-5M4 17v-5h5M6 6a8 8 0 0 1 13 2M5 16a8 8 0 0 0 13 2"/>',
            'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7v.1"/>',
        );
        return '<svg class="tcc-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( isset( $paths[$name] ) ? $paths[$name] : $paths['card'] ) . '</svg>';
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
