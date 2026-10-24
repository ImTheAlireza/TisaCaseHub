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
        if ( ! $a ) { echo '<p>مسیر پرداخت این سفارش هنوز ایجاد نشده است؛ این سفارش را با روش پرداخت مشتری تکمیل کنید. تغییر دستی نام روش پرداخت، رسید ایجاد نمی‌کند.</p>'; return; }
        $card = json_decode( $a['card'], true ); $s = $a['latest_id'] ? $repo->submission( $a['latest_id'] ) : null;
        $form = 'tcc-review-' . $order->get_id();
        self::$forms[$order->get_id()] = array( 'a' => $a, 'fingerprint' => TCC_Helpers::fingerprint( $order ) );
        echo '<div class="tcc-panel tcc-order-box" dir="rtl" data-tcc-order="' . esc_attr( $order->get_id() ) . '">';
        echo '<div class="tcc-heading">' . TCC_Helpers::display_badge( $order, $a ) . '<p><strong>روش پرداخت: ' . esc_html( TCC_TITLE ) . '</strong></p></div>';
        if ( ! TCC_Helpers::matches( $order, $a ) ) { echo '<p class="tcc-message tcc-error">مبلغ یا ارز سفارش پس از ایجاد پرداخت تغییر کرده؛ تأیید مسدود است و تطبیق مالی لازم است.</p>'; }
        if ( 'approving' === $a['status'] ) { echo '<p class="tcc-message tcc-error">تأیید قبلی ناتمام است؛ ورود وجه و وضعیت واقعی ووکامرس را تطبیق دهید. تا وقتی سفارش پرداخت‌شده نشده، تأیید خودکار تکرار نمی‌شود. پس از تطبیق قطعی توسط مسئول مالی و ثبت وضعیت صحیح سفارش، دکمه تکمیل سابقه فعال می‌شود.</p>'; }
        if ( $order->is_paid() && 'approved' !== $a['status'] ) { echo '<p class="tcc-message">ووکامرس سفارش را پرداخت‌شده می‌داند. اگر تأیید قبلی ناتمام است فقط تکمیل ثبت سابقه انجام می‌شود؛ پرداخت دوباره اجرا نمی‌شود.</p>'; }
        echo '<div class="tcc-admin-grid"><div><h3>اطلاعات پرداخت</h3><p>مبلغ فعلی: <strong>' . wp_kses_post( $order->get_formatted_order_total() ) . '</strong></p><p>مبلغ زمان پرداخت: ' . wp_kses_post( wc_price( $a['amount'], array( 'currency' => $a['currency'] ) ) ) . '</p><p>کارت مقصد: <bdi>' . esc_html( implode( ' ', str_split( $card['number'], 4 ) ) ) . '</bdi></p><p>' . esc_html( $card['bank'] . ' — ' . $card['holder'] ) . '</p>';
        if ( $s ) {
            echo '<p>کد پیگیری: <bdi>' . esc_html( $s['reference'] ?: 'ثبت نشده' ) . '</bdi></p><p>چهار رقم آخر مبدأ: <bdi>' . esc_html( $s['last4'] ?: 'ثبت نشده' ) . '</bdi></p><p>ارسال: ' . esc_html( wp_date( 'Y/m/d H:i', strtotime( $s['created_at'] . ' UTC' ) ) ) . '</p><p>توضیح مشتری: ' . esc_html( $s['customer_note'] ) . '</p>';
            if ( $repo->duplicate( $s ) ) { echo '<p class="tcc-message tcc-error">این فایل یا کد پیگیری در سفارش دیگری هم ثبت شده؛ فقط هشدار برای تطبیق است و اثبات تقلب نیست.</p>'; }
        } else { echo '<p class="tcc-message">هنوز رسیدی ارسال نشده است.</p>'; }
        echo '</div><div class="tcc-admin-image">';
        if ( $s && $s['file_key'] ) {
            $url = TCC_Access::file_url( $order, $s, $a );
            echo '<a target="_blank" rel="noopener noreferrer" href="' . esc_url( $url ) . '"><img src="' . esc_url( $url ) . '" alt="تصویر رسید سفارش" loading="lazy"></a><p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">بازکردن تصویر اصلی</a> · <button type="button" class="button tcc-rotate">چرخش پیش‌نمایش</button></p>';
        } else { echo '<p class="tcc-muted">تصویری موجود نیست.</p>'; }
        echo '</div></div>';
        $can_decide = $s && ( ( ! $order->is_paid() && $order->has_status( array( 'on-hold', 'pending' ) ) && ! in_array( $a['status'], array( 'approved', 'approving' ), true ) ) || ( $order->is_paid() && 'approving' === $a['status'] ) );
        if ( $can_decide ) {
            echo '<div class="tcc-decision"><h3>تصمیم مسئول مالی</h3><p>پیش از تصمیم، تغییرات سفارش را ذخیره کنید. رد رسید، لغو سفارش یا بازپرداخت بانکی نیست.</p>';
            echo '<label class="tcc-field">علت آماده<select class="tcc-reason-preset"><option value="">انتخاب اختیاری…</option>';
            foreach ( explode( "\n", TCC_Settings::get()['reject_reasons'] ) as $reason ) { if ( trim( $reason ) ) { echo '<option>' . esc_html( trim( $reason ) ) . '</option>'; } }
            echo '</select></label><label class="tcc-field">علت قابل‌نمایش به مشتری (برای رد/اصلاح الزامی)<textarea name="reason" form="' . esc_attr( $form ) . '" rows="2" maxlength="1000"></textarea></label><label class="tcc-field">یادداشت داخلی؛ برای مشتری ارسال نمی‌شود<textarea name="private_note" form="' . esc_attr( $form ) . '" rows="2" maxlength="1000"></textarea></label><label class="tcc-check"><input type="checkbox" name="confirmed" value="yes" form="' . esc_attr( $form ) . '"> ورود وجه به حساب مقصد را بررسی کرده‌ام.</label>';
            echo '<div class="tcc-actions"><button type="submit" name="decision" value="approve" form="' . esc_attr( $form ) . '" class="tcc-btn tcc-primary">' . ( 'approving' === $a['status'] ? 'تکمیل سابقه تأیید' : 'تأیید پرداخت' ) . '</button>';
            if ( 'approving' !== $a['status'] ) {
                foreach ( array( 'reject' => 'رد رسید', 'correct' => 'درخواست اصلاح', 'reconcile' => 'نیازمند تطبیق بیشتر' ) as $value => $label ) { echo '<button type="submit" name="decision" value="' . esc_attr( $value ) . '" form="' . esc_attr( $form ) . '" class="tcc-btn ' . ( 'reject' === $value ? 'tcc-danger' : '' ) . '">' . esc_html( $label ) . '</button>'; }
            }
            echo '</div></div>';
        }
        echo '<p class="tcc-feedback" role="status" aria-live="polite"></p><details class="tcc-history"><summary>تاریخچه رسیدها و تصمیم‌ها (۳۰ مورد آخر)</summary>';
        foreach ( $repo->history( $order->get_id(), 'submissions' ) as $old ) {
            echo '<p>رسید #' . esc_html( $old['id'] ) . ' — ' . esc_html( wp_date( 'Y/m/d H:i', strtotime( $old['created_at'] . ' UTC' ) ) );
            if ( $old['file_key'] ) { echo ' · <a target="_blank" rel="noopener noreferrer" href="' . esc_url( TCC_Access::file_url( $order, $old, $a ) ) . '">مشاهده تصویر</a>'; } echo '</p>';
        }
        foreach ( $repo->history( $order->get_id() ) as $event ) {
            $label = TCC_Helpers::event_label( $event['action'] );
            $actor = $event['actor'] ? get_userdata( $event['actor'] ) : false;
            echo '<article class="tcc-event"><strong>' . esc_html( $label ) . '</strong> — ' . esc_html( $actor ? $actor->display_name : 'مشتری/سیستم' ) . ' — ' . esc_html( wp_date( 'Y/m/d H:i', strtotime( $event['created_at'] . ' UTC' ) ) ) . '<p>علت عمومی: ' . esc_html( $event['public_reason'] ?: '—' ) . '</p><p>یادداشت داخلی: ' . esc_html( $event['private_note'] ?: '—' ) . '</p></article>';
        }
        echo '</details><details class="tcc-history"><summary>لینک پیگیری و تمدید دسترسی مهمان</summary><p>اعتبار تا: ' . esc_html( wp_date( 'Y/m/d H:i', $a['access_expires'] ) ) . '</p><label class="tcc-field">لینک محرمانه پیگیری؛ فقط برای صاحب سفارش<input type="text" dir="ltr" readonly value="' . esc_attr( TCC_Access::url( $order ) ) . '"></label><button type="submit" form="' . esc_attr( $form ) . '" name="decision" value="renew" class="tcc-btn">تمدید لینک پیگیری</button><p>در صورت فعال‌بودن اعلان مشتری و داشتن ایمیل معتبر، لینک تازه ایمیل می‌شود؛ لینک قبلی باطل خواهد شد.</p></details>';
        if ( current_user_can( 'manage_options' ) ) { echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=tisacase-card-to-card' ) ) . '">تنظیمات کارت‌به‌کارت</a></p>'; }
        echo '</div>';
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
