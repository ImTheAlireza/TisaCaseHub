<?php
defined( 'ABSPATH' ) || exit;

final class TSPAY_Order_Admin {
    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'register_box' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'wp_ajax_tspay_admin_review', array( __CLASS__, 'review' ) );
    }

    public static function assets() {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) { return; }
        wp_enqueue_style( 'tspay-admin', TSPAY_URL . 'assets/admin.css', array(), TSPAY_VERSION );
        wp_enqueue_script( 'tspay-admin', TSPAY_URL . 'assets/admin.js', array(), TSPAY_VERSION, true );
    }

    public static function register_box() {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) { return; }
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0 );
        $order = $id ? wc_get_order( $id ) : false;
        if ( $order && TSPAY_GATEWAY === $order->get_payment_method() ) {
            add_meta_box( 'tspay-order-review', 'بررسی پرداخت اسنپ‌پی', array( __CLASS__, 'render_box' ), $screen->id, 'normal', 'high' );
        }
    }

    public static function render_box( $source ) {
        $order = $source instanceof WC_Order ? $source : ( isset( $source->ID ) ? wc_get_order( $source->ID ) : false );
        if ( ! $order || TSPAY_GATEWAY !== $order->get_payment_method() ) { echo '<p>این سفارش با روش پرداخت اسنپ‌پی ثبت نشده است.</p>'; return; }
        if ( ! current_user_can( 'manage_woocommerce' ) ) { echo '<p>برای بررسی این پرداخت، مجوز مدیریت ووکامرس لازم است.</p>'; return; }

        $tracking = (string) $order->get_meta( '_tspay_customer_tracking_code', true );
        $reported = 'yes' === $order->get_meta( '_tspay_customer_reported_paid', true );
        $review_state = (string) $order->get_meta( '_tspay_admin_review_state', true );
        $reason = (string) $order->get_meta( '_tspay_admin_rejection_reason', true );
        $can_review = $reported && '' !== $tracking && $order->has_status( 'on-hold' ) && ! $order->is_paid();
        $nonce = wp_create_nonce( 'tspay_admin_review_' . $order->get_id() );
        ?>
        <div class="tspay-admin-review" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-order="<?php echo esc_attr( $order->get_id() ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
            <div class="tspay-admin-summary">
                <div><span>مبلغ کل سفارش</span><strong><?php echo wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ); ?></strong></div>
                <div><span>وضعیت ووکامرس</span><strong><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></strong></div>
            </div>
            <?php if ( $tracking ) { ?>
                <div class="tspay-admin-tracking"><span>کد رهگیری ثبت‌شده توسط مشتری</span><code dir="ltr"><?php echo esc_html( $tracking ); ?></code></div>
            <?php } else { ?>
                <p class="tspay-admin-notice">مشتری هنوز کد رهگیری پرداخت را ثبت نکرده است.</p>
            <?php } ?>
            <?php if ( 'approved' === $review_state || $order->is_paid() ) { ?>
                <p class="tspay-admin-state is-approved">پرداخت توسط مدیر تأیید شده است.</p>
            <?php } elseif ( 'rejected' === $review_state ) { ?>
                <p class="tspay-admin-state is-rejected">اعلام پرداخت رد شده است.<?php if ( $reason ) { ?><br><span>علت: <?php echo esc_html( $reason ); ?></span><?php } ?></p>
            <?php } elseif ( $can_review ) { ?>
                <p class="tspay-admin-warning">پیش از تأیید، کد رهگیری و دریافت مبلغ دقیق را در پنل/حساب اسنپ‌پی تطبیق دهید. ثبت کد توسط مشتری به‌تنهایی اثبات پرداخت نیست.</p>
                <label class="tspay-admin-confirm"><input type="checkbox" data-tspay-confirm> <span>واریز واقعی و مبلغ سفارش را در اسنپ‌پی/حساب مقصد بررسی کردم.</span></label>
                <button type="button" class="button button-primary tspay-admin-approve" data-decision="approve">تأیید پرداخت و تکمیل سفارش</button>
                <label class="tspay-admin-reason-label" for="tspay-rejection-reason">علت رد <small>(به مشتری نمایش داده می‌شود)</small></label>
                <textarea id="tspay-rejection-reason" data-tspay-reason rows="2" maxlength="500" placeholder="مثلاً کد رهگیری پیدا نشد یا مبلغ واریزی با سفارش تطبیق ندارد"></textarea>
                <button type="button" class="button tspay-admin-reject" data-decision="reject">رد اعلام پرداخت</button>
            <?php } elseif ( 'pending' === $order->get_status() ) { ?>
                <p class="tspay-admin-notice">سفارش هنوز در انتظار پرداخت است؛ پس از ثبت کد رهگیری مشتری، ابزار بررسی اینجا نمایش داده می‌شود.</p>
            <?php } else { ?>
                <p class="tspay-admin-notice">این سفارش در وضعیت قابل بررسی پرداخت نیست.</p>
            <?php } ?>
            <p class="tspay-admin-feedback" role="status" aria-live="polite"></p>
        </div>
        <?php
    }

    public static function review() {
        $order_id_raw = isset( $_POST['order_id'] ) && is_scalar( $_POST['order_id'] ) ? wp_unslash( $_POST['order_id'] ) : 0;
        $order_id = absint( $order_id_raw );
        $nonce = isset( $_POST['nonce'] ) && is_string( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        $decision = isset( $_POST['decision'] ) && is_string( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
        if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) { wp_send_json_error( array( 'message' => 'روش درخواست معتبر نیست.' ), 405 ); }
        if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_shop_order', $order_id ) ) { wp_send_json_error( array( 'message' => 'مجوز ویرایش این سفارش را ندارید.' ), 403 ); }
        if ( ! wp_verify_nonce( $nonce, 'tspay_admin_review_' . $order_id ) ) { wp_send_json_error( array( 'message' => 'اعتبار درخواست تمام شده؛ صفحه سفارش را تازه کنید.' ), 403 ); }
        if ( ! in_array( $decision, array( 'approve', 'reject' ), true ) ) { wp_send_json_error( array( 'message' => 'عملیات درخواستی معتبر نیست.' ), 400 ); }
        $order = wc_get_order( $order_id );
        if ( ! $order || TSPAY_GATEWAY !== $order->get_payment_method() || ! $order->has_status( 'on-hold' ) || $order->is_paid() || 'yes' !== $order->get_meta( '_tspay_customer_reported_paid', true ) ) {
            wp_send_json_error( array( 'message' => 'سفارش دیگر در انتظار بررسی پرداخت نیست؛ صفحه را تازه کنید.' ), 409 );
        }
        $tracking = (string) $order->get_meta( '_tspay_customer_tracking_code', true );
        if ( '' === $tracking ) { wp_send_json_error( array( 'message' => 'کد رهگیری در سفارش ثبت نشده است.' ), 400 ); }

        if ( 'approve' === $decision ) {
            $confirmed = isset( $_POST['confirmed'] ) && is_string( $_POST['confirmed'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['confirmed'] ) );
            if ( ! $confirmed ) { wp_send_json_error( array( 'message' => 'برای تأیید، ابتدا دریافت وجه و مبلغ را بررسی و تیک تأیید را بزنید.' ), 400 ); }
            $order->add_order_note( 'مدیر پس از بررسی دریافت وجه و تطبیق مبلغ، کد رهگیری اسنپ‌پی را تأیید کرد: ' . $tracking );
            $order->payment_complete();
            if ( ! $order->is_paid() ) { wp_send_json_error( array( 'message' => 'ووکامرس پرداخت را ثبت نکرد؛ وضعیت سفارش و یادداشت‌ها را بررسی کنید.' ), 500 ); }
            $order->update_meta_data( '_tspay_admin_review_state', 'approved' );
            $order->update_meta_data( '_tspay_admin_reviewed_by', get_current_user_id() );
            $order->update_meta_data( '_tspay_admin_reviewed_at', current_time( 'mysql', true ) );
            $order->save();
            wp_send_json_success( array( 'message' => 'پرداخت تأیید شد و وضعیت سفارش به‌روزرسانی شد.' ) );
        }

        $reason = isset( $_POST['reason'] ) && is_string( $_POST['reason'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) ) : '';
        if ( '' === $reason || strlen( $reason ) > 2000 ) { wp_send_json_error( array( 'message' => 'برای رد اعلام پرداخت، علت کوتاه و روشن را وارد کنید.' ), 400 ); }
        $order->update_meta_data( '_tspay_admin_review_state', 'rejected' );
        $order->update_meta_data( '_tspay_admin_rejection_reason', $reason );
        $order->update_meta_data( '_tspay_admin_reviewed_by', get_current_user_id() );
        $order->update_meta_data( '_tspay_admin_reviewed_at', current_time( 'mysql', true ) );
        $order->save();
        $order->update_status( 'failed', 'اعلام پرداخت اسنپ‌پی توسط مدیر رد شد. علت اعلام‌شده به مشتری: ' . $reason );
        wp_send_json_success( array( 'message' => 'اعلام پرداخت رد شد؛ وضعیت سفارش ناموفق شد.' ) );
    }
}
