<?php
defined( 'ABSPATH' ) || exit;
class TCC_Admin {
    public static function init() {
        add_action( 'admin_menu', function () { add_submenu_page( 'woocommerce', TCC_TITLE, TCC_TITLE, 'manage_options', 'tisacase-card-to-card', array( __CLASS__, 'page' ) ); } );
        add_action( 'admin_post_tcc_settings', array( __CLASS__, 'save' ) );
        add_action( 'admin_notices', function () {
            if ( ! current_user_can( 'manage_options' ) || 'yes' !== TCC_Settings::get()['enabled'] ) { return; }
            $screen = get_current_screen();
            if ( $screen && 'woocommerce_page_tisacase-card-to-card' === $screen->id ) { return; }
            try { ( new TCC_Storage() )->base(); }
            catch ( Throwable $e ) { echo '<div class="notice notice-error"><p>کارت به کارت تیساکیس: ذخیره خصوصی رسید آماده نیست. <a href="' . esc_url( admin_url( 'admin.php?page=tisacase-card-to-card' ) ) . '">تنظیمات</a></p></div>'; }
        } );
    }
    public static function icon( $name ) {
        $paths = array(
            'card' => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18M7 15h3"/>',
            'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3zM9 7h6M9 11h6M9 15h3"/>',
            'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
            'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6M21 21v-2a6 6 0 0 0-3-5"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'chevron' => '<path d="m7 10 5 5 5-5"/>',
            'check' => '<path d="m5 12 4 4L19 6"/>',
            'arrow' => '<path d="M19 12H5m6-6-6 6 6 6"/>',
        );
        return '<svg class="tcc-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( isset( $paths[$name] ) ? $paths[$name] : $paths['card'] ) . '</svg>';
    }
    private static function field( $s, $key, $label, $type = 'text', $unit = '' ) {
        $id = 'tcc-setting-' . $key;
        echo '<label class="tcc-field" for="' . esc_attr( $id ) . '"><span class="tcc-field-label">' . esc_html( $label ) . ( $unit ? '<small>' . esc_html( $unit ) . '</small>' : '' ) . '</span>';
        if ( 'textarea' === $type ) { echo '<textarea id="' . esc_attr( $id ) . '" name="settings[' . esc_attr( $key ) . ']" rows="2">' . esc_textarea( $s[$key] ) . '</textarea>'; }
        else {
            $ranges = array( 'max_mb' => ' min="1" max="8" step="1"', 'hours' => ' min="1" max="168" step="1"', 'guest_days' => ' min="1" max="30" step="1"' );
            echo '<input id="' . esc_attr( $id ) . '" type="' . esc_attr( $type ) . '" name="settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $s[$key] ) . '"' . ( isset( $ranges[$key] ) ? $ranges[$key] . ' inputmode="numeric" dir="ltr" required' : '' ) . ( 'recipients' === $key ? ' dir="ltr"' : '' ) . '>';
        }
        echo '</label>';
    }
    private static function check( $s, $key, $label, $hint = '' ) {
        echo '<label class="tcc-switch"><input type="checkbox" name="settings[' . esc_attr( $key ) . ']" value="yes" ' . checked( $s[$key], 'yes', false ) . '><span class="tcc-switch-track" aria-hidden="true"></span><span>' . esc_html( $label ) . ( $hint ? '<small>' . esc_html( $hint ) . '</small>' : '' ) . '</span></label>';
    }
    public static function card_row( $card, $index ) {
        $prefix = 'settings[cards][' . $index . ']';
        $number = isset( $card['number'] ) ? $card['number'] : '';
        $title = ! empty( $card['label'] ) ? $card['label'] : ( ! empty( $card['bank'] ) ? $card['bank'] : 'کارت جدید' );
        echo '<details class="tcc-card-row"' . ( ! $number ? ' open' : '' ) . '><summary><span class="tcc-card-symbol">' . self::icon( 'card' ) . '</span><strong class="tcc-card-title">' . esc_html( $title ) . '</strong><bdi class="tcc-card-last4">' . ( $number ? '•••• ' . esc_html( substr( $number, -4 ) ) : '—' ) . '</bdi><span class="tcc-card-state tcc-badge' . ( ! empty( $card['active'] ) ? ' tcc-approved' : '' ) . '">' . ( ! empty( $card['active'] ) ? 'فعال' : 'غیرفعال' ) . '</span>' . self::icon( 'chevron' ) . '</summary><div class="tcc-card-editor"><div class="tcc-fields">';
        foreach ( array( 'number' => 'شماره کارت', 'holder' => 'صاحب کارت', 'bank' => 'بانک', 'label' => 'عنوان داخلی' ) as $key => $label ) {
            echo '<label class="tcc-field"><span>' . esc_html( $label ) . '</span><input data-card-field="' . esc_attr( $key ) . '" name="' . esc_attr( $prefix . '[' . $key . ']' ) . '" value="' . esc_attr( isset( $card[$key] ) ? $card[$key] : '' ) . '"' . ( 'number' === $key ? ' dir="ltr" inputmode="numeric" maxlength="24" autocomplete="off" placeholder="0000 0000 0000 0000"' : '' ) . '></label>';
        }
        echo '</div><div class="tcc-card-footer"><label class="tcc-switch"><input data-card-field="active" type="checkbox" name="' . esc_attr( $prefix . '[active]' ) . '" value="1" ' . checked( ! empty( $card['active'] ), true, false ) . '><span class="tcc-switch-track" aria-hidden="true"></span><span>فعال</span></label><label class="tcc-check tcc-remove"><input data-card-field="remove" type="checkbox" name="' . esc_attr( $prefix . '[remove]' ) . '" value="1"> حذف کارت</label></div></div></details>';
    }
    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'دسترسی ندارید.' ); }
        $status = array();
        try { ( new TCC_Storage() )->base(); $status[] = array( 'ok', 'ذخیره رسید آماده است' ); }
        catch ( Throwable $e ) { $status[] = array( 'error', 'ذخیره خصوصی رسید آماده نیست' ); }
        if ( get_option( 'tcc_db_version' ) !== TCC_VERSION ) { $status[] = array( 'error', 'نصب جدول‌های مالی کامل نیست' ); }
        if ( ! in_array( get_woocommerce_currency(), array( 'IRR', 'IRT' ), true ) ) { $status[] = array( 'error', 'ارز فروشگاه باید ریال یا تومان باشد' ); }
        if ( ! TCC_Settings::primary_card() ) { $status[] = array( 'error', 'کارت مقصد فعال ندارید' ); }
        self::render( TCC_Settings::get(), $status, (array) get_option( 'tcc_reviewers', array() ) );
    }
    /** Shared by the real settings page and markup/browser regression fixtures. */
    public static function render( $s, $status, $reviewers = array() ) {
        $tabs = array( 'cards' => array( 'card', 'کارت‌ها' ), 'payment' => array( 'receipt', 'پرداخت و رسید' ), 'notifications' => array( 'bell', 'اعلان‌ها' ), 'access' => array( 'users', 'دسترسی' ) );
        $active = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) && isset( $tabs[$_GET['tab']] ) ? $_GET['tab'] : 'cards';
        include TCC_PATH . 'templates/settings.php';
    }
    public static function save() {
        if ( ! current_user_can( 'manage_options' ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) { wp_die( 'دسترسی ندارید.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'tcc_settings' );
        try {
            $input = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
            $clean = TCC_Settings::sanitize( $input );
            $reviewers = array_values( array_unique( array_filter( array_map( 'absint', preg_split( '/[,\s]+/', TCC_Helpers::post( 'reviewers' ) ) ) ) ) );
            foreach ( $reviewers as $id ) { if ( ! get_userdata( $id ) ) { throw new InvalidArgumentException( 'یکی از شناسه‌های بازبین معتبر نیست؛ تنظیمات ذخیره نشد.' ); } }
            global $wpdb;
            $audit = $wpdb->insert( TCC_Repository::table( 'events' ), array( 'order_id' => 0, 'action' => 'settings_requested', 'actor' => get_current_user_id(), 'public_reason' => '', 'private_note' => 'درخواست ذخیره تنظیمات و دسترسی‌ها؛ هش تنظیمات: ' . hash( 'sha256', wp_json_encode( $clean ) ) . '؛ بازبین‌ها: ' . implode( ',', $reviewers ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'notify_admin' => 1, 'notify_customer' => 1 ) );
            if ( false === $audit ) { throw new RuntimeException( 'ثبت سابقه تنظیمات ممکن نیست؛ تغییری ذخیره نشد.' ); }
            update_option( 'tcc_settings', $clean, false );
            foreach ( array_diff( (array) get_option( 'tcc_reviewers', array() ), $reviewers ) as $id ) { $user = get_userdata( $id ); if ( $user ) { $user->remove_cap( 'tcc_review_payments' ); } }
            foreach ( $reviewers as $id ) { get_userdata( $id )->add_cap( 'tcc_review_payments' ); }
            update_option( 'tcc_reviewers', $reviewers, false );
        } catch ( Throwable $e ) { wp_die( esc_html( $e->getMessage() ), '', array( 'response' => 400, 'back_link' => true ) ); }
        $tab = TCC_Helpers::post( 'ui_tab' );
        if ( ! in_array( $tab, array( 'cards', 'payment', 'notifications', 'access' ), true ) ) { $tab = 'cards'; }
        wp_safe_redirect( add_query_arg( 'tab', $tab, admin_url( 'admin.php?page=tisacase-card-to-card&saved=1' ) ) ); exit;
    }
}
