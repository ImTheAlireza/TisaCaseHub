<?php
defined( 'ABSPATH' ) || exit;
class TCC_Admin {
    public static function init() {
        add_action( 'admin_menu', function () { add_submenu_page( 'woocommerce', TCC_TITLE, 'کارت به کارت تیساکیس', 'manage_options', 'tisacase-card-to-card', array( __CLASS__, 'page' ) ); } );
        add_action( 'admin_post_tcc_settings', array( __CLASS__, 'save' ) );
        add_action( 'admin_notices', function () {
            if ( current_user_can( 'manage_options' ) && 'yes' === TCC_Settings::get()['enabled'] ) {
                try { ( new TCC_Storage() )->base(); }
                catch ( Throwable $e ) { echo '<div class="notice notice-error"><p>کارت به کارت تیساکیس غیرفعال عملیاتی است: ' . esc_html( $e->getMessage() ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=tisacase-card-to-card' ) ) . '">بررسی تنظیمات</a></p></div>'; }
            }
        } );
    }
    private static function field( $s, $key, $label, $type = 'text', $description = '' ) {
        echo '<label class="tcc-field"><strong>' . esc_html( $label ) . '</strong>';
        if ( 'textarea' === $type ) { echo '<textarea name="settings[' . esc_attr( $key ) . ']" rows="3">' . esc_textarea( $s[$key] ) . '</textarea>'; }
        else { echo '<input type="' . esc_attr( $type ) . '" name="settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $s[$key] ) . '">'; }
        if ( $description ) { echo '<small>' . esc_html( $description ) . '</small>'; } echo '</label>';
    }
    private static function check( $s, $key, $label ) { echo '<label class="tcc-check"><input type="checkbox" name="settings[' . esc_attr( $key ) . ']" value="yes" ' . checked( $s[$key], 'yes', false ) . '> ' . esc_html( $label ) . '</label>'; }
    public static function card_row( $card, $index ) {
        $prefix = 'settings[cards][' . $index . ']';
        echo '<div class="tcc-card-row"><div class="tcc-fields">';
        foreach ( array( 'label' => 'عنوان داخلی', 'number' => 'شماره کارت ۱۶رقمی', 'holder' => 'نام صاحب کارت', 'bank' => 'نام بانک' ) as $key => $label ) {
            echo '<label class="tcc-field">' . esc_html( $label ) . '<input name="' . esc_attr( $prefix . '[' . $key . ']' ) . '" value="' . esc_attr( isset( $card[$key] ) ? $card[$key] : '' ) . '" ' . ( 'number' === $key ? 'dir="ltr" inputmode="numeric" maxlength="24"' : '' ) . '></label>';
        }
        echo '</div><label class="tcc-check"><input type="checkbox" name="' . esc_attr( $prefix . '[active]' ) . '" value="1" ' . checked( ! empty( $card['active'] ), true, false ) . '> فعال برای سفارش‌های جدید</label><label class="tcc-check"><input type="checkbox" name="' . esc_attr( $prefix . '[remove]' ) . '" value="1"> حذف از تنظیمات (بدون تغییر سفارش‌های قبلی)</label></div>';
    }
    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'دسترسی ندارید.' ); }
        $s = TCC_Settings::get();
        echo '<div class="wrap"><main class="tcc-panel tcc-settings" dir="rtl"><header class="tcc-heading"><span class="tcc-eyebrow">تنظیمات پرداخت دستی · نسخه ' . esc_html( TCC_VERSION ) . '</span><h1>کارت به کارت تیساکیس</h1><p>رسید، تأیید و رد را از داخل خود سفارش مدیریت کنید؛ این صفحه برای تنظیمات سراسری است.</p><a class="tcc-btn" href="' . esc_url( add_query_arg( 'tcc_method', TCC_GATEWAY, TCC_Helpers::orders_url() ) ) . '">مشاهده سفارش‌های کارت‌به‌کارت</a></header>';
        if ( isset( $_GET['saved'] ) ) { echo '<p class="tcc-message" role="status">تنظیمات ذخیره شد.</p>'; }
        echo '<section class="tcc-settings-section"><h2>آمادگی دریافت رسید خصوصی</h2>';
        try { $base = ( new TCC_Storage() )->base(); echo '<p class="tcc-message">مسیر بیرون ریشه‌های عمومی و قابل نوشتن است: <bdi>' . esc_html( $base ) . '</bdi></p>'; }
        catch ( Throwable $e ) { echo '<p class="tcc-message tcc-error">' . esc_html( $e->getMessage() ) . '</p>'; }
        if ( get_option( 'tcc_db_version' ) !== TCC_VERSION ) { echo '<p class="tcc-message tcc-error">جدول‌های مالی کامل نصب نشده‌اند؛ تا رفع مجوز/خطای دیتابیس، درگاه در دسترس نیست.</p>'; }
        if ( ! in_array( get_woocommerce_currency(), array( 'IRR', 'IRT' ), true ) ) { echo '<p class="tcc-message tcc-error">ارز فعلی فروشگاه در نسخه اول پشتیبانی نمی‌شود؛ فقط IRR و IRT قابل استفاده هستند.</p>'; }
        echo '<p>در صورت نیاز، مدیر هاست ثابت <code>TCC_PRIVATE_DIR</code> را در wp-config.php به یک مسیر <strong>پایدار و بیرون webroot</strong> تنظیم کند. هیچ فایل این پوشه نباید با URL عمومی، alias وب‌سرور یا CDN سرو شود. تغییر مسیر پس از دریافت رسید نیازمند انتقال کنترل‌شده فایل‌هاست.</p><p>نسخه اول: فقط ریال (IRR) و تومان (IRT)، بدون تبدیل خودکار؛ PDF/HEIC، تأیید بانکی خودکار و بازپرداخت بانکی پشتیبانی نمی‌شوند.</p></section>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tcc_settings">'; wp_nonce_field( 'tcc_settings' );
        echo '<section class="tcc-settings-section"><h2>۱. فعال‌سازی و راهنمای مشتری</h2>'; self::check( $s, 'enabled', 'فعال‌سازی روش پرداخت (نیازمند کارت فعال، ارز پشتیبانی‌شده و مسیر خصوصی سالم)' );
        self::field( $s, 'instructions', 'راهنمای واریز', 'textarea' ); self::field( $s, 'review_text', 'توضیح زمان بررسی' ); self::field( $s, 'support', 'راه ارتباط پشتیبانی', 'textarea' ); echo '</section>';
        echo '<section class="tcc-settings-section"><h2>۲. کارت‌های مقصد</h2><p>یک کارت پیش‌فرض به هر سفارش اختصاص می‌یابد؛ مقصد سفارش‌های قبلی ثابت می‌ماند. حداکثر ۲۰ کارت.</p><div id="tcc-cards">';
        foreach ( $s['cards'] as $i => $card ) { self::card_row( $card, $i ); }
        if ( ! $s['cards'] ) { self::card_row( array( 'active' => true ), 0 ); }
        echo '</div><button type="button" id="tcc-add-card" class="tcc-btn">افزودن کارت</button><template id="tcc-card-template">'; self::card_row( array( 'active' => true ), '__INDEX__' ); echo '</template><label class="tcc-field">کارت پیش‌فرض<select name="settings[default_card]"><option value="">اولین کارت فعال در ترتیب بالا</option>';
        foreach ( $s['cards'] as $card ) { echo '<option value="' . esc_attr( $card['id'] ) . '" ' . selected( $s['default_card'], $card['id'], false ) . '>' . esc_html( ( $card['label'] ?: $card['holder'] ) . ' — ' . substr( $card['number'], -4 ) ) . '</option>'; } echo '</select></label></section>';
        echo '<section class="tcc-settings-section"><h2>۳. فرم رسید</h2>'; self::check( $s, 'receipt_required', 'تصویر رسید الزامی باشد' ); self::check( $s, 'reference_required', 'کد پیگیری الزامی باشد' ); self::field( $s, 'max_mb', 'حداکثر حجم تصویر به مگابایت (۱ تا ۸)', 'number', 'سقف واقعی از محدودیت هاست بیشتر نمی‌شود. فقط JPG، PNG و WebP.' ); echo '<p>بدون تصویر، کد پیگیری لازم است. شماره کامل کارت مبدأ، CVV2، تاریخ انقضا و رمز دریافت نمی‌شود. سقف ضدسوءاستفاده: پنج رسید در ساعت و بیست نسخه در کل برای هر سفارش.</p></section>';
        echo '<section class="tcc-settings-section"><h2>۴. مهلت و دسترسی مهمان</h2>'; self::field( $s, 'hours', 'مهلت اعلام رسید به ساعت (۱ تا ۱۶۸)', 'number' ); self::field( $s, 'guest_days', 'اعتبار لینک مهمان به روز (۱ تا ۳۰)', 'number', 'تمدید و ابطال لینک قبلی از داخل همان سفارش ممکن است.' ); self::check( $s, 'auto_cancel', 'لغو خودکار پس از مهلت؛ فقط سفارش بدون هیچ رسید/اعلام واریز' ); echo '<p class="tcc-message">پیش‌فرض لغو خودکار خاموش است. رد رسید یا تأخیر بازبین، باعث لغو خودکار نمی‌شود. اجرای زمان‌بندی به Cron سایت وابسته است.</p></section>';
        echo '<section class="tcc-settings-section"><h2>۵. اعلان‌ها</h2>'; self::check( $s, 'notify_admin', 'ایمیل رسید جدید به مسئولان مالی' ); self::field( $s, 'recipients', 'ایمیل گیرندگان؛ جداشده با کاما' ); self::check( $s, 'notify_customer', 'ایمیل ثبت، رد، اصلاح، تأیید و یادآوری به مشتری' ); echo '<p>ایمیل بازبین مستقیماً به سفارش می‌رود. رسید پیوست نمی‌شود. تلگرام معمول سفارش پرداخت‌شده از هوک‌های ووکامرس استفاده می‌کند؛ اعلان اختصاصی رسید/پیامک در این نسخه نیست.</p></section>';
        echo '<section class="tcc-settings-section"><h2>۶. بازبینی و دسترسی</h2>'; self::field( $s, 'reject_reasons', 'علت‌های آماده رد یا اصلاح؛ هر خط یک مورد', 'textarea' ); echo '<label class="tcc-field">شناسه کاربران مجاز بررسی؛ جداشده با کاما<input name="reviewers" dir="ltr" value="' . esc_attr( implode( ',', (array) get_option( 'tcc_reviewers', array() ) ) ) . '"><small>مدیر سایت به‌صورت پیش‌فرض مجاز است. این گزینه فقط مجوز بازبینی می‌دهد؛ کاربر باید مجوز ویرایش همان سفارش را هم داشته باشد. تغییر کارت‌ها فقط برای مدیر تنظیمات است.</small></label></section>';
        echo '<section class="tcc-settings-section"><h2>۷. نگهداری و سابقه مالی</h2><p>رسیدها و سابقه مالی با غیرفعال‌سازی یا حذف افزونه خودکار پاک نمی‌شوند. پاک‌سازی دوره‌ای مالی در نسخه اول خاموش و بدون زمان نگهداری پیش‌فرض است؛ سیاست بایگانی و حذف را قبل از استفاده با مسئول مالی تعیین کنید. پوشه بیرون وب در کتابخانه رسانه و اسکن uploads بهینه‌ساز وارد نمی‌شود.</p><p>تنظیمات و تغییر مجوزها با شناسه مدیر و زمان در دفتر رویداد ثبت می‌شوند؛ شماره کارت کامل در این سابقه تکرار نمی‌شود.</p></section><button type="submit" class="tcc-btn tcc-primary">ذخیره تنظیمات</button></form></main></div>';
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
        wp_safe_redirect( admin_url( 'admin.php?page=tisacase-card-to-card&saved=1' ) ); exit;
    }
}
